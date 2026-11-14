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

Като root на новия сървър · As root on the fresh server:

```bash
adduser deploy && usermod -aG sudo deploy
rsync --archive --chown=deploy:deploy ~/.ssh /home/deploy      # копира вашия SSH ключ · copies your SSH key
```

От друг терминал проверете, че `ssh deploy@SERVER` работи, **после** · In another terminal confirm `ssh deploy@SERVER` works, **then**:

```bash
sudo sed -i 's/^#\?PasswordAuthentication.*/PasswordAuthentication no/' /etc/ssh/sshd_config
sudo sed -i 's/^#\?PermitRootLogin.*/PermitRootLogin no/' /etc/ssh/sshd_config
sudo systemctl restart ssh
```

Защитна стена · Firewall (`ufw`):

```bash
sudo ufw default deny incoming && sudo ufw default allow outgoing
sudo ufw allow 22/tcp && sudo ufw allow 80/tcp && sudo ufw allow 443/tcp
# Таблото на Coolify (порт 8000) — само от вашето IP, или го затворете след настройката:
sudo ufw allow from ВАШЕТО.IP to any port 8000 proto tcp
sudo ufw enable
```

Coolify ползва и други портове (напр. за терминала и реалното време) — вижте официалната документация за защитната стена преди да затворите всичко. · Coolify uses other ports too (e.g. terminal / realtime) — read the official firewall notes in its docs before closing everything.

Автоматични актуализации за сигурност · Automatic security updates:

```bash
sudo apt update && sudo apt install -y unattended-upgrades
sudo dpkg-reconfigure -plow unattended-upgrades
```

Swap 2 GB:

```bash
sudo fallocate -l 2G /swapfile && sudo chmod 600 /swapfile
sudo mkswap /swapfile && sudo swapon /swapfile
echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab
```

---

## 3. Coolify

1. **Инсталация · Install** — само с официалната команда от документацията · only with the official command from the docs: <https://coolify.io/docs/get-started/installation>
2. Първи вход · First login: отворете таблото (`http://SERVER-IP:8000`), създайте администратор веднага — първият, който го отвори, го взима. · open the dashboard, create the admin account immediately — whoever opens it first owns it.
3. **Нов ресурс · New resource** → публично GitHub репо (адресът на репото) → build pack **Docker Compose**, файл `docker-compose.yml`.
4. **Променливи · Environment variables** — от `.env.docker.example`: задължителни `APP_URL`, `DB_PASSWORD`, `DB_ROOT_PASSWORD`; препоръчително и `APP_KEY` (виж раздел 5). За тест сървър: `COMPOSE_PROFILES=staging`, `EASYCO_NOINDEX=true`, `EASYCO_MAIL_SANDBOX=true`, `EASYCO_BASIC_AUTH_USER/PASSWORD`, `SESSION_DOMAIN=dev.raf.bg`, `SESSION_SECURE_COOKIE=true`. · Required: `APP_URL`, `DB_PASSWORD`, `DB_ROOT_PASSWORD`; `APP_KEY` recommended (section 5). For a test server set the staging switches shown above.
5. **Домейн · Domain**: на услугата **`varnish`** задайте `https://dev.raf.bg` (контейнерният порт е 80; ако Coolify иска порт в полето, пишете `https://dev.raf.bg:80`). Нищо друго не получава домейн. · Assign `https://dev.raf.bg` to the **`varnish`** service (container port 80; if Coolify asks for the port in the field, `https://dev.raf.bg:80`). No other service gets a domain.
6. HTTPS (Let's Encrypt) — Coolify го издава сам, когато DNS сочи към сървъра (раздел 4). · Coolify issues it itself once DNS points to the server.
7. Автоматичен deploy при push — включете в настройките на ресурса (webhook на GitHub / GitHub App според версията). · Enable auto-deploy in the resource settings (GitHub webhook / GitHub App, depending on the Coolify version).

Mailpit UI не е в интернет: слуша само на `127.0.0.1:8025` на сървъра · The Mailpit UI is not on the internet: it listens on the server's `127.0.0.1:8025` only:

```bash
ssh -L 8025:127.0.0.1:8025 deploy@SERVER      # после http://localhost:8025 · then open http://localhost:8025
```

---

## 4. DNS

В DNS панела на доставчика, който държи зоната на `raf.bg`, добавете запис · At the provider that hosts the `raf.bg` zone add:

| Type | Name | Value |
|------|------|-------|
| A | `dev` | IPv4 на сървъра · the server's IPv4 |

Проверка · Check (от Windows / from any machine): `nslookup dev.raf.bg` — трябва да върне IP-то на сървъра · must return the server's IP (propagation can take minutes).

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

**Проверки · Checks** (browser, зад site password ако е зададена · behind the site password if set):
- `/up` → 200 · начална страница · home · страница на продукт · product page · количка · cart · `/admin/login` → 200, вход · login.
- Тестова поръчка → писмото е в Mailpit (през SSH тунела) · a test order → the mail is in Mailpit (through the tunnel).
- `curl -sI https://dev.raf.bg/` → `X-Robots-Tag: noindex, nofollow`; `curl https://dev.raf.bg/robots.txt` → `Disallow: /`.

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
