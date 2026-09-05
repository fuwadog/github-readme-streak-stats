<?php

declare(strict_types=1);

use App\Client\GitHubClient;
use App\Service\WhitelistService;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 1) . "/vendor/autoload.php";
require_once dirname(__DIR__, 1) . "/api/src/Client/GitHubClient.php";
require_once dirname(__DIR__, 1) . "/api/src/Service/WhitelistService.php";
require_once dirname(__DIR__, 1) . "/api/stats.php";

final class SecuritySpyGitHubClient extends GitHubClient
{
    public int $validationCalls = 0;
    public int $requestCalls = 0;

    public function __construct()
    {
        parent::__construct(["test-token"]);
    }

    public function validateCredentials(): void
    {
        ++$this->validationCalls;
    }

    public function executeContributionGraphRequests(string $user, array $years): array
    {
        ++$this->requestCalls;
        return [];
    }
}

final class ApiSecurityTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SERVER["WHITELIST"]);
    }

    public function testMissingOrBlankWhitelistDeniesByDefault(): void
    {
        // Arrange
        unset($_SERVER["WHITELIST"]);

        // Act
        $missing = new WhitelistService();
        $blank = new WhitelistService("");

        // Assert
        $this->assertFalse($missing->isWhitelisted("DenverCoder1"));
        $this->assertFalse($blank->isWhitelisted("DenverCoder1"));
    }

    public function testWildcardWhitelistDeniesByDefault(): void
    {
        // Arrange
        $service = new WhitelistService("*");

        // Act and Assert
        $this->assertFalse($service->isWhitelisted("any-user"));
    }

    public function testConfiguredWhitelistOnlyAllowsListedUsers(): void
    {
        // Arrange
        $service = new WhitelistService("DenverCoder1");

        // Act and Assert
        $this->assertTrue($service->isWhitelisted("DenverCoder1"));
        $this->assertTrue($service->isWhitelisted("dEnVeRcOdEr1"));
        $this->assertFalse($service->isWhitelisted("other-user"));
    }

    public function testMalformedWhitelistDeniesEveryUser(): void
    {
        foreach (["bad/user", "listed-user,", ["listed-user", 42]] as $configuration) {
            $service = new WhitelistService($configuration);

            $this->assertFalse($service->isWhitelisted("listed-user"));
        }
    }

    public function testDeniedUserCannotReachGitHubClient(): void
    {
        $client = new SecuritySpyGitHubClient();

        try {
            getContributionGraphs("other-user", null, $client, new WhitelistService("listed-user"));
            $this->fail("A non-whitelisted user must be denied.");
        } catch (InvalidArgumentException $error) {
            $this->assertSame(403, $error->getCode());
        }

        $this->assertSame(0, $client->validationCalls);
        $this->assertSame(0, $client->requestCalls);
    }

    public function testInvalidCredentialsAreRejectedBeforeSuccessfulResponseHandling(): void
    {
        // Arrange
        $client = new GitHubClient(["test-token"]);
        $method = new ReflectionMethod($client, "isUnauthorizedResponse");
        $method->setAccessible(true);

        // Act and Assert
        $this->assertTrue($method->invoke($client, 401, (object) [], ""));
        $this->assertTrue(
            $method->invoke(
                $client,
                200,
                (object) ["errors" => [(object) ["type" => "BAD_CREDENTIALS"]]],
                "Authentication failed",
            ),
        );
        $this->assertFalse($method->invoke($client, 200, (object) ["data" => (object) []], ""));
    }

    public function testTrustedProxyAndServerlessRateLimitContractsAreEnforced(): void
    {
        // Arrange
        $index = file_get_contents(dirname(__DIR__, 1) . "/api/index.php");

        // Act and Assert
        $this->assertIsString($index);
        $this->assertStringContainsString('$_SERVER["REMOTE_ADDR"]', $index);
        $this->assertStringContainsString("TRUSTED_PROXY_CIDRS", $index);
        $this->assertStringContainsString('if (isTrustedProxy($remoteAddress))', $index);
        $this->assertStringContainsString("EXTERNAL_RATE_LIMITER", $index);
        $this->assertStringContainsString(
            'throw new RuntimeException("Serverless rate limiting requires an external rate limiter.", 500);',
            $index,
        );
        $vercel = file_get_contents(dirname(__DIR__, 1) . "/vercel.json");
        $this->assertIsString($vercel);
        $this->assertStringNotContainsString('"key": "Cache-Control"', $vercel);
    }

    public function testGitHubTokenIsRedactedFromDiagnosticMessages(): void
    {
        $client = new GitHubClient(["test-token"]);
        $method = new ReflectionMethod($client, "sanitizeForLogging");
        $method->setAccessible(true);

        $sanitized = $method->invoke($client, "GitHub rejected test-token");

        $this->assertStringNotContainsString("test-token", $sanitized);
        $this->assertStringContainsString("[REDACTED]", $sanitized);
    }
}
