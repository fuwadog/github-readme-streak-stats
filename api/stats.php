<?php

declare(strict_types=1);

require_once __DIR__ . "/src/Client/GitHubClient.php";
require_once __DIR__ . "/src/Exception/ApiException.php";
require_once __DIR__ . "/src/Service/StreakCalculator.php";
require_once __DIR__ . "/src/Service/WhitelistService.php";

use App\Client\GitHubClient;
use App\Exception\ApiException;
use App\Service\StreakCalculator;
use App\Service\WhitelistService;

const CACHE_KEY_V1 = "v1|";
const GRAPH_CACHE_TTL_SECONDS = 86400;
const GRAPH_CACHE_STALE_SECONDS = 604800;

// Dependencies are supplied at call sites; no request state is stored globally.

function buildContributionGraphQuery(string $user, int $year): string
{
    return (new GitHubClient())->buildContributionGraphQuery($user, $year);
}

function executeContributionGraphRequests(string $user, array $years): array
{
    return (new GitHubClient())->executeContributionGraphRequests($user, $years);
}

/** Build the token-free, versioned cache key shared by all GitHub responses. */
function buildCacheKeyV1(string $username, array $options = [], array $years = []): string
{
    $normalizedOptions = [];
    foreach ($options as $key => $value) {
        $normalizedOptions[(string) $key] = is_scalar($value) ? (string) $value : json_encode($value);
    }
    ksort($normalizedOptions);
    $normalizedYears = array_map(static fn(mixed $year): int => (int) $year, $years);
    sort($normalizedYears, SORT_NUMERIC);
    return hash(
        "sha256",
        CACHE_KEY_V1 .
            strtolower(trim($username)) .
            "|" .
            json_encode($normalizedOptions) .
            "|" .
            json_encode($normalizedYears),
    );
}

function graphCachePath(string $key): string
{
    return sys_get_temp_dir() . DIRECTORY_SEPARATOR . "streak_graph_v1_" . $key . ".cache";
}

function readGraphCache(string $key, int $now): ?array
{
    if (isCacheDisabled()) {
        return null;
    }
    $contents = @file_get_contents(graphCachePath($key));
    $entry = $contents === false ? null : @unserialize($contents, ["allowed_classes" => ["stdClass"]]);
    if (!is_array($entry) || !is_int($entry["created"] ?? null) || !is_array($entry["value"] ?? null)) {
        return null;
    }
    return $now - $entry["created"] <= GRAPH_CACHE_TTL_SECONDS + GRAPH_CACHE_STALE_SECONDS ? $entry : null;
}

function writeGraphCache(string $key, array $value): void
{
    if (isCacheDisabled()) {
        return;
    }
    $encoded = serialize(["created" => time(), "value" => $value]);
    @file_put_contents(graphCachePath($key), $encoded, LOCK_EX);
}

function validateGitHubCredentials(?GitHubClient $githubClient = null): void
{
    ($githubClient ?? new GitHubClient())->validateCredentials();
}

function rethrowContributionFetchFailure(Throwable $error, GitHubClient $githubClient): never
{
    $retryAfterSeconds = $githubClient->getRetryAfterSeconds();
    if ($retryAfterSeconds !== null && (!($error instanceof ApiException) || $error->getRetryAfterSeconds() === null)) {
        throw new ApiException($error->getMessage(), (int) $error->getCode(), $error, $retryAfterSeconds);
    }
    throw $error;
}

