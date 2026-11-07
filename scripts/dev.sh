#!/bin/sh
# Локальный запуск портала: PHP встроенный сервер + пересборка CSS.
# На хостинге не нужен — там работает Apache + PHP, а CSS уже собран в assets/css/app.css.
set -e
cd "$(dirname "$0")/.."

PORT="${PORT:-3000}"

if ! command -v php >/dev/null 2>&1; then
  if command -v dnf >/dev/null 2>&1; then
    echo "[dev] PHP не найден — ставлю php8.2 через dnf"
    sudo dnf install -y php8.2-cli php8.2-pdo php8.2-mbstring php8.2-xml php8.2-intl >/dev/null
  else
    echo "[dev] Нужен PHP 8.2+ с pdo_sqlite и mbstring" >&2
    exit 1
  fi
fi

# Рабочая копия базы для разработки, чтобы не трогать storage/gamerpro.sqlite.
if [ ! -f storage/dev.sqlite ]; then
  cp storage/gamerpro.sqlite storage/dev.sqlite
  echo "[dev] Создана storage/dev.sqlite из storage/gamerpro.sqlite"
fi
export GAMERPRO_DB="$(pwd)/storage/dev.sqlite"

if [ -x node_modules/.bin/tailwindcss ]; then
  node_modules/.bin/tailwindcss -i assets/css/src/app.css -o assets/css/app.css --watch=always &
fi

echo "[dev] http://localhost:$PORT"
exec php -S "0.0.0.0:$PORT" index.php
