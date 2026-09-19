# RUNBOOK — Server 2 (dumb WireGuard egress gateway)

Server 2 sits in the target jurisdiction. It terminates a plain WireGuard tunnel
(`wg0`) from Server 1 and NATs everything that arrives on it out to the internet.
It holds no CIDR logic and no default-route policy — it just forwards + masquerades.

Run every step as **root**. Each step ends with a verification; do not proceed
until it passes.

## 0. Prerequisites
- Debian 13 (trixie), root or sudo shell.
- Public IPv4 and the WAN interface name (assumed `eth0` below — adjust if different).
- Your real SSH port (assumed `22` below).

## 1. Install packages
```bash
apt update && apt install -y wireguard nftables
```
Verify:
```bash
wg --version        # prints a wireguard-tools version
```

## 2. Enable IPv4 forwarding
```bash
cat > /etc/sysctl.d/99-awg.conf <<'EOF'
net.ipv4.ip_forward=1
EOF
sysctl --system
```
Verify:
```bash
sysctl net.ipv4.ip_forward   # => net.ipv4.ip_forward = 1
```

## 3. WireGuard keys + config
```bash
umask 077
cd /etc/wireguard
wg genkey | tee privkey | wg pubkey > pubkey
```
Install `wg0.conf` from `deploy/server2/wg0.conf.example`:
- `PrivateKey` = contents of `/etc/wireguard/privkey`
- Leave `[Peer] PublicKey` (Server 1's `wg1` pubkey) as a placeholder for now (Step 4).
```bash
chmod 600 /etc/wireguard/wg0.conf
```
Verify:
```bash
test "$(stat -c '%a' /etc/wireguard/wg0.conf)" = 600 && echo "perms ok"
```

## 4. Exchange keys with Server 1
- **Record for Server 1:** this box's **public key** (`cat /etc/wireguard/pubkey`),
  its **public IP**, and the WireGuard port **51820**.
- **Fill in later:** once Server 1's `wg1` public key is known, put it in
  `wg0.conf`'s `[Peer] PublicKey` and `systemctl restart wg-quick@wg0`.

## 5. Firewall (nftables)
Install `deploy/server2/nftables-server2.nft` as `/etc/nftables.conf`.
**Before enabling, confirm the SSH `accept` rule matches your real port** (edit the
`tcp dport 22 accept` line if you use a non-standard port) — otherwise you lock
yourself out.
```bash
nft -f /etc/nftables.conf
```
Verify **you still have your SSH session** and the ruleset loaded:
```bash
nft list ruleset | head        # shows table inet fw + table ip nat
```
Then persist:
```bash
systemctl enable --now nftables
```

## 6. Bring up the tunnel
```bash
systemctl enable --now wg-quick@wg0
```
Verify:
```bash
wg show wg0                    # interface is up and listening on 51820
```

## 7. Note
The handshake won't complete until Server 1 is up and pointed at this box
(see `deploy/RUNBOOK-server1.md`). After Server 1 is configured and Step 4's
peer key is filled in, `wg show wg0` should show a recent handshake and rising
transfer counters.

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
