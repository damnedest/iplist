# Unified Config Lists Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move the scattered "which config files feed which output" logic into one data file (`config/lists.php`) plus one resolver (`resolveList()`), and give both route generators a shared `--list=<name>` switch.

**Architecture:** `config/lists.php` returns a pure map of `list name → array of paths/globs` (relative to `config/`). A single `resolveList($name, $configDir, $listsFile)` in `scripts/lib/cidr4.php` expands a named list to a sorted, deduped list of real files (always excluding `config/check/`). Both `build-keenetic-routes-from-cidr4.php` and `build-cidr4-list.php` accept `--list=<name>`; explicit file arguments still take precedence. The three old resolvers (`defaultConfigFiles`, `defaultConfigPatterns`, `allConfigFiles`) are removed.

**Tech Stack:** PHP 8.5 (CLI), PHPUnit 9.x, GNU Make.

## Global Constraints

- Keenetic output must stay **byte-identical** — `KeeneticRegressionTest` (explicit fixture files, not `--list`) is the safety net and must keep passing unchanged.
- The `keenetic` list in `config/lists.php` must be the **verbatim** copy of today's `defaultConfigPatterns()` — all 23 entries, same order.
- `awg` is seeded as an exact copy of `keenetic`'s entries; `youtube` is `['youtube/youtube.com.json']`.
- `resolveList` **always excludes** anything under `config/check/` (the punch-out source is never a routing source).
- No change to CIDR math, aggregation, punch-out, output formats, `awg-update.sh`, or deploy artifacts. No IPv6.
- Explicit file arguments to either script **take precedence** over `--list`.
- Default list: keenetic script → `keenetic`; `build-cidr4-list.php` → `awg`.
- Unknown list name → resolver throws `\RuntimeException`; the script prints the message to STDERR and exits non-zero.
- Run the full suite with `composer test` (PHPUnit, bootstrap `test/bootstrap.php`).

---

## File Structure

- `scripts/lib/cidr4.php` — **modify**: add `resolveList()`; remove `defaultConfigFiles()`, `defaultConfigPatterns()`, `allConfigFiles()`.
- `config/lists.php` — **create**: the single source-of-truth list map.
- `scripts/build-keenetic-routes-from-cidr4.php` — **modify**: parse `--list`, default `keenetic`, call `resolveList()`.
- `scripts/build-cidr4-list.php` — **modify**: parse `--list`, default `awg`, call `resolveList()`.
- `Makefile` — **modify**: add `LIST ?=`, rewire targets to `--list`, remove `YOUTUBE_CONFIG`.
- `test/fixtures/scripts/lists.php` — **create**: fixture list map for resolver tests.
- `test/fixtures/scripts/config/check/probe.json` — **create**: fixture check file (proves `check/` exclusion).
- `test/Scripts/ListsTest.php` — **create**: `resolveList()` unit + function-level end-to-end tests.
- `test/Scripts/ConfigListsTest.php` — **create**: pins `config/lists.php` verbatim.
- `test/Scripts/KeeneticCliTest.php` — **create**: keenetic script `--list` smoke + unknown-list exit code.
- `test/Scripts/Cidr4LibTest.php` — **modify**: drop the `allConfigFiles()` test.
- `test/Scripts/BuildCidr4ListTest.php` — **modify**: add default-`awg` and unknown-list assertions.

---

### Task 1: `resolveList()` in the lib + fixtures + ListsTest

**Files:**
- Modify: `scripts/lib/cidr4.php` (append new function after `allConfigFiles`, near line 412)
- Create: `test/fixtures/scripts/lists.php`
- Create: `test/fixtures/scripts/config/check/probe.json`
- Test: `test/Scripts/ListsTest.php`

