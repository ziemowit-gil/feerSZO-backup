# Docker — FEER SZO

## Szybka instalacja produkcyjna (Ubuntu 22.04 / 24.04)

```bash
curl -fsSL https://codeberg.org/ziemowitgil/feerSZO/raw/branch/main/docker/setup.sh \
  | sudo bash
```

Z MySQL:

```bash
curl -fsSL https://codeberg.org/ziemowitgil/feerSZO/raw/branch/main/docker/setup.sh \
  | sudo bash -s -- --mysql
```

Skrypt:
- instaluje Docker Engine + UFW,
- klonuje repo do `/opt/feer-szo`,
- interaktywnie tworzy `.env.prod` (pyta o domenę, e-mail ACME, APP\_KEY generuje sam),
- uruchamia stack: `app` + `redis` + `traefik` (SSL Let's Encrypt).

> Szczegółowy opis kroków: [`DEPLOY.md`](DEPLOY.md)

---

## Konfiguracja Microsoft 365 (Azure Portal)

Panel: `https://portal.azure.com` → **App registrations** → **New registration**

### 1. Rejestracja aplikacji

| Pole | Wartość |
|------|---------|
| Name | FEER SZO |
| Supported account types | Single tenant |
| Redirect URI (Web) | `https://szo.feer.org.pl/auth/microsoft.php` |

Po zapisaniu skopiuj **Application (client) ID** i **Directory (tenant) ID**.

### 2. Client Secret

**Certificates & secrets** → **New client secret** → skopiuj wartość (widoczna raz).

### 3. API Permissions → Add a permission → Microsoft Graph

#### Application permissions (wymagane)

| Permission | Do czego |
|-----------|----------|
| `User.ReadWrite.All` | Tworzenie i edycja kont M365 |
| `Directory.ReadWrite.All` | Grupy, role, obiekty katalogu |
| `Organization.Read.All` | Dane tenanta, domeny, licencje |
| `Mail.Send` | Wysyłanie maili jako dowolny użytkownik |

#### Application permissions (opcjonalne — włącz jeśli używasz modułu)

| Permission | Moduł |
|-----------|-------|
| `Sites.ReadWrite.All` | SharePoint — pliki i backup |
| `Contacts.Read` | Synchronizacja kontaktów |
| `Calendars.Read` | Synchronizacja kalendarzy |
| `GroupMember.ReadWrite.All` | Zarządzanie grupami M365 |

#### Delegated permissions — Exchange Online (opcjonalne, dla Roundcube)

`IMAP.AccessAsUser.All` · `SMTP.Send`

#### Delegated permissions — Microsoft Graph (dla SSO)

`openid` · `profile` · `email` · `offline_access`

### 4. Grant Admin Consent

**API permissions** → **Grant admin consent for [Twoja organizacja]** → Confirm.

> Bez Admin Consent uprawnienia Application nie będą aktywne.

### 5. Wpisz dane do systemu

Panel aplikacji: `https://szo.feer.org.pl/admin/m365_settings.php`

Lub w `.env.prod`:

```dotenv
MS_ENABLED=1
MS_TENANT_ID=xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx
MS_CLIENT_ID=xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx
MS_CLIENT_SECRET=twoj_secret
MS_REDIRECT_URI=https://szo.feer.org.pl/auth/microsoft.php
```

Po zapisaniu: `https://szo.feer.org.pl/admin/graph_permissions.php` → weryfikacja uprawnień.

---

## Środowisko deweloperskie FEER SZO

## Wymagania

- Docker Engine 24+
- Docker Compose v2
- Git (repozytorium sklonowane lokalnie)

---

## Instrukcja uruchomienia

### 1. Sklonuj repozytorium (jeśli jeszcze nie masz)

```bash
git clone <url-repozytorium> feerSZO
cd feerSZO
```

### 2. Zainstaluj zależności PHP

```bash
composer install
```

> Jeśli nie masz Composera lokalnie, możesz uruchomić go przez Docker:
> ```bash
> docker run --rm -v "$(pwd):/app" composer:2 install
> ```

### 3. Skonfiguruj zmienne środowiskowe

```bash
cp docker/.env.example docker/.env
```

Domyślne porty — zmień w `docker/.env` jeśli są zajęte:

```dotenv
MAILPIT_HTTP_PORT=8025   # panel e-mail
RABBITMQ_MGMT_PORT=15672 # panel RabbitMQ
```

> Aplikacja działa zawsze na porcie **80** (mapowanie stałe `80:80`).

### 4. Zbuduj i uruchom kontenery

```bash
cd docker
docker compose up --build -d
```

Pierwsze uruchomienie trwa dłużej (pobieranie obrazów, budowanie PHP z rozszerzeniami).

### 5. Sprawdź czy wszystko działa

```bash
docker compose ps
```

Wszystkie usługi powinny mieć status `running (healthy)`.

### 6. Otwórz w przeglądarce

| Adres | Co to jest |
|-------|-----------|
| http://localhost | Aplikacja FEER SZO |
| http://localhost:8025 | Mailpit — przechwycone e-maile |
| http://localhost:15672 | RabbitMQ — panel zarządzania (login: `feer` / `feer`) |

---

## Codzienna praca

### Start / stop

```bash
cd docker
docker compose up -d        # uruchom w tle
docker compose stop         # zatrzymaj (zachowuje dane)
docker compose down         # zatrzymaj i usuń kontenery (dane w volumes są zachowane)
docker compose down -v      # usuń też volumes (czysta instalacja)
```

### Przebudowanie obrazu po zmianie Dockerfile

```bash
docker compose up --build -d
```

### Logi

```bash
docker compose logs -f app           # Apache + PHP
docker compose logs -f rabbitmq      # broker wiadomości
docker exec feer-szo-app tail -f /var/log/feer_cron.log   # zadania cron
```

### Wejście do kontenera aplikacji

```bash
docker exec -it feer-szo-app bash
```

### Inspekcja Redis (sesje)

```bash
docker exec feer-szo-redis redis-cli keys 'feer:*'
```

### Ręczne uruchomienie kolejki e-mail

```bash
docker exec feer-szo-app php /var/www/html/cron/mail_queue.php
```

---

## Architektura usług

| Usługa | Obraz | Rola |
|--------|-------|------|
| `app` | PHP 8.4-apache | Aplikacja + cron |
| `redis` | redis:7-alpine | Sesje logowania (`session.save_handler=redis`) |
| `rabbitmq` | rabbitmq:3-management-alpine | Kolejka wiadomości e-mail (kolejka `mail.send`) |
| `mailpit` | axllent/mailpit | Catch-all SMTP — przechwytuje wszystkie e-maile |

## Przepływ wiadomości e-mail

```
mail_queue_add()
    → INSERT do SQLite  (historia, admin, retry)
    → rabbit_publish('mail.send', ['id' => $mail_id])

cron/mail_queue.php (co minutę)
    → rabbit_consume_batch('mail.send')
        → SELECT z SQLite po id
        → wysyłka (_mail_send → Mailpit w dev)
        → UPDATE status w SQLite
    → fallback: polling SQLite jeśli RabbitMQ niedostępny
```

## Struktura plików

| Plik | Opis |
|------|------|
| `Dockerfile` | PHP 8.4 + Apache + ext-redis + cron |
| `docker-compose.yml` | Definicja wszystkich usług |
| `apache.conf` | VirtualHost z AllowOverride All |
| `php.ini` | E_ALL, upload 50M, strefa Warsaw, sesje Redis |
| `msmtp.conf` | PHP `mail()` → Mailpit |
| `crontab` | Zadania cykliczne aplikacji |
| `entrypoint.sh` | Start crona + Apache |
