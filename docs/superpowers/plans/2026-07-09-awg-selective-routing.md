# AWG 2.0 Selective CIDR Routing — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a make-driven generator that turns this repo's `config/*/*.json` CIDR data into an nftables set + plain list for an AmneziaWG 2.0 front server that selectively routes matched destinations through a WireGuard gateway; plus a daily self-updating pipeline with Telegram reporting; plus agent-executed server deployment.

**Architecture:** Part A (this repo, TDD): extract the reusable CIDR helpers from `scripts/build-keenetic-routes-from-cidr4.php` into a shared library, add `scripts/build-cidr4-list.php` that emits `generated/awg-cidr4.lst` + `generated/awg-set.nft`, add Make targets, and add `scripts/awg-update.sh` (git pull from fork → regenerate → diff → conditional atomic nft reload → Telegram). Part B (live servers, agent-executed over SSH): bring up Server 2 (dumb WG egress) then Server 1 (AWG 2.0 in, policy-based routing out), following the `deploy/` runbooks with lockout-safety.

**Tech Stack:** PHP 8.1+ (CLI, no framework — matches existing scripts), PHPUnit 9, GNU Make, Bash, nftables, WireGuard / AmneziaWG 2.0, systemd, Telegram Bot API via curl. Debian 13 (trixie) on both servers.

**Spec:** `docs/superpowers/specs/2026-07-08-awg-selective-routing-design.md`

## Global Constraints

- **PHP 8.1+**, `declare(strict_types=1);` at top of every new PHP file (matches existing scripts).
- **IPv4 only.** No IPv6 anywhere in generated data or configs.
- **Data source is the fork** `git@github.com:damnedest/iplist.git` (remote `fork`), branch `master` — never pull data from upstream `origin` (`rekryt/iplist`).
- **nftables identifiers are fixed:** family/table `inet awg`, set `awgvia`, fwmark `0x1`, routing table `100`.
- **Reuse, don't duplicate:** shared CIDR math lives in `scripts/lib/cidr4.php`; both `build-keenetic-routes-from-cidr4.php` and `build-cidr4-list.php` require it.
- **Existing keenetic output must not change.** `make keenetic-all` produces byte-identical output before and after the refactor (guarded by a golden test).
- **CLI style:** human messages go to STDERR via the `c()` color helper; machine output (lists) goes to STDOUT or a file. Exit non-zero on error.
- **Secrets never in the repo.** Telegram token/chat id live only in `/etc/awg/telegram.env` on the server; the repo ships `telegram.env.example` only.
- **Fail-closed** on Server 1: if `wg1` is down, marked traffic is blackholed, never leaked to the direct egress.

---

# Part A — Repository code (TDD, built and tested locally)

## File Structure (Part A)

- Create `scripts/lib/cidr4.php` — shared pure CIDR/IP helpers + `readConfigCidr4()`, `computeEffectiveCidr4()`, `allConfigFiles()`. No side effects, no I/O on include.
- Create `scripts/lib/awg_format.php` — pure formatters `formatAwgLst()`, `formatAwgNftSet()`.
- Modify `scripts/build-keenetic-routes-from-cidr4.php` — `require_once` the lib; delete its now-duplicated helper definitions. Behaviour unchanged.
- Create `scripts/build-cidr4-list.php` — thin CLI: collect → format → write `generated/awg-cidr4.lst` and `generated/awg-set.nft`.
- Create `scripts/awg-update.sh` — fetch-from-fork → regenerate → diff → conditional atomic reload → Telegram.
- Modify `Makefile` — add `awg-fetch`, `awg-all`, `awg-reload`, `awg-update`.
- Create tests under `test/Scripts/`:
  - `KeeneticRegressionTest.php` (golden guard around the refactor)
  - `Cidr4LibTest.php`
  - `AwgFormatTest.php`
  - `BuildCidr4ListTest.php`
- Create fixtures under `test/fixtures/scripts/` (small config tree for deterministic assertions).

Tests use plain `PHPUnit\Framework\TestCase` (namespace `OpenCCK\Scripts`, autoloaded via `autoload-dev` `OpenCCK\ => test/`) and `require_once` the lib files directly — they do NOT use the amphp `AsyncTest` base.

---

### Task 1: Golden regression guard for keenetic output

Locks current keenetic behaviour before we touch the file, so the extraction in Task 2 is provably safe.

**Files:**
- Create: `test/fixtures/scripts/config/games/game-a.json`
- Create: `test/fixtures/scripts/config/tools/tool-a.json`
- Create: `test/Scripts/KeeneticRegressionTest.php`

**Interfaces:**
- Consumes: existing `scripts/build-keenetic-routes-from-cidr4.php` CLI (accepts config file paths as argv, prints `route add …` lines to STDOUT).
- Produces: a reusable fixture config tree at `test/fixtures/scripts/config/` used by Tasks 1, 4.

- [ ] **Step 1: Create fixture config files**

`test/fixtures/scripts/config/games/game-a.json`:
```json
{
    "domains": ["game-a.example"],
    "timeout": 0,
    "ip4": ["203.0.113.1"],
    "cidr4": ["198.51.100.0/24", "192.0.2.0/25"]
}
```

`test/fixtures/scripts/config/tools/tool-a.json`:
```json
{
    "domains": ["tool-a.example"],
    "timeout": 0,
    "ip4": ["192.0.2.200"],
    "cidr4": ["203.0.113.0/24"]
}
```

