# Moduł Materiały zewnętrzne

Specyfikacja techniczno-funkcjonalna modułu obsługi zewnętrznych zasobów
edukacyjnych: książek od wydawnictw, e-booków, dokumentów licencjonowanych
i materiałów prawnie chronionych.

Stack: **czysty PHP 8.1+ i SQLite** — tak jak reszta SZO. Bez frameworka, bez ORM,
bez kolejek zewnętrznych. Kod trzyma się konwencji projektu: `db_one/db_all/db_exec`,
samonaprawiający się schemat, `h()` na wyjściu, `csrf_check()` na zapisie,
agenty w `cron/dispatcher.php`.

Planowane rozmieszczenie: `includes/ext_materials.php`, `includes/ext_access.php`,
`karty30/ti/ext/*`, `api/v1/ext.php`, `cron/ext_agent.php`.

---

## 0. Zasady nadrzędne

**1. Plik chroniony nigdy nie leży pod adresem URL.** Nic nie trafia do `uploads/`
dostępnego z sieci ani do `public/`. Jedyna droga do treści prowadzi przez PHP,
po sprawdzeniu uprawnień. Ścieżka na dysku nie wychodzi na zewnątrz — na zewnątrz
istnieje wyłącznie `resource_id`.

**2. Dostęp to bilet, nie link.** Podpisany adres da się przekleić na komunikator.
Bilet jest rekordem w bazie: jednorazowy, przypisany do osoby, z krótkim terminem
ważności i możliwością unieważnienia.

**3. Każdy dostęp zostawia ślad — także odmowa.** Przy materiałach licencjonowanych
log jest dowodem wykonania umowy z wydawcą. Odmowy są w nim równie ważne:
pokazują, że kontrola działa.

**4. Nie obiecujemy szczelnego DRM.** Wszystko, co dociera do przeglądarki, da się
sfotografować i wydrukować do PDF-a. Moduł daje kontrolę dostępu, ograniczenie
skali, znak wodny wskazujący konkretną osobę i pełny ślad. To jest to, czego
licencje realnie wymagają — i tyle wolno napisać w umowie z wydawcą.

---

## 1. Model danych

### 1.1 Hierarchia

```
Kategoria (drzewo)  ──┐
                      ├─→ Tytuł (dzieło) ──→ Wydanie ──→ Zasób (plik/rozdział/link)
Wydawca ──────────────┘                                        └─→ Zasób (pod-rozdział)
```

| Poziom | Po co osobno |
|---|---|
| **Wydawca** | strona umowy; narzuca reguły dziedziczone przez wszystkie tytuły |
| **Kategoria** | klasyfikacja przecinająca wydawców; drzewo, uprawnienia dziedziczą w dół |
| **Tytuł** | dzieło niezależne od wydania — prawa te same, ISBN różne |
| **Wydanie** | licencję kupuje się zwykle na wydanie, nie na tytuł (patrz § 7) |
| **Zasób** | to, co się serwuje: plik, rozdział, link; drzewo przez `parent_id` |

### 1.2 Schemat (SQLite)

SQLite nie ma ENUM ani JSON jako typu — stąd `TEXT` z `CHECK` i `TEXT` z
`json_encode()`. `PRAGMA foreign_keys=ON` ustawia już `db()`.

