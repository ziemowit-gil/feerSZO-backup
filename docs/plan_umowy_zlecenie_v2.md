# Umowy zlecenie w feerSZO‑v2 — plan porządków i rozbudowy

Stan na: 2026‑10‑06. Termin pełnego uruchomienia: **1 stycznia 2027**.

## 0. Ustalenia wstępne (to, co wynika z kodu, a nie z opisu)

| Temat | Opis w zadaniu | Jak jest naprawdę |
|---|---|---|
| Framework | Laravel, SQLite, moduły | `feerSZO-v2`: Laravel 13.8, `nwidart/laravel-modules` 13, `spatie/laravel-permission`, mPDF. Moduł: `Modules/Umowy`. |
| Front | Tailwind / Alpine.js | **Inertia + Vue 3 + Tailwind 4**. Strony w `resources/js/Pages/Umowy/*.vue`, layout `@/Layouts/AppLayout.vue`. Alpine nie występuje; UI robimy w Vue. |
| Baza | migracje | v2 **czyta tę samą bazę co v1** (`DB_DATABASE=/Users/zgil/webev-projects/feerSZO/umowy.db`). Tabela `umowy_zlecenie` ma ~100 kolumn utrzymywanych przez v1. Do 1 stycznia v1 pracuje równolegle, więc migracje muszą być **addytywne i idempotentne** (wzorzec `Schema::hasTable/hasColumn` jak w `2026_08_04_000002`). Żadnych `rename`/`drop`. |
| Rachunki | „generowanie rachunku" | **System nie generuje rachunków** — robi to księgowy. Zamiast tego: zamknięcie okresu ewidencji + wydruk „Zestawienie godzin / protokół" dla księgowości. Tabela `zlecenie_rachunki` z v1 (rejestr plików rachunków) zostaje nietknięta. |
| Godziny z dydaktyki | — (nowe) | Źródło „Dydaktyka – Karty30 dziennik": `k30_ti_sessions` (`instructor_id`, `course_id`, `duration_min`, `status`), `k30_ti_courses.instructor_id`, `k30_ti_course_coinstructors`. Godziny **per grupa** (= per `course_id`). |
| Co już jest w v2 | — | `ContractAutoCompleteJob` (po terminie → `do rozliczenia` dla zlecenia), `ContractExpiryReminderJob` (e‑mail 30/14/7/1 dni), `ContractTemplateService` + `print`, `ContractNumberService`, audyt. **Nic nie jest zaplanowane w schedulerze** (`routes/console.php` ma tylko `inspire`). |
| Istniejąca ewidencja | — | Tabela `timesheets` = **jeden wpis na miesiąc** (unique `contract_type, contract_id, rok, miesiac`), bez dat dziennych i bez grup. Nie nadaje się na ewidencję per dzień/grupa; zostaje dla wolontariatu. |

Decyzje projektowe wynikające z powyższego:

1. Pracujemy w `feerSZO-v2/Modules/Umowy`, nie w v1. (Ta sesja ma otwarty katalog v1 — do implementacji trzeba dodać `feerSZO-v2` do sesji.)
2. Logika specyficzna dla zlecenia idzie do **podprzestrzeni `Zlecenie`** w module (`Modules/Umowy/app/{Enums,Http/Requests,Services,Models}/Zlecenie/...`), a generyczny `ContractController` zostaje dla pozostałych 6 typów. Nie rozbijamy modułu na nowy — zlecenie dzieli z resztą audyt, aneksy, zatwierdzenia, M365.
3. Cykl życia zlecenia = 5 stanów biznesowych (Projekt, Aktywna, Do rozliczenia, Zakończona, Anulowana) **mapowanych na istniejące łańcuchy w bazie**, żeby v1 i v2 widziały to samo do dnia przełączenia.

---

## Krok 1. Migracje bazy danych

Wszystkie w `Modules/Umowy/database/migrations/`, wszystkie z bramką „jeśli istnieje — nic nie rób".

### 1.1 `2026_10_10_000001_add_zlecenie_hours_source_columns.php`

Nowe kolumny w `umowy_zlecenie` (addytywnie):

| kolumna | typ | po co |
|---|---|---|
| `zrodlo_godzin` | string(20) default `'reczne'` | `reczne` \| `karty30` — skąd biorą się wpisy ewidencji |
| `ti_instructor_user_id` | integer null, FK `users.id` | którego prowadzącego sesje zaciągać (umowa nie ma `user_id`, tylko `person_id`/`email`) |
| `rozliczenie_okres` | string(10) default `'miesiac'` | `miesiac` (jedyny na start; zostawia furtkę na `tydzien`) |
| `ostatnia_synchronizacja_at` | datetime null | kiedy ostatnio pobrano godziny z dziennika |