**Interfaces:**
- Consumes: existing `computeEffectiveCidr4(array $paths, ?string $checkDir, bool $includeIp4): string[]` from `cidr4.php`.
- Produces: `resolveList(string $name, string $configDir, ?string $listsFile = null): array` — returns a sorted, deduped `array<int,string>` of real file paths (each `$configDir . '/' . <expanded entry>`), excluding any path under `$configDir/check/`; throws `\RuntimeException` if `$name` is not a key in the lists map, or if the lists file is missing/unreadable. `$listsFile` defaults to `$configDir . '/lists.php'`.

- [ ] **Step 1: Create the fixture check file**

Create `test/fixtures/scripts/config/check/probe.json`:

```json
{ "domains": ["probe.example"], "timeout": 0, "ip4": [], "cidr4": ["10.0.0.0/8"] }
```

- [ ] **Step 2: Create the fixture lists file**

Create `test/fixtures/scripts/lists.php`:

```php
<?php

declare(strict_types=1);

return [
    'both'      => ['games/game-a.json', 'tools/tool-a.json'],
    'games'     => ['games/*.json'],
    'withcheck' => ['games/*.json', 'check/*.json'],
];
```

- [ ] **Step 3: Write the failing test**

Create `test/Scripts/ListsTest.php`:

```php
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
```

- [ ] **Step 4: Run the test to verify it fails**

Run: `composer test -- --filter ListsTest`
Expected: FAIL — `Call to undefined function OpenCCK\Scripts\resolveList()`.

- [ ] **Step 5: Implement `resolveList()`**

In `scripts/lib/cidr4.php`, append after the closing brace of `allConfigFiles()` (currently the last function, ~line 412):

```php
/**
 * Resolves a named config list (from $listsFile, default $configDir/lists.php) to a
 * sorted, deduped list of real file paths. Every entry is glob-expanded relative to
 * $configDir; anything under $configDir/check/ is always excluded (that directory is
 * the punch-out source, never a routing source).
 *
 * @return array<int, string>
 * @throws \RuntimeException if the lists file is missing/unreadable or $name is undefined.
 */
function resolveList(string $name, string $configDir, ?string $listsFile = null): array {
    $listsFile ??= $configDir . '/lists.php';
    if (!is_file($listsFile) || !is_readable($listsFile)) {
        throw new \RuntimeException("Lists file not found or unreadable: {$listsFile}");
    }

    $lists = require $listsFile;
    if (!is_array($lists) || !array_key_exists($name, $lists) || !is_array($lists[$name])) {
        $known = is_array($lists) ? implode(', ', array_keys($lists)) : '(none)';
        throw new \RuntimeException("Unknown config list '{$name}'. Known lists: {$known}");
    }

    $checkPrefix = rtrim($configDir, '/') . '/check/';
    $files = [];
    foreach ($lists[$name] as $entry) {
        foreach (glob($configDir . '/' . $entry, GLOB_NOSORT) ?: [] as $path) {
            if (!is_file($path) || str_starts_with($path, $checkPrefix)) {
                continue;
            }
            $files[$path] = true;
        }
    }

    $files = array_keys($files);
    sort($files, SORT_STRING);
    return $files;
}
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `composer test -- --filter ListsTest`
Expected: PASS (5 tests).

- [ ] **Step 7: Run the full suite to confirm nothing regressed**

Run: `composer test`
Expected: PASS (old resolvers still present, so all existing tests still green).

- [ ] **Step 8: Commit**

```bash
git add scripts/lib/cidr4.php test/Scripts/ListsTest.php test/fixtures/scripts/lists.php test/fixtures/scripts/config/check/probe.json
git commit -m "feat: add resolveList() resolver + list fixtures and tests"
```

---

### Task 2: `config/lists.php` (single source) + verbatim pin test

**Files:**
- Create: `config/lists.php`
- Test: `test/Scripts/ConfigListsTest.php`

**Interfaces:**
- Consumes: nothing (pure data file returning `array<string, array<int,string>>`).
- Produces: `config/lists.php` returns a map with keys `keenetic`, `youtube`, `awg`. `keenetic` == the verbatim 23 entries from the removed `defaultConfigPatterns()`; `awg` == identical to `keenetic`; `youtube` == `['youtube/youtube.com.json']`.

- [ ] **Step 1: Write the failing pin test**

Create `test/Scripts/ConfigListsTest.php`:

```php
<?php

