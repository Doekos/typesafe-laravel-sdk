<?php

declare(strict_types=1);

namespace Doekos\TypeSafe\Support;

use Doekos\TypeSafe\Exceptions\TypeSafeException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;

/**
 * Level-gated logging to a Laravel channel, with credential redaction.
 *
 * `info` logs request outcomes and retries; `debug` adds redacted headers and bodies; `warn` reports
 * dropped answer types. Bodies are logged as-is (documented, matching the official SDKs).
 *
 * @internal
 */
final class RequestLogger
{
    /** @var array<string, int> */
    private const RANKS = ['debug' => 0, 'info' => 1, 'warn' => 2, 'error' => 3, 'off' => 4];

    private readonly string $level;

    public function __construct(private readonly ?string $channel, string $level)
    {
        $level = strtolower($level) === 'warning' ? 'warn' : strtolower($level);
        if (! isset(self::RANKS[$level])) {
            throw new TypeSafeException(sprintf('Invalid log level "%s". Expected one of: debug, info, warn, warning, error, off.', $level));
        }
        $this->level = $level;
    }

    public function info(string $message): void
    {
        $this->write('info', $message);
    }

    public function warn(string $message): void
    {
        $this->write('warn', $message);
    }

    /**
     * @param  array<string, string>  $headers
     */
    public function wire(string $method, string $url, string $arrow, array $headers, ?string $body): void
    {
        if (! $this->enabled('debug')) {
            return;
        }
        $this->write('debug', sprintf('%s %s %s headers=%s body=%s', $method, $url, $arrow,
            (string) json_encode($this->redactHeaders($headers)), $body ?? 'null'));
    }

    private function enabled(string $level): bool
    {
        return self::RANKS[$level] >= self::RANKS[$this->level];
    }

    private function write(string $level, string $message): void
    {
        if (! $this->enabled($level)) {
            return;
        }
        $logger = $this->channel === null ? Log::channel() : Log::channel($this->channel);
        $logger->{$level === 'warn' ? 'warning' : $level}('[typesafe] '.$message);
    }

    /**
     * Flatten and redact response headers for a wire log.
     *
     * @return array<string, string>
     */
    public static function flatten(Response $response): array
    {
        $flat = [];
        foreach ($response->headers() as $name => $values) {
            $flat[(string) $name] = implode(', ', array_map(
                static fn ($value): string => is_scalar($value) ? (string) $value : '',
                (array) $values,
            ));
        }

        return $flat;
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    private function redactHeaders(array $headers): array
    {
        $redacted = [];
        foreach ($headers as $name => $value) {
            $lower = strtolower($name);
            if (in_array($lower, ['authorization', 'proxy-authorization', 'x-api-key'], true)) {
                $redacted[$name] = self::maskKey($value);
            } elseif ($lower === 'cookie' || $lower === 'set-cookie' || $lower === 'api-key'
                || str_contains($lower, 'token') || str_contains($lower, 'secret')) {
                $redacted[$name] = '***';
            } else {
                $redacted[$name] = $value;
            }
        }

        return $redacted;
    }

    /** Mask a credential, keeping its scheme and the last four characters of secrets longer than eight. */
    private static function maskKey(string $value): string
    {
        if (str_contains($value, ' ')) {
            [$scheme, $secret] = explode(' ', $value, 2);
        } else {
            $scheme = null;
            $secret = $value;
        }
        $tail = strlen($secret) > 8 ? substr($secret, -4) : '';

        return ($scheme !== null ? $scheme.' ' : '').'***'.$tail;
    }
}
