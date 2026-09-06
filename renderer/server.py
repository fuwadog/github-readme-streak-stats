#!/usr/bin/env python3
"""Small, private SVG-to-PNG rendering service.

The service speaks a deliberately narrow HTTP/1.1 protocol over a Unix socket.
Clients can send ``GET /health`` for a JSON readiness response, or ``POST
/render`` with ``Host``, ``Content-Type: application/json``, and
``Content-Length`` headers. The render JSON body contains ``svg``, ``width``,
``height``, and an optional ``deadline_ms``. A successful render returns the
raw PNG bytes as ``image/png``; failures return a small JSON error object. The
service never binds an Internet socket.
"""

from __future__ import annotations

import errno
import json
import logging
import os
import re
import signal
import socket
import socketserver
import stat
import subprocess
import tempfile
import threading
import time
import xml.etree.ElementTree as ElementTree
from dataclasses import dataclass
from pathlib import Path
from typing import Any
from urllib.parse import urlsplit

try:
    import resource as _resource
except ImportError:  # pragma: no cover - only Windows lacks resource
    _resource = None
resource: Any = _resource


LOGGER = logging.getLogger("renderer")

MAX_HEADER_BYTES = 16 * 1024
MAX_BODY_BYTES = 1 * 1024 * 1024
MAX_SVG_BYTES = 512 * 1024
MAX_SVG_ELEMENTS = 10_000
MAX_SVG_DEPTH = 64
MAX_DIMENSION = 4096
MAX_PIXELS = 16 * 1024 * 1024
MAX_OUTPUT_BYTES = 16 * 1024 * 1024
DEFAULT_DEADLINE_MS = 5_000
MAX_DEADLINE_MS = 10_000
PROTOCOL_READ_TIMEOUT_SECONDS = 5.0
REQUEST_READ_DEADLINE_SECONDS = 10.0
MAX_CONCURRENCY = 2
CHILD_MEMORY_BYTES = 512 * 1024 * 1024
CHILD_CPU_SECONDS = 12
CHILD_FILE_BYTES = MAX_OUTPUT_BYTES
CHILD_OPEN_FILES = 64
SOCKET_MODE = 0o660
DEFAULT_SOCKET_PATH = Path("/run/streak-renderer/renderer.sock")
PNG_SIGNATURE = b"\x89PNG\r\n\x1a\n"
HEADER_NAME = re.compile(r"^[!#$%&'*+.^_`|~0-9A-Za-z-]+$")
HEALTH_BODY = b'{"status":"ok"}'


class RendererError(Exception):
    """An expected protocol or rendering failure with an HTTP status."""

    def __init__(self, status: int, message: str) -> None:
        super().__init__(message)
        self.status = status
        self.message = message


class DeadlineExceeded(RendererError):
    def __init__(self) -> None:
        super().__init__(504, "render deadline exceeded")


@dataclass(frozen=True, slots=True)
class RendererConfig:
    socket_path: Path = DEFAULT_SOCKET_PATH
    temp_dir: Path = Path("/tmp/renderer")
    inkscape: str = "/usr/bin/inkscape"
    max_body_bytes: int = MAX_BODY_BYTES
    max_svg_bytes: int = MAX_SVG_BYTES
    max_dimension: int = MAX_DIMENSION
    max_pixels: int = MAX_PIXELS
    max_output_bytes: int = MAX_OUTPUT_BYTES
    max_deadline_ms: int = MAX_DEADLINE_MS
    max_concurrency: int = MAX_CONCURRENCY


@dataclass(frozen=True, slots=True)
class HttpRequest:
    method: str
    target: str
    headers: dict[str, str]
    body: bytes


@dataclass(frozen=True, slots=True)
class RenderRequest:
    svg: str
    width: int
    height: int
    deadline_ms: int


def _bounded_env_int(name: str, default: int, upper_bound: int) -> int:
    value = os.environ.get(name)
    if value is None:
        return default
    if not value.isascii() or not value.isdecimal():
        raise ValueError(f"{name} must be a positive decimal integer")
    parsed = int(value)
    if parsed < 1 or parsed > upper_bound:
        raise ValueError(f"{name} is outside its permitted range")
    return parsed


