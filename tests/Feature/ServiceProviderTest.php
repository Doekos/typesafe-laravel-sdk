<?php

declare(strict_types=1);

namespace Doekos\TypeSafe\Tests\Feature;

use Doekos\TypeSafe\Exceptions\TypeSafeException;
use Doekos\TypeSafe\Facades\TypeSafe;
use Doekos\TypeSafe\Questions\Noul;
use Doekos\TypeSafe\Responses\SystemOneResponse;
use Doekos\TypeSafe\Tests\TestCase;
use Doekos\TypeSafe\TypeSafeClient;
use Doekos\TypeSafe\TypeSafeServiceProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;
use PHPUnit\Framework\Attributes\Test;

final class ServiceProviderTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function okBody(string $model = 'jev-latest'): array
    {
        return ['model' => $model, 'answers' => [], 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]];
    }

    #[Test]
    public function unset_config_falls_back_to_the_sdk_defaults(): void
    {
        config(['typesafe.api_key' => 'test-key', 'typesafe.base_url' => null, 'typesafe.default_model' => null, 'typesafe.timeout' => null]);
        $this->container()->forgetInstance(TypeSafeClient::class);
        Http::fake(['api.typesafe.ai/*' => Http::response($this->okBody(), 200)]);

        $this->container()->make(TypeSafeClient::class)->systemOne('x', ['q' => Noul::make('ok?')]);

        Http::assertSent(function (Request $request): bool {
            self::assertSame('https://api.typesafe.ai/v1/systemone', $request->url());
            self::assertSame('jev-latest', $request->data()['model']);

            return true;
        });
    }

    #[Test]
    public function a_missing_api_key_throws_only_when_the_client_is_resolved(): void
    {
        config(['typesafe.api_key' => null]);
        $this->container()->forgetInstance(TypeSafeClient::class);

        $this->expectException(TypeSafeException::class);
        $this->container()->make(TypeSafeClient::class);
    }

    #[Test]
    public function config_values_configure_the_client(): void
    {
        config([
            'typesafe.api_key' => 'cfg-key',
            'typesafe.base_url' => 'https://example.test/',
            'typesafe.default_model' => 'cfg-model',
        ]);
        $this->container()->forgetInstance(TypeSafeClient::class);
        Http::fake(['example.test/*' => Http::response($this->okBody('cfg-model'), 200)]);

        $response = $this->container()->make(TypeSafeClient::class)->systemOne('x', ['q' => new Noul]);

        self::assertInstanceOf(SystemOneResponse::class, $response);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://example.test/v1/systemone'
            && $request->data()['model'] === 'cfg-model');
    }

    #[Test]
    public function the_facade_sends_requests(): void
    {
        config(['typesafe.api_key' => 'cfg-key']);
        $this->container()->forgetInstance(TypeSafeClient::class);
        Http::fake(['api.typesafe.ai/*' => Http::response($this->okBody(), 200, ['x-typesafe-request-id' => 'req_facade'])]);

        $response = TypeSafe::systemOne('x', ['q' => new Noul]);

        self::assertInstanceOf(SystemOneResponse::class, $response);
        self::assertSame('req_facade', $response->requestId);
    }

    #[Test]
    public function an_environment_variable_supplies_the_default_model_when_no_argument_is_given(): void
    {
        $_SERVER['TYPESAFE_DEFAULT_MODEL'] = 'env-model';
        try {
            Http::fake(['api.typesafe.ai/*' => Http::response($this->okBody('env-model'), 200)]);
            $this->client()->systemOne('x', ['q' => new Noul]);
            Http::assertSent(fn (Request $request): bool => $request->data()['model'] === 'env-model');
        } finally {
            unset($_SERVER['TYPESAFE_DEFAULT_MODEL']);
        }
    }

    #[Test]
    public function an_explicit_argument_overrides_the_environment(): void
    {
        $_SERVER['TYPESAFE_DEFAULT_MODEL'] = 'env-model';
        try {
            Http::fake(['api.typesafe.ai/*' => Http::response($this->okBody('arg-model'), 200)]);
            $this->client(model: 'arg-model')->systemOne('x', ['q' => new Noul]);
            Http::assertSent(fn (Request $request): bool => $request->data()['model'] === 'arg-model');
        } finally {
            unset($_SERVER['TYPESAFE_DEFAULT_MODEL']);
        }
    }

    #[Test]
    public function it_publishes_config_under_the_typesafe_config_tag(): void
    {
        $paths = ServiceProvider::pathsToPublish(TypeSafeServiceProvider::class, 'typesafe-config');

        self::assertNotEmpty($paths);
        $target = array_values($paths)[0] ?? null;
        self::assertIsString($target);
        self::assertStringEndsWith('typesafe.php', $target);
    }
}