```sql
CREATE TABLE IF NOT EXISTS k30_ext_publishers (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    name          TEXT    NOT NULL,
    slug          TEXT    NOT NULL UNIQUE,
    contract_no   TEXT    NOT NULL DEFAULT '',
    contract_from DATE,
    contract_to   DATE,
    -- reguły dziedziczone przez tytuły tego wydawcy (JSON)
    default_rules TEXT    NOT NULL DEFAULT '{}',
    is_active     INTEGER NOT NULL DEFAULT 1,
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS k30_ext_categories (
    id        INTEGER PRIMARY KEY AUTOINCREMENT,
    parent_id INTEGER REFERENCES k30_ext_categories(id) ON DELETE CASCADE,
    name      TEXT    NOT NULL,
    -- ścieżka materializowana '/1/14/57/' — dziedziczenie uprawnień jednym LIKE,
    -- bez rekurencji (SQLite ma CTE, ale to zapytanie leci przy każdej liście)
    path      TEXT    NOT NULL DEFAULT '',
    depth     INTEGER NOT NULL DEFAULT 0,
    position  INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS ix_ext_cat_path ON k30_ext_categories(path);

CREATE TABLE IF NOT EXISTS k30_ext_titles (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    publisher_id INTEGER NOT NULL REFERENCES k30_ext_publishers(id) ON DELETE CASCADE,
    title        TEXT    NOT NULL,
    subtitle     TEXT    NOT NULL DEFAULT '',
    authors      TEXT    NOT NULL DEFAULT '',   -- „Kowalski J., Nowak A."
    isbn         TEXT    NOT NULL DEFAULT '',
    lang         TEXT    NOT NULL DEFAULT 'pl',
    year         INTEGER,
    description  TEXT    NOT NULL DEFAULT '',
    cover_path   TEXT    NOT NULL DEFAULT '',
    -- ── prawa i dostęp ─────────────────────────────────────────────────
    access_level TEXT    NOT NULL DEFAULT 'licensed'
                 CHECK (access_level IN ('public','registered','licensed','restricted')),
    is_copyrighted   INTEGER NOT NULL DEFAULT 1,
    allow_download   INTEGER NOT NULL DEFAULT 0,
    allow_print      INTEGER NOT NULL DEFAULT 0,
    watermark_policy TEXT    NOT NULL DEFAULT 'both'
                     CHECK (watermark_policy IN ('none','footer','overlay','both')),
    embargo_until    DATETIME,
    rights_note      TEXT    NOT NULL DEFAULT '',   -- co dokładnie wolno; cytat z umowy
    is_active    INTEGER NOT NULL DEFAULT 1,
    created_by   INTEGER REFERENCES users(id) ON DELETE SET NULL,
    created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS ix_ext_titles_pub ON k30_ext_titles(publisher_id, is_active);

CREATE TABLE IF NOT EXISTS k30_ext_category_title (
    category_id INTEGER NOT NULL REFERENCES k30_ext_categories(id) ON DELETE CASCADE,
    title_id    INTEGER NOT NULL REFERENCES k30_ext_titles(id)     ON DELETE CASCADE,
    PRIMARY KEY (category_id, title_id)
);

CREATE TABLE IF NOT EXISTS k30_ext_resources (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title_id   INTEGER NOT NULL REFERENCES k30_ext_titles(id) ON DELETE CASCADE,
    parent_id  INTEGER REFERENCES k30_ext_resources(id) ON DELETE CASCADE,
    kind       TEXT    NOT NULL DEFAULT 'file' CHECK (kind IN ('file','chapter','link')),
    name       TEXT    NOT NULL,
    position   INTEGER NOT NULL DEFAULT 0,
    -- magazyn adresowany treścią: nazwa pliku = sha256, oryginał tylko do pobrania
    checksum   TEXT    NOT NULL DEFAULT '',
    orig_name  TEXT    NOT NULL DEFAULT '',
    mime       TEXT    NOT NULL DEFAULT '',
    size_bytes INTEGER NOT NULL DEFAULT 0,
    pages      INTEGER,
    url        TEXT    NOT NULL DEFAULT '',      -- gdy kind='link'
    rules      TEXT    NOT NULL DEFAULT '{}',    -- nadpisanie reguł tytułu (JSON)
    is_active  INTEGER NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS ix_ext_res_title ON k30_ext_resources(title_id, parent_id, position);
CREATE INDEX IF NOT EXISTS ix_ext_res_sum   ON k30_ext_resources(checksum);
```

### 1.3 Uprawnienia — jedna tabela na wszystkie poziomy

Uprawnienie musi dać się nadać na dowolnym poziomie („cała kategoria Prawo",
„wszystko od WoltersKluwer", „ten jeden rozdział"), więc zamiast pięciu tabel
jedna, z parą kolumn typ/id — odpowiednik polimorfizmu:

```sql
CREATE TABLE IF NOT EXISTS k30_ext_grants (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    -- na czym: category | publisher | title | resource
    scope_type     TEXT    NOT NULL,
    scope_id       INTEGER NOT NULL,
    -- dla kogo: user | role | group (grupa TI) | student (konto kursanta) | all
    subject_type   TEXT    NOT NULL,
    subject_id     INTEGER NOT NULL DEFAULT 0,
    effect         TEXT    NOT NULL DEFAULT 'allow' CHECK (effect IN ('allow','deny')),
    abilities      TEXT    NOT NULL DEFAULT 'view,stream',  -- view|stream|download|print
    starts_at      DATETIME,
    ends_at        DATETIME,
    license_id     INTEGER REFERENCES k30_ext_licenses(id) ON DELETE SET NULL,
    note           TEXT    NOT NULL DEFAULT '',
    created_by     INTEGER,
    created_at     DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS ix_ext_grants_subj  ON k30_ext_grants(subject_type, subject_id, effect);
CREATE INDEX IF NOT EXISTS ix_ext_grants_scope ON k30_ext_grants(scope_type, scope_id);

CREATE TABLE IF NOT EXISTS k30_ext_licenses (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    publisher_id  INTEGER NOT NULL REFERENCES k30_ext_publishers(id) ON DELETE CASCADE,
    kind          TEXT    NOT NULL DEFAULT 'institutional'
                  CHECK (kind IN ('institutional','group','individual','trial')),
    name          TEXT    NOT NULL,
    valid_from    DATE    NOT NULL,
    valid_to      DATE    NOT NULL,
    seats         INTEGER,                 -- NULL = bez limitu osób
    max_downloads INTEGER,                 -- na osobę, na dobę
    scope_json    TEXT    NOT NULL DEFAULT '{}',   -- zawężenie: kategorie/tytuły
    contract_file TEXT    NOT NULL DEFAULT '',     -- skan umowy (ten sam magazyn)
    is_active     INTEGER NOT NULL DEFAULT 1,
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS k30_ext_license_seats (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    license_id  INTEGER NOT NULL REFERENCES k30_ext_licenses(id) ON DELETE CASCADE,
    subject_type TEXT   NOT NULL,          -- user | student
    subject_id  INTEGER NOT NULL,
    assigned_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    released_at DATETIME
);
CREATE UNIQUE INDEX IF NOT EXISTS ux_ext_seat
    ON k30_ext_license_seats(license_id, subject_type, subject_id)
    WHERE released_at IS NULL;

CREATE TABLE IF NOT EXISTS k30_ext_tickets (
    id           TEXT    PRIMARY KEY,      -- 32 hex, losowe
    resource_id  INTEGER NOT NULL REFERENCES k30_ext_resources(id) ON DELETE CASCADE,
    subject_type TEXT    NOT NULL,
    subject_id   INTEGER NOT NULL,
    ability      TEXT    NOT NULL,
    ip           TEXT    NOT NULL DEFAULT '',
    ua_hash      TEXT    NOT NULL DEFAULT '',
    license_id   INTEGER,
    expires_at   DATETIME NOT NULL,
    used_at      DATETIME,
    created_at   DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS ix_ext_tickets_exp ON k30_ext_tickets(expires_at);

CREATE TABLE IF NOT EXISTS k30_ext_access_log (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    subject_type TEXT    NOT NULL,
    subject_id   INTEGER NOT NULL,
    subject_name TEXT    NOT NULL DEFAULT '',   -- kopia nazwy: log ma przeżyć kasowanie konta
    resource_id  INTEGER,
    title_id     INTEGER,
    ability      TEXT    NOT NULL,
    decision     TEXT    NOT NULL,              -- granted | denied | served
    reason       TEXT    NOT NULL DEFAULT '',
    ticket_id    TEXT    NOT NULL DEFAULT '',
    ip           TEXT    NOT NULL DEFAULT '',
    created_at   DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS ix_ext_log_subj ON k30_ext_access_log(subject_type, subject_id, created_at);
CREATE INDEX IF NOT EXISTS ix_ext_log_res  ON k30_ext_access_log(resource_id, created_at);
```

