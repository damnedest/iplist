#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Checks that every ip4 entry in a site config is covered by at least one cidr4 entry.
 *
 * Usage:
 *   php scripts/check-ip4-covered-by-cidr4.php config/ai/chatgpt.com.json
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from CLI.\n");
    exit(1);
}

$path = $argv[1] ?? null;
if ($path === null || in_array($path, ['-h', '--help'], true)) {
    fwrite(STDERR, "Usage: php scripts/check-ip4-covered-by-cidr4.php <config.json>\n");
    exit($path === null ? 1 : 0);
}

if (!is_file($path) || !is_readable($path)) {
    fwrite(STDERR, "Input file is not readable: {$path}\n");
    exit(1);
}

$raw = file_get_contents($path);
if ($raw === false) {
    fwrite(STDERR, "Failed to read input file: {$path}\n");
    exit(1);
}

$config = json_decode($raw, true);
if (!is_array($config)) {
    fwrite(STDERR, "Invalid JSON in {$path}: " . json_last_error_msg() . "\n");
    exit(1);
}

$ip4 = $config['ip4'] ?? [];
$cidr4 = $config['cidr4'] ?? [];

if (!is_array($ip4)) {
    fwrite(STDERR, "Invalid config: ip4 must be an array.\n");
    exit(1);
}
if (!is_array($cidr4)) {
    fwrite(STDERR, "Invalid config: cidr4 must be an array.\n");
    exit(1);
}

$invalidIp4 = [];
$invalidCidr4 = [];
$validIp4 = [];
$validCidr4 = [];

foreach ($ip4 as $ip) {
    if (!is_string($ip) || !isValidIp4($ip)) {
        $invalidIp4[] = stringify($ip);
        continue;
    }
    $validIp4[] = $ip;
}

foreach ($cidr4 as $cidr) {
    if (!is_string($cidr) || parseCidr4($cidr) === null) {
        $invalidCidr4[] = stringify($cidr);
        continue;
    }
    $validCidr4[] = $cidr;
}

$uncovered = [];
foreach ($validIp4 as $ip) {
    if (!isIpCoveredByCidrs($ip, $validCidr4)) {
        $uncovered[] = $ip;
    }
}

echo "File: {$path}\n";
echo "ip4 entries: " . count($ip4) . "\n";
echo "cidr4 entries: " . count($cidr4) . "\n";
echo "invalid ip4: " . count($invalidIp4) . "\n";
echo "invalid cidr4: " . count($invalidCidr4) . "\n";
echo "uncovered ip4: " . count($uncovered) . "\n";

if ($invalidIp4 !== []) {
    echo "\nInvalid ip4 entries:\n";
    foreach ($invalidIp4 as $ip) {
        echo "  {$ip}\n";
    }
}

if ($invalidCidr4 !== []) {
    echo "\nInvalid cidr4 entries:\n";
    foreach ($invalidCidr4 as $cidr) {
        echo "  {$cidr}\n";
    }
}

if ($uncovered !== []) {
    echo "\nUncovered ip4 entries:\n";
    foreach ($uncovered as $ip) {
        echo "  {$ip}\n";
    }
}

if ($invalidIp4 !== [] || $invalidCidr4 !== [] || $uncovered !== []) {
    exit(1);
}

echo "\nOK: all ip4 entries are covered by cidr4.\n";
exit(0);

function isIpCoveredByCidrs(string $ip, array $cidrs): bool {
    $ipLong = ip4ToUInt($ip);
    if ($ipLong === null) {
        return false;
    }

    foreach ($cidrs as $cidr) {
        $parsed = parseCidr4($cidr);
        if ($parsed === null) {
            continue;
        }

        [$networkLong, $prefix] = $parsed;
        $mask = cidrMask($prefix);
        if (($ipLong & $mask) === ($networkLong & $mask)) {
            return true;
        }
    }

    return false;
}

function isValidIp4(string $ip): bool {
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
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

    return [$networkLong, $prefix];
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

function stringify(mixed $value): string {
    if (is_scalar($value) || $value === null) {
        return var_export($value, true);
    }

    return gettype($value);
}