function getContributionGraphs(
    string $user,
    ?int $startingYear = null,
    ?GitHubClient $githubClient = null,
    ?WhitelistService $whitelistService = null,
): array {
    $whitelistService ??= new WhitelistService();
    if (!$whitelistService->isWhitelisted($user)) {
        throw new InvalidArgumentException("User not in whitelist.", 403);
    }
    $githubClient ??= new GitHubClient();
    $githubClient->validateCredentials();

    $currentYear = (int) date("Y");
    if ($startingYear !== null && ($startingYear < 2005 || $startingYear > $currentYear)) {
        throw new InvalidArgumentException("Invalid starting year. Must be between 2005 and current year.", 400);
    }
    $currentKey = buildCacheKeyV1($user, ["starting_year" => $startingYear], [$currentYear]);
    $currentEntry = readGraphCache($currentKey, time());
    try {
        $responses =
            $currentEntry !== null && time() - $currentEntry["created"] <= GRAPH_CACHE_TTL_SECONDS
                ? $currentEntry["value"]
                : $githubClient->executeContributionGraphRequests($user, [$currentYear]);
        if ($currentEntry === null || time() - $currentEntry["created"] > GRAPH_CACHE_TTL_SECONDS) {
            writeGraphCache($currentKey, $responses);
        }
    } catch (Throwable $error) {
        if ($currentEntry === null) {
            rethrowContributionFetchFailure($error, $githubClient);
        }
        $responses = $currentEntry["value"];
    }
    $currentResponse = $responses[$currentYear] ?? null;
    $userCreatedDateTimeString = is_object($currentResponse)
        ? $currentResponse?->data?->user?->createdAt ?? null
        : null;
    if (empty($userCreatedDateTimeString)) {
        throw new \RuntimeException("Failed to retrieve contributions. This is likely a GitHub API issue.", 500);
    }
    $userCreatedYear = intval(explode("-", $userCreatedDateTimeString)[0]);
    $minimumYear = $startingYear ?? $userCreatedYear;
    $minimumYear = max($minimumYear, 2005);
    $yearsToRequest = $minimumYear < $currentYear ? range($minimumYear, $currentYear - 1) : [];
    $contributionYears = is_object($currentResponse)
        ? $currentResponse?->data?->user?->contributionsCollection?->contributionYears ?? []
        : [];
    if (!is_array($contributionYears)) {
        throw new \RuntimeException("GitHub returned invalid contribution years.", 502);
    }
    $firstContributionYear = $contributionYears[count($contributionYears) - 1] ?? $userCreatedYear;
    if ($firstContributionYear < 2005 && count($yearsToRequest) < 99) {
        array_unshift($yearsToRequest, $firstContributionYear);
    }
    if (count($yearsToRequest) > 99) {
        throw new \InvalidArgumentException("Too many contribution years requested.", 400);
    }
    if ($yearsToRequest !== []) {
        $historicalKey = buildCacheKeyV1($user, ["starting_year" => $startingYear], $yearsToRequest);
        $historicalEntry = readGraphCache($historicalKey, time());
        try {
            $historicalResponses =
                $historicalEntry !== null && time() - $historicalEntry["created"] <= GRAPH_CACHE_TTL_SECONDS
                    ? $historicalEntry["value"]
                    : $githubClient->executeContributionGraphRequests($user, $yearsToRequest);
            if ($historicalEntry === null || time() - $historicalEntry["created"] > GRAPH_CACHE_TTL_SECONDS) {
                writeGraphCache($historicalKey, $historicalResponses);
            }
        } catch (Throwable $error) {
            if ($historicalEntry === null) {
                rethrowContributionFetchFailure($error, $githubClient);
            }
            $historicalResponses = $historicalEntry["value"];
        }
        foreach ($yearsToRequest as $year) {
            if (!array_key_exists($year, $historicalResponses)) {
                throw new \RuntimeException("Failed to retrieve contributions for year $year.", 502);
            }
        }
        $responses += $historicalResponses;
    }
    return $responses;
}

function getGitHubTokens(): array
{
    return (new GitHubClient())->getGitHubTokens();
}

function getGitHubToken(): string
{
    return (new GitHubClient())->getGitHubToken();
}

function removeGitHubToken(string $token): void
{
    (new GitHubClient())->removeGitHubToken($token);
}

function getGraphQLCurlHandle(string $query, string $token, array $variables = []): CurlHandle
{
    return (new GitHubClient())->getGraphQLCurlHandle($query, $token, $variables);
}

function getContributionDates(array $contributionGraphs): array
{
    return (new StreakCalculator())->getContributionDates($contributionGraphs);
}

function normalizeDays(array $days): array
{
    return (new StreakCalculator())->normalizeDays($days);
}

function isExcludedDay(string $date, array $excludedDays): bool
{
    return (new StreakCalculator())->isExcludedDay($date, $excludedDays);
}

function getContributionStats(array $contributions, array $excludedDays = []): array
{
    return (new StreakCalculator())->getContributionStats($contributions, $excludedDays);
}

function getPreviousSunday(string $date): string
{
    return (new StreakCalculator())->getPreviousSunday($date);
}

function getWeeklyContributionStats(array $contributions): array
{
    return (new StreakCalculator())->getWeeklyContributionStats($contributions);
}
