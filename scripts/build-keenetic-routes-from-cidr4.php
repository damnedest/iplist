#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Builds a unified CIDR4 route list for Keenetic .bat from config cidr4 entries.
 *
 * Usage:
 *   php scripts/build-keenetic-routes-from-cidr4.php
 *   php scripts/build-keenetic-routes-from-cidr4.php config/ai/chatgpt.com.json config/ai/claude.ai.json
 */

require_once __DIR__ . '/lib/cidr4.php';

$useColor = PHP_SAPI === 'cli' && stream_isatty(STDERR) && getenv('NO_COLOR') === false;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, c("This script must be run from CLI.", "1;31") . "\n");
    exit(1);
}

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

$cidrs = [];
$inputCidrs = [];
$errors = [];
$ip4Entries = 0;
$cidr4Entries = 0;
$perFileIp4Entries = [];
$perFileCidr4Entries = [];

foreach ($paths as $path) {
    $perFileIp4Entries[$path] = 0;
    $perFileCidr4Entries[$path] = 0;

    if (!is_file($path) || !is_readable($path)) {
        $errors[] = "Input file is not readable: {$path}";
        continue;
    }

    $raw = file_get_contents($path);
    if ($raw === false) {
        $errors[] = "Failed to read input file: {$path}";
        continue;
    }

    $config = json_decode($raw, true);
    if (!is_array($config)) {
        $errors[] = "Invalid JSON in {$path}: " . json_last_error_msg();
        continue;
    }

    $ip4 = $config['ip4'] ?? [];
    if (!is_array($ip4)) {
        $errors[] = "Invalid config in {$path}: ip4 must be an array.";
        continue;
    }

    foreach ($ip4 as $ip) {
        if (!is_string($ip) || !isValidIp4($ip)) {
            $errors[] = "Invalid ip4 entry in {$path}: " . stringify($ip);
            continue;
        }
        $ip4Entries++;
        $perFileIp4Entries[$path]++;
    }

    $cidr4 = $config['cidr4'] ?? [];
    if (!is_array($cidr4)) {
        $errors[] = "Invalid config in {$path}: cidr4 must be an array.";
        continue;
    }

    foreach ($cidr4 as $cidr) {
        if (!is_string($cidr) || parseCidr4($cidr) === null) {
            $errors[] = "Invalid cidr4 entry in {$path}: " . stringify($cidr);
            continue;
        }
        $cidr4Entries++;
        $perFileCidr4Entries[$path]++;
        $cidrs[$cidr] = true;
        $inputCidrs[] = ['cidr' => $cidr, 'file' => $path, 'range' => parseCidr4($cidr)];
    }
}

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, c($error, "1;31") . "\n");
    }
    exit(1);
}

$checkRanges = loadCheckRanges(__DIR__ . '/../config/check');
$intersectionGroups = [];
$hitCount = 0;
$replacementByCidr = [];
foreach ($inputCidrs as $input) {
    [$aStart, $aEnd] = $input['range'];
    $overlapping = [];
    foreach ($checkRanges as $check) {
        [$bStart, $bEnd] = $check['range'];
        if (rangesIntersect($aStart, $aEnd, $bStart, $bEnd)) {
            $overlapping[] = $check;
        }
    }
    if ($overlapping === []) {
        continue;
    }

    $hitCount += count($overlapping);
    $intersectionGroups[] = ['input' => $input, 'checks' => $overlapping];

    if (!array_key_exists($input['cidr'], $replacementByCidr)) {
        $remaining = subtractRange($input['range'], array_map(fn(array $c): array => $c['range'], $overlapping));
        $replacementCidrs = [];
        foreach ($remaining as [$rStart, $rEnd]) {
            foreach (rangeToCidrs($rStart, $rEnd) as $cidr) {
                $replacementCidrs[] = $cidr;
            }
        }
        $replacementByCidr[$input['cidr']] = $replacementCidrs;
    }
}

