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

/** Kto ma zatwierdzić regulamin (k30_ti_terms.audience, lista po przecinku; pusta = wszyscy). */
const TI_TERM_AUDIENCES = [
    'kursanci'    => 'Kursanci szkoleń (zwykłe grupy)',
    'szk'         => 'Uczestnicy szkoleń krótkich (SzK)',
    'konsultacje' => 'Beneficjenci konsultacji',
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
        accepted_by_role TEXT NOT NULL DEFAULT 'kursant', -- 'kursant' | 'rodzic' | 'admin_skip' | 'admin_remote' — kto faktycznie zaakceptował (admin_* = czynność administratora)
        admin_id   INTEGER REFERENCES users(id) ON DELETE SET NULL, -- administrator, gdy accepted_by_role zaczyna się od 'admin_'
        admin_note TEXT    NOT NULL DEFAULT '',   -- uzasadnienie/podstawa czynności administratora
        signed_file          TEXT NOT NULL DEFAULT '', -- nazwa pliku (UPLOAD_DIR/ti_terms_signed/) z podpisanym skanem oświadczenia admina
        signed_file_orig     TEXT NOT NULL DEFAULT '', -- oryginalna nazwa przesłanego pliku
        signed_uploaded_at   DATETIME
    )");
    try { db()->exec("ALTER TABLE k30_ti_terms_accepts ADD COLUMN accepted_by_role TEXT NOT NULL DEFAULT 'kursant'"); } catch (\Throwable $e) {}
    try { db()->exec("ALTER TABLE k30_ti_terms_accepts ADD COLUMN admin_id INTEGER"); } catch (\Throwable $e) {}
    try { db()->exec("ALTER TABLE k30_ti_terms_accepts ADD COLUMN admin_note TEXT NOT NULL DEFAULT ''"); } catch (\Throwable $e) {}
    try { db()->exec("ALTER TABLE k30_ti_terms_accepts ADD COLUMN signed_file TEXT NOT NULL DEFAULT ''"); } catch (\Throwable $e) {}
    try { db()->exec("ALTER TABLE k30_ti_terms_accepts ADD COLUMN signed_file_orig TEXT NOT NULL DEFAULT ''"); } catch (\Throwable $e) {}
    try { db()->exec("ALTER TABLE k30_ti_terms_accepts ADD COLUMN signed_uploaded_at DATETIME"); } catch (\Throwable $e) {}

    db()->exec("CREATE INDEX IF NOT EXISTS idx_ti_terms_acc ON k30_ti_terms_accepts(client_id, term_id)");
    // Odbiorcy regulaminu (kto ma go zatwierdzić) — TI_TERM_AUDIENCES; pusto = wszyscy
    try { db()->exec("ALTER TABLE k30_ti_terms ADD COLUMN audience TEXT NOT NULL DEFAULT ''"); } catch (\Throwable $e) {}
    // Warunki regulaminu (JSON): gate — blokuje panel do akceptacji (pokazywany zaraz po zalogowaniu), age — all|adult|minor,
    // since — dotyczy kont założonych od dnia (RRRR-MM-DD), parent — u małoletnich akceptuje opiekun (panel rodzica)
    try { db()->exec("ALTER TABLE k30_ti_terms ADD COLUMN conditions TEXT NOT NULL DEFAULT ''"); } catch (\Throwable $e) {}

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

/** Warunki regulaminu z domyślnymi wartościami (vLAB: u małoletnich zawsze opiekun — dotychczasowa reguła). */
function ti_term_conditions(array $term): array {
    $c = json_decode((string)($term['conditions'] ?? ''), true);
    $c = is_array($c) ? $c : [];
    return [
        'gate'   => !empty($c['gate']),
        'age'    => in_array($c['age'] ?? '', ['adult', 'minor'], true) ? $c['age'] : 'all',
        'since'  => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($c['since'] ?? '')) ? $c['since'] : '',
        'parent' => !empty($c['parent']) || ($term['type'] ?? '') === 'vlab',
    ];
}

