<?php

declare(strict_types=1);

namespace OpenCCK\Scripts;

use PHPUnit\Framework\TestCase;

final class BuildCidr4ListTest extends TestCase {
    private const SCRIPT = __DIR__ . '/../../scripts/build-cidr4-list.php';
    private const FIXCONF = __DIR__ . '/../fixtures/scripts/config';

    public function testGeneratesLstAndNftFromExplicitConfigs(): void {
        $lst = tempnam(sys_get_temp_dir(), 'awglst');
        $nft = tempnam(sys_get_temp_dir(), 'awgnft');

        $cmd = 'NO_COLOR=1 php ' . escapeshellarg(self::SCRIPT)
            . ' --lst=' . escapeshellarg($lst)
            . ' --nft=' . escapeshellarg($nft)
            . ' ' . escapeshellarg(self::FIXCONF . '/games/game-a.json')
            . ' ' . escapeshellarg(self::FIXCONF . '/tools/tool-a.json')
            . ' 2>/dev/null';
        $rc = 0;
        $out = [];
        exec($cmd, $out, $rc);

        self::assertSame(0, $rc, 'script should exit 0');

        $lstBody = (string) file_get_contents($lst);
        $nftBody = (string) file_get_contents($nft);
        @unlink($lst);
        @unlink($nft);

        // ip4 singletons ARE included as /32 in the AWG table (unless a broader CIDR
        // in the same set subsumes them during aggregation). 192.0.2.200/32 lies
        // outside every other range, so it survives as a /32 and proves inclusion.
        self::assertStringContainsString('192.0.2.200/32', $lstBody);
        self::assertStringContainsString('198.51.100.0/24', $lstBody);
        self::assertStringContainsString('flush set inet awg awgvia', $nftBody);
        self::assertStringContainsString('198.51.100.0/24', $nftBody);
    }

    public function testExitsNonZeroOnBadConfig(): void {
        $cmd = 'NO_COLOR=1 php ' . escapeshellarg(self::SCRIPT)
            . ' --lst=/dev/null --nft=/dev/null '
            . escapeshellarg(self::FIXCONF . '/bad/bad.json') . ' 2>/dev/null';
        $rc = 0;
        $out = [];
        exec($cmd, $out, $rc);
        self::assertNotSame(0, $rc);
    }

    public function testDefaultListAwgGeneratesLst(): void {
        $defaultLst = tempnam(sys_get_temp_dir(), 'defaultlst');
        $defaultNft = tempnam(sys_get_temp_dir(), 'defaultnft');
        $awgLst = tempnam(sys_get_temp_dir(), 'awglst');
        $awgNft = tempnam(sys_get_temp_dir(), 'awgnft');

        $defaultCmd = 'NO_COLOR=1 php ' . escapeshellarg(self::SCRIPT)
            . ' --lst=' . escapeshellarg($defaultLst)
            . ' --nft=' . escapeshellarg($defaultNft)
            . ' 2>/dev/null'; // no config args, no --list => default --list=awg
        $defaultRc = 0;
        $defaultOut = [];
        exec($defaultCmd, $defaultOut, $defaultRc);

        $awgCmd = 'NO_COLOR=1 php ' . escapeshellarg(self::SCRIPT)
            . ' --lst=' . escapeshellarg($awgLst)
            . ' --nft=' . escapeshellarg($awgNft)
            . ' --list=awg 2>/dev/null';
        $awgRc = 0;
        $awgOut = [];
        exec($awgCmd, $awgOut, $awgRc);

        $defaultBody = (string) file_get_contents($defaultLst);
        $awgBody = (string) file_get_contents($awgLst);
        @unlink($defaultLst);
        @unlink($defaultNft);
        @unlink($awgLst);
        @unlink($awgNft);

        self::assertSame(0, $defaultRc, 'default --list=awg should exit 0');
        self::assertSame(0, $awgRc, 'explicit --list=awg should exit 0');
        self::assertNotSame('', trim($defaultBody), 'awg list should produce CIDR output');
        self::assertSame($awgBody, $defaultBody, 'default output must match explicit --list=awg output');
    }

    public function testUnknownListExitsNonZero(): void {
        $cmd = 'NO_COLOR=1 php ' . escapeshellarg(self::SCRIPT)
            . ' --lst=/dev/null --nft=/dev/null --list=does-not-exist 2>&1';
        $rc = 0;
        $out = [];
        exec($cmd, $out, $rc);
        $err = implode("\n", $out);
        self::assertNotSame(0, $rc);
        self::assertStringContainsString('Unknown config list', $err);
        self::assertStringContainsString('does-not-exist', $err);
    }
}