### 1.4 Samonaprawa schematu

Wzorzec jak w `includes/karty30.php` i `includes/letters_schema.php` — jedna funkcja
wołana na wejściu każdej strony modułu. Dzięki temu wdrożenie to `git pull`, bez
osobnego kroku migracji:

```php
function ext_migrate(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    $pdo = db();
    foreach (ext_schema_sql() as $sql) {          // CREATE TABLE / CREATE INDEX
        $pdo->exec($sql);
    }
    // Kolumny dokładane później — każda w try/catch, bo SQLite nie zna
    // ADD COLUMN IF NOT EXISTS, a powtórka rzuca wyjątkiem.
    foreach ([
        "ALTER TABLE k30_ext_titles ADD COLUMN territory TEXT NOT NULL DEFAULT ''",
        "ALTER TABLE k30_ext_resources ADD COLUMN ocr_done INTEGER NOT NULL DEFAULT 0",
    ] as $sql) {
        try { $pdo->exec($sql); } catch (\Throwable $e) { /* kolumna już jest */ }
    }
}
```

---

## 2. Upload książek — ścieżka podstawowa

Materiały wgrywa pracownik (uprawnienie zapisu w module) — import z API wydawcy
to przypadek rzadki i wchodzi dopiero w etapie 4 (§ 8).

### 2.1 Magazyn adresowany treścią

```
UPLOAD_DIR/ext/<sha256[0:2]>/<sha256>          ← plik, bez rozszerzenia
UPLOAD_DIR/ext/wm/<resource>/<subject>/<v>.pdf ← wersje ze znakiem wodnym (cache)
```

Trzy powody, żeby nazwą pliku był skrót jego treści:

* nazwa od użytkownika **nigdy** nie staje się ścieżką — `../../` i `x.php` przestają być tematem,
* ten sam plik wgrany dwa razy zajmuje miejsce raz (dedup po `checksum`),
* skrót jest jednocześnie dowodem integralności przy weryfikacji zbioru.

Oryginalna nazwa idzie do `orig_name` i wraca dopiero w nagłówku
`Content-Disposition` przy pobraniu.

### 2.2 Duże pliki: upload w kawałkach

Książki mają 50–500 MB, a `upload_max_filesize`/`post_max_size`/`max_execution_time`
zwykle na to nie pozwalają — i nie warto ich podnosić globalnie dla całego SZO.
Przeglądarka tnie plik na kawałki po 4 MB i dosyła je po kolei; serwer dokleja
je do pliku tymczasowego:

