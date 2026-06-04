<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/certificates.php';

require_login();

$req_id = intval($_GET['id'] ?? 0);
$req    = get_certificate_request($req_id);
if (!$req || $req['status'] !== 'wydane') {
    http_response_code(404);
    die('Zaświadczenie nie zostało znalezione lub nie zostało jeszcze wydane.');
}

$type     = $req['contract_type'];
$cid      = $req['contract_id'];
$TABLE    = table_for_type($type);
$row      = db_one("SELECT * FROM {$TABLE} WHERE id = ?", [$cid]);

$stored  = org_setting('org_name');
$const   = defined('ORG_NAME') ? ORG_NAME : '';
$org     = (strlen($const) > strlen($stored)) ? $const : ($stored ?: $const);
$org_city  = org_setting('org_miejscowosc') ?: '';
$org_nip   = org_setting('org_nip') ?: '';
$org_krs   = org_setting('org_krs') ?: '';
$org_adres = org_setting('org_adres') ?: '';

// Logo base64
$_logo_b64  = ''; $_logo_mime = 'image/png';
$_logo_file = org_setting('org_logo');
if ($_logo_file) {
    $lpath = dirname(__DIR__) . '/assets/logo/' . basename($_logo_file);
    if (file_exists($lpath) && filesize($lpath) < 500_000) {
        $_logo_b64  = base64_encode(file_get_contents($lpath));
        $_logo_mime = str_ends_with(strtolower($_logo_file), '.svg') ? 'image/svg+xml'
                    : (str_ends_with(strtolower($_logo_file), '.jpg') || str_ends_with(strtolower($_logo_file), '.jpeg') ? 'image/jpeg' : 'image/png');
    }
}

$issued_date = $req['issued_at'] ? date('d.m.Y', strtotime($req['issued_at'])) : date('d.m.Y');
$cert_number = $req['cert_number'] ?: ('ZAWOL/' . str_pad($req_id, 4, '0', STR_PAD_LEFT) . '/' . date('Y'));
$sign_type   = $req['sign_type'] ?? 'papierowe';
$issuer_name = $req['issued_by_name'] ?? '';

$has_file  = !empty($req['certificate_file']);
$file_url  = $has_file ? certificate_file_url($req['certificate_file']) : '';
$is_pdf    = $has_file && str_ends_with(strtolower($req['certificate_file']), '.pdf');

// ── Czyść zapisaną treść ze starych artefaktów ──────────────────────────────
$raw = trim($req['certificate_content'] ?? '');
// Usuń nagłówek "ZAŚWIADCZENIE" (stary szablon)
$raw = preg_replace('/^ZAŚWIADCZENIE\s+/u', '', $raw);
// Usuń blok podpisu (linia kropek + "Podpis...") i wszystko po nim
$raw = preg_replace('/\s*\.{10,}.*$/su', '', $raw);
$raw = preg_replace('/\s*Podpis osoby.*$/su', '', $raw);
// Usuń datę jako ostatnią linię (d MMMM YYYY)
$raw = preg_replace('/\s*\d{1,2}\s+\w+\s+\d{4}\s*$/u', '', $raw);
// Usuń org name jako ostatnią linię jeśli identyczna
if ($org && str_ends_with(rtrim($raw), $org)) {
    $raw = substr($raw, 0, strrpos($raw, $org));
}
$raw = trim($raw);