def load_config() -> RendererConfig:
    """Read only narrowing configuration; limits cannot be raised by env."""

    return RendererConfig(
        socket_path=Path(os.environ.get("RENDERER_SOCKET", str(DEFAULT_SOCKET_PATH))),
        temp_dir=Path(os.environ.get("RENDERER_TMPDIR", "/tmp/renderer")),
        inkscape=os.environ.get("RENDERER_INKSCAPE", "/usr/bin/inkscape"),
        max_body_bytes=_bounded_env_int(
            "RENDERER_MAX_BODY_BYTES", MAX_BODY_BYTES, MAX_BODY_BYTES
        ),
        max_svg_bytes=_bounded_env_int(
            "RENDERER_MAX_SVG_BYTES", MAX_SVG_BYTES, MAX_SVG_BYTES
        ),
        max_dimension=_bounded_env_int(
            "RENDERER_MAX_DIMENSION", MAX_DIMENSION, MAX_DIMENSION
        ),
        max_pixels=_bounded_env_int("RENDERER_MAX_PIXELS", MAX_PIXELS, MAX_PIXELS),
        max_output_bytes=_bounded_env_int(
            "RENDERER_MAX_OUTPUT_BYTES", MAX_OUTPUT_BYTES, MAX_OUTPUT_BYTES
        ),
        max_deadline_ms=_bounded_env_int(
            "RENDERER_MAX_DEADLINE_MS", MAX_DEADLINE_MS, MAX_DEADLINE_MS
        ),
        max_concurrency=_bounded_env_int(
            "RENDERER_MAX_CONCURRENCY", MAX_CONCURRENCY, MAX_CONCURRENCY
        ),
    )


