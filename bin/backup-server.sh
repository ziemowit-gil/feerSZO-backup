#!/usr/bin/env bash
# =============================================================================
# bin/backup-server.sh — Kompletny backup serwera feerSZO
#
# Archiwizuje:
#   git bundle         — cały stan gita (historia + working tree)
#   SQLite DB(s)       — bezpieczna kopia .backup (API online, WAL-safe)
#   tenants/           — bazy i dane per-tenant
#   certs/             — klucze prywatne app + SAML IdP
#   config.local.php   — sekrety konfiguracyjne
#   uploads/           — pliki użytkowników
#   EJBCA DB           — mysqldump z kontenera Docker (opcjonalnie)
#   ścieżki dodatkowe  — patrz EXTRA_PATHS
#
# Wynik: feerszo_YYYYMMDD_HHMMSS.tar.gz  (opcjonalnie .gpg)
#        → lokalnie w BACKUP_STORE
#        → opcjonalnie rsync na SSH lub aws s3 cp
#
# Użycie:
#   bash bin/backup-server.sh [--dry-run] [--no-remote] [--no-git]
#
# Wymagania: bash 4+, tar, git, gzip; opcjonalnie: sqlite3, gpg, rsync, aws
# =============================================================================
set -euo pipefail

# ─── KONFIGURACJA ─────────────────────────────────────────────────────────────

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# Gdzie odkładać gotowe paczki
BACKUP_STORE="/var/backups/feerszo"

# Ile lokalnych paczek zachować (starsze są usuwane)
KEEP_LOCAL=14

# Zdalne składowanie — ustaw TYLKO JEDNO (lub żadne)
REMOTE_SSH=""            # np. backup@nas.example.com:/mnt/backups/feerszo
REMOTE_S3=""             # np. s3://moj-bucket/feerszo
KEEP_REMOTE=30           # ile paczek zachować na S3 (rsync zarządza sam)

# EJBCA Docker (opcjonalnie)
EJBCA_CONTAINER=""       # np. "ejbca"; puste = pomijaj
EJBCA_DB="ejbca"
EJBCA_USER="root"
EJBCA_PASS=""            # puste = bez hasła

# Szyfrowanie GPG (opcjonalnie)
GPG_RECIPIENT=""         # np. backup@feer.org.pl; puste = bez szyfrowania

# Dodatkowe ścieżki (bezwzględne lub względne wobec APP_DIR)
EXTRA_PATHS=()
# EXTRA_PATHS=("docker/nginx.conf" "/etc/letsencrypt/live/feer.org.pl")

# ──────────────────────────────────────────────────────────────────────────────

DRY=false; NO_REMOTE=false; NO_GIT=false
for _arg in "$@"; do
  case "$_arg" in
    --dry-run)   DRY=true ;;
    --no-remote) NO_REMOTE=true ;;
    --no-git)    NO_GIT=true ;;
    --help|-h)
      grep '^#' "$0" | head -30 | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) printf '[WARN] Nieznana opcja: %s\n' "$_arg" >&2 ;;
  esac
done

# ─── Helpers ──────────────────────────────────────────────────────────────────
_ts()   { date +'%H:%M:%S'; }
log()   { printf '\e[36m[%s]\e[0m %s\n'   "$(_ts)" "$*"; }
ok()    { printf '\e[32m[ OK]\e[0m %s\n'  "$*"; }
warn()  { printf '\e[33m[WARN]\e[0m %s\n' "$*" >&2; }
err()   { printf '\e[31m[ERR ]\e[0m %s\n' "$*" >&2; }
die()   { err "$*"; exit 1; }

human_sz() {
  local s="$1"
  awk -v s="$s" 'BEGIN {
    if      (s >= 1073741824) printf "%.1f GB", s/1073741824
    else if (s >= 1048576)    printf "%.1f MB", s/1048576
    else if (s >= 1024)       printf "%d KB",   s/1024
    else                      printf "%d B",    s
  }'
}

file_sz() { stat -c%s "$1" 2>/dev/null || stat -f%z "$1" 2>/dev/null || echo 0; }

ERRORS=0
inc_err() { (( ERRORS++ )) || true; }

TMP_DIR=""
cleanup() { [[ -n "$TMP_DIR" && -d "$TMP_DIR" ]] && rm -rf "$TMP_DIR"; }
trap cleanup EXIT INT TERM

