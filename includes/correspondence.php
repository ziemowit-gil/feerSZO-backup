<?php
/**
 * Moduł Korespondencja — init DB + funkcje pomocnicze.
 */

function _corr_init(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo = db();

    $pdo->exec("CREATE TABLE IF NOT EXISTS correspondence (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        direction     TEXT    NOT NULL DEFAULT 'incoming',   -- incoming | outgoing
        number        TEXT    NOT NULL DEFAULT '',
        date          DATE    NOT NULL,
        correspondent TEXT    NOT NULL DEFAULT '',            -- nadawca lub odbiorca
        subject       TEXT    NOT NULL DEFAULT '',
        description   TEXT    NOT NULL DEFAULT '',
        category      TEXT    NOT NULL DEFAULT '',
        status        TEXT    NOT NULL DEFAULT 'new',         -- new|in_progress|replied|closed|archived
        attachment    TEXT    NOT NULL DEFAULT '',
        handled_by    INTEGER DEFAULT NULL REFERENCES users(id) ON DELETE SET NULL,
        created_by    INTEGER DEFAULT NULL REFERENCES users(id) ON DELETE SET NULL,
        created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at    DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_corr_dir  ON correspondence(direction)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_corr_date ON correspondence(date DESC)");

    // Połączenie z EZD
    try { $pdo->exec("ALTER TABLE correspondence ADD COLUMN ezd_pismo_id INTEGER DEFAULT NULL REFERENCES ezd_pisma(id) ON DELETE SET NULL"); } catch(\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE ezd_pisma ADD COLUMN corr_id INTEGER DEFAULT NULL REFERENCES correspondence(id) ON DELETE SET NULL"); } catch(\Throwable $e) {}

    // Pola wysyłki fizycznej / dispatch
    try { $pdo->exec("ALTER TABLE correspondence ADD COLUMN carrier          TEXT NOT NULL DEFAULT ''"); } catch(\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE correspondence ADD COLUMN tracking_number  TEXT NOT NULL DEFAULT ''"); } catch(\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE correspondence ADD COLUMN shipment_type    TEXT NOT NULL DEFAULT ''"); } catch(\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE correspondence ADD COLUMN dispatch_date    DATE"); } catch(\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE correspondence ADD COLUMN contract_type    TEXT NOT NULL DEFAULT ''"); } catch(\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE correspondence ADD COLUMN contract_id      INTEGER DEFAULT NULL"); } catch(\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE correspondence ADD COLUMN s10_number       TEXT NOT NULL DEFAULT ''"); } catch(\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE correspondence ADD COLUMN apaczka_shipment_id INTEGER DEFAULT NULL"); } catch(\Throwable $e) {}

    // Rodzaj medium — rozróżnienie korespondencji papierowej od elektronicznej
    try { $pdo->exec("ALTER TABLE correspondence ADD COLUMN medium TEXT NOT NULL DEFAULT 'papier'"); } catch(\Throwable $e) {}
}

// Rodzaj medium korespondencji (spójne z EZD_MEDIA w includes/ezd.php)
const CORR_MEDIA = [
    'papier' => ['label' => 'Papierowe',            'icon' => 'bi-file-earmark-text'],
    'email'  => ['label' => 'E-mail',               'icon' => 'bi-at'],
    'epuap'  => ['label' => 'e-Doręczenia',         'icon' => 'bi-mailbox2'],
    'faks'   => ['label' => 'Faks',                 'icon' => 'bi-printer'],
    'inne'   => ['label' => 'Inne',                 'icon' => 'bi-question-circle'],
];

// ── CRUD ──────────────────────────────────────────────────────────────────────