declare(strict_types=1);

namespace OpenCCK\Scripts;

use PHPUnit\Framework\TestCase;

/**
 * Pins config/lists.php so the keenetic set stays a verbatim copy of the old
 * defaultConfigPatterns(). This is the real safety net for the "byte-identical
 * keenetic output" requirement (KeeneticRegressionTest guards the math, not this list).
 */
final class ConfigListsTest extends TestCase {
    private const KEENETIC = [
        'ai/aistudio.google.com.json',
        'ai/chatgpt.com.json',
        'ai/claude.ai.json',
        'ai/grok.com.json',
        'ai/perplexity.ai.json',
        'custom/*.json',
        'discord/*.json',
        'games/roblox.com.json',
        'hosting/bitninja.com.json',
        'hosting/hetzner.com.json',
        'hosting/namecheap.com.json',
        'hosting/cloudlinux.com.json',
        'jetbrains/*.json',
        'messengers/messenger.com.json',
        'messengers/whatsapp.com.json',
        'music/spotify.com.json',
        'socials/facebook.com.json',
        'socials/instagram.com.json',
        'socials/linkedin.com.json',
        'socials/x.com.json',
        'tools/medium.com.json',
        'torrent/rutracker.org.json',
        'video/kino.pub.json',
    ];

    private function lists(): array {
        return require __DIR__ . '/../../config/lists.php';
    }

    public function testKeeneticIsVerbatimCuratedSet(): void {
        self::assertSame(self::KEENETIC, $this->lists()['keenetic']);
    }

    public function testAwgSeededAsCopyOfKeenetic(): void {
        $lists = $this->lists();
        self::assertSame($lists['keenetic'], $lists['awg']);
    }

