<?php

declare(strict_types=1);

namespace App\Service;

use JsonException;
use RuntimeException;

/**
 * Bounded client for the isolated PNG renderer.
 *
 * The renderer speaks a narrow HTTP/1.1 contract over a Unix socket:
 * POST /render with a JSON body returns image/png. Non-success responses are
 * JSON error responses and are mapped to stable client error codes.
 */
final class PngRendererClient
{
    public const DEFAULT_MAX_SVG_BYTES = 2_097_152;
    public const DEFAULT_MAX_PNG_BYTES = 16_777_216;
    public const DEFAULT_MAX_FRAME_BYTES = 20_971_520;
    public const DEFAULT_MAX_HEADER_BYTES = 16_384;
    public const DEFAULT_MAX_DIMENSION = 4096;
    public const DEFAULT_CONNECT_TIMEOUT_MS = 250;
    public const DEFAULT_READ_TIMEOUT_MS = 2_000;
    private const MAX_RENDERER_DEADLINE_MS = 10_000;
    private const PNG_SIGNATURE = "\x89PNG\r\n\x1a\n";
    /** @var list<string> Preferred web setting followed by compatibility aliases. */
    private const SOCKET_ENVIRONMENT_KEYS = ["PNG_RENDERER_SOCKET", "PNG_RENDERER_SOCKET_PATH", "RENDERER_SOCKET"];

    private ?string $socketPath;

    public function __construct(
        ?string $socketPath = null,
        private readonly int $connectTimeoutMs = self::DEFAULT_CONNECT_TIMEOUT_MS,
        private readonly int $readTimeoutMs = self::DEFAULT_READ_TIMEOUT_MS,
        private readonly int $maxSvgBytes = self::DEFAULT_MAX_SVG_BYTES,
        private readonly int $maxPngBytes = self::DEFAULT_MAX_PNG_BYTES,
        private readonly int $maxFrameBytes = self::DEFAULT_MAX_FRAME_BYTES,
        private readonly int $maxDimension = self::DEFAULT_MAX_DIMENSION,
    ) {
        $this->socketPath = $this->resolveSocketPath($socketPath);
        if ($this->connectTimeoutMs <= 0 || $this->readTimeoutMs <= 0) {
            throw new \InvalidArgumentException("Renderer timeouts must be positive.");
        }
        if ($this->maxSvgBytes <= 0 || $this->maxPngBytes <= 0 || $this->maxFrameBytes <= 0) {
            throw new \InvalidArgumentException("Renderer limits must be positive.");
        }
        if ($this->maxDimension <= 0) {
            throw new \InvalidArgumentException("Renderer dimensions must be positive.");
        }
    }

    public function hasConfiguredSocket(): bool
    {
        return $this->socketPath !== null;
    }

    /**
     * Render SVG through the isolated renderer and return decoded PNG bytes.
     *
     * @throws PngRendererException when the renderer cannot safely complete the request
     */
    public function render(string $svg, int $width, int $height): string
    {
        $this->validateDimensions($width, $height);
        if (strlen($svg) > $this->maxSvgBytes) {
            throw $this->error("request_too_large");
        }
        if (!$this->hasConfiguredSocket()) {
            throw $this->error("renderer_unavailable");
        }

        try {
            $body = json_encode(
                [
                    "svg" => $svg,
                    "width" => $width,
                    "height" => $height,
                    "deadline_ms" => min($this->readTimeoutMs, self::MAX_RENDERER_DEADLINE_MS),
                ],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            );
        } catch (JsonException) {
            throw $this->error("request_encoding_failed");
        }

        $request = implode("\r\n", [
            "POST /render HTTP/1.1",
            "Host: renderer",
            "Content-Type: application/json; charset=utf-8",
            "Content-Length: " . strlen($body),
            "Connection: close",
            "",
            $body,
        ]);
        if (strlen($request) > $this->maxFrameBytes) {
            throw $this->error("request_too_large");
        }

        $socket = $this->connect();
        try {
            $deadline = microtime(true) + $this->readTimeoutMs / 1000;
            $this->writeAll($socket, $request, $deadline);
            $response = $this->readResponse($socket, $deadline);
        } finally {
            fclose($socket);
        }

        if ($response["status"] !== 200) {
            throw $this->error($this->mapStatusToErrorCode($response["status"]));
        }
        if ($this->mediaType($response["headers"]["content-type"] ?? "") !== "image/png") {
            throw $this->error("malformed_response");
        }

        $png = $response["body"];
        if (strlen($png) > $this->maxPngBytes) {
            throw $this->error("response_too_large");
        }
        $this->validatePng($png, $width, $height);
        return $png;
    }

