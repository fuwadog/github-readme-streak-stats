<?php

declare(strict_types=1);

namespace App\Client;

class GitHubClient
{
    /**
     * Sanitize sensitive data for logging by replacing tokens with [REDACTED]
     *
     * @param string $message The message to sanitize
     * @return string Sanitized message safe for logging
     */
    private function sanitizeForLogging(string $message): string
    {
        $sanitized = $message;
        if (isset($_ENV["TOKEN"])) {
            $sanitized = str_replace($_ENV["TOKEN"], "[REDACTED]", $sanitized);
        }
        $index = 2;
        while (isset($_ENV["TOKEN{$index}"])) {
            $sanitized = str_replace($_ENV["TOKEN{$index}"], "[REDACTED]", $sanitized);
            $index++;
        }
        if (strlen($sanitized) > 500) {
            $sanitized = substr($sanitized, 0, 500) . "... [truncated]";
        }
        return $sanitized;
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
        $start = "$year-01-01T00:00:00Z";
        $end = "$year-12-31T23:59:59Z";
        return "query {
            user(login: \"$user\") {
                createdAt
                contributionsCollection(from: \"$start\", to: \"$end\") {
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
        $tokens = [];
        $requests = [];
        foreach ($years as $year) {
            $tokens[$year] = $this->getGitHubToken();
            $query = $this->buildContributionGraphQuery($user, $year);
            $requests[$year] = $this->getGraphQLCurlHandle($query, $tokens[$year]);
        }
        $multi = curl_multi_init();
        foreach ($requests as $handle) {
            curl_multi_add_handle($multi, $handle);
        }
        $running = null;
        do {
            curl_multi_exec($multi, $running);
            if ($running) {
                curl_multi_select($multi, 1.0);
            }
        } while ($running);
        $responses = [];
        foreach ($requests as $year => $handle) {
            $contents = curl_multi_getcontent($handle);
            $curlError = curl_error($handle);
            $curlErrno = curl_errno($handle);
            $httpCode = curl_getinfo($handle, CURLINFO_HTTP_CODE);
            if ($contents === false || $contents === "") {
                error_log(
                    "cURL failed for $user's $year contributions: errno=$curlErrno, error=$curlError, httpCode=$httpCode",
                );
                $contents = "";
            }
            $decoded = is_string($contents) && $contents !== "" ? json_decode($contents) : null;
            if (!is_object($decoded) || empty($decoded->data) || !empty($decoded->errors)) {
                $message = is_object($decoded)
                    ? $decoded->errors[0]->message ?? ($decoded->message ?? "An API error occurred.")
                    : "An API error occurred.";
                $error_type = is_object($decoded) ? $decoded->errors[0]->type ?? "" : "";
                if ($curlErrno === 60) {
                    throw new \RuntimeException("You don't have a valid SSL Certificate installed or XAMPP.", 500);
                } elseif ($curlErrno) {
                    throw new \RuntimeException("cURL error ($curlErrno): $curlError", 500);
                } elseif ($error_type === "NOT_FOUND") {
                    throw new \InvalidArgumentException("Could not find a user with that name.", 404);
                }
                if (str_contains($message, "rate limit exceeded")) {
                    $this->removeGitHubToken($tokens[$year]);
                }
                $sanitizedContents = $this->sanitizeForLogging((string) ($contents ?? ""));
                error_log("First attempt to decode response for $user's $year contributions failed. $message");
                error_log("Contents: $sanitizedContents");
                $query = $this->buildContributionGraphQuery($user, $year);
                $token = $this->getGitHubToken();
                $request = $this->getGraphQLCurlHandle($query, $token);
                $contents = curl_exec($request);
                $retryCurlError = curl_error($request);
                $retryCurlErrno = curl_errno($request);
                $retryHttpCode = curl_getinfo($request, CURLINFO_HTTP_CODE);
                if ($contents === false || $contents === "") {
                    error_log(
                        "Retry cURL failed for $user's $year contributions: errno=$retryCurlErrno, error=$retryCurlError, httpCode=$retryHttpCode",
                    );
                    $contents = "";
                }
                $decoded = is_string($contents) && $contents !== "" ? json_decode($contents) : null;
                if (!is_object($decoded) || empty($decoded->data)) {
                    $message = is_object($decoded)
                        ? $decoded->errors[0]->message ?? ($decoded->message ?? "An API error occurred.")
                        : "An API error occurred.";
                    if (str_contains($message, "rate limit exceeded")) {
                        $this->removeGitHubToken($token);
                    }
                    $sanitizedContents = $this->sanitizeForLogging((string) ($contents ?? ""));
                    error_log("Failed to decode response for $user's $year contributions after 2 attempts. $message");
                    error_log("Contents: $sanitizedContents");
                    continue;
                }
            }
            $responses[$year] = $decoded;
        }
        foreach ($requests as $request) {
            curl_multi_remove_handle($multi, $request);
        }
        curl_multi_close($multi);
        return $responses;
    }

    /**
     * Get all tokens from environment variables (TOKEN, TOKEN2, TOKEN3, etc.) if they are set
     *
     * @return array<string> List of tokens
     */
    public function getGitHubTokens(): array
    {
        if (isset($GLOBALS["ALL_TOKENS"])) {
            return $GLOBALS["ALL_TOKENS"];
        }
        $tokens = isset($_ENV["TOKEN"]) ? [$_ENV["TOKEN"]] : [];
        $index = 2;
        while (isset($_ENV["TOKEN{$index}"])) {
            $tokens[] = $_ENV["TOKEN{$index}"];
            $index++;
        }
        $GLOBALS["ALL_TOKENS"] = $tokens;
        return $tokens;
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
        $all_tokens = $this->getGitHubTokens();
        if (empty($all_tokens)) {
            throw new \RuntimeException("There is no GitHub token available.", 500);
        }
        return $all_tokens[array_rand($all_tokens)];
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
        $index = array_search($token, $GLOBALS["ALL_TOKENS"]);
        if ($index !== false) {
            unset($GLOBALS["ALL_TOKENS"][$index]);
        }
        if (empty($GLOBALS["ALL_TOKENS"])) {
            throw new \RuntimeException(
                "We are being rate-limited! Check <a href='https://git.io/streak-ratelimit' font-weight='bold'>git.io/streak-ratelimit</a> for details.",
                429,
            );
        }
    }

    /**
     * Create a CurlHandle for a POST request to GitHub's GraphQL API
     *
     * @param string $query GraphQL query
     * @param string $token GitHub token to use for the request
     * @return \CurlHandle The curl handle for the request
     */
    public function getGraphQLCurlHandle(string $query, string $token): \CurlHandle
    {
        $headers = [
            "Authorization: bearer $token",
            "Content-Type: application/json",
            "Accept: application/vnd.github.v4.idl",
            "User-Agent: GitHub-Readme-Streak-Stats",
        ];
        $body = ["query" => $query];
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://api.github.com/graphql");
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        // Use bundled CA cert with fallback to system default
        $caPath = __DIR__ . "/../../cacert.pem";
        if (file_exists($caPath)) {
            curl_setopt($ch, CURLOPT_CAINFO, $caPath);
        }
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_VERBOSE, true);
        curl_setopt($ch, CURLOPT_STDERR, fopen("php://temp", "w+"));
        return $ch;
    }
}
