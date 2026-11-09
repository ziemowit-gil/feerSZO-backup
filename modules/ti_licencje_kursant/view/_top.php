<?php
if (!isset($org)) { header('Location: /karty30/ti/kursant/licencje/', true, 302); exit; }   // widok tylko przez router modułu (bezpośredni adres pliku → panel)
/**
 * modules/ti_licencje_kursant/view/_top.php — szkielet panelu licencji w stylu Portalu Kontrahenta (portal_kontrahenta/_layout_head.php):
 * biały, gov.pl-podobny pasek z marką, pasek dostępności (kontrast + wielkość liter, localStorage), okruszki, dwie kolumny: menu po lewej, treść.
 * Treść korzysta z Tailwind CDN (prefiks tw-) i komponentów m-* przemalowanych na paletę portalu. Bez menu (strona logowania): $nav = null.
 * Zmienne: $org, $PAGE_TITLE, $me (opcjonalnie), $nav (opcjonalnie: lista [anchor, etykieta, odznaka]), $flash (opcjonalnie).
 */
$PAGE_TITLE = $PAGE_TITLE ?? 'Panel licencji';
$nav = $nav ?? null;
?><!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= h($PAGE_TITLE) ?> — <?= h($org) ?></title>
<script>try { var _c = localStorage.getItem('lp_contrast'), _f = localStorage.getItem('lp_fontsize'); if (_c) document.documentElement.dataset.contrast = _c; if (_f) document.documentElement.dataset.fontsize = _f; } catch (e) {}</script>
<script src="https://cdn.tailwindcss.com/3.4.5"></script>
<script>tailwind.config = { prefix: 'tw-', corePlugins: { preflight: true }, theme: { extend: {
  colors: { m: { cyan: '#0857a6', cobalt: '#063f7a', green: '#1e7b3a', red: '#b42318', amber: '#f0a30a', orange: '#c2410c', steel: '#5a5a5a', dark: '#1b1b1b', line: '#d8dde3', bg: '#f5f7fa' } } } } }</script>
