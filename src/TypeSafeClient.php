<?php

declare(strict_types=1);

namespace Doekos\TypeSafe;

use Closure;
use Doekos\TypeSafe\Exceptions\ApiConnectionException;
use Doekos\TypeSafe\Exceptions\ApiException;
use Doekos\TypeSafe\Exceptions\ApiTimeoutException;
use Doekos\TypeSafe\Exceptions\ResponseValidationException;
use Doekos\TypeSafe\Exceptions\TypeSafeException;
use Doekos\TypeSafe\Questions\Question;
use Doekos\TypeSafe\Responses\ModelsResponse;
use Doekos\TypeSafe\Support\RequestLogger;
use Doekos\TypeSafe\Support\ResponseDecoder;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Foundation\Application;
use Illuminate\Http\Client\ConnectionException as HttpConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Pool as HttpPool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Sleep;

/** Laravel-native client for the TypeSafe (Jev / System One) API. */
final class TypeSafeClient
{
    public const VERSION = '0.7.0';

    private const DEFAULT_BASE_URL = 'https://api.typesafe.ai';

    private const DEFAULT_MODEL = 'jev-latest';

    private const SYSTEM_ONE_PATH = '/v1/systemone';

    private const MODELS_PATH = '/v1/models';

    /** cURL timeout errno surfaced through Guzzle's handler context. */
    private const CURL_TIMEOUT_ERRNO = 28;

    private readonly string $apiKey;

    private readonly string $baseUrl;

    private readonly string $defaultModel;

    private readonly float $timeout;

    /** @var array<string, string> */
    private readonly array $headers;

    private readonly RetryPolicy $retry;

    private readonly Factory $http;

    private readonly RequestLogger $logger;

    private readonly ResponseDecoder $decoder;

    public readonly Models $models;

    /**
     * @param  RetryPolicy|array<string, mixed>|null  $retry
     * @param  array<string, string>  $headers
     */
    public function __construct(
        ?string $apiKey = null,
        ?string $baseUrl = null,
        ?string $model = null,
        float|int|null $timeout = null,
        array $headers = [],
        RetryPolicy|array|null $retry = null,
        ?string $logChannel = null,
        ?string $logLevel = null,
        ?Factory $http = null,
    ) {
        $this->apiKey = self::firstNonBlank($apiKey, self::env('TYPESAFE_API_KEY'))
            ?? throw new TypeSafeException('No API key was provided. Pass apiKey or set the TYPESAFE_API_KEY environment variable.');
        $this->baseUrl = rtrim(self::firstNonBlank($baseUrl, self::env('TYPESAFE_BASE_URL')) ?? self::DEFAULT_BASE_URL, '/');
        $this->defaultModel = self::firstNonBlank($model, self::env('TYPESAFE_DEFAULT_MODEL')) ?? self::DEFAULT_MODEL;
        $timeout = $timeout === null ? 10.0 : (float) $timeout;
        if (! is_finite($timeout) || $timeout <= 0) {
            throw new TypeSafeException('timeout must be a positive, finite number of seconds.');
        }
        $this->timeout = $timeout;
        $this->headers = $headers;
        $this->retry = (new RetryPolicy)->merge($retry ?? []);
        $this->http = $http ?? new Factory;
        $this->logger = new RequestLogger($logChannel, self::firstNonBlank($logLevel, self::env('TYPESAFE_LOG_LEVEL')) ?? 'warn');
        $this->decoder = new ResponseDecoder($this->logger);
        $this->models = new Models($this);
    }

    /**
     * @param  RetryPolicy|array<string, mixed>|null  $retry
     *                                                        Ask System One one or more questions about the given state.
     * @param  string|array<mixed>  $state
     * @param  array<string, mixed>  $questions
     * @param  array<string, string>  $extraHeaders
     * @param  array<string, mixed>  $extraBody
     * @param  class-string|null  $responseModel
     */
    public function systemOne(
        string|array $state,
        array $questions,
        ?string $model = null,
        RetryPolicy|array|null $retry = null,
        ?float $timeout = null,
        array $extraHeaders = [],
        array $extraBody = [],
        ?string $responseModel = null,
    ): object {
        $spec = new PendingSystemOne($state, $questions, $model, $retry, $timeout, $extraHeaders, $extraBody, $responseModel);
        $body = $this->systemOneBody($spec);
        $url = $this->baseUrl.self::SYSTEM_ONE_PATH;

        return $this->send('POST', $url, $body, $this->resolveTimeout($timeout), $this->buildHeaders($extraHeaders), $this->policy($retry),
            fn (Response $response): object => $this->decoder->systemOne($response, $responseModel));
    }

