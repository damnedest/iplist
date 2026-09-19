# AWG 3.1 Front (Server 3) + Server 1 Decommission — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stand up Server 3 as an AmneziaWG 3.1 client front that policy-routes in-set traffic through the existing Server 2 WireGuard egress, migrate clients off Server 1 (AWG 2.0), then retire Server 1.

**Architecture:** Server 3 is a clone of Server 1's role, but built from the *as-built* state (userspace `amneziawg-go`, independent fail-closed `awg-pbr`, `awg-nftset`, Telegram-via-tunnel) rather than the July templates. Server 2 only gains a second address + peer on `wg0`. A small bash script issues 3.1 client configs by copying the obfuscation profile straight out of the server config.

**Tech Stack:** Debian 13, `amneziawg-go` v3.1.20260828 + `amneziawg-tools` v3.1.20260812 (built from source, Go from go.dev tarball), kernel WireGuard for the uplink, nftables, systemd, bash/awk, `qrencode`.

**Spec:** `docs/superpowers/specs/2026-09-19-awg31-server3-migration-design.md`

## Global Constraints

- IPv4 only.
- Server 3: `awg0 = 10.9.0.1/24` (client net), `wg1 = 10.9.10.1/30`; Server 2 adds `10.9.10.2/30` on `wg0`. PBR table `100`, `fwmark 0x1`.
- `awg0` UDP port is a **random high port chosen at deploy time**, never 51820.
- AWG version tags: `amneziawg-go v3.1.20260828`, `amneziawg-tools v3.1.20260812`. Go version = the `go` directive in the go repo's `go.mod` (currently `1.25.0`; read it, don't assume).
- Header Protection requires `S1..S4 >= 12`.
- Obfuscation profile lives **only** in the server `awg0.conf` `[Interface]`; the client script copies it. Key list (exact spelling): `Jc Jmin Jmax S1 S2 S3 S4 H1 H2 H3 H4 I1 I2 I3 I4 I5 HeaderProtectionKey ContentPaddingAddition RekeyAfterTime RekeyTimeout RejectAfterTime KeepaliveTimeout MaxHandshakeAttempts RandomTrailers DisableCookies`.
- Server 1 is not touched until §6.2 of the spec is satisfied. Deleting Server 1 at the hoster is the last, manual step.
- Secrets (`telegram.env`, private keys, client configs) never enter the repo.
- Servers pull from the fork `https://github.com/damnedest/iplist` (remote `fork`), so repo tasks must be **pushed to the fork** before live deployment.
- The `awg-quick` from tools 3.1 falls back to `amneziawg-go` automatically when `/sys/module/amneziawg` is absent and the binary is on `PATH` (`WG_QUICK_USERSPACE_IMPLEMENTATION` env var, default `amneziawg-go`). No systemd override is needed; the runbook verifies the userspace process is running instead.
- Commit messages end with `Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>`.

---

## File map

| Path | Responsibility |
| ---- | -------------- |
| `deploy/server3/awg0.conf.example` | AWG 3.1 client-facing interface, full obfuscation profile with placeholders + comments |
| `deploy/server3/wg1.conf.example` | Kernel WG uplink to Server 2, `Table = off`, PostUp/PostDown install/remove `default dev wg1 table 100` |
| `deploy/server3/awg-pbr.service` | As-built fail-closed floor: fwmark rule, blackhole, Telegram CIDR rules; ExecStop removes only its own blackhole |
| `deploy/server3/awg-nftset.service` | Reload `generated/awg-set.nft` after nftables at boot |
| `deploy/server3/nftables-awg.nft` | Set + mark + MSS clamp + masquerade (WAN iface placeholder) |
| `deploy/server3/awg-update.service`, `awg-update.timer`, `telegram.env.example` | Same as Server 1 |
| `scripts/awg-add-client.sh` | Issue / show / remove 3.1 client peers live |
| `scripts/tests/test-awg-add-client.sh` | Portable bash test with a stub `awg` binary |
| `deploy/server2/wg0.conf.example` | Two peers, two addresses |
| `deploy/RUNBOOK-server2.md` | New section: add Server 3 peer live; later remove Server 1 peer |
| `deploy/RUNBOOK-server3.md` | Full deploy + acceptance + Server 1 decommission chapter |

---

## Part A — Repository work

### Task 1: Server 3 deploy templates (as-built)

**Files:**
- Create: `deploy/server3/awg0.conf.example`
- Create: `deploy/server3/wg1.conf.example`
- Create: `deploy/server3/awg-pbr.service`
- Create: `deploy/server3/awg-nftset.service`
- Create: `deploy/server3/nftables-awg.nft`
- Create: `deploy/server3/awg-update.service`
- Create: `deploy/server3/awg-update.timer`
- Create: `deploy/server3/telegram.env.example`

