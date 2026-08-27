<?php
/**
 * karty30/ti/ext/index.php — katalog materiałów zewnętrznych.
 *
 * Metadane pokazujemy szerzej niż treść: tytuł, autora i opis widzi każdy
 * zalogowany, plików broni dopiero ext_decide() na karcie tytułu. Dzięki temu
 * kursant wie, że materiał istnieje, i wie, o co poprosić.
 */
require_once __DIR__ . '/_boot.php';

$EXT_SUBJECT = ext_require_subject($EXT_SUBJECT);

$f = [
    'q'            => trim((string)($_GET['q'] ?? '')),
    'category_id'  => (int)($_GET['cat'] ?? 0),
    'publisher_id' => (int)($_GET['pub'] ?? 0),
];
$page   = max(1, (int)($_GET['p'] ?? 1));
$per    = 40;
$titles = ext_titles_visible($EXT_SUBJECT, $f, $per + 1, ($page - 1) * $per);
$more   = count($titles) > $per;
$titles = array_slice($titles, 0, $per);

$cats = ext_categories();
$pubs = ext_publishers();

$EXT_TITLE = 'Katalog';
$EXT_TAB   = 'katalog';
include __DIR__ . '/_head.php';
?>

<?php /* Nazwa modułu stoi w pasku u góry — nagłówek strony mówi, co to za ekran. */ ?>
<h1 class="h4 fw-bold mb-1"><i class="bi bi-book text-primary me-2" aria-hidden="true"></i>Katalog</h1>
<p class="text-body-secondary small mb-3">
  Książki, e-booki i dokumenty udostępnione przez wydawnictwa. Dostęp zależy od licencji —
  przy każdej pozycji widać, co możesz z nią zrobić.
</p>

<?php
// Kursant najczęściej szuka nie „czegoś w katalogu", tylko lektury do zajęć,
// które właśnie miał — dlatego przypięcia stoją nad wyszukiwarką.
$myPins = ($EXT_SUBJECT['type'] === 'student' && !empty($EXT_SUBJECT['client_id']))
    ? ext_pins_for_client((int)$EXT_SUBJECT['client_id'], 10) : [];
if ($myPins): ?>
<section class="card mb-3" aria-labelledby="ext-pins-h">
  <div class="card-header" id="ext-pins-h">Materiały do Twoich zajęć</div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-sm align-middle mb-0">
        <caption class="visually-hidden">Materiały przypięte przez prowadzącego do Twoich lekcji</caption>
        <thead><tr><th scope="col">Lekcja</th><th scope="col">Materiał</th><th scope="col">Notatka</th></tr></thead>
        <tbody>
          <?php foreach ($myPins as $pn): ?>
          <tr>
            <td class="small text-nowrap">
              <time datetime="<?= h((string)$pn['lesson_date']) ?>"><?= h((string)$pn['lesson_date']) ?></time>
              <span class="d-block text-body-secondary"><?= h($pn['course_name']) ?></span>
            </td>
            <th scope="row" class="fw-semibold">
              <a href="title.php?id=<?= (int)$pn['title_id'] ?>"><?= h($pn['title_name']) ?></a>
              <span class="fw-normal text-body-secondary d-block small"><?= h($pn['res_name']) ?></span>
            </th>
            <td class="small"><?= h($pn['note']) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</section>
<?php endif; ?>

<form method="get" class="row g-2 align-items-end mb-3">
  <div class="col-12 col-md-4">
    <label class="form-label" for="ext-q">Szukaj</label>
    <input type="search" class="form-control" id="ext-q" name="q" value="<?= h($f['q']) ?>"
           placeholder="tytuł, autor, opis">
  </div>
  <div class="col-6 col-md-3">
    <label class="form-label" for="ext-cat">Kategoria</label>
    <select class="form-select" id="ext-cat" name="cat">
      <option value="0">— wszystkie —</option>
      <?php foreach ($cats as $c): ?>
      <option value="<?= (int)$c['id'] ?>" <?= $f['category_id'] === (int)$c['id'] ? 'selected' : '' ?>>
        <?= str_repeat('· ', (int)$c['depth']) . h($c['name']) ?>
      </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-6 col-md-3">
    <label class="form-label" for="ext-pub">Wydawca</label>
    <select class="form-select" id="ext-pub" name="pub">
      <option value="0">— wszyscy —</option>
      <?php foreach ($pubs as $p): ?>
      <option value="<?= (int)$p['id'] ?>" <?= $f['publisher_id'] === (int)$p['id'] ? 'selected' : '' ?>><?= h($p['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-12 col-md-2">
    <button class="btn btn-primary w-100"><i class="bi bi-search me-1" aria-hidden="true"></i>Szukaj</button>
  </div>
</form>

<?php if (!$titles): ?>
<div class="alert alert-info">
  <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
  <?= $f['q'] !== '' || $f['category_id'] || $f['publisher_id']
      ? 'Nic nie pasuje do tych warunków.'
      : 'W katalogu nie ma jeszcze żadnych materiałów.' ?>
</div>
<?php else: ?>

<p class="small text-body-secondary">Pozycji na tej stronie: <strong><?= count($titles) ?></strong></p>

<div class="ext-grid mb-3">
  <?php foreach ($titles as $t): ?>
  <article class="ext-card">
    <h3><a href="title.php?id=<?= (int)$t['id'] ?>"><?= h($t['title']) ?></a></h3>
    <?php if ($t['authors'] !== ''): ?><div class="ext-meta"><?= h($t['authors']) ?></div><?php endif; ?>
    <div class="ext-meta">
      <?= h($t['publisher_name']) ?>
      <?php if ((int)$t['n_editions'] > 1): ?> · <?= (int)$t['n_editions'] ?> wydania<?php endif; ?>
    </div>
    <div class="mt-1">
      <?php
        // Poziom dostępu podajemy słowem — kolor sam z siebie niczego nie mówi
        $lvl = ['public' => ['Otwarty', 'success'], 'registered' => ['Dla zalogowanych', 'secondary'],
                'licensed' => ['Na licencji', 'warning'], 'restricted' => ['Ograniczony', 'danger']];
        [$lbl, $var] = $lvl[$t['access_level']] ?? ['Na licencji', 'warning'];
      ?>
      <span class="badge text-bg-<?= h($var) ?>"><span class="visually-hidden">Dostęp: </span><?= h($lbl) ?></span>
      <?php if (!empty($t['embargo_until']) && $t['embargo_until'] > date('Y-m-d H:i:s')): ?>
      <span class="badge text-bg-light border text-dark">embargo do <?= h(substr($t['embargo_until'], 0, 10)) ?></span>
      <?php endif; ?>
    </div>
  </article>
  <?php endforeach; ?>
</div>

<nav aria-label="Strony katalogu" class="d-flex gap-2">
  <?php $qs = fn(int $pp) => 'index.php?' . http_build_query(array_filter([
      'q' => $f['q'], 'cat' => $f['category_id'], 'pub' => $f['publisher_id'], 'p' => $pp])); ?>
  <?php if ($page > 1): ?><a class="btn btn-sm btn-outline-secondary" href="<?= h($qs($page - 1)) ?>">← Poprzednia</a><?php endif; ?>
  <?php if ($more):     ?><a class="btn btn-sm btn-outline-secondary" href="<?= h($qs($page + 1)) ?>">Następna →</a><?php endif; ?>
</nav>

<?php endif; ?>

<?php include __DIR__ . '/_foot.php'; ?>
