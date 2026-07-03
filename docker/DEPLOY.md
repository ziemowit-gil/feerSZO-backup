# FEER SZO — Instrukcja wdrożenia produkcyjnego

> **Domena docelowa:** `szo.feer.org.pl`  
> **Stack:** PHP 8.4 + Apache · Redis · Traefik (SSL Let's Encrypt)  
> **Opcjonalnie:** MySQL 8.4

---

## Spis treści

1. [Wymagania serwera](#1-wymagania-serwera)
2. [Struktura plików Docker](#2-struktura-plików-docker)
3. [Wdrożenie — SQLite (zalecane na start)](#3-wdrożenie--sqlite)
4. [Wdrożenie — MySQL](#4-wdrożenie--mysql)
5. [Konfiguracja Microsoft 365](#5-konfiguracja-microsoft-365)
6. [Pierwsze uruchomienie](#6-pierwsze-uruchomienie)
7. [Weryfikacja i lista kontrolna](#7-weryfikacja-i-lista-kontrolna)
8. [Codzienna obsługa](#8-codzienna-obsługa)
9. [Aktualizacje](#9-aktualizacje)
10. [Backup i przywracanie](#10-backup-i-przywracanie)
11. [Środowisko deweloperskie](#11-środowisko-deweloperskie)
12. [Rozwiązywanie problemów](#12-rozwiązywanie-problemów)

---

## 1. Wymagania serwera

| Zasób | Minimum | Zalecane |
|-------|---------|---------|
| RAM | 1 GB | 2 GB |
| CPU | 1 vCPU | 2 vCPU |
| Dysk | 20 GB | 40 GB |
| System | Ubuntu 22.04 LTS | Ubuntu 24.04 LTS |

### Wymagane oprogramowanie

```bash
# Docker Engine 24+
curl -fsSL https://get.docker.com | sh
sudo usermod -aG docker $USER && newgrp docker

# Sprawdź wersje
docker --version        # Docker version 24+
docker compose version  # Docker Compose version v2+
```

### DNS — skieruj domenę na serwer

W panelu DNS domeny `feer.org.pl` dodaj rekord **A**:

```
szo.feer.org.pl.   A   <IP_SERWERA>
```

> ⚠️ DNS musi się propagować **zanim** uruchomisz stack. Let's Encrypt wymaga dostępu HTTP.
> Sprawdź: `dig szo.feer.org.pl` lub `nslookup szo.feer.org.pl`

#### Aliasy ngosystem.pl (transparentne)

System działa pod `szo.feer.org.pl`. Subdomeny **ngosystem.pl** są **transparentnymi aliasami**
(bez 301) — Traefik kieruje ruch wprost do kontenera `app`, doklejając prefiks ścieżki, a adres
w pasku przeglądarki **pozostaje** na ngosystem.pl:

```
crm.ngosystem.pl/<x>       → app obsługuje /crm/<x>
zadania.ngosystem.pl/<x>   → app obsługuje /tasks/<x>
ti.ngosystem.pl/<x>        → app obsługuje /karty30/ti/kursant/<x>
```

Rekordy DNS w Cloudflare utworzysz idempotentnym konfiguratorem. Najprościej —
kreator zada pytania i sam zapisze `docker/.env.cloudflare`:

```bash
bash docker/cloudflare-dns.sh --init     # kreator: token, IP, hosty → zapis + utworzenie rekordów
```

Albo ręcznie z pliku konfiguracyjnego:

```bash
cp docker/.env.cloudflare.example docker/.env.cloudflare   # wpisz token API + IP serwera
bash docker/cloudflare-dns.sh --dry-run                    # podgląd
bash docker/cloudflare-dns.sh                              # utworzenie/aktualizacja
```

> Rekordy są domyślnie **proxied** (pomarańczowa chmurka) — w Cloudflare ustaw SSL/TLS na
> **Full (strict)**. Hosty aliasów ustawiasz w `.env.prod` (`DOMAIN_CRM`, `DOMAIN_TASKS`, `DOMAIN_TI`).

> ⚠️ **Świadomość hosta w aplikacji.** Aplikacja używa stałego `APP_URL=https://szo.feer.org.pl`,
> więc linki nawigacyjne, przekierowanie logowania (`require_login`) oraz OAuth Microsoft / SAML
> wskazują `szo.feer.org.pl`. W praktyce alias jest transparentny dla **wejścia** na stronę, ale
> klikając dalej lub logując się użytkownik trafi z powrotem na `szo.feer.org.pl`. Pełna
> transparentność (pozostanie na ngosystem.pl przez całą sesję) wymaga uczynienia `APP_URL`/ciasteczek
> zależnymi od nagłówka `Host` oraz zarejestrowania hostów ngosystem.pl w Azure AD (redirect URI)
> i w konfiguracji SAML. To osobna, większa zmiana po stronie aplikacji.

### Firewall

```bash
# UFW — otwórz porty HTTP, HTTPS, SSH
sudo ufw allow 22/tcp
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw enable
```

---

## 2. Struktura plików Docker

```
docker/
├── Dockerfile               # PHP 8.4 + Apache + pdo_sqlite + pdo_mysql
├── docker-compose.yml       # Baza: app + redis
├── docker-compose.override.yml  # DEV: mailpit, rabbitmq, port :80
├── docker-compose.prod.yml  # PROD: Traefik + SSL + php.prod.ini
├── docker-compose.mysql.yml # Addon: MySQL 8.4 (dev lub prod)
├── docker-compose.ejbca.yml # Addon: EJBCA CE — wewnętrzny CA (opcjonalny, zob. EJBCA.md)
├── setup-ejbca.sh           # Wdraża EJBCA obok już działającego stacku
├── apache.conf              # VirtualHost (RemoteIP dla Traefik)
├── php.ini                  # Dev PHP config (E_ALL, display_errors=On)
├── php.prod.ini             # Prod PHP config (błędy ukryte, opcache)
├── entrypoint.sh            # Start: uprawnienia → cron → Apache
├── crontab                  # Zadania cykliczne (mail, umowy, dispatcher)
├── msmtp.conf               # PHP mail() → Mailpit (dev) lub SMTP
├── .env.example             # Szablon zmiennych DEV
├── .env.prod.example        # Szablon zmiennych PROD ← wypełnij to
├── DEPLOY.md                # Ta instrukcja
└── EJBCA.md                 # Wdrożenie opcjonalnego CA (EJBCA)
```

---

## 3. Wdrożenie — SQLite

SQLite jest najproszą opcją na start — zero konfiguracji bazy danych.
Plik `umowy.db` leży bezpośrednio w katalogu projektu.

### Krok 1 — Sklonuj repozytorium

```bash
cd /opt
git clone https://codeberg.org/ziemowitgil/feerSZO feer-szo
cd feer-szo
```

### Krok 2 — Wygeneruj APP_KEY

```bash
php -r "echo bin2hex(random_bytes(32)) . PHP_EOL;"
# Skopiuj wynik — to będzie Twój APP_KEY
```

### Krok 3 — Utwórz plik .env.prod

```bash
cp docker/.env.prod.example docker/.env.prod
nano docker/.env.prod
```

Minimalne wypełnienie (SQLite):

```dotenv
DOMAIN=szo.feer.org.pl
ACME_EMAIL=admin@feer.org.pl
APP_ENV=production
APP_URL=https://szo.feer.org.pl
APP_KEY=<wklej_wygenerowany_klucz>
ORG_NAME=Fundacja Edukacji Empatii Rozwoju FEER
DB_TYPE=sqlite
MS_ENABLED=0
```

> M365 możesz skonfigurować później przez panel admina (`/admin/m365_settings.php`).

### Krok 4 — Zbuduj i uruchom

```bash
cd /opt/feer-szo/docker

docker compose \
  -f docker-compose.yml \
  -f docker-compose.prod.yml \
  --env-file .env.prod \
  up -d --build
```

Pierwsze uruchomienie (~3–5 min — budowanie obrazu PHP).

### Krok 5 — Sprawdź status

```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml \
  --env-file .env.prod ps
```

Oczekiwany stan:

```
NAME            STATUS
feer-traefik    Up (running)
feer-app        Up (running)
feer-redis      Up (healthy)
```

### Krok 6 — Otwórz w przeglądarce

```
https://szo.feer.org.pl
```

>  Certyfikat SSL jest automatycznie pobierany z Let's Encrypt (~30 sekund).
> Jeśli widzisz błąd SSL przy pierwszym wejściu — odczekaj chwilę i odśwież.

---

## 4. Wdrożenie — MySQL

### Krok 1–2 identyczne jak wyżej

### Krok 3 — .env.prod z MySQL

```dotenv
DOMAIN=szo.feer.org.pl
ACME_EMAIL=admin@feer.org.pl
APP_ENV=production
APP_URL=https://szo.feer.org.pl
APP_KEY=<klucz>
ORG_NAME=Fundacja Edukacji Empatii Rozwoju FEER

DB_TYPE=mysql
DB_HOST=mysql
DB_PORT=3306
DB_NAME=feer
DB_USER=feer
DB_PASS=TajneHasloMySQL2024!
MYSQL_ROOT_PASSWORD=TajneHasloRoot2024!
```

### Krok 4 — Uruchom z MySQL

```bash
cd /opt/feer-szo/docker

docker compose \
  -f docker-compose.yml \
  -f docker-compose.prod.yml \
  -f docker-compose.mysql.yml \
  --env-file .env.prod \
  up -d --build
```

### Krok 5 — Migracja danych ze SQLite (jeśli masz istniejącą bazę)

```bash
# Poczekaj aż MySQL będzie zdrowy
docker compose -f docker-compose.yml -f docker-compose.prod.yml \
  -f docker-compose.mysql.yml --env-file .env.prod \
  exec mysql mysqladmin ping -h localhost -u root -p

# Uruchom migrację
docker exec feer-app php /var/www/html/cli/migrate_to_mysql.php \
  --host=mysql \
  --db=feer \
  --user=feer \
  --pass=TajneHasloMySQL2024!
```

---

## 5. Konfiguracja Microsoft 365

Po pierwszym wdrożeniu skonfiguruj M365 przez panel admina:

1. Przejdź do `https://szo.feer.org.pl/admin/m365_settings.php`
2. Podaj Tenant ID, Client ID i Client Secret
3. Kliknij **Połącz przez OAuth** → zaloguj się kontem Global Admin Azure
4. Wróć do ustawień — dane zostaną wykryte automatycznie
5. Przejdź do `https://szo.feer.org.pl/admin/graph_permissions.php`
6. Kliknij **Sprawdź przez Graph API** → weryfikacja uprawnień
7. Użyj linku **Grant Admin Consent** jeśli brakuje uprawnień

Lub ustaw przez .env.prod i przebuduj:

```dotenv
MS_ENABLED=1
MS_TENANT_ID=xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx
MS_CLIENT_ID=xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx
MS_CLIENT_SECRET=twoj_secret_azure
MS_REDIRECT_URI=https://szo.feer.org.pl/auth/microsoft.php
```

---

## 6. Pierwsze uruchomienie

Po wdrożeniu wejdź na stronę i:

### Utwórz konto administratora (jeśli baza pusta)

```bash
docker exec -it feer-app php /var/www/html/cli/CreateServiceUser.php
```

### Lub zainicjalizuj bazę przez kreator

```
https://szo.feer.org.pl/setup/
```

### Lista kontrolna wdrożenia

```bash
# CLI
docker exec feer-app php /var/www/html/cli/prod_check.php

# Lub przez panel admina
https://szo.feer.org.pl/admin/prod_check.php
```

---

## 7. Weryfikacja i lista kontrolna

```bash
# Status kontenerów
docker ps --format "table {{.Names}}\t{{.Status}}\t{{.Ports}}"

# Certyfikat SSL
curl -vI https://szo.feer.org.pl 2>&1 | grep -E 'SSL|issuer|expire'

# Logi Apache
docker exec feer-app tail -20 /var/log/apache2/error.log

# Logi cron
docker exec feer-app tail -30 /var/log/feer_cron.log

# Logi Traefik
docker logs feer-traefik --tail=50

# Test połączenia z bazą
docker exec feer-app php -r "
  require '/var/www/html/config.php';
  require '/var/www/html/includes/db.php';
  \$r = db_one('SELECT COUNT(*) AS c FROM users');
  echo 'DB OK, users: ' . \$r['c'] . PHP_EOL;
"
```

---

## 8. Codzienna obsługa

### Start / Stop / Restart

```bash
cd /opt/feer-szo/docker

# SQLite prod
COMPOSE="docker compose -f docker-compose.yml -f docker-compose.prod.yml --env-file .env.prod"

# MySQL prod (dodaj -f docker-compose.mysql.yml)

$COMPOSE up -d        # uruchom / wznów
$COMPOSE stop         # zatrzymaj (zachowuje dane)
$COMPOSE down         # usuń kontenery (dane w volumes są zachowane)
$COMPOSE restart app  # restart tylko aplikacji
```

### Przydatne aliasy (dodaj do ~/.bashrc)

```bash
alias feer='docker compose -f /opt/feer-szo/docker/docker-compose.yml \
  -f /opt/feer-szo/docker/docker-compose.prod.yml \
  --env-file /opt/feer-szo/docker/.env.prod'

# Użycie:
feer up -d
feer logs -f app
feer exec app bash
```

### Logi na żywo

```bash
docker logs -f feer-app          # Apache + PHP
docker logs -f feer-traefik      # proxy i SSL
docker exec feer-app tail -f /var/log/feer_cron.log  # cron
```

---

## 9. Aktualizacje

```bash
cd /opt/feer-szo

# 1. Pobierz zmiany
git pull origin main

# 2. Przebuduj obraz (jeśli zmienił się Dockerfile lub php.prod.ini)
docker compose -f docker/docker-compose.yml -f docker/docker-compose.prod.yml \
  --env-file docker/.env.prod up -d --build

# Lub bez przebudowywania (tylko zmiany kodu PHP):
docker exec feer-app kill -USR2 1   # graceful Apache reload

# 3. Wyczyść opcache po aktualizacji
docker exec feer-app sh -c \
  "find /var/www/html -name '*.php' -exec touch {} \; 2>/dev/null; true"
```

---

## 10. Backup i przywracanie

### SQLite — backup ręczny

```bash
# Kopia zapasowa z blokadą WAL
docker exec feer-app sqlite3 /var/www/html/umowy.db ".backup '/tmp/umowy_backup.db'"
docker cp feer-app:/tmp/umowy_backup.db ./backup_$(date +%Y%m%d_%H%M).db
```

### SQLite — backup automatyczny przez SharePoint

Konfiguracja w panelu admina: `admin/m365_settings.php` → sekcja SharePoint.
Backup uruchamiany automatycznie przez `cron/dispatcher.php`.

### MySQL — backup

```bash
docker exec feer-mysql \
  mysqldump -u root -p"${MYSQL_ROOT_PASSWORD}" feer \
  > backup_$(date +%Y%m%d_%H%M).sql

# Przywracanie
docker exec -i feer-mysql \
  mysql -u root -p"${MYSQL_ROOT_PASSWORD}" feer \
  < backup_20240101_1200.sql
```

### Uploads — backup

```bash
tar -czf uploads_$(date +%Y%m%d).tar.gz /opt/feer-szo/uploads/
```

---

## 11. Środowisko deweloperskie

```bash
cd /opt/feer-szo/docker

# Skopiuj konfigurację dev
cp .env.example .env

# Uruchom (auto-ładuje docker-compose.override.yml)
docker compose up -d --build

# Dostępne usługi:
# http://localhost       — aplikacja
# http://localhost:8025  — Mailpit (przechwycone e-maile)
# http://localhost:15672 — RabbitMQ (feer/feer)
```

### DEV + MySQL

```bash
docker compose -f docker-compose.yml -f docker-compose.mysql.yml up -d
```

---

## 12. Rozwiązywanie problemów

### SSL nie działa / certyfikat Let's Encrypt nie pobiera się

```bash
# Sprawdź logi Traefik
docker logs feer-traefik 2>&1 | grep -i "error\|acme\|cert"

# Możliwe przyczyny:
# - DNS nie wskazuje na serwer (dig szo.feer.org.pl)
# - Port 80 zablokowany przez firewall (ufw allow 80/tcp)
# - Osiągnięto limit Let's Encrypt (5 certów/tydzień dla jednej domeny)

# Test certyfikatu
openssl s_client -connect szo.feer.org.pl:443 -servername szo.feer.org.pl < /dev/null
```

### Błąd 502/503 — app nie odpowiada

```bash
docker ps                           # czy feer-app działa?
docker logs feer-app --tail=50      # błędy Apache/PHP
docker exec feer-app php -v         # czy PHP działa?
```

### Błąd bazy danych (SQLite)

```bash
# Sprawdź uprawnienia
docker exec feer-app ls -la /var/www/html/umowy.db

# Napraw uprawnienia
docker exec feer-app chown www-data:www-data /var/www/html/umowy.db
docker exec feer-app chmod 664 /var/www/html/umowy.db

# Sprawdź integralność
docker exec feer-app sqlite3 /var/www/html/umowy.db "PRAGMA integrity_check;"
```

### Błąd bazy danych (MySQL)

```bash
docker exec feer-mysql mysqladmin -u root -p status
docker logs feer-mysql --tail=30
```

### Reset sesji (po zmianie APP_KEY)

```bash
docker exec feer-redis redis-cli FLUSHDB
```

### Ręczne odnowienie certyfikatu SSL

Traefik odnawia certyfikaty automatycznie (30 dni przed wygaśnięciem).
Aby wymusić odnowienie:

```bash
# Usuń plik acme.json (Traefik pobierze nowy certyfikat przy restarcie)
docker exec feer-traefik rm /letsencrypt/acme.json
docker restart feer-traefik
```

---

## Przydatne komendy jednolinijkowe

```bash
# Produkcja — standardowy skrót
PROD="docker compose -f /opt/feer-szo/docker/docker-compose.yml \
  -f /opt/feer-szo/docker/docker-compose.prod.yml \
  --env-file /opt/feer-szo/docker/.env.prod"

$PROD ps                         # status
$PROD logs -f app                # logi na żywo
$PROD exec app bash              # shell w kontenerze
$PROD exec app php cli/prod_check.php   # lista kontrolna
$PROD up -d --build              # przebuduj i uruchom
$PROD pull && $PROD up -d        # aktualizuj obrazy bazowe
```