**Interfaces:**
- Produces: the peer block format that `scripts/awg-add-client.sh` (Task 2) appends and parses: `[Peer]` / `# client: <name>` / `PublicKey = …` / `PresharedKey = …` / `AllowedIPs = 10.9.0.X/32`.

- [ ] **Step 1: Write `deploy/server3/awg0.conf.example`**

```ini
# /etc/amnezia/amneziawg/awg0.conf on Server 3 (client-facing AmneziaWG 3.1).
# Runs in USERSPACE (amneziawg-go); awg-quick picks it automatically when the kernel
# module is absent. The whole obfuscation profile lives HERE and only here:
# scripts/awg-add-client.sh copies these keys verbatim into every client config.
#
# Generate the profile once at deploy time (RUNBOOK-server3.md §5). Rules:
#   * S1..S4 must be >= 12 (Header Protection uses them as nonce material).
#   * H1..H4 are four NON-overlapping uint32 ranges, none containing 1..4.
#   * Jmax must stay well below the WAN MTU (junk packets must not fragment).
#   * I1..I5 are client-side only (README: "no need to specify on both sides").
#     Leave empty here; add real CPS signatures on clients later if needed.
[Interface]
Address = 10.9.0.1/24
ListenPort = <AWG0_PORT>            # random high UDP port chosen at deploy, NOT 51820
PrivateKey = <SERVER3_AWG_PRIVATE_KEY>

# --- junk packets before handshake (client-side semantics, kept symmetric) ---
Jc = 6
Jmin = 40
Jmax = 120

# --- message paddings (server-side: must match clients; >= 12 for header protection) ---
S1 = <S1>
S2 = <S2>
S3 = <S3>
S4 = <S4>

# --- message type headers (server-side: must match clients; 4 disjoint ranges) ---
H1 = <H1_LO>-<H1_HI>
H2 = <H2_LO>-<H2_HI>
H3 = <H3_LO>-<H3_HI>
H4 = <H4_LO>-<H4_HI>

# --- AWG 3.x ---
HeaderProtectionKey = <HPK>         # awg genkey
ContentPaddingAddition = 0-64       # random extra padding on transport payload
RekeyAfterTime = 100-140            # WG default 120
RekeyTimeout = 4-8                  # WG default 5
RejectAfterTime = 170-190           # WG default 180
KeepaliveTimeout = 8-14             # WG default 10
MaxHandshakeAttempts = 12-20
RandomTrailers = on
DisableCookies = off                # keep WG cookie DoS protection

# Peers are appended by scripts/awg-add-client.sh in exactly this shape:
# [Peer]
# # client: <name>
# PublicKey = <CLIENT_PUBLIC_KEY>
# PresharedKey = <CLIENT_PSK>
# AllowedIPs = 10.9.0.X/32
```

- [ ] **Step 2: Write `deploy/server3/wg1.conf.example`**

```ini
# /etc/wireguard/wg1.conf on Server 3 (plain kernel WireGuard uplink to Server 2).
# Table = off: wg-quick must NOT touch the main table. The preferred route in
# table 100 is owned by THIS file (PostUp/PostDown); the blackhole floor and the
# fwmark rule are owned by awg-pbr.service and stay up even when wg1 is down.
[Interface]
Address = 10.9.10.1/30
PrivateKey = <SERVER3_WG1_PRIVATE_KEY>
Table = off
MTU = 1420
PostUp = ip route replace default dev wg1 table 100
PostDown = ip route del default dev wg1 table 100 || true

[Peer]
# Server 2
PublicKey = <SERVER2_WG0_PUBLIC_KEY>
Endpoint = <SERVER2_PUBLIC_IP>:51820
AllowedIPs = 0.0.0.0/0
PersistentKeepalive = 25
```

- [ ] **Step 3: Write `deploy/server3/awg-pbr.service`**

```ini
[Unit]
Description=AWG policy-based routing floor (fwmark 0x1 -> table 100, fail-closed blackhole)
# Independent of wg1 on purpose: if wg1 dies, this unit stays up and the blackhole
# keeps marked traffic from leaking to the direct egress.
After=network-online.target
Wants=network-online.target

[Service]
Type=oneshot
RemainAfterExit=yes
ExecStart=-/sbin/ip rule add fwmark 0x1 lookup 100
ExecStart=-/sbin/ip route add blackhole default table 100 metric 100
# Server 3's OWN Telegram traffic must also go via Server 2 (api.telegram.org is
# blocked from the front's direct egress).
ExecStart=-/sbin/ip rule add to 149.154.160.0/20 lookup 100
ExecStart=-/sbin/ip rule add to 91.108.4.0/22 lookup 100
ExecStart=-/sbin/ip rule add to 91.108.8.0/21 lookup 100
ExecStart=-/sbin/ip rule add to 91.108.16.0/21 lookup 100
ExecStart=-/sbin/ip rule add to 91.108.56.0/22 lookup 100
ExecStart=-/sbin/ip rule add to 95.161.64.0/20 lookup 100
# Remove ONLY what we own. Never `ip route flush table 100`: that would wipe the
# `default dev wg1` route installed by wg1.conf PostUp and break the tunnel.
ExecStop=-/sbin/ip route del blackhole default table 100 metric 100
ExecStop=-/sbin/ip rule del to 149.154.160.0/20 lookup 100
ExecStop=-/sbin/ip rule del to 91.108.4.0/22 lookup 100
ExecStop=-/sbin/ip rule del to 91.108.8.0/21 lookup 100
ExecStop=-/sbin/ip rule del to 91.108.16.0/21 lookup 100
ExecStop=-/sbin/ip rule del to 91.108.56.0/22 lookup 100
ExecStop=-/sbin/ip rule del to 95.161.64.0/20 lookup 100
ExecStop=-/sbin/ip rule del fwmark 0x1 lookup 100

[Install]
WantedBy=multi-user.target
```

