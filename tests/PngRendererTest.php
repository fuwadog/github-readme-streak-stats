<?php

declare(strict_types=1);

use App\Output\SvgGenerator;
use App\Service\PngRendererClient;
use App\Service\PngRendererException;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . "/vendor/autoload.php";
require_once dirname(__DIR__) . "/api/src/Service/PngRendererClient.php";
require_once dirname(__DIR__) . "/api/src/Output/SvgGenerator.php";

final class PngRendererTest extends TestCase
{
    private const RENDERER_CONTRACT = [
        "method" => "POST",
        "path" => "/render",
        "transport" => "private-unix-socket",
        "content_type" => "image/png",
    ];
    private const WIDTH = 10;
    private const HEIGHT = 20;

    protected function tearDown(): void
    {
        unset(
            $_SERVER["VERCEL"],
            $_SERVER["AWS_LAMBDA_FUNCTION_NAME"],
            $_SERVER["PNG_RENDERER_SOCKET"],
            $_SERVER["PNG_RENDERER_SOCKET_PATH"],
            $_SERVER["RENDERER_SOCKET"],
            $_ENV["PNG_RENDERER_SOCKET"],
            $_ENV["PNG_RENDERER_SOCKET_PATH"],
            $_ENV["RENDERER_SOCKET"],
        );
    }

    public function testPreferredWebRendererSocketTakesPrecedenceOverAliases(): void
    {
        $capture = $this->runRenderer(
            $this->httpResponse(200, "image/png", $this->fixturePng(self::WIDTH, self::HEIGHT)),
            function (string $socket): string {
                $_SERVER["PNG_RENDERER_SOCKET"] = $socket;
                $_SERVER["PNG_RENDERER_SOCKET_PATH"] = "/tmp/missing-renderer-alias.sock";
                $_SERVER["RENDERER_SOCKET"] = "/tmp/missing-sidecar-alias.sock";

                return (new PngRendererClient())->render("<svg/>", self::WIDTH, self::HEIGHT);
            },
        );

        $this->assertStringStartsWith("POST /render HTTP/1.1\r\n", $capture["request"]);
    }

    public function testEmptyServerValueFallsBackToEnvironmentValue(): void
    {
        $capture = $this->runRenderer(
            $this->httpResponse(200, "image/png", $this->fixturePng(self::WIDTH, self::HEIGHT)),
            function (string $socket): string {
                $_SERVER["PNG_RENDERER_SOCKET"] = "";
                $_ENV["PNG_RENDERER_SOCKET"] = $socket;

                return (new PngRendererClient())->render("<svg/>", self::WIDTH, self::HEIGHT);
            },
        );

        $this->assertStringStartsWith("POST /render HTTP/1.1\r\n", $capture["request"]);
    }

    public function testRendererSocketAliasesAreRecognized(): void
    {
        foreach (["PNG_RENDERER_SOCKET_PATH", "RENDERER_SOCKET"] as $key) {
            $capture = $this->runRenderer(
                $this->httpResponse(200, "image/png", $this->fixturePng(self::WIDTH, self::HEIGHT)),
                function (string $socket) use ($key): string {
                    $_SERVER[$key] = $socket;

                    return (new PngRendererClient())->render("<svg/>", self::WIDTH, self::HEIGHT);
                },
            );

            $this->assertStringStartsWith("POST /render HTTP/1.1\r\n", $capture["request"]);
            unset($_SERVER[$key]);
        }
    }

    public function testExplicitRendererSocketTakesPrecedenceOverEnvironment(): void
    {
        $capture = $this->runRenderer(
            $this->httpResponse(200, "image/png", $this->fixturePng(self::WIDTH, self::HEIGHT)),
            function (string $socket): string {
                $_SERVER["PNG_RENDERER_SOCKET"] = "/tmp/missing-renderer.sock";

                return (new PngRendererClient($socket))->render("<svg/>", self::WIDTH, self::HEIGHT);
            },
        );

        $this->assertStringStartsWith("POST /render HTTP/1.1\r\n", $capture["request"]);
    }

