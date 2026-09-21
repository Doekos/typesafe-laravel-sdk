<?php

declare(strict_types=1);

namespace Doekos\TypeSafe\Exceptions;

/** The rate limit was exceeded (429). */
class RateLimitException extends ApiException
{
    /** The server's requested wait in milliseconds, or `null` if unavailable. */
    public readonly ?float $retryAfterMs;

    /**
     * @param  array<mixed>  $headers
     */
    public function __construct(int $status, mixed $body, array $headers, ?string $endpoint = null, ?string $message = null)
    {
        parent::__construct($status, $body, $headers, $endpoint, $message);
        $this->retryAfterMs = self::parseRetryAfterMs($headers);
    }
}
