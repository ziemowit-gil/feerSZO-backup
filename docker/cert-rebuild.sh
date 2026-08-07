#!/usr/bin/env bash
# cert-rebuild.sh — odbuduj certyfikat SSL (Let's Encrypt via Traefik) + cert aplikacji
#
# Uruchamiaj gdy:
#   - strona nie odpowiada (curl exit 7 / SSL error)
#   - Traefik nie może pobrać/odnowić certyfikatu
#   - acme.json jest uszkodzony lub pusty
#
# Użycie (na serwerze, w katalogu repozytorium):
#   bash docker/cert-rebuild.sh              # pełna odbudowa (SSL + app cert)
#   bash docker/cert-rebuild.sh --acme-only  # tylko SSL Traefik
#   bash docker/cert-rebuild.sh --app-only   # tylko cert aplikacji (certs/app.*)
#   bash docker/cert-rebuild.sh --status     # pokaż stan bez zmian
#
# Użycie przez SSH z lokalnej maszyny:
#   bash docker/cert-rebuild.sh --ssh user@serwer [opcje]
#
# Wymagania: docker, docker compose, dostęp do grupy "docker" lub sudo.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(dirname "$SCRIPT_DIR")"
ENV_FILE="${SCRIPT_DIR}/.env.prod"

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
CYAN='\033[0;36m'; BOLD='\033[1m'; RESET='\033[0m'
info()    { echo -e "${CYAN}▸ $*${RESET}"; }
ok()      { echo -e "${GREEN}✔ $*${RESET}"; }
warn()    { echo -e "${YELLOW}⚠ $*${RESET}"; }
die()     { echo -e "${RED}✖ $*${RESET}" >&2; exit 1; }
section() { echo -e "\n${BOLD}━━ $* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${RESET}"; }

# ── Tryb SSH — przekaż skrypt na serwer i uruchom tam ────────────────────────
if [[ "${1:-}" == "--ssh" ]]; then
    SSH_TARGET="${2:?--ssh wymaga user@host}"
    shift 2
    REMOTE_REPO="${REMOTE_REPO:-/opt/feerSZO}"
    info "Łączę z ${SSH_TARGET}…"
    ssh -t "$SSH_TARGET" "bash ${REMOTE_REPO}/docker/cert-rebuild.sh $*"
    exit $?
fi

# ── Argumenty ─────────────────────────────────────────────────────────────────
DO_ACME=1 DO_APP=1 STATUS_ONLY=0
for arg in "$@"; do
    case "$arg" in
        --acme-only) DO_APP=0  ;;
        --app-only)  DO_ACME=0 ;;
        --status)    STATUS_ONLY=1; DO_ACME=0; DO_APP=0 ;;
        *) die "Nieznana flaga: $arg" ;;
    esac
done

# ── Compose helper ────────────────────────────────────────────────────────────
[[ -f "$ENV_FILE" ]] || die ".env.prod nie istnieje: ${ENV_FILE}"
COMPOSE="docker compose \
  -f ${SCRIPT_DIR}/docker-compose.yml \
  -f ${SCRIPT_DIR}/docker-compose.prod.yml \
  --env-file ${ENV_FILE}"

# ── KROK 0: DNS resolver (systemd-resolved) ───────────────────────────────────
if [[ $STATUS_ONLY -eq 0 ]] && command -v systemctl &>/dev/null; then
    section "0. DNS resolver (systemd-resolved)"
    if ! systemctl is-active --quiet systemd-resolved 2>/dev/null; then
        warn "systemd-resolved nie działa — uruchamiam…"
        systemctl restart systemd-resolved 2>/dev/null || true
        sleep 2
        if systemctl is-active --quiet systemd-resolved 2>/dev/null; then
            ok "systemd-resolved uruchomiony"
        else
            warn "systemd-resolved wciąż nie działa — Docker DNS może nie działać w kontenerach"
            warn "Spróbuj ręcznie: sudo systemctl enable --now systemd-resolved"
        fi
    else
        ok "systemd-resolved działa"
    fi

    # Sprawdź czy DNS w ogóle odpowiada
    if ! host -W 3 acme-v02.api.letsencrypt.org &>/dev/null 2>&1; then
        warn "DNS lookup nie działa — sprawdź /etc/resolv.conf:"
        cat /etc/resolv.conf | head -5 || true
        warn "Jeśli brak 'nameserver', dodaj: echo 'nameserver 8.8.8.8' >> /etc/resolv.conf"
    else
        ok "DNS działa (lookup acme-v02.api.letsencrypt.org OK)"
    fi
fi

# ── STATUS ────────────────────────────────────────────────────────────────────
section "Stan"

# Traefik
if docker ps --filter "name=feer-traefik" --format "{{.Status}}" 2>/dev/null | grep -q Up; then
    ok "feer-traefik: działa"
else
    warn "feer-traefik: NIE działa (lub brak kontenera)"
fi

