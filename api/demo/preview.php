<?php

declare(strict_types=1);

require_once dirname(__DIR__, 1) . "/card.php";
require_once dirname(__DIR__, 1) . "/stats.php";

header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");
header("Referrer-Policy: strict-origin-when-cross-origin");

$mode = $_GET["mode"] ?? "daily";

try {
    if (!is_string($mode) || !in_array($mode, ["daily", "weekly"], true)) {
        throw new InvalidArgumentException("Invalid mode. Must be daily or weekly.", 400);
    }

    $excludeDays = $_GET["exclude_days"] ?? "";
    if (!is_string($excludeDays)) {
        throw new InvalidArgumentException(
            "Invalid exclude_days. Must be comma-separated day names (e.g., Sun,Mon).",
            400,
        );
    }

    // generate demo stats
    $demoStats = [
        "mode" => "daily",
        "totalContributions" => 2048,
        "firstContribution" => "2016-08-10",
        "longestStreak" => [
            "start" => "2021-12-19",
            "end" => "2022-03-14",
            "length" => 86,
        ],
        "currentStreak" => [
            "start" => date("Y-m-d", strtotime("-15 days")),
            "end" => date("Y-m-d"),
            "length" => 16,
        ],
        "excludedDays" => normalizeDays(explode(",", $excludeDays)),
    ];

    if ($mode === "weekly") {
        $demoStats["mode"] = "weekly";
        $demoStats["longestStreak"] = [
            "start" => "2021-12-19",
            "end" => "2022-03-13",
            "length" => 13,
        ];
        $demoStats["currentStreak"] = [
            "start" => getPreviousSunday(date("Y-m-d", strtotime("-15 days"))),
            "end" => getPreviousSunday(date("Y-m-d")),
            "length" => 3,
        ];
        unset($demoStats["excludedDays"]);
    }

    renderOutput($demoStats);
} catch (InvalidArgumentException | RuntimeException $error) {
    error_log("Error {$error->getCode()}: {$error->getMessage()}");
    if ($error->getCode() >= 500) {
        error_log($error->getTraceAsString());
    }
    renderOutput($error->getMessage(), $error->getCode());
}