```php
// karty30/ti/ext/upload.php — fragment: przyjęcie kawałka
$uid   = ext_require_writer();                      // sesja SZO albo panel kierownika
csrf_check();

$sid   = preg_replace('/[^a-f0-9]/', '', (string)($_POST['sid'] ?? ''));   // id sesji uploadu
$idx   = (int)($_POST['idx'] ?? 0);
$total = (int)($_POST['total'] ?? 0);
if ($sid === '' || !isset($_FILES['chunk']) || $_FILES['chunk']['error'] !== UPLOAD_ERR_OK) {
    ext_json(['ok' => false, 'msg' => 'Błąd przesyłania kawałka.'], 400);
}

$tmp = rtrim(UPLOAD_DIR, '/') . '/ext/tmp/' . $sid . '.part';
@mkdir(dirname($tmp), 0770, true);

// Kawałki muszą przyjść po kolei — inaczej doklejenie zbuduje śmieci.
// Rozmiar pliku tymczasowego mówi, którego kawałka oczekujemy.
$have = is_file($tmp) ? filesize($tmp) : 0;
if ($idx * EXT_CHUNK_BYTES !== $have) {
    ext_json(['ok' => false, 'expect' => intdiv($have, EXT_CHUNK_BYTES)], 409);
}

$in  = fopen($_FILES['chunk']['tmp_name'], 'rb');
$out = fopen($tmp, 'ab');
stream_copy_to_stream($in, $out);                   // bez wczytywania do pamięci
fclose($in); fclose($out);

if ($idx + 1 < $total) { ext_json(['ok' => true, 'next' => $idx + 1]); }

// Ostatni kawałek — walidacja i wpisanie do magazynu
$res = ext_store_upload($tmp, (string)($_POST['name'] ?? 'plik'), $uid);
ext_json($res, $res['ok'] ? 200 : 422);
```

### 2.3 Walidacja i wpisanie do magazynu

```php
const EXT_CHUNK_BYTES = 4 * 1024 * 1024;
const EXT_MAX_BYTES   = 800 * 1024 * 1024;

/** Dozwolone typy — rozstrzyga zawartość pliku, nie rozszerzenie i nie $_FILES['type']. */
function ext_allowed_mimes(): array
{
    return [
        'application/pdf'  => 'pdf',
        'application/epub+zip' => 'epub',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    ];
}

/**
 * Przenosi plik tymczasowy do magazynu adresowanego treścią.
 * Zwraca ['ok'=>bool,'checksum'=>…,'mime'=>…,'size'=>…,'pages'=>…,'dup'=>bool].
 */
function ext_store_upload(string $tmp, string $origName, int $byUserId): array
{
    if (!is_file($tmp)) return ['ok' => false, 'msg' => 'Brak pliku.'];

    $size = filesize($tmp);
    if ($size <= 0 || $size > EXT_MAX_BYTES) {
        @unlink($tmp);
        return ['ok' => false, 'msg' => 'Plik jest pusty albo za duży (limit '
            . round(EXT_MAX_BYTES / 1048576) . ' MB).'];
    }

    // finfo czyta magiczne bajty — deklaracja przeglądarki nie ma tu nic do rzeczy
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp) ?: '';
    if (!isset(ext_allowed_mimes()[$mime])) {
        @unlink($tmp);
        return ['ok' => false, 'msg' => 'Niedozwolony typ pliku (' . $mime . ').'];
    }

    $sum  = hash_file('sha256', $tmp);
    $dir  = rtrim(UPLOAD_DIR, '/') . '/ext/' . substr($sum, 0, 2);
    $dest = $dir . '/' . $sum;

    if (is_file($dest)) {                 // ten sam plik już jest — dedup
        @unlink($tmp);
        return ['ok' => true, 'checksum' => $sum, 'mime' => $mime, 'size' => filesize($dest),
                'pages' => ext_pdf_pages($dest), 'dup' => true];
    }

    @mkdir($dir, 0770, true);
    if (!rename($tmp, $dest)) {           // rename w obrębie tego samego wolumenu = atomowe
        @unlink($tmp);
        return ['ok' => false, 'msg' => 'Nie udało się zapisać pliku w magazynie.'];
    }
    @chmod($dest, 0640);

    ext_log_admin($byUserId, 'upload', $sum, $origName);

    return ['ok' => true, 'checksum' => $sum, 'mime' => $mime, 'size' => $size,
            'pages' => ext_pdf_pages($dest), 'dup' => false];
}

/**
 * Liczba stron PDF-a. Liczymy przez mPDF (i tak jest w projekcie), bo szukanie
 * `/Type /Page` wyrażeniem regularnym zaniża wynik w plikach ze strumieniami
 * obiektów — a takie są dziś prawie wszystkie książki z wydawnictw.
 * Zwraca null, gdy pliku nie da się otworzyć — liczba stron jest metadaną,
 * jej brak nie może wywrócić wgrywania.
 */
function ext_pdf_pages(string $path): ?int
{
    try {
        $mpdf  = new \Mpdf\Mpdf(['tempDir' => sys_get_temp_dir() . '/mpdf']);
        $pages = $mpdf->SetSourceFile($path);
        return $pages ?: null;
    } catch (\Throwable $e) {
        error_log('[ext_pdf_pages] ' . $e->getMessage());
        return null;
    }
}
```

**Druga bariera na wypadek błędnej konfiguracji serwera.** Katalog `uploads/`
w tym wdrożeniu leży poza webrootem, ale to założenie warto podeprzeć plikiem
`UPLOAD_DIR/ext/.htaccess` z `Require all denied` — jeśli kiedyś ktoś przeniesie
katalog, moduł nie zacznie przez to rozdawać książek.

---

## 3. Wielopoziomowy dostęp

### 3.1 Trzy sesje, jeden podmiot

SZO ma trzy niezależne sesje: aplikacja (`current_user()`), panel kursanta
(`student_current()`) i panel prowadzącego (`dyd_current()`). Moduł ma działać we
wszystkich, więc serwis nie zgaduje, kto pyta — dostaje podmiot:

