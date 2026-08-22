<?php
/**
 * tile_form.php — formularz kafelka. Pola pokazywane zależnie od typu (Alpine).
 * Zmienne: $tile, $pages, $galleries
 */
$isNew = (int)$tile['id'] === 0;
?>
<form method="post" enctype="multipart/form-data" class="space-y-6"
      x-data="{ type: '<?= e($tile['type']) ?>', icon: '<?= e($tile['icon']) ?>' }">
  <?= csrf_field() ?>

  <div class="grid gap-6 lg:grid-cols-3">

    <!-- Kolumna główna -->
    <div class="space-y-6 lg:col-span-2">

      <section class="<?= ui('card') ?> space-y-5">
        <h2 class="<?= ui('section_h') ?>">Rodzaj i treść</h2>

        <div class="grid gap-5 sm:grid-cols-2">
          <label class="block">
            <span class="<?= ui('label') ?>">Typ kafelka</span>
            <select name="type" x-model="type" class="<?= ui('select') ?>">
              <?php foreach (tile_types() as $k => $label): ?>
                <option value="<?= e($k) ?>" <?= $tile['type'] === $k ? 'selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
            <span class="<?= ui('hint') ?>">„Ikona social media” trafia do wiersza ikon w nagłówku strony.</span>
          </label>

          <label class="block" x-show="!['divider'].includes(type)">
            <span class="<?= ui('label') ?>">Rozmiar (układ Bento)</span>
            <select name="size" class="<?= ui('select') ?>">
              <?php foreach (tile_sizes() as $k => $label): ?>
                <option value="<?= e($k) ?>" <?= $tile['size'] === $k ? 'selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
            <span class="<?= ui('hint') ?>">W układzie jednokolumnowym rozmiar nie ma znaczenia.</span>
          </label>
        </div>

        <div class="grid gap-5 sm:grid-cols-2" x-show="type !== 'divider'">
          <label class="block">
            <span class="<?= ui('label') ?>">Tytuł</span>
            <input name="title" value="<?= e($tile['title']) ?>" class="<?= ui('input') ?>" placeholder="np. LinkedIn">
          </label>
          <label class="block">
            <span class="<?= ui('label') ?>">Podtytuł</span>
            <input name="subtitle" value="<?= e($tile['subtitle']) ?>" class="<?= ui('input') ?>" placeholder="np. Zobacz mój profil">
          </label>
        </div>

        <!-- Adres URL -->
        <label class="block" x-show="['link','social'].includes(type)">
          <span class="<?= ui('label') ?>">Adres URL</span>
          <input name="url" value="<?= e($tile['url']) ?>" class="<?= ui('input') ?>"
                 :disabled="!['link','social'].includes(type)"
                 placeholder="https://www.linkedin.com/in/…">
          <span class="<?= ui('hint') ?>">Dozwolone: https://, http://, mailto:, tel:</span>
        </label>

        <!-- E-mail / telefon (opcjonalne nadpisanie ustawień) -->
        <label class="block" x-show="['email','phone'].includes(type)">
          <span class="<?= ui('label') ?>">Adres / numer (puste = z Ustawień)</span>
          <input name="url" value="<?= e($tile['url']) ?>" class="<?= ui('input') ?>"
                 :disabled="!['email','phone'].includes(type)" placeholder="kontakt@example.com">
        </label>

        <!-- Podstrona -->
        <label class="block" x-show="type === 'gallery' || type === 'page'">
          <span class="<?= ui('label') ?>" x-text="type === 'page' ? 'Podstrona' : 'Galeria'"></span>
          <select name="page_id" class="<?= ui('select') ?>" x-show="type === 'page'">
            <option value="0">— wybierz —</option>
            <?php foreach ($pages as $p): ?>
              <option value="<?= (int)$p['id'] ?>" <?= (int)$tile['page_id'] === (int)$p['id'] ? 'selected' : '' ?>>
                <?= e($p['title']) ?><?= (int)$p['is_published'] === 0 ? ' (szkic)' : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
          <select name="gallery_id" class="<?= ui('select') ?>" x-show="type === 'gallery'">
            <option value="0">— wybierz —</option>
            <?php foreach ($galleries as $g): ?>
              <option value="<?= (int)$g['id'] ?>" <?= (int)$tile['gallery_id'] === (int)$g['id'] ? 'selected' : '' ?>>
                <?= e($g['title']) ?> (<?= (int)$g['images_count'] ?> zdj.)
              </option>
            <?php endforeach; ?>
          </select>
        </label>

        <!-- Treść widżetu / embed -->
        <label class="block" x-show="['text','embed'].includes(type)">
          <span class="<?= ui('label') ?>" x-text="type === 'text' ? 'Treść (Markdown)' : 'Kod HTML / embed'"></span>
          <textarea name="body" rows="7" class="<?= ui('textarea') ?>"><?= e($tile['body']) ?></textarea>
          <span class="<?= ui('hint') ?>">
            Widżet tekstowy: Markdown (**pogrubienie**, listy, linki). Embed: wklejony kod jest wstawiany bez zmian —
            używaj tylko zaufanych źródeł.
          </span>
        </label>
      </section>

      <!-- Grafika -->
      <section class="<?= ui('card') ?> space-y-4" x-show="!['divider','heading','social'].includes(type)">
        <h2 class="<?= ui('section_h') ?>">Grafika kafelka</h2>
        <?php if ($tile['image'] !== ''): ?>
          <div class="flex items-center gap-4">
            <img src="<?= e(upload_url((string)$tile['image'])) ?>" alt="" class="h-20 w-32 rounded-lg object-cover">
            <label class="flex items-center gap-2 text-sm text-rose-300">
              <input type="checkbox" name="remove_image" value="1" class="<?= ui('checkbox') ?>"> Usuń obraz
            </label>
          </div>
        <?php endif; ?>
        <label class="block">
          <span class="<?= ui('label') ?>">Wgraj obraz (opcjonalnie)</span>
          <input type="file" name="image" accept="image/*"
                 class="w-full rounded-lg border border-zinc-700 bg-zinc-950 p-2 text-sm file:mr-3 file:rounded file:border-0 file:bg-zinc-800 file:px-3 file:py-1.5 file:text-zinc-200">
          <span class="<?= ui('hint') ?>">JPG / PNG / WEBP / AVIF / GIF / SVG, maks. 8 MB. Obraz staje się tłem kafelka.</span>
        </label>
      </section>
    </div>

    <!-- Kolumna boczna -->
    <div class="space-y-6">

      <section class="<?= ui('card') ?> space-y-4">
        <h2 class="<?= ui('section_h') ?>">Publikacja</h2>
        <label class="flex items-center gap-2.5 text-sm text-zinc-300">
          <input type="checkbox" name="is_active" value="1" <?= (int)$tile['is_active'] === 1 ? 'checked' : '' ?> class="<?= ui('checkbox') ?>">
          Kafelek widoczny na stronie
        </label>
        <label class="flex items-center gap-2.5 text-sm text-zinc-300" x-show="['link','social','embed'].includes(type)">
          <input type="checkbox" name="open_blank" value="1" <?= (int)$tile['open_blank'] === 1 ? 'checked' : '' ?> class="<?= ui('checkbox') ?>">
          Otwieraj w nowej karcie
        </label>
        <label class="block">
          <span class="<?= ui('label') ?>">Pozycja</span>
          <input type="number" name="position" min="1" value="<?= (int)$tile['position'] ?>" class="<?= ui('input') ?>">
          <span class="<?= ui('hint') ?>">Mniejsza liczba = wyżej.</span>
        </label>
      </section>

      <section class="<?= ui('card') ?> space-y-4" x-show="type !== 'divider'">
        <h2 class="<?= ui('section_h') ?>">Ikona</h2>
        <div class="flex items-center gap-3">
          <span class="grid h-11 w-11 place-items-center rounded-lg border border-zinc-800 bg-zinc-900 text-xl text-indigo-400">
            <template x-if="icon.startsWith('bi-')"><i class="bi" :class="icon"></i></template>
            <template x-if="icon && !icon.startsWith('bi-')"><span x-text="icon"></span></template>
            <template x-if="!icon"><i class="bi bi-square text-zinc-600"></i></template>
          </span>
          <input name="icon" x-model="icon" class="<?= ui('input') ?>" placeholder="bi-linkedin lub emoji 🎯">
        </div>
        <div class="max-h-64 space-y-3 overflow-y-auto pr-1">
          <?php foreach (icon_choices() as $group => $icons): ?>
            <div>
              <p class="mb-1.5 text-[11px] uppercase tracking-wider text-zinc-500"><?= e($group) ?></p>
              <div class="flex flex-wrap gap-1.5">
                <?php foreach ($icons as $cls => $label): ?>
                  <button type="button" @click="icon = '<?= e($cls) ?>'" title="<?= e($label) ?>"
                          class="grid h-8 w-8 place-items-center rounded-md border border-zinc-800 text-zinc-300 transition hover:border-indigo-500 hover:text-indigo-300"
                          :class="icon === '<?= e($cls) ?>' ? 'border-indigo-500 text-indigo-300' : ''">
                    <i class="bi <?= e($cls) ?>"></i>
                  </button>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </section>

      <section class="<?= ui('card') ?>" x-show="type !== 'divider'">
        <h2 class="<?= ui('section_h') ?>">Kolor akcentu</h2>
        <div class="mt-3 flex items-center gap-3">
          <input type="color" name="accent" value="<?= e($tile['accent'] !== '' ? $tile['accent'] : setting('color_accent', '#FF724F')) ?>" class="<?= ui('color') ?>">
          <span class="text-xs text-zinc-500">Puste = kolor globalny.</span>
        </div>
      </section>
    </div>
  </div>

  <div class="flex flex-wrap items-center gap-3 border-t border-zinc-800 pt-5">
    <button type="submit" class="<?= ui('btn_primary') ?>">
      <i class="bi bi-check-lg"></i> <?= $isNew ? 'Dodaj kafelek' : 'Zapisz zmiany' ?>
    </button>
    <a href="<?= e(url('/admin/tiles')) ?>" class="<?= ui('btn') ?>">Anuluj</a>

    <?php if (!$isNew): ?>
      <span class="ml-auto"></span>
      <button type="submit" form="tile-delete" class="<?= ui('btn_danger') ?>"><i class="bi bi-trash"></i> Usuń kafelek</button>
    <?php endif; ?>
  </div>
</form>

<?php if (!$isNew): ?>
  <form id="tile-delete" method="post" action="<?= e(url('/admin/tiles/' . $tile['id'] . '/delete')) ?>"
        onsubmit="return confirm('Usunąć ten kafelek? Tej operacji nie można cofnąć.')" class="hidden">
    <?= csrf_field() ?>
  </form>
<?php endif; ?>
