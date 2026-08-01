<?php
/**
 * certificates/preview.php
 * Renderuje podgląd zaświadczenia (HTML) z overridem treści z POST.
 * Używany przez zakładkę "Podgląd na żywo" w issue.php (iframe srcdoc).
 * Tylko dla adminów; nie zapisuje niczego do bazy.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/certificates.php';

require_login();
if (!is_admin())                              { http_response_code(403); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST')    { http_response_code(405); exit; }
csrf_check();

$req_id = (int)($_POST['req_id'] ?? 0);
$req    = get_certificate_request($req_id);
if (!$req) { http_response_code(404); exit; }

// Nadpisz treść treścią z edytora (POST) — bez zapisu do DB
$req['certificate_content'] = $_POST['content'] ?? '';

$v = cert_view_vars($req);
extract($v, EXTR_PREFIX_ALL, 'c');

// Akapity HTML-safe
$para_html = '';
foreach ($c_paragraphs as $p) {
    $para_html .= '<p>' . nl2br(htmlspecialchars($p)) . '</p>' . "\n";
}

// Logo
$logo_html = $c_logo_b64
    ? '<img src="data:' . $c_logo_mime . ';base64,' . $c_logo_b64 . '" style="max-height:32px;margin-bottom:4px">'
    : '';

// Blok podpisu
if ($c_sign_type === 'papierowe') {
    $sign_html = '<div style="margin-top:40px;text-align:right">
        <div style="display:inline-block;border-top:1px solid #000;width:220px;
                    text-align:center;padding-top:6px;font-size:.87rem">
            Podpis osoby upoważnionej'
        . ($c_issuer_name ? '<br><strong>' . htmlspecialchars($c_issuer_name) . '</strong>' : '')
        . '</div></div>';
} else {
    $sign_html = '<div style="margin-top:32px;text-align:right;font-style:italic">
        Podpisano elektronicznie'
        . ($c_issuer_name ? ' — <strong>' . htmlspecialchars($c_issuer_name) . '</strong>' : '')
        . '</div>';
}

header('Content-Type: text/html; charset=UTF-8');
header('X-Robots-Tag: noindex');
header('Cache-Control: no-store');
?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<style>
* { box-sizing: border-box; }
body {
    font-family: sans-serif; font-size: 11pt; line-height: 1.55; color: #000;
    width: 210mm; margin: 0 auto; padding: 14mm 16mm 12mm;
    background: #fff;
}
.cert-header {
    display: flex; justify-content: space-between; gap: 12px;
    border-bottom: 2px solid #000; padding-bottom: 10px; margin-bottom: 18px;
}
.cert-title {
    font-size: 1.4rem; font-weight: 900; text-align: center;
    text-transform: uppercase; margin: 18px 0 4px; letter-spacing: .04em;
}
.cert-num   { text-align: center; font-size: .82rem; color: #555; margin-bottom: 20px; }
p           { margin: 0 0 .85em 0; text-align: justify; }
.cert-footer {
    margin-top: 28px; padding-top: 12px; border-top: 1px solid #ccc;
    font-size: .72rem; color: #888; text-align: center;
}
.preview-badge {
    position: fixed; top: 8px; right: 8px; background: #fbbf24; color: #000;
    font-size: .72rem; font-weight: 700; padding: 3px 10px; border-radius: 4px;
    z-index: 99; font-family: sans-serif; letter-spacing: .04em;
}
@media print { .preview-badge { display: none; } }
</style>
</head>
<body>

<div class="preview-badge">PODGLĄD</div>

<div class="cert-header">
  <div>
    <?= $logo_html ?>
    <?php if ($logo_html): ?><br><?php endif; ?>
    <strong><?= htmlspecialchars($c_org) ?></strong><br>
    <small style="color:#555">
      <?= htmlspecialchars($c_org_adres) ?>
      <?= $c_org_nip ? ' · NIP: ' . htmlspecialchars($c_org_nip) : '' ?>
    </small>
  </div>
  <div style="text-align:right;white-space:nowrap">
    <small style="color:#333">
      <?= htmlspecialchars($c_org_city) ?>, <?= htmlspecialchars($c_issued_date) ?><br>
      Nr: <?= htmlspecialchars($c_cert_number) ?>
    </small>
  </div>
</div>

<div class="cert-title"><?= htmlspecialchars($c_cert_type_label) ?></div>
<div class="cert-num"><?= htmlspecialchars($c_cert_number) ?></div>

<?= $para_html ?>

<?= $sign_html ?>

<div class="cert-footer">
  <em>Stopka weryfikacyjna (kod QR) zostanie dołączona w finalnym wydruku i PDF.</em>
</div>

</body>
</html>
