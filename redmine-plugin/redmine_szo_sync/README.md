# redmine_szo_sync

Wtyczka Redmine, która po utworzeniu/zmianie zagadnienia powiadamia SZO (webhook),
dzięki czemu synchronizacja Helpdesku SZO ↔ Redmine jest natychmiastowa (obok
crona `cron/redmine_sync.php` po stronie SZO).

Wysyłany jest tylko sygnał `{ "issue_id": N }` z podpisem HMAC-SHA256 w nagłówku
`X-SZO-Signature`. Właściwy stan (notatki, status) SZO pobiera z Redmine przez
REST API — treść webhooka nie jest zaufanym źródłem danych.

## Instalacja (na serwerze Redmine, np. MyDevil)

1. Skopiuj katalog `redmine_szo_sync/` do `plugins/` w katalogu Redmine:
   ```
   plugins/redmine_szo_sync/
   ```
2. (Wtyczka nie ma migracji ani zależności gem — nic więcej nie trzeba.)
3. Zrestartuj Redmine (Passenger: `touch tmp/restart.txt`).
4. Zaloguj się jako administrator → **Administracja → Wtyczki → SZO Sync → Konfiguruj**:
   - **URL webhooka SZO**: `https://<twoje-szo>/api/redmine_webhook.php`
   - **Sekret (HMAC)**: dowolny długi ciąg — ten sam wpisz w SZO
     (*Ustawienia → Redmine → „Sekret webhooka"*).

## Jak działa

- Hook `controller_issues_new_after_save` / `controller_issues_edit_after_save`
  (działa dla UI i REST API) → POST do SZO z krótkimi timeoutami, odporny na błędy
  (nie przerywa zapisu zagadnienia).
- SZO (`api/redmine_webhook.php`) weryfikuje podpis, znajduje powiązane zgłoszenie
  po `redmine_issue_id` i pobiera aktualny stan z Redmine (import notatek, mapowanie
  zamknięcia na status „Rozwiązane").

## Wymagania

Redmine 5.x/6.x. Bez dodatkowych gemów (używa `net/http`, `openssl` z Rubiego).