    /**
     * @param  RetryPolicy|array<string, mixed>|null  $retry
     * @param  array<string, string>  $extraHeaders
     */
    public function listModels(RetryPolicy|array|null $retry = null, ?float $timeout = null, array $extraHeaders = []): ModelsResponse
    {
        $url = $this->baseUrl.self::MODELS_PATH;

        return $this->send('GET', $url, null, $this->resolveTimeout($timeout), $this->buildHeaders($extraHeaders), $this->policy($retry),
            fn (Response $response): ModelsResponse => $this->decoder->models($response));
    }

    /**
     * Send many `systemOne` requests concurrently. Never throws per item: each keyed result is a
     * decoded response or a {@see TypeSafeException}.
     *
     * Keys come from a returned array (`fn (Pool $p) => ['a' => $p->systemOne(...)]`), else from `as()`, else positions.
     *
     * @param  Closure(Pool): mixed  $build
     * @return array<array-key, object|TypeSafeException>
     */
    public function pool(Closure $build): array
    {
        $pool = new Pool;
        $returned = $build($pool);
        // A returned keyed array (`['a' => $p->systemOne(...)]`) names the results; otherwise `as()` keys or positions do.
        $queued = is_array($returned) ? array_filter($returned, fn ($spec): bool => $spec instanceof PendingSystemOne) : [];
        if (is_array($returned) && $queued !== [] && count($queued) !== count($returned)) {
            throw new TypeSafeException('A pool closure returning requests must return only queued requests, keyed by result name.');
        }
        /** @var array<array-key, PendingSystemOne> $specs */
        $specs = $queued === [] ? $pool->specs() : $queued;

        $results = [];
        $pending = [];
        foreach ($specs as $key => $spec) {
            try {
                $pending[$key] = [
                    'json' => self::encodeBody($this->systemOneBody($spec)),
                    'headers' => $this->buildHeaders($spec->extraHeaders),
                    'timeout' => $this->resolveTimeout($spec->timeout),
                    'policy' => $this->policy($spec->retry),
                    'responseModel' => $spec->responseModel,
                    'attempt' => 0,
                    'start' => $this->now(),
                ];
            } catch (TypeSafeException $error) {
                $results[$key] = $error;
            }
        }

        $url = $this->baseUrl.self::SYSTEM_ONE_PATH;
        $endpoint = 'POST '.$url;
        while ($pending !== []) {
            $round = $this->http->pool(function (HttpPool $p) use ($pending, $url): array {
                $requests = [];
                foreach ($pending as $key => $state) {
                    $headers = $state['headers'];
                    if ($state['attempt'] > 0) {
                        $headers['X-TypeSafe-Retry-Count'] = (string) $state['attempt'];
                        $this->logger->info(sprintf('POST %s retry %d (pool %s)', $url, $state['attempt'], $key));
                    }
                    $this->logger->wire('POST', $url, '->', $headers, $state['json']);
                    $requests[] = $p->as((string) $key)->timeout($state['timeout'])->withHeaders($headers)
                        ->withBody($state['json'], 'application/json')->post($url);
                }

                return $requests;
            });

            $maxDelay = 0.0;
            $next = [];
            foreach ($pending as $key => $state) {
                $result = $round[(string) $key];
                if ($result instanceof Response) {
                    $this->logger->info(sprintf('POST %s <- %d (pool %s, request %s)', $url, $result->status(), $key,
                        ResponseDecoder::requestId($result) ?? '-'));
                    $this->logger->wire('POST', $url, '<-', RequestLogger::flatten($result), $result->body());
                } else {
                    $this->logger->info(sprintf('POST %s <- %s (pool %s)', $url, $result::class, $key));
                }
                if ($result instanceof Response && $result->successful()) {
                    try {
                        $results[$key] = $this->decoder->systemOne($result, $state['responseModel']);
                    } catch (ResponseValidationException $error) {
                        $results[$key] = $error;
                    }

                    continue;
                }
                if ($result instanceof Response) {
                    $error = $this->apiError($result, $endpoint);
                    $retryAfterMs = ApiException::parseRetryAfterMs($result->headers());
                } elseif ($result instanceof HttpConnectionException) {
                    $error = $this->connectionError($result, $state['timeout']);
                    $retryAfterMs = null;
                } else {
                    $error = new ApiConnectionException('Connection error.');
                    $retryAfterMs = null;
                }

                $delay = $state['policy']->delay($state['attempt'], $retryAfterMs, $this->random());
                if ($this->stopRetrying($state['policy'], $state['attempt'], $error, $state['start'], $delay)) {
                    $results[$key] = $error;

                    continue;
                }
                $state['attempt']++;
                $next[$key] = $state;
                $maxDelay = max($maxDelay, $delay);
            }

            $pending = $next;
            if ($pending !== [] && $maxDelay > 0) {
                $this->sleep($maxDelay);
            }
        }

        return $results;
    }

