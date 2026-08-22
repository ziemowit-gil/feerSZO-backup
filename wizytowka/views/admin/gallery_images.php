<?php
/**
 * gallery_images.php — multi-upload i zarządzanie zdjęciami galerii.
 * Zmienne: $gallery, $images
 */
?>
<div class="space-y-6">

  <div class="flex flex-wrap items-center justify-between gap-3">
    <p class="text-sm text-zinc-400">
      Galeria <strong class="text-zinc-200"><?= e($gallery['title']) ?></strong> ·
      <a href="<?= e(url('/g/' . $gallery['slug'])) ?>" target="_blank" rel="noopener" class="text-indigo-400 hover:underline">/g/<?= e($gallery['slug']) ?></a>
      · <?= count($images) ?> zdjęć
    </p>
    <div class="flex gap-2">
      <a href="<?= e(url('/admin/galleries/' . $gallery['id'])) ?>" class="<?= ui('btn') ?>"><i class="bi bi-pencil"></i> Dane galerii</a>
      <a href="<?= e(url('/admin/galleries')) ?>" class="<?= ui('btn') ?>">Wszystkie galerie</a>
    </div>
  </div>

  <!-- Multi-upload -->
  <section class="<?= ui('card') ?>" x-data="{ files: 0, names: [] }">
    <h2 class="<?= ui('section_h') ?>">Dodaj zdjęcia</h2>
    <form method="post" enctype="multipart/form-data" class="mt-4 space-y-4">
      <?= csrf_field() ?>
      <label class="flex cursor-pointer flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed border-zinc-700 bg-zinc-950/60 px-6 py-10 text-center transition hover:border-indigo-500">
        <i class="bi bi-cloud-arrow-up text-3xl text-indigo-400"></i>
        <span class="text-sm text-zinc-300">Kliknij i wybierz pliki (można wiele naraz)</span>
        <span class="text-xs text-zinc-500">JPG, PNG, WEBP, AVIF, GIF, SVG · maks. 8 MB na plik</span>
        <input type="file" name="images[]" multiple accept="image/*" class="sr-only"
               @change="files = $event.target.files.length; names = Array.from($event.target.files).map(f => f.name)">
        <span x-show="files" x-cloak class="mt-2 text-xs text-emerald-300">
          Wybrano: <span x-text="files"></span> plików
        </span>
      </label>
      <ul x-show="files" x-cloak class="grid gap-1 text-xs text-zinc-500 sm:grid-cols-2">
        <template x-for="n in names" :key="n"><li class="truncate">· <span x-text="n"></span></li></template>
      </ul>
      <button type="submit" class="<?= ui('btn_primary') ?>" :disabled="!files"><i class="bi bi-upload"></i> Wgraj</button>
    </form>
  </section>

  <?php if ($images): ?>
    <!-- Edycja opisów i kolejności -->
    <form method="post" class="space-y-4">
      <?= csrf_field() ?>
      <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        <?php foreach ($images as $i => $img):
            $isCover = (int)($gallery['cover_image_id'] ?? 0) === (int)$img['id']; ?>
          <article class="<?= ui('card') ?> space-y-3 p-4 <?= $isCover ? 'ring-1 ring-indigo-500' : '' ?>">
            <div class="relative overflow-hidden rounded-xl border border-zinc-800 bg-zinc-950">
              <img src="<?= e(upload_url('gallery/' . $img['filename'])) ?>" alt="<?= e($img['alt']) ?>"
                   class="aspect-[4/3] w-full object-cover">
              <?php if ($isCover): ?>
                <span class="absolute left-2 top-2 <?= ui('badge') ?> bg-indigo-500 text-white"><i class="bi bi-star-fill"></i> okładka</span>
              <?php endif; ?>
            </div>

            <label class="block">
              <span class="<?= ui('label') ?>">Opis alternatywny (alt)</span>
              <input name="img[<?= (int)$img['id'] ?>][alt]" value="<?= e($img['alt']) ?>" class="<?= ui('input') ?>"
                     placeholder="Co widać na zdjęciu">
            </label>
            <label class="block">
              <span class="<?= ui('label') ?>">Podpis pod zdjęciem</span>
              <input name="img[<?= (int)$img['id'] ?>][caption]" value="<?= e($img['caption']) ?>" class="<?= ui('input') ?>">
            </label>

            <div class="flex items-end gap-2">
              <label class="block w-24">
                <span class="<?= ui('label') ?>">Pozycja</span>
                <input type="number" min="1" name="img[<?= (int)$img['id'] ?>][position]" value="<?= (int)$img['position'] ?>" class="<?= ui('input') ?>">
              </label>
              <p class="flex-1 text-[11px] leading-4 text-zinc-500">
                <?= (int)$img['width'] ?>×<?= (int)$img['height'] ?> px<br><?= e(human_size((int)$img['size_bytes'])) ?>
              </p>
            </div>

            <div class="flex items-center gap-1 border-t border-zinc-800 pt-3">
              <button type="submit" form="up-<?= (int)$img['id'] ?>" class="<?= ui('btn_ghost') ?>" <?= $i === 0 ? 'disabled' : '' ?>><i class="bi bi-arrow-up"></i></button>
              <button type="submit" form="down-<?= (int)$img['id'] ?>" class="<?= ui('btn_ghost') ?>" <?= $i === count($images) - 1 ? 'disabled' : '' ?>><i class="bi bi-arrow-down"></i></button>
              <button type="submit" form="cover-<?= (int)$img['id'] ?>" class="<?= ui('btn_ghost') ?>" title="Ustaw jako okładkę"><i class="bi bi-star"></i></button>
              <button type="submit" form="del-<?= (int)$img['id'] ?>" class="<?= ui('btn_ghost') ?> ml-auto hover:text-rose-300"><i class="bi bi-trash"></i></button>
            </div>
          </article>
        <?php endforeach; ?>
      </div>

      <div class="sticky bottom-4 flex justify-end">
        <button type="submit" class="<?= ui('btn_primary') ?> shadow-lg shadow-black/40">
          <i class="bi bi-check-lg"></i> Zapisz opisy i kolejność
        </button>
      </div>
    </form>

    <!-- Formularze akcji pojedynczych zdjęć (poza formularzem edycji) -->
    <?php foreach ($images as $img): $id = (int)$img['id']; ?>
      <form id="up-<?= $id ?>"    method="post" action="<?= e(url('/admin/images/' . $id . '/move/up')) ?>"   class="hidden"><?= csrf_field() ?></form>
      <form id="down-<?= $id ?>"  method="post" action="<?= e(url('/admin/images/' . $id . '/move/down')) ?>" class="hidden"><?= csrf_field() ?></form>
      <form id="cover-<?= $id ?>" method="post" action="<?= e(url('/admin/images/' . $id . '/cover')) ?>"     class="hidden"><?= csrf_field() ?></form>
      <form id="del-<?= $id ?>"   method="post" action="<?= e(url('/admin/images/' . $id . '/delete')) ?>"
            onsubmit="return confirm('Usunąć to zdjęcie?')" class="hidden"><?= csrf_field() ?></form>
    <?php endforeach; ?>
  <?php endif; ?>
</div>
