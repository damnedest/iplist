<?php

declare(strict_types=1);

namespace OpenCCK\Scripts;

use PHPUnit\Framework\TestCase;

/**
 * Characterization test: pins the exact STDOUT of the keenetic route builder for a
 * fixed fixture config set, so the Task 2 library extraction cannot change behaviour.
 */
final class KeeneticRegressionTest extends TestCase {
    private const SCRIPT = __DIR__ . '/../../scripts/build-keenetic-routes-from-cidr4.php';
    private const FIXCONF = __DIR__ . '/../fixtures/scripts/config';

    private function runScript(array $args): string {
        $cmd = 'NO_COLOR=1 php ' . escapeshellarg(self::SCRIPT);
        foreach ($args as $a) {
            $cmd .= ' ' . escapeshellarg($a);
        }
        $cmd .= ' 2>/dev/null';
        return (string) shell_exec($cmd);
    }

    public function testKeeneticOutputForFixtureIsStable(): void {
        $out = $this->runScript([
            self::FIXCONF . '/games/game-a.json',
            self::FIXCONF . '/tools/tool-a.json',
        ]);

        // cidr4 entries only, aggregated & sorted, rendered as Keenetic routes.
        // 192.0.2.0/25 + 203.0.113.0/24 + 198.51.100.0/24 (ip4 singletons are not routed by keenetic).
        $expected =
            "route add 192.0.2.0 mask 255.255.255.128 0.0.0.0\n" .
            "route add 198.51.100.0 mask 255.255.255.0 0.0.0.0\n" .
            "route add 203.0.113.0 mask 255.255.255.0 0.0.0.0\n";

        self::assertSame($expected, $out);
    }
}