- [ ] **Step 2: Write the failing golden test**

`test/Scripts/KeeneticRegressionTest.php`:
```php
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
```

- [ ] **Step 3: Run the test and capture ACTUAL output**

Run: `vendor/bin/phpunit --filter testKeeneticOutputForFixtureIsStable`
Expected: it may FAIL if the hard-coded `$expected` differs from real output (ordering, aggregation). If it fails, copy the ACTUAL string from the diff into `$expected` verbatim and re-run until it PASSES. The goal is to pin *whatever the script does today*, not to assert a guessed value.

- [ ] **Step 4: Confirm the test passes (pins current behaviour)**

Run: `vendor/bin/phpunit --filter testKeeneticOutputForFixtureIsStable`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add test/fixtures/scripts/config test/Scripts/KeeneticRegressionTest.php
git commit -m "test: golden regression guard for keenetic route output"
```

---

### Task 2: Extract shared CIDR library

Move the pure helpers out of the keenetic script into `scripts/lib/cidr4.php`, add the reusable collection function, and point keenetic at the lib. Task 1's golden test proves keenetic is unchanged.

**Files:**
- Create: `scripts/lib/cidr4.php`
- Modify: `scripts/build-keenetic-routes-from-cidr4.php` (delete moved functions, add `require_once`)
- Create: `test/Scripts/Cidr4LibTest.php`

**Interfaces:**
- Produces (global functions, no namespace — matches existing call sites):
  - `isValidIp4(string $ip): bool`
  - `ip4ToUInt(string $ip): ?int`
  - `cidrMask(int $prefix): int`
  - `trailingZeros(int $value): int`
  - `log2Floor(int $value): int`
  - `rangeToCidrs(int $start, int $end): array` (list of `"a.b.c.d/n"`)
  - `parseCidr4(string $cidr): ?array` (returns `[int $start, int $end]` or null)
  - `rangesIntersect(int,int,int,int): bool`
  - `subtractRange(array $input, array $subtrahends): array`
  - `aggregateCidr4(array $cidrs): iterable` (merged, minimal, sorted CIDR strings)
  - `loadCheckRanges(string $checkDir): array`
  - `defaultConfigFiles(string $configDir): array`
  - `defaultConfigPatterns(): array`
  - `readConfigCidr4(string $path): array` → `['ip4' => string[], 'cidr4' => string[]]` (NEW)
  - `computeEffectiveCidr4(array $paths, ?string $checkDir = null, bool $includeIp4 = true): array` (NEW; returns aggregated CIDR strings)
  - `allConfigFiles(string $configDir): array` (NEW; every `*/*.json` under configDir, sorted)
- Consumes: nothing.

- [ ] **Step 1: Create `scripts/lib/cidr4.php` with the moved helpers**

Create the file with this header, then **cut verbatim** these functions from `scripts/build-keenetic-routes-from-cidr4.php` (current line numbers in parens) and paste them below the header: `defaultConfigFiles` (200), `defaultConfigPatterns` (222), `loadCheckRanges` (254), `aggregateCidr4` (304), `parseCidr4` (352), `rangesIntersect` (389), `rangeToCidrs` (396), `subtractRange` (422), `isValidIp4` (453), `ip4ToUInt` (457), `cidrMask` (466), `trailingZeros` (474), `log2Floor` (488).

Do NOT move `c()`, `renderKeeneticRoute()`, `prefixToDottedMask()`, `formatCompression()`, `displayPath()`, or `stringify()` — those stay in the keenetic script (UI/format-specific).

File header:
```php
<?php

declare(strict_types=1);

/**
 * Shared IPv4 / CIDR helpers for the route generators. Pure functions, no I/O on
 * include. Required by scripts/build-keenetic-routes-from-cidr4.php and
 * scripts/build-cidr4-list.php.
 */

// <-- moved functions pasted here verbatim -->
```

- [ ] **Step 2: Append the three NEW functions to `scripts/lib/cidr4.php`**

```php
/**
 * Reads and validates a config file's ip4/cidr4 arrays.
 * @return array{ip4: string[], cidr4: string[]}
 * @throws \RuntimeException on unreadable file, invalid JSON, or invalid entry.
 */
function readConfigCidr4(string $path): array {
    if (!is_file($path) || !is_readable($path)) {
        throw new \RuntimeException("Input file is not readable: {$path}");
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        throw new \RuntimeException("Failed to read input file: {$path}");
    }
    $config = json_decode($raw, true);
    if (!is_array($config)) {
        throw new \RuntimeException("Invalid JSON in {$path}: " . json_last_error_msg());
    }

    $ip4 = $config['ip4'] ?? [];
    $cidr4 = $config['cidr4'] ?? [];
    if (!is_array($ip4) || !is_array($cidr4)) {
        throw new \RuntimeException("Invalid config in {$path}: ip4/cidr4 must be arrays.");
    }

    $outIp4 = [];
    foreach ($ip4 as $ip) {
        if (!is_string($ip) || !isValidIp4($ip)) {
            throw new \RuntimeException("Invalid ip4 entry in {$path}: " . var_export($ip, true));
        }
        $outIp4[] = $ip;
    }
    $outCidr4 = [];
    foreach ($cidr4 as $cidr) {
        if (!is_string($cidr) || parseCidr4($cidr) === null) {
            throw new \RuntimeException("Invalid cidr4 entry in {$path}: " . var_export($cidr, true));
        }
        $outCidr4[] = $cidr;
    }

    return ['ip4' => $outIp4, 'cidr4' => $outCidr4];
}

