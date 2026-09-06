from __future__ import annotations

import json
import os
import socket
import stat
import struct
import tempfile
import threading
import time
import unittest
from dataclasses import replace
from pathlib import Path

try:
    from . import server
except ImportError:  # pragma: no cover - supports direct invocation
    import server


SVG = "<svg xmlns='http://www.w3.org/2000/svg'><rect width='10' height='10'/></svg>"


def make_fake_inkscape(directory: Path, mode: str = "success") -> Path:
    output = directory / "fake-inkscape.py"
    output.write_text(
        "#!/usr/bin/env python3\n"
        "import pathlib, re, struct, sys, time, zlib\n"
        f"mode = {mode!r}\n"
        "if mode == 'sleep': time.sleep(2)\n"
        "if mode == 'fail': raise SystemExit(1)\n"
        "output = next(arg.split('=', 1)[1] for arg in sys.argv if arg.startswith('--export-filename='))\n"
        "width = int(next(arg.split('=', 1)[1] for arg in sys.argv if arg.startswith('--export-width=')))\n"
        "height = int(next(arg.split('=', 1)[1] for arg in sys.argv if arg.startswith('--export-height=')))\n"
        "def chunk(kind, payload):\n"
        "    return struct.pack('>I', len(payload)) + kind + payload + struct.pack('>I', zlib.crc32(kind + payload) & 0xffffffff)\n"
        "rows = b''.join(b'\\x00' + b'\\x00\\x00\\x00\\xff' * width for _ in range(height))\n"
        "png = b'\\x89PNG\\r\\n\\x1a\\n' + chunk(b'IHDR', struct.pack('>IIBBBBB', width, height, 8, 6, 0, 0, 0)) + chunk(b'IDAT', zlib.compress(rows)) + chunk(b'IEND', b'')\n"
        "if mode == 'invalid-png': pathlib.Path(output).write_bytes(b'not-png')\n"
        "else: pathlib.Path(output).write_bytes(png)\n",
        encoding="utf-8",
    )
    output.chmod(0o755)
    return output


