#!/usr/bin/env bash
# Deploy Angular build to seohost.pl via FTP.
# Usage:
#   ./deploy.sh                   # build + deploy
#   ./deploy.sh --no-build        # deploy already-built dist only
#
# Required env vars (or edit defaults below):
#   FTP_HOST   – FTP hostname, e.g. ftp.feer.org.pl
#   FTP_USER   – FTP username
#   FTP_PASS   – FTP password
#   FTP_DIR    – remote directory, default /public_html/newUI
#
# Requires lftp: brew install lftp  (macOS)
set -euo pipefail

FTP_HOST="${FTP_HOST:-ftp.feer.org.pl}"
FTP_USER="${FTP_USER:-}"
FTP_PASS="${FTP_PASS:-}"
FTP_DIR="${FTP_DIR:-/public_html/newUI}"

LOCAL_DIST="$(dirname "$0")/dist/ng-app/browser"

if [[ -z "$FTP_USER" || -z "$FTP_PASS" ]]; then
  echo "ERROR: set FTP_USER and FTP_PASS environment variables before running."
  echo "  export FTP_USER=twoj_login"
  echo "  export FTP_PASS=twoje_haslo"
  exit 1
fi

if [[ "${1:-}" != "--no-build" ]]; then
  echo "==> Building ng-app (production)..."
  bash "$(dirname "$0")/build-prod.sh"
fi

if [[ ! -d "$LOCAL_DIST" ]]; then
  echo "ERROR: dist not found at $LOCAL_DIST — run build-prod.sh first."
  exit 1
fi

echo ""
echo "==> Uploading to $FTP_HOST:$FTP_DIR ..."

lftp -u "$FTP_USER","$FTP_PASS" "$FTP_HOST" <<FTPCMD
set ftp:ssl-allow no
set net:timeout 30
set net:max-retries 3
mirror --reverse --delete --verbose \
  "$LOCAL_DIST/" \
  "$FTP_DIR/"
bye
FTPCMD

echo ""
echo "==> Deployed. Visit your site at https://feer.org.pl/newUI/"