# Sprawdź ważność certyfikatu SSL (przez openssl, bez SNI trust)
DOMAIN="$(grep -E '^DOMAIN=' "$ENV_FILE" | cut -d= -f2 | tr -d '"' | xargs)"
if [[ -n "$DOMAIN" ]]; then
    ssl_expiry=$(echo Q | openssl s_client -connect "${DOMAIN}:443" -servername "$DOMAIN" \
        2>/dev/null | openssl x509 -noout -enddate 2>/dev/null | cut -d= -f2 || echo "brak")
    if [[ "$ssl_expiry" == "brak" ]]; then
        warn "SSL ${DOMAIN}: brak odpowiedzi (serwer niedostępny lub cert brakujący)"
    else
        info "SSL ${DOMAIN} ważny do: ${ssl_expiry}"
    fi
fi

# App cert
app_cert="${REPO_DIR}/certs/app.crt"
if [[ -f "$app_cert" ]]; then
    app_expiry=$(openssl x509 -noout -enddate -in "$app_cert" 2>/dev/null | cut -d= -f2 || echo "błąd")
    app_days=$(openssl x509 -noout -checkend 86400 -in "$app_cert" 2>/dev/null \
        && echo "OK" || echo "wygasa/wygasł")
    info "App cert (certs/app.crt): ${app_expiry} [${app_days}]"
else
    warn "App cert: brak pliku certs/app.crt"
fi

[[ $STATUS_ONLY -eq 1 ]] && exit 0

# ── KROK 1: Odbudowa certyfikatu SSL (ACME / Let's Encrypt) ──────────────────
if [[ $DO_ACME -eq 1 ]]; then
    section "1. Odbudowa SSL (Traefik + Let's Encrypt)"

    info "Zatrzymuję Traefik…"
    docker stop feer-traefik 2>/dev/null || warn "feer-traefik już zatrzymany"

    info "Resetuję acme.json w volume 'letsencrypt_data'…"
    # Tworzy pusty plik z uprawnieniami 600 — Traefik wymaga dokładnie tych uprawnień
    docker run --rm \
        -v letsencrypt_data:/letsencrypt \
        alpine \
        sh -c 'rm -f /letsencrypt/acme.json && touch /letsencrypt/acme.json && chmod 600 /letsencrypt/acme.json'
    ok "acme.json wyczyszczony"

    info "Uruchamiam stack (Traefik wystartuje i zażąda nowego certyfikatu)…"
    $COMPOSE up -d

    info "Czekam na uzyskanie certyfikatu (max 120 s)…"
    for i in $(seq 1 24); do
        sleep 5
        cert_ok=$(echo Q | openssl s_client -connect "${DOMAIN}:443" -servername "$DOMAIN" \
            2>/dev/null | openssl x509 -noout -checkend 0 2>/dev/null && echo yes || echo no)
        if [[ "$cert_ok" == "yes" ]]; then
            ok "Certyfikat SSL uzyskany i ważny! (po $((i * 5)) s)"
            break
        fi
        echo -n "  .."
        if [[ $i -eq 24 ]]; then
            warn "Timeout — cert jeszcze nie gotowy. Sprawdź logi Traefika:"
            docker logs feer-traefik --tail=30 2>&1 | grep -iE "error|cert|acme|challenge" || true
        fi
    done
fi

# ── KROK 2: Odbudowa certyfikatu aplikacji (certs/app.*) ─────────────────────
if [[ $DO_APP -eq 1 ]]; then
    section "2. Certyfikat aplikacji (certs/app.*)"

    days=$(docker exec feer-app php -r "
        \$f = '/var/www/html/certs/app.crt';
        if (!file_exists(\$f)) { echo -999; exit; }
        \$p = openssl_x509_parse(file_get_contents(\$f));
        if (!\$p) { echo -999; exit; }
        echo (int)ceil((\$p['validTo_time_t'] - time()) / 86400);
    " 2>/dev/null) || days=-999

    if [[ "${days}" -le 30 ]]; then
        if [[ "${days}" -eq -999 ]]; then
            warn "Brak certyfikatu aplikacji — generuję…"
        elif [[ "${days}" -le 0 ]]; then
            warn "Certyfikat aplikacji wygasł — regeneruję…"
        else
            warn "Certyfikat aplikacji wygasa za ${days} dni — odnawiam…"
        fi
        docker exec feer-app php /var/www/html/cli/refresh_cert.php \
            2>&1 | grep -E "\[OK\]|\[INFO\]|\[BLAD\]|Error|error" || true
        ok "Certyfikat aplikacji odnowiony"
    else
        ok "Certyfikat aplikacji OK (${days} dni do wygaśnięcia)"
    fi
fi

# ── Podsumowanie ──────────────────────────────────────────────────────────────
section "Gotowe"
$COMPOSE ps --format "table {{.Name}}\t{{.Status}}\t{{.Ports}}" 2>/dev/null || $COMPOSE ps
echo ""
info "Ostatnie logi Traefika:"
docker logs feer-traefik --tail=20 2>/dev/null | grep -E "certificate|error|warn|acme|level" || true
echo ""
ok "cert-rebuild zakończony. Testuj: curl -I https://${DOMAIN}/"
