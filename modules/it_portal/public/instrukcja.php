<?php
/**
 * modules/it_portal/public/instrukcja.php — Portal Usług IT: długa instrukcja jako osobna strona z bocznym spisem rozdziałów.
 *
 * Adres: <APP_URL>/uslugi-it/instrukcje/{slug} (reguła w .htaccess). Instrukcja to katalog instrukcje/{slug}/ z plikami:
 *   meta.php   — tablica: title, brand (HTML nazwy w spisie), lead, author, date, pdf (plik obok albo ''), service [slug, nazwa], toc [id => etykieta]
 *   tresc.html — treść (sekcje <section id="…"> zgodne z toc), {BASE} = publiczny adres katalogu (zrzuty img/, PDF)
 * Artykuły w bazie (bloki) nie mają spisu z podświetlaniem rozdziału ani pinezek na zrzutach, stąd osobna strona (2026-11-24).
 * Spis: kotwice, podświetlenie bieżącego rozdziału (aria-current), na wąskim ekranie rozwijany przyciskiem; bez JS spis jest zawsze widoczny.
 * Zrzuty w linkach otwiera lightbox portalu (lightbox.js).
 */
require_once __DIR__ . '/_layout.php';

$slug = (string)($_GET['k'] ?? '');
$dir  = __DIR__ . '/instrukcje/' . $slug;
if (!preg_match('~^[a-z0-9-]{1,80}$~', $slug) || !is_file($dir . '/meta.php') || !is_file($dir . '/tresc.html')) {
    itPortalNotFound('Ta instrukcja nie istnieje albo została wycofana.');
}
$meta = require $dir . '/meta.php';
$base = APP_URL . '/modules/it_portal/public/instrukcje/' . $slug;
$css  = APP_URL . '/modules/it_portal/public/instrukcje/instrukcja.css?v=' . @filemtime(__DIR__ . '/instrukcje/instrukcja.css');
$pdf  = ($meta['pdf'] ?? '') !== '' && is_file($dir . '/' . $meta['pdf']) ? $base . '/' . rawurlencode($meta['pdf']) : '';
$pdfKb = $pdf !== '' ? (int)round(filesize($dir . '/' . $meta['pdf']) / 1024) : 0;
[$svcSlug, $svcName] = $meta['service'] ?? ['', ''];

itPortalPageStart($meta['title'], 'uslugi', $meta['lead']);
?>
<link rel="stylesheet" href="<?= h($css) ?>">
<div class="itpi" id="itpi">
  <aside class="itpi-side" aria-label="Spis rozdziałów">
    <p class="itpi-brand"><?= $meta['brand'] ?></p>
    <p class="itpi-by">Autor: <?= h($meta['author']) ?></p>
    <button class="itpi-toggle" type="button" aria-expanded="false" aria-controls="itpi-spis" hidden>Spis treści</button>
    <nav id="itpi-spis" class="itpi-nav" aria-label="Rozdziały">
      <ol>
        <?php foreach ($meta['toc'] as $id => $label): ?>
        <li><a href="#<?= h($id) ?>"><?= h($label) ?></a></li>
        <?php endforeach; ?>
      </ol>
      <?php if ($pdf !== ''): ?>
      <a class="itpi-pdf" href="<?= h($pdf) ?>" download>Pobierz PDF <span class="itpi-pdf__n">(<?= $pdfKb ?> KB)</span></a>
      <?php endif; ?>
    </nav>
  </aside>

  <div class="itpi-main">
    <?php if ($svcSlug !== ''): ?>
    <p class="itpi-crumb"><a href="<?= h(itPortalUrl('uslugi')) ?>">Usługi</a> › <a href="<?= h(itPortalUrl('uslugi/' . rawurlencode($svcSlug))) ?>"><?= h($svcName) ?></a></p>
    <?php endif; ?>
    <header class="itpi-head">
      <h1><?= h($meta['title']) ?></h1>
      <p class="itpi-lead"><?= h($meta['lead']) ?></p>
      <p class="itpi-meta">Autor: <?= h($meta['author']) ?> · <?= h($meta['date']) ?></p>
    </header>
    <?= str_replace('{BASE}', h($base), (string)file_get_contents($dir . '/tresc.html')) ?>
  </div>
</div>
<script>
(function () {
  var root = document.getElementById('itpi');
  var toggle = root.querySelector('.itpi-toggle');
  var narrow = window.matchMedia('(max-width: 900px)');
  toggle.hidden = false;
  root.classList.add('js');
  toggle.addEventListener('click', function () {
    var open = root.classList.toggle('open');
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
  });
  var links = Array.prototype.slice.call(root.querySelectorAll('.itpi-nav a[href^="#"]'));
  links.forEach(function (a) {
    a.addEventListener('click', function () {
      if (narrow.matches) { root.classList.remove('open'); toggle.setAttribute('aria-expanded', 'false'); }
    });
  });
  var sections = links.map(function (a) { return document.getElementById(a.getAttribute('href').slice(1)); });
  function update() {
    var current = 0;
    sections.forEach(function (s, i) { if (s && s.getBoundingClientRect().top < 120) current = i; });
    links.forEach(function (a, i) {
      a.classList.toggle('active', i === current);
      if (i === current) a.setAttribute('aria-current', 'location'); else a.removeAttribute('aria-current');
    });
  }
  window.addEventListener('scroll', update, { passive: true });
  update();
})();
</script>
<?php
itPortalPageEnd();
