# syntax=docker/dockerfile:1

ARG PHP_VERSION=8.4

# Base: FrankenPHP (Caddy + PHP) with the extensions the app needs.
FROM dunglas/frankenphp:1-php${PHP_VERSION} AS base

RUN apt-get update \
    && apt-get install -y --no-install-recommends curl supervisor \
    && rm -rf /var/lib/apt/lists/* \
    && install-php-extensions bcmath gd gmp intl opcache pcntl pdo_mysql pdo_pgsql redis sockets xsl zip

# PHP dependencies (production only) and the optimized autoloader.
FROM base AS php-build

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY . .
RUN mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && composer dump-autoload --optimize --no-dev --no-interaction

# Front-end assets. Tailwind scans the framework's pagination views, hence vendor/.
FROM node:22-slim AS assets

WORKDIR /app

COPY package.json package-lock.json .npmrc ./
RUN npm ci

COPY vite.config.js tailwind.config.js postcss.config.js ./
COPY resources ./resources
COPY --from=php-build /app/vendor/laravel/framework/src/Illuminate/Pagination/resources/views ./vendor/laravel/framework/src/Illuminate/Pagination/resources/views
RUN npm run build

# Final image.
FROM base AS runtime

WORKDIR /app

RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && useradd --uid 1000 --create-home --shell /usr/sbin/nologin app

COPY --from=php-build --chown=app:app /app /app
COPY --from=assets --chown=app:app /app/public/build /app/public/build
COPY docker/Caddyfile /etc/frankenphp/Caddyfile
COPY docker/supervisord.conf /etc/dmarc-supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint

RUN chmod +x /usr/local/bin/entrypoint \
    && mkdir -p storage bootstrap/cache /data/caddy /config/caddy \
    && chown -R app:app storage bootstrap/cache /data /config

# Every value below can be overridden with a container environment variable;
# there is no .env file in the image, the app reads the real environment.
ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    DB_CONNECTION=sqlite \
    DB_DATABASE=/app/storage/database/database.sqlite \
    DMARC_SMTP_HOST=0.0.0.0 \
    DMARC_SMTP_ENABLED=false \
    RUN_MIGRATIONS=true

USER app

# Persist /app/storage (SQLite database, stored reports, GeoIP data, the .eml/.msg inbox).
EXPOSE 8080 2525

HEALTHCHECK --interval=30s --timeout=5s --start-period=40s --retries=3 \
    CMD curl -fsS http://127.0.0.1:8080/up || exit 1

ENTRYPOINT ["entrypoint"]
CMD ["all"]