# ─── Pre-flight ───────────────────────────────────────────────────────────────
log "=== feerSZO backup-server === $(date '+%Y-%m-%d %H:%M:%S') ==="
log "Aplikacja: $APP_DIR"
$DRY && warn "Tryb DRY-RUN — nic nie zostanie zapisane."

[[ -d "$APP_DIR" ]] || die "APP_DIR nie istnieje: $APP_DIR"
command -v tar  &>/dev/null || die "Brak polecenia: tar"
command -v git  &>/dev/null || die "Brak polecenia: git"
[[ -n "$GPG_RECIPIENT" ]] && { command -v gpg &>/dev/null || die "Brak gpg (wymagane dla szyfrowania)"; }
[[ -n "$REMOTE_SSH" ]] && ! $NO_REMOTE && { command -v rsync &>/dev/null || warn "Brak rsync — SSH transfer pomijany."; }
[[ -n "$REMOTE_S3" ]] && ! $NO_REMOTE && { command -v aws   &>/dev/null || warn "Brak aws-cli — S3 transfer pomijany."; }

TMP_DIR="$(mktemp -d /tmp/feerszo-backup-XXXXXX)"
STAGE="$TMP_DIR/stage"
mkdir -p "$STAGE"

STAMP="$(date +'%Y%m%d_%H%M%S')"
ARCHIVE_NAME="feerszo_${STAMP}.tar.gz"

$DRY || mkdir -p "$BACKUP_STORE"

# ─── 1. Git bundle ────────────────────────────────────────────────────────────
if ! $NO_GIT; then
  log "1. Git bundle (cała historia + working tree)…"
  BUNDLE="$STAGE/repo.bundle"
  if $DRY; then
    warn "  [dry] git bundle create repo.bundle --all"
  elif git -C "$APP_DIR" bundle create "$BUNDLE" --all 2>/dev/null; then
    ok "  repo.bundle → $(human_sz "$(file_sz "$BUNDLE")")"
  else
    warn "  git bundle nie powiódł się — pomijam"
    inc_err
  fi
fi

# ─── 2. Bazy SQLite ───────────────────────────────────────────────────────────
log "2. Bazy SQLite…"
mkdir -p "$STAGE/db"
DB_COUNT=0

while IFS= read -r -d '' db_src; do
  db_rel="${db_src#"$APP_DIR/"}"
  db_dst="$STAGE/db/$(printf '%s' "$db_rel" | tr '/' '__')"
  if $DRY; then
    warn "  [dry] backup $db_rel"
  else
    if command -v sqlite3 &>/dev/null; then
      if sqlite3 "$db_src" ".backup '${db_dst//\'/\'\'\'}'" 2>/dev/null; then
        ok "  $db_rel → $(human_sz "$(file_sz "$db_dst")")"
        (( DB_COUNT++ )) || true
      else
        warn "  .backup nie powiodło się dla $db_rel — VACUUM INTO"
        if sqlite3 "$db_src" "VACUUM INTO '${db_dst//\'/\'\'\'}';" 2>/dev/null; then
          ok "  $db_rel (VACUUM) → $(human_sz "$(file_sz "$db_dst")")"
          (( DB_COUNT++ )) || true
        else
          warn "  VACUUM też nie powiodło się — kopiuję plik bezpośrednio"
          cp "$db_src" "$db_dst" && ok "  $db_rel → skopiowano (niespójny WAL możliwy!)"
          (( DB_COUNT++ )) || true
          inc_err
        fi
      fi
    else
      warn "  Brak sqlite3 — kopiuję $db_rel bezpośrednio"
      cp "$db_src" "$db_dst" && ok "  $db_rel → skopiowano (niespójny WAL możliwy!)"
      (( DB_COUNT++ )) || true
      inc_err
    fi
  fi
done < <(find "$APP_DIR" \
  -not -path "$APP_DIR/backups/*" \
  -not -path "$APP_DIR/vendor/*" \
  -not -path "$APP_DIR/.git/*" \
  -not -path "$APP_DIR/.claude/*" \
  -not -path "$APP_DIR/output/*" \
  -not -path "*/.angular/*" \
  -not -path "*/node_modules/*" \
  \( -name "*.db" -o -name "*.sqlite" -o -name "*.sqlite3" \) \
  -type f -size +0c -print0 2>/dev/null)

$DRY || ok "  Razem baz: $DB_COUNT"

