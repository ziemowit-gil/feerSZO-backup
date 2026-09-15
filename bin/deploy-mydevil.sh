#!/usr/bin/env bash
# bin/deploy-mydevil.sh — wdrożenie/aktualizacja rdzenia feerSZO (PHP+SQLite)
# na hostingu współdzielonym MyDevil, przez SSH.
#
# Automatyzuje: klon/pull kodu, composer install, dodanie domen (główna +
# aliasy typu "pointer"), wydanie certyfikatów SSL (Let's Encrypt), dopisanie
# crona aplikacji, opcjonalnie synchronizację danych (SQLite/uploads/certs).
#
# NIE automatyzuje (zrób ręcznie, raz):
#   - założenie konta/planu MyDevil
#   - wypełnienie sekretów w config.local.php NA KONCIE (MS_*, EXAM_ENGINE_*,
#     dss_url, LDAP_* — powinny wskazywać na publiczne adresy usług, które
#     zostają na starym VPS: exam-engine, DSS, LDAP, ownCloud)
#   - weryfikację rozszerzeń PHP dostępnych na koncie
#   - przełączenie DNS domen na IP MyDevil (dopiero po testach!)
#
# UWAGA: składnia `devil www add DOMENA pointer GŁÓWNA_DOMENA` (alias jednego
# katalogu pod kilkoma domenami) NIE jest tu zweryfikowana 1:1 z panelem —
# sprawdź `devil www help` na koncie przed pierwszym uruchomieniem bez --dry-run.
#
# Wymaga: skonfigurowanego dostępu SSH (klucz) do konta MyDevil, `ssh`, `rsync`.
#
# Użycie:
#   bash bin/deploy-mydevil.sh --dry-run              # zobacz co by się stało
#   bash bin/deploy-mydevil.sh                        # kod + composer + domeny + SSL + cron
#   bash bin/deploy-mydevil.sh --sync-data             # + prześlij bazę/uploads/certy (NADPISUJE na serwerze!)
#   bash bin/deploy-mydevil.sh --skip-ssl              # bez wydawania certów (np. już wydane)
#
# Najprościej: uruchom najpierw kreator (bin/deploy-mydevil-wizard.sh) — zapyta
# o wszystko poniżej i zapisze do bin/.deploy-mydevil.conf, skąd ten skrypt
# odczyta je automatycznie. Ręczna edycja sekcji KONFIGURACJA jest nadal
# możliwa (np. bez kreatora) — wartości z .deploy-mydevil.conf mają pierwszeństwo.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CONFIG_FILE="${SCRIPT_DIR}/.deploy-mydevil.conf"
# shellcheck source=/dev/null
[[ -f "$CONFIG_FILE" ]] && source "$CONFIG_FILE"

# ─── KONFIGURACJA (wartości domyślne — nadpisz przez .deploy-mydevil.conf) ───
MYDEVIL_SSH="${MYDEVIL_SSH:-login@serwerX.mydevil.net}"   # user@host — z panelu MyDevil
REMOTE_REPO_DIR="${REMOTE_REPO_DIR:-feerSZO}"              # katalog wzgl. \$HOME na koncie
GIT_REMOTE_URL="${GIT_REMOTE_URL:-git@codeberg.org:ziemowitgil/feerSZO.git}"
COMPOSER_BIN="${COMPOSER_BIN:-composer84}"                 # composer83/84 wg wersji PHP na koncie

# Domena główna (prawdziwy katalog kodu) + domeny-aliasy (pointer → domena główna)
PRIMARY_DOMAIN="${PRIMARY_DOMAIN:-szo.feer.org.pl}"
if [[ "${ALIAS_DOMAINS+isset}" != "isset" ]]; then
    ALIAS_DOMAINS=(
        "crm.feer.org.pl"
        "ezd.feer.org.pl"
        "zadania.feer.org.pl"
        "ti.feer.org.pl"
        # ngosystem.pl — transparentne aliasy (patrz .htaccess: RewriteCond
        # %{HTTP_HOST} po prefiksie, niezależnie od domeny bazowej), muszą
        # mieć własny vhost (pointer) na MyDevil ZANIM delegacja DNS
        # (bin/mydevil-dns-delegate.sh) przełączy je z Cloudflare.
        "ngosystem.pl"
        "www.ngosystem.pl"
        "szo.ngosystem.pl"
        "crm.ngosystem.pl"
        "ezd.ngosystem.pl"
        "zadania.ngosystem.pl"
        "ti.ngosystem.pl"
    )
