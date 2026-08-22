<?php /** pages.php — lista podstron. Zmienne: $pages */ ?>
<div class="space-y-5">
  <div class="flex flex-wrap items-center justify-between gap-3">
    <p class="text-sm text-zinc-400">Podstrony dostępne pod adresem <code class="text-zinc-300">/p/slug</code>.</p>
    <a href="<?= e(url('/admin/pages/new')) ?>" class="<?= ui('btn_primary') ?>"><i class="bi bi-plus-lg"></i> Nowa podstrona</a>
  </div>

  <?php if (!$pages): ?>
    <div class="<?= ui('card') ?> text-center">
      <i class="bi bi-file-text text-3xl text-zinc-700"></i>
      <p class="mt-3 text-sm text-zinc-400">Brak podstron. Dodaj np. „Oferta” albo „O mnie”.</p>
    </div>
  <?php else: ?>
    <div class="overflow-x-auto rounded-2xl border border-zinc-800">
      <table class="min-w-full divide-y divide-zinc-800">
        <thead class="bg-zinc-900/70">
          <tr>
            <th class="<?= ui('th') ?> w-20">Kolejność</th>
            <th class="<?= ui('th') ?>">Tytuł</th>
            <th class="<?= ui('th') ?>">Adres</th>
            <th class="<?= ui('th') ?>">Format</th>
            <th class="<?= ui('th') ?>">Status</th>
            <th class="<?= ui('th') ?>">Odsłony</th>
            <th class="<?= ui('th') ?> text-right">Akcje</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-zinc-800 bg-zinc-950/40">
        <?php foreach ($pages as $i => $p): ?>
          <tr>
            <td class="<?= ui('td') ?>">
              <div class="flex items-center gap-1">
                <form method="post" action="<?= e(url('/admin/pages/' . $p['id'] . '/move/up')) ?>"><?= csrf_field() ?>
                  <button class="<?= ui('btn_ghost') ?>" <?= $i === 0 ? 'disabled' : '' ?>><i class="bi bi-arrow-up"></i></button>
                </form>
                <form method="post" action="<?= e(url('/admin/pages/' . $p['id'] . '/move/down')) ?>"><?= csrf_field() ?>
                  <button class="<?= ui('btn_ghost') ?>" <?= $i === count($pages) - 1 ? 'disabled' : '' ?>><i class="bi bi-arrow-down"></i></button>
                </form>
              </div>
            </td>
            <td class="<?= ui('td') ?>">
              <a href="<?= e(url('/admin/pages/' . $p['id'])) ?>" class="font-medium text-zinc-100 hover:text-indigo-300"><?= e($p['title']) ?></a>
              <?php if ($p['excerpt'] !== ''): ?><span class="block max-w-md truncate text-xs text-zinc-500"><?= e($p['excerpt']) ?></span><?php endif; ?>
            </td>
            <td class="<?= ui('td') ?> text-xs">
              <a href="<?= e(url('/p/' . $p['slug'])) ?>" target="_blank" rel="noopener" class="text-indigo-400 hover:underline">/p/<?= e($p['slug']) ?></a>
            </td>
            <td class="<?= ui('td') ?> text-xs text-zinc-500"><?= $p['format'] === 'html' ? 'HTML' : 'Markdown' ?></td>
            <td class="<?= ui('td') ?>">
              <?php if ((int)$p['is_published'] === 1): ?>
                <span class="<?= ui('badge') ?> bg-emerald-500/15 text-emerald-300">opublikowana</span>
              <?php else: ?>
                <span class="<?= ui('badge') ?> bg-zinc-800 text-zinc-400">szkic</span>
              <?php endif; ?>
              <?php if ((int)$p['noindex'] === 1): ?>
                <span class="<?= ui('badge') ?> bg-amber-500/15 text-amber-300">noindex</span>
              <?php endif; ?>
            </td>
            <td class="<?= ui('td') ?> text-xs text-zinc-500"><?= (int)$p['views'] ?></td>
            <td class="<?= ui('td') ?>">
              <div class="flex items-center justify-end gap-1.5">
                <a href="<?= e(url('/admin/pages/' . $p['id'])) ?>" class="<?= ui('btn_ghost') ?>"><i class="bi bi-pencil"></i></a>
                <form method="post" action="<?= e(url('/admin/pages/' . $p['id'] . '/delete')) ?>"
                      onsubmit="return confirm('Usunąć podstronę „<?= e($p['title']) ?>”?')"><?= csrf_field() ?>
                  <button class="<?= ui('btn_ghost') ?> hover:text-rose-300"><i class="bi bi-trash"></i></button>
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
