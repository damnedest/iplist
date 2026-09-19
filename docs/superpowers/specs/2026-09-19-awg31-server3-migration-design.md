# AWG 3.1 на новом фронте (Server 3) и вывод Server 1

**Дата:** 2026-09-19
**Статус:** спека (design), до реализации
**Предыдущая спека:** `2026-07-08-awg-selective-routing-design.md` (as-built отличается от неё,
см. §2.3)

**Цель:** поднять третий сервер с AmneziaWG 3.1 в роли клиентского фронта, направить его
туннель на уже работающий Server 2 (plain WireGuard-выход), перевести клиентов с Server 1
(AWG 2.0) на Server 3 без простоя и после успешной технической приёмки вывести Server 1.

---

## 1. Контекст и решения

- **Почему новый сервер, а не апгрейд на месте.** AWG 3.1 несовместим с 2.0: Amnezia
  требует снести 2.0 и выдать всем новые ключи. Отдельный сервер даёт миграцию без простоя:
  Server 1 обслуживает старых клиентов, пока каждый не переедет.
- **Судьба Server 1.** После успешной технической приёмки Server 3 и переезда всех клиентов
  Server 1 выводится из эксплуатации (§6). Второй фронт на 3.1 не планируется, поэтому
  обобщённая «роль фронта» не делается (YAGNI).
- **Хостинг Server 3:** тот же хостер, что у Server 1, Debian 13 trixie. Ожидается то же
  кастомное ядро без headers, поэтому основной путь установки — userspace `amneziawg-go`,
  как уже работает на Server 1.
- **Клиенты:** мало, выдаются вручную. Добавляется скрипт `scripts/awg-add-client.sh` (§5).
- **Только IPv4.** Как и раньше.
- **Версии** (актуальные на дату спеки): `amneziawg-go` тег `v3.1.20260828`,
  `amneziawg-tools` тег `v3.1.20260812` (первый с параметрами 3.1). Клиенты: Amnezia VPN
  5.0.1.5+ или отдельные приложения AmneziaWG с поддержкой 3.1.

## 2. Топология и адресация

```
Клиент 3.1 ──AWG 3.1──► Server 3 (новый) ──wg1: 10.9.10.1/30──┐
                                                               ├──► Server 2 wg0 ──► интернет
Клиент 2.0 ──AWG 2.0──► Server 1 (старый) ──wg1: 10.9.9.1/30──┘   (10.9.9.2/30 + 10.9.10.2/30)
```

### 2.1 Server 3
| Интерфейс | Назначение | Адрес / порт |
| --------- | ---------- | ------------ |
| WAN (имя проверить на месте, на Server 1 это `eth0`) | публичный IP-3 | — |
| `awg0` | AmneziaWG 3.1, клиенты (userspace) | `10.9.0.1/24`, UDP-порт **случайный высокий**, выбирается при деплое и фиксируется в runbook; не 51820 |
| `wg1` | plain WG (ядро) до Server 2 | `10.9.10.1/30` |

- Клиентская сеть `10.9.0.0/24` совпадает с Server 1. Конфликта нет: сети независимы,
  Server 2 видит только маскированный адрес туннеля.
- PBR: таблица `100`, `fwmark 0x1` — без изменений.

### 2.2 Server 2
- `wg0` получает **второй адрес** `10.9.10.2/30` и **второй `[Peer]`** (Server 3,
  `AllowedIPs = 10.9.10.1/32`). Новых интерфейсов и правил nftables нет: masquerade на
  `ens3` уже покрывает любой источник.

### 2.3 As-built Server 1, которое наследует Server 3
Шаблоны в `deploy/server1/` отражают июльский план, а не фактическое состояние. Server 3
ставится по as-built:
- AWG в **userspace** (`amneziawg-go` в `/usr/bin`). `awg-quick` из tools 3.1 сам
  падает на `amneziawg-go`, если нет `/sys/module/amneziawg` и бинарь в `PATH`
  (переменная `WG_QUICK_USERSPACE_IMPLEMENTATION`, по умолчанию `amneziawg-go`), поэтому
  systemd-override не нужен; runbook вместо этого проверяет `pgrep amneziawg-go`.
  `wg1` — ядерный модуль.
