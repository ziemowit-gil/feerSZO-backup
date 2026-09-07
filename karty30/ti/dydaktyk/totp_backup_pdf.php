<?php
/**
 * karty30/ti/dydaktyk/totp_backup_pdf.php — PDF z kodami zapasowymi 2FA.
 *
 * Dostępny WYŁĄCZNIE w tym samym oknie sesji co ekran "zapisz kody zapasowe"
 * w totp_gate.php (tuż po włączeniu TOTP) — źródłem kodów jest ta sama
 * sesja ($_SESSION['k30_dyd_2fa_backup_show']), nie baza danych. Po kliknięciu
 * "przejdź do panelu" ten klucz sesji jest czyszczony (dyd_2fa_clear_pending())
 * i PDF przestaje być dostępny, tak samo jak sam ekran przestaje pokazywać kody.
 */
require_once __DIR__ . '/auth.php';

dyd_start();
$backup  = $_SESSION['k30_dyd_2fa_backup_show'] ?? null;
$profile = dyd_2fa_pending();

if (!$backup || !is_array($backup) || !$profile) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Kody zapasowe nie są już dostępne do pobrania — zostały pokazane tylko raz, tuż po włączeniu 2FA.';
    exit;
}

require_once dirname(dirname(dirname(__DIR__))) . '/includes/fpdf/fpdf.php';
$pl  = fn(string $s): string => iconv('UTF-8', 'CP1252//TRANSLIT//IGNORE', $s) ?: $s;
$org = defined('ORG_NAME') ? ORG_NAME : '';

try {
    $pdf = new FPDF('P', 'mm', 'A4');
    $pdf->SetAutoPageBreak(true, 15);
    $pdf->SetMargins(18, 18, 18);
    $pdf->AddPage();
    $W = $pdf->GetPageWidth() - 36;

    $pdf->SetFillColor(30, 58, 95); $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Helvetica', 'B', 14);
    $pdf->Cell($W, 10, $pl('Kody zapasowe 2FA — Panel dydaktyka'), 0, 1, 'L', true);
    $pdf->SetTextColor(0, 0, 0); $pdf->SetFont('Helvetica', '', 9);
    $sub = ($org ? $org . '   ·   ' : '') . ($profile['name'] ?? $profile['email'] ?? '');
    $pdf->Cell($W, 6, $pl($sub), 0, 1);
    $pdf->Ln(4);

    $pdf->SetFont('Helvetica', '', 9.5);
    $pdf->MultiCell($W, 5, $pl(
        'Jeśli zgubisz dostęp do aplikacji uwierzytelniającej (Google Authenticator, Microsoft '
      . 'Authenticator), zaloguj się jednym z poniższych kodów zamiast 6-cyfrowego kodu z aplikacji. '
      . 'Każdy kod działa TYLKO RAZ. Przechowuj tę kartkę w bezpiecznym miejscu — kto ją zdobędzie, '
      . 'może się nią zalogować.'
    ), 0, 'L');
    $pdf->Ln(4);

    $pdf->SetFont('Courier', 'B', 15);
    $colW = $W / 2;
    foreach (array_chunk($backup, 2) as $pair) {
        foreach ($pair as $code) {
            $pdf->SetFillColor(238, 240, 244); $pdf->SetDrawColor(208, 208, 216);
            $pdf->Cell($colW - 3, 11, $pl($code), 1, 0, 'C', true);
            $pdf->Cell(6, 11, '', 0, 0);
        }
        $pdf->Ln(13);
    }

    $pdf->Ln(4);
    $pdf->SetFont('Helvetica', '', 8); $pdf->SetTextColor(120, 120, 120);
    $pdf->Cell($W, 4, $pl('Wygenerowano: ' . date('d.m.Y H:i')), 0, 1, 'L');

    $pdfData = $pdf->Output('S');
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="kody-zapasowe-2fa.pdf"');
    header('Content-Length: ' . strlen($pdfData));
    header('Cache-Control: no-store');
    echo $pdfData;
    exit;
} catch (\Throwable $e) {
    error_log('[totp_backup_pdf] ' . $e->getMessage());
    if (!headers_sent()) { http_response_code(500); header('Content-Type: text/plain; charset=UTF-8'); }
    echo 'Błąd generowania PDF: ' . $e->getMessage();
    exit;
}
