<?php

declare(strict_types=1);

/**
 * Report whether this package still mirrors the latest official TypeSafe SDK release.
 *
 * Usage: php bin/upstream-check.php [--ours=0.7.0] [--python=0.7.0] [--js=0.6.0]
 * Omitted values are read from src/TypeSafeClient.php and the GitHub API.
 * Exits 0 when in step, 1 when upstream is ahead.
 */

namespace Doekos\TypeSafe\Tools;

const PYTHON_REPO = 'typesafe-ai/typesafe-sdk-python';
const JS_REPO = 'typesafe-ai/typesafe-sdk-js';

/**
 * The mirrored release is the furthest-ahead official SDK; ours must be at or above it.
 *
 * @return array{status: 'ok'|'behind', mirrored: string, summary: string}
 */
function verdict(string $ours, string $python, string $js): array
{
    $mirrored = version_compare($python, $js, '>=') ? $python : $js;
    $inStep = version_compare($ours, $mirrored, '>=');

    return [
        'status' => $inStep ? 'ok' : 'behind',
        'mirrored' => $mirrored,
        'summary' => $inStep
            ? sprintf('in step: %s mirrors python %s / js %s', $ours, $python, $js)
            : sprintf('behind: %s mirrors python %s / js %s; upstream is at %s', $ours, $python, $js, $mirrored),
    ];
}

/** The version constant, read without booting Composer's autoloader. */
function ourVersion(string $clientFile): string
{
    $source = file_get_contents($clientFile);
    if ($source === false || preg_match("/VERSION\s*=\s*'([^']+)'/", $source, $match) !== 1) {
        throw new \RuntimeException('Could not read VERSION from '.$clientFile);
    }

    return $match[1];
}

/** @return array{version: string, url: string, notes: string} */
function latestRelease(string $repo): array
{
    $headers = ['User-Agent: typesafe-laravel-sdk-upstream-check', 'Accept: application/vnd.github+json'];
    $token = getenv('GITHUB_TOKEN');
    if (is_string($token) && $token !== '') {
        $headers[] = 'Authorization: Bearer '.$token;
    }
    $body = file_get_contents('https://api.github.com/repos/'.$repo.'/releases/latest', false, stream_context_create([
        'http' => ['header' => implode("\r\n", $headers), 'timeout' => 15],
    ]));
    if ($body === false) {
        throw new \RuntimeException('Could not reach the GitHub API for '.$repo);
    }
    $data = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
    if (! is_array($data) || ! is_string($data['tag_name'] ?? null)) {
        throw new \RuntimeException('Unexpected release payload for '.$repo);
    }

    return [
        'version' => ltrim($data['tag_name'], 'v'),
        'url' => is_string($data['html_url'] ?? null) ? $data['html_url'] : '',
        'notes' => is_string($data['body'] ?? null) ? trim($data['body']) : '',
    ];
}

/** @param list<string> $argv */
function main(array $argv): int
{
    $options = [];
    foreach ($argv as $argument) {
        if (preg_match('/^--(ours|python|js)=(.+)$/', $argument, $match) === 1) {
            $options[$match[1]] = ltrim($match[2], 'v');
        }
    }

    $ours = $options['ours'] ?? ourVersion(__DIR__.'/../src/TypeSafeClient.php');
    $python = isset($options['python']) ? ['version' => $options['python'], 'url' => '', 'notes' => ''] : latestRelease(PYTHON_REPO);
    $js = isset($options['js']) ? ['version' => $options['js'], 'url' => '', 'notes' => ''] : latestRelease(JS_REPO);

    $result = verdict($ours, $python['version'], $js['version']);
    echo strtoupper($result['status']), ' ', $result['mirrored'], PHP_EOL, $result['summary'], PHP_EOL;
    if ($result['status'] === 'ok') {
        return 0;
    }

    $ahead = version_compare($python['version'], $js['version'], '>=') ? $python : $js;
    echo PHP_EOL, 'Release: ', $ahead['url'] ?: 'n/a', PHP_EOL;
    if ($ahead['notes'] !== '') {
        echo PHP_EOL, $ahead['notes'], PHP_EOL;
    }

    return 1;
}

$script = $_SERVER['SCRIPT_FILENAME'] ?? null;
if (PHP_SAPI === 'cli' && is_string($script) && realpath($script) === realpath(__FILE__)) {
    /** @var list<string> $arguments */
    $arguments = array_slice(is_array($_SERVER['argv'] ?? null) ? array_values($_SERVER['argv']) : [], 1);
    exit(main($arguments));
}
