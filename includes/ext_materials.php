<?php
/**
 * includes/ext_materials.php — Materiały zewnętrzne: schemat i domena.
 *
 * Moduł obsługuje zewnętrzne zasoby edukacyjne: książki od wydawnictw, e-booki,
 * dokumenty licencjonowane i materiały prawnie chronione. Książki wgrywa
 * pracownik — import z API wydawcy jest przypadkiem rzadkim i dokłada się później
 * (tabele `external_id` i `checksum` są już pod to przygotowane).
 *
 * Hierarchia: wydawca ─┐
 *             kategoria ┴→ tytuł → wydanie → zasób (plik/rozdział/link)
 *
 * WYDANIE jest osobnym poziomem, bo wydawnictwa sprzedają licencje na konkretną
 * edycję (nowe wydanie = nowy zakup), a katalog pokazuje tytuł. Uprawnienia
 * i licencje mogą jednak celować w dowolny poziom (patrz ext_access.php), więc
 * przy umowach zbiorczych wydanie po prostu zostaje jedno i nikomu nie przeszkadza.
 *
 * Prawa dziedziczą w dół i każdy niższy poziom może je ZAWĘZIĆ, nigdy poszerzyć:
 * wydawca → tytuł → wydanie → zasób (ext_effective_rules()).
 *
 * Autoryzacja i serwowanie plików: includes/ext_access.php.
 * Dokumentacja projektowa: docs/materialy-zewnetrzne.md.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

/** Kawałek uploadu — tyle wysyła przeglądarka w jednym żądaniu. */
const EXT_CHUNK_BYTES = 4 * 1024 * 1024;
/** Górny limit pojedynczego pliku (książki bywają duże, ale nie bez końca). */
const EXT_MAX_BYTES = 800 * 1024 * 1024;

/** Czy moduł jest włączony dla organizacji. */
function ext_enabled(): bool
{
    return org_setting('ext_enabled') === '1';
}

// ── Schemat ──────────────────────────────────────────────────────────────────

/**
 * Samonaprawa schematu — wołana na wejściu każdej strony modułu.
 * Wzorzec jak w karty30_migrate(): CREATE TABLE IF NOT EXISTS + ALTER-y
 * w try/catch, bo SQLite nie zna ADD COLUMN IF NOT EXISTS.
 */
