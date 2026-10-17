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
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Ubuntu:wght@300;400;500;700&display=swap">
<script src="https://cdn.tailwindcss.com"></script>
<script>tailwind.config = { prefix: 'tw-', theme: { extend: { colors: { feer: { DEFAULT: '#1d68f2', dark: '#164eb6', bg: '#edf3fe', line: '#d8e2f3' } } } } }</script>
<style type="text/tailwindcss">
  body { font-family: 'Ubuntu', ui-sans-serif, system-ui, sans-serif; }
  h1, h2, h3 { font-family: 'Ubuntu', sans-serif; font-weight: 500; }
  .k-card  { @apply tw-bg-white tw-border tw-border-solid tw-border-gray-300 tw-p-5 sm:tw-p-6 tw-mb-5; }
  .k-label { @apply tw-block tw-text-sm tw-font-medium tw-mb-1; }
  .k-input { @apply tw-w-full tw-rounded-none tw-border tw-border-solid tw-border-feer-line tw-px-3 tw-py-2 tw-bg-white focus:tw-outline-none focus:tw-ring-4 focus:tw-ring-blue-200 focus:tw-border-feer; }
  .k-btn, .k-btn-ghost { @apply tw-inline-flex tw-items-center tw-justify-center tw-gap-2 tw-rounded-xl tw-px-4 tw-py-2 tw-font-bold focus:tw-outline-none focus:tw-ring-4 focus:tw-ring-blue-300 disabled:tw-opacity-50; }
  .k-btn { @apply tw-bg-[#fcc634] tw-text-[#0b5ea8] hover:tw-brightness-95 tw-rounded-none; }
  .k-btn-ghost { @apply tw-bg-white tw-text-[#0b5ea8] tw-border-2 tw-border-solid tw-border-[#0b5ea8] hover:tw-bg-gray-50 tw-rounded-none; }
  .k-help { @apply tw-text-xs tw-text-gray-500 tw-mt-1; }
  .k-badge { @apply tw-inline-block tw-rounded-full tw-px-2.5 tw-py-0.5 tw-text-xs tw-font-bold; }
  a { @apply tw-text-feer; }
</style>
<?php include dirname(__DIR__, 3) . '/view/portal/_style.php'; ?>
</head>
<body class="pp tw-text-gray-900">
<?php
$ppTitle = 'Panel Kursanta SSO'; $ppOrg = $org; $ppCrumb = $title; $ppLogo = ''; $ppLoginUrl = '/login.php';
$ppAccount = $acc ?? null; $ppCsrf = Router::csrfToken();
include dirname(__DIR__, 3) . '/view/portal/_header.php';
?>
<div class="pp-wrap" style="max-width:1240px">
  <?php $fl = flash_get(); if ($fl): $ok = in_array($fl['type'] ?? '', ['success', 'info'], true); ?>
    <div class="tw-rounded-xl tw-p-3 tw-mb-5 tw-border <?= $ok ? 'tw-bg-green-50 tw-border-green-300 tw-text-green-900' : 'tw-bg-red-50 tw-border-red-300 tw-text-red-900' ?>" role="<?= $ok ? 'status' : 'alert' ?>"><?= h($fl['msg'] ?? $fl['message'] ?? '') ?></div>
  <?php endif; ?>
  <main id="pp-main">
