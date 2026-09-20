<?php
declare(strict_types=1);
namespace App\Exception;
class ApiException extends \RuntimeException
{
    public function __construct(
        string $message,
        int $code = 500,
        ?\Throwable $previous = null,
        private readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function getRetryAfterSeconds(): ?int
    {
        return $this->retryAfterSeconds;
    }
}
