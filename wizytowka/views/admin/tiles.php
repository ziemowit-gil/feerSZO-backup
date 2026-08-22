<?php
/** tiles.php — lista kafelków z kolejnością i przełącznikiem widoczności. Zmienne: $tiles */
$types = tile_types();
$sizes = tile_sizes();
?>
<div class="space-y-5">

  <div class="flex flex-wrap items-center justify-between gap-3">
    <p class="text-sm text-zinc-400">
      Kolejność na liście = kolejność na stronie głównej. <?= count($tiles) ?> kafelków.
    </p>
    <a href="<?= e(url('/admin/tiles/new')) ?>" class="<?= ui('btn_primary') ?>">
      <i class="bi bi-plus-lg"></i> Nowy kafelek
    </a>
  </div>

  <?php if (!$tiles): ?>
    <div class="<?= ui('card') ?> text-center">
      <i class="bi bi-grid-1x2 text-3xl text-zinc-700"></i>
      <p class="mt-3 text-sm text-zinc-400">Nie ma jeszcze kafelków. Dodaj pierwszy — np. link do LinkedIn.</p>
    </div>
  <?php else: ?>
    <div class="overflow-x-auto rounded-2xl border border-zinc-800">
      <table class="min-w-full divide-y divide-zinc-800">
        <thead class="bg-zinc-900/70">
          <tr>
            <th class="<?= ui('th') ?> w-20">Kolejność</th>
            <th class="<?= ui('th') ?>">Kafelek</th>
            <th class="<?= ui('th') ?>">Typ</th>
            <th class="<?= ui('th') ?>">Rozmiar</th>
            <th class="<?= ui('th') ?>">Cel</th>
            <th class="<?= ui('th') ?> text-right">Akcje</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-zinc-800 bg-zinc-950/40">
        <?php foreach ($tiles as $i => $t):
            $target = match ($t['type']) {
                'page'    => $t['page_title']    ? '/p/… ' . $t['page_title']    : '⚠ brak podstrony',
                'gallery' => $t['gallery_title'] ? '/g/… ' . $t['gallery_title'] : '⚠ brak galerii',
                'link', 'social' => (string)$t['url'],
                'email'   => (string)($t['url'] ?: setting('email')),
                'phone'   => (string)($t['url'] ?: setting('phone')),
                default   => '—',
            }; ?>
          <tr class="<?= (int)$t['is_active'] === 1 ? '' : 'opacity-45' ?>">
            <td class="<?= ui('td') ?>">
              <div class="flex items-center gap-1">
                <form method="post" action="<?= e(url('/admin/tiles/' . $t['id'] . '/move/up')) ?>">
                  <?= csrf_field() ?>
                  <button class="<?= ui('btn_ghost') ?>" title="W górę" <?= $i === 0 ? 'disabled' : '' ?>><i class="bi bi-arrow-up"></i></button>
                </form>
                <form method="post" action="<?= e(url('/admin/tiles/' . $t['id'] . '/move/down')) ?>">
                  <?= csrf_field() ?>
                  <button class="<?= ui('btn_ghost') ?>" title="W dół" <?= $i === count($tiles) - 1 ? 'disabled' : '' ?>><i class="bi bi-arrow-down"></i></button>
                </form>
              </div>
            </td>
            <td class="<?= ui('td') ?>">
              <div class="flex items-center gap-3">
                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg border border-zinc-800 bg-zinc-900 text-indigo-400">
                  <?= $t['icon'] !== '' ? icon_html((string)$t['icon']) : '<i class="bi bi-square"></i>' ?>
                </span>
                <span class="min-w-0">
                  <a href="<?= e(url('/admin/tiles/' . $t['id'])) ?>" class="block truncate font-medium text-zinc-100 hover:text-indigo-300">
                    <?= $t['title'] !== '' ? e($t['title']) : '<span class="text-zinc-500">(bez tytułu)</span>' ?>
                  </a>
                  <?php if ($t['subtitle'] !== ''): ?>
                    <span class="block truncate text-xs text-zinc-500"><?= e($t['subtitle']) ?></span>
                  <?php endif; ?>
                </span>
              </div>
            </td>
            <td class="<?= ui('td') ?>"><span class="<?= ui('badge') ?> bg-zinc-800 text-zinc-300"><?= e($types[$t['type']] ?? $t['type']) ?></span></td>
            <td class="<?= ui('td') ?> text-xs text-zinc-500"><?= e($sizes[$t['size']] ?? $t['size']) ?></td>
            <td class="<?= ui('td') ?> max-w-[16rem] truncate text-xs text-zinc-500"><?= e($target) ?></td>
            <td class="<?= ui('td') ?>">
              <div class="flex items-center justify-end gap-1.5">
                <form method="post" action="<?= e(url('/admin/tiles/' . $t['id'] . '/toggle')) ?>">
                  <?= csrf_field() ?>
                  <button class="<?= ui('btn_ghost') ?>" title="<?= (int)$t['is_active'] === 1 ? 'Ukryj' : 'Pokaż' ?>">
                    <i class="bi <?= (int)$t['is_active'] === 1 ? 'bi-eye-fill text-emerald-400' : 'bi-eye-slash' ?>"></i>
                  </button>
                </form>
                <a href="<?= e(url('/admin/tiles/' . $t['id'])) ?>" class="<?= ui('btn_ghost') ?>" title="Edytuj"><i class="bi bi-pencil"></i></a>
                <form method="post" action="<?= e(url('/admin/tiles/' . $t['id'] . '/delete')) ?>"
                      onsubmit="return confirm('Usunąć ten kafelek?')">
                  <?= csrf_field() ?>
                  <button class="<?= ui('btn_ghost') ?> hover:text-rose-300" title="Usuń"><i class="bi bi-trash"></i></button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
