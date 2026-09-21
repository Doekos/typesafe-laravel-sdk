<?php

declare(strict_types=1);

namespace Doekos\TypeSafe\Questions;

/** A yes/no question with optional descriptions for either outcome. */
final readonly class Noul implements Question
{
    /**
     * @param  string|array<mixed>|null  $instructions
     * @param  array<string, mixed>|null  $criteria  Optional `true`/`false` outcome descriptions.
     */
    public function __construct(
        public string|array|null $instructions = null,
        public ?array $criteria = null,
    ) {}

    /**
     * Instructions first, like the JS `noul(instructions, criteria)` builder; `true:`/`false:` describe the outcomes.
     *
     * @param  string|array<mixed>|null  $instructions
     * @param  string|array<mixed>|null  $true
     * @param  string|array<mixed>|null  $false
     */
    public static function make(string|array|null $instructions = null, string|array|null $true = null, string|array|null $false = null): self
    {
        $criteria = array_filter(['true' => $true, 'false' => $false], fn ($description) => $description !== null);

        return new self($instructions, $criteria === [] ? null : $criteria);
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return array_filter(
            ['type' => 'noul', 'instructions' => $this->instructions, 'criteria' => $this->criteria],
            static fn ($value): bool => $value !== null,
        );
    }
}
