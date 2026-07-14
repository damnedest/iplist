# AWG 2.0 → выборочная маршрутизация по CIDR через WireGuard-шлюз

**Дата:** 2026-07-08
**Статус:** черновик спеки (design), до реализации
**Цель:** два сервера на Debian 13. Клиенты подключаются к Server 1 по AmneziaWG 2.0.
Server 1 по таблице подсетей (CIDR-листы из этого репозитория) решает:
если destination попал в таблицу — гнать трафик на Server 2 по plain WireGuard;
если не попал — Server 1 сам выпускает трафик в интернет (NAT/masquerade).

---

## 1. Контекст и назначение

- **Сценарий:** обход блокировок. Клиент находится в стране с блокировками.
  Server 1 — «фронт» за границей (нейтральная локация), принимает клиентов по
  AmneziaWG 2.0 (обфускация против DPI). Server 2 — в целевой юрисдикции; часть
  сервисов доступна только с его IP.
- **Таблица подсетей** = CIDR-листы, генерируемые этим репозиторием (iplist) из
  `config/*/*.json` (поля `ip4` / `cidr4`). Объём — несколько тысяч CIDR.
- **Источник данных — форк, не upstream.** Данные берём из форка
  `git@github.com:damnedest/iplist.git` (remote `fork`), где лежат кастомные конфиги,
  а НЕ из upstream `rekryt/iplist` (remote `origin`, «мастер»). На Server 1
  репозиторий клонируется из форка, авто-обновление тянет из него же.
- **Только IPv4.** IPv6 в этой итерации не поддерживается (на клиентском AWG IPv6
  отключается, чтобы не было утечек мимо правил).

## 2. Выбранный подход

Маршрутизация **на уровне ядра** (вариант A), без userspace-прокси:

- Пакеты клиента приходят на Server 1 уже расшифрованными ядром (AWG — kernel-tunnel).
- **Policy-Based Routing (PBR):** nftables-сет с CIDR ставит `fwmark`, `ip rule`
  заворачивает помеченный трафик в отдельную таблицу маршрутизации с default через `wg1`.
- Непомеченный трафик идёт по main-таблице и выходит в интернет через `MASQUERADE`.

Отвергнутая альтернатива — userspace-прокси (sing-box/xray, вариант B): точнее
(домены, а не только IP), но тяжелее и требует распаковки/переупаковки трафика в
userspace. Не выбрана.

## 3. Топология

```
Клиент (страна с блокировками)
   │  AmneziaWG 2.0  (обфускация, обход DPI)
   ▼
Server 1  «фронт» (за границей)
   ├─ dst ∈ CIDR-таблице ──►  plain WireGuard (wg1)  ──►  Server 2 ──► интернет (целевая юрисдикция)
   └─ dst ∉ таблице ───────►  MASQUERADE ──► интернет (выход с IP Server 1)
```

Каждый хоп — одиночная инкапсуляция (на Server 1 трафик расшифровывается из AWG и
заново инкапсулируется в WG; двойного вложения на проводе нет).

## 4. Адресация и интерфейсы

### Server 1
| Интерфейс | Назначение | Адрес |
| --------- | ---------- | ----- |
| `eth0` | WAN | публичный IP-1 |
| `awg0` | AmneziaWG 2.0, клиенты | `10.9.0.1/24` |
| `wg1`  | plain WG до Server 2 (p2p) | `10.9.9.1/30` |

### Server 2
| Интерфейс | Назначение | Адрес |
| --------- | ---------- | ----- |
| `eth0` | WAN | публичный IP-2 |
| `wg0`  | plain WG от Server 1 (p2p) | `10.9.9.2/30` |

- Таблица маршрутизации PBR: `100` (имя `awgvia`).
- `fwmark`: `0x1`.

> Подсети `10.9.0.0/24` и `10.9.9.0/30` — примерные, при реализации свериться, что
> не пересекаются с существующими сетями на серверах.

## 5. Server 1 — конфигурация

### 5.1 AmneziaWG 2.0 (`awg0`)
- Установка на Debian 13: `amneziawg-dkms` + `amneziawg-tools` (репозиторий Amnezia;
  если пакета под trixie нет — сборка DKMS-модуля из исходников `amneziawg-linux-kernel-module`).
  Fallback — userspace `amneziawg-go`.