```php
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('umowy_zlecenie', function (Blueprint $t) {
            if (!Schema::hasColumn('umowy_zlecenie', 'zrodlo_godzin')) {
                $t->string('zrodlo_godzin', 20)->default('reczne');
            }
            if (!Schema::hasColumn('umowy_zlecenie', 'ti_instructor_user_id')) {
                $t->unsignedBigInteger('ti_instructor_user_id')->nullable()->index();
            }
            if (!Schema::hasColumn('umowy_zlecenie', 'rozliczenie_okres')) {
                $t->string('rozliczenie_okres', 10)->default('miesiac');
            }
            if (!Schema::hasColumn('umowy_zlecenie', 'ostatnia_synchronizacja_at')) {
                $t->dateTime('ostatnia_synchronizacja_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        // SQLite: dropColumn wymaga doctrine/dbal lub przebudowy tabeli — świadomie no-op,
        // kolumny są nieszkodliwe dla v1.
    }
};
```

### 1.2 `2026_10_10_000002_create_zlecenie_ewidencja_tables.php`

Dwie tabele. Okres jest „kopertą" miesiąca ze statusem; wpisy są dzienne i (dla Karty30) per sesja/grupa.

**`zlecenie_ewidencja_okresy`**

| kolumna | typ | uwagi |
|---|---|---|
| id | pk | |
| contract_id | int, index | FK logiczne do `umowy_zlecenie.id` (bez constraintu — v1 nie ma FK) |
| rok, miesiac | int | unique(`contract_id`, `rok`, `miesiac`) |
| status | string(20) | `otwarty` → `zamkniety` → `przekazany` (do księgowości) |
| suma_godzin | decimal(8,2) | cache, przeliczany przez serwis |
| stawka_snapshot | decimal(10,2) | stawka obowiązująca w chwili zamknięcia |
| kwota_wstepna | decimal(12,2) | `suma_godzin × stawka` (godzinowa) lub stawka miesięczna |
| zamkniety_by / zamkniety_at | int / datetime | |
| przekazany_by / przekazany_at | int / datetime | |
| uwagi | text | |
| created_at / updated_at | datetime | |

**`zlecenie_ewidencja_wpisy`**

| kolumna | typ | uwagi |
|---|---|---|
| id | pk | |
| okres_id | int, index | FK do okresu |
| contract_id | int, index | denormalizacja dla prostych sum |
| data | date | dzień pracy |
| godziny | decimal(5,2) | > 0, ≤ 24 |
| opis | text null | co zrobiono |
| zrodlo | string(20) | `reczne` \| `karty30` |
| ti_course_id | int null | grupa z dziennika |
| ti_course_name | string null | snapshot nazwy grupy (grupy bywają kasowane/zmieniane) |
| ti_session_id | int null | **unique(`contract_id`, `ti_session_id`)** — idempotentna synchronizacja |
| created_by / updated_by | int | |
| created_at / updated_at | datetime | |

```php
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('zlecenie_ewidencja_okresy')) {
            Schema::create('zlecenie_ewidencja_okresy', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('contract_id')->index();
                $t->unsignedSmallInteger('rok');
                $t->unsignedTinyInteger('miesiac');
                $t->string('status', 20)->default('otwarty');
                $t->decimal('suma_godzin', 8, 2)->default(0);
                $t->decimal('stawka_snapshot', 10, 2)->nullable();
                $t->decimal('kwota_wstepna', 12, 2)->default(0);
                $t->unsignedBigInteger('zamkniety_by')->nullable();
                $t->dateTime('zamkniety_at')->nullable();
                $t->unsignedBigInteger('przekazany_by')->nullable();
                $t->dateTime('przekazany_at')->nullable();
                $t->text('uwagi')->nullable();
                $t->timestamps();
                $t->unique(['contract_id', 'rok', 'miesiac'], 'uq_zl_okres');
            });
        }

        if (!Schema::hasTable('zlecenie_ewidencja_wpisy')) {
            Schema::create('zlecenie_ewidencja_wpisy', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('okres_id')->index();
                $t->unsignedBigInteger('contract_id')->index();
                $t->date('data');
                $t->decimal('godziny', 5, 2);
                $t->text('opis')->nullable();
                $t->string('zrodlo', 20)->default('reczne');
                $t->unsignedBigInteger('ti_course_id')->nullable()->index();
                $t->string('ti_course_name')->nullable();
                $t->unsignedBigInteger('ti_session_id')->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->unsignedBigInteger('updated_by')->nullable();
                $t->timestamps();
                $t->unique(['contract_id', 'ti_session_id'], 'uq_zl_wpis_session');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('zlecenie_ewidencja_wpisy');
        Schema::dropIfExists('zlecenie_ewidencja_okresy');
    }
};
```

