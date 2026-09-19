<?php

declare(strict_types=1);

namespace Doekos\TypeSafe\Questions;

use Doekos\TypeSafe\Exceptions\TypeSafeException;

/** A question that selects between named alternatives. */
final readonly class Choice implements Question
{
    /**
     * @param  array<string, mixed>  $criteria  Labels mapped to descriptions (or `null` for undescribed labels).
     * @param  string|array<mixed>|null  $instructions
     */
    public function __construct(
        public array $criteria,
        public string|array|null $instructions = null,
    ) {
        if (array_is_list($criteria) && $criteria !== []) {
            throw new TypeSafeException('Choice criteria must map labels to descriptions, not list them.');
        }
    }

    /**
     * Instructions first, like the JS `choice(instructions, criteria)` builder.
     *
     * @param  string|array<mixed>|null  $instructions
     * @param  array<string, mixed>  $criteria
     */
    public static function make(string|array|null $instructions, array $criteria): self
    {
        return new self($criteria, $instructions);
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        $wire = ['type' => 'choice', 'criteria' => $this->criteria];
        if ($this->instructions !== null) {
            $wire['instructions'] = $this->instructions;
        }

        return $wire;
    }
}
