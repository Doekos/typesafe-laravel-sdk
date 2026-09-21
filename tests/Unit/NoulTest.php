<?php

declare(strict_types=1);

namespace Doekos\TypeSafe\Tests\Unit;

use Doekos\TypeSafe\Questions\Noul;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NoulTest extends TestCase
{
    #[Test]
    public function it_serializes_only_its_type_when_empty(): void
    {
        self::assertSame(['type' => 'noul'], (new Noul)->jsonSerialize());
    }

    #[Test]
    public function it_serializes_instructions_and_criteria(): void
    {
        $noul = Noul::make('Is this spam?', true: 'ad', false: 'legit');

        self::assertSame([
            'type' => 'noul',
            'instructions' => 'Is this spam?',
            'criteria' => ['true' => 'ad', 'false' => 'legit'],
        ], $noul->jsonSerialize());
    }

    #[Test]
    public function make_describes_only_the_outcomes_given(): void
    {
        self::assertSame(
            ['type' => 'noul', 'instructions' => 'Urgent?', 'criteria' => ['true' => 'needs action today']],
            Noul::make('Urgent?', true: 'needs action today')->jsonSerialize(),
        );
        self::assertSame(['type' => 'noul', 'instructions' => 'Urgent?'], Noul::make('Urgent?')->jsonSerialize());
    }

    #[Test]
    public function it_preserves_nested_nulls_inside_criteria(): void
    {
        $noul = new Noul(instructions: null, criteria: ['true' => null]);

        self::assertSame(['type' => 'noul', 'criteria' => ['true' => null]], $noul->jsonSerialize());
    }
}