### 1.2a Numery umowy: praca hybrydowa w 2026 (numer na rachunku ≠ numer oryginalny)

W 2026 r. umowy zlecenie prowadzone są hybrydowo (v1 + obieg poza systemem), więc **numer umowy wpisany na rachunku nie jest tożsamy z numerem oryginalnym** umowy. W `umowy_zlecenie` są już cztery pola numerów: `numer_umowy`, `nr_roboczy`, `nr_system`, `nr_rejestru`; w `zlecenie_rachunki` jest własne `numer`. Nie dokładamy piątego pola „na ślepo", tylko porządkujemy znaczenie:

| pole | znaczenie od 1 I 2027 | w 2026 (hybryda) |
|---|---|---|
| `numer_umowy` | **numer oryginalny** (z dokumentu podpisanego) | jak dziś; nie zmieniamy |
| `nr_rejestru` | numer w Rejestrze Umów (globalny) | jak dziś |
| `nr_system` | numer nadany przez system (autonumer `ContractNumberService`) | może być pusty |
| `nr_roboczy` | **numer używany na rachunkach / w obiegu księgowym** — alias, pod którym księgowy zna umowę | tu wpisujemy numer „z rachunku" |

Dodatkowo migracja `2026_10_10_000003_create_zlecenie_numery_alias.php` tworzy tabelę **`zlecenie_numery_alias`** (`contract_id`, `alias`, `rodzaj` = `rachunek`|`stary_system`|`papier`, `obowiazuje_od`, `obowiazuje_do`, `uwagi`, unique(`alias`, `rodzaj`)), bo jedna umowa może mieć w ciągu roku więcej niż jeden numer „księgowy" (aneks, zmiana obiegu). `nr_roboczy` to zawsze alias bieżący, tabela trzyma historię.

Konsekwencje w kodzie:

- `ZlecenieFilters` i pole „szukaj" dopasowują **każdy** numer: `numer_umowy`, `nr_rejestru`, `nr_system`, `nr_roboczy` oraz `zlecenie_numery_alias.alias`. Wynik na liście pokazuje, po którym numerze trafiono („znaleziono po numerze z rachunku").
- Zestawienie godzin dla księgowego drukuje **oba numery**: „Umowa nr … (na rachunkach: …)". Przekazanie okresu do księgowości bez uzupełnionego aliasu dla umów sprzed 2027 r. daje ostrzeżenie (nie blokadę).
- `Show.vue` ma sekcję „Numery i aliasy" z historią; edycja aliasu = audyt.
- **Cutover nie przenumerowuje** niczego. Od 1 I 2027 nowe umowy dostają jeden numer (`numer_umowy` = `nr_system` z autonumeru), a `nr_roboczy` pozostaje puste; hybryda kończy się dla umów zawartych od nowego roku, stare zachowują aliasy do końca obowiązywania.
- Walidacja: `nr_roboczy` i aliasy są unikalne w obrębie roku (`rodzaj`+`alias`), ale **nie** unikalne względem `numer_umowy` innej umowy — bo właśnie w hybrydzie zdarza się, że numer rachunkowy jednej umowy wygląda jak oryginalny innej. Raport cutover wypisuje takie kolizje do ręcznego sprawdzenia.

### 1.3 Normalizacja statusów — **dopiero na przełączeniu (1 stycznia)**

Nie migracja, lecz komenda `umowy:zlecenie-cutover` uruchamiana ręcznie w dniu startu (patrz Krok 6). Do tego dnia v1 zapisuje `do podpisu`, `podpisana`, `zawieszona`, `obowiązująca` (z polskimi znakami!) i v2 musi je czytać. Mapowanie w enumie (Krok 2), nie w bazie.

### 1.4 Dla testów: `database/schema/sqlite-schema.sql`

v2 nie ma migracji tworzącej `umowy_zlecenie`. Żeby testy Feature działały na `:memory:`, dodajemy wyciąg schematu (`umowy_zlecenie`, `users`, `k30_ti_courses`, `k30_ti_sessions`, `k30_ti_course_coinstructors`, `contract_audit_log`) ładowany przez `Schema::blueprintResolver`/`DB::unprepared` w `tests/TestCase.php`. Jednorazowo: `sqlite3 umowy.db .schema <tabela> >> database/schema/sqlite-schema.sql`.

