<?php

declare(strict_types=1);

namespace Doekos\TypeSafe\Questions;

use Doekos\TypeSafe\Exceptions\TypeSafeException;

/** A question that assigns a score using an ordered rubric. */
final readonly class Score implements Question
{
    /**
     * @param  list<mixed>  $criteria  A non-empty, ordered list of score-level descriptions, one per score from zero.
     * @param  string|array<mixed>|null  $instructions
     */
    public function __construct(
        public array $criteria,
        public string|array|null $instructions = null,
    ) {
        if ($criteria === []) {
            throw new TypeSafeException('Score question has no criteria; at least one score is required.');
        }
        if (! array_is_list($criteria)) {
            throw new TypeSafeException('Score criteria must be listed in score order from zero, not mapped.');
        }
    }

    /**
     * Instructions first, like the JS `score(instructions, criteria)` builder.
     *
     * @param  string|array<mixed>|null  $instructions
     * @param  list<mixed>  $criteria
     */
    public static function make(string|array|null $instructions, array $criteria): self
    {
        return new self($criteria, $instructions);
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        $wire = ['type' => 'score', 'criteria' => $this->criteria];
        if ($this->instructions !== null) {
            $wire['instructions'] = $this->instructions;
        }

        return $wire;
    }
}
