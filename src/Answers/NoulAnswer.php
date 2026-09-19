<?php

declare(strict_types=1);

namespace Doekos\TypeSafe\Answers;

/** A yes/no answer: the probability of a yes/true, from 0 to 1. */
final readonly class NoulAnswer implements Answer
{
    public function __construct(public float $noul) {}
}