@unittest.skipUnless(os.name == "posix", "renderer requires Unix-domain sockets")
class RendererServerTests(unittest.TestCase):
    def setUp(self) -> None:
        test_tmpdir = os.environ.get("RENDERER_TEST_TMPDIR")
        self.directory = Path(
            tempfile.mkdtemp(prefix="renderer-test-", dir=test_tmpdir)
        )
        self.socket_path = self.directory / "renderer.sock"
        self.inkscape = make_fake_inkscape(self.directory)
        self.config = server.RendererConfig(
            socket_path=self.socket_path,
            temp_dir=self.directory / "tmp",
            inkscape=str(self.inkscape),
            max_body_bytes=1024,
            max_svg_bytes=512,
            max_deadline_ms=5_000,
            max_concurrency=2,
        )
        self.renderer = server.create_server(self.config)
        self.thread = threading.Thread(
            target=self.renderer.serve_forever,
            kwargs={"poll_interval": 0.01},
            daemon=True,
        )
        self.thread.start()
        for _ in range(100):
            if self.socket_path.exists():
                return
            time.sleep(0.01)
        self.fail("renderer socket did not start")

    def tearDown(self) -> None:
        self.renderer.shutdown()
        self.thread.join(timeout=2)
        self.renderer.server_close()
        if self.socket_path.exists():
            self.socket_path.unlink()
        for child in self.directory.rglob("*"):
            if child.is_file():
                child.unlink()
        for child in sorted(self.directory.rglob("*"), reverse=True):
            if child.is_dir():
                child.rmdir()
        self.directory.rmdir()

    def request(
        self,
        body: bytes,
        *,
        method: str = "POST",
        target: str = "/render",
        content_type: str = "application/json",
        host: str = "renderer",
        content_length: int | None = None,
    ) -> tuple[int, dict[str, str], bytes]:
        length = len(body) if content_length is None else content_length
        request = (
            f"{method} {target} HTTP/1.1\r\n"
            f"Host: {host}\r\n"
            f"Content-Type: {content_type}\r\n"
            f"Content-Length: {length}\r\n"
            "Connection: close\r\n\r\n"
        ).encode() + body
        with socket.socket(socket.AF_UNIX, socket.SOCK_STREAM) as connection:  # type: ignore[attr-defined]
            connection.connect(str(self.socket_path))
            connection.sendall(request)
            connection.shutdown(socket.SHUT_WR)
            response = b""
            while chunk := connection.recv(64 * 1024):
                response += chunk
        headers, response_body = response.split(b"\r\n\r\n", 1)
        lines = headers.decode("ascii").split("\r\n")
        status = int(lines[0].split(" ", 2)[1])
        response_headers = dict(line.split(": ", 1) for line in lines[1:])
        return status, response_headers, response_body

    def render_body(self, **overrides: object) -> bytes:
        payload: dict[str, object] = {
            "svg": SVG,
            "width": 10,
            "height": 10,
        }
        payload.update(overrides)
        return json.dumps(payload, separators=(",", ":")).encode()

    def test_should_report_health_over_the_unix_socket(self) -> None:
        status, headers, body = self.request(b"", method="GET", target="/health")

        self.assertEqual(status, 200)
        self.assertEqual(headers["Content-Type"], "application/json; charset=utf-8")
        health = json.loads(body)
        self.assertEqual(health, {"status": "ok"})
        self.assertEqual(set(health), {"status"})

    def test_should_return_exact_json_error_shape(self) -> None:
        status, headers, body = self.request(b"{")

        self.assertEqual(status, 400)
        self.assertEqual(headers["Content-Type"], "application/json; charset=utf-8")
        error = json.loads(body)
        self.assertEqual(set(error), {"error"})
        self.assertEqual(error["error"], "invalid JSON body")

    def test_should_render_png_when_request_matches_http_contract(self) -> None:
        status, headers, body = self.request(self.render_body())

        self.assertEqual(status, 200)
        self.assertEqual(headers["Content-Type"], "image/png")
        self.assertTrue(body.startswith(server.PNG_SIGNATURE))
        self.assertEqual(body[16:24], struct.pack(">II", 10, 10))

    def test_should_reject_unsafe_protocol_and_svg_inputs(self) -> None:
        cases = [
            ("GET", "/render", "application/json", self.render_body()),
            ("POST", "/other", "application/json", self.render_body()),
            ("POST", "/render", "text/plain", self.render_body()),
            (
                "POST",
                "/render",
                "application/json",
                self.render_body(svg="<svg><script/></svg>"),
            ),
            (
                "POST",
                "/render",
                "application/json",
                self.render_body(
                    svg="<svg><image href='https://example.invalid/x'/></svg>"
                ),
            ),
        ]

        for method, target, content_type, body in cases:
            with self.subTest(method=method, target=target, content_type=content_type):
                status, _, _ = self.request(
                    body, method=method, target=target, content_type=content_type
                )
                self.assertIn(status, {404, 405, 415, 400})

    def test_should_reject_missing_host_header(self) -> None:
        body = self.render_body()
        request = (
            b"POST /render HTTP/1.1\r\nContent-Type: application/json\r\n"
            + f"Content-Length: {len(body)}\r\n\r\n".encode()
            + body
        )
        with socket.socket(socket.AF_UNIX, socket.SOCK_STREAM) as connection:  # type: ignore[attr-defined]
            connection.connect(str(self.socket_path))
            connection.sendall(request)
            connection.shutdown(socket.SHUT_WR)
            response = connection.recv(4096)
        self.assertIn(b" 400 ", response)

    def test_should_enforce_request_and_render_bounds(self) -> None:
        status, _, _ = self.request(
            b"{}", content_length=self.config.max_body_bytes + 1
        )
        self.assertEqual(status, 413)
        status, _, _ = self.request(self.render_body(svg="x" * 600))
        self.assertEqual(status, 413)
        status, _, _ = self.request(self.render_body(width=5_000))
        self.assertEqual(status, 400)

    def test_should_return_gateway_error_when_child_fails(self) -> None:
        failing_config = replace(
            self.config, inkscape=str(make_fake_inkscape(self.directory, "fail"))
        )
        self.renderer.shutdown()
        self.thread.join(timeout=2)
        self.renderer.server_close()
        self.socket_path.unlink()
        self.renderer = server.create_server(failing_config)
        self.thread = threading.Thread(target=self.renderer.serve_forever, daemon=True)
        self.thread.start()

        status, _, body = self.request(self.render_body())
        self.assertEqual(status, 502)
        self.assertIn(b"renderer failed", body)

    def test_should_timeout_and_terminate_slow_child(self) -> None:
        slow_config = replace(
            self.config, inkscape=str(make_fake_inkscape(self.directory, "sleep"))
        )
        self.renderer.shutdown()
        self.thread.join(timeout=2)
        self.renderer.server_close()
        self.socket_path.unlink()
        self.renderer = server.create_server(slow_config)
        self.thread = threading.Thread(target=self.renderer.serve_forever, daemon=True)
        self.thread.start()

        status, _, body = self.request(self.render_body(deadline_ms=50))
        self.assertEqual(status, 504)
        self.assertIn(b"deadline exceeded", body)

    def test_should_reject_invalid_png_from_child(self) -> None:
        invalid_config = replace(
            self.config, inkscape=str(make_fake_inkscape(self.directory, "invalid-png"))
        )
        self.renderer.shutdown()
        self.thread.join(timeout=2)
        self.renderer.server_close()
        self.socket_path.unlink()
        self.renderer = server.create_server(invalid_config)
        self.thread = threading.Thread(target=self.renderer.serve_forever, daemon=True)
        self.thread.start()

        status, _, body = self.request(self.render_body())
        self.assertEqual(status, 502)
        self.assertIn(b"invalid PNG", body)

    def test_should_return_busy_when_capacity_is_exhausted(self) -> None:
        self.renderer._slots = threading.BoundedSemaphore(1)
        self.assertTrue(self.renderer._slots.acquire(blocking=False))
        try:
            status, _, body = self.request(self.render_body())
        finally:
            self.renderer._slots.release()
        self.assertEqual(status, 503)
        self.assertIn(b"renderer busy", body)

    def test_should_set_socket_mode_and_remove_existing_socket_only(self) -> None:
        mode = stat.S_IMODE(self.socket_path.stat().st_mode)
        self.assertEqual(mode, server.SOCKET_MODE)
        self.renderer.shutdown()
        self.thread.join(timeout=2)
        self.renderer.server_close()
        self.socket_path.unlink()
        self.socket_path.write_text("not a socket", encoding="ascii")
        with self.assertRaises(RuntimeError):
            server.create_server(self.config)


if __name__ == "__main__":
    unittest.main()
