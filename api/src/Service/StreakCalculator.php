<?php

declare(strict_types=1);

namespace App\Service;

class StreakCalculator
{
    /**
     * Get an array of all dates with the number of contributions
     *
     * @param array<int,\stdClass> $contributionCalendars List of GraphQL response objects by year
     * @return array<string,int> Y-M-D dates mapped to the number of contributions
     */
    public function getContributionDates(array $contributionGraphs): array
    {
        $contributions = [];
        $today = date("Y-m-d");
        $tomorrow = date("Y-m-d", strtotime("tomorrow"));
        ksort($contributionGraphs);
        foreach ($contributionGraphs as $graph) {
            $weeks = $graph->data->user->contributionsCollection->contributionCalendar->weeks;
            foreach ($weeks as $week) {
                foreach ($week->contributionDays as $day) {
                    $date = $day->date;
                    $count = $day->contributionCount;
                    if ($date <= $today || ($date == $tomorrow && $count > 0)) {
                        $contributions[$date] = $count;
                    }
                }
            }
        }
        return $contributions;
    }

    /**
     * Normalize names of days of the week (eg. ["Sunday", " mon", "TUE"] -> ["Sun", "Mon", "Tue"])
     *
     * @param array<string> $days List of days of the week
     * @return array<string> List of normalized days of the week
     */
    public function normalizeDays(array $days): array
    {
        return array_filter(
            array_map(function ($dayOfWeek) {
                $dayOfWeek = substr(ucfirst(strtolower(trim($dayOfWeek))), 0, 3);
                return in_array($dayOfWeek, ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"]) ? $dayOfWeek : null;
            }, $days),
        );
    }

    /**
     * Check if a day is an excluded day of the week
     *
     * @param string $date Date to check (Y-m-d)
     * @param array<string> $excludedDays List of days of the week to exclude
     * @return bool True if the day is excluded, false otherwise
     */
    public function isExcludedDay(string $date, array $excludedDays): bool
    {
        if (empty($excludedDays)) {
            return false;
        }
        $day = date("D", strtotime($date));
        return in_array($day, $excludedDays);
    }

    /**
     * Get a stats array with the contribution count, daily streak, and dates
     *
     * @param array<string,int> $contributions Y-M-D contribution dates with contribution counts
     * @param array<string> $excludedDays List of days of the week to exclude
     * @return array<string,mixed> Streak stats
     */
    public function getContributionStats(array $contributions, array $excludedDays = []): array
    {
        if (empty($contributions)) {
            throw new \RuntimeException("No contributions found.", 204);
        }
        $today = array_key_last($contributions);
        $first = array_key_first($contributions);
        $stats = [
            "mode" => "daily",
            "totalContributions" => 0,
            "firstContribution" => "",
            "longestStreak" => [
                "start" => $first,
                "end" => $first,
                "length" => 0,
            ],
            "currentStreak" => [
                "start" => $first,
                "end" => $first,
                "length" => 0,
            ],
            "excludedDays" => $excludedDays,
        ];

        foreach ($contributions as $date => $count) {
            $stats["totalContributions"] += $count;
            if ($count > 0 || ($stats["currentStreak"]["length"] > 0 && $this->isExcludedDay($date, $excludedDays))) {
                ++$stats["currentStreak"]["length"];
                $stats["currentStreak"]["end"] = $date;
                if ($stats["currentStreak"]["length"] == 1) {
                    $stats["currentStreak"]["start"] = $date;
                }
                if (!$stats["firstContribution"]) {
                    $stats["firstContribution"] = $date;
                }
                if ($stats["currentStreak"]["length"] > $stats["longestStreak"]["length"]) {
                    $stats["longestStreak"]["start"] = $stats["currentStreak"]["start"];
                    $stats["longestStreak"]["end"] = $stats["currentStreak"]["end"];
                    $stats["longestStreak"]["length"] = $stats["currentStreak"]["length"];
                }
            } elseif ($date != $today) {
                $stats["currentStreak"]["length"] = 0;
                $stats["currentStreak"]["start"] = $today;
                $stats["currentStreak"]["end"] = $today;
            }
        }
        return $stats;
    }

    /**
     * Get the previous Sunday of a given date
     *
     * @param string $date Date to get previous Sunday of (Y-m-d)
     * @return string Previous Sunday
     */
    public function getPreviousSunday(string $date): string
    {
        $dayOfWeek = date("w", strtotime($date));
        return date("Y-m-d", strtotime("-$dayOfWeek days", strtotime($date)));
    }

    /**
     * Get a stats array with the contribution count, weekly streak, and dates
     *
     * @param array<string,int> $contributions Y-M-D contribution dates with contribution counts
     * @return array<string,mixed> Streak stats
     */
    public function getWeeklyContributionStats(array $contributions): array
    {
        if (empty($contributions)) {
            throw new \RuntimeException("No contributions found.", 204);
        }
        $thisWeek = $this->getPreviousSunday(array_key_last($contributions));
        $first = array_key_first($contributions);
        $firstWeek = $this->getPreviousSunday($first);
        $stats = [
            "mode" => "weekly",
            "totalContributions" => 0,
            "firstContribution" => "",
            "longestStreak" => [
                "start" => $firstWeek,
                "end" => $firstWeek,
                "length" => 0,
            ],
            "currentStreak" => [
                "start" => $firstWeek,
                "end" => $firstWeek,
                "length" => 0,
            ],
        ];

        $weeks = [];
        foreach ($contributions as $date => $count) {
            $week = $this->getPreviousSunday($date);
            if (!isset($weeks[$week])) {
                $weeks[$week] = 0;
            }
            if ($count > 0) {
                $weeks[$week] += $count;
                if (!$stats["firstContribution"]) {
                    $stats["firstContribution"] = $date;
                }
            }
        }

        foreach ($weeks as $week => $count) {
            $stats["totalContributions"] += $count;
            if ($count > 0) {
                ++$stats["currentStreak"]["length"];
                $stats["currentStreak"]["end"] = $week;
                if ($stats["currentStreak"]["length"] == 1) {
                    $stats["currentStreak"]["start"] = $week;
                }
                if ($stats["currentStreak"]["length"] > $stats["longestStreak"]["length"]) {
                    $stats["longestStreak"]["start"] = $stats["currentStreak"]["start"];
                    $stats["longestStreak"]["end"] = $stats["currentStreak"]["end"];
                    $stats["longestStreak"]["length"] = $stats["currentStreak"]["length"];
                }
            } elseif ($week != $thisWeek) {
                $stats["currentStreak"]["length"] = 0;
                $stats["currentStreak"]["start"] = $thisWeek;
                $stats["currentStreak"]["end"] = $thisWeek;
            }
        }
        return $stats;
    }
}
