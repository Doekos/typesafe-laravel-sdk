<?php

declare(strict_types=1);

namespace Doekos\TypeSafe\Tests\Feature;

use Doekos\TypeSafe\Answers\ChoiceAnswer;
use Doekos\TypeSafe\Answers\NoulAnswer;
use Doekos\TypeSafe\Exceptions\ResponseValidationException;
use Doekos\TypeSafe\Questions\Noul;
use Doekos\TypeSafe\Responses\Usage;
use Doekos\TypeSafe\Tests\TestCase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

final class Report
{
    public function __construct(
        public NoulAnswer $billing,
        public ChoiceAnswer $cat,
        public string $model,
        public Usage $usage,
        public ?string $requestId,
        public ?NoulAnswer $optional = null,
    ) {}
}

final class MismatchedReport
{
    public function __construct(public ChoiceAnswer $billing) {}
}

final class RequiresMissing
{
    public function __construct(public NoulAnswer $ghost) {}
}

/** A nullable parameter with no default is still required: a missing answer must not silently become null. */
final class NullableMissing
{
    public function __construct(public ?NoulAnswer $ghost) {}
}

final class ResponseModelTest extends TestCase
{
    private function fakeResult(): void
    {
        Http::fake(['api.typesafe.ai/*' => Http::response([
            'model' => 'jev-1.13.0',
            'answers' => [
                'billing' => ['type' => 'noul', 'noul' => 0.98],
                'cat' => ['type' => 'choice', 'choice' => 'billing', 'confidence' => 1.0, 'probabilities' => ['billing' => 1.0]],
            ],
            'usage' => ['input_tokens' => 3, 'output_tokens' => 4],
        ], 200, ['x-typesafe-request-id' => 'req_dto'])]);
    }

    #[Test]
    public function it_hydrates_a_dto_by_question_name_and_metadata(): void
    {
        $this->fakeResult();

        $report = $this->client()->systemOne('x', ['q' => new Noul], responseModel: Report::class);

        self::assertInstanceOf(Report::class, $report);
        self::assertSame(0.98, $report->billing->noul);
        self::assertSame('billing', $report->cat->choice);
        self::assertSame('jev-1.13.0', $report->model);
        self::assertSame(3, $report->usage->inputTokens);
        self::assertSame('req_dto', $report->requestId);
        self::assertNull($report->optional);
    }

    #[Test]
    public function a_type_mismatch_raises_a_validation_error(): void
    {
        $this->fakeResult();

        try {
            $this->client()->systemOne('x', ['q' => new Noul], responseModel: MismatchedReport::class);
            self::fail('Expected ResponseValidationException.');
        } catch (ResponseValidationException $error) {
            self::assertSame('answers.billing', $error->fieldPath);
        }
    }

    #[Test]
    public function a_missing_required_answer_raises_a_validation_error(): void
    {
        $this->fakeResult();

        try {
            $this->client()->systemOne('x', ['q' => new Noul], responseModel: RequiresMissing::class);
            self::fail('Expected ResponseValidationException.');
        } catch (ResponseValidationException $error) {
            self::assertSame('answers.ghost', $error->fieldPath);
        }
    }

    #[Test]
    public function a_nullable_parameter_without_a_default_is_still_required(): void
    {
        $this->fakeResult();

        try {
            $this->client()->systemOne('x', ['q' => new Noul], responseModel: NullableMissing::class);
            self::fail('Expected ResponseValidationException.');
        } catch (ResponseValidationException $error) {
            self::assertSame('answers.ghost', $error->fieldPath);
        }
    }
}