# ─── 3. Certyfikaty i klucze ──────────────────────────────────────────────────
log "3. Certyfikaty i klucze…"
CERT_ITEMS=("$APP_DIR/certs" "$APP_DIR/cert-salt.php" "$APP_DIR/saml")
for item in "${CERT_ITEMS[@]}"; do
  [[ -e "$item" ]] || continue
  rel="${item#"$APP_DIR/"}"
  if $DRY; then
    warn "  [dry] backup $rel"
  elif [[ -d "$item" ]]; then
    mkdir -p "$STAGE/certs/$(basename "$item")"
    cp -rp "$item/." "$STAGE/certs/$(basename "$item")/"
    ok "  $rel/ ($(find "$STAGE/certs/$(basename "$item")" -type f | wc -l | tr -d ' ') pliki)"
  else
    mkdir -p "$STAGE/certs"
    cp "$item" "$STAGE/certs/"
    ok "  $rel"
  fi
done

# ─── 4. Konfiguracja (sekrety) ────────────────────────────────────────────────
log "4. Pliki konfiguracyjne…"
mkdir -p "$STAGE/config"
for cfg_name in config.local.php .env .env.local .env.production config.production.php; do
  src="$APP_DIR/$cfg_name"
  [[ -f "$src" ]] || continue
  if $DRY; then
    warn "  [dry] backup $cfg_name"
  else
    cp "$src" "$STAGE/config/"
    ok "  $cfg_name"
  fi
done

# ─── 5. Uploads ───────────────────────────────────────────────────────────────
log "5. Uploads…"
UPL_SRC="$APP_DIR/uploads"
if [[ -d "$UPL_SRC" ]]; then
  UPL_COUNT=$(find "$UPL_SRC" -type f 2>/dev/null | wc -l | tr -d ' ')
  if $DRY; then
    warn "  [dry] tar uploads/ ($UPL_COUNT pliki)"
  else
    UPL_ARC="$STAGE/uploads.tar.gz"
    if tar -czf "$UPL_ARC" -C "$APP_DIR" uploads/ 2>/dev/null; then
      ok "  uploads/ ($UPL_COUNT pliki) → $(human_sz "$(file_sz "$UPL_ARC")")"
    else
      warn "  tar uploads/ nie powiódł się"
      inc_err
    fi
  fi
else
  warn "  Brak katalogu uploads/ — pomijam."
fi

