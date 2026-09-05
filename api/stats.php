<?php

declare(strict_types=1);

require_once __DIR__ . "/src/Client/GitHubClient.php";
require_once __DIR__ . "/src/Service/StreakCalculator.php";
require_once __DIR__ . "/src/Service/WhitelistService.php";

use App\Client\GitHubClient;
use App\Service\StreakCalculator;
use App\Service\WhitelistService;

// Dependencies are supplied at call sites; no request state is stored globally.

function buildContributionGraphQuery(string $user, int $year): string
{
    return (new GitHubClient())->buildContributionGraphQuery($user, $year);
}

function executeContributionGraphRequests(string $user, array $years): array
{
    return (new GitHubClient())->executeContributionGraphRequests($user, $years);
}

function validateGitHubCredentials(?GitHubClient $githubClient = null): void
{
    ($githubClient ?? new GitHubClient())->validateCredentials();
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
    $responses = $githubClient->executeContributionGraphRequests($user, [$currentYear]);
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
        $historicalResponses = $githubClient->executeContributionGraphRequests($user, $yearsToRequest);
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
