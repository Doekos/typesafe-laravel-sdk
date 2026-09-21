<?php

declare(strict_types=1);

namespace Doekos\TypeSafe\Tests\Unit;

use Doekos\TypeSafe\Exceptions\ApiConnectionException;
use Doekos\TypeSafe\Exceptions\ApiException;
use Doekos\TypeSafe\Exceptions\ApiTimeoutException;
use Doekos\TypeSafe\Exceptions\TypeSafeException;
use Doekos\TypeSafe\RetryPolicy;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RetryPolicyTest extends TestCase
{
    #[Test]
    public function backoff_doubles_and_applies_jitter(): void
    {
        $policy = new RetryPolicy;

        self::assertSame(0.5, $policy->delay(0, null, 0.0));
        self::assertSame(0.375, $policy->delay(0, null, 1.0));
        self::assertSame(1.0, $policy->delay(1, null, 0.0));
        self::assertSame(2.0, $policy->delay(2, null, 0.0));
    }

    #[Test]
    public function backoff_is_capped_at_the_maximum(): void
    {
        self::assertSame(5.0, (new RetryPolicy)->delay(10, null, 0.0));
    }

    #[Test]
    public function zero_backoff_disables_delay(): void
    {
        self::assertSame(0.0, (new RetryPolicy(backoffInitial: 0.0))->delay(3, null, 0.5));
    }

    #[Test]
    public function retry_after_is_honored_when_within_the_maximum(): void
    {
        self::assertSame(2.0, (new RetryPolicy)->delay(0, 2000.0, 0.0));
    }

    #[Test]
    public function retry_after_over_the_maximum_falls_back_to_backoff(): void
    {
        self::assertSame(0.5, (new RetryPolicy)->delay(0, 70_000.0, 0.0));
    }

    #[Test]
    public function retry_after_is_ignored_when_disabled(): void
    {
        self::assertSame(0.5, (new RetryPolicy(respectRetryAfter: false))->delay(0, 2000.0, 0.0));
    }

    #[Test]
    public function it_classifies_built_in_retryable_errors(): void
    {
        $policy = new RetryPolicy;

        self::assertTrue($policy->retryable(new ApiTimeoutException(1.0)));
        self::assertTrue($policy->retryable(new ApiConnectionException('boom')));
        self::assertTrue($policy->retryable(ApiException::for(429, null, [])));
        self::assertTrue($policy->retryable(ApiException::for(503, null, [])));
        self::assertFalse($policy->retryable(ApiException::for(400, null, [])));
    }

    #[Test]
    public function built_in_toggles_are_respected(): void
    {
        self::assertFalse((new RetryPolicy(apiTimeoutError: false))->retryable(new ApiTimeoutException(1.0)));
        self::assertFalse((new RetryPolicy(apiConnectionError: false))->retryable(new ApiConnectionException('x')));
    }

    #[Test]
    public function extra_exception_types_and_predicate_trigger_retries(): void
    {
        self::assertTrue((new RetryPolicy(exceptions: [RuntimeException::class]))->retryable(new RuntimeException('x')));
        self::assertTrue((new RetryPolicy(predicate: fn (): bool => true))->retryable(ApiException::for(400, null, [])));
    }

    #[Test]
    public function merge_replaces_the_base_policy(): void
    {
        $override = new RetryPolicy(maxRetries: 5);

        self::assertSame($override, (new RetryPolicy)->merge($override));
    }

    #[Test]
    public function merge_overlays_a_partial_array_on_the_base_policy(): void
    {
        $base = new RetryPolicy(maxRetries: 4, httpStatuses: [429], timeout: null);

        $merged = $base->merge(['backoffJitter' => 0.0]);

        self::assertSame(4, $merged->maxRetries);
        self::assertSame([429 => true], $merged->httpStatuses);
        self::assertNull($merged->timeout);
        self::assertSame(0.0, $merged->backoffJitter);
    }

    #[Test]
    public function merge_rejects_unknown_or_mistyped_fields(): void
    {
        foreach ([['maxRetrys' => 1], ['maxRetries' => 'many'], ['maxRetries' => -1]] as $override) {
            try {
                (new RetryPolicy)->merge($override);
                self::fail('Expected TypeSafeException for '.json_encode($override));
            } catch (TypeSafeException) {
                self::addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function it_validates_its_configuration(): void
    {
        $cases = [
            fn () => new RetryPolicy(maxRetries: -1),
            fn () => new RetryPolicy(backoffJitter: 2.0),
            fn () => new RetryPolicy(timeout: 0.0),
            fn () => new RetryPolicy(httpStatuses: [42]),
        ];
        foreach ($cases as $case) {
            try {
                $case();
                self::fail('Expected TypeSafeException.');
            } catch (TypeSafeException $error) {
                self::assertNotSame('', $error->getMessage());
            }
        }
    }

    #[Test]
    public function a_null_timeout_disables_the_budget(): void
    {
        self::assertNull((new RetryPolicy(timeout: null))->timeout);
    }
}
