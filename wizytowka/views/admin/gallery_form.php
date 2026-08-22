<?php
/** gallery_form.php — dane galerii. Zmienne: $gallery */
$isNew = (int)$gallery['id'] === 0;
?>
<form method="post" class="space-y-6">
  <?= csrf_field() ?>

  <div class="grid gap-6 lg:grid-cols-3">
    <div class="space-y-6 lg:col-span-2">
      <section class="<?= ui('card') ?> space-y-5">
        <h2 class="<?= ui('section_h') ?>">Galeria</h2>

        <label class="block">
          <span class="<?= ui('label') ?>">Tytuł <span class="text-rose-400">*</span></span>
          <input name="title" required value="<?= e($gallery['title']) ?>" class="<?= ui('input') ?>" placeholder="np. Realizacje">
        </label>

        <div class="grid gap-5 sm:grid-cols-2">
          <label class="block">
            <span class="<?= ui('label') ?>">Adres (slug)</span>
            <input name="slug" value="<?= e($gallery['slug']) ?>" class="<?= ui('input') ?>" placeholder="realizacje">
            <span class="<?= ui('hint') ?>">Puste = z tytułu. Adres: <code>/g/slug</code></span>
          </label>
          <label class="block">
            <span class="<?= ui('label') ?>">Układ siatki</span>
            <select name="layout" class="<?= ui('select') ?>">
              <option value="masonry" <?= $gallery['layout'] === 'masonry' ? 'selected' : '' ?>>Naturalne proporcje</option>
              <option value="grid" <?= $gallery['layout'] === 'grid' ? 'selected' : '' ?>>Kwadraty (kadrowanie)</option>
            </select>
          </label>
        </div>

        <label class="block">
          <span class="<?= ui('label') ?>">Opis (Markdown)</span>
          <textarea name="description" rows="5" class="<?= ui('textarea') ?>"><?= e($gallery['description']) ?></textarea>
        </label>
      </section>

      <section class="<?= ui('card') ?> space-y-5">
        <h2 class="<?= ui('section_h') ?>">SEO</h2>
        <label class="block">
          <span class="<?= ui('label') ?>">Meta title</span>
          <input name="meta_title" value="<?= e($gallery['meta_title']) ?>" class="<?= ui('input') ?>" maxlength="70">
        </label>
        <label class="block">
          <span class="<?= ui('label') ?>">Meta description</span>
          <textarea name="meta_description" rows="3" class="<?= ui('input') ?> font-sans" maxlength="200"><?= e($gallery['meta_description']) ?></textarea>
        </label>
      </section>
    </div>

    <div class="space-y-6">
      <section class="<?= ui('card') ?> space-y-4">
        <h2 class="<?= ui('section_h') ?>">Publikacja</h2>
        <label class="flex items-center gap-2.5 text-sm text-zinc-300">
          <input type="checkbox" name="is_published" value="1" <?= (int)$gallery['is_published'] === 1 ? 'checked' : '' ?> class="<?= ui('checkbox') ?>">
          Galeria publiczna
        </label>
        <label class="block">
          <span class="<?= ui('label') ?>">Pozycja</span>
          <input type="number" name="position" min="1" value="<?= (int)$gallery['position'] ?>" class="<?= ui('input') ?>">
        </label>
        <?php if (!$isNew): ?>
          <p class="text-xs text-zinc-500">
            Odsłony: <?= (int)$gallery['views'] ?> ·
            <a href="<?= e(url('/g/' . $gallery['slug'])) ?>" target="_blank" rel="noopener" class="text-indigo-400 hover:underline">podejrzyj</a>
          </p>
          <a href="<?= e(url('/admin/galleries/' . $gallery['id'] . '/images')) ?>" class="<?= ui('btn') ?> w-full justify-center">
            <i class="bi bi-images"></i> Zarządzaj zdjęciami
          </a>
        <?php else: ?>
          <p class="text-xs text-zinc-500">Po utworzeniu galerii od razu przejdziesz do wgrywania zdjęć.</p>
        <?php endif; ?>
      </section>
    </div>
  </div>

  <div class="flex flex-wrap items-center gap-3 border-t border-zinc-800 pt-5">
    <button type="submit" class="<?= ui('btn_primary') ?>"><i class="bi bi-check-lg"></i> <?= $isNew ? 'Utwórz galerię' : 'Zapisz zmiany' ?></button>
    <a href="<?= e(url('/admin/galleries')) ?>" class="<?= ui('btn') ?>">Anuluj</a>
    <?php if (!$isNew): ?>
      <button type="submit" form="gal-delete" class="<?= ui('btn_danger') ?> ml-auto"><i class="bi bi-trash"></i> Usuń galerię</button>
    <?php endif; ?>
  </div>
</form>

<?php if (!$isNew): ?>
  <form id="gal-delete" method="post" action="<?= e(url('/admin/galleries/' . $gallery['id'] . '/delete')) ?>"
        onsubmit="return confirm('Usunąć galerię wraz ze wszystkimi zdjęciami?')" class="hidden"><?= csrf_field() ?></form>
<?php endif; ?>
