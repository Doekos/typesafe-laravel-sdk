<?php

declare(strict_types=1);

namespace Doekos\TypeSafe;

/** Collects `systemOne` requests to send concurrently, keyed by `as()` or by array position. */
final class Pool
{
    /** @var array<array-key, PendingSystemOne> */
    private array $specs = [];

    private ?string $pendingKey = null;

    /** Key the next queued request. */
    public function as(string $key): self
    {
        $this->pendingKey = $key;

        return $this;
    }

    /**
     * Queue a `systemOne` request. Same arguments as {@see TypeSafeClient::systemOne()}.
     *
     * @param  RetryPolicy|array<string, mixed>|null  $retry
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
    ): PendingSystemOne {
        $key = $this->pendingKey ?? count($this->specs);
        $this->specs[$key] = new PendingSystemOne($state, $questions, $model, $retry, $timeout, $extraHeaders, $extraBody, $responseModel);
        $this->pendingKey = null;

        return $this->specs[$key];
    }

    /** @return array<array-key, PendingSystemOne> */
    public function specs(): array
    {
        return $this->specs;
    }
}
