<?php

declare(strict_types=1);

return [
    // API key. Required; resolution is lazy, so a missing key only errors on first use.
    'api_key' => env('TYPESAFE_API_KEY'),

    // Null values fall back to the SDK defaults (https://api.typesafe.ai, jev-latest, 10s, log level warn).

    // API base URL. A trailing slash is stripped.
    'base_url' => env('TYPESAFE_BASE_URL'),

    // Default model used when a request does not specify one.
    'default_model' => env('TYPESAFE_DEFAULT_MODEL'),

    // Per-attempt request timeout, in seconds.
    'timeout' => env('TYPESAFE_TIMEOUT'),

    // Default headers sent with every request.
    'headers' => [],

    'log' => [
        // Log channel, or null for the default channel.
        'channel' => env('TYPESAFE_LOG_CHANNEL'),

        // debug | info | warn | warning | error | off
        'level' => env('TYPESAFE_LOG_LEVEL'),
    ],
];
