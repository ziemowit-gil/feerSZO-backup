<?php
/**
 * modules/ti_licencje_dydaktyczne/view/public/_top.php — nagłówek portalu licencji AM5 w szacie Metro UI
 * (Tailwind CDN z prefiksem tw-, bez zaokrągleń, płaskie kafle, akcent cyan #1ba1e2, pasek aplikacji #1d1d1d).
 * Wejście: $title, $org. Treść generowana po stronie serwera — bez klas dodawanych dynamicznie przez JS.
 */
$sym = $sym ?? (isset($l['form']) && $l['form'] instanceof LicenseForm ? $l['form']->value : LD_SYMBOL);
?><!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#1d1d1d">
<title><?= h($title) ?> — <?= h($org) ?></title>
<script src="https://cdn.tailwindcss.com/3.4.5"></script>
<script>tailwind.config = { prefix: 'tw-', corePlugins: { preflight: true }, theme: { borderRadius: { none: '0', DEFAULT: '0' }, extend: {
  colors: { m: { cyan: '#1ba1e2', cobalt: '#0050ef', green: '#008a00', red: '#a20025', amber: '#f0a30a', orange: '#fa6800', steel: '#647687', dark: '#1d1d1d', line: '#d6d6d6', bg: '#f4f4f4' } },
  fontFamily: { metro: ['"Segoe UI"', '"Segoe UI Web"', '"Open Sans"', 'Ubuntu', 'system-ui', 'sans-serif'] } } } }</script>
<style type="text/tailwindcss">
  body { @apply tw-font-metro tw-bg-m-bg tw-text-m-dark; }
  .m-tile   { @apply tw-bg-white tw-border tw-border-solid tw-border-m-line tw-p-5 sm:tw-p-6 tw-mb-4; }
  .m-head   { @apply tw-text-xl tw-font-light tw-mb-3 tw-text-m-dark; }
  .m-label  { @apply tw-block tw-text-sm tw-font-semibold tw-mb-1; }
  .m-input  { @apply tw-w-full tw-border-2 tw-border-solid tw-border-m-line tw-bg-white tw-px-3 tw-py-2 focus:tw-outline-none focus:tw-border-m-cyan; }
  .m-input[aria-invalid="true"] { @apply tw-border-m-red; }
  .m-err    { @apply tw-text-sm tw-text-m-red tw-mt-1 tw-font-semibold; }
  .m-help   { @apply tw-text-xs tw-text-m-steel tw-mt-1; }
  .m-btn    { @apply tw-inline-flex tw-items-center tw-justify-center tw-gap-2 tw-bg-m-cyan tw-text-white tw-font-semibold tw-px-6 tw-py-3 tw-border-0 hover:tw-bg-m-cobalt focus:tw-outline-none focus:tw-ring-4 focus:tw-ring-m-amber; }
  .m-btn-ghost { @apply tw-inline-flex tw-items-center tw-gap-2 tw-bg-white tw-text-m-dark tw-font-semibold tw-px-5 tw-py-2 tw-border-2 tw-border-solid tw-border-m-dark hover:tw-bg-m-bg focus:tw-outline-none focus:tw-ring-4 focus:tw-ring-m-amber; }
  .m-check  { @apply tw-flex tw-gap-3 tw-items-start tw-p-3 tw-border-2 tw-border-solid tw-border-m-line tw-bg-white tw-cursor-pointer; }
  .m-check input { @apply tw-mt-1 tw-h-5 tw-w-5 tw-shrink-0 tw-accent-m-cyan; }
  .m-kv dt  { @apply tw-text-xs tw-uppercase tw-tracking-wide tw-text-m-steel; }
  .m-kv dd  { @apply tw-mb-3 tw-font-semibold; }
  a { @apply tw-text-m-cobalt tw-underline; }
  .skip { @apply tw-sr-only focus:tw-not-sr-only focus:tw-absolute focus:tw-p-3 focus:tw-bg-m-amber focus:tw-text-m-dark; }
</style>
</head>
<body>
<a class="skip" href="#tresc">Przejdź do treści</a>
<header class="tw-bg-m-dark tw-text-white">
  <div class="tw-max-w-4xl tw-mx-auto tw-flex tw-items-stretch">
    <div class="<?= $sym === 'AM7' ? 'tw-bg-m-cobalt' : ($sym === 'AM20' ? 'tw-bg-m-green' : 'tw-bg-m-cyan') ?> tw-px-4 tw-py-3 tw-text-2xl tw-font-semibold tw-tracking-wide" aria-hidden="true"><?= h($sym) ?></div>
    <div class="tw-px-4 tw-py-2">
      <div class="tw-text-xs tw-uppercase tw-tracking-widest tw-opacity-80"><?= h($org) ?></div>
      <div class="tw-text-lg tw-font-light"><?= $sym === 'AM7' ? 'Licencje do oprogramowania' : ($sym === 'AM20' ? 'Licencje — zmiana płatnika' : (str_starts_with((string)$sym, 'DOP') ? 'Wnioski rozliczeniowe uczestnika' : 'Licencje dydaktyczne')) ?></div>
    </div>
  </div>
</header>
<main id="tresc" class="tw-max-w-4xl tw-mx-auto tw-px-4 tw-py-6">
<h1 class="tw-text-3xl tw-font-light tw-mb-5"><?= h($title) ?></h1>
