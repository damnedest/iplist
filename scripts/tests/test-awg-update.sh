#!/usr/bin/env bash
# Test scripts/awg-update.sh diff/notify logic against a stub repo (stub Makefile, no
# network, no nft). The CIDR list is in numeric order, NOT lexicographic: comm must
# still produce the right +/- counts.
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
SCRIPT="$HERE/../awg-update.sh"
T="$(mktemp -d)"; trap 'rm -rf "$T"' EXIT
mkdir -p "$T/repo/generated" "$T/state"

# stub Makefile: awg-fetch/awg-reload are no-ops, awg-all copies the "new" fixture
cat > "$T/repo/Makefile" <<'MK'
awg-fetch:
	@true
awg-all:
	@cp new.lst generated/awg-cidr4.lst
awg-reload:
	@echo RELOAD >> reload.log
MK

fail() { echo "FAIL: $*" >&2; exit 1; }
run() { AWG_REPO_DIR="$T/repo" GENERATED_DIR="$T/repo/generated" AWG_SNAPSHOT="$T/state/prev" \
        AWG_ENV_FILE="$T/none" bash "$SCRIPT" --dry-run; }

# numeric CIDR order (as build-cidr4-list.php emits it): "10.x" sorts after "2.x" only
# numerically. 103.23.125.0/24 vs 103.230.0.0/17 is a real pair where C byte order and
# glibc en_US.UTF-8 collation disagree: GNU comm run under en_US on a C-sorted file
# aborts with "not in sorted order" (this is what broke the daily timer on Server 1).
printf '1.0.0.0/9\n2.16.0.0/13\n3.0.0.0/8\n10.0.0.0/8\n103.23.125.0/24\n103.230.0.0/17\n104.16.0.0/12\n' > "$T/state/prev"
printf '1.0.0.0/9\n2.16.0.0/13\n3.0.0.0/8\n5.101.152.0/24\n10.0.0.0/8\n103.23.125.0/24\n103.230.0.0/17\n104.16.0.0/12\n185.0.0.0/8\n' > "$T/repo/new.lst"

# 1. changed list -> +2 / -0, exit 0, no comm errors
out="$(run 2>&1)" || fail "script exited non-zero:"$'\n'"$out"
echo "$out" | grep -q 'comm:' && fail "comm complained about ordering:"$'\n'"$out"
echo "$out" | grep -q 'awg CIDR updated: +2 / -0' || fail "wrong counts:"$'\n'"$out"
echo "$out" | grep -q '^+ 5.101.152.0/24' || fail "sample missing added entry:"$'\n'"$out"
echo "$out" | grep -q '^+ 185.0.0.0/8'    || fail "sample missing added entry 2:"$'\n'"$out"
cmp -s "$T/state/prev" "$T/repo/generated/awg-cidr4.lst" || fail "snapshot not updated"

# 2. removal -> +0 / -1
printf '1.0.0.0/9\n2.16.0.0/13\n3.0.0.0/8\n5.101.152.0/24\n103.23.125.0/24\n103.230.0.0/17\n104.16.0.0/12\n185.0.0.0/8\n' > "$T/repo/new.lst"
out="$(run 2>&1)" || fail "script exited non-zero (removal):"$'\n'"$out"
echo "$out" | grep -q 'awg CIDR updated: +0 / -1' || fail "wrong counts (removal):"$'\n'"$out"
echo "$out" | grep -q '^- 10.0.0.0/8' || fail "sample missing removed entry:"$'\n'"$out"

# 3. unchanged -> "No CIDR changes."
out="$(run 2>&1)" || fail "script exited non-zero (unchanged):"$'\n'"$out"
echo "$out" | grep -q 'No CIDR changes' || fail "expected no-change path:"$'\n'"$out"

echo "OK: all awg-update tests passed"
