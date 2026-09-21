# TypeSafe Laravel SDK

A Laravel-native SDK for the [TypeSafe](https://typesafe.ai) (Jev / System One) API, expressed in
Laravel idioms: config, a service provider, a facade, the `Http` client, `Http::fake`, `Http::pool`,
and `Log`. It mirrors the behaviour of the official [Python](https://github.com/typesafe-ai/typesafe-sdk-python)
and [JS](https://github.com/typesafe-ai/typesafe-sdk-js) SDKs — retry budget and predicate, concurrency,
credential-redacting logs, response models, and full error mapping.

- **PHP** 8.3+
- **Laravel** 12, 13

## Installation

```bash
composer require doekos/typesafe-laravel-sdk
```

The service provider and `TypeSafe` facade are auto-discovered. Publish the config if you want to
tweak defaults:

```bash
php artisan vendor:publish --tag=typesafe-config
```

Set your key in `.env`:

```dotenv
TYPESAFE_API_KEY=apikey_...
```

## Quick start

```php
use Doekos\TypeSafe\Facades\TypeSafe;
use Doekos\TypeSafe\Questions\Noul;
use Doekos\TypeSafe\Questions\Choice;
use Doekos\TypeSafe\Questions\Score;

$response = TypeSafe::systemOne(
    state: 'I was charged twice. Please help.',
    questions: [
        'billing'  => Noul::make('Is this message about billing?'),
        'urgent'   => Noul::make('Is it urgent?', true: 'needs action today'),
        'category' => Choice::make('What is it about?', ['billing' => 'money matters', 'technical' => 'a technical problem']),
        'urgency'  => Score::make('How soon?', ['can wait', 'today', 'right now']),
        'raw'      => ['type' => 'noul', 'instructions' => 'Is the customer polite?'], // raw arrays work too
    ],
);

$response->noul('billing')->noul;          // 0.98
$response->choice('category')->choice;     // "billing"
$response->score('urgency')->score;        // 1.7
$response->usage->inputTokens;             // 120
$response->requestId;                      // "req_..."
$response->rawResponse;                    // Illuminate\Http\Client\Response

// All answers by name, or grouped by type:
$response->answers;   // ['billing' => NoulAnswer, ...]
$response->nouls;   // ['billing' => NoulAnswer]
$response->choices; // ['category' => ChoiceAnswer]
$response->scores;  // ['urgency' => ScoreAnswer]
```

List available models:

```php
foreach (TypeSafe::listModels()->models as $model) {
    echo "{$model->name} — {$model->description} ({$model->releaseDate})\n";
}
// or, through the container: app(TypeSafeClient::class)->models->list()
```

Questions may also be passed as raw arrays (useful for question types this SDK does not model yet):

```php
TypeSafe::systemOne('...', [
    'region' => ['type' => 'bounding_box', 'criteria' => [...]],
]);
```

## Configuration

`config/typesafe.php`. Each value except `headers` falls back to an environment variable; a null
value falls back to the SDK default in the table below.

| Config key            | Env var                    | Default                      | Notes                                            |
|-----------------------|----------------------------|------------------------------|--------------------------------------------------|
| `api_key`             | `TYPESAFE_API_KEY`         | —                            | Required. Resolved lazily; errors on first use.  |
| `base_url`            | `TYPESAFE_BASE_URL`        | `https://api.typesafe.ai`    | Trailing slash stripped.                         |
| `default_model`       | `TYPESAFE_DEFAULT_MODEL`   | `jev-latest`                 | Used when a request omits `model`.               |
| `timeout`             | `TYPESAFE_TIMEOUT`         | `10.0`                       | Per-attempt timeout, in seconds.                 |
| `headers`             | —                          | `[]`                         | Default headers on every request.                |
| `log.channel`         | `TYPESAFE_LOG_CHANNEL`     | default channel              | Laravel log channel.                             |
| `log.level`           | `TYPESAFE_LOG_LEVEL`       | `warn`                       | `debug` \| `info` \| `warn` \| `error` \| `off`. |

Per-call overrides on `systemOne()`: `model`, `retry`, `timeout`, `extraHeaders`, `extraBody`,
`responseModel`. On `listModels()` (and `models->list()`): `retry`, `timeout`, `extraHeaders`.

## Retries

`RetryPolicy` is an immutable value object in **seconds**. Defaults match the official SDKs:

```php
use Doekos\TypeSafe\RetryPolicy;

$policy = new RetryPolicy(
    maxRetries: 2,
    backoffInitial: 0.5,
    backoffMax: 5.0,
    backoffJitter: 0.25,
    httpStatuses: [408, 429, 500, 502, 503, 504], // default: 408, 429, 5xx
    respectRetryAfter: true,
    maxRetryAfter: 60.0,     // ignore a Retry-After larger than this, in seconds
    apiConnectionError: true,
    apiTimeoutError: true,
    exceptions: [],          // extra throwable types to retry
    predicate: null,         // fn (Throwable): bool
    timeout: 30.0,           // total budget per call, in seconds (null disables)
);

TypeSafe::systemOne('...', [...], retry: $policy);
```

Delay honours `retry-after-ms`, then `Retry-After` (seconds or HTTP-date), else capped exponential
backoff with jitter. The total-budget stops before a retry whose delay would reach the budget.

A per-call `RetryPolicy` **replaces** the client policy (Python). A per-call **array** of field names
merges over it instead (JS `Partial<RetryPolicy>`); unknown or mistyped fields throw `TypeSafeException`:

```php
TypeSafe::systemOne('...', [...], retry: ['maxRetries' => 5]); // everything else inherits the client policy
```

## Concurrency

`TypeSafe::pool()` sends many `systemOne` requests over `Http::pool`. It never throws per item: each
keyed result is a decoded response **or** a `TypeSafeException`. Retries happen in rounds.

```php
use Doekos\TypeSafe\Pool;

$results = TypeSafe::pool(fn (Pool $p) => [
    'a' => $p->systemOne('first message',  ['spam' => Noul::make('Is this spam?')]),
    'b' => $p->systemOne('second message', ['spam' => Noul::make('Is this spam?')]),
]);

$results['a']; // SystemOneResponse | TypeSafeException
$results['b'];

// Http::pool style works too: $pool->as('a')->systemOne(...); unkeyed requests are numbered.
```

## Response models (DTO hydration)

Pass a class name as `responseModel` to hydrate a typed DTO. Constructor parameters are matched by
question name to typed answers; `model`, `usage`, and `requestId` are filled when declared. A missing
required parameter, or an answer whose type does not match the declared parameter type, raises a
`ResponseValidationException`.

```php
final class Triage
{
    public function __construct(
        public NoulAnswer $billing,
        public ChoiceAnswer $category,
        public string $model,
        public Usage $usage,
        public ?string $requestId = null,
    ) {}
}

$triage = TypeSafe::systemOne('...', [...], responseModel: Triage::class);
```

## Logging

The SDK logs to `Log::channel(config('typesafe.log.channel'))`, gated by `log.level`. Every line is
prefixed `[typesafe]`:

- `info` — request outcomes (`POST … <- 200 in 123ms (request req_…)`) and retries. Pooled requests
  log the same way, keyed by their result name (`POST … <- 200 (pool a, request req_…)`).
- `debug` — request/response headers and bodies.
- `warn` — dropped answer types the SDK does not model.

Credential headers are redacted: `authorization`, `proxy-authorization`, `x-api-key` keep their
scheme and the last four characters (`Bearer ***abcd`); `cookie`, `set-cookie`, `api-key`, and any
header whose name contains `token` or `secret` are fully masked (`***`). Bodies are logged as-is, the
same as the official SDKs.

## Errors

All exceptions extend `Doekos\TypeSafe\Exceptions\TypeSafeException`.

| Condition               | Exception                       |
|-------------------------|---------------------------------|
| 400                     | `BadRequestException`           |
| 401                     | `AuthenticationException`       |
| 403                     | `PermissionDeniedException`     |
| 404                     | `NotFoundException`             |
| 409                     | `ConflictException`             |
| 422                     | `UnprocessableEntityException`  |
| 429                     | `RateLimitException`            |
| 5xx                     | `InternalServerException`       |
| other 4xx               | `ApiException`                  |
| connection failure      | `ApiConnectionException`        |
| timeout                 | `ApiTimeoutException`           |
| malformed success body  | `ResponseValidationException`   |

`ApiException` exposes `$status`, `$body`, `$headers`, `$endpoint`, and `requestId()`. Its message is
`"{METHOD url}: {status} {message} (request_id=…)"`, with the server message extracted from `error`,
`error.message`, `message`, `detail`, `detail.message`, or a FastAPI `detail[]` list.

## Testing

Because the SDK is built on Laravel's `Http` client, test it with `Http::fake()`:

```php
Http::fake(['api.typesafe.ai/*' => Http::response([
    'model' => 'jev-latest',
    'answers' => ['spam' => ['type' => 'noul', 'noul' => 0.98]],
    'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
], 200)]);
```

## Parity with the official SDKs

Every public symbol of the Python (`__all__`) and JS (`index.ts`) SDKs is mapped or listed as a
non-goal.

| Python / JS                              | This package                                     |
|------------------------------------------|--------------------------------------------------|
| `TypeSafeClient`                         | `TypeSafeClient` (+ `TypeSafe` facade)           |
| `Models` / `ListModelsResponse`          | `Models` / `Responses\ModelsResponse`            |
| `ModelMetadata`                          | `Responses\ModelMetadata`                        |
| `Noul` / `Choice` / `Score`              | `Questions\Noul` / `Choice` / `Score`            |
| `noul()` / `choice()` / `score()` (JS)   | `Noul::make()` / `Choice::make()` / `Score::make()` |
| `Question` / `Questions`                 | `Questions\Question` / `array<string, mixed>`    |
| `NoulModel` / `ChoiceModel` / `ScoreModel` / `QuestionModel` | raw arrays (accepted directly) |
| `NoulCriteria`                           | array (`['true' => …, 'false' => …]`)            |
| `Answer` / `NoulAnswer` / `ChoiceAnswer` / `ScoreAnswer` | `Answers\Answer` / `NoulAnswer` / `ChoiceAnswer` / `ScoreAnswer` |
| `SystemOneResponse` / `Usage`            | `Responses\SystemOneResponse` / `Usage`          |
| `RetryPolicy`                            | `RetryPolicy`                                     |
| `JSONContent` / `JSONValue`              | native `string \| array`                         |
| `TypeSafeError`                          | `Exceptions\TypeSafeException`                    |
| `TypeSafeAPIError`                       | `Exceptions\ApiException`                         |
| `TypeSafeAPIConnectionError`             | `Exceptions\ApiConnectionException`              |
| `TypeSafeAPITimeoutError`                | `Exceptions\ApiTimeoutException`                 |
| `TypeSafeAPIResponseValidationError`     | `Exceptions\ResponseValidationException`         |
| `TypeSafeBadRequestError` … `TypeSafeUnprocessableEntityError` | `BadRequestException` … `UnprocessableEntityException` |
| `ConflictError` (JS) / 409              | `Exceptions\ConflictException`                    |
| `LOG_LEVELS` / `constants`               | `config/typesafe.php` + env vars                 |
| `responseModel` (Python Pydantic)        | `responseModel` (constructor hydration)          |
| **Concurrency:** `AsyncTypeSafeClient` / `AsyncModels` | `TypeSafe::pool()` (Laravel `Http::pool`) |
| **Non-goal:** `APIPromise` / `WithResponse` (JS) | `$response->rawResponse`                  |
| **Non-goal:** `AbortSignal`, `dangerouslyAllowBrowser`, custom `fetch`/httpx transport | Laravel `Http` factory (`Http::globalMiddleware`, container binding, `Http::fake`) |

## Versioning

This package's version says which official SDK release it mirrors. `0.7.0` mirrors **Python SDK 0.7.0**
and **JavaScript SDK 0.6.0** (the two share one release line; Python was ahead at the time).

- **MAJOR.MINOR** always equals the highest official release this package mirrors, so upgrading the
  minor means the mirrored API surface moved.
- **PATCH** belongs to this package and never goes backwards: PHP-only fixes raise it between upstream
  releases, and it jumps to the official patch when that one overtakes it. The version is therefore
  always at or above the release it mirrors.
- Every changelog entry names the upstream versions it mirrors, so the claim can be checked per release.

Run `composer upstream` to check this package against the latest official releases; a weekly workflow
does the same and opens an issue when they move ahead.

## License

MIT. See [LICENSE](LICENSE).
