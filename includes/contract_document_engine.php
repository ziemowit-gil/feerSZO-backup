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

    // Samonaprawa schematu — instalacje sprzed tej kolumny nie mają jej jeszcze.
    try { db()->exec("ALTER TABLE contract_documents ADD COLUMN nr_karty TEXT NULL"); } catch (\Throwable $e) {}

    db()->exec("CREATE TABLE IF NOT EXISTS contract_data_verifications (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        contract_type TEXT     NOT NULL,
        contract_id   INTEGER  NOT NULL,
        verified_at   DATETIME NOT NULL,
        document_id   INTEGER  NULL,
        UNIQUE(contract_type, contract_id)
    )");
}

/** Ile dni od potwierdzenia danych uznajemy je za nadal aktualne. */
const CGD_VERIFICATION_VALIDITY_MONTHS = 6;

/**
 * Zapisuje/aktualizuje datę ostatniego potwierdzenia aktualności danych dla
 * umowy — wołane automatycznie z cgd_create() przy generowaniu dokumentu
 * z wzoru oznaczonego jako „potwierdzający dane" (contract_doc_templates.verifies_data).
 */
function cgd_mark_data_verified(string $contract_type, int $contract_id, int $document_id): void {
    cgd_migrate();
    db()->prepare(
        "INSERT INTO contract_data_verifications (contract_type, contract_id, verified_at, document_id)
         VALUES (?, ?, ?, ?)
         ON CONFLICT(contract_type, contract_id) DO UPDATE SET
            verified_at = excluded.verified_at, document_id = excluded.document_id"
    )->execute([$contract_type, $contract_id, date('Y-m-d H:i:s'), $document_id]);
}

/**
 * Status weryfikacji danych dla umowy.
 * Zwraca: verified_at (albo null gdy nigdy), expires_at, is_stale (bool).
 */
function cgd_verification_status(string $contract_type, int $contract_id): array {
    cgd_migrate();
    $row = db_one(
        "SELECT verified_at FROM contract_data_verifications WHERE contract_type=? AND contract_id=?",
        [$contract_type, $contract_id]
    );
    if (!$row) return ['verified_at' => null, 'expires_at' => null, 'is_stale' => true];

    $expires_at = date('Y-m-d H:i:s', strtotime('+' . CGD_VERIFICATION_VALIDITY_MONTHS . ' months', strtotime($row['verified_at'])));
    return [
        'verified_at' => $row['verified_at'],
        'expires_at'  => $expires_at,
        'is_stale'    => strtotime($expires_at) < time(),
    ];
}

/**
 * Generuje unikalny numer Karty Weryfikacji Danych: EZD-KaWer/{6 cyfr}{3 litery}.
 * Sprawdza unikalność w bazie (kolizja losowa jest bliska zeru, ale i tak
 * zabezpieczamy się przed nią pętlą).
 */
