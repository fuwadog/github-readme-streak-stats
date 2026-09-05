<?php

declare(strict_types=1);

namespace App\Client;

class GitHubClient
{
    private const MAX_ATTEMPTS = 100;
    private const MAX_RETRIES_PER_REQUEST = 1;
    private const VERCEL_HOBBY_MAX_DURATION_SECONDS = 10;
    private const CLEANUP_MARGIN_SECONDS = 2;
    private const REQUEST_DEADLINE_SECONDS = self::VERCEL_HOBBY_MAX_DURATION_SECONDS - self::CLEANUP_MARGIN_SECONDS;
    private const MAX_MULTI_SELECT_MILLISECONDS = 250;
    private const GRAPHQL_ENDPOINT = "https://api.github.com/graphql";

    private array $tokens = [];
    private array $redactionTokens = [];
    private int $attemptsUsed = 0;
    private ?int $deadlineNanoseconds = null;

    public function __construct(?array $tokens = null)
    {
        if ($tokens === null) {
            $tokens = [];
            for ($index = 1; $index <= 100; $index++) {
                $key = $index === 1 ? "TOKEN" : "TOKEN{$index}";
                $value = $this->getEnvironmentValue($key);
                if (is_string($value) && trim($value) !== "") {
                    $tokens[] = trim($value);
                }
            }
        }
        $this->tokens = array_values(
            array_unique(
                array_filter(
                    array_map(static fn(mixed $token): mixed => is_string($token) ? trim($token) : null, $tokens),
                    static fn(mixed $token): bool => is_string($token) && $token !== "",
                ),
            ),
        );
        $this->redactionTokens = $this->tokens;
    }

    private function getEnvironmentValue(string $key): ?string
    {
        foreach ([$_SERVER, $_ENV] as $environment) {
            $value = $environment[$key] ?? null;
            if (is_string($value) && trim($value) !== "") {
                return trim($value);
            }
        }

        $value = getenv($key);
        return is_string($value) && trim($value) !== "" ? trim($value) : null;
    }
    /**
     * Sanitize sensitive data for logging by replacing tokens with [REDACTED]
     *
     * @param string $message The message to sanitize
     * @return string Sanitized message safe for logging
     */
    private function sanitizeForLogging(string $message): string
    {
        $sanitized = $message;
        foreach ($this->redactionTokens as $token) {
            $sanitized = str_replace($token, "[REDACTED]", $sanitized);
        }
        if (strlen($sanitized) > 500) {
            $sanitized = substr($sanitized, 0, 500) . "... [truncated]";
        }
        return $sanitized;
    }

    private function isValidGraphQLResponse(mixed $decoded, int $httpCode): bool
    {
        return is_object($decoded) &&
            is_object($decoded->data ?? null) &&
            is_object($decoded->data->user ?? null) &&
            empty($decoded->errors) &&
            ($httpCode === 0 || ($httpCode >= 200 && $httpCode < 300));
    }

    private function getDiagnosticType(mixed $decoded, int $curlErrno): string
    {
        if ($curlErrno !== 0) {
            return "transport";
        }

        $type = strtolower($this->getResponseErrorType($decoded));
        return preg_match("/^[a-z0-9_.-]{1,32}$/", $type) === 1 ? $type : "upstream";
    }

    private function logRequestFailure(string $event, int $httpCode, mixed $decoded, int $curlErrno = 0): void
    {
        $status = $httpCode >= 100 && $httpCode <= 599 ? $httpCode : 0;
        error_log(
            "github_request_failed event={$event} status={$status} type=" .
                $this->getDiagnosticType($decoded, $curlErrno),
        );
    }

    private function getResponseErrorMessage(mixed $decoded): string
    {
        if (!is_object($decoded)) {
            return "An API error occurred.";
        }
        $firstError = is_array($decoded->errors ?? null) ? $decoded->errors[0] ?? null : null;
        if (is_object($firstError) && is_string($firstError->message ?? null)) {
            return $firstError->message;
        }
        return is_string($decoded->message ?? null) ? $decoded->message : "An API error occurred.";
    }

