<?php
/**
 * contracts/print_template.php
 * Generuje dokument z szablonu wzoru, podstawiając dane umowy.
 *
 * GET params:
 *   template_id  (int)    — ID szablonu
 *   contract_id  (int)    — ID umowy (opcjonalne; bez niego: podgląd z pustymi danymi)
 *   type         (string) — typ umowy: wolontariat|zlecenie|dzielo|praca (opcjonalne)
 *   preview      (1)      — podgląd (bez auto-print)
 */
if (!defined('APP_INSTALLED')) require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/contract_template_engine.php';

require_login();

$template_id = (int)($_GET['template_id'] ?? 0);
$contract_id = (int)($_GET['contract_id'] ?? 0);
$type        = $_GET['type'] ?? 'wolontariat';
$is_preview  = isset($_GET['preview']);

if (!$template_id) { http_response_code(400); exit('Brak parametru template_id.'); }

cte_migrate();

$tpl = db_one("SELECT * FROM contract_doc_templates WHERE id=?", [$template_id]);
if (!$tpl) { http_response_code(404); exit('Szablon nie istnieje.'); }

// Jeśli podano ID umowy — pobierz dane z właściwej tabeli
$row = [];
if ($contract_id && in_array($type, ['wolontariat', 'zlecenie', 'dzielo', 'praca'], true)) {
    $table_map = [
        'wolontariat' => 'umowy_wolontariat',
        'zlecenie'    => 'umowy_zlecenie',
        'dzielo'      => 'umowy_dzielo',
        'praca'       => 'umowy_praca',
    ];
    $table = $table_map[$type];
    $row   = db_one("SELECT * FROM {$table} WHERE id=?", [$contract_id]) ?: [];
}

$map  = cte_build_map($type, $row);
$html = cte_render($tpl['body'], $map);

$stored  = org_setting('org_name');
$const   = defined('ORG_NAME') ? ORG_NAME : '';
$org_name = (strlen($const) > strlen($stored)) ? $const : ($stored ?: $const);
$org_adres = org_setting('org_adres') ?: '';
$org_nip   = org_setting('org_nip') ?: '';
$org_krs   = org_setting('org_krs') ?: '';
$org_city  = org_setting('org_miejscowosc') ?: '';

// Logo base64
$_logo_b64  = ''; $_logo_mime = 'image/png';
$_logo_file = org_setting('org_logo');
if ($_logo_file) {
    $lpath = dirname(__DIR__) . '/assets/logo/' . basename($_logo_file);
    if (file_exists($lpath) && filesize($lpath) < 500_000) {
        $_logo_b64  = base64_encode(file_get_contents($lpath));
        $_logo_mime = str_ends_with(strtolower($_logo_file), '.svg') ? 'image/svg+xml'
                    : (str_ends_with(strtolower($_logo_file), '.jpg') ? 'image/jpeg' : 'image/png');
    }
}

$doc_date = date('d.m.Y');
$doc_ref  = !empty($row['numer_umowy']) ? $row['numer_umowy'] : ($org_city ? $org_city . ', ' . $doc_date : $doc_date);
?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<title><?= h($tpl['name']) ?><?= $row ? ' — ' . h($row['imie_nazwisko'] ?? '') : '' ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Lato:ital,wght@0,400;0,700;0,900;1,400&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; }
html { font-size: 11pt; }
body {
    font-family: 'Lato', 'Segoe UI', Arial, sans-serif;
    color: #111;
    margin: 0;
    padding: 0;
    background: #fff;
}

