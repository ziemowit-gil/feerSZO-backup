#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")"

echo "==> Building ng-app (production)..."
npx ng build --configuration production

echo ""
echo "==> Done. Files ready in: dist/ng-app/browser/"
echo "    Upload the contents of that directory to /newUI/ on the server."