- Управление через `awg-quick up awg0` + systemd `awg-quick@awg0`.
- В конфиге интерфейса — обфускационные параметры **AWG 2.0**: `Jc/Jmin/Jmax`,
  `S1/S2`, `H1..H4` и добавления 2.0 (`I1..I5`, `Itime` — junk/маскировка).
  Значения согласованы между клиентом и сервером.
- `Address = 10.9.0.1/24`, IPv6 не назначаем.
- Клиентские `AllowedIPs = 0.0.0.0/0` (весь трафик клиента идёт в тоннель; развилка
  делается уже на Server 1, не на клиенте).

### 5.2 plain WireGuard до Server 2 (`wg1`)
- `wg-quick@wg1`. `Address = 10.9.9.1/30`, `MTU = 1420` (уточнить по факту, см. §7).
- Peer = Server 2: `AllowedIPs = 0.0.0.0/0` (в тоннель заворачивается весь помеченный
  трафик), `Endpoint = IP-2:51820`, `PersistentKeepalive = 25`.
- **Важно:** т.к. `wg-quick` с `AllowedIPs=0.0.0.0/0` по умолчанию добавляет default-route
  и правила — использовать `Table = off` (или `Table = 100`) в `[Interface]`, чтобы
  wg-quick НЕ трогал main-таблицу. Маршрут в таблицу `100` ставим сами (§5.4).

### 5.3 sysctl
```
net.ipv4.ip_forward = 1
net.ipv4.conf.all.rp_filter = 2      # loose, иначе PBR-ответы могут отбрасываться
```

### 5.4 Policy-Based Routing
```sh
# отдельная таблица: помеченный трафик уходит в тоннель на Server 2
ip route add default dev wg1 table 100
ip rule add fwmark 0x1 lookup 100

# FAIL-CLOSED: если wg1 недоступен — помеченный трафик дропается,
# НЕ утекает в прямой выход Server 1 (см. §8, каветы).
ip route add blackhole default table 100 metric 100   # ниже приоритетом, чем dev wg1
```
Персистентность: оформить как `systemd`-unit (`awg-pbr.service`, `After=wg-quick@wg1`)
или через `PostUp`/`PostDown` в конфиге `wg1`.

### 5.5 nftables — сет CIDR + маркировка + NAT + MSS clamp
Базовый статичный файл `/etc/nftables.d/awg.nft` (сет наполняется отдельно, §6):
```
table inet awg {
    set awgvia {
        type ipv4_addr
        flags interval
        auto-merge
        # элементы грузятся из generated/awg-set.nft (атомарно, §6)
    }

    chain prerouting {
        type filter hook prerouting priority mangle; policy accept;
        iifname "awg0" ip daddr @awgvia meta mark set 0x1
    }

    chain forward {
        type filter hook forward priority filter; policy accept;
        # MSS clamp — критично из-за инкапсуляции (см. §7)
        tcp flags syn tcp option maxseg size set rt mtu
    }

    chain postrouting {
        type nat hook postrouting priority srcnat; policy accept;
        oifname "eth0" masquerade      # прямой выход
        oifname "wg1"  masquerade      # в тоннель: Server 2 видит src = 10.9.9.1,
                                       # не нужно знать клиентскую подсеть (двойной NAT)
    }
}
```

## 6. Генерация и авто-обновление таблицы подсетей

### 6.1 make-команды (интерфейс обновления)
Все шаги обновления оформляются как make-цели, чтобы их можно было запускать и руками,
и из systemd timer (`.PHONY`, переменные `FORK ?= fork`, `BRANCH ?= master`,
`GENERATED_DIR ?= generated`, `NFT_SET_FILE ?= generated/awg-set.nft`):

| Цель | Что делает |
| ---- | ---------- |
| `awg-fetch` | `git fetch $(FORK)` + `git merge --ff-only $(FORK)/$(BRANCH)` — тянет свежие данные **из форка** (не из upstream). |
| `awg-all`   | Генерация артефактов из `config/*/*.json` (новый `scripts/build-cidr4-list.php`, см. ниже). |
| `awg-reload`| Атомарно применить сет: `nft -f $(NFT_SET_FILE)` (тоннели/интерфейсы не трогаются). |
| `awg-update`| Полный цикл для timer: `awg-fetch` → `awg-all` → diff → **если изменилось** `awg-reload` + Telegram-отчёт (через `scripts/awg-update.sh`). Иначе — тишина. |

