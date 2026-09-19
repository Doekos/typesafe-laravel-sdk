<?php

declare(strict_types=1);

namespace Doekos\TypeSafe\Exceptions;

/** An unsuccessful HTTP response, with its body, headers, and request context. */
class ApiException extends TypeSafeException
{
    private const MAX_ERROR_BODY_LENGTH = 200;

    /**
     * @param  array<mixed>  $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly mixed $body,
        public readonly array $headers,
        public readonly ?string $endpoint = null,
        ?string $message = null,
    ) {
        parent::__construct($this->formatMessage($message ?? $this->resolveMessage()));
    }

    /**
     * Build the matching exception subclass for an HTTP status code.
     *
     * @param  array<mixed>  $headers
     */
    public static function for(int $status, mixed $body, array $headers, ?string $endpoint = null): self
    {
        return match (true) {
            $status === 400 => new BadRequestException($status, $body, $headers, $endpoint),
            $status === 401 => new AuthenticationException($status, $body, $headers, $endpoint),
            $status === 403 => new PermissionDeniedException($status, $body, $headers, $endpoint),
            $status === 404 => new NotFoundException($status, $body, $headers, $endpoint),
            $status === 409 => new ConflictException($status, $body, $headers, $endpoint),
            $status === 422 => new UnprocessableEntityException($status, $body, $headers, $endpoint),
            $status === 429 => new RateLimitException($status, $body, $headers, $endpoint),
            $status >= 500 => new InternalServerException($status, $body, $headers, $endpoint),
            default => new self($status, $body, $headers, $endpoint),
        };
    }

    /** The `x-typesafe-request-id` response header, or `null` if absent. */
    public function requestId(): ?string
    {
        return $this->header('x-typesafe-request-id');
    }

    /** Case-insensitive lookup of a single header value. */
    public function header(string $name): ?string
    {
        return self::headerValue($this->headers, $name);
    }

    /**
     * @param  array<mixed>  $headers
     */
    private static function headerValue(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (strcasecmp((string) $key, $name) === 0) {
                if (is_array($value)) {
                    $first = $value[0] ?? null;

                    return is_string($first) ? $first : null;
                }

                return is_string($value) ? $value : null;
            }
        }

        return null;
    }

    /**
     * Parse `retry-after-ms` (preferred) or `retry-after` (seconds or HTTP-date) into milliseconds.
     *
     * @param  array<mixed>  $headers
     */
    public static function parseRetryAfterMs(array $headers, ?int $now = null): ?float
    {
        $get = static fn (string $name): ?string => self::headerValue($headers, $name);

        $ms = $get('retry-after-ms');
        if ($ms !== null && is_numeric(trim($ms))) {
            $value = (float) trim($ms);
            // A negative or non-finite value falls through to `retry-after`, as the official SDKs do.
            if (is_finite($value) && $value >= 0) {
                return $value;
            }
        }

        $raw = $get('retry-after');
        if ($raw === null) {
            return null;
        }
        if (is_numeric(trim($raw))) {
            $seconds = (float) trim($raw);

            return is_finite($seconds) && $seconds >= 0 ? $seconds * 1000 : null;
        }
        $timestamp = strtotime($raw);
        if ($timestamp === false) {
            return null;
        }

        return max(0.0, ($timestamp - ($now ?? time())) * 1000);
    }

    private function formatMessage(?string $message): string
    {
        $text = $this->endpoint !== null ? $this->endpoint.': ' : '';
        $text .= (string) $this->status;
        if ($message !== null && $message !== '') {
            $text .= ' '.$message;
        }
        $requestId = $this->requestId();
        if ($requestId !== null) {
            $text .= sprintf(' (request_id=%s)', $requestId);
        }

        return $text;
    }

    private function resolveMessage(): string
    {
        $extracted = self::extractMessage($this->body);
        if ($extracted !== null && $extracted !== '') {
            return $extracted;
        }
        if ($this->body === null) {
            return 'status code (no body)';
        }
        $encoded = json_encode($this->body);
        $raw = is_string($this->body) ? $this->body : (is_string($encoded) ? $encoded : '');

        return mb_strlen($raw) > self::MAX_ERROR_BODY_LENGTH
            ? mb_substr($raw, 0, self::MAX_ERROR_BODY_LENGTH).'…'
            : $raw;
    }

    /** Port of the Python SDK's server-message extraction. */
    public static function extractMessage(mixed $body): ?string
    {
        if (is_string($body)) {
            return $body === '' ? null : $body;
        }
        if (! is_array($body)) {
            return null;
        }

        $error = $body['error'] ?? null;
        if (is_string($error)) {
            return $error;
        }
        if (is_array($error) && isset($error['message']) && is_string($error['message'])) {
            return $error['message'];
        }

        $message = $body['message'] ?? null;
        if (is_string($message)) {
            return $message;
        }

        $detail = $body['detail'] ?? null;
        if (is_string($detail)) {
            return $detail;
        }
        if (is_array($detail) && isset($detail['message']) && is_string($detail['message'])) {
            return $detail['message'];
        }
        if (is_array($detail) && array_is_list($detail)) {
            $parts = [];
            foreach ($detail as $entry) {
                if (! is_array($entry) || ! isset($entry['msg']) || ! is_string($entry['msg'])) {
                    continue;
                }
                $location = $entry['loc'] ?? null;
                $path = '';
                if (is_array($location)) {
                    $segments = array_filter($location, static fn ($item): bool => $item !== 'body' && is_scalar($item));
                    $path = implode('.', array_map(static fn ($item): string => is_scalar($item) ? (string) $item : '', $segments));
                }
                $parts[] = $path !== '' ? sprintf('%s: %s', $path, $entry['msg']) : $entry['msg'];
            }

            return $parts === [] ? null : implode('; ', $parts);
        }

        return null;
    }
}