    public function testYoutubeList(): void {
        self::assertSame(['youtube/youtube.com.json'], $this->lists()['youtube']);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer test -- --filter ConfigListsTest`
Expected: FAIL — `config/lists.php` does not exist (require fails).

- [ ] **Step 3: Create `config/lists.php`**

Create `config/lists.php`:

```php
<?php

declare(strict_types=1);

/**
 * Single source of truth for named config sets. Each list maps to file paths or globs
 * relative to config/. Consumed by scripts/lib/cidr4.php::resolveList() and, through it,
 * by both route generators via --list=<name>.
 */
return [
    'keenetic' => [
        'ai/aistudio.google.com.json',
        'ai/chatgpt.com.json',
        'ai/claude.ai.json',
        'ai/grok.com.json',
        'ai/perplexity.ai.json',
        'custom/*.json',
        'discord/*.json',
        'games/roblox.com.json',
        'hosting/bitninja.com.json',
        'hosting/hetzner.com.json',
        'hosting/namecheap.com.json',
        'hosting/cloudlinux.com.json',
        'jetbrains/*.json',
        'messengers/messenger.com.json',
        // 'messengers/telegram.org.json', // use official
        'messengers/whatsapp.com.json', // yandex VPN?
        'music/spotify.com.json',
        'socials/facebook.com.json',
        'socials/instagram.com.json',
        'socials/linkedin.com.json',
        'socials/x.com.json',
        'tools/medium.com.json',
        'torrent/rutracker.org.json',
        'video/kino.pub.json',
    ],
    'youtube' => [
        'youtube/youtube.com.json',
    ],
    // Seeded as a copy of 'keenetic'; curated/edited going forward.
    'awg' => [
        'ai/aistudio.google.com.json',
        'ai/chatgpt.com.json',
        'ai/claude.ai.json',
        'ai/grok.com.json',
        'ai/perplexity.ai.json',
        'custom/*.json',
        'discord/*.json',
        'games/roblox.com.json',
        'hosting/bitninja.com.json',
        'hosting/hetzner.com.json',
        'hosting/namecheap.com.json',
        'hosting/cloudlinux.com.json',
        'jetbrains/*.json',
        'messengers/messenger.com.json',
        // 'messengers/telegram.org.json', // use official
        'messengers/whatsapp.com.json', // yandex VPN?
        'music/spotify.com.json',
        'socials/facebook.com.json',
        'socials/instagram.com.json',
        'socials/linkedin.com.json',
        'socials/x.com.json',
        'tools/medium.com.json',
        'torrent/rutracker.org.json',
        'video/kino.pub.json',
    ],
];
```

Note: the `//` comment lines are inside array literals and are inert — `testKeeneticIsVerbatimCuratedSet` compares the resulting values, which exclude the commented `telegram.org.json` entry, exactly as the old `defaultConfigPatterns()` did.

- [ ] **Step 4: Run the test to verify it passes**

Run: `composer test -- --filter ConfigListsTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add config/lists.php test/Scripts/ConfigListsTest.php
git commit -m "feat: config/lists.php single source (keenetic/youtube/awg)"
```

---

### Task 3: Rewire keenetic script to `--list`; remove `defaultConfig*` from lib

**Files:**
- Modify: `scripts/build-keenetic-routes-from-cidr4.php:23-34` (arg parsing + path selection) and `:24-28` (usage text)
- Modify: `scripts/lib/cidr4.php:14-63` (remove `defaultConfigFiles` + `defaultConfigPatterns`)
- Test: `test/Scripts/KeeneticCliTest.php`

**Interfaces:**
- Consumes: `resolveList(string, string, ?string): array` (Task 1); `config/lists.php` (Task 2).
- Produces: keenetic script that defaults to `--list=keenetic`, honours explicit file args over `--list`, and exits non-zero on an unknown list.

- [ ] **Step 1: Write the failing test**

Create `test/Scripts/KeeneticCliTest.php`:

```php
<?php

declare(strict_types=1);

namespace OpenCCK\Scripts;

use PHPUnit\Framework\TestCase;

final class KeeneticCliTest extends TestCase {
    private const SCRIPT = __DIR__ . '/../../scripts/build-keenetic-routes-from-cidr4.php';

    /** @return array{0:string,1:int} stdout, exit code */
    private function run(array $args): array {
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
        [$stdout, $rc] = $this->run([]); // no args => --list=keenetic
        self::assertSame(0, $rc);
        self::assertStringContainsString('route add ', $stdout);
    }

    public function testExplicitListNameEmitsRoutes(): void {
        [$stdout, $rc] = $this->run(['--list=youtube']);
        self::assertSame(0, $rc);
        self::assertStringContainsString('route add ', $stdout);
    }

    public function testUnknownListExitsNonZero(): void {
        [, $rc] = $this->run(['--list=does-not-exist']);
        self::assertNotSame(0, $rc);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer test -- --filter KeeneticCliTest`
Expected: FAIL — `--list=youtube`/`--list=does-not-exist` are treated as file paths by the current script (unknown list is not yet handled), so exit codes / output are wrong.

- [ ] **Step 3: Rewire the keenetic script's argument handling**

In `scripts/build-keenetic-routes-from-cidr4.php`, replace lines 23-34:

```php
$args = array_slice($argv, 1);
if (in_array('-h', $args, true) || in_array('--help', $args, true)) {
    fwrite(STDERR, c("Usage: php scripts/build-keenetic-routes-from-cidr4.php [--list=<name>] [config.json ...]", "36") . "\n");
    fwrite(STDERR, c("If no files are provided, the default is --list=keenetic.", "36") . "\n");
    fwrite(STDERR, c("Explicit config files take precedence over --list. Lists are defined in config/lists.php.", "36") . "\n");
    exit(0);
}

$list = 'keenetic';
$paths = [];
foreach ($args as $arg) {
    if (str_starts_with($arg, '--list=')) {
        $list = substr($arg, 7);
    } else {
        $paths[] = $arg;
    }
}

if ($paths === []) {
    try {
        $paths = resolveList($list, __DIR__ . '/../config');
    } catch (\RuntimeException $e) {
        fwrite(STDERR, c($e->getMessage(), "1;31") . "\n");
        exit(1);
    }
}
if ($paths === []) {
    fwrite(STDERR, c("No input files found.", "1;31") . "\n");
    exit(1);
}
```

- [ ] **Step 4: Remove the dead resolvers from the lib**

In `scripts/lib/cidr4.php`, delete `defaultConfigFiles()` (lines 11-31 including its docblock) and `defaultConfigPatterns()` (lines 33-63 including its docblock). Leave `loadCheckRanges()` and everything below intact.

- [ ] **Step 5: Run the keenetic tests**

Run: `composer test -- --filter KeeneticCliTest`
Expected: PASS (3 tests).

Run: `composer test -- --filter KeeneticRegressionTest`
Expected: PASS — explicit-file path is unchanged, output still byte-identical.

- [ ] **Step 6: Run the full suite**

Run: `composer test`
Expected: PASS — nothing else referenced `defaultConfigFiles`/`defaultConfigPatterns` (verified: only this script did).

- [ ] **Step 7: Commit**

```bash
git add scripts/build-keenetic-routes-from-cidr4.php scripts/lib/cidr4.php test/Scripts/KeeneticCliTest.php
git commit -m "feat: keenetic script uses --list; drop defaultConfig* resolvers"
```

---

### Task 4: Rewire `build-cidr4-list.php` to `--list`; remove `allConfigFiles`

**Files:**
- Modify: `scripts/build-cidr4-list.php:24-46` (arg parsing + path selection) and `:31-34` (usage text)
- Modify: `scripts/lib/cidr4.php:395-412` (remove `allConfigFiles`)
- Modify: `test/Scripts/Cidr4LibTest.php:50-56` (remove the `allConfigFiles` test)
- Test: `test/Scripts/BuildCidr4ListTest.php` (add assertions)

**Interfaces:**
- Consumes: `resolveList(string, string, ?string): array` (Task 1); `config/lists.php` (Task 2).
- Produces: AWG script that defaults to `--list=awg`, honours explicit file args over `--list`, and exits non-zero on an unknown list.

- [ ] **Step 1: Write the failing tests**

In `test/Scripts/BuildCidr4ListTest.php`, add two methods inside the class (after `testExitsNonZeroOnBadConfig`):

```php
    public function testDefaultListAwgGeneratesLst(): void {
        $lst = tempnam(sys_get_temp_dir(), 'awglst');
        $nft = tempnam(sys_get_temp_dir(), 'awgnft');
        $cmd = 'NO_COLOR=1 php ' . escapeshellarg(self::SCRIPT)
            . ' --lst=' . escapeshellarg($lst)
            . ' --nft=' . escapeshellarg($nft)
            . ' 2>/dev/null'; // no config args, no --list => default --list=awg
        $rc = 0;
        $out = [];
        exec($cmd, $out, $rc);
        $lstBody = (string) file_get_contents($lst);
        @unlink($lst);
        @unlink($nft);
        self::assertSame(0, $rc, 'default --list=awg should exit 0');
        self::assertNotSame('', trim($lstBody), 'awg list should produce CIDR output');
    }

    public function testUnknownListExitsNonZero(): void {
        $cmd = 'NO_COLOR=1 php ' . escapeshellarg(self::SCRIPT)
            . ' --lst=/dev/null --nft=/dev/null --list=does-not-exist 2>/dev/null';
        $rc = 0;
        $out = [];
        exec($cmd, $out, $rc);
        self::assertNotSame(0, $rc);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `composer test -- --filter BuildCidr4ListTest`
Expected: FAIL — `--list=does-not-exist` is currently treated as a config path (unknown-list handling absent); default-no-args currently globs all configs rather than the `awg` list.

- [ ] **Step 3: Rewire the AWG script's argument handling**

In `scripts/build-cidr4-list.php`, replace the arg loop and default block (lines 24-46):

```php
$paths = [];
$list = 'awg';

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--lst=')) {
        $lstPath = substr($arg, 6);
    } elseif (str_starts_with($arg, '--nft=')) {
        $nftPath = substr($arg, 6);
    } elseif (str_starts_with($arg, '--list=')) {
        $list = substr($arg, 7);
    } elseif ($arg === '-h' || $arg === '--help') {
        fwrite(STDERR, "Usage: php scripts/build-cidr4-list.php [--list=<name>] [--lst=PATH] [--nft=PATH] [config.json ...]\n");
        fwrite(STDERR, "No config files => --list=awg (the curated AWG set). Lists are defined in config/lists.php.\n");
        fwrite(STDERR, "Explicit config files take precedence over --list.\n");
        exit(0);
    } else {
        $paths[] = $arg;
    }
}

