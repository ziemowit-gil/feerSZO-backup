<?php
/**
 * wsparcie_ou/oswiadczenie.php — Oświadczenie Zarządu FEER (PDF) dla wpisu ewidencji
 * wsparcia zewnętrznego: zatwierdzenie albo odrzucenie (z powodem).
 *   ?id=N            → podgląd PDF (inline)
 *   ?id=N&dl=1       → pobranie PDF (attachment)
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/wsparcie_ou.php';

require_login();
require_module_enabled('wsparcie_ou_enabled', 'Ewidencja wsparcia zewnętrznego OU');
if (!can_read('wsparcie_ou')) { http_response_code(403); die('Brak uprawnień.'); }

$id = (int)($_GET['id'] ?? 0);
$w  = wsparcie_ou_get($id);
if (!$w) { http_response_code(404); die('Wpis nie istnieje.'); }
if ($w['status'] === 'oczekuje') { http_response_code(409); die('Wpis nie został jeszcze rozpatrzony — oświadczenie dostępne po zatwierdzeniu lub odrzuceniu.'); }

$S = function (string $k): string {
    $r = db_one("SELECT value FROM settings WHERE key_=?", [$k]);
    return trim((string)($r['value'] ?? ''));
};
$org_name   = $S('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : 'Fundacja');
$org_short  = $S('org_short_name') ?: $org_name;
$org_adres  = $S('org_adres');
$org_miejsc = $S('org_miejscowosc') ?: $S('org_miasto');
$org_nip    = $S('org_nip');
$org_regon  = $S('org_regon');
$org_krs    = $S('org_krs');
$org_zarzad = $S('org_zarzad');

$approved = $w['status'] === 'zatwierdzony';
$fmtG  = fn($x) => rtrim(rtrim(number_format((float)$x, 2, ',', ' '), '0'), ',');
$mies  = $w['miesiac'];
$decDate = $w['decided_at'] ? substr($w['decided_at'], 0, 10) : date('Y-m-d');

// Różnica godzin względem poprzedniego miesiąca (ten sam podmiot)
$prevInfo  = wsparcie_ou_prev_month_hours($w['podmiot_krs'], $w['podmiot_nazwa'], $mies);
$prevHours = $prevInfo['hours'];
$prevMies  = $prevInfo['prev'];
$diff      = $prevHours === null ? null : ((float)$w['liczba_godzin'] - $prevHours);
$diffStr   = $diff === null ? '—' : (($diff > 0 ? '+' : ($diff < 0 ? '−' : '')) . $fmtG(abs($diff)) . ' h');

require_once dirname(__DIR__) . '/includes/fpdf/fpdf.php';
$FD = dirname(__DIR__) . '/includes/fpdf/font/';
$pl = fn(string $s): string => iconv('UTF-8', 'ISO-8859-2//TRANSLIT//IGNORE', $s) ?: $s;

try {
    $pdf = new FPDF('P', 'mm', 'A4');
    $pdf->SetAutoPageBreak(true, 18);
    $pdf->SetMargins(18, 18, 18);
    $pdf->AddFont('DejaVu', '',  'dejavusans.json',  $FD);
    $pdf->AddFont('DejaVu', 'B', 'dejavusansb.json', $FD);
    $pdf->AddPage();
    $W = $pdf->GetPageWidth() - 36;

    // Nagłówek — organizacja + miejscowość/data
    $pdf->SetFont('DejaVu', 'B', 10);
    $topY = $pdf->GetY();
    $pdf->MultiCell($W * 0.62, 5, $pl($org_name), 0, 'L');
    $pdf->SetFont('DejaVu', '', 8.5);
    if ($org_adres)  $pdf->MultiCell($W * 0.62, 4, $pl($org_adres . ($org_miejsc ? ', ' . $org_miejsc : '')), 0, 'L');
    $reg = trim(($org_nip ? 'NIP ' . $org_nip : '') . ($org_regon ? '   REGON ' . $org_regon : '') . ($org_krs ? '   KRS ' . $org_krs : ''));
    if ($reg) $pdf->MultiCell($W * 0.62, 4, $pl($reg), 0, 'L');
    $endLeftY = $pdf->GetY();
    $pdf->SetXY(18 + $W * 0.62, $topY);
    $pdf->SetFont('DejaVu', '', 9);
    $pdf->MultiCell($W * 0.38, 5, $pl(($org_miejsc ? $org_miejsc . ', ' : '') . 'dnia ' . $decDate . ' r.'), 0, 'R');
    $pdf->SetY(max($endLeftY, $topY) + 6);

    // Linia oddzielająca nagłówek
    $pdf->SetDrawColor(40, 40, 40); $pdf->SetLineWidth(0.4);
    $ry = $pdf->GetY(); $pdf->Line(18, $ry, 18 + $W, $ry);
    $pdf->SetLineWidth(0.2); $pdf->Ln(8);

    // Tytuł
    $pdf->SetFont('DejaVu', 'B', 15);
    $pdf->MultiCell($W, 8, $pl('OŚWIADCZENIE ZARZĄDU'), 0, 'C');
    $pdf->SetFont('DejaVu', '', 9.5); $pdf->SetTextColor(70, 70, 70);
    $pdf->MultiCell($W, 5, $pl('w sprawie ewidencji wsparcia zewnętrznego'), 0, 'C');
    $pdf->SetTextColor(0, 0, 0);
    // Krótka linia dekoracyjna pod tytułem
    $cy = $pdf->GetY() + 2; $pdf->SetDrawColor(150, 165, 185);
    $pdf->Line(18 + $W / 2 - 25, $cy, 18 + $W / 2 + 25, $cy);
    $pdf->SetDrawColor(150, 165, 185);
    $pdf->Ln(9);

    // Treść
    $pdf->SetFont('DejaVu', '', 11);
    $intro = 'Zarząd ' . $org_name . ', po rozpatrzeniu ewidencji wsparcia świadczonego przez podmiot zewnętrzny, '
        . 'oświadcza, co następuje:';
    $pdf->MultiCell($W, 6, $pl($intro), 0, 'J');
    $pdf->Ln(3);

    // Dane podmiotu / wsparcia — tabelka
    $row = function (string $k, string $v) use ($pdf, $pl, $W) {
        $pdf->SetFont('DejaVu', '', 10);
        $pdf->SetFillColor(240, 243, 247);
        $pdf->Cell($W * 0.38, 7, $pl($k), 1, 0, 'L', true);
        $pdf->SetFont('DejaVu', 'B', 10);
        $pdf->Cell($W * 0.62, 7, $pl($v), 1, 1, 'L', false);
    };
    $row('Podmiot', $w['podmiot_nazwa'] ?: '—');
    $ident = trim(($w['podmiot_krs'] ? 'KRS ' . $w['podmiot_krs'] : '') . ($w['podmiot_nip'] ? '   NIP ' . $w['podmiot_nip'] : '') . ($w['podmiot_regon'] ? '   REGON ' . $w['podmiot_regon'] : ''));
    if ($ident !== '') $row('Identyfikatory', $ident);
    if ($w['podmiot_adres']) $row('Adres', $w['podmiot_adres']);
    $row('Okres (miesiąc)', $mies);
    $row('Liczba godzin wsparcia', $fmtG($w['liczba_godzin']) . ' h');
    $row('Poprzedni miesiąc (' . $prevMies . ')', $prevHours === null ? 'brak wpisu' : $fmtG($prevHours) . ' h');
    $row('Różnica godzin (miesiąc do miesiąca)', $diffStr);
    $pdf->Ln(5);

    // Rozstrzygnięcie
    $pdf->SetFont('DejaVu', 'B', 12);
    if ($approved) {
        $pdf->SetTextColor(21, 128, 61);
        $pdf->MultiCell($W, 7, $pl('ZATWIERDZA powyższe wsparcie zewnętrzne'), 0, 'L');
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('DejaVu', '', 10.5);
        $pdf->MultiCell($W, 6, $pl('i uznaje wykazaną liczbę godzin za zrealizowaną oraz rozliczoną.'), 0, 'J');
    } else {
        $pdf->SetTextColor(185, 28, 28);
        $pdf->MultiCell($W, 7, $pl('ODRZUCA powyższe wsparcie zewnętrzne.'), 0, 'L');
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('DejaVu', 'B', 10.5);
        $pdf->MultiCell($W, 6, $pl('Powód odrzucenia:'), 0, 'L');
        $pdf->SetFont('DejaVu', '', 10.5);
        $pdf->MultiCell($W, 6, $pl($w['powod_odrzucenia'] !== '' ? $w['powod_odrzucenia'] : '—'), 1, 'J');
    }
    $pdf->Ln(4);
    if ($w['decided_name']) {
        $pdf->SetFont('DejaVu', '', 8.5); $pdf->SetTextColor(110, 110, 110);
        $pdf->MultiCell($W, 4.5, $pl('Rozstrzygnięcie zarejestrował(a): ' . $w['decided_name'] . ' (' . $decDate . ').'), 0, 'L');
        $pdf->SetTextColor(0, 0, 0);
    }

    // Adnotacja urzędowa (tryb złożenia)
    $pdf->Ln(6);
    if ($pdf->GetY() > $pdf->GetPageHeight() - 60) $pdf->AddPage();
    $pdf->SetFont('DejaVu', 'B', 8.5);
    $pdf->SetFillColor(238, 242, 247); $pdf->SetDrawColor(150, 165, 185);
    $pdf->Cell($W, 6, $pl('Tryb złożenia'), 1, 1, 'L', true);
    $pdf->SetFont('DejaVu', '', 9);
    $pdf->MultiCell($W, 5, $pl('Niniejsze oświadczenie należy zatwierdzić elektronicznie i przesłać za pośrednictwem '
        . 'systemu SOD Generator NGO do Wydziału Polityki Społecznej, Równości i Zdrowia '
        . 'Urzędu Miasta Krakowa.'), 1, 'J');

    // Podpis zarządu
    $pdf->Ln(18);
    if ($pdf->GetY() > $pdf->GetPageHeight() - 40) $pdf->AddPage();
    $sigW = 82; $sigX = 18 + $W - $sigW; $ySig = $pdf->GetY() + 8;
    $pdf->SetDrawColor(120, 120, 120);
    $pdf->Line($sigX, $ySig, $sigX + $sigW, $ySig);
    $pdf->SetXY($sigX, $ySig + 1);
    $pdf->SetFont('DejaVu', '', 8.5);
    if ($org_zarzad) { $pdf->MultiCell($sigW, 4, $pl($org_zarzad), 0, 'C'); $pdf->SetX($sigX); }
    $pdf->Cell($sigW, 4, $pl('(podpis / pieczęć Zarządu ' . $org_short . ')'), 0, 1, 'C');

    while (ob_get_level() > 0) ob_end_clean();
    $disp = !empty($_GET['dl']) ? 'D' : 'I';
    $pdf->Output($disp, 'oswiadczenie_zarzadu_OU_' . $id . '_' . $mies . '.pdf');
    exit;
} catch (\Throwable $e) {
    error_log('[wsparcie_ou/oswiadczenie] #' . $id . ': ' . $e->getMessage());
    if (!headers_sent()) { http_response_code(500); header('Content-Type: text/plain; charset=UTF-8'); }
    echo "Nie udało się wygenerować oświadczenia.\nPowód: " . $e->getMessage() . "\n";
    exit;
}
