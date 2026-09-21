<?php

declare(strict_types=1);

namespace Doekos\TypeSafe\Tests\Feature;

use Doekos\TypeSafe\Exceptions\ApiConnectionException;
use Doekos\TypeSafe\Exceptions\TypeSafeException;
use Doekos\TypeSafe\Pool;
use Doekos\TypeSafe\Questions\Noul;
use Doekos\TypeSafe\Responses\SystemOneResponse;
use Doekos\TypeSafe\RetryPolicy;
use Doekos\TypeSafe\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\Test;

final class PoolTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function okBody(string $model): array
    {
        return [
            'model' => $model,
            'answers' => ['q' => ['type' => 'noul', 'noul' => 0.5]],
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ];
    }

    #[Test]
    public function it_resolves_each_key_and_never_throws(): void
    {
        Sleep::fake();
        $attempts = [];
        Http::fake(['api.typesafe.ai/*' => function (Request $request) use (&$attempts) {
            $raw = $request->data()['state'] ?? null;
            $state = is_string($raw) ? $raw : '';
            $attempts[$state] = ($attempts[$state] ?? 0) + 1;

            return match ($state) {
                'good' => Http::response($this->okBody('good'), 200, ['x-typesafe-request-id' => 'req_good']),
                'flaky' => $attempts['flaky'] < 2 ? Http::response(['e' => 1], 503) : Http::response($this->okBody('flaky'), 200),
                default => throw $this->connectionException(),
            };
        }]);

        $results = $this->client()->pool(function (Pool $pool): void {
            $pool->as('good')->systemOne('good', ['q' => new Noul]);
            $pool->as('flaky')->systemOne('flaky', ['q' => new Noul], retry: new RetryPolicy(backoffInitial: 0.0));
            $pool->as('down')->systemOne('down', ['q' => new Noul], retry: new RetryPolicy(backoffInitial: 0.0, maxRetries: 1));
            $pool->as('invalid')->systemOne('x', []); // empty questions -> validation error, no request
        });

        self::assertInstanceOf(SystemOneResponse::class, $results['good']);
        self::assertSame('req_good', $results['good']->requestId);

        self::assertInstanceOf(SystemOneResponse::class, $results['flaky']);
        self::assertSame('flaky', $results['flaky']->model);

        self::assertInstanceOf(ApiConnectionException::class, $results['down']);
        self::assertInstanceOf(TypeSafeException::class, $results['invalid']);

        self::assertSame(2, $attempts['flaky']);
    }

    #[Test]
    public function a_returned_keyed_array_names_the_results(): void
    {
        Http::fake(['api.typesafe.ai/*' => fn (Request $request) => Http::response($this->okBody(is_string($state = $request->data()['state'] ?? null) ? $state : ''), 200)]);

        $results = $this->client()->pool(fn (Pool $p) => [
            'first' => $p->systemOne('one', ['q' => new Noul]),
            'second' => $p->systemOne('two', ['q' => new Noul]),
        ]);

        self::assertSame(['first', 'second'], array_keys($results));
        self::assertInstanceOf(SystemOneResponse::class, $results['first']);
        self::assertSame('one', $results['first']->model);
        self::assertInstanceOf(SystemOneResponse::class, $results['second']);
        self::assertSame('two', $results['second']->model);
    }

    #[Test]
    public function mixing_queued_requests_with_other_values_is_rejected(): void
    {
        Http::fake();

        $this->expectException(TypeSafeException::class);

        $this->client()->pool(fn (Pool $p) => [
            'a' => $p->systemOne('one', ['q' => new Noul]),
            'b' => 'not a request',
        ]);
    }

    #[Test]
    public function retried_pool_items_carry_the_retry_count_header(): void
    {
        Sleep::fake();
        $attempts = 0;
        Http::fake(['api.typesafe.ai/*' => function () use (&$attempts) {
            $attempts++;

            return $attempts < 2 ? Http::response(['e' => 1], 503) : Http::response($this->okBody('m'), 200);
        }]);

        $this->client()->pool(fn (Pool $pool) => $pool->as('a')->systemOne('a', ['q' => new Noul], retry: new RetryPolicy(backoffInitial: 0.0)));

        $requests = $this->sentRequests();
        $first = $requests[0] ?? null;
        $second = $requests[1] ?? null;
        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertFalse($first->hasHeader('X-TypeSafe-Retry-Count'));
        self::assertTrue($second->hasHeader('X-TypeSafe-Retry-Count', '1'));
    }

    #[Test]
    public function it_keys_results_by_array_position_without_as(): void
    {
        Http::fake(['api.typesafe.ai/*' => Http::response($this->okBody('m'), 200)]);

        $results = $this->client()->pool(function (Pool $pool): void {
            $pool->systemOne('first', ['q' => new Noul]);
            $pool->systemOne('second', ['q' => new Noul]);
        });

        self::assertArrayHasKey(0, $results);
        self::assertArrayHasKey(1, $results);
        self::assertInstanceOf(SystemOneResponse::class, $results[0]);
    }
}
