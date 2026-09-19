<?php

declare(strict_types=1);

namespace Doekos\TypeSafe\Tests;

use Doekos\TypeSafe\RetryPolicy;
use Doekos\TypeSafe\TypeSafeClient;
use Doekos\TypeSafe\TypeSafeServiceProvider;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [TypeSafeServiceProvider::class];
    }

    /**
     * Build a client wired to the faked HTTP factory. Call Http::fake() before using it.
     *
     * @param  array<string, string>  $headers
     */
    protected function client(
        ?string $apiKey = 'test-key',
        ?string $baseUrl = null,
        ?string $model = null,
        float|int|null $timeout = null,
        array $headers = [],
        ?RetryPolicy $retry = null,
        ?string $logChannel = null,
        ?string $logLevel = 'off',
        ?Factory $http = null,
    ): TypeSafeClient {
        return new TypeSafeClient($apiKey, $baseUrl, $model, $timeout, $headers, $retry, $logChannel, $logLevel, $http ?? $this->factory());
    }

    protected function factory(): Factory
    {
        /** @var Factory */
        return Http::getFacadeRoot();
    }

    protected function container(): Application
    {
        $app = $this->app;
        self::assertNotNull($app);

        return $app;
    }

    /**
     * The requests recorded by Http::fake(), in order.
     *
     * @return list<Request>
     */
    protected function sentRequests(): array
    {
        $requests = [];
        foreach (Http::recorded() as $pair) {
            $requests[] = $pair[0];
        }

        return $requests;
    }

    /** A connection failure with no HTTP response. */
    protected function connectionException(string $message = 'boom'): ConnectionException
    {
        return new ConnectionException($message, 0, new ConnectException($message, new GuzzleRequest('POST', 'https://api.typesafe.ai')));
    }

    /** A timeout as the installed Guzzle raises it: a v8 NetworkTimeoutException, or a v7 ConnectException with cURL errno 28. */
    protected function timeoutException(): ConnectionException
    {
        $request = new GuzzleRequest('POST', 'https://api.typesafe.ai');
        $guzzle8Timeout = 'GuzzleHttp\\Exception\\NetworkTimeoutException';
        $guzzle = class_exists($guzzle8Timeout)
            ? new $guzzle8Timeout('cURL error 28: timed out', $request)
            : (new \ReflectionClass(ConnectException::class))->newInstance('cURL error 28: timed out', $request, null, ['errno' => 28]);

        return new ConnectionException($guzzle->getMessage(), 0, $guzzle);
    }
}
