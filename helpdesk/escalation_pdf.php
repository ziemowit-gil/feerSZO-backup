<?php
/**
 * helpdesk/escalation_pdf.php — Potwierdzenie podbicia zgłoszenia (brak reakcji) w PDF.
 * GET: id (wymagane, id podbicia), t (opcjonalnie — token publicznego mikropanelu).
 * Dostęp: operator/zgłaszający zalogowany (hd_can_view_ticket) LUB posiadacz tokenu
 * publicznego mikropanelu tego zgłoszenia (bez logowania).
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/helpdesk.php';
helpdesk_migrate();

$esc_id = (int)($_GET['id'] ?? 0);
$esc    = $esc_id ? db_one("SELECT * FROM helpdesk_escalations WHERE id=?", [$esc_id]) : null;
if (!$esc) { http_response_code(404); exit('Nie znaleziono podbicia.'); }

$ticket = db_one("SELECT * FROM helpdesk_tickets WHERE id=?", [(int)$esc['ticket_id']]);
if (!$ticket) { http_response_code(404); exit('Nie znaleziono zgłoszenia.'); }

$token      = trim($_GET['t'] ?? '');
$authorized = hd_can_view_ticket($ticket)
    || ($token !== '' && !empty($ticket['access_token']) && hash_equals((string)$ticket['access_token'], $token));
if (!$authorized) { http_response_code(403); exit('Brak dostępu.'); }

$org = defined('ORG_NAME') ? ORG_NAME : 'FEER';
$h   = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

$body_html = '
<h2 style="text-align:center;margin-bottom:2pt">Podbicie zgłoszenia helpdesku</h2>
<p style="text-align:center;color:#555;margin-top:0">brak reakcji na zgłoszenie</p>
<table width="100%" style="margin:14pt 0 10pt 0">
  <tr><td width="45%"><strong>Numer podbicia:</strong></td><td>' . $h($esc['number']) . '</td></tr>
  <tr><td><strong>Data podbicia:</strong></td><td>' . $h(date('d.m.Y H:i', strtotime($esc['created_at']))) . '</td></tr>
</table>
<hr>
<table width="100%" style="margin:10pt 0">
  <tr><td width="45%"><strong>Dotyczy zgłoszenia nr:</strong></td><td>' . $h($ticket['number']) . '</td></tr>
  <tr><td><strong>Temat:</strong></td><td>' . $h($ticket['title']) . '</td></tr>
  <tr><td><strong>Zgłoszono dnia:</strong></td><td>' . $h(date('d.m.Y H:i', strtotime($ticket['created_at']))) . '</td></tr>
  <tr><td><strong>Ostatnia aktualizacja:</strong></td><td>' . $h(date('d.m.Y H:i', strtotime($ticket['updated_at']))) . '</td></tr>
  <tr><td><strong>Dni bez reakcji:</strong></td><td>' . (int)$esc['days_waiting'] . '</td></tr>
  <tr><td><strong>Status w chwili podbicia:</strong></td><td>' . $h(HD_STATUSES[$ticket['status']]['label'] ?? $ticket['status']) . '</td></tr>
</table>
<hr>
<table width="100%" style="margin:10pt 0">
  <tr><td width="45%"><strong>Zgłaszający:</strong></td><td>' . $h($esc['requester_name'] ?: '—') . '</td></tr>
  <tr><td><strong>E-mail:</strong></td><td>' . $h($esc['requester_email'] ?: '—') . '</td></tr>
  <tr><td><strong>Telefon:</strong></td><td>' . $h($esc['requester_phone'] ?: '—') . '</td></tr>
</table>
<hr>
<p style="margin-top:12pt"><strong>Uzasadnienie zgłaszającego:</strong></p>
<p style="white-space:pre-wrap">' . ($esc['reason'] !== '' ? nl2br($h($esc['reason'])) : '<em>— nie podano —</em>') . '</p>
<div style="margin-top:40pt">
  <table width="100%">
    <tr>
      <td width="50%">Data: ' . $h(date('d.m.Y')) . '</td>
      <td width="50%" style="border-top:1px solid #000;padding-top:4pt">Podpis osoby przyjmującej podbicie</td>
    </tr>
  </table>
</div>
<p style="margin-top:24pt;color:#888;font-size:8pt">Wygenerowano automatycznie ' . $h(date('d.m.Y H:i')) . ' — ' . $h($org) . ' · Helpdesk IT</p>
';

$base_css = '
body { font-family: "DejaVu Serif", serif; font-size: 11pt; line-height: 1.5; color: #000; }
table { border-collapse: collapse; }
td    { vertical-align: top; padding: 2pt 4pt 2pt 0; }
hr    { border: none; border-top: 1px solid #ccc; }
';

require_once dirname(__DIR__) . '/vendor/autoload.php';

try {
    $mpdf = new \Mpdf\Mpdf([
        'mode'          => 'utf-8',
        'format'        => 'A4',
        'margin_left'   => 22,
        'margin_right'  => 20,
        'margin_top'    => 18,
        'margin_bottom' => 18,
        'default_font'  => 'dejavuserif',
    ]);
    $mpdf->SetTitle('Podbicie zgłoszenia ' . $ticket['number']);
    $mpdf->SetAuthor($org);
    $mpdf->SetCreator('FEER SZO');
    $mpdf->WriteHTML($base_css, \Mpdf\HTMLParserMode::HEADER_CSS);
    $mpdf->WriteHTML($body_html, \Mpdf\HTMLParserMode::HTML_BODY);

    $filename = 'Podbicie-' . preg_replace('/[^A-Za-z0-9_-]/', '_', $esc['number']) . '.pdf';
    $mpdf->Output($filename, \Mpdf\Output\Destination::INLINE);
} catch (\Throwable $e) {
    http_response_code(500);
    echo '<p style="font-family:sans-serif;color:red;padding:2rem">Błąd generowania PDF: ' . $h($e->getMessage()) . '</p>';
}