    public function testHttpRendererContractReturnsValidatedPng(): void
    {
        $png = $this->fixturePng(self::WIDTH, self::HEIGHT);
        $capture = $this->runRenderer($this->httpResponse(200, "image/png", $png), function (
            string $socket,
            string $capture,
        ): string {
            return (new PngRendererClient($socket))->render("<svg width='10' height='20'/>", self::WIDTH, self::HEIGHT);
        });

        $this->assertSame($png, $capture["body"]);
        $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", $capture["body"]);
        $this->assertStringStartsWith("POST /render HTTP/1.1\r\n", $capture["request"]);
        $this->assertStringContainsString("Content-Type: application/json; charset=utf-8\r\n", $capture["request"]);
        $this->assertStringNotContainsString("png_base64", $capture["request"]);
        $this->assertSame(
            [
                "svg" => "<svg width='10' height='20'/>",
                "width" => self::WIDTH,
                "height" => self::HEIGHT,
                "deadline_ms" => PngRendererClient::DEFAULT_READ_TIMEOUT_MS,
            ],
            json_decode(
                substr($capture["request"], strpos($capture["request"], "\r\n\r\n") + 4),
                true,
                512,
                JSON_THROW_ON_ERROR,
            ),
        );
    }

    public function testHttpStatusIsMappedToStableRendererError(): void
    {
        $this->expectException(PngRendererException::class);
        $this->expectExceptionMessage("renderer_timeout");

        $this->runRenderer(
            $this->httpResponse(504, "application/json", '{"error":"render deadline exceeded"}'),
            function (string $socket): string {
                return (new PngRendererClient($socket))->render("<svg/>", self::WIDTH, self::HEIGHT);
            },
        );
    }

    public function testMalformedPngIsRejected(): void
    {
        $this->expectException(PngRendererException::class);
        $this->expectExceptionMessage("malformed_png");

        $this->runRenderer($this->httpResponse(200, "image/png", "not-png"), function (string $socket): string {
            return (new PngRendererClient($socket))->render("<svg/>", self::WIDTH, self::HEIGHT);
        });
    }

    public function testRequestBoundIsEnforced(): void
    {
        $this->expectException(PngRendererException::class);
        $this->expectExceptionMessage("request_too_large");

        (new PngRendererClient(null, maxSvgBytes: 4))->render("<svg/>", self::WIDTH, self::HEIGHT);
    }

    public function testPngResponseBoundIsEnforced(): void
    {
        $this->expectException(PngRendererException::class);
        $this->expectExceptionMessage("response_too_large");

        $this->runRenderer(
            $this->httpResponse(200, "image/png", $this->fixturePng(self::WIDTH, self::HEIGHT)),
            function (string $socket): string {
                return (new PngRendererClient($socket, maxPngBytes: 32))->render("<svg/>", self::WIDTH, self::HEIGHT);
            },
        );
    }

    public function testRendererContractUsesBoundedPrivateUnixSocket(): void
    {
        $this->assertSame("POST", self::RENDERER_CONTRACT["method"]);
        $this->assertSame("/render", self::RENDERER_CONTRACT["path"]);
        $this->assertSame("private-unix-socket", self::RENDERER_CONTRACT["transport"]);
        $this->assertSame("image/png", self::RENDERER_CONTRACT["content_type"]);
        $this->assertLessThanOrEqual(10_000, PngRendererClient::DEFAULT_READ_TIMEOUT_MS);
        $this->assertLessThanOrEqual(
            PngRendererClient::DEFAULT_MAX_FRAME_BYTES,
            PngRendererClient::DEFAULT_MAX_SVG_BYTES + PngRendererClient::DEFAULT_MAX_HEADER_BYTES,
        );

        $source = file_get_contents(dirname(__DIR__) . "/api/src/Service/PngRendererClient.php");
        $this->assertIsString($source);
        $this->assertStringContainsString('"unix://"', $source);
        $this->assertStringNotContainsString('"tcp://"', $source);
        $this->assertStringNotContainsString("curl_", $source);
    }

    public function testInvalidDimensionsAreRejectedBeforeRendererConnection(): void
    {
        foreach ([[0, self::HEIGHT], [-1, self::HEIGHT], [self::WIDTH, 0], [4097, self::HEIGHT]] as [$width, $height]) {
            try {
                (new PngRendererClient("/tmp/renderer-must-not-connect.sock"))->render("<svg/>", $width, $height);
                $this->fail("Invalid renderer dimensions were accepted.");
            } catch (PngRendererException $error) {
                $this->assertSame("invalid_dimensions", $error->rendererCode);
            }
        }
    }

