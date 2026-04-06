<?php

declare(strict_types=1);

require_once dirname(__DIR__, 1) . "/vendor/autoload.php";
require_once "stats.php";
require_once "card.php";

define("API_ROOT", __DIR__);

// Set UTC timezone for consistent date handling across all environments
date_default_timezone_set("UTC");

header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");
header("Referrer-Policy: strict-origin-when-cross-origin");

$dotenv = \Dotenv\Dotenv::createImmutable(dirname(__DIR__, 1));
$dotenv->safeLoad();

if (!isset($_ENV["TOKEN"])) {
    renderOutput("Missing GitHub token. Check Contributing.md for details.", 500);
}

/**
 * Simple file-based rate limiter (100 requests per minute per IP)
 * In serverless environments (Vercel), rate limiting is skipped because
 * each invocation has an isolated filesystem that isn't shared.
 *
 * @return bool True if request is allowed, false if rate limited
 */
function checkRateLimit(): bool
{
    // Serverless environments: skip file-based rate limiting
    if (getenv("VERCEL") === "1" || getenv("AWS_LAMBDA_FUNCTION_NAME") !== false) {
        return true;
    }

    // Use Cloudflare IP if available, otherwise fall back to REMOTE_ADDR
    // X-Forwarded-For is intentionally excluded as it can be spoofed
    $ip = $_SERVER["HTTP_CF_CONNECTING_IP"] ?? ($_SERVER["REMOTE_ADDR"] ?? "unknown");
    $ip = preg_replace("/[^0-9a-fA-F:.]/", "", $ip);
    $cacheDir = sys_get_temp_dir();
    $rateFile = $cacheDir . "/rate_limit_" . md5($ip);
    $now = time();
    $windowStart = $now - 60;
    $maxRequests = 100;

    $requests = [];
    if (file_exists($rateFile)) {
        $fp = @fopen($rateFile, "r");
        if ($fp !== false) {
            if (flock($fp, LOCK_SH)) {
                $data = stream_get_contents($fp);
                flock($fp, LOCK_UN);
                $requests = is_string($data) ? json_decode($data, true) : [];
            }
            fclose($fp);
        }
    }
    if (!is_array($requests)) {
        $requests = [];
    }
    $requests = array_filter($requests, function ($ts) use ($windowStart) {
        return $ts > $windowStart;
    });

    if (count($requests) >= $maxRequests) {
        return false;
    }

    $requests[] = $now;
    $fp = @fopen($rateFile, "c");
    if ($fp !== false) {
        if (flock($fp, LOCK_EX)) {
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($requests));
            fflush($fp);
            flock($fp, LOCK_UN);
        }
        fclose($fp);
    }
    return true;
}

/**
 * Validate username input
 *
 * @param string $user Username to validate
 * @return bool True if valid
 */
function isValidUsername(string $user): bool
{
    return $user !== "" && strlen($user) <= 39 && preg_match("/^[a-zA-Z0-9\-]+$/", $user);
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
        if ($trimmed !== "" && !in_array($trimmed, $validDays)) {
            return false;
        }
    }
    return true;
}

// redirect to demo site if user is not given
if (!isset($_GET["user"])) {
    header("Location: demo/");
    exit();
}

if (!checkRateLimit()) {
    renderOutput("Rate limit exceeded. Please try again later.", 429);
}

try {
    $user = preg_replace("/[^a-zA-Z0-9\-]/", "", $_GET["user"]);
    $startingYear = isset($_GET["starting_year"]) ? intval($_GET["starting_year"]) : null;
    $mode = isset($_GET["mode"]) ? $_GET["mode"] : null;
    $excludeDaysRaw = $_GET["exclude_days"] ?? "";

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

    // set cache TTL from environment variable
    // Set CACHE_TTL or CACHE_TTL_DEFAULT in Vercel (value in seconds, e.g., 18000 = 5 hours)
    // CACHE_TTL takes priority over CACHE_TTL_DEFAULT
    $cacheSeconds = isset($_ENV["CACHE_TTL"]) ? (int) $_ENV["CACHE_TTL"] : null;
    if ($cacheSeconds === null) {
        $cacheSeconds = isset($_ENV["CACHE_TTL_DEFAULT"]) ? (int) $_ENV["CACHE_TTL_DEFAULT"] : null;
    }
    if ($cacheSeconds !== null && $cacheSeconds > 0) {
        if (!isset($_ENV["DISABLE_CACHE"]) || $_ENV["DISABLE_CACHE"] !== "true") {
            header("Expires: " . gmdate("D, d M Y H:i:s", time() + $cacheSeconds) . " GMT");
            header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
            header("Cache-Control: public, max-age=$cacheSeconds");
        }
    }

    renderOutput($stats);
} catch (InvalidArgumentException | RuntimeException $error) {
    header("Cache-Control: no-cache, no-store, must-revalidate");
    header("Pragma: no-cache");
    header("Expires: 0");
    error_log("Error {$error->getCode()}: {$error->getMessage()}");
    if ($error->getCode() >= 500) {
        error_log($error->getTraceAsString());
    }
    renderOutput($error->getMessage(), $error->getCode());
}
