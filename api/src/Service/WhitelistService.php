<?php

declare(strict_types=1);

namespace App\Service;

class WhitelistService
{
    /**
     * Get environment variable with multiple fallback methods
     *
     * @param string $key Environment variable name
     * @return string|null The environment variable value or null if not set
     */
    private function getEnvVar(string $key): ?string
    {
        if (isset($_SERVER[$key]) && $_SERVER[$key] !== "") {
            return $_SERVER[$key];
        }
        $value = getenv($key);
        return $value !== false ? $value : null;
    }

    /**
     * Check if a GitHub username is allowed based on the whitelist
     *
     * @param string $user GitHub username to check
     * @return bool True if the username is in the whitelist or if the whitelist is empty, false otherwise
     */
    public function isWhitelisted(string $user): bool
    {
        $whitelistValue = $this->getEnvVar("WHITELIST");

        $whitelist = array_map("trim", array_filter(explode(",", $whitelistValue ?? "")));

        $result = empty($whitelist) || in_array($user, $whitelist, true);

        return $result;
    }
}
