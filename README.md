# Rejestr Umów Fundacji

System zarządzania umowami dla organizacji non-profit. PHP 8.1+ z SQLite lub MySQL.

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
