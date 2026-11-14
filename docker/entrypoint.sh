#!/bin/sh
# EasyCo (DOCKER-1): runs on EVERY start of the `app` container (queue / scheduler do not use it: migrations run only here).
# Idempotent; fails loudly (set -e): a failed migration stops the container instead of serving a half-migrated site.
set -eu

cd /var/www/html

log() { printf '[entrypoint] %s\n' "$*"; }
as_app() { runuser -u www-data -- "$@"; }

# ---- php-fpm pool sizes from the environment (defaults: a 4 GB server) -----------------------------------------------------------------
export PHP_FPM_MAX_CHILDREN="${PHP_FPM_MAX_CHILDREN:-8}"
export PHP_FPM_START_SERVERS="${PHP_FPM_START_SERVERS:-2}"
export PHP_FPM_MIN_SPARE_SERVERS="${PHP_FPM_MIN_SPARE_SERVERS:-2}"
export PHP_FPM_MAX_SPARE_SERVERS="${PHP_FPM_MAX_SPARE_SERVERS:-4}"
export PHP_UPLOAD_MAX_FILESIZE="${PHP_UPLOAD_MAX_FILESIZE:-128M}"
export PHP_POST_MAX_SIZE="${PHP_POST_MAX_SIZE:-130M}"
envsubst < /usr/local/etc/php-fpm.d/www.conf.template > /usr/local/etc/php-fpm.d/www.conf

# ---- storage volume: the named volume hides the image's storage/ folders, so recreate the layout and the owner ------------------------
mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache

# ---- no APP_KEY yet: first install. Start php-fpm so `easyco-install` can run inside this container, but touch nothing else --------------
if [ -z "${APP_KEY:-}" ]; then
    log "WARNING: APP_KEY is empty. Skipping migrate / optimize. Run 'easyco-install' inside this container (it prints a key),"
    log "         put the key into the environment (Coolify), then redeploy. The site cannot work until then."
    exec php-fpm -F
fi

# ---- wait for MySQL (PDO, up to ~120 s) -----------------------------------------------------------------------------------------------
log "waiting for the database at ${DB_HOST:-mysql}:${DB_PORT:-3306} ..."
tries=0
until php -r '
    try {
        new PDO(
            sprintf("mysql:host=%s;port=%s;dbname=%s", getenv("DB_HOST") ?: "mysql", getenv("DB_PORT") ?: "3306", getenv("DB_DATABASE") ?: ""),
            getenv("DB_USERNAME") ?: "", getenv("DB_PASSWORD") ?: "", [PDO::ATTR_TIMEOUT => 3]
        );
    } catch (Throwable $e) { fwrite(STDERR, $e->getMessage()."\n"); exit(1); }
' 2>/dev/null; do
    tries=$((tries + 1))
    if [ "$tries" -ge 60 ]; then
        log "ERROR: the database did not answer in time"
        exit 1
    fi
    sleep 2
done
log "database is up"

# ---- storage link, migrations, caches, queue restart ------------------------------------------------------------------------------------
[ -e public/storage ] || as_app php artisan storage:link
as_app php artisan migrate --force
as_app php artisan optimize
# Workers keep the booted code: tell them to restart (the signal travels through the cache store, which must be shared: redis).
as_app php artisan queue:restart

log "starting php-fpm"
exec php-fpm -F
