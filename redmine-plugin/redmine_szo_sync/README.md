# redmine_szo_sync

Wtyczka Redmine, która po utworzeniu/zmianie zagadnienia powiadamia SZO (webhook),
dzięki czemu synchronizacja Helpdesku SZO ↔ Redmine jest natychmiastowa (obok
crona `cron/redmine_sync.php` po stronie SZO).

Wysyłany jest tylko sygnał `{ "issue_id": N }` z podpisem HMAC-SHA256 w nagłówku
`X-SZO-Signature`. Właściwy stan (notatki, status) SZO pobiera z Redmine przez
REST API — treść webhooka nie jest zaufanym źródłem danych.

Wtyczka zakłada też (migracją) pole niestandardowe zagadnień **„Komentarze"**
(Długi tekst, wszystkie trackery) — jego treść SZO ściąga do zgłoszeń.

## Instalacja (na serwerze Redmine — MyDevil, konto `s89`)

Repo SZO jest na tym samym koncie, więc najprościej symlinkiem:

```bash
# 1. znajdź katalog Redmine (tam gdzie Gemfile i plugins/)
find ~ -maxdepth 4 -name Gemfile -path '*redmine*'
REDMINE=~/domains/feer.usermd.net/public_html      # ← podmień na właściwy

# 2. podłącz wtyczkę
ln -s ~/feerSZO/redmine-plugin/redmine_szo_sync "$REDMINE/plugins/redmine_szo_sync"

# 3. migracja wtyczki (tworzy pole „Komentarze")
cd "$REDMINE"
bundle exec rake redmine:plugins:migrate NAME=redmine_szo_sync RAILS_ENV=production

# 4. restart (Passenger)
touch "$REDMINE/tmp/restart.txt"
```

> Kopiowanie zamiast symlinka: `cp -r ~/feerSZO/redmine-plugin/redmine_szo_sync "$REDMINE/plugins/"`.
> Cofnięcie migracji: `bundle exec rake redmine:plugins:migrate NAME=redmine_szo_sync VERSION=0 RAILS_ENV=production`.

Po restarcie: **Administracja → Wtyczki → SZO Sync → Konfiguruj**:
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
