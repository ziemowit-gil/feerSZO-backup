# SquirrelMail „sqm" — awaryjny klient poczty

> **Obraz:** budowany lokalnie z `./Dockerfile` (PHP 7.4 + SquirrelMail 1.4.22)
> **Compose:** `docker/docker-compose.sqm.yml` · **Domena:** `${SQM_DOMAIN}`

## 1. Najpierw ostrzeżenie, bo zmienia decyzję

**SquirrelMail nie zaloguje się do skrzynki `@feer.org.pl`.** Zna wyłącznie
logowanie hasłem (Basic Auth) do IMAP i SMTP, a Microsoft **trwale wyłączył je
w Exchange Online** — nie da się tego odblokować w tenancie. Nie ma też obsługi
OAuth2 i nigdy nie będzie: ostatnie wydanie to **1.4.22 z 2011 roku**, projekt
nie dostaje nawet poprawek bezpieczeństwa.

Praktyczne wnioski:

- Dla skrzynek Microsoft 365 użytecznymi klientami są **Outlook w przeglądarce**,
  **Roundcube** (`../roundcube/`) i **SnappyMail** (`../snappymail/`).
- SquirrelMail trzymamy jako **awaryjny klient dla skrzynek na innym serwerze
  IMAP** (własny Dovecot, poczta u zewnętrznego dostawcy) — i dla sprzętu
  albo przeglądarek, na których nie uruchamia się nic nowszego. Tylko wtedy
  wpisuj jego adres w Admin → Poczta → Webmail.
- Puste pole „SquirrelMail" w Admin → Poczta → Webmail = klient **nie pojawia
  się** ani na `poczta.feer.org.pl`, ani w panelu wolontariusza. To jest stan
  domyślny i zalecany.

W zestawieniu na `poczta.feer.org.pl` klient jest oznaczony jako **awaryjny**,
a wiersze „Logowanie kontem Microsoft" i „Aktywnie rozwijany i łatany" mają
jawne „—" z wyjaśnieniem — użytkownik nie wybierze go przez pomyłkę
(`includes/webmail_clients.php`).

## 2. Dlaczego PHP 7.4, a nie 8.x

Kod 1.4.x pochodzi z ery PHP 4/5 i na PHP 8 przestaje działać (usunięta rodzina
`ereg_*`, przekazywanie przez referencję w wywołaniach itd.). 7.4 to najnowsza
wersja, na której 1.4.22 startuje bez łatania — a portowanie martwego projektu
nie ma sensu. Konsekwencja: **kontener stoi na nieutrzymywanym PHP**, więc
domyślnie jest zamknięty na sieci prywatne (middleware `sqm-allow`
w `docker-compose.sqm.yml`).

## 3. Uruchomienie

W `.env.prod`:

```env
SQM_DOMAIN=sqm.feer.org.pl          # rekord A na IP serwera
SQM_IMAP_HOST=imap.twojdostawca.pl  # NIE outlook.office365.com
SQM_IMAP_PORT=993
SQM_IMAP_TLS=1                      # 1 = SSL/TLS, 2 = STARTTLS
SQM_SMTP_HOST=smtp.twojdostawca.pl
SQM_SMTP_PORT=587
SQM_SMTP_TLS=2
# Kto ma dostęp. Domyślnie tylko sieci prywatne — jeśli musi być z internetu,
# wpisz konkretne adresy, nigdy 0.0.0.0/0.
SQM_ALLOW_IPS=10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,127.0.0.1/32
```

```bash
cd docker
docker compose -f docker-compose.yml -f docker-compose.prod.yml \
  -f docker-compose.sqm.yml --env-file .env.prod up -d --build sqm
```

Konfiguracja jest brana z ENV przez `config_local.php` (SquirrelMail wczytuje go
po `config.php`), więc **nie uruchamiamy interaktywnego `conf.pl`**. Zmiana
ustawień = zmiana `.env.prod` + `up -d --force-recreate sqm`.

## 4. Pułapki

- **`up -d sqm` pisze „Container feer-sqm Running"** — Compose nie przeładował
  kontenera. Wymuś `--force-recreate` (ta sama pułapka co przy Roundcube
  i SnappyMailu).
- **Zmiana `Dockerfile`/`config_local.php` wymaga `--build`** — bez tego Compose
  użyje starego obrazu `feer-squirrelmail:1.4.22`.
- **Dane użytkowników są w woluminie `sqm_data`** (`/var/local/squirrelmail`),
  celowo poza webrootem — w 1.4.x to jedyna ochrona preferencji i załączników
  przed odczytem po HTTP. Nie przenosić ich do `/var/www/html`.
- **Adres pobierania obrazu** (SourceForge) może przestać działać — wtedy
  podmień `SQM_URL` w `Dockerfile` na inne lustro wydania 1.4.22 i przebuduj.
- **Nie dodawaj tu wtyczek „na produkcję"** (np. `change_password`, `sieve`) bez
  przejrzenia kodu — to najczęstsze źródło podatności w tym ekosystemie.
