# Integracja Helpdesk SZO ↔ Redmine — wdrożenie i konfiguracja

Model docelowy: **w panelu SZO klient tylko składa i śledzi zgłoszenie; cały
backend i obsługa techniczna dzieją się w Redmine.** Zgłoszenia z SZO trafiają do
Redmine przez API, a odpowiedzi/statusy wracają do SZO (webhook + cron).

- **SZO** (dalej `<SZO>`) — Wasz adres SZO, np. `https://szo.feer.org.pl`.
  Dokładne URL-e do skopiowania pokazuje panel **Admin → Redmine**.
- **Redmine** — `https://feer.usermd.net` (MyDevil, FreeBSD, PHP współdzielony).

---

## 0. Wdrożenie kodu (produkcja SZO)

```bash
cd ~/feerSZO
git pull
```

> **Nie uruchamiaj `composer install`** — katalog `vendor/` jest w repo (razem z
> biblioteką `kbsali/redmine-api`). `composer install` na serwerze przegenerowałby
> pliki autoloadu i zablokował kolejny `git pull` (konflikt) oraz może wymusić
> nieobsługiwaną wersję PHP. Jeśli `git pull` zgłosi konflikt na `vendor/composer/*`:
> ```bash
> git checkout -- vendor/composer/autoload_static.php vendor/composer/installed.json vendor/composer/installed.php
> git pull
> ```

Cron dyspozytora musi działać (zwykle już jest):
```
* * * * * php ~/feerSZO/cron/dispatcher.php >> ~/log/szo_cron.log 2>&1
```

---

## 1. W Redmine — włącz API i utwórz konto integracyjne

1. **Administracja → Ustawienia → API** → zaznacz **„Włącz webserwis REST"** → Zapisz.
   (adres: `https://feer.usermd.net/settings?tab=api`)
2. Zaloguj się kontem, które ma być kontem integracyjnym (najlepiej dedykowane,
   z dostępem do projektu Helpdesk) → **Moje konto** → panel po prawej
   **„Klucz API" → Pokaż** i skopiuj.
   (adres: `https://feer.usermd.net/my/account`)
