<?php

declare(strict_types=1);

namespace Doekos\TypeSafe\Exceptions;

/** A successful HTTP response whose body was missing or structurally invalid required data. */
class ResponseValidationException extends ApiException
{
    /**
     * @param  array<mixed>  $headers
     */
    public function __construct(
        int $status,
        mixed $body,
        array $headers,
        public readonly string $fieldPath,
        ?string $endpoint = null,
    ) {
        parent::__construct($status, $body, $headers, $endpoint, sprintf('Invalid response data at %s.', var_export($fieldPath, true)));
    }
}
