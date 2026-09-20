<?php

declare(strict_types=1);

const MINIMUM_LINE_COVERAGE = 80.0;

if ($argc !== 2) {
    fwrite(STDERR, "Coverage check requires a Clover report path.\n");
    exit(1);
}

$reportPath = $argv[1];
if (!is_file($reportPath) || !is_readable($reportPath)) {
    fwrite(STDERR, "Coverage report is missing or unreadable.\n");
    exit(1);
}

$document = new DOMDocument();
$document->resolveExternals = false;
$document->substituteEntities = false;
if (!@$document->load($reportPath, LIBXML_NONET | LIBXML_NOBLANKS)) {
    fwrite(STDERR, "Coverage report is not valid Clover XML.\n");
    exit(1);
}

$metrics = (new DOMXPath($document))->query("/coverage/project/metrics");
if ($metrics === false || $metrics->length !== 1) {
    fwrite(STDERR, "Coverage report does not contain project line metrics.\n");
    exit(1);
}

$statements = $metrics->item(0)?->attributes?->getNamedItem("statements")?->value;
$coveredStatements = $metrics->item(0)?->attributes?->getNamedItem("coveredstatements")?->value;
if (
    $statements === null ||
    $coveredStatements === null ||
    !ctype_digit($statements) ||
    !ctype_digit($coveredStatements) ||
    (int) $statements <= 0 ||
    (int) $coveredStatements < 0 ||
    (int) $coveredStatements > (int) $statements
) {
    fwrite(STDERR, "Coverage report contains invalid project line metrics.\n");
    exit(1);
}

$coverage = ((int) $coveredStatements / (int) $statements) * 100;
if ($coverage < MINIMUM_LINE_COVERAGE) {
    fwrite(STDERR, sprintf("Line coverage %.2f%% is below the %.2f%% threshold.\n", $coverage, MINIMUM_LINE_COVERAGE));
    exit(1);
}

printf("Line coverage %.2f%% meets the %.2f%% threshold.\n", $coverage, MINIMUM_LINE_COVERAGE);
