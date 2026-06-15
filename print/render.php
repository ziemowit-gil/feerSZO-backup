<?php
/**
 * print/render.php
 * Uniwersalny render wzoru wydruku — podgląd lub gotowy do druku/PDF.
 *
 * GET:
 *   template_id (int)  — ID wzoru (wymagane)
 *   contract_id (int)  — opcjonalnie: dane umowy
 *   type        (str)  — typ umowy: wolontariat|zlecenie|dzielo|praca
 *   cert_id     (int)  — opcjonalnie: wniosek o zaświadczenie (treść + numer)
 *   preview     (1)    — pasek narzędzi zamiast auto-druku
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/print_templates.php';

require_login();

$template_id = (int)($_GET['template_id'] ?? 0);
$contract_id = (int)($_GET['contract_id'] ?? 0);
$cert_id     = (int)($_GET['cert_id'] ?? 0);
$type        = $_GET['type'] ?? 'wolontariat';
$is_preview  = isset($_GET['preview']);

if (!$template_id) { http_response_code(400); exit('Brak parametru template_id.'); }

$tpl = pt_get($template_id);
if (!$tpl) { http_response_code(404); exit('Wzór nie istnieje.'); }

$ctx = ['type' => $type, 'sample' => true];

if ($contract_id && in_array($type, ['wolontariat', 'zlecenie', 'dzielo', 'praca'], true)) {
    $table = ['wolontariat'=>'umowy_wolontariat','zlecenie'=>'umowy_zlecenie',
              'dzielo'=>'umowy_dzielo','praca'=>'umowy_praca'][$type];
    $ctx['row']    = db_one("SELECT * FROM {$table} WHERE id=?", [$contract_id]) ?: [];
    $ctx['sample'] = false;
}

if ($cert_id) {
    require_once dirname(__DIR__) . '/includes/certificates.php';
    $req = get_certificate_request($cert_id);
    if ($req) {
        $ctx['req']    = $req;
        $ctx['type']   = $req['contract_type'];
        $ctx['sample'] = false;
        if (empty($ctx['row'])) {
            $t = table_for_type($req['contract_type']);
            $ctx['row'] = db_one("SELECT * FROM {$t} WHERE id=?", [$req['contract_id']]) ?: [];
        }
    }
}

$opts = pt_options($tpl);
$map  = pt_build_map($ctx);
$doc  = pt_document_html($tpl, $map);
?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<title><?= h($tpl['name']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Lato:ital,wght@0,400;0,700;0,900;1,400&display=swap" rel="stylesheet">
<style>
<?= pt_document_css($opts['orientation'] ?? 'portrait') ?>

@media screen {
  body { background:#e5e7eb; }
  .pt-page { margin:2rem auto; box-shadow:0 4px 32px rgba(0,0,0,.18); border-radius:2px; }
  .pbar {
    position:fixed; top:0; left:0; right:0; background:#1e3a5f; color:#fff;
    display:flex; align-items:center; gap:.75rem; padding:.6rem 1.25rem;
    font-family:system-ui,sans-serif; font-size:.85rem; z-index:100;
    box-shadow:0 2px 8px rgba(0,0,0,.25);
  }
  .pbar strong { font-size:.92rem; }
  .pbar .pbar-actions { margin-left:auto; display:flex; gap:.5rem; }
  .pbar a, .pbar button {
    background:rgba(255,255,255,.15); color:#fff; border:1px solid rgba(255,255,255,.3);
    border-radius:6px; padding:.3rem .8rem; font-size:.8rem; cursor:pointer;
    text-decoration:none; font-family:inherit;
  }
  .pbar a:hover, .pbar button:hover { background:rgba(255,255,255,.25); }
  .pt-wrap { padding-top:<?= $is_preview ? '54px' : '0' ?>; }
  <?php if (!$is_preview): ?>
  .pt-page { margin:0; box-shadow:none; border-radius:0; }
  <?php endif; ?>
}
@media print {
  .pbar { display:none !important; }
  .pt-wrap { padding-top:0 !important; }
  .pt-page { box-shadow:none; border-radius:0; margin:0; }
}
</style>
</head>
<body>

<?php if ($is_preview): ?>
<div class="pbar">
  <i class="bi bi-printer"></i>
  <strong><?= h($tpl['name']) ?></strong>
  <span style="opacity:.6;font-size:.78rem"><?= h(pt_category_label($tpl['category'])) ?><?= $ctx['sample'] ? ' · podgląd (dane przykładowe)' : '' ?></span>
  <div class="pbar-actions">
    <a href="<?= h(APP_URL) ?>/admin/print_templates.php">← Wróć</a>
    <button onclick="window.print()">🖨 Drukuj / PDF</button>
  </div>
</div>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<?php endif; ?>

<div class="pt-wrap">
  <?= $doc ?>
</div>

<?php if (!$is_preview): ?>
<script>window.addEventListener('load', function(){ window.print(); });</script>
<?php endif; ?>
</body>
</html>
