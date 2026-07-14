<?php

declare(strict_types=1);

/**
 * Shared IPv4 / CIDR helpers for the route generators. Pure functions, no I/O on
 * include. Required by scripts/build-keenetic-routes-from-cidr4.php and
 * scripts/build-cidr4-list.php.
 */

/**
 * @return array<int, array{file: string, cidr: string, range: array{int, int}}>
 */
function loadCheckRanges(string $checkDir): array {
    $ranges = [];
    if (!is_dir($checkDir)) {
        return $ranges;
    }

    $files = glob($checkDir . '/*.json', GLOB_NOSORT) ?: [];
    sort($files, SORT_STRING);

    foreach ($files as $path) {
        if (!is_file($path) || !is_readable($path)) {
            fwrite(STDERR, c("Warning: check file is not readable: " . displayPath($path), "33") . "\n");
            continue;
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            fwrite(STDERR, c("Warning: failed to read check file: " . displayPath($path), "33") . "\n");
            continue;
        }

        $config = json_decode($raw, true);
        if (!is_array($config)) {
            fwrite(STDERR, c("Warning: invalid JSON in check file " . displayPath($path) . ": " . json_last_error_msg(), "33") . "\n");
            continue;
        }

        $cidr4 = $config['cidr4'] ?? [];
        if (!is_array($cidr4)) {
            fwrite(STDERR, c("Warning: cidr4 must be an array in check file " . displayPath($path), "33") . "\n");
            continue;
        }

        foreach ($cidr4 as $cidr) {
            $range = is_string($cidr) ? parseCidr4($cidr) : null;
            if ($range === null) {
                fwrite(STDERR, c("Warning: invalid cidr4 entry in check file " . displayPath($path) . ": " . stringify($cidr), "33") . "\n");
                continue;
            }
            $ranges[] = ['file' => $path, 'cidr' => $cidr, 'range' => $range];
        }
    }

    return $ranges;
}

/**
 * @param array<int, string> $cidrs
 * @return iterable<string>
 */
function aggregateCidr4(array $cidrs): iterable {
    $ranges = [];
    foreach ($cidrs as $cidr) {
        $range = parseCidr4($cidr);
        if ($range !== null) {
            $ranges[] = $range;
        }
    }

    if ($ranges === []) {
        return;
    }

    usort($ranges, fn(array $a, array $b): int => $a[0] === $b[0] ? $a[1] <=> $b[1] : $a[0] <=> $b[0]);

    $rangeStart = $ranges[0][0];
    $rangeEnd = $ranges[0][1];
    $count = count($ranges);

    for ($i = 1; $i < $count; $i++) {
        [$start, $end] = $ranges[$i];
        if ($start <= $rangeEnd + 1) {
            if ($end > $rangeEnd) {
                $rangeEnd = $end;
            }
            continue;
        }

        foreach (rangeToCidrs($rangeStart, $rangeEnd) as $cidr) {
            yield $cidr;
        }
        $rangeStart = $start;
        $rangeEnd = $end;
    }

    foreach (rangeToCidrs($rangeStart, $rangeEnd) as $cidr) {
        yield $cidr;
    }
}

/**
 * @return array{int, int}|null
 */
function parseCidr4(string $cidr): ?array {
    $parts = explode('/', $cidr, 2);
    if (count($parts) !== 2) {
        return null;
    }

    [$network, $prefixRaw] = $parts;
    if (!isValidIp4($network) || !preg_match('/^\d+$/', $prefixRaw)) {
        return null;
    }

    $prefix = (int) $prefixRaw;
    if ($prefix < 0 || $prefix > 32) {
        return null;
    }

    $networkLong = ip4ToUInt($network);
    if ($networkLong === null) {
        return null;
    }

    $mask = cidrMask($prefix);
    $start = $networkLong & $mask;
    $end = $start | (0xffffffff ^ $mask);

    return [$start, $end];
}

function rangesIntersect(int $aStart, int $aEnd, int $bStart, int $bEnd): bool {
    return $aStart <= $bEnd && $bStart <= $aEnd;
}

/**
 * @return array<int, string>
 */
function rangeToCidrs(int $start, int $end): array {
    $cidrs = [];

    while ($start <= $end) {
        $maxByAlignment = $start === 0 ? 32 : trailingZeros($start);
        $maxByRemaining = log2Floor($end - $start + 1);
        $hostBits = min($maxByAlignment, $maxByRemaining);

        $cidrs[] = long2ip($start) . '/' . (32 - $hostBits);

        if ($hostBits >= 32) {
            break;
        }
        $start += 1 << $hostBits;
    }

    return $cidrs;
}

/**
 * Returns $input minus the union of $subtrahends as a list of [start, end] ranges.
 *
 * @param array{int, int}              $input
 * @param array<int, array{int, int}>  $subtrahends
 * @return array<int, array{int, int}>
 */
function subtractRange(array $input, array $subtrahends): array {
    [$start, $end] = $input;

    $clamped = [];
    foreach ($subtrahends as [$subStart, $subEnd]) {
        $lo = max($subStart, $start);
        $hi = min($subEnd, $end);
        if ($lo <= $hi) {
            $clamped[] = [$lo, $hi];
        }
    }

    usort($clamped, fn(array $a, array $b): int => $a[0] <=> $b[0]);

    $result = [];
    $cursor = $start;
    foreach ($clamped as [$subStart, $subEnd]) {
        if ($subStart > $cursor) {
            $result[] = [$cursor, $subStart - 1];
        }
        if ($subEnd + 1 > $cursor) {
            $cursor = $subEnd + 1;
        }
    }
    if ($cursor <= $end) {
        $result[] = [$cursor, $end];
    }

    return $result;
}

function isValidIp4(string $ip): bool {
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
}

function ip4ToUInt(string $ip): ?int {
    $long = ip2long($ip);
    if ($long === false) {
        return null;
    }

    return (int) sprintf('%u', $long);
}

function cidrMask(int $prefix): int {
    if ($prefix === 0) {
        return 0;
    }

    return (0xffffffff << (32 - $prefix)) & 0xffffffff;
}

function trailingZeros(int $value): int {
    if ($value === 0) {
        return 32;
    }

    $count = 0;
    while (($value & 1) === 0) {
        $count++;
        $value >>= 1;
    }

    return $count;
}

function log2Floor(int $value): int {
    $result = 0;
    while ($value > 1) {
        $value >>= 1;
        $result++;
    }

    return $result;
}

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
