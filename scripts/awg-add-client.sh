#!/usr/bin/env bash
# awg-add-client.sh — issue / show / remove AmneziaWG 3.1 client peers on the front.
#
#   awg-add-client.sh [--dns IP] [--mtu N] [--no-qr] <name>   add (or show if exists)
#   awg-add-client.sh --remove <name>                          remove peer + client file
#
# The obfuscation profile is copied verbatim from the server [Interface], so client
# and server can never drift. Live changes go through `awg set` FIRST; the server
# config is only edited after the live change succeeded.
set -euo pipefail

AWG_BIN="${AWG_BIN:-awg}"
AWG_IFACE="${AWG_IFACE:-awg0}"
AWG_CONF="${AWG_CONF:-/etc/amnezia/amneziawg/${AWG_IFACE}.conf}"
CLIENT_DIR="${AWG_CLIENT_DIR:-/etc/amnezia/amneziawg/clients}"
ENDPOINT_HOST="${AWG_ENDPOINT_HOST:-}"
ENDPOINT_HOST_FILE="${AWG_ENDPOINT_HOST_FILE:-/etc/amnezia/amneziawg/endpoint-host}"
CLIENT_NET="${AWG_CLIENT_NET:-10.9.0}"
DNS="${AWG_DNS:-1.1.1.1}"
MTU="${AWG_MTU:-1280}"
QR="${AWG_QR:-1}"

# Keys copied from server [Interface] into every client [Interface] (order kept).
PROFILE_KEYS="Jc Jmin Jmax S1 S2 S3 S4 H1 H2 H3 H4 I1 I2 I3 I4 I5 HeaderProtectionKey ContentPaddingAddition RekeyAfterTime RekeyTimeout RejectAfterTime KeepaliveTimeout MaxHandshakeAttempts RandomTrailers DisableCookies"

die() { echo "awg-add-client: $*" >&2; exit 1; }

usage() {
    sed -n '2,6p' "$0" | sed 's/^# \{0,1\}//'
    exit 2
}

