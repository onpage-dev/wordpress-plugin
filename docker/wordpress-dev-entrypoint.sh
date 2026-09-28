#!/bin/bash
set -e

HOST_UID="${HOST_UID:-1000}"
HOST_GID="${HOST_GID:-1000}"

if [ "$(id -u)" = "0" ]; then
    CURRENT_UID="$(id -u www-data)"
    CURRENT_GID="$(id -g www-data)"

    if [ "$HOST_GID" != "$CURRENT_GID" ]; then
        groupmod -o -g "$HOST_GID" www-data
    fi

    if [ "$HOST_UID" != "$CURRENT_UID" ]; then
        usermod -o -u "$HOST_UID" www-data
    fi

    if [ -d /var/www/html/wp-content ]; then
        chown -R www-data:www-data /var/www/html/wp-content
    fi

    if [ -e /var/www/html/.htaccess ]; then
        chown www-data:www-data /var/www/html/.htaccess
    fi
fi

exec docker-entrypoint.sh "$@"