- `awg-pbr.service` — **независимый always-on пол**: правило `ip rule fwmark 0x1 lookup 100`,
  `blackhole default table 100 metric 100`, правила `ip rule to <Telegram CIDR> lookup 100`.
  Без `Requires=wg-quick@wg1`. `ExecStop` удаляет только свой blackhole
  (`ip route del blackhole default table 100`), **не** `ip route flush table 100`.
- Маршрут `default dev wg1 table 100` ставится из `PostUp`/`PostDown` в `wg1.conf`.
- `awg-nftset.service` (oneshot, `After=nftables`) перезагружает `generated/awg-set.nft`
  после boot.
- Telegram CIDR через туннель: `149.154.160.0/20, 91.108.4.0/22, 91.108.8.0/21,
  91.108.16.0/21, 91.108.56.0/22, 95.161.64.0/20`.
- Клон форка `https://github.com/damnedest/iplist` в `/opt/iplist`, `awg-update.timer`
  ежедневно 04:17 UTC. `/etc/awg/telegram.env` (600) с тем же ботом и чатом.

## 3. Установка Server 3

### 3.1 Проверка headers
Первый шаг runbook: `apt install linux-headers-$(uname -r)`. Ожидается провал → userspace-путь
(§3.2). Если headers есть, DKMS-модуль допустим, но runbook описывает и проверяет только
userspace, потому что он уже проверен в бою.

### 3.2 AWG 3.1 userspace
1. Go из официального tarball `go.dev`, версия пинуется по `go.mod` тега `amneziawg-go`
   (3.1 собирался на Go 1.27.x). Установка в `/usr/local/go`.
2. `amneziawg-go`: `git clone`, `git checkout v3.1.20260828`, `make`, бинарь в
   `/usr/bin/amneziawg-go`.
3. `amneziawg-tools`: `git checkout v3.1.20260812`, `make -C src && make -C src install`.
   Даёт `awg`, `awg-quick`, `awg-quick@.service`. Старые tools параметры 3.1 не парсят.
4. Override systemd-юнита не нужен (см. §2.3); проверка — `pgrep -a amneziawg-go` после
   поднятия `awg0`.
5. `apt install wireguard nftables git make php-cli curl qrencode build-essential`.

Проверка: `awg --version` и `amneziawg-go --version` показывают 3.1.

### 3.3 Профиль обфускации 3.1
Все параметры живут в `[Interface]` серверного `/etc/amnezia/amneziawg/awg0.conf` и
только там. Шаблон `deploy/server3/awg0.conf.example` содержит плейсхолдеры и комментарий
с ролью каждого параметра:

| Параметр | Тип | Роль |
| -------- | --- | ---- |
| `Jc`, `Jmin`, `Jmax` | uint16 | количество и размер junk-пакетов |
| `S1..S4` | uint16 | случайные префиксы Init / Response / Cookie / Data |
| `H1..H4` | range<uint32> | идентификаторы типов сообщений |
| `I1..I5` | CPS | обфускационные пакеты до handshake |
| `HeaderProtectionKey` | 32-байтный ключ (base64) | шифрование заголовков |
| `ContentPaddingAddition` | range<uint16> | случайное дополнение payload |
| `RekeyAfterTime`, `RekeyTimeout`, `RejectAfterTime`, `KeepaliveTimeout`, `MaxHandshakeAttempts` | range<uint16> | рандомизация таймеров |
| `RandomTrailers` | on/off | случайные трейлеры |
| `DisableCookies` | on/off | отключение Cookie Reply |

Значения генерируются один раз при деплое (`HeaderProtectionKey` — `head -c32 /dev/urandom |
base64`, остальные — в рекомендованных Amnezia диапазонах; runbook даёт конкретный рабочий
набор). Скрипт выдачи клиентов (§5) копирует их из серверного конфига по фиксированному списку
ключей, поэтому расхождение клиент-сервер исключено по построению.

