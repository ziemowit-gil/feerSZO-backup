#!/usr/bin/env bash
#
# docker/install-azure-cli.sh — instalacja Azure CLI (az) w kontenerze.
#
# Obraz bazowy projektu to php:8.4-apache (Debian bookworm), więc instalujemy
# z oficjalnego repozytorium Microsoftu (apt) — bez pipe'owania zdalnego skryptu
# do basha. Skrypt jest idempotentny: gdy `az` już jest, kończy się od razu.
#
# Użycie:
#   - w trakcie budowania obrazu (Dockerfile):
#       COPY install-azure-cli.sh /tmp/install-azure-cli.sh
#       RUN bash /tmp/install-azure-cli.sh && rm -f /tmp/install-azure-cli.sh
#   - w działającym kontenerze:
#       docker compose exec <usluga> bash /var/www/html/docker/install-azure-cli.sh
#       (uruchom jako root: docker compose exec -u 0 ...)
#
set -euo pipefail

# Już zainstalowane? — nic nie rób.
if command -v az >/dev/null 2>&1; then
    echo "Azure CLI już zainstalowane: $(az version --output tsv --query '\"azure-cli\"' 2>/dev/null || echo '?')"
    exit 0
fi

# Wymaga uprawnień roota (apt + zapis do /etc).
if [ "$(id -u)" -ne 0 ]; then
    echo "Ten skrypt trzeba uruchomić jako root (np. docker compose exec -u 0 ...)." >&2
    exit 1
fi

export DEBIAN_FRONTEND=noninteractive

echo "==> Instalacja zależności"
apt-get update
apt-get install -y --no-install-recommends \
    ca-certificates \
    curl \
    gnupg \
    apt-transport-https

# Kodename dystrybucji (np. bookworm) — z /etc/os-release, bez zależności lsb-release.
. /etc/os-release
AZ_DIST="${VERSION_CODENAME:-bookworm}"
ARCH="$(dpkg --print-architecture)"

echo "==> Klucz GPG repozytorium Microsoftu"
install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://packages.microsoft.com/keys/microsoft.asc \
    | gpg --dearmor --yes -o /etc/apt/keyrings/microsoft.gpg
chmod 0644 /etc/apt/keyrings/microsoft.gpg

echo "==> Dodanie repozytorium azure-cli (dist=${AZ_DIST}, arch=${ARCH})"
echo "deb [arch=${ARCH} signed-by=/etc/apt/keyrings/microsoft.gpg] https://packages.microsoft.com/repos/azure-cli/ ${AZ_DIST} main" \
    > /etc/apt/sources.list.d/azure-cli.list

echo "==> Instalacja azure-cli"
apt-get update
apt-get install -y --no-install-recommends azure-cli

# Sprzątanie cache apt (mniejszy obraz)
rm -rf /var/lib/apt/lists/*

echo "==> Gotowe: $(az version --output tsv --query '\"azure-cli\"' 2>/dev/null || az --version | head -1)"
