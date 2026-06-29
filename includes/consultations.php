<?php
/**
 * includes/consultations.php — Moduł „Karta konsultacyjna".
 *
 * Rejestr konsultacji udzielanych organizacjom (dostępność, sprawy formalne itp.).
 * Składa się z trzech warstw:
 *   • konsultacje/form.php   — publiczny formularz (BEZ logowania) + honeypot
 *   • konsultacje/admin.php  — tabela kart z filtrem dat + paczka ZIP
 *   • konsultacje/pdf.php    — oficjalny protokół do druku/PDF z miejscem na podpisy
 *
 * Tabela: szo_consultation_cards (auto-migracja, SQLite + MySQL).
 * Wszystkie zapytania to prepared statements (zob. includes/db.php).
 */

require_once __DIR__ . '/db.php';

/* ── Słowniki (klucz w bazie → etykieta dla człowieka) ─────────────────────── */

/** Obszary wsparcia (select w formularzu). */
function cc_areas(): array {
    return [
        'dostepnosc_cyfrowa'        => 'Dostępność cyfrowa',
        'dostepnosc_architektoniczna' => 'Dostępność architektoniczna',
        'formalne_prawne'           => 'Sprawy formalne / prawne',
        'inne'                      => 'Inne',
    ];
}

/** Forma konsultacji (radio w formularzu). */
function cc_forms(): array {
    return [
        'stacjonarnie' => 'Stacjonarnie',
        'online'       => 'Online',
        'telefonicznie'=> 'Telefonicznie',
        'mailowo'      => 'Mailowo',
    ];
}

/** Statusy karty. */
function cc_statuses(): array {
    return ['new' => 'Nowa', 'closed' => 'Zamknięta'];
}

/** Bezpieczna etykieta z mapy (z fallbackiem na surową wartość). */
function cc_label(array $map, ?string $key): string {
    if ($key === null || $key === '') return '—';
    return $map[$key] ?? $key;
}

/* ── Migracja schematu ─────────────────────────────────────────────────────── */

/**
 * Tworzy tabelę szo_consultation_cards, jeśli nie istnieje. Idempotentne.
 * Wołane na początku każdej strony modułu.
 */