if ($paths === []) {
    try {
        $paths = resolveList($list, $rootDir . '/config');
    } catch (\RuntimeException $e) {
        fwrite(STDERR, cc($e->getMessage(), "1;31") . "\n");
        exit(1);
    }
}
if ($paths === []) {
    fwrite(STDERR, cc("No input config files found.", "1;31") . "\n");
    exit(1);
}
```

- [ ] **Step 4: Remove `allConfigFiles()` from the lib**

In `scripts/lib/cidr4.php`, delete `allConfigFiles()` (its docblock + body, currently the last function, ~lines 395-412).

- [ ] **Step 5: Remove the dead lib test**

In `test/Scripts/Cidr4LibTest.php`, delete `testAllConfigFilesFindsFixtureTree()` (lines 50-56):

```php
    public function testAllConfigFilesFindsFixtureTree(): void {
        $files = allConfigFiles(__DIR__ . '/../fixtures/scripts/config');
        self::assertNotEmpty($files);
        foreach ($files as $f) {
            self::assertStringEndsWith('.json', $f);
        }
    }
```

(`resolveList` coverage in `ListsTest` replaces it.)

- [ ] **Step 6: Run the AWG tests**

Run: `composer test -- --filter BuildCidr4ListTest`
Expected: PASS (4 tests — the two original explicit-file tests plus the two new ones).

- [ ] **Step 7: Run the full suite**

Run: `composer test`
Expected: PASS — no remaining reference to `allConfigFiles` (verified: only this script + the deleted test used it).

- [ ] **Step 8: Commit**

```bash
git add scripts/build-cidr4-list.php scripts/lib/cidr4.php test/Scripts/BuildCidr4ListTest.php test/Scripts/Cidr4LibTest.php
git commit -m "feat: build-cidr4-list uses --list (default awg); drop allConfigFiles"
```

---

### Task 5: Makefile — `LIST` override, `--list` targets, remove `YOUTUBE_CONFIG`

**Files:**
- Modify: `Makefile:10` (remove `YOUTUBE_CONFIG`, add `LIST ?=`), `:32-40` (rewire targets)

**Interfaces:**
- Consumes: the `--list` CLI flag on both scripts (Tasks 3, 4).
- Produces: `keenetic-routes`, `keenetic-youtube`, `awg-all` targets that pass `--list=$(or $(LIST),<default>)`.

- [ ] **Step 1: Remove `YOUTUBE_CONFIG`, add `LIST`**

In `Makefile`, delete line 10 (`YOUTUBE_CONFIG ?= config/youtube/youtube.com.json`) and add, near the other variables (e.g. after `AWG_UPDATE_SCRIPT ?= ...`):

```make
LIST ?=
```

- [ ] **Step 2: Rewire the three targets**

Replace the `keenetic-routes`, `keenetic-youtube`, and `awg-all` recipe bodies:

```make
keenetic-routes: ensure-generated ## Generate CIDR4 routes for the keenetic set (LIST=<name> to override)
	php $(CIDR4_SCRIPT) --list=$(or $(LIST),keenetic) > $(GENERATED_DIR)/routes-cidr4.bat

