<?php
/**
 * includes/vlab_contracts.php — Umowy o dostęp do VLab.
 *
 * Tabela:
 *   k30_vlab_contracts — umowy VLab powiązane z kursantami
 *
 * Numeracja: VL/ddmmrr/nnn  (np. VL/260625/001)
 *   ddmmrr = data zawarcia w formacie dzień-miesiąc-rok-2cyfry
 *   nnn    = numer kolejny umów z tego dnia (001, 002 …)
 */

const VLAB_CONTRACT_STATUSES = [
    'projekt'      => ['label' => 'Projekt',      'class' => 'secondary'],
    'do podpisu'   => ['label' => 'Do podpisu',   'class' => 'warning'],
    'aktywna'      => ['label' => 'Aktywna',       'class' => 'success'],
    'zawieszona'   => ['label' => 'Zawieszona',    'class' => 'orange'],
    'zakończona'   => ['label' => 'Zakończona',    'class' => 'primary'],
    'anulowana'    => ['label' => 'Anulowana',     'class' => 'danger'],
];

function vlab_contracts_migrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;

    db()->exec("CREATE TABLE IF NOT EXISTS k30_vlab_contracts (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        numer_umowy     TEXT    NOT NULL UNIQUE,
        client_id       INTEGER NOT NULL REFERENCES k30_clients(id) ON DELETE RESTRICT,
        account_id      INTEGER REFERENCES k30_ti_student_accounts(id) ON DELETE SET NULL,
        status          TEXT    NOT NULL DEFAULT 'projekt',
        data_zawarcia   DATE    NOT NULL DEFAULT (date('now')),
        data_waznosci   DATE,
        cel_dostepu     TEXT    NOT NULL DEFAULT '',
        warunki_html    TEXT    NOT NULL DEFAULT '',
        podpisana_przez TEXT    NOT NULL DEFAULT '',
        uwagi           TEXT    NOT NULL DEFAULT '',
        created_by      INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    db()->exec("CREATE INDEX IF NOT EXISTS idx_vlab_contracts_client ON k30_vlab_contracts(client_id)");
    db()->exec("CREATE INDEX IF NOT EXISTS idx_vlab_contracts_status ON k30_vlab_contracts(status)");
}

/** Generuje numer umowy VL/ddmmrr/nnn dla podanej daty zawarcia. */
function vlab_next_number(string $data_zawarcia = ''): string {
    if ($data_zawarcia === '') $data_zawarcia = date('Y-m-d');
    $ts     = strtotime($data_zawarcia) ?: time();
    $suffix = date('dmy', $ts); // ddmmrr
    $prefix = 'VL/' . $suffix . '/';
    $row    = db_one(
        "SELECT COUNT(*) AS cnt FROM k30_vlab_contracts WHERE numer_umowy LIKE ?",
        [$prefix . '%']
    );
    $n = ($row['cnt'] ?? 0) + 1;
    return $prefix . sprintf('%03d', $n);
}

/** Pobierz wszystkie umowy (z danymi kursanta). */
function vlab_contracts_all(array $filters = []): array {
    vlab_contracts_migrate();
    $where = ['1=1'];
    $params = [];

    if (!empty($filters['status'])) {
        $where[] = 'vc.status=?';
        $params[] = $filters['status'];
    }
    if (!empty($filters['client_id'])) {
        $where[] = 'vc.client_id=?';
        $params[] = (int)$filters['client_id'];
    }
    if (!empty($filters['q'])) {
        $where[] = '(c.name LIKE ? OR vc.numer_umowy LIKE ?)';
        $params[] = '%' . $filters['q'] . '%';
        $params[] = '%' . $filters['q'] . '%';
    }

    return db_all(
        "SELECT vc.*, c.name AS client_name, c.email AS client_email,
                a.login AS account_login
         FROM k30_vlab_contracts vc
         JOIN k30_clients c ON c.id=vc.client_id
         LEFT JOIN k30_ti_student_accounts a ON a.id=vc.account_id
         WHERE " . implode(' AND ', $where) . "
         ORDER BY vc.created_at DESC",
        $params
    );
}

