<?php

declare(strict_types=1);

namespace Doekos\TypeSafe\Tests\Unit;

use Doekos\TypeSafe\Exceptions\ApiException;
use Doekos\TypeSafe\Exceptions\AuthenticationException;
use Doekos\TypeSafe\Exceptions\BadRequestException;
use Doekos\TypeSafe\Exceptions\ConflictException;
use Doekos\TypeSafe\Exceptions\InternalServerException;
use Doekos\TypeSafe\Exceptions\NotFoundException;
use Doekos\TypeSafe\Exceptions\PermissionDeniedException;
use Doekos\TypeSafe\Exceptions\RateLimitException;
use Doekos\TypeSafe\Exceptions\ResponseValidationException;
use Doekos\TypeSafe\Exceptions\UnprocessableEntityException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ApiExceptionTest extends TestCase
{
    /**
     * @param  class-string  $class
     */
    #[Test]
    #[DataProvider('statusClasses')]
    public function it_maps_status_codes_to_classes(int $status, string $class): void
    {
        self::assertInstanceOf($class, ApiException::for($status, null, []));
    }

    /**
     * @return array<array-key, array{int, class-string}>
     */
    public static function statusClasses(): array
    {
        return [
            '400' => [400, BadRequestException::class],
            '401' => [401, AuthenticationException::class],
            '403' => [403, PermissionDeniedException::class],
            '404' => [404, NotFoundException::class],
            '409' => [409, ConflictException::class],
            '422' => [422, UnprocessableEntityException::class],
            '429' => [429, RateLimitException::class],
            '500' => [500, InternalServerException::class],
            '503' => [503, InternalServerException::class],
            '418' => [418, ApiException::class],
        ];
    }

    #[Test]
    #[DataProvider('messages')]
    public function it_extracts_the_server_message(mixed $body, ?string $expected): void
    {
        self::assertSame($expected, ApiException::extractMessage($body));
    }

    /**
     * @return array<string, array{mixed, ?string}>
     */
    public static function messages(): array
    {
        return [
            'plain string' => ['boom', 'boom'],
            'empty string' => ['', null],
            'error string' => [['error' => 'nope'], 'nope'],
            'error object' => [['error' => ['message' => 'deep']], 'deep'],
            'message key' => [['message' => 'hi'], 'hi'],
            'detail string (400)' => [['detail' => 'Noul question must have criteria or instructions: a'], 'Noul question must have criteria or instructions: a'],
            'detail object (401)' => [['detail' => ['error_type' => 'authentication_error', 'message' => 'Cannot authenticate with the server. Please check your API key and try again.']], 'Cannot authenticate with the server. Please check your API key and try again.'],
            'detail object (400)' => [['detail' => ['error_type' => 'api_usage_error', 'message' => 'Unknown model: nope']], 'Unknown model: nope'],
            'detail list (422)' => [['detail' => [['type' => 'too_short', 'loc' => ['body', 'questions'], 'msg' => 'Dictionary should have at least 1 item after validation, not 0']]], 'questions: Dictionary should have at least 1 item after validation, not 0'],
            'unhandled' => [['other' => 1], null],
        ];
    }

    #[Test]
    public function the_message_carries_endpoint_status_and_request_id(): void
    {
        $error = ApiException::for(401, ['detail' => ['message' => 'bad key']], ['x-typesafe-request-id' => ['req_abc']], 'POST https://api.typesafe.ai/v1/systemone');

        self::assertSame('POST https://api.typesafe.ai/v1/systemone: 401 bad key (request_id=req_abc)', $error->getMessage());
        self::assertSame('req_abc', $error->requestId());
    }

    #[Test]
    public function an_empty_body_falls_back_to_a_no_body_message(): void
    {
        self::assertSame('500 status code (no body)', ApiException::for(500, null, [])->getMessage());
    }

    #[Test]
    public function a_long_unstructured_body_is_truncated(): void
    {
        $error = ApiException::for(500, ['unknown' => str_repeat('y', 300)], []);

        self::assertStringEndsWith('…', $error->getMessage());
        // 200 chars of the JSON encoding, then the ellipsis.
        self::assertSame(200, mb_strlen(mb_substr($error->getMessage(), strlen('500 '), -1)));
    }

    #[Test]
    public function rate_limit_reads_retry_after_headers(): void
    {
        self::assertSame(1500.0, (new RateLimitException(429, null, ['retry-after-ms' => ['1500']]))->retryAfterMs);
        self::assertSame(2000.0, (new RateLimitException(429, null, ['Retry-After' => ['2']]))->retryAfterMs);
        self::assertNull((new RateLimitException(429, null, []))->retryAfterMs);
        // A negative or unparseable retry-after-ms falls through to Retry-After, as in the official SDKs.
        self::assertSame(2000.0, (new RateLimitException(429, null, ['retry-after-ms' => ['-1'], 'Retry-After' => ['2']]))->retryAfterMs);
        self::assertSame(2000.0, (new RateLimitException(429, null, ['retry-after-ms' => ['soon'], 'Retry-After' => ['2']]))->retryAfterMs);
    }

    #[Test]
    public function response_validation_names_the_field_path(): void
    {
        $error = new ResponseValidationException(200, ['answers' => []], [], 'answers.tone.confidence');

        self::assertSame('answers.tone.confidence', $error->fieldPath);
        self::assertStringContainsString("Invalid response data at 'answers.tone.confidence'.", $error->getMessage());
    }
}
