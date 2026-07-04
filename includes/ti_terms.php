<?php
/**
 * includes/ti_terms.php — Regulaminy TI (szkolenia + vLAB).
 *
 * Tabele:
 *   k30_ti_terms          — treści regulaminów (typ + HTML + wersja)
 *   k30_ti_terms_accepts  — log akceptacji kursantów (data, IP, treść skrót)
 */

const TI_TERM_TYPES = [
    'szkolenia' => 'Regulamin szkoleń',
    'vlab'      => 'Regulamin vLAB',
    'licencje'  => 'Oświadczenie o poufności — Licencje',
];

function ti_terms_migrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;

    db()->exec("CREATE TABLE IF NOT EXISTS k30_ti_terms (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        type       TEXT    NOT NULL UNIQUE,
        title      TEXT    NOT NULL DEFAULT '',
        body_html  TEXT    NOT NULL DEFAULT '',
        version    INTEGER NOT NULL DEFAULT 1,
        is_active  INTEGER NOT NULL DEFAULT 1,
        updated_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    db()->exec("CREATE TABLE IF NOT EXISTS k30_ti_terms_accepts (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        term_id    INTEGER NOT NULL REFERENCES k30_ti_terms(id) ON DELETE CASCADE,
        client_id  INTEGER NOT NULL REFERENCES k30_clients(id) ON DELETE CASCADE,
        account_id INTEGER REFERENCES k30_ti_student_accounts(id) ON DELETE SET NULL,
        version    INTEGER NOT NULL DEFAULT 1,
        ip         TEXT    NOT NULL DEFAULT '',
        ua         TEXT    NOT NULL DEFAULT '',
        accepted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        accepted_by_role TEXT NOT NULL DEFAULT 'kursant' -- 'kursant' | 'rodzic' — kto faktycznie zaakceptował
    )");
    try { db()->exec("ALTER TABLE k30_ti_terms_accepts ADD COLUMN accepted_by_role TEXT NOT NULL DEFAULT 'kursant'"); } catch (\Throwable $e) {}

    db()->exec("CREATE INDEX IF NOT EXISTS idx_ti_terms_acc ON k30_ti_terms_accepts(client_id, term_id)");

    // Utwórz domyślne wpisy regulaminów jeśli nie istnieją
    foreach (TI_TERM_TYPES as $type => $title) {
        $ex = db_one("SELECT id FROM k30_ti_terms WHERE type=?", [$type]);
        if (!$ex) {
            $body_html = '<p>Treść regulaminu zostanie uzupełniona przez administratora.</p>';
            $is_active = 0;
            if ($type === 'licencje') {
                $body_html = '<p>Oświadczam, że dane logowania oraz klucze licencyjne udostępnione mi w module '
                    . 'Licencje są przeznaczone wyłącznie do mojego osobistego użytku w ramach zajęć i projektów '
                    . 'realizowanych z Fundacją. Zobowiązuję się:</p>'
                    . '<ul>'
                    . '<li>nie udostępniać loginów, haseł ani kluczy licencyjnych osobom trzecim,</li>'
                    . '<li>nie publikować ich w internecie (repozytoria, fora, media społecznościowe),</li>'
                    . '<li>korzystać z licencjonowanego oprogramowania zgodnie z warunkami licencji producenta,</li>'
                    . '<li>niezwłocznie poinformować administratora o podejrzeniu wycieku lub nieautoryzowanego użycia danych.</li>'
                    . '</ul>'
                    . '<p>Naruszenie poufności może skutkować odebraniem dostępu do licencji.</p>';
                $is_active = 1;
            }
            db_insert('k30_ti_terms', [
                'type'      => $type,
                'title'     => $title,
                'body_html' => $body_html,
                'is_active' => $is_active,
            ]);
        }
    }
}

/** Pobierz regulamin wg typu. */
function ti_term_get(string $type): ?array {
    ti_terms_migrate();
    return db_one("SELECT * FROM k30_ti_terms WHERE type=?", [$type]) ?: null;
}

