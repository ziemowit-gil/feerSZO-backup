# FEER NGO — Wtyczka Moodle: Synchronizacja wolontariuszy

Plugin Moodle (`local_feer_sync`) łączący system zarządzania wolontariuszami FEER NGO z platformą Moodle.

## Co robi

- Synchronizuje konta wolontariuszy z aktywną umową → tworzy/aktualizuje konta Moodle
- Zapisuje wolontariuszy na kursy (globalnie i wg mapowania Działanie → Kurs)
- Zawiesza konta Moodle gdy umowa zostaje zakończona/anulowana
- Działa automatycznie (codziennie o 03:00) lub ręcznie z panelu admina
- Prowadzi dziennik każdej synchronizacji

## Instalacja

1. Skopiuj katalog `local/feer_sync` do `<moodle_root>/local/`
2. Zaloguj się jako administrator Moodle → **Administracja → Powiadomienia** → zainstaluj plugin
3. Skonfiguruj w **Admin → Wtyczki → Lokalne → FEER NGO**

## Konfiguracja po stronie FEER

1. Wejdź w **Admin → Klucze API** → Utwórz nowy klucz z uprawnieniem `volunteers:read`
2. Skopiuj klucz — będzie widoczny tylko raz

## Konfiguracja w Moodle

| Pole | Opis |
|---|---|
| **Adres URL systemu FEER** | np. `https://system.mojafundacja.pl` |
| **Klucz API** | Klucz z uprawnieniem `volunteers:read` |
| **Automatyczny zapis na kursy** | ID kursów Moodle po przecinku, np. `3,7,12` |
| **Mapowanie Działanie → Kurs** | JSON: `{"42": 5, "43": 8}` (action_id z FEER → course_id w Moodle) |
| **Utwórz konto jeśli brak** | Twórz nowe konto Moodle gdy wolontariusz go nie ma |
| **Zawieś konto gdy umowa się kończy** | Zawieś konto Moodle po zakończeniu umowy |

## Endpoint FEER

Plugin wywołuje:
```
GET /api/v1/moodle_sync.php?include=active&page=1&per_page=100
Authorization: Bearer <klucz_api>
```

Odpowiedź zawiera: `id`, `email`, `firstname`, `lastname`, `status`,
`contract_status_group` (`active`/`ended`/`pending`), `action_id`, `action_name`,
`project`, `start_date`, `end_date`, `updated_at`.

## Synchronizacja przyrostowa

Przy każdej synchronizacji plugin zapisuje czas wykonania w `mdl_config_plugins`.
Kolejna synchronizacja może pobrać tylko zmienione rekordy: `?since=2026-06-01+03:00:00`.

## Struktura plików

```
local/feer_sync/
├── version.php                        — deklaracja wtyczki (Moodle 4.0+)
├── settings.php                       — formularz ustawień w panelu admina
├── lib.php                            — haki Moodle
├── db/
│   ├── install.xml                    — tabele: sync_users (mapa e-mail→moodle_id), sync_log
│   └── tasks.php                      — zaplanowane zadanie (codziennie 03:00)
├── classes/
│   ├── api/feer_client.php            — klient REST API FEER (stronicowanie)
│   └── task/sync_task.php             — logika synchronizacji
├── admin/sync.php                     — panel ręcznej synchronizacji + dziennik
└── lang/{en,pl}/local_feer_sync.php  — tłumaczenia
```
