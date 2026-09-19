<?php

declare(strict_types=1);

namespace Doekos\TypeSafe\Facades;

use Doekos\TypeSafe\TypeSafeClient;
use Illuminate\Support\Facades\Facade;

/**
 * @method static object systemOne(string|array<mixed> $state, array<string, mixed> $questions, ?string $model = null, \Doekos\TypeSafe\RetryPolicy|array<string, mixed>|null $retry = null, ?float $timeout = null, array<string, string> $extraHeaders = [], array<string, mixed> $extraBody = [], ?string $responseModel = null)
 * @method static array<array-key, object|\Doekos\TypeSafe\Exceptions\TypeSafeException> pool(\Closure $build)
 * @method static \Doekos\TypeSafe\Responses\ModelsResponse listModels(\Doekos\TypeSafe\RetryPolicy|array<string, mixed>|null $retry = null, ?float $timeout = null, array<string, string> $extraHeaders = [])
 *
 * @see TypeSafeClient
 */
final class TypeSafe extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return TypeSafeClient::class;
    }
}
