<?php
if (!isset($org)) { header('Location: /karty30/ti/kursant/licencje/', true, 302); exit; }   // widok tylko przez router modułu (bezpośredni adres pliku → panel)
/**
 * modules/ti_licencje_kursant/view/_top.php — szkielet panelu licencji: układ jak Portal Kontrahenta (pasek, okruszki, menu po lewej, treść),
 * wygląd Metro (jak System Tożsamości): ciemny pasek z akcentem, zero zaokrągleń, stonowane znaczniki stanu (m-tag ok|due|bad|off) zamiast kolorowych pigułek.
 * Pasek dostępności: kontrast + wielkość liter (localStorage). Treść: Tailwind CDN (prefiks tw-) do siatki i odstępów + komponenty m-*. Bez menu (logowanie): $nav = null.
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
  /* Metro (jak System Tożsamości i widok METRO panelu uczestnika): ciemny pasek z akcentem #1ba1e2, zero zaokrągleń, Segoe UI, stonowane kolory.
     Kontrast: #0f6fa8 na białym 5,4:1 (link, przycisk), #555 na białym 7,5:1 (tekst pomocniczy), znaczniki stanu ≥ 4,5:1. */
  :root { --mt-ink: #1d1d1d; --mt-muted: #555; --mt-line: #d1d5db; --mt-bg: #f3f4f6; --mt-card: #fff; --mt-accent: #1ba1e2; --mt-link: #0f6fa8;
          --mt-ok: #107c10; --mt-due: #8a5300; --mt-bad: #a4262c; --mt-off: #555; --mt-focus: #d13438; --pk-fs: 16px;
          --pk-border: var(--mt-line); --pk-blue-dark: var(--mt-link); --pk-panel: var(--mt-bg); --pk-bg: var(--mt-card); --pk-ink: var(--mt-ink); --pk-muted: var(--mt-muted); }
  html[data-contrast="high"] { --mt-ink: #fff; --mt-muted: #e5e5e5; --mt-line: #ffe600; --mt-bg: #000; --mt-card: #000; --mt-accent: #ffe600; --mt-link: #ffe600;
          --mt-ok: #7CFC00; --mt-due: #ffe600; --mt-bad: #ff8a8a; --mt-off: #e5e5e5; --mt-focus: #ff8a8a; }
  html[data-fontsize="lg"] { --pk-fs: 19px; } html[data-fontsize="xl"] { --pk-fs: 22px; }
  html { font-size: var(--pk-fs); }
  body { margin: 0; font-family: "Segoe UI", system-ui, -apple-system, Roboto, Arial, sans-serif; background: var(--mt-bg); color: var(--mt-ink); }
  a { color: var(--mt-link); text-underline-offset: 2px; }
  html[data-contrast="high"] a { text-decoration: underline; }
  :focus-visible { outline: 3px solid var(--mt-focus); outline-offset: 2px; }
  .skip { position: absolute; left: -9999px; } .skip:focus { left: 8px; top: 8px; background: #fff; color: #000; padding: 8px 12px; z-index: 10; }
  /* pasek górny */
  .pk-topbar { display: flex; align-items: center; justify-content: space-between; padding: 10px 24px; background: #1d1d1d; color: #fff; border-bottom: 3px solid var(--mt-accent); flex-wrap: wrap; gap: 10px; }
  .pk-brand { font-weight: 300; font-size: 1.2rem; color: #fff; text-decoration: none; letter-spacing: .01em; }
  .pk-brand b { font-weight: 600; }
  .pk-a11y { display: flex; align-items: center; gap: 14px; font-size: .8rem; flex-wrap: wrap; color: #e5e5e5; }
  .pk-a11y-group { display: flex; align-items: center; gap: 4px; }
  .pk-a11y button, .pk-user button { border: 1px solid #6b6b6b; background: transparent; color: #fff; border-radius: 0; padding: 3px 9px; font-weight: 600; cursor: pointer; font-size: .82rem; min-height: 32px; }
  .pk-a11y button:hover, .pk-user button:hover { background: #333; }
  .pk-a11y button.active { background: #fff; color: #1d1d1d; border-color: #fff; }
  .pk-user { display: flex; align-items: center; gap: 12px; font-size: .9rem; }
  .pk-crumb { padding: 8px 24px; font-size: .82rem; color: var(--mt-muted); background: var(--mt-card); border-bottom: 1px solid var(--mt-line); }
  .pk-wrap { max-width: 1200px; margin: 0 auto; padding: 28px 24px 60px; }
  .pk-grid { display: grid; grid-template-columns: 1fr; gap: 28px; }
  @media (min-width: 900px) { .pk-grid { grid-template-columns: 230px 1fr; } }
  /* menu boczne */
  .pk-side { align-self: start; position: sticky; top: 12px; background: var(--mt-card); border: 1px solid var(--mt-line); padding: 6px 0; }
  .pk-side a { display: flex; justify-content: space-between; gap: 8px; align-items: center; padding: 10px 14px; text-decoration: none; color: var(--mt-ink); font-weight: 400; min-height: 42px; border-left: 3px solid transparent; }
  .pk-side a:hover, .pk-side a:focus-visible, .pk-side a[aria-current="true"] { background: var(--mt-bg); border-left-color: var(--mt-accent); }
  .pk-side .pk-badge { font-size: .78rem; color: var(--mt-muted); white-space: nowrap; font-variant-numeric: tabular-nums; }
  .pk-side .pk-badge.due { color: var(--mt-bad); font-weight: 700; }
  .pk-footer { text-align: center; padding: 24px; color: var(--mt-muted); font-size: .8rem; }
  /* komunikaty: płaskie, z paskiem po lewej */
  .pk-alert { padding: 12px 16px; margin-bottom: 16px; font-size: .95rem; background: var(--mt-card); border: 1px solid var(--mt-line); border-left: 4px solid var(--mt-link); }
  .pk-alert-err { border-left-color: var(--mt-bad); } .pk-alert-ok { border-left-color: var(--mt-ok); } .pk-alert-info { border-left-color: var(--mt-link); }
  /* treść */
  .m-title { font-size: 2rem; font-weight: 300; margin: 0 0 4px; letter-spacing: -.01em; }
  .m-lead { color: var(--mt-muted); margin: 0 0 24px; }
  .m-sect { font-size: .8rem; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; color: var(--mt-muted); margin: 0 0 10px; }
  .m-sect small { text-transform: none; letter-spacing: 0; font-weight: 400; }
  .m-tile { background: var(--mt-card); border: 1px solid var(--mt-line); padding: 22px 24px; margin-bottom: 20px; scroll-margin-top: 16px; }
  .m-head { font-size: 1.45rem; font-weight: 300; margin: 0 0 12px; display: flex; flex-wrap: wrap; align-items: center; gap: 10px; }
  .m-label { display: block; font-size: .85rem; font-weight: 600; margin-bottom: 4px; color: var(--mt-ink); }
  .m-input { width: 100%; padding: 9px 11px; border: 1px solid #8a8a8a; border-radius: 0; font-size: 1rem; background: var(--mt-card); color: var(--mt-ink); }
  .m-input:focus-visible { outline: 3px solid var(--mt-focus); outline-offset: 0; }
  .m-input[aria-invalid="true"] { border: 2px solid var(--mt-bad); }
  .m-err { font-size: .85rem; color: var(--mt-bad); margin-top: 4px; font-weight: 600; }
  .m-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; background: var(--mt-link); color: #fff; border: 1px solid var(--mt-link); border-radius: 0; padding: 9px 20px; font-weight: 600; font-size: 1rem; cursor: pointer; text-decoration: none; min-height: 44px; }
  .m-btn:hover { background: #0b5a89; }
  html[data-contrast="high"] .m-btn { color: #000; }
  .m-btn-ghost { display: inline-flex; align-items: center; gap: 6px; background: transparent; color: var(--mt-ink); border: 1px solid var(--mt-ink); border-radius: 0; padding: 7px 14px; font-weight: 600; cursor: pointer; text-decoration: none; min-height: 40px; font-size: .92rem; }
  .m-btn-ghost:hover { background: var(--mt-bg); }
  .m-btn-danger { border-color: var(--mt-bad); color: var(--mt-bad); }
  .m-check { display: flex; gap: 12px; align-items: flex-start; padding: 12px; border: 1px solid var(--mt-line); background: var(--mt-bg); cursor: pointer; }
  .m-check input { margin-top: 4px; width: 20px; height: 20px; flex-shrink: 0; }
  /* znacznik stanu: tekst + ramka, bez wypełnienia */
  .m-tag { display: inline-block; padding: 1px 8px; font-size: .74rem; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; border: 1px solid currentColor; color: var(--mt-off); vertical-align: middle; line-height: 1.6; }
  .m-tag.ok { color: var(--mt-ok); } .m-tag.due { color: var(--mt-due); } .m-tag.bad { color: var(--mt-bad); } .m-tag.off { color: var(--mt-off); }
  .m-kv dt { font-size: .74rem; text-transform: uppercase; letter-spacing: .06em; color: var(--mt-muted); }
  .m-kv dd { margin: 0 0 12px; font-weight: 600; }
  .m-row { border-top: 1px solid var(--mt-line); padding: 14px 0; }
  .m-num { font-size: 1.9rem; font-weight: 300; line-height: 1.1; font-variant-numeric: tabular-nums; }
  .m-box { border: 1px solid var(--mt-line); padding: 14px 16px; background: var(--mt-card); }
  .m-box.now { border-top: 3px solid var(--mt-ink); }
  .m-note { font-size: .78rem; color: var(--mt-muted); }
  .m-muted { color: var(--mt-muted); }
  .m-mono { font-family: Consolas, "SFMono-Regular", Menlo, monospace; }
  summary { cursor: pointer; font-weight: 600; min-height: 32px; }
  .m-code { font-size: 1.6rem; letter-spacing: .4em; text-align: center; max-width: 12ch; font-family: Consolas, Menlo, monospace; }
</style>
</head>
<body>
<a class="skip" href="#tresc">Przejdź do treści</a>
<div class="pk-topbar">
  <a class="pk-brand" href="<?= h(isset($me) ? route('lk.index') : route('lk.login')) ?>"><b>Panel licencji</b> · <?= h($org) ?></a>
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