    /**
     * Run one request with retries and return the decoded response.
     *
     * @template T of object
     *
     * @param  array<string, mixed>|null  $body
     * @param  array<string, string>  $headers
     * @param  Closure(Response): T  $decode
     * @return T
     */
    private function send(string $method, string $url, ?array $body, float $timeout, array $headers, RetryPolicy $policy, Closure $decode): object
    {
        $endpoint = $method.' '.$url;
        $json = $body === null ? null : self::encodeBody($body);
        $start = $this->now();

        for ($attempt = 0; ; $attempt++) {
            $attemptHeaders = $headers;
            if ($attempt > 0) {
                $attemptHeaders['X-TypeSafe-Retry-Count'] = (string) $attempt;
                $this->logger->info(sprintf('%s %s retry %d', $method, $url, $attempt));
            }
            $this->logger->wire($method, $url, '->', $attemptHeaders, $json);
            $begun = $this->now();
            $retryAfterMs = null;
            try {
                $response = $this->dispatch($method, $url, $json, $timeout, $attemptHeaders);
            } catch (HttpConnectionException $exception) {
                $error = $this->connectionError($exception, $timeout);
                $response = null;
            }

            if (isset($response)) {
                $this->logger->info(sprintf('%s %s <- %d in %dms (request %s)', $method, $url, $response->status(),
                    (int) round(($this->now() - $begun) * 1000), ResponseDecoder::requestId($response) ?? '-'));
                $this->logger->wire($method, $url, '<-', RequestLogger::flatten($response), $response->body());
                if ($response->successful()) {
                    return $decode($response);
                }
                $error = $this->apiError($response, $endpoint);
                $retryAfterMs = ApiException::parseRetryAfterMs($response->headers());
            }

            $delay = $policy->delay($attempt, $retryAfterMs, $this->random());
            if ($this->stopRetrying($policy, $attempt, $error, $start, $delay)) {
                throw $error;
            }
            if ($delay > 0) {
                $this->sleep($delay);
            }
        }
    }

    /** The one retry decision both the single-request loop and the pool rounds ask. */
    private function stopRetrying(RetryPolicy $policy, int $attempt, TypeSafeException $error, float $start, float $delay): bool
    {
        return $attempt >= $policy->maxRetries || ! $policy->retryable($error) || $this->budgetExhausted($policy, $start, $delay);
    }