/** Pobierz jedną umowę po ID. */
function vlab_contract_get(int $id): ?array {
    vlab_contracts_migrate();
    return db_one(
        "SELECT vc.*, c.name AS client_name, c.email AS client_email,
                a.login AS account_login
         FROM k30_vlab_contracts vc
         JOIN k30_clients c ON c.id=vc.client_id
         LEFT JOIN k30_ti_student_accounts a ON a.id=vc.account_id
         WHERE vc.id=?",
        [$id]
    ) ?: null;
}

/** Pobierz umowy dla kursanta (do panelu kursanta). */
function vlab_contracts_for_client(int $client_id): array {
    vlab_contracts_migrate();
    return db_all(
        "SELECT * FROM k30_vlab_contracts WHERE client_id=? ORDER BY created_at DESC",
        [$client_id]
    );
}

/** Utwórz nową umowę. Zwraca ID. */
function vlab_contract_create(array $data, int $user_id): int {
    vlab_contracts_migrate();
    $data_zawarcia = $data['data_zawarcia'] ?? date('Y-m-d');
    $numer = vlab_next_number($data_zawarcia);

    return db_insert('k30_vlab_contracts', [
        'numer_umowy'     => $numer,
        'client_id'       => (int)$data['client_id'],
        'account_id'      => !empty($data['account_id']) ? (int)$data['account_id'] : null,
        'status'          => $data['status'] ?? 'projekt',
        'data_zawarcia'   => $data_zawarcia,
        'data_waznosci'   => $data['data_waznosci'] ?: null,
        'cel_dostepu'     => $data['cel_dostepu'] ?? '',
        'warunki_html'    => $data['warunki_html'] ?? '',
        'podpisana_przez' => $data['podpisana_przez'] ?? '',
        'uwagi'           => $data['uwagi'] ?? '',
        'created_by'      => $user_id,
    ]);
}

/** Zaktualizuj umowę. */
function vlab_contract_update(int $id, array $data): void {
    vlab_contracts_migrate();
    $allowed = ['status','data_zawarcia','data_waznosci','cel_dostepu','warunki_html','podpisana_przez','uwagi','account_id'];
    $set = [];
    $params = [];
    foreach ($allowed as $col) {
        if (array_key_exists($col, $data)) {
            $set[] = "$col=?";
            $params[] = ($data[$col] === '' && in_array($col, ['data_waznosci','account_id'])) ? null : $data[$col];
        }
    }
    if (!$set) return;
    $set[] = "updated_at=datetime('now')";
    $params[] = $id;
    db()->prepare("UPDATE k30_vlab_contracts SET " . implode(',', $set) . " WHERE id=?")->execute($params);
}

