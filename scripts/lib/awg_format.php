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