```php
/** Ujednolicony podmiot uprawnień: ['type'=>'user|student','id'=>int,'name'=>string,'roles'=>[]]. */
function ext_subject(): ?array
{
    if (function_exists('dyd_current') && ($d = dyd_current())) {
        return ['type' => 'user', 'id' => (int)$d['user_id'], 'name' => $d['name'],
                'roles' => [$d['role'] ?? ''], 'staff' => !empty($d['is_staff'])];
    }
    if (function_exists('student_current') && ($s = student_current())) {
        return ['type' => 'student', 'id' => (int)$s['id'], 'name' => $s['name'] ?? '',
                'roles' => [], 'staff' => false];
    }
    if (function_exists('current_user') && ($u = current_user())) {
        return ['type' => 'user', 'id' => (int)$u['id'], 'name' => $u['name'],
                'roles' => [$u['role'] ?? ''], 'staff' => can_write('karty30') || is_admin()];
    }
    return null;
}
```

> **Pułapka, którą już znamy z panelu kierownika:** w panelach `current_user()`
> i `is_admin()` patrzą na sesję SZO, której tam nie ma — zawsze puste. Kolejność
> sprawdzania powyżej jest istotna, a funkcje decydujące przyjmują `$subject`
> w argumencie i nigdy go sobie nie dobierają same.

### 3.2 Pięć warstw, odmowa wygrywa

| # | Warstwa | Odmowa znaczy |
|---|---|---|
| 1 | moduł włączony (`ext_enabled`) i podmiot zalogowany | 403 / logowanie |
| 2 | zakaz jawny (`deny` na dowolnym poziomie) | 403, bez dalszych pytań |
| 3 | poziom dostępu tytułu + grant albo licencja | „wykup dostęp" |
| 4 | reguły zasobu: embargo, terytorium, druk, pobranie | dostęp częściowy |
| 5 | limity: pobrania na dobę, równoległe odczyty | 429 |

Decyzja nie jest wartością logiczną. Odpowiedź brzmi „tak, ale strumieniowo,
ze znakiem wodnym, bez druku, przez 180 sekund" — więc funkcja zwraca tablicę
z **ograniczeniami**, a nie `true`:

```php
/**
 * Decyzja o dostępie. Zwraca:
 *   ['ok'=>bool,'reason'=>string,'abilities'=>[],'watermark'=>'none|footer|overlay|both',
 *    'ttl'=>int,'license_id'=>?int]
 * `reason` jest kodem — trafia do logu i do komunikatu dla użytkownika.
 */
function ext_decide(array $subject, array $resource, string $ability): array
{
    $deny = fn(string $r) => ['ok' => false, 'reason' => $r, 'abilities' => []];

    if (org_setting('ext_enabled') !== '1')            return $deny('module_off');

    $title = db_one("SELECT t.*, p.default_rules
                     FROM k30_ext_titles t
                     JOIN k30_ext_publishers p ON p.id = t.publisher_id
                     WHERE t.id=? AND t.is_active=1", [(int)$resource['title_id']]);
    if (!$title)                                        return $deny('not_found');

    // Reguły składamy w jednej kolejności: wydawca → tytuł → zasób
    $rules = ext_merge_rules(
        json_decode((string)$title['default_rules'], true) ?: [],
        $title,
        json_decode((string)$resource['rules'], true) ?: []
    );

    if (!empty($title['embargo_until']) && $title['embargo_until'] > date('Y-m-d H:i:s')) {
        return $deny('embargo');
    }

    // Zakaz jawny na dowolnym poziomie kończy sprawę — także dla pracownika
    $chain = ext_grants_for($subject, $title, $resource);
    if (ext_chain_has($chain, 'deny', $ability))        return $deny('explicit_deny');

    // Pracownik z prawem zapisu widzi wszystko, co nie jest jawnie zabronione —
    // inaczej nie dałoby się modułem administrować. Zapisujemy to w logu osobno.
    $license = null;
    if (empty($subject['staff'])) {
        if ($title['access_level'] !== 'public' && !ext_chain_has($chain, 'allow', $ability)) {
            $license = ext_license_for($subject, (int)$title['id']);
            if (!$license)                              return $deny('no_license');
            if (!ext_license_has_seat($license, $subject)) return $deny('no_seat');
        }
    }

    if ($ability === 'download' && empty($rules['allow_download'])) return $deny('download_forbidden');
    if ($ability === 'print'    && empty($rules['allow_print']))    return $deny('print_forbidden');

    if (!ext_limits_ok($subject, $resource, $ability, $license)) return $deny('limit_exceeded');

    return [
        'ok'         => true,
        'reason'     => 'ok',
        'abilities'  => $rules['abilities'],
        'watermark'  => empty($subject['staff']) ? $rules['watermark_policy'] : 'footer',
        'ttl'        => (int)($rules['ticket_ttl'] ?? 180),
        'license_id' => $license['id'] ?? null,
    ];
}
```

### 3.3 Dziedziczenie w drzewie kategorii

Grant nadany na kategorii ma obejmować wszystko poniżej. Po to jest `path`:
zamiast rekurencji jedno porównanie prefiksu.

