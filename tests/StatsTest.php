<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 1) . "/vendor/autoload.php";
require_once "api/stats.php";

class FixtureGitHubClient extends \App\Client\GitHubClient
{
    public function __construct(private readonly bool $includeHistoricalYears = false)
    {
        parent::__construct(["fixture-token"]);
    }

    public function executeContributionGraphRequests(string $user, array $years): array
    {
        if ($user !== "DenverCoder1") {
            throw new InvalidArgumentException("Could not find a user with that name.", 404);
        }
        $graphs = [];
        foreach ($years as $year) {
            $days = [
                ["date" => "2020-01-01", "count" => 2],
                ["date" => "2020-01-02", "count" => 1],
                ["date" => "2020-01-03", "count" => 0],
                ["date" => "2020-01-04", "count" => 3],
                ["date" => "2020-01-05", "count" => 1],
            ];
            if (count($years) > 1 && !$this->includeHistoricalYears) {
                $days = [];
            } elseif ($this->includeHistoricalYears && $year !== (int) date("Y")) {
                $days = [["date" => "$year-01-01", "count" => 1]];
            }
            $graphs[$year] = $this->createGraph($days);
        }

        return $graphs;
    }

    protected function createGraph(array $days): \stdClass
    {
        $contributionDays = array_map(
            static fn(array $day): \stdClass => (object) [
                "contributionCount" => $day["count"],
                "date" => $day["date"],
            ],
            $days,
        );

        return (object) [
            "data" => (object) [
                "user" => (object) [
                    "createdAt" => "2016-08-10T00:00:00Z",
                    "contributionsCollection" => (object) [
                        "contributionYears" => [2020, 2019, 2018, 2017, 2016],
                        "contributionCalendar" => (object) [
                            "weeks" => [(object) ["contributionDays" => $contributionDays]],
                        ],
                    ],
                ],
            ],
        ];
    }
}

final class PartialResponseGitHubClient extends FixtureGitHubClient
{
    public function executeContributionGraphRequests(string $user, array $years): array
    {
        $responses = parent::executeContributionGraphRequests($user, $years);
        if (count($years) > 1) {
            array_pop($responses);
        }
        return $responses;
    }
}

final class StatsTest extends TestCase
{
    private FixtureGitHubClient $githubClient;

    protected function setUp(): void
    {
        $_SERVER["WHITELIST"] = "DenverCoder1";
        $this->githubClient = new FixtureGitHubClient();
        $GLOBALS["githubClient"] = $this->githubClient;
    }

    protected function tearDown(): void
    {
        unset($_SERVER["WHITELIST"]);
    }

    private function getGraphs(
        string $user,
        ?int $startingYear = null,
        ?FixtureGitHubClient $githubClient = null,
    ): array {
        $githubClient ??= $this->githubClient;
        $GLOBALS["githubClient"] = $githubClient;
        $parameterCount = (new ReflectionFunction("getContributionGraphs"))->getNumberOfParameters();
        $arguments = [$user];
        if ($startingYear !== null || $parameterCount >= 2) {
            $arguments[] = $startingYear;
        }
        if ($parameterCount >= 3) {
            $arguments[] = $githubClient;
        }
        return call_user_func_array("getContributionGraphs", $arguments);
    }

    /**
     * Test that values seem correct for valid username
     */
    public function testValidUsername(): void
    {
        $stats = getContributionStats(getContributionDates($this->getGraphs("DenverCoder1")));

        $this->assertSame(7, $stats["totalContributions"]);
        $this->assertSame("2020-01-01", $stats["firstContribution"]);
        $this->assertSame(["start" => "2020-01-01", "end" => "2020-01-02", "length" => 2], $stats["longestStreak"]);
        $this->assertSame(["start" => "2020-01-04", "end" => "2020-01-05", "length" => 2], $stats["currentStreak"]);
    }

    /**
     * Test contributions with overriden starting year
     */
    public function testOverrideStartingYear(): void
    {
        $_SERVER["WHITELIST"] = "DenverCoder1";
        $stats = getContributionStats(
            getContributionDates($this->getGraphs("DenverCoder1", 2019, new FixtureGitHubClient(true))),
        );

        $this->assertSame("2019-01-01", $stats["firstContribution"]);
    }

    /**
     * Test that an invalid username returns 'not found' error
     */
    public function testInvalidUsername(): void
    {
        $_SERVER["WHITELIST"] = "help";
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Could not find a user with that name.");
        $this->getGraphs("help");
    }