---

## Krok 2. Model domeny: enumy, modele, serwisy

### 2.1 `Enums/Zlecenie/ZlecenieStatus.php` — 5 stanów na 12 łańcuchach

```php
<?php
namespace Modules\Umowy\Enums\Zlecenie;

enum ZlecenieStatus: string
{
    case Projekt       = 'projekt';
    case Aktywna       = 'w realizacji';
    case DoRozliczenia = 'do rozliczenia';
    case Zakonczona    = 'zakonczona';
    case Anulowana     = 'anulowana';

    /** Łańcuchy zapisane historycznie przez v1 → stan biznesowy. */
    public static function fromLegacy(?string $raw): self
    {
        return match (mb_strtolower(trim((string) $raw))) {
            'projekt', 'do podpisu'                                   => self::Projekt,
            'podpisana', 'w realizacji', 'zawieszona',
            'obowiazujaca', 'obowiązująca', 'zablokowana'             => self::Aktywna,
            'do rozliczenia'                                          => self::DoRozliczenia,
            'zakonczona', 'zakończona', 'wygasla', 'wygasła'          => self::Zakonczona,
            'anulowana', 'rozwiazana', 'rozwiązana'                   => self::Anulowana,
            default                                                   => self::Projekt,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Projekt       => 'Projekt',
            self::Aktywna       => 'Aktywna',
            self::DoRozliczenia => 'Do rozliczenia',
            self::Zakonczona    => 'Zakończona',
            self::Anulowana     => 'Anulowana',
        };
    }

    /** @return self[] */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Projekt       => [self::Aktywna, self::Anulowana],
            self::Aktywna       => [self::DoRozliczenia, self::Zakonczona, self::Anulowana],
            self::DoRozliczenia => [self::Zakonczona, self::Aktywna],   // powrót = aneks/przedłużenie
            self::Zakonczona    => [],
            self::Anulowana     => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedNext(), true);
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::Aktywna, self::DoRozliczenia], true);
    }

    public function color(): string
    {
        return match ($this) {
            self::Projekt       => 'gray',
            self::Aktywna       => 'green',
            self::DoRozliczenia => 'amber',
            self::Zakonczona    => 'blue',
            self::Anulowana     => 'red',
        };
    }
}
```

Dodatkowo `ZrodloGodzin` (`reczne`, `karty30`) i `TypStawki` (`godzinowo`, `miesiecznie`, `ryczalt`) jako małe enumy string‑backed. Etykieta opcji w UI: **„Dydaktyka – Karty30 dziennik"**.

### 2.2 Model `ContractZlecenie` — uzupełnienia (bez łamania reszty modułu)

```php
protected $casts = [
    // ... istniejące ...
    'zrodlo_godzin'              => ZrodloGodzin::class,
    'typ_stawki'                 => TypStawki::class,
    'ti_instructor_user_id'      => 'integer',
    'ostatnia_synchronizacja_at' => 'datetime',
    'data_rozpoczecia'           => 'date',
    'data_zakonczenia'           => 'date',
];

public function statusEnum(): ZlecenieStatus
{
    return ZlecenieStatus::fromLegacy($this->status);
}

public function okresy(): HasMany
{
    return $this->hasMany(EwidencjaOkres::class, 'contract_id')->orderByDesc('rok')->orderByDesc('miesiac');
}

public function wpisy(): HasMany
{
    return $this->hasMany(EwidencjaWpis::class, 'contract_id');
}

// Scopes do listy i alertów
public function scopeOpen(Builder $q): Builder
{
    return $q->whereIn('status', ['podpisana', 'w realizacji', 'zawieszona', 'obowiazujaca', 'obowiązująca', 'zablokowana', 'do rozliczenia']);
}

public function scopeEndingWithin(Builder $q, int $days): Builder
{
    return $q->open()
        ->where('bezterminowa', 0)
        ->whereNotNull('data_zakonczenia')
        ->whereBetween('data_zakonczenia', [today()->toDateString(), today()->addDays($days)->toDateString()]);
}

public function scopeOverdue(Builder $q): Builder
{
    return $q->open()->where('bezterminowa', 0)->where('data_zakonczenia', '<', today()->toDateString());
}

public function scopeForExecutor(Builder $q, string $name): Builder
{
    return $q->where('imie_nazwisko', 'like', "%{$name}%");
}
```

Nowe modele: `Models/Zlecenie/EwidencjaOkres` (`$table = 'zlecenie_ewidencja_okresy'`, `casts status => OkresStatus`), `Models/Zlecenie/EwidencjaWpis`.

