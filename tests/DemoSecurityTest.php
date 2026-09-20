<?php

declare(strict_types=1);

use App\Output\SvgGenerator;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 1) . "/vendor/autoload.php";
require_once "api/card.php";

final class DemoSecuritySvgGenerator extends SvgGenerator
{
    public function convertSvgToPng(string $svg, int $cardWidth, int $cardHeight): string
    {
        return "\x89PNG\r\n\x1a\n";
    }
}

final class DemoSecurityTest extends TestCase
{
    private function runPhp(string $code): string
    {
        $process = proc_open([PHP_BINARY, "-r", $code], [1 => ["pipe", "w"], 2 => ["pipe", "w"]], $pipes);
        if (!is_resource($process)) {
            $this->fail("Unable to start PHP subprocess.");
        }

        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return $output === false ? "" : $output;
    }

    public function testSiblingPrefixIsNotServedByStaticRoute(): void
    {
        $siblingPath = dirname(__DIR__) . "/api/demo-" . bin2hex(random_bytes(8)) . ".txt";
        file_put_contents($siblingPath, "sensitive sibling content");

        try {
            $script =
                '$_SERVER["REQUEST_URI"] = ' .
                var_export("/demo/../" . basename($siblingPath), true) .
                "; require " .
                var_export(dirname(__DIR__) . "/api/demo/vercel-static.php", true) .
                ";";
            $this->assertSame("Not found", $this->runPhp($script));
        } finally {
            unlink($siblingPath);
        }
    }

    public function testEncodedAndNormalizedTraversalIsRejected(): void
    {
        $staticPath = dirname(__DIR__) . "/api/demo/vercel-static.php";
        foreach (
            [
                "../composer.json",
                "css/../../composer.json",
                "css/../../preview.php",
                "%2e%2e/composer.json",
                "..%2fcomposer.json",
            ]
            as $path
        ) {
            $script =
                '$_GET = ' .
                var_export(["__demo_path" => $path], true) .
                "; require " .
                var_export($staticPath, true) .
                ";";

            $this->assertSame("Not found", $this->runPhp($script), "Traversal path was served: {$path}");
        }
    }

    public function testSymlinkToOutsideDemoRootIsRejectedBeforeAllowlisting(): void
    {
        $allowedPath = dirname(__DIR__) . "/api/demo/css/style.css";
        $backupPath = $allowedPath . ".security-test-backup";
        $outsidePath = dirname(__DIR__) . "/composer.json";
        $this->assertFileExists($allowedPath);
        $this->assertFileDoesNotExist($backupPath);

        $this->assertTrue(rename($allowedPath, $backupPath));
        $linked = false;
        try {
            try {
                $linked = @symlink($outsidePath, $allowedPath);
            } catch (\Throwable $error) {
                $this->markTestSkipped("Symlinks are unavailable: {$error->getMessage()}");
            }
            if (!$linked) {
                $this->markTestSkipped("Symlinks are unavailable on this test runner.");
            }

            $script =
                '$_GET = ' .
                var_export(["__demo_path" => "css/style.css"], true) .
                "; require " .
                var_export(dirname(__DIR__) . "/api/demo/vercel-static.php", true) .
                ";";
            $this->assertSame("Not found", $this->runPhp($script));
        } finally {
            if ($linked && is_link($allowedPath)) {
                unlink($allowedPath);
            }
            if (file_exists($backupPath)) {
                rename($backupPath, $allowedPath);
            }
        }
    }

    public function testStaticRouteOnlyServesApprovedAssetsAndPreview(): void
    {
        $staticPath = dirname(__DIR__) . "/api/demo/vercel-static.php";
        $assetScript =
            '$_GET = ' .
            var_export(["__demo_path" => "css/style.css"], true) .
            "; require " .
            var_export($staticPath, true) .
            ";";
        $this->assertStringContainsString("body", $this->runPhp($assetScript));

        $secretPath = dirname(__DIR__) . "/api/demo/security-test.php";
        file_put_contents($secretPath, "<?php echo 'secret';");
        try {
            $secretScript =
                '$_GET = ' .
                var_export(["__demo_path" => "security-test.php"], true) .
                "; require " .
                var_export($staticPath, true) .
                ";";
            $this->assertSame("Forbidden", $this->runPhp($secretScript));
        } finally {
            unlink($secretPath);
        }
    }

