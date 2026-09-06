<?php

declare(strict_types=1);

require_once dirname(__DIR__, 1) . "/vendor/autoload.php";
require_once "stats.php";
require_once "card.php";

define("API_ROOT", __DIR__);
const MAX_QUERY_STRING_BYTES = 8192;
const MAX_REQUEST_BODY_BYTES = 8192;
const MAX_RATE_METADATA_BYTES = 8192;
const CLIENT_ERROR_MESSAGE = "Unable to process request.";
const INTERNAL_ROUTES = ["api", "demo-index", "demo-preview", "demo-static"];

// Set UTC timezone for consistent date handling across all environments
date_default_timezone_set("UTC");

header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");
header("Referrer-Policy: strict-origin-when-cross-origin");

$dotenv = \Dotenv\Dotenv::createImmutable(dirname(__DIR__, 1));
$dotenv->safeLoad();

function getEnvironmentValue(string $key): ?string
{
    if (array_key_exists($key, $_SERVER)) {
        return is_string($_SERVER[$key]) ? $_SERVER[$key] : null;
    }
    if (array_key_exists($key, $_ENV)) {
        return is_string($_ENV[$key]) ? $_ENV[$key] : null;
    }
    $value = getenv($key);
    return $value !== false ? $value : null;
}

function isServerlessEnvironment(): bool
{
    $vercel = getEnvironmentValue("VERCEL");
    $lambda = getEnvironmentValue("AWS_LAMBDA_FUNCTION_NAME");
    return $vercel === "1" || ($lambda !== null && trim($lambda) !== "");
}

function isExternalRateLimitConfigured(): bool
{
    foreach (["EXTERNAL_RATE_LIMITER", "EXTERNAL_RATE_LIMITING", "RATE_LIMIT_EXTERNAL"] as $key) {
        $value = getEnvironmentValue($key);
        if ($value !== null && in_array(strtolower(trim($value)), ["true", "1"], true)) {
            return true;
        }
    }
    return false;
}

function isIpInCidr(string $ip, string $cidr): bool
{
    $parts = explode("/", trim($cidr), 2);
    if (
        count($parts) !== 2 ||
        preg_match("/^\\d+$/", $parts[1]) !== 1 ||
        filter_var($ip, FILTER_VALIDATE_IP) === false
    ) {
        return false;
    }

    $ipBinary = inet_pton($ip);
    $networkBinary = inet_pton($parts[0]);
    if ($ipBinary === false || $networkBinary === false || strlen($ipBinary) !== strlen($networkBinary)) {
        return false;
    }

    $prefixLength = (int) $parts[1];
    $maxPrefixLength = strlen($ipBinary) * 8;
    if ($prefixLength < 0 || $prefixLength > $maxPrefixLength) {
        return false;
    }

    $fullBytes = intdiv($prefixLength, 8);
    if ($fullBytes > 0 && substr($ipBinary, 0, $fullBytes) !== substr($networkBinary, 0, $fullBytes)) {
        return false;
    }
    $remainingBits = $prefixLength % 8;
    if ($remainingBits === 0) {
        return true;
    }

    $mask = (0xff << 8 - $remainingBits) & 0xff;
    return (ord($ipBinary[$fullBytes]) & $mask) === (ord($networkBinary[$fullBytes]) & $mask);
}

function isTrustedProxy(string $ip): bool
{
    $configuredCidrs = getEnvironmentValue("TRUSTED_PROXY_CIDRS") ?? getEnvironmentValue("TRUSTED_PROXY_CIDR");
    if ($configuredCidrs === null || trim($configuredCidrs) === "") {
        return false;
    }

    foreach (explode(",", $configuredCidrs) as $cidr) {
        if (isIpInCidr($ip, trim($cidr))) {
            return true;
        }
    }
    return false;
}

function getClientIp(): string
{
    $remoteAddress = $_SERVER["REMOTE_ADDR"] ?? null;
    if (!is_string($remoteAddress) || filter_var($remoteAddress, FILTER_VALIDATE_IP) === false) {
        return "unknown";
    }

    $cloudflareAddress = $_SERVER["HTTP_CF_CONNECTING_IP"] ?? null;
    if (is_string($cloudflareAddress) && filter_var($cloudflareAddress, FILTER_VALIDATE_IP) !== false) {
        if (isTrustedProxy($remoteAddress)) {
            return $cloudflareAddress;
        }
    }
    return $remoteAddress;
}

/**
 * Simple file-based rate limiter (100 requests per minute per IP).
 * Serverless deployments have no shared backend here, so they fail closed
 * rather than silently disabling abuse protection.
 *
 * @return bool True if request is allowed, false if rate limited or unavailable
 */
