<?php

declare(strict_types=1);

namespace Doekos\TypeSafe\Tests\Feature;

use Carbon\CarbonInterval;
use Doekos\TypeSafe\Answers\ChoiceAnswer;
use Doekos\TypeSafe\Answers\NoulAnswer;
use Doekos\TypeSafe\Answers\ScoreAnswer;
use Doekos\TypeSafe\Exceptions\ApiTimeoutException;
use Doekos\TypeSafe\Exceptions\AuthenticationException;
use Doekos\TypeSafe\Exceptions\ResponseValidationException;
use Doekos\TypeSafe\Exceptions\TypeSafeException;
use Doekos\TypeSafe\Exceptions\UnprocessableEntityException;
use Doekos\TypeSafe\Questions\Noul;
use Doekos\TypeSafe\Responses\SystemOneResponse;
use Doekos\TypeSafe\RetryPolicy;
use Doekos\TypeSafe\Tests\TestCase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\Test;

final class SystemOneTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function fixture(): array
    {
        return [
            'model' => 'jev-1.13.0',
            'answers' => [
                'billing' => ['type' => 'noul', 'noul' => 0.98],
                'cat' => ['type' => 'choice', 'choice' => 'billing', 'confidence' => 1.0, 'probabilities' => ['technical' => 0.0, 'billing' => 1.0]],
                'anger' => ['type' => 'score', 'score' => 1.1, 'confidence' => 0.84, 'legend' => ['0' => 'calm', '1' => 'annoyed', '2' => 'furious'], 'probabilities' => ['0' => 0.0, '1' => 0.9, '2' => 0.1]],
            ],
            'usage' => ['input_tokens' => 374, 'output_tokens' => 77],
        ];
    }

    private function fakeOk(): void
    {
        Http::fake(['api.typesafe.ai/*' => Http::response($this->fixture(), 200, ['x-typesafe-request-id' => 'req_ok'])]);
    }

    private function ask(?RetryPolicy $retry = null): SystemOneResponse
    {
        $response = $this->client()->systemOne('a message', ['billing' => new Noul('billing?')], retry: $retry);
        self::assertInstanceOf(SystemOneResponse::class, $response);

        return $response;
    }

    #[Test]
    public function it_decodes_every_answer_type(): void
    {
        $this->fakeOk();
        $response = $this->ask();

        self::assertSame('jev-1.13.0', $response->model);
        self::assertSame('req_ok', $response->requestId);
        self::assertSame(374, $response->usage->inputTokens);

        self::assertInstanceOf(NoulAnswer::class, $response->noul('billing'));
        self::assertSame(0.98, $response->noul('billing')->noul);

        $choice = $response->choice('cat');
        self::assertInstanceOf(ChoiceAnswer::class, $choice);
        self::assertSame('billing', $choice->choice);
        self::assertSame(['technical' => 0.0, 'billing' => 1.0], $choice->probabilities);

        $score = $response->score('anger');
        self::assertInstanceOf(ScoreAnswer::class, $score);
        self::assertSame([0 => 'calm', 1 => 'annoyed', 2 => 'furious'], $score->legend);
        self::assertSame([0 => 0.0, 1 => 0.9, 2 => 0.1], $score->probabilities);

        self::assertCount(1, $response->nouls);
        self::assertCount(1, $response->choices);
        self::assertCount(1, $response->scores);
    }

    #[Test]
    public function it_sends_the_wire_body_with_extra_body_merged_last(): void
    {
        $this->fakeOk();
        $this->client()->systemOne(
            state: ['subject' => 'hi'],
            questions: ['billing' => new Noul('billing?'), 'raw' => ['type' => 'bounding_box']],
            model: 'jev-custom',
            extraBody: ['temperature' => 0.2, 'model' => 'overridden'],
        );

        Http::assertSent(function (Request $request): bool {
            $body = $request->data();

            return data_get($body, 'state') === ['subject' => 'hi']
                && data_get($body, 'model') === 'overridden'
                && data_get($body, 'temperature') === 0.2
                && data_get($body, 'questions.billing') === ['type' => 'noul', 'instructions' => 'billing?']
                && data_get($body, 'questions.raw') === ['type' => 'bounding_box'];
        });
    }

    #[Test]
    public function it_forces_headers_and_strips_user_retry_count(): void
    {
        $this->fakeOk();
        $this->client(headers: ['X-Client' => 'yes'])->systemOne('x', ['q' => new Noul], extraHeaders: [
            'X-Extra' => 'call',
            'x-typesafe-retry-count' => '99',
        ]);

        Http::assertSent(function (Request $request): bool {
            return $request->hasHeader('Authorization', 'Bearer test-key')
                && $request->hasHeader('Accept', 'application/json')
                && $request->hasHeader('User-Agent', 'typesafe-laravel-sdk/0.7.0')
                && $request->hasHeader('X-TypeSafe-SDK', 'typesafe-laravel-sdk/0.7.0')
                && $request->hasHeader('X-TypeSafe-Runtime')
                && $request->hasHeader('X-Client', 'yes')
                && $request->hasHeader('X-Extra', 'call')
                && ! $request->hasHeader('X-TypeSafe-Retry-Count');
        });

        $first = $this->sentRequests()[0] ?? null;
        self::assertNotNull($first);
        $runtime = $first->header('X-TypeSafe-Runtime')[0] ?? null;
        self::assertIsString($runtime);
        self::assertStringStartsWith('php/', $runtime);
    }

    #[Test]
    public function it_retries_and_sets_the_retry_count_header(): void
    {
        Sleep::fake();
        Http::fake(['api.typesafe.ai/*' => Http::sequence()
            ->pushStatus(500)
            ->push($this->fixture(), 200, ['x-typesafe-request-id' => 'req_ok'])]);

        $this->ask(retry: new RetryPolicy(backoffInitial: 0.01, backoffJitter: 0.0));

        Http::assertSentCount(2);
        $requests = $this->sentRequests();
        $first = $requests[0] ?? null;
        $second = $requests[1] ?? null;
        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertFalse($first->hasHeader('X-TypeSafe-Retry-Count'));
        self::assertTrue($second->hasHeader('X-TypeSafe-Retry-Count', '1'));
    }

    #[Test]
    public function it_retries_status_408_429_and_5xx(): void
    {
        Sleep::fake();
        foreach ([408, 429, 503] as $status) {
            $http = new Factory;
            $http->fake(['api.typesafe.ai/*' => $http->sequence()->pushStatus($status)->push($this->fixture(), 200)]);

            $this->client(http: $http)->systemOne('x', ['q' => new Noul], retry: new RetryPolicy(backoffInitial: 0.0));
            $http->assertSentCount(2);
        }
    }

    #[Test]
    public function it_retries_connection_and_timeout_errors(): void
    {
        Sleep::fake();
        $attempts = 0;
        Http::fake(['api.typesafe.ai/*' => function () use (&$attempts) {
            $attempts++;
            if ($attempts === 1) {
                throw $this->connectionException();
            }
            if ($attempts === 2) {
                throw $this->timeoutException();
            }

            return Http::response($this->fixture(), 200);
        }]);

        $this->ask(retry: new RetryPolicy(backoffInitial: 0.0, maxRetries: 3));
        self::assertSame(3, $attempts);
    }

    #[Test]
    public function a_timeout_surfaces_as_an_api_timeout_exception(): void
    {
        Http::fake(['api.typesafe.ai/*' => fn () => throw $this->timeoutException()]);

        try {
            $this->client()->systemOne('x', ['q' => new Noul], retry: new RetryPolicy(maxRetries: 0));
            self::fail('Expected ApiTimeoutException.');
        } catch (ApiTimeoutException $error) {
            self::assertSame(10.0, $error->timeout);
        }
    }

    #[Test]
    public function retry_after_header_controls_the_delay(): void
    {
        Sleep::fake();
        Http::fake(['api.typesafe.ai/*' => Http::sequence()
            ->push(['message' => 'slow'], 429, ['retry-after-ms' => '250'])
            ->push($this->fixture(), 200)]);

        $this->ask(retry: new RetryPolicy(backoffInitial: 5.0, backoffJitter: 0.0));

        Sleep::assertSlept(fn (CarbonInterval $duration): bool => (int) round($duration->totalMilliseconds) === 250);
    }

    #[Test]
    public function it_stops_before_a_retry_that_would_exhaust_the_budget(): void
    {
        Sleep::fake();
        Carbon::setTestNow(Carbon::now());
        $attempts = 0;
        Http::fake(['api.typesafe.ai/*' => function () use (&$attempts) {
            $attempts++;
            Carbon::setTestNow(Carbon::now()->addSecond()); // each attempt "takes" 1s

            return Http::response(['message' => 'busy'], 429, ['retry-after-ms' => '0']);
        }]);

        $this->expectException(TypeSafeException::class);
        try {
            $this->client()->systemOne('x', ['q' => new Noul], retry: new RetryPolicy(maxRetries: 10, timeout: 2.0));
        } finally {
            self::assertSame(2, $attempts, 'budget stops the loop after two 1-second attempts');
            Carbon::setTestNow();
        }
    }

    #[Test]
    public function per_call_retry_can_disable_retries(): void
    {
        Http::fake(['api.typesafe.ai/*' => Http::response(['detail' => 'nope'], 500)]);

        try {
            $this->client(retry: new RetryPolicy(maxRetries: 5))
                ->systemOne('x', ['q' => new Noul], retry: new RetryPolicy(maxRetries: 0));
            self::fail('Expected an exception.');
        } catch (TypeSafeException) {
            Http::assertSentCount(1);
        }
    }

    #[Test]
    public function per_call_retry_array_merges_over_the_client_policy(): void
    {
        Http::fake(['api.typesafe.ai/*' => Http::response(['detail' => 'nope'], 500)]);

        try {
            // The client allows 3 retries on 429 only; the array override keeps that status set.
            $this->client(retry: new RetryPolicy(maxRetries: 3, httpStatuses: [429]))
                ->systemOne('x', ['q' => new Noul], retry: ['backoffInitial' => 0.0]);
            self::fail('Expected an exception.');
        } catch (TypeSafeException) {
            Http::assertSentCount(1);
        }
    }

    #[Test]
    public function raw_questions_with_misshaped_criteria_are_rejected_before_sending(): void
    {
        Http::fake();

        foreach ([
            ['type' => 'score', 'criteria' => ['low' => 'a', 'high' => 'b']],
            ['type' => 'choice', 'criteria' => ['a', 'b']],
        ] as $question) {
            try {
                $this->client()->systemOne('x', ['q' => $question]);
                self::fail('Expected a validation error for '.json_encode($question));
            } catch (TypeSafeException) {
                self::addToAssertionCount(1);
            }
        }

        Http::assertNothingSent();
    }

    #[Test]
    public function it_maps_error_statuses_to_exception_classes(): void
    {
        Http::fake(['api.typesafe.ai/*' => Http::response(
            ['detail' => ['error_type' => 'authentication_error', 'message' => 'Cannot authenticate with the server. Please check your API key and try again.']],
            401,
            ['x-typesafe-request-id' => 'req_bad'],
        )]);

        try {
            $this->client()->systemOne('x', ['q' => new Noul]);
            self::fail('Expected AuthenticationException.');
        } catch (AuthenticationException $error) {
            self::assertSame(401, $error->status);
            self::assertSame('req_bad', $error->requestId());
            self::assertStringContainsString('Cannot authenticate with the server', $error->getMessage());
        }
    }

    #[Test]
    public function a_422_validation_body_renders_its_message(): void
    {
        Http::fake(['api.typesafe.ai/*' => Http::response(
            ['detail' => [['type' => 'too_short', 'loc' => ['body', 'questions'], 'msg' => 'Dictionary should have at least 1 item after validation, not 0']]],
            422,
        )]);

        try {
            $this->client()->systemOne('x', ['q' => new Noul]);
            self::fail('Expected UnprocessableEntityException.');
        } catch (UnprocessableEntityException $error) {
            self::assertStringContainsString('questions: Dictionary should have at least 1 item after validation, not 0', $error->getMessage());
        }
    }

    #[Test]
    public function it_drops_unknown_answer_types(): void
    {
        Http::fake(['api.typesafe.ai/*' => Http::response([
            'model' => 'jev-latest',
            'answers' => ['known' => ['type' => 'noul', 'noul' => 0.5], 'future' => ['type' => 'bounding_box', 'box' => [1, 2]]],
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ], 200)]);

        $response = $this->ask();
        self::assertArrayHasKey('known', $response->answers);
        self::assertArrayNotHasKey('future', $response->answers);
    }

    #[Test]
    public function a_missing_answer_field_raises_a_validation_error_with_a_field_path(): void
    {
        Http::fake(['api.typesafe.ai/*' => Http::response([
            'model' => 'jev-latest',
            'answers' => ['billing' => ['type' => 'noul']],
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ], 200, ['x-typesafe-request-id' => 'req_v'])]);

        try {
            $this->client()->systemOne('x', ['q' => new Noul]);
            self::fail('Expected ResponseValidationException.');
        } catch (ResponseValidationException $error) {
            self::assertSame('answers.billing.noul', $error->fieldPath);
            self::assertSame('req_v', $error->requestId());
        }
    }

    #[Test]
    public function a_response_validation_error_is_not_retried(): void
    {
        // A 200 body missing the required `model` field -> validation error, which must not be retried.
        Http::fake(['api.typesafe.ai/*' => Http::response(['answers' => [], 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]], 200)]);

        $this->expectException(ResponseValidationException::class);
        try {
            $this->client()->systemOne('x', ['q' => new Noul], retry: new RetryPolicy(maxRetries: 3));
        } finally {
            Http::assertSentCount(1);
        }
    }

    #[Test]
    public function client_validates_questions_before_sending(): void
    {
        Http::fake();

        $this->expectException(TypeSafeException::class);
        try {
            $this->client()->systemOne('x', []);
        } finally {
            Http::assertNothingSent();
        }
    }

    #[Test]
    public function raw_score_question_requires_non_empty_criteria(): void
    {
        Http::fake();

        try {
            $this->client()->systemOne('x', ['s' => ['type' => 'score', 'criteria' => []]]);
            self::fail('Expected TypeSafeException.');
        } catch (TypeSafeException $error) {
            self::assertStringContainsString('at least one score is required', $error->getMessage());
        }
    }
}