    private function getResponseErrorType(mixed $decoded): string
    {
        if (!is_object($decoded) || !is_array($decoded->errors ?? null)) {
            return "";
        }
        $firstError = $decoded->errors[0] ?? null;
        return is_object($firstError) && is_string($firstError->type ?? null) ? $firstError->type : "";
    }

    private function consumeAttempt(): void
    {
        if ($this->attemptsUsed >= self::MAX_ATTEMPTS) {
            throw new \RuntimeException("GitHub request attempt budget exhausted.", 503);
        }
        $this->getRemainingTimeoutMilliseconds();
        ++$this->attemptsUsed;
    }

    private function getRemainingTimeoutMilliseconds(): int
    {
        $this->deadlineNanoseconds ??= hrtime(true) + self::REQUEST_DEADLINE_SECONDS * 1_000_000_000;
        $remainingNanoseconds = $this->deadlineNanoseconds - hrtime(true);
        if ($remainingNanoseconds <= 0) {
            throw new \RuntimeException("GitHub request deadline exceeded.", 504);
        }
        return max(1, min(self::REQUEST_DEADLINE_SECONDS * 1_000, intdiv($remainingNanoseconds, 1_000_000)));
    }

    /**
     * @return array{contents:string, decoded:mixed, curlErrno:int, httpCode:int, message:string}
     */
    private function readCurlResponse(
        \CurlHandle $handle,
        string $user,
        int $year,
        string $attemptLabel,
        string|false|null $providedContents = null,
    ): array {
        $contents = $providedContents ?? curl_multi_getcontent($handle);
        $curlErrno = curl_errno($handle);
        $httpCode = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        if ($contents === false || $contents === "") {
            $this->logRequestFailure("transport", $httpCode, null, $curlErrno);
            $contents = "";
        }
        $decoded = $contents !== "" ? json_decode($contents) : null;
        $message = $this->getResponseErrorMessage($decoded);
        if ($curlErrno === 60) {
            throw new \RuntimeException("You don't have a valid SSL Certificate installed or XAMPP.", 500);
        }
        if ($this->getResponseErrorType($decoded) === "NOT_FOUND") {
            throw new \InvalidArgumentException("Could not find a user with that name.", 404);
        }
        if (!$this->isValidGraphQLResponse($decoded, $httpCode)) {
            $this->logRequestFailure("response", $httpCode, $decoded, $curlErrno);
        }
        return [
            "contents" => $contents,
            "decoded" => $decoded,
            "curlErrno" => $curlErrno,
            "httpCode" => $httpCode,
            "message" => $message,
        ];
    }

    private function isUnauthorizedResponse(int $httpCode, mixed $decoded, string $message): bool
    {
        $errorType = strtoupper($this->getResponseErrorType($decoded));
        return $httpCode === 401 ||
            in_array($errorType, ["BAD_CREDENTIALS", "INVALID_TOKEN", "UNAUTHORIZED"], true) ||
            stripos($message, "bad credentials") !== false;
    }

    private function handleTokenFailure(string $token, mixed $decoded, int $httpCode, string $message): void
    {
        if ($httpCode === 429 || stripos($message, "rate limit") !== false) {
            $this->discardToken($token, "rate_limited");
            return;
        }
        if ($this->isUnauthorizedResponse($httpCode, $decoded, $message)) {
            $this->discardToken($token, "unauthorized");
        }
    }

    private function discardToken(string $token, string $reason): void
    {
        $index = array_search($token, $this->tokens, true);
        if ($index !== false) {
            unset($this->tokens[$index]);
            $this->tokens = array_values($this->tokens);
        }
        if ($this->tokens !== []) {
            return;
        }
        if ($reason === "unauthorized") {
            throw new \RuntimeException("All configured GitHub tokens were unauthorized.", 401);
        }
        throw new \RuntimeException(
            "We are being rate-limited! Check <a href='https://git.io/streak-ratelimit' font-weight='bold'>git.io/streak-ratelimit</a> for details.",
            429,
        );
    }