fi

# IP konta MyDevil (do wydawania certów Let's Encrypt) — z panelu / `devil vhost list`
MYDEVIL_IP="${MYDEVIL_IP:-}"

# ──────────────────────────────────────────────────────────────────────────────

DRY=false; SKIP_SSL=false; SYNC_DATA=false; ASSUME_YES=false; DB_FILE=""
for arg in "$@"; do
    case "$arg" in
        --dry-run)   DRY=true ;;
        --skip-ssl)  SKIP_SSL=true ;;
        --sync-data) SYNC_DATA=true ;;
        --yes)       ASSUME_YES=true ;;
        --db-file=*) DB_FILE="${arg#--db-file=}" ;;
        --help|-h) grep '^#' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) echo "Nieznana opcja: $arg" >&2; exit 1 ;;
    esac
done

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
CYAN='\033[0;36m'; BOLD='\033[1m'; RESET='\033[0m'
info()    { echo -e "${CYAN}▸ $*${RESET}"; }
ok()      { echo -e "${GREEN}✔ $*${RESET}"; }
warn()    { echo -e "${YELLOW}⚠ $*${RESET}"; }
die()     { echo -e "${RED}✖ $*${RESET}" >&2; exit 1; }
section() { echo -e "\n${BOLD}━━ $* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${RESET}"; }

[[ "$MYDEVIL_SSH" == "login@serwerX.mydevil.net" ]] && \
    die "Uzupełnij MYDEVIL_SSH (i resztę sekcji KONFIGURACJA) na górze skryptu przed uruchomieniem."

# ── Logowanie hasłem: jedna sesja SSH, wiele poleceń ────────────────────────
# Konto działa na hasłach (nie klucz) — bez tego każde ssh/scp/rsync osobno
# pytałoby o hasło. ControlMaster=auto+ControlPersist trzyma jedno połączenie
# przez 10 minut, więc hasło podajesz raz, a kolejne wywołania (także z
# deploy-mydevil-wizard.sh, jeśli to on Cię tu przywiódł) je reużywają.
SSH_CTL_DIR="${HOME}/.ssh/feerszo-cm"
mkdir -p "$SSH_CTL_DIR" 2>/dev/null || true
SSH_OPTS=(-o "ControlMaster=auto" -o "ControlPath=${SSH_CTL_DIR}/%r@%h:%p" -o "ControlPersist=600")

run_remote() {
    local cmd="$1"
    if $DRY; then
        warn "[dry] ssh ${MYDEVIL_SSH} \"${cmd}\""
    else
        ssh "${SSH_OPTS[@]}" "$MYDEVIL_SSH" "$cmd"
    fi
}

REPO_ROOT="$(dirname "$SCRIPT_DIR")"

# ── 0. Preflight ───────────────────────────────────────────────────────────
section "Preflight"
command -v ssh   >/dev/null || die "Brak polecenia: ssh"
command -v rsync >/dev/null || die "Brak polecenia: rsync"

if ! git -C "$REPO_ROOT" diff --quiet 2>/dev/null; then
    warn "Lokalne repo ma niezacommitowane zmiany — na serwer trafi ostatni commit z origin/main, nie working tree."
fi

if $DRY; then
    warn "[dry] pomijam test połączenia SSH"
else
    info "Łączę (może zapytać o hasło — kolejne kroki go już nie zapytają przez 10 min)…"
    ssh -o ConnectTimeout=8 "${SSH_OPTS[@]}" "$MYDEVIL_SSH" "echo ok" >/dev/null 2>&1 \
        && ok "Połączenie SSH działa" \
        || die "Nie mogę połączyć się przez SSH z ${MYDEVIL_SSH} (login/hasło poprawne?)."
fi