3. Ustal **projekt** na zgłoszenia (np. „Helpdesk"). Identyfikator widać w adresie
   `https://feer.usermd.net/projects/<identyfikator>`.

---

## 2. W SZO — Admin → Redmine (`<SZO>/admin/redmine_settings.php`)

Wpisz i zapisz:

| Pole | Wartość |
|---|---|
| Wysyłaj zgłoszenia do Redmine | ✔ włącz |
| Adres Redmine | `https://feer.usermd.net` |
| Klucz API | (z kroku 1.2) |
| Projekt docelowy | ID lub identyfikator projektu |
| Tracker (opc.) | np. Support |

Kliknij **Testuj połączenie** — powinno pokazać „Połączenie działa (zalogowano
jako …)". Po udanym teście wczytają się listy do mapowań poniżej.

Uzupełnij (opcjonalnie): **mapowanie kategorii → tracker**, **priorytetów** i
**pól niestandardowych**.

Na końcu włącz **„Helpdesk w SZO tylko dla zgłaszających"** — to ukrywa konsolę
operatora w SZO (obsługa wyłącznie w Redmine).

---

## 3. Natychmiastowy sync — plugin Redmine (opcjonalnie, zalecane)

Bez pluginu sync działa przez cron co 10 min. Plugin daje synchronizację od razu
po zmianie w Redmine.

Na serwerze MyDevil (repo SZO jest na tym samym koncie):
```bash
# znajdź katalog Redmine (tam gdzie jest Gemfile i plugins/)
find ~ -maxdepth 4 -name Gemfile -path '*redmine*'

REDMINE=~/domains/feer.usermd.net/public_html   # podmień na właściwy
ln -s ~/feerSZO/redmine-plugin/redmine_szo_sync "$REDMINE/plugins/redmine_szo_sync"
touch "$REDMINE/tmp/restart.txt"
```

Potem w Redmine: **Administracja → Wtyczki → SZO Sync → Konfiguruj**:

| Pole (w Redmine) | Wartość |
|---|---|
| URL webhooka SZO | `<SZO>/api/redmine_webhook.php` |
| Sekret (HMAC) | dowolny długi ciąg |

Ten sam sekret wpisz w SZO: **Admin → Redmine → „Sekret webhooka"**.

---

## 4. OAuth per użytkownik (opcjonalnie — atrybucja zgłoszeń do osób)

Dzięki temu zgłoszenia/komentarze w Redmine są podpisane nazwiskiem realnej osoby
(a nie konta integracyjnego). Wymaga konta Redmine dla danej osoby.

1. W Redmine: **Administracja → Applications → New Application**
   (adres: `https://feer.usermd.net/oauth/applications/new`):
   - **Name**: `SZO`
   - **Redirect URI**: `<SZO>/auth/redmine_callback.php`
   - **Scopes**: zaznacz potrzebne (min. „Śledzenie zagadnień": przeglądanie/
     dodawanie/notatki).
   - Zapisz → skopiuj **Client ID** i **Client Secret**.
2. W SZO: **Admin → Redmine → OAuth Client ID / Client Secret** → Zapisz.
3. Każdy pracownik łączy swoje konto na stronie: `<SZO>/auth/redmine_account.php`
   (przycisk „Połącz z Redmine"). Tam też widzi **„Moje zgłoszenia"** z Redmine.

> OAuth dotyczy tylko zalogowanych użytkowników SZO. Cron, webhook i publiczny
> mini-helpdesk działają zawsze na kluczu API (bezobsługowo).

---

## 5. Mini-helpdesk (publiczny formularz, opcjonalnie)

Dla zgłoszeń bez logowania (np. link na stronie WWW / w Redmine).

1. W SZO: **Admin → Redmine** → włącz **„Publiczny formularz zgłoszeń"**.
2. Adres: `<SZO>/helpdesk/mini.php` — podlinkuj albo osadź:
   ```html
   <iframe src="<SZO>/helpdesk/mini.php" style="width:100%;height:660px;border:0"></iframe>
   ```
3. Dla pełnej publiczności rozważ ochronę na Cloudflare (jest już honeypot + limit).

---

## Ściąga adresów

| Co | Adres |
|---|---|
| Konfiguracja w SZO | `<SZO>/admin/redmine_settings.php` |
| Webhook (do pluginu) | `<SZO>/api/redmine_webhook.php` |
| OAuth Redirect URI | `<SZO>/auth/redmine_callback.php` |
| Łączenie konta / Moje zgłoszenia | `<SZO>/auth/redmine_account.php` |
| Mini-helpdesk | `<SZO>/helpdesk/mini.php` |
| Redmine — API/ustawienia | `https://feer.usermd.net/settings?tab=api` |
| Redmine — klucz API | `https://feer.usermd.net/my/account` |
| Redmine — OAuth apps | `https://feer.usermd.net/oauth/applications/new` |
| Redmine — wtyczki | `https://feer.usermd.net/admin/plugins` |

## Odwzorowanie starego Helpdesku SZO w Redmine

Aby Redmine wiernie odzwierciedlał dotychczasowy Helpdesk, odtwórz w nim tę samą
taksonomię (Redmine nie pozwala zakładać trackerów/statusów/pól przez API — robisz
to raz w panelu Administracja), a potem zmapuj wartości w SZO (Admin → Redmine).

### 1. Priorytety  (Administracja → Wyliczenia → Priorytety zagadnień)
Utwórz i zmapuj (SZO → Redmine) w sekcji „Mapowanie priorytetów":

| SZO | Redmine |
|---|---|
| Niski | Niski |
| Normalny | Normalny (domyślny) |
| Wysoki | Wysoki |
| Krytyczny | Krytyczny / Pilny |

### 2. Statusy  (Administracja → Statusy zagadnień)
Odtwórz statusy dawnego Helpdesku (te „zamykające" oznacz jako *zamknięte*):
Nowe, Otwarte, Oczekuje, Wymaga prac programistycznych, Przekazano do firmy
zewnętrznej, **Rozwiązane** (zamknięty), **Zamknięte** (zamknięty).
> SZO mapuje zwrotnie tylko zamknięcie issue → status „Rozwiązane"; pełne statusy
> obsługujesz już w Redmine (backend).

### 3. Trackery / kategorie  (Administracja → Typy zagadnień)
Dwa podejścia — wybierz jedno:
- **Prościej:** jeden tracker „Helpdesk” + pole niestandardowe **Kategoria** (patrz niżej),
  a w SZO w „Mapowanie kategorii → tracker” wskaż ten sam tracker dla wszystkich.
- **Rozdzielnie:** utwórz trackery np. `Zgłoszenie IT`, `Błąd`, `Propozycja` i zmapuj:

| Kategoria SZO | Tracker Redmine (przykład) |
|---|---|
| Sprzęt IT, Oprogramowanie, Sieć/Internet, Dostęp, Konto, Microsoft 365, Drukarki, Kopia zapasowa, Inne IT | Zgłoszenie IT |
| Błąd w systemie | Błąd |
| Propozycja nowej funkcji, Sugestia | Propozycja |
| Inne (spoza IT) | Zgłoszenie IT |

### 4. Pola niestandardowe  (Administracja → Pola niestandardowe → dla Zagadnień)
Utwórz i zmapuj w SZO (sekcja „Pola niestandardowe” + „Pole »Komentarze«”):

| Pole w Redmine (format) | Źródło ze zgłoszenia SZO |
|---|---|
| Nr zgłoszenia SZO (Tekst) | Nr zgłoszenia SZO |
| Zgłaszający (Tekst) | Zgłaszający — imię i nazwisko |
| E-mail zgłaszającego (Tekst) | Zgłaszający — e-mail |
| Kategoria (Lista / Tekst) | Kategoria |
| **Komentarze (Długi tekst)** | (czytane z Redmine → SZO) |

Dzięki temu każde zagadnienie w Redmine niesie komplet danych dawnego zgłoszenia,
a treść pola **Komentarze** wraca do klienta w SZO.

## Jak to działa (skrót)

- Nowe zgłoszenie w SZO (panel lub mini) → **issue w Redmine** (tracker wg kategorii,
  priorytet, pola niestandardowe).
- Odpowiedź w SZO → **notatka w Redmine** (jako osoba OAuth lub konto integracyjne).
- Zmiana w Redmine (notatka, zamknięcie) → **wraca do SZO** (webhook natychmiast /
  cron co 10 min: import notatek, status „Rozwiązane").
- Cron dopycha też zaległe zgłoszenia bez powiązania — nic nie zostaje poza sync.