    /**
     * Build a GraphQL query for a contribution graph
     *
     * @param string $user GitHub username to get graphs for
     * @param int $year Year to get graph for
     * @return string GraphQL query
     */
    public function buildContributionGraphQuery(string $user, int $year): string
    {
        return "query(\$login: String!, \$from: DateTime!, \$to: DateTime!) {
            user(login: \$login) {
                createdAt
                contributionsCollection(from: \$from, to: \$to) {
                    contributionYears
                    contributionCalendar {
                        weeks {
                            contributionDays {
                                contributionCount
                                date
                            }
                        }
                    }
                }
            }
        }";
    }

    /**
     * Execute multiple requests with cURL and handle GitHub API rate limits and errors
     *
     * @param string $user GitHub username to get graphs for
     * @param array<int> $years Years to get graphs for
     * @return array<int,\stdClass> List of GraphQL response objects with years as keys
     */
    public function executeContributionGraphRequests(string $user, array $years): array
    {
        if (count($years) > self::MAX_ATTEMPTS) {
            throw new \InvalidArgumentException("Too many contribution years requested.", 400);
        }
        if ($years === []) {
            return [];
        }
        $this->deadlineNanoseconds ??= hrtime(true) + self::REQUEST_DEADLINE_SECONDS * 1_000_000_000;

        $tokens = [];
        $requests = [];
        $multi = curl_multi_init();
        if ($multi === false) {
            throw new \RuntimeException("Unable to initialize the GitHub request pool.", 500);
        }

        try {
            foreach ($years as $year) {
                if (!is_int($year)) {
                    throw new \InvalidArgumentException("Contribution years must be integers.", 400);
                }
                $tokens[$year] = $this->getGitHubToken();
                $this->consumeAttempt();
                $query = $this->buildContributionGraphQuery($user, $year);
                $requests[$year] = $this->getGraphQLCurlHandle($query, $tokens[$year], [
                    "login" => $user,
                    "from" => "$year-01-01T00:00:00Z",
                    "to" => "$year-12-31T23:59:59Z",
                ]);
                if (curl_multi_add_handle($multi, $requests[$year]) !== CURLM_OK) {
                    $handle = $requests[$year];
                    unset($requests[$year]);
                    curl_close($handle);
                    throw new \RuntimeException("Unable to add a GitHub request to the request pool.", 502);
                }
            }

            $running = 0;
            do {
                $this->getRemainingTimeoutMilliseconds();
                $multiStatus = curl_multi_exec($multi, $running);
                if ($multiStatus !== CURLM_OK && $multiStatus !== CURLM_CALL_MULTI_PERFORM) {
                    throw new \RuntimeException("GitHub request pool failed.", 502);
                }
                if ($running) {
                    $selectTimeoutMilliseconds = min(
                        self::MAX_MULTI_SELECT_MILLISECONDS,
                        $this->getRemainingTimeoutMilliseconds(),
                    );
                    $selected = curl_multi_select($multi, $selectTimeoutMilliseconds / 1000);
                    if ($selected === -1) {
                        usleep(1_000);
                    }
                }
            } while ($running);

            $responses = [];
            foreach ($requests as $year => $handle) {
                $response = $this->readCurlResponse($handle, $user, (int) $year, "First attempt");
                curl_multi_remove_handle($multi, $handle);
                curl_close($handle);
                unset($requests[$year]);

                if (!$this->isValidGraphQLResponse($response["decoded"], $response["httpCode"])) {
                    $this->handleTokenFailure(
                        $tokens[$year],
                        $response["decoded"],
                        $response["httpCode"],
                        $response["message"],
                    );

                    $retrySucceeded = false;
                    for ($retryCount = 0; $retryCount < self::MAX_RETRIES_PER_REQUEST; ++$retryCount) {
                        $retryToken = $this->getGitHubToken();
                        $this->consumeAttempt();
                        $retryHandle = null;
                        try {
                            $retryHandle = $this->getGraphQLCurlHandle(
                                $this->buildContributionGraphQuery($user, (int) $year),
                                $retryToken,
                                [
                                    "login" => $user,
                                    "from" => "$year-01-01T00:00:00Z",
                                    "to" => "$year-12-31T23:59:59Z",
                                ],
                            );
                            $retryContents = curl_exec($retryHandle);
                            $retryResponse = $this->readCurlResponse(
                                $retryHandle,
                                $user,
                                (int) $year,
                                "Retry",
                                $retryContents,
                            );
                        } finally {
                            if ($retryHandle instanceof \CurlHandle) {
                                curl_close($retryHandle);
                            }
                        }

                        if ($this->isValidGraphQLResponse($retryResponse["decoded"], $retryResponse["httpCode"])) {
                            $response = $retryResponse;
                            $retrySucceeded = true;
                            break;
                        }
                        $this->handleTokenFailure(
                            $retryToken,
                            $retryResponse["decoded"],
                            $retryResponse["httpCode"],
                            $retryResponse["message"],
                        );
                    }
                    if (!$retrySucceeded) {
                        throw new \RuntimeException("Failed to retrieve contributions after retry.", 502);
                    }
                }
                $responses[$year] = $response["decoded"];
            }
            return $responses;
        } finally {
            foreach ($requests as $request) {
                curl_multi_remove_handle($multi, $request);
                curl_close($request);
            }
            curl_multi_close($multi);
        }
    }

    /**
     * Get all tokens from environment variables (TOKEN, TOKEN2, TOKEN3, etc.) if they are set
     *
     * @return array<string> List of tokens
     */
    public function getGitHubTokens(): array
    {
        return $this->tokens;
    }

    public function validateCredentials(): void
    {
        if ($this->tokens === []) {
            throw new \RuntimeException("There is no GitHub token available.", 500);
        }
    }

    /**
     * Get a token from the token pool
     *
     * @return string GitHub token
     *
     * @throws \RuntimeException if no tokens are available
     */
    public function getGitHubToken(): string
    {
        $allTokens = $this->getGitHubTokens();
        if ($allTokens === []) {
            throw new \RuntimeException("There is no GitHub token available.", 500);
        }
        return $allTokens[array_rand($allTokens)];
    }

    /**
     * Remove a token from the token pool
     *
     * @param string $token Token to remove
     * @return void
     *
     * @throws \RuntimeException if no tokens are available after removing the token
     */
    public function removeGitHubToken(string $token): void
    {
        $this->discardToken($token, "rate_limited");
    }

    /**
     * Create a CurlHandle for a POST request to GitHub's GraphQL API
     *
     * @param string $query GraphQL query
     * @param string $token GitHub token to use for the request
     * @return \CurlHandle The curl handle for the request
     */
    public function getGraphQLCurlHandle(string $query, string $token, array $variables = []): \CurlHandle
    {
        $headers = [
            "Authorization: bearer $token",
            "Content-Type: application/json",
            "Accept: application/vnd.github.v4.idl",
            "User-Agent: GitHub-Readme-Streak-Stats",
        ];
        $body = ["query" => $query, "variables" => $variables];
        $encodedBody = json_encode($body, JSON_THROW_ON_ERROR);
        $timeoutMilliseconds = $this->getRemainingTimeoutMilliseconds();
        $ch = curl_init();
        if ($ch === false) {
            throw new \RuntimeException("Unable to initialize a GitHub request.", 500);
        }
        curl_setopt($ch, CURLOPT_URL, self::GRAPHQL_ENDPOINT);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $encodedBody);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        // Use bundled CA cert with fallback to system default
        $caPath = __DIR__ . "/../../cacert.pem";
        if (file_exists($caPath)) {
            curl_setopt($ch, CURLOPT_CAINFO, $caPath);
        }
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, $timeoutMilliseconds);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, min(5_000, $timeoutMilliseconds));
        curl_setopt($ch, CURLOPT_VERBOSE, false);
        return $ch;
    }
}
