<?php

declare(strict_types=1);

namespace Doekos\TypeSafe\Questions;

use JsonSerializable;

/** A question about the supplied state. Serializes to its wire form, omitting unset optional fields. */
interface Question extends JsonSerializable
{
    /** @return array<string, mixed> */
    public function jsonSerialize(): array;
}
