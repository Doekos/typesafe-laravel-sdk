<?php

declare(strict_types=1);

namespace Doekos\TypeSafe\Responses;

/** Token counts for a request, when reported by the API. */
final readonly class Usage
{
    public function __construct(
        public ?int $inputTokens = null,
        public ?int $outputTokens = null,
    ) {}
}