/**
 * Collects cidr4 (and, by default, ip4 as /32) from every path, punches out any
 * ranges present under $checkDir, then aggregates to a minimal sorted CIDR list.
 * @param string[] $paths
 * @return string[]
 */
function computeEffectiveCidr4(array $paths, ?string $checkDir = null, bool $includeIp4 = true): array {
    $cidrs = [];
    foreach ($paths as $path) {
        $cfg = readConfigCidr4($path);
        foreach ($cfg['cidr4'] as $c) {
            $cidrs[$c] = true;
        }
        if ($includeIp4) {
            foreach ($cfg['ip4'] as $ip) {
                $cidrs[$ip . '/32'] = true;
            }
        }
    }

    $checkRanges = ($checkDir !== null && is_dir($checkDir)) ? loadCheckRanges($checkDir) : [];
    if ($checkRanges !== []) {
        $effective = [];
        foreach (array_keys($cidrs) as $cidr) {
            $range = parseCidr4($cidr);
            if ($range === null) {
                continue;
            }
            $overlap = [];
            foreach ($checkRanges as $check) {
                [$bStart, $bEnd] = $check['range'];
                if (rangesIntersect($range[0], $range[1], $bStart, $bEnd)) {
                    $overlap[] = $check['range'];
                }
            }
            if ($overlap === []) {
                $effective[$cidr] = true;
                continue;
            }
            foreach (subtractRange($range, $overlap) as [$rStart, $rEnd]) {
                foreach (rangeToCidrs($rStart, $rEnd) as $rc) {
                    $effective[$rc] = true;
                }
            }
        }
        $cidrs = $effective;
    }

    $out = [];
    foreach (aggregateCidr4(array_keys($cidrs)) as $c) {
        $out[] = $c;
    }
    return $out;
}

/**
 * Every config/<group>/<site>.json under $configDir, sorted. Used for the full
 * AWG routing table ("everything").
 * @return string[]
 */
function allConfigFiles(string $configDir): array {
    if (!is_dir($configDir)) {
        return [];
    }
    $files = [];
    foreach (glob($configDir . '/*/*.json', GLOB_NOSORT) ?: [] as $path) {
        if (is_file($path)) {
            $files[] = $path;
        }
    }
    sort($files, SORT_STRING);
    return array_values($files);
}
```

- [ ] **Step 3: Point the keenetic script at the lib**

In `scripts/build-keenetic-routes-from-cidr4.php`, immediately after the opening `declare(strict_types=1);` block (before the script's own logic), add:
```php
require_once __DIR__ . '/lib/cidr4.php';
```
Then delete the 13 function definitions listed in Step 1 from the keenetic file (they now come from the lib).

- [ ] **Step 4: Run the golden test — behaviour must be unchanged**

Run: `vendor/bin/phpunit --filter testKeeneticOutputForFixtureIsStable`
Expected: PASS (identical to Task 1). If it fails, the extraction changed something — fix before proceeding.

- [ ] **Step 5: Write lib unit tests**

`test/Scripts/Cidr4LibTest.php`:
```php
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

    public function testAllConfigFilesFindsFixtureTree(): void {
        $files = allConfigFiles(__DIR__ . '/../fixtures/scripts/config');
        self::assertNotEmpty($files);
        foreach ($files as $f) {
            self::assertStringEndsWith('.json', $f);
        }
    }
}
```

- [ ] **Step 6: Add the bad-entry fixture**

`test/fixtures/scripts/config/bad/bad.json`:
```json
{ "domains": ["bad.example"], "timeout": 0, "ip4": ["not-an-ip"], "cidr4": [] }
```
Note: `allConfigFiles` will include `bad/bad.json`; keep AWG generation (Task 4) pointed at a curated fixture list in tests so the bad file doesn't break unrelated assertions. `bad.json` exists only for the throw test.

- [ ] **Step 7: Run all script tests**

Run: `vendor/bin/phpunit --filter 'Cidr4Lib|Keenetic'`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add scripts/lib/cidr4.php scripts/build-keenetic-routes-from-cidr4.php test/Scripts/Cidr4LibTest.php test/fixtures/scripts/config/bad/bad.json
git commit -m "refactor: extract shared CIDR lib; reuse in keenetic builder"
```

---

### Task 3: AWG output formatters

**Files:**
- Create: `scripts/lib/awg_format.php`
- Create: `test/Scripts/AwgFormatTest.php`

**Interfaces:**
- Produces:
  - `formatAwgLst(array $cidrs): string` — newline-joined, trailing newline; empty string for empty input.
  - `formatAwgNftSet(array $cidrs, string $table = 'awg', string $set = 'awgvia'): string` — an atomic nft transaction (`flush set` + `table … { set … { elements = { … } } }`).
- Consumes: nothing (pure string formatting).

- [ ] **Step 1: Write the failing test**

`test/Scripts/AwgFormatTest.php`:
```php
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
```

- [ ] **Step 2: Run it — expect failure**

Run: `vendor/bin/phpunit --filter AwgFormat`
Expected: FAIL (`require`d file does not exist).

- [ ] **Step 3: Implement `scripts/lib/awg_format.php`**

