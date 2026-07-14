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