/** Zapis warunków z formularza (gate, age, since, parent). */
function ti_term_set_conditions(string $type, array $in): void {
    ti_terms_migrate();
    $c = ['gate' => !empty($in['gate']) ? 1 : 0, 'age' => in_array($in['age'] ?? '', ['adult', 'minor'], true) ? $in['age'] : 'all',
          'since' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in['since'] ?? '')) ? $in['since'] : '', 'parent' => !empty($in['parent']) ? 1 : 0];
    db()->prepare("UPDATE k30_ti_terms SET conditions=? WHERE type=?")->execute([json_encode($c), $type]);
}

/** Opis warunków jednym zdaniem (lista, zakładki). */
function ti_term_conditions_label(array $term): string {
    $c = ti_term_conditions($term); $o = [];
    if ($c['gate']) $o[] = 'blokuje panel do akceptacji';
    if ($c['age'] === 'adult') $o[] = 'tylko pełnoletni';
    if ($c['age'] === 'minor') $o[] = 'tylko małoletni';
    if ($c['since'] !== '') $o[] = 'konta od ' . date('d.m.Y', strtotime($c['since']));
    if ($c['parent']) $o[] = 'u małoletnich akceptuje opiekun';
    return implode(', ', $o);
}

/** Konto panelu beneficjenta (małoletni, data założenia) — do warunków regulaminu. */
function ti_term_client_account(int $client_id): array {
    static $c = [];
    if (!isset($c[$client_id])) {
        try { $a = db_one("SELECT is_minor, created_at FROM k30_ti_student_accounts WHERE client_id=? ORDER BY id LIMIT 1", [$client_id]); } catch (\Throwable $e) { $a = null; }
        $c[$client_id] = ['minor' => !empty($a['is_minor']), 'created' => substr((string)($a['created_at'] ?? ''), 0, 10)];
    }
    return $c[$client_id];
}

/** Czy akceptacja należy do opiekuna (małoletni + warunek „akceptuje opiekun”). */
function ti_term_parent_only(array $term, int $client_id): bool {
    return ti_term_conditions($term)['parent'] && ti_term_client_account($client_id)['minor'];
}

