<?php
/**
 * certificates/download_pdf.php
 * Generuje zaświadczenie jako PDF (mPDF) i wysyła do pobrania.
 * Wymaga logowania; dostępny dla admina lub właściciela wniosku.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/certificates.php';

require_login();

$req_id = intval($_GET['id'] ?? 0);
$req    = get_certificate_request($req_id);
if (!$req || $req['status'] !== 'wydane') {
    http_response_code(404); die('Nie znaleziono wydanego zaświadczenia.');
}

$_cur = current_user();
if (!is_admin() && (int)($req['requested_by'] ?? 0) !== (int)$_cur['id']) {
    http_response_code(403); die('Brak dostępu do tego zaświadczenia.');
}

$v = cert_view_vars($req);
extract($v, EXTR_PREFIX_ALL, 'c');

// QR weryfikacyjny (lokalnie — bacon/bacon-qr-code)
$verify_code = cert_ensure_verify_code($req_id);
$verify_url  = certificate_verify_url($verify_code);
$_qr_svg     = (new \BaconQrCode\Writer(
    new \BaconQrCode\Renderer\ImageRenderer(
        new \BaconQrCode\Renderer\RendererStyle\RendererStyle(90),
        new \BaconQrCode\Renderer\Image\SvgImageBackEnd()
    )
))->writeString($verify_url);
$qr_data = 'data:image/svg+xml;base64,' . base64_encode($_qr_svg);

// Logo inline (base64)
$logo_html = $c_logo_b64
    ? '<img src="data:' . $c_logo_mime . ';base64,' . $c_logo_b64 . '" style="max-height:28pt;margin-bottom:3pt">'
    : '';

// Akapity (htmlspecialchars — mPDF przetwarza HTML)
$paras_html = '';
foreach ($c_paragraphs as $p) {
    $paras_html .= '<p>' . nl2br(htmlspecialchars($p)) . '</p>';
}

// Blok podpisu
if ($c_sign_type === 'papierowe') {
    $sign_html = '
<div style="margin-top:36pt;text-align:right">
  <table width="46%" align="right" cellpadding="0" cellspacing="0">
    <tr><td style="border-top:1pt solid #000;text-align:center;padding-top:5pt;font-size:9pt">
      Podpis osoby upoważnionej'
        . ($c_issuer_name ? '<br><strong>' . htmlspecialchars($c_issuer_name) . '</strong>' : '')
      . '
    </td></tr>
  </table>
</div>';
} else {
    $sign_html = '<div style="margin-top:28pt;text-align:right;font-style:italic;font-size:10pt">
        Podpisano elektronicznie'
        . ($c_issuer_name ? ' — <strong>' . htmlspecialchars($c_issuer_name) . '</strong>' : '')
        . '</div>';
}

// Stopka weryfikacyjna z QR
$footer_html = '
<div style="margin-top:24pt;padding-top:9pt;border-top:1pt solid #ccc">
  <table width="100%" cellpadding="0" cellspacing="4">
    <tr>
      <td width="52" valign="middle">
        <img src="' . $qr_data . '" style="width:46pt;height:46pt">
      </td>
      <td valign="middle" style="font-size:7.5pt;color:#444;padding-left:6pt">
        <strong>Zweryfikuj autentyczność zaświadczenia</strong><br>
        Zeskanuj kod QR lub wejdź na
        <strong>' . htmlspecialchars(rtrim(APP_URL, '/') . '/weryfikuj') . '</strong><br>
        i podaj kod weryfikacyjny:
        <strong style="letter-spacing:.05em">' . htmlspecialchars($verify_code) . '</strong>
      </td>
    </tr>
  </table>
</div>';

$org_meta = htmlspecialchars($c_org_adres)
    . ($c_org_nip ? ' &nbsp;·&nbsp; NIP: ' . htmlspecialchars($c_org_nip) : '')
    . ($c_org_krs ? ' &nbsp;·&nbsp; KRS: ' . htmlspecialchars($c_org_krs) : '');

$base_css = '
body  { font-family: "DejaVu Sans", sans-serif; font-size: 11pt; line-height: 1.55; color: #000; }
p     { margin: 0 0 8pt 0; text-align: justify; }
table { border-collapse: collapse; }
td    { vertical-align: top; }
h1    { text-align: center; font-size: 14pt; text-transform: uppercase;
        margin: 14pt 0 3pt 0; letter-spacing: .04em; }
.num  { text-align: center; font-size: 9pt; color: #555; margin-bottom: 18pt; }
';

$body_html = '
<table width="100%" cellpadding="0" cellspacing="0"
       style="border-bottom:1pt solid #000;padding-bottom:8pt;margin-bottom:14pt">
  <tr>
    <td width="62%">
      ' . $logo_html . ($logo_html ? '<br>' : '') . '
      <strong>' . htmlspecialchars($c_org) . '</strong><br>
      <span style="font-size:8pt;color:#444">' . $org_meta . '</span>
    </td>
    <td width="38%" align="right">
      <span style="font-size:8.5pt;color:#333">'
        . htmlspecialchars($c_org_city) . ', ' . htmlspecialchars($c_issued_date) . '<br>
        Nr: <strong>' . htmlspecialchars($c_cert_number) . '</strong>
      </span>
    </td>
  </tr>
</table>

<h1>' . htmlspecialchars($c_cert_type_label) . '</h1>
<p class="num">' . htmlspecialchars($c_cert_number) . '</p>

' . $paras_html . '
' . $sign_html . '
' . $footer_html;

try {
    $mpdf = new \Mpdf\Mpdf([
        'mode'          => 'utf-8',
        'format'        => 'A4',
        'margin_left'   => 22,
        'margin_right'  => 20,
        'margin_top'    => 18,
        'margin_bottom' => 18,
        'default_font'  => 'dejavusans',
    ]);
    $mpdf->showImageErrors = false;
    $mpdf->SetTitle($c_cert_type_label . ' — ' . $c_cert_number);
    $mpdf->SetAuthor($c_org);
    $mpdf->SetCreator('FEER SZO');
    $mpdf->WriteHTML($base_css, \Mpdf\HTMLParserMode::HEADER_CSS);
    $mpdf->WriteHTML($body_html, \Mpdf\HTMLParserMode::HTML_BODY);

    $filename = 'Zaswiadczenie_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $c_cert_number) . '.pdf';
    $mpdf->Output($filename, \Mpdf\Output\Destination::DOWNLOAD);
} catch (\Throwable $e) {
    http_response_code(500);
    echo '<p style="font-family:sans-serif;color:red;padding:2rem">Błąd generowania PDF: '
        . htmlspecialchars($e->getMessage()) . '</p>';
}
exit;
