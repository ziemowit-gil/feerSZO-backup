<?php
/**
 * konsultacje/pdf.php — Generuje PRAWDZIWY plik PDF „Karta konsultacji".
 *
 * GET:
 *   id (int)  — ID karty.
 *   dl (1)    — wymuś pobranie pliku (Content-Disposition: attachment).
 *               Bez parametru dokument wyświetla się w przeglądarce jako PDF.
 *
 * Dokument budowany jest po stronie serwera (FPDF + font DejaVu, kodowanie
 * ISO-8859-2 dla polskich znaków) — to faktyczny plik PDF, nie wydruk z okna
 * przeglądarki.
 *
 * Dostęp: zalogowany (nie-viewer) ALBO autor świeżego wpisu z publicznego
 * formularza (ID na liście dozwolonych w sesji — cc_pub_pdf).
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/consultations.php';

$id = (int)($_GET['id'] ?? 0);

auth_start();
$pub_ok = $id > 0 && in_array($id, $_SESSION['cc_pub_pdf'] ?? [], true);
if (!$pub_ok) {
    require_login();
    if (is_viewer()) { http_response_code(403); exit('Brak dostępu.'); }
}

$c = $id ? cc_get($id) : null;
if (!$c) { http_response_code(404); exit('Karta konsultacyjna nie istnieje.'); }

cc_render_pdf_file($c, isset($_GET['dl']) ? 'D' : 'I');

/**
 * Buduje i wysyła plik PDF protokołu konsultacji.
 * $dest: 'I' = wyświetl w przeglądarce, 'D' = pobierz, 'S' = zwróć jako string.
 */
function cc_render_pdf_file(array $c, string $dest = 'I'): string
{
    require_once dirname(__DIR__) . '/includes/fpdf/fpdf.php';
    require_once dirname(__DIR__) . '/includes/fpdi/autoload_fpdi.php';

    // Polskie znaki: font DejaVu + kodowanie ISO-8859-2 (wzorzec sprawdzony
    // w onboarding/pdf_rachunek.php dla tej wersji FPDF).
    $rp = fn($s) => iconv('UTF-8', 'ISO-8859-2//TRANSLIT//IGNORE', (string)$s) ?: (string)$s;

    $org   = defined('ORG_NAME') ? ORG_NAME : 'Organizacja';
    $no    = cc_card_number($c);
    $area  = cc_label(cc_areas(), $c['area_type']);
    $form  = cc_label(cc_forms(), $c['form']);
    $date  = date_pl($c['consultation_date']);
    $hrs   = cc_hours_label((float)$c['hours']);

    $prepared = substr((string)($c['created_at'] ?? ''), 0, 10);
    if ($prepared === '' || !cc_valid_date($prepared)) $prepared = date('Y-m-d');
    $prepared_pl = date_pl($prepared);

    $is_remote = in_array($c['form'], ['online', 'telefonicznie', 'mailowo'], true);

    $pdf = new \setasign\Fpdi\Fpdi('P', 'mm', 'A4');
    $pdf->SetAutoPageBreak(true, 16);
    $pdf->SetMargins(20, 18, 20);
    $fd = dirname(__DIR__) . '/includes/fpdf/font/';
    $pdf->AddFont('DejaVu', '',  'dejavusans.json',  $fd);
    $pdf->AddFont('DejaVu', 'B', 'dejavusansb.json', $fd);
    $pdf->AddPage();

    $W = 170; // 210 − 2·20

    // ── Nagłówek ──────────────────────────────────────────────────────────
    $pdf->SetFont('DejaVu', 'B', 9);
    $pdf->Cell($W, 5, $rp($org), 0, 1, 'L');
    $pdf->SetDrawColor(26, 26, 26); $pdf->SetLineWidth(0.5);
    $y = $pdf->GetY() + 1; $pdf->Line(20, $y, 20 + $W, $y);
    $pdf->Ln(4);

    $pdf->SetFont('DejaVu', 'B', 17);
    $pdf->Cell($W, 9, $rp('KARTA KONSULTACJI'), 0, 1, 'L');
    $pdf->SetFont('DejaVu', '', 9.5); $pdf->SetTextColor(90, 90, 90);
    $pdf->Cell($W, 5, $rp('Nr ' . $no . '   ·   data sporządzenia: ' . $prepared_pl), 0, 1, 'L');
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Ln(3);

    // ── Metryczka ─────────────────────────────────────────────────────────
    $row = function (string $label, string $val) use ($pdf, $rp) {
        $lw = 50; $vw = 120;
        $pdf->SetFont('DejaVu', 'B', 10); $pdf->SetFillColor(244, 246, 250);
        $pdf->SetDrawColor(215, 221, 229); $pdf->SetLineWidth(0.2);
        $pdf->Cell($lw, 7, $rp($label), 1, 0, 'L', true);
        $pdf->SetFont('DejaVu', '', 10);
        $pdf->Cell($vw, 7, $rp($val), 1, 1, 'L');
    };
    $row('Organizacja',       $c['org_name']);
    $row('Data konsultacji',  $date);
    $row('Obszar wsparcia',   $area);
    $row('Forma konsultacji', $form);
    $row('Liczba godzin',     $hrs);

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
    $pdf->Ln(16);                       // miejsce na odręczny podpis
    $lineY = $pdf->GetY();
    $gap = 12; $colW = ($W - $gap) / 2;
    $leftX = 20; $rightX = 20 + $colW + $gap;

    // Lewa kolumna — konsultant (zawsze linia podpisu).
    $pdf->SetDrawColor(120, 120, 120); $pdf->SetLineWidth(0.2);
    $pdf->Line($leftX, $lineY, $leftX + $colW, $lineY);
    $pdf->SetXY($leftX, $lineY + 1);
    $pdf->SetFont('DejaVu', 'B', 9.5);
    $pdf->Cell($colW, 5, $rp(trim((string)$c['consultant']) !== '' ? $c['consultant'] : ' '), 0, 2, 'C');
    $pdf->SetFont('DejaVu', '', 8); $pdf->SetTextColor(110, 110, 110);
    $pdf->Cell($colW, 4, $rp('Podpis konsultanta'), 0, 0, 'C');
    $pdf->SetTextColor(0, 0, 0);

    // Prawa kolumna — beneficjent: linia (stacjonarnie) lub adnotacja (zdalnie).
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

    $logo = cc_krakow_logo_path();
    if ($logo) {
        $imgW = 42; $x = (210 - $imgW) / 2;
        $pdf->Ln(2);
        $pdf->Image($logo, $x, $pdf->GetY(), $imgW);
    }

    // ── Stopka ────────────────────────────────────────────────────────────
    $pdf->SetAutoPageBreak(false);     // nie wypychaj stopki na nową stronę
    $pdf->SetY(-15);
    $pdf->SetFont('DejaVu', '', 8); $pdf->SetTextColor(120, 120, 120);
    $pdf->Cell($W / 2, 5, $rp($org), 0, 0, 'L');
    $pdf->Cell($W / 2, 5, $rp('Karta nr ' . $no . ' · ' . $prepared_pl), 0, 0, 'R');
    $pdf->SetTextColor(0, 0, 0);

    $fname = 'Karta_konsultacji_' . preg_replace('/[^0-9A-Za-z]+/', '-', $no) . '.pdf';
    return (string)$pdf->Output($dest, $fname);
}
