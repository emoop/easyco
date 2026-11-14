# syntax=docker/dockerfile:1
#
# EasyCo image (DOCKER-1, deploy-docker.md). ONE image for app (php-fpm), queue and scheduler; a second, tiny target `web` (nginx + public/).
# Nothing here is specific to one site: everything site-specific arrives through environment variables.

ARG PHP_VERSION=8.3
ARG NODE_VERSION=22

# ---- 1. composer vendor (no dev packages, no scripts) ---------------------------------------------------------------------------------
FROM composer:2 AS vendor
WORKDIR /app
# The EasyCo domain packages are path repositories (packages/EasyCo/*): copy them BEFORE install. MIRROR = real copies, not symlinks,
# so vendor/ stays valid wherever it is copied to.
ENV COMPOSER_MIRROR_PATH_REPOS=1
COPY composer.json composer.lock ./
COPY packages ./packages
# The composer image has no intl / gd etc.; the final image has them, so the platform check is skipped here only.
RUN composer install --no-dev --prefer-dist --optimize-autoloader --no-scripts --no-interaction --ignore-platform-reqs

# ---- 2. front-end build (Vite -> public/build) ------------------------------------------------------------------------------------
FROM node:${NODE_VERSION}-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
# vite.config.js, resources/ and scripts/ (the `prebuild` font copy). vendor/ too: a stylesheet may import from a package.
COPY vite.config.js ./
COPY resources ./resources
COPY scripts ./scripts
COPY public ./public
COPY --from=vendor /app/vendor ./vendor
RUN npm run build

# ---- 3. the application image (php-fpm) -----------------------------------------------------------------------------------------
FROM php:${PHP_VERSION}-fpm-bookworm AS app

# Runtime libraries stay; build headers are removed again. GD WITH WebP: the media pipeline writes WebP variants
# (LaravelMediaImageProcessor -> toWebp(); Illuminate\Image default driver is `gd`).
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        libicu72 libzip4 libpng16-16 libjpeg62-turbo libwebp7 libfreetype6 libfcgi-bin gettext-base util-linux; \
    apt-get install -y --no-install-recommends \
        $PHPIZE_DEPS libicu-dev libzip-dev libpng-dev libjpeg-dev libwebp-dev libfreetype6-dev; \
    docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp; \
    docker-php-ext-install -j"$(nproc)" pdo_mysql intl bcmath zip exif opcache pcntl gd; \
    pecl install redis; \
    docker-php-ext-enable redis; \
    apt-get purge -y --auto-remove $PHPIZE_DEPS libicu-dev libzip-dev libpng-dev libjpeg-dev libwebp-dev libfreetype6-dev; \
    rm -rf /var/lib/apt/lists/* /tmp/pear

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-easyco.ini
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/zz-opcache.ini
# The pool is a TEMPLATE: the entrypoint fills in the sizes from the environment (envsubst) when the container starts.
COPY docker/php/www.conf.template /usr/local/etc/php-fpm.d/www.conf.template
RUN rm -f /usr/local/etc/php-fpm.d/www.conf /usr/local/etc/php-fpm.d/www.conf.default

WORKDIR /var/www/html
COPY --chown=www-data:www-data . .
COPY --from=vendor --chown=www-data:www-data /app/vendor ./vendor
COPY --from=assets --chown=www-data:www-data /app/public/build ./public/build
COPY --chmod=755 docker/entrypoint.sh /usr/local/bin/entrypoint.sh
COPY --chmod=755 docker/install.sh /usr/local/bin/easyco-install

# Package discovery + the Filament / Livewire assets that composer's scripts would publish (public/css/filament and public/js/filament are
# not in git). Throwaway settings, so that nothing connects to a database or needs a real key at build time.
RUN set -eux; \
    mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache; \
    export APP_ENV=production APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= \
           DB_CONNECTION=sqlite DB_DATABASE=:memory: CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync; \
    php artisan package:discover --ansi; \
    php artisan filament:upgrade; \
    php artisan vendor:publish --tag=laravel-assets --ansi --force; \
    ln -sfn /var/www/html/storage/app/public public/storage; \
    rm -f bootstrap/cache/config.php bootstrap/cache/routes-*.php; \
    chown -R www-data:www-data storage bootstrap/cache public

ENV APP_ENV=production APP_DEBUG=false
EXPOSE 9000
HEALTHCHECK --interval=15s --timeout=5s --start-period=60s --retries=5 \
    CMD SCRIPT_NAME=/ping SCRIPT_FILENAME=/ping REQUEST_METHOD=GET cgi-fcgi -bind -connect 127.0.0.1:9000 | grep -q pong
# The entrypoint execs php-fpm (PID 1). queue / scheduler override the command in docker-compose.yml.
CMD ["/usr/local/bin/entrypoint.sh"]

# ---- 4. web: nginx with public/ baked in ---------------------------------------------------------------------------------------
FROM nginx:1.27-alpine AS web
ENV NGINX_CLIENT_MAX_BODY_SIZE=128m
COPY docker/nginx/default.conf.template /etc/nginx/templates/default.conf.template
COPY --from=app /var/www/html/public /var/www/html/public
# public/storage -> storage/app/public: the `storage` volume is mounted at /var/www/html/storage by docker-compose.yml.
RUN rm -rf /var/www/html/public/storage && ln -sfn /var/www/html/storage/app/public /var/www/html/public/storage
