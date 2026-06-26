<?php
/**
 * includes/panel_terms.php — Regulamin panelu SZO (akceptacja przez użytkowników).
 *
 * Tabele:
 *   panel_terms          — treść regulaminu (jeden aktywny wpis)
 *   panel_terms_accepts  — log akceptacji użytkowników (user_id → users)
 */

function panel_terms_migrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;

    db()->exec("CREATE TABLE IF NOT EXISTS panel_terms (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        title      TEXT    NOT NULL DEFAULT 'Regulamin panelu',
        body_html  TEXT    NOT NULL DEFAULT '',
        version    INTEGER NOT NULL DEFAULT 1,
        is_active  INTEGER NOT NULL DEFAULT 0,
        updated_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    db()->exec("CREATE TABLE IF NOT EXISTS panel_terms_accepts (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        term_id     INTEGER NOT NULL REFERENCES panel_terms(id) ON DELETE CASCADE,
        user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        version     INTEGER NOT NULL DEFAULT 1,
        ip          TEXT    NOT NULL DEFAULT '',
        ua          TEXT    NOT NULL DEFAULT '',
        accepted_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    db()->exec("CREATE INDEX IF NOT EXISTS idx_panel_terms_acc ON panel_terms_accepts(user_id, term_id)");

    // Utwórz domyślny wpis jeśli nie istnieje
    $ex = db_one("SELECT id FROM panel_terms LIMIT 1");
    if (!$ex) {
        db_insert('panel_terms', [
            'title'     => 'Regulamin panelu',
            'body_html' => '<p>Treść regulaminu zostanie uzupełniona przez administratora.</p>',
            'is_active' => 0,
        ]);
    }
}

/** Pobierz aktywny regulamin panelu (lub null gdy brak/nieaktywny). */
function panel_term_get(): ?array {
    panel_terms_migrate();
    return db_one("SELECT * FROM panel_terms ORDER BY id DESC LIMIT 1") ?: null;
}

/** Czy użytkownik zaakceptował aktualną wersję regulaminu? */
function panel_term_accepted(int $user_id): bool {
    panel_terms_migrate();
    $term = db_one("SELECT id, version, is_active FROM panel_terms ORDER BY id DESC LIMIT 1");
    if (!$term || empty($term['is_active'])) return true;
    $acc = db_one(
        "SELECT id FROM panel_terms_accepts WHERE user_id=? AND term_id=? AND version>=?",
        [$user_id, (int)$term['id'], (int)$term['version']]
    );
    return $acc !== null;
}

/** Zapisz akceptację przez użytkownika. */
function panel_term_accept(int $user_id, int $term_id): void {
    panel_terms_migrate();
    $term = db_one("SELECT version FROM panel_terms WHERE id=?", [$term_id]);
    if (!$term) return;
    db()->prepare("DELETE FROM panel_terms_accepts WHERE user_id=? AND term_id=?")->execute([$user_id, $term_id]);
    db_insert('panel_terms_accepts', [
        'term_id'  => $term_id,
        'user_id'  => $user_id,
        'version'  => (int)$term['version'],
        'ip'       => substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45),
        'ua'       => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 300),
    ]);
}

/** Historia akceptacji dla użytkownika. */
function panel_terms_accepts_for_user(int $user_id): array {
    panel_terms_migrate();
    return db_all(
        "SELECT a.*, t.title, t.body_html
         FROM panel_terms_accepts a
         JOIN panel_terms t ON t.id=a.term_id
         WHERE a.user_id=?
         ORDER BY a.accepted_at DESC",
        [$user_id]
    );
}

/** Pobierz konkretny rekord akceptacji (dla PDF). */
function panel_term_accept_get(int $accept_id, int $user_id): ?array {
    panel_terms_migrate();
    return db_one(
        "SELECT a.*, t.title, t.body_html, t.version AS term_version
         FROM panel_terms_accepts a
         JOIN panel_terms t ON t.id=a.term_id
         WHERE a.id=? AND a.user_id=?",
        [$accept_id, $user_id]
    ) ?: null;
}

/** Zaktualizuj treść regulaminu (admin). Automatycznie inkrementuje wersję. */
function panel_term_save(string $title, string $body_html, int $is_active, int $user_id): void {
    panel_terms_migrate();
    $existing = db_one("SELECT id, version, body_html FROM panel_terms ORDER BY id DESC LIMIT 1");
    if (!$existing) return;

    $new_version = (int)$existing['version'];
    if (trim($existing['body_html'] ?? '') !== trim($body_html)) {
        $new_version++;
    }

    db()->prepare(
        "UPDATE panel_terms SET title=?, body_html=?, is_active=?, version=?, updated_by=?, updated_at=datetime('now') WHERE id=?"
    )->execute([$title, $body_html, $is_active ? 1 : 0, $new_version, $user_id, (int)$existing['id']]);
}

