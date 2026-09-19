<?php

declare(strict_types=1);

namespace Doekos\TypeSafe\Responses;

use Illuminate\Http\Client\Response;

/** The models available to the account. */
final readonly class ModelsResponse
{
    /**
     * @param  list<ModelMetadata>  $models
     */
    public function __construct(
        public array $models,
        public ?string $requestId,
        public Response $rawResponse,
    ) {}
}
