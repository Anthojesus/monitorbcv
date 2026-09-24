#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

php artisan monitor:seed-catalog --force --demo-checks
php artisan optimize
echo "Catálogo de desarrollo cargado en este ambiente."