/** Pobierz wszystkie regulaminy. */
function ti_terms_all(): array {
    ti_terms_migrate();
    return db_all("SELECT t.*, COALESCE(NULLIF(TRIM(u.first_name||' '||u.last_name),''), u.name) AS editor_name
                   FROM k30_ti_terms t
                   LEFT JOIN users u ON u.id=t.updated_by
                   ORDER BY t.type");
}

/** Czy kursant zaakceptował aktualną wersję regulaminu danego typu? */
function ti_term_accepted(int $client_id, string $type): bool {
    ti_terms_migrate();
    $term = db_one("SELECT id, version, is_active FROM k30_ti_terms WHERE type=?", [$type]);
    if (!$term || empty($term['is_active'])) return true; // nieaktywny = nie wymaga akceptacji
    $acc = db_one(
        "SELECT id FROM k30_ti_terms_accepts WHERE client_id=? AND term_id=? AND version>=?",
        [$client_id, (int)$term['id'], (int)$term['version']]
    );
    return $acc !== null;
}

/** Wszystkie nieakceptowane aktywne regulaminy dla kursanta. Zwraca tablicę wpisów k30_ti_terms. */
function ti_terms_pending(int $client_id): array {
    ti_terms_migrate();
    $all = db_all("SELECT * FROM k30_ti_terms WHERE is_active=1 ORDER BY type");
    $pending = [];
    foreach ($all as $t) {
        $acc = db_one(
            "SELECT id FROM k30_ti_terms_accepts WHERE client_id=? AND term_id=? AND version>=?",
            [$client_id, (int)$t['id'], (int)$t['version']]
        );
        if (!$acc) $pending[] = $t;
    }
    return $pending;
}

/**
 * Zapisz akceptację regulaminu. $role rozróżnia kto faktycznie kliknął „akceptuję":
 * 'kursant' (domyślnie) — sam kursant, we własnym panelu; 'rodzic' — opiekun w panelu rodzica
 * (wymagane dla regulaminów, które u małoletnich musi zaakceptować opiekun, np. VLab).
 */
function ti_term_accept(int $client_id, int $term_id, int $account_id, string $role = 'kursant'): void {
    ti_terms_migrate();
    $term = db_one("SELECT version FROM k30_ti_terms WHERE id=?", [$term_id]);
    if (!$term) return;
    // Usuń starsze akceptacje tego regulaminu (unikamy duplikatów)
    db()->prepare("DELETE FROM k30_ti_terms_accepts WHERE client_id=? AND term_id=?")->execute([$client_id, $term_id]);
    db_insert('k30_ti_terms_accepts', [
        'term_id'    => $term_id,
        'client_id'  => $client_id,
        'account_id' => $account_id,
        'version'    => (int)$term['version'],
        'ip'         => substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45),
        'ua'         => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 300),
        'accepted_by_role' => $role === 'rodzic' ? 'rodzic' : 'kursant',
    ]);
}

/** Historia akceptacji regulaminów kursanta (do zakładki + PDF). */
function ti_terms_accepts_for_client(int $client_id): array {
    ti_terms_migrate();
    return db_all(
        "SELECT a.*, t.type, t.title, t.body_html
         FROM k30_ti_terms_accepts a
         JOIN k30_ti_terms t ON t.id=a.term_id
         WHERE a.client_id=?
         ORDER BY a.accepted_at DESC",
        [$client_id]
    );
}

/** Pobierz konkretny rekord akceptacji (dla PDF). */
function ti_terms_accept_get(int $accept_id, int $client_id): ?array {
    ti_terms_migrate();
    return db_one(
        "SELECT a.*, t.type, t.title, t.body_html, t.version AS term_version
         FROM k30_ti_terms_accepts a
         JOIN k30_ti_terms t ON t.id=a.term_id
         WHERE a.id=? AND a.client_id=?",
        [$accept_id, $client_id]
    ) ?: null;
}

/** Zaktualizuj treść regulaminu (admin). Automatycznie inkrementuje wersję. */
function ti_term_save(string $type, string $title, string $body_html, int $is_active, int $user_id): void {
    ti_terms_migrate();
    $existing = db_one("SELECT id, version FROM k30_ti_terms WHERE type=?", [$type]);
    if (!$existing) return;

    // Inkrementuj wersję tylko jeśli treść się zmieniła
    $old = db_one("SELECT body_html FROM k30_ti_terms WHERE type=?", [$type]);
    $new_version = (int)$existing['version'];
    if (trim($old['body_html'] ?? '') !== trim($body_html)) {
        $new_version++;
    }

    db()->prepare(
        "UPDATE k30_ti_terms SET title=?, body_html=?, is_active=?, version=?, updated_by=?, updated_at=datetime('now') WHERE type=?"
    )->execute([$title, $body_html, $is_active ? 1 : 0, $new_version, $user_id, $type]);
}

