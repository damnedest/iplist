<?php

declare(strict_types=1);

namespace OpenCCK\Scripts;

use PHPUnit\Framework\TestCase;

final class ListsTest extends TestCase {
    private const FIXCONF = __DIR__ . '/../fixtures/scripts/config';
    private const FIXLISTS = __DIR__ . '/../fixtures/scripts/lists.php';

    public static function setUpBeforeClass(): void {
        require_once __DIR__ . '/../../scripts/lib/cidr4.php';
    }

    public function testExpandsGlobsToFiles(): void {
        $files = resolveList('games', self::FIXCONF, self::FIXLISTS);
        self::assertNotEmpty($files);
        foreach ($files as $f) {
            self::assertStringEndsWith('.json', $f);
        }
        self::assertContains(self::FIXCONF . '/games/game-a.json', $files);
    }

    public function testExcludesCheckDir(): void {
        $files = resolveList('withcheck', self::FIXCONF, self::FIXLISTS);
        foreach ($files as $f) {
            self::assertStringNotContainsString('/check/', $f);
        }
        // The non-check entry still resolves.
        self::assertContains(self::FIXCONF . '/games/game-a.json', $files);
    }

    public function testThrowsOnUnknownList(): void {
        $this->expectException(\RuntimeException::class);
        resolveList('nope', self::FIXCONF, self::FIXLISTS);
    }

    public function testResultIsSortedAndDeduped(): void {
        $files = resolveList('both', self::FIXCONF, self::FIXLISTS);
        $sorted = $files;
        sort($sorted, SORT_STRING);
        self::assertSame($sorted, $files);
        self::assertSame(array_values(array_unique($files)), $files);
    }

    public function testListSelectsSetEndToEnd(): void {
        $files = resolveList('both', self::FIXCONF, self::FIXLISTS);
        $cidrs = computeEffectiveCidr4($files, null, true);
        // 'both' = games/game-a.json + tools/tool-a.json
        self::assertContains('203.0.113.0/24', $cidrs);  // tool-a cidr4
        self::assertContains('198.51.100.0/24', $cidrs);  // game-a cidr4
        self::assertContains('192.0.2.200/32', $cidrs);   // tool-a ip4 singleton, outside every range
    }
}
