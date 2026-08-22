<?php /** galleries.php — lista galerii. Zmienne: $galleries */ ?>
<div class="space-y-5">
  <div class="flex flex-wrap items-center justify-between gap-3">
    <p class="text-sm text-zinc-400">Galerie dostępne pod adresem <code class="text-zinc-300">/g/slug</code>.</p>
    <a href="<?= e(url('/admin/galleries/new')) ?>" class="<?= ui('btn_primary') ?>"><i class="bi bi-plus-lg"></i> Nowa galeria</a>
  </div>

  <?php if (!$galleries): ?>
    <div class="<?= ui('card') ?> text-center">
      <i class="bi bi-images text-3xl text-zinc-700"></i>
      <p class="mt-3 text-sm text-zinc-400">Brak galerii. Utwórz np. „Realizacje” albo „Certyfikaty”.</p>
    </div>
  <?php else: ?>
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
      <?php foreach ($galleries as $i => $g): $cover = gallery_cover($g); ?>
        <article class="<?= ui('card') ?> flex flex-col gap-3 p-4">
          <a href="<?= e(url('/admin/galleries/' . $g['id'] . '/images')) ?>"
             class="block aspect-[16/10] overflow-hidden rounded-xl border border-zinc-800 bg-zinc-950">
            <?php if ($cover): ?>
              <img src="<?= e(upload_url('gallery/' . $cover['filename'])) ?>" alt="" class="h-full w-full object-cover">
            <?php else: ?>
              <span class="grid h-full place-items-center text-zinc-700"><i class="bi bi-image text-3xl"></i></span>
            <?php endif; ?>
          </a>

          <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
              <a href="<?= e(url('/admin/galleries/' . $g['id'])) ?>" class="block truncate font-medium text-zinc-100 hover:text-indigo-300">
                <?= e($g['title']) ?>
              </a>
              <p class="text-xs text-zinc-500">
                <?= (int)$g['images_count'] ?> zdjęć ·
                <a href="<?= e(url('/g/' . $g['slug'])) ?>" target="_blank" rel="noopener" class="text-indigo-400 hover:underline">/g/<?= e($g['slug']) ?></a>
              </p>
            </div>
            <?php if ((int)$g['is_published'] === 1): ?>
              <span class="<?= ui('badge') ?> shrink-0 bg-emerald-500/15 text-emerald-300">publiczna</span>
            <?php else: ?>
              <span class="<?= ui('badge') ?> shrink-0 bg-zinc-800 text-zinc-400">ukryta</span>
            <?php endif; ?>
          </div>

          <div class="mt-auto flex items-center gap-1.5 border-t border-zinc-800 pt-3">
            <a href="<?= e(url('/admin/galleries/' . $g['id'] . '/images')) ?>" class="<?= ui('btn_ghost') ?>"><i class="bi bi-images"></i> Zdjęcia</a>
            <a href="<?= e(url('/admin/galleries/' . $g['id'])) ?>" class="<?= ui('btn_ghost') ?>"><i class="bi bi-pencil"></i> Edytuj</a>
            <span class="ml-auto flex items-center gap-1">
              <form method="post" action="<?= e(url('/admin/galleries/' . $g['id'] . '/move/up')) ?>"><?= csrf_field() ?>
                <button class="<?= ui('btn_ghost') ?>" <?= $i === 0 ? 'disabled' : '' ?>><i class="bi bi-arrow-up"></i></button>
              </form>
              <form method="post" action="<?= e(url('/admin/galleries/' . $g['id'] . '/move/down')) ?>"><?= csrf_field() ?>
                <button class="<?= ui('btn_ghost') ?>" <?= $i === count($galleries) - 1 ? 'disabled' : '' ?>><i class="bi bi-arrow-down"></i></button>
              </form>
              <form method="post" action="<?= e(url('/admin/galleries/' . $g['id'] . '/delete')) ?>"
                    onsubmit="return confirm('Usunąć galerię „<?= e($g['title']) ?>” wraz ze wszystkimi zdjęciami?')"><?= csrf_field() ?>
                <button class="<?= ui('btn_ghost') ?> hover:text-rose-300"><i class="bi bi-trash"></i></button>
              </form>
            </span>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
