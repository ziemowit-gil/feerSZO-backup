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

## Wymagania

- PHP 8.1+ z rozszerzeniami: `pdo`, `pdo_sqlite` lub `pdo_mysql`, `json`, `mbstring`, `openssl`, `zip`
- Apache z `mod_rewrite` lub Nginx
- Katalog `uploads/` z prawem zapisu przez serwer

---

## Instalacja (nowa)

### 1. Wgraj pliki

Skopiuj zawartość repozytorium na serwer, np. do `public_html/` lub podkatalogu.

```bash
git clone https://codeberg.org/ziemowitgil/feerSZO.git .
chmod 755 uploads/ certs/ logs/ backups/
```

Lub wgraj przez FTP i nadaj uprawnienia w menedżerze plików panelu hostingowego.

### 2. Uruchom kreator instalacji

Otwórz w przeglądarce:

```
https://twoja-domena.pl/install.php
```

Kreator przeprowadzi przez 6 kroków:

| Krok | Co robisz |
|---|---|
| 1. Wymagania | Sprawdzenie PHP i uprawnień — musi być zielono |
| 2. Baza danych | Wybierz SQLite (zalecane) lub MySQL |
| 3. Organizacja | Pełna nazwa i numer KRS |
| 4. Microsoft 365 | Opcjonalne — dane z Azure AD (można pominąć) |
| 5. Administrator | E-mail i hasło pierwszego konta admin |
| 6. Gotowe | Lista kolejnych kroków |

### 3. Wygeneruj certyfikat instalacyjny

Otwórz panel twórcy i zaloguj się hasłem domyślnym:

```
https://twoja-domena.pl/creator.php
# hasło: zaq1@WSX  ← zmień od razu w sekcji creator.password
```

W sekcji **cert.generate** wpisz KRS i nazwę organizacji → kliknij `generate_cert()`.  
Bez certyfikatu aplikacja wyświetla ekran blokady.

### 4. Skonfiguruj CRON

**DirectAdmin** → Zaawansowane funkcje → Menadżer zadań Cron → Dodaj zadanie:

- Minuta / Godzina / Dzień / Miesiąc / Dzień tyg.: wszystkie `*`
- Polecenie (skopiuj z **Admin → Konfiguracja CRON**):

```
php /home/login/domains/domena.pl/public_html/cron/dispatcher.php
```

Lub wygeneruj token i użyj URL-cron (dla hostingów bez PHP CLI):

```
https://twoja-domena.pl/cron.php?token=WYGENEROWANY_TOKEN
```

### 5. Uzupełnij konfigurację

W panelu **Admin → Dane organizacji**:
- Logo, kolory brandingu
- E-mail wysyłki (M365 lub SMTP)
- Osoby do reprezentacji (podpisujące umowy)

Checklist konfiguracji wstępnej jest widoczna na pulpicie admina dopóki wszystkie punkty nie są zielone.

### 6. Wyczyść dane testowe i uruchom

```
Admin → Czyszczenie przed wdrożeniem
```

Wpisz `CZYŚĆ` i potwierdź. Usuwa wszystkie dane demo, zachowuje konfigurację.

### 7. Zabezpiecz pliki instalacyjne

Po zakończeniu instalacji **usuń lub zablokuj dostęp** do:

```
install.php
setup_db.php
```

---

## Aktualizacja (upgrade produkcyjny)

### Kiedy aktualizować

Po wgraniu nowej wersji kodu (np. przez `git pull` lub FTP) uruchom panel aktualizacji, który:
- Porównuje zainstalowaną wersję z aktualną (git hash)
- Pokazuje listę zmian od ostatniej aktualizacji
- Wykonuje migracje schematu bazy (bezpieczne — tylko dodaje, nigdy nie usuwa)
- Automatycznie tworzy backup bazy przed migracją

### Kroki aktualizacji

**1. Wgraj nowe pliki na serwer**

```bash
git pull origin main
# lub wgraj przez FTP — nie nadpisuj config.php !
```

> `config.php` jest generowany podczas instalacji i zawiera klucze — **nie zastępuj go**.

**2. Otwórz panel aktualizacji**

```
https://twoja-domena.pl/upgrade.php
```

Zaloguj się e-mailem i hasłem konta admina lub kodem IKA.

**3. Sprawdź zmiany i uruchom migracje**

Panel pokaże:
- Ile nowych commitów od ostatniej aktualizacji
- Listę zmian z opisami (feat / fix / refactor)
- Status pokrycia schematu bazy

Kliknij **Uruchom aktualizację** — backup jest tworzony automatycznie do `backups/`.

**4. Co jest chronione**

Migracje **nigdy nie ruszają**:
- Konfiguracji Microsoft 365 (tenant_id, client_id, client_secret)
- Ustawień SMTP / e-mail / SMS
- APP_KEY i certyfikatu instalacyjnego
- Danych organizacji i kont użytkowników
- Wszystkich umów, zadań i dokumentów

**5. W razie problemu**

Backup bazy przed migracją: `backups/upgrade_YYYYMMDD_HHMMSS.db`

```bash
# Przywróć ręcznie (SQLite):
cp backups/upgrade_20260602_143022.db umowy.db
```

---

## Logowanie

| Rola | Metoda |
|---|---|
| Administratorzy, koordynatorzy | E-mail + hasło |
| Pracownicy i wolontariusze z MS365 | Konto Microsoft 365 |
| Wolontariusze bez konta MS | Kod SMS na numer z umowy wolontariackiej |
| Goście / nowi pracownicy | Jednorazowy kod od administratora |

## Bezpieczeństwo

- Certyfikat instalacyjny x509 RSA-2048 powiązany z APP_KEY przez HMAC-SHA256
- 2FA: TOTP / SMS / WebAuthn dla adminów
- Brute-force protection na logowaniu
- CSRF na wszystkich formularzach POST

## Narzędzia

| Narzędzie | Opis |
|---|---|
| `creator.php` | Panel twórcy — generowanie certyfikatu, APP_KEY, zmiana hasła |
| `upgrade.php` | Aktualizacja schematu bazy z porównaniem wersji git |
| `licensemanager/` | Zewnętrzny panel zarządzania licencjami instalacji |
| `admin/cron_setup.php` | Gotowe polecenia CRON dla DirectAdmin i CLI |
| `admin/clean_for_prod.php` | Czyszczenie danych testowych przed wdrożeniem |
| `admin/version.php` | Historia commitów i aktualna wersja systemu |

## Kontakt

[dev@ziemowit.me](mailto:dev@ziemowit.me)
