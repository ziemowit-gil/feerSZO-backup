<?php
/**
 * modules/ti/view/nabor/public/_top.php — wspólny nagłówek stron konta kandydata (Tailwind, prefiks tw-).
 * Wejście: $title, $org, $acc (zalogowany kandydat albo null), $form (naborForm()).
 */
?><!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<meta name="theme-color" content="#1d68f2">
<title><?= h($title) ?> — <?= h($org) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Ubuntu:wght@400;500;700&family=Montserrat:wght@700;800&display=swap">
<script src="https://cdn.tailwindcss.com"></script>
<script>tailwind.config = { prefix: 'tw-', theme: { extend: { colors: { feer: { DEFAULT: '#1d68f2', dark: '#164eb6', bg: '#edf3fe', line: '#d8e2f3' } } } } }</script>
<style type="text/tailwindcss">
  body { font-family: 'Ubuntu', ui-sans-serif, system-ui, sans-serif; }
  h1, h2, h3 { font-family: 'Montserrat', 'Ubuntu', sans-serif; }
  .k-card  { @apply tw-bg-white tw-rounded-2xl tw-shadow tw-p-5 sm:tw-p-6 tw-mb-5; }
  .k-label { @apply tw-block tw-text-sm tw-font-medium tw-mb-1; }
  .k-input { @apply tw-w-full tw-rounded-lg tw-border tw-border-feer-line tw-px-3 tw-py-2 tw-bg-white focus:tw-outline-none focus:tw-ring-4 focus:tw-ring-blue-200 focus:tw-border-feer; }
  .k-btn, .k-btn-ghost { @apply tw-inline-flex tw-items-center tw-justify-center tw-gap-2 tw-rounded-xl tw-px-4 tw-py-2 tw-font-bold focus:tw-outline-none focus:tw-ring-4 focus:tw-ring-blue-300 disabled:tw-opacity-50; }
  .k-btn { @apply tw-bg-feer tw-text-white hover:tw-bg-feer-dark; }
  .k-btn-ghost { @apply tw-bg-white tw-text-gray-800 tw-border tw-border-gray-300 hover:tw-bg-gray-50; }
  .k-help { @apply tw-text-xs tw-text-gray-500 tw-mt-1; }
  .k-badge { @apply tw-inline-block tw-rounded-full tw-px-2.5 tw-py-0.5 tw-text-xs tw-font-bold; }
  a { @apply tw-text-feer; }
</style>
</head>
<body class="tw-bg-feer-bg tw-text-gray-900">
<div class="tw-max-w-3xl tw-mx-auto tw-px-4 tw-py-6">
  <header class="tw-flex tw-flex-wrap tw-items-center tw-gap-3 tw-mb-6">
    <a href="<?= h(naborUrl('/zapisy')) ?>" class="tw-text-xl tw-font-extrabold tw-text-gray-900 tw-no-underline tw-mr-auto"><?= h($form['texts']['title']) ?> <span class="tw-text-sm tw-font-normal tw-text-gray-500">· <?= h($org) ?></span></a>
    <?php if (!empty($acc)): ?>
      <nav aria-label="Konto kandydata" class="tw-flex tw-items-center tw-gap-3 tw-text-sm">
        <a href="<?= h(naborUrl('/konto')) ?>" class="tw-font-bold">Moje zgłoszenia</a>
        <a href="<?= h(naborUrl('/zapisy')) ?>">Nowe zgłoszenie</a>
        <span class="tw-text-gray-500 tw-hidden sm:tw-inline"><?= h($acc['email']) ?></span>
        <form method="post" action="<?= h(naborUrl('/wyloguj')) ?>"><?= Router::csrfField() ?><button class="k-btn-ghost tw-py-1 tw-text-sm">Wyloguj</button></form>
      </nav>
    <?php else: ?>
      <a href="<?= h(naborUrl('/logowanie')) ?>" class="tw-text-sm tw-font-bold">Zaloguj się na konto kandydata</a>
    <?php endif; ?>
  </header>
  <?php $fl = flash_get(); if ($fl): $ok = in_array($fl['type'] ?? '', ['success', 'info'], true); ?>
    <div class="tw-rounded-xl tw-p-3 tw-mb-5 tw-border <?= $ok ? 'tw-bg-green-50 tw-border-green-300 tw-text-green-900' : 'tw-bg-red-50 tw-border-red-300 tw-text-red-900' ?>" role="<?= $ok ? 'status' : 'alert' ?>"><?= h($fl['msg'] ?? $fl['message'] ?? '') ?></div>
  <?php endif; ?>
  <main id="main">