    private function budgetExhausted(RetryPolicy $policy, float $start, float $delay): bool
    {
        return $policy->timeout !== null && ($this->now() - $start) + $delay >= $policy->timeout;
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function dispatch(string $method, string $url, ?string $json, float $timeout, array $headers): Response
    {
        $request = $this->http->timeout($timeout)->withHeaders($headers);
        if ($json !== null) {
            $request = $request->withBody($json, 'application/json');
        }

        return $request->send($method, $url);
    }

    /**
     * Guzzle 8 raises dedicated timeout exceptions; Guzzle 7 reports cURL errno 28 in the handler context.
     * Class names are strings so this loads (and analyses) against either major version.
     */
    private static function timedOut(?\Throwable $previous): bool
    {
        foreach (['GuzzleHttp\\Exception\\ConnectTimeoutException', 'GuzzleHttp\\Exception\\NetworkTimeoutException'] as $class) {
            if ($previous !== null && is_a($previous, $class)) {
                return true;
            }
        }

        if (! $previous instanceof ConnectException || ! method_exists($previous, 'getHandlerContext')) {
            return false;
        }
        $context = $previous->getHandlerContext();

        return is_array($context) && ($context['errno'] ?? null) === self::CURL_TIMEOUT_ERRNO;
    }

    private function connectionError(HttpConnectionException $exception, float $timeout): ApiConnectionException
    {
        if (self::timedOut($exception->getPrevious())) {
            return new ApiTimeoutException($timeout, $exception);
        }

        return new ApiConnectionException('Connection error: '.$exception->getMessage(), 0, $exception);
    }

    private function apiError(Response $response, string $endpoint): ApiException
    {
        return ApiException::for($response->status(), ResponseDecoder::decodeBody($response), $response->headers(), $endpoint);
    }

    /**
     * @return array<string, mixed>
     */
    private function systemOneBody(PendingSystemOne $spec): array
    {
        $body = [
            'state' => $spec->state,
            'model' => $spec->model ?? $this->defaultModel,
            'questions' => $this->normalizeQuestions($spec->questions),
        ];

        return array_merge($body, $spec->extraBody);
    }

    /**
     * @param  array<string, mixed>  $questions
     * @return array<string, mixed>
     */
    private function normalizeQuestions(array $questions): array
    {
        if ($questions === []) {
            throw new TypeSafeException('At least one question is required.');
        }
        $normalized = [];
        foreach ($questions as $name => $question) {
            if (! is_string($name)) {
                throw new TypeSafeException('Question names must be strings.');
            }
            if ($question instanceof Question) {
                $normalized[$name] = $question->jsonSerialize();

                continue;
            }
            if (! is_array($question) || ! isset($question['type']) || ! is_string($question['type']) || $question['type'] === '') {
                throw new TypeSafeException(sprintf('Question "%s" must be a Question or an array with a nonempty string "type".', $name));
            }
            if (in_array($question['type'], ['choice', 'score'], true) && ! array_key_exists('criteria', $question)) {
                throw new TypeSafeException(sprintf('Question "%s" requires "criteria".', $name));
            }
            $criteria = $question['criteria'] ?? null;
            if ($question['type'] === 'score' && (! is_array($criteria) || $criteria === [])) {
                throw new TypeSafeException(sprintf('Score question "%s" has no criteria; at least one score is required.', $name));
            }
            if ($question['type'] === 'score' && is_array($criteria) && ! array_is_list($criteria)) {
                throw new TypeSafeException(sprintf('Score question "%s" must list its criteria in score order, not map them.', $name));
            }
            if ($question['type'] === 'choice' && (! is_array($criteria) || ($criteria !== [] && array_is_list($criteria)))) {
                throw new TypeSafeException(sprintf('Choice question "%s" must map labels to descriptions, not list them.', $name));
            }
            $normalized[$name] = $question;
        }

        return $normalized;
    }

    /**
     * @param  array<string, string>  $extraHeaders
     * @return array<string, string>
     */
    private function buildHeaders(array $extraHeaders): array
    {
        $headers = self::mergeHeaders($this->headers, $extraHeaders);
        self::removeHeader($headers, 'X-TypeSafe-Retry-Count');

        return self::mergeHeaders($headers, [
            'Authorization' => 'Bearer '.$this->apiKey,
            'Accept' => 'application/json',
            'User-Agent' => 'typesafe-laravel-sdk/'.self::VERSION,
            'X-TypeSafe-SDK' => 'typesafe-laravel-sdk/'.self::VERSION,
            'X-TypeSafe-Runtime' => self::runtime(),
        ]);
    }

    private static function runtime(): string
    {
        $laravel = class_exists(Application::class)
            ? Application::VERSION
            : 'unknown';

        return sprintf('php/%s (%s; %s) laravel/%s', PHP_VERSION, php_uname('s'), php_uname('m'), $laravel);
    }

    /** @param  RetryPolicy|array<string, mixed>|null  $override */
    private function policy(RetryPolicy|array|null $override): RetryPolicy
    {
        return $override === null ? $this->retry : $this->retry->merge($override);
    }

    private function resolveTimeout(?float $timeout): float
    {
        if ($timeout === null) {
            return $this->timeout;
        }
        if (! is_finite($timeout) || $timeout <= 0) {
            throw new TypeSafeException('timeout must be a positive, finite number of seconds.');
        }

        return $timeout;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private static function encodeBody(array $body): string
    {
        try {
            return json_encode($body, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new TypeSafeException('The request body could not be encoded as JSON.', 0, $error);
        }
    }

    private function now(): float
    {
        return (float) Carbon::now()->format('U.u');
    }

    private function sleep(float $seconds): void
    {
        Sleep::for((int) round($seconds * 1000))->milliseconds();
    }

    private function random(): float
    {
        return mt_rand() / mt_getrandmax();
    }

    private static function firstNonBlank(?string ...$values): ?string
    {
        foreach ($values as $value) {
            if ($value !== null && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    private static function env(string $key): ?string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        return is_string($value) ? $value : null;
    }

    /**
     * @param  array<string, string>  ...$sources
     * @return array<string, string>
     */
    private static function mergeHeaders(array ...$sources): array
    {
        $merged = [];
        $names = [];
        foreach ($sources as $source) {
            foreach ($source as $name => $value) {
                $lower = strtolower($name);
                if (isset($names[$lower])) {
                    unset($merged[$names[$lower]]);
                }
                $merged[$name] = $value;
                $names[$lower] = $name;
            }
        }

        return $merged;
    }

    /**
     * @param  array<string, string>  $headers
     */
    private static function removeHeader(array &$headers, string $name): void
    {
        foreach (array_keys($headers) as $existing) {
            if (strcasecmp($existing, $name) === 0) {
                unset($headers[$existing]);
            }
        }
    }
}
