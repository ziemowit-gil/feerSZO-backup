<?php
/**
 * karty30/ti/ext/title.php — karta tytułu: metadane, wydania i zasoby.
 *
 * Przy każdym zasobie stoi albo przycisk, albo POWÓD odmowy. Wyszarzony przycisk
 * bez wyjaśnienia kończy się telefonem do sekretariatu, a zdanie „licencja
 * wygasła 30.06" załatwia sprawę na miejscu.
 */
require_once __DIR__ . '/_boot.php';

$EXT_SUBJECT = ext_require_subject($EXT_SUBJECT);

$title = ext_title_get((int)($_GET['id'] ?? 0));
if (!$title || (empty($title['is_active']) && !$EXT_MANAGE)) {
    http_response_code(404);
    $EXT_TITLE = 'Nie znaleziono'; $EXT_TAB = 'katalog';
    include __DIR__ . '/_head.php';
    echo '<div class="alert alert-warning">Materiał nie istnieje albo został wycofany.</div>';
    include __DIR__ . '/_foot.php';
    exit;
}

$publisher = ext_publisher_get((int)$title['publisher_id']);
$editions  = ext_editions((int)$title['id'], !$EXT_MANAGE);

$EXT_TITLE = $title['title'];
$EXT_TAB   = 'katalog';
include __DIR__ . '/_head.php';
?>

<div class="skin-crumbs mb-3">
  <a href="index.php">Katalog</a> &rsaquo; <strong><?= h($title['title']) ?></strong>
</div>

<h1 class="h4 fw-bold mb-1"><?= h($title['title']) ?></h1>
<?php if ($title['subtitle'] !== ''): ?>
<p class="text-body-secondary mb-1"><?= h($title['subtitle']) ?></p>
<?php endif; ?>
<p class="ext-meta mb-3">
  <?php if ($title['authors'] !== ''): ?><?= h($title['authors']) ?> · <?php endif; ?>
  <?= h($publisher['name'] ?? '') ?>
  <?php if ($title['lang'] !== 'pl'): ?> · język: <?= h($title['lang']) ?><?php endif; ?>
</p>

<?php if ($title['description'] !== ''): ?>
<p class="mb-3" style="white-space:pre-wrap"><?= h($title['description']) ?></p>
<?php endif; ?>

<?php if ($title['rights_note'] !== ''): ?>
<?php /* Zapis z umowy pokazujemy wprost — użytkownik ma wiedzieć, co wolno,
         zanim kliknie, a nie dowiadywać się z komunikatu o odmowie. */ ?>
<div class="alert alert-light border small mb-3">
  <i class="bi bi-shield-check me-1" aria-hidden="true"></i>
  <strong>Zasady korzystania:</strong> <?= h($title['rights_note']) ?>
</div>
<?php endif; ?>

<?php if ($EXT_MANAGE): ?>
<p class="mb-3">
  <a href="upload.php?title=<?= (int)$title['id'] ?>" class="btn btn-sm btn-primary">
    <i class="bi bi-upload me-1" aria-hidden="true"></i>Dodaj plik do tego tytułu
  </a>
  <a href="admin.php?tab=uprawnienia&amp;title=<?= (int)$title['id'] ?>" class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-key me-1" aria-hidden="true"></i>Uprawnienia
  </a>
</p>
<?php endif; ?>

<?php if (!$editions): ?>
<div class="alert alert-info">Ten tytuł nie ma jeszcze wgranych plików.</div>
<?php endif; ?>

<?php foreach ($editions as $ed): $resources = ext_resources((int)$ed['id'], !$EXT_MANAGE); ?>
<section class="card mb-3">
  <div class="card-header">
    <?= h($ed['name']) ?>
    <?php if ($ed['year']): ?><span class="fw-normal">· <?= (int)$ed['year'] ?></span><?php endif; ?>
    <?php if ($ed['isbn'] !== ''): ?><span class="fw-normal">· ISBN <?= h($ed['isbn']) ?></span><?php endif; ?>
  </div>
  <div class="card-body p-0">
    <?php if (!$resources): ?>
    <p class="p-2 mb-0 text-body-secondary small">Brak plików w tym wydaniu.</p>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-sm align-middle mb-0">
        <caption class="visually-hidden">Zasoby wydania <?= h($ed['name']) ?> wraz z dostępnymi czynnościami</caption>
        <thead>
          <tr>
            <th scope="col">Zasób</th>
            <th scope="col">Rozmiar</th>
            <th scope="col">Stron</th>
            <th scope="col">Co możesz zrobić</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($resources as $r):
            $ctx = ext_resource_context((int)$r['id']);
            $dv  = $ctx ? ext_decide($EXT_SUBJECT, $ctx, 'stream')   : ['ok' => false, 'reason' => 'not_found'];
            $dd  = $ctx ? ext_decide($EXT_SUBJECT, $ctx, 'download') : ['ok' => false, 'reason' => 'not_found'];
          ?>
          <tr>
            <th scope="row" class="fw-semibold ext-tree-depth-<?= min(2, (int)$r['depth']) ?>">
              <?= h($r['name']) ?>
              <?php if ($r['orig_name'] !== ''): ?>
              <span class="fw-normal text-body-secondary small d-block"><?= h($r['orig_name']) ?></span>
              <?php endif; ?>
            </th>
            <td class="text-nowrap"><?= $r['size_bytes'] ? h(ext_human_size((int)$r['size_bytes'])) : '—' ?></td>
            <td class="text-nowrap"><?= $r['pages'] ? (int)$r['pages'] : '—' ?></td>
            <td class="text-nowrap">
              <?php if ($r['kind'] === 'link' && $r['url'] !== ''): ?>
                <a href="<?= h($r['url']) ?>" target="_blank" rel="noopener">Otwórz u wydawcy<span class="visually-hidden"> (nowa karta)</span></a>
              <?php elseif ($dv['ok']): ?>
                <form method="post" action="ticket.php" class="d-inline">
                  <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                  <input type="hidden" name="resource_id" value="<?= (int)$r['id'] ?>">
                  <input type="hidden" name="ability" value="stream">
                  <button class="btn btn-sm btn-primary"><i class="bi bi-book me-1" aria-hidden="true"></i>Czytaj</button>
                </form>
                <?php if ($dd['ok']): ?>
                <form method="post" action="ticket.php" class="d-inline">
                  <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                  <input type="hidden" name="resource_id" value="<?= (int)$r['id'] ?>">
                  <input type="hidden" name="ability" value="download">
                  <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-download me-1" aria-hidden="true"></i>Pobierz</button>
                </form>
                <?php endif; ?>
              <?php else: ?>
                <span class="ext-deny"><i class="bi bi-lock me-1" aria-hidden="true"></i><?= h(ext_reason_text($dv['reason'])) ?></span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</section>
<?php endforeach; ?>

<?php include __DIR__ . '/_foot.php'; ?>
