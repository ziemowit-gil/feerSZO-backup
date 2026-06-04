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

$type      = $req['contract_type'];
$cid       = $req['contract_id'];
$TABLE     = table_for_type($type);
$row       = db_one("SELECT * FROM {$TABLE} WHERE id = ?", [$cid]);
$stored_name = org_setting('org_name');
$const_name  = defined('ORG_NAME') ? ORG_NAME : '';
$org       = (strlen($const_name) > strlen($stored_name)) ? $const_name : ($stored_name ?: $const_name);
$org_city  = org_setting('org_miejscowosc') ?: '';
$org_nip   = org_setting('org_nip') ?: '';
$org_krs   = org_setting('org_krs') ?: '';
$org_adres = org_setting('org_adres') ?: '';

// Logo org — base64 dla pewności druku
$_logo_file = org_setting('org_logo');
$_logo_b64  = '';
$_logo_mime = '';
if ($_logo_file) {
    $lpath = dirname(__DIR__) . '/assets/logo/' . basename($_logo_file);
    if (file_exists($lpath) && filesize($lpath) < 500_000) {
        $_logo_b64  = base64_encode(file_get_contents($lpath));
        $_logo_mime = str_ends_with(strtolower($_logo_file), '.png') ? 'image/png'
                    : (str_ends_with(strtolower($_logo_file), '.svg') ? 'image/svg+xml' : 'image/jpeg');
    }
}

$issued_date = $req['issued_at'] ? date('d.m.Y', strtotime($req['issued_at'])) : date('d.m.Y');
$cert_number = $req['cert_number'] ?: ('ZAWOL/' . str_pad($req_id, 4, '0', STR_PAD_LEFT) . '/' . date('Y'));
$sign_type   = $req['sign_type'] ?? 'papierowe';
$issuer_name = $req['issued_by_name'] ?? '';
$typ_label   = CONTRACT_TYPES[$type] ?? $type;

$has_file    = !empty($req['certificate_file']);
$has_text    = !empty($req['certificate_content']);
$file_url    = $has_file ? certificate_file_url($req['certificate_file']) : '';
$is_pdf      = $has_file && str_ends_with(strtolower($req['certificate_file']), '.pdf');
?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<title>Zaświadczenie <?= h($cert_number) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@700;800&display=swap" rel="stylesheet">
<style>
/* ════════════════════════════════════════════════════════
   ZAŚWIADCZENIE  ·  druk / PDF  ·  format A4
   ════════════════════════════════════════════════════════ */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

html { font-size: 10.5pt; }

body {
  font-family: 'Times New Roman', Times, serif;
  font-size: 1rem;
  color: #000;
  background: #fff;
  width: 210mm;
  min-height: 297mm;
  margin: 0 auto;
  padding: 16mm 18mm 14mm 25mm;
  line-height: 1.45;
}