- [ ] **Step 4: Write `deploy/server3/awg-nftset.service`**

```ini
[Unit]
Description=Reload generated AWG CIDR set into nftables after boot
After=nftables.service
Requires=nftables.service

[Service]
Type=oneshot
RemainAfterExit=yes
ExecStart=/usr/sbin/nft -f /opt/iplist/generated/awg-set.nft

[Install]
WantedBy=multi-user.target
```

- [ ] **Step 5: Write `deploy/server3/nftables-awg.nft`**

```
#!/usr/sbin/nft -f
# Server 3 AWG ruleset. Include from /etc/nftables.conf. The awgvia set is filled
# separately by `nft -f /opt/iplist/generated/awg-set.nft` (awg-nftset.service).
# Replace <WAN> with the real WAN interface name (`ip -br link`).
table inet awg {
    set awgvia {
        type ipv4_addr
        flags interval
        auto-merge
    }

    chain prerouting {
        type filter hook prerouting priority mangle; policy accept;
        # mark ONLY client traffic destined to the routed CIDRs (never local/SSH traffic)
        iifname "awg0" ip daddr @awgvia meta mark set 0x1
    }

    chain forward {
        type filter hook forward priority filter; policy accept;
        tcp flags syn tcp option maxseg size set rt mtu
    }

    chain postrouting {
        type nat hook postrouting priority srcnat; policy accept;
        oifname "<WAN>" masquerade
        oifname "wg1" masquerade
    }
}
```

- [ ] **Step 6: Write the three unchanged-from-Server-1 files**

`deploy/server3/awg-update.service`:
```ini
[Unit]
Description=AWG CIDR set refresh from fork
After=network-online.target
Wants=network-online.target

[Service]
Type=oneshot
WorkingDirectory=/opt/iplist
ExecStart=/usr/bin/make awg-update
```

`deploy/server3/awg-update.timer`:
```ini
[Unit]
Description=Daily AWG CIDR set refresh

[Timer]
OnCalendar=*-*-* 04:17:00
Persistent=true

[Install]
WantedBy=timers.target
```

`deploy/server3/telegram.env.example`:
```
# Copy to /etc/awg/telegram.env, chmod 600. Never commit real values.
TG_TOKEN=123456:ABC-your-bot-token
TG_CHAT=123456789
```

- [ ] **Step 7: Verify the templates**

Run:
```bash
cd deploy/server3
ls | wc -l                                                     # 8
grep -c '^ExecStart=-/sbin/ip rule add to' awg-pbr.service     # 6
grep -c 'ip route flush' awg-pbr.service                       # 0
grep -c '^Requires=wg-quick' awg-pbr.service                   # 0
grep -c '^PostUp = ip route replace default dev wg1 table 100' wg1.conf.example   # 1
for k in Jc Jmin Jmax S1 S2 S3 S4 H1 H2 H3 H4 HeaderProtectionKey ContentPaddingAddition RekeyAfterTime RekeyTimeout RejectAfterTime KeepaliveTimeout MaxHandshakeAttempts RandomTrailers DisableCookies; do grep -q "^$k = " awg0.conf.example || echo "MISSING $k"; done; echo "keys checked"
```
Expected: `8`, `6`, `0`, `0`, `1`, no `MISSING` lines, `keys checked`.

- [ ] **Step 8: Commit**

```bash
git add deploy/server3
git commit -m "deploy: Server 3 templates (AWG 3.1 front, as-built PBR)

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 2: `scripts/awg-add-client.sh` with a stub-based test

**Files:**
- Create: `scripts/tests/test-awg-add-client.sh`
- Create: `scripts/awg-add-client.sh`

**Interfaces:**
- Consumes: peer block format from Task 1.
- Produces: CLI `awg-add-client.sh <name>` (add or show), `awg-add-client.sh --remove <name>`. Environment overrides (all optional): `AWG_BIN` (default `awg`), `AWG_IFACE` (`awg0`), `AWG_CONF` (`/etc/amnezia/amneziawg/$AWG_IFACE.conf`), `AWG_CLIENT_DIR` (`/etc/amnezia/amneziawg/clients`), `AWG_ENDPOINT_HOST` (else read from `/etc/amnezia/amneziawg/endpoint-host`), `AWG_CLIENT_NET` (`10.9.0`), `AWG_DNS` (`1.1.1.1`), `AWG_MTU` (`1280`), `AWG_QR` (`1`). Flags `--dns X`, `--mtu N`, `--no-qr`.
- The runbook (Task 4) writes `/etc/amnezia/amneziawg/endpoint-host` containing Server 3's public IP.

Portability requirement: must run on macOS bash 3.2 + BSD awk (so the test runs locally) and on Debian bash 5 + mawk. No associative arrays, no `mapfile`, no gawk-only functions.

- [ ] **Step 1: Write the failing test `scripts/tests/test-awg-add-client.sh`**

```bash
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
```

- [ ] **Step 2: Run the test, expect failure**

Run: `chmod +x scripts/tests/test-awg-add-client.sh && scripts/tests/test-awg-add-client.sh`
Expected: fails immediately (script missing: `No such file or directory` or `FAIL: no live awg set call`).

- [ ] **Step 3: Write `scripts/awg-add-client.sh`**

```bash
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
```

- [ ] **Step 4: Run the test, expect pass**

Run: `chmod +x scripts/awg-add-client.sh && scripts/tests/test-awg-add-client.sh`
Expected: `ALL TESTS PASSED`. If `bash -n scripts/awg-add-client.sh` reports nothing and the test still fails, read the `FAIL:` line: it names the assertion.

- [ ] **Step 5: Lint**

Run: `bash -n scripts/awg-add-client.sh scripts/tests/test-awg-add-client.sh && (command -v shellcheck >/dev/null && shellcheck scripts/awg-add-client.sh || echo "shellcheck not installed, skipped")`
Expected: no syntax errors; shellcheck warnings (if run) are either fixed or justified in a comment.

- [ ] **Step 6: Commit**

```bash
git add scripts/awg-add-client.sh scripts/tests/test-awg-add-client.sh
git commit -m "feat: awg-add-client.sh — issue/show/remove AWG 3.1 client peers live

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 3: Server 2 template + runbook (second peer)

**Files:**
- Modify: `deploy/server2/wg0.conf.example`
- Modify: `deploy/RUNBOOK-server2.md` (append a section after §7)

- [ ] **Step 1: Replace `deploy/server2/wg0.conf.example`**

```ini
# /etc/wireguard/wg0.conf on Server 2 (target-jurisdiction egress gateway).
# One WG interface, one /30 per front. Fill <PLACEHOLDERS>.
# Generate keys: wg genkey | tee privkey | wg pubkey > pubkey
[Interface]
Address = 10.9.9.2/30, 10.9.10.2/30
ListenPort = 51820
PrivateKey = <SERVER2_PRIVATE_KEY>
# no default-route games here; this box just NATs what arrives on wg0

[Peer]
# Server 1 (AWG 2.0 front) — remove after Server 1 is decommissioned
PublicKey = <SERVER1_WG1_PUBLIC_KEY>
AllowedIPs = 10.9.9.1/32

[Peer]
# Server 3 (AWG 3.1 front)
PublicKey = <SERVER3_WG1_PUBLIC_KEY>
AllowedIPs = 10.9.10.1/32
```

- [ ] **Step 2: Append to `deploy/RUNBOOK-server2.md`**

````markdown
## 8. Add a second front (Server 3) — live, no tunnel restart

Server 3 uses its own /30 (`10.9.10.0/30`). Apply live first, then persist. Server 1's
tunnel must not drop at any point.

```bash
# live
ip addr add 10.9.10.2/30 dev wg0
wg set wg0 peer <SERVER3_WG1_PUBLIC_KEY> allowed-ips 10.9.10.1/32
```
Persist in `/etc/wireguard/wg0.conf` (see `deploy/server2/wg0.conf.example`):
- `Address = 10.9.9.2/30, 10.9.10.2/30`
- second `[Peer]` block for Server 3.

Verify:
```bash
wg-quick strip wg0 >/dev/null && echo "conf parses"     # MUST pass or wg0 dies on reboot
wg show wg0                       # two peers; Server 1 handshake still recent
ip -4 addr show dev wg0           # both /30 addresses
ping -c 2 10.9.10.1               # once Server 3's wg1 is up
```

## 9. Remove Server 1's peer (only after Server 1 is decommissioned — RUNBOOK-server3.md §12)

```bash
wg set wg0 peer <SERVER1_WG1_PUBLIC_KEY> remove
ip addr del 10.9.9.2/30 dev wg0
```
Then delete the Server 1 `[Peer]` block from `wg0.conf` and set `Address = 10.9.10.2/30`.
Verify: `wg-quick strip wg0 >/dev/null && wg show wg0` shows only Server 3, with a
recent handshake.
````

- [ ] **Step 3: Verify**

Run: `grep -c '^\[Peer\]' deploy/server2/wg0.conf.example; grep -n '^## 8\|^## 9' deploy/RUNBOOK-server2.md`
Expected: `2`, and both new headings listed.

- [ ] **Step 4: Commit**

```bash
git add deploy/server2/wg0.conf.example deploy/RUNBOOK-server2.md
git commit -m "deploy: Server 2 — second front peer (Server 3) + removal steps

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 4: `deploy/RUNBOOK-server3.md` — deploy + acceptance

**Files:**
- Create: `deploy/RUNBOOK-server3.md`

**Interfaces:**
- Consumes: templates from Task 1, script from Task 2, Server 2 §8 from Task 3.

- [ ] **Step 1: Write the runbook**

````markdown
# RUNBOOK — Server 3 (AmneziaWG 3.1 in, policy-based routing out)

Server 3 replaces Server 1 as the client-facing front. Clients connect over
**AmneziaWG 3.1** (`awg0`, userspace `amneziawg-go`). Traffic whose destination is in
the generated `awgvia` nftables set is marked (`fwmark 0x1`) and policy-routed through a
plain kernel WireGuard uplink (`wg1`) to **Server 2**, which NATs it out. Everything else
exits Server 3 directly. If `wg1` is down, marked traffic is **blackholed (fail-closed)**.

Run every step as **root**. Each step ends with a verification. **Step 7 arms an
auto-rollback timer before any network change.** Spec:
`docs/superpowers/specs/2026-09-19-awg31-server3-migration-design.md`.

## 0. Prerequisites
- Debian 13 (trixie), same hoster as Server 1. Note the WAN interface name:
  `ip -br link` (Server 1 had `eth0`; use what you see, referred to as `<WAN>` below).
- Server 2 up; you have its `wg0` **public key** and **public IP**.
- Your SSH port (assumed `22`).
- Pick the client-facing UDP port now: `shuf -i 20000-60000 -n 1` → `<AWG0_PORT>`.
  Write it down; it goes into `awg0.conf` and into every client config.

## 1. Packages + kernel headers probe
```bash
apt update
apt install -y wireguard nftables git make php-cli curl qrencode build-essential
apt install -y "linux-headers-$(uname -r)" || echo "NO HEADERS -> userspace path (expected)"
```
Verify: `wg --version` prints a version. If headers installed, you *may* build the
DKMS module instead, but this runbook only covers and tests the userspace path.

## 2. Go toolchain (latest stable; must be >= the go.mod of the amneziawg-go tag)
```bash
mkdir -p /root/src && cd /root/src
git clone --depth 1 --branch v3.1.20260828 https://github.com/amnezia-vpn/amneziawg-go.git
awk '/^go /{print "go.mod wants go " $2}' amneziawg-go/go.mod
GOLATEST="$(curl -fsSL 'https://go.dev/VERSION?m=text' | head -1)"; echo "$GOLATEST"   # e.g. go1.27.1
curl -fsSLo /tmp/go.tgz "https://go.dev/dl/${GOLATEST}.linux-amd64.tar.gz"
rm -rf /usr/local/go && tar -C /usr/local -xzf /tmp/go.tgz
export PATH=/usr/local/go/bin:$PATH
```
Verify: `go version` prints `$GOLATEST`, and it is >= the go.mod line (a newer Go
builds an older module; the reverse fails at `go build`).

## 3. Build amneziawg-go 3.1 (userspace datapath)
```bash
cd /root/src/amneziawg-go
make && make install            # -> /usr/bin/amneziawg-go
```
Verify: `amneziawg-go --version` mentions `3.1`.

## 4. Build amneziawg-tools 3.1 (awg, awg-quick, systemd unit)
```bash
cd /root/src
git clone --depth 1 --branch v3.1.20260812 https://github.com/amnezia-vpn/amneziawg-tools.git
make -C amneziawg-tools/src && make -C amneziawg-tools/src install
systemctl daemon-reload
```
Verify:
```bash
awg --version                                        # 3.1 tools
ls /usr/lib/systemd/system/awg-quick@.service        # unit installed by `make install`
grep -n 'WG_QUICK_USERSPACE_IMPLEMENTATION' /usr/bin/awg-quick | head -2
```
The last line shows `awg-quick` defaults to `amneziawg-go` when `/sys/module/amneziawg`
is absent. No override needed.

## 5. Keys + obfuscation profile
```bash
umask 077
mkdir -p /etc/amnezia/amneziawg /etc/wireguard
awg genkey | tee /etc/amnezia/amneziawg/awg0.privkey | awg pubkey > /etc/amnezia/amneziawg/awg0.pubkey
wg  genkey | tee /etc/wireguard/wg1.privkey          | wg  pubkey > /etc/wireguard/wg1.pubkey
# Header Protection key
awg genkey > /etc/amnezia/amneziawg/hpk
# S1..S4 (>=12), H1..H4 (4 disjoint ranges, width 10000, far from 1..4)
S=($(shuf -i 12-64 -n 4)); echo "S1=${S[0]} S2=${S[1]} S3=${S[2]} S4=${S[3]}"
H=($(shuf -i 100000-2000000000 -n 4 | sort -n)); for i in 0 1 2 3; do echo "H$((i+1))=${H[$i]}-$((H[$i]+10000))"; done
```
Check the four H ranges do not overlap (with a 10000 width and random bases in a
2e9 space a collision is practically impossible; eyeball it anyway).

Write `/etc/amnezia/amneziawg/awg0.conf` from `deploy/server3/awg0.conf.example`
(after the repo is cloned in §6 you can `cp` it from `/opt/iplist/deploy/server3/`):
- `ListenPort = <AWG0_PORT>`, `PrivateKey` = awg0.privkey, `HeaderProtectionKey` = hpk,
  `S1..S4`, `H1..H4` from above. Leave `I1..I5` absent.
Write `/etc/wireguard/wg1.conf` from `deploy/server3/wg1.conf.example` with wg1.privkey,
Server 2's pubkey and IP. Then:
```bash
chmod 600 /etc/amnezia/amneziawg/awg0.conf /etc/wireguard/wg1.conf
echo "<SERVER3_PUBLIC_IP>" > /etc/amnezia/amneziawg/endpoint-host
awg-quick strip awg0 >/dev/null && echo "awg0.conf parses"
wg-quick  strip wg1  >/dev/null && echo "wg1.conf parses"
```
Verify: both `parses` lines; no `<` placeholders left: `grep -n '<' /etc/amnezia/amneziawg/awg0.conf /etc/wireguard/wg1.conf` prints nothing.

**Give Server 2 this box's `wg1` public key** (`cat /etc/wireguard/wg1.pubkey`) and
run `RUNBOOK-server2.md` §8 there now.

## 6. Clone the fork + sysctl
```bash
git clone https://github.com/damnedest/iplist.git /opt/iplist
git -C /opt/iplist remote rename origin fork
cat > /etc/sysctl.d/99-awg.conf <<'EOF'
net.ipv4.ip_forward=1
net.ipv4.conf.all.rp_filter=2
EOF
sysctl --system
cd /opt/iplist && make awg-all
```
Verify: `git -C /opt/iplist remote -v` shows `fork`; `sysctl net.ipv4.ip_forward net.ipv4.conf.all.rp_filter` → `1` and `2`; `generated/awg-set.nft` exists.

## 7. LOCKOUT SAFETY — arm auto-rollback BEFORE nftables
```bash
systemd-run --on-active=10min --timer-property=AccuracySec=1s \
  /bin/sh -c 'nft flush ruleset; systemctl restart nftables; \
              ip route flush table 100; ip rule del fwmark 0x1 lookup 100 || true'
```
Now install the ruleset. Take `deploy/server3/nftables-awg.nft`, replace `<WAN>` with
the real interface, save as `/etc/nftables.d/awg.nft`, and make sure
`/etc/nftables.conf` ends with `include "/etc/nftables.d/awg.nft"`. If you keep a
drop-policy input chain, it must accept your SSH port **and** `udp dport <AWG0_PORT>`.
```bash
nft -f /etc/nftables.conf
systemctl enable --now nftables
nft -f /opt/iplist/generated/awg-set.nft
```
Verify **your SSH session is alive**, then `nft list set inet awg awgvia | head` shows
a populated interval set.

## 8. systemd units
```bash
cp /opt/iplist/deploy/server3/awg-pbr.service /opt/iplist/deploy/server3/awg-nftset.service \
   /opt/iplist/deploy/server3/awg-update.service /opt/iplist/deploy/server3/awg-update.timer \
   /etc/systemd/system/
install -Dm600 /opt/iplist/deploy/server3/telegram.env.example /etc/awg/telegram.env
# edit /etc/awg/telegram.env with the real TG_TOKEN / TG_CHAT (same bot + chat as Server 1)
systemctl daemon-reload
systemctl enable --now awg-nftset.service awg-pbr.service
systemctl enable --now wg-quick@wg1
systemctl enable --now awg-quick@awg0
systemctl enable --now awg-update.timer
```
Verify:
```bash
ip rule | grep -c 'lookup 100'              # 7 (fwmark + 6 Telegram)
ip route show table 100                     # default dev wg1  AND  blackhole default metric 100
wg show wg1                                 # recent handshake with Server 2
awg show awg0                               # interface up, listening on <AWG0_PORT>
pgrep -a amneziawg-go                       # userspace datapath running for awg0
systemctl --failed                          # 0 loaded units
```

## 9. First client (test client)
```bash
/opt/iplist/scripts/awg-add-client.sh testclient
```
Import the printed config/QR into Amnezia VPN ≥ 5.0.1.5 (or a 3.1-capable AmneziaWG
app) on a phone/laptop. Verify `awg show awg0` shows a recent handshake for it.

## 10. Acceptance (all must pass before §11)
| # | Check | Expected |
| - | ----- | -------- |
| 1 | `awg --version; amneziawg-go --version; awg-quick strip awg0 >/dev/null` | 3.1 / 3.1 / parses |
| 2 | test client 3.1 connects | recent handshake in `awg show awg0` |
| 3 | old 2.0 client config pointed at Server 3's IP:port | **no** handshake |
| 4 | netns client, `curl -s` to an IP-echo service whose IP is **not** in the set | Server 3's IP |
| 5 | netns client, `curl -s` to an IP-echo service whose IP **is** in the set | Server 2's IP |
| 6 | `systemctl stop wg-quick@wg1` → in-set curl | `000`; direct still works; `start` restores without touching awg-pbr |
| 7 | `systemctl restart awg-pbr; ip route show table 100` | still has `default dev wg1` |
| 8 | `curl -o /dev/null https://<large file>` via both paths | completes |
| 9 | `reboot`; then `systemctl --failed`, re-run 4–6 | 0 failed, all pass |
| 10 | `sed -i '$d' /var/lib/awg/awg-cidr4.prev` (fake a diff), then `systemctl start awg-update.service` | set reloaded, Telegram message arrives via the tunnel |
| 11 | on Server 2: `wg show wg0` | both peers, Server 1 handshake never dropped; reboot Server 2 → both come back |
| 12 | `awg-add-client.sh second` while `testclient` streams; `--remove second`; `awg-add-client.sh testclient` again | no drop; peer gone; no duplicate |

Rows 4–5: classify each echo service first, then pick one of each kind
(`ifconfig.me`, `api.ipify.org`, `icanhazip.com`, `ip.sb` — the set is broad, so some WILL be in it):
```bash
for h in ifconfig.me api.ipify.org icanhazip.com ip.sb; do
  ip="$(getent ahostsv4 "$h" | awk 'NR==1{print $1}')"
  nft get element inet awg awgvia "{ $ip }" >/dev/null 2>&1 && echo "$h $ip IN-SET" || echo "$h $ip direct"
done
```

netns client recipe (same as July):
```bash
ip netns add c && ip link add vc0 type veth peer name vc1 && ip link set vc1 netns c
ip addr add 10.99.0.1/30 dev vc0 && ip link set vc0 up
ip netns exec c ip addr add 10.99.0.2/30 dev vc1 && ip netns exec c ip link set vc1 up
ip netns exec c ip route add default via 10.99.0.1
nft add rule inet awg prerouting iifname "vc0" ip daddr @awgvia meta mark set 0x1   # temp, remove after
nft add rule inet awg postrouting oifname "<WAN>" ip saddr 10.99.0.0/30 masquerade  # temp
```
Remove the two temp rules and the netns afterwards.

## 11. Cancel the rollback timer
```bash
systemctl list-timers | grep run-
systemctl stop <run-xxxx.timer>
```

## 12. Decommission Server 1 — see the next chapter (added in Task 5).
````

- [ ] **Step 2: Verify**

Run: `grep -n '^## ' deploy/RUNBOOK-server3.md; grep -c '<AWG0_PORT>' deploy/RUNBOOK-server3.md`
Expected: headings 0–12 listed; the port placeholder appears ≥ 4 times (prereq, conf, firewall, acceptance).

- [ ] **Step 3: Commit**

```bash
git add deploy/RUNBOOK-server3.md
git commit -m "docs: RUNBOOK-server3 — AWG 3.1 front deploy + acceptance

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 5: Decommission chapter + push to fork

**Files:**
- Modify: `deploy/RUNBOOK-server3.md` (replace the §12 stub)

- [ ] **Step 1: Replace the `## 12.` stub with the full chapter**

````markdown
## 12. Decommission Server 1

**Gate (spec §6.2):** §10 acceptance fully passed **and** every client is on a 3.1
config and has confirmed it works. Until then, Server 1 is untouched and remains the
rollback: clients just switch back to their old profile.

Order matters. Step 3 is the point of no return.

1. **Server 2 — drop Server 1's peer** (`RUNBOOK-server2.md` §9). Verify Server 3's
   handshake on `wg0` is still recent afterwards.
2. **Server 1 — stop and disable everything, remove the bot token**
   ```bash
   systemctl disable --now awg-quick@awg0 wg-quick@wg1 awg-pbr.service awg-update.timer awg-nftset.service
   shred -u /etc/awg/telegram.env
   ```
   Verify: `systemctl list-units 'awg*' 'wg-quick*'` shows nothing active.
3. **Hoster — delete Server 1.** Manual, in the hoster panel. No rollback after this.
4. **Access hygiene (Server 2 and Server 3)**
   ```bash
   sed -i '/claude-deploy/d' /root/.ssh/authorized_keys        # if the deploy key is no longer needed
   cat > /etc/ssh/sshd_config.d/10-hardening.conf <<'EOF'
   PasswordAuthentication no
   PermitRootLogin prohibit-password
   EOF
   sshd -t && systemctl reload ssh
   ```
   Verify from your workstation, in a **new** terminal while the old session stays
   open: key login works, password login is refused.
5. **Repo:** run Part C of the plan (RETIRED markers).
````

- [ ] **Step 2: Verify + commit**

Run: `grep -n 'point of no return\|## 12' deploy/RUNBOOK-server3.md`
Expected: both present.

```bash
git add deploy/RUNBOOK-server3.md
git commit -m "docs: RUNBOOK-server3 — Server 1 decommission chapter

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

- [ ] **Step 3: Push to the fork (servers pull from it)**

Run: `git remote -v` — confirm `fork` points at `damnedest/iplist`. Then:
```bash
git push fork master
```
Expected: push succeeds. If it is rejected (memory notes an old unpushed local commit `abd44e3e` with a curated awg list), **stop and ask the user** which side wins; do not force-push.

---

## Part B — Live deployment (runbook execution, done by the user + assistant over SSH)

### Task 6: Bring up Server 3 and pass acceptance

**Files:** none (live systems). Follow `deploy/RUNBOOK-server3.md` §0–§11 and `deploy/RUNBOOK-server2.md` §8.

- [ ] **Step 1: Server 2 §8** (needs Server 3's wg1 pubkey from RUNBOOK-server3 §5). Verify `wg-quick strip wg0` passes and Server 1's handshake is unaffected.
- [ ] **Step 2: Server 3 §0–§9.** Record `<AWG0_PORT>` and the WAN iface name in the assistant memory file (not in the repo). Never record keys or the obfuscation values anywhere but the server.
- [ ] **Step 3: Server 3 §10 acceptance table, all 12 rows.** Paste the actual command output for rows 3, 6, 7, 9, 11 into the session log; any failure blocks §11.
- [ ] **Step 4: §11 cancel rollback timer.**
- [ ] **Step 5: Issue real client configs** with `awg-add-client.sh <name>`; keep Server 1 running.

### Task 7: Client migration window

- [ ] **Step 1:** Each client imports the 3.1 profile alongside the old one and switches.
- [ ] **Step 2:** Track confirmations; the gate is "all clients confirmed on 3.1". No fixed number of days.

---

## Part C — After the gate (spec §6.2)

### Task 8: Decommission Server 1 and retire its repo artifacts

**Files:**
- Modify: `deploy/RUNBOOK-server1.md:1`
- Modify: `deploy/server1/awg0.conf.example:1`, `deploy/server1/wg1.conf.example:1`, `deploy/server1/awg-pbr.service:1`, `deploy/server1/nftables-awg.nft:1`

- [ ] **Step 1: Execute `RUNBOOK-server3.md` §12 steps 1–4 live**, in order, with each verification.

- [ ] **Step 2: Mark Server 1 artifacts retired** (prepend one line; `<date>` = the day Server 1 was deleted, ISO format)

```bash
D=<date>
f=deploy/RUNBOOK-server1.md
{ printf '> **RETIRED %s** — Server 1 was decommissioned; see `deploy/RUNBOOK-server3.md`.\n\n' "$D"; cat "$f"; } > "$f.new" && mv "$f.new" "$f"
for f in deploy/server1/awg0.conf.example deploy/server1/wg1.conf.example deploy/server1/nftables-awg.nft deploy/server1/awg-pbr.service; do
  { printf '# RETIRED %s — Server 1 decommissioned; current front is deploy/server3/\n' "$D"; cat "$f"; } > "$f.new" && mv "$f.new" "$f"
done
```
Verify: `head -1 deploy/RUNBOOK-server1.md deploy/server1/*.example deploy/server1/nftables-awg.nft deploy/server1/awg-pbr.service` all show a RETIRED line; `git diff --stat` touches only those 5 files.

- [ ] **Step 3: Commit + push**

```bash
git add deploy/RUNBOOK-server1.md deploy/server1
git commit -m "docs: mark Server 1 deploy artifacts RETIRED after decommission

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
git push fork master
```

- [ ] **Step 4: Update the assistant memory file** `vpn-awg-deployment.md`: Server 3 replaces Server 1 (IP, port, WAN iface, tunnel /30), Server 1 gone, open follow-ups updated (deploy key / password auth status).