keenetic-youtube: ensure-generated ## Generate CIDR4 routes for YouTube
	php $(CIDR4_SCRIPT) --list=$(or $(LIST),youtube) > $(GENERATED_DIR)/youtube-routes-cidr4.bat
```

```make
awg-all: ensure-generated ## Generate AWG CIDR list + nftables set (LIST=<name> to override; default awg)
	php $(AWG_CIDR_SCRIPT) --list=$(or $(LIST),awg) --lst=$(AWG_LST) --nft=$(AWG_NFT)
```

- [ ] **Step 3: Verify the recipes resolve correctly (dry run)**

Run:
```bash
make -n keenetic-routes && make -n keenetic-youtube && make -n awg-all && make -n keenetic-routes LIST=awg
```
Expected output includes, respectively:
```
php scripts/build-keenetic-routes-from-cidr4.php --list=keenetic > generated/routes-cidr4.bat
php scripts/build-keenetic-routes-from-cidr4.php --list=youtube > generated/youtube-routes-cidr4.bat
php scripts/build-cidr4-list.php --list=awg --lst=generated/awg-cidr4.lst --nft=generated/awg-set.nft
php scripts/build-keenetic-routes-from-cidr4.php --list=awg > generated/routes-cidr4.bat
```

- [ ] **Step 4: Verify `YOUTUBE_CONFIG` is gone**

Run: `grep -n YOUTUBE_CONFIG Makefile`
Expected: no output (exit 1).

- [ ] **Step 5: Smoke-run the real generators end-to-end**

Run:
```bash
make keenetic-all && make awg-all && composer test
```
Expected: both `make` targets exit 0 and write files under `generated/`; `composer test` PASSES (full suite).

- [ ] **Step 6: Commit**

```bash
git add Makefile
git commit -m "build: Makefile targets pass --list; add LIST override; drop YOUTUBE_CONFIG"
```

---

## Self-Review

**Spec coverage:**
- §1 `config/lists.php` → Task 2 (verbatim keenetic/youtube/awg + pin test).
- §2 `resolveList()` replacing three resolvers → Task 1 (add) + Task 3 (remove `defaultConfig*`) + Task 4 (remove `allConfigFiles`). Excludes `check/`, dedupes, sorts `SORT_STRING`, throws on unknown → Task 1.
- §3 `--list` in both generators, explicit-file precedence, defaults (keenetic/awg), unknown→non-zero → Tasks 3 & 4.
- §4 Makefile `LIST ?=`, rewired targets, `YOUTUBE_CONFIG` removed → Task 5.
- §5 KeeneticRegressionTest kept unchanged (Task 3 Step 5 runs it); new ListsTest with glob-expansion, check-exclusion, unknown-list-throw (Task 1); function-level end-to-end `--list` selection (Task 1 `testListSelectsSetEndToEnd`, per the agreed approach); verbatim guard (Task 2 ConfigListsTest). Fixtures reuse `test/fixtures/scripts/config` + new `test/fixtures/scripts/lists.php`.
- Risks: verbatim keenetic guarded by ConfigListsTest (Task 2) *and* the byte-identical math by KeeneticRegressionTest (Task 3); `build-cidr4-list.php` "no args" now means `awg` (intentional) → Task 4 `testDefaultListAwgGeneratesLst`.

**Placeholder scan:** none — every code/step is concrete.

**Type consistency:** `resolveList(string $name, string $configDir, ?string $listsFile = null): array` used identically in Task 1 (definition), Task 3, and Task 4. `computeEffectiveCidr4($paths, $checkDir, $includeIp4)` used with existing signature. Makefile var `LIST` and `$(or $(LIST),<default>)` consistent across targets.

**Note on removal ordering:** `defaultConfigFiles`/`defaultConfigPatterns` are removed in Task 3 (the task that also removes their only caller), and `allConfigFiles` in Task 4 (same). Task 1 deliberately leaves all three in place so the suite stays green between tasks.
