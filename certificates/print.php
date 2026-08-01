<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/certificates.php';
require_once dirname(__DIR__) . '/includes/print_templates.php';

require_login();

$req_id = intval($_GET['id'] ?? 0);
$req    = get_certificate_request($req_id);
if (!$req || $req['status'] !== 'wydane') {
    http_response_code(404);
    die('Zaświadczenie nie zostało znalezione lub nie zostało jeszcze wydane.');
}

$_cur = current_user();
if (!is_admin() && (int)($req['requested_by'] ?? 0) !== (int)$_cur['id']) {
    http_response_code(403);
    die('Brak dostępu do tego zaświadczenia.');
}

$type     = $req['contract_type'];
$cid      = $req['contract_id'];
$TABLE    = table_for_type($type);
$row      = db_one("SELECT * FROM {$TABLE} WHERE id = ?", [$cid]);

/* ── Wydruk z wybranego wzoru (jeśli przypisany) ──────────────────────────── */
pt_migrate();
$pt_tpl = !empty($req['print_template_id']) ? pt_get((int)$req['print_template_id']) : null;
if ($pt_tpl) {
    $pt_opts = pt_options($pt_tpl);
    $pt_map  = pt_build_map(['type' => $type, 'row' => $row ?: [], 'req' => $req]);
    $pt_doc  = pt_document_html($pt_tpl, $pt_map);
    $pt_prev = isset($_GET['preview']);
    ?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<title><?= h($pt_tpl['name']) ?> — <?= h(get_contract_person_name($type, $row ?: [])) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Lato:ital,wght@0,400;0,700;0,900;1,400&display=swap" rel="stylesheet">
<style>
<?= pt_document_css($pt_opts['orientation'] ?? 'portrait') ?>
@media screen { body { background:#e5e7eb; } .pt-page { margin:0 auto; box-shadow:0 4px 32px rgba(0,0,0,.18); } }
@media print  { .pt-page { box-shadow:none; margin:0; } }
</style>
</head>
<body>
<?= $pt_doc ?>
<?php if (!$pt_prev): ?>
<script>window.addEventListener('load', function(){ window.print(); });</script>
<?php endif; ?>
</body>
</html>
    <?php
    exit;
}

$stored  = org_setting('org_name');
$const   = defined('ORG_NAME') ? ORG_NAME : '';
$org     = (strlen($const) > strlen($stored)) ? $const : ($stored ?: $const);
$org_city  = org_setting('org_miejscowosc') ?: '';
$org_nip   = org_setting('org_nip') ?: '';
$org_krs   = org_setting('org_krs') ?: '';
$org_adres = org_setting('org_adres') ?: '';

$_logo_b64  = ''; $_logo_mime = 'image/png';
$_logo_file = org_setting('org_logo');
if ($_logo_file) {
    $lpath = dirname(__DIR__) . '/assets/logo/' . basename($_logo_file);
    if (file_exists($lpath) && filesize($lpath) < 500_000) {
        $_logo_b64  = base64_encode(file_get_contents($lpath));
        $_logo_mime = str_ends_with(strtolower($_logo_file), '.svg') ? 'image/svg+xml' : 'image/png';
    }
}

$issued_date = $req['issued_at'] ? date('d.m.Y', strtotime($req['issued_at'])) : date('d.m.Y');
$cert_type_key   = $req['certificate_type'] ?? 'wolontariat';
$cert_type_label = CERTIFICATE_TYPES[$cert_type_key]['label'] ?? 'Zaświadczenie';
$cert_prefix_fb  = cert_type_prefix($cert_type_key);
$cert_number     = $req['cert_number'] ?: ($cert_prefix_fb . '/' . str_pad($req_id, 4, '0', STR_PAD_LEFT) . '/' . date('Y'));
$sign_type   = $req['sign_type'] ?? 'papierowe';
$issuer_name = $req['issued_by_name'] ?? '';
$has_file    = !empty($req['certificate_file']);
$file_url    = $has_file ? certificate_file_url($req['certificate_file']) : '';

$raw = trim($req['certificate_content'] ?? '');
$raw = preg_replace('/^ZAŚWIADCZENIE\s+/u', '', $raw);
$raw = preg_replace('/\s*\.{10,}.*$/su', '', $raw);
$raw = preg_replace('/\s*Podpis osoby.*$/su', '', $raw);
$raw = preg_replace('/\s*\d{1,2}\s+\w+\s+\d{4}\s*$/u', '', $raw);
$raw = trim($raw);

$paragraphs = [];
foreach (preg_split('/\n{2,}/', $raw) as $p) {
    $p = trim($p);
    if ($p !== '') $paragraphs[] = nl2br(h($p));
}

// Kod i URL publicznej weryfikacji (/weryfikuj) — dla każdego wydanego zaświadczenia.
$verify_code = cert_ensure_verify_code($req_id);
$verify_url  = certificate_verify_url($verify_code);

// QR generowany lokalnie (bacon/bacon-qr-code) — bez wysyłania danych do zewnętrznych API.
$_qr_svg  = (new \BaconQrCode\Writer(
    new \BaconQrCode\Renderer\ImageRenderer(
        new \BaconQrCode\Renderer\RendererStyle\RendererStyle(150),
        new \BaconQrCode\Renderer\Image\SvgImageBackEnd()
    )
))->writeString($verify_url);
$verify_qr = 'data:image/svg+xml;base64,' . base64_encode($_qr_svg);

$ean_url = ''; $ean_val = '';
if ($sign_type === 'elektroniczne') {
    $ean_val = date('dmY', strtotime($issued_date)) . substr(preg_replace('/[^0-9]/', '', $cert_number), -4);
    // Kod kreskowy lokalnie (picqer/php-barcode-generator) — bez zewnętrznego API.
    $_bg     = new \Picqer\Barcode\BarcodeGeneratorSVG();
    $ean_url = 'data:image/svg+xml;base64,' . base64_encode(
        $_bg->getBarcode($ean_val, \Picqer\Barcode\BarcodeGeneratorSVG::TYPE_EAN_13)
    );
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<style>
  body { font-family: sans-serif; font-size: 11pt; line-height: 1.5; color: #000; width: 210mm; margin: 0 auto; padding: 15mm; }
  .cert-header { display: flex; justify-content: space-between; border-bottom: 2px solid #000; padding-bottom: 10px; margin-bottom: 20px; }
  .cert-title-main { font-size: 1.6rem; font-weight: 900; text-align: center; text-transform: uppercase; margin: 20px 0; }
  .cert-sign-row { margin-top: 30px; border-top: 1px solid #ccc; padding-top: 15px; }
</style>
</head>
<body>

<header class="cert-header">
  <div><strong><?= h($org) ?></strong><br><?= h($org_adres) ?></div>
  <div><?= h($org_city) ?>, <?= $issued_date ?><br>Nr: <?= h($cert_number) ?></div>
</header>

<div class="cert-title-main"><?= h($cert_type_label) ?></div>

<div class="cert-body">
  <?php foreach ($paragraphs as $p): ?><p><?= $p ?></p><?php endforeach; ?>
</div>

<?php if ($sign_type === 'elektroniczne'): ?>
<div class="cert-sign-row" style="display: flex; gap: 30px; align-items: center;">
    <div style="text-align: center;"><img src="<?= h($verify_qr) ?>" style="width: 70px;"><br><small>Weryfikacja</small></div>
    <div style="text-align: center;"><img src="<?= h($ean_url) ?>" style="height: 40px;"><br><small><?= h($ean_val) ?></small></div>
    <div style="font-size: 0.8em; line-height: 1.3;">
        Dokument podpisano kwalifikowanym podpisem przez: <strong><?= h($issuer_name) ?></strong><br>
        Nr w Systemie: <strong><?= h($cert_number) ?></strong><br>
        Podstawa: Rozp. (UE) 2024/1183 (eIDAS2) oraz ustawa o usługach zaufania.
    </div>
</div>
<?php else: ?>
<div class="cert-sign-row" style="text-align: right; margin-top: 50px;">
    <div style="border-bottom: 1px solid #000; width: 250px; display: inline-block; height: 40px;"></div>
    <div>Podpis osoby upoważnionej</div>
</div>
<?php endif; ?>

<!-- Stopka weryfikacyjna — pozwala potwierdzić autentyczność dokumentu online -->
<div style="margin-top: 28px; padding-top: 12px; border-top: 1px solid #ccc; display: flex; gap: 14px; align-items: center; font-size: .72rem; color: #444;">
  <img src="<?= h($verify_qr) ?>" alt="QR weryfikacji" style="width: 64px; height: 64px;">
  <div>
    <strong>Zweryfikuj autentyczność tego zaświadczenia</strong><br>
    Zeskanuj kod QR lub wejdź na <strong><?= h(rtrim(APP_URL, '/') . '/weryfikuj') ?></strong><br>
    i podaj kod weryfikacyjny: <strong style="letter-spacing:.06em"><?= h($verify_code) ?></strong>
  </div>
</div>

</body>
</html>