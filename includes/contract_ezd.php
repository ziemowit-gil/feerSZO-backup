<?php
/**
 * Integracja modułu umów z EZD Wirtualne biurko.
 *
 * Każda umowa może być powiązana z jedną koszulką EZD (ezd_sprawy.id).
 * Kolumna `ezd_sprawa_id` dodawana samonprawczo do każdej tabeli umów.
 *
 * Publiczne API:
 *   contract_ezd_schema_heal()
 *   contract_ezd_get_sprawa_id(type, id) → ?int
 *   contract_ezd_link(type, id, sprawa_id|null, user_id)
 *   contract_ezd_get_sprawa(type, id) → ?array   (tylko gdy ezd_enabled)
 *   contract_ezd_create_and_link(type, id, user_id, row) → int  (sprawa_id)
 *   contract_ezd_register_letter(letter_id)
 */

if (!function_exists('contract_ezd_schema_heal')) :

// ── Schemat ────────────────────────────────────────────────────────────────────

function contract_ezd_schema_heal(): void {
    static $done = false;
    if ($done) return;
    $done = true;

    // Typy umów z własnymi tabelami (pomija virtual types jak canva_request)
    $tables = array_map(
        fn($t) => 'umowy_' . $t,
        array_keys(array_filter(CONTRACT_TYPES, fn($_, $k) => !in_array($k, ['canva_request'], true), ARRAY_FILTER_USE_BOTH))
    );

    $pdo = db();
    foreach ($tables as $tbl) {
        try {
            $pdo->exec("ALTER TABLE {$tbl} ADD COLUMN ezd_sprawa_id INTEGER REFERENCES ezd_sprawy(id) ON DELETE SET NULL");
        } catch (\Throwable $e) {
            // Kolumna już istnieje lub tabela jeszcze nie istnieje — obydwa przypadki OK
        }
        try {
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_{$tbl}_ezd ON {$tbl}(ezd_sprawa_id) WHERE ezd_sprawa_id IS NOT NULL");
        } catch (\Throwable $e) {}
    }

    // Zwrotny link w pismach umów
    try {
        $pdo->exec("ALTER TABLE contract_letters ADD COLUMN ezd_pismo_id INTEGER REFERENCES ezd_pisma(id) ON DELETE SET NULL");
    } catch (\Throwable $e) {}
}

// Auto-heal przy każdym załadowaniu pliku gdy tabele istnieją
try { contract_ezd_schema_heal(); } catch (\Throwable $e) {}

// ── Podstawowe helpery ──────────────────────────────────────────────────────────

function contract_ezd_get_sprawa_id(string $type, int $id): ?int {
    $tbl = table_for_type($type);
    $row = db_one("SELECT ezd_sprawa_id FROM {$tbl} WHERE id=?", [$id]);
    if (!$row || !$row['ezd_sprawa_id']) return null;
    return (int)$row['ezd_sprawa_id'];
}

function contract_ezd_link(string $type, int $id, ?int $sprawa_id, int $user_id): void {
    $tbl = table_for_type($type);
    db()->prepare("UPDATE {$tbl} SET ezd_sprawa_id=? WHERE id=?")
       ->execute([$sprawa_id, $id]);

    $action = $sprawa_id ? 'ezd_linked' : 'ezd_unlinked';
    $note   = $sprawa_id ? "Powiązano z koszulką EZD #{$sprawa_id}" : 'Odwiązano koszulkę EZD';
    try {
        log_contract_action($type, $id, $user_id, $action, $note);
    } catch (\Throwable $e) {}
}

/**
 * Zwraca pełne dane koszulki EZD powiązanej z umową.
 * Zwraca null gdy: brak powiązania, EZD wyłączone, brak funkcji EZD.
 */
function contract_ezd_get_sprawa(string $type, int $id): ?array {
    if (!module_enabled('ezd_enabled')) return null;
    $sid = contract_ezd_get_sprawa_id($type, $id);
    if (!$sid) return null;
    if (!function_exists('ezd_sprawa_get')) {
        require_once __DIR__ . '/ezd.php';
    }
    return ezd_sprawa_get($sid);
}

// ── Czytelna etykieta umowy (do tytułu koszulki) ───────────────────────────────

function contract_ezd_label(string $type, array $row): string {
    $lbl  = CONTRACT_TYPES[$type] ?? $type;
    $name = '';
    foreach (['imie_nazwisko','nazwa_wykonawcy','nazwa_firmy','nazwa_zadania','strona_umowy'] as $col) {
        if (!empty($row[$col])) { $name = $row[$col]; break; }
    }
    $nr = !empty($row['numer_umowy']) ? ' (' . $row['numer_umowy'] . ')' : '';
    return $lbl . ($name ? ' — ' . $name : '') . $nr;
}

// ── Auto-provisioning JRWA / teczki dla koszulek umów ─────────────────────────