function cc_migrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;

    $pdo = db();

    if (DB_TYPE === 'sqlite') {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS szo_consultation_cards (
                id                  INTEGER PRIMARY KEY AUTOINCREMENT,
                crm_org_id          INTEGER NULL REFERENCES crm_contacts(id) ON DELETE SET NULL,
                org_name            TEXT    NOT NULL,
                consultation_date   DATE    NOT NULL,
                area_type           TEXT    NOT NULL DEFAULT 'inne',
                form                TEXT    NOT NULL DEFAULT 'stacjonarnie',
                problem_description TEXT    NOT NULL DEFAULT '',
                actions_taken       TEXT    NOT NULL DEFAULT '',
                next_steps          TEXT    NOT NULL DEFAULT '',
                hours               REAL    NOT NULL DEFAULT 0,
                consultant          TEXT    NOT NULL DEFAULT '',
                status              TEXT    NOT NULL DEFAULT 'new',
                created_by          INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
                created_ip          TEXT    NULL,
                created_at          DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_cc_date   ON szo_consultation_cards(consultation_date)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_cc_status ON szo_consultation_cards(status)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_cc_org    ON szo_consultation_cards(crm_org_id)");
    } else {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS szo_consultation_cards (
                id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
                crm_org_id          INT UNSIGNED NULL,
                org_name            VARCHAR(255) NOT NULL,
                consultation_date   DATE NOT NULL,
                area_type           VARCHAR(64)  NOT NULL DEFAULT 'inne',
                form                VARCHAR(32)  NOT NULL DEFAULT 'stacjonarnie',
                problem_description TEXT NOT NULL,
                actions_taken       TEXT NOT NULL,
                next_steps          TEXT NOT NULL,
                hours               DECIMAL(6,2) NOT NULL DEFAULT 0,
                consultant          VARCHAR(255) NOT NULL DEFAULT '',
                status              VARCHAR(16)  NOT NULL DEFAULT 'new',
                created_by          INT UNSIGNED NULL,
                created_ip          VARCHAR(64)  NULL,
                created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_cc_date   (consultation_date),
                KEY idx_cc_status (status),
                KEY idx_cc_org    (crm_org_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
}

/* ── Operacje na danych ────────────────────────────────────────────────────── */

/** Pobiera pojedynczą kartę lub null. */
function cc_get(int $id): ?array {
    cc_migrate();
    return db_one("SELECT * FROM szo_consultation_cards WHERE id=?", [$id]);
}

/**
 * Zwraca [SQL-where, params] dla filtra zakresu dat.
 * $from/$to w formacie Y-m-d (puste = brak ograniczenia).
 */
function cc_date_where(string $from, string $to): array {
    $w = [];
    $p = [];
    if ($from !== '' && cc_valid_date($from)) { $w[] = 'consultation_date >= ?'; $p[] = $from; }
    if ($to   !== '' && cc_valid_date($to))   { $w[] = 'consultation_date <= ?'; $p[] = $to; }
    return [$w ? 'WHERE ' . implode(' AND ', $w) : '', $p];
}

/** Lista kart w zakresie dat, od najnowszych. */
function cc_list(string $from = '', string $to = ''): array {
    cc_migrate();
    [$where, $params] = cc_date_where($from, $to);
    return db_all(
        "SELECT * FROM szo_consultation_cards $where
         ORDER BY consultation_date DESC, id DESC",
        $params
    );
}

/**
 * Suma godzin i liczba kart w rozbiciu na organizacje (w zakresie dat).
 * Zwraca wiersze [org_name, cards, hours] posortowane malejąco po godzinach.
 */
function cc_hours_by_org(string $from = '', string $to = ''): array {
    cc_migrate();
    [$where, $params] = cc_date_where($from, $to);
    return db_all(
        "SELECT org_name, COUNT(*) AS cards, COALESCE(SUM(hours),0) AS hours
         FROM szo_consultation_cards $where
         GROUP BY org_name
         ORDER BY hours DESC, org_name ASC",
        $params
    );
}

/** Walidacja daty Y-m-d. */
function cc_valid_date(string $d): bool {
    $t = \DateTime::createFromFormat('Y-m-d', $d);
    return $t && $t->format('Y-m-d') === $d;
}

/**
 * Próbuje dopasować nazwę organizacji do kontaktu CRM (po polu organizacja
 * lub imie_nazwisko). Zwraca crm_contacts.id albo null. Bezpieczne, gdy CRM
 * nie jest zainstalowany.
 */
function cc_match_crm_org(string $name): ?int {
    $name = trim($name);
    if ($name === '') return null;
    try {
        $r = db_one(
            "SELECT id FROM crm_contacts
             WHERE organizacja = ? COLLATE NOCASE OR imie_nazwisko = ? COLLATE NOCASE
             ORDER BY id LIMIT 1",
            [$name, $name]
        );
        return $r ? (int)$r['id'] : null;
    } catch (\Throwable $e) {
        // MySQL nie zna COLLATE NOCASE — spróbuj bez niego.
        try {
            $r = db_one(
                "SELECT id FROM crm_contacts WHERE organizacja = ? OR imie_nazwisko = ? ORDER BY id LIMIT 1",
                [$name, $name]
            );
            return $r ? (int)$r['id'] : null;
        } catch (\Throwable $e2) {
            return null;
        }
    }
}

/** Lista nazw organizacji z CRM do podpowiedzi (datalist). Pusta gdy brak CRM. */
function cc_org_suggestions(int $limit = 500): array {
    try {
        $rows = db_all(
            "SELECT DISTINCT organizacja AS n FROM crm_contacts
             WHERE organizacja IS NOT NULL AND organizacja <> ''
             UNION
             SELECT DISTINCT imie_nazwisko AS n FROM crm_contacts
             WHERE type='organizacja' AND imie_nazwisko IS NOT NULL AND imie_nazwisko <> ''
             ORDER BY n LIMIT $limit"
        );
        return array_values(array_filter(array_map(fn($r) => trim((string)$r['n']), $rows)));
    } catch (\Throwable $e) {
        return [];
    }
}

/**
 * Waliduje i normalizuje dane z formularza.
 * Zwraca [array $clean, array $errors]. Klucze $errors = nazwy pól.
 */
function cc_validate(array $in): array {
    $err = [];
    $clean = [];

    $clean['org_name'] = trim((string)($in['org_name'] ?? ''));
    if ($clean['org_name'] === '') {
        $err['org_name'] = 'Podaj nazwę organizacji.';
    } elseif (mb_strlen($clean['org_name']) > 255) {
        $err['org_name'] = 'Nazwa organizacji jest zbyt długa (max 255 znaków).';
    }

    $date = trim((string)($in['consultation_date'] ?? ''));
    if ($date === '' || !cc_valid_date($date)) {
        $err['consultation_date'] = 'Podaj poprawną datę konsultacji.';
    } else {
        $clean['consultation_date'] = $date;
    }

    $area = (string)($in['area_type'] ?? '');
    if (!array_key_exists($area, cc_areas())) {
        $err['area_type'] = 'Wybierz obszar wsparcia.';
    } else {
        $clean['area_type'] = $area;
    }

    $form = (string)($in['form'] ?? '');
    if (!array_key_exists($form, cc_forms())) {
        $err['form'] = 'Wybierz formę konsultacji.';
    } else {
        $clean['form'] = $form;
    }

    $clean['problem_description'] = trim((string)($in['problem_description'] ?? ''));
    if ($clean['problem_description'] === '') {
        $err['problem_description'] = 'Opisz problem lub zagadnienie.';
    }

    $clean['actions_taken'] = trim((string)($in['actions_taken'] ?? ''));
    $clean['next_steps']    = trim((string)($in['next_steps'] ?? ''));

    $hours = str_replace(',', '.', trim((string)($in['hours'] ?? '')));
    if ($hours === '' || !is_numeric($hours) || (float)$hours < 0 || (float)$hours > 999) {
        $err['hours'] = 'Podaj poprawną liczbę godzin (0–999).';
    } else {
        $clean['hours'] = round((float)$hours, 2);
    }

    $clean['consultant'] = trim((string)($in['consultant'] ?? ''));
    if ($clean['consultant'] === '') {
        $err['consultant'] = 'Podaj imię i nazwisko konsultanta (podpis).';
    } elseif (mb_strlen($clean['consultant']) > 255) {
        $err['consultant'] = 'Pole podpisu jest zbyt długie.';
    }

    return [$clean, $err];
}

/**
 * Zapisuje nową kartę. $clean musi pochodzić z cc_validate (bez błędów).
 * Zwraca ID nowego rekordu.
 */
function cc_create(array $clean, ?int $created_by = null, ?string $ip = null): int {
    cc_migrate();
    $data = [
        'crm_org_id'          => cc_match_crm_org($clean['org_name']),
        'org_name'            => $clean['org_name'],
        'consultation_date'   => $clean['consultation_date'],
        'area_type'           => $clean['area_type'],
        'form'                => $clean['form'],
        'problem_description' => $clean['problem_description'],
        'actions_taken'       => $clean['actions_taken'],
        'next_steps'          => $clean['next_steps'],
        'hours'               => $clean['hours'],
        'consultant'          => $clean['consultant'],
        'status'              => 'new',
        'created_by'          => $created_by,
        'created_ip'          => $ip,
    ];
    return db_insert('szo_consultation_cards', $data);
}

/** Czytelny numer karty: KK/0012/2026 (na podstawie ID i roku konsultacji). */
function cc_card_number(array $c): string {
    $year = substr((string)($c['consultation_date'] ?? ''), 0, 4);
    if ($year === '' || !ctype_digit($year)) {
        $year = substr((string)($c['created_at'] ?? date('Y')), 0, 4) ?: date('Y');
    }
    return sprintf('KK/%04d/%s', (int)$c['id'], $year);
}

/**
 * Ścieżka pliku logo Miasta Krakowa do osadzenia w PDF (tylko rastry: PNG/JPG —
 * FPDF nie obsługuje SVG). Zwraca null, gdy pliku brak.
 */
function cc_krakow_logo_path(): ?string {
    $root = dirname(__DIR__);
    foreach (['/assets/logo/krakow.png', '/assets/logo/krakow.jpg', '/assets/logo/krakow.jpeg',
              '/assets/img/krakow.png',  '/assets/img/krakow.jpg'] as $rel) {
        $p = $root . $rel;
        if (is_file($p) && filesize($p) > 0) return $p;
    }
    return null;
}

/** Format godzin „2.5 h" → „2,5 godz." */
function cc_hours_label(?float $h): string {
    $h = (float)$h;
    $s = rtrim(rtrim(number_format($h, 1, ',', ' '), '0'), ',');
    return $s . ' godz.';
}

/**
 * Renderuje kartę jako czysty protokół tekstowy (do paczki ZIP).
 */
function cc_to_text(array $c): string {
    $L  = fn($k, $v) => str_pad($k . ':', 26) . $v . "\n";
    $hr = str_repeat('=', 60) . "\n";
    $org = defined('ORG_NAME') ? ORG_NAME : 'Organizacja';

    $out  = $hr;
    $out .= "  KARTA DORADZTWA  ·  nr {$c['id']}\n";
    $out .= "  {$org}\n";
    $out .= $hr . "\n";
    $out .= $L('Organizacja',        $c['org_name']);
    $out .= $L('Data konsultacji',   $c['consultation_date']);
    $out .= $L('Obszar wsparcia',    cc_label(cc_areas(), $c['area_type']));
    $out .= $L('Forma konsultacji',  cc_label(cc_forms(), $c['form']));
    $out .= $L('Liczba godzin',      cc_hours_label((float)$c['hours']));
    $out .= $L('Status',             cc_label(cc_statuses(), $c['status']));
    $out .= "\n" . str_repeat('-', 60) . "\n";
    $out .= "PROBLEM / ZAGADNIENIE\n" . str_repeat('-', 60) . "\n";
    $out .= ($c['problem_description'] !== '' ? $c['problem_description'] : '—') . "\n\n";
    $out .= str_repeat('-', 60) . "\n";
    $out .= "PODJĘTE CZYNNOŚCI\n" . str_repeat('-', 60) . "\n";
    $out .= ($c['actions_taken'] !== '' ? $c['actions_taken'] : '—') . "\n\n";
    $out .= str_repeat('-', 60) . "\n";
    $out .= "DALSZE KROKI\n" . str_repeat('-', 60) . "\n";
    $out .= ($c['next_steps'] !== '' ? $c['next_steps'] : '—') . "\n\n";
    $out .= $hr;
    $out .= "Konsultant: " . ($c['consultant'] !== '' ? $c['consultant'] : '—') . "\n";
    $out .= "Utworzono:  " . ($c['created_at'] ?? '') . "\n";
    $out .= $hr;
    return $out;
}

/** Bezpieczna nazwa pliku dla pozycji w archiwum: RRRR-MM-DD_Nazwa_ID. */
function cc_filename_base(array $c): string {
    $name = $c['org_name'] ?? 'organizacja';
    // Transliteracja PL → ASCII gdzie się da, reszta na podkreślenie.
    $map = ['ą'=>'a','ć'=>'c','ę'=>'e','ł'=>'l','ń'=>'n','ó'=>'o','ś'=>'s','ż'=>'z','ź'=>'z',
            'Ą'=>'A','Ć'=>'C','Ę'=>'E','Ł'=>'L','Ń'=>'N','Ó'=>'O','Ś'=>'S','Ż'=>'Z','Ź'=>'Z'];
    $name = strtr($name, $map);
    $name = preg_replace('/[^A-Za-z0-9]+/', '_', $name);
    $name = trim((string)$name, '_');
    if ($name === '') $name = 'organizacja';
    $name = mb_substr($name, 0, 60);
    return sprintf('%s_%s_%d', $c['consultation_date'] ?? '0000-00-00', $name, (int)$c['id']);
}

/* ── Generowanie prawdziwego PDF (FPDF + DejaVu, ISO-8859-2) ────────────────── */

/** Konwersja UTF-8 → ISO-8859-2 dla tej wersji FPDF (font DejaVu). */
function cc_pdf_iconv(string $s): string {
    return iconv('UTF-8', 'ISO-8859-2//TRANSLIT//IGNORE', $s) ?: $s;
}

/** Tworzy i konfiguruje dokument FPDF z fontem DejaVu. */
function cc_pdf_new(): \setasign\Fpdi\Fpdi {
    require_once __DIR__ . '/fpdf/fpdf.php';
    require_once __DIR__ . '/fpdi/autoload_fpdi.php';
    $pdf = new \setasign\Fpdi\Fpdi('P', 'mm', 'A4');
    $pdf->SetMargins(20, 18, 20);
    $fd = __DIR__ . '/fpdf/font/';
    $pdf->AddFont('DejaVu', '',  'dejavusans.json',  $fd);
    $pdf->AddFont('DejaVu', 'B', 'dejavusansb.json', $fd);
    return $pdf;
}

/**
 * Dorysowuje JEDNĄ kartę konsultacyjną jako nową stronę istniejącego dokumentu.
 * Współdzielone przez eksport pojedynczy i zbiorczy.
 */
function cc_pdf_add_card(\setasign\Fpdi\Fpdi $pdf, array $c): void {
    $rp = fn($s) => cc_pdf_iconv((string)$s);

    $org  = defined('ORG_NAME') ? ORG_NAME : 'Organizacja';
    $area = cc_label(cc_areas(), $c['area_type']);
    $form = cc_label(cc_forms(), $c['form']);
    $date = date_pl($c['consultation_date']);
    $hrs  = cc_hours_label((float)$c['hours']);

    $is_remote = in_array($c['form'], ['online', 'telefonicznie', 'mailowo'], true);

    $pdf->SetAutoPageBreak(true, 16);
    $pdf->AddPage();
    $W = 170; // 210 − 2·20

    // ── Nagłówek ──────────────────────────────────────────────────────────
    $pdf->SetFont('DejaVu', 'B', 9);
    $pdf->Cell($W, 5, $rp($org), 0, 1, 'L');
    $pdf->SetDrawColor(26, 26, 26); $pdf->SetLineWidth(0.5);
    $y = $pdf->GetY() + 1; $pdf->Line(20, $y, 20 + $W, $y);
    $pdf->Ln(4);

    $pdf->SetFont('DejaVu', 'B', 17);
    $pdf->Cell($W, 9, $rp('KARTA DORADZTWA'), 0, 1, 'L');
    $pdf->Ln(3);

    // ── Metryczka ─────────────────────────────────────────────────────────
    $rowFn = function (string $label, string $val) use ($pdf, $rp) {
        $pdf->SetFont('DejaVu', 'B', 10); $pdf->SetFillColor(244, 246, 250);
        $pdf->SetDrawColor(215, 221, 229); $pdf->SetLineWidth(0.2);
        $pdf->Cell(50, 7, $rp($label), 1, 0, 'L', true);
        $pdf->SetFont('DejaVu', '', 10);
        $pdf->Cell(120, 7, $rp($val), 1, 1, 'L');
    };
    $rowFn('Organizacja',       $c['org_name']);
    $rowFn('Data konsultacji',  $date);
    $rowFn('Obszar wsparcia',   $area);
    $rowFn('Forma konsultacji', $form);
    $rowFn('Liczba godzin',     $hrs);

    // ── Sekcje opisowe ──────────────────────────────────────────────────────
    $section = function (string $title, ?string $body) use ($pdf, $rp, $W) {
        $pdf->Ln(3);
        $pdf->SetFont('DejaVu', 'B', 9.5); $pdf->SetTextColor(51, 51, 51);
        $pdf->Cell($W, 6, $rp(mb_strtoupper($title, 'UTF-8')), 'B', 1, 'L');
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(1);
        $pdf->SetFont('DejaVu', '', 10.5);
        $txt = trim((string)$body);
        $pdf->MultiCell($W, 5, $rp($txt !== '' ? $txt : '—'), 0, 'L');
    };
    $section('Problem / zagadnienie', $c['problem_description']);
    $section('Podjęte czynności',     $c['actions_taken']);
    $section('Dalsze kroki',          $c['next_steps']);

    // ── Podpisy ─────────────────────────────────────────────────────────────
    if ($pdf->GetY() > 225) $pdf->AddPage();
    $pdf->Ln(16);
    $lineY = $pdf->GetY();
    $gap = 12; $colW = ($W - $gap) / 2;
    $leftX = 20; $rightX = 20 + $colW + $gap;

    $pdf->SetDrawColor(120, 120, 120); $pdf->SetLineWidth(0.2);
    $pdf->Line($leftX, $lineY, $leftX + $colW, $lineY);
    $pdf->SetXY($leftX, $lineY + 1);
    $pdf->SetFont('DejaVu', 'B', 9.5);
    $pdf->Cell($colW, 5, $rp(trim((string)$c['consultant']) !== '' ? $c['consultant'] : ' '), 0, 2, 'C');
    $pdf->SetFont('DejaVu', '', 8); $pdf->SetTextColor(110, 110, 110);
    $pdf->Cell($colW, 4, $rp('Podpis konsultanta'), 0, 0, 'C');
    $pdf->SetTextColor(0, 0, 0);

    if ($is_remote) {
        $pdf->SetXY($rightX, $lineY - 6);
        $pdf->SetFont('DejaVu', '', 8.5); $pdf->SetTextColor(80, 80, 80);
        $pdf->MultiCell($colW, 4,
            $rp('Konsultacja udzielona zdalnie (' . $form . ') — podpis '
              . 'beneficjenta organizacji nie jest wymagany.'), 1, 'C');
        $pdf->SetTextColor(0, 0, 0);
    } else {
        $pdf->Line($rightX, $lineY, $rightX + $colW, $lineY);
        $pdf->SetXY($rightX, $lineY + 1);
        $pdf->SetFont('DejaVu', 'B', 9.5);
        $pdf->Cell($colW, 5, ' ', 0, 2, 'C');
        $pdf->SetFont('DejaVu', '', 8); $pdf->SetTextColor(110, 110, 110);
        $pdf->Cell($colW, 4, $rp('Podpis przedstawiciela organizacji'), 0, 0, 'C');
        $pdf->SetTextColor(0, 0, 0);
    }

    // ── Dopisek o finansowaniu + logo Miasta Krakowa ─────────────────────────
    $pdf->SetY($pdf->GetY() + 14);
    $pdf->SetFont('DejaVu', '', 8.5); $pdf->SetTextColor(60, 60, 60);
    $pdf->MultiCell($W, 4.5,
        $rp('Konsultacja udzielona w ramach projektu „Akademia Dostępności w NGO” '
          . 'finansowanego ze środków Miasta Krakowa.'), 0, 'C');
    $pdf->SetTextColor(0, 0, 0);

    // ── Stopka (bez wypychania na nową stronę) ────────────────────────────
    $pdf->SetAutoPageBreak(false);
    $pdf->SetY(-15);
    $pdf->SetFont('DejaVu', '', 8); $pdf->SetTextColor(120, 120, 120);
    $pdf->Cell($W, 5, $rp($org), 0, 0, 'L');
    $pdf->SetTextColor(0, 0, 0);
}

/**
 * Buduje i wysyła plik PDF jednej karty.
 * $dest: 'I' = podgląd, 'D' = pobranie, 'S' = zwróć jako string.
 */
function cc_render_pdf_file(array $c, string $dest = 'I'): string {
    $pdf = cc_pdf_new();
    cc_pdf_add_card($pdf, $c);
    $fname = 'Karta_doradztwa_' . cc_filename_base($c) . '.pdf';
    return (string)$pdf->Output($dest, $fname);
}

/**
 * Buduje zbiorczy PDF: każda karta na osobnej stronie A4. Bez nagłówków i
 * adresów dodawanych przez przeglądarkę przy zwykłym wydruku.
 * $dest: 'I' | 'D' | 'S'.
 */
function cc_render_pdf_bulk(array $cards, string $dest = 'D', string $fname = 'karty-konsultacyjne.pdf'): string {
    $pdf = cc_pdf_new();
    foreach ($cards as $c) {
        cc_pdf_add_card($pdf, $c);
    }
    if (!$cards) { // pusty dokument zamiast błędu
        $pdf->SetAutoPageBreak(true, 16);
        $pdf->AddPage();
        $pdf->SetFont('DejaVu', '', 11);
        $pdf->Cell(0, 10, cc_pdf_iconv('Brak kart konsultacyjnych w wybranym zakresie.'), 0, 1, 'L');
    }
    return (string)$pdf->Output($dest, $fname);
}