function corr_get_all(array $filters = []): array {
    _corr_init();
    $where = []; $params = [];

    if (!empty($filters['direction'])) {
        $where[] = 'direction=?'; $params[] = $filters['direction'];
    }
    if (!empty($filters['status'])) {
        $where[] = 'status=?'; $params[] = $filters['status'];
    }
    if (!empty($filters['category'])) {
        $where[] = 'category=?'; $params[] = $filters['category'];
    }
    if (!empty($filters['medium'])) {
        $where[] = 'medium=?'; $params[] = $filters['medium'];
    }
    if (!empty($filters['q'])) {
        $where[] = '(subject LIKE ? OR correspondent LIKE ? OR number LIKE ?)';
        $q = '%' . $filters['q'] . '%';
        array_push($params, $q, $q, $q);
    }
    if (!empty($filters['date_from'])) {
        $where[] = 'date >= ?'; $params[] = $filters['date_from'];
    }
    if (!empty($filters['date_to'])) {
        $where[] = 'date <= ?'; $params[] = $filters['date_to'];
    }

    // Sprawdź czy tabela ezd_pisma istnieje (EZD może być wyłączone)
    $_ezd_exists = db_one("SELECT name FROM sqlite_master WHERE type='table' AND name='ezd_pisma'");
    $ezd_join = $_ezd_exists
        ? "LEFT JOIN ezd_pisma p ON p.id = c.ezd_pismo_id"
        : "";
    $ezd_col  = $_ezd_exists ? ", p.sygnatura AS ezd_sygnatura" : "";

    $sql = "SELECT c.*, u.name AS handler_name, cb.name AS creator_name {$ezd_col}
            FROM correspondence c
            LEFT JOIN users u  ON u.id  = c.handled_by
            LEFT JOIN users cb ON cb.id = c.created_by
            {$ezd_join}"
        . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
        . " ORDER BY c.date DESC, c.id DESC";

    return db_all($sql, $params);
}

function corr_get(int $id): ?array {
    _corr_init();
    return db_one(
        "SELECT c.*, u.name AS handler_name, cb.name AS creator_name
         FROM correspondence c
         LEFT JOIN users u  ON u.id  = c.handled_by
         LEFT JOIN users cb ON cb.id = c.created_by
         WHERE c.id=?", [$id]
    );
}

function corr_create(array $data, int $user_id): int {
    _corr_init();
    $data['created_by'] = $user_id;
    $data['created_at'] = date('Y-m-d H:i:s');
    $data['updated_at'] = date('Y-m-d H:i:s');
    return db_insert('correspondence', $data);
}

function corr_update(int $id, array $data): void {
    _corr_init();
    $data['updated_at'] = date('Y-m-d H:i:s');
    $set = implode(', ', array_map(fn($k) => "$k=:$k", array_keys($data)));
    $data['id'] = $id;
    db()->prepare("UPDATE correspondence SET $set WHERE id=:id")->execute($data);
}

function corr_delete(int $id): void {
    _corr_init();
    // Usuń załącznik
    $row = db_one("SELECT attachment FROM correspondence WHERE id=?", [$id]);
    if ($row && $row['attachment']) {
        @unlink(dirname(__DIR__) . '/uploads/correspondence/' . $row['attachment']);
    }
    db()->prepare("DELETE FROM correspondence WHERE id=?")->execute([$id]);
}

function corr_upload(int $id, string $field, int $user_id): ?string {
    _corr_init();
    $f = $_FILES[$field] ?? null;
    if (!$f || $f['error'] !== UPLOAD_ERR_OK) return null;

    $allowed = ['pdf','doc','docx','xls','xlsx','odt','ods','png','jpg','jpeg','gif','webp','zip','txt','eml','msg'];
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed)) return 'Niedozwolony typ pliku.';
    if ($f['size'] > 20 * 1024 * 1024) return 'Plik zbyt duży (maks. 20 MB).';

    $dir = dirname(__DIR__) . '/uploads/correspondence/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    // Usuń stary plik
    $old = db_one("SELECT attachment FROM correspondence WHERE id=?", [$id]);
    if ($old && $old['attachment']) @unlink($dir . $old['attachment']);

    $stored = 'corr_' . $id . '_' . time() . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], $dir . $stored)) return 'Błąd zapisu pliku.';

    db()->prepare("UPDATE correspondence SET attachment=? WHERE id=?")->execute([$stored, $id]);
    return null;
}

// ── Słowniki ──────────────────────────────────────────────────────────────────

