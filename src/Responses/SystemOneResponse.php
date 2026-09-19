<?php

declare(strict_types=1);

namespace Doekos\TypeSafe\Responses;

use Doekos\TypeSafe\Answers\Answer;
use Doekos\TypeSafe\Answers\ChoiceAnswer;
use Doekos\TypeSafe\Answers\NoulAnswer;
use Doekos\TypeSafe\Answers\ScoreAnswer;
use Illuminate\Http\Client\Response;

/** Answers grouped by question type, with model and usage metadata. */
final readonly class SystemOneResponse
{
    /** @var array<string, NoulAnswer> Yes/no answers keyed by question name. */
    public array $nouls;

    /** @var array<string, ChoiceAnswer> Choice answers keyed by question name. */
    public array $choices;

    /** @var array<string, ScoreAnswer> Score answers keyed by question name. */
    public array $scores;

    /**
     * @param  array<string, Answer>  $answers
     */
    public function __construct(
        public string $model,
        public Usage $usage,
        public array $answers,
        public ?string $requestId,
        public Response $rawResponse,
    ) {
        $this->nouls = array_filter($answers, static fn (Answer $a): bool => $a instanceof NoulAnswer);
        $this->choices = array_filter($answers, static fn (Answer $a): bool => $a instanceof ChoiceAnswer);
        $this->scores = array_filter($answers, static fn (Answer $a): bool => $a instanceof ScoreAnswer);
    }

    public function noul(string $name): ?NoulAnswer
    {
        $answer = $this->answers[$name] ?? null;

        return $answer instanceof NoulAnswer ? $answer : null;
    }

    public function choice(string $name): ?ChoiceAnswer
    {
        $answer = $this->answers[$name] ?? null;

        return $answer instanceof ChoiceAnswer ? $answer : null;
    }

    public function score(string $name): ?ScoreAnswer
    {
        $answer = $this->answers[$name] ?? null;

        return $answer instanceof ScoreAnswer ? $answer : null;
    }
}