```php
<?php

declare(strict_types=1);

/**
 * Pure formatters that render an aggregated CIDR list into the two artifacts the
 * AWG front server consumes: a plain .lst and an atomic nftables set transaction.
 */

/** @param string[] $cidrs */
function formatAwgLst(array $cidrs): string {
    return $cidrs === [] ? '' : implode("\n", $cidrs) . "\n";
}

/**
 * Renders an atomic nftables transaction: flush the set, then (re)declare the
 * table+set and load elements. Applying with `nft -f` is a single transaction,
 * so the live set is swapped without an empty window and without touching
 * interfaces or other rules.
 * @param string[] $cidrs
 */
function formatAwgNftSet(array $cidrs, string $table = 'awg', string $set = 'awgvia'): string {
    $head =
        "flush set inet {$table} {$set}\n" .
        "table inet {$table} {\n" .
        "    set {$set} {\n" .
        "        type ipv4_addr\n" .
        "        flags interval\n" .
        "        auto-merge\n";

    $elements = '';
    if ($cidrs !== []) {
        $joined = implode(",\n            ", $cidrs);
        $elements =
            "        elements = {\n" .
            "            {$joined}\n" .
            "        }\n";
    }

    return $head . $elements . "    }\n}\n";
}
```

- [ ] **Step 4: Run it — expect pass**

Run: `vendor/bin/phpunit --filter AwgFormat`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add scripts/lib/awg_format.php test/Scripts/AwgFormatTest.php
git commit -m "feat: AWG list + nftables set formatters"
```

---

### Task 4: `build-cidr4-list.php` CLI + generated artifacts

**Files:**
- Create: `scripts/build-cidr4-list.php`
- Create: `test/Scripts/BuildCidr4ListTest.php`
- Modify: `generated/.gitignore` (ensure new artifacts are ignored like the existing ones)

**Interfaces:**
- Consumes: `computeEffectiveCidr4()`, `allConfigFiles()`, `defaultConfigFiles()` (lib); `formatAwgLst()`, `formatAwgNftSet()` (lib).
- Produces: CLI writing `generated/awg-cidr4.lst` and `generated/awg-set.nft`. Usage:
  - `php scripts/build-cidr4-list.php` → all configs under `config/` (the AWG "everything" table).
  - `php scripts/build-cidr4-list.php config/ai/chatgpt.com.json …` → only the given files.
  - Honors `--lst=<path>` and `--nft=<path>` overrides (used by the test to write to a temp dir).

- [ ] **Step 1: Write the failing test**

`test/Scripts/BuildCidr4ListTest.php`:
```php
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

        // ip4 singletons ARE included as /32 in the AWG table.
        self::assertStringContainsString('203.0.113.1/32', $lstBody);
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
}
```

- [ ] **Step 2: Run it — expect failure**

Run: `vendor/bin/phpunit --filter BuildCidr4List`
Expected: FAIL (script missing).

- [ ] **Step 3: Implement `scripts/build-cidr4-list.php`**

```php
#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/cidr4.php';
require_once __DIR__ . '/lib/awg_format.php';

$useColor = PHP_SAPI === 'cli' && stream_isatty(STDERR) && getenv('NO_COLOR') === false;

function cc(string $text, string $code): string {
    global $useColor;
    return $useColor ? "\033[{$code}m{$text}\033[0m" : $text;
}

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from CLI.\n");
    exit(1);
}

$rootDir = __DIR__ . '/..';
$lstPath = $rootDir . '/generated/awg-cidr4.lst';
$nftPath = $rootDir . '/generated/awg-set.nft';
$paths = [];

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--lst=')) {
        $lstPath = substr($arg, 6);
    } elseif (str_starts_with($arg, '--nft=')) {
        $nftPath = substr($arg, 6);
    } elseif ($arg === '-h' || $arg === '--help') {
        fwrite(STDERR, "Usage: php scripts/build-cidr4-list.php [--lst=PATH] [--nft=PATH] [config.json ...]\n");
        fwrite(STDERR, "No config files => all config/*/*.json (the full AWG routing table).\n");
        exit(0);
    } else {
        $paths[] = $arg;
    }
}

if ($paths === []) {
    $paths = allConfigFiles($rootDir . '/config');
}
if ($paths === []) {
    fwrite(STDERR, cc("No input config files found.", "1;31") . "\n");
    exit(1);
}

try {
    $cidrs = computeEffectiveCidr4($paths, $rootDir . '/config/check', true);
} catch (\RuntimeException $e) {
    fwrite(STDERR, cc($e->getMessage(), "1;31") . "\n");
    exit(1);
}

$lstOk = file_put_contents($lstPath, formatAwgLst($cidrs)) !== false;
$nftOk = file_put_contents($nftPath, formatAwgNftSet($cidrs)) !== false;
if (!$lstOk || !$nftOk) {
    fwrite(STDERR, cc("Failed to write output files.", "1;31") . "\n");
    exit(1);
}