    private function resolveSocketPath(?string $socketPath): ?string
    {
        if ($socketPath === null) {
            foreach (self::SOCKET_ENVIRONMENT_KEYS as $key) {
                $value = $this->environmentValue($key);
                if ($value !== null) {
                    $socketPath = $value;
                    break;
                }
            }
        }

        if ($socketPath === null || trim($socketPath) === "") {
            return null;
        }

        $socketPath = trim($socketPath);
        if (strlen($socketPath) > 107 || str_contains($socketPath, "\0")) {
            throw new \InvalidArgumentException("Renderer socket path is invalid.");
        }
        return $socketPath;
    }

    private function environmentValue(string $key): ?string
    {
        foreach ([$_SERVER, $_ENV] as $environment) {
            $value = $environment[$key] ?? null;
            if (is_string($value) && trim($value) !== "") {
                return trim($value);
            }
        }

        $value = getenv($key);
        return is_string($value) && trim($value) !== "" ? trim($value) : null;
    }

    private function validateDimensions(int $width, int $height): void
    {
        if ($width <= 0 || $height <= 0 || $width > $this->maxDimension || $height > $this->maxDimension) {
            throw $this->error("invalid_dimensions");
        }
    }

    /** @return resource */
    private function connect()
    {
        if ($this->socketPath === null) {
            throw $this->error("renderer_unavailable");
        }

        $errno = 0;
        $socket = @stream_socket_client(
            "unix://" . $this->socketPath,
            $errno,
            $error,
            $this->connectTimeoutMs / 1000,
            STREAM_CLIENT_CONNECT,
        );
        if (!is_resource($socket)) {
            throw $this->error("renderer_unavailable");
        }

        stream_set_blocking($socket, false);
        return $socket;
    }

    /** @param resource $socket */
    private function writeAll($socket, string $request, float $deadline): void
    {
        $offset = 0;
        while ($offset < strlen($request)) {
            $this->waitFor($socket, false, $deadline);
            $written = @fwrite($socket, substr($request, $offset));
            if ($written === false || $written === 0) {
                throw $this->error("renderer_write_failed");
            }
            $offset += $written;
        }
    }