function ext_migrate(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    $pdo = db();

    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_ext_publishers (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        name          TEXT    NOT NULL,
        slug          TEXT    NOT NULL,
        contract_no   TEXT    NOT NULL DEFAULT '',
        contract_from DATE,
        contract_to   DATE,
        contact       TEXT    NOT NULL DEFAULT '',
        default_rules TEXT    NOT NULL DEFAULT '{}',
        is_active     INTEGER NOT NULL DEFAULT 1,
        created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS ux_ext_pub_slug ON k30_ext_publishers(slug)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_ext_categories (
        id        INTEGER PRIMARY KEY AUTOINCREMENT,
        parent_id INTEGER REFERENCES k30_ext_categories(id) ON DELETE CASCADE,
        name      TEXT    NOT NULL,
        path      TEXT    NOT NULL DEFAULT '',
        depth     INTEGER NOT NULL DEFAULT 0,
        position  INTEGER NOT NULL DEFAULT 0
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS ix_ext_cat_path ON k30_ext_categories(path)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_ext_titles (
        id           INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
        publisher_id INTEGER NOT NULL REFERENCES k30_ext_publishers(id) ON DELETE CASCADE,
        title        TEXT    NOT NULL,
        subtitle     TEXT    NOT NULL DEFAULT '',
        authors      TEXT    NOT NULL DEFAULT '',
        lang         TEXT    NOT NULL DEFAULT 'pl',
        description  TEXT    NOT NULL DEFAULT '',
        external_id  TEXT    NOT NULL DEFAULT '',
        access_level TEXT    NOT NULL DEFAULT 'licensed',
        is_copyrighted   INTEGER NOT NULL DEFAULT 1,
        allow_download   INTEGER NOT NULL DEFAULT 0,
        allow_print      INTEGER NOT NULL DEFAULT 0,
        watermark_policy TEXT    NOT NULL DEFAULT 'both',
        embargo_until    DATETIME,
        rights_note      TEXT    NOT NULL DEFAULT '',
        is_active    INTEGER NOT NULL DEFAULT 1,
        created_by   INTEGER,
        created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at   DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS ix_ext_titles_pub ON k30_ext_titles(publisher_id, is_active)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_ext_editions (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        title_id   INTEGER NOT NULL REFERENCES k30_ext_titles(id) ON DELETE CASCADE,
        name       TEXT    NOT NULL DEFAULT 'wydanie podstawowe',
        edition_no TEXT    NOT NULL DEFAULT '',
        year       INTEGER,
        isbn       TEXT    NOT NULL DEFAULT '',
        -- puste = dziedziczy z tytułu; wypełnione = zawęża (patrz ext_effective_rules)
        rules      TEXT    NOT NULL DEFAULT '{}',
        is_active  INTEGER NOT NULL DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS ix_ext_ed_title ON k30_ext_editions(title_id, is_active)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_ext_category_title (
        category_id INTEGER NOT NULL REFERENCES k30_ext_categories(id) ON DELETE CASCADE,
        title_id    INTEGER NOT NULL REFERENCES k30_ext_titles(id) ON DELETE CASCADE,
        PRIMARY KEY (category_id, title_id)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS k30_ext_resources (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        edition_id INTEGER NOT NULL REFERENCES k30_ext_editions(id) ON DELETE CASCADE,
        parent_id  INTEGER REFERENCES k30_ext_resources(id) ON DELETE CASCADE,
        kind       TEXT    NOT NULL DEFAULT 'file',
        name       TEXT    NOT NULL,
        position   INTEGER NOT NULL DEFAULT 0,
        checksum   TEXT    NOT NULL DEFAULT '',
        orig_name  TEXT    NOT NULL DEFAULT '',
        mime       TEXT    NOT NULL DEFAULT '',
        size_bytes INTEGER NOT NULL DEFAULT 0,
        pages      INTEGER,
        url        TEXT    NOT NULL DEFAULT '',
        rules      TEXT    NOT NULL DEFAULT '{}',
        is_active  INTEGER NOT NULL DEFAULT 1,
        created_by INTEGER,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS ix_ext_res_ed  ON k30_ext_resources(edition_id, parent_id, position)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS ix_ext_res_sum ON k30_ext_resources(checksum)");

    // Kolumny dokładane po wdrożeniu — każda osobno, bo powtórka rzuca wyjątkiem
    foreach ([
        "ALTER TABLE k30_ext_titles ADD COLUMN territory TEXT NOT NULL DEFAULT ''",
        "ALTER TABLE k30_ext_titles ADD COLUMN cover_sum TEXT NOT NULL DEFAULT ''",
    ] as $sql) {
        try { $pdo->exec($sql); } catch (\Throwable $e) { /* kolumna już jest */ }
    }

    ext_access_migrate();   // granty, licencje, bilety, log — includes/ext_access.php
}

// ── Magazyn plików ───────────────────────────────────────────────────────────

/**
 * Ścieżka pliku w magazynie adresowanym treścią.
 * Nazwą jest skrót sha256, więc nazwa od użytkownika NIGDY nie staje się ścieżką,
 * a ten sam plik wgrany dwa razy zajmuje miejsce raz.
 */
function ext_storage_path(string $checksum): string
{
    $checksum = preg_replace('/[^a-f0-9]/', '', strtolower($checksum));
    if (strlen($checksum) !== 64) return '';
    return rtrim(UPLOAD_DIR, '/') . '/ext/' . substr($checksum, 0, 2) . '/' . $checksum;
}

/** Katalog na pliki tymczasowe uploadu (kawałki doklejane po kolei). */
function ext_tmp_path(string $sid): string
{
    $sid = preg_replace('/[^a-f0-9]/', '', $sid);
    return rtrim(UPLOAD_DIR, '/') . '/ext/tmp/' . $sid . '.part';
}

/** Typy, które wolno wgrać. Rozstrzyga zawartość pliku, nie rozszerzenie. */
function ext_allowed_mimes(): array
{
    return [
        'application/pdf'       => 'PDF',
        'application/epub+zip'  => 'EPUB',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'DOCX',
        'image/jpeg'            => 'JPEG',
        'image/png'             => 'PNG',
    ];
}

/**
 * Przenosi gotowy plik tymczasowy do magazynu.
 * Zwraca ['ok'=>bool,'msg'=>string,'checksum'=>…,'mime'=>…,'size'=>…,'pages'=>…,'dup'=>bool].
 */
function ext_store_upload(string $tmp, int $byUserId = 0): array
{
    if (!is_file($tmp)) return ['ok' => false, 'msg' => 'Brak pliku do zapisania.'];

    $size = (int)filesize($tmp);
    if ($size <= 0 || $size > EXT_MAX_BYTES) {
        @unlink($tmp);
        return ['ok' => false, 'msg' => 'Plik jest pusty albo przekracza limit '
            . round(EXT_MAX_BYTES / 1048576) . ' MB.'];
    }

    // finfo czyta magiczne bajty — deklaracja przeglądarki nie ma tu nic do rzeczy
    $mime = '';
    if (class_exists('finfo')) {
        $mime = (string)(new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    } elseif (function_exists('mime_content_type')) {
        $mime = (string)mime_content_type($tmp);
    }
    if (!isset(ext_allowed_mimes()[$mime])) {
        @unlink($tmp);
        return ['ok' => false, 'msg' => 'Niedozwolony typ pliku' . ($mime ? " ($mime)" : '') . '.'];
    }

    $sum  = hash_file('sha256', $tmp);
    $dest = ext_storage_path($sum);
    if ($dest === '') { @unlink($tmp); return ['ok' => false, 'msg' => 'Błąd skrótu pliku.']; }

    if (is_file($dest)) {                      // ten sam plik już jest w magazynie
        @unlink($tmp);
        return ['ok' => true, 'msg' => 'Plik był już w magazynie — użyto istniejącej kopii.',
                'checksum' => $sum, 'mime' => $mime, 'size' => (int)filesize($dest),
                'pages' => ext_pdf_pages($dest, $mime), 'dup' => true];
    }

    @mkdir(dirname($dest), 0770, true);
    if (!@rename($tmp, $dest)) {               // rename w obrębie wolumenu jest atomowe
        @unlink($tmp);
        return ['ok' => false, 'msg' => 'Nie udało się zapisać pliku w magazynie.'];
    }
    @chmod($dest, 0640);
    ext_storage_guard();

    return ['ok' => true, 'msg' => 'Plik zapisany.', 'checksum' => $sum, 'mime' => $mime,
            'size' => $size, 'pages' => ext_pdf_pages($dest, $mime), 'dup' => false];
}

/**
 * Druga bariera na wypadek błędnej konfiguracji serwera. Katalog uploads/ w tym
 * wdrożeniu leży poza webrootem, ale gdyby kiedyś ktoś go przeniósł, moduł nie
 * ma przez to zacząć rozdawać książek bezpośrednio.
 */
function ext_storage_guard(): void
{
    $dir = rtrim(UPLOAD_DIR, '/') . '/ext';
    $ht  = $dir . '/.htaccess';
    if (!is_file($ht)) {
        @mkdir($dir, 0770, true);
        @file_put_contents($ht, "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
    }
}

/**
 * Liczba stron PDF-a. Liczymy przez mPDF (i tak jest w projekcie), bo szukanie
 * `/Type /Page` wyrażeniem regularnym zaniża wynik w plikach ze strumieniami
 * obiektów — a takie są dziś prawie wszystkie książki z wydawnictw.
 * Brak wyniku nie jest błędem: liczba stron to metadana, nie warunek wgrania.
 */
function ext_pdf_pages(string $path, string $mime = 'application/pdf'): ?int
{
    if ($mime !== 'application/pdf' || !is_file($path)) return null;
    $autoload = dirname(__DIR__) . '/vendor/autoload.php';
    if (!is_file($autoload)) return null;
    require_once $autoload;
    try {
        $mpdf  = new \Mpdf\Mpdf(['tempDir' => sys_get_temp_dir() . '/mpdf']);
        $pages = (int)$mpdf->SetSourceFile($path);
        return $pages ?: null;
    } catch (\Throwable $e) {
        error_log('[ext_pdf_pages] ' . $e->getMessage());
        return null;
    }
}

/** Kasuje plik z magazynu, o ile żaden inny zasób go nie używa. */
function ext_blob_release(string $checksum): void
{
    if ($checksum === '') return;
    $used = db_one("SELECT COUNT(*) AS n FROM k30_ext_resources WHERE checksum=?", [$checksum]);
    if ((int)($used['n'] ?? 0) > 0) return;      // wciąż w użyciu — nie ruszamy
    $p = ext_storage_path($checksum);
    if ($p !== '' && is_file($p)) @unlink($p);
}

// ── Reguły praw ──────────────────────────────────────────────────────────────

/** Reguły wyjściowe — najostrożniejsze z możliwych. */
function ext_default_rules(): array
{
    return [
        'allow_download'   => 0,
        'allow_print'      => 0,
        'watermark_policy' => 'both',
        'ticket_ttl'       => 180,
        'abilities'        => ['view', 'stream'],
    ];
}

/**
 * Reguły skuteczne dla zasobu: wydawca → tytuł → wydanie → zasób.
 * Niższy poziom może tylko ZAWĘZIĆ — nikt na dole nie odblokuje pobierania,
 * którego zabronił wydawca. Inaczej jedno nieuważne kliknięcie na rozdziale
 * łamałoby umowę licencyjną.
 */
function ext_effective_rules(array $publisher, array $title, array $edition = [], array $resource = []): array
{
    $r = array_merge(ext_default_rules(), ext_json($publisher['default_rules'] ?? ''));

    $r['allow_download']   = (int)$r['allow_download']   & (int)($title['allow_download'] ?? 0);
    $r['allow_print']      = (int)$r['allow_print']      & (int)($title['allow_print'] ?? 0);
    $r['watermark_policy'] = ext_stricter_watermark($r['watermark_policy'], $title['watermark_policy'] ?? 'both');

    foreach ([$edition, $resource] as $lvl) {
        $o = ext_json($lvl['rules'] ?? '');
        if (isset($o['allow_download'])) $r['allow_download'] = (int)$r['allow_download'] & (int)$o['allow_download'];
        if (isset($o['allow_print']))    $r['allow_print']    = (int)$r['allow_print']    & (int)$o['allow_print'];
        if (isset($o['watermark_policy'])) {
            $r['watermark_policy'] = ext_stricter_watermark($r['watermark_policy'], (string)$o['watermark_policy']);
        }
        if (isset($o['ticket_ttl'])) $r['ticket_ttl'] = min((int)$r['ticket_ttl'], max(30, (int)$o['ticket_ttl']));
    }

    $r['abilities'] = ['view', 'stream'];
    if ($r['allow_download']) $r['abilities'][] = 'download';
    if ($r['allow_print'])    $r['abilities'][] = 'print';
    return $r;
}

/** Z dwóch polityk znaku wodnego wybiera ostrzejszą (none < footer < overlay < both). */
function ext_stricter_watermark(string $a, string $b): string
{
    $rank = ['none' => 0, 'footer' => 1, 'overlay' => 2, 'both' => 3];
    return ($rank[$b] ?? 3) > ($rank[$a] ?? 0) ? $b : $a;
}

/** json_decode, który nigdy nie wywraca strony na uszkodzonym wpisie. */
function ext_json(?string $raw): array
{
    if ($raw === null || trim($raw) === '') return [];
    $v = json_decode($raw, true);
    return is_array($v) ? $v : [];
}

// ── Odczyt katalogu ──────────────────────────────────────────────────────────

function ext_publishers(bool $only_active = true): array
{
    return db_all("SELECT * FROM k30_ext_publishers "
        . ($only_active ? "WHERE is_active=1 " : "")
        . "ORDER BY name COLLATE NOCASE");
}

function ext_publisher_get(int $id): ?array
{
    return $id ? db_one("SELECT * FROM k30_ext_publishers WHERE id=?", [$id]) : null;
}

/** Kategorie płasko, w kolejności drzewa (path sortuje samo). */
function ext_categories(): array
{
    return db_all("SELECT * FROM k30_ext_categories ORDER BY path, position, name COLLATE NOCASE");
}

/**
 * Zapis kategorii wraz z odbudową ścieżki materializowanej.
 * Ścieżka '/1/14/57/' pozwala dziedziczyć uprawnienia jednym LIKE, bez rekurencji.
 */
function ext_category_save(array $d, ?int $id = null): int
{
    $parent = (int)($d['parent_id'] ?? 0) ?: null;
    $row = [
        'parent_id' => $parent,
        'name'      => trim((string)($d['name'] ?? '')),
        'position'  => (int)($d['position'] ?? 0),
    ];
    if ($id) { db_update('k30_ext_categories', $row, $id); }
    else     { $id = db_insert('k30_ext_categories', $row + ['path' => '', 'depth' => 0]); }

    $p     = $parent ? db_one("SELECT path, depth FROM k30_ext_categories WHERE id=?", [$parent]) : null;
    $path  = ($p['path'] ?? '/') . $id . '/';
    $depth = (int)($p['depth'] ?? -1) + 1;
    db_exec("UPDATE k30_ext_categories SET path=?, depth=? WHERE id=?", [$path, $depth, $id]);

    // Przeniesienie gałęzi zmienia ścieżki potomków — poprawiamy je jednym UPDATE
    db_exec("UPDATE k30_ext_categories
             SET path = ? || substr(path, length(?) + 1)
             WHERE path LIKE ? AND id <> ?",
        [$path, $path, '%/' . $id . '/%', $id]);

    return $id;
}

function ext_title_get(int $id): ?array
{
    return $id ? db_one(
        "SELECT t.*, p.name AS publisher_name, p.default_rules
         FROM k30_ext_titles t
         JOIN k30_ext_publishers p ON p.id = t.publisher_id
         WHERE t.id=?", [$id]) : null;
}

function ext_editions(int $title_id, bool $only_active = true): array
{
    return db_all("SELECT * FROM k30_ext_editions WHERE title_id=? "
        . ($only_active ? "AND is_active=1 " : "")
        . "ORDER BY year DESC, id DESC", [$title_id]);
}

function ext_edition_get(int $id): ?array
{
    return $id ? db_one("SELECT * FROM k30_ext_editions WHERE id=?", [$id]) : null;
}

/** Zasoby wydania w kolejności drzewa (rozdziały i ich dzieci). */
function ext_resources(int $edition_id, bool $only_active = true): array
{
    $rows = db_all("SELECT * FROM k30_ext_resources WHERE edition_id=? "
        . ($only_active ? "AND is_active=1 " : "")
        . "ORDER BY position, id", [$edition_id]);

    $byParent = [];
    foreach ($rows as $r) { $byParent[(int)$r['parent_id']][] = $r; }

    $out = [];
    $walk = function (int $parent, int $depth) use (&$walk, &$out, $byParent) {
        foreach ($byParent[$parent] ?? [] as $r) {
            $r['depth'] = $depth;
            $out[] = $r;
            $walk((int)$r['id'], $depth + 1);
        }
    };
    $walk(0, 0);
    return $out;
}

function ext_resource_get(int $id): ?array
{
    return $id ? db_one("SELECT * FROM k30_ext_resources WHERE id=?", [$id]) : null;
}

/** Zasób razem z całym kontekstem praw — tego potrzebuje ext_decide(). */
function ext_resource_context(int $resource_id): ?array
{
    $row = db_one(
        "SELECT r.*, e.title_id, e.rules AS edition_rules, e.name AS edition_name,
                t.publisher_id
         FROM k30_ext_resources r
         JOIN k30_ext_editions e ON e.id = r.edition_id
         JOIN k30_ext_titles   t ON t.id = e.title_id
         WHERE r.id=? AND r.is_active=1 AND e.is_active=1 AND t.is_active=1",
        [$resource_id]
    );
    if (!$row) return null;

    $title     = ext_title_get((int)$row['title_id']);
    $publisher = ext_publisher_get((int)$row['publisher_id']);
    if (!$title || !$publisher) return null;

    return ['resource' => $row, 'edition' => ['rules' => $row['edition_rules']],
            'title' => $title, 'publisher' => $publisher];
}

/** Rozmiar po ludzku — do list i podsumowań. */
function ext_human_size(int $bytes): string
{
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 1) . ' GB';
    if ($bytes >= 1048576)    return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024)       return round($bytes / 1024) . ' kB';
    return $bytes . ' B';
}
