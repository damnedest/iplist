# RUNBOOK — Server 1 (AmneziaWG 2.0 in, policy-based routing out)

Server 1 is the client-facing box. Clients connect to it over AmneziaWG 2.0 (`awg0`).
Traffic whose destination is in the generated `awgvia` nftables set is marked
(`fwmark 0x1`) and policy-routed through a plain WireGuard uplink (`wg1`) to
**Server 2**, which NATs it out in the target jurisdiction. Everything else exits
Server 1 directly. If `wg1` is down, marked traffic is **blackholed (fail-closed)**,
never leaked to the direct egress.

Run every step as **root**. Each step ends with a verification. Server 1 mutates
live networking — **Step 5 arms an auto-rollback timer before any network change**.

## 0. Prerequisites
- Debian 13 (trixie), root or sudo shell.
- Server 2 already deployed (`deploy/RUNBOOK-server2.md`); have its WG **public key**
  and **public IP** ready.
- WAN interface name (assumed `eth0`) and your real SSH port (assumed `22`).

## 1. Install packages
```bash
apt update
apt install -y wireguard nftables git make php-cli curl
```
**AmneziaWG 2.0:** add the AmneziaWG apt repository and install:
```bash
apt install -y amneziawg amneziawg-tools
```
**Fallbacks if no trixie package is available:**
- Build the kernel module via DKMS from `amneziawg-linux-kernel-module`:
  `apt install -y dkms` then follow the module's `dkms add/build/install` steps.
- Or use the userspace implementation `amneziawg-go`.

Verify:
```bash
awg --version
```

## 2. Clone the repo (the FORK, not upstream)
```bash
git clone git@github.com:damnedest/iplist.git /opt/iplist
```
Verify:
```bash
git -C /opt/iplist remote -v      # origin/fork should point at damnedest/iplist
```
**Deploy-time decision (confirm before enabling the timer):** keep `merge --ff-only`
in `make awg-fetch`, or, for a fully hands-off box, switch the deploy clone's fetch to
`git fetch fork && git reset --hard fork/master`. The `--ff-only` default is safe but
will fail (and skip the update) if the clone ever has local commits; `reset --hard`
is unattended-friendly but discards any local state.

## 3. Kernel networking sysctls
```bash
cat > /etc/sysctl.d/99-awg.conf <<'EOF'
net.ipv4.ip_forward=1
net.ipv4.conf.all.rp_filter=2
EOF
sysctl --system
```
Verify:
```bash
sysctl net.ipv4.ip_forward net.ipv4.conf.all.rp_filter
# => ip_forward = 1 ; rp_filter = 2 (loose, required for policy routing)
```

## 4. Keys + interface configs
```bash
umask 077
# awg0 keys
awg genkey | tee /etc/amnezia/amneziawg/awg0.privkey | awg pubkey > /etc/amnezia/amneziawg/awg0.pubkey
# wg1 keys
wg genkey | tee /etc/wireguard/wg1.privkey | wg pubkey > /etc/wireguard/wg1.pubkey
```
- Write `/etc/amnezia/amneziawg/awg0.conf` from `deploy/server1/awg0.conf.example`
  (real `PrivateKey`, obfuscation params matching your client, one `[Peer]` per client).
- Write `/etc/wireguard/wg1.conf` from `deploy/server1/wg1.conf.example`
  (real `PrivateKey`, Server 2's **public key** in `[Peer] PublicKey`, Server 2's
  **public IP** in `Endpoint`).
- Give Server 2 this box's **`wg1` public key** (`cat /etc/wireguard/wg1.pubkey`) so it
  can fill its `[Peer]` block (`deploy/RUNBOOK-server2.md` Step 4).
```bash
chmod 600 /etc/amnezia/amneziawg/awg0.conf /etc/wireguard/wg1.conf
```
Verify:
```bash
stat -c '%a %n' /etc/amnezia/amneziawg/awg0.conf /etc/wireguard/wg1.conf   # both 600
```

## 5. LOCKOUT SAFETY — arm an auto-rollback BEFORE applying rules
Schedule a one-shot that tears everything back down in 10 minutes, so a mistake
self-heals and returns your SSH:
```bash
systemd-run --on-active=10min --timer-property=AccuracySec=1s \
  /bin/sh -c 'nft flush ruleset; systemctl restart nftables; \
              ip route flush table 100; ip rule del fwmark 0x1 lookup 100 || true'
```
Now apply the base ruleset. **First confirm the SSH `accept` rule matches your real
port** (edit if non-standard). If you also run a drop-policy input chain on Server 1,
keep a management-input ruleset (as on Server 2) and ensure SSH stays accepted.
```bash
nft -f /etc/nftables.conf        # your base ruleset incl. deploy/server1/nftables-awg.nft
```
Verify **your SSH session is still alive**, then:
```bash
nft list ruleset | sed -n '1,40p'
```

## 6. Load the generated CIDR set
```bash
cd /opt/iplist
make awg-all
nft -f generated/awg-set.nft
```
Verify:
```bash
nft list set inet awg awgvia | head    # populated interval set
```

## 7. Bring up the uplink to Server 2
```bash
systemctl enable --now wg-quick@wg1
```
Verify:
```bash
wg show wg1                             # a recent handshake with Server 2
```

## 8. Policy-based routing (fail-closed)
Install `deploy/server1/awg-pbr.service`, then:
```bash
systemctl enable --now awg-pbr.service
```
Verify:
```bash
ip rule | grep 'fwmark 0x1 lookup 100'
ip route show table 100                 # default dev wg1  +  blackhole default metric 100
```

## 9. Bring up the client interface
```bash
systemctl enable --now awg-quick@awg0   # or wg-quick@awg0, per your AWG package
```
Connect a test client and verify it handshakes:
```bash
awg show awg0
```

## 10. Auto-update + Telegram
```bash
install -Dm600 deploy/server1/telegram.env.example /etc/awg/telegram.env
# edit /etc/awg/telegram.env with the real TG_TOKEN / TG_CHAT
cp deploy/server1/awg-update.service deploy/server1/awg-update.timer /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now awg-update.timer
systemctl start awg-update.service      # run once now
```
Verify: a Telegram message arrives, and:
```bash
systemctl list-timers awg-update.timer
journalctl -u awg-update.service --no-pager | tail
```

## 11. Acceptance + cancel the rollback
Run the §10 acceptance checks from the spec:
- `curl` to an **in-set** IP exits via **Server 2's** public IP.
- `curl` to an **out-of-set** IP exits via **Server 1's** public IP.
- `wg show` / `awg show` handshakes are healthy.
- Stop `wg1` (`systemctl stop wg-quick@wg1`) → in-set traffic is **blackholed**
  (fail-closed) while direct traffic still works; restart `wg1` afterwards.
- A large HTTPS download works over **both** paths (MTU/MSS clamp is effective).

Only once everything is verified, **cancel the auto-rollback timer**:
```bash
systemctl list-timers | grep run-       # find the transient run-<id>.timer
systemctl stop <run-xxxx.timer>          # (or let it fire for a clean re-apply)
```