    /**
     * Test that an valid username can be accessed with whitelist
     */
    public function testValidUsernameWithWhitelist(): void
    {
        $_SERVER["WHITELIST"] = "DenverCoder1";
        try {
            $contributionGraphs = $this->getGraphs("DenverCoder1");
            $this->assertIsArray($contributionGraphs);
            $this->assertNotEmpty($contributionGraphs);
        } finally {
            unset($_SERVER["WHITELIST"]);
        }
    }

    /**
     * Test that an not whitelisted username returns 'not whitelisted' error
     */
    public function testNotWhitelistedUsername(): void
    {
        $_SERVER["WHITELIST"] = "DenverCoder1";
        try {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage("User not in whitelist.");
            $this->getGraphs("help");
        } finally {
            unset($_SERVER["WHITELIST"]);
        }
    }

    public function testCredentialsAreValidatedBeforeContributionFanout(): void
    {
        $client = new \App\Client\GitHubClient([]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("There is no GitHub token available.");
        getContributionGraphs("DenverCoder1", null, $client);
    }

    public function testContributionYearBudgetIsBounded(): void
    {
        $client = new \App\Client\GitHubClient(["fixture-token"]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Too many contribution years requested.");
        $client->executeContributionGraphRequests("DenverCoder1", range(2000, 2100));
    }

    public function testExpiredMonotonicDeadlineStopsNewRequests(): void
    {
        $client = new \App\Client\GitHubClient(["fixture-token"]);
        $deadline = new ReflectionProperty(\App\Client\GitHubClient::class, "deadlineNanoseconds");
        $deadline->setValue($client, hrtime(true) - 1);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(504);
        $client->getGraphQLCurlHandle("query", "fixture-token");
    }

    public function testGraphQLRequestsShareOneMonotonicDeadline(): void
    {
        $client = new \App\Client\GitHubClient(["fixture-token"]);
        $deadline = new ReflectionProperty(\App\Client\GitHubClient::class, "deadlineNanoseconds");

        $firstHandle = $client->getGraphQLCurlHandle("query", "fixture-token");
        $firstDeadline = $deadline->getValue($client);
        $secondHandle = $client->getGraphQLCurlHandle("query", "fixture-token");
        $secondDeadline = $deadline->getValue($client);
        curl_close($firstHandle);
        curl_close($secondHandle);

        $this->assertIsInt($firstDeadline);
        $this->assertSame($firstDeadline, $secondDeadline);
    }

    public function testExhaustedAttemptBudgetAbortsBeforeAnotherRetry(): void
    {
        $client = new \App\Client\GitHubClient(["fixture-token"]);
        $attempts = new ReflectionProperty(\App\Client\GitHubClient::class, "attemptsUsed");
        $attempts->setValue($client, 100);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(503);
        $client->executeContributionGraphRequests("DenverCoder1", [2025]);
    }

    public function testPartialHistoricalResultsAbortWithoutReturningPartialData(): void
    {
        $client = new PartialResponseGitHubClient();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Failed to retrieve contributions for year");
        getContributionGraphs("DenverCoder1", null, $client);
    }

    /**
     * Test that an organization name returns 'not a user' error
     */
    public function testOrganizationName(): void
    {
        $_SERVER["WHITELIST"] = "DenverCoderOne";
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Could not find a user with that name.");
        $this->getGraphs("DenverCoderOne");
    }

    /**
     * Test stats contributed today
     */
    public function testContributedToday(): void
    {
        $contributions = [
            "2021-04-15" => 5,
            "2021-04-16" => 3,
            "2021-04-17" => 2,
            "2021-04-18" => 7,
        ];
        $stats = getContributionStats($contributions);
        $expected = [
            "mode" => "daily",
            "totalContributions" => 17,
            "firstContribution" => "2021-04-15",
            "longestStreak" => [
                "start" => "2021-04-15",
                "end" => "2021-04-18",
                "length" => 4,
            ],
            "currentStreak" => [
                "start" => "2021-04-15",
                "end" => "2021-04-18",
                "length" => 4,
            ],
            "excludedDays" => [],
        ];
        $this->assertEquals($expected, $stats);
    }

    /**
     * Test stats missing today
     */
    public function testMissingToday(): void
    {
        $contributions = [
            "2021-04-15" => 5,
            "2021-04-16" => 3,
            "2021-04-17" => 2,
            "2021-04-18" => 0,
        ];
        $stats = getContributionStats($contributions);
        $expected = [
            "mode" => "daily",
            "totalContributions" => 10,
            "firstContribution" => "2021-04-15",
            "longestStreak" => [
                "start" => "2021-04-15",
                "end" => "2021-04-17",
                "length" => 3,
            ],
            "currentStreak" => [
                "start" => "2021-04-15",
                "end" => "2021-04-17",
                "length" => 3,
            ],
            "excludedDays" => [],
        ];
        $this->assertEquals($expected, $stats);
    }

    /**
     * Test stats missing 2 days
     */
    public function testMissingTwoDays(): void
    {
        $contributions = [
            "2021-04-15" => 5,
            "2021-04-16" => 3,
            "2021-04-17" => 0,
            "2021-04-18" => 0,
        ];
        $stats = getContributionStats($contributions);
        $expected = [
            "mode" => "daily",
            "totalContributions" => 8,
            "firstContribution" => "2021-04-15",
            "longestStreak" => [
                "start" => "2021-04-15",
                "end" => "2021-04-16",
                "length" => 2,
            ],
            "currentStreak" => [
                "start" => "2021-04-18",
                "end" => "2021-04-18",
                "length" => 0,
            ],
            "excludedDays" => [],
        ];
        $this->assertEquals($expected, $stats);
    }

    /**
     * Test multiple year streak
     */
    public function testMultipleYearStreak(): void
    {
        $contributions = [];
        $firstDate = new DateTimeImmutable("2024-01-01");
        for ($i = 0; $i < 370; ++$i) {
            $contributions[$firstDate->modify("+$i days")->format("Y-m-d")] = 1;
        }
        $stats = getContributionStats($contributions);
        $lastDate = $firstDate->modify("+369 days")->format("Y-m-d");
        $expected = [
            "mode" => "daily",
            "totalContributions" => 370,
            "firstContribution" => "2024-01-01",
            "longestStreak" => [
                "start" => "2024-01-01",
                "end" => $lastDate,
                "length" => 370,
            ],
            "currentStreak" => [
                "start" => "2024-01-01",
                "end" => $lastDate,
                "length" => 370,
            ],
            "excludedDays" => [],
        ];
        $this->assertEquals($expected, $stats);
    }

    /**
     * Test future commits
     * Tomorrow should count because of timezone differences, but further ahead should not
     */
    public function testFutureCommits(): void
    {
        $yesterday = date("Y-m-d", strtotime("yesterday"));
        $today = date("Y-m-d", strtotime("today"));
        $tomorrow = date("Y-m-d", strtotime("tomorrow"));
        $inTwoDays = date("Y-m-d", strtotime("$today +2 days"));
        $contributionGraphs = [
            (object) [
                "data" => (object) [
                    "user" => (object) [
                        "contributionsCollection" => (object) [
                            "contributionCalendar" => (object) [
                                "weeks" => (object) [
                                    (object) [
                                        "contributionDays" => (object) [
                                            (object) [
                                                "contributionCount" => 1,
                                                "date" => $yesterday,
                                            ],
                                            (object) [
                                                "contributionCount" => 1,
                                                "date" => $today,
                                            ],
                                            (object) [
                                                "contributionCount" => 1,
                                                "date" => $tomorrow,
                                            ],
                                            (object) [
                                                "contributionCount" => 1,
                                                "date" => $inTwoDays,
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $contributions = getContributionDates($contributionGraphs);
        $stats = getContributionStats($contributions);
        $expected = [
            "mode" => "daily",
            "totalContributions" => 3,
            "firstContribution" => date("Y-m-d", strtotime("yesterday")),
            "longestStreak" => [
                "start" => date("Y-m-d", strtotime("yesterday")),
                "end" => date("Y-m-d", strtotime("tomorrow")),
                "length" => 3,
            ],
            "currentStreak" => [
                "start" => date("Y-m-d", strtotime("yesterday")),
                "end" => date("Y-m-d", strtotime("tomorrow")),
                "length" => 3,
            ],
            "excludedDays" => [],
        ];
        $this->assertEquals($expected, $stats);
    }

    /**
     * Test weekly stats
     */
    public function testWeeklyStats(): void
    {
        $contributions = [
            "2022-11-12" => 5,
            "2022-11-13" => 3, // Sunday
            "2022-11-14" => 2,
            "2022-11-15" => 0,
            "2022-11-16" => 0,
            "2022-11-17" => 0,
            "2022-11-18" => 0,
            "2022-11-19" => 0,
            "2022-11-20" => 0, // Sunday
            "2022-11-21" => 1,
        ];
        $stats = getWeeklyContributionStats($contributions);
        $expected = [
            "mode" => "weekly",
            "totalContributions" => 11,
            "firstContribution" => "2022-11-12",
            "longestStreak" => [
                "start" => "2022-11-06", // Previous Sunday before 2022-11-12
                "end" => "2022-11-20",
                "length" => 3,
            ],
            "currentStreak" => [
                "start" => "2022-11-06",
                "end" => "2022-11-20",
                "length" => 3,
            ],
        ];
        $this->assertEquals($expected, $stats);
    }

    /**
     * Test weekly stats missing a week
     */
    public function testWeeklyStatsMissingWeek(): void
    {
        $contributions = [
            "2022-11-05" => 2,
            "2022-11-06" => 0, // Sunday
            "2022-11-07" => 0,
            "2022-11-08" => 0,
            "2022-11-09" => 0,
            "2022-11-10" => 0,
            "2022-11-11" => 0,
            "2022-11-12" => 5,
            "2022-11-13" => 0, // Sunday
            "2022-11-14" => 0,
            "2022-11-15" => 0,
            "2022-11-16" => 0,
            "2022-11-17" => 0,
            "2022-11-18" => 0,
            "2022-11-19" => 0,
            "2022-11-20" => 0, // Sunday
            "2022-11-21" => 1,
            "2022-11-22" => 1,
        ];
        $stats = getWeeklyContributionStats($contributions);
        $expected = [
            "mode" => "weekly",
            "totalContributions" => 9,
            "firstContribution" => "2022-11-05",
            "longestStreak" => [
                "start" => "2022-10-30", // Previous Sunday before 2022-11-05
                "end" => "2022-11-06",
                "length" => 2,
            ],
            "currentStreak" => [
                "start" => "2022-11-20",
                "end" => "2022-11-20",
                "length" => 1,
            ],
        ];
        $this->assertEquals($expected, $stats);
    }

    /**
     * Test weekly stats missing this week
     */
    public function testWeeklyStatsMissingThisWeek(): void
    {
        $contributions = [];
        $thisWeek = getPreviousSunday(date("Y-m-d"));
        $lastWeek = getPreviousSunday(date("Y-m-d", strtotime("$thisWeek -1 week")));
        for ($i = 0; $i < 7; $i++) {
            $date = date("Y-m-d", strtotime("$lastWeek +$i days"));
            $contributions[$date] = 1;
        }
        for ($i = 0; $i < 7; $i++) {
            $date = date("Y-m-d", strtotime("$thisWeek +$i days"));
            $contributions[$date] = 0;
        }
        $stats = getWeeklyContributionStats($contributions);
        $expected = [
            "mode" => "weekly",
            "totalContributions" => 7,
            "firstContribution" => $lastWeek,
            "longestStreak" => [
                "start" => $lastWeek,
                "end" => $lastWeek,
                "length" => 1,
            ],
            "currentStreak" => [
                "start" => $lastWeek,
                "end" => $lastWeek,
                "length" => 1,
            ],
        ];
        $this->assertEquals($expected, $stats);
    }

    /**
     * Test stats with excluded days of the week
     */
    public function testExcludeDays(): void
    {
        $contributions = [
            "2023-04-12" => 1,
            "2023-04-13" => 0,
            "2023-04-14" => 2,
            "2023-04-15" => 0,
            "2023-04-16" => 0,
            "2023-04-17" => 3,
        ];
        $excludeDays = ["Sun", "Sat"];
        $stats = getContributionStats($contributions, $excludeDays);
        $expected = [
            "mode" => "daily",
            "totalContributions" => 6,
            "firstContribution" => "2023-04-12",
            "longestStreak" => [
                "start" => "2023-04-14",
                "end" => "2023-04-17",
                "length" => 4,
            ],
            "currentStreak" => [
                "start" => "2023-04-14",
                "end" => "2023-04-17",
                "length" => 4,
            ],
            "excludedDays" => $excludeDays,
        ];
        $this->assertEquals($expected, $stats);
    }

    /**
     * Test stats with excluded days of the week and no contribution before weekend
     */
    public function testExcludeDaysNoContributionBeforeWeekend(): void
    {
        $contributions = [
            "2023-04-12" => 1,
            "2023-04-13" => 2,
            "2023-04-14" => 0,
            "2023-04-15" => 0,
            "2023-04-16" => 0,
            "2023-04-17" => 3,
        ];
        $excludeDays = ["Sun", "Sat"];
        $stats = getContributionStats($contributions, $excludeDays);
        $expected = [
            "mode" => "daily",
            "totalContributions" => 6,
            "firstContribution" => "2023-04-12",
            "longestStreak" => [
                "start" => "2023-04-12",
                "end" => "2023-04-13",
                "length" => 2,
            ],
            "currentStreak" => [
                "start" => "2023-04-17",
                "end" => "2023-04-17",
                "length" => 1,
            ],
            "excludedDays" => $excludeDays,
        ];
        $this->assertEquals($expected, $stats);
    }
}
