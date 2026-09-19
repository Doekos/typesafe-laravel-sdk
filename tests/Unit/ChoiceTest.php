<?php

declare(strict_types=1);

namespace Doekos\TypeSafe\Tests\Unit;

use Doekos\TypeSafe\Exceptions\TypeSafeException;
use Doekos\TypeSafe\Questions\Choice;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ChoiceTest extends TestCase
{
    #[Test]
    public function it_serializes_criteria_map_without_instructions(): void
    {
        $choice = new Choice(criteria: ['angry' => 'hostile', 'calm' => null]);

        self::assertSame(['type' => 'choice', 'criteria' => ['angry' => 'hostile', 'calm' => null]], $choice->jsonSerialize());
    }

    #[Test]
    public function listed_criteria_are_rejected(): void
    {
        $this->expectException(TypeSafeException::class);

        // Callers without static analysis can still pass a list; the runtime guard is what this covers.
        // @phpstan-ignore argument.type
        Choice::make('Pick one', ['a', 'b']);
    }

    #[Test]
    public function it_includes_instructions_when_given(): void
    {
        $choice = Choice::make('Pick one', ['a' => 'x']);

        self::assertSame(['type' => 'choice', 'criteria' => ['a' => 'x'], 'instructions' => 'Pick one'], $choice->jsonSerialize());
    }
}
