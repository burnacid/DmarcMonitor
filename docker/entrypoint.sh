#!/bin/sh
set -e

cd /app

role="${1:-all}"

prepare() {
    if [ -z "${APP_KEY:-}" ]; then
        echo "APP_KEY is not set. Generate one with:" >&2
        echo "  docker run --rm <image> php artisan key:generate --show" >&2
        exit 1
    fi

    mkdir -p storage/app storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs

    if [ "${DB_CONNECTION:-sqlite}" = "sqlite" ]; then
        database="${DB_DATABASE:-/app/storage/database/database.sqlite}"
        mkdir -p "$(dirname "$database")"
        [ -e "$database" ] || touch "$database"
    fi

    php artisan optimize
}

smtp_enabled() {
    case "$(printf '%s' "${DMARC_SMTP_ENABLED:-false}" | tr 'A-Z' 'a-z')" in
        true | 1 | yes | on) return 0 ;;
        *) return 1 ;;
    esac
}

migrate() {
    if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
        php artisan migrate --force
    fi
}

case "$role" in
    web)
        prepare
        migrate
        exec frankenphp run --config /etc/frankenphp/Caddyfile --adapter caddyfile
        ;;
    scheduler)
        prepare
        exec php artisan schedule:work
        ;;
    smtp)
        if ! smtp_enabled; then
            echo "SMTP listener is disabled. Set DMARC_SMTP_ENABLED=true to start it."
            exit 0
        fi
        prepare
        exec php artisan dmarc:smtp-serve
        ;;
    all)
        prepare
        migrate
        exec supervisord -c /etc/dmarc-supervisord.conf
        ;;
    *)
        exec "$@"
        ;;
esac