if ($intersectionGroups === []) {
    fwrite(STDERR, c("Intersections with config/check: none", "32") . "\n");
} else {
    fwrite(STDERR, c("Intersections with config/check (" . $hitCount . "):", "1;31") . "\n");
    foreach ($intersectionGroups as $group) {
        $input = $group['input'];
        foreach ($group['checks'] as $check) {
            fwrite(
                STDERR,
                "  " . c(displayPath($input['file']), "2") . "  " . c($input['cidr'], "1;31") .
                    "  " . c("overlaps", "31") . "  " . c(displayPath($check['file']), "2") . "  " . c($check['cidr'], "1;31") . "\n"
            );
        }
        $replacement = $replacementByCidr[$input['cidr']];
        $prefix = c("    → replaced " . $input['cidr'] . " with: ", "2");
        if ($replacement === []) {
            fwrite(STDERR, $prefix . c("(removed — fully inside check)", "33") . "\n");
        } else {
            fwrite(STDERR, $prefix . c(implode("  ", $replacement), "32") . "\n");
        }
    }
}

$uniqueCidr4 = count($cidrs);

$effectiveCidrs = [];
foreach (array_keys($cidrs) as $cidr) {
    if (array_key_exists($cidr, $replacementByCidr)) {
        foreach ($replacementByCidr[$cidr] as $replacement) {
            $effectiveCidrs[] = $replacement;
        }
    } else {
        $effectiveCidrs[] = $cidr;
    }
}

$routes = 0;
foreach (aggregateCidr4($effectiveCidrs) as $cidr) {
    echo renderKeeneticRoute($cidr);
    $routes++;
}

fwrite(STDERR, c("Files:", "1;36") . " " . c((string) count($paths), "1") . "\n");
fwrite(STDERR, c("Entries by file:", "1;36") . "\n");
foreach ($paths as $path) {
    fwrite(
        STDERR,
        "  " . c(displayPath($path), "2") .
            ": ip4=" . c((string) ($perFileIp4Entries[$path] ?? 0), "1") .
            ", cidr4=" . c((string) ($perFileCidr4Entries[$path] ?? 0), "1") .
            "\n"
    );
}
fwrite(STDERR, c("IP4 entries:", "36") . " " . c((string) $ip4Entries, "1") . "\n");
fwrite(STDERR, c("CIDR4 entries:", "36") . " " . c((string) $cidr4Entries, "1") . "\n");
fwrite(STDERR, c("Unique CIDR4:", "36") . " " . c((string) $uniqueCidr4, "1") . "\n");
fwrite(STDERR, c("Final CIDR4 routes:", "36") . " " . c((string) $routes, "1") . "\n");
fwrite(STDERR, c("Compression vs IP4 entries:", "36") . " " . c(formatCompression($ip4Entries, $routes), "32") . "\n");
fwrite(STDERR, c("Merge vs unique CIDR4:", "36") . " " . c(formatCompression($uniqueCidr4, $routes), "32") . "\n");

exit(0);

function renderKeeneticRoute(string $cidr): string {
    [$network, $prefix] = explode('/', $cidr, 2);
    return 'route add ' . $network . ' mask ' . prefixToDottedMask((int) $prefix) . " 0.0.0.0\n";
}

/**
 * Wraps text in an ANSI SGR sequence when color output is enabled.
 * $code is a raw SGR parameter string, e.g. "1;31", "33", "2".
 */
function c(string $text, string $code): string {
    global $useColor;
    return $useColor ? "\033[{$code}m{$text}\033[0m" : $text;
}

function prefixToDottedMask(int $prefix): string {
    if ($prefix <= 0) {
        return '0.0.0.0';
    }
    if ($prefix >= 32) {
        return '255.255.255.255';
    }

    $mask = (0xffffffff << (32 - $prefix)) & 0xffffffff;
    return long2ip($mask);
}

function formatCompression(int $before, int $after): string {
    if ($before === 0) {
        return 'n/a';
    }

    $ratio = $after === 0 ? 0.0 : $before / $after;
    $reduction = (1 - ($after / $before)) * 100;

    return sprintf('%.2fx, %.2f%% fewer routes', $ratio, $reduction);
}

function displayPath(string $path): string {
    $root = realpath(__DIR__ . '/..');
    $real = realpath($path);

    if ($root !== false && $real !== false && str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
        return substr($real, strlen($root) + 1);
    }

    return $path;
}

function stringify(mixed $value): string {
    if (is_scalar($value) || $value === null) {
        return var_export($value, true);
    }

    return gettype($value);
}
