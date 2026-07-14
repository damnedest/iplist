<?php

declare(strict_types=1);

namespace OpenCCK\Scripts;

use PHPUnit\Framework\TestCase;

final class AwgFormatTest extends TestCase {
    public static function setUpBeforeClass(): void {
        require_once __DIR__ . '/../../scripts/lib/awg_format.php';
    }

    public function testLstJoinsWithTrailingNewline(): void {
        self::assertSame("10.0.0.0/8\n192.0.2.0/24\n", formatAwgLst(['10.0.0.0/8', '192.0.2.0/24']));
    }

    public function testLstEmpty(): void {
        self::assertSame('', formatAwgLst([]));
    }

    public function testNftSetContainsAtomicFlushAndElements(): void {
        $nft = formatAwgNftSet(['10.0.0.0/8', '192.0.2.0/24']);
        self::assertStringContainsString('flush set inet awg awgvia', $nft);
        self::assertStringContainsString('table inet awg {', $nft);
        self::assertStringContainsString('set awgvia {', $nft);
        self::assertStringContainsString('flags interval', $nft);
        self::assertStringContainsString('auto-merge', $nft);
        self::assertStringContainsString('10.0.0.0/8', $nft);
        self::assertStringContainsString('192.0.2.0/24', $nft);
    }

    public function testNftSetEmptyHasNoElementsBlock(): void {
        $nft = formatAwgNftSet([]);
        self::assertStringContainsString('flush set inet awg awgvia', $nft);
        self::assertStringNotContainsString('elements =', $nft);
    }
}