function checkRateLimit(): bool
{
    if (isServerlessEnvironment()) {
        if (!isExternalRateLimitConfigured()) {
            throw new RuntimeException("Serverless rate limiting requires an external rate limiter.", 500);
        }
        return true;
    }

    $ip = getClientIp();
    $cacheDir = sys_get_temp_dir();
    $rateFile = $cacheDir . "/rate_limit_" . md5($ip);
    $now = time();
    $windowStart = $now - 60;
    $maxRequests = 100;

    $fp = fopen($rateFile, "c+");
    if ($fp === false || !flock($fp, LOCK_EX)) {
        if (is_resource($fp)) {
            fclose($fp);
        }
        return false;
    }
    if (!rewind($fp)) {
        flock($fp, LOCK_UN);
        fclose($fp);
        return false;
    }
    $data = stream_get_contents($fp, MAX_RATE_METADATA_BYTES + 1);
    if ($data === false || strlen($data) > MAX_RATE_METADATA_BYTES) {
        flock($fp, LOCK_UN);
        fclose($fp);
        return false;
    }
    $requests = $data === "" ? [] : json_decode($data, true);
    if (!is_array($requests)) {
        flock($fp, LOCK_UN);
        fclose($fp);
        return false;
    }
    $requests = array_filter($requests, function ($ts) use ($windowStart) {
        return (is_int($ts) || is_float($ts)) && $ts > $windowStart;
    });

    if (count($requests) >= $maxRequests) {
        flock($fp, LOCK_UN);
        fclose($fp);
        return false;
    }

    $requests[] = $now;
    $encodedRequests = json_encode($requests);
    $writeSucceeded =
        $encodedRequests !== false &&
        ftruncate($fp, 0) &&
        rewind($fp) &&
        fwrite($fp, $encodedRequests) !== false &&
        fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return $writeSucceeded;
}

/**
 * Validate username input
 *
 * @param string $user Username to validate
 * @return bool True if valid
 */
function isValidUsername(string $user): bool
{
    return preg_match("/^[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,37}[a-zA-Z0-9])?$/", $user) === 1;
}

/**
 * Validate starting year parameter
 *
 * @param int|null $year Year to validate
 * @return bool True if valid
 */
function isValidStartingYear(?int $year): bool
{
    if ($year === null) {
        return true;
    }
    $currentYear = (int) date("Y");
    return $year >= 2005 && $year <= $currentYear;
}

/**
 * Validate exclude days parameter
 *
 * @param string $excludeDaysRaw Raw exclude_days parameter
 * @return bool True if valid
 */
function isValidExcludeDays(string $excludeDaysRaw): bool
{
    if ($excludeDaysRaw === "") {
        return true;
    }
    $validDays = ["sun", "mon", "tue", "wed", "thu", "fri", "sat"];
    $days = explode(",", $excludeDaysRaw);
    foreach ($days as $day) {
        $trimmed = strtolower(trim($day));
        if ($trimmed === "" || !in_array($trimmed, $validDays, true)) {
            return false;
        }
    }
    return true;
}

function validateRequestLimits(): void
{
    $method = $_SERVER["REQUEST_METHOD"] ?? "GET";
    if (!is_string($method) || !in_array(strtoupper($method), ["GET", "HEAD"], true)) {
        header("Allow: GET, HEAD");
        throw new InvalidArgumentException("Method not allowed.", 405);
    }

    $queryString = $_SERVER["QUERY_STRING"] ?? null;
    if ($queryString === null && isset($_SERVER["REQUEST_URI"]) && is_string($_SERVER["REQUEST_URI"])) {
        $queryString = parse_url($_SERVER["REQUEST_URI"], PHP_URL_QUERY) ?? "";
    }
    $queryString ??= "";
    if (!is_string($queryString) || strlen($queryString) > MAX_QUERY_STRING_BYTES) {
        throw new InvalidArgumentException("Request query is too large.", 413);
    }

    $contentLength = $_SERVER["CONTENT_LENGTH"] ?? "";
    if (
        $contentLength !== "" &&
        (!is_string($contentLength) || !ctype_digit($contentLength) || (int) $contentLength > MAX_REQUEST_BODY_BYTES)
    ) {
        throw new InvalidArgumentException("Request body is too large.", 413);
    }
}

function getInternalRoute(): string
{
    $route = $_GET["__route"] ?? "api";
    unset($_GET["__route"]);
    if (!is_string($route) || !in_array($route, INTERNAL_ROUTES, true)) {
        throw new InvalidArgumentException("Not found.", 404);
    }
    return $route;
}

