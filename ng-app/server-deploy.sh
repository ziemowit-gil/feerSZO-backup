#!/usr/bin/env bash
# Uruchom na serwerze źródłowym (w katalogu ng-app):
#   cd /var/www/feerSZO/ng-app && bash server-deploy.sh
#
# Wymaga: Node.js >= 18, npm
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$SCRIPT_DIR"

echo "==> git pull..."
git -C "$(dirname "$SCRIPT_DIR")" pull

echo "==> npm ci (instalacja zależności)..."
npm ci

echo "==> ng build (production)..."
npx ng build --configuration production

DIST="$SCRIPT_DIR/dist/ng-app/browser"
WEBROOT="${WEBROOT:-$(dirname "$SCRIPT_DIR")/newUI}"

echo "==> Kopiowanie do $WEBROOT ..."
mkdir -p "$WEBROOT"
rsync -a --delete "$DIST/" "$WEBROOT/"

echo ""
echo "==> Gotowe. Angular dostępny pod /newUI/"