    public function testInvalidResponseFrameIsRejectedBeforeBodyRead(): void
    {
        $this->expectException(PngRendererException::class);
        $this->expectExceptionMessage("response_too_large");

        $this->runRenderer(
            "HTTP/1.1 200 OK\r\n" .
                "Content-Type: image/png\r\n" .
                "Content-Length: 1024\r\n" .
                "Connection: close\r\n\r\n",
            function (string $socket): string {
                return (new PngRendererClient($socket, maxFrameBytes: 256))->render(
                    "<svg/>",
                    self::WIDTH,
                    self::HEIGHT,
                );
            },
        );
    }

    public function testSuccessfulPngOutputRemainsPng(): void
    {
        $stats = [
            "mode" => "daily",
            "totalContributions" => 1,
            "firstContribution" => "2020-01-01",
            "longestStreak" => ["start" => "2020-01-01", "end" => "2020-01-01", "length" => 1],
            "currentStreak" => ["start" => "2020-01-01", "end" => "2020-01-01", "length" => 1],
            "excludedDays" => [],
        ];
        $png = $this->fixturePng(300, 170);

        $capture = $this->runRenderer($this->httpResponse(200, "image/png", $png), function (string $socket) use (
            $stats,
        ): string {
            $response = (new SvgGenerator(new PngRendererClient($socket)))->generateOutput($stats, [
                "type" => "png",
                "card_width" => "300",
                "card_height" => "170",
            ]);

            $this->assertSame(200, $response["status"]);
            $this->assertSame("image/png", $response["contentType"]);
            return $response["body"];
        });

        $this->assertSame($png, $capture["body"]);
    }

    public function testRendererFailureUsesSafeSvgFallback(): void
    {
        $stats = [
            "mode" => "daily",
            "totalContributions" => 1,
            "firstContribution" => "2020-01-01",
            "longestStreak" => ["start" => "2020-01-01", "end" => "2020-01-01", "length" => 1],
            "currentStreak" => ["start" => "2020-01-01", "end" => "2020-01-01", "length" => 1],
            "excludedDays" => [],
        ];
        $socket = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "renderer-fallback-" . bin2hex(random_bytes(8)) . ".sock";

        $response = (new SvgGenerator(new PngRendererClient($socket)))->generateOutput($stats, ["type" => "png"]);

        $this->assertSame(500, $response["status"]);
        $this->assertSame("image/svg+xml", $response["contentType"]);
        $this->assertStringStartsWith("<svg", $response["body"]);
        $this->assertStringContainsString("PNG renderer error: renderer_unavailable", $response["body"]);
        $this->assertStringNotContainsString("\x89PNG", $response["body"]);
        $this->assertStringNotContainsString("<script", $response["body"]);
        $this->assertStringNotContainsString($socket, $response["body"]);
    }

    public function testPngFallbackPreservesWhitelistDenialStatus(): void
    {
        $socket = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "renderer-denial-" . bin2hex(random_bytes(8)) . ".sock";

        $response = (new SvgGenerator(new PngRendererClient($socket)))->generateOutput(
            "User not in whitelist.",
            ["type" => "png"],
            403,
        );

        $this->assertSame(403, $response["status"]);
        $this->assertSame("image/svg+xml", $response["contentType"]);
        $this->assertStringContainsString("User not in whitelist.", $response["body"]);
        $this->assertStringNotContainsString("renderer_unavailable", $response["body"]);
    }

    public function testConfiguredSidecarFailureDoesNotUseLocalFallback(): void
    {
        $socket = tempnam(sys_get_temp_dir(), "png-renderer-missing-");
        $this->assertIsString($socket);
        unlink($socket);

        try {
            $this->expectException(PngRendererException::class);
            $this->expectExceptionMessage("renderer_unavailable");
            (new SvgGenerator(new PngRendererClient($socket)))->convertSvgToPng(
                "<svg width='10px' height='20px'/>",
                self::WIDTH,
                self::HEIGHT,
            );
        } finally {
            if (file_exists($socket)) {
                unlink($socket);
            }
        }
    }

    public function testConfiguredSidecarIsSelectedByGenerator(): void
    {
        $png = $this->fixturePng(self::WIDTH, self::HEIGHT);
        $capture = $this->runRenderer($this->httpResponse(200, "image/png", $png), function (string $socket): string {
            return (new SvgGenerator(new PngRendererClient($socket)))->convertSvgToPng(
                "<svg width='10px' height='20px'><style>animation: test;</style></svg>",
                self::WIDTH,
                self::HEIGHT,
            );
        });

        $this->assertSame($png, $capture["body"]);
        $this->assertStringNotContainsString("animation", $capture["request"]);
    }