### 2.3 Serwisy (`Services/Zlecenie/`)

| Serwis | Odpowiedzialność | Nie robi |
|---|---|---|
| `WynagrodzenieCalculator` | czyste obliczenia: `kwotaZaOkres(ContractZlecenie, float $godziny): Money`; godzinowo → `godziny × stawka`; miesięcznie → `stawka_kwota` (proporcjonalnie przy niepełnym miesiącu od/do); ryczałt → `wynagrodzenie_brutto` tylko informacyjnie | nie dotyka bazy, nie liczy podatków/ZUS (to księgowy) |
| `EwidencjaService` | `dodajWpis()`, `edytujWpis()`, `usunWpis()`, `okresDla(contract, rok, miesiac)` (get‑or‑create), `przeliczOkres()`, `zamknijOkres()`, `przekazOkres()`, `otworzPonownie()` (tylko admin, z audytem) | nie wie o Karty30 |
| `Karty30HoursImporter` | `sync(ContractZlecenie, int $rok, int $miesiac): SyncResult` — zaciąga sesje prowadzącego, grupuje per `course_id`, upsert wpisów po `ti_session_id`, usuwa wpisy których sesje zniknęły/anulowano; odmawia dla okresu `zamkniety`/`przekazany` | nie modyfikuje tabel `k30_*` |
| `ZlecenieLifecycleService` | `zmienStatus(contract, ZlecenieStatus $to, ?string $note)` z walidacją przejścia + audyt; `oznaczDoRozliczenia()` (dla joba); `zakoncz()` sprawdza, czy wszystkie okresy są `przekazane` | nie renderuje |
| `ZestawienieGodzinRenderer` | HTML/PDF „Zestawienie godzin i wstępnego wynagrodzenia za okres" (mPDF, jak `print`), z podziałem per grupa dla Karty30 | **nie generuje rachunku** |

Zasady liczenia godzin z dziennika (`Karty30HoursImporter`):

```php
// sesje, które liczą się do wynagrodzenia — zgodnie z v1 (karty30.php:2781, schedulerReports.php:27)
private const PAYABLE_STATUSES = ['held', 'individual_change', 'remote_material'];

private function sessionsFor(ContractZlecenie $c, int $rok, int $miesiac): Collection
{
    $uid = $c->ti_instructor_user_id;
    return DB::table('k30_ti_sessions as s')
        ->join('k30_ti_courses as c', 'c.id', '=', 's.course_id')
        ->leftJoin('k30_ti_course_coinstructors as ci', fn ($j) =>
            $j->on('ci.course_id', '=', 'c.id')->where('ci.user_id', $uid))
        ->whereRaw("strftime('%Y-%m', s.lesson_date) = ?", [sprintf('%04d-%02d', $rok, $miesiac)])
        ->whereIn('s.status', self::PAYABLE_STATUSES)
        ->whereNull('s.draft_id')
        ->where(fn ($w) => $w
            ->where('s.instructor_id', $uid)                                   // prowadzący sesji
            ->orWhere(fn ($x) => $x->whereNull('s.instructor_id')->where('c.instructor_id', $uid)) // domyślny prowadzący grupy
            ->orWhereNotNull('ci.id'))                                         // współprowadzący
        ->whereBetween('s.lesson_date', [$c->data_rozpoczecia, $c->data_zakonczenia ?? '9999-12-31'])
        ->select('s.id', 's.lesson_date', 's.duration_min', 's.time_from', 's.time_to', 's.topic',
                 'c.id as course_id', 'c.name as course_name', 'c.group_code')
        ->get();
}

/** Minuty → godziny. Decyzja: dokładnie (min/60, 2 miejsca). Moduł Faktury w v1 liczy ceil(min/60) — to inna sprawa (fakturowanie klienta). */
private function hours(object $s): float
{
    $min = (int) $s->duration_min ?: $this->minutesFromRange($s->time_from, $s->time_to);
    return round($min / 60, 2);
}
```

Wynik synchronizacji = lista linii per grupa (`course_id`, nazwa, liczba sesji, suma godzin) + wpisy dzienne. Stawka zawsze **z umowy** (`stawka_kwota`), nie z `lesson_payout_bb` grupy; `lesson_payout_bb` i `rate_override` pokazujemy jako informację pomocniczą w UI (do decyzji: czy ma kiedykolwiek nadpisywać stawkę umowy — domyślnie **nie**).

---

## Krok 3. Walidacja i kontrolery

### 3.1 Form Requesty (`Http/Requests/Zlecenie/`)

