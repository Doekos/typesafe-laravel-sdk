<?php

declare(strict_types=1);

namespace Doekos\TypeSafe\Answers;

/** A selected label with the probability of every choice. */
final readonly class ChoiceAnswer implements Answer
{
    /**
     * @param  array<string, float>  $probabilities
     */
    public function __construct(
        public string $choice,
        public float $confidence,
        public array $probabilities,
    ) {}
}
