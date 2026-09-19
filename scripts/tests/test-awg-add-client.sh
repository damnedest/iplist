#!/usr/bin/env bash
# Test scripts/awg-add-client.sh against a stub `awg` binary in a temp dir.
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
SCRIPT="$HERE/../awg-add-client.sh"
T="$(mktemp -d)"
trap 'rm -rf "$T"' EXIT

mkdir -p "$T/bin" "$T/clients"
# --- stub awg: deterministic keys, records every `set` call ---
cat > "$T/bin/awg" <<'EOF'
#!/usr/bin/env bash
case "$1" in
  genkey)  echo "PRIV$(date +%s%N 2>/dev/null || echo $RANDOM)$RANDOM=" ;;
  genpsk)  echo "PSK$RANDOM$RANDOM=" ;;
  pubkey)  read -r k; echo "PUB_${k:0:10}" ;;
  set)     shift; echo "set $*" >> "${AWG_CALLS}" ;;
  *)       echo "stub awg: unsupported $*" >&2; exit 1 ;;
esac
EOF
chmod +x "$T/bin/awg"

cat > "$T/awg0.conf" <<'EOF'
[Interface]
Address = 10.9.0.1/24
ListenPort = 41234
PrivateKey = SERVERPRIVATEKEY=
Jc = 6
Jmin = 40
Jmax = 120
S1 = 20
S2 = 24
S3 = 16
S4 = 12
H1 = 100-200
H2 = 300-400
H3 = 500-600
H4 = 700-800
HeaderProtectionKey = HPKEYHPKEY=
ContentPaddingAddition = 0-64
RandomTrailers = on
DisableCookies = off

[Peer]
# client: existing
PublicKey = PUB_existing
PresharedKey = PSKexisting=
AllowedIPs = 10.9.0.2/32
EOF

export PATH="$T/bin:$PATH" AWG_CALLS="$T/calls.log"
export AWG_CONF="$T/awg0.conf" AWG_CLIENT_DIR="$T/clients" AWG_ENDPOINT_HOST="203.0.113.7" AWG_QR=0

fail() { echo "FAIL: $*" >&2; exit 1; }

# 1. add a client -> live apply, conf append, client file
out="$("$SCRIPT" alice)"
grep -q '^set awg0 peer PUB_' "$T/calls.log" || fail "no live awg set call"
grep -q 'allowed-ips 10.9.0.3/32' "$T/calls.log" || fail "next free ip should be .3"
grep -q '^# client: alice$' "$T/awg0.conf" || fail "peer not appended to server conf"
test -f "$T/clients/alice.conf" || fail "client conf not written"
c="$T/clients/alice.conf"
grep -q '^Address = 10.9.0.3/32$' "$c" || fail "client address"
grep -q '^Endpoint = 203.0.113.7:41234$' "$c" || fail "endpoint host:port"
grep -q '^PublicKey = PUB_SERVERPRIV$' "$c" || fail "server pubkey derived from server private key"
grep -q '^HeaderProtectionKey = HPKEYHPKEY=$' "$c" || fail "obfuscation key copied (value with '=')"
grep -q '^H4 = 700-800$' "$c" || fail "H4 copied"
grep -q '^DNS = 1.1.1.1$' "$c" || fail "default DNS"
grep -q '^MTU = 1280$' "$c" || fail "default MTU"
grep -q '^AllowedIPs = 0.0.0.0/0$' "$c" || fail "client AllowedIPs"
grep -q '^PersistentKeepalive = 25$' "$c" || fail "keepalive"
grep -q '^I1' "$c" && fail "empty/absent I1 must not be emitted"
echo "$out" | grep -q '^\[Interface\]' || fail "config printed to stdout"

# 2. re-run same name -> no duplicate, no new set call
n_before="$(wc -l < "$T/calls.log")"
"$SCRIPT" alice >/dev/null
n_after="$(wc -l < "$T/calls.log")"
[ "$n_before" = "$n_after" ] || fail "re-run must not call awg set"
[ "$(grep -c '^# client: alice$' "$T/awg0.conf")" = 1 ] || fail "duplicate peer block"

# 3. second client gets .4 and custom dns/mtu
"$SCRIPT" --dns 9.9.9.9 --mtu 1360 bob >/dev/null
grep -q '^Address = 10.9.0.4/32$' "$T/clients/bob.conf" || fail "bob address"
grep -q '^DNS = 9.9.9.9$' "$T/clients/bob.conf" || fail "custom dns"
grep -q '^MTU = 1360$' "$T/clients/bob.conf" || fail "custom mtu"

# 4. remove alice -> live remove, block gone, bob and existing intact
"$SCRIPT" --remove alice
grep -q '^set awg0 peer PUB_.* remove$' "$T/calls.log" || fail "no live remove"
grep -q '^# client: alice$' "$T/awg0.conf" && fail "alice block still in conf"
grep -q '^# client: bob$' "$T/awg0.conf" || fail "bob block lost"
grep -q '^# client: existing$' "$T/awg0.conf" || fail "existing block lost"
grep -q '^\[Interface\]' "$T/awg0.conf" || fail "interface section lost"
test ! -f "$T/clients/alice.conf" || fail "client file not removed"
[ -n "$(find "$T/awg0.conf" -perm 600)" ] || fail "conf mode not 600 after atomic rewrite"
[ -z "$(find "$T" -maxdepth 1 -name '.awg0.conf.*')" ] || fail "temp conf file left behind after remove"

# 5. bad name rejected, conf untouched
before="$(cat "$T/awg0.conf")"
if "$SCRIPT" 'bad name!' 2>/dev/null; then fail "bad name accepted"; fi
[ "$before" = "$(cat "$T/awg0.conf")" ] || fail "conf modified on bad name"

# 6. live apply failure -> conf untouched
cat > "$T/bin/awg" <<'EOF'
#!/usr/bin/env bash
case "$1" in
  genkey)  echo "PRIVX=" ;; genpsk) echo "PSKX=" ;; pubkey) read -r k; echo "PUB_$k" ;;
  set)     exit 1 ;;
esac
EOF
if "$SCRIPT" carol 2>/dev/null; then fail "must fail when awg set fails"; fi
grep -q '^# client: carol$' "$T/awg0.conf" && fail "conf modified after failed awg set"
test ! -f "$T/clients/carol.conf" || fail "client file written after failed awg set"

echo "ALL TESTS PASSED"
