#!/bin/sh
# EasyCo (DOCKER-1): FIRST install only. Run by hand INSIDE the running `app` container (in the image it is also on the PATH as `easyco-install`):
#     docker exec -it <app-container> easyco-install        (or Coolify: the service's Terminal)
# Idempotent where it can be (the seeders skip what exists; the administrator command refuses when a staff member already exists).
set -eu

cd /var/www/html

as_app() { if [ "$(id -u)" = "0" ]; then runuser -u www-data -- "$@"; else "$@"; fi; }

echo "== EasyCo first install =="

# 1. APP_KEY. Never written into the image; the operator puts it into the environment (Coolify) and redeploys.
if [ -z "${APP_KEY:-}" ]; then
    KEY="$(as_app php artisan key:generate --show)"
    echo
    echo "APP_KEY is empty. Put this value into the environment of the application (Coolify -> Environment Variables), then REDEPLOY and run this script again:"
    echo
    echo "    APP_KEY=${KEY}"
    echo
    echo "Keep a copy somewhere safe: without it stored mail passwords and sessions cannot be read again."
    exit 2
fi

# 1b. PRIVACY_HASH_KEY (ACC-4a). The keyed hash of the forget log and the suppression list; NOT the APP_KEY, so an APP_KEY rotation cannot break it.
#     Same arrangement as APP_KEY: printed once, put into the environment by the operator, then redeploy and run this script again.
if [ -z "${PRIVACY_HASH_KEY:-}" ]; then
    PKEY="$(as_app php -r 'echo bin2hex(random_bytes(32));')"
    echo
    echo "PRIVACY_HASH_KEY is empty. Put this value into the environment of the application (Coolify -> Environment Variables), then REDEPLOY and run this script again:"
    echo
    echo "    PRIVACY_HASH_KEY=${PKEY}"
    echo
    echo "NEVER change it after go-live, and back it up together with .env: a lost or changed key makes every existing suppression (every person who was forgotten) unmatchable."
    exit 2
fi

# 2. Database (the entrypoint already migrates at every start; this is a safety net).
as_app php artisan migrate --force

# 3. The seeders a fresh database needs (DatabaseSeeder is NOT used: it creates a demo test user).
#    - Pricing system lists ("Regular" / "Sale" reserved lists), - Staff system roles (Administrator, ...), - the two empty legal-page drafts.
for seeder in 'EasyCo\Pricing\Seeders\PricingSystemListsSeeder' 'EasyCo\Staff\Seeders\StaffSystemRolesSeeder' 'Database\Seeders\ContentDraftsSeeder'; do
    echo "-- seeding ${seeder}"
    as_app php artisan db:seed --class="${seeder}" --force
done

# 4. The first administrator: the existing command prompts for name, e-mail and password (no defaults ship); it refuses if one exists.
echo "-- first administrator"
if ! as_app php artisan staff:create-administrator; then
    echo "(no administrator created: one may already exist; run 'php artisan staff:create-administrator --force' to add another)"
fi

cat <<'NEXT'

== Next steps ==
 1. Open the site, then /admin/login and sign in with the administrator you just created.
 2. Admin -> Settings: shop name, currency/locale, mail (on a test server mail goes to Mailpit whatever you choose, EASYCO_MAIL_SANDBOX).
 3. Check: home page, a product page, the cart, the admin, and a test order whose mail lands in Mailpit.
 4. Set up the nightly backup (packages/EasyCo/documents/deploy-docker.md, section 6).
NEXT