### 3.4 Остальная конфигурация
- sysctl: `net.ipv4.ip_forward=1`, `net.ipv4.conf.all.rp_filter=2`.
- nftables `/etc/nftables.d/awg.nft`: сет `awgvia` (interval, auto-merge), маркировка
  `iifname "awg0" ip daddr @awgvia meta mark set 0x1`, MSS clamp в forward, masquerade на
  WAN и на `wg1`. SSH-порт в input-правилах проверяется на месте.
- `awg-nftset.service`, `awg-pbr.service`, `wg1.conf` с `Table = off`, `MTU = 1420`,
  `PostUp`/`PostDown` для маршрута в таблицу 100 — по §2.3.
- `awg-update.service` + `awg-update.timer`, `/etc/awg/telegram.env`.
- **Lockout safety**: перед первым `nft -f` — `systemd-run --on-active=10min` с откатом
  ruleset и таблицы 100, как в `RUNBOOK-server1.md` §5. Отменяется только после приёмки (§7).

### 3.5 Файлы в репо
```
deploy/server3/
  awg0.conf.example            # 3.1 параметры с плейсхолдерами и комментариями
  wg1.conf.example             # Table=off, PostUp/PostDown маршрута table 100
  awg-pbr.service              # as-built: независимый пол, blackhole, Telegram rules
  awg-nftset.service
  awg-update.service, awg-update.timer, telegram.env.example
  nftables-awg.nft
deploy/RUNBOOK-server3.md      # пошагово, каждая секция с проверкой
scripts/awg-add-client.sh
```
`deploy/server2/wg0.conf.example` и `RUNBOOK-server2.md` обновляются под два peer'а.

## 4. Изменения на Server 2

Применяются **живьём, без разрыва туннеля Server 1**:
```sh
ip addr add 10.9.10.2/30 dev wg0
wg set wg0 peer <SERVER3_WG1_PUBKEY> allowed-ips 10.9.10.1/32
```
Затем то же вносится в `/etc/wireguard/wg0.conf` для персистентности:
`Address = 10.9.9.2/30, 10.9.10.2/30` и новый блок `[Peer]`.

Проверки:
- `wg-quick strip wg0` парсит конфиг без ошибок (иначе после ребута ляжет и старый туннель).
- `wg show wg0` — handshake с обоих peer'ов; `ping 10.9.10.1` с Server 2 проходит.
- Handshake Server 1 не прервался.

## 5. Выдача клиентов: `scripts/awg-add-client.sh`

Bash + awk, около сотни строк, запускается на Server 3 от root. Попадает на сервер через
клон форка.

- `awg-add-client.sh <имя>`:
  1. Находит следующий свободный адрес в `10.9.0.0/24` по существующим `AllowedIPs` в
     `awg0.conf`.
  2. Генерирует ключевую пару (`awg genkey`/`awg pubkey`) и preshared key (`awg genpsk`).
  3. Дописывает `[Peer]` в `awg0.conf` с комментарием `# client: <имя>` и применяет
     **живьём**: `awg set awg0 peer <pub> preshared-key <file> allowed-ips 10.9.0.X/32`.
     Интерфейс не перезапускается, остальные клиенты не отваливаются.
  4. Собирает клиентский конфиг:
     - `[Interface]`: `PrivateKey`, `Address = 10.9.0.X/32`, `DNS = 1.1.1.1` по умолчанию, флаг `--dns` переопределяет (резолвер
       уходит в туннель вместе со всем трафиком), `MTU = 1280` (рекомендация Amnezia для
       3.1; флаг `--mtu` переопределяет), и все параметры обфускации из §3.3, скопированные
       из серверного `[Interface]`.
     - `[Peer]`: `PublicKey` сервера, `PresharedKey`, `Endpoint = IP-3:<порт>`,
       `AllowedIPs = 0.0.0.0/0`, `PersistentKeepalive = 25`.
  5. Сохраняет в `/etc/amnezia/amneziawg/clients/<имя>.conf` (600), печатает конфиг и QR
     (`qrencode -t ansiutf8`).
- Повторный вызов с существующим именем **не создаёт дубликат**, а показывает сохранённый
  конфиг и QR заново.
