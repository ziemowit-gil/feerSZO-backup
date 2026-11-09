<?php
if (!isset($me, $tab)) { header('Location: /karty30/ti/kursant/licencje/', true, 302); exit; }   // widok tylko przez router modułu (bezpośredni adres pliku → panel)
/**
 * modules/ti_licencje_kursant/view/_top.php — nagłówek panelu „Moje licencje” (Metro, Tailwind tw-), zakładki Licencje / Produkty i usługi, komunikat.
 * Zmienne: $org, $me, $flash, $tab ('licencje' | 'produkty').
 */
?><!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#1d1d1d">
<title><?= $tab === 'produkty' ? 'Produkty i usługi' : 'Moje licencje' ?> — <?= h($org) ?></title>
<script src="https://cdn.tailwindcss.com/3.4.5"></script>
<script>tailwind.config = { prefix: 'tw-', corePlugins: { preflight: true }, theme: { borderRadius: { none: '0', DEFAULT: '0' }, extend: {
  colors: { m: { cyan: '#1ba1e2', cobalt: '#0050ef', green: '#008a00', red: '#a20025', amber: '#f0a30a', orange: '#fa6800', steel: '#647687', dark: '#1d1d1d', line: '#d6d6d6', bg: '#f4f4f4', nord: '#4687ff', claude: '#da532c' } },
  fontFamily: { metro: ['"Segoe UI"', '"Segoe UI Web"', '"Open Sans"', 'Ubuntu', 'system-ui', 'sans-serif'] } } } }</script>
<style type="text/tailwindcss">
  body { @apply tw-font-metro tw-bg-m-bg tw-text-m-dark; }
  .m-tile   { @apply tw-bg-white tw-border tw-border-solid tw-border-m-line tw-p-5 sm:tw-p-6 tw-mb-4; }
  .m-head   { @apply tw-text-xl tw-font-light tw-mb-3 tw-text-m-dark; }
  .m-label  { @apply tw-block tw-text-sm tw-font-semibold tw-mb-1; }
  .m-input  { @apply tw-w-full tw-border-2 tw-border-solid tw-border-m-line tw-bg-white tw-px-3 tw-py-2 focus:tw-outline-none focus:tw-border-m-cyan; }
  .m-input[aria-invalid="true"] { @apply tw-border-m-red; }
  .m-err    { @apply tw-text-sm tw-text-m-red tw-mt-1 tw-font-semibold; }
  .m-btn    { @apply tw-inline-flex tw-items-center tw-justify-center tw-gap-2 tw-bg-m-cyan tw-text-white tw-font-semibold tw-px-5 tw-py-3 tw-border-0 tw-no-underline hover:tw-bg-m-cobalt focus:tw-outline-none focus:tw-ring-4 focus:tw-ring-m-amber; min-height: 44px; }
  .m-btn-ghost { @apply tw-inline-flex tw-items-center tw-gap-2 tw-bg-white tw-text-m-dark tw-font-semibold tw-px-4 tw-py-2 tw-border-2 tw-border-solid tw-border-m-dark tw-no-underline hover:tw-bg-m-bg focus:tw-outline-none focus:tw-ring-4 focus:tw-ring-m-amber; min-height: 44px; }
  .m-check  { @apply tw-flex tw-gap-3 tw-items-start tw-p-3 tw-border-2 tw-border-solid tw-border-m-line tw-bg-white tw-cursor-pointer; }
  .m-check input { @apply tw-mt-1 tw-h-5 tw-w-5 tw-shrink-0 tw-accent-m-cyan; }
  .m-chip   { @apply tw-inline-block tw-text-white tw-px-2 tw-py-0.5 tw-text-xs tw-font-semibold tw-align-middle; }
  .m-kv dt  { @apply tw-text-xs tw-uppercase tw-tracking-wide tw-text-m-steel; }
  .m-kv dd  { @apply tw-mb-3 tw-font-semibold; }
  .m-row    { @apply tw-border-t tw-border-solid tw-border-m-line tw-py-3; }
  summary   { @apply tw-cursor-pointer tw-font-semibold; min-height: 32px; }
  a { @apply tw-text-m-cobalt tw-underline; }
  .skip { @apply tw-sr-only focus:tw-not-sr-only focus:tw-absolute focus:tw-p-3 focus:tw-bg-m-amber focus:tw-text-m-dark; }
  :focus-visible { outline: 3px solid #facc15; outline-offset: 2px; }
</style>
</head>
<body>
<a class="skip" href="#tresc">Przejdź do treści</a>
<header class="tw-bg-m-dark tw-text-white">
  <div class="tw-max-w-4xl tw-mx-auto tw-flex tw-items-stretch">
    <div class="tw-bg-m-cyan tw-px-4 tw-py-3 tw-text-2xl tw-font-semibold" aria-hidden="true">🔑</div>
    <div class="tw-px-4 tw-py-2 tw-flex-1">
      <div class="tw-text-xs tw-uppercase tw-tracking-widest tw-opacity-80"><?= h($org) ?></div>
      <div class="tw-text-lg tw-font-light">Moje licencje</div>
    </div>
    <a href="../index.php" class="tw-self-center tw-px-4 tw-text-white tw-text-sm">← Panel uczestnika</a>
  </div>
</header>
<main id="tresc" class="tw-max-w-4xl tw-mx-auto tw-px-4 tw-py-6">
<h1 class="tw-text-3xl tw-font-light tw-mb-1"><?= $tab === 'produkty' ? 'Produkty i usługi' : 'Moje licencje' ?></h1>
<p class="tw-text-m-steel tw-mb-4"><?= h($me['name']) ?> — <?= $tab === 'produkty' ? 'usługi, które Fundacja kupuje dla Ciebie i refakturuje na Ciebie.' : 'wszystkie programy i dostępy w jednym miejscu.' ?></p>
<nav class="tw-flex tw-gap-6 tw-border-0 tw-border-b tw-border-solid tw-border-m-line tw-mb-5" aria-label="Zakładki">
  <?php foreach (['licencje' => ['Licencje', route('lk.index')], 'produkty' => ['Produkty i usługi', route('lk.products')]] as $tk => [$tl, $tu]): ?>
  <a href="<?= h($tu) ?>" class="tw-text-lg tw-py-2 tw-no-underline <?= $tab === $tk ? 'tw-text-m-dark tw-font-semibold tw-border-0 tw-border-b-4 tw-border-solid tw-border-m-cyan' : 'tw-text-m-steel tw-font-light' ?>"<?= $tab === $tk ? ' aria-current="page"' : '' ?>><?= h($tl) ?></a>
  <?php endforeach; ?>
</nav>

<?php if ($flash): ?>
<div class="<?= $flash[0] === 'ok' ? 'tw-bg-m-green' : ($flash[0] === 'info' ? 'tw-bg-m-cobalt' : 'tw-bg-m-red') ?> tw-text-white tw-p-4 tw-mb-4" role="<?= $flash[0] === 'err' ? 'alert' : 'status' ?>" tabindex="-1" id="komunikat"><?= h($flash[1]) ?></div>
<script>document.getElementById('komunikat').focus();</script>
<?php endif; ?>

