<?php

declare(strict_types=1);

namespace Doekos\TypeSafe;

use Closure;
use Doekos\TypeSafe\Exceptions\ApiConnectionException;
use Doekos\TypeSafe\Exceptions\ApiException;
use Doekos\TypeSafe\Exceptions\ApiTimeoutException;
use Doekos\TypeSafe\Exceptions\TypeSafeException;
use Throwable;

/**
 * Retry configuration and delay logic, in seconds.
 *
 * A superset of the Python fields plus the JS `maxRetryAfter`. A per-call `RetryPolicy` replaces the
 * client policy (Python); a per-call array of named fields merges over it (JS `Partial<RetryPolicy>`).
 * See {@see merge()}.
 */
final readonly class RetryPolicy
{
    /** @var array<int, true> */
    public array $httpStatuses;

    /** @var list<class-string<Throwable>> */
    public array $exceptions;

    /**
     * @param  list<int>|null  $httpStatuses  Retried status codes; defaults to 408, 429, and 5xx.
     * @param  list<class-string<Throwable>>  $exceptions  Extra exception types that trigger a retry.
     * @param  (Closure(Throwable): bool)|null  $predicate  Called with the error; `true` triggers a retry.
     */
    public function __construct(
        public int $maxRetries = 2,
        public float $backoffInitial = 0.5,
        public float $backoffMax = 5.0,
        public float $backoffJitter = 0.25,
        ?array $httpStatuses = null,
        public bool $respectRetryAfter = true,
        public float $maxRetryAfter = 60.0,
        public bool $apiConnectionError = true,
        public bool $apiTimeoutError = true,
        array $exceptions = [],
        public ?Closure $predicate = null,
        public ?float $timeout = 30.0,
    ) {
        if ($maxRetries < 0) {
            throw new TypeSafeException('maxRetries must be a non-negative integer.');
        }
        foreach (['backoffInitial' => $backoffInitial, 'backoffMax' => $backoffMax, 'maxRetryAfter' => $maxRetryAfter] as $name => $value) {
            if (! is_finite($value) || $value < 0) {
                throw new TypeSafeException(sprintf('%s must be a non-negative, finite number of seconds.', $name));
            }
        }
        if (! is_finite($backoffJitter) || $backoffJitter < 0 || $backoffJitter > 1) {
            throw new TypeSafeException('backoffJitter must be between zero and one.');
        }
        if ($timeout !== null && (! is_finite($timeout) || $timeout <= 0)) {
            throw new TypeSafeException('timeout must be a positive, finite number of seconds.');
        }

        $statuses = $httpStatuses ?? [408, 429, ...range(500, 599)];
        $normalized = [];
        foreach ($statuses as $status) {
            if ($status < 100 || $status > 999) {
                throw new TypeSafeException(sprintf('Invalid retry HTTP status %d.', $status));
            }
            $normalized[$status] = true;
        }
        $this->httpStatuses = $normalized;
        $this->exceptions = array_values($exceptions);
    }

    /** Whether this error is retryable under the policy. */
    public function retryable(Throwable $error): bool
    {
        $builtin = match (true) {
            $error instanceof ApiTimeoutException => $this->apiTimeoutError,
            $error instanceof ApiConnectionException => $this->apiConnectionError,
            $error instanceof ApiException => isset($this->httpStatuses[$error->status]),
            default => false,
        };
        if ($builtin) {
            return true;
        }
        foreach ($this->exceptions as $class) {
            if ($error instanceof $class) {
                return true;
            }
        }

        return $this->predicate !== null && ($this->predicate)($error);
    }

    /**
     * Delay in seconds before a zero-based retry attempt.
     *
     * Honors an allowed server delay; otherwise capped exponential backoff with jitter.
     *
     * @param  float  $rand  A value in [0, 1); injected for deterministic tests.
     */
    public function delay(int $attempt, ?float $retryAfterMs, float $rand): float
    {
        if ($this->respectRetryAfter && $retryAfterMs !== null) {
            $seconds = $retryAfterMs / 1000;
            if ($seconds <= $this->maxRetryAfter) {
                return $seconds;
            }
        }
        if ($this->backoffInitial === 0.0 || $this->backoffMax === 0.0) {
            return 0.0;
        }
        $exponential = min($this->backoffInitial * (2 ** $attempt), $this->backoffMax);
        $delay = $exponential * (1 - $rand * $this->backoffJitter);

        return min($exponential, round($delay, 3));
    }

    /**
     * Overlay a per-call override: a `RetryPolicy` wins entirely, an array of constructor field names
     * is merged over this policy (e.g. `['maxRetries' => 5]`).
     *
     * @param  RetryPolicy|array<string, mixed>  $override
     */
    public function merge(RetryPolicy|array $override): RetryPolicy
    {
        if ($override instanceof self) {
            return $override;
        }

        try {
            // Values are type-checked by the constructor at runtime; a TypeError is rethrown below.
            // @phpstan-ignore argument.type
            return new self(...[...get_object_vars($this), 'httpStatuses' => array_keys($this->httpStatuses), ...$override]);
        } catch (\Error $error) {
            throw new TypeSafeException('Invalid retry override: '.$error->getMessage(), 0, $error);
        }
    }
}
