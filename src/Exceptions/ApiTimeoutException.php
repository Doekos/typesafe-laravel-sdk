<?php

declare(strict_types=1);

namespace Doekos\TypeSafe\Exceptions;

use Throwable;

/** A request exceeded its configured timeout. */
class ApiTimeoutException extends ApiConnectionException
{
    public function __construct(public readonly float $timeout, ?Throwable $previous = null)
    {
        parent::__construct(sprintf('Request timed out (timeout=%s).', $this->timeout), 0, $previous);
    }
}
