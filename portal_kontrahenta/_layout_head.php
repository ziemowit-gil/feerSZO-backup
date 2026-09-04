<?php
/**
 * portal_kontrahenta/_layout_head.php — wspólny szkielet stron Portalu Kontrahenta.
 * Samodzielny (NIE includes/header.php — to osobna, publiczna powierzchnia dla
 * zewnętrznych kontrahentów, nie personelu). Pasek dostępności: kontrast +
 * wielkość liter, stan w localStorage, zgodnie ze stylem WCAG innych portali
 * publicznych (Login.gov.pl itp.) — ale logowanie tu jest własne (NIP/e-mail
 * + hasło), bez integracji z węzłem krajowym eID.
 * Oczekuje zmiennej $PAGE_TITLE i opcjonalnie $BREADCRUMB (string).
 */
$org = defined('ORG_NAME') ? ORG_NAME : 'Portal Kontrahenta';
$logo = org_setting('org_logo');
?>
<!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($PAGE_TITLE ?? 'Portal Kontrahenta') ?> — <?= h($org) ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
  :root {
    --pk-blue: #0857a6; --pk-blue-dark: #063f7a; --pk-bg: #ffffff; --pk-ink: #1b1b1b;
    --pk-muted: #5a5a5a; --pk-border: #d8dde3; --pk-panel: #f5f7fa; --pk-fs: 16px;
  }
  html[data-contrast="high"] {
    --pk-blue: #ffe600; --pk-blue-dark: #ffe600; --pk-bg: #000000; --pk-ink: #ffffff;
    --pk-muted: #e5e5e5; --pk-border: #ffe600; --pk-panel: #111111;
  }
  html[data-fontsize="lg"]  { --pk-fs: 19px; }
  html[data-fontsize="xl"]  { --pk-fs: 22px; }
  * { box-sizing: border-box; }
  html { font-size: var(--pk-fs); }
  body { margin: 0; font-family: -apple-system, Segoe UI, Roboto, Arial, sans-serif; background: var(--pk-bg); color: var(--pk-ink); }
  a { color: var(--pk-blue-dark); }
  html[data-contrast="high"] a { color: var(--pk-blue); text-decoration: underline; }
  .pk-topbar { display: flex; align-items: center; justify-content: space-between; padding: 14px 24px; border-bottom: 1px solid var(--pk-border); flex-wrap: wrap; gap: 10px; }
  .pk-brand { display: flex; align-items: center; gap: 10px; font-weight: 800; font-size: 1.15rem; color: var(--pk-blue-dark); text-decoration: none; }
  html[data-contrast="high"] .pk-brand { color: var(--pk-blue); }
  .pk-brand img { height: 32px; width: auto; border-radius: 4px; }
  .pk-a11y { display: flex; align-items: center; gap: 14px; font-size: .82rem; }
  .pk-a11y-group { display: flex; align-items: center; gap: 6px; }
  .pk-a11y button {
    border: 1px solid var(--pk-border); background: var(--pk-bg); color: var(--pk-ink);
    border-radius: 4px; padding: 3px 8px; font-weight: 700; cursor: pointer; font-size: .85rem;
  }
  .pk-a11y button.active { background: var(--pk-blue-dark); color: #fff; border-color: var(--pk-blue-dark); }
  html[data-contrast="high"] .pk-a11y button.active { background: var(--pk-blue); color: #000; }
  .pk-crumb { padding: 10px 24px; font-size: .85rem; color: var(--pk-muted); border-bottom: 1px solid var(--pk-border); }
  .pk-crumb a { text-decoration: none; }
  .pk-wrap { max-width: 1100px; margin: 0 auto; padding: 32px 24px 60px; }
  .pk-card { background: var(--pk-bg); border: 1px solid var(--pk-border); border-radius: 8px; padding: 28px; max-width: 480px; }
  .pk-card.wide { max-width: 100%; }
  .pk-card h1 { font-size: 1.3rem; margin: 0 0 16px; }
  .pk-field { margin-bottom: 16px; }
  .pk-field label { display: block; font-size: .85rem; font-weight: 600; margin-bottom: 4px; color: var(--pk-muted); }
  .pk-field input, .pk-field select {
    width: 100%; padding: 9px 11px; border: 1px solid var(--pk-border); border-radius: 5px;
    font-size: 1rem; background: var(--pk-bg); color: var(--pk-ink);
  }
  .pk-btn {
    display: inline-block; background: var(--pk-blue-dark); color: #fff; border: none; border-radius: 5px;
    padding: 10px 22px; font-weight: 700; font-size: 1rem; cursor: pointer; text-decoration: none;
  }
  html[data-contrast="high"] .pk-btn { background: var(--pk-blue); color: #000; }
  .pk-btn:hover { opacity: .92; }
  .pk-alert { padding: 10px 14px; border-radius: 6px; margin-bottom: 16px; font-size: .9rem; }
  .pk-alert-danger  { background: #fdecec; color: #8a1c1c; border: 1px solid #f3b9b9; }
  .pk-alert-success { background: #eaf7ee; color: #146c32; border: 1px solid #b6e3c2; }
  .pk-alert-info    { background: #eaf2fb; color: #0857a6; border: 1px solid #b9d3ef; }
  html[data-contrast="high"] .pk-alert { background: #111; border: 1px solid var(--pk-blue); color: var(--pk-ink); }
  .pk-table { width: 100%; border-collapse: collapse; font-size: .92rem; }
  .pk-table th, .pk-table td { padding: 9px 10px; border-bottom: 1px solid var(--pk-border); text-align: left; }
  .pk-table th { background: var(--pk-panel); font-size: .78rem; text-transform: uppercase; letter-spacing: .03em; color: var(--pk-muted); }
  .pk-badge { display: inline-block; padding: 2px 9px; border-radius: 20px; font-size: .78rem; font-weight: 700; background: var(--pk-panel); border: 1px solid var(--pk-border); }
  .pk-footer { text-align: center; padding: 24px; color: var(--pk-muted); font-size: .8rem; }
</style>
</head>
<body>
<div class="pk-topbar">
  <a class="pk-brand" href="<?= APP_URL ?>/portal_kontrahenta/index.php">
    <?php if ($logo): ?><img src="<?= APP_URL ?>/assets/logo/<?= h($logo) ?>" alt=""><?php endif; ?>
    Portal Kontrahenta — <?= h($org) ?>
  </a>
  <div class="pk-a11y">
    <div class="pk-a11y-group">
      <span>Kontrast:</span>
      <button type="button" data-pk-contrast="normal">Normalny</button>
      <button type="button" data-pk-contrast="high">Wysoki</button>
    </div>
    <div class="pk-a11y-group">
      <span>Wielkość liter:</span>
      <button type="button" data-pk-fontsize="md">A</button>
      <button type="button" data-pk-fontsize="lg">A</button>
      <button type="button" data-pk-fontsize="xl">A</button>
    </div>
  </div>
</div>
<?php if (!empty($BREADCRUMB)): ?>
<div class="pk-crumb"><?= $BREADCRUMB ?></div>
<?php endif; ?>
<div class="pk-wrap">
