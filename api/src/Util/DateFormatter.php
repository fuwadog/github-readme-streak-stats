<?php

declare(strict_types=1);

namespace App\Util;

class DateFormatter
{
    /**
     * Convert date from Y-M-D to more human-readable format
     *
     * @param string $dateString String in Y-M-D format
     * @param string|null $format Date format to use, or null to use locale default
     * @param string $locale Locale code
     * @return string Formatted Date string
     */
    public function formatDate(string $dateString, ?string $format, string $locale): string
    {
        $date = new \DateTime($dateString);
        $formatted = "";
        $patternGenerator = new \IntlDatePatternGenerator($locale);
        if (date_format($date, "Y") == date("Y")) {
            if ($format) {
                $formatted = date_format($date, preg_replace("/\[.*?\]/", "", $format));
            } else {
                $pattern = $patternGenerator->getBestPattern("MMM d");
                $dateFormatter = new \IntlDateFormatter(
                    $locale,
                    \IntlDateFormatter::MEDIUM,
                    \IntlDateFormatter::NONE,
                    pattern: $pattern,
                );
                $formatted = $dateFormatter->format($date);
            }
        }
        else {
            if ($format) {
                $formatted = date_format($date, str_replace(["[", "]"], "", $format));
            } else {
                $pattern = $patternGenerator->getBestPattern("yyyy MMM d");
                $dateFormatter = new \IntlDateFormatter(
                    $locale,
                    \IntlDateFormatter::MEDIUM,
                    \IntlDateFormatter::NONE,
                    pattern: $pattern,
                );
                $formatted = $dateFormatter->format($date);
            }
        }
        return htmlspecialchars($formatted);
    }
}