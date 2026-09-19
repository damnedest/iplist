# RUNBOOK — Server 3 (AmneziaWG 3.1 in, policy-based routing out)

Server 3 replaces Server 1 as the client-facing front. Clients connect over
**AmneziaWG 3.1** (`awg0`, userspace `amneziawg-go`). Traffic whose destination is in
the generated `awgvia` nftables set is marked (`fwmark 0x1`) and policy-routed through a
plain kernel WireGuard uplink (`wg1`) to **Server 2**, which NATs it out. Everything else
exits Server 3 directly. If `wg1` is down, marked traffic is **blackholed (fail-closed)**.

Run every step as **root**. Each step ends with a verification. **Step 7 arms an
auto-rollback timer before the firewall change and disarms it once SSH is confirmed
alive.** Spec:
`docs/superpowers/specs/2026-09-19-awg31-server3-migration-design.md`.

## 0. Prerequisites
- Debian 13 (trixie), same hoster as Server 1. Note the WAN interface name:
  `ip -br link` (Server 1 had `eth0`; use what you see, referred to as `<WAN>` below).
- Server 2 up; you have its `wg0` **public key** and **public IP**.
- Your SSH port (assumed `22`).
- Pick the client-facing UDP port now: `until p=$(shuf -i 20000-60000 -n 1); [ "$p" != 51820 ]; do :; done; echo $p` → `<AWG0_PORT>` (never 51820).
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
Clone the fork first so the templates are on the box:
```bash
git clone https://github.com/damnedest/iplist.git /opt/iplist
git -C /opt/iplist remote rename origin fork
```

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

Write `/etc/amnezia/amneziawg/awg0.conf` from `/opt/iplist/deploy/server3/awg0.conf.example`:
- `ListenPort = <AWG0_PORT>`, `PrivateKey` = awg0.privkey, `HeaderProtectionKey` = hpk,
  `S1..S4`, `H1..H4` from above. Leave `I1..I5` absent.
Write `/etc/wireguard/wg1.conf` from `/opt/iplist/deploy/server3/wg1.conf.example` with wg1.privkey,
Server 2's pubkey and IP. Then:
```bash
chmod 600 /etc/amnezia/amneziawg/awg0.conf /etc/wireguard/wg1.conf
echo "<SERVER3_PUBLIC_IP>" > /etc/amnezia/amneziawg/endpoint-host
awg-quick strip awg0 >/dev/null && echo "awg0.conf parses"
wg-quick  strip wg1  >/dev/null && echo "wg1.conf parses"
```
Verify: both `parses` lines; no `<` placeholders left: `grep -n '<' /etc/amnezia/amneziawg/awg0.conf /etc/wireguard/wg1.conf /etc/amnezia/amneziawg/endpoint-host` prints nothing. `git -C /opt/iplist remote -v` shows `fork`.

**Give Server 2 this box's `wg1` public key** (`cat /etc/wireguard/wg1.pubkey`) and
run `RUNBOOK-server2.md` §8 there now.

## 6. sysctl + generate the CIDR set
```bash
cat > /etc/sysctl.d/99-awg.conf <<'EOF'
net.ipv4.ip_forward=1
net.ipv4.conf.all.rp_filter=2
EOF
sysctl --system
cd /opt/iplist && make awg-all
```
Verify: `sysctl net.ipv4.ip_forward net.ipv4.conf.all.rp_filter` → `1` and `2`; `generated/awg-set.nft` exists.

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
```bash
grep -n '<' /etc/nftables.d/awg.nft          # must print nothing (WAN placeholder replaced)
nft list chain inet awg postrouting          # both masquerade rules name real interfaces
```
```bash
# SSH still works and the set is populated -> disarm the rollback NOW (it would otherwise
# fire in 10 minutes, flush table 100 and reload nftables with an EMPTY awgvia set).
systemctl list-units --plain --no-legend 'run-*.timer'
systemctl stop $(systemctl list-units --plain --no-legend 'run-*.timer' | awk '{print $1}')
systemctl list-units --plain --no-legend 'run-*.timer'      # must print nothing
```
Re-arm the same `systemd-run` guard before any later step that edits `/etc/nftables.conf`
or `/etc/nftables.d/awg.nft`; §8–§10 only add `ip rule`/`ip route` entries scoped to
marked or Telegram traffic and cannot lock you out.

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
| 4 | netns client, `curl -s --max-time 10` to an IP-echo service whose IP is **not** in the set | Server 3's IP |
| 5 | netns client, `curl -s --max-time 10` to an IP-echo service whose IP **is** in the set | Server 2's IP |
| 6 | from the §9 test client (real awg0 path): curl the in-set echo service and the out-of-set one | Server 2's IP and Server 3's IP respectively |
| 7 | `systemctl stop wg-quick@wg1` → in-set curl | `000`; direct still works; `start` restores without touching awg-pbr |
| 8 | `systemctl restart awg-pbr; ip route show table 100` | still has `default dev wg1` |
| 9 | `curl -o /dev/null https://<large file>` via both paths | completes |
| 10 | `reboot`; then `systemctl --failed`, re-run 4–7 | 0 failed, all pass |
| 11 | `systemctl start awg-update.service` once (first run adopts the baseline and sends the "initialized" Telegram message via the tunnel), then `sed -i '$d' /var/lib/awg/awg-cidr4.prev` and `systemctl start awg-update.service` again | both Telegram messages arrive; set reloaded on the second run |
| 12 | on Server 2: `wg show wg0` | both peers, Server 1 handshake never dropped; reboot Server 2 → both come back |
| 13 | `awg-add-client.sh second` while `testclient` streams; `--remove second`; `awg-add-client.sh testclient` again | no drop; peer gone; no duplicate |

Rows 4–6: classify each echo service first, then pick one of each kind
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

## 11. Confirm no rollback timer is left armed
```bash
systemctl list-units --plain --no-legend 'run-*.timer'      # must print nothing
```
The §7 guard was disarmed right after the firewall came up. If you re-armed it for a later
edit, stop it here. A timer that fires looks exactly like a routing bug: empty `awgvia`
set, missing `default dev wg1` in table 100 — recover with
`systemctl restart awg-pbr wg-quick@wg1 && nft -f /opt/iplist/generated/awg-set.nft`.

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
