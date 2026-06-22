<?php
/**
 * karty30/ti/curriculum.php — Plan nauczania (program / sylabus) kursu TI.
 *
 * Wzorzec master-detail (zgodnie z grades.php): lista pozycji planu po lewej,
 * edytor pojedynczej pozycji + import po prawej. Bez modali — pełna obsługa
 * klawiaturą, aria-live dla komunikatów importu/zapisu, kolejność zmieniana
 * dostępnymi przyciskami (w górę/w dół), a nie drag&drop.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/karty30.php';

k30_require_access();
karty30_migrate();

$can_write  = can_write('karty30') || is_admin();
$can_delete = is_admin();
$PAGE_TITLE = 'Plan nauczania — TI';

$course_id  = (int)($_GET['course'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_write) {
    csrf_check();
    $op  = $_POST['_op'] ?? '';
    $cid = (int)($_POST['course_id'] ?? 0);

    if ($op === 'save_item') {
        $iid   = (int)($_POST['item_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        if (!$cid || !k30_ti_course_get($cid)) { flash_set('danger','Wybierz kurs.'); header('Location: curriculum.php'); exit; }
        if ($title === '') { flash_set('danger','Podaj temat pozycji planu.'); header('Location: curriculum.php?course='.$cid); exit; }
        k30_ti_curriculum_save([
            'course_id'   => $cid,
            'section'     => $_POST['section'] ?? '',
            'title'       => $title,
            'description' => $_POST['description'] ?? '',
            'est_minutes' => $_POST['est_minutes'] ?? 0,
            'is_active'   => isset($_POST['is_active']) ? 1 : 0,
        ], $iid ?: null, current_user()['id'] ?? null);
        flash_set('success', $iid ? 'Pozycja planu zaktualizowana.' : 'Pozycja planu dodana.');
        header('Location: curriculum.php?course='.$cid); exit;
    }

    if ($op === 'delete_item') {
        if (!$can_delete) { http_response_code(403); die('Brak uprawnień.'); }
        $iid = (int)($_POST['item_id'] ?? 0);
        if ($iid) { k30_ti_curriculum_delete($iid); flash_set('success','Pozycja planu usunięta.'); }
        header('Location: curriculum.php?course='.$cid); exit;
    }

    if ($op === 'move_item') {
        $iid = (int)($_POST['item_id'] ?? 0);
        $dir = ($_POST['dir'] ?? '') === 'up' ? 'up' : 'down';
        $items = k30_ti_curriculum_list($cid);
        $ids   = array_map(fn($r)=>(int)$r['id'], $items);
        $pos   = array_search($iid, $ids, true);
        if ($pos !== false) {
            $swap = $dir === 'up' ? $pos - 1 : $pos + 1;
            if ($swap >= 0 && $swap < count($ids)) {
                [$ids[$pos], $ids[$swap]] = [$ids[$swap], $ids[$pos]];
                k30_ti_curriculum_reorder($cid, $ids);
            }
        }
        header('Location: curriculum.php?course='.$cid.'#item-'.$iid); exit;
    }

    if ($op === 'import_csv') {
        if (!$cid || !k30_ti_course_get($cid)) { flash_set('danger','Wybierz kurs.'); header('Location: curriculum.php'); exit; }
        $raw = '';
        if (!empty($_FILES['csv_file']['tmp_name']) && is_uploaded_file($_FILES['csv_file']['tmp_name'])) {
            $raw = (string)file_get_contents($_FILES['csv_file']['tmp_name']);
        } elseif (trim($_POST['csv_text'] ?? '') !== '') {
            $raw = (string)$_POST['csv_text'];
        }
        if (trim($raw) === '') {
            flash_set('danger','Wgraj plik CSV lub wklej dane do importu.');
            header('Location: curriculum.php?course='.$cid); exit;
        }
        $res = k30_ti_curriculum_import_csv($cid, $raw, current_user()['id'] ?? null);
        // Raport importu przekazany przez sesję (PRG) — wyświetlony w aria-live.
        $_SESSION['k30_curr_import'] = $res;
        header('Location: curriculum.php?course='.$cid.'#import'); exit;
    }
}

$courses = k30_ti_courses(false);
$course  = $course_id ? k30_ti_course_get($course_id) : null;
$items   = $course ? k30_ti_curriculum_list($course_id) : [];

// Mapa: pozycja planu → lekcje, które ją realizują
$item_lessons = [];
if ($course && $items) {
    $rows = db_all(
        "SELECT sc.curriculum_id, s.id AS session_id, s.lesson_date, s.topic
         FROM k30_ti_session_curriculum sc
         JOIN k30_ti_sessions s ON s.id=sc.session_id
         WHERE s.course_id=?
         ORDER BY s.lesson_date",
        [$course_id]
    );
    foreach ($rows as $r) { $item_lessons[(int)$r['curriculum_id']][] = $r; }
}

// Statystyka
$total_min = 0; $n_active = 0;
foreach ($items as $it) { $total_min += (int)$it['est_minutes']; if ($it['is_active']) $n_active++; }

// Edycja pozycji
$edit_id  = (int)($_GET['edit'] ?? 0);
$edit_row = $edit_id ? k30_ti_curriculum_get($edit_id) : null;
$f = $edit_row ?: ['id'=>0,'section'=>'','title'=>'','description'=>'','est_minutes'=>0,'is_active'=>1];

// Raport importu (jednorazowy)
$import = $_SESSION['k30_curr_import'] ?? null;
unset($_SESSION['k30_curr_import']);

include dirname(dirname(__DIR__)) . '/karty30/includes/header_k30.php';
?>

<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/karty30/index.php">Karty 30</a></li>
  <li class="breadcrumb-item"><a href="index.php">Zajęcia TI</a></li>
  <li class="breadcrumb-item active">Plan nauczania</li>
</ol></nav>

<div class="d-flex align-items-center mb-3 gap-2 flex-wrap">
  <h4 class="mb-0 fw-bold"><i class="bi bi-list-check text-primary me-2"></i>Plan nauczania</h4>
  <a href="grades.php" class="btn btn-outline-secondary btn-sm ms-auto"><i class="bi bi-table me-1"></i>Dziennik ocen</a>
  <a href="materials.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-collection-play me-1"></i>Materiały</a>
</div>

<?= flash_html() ?>

<!-- Komunikaty importu / zapisu — ogłaszane przez czytnik ekranu -->
<div aria-live="polite" aria-atomic="true" id="import-status">
<?php if ($import): ?>
  <?php $err = $import['errors'] ?? []; $added = (int)($import['added'] ?? 0); ?>
  <div class="alert <?= $err ? 'alert-warning' : 'alert-success' ?>" id="import" tabindex="-1" role="status">
    <div class="fw-semibold mb-1">
      <i class="bi bi-<?= $err ? 'exclamation-triangle' : 'check-circle' ?> me-1" aria-hidden="true"></i>
      Import zakończony: dodano <?= $added ?> <?= $added===1?'pozycję':'pozycji' ?> planu.
    </div>
    <?php if ($err): ?>
    <div class="mb-1">Pominięto <?= count($err) ?> <?= count($err)===1?'wiersz':'wierszy' ?> z błędami formatu:</div>
    <ul class="mb-0 small">
      <?php foreach (array_slice($err, 0, 50) as $e): ?>
      <li>Wiersz <?= (int)$e['line'] ?>: <?= h($e['msg']) ?></li>
      <?php endforeach; ?>
      <?php if (count($err) > 50): ?><li>… i <?= count($err)-50 ?> kolejnych.</li><?php endif; ?>
    </ul>
    <?php endif; ?>
  </div>
<?php endif; ?>
</div>

<form method="get" class="card border-0 shadow-sm mb-4">
  <div class="card-body d-flex align-items-end gap-2 flex-wrap">
    <div>
      <label class="form-label fw-semibold mb-1" for="course-select">Kurs / grupa</label>
      <select class="form-select" name="course" id="course-select" onchange="this.form.submit()" style="min-width:260px">
        <option value="">— wybierz kurs —</option>
        <?php foreach ($courses as $c): ?>
        <option value="<?= (int)$c['id'] ?>" <?= $course_id===(int)$c['id']?'selected':'' ?>><?= h($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <noscript><button class="btn btn-primary">Pokaż</button></noscript>
  </div>
</form>

<?php if (!$course): ?>
<div class="alert alert-info"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>Wybierz kurs, aby zarządzać planem nauczania.</div>
<?php else: ?>

<div class="row g-4">
  <!-- LISTA pozycji planu (master) -->
  <div class="col-lg-8">
    <div class="card border-0 shadow-sm">
      <div class="card-header fw-semibold d-flex align-items-center flex-wrap gap-2">
        <span><i class="bi bi-list-check me-2" aria-hidden="true"></i><?= h($course['name']) ?></span>
        <span class="badge bg-secondary"><?= count($items) ?> pozycji</span>
        <?php if ($total_min > 0): ?><span class="badge bg-light text-dark border">≈ <?= (int)round($total_min/60) ?> h planu</span><?php endif; ?>
      </div>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <caption class="visually-hidden">Pozycje planu nauczania kursu <?= h($course['name']) ?>, uporządkowane wg działów i kolejności</caption>
          <thead class="table-light"><tr>
            <th scope="col" style="width:2.5rem">#</th>
            <th scope="col">Temat / punkt planu</th>
            <th scope="col" class="text-nowrap">Czas</th>
            <th scope="col">Lekcje</th>
            <?php if ($can_write): ?><th scope="col" class="text-end">Akcje</th><?php endif; ?>
          </tr></thead>
          <tbody>
            <?php if (!$items): ?><tr><td colspan="5" class="text-center text-muted py-3">Brak pozycji w planie. Dodaj pierwszą po prawej lub zaimportuj z CSV.</td></tr><?php endif; ?>
            <?php $last_section = null; $rownum = 0; foreach ($items as $idx => $it):
              $sec = (string)$it['section'];
              if ($sec !== $last_section):
                $last_section = $sec; ?>
                <tr class="table-light"><th colspan="5" scope="colgroup" class="small text-uppercase fw-bold text-secondary py-1">
                  <i class="bi bi-folder2 me-1" aria-hidden="true"></i><?= $sec !== '' ? h($sec) : 'Bez działu' ?>
                </th></tr>
            <?php endif; $rownum++; $lessons = $item_lessons[(int)$it['id']] ?? []; ?>
            <tr id="item-<?= (int)$it['id'] ?>" <?= !$it['is_active'] ? 'class="text-muted"' : '' ?>>
              <td class="text-muted small"><?= $rownum ?></td>
              <td>
                <span class="fw-semibold"><?= h($it['title']) ?></span>
                <?php if (!$it['is_active']): ?><span class="badge bg-secondary ms-1">ukryta</span><?php endif; ?>
                <?php if (trim((string)$it['description']) !== ''): ?>
                  <div class="small text-muted"><?= nl2br(h(mb_strimwidth($it['description'],0,160,'…','UTF-8'))) ?></div>
                <?php endif; ?>
              </td>
              <td class="text-nowrap small"><?= (int)$it['est_minutes'] > 0 ? (int)$it['est_minutes'].' min' : '<span class="text-muted">—</span>' ?></td>
              <td class="small">
                <?php if ($lessons): foreach ($lessons as $ls): ?>
                  <span class="badge bg-info-subtle text-info-emphasis border me-1" title="<?= h($ls['topic'] ?? '') ?>"><?= h(substr($ls['lesson_date'],0,10)) ?></span>
                <?php endforeach; else: ?><span class="text-muted">—</span><?php endif; ?>
              </td>
              <?php if ($can_write): ?>
              <td class="text-end text-nowrap">
                <form method="post" class="d-inline">
                  <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                  <input type="hidden" name="_op" value="move_item">
                  <input type="hidden" name="course_id" value="<?= $course_id ?>">
                  <input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>">
                  <button name="dir" value="up" class="btn btn-sm btn-outline-secondary py-0 px-2" title="Przesuń wyżej" aria-label="Przesuń wyżej: <?= h($it['title']) ?>" <?= $idx===0?'disabled':'' ?>><i class="bi bi-arrow-up" aria-hidden="true"></i></button>
                  <button name="dir" value="down" class="btn btn-sm btn-outline-secondary py-0 px-2" title="Przesuń niżej" aria-label="Przesuń niżej: <?= h($it['title']) ?>" <?= $idx===count($items)-1?'disabled':'' ?>><i class="bi bi-arrow-down" aria-hidden="true"></i></button>
                </form>
                <a href="?course=<?= $course_id ?>&edit=<?= (int)$it['id'] ?>#item-form" class="btn btn-sm btn-outline-primary py-0 px-2" title="Edytuj" aria-label="Edytuj: <?= h($it['title']) ?>"><i class="bi bi-pencil" aria-hidden="true"></i></a>
                <?php if ($can_delete): ?>
                <form method="post" class="d-inline" onsubmit="return confirm('Usunąć pozycję planu „<?= h(addslashes($it['title'])) ?>”?')">
                  <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                  <input type="hidden" name="_op" value="delete_item">
                  <input type="hidden" name="course_id" value="<?= $course_id ?>">
                  <input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>">
                  <button class="btn btn-sm btn-outline-danger py-0 px-2" title="Usuń" aria-label="Usuń: <?= h($it['title']) ?>"><i class="bi bi-trash" aria-hidden="true"></i></button>
                </form>
                <?php endif; ?>
              </td>
              <?php endif; ?>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- DETAIL: edytor pozycji + import (detail) -->
  <?php if ($can_write): ?>
  <div class="col-lg-4">
    <div class="card border-0 shadow-sm" id="item-form">
      <div class="card-header fw-semibold"><i class="bi bi-<?= $edit_row ? 'pencil' : 'plus-lg' ?> me-2" aria-hidden="true"></i><?= $edit_row ? 'Edytuj pozycję planu' : 'Nowa pozycja planu' ?></div>
      <div class="card-body">
        <form method="post">
          <input type="hidden" name="_csrf"     value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="_op"        value="save_item">
          <input type="hidden" name="item_id"    value="<?= (int)$f['id'] ?>">
          <input type="hidden" name="course_id"  value="<?= $course_id ?>">
          <div class="mb-2">
            <label class="form-label fw-semibold" for="f-section">Dział / moduł</label>
            <input type="text" class="form-control" id="f-section" name="section" list="sections-list" value="<?= h($f['section']) ?>" placeholder="np. Podstawy systemu" maxlength="120">
            <datalist id="sections-list">
              <?php foreach (array_values(array_unique(array_filter(array_map(fn($i)=>$i['section'],$items)))) as $s): ?>
              <option value="<?= h($s) ?>"></option>
              <?php endforeach; ?>
            </datalist>
            <div class="form-text">Pozycje grupują się wg działu. Zostaw puste dla „Bez działu".</div>
          </div>
          <div class="mb-2">
            <label class="form-label fw-semibold" for="f-title">Temat / punkt planu <span class="text-danger" aria-hidden="true">*</span></label>
            <input type="text" class="form-control" id="f-title" name="title" value="<?= h($f['title']) ?>" required maxlength="255" placeholder="np. Obsługa czytnika ekranu NVDA">
          </div>
          <div class="mb-2">
            <label class="form-label" for="f-desc">Opis / efekty kształcenia</label>
            <textarea class="form-control" id="f-desc" name="description" rows="3" placeholder="Co kursant ma umieć po realizacji tego punktu"><?= h($f['description']) ?></textarea>
          </div>
          <div class="mb-2">
            <label class="form-label" for="f-min">Szacowany czas (min)</label>
            <input type="number" class="form-control" id="f-min" name="est_minutes" min="0" max="100000" step="5" value="<?= (int)$f['est_minutes'] ?: '' ?>" placeholder="np. 90">
          </div>
          <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" role="switch" name="is_active" id="f-active" value="1" <?= $f['is_active'] ? 'checked' : '' ?>>
            <label class="form-check-label" for="f-active">Pozycja aktywna (widoczna w planie)</label>
          </div>
          <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary"><?= $edit_row ? 'Zapisz zmiany' : 'Dodaj pozycję' ?></button>
            <?php if ($edit_row): ?><a href="curriculum.php?course=<?= $course_id ?>#item-form" class="btn btn-outline-secondary">Anuluj</a><?php endif; ?>
          </div>
        </form>
      </div>
    </div>

    <!-- Import CSV -->
    <div class="card border-0 shadow-sm mt-4">
      <div class="card-header fw-semibold"><i class="bi bi-upload me-2" aria-hidden="true"></i>Import z pliku CSV</div>
      <div class="card-body">
        <form method="post" enctype="multipart/form-data">
          <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="_op" value="import_csv">
          <input type="hidden" name="course_id" value="<?= $course_id ?>">
          <p class="small text-muted mb-2">Kolumny (separator <code>;</code> lub <code>,</code>): <strong>dział; temat; opis; czas&nbsp;w&nbsp;min</strong>. Wiersz nagłówka jest pomijany. Tylko <em>temat</em> jest wymagany.</p>
          <div class="mb-2">
            <label class="form-label" for="csv-file">Plik CSV</label>
            <input type="file" class="form-control" id="csv-file" name="csv_file" accept=".csv,text/csv,text/plain">
          </div>
          <div class="mb-2">
            <label class="form-label" for="csv-text">… lub wklej dane</label>
            <textarea class="form-control" id="csv-text" name="csv_text" rows="3" placeholder="Podstawy;Włączanie i wyłączanie komputera;;30"></textarea>
          </div>
          <button type="submit" class="btn btn-outline-primary"><i class="bi bi-upload me-1" aria-hidden="true"></i>Importuj</button>
        </form>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>

<?php if ($import || $edit_row): ?>
<script>
// Przenieś fokus na wynik importu / formularz edycji (dostępność)
(function(){
  <?php if ($import): ?>
  var imp = document.getElementById('import');
  if (imp) { imp.focus(); }
  <?php elseif ($edit_row): ?>
  var t = document.getElementById('f-title');
  if (t) { t.focus(); }
  <?php endif; ?>
})();
</script>
<?php endif; ?>

<?php endif; ?>

<?php include dirname(dirname(__DIR__)) . '/karty30/includes/footer_k30.php'; ?>
