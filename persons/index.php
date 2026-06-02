<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/persons.php';

require_role('admin', 'editor');
$PAGE_TITLE = 'Strony umów';

$q = trim($_GET['q'] ?? '');
if ($q !== '') {
    $persons = persons_search($q);
} else {
    $persons = persons_all();
}

// Stats
try { $total = db_one("SELECT COUNT(*) AS c FROM persons"); $total = (int)($total['c'] ?? 0); } catch(\Throwable $e) { $total = 0; }

include dirname(__DIR__) . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0"><i class="bi bi-person-lines-fill text-primary"></i> Strony umów</h4>
  <a href="add.php" class="btn btn-primary btn-sm"><i class="bi bi-person-plus"></i> Dodaj osobę</a>
</div>

<div class="row g-3 mb-3">
  <div class="col-md-3">
    <div class="card shadow-sm text-center">
      <div class="card-body py-3">
        <div class="fs-3 fw-bold text-primary"><?= $total ?></div>
        <div class="text-muted small">Osób w bazie</div>
      </div>
    </div>
  </div>
</div>

<div class="card shadow-sm mb-3">
  <div class="card-body py-2">
    <form method="get" class="d-flex gap-2">
      <input type="text" name="q" class="form-control" placeholder="Szukaj po nazwisku, PESEL lub email…" value="<?= h($q) ?>">
      <button type="submit" class="btn btn-outline-primary"><i class="bi bi-search"></i></button>
      <?php if ($q): ?><a href="index.php" class="btn btn-outline-secondary">Wyczyść</a><?php endif; ?>
    </form>
  </div>
</div>

<?php if ($persons): ?>
<div class="card shadow-sm">
<div class="table-responsive">
<table class="table table-hover table-sm align-middle mb-0">
  <thead class="table-light">
    <tr>
      <th>Imię i nazwisko</th>
      <th>PESEL</th>
      <th>E-mail / Telefon</th>
      <th class="text-center">Kwestionariusz</th>
      <th>Dodano</th>
      <th></th>
    </tr>
  </thead>
  <tbody>
  <?php
  $q_icons = [
      'filled'    => '<span class="badge bg-success"><i class="bi bi-patch-check-fill me-1"></i>Wypełniony</span>',
      'sent'      => '<span class="badge bg-primary"><i class="bi bi-send me-1"></i>Wysłany</span>',
      'generated' => '<span class="badge bg-warning text-dark"><i class="bi bi-link-45deg me-1"></i>Link</span>',
      'none'      => '<span class="text-muted small">—</span>',
  ];
  foreach ($persons as $p): ?>
  <tr>
    <td>
      <a href="view.php?id=<?= $p['id'] ?>" class="fw-semibold text-decoration-none">
        <?= h($p['imie_nazwisko']) ?>
      </a>
    </td>
    <td class="font-monospace small"><?= h($p['pesel']) ?: '—' ?></td>
    <td>
      <?= $p['email'] ? '<div class="small"><a href="mailto:'.h($p['email']).'">'.h($p['email']).'</a></div>' : '' ?>
      <?= $p['telefon'] ? '<div class="small text-muted">'.h($p['telefon']).'</div>' : (!$p['email'] ? '—' : '') ?>
    </td>
    <td class="text-center"><?= $q_icons[questionnaire_status($p)] ?></td>
    <td class="small text-muted"><?= date_pl($p['created_at']) ?></td>
    <td class="text-end text-nowrap">
      <a href="view.php?id=<?= $p['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye"></i></a>
      <a href="edit.php?id=<?= $p['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></a>
      <?php if (is_admin()): ?>
      <?= delete_btn('persons', (int)$p['id'], $p['imie_nazwisko'] ?? '#'.$p['id']) ?>
      <?php endif; ?>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
</div>
<?php elseif ($q): ?>
<div class="alert alert-info">Nie znaleziono osób pasujących do "<strong><?= h($q) ?></strong>".
  <a href="add.php">Dodaj nową osobę</a>.
</div>
<?php else: ?>
<div class="alert alert-secondary">Brak osób w bazie. <a href="add.php">Dodaj pierwszą osobę</a>.</div>
<?php endif; ?>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
