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

// Nazwa org — preferuj dłuższą z obu wartości
$stored  = org_setting('org_name');
$const   = defined('ORG_NAME') ? ORG_NAME : '';
$org     = (strlen($const) > strlen($stored)) ? $const : ($stored ?: $const);
$org_city  = org_setting('org_miejscowosc') ?: '';
$org_nip   = org_setting('org_nip') ?: '';
$org_krs   = org_setting('org_krs') ?: '';
$org_adres = org_setting('org_adres') ?: '';

// Logo — base64 dla pewności druku
$_logo_b64  = '';
$_logo_mime = 'image/png';
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
$has_text  = !empty($req['certificate_content']);
$file_url  = $has_file ? certificate_file_url($req['certificate_file']) : '';
$is_pdf    = $has_file && str_ends_with(strtolower($req['certificate_file']), '.pdf');

// Treść → akapity HTML (podział na \n\n; \n → <br>)
$paragraphs = [];
if ($has_text) {
    foreach (preg_split('/\n{2,}/', $req['certificate_content']) as $p) {
        $p = trim($p);
        if ($p !== '') $paragraphs[] = nl2br(h($p));
    }
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Zaświadczenie <?= h($cert_number) ?> — <?= h($org) ?></title>
<!-- Lato (treść) + Playfair Display (tytuł urzędowy) -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Lato:wght@400;700&family=Playfair+Display:wght@700;900&display=swap" rel="stylesheet">
<style>
/* ═══════════════════════════════════════════════════════════
   ZAŚWIADCZENIE O WOLONTARIACIE  ·  A4  ·  Lato + Playfair
   ═══════════════════════════════════════════════════════════ */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

html { font-size: 11pt; }

body {
  font-family: 'Lato', 'Segoe UI', Helvetica, Arial, sans-serif;
  font-size: 1rem; line-height: 1.6;
  color: #111; background: #fff;
  width: 210mm; min-height: 297mm;
  margin: 0 auto;
  padding: 18mm 20mm 16mm 26mm;
}

/* ── Pasek narzędzi (tylko ekran) ─────────────────────────── */
.toolbar {
  position: fixed; top: 0; left: 0; right: 0; z-index: 9999;
  background: #14532d; color: #fff;
  padding: .55rem 1.5rem; font-family: system-ui, sans-serif;
  font-size: .86rem; display: flex; align-items: center; gap: 1rem;
}
.toolbar button {
  background: #fff; color: #14532d; border: none;
  padding: .35rem 1.1rem; border-radius: 4px;
  font-weight: 700; cursor: pointer; font-size: .86rem;
}
.toolbar a { color: rgba(255,255,255,.82); text-decoration: none; }
.toolbar a:hover { color: #fff; }

/* ── Nagłówek organizacji ─────────────────────────────────── */
.org-header {
  display: flex; justify-content: space-between; align-items: flex-start;
  border-bottom: 2.5px solid #14532d; padding-bottom: .6rem; margin-bottom: .9rem;
  gap: 1.2rem;
}
.org-left   { display: flex; align-items: center; gap: .9rem; }
.org-logo   { height: 44px; width: auto; object-fit: contain; display: block; flex-shrink: 0; }
.org-name   { font-size: .96rem; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; line-height: 1.25; }
.org-meta   { font-size: .76rem; color: #555; margin-top: .15rem; line-height: 1.45; }
.doc-ref    { text-align: right; flex-shrink: 0; }
.doc-ref-num {
  font-size: .8rem; font-weight: 700; letter-spacing: .04em;
  border: 1.5px solid #14532d; color: #14532d;
  padding: .12rem .55rem; display: inline-block; margin-bottom: .15rem;
}
.doc-ref-date { font-size: .73rem; color: #555; }

/* ── Tytuł urzędowy ───────────────────────────────────────── */
.cert-title-wrap {
  text-align: center; padding: .7rem 0 .6rem;
  margin-bottom: 1rem;
  border-bottom: 1px solid #ccc;
}
.cert-title {
  font-family: 'Playfair Display', 'Georgia', serif;
  font-size: 1.9rem; font-weight: 900;
  letter-spacing: .06em; color: #14532d;
  line-height: 1.1;
}
.cert-title-sub {
  margin-top: .35rem; font-size: .8rem; color: #777; letter-spacing: .03em;
}
.cert-title-number {
  display: inline-block; margin-top: .3rem;
  font-size: .78rem; font-weight: 700;
  background: #f0fdf4; border: 1px solid #86efac;
  color: #15803d; border-radius: 4px; padding: .1rem .5rem;
}

/* ── Treść ────────────────────────────────────────────────── */
.cert-content { margin: .4rem 0 1rem; }
.cert-content p {
  margin-bottom: .75rem; text-align: justify; font-size: 1rem; line-height: 1.65;
}
.cert-content p:last-child { margin-bottom: 0; }

/* Wyróżnienie pierwszego akapitu */
.cert-content p:first-child { font-size: 1.02rem; }

/* ── ePodpis ──────────────────────────────────────────────── */
.epodpis-box {
  border: 1px solid #14532d; border-radius: 4px;
  padding: .5rem .85rem; margin: .8rem 0;
  display: flex; align-items: center; gap: .6rem;
  font-size: .82rem; background: #f0fdf4;
}

/* ── Separator ────────────────────────────────────────────── */
.cert-hr { border: none; border-top: 1px solid #ddd; margin: 1rem 0 .8rem; }

/* ── Podpis ───────────────────────────────────────────────── */
.sign-area {
  display: flex; align-items: flex-end; gap: 2rem;
  margin-top: .5rem;
}
.sign-spacer { flex: 1; }
.sign-block  { min-width: 200px; max-width: 260px; }
.sign-line   { border-bottom: 1px solid #333; height: 2rem; margin-bottom: .2rem; }
.sign-label  { font-size: .7rem; color: #555; text-align: center; line-height: 1.35; }
.sign-name   { font-size: .78rem; font-weight: 700; text-align: center; margin-top: .15rem; }

/* ── Stopka ───────────────────────────────────────────────── */
.cert-footer {
  margin-top: 1rem; padding-top: .45rem; border-top: 1px solid #eee;
  font-size: .68rem; color: #999;
  display: flex; justify-content: space-between; align-items: baseline;
}

/* ── Druk ─────────────────────────────────────────────────── */
@media screen {
  body { margin-top: 2.5rem; box-shadow: 0 0 24px rgba(0,0,0,.14); }
}
@media print {
  body { margin: 0; padding: 14mm 18mm 12mm 22mm; box-shadow: none; }
  .toolbar, .pdf-screen { display: none !important; }
  @page { size: A4 portrait; margin: 0; }
}
</style>
</head>
<body>

<!-- Pasek narzędzi -->
<div class="toolbar" aria-hidden="true">
  <button onclick="window.print()">🖨 Drukuj / PDF</button>
  <?php if ($has_file): ?>
  <a href="<?= h($file_url) ?>" download>⬇ Pobierz ePodpis</a>
  <?php endif; ?>
  <a href="<?= h(contract_url($type, $cid)) ?>">← Wróć do umowy</a>
  <span style="margin-left:auto;opacity:.65;font-size:.78rem"><?= h($cert_number) ?></span>
</div>

<?php if ($has_file && $is_pdf): ?>
<!-- Wbudowany PDF z ePodpisem — tylko ekran -->
<div class="pdf-screen" style="margin-bottom:1.2rem">
  <iframe src="<?= h($file_url) ?>"
          style="width:100%;height:80vh;border:1px solid #ddd;display:block"
          title="Zaświadczenie z podpisem elektronicznym"></iframe>
  <p style="font-family:system-ui,sans-serif;font-size:.78rem;text-align:center;color:#777;padding:.4rem 0">
    Powyżej: oryginał z podpisem elektronicznym &nbsp;·&nbsp;
    <a href="<?= h($file_url) ?>" download style="color:#14532d">Pobierz plik PDF</a>
    &nbsp;·&nbsp; poniżej: wersja do wydruku
  </p>
</div>
<?php elseif ($has_file): ?>
<div class="pdf-screen" style="text-align:center;padding:1rem">
  <img src="<?= h($file_url) ?>" alt="Zaświadczenie" style="max-width:100%;border:1px solid #ddd">
</div>
<?php endif; ?>

<!-- ═════════════ DOKUMENT ═════════════ -->

<!-- Nagłówek organizacji -->
<header class="org-header">
  <div class="org-left">
    <?php if ($_logo_b64): ?>
    <img src="data:<?= h($_logo_mime) ?>;base64,<?= $_logo_b64 ?>"
         alt="" role="presentation" class="org-logo">
    <?php endif; ?>
    <div>
      <div class="org-name"><?= h($org) ?></div>
      <?php if ($org_adres || $org_nip): ?>
      <div class="org-meta">
        <?php if ($org_adres): ?><?= h($org_adres) ?><?php endif; ?>
        <?php if ($org_nip): ?><br>NIP: <?= h($org_nip) ?><?php if ($org_krs): ?> &nbsp;·&nbsp; KRS: <?= h($org_krs) ?><?php endif; ?><?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
  <div class="doc-ref">
    <div class="doc-ref-num"><?= h($cert_number) ?></div>
    <div class="doc-ref-date"><?= h($org_city) ?>, <?= $issued_date ?></div>
  </div>
</header>

<!-- Tytuł -->
<div class="cert-title-wrap">
  <div class="cert-title">Zaświadczenie</div>
  <div class="cert-title-sub">o wolontariacie</div>
  <div class="cert-title-number"><?= h($cert_number) ?></div>
</div>

<!-- Treść — akapity -->
<?php if ($paragraphs): ?>
<div class="cert-content" role="main">
  <?php foreach ($paragraphs as $p): ?>
  <p><?= $p ?></p>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ePodpis -->
<?php if ($sign_type === 'elektroniczne'): ?>
<div class="epodpis-box">
  <span style="font-size:1.15rem" aria-hidden="true">🔐</span>
  <span>
    <strong>Podpisano elektronicznie</strong> (ePodpis kwalifikowany).
    Autentyczność weryfikuje plik PDF dołączony powyżej.
  </span>
</div>
<?php endif; ?>

<!-- Separator + Podpis -->
<hr class="cert-hr">
<div class="sign-area">
  <div class="sign-spacer"></div>
  <div class="sign-block">
    <?php if ($sign_type === 'papierowe'): ?>
    <div class="sign-line"></div>
    <div class="sign-label">Podpis osoby upoważnionej<br>do wystawienia zaświadczenia</div>
    <?php if ($issuer_name): ?><div class="sign-name"><?= h($issuer_name) ?></div><?php endif; ?>
    <?php else: ?>
    <div style="border-top:1px solid #888;padding-top:.3rem">
      <div class="sign-label">Podpisano elektronicznie</div>
      <?php if ($issuer_name): ?><div class="sign-name"><?= h($issuer_name) ?></div><?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- Stopka -->
<footer class="cert-footer">
  <span><?= h($org) ?></span>
  <span><?= h($cert_number) ?> · wydano <?= $issued_date ?></span>
</footer>

</body>
</html>
