<?php
/**
 * install.php (widok) — samodzielna strona instalatora (Tailwind z CDN).
 * Zmienne: $requirements, $canInstall, $errors, $in
 */
?>
<!DOCTYPE html>
<html lang="pl" class="dark">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Instalator — <?= e(APP_NAME) ?> <?= e(APP_VERSION) ?></title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?display=swap&amp;family=Inter:wght@400;500;600;700">
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.1/dist/cdn.min.js"></script>
<style>body{font-family:Inter,system-ui,sans-serif}[x-cloak]{display:none!important}</style>
</head>
<body class="min-h-screen bg-zinc-950 text-zinc-200 antialiased">

<div class="mx-auto max-w-3xl px-5 py-12">

  <header class="mb-10">
    <p class="text-xs uppercase tracking-[0.2em] text-indigo-400">Instalator · v<?= e(APP_VERSION) ?></p>
    <h1 class="mt-2 text-3xl font-semibold text-white">Twoja cyfrowa wizytówka</h1>
    <p class="mt-2 text-zinc-400">
      Kilka pól i instancja jest gotowa: baza SQLite, konto administratora i startowy układ strony.
    </p>
  </header>

  <!-- Wymagania środowiska -->
  <section class="mb-8 rounded-2xl border border-zinc-800 bg-zinc-900/60 p-6">
    <h2 class="mb-4 text-sm font-semibold uppercase tracking-wider text-zinc-400">Wymagania serwera</h2>
    <ul class="grid gap-2 sm:grid-cols-2">
      <?php foreach ($requirements as $r): ?>
        <li class="flex items-start gap-2 text-sm">
          <i class="bi <?= $r['ok'] ? 'bi-check-circle-fill text-emerald-400' : 'bi-x-circle-fill text-rose-400' ?> mt-0.5"></i>
          <span>
            <span class="<?= $r['ok'] ? 'text-zinc-200' : 'text-rose-300' ?>"><?= e($r['label']) ?></span>
            <span class="block text-xs text-zinc-500"><?= e($r['hint']) ?></span>
          </span>
        </li>
      <?php endforeach; ?>
    </ul>
  </section>

  <?php if ($errors): ?>
    <div class="mb-6 rounded-xl border border-rose-800 bg-rose-950/60 p-4 text-sm text-rose-200">
      <p class="mb-2 font-semibold">Popraw poniższe błędy:</p>
      <ul class="list-disc space-y-1 pl-5">
        <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <form method="post" class="space-y-8" x-data="{show:false}">
    <?= csrf_field() ?>

    <!-- Dane strony -->
    <section class="rounded-2xl border border-zinc-800 bg-zinc-900/60 p-6">
      <h2 class="mb-5 text-sm font-semibold uppercase tracking-wider text-zinc-400">1 · Strona</h2>
      <div class="grid gap-5 sm:grid-cols-2">
        <label class="block sm:col-span-1">
          <span class="mb-1.5 block text-sm text-zinc-300">Nazwa strony <span class="text-rose-400">*</span></span>
          <input name="site_name" required value="<?= e($in['site_name']) ?>" placeholder="np. Alicja Bazan"
                 class="w-full rounded-lg border border-zinc-700 bg-zinc-950 px-3 py-2 text-sm outline-none focus:border-indigo-500">
        </label>
        <label class="block sm:col-span-1">
          <span class="mb-1.5 block text-sm text-zinc-300">Imię i nazwisko <span class="text-rose-400">*</span></span>
          <input name="owner_name" required value="<?= e($in['owner_name']) ?>" placeholder="np. Alicja Bazan"
                 class="w-full rounded-lg border border-zinc-700 bg-zinc-950 px-3 py-2 text-sm outline-none focus:border-indigo-500">
        </label>
        <label class="block sm:col-span-2">
          <span class="mb-1.5 block text-sm text-zinc-300">Tytuł / krótki opis</span>
          <input name="tagline" value="<?= e($in['tagline']) ?>" placeholder="np. Szkoleniowczyni, edukatorka, mówczyni"
                 class="w-full rounded-lg border border-zinc-700 bg-zinc-950 px-3 py-2 text-sm outline-none focus:border-indigo-500">
        </label>
      </div>
    </section>

    <!-- Wygląd -->
    <section class="rounded-2xl border border-zinc-800 bg-zinc-900/60 p-6">
      <h2 class="mb-5 text-sm font-semibold uppercase tracking-wider text-zinc-400">2 · Wygląd</h2>
      <div class="grid gap-3 sm:grid-cols-3">
        <?php foreach (theme_presets() as $key => $p): ?>
          <label class="cursor-pointer">
            <input type="radio" name="preset" value="<?= e($key) ?>" class="peer sr-only"
                   <?= $in['preset'] === $key ? 'checked' : '' ?>>
            <span class="block rounded-xl border border-zinc-700 p-4 transition peer-checked:border-indigo-500 peer-checked:bg-indigo-500/10">
              <span class="flex gap-1.5">
                <?php foreach (['color_bg', 'color_accent', 'color_text', 'color_btn_bg'] as $c): ?>
                  <span class="h-5 w-5 rounded-full border border-black/20" style="background:<?= e($p[$c]) ?>"></span>
                <?php endforeach; ?>
              </span>
              <span class="mt-3 block text-sm font-medium text-zinc-100"><?= e($p['label']) ?></span>
              <span class="mt-0.5 block text-xs text-zinc-500">Układ: <?= $p['site_layout'] === 'bento' ? 'siatka Bento' : 'jedna kolumna' ?></span>
            </span>
          </label>
        <?php endforeach; ?>
      </div>
      <label class="mt-5 flex items-start gap-3 text-sm text-zinc-300">
        <input type="checkbox" name="seed" value="1" <?= !empty($in['seed']) ? 'checked' : '' ?>
               class="mt-0.5 h-4 w-4 rounded border-zinc-600 bg-zinc-950">
        <span>Dodaj przykładową treść (kafelki, podstronę „O mnie", galerię „Realizacje") — łatwiej zacząć.</span>
      </label>
    </section>

    <!-- Konto administratora -->
    <section class="rounded-2xl border border-zinc-800 bg-zinc-900/60 p-6">
      <h2 class="mb-5 text-sm font-semibold uppercase tracking-wider text-zinc-400">3 · Konto administratora</h2>
      <div class="grid gap-5 sm:grid-cols-2">
        <label class="block sm:col-span-2">
          <span class="mb-1.5 block text-sm text-zinc-300">Adres e-mail (login) <span class="text-rose-400">*</span></span>
          <input type="email" name="email" required value="<?= e($in['email']) ?>" autocomplete="username"
                 class="w-full rounded-lg border border-zinc-700 bg-zinc-950 px-3 py-2 text-sm outline-none focus:border-indigo-500">
        </label>
        <label class="block">
          <span class="mb-1.5 block text-sm text-zinc-300">Hasło <span class="text-rose-400">*</span></span>
          <input :type="show ? 'text' : 'password'" name="password" required autocomplete="new-password"
                 class="w-full rounded-lg border border-zinc-700 bg-zinc-950 px-3 py-2 text-sm outline-none focus:border-indigo-500">
        </label>
        <label class="block">
          <span class="mb-1.5 block text-sm text-zinc-300">Powtórz hasło <span class="text-rose-400">*</span></span>
          <input :type="show ? 'text' : 'password'" name="password2" required autocomplete="new-password"
                 class="w-full rounded-lg border border-zinc-700 bg-zinc-950 px-3 py-2 text-sm outline-none focus:border-indigo-500">
        </label>
      </div>
      <label class="mt-4 flex items-center gap-2 text-xs text-zinc-400">
        <input type="checkbox" x-model="show" class="h-4 w-4 rounded border-zinc-600 bg-zinc-950"> Pokaż hasło
      </label>
      <p class="mt-3 text-xs text-zinc-500">
        Minimum 10 znaków, wielka i mała litera oraz cyfra. Hasło zapisujemy jako <code>password_hash()</code>.
      </p>
    </section>

    <div class="flex flex-wrap items-center gap-4">
      <button type="submit" <?= $canInstall ? '' : 'disabled' ?>
              class="inline-flex items-center gap-2 rounded-xl bg-indigo-500 px-5 py-3 text-sm font-semibold text-white transition hover:bg-indigo-400 disabled:cursor-not-allowed disabled:opacity-40">
        <i class="bi bi-rocket-takeoff-fill"></i> Zainstaluj
      </button>
      <p class="text-xs text-zinc-500">Baza zostanie utworzona w <code><?= e(basename(DB_FILE)) ?></code>.</p>
    </div>
  </form>
</div>
</body>
</html>