/** Grupy odbiorców, do których należy beneficjent (klucze TI_TERM_AUDIENCES). */
function ti_term_client_groups(int $client_id): array {
    static $c = [];
    if (isset($c[$client_id])) return $c[$client_id];
    $g = [];
    try {
        $e = db_one("SELECT SUM(CASE WHEN COALESCE(k.simple_mode,0) > 0 THEN 1 ELSE 0 END) AS szk, SUM(CASE WHEN COALESCE(k.simple_mode,0) = 0 THEN 1 ELSE 0 END) AS kur
                       FROM k30_ti_enrollments e JOIN k30_ti_courses k ON k.id = e.course_id WHERE e.client_id = ? AND e.status = 'active'", [$client_id]);
        if ((int)($e['kur'] ?? 0) > 0) $g[] = 'kursanci';
        if ((int)($e['szk'] ?? 0) > 0) $g[] = 'szk';
    } catch (\Throwable $ex) { $g[] = 'kursanci'; }
    try {
        if (db_one("SELECT 1 FROM k30_ti_student_accounts WHERE client_id=? AND simple_mode=4", [$client_id])
            || db_one("SELECT 1 FROM k30_schedules WHERE client_id=? LIMIT 1", [$client_id])
            || db_one("SELECT 1 FROM k30_consultations WHERE client_id=? LIMIT 1", [$client_id])) $g[] = 'konsultacje';
    } catch (\Throwable $ex) {}
    return $c[$client_id] = $g;
}

/** Czy regulamin dotyczy beneficjenta (pusta lista odbiorców = wszystkich). */
function ti_term_applies(array $term, int $client_id): bool {
    $aud = array_filter(explode(',', (string)($term['audience'] ?? '')));
    if ($aud && !array_intersect($aud, ti_term_client_groups($client_id))) return false;
    $c = ti_term_conditions($term); $acc = ti_term_client_account($client_id);
    if ($c['age'] === 'adult' && $acc['minor']) return false;
    if ($c['age'] === 'minor' && !$acc['minor']) return false;
    if ($c['since'] !== '' && $acc['created'] !== '' && $acc['created'] < $c['since']) return false;   // tylko nowe konta
    return true;
}

/** Regulaminy blokujące panel, które kursant musi (i może sam) zaakceptować — bramka po zalogowaniu. */
function ti_terms_gate_pending(int $client_id): array {
    return array_values(array_filter(ti_terms_pending($client_id), fn($t) => ti_term_conditions($t)['gate'] && !ti_term_parent_only($t, $client_id)));
}

/** Czy kursant zaakceptował aktualną wersję regulaminu danego typu? */
function ti_term_accepted(int $client_id, string $type): bool {
    ti_terms_migrate();
    $term = db_one("SELECT id, type, version, is_active, audience, conditions FROM k30_ti_terms WHERE type=?", [$type]);
    if (!$term || empty($term['is_active'])) return true; // nieaktywny = nie wymaga akceptacji
    if (!ti_term_applies($term, $client_id)) return true; // nie dotyczy tej osoby (odbiorcy regulaminu)
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
        if (!ti_term_applies($t, $client_id)) continue;   // regulamin dla innych odbiorców
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

/**
 * Administrator: pomiń wymóg akceptacji regulaminu dla kursanta, lub zaakceptuj go
 * zdalnie w jego imieniu (np. zgoda potwierdzona telefonicznie/mailowo poza panelem).
 * $mode: 'skip' (pominięcie wymogu) | 'remote' (akceptacja zdalna w imieniu kursanta).
 * $reason — wymagane uzasadnienie/podstawa, trafia na wygenerowane oświadczenie.
 * Zwraca ID wpisu akceptacji (do wygenerowania oświadczenia PDF).
 */
function ti_term_admin_action(int $client_id, int $term_id, int $admin_id, string $mode, string $reason): int {
    ti_terms_migrate();
    $term = db_one("SELECT version FROM k30_ti_terms WHERE id=?", [$term_id]);
    if (!$term) return 0;
    db()->prepare("DELETE FROM k30_ti_terms_accepts WHERE client_id=? AND term_id=?")->execute([$client_id, $term_id]);
    return db_insert('k30_ti_terms_accepts', [
        'term_id'          => $term_id,
        'client_id'        => $client_id,
        'version'          => (int)$term['version'],
        'ip'               => substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45),
        'ua'               => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 300),
        'accepted_by_role' => $mode === 'skip' ? 'admin_skip' : 'admin_remote',
        'admin_id'         => $admin_id,
        'admin_note'       => $reason,
    ]);
}

/** Czytelna etykieta „kto zaakceptował" do list/UI. */
function ti_terms_role_label(string $role): string {
    return match ($role) {
        'rodzic'       => 'Rodzic/opiekun',
        'admin_skip'   => 'Administrator — pominięcie',
        'admin_remote' => 'Administrator — zdalnie',
        default        => 'Kursant',
    };
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

/**
 * Administrator: cofnij (trwale usuń) zapisaną akceptację regulaminu — niezależnie kto ją
 * zarejestrował (kursant/rodzic/admin). Kursant będzie musiał zaakceptować ponownie, albo admin
 * użyje ponownie pominięcia/zdalnej akceptacji (`ti_term_admin_action`).
 */
function ti_term_accept_revoke(int $accept_id): bool {
    ti_terms_migrate();
    $stmt = db()->prepare("DELETE FROM k30_ti_terms_accepts WHERE id=?");
    $stmt->execute([$accept_id]);
    return $stmt->rowCount() > 0;
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
/** Odbiorcy regulaminu z formularza (lista kluczy TI_TERM_AUDIENCES; pusto = wszyscy). */
function ti_term_set_audience(string $type, array $aud): void {
    ti_terms_migrate();
    $aud = array_values(array_intersect(array_keys(TI_TERM_AUDIENCES), $aud));
    db()->prepare("UPDATE k30_ti_terms SET audience=? WHERE type=?")->execute([implode(',', $aud), $type]);
}

/** Nowy regulamin (typ własny „r_…”). Zwraca klucz typu albo '' przy błędzie. */
function ti_term_create(string $title, string $body_html, array $aud, int $user_id): string {
    ti_terms_migrate();
    $title = trim($title);
    if ($title === '') return '';
    $base = 'r_' . (substr(trim(preg_replace('/[^a-z0-9]+/', '_', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $title) ?: '')), '_'), 0, 30) ?: 'regulamin');
    $type = $base; $i = 2;
    while (db_one("SELECT 1 FROM k30_ti_terms WHERE type=?", [$type])) $type = $base . '_' . $i++;
    db_insert('k30_ti_terms', ['type' => $type, 'title' => mb_substr($title, 0, 200), 'body_html' => $body_html, 'is_active' => 0, 'updated_by' => $user_id ?: null]);
    ti_term_set_audience($type, $aud);
    return $type;
}