    public function testPreviewSupportsOnlyDailyAndWeeklyModes(): void
    {
        $previewPath = dirname(__DIR__) . "/api/demo/preview.php";

        foreach (["daily", "weekly"] as $mode) {
            $script =
                '$_GET = ' .
                var_export(["mode" => $mode, "type" => "json"], true) .
                "; require " .
                var_export($previewPath, true) .
                ";";
            $response = json_decode($this->runPhp($script), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame($mode, $response["mode"]);
        }

        $script =
            '$_GET = ' .
            var_export(["mode" => "monthly", "type" => "json"], true) .
            "; require " .
            var_export($previewPath, true) .
            ";";
        $response = json_decode($this->runPhp($script), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(400, $response["code"]);
    }

    public function testDemoIgnoresUnknownQueryParameters(): void
    {
        $script = file_get_contents(dirname(__DIR__) . "/api/demo/js/script.js");
        $this->assertIsString($script);
        $this->assertStringContainsString("if (!propertyOption) {", $script);
        $this->assertStringContainsString("option.value === key", $script);
        $this->assertStringContainsString("return;\n        }\n        // add advanced property", $script);
    }

    public function testPreviewResponseContentTypesMatchRequestedOutput(): void
    {
        $stats = [
            "mode" => "daily",
            "totalContributions" => 2048,
            "firstContribution" => "2016-08-10",
            "longestStreak" => [
                "start" => "2021-12-19",
                "end" => "2022-03-14",
                "length" => 86,
            ],
            "currentStreak" => [
                "start" => "2021-12-19",
                "end" => "2022-01-03",
                "length" => 16,
            ],
            "excludedDays" => [],
        ];

        $originalGenerator = $GLOBALS["svgGenerator"];
        $GLOBALS["svgGenerator"] = new DemoSecuritySvgGenerator();
        try {
            $this->assertSame("image/svg+xml", generateOutput($stats, ["type" => "svg"])["contentType"]);
            $this->assertSame(
                "application/json; charset=UTF-8",
                generateOutput($stats, ["type" => "json"])["contentType"],
            );
            $this->assertSame("image/png", generateOutput($stats, ["type" => "png"])["contentType"]);
        } finally {
            $GLOBALS["svgGenerator"] = $originalGenerator;
        }
    }

    public function testDemoFormUsesUniqueIdsAndMatchingLabels(): void
    {
        $markup = file_get_contents(dirname(__DIR__) . "/api/demo/index.php");
        $this->assertIsString($markup);

        preg_match_all('/\bid="([^"]+)"/', $markup, $idMatches);
        $this->assertCount(count(array_unique($idMatches[1])), $idMatches[1]);

        preg_match_all('/<label\s+for="([^"]+)"/', $markup, $labelMatches);
        foreach ($labelMatches[1] as $labelId) {
            $this->assertStringContainsString('id="' . $labelId . '"', $markup);
        }
    }

    public function testDemoThemeControlIsKeyboardOperableAndHasNoJavascriptLinks(): void
    {
        $markup = file_get_contents(dirname(__DIR__) . "/api/demo/index.php");
        $this->assertIsString($markup);
        $this->assertMatchesRegularExpression(
            '/<button(?=[^>]*class="darkmode")(?=[^>]*type="button")[^>]*>/',
            $markup,
        );
        $this->assertStringNotContainsString('href="javascript:', $markup);
        $this->assertStringNotContainsString("href='javascript:", $markup);
    }

    public function testDemoCheckboxesRemainFocusableAndDiscoverable(): void
    {
        $styles = file_get_contents(dirname(__DIR__) . "/api/demo/css/style.css");
        $this->assertIsString($styles);
        $this->assertStringNotContainsString(".checkbox-buttons input {\n  display: none", $styles);
        $this->assertStringContainsString("position: absolute", $styles);
        $this->assertStringContainsString("clip: rect(0, 0, 0, 0)", $styles);
        $this->assertStringContainsString(":focus-visible + label", $styles);
    }

    public function testDemoDoesNotLoadThirdPartyScriptsUnconditionally(): void
    {
        $markup = file_get_contents(dirname(__DIR__) . "/api/demo/index.php");
        $this->assertIsString($markup);
        $this->assertStringNotContainsString("googletagmanager.com", $markup);
        $this->assertStringNotContainsString("buttons.github.io/buttons.js", $markup);
        $this->assertStringContainsString('src="./js/script.js', $markup);
    }
}