# ─── 6. Ścieżki dodatkowe ─────────────────────────────────────────────────────
if (( ${#EXTRA_PATHS[@]} > 0 )); then
  log "6. Dodatkowe ścieżki…"
  mkdir -p "$STAGE/extra"
  for extra in "${EXTRA_PATHS[@]}"; do
    [[ "$extra" = /* ]] || extra="$APP_DIR/$extra"
    if [[ ! -e "$extra" ]]; then warn "  Nie istnieje: $extra"; continue; fi
    if $DRY; then
      warn "  [dry] backup $extra"
    else
      cp -rp "$extra" "$STAGE/extra/"
      ok "  $extra"
    fi
  done
fi

# ─── 7. EJBCA mysqldump ───────────────────────────────────────────────────────
if [[ -n "$EJBCA_CONTAINER" ]]; then
  log "7. EJBCA mysqldump…"
  if ! command -v docker &>/dev/null; then
    warn "  Brak docker — pomijam EJBCA."
    inc_err
  elif ! docker ps --format '{{.Names}}' 2>/dev/null | grep -qx "$EJBCA_CONTAINER"; then
    warn "  Kontener '$EJBCA_CONTAINER' nie jest uruchomiony — pomijam."
    inc_err
  elif $DRY; then
    warn "  [dry] docker exec $EJBCA_CONTAINER mysqldump $EJBCA_DB | gzip"
  else
    EJBCA_DUMP="$STAGE/ejbca.sql.gz"
    _pass_opt=""
    [[ -n "$EJBCA_PASS" ]] && _pass_opt="-p${EJBCA_PASS}"
    if docker exec "$EJBCA_CONTAINER" \
         mysqldump -u"$EJBCA_USER" ${_pass_opt} \
         --single-transaction --routines --triggers "$EJBCA_DB" 2>/dev/null \
         | gzip > "$EJBCA_DUMP"; then
      ok "  EJBCA → $(human_sz "$(file_sz "$EJBCA_DUMP")")"
    else
      warn "  mysqldump nie powiódł się"
      inc_err
    fi
  fi
fi

# ─── Metadane archiwum ────────────────────────────────────────────────────────
if ! $DRY; then
  {
    echo "feerszo backup — $(date '+%Y-%m-%d %H:%M:%S %Z')"
    echo "Wersja git:    $(git -C "$APP_DIR" describe --always --tags 2>/dev/null || echo '?')"
    echo "Hostname:      $(hostname -f 2>/dev/null || hostname)"
    echo "PHP:           $(php --version 2>/dev/null | head -1 || echo '?')"
    echo "Błędy/warningi: $ERRORS"
  } > "$STAGE/MANIFEST.txt"
fi

# ─── Pakietuj stage ───────────────────────────────────────────────────────────
if ! $DRY; then
  log "Pakietuję archiwum…"
  TMP_ARC="$TMP_DIR/$ARCHIVE_NAME"
  tar -czf "$TMP_ARC" -C "$STAGE" . 2>/dev/null
  ok "Rozmiar: $(human_sz "$(file_sz "$TMP_ARC")")"

  if [[ -n "$GPG_RECIPIENT" ]]; then
    log "Szyfruję GPG (odbiorca: $GPG_RECIPIENT)…"
    gpg --batch --yes --recipient "$GPG_RECIPIENT" \
        --output "${TMP_ARC}.gpg" --encrypt "$TMP_ARC"
    rm -f "$TMP_ARC"
    TMP_ARC="${TMP_ARC}.gpg"
    ARCHIVE_NAME="${ARCHIVE_NAME}.gpg"
    ok "Zaszyfrowano → $ARCHIVE_NAME"
  fi

  ARCHIVE_FINAL="$BACKUP_STORE/$ARCHIVE_NAME"
  mv "$TMP_ARC" "$ARCHIVE_FINAL"
  ok "Lokalnie: $ARCHIVE_FINAL"
fi

# ─── Transfer zdalny ──────────────────────────────────────────────────────────
if ! $DRY && ! $NO_REMOTE; then
  if [[ -n "$REMOTE_SSH" ]] && command -v rsync &>/dev/null; then
    log "Wysyłam SSH → $REMOTE_SSH…"
    if rsync -az --protect-args "$ARCHIVE_FINAL" "$REMOTE_SSH/"; then
      ok "SSH: OK"
    else
      warn "rsync nie powiódł się — backup lokalny zachowany"
      inc_err
    fi
  fi

  if [[ -n "$REMOTE_S3" ]] && command -v aws &>/dev/null; then
    log "Wysyłam S3 → $REMOTE_S3…"
    if aws s3 cp "$ARCHIVE_FINAL" "${REMOTE_S3}/${ARCHIVE_NAME}" \
         --storage-class STANDARD_IA --no-progress; then
      ok "S3: OK"
      # Rotacja S3
      log "Rotacja S3 (zachowuję $KEEP_REMOTE ostatnich)…"
      aws s3 ls "${REMOTE_S3}/" 2>/dev/null \
        | awk '{print $4}' | grep -E '^feerszo_.*\.tar\.gz(\.gpg)?$' | sort \
        | head -n -"$KEEP_REMOTE" \
        | while IFS= read -r key; do
            aws s3 rm "${REMOTE_S3}/${key}" --quiet 2>/dev/null \
              && ok "  S3: usunięto $key"
          done
    else
      warn "aws s3 cp nie powiódł się — backup lokalny zachowany"
      inc_err
    fi
  fi
fi

# ─── Rotacja lokalna ──────────────────────────────────────────────────────────
if ! $DRY; then
  log "Rotacja lokalna (zachowuję $KEEP_LOCAL ostatnich)…"
  ROTATED=0
  while IFS= read -r old; do
    rm -f "$old"
    (( ROTATED++ )) || true
    ok "  Usunięto: $(basename "$old")"
  done < <(ls -1t "${BACKUP_STORE}/feerszo_"*.tar.gz* 2>/dev/null | tail -n +"$(( KEEP_LOCAL + 1 ))")
  (( ROTATED == 0 )) && ok "  Nic do usunięcia."
fi

# ─── Podsumowanie ─────────────────────────────────────────────────────────────
echo ""
if $DRY; then
  log "=== DRY-RUN zakończony. ==="
elif (( ERRORS == 0 )); then
  ok "=== Backup zakończony sukcesem ($(date '+%Y-%m-%d %H:%M:%S')) ==="
else
  warn "=== Backup zakończony z $ERRORS ostrzeżeniami ($(date '+%Y-%m-%d %H:%M:%S')) ==="
fi

exit $(( ERRORS > 0 ? 1 : 0 ))
