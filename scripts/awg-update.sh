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

# The list is in numeric CIDR order, but comm needs sorted input (GNU comm aborts on
# unsorted files, BSD comm silently miscounts). Sort AND compare under LC_ALL=C: the
# order check inside comm follows the locale too, and en_US.UTF-8 collation disagrees
# with a C-sorted file on '.' and '/'.
OLD_SORTED="$(mktemp)"; NEW_SORTED="$(mktemp)"
trap 'rm -f "$OLD_SORTED" "$NEW_SORTED"' EXIT
LC_ALL=C sort -u "$SNAPSHOT" > "$OLD_SORTED"
LC_ALL=C sort -u "$LST"      > "$NEW_SORTED"
ccomm() { LC_ALL=C comm "$@" "$OLD_SORTED" "$NEW_SORTED"; }
added="$(ccomm -13 | wc -l | tr -d ' ')"
removed="$(ccomm -23 | wc -l | tr -d ' ')"
sample="$(
    { ccomm -13 | sed 's/^/+ /'; ccomm -23 | sed 's/^/- /'; } | head -30
)"
total_changes=$(( added + removed ))
suffix=""
[ "$total_changes" -gt 30 ] && suffix=$'\n…and '"$(( total_changes - 30 ))"" more"

[ "$DRY_RUN" = "1" ] || make awg-reload
cp "$LST" "$SNAPSHOT"
notify "⚙️ awg CIDR updated: +${added} / -${removed}"$'\n'"${sample}${suffix}"
echo "Reloaded: +${added}/-${removed}"
