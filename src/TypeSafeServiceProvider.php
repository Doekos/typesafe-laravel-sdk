<?php

declare(strict_types=1);

namespace Doekos\TypeSafe;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\ServiceProvider;

final class TypeSafeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/typesafe.php', 'typesafe');

        $this->app->singleton(TypeSafeClient::class, function (Application $app): TypeSafeClient {
            $config = $app->make(Repository::class);
            $timeout = $config->get('typesafe.timeout');

            $headers = [];
            foreach ((array) $config->get('typesafe.headers', []) as $name => $value) {
                if (is_string($name) && is_string($value)) {
                    $headers[$name] = $value;
                }
            }

            return new TypeSafeClient(
                apiKey: self::asString($config->get('typesafe.api_key')),
                baseUrl: self::asString($config->get('typesafe.base_url')),
                model: self::asString($config->get('typesafe.default_model')),
                timeout: is_numeric($timeout) ? (float) $timeout : null,
                headers: $headers,
                logChannel: self::asString($config->get('typesafe.log.channel')),
                logLevel: self::asString($config->get('typesafe.log.level')),
                http: $app->make(Factory::class),
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/typesafe.php' => $this->app->configPath('typesafe.php'),
            ], 'typesafe-config');
        }
    }

    private static function asString(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