    /**
     * @param resource $socket
     * @return array{status:int,headers:array<string,string>,body:string}
     */
    private function readResponse($socket, float $deadline): array
    {
        $buffer = "";
        $separator = "\r\n\r\n";
        while (($headerEnd = strpos($buffer, $separator)) === false) {
            if (strlen($buffer) >= self::DEFAULT_MAX_HEADER_BYTES) {
                throw $this->error("response_headers_too_large");
            }
            $this->waitFor($socket, true, $deadline);
            $chunk = @fread($socket, min(8192, self::DEFAULT_MAX_HEADER_BYTES + 1 - strlen($buffer)));
            if ($chunk === false) {
                throw $this->error("renderer_read_failed");
            }
            if ($chunk === "") {
                throw $this->error("malformed_response");
            }
            $buffer .= $chunk;
            if (strlen($buffer) > self::DEFAULT_MAX_HEADER_BYTES && strpos($buffer, $separator) === false) {
                throw $this->error("response_headers_too_large");
            }
        }

        $headerBytes = substr($buffer, 0, $headerEnd);
        $body = substr($buffer, $headerEnd + strlen($separator));
        $lines = explode("\r\n", $headerBytes);
        $statusLine = array_shift($lines);
        if (!is_string($statusLine) || preg_match('/^HTTP\/1\.1 ([0-9]{3})(?: .*)?$/D', $statusLine, $match) !== 1) {
            throw $this->error("malformed_response");
        }

        $headers = [];
        foreach ($lines as $line) {
            $colon = strpos($line, ":");
            if ($colon === false) {
                throw $this->error("malformed_response");
            }
            $name = strtolower(substr($line, 0, $colon));
            $value = trim(substr($line, $colon + 1));
            if ($name === "" || isset($headers[$name])) {
                throw $this->error("malformed_response");
            }
            $headers[$name] = $value;
        }

        $contentLength = $headers["content-length"] ?? null;
        if ($contentLength === null || !ctype_digit($contentLength)) {
            throw $this->error("malformed_response");
        }
        $length = (int) $contentLength;
        if ($headerEnd + strlen($separator) + $length > $this->maxFrameBytes) {
            throw $this->error("response_too_large");
        }
        if (isset($headers["transfer-encoding"])) {
            throw $this->error("malformed_response");
        }
        if (strlen($body) > $length) {
            throw $this->error("malformed_response");
        }

        while (strlen($body) < $length) {
            $this->waitFor($socket, true, $deadline);
            $chunk = @fread($socket, min(8192, $length - strlen($body)));
            if ($chunk === false) {
                throw $this->error("renderer_read_failed");
            }
            if ($chunk === "") {
                throw $this->error("malformed_response");
            }
            $body .= $chunk;
        }
        if (strlen($body) !== $length) {
            $body = substr($body, 0, $length);
        }

        return ["status" => (int) $match[1], "headers" => $headers, "body" => $body];
    }

    /** @param resource $socket */
    private function waitFor($socket, bool $read, float $deadline): void
    {
        $remaining = $deadline - microtime(true);
        if ($remaining <= 0) {
            throw $this->error("renderer_timeout");
        }

        $readSockets = $read ? [$socket] : [];
        $writeSockets = $read ? [] : [$socket];
        $seconds = (int) floor($remaining);
        $microseconds = (int) (($remaining - $seconds) * 1_000_000);
        $exceptSockets = [];
        $selected = @stream_select($readSockets, $writeSockets, $exceptSockets, $seconds, $microseconds);
        if ($selected === false) {
            throw $this->error("renderer_io_failed");
        }
        if ($selected === 0) {
            throw $this->error("renderer_timeout");
        }
    }

    private function mediaType(string $contentType): string
    {
        return strtolower(trim(explode(";", $contentType, 2)[0]));
    }

    private function mapStatusToErrorCode(int $status): string
    {
        return match ($status) {
            408, 504 => "renderer_timeout",
            413 => "request_too_large",
            502 => "renderer_failed",
            503 => "renderer_unavailable",
            default => "renderer_failed",
        };
    }

    private function validatePng(string $png, int $width, int $height): void
    {
        if (strlen($png) < 33 || !str_starts_with($png, self::PNG_SIGNATURE)) {
            throw $this->error("malformed_png");
        }
        $ihdrLength = unpack("Nlength", substr($png, 8, 4))["length"] ?? 0;
        if ($ihdrLength !== 13 || substr($png, 12, 4) !== "IHDR") {
            throw $this->error("malformed_png");
        }
        $dimensions = unpack("Nwidth/Nheight", substr($png, 16, 8));
        if (($dimensions["width"] ?? 0) !== $width || ($dimensions["height"] ?? 0) !== $height) {
            throw $this->error("renderer_dimension_mismatch");
        }
    }

    private function error(string $code): PngRendererException
    {
        return new PngRendererException($code);
    }
}

final class PngRendererException extends RuntimeException
{
    public function __construct(public readonly string $rendererCode)
    {
        parent::__construct("PNG renderer error: {$rendererCode}", 500);
    }
}
