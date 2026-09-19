<?php

declare(strict_types=1);

namespace Doekos\TypeSafe;

use Doekos\TypeSafe\Responses\ModelsResponse;

/** The `models` resource: lists the models available to the account. */
final readonly class Models
{
    public function __construct(private TypeSafeClient $client) {}

    /**
     * @param  RetryPolicy|array<string, mixed>|null  $retry
     * @param  array<string, string>  $extraHeaders
     */
    public function list(RetryPolicy|array|null $retry = null, ?float $timeout = null, array $extraHeaders = []): ModelsResponse
    {
        return $this->client->listModels($retry, $timeout, $extraHeaders);
    }
}
