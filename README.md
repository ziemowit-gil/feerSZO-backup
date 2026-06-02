# Platforma NGO — feerSZO

Webowa platforma do zarządzania organizacją pozarządową. PHP 8.1+ / SQLite lub MySQL.

## Moduły

| Moduł | Opis |
|---|---|
| **Umowy** | Wolontariat, zlecenie, dzieło, praca — pełny cykl życia, wydruki, e-podpis |
| **CRM** | Kontakty, grupy, historia komunikacji, masowa wysyłka, AI composer |
| **Zadania** | Tablice kanban, obszary robocze, cykliczne, przypisania, timesheets |
| **Panel wolontariusza** | Logowanie SMS/MS365/kod, podgląd umów, zadania |
| **eObieg DK** | Pisma, sprawy, teczki, dekretacja |
| **Karty 30** | Klienci, konsultacje, listy oczekujących |
| **Katalog** | Współpracownicy i zasoby organizacji |
| **Zdarzenia** | Rejestracje, check-in QR, formularze |
| **Granty** | Zarządzanie projektami grantowymi |
| **Certyfikaty** | Wnioski i wystawianie zaświadczeń |

## Logowanie

| Rola | Metoda |
|---|---|
| Administratorzy, koordynatorzy | E-mail + hasło |
| Pracownicy z MS365 | Konto Microsoft 365 |
| Wolontariusze bez konta MS | Kod SMS na numer z umowy |
| Goście / nowi | Jednorazowy kod od administratora |

## Bezpieczeństwo

- Certyfikat instalacyjny x509 RSA-2048 powiązany z APP_KEY przez HMAC-SHA256
- 2FA: TOTP / SMS / WebAuthn
- Brute-force protection, CSRF na wszystkich formularzach

## Instalacja

1. Wgraj pliki na serwer
2. Otwórz `install.php` w przeglądarce
3. Wygeneruj certyfikat: `creator.php` (hasło twórcy → zmień przy pierwszym logowaniu)
4. Uzupełnij dane organizacji i skonfiguruj e-mail w panelu admina
5. Dodaj CRON: **Admin → Konfiguracja CRON**
6. Wyczyść dane testowe: **Admin → Czyszczenie przed wdrożeniem**

## CRON

```
# DirectAdmin — Menadżer zadań Cron → co minutę → Polecenie:
php /home/.../public_html/cron/dispatcher.php

# lub URL (generuj token w Admin → Konfiguracja CRON):
https://domena.pl/cron.php?token=TOKEN
```

## Narzędzia

| | |
|---|---|
| `creator.php` | Panel twórcy — cert, APP_KEY (hasło: zmień przy pierwszym logowaniu) |
| `licensemanager/` | Zewnętrzny panel zarządzania licencjami |
| `admin/clean_for_prod.php` | Czyszczenie danych testowych przed wdrożeniem |
| `admin/version.php` | Historia zmian i wersja systemu |

## Kontakt

[dev@ziemowit.me](mailto:dev@ziemowit.me)

## Wymagania

- PHP 8.1+, rozszerzenia: `pdo`, `pdo_sqlite` lub `pdo_mysql`, `json`, `mbstring`, `fileinfo`
- Apache z `mod_rewrite` lub Nginx
- Katalog `uploads/` z prawem zapisu przez serwer

## Instalacja

1. Skopiuj pliki na serwer (np. do `/var/www/umowy/`)
2. Nadaj uprawnienia: `chmod 755 uploads/`
3. Otwórz w przeglądarce: `https://twoja-domena.pl/install.php`
4. Przejdź przez 4 kroki kreatora:
   - **Baza danych**: SQLite (plik lokalny) lub MySQL/MariaDB
   - **Microsoft OAuth**: opcjonalne — dane z Azure Portal (rejestracja aplikacji)
   - **Administrator**: konto pierwszego użytkownika
5. **Po instalacji usuń lub zablokuj `install.php`**

## Logowanie Microsoft (Azure AD)

W Azure Portal → Azure Active Directory → Rejestracje aplikacji:
1. Utwórz nową rejestrację
2. Dodaj URI przekierowania: `https://twoja-domena.pl/auth/microsoft.php`
3. Skopiuj: Tenant ID, Client ID (Application ID)
4. Utwórz Client Secret w zakładce "Certyfikaty i klucze tajne"

## Role użytkowników

| Rola    | Opis                                               |
|---------|----------------------------------------------------|
| admin   | Pełny dostęp, zarządzanie użytkownikami            |
| editor  | Dodawanie i edycja umów, upload plików             |
| viewer  | Tylko odczyt rejestru                              |

## Typy umów

- Umowa zlecenie
- Umowa o świadczenie usług
- Umowa wolontariacka
- Umowa o dzieło
- Umowa o pracę
- Inna umowa (najem, NDA, licencja, darowizna itp.)

## Bezpieczeństwo

- Hasła przechowywane jako bcrypt hash
- CSRF tokeny w każdym formularzu POST
- Pliki uploadu w katalogu `uploads/` z blokadą wykonania PHP
- Dostęp do `includes/`, `config.php`, plików `.db`, `.sql` zablokowany przez `.htaccess`
- Sesje z regeneracją ID po zalogowaniu

## Nginx (przykładowa konfiguracja)

```nginx
location ~ ^/umowy/includes/ { deny all; }
location ~ ^/umowy/uploads/.*\.php$ { deny all; }
location ~ /\.(sql|db|env)$ { deny all; }
```
