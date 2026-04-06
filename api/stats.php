<?php

declare(strict_types=1);

require_once __DIR__ . "/src/Client/GitHubClient.php";
require_once __DIR__ . "/src/Service/StreakCalculator.php";
require_once __DIR__ . "/src/Service/WhitelistService.php";

use App\Client\GitHubClient;
use App\Service\StreakCalculator;
use App\Service\WhitelistService;

$GLOBALS["githubClient"] = new GitHubClient();
$GLOBALS["streakCalculator"] = new StreakCalculator();
$GLOBALS["whitelistService"] = new WhitelistService();

function buildContributionGraphQuery(string $user, int $year): string
{
    global $githubClient;
    return $githubClient->buildContributionGraphQuery($user, $year);
}

function executeContributionGraphRequests(string $user, array $years): array
{
    global $githubClient;
    return $githubClient->executeContributionGraphRequests($user, $years);
}

function getContributionGraphs(string $user, ?int $startingYear = null): array
{
    global $githubClient, $whitelistService;
    if (!$whitelistService->isWhitelisted($user)) {
        throw new InvalidArgumentException("User not in whitelist.", 403);
    }

    $currentYear = intval(date("Y"));
    $responses = executeContributionGraphRequests($user, [$currentYear]);
    $userCreatedDateTimeString = $responses[$currentYear]->data->user->createdAt ?? null;
    if (empty($userCreatedDateTimeString)) {
        throw new \RuntimeException("Failed to retrieve contributions. This is likely a GitHub API issue.", 500);
    }
    $userCreatedYear = intval(explode("-", $userCreatedDateTimeString)[0]);
    $minimumYear = $startingYear ?: $userCreatedYear;
    $minimumYear = max($minimumYear, 2005);
    $yearsToRequest = range($minimumYear, $currentYear - 1);
    $contributionYears = $responses[$currentYear]->data->user->contributionsCollection->contributionYears ?? [];
    $firstContributionYear = $contributionYears[count($contributionYears) - 1] ?? $userCreatedYear;
    if ($firstContributionYear < 2005) {
        array_unshift($yearsToRequest, $firstContributionYear);
    }
    $responses += executeContributionGraphRequests($user, $yearsToRequest);
    return $responses;
}

function getGitHubTokens(): array
{
    global $githubClient;
    return $githubClient->getGitHubTokens();
}

function getGitHubToken(): string
{
    global $githubClient;
    return $githubClient->getGitHubToken();
}

function removeGitHubToken(string $token): void
{
    global $githubClient;
    $githubClient->removeGitHubToken($token);
}

function getGraphQLCurlHandle(string $query, string $token): CurlHandle
{
    global $githubClient;
    return $githubClient->getGraphQLCurlHandle($query, $token);
}

function getContributionDates(array $contributionGraphs): array
{
    global $streakCalculator;
    return $streakCalculator->getContributionDates($contributionGraphs);
}

function normalizeDays(array $days): array
{
    global $streakCalculator;
    return $streakCalculator->normalizeDays($days);
}

function isExcludedDay(string $date, array $excludedDays): bool
{
    global $streakCalculator;
    return $streakCalculator->isExcludedDay($date, $excludedDays);
}

function getContributionStats(array $contributions, array $excludedDays = []): array
{
    global $streakCalculator;
    return $streakCalculator->getContributionStats($contributions, $excludedDays);
}

function getPreviousSunday(string $date): string
{
    global $streakCalculator;
    return $streakCalculator->getPreviousSunday($date);
}

function getWeeklyContributionStats(array $contributions): array
{
    global $streakCalculator;
    return $streakCalculator->getWeeklyContributionStats($contributions);
}
