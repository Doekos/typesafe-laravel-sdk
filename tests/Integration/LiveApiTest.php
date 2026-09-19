<?php

declare(strict_types=1);

namespace Doekos\TypeSafe\Tests\Integration;

use Doekos\TypeSafe\Answers\ChoiceAnswer;
use Doekos\TypeSafe\Answers\NoulAnswer;
use Doekos\TypeSafe\Answers\ScoreAnswer;
use Doekos\TypeSafe\Exceptions\ApiException;
use Doekos\TypeSafe\Questions\Choice;
use Doekos\TypeSafe\Questions\Noul;
use Doekos\TypeSafe\Questions\Score;
use Doekos\TypeSafe\Responses\ModelsResponse;
use Doekos\TypeSafe\Responses\SystemOneResponse;
use Doekos\TypeSafe\TypeSafeClient;
use Illuminate\Http\Client\Factory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Opt-in live checks against the real api.typesafe.ai. Skipped unless TYPESAFE_RUN_LIVE_TESTS=1
 * and TYPESAFE_API_KEY is set. The key is read from the environment only and never written anywhere.
 */
final class LiveApiTest extends TestCase
{
    private function skipUnlessLive(): void
    {
        if ((getenv('TYPESAFE_RUN_LIVE_TESTS') ?: '') !== '1') {
            self::markTestSkipped('Set TYPESAFE_RUN_LIVE_TESTS=1 to run live API tests.');
        }
    }

    private function liveClient(): TypeSafeClient
    {
        $this->skipUnlessLive();
        if ((getenv('TYPESAFE_API_KEY') ?: '') === '') {
            self::markTestSkipped('Set TYPESAFE_API_KEY to run live API tests.');
        }

        return new TypeSafeClient(http: new Factory);
    }

    #[Test]
    public function it_lists_models(): void
    {
        $response = $this->liveClient()->models->list();

        self::assertInstanceOf(ModelsResponse::class, $response);
        self::assertNotEmpty($response->models);
        self::assertNotSame('', $response->models[0]->name);
    }

    #[Test]
    public function it_answers_a_small_system_one_request(): void
    {
        $response = $this->liveClient()->systemOne('I was charged twice, please help.', [
            'billing' => new Noul('Is this about billing?'),
            'category' => new Choice(['billing' => 'money matters', 'technical' => 'a technical problem']),
            'urgency' => new Score(['can wait', 'today', 'now']),
        ]);

        self::assertInstanceOf(SystemOneResponse::class, $response);
        self::assertNotNull($response->requestId);
        self::assertInstanceOf(NoulAnswer::class, $response->noul('billing'));
        self::assertInstanceOf(ChoiceAnswer::class, $response->choice('category'));
        self::assertInstanceOf(ScoreAnswer::class, $response->score('urgency'));
    }

    #[Test]
    public function an_invalid_key_returns_an_authentication_error_body(): void
    {
        $this->skipUnlessLive();

        $client = new TypeSafeClient(apiKey: 'sk-invalid-key', http: new Factory);

        try {
            $client->models->list();
            self::fail('Expected an ApiException.');
        } catch (ApiException $error) {
            self::assertContains($error->status, [401, 403]);
            self::assertNotSame('', $error->getMessage());
        }
    }
}
