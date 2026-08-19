<?php

declare(strict_types=1);

namespace OpenCCK\Scripts;

use PHPUnit\Framework\TestCase;

/**
 * Covers the 'subtract' declaration in config/lists.php: a list can be pinned disjoint
 * from another one, so two generated route files never claim the same prefix.
 */
final class ListSubtractTest extends TestCase {
    private string $dir;

    public static function setUpBeforeClass(): void {
        // cidr4.php reports warnings through helpers each CLI entry point defines itself.
        foreach (['c', 'displayPath', 'stringify'] as $fn) {
            if (!function_exists($fn)) {
                eval("function {$fn}(\$v, \$_ = null): string { return (string) (is_scalar(\$v) ? \$v : json_encode(\$v)); }");
            }
        }
        require_once __DIR__ . '/../../scripts/lib/cidr4.php';
    }

    protected function setUp(): void {
        $this->dir = sys_get_temp_dir() . '/listsub-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/set', 0755, true);
    }

    protected function tearDown(): void {
        foreach (glob($this->dir . '/set/*.json') ?: [] as $f) {
            unlink($f);
        }
        @unlink($this->dir . '/lists.php');
        @rmdir($this->dir . '/set');
        @rmdir($this->dir);
    }

    /** @param array<int, string> $cidr4 */
    private function writeConfig(string $name, array $cidr4, array $ip4 = []): void {
        file_put_contents(
            $this->dir . '/set/' . $name . '.json',
            json_encode(['domains' => [], 'ip4' => $ip4, 'ip6' => [], 'cidr4' => $cidr4, 'cidr6' => []])
        );
    }

    private function writeLists(array $lists): void {
        file_put_contents($this->dir . '/lists.php', '<?php return ' . var_export($lists, true) . ';');
    }

    public function testFlatListStillResolvesAndDeclaresNoSubtraction(): void {
        $this->writeConfig('a', ['10.0.0.0/8']);
        $this->writeLists(['main' => ['set/a.json']]);

        self::assertSame([$this->dir . '/set/a.json'], resolveList('main', $this->dir));
        self::assertSame([], resolveSubtract('main', $this->dir));
        self::assertSame(['10.0.0.0/8'], computeListCidr4('main', $this->dir));
    }

    public function testSubtractDropsPrefixesOwnedByTheOtherList(): void {
        $this->writeConfig('main', ['10.0.0.0/8']);
        $this->writeConfig('side', ['10.0.0.0/8', '192.168.0.0/16']);
        $this->writeLists([
            'main' => ['set/main.json'],
            'side' => ['files' => ['set/side.json'], 'subtract' => ['main']],
        ]);

        self::assertSame(['main'], resolveSubtract('side', $this->dir));
        self::assertSame(['192.168.0.0/16'], computeListCidr4('side', $this->dir));
        self::assertSame(['10.0.0.0/8'], computeListCidr4('main', $this->dir), 'main must stay untouched');
    }

    public function testPartiallyOverlappingPrefixIsSplitNotDropped(): void {
        $this->writeConfig('main', ['10.1.0.0/16']);
        $this->writeConfig('side', ['10.0.0.0/14']);
        $this->writeLists([
            'main' => ['set/main.json'],
            'side' => ['files' => ['set/side.json'], 'subtract' => ['main']],
        ]);

        self::assertSame(['10.0.0.0/16', '10.2.0.0/15'], computeListCidr4('side', $this->dir));
    }

    public function testSubtractionCoversIp4EntriesOfTheOtherList(): void {
        $this->writeConfig('main', [], ['10.0.0.1']);
        $this->writeConfig('side', ['10.0.0.0/30']);
        $this->writeLists([
            'main' => ['set/main.json'],
            'side' => ['files' => ['set/side.json'], 'subtract' => ['main']],
        ]);

        self::assertSame(['10.0.0.0/32', '10.0.0.2/31'], computeListCidr4('side', $this->dir));
    }

    public function testEmptyResultWhenFullyContained(): void {
        $this->writeConfig('main', ['10.0.0.0/8']);
        $this->writeConfig('side', ['10.10.0.0/16']);
        $this->writeLists([
            'main' => ['set/main.json'],
            'side' => ['files' => ['set/side.json'], 'subtract' => ['main']],
        ]);

        self::assertSame([], computeListCidr4('side', $this->dir));
    }

    public function testCircularSubtractIsRejected(): void {
        $this->writeConfig('a', ['10.0.0.0/8']);
        $this->writeConfig('b', ['192.168.0.0/16']);
        $this->writeLists([
            'a' => ['files' => ['set/a.json'], 'subtract' => ['b']],
            'b' => ['files' => ['set/b.json'], 'subtract' => ['a']],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Circular 'subtract' declaration involving list 'a'");
        computeListCidr4('a', $this->dir);
    }

    public function testMalformedDeclarationIsRejected(): void {
        $this->writeConfig('a', ['10.0.0.0/8']);
        $this->writeLists(['a' => ['files' => ['set/a.json'], 'subtract' => 'main']]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("must both be arrays");
        resolveSubtract('a', $this->dir);
    }

    /**
     * The invariant the whole feature exists for: nothing the router routes through the
     * main .bat may also appear in the YouTube one.
     */
    public function testRealYoutubeListIsDisjointFromKeenetic(): void {
        $configDir = __DIR__ . '/../../config';
        $checkDir = $configDir . '/check';

        $youtube = computeListCidr4('youtube', $configDir, $checkDir);
        $keenetic = computeListCidr4('keenetic', $configDir, $checkDir);

        $keeneticRanges = array_map(
            fn(string $cidr): array => parseCidr4($cidr),
            $keenetic
        );

        $collisions = [];
        foreach ($youtube as $cidr) {
            [$start, $end] = parseCidr4($cidr);
            foreach ($keeneticRanges as [$kStart, $kEnd]) {
                if (rangesIntersect($start, $end, $kStart, $kEnd)) {
                    $collisions[] = $cidr;
                    break;
                }
            }
        }

        self::assertSame([], $collisions, 'YouTube routes overlap the main list');
        self::assertNotSame([], $youtube, 'subtraction must not empty the list entirely');
    }
}
