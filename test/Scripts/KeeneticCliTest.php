<?php

declare(strict_types=1);

namespace OpenCCK\Scripts;

use PHPUnit\Framework\TestCase;

final class KeeneticCliTest extends TestCase {
    private const SCRIPT = __DIR__ . '/../../scripts/build-keenetic-routes-from-cidr4.php';

    /** @return array{0:string,1:int} stdout, exit code */
    private function runCli(array $args): array {
        $cmd = 'NO_COLOR=1 php ' . escapeshellarg(self::SCRIPT);
        foreach ($args as $a) {
            $cmd .= ' ' . escapeshellarg($a);
        }
        $cmd .= ' 2>/dev/null';
        $out = [];
        $rc = 0;
        exec($cmd, $out, $rc);
        return [implode("\n", $out), $rc];
    }

    public function testDefaultListEmitsKeeneticRoutes(): void {
        [$stdout, $rc] = $this->runCli([]); // no args => --list=keenetic
        self::assertSame(0, $rc);
        self::assertStringContainsString('route add ', $stdout);
    }

    public function testExplicitListNameEmitsRoutes(): void {
        [$stdout, $rc] = $this->runCli(['--list=youtube']);
        self::assertSame(0, $rc);
        self::assertStringContainsString('route add ', $stdout);
    }

    public function testUnknownListExitsNonZero(): void {
        [, $rc] = $this->runCli(['--list=does-not-exist']);
        self::assertNotSame(0, $rc);
    }
}