fwrite(STDERR, cc("AWG CIDR list:", "1;36") . " " . cc((string) count($cidrs), "1") . " entries\n");
fwrite(STDERR, "  " . $lstPath . "\n  " . $nftPath . "\n");
exit(0);
```

- [ ] **Step 4: Run it — expect pass**

Run: `vendor/bin/phpunit --filter BuildCidr4List`
Expected: PASS.

- [ ] **Step 5: Make the script executable & confirm real run**

Run:
```bash
chmod +x scripts/build-cidr4-list.php
php scripts/build-cidr4-list.php
head -3 generated/awg-cidr4.lst
head -8 generated/awg-set.nft
```
Expected: several thousand CIDR lines in the `.lst`; the `.nft` starts with `flush set inet awg awgvia`.

- [ ] **Step 6: Ensure generated artifacts are gitignored**

Read `generated/.gitignore`; if `awg-cidr4.lst` / `awg-set.nft` are not already covered by an existing wildcard, add:
```
awg-cidr4.lst
awg-set.nft
```

- [ ] **Step 7: Commit**

```bash
git add scripts/build-cidr4-list.php test/Scripts/BuildCidr4ListTest.php generated/.gitignore
git commit -m "feat: build-cidr4-list generator (awg .lst + nft set)"
```

---

### Task 5: Make targets

**Files:**
- Modify: `Makefile`

**Interfaces:**
- Consumes: `scripts/build-cidr4-list.php`, `scripts/awg-update.sh` (Task 6).
- Produces: `awg-fetch`, `awg-all`, `awg-reload`, `awg-update` targets.

- [ ] **Step 1: Add variables and targets to `Makefile`**

Add to the `.PHONY` line: `awg-fetch awg-all awg-reload awg-update`. Add near the other variables:
```make
AWG_CIDR_SCRIPT ?= scripts/build-cidr4-list.php
AWG_LST ?= $(GENERATED_DIR)/awg-cidr4.lst
AWG_NFT ?= $(GENERATED_DIR)/awg-set.nft
AWG_UPDATE_SCRIPT ?= scripts/awg-update.sh
```
Add the targets:
```make
awg-fetch: ## Fetch latest data from the fork remote (not upstream)
	git fetch $(FORK)
	git merge --ff-only $(FORK)/$(BRANCH)

awg-all: ensure-generated ## Generate AWG CIDR list + nftables set from all configs
	php $(AWG_CIDR_SCRIPT) --lst=$(AWG_LST) --nft=$(AWG_NFT)

awg-reload: ## Atomically load the nftables set (root; run on Server 1)
	nft -f $(AWG_NFT)

awg-update: ## Full cycle for the timer: fetch -> generate -> diff -> reload+notify if changed
	$(AWG_UPDATE_SCRIPT)
```

- [ ] **Step 2: Verify make help + dry runs**

Run:
```bash
make help
make awg-all
```
Expected: `help` lists the four new targets; `awg-all` regenerates the two artifacts without error.

- [ ] **Step 3: Commit**

```bash
git add Makefile
git commit -m "build: make targets awg-fetch/awg-all/awg-reload/awg-update"
```

---

### Task 6: `awg-update.sh` (fetch → diff → conditional reload → Telegram)

**Files:**
- Create: `scripts/awg-update.sh`

**Interfaces:**
- Consumes: `make awg-fetch`, `make awg-all`, `make awg-reload`; env file `/etc/awg/telegram.env` (`TG_TOKEN`, `TG_CHAT`); a snapshot file `$SNAPSHOT` (default `/var/lib/awg/awg-cidr4.prev`).
- Produces: side effects only (reload + Telegram). Idempotent when nothing changed (no reload, no message). Supports `--dry-run` (skip reload + skip send; print what would happen).

- [ ] **Step 1: Implement `scripts/awg-update.sh`**

```bash
#!/usr/bin/env bash
# Daily AWG CIDR refresh: pull data from the fork, regenerate the set, and if the
# effective list changed, atomically reload nftables and report the diff to Telegram.
set -euo pipefail

REPO_DIR="${AWG_REPO_DIR:-$(cd "$(dirname "$0")/.." && pwd)}"
GENERATED_DIR="${GENERATED_DIR:-$REPO_DIR/generated}"
LST="$GENERATED_DIR/awg-cidr4.lst"
SNAPSHOT="${AWG_SNAPSHOT:-/var/lib/awg/awg-cidr4.prev}"
ENV_FILE="${AWG_ENV_FILE:-/etc/awg/telegram.env}"
DRY_RUN=0
[ "${1:-}" = "--dry-run" ] && DRY_RUN=1

cd "$REPO_DIR"

notify() {
    local text="$1"
    if [ "$DRY_RUN" = "1" ]; then
        echo "[dry-run] would send: $text"
        return 0
    fi
    if [ ! -r "$ENV_FILE" ]; then
        echo "WARN: $ENV_FILE unreadable; cannot notify" >&2
        return 0
    fi
    # shellcheck disable=SC1090
    . "$ENV_FILE"
    if ! curl -sS -m 20 -X POST "https://api.telegram.org/bot${TG_TOKEN}/sendMessage" \
            -d chat_id="${TG_CHAT}" -d parse_mode=HTML --data-urlencode text="$text" >/dev/null; then
        echo "WARN: Telegram send failed" >&2   # visible in journal; do not lose the run
    fi
}

make awg-fetch
make awg-all

mkdir -p "$(dirname "$SNAPSHOT")"
if [ ! -f "$SNAPSHOT" ]; then
    # First run: adopt current list as baseline, load once, announce.
    cp "$LST" "$SNAPSHOT"
    [ "$DRY_RUN" = "1" ] || make awg-reload
    notify "⚙️ awg CIDR initialized: $(wc -l < "$LST") entries"
    exit 0
fi

if diff -q "$SNAPSHOT" "$LST" >/dev/null; then
    echo "No CIDR changes."
    exit 0
fi

