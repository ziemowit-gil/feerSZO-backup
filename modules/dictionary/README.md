# Słownik Centralny (`modules/dictionary`)

Jedno miejsce na listy wyboru całego SZO — zamiast stałych PHP (`const X_STATUSES = [...]`), tabel słownikowych
w modułach i list wpisanych na sztywno w formularzach.

Panel: **Admin → Słownik Centralny** (`/modules/dictionary/router.php`), także w launcherze i Ctrl+K.

## Model

| Tabela | Zawartość |
|---|---|
| `dictionaries` | słownik: `code` (unikalny, używany w kodzie), `name`, `kind`, `is_protected`, `parent_id` (drzewo kategorii), soft-delete `deleted_at` |
| `dictionary_entries` | wpis: `code` (wartość zapisywana w danych, unikalna w słowniku), `label` (polski), `parent_id` (drzewo wpisów), `attributes` (JSON), `valid_from`/`valid_to`, `is_active`, `version`, soft-delete |
| `dictionary_entry_labels` | tłumaczenia etykiety `(entry_id, locale)`; języki w ustawieniu `dictionary_locales` (domyślnie `en,uk`) |
| `dictionary_history` | każda zmiana: akcja, stan przed/po (JSON), autor, czas |

Schemat tworzy się sam (`dictionaryMigrate()` w `lib/dictionarySchema.php`, za bramką `szo_schema_current`).
SQL jest przenośny SQLite ↔ MySQL; znaczniki czasu liczy PHP; rodzice w imporcie/eksporcie są po **kodach**, nie id.

### Rodzaje i blokady

- **Statyczny / Dynamiczny / Biznesowy** — zwykła edycja wg uprawnień.
- **Systemowy** — kody wpisów są użyte w kodzie aplikacji: nie można zmienić kodu wpisu ani słownika, nie można
  usunąć wpisu ani słownika. Wolno zmieniać etykiety, tłumaczenia, atrybuty, kolejność, okres i aktywność.
- **Zabezpieczony** (`is_protected`) — niezależnie od rodzaju blokuje każdą zmianę (także import). Włącza i zdejmuje
  tylko administrator (przycisk na stronie słownika).

### Wersjonowanie

- `valid_from`/`valid_to` — okres obowiązywania. Opcje do formularzy pokazują wpisy obowiązujące w danym dniu
  (domyślnie dziś; `?at=` w API, `$at` w PHP). Zamiast zmieniać znaczenie wpisu: zakończ jego okres i dodaj nowy.
- `version` — blokada optymistyczna: formularz wysyła wersję, równoczesna edycja kończy się komunikatem, nie nadpisaniem.
- `dictionary_history` — pełna historia z różnicami (ekran „Historia”, także dla pojedynczego wpisu).
- Usunięcie = kosz (soft-delete). Etykiety wpisów z kosza nadal działają w `dictLabel()` (stare dane się wyświetlają).

## Uprawnienia

Moduł uprawnień `slowniki` (Role i uprawnienia): `can_read` — przegląd, historia, eksport; `can_write` — edycja
i import; `can_delete` — kosz i przywracanie. Admin ma wszystko. Edytor dostaje domyślnie odczyt.
`/api/{kod}` jest dostępne dla każdego zalogowanego (listy w formularzach) — **w słownikach nie trzymamy danych wrażliwych**.

## API dla modułów (PHP)

```php
require_once dirname(__DIR__, N) . '/modules/dictionary/lib/dictionaryApi.php';

$opcje = dictOptions('kod', STARA_STALA);            // ['kod_wpisu' => 'Etykieta'] — aktywne, obowiązujące dziś, kolejność drzewa
$wpisy = dictItems('kod');                           // ['kod_wpisu' => ['label','parent','depth','has_children','attributes','description']]
$nazwa = dictLabel('kod', $row['pole'], $row['pole']); // etykieta zapisanej wartości (także nieaktywnej / z kosza)
if (!dictAllowed('kod', $_POST['pole'], array_keys(STARA_STALA))) { /* błąd walidacji */ }
echo dictDatalist('kod', 'id_pola', ['adres' => 'id_pola_adresu']); // podpowiedzi + autouzupełnienie z atrybutów
```

- Funkcje-skróty **nigdy nie rzucają wyjątku**: brak słownika, pusty słownik albo błąd bazy → `$fallback`.
  Dzięki temu przepięty moduł działa też na instalacji, na której słownika jeszcze nie ma.
- Wszystkie przyjmują `$locale` (np. `'en'`; brak tłumaczenia → polski) i `$at` (`Y-m-d`).
- Pełne API (zapis, historia, import): `dictService()` → `DictionaryService`, `dictTransfer()` → `DictionaryTransfer`.
  Dane wejściowe zawsze przez DTO: `DictionaryInput::fromArray()`, `EntryInput::fromArray()` (walidacja,
  `DictionaryValidationException` z błędami per pole).

### Cache