/** Generuj i wyślij PDF potwierdzenia akceptacji. Kończy skrypt. */
function ti_term_pdf(array $accept, array $client): void {
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

    // Nagłówek
    $pdf->SetFillColor(30, 64, 120);
    $pdf->Rect(20, $pdf->GetY(), $W, 14, 'F');
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('DejaVu', 'B', 13);
    $pdf->Cell($W, 14, _ti_pdf_txt('Potwierdzenie akceptacji regulaminu'), 0, 1, 'C', false);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Ln(4);

    // Blok danych
    $pdf->SetFont('DejaVu', 'B', 10);
    $pdf->Cell($W, 6, _ti_pdf_txt($accept['title']), 0, 1);
    $pdf->SetFont('DejaVu', '', 9);
    $pdf->SetTextColor(80, 80, 80);
    $pdf->Cell($W, 5, _ti_pdf_txt($org), 0, 1);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Ln(3);

    // Ramka z danymi akceptacji
    $pdf->SetFillColor(245, 247, 250);
    $pdf->SetDrawColor(200, 210, 220);
    $pdf->Rect(20, $pdf->GetY(), $W, 28, 'FD');
    $y0 = $pdf->GetY() + 4;
    $pdf->SetXY(24, $y0);
    $rows = [
        ['Kursant',    $client['name'] ?? ''],
        ['Data i czas', date('d.m.Y H:i:s', strtotime($accept['accepted_at']))],
        ['Adres IP',   $accept['ip'] ?? ''],
        ['Wersja reg.', 'v' . ($accept['version'] ?? 1)],
    ];
    $pdf->SetFont('DejaVu', '', 9);
    foreach ($rows as [$label, $val]) {
        $pdf->SetXY(24, $pdf->GetY());
        $pdf->SetFont('DejaVu', 'B', 9);
        $pdf->Cell(38, 5.5, _ti_pdf_txt($label . ':'), 0, 0);
        $pdf->SetFont('DejaVu', '', 9);
        $pdf->Cell($W - 42, 5.5, _ti_pdf_txt($val), 0, 1);
    }
    $pdf->Ln(6);

    // Treść regulaminu (HTML strip → akapity)
    $pdf->SetFont('DejaVu', 'B', 10);
    $pdf->Cell($W, 6, _ti_pdf_txt('Treść regulaminu (zaakceptowana wersja):'), 0, 1);
    $pdf->SetFont('DejaVu', '', 8.5);
    $pdf->SetTextColor(40, 40, 40);

    $plain = _ti_html_to_plain($accept['body_html']);
    $lines = explode("\n", $plain);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') { $pdf->Ln(2); continue; }
        $pdf->MultiCell($W, 5, _ti_pdf_txt($line), 0, 'L');
    }

    $pdf->SetTextColor(0,0,0);
    $pdf->Ln(6);

    // Stopka
    $pdf->SetFont('DejaVu', '', 7.5);
    $pdf->SetTextColor(130, 130, 130);
    $pdf->Cell($W, 5, _ti_pdf_txt('Dokument wygenerowany automatycznie · ' . $org . ' · ' . date('d.m.Y H:i')), 0, 1, 'C');

    $fname = 'regulamin_' . preg_replace('/[^a-z0-9_]/i', '_', $accept['type']) . '_' . date('Ymd', strtotime($accept['accepted_at'])) . '.pdf';
    $pdf->Output('D', $fname);
    exit;
}

function _ti_pdf_txt(string $s): string {
    return iconv('UTF-8', 'ISO-8859-2//TRANSLIT//IGNORE', $s) ?: $s;
}

function _ti_html_to_plain(string $html): string {
    // Znaczniki blokowe → nowe linie
    $html = preg_replace('#<br\s*/?>|</p>|</li>|</h[1-6]>|</div>#i', "\n", $html);
    $html = preg_replace('#<li[^>]*>#i', '• ', $html);
    $html = strip_tags($html);
    $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $html = preg_replace('/\n{3,}/', "\n\n", $html);
    return trim($html);
}

/**
 * Renderuje blok akceptacji regulaminu (formularz z treścią do przeczytania).
 * Używane na zakładce regulaminy, vlab i online w panelu kursanta.
 */
function _ti_terms_acceptance_block(?array $term, string $token, string $redirect_tab): string {
    if (!$term) return '';
    ob_start(); ?>
    <div class="card border-warning mb-4 shadow-sm">
      <div class="card-header bg-warning bg-opacity-10 d-flex align-items-center gap-2">
        <i class="bi bi-file-earmark-text text-warning fs-5" aria-hidden="true"></i>
        <strong><?= h($term['title']) ?></strong>
        <span class="badge text-bg-secondary ms-auto">v<?= (int)$term['version'] ?></span>
      </div>
      <div class="card-body">
        <div class="border rounded p-3 mb-3 small"
             style="max-height:320px;overflow-y:auto;background:var(--bs-tertiary-bg)"
             tabindex="0" aria-label="Treść regulaminu <?= h($term['title']) ?>">
          <?= $term['body_html'] ?>
        </div>
        <form method="post">
          <input type="hidden" name="_token" value="<?= h($token) ?>">
          <input type="hidden" name="_op" value="accept_term">
          <input type="hidden" name="term_id" value="<?= (int)$term['id'] ?>">
          <input type="hidden" name="redirect_tab" value="<?= h($redirect_tab) ?>">
          <div class="form-check mb-3">
            <input type="checkbox" class="form-check-input" id="accept-cb-<?= (int)$term['id'] ?>" required>
            <label class="form-check-label" for="accept-cb-<?= (int)$term['id'] ?>">
              Przeczytałem/am i akceptuję powyższy regulamin
            </label>
          </div>
          <button type="submit" class="btn btn-primary">
            <i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Zaakceptuj regulamin
          </button>
        </form>
      </div>
    </div>
    <?php
    return ob_get_clean();
}