/* ── Nagłówek organizacji (jak w zaświadczeniach) ── */
.doc-org-header {
    display: flex; justify-content: space-between; align-items: flex-start;
    border-bottom: 2px solid #000; padding-bottom: .6rem; margin-bottom: .75rem;
    gap: 1rem;
}
.doc-org-left  { display: flex; align-items: center; gap: .8rem; }
.doc-org-logo  { height: 40px; width: auto; object-fit: contain; display: block; flex-shrink: 0; }
.doc-org-name  { font-size: .9rem; font-weight: 900; text-transform: uppercase; letter-spacing: .04em; line-height: 1.2; }
.doc-org-meta  { font-size: .74rem; color: #444; margin-top: .12rem; line-height: 1.4; }
.doc-org-ref   { text-align: right; flex-shrink: 0; }
.doc-org-ref-num {
    font-size: .78rem; font-weight: 700; letter-spacing: .04em;
    border: 1.5px solid #000; padding: .1rem .5rem;
    display: inline-block; margin-bottom: .1rem;
}
.doc-org-ref-date { font-size: .73rem; color: #555; }

/* ── Tytuł szablonu ── */
.doc-title-wrap {
    text-align: center; padding: .6rem 0 .5rem;
}
.doc-title { font-size: 1.15rem; font-weight: 900; text-transform: uppercase; letter-spacing: .08em; }

/* ── Podgląd w przeglądarce ── */
@media screen {
    body { background: #e5e7eb; }
    .page {
        width: 210mm;
        min-height: 297mm;
        margin: 2rem auto;
        padding: 22mm 20mm 18mm;
        background: #fff;
        box-shadow: 0 4px 32px rgba(0,0,0,.18);
        border-radius: 2px;
    }
    .preview-bar {
        position: fixed; top: 0; left: 0; right: 0;
        background: #1e3a5f; color: #fff;
        display: flex; align-items: center; gap: .75rem;
        padding: .6rem 1.25rem;
        font-family: system-ui, sans-serif; font-size: .85rem;
        z-index: 100; box-shadow: 0 2px 8px rgba(0,0,0,.25);
    }
    .preview-bar strong { font-size: .92rem; }
    .preview-bar .pbar-actions { margin-left: auto; display: flex; gap: .5rem; }
    .preview-bar a, .preview-bar button {
        background: rgba(255,255,255,.15); color: #fff; border: 1px solid rgba(255,255,255,.3);
        border-radius: 6px; padding: .3rem .8rem; font-size: .8rem; cursor: pointer;
        text-decoration: none; font-family: inherit;
    }
    .preview-bar a:hover, .preview-bar button:hover { background: rgba(255,255,255,.25); }
    .page-wrap { padding-top: 54px; }

    <?php if (!$is_preview): ?>
    /* Jeśli nie preview — ukryj pasek */
    .preview-bar, .page-wrap { padding-top: 0; }
    .page { margin: 0; box-shadow: none; border-radius: 0; }
    <?php endif; ?>
}

/* ── Print ── */
@media print {
    .preview-bar { display: none !important; }
    .page-wrap { padding-top: 0 !important; }
    .page {
        width: 100%; min-height: auto;
        padding: 15mm 18mm 15mm;
        box-shadow: none; border-radius: 0; margin: 0;
        border: none;
    }
}

/* ── Typografia dokumentu ── */
.page h1 { font-size: 1.15rem; text-align: center; margin-bottom: .5rem; }
.page h2 { font-size: 1rem; margin-top: 1.2rem; margin-bottom: .4rem; }
.page h3 { font-size: .95rem; margin-top: 1rem; margin-bottom: .3rem; }
.page p  { line-height: 1.65; margin-bottom: .6rem; text-align: justify; }
.page ul, .page ol { margin-bottom: .6rem; padding-left: 1.4rem; line-height: 1.6; }
.page table { width: 100%; border-collapse: collapse; margin-bottom: .8rem; font-size: .9rem; }
.page table td, .page table th { border: 1px solid #aaa; padding: .35rem .55rem; }
.page table th { background: #f3f4f6; font-weight: 600; }

/* Zaznaczenie nieuzupełnionych zmiennych (pozostałe {…}) */
.page [data-unfilled] { background: #fef9c3; outline: 1px dashed #ca8a04; padding: 0 2px; }
</style>
</head>
<body>

<?php if ($is_preview): ?>
<div class="preview-bar">
  <i class="bi bi-file-earmark-text" style="font-size:1.1rem"></i>
  <strong><?= h($tpl['name']) ?></strong>
  <?php if ($row): ?>
    <span style="opacity:.65">— <?= h($row['imie_nazwisko'] ?? '') ?></span>
  <?php else: ?>
    <span style="opacity:.5;font-size:.78rem">(podgląd wzoru — bez danych umowy)</span>
  <?php endif; ?>
  <div class="pbar-actions">
    <a href="<?= h(APP_URL) ?>/admin/contract_templates.php">← Wróć</a>
    <a href="<?= h(APP_URL) ?>/contracts/download_template_docx.php?template_id=<?= $template_id ?>&contract_id=<?= $contract_id ?>&type=<?= h($type) ?>">⬇ DOCX</a>
    <?php if ($contract_id && !empty($row['email'])): ?>
    <button onclick="document.getElementById('emailSendForm').submit()" style="background:rgba(255,255,255,.15)">
      📧 Wyślij na e-mail
    </button>
    <form id="emailSendForm" method="post"
          action="<?= APP_URL ?>/contracts/email_template_doc.php"
          style="display:none">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="template_id"  value="<?= $template_id ?>">
      <input type="hidden" name="contract_id"  value="<?= $contract_id ?>">
      <input type="hidden" name="type"         value="<?= h($type) ?>">
      <input type="hidden" name="return_url"   value="<?= h($_SERVER['REQUEST_URI'] ?? APP_URL) ?>">
    </form>
    <?php elseif ($contract_id && empty($row['email'])): ?>
    <span style="opacity:.5;font-size:.78rem" title="Brak e-mail w umowie">📧 brak e-mail</span>
    <?php endif; ?>
    <button onclick="window.print()">🖨 Drukuj / PDF</button>
  </div>
</div>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<?php endif; ?>

<div class="page-wrap">
<div class="page">

  <!-- Nagłówek organizacji -->
  <div class="doc-org-header">
    <div class="doc-org-left">
      <?php if ($_logo_b64): ?>
      <img src="data:<?= h($_logo_mime) ?>;base64,<?= $_logo_b64 ?>"
           alt="" role="presentation" class="doc-org-logo">
      <?php endif; ?>
      <div>
        <div class="doc-org-name"><?= h($org_name) ?></div>
        <?php if ($org_adres || $org_nip): ?>
        <div class="doc-org-meta">
          <?php if ($org_adres): ?><?= h($org_adres) ?><?php endif; ?>
          <?php if ($org_nip): ?><br>NIP: <?= h($org_nip) ?><?php if ($org_krs): ?> · KRS: <?= h($org_krs) ?><?php endif; ?><?php endif; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <div class="doc-org-ref">
      
      <div class="doc-org-ref-date"><?= h($org_city ?: 'Miejscowość') ?>, <?= $doc_date ?></div>
    </div>
  </div>

  <!-- Tytuł dokumentu -->
  <div class="doc-title-wrap">
    <div class="doc-title"><?= h($tpl['name']) ?></div>
  </div>

  <!-- Treść z podstawionymi zmiennymi -->
  <?= $html ?>

</div>
</div>

<?php if (!$is_preview): ?>
<script>window.addEventListener('load', function() { window.print(); });</script>
<?php endif; ?>

</body>
</html>
