<?php
/** dashboard.php — pulpit: statystyki, podpowiedzi, skróty. Zmienne: $stats, $todo, $popular */
$cards = [
    ['Kafelki',   $stats['tiles'],     $stats['tiles_on'] . ' aktywnych',   'bi-grid-1x2-fill', '/admin/tiles'],
    ['Podstrony', $stats['pages'],     $stats['pages_on'] . ' opublikowanych', 'bi-file-text-fill', '/admin/pages'],
    ['Galerie',   $stats['galleries'], $stats['images'] . ' zdjęć',         'bi-images',        '/admin/galleries'],
    ['Odsłony',   $stats['views'],     'podstrony + galerie',               'bi-eye-fill',      '/admin/pages'],
];
?>
<div class="space-y-6">

  <!-- Statystyki -->
  <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
    <?php foreach ($cards as [$label, $value, $sub, $icon, $href]): ?>
      <a href="<?= e(url($href)) ?>" class="<?= ui('card') ?> group transition hover:border-indigo-600">
        <div class="flex items-center justify-between">
          <span class="text-xs font-semibold uppercase tracking-wider text-zinc-500"><?= e($label) ?></span>
          <i class="bi <?= e($icon) ?> text-indigo-400"></i>
        </div>
        <p class="mt-3 text-3xl font-semibold text-white"><?= (int)$value ?></p>
        <p class="mt-1 text-xs text-zinc-500"><?= e($sub) ?></p>
      </a>
    <?php endforeach; ?>
  </div>

  <div class="grid gap-6 lg:grid-cols-3">

    <!-- Skróty -->
    <section class="<?= ui('card') ?> lg:col-span-2">
      <h2 class="<?= ui('section_h') ?>">Szybkie akcje</h2>
      <div class="mt-4 grid gap-3 sm:grid-cols-2">
        <?php foreach ([
            ['/admin/tiles/new',     'bi-plus-square-fill', 'Nowy kafelek',   'Link, galeria, tekst, nagłówek…'],
            ['/admin/pages/new',     'bi-file-earmark-plus','Nowa podstrona', 'Oferta, cennik, O mnie…'],
            ['/admin/galleries/new', 'bi-images',           'Nowa galeria',   'Realizacje, certyfikaty…'],
            ['/admin/settings',      'bi-sliders',          'Ustawienia',     'Avatar, bio, kontakt, kolory'],
        ] as [$href, $icon, $t, $d]): ?>
          <a href="<?= e(url($href)) ?>" class="<?= ui('card_flat') ?> flex items-start gap-3 transition hover:border-indigo-600">
            <i class="bi <?= e($icon) ?> mt-0.5 text-lg text-indigo-400"></i>
            <span>
              <span class="block text-sm font-medium text-zinc-100"><?= e($t) ?></span>
              <span class="block text-xs text-zinc-500"><?= e($d) ?></span>
            </span>
          </a>
        <?php endforeach; ?>
      </div>

      <?php if ($popular): ?>
        <h2 class="<?= ui('section_h') ?> mt-7">Najczęściej odwiedzane</h2>
        <ul class="mt-3 divide-y divide-zinc-800">
          <?php foreach ($popular as $p): ?>
            <li class="flex items-center justify-between gap-3 py-2.5 text-sm">
              <span class="flex min-w-0 items-center gap-2">
                <span class="<?= ui('badge') ?> bg-zinc-800 text-zinc-400"><?= e($p['kind']) ?></span>
                <a href="<?= e(url((string)$p['href'])) ?>" target="_blank" rel="noopener" class="truncate text-zinc-200 hover:text-indigo-300">
                  <?= e($p['title']) ?>
                </a>
              </span>
              <span class="shrink-0 text-xs text-zinc-500"><?= (int)$p['views'] ?> odsłon</span>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>

    <!-- Co jeszcze uzupełnić -->
    <section class="<?= ui('card') ?>">
      <h2 class="<?= ui('section_h') ?>">Stan wizytówki</h2>
      <?php if (!$todo): ?>
        <p class="mt-4 flex items-start gap-2 text-sm text-emerald-300">
          <i class="bi bi-check-circle-fill mt-0.5"></i> Wszystko uzupełnione. Wizytówka gotowa!
        </p>
      <?php else: ?>
        <ul class="mt-4 space-y-2.5">
          <?php foreach ($todo as [$label, $href]): ?>
            <li>
              <a href="<?= e(url($href)) ?>" class="flex items-start gap-2 text-sm text-zinc-300 hover:text-indigo-300">
                <i class="bi bi-circle mt-1 text-[10px] text-amber-400"></i><?= e($label) ?>
              </a>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>

      <div class="mt-6 border-t border-zinc-800 pt-4 text-xs text-zinc-500">
        <p>Adres publiczny:</p>
        <a href="<?= e(url('/')) ?>" target="_blank" rel="noopener" class="break-all text-indigo-400 hover:underline"><?= e(abs_url('/')) ?></a>
        <?php if (setting_bool('vcard_enabled', true)): ?>
          <p class="mt-3">vCard: <a href="<?= e(url('/vcard.vcf')) ?>" class="text-indigo-400 hover:underline">pobierz .vcf</a></p>
        <?php endif; ?>
      </div>
    </section>
  </div>
</div>
