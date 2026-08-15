<?php
/**
 * onboarding/pdf_rachunek.php
 * Generuje PDF "Oświadczenia o numerze rachunku bankowego" dla zleceniobiorcy.
 *
 * Dostęp:
 *  - Sesja onboardingowa: ?id=<ob_id>  (sprawdzamy ob_id == $_SESSION['ob_id'])
 *  - Admin/editor: zalogowany z require_role('admin','editor')
 *
 * Parametry GET:
 *  ?id=<ob_id>          — ID zgłoszenia
 *  ?signed=1            — dołącz blok e-podpisu (jeśli rachunek_podpis_at jest ustawione)
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/onboarding_schema.php';
auth_start();

$ob_id = intval($_GET['id'] ?? 0);

// ── Autoryzacja ──────────────────────────────────────────────────────────────
$is_admin = isset($_SESSION['user_id']) && in_array($_SESSION['role'] ?? '', ['admin', 'editor'], true);
$in_onboarding = isset($_SESSION['ob_id']) && (int)$_SESSION['ob_id'] === $ob_id;

if (!$is_admin && !$in_onboarding) {
    http_response_code(403);
    exit('Brak dostępu.');
}

$vol = db_one("SELECT * FROM onboarding_volunteers WHERE id=?", [$ob_id]);
if (!$vol) { http_response_code(404); exit('Nie znaleziono zgłoszenia.'); }
if ($vol['typ'] !== 'zleceniobiorca') { http_response_code(400); exit('Dokument dotyczy tylko zleceniobiorców.'); }

// ── Helper: UTF-8 → ISO-8859-2 ───────────────────────────────────────────────
function _rp(string $s): string {
    return iconv('UTF-8', 'CP1252//TRANSLIT//IGNORE', $s) ?: $s;
}

// ── Format rachunku: dodaj spacje co 4 cyfry ─────────────────────────────────
function fmt_iban(string $s): string {
    $s = preg_replace('/\D/', '', $s);
    return trim(chunk_split($s, 4, ' '));
}

// ── Data i miejscowość ────────────────────────────────────────────────────────
$miasto = defined('ORG_CITY') ? ORG_CITY : 'Kraków';
$data   = strftime('%d %B %Y', time());
if (function_exists('datefmt_create')) {
    // IntlDateFormatter — ładny format po polsku
    $fmt  = new IntlDateFormatter('pl_PL', IntlDateFormatter::LONG, IntlDateFormatter::NONE);
    $data = $fmt->format(new DateTime());
} else {
    // Fallback: ręczne tłumaczenie miesięcy
    $months = ['', 'stycznia', 'lutego', 'marca', 'kwietnia', 'maja', 'czerwca',
               'lipca', 'sierpnia', 'września', 'października', 'listopada', 'grudnia'];
    $data = (int)date('j') . ' ' . $months[(int)date('n')] . ' ' . date('Y');
}

$org_name  = defined('ORG_NAME') ? ORG_NAME : 'Fundacji';
$imie      = $vol['imie_nazwisko'] ?? '';
$numer     = fmt_iban($vol['rachunek_bankowy'] ?? '');
$bank      = $vol['bank_nazwa'] ?? '';
$posiadacz = $imie; // zakładamy że właściciel rachunku = osoba składająca

$signed_at  = $vol['rachunek_podpis_at']     ?? '';
$signed_ip  = $vol['rachunek_podpis_ip']     ?? '';
$signed_met = $vol['rachunek_podpis_metoda'] ?? '';
$with_sig   = (bool)($_GET['signed'] ?? $signed_at);

// ── Generowanie PDF ───────────────────────────────────────────────────────────
require_once dirname(__DIR__) . '/includes/fpdf/fpdf.php';
require_once dirname(__DIR__) . '/includes/fpdi/autoload_fpdi.php';

$pdf = new \setasign\Fpdi\Fpdi('P', 'mm', 'A4');
$pdf->SetAutoPageBreak(true, 20);
$pdf->SetMargins(25, 20, 25);

$font_dir = dirname(__DIR__) . '/includes/fpdf/font/';
$pdf->AddFont('DejaVu',  '',  'dejavusans.json',  $font_dir);
$pdf->AddFont('DejaVu',  'B', 'dejavusansb.json', $font_dir);

$pdf->AddPage();
$W = 160; // szerokość tekstu (210 - 2*25)

// ── Nagłówek: data i miejscowość (prawy górny róg) ───────────────────────────
$pdf->SetFont('Helvetica', '', 10);
$pdf->SetXY(25, 20);
$pdf->Cell($W, 5, '', 0, 1); // spacer
$pdf->SetX(25);

// Prawostronny blok: miasto, data
$pdf->SetFont('Helvetica', '', 10);
$pdf->Cell($W, 5, _rp($miasto . ', ' . $data), 0, 1, 'R');
$pdf->SetFont('Helvetica', '', 7.5);
$pdf->Cell($W, 4, _rp('(miejscowość, data)'), 0, 1, 'R');

$pdf->Ln(2);

// ── Imię i nazwisko (lewy górny blok) ────────────────────────────────────────
$pdf->SetFont('Helvetica', 'B', 10);
$pdf->Cell($W * 0.55, 5, _rp($imie), 0, 1, 'L');
$pdf->SetFont('Helvetica', '', 7.5);
$pdf->Cell($W * 0.55, 4, _rp('(imię i nazwisko)'), 0, 1, 'L');

$pdf->Ln(8);

// ── Tytuł dokumentu ──────────────────────────────────────────────────────────
$pdf->SetFont('Helvetica', 'B', 11);
$pdf->Cell($W, 6, _rp('Oświadczenie o numerze rachunku bankowego'), 0, 1, 'C');

$pdf->Ln(4);

// ── Wstęp ────────────────────────────────────────────────────────────────────
$pdf->SetFont('Helvetica', '', 10);
$intro = 'Dane rachunku bankowego do wypłaty wynagrodzenia z tytułu zatrudnienia w ' . $org_name;
$pdf->MultiCell($W, 5, _rp($intro), 0, 'J');

$pdf->Ln(4);

// ── Pole: Numer ───────────────────────────────────────────────────────────────
$pdf->SetFont('Helvetica', 'B', 10);
$pdf->Cell($W, 5, _rp('Numer:'), 0, 1, 'L');
$pdf->SetFont('Helvetica', '', 10);
$pdf->Cell($W, 6, _rp($numer ?: ''), 0, 1, 'L');
$pdf->SetDrawColor(0, 0, 0);
$pdf->Line(25, $pdf->GetY(), 185, $pdf->GetY());
$pdf->Ln(5);

// ── Pole: Bank ────────────────────────────────────────────────────────────────
$pdf->SetFont('Helvetica', 'B', 10);
$pdf->Cell($W, 5, _rp('Bank prowadzący rachunek'), 0, 1, 'L');
$pdf->SetFont('Helvetica', '', 10);
$pdf->Cell($W, 6, _rp($bank ?: ''), 0, 1, 'L');
$pdf->Line(25, $pdf->GetY(), 185, $pdf->GetY());
$pdf->Ln(5);

// ── Pole: Posiadacz ───────────────────────────────────────────────────────────
$pdf->SetFont('Helvetica', 'B', 10);
$pdf->Cell($W, 5, _rp('Imię i nazwisko posiadacza rachunku'), 0, 1, 'L');
$pdf->SetFont('Helvetica', '', 10);
$pdf->Cell($W, 6, _rp($posiadacz ?: ''), 0, 1, 'L');
$pdf->Line(25, $pdf->GetY(), 185, $pdf->GetY());
$pdf->Ln(7);

// ── Treść oświadczenia ────────────────────────────────────────────────────────
$pdf->SetFont('Helvetica', '', 10);
$p1 = 'W związku z planowanym zatrudnieniem na podstawie umowy cywilnoprawnej, realizowanej w ramach '
    . 'zadania finansowanego ze środków publicznych, oświadczam, że jestem właścicielem wskazanego '
    . 'do wypłaty wynagrodzenia rachunku bankowego.';
$pdf->MultiCell($W, 5.2, _rp($p1), 0, 'J');
$pdf->Ln(3);

$pdf->MultiCell($W, 5.2, _rp('W odniesieniu do powyższego rachunku potwierdzam, co następuje:'), 0, 'J');
$pdf->Ln(2);

$p2a = '1) rachunek ten nie jest objęty żadnym zajęciem komorniczym ani innymi postępowaniami egzekucyjnymi,';
$p2b = '2) rachunek jest prowadzony dla osoby fizycznej i nie jest wykorzystywany w ramach prowadzonej działalności gospodarczej.';
$pdf->MultiCell($W, 5.2, _rp($p2a), 0, 'J');
$pdf->MultiCell($W, 5.2, _rp($p2b), 0, 'J');
$pdf->Ln(3);

$p3 = 'Jednocześnie przyjmuję do wiadomości, że w przypadku uzyskania przez Fundację informacji o wystąpieniu '
    . 'zajęcia komorniczego na wskazanym rachunku, Fundacja zastrzega sobie prawo do natychmiastowego '
    . 'rozwiązania zawartej umowy z wyłącznej winy Zleceniobiorcy.';
$pdf->MultiCell($W, 5.2, _rp($p3), 0, 'J');
$pdf->Ln(3);

$p4 = 'Niniejsze oświadczenie pozostaje w mocy do końca roku kalendarzowego, w którym zostało złożone. '
    . 'Termin ten ulega skróceniu wyłącznie w sytuacji, gdy w toku trwającej współpracy Zleceniobiorca '
    . 'złoży nowe oświadczenie o Numerze Rachunku Bankowego (NRB).';
$pdf->MultiCell($W, 5.2, _rp($p4), 0, 'J');
$pdf->Ln(10);

// ── Podpis ────────────────────────────────────────────────────────────────────
if ($with_sig && $signed_at) {
    // Podpis elektroniczny
    $pdf->SetFont('Helvetica', '', 8);
    $sig_line = '*** Podpisano elektronicznie: ' . $imie . ' / ' . date('d.m.Y H:i', strtotime($signed_at)) . ' ***';
    $pdf->Cell($W, 5, _rp($sig_line), 0, 1, 'C');
    $pdf->SetDrawColor(100, 100, 100);
    $pdf->Line(110, $pdf->GetY(), 185, $pdf->GetY());
    $pdf->Ln(2);
    $pdf->SetFont('Helvetica', '', 8);
    $pdf->Cell($W, 4, _rp('Czytelny Podpis Zleceniobiorcy'), 0, 1, 'R');
} else {
    // Pusta linia podpisu (do ręcznego podpisania)
    $pdf->SetX(25);
    $pdf->SetFont('Helvetica', '', 10);
    $pdf->Cell($W * 0.45, 5, '', 0, 0); // lewy pusty blok
    // Prawa strona
    $sigX = 25 + $W * 0.45;
    $sigW = $W * 0.55;
    $pdf->Line($sigX, $pdf->GetY() + 5, $sigX + $sigW, $pdf->GetY() + 5);
    $pdf->Ln(8);
    $pdf->SetFont('Helvetica', '', 8.5);
    $pdf->Cell($W, 4, _rp('Czytelny Podpis Zleceniobiorcy'), 0, 1, 'R');
}

// ── Output ────────────────────────────────────────────────────────────────────
$filename = 'oswiadczenie_rachunek_' . preg_replace('/\s+/', '_', strtolower($imie ?: $ob_id)) . '.pdf';
$pdf->Output('D', $filename);
