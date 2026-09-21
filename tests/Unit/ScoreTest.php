<?php

declare(strict_types=1);

namespace Doekos\TypeSafe\Tests\Unit;

use Doekos\TypeSafe\Exceptions\TypeSafeException;
use Doekos\TypeSafe\Questions\Score;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ScoreTest extends TestCase
{
    #[Test]
    public function mapped_criteria_are_rejected(): void
    {
        $this->expectException(TypeSafeException::class);

        // Callers without static analysis can still pass a map; the runtime guard is what this covers.
        // @phpstan-ignore argument.type
        Score::make('Rate urgency', [1 => 'low', 2 => 'high']);
    }

    #[Test]
    public function it_serializes_an_ordered_rubric(): void
    {
        $score = Score::make('Rate urgency', ['low', 'high']);

        self::assertSame(['type' => 'score', 'criteria' => ['low', 'high'], 'instructions' => 'Rate urgency'], $score->jsonSerialize());
    }

    #[Test]
    public function a_single_level_rubric_is_allowed(): void
    {
        self::assertSame(['type' => 'score', 'criteria' => ['only']], (new Score(['only']))->jsonSerialize());
    }

    #[Test]
    public function it_rejects_empty_criteria(): void
    {
        $this->expectException(TypeSafeException::class);
        $this->expectExceptionMessage('at least one score is required');

        new Score([]);
    }
}
