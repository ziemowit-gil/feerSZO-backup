# Docker — środowisko deweloperskie FEER SZO

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
