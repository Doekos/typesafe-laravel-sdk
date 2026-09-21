<?php

declare(strict_types=1);

namespace Doekos\TypeSafe\Support;

use Doekos\TypeSafe\Answers\Answer;
use Doekos\TypeSafe\Answers\ChoiceAnswer;
use Doekos\TypeSafe\Answers\NoulAnswer;
use Doekos\TypeSafe\Answers\ScoreAnswer;
use Doekos\TypeSafe\Exceptions\ResponseValidationException;
use Doekos\TypeSafe\Responses\ModelMetadata;
use Doekos\TypeSafe\Responses\ModelsResponse;
use Doekos\TypeSafe\Responses\SystemOneResponse;
use Doekos\TypeSafe\Responses\Usage;
use Illuminate\Http\Client\Response;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Turns a successful HTTP response into typed SDK objects, raising {@see ResponseValidationException}
 * with a dotted field path for the first missing or structurally invalid field.
 *
 * @internal
 */
final class ResponseDecoder
{
    public function __construct(private readonly RequestLogger $logger) {}

    /**
     * @param  class-string|null  $responseModel
     */
    public function systemOne(Response $response, ?string $responseModel): object
    {
        $data = $this->object($response);
        $answers = $this->answers($data, $response);
        if ($responseModel !== null) {
            return $this->hydrate($responseModel, $data, $answers, $response);
        }

        return new SystemOneResponse(
            $this->requiredString($data, 'model', 'model', $response),
            $this->usage($data, $response),
            $answers,
            self::requestId($response),
            $response,
        );
    }

