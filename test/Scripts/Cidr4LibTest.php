<?php

declare(strict_types=1);

namespace OpenCCK\Scripts;

use PHPUnit\Framework\TestCase;

final class Cidr4LibTest extends TestCase {
    public static function setUpBeforeClass(): void {
        require_once __DIR__ . '/../../scripts/lib/cidr4.php';
    }

    public function testParseCidr4RejectsGarbage(): void {
        self::assertNull(parseCidr4('nope'));
        self::assertNull(parseCidr4('1.2.3.4/33'));
        self::assertIsArray(parseCidr4('10.0.0.0/8'));
    }

    public function testReadConfigCidr4ReturnsArrays(): void {
        $cfg = readConfigCidr4(__DIR__ . '/../fixtures/scripts/config/games/game-a.json');
        self::assertSame(['203.0.113.1'], $cfg['ip4']);
        self::assertContains('198.51.100.0/24', $cfg['cidr4']);
    }

    public function testReadConfigCidr4ThrowsOnBadEntry(): void {
        $this->expectException(\RuntimeException::class);
        readConfigCidr4(__DIR__ . '/../fixtures/scripts/config/bad/bad.json');
    }

    public function testComputeEffectiveIncludesIp4AsSlash32(): void {
        $cidrs = computeEffectiveCidr4(
            [__DIR__ . '/../fixtures/scripts/config/games/game-a.json'],
            null,
            true
        );
        self::assertContains('203.0.113.1/32', $cidrs);
        self::assertContains('198.51.100.0/24', $cidrs);
    }

    public function testComputeEffectiveExcludesIp4WhenDisabled(): void {
        $cidrs = computeEffectiveCidr4(
            [__DIR__ . '/../fixtures/scripts/config/games/game-a.json'],
            null,
            false
        );
        self::assertNotContains('203.0.113.1/32', $cidrs);
    }

}
