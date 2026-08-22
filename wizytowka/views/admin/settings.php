<?php
/**
 * settings.php — ustawienia strony w zakładkach (profil, kontakt, wygląd, SEO).
 * Zmienne: $presets
 */
$tabs = [
    'profil'  => ['Profil',   'bi-person-fill'],
    'kontakt' => ['Kontakt',  'bi-telephone-fill'],
    'wyglad'  => ['Wygląd',   'bi-palette-fill'],
    'seo'     => ['SEO i stopka', 'bi-search'],
];
$colors = [
    'color_bg'       => ['Tło strony', '#F5F5F5'],
    'color_accent'   => ['Akcent (ikony, linki)', '#FF724F'],
    'color_accent2'  => ['Akcent — hover', '#FF8466'],
    'color_text'     => ['Tekst', '#D67F69'],
    'color_heading'  => ['Nagłówki', '#D67F69'],
    'color_btn_bg'   => ['Tło przycisków / kart', '#FFFFFF'],
    'color_btn_text' => ['Tekst przycisków', '#1A4757'],
    'color_rule'     => ['Linie rozdzielające', '#D67F69'],
];
?>
<div x-data="{ tab: window.location.hash.replace('#','') || 'profil' }">

  <!-- Zakładki -->
  <nav class="mb-5 flex flex-wrap gap-1.5 rounded-xl border border-zinc-800 bg-zinc-900/60 p-1.5">
    <?php foreach ($tabs as $key => [$label, $icon]): ?>
      <button type="button" @click="tab='<?= e($key) ?>'; history.replaceState(null,'','#<?= e($key) ?>')"
              class="inline-flex items-center gap-2 rounded-lg px-3.5 py-2 text-sm transition"
              :class="tab === '<?= e($key) ?>' ? 'bg-indigo-500/15 text-indigo-300' : 'text-zinc-400 hover:bg-zinc-800 hover:text-zinc-100'">
        <i class="bi <?= e($icon) ?>"></i> <?= e($label) ?>
      </button>
    <?php endforeach; ?>
  </nav>

  <form method="post" enctype="multipart/form-data" class="space-y-6">
    <?= csrf_field() ?>

    <!-- ── PROFIL ──────────────────────────────────────────────────── -->
    <div x-show="tab==='profil'" class="space-y-6">
      <section class="<?= ui('card') ?> space-y-5">
        <h2 class="<?= ui('section_h') ?>">Nazwa i opis</h2>
        <div class="grid gap-5 sm:grid-cols-2">
          <label class="block">
            <span class="<?= ui('label') ?>">Nazwa strony <span class="text-rose-400">*</span></span>
            <input name="site_name" required value="<?= e(setting('site_name')) ?>" class="<?= ui('input') ?>">
          </label>
          <label class="block">
            <span class="<?= ui('label') ?>">Imię i nazwisko</span>
            <input name="owner_name" value="<?= e(setting('owner_name')) ?>" class="<?= ui('input') ?>">
          </label>
          <label class="block">
            <span class="<?= ui('label') ?>">Część nazwiska pogrubiona (opcjonalnie)</span>
            <input name="owner_name_bold" value="<?= e(setting('owner_name_bold')) ?>" class="<?= ui('input') ?>" placeholder="np. Bazan">
            <span class="<?= ui('hint') ?>">Wtedy w nagłówku: „Alicja <strong>Bazan</strong>”.</span>
          </label>
          <label class="block">
            <span class="<?= ui('label') ?>">Tytuł / rola</span>
            <input name="tagline" value="<?= e(setting('tagline')) ?>" class="<?= ui('input') ?>"
                   placeholder="Szkoleniowczyni, edukatorka, mówczyni">
          </label>
          <label class="block sm:col-span-2">
            <span class="<?= ui('label') ?>">Wyróżnienie nad opisem</span>
            <input name="headline" value="<?= e(setting('headline')) ?>" class="<?= ui('input') ?>" placeholder="np. Młoda Polka 2024">
          </label>
        </div>
        <label class="block">
          <span class="<?= ui('label') ?>">Bio (Markdown)</span>
          <textarea name="bio" rows="6" class="<?= ui('textarea') ?>"><?= e(setting('bio')) ?></textarea>
        </label>
        <div class="grid gap-5 sm:grid-cols-2">
          <label class="block">
            <span class="<?= ui('label') ?>">Stanowisko (vCard)</span>
            <input name="job_title" value="<?= e(setting('job_title')) ?>" class="<?= ui('input') ?>">
          </label>
          <label class="block">
            <span class="<?= ui('label') ?>">Firma / organizacja (vCard)</span>
            <input name="company" value="<?= e(setting('company')) ?>" class="<?= ui('input') ?>">
          </label>
        </div>
      </section>

      <section class="<?= ui('card') ?> space-y-5">
        <h2 class="<?= ui('section_h') ?>">Zdjęcie / avatar</h2>
        <div class="flex flex-wrap items-start gap-5">
          <?php if (setting('avatar') !== ''): ?>
            <img src="<?= e(upload_url(setting('avatar'))) ?>" alt="" class="h-28 w-28 rounded-full object-cover ring-2 ring-zinc-800">
          <?php else: ?>
            <span class="grid h-28 w-28 place-items-center rounded-full border border-dashed border-zinc-700 text-zinc-600"><i class="bi bi-person text-3xl"></i></span>
          <?php endif; ?>
          <div class="flex-1 space-y-3">
            <input type="file" name="avatar" accept="image/*"
                   class="w-full rounded-lg border border-zinc-700 bg-zinc-950 p-2 text-sm file:mr-3 file:rounded file:border-0 file:bg-zinc-800 file:px-3 file:py-1.5 file:text-zinc-200">
            <?php if (setting('avatar') !== ''): ?>
              <label class="flex items-center gap-2 text-sm text-rose-300">
                <input type="checkbox" name="remove_avatar" value="1" class="<?= ui('checkbox') ?>"> Usuń zdjęcie
              </label>
            <?php endif; ?>
            <label class="block max-w-xs">
              <span class="<?= ui('label') ?>">Kształt</span>
              <select name="avatar_shape" class="<?= ui('select') ?>">
                <?php foreach (['circle' => 'Koło', 'rounded' => 'Zaokrąglony prostokąt', 'square' => 'Kwadrat'] as $k => $l): ?>
                  <option value="<?= e($k) ?>" <?= setting('avatar_shape', 'circle') === $k ? 'selected' : '' ?>><?= e($l) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
          </div>
        </div>
      </section>
    </div>

    <!-- ── KONTAKT ─────────────────────────────────────────────────── -->
    <div x-show="tab==='kontakt'" class="space-y-6">
      <section class="<?= ui('card') ?> space-y-5">
        <h2 class="<?= ui('section_h') ?>">Dane kontaktowe</h2>
        <div class="grid gap-5 sm:grid-cols-2">
          <label class="block">
            <span class="<?= ui('label') ?>">E-mail</span>
            <input type="email" name="email" value="<?= e(setting('email')) ?>" class="<?= ui('input') ?>">
          </label>
          <label class="block">
            <span class="<?= ui('label') ?>">Telefon</span>
            <input name="phone" value="<?= e(setting('phone')) ?>" class="<?= ui('input') ?>" placeholder="+48 600 000 000">
          </label>
          <label class="block">
            <span class="<?= ui('label') ?>">Strona www</span>
            <input name="website" value="<?= e(setting('website')) ?>" class="<?= ui('input') ?>" placeholder="https://…">
          </label>
          <label class="block">
            <span class="<?= ui('label') ?>">Miasto / lokalizacja</span>
            <input name="location" value="<?= e(setting('location')) ?>" class="<?= ui('input') ?>" placeholder="Katowice">
          </label>
        </div>
      </section>

      <section class="<?= ui('card') ?> space-y-5">
        <h2 class="<?= ui('section_h') ?>">Wizytówka vCard (.vcf)</h2>
        <label class="flex items-center gap-2.5 text-sm text-zinc-300">
          <input type="checkbox" name="vcard_enabled" value="1" <?= setting_bool('vcard_enabled', true) ? 'checked' : '' ?> class="<?= ui('checkbox') ?>">
          Pokaż przycisk pobierania vCard
        </label>
        <label class="block max-w-md">
          <span class="<?= ui('label') ?>">Napis na przycisku</span>
          <input name="vcard_label" value="<?= e(setting('vcard_label', 'Zapisz kontakt (vCard)')) ?>" class="<?= ui('input') ?>">
        </label>
        <p class="text-xs text-zinc-500">
          Plik generowany z powyższych danych:
          <a href="<?= e(url('/vcard.vcf')) ?>" class="text-indigo-400 hover:underline">sprawdź podgląd</a>
        </p>
      </section>
    </div>

    <!-- ── WYGLĄD ──────────────────────────────────────────────────── -->
    <div x-show="tab==='wyglad'" class="space-y-6">
      <section class="<?= ui('card') ?> space-y-5">
        <h2 class="<?= ui('section_h') ?>">Układ strony głównej</h2>
        <div class="grid gap-3 sm:grid-cols-3">
          <?php foreach ([
              'stack' => ['Jedna kolumna', 'Wyśrodkowana wizytówka: nagłówek, przyciski-pigułki, sekcje z liniami.'],
              'split' => ['Wizytówka z boku', 'Dane kontaktowe przyklejone w lewej kolumnie, treść w dwóch kolumnach obok.'],
              'bento' => ['Siatka Bento',  'Kafelki w siatce 4 kolumn, rozmiary 1×1 … 2×2, efekt szkła.'],
          ] as $k => [$l, $d]): ?>
            <label class="cursor-pointer">
              <input type="radio" name="site_layout" value="<?= e($k) ?>" class="peer sr-only"
                     <?= setting('site_layout', 'stack') === $k ? 'checked' : '' ?>>
              <span class="block rounded-xl border border-zinc-700 p-4 transition peer-checked:border-indigo-500 peer-checked:bg-indigo-500/10">
                <span class="block text-sm font-medium text-zinc-100"><?= e($l) ?></span>
                <span class="mt-1 block text-xs text-zinc-500"><?= e($d) ?></span>
              </span>
            </label>
          <?php endforeach; ?>
        </div>

        <div class="grid gap-5 sm:grid-cols-2">
          <label class="block">
            <span class="<?= ui('label') ?>">Krój pisma (Google Fonts)</span>
            <input name="font_family" value="<?= e(setting('font_family', 'Inter')) ?>" class="<?= ui('input') ?>" placeholder="Inter">
            <span class="<?= ui('hint') ?>">Np. Inter, Manrope, Poppins, Lora, Space Grotesk.</span>
          </label>
          <label class="mt-1 flex items-center gap-2.5 text-sm text-zinc-300">
            <input type="checkbox" name="bg_pattern" value="1" <?= setting_bool('bg_pattern', true) ? 'checked' : '' ?> class="<?= ui('checkbox') ?>">
            Animowane tło (delikatne kwadraty w kolorze akcentu)
          </label>
        </div>
      </section>

      <section class="<?= ui('card') ?>">
        <h2 class="<?= ui('section_h') ?>">Kolory</h2>
        <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <?php foreach ($colors as $key => [$label, $default]): $val = setting($key, $default); ?>
            <label class="block">
              <span class="<?= ui('label') ?>"><?= e($label) ?></span>
              <span class="flex items-center gap-2">
                <input type="color" value="<?= e($val) ?>" class="<?= ui('color') ?>"
                       oninput="this.nextElementSibling.value = this.value.toUpperCase()">
                <input name="<?= e($key) ?>" value="<?= e($val) ?>" class="<?= ui('input') ?> font-mono text-xs"
                       oninput="this.previousElementSibling.value = this.value">
              </span>
            </label>
          <?php endforeach; ?>
        </div>
      </section>
    </div>

    <!-- ── SEO ─────────────────────────────────────────────────────── -->
    <div x-show="tab==='seo'" class="space-y-6">
      <section class="<?= ui('card') ?> space-y-5">
        <h2 class="<?= ui('section_h') ?>">Meta dane strony głównej</h2>
        <label class="block">
          <span class="<?= ui('label') ?>">Meta title</span>
          <input name="meta_title" value="<?= e(setting('meta_title')) ?>" class="<?= ui('input') ?>" maxlength="70">
        </label>
        <label class="block">
          <span class="<?= ui('label') ?>">Meta description</span>
          <textarea name="meta_description" rows="3" class="<?= ui('input') ?> font-sans" maxlength="200"><?= e(setting('meta_description')) ?></textarea>
        </label>
        <label class="block">
          <span class="<?= ui('label') ?>">Słowa kluczowe</span>
          <input name="meta_keywords" value="<?= e(setting('meta_keywords')) ?>" class="<?= ui('input') ?>">
        </label>
        <div class="flex flex-wrap items-start gap-5">
          <?php if (setting('og_image') !== ''): ?>
            <img src="<?= e(upload_url(setting('og_image'))) ?>" alt="" class="h-24 w-40 rounded-lg object-cover">
          <?php endif; ?>
          <label class="flex-1">
            <span class="<?= ui('label') ?>">Obraz do udostępnień (Open Graph, 1200×630)</span>
            <input type="file" name="og_image" accept="image/*"
                   class="w-full rounded-lg border border-zinc-700 bg-zinc-950 p-2 text-sm file:mr-3 file:rounded file:border-0 file:bg-zinc-800 file:px-3 file:py-1.5 file:text-zinc-200">
            <?php if (setting('og_image') !== ''): ?>
              <span class="mt-2 flex items-center gap-2 text-sm text-rose-300">
                <input type="checkbox" name="remove_og_image" value="1" class="<?= ui('checkbox') ?>"> Usuń obraz
              </span>
            <?php endif; ?>
          </label>
        </div>
      </section>

      <section class="<?= ui('card') ?> space-y-5">
        <h2 class="<?= ui('section_h') ?>">Stopka i statystyki</h2>
        <label class="block">
          <span class="<?= ui('label') ?>">Tekst w stopce</span>
          <input name="footer_text" value="<?= e(setting('footer_text')) ?>" class="<?= ui('input') ?>"
                 placeholder="© <?= date('Y') ?> …">
        </label>
        <label class="block">
          <span class="<?= ui('label') ?>">Kod analityki (wstawiany w &lt;head&gt;)</span>
          <textarea name="analytics_code" rows="4" class="<?= ui('textarea') ?>"><?= e(setting('analytics_code')) ?></textarea>
          <span class="<?= ui('hint') ?>">Wklejany bez zmian — używaj tylko własnych, zaufanych skryptów.</span>
        </label>
      </section>
    </div>

    <div class="sticky bottom-4 flex flex-wrap items-center gap-3">
      <button type="submit" class="<?= ui('btn_primary') ?> shadow-lg shadow-black/40"><i class="bi bi-check-lg"></i> Zapisz ustawienia</button>
      <a href="<?= e(url('/')) ?>" target="_blank" rel="noopener" class="<?= ui('btn') ?>"><i class="bi bi-box-arrow-up-right"></i> Podejrzyj stronę</a>
    </div>
  </form>

  <!-- Presety kolorów (osobne formularze, żeby nie mieszać z zapisem ustawień) -->
  <section class="<?= ui('card') ?> mt-6" x-show="tab==='wyglad'">
    <h2 class="<?= ui('section_h') ?>">Gotowe presety</h2>
    <p class="<?= ui('hint') ?> mb-4">Zastąpią obecne kolory i układ. Zmiany możesz potem dopracować ręcznie.</p>
    <div class="grid gap-3 sm:grid-cols-3">
      <?php foreach ($presets as $key => $p): ?>
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="apply_preset" value="<?= e($key) ?>">
          <button class="w-full rounded-xl border border-zinc-700 p-4 text-left transition hover:border-indigo-500">
            <span class="flex gap-1.5">
              <?php foreach (['color_bg', 'color_accent', 'color_text', 'color_btn_bg'] as $c): ?>
                <span class="h-5 w-5 rounded-full border border-black/20" style="background:<?= e($p[$c]) ?>"></span>
              <?php endforeach; ?>
            </span>
            <span class="mt-3 block text-sm font-medium text-zinc-100"><?= e($p['label']) ?></span>
          </button>
        </form>
      <?php endforeach; ?>
    </div>
  </section>
</div>