function corr_categories(): array {
    _corr_init();
    $rows = db_all("SELECT DISTINCT category FROM correspondence WHERE category!='' ORDER BY category");
    return array_column($rows, 'category');
}

function corr_status_label(string $s): array {
    return match($s) {
        'new'         => ['Nowa',         'primary'],
        'in_progress' => ['W trakcie',    'warning'],
        'replied'     => ['Odpowiedziano','info'],
        'closed'      => ['Zamknięta',    'success'],
        'archived'    => ['Archiwum',     'secondary'],
        default       => [$s,             'light'],
    };
}

function corr_direction_label(string $d): array {
    return match($d) {
        'incoming' => ['Przychodząca', 'bi-arrow-down-circle-fill', 'success'],
        'outgoing' => ['Wychodząca',   'bi-arrow-up-circle-fill',   'primary'],
        default    => [$d,             'bi-circle',                  'secondary'],
    };
}

function corr_stats(): array {
    _corr_init();
    $rows = db_all("SELECT direction, status, COUNT(*) AS c FROM correspondence GROUP BY direction, status");
    $out = ['incoming' => [], 'outgoing' => [], 'total' => 0];
    foreach ($rows as $r) {
        $out[$r['direction']][$r['status']] = (int)$r['c'];
        $out['total'] += (int)$r['c'];
    }
    return $out;
}

// ── Integracja EZD ────────────────────────────────────────────────────────────

/**
 * Tworzy pismo EZD na podstawie wpisu korespondencji i łączy je ze sobą.
 * Zwraca ID nowego pisma EZD.
 */
function corr_register_in_ezd(int $corr_id, int $sprawa_id, int $user_id): int {
    _corr_init();
    require_once __DIR__ . '/ezd.php';

    $corr = corr_get($corr_id);
    if (!$corr) throw new \RuntimeException('Korespondencja nie istnieje.');

    $sprawa = ezd_sprawa_get($sprawa_id);
    if (!$sprawa) throw new \RuntimeException('Sprawa EZD nie istnieje.');

    // Mapowanie kierunku
    $kierunek_map = ['incoming' => 'przychodzace', 'outgoing' => 'wychodzace'];
    $kierunek = $kierunek_map[$corr['direction']] ?? 'przychodzace';

    $pismo_id = ezd_pismo_create([
        'sprawa_id'    => $sprawa_id,
        'kierunek'     => $kierunek,
        'title'        => $corr['subject'],
        'tresc'        => $corr['description'],
        'nadawca'      => $corr['direction'] === 'incoming' ? $corr['correspondent'] : '',
        'odbiorca'     => $corr['direction'] === 'outgoing' ? $corr['correspondent'] : '',
        'data_pisma'   => $corr['date'],
        'data_wplywu'  => $corr['direction'] === 'incoming' ? $corr['date'] : '',
        'data_wysylki' => $corr['direction'] === 'outgoing' ? $corr['date'] : '',
        'status'       => 'nowe',
        'owner_id'     => $user_id,
        'corr_id'      => $corr_id,
        'rodzaj_medium'=> array_key_exists($corr['medium'] ?? '', EZD_MEDIA) ? $corr['medium'] : 'papier',
    ], $user_id);

    // Aktualizuj oba rekordy z cross-linkiem
    db()->prepare("UPDATE correspondence SET ezd_pismo_id=? WHERE id=?")->execute([$pismo_id, $corr_id]);

    // Przenieś załącznik jeśli istnieje
    if ($corr['attachment']) {
        $src = dirname(__DIR__) . '/uploads/correspondence/' . $corr['attachment'];
        if (file_exists($src)) {
            ezd_upload_from_file($src, $corr['attachment'], $sprawa_id, $user_id, $pismo_id);
        }
    }

    ezd_log(null, $sprawa_id, $pismo_id, null, $user_id, 'pismo_create',
        'Zarejestrowano z modułu Korespondencja #' . $corr_id);

    return $pismo_id;
}

/**
 * Zwraca dane powiązanego pisma EZD lub null.
 */
