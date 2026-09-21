<?php

declare(strict_types=1);

namespace Doekos\TypeSafe\Tests\Feature;

use Doekos\TypeSafe\Pool;
use Doekos\TypeSafe\Questions\Noul;
use Doekos\TypeSafe\Tests\TestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;

final class LoggingTest extends TestCase
{
    private function spyLogger(): MockInterface
    {
        $logger = Mockery::spy(LoggerInterface::class);
        Log::shouldReceive('channel')->andReturn($logger);

        return $logger;
    }

    private function fakeOk(): void
    {
        Http::fake(['api.typesafe.ai/*' => Http::response(
            ['model' => 'm', 'answers' => [], 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]],
            200,
            ['x-typesafe-request-id' => 'req_log'],
        )]);
    }

    #[Test]
    public function info_level_logs_the_request_outcome(): void
    {
        $logger = $this->spyLogger();
        $this->fakeOk();

        $this->client(logLevel: 'info')->systemOne('x', ['q' => new Noul]);

        $logger->shouldHaveReceived('info')->with(Mockery::on(
            fn (string $message): bool => str_contains($message, 'POST https://api.typesafe.ai/v1/systemone <- 200')
                && str_contains($message, '(request req_log)'),
        ));
    }

    #[Test]
    public function pooled_requests_are_logged_like_single_ones(): void
    {
        $logger = $this->spyLogger();
        $this->fakeOk();

        $this->client(logLevel: 'info')->pool(fn (Pool $pool) => ['a' => $pool->systemOne('x', ['q' => new Noul])]);

        $logger->shouldHaveReceived('info')->with(Mockery::on(
            fn (string $message): bool => str_contains($message, 'POST https://api.typesafe.ai/v1/systemone <- 200')
                && str_contains($message, '(pool a, request req_log)'),
        ));
    }

    #[Test]
    public function warn_default_does_not_log_info(): void
    {
        $logger = $this->spyLogger();
        $this->fakeOk();

        $this->client(logLevel: 'warn')->systemOne('x', ['q' => new Noul]);

        $logger->shouldNotHaveReceived('info');
    }

    #[Test]
    public function debug_level_logs_and_redacts_credential_headers(): void
    {
        $logger = $this->spyLogger();
        $this->fakeOk();

        $this->client(apiKey: 'secret-value-1234', logLevel: 'debug', headers: [
            'X-My-Token' => 'super-secret',
            'Cookie' => 'session=1',
        ])->systemOne('x', ['q' => new Noul]);

        $logger->shouldHaveReceived('debug')->with(Mockery::on(function (string $message): bool {
            return str_contains($message, 'Bearer ***1234')
                && ! str_contains($message, 'secret-value-1234')
                && str_contains($message, '"X-My-Token":"***"')
                && str_contains($message, '"Cookie":"***"');
        }));
    }

    #[Test]
    public function warn_level_reports_dropped_answer_types(): void
    {
        $logger = $this->spyLogger();
        Http::fake(['api.typesafe.ai/*' => Http::response([
            'model' => 'm',
            'answers' => ['future' => ['type' => 'bounding_box']],
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ], 200)]);

        $this->client(logLevel: 'warn')->systemOne('x', ['q' => new Noul]);

        $logger->shouldHaveReceived('warning')->with(Mockery::on(
            fn (string $message): bool => str_contains($message, 'Ignoring answer "future" with unrecognized type "bounding_box"'),
        ));
    }

    #[Test]
    public function off_level_logs_nothing(): void
    {
        $logger = $this->spyLogger();
        $this->fakeOk();

        $this->client(logLevel: 'off')->systemOne('x', ['q' => new Noul]);

        $logger->shouldNotHaveReceived('info');
        $logger->shouldNotHaveReceived('debug');
        $logger->shouldNotHaveReceived('warning');
    }
}
