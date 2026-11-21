# Deploy с Docker / Coolify · Deploy with Docker / Coolify (DOCKER-1)

Всички файлове са общи — нищо специфично за един сайт няма в репото; всичко идва от променливи на средата (`.env.docker.example`).
All files are generic — nothing site-specific lives in the repo; everything comes from environment variables (`.env.docker.example`).

Локалната разработка остава на **Laragon**. Docker е само за сървъра. · Local development stays on **Laragon**. Docker is for the server only.

> **Статус · Status.** Написано без Docker на машината на разработчика (`docker` липсва). `docker compose config`, build-ът и проверките в раздел 5 са **за проверка на сървъра** — не са изпълнявани. · Written on a machine without Docker. `docker compose config`, the image build and the checks in section 5 are **to be verified on the server** — they have not been run.

---

## 1. Какво е стекът · What the stack is

```
Интернет / Internet
      │  HTTPS (Let's Encrypt)
 ┌────▼─────────────┐
 │ Coolify proxy    │  TLS ends here; sets X-Forwarded-Proto / X-Forwarded-For
 └────┬─────────────┘
 ┌────▼─────┐   ┌──────┐   ┌──────────────┐
 │ varnish  ├──►│ web  ├──►│ app          │  php-fpm 8.3 (migrations on start)
 │ (pass-all│   │nginx │   └──────┬───────┘
 │  until P8│   └──────┘          │
 └──────────┘      ┌──────────────┼───────────────┬───────────┐
                   │ queue        │ scheduler     │ mysql 8   │ redis
                   │ queue:work   │ schedule:work │ (volume)  │ (cache/locks)
                   └──────────────┴───────────────┴───────────┘
 mailpit  — само за тест (profile "staging") · test environments only (profile "staging")
```

* `app`, `queue`, `scheduler` = **един образ** · **one image**. Само `app` пуска миграциите · Only `app` runs migrations.
* Опашката остава на **database** драйвера (както е в приложението днес); кеш и ключалки — **redis**; сесии — **database**. · The queue stays on the **database** driver (as the app uses today); cache/locks — **redis**; sessions — **database**.
* Redis без запис на диск: рестартът го изпразва; ключовете „завинаги" се смятат наново при липса, а при натиск на паметта (`volatile-lru`) се изхвърлят само ключове с TTL. · Redis has no persistence: a restart empties it; "forever" keys are recomputed on a miss, and under memory pressure (`volatile-lru`) only keys with a TTL are evicted.
* MySQL **8.4** (LTS). Версиите на образите са закачени (redis 7.4, nginx 1.27, varnish 7.6, mailpit v1.21.8) — без `latest`; сменят се умишлено. · Image versions are pinned — changed on purpose.
* Varnish VCL-ът е във собствен образ (`target: varnish`), без bind mount. · The VCL is baked into its own image.
* **Правило за опашката · Queue rule:** `DB_QUEUE_RETRY_AFTER` (3700) > най-дългия timeout на задача (3600, `DeleteUnusedMediaJob`); `QUEUE_WORKER_TIMEOUT` (90) < `DB_QUEUE_RETRY_AFTER`. Нова по-дълга задача → вдигнете първото. · `DB_QUEUE_RETRY_AFTER` must exceed the longest job timeout; the worker `--timeout` must stay below it.
* Томове · Volumes: `storage` (качени файлове, медия, логове · uploads, media, logs), `mysql`.
* Varnish сега е **прозрачен** (минава всичко, нищо не кешира). Реалното кеширане е P8. · Varnish is **transparent** for now (passes everything, caches nothing). Real caching is P8.

---

## 2. Подготовка на сървъра · Server preparation (Ubuntu 24.04)

Като root на новия сървър · As root on the fresh server (`<sudo-user>` = вашият sudo потребител, напр. `deploy`; името е произволно · your sudo user, e.g. `deploy`; any name works):

```bash
adduser <sudo-user> && usermod -aG sudo <sudo-user>
rsync --archive --chown=<sudo-user>:<sudo-user> ~/.ssh /home/<sudo-user>      # копира вашия SSH ключ · copies your SSH key
```

