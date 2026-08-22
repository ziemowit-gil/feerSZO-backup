<?php
/** login.php — formularz logowania do panelu. Zmienne: $error, $email, $lock */
?>
<!DOCTYPE html>
<html lang="pl" class="dark">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Logowanie — <?= e(setting('site_name', APP_NAME)) ?></title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?display=swap&amp;family=Inter:wght@400;500;600;700">
<style>body{font-family:Inter,system-ui,sans-serif}</style>
</head>
<body class="grid min-h-screen place-items-center bg-zinc-950 px-5 text-zinc-200 antialiased">
<div class="w-full max-w-sm">

  <div class="mb-8 text-center">
    <span class="mx-auto mb-3 grid h-12 w-12 place-items-center rounded-xl bg-indigo-500 text-xl text-white">
      <i class="bi bi-person-vcard"></i>
    </span>
    <h1 class="text-lg font-semibold text-white"><?= e(setting('site_name', APP_NAME)) ?></h1>
    <p class="mt-1 text-sm text-zinc-500">Panel administratora</p>
  </div>

  <?php if ($error !== ''): ?>
    <p class="mb-4 flex items-start gap-2 rounded-xl border border-rose-800 bg-rose-950/60 p-3 text-sm text-rose-200">
      <i class="bi bi-exclamation-triangle-fill mt-0.5"></i><span><?= e($error) ?></span>
    </p>
  <?php endif; ?>

  <form method="post" class="space-y-4 rounded-2xl border border-zinc-800 bg-zinc-900/60 p-6">
    <?= csrf_field() ?>
    <label class="block">
      <span class="<?= ui('label') ?>">E-mail</span>
      <input type="email" name="email" required autofocus autocomplete="username"
             value="<?= e($email) ?>" class="<?= ui('input') ?>">
    </label>
    <label class="block">
      <span class="<?= ui('label') ?>">Hasło</span>
      <input type="password" name="password" required autocomplete="current-password" class="<?= ui('input') ?>">
    </label>
    <button type="submit" class="<?= ui('btn_primary') ?> w-full justify-center">
      <i class="bi bi-box-arrow-in-right"></i> Zaloguj się
    </button>
  </form>

  <p class="mt-6 text-center text-xs text-zinc-600">
    <a href="<?= e(url('/')) ?>" class="hover:text-zinc-400">← Wróć na stronę</a>
  </p>
</div>
</body>
</html>
