#!/bin/sh
# ─────────────────────────────────────────────────────────────────────────────
# Polaris EA fork — production entrypoint.
#
# The image ships NO config.php (it is .dockerignore'd / gitignored because it
# holds DB credentials). This entrypoint generates it from environment variables
# on every boot (env is the source of truth), waits for the database, optionally
# runs migrations, then hands off to php-fpm.
#
# Runs as www-data (see Dockerfile `USER www-data`). config.php is pre-created
# www-data-owned in the image so this script can overwrite it.
#
# Environment:
#   EA_DB_HOST       (default: ea-mysql)
#   EA_DB_NAME       (default: easyappointments)
#   EA_DB_USER       (default: eauser)
#   EA_DB_PASSWORD   (default: empty)
#   EA_BASE_URL      (default: http://localhost)  EA's own public base URL
#   EA_DEBUG_MODE    (default: false)             PHP literal — false in prod
#   EA_AUTO_MIGRATE  (default: false)             run `console migrate` on boot
#   (EA_JWT_SIGNING_SECRET / POLARIS_EA_JWT_SECRET / POLARIS_* read directly by the app)
# ─────────────────────────────────────────────────────────────────────────────
set -eu

: "${EA_DB_HOST:=ea-mysql}"
: "${EA_DB_NAME:=easyappointments}"
: "${EA_DB_USER:=eauser}"
: "${EA_DB_PASSWORD:=}"
: "${EA_BASE_URL:=http://localhost}"
: "${EA_DEBUG_MODE:=false}"
export EA_DB_HOST EA_DB_NAME EA_DB_USER EA_DB_PASSWORD

cat > /var/www/html/config.php <<PHP
<?php
class Config {
    const BASE_URL    = '${EA_BASE_URL}';
    const LANGUAGE    = 'english';
    const DEBUG_MODE  = ${EA_DEBUG_MODE};
    const DB_HOST     = '${EA_DB_HOST}';
    const DB_NAME     = '${EA_DB_NAME}';
    const DB_USERNAME = '${EA_DB_USER}';
    const DB_PASSWORD = '${EA_DB_PASSWORD}';
}
PHP
echo "EA entrypoint: wrote config.php (DB_HOST=${EA_DB_HOST} DB_NAME=${EA_DB_NAME} BASE_URL=${EA_BASE_URL} DEBUG=${EA_DEBUG_MODE})"

# Wait for the database to accept connections (max ~60s).
n=0
while :; do
    if php -r 'try { new PDO("mysql:host=".getenv("EA_DB_HOST").";dbname=".getenv("EA_DB_NAME"), getenv("EA_DB_USER"), getenv("EA_DB_PASSWORD")); exit(0); } catch (Throwable $e) { exit(1); }' >/dev/null 2>&1; then
        echo "EA entrypoint: database reachable"
        break
    fi
    n=$((n + 1))
    if [ "$n" -ge 30 ]; then
        echo "EA entrypoint: WARNING — database not reachable after 60s; starting php-fpm anyway"
        break
    fi
    echo "EA entrypoint: waiting for database ($n/30)..."
    sleep 2
done

# Optional schema migration — idempotent (CI3 applies only pending migrations).
if [ "${EA_AUTO_MIGRATE:-false}" = "true" ]; then
    echo "EA entrypoint: EA_AUTO_MIGRATE=true — running migrations"
    php /var/www/html/index.php console migrate || echo "EA entrypoint: WARNING — migrate failed; continuing"
fi

exec "$@"
