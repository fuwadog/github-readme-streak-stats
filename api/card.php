<?php

declare(strict_types=1);

require_once __DIR__ . "/src/Output/SvgGenerator.php";
require_once __DIR__ . "/src/Util/DateFormatter.php";

use App\Output\SvgGenerator;
use App\Util\DateFormatter;

$GLOBALS['svgGenerator'] = new SvgGenerator();
$GLOBALS['dateFormatter'] = new DateFormatter();

function formatDate(string $dateString, string|null $format, string $locale): string
{
    global $dateFormatter;
    return $dateFormatter->formatDate($dateString, $format, $locale);
}

function translateDays(array $days, string $locale): array
{
    global $svgGenerator;
    return $svgGenerator->translateDays($days, $locale);
}

function getExcludingDaysText($excludedDays, $localeTranslations, $localeCode)
{
    global $svgGenerator;
    return $svgGenerator->getExcludingDaysText($excludedDays, $localeTranslations, $localeCode);
}

function normalizeThemeName(string $theme): string
{
    global $svgGenerator;
    return $svgGenerator->normalizeThemeName($theme);
}

function getRequestedTheme(array $params): array
{
    global $svgGenerator;
    return $svgGenerator->getRequestedTheme($params);
}

function utf8WordWrap(string $string, int $width = 75, string $break = "\n", bool $cut_long_words = false): string
{
    $string = preg_replace("/(.{1,$width})(?:\s|$)/uS", "$1$break", $string);
    if ($cut_long_words) {
        $string = preg_replace("/(\S{" . $width . "})(?=\S)/u", "$1$break", $string);
    }
    return rtrim($string, $break);
}

function utf8Strlen(string $string): int
{
    return preg_match_all("/./us", $string, $matches);
}

function splitLines(string $text, int $maxChars, int $line1Offset): string
{
    if ($maxChars > 0 && utf8Strlen($text) > $maxChars && strpos($text, "\n") === false) {
        if (strpos($text, " - ") !== false) {
            $text = str_replace(" - ", "\n- ", $text);
        } else {
            $text = utf8WordWrap($text, $maxChars, "\n", true);
        }
    }
    $text = htmlspecialchars($text);
    return preg_replace(
        "/^(.*)\n(.*)/",
        "<tspan x='0' dy='{$line1Offset}'>$1</tspan><tspan x='0' dy='16'>$2</tspan>",
        $text,
    );
}

function normalizeLocaleCode(string $localeCode): string
{
    global $svgGenerator;
    return $svgGenerator->normalizeLocaleCode($localeCode);
}

function getTranslations(string $localeCode): array
{
    global $svgGenerator;
    return $svgGenerator->getTranslations($localeCode);
}

function getCardWidth(array $params, int $numColumns = 3): int
{
    global $svgGenerator;
    return $svgGenerator->getCardWidth($params, $numColumns);
}

function getCardHeight(array $params): int
{
    global $svgGenerator;
    return $svgGenerator->getCardHeight($params);
}

function formatNumber(float $num, string $localeCode, bool $useShortNumbers): string
{
    global $svgGenerator;
    return $svgGenerator->formatNumber($num, $localeCode, $useShortNumbers);
}

function generateCard(array $stats, ?array $params = null): string
{
    global $svgGenerator;
    return $svgGenerator->generateCard($stats, $params);
}

function generateErrorCard(string $message, ?array $params = null): string
{
    global $svgGenerator;
    return $svgGenerator->generateErrorCard($message, $params);
}

function removeAnimations(string $svg): string
{
    global $svgGenerator;
    return $svgGenerator->removeAnimations($svg);
}

function convertHexColor(string $color): array
{
    global $svgGenerator;
    return $svgGenerator->convertHexColor($color);
}

function convertHexColors(string $svg): string
{
    global $svgGenerator;
    return $svgGenerator->convertHexColors($svg);
}

function convertSvgToPng(string $svg, int $cardWidth, int $cardHeight): string
{
    global $svgGenerator;
    return $svgGenerator->convertSvgToPng($svg, $cardWidth, $cardHeight);
}

function generateOutput(string|array $output, ?array $params = null, int $errorCode = 200): array
{
    $params = $params ?? $_GET;
    $requestedType = $params["type"] ?? "svg";

    if ($requestedType === "json") {
        $data = gettype($output) === "string" ? ["error" => $output, "code" => $errorCode] : $output;
        return [
            "contentType" => "application/json",
            "body" => json_encode($data),
        ];
    }

    $svg = gettype($output) === "string" ? generateErrorCard($output, $params) : generateCard($output, $params);
    $svg = convertHexColors($svg);

    if ($requestedType === "png") {
        try {
            $cardWidth = (int) preg_replace("/.*width=[\"'](\d+)px[\"'].*/", "$1", $svg);
            $cardHeight = (int) preg_replace("/.*height=[\"'](\d+)px[\"'].*/", "$1", $svg);
            $png = convertSvgToPng($svg, $cardWidth, $cardHeight);
            return [
                "contentType" => "image/png",
                "body" => $png,
            ];
        } catch (Exception $e) {
            return [
                "contentType" => "image/svg+xml",
                "status" => 500,
                "body" => generateErrorCard($e->getMessage(), $params),
            ];
        }
    }

    if (isset($params["disable_animations"]) && $params["disable_animations"] == "true") {
        $svg = removeAnimations($svg);
    }

    return [
        "contentType" => "image/svg+xml",
        "body" => $svg,
    ];
}

function renderOutput(string|array $output, int $responseCode = 200): void
{
    $response = generateOutput($output, null, $responseCode);
    http_response_code($responseCode);
    header("Content-Type: {$response["contentType"]}");
    exit($response["body"]);
}
