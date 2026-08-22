<?php
/** page_form.php — formularz podstrony (treść + SEO). Zmienne: $page */
$isNew = (int)$page['id'] === 0;
?>
<form method="post" enctype="multipart/form-data" class="space-y-6" x-data="{ format: '<?= e($page['format']) ?>' }">
  <?= csrf_field() ?>

  <div class="grid gap-6 lg:grid-cols-3">
    <div class="space-y-6 lg:col-span-2">

      <section class="<?= ui('card') ?> space-y-5">
        <h2 class="<?= ui('section_h') ?>">Treść</h2>

        <label class="block">
          <span class="<?= ui('label') ?>">Tytuł <span class="text-rose-400">*</span></span>
          <input name="title" required value="<?= e($page['title']) ?>" class="<?= ui('input') ?>" placeholder="np. Oferta szkoleń">
        </label>

        <div class="grid gap-5 sm:grid-cols-2">
          <label class="block">
            <span class="<?= ui('label') ?>">Adres (slug)</span>
            <input name="slug" value="<?= e($page['slug']) ?>" class="<?= ui('input') ?>" placeholder="oferta-szkolen">
            <span class="<?= ui('hint') ?>">Puste = wygenerowany z tytułu. Adres: <code>/p/slug</code></span>
          </label>
          <label class="block">
            <span class="<?= ui('label') ?>">Format treści</span>
            <select name="format" x-model="format" class="<?= ui('select') ?>">
              <option value="markdown" <?= $page['format'] === 'markdown' ? 'selected' : '' ?>>Markdown</option>
              <option value="html" <?= $page['format'] === 'html' ? 'selected' : '' ?>>HTML</option>
            </select>
          </label>
        </div>

        <label class="block">
          <span class="<?= ui('label') ?>">Zajawka (krótki wstęp)</span>
          <input name="excerpt" value="<?= e($page['excerpt']) ?>" class="<?= ui('input') ?>">
        </label>

        <label class="block">
          <span class="<?= ui('label') ?>">Treść</span>
          <textarea name="content" rows="18" class="<?= ui('textarea') ?>"><?= e($page['content']) ?></textarea>
          <span class="<?= ui('hint') ?>" x-show="format === 'markdown'">
            Markdown: <code>## Nagłówek</code>, <code>**pogrubienie**</code>, <code>- lista</code>,
            <code>[link](https://…)</code>, <code>![opis](/uploads/…)</code>
          </span>
          <span class="<?= ui('hint') ?>" x-show="format === 'html'">
            HTML: znaczniki <code>script</code>, <code>iframe</code> i atrybuty <code>on*</code> są usuwane przy zapisie.
          </span>
        </label>
      </section>

      <section class="<?= ui('card') ?> space-y-5">
        <h2 class="<?= ui('section_h') ?>">SEO</h2>
        <div class="grid gap-5 sm:grid-cols-2">
          <label class="block">
            <span class="<?= ui('label') ?>">Meta title</span>
            <input name="meta_title" value="<?= e($page['meta_title']) ?>" class="<?= ui('input') ?>" maxlength="70">
            <span class="<?= ui('hint') ?>">Puste = tytuł podstrony. Optymalnie do 60 znaków.</span>
          </label>
          <label class="block">
            <span class="<?= ui('label') ?>">Słowa kluczowe</span>
            <input name="meta_keywords" value="<?= e($page['meta_keywords']) ?>" class="<?= ui('input') ?>"
                   placeholder="szkolenia, neuroróżnorodność, dostępność">
          </label>
        </div>
        <label class="block">
          <span class="<?= ui('label') ?>">Meta description</span>
          <textarea name="meta_description" rows="3" class="<?= ui('input') ?> font-sans" maxlength="200"><?= e($page['meta_description']) ?></textarea>
          <span class="<?= ui('hint') ?>">150–160 znaków działa najlepiej.</span>
        </label>
        <label class="flex items-center gap-2.5 text-sm text-zinc-300">
          <input type="checkbox" name="noindex" value="1" <?= (int)$page['noindex'] === 1 ? 'checked' : '' ?> class="<?= ui('checkbox') ?>">
          Nie indeksuj tej podstrony (noindex)
        </label>
      </section>
    </div>

    <div class="space-y-6">
      <section class="<?= ui('card') ?> space-y-4">
        <h2 class="<?= ui('section_h') ?>">Publikacja</h2>
        <label class="flex items-center gap-2.5 text-sm text-zinc-300">
          <input type="checkbox" name="is_published" value="1" <?= (int)$page['is_published'] === 1 ? 'checked' : '' ?> class="<?= ui('checkbox') ?>">
          Opublikowana
        </label>
        <label class="block">
          <span class="<?= ui('label') ?>">Pozycja</span>
          <input type="number" name="position" min="1" value="<?= (int)$page['position'] ?>" class="<?= ui('input') ?>">
        </label>
        <?php if (!$isNew): ?>
          <p class="text-xs text-zinc-500">
            Odsłony: <?= (int)$page['views'] ?> ·
            <a href="<?= e(url('/p/' . $page['slug'])) ?>" target="_blank" rel="noopener" class="text-indigo-400 hover:underline">podejrzyj</a>
          </p>
        <?php endif; ?>
      </section>

      <section class="<?= ui('card') ?> space-y-4">
        <h2 class="<?= ui('section_h') ?>">Obraz nagłówkowy</h2>
        <?php if ($page['hero_image'] !== ''): ?>
          <img src="<?= e(upload_url((string)$page['hero_image'])) ?>" alt="" class="w-full rounded-lg object-cover">
          <label class="flex items-center gap-2 text-sm text-rose-300">
            <input type="checkbox" name="remove_hero_image" value="1" class="<?= ui('checkbox') ?>"> Usuń obraz
          </label>
        <?php endif; ?>
        <input type="file" name="hero_image" accept="image/*"
               class="w-full rounded-lg border border-zinc-700 bg-zinc-950 p-2 text-sm file:mr-3 file:rounded file:border-0 file:bg-zinc-800 file:px-3 file:py-1.5 file:text-zinc-200">
      </section>
    </div>
  </div>

  <div class="flex flex-wrap items-center gap-3 border-t border-zinc-800 pt-5">
    <button type="submit" class="<?= ui('btn_primary') ?>"><i class="bi bi-check-lg"></i> <?= $isNew ? 'Utwórz podstronę' : 'Zapisz zmiany' ?></button>
    <a href="<?= e(url('/admin/pages')) ?>" class="<?= ui('btn') ?>">Anuluj</a>
    <?php if (!$isNew): ?>
      <button type="submit" form="page-delete" class="<?= ui('btn_danger') ?> ml-auto"><i class="bi bi-trash"></i> Usuń podstronę</button>
    <?php endif; ?>
  </div>
</form>

<?php if (!$isNew): ?>
  <form id="page-delete" method="post" action="<?= e(url('/admin/pages/' . $page['id'] . '/delete')) ?>"
        onsubmit="return confirm('Usunąć tę podstronę? Tej operacji nie można cofnąć.')" class="hidden"><?= csrf_field() ?></form>
<?php endif; ?>