/** Etykieta odbiorców do listy / zakładek. */
function ti_term_audience_label(array $term): string {
    $aud = array_filter(explode(',', (string)($term['audience'] ?? '')));
    return $aud ? implode(', ', array_map(fn($a) => TI_TERM_AUDIENCES[$a] ?? $a, $aud)) : 'wszyscy kursanci i beneficjenci';
}

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
    _ti_pdf_fonts($pdf);   // DejaVu — pełne polskie znaki (ISO-8859-2); Helvetica gubiła ogonki
    $pdf->SetAutoPageBreak(true, 20);
    $pdf->SetMargins(20, 20, 20);
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

/**
 * Generuj i wyślij PDF oświadczenia administratora — pominięcie wymogu akceptacji
 * regulaminu lub zdalna akceptacja w imieniu kursanta. Kończy skrypt.
 */
function ti_term_admin_pdf(array $accept, array $client, ?array $admin): void {
    require_once __DIR__ . '/fpdf/fpdf.php';
    require_once __DIR__ . '/fpdi/autoload_fpdi.php';

    $is_skip   = $accept['accepted_by_role'] === 'admin_skip';
    $admin_name = $admin
        ? (trim(($admin['first_name'] ?? '') . ' ' . ($admin['last_name'] ?? '')) ?: ($admin['name'] ?? ''))
        : '—';

    $pdf = new \setasign\Fpdi\Fpdi('P', 'mm', 'A4');
    _ti_pdf_fonts($pdf);   // DejaVu — pełne polskie znaki (ISO-8859-2); Helvetica gubiła ogonki
    $pdf->SetAutoPageBreak(true, 20);
    $pdf->SetMargins(20, 20, 20);
    $pdf->AddPage();
    $W = $pdf->GetPageWidth() - 40;
    $org = defined('ORG_NAME') ? ORG_NAME : '';

    // Nagłówek
    $pdf->SetFillColor(180, 60, 30);
    $pdf->Rect(20, $pdf->GetY(), $W, 14, 'F');
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('DejaVu', 'B', 13);
    $pdf->Cell($W, 14, _ti_pdf_txt('Oświadczenie administratora — regulamin'), 0, 1, 'C', false);
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

    // Ramka z danymi czynności
    $rowsH = 34;
    $pdf->SetFillColor(245, 247, 250);
    $pdf->SetDrawColor(200, 210, 220);
    $pdf->Rect(20, $pdf->GetY(), $W, $rowsH, 'FD');
    $pdf->SetXY(24, $pdf->GetY() + 4);
    $rows = [
        ['Kursant',       $client['name'] ?? ''],
        ['Czynność',      $is_skip ? 'Pominięcie wymogu akceptacji regulaminu' : 'Akceptacja zdalna w imieniu kursanta'],
        ['Administrator', $admin_name],
        ['Data i czas',   date('d.m.Y H:i:s', strtotime($accept['accepted_at']))],
        ['Wersja reg.',   'v' . ($accept['version'] ?? 1)],
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

    // Uzasadnienie / podstawa
    $pdf->SetFont('DejaVu', 'B', 10);
    $pdf->Cell($W, 6, _ti_pdf_txt('Uzasadnienie / podstawa czynności:'), 0, 1);
    $pdf->SetFont('DejaVu', '', 9);
    $pdf->MultiCell($W, 5, _ti_pdf_txt((string)($accept['admin_note'] ?: '—')), 0, 'L');
    $pdf->Ln(4);

    // Treść regulaminu (HTML strip → akapity)
    $pdf->SetFont('DejaVu', 'B', 10);
    $pdf->Cell($W, 6, _ti_pdf_txt($is_skip ? 'Treść regulaminu (którego wymóg pominięto):' : 'Treść regulaminu (zaakceptowana wersja):'), 0, 1);
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

    $fname = 'oswiadczenie_' . preg_replace('/[^a-z0-9_]/i', '_', $accept['type']) . '_' . date('Ymd', strtotime($accept['accepted_at'])) . '.pdf';
    $pdf->Output('D', $fname);
    exit;
}

/**
 * Zapisuje przesłany skan podpisanego oświadczenia administratora (PDF/JPG/PNG) do
 * UPLOAD_DIR/ti_terms_signed/. Zwraca ['name'=>oryginalna nazwa,'stored'=>nazwa na dysku] lub null,
 * gdy nie przesłano pliku. Rzuca RuntimeException przy błędzie/niedozwolonym typie/rozmiarze.
 */
function ti_term_signed_upload(string $field): ?array {
    $f = $_FILES[$field] ?? null;
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if ($f['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Błąd przesyłania pliku.');
    if ($f['size'] > 15 * 1024 * 1024) throw new RuntimeException('Plik zbyt duży (maks. 15 MB).');
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['pdf', 'jpg', 'jpeg', 'png'], true)) {
        throw new RuntimeException('Dozwolone formaty: PDF, JPG, PNG (skan podpisanego dokumentu).');
    }
    $dir = rtrim(UPLOAD_DIR, '/') . '/ti_terms_signed/';
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); @file_put_contents($dir . '.htaccess', "Deny from all\nOptions -Indexes\n"); }
    $stored = 'sig_' . date('Ymd_His') . '_' . bin2hex(random_bytes(5)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], $dir . $stored)) throw new RuntimeException('Nie udało się zapisać pliku.');
    return ['name' => mb_substr($f['name'], 0, 200), 'stored' => $stored];
}

