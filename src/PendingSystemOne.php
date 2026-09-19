<?php

declare(strict_types=1);

namespace Doekos\TypeSafe;

/** A single `systemOne` request queued inside a {@see Pool}. */
final readonly class PendingSystemOne
{
    /**
     * @param  RetryPolicy|array<string, mixed>|null  $retry
     * @param  string|array<mixed>  $state
     * @param  array<string, mixed>  $questions
     * @param  array<string, string>  $extraHeaders
     * @param  array<string, mixed>  $extraBody
     * @param  class-string|null  $responseModel
     */
    public function __construct(
        public string|array $state,
        public array $questions,
        public ?string $model = null,
        public RetryPolicy|array|null $retry = null,
        public ?float $timeout = null,
        public array $extraHeaders = [],
        public array $extraBody = [],
        public ?string $responseModel = null,
    ) {}
}