function _contract_ezd_jrwa_id(): int {
    if (!function_exists('ezd_sprawa_get')) require_once __DIR__ . '/ezd.php';

    $symbol = org_setting('contracts_ezd_jrwa') ?: 'UMW';
    $row = db_one("SELECT id FROM ezd_jrwa WHERE symbol=?", [$symbol]);
    if ($row) return (int)$row['id'];

    // Auto-utwórz hasło JRWA
    db()->prepare(
        "INSERT INTO ezd_jrwa (symbol, title, kat_arch, description)
         VALUES (?, 'Umowy i porozumienia', 'B10', 'Auto-utworzone przez integrację contract↔EZD')"
    )->execute([$symbol]);
    return (int)db()->lastInsertId();
}

function _contract_ezd_teczka_id(): int {
    if (!function_exists('ezd_teczka_create')) require_once __DIR__ . '/ezd.php';

    $jrwa_id = _contract_ezd_jrwa_id();
    $year    = (int)date('Y');

    $row = db_one("SELECT id FROM ezd_teczki WHERE jrwa_id=? AND rok=?", [$jrwa_id, $year]);
    if ($row) return (int)$row['id'];

    $symbol = (org_setting('contracts_ezd_jrwa') ?: 'UMW') . '.' . $year;
    return ezd_teczka_create([
        'jrwa_id'  => $jrwa_id,
        'symbol'   => $symbol,
        'title'    => 'Umowy ' . $year,
        'rok'      => $year,
        'owner_id' => null,
    ], 1); // system user
}

/**
 * Tworzy nową koszulkę EZD dla umowy i powiązuje ją.
 * Wymaga włączonego EZD i uprawnień write.
 * @return int sprawa_id
 */
function contract_ezd_create_and_link(string $type, int $id, int $user_id, array $row): int {
    if (!function_exists('ezd_sprawa_create')) require_once __DIR__ . '/ezd.php';

    $teczka_id = _contract_ezd_teczka_id();
    $title     = contract_ezd_label($type, $row);

    $sprawa_id = ezd_sprawa_create([
        'teczka_id'  => $teczka_id,
        'title'      => $title,
        'status'     => 'open',
        'owner_id'   => $user_id,
        'deadline'   => !empty($row['data_zakonczenia']) ? $row['data_zakonczenia']
                     : (!empty($row['data_do'])          ? $row['data_do'] : null),
        'ref_type'   => 'contract_' . $type,
        'ref_id'     => $id,
    ], $user_id);

    contract_ezd_link($type, $id, $sprawa_id, $user_id);

    // Log w EZD
    try {
        ezd_log(null, $sprawa_id, null, null, $user_id, 'contract_linked',
            "Powiązano z umową: [{$type} #{$id}] {$title}");
    } catch (\Throwable $e) {}

    return $sprawa_id;
}

// ── Rejestracja pisma do umowy w EZD ──────────────────────────────────────────

/**
 * Jeśli umowa ma powiązaną koszulkę EZD, rejestruje pismo (`contract_letters`)
 * jako EZD pismo przychodzące/wychodzące. Idempotentne — pomija jeśli już zarejestrowane.
 */
function contract_ezd_register_letter(int $letter_id): void {
    if (!module_enabled('ezd_enabled')) return;

    $letter = db_one("SELECT * FROM contract_letters WHERE id=?", [$letter_id]);
    if (!$letter) return;

    // Sprawdź czy już zarejestrowane
    if (!empty($letter['ezd_pismo_id'])) return;

    $sprawa_id = contract_ezd_get_sprawa_id(
        (string)$letter['contract_type'],
        (int)$letter['contract_id']
    );
    if (!$sprawa_id) return;

    if (!function_exists('ezd_pismo_create')) require_once __DIR__ . '/ezd.php';

    // Sprawdź dostęp do koszulki (sprawa musi istnieć i być otwarta)
    $sprawa = ezd_sprawa_get($sprawa_id);
    if (!$sprawa || $sprawa['status'] === 'closed') return;

    $kierunek = str_contains((string)($letter['kierunek'] ?? ''), 'wych') ? 'wychodzace' : 'przychodzace';
    $user_id  = (int)($letter['created_by'] ?? (current_user()['id'] ?? 1));

    try {
        $pismo_id = ezd_pismo_create([
            'sprawa_id'  => $sprawa_id,
            'kierunek'   => $kierunek,
            'title'      => $letter['tytul'] ?? 'Pismo do umowy',
            'status'     => 'nowe',
            'data_pisma' => $letter['data_pisma'] ?? date('Y-m-d'),
            'nadawca'    => $letter['nadawca']    ?? '',
            'odbiorca'   => $letter['odbiorca']   ?? '',
        ], $user_id);

        // Wgraj załącznik pisma jeśli jest
        if (!empty($letter['plik']) && function_exists('ezd_upload_from_path')) {
            $plik_path = UPLOAD_DIR . ltrim($letter['plik'], '/');
            if (is_file($plik_path)) {
                ezd_upload_from_path($plik_path, basename($letter['plik']), $sprawa_id, $user_id, $pismo_id);
            }
        }

        // Zapisz zwrotny link
        db()->prepare("UPDATE contract_letters SET ezd_pismo_id=? WHERE id=?")
            ->execute([$pismo_id, $letter_id]);
    } catch (\Throwable $e) {
        // Best-effort — nie blokuje zapisu pisma
    }
}

endif;
