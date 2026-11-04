#!/bin/sh
set -eu

if [ -n "${APP_KEY_FILE:-}" ]; then
    if [ ! -r "$APP_KEY_FILE" ]; then
        echo "APP_KEY secret is not readable." >&2
        exit 1
    fi
    APP_KEY="$(cat "$APP_KEY_FILE")"
    export APP_KEY
fi

if [ -n "${DB_PASSWORD_FILE:-}" ]; then
    if [ ! -r "$DB_PASSWORD_FILE" ]; then
        echo "Database password secret is not readable." >&2
        exit 1
    fi
    DB_PASSWORD="$(cat "$DB_PASSWORD_FILE")"
    export DB_PASSWORD
fi

if [ -z "${APP_KEY:-}" ] || [ -z "${DB_PASSWORD:-}" ]; then
    echo "Required application encryption/database secrets are missing." >&2
    exit 1
fi

exec "$@"
