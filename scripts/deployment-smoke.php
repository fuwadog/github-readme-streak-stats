<?php

declare(strict_types=1);

/**
 * Run read-only checks against the canonical production deployment.
 *
 * This is deliberately opt-in. It never prints the configured URL, username,
 * response bodies, request headers, or any other credential-bearing value.
 */
const CANONICAL_HOST = "github-readme-streak-stats-black-phi.vercel.app";
const API_RESPONSE_V1 = [
    "status" => 200,
    "content_type" => "image/svg+xml",
    "cache_control" => "public, max-age=",
];

final class SmokeFailure extends RuntimeException {}

/** @return array{status: int, headers: array<string, string>, body: string} */
function request(string $url): array
{
    $headers = [];
    $handle = curl_init($url);
    if ($handle === false) {
        throw new SmokeFailure("Unable to initialize the HTTP client.");
    }

    curl_setopt_array($handle, [
        CURLOPT_CUSTOMREQUEST => "GET",
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$headers): int {
            $separator = strpos($line, ":");
            if ($separator !== false) {
                $name = strtolower(trim(substr($line, 0, $separator)));
                $headers[$name] = trim(substr($line, $separator + 1));
            }
            return strlen($line);
        },
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_USERAGENT => "deployment-smoke/1",
    ]);

    $body = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $error = curl_errno($handle);
    unset($handle);

    if ($body === false || $error !== 0 || $status === 0) {
        throw new SmokeFailure("The production probe failed to receive a response.");
    }

    return ["status" => $status, "headers" => $headers, "body" => $body];
}

function requireEnvironment(string $key): string
{
    $value = getenv($key);
    if (!is_string($value) || trim($value) === "") {
        throw new SmokeFailure("{$key} is required when deployment smoke checks are enabled.");
    }
    return trim($value);
}

function assertCondition(bool $condition, string $message): void
{
    if (!$condition) {
        throw new SmokeFailure($message);
    }
}

function assertSecurityHeaders(array $headers): void
{
    assertCondition(($headers["x-content-type-options"] ?? "") === "nosniff", "Missing nosniff security header.");
    assertCondition(($headers["x-frame-options"] ?? "") === "DENY", "Missing frame protection header.");
    assertCondition(
        ($headers["referrer-policy"] ?? "") === "strict-origin-when-cross-origin",
        "Missing referrer policy header.",
    );
}

function assertSvg(array $response): void
{
    assertCondition($response["status"] === API_RESPONSE_V1["status"], "Unexpected SVG response status.");
    assertCondition(
        str_starts_with(strtolower($response["headers"]["content-type"] ?? ""), API_RESPONSE_V1["content_type"]),
        "Unexpected SVG content type.",
    );
    assertCondition(str_contains($response["body"], "<svg"), "SVG response does not contain an SVG document.");
    assertCondition(
        str_starts_with($response["headers"]["cache-control"] ?? "", API_RESPONSE_V1["cache_control"]),
        "Successful SVG response is not publicly cacheable.",
    );
    assertSecurityHeaders($response["headers"]);
}

function assertNoSensitiveContent(string $body): void
{
    assertCondition(
        preg_match("/(?:TOKEN\d*|BEGIN (?:RSA|OPENSSH) PRIVATE KEY|gh[pousr]_[A-Za-z0-9]+)/i", $body) !== 1,
        "Probe response contained sensitive content.",
    );
}

function runSmokeChecks(): void
{
    $productionUrl = rtrim(requireEnvironment("PRODUCTION_URL"), "/");
    $productionUser = requireEnvironment("PRODUCTION_USER");
    $parsedUrl = parse_url($productionUrl);
    assertCondition(
        is_array($parsedUrl) &&
            ($parsedUrl["scheme"] ?? "") === "https" &&
            ($parsedUrl["host"] ?? "") === CANONICAL_HOST &&
            in_array($parsedUrl["path"] ?? "", ["", "/"], true) &&
            !isset($parsedUrl["query"], $parsedUrl["fragment"], $parsedUrl["user"], $parsedUrl["pass"]),
        "PRODUCTION_URL must be the canonical HTTPS host.",
    );
    assertCondition(
        preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,37}[A-Za-z0-9])?$/', $productionUser) === 1,
        "PRODUCTION_USER is not a valid GitHub username.",
    );

    $root = $productionUrl . "/?user=" . rawurlencode($productionUser) . "&type=svg";
    $svg = request($root);
    assertSvg($svg);
    assertNoSensitiveContent($svg["body"]);

    $demo = request($productionUrl . "/demo/");
    assertCondition($demo["status"] === 200, "Demo route did not return success.");
    assertCondition(str_contains($demo["body"], "GitHub Readme Streak Stats"), "Demo fixture is unavailable.");
    assertCondition(!str_contains($demo["body"], "api/index.php"), "Demo response exposes the API route.");
    assertNoSensitiveContent($demo["body"]);

    $preview = request($productionUrl . "/demo/preview.php?mode=daily&type=json");
    assertCondition($preview["status"] === 200, "Demo preview did not return success.");
    assertCondition(
        str_starts_with(strtolower($preview["headers"]["content-type"] ?? ""), "application/json"),
        "Demo preview did not return JSON.",
    );
    assertCondition(
        str_contains($preview["body"], '"totalContributions":2048'),
        "Demo preview is not using fixture data.",
    );
    assertNoSensitiveContent($preview["body"]);

    $png = request($productionUrl . "/?user=" . rawurlencode($productionUser) . "&type=png");
    assertCondition($png["status"] === 500, "PNG fallback did not return the documented error status.");
    assertCondition(
        str_starts_with(strtolower($png["headers"]["content-type"] ?? ""), "image/svg+xml"),
        "PNG fallback did not return SVG.",
    );
    assertCondition(
        str_contains($png["body"], "PNG conversion failed. Please use type=svg instead."),
        "PNG fallback message is missing.",
    );
    assertCondition(
        str_starts_with($png["headers"]["cache-control"] ?? "", "no-cache, no-store"),
        "PNG fallback must not be cached.",
    );
    assertNoSensitiveContent($png["body"]);
}

if (getenv("PRODUCTION_URL") === false && getenv("PRODUCTION_USER") === false) {
    fwrite(STDOUT, "Deployment smoke checks skipped (set PRODUCTION_URL and PRODUCTION_USER to run).\n");
    exit(0);
}

try {
    runSmokeChecks();
    fwrite(STDOUT, "Deployment smoke checks passed.\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, "Deployment smoke checks failed: {$error->getMessage()}\n");
    exit(1);
}
