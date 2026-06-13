# feerSZO CRM — dodatek (add-in) do Outlooka

Panel boczny (task pane) na Office.js: przy otwartym mailu pokazuje powiązany kontakt
CRM i pozwala przypiąć mail do kartoteki (historii komunikacji kontaktu).

## Pliki

| Plik | Rola |
|------|------|
| `manifest.php` | Manifest add-inu, generowany dynamicznie z `APP_URL` (działa też multi-tenant). To jest URL do wgrania w Outlooku. |
| `taskpane.html` / `taskpane.js` | Panel boczny: odczyt maila (Office.js), lookup kontaktu, przypięcie. |
| `commands.html` | Plik funkcyjny wymagany przez manifest (add-in używa tylko task pane). |

## Backend (w `crm/api/`)

| Endpoint | Rola |
|----------|------|
| `addin_lookup.php` | `?emails=a@x.pl,b@y.pl` → dopasowane kontakty CRM. |
| `addin_pin.php` | POST → zapis maila do `crm_communications` (dedup po `outlook_message_id`). |

Autoryzacja: token API (`crm_sync_token`) w nagłówku `Authorization: Bearer <token>`,
lub aktywna sesja CRM. Brak problemu z CORS — task pane jest serwowany z domeny feerSZO
(`SourceLocation` w manifeście), więc wywołania API są same-origin.

## Instalacja (sideload)

1. Skopiuj URL manifestu: `{APP_URL}/outlook-addin/manifest.php`
   (gotowy do skopiowania w panelu: **CRM → Ustawienia → Synchronizacja Outlook → Dodatek do Outlooka**).
2. Outlook → *Pobierz dodatki* → *Moje dodatki* → *Dodaj dodatek niestandardowy* → *Z adresu URL* → wklej URL.
3. Otwórz mail → wstążka → przycisk **Kontakt CRM**.
4. Przy pierwszym uruchomieniu wklej **token API** (widoczny w tym samym panelu ustawień).

## Wymagania

- HTTPS (Office add-iny wymagają HTTPS dla wszystkich URL-i — feerSZO działa po https).
- Uprawnienie manifestu: `ReadItem` (odczyt nadawcy/odbiorców/tematu/treści).
- Token API CRM nadany przez administratora.

## Kierunek (in/out)

Wyznaczany w `taskpane.js`: jeśli nadawca = adres zalogowanego użytkownika → `out`
(dopasowanie po odbiorcach), w przeciwnym razie `in` (dopasowanie po nadawcy).
Zapisywane wpisy mają status `zsynchronizowana` i `outlook_message_id` z prefiksem `addin:`
(rozłączny z auto-syncem przez Graph, więc dedup nie koliduje).
