# Unified Config Lists — Design

**Date:** 2026-07-09
**Status:** Approved (pending spec review)

## Problem

The "which config files feed which output" logic is scattered across three places:

- `scripts/lib/cidr4.php` — `defaultConfigPatterns()` hard-codes the curated keenetic set.
- `Makefile` — `YOUTUBE_CONFIG` hard-codes the single youtube config path.
- `build-cidr4-list.php` / `allConfigFiles()` — AWG uses a `config/*/*.json` glob ("everything").

There is no single place to see or edit these sets, and adding a new output means touching PHP and Make. We want one common file that names each set, and a single `--list=<name>` switch shared by both generators.

## Goal

- One file, `config/lists.php`, defines every named config set.
- Both generators accept `--list=<name>` to pick a set.
- Remove the hard-coded keenetic patterns from the lib and `YOUTUBE_CONFIG` from the Makefile.
- Keenetic output stays byte-identical (existing golden guard must keep passing).

## Design

### 1. `config/lists.php` (the single source)

Lives with the configs ("common place"). Pure data, no side effects — returns a map of
`list name → array of paths/globs`, each relative to `config/`:

```php
<?php
declare(strict_types=1);
return [
    'keenetic' => [ /* the current defaultConfigPatterns() entries, verbatim */ ],
    'youtube'  => ['youtube/youtube.com.json'],
    'awg'      => [ /* seeded as a copy of 'keenetic' for now; curated/edited later */ ],
];
```

- Entries may be exact files (`ai/chatgpt.com.json`) or globs (`discord/*.json`, `custom/*.json`).
- `keenetic` is seeded verbatim from today's `defaultConfigPatterns()` so keenetic output does not change.
- `awg` is seeded as a copy of `keenetic`'s entries (agreed starting point; the user curates it going forward).

### 2. `resolveList()` in `scripts/lib/cidr4.php`

One resolver replaces `defaultConfigFiles()`, `defaultConfigPatterns()`, and `allConfigFiles()`:

```
resolveList(string $name, string $configDir, ?string $listsFile = null): string[]
```

- Loads the lists map (`$listsFile` defaults to `$configDir/lists.php`).
- Throws `\RuntimeException` with a clear message if `$name` is not a defined list.
- Expands every entry via `glob($configDir . '/' . $entry)`, keeps only real files.
- **Always excludes anything under `config/check/`** — that directory is the punch-out
  source, never a routing source (guards against a broad glob pulling it in).
- Dedupes and sorts (`SORT_STRING`), returns a list of absolute-ish paths (same shape
  `defaultConfigFiles()` returned).

`defaultConfigFiles()`, `defaultConfigPatterns()`, and `allConfigFiles()` are removed;
`resolveList()` is the single entry point. (The keenetic script's inline config-reading
loop and `computeEffectiveCidr4()` are unchanged — they still take a list of file paths.)

### 3. `--list=<name>` in both generators

Both `build-keenetic-routes-from-cidr4.php` and `build-cidr4-list.php`:

- Accept `--list=<name>` → `resolveList($name, …)`.
- Explicit file arguments still work and **take precedence** over `--list` (debug one config).
- Default when neither is given:
  - keenetic script → `--list=keenetic`
  - `build-cidr4-list.php` → `--list=awg`
- Unknown list name → print the resolver's error to STDERR, exit non-zero.

### 4. Makefile

- Add `LIST ?=` (empty by default) so a target can be overridden: `make keenetic-routes LIST=awg`.
- Rewire targets to pass `--list`:
  - `keenetic-routes` → `php $(CIDR4_SCRIPT) --list=$(or $(LIST),keenetic)`
  - `keenetic-youtube` → `php $(CIDR4_SCRIPT) --list=$(or $(LIST),youtube)`
  - `awg-all` → `php $(AWG_CIDR_SCRIPT) --list=$(or $(LIST),awg) --lst=$(AWG_LST) --nft=$(AWG_NFT)`
- Remove `YOUTUBE_CONFIG`.

### 5. Tests

- **Keep** `KeeneticRegressionTest` unchanged — it passes explicit fixture files (not `--list`),
  so it independently proves keenetic output is unaffected by the refactor.
- **New** `ListsTest` (`test/Scripts/ListsTest.php`) against a small fixture `lists.php`:
  - `resolveList` expands globs to files.
  - `resolveList` excludes `check/`.
  - `resolveList` throws on an unknown list name.
- **New** assertion that `--list=<name>` selects the intended set end-to-end (e.g. run
  `build-cidr4-list.php --list=<fixture-list>` against a fixture config tree + fixture lists file
  and check the emitted CIDRs). Fixtures: reuse `test/fixtures/scripts/config` plus a
  `test/fixtures/scripts/lists.php`.

## Non-goals

- No change to CIDR math, aggregation, punch-out, or output formats.
- No change to `awg-update.sh` or the deploy artifacts.
- No IPv6.

## Risks / Notes

- The `keenetic` list must be copied **verbatim** from `defaultConfigPatterns()` or the golden
  test fails — that test is the safety net for this refactor.
- `--list` default for `build-cidr4-list.php` changes its "no args" behaviour from "all configs"
  to "the `awg` curated list". This is intentional (AWG is now curated, per the agreed design).
