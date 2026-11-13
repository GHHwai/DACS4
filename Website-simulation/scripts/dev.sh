#!/usr/bin/env bash
# Starts the web app (:8000) and the real-time gateway (:9501) together.
# PHP's built-in server defaults to 2 MB uploads, so raise the limits to match config/chat.php (20 MB/file, 5 files).
set -euo pipefail
cd "$(dirname "$0")/.."
[ -f .env ] || { cp .env.example .env; php artisan key:generate; }
[ -f database/database.sqlite ] || touch database/database.sqlite
php artisan migrate --force

ROUTER="$PWD/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php"
trap 'kill 0' EXIT INT TERM
(cd public && php -d upload_max_filesize=25M -d post_max_size=110M -S "${WEB_HOST:-127.0.0.1}:${WEB_PORT:-8000}" "$ROUTER") &
php artisan chat:serve &
wait
