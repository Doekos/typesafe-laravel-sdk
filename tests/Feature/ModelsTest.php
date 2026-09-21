<?php

declare(strict_types=1);

namespace Doekos\TypeSafe\Tests\Feature;

use Doekos\TypeSafe\Exceptions\ResponseValidationException;
use Doekos\TypeSafe\Responses\ModelsResponse;
use Doekos\TypeSafe\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

final class ModelsTest extends TestCase
{
    #[Test]
    public function it_lists_models_and_keeps_release_date_as_a_plain_string(): void
    {
        Http::fake(['api.typesafe.ai/*' => Http::response([
            'models' => [
                ['name' => 'jev-latest', 'description' => 'General-purpose.', 'release_date' => '2026-09-10T18:38:01.391457+00:00'],
                ['name' => 'jev-1.13.0', 'description' => 'Pinned.', 'release_date' => '2026-09-01T00:00:00+00:00'],
            ],
        ], 200, ['x-typesafe-request-id' => 'req_models'])]);

        $response = $this->client()->models->list();

        self::assertInstanceOf(ModelsResponse::class, $response);
        self::assertSame('req_models', $response->requestId);
        self::assertCount(2, $response->models);
        self::assertSame('jev-latest', $response->models[0]->name);
        self::assertSame('2026-09-10T18:38:01.391457+00:00', $response->models[0]->releaseDate);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://api.typesafe.ai/v1/models');
    }

    #[Test]
    public function it_forwards_extra_headers(): void
    {
        Http::fake(['api.typesafe.ai/*' => Http::response(['models' => []], 200)]);

        $this->client()->models->list(extraHeaders: ['X-Trace' => 'abc']);

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('X-Trace', 'abc')
            && $request->hasHeader('Authorization', 'Bearer test-key'));
    }

    #[Test]
    public function a_malformed_models_payload_raises_a_validation_error(): void
    {
        Http::fake(['api.typesafe.ai/*' => Http::response(['models' => [['name' => 'x', 'description' => 'y']]], 200)]);

        try {
            $this->client()->models->list();
            self::fail('Expected ResponseValidationException.');
        } catch (ResponseValidationException $error) {
            self::assertSame('models[0].release_date', $error->fieldPath);
        }
    }
}