/** Wysyła przesłany skan podpisanego oświadczenia do przeglądarki (download). Kończy skrypt. */
function ti_term_signed_send_file(string $stored, string $orig = ''): void {
    $path = rtrim(UPLOAD_DIR, '/') . '/ti_terms_signed/' . basename($stored);
    if ($stored === '' || !is_file($path)) { http_response_code(404); exit('Plik nie istnieje.'); }
    $name = preg_replace('/[\r\n"]+/', '', $orig !== '' ? $orig : basename($stored));
    $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $mime = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'][$ext] ?? 'application/octet-stream';
    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

/** Usuwa z dysku poprzednio przesłany skan podpisanego oświadczenia (jeśli istnieje). */
function ti_term_signed_delete_file(string $stored): void {
    if ($stored === '') return;
    $path = rtrim(UPLOAD_DIR, '/') . '/ti_terms_signed/' . basename($stored);
    if (is_file($path)) @unlink($path);
}

function _ti_pdf_txt(string $s): string {
    // font DejaVu (includes/fpdf/font/dejavusans.json) jest w ISO-8859-2 — znaki spoza (cudzysłowy „” itd.) jak w TiPdf::pl()
    $s = strtr($s, ['„' => '"', '”' => '"', '“' => '"', '‘' => "'", '’' => "'", '—' => '-', '–' => '-', '…' => '...', '·' => '-', '›' => '>', '‹' => '<', '•' => '-']);
    return iconv('UTF-8', 'ISO-8859-2//TRANSLIT//IGNORE', $s) ?: $s;
}

/** Rejestruje font DejaVu (zwykły i pogrubiony) w dokumencie FPDF/FPDI. */
function _ti_pdf_fonts(\FPDF $pdf): void {
    $fdir = __DIR__ . '/fpdf/font/';
    $pdf->AddFont('DejaVu', '',  'dejavusans.json',  $fdir);
    $pdf->AddFont('DejaVu', 'B', 'dejavusansb.json', $fdir);
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

