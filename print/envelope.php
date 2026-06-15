<?php
/**
 * print/envelope.php
 * Render wzoru koperty — podgląd lub gotowy do druku.
 *
 * GET:
 *   template_id (int)  — ID wzoru koperty (wymagane)
 *   contract_id (int)  — opcjonalnie: adresat z umowy
 *   type        (str)  — typ umowy: wolontariat|zlecenie|dzielo|praca
 *   person_id   (int)  — opcjonalnie: adresat z osoby (CRM)
 *   r_name      (str)  — adresat ręcznie: imię i nazwisko / nazwa
 *   r_addr      (str)  — adresat ręcznie: adres (linie rozdzielone \n)
 *   preview     (1)    — dane przykładowe + pasek narzędzi
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/envelopes.php';

require_login();

$template_id = (int)($_GET['template_id'] ?? 0);
$contract_id = (int)($_GET['contract_id'] ?? 0);
$person_id   = (int)($_GET['person_id'] ?? 0);
$type        = $_GET['type'] ?? 'wolontariat';
$is_preview  = isset($_GET['preview']);

if (!$template_id) { http_response_code(400); exit('Brak parametru template_id.'); }

$tpl = env_get($template_id);
if (!$tpl) { http_response_code(404); exit('Wzór koperty nie istnieje.'); }

$fmt = env_format($tpl['format']);
$o   = env_options($tpl);

/* ── Ustal adresata ───────────────────────────────────────────────────────── */
$ctx = [];
$need_form = false;

if ($is_preview) {
    $ctx['sample'] = true;
} elseif ($contract_id && in_array($type, ['wolontariat','zlecenie','dzielo','praca'], true)) {
    $table = ['wolontariat'=>'umowy_wolontariat','zlecenie'=>'umowy_zlecenie',
              'dzielo'=>'umowy_dzielo','praca'=>'umowy_praca'][$type];
    $ctx['row'] = db_one("SELECT * FROM {$table} WHERE id=?", [$contract_id]) ?: [];
} elseif ($person_id) {
    $ctx['row'] = db_one("SELECT * FROM persons WHERE id=?", [$person_id]) ?: [];
} elseif (isset($_GET['r_name']) || isset($_GET['r_addr'])) {
    $ctx['recipient'] = [
        'name' => trim((string)($_GET['r_name'] ?? '')),
        'addr' => trim((string)($_GET['r_addr'] ?? '')),
    ];
} else {
    $need_form = true;
}

/* ── Formularz ręcznego adresata (gdy brak źródła) ────────────────────────── */
if ($need_form) {
    $PAGE_TITLE = 'Koperta — adresat';
    ?>
    <!DOCTYPE html><html lang="pl"><head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Koperta — adresat</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    </head><body class="bg-light">
    <div class="container" style="max-width:520px;margin-top:3rem">
      <div class="card shadow-sm">
        <div class="card-body">
          <h5 class="fw-bold mb-1"><i class="bi bi-envelope text-primary me-2"></i><?= h($tpl['name']) ?></h5>
          <p class="text-muted small mb-3"><?= h($fmt['label']) ?> — wpisz dane adresata, aby wydrukować kopertę.</p>
          <form method="get">
            <input type="hidden" name="template_id" value="<?= (int)$template_id ?>">
            <div class="mb-2">
              <label class="form-label small fw-semibold">Imię i nazwisko / nazwa</label>
              <input name="r_name" class="form-control" autofocus value="<?= h($_GET['r_name'] ?? '') ?>">
            </div>
            <div class="mb-3">
              <label class="form-label small fw-semibold">Adres (ulica, kod i miejscowość — każde w nowej linii)</label>
              <textarea name="r_addr" class="form-control" rows="3" placeholder="ul. Przykładowa 12/3&#10;00-001 Warszawa"><?= h($_GET['r_addr'] ?? '') ?></textarea>
            </div>
            <div class="d-flex gap-2">
              <a href="<?= h(APP_URL) ?>/admin/envelope_templates.php" class="btn btn-outline-secondary">Anuluj</a>
              <button class="btn btn-primary ms-auto"><i class="bi bi-printer me-1"></i>Drukuj kopertę</button>
            </div>
          </form>
        </div>
      </div>
    </div>
    </body></html>
    <?php
    exit;
}

$envelope = env_render_html($tpl, $ctx);
?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<title><?= h($tpl['name']) ?></title>
<?php if (($o['font'] ?? 'montserrat') !== 'arial'): ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;700&display=swap" rel="stylesheet">
<?php endif; ?>
<style>
<?= env_css($tpl['format'], $o['font'] ?? 'montserrat', $o['font_size'] ?? 'normal') ?>

@media screen {
  body { background:#e5e7eb; }
  .env { margin:2rem auto; box-shadow:0 4px 32px rgba(0,0,0,.18); }
  .ebar {
    position:fixed; top:0; left:0; right:0; background:#1e3a5f; color:#fff;
    display:flex; align-items:center; gap:.75rem; padding:.6rem 1.25rem;
    font-family:system-ui,sans-serif; font-size:.85rem; z-index:100;
    box-shadow:0 2px 8px rgba(0,0,0,.25);
  }
  .ebar strong { font-size:.92rem; }
  .ebar .ebar-actions { margin-left:auto; display:flex; gap:.5rem; }
  .ebar a, .ebar button {
    background:rgba(255,255,255,.15); color:#fff; border:1px solid rgba(255,255,255,.3);
    border-radius:6px; padding:.3rem .8rem; font-size:.8rem; cursor:pointer;
    text-decoration:none; font-family:inherit;
  }
  .ebar a:hover, .ebar button:hover { background:rgba(255,255,255,.25); }
  .env-wrap { padding-top:<?= $is_preview ? '54px' : '0' ?>; }
  <?php if (!$is_preview): ?>
  .env { margin:0; box-shadow:none; }
  <?php endif; ?>
}
@media print {
  .ebar { display:none !important; }
  .env-wrap { padding-top:0 !important; }
  .env { box-shadow:none; margin:0; }
}
</style>
</head>
<body>

<?php if ($is_preview): ?>
<div class="ebar">
  <i class="bi bi-envelope"></i>
  <strong><?= h($tpl['name']) ?></strong>
  <span style="opacity:.6;font-size:.78rem"><?= h($fmt['label']) ?> · podgląd (dane przykładowe)</span>
  <div class="ebar-actions">
    <a href="<?= h(APP_URL) ?>/admin/envelope_templates.php">← Wróć</a>
    <button onclick="window.print()">🖨 Drukuj</button>
  </div>
</div>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<?php endif; ?>

<div class="env-wrap">
  <?= $envelope ?>
</div>

<?php if (!$is_preview): ?>
<script>window.addEventListener('load', function(){ window.print(); });</script>
<?php endif; ?>
</body>
</html>
