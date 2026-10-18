<?php
/**
 * Publiczna weryfikacja zaświadczenia po kodzie QR.
 * Dostępna bez logowania — pokazuje tylko podstawowe informacje.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
// zaswiadczenia_ezd.php inicjalizuje schemat — nie wymaga zalogowania
require_once dirname(dirname(__DIR__)) . '/includes/zaswiadczenia_ezd.php';

$code = trim($_GET['code'] ?? '');
$zas  = $code !== '' ? ezd_zas_by_verify_code($code) : null;

$org = function_exists('org_setting') ? (org_setting('org_name') ?: '') : (defined('ORG_NAME') ? ORG_NAME : '');
$logo_file = function_exists('org_setting') ? (org_setting('ezd_logo') ?: org_setting('org_logo') ?: '') : '';
$logo_path = $logo_file ? dirname(dirname(__DIR__)) . '/assets/logo/' . $logo_file : '';
$logo_html = '';
if ($logo_path && file_exists($logo_path)) {
    $b64 = base64_encode(file_get_contents($logo_path));
    $logo_html = '<img src="data:' . mime_content_type($logo_path) . ';base64,' . $b64
               . '" style="max-height:48px;max-width:160px" alt="' . h($org) . '">';
}

if ($zas) {
    $wdt     = $zas['wazne_do'] ? strtotime($zas['wazne_do']) : 0;
    $expired = $wdt && $wdt < time();
    $valid   = !$expired;
}
?><!doctype html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Weryfikacja zaświadczenia — <?= h($org) ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
  /* Szata Metro UI jak System Tożsamości: zero zaokrągleń i cieni, statusy jako pas z grubą lewą krawędzią */
  * { box-sizing: border-box; }
  body { font-family: "Segoe UI","Segoe UI Web (West European)",system-ui,-apple-system,Roboto,sans-serif; background: #fff; color: #111827; margin: 0; padding: 0 0 24px; -webkit-font-smoothing: antialiased; }
  body::before { content: ""; display: block; height: 48px; background: #1d1d1d; }
  .card { background: #fff; border: 1px solid #e1e1e1; border-radius: 0; box-shadow: none; max-width: 520px; margin: 32px auto; padding: 32px; }
  @media (max-width: 560px) { .card { margin: 16px; padding: 22px; } .card .org-header { margin: -22px -22px 20px; padding: 14px 22px; } }
  .org-header { display: flex; align-items: center; gap: 14px; margin: -32px -32px 24px; padding: 16px 32px; background: #f2f2f2; border-bottom: 0; }
  .status-ok, .status-err, .status-exp { color: #111827; background: #f2f2f2; border: 0; border-left: 8px solid #007a00; border-radius: 0; padding: 16px 20px; }
  .status-err { border-left-color: #a20025; }
  .status-exp { border-left-color: #c25100; }
  dl { margin: 0; }
  dt { font-size: .7rem; color: #5f5f5f; margin-top: 12px; text-transform: uppercase; letter-spacing: .1em; font-weight: 600; }
  dd { font-size: 1.02rem; font-weight: 400; margin: .1rem 0 0; }
  .footer-note { text-align: center; font-size: .74rem; color: #5f5f5f; margin-top: 24px; }
</style>
</head>
<body>

<div class="card">
  <div class="org-header">
    <?= $logo_html ?>
    <div>
      <div style="font-weight:700;font-size:1.05rem"><?= h($org) ?></div>
      <div style="font-size:.78rem;color:#888">Weryfikacja zaświadczenia</div>
    </div>
  </div>

  <?php if (!$code): ?>
  <div class="status-err">
    <i class="bi bi-qr-code-scan me-2"></i>Brak kodu weryfikacyjnego — zeskanuj kod QR z dokumentu.
  </div>

  <?php elseif (!$zas): ?>
  <div class="status-err">
    <i class="bi bi-x-circle-fill me-2"></i><strong>Zaświadczenie nieautentyczne</strong>
    <p style="margin:.8rem 0 0;font-size:.9rem">Kod weryfikacyjny nie istnieje lub zaświadczenie zostało unieważnione.</p>
  </div>

  <?php elseif ($expired): ?>
  <div class="status-exp">
    <i class="bi bi-exclamation-triangle-fill me-2"></i><strong>Zaświadczenie wygasłe</strong>
    <dl style="margin-top:14px">
      <dt>Numer</dt>
      <dd class="font-monospace"><?= h($zas['nr_zaswiadczenia']) ?></dd>
      <dt>Typ</dt>
      <dd><?= h($zas['typ_nazwa']) ?></dd>
      <dt>Wystawiony dla</dt>
      <dd><?= h($zas['wnioskodawca_name']) ?></dd>
      <dt>Data wydania</dt>
      <dd><?= $zas['zatwierdzone_at'] ? date('d.m.Y', strtotime($zas['zatwierdzone_at'])) : '—' ?></dd>
      <dt>Ważne do</dt>
      <dd style="color:#b91c1c"><?= $zas['wazne_do'] ? date('d.m.Y', strtotime($zas['wazne_do'])) : '—' ?></dd>
    </dl>
  </div>

  <?php else: ?>
  <div class="status-ok">
    <div style="font-size:1.15rem;font-weight:700;margin-bottom:14px">
      <i class="bi bi-patch-check-fill me-2"></i>Zaświadczenie autentyczne
    </div>
    <dl>
      <dt>Numer</dt>
      <dd class="font-monospace"><?= h($zas['nr_zaswiadczenia']) ?></dd>
      <dt>Typ</dt>
      <dd><?= h($zas['typ_nazwa']) ?></dd>
      <dt>Wystawiony dla</dt>
      <dd><?= h($zas['wnioskodawca_name']) ?></dd>
      <dt>Data wydania</dt>
      <dd><?= $zas['zatwierdzone_at'] ? date('d.m.Y', strtotime($zas['zatwierdzone_at'])) : '—' ?></dd>
      <?php if ($zas['wazne_do']): ?>
      <dt>Ważne do</dt>
      <dd><?= date('d.m.Y', strtotime($zas['wazne_do'])) ?></dd>
      <?php endif; ?>
    </dl>
  </div>
  <?php endif; ?>

  <p class="footer-note"><?= h($org) ?> &bull; Automatyczna weryfikacja dokumentu &bull; <?= date('d.m.Y') ?></p>
</div>

</body>
</html>