<style>
  :root { --pk-blue: #0857a6; --pk-blue-dark: #063f7a; --pk-bg: #ffffff; --pk-ink: #1b1b1b; --pk-muted: #5a5a5a; --pk-border: #d8dde3; --pk-panel: #f5f7fa; --pk-fs: 16px; }
  html[data-contrast="high"] { --pk-blue: #ffe600; --pk-blue-dark: #ffe600; --pk-bg: #000; --pk-ink: #fff; --pk-muted: #e5e5e5; --pk-border: #ffe600; --pk-panel: #111; }
  html[data-fontsize="lg"] { --pk-fs: 19px; } html[data-fontsize="xl"] { --pk-fs: 22px; }
  html { font-size: var(--pk-fs); }
  body { margin: 0; font-family: -apple-system, "Segoe UI", Roboto, Arial, sans-serif; background: var(--pk-bg); color: var(--pk-ink); }
  a { color: var(--pk-blue-dark); }
  html[data-contrast="high"] a { color: var(--pk-blue); text-decoration: underline; }
  :focus-visible { outline: 3px solid #facc15; outline-offset: 2px; }
  .skip { position: absolute; left: -9999px; } .skip:focus { left: 8px; top: 8px; background: #facc15; color: #000; padding: 8px 12px; z-index: 10; }
  .pk-topbar { display: flex; align-items: center; justify-content: space-between; padding: 14px 24px; border-bottom: 1px solid var(--pk-border); flex-wrap: wrap; gap: 10px; }
  .pk-brand { font-weight: 800; font-size: 1.15rem; color: var(--pk-blue-dark); text-decoration: none; }
  html[data-contrast="high"] .pk-brand { color: var(--pk-blue); }
  .pk-a11y { display: flex; align-items: center; gap: 14px; font-size: .82rem; flex-wrap: wrap; }
  .pk-a11y-group { display: flex; align-items: center; gap: 6px; }
  .pk-a11y button, .pk-user button { border: 1px solid var(--pk-border); background: var(--pk-bg); color: var(--pk-ink); border-radius: 4px; padding: 3px 8px; font-weight: 700; cursor: pointer; font-size: .85rem; min-height: 32px; }
  .pk-a11y button.active { background: var(--pk-blue-dark); color: #fff; border-color: var(--pk-blue-dark); }
  html[data-contrast="high"] .pk-a11y button.active { background: var(--pk-blue); color: #000; }
  .pk-user { display: flex; align-items: center; gap: 10px; font-size: .9rem; }
  .pk-crumb { padding: 10px 24px; font-size: .85rem; color: var(--pk-muted); border-bottom: 1px solid var(--pk-border); }
  .pk-wrap { max-width: 1200px; margin: 0 auto; padding: 28px 24px 60px; }
  .pk-grid { display: grid; grid-template-columns: 1fr; gap: 28px; }
  @media (min-width: 900px) { .pk-grid { grid-template-columns: 240px 1fr; } }
  .pk-side { align-self: start; position: sticky; top: 12px; border: 1px solid var(--pk-border); border-radius: 8px; padding: 10px; background: var(--pk-panel); }
  .pk-side a { display: flex; justify-content: space-between; gap: 8px; align-items: center; padding: 9px 10px; border-radius: 6px; text-decoration: none; color: var(--pk-ink); font-weight: 600; min-height: 40px; }
  .pk-side a:hover, .pk-side a:focus { background: var(--pk-bg); }
  .pk-side .pk-badge { display: inline-block; padding: 1px 8px; border-radius: 20px; font-size: .75rem; font-weight: 700; background: var(--pk-bg); border: 1px solid var(--pk-border); color: var(--pk-ink); white-space: nowrap; }
  .pk-side .pk-badge.due { background: #fff4d6; border-color: #f0a30a; }
  .pk-side hr { border: 0; border-top: 1px solid var(--pk-border); margin: 8px 0; }
  .pk-footer { text-align: center; padding: 24px; color: var(--pk-muted); font-size: .8rem; }
  .pk-alert { padding: 12px 14px; border-radius: 6px; margin-bottom: 16px; font-size: .95rem; }
  .pk-alert-err { background: #fdecec; color: #8a1c1c; border: 1px solid #f3b9b9; } .pk-alert-ok { background: #eaf7ee; color: #146c32; border: 1px solid #b6e3c2; } .pk-alert-info { background: #eaf2fb; color: #0857a6; border: 1px solid #b9d3ef; }
  html[data-contrast="high"] .pk-alert { background: #111; border: 1px solid var(--pk-blue); color: var(--pk-ink); }
  /* komponenty treści (m-*) — w palecie portalu */
  .m-tile { background: var(--pk-bg); border: 1px solid var(--pk-border); border-radius: 8px; padding: 22px 24px; margin-bottom: 20px; scroll-margin-top: 16px; }
  .m-head { font-size: 1.25rem; font-weight: 700; margin: 0 0 12px; }
  .m-label { display: block; font-size: .85rem; font-weight: 600; margin-bottom: 4px; color: var(--pk-muted); }
  .m-input { width: 100%; padding: 9px 11px; border: 1px solid var(--pk-border); border-radius: 5px; font-size: 1rem; background: var(--pk-bg); color: var(--pk-ink); }
  .m-input[aria-invalid="true"] { border: 2px solid #b42318; }
  .m-err { font-size: .85rem; color: #b42318; margin-top: 4px; font-weight: 600; }
  .m-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; background: var(--pk-blue-dark); color: #fff; border: none; border-radius: 5px; padding: 10px 20px; font-weight: 700; font-size: 1rem; cursor: pointer; text-decoration: none; min-height: 44px; }
  html[data-contrast="high"] .m-btn { background: var(--pk-blue); color: #000; }
  .m-btn-ghost { display: inline-flex; align-items: center; gap: 6px; background: var(--pk-bg); color: var(--pk-blue-dark); border: 1px solid var(--pk-blue-dark); border-radius: 5px; padding: 8px 16px; font-weight: 700; cursor: pointer; text-decoration: none; min-height: 40px; }
  .m-check { display: flex; gap: 12px; align-items: flex-start; padding: 12px; border: 1px solid var(--pk-border); border-radius: 6px; background: var(--pk-panel); cursor: pointer; }
  .m-check input { margin-top: 4px; width: 20px; height: 20px; flex-shrink: 0; }
  .m-chip { display: inline-block; padding: 2px 9px; border-radius: 20px; font-size: .78rem; font-weight: 700; vertical-align: middle; color: #fff; }
  .m-kv dt { font-size: .75rem; text-transform: uppercase; letter-spacing: .03em; color: var(--pk-muted); }
  .m-kv dd { margin: 0 0 12px; font-weight: 600; }
  .m-row { border-top: 1px solid var(--pk-border); padding: 12px 0; }
  summary { cursor: pointer; font-weight: 600; min-height: 32px; }
  html[data-contrast="high"] .tw-bg-m-bg { background: #111 !important; color: #fff !important; }
</style>
</head>
<body>
<a class="skip" href="#tresc">Przejdź do treści</a>
<div class="pk-topbar">
  <a class="pk-brand" href="<?= h(isset($me) ? route('lk.index') : route('lk.login')) ?>">Panel licencji — <?= h($org) ?></a>
  <div class="pk-a11y" aria-label="Ułatwienia dostępu">
    <div class="pk-a11y-group" role="group" aria-label="Kontrast"><span>Kontrast:</span><button type="button" data-lp-contrast="normal">Normalny</button><button type="button" data-lp-contrast="high">Wysoki</button></div>
    <div class="pk-a11y-group" role="group" aria-label="Wielkość liter"><span>Wielkość liter:</span><button type="button" data-lp-fontsize="md" aria-label="Litery normalne">A</button><button type="button" data-lp-fontsize="lg" aria-label="Litery większe" style="font-size:1rem">A</button><button type="button" data-lp-fontsize="xl" aria-label="Litery największe" style="font-size:1.15rem">A</button></div>
  </div>
  <?php if (isset($me)): ?>
  <div class="pk-user"><span><?= h($me['name']) ?></span>
    <form method="post" action="<?= h(route('lk.logout')) ?>"><?= Router::csrfField() ?><button type="submit">Wyloguj</button></form></div>
  <?php endif; ?>
</div>
<div class="pk-crumb"><?= isset($me) ? 'Panel licencji' . ($me['fromTi'] ? ' · <a href="../index.php">← Panel uczestnika TI</a>' : '') : 'Logowanie do panelu licencji' ?></div>
<div class="pk-wrap<?= $nav !== null ? ' pk-grid' : '' ?>">
<?php if ($nav !== null): ?>
  <nav class="pk-side" aria-label="Menu panelu licencji">
    <?php foreach ($nav as [$anchor, $label, $badge, $due]): ?>
      <a href="#<?= h($anchor) ?>"><span><?= h($label) ?></span><?php if ($badge !== ''): ?><span class="pk-badge<?= $due ? ' due' : '' ?>"><?= h($badge) ?></span><?php endif; ?></a>
    <?php endforeach; ?>
  </nav>
<?php endif; ?>
<main id="tresc">
<?php if (!empty($flash)): ?>
<div class="pk-alert pk-alert-<?= $flash[0] === 'ok' ? 'ok' : ($flash[0] === 'info' ? 'info' : 'err') ?>" role="<?= $flash[0] === 'err' ? 'alert' : 'status' ?>" tabindex="-1" id="komunikat"><?= h($flash[1]) ?></div>
<script>document.getElementById('komunikat').focus();</script>
<?php endif; ?>