added="$(comm -13 "$SNAPSHOT" "$LST" | wc -l | tr -d ' ')"
removed="$(comm -23 "$SNAPSHOT" "$LST" | wc -l | tr -d ' ')"
sample="$(
    { comm -13 "$SNAPSHOT" "$LST" | sed 's/^/+ /'; comm -23 "$SNAPSHOT" "$LST" | sed 's/^/- /'; } | head -30
)"
more_added="$(comm -13 "$SNAPSHOT" "$LST" | wc -l | tr -d ' ')"
more_removed="$(comm -23 "$SNAPSHOT" "$LST" | wc -l | tr -d ' ')"
total_changes=$(( more_added + more_removed ))
suffix=""
[ "$total_changes" -gt 30 ] && suffix=$'\n…and '"$(( total_changes - 30 ))"" more"

[ "$DRY_RUN" = "1" ] || make awg-reload
cp "$LST" "$SNAPSHOT"
notify "⚙️ awg CIDR updated: +${added} / -${removed}"$'\n'"${sample}${suffix}"
echo "Reloaded: +${added}/-${removed}"
```
NOTE: `comm` requires sorted input; the generator's `aggregateCidr4` already emits sorted CIDRs, but sort defensively if the diff ever looks wrong (`comm -13 <(sort "$SNAPSHOT") <(sort "$LST")`).

- [ ] **Step 2: Make executable and shellcheck**

Run:
```bash
chmod +x scripts/awg-update.sh
shellcheck scripts/awg-update.sh || true   # advisory; fix real warnings
```
Expected: no error-level shellcheck findings (the `SC1090` source is intentionally suppressed).

- [ ] **Step 3: Functional check with `--dry-run` (no root, no network reload)**

Run:
```bash
rm -f /tmp/awg-snap.prev
AWG_SNAPSHOT=/tmp/awg-snap.prev AWG_ENV_FILE=/dev/null scripts/awg-update.sh --dry-run
AWG_SNAPSHOT=/tmp/awg-snap.prev AWG_ENV_FILE=/dev/null scripts/awg-update.sh --dry-run
```
Expected: first run prints `[dry-run] would send: ⚙️ awg CIDR initialized: N entries`; second run prints `No CIDR changes.` (assuming `awg-fetch` pulled nothing new). Note: `make awg-fetch` will try to reach the `fork` remote — if the sandbox has no network/remote, temporarily comment `make awg-fetch` for this local check, then restore it.

- [ ] **Step 4: Commit**

```bash
git add scripts/awg-update.sh
git commit -m "feat: awg-update.sh — diff-driven reload + Telegram report"
```

---

### Task 7: Deploy artifacts — Server 2 (dumb WG egress)

Static templates + runbook. No unit tests; verification is review here and exercised live in Part B.

**Files:**
- Create: `deploy/server2/wg0.conf.example`
- Create: `deploy/server2/nftables-server2.nft`
- Create: `deploy/RUNBOOK-server2.md`

- [ ] **Step 1: `deploy/server2/wg0.conf.example`**

```ini
# /etc/wireguard/wg0.conf on Server 2 (target-jurisdiction egress gateway).
# Fill <PLACEHOLDERS>. Generate keys: wg genkey | tee privkey | wg pubkey > pubkey
[Interface]
Address = 10.9.9.2/30
ListenPort = 51820
PrivateKey = <SERVER2_PRIVATE_KEY>
# no default-route games here; this box just NATs what arrives on wg0

[Peer]
# Server 1
PublicKey = <SERVER1_WG1_PUBLIC_KEY>
AllowedIPs = 10.9.9.1/32
```

- [ ] **Step 2: `deploy/server2/nftables-server2.nft`**

```
#!/usr/sbin/nft -f
# Server 2: forward + masquerade traffic arriving from Server 1 over wg0.
flush ruleset

table inet fw {
    chain input {
        type filter hook input priority filter; policy drop;
        ct state established,related accept
        iif "lo" accept
        # keep management SSH reachable on WAN (adjust port if non-standard)
        tcp dport 22 accept
        # WireGuard listener
        udp dport 51820 accept
        ip protocol icmp accept
    }
    chain forward {
        type filter hook forward priority filter; policy drop;
        ct state established,related accept
        iifname "wg0" accept
    }
}