function corr_get_ezd_pismo(int $corr_id): ?array {
    _corr_init();
    $corr = db_one("SELECT ezd_pismo_id FROM correspondence WHERE id=?", [$corr_id]);
    if (!$corr || !$corr['ezd_pismo_id']) return null;
    return db_one(
        "SELECT p.*, s.znak_sprawy, s.title AS sprawa_title, s.id AS sprawa_id
         FROM ezd_pisma p
         JOIN ezd_sprawy s ON s.id = p.sprawa_id
         WHERE p.id=?",
        [(int)$corr['ezd_pismo_id']]
    );
}

/**
 * Zwraca dane powiązanej korespondencji dla pisma EZD lub null.
 */
function ezd_get_linked_corr(int $pismo_id): ?array {
    return db_one(
        "SELECT * FROM correspondence WHERE ezd_pismo_id=?",
        [$pismo_id]
    );
}

/**
 * Czy automatyczna rejestracja korespondencji w EZD jest włączona (domyślnie tak).
 */
function corr_ezd_auto_enabled(): bool {
    if (!module_enabled('ezd_enabled')) return false;
    return org_setting('corr_ezd_auto') !== '0';
}

/**
 * Automatyczna rejestracja korespondencji w EZD Wirtualne biurko.
 * Trafia do sprawy ciągłej „Korespondencja przychodząca/wychodząca {rok}" (JRWA korespondencji).
 * Idempotentne — pomija, gdy korespondencja jest już powiązana z pismem EZD.
 * @return int|null id utworzonego pisma EZD lub null gdy pominięto
 */
function corr_auto_register(int $corr_id, int $user_id): ?int {
    if (!corr_ezd_auto_enabled()) return null;
    _corr_init();
    require_once __DIR__ . '/ezd.php';

    $corr = corr_get($corr_id);
    if (!$corr) return null;
    if (!empty($corr['ezd_pismo_id'])) return (int)$corr['ezd_pismo_id']; // już powiązane

    $dir = ($corr['direction'] ?? 'incoming') === 'outgoing' ? 'outgoing' : 'incoming';
    $rok = (int)substr($corr['date'] ?? date('Y-m-d'), 0, 4) ?: (int)date('Y');
    try {
        $sprawa_id = ezd_corr_sprawa_id($dir, $rok, $user_id ?: 0);
        return corr_register_in_ezd($corr_id, $sprawa_id, $user_id ?: 0);
    } catch (\Throwable $e) {
        error_log('corr_auto_register: ' . $e->getMessage());
        return null;
    }
}

/**
 * Odłącza pismo EZD od korespondencji (usuwa cross-linki, nie usuwa rekordów).
 */
function corr_unlink_ezd(int $corr_id): void {
    _corr_init();
    $corr = db_one("SELECT ezd_pismo_id FROM correspondence WHERE id=?", [$corr_id]);
    if ($corr && $corr['ezd_pismo_id']) {
        db()->prepare("UPDATE ezd_pisma SET corr_id=NULL WHERE id=?")->execute([$corr['ezd_pismo_id']]);
    }
    db()->prepare("UPDATE correspondence SET ezd_pismo_id=NULL WHERE id=?")->execute([$corr_id]);
}

/**
 * Pomocnicza do przeniesienia pliku do EZD bez $_FILES.
 */
function ezd_upload_from_file(string $src_path, string $original_name, int $sprawa_id, int $user_id, ?int $pismo_id = null): void {
    $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
    $dir = dirname(__DIR__) . '/uploads/ezd/' . $sprawa_id . '/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $stored = time() . '_' . $original_name;
    if (!copy($src_path, $dir . $stored)) return;

    $mime = mime_content_type($src_path) ?: 'application/octet-stream';
    $size = filesize($src_path);

    db()->prepare(
        "INSERT INTO ezd_zalaczniki (sprawa_id, pismo_id, original_name, stored_name, mime_type, file_size, uploaded_by)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    )->execute([$sprawa_id, $pismo_id, $original_name, $stored, $mime, $size, $user_id]);
}