Odczyty (`getDictionaryItems`, `getLabel`, `exists`) są cache'owane w pamięci procesu oraz — gdy włączony
(Admin → Redis) — w Redisie, w grupie kluczy `dict` (TTL 1 h). Każdy zapis przez serwis czyści pamięć procesu
i podbija pokolenie grupy (`szo_cache_bump('dict')`), więc zmiana jest widoczna od następnego żądania.
**Nie zapisuj do tabel słownika z pominięciem serwisu** — cache się nie unieważni (najwyżej po TTL).

## API JSON (dla JS)

- `GET /modules/dictionary/router.php/api/{kod}?locale=en&at=2026-01-01&leaves=1` →
  `{"code": "...", "items": [{"code","label","parent","depth","has_children","attributes","description"}]}`
- `GET /modules/dictionary/router.php/api` — lista słowników (wymaga `can_read('slowniki')`).

## Import / eksport

- **JSON** (`{"format":"szo-dictionary","version":1,"dictionaries":[{..., "entries":[...]}]}`) — wiele słowników,
  zakłada brakujące, kategorie przed podkategoriami. Format = eksport, więc służy też do przenoszenia między instalacjami.
- **CSV** — jeden słownik: `code;label;parent_code;sort_order;is_active;valid_from;valid_to;description;attributes;label_en…`
  (UTF-8 z BOM, `;`; import wykrywa też `,` i tabulator). `attributes` = obiekt JSON w komórce.
  Eksport dopisuje apostrof przed `= + - @` (ochrona przed formułami w Excelu), import go zdejmuje.
- Import dopasowuje po kodach: nowe dodaje, zmienione aktualizuje (nowa wersja + historia), reszty nie rusza.
  Opcja „Tylko próba” liczy zmiany w wycofywanej transakcji.

## Wytyczne przepinania istniejących modułów

Na start podpięty jest jeden słownik: **`instytucje_dotujace`** (kategoria `dotacje`) → pole „Organ zlecający”
w umowie powierzenia (`contracts/powierzenie/add.php`, `edit.php`), zasilony seedem
`lib/seeds/instytucjeDotujace.json` (16 urzędów marszałkowskich, NIW-CRSO, ministerstwa, PFRON, MCPS,
Urząd m.st. Warszawy) przez migrację jednorazową `2026-10_dictionary_instytucje_dotujace` w `modules/selfrepairDB`.

Kolejne listy przepinaj **po jednej, osobnym commitem**:

1. Wybierz kod słownika (`snake_case`, bez polskich znaków) i rodzaj. Jeśli klucze stałej są porównywane w kodzie
   (`if ($status === 'rozwiązana')`, `case 'inny':`) → rodzaj **systemowy**.
2. Zasiej słownik ze stałej migracją jednorazową w `modules/selfrepairDB/logic/migrations.php`
   (`dictTransfer()->import(json, 'json')` albo `dictService()->importDictionary([...], $wpisy)`); dodatkowe pola stałej
   (ikona, kolor, odznaka) → `attributes`.
3. W kodzie zastąp odczyt stałej funkcją z fallbackiem na tę stałą — **stałej nie usuwaj** w tym kroku:
   - listy w `<select>` → `dictOptions('kod', STALA)`,
   - walidacja POST → `dictAllowed('kod', $v, array_keys(STALA))`,
   - wyświetlanie zapisanej wartości → `dictLabel('kod', $v, STALA[$v] ?? $v)`,
   - stałe z metadanymi (`['label' => …, 'icon' => …]`) → `dictItems()` i `attributes`.
4. Wartości w tabelach biznesowych zostają kodami wpisów (nie id) — przenośne i czytelne w SQL i eksportach.
5. Pole tekstowe z typowymi wartościami (organ, instytucja, miejscowość) → `dictDatalist()`; wolny wpis zostaje.
6. Kandydaci (stałe w `includes/`): `CRM_DELETE_REASONS`, `CORR_MEDIA`, `CRM_CHANNELS`, `CRM_OFFER_FUNDING`,
   `CERTIFICATE_TYPES`, `SHIPMENT_PURPOSE`, sfery zadań publicznych (datalist `sfery_list` w powierzeniu).
   Statusy z logiką przejść (`STATUS_LABELS` umów, statusy CRM) przenoś ostatnie i tylko jako **systemowe**.
7. Przeniesione statusy z logiką: `crm_sprawy_statusy` (includes/crm.php) i **`umowy_statusy`** — statusy umów wszystkich
   typów (`modules/contract_statuses/lib/contractStatuses.php`: `contractStatusOptions($typ)`, `contractStatusLabel()`,
   `contractStatusBadge()`, `contractStatusesInGroup('active')`, `contractStatusSqlIn()`). Gdy zapisywana wartość ma polskie
   znaki lub spacje, kod wpisu jest jej wersją ASCII (`w_realizacji`), a wartość leży w atrybucie `wartosc`.