```php
function ext_grants_for(array $subject, array $title, array $resource): array
{
    // Podmioty, na które może być wystawiony grant: konkretna osoba, jej rola,
    // grupy TI, w których jest, oraz „wszyscy zalogowani".
    $subjects = ext_subject_keys($subject);   // ['user:7','role:editor','group:11','all:0']
    $inSubj   = implode(',', array_fill(0, count($subjects), '?'));

    return db_all(
        "SELECT g.* FROM k30_ext_grants g
         WHERE (g.subject_type || ':' || g.subject_id) IN ($inSubj)
           AND (g.starts_at IS NULL OR g.starts_at <= datetime('now'))
           AND (g.ends_at   IS NULL OR g.ends_at   >= datetime('now'))
           AND (
                (g.scope_type='resource'  AND g.scope_id = CAST(? AS INTEGER))
             OR (g.scope_type='title'     AND g.scope_id = CAST(? AS INTEGER))
             OR (g.scope_type='publisher' AND g.scope_id = CAST(? AS INTEGER))
             OR (g.scope_type='category'  AND g.scope_id IN (
                    -- kategorie tytułu i wszyscy ich przodkowie
                    SELECT c.id FROM k30_ext_categories c
                    JOIN k30_ext_category_title ct ON ct.category_id = c.id
                    WHERE ct.title_id = CAST(? AS INTEGER)
                    UNION
                    SELECT a.id FROM k30_ext_categories a
                    JOIN k30_ext_categories c2 ON c2.path LIKE a.path || '%'
                    JOIN k30_ext_category_title ct2 ON ct2.category_id = c2.id
                    WHERE ct2.title_id = CAST(? AS INTEGER)
                 ))
           )",
        array_merge($subjects, [(int)$resource['id'], (int)$title['id'],
                                (int)$title['publisher_id'], (int)$title['id'], (int)$title['id']])
    );
}
```

> **Pułapka SQLite (znana z protokołów):** przy porównaniu wyrażenia z parametrem
> PDO wiąże go jako TEKST i warunek nie trafia — stąd `CAST(? AS INTEGER)`
> wszędzie tam, gdzie po lewej stronie nie stoi goła kolumna INTEGER.

### 3.4 Lista katalogu — jedno zapytanie, nie pętla

Sprawdzanie uprawnień per rekord przy 5 000 tytułów zabije stronę. Katalog zawęża SQL:

```php
function ext_titles_visible(array $subject, array $filter = [], int $limit = 50, int $offset = 0): array
{
    $subjects = ext_subject_keys($subject);
    $in       = implode(',', array_fill(0, count($subjects), '?'));
    $params   = $subjects;

    $sql = "SELECT t.*, p.name AS publisher_name
            FROM k30_ext_titles t
            JOIN k30_ext_publishers p ON p.id = t.publisher_id
            WHERE t.is_active = 1
              AND (
                   t.access_level = 'public'
                OR EXISTS (SELECT 1 FROM k30_ext_grants g
                           WHERE (g.subject_type || ':' || g.subject_id) IN ($in)
                             AND g.effect='allow'
                             AND ((g.scope_type='title' AND g.scope_id=t.id)
                               OR (g.scope_type='publisher' AND g.scope_id=t.publisher_id))
                             AND (g.ends_at IS NULL OR g.ends_at >= datetime('now')))
              )";
    // … filtry (kategoria, wydawca, fraza) dokładane warunkami i parametrami …
    return db_all($sql . " ORDER BY t.title COLLATE NOCASE LIMIT $limit OFFSET $offset", $params);
}
```

Metadane (okładka, opis, autor) pokazujemy szerzej niż treść — to jest sens
poziomu `registered`: katalog widzi każdy zalogowany, plik już nie. Przy każdej
pozycji zwracamy **powód odmowy**, nie samo wyszarzenie: użytkownik ma przeczytać
„licencja wygasła 30.06", a nie dzwonić z pytaniem, czemu przycisk nie działa.

---

## 4. Serwowanie plików

### 4.1 Bilet

```php
function ext_ticket_issue(array $subject, array $resource, string $ability): array
{
    $d = ext_decide($subject, $resource, $ability);
    ext_log($subject, $resource, $ability, $d['ok'] ? 'granted' : 'denied', $d['reason']);
    if (!$d['ok']) return ['ok' => false, 'reason' => $d['reason']];

    $id = bin2hex(random_bytes(16));
    db_insert('k30_ext_tickets', [
        'id'           => $id,
        'resource_id'  => (int)$resource['id'],
        'subject_type' => $subject['type'],
        'subject_id'   => (int)$subject['id'],
        'ability'      => $ability,
        'ip'           => $_SERVER['REMOTE_ADDR'] ?? '',
        'ua_hash'      => hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? ''),
        'license_id'   => $d['license_id'],
        'expires_at'   => date('Y-m-d H:i:s', time() + $d['ttl']),
    ]);

    return ['ok' => true, 'url' => 'file.php?t=' . $id, 'ttl' => $d['ttl']];
}
```

### 4.2 Strumień z obsługą zakresów

`readfile()` na 300-megabajtowym PDF-ie zje pamięć i nie pozwoli czytnikowi skakać
po stronach. Czytniki PDF proszą o fragmenty nagłówkiem `Range`, więc trzeba go obsłużyć:

