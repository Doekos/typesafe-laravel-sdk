<?php

declare(strict_types=1);

namespace Doekos\TypeSafe\Tests\Unit;

use Doekos\TypeSafe\TypeSafeClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function Doekos\TypeSafe\Tools\ourVersion;
use function Doekos\TypeSafe\Tools\verdict;

require_once __DIR__.'/../../bin/upstream-check.php';

final class UpstreamCheckTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string, string, string}>
     */
    public static function versions(): array
    {
        return [
            'in step with the furthest-ahead SDK' => ['0.7.0', '0.7.0', '0.6.0', 'ok', '0.7.0'],
            'upstream minor ahead' => ['0.7.0', '0.8.0', '0.6.0', 'behind', '0.8.0'],
            'upstream patch ahead' => ['0.7.0', '0.7.1', '0.6.0', 'behind', '0.7.1'],
            'our own patch ahead is allowed' => ['0.7.3', '0.7.0', '0.6.0', 'ok', '0.7.0'],
            'js ahead of python' => ['0.7.0', '0.7.0', '0.9.0', 'behind', '0.9.0'],
        ];
    }

    #[Test]
    #[DataProvider('versions')]
    public function it_compares_our_version_against_both_official_sdks(
        string $ours,
        string $python,
        string $js,
        string $status,
        string $mirrored,
    ): void {
        $result = verdict($ours, $python, $js);

        self::assertSame($status, $result['status']);
        self::assertSame($mirrored, $result['mirrored']);
        self::assertStringContainsString($ours, $result['summary']);
    }

    #[Test]
    public function it_reads_the_version_constant_from_the_client(): void
    {
        self::assertSame(TypeSafeClient::VERSION, ourVersion(__DIR__.'/../../src/TypeSafeClient.php'));
    }
}