# ── 1. Kod: klon albo fast-forward pull ────────────────────────────────────
section "Kod (git)"
run_remote "test -d ${REMOTE_REPO_DIR}/.git \
    && (cd ${REMOTE_REPO_DIR} && git fetch origin && git merge --ff-only origin/main) \
    || git clone ${GIT_REMOTE_URL} ${REMOTE_REPO_DIR}"
ok "Kod zsynchronizowany"

# ── 2. Composer ─────────────────────────────────────────────────────────────
section "Composer"
run_remote "cd ${REMOTE_REPO_DIR} && ${COMPOSER_BIN} install --no-dev --optimize-autoloader"
ok "Zależności zainstalowane"

# ── 3. Domeny WWW ────────────────────────────────────────────────────────────
section "Domeny WWW"
run_remote "devil www add ${PRIMARY_DOMAIN} php || true"
run_remote "test -L domains/${PRIMARY_DOMAIN}/public_html \
    || (rm -rf domains/${PRIMARY_DOMAIN}/public_html && ln -s ../../${REMOTE_REPO_DIR} domains/${PRIMARY_DOMAIN}/public_html)"
ok "Domena główna: ${PRIMARY_DOMAIN} → ${REMOTE_REPO_DIR}"

for d in "${ALIAS_DOMAINS[@]}"; do
    # UWAGA: składnia "pointer" niezweryfikowana — sprawdź `devil www help`
    # na koncie, jeśli to nie zadziała od razu.
    run_remote "devil www add ${d} pointer ${PRIMARY_DOMAIN} || true"
    ok "Domena: ${d} → pointer do ${PRIMARY_DOMAIN}"
done

# ── 4. SSL (Let's Encrypt) ───────────────────────────────────────────────────
if ! $SKIP_SSL; then
    section "SSL (Let's Encrypt)"
    [[ -z "$MYDEVIL_IP" ]] && die "Uzupełnij MYDEVIL_IP w konfiguracji (albo uruchom z --skip-ssl)."
    for d in "$PRIMARY_DOMAIN" "${ALIAS_DOMAINS[@]}"; do
        run_remote "devil ssl www add ${MYDEVIL_IP} le le ${d} || true"
    done
    ok "Certyfikaty zlecone — zweryfikuj: ssh ${MYDEVIL_SSH} devil ssl www list"
else
    warn "Pominięto SSL (--skip-ssl)"
fi

# ── 5. Cron ──────────────────────────────────────────────────────────────────
section "Cron"
CRON_MARKER="# feerszo-managed-by-deploy-mydevil.sh — nie edytuj ręcznie ponizej, uruchom skrypt ponownie"
CRON_BLOCK="$(cat <<EOF
${CRON_MARKER}
* * * * * php ~/${REMOTE_REPO_DIR}/cron/dispatcher.php >> ~/feerszo_cron.log 2>&1
0 7 * * * php ~/${REMOTE_REPO_DIR}/cron/contract_expiry_reminder.php >> ~/feerszo_cron.log 2>&1
5 7 * * * php ~/${REMOTE_REPO_DIR}/cron/tasks_due_reminder.php >> ~/feerszo_cron.log 2>&1
*/2 * * * * php ~/${REMOTE_REPO_DIR}/cron/tasks_notification_worker.php >> ~/feerszo_cron.log 2>&1
0 * * * * php ~/${REMOTE_REPO_DIR}/cron/tasks_recurring.php >> ~/feerszo_cron.log 2>&1
10 3 * * * php ~/${REMOTE_REPO_DIR}/cron/tasks_archive.php >> ~/feerszo_cron.log 2>&1
30 7 * * * php ~/${REMOTE_REPO_DIR}/cron/sync_ldap.php >> ~/feerszo_cron.log 2>&1
10 7 * * * php ~/${REMOTE_REPO_DIR}/cron/minor_volunteer_periodic_verification.php >> ~/feerszo_cron.log 2>&1
20 7 * * * php ~/${REMOTE_REPO_DIR}/cron/guardian_consent_renewal.php >> ~/feerszo_cron.log 2>&1
EOF
)"

if $DRY; then
    warn "[dry] dopisałbym do crontaba (jeśli marker jeszcze nie istnieje):"
    echo "$CRON_BLOCK"