// Podziel na akapity HTML
$paragraphs = [];
foreach (preg_split('/\n{2,}/', $raw) as $p) {
    $p = trim($p);
    if ($p !== '') $paragraphs[] = nl2br(h($p));
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Zaświadczenie <?= h($cert_number) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Lato:ital,wght@0,400;0,700;0,900;1,400&display=swap" rel="stylesheet">
<style>
/* ═══════════════════════════════════════════════════════
   ZAŚWIADCZENIE O WOLONTARIACIE — A4, czarno-białe, Lato
   ═══════════════════════════════════════════════════════ */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html { font-size: 11pt; }

body {
  font-family: 'Lato', 'Segoe UI', Arial, sans-serif;
  font-size: 1rem; line-height: 1.55;
  color: #000; background: #fff;
  width: 210mm; min-height: 297mm;
  margin: 0 auto;
  padding: 18mm 20mm 16mm 25mm;
}

/* Pasek narzędzi — tylko ekran */
.toolbar {
  position: fixed; top: 0; left: 0; right: 0; z-index: 9999;
  background: #1a1a1a; color: #fff;
  padding: .5rem 1.5rem; display: flex; align-items: center; gap: .85rem;
  font-family: 'Lato', system-ui, sans-serif; font-size: .84rem;
}
.toolbar button {
  background: #fff; color: #1a1a1a; border: none;
  padding: .3rem 1rem; border-radius: 3px;
  font-weight: 700; cursor: pointer; font-size: .84rem;
}
.toolbar a { color: rgba(255,255,255,.78); text-decoration: none; font-size: .82rem; }
.toolbar a:hover { color: #fff; }
.toolbar .tb-sep { color: rgba(255,255,255,.3); }

/* Nagłówek — logo + org + numer */
.cert-header {
  display: flex; justify-content: space-between; align-items: flex-start;
  padding-bottom: .65rem; margin-bottom: .8rem;
  border-bottom: 2px solid #000; gap: 1.2rem;
}
.cert-org-block { display: flex; align-items: center; gap: .75rem; }
.cert-logo      { height: 40px; width: auto; display: block; flex-shrink: 0; }
.cert-org-name  { font-size: .92rem; font-weight: 900; text-transform: uppercase; letter-spacing: .04em; line-height: 1.2; }
.cert-org-meta  { font-size: .74rem; color: #333; margin-top: .12rem; line-height: 1.45; }
.cert-ref       { text-align: right; flex-shrink: 0; }
.cert-ref-num   {
  font-size: .8rem; font-weight: 700;
  border: 1.5px solid #000; padding: .1rem .5rem;
  display: inline-block; margin-bottom: .12rem; letter-spacing: .03em;
}
.cert-ref-date  { font-size: .73rem; color: #444; }

/* Tytuł */
.cert-title-block { text-align: center; margin: .9rem 0 1rem; }
.cert-title-main  {
  font-size: 1.55rem; font-weight: 900;
  text-transform: uppercase; letter-spacing: .12em; line-height: 1.1;
}
.cert-title-sub   { font-size: .88rem; font-weight: 400; letter-spacing: .08em; color: #333; margin-top: .2rem; }
.cert-title-hr    { border: none; border-top: 1px solid #000; margin: .55rem 3rem 0; }

/* Treść */
.cert-body { margin: .2rem 0 1.1rem; }
.cert-body p { margin-bottom: .7rem; text-align: justify; line-height: 1.65; }
.cert-body p:last-child { margin-bottom: 0; }
.cert-body p:first-child { font-weight: 400; }

/* ePodpis */
.epodpis-note {
  border: 1px solid #333; padding: .45rem .75rem; margin: .7rem 0;
  display: flex; gap: .5rem; font-size: .82rem;
}

/* Podpis */
.cert-sign-row {
  display: flex; align-items: flex-end; justify-content: flex-end;
  margin-top: .5rem;
}
.cert-sign-block { min-width: 230px; }
.cert-sign-line  { border-bottom: 1px solid #000; height: 2.2rem; margin-bottom: .18rem; }
.cert-sign-label { font-size: .72rem; color: #444; text-align: center; line-height: 1.3; }
.cert-sign-name  { font-size: .78rem; font-weight: 700; text-align: center; margin-top: .12rem; }

/* Stopka */
.cert-footer {
  margin-top: 1.1rem; padding-top: .4rem; border-top: 1px solid #ccc;
  font-size: .68rem; color: #777;
  display: flex; justify-content: space-between;
}

/* Druk */
@media screen {
  body { margin-top: 2.8rem; box-shadow: 0 0 20px rgba(0,0,0,.12); }
}
@media print {
  body { margin: 0; padding: 15mm 18mm 12mm 22mm; box-shadow: none; }
  .toolbar, .pdf-screen { display: none !important; }
  @page { size: A4 portrait; margin: 0; }
}
</style>
</head>
<body>

<!-- Pasek narzędzi -->
<div class="toolbar" aria-hidden="true">
  <button onclick="window.print()">🖨 Drukuj / PDF</button>
  <a href="<?= APP_URL ?>/certificates/download_docx.php?id=<?= $req_id ?>">⬇ Pobierz DOCX</a>
  <?php if ($has_file): ?>
  <span class="tb-sep">|</span>
  <a href="<?= h($file_url) ?>" download>⬇ Pobierz ePodpis PDF</a>
  <?php endif; ?>
  <span class="tb-sep">|</span>
  <a href="<?= h(contract_url($type, $cid)) ?>">← Wróć do umowy</a>
  <span style="margin-left:auto;opacity:.5;font-size:.75rem"><?= h($cert_number) ?></span>
</div>

<?php if ($has_file && $is_pdf): ?>
<div class="pdf-screen" style="margin-bottom:1rem">
  <iframe src="<?= h($file_url) ?>"
          style="width:100%;height:75vh;border:1px solid #ccc;display:block"
          title="Zaświadczenie z ePodpisem"></iframe>
  <p style="font-family:Lato,sans-serif;font-size:.77rem;text-align:center;color:#777;padding:.35rem">
    Oryginał z podpisem elektronicznym ·
    <a href="<?= h($file_url) ?>" download style="color:#000">Pobierz PDF</a>
    · poniżej: wersja do wydruku
  </p>
</div>
<?php elseif ($has_file): ?>
<div class="pdf-screen" style="text-align:center;padding:1rem">
  <img src="<?= h($file_url) ?>" alt="Zaświadczenie" style="max-width:100%;border:1px solid #ccc">
</div>
<?php endif; ?>

<!-- ══════ DOKUMENT ══════ -->

<header class="cert-header">
  <div class="cert-org-block">
    <?php if ($_logo_b64): ?>
    <img src="data:<?= h($_logo_mime) ?>;base64,<?= $_logo_b64 ?>"
         alt="" role="presentation" class="cert-logo">
    <?php endif; ?>
    <div>
      <div class="cert-org-name"><?= h($org) ?></div>
      <?php if ($org_adres || $org_nip): ?>
      <div class="cert-org-meta">
        <?php if ($org_adres): ?><?= h($org_adres) ?><?php endif; ?>
        <?php if ($org_nip): ?><br>NIP: <?= h($org_nip) ?><?php if ($org_krs): ?> · KRS: <?= h($org_krs) ?><?php endif; ?><?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
  <div class="cert-ref">
    <div class="cert-ref-num"><?= h($cert_number) ?></div>
    <div class="cert-ref-date"><?= h($org_city ?: 'Miejscowość') ?>, dnia <?= $issued_date ?></div>
  </div>
</header>

<div class="cert-title-block">
  <div class="cert-title-main">Zaświadczenie</div>
  <div class="cert-title-sub">o wolontariacie</div>
  <hr class="cert-title-hr">
</div>

<?php if ($paragraphs): ?>
<div class="cert-body">
  <?php foreach ($paragraphs as $p): ?>
  <p><?= $p ?></p>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($sign_type === 'elektroniczne'): ?>
<div class="epodpis-note">
  <strong>Podpisano elektronicznie</strong> (ePodpis kwalifikowany) —
  autentyczność weryfikuje dołączony plik PDF.
</div>
<?php endif; ?>

<div style="margin-top:1.5rem;font-size:.87rem">
  <?= h($org_city ?: 'Miejscowość') ?>, dnia <?= $issued_date ?>
</div>

<div class="cert-sign-row">
  <div class="cert-sign-block">
    <?php if ($sign_type === 'papierowe'): ?>
    <div class="cert-sign-line"></div>
    <div class="cert-sign-label">Podpis osoby upoważnionej<br>do wystawienia zaświadczenia</div>
    <?php if ($issuer_name): ?><div class="cert-sign-name"><?= h($issuer_name) ?></div><?php endif; ?>
    <?php else: ?>
    <div style="border-top:1px solid #666;padding-top:.25rem">
      <div class="cert-sign-label">Podpisano elektronicznie</div>
      <?php if ($issuer_name): ?><div class="cert-sign-name"><?= h($issuer_name) ?></div><?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<footer class="cert-footer">
  <span><?= h($org) ?></span>
  <span><?= h($cert_number) ?> · wydano <?= $issued_date ?></span>
</footer>

</body>
</html>