`StoreZlecenieRequest` / `UpdateZlecenieRequest` (update dziedziczy i zmienia `required` → `sometimes|required`):

```php
public function rules(): array
{
    return [
        'numer_umowy'        => ['nullable', 'string', 'max:100'],   // puste = autonumer z ContractNumberService
        'imie_nazwisko'      => ['required', 'string', 'max:255'],
        'pesel'              => ['nullable', 'digits:11', new PeselChecksum],
        'email'              => ['nullable', 'email', 'max:255'],
        'przedmiot_zlecenia' => ['required', 'string'],
        'data_zawarcia'      => ['required', 'date'],
        'data_rozpoczecia'   => ['required', 'date', 'after_or_equal:data_zawarcia'],
        'bezterminowa'       => ['boolean'],
        'data_zakonczenia'   => ['exclude_if:bezterminowa,true', 'required', 'date', 'after_or_equal:data_rozpoczecia'],
        'typ_stawki'         => ['required', Rule::enum(TypStawki::class)],
        'stawka_kwota'       => ['required_unless:typ_stawki,ryczalt', 'nullable', 'numeric', 'min:0.01', 'max:999999.99'],
        'wynagrodzenie_brutto' => ['required_if:typ_stawki,ryczalt', 'nullable', 'numeric', 'min:0'],
        'liczba_godzin_planowana' => ['nullable', 'numeric', 'min:0'],
        'zrodlo_godzin'      => ['required', Rule::enum(ZrodloGodzin::class)],
        'ti_instructor_user_id' => ['required_if:zrodlo_godzin,karty30', 'nullable', 'integer', 'exists:users,id'],
        'opiekun'            => ['nullable', 'string', 'max:255'],
        'org_unit_id'        => ['nullable', 'integer'],
        'uwagi'              => ['nullable', 'string'],
        // status NIE jest tu — zmiana statusu to osobny endpoint
    ];
}
```

`StoreWpisRequest`: `data` (date, w granicach umowy, nie w okresie zamkniętym — walidator `OkresOtwarty`), `godziny` (`numeric`, `min:0.25`, `max:24`), `opis` (`nullable|max:2000`). Dla umów z `zrodlo_godzin = karty30` wpis ręczny jest dozwolony tylko z flagą „poza dziennikiem" (opis wymagany) — żeby nie dublować sesji.

`ChangeStatusRequest`: `status` (`Rule::enum(ZlecenieStatus)`), `note` (`nullable|max:1000`).

### 3.2 Kontrolery (`Http/Controllers/Zlecenie/`)

Cienkie: request → serwis → redirect/Inertia.

| Kontroler | Trasy |
|---|---|
| `ZlecenieController` | `GET /umowy/zlecenie` (lista z filtrami), `GET create`, `POST`, `GET {id}`, `GET {id}/edit`, `PATCH {id}` — **przejmuje** gałąź `zlecenie` z pętli w `routes/web.php` (reszta typów bez zmian) |
| `ZlecenieStatusController` | `POST /umowy/zlecenie/{id}/status` |
| `EwidencjaController` | `GET /umowy/zlecenie/{id}/ewidencja/{rok}/{miesiac}`, `POST .../wpisy`, `PATCH/DELETE .../wpisy/{wpis}`, `POST .../zamknij`, `POST .../przekaz`, `POST .../otworz` |
| `Karty30SyncController` | `POST /umowy/zlecenie/{id}/ewidencja/{rok}/{miesiac}/sync` |
| `ZestawienieController` | `GET .../zestawienie` (HTML do druku), `GET .../zestawienie.pdf` |

Uprawnienia (spatie): istniejące `umowy.view/.create/.edit` + nowe `umowy.ewidencja.edit`, `umowy.ewidencja.close`, `umowy.ewidencja.reopen`.

Lista — filtry w `index()` przez `ZlecenieFilters` (invokable, pipeline `when()`): `q` (numer/nazwisko/e‑mail), `status` (po 5 stanach biznesowych — tłumaczone na listę łańcuchów legacy), `wykonawca`, `od`/`do` (zakres dat obowiązywania, zachodzenie przedziałów), `konczace_sie` (14 dni), `zrodlo_godzin`, `opiekun`, `org_unit_id`. Sort: `data_zakonczenia`, `imie_nazwisko`, `numer_umowy`.

---

## Krok 4. UI (Inertia + Vue 3 + Tailwind 4)

Strony w `resources/js/Pages/Umowy/Zlecenie/`:

- **`Index.vue`** — pasek filtrów (status‑chips: 5 stanów + „Kończące się ≤14 dni" + „Po terminie"), pola: szukaj, wykonawca, od/do, źródło godzin. Wiersz: numer, wykonawca, okres od–do, stawka (`120,00 zł/h` / `4 000,00 zł/mies.` / ryczałt), status‑badge, **badge „kończy się za 9 dni"** (amber) / **„po terminie"** (red), suma godzin w bieżącym miesiącu, ikona źródła (dziennik). Bez modali — master‑detail jak w reszcie v2.
- **`Form.vue`** — sekcje: Strony → Przedmiot → Okres (od/do/bezterminowa) → Wynagrodzenie (typ stawki ↔ pola warunkowe, podgląd „≈ X zł za pełny miesiąc przy N h") → **Ewidencja godzin**: select „Źródło godzin": *Ręczne wpisy* / *Dydaktyka – Karty30 dziennik*; przy drugim pojawia się wybór prowadzącego (`users` z rolą dydaktyk, podpowiedź po `email` umowy) i podgląd „w tym miesiącu dziennik ma N sesji w M grupach". Status nie jest polem formularza.
- **`Show.vue`** — nagłówek ze statusem i akcjami przejść (tylko dozwolone z `allowedNext()`), zakładki: *Dane*, *Ewidencja godzin*, *Dokumenty/pisma*, *Aneksy*, *Historia*.
- **`Ewidencja.vue`** (zakładka) — nawigacja miesiącami; dla Karty30: tabela **per grupa** (grupa, kod, liczba sesji, godziny, kwota) + rozwijane sesje dzienne; przycisk „Pobierz z dziennika" (sync) i „Ostatnia synchronizacja: …". Dla ręcznych: tabela dzienna + inline‑formularz dodawania. Stopka: suma godzin, stawka, **wstępne wynagrodzenie**, przyciski „Zamknij okres" → „Przekaż do księgowości" → link „Zestawienie (PDF)". Okres zamknięty = tylko odczyt, ikona kłódki.
- **`Zestawienie.vue`** — widok do druku (`@media print`), w PDF przez mPDF z tego samego szablonu Blade (`resources/views/zlecenie/zestawienie.blade.php`), bo mPDF nie renderuje Vue.

Komponenty wspólne do wyciągnięcia: `StatusBadge.vue`, `MoneyCell.vue`, `HoursCell.vue`, `PeriodNav.vue`.

---

## Krok 5. Automaty: statusy, alerty, harmonogram

1. **Scheduler** — brak w v2, dodać w `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('umowy:maintenance --auto-complete --expiry-reminders')->dailyAt('06:00')->withoutOverlapping();
Schedule::command('umowy:zlecenie-sync-karty30')->dailyAt('05:30');          // dociąga sesje z dziennika dla otwartych okresów
Schedule::command('umowy:zlecenie-otworz-okresy')->monthlyOn(1, '05:00');    // tworzy okres bieżącego miesiąca dla aktywnych umów
```

   Plus cron na serwerze: `* * * * * php artisan schedule:run`.

2. **Auto „Do rozliczenia"** — `ContractAutoCompleteJob` już to robi dla zlecenia (po `data_zakonczenia`, pomija `bezterminowa`). Zmiana: przejść przez `ZlecenieLifecycleService::oznaczDoRozliczenia()` zamiast surowego `DB::update`, żeby audyt i reguły były w jednym miejscu.

3. **Alerty 14 dni** — dwa kanały:
   - e‑mail do wykonawcy: istnieje (`ContractExpiryReminderJob`, 30/14/7/1). Dodać kopię do opiekuna (`opiekun` → e‑mail z `users`) i do skrzynki kadr z `config('umowy.alerts.to')`.
   - w aplikacji: scope `endingWithin(14)` → licznik na `Dashboard.vue` (kafel „Kończące się umowy zlecenie: N") i badge w liście; `overdue()` → „Do zamknięcia: N".

4. **Blokady**: zmiana statusu na `Zakonczona` wymaga, by wszystkie okresy były `przekazane` (albo świadome potwierdzenie admina z notatką). Sync z dziennika nigdy nie dotyka okresu `zamkniety`/`przekazany`.

---

## Krok 6. Testy, migracja danych, harmonogram do 1 stycznia 2027

### Testy (Pest/PHPUnit, SQLite `:memory:` ze schematem z 1.4)

- Unit: `ZlecenieStatus::fromLegacy` (wszystkie 12 łańcuchów + polskie znaki), `allowedNext`, `WynagrodzenieCalculator` (godzinowo/miesięcznie/niepełny miesiąc/ryczałt).
- Feature: Form Request (daty od/do, `required_unless` dla stawki, `required_if` dla prowadzącego), `EwidencjaService` (unikalność okresu, blokada wpisu do zamkniętego okresu), `Karty30HoursImporter` (fixture: 2 grupy, sesje `held`/`cancelled`/`draft`, współprowadzący → oczekiwane sumy per grupa; ponowny sync nie dubluje; anulowana sesja znika), `ContractAutoCompleteJob` (po terminie → `do rozliczenia`, bezterminowa nie), lista: filtr „kończące się 14 dni".

### Przełączenie (cutover) — komenda `umowy:zlecenie-cutover`

Uruchamiana raz, 1 stycznia 2027 (lub 2 stycznia rano), po zamrożeniu edycji zleceń w v1:

1. `status` → normalizacja do 5 wartości (`fromLegacy()->value`), wpis audytu `cutover`.
2. `zrodlo_godzin = 'reczne'` dla wszystkich; dla umów, których `email` pasuje do `users.email` z rolą dydaktyk → podpowiedź `karty30` w raporcie (bez automatycznego ustawiania — decyzja kadr).
3. Utworzenie okresu `2027‑01` dla każdej aktywnej umowy.
4. Import aliasów numerów z `zlecenie_rachunki.numer` (2026) do `zlecenie_numery_alias` (rodzaj `rachunek`) + `nr_roboczy`; raport kolizji numerów (alias jednej umowy = `numer_umowy` innej).
5. Raport: umowy bez `data_zakonczenia` i bez `bezterminowa`, bez stawki, z `typ_stawki` spoza enuma.
6. W v1: `contracts/zlecenie/*` → stub 302 na v2 (jak przy wygaszaniu karty30 — wzorzec `office_enter`).

### Harmonogram (12 tygodni)

| Tydzień | Zakres | Commit‑y (jedna funkcja = jeden commit) |
|---|---|---|
| 41–42 (6–17 X) | Krok 1 + 2: migracje, enumy, modele, `WynagrodzenieCalculator`, schemat testowy | 5–6 |
| 43–44 | Krok 3: Form Requesty, `ZlecenieController` + `ZlecenieStatusController`, `ZlecenieFilters` (szukanie po wszystkich numerach i aliasach), aliasy numerów (1.2a), przepięcie tras | 5–6 |
| 45–46 | `EwidencjaService` + `EwidencjaController` + `Ewidencja.vue` (ręczne wpisy, okresy, zamknięcie/przekazanie) | 4 |
| 47–48 | `Karty30HoursImporter` + sync + UI per grupa + komenda dzienna | 3–4 |
| 49 | Zestawienie HTML/PDF dla księgowości | 2 |
| 50 | Scheduler, alerty 14 dni (dashboard, lista, e‑mail do opiekuna), auto‑status przez serwis | 3 |
| 51 | Index/Form/Show — porządki UI, filtry, komponenty wspólne; dokumentacja `docs/umowy_zlecenie.md` | 3 |
| 52 (21–31 XII) | Testy end‑to‑end na kopii `umowy.db`, próbny cutover na kopii, szkolenie kadr/księgowego, zamrożenie v1 | 2 |
| **1 I 2027** | `umowy:zlecenie-cutover`, stub w v1, start | 1 |

Bufor: cały tydzień 52 jest zapasem; gdy coś się przesunie, pierwszy do wycięcia jest tydzień 51 (porządki UI), nie 47–48 (dziennik).

---

## Pytania do rozstrzygnięcia (nie blokują startu prac)

1. **Minuty → godziny**: dokładnie (`min/60`) czy jak w module Faktury (`ceil`)? Propozycja: dokładnie, zaokrąglenie do 0,25 h jako opcja per umowa.
2. **Stawka przy Karty30**: zawsze z umowy, czy `lesson_payout_bb`/`rate_override` grupy może ją nadpisać? Propozycja: tylko z umowy; dane grupy wyłącznie informacyjnie.
3. **Współprowadzący**: liczymy pełne godziny sesji każdemu, czy dzielimy? Propozycja: pełne każdemu (tak liczy dziś raport prowadzącego).
4. **Kto zamyka okres**: opiekun umowy, kadry, czy sam zleceniobiorca (portal)? Propozycja: kadry/opiekun; zleceniobiorca w v2 na razie tylko czyta zestawienie.
5. Czy pola PESEL/dowód/adres z `umowy_zlecenie` mają w v2 pozostać w jednej tabeli, czy iść do `persons` (`person_id` już istnieje)? Propozycja: zostawić do cutoveru, a po 1 stycznia osobny etap „dane osobowe → persons".