table ip nat {
    chain postrouting {
        type nat hook postrouting priority srcnat; policy accept;
        oifname "eth0" masquerade
    }
}
```

- [ ] **Step 3: `deploy/RUNBOOK-server2.md`**

Write an ordered, root-executed checklist (each step ends with a verification):
1. `apt update && apt install -y wireguard nftables` ; verify `wg --version`.
2. `sysctl` — write `/etc/sysctl.d/99-awg.conf` with `net.ipv4.ip_forward=1`; `sysctl --system`; verify `sysctl net.ipv4.ip_forward` = 1.
3. Generate keys; write `/etc/wireguard/wg0.conf` from the example; `chmod 600`.
4. Fill Server 1's public key later (cross-reference: Server 1's `wg1` pubkey → this peer). Record Server 2's public key + public IP + `51820` for Server 1's config.
5. Install `nftables-server2.nft` as `/etc/nftables.conf` (adjust SSH port). **Before enabling:** confirm the SSH `accept` rule matches your real port. `nft -f /etc/nftables.conf`; verify you still have your SSH session; `systemctl enable --now nftables`.
6. `systemctl enable --now wg-quick@wg0`; verify `wg show wg0`.
7. Note: the tunnel won't complete until Server 1 is up (Part B, Server 1).

- [ ] **Step 4: Commit**

```bash
git add deploy/server2 deploy/RUNBOOK-server2.md
git commit -m "deploy: Server 2 (WG egress) configs + runbook"
```

---

### Task 8: Deploy artifacts — Server 1 (AWG in, PBR out) + systemd + Telegram

**Files:**
- Create: `deploy/server1/awg0.conf.example`
- Create: `deploy/server1/wg1.conf.example`
- Create: `deploy/server1/nftables-awg.nft`
- Create: `deploy/server1/awg-pbr.service`
- Create: `deploy/server1/awg-update.service`
- Create: `deploy/server1/awg-update.timer`
- Create: `deploy/server1/telegram.env.example`
- Create: `deploy/RUNBOOK-server1.md`

- [ ] **Step 1: `deploy/server1/awg0.conf.example`**

```ini
# /etc/amnezia/amneziawg/awg0.conf on Server 1 (client-facing AWG 2.0 interface).
# AWG 2.0 obfuscation params (Jc/Jmin/Jmax/S1/S2/H1..H4 + 2.0: I1..I5, Itime) MUST
# match the client config exactly. Values below are placeholders — generate a real set.
[Interface]
Address = 10.9.0.1/24
ListenPort = 51820
PrivateKey = <SERVER1_AWG_PRIVATE_KEY>
Jc = 4
Jmin = 40
Jmax = 70
S1 = 0
S2 = 0
H1 = <H1>
H2 = <H2>
H3 = <H3>
H4 = <H4>
# I1..I5 / Itime: AWG 2.0 junk-packet templates — set per your obfuscation profile.

[Peer]
# one block per client
PublicKey = <CLIENT_PUBLIC_KEY>
AllowedIPs = 10.9.0.2/32
```

- [ ] **Step 2: `deploy/server1/wg1.conf.example`**

```ini
# /etc/wireguard/wg1.conf on Server 1 (plain WG uplink to Server 2).
# Table = off: wg-quick must NOT install a default route into the main table.
# The policy-routing rule (Task/awg-pbr.service) sends only marked traffic here.
[Interface]
Address = 10.9.9.1/30
PrivateKey = <SERVER1_WG1_PRIVATE_KEY>
Table = off
MTU = 1420

[Peer]
# Server 2
PublicKey = <SERVER2_WG0_PUBLIC_KEY>
Endpoint = <SERVER2_PUBLIC_IP>:51820
AllowedIPs = 0.0.0.0/0
PersistentKeepalive = 25
```

- [ ] **Step 3: `deploy/server1/nftables-awg.nft`**

```
#!/usr/sbin/nft -f
# Server 1 base ruleset. The awgvia set is populated separately by `nft -f awg-set.nft`
# (generated). This file declares the set empty and the mark/nat/mss rules.
table inet awg {
    set awgvia {
        type ipv4_addr
        flags interval
        auto-merge
    }

    chain prerouting {
        type filter hook prerouting priority mangle; policy accept;
        # mark ONLY client traffic destined to the routed CIDRs (never local/SSH traffic)
        iifname "awg0" ip daddr @awgvia meta mark set 0x1
    }

    chain forward {
        type filter hook forward priority filter; policy accept;
        tcp flags syn tcp option maxseg size set rt mtu
    }

    chain postrouting {
        type nat hook postrouting priority srcnat; policy accept;
        oifname "eth0" masquerade
        oifname "wg1" masquerade
    }
}
```
Note: keep a separate management-input ruleset (as in Server 2) if Server 1 also runs a drop-policy input chain; ensure SSH stays accepted.

- [ ] **Step 4: `deploy/server1/awg-pbr.service`**

```ini
[Unit]
Description=AWG policy-based routing (fwmark 0x1 -> table 100 via wg1, fail-closed)
After=wg-quick@wg1.service network-online.target
Wants=network-online.target
Requires=wg-quick@wg1.service

[Service]
Type=oneshot
RemainAfterExit=yes
ExecStart=/sbin/ip rule add fwmark 0x1 lookup 100
ExecStart=/sbin/ip route add default dev wg1 table 100
ExecStart=/sbin/ip route add blackhole default table 100 metric 100
ExecStop=/sbin/ip route flush table 100
ExecStop=/sbin/ip rule del fwmark 0x1 lookup 100

[Install]
WantedBy=multi-user.target
```

- [ ] **Step 5: `deploy/server1/awg-update.service` + `awg-update.timer`**

`awg-update.service`:
```ini
[Unit]
Description=AWG CIDR set refresh from fork
After=network-online.target
Wants=network-online.target

[Service]
Type=oneshot
WorkingDirectory=/opt/iplist
ExecStart=/usr/bin/make awg-update
```

`awg-update.timer`:
```ini
[Unit]
Description=Daily AWG CIDR set refresh

[Timer]
OnCalendar=*-*-* 04:17:00
Persistent=true

