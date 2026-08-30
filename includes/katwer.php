<?php
/**
 * includes/katwer.php
 * Moduł KATWER — Karta Weryfikacji Danych.
 *
 * Odpowiada TYLKO za: pilnowanie aktualności danych umowy (kiedy ostatnio
 * potwierdzone, czy przeterminowane) i nadawanie numeru karty. Samo
 * generowanie/edycję/eksport dokumentu robi generyczny silnik
 * includes/contract_document_engine.php (cgd_*) — używany przez KAŻDY
 * wzór, nie tylko Kartę Weryfikacji. Ten plik jest wołany PRZEZ ten silnik
 * (cgd_create()) gdy wzór jest oznaczony contract_doc_templates.verifies_data=1.
 */
require_once __DIR__ . '/db.php';

function katwer_migrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;

    db()->exec("CREATE TABLE IF NOT EXISTS contract_data_verifications (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        contract_type TEXT     NOT NULL,
        contract_id   INTEGER  NOT NULL,
        verified_at   DATETIME NOT NULL,
        document_id   INTEGER  NULL,
        UNIQUE(contract_type, contract_id)
    )");
}

/** Ile miesięcy od potwierdzenia danych uznajemy je za nadal aktualne. */
const KATWER_VALIDITY_MONTHS = 6;

/** Tekst wstawiany zamiast placeholdera, gdy dla pola nie ma danych w systemie. */
const KATWER_NO_DATA_LABEL = 'Brak danych w systemie';

/**
 * Generuje unikalny numer Karty Weryfikacji Danych: EZD-KaWer/{6 cyfr}{3 litery}.
 * Sprawdza unikalność w bazie (kolizja losowa jest bliska zeru, ale i tak
 * zabezpieczamy się przed nią pętlą).
 */
function katwer_generate_numer(): string {
    do {
        $digits  = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $letters = '';
        for ($i = 0; $i < 3; $i++) $letters .= chr(random_int(65, 90));
        $numer = 'EZD-KaWer/' . $digits . $letters;
    } while (db_one("SELECT id FROM contract_documents WHERE nr_karty=?", [$numer]));
    return $numer;
}

/**
 * Zapisuje/aktualizuje datę ostatniego potwierdzenia aktualności danych dla
 * umowy — wołane automatycznie z cgd_create() przy generowaniu dokumentu
 * z wzoru oznaczonego jako „potwierdzający dane".
 */
function katwer_mark_verified(string $contract_type, int $contract_id, int $document_id): void {
    katwer_migrate();
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
function katwer_status(string $contract_type, int $contract_id): array {
    katwer_migrate();
    $row = db_one(
        "SELECT verified_at FROM contract_data_verifications WHERE contract_type=? AND contract_id=?",
        [$contract_type, $contract_id]
    );
    if (!$row) return ['verified_at' => null, 'expires_at' => null, 'is_stale' => true];

    $expires_at = date('Y-m-d H:i:s', strtotime('+' . KATWER_VALIDITY_MONTHS . ' months', strtotime($row['verified_at'])));
    return [
        'verified_at' => $row['verified_at'],
        'expires_at'  => $expires_at,
        'is_stale'    => strtotime($expires_at) < time(),
    ];
}

/**
 * Znajduje aktywny wzór Karty Weryfikacji (verifies_data=1) pasujący do typu
 * umowy — preferuje wzór dedykowany typowi nad uniwersalnym. Używane przez
 * baner ostrzegawczy do zaproponowania szybkiego wygenerowania karty.
 */
function katwer_find_template(string $contract_type): ?array {
    return db_one(
        "SELECT * FROM contract_doc_templates
          WHERE is_active=1 AND verifies_data=1 AND (type=? OR type='universal')
          ORDER BY (type=?) DESC LIMIT 1",
        [$contract_type, $contract_type]
    ) ?: null;
}

/**
 * Baner „monit" — HTML ostrzeżenia gdy dane umowy nigdy nie były
 * zweryfikowane albo weryfikacja jest przeterminowana. Zwraca '' gdy dane są
 * aktualne (celowo ciche — monit ma reagować tylko na problem).
 */
function katwer_banner_html(string $contract_type, int $contract_id): string {
    $status = katwer_status($contract_type, $contract_id);
    if ($status['verified_at'] && !$status['is_stale']) return '';

    $tpl = katwer_find_template($contract_type);
    $never = !$status['verified_at'];

    $msg = $never
        ? 'Dane tej umowy nigdy nie zostały zweryfikowane Kartą Weryfikacji Danych.'
        : 'Dane tej umowy wymagają ponownej weryfikacji — ostatnie potwierdzenie: '
          . h(date_pl($status['verified_at'])) . ' (ważność: ' . KATWER_VALIDITY_MONTHS . ' mies.).';

    $html  = '<div class="alert alert-warning d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">';
    $html .= '<div><i class="bi bi-exclamation-triangle-fill me-2"></i>' . $msg
           . ' Zalecane przed wydrukiem/podpisaniem umowy.</div>';
    if ($tpl) {
        $html .= '<form method="post" action="' . h(APP_URL) . '/contracts/dokumenty/generuj.php" class="d-inline">'
               . '<input type="hidden" name="_csrf" value="' . csrf_token() . '">'
               . '<input type="hidden" name="template_id" value="' . (int)$tpl['id'] . '">'
               . '<input type="hidden" name="contract_type" value="' . h($contract_type) . '">'
               . '<input type="hidden" name="contract_id" value="' . (int)$contract_id . '">'
               . '<button class="btn btn-sm btn-warning text-nowrap">'
               . '<i class="bi bi-file-earmark-plus me-1"></i>Wygeneruj Kartę Weryfikacji</button>'
               . '</form>';
    }
    $html .= '</div>';
    return $html;
}