```php
// karty30/ti/ext/file.php
$t = db_one("SELECT * FROM k30_ext_tickets WHERE id=?", [preg_replace('/[^a-f0-9]/','', $_GET['t'] ?? '')]);
if (!$t)                                     ext_fail(404, 'Nieprawidłowy link.');
if ($t['expires_at'] < date('Y-m-d H:i:s'))  ext_fail(410, 'Link wygasł — otwórz materiał ponownie.');
if ($t['ability'] === 'download' && $t['used_at']) ext_fail(410, 'Link został już użyty.');
if (!hash_equals($t['ua_hash'], hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? '')))
                                             ext_fail(403, 'Link wystawiono dla innej przeglądarki.');

$res  = db_one("SELECT * FROM k30_ext_resources WHERE id=? AND is_active=1", [(int)$t['resource_id']]);
$path = ext_prepare_file($res, $t);          // ← tu wchodzi znak wodny (§ 4.3)

$size  = filesize($path);
$start = 0; $end = $size - 1;
if (preg_match('/bytes=(\d+)-(\d*)/', $_SERVER['HTTP_RANGE'] ?? '', $m)) {
    $start = (int)$m[1];
    if ($m[2] !== '') $end = min((int)$m[2], $size - 1);
    http_response_code(206);
    header("Content-Range: bytes $start-$end/$size");
}

while (ob_get_level()) ob_end_clean();       // bufor PHP nie ma trzymać 300 MB
header('Content-Type: ' . ($res['mime'] ?: 'application/octet-stream'));
header('Content-Length: ' . ($end - $start + 1));
header('Accept-Ranges: bytes');
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: ' . ($t['ability'] === 'download'
    ? 'attachment; filename="' . preg_replace('/[\r\n"]+/', '', $res['orig_name']) . '"'
    : 'inline'));

$fh = fopen($path, 'rb');
fseek($fh, $start);
$left = $end - $start + 1;
while ($left > 0 && !feof($fh)) {
    $chunk = fread($fh, min(8192, $left));
    echo $chunk;
    $left -= strlen($chunk);
    flush();
}
fclose($fh);

db_exec("UPDATE k30_ext_tickets SET used_at=datetime('now') WHERE id=?", [$t['id']]);
ext_log_ticket($t, 'served');
```

### 4.3 Znak wodny (mPDF 8.3 — jest w `composer.json`)

Wersje ze znakiem wodnym trzymamy w cache, bo stemplowanie 400-stronicowej książki
trwa kilkanaście sekund. Klucz cache zawiera **wersję polityki** — zmiana reguł
unieważnia stare pliki bez ręcznego czyszczenia:

```php
function ext_prepare_file(array $res, array $ticket): string
{
    $src = ext_storage_path($res['checksum']);
    $pol = ext_watermark_policy($res, $ticket);
    if ($pol === 'none' || $res['mime'] !== 'application/pdf') return $src;

    $key = sprintf('%s/ext/wm/%d/%s-%d/%s.pdf', rtrim(UPLOAD_DIR, '/'),
        (int)$res['id'], $ticket['subject_type'], (int)$ticket['subject_id'], ext_policy_version($pol));

    if (is_file($key)) return $key;

    // Duże pliki stempluje agent w tle — strona nie może wisieć 30 sekund
    if ($res['size_bytes'] > 20 * 1024 * 1024) {
        ext_queue_watermark((int)$res['id'], $ticket, $key);
        ext_fail(202, 'Dokument jest przygotowywany. Odśwież stronę za chwilę.');
    }

    @mkdir(dirname($key), 0770, true);
    ext_watermark_pdf($src, $key, ext_watermark_text($ticket), $pol);
    return $key;
}

function ext_watermark_pdf(string $src, string $dst, string $mark, string $policy): void
{
    $mpdf = new \Mpdf\Mpdf(['tempDir' => sys_get_temp_dir() . '/mpdf']);
    $pages = $mpdf->SetSourceFile($src);           // w mPDF 8 SetImportUse() nie jest już potrzebne

    if ($policy === 'overlay' || $policy === 'both') {
        $mpdf->SetWatermarkText($mark);
        $mpdf->showWatermarkText  = true;
        $mpdf->watermarkTextAlpha = 0.10;          // czytelne, ale nie zasłania treści
    }
    // Ślad forensyczny: podpisany identyfikator biletu w metadanych —
    // po wycieku wskazuje konkretne wydanie pliku konkretnej osobie.
    $mpdf->SetCreator('SZO/ext:' . hash_hmac('sha256', $mark, APP_KEY));

    for ($p = 1; $p <= $pages; $p++) {
        $tpl  = $mpdf->ImportPage($p);
        $size = $mpdf->getTemplateSize($tpl);
        $mpdf->AddPageByArray(['orientation' => $size['width'] > $size['height'] ? 'L' : 'P']);
        $mpdf->UseTemplate($tpl);
        if ($policy === 'footer' || $policy === 'both') {
            $mpdf->SetHTMLFooter('<div style="font-size:7pt;color:#666">' . h($mark) . '</div>');
        }
    }
    $mpdf->Output($dst, \Mpdf\Output\Destination::FILE);
}

function ext_watermark_text(array $ticket): string
{
    $s = ext_subject_name($ticket['subject_type'], (int)$ticket['subject_id']);
    return sprintf('%s · %s · bilet %s', $s, date('Y-m-d H:i'), substr($ticket['id'], 0, 8));
}
```