- `awg-add-client.sh --remove <имя>`: `awg set awg0 peer <pub> remove`, удаляет блок из
  `awg0.conf` и файл клиента.
- Ошибки (нет свободного адреса, имя с недопустимыми символами, `awg0` не поднят) —
  ненулевой код и сообщение, конфиг сервера не трогается.

## 6. Миграция и вывод Server 1

### 6.1 Параллельная работа
Пока идёт тест, Server 1 не трогаем. Клиенты получают конфиги 3.1 через §5, ставят их рядом
со старыми и переключаются. Старый профиль остаётся в приложении как запасной, пока Server 1
жив.

### 6.2 Условие выхода из теста
Техническая приёмка §7 пройдена **и** все клиенты переведены на 3.1 и подтвердили работу.
Полевой срок в днях не задаётся.

### 6.3 Вывод Server 1 (отдельная глава `RUNBOOK-server3.md`)
Только после §6.2, шаги строго по порядку:
1. **Server 2:** `wg set wg0 peer <SERVER1_PUBKEY> remove`, `ip addr del 10.9.9.2/30 dev wg0`,
   убрать peer и адрес из `wg0.conf`, `wg-quick strip wg0` без ошибок. Проверить, что
   handshake Server 3 цел.
2. **Server 1:** `systemctl disable --now awg-quick@awg0 wg-quick@wg1 awg-pbr awg-update.timer`.
   Удалить `/etc/awg/telegram.env` (токен бота не должен остаться на выводимой машине).
3. **Хостер:** удалить Server 1. Шаг последний и только вручную — после него отката нет.
4. **Гигиена доступа:** удалить deploy-ключ `claude-deploy` из `authorized_keys` на Server 2,
   если он больше не нужен; отключить парольный вход root на Server 2 и Server 3
   (`PasswordAuthentication no`, `PermitRootLogin prohibit-password`). Открытый долг с июля.

### 6.4 Репозиторий после вывода
`deploy/server1/` и `RUNBOOK-server1.md` не удаляются: первая строка получает пометку
`RETIRED <дата вывода>, see RUNBOOK-server3.md`. История развёртывания остаётся читаемой.

### 6.5 Откат
Пока Server 1 не удалён у хостера, откат тривиален: клиенты переключаются на старый профиль,
peer на Server 2 возвращается. После шага 6.3.3 отката нет.

## 7. Приёмка

Все проверки на Server 3, **до** отмены auto-rollback таймера и **до** выдачи конфигов
реальным клиентам. Автотестов в репо нет: ручной runbook с командами и ожидаемым выводом.

| # | Проверка | Ожидание |
| - | -------- | -------- |
| 1 | `awg --version`, `amneziawg-go --version`, `awg-quick strip awg0` | 3.1, конфиг парсится |
| 2 | Тестовый клиент 3.1 | `awg show awg0` — свежий handshake |
| 3 | Клиент с конфигом 2.0, направленный на Server 3 | handshake **нет** (подтверждает реальный 3.1) |
| 4 | netns-клиент: `curl` на адрес **не** из сета | выход с IP-3 |
| 5 | netns-клиент: `curl` на адрес из сета | выход с IP-2 |
| 6 | `systemctl stop wg-quick@wg1` | in-set → `000`, прямой трафик работает; после `start` восстанавливается без рестарта `awg-pbr` |
| 7 | `systemctl restart awg-pbr` | `ip route show table 100` по-прежнему содержит `default dev wg1` (регрессия июльского бага) |
| 8 | Большая HTTPS-загрузка по обоим путям | проходит (MSS clamp работает) |
| 9 | Reboot Server 3 | `systemctl --failed` пуст, п. 4–6 повторно проходят |
| 10 | `systemctl start awg-update.service` с принудительным изменением сета | сообщение в Telegram доставлено через туннель |
| 11 | Server 2 после добавления peer'а | handshake Server 1 не терялся; после reboot Server 2 `wg0` поднимается с обоими peer'ами |
| 12 | `awg-add-client.sh`: второй клиент, `--remove`, повторный вызов | сессия первого клиента не рвётся; peer удалён; дубликата нет |

Только после этого — отмена rollback-таймера и выдача конфигов клиентам.
