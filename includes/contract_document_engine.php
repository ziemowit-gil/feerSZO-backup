<?php
/**
 * includes/contract_document_engine.php
 * Wygenerowane (i edytowalne na żywo) dokumenty umów.
 *
 * Odróżnij od contract_template_engine.php: tamten silnik przechowuje WZORCE
 * (contract_doc_templates, tagi {zmienna}). Ten plik przechowuje KONKRETNE
 * dokumenty wygenerowane z wzorca dla danej umowy — z treścią, którą
 * koordynator może dowolnie doedytować przed wydrukiem/eksportem, niezależnie
 * od wzorca źródłowego (zmiana wzorca później nie wpływa na już wygenerowane
 * dokumenty).
 */
require_once __DIR__ . '/contract_template_engine.php';

function cgd_migrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;

    db()->exec("CREATE TABLE IF NOT EXISTS contract_documents (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        template_id   INTEGER NOT NULL,
        contract_type TEXT    NOT NULL,
        contract_id   INTEGER NOT NULL,
        tresc_finalna TEXT    NOT NULL DEFAULT '',
        status        TEXT    NOT NULL DEFAULT 'szkic',
        created_by    INTEGER NULL,
        created_at    DATETIME NOT NULL DEFAULT (datetime('now','localtime')),
        updated_at    DATETIME NOT NULL DEFAULT (datetime('now','localtime'))
    )");
    db()->exec("CREATE INDEX IF NOT EXISTS idx_contract_documents_contract
                ON contract_documents(contract_type, contract_id)");
}

/** Statusy dokumentu wraz z etykietami (kolejność = ścieżka procesu). */
function cgd_statuses(): array {
    return [
        'szkic'       => 'Szkic',
        'do_podpisu'  => 'Do podpisu',
        'podpisana'   => 'Podpisana',
    ];
}

const CGD_CONTRACT_TABLES = [
    'wolontariat' => 'umowy_wolontariat',
    'zlecenie'    => 'umowy_zlecenie',
    'dzielo'      => 'umowy_dzielo',
    'praca'       => 'umowy_praca',
];

/** Pobiera wiersz źródłowej umowy (dane do podstawienia), albo [] gdy brak. */
function cgd_source_row(string $contract_type, int $contract_id): array {
    $table = CGD_CONTRACT_TABLES[$contract_type] ?? null;
    if (!$table || !$contract_id) return [];
    return db_one("SELECT * FROM {$table} WHERE id=?", [$contract_id]) ?: [];
}

/**
 * Krok 1 — generuje dokument z wzorca: podstawia dane umowy i zapisuje
 * nowy wiersz w contract_documents (status początkowy: 'szkic').
 * Zwraca ID nowo utworzonego dokumentu, albo null gdy wzorzec nie istnieje.
 */
function cgd_create(int $template_id, string $contract_type, int $contract_id, int $created_by): ?int {
    cgd_migrate();

    $tpl = cte_get($template_id);
    if (!$tpl) return null;

    $row  = cgd_source_row($contract_type, $contract_id);
    $map  = cte_build_map($contract_type, $row);
    $html = cte_render($tpl['body'], $map);

    return db_insert('contract_documents', [
        'template_id'   => $template_id,
        'contract_type' => $contract_type,
        'contract_id'   => $contract_id,
        'tresc_finalna' => $html,
        'status'        => 'szkic',
        'created_by'    => $created_by ?: null,
        'created_at'    => date('Y-m-d H:i:s'),
    ]);
}

function cgd_get(int $id): ?array {
    cgd_migrate();
    return db_one("SELECT * FROM contract_documents WHERE id=?", [$id]) ?: null;
}

/** Lista wygenerowanych dokumentów dla danej umowy, z nazwą wzorca źródłowego. */
function cgd_list_for_contract(string $contract_type, int $contract_id): array {
    cgd_migrate();
    return db_all(
        "SELECT cd.*, ct.name AS template_name
           FROM contract_documents cd
           LEFT JOIN contract_doc_templates ct ON ct.id = cd.template_id
          WHERE cd.contract_type = ? AND cd.contract_id = ?
          ORDER BY cd.created_at DESC",
        [$contract_type, $contract_id]
    );
}

/** Krok 2 — zapisuje treść po live edycji. */
function cgd_update_content(int $id, string $html): void {
    cgd_migrate();
    db_update('contract_documents', ['tresc_finalna' => $html], $id);
}

function cgd_set_status(int $id, string $status): bool {
    cgd_migrate();
    if (!array_key_exists($status, cgd_statuses())) return false;
    db_update('contract_documents', ['status' => $status], $id);
    return true;
}