`scripts/build-cidr4-list.php` (вызывается из `awg-all`) переиспользует логику
сбора/валидации CIDR из существующего `scripts/build-keenetic-routes-from-cidr4.php`
(чтение `config/*/*.json`, поля `ip4`/`cidr4`, валидация, дедуп/merge) и выдаёт два артефакта:
1. `generated/awg-cidr4.lst` — plain, по одному `a.b.c.d/nn` на строку.
2. `generated/awg-set.nft` — атомарная транзакция для nftables:
   ```
   flush set inet awg awgvia
   table inet awg {
       set awgvia { type ipv4_addr; flags interval; auto-merge;
           elements = { 1.0.0.0/9, 2.16.0.0/13, ... } }
   }
   ```
Цель **лёгкая**: только PHP-CLI читает уже готовые JSON (никакого `whois`/`ipcalc`,
никакого сетевого краулинга). Значит Server 1 генерирует сам.

### 6.2 Авто-обновление на Server 1 (раз в сутки)
Клон форка на Server 1; запуск `make awg-update` через **systemd timer**
(`awg-update.timer`, ежедневно ночью, `Persistent=true`). `scripts/awg-update.sh`
реализует diff+notify (то, что неудобно в чистом make):

1. `make awg-fetch` — свежие данные из форка (`damnedest/iplist`).
2. `make awg-all`.
3. Сравнить новый `generated/awg-cidr4.lst` со снапшотом прошлого прогона.
4. **Если изменилось:**
   - `make awg-reload` — атомарно применить сет (интерфейсы `awg0`/`wg1` и тоннели НЕ рвутся).
   - Telegram-отчёт (§6.3): сводка `+N / −M` и (усечённый при большом объёме) diff
     добавленных/удалённых CIDR.
   - обновить снапшот.
5. **Если без изменений** — ничего не делать, уведомление не слать.

Требования на Server 1: `php-cli`, `git`, `make`, `nftables`, `curl`.

### 6.3 Уведомление в Telegram
- Отправка одним `curl` на Bot API:
  `curl -s -X POST "https://api.telegram.org/bot$TG_TOKEN/sendMessage" -d chat_id="$TG_CHAT" -d parse_mode=HTML --data-urlencode text="..."`
- Никакого relay/MTA, нет проблем с deliverability VPS-IP, пуш прямо на телефон.
- Секреты (`TG_TOKEN`, `TG_CHAT`) — в `/etc/awg/telegram.env` (права `600`), НЕ в репозитории.
- Формат сообщения: `⚙️ awg CIDR updated: +N / −M` + список изменений
  (при большом diff — усечь до первых ~30 строк и указать «…and K more»).
- Ошибку отправки — писать в systemd journal (не терять факт обновления).

## 7. MTU / MSS (критично, не опционально)

- Клиент за AWG, далее трафик к Server 2 идёт ещё и через WG (`wg1`). Без коррекции
  крупные пакеты (TLS-хендшейки, загрузки) будут «зависать».
- Меры:
  - `wg1 MTU = 1420` (уточнить: `ip-2` overhead; при проблемах снижать до 1280).
  - `awg0 MTU` — по рекомендации awg-quick минус запас на junk-обфускацию 2.0.
  - **MSS clamp** в nftables forward-chain (`tcp option maxseg size set rt mtu`, §5.5)
    — обязательно, покрывает оба выхода.
- Проверка: `ping -M do -s <size>` вдоль пути; крупная HTTPS-загрузка через
  «помеченный» и «прямой» маршруты.

## 8. Каветы и принятые ограничения (осознанно)

1. **Неточность IP-маршрутизации на общих CDN.** Сервисы на Cloudflare/Fastly/Google
   делят IP с незаблокированными сайтами. CIDR-сет иногда гонит «лишнее» через Server 2
   или недоохватывает нужное. Цена простоты варианта A. **Принято.**
2. **Fail-closed.** При недоступности Server 2 помеченный трафик дропается (blackhole),
   а не утекает в прямой (заблокированный) выход Server 1. **Принято** (§5.4).
3. **Двойной NAT** (masquerade и на `eth0`, и на `wg1`). Упрощает Server 2 (не знает
   про клиентскую подсеть). Минус — на Server 2 не видно реальных клиентских IP; для
   этого сценария не нужно. **Принято.**