function cgd_generate_karta_numer(): string {
    cgd_migrate();
    do {
        $digits  = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $letters = '';
        for ($i = 0; $i < 3; $i++) $letters .= chr(random_int(65, 90));
        $numer = 'EZD-KaWer/' . $digits . $letters;
    } while (db_one("SELECT id FROM contract_documents WHERE nr_karty=?", [$numer]));
    return $numer;
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
 * Zwraca listę placeholderów UŻYTYCH w treści wzorca, dla których mapa danych
 * ma pustą wartość — czyli te, o które trzeba dopytać koordynatora przed
 * wygenerowaniem dokumentu. Format: {tag} => opis (z cte_variables()).
 */
function cgd_missing_placeholders(string $template_body, array $map): array {
    static $desc_by_tag = null;
    if ($desc_by_tag === null) {
        $desc_by_tag = [];
        foreach (cte_variables() as $vars) {
            foreach ($vars as $tag => $desc) $desc_by_tag[$tag] = $desc;
        }
    }

    $missing = [];
    foreach ($map as $tag => $val) {
        if (trim((string)$val) === '' && str_contains($template_body, $tag)) {
            $missing[$tag] = $desc_by_tag[$tag] ?? trim($tag, '{}');
        }
    }
    return $missing;
}

/** Tekst wstawiany zamiast placeholdera, gdy koordynator jawnie oznaczy pole jako niedostępne. */
const CGD_NO_DATA_LABEL = 'Brak danych w systemie';

/**
 * Krok 1 — generuje dokument z wzorca: podstawia dane umowy (i ewentualne
 * ręcznie uzupełnione braki z $overrides) i zapisuje nowy wiersz w
 * contract_documents (status początkowy: 'szkic').
 * Wzory oznaczone jako verifies_data dostają dodatkowo unikalny numer karty
 * (patrz cgd_generate_karta_numer()) dostępny w treści jako {nr_karty}.
 * Zwraca ID nowo utworzonego dokumentu, albo null gdy wzorzec nie istnieje.
 */
function cgd_create(int $template_id, string $contract_type, int $contract_id, int $created_by, array $overrides = []): ?int {
    cgd_migrate();

    $tpl = cte_get($template_id);
    if (!$tpl) return null;

    $row  = cgd_source_row($contract_type, $contract_id);
    $map  = cte_build_map($contract_type, $row);
    if ($overrides) $map = array_merge($map, $overrides);

    $nr_karty = null;
    if (!empty($tpl['verifies_data'])) {
        $nr_karty = cgd_generate_karta_numer();
        $map['{nr_karty}'] = $nr_karty;
    }

    $html = cte_render($tpl['body'], $map);

    $doc_id = db_insert('contract_documents', [
        'template_id'   => $template_id,
        'contract_type' => $contract_type,
        'contract_id'   => $contract_id,
        'tresc_finalna' => $html,
        'nr_karty'      => $nr_karty,
        'status'        => 'szkic',
        'created_by'    => $created_by ?: null,
        'created_at'    => date('Y-m-d H:i:s'),
    ]);

    if (!empty($tpl['verifies_data'])) {
        cgd_mark_data_verified($contract_type, $contract_id, $doc_id);
    }

    return $doc_id;
}

/**
 * Buduje HTML nagłówka organizacji (logo, nazwa, adres, NIP/KRS, numer/data)
 * — ten sam wygląd w podglądzie/edycji (edytuj.php) i w eksporcie PDF
 * (contracts/dokumenty/pdf.php), żeby to, co koordynator widzi przy edycji,
 * odpowiadało temu, co dostanie na wydruku.
 */
function cgd_org_header_html(array $doc, array $row): string {
    $org_name  = org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : '');
    $org_adres = org_setting('org_adres') ?: '';
    $org_nip   = org_setting('org_nip') ?: '';
    $org_krs   = org_setting('org_krs') ?: '';
    $org_city  = org_setting('org_miejscowosc') ?: '';

    $logo_b64 = ''; $logo_mime = 'image/png';
    $logo_file = org_setting('org_logo');
    if ($logo_file) {
        $lpath = dirname(__DIR__) . '/assets/logo/' . basename($logo_file);
        if (file_exists($lpath) && filesize($lpath) < 500_000) {
            $logo_b64  = base64_encode(file_get_contents($lpath));
            $logo_mime = str_ends_with(strtolower($logo_file), '.svg') ? 'image/svg+xml'
                       : (str_ends_with(strtolower($logo_file), '.jpg') ? 'image/jpeg' : 'image/png');
        }
    }

    $doc_ref  = $doc['nr_karty'] ?? ($row['numer_umowy'] ?? '');
    $doc_date = date('d.m.Y', strtotime($doc['updated_at'] ?? $doc['created_at']));
    $meta = trim(($org_adres ?: '') . ($org_nip ? "\nNIP: {$org_nip}" . ($org_krs ? " · KRS: {$org_krs}" : '') : ''));

    $html  = '<div class="doc-org-header" style="width:100%;border-bottom:1.5pt solid #000;padding-bottom:8pt;margin-bottom:14pt">';
    $html .= '<table style="width:100%;border-collapse:collapse"><tr>';
    $html .= '<td style="width:70%;vertical-align:top">';
    if ($logo_b64) $html .= '<img src="data:' . $logo_mime . ';base64,' . $logo_b64 . '" style="height:30pt;margin-bottom:4pt"/><br/>';
    $html .= '<span style="font-size:11pt;font-weight:bold;text-transform:uppercase">' . htmlspecialchars($org_name) . '</span><br/>';
    if ($meta) $html .= '<span style="font-size:8pt;color:#444;white-space:pre-line">' . nl2br(htmlspecialchars($meta)) . '</span>';
    $html .= '</td>';
    $html .= '<td style="text-align:right;font-size:8pt;color:#555;vertical-align:top">';
    if ($doc_ref) $html .= htmlspecialchars($doc_ref) . '<br/>';
    $html .= ($org_city ? htmlspecialchars($org_city) . ', ' : '') . 'dnia ' . $doc_date;
    $html .= '</td></tr></table></div>';

    return $html;
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
