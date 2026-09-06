<?php

declare(strict_types=1);

namespace App\Service;

class WhitelistService
{
    private array $configuredWhitelist = [];
    private bool $validConfiguration = false;

    public function __construct(string|array|null $configuration = null)
    {
        $configuration ??= $this->getEnvVar("WHITELIST") ?? "";
        $entries = is_string($configuration) ? explode(",", $configuration) : $configuration;
        if ($entries === []) {
            return;
        }

        foreach ($entries as $entry) {
            if (!is_string($entry)) {
                $this->configuredWhitelist = [];
                return;
            }
            $entry = trim($entry);
            if (preg_match("/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,37}[A-Za-z0-9])?$/", $entry) !== 1) {
                $this->configuredWhitelist = [];
                return;
            }
            $this->configuredWhitelist[] = strtolower($entry);
        }
        $this->validConfiguration = $this->configuredWhitelist !== [];
    }
    /**
     * Get environment variable with multiple fallback methods
     *
     * @param string $key Environment variable name
     * @return string|null The environment variable value or null if not set
     */
    private function getEnvVar(string $key): ?string
    {
        if (array_key_exists($key, $_SERVER)) {
            return is_string($_SERVER[$key]) ? $_SERVER[$key] : null;
        }
        if (array_key_exists($key, $_ENV)) {
            return is_string($_ENV[$key]) ? $_ENV[$key] : null;
        }
        $value = getenv($key);
        return $value !== false ? $value : null;
    }

    /**
     * Check if a GitHub username is allowed based on the whitelist
     *
     * @param string $user GitHub username to check
     * @return bool True if the username is allowed by the configured policy
     */
    public function isWhitelisted(string $user): bool
    {
        return $this->validConfiguration && in_array(strtolower($user), $this->configuredWhitelist, true);
    }
}