/** Generuj PDF umowy i wyślij do przeglądarki. Kończy skrypt. */
function vlab_contract_pdf(array $contract): void {
    require_once __DIR__ . '/fpdf/fpdf.php';
    require_once __DIR__ . '/fpdi/autoload_fpdi.php';

    $pdf = new \setasign\Fpdi\Fpdi('P', 'mm', 'A4');
    $pdf->SetAutoPageBreak(true, 20);
    $pdf->SetMargins(20, 20, 20);
    $font_dir = __DIR__ . '/fpdf/font/';
    $pdf->AddFont('DejaVu', '',  'dejavusans.json',  $font_dir);
    $pdf->AddFont('DejaVu', 'B', 'dejavusansb.json', $font_dir);
    $pdf->AddPage();

    $W   = $pdf->GetPageWidth() - 40;
    $org = defined('ORG_NAME') ? ORG_NAME : '';

    // Nagłówek
    $pdf->SetFillColor(15, 80, 150);
    $pdf->Rect(20, $pdf->GetY(), $W, 15, 'F');
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('DejaVu', 'B', 13);
    $pdf->Cell($W, 15, _vc_txt('Umowa o dostęp do VLab'), 0, 1, 'C');
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Ln(4);

    // Numer + org
    $pdf->SetFont('DejaVu', 'B', 11);
    $pdf->Cell($W, 7, _vc_txt($contract['numer_umowy']), 0, 1, 'C');
    $pdf->SetFont('DejaVu', '', 9);
    $pdf->SetTextColor(80, 80, 80);
    $pdf->Cell($W, 5, _vc_txt($org), 0, 1, 'C');
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Ln(4);

    // Ramka danych
    $pdf->SetFillColor(245, 247, 250);
    $pdf->SetDrawColor(200, 210, 220);
    $row_h = 6;
    $rows = [
        ['Kursant',           $contract['client_name'] ?? ''],
        ['Konto VLab',        $contract['account_login'] ?? '—'],
        ['Data zawarcia',     $contract['data_zawarcia'] ? date('d.m.Y', strtotime($contract['data_zawarcia'])) : ''],
        ['Ważność umowy',     $contract['data_waznosci'] ? date('d.m.Y', strtotime($contract['data_waznosci'])) : 'bezterminowa'],
        ['Status',            VLAB_CONTRACT_STATUSES[$contract['status']]['label'] ?? $contract['status']],
        ['Cel dostępu',       $contract['cel_dostepu'] ?? ''],
        ['Podpisana przez',   $contract['podpisana_przez'] ?? ''],
    ];
    $box_h = count($rows) * $row_h + 8;
    $pdf->Rect(20, $pdf->GetY(), $W, $box_h, 'FD');
    $y0 = $pdf->GetY() + 4;
    foreach ($rows as [$label, $val]) {
        $pdf->SetXY(24, $y0);
        $pdf->SetFont('DejaVu', 'B', 9);
        $pdf->Cell(46, $row_h, _vc_txt($label . ':'), 0, 0);
        $pdf->SetFont('DejaVu', '', 9);
        $pdf->Cell($W - 50, $row_h, _vc_txt((string)$val), 0, 0);
        $y0 += $row_h;
    }
    $pdf->SetXY(20, $y0 + 4);
    $pdf->Ln(6);

    // Treść warunków
    if (!empty($contract['warunki_html'])) {
        $pdf->SetFont('DejaVu', 'B', 10);
        $pdf->Cell($W, 6, _vc_txt('Warunki dostępu:'), 0, 1);
        $pdf->SetFont('DejaVu', '', 8.5);
        $pdf->SetTextColor(40, 40, 40);
        foreach (explode("\n", _vc_html_to_plain($contract['warunki_html'])) as $line) {
            $line = trim($line);
            if ($line === '') { $pdf->Ln(2); continue; }
            $pdf->MultiCell($W, 5, _vc_txt($line), 0, 'L');
        }
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(4);
    }

    // Podpisy
    $pdf->Ln(10);
    $pdf->SetFont('DejaVu', '', 9);
    $half = ($W - 20) / 2;
    $y_sig = $pdf->GetY();
    $pdf->SetXY(20, $y_sig);
    $pdf->Cell($half, 5, _vc_txt('Organizacja / Zlecający'), 0, 0, 'C');
    $pdf->Cell(20, 5, '', 0, 0);
    $pdf->Cell($half, 5, _vc_txt('Kursant'), 0, 1, 'C');
    $pdf->Ln(10);
    $pdf->SetXY(20, $pdf->GetY());
    $pdf->Cell($half, 0.5, '', 'B', 0, 'C');
    $pdf->Cell(20, 0.5, '', 0, 0);
    $pdf->Cell($half, 0.5, '', 'B', 1, 'C');

    // Stopka
    $pdf->Ln(8);
    $pdf->SetFont('DejaVu', '', 7.5);
    $pdf->SetTextColor(130, 130, 130);
    $pdf->Cell($W, 5, _vc_txt('Wygenerowano automatycznie · ' . $org . ' · ' . date('d.m.Y H:i')), 0, 1, 'C');

    $fname = 'umowa_vlab_' . preg_replace('/[^a-z0-9_]/i', '_', $contract['numer_umowy']) . '.pdf';
    $pdf->Output('D', $fname);
    exit;
}

function _vc_txt(string $s): string {
    return iconv('UTF-8', 'ISO-8859-2//TRANSLIT//IGNORE', $s) ?: $s;
}
function _vc_html_to_plain(string $html): string {
    $html = preg_replace('#<br\s*/?>|</p>|</li>|</h[1-6]>|</div>#i', "\n", $html);
    $html = preg_replace('#<li[^>]*>#i', '• ', $html);
    $html = strip_tags($html);
    $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace('/\n{3,}/', "\n\n", $html));
}