От друг терминал проверете, че `ssh <sudo-user>@SERVER` работи, **после** укрепете sshd (виж „sshd" по-долу; **не** с голо `PermitRootLogin no`). · In another terminal confirm `ssh <sudo-user>@SERVER` works, **then** harden sshd (see "sshd" below; **not** with a bare `PermitRootLogin no`).

Часова зона и swap · Timezone and swap (8 GB машина · an 8 GB machine: swap 4G):

```bash
sudo timedatectl set-timezone UTC
sudo fallocate -l 4G /swapfile && sudo chmod 600 /swapfile
sudo mkswap /swapfile && sudo swapon /swapfile
echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab
echo 'vm.swappiness=10' | sudo tee /etc/sysctl.d/99-swappiness.conf && sudo sysctl --system
```

Автоматични актуализации за сигурност · Automatic security updates:

```bash
sudo apt update && sudo apt install -y unattended-upgrades
sudo dpkg-reconfigure -plow unattended-upgrades
```

### Защитна стена · Firewall

**Външната защитна стена на доставчика е задължителна.** Портовете, публикувани от Docker, **заобикалят `ufw`** (Docker пише директно правила в `iptables`), така че правило в `ufw` не ги затваря. Политика във външната стена: **входящи само TCP 22, 80, 443; всичко друго се отхвърля.** · **The provider's external firewall is required.** Docker-published ports **bypass `ufw`** (Docker writes its own `iptables` rules), so a `ufw` rule does not close them. Policy at the external firewall: **allow incoming TCP 22/80/443 only, drop everything else.**

Напр. `coolify-proxy` публикува и **8080** (таблото на Traefik); само външната стена го държи затворено. · E.g. `coolify-proxy` also publishes **8080** (the Traefik dashboard); only the external firewall keeps it closed.

`ufw` на хоста остава като втори слой за самия хост (не за контейнерите) · `ufw` stays as a second layer for the host itself (not for containers):

```bash
sudo ufw default deny incoming && sudo ufw default allow outgoing
sudo ufw allow 22/tcp && sudo ufw allow 80/tcp && sudo ufw allow 443/tcp
sudo ufw enable
```

**Няма правило за порт 8000**: таблото на Coolify никога не е публично (раздел 3). · **No rule for port 8000**: the Coolify dashboard is never public (section 3).

### sshd

`PermitRootLogin no` **чупи Coolify**: той управлява `localhost` през SSH като root от Docker мрежата си. Затова drop-in `/etc/ssh/sshd_config.d/00-easyco.conf` (`00-`, за да се чете първи; първата стойност печели · `00-` so it is read first: the first value wins):

```
PermitRootLogin no
PasswordAuthentication no
KbdInteractiveAuthentication no
PubkeyAuthentication yes
AllowUsers <sudo-user> root@127.0.0.1 root@10.0.0.0/8 root@172.16.0.0/12 root@192.168.0.0/16

Match Address 127.0.0.1,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16
    PermitRootLogin prohibit-password
```

`PermitRootLogin no` breaks Coolify, which manages localhost over SSH as root from its Docker network. The `Match` block lets root in with a key from the local / Docker ranges only; `AllowUsers` lists who may log in at all.

```bash
sudo sshd -t && sudo systemctl restart ssh      # проверка на синтаксиса, после рестарт · syntax check, then restart
ssh root@SERVER                                 # ОТВЪН трябва да откаже · from OUTSIDE it must be denied
```

Не затваряйте текущата сесия, докато нов вход като `<sudo-user>` не работи от друг терминал. · Keep your current session open until a new login as `<sudo-user>` works from another terminal.


---

## 3. Coolify

1. **Инсталация · Install**: само с официалната команда от документацията · only with the official command from the docs: <https://coolify.io/docs/get-started/installation>
2. **Архив на `/data/coolify/source/.env`** (собственият `APP_KEY` на Coolify) **извън сървъра, веднага след инсталацията**: без него Coolify не може да чете запазените си тайни след преинсталация. · **Back up `/data/coolify/source/.env`** (Coolify's own `APP_KEY`) **off the server right after install**: without it Coolify cannot read its stored secrets after a reinstall.
3. **Таблото на Coolify никога не е публично** (порт 8000 не се отваря никъде). Достъп само през SSH тунел · **The dashboard is never public.** Reach it only through an SSH tunnel:

   ```bash
   ssh -L 8000:localhost:8000 -L 6001:localhost:6001 -L 6002:localhost:6002 <sudo-user>@SERVER
   # после · then open http://localhost:8000
   ```

   Без 6001/6002 таблото показва „Cannot connect to real-time service". · Without 6001/6002 the dashboard shows "Cannot connect to real-time service".
4. **Първият администратор** се създава в първите **10 минути** след инсталацията; първият, който отвори страницата, я взима. Ако ги изпуснете: `sudo docker restart coolify`. · **Create the first admin within 10 minutes** of the install; whoever opens it first owns it. If you miss that: `sudo docker restart coolify`.
5. **Достъп до репото · Repository access:** ресурс **„Private Repository (with Deploy Key)"**, build pack **Docker Compose**, файл `/docker-compose.yml`.
   * Генерирайте **ED25519** ключ в Coolify → Keys & Tokens; добавете го в GitHub → repo Settings → Deploy keys, **само за четене (read-only)**. · Generate an **ED25519** key in Coolify → Keys & Tokens; add it in GitHub → repo Settings → Deploy keys, **read-only**.
   * Защо не GitHub App: callback-ът и webhook-овете му изискват таблото да е публично достъпно, а нашето не е. · Why not the GitHub App: its callback and webhooks need the dashboard to be publicly reachable, and ours is not.
   * **Последица · Consequence:** няма автоматичен deploy при push; deploy-вате с бутона **Deploy / Redeploy**. · no auto-deploy on push; deploy with the **Deploy / Redeploy** button.
   * Когато Smart Scan предложи да импортира `.env.example`: **не**; той е за локална разработка. · When Smart Scan offers to import `.env.example`: **don't**; that file is for local development.
6. **Променливи · Environment variables**: от `.env.docker.example`. Coolify показва всяка `${VAR}` от compose файла. Задължителни · Required: `APP_URL`, `DB_PASSWORD`, `DB_ROOT_PASSWORD`, **`PRIVACY_HASH_KEY`** (раздел 5); препоръчително · recommended: `APP_KEY` (раздел 5). За тест сървър · For a test server: `EASYCO_NOINDEX=true`, `EASYCO_MAIL_SANDBOX=true`, `EASYCO_BASIC_AUTH_USER/PASSWORD`, `SESSION_DOMAIN=<your-domain>`, `SESSION_SECURE_COOKIE=true`. **`COMPOSE_PROFILES=staging` се добавя на ръка**: не е `${}` препратка, затова Coolify не я показва. · **`COMPOSE_PROFILES=staging` is added by hand**: it is not a `${}` reference, so Coolify does not list it.
7. **Домейн · Domain**: на услугата **`varnish`** задайте `https://<your-domain>` (контейнерният порт е 80; ако Coolify иска порт в полето, `https://<your-domain>:80`). Нищо друго не получава домейн. · Assign `https://<your-domain>` to the **`varnish`** service (container port 80; if Coolify asks for the port in the field, `https://<your-domain>:80`). No other service gets a domain.
8. HTTPS (Let's Encrypt): Coolify го издава сам, когато DNS сочи към сървъра (раздел 4). · Coolify issues it itself once DNS points to the server.
9. **Изходяща поща · Outgoing mail:** някои доставчици (netcup по подразбиране) **блокират изходящи 25/465/587**. Продукционният SMTP изисква махане на блокадата, или доставчик на поща през HTTPS API. · Some providers (netcup by default) **drop outgoing 25/465/587**. Production SMTP needs that block removed, or an HTTPS-API mail provider.

Mailpit UI не е в интернет: слуша само на `127.0.0.1:8025` на сървъра · The Mailpit UI is not on the internet: it listens on the server's `127.0.0.1:8025` only:

```bash
ssh -L 8025:127.0.0.1:8025 <sudo-user>@SERVER      # после http://localhost:8025 · then open http://localhost:8025
```

---

## 4. DNS

В DNS панела на доставчика, който държи зоната на домейна ви, добавете запис · At the provider that hosts your domain zone add:

| Type | Name | Value |
|------|------|-------|
| A | `dev` | IPv4 на сървъра · the server's IPv4 |

Проверка · Check (от Windows / from any machine): `nslookup <your-domain>` — трябва да върне IP-то на сървъра · must return the server's IP (propagation can take minutes).

---

## 5. Първа инсталация · First install

**Най-чистият път · Cleanest path:** генерирайте ключ преди първия deploy и го сложете в Coolify · generate a key before the first deploy and put it into Coolify:

```bash
echo "base64:$(openssl rand -base64 32)"      # → APP_KEY (запазете копие · keep a copy)
```

След първия deploy, в контейнера `app` (Coolify → услугата → Terminal, или `docker exec -it`), **веднъж** · After the first deploy, inside the `app` container, **once**:

```bash
easyco-install          # = docker/install.sh
```

Скриптът · The script: (1) ако `APP_KEY` е празен — печата ключ и спира (сложете го в Coolify, redeploy, пуснете пак) · if `APP_KEY` is empty it prints one and stops; (2) `migrate --force`; (3) сийдъри · seeders: `PricingSystemListsSeeder`, `StaffSystemRolesSeeder`, `ContentDraftsSeeder` (**не** `DatabaseSeeder` — той прави демо потребител · it creates a demo user); (4) `php artisan staff:create-administrator` — пита име / e-mail / парола, без стойности по подразбиране · prompts, no defaults.

**`PRIVACY_HASH_KEY` (ACC-4a) · ключ за поверителността: задължителна в продукция · required in production.** Подава се на `app`, `queue` и `scheduler` през `docker-compose.yml`. Ако е празна, стекът **стартира**, но `app` печата предупреждение при всеки старт, `php artisan about` показва `MISSING`, а заявките за забравяне се отказват · It reaches `app`, `queue` and `scheduler` through `docker-compose.yml`. If empty the stack **still starts**, but `app` logs a warning at every start, `php artisan about` shows `MISSING`, and forget requests are refused. Генериране · Generate: `php -r 'echo bin2hex(random_bytes(32));'` (или · or `openssl rand -hex 32`). Втора променлива, от същия вид като `APP_KEY`: ако е празна, `easyco-install` печата стойност (64 hex знака) и спира; сложете я в Coolify, redeploy, пуснете скрипта пак · A second variable like `APP_KEY`: if empty, `easyco-install` prints a value (64 hex characters) and stops; put it into Coolify, redeploy, run the script again. Ключът пази хешовете на дневника на забравените и списъка за потискане · It keys the hashes of the forget log and the suppression list. **Никога не го сменяйте след пускане в продукция и го архивирайте заедно с `.env`** · **never change it after go-live and back it up together with `.env`**: загубен или сменен ключ прави всички съществуващи потискания неразпознаваеми · a lost or changed key makes every existing suppression unmatchable. Без ключ (извън local/testing) заявките за забравяне се отказват с ясна грешка · Without it (outside local/testing) forget requests are refused with a clear error.

**Проверки · Checks** (browser, зад site password ако е зададена · behind the site password if set):
- `/up` → 200 · начална страница · home · страница на продукт · product page · количка · cart · `/admin/login` → 200, вход · login.
- Тестова поръчка → писмото е в Mailpit (през SSH тунела) · a test order → the mail is in Mailpit (through the tunnel).
- `curl -sI https://<your-domain>/` → `X-Robots-Tag: noindex, nofollow`; `curl https://<your-domain>/robots.txt` → `Disallow: /`.

---

## 6. Резервни копия · Backups

**Избор · Choice:** cron на **хоста** (не вътре в Coolify) — работи и ако Coolify се преинсталира; не зависи от версията му. · A **host** cron job (not inside Coolify) — survives a Coolify reinstall and does not depend on its version.

`/usr/local/bin/easyco-backup.sh` (`chmod 700`, собственик root · owned by root):

```bash
#!/bin/bash
set -euo pipefail
DEST=/var/backups/easyco; mkdir -p "$DEST"; STAMP=$(date +%F)

DB=$(docker ps --filter "label=com.docker.compose.service=mysql" --format '{{.Names}}' | head -n1)
docker exec "$DB" sh -c 'mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction --routines --triggers "$MYSQL_DATABASE"' \
  | gzip > "$DEST/db-$STAMP.sql.gz"

VOL=$(docker volume ls --format '{{.Name}}' | grep -E '(^|_)storage$' | head -n1)
docker run --rm -v "$VOL":/data:ro -v "$DEST":/backup alpine tar czf "/backup/storage-$STAMP.tar.gz" -C /data .

find "$DEST" -type f -mtime +7 -delete        # пази 7 дни · keep 7 days
```

```bash
echo '30 2 * * * root /usr/local/bin/easyco-backup.sh >> /var/log/easyco-backup.log 2>&1' | sudo tee /etc/cron.d/easyco-backup
```

Проверете, че имената на контейнера и тома се намират (`docker ps`, `docker volume ls`) — Coolify добавя свои префикси. · Check that the container and volume names are found — Coolify adds its own prefixes.

**Възстановяване · Restore:**

```bash
gunzip -c /var/backups/easyco/db-ДАТА.sql.gz | docker exec -i "$DB" sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"'
docker run --rm -v "$VOL":/data -v /var/backups/easyco:/backup alpine sh -c 'cd /data && tar xzf /backup/storage-ДАТА.tar.gz'
```

Копията са на същия сървър — копирайте ги и навън (напр. `rsync` към друга машина). Преди рискови стъпки правете **netcup snapshot**. · The copies sit on the same server — also copy them off-site (e.g. `rsync`). Take a **netcup snapshot** before risky steps.

---

## 7. Ежедневна работа · Daily work

* `git push` → Coolify прави deploy. При старт `app` пуска миграциите, `optimize` и `queue:restart`. · `git push` → Coolify deploys; on start `app` runs migrations, `optimize` and `queue:restart`.
* **Логове · Logs:** Coolify → ресурс → услуга → Logs; или `docker logs -f <контейнер>`. Приложението логва към stderr (`LOG_CHANNEL=stderr`). · Coolify → service → Logs, or `docker logs -f <container>`; the app logs to stderr.
* **Artisan:** в Terminal на `app` · in the `app` Terminal: `php artisan about`, `php artisan queue:failed`, …
* **Връщане назад · Rollback:** Coolify → Deployments → наново deploy на предишния commit (или `git revert` + push). Миграциите вървят само напред: ако deploy-ът е пуснал миграция, първо върнете базата от копие. · redeploy the previous commit from Coolify's deployment list (or `git revert` + push). Migrations only go forward: if the bad deploy ran a migration, restore the database from a backup first.

---

## 8. За други търговци (по-късно) · For other merchants (later)

Същите файлове с друг домейн (`APP_URL`, `SESSION_DOMAIN`, `SANCTUM_STATEFUL_DOMAINS`) и **без** staging превключвателите (`COMPOSE_PROFILES`, `EASYCO_NOINDEX`, `EASYCO_MAIL_SANDBOX`, `EASYCO_BASIC_AUTH_*`) — тогава няма Mailpit, сайтът се индексира, пощата тръгва през избрания в админа транспорт. · The same files with another domain and **without** the staging switches — no Mailpit, the site is indexable, mail goes through the transport chosen in the admin.

---

## Приложение: превключватели и лимити · Appendix: switches and limits

| Променлива · Variable | Ефект · Effect |
|---|---|
| `EASYCO_NOINDEX=true` | `X-Robots-Tag: noindex, nofollow` на всеки отговор + `/robots.txt` `Disallow: /` (в приложението, `App\Http\Middleware\NoIndexResponses`). Не покрива файлове, които nginx сервира директно (картинки, `build/`). |
| `EASYCO_BASIC_AUTH_USER` + `_PASSWORD` | HTTP basic auth за целия сайт **в приложението** (`SiteBasicAuth`): без htpasswd файл, едно и също зад всеки proxy. `/up` е отворен; админът си пази собствения вход. Само при двете зададени. |
| `EASYCO_MAIL_SANDBOX=true` | Всяко писмо отива към Mailpit, **каквото и да е избрано в админа** (`MailConfigurator::apply`, едно място). |
| `TRUSTED_PROXIES` | Докер мрежите (по подразбиране `10/8, 172.16/12, 192.168/16`); никога `*`. |
| Качвания · Uploads | nginx `NGINX_CLIENT_MAX_BODY_SIZE`, PHP `PHP_UPLOAD_MAX_FILESIZE`/`PHP_POST_MAX_SIZE` ≥ `MEDIA_MAX_VIDEO_SIZE_KB` (100 MB по подразбиране). |
| Ресурси · Resources (4 GB) | `PHP_FPM_MAX_CHILDREN=8`, `MYSQL_INNODB_BUFFER_POOL_SIZE=512M`, `VARNISH_SIZE=128m`, `REDIS_MAXMEMORY=128mb`. |

---

## Приложение: защита от ботове и режим на сигурност (P8d) · Appendix: bot protection and the security mode (P8d)

| Променлива · Variable / команда · command | Ефект · Effect |
|---|---|
| `SECURITY_MODE=log\|block` (по подразбиране · default **`log`**) | Единственият превключвател на всяко **ново блокиращо** правило на приложението (заключване на админ входа, honeypot полетата). В `log` правилото само записва какво **би** блокирало; в `block` блокира. Старите лимити (филтри, търсене, API, собственият лимит на Filament) **не** зависят от него. · The ONE switch of every new blocking rule of the app (admin-login lockout, honeypots). `log` only logs what it WOULD block; `block` blocks. The older limiters do not depend on it. |
| **Безопасен режим · Safe mode** | Ако правило пречи на истински потребители: `SECURITY_MODE=log` в Coolify → redeploy (или `php artisan config:clear` + рестарт на `app`). Нищо друго не се пипа. · If a rule gets in real users' way: set `SECURITY_MODE=log` in Coolify and redeploy (or `config:clear` + restart `app`). Nothing else to touch. |
| `php artisan security:bot-challenge off\|filters\|misses` | Записва «Усилена защита от ботове» (същата настройка като полето в админа: Настройки → Сигурност, само собственик) и я праща към кеш слоя веднага. · Stores the same setting as the admin field (Settings → Security, owner only) and pushes it to the cache layer at once. |
| `security:push-bot-challenge` (планиран · scheduled, всяка минута · every minute) | Праща записаната стойност към кеш слоя (Varnish я забравя при рестарт). Днес е **празна операция** (`NullBotChallengeSwitch`); истинската връзка идва с P8c. · Pushes the stored value to the cache layer. Today a no-op (`NullBotChallengeSwitch`); the real Varnish switch arrives in P8c. |
| `/__ec/trap` | Капан URL: логва IP + user agent, отговаря със страницата 404, не се кешира, `Disallow` в robots.txt. Баненето е работа на CrowdSec (P8c чете access log-а). · Trap URL: logged, answered with the 404, never cached, `Disallow`ed in robots.txt. Banning is CrowdSec's job (P8c reads the access log). |
| `POST /csp-report` | Получава CSP доклади (report-only), най-много 1 ред в лога на (директива, хост) за минута; нищо не се записва в базата. · Receives report-only CSP reports; one log line per (directive, host) per minute; nothing is stored in the database. |

**Какво добавя P8c · What P8c adds:** Varnish VCL (истинският превключвател на проверката за бот, `X-Forwarded-Proto`/HSTS), Anubis, Traefik/CrowdSec правилата (HSTS е в Traefik, не в приложението). · the Varnish VCL (the real bot-challenge switch), Anubis, the Traefik / CrowdSec rules (HSTS lives at Traefik, not in the app).