4. **Только IPv4.** IPv6 на клиенте отключается, чтобы не было утечки мимо правил.

## 9. Server 2 — конфигурация (тупой выходной шлюз)

- `wg-quick@wg0`: `Address = 10.9.9.2/30`, peer = Server 1
  (`AllowedIPs = 10.9.9.1/32` — т.к. Server 1 делает masquerade, source всегда `10.9.9.1`),
  `Endpoint`/`PersistentKeepalive` при необходимости.
- sysctl: `net.ipv4.ip_forward = 1`.
- nftables: `oifname "eth0" masquerade` для форварда из `wg0`.
- Больше ничего.

## 10. Проверка (acceptance)

1. Клиент подключается по AWG 2.0, есть интернет.
2. `curl` к IP **из** CIDR-таблицы → внешний IP = **IP-2** (Server 2).
3. `curl` к IP **вне** таблицы → внешний IP = **IP-1** (Server 1).
4. `nft list set inet awg awgvia | wc -l` ≈ ожидаемое число CIDR.
5. `ip rule` показывает `fwmark 0x1 lookup 100`; `ip route show table 100` — default dev wg1.
6. Останов `wg1` → трафик к таблице-IP не течёт мимо (fail-closed), прямой трафик работает.
7. `make awg-all` + правка config → `awg-update.sh` присылает письмо с `+N/-M`; повторный
   прогон без изменений — письма нет.
8. Крупная HTTPS-загрузка через оба маршрута без зависаний (MTU/MSS ок).

## 11. Артефакты для реализации

**В репозитории (форк `damnedest/iplist`):**
- `scripts/build-cidr4-list.php` — генератор `awg-cidr4.lst` + `awg-set.nft`.
- `Makefile` — цели `awg-fetch`, `awg-all`, `awg-reload`, `awg-update`.
- `scripts/awg-update.sh` — авто-обновление + diff + Telegram-отчёт.
- `deploy/` (документация/примеры):
  - `RUNBOOK-server1.md`, `RUNBOOK-server2.md` — пронумерованные чеклисты установки
    (пользователь выполняет под root; каждый шаг с проверкой результата).
  - unit-файлы systemd (`awg-update.service`/`.timer`, `awg-pbr.service`),
  - примеры конфигов `awg0.conf`/`wg1.conf`/`wg0.conf`, `nftables.d/awg.nft`,
    `telegram.env.example`.

**Форма реализации:** один план (writing-plans). Части:
- **Код репы** — генератор, make-цели, `awg-update.sh` — с локальным TDD.
- **Деплой серверов исполняет агент по SSH** (root-доступ предоставляет пользователь).
  Runbook’и в `deploy/` пишутся как воспроизводимый лог/для пересборки, но выполняет
  их агент, он же прогоняет приёмку (§10). Сначала целиком Server 2, затем Server 1.

**Деплой на серверах (исполняет агент по SSH):**
- Server 1: клон форка, AWG 2.0, wg1, nftables, PBR, systemd timer, curl+Telegram.
- Server 2: wg0, nftables masquerade, ip_forward.

## 12. Безопасность деплоя по SSH (не заблокировать себя)

Настройка nftables + PBR + WireGuard по SSH может отрезать управляющий доступ. Меры,
обязательные при исполнении:
- SSH до серверов — через уже настроенный SSH пользователя с локальной машины
  (агент вызывает `ssh <host> …` через Bash), без заведения отдельных ключей.
- Отдельное правило nftables, **всегда** разрешающее управляющий SSH на `eth0`
  (по порту), ставится ПЕРВЫМ; policy `drop` вслепую не применяется.
- Сетевые изменения (nft/`ip rule`/`ip route`) применяются через **rollback-таймер**
  (`systemd-run --on-active=<N>min …` откатывает к сохранённому конфигу, если агент
  не подтвердил успех) — при потере связи сервер сам вернётся в рабочее состояние.
- PBR-правила не должны заворачивать управляющий трафик сервера в туннель
  (маркируется только `iifname "awg0"`, локальный/SSH трафик — нет).
- Порядок: полностью поднять и проверить Server 2 (проще, независим) → затем Server 1.

**Требуется от пользователя перед деплой-фазой:** рабочий SSH до обоих серверов
(алиасы/IP, root или sudo, порт), перечень сервисов, которые нельзя ломать.