function getErrorStatus(Throwable $error): int
{
    $status = $error->getCode();
    return $status >= 400 && $status <= 599 ? $status : 500;
}

function getErrorType(Throwable $error): string
{
    $class = str_replace("\\", "/", $error::class);
    $type = basename($class);
    if (preg_match("/^[A-Za-z][A-Za-z0-9_.-]{0,31}$/", $type) !== 1) {
        return "unknown";
    }
    return $type;
}

try {
    $route = getInternalRoute();
    validateRequestLimits();

    switch ($route) {
        case "api":
            break;
        case "demo-index":
            require __DIR__ . "/demo/index.php";
            exit();
        case "demo-preview":
            require __DIR__ . "/demo/preview.php";
            exit();
        case "demo-static":
            require __DIR__ . "/demo/vercel-static.php";
            exit();
        default:
            throw new InvalidArgumentException("Not found.", 404);
    }

    // redirect to demo site if user is not given
    if (!isset($_GET["user"])) {
        header("Location: demo/");
        exit();
    }

    if (!checkRateLimit()) {
        header("Retry-After: 60");
        renderOutput("Rate limit exceeded. Please try again later.", 429);
    }

    $user = $_GET["user"] ?? null;
    if (!is_string($user)) {
        throw new InvalidArgumentException("Invalid username provided.", 400);
    }

    $startingYearRaw = $_GET["starting_year"] ?? null;
    if (
        $startingYearRaw !== null &&
        (!is_string($startingYearRaw) || preg_match("/^\d{4}$/", $startingYearRaw) !== 1)
    ) {
        throw new InvalidArgumentException("Invalid starting year. Must be between 2005 and current year.", 400);
    }
    $startingYear = $startingYearRaw !== null ? (int) $startingYearRaw : null;
    $mode = isset($_GET["mode"]) ? $_GET["mode"] : null;
    $excludeDaysRaw = $_GET["exclude_days"] ?? "";
    if ($mode !== null && (!is_string($mode) || !in_array($mode, ["daily", "weekly"], true))) {
        throw new InvalidArgumentException("Invalid mode. Must be daily or weekly.", 400);
    }
    if (!is_string($excludeDaysRaw)) {
        throw new InvalidArgumentException(
            "Invalid exclude_days. Must be comma-separated day names (e.g., Sun,Mon).",
            400,
        );
    }

    if (!isValidUsername($user)) {
        throw new InvalidArgumentException("Invalid username provided.", 400);
    }
    if (!isValidStartingYear($startingYear)) {
        throw new InvalidArgumentException("Invalid starting year. Must be between 2005 and current year.", 400);
    }
    if (!isValidExcludeDays($excludeDaysRaw)) {
        throw new InvalidArgumentException(
            "Invalid exclude_days. Must be comma-separated day names (e.g., Sun,Mon).",
            400,
        );
    }

    // Fetch data from GitHub API
    $contributionGraphs = getContributionGraphs($user, $startingYear);
    $contributions = getContributionDates($contributionGraphs);

    if ($mode === "weekly") {
        $stats = getWeeklyContributionStats($contributions);
    } else {
        // split and normalize excluded days
        $excludeDays = normalizeDays(explode(",", $excludeDaysRaw));
        $stats = getContributionStats($contributions, $excludeDays);
    }

    // Set CACHE_TTL or CACHE_TTL_DEFAULT in the environment (seconds).
    $cacheTtl = getCacheEnvironmentValue("CACHE_TTL");
    $cacheTtlDefault = getCacheEnvironmentValue("CACHE_TTL_DEFAULT");
    $cacheSeconds = null;
    foreach ([$cacheTtl, $cacheTtlDefault] as $configuredTtl) {
        $validatedTtl = filter_var($configuredTtl, FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]]);
        if ($validatedTtl !== false) {
            $cacheSeconds = $validatedTtl;
            break;
        }
    }
    $cacheControl = "no-store";
    if (!isCacheDisabled()) {
        $cacheSeconds ??= 86400;
        header("Expires: " . gmdate("D, d M Y H:i:s", time() + $cacheSeconds) . " GMT");
        header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
        $cacheControl = "public, max-age=$cacheSeconds";
    }

    renderOutput($stats, 200, null, $cacheControl);
} catch (Throwable $error) {
    $status = getErrorStatus($error);
    setNoStoreHeaders();
    error_log("request_failed event=api_exception status={$status} type=" . getErrorType($error));
    $message =
        $error instanceof InvalidArgumentException &&
        $error->getCode() === 403 &&
        $error->getMessage() === "User not in whitelist."
            ? $error->getMessage()
            : CLIENT_ERROR_MESSAGE;
    renderOutput($message, $status);
}