    public function testVercelWithoutSidecarRetainsUnavailableRendererBehavior(): void
    {
        $_SERVER["VERCEL"] = "1";

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("not available on serverless platforms");

        (new SvgGenerator(new PngRendererClient(null)))->convertSvgToPng(
            "<svg width='10px' height='20px'/>",
            self::WIDTH,
            self::HEIGHT,
        );
    }

    public function testNoSocketFallsBackToLocalRendererPath(): void
    {
        try {
            $png = (new SvgGenerator(new PngRendererClient(null)))->convertSvgToPng(
                "<svg width='10px' height='20px'/>",
                self::WIDTH,
                self::HEIGHT,
            );
            $this->assertStringStartsWith("\x89PNG", $png);
        } catch (\InvalidArgumentException $error) {
            $this->assertMatchesRegularExpression("/Inkscape|type=svg/", $error->getMessage());
        }
    }

    /** @return array{request:string,body:string} */
    private function runRenderer(string $response, callable $render): array
    {
        $socket = tempnam(sys_get_temp_dir(), "png-renderer-");
        $ready = tempnam(sys_get_temp_dir(), "png-renderer-ready-");
        $capture = tempnam(sys_get_temp_dir(), "png-renderer-capture-");
        if ($socket === false || $ready === false || $capture === false) {
            $this->fail("Unable to allocate renderer test files.");
        }
        unlink($socket);
        unlink($ready);
        unlink($capture);

        $code = <<<'PHP'
                            $server = stream_socket_server("unix://" . $argv[1], $errno, $error);
                            if ($server === false) { exit(2); }
                            file_put_contents($argv[2], "ready");
                            $client = stream_socket_accept($server, 5);
                            if ($client === false) { exit(3); }
                            stream_set_timeout($client, 5);
                            $request = "";
                            while (strpos($request, "\r\n\r\n") === false) {
                                $chunk = fread($client, 8192);
                                if ($chunk === false || $chunk === "") { exit(4); }
                                $request .= $chunk;
                            }
                            $length = 0;
                            if (preg_match('/\r\nContent-Length: ([0-9]+)\r\n/i', $request, $match) === 1) { $length = (int) $match[1]; }
                            while (strlen(substr($request, strpos($request, "\r\n\r\n") + 4)) < $length) {
                                $chunk = fread($client, 8192);
                                if ($chunk === false || $chunk === "") { exit(5); }
                                $request .= $chunk;
                            }
                            file_put_contents($argv[3], $request);
                            fwrite($client, base64_decode($argv[4], true));
                            fclose($client);
                            fclose($server);
        PHP;
        $process = proc_open(
            [PHP_BINARY, "-r", $code, $socket, $ready, $capture, base64_encode($response)],
            [1 => ["pipe", "w"], 2 => ["pipe", "w"]],
            $pipes,
        );
        if (!is_resource($process)) {
            $this->fail("Unable to start renderer test process.");
        }

        try {
            $deadline = microtime(true) + 2;
            while (!file_exists($ready) && microtime(true) < $deadline) {
                usleep(10_000);
            }
            if (!file_exists($ready)) {
                $this->fail("Renderer test process did not become ready.");
            }
            $body = $render($socket, $capture);
            proc_close($process);
            $request = file_get_contents($capture);
            $this->assertIsString($request);
            return ["request" => $request, "body" => $body];
        } finally {
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
            foreach ([$socket, $ready, $capture] as $path) {
                if (is_string($path) && file_exists($path)) {
                    unlink($path);
                }
            }
        }
    }

    private function httpResponse(int $status, string $contentType, string $body): string
    {
        return "HTTP/1.1 {$status} OK\r\n" .
            "Content-Type: {$contentType}\r\n" .
            "Content-Length: " .
            strlen($body) .
            "\r\n" .
            "Connection: close\r\n\r\n" .
            $body;
    }

    private function fixturePng(int $width, int $height): string
    {
        return "\x89PNG\r\n\x1a\n" .
            pack("N", 13) .
            "IHDR" .
            pack("NN", $width, $height) .
            "\x08\x06\x00\x00\x00" .
            pack("N", 0);
    }
}