# Value of KEY in the server [Interface] section (stops at first [Peer]).
iface_value() {
    awk -v key="$1" '
        /^\[Peer\]/ { exit }
        /^[ \t]*#/ { next }
        {
            i = index($0, "="); if (i == 0) next
            k = substr($0, 1, i - 1); v = substr($0, i + 1)
            gsub(/^[ \t]+|[ \t]+$/, "", k); gsub(/^[ \t]+|[ \t]+$/, "", v)
            sub(/[ \t]+#.*$/, "", v)
            if (k == key) { print v; exit }
        }' "$AWG_CONF"
}

# Public key of the peer whose block carries "# client: NAME".
peer_pubkey() {
    awk -v n="$1" '
        /^\[Peer\]/ { inpeer = 1; found = 0; next }
        inpeer && $0 == "# client: " n { found = 1; next }
        inpeer && found && /^PublicKey[ \t]*=/ {
            i = index($0, "="); v = substr($0, i + 1); gsub(/^[ \t]+|[ \t]+$/, "", v); print v; exit
        }' "$AWG_CONF"
}

# Rewrite server conf without the [Peer] block of NAME (keeps perms/inode).
delete_peer_block() {
    local tmp; tmp="$(mktemp)"
    awk -v n="$1" '
        /^\[Peer\]/ { if (buf != "" && !skip) printf "%s", buf; buf = $0 "\n"; skip = 0; inpeer = 1; next }
        inpeer { buf = buf $0 "\n"; if ($0 == "# client: " n) skip = 1; next }
        { print }
        END { if (buf != "" && !skip) printf "%s", buf }' "$AWG_CONF" > "$tmp"
    cat "$tmp" > "$AWG_CONF"; rm -f "$tmp"
}

next_free_ip() {
    local used i esc
    esc="$(printf '%s' "$CLIENT_NET" | sed 's/\./\\./g')"
    used="$(grep -E "^AllowedIPs[[:space:]]*=[[:space:]]*${esc}\.[0-9]+/32" "$AWG_CONF" \
            | sed -E 's/.*\.([0-9]+)\/32.*/\1/' || true)"
    i=2
    while [ "$i" -le 254 ]; do
        if ! printf '%s\n' "$used" | grep -qx "$i"; then echo "$CLIENT_NET.$i"; return 0; fi
        i=$((i + 1))
    done
    return 1
}

show_client() {
    local f="$CLIENT_DIR/$1.conf"
    cat "$f"
    if [ "$QR" = "1" ] && command -v qrencode >/dev/null 2>&1; then
        echo; qrencode -t ansiutf8 < "$f"
    fi
}

add_client() {
    local name="$1" ip priv pub psk server_priv server_pub port host tmp_psk k v
    [ -f "$AWG_CONF" ] || die "server config not found: $AWG_CONF"
    mkdir -p "$CLIENT_DIR"; chmod 700 "$CLIENT_DIR"

    if [ -f "$CLIENT_DIR/$name.conf" ]; then show_client "$name"; return 0; fi
    [ -z "$(peer_pubkey "$name")" ] || die "peer '$name' exists in $AWG_CONF but has no client file; remove it first"

    host="$ENDPOINT_HOST"
    [ -n "$host" ] || { [ -r "$ENDPOINT_HOST_FILE" ] && host="$(tr -d '[:space:]' < "$ENDPOINT_HOST_FILE")"; }
    [ -n "$host" ] || die "endpoint host unknown: set AWG_ENDPOINT_HOST or write $ENDPOINT_HOST_FILE"
    port="$(iface_value ListenPort)"; [ -n "$port" ] || die "ListenPort missing in $AWG_CONF"
    server_priv="$(iface_value PrivateKey)"; [ -n "$server_priv" ] || die "PrivateKey missing in $AWG_CONF"
    server_pub="$(printf '%s\n' "$server_priv" | "$AWG_BIN" pubkey)"
    ip="$(next_free_ip)" || die "no free address left in $CLIENT_NET.0/24"

    umask 077
    priv="$("$AWG_BIN" genkey)"
    pub="$(printf '%s\n' "$priv" | "$AWG_BIN" pubkey)"
    psk="$("$AWG_BIN" genpsk)"

    # 1. live first — if the interface is down or awg fails, nothing is persisted
    tmp_psk="$(mktemp)"; printf '%s\n' "$psk" > "$tmp_psk"
    if ! "$AWG_BIN" set "$AWG_IFACE" peer "$pub" preshared-key "$tmp_psk" allowed-ips "$ip/32"; then
        rm -f "$tmp_psk"; die "live 'awg set' failed; server config left untouched"
    fi
    rm -f "$tmp_psk"

    # 2. persist on the server
    printf '\n[Peer]\n# client: %s\nPublicKey = %s\nPresharedKey = %s\nAllowedIPs = %s/32\n' \
        "$name" "$pub" "$psk" "$ip" >> "$AWG_CONF"

    # 3. client config
    {
        echo "[Interface]"
        echo "PrivateKey = $priv"
        echo "Address = $ip/32"
        echo "DNS = $DNS"
        echo "MTU = $MTU"
        for k in $PROFILE_KEYS; do
            v="$(iface_value "$k")"
            [ -n "$v" ] && echo "$k = $v"
        done
        echo
        echo "[Peer]"
        echo "PublicKey = $server_pub"
        echo "PresharedKey = $psk"
        echo "Endpoint = $host:$port"
        echo "AllowedIPs = 0.0.0.0/0"
        echo "PersistentKeepalive = 25"
    } > "$CLIENT_DIR/$name.conf"
    chmod 600 "$CLIENT_DIR/$name.conf"
    show_client "$name"
}

remove_client() {
    local name="$1" pub
    pub="$(peer_pubkey "$name")"
    [ -n "$pub" ] || die "no peer named '$name' in $AWG_CONF"
    "$AWG_BIN" set "$AWG_IFACE" peer "$pub" remove || die "live remove failed; server config left untouched"
    delete_peer_block "$name"
    rm -f "$CLIENT_DIR/$name.conf"
    echo "removed peer '$name' ($pub)"
}

MODE=add; NAME=""
while [ $# -gt 0 ]; do
    case "$1" in
        --remove) MODE=remove ;;
        --dns) [ $# -ge 2 ] || die "--dns needs a value"; shift; DNS="$1" ;;
        --mtu) [ $# -ge 2 ] || die "--mtu needs a value"; shift; MTU="$1" ;;
        --no-qr) QR=0 ;;
        -h|--help) usage ;;
        -*) die "unknown option: $1" ;;
        *) [ -z "$NAME" ] || die "only one name allowed"; NAME="$1" ;;
    esac
    shift
done
[ -n "$NAME" ] || usage
printf '%s' "$NAME" | grep -Eq '^[A-Za-z0-9._-]{1,32}$' || die "invalid name '$NAME' (allowed: A-Z a-z 0-9 . _ - ; max 32)"

case "$MODE" in
    add) add_client "$NAME" ;;
    remove) remove_client "$NAME" ;;
esac
