<?php
/**
 * layout.php (panel) — ciemny layout z nawigacją boczną.
 * Zmienne: $content_view, $page_title, $user
 */
$nav = [
    ['/admin',           'bi-speedometer2',    'Pulpit'],
    ['/admin/tiles',     'bi-grid-1x2-fill',   'Kafelki Bento'],
    ['/admin/pages',     'bi-file-text-fill',  'Podstrony'],
    ['/admin/galleries', 'bi-images',          'Galerie'],
    ['/admin/settings',  'bi-sliders',         'Ustawienia'],
    ['/admin/account',   'bi-person-circle',   'Konto'],
];
$here = '/admin' . rtrim((string)preg_replace('~^' . preg_quote(base_path(), '~') . '/admin~', '', explode('?', (string)$_SERVER['REQUEST_URI'])[0]), '/');
?>
<!DOCTYPE html>
<html lang="pl" class="dark">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($page_title ?? 'Panel') ?> — <?= e(setting('site_name', APP_NAME)) ?></title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?display=swap&amp;family=Inter:wght@400;500;600;700">
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.1/dist/cdn.min.js"></script>
<style>
  body{font-family:Inter,system-ui,-apple-system,sans-serif}
  [x-cloak]{display:none!important}
  ::-webkit-scrollbar{width:10px;height:10px}
  ::-webkit-scrollbar-thumb{background:#3f3f46;border-radius:6px}
</style>
</head>
<body class="min-h-screen bg-zinc-950 text-zinc-200 antialiased" x-data="{ menu:false }">

<div class="flex min-h-screen">

  <!-- Nawigacja boczna -->
  <aside class="fixed inset-y-0 left-0 z-40 w-64 shrink-0 border-r border-zinc-800 bg-zinc-900/80 backdrop-blur transition-transform lg:static lg:translate-x-0"
         :class="menu ? 'translate-x-0' : '-translate-x-full'">
    <div class="flex h-16 items-center gap-2.5 border-b border-zinc-800 px-5">
      <span class="grid h-8 w-8 place-items-center rounded-lg bg-indigo-500 text-white"><i class="bi bi-person-vcard"></i></span>
      <span class="truncate text-sm font-semibold text-white"><?= e(setting('site_name', APP_NAME)) ?></span>
    </div>

    <nav class="space-y-1 p-3">
      <?php foreach ($nav as [$href, $icon, $label]):
        $active = $here === rtrim($href, '/') || ($href !== '/admin' && str_starts_with($here, $href)); ?>
        <a href="<?= e(url($href)) ?>" @click="menu=false"
           class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm transition <?= $active ? 'bg-indigo-500/15 text-indigo-300' : 'text-zinc-400 hover:bg-zinc-800 hover:text-zinc-100' ?>">
          <i class="bi <?= e($icon) ?> text-base"></i><?= e($label) ?>
        </a>
      <?php endforeach; ?>
    </nav>

    <div class="absolute inset-x-0 bottom-0 space-y-2 border-t border-zinc-800 p-3">
      <a href="<?= e(url('/')) ?>" target="_blank" rel="noopener"
         class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm text-zinc-400 transition hover:bg-zinc-800 hover:text-zinc-100">
        <i class="bi bi-box-arrow-up-right"></i> Zobacz stronę
      </a>
      <form method="post" action="<?= e(url('/admin/logout')) ?>">
        <?= csrf_field() ?>
        <button type="submit" class="flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-sm text-zinc-400 transition hover:bg-rose-950/50 hover:text-rose-300">
          <i class="bi bi-box-arrow-left"></i> Wyloguj
        </button>
      </form>
      <p class="px-3 pb-1 text-[11px] text-zinc-600"><?= e(APP_NAME) ?> v<?= e(APP_VERSION) ?></p>
    </div>
  </aside>

  <!-- Zasłona na mobile -->
  <div x-show="menu" x-cloak @click="menu=false" class="fixed inset-0 z-30 bg-black/60 lg:hidden"></div>

  <!-- Treść -->
  <div class="flex min-w-0 flex-1 flex-col">
    <header class="sticky top-0 z-20 flex h-16 items-center gap-3 border-b border-zinc-800 bg-zinc-950/85 px-4 backdrop-blur sm:px-6">
      <button @click="menu=!menu" class="grid h-9 w-9 place-items-center rounded-lg border border-zinc-800 text-zinc-300 lg:hidden">
        <i class="bi bi-list"></i>
      </button>
      <h1 class="truncate text-base font-semibold text-white"><?= e($page_title ?? 'Panel') ?></h1>
      <span class="ml-auto hidden text-xs text-zinc-500 sm:block"><?= e((string)($user['email'] ?? '')) ?></span>
    </header>

    <main class="mx-auto w-full max-w-6xl flex-1 p-4 sm:p-6">
      <?php foreach (flash_take() as $f):
        $tone = match ($f['type']) {
            'success' => 'border-emerald-800 bg-emerald-950/60 text-emerald-200',
            'error'   => 'border-rose-800 bg-rose-950/60 text-rose-200',
            default   => 'border-zinc-700 bg-zinc-900 text-zinc-200',
        };
        $ic = match ($f['type']) { 'success' => 'bi-check-circle-fill', 'error' => 'bi-exclamation-triangle-fill', default => 'bi-info-circle-fill' }; ?>
        <div class="mb-4 flex items-start gap-2.5 rounded-xl border p-3.5 text-sm <?= $tone ?>">
          <i class="bi <?= $ic ?> mt-0.5"></i><span><?= e($f['msg']) ?></span>
        </div>
      <?php endforeach; ?>

      <?php if (!empty($errors)): ?>
        <div class="mb-4 rounded-xl border border-rose-800 bg-rose-950/60 p-4 text-sm text-rose-200">
          <p class="mb-2 font-semibold">Nie zapisano — popraw błędy:</p>
          <ul class="list-disc space-y-1 pl-5">
            <?php foreach ($errors as $err): ?><li><?= $err ?></li><?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <?php require $content_view; ?>
    </main>
  </div>
</div>
</body>
</html>
