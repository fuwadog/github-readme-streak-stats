<?php

declare(strict_types=1);

use App\Output\SvgGenerator;
use PHPUnit\Framework\TestCase;

require_once "api/card.php";

final class FixturePngSvgGenerator extends SvgGenerator
{
    public int $convertedWidth = 0;
    public int $convertedHeight = 0;

    public function convertSvgToPng(string $svg, int $cardWidth, int $cardHeight): string
    {
        $this->convertedWidth = $cardWidth;
        $this->convertedHeight = $cardHeight;

        return "fixture-png";
    }
}

final class FailingPngSvgGenerator extends SvgGenerator
{
    public function convertSvgToPng(string $svg, int $cardWidth, int $cardHeight): string
    {
        throw new RuntimeException("PNG conversion failed");
    }
}

final class AnimationTest extends TestCase
{
    private array $stats = [
        "mode" => "daily",
        "totalContributions" => 2048,
        "firstContribution" => "2016-08-10",
        "longestStreak" => [
            "start" => "2016-03-14",
            "end" => "2016-12-19",
            "length" => 281,
        ],
        "currentStreak" => [
            "start" => "2019-03-28",
            "end" => "2019-04-12",
            "length" => 16,
        ],
        "excludedDays" => [],
    ];

    private array $params = [
        "background" => "000000",
        "border" => "111111",
        "stroke" => "222222",
        "ring" => "333333",
        "fire" => "444444",
        "currStreakNum" => "555555",
        "sideNums" => "666666",
        "currStreakLabel" => "777777",
        "sideLabels" => "888888",
        "dates" => "999999",
        "excludeDaysLabel" => "aaaaaa",
    ];

    protected function tearDown(): void
    {
        $GLOBALS["svgGenerator"] = new SvgGenerator();
    }

    public function testDefaultOutputIsStatic(): void
    {
        $render = generateOutput($this->stats, $this->params)["body"];

        $this->assertStringNotContainsString("<style>", $render);
        $this->assertStringNotContainsString("@keyframes", $render);
        $this->assertStringNotContainsString("animation:", $render);
        $this->assertStringContainsString("16", $render);
    }

    public function testAnimationsRequireExactOptIn(): void
    {
        foreach (["1", "yes", "false", ""] as $value) {
            $params = $this->params;
            $params["animation"] = $value;
            $render = generateOutput($this->stats, $params)["body"];

            $this->assertStringNotContainsString("animation:", $render, "Unexpected animation for '$value'.");
        }

        $params = $this->params;
        $params["animation"] = "true";
        $render = generateOutput($this->stats, $params)["body"];

        $this->assertStringContainsString("animation:", $render);
        $this->assertStringContainsString("@keyframes", $render);
    }

    public function testOptInAnimationTimingAndKeyframesRemainStable(): void
    {
        $params = $this->params;
        $params["animation"] = "true";
        $render = generateOutput($this->stats, $params)["body"];

        $this->assertStringContainsString("@keyframes currstreak", $render);
        $this->assertStringContainsString("0.6s linear forwards", $render);
        $this->assertStringContainsString("@keyframes fadein", $render);
        $this->assertStringContainsString("forwards 1.4s", $render);
        $this->assertStringContainsString("80% { font-size: 34px; opacity: 1; }", $render);
        $this->assertStringContainsString("100% { font-size: 28px; opacity: 1; }", $render);
    }

    public function testReducedMotionOutputKeepsAnimatedCardVisible(): void
    {
        $params = $this->params;
        $params["animation"] = "true";

        $render = generateOutput($this->stats, $params)["body"];
        $errorRender = generateOutput("Request failed", $params)["body"];

        $this->assertStringContainsString("opacity: 1 !important;", $render);
        $this->assertStringContainsString("opacity: 1 !important;", $errorRender);
    }

    public function testReducedMotionFallbackCanDisableOptInAnimations(): void
    {
        $params = $this->params;
        $params["animation"] = "true";
        $params["disable_animations"] = "true";
        $render = generateOutput($this->stats, $params)["body"];

        $this->assertStringNotContainsString("@keyframes", $render);
        $this->assertStringNotContainsString("animation:", $render);
    }

    public function testPngOutputUsesConversionSeam(): void
    {
        $generator = new FixturePngSvgGenerator();
        $GLOBALS["svgGenerator"] = $generator;

        $response = generateOutput($this->stats, ["type" => "png", "animation" => "true"]);

        $this->assertSame("image/png", $response["contentType"]);
        $this->assertSame(200, $response["status"]);
        $this->assertSame("fixture-png", $response["body"]);
        $this->assertSame(495, $generator->convertedWidth);
        $this->assertSame(195, $generator->convertedHeight);
    }

    public function testPngConversionFailureReturnsStaticSvgError(): void
    {
        $GLOBALS["svgGenerator"] = new FailingPngSvgGenerator();

        $response = generateOutput("Request failed", ["type" => "png", "animation" => "true"], 503);

        $this->assertSame("image/svg+xml", $response["contentType"]);
        $this->assertSame(500, $response["status"]);
        $this->assertStringContainsString("PNG conversion failed", $response["body"]);
        $this->assertStringNotContainsString("animation:", $response["body"]);
    }

    public function testErrorCardsSupportStaticAndOptInAnimationModes(): void
    {
        $static = generateOutput("An unknown error occurred", $this->params)["body"];
        $params = $this->params;
        $params["animation"] = "true";
        $animated = generateOutput("An unknown error occurred", $params)["body"];

        $this->assertStringContainsString("An unknown error occurred", $static);
        $this->assertStringNotContainsString("animation:", $static);
        $this->assertStringContainsString("An unknown error occurred", $animated);
        $this->assertStringContainsString("animation: fadein", $animated);
    }

    public function testCurrentStreakUsesCountUpAnimationWhenOptedIn(): void
    {
        $params = $this->params;
        $params["animation"] = "true";
        $render = generateOutput($this->stats, $params)["body"];

        $this->assertSame(1, substr_count($render, "animation: currstreak"));
        $this->assertStringContainsString("font-size='28px'", $render);
    }

    public function testAnimationsWorkWithEveryTheme(): void
    {
        $themes = include "api/themes.php";
        foreach (array_keys($themes) as $theme) {
            $params = $this->params;
            $params["theme"] = $theme;
            $params["animation"] = "true";
            $response = generateOutput($this->stats, $params);

            $this->assertSame("image/svg+xml", $response["contentType"], "Theme '$theme' did not render as SVG.");
            $this->assertStringContainsString("@keyframes", $response["body"], "Theme '$theme' lost animations.");
        }
    }
}
