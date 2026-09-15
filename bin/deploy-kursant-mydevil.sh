#!/usr/bin/env bash
# bin/deploy-kursant-mydevil.sh — wdróż kursantApp (Angular — panel kursanta
# I panel dydaktyka, jeden build: src/app/features/instructor to ten sam
# frontend co reszta) jako STATYCZNE pliki na MyDevil, pod ti.feer.org.pl/newUI/.
#
# Na starym VPS-ie kursantApp jeździł w kontenerze nginx, a Traefik robił
# PathPrefix('/newUI/') + strip prefiksu (docker/docker-compose.kursant.yml).
# MyDevil to zwykły Apache/PHP (bez Dockera/Traefika/Node do serwowania) —
# więc zamiast kontenera: build lokalny → statyczne pliki wprost do
# ~/feerSZO/newUI/ na koncie, a SPA-fallback (odpowiednik nginx
# `try_files $uri $uri/ /index.html`) robi .htaccess w tym katalogu.
#
# WYMAGANE (jednorazowo, patrz [.htaccess](../.htaccess)): katalog /newUI/
# musi być wyłączony z catch-alla subdomeny ti.* — już zrobione w repo
# (wyjątek "newUI" w RewriteCond obok auth/assets/uploads/errors/api).
#
# API: kursantApp woła względne /api/v1/kursant_student.php i
# /api/v1/dydaktyk_instructor.php (patrz src/app/core/services/*.ts) — to
# ten sam origin (ti.feer.org.pl), więc działa bez żadnej konfiguracji CORS
# ani zmiennych środowiskowych API w Angularze.
#
# Wymaga: node+npm lokalnie, rsync, oraz bin/.deploy-mydevil.conf (SSH/domena).
#
# Użycie:
#   bash bin/deploy-kursant-mydevil.sh                # build + wyślij
#   bash bin/deploy-kursant-mydevil.sh --dry-run       # tylko build, bez wysyłki
#   bash bin/deploy-kursant-mydevil.sh --skip-build     # użyj istniejącego dist/
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(dirname "$SCRIPT_DIR")"
APP_DIR="${REPO_ROOT}/kursantApp"
DEPLOY_CONF="${SCRIPT_DIR}/.deploy-mydevil.conf"

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
CYAN='\033[0;36m'; BOLD='\033[1m'; RESET='\033[0m'
info()    { echo -e "${CYAN}▸ $*${RESET}"; }
ok()      { echo -e "${GREEN}✔ $*${RESET}"; }
warn()    { echo -e "${YELLOW}⚠ $*${RESET}"; }
die()     { echo -e "${RED}✖ $*${RESET}" >&2; exit 1; }
section() { echo -e "\n${BOLD}━━ $* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${RESET}"; }

[[ -f "$DEPLOY_CONF" ]] || die "Brak ${DEPLOY_CONF} — uruchom najpierw bin/deploy-mydevil-wizard.sh"
# shellcheck source=/dev/null
source "$DEPLOY_CONF"
[[ -n "${MYDEVIL_SSH:-}" ]]     || die "MYDEVIL_SSH nie ustawione w ${DEPLOY_CONF}"
[[ -n "${REMOTE_REPO_DIR:-}" ]] || die "REMOTE_REPO_DIR nie ustawione w ${DEPLOY_CONF}"

DRY=false; SKIP_BUILD=false
for arg in "$@"; do
    case "$arg" in
        --dry-run)    DRY=true ;;
        --skip-build) SKIP_BUILD=true ;;
        --help|-h) grep '^#' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) die "Nieznana opcja: $arg" ;;
    esac
done

DIST_DIR="${APP_DIR}/dist/kursant-app/browser"

# ── Build ──────────────────────────────────────────────────────────────────
if ! $SKIP_BUILD; then
    section "Build (Angular, production, base-href=/newUI/)"
    command -v npm >/dev/null || die "Brak 'npm' — zainstaluj Node.js."
    ( cd "$APP_DIR" && npm ci && npx ng build --configuration production --base-href=/newUI/ )
    [[ -f "${DIST_DIR}/index.html" ]] || die "Build nie wyprodukował ${DIST_DIR}/index.html — sprawdź logi wyżej."
    ok "Zbudowano: ${DIST_DIR}"
else
    section "Build pominięty (--skip-build)"
    [[ -f "${DIST_DIR}/index.html" ]] || die "Brak ${DIST_DIR}/index.html — usuń --skip-build albo zbuduj ręcznie najpierw."
fi

# ── .htaccess SPA-fallback (lokalnie, wysłany razem z resztą przez rsync) ───
cat > "${DIST_DIR}/.htaccess" <<'HTACCESS'
# Wygenerowane przez bin/deploy-kursant-mydevil.sh — SPA-fallback dla Angulara,
# odpowiednik nginx `try_files $uri $uri/ /index.html`.
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} -f [OR]
RewriteCond %{REQUEST_FILENAME} -d
RewriteRule ^ - [L]
RewriteRule ^ index.html [L]

# Brak cache dla index.html (jak w nginx.conf), długi cache dla reszty
# (pliki mają hash w nazwie z Angular CLI, więc bezpiecznie).
<IfModule mod_headers.c>
    <FilesMatch "^index\.html$">
        Header set Cache-Control "no-store"
    </FilesMatch>
    <FilesMatch "\.(js|css|woff2?|ttf|eot|svg|png|ico)$">
        Header set Cache-Control "public, max-age=31536000, immutable"
    </FilesMatch>
</IfModule>
HTACCESS
ok "Dopisano .htaccess (SPA-fallback) do dist/"

# ── Wysyłka ──────────────────────────────────────────────────────────────────
section "Wysyłka na MyDevil → ~/${REMOTE_REPO_DIR}/newUI/"
SSH_CTL_DIR="${HOME}/.ssh/feerszo-cm"
mkdir -p "$SSH_CTL_DIR" 2>/dev/null || true
SSH_OPTS=(-o "ControlMaster=auto" -o "ControlPath=${SSH_CTL_DIR}/%r@%h:%p" -o "ControlPersist=600")

if $DRY; then
    warn "[dry] rsync -avz --delete ${DIST_DIR}/ ${MYDEVIL_SSH}:${REMOTE_REPO_DIR}/newUI/"
else
    ssh "${SSH_OPTS[@]}" "$MYDEVIL_SSH" "mkdir -p ${REMOTE_REPO_DIR}/newUI"
    rsync -avz --delete -e "ssh ${SSH_OPTS[*]}" "${DIST_DIR}/" "${MYDEVIL_SSH}:${REMOTE_REPO_DIR}/newUI/"
    ok "Wysłano."
fi

section "Zostaje do zrobienia ręcznie (jednorazowo)"
cat <<EOF
  1. W config.local.php NA KONCIE MyDevil ustaw (jeśli jeszcze nie ma —
     bin/copy-config-local-to-mydevil.sh mógł to już przenieść ze starego VPS):
       define('KURSANT_NEW_UI_URL', 'https://${PRIMARY_DOMAIN/szo/ti}/newUI');
       define('KURSANT_NEW_UI_ENABLED', true);
  2. Test: https://ti.feer.org.pl/newUI/ (i głębokie linki, np. po odświeżeniu
     strony na konkretnej zakładce — to właśnie sprawdza .htaccess SPA-fallback).
  3. Panel dydaktyka jest w TYM SAMYM buildzie (src/app/features/instructor) —
     nic dodatkowego do wdrożenia, routing/rola w samym Angularze.
EOF
ok "Gotowe."
