#!/usr/bin/env bash
# git.sh — Synchronizacja z Codeberg (https://codeberg.org/ziemowitgil/feerSZO)
#
# Użycie:
#   ./git.sh push [wiadomość]   — commit + push do origin/main
#   ./git.sh pull               — pull z origin/main (rebase)
#   ./git.sh status             — podgląd zmian
#   ./git.sh sync               — pull, potem push (jeśli są lokalne zmiany)
#
set -euo pipefail

REMOTE="origin"
BRANCH="main"
REPO_URL="https://codeberg.org/ziemowitgil/feerSZO"

# Katalog skryptu = korzeń repo
cd "$(dirname "$0")"

# ── Kolory ────────────────────────────────────────────────────────────────────
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
BLUE='\033[0;34m'; CYAN='\033[0;36m'; BOLD='\033[1m'; NC='\033[0m'

info()    { echo -e "${CYAN}▸${NC} $*"; }
success() { echo -e "${GREEN}✓${NC} $*"; }
warn()    { echo -e "${YELLOW}⚠${NC} $*"; }
error()   { echo -e "${RED}✗${NC} $*" >&2; exit 1; }
header()  { echo -e "\n${BOLD}${BLUE}══ $* ══${NC}"; }

# ── Sprawdź czy git jest dostępny ─────────────────────────────────────────────
command -v git &>/dev/null || error "git nie jest zainstalowany."

# ── Ignorowane pliki (nigdy nie commitujemy) ──────────────────────────────────
ensure_gitignore() {
    local entries=(
        "*.db"
        "*.db-shm"
        "*.db-wal"
        "*.pfx"
        "*.p12"
        "*.key"
        "certs/*.key"
        "uploads/"
        "backups/"
        "tenants/*/umowy.db"
        "tenants/*/uploads/"
        ".env"
        "*.log"
    )
    local changed=0
    for entry in "${entries[@]}"; do
        grep -qxF "$entry" .gitignore 2>/dev/null || { echo "$entry" >> .gitignore; changed=1; }
    done
    [[ $changed -eq 1 ]] && info "Zaktualizowano .gitignore"
}

# ── STATUS ────────────────────────────────────────────────────────────────────
cmd_status() {
    header "Status repozytorium"
    info "Gałąź: $(git branch --show-current)  |  Remote: ${REPO_URL}"
    echo
    git status --short
    echo
    local ahead behind
    git fetch "$REMOTE" "$BRANCH" --quiet 2>/dev/null || true
    ahead=$(git rev-list --count "${REMOTE}/${BRANCH}..HEAD" 2>/dev/null || echo 0)
    behind=$(git rev-list --count "HEAD..${REMOTE}/${BRANCH}" 2>/dev/null || echo 0)
    [[ $ahead  -gt 0 ]] && warn "Lokalnie: +${ahead} commitów do wypchnięcia"
    [[ $behind -gt 0 ]] && warn "Zdalnie:  +${behind} commitów do pobrania"
    [[ $ahead -eq 0 && $behind -eq 0 ]] && success "Synchronizacja aktualna"
}

# ── PUSH ──────────────────────────────────────────────────────────────────────
cmd_push() {
    header "Push → Codeberg"
    ensure_gitignore

    # Sprawdź czy jest co commitować
    if git diff --quiet && git diff --staged --quiet && [[ -z "$(git ls-files --others --exclude-standard)" ]]; then
        info "Brak zmian do zatwierdzenia."
        # Może są już commits do pushowania
        local ahead
        ahead=$(git rev-list --count "${REMOTE}/${BRANCH}..HEAD" 2>/dev/null || echo 0)
        if [[ $ahead -gt 0 ]]; then
            info "Wysyłam ${ahead} istniejący/e commit(y)..."
            git push "$REMOTE" "$BRANCH"
            success "Push zakończony."
        else
            success "Repozytorium aktualne — nic do pushowania."
        fi
        return
    fi

    # Wiadomość commita
    local msg="${1:-}"
    if [[ -z "$msg" ]]; then
        # Auto-generuj z listy zmienionych plików
        local changed
        changed=$(git diff --name-only; git diff --staged --name-only; git ls-files --others --exclude-standard)
        local count
        count=$(echo "$changed" | grep -c . || true)
        msg="Aktualizacja: ${count} plik(ów) — $(date '+%Y-%m-%d %H:%M')"
    fi

    echo
    info "Pliki do zatwierdzenia:"
    git status --short
    echo
    read -rp "$(echo -e "${YELLOW}?${NC} Wiadomość commita [${msg}]: ")" custom_msg
    [[ -n "$custom_msg" ]] && msg="$custom_msg"

    # Staging — wszystko oprócz .gitignore'owanych
    git add -A
    git commit -m "$msg"
    success "Commit: ${msg}"

    info "Push → ${REMOTE}/${BRANCH}..."
    git push "$REMOTE" "$BRANCH"
    success "Push zakończony. Widok: ${REPO_URL}"
}

# ── PULL ──────────────────────────────────────────────────────────────────────
cmd_pull() {
    header "Pull ← Codeberg"

    # Sprawdź czy są niezapisane zmiany (ryzyko konfliktu)
    if ! git diff --quiet || ! git diff --staged --quiet; then
        warn "Masz niezatwierdzone zmiany. Pull może powodować konflikty."
        read -rp "$(echo -e "${YELLOW}?${NC} Kontynuować? [t/N]: ")" ans
        [[ "${ans,,}" != "t" ]] && { info "Anulowano."; exit 0; }
    fi

    info "Pobieranie z ${REMOTE}/${BRANCH}..."
    git pull --rebase "$REMOTE" "$BRANCH"
    success "Pull zakończony."
}

# ── SYNC (pull → push) ────────────────────────────────────────────────────────
cmd_sync() {
    header "Sync ↕ Codeberg"
    cmd_pull
    echo
    cmd_push "${1:-}"
}

# ── MAIN ──────────────────────────────────────────────────────────────────────
CMD="${1:-status}"
shift || true

case "$CMD" in
    push)   cmd_push   "$@" ;;
    pull)   cmd_pull        ;;
    status) cmd_status      ;;
    sync)   cmd_sync   "$@" ;;
    *)
        echo -e "Użycie: ${BOLD}./git.sh${NC} <komenda> [opcje]"
        echo
        echo "  push [msg]  — zatwierdź zmiany i wyślij do Codeberg"
        echo "  pull        — pobierz zmiany z Codeberg"
        echo "  status      — pokaż stan repozytorium"
        echo "  sync [msg]  — pull + push"
        exit 1
        ;;
esac