def _duplicate_key_rejected(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
    result: dict[str, Any] = {}
    for key, value in pairs:
        if key in result:
            raise RendererError(400, "duplicate JSON key")
        result[key] = value
    return result


def _reject_json_constant(value: str) -> None:
    raise ValueError(value)


def _read_http_request(
    connection: socket.socket, config: RendererConfig
) -> HttpRequest:
    started = time.monotonic()
    received = bytearray()
    separator = b"\r\n\r\n"
    while separator not in received:
        if len(received) >= MAX_HEADER_BYTES:
            raise RendererError(431, "request headers too large")
        remaining = MAX_HEADER_BYTES - len(received)
        connection.settimeout(
            min(
                PROTOCOL_READ_TIMEOUT_SECONDS,
                max(
                    0.001,
                    started + REQUEST_READ_DEADLINE_SECONDS - time.monotonic(),
                ),
            )
        )
        try:
            chunk = connection.recv(min(4096, remaining + 1))
        except socket.timeout as error:
            raise RendererError(408, "request read timed out") from error
        if not chunk:
            raise RendererError(400, "incomplete HTTP request")
        received.extend(chunk)
        if len(received) > MAX_HEADER_BYTES and separator not in received:
            raise RendererError(431, "request headers too large")

    header_bytes, body = bytes(received).split(separator, 1)
    try:
        header_lines = header_bytes.decode("ascii").split("\r\n")
    except UnicodeDecodeError as error:
        raise RendererError(400, "HTTP headers must be ASCII") from error
    if not header_lines or len(header_lines[0].split(" ")) != 3:
        raise RendererError(400, "malformed request line")
    method, target, version = header_lines[0].split(" ")
    if version != "HTTP/1.1":
        raise RendererError(505, "HTTP version not supported")
    headers: dict[str, str] = {}
    for line in header_lines[1:]:
        if (
            ":" not in line
            or line[: line.index(":")].strip() != line[: line.index(":")]
        ):
            raise RendererError(400, "malformed HTTP header")
        name, value = line.split(":", 1)
        if not HEADER_NAME.fullmatch(name) or name.lower() in headers:
            raise RendererError(400, "duplicate or invalid HTTP header")
        headers[name.lower()] = value.strip()

    if not headers.get("host"):
        raise RendererError(400, "host header is required")

    if method != "POST":
        return HttpRequest(method, target, headers, b"")

    transfer_encoding = headers.get("transfer-encoding")
    if transfer_encoding is not None:
        raise RendererError(400, "chunked requests are not supported")
    content_length = headers.get("content-length")
    if content_length is None:
        raise RendererError(411, "content-length is required")
    if not content_length.isascii() or not content_length.isdecimal():
        raise RendererError(400, "invalid content-length")
    body_length = int(content_length)
    if body_length > config.max_body_bytes:
        raise RendererError(413, "request body too large")

    deadline = started + REQUEST_READ_DEADLINE_SECONDS
    while len(body) < body_length:
        connection.settimeout(
            min(PROTOCOL_READ_TIMEOUT_SECONDS, max(0.001, deadline - time.monotonic()))
        )
        try:
            chunk = connection.recv(min(64 * 1024, body_length - len(body)))
        except socket.timeout as error:
            raise RendererError(408, "request read timed out") from error
        if not chunk:
            raise RendererError(400, "incomplete request body")
        body += chunk
    if len(body) != body_length:
        body = body[:body_length]
    return HttpRequest(method, target, headers, body)


def _validate_svg(svg: str, config: RendererConfig) -> None:
    try:
        svg_bytes = svg.encode("utf-8", "strict")
    except UnicodeEncodeError as error:
        raise RendererError(400, "SVG must be valid UTF-8") from error
    if not svg_bytes or len(svg_bytes) > config.max_svg_bytes or b"\x00" in svg_bytes:
        raise RendererError(
            413 if len(svg_bytes) > config.max_svg_bytes else 400, "invalid SVG size"
        )
    lowered = svg_bytes.lower()
    if b"<!doctype" in lowered or b"<!entity" in lowered or b"<?" in lowered:
        raise RendererError(400, "SVG declarations are not permitted")
    if re.search(
        rb"<\s*script\b|\bon[a-z0-9_-]+\s*=|javascript\s*:|@import|expression\s*\(",
        lowered,
    ):
        raise RendererError(400, "active SVG content is not permitted")

    try:
        root = ElementTree.fromstring(svg_bytes)
    except (ElementTree.ParseError, ValueError) as error:
        raise RendererError(400, "invalid SVG XML") from error
    if root.tag.rsplit("}", 1)[-1].lower() != "svg":
        raise RendererError(400, "SVG root element is required")

    count = 0
    stack: list[tuple[ElementTree.Element, int]] = [(root, 1)]
    while stack:
        element, depth = stack.pop()
        count += 1
        if count > MAX_SVG_ELEMENTS or depth > MAX_SVG_DEPTH:
            raise RendererError(400, "SVG structure is too complex")
        local_name = element.tag.rsplit("}", 1)[-1].lower()
        if local_name in {"script", "foreignobject", "iframe", "object", "image"}:
            raise RendererError(400, "external SVG content is not permitted")
        for attribute, value in element.attrib.items():
            attribute_name = attribute.rsplit("}", 1)[-1].lower()
            value_lower = value.lower()
            if attribute_name in {"href", "src", "base"} and not value_lower.startswith(
                "#"
            ):
                raise RendererError(400, "external SVG references are not permitted")
            if "url(" in value_lower and not re.search(r"url\(\s*#", value_lower):
                raise RendererError(400, "external SVG references are not permitted")
        stack.extend((child, depth + 1) for child in element)


def _parse_render_request(
    http_request: HttpRequest, config: RendererConfig
) -> RenderRequest:
    if http_request.method != "POST":
        raise RendererError(405, "method not allowed")
    if not http_request.target.startswith("/"):
        raise RendererError(400, "origin-form request target is required")
    parsed_target = urlsplit(http_request.target)
    if parsed_target.path != "/render" or parsed_target.query or parsed_target.fragment:
        raise RendererError(404, "not found")
    content_type = http_request.headers.get("content-type", "")
    media_type, _, parameters = content_type.partition(";")
    if media_type.strip().lower() != "application/json":
        raise RendererError(415, "content-type must be application/json")
    if parameters and any(
        parameter.strip().lower() not in {"charset=utf-8", 'charset="utf-8"'}
        for parameter in parameters.split(";")
    ):
        raise RendererError(415, "unsupported content-type parameters")
    try:
        decoded = http_request.body.decode("utf-8", "strict")
        payload = json.loads(
            decoded,
            object_pairs_hook=_duplicate_key_rejected,
            parse_constant=_reject_json_constant,
        )
    except (
        UnicodeDecodeError,
        json.JSONDecodeError,
        RecursionError,
        ValueError,
    ) as error:
        raise RendererError(400, "invalid JSON body") from error
    if not isinstance(payload, dict) or set(payload) - {
        "svg",
        "width",
        "height",
        "deadline_ms",
    }:
        raise RendererError(400, "invalid render request")
    svg = payload.get("svg")
    width = payload.get("width")
    height = payload.get("height")
    if (
        not isinstance(svg, str)
        or not isinstance(width, int)
        or isinstance(width, bool)
        or not isinstance(height, int)
        or isinstance(height, bool)
    ):
        raise RendererError(400, "svg, width, and height are required")
    if (
        width < 1
        or height < 1
        or width > config.max_dimension
        or height > config.max_dimension
        or width * height > config.max_pixels
    ):
        raise RendererError(400, "invalid render dimensions")
    deadline_ms = payload.get("deadline_ms", DEFAULT_DEADLINE_MS)
    if (
        not isinstance(deadline_ms, int)
        or isinstance(deadline_ms, bool)
        or deadline_ms < 1
        or deadline_ms > config.max_deadline_ms
    ):
        raise RendererError(400, "invalid render deadline")
    _validate_svg(svg, config)
    return RenderRequest(svg, width, height, deadline_ms)


def _parse_health_request(http_request: HttpRequest) -> None:
    if not http_request.target.startswith("/"):
        raise RendererError(400, "origin-form request target is required")
    parsed_target = urlsplit(http_request.target)
    if parsed_target.path != "/health" or parsed_target.query or parsed_target.fragment:
        raise RendererError(404, "not found")
    if http_request.method != "GET":
        raise RendererError(405, "method not allowed")


def _set_child_limits() -> None:
    if resource is None:
        return
    limits = (
        (resource.RLIMIT_AS, CHILD_MEMORY_BYTES),
        (resource.RLIMIT_CPU, CHILD_CPU_SECONDS),
        (resource.RLIMIT_FSIZE, CHILD_FILE_BYTES),
        (resource.RLIMIT_NOFILE, CHILD_OPEN_FILES),
    )
    for limit, maximum in limits:
        try:
            resource.setrlimit(limit, (maximum, maximum))
        except (OSError, ValueError):
            LOGGER.warning("unable to apply child resource limit %s", limit)


def _terminate_process(process: subprocess.Popen[bytes]) -> None:
    if process.poll() is not None:
        return
    try:
        if os.name == "posix":
            os.killpg(process.pid, signal.SIGTERM)
        else:  # pragma: no cover - renderer image is Linux
            process.terminate()
        process.wait(timeout=0.5)
    except (ProcessLookupError, subprocess.TimeoutExpired):
        try:
            if os.name == "posix":
                os.killpg(process.pid, signal.SIGKILL)
            else:  # pragma: no cover
                process.kill()
            process.wait(timeout=0.5)
        except (ProcessLookupError, subprocess.TimeoutExpired):
            LOGGER.error("renderer child did not exit after termination")


def _validate_png(data: bytes, request: RenderRequest, config: RendererConfig) -> None:
    if (
        len(data) > config.max_output_bytes
        or len(data) < 24
        or not data.startswith(PNG_SIGNATURE)
    ):
        raise RendererError(502, "renderer returned invalid PNG")
    if int.from_bytes(data[8:12], "big") != 13 or data[12:16] != b"IHDR":
        raise RendererError(502, "renderer returned invalid PNG")
    output_width = int.from_bytes(data[16:20], "big")
    output_height = int.from_bytes(data[20:24], "big")
    if output_width != request.width or output_height != request.height:
        raise RendererError(502, "renderer returned unexpected dimensions")


def _render(request: RenderRequest, config: RendererConfig, deadline: float) -> bytes:
    config.temp_dir.mkdir(mode=0o700, parents=True, exist_ok=True)
    with tempfile.TemporaryDirectory(
        prefix="render-", dir=config.temp_dir
    ) as directory:
        work_dir = Path(directory)
        svg_path = work_dir / "input.svg"
        png_path = work_dir / "output.png"
        svg_path.write_bytes(request.svg.encode("utf-8"))
        os.chmod(svg_path, 0o600)
        child_environment = {
            "PATH": "/usr/bin:/bin",
            "HOME": str(work_dir),
            "TMPDIR": str(work_dir),
            "XDG_CONFIG_HOME": str(work_dir / "config"),
            "XDG_CACHE_HOME": str(work_dir / "cache"),
            "XDG_DATA_HOME": str(work_dir / "data"),
            "LANG": "C.UTF-8",
            "LC_ALL": "C.UTF-8",
        }
        if time.monotonic() >= deadline:
            raise DeadlineExceeded()
        process: subprocess.Popen[bytes] | None = None
        try:
            process = subprocess.Popen(
                [
                    config.inkscape,
                    str(svg_path),
                    "--export-type=png",
                    f"--export-filename={png_path}",
                    f"--export-width={request.width}",
                    f"--export-height={request.height}",
                ],
                cwd=work_dir,
                env=child_environment,
                stdin=subprocess.DEVNULL,
                stdout=subprocess.DEVNULL,
                stderr=subprocess.DEVNULL,
                close_fds=True,
                start_new_session=True,
                preexec_fn=_set_child_limits
                if os.name == "posix" and resource is not None
                else None,
            )
            remaining = deadline - time.monotonic()
            if remaining <= 0:
                raise DeadlineExceeded()
            try:
                process.communicate(timeout=remaining)
            except subprocess.TimeoutExpired as error:
                raise DeadlineExceeded() from error
            if time.monotonic() > deadline:
                raise DeadlineExceeded()
            if process.returncode != 0:
                raise RendererError(502, "renderer failed")
            try:
                output_size = png_path.stat().st_size
            except FileNotFoundError as error:
                raise RendererError(502, "renderer returned no image") from error
            if output_size < 1 or output_size > config.max_output_bytes:
                raise RendererError(502, "renderer output too large")
            data = png_path.read_bytes()
            _validate_png(data, request, config)
            return data
        finally:
            if process is not None and process.poll() is None:
                _terminate_process(process)


def _write_response(
    connection: socket.socket,
    status: int,
    content_type: str,
    body: bytes,
    extra_headers: dict[str, str] | None = None,
) -> None:
    reason = {
        200: "OK",
        400: "Bad Request",
        404: "Not Found",
        405: "Method Not Allowed",
        408: "Request Timeout",
        411: "Length Required",
        413: "Content Too Large",
        415: "Unsupported Media Type",
        431: "Request Header Fields Too Large",
        502: "Bad Gateway",
        503: "Service Unavailable",
        504: "Gateway Timeout",
        505: "HTTP Version Not Supported",
    }.get(status, "Internal Server Error")
    headers = {
        "Content-Type": content_type,
        "Content-Length": str(len(body)),
        "Connection": "close",
        "X-Content-Type-Options": "nosniff",
    }
    if extra_headers:
        headers.update(extra_headers)
    response = [f"HTTP/1.1 {status} {reason}\r\n".encode("ascii")]
    response.extend(
        f"{name}: {value}\r\n".encode("ascii") for name, value in headers.items()
    )
    response.append(b"\r\n")
    response.append(body)
    try:
        connection.sendall(b"".join(response))
    except (BrokenPipeError, ConnectionResetError):
        return


def _write_error(connection: socket.socket, error: RendererError) -> None:
    body = json.dumps({"error": error.message}, separators=(",", ":")).encode("utf-8")
    extra_headers = {"Allow": "POST"} if error.status == 405 else None
    _write_response(
        connection, error.status, "application/json; charset=utf-8", body, extra_headers
    )


def _write_health(connection: socket.socket) -> None:
    _write_response(connection, 200, "application/json; charset=utf-8", HEALTH_BODY)


def handle_connection(connection: socket.socket, config: RendererConfig) -> None:
    try:
        http_request = _read_http_request(connection, config)
        target_path = http_request.target.partition("?")[0].partition("#")[0]
        if http_request.target.startswith("/") and target_path == "/health":
            _parse_health_request(http_request)
            _write_health(connection)
            return
        render_request = _parse_render_request(http_request, config)
        deadline = time.monotonic() + render_request.deadline_ms / 1000
        data = _render(render_request, config, deadline)
        _write_response(connection, 200, "image/png", data)
    except RendererError as error:
        _write_error(connection, error)
    except (OSError, subprocess.SubprocessError) as error:
        LOGGER.warning("renderer request failed: %s", type(error).__name__)
        _write_error(connection, RendererError(502, "renderer unavailable"))
    except Exception:
        LOGGER.exception("unexpected renderer failure")
        _write_error(connection, RendererError(500, "internal renderer error"))


class RendererRequestHandler(socketserver.BaseRequestHandler):
    def handle(self) -> None:
        handle_connection(self.request, self.server.config)  # type: ignore[attr-defined]


_UNIX_STREAM_SERVER: Any = getattr(socketserver, "UnixStreamServer", None)
_SERVER_BASE: Any = _UNIX_STREAM_SERVER or socketserver.TCPServer


class RendererServer(socketserver.ThreadingMixIn, _SERVER_BASE):
    daemon_threads = True
    block_on_close = True
    request_queue_size = 32

    def __init__(self, config: RendererConfig) -> None:
        if _UNIX_STREAM_SERVER is None:
            raise RuntimeError("Unix-domain sockets are required")
        self.config = config
        self._slots = threading.BoundedSemaphore(config.max_concurrency)
        _UNIX_STREAM_SERVER.__init__(
            self, str(config.socket_path), RendererRequestHandler
        )

    def server_bind(self) -> None:
        super().server_bind()
        os.chmod(self.server_address, SOCKET_MODE)

    def process_request(  # type: ignore[override]
        self, request: Any, client_address: Any
    ) -> None:
        if not self._slots.acquire(blocking=False):
            try:
                _write_error(request, RendererError(503, "renderer busy"))
                request.shutdown(socket.SHUT_WR)
                request.setblocking(False)
                while request.recv(64 * 1024):
                    pass
            except OSError as error:
                LOGGER.debug(
                    "busy renderer client closed during response: %s",
                    type(error).__name__,
                )
            finally:
                request.close()
            return
        super().process_request(request, client_address)

    def process_request_thread(  # type: ignore[override]
        self, request: Any, client_address: Any
    ) -> None:
        try:
            super().process_request_thread(request, client_address)
        finally:
            self._slots.release()


def _prepare_socket(path: Path) -> None:
    if not path.is_absolute() or len(str(path).encode()) >= 108:
        raise RuntimeError("renderer socket path must be an absolute Unix-socket path")
    path.parent.mkdir(mode=0o750, parents=True, exist_ok=True)
    try:
        path_stat = path.lstat()
    except FileNotFoundError:
        return
    if not stat.S_ISSOCK(path_stat.st_mode):
        raise RuntimeError(f"refusing to replace non-socket {path}")
    path.unlink()


def create_server(config: RendererConfig) -> RendererServer:
    _prepare_socket(config.socket_path)
    return RendererServer(config)


def _remove_socket(path: Path) -> None:
    try:
        if stat.S_ISSOCK(path.lstat().st_mode):
            path.unlink()
    except FileNotFoundError:
        return
    except OSError as error:
        if error.errno != errno.ENOENT:
            LOGGER.warning("unable to remove renderer socket")


def main() -> int:
    logging.basicConfig(
        level=os.environ.get("LOG_LEVEL", "INFO"), format="%(levelname)s %(message)s"
    )
    try:
        config = load_config()
        server = create_server(config)
    except (OSError, RuntimeError, ValueError) as error:
        LOGGER.critical("renderer startup failed: %s", error)
        return 1

    def stop_server(_signum: int, _frame: Any) -> None:
        import threading

        threading.Thread(target=server.shutdown, daemon=True).start()

    signal.signal(signal.SIGTERM, stop_server)
    signal.signal(signal.SIGINT, stop_server)
    try:
        LOGGER.info("renderer listening on private Unix socket")
        server.serve_forever(poll_interval=0.5)
    finally:
        server.server_close()
        _remove_socket(config.socket_path)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