else
    if ssh "${SSH_OPTS[@]}" "$MYDEVIL_SSH" "crontab -l 2>/dev/null | grep -qF '${CRON_MARKER}'"; then
        ok "Wpisy crona już obecne (marker znaleziony) — pomijam"
    else
        printf '%s\n' "$CRON_BLOCK" | ssh "${SSH_OPTS[@]}" "$MYDEVIL_SSH" "(crontab -l 2>/dev/null; cat) | crontab -" \
            && ok "Dopisano crontab"
    fi
fi

# ── 6. Dane (SQLite + uploads + certy) — TYLKO na żądanie ───────────────────
# Baza NIE nadpisuje umowy.db bezpośrednio — trafia jako umowy.import.db,
# a właściwy import (z walidacją) robi install.php (krok "Baza danych" →
# "Plik już na serwerze"). Dzięki temu `git clone` zawsze daje czystą
# instalację, a przełączenie na prawdziwe dane jest jawnym, sprawdzonym krokiem
# w kreatorze, nie cichym rsync w tle.
if $SYNC_DATA; then
    section "Dane (baza jako umowy.import.db — dokończ import w install.php!)"
    _db_source="${DB_FILE:-${REPO_ROOT}/umowy.db}"
    [[ -f "$_db_source" ]] || die "Nie znaleziono pliku bazy: ${_db_source} (użyj --db-file=ścieżka, np. umowy.production.db z bin/pull-db-from-docker.sh)"
    if ! $ASSUME_YES && ! $DRY; then
        read -r -p "To prześle bazę (${_db_source})/uploads/certy z tej maszyny na MyDevil (uploads/certs nadpisuje w miejscu). Kontynuować? [t/N] " reply
        [[ "$reply" =~ ^[tT]$ ]] || die "Przerwano."
    fi
    if $DRY; then
        warn "[dry] scp ${_db_source} → ${REMOTE_REPO_DIR}/umowy.import.db, rsync uploads/ certs/ → ${MYDEVIL_SSH}:${REMOTE_REPO_DIR}/"
    else
        scp "${SSH_OPTS[@]}" "$_db_source" "${MYDEVIL_SSH}:${REMOTE_REPO_DIR}/umowy.import.db"
        rsync -avz -e "ssh ${SSH_OPTS[*]}" "${REPO_ROOT}/uploads/" "${MYDEVIL_SSH}:${REMOTE_REPO_DIR}/uploads/"
        rsync -avz -e "ssh ${SSH_OPTS[*]}" "${REPO_ROOT}/certs/"   "${MYDEVIL_SSH}:${REMOTE_REPO_DIR}/certs/"
        ok "Dane przesłane — dokończ import: https://${PRIMARY_DOMAIN}/install.php"
    fi
else
    info "Pominięto dane (uruchom z --sync-data tuż przed właściwym cutover)"
fi

# ── Podsumowanie ─────────────────────────────────────────────────────────────
section "Zostaje do zrobienia ręcznie"
cat <<'EOF'
  1. Wejdź na https://TWOJA_DOMENA/install.php i dokończ instalację:
     - z --sync-data: wybierz "Plik już na serwerze" (umowy.import.db wykryty automatycznie)
     - bez --sync-data: "Nowa, pusta instalacja"
  2. config.local.php NA KONCIE MyDevil — uzupełnij sekrety (MS_*, EXAM_ENGINE_URL,
     EXAM_ENGINE_TOKEN, dss_url, LDAP_*) tak, by wskazywały na publiczne adresy
     usług, które zostają na starym VPS (exam-engine, DSS, LDAP, ownCloud).
  3. Sprawdź rozszerzenia PHP na koncie (phpinfo(): pdo_sqlite, ldap, gd, mbstring).
  4. Dopiero gdy wszystko działa pod tymczasowym testem (np. edytując /etc/hosts
     lokalnie) — przełącz DNS domen na IP MyDevil.
  5. composer.json ma "platform.php": "8.5.6" — MyDevil oferuje do 8.4. Sprawdź,
     czy kod faktycznie wymaga funkcji z PHP 8.5, zanim to zignorujesz.
EOF

ok "Deploy zakończony."
