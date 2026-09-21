<?php

declare(strict_types=1);

namespace Doekos\TypeSafe\Answers;

/** An expected score with its rubric and per-level probabilities, keyed by integer score. */
final readonly class ScoreAnswer implements Answer
{
    /**
     * @param  array<int, mixed>  $legend
     * @param  array<int, float>  $probabilities
     */
    public function __construct(
        public float $score,
        public float $confidence,
        public array $legend,
        public array $probabilities,
    ) {}
}
