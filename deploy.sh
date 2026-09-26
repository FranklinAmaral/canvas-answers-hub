#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="${GRADERAI_PROJECT_DIR:-$SCRIPT_DIR}"

cd "$PROJECT_DIR"

echo "Atualizando código pelo Git..."
git pull

echo "Compilando assets frontend..."
docker run --rm \
    --user "$(id -u):$(id -g)" \
    --env NPM_CONFIG_CACHE=/tmp/npm-cache \
    --volume "$PROJECT_DIR:/app" \
    --workdir /app \
    node:22-alpine \
    sh -c "npm ci && npm run build"

echo "Instalando dependências PHP..."
docker compose exec graderai-app composer install --no-dev --optimize-autoloader

echo "Rodando migrations..."
docker compose exec graderai-app php artisan migrate --force

echo "Limpando caches..."
docker compose exec graderai-app php artisan optimize:clear

echo "Recriando caches..."
docker compose exec graderai-app php artisan config:cache
docker compose exec graderai-app php artisan route:cache
docker compose exec graderai-app php artisan view:cache

echo "Reiniciando worker e scheduler..."
docker compose restart graderai-worker graderai-scheduler

echo "Deploy finalizado com sucesso."