[Install]
WantedBy=timers.target
```

- [ ] **Step 6: `deploy/server1/telegram.env.example`**

```bash
# Copy to /etc/awg/telegram.env, chmod 600. Never commit real values.
TG_TOKEN=123456:ABC-your-bot-token
TG_CHAT=123456789
```

- [ ] **Step 7: `deploy/RUNBOOK-server1.md`**

Ordered root-executed checklist, each step ending with verification. Cover:
1. Install: `apt update`; `apt install -y wireguard nftables git make php-cli curl`. AWG 2.0: add the AmneziaWG apt repo and `apt install -y amneziawg amneziawg-tools`; **fallback** if no trixie package — build DKMS from `amneziawg-linux-kernel-module` (document the `dkms` steps) or use `amneziawg-go` userspace. Verify `awg --version`.
2. `git clone git@github.com:damnedest/iplist.git /opt/iplist` (the **fork**); verify `git -C /opt/iplist remote -v` shows the fork. (Decision to confirm at deploy: keep `merge --ff-only` in `awg-fetch`, or switch the deploy clone's `awg-fetch` to `git fetch fork && git reset --hard fork/master` for a hands-off box.)
3. `sysctl` `/etc/sysctl.d/99-awg.conf`: `net.ipv4.ip_forward=1`, `net.ipv4.conf.all.rp_filter=2`; `sysctl --system`; verify.
4. Keys for `awg0` and `wg1`; write configs from examples; `chmod 600`. Exchange pubkeys with Server 2 (fill Server 2's `[Peer]` with `wg1` pubkey; fill `wg1.conf` with Server 2 pubkey + IP).
5. **Lockout safety:** schedule a rollback before applying network rules —
   `systemd-run --on-active=10min --timer-property=AccuracySec=1s /bin/sh -c 'nft flush ruleset; systemctl restart nftables; ip route flush table 100; ip rule del fwmark 0x1 lookup 100 || true'`
   Apply base ruleset `nft -f /etc/nftables.conf` (with SSH accept verified first). Confirm SSH still alive.
6. `make awg-all` in `/opt/iplist`; `nft -f generated/awg-set.nft`; verify `nft list set inet awg awgvia | head`.
7. `systemctl enable --now wg-quick@wg1`; verify `wg show wg1` handshakes with Server 2.
8. `systemctl enable --now awg-pbr.service`; verify `ip rule` shows `fwmark 0x1 lookup 100` and `ip route show table 100` has `default dev wg1`.
9. `systemctl enable --now awg-quick@awg0` (or `wg-quick@awg0` per the AWG package); connect a test client.
10. Install `telegram.env` (chmod 600), `awg-update.service`+`.timer`; `systemctl enable --now awg-update.timer`; run once `systemctl start awg-update.service`; verify a Telegram message.
11. If everything is verified, **cancel the rollback timer**: `systemctl list-timers | grep run-` then `systemctl stop <transient-unit>` (or just let it fire if you want a clean re-apply). Run the §10 acceptance checks from the spec.

- [ ] **Step 8: Commit**

```bash
git add deploy/server1 deploy/RUNBOOK-server1.md
git commit -m "deploy: Server 1 (AWG in, PBR out) configs, systemd units, runbook"
```

---

# Part B — Server deployment (agent-executed over SSH, gated)

Not a TDD task list — executed against live Debian 13 servers using the runbooks from Tasks 7–8, in this order. **Prerequisites the user must supply before this part starts:**
- Working SSH from this machine to both servers (host aliases/IPs, root or sudo, SSH port).
- List of existing services on each box that must not be disrupted.

Execution order and gates:
1. **Server 2 first** (independent). Follow `deploy/RUNBOOK-server2.md`. Record its WG public key + public IP.
2. **Server 1 next.** Follow `deploy/RUNBOOK-server1.md`. Exchange keys with Server 2. Every network-mutating step is preceded by a `systemd-run` rollback timer (spec §12) so a mistake self-heals.
3. **Acceptance** (spec §10): curl to an in-set IP exits via Server 2's IP; curl to an out-of-set IP exits via Server 1's IP; `wg show` handshakes; stop `wg1` → in-set traffic is blackholed (fail-closed) while direct traffic still works; large HTTPS download works over both paths (MTU/MSS).

---

## Self-Review

**Spec coverage:**
- §2/§5 kernel PBR, marking, NAT, MSS → Task 8 (`nftables-awg.nft`, `awg-pbr.service`). ✅
- §6.1 make targets `awg-fetch/awg-all/awg-reload/awg-update` → Task 5. ✅
- §6.1 `build-cidr4-list.php` reusing keenetic CIDR logic → Tasks 2–4. ✅
- §6.1 artifacts `awg-cidr4.lst` + atomic `awg-set.nft` → Tasks 3–4. ✅
- §6.2 daily fork-sourced update + conditional reload → Task 6 + Task 8 timer. ✅
- §6.3 Telegram report, secrets outside repo → Task 6 + Task 8 `telegram.env.example`. ✅
- §7 MTU/MSS clamp → Task 8 `nftables-awg.nft` + `wg1.conf` MTU. ✅
- §8 fail-closed → Task 8 `awg-pbr.service` blackhole route. ✅
- §9 Server 2 egress → Task 7. ✅
- §10 acceptance → Part B step 3. ✅
- §11 fork as data source → Global Constraints + Task 5 `awg-fetch` + Task 8 clone. ✅
- §12 SSH lockout safety → Part B + Task 8 runbook rollback timer. ✅

**Placeholder scan:** Deployment configs use clearly-marked `<PLACEHOLDER>` substitution values (filled at deploy time) — these are complete templates, not plan gaps. No code step is left as TODO. ✅

**Type consistency:** `computeEffectiveCidr4(array, ?string, bool): string[]`, `formatAwgLst(array): string`, `formatAwgNftSet(array, string, string): string`, `readConfigCidr4(string): array{ip4,cidr4}` — names/signatures identical across Tasks 2, 3, 4. nft identifiers (`inet awg` / `awgvia` / fwmark `0x1` / table `100`) consistent across Tasks 3, 4, 6, 8. ✅