---

## 5. Strony i przepływ

```
karty30/ti/ext/
├── index.php      katalog: drzewo kategorii + lista tytułów (ext_titles_visible)
├── title.php      karta tytułu: metadane, spis zasobów, powody odmowy
├── read.php       czytnik: <iframe src="file.php?t=…"> + blokada druku w CSP
├── file.php       strumień po bilecie (§ 4.2)
├── upload.php     wgrywanie w kawałkach + metadane (pracownik)
├── admin.php      wydawcy, kategorie, licencje, granty, log dostępu
└── _tabs.php      wspólna nawigacja modułu
```

Przepływ jednego odczytu:

```
title.php            → ext_titles_visible / ext_decide per zasób (powód odmowy w UI)
POST ticket.php      → ext_decide (5 warstw) → k30_ext_tickets → file.php?t=…
GET  file.php?t=…    → weryfikacja: ważność, jednorazowość, UA
                     → ext_prepare_file (znak wodny z cache; >20 MB → agent, 202)
                     → strumień 8 KB z obsługą Range
                     → k30_ext_access_log (served)
```

**API** (`api/v1/ext.php`, Bearer + zakresy `ext:read` / `ext:write`, wzorem
`api/v1/karty30.php`): `GET /titles`, `GET /titles/{id}`, `POST /resources/{id}/ticket`,
`POST /uploads` (kawałki), `GET /log`. Zapisu treści przez API nie ma bez sesji —
wgrywanie książek to czynność człowieka z uprawnieniem, nie integracji.

**Agent** w `cron/dispatcher.php`:

```php
'ext_agent' => [
    'file'     => __DIR__ . '/ext_agent.php',
    'interval' => 120,        // co 2 min
],
```
Robi trzy rzeczy: stempluje pliki z kolejki (`k30_ext_wm_queue`), kasuje bilety
i wygasłe wersje z cache, raz na dobę raportuje licencje kończące się w ciągu
30 dni (zadanie dla opiekuna umowy).

---

## 6. Pułapki

**SQLite**

* brak ENUM → `TEXT` + `CHECK`; brak `ADD COLUMN IF NOT EXISTS` → ALTER w `try/catch`,
* `COALESCE(x,0)=?` i wyrażenia po lewej stronie nie trafiają — parametr wiąże się
  jako TEKST, potrzebny `CAST(? AS INTEGER)`,
* stemplowanie PDF-a **poza transakcją** — kilkanaście sekund z otwartym zapisem
  zablokuje bazę mimo WAL,
* `LIKE path || '%'` po indeksowanej kolumnie `path` zastępuje rekurencję.

**PHP i pliki**

* nie ufać `$_FILES['type']` ani rozszerzeniu — rozstrzyga `finfo` na zawartości,
* nazwa od użytkownika nigdy jako ścieżka; magazyn adresowany treścią to załatwia,
* `while (ob_get_level()) ob_end_clean()` przed strumieniem, inaczej bufor rośnie
  do rozmiaru pliku,
* `Content-Disposition: attachment` tylko gdy `allow_download` — inaczej `inline`,
* przy `X-Sendfile`/`X-Accel-Redirect` **nie da się** nałożyć znaku wodnego w locie;
  albo stempel z cache, albo strumień przez PHP.

**Ten projekt**

* trzy sesje: funkcje decydujące przyjmują `$subject` w argumencie (§ 3.1),
* `csrf_check()` i `flash_*` działają we wszystkich sesjach — `auth_start()` nie
  rusza już otwartej sesji panelu,
* polski cudzysłów w kodzie: `„tekst”` z ASCII `"` rozbija atrybut i string.

---

## 7. Decyzja do podjęcia przed kodowaniem

**Czy licencja wisi na tytule, czy na wydaniu.** Wydawnictwa sprzedają zwykle
wydanie (nowa edycja = nowy zakup), a katalogi pokazują tytuł. Schemat powyżej
zakłada tytuł — prostsze i wystarczające, gdy umowy są zbiorcze. Jeśli umowy
wskazują konkretne wydania, trzeba dołożyć `k30_ext_editions` między tytułem
a zasobem i przenieść tam pola praw. Zmiana po wdrożeniu jest kosztowna, bo
dotyka grantów, licencji i całego katalogu — dlatego rozstrzygamy to na początku,
patrząc w podpisane umowy.

---

## 8. Kolejność wdrożenia

| Etap | Zakres | Efekt |
|---|---|---|
| 1 | schemat, wydawcy, kategorie, tytuły, upload w kawałkach, katalog dla pracownika | książki w systemie |
| 2 | granty, `ext_decide()`, bilety, strumień z Range, log | kontrolowany dostęp |
| 3 | znak wodny + cache + agent, czytnik, limity | ochrona i ślad |
| 4 | licencje z miejscami, raport wygasających, API, import od wydawcy | obsługa umów |

Po etapie 2 moduł jest już używalny; etapy 3–4 dokładają to, czego wymagają
konkretne umowy z wydawcami.