/* ── Toolbar (ekran) ──────────────────────────────────── */
.toolbar {
  position: fixed; top: 0; left: 0; right: 0; z-index: 9999;
  background: #14532d; color: #fff;
  padding: .6rem 1.5rem;
  display: flex; align-items: center; gap: 1rem;
  font-family: system-ui, sans-serif; font-size: .88rem;
}
.toolbar button {
  background: #fff; color: #14532d; border: none;
  padding: .4rem 1.2rem; border-radius: 4px;
  font-weight: 700; cursor: pointer; font-size: .88rem;
}
.toolbar a { color: rgba(255,255,255,.8); font-size: .83rem; text-decoration: none; }
.toolbar a:hover { color: #fff; }

/* ── Nagłówek org ─────────────────────────────────────── */
.org-header {
  display: flex; justify-content: space-between; align-items: flex-start;
  padding-bottom: .55rem; border-bottom: 2px solid #000; margin-bottom: .7rem;
  gap: 1rem;
}
.org-logo  { height: 42px; width: auto; display: block; object-fit: contain; flex-shrink: 0; }
.org-left  { display: flex; align-items: center; gap: .85rem; }
.org-name  { font-size: 1rem; font-weight: bold; text-transform: uppercase; letter-spacing: .04em; line-height: 1.3; }
.org-meta  { font-size: .78rem; color: #333; margin-top: .12rem; line-height: 1.4; }
.doc-ref   { text-align: right; flex-shrink: 0; }
.doc-ref-num {
  font-size: .82rem; font-weight: bold; letter-spacing: .03em;
  border: 1.5px solid #14532d; padding: .15rem .5rem;
  display: inline-block; margin-bottom: .2rem; color: #14532d;
}
.doc-ref-date { font-size: .75rem; color: #444; }

/* ── Tytuł ────────────────────────────────────────────── */
.doc-title-wrap {
  text-align: center; margin: .7rem 0 .75rem;
  padding: .6rem 0;
  border-top: 1px solid #888; border-bottom: 1px solid #888;
}
.doc-title {
  font-family: 'Montserrat', 'Arial Black', Arial, sans-serif;
  font-size: 1.7rem; font-weight: 800;
  text-transform: uppercase; letter-spacing: .18em;
  color: #14532d;
}
.doc-subtitle { font-size: .78rem; color: #555; margin-top: .25rem; }

/* ── Treść zaświadczenia ──────────────────────────────── */
.cert-body {
  white-space: pre-wrap; line-height: 1.7;
  font-size: .98rem; text-align: justify;
  margin: .8rem 0 1rem;
}

/* ── Ramka ePodpisu ───────────────────────────────────── */
.epodpis-note {
  border: 1px solid #14532d; padding: .5rem .75rem;
  border-radius: 3px; font-size: .82rem; margin: .6rem 0;
  display: flex; gap: .6rem; align-items: center;
}

/* ── Separator ────────────────────────────────────────── */
.hr-thin { border: none; border-top: 1px solid #bbb; margin: .7rem 0; }

/* ── Podpisy ──────────────────────────────────────────── */
.sign-row { display: flex; gap: 2rem; margin-top: .5rem; }
.sign-block { flex: 1; }
.sign-city  { font-size: .85rem; margin-bottom: .8rem; }
.sign-line  { border-bottom: 1px solid #000; margin-bottom: .2rem; height: 2.2rem; }
.sign-label { font-size: .72rem; text-align: center; color: #333; line-height: 1.35; }

/* ── Stopka ───────────────────────────────────────────── */
.doc-footer {
  margin-top: .7rem; padding-top: .4rem; border-top: 1px solid #ccc;
  font-size: .7rem; color: #777; display: flex; justify-content: space-between;
}

/* ── PDF embed ────────────────────────────────────────── */
.pdf-wrap { width: 100%; height: 80vh; border: 1px solid #dee2e6; display: block; margin-bottom: 1rem; }

/* ── Druk ─────────────────────────────────────────────── */
@media screen {
  body { margin-top: 3rem; box-shadow: 0 0 20px rgba(0,0,0,.18); }
}
@media print {
  body { margin: 0; padding: 12mm 16mm 10mm 22mm; box-shadow: none; }
  .toolbar, .pdf-wrap-screen { display: none !important; }
  @page { size: A4 portrait; margin: 0; }
}
</style>
</head>
<body>

<!-- Toolbar -->
<div class="toolbar" aria-hidden="true">
  <button onclick="window.print()">🖨 Drukuj / PDF</button>
  <?php if ($has_file): ?>
  <a href="<?= h($file_url) ?>" download>⬇ Pobierz plik</a>
  <?php endif; ?>
  <a href="<?= h(contract_url($type, $cid)) ?>">← Wróć do umowy</a>
  <span style="margin-left:auto;opacity:.7"><?= h($cert_number) ?></span>
</div>

<?php if ($has_file && $is_pdf): ?>
<!-- PDF embed — tylko ekran -->
<div class="pdf-wrap-screen" style="margin-bottom:1rem">
  <iframe src="<?= h($file_url) ?>" class="pdf-wrap"
          title="Zaświadczenie — plik PDF z ePodpisem"></iframe>
  <div style="font-family:system-ui,sans-serif;font-size:.82rem;text-align:center;color:#555;padding:.5rem">
    Powyżej: plik PDF z podpisem elektronicznym.
    Poniżej: wersja do wydruku.
    <a href="<?= h($file_url) ?>" download style="color:#14532d">Pobierz plik ↓</a>
  </div>
</div>
<?php elseif ($has_file): ?>
<div class="pdf-wrap-screen no-print text-center p-3">
  <img src="<?= h($file_url) ?>" alt="Zaświadczenie" style="max-width:100%;border:1px solid #dee2e6">
</div>
<?php endif; ?>

<!-- ═══ DOKUMENT ═══════════════════════════════════════════════════════════════ -->

<!-- Nagłówek org -->
<div class="org-header">
  <div class="org-left">
    <?php if ($_logo_b64): ?>
    <img src="data:<?= $_logo_mime ?>;base64,<?= $_logo_b64 ?>"
         alt="Logo <?= h($org) ?>" class="org-logo">
    <?php endif; ?>
    <div>
      <div class="org-name"><?= h($org) ?></div>
      <div class="org-meta">
        <?php if ($org_adres): ?><?= h($org_adres) ?><br><?php endif; ?>
        <?php if ($org_nip): ?>NIP: <?= h($org_nip) ?><?php if ($org_krs): ?> &nbsp;·&nbsp; KRS: <?= h($org_krs) ?><?php endif; ?><?php endif; ?>
      </div>
    </div>
  </div>
  <div class="doc-ref">
    <div class="doc-ref-num">Nr <?= h($cert_number) ?></div>
    <div class="doc-ref-date">
      <?= h($org_city) ?>, dnia <?= $issued_date ?>
    </div>
  </div>
</div>

<!-- Tytuł -->
<div class="doc-title-wrap">
  <div class="doc-title">Zaświadczenie</div>
  <div class="doc-subtitle">
    <?= h($typ_label) ?> · <?= h($row['numer_umowy'] ?? '') ?>
    <?php if ($req['cel']): ?> · cel: <?= h($req['cel']) ?><?php endif; ?>
  </div>
</div>

<!-- Treść -->
<?php if ($has_text): ?>
<div class="cert-body"><?= h($req['certificate_content']) ?></div>
<?php endif; ?>

<!-- Forma podpisania -->
<?php if ($sign_type === 'elektroniczne'): ?>
<div class="epodpis-note">
  <span style="font-size:1.1rem">🔐</span>
  <div>
    <strong>Dokument podpisany elektronicznie</strong> (ePodpis kwalifikowany) —
    podpis weryfikowalny w pliku PDF dołączonym powyżej.
    Dokument papierowy nie wymaga odręcznego podpisu.
  </div>
</div>
<?php endif; ?>

<!-- Podpis -->
<hr class="hr-thin">
<div class="sign-row">
  <div class="sign-block" style="flex:1.3">
    <div class="sign-city">
      <?= h($org_city) ?>, dnia <?= $issued_date ?>
    </div>
    <?php if ($sign_type === 'papierowe'): ?>
    <div class="sign-line"></div>
    <div class="sign-label">
      (podpis osoby upoważnionej do wystawienia zaświadczenia)
      <?php if ($issuer_name): ?><br><strong><?= h($issuer_name) ?></strong><?php endif; ?>
    </div>
    <?php else: ?>
    <div class="sign-label" style="padding-top:.3rem;border-top:1px solid #888;text-align:left">
      <strong>ePodpis:</strong> <?= $issuer_name ? h($issuer_name) : 'podpisano elektronicznie' ?>
    </div>
    <?php endif; ?>
  </div>
  <div style="flex:.4"></div>
</div>

<!-- Stopka -->
<div class="doc-footer">
  <span>
    <?= h($org) ?>
    · zaświadczenie wydane dla: <?= h($req['requester_name']) ?>
  </span>
  <span><?= h($cert_number) ?> · <?= $issued_date ?></span>
</div>

</body>
</html>
