<?php

declare(strict_types=1);

namespace Doekos\TypeSafe\Responses;

/** Metadata describing a single available model. */
final readonly class ModelMetadata
{
    public function __construct(
        public string $name,
        public string $description,
        public string $releaseDate,
    ) {}
}