    public function models(Response $response): ModelsResponse
    {
        $data = $this->object($response);
        if (! isset($data['models']) || ! is_array($data['models']) || ! array_is_list($data['models'])) {
            throw $this->error($response, 'models');
        }
        $models = [];
        foreach ($data['models'] as $index => $raw) {
            if (! is_array($raw)) {
                throw $this->error($response, sprintf('models[%d]', $index));
            }
            $models[] = new ModelMetadata(
                $this->requiredString($raw, 'name', sprintf('models[%d].name', $index), $response),
                $this->requiredString($raw, 'description', sprintf('models[%d].description', $index), $response),
                $this->requiredString($raw, 'release_date', sprintf('models[%d].release_date', $index), $response),
            );
        }

        return new ModelsResponse($models, self::requestId($response), $response);
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<string, Answer>
     */
    private function answers(array $data, Response $response): array
    {
        if (! array_key_exists('answers', $data)) {
            return [];
        }
        if (! is_array($data['answers'])) {
            throw $this->error($response, 'answers');
        }
        $answers = [];
        foreach ($data['answers'] as $key => $raw) {
            $name = (string) $key;
            if (! is_array($raw) || ! isset($raw['type']) || ! is_string($raw['type'])) {
                throw $this->error($response, sprintf('answers.%s.type', $name));
            }
            if (! in_array($raw['type'], ['noul', 'choice', 'score'], true)) {
                $this->logger->warn(sprintf('Ignoring answer "%s" with unrecognized type "%s"', $name, $raw['type']));

                continue;
            }
            $answers[$name] = $this->answer($name, $raw['type'], $raw, $response);
        }

        return $answers;
    }

    /**
     * @param  array<array-key, mixed>  $raw
     */
    private function answer(string $name, string $type, array $raw, Response $response): Answer
    {
        $path = fn (string $field): string => sprintf('answers.%s.%s', $name, $field);

        return match ($type) {
            'noul' => new NoulAnswer($this->requiredFloat($raw, 'noul', $path('noul'), $response)),
            'choice' => new ChoiceAnswer(
                $this->requiredString($raw, 'choice', $path('choice'), $response),
                $this->requiredFloat($raw, 'confidence', $path('confidence'), $response),
                $this->stringFloatMap($this->requiredMap($raw, 'probabilities', $path('probabilities'), $response), $path('probabilities'), $response),
            ),
            default => new ScoreAnswer(
                $this->requiredFloat($raw, 'score', $path('score'), $response),
                $this->requiredFloat($raw, 'confidence', $path('confidence'), $response),
                $this->intKeyMap($this->requiredMap($raw, 'legend', $path('legend'), $response), $path('legend'), $response),
                $this->intFloatMap($this->requiredMap($raw, 'probabilities', $path('probabilities'), $response), $path('probabilities'), $response),
            ),
        };
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function usage(array $data, Response $response): Usage
    {
        if (! isset($data['usage']) || ! is_array($data['usage'])) {
            throw $this->error($response, 'usage');
        }

        return new Usage(
            $this->optionalInt($data['usage'], 'input_tokens', 'usage.input_tokens', $response),
            $this->optionalInt($data['usage'], 'output_tokens', 'usage.output_tokens', $response),
        );
    }

    /**
     * Hydrate a user DTO: constructor params matched by question name to typed answers, plus
     * `model`, `usage`, and `requestId` when declared. A missing required param, or an answer whose
     * type does not match the declared parameter type, raises a validation error.
     *
     * @param  class-string  $class
     * @param  array<array-key, mixed>  $data
     * @param  array<string, Answer>  $answers
     */
    private function hydrate(string $class, array $data, array $answers, Response $response): object
    {
        $constructor = (new ReflectionClass($class))->getConstructor();
        $arguments = [];
        foreach ($constructor?->getParameters() ?? [] as $parameter) {
            $name = $parameter->getName();
            if (in_array($name, ['model', 'usage', 'requestId'], true)) {
                $arguments[] = match ($name) {
                    'model' => $this->requiredString($data, 'model', 'model', $response),
                    'usage' => $this->usage($data, $response),
                    default => self::requestId($response),
                };

                continue;
            }
            if (! array_key_exists($name, $answers)) {
                if (! $parameter->isDefaultValueAvailable()) {
                    throw $this->error($response, 'answers.'.$name);
                }
                $arguments[] = $parameter->getDefaultValue();

                continue;
            }
            $answer = $answers[$name];
            $type = $parameter->getType();
            if ($type instanceof ReflectionNamedType && ! $type->isBuiltin() && ! $answer instanceof ($type->getName())) {
                throw $this->error($response, 'answers.'.$name);
            }
            $arguments[] = $answer;
        }

        return new $class(...$arguments);
    }

    // --- Primitive helpers ------------------------------------------------

    /**
     * @return array<array-key, mixed>
     */
    private function object(Response $response): array
    {
        $body = self::decodeBody($response);
        if (! is_array($body)) {
            throw $this->error($response, '');
        }

        return $body;
    }

    public static function decodeBody(Response $response): mixed
    {
        $body = $response->body();
        if ($body === '') {
            return null;
        }
        try {
            return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $body;
        }
    }

    public static function requestId(Response $response): ?string
    {
        $value = $response->header('x-typesafe-request-id');

        return $value === '' ? null : $value;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function requiredString(array $data, string $key, string $path, Response $response): string
    {
        $value = $data[$key] ?? null;
        if (! is_string($value)) {
            throw $this->error($response, $path);
        }

        return $value;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function requiredFloat(array $data, string $key, string $path, Response $response): float
    {
        $value = $data[$key] ?? null;
        if (! is_int($value) && ! is_float($value)) {
            throw $this->error($response, $path);
        }

        return (float) $value;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private function requiredMap(array $data, string $key, string $path, Response $response): array
    {
        $value = $data[$key] ?? null;
        if (! is_array($value)) {
            throw $this->error($response, $path);
        }

        return $value;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function optionalInt(array $data, string $key, string $path, Response $response): ?int
    {
        if (! array_key_exists($key, $data) || $data[$key] === null) {
            return null;
        }
        if (! is_int($data[$key])) {
            throw $this->error($response, $path);
        }

        return $data[$key];
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<string, float>
     */
    private function stringFloatMap(array $values, string $path, Response $response): array
    {
        $result = [];
        foreach ($values as $key => $value) {
            if (! is_int($value) && ! is_float($value)) {
                throw $this->error($response, $path);
            }
            $result[(string) $key] = (float) $value;
        }

        return $result;
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<int, mixed>
     */
    private function intKeyMap(array $values, string $path, Response $response): array
    {
        $result = [];
        foreach ($values as $key => $value) {
            if (filter_var($key, FILTER_VALIDATE_INT) === false) {
                throw $this->error($response, $path);
            }
            $result[(int) $key] = $value;
        }

        return $result;
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<int, float>
     */
    private function intFloatMap(array $values, string $path, Response $response): array
    {
        $result = [];
        foreach ($this->intKeyMap($values, $path, $response) as $key => $value) {
            if (! is_int($value) && ! is_float($value)) {
                throw $this->error($response, $path);
            }
            $result[$key] = (float) $value;
        }

        return $result;
    }

    private function error(Response $response, string $path): ResponseValidationException
    {
        return new ResponseValidationException($response->status(), self::decodeBody($response), $response->headers(), $path);
    }
}