/** Generuj i wyślij PDF potwierdzenia akceptacji. Kończy skrypt. */
function panel_term_pdf(array $accept, array $user): void {
    require_once __DIR__ . '/fpdf/fpdf.php';
    require_once __DIR__ . '/fpdi/autoload_fpdi.php';

    $pdf = new \setasign\Fpdi\Fpdi('P', 'mm', 'A4');
    $pdf->SetAutoPageBreak(true, 20);
    $pdf->SetMargins(20, 20, 20);
    $font_dir = __DIR__ . '/fpdf/font/';
    $pdf->AddFont('DejaVu', '',  'dejavusans.json',  $font_dir);
    $pdf->AddFont('DejaVu', 'B', 'dejavusansb.json', $font_dir);

    $pdf->AddPage();
    $W = $pdf->GetPageWidth() - 40;
    $org = defined('ORG_NAME') ? ORG_NAME : '';

    $pdf->SetFillColor(30, 64, 120);
    $pdf->Rect(20, $pdf->GetY(), $W, 14, 'F');
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('DejaVu', 'B', 13);
    $pdf->Cell($W, 14, _pt_txt('Potwierdzenie akceptacji regulaminu panelu'), 0, 1, 'C', false);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Ln(4);

    $pdf->SetFont('DejaVu', 'B', 10);
    $pdf->Cell($W, 6, _pt_txt($accept['title']), 0, 1);
    $pdf->SetFont('DejaVu', '', 9);
    $pdf->SetTextColor(80, 80, 80);
    $pdf->Cell($W, 5, _pt_txt($org), 0, 1);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Ln(3);

    $pdf->SetFillColor(245, 247, 250);
    $pdf->SetDrawColor(200, 210, 220);
    $pdf->Rect(20, $pdf->GetY(), $W, 23, 'FD');
    $pdf->SetXY(24, $pdf->GetY() + 4);
    $rows = [
        ['Użytkownik', ($user['name'] ?? '') . ' <' . ($user['email'] ?? '') . '>'],
        ['Data i czas', date('d.m.Y H:i:s', strtotime($accept['accepted_at']))],
        ['Adres IP',    $accept['ip'] ?? ''],
        ['Wersja reg.', 'v' . ($accept['version'] ?? 1)],
    ];
    foreach ($rows as [$label, $val]) {
        $pdf->SetXY(24, $pdf->GetY());
        $pdf->SetFont('DejaVu', 'B', 9);
        $pdf->Cell(38, 5.5, _pt_txt($label . ':'), 0, 0);
        $pdf->SetFont('DejaVu', '', 9);
        $pdf->Cell($W - 42, 5.5, _pt_txt($val), 0, 1);
    }
    $pdf->Ln(6);

    $pdf->SetFont('DejaVu', 'B', 10);
    $pdf->Cell($W, 6, _pt_txt('Treść regulaminu (zaakceptowana wersja):'), 0, 1);
    $pdf->SetFont('DejaVu', '', 8.5);
    $pdf->SetTextColor(40, 40, 40);

    $plain = _pt_html_to_plain($accept['body_html']);
    foreach (explode("\n", $plain) as $line) {
        $line = trim($line);
        if ($line === '') { $pdf->Ln(2); continue; }
        $pdf->MultiCell($W, 5, _pt_txt($line), 0, 'L');
    }

    $pdf->SetTextColor(0,0,0);
    $pdf->Ln(6);
    $pdf->SetFont('DejaVu', '', 7.5);
    $pdf->SetTextColor(130, 130, 130);
    $pdf->Cell($W, 5, _pt_txt('Dokument wygenerowany automatycznie · ' . $org . ' · ' . date('d.m.Y H:i')), 0, 1, 'C');

    $fname = 'regulamin_panelu_' . date('Ymd', strtotime($accept['accepted_at'])) . '.pdf';
    $pdf->Output('D', $fname);
    exit;
}

function _pt_txt(string $s): string {
    return iconv('UTF-8', 'ISO-8859-2//TRANSLIT//IGNORE', $s) ?: $s;
}

function _pt_html_to_plain(string $html): string {
    $html = preg_replace('#<br\s*/?>|</p>|</li>|</h[1-6]>|</div>#i', "\n", $html);
    $html = preg_replace('#<li[^>]*>#i', '• ', $html);
    $html = strip_tags($html);
    $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $html = preg_replace('/\n{3,}/', "\n\n", $html);
    return trim($html);
}
