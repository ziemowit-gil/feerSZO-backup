<?php
/**
 * karty30/ti/tests.php — Testy/quizy TI: lista testów kursu + edytor metadanych.
 * Master-detail (jak grades.php): lista testów (lewa) + formularz testu (prawa).
 * Budowanie pytań i przegląd odpowiedzi: test_build.php?test=ID.
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
$PAGE_TITLE = 'Testy — TI';

$course_id  = (int)($_GET['course'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_write) {
    csrf_check();
    if ($_cc_msg = ti_course_closed_guard($_POST)) {
        flash_set('danger', $_cc_msg);
        header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'index.php')); exit;
    }
    $op  = $_POST['_op'] ?? '';
    $cid = (int)($_POST['course_id'] ?? 0);

    if ($op === 'save_test') {
        $tid = (int)($_POST['test_id'] ?? 0);
        if (!$cid || !k30_ti_course_get($cid)) { flash_set('danger','Wybierz kurs.'); header('Location: tests.php'); exit; }
        if (trim($_POST['title'] ?? '') === '') { flash_set('danger','Podaj tytuł testu.'); header('Location: tests.php?course='.$cid); exit; }
        $new = k30_ti_test_save([
            'course_id'      => $cid,
            'title'          => $_POST['title'] ?? '',
            'description'    => $_POST['description'] ?? '',
            'time_limit_min' => $_POST['time_limit_min'] ?? 0,
            'pass_pct'        => $_POST['pass_pct'] ?? 0,
            'retake_pass_pct' => $_POST['retake_pass_pct'] ?? 0,
            'shuffle'         => isset($_POST['shuffle']) ? 1 : 0,
            'is_active'      => isset($_POST['is_active']) ? 1 : 0,
            'sync_grade'     => isset($_POST['sync_grade']) ? 1 : 0,
        ], $tid ?: null, current_user()['id'] ?? null);
        flash_set('success', $tid ? 'Test zaktualizowany.' : 'Test utworzony — dodaj pytania.');
        header('Location: ' . ($tid ? 'tests.php?course='.$cid : 'test_build.php?test='.$new)); exit;
    }

    if ($op === 'toggle_active') {
        $tid = (int)($_POST['test_id'] ?? 0);
        $t = $tid ? k30_ti_test_get($tid) : null;
        if ($t) {
            db()->prepare("UPDATE k30_ti_tests SET is_active=?, updated_at=datetime('now') WHERE id=?")
                ->execute([empty($t['is_active']) ? 1 : 0, $tid]);
            flash_set('success', empty($t['is_active']) ? 'Test udostępniony kursantom.' : 'Test ukryty.');
        }
        header('Location: tests.php?course='.$cid); exit;
    }

    if ($op === 'delete_test') {
        if (!$can_delete) { http_response_code(403); die('Brak uprawnień.'); }
        $tid = (int)($_POST['test_id'] ?? 0);
        if ($tid) { k30_ti_test_delete($tid); flash_set('success','Test usunięty.'); }
        header('Location: tests.php?course='.$cid); exit;
    }
}

$courses = k30_ti_courses(false);
$course  = $course_id ? k30_ti_course_get($course_id) : null;
$tests   = $course ? k30_ti_tests_list($course_id) : [];

$edit_id  = (int)($_GET['edit'] ?? 0);
$edit_row = $edit_id ? k30_ti_test_get($edit_id) : null;
$f = $edit_row ?: ['id'=>0,'title'=>'','description'=>'','time_limit_min'=>0,'pass_pct'=>0,'retake_pass_pct'=>0,'shuffle'=>0,'is_active'=>0,'sync_grade'=>0];

include dirname(dirname(__DIR__)) . '/karty30/includes/header_k30.php';
?>

<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/karty30/index.php">Dydaktyka 3</a></li>
  <li class="breadcrumb-item"><a href="index.php">Zajęcia TI</a></li>
  <li class="breadcrumb-item active">Testy</li>
</ol></nav>

<div class="d-flex align-items-center mb-3 gap-2 flex-wrap">
  <h4 class="mb-0 fw-bold"><i class="bi bi-card-checklist text-primary me-2"></i>Testy</h4>
  <a href="curriculum.php" class="btn btn-outline-secondary btn-sm ms-auto"><i class="bi bi-list-check me-1"></i>Plan nauczania</a>
  <a href="grades.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-table me-1"></i>Dziennik ocen</a>
</div>

<?= flash_html() ?>

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
<div class="alert alert-info"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>Wybierz kurs, aby zarządzać testami.</div>
<?php else: ?>

<div class="row g-4">
  <!-- LISTA testów (master) -->
  <div class="col-lg-8">
    <div class="card border-0 shadow-sm">
      <div class="card-header fw-semibold d-flex align-items-center gap-2">
        <span><i class="bi bi-card-checklist me-2" aria-hidden="true"></i><?= h($course['name']) ?></span>
        <span class="badge bg-secondary"><?= count($tests) ?> testów</span>
      </div>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <caption class="visually-hidden">Testy kursu <?= h($course['name']) ?></caption>
          <thead class="table-light"><tr>
            <th scope="col">Test</th>
            <th scope="col" class="text-nowrap">Pytania</th>
            <th scope="col" class="text-nowrap">Podejścia</th>
            <th scope="col">Status</th>
            <?php if ($can_write): ?><th scope="col" class="text-end">Akcje</th><?php endif; ?>
          </tr></thead>
          <tbody>
            <?php if (!$tests): ?><tr><td colspan="5" class="text-center text-muted py-3">Brak testów. Utwórz pierwszy po prawej.</td></tr><?php endif; ?>
            <?php foreach ($tests as $t): ?>
            <tr>
              <td>
                <a href="test_build.php?test=<?= (int)$t['id'] ?>" class="fw-semibold text-decoration-none"><?= h($t['title']) ?></a>
                <?php if (trim((string)$t['description']) !== ''): ?><div class="small text-muted"><?= h(mb_strimwidth($t['description'],0,120,'…','UTF-8')) ?></div><?php endif; ?>
                <?php if ((int)$t['time_limit_min'] > 0): ?><span class="badge bg-light text-dark border"><i class="bi bi-stopwatch me-1" aria-hidden="true"></i><?= (int)$t['time_limit_min'] ?> min</span><?php endif; ?>
                <?php if ((int)$t['pass_pct'] > 0): ?><span class="badge bg-light text-dark border">próg <?= (int)$t['pass_pct'] ?>%</span><?php endif; ?>
                <?php if (!empty($t['sync_grade'])): ?><span class="badge bg-light text-dark border" title="Wynik trafia do e-dziennika"><i class="bi bi-table me-1" aria-hidden="true"></i>do dziennika</span><?php endif; ?>
              </td>
              <td class="text-nowrap"><?= (int)$t['n_questions'] ?></td>
              <td class="text-nowrap"><?= (int)$t['n_attempts'] ?></td>
              <td>
                <?php if (!empty($t['is_active'])): ?>
                  <span class="badge text-bg-success d-inline-flex align-items-center gap-1"><i class="bi bi-eye-fill" aria-hidden="true"></i>Udostępniony</span>
                <?php else: ?>
                  <span class="badge text-bg-secondary d-inline-flex align-items-center gap-1"><i class="bi bi-eye-slash" aria-hidden="true"></i>Ukryty</span>
                <?php endif; ?>
              </td>
              <?php if ($can_write): ?>
              <td class="text-end text-nowrap">
                <a href="test_build.php?test=<?= (int)$t['id'] ?>" class="btn btn-sm btn-outline-primary py-0 px-2" title="Pytania i odpowiedzi" aria-label="Buduj pytania: <?= h($t['title']) ?>"><i class="bi bi-pencil-square" aria-hidden="true"></i></a>
                <a href="?course=<?= $course_id ?>&edit=<?= (int)$t['id'] ?>#test-form" class="btn btn-sm btn-outline-secondary py-0 px-2" title="Ustawienia testu" aria-label="Ustawienia: <?= h($t['title']) ?>"><i class="bi bi-gear" aria-hidden="true"></i></a>
                <form method="post" class="d-inline">
                  <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                  <input type="hidden" name="_op" value="toggle_active">
                  <input type="hidden" name="course_id" value="<?= $course_id ?>">
                  <input type="hidden" name="test_id" value="<?= (int)$t['id'] ?>">
                  <button class="btn btn-sm btn-outline-secondary py-0 px-2" title="<?= !empty($t['is_active'])?'Ukryj':'Udostępnij' ?>" aria-label="<?= !empty($t['is_active'])?'Ukryj':'Udostępnij' ?>: <?= h($t['title']) ?>"><i class="bi bi-<?= !empty($t['is_active'])?'eye-slash':'eye' ?>" aria-hidden="true"></i></button>
                </form>
                <?php if ($can_delete): ?>
                <form method="post" class="d-inline" onsubmit="return confirm('Usunąć test „<?= h(addslashes($t['title'])) ?>” wraz z pytaniami i podejściami?')">
                  <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                  <input type="hidden" name="_op" value="delete_test">
                  <input type="hidden" name="course_id" value="<?= $course_id ?>">
                  <input type="hidden" name="test_id" value="<?= (int)$t['id'] ?>">
                  <button class="btn btn-sm btn-outline-danger py-0 px-2" title="Usuń" aria-label="Usuń: <?= h($t['title']) ?>"><i class="bi bi-trash" aria-hidden="true"></i></button>
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

  <!-- DETAIL: ustawienia testu -->
  <?php if ($can_write): ?>
  <div class="col-lg-4">
    <div class="card border-0 shadow-sm" id="test-form">
      <div class="card-header fw-semibold"><i class="bi bi-<?= $edit_row ? 'gear' : 'plus-lg' ?> me-2" aria-hidden="true"></i><?= $edit_row ? 'Ustawienia testu' : 'Nowy test' ?></div>
      <div class="card-body">
        <form method="post">
          <input type="hidden" name="_csrf"     value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="_op"        value="save_test">
          <input type="hidden" name="test_id"    value="<?= (int)$f['id'] ?>">
          <input type="hidden" name="course_id"  value="<?= $course_id ?>">
          <div class="mb-2">
            <label class="form-label fw-semibold" for="t-title">Tytuł <span class="text-danger" aria-hidden="true">*</span></label>
            <input type="text" class="form-control" id="t-title" name="title" value="<?= h($f['title']) ?>" required maxlength="255" placeholder="np. Sprawdzian — podstawy systemu">
          </div>
          <div class="mb-2">
            <label class="form-label" for="t-desc">Opis / instrukcja</label>
            <textarea class="form-control" id="t-desc" name="description" rows="2" placeholder="Widoczna dla kursanta przed rozpoczęciem"><?= h($f['description']) ?></textarea>
          </div>
          <div class="row g-2">
            <div class="col-6 mb-2">
              <label class="form-label" for="t-time">Limit czasu (min)</label>
              <input type="number" class="form-control" id="t-time" name="time_limit_min" min="0" max="600" value="<?= (int)$f['time_limit_min'] ?: '' ?>" placeholder="0 = brak">
            </div>
            <div class="col-6 mb-2">
              <label class="form-label" for="t-pass">Próg zaliczenia (%)</label>
              <input type="number" class="form-control" id="t-pass" name="pass_pct" min="0" max="100" value="<?= (int)$f['pass_pct'] ?: '' ?>" placeholder="0 = brak">
            </div>
          </div>
          <div class="form-check form-switch mb-1">
            <input class="form-check-input" type="checkbox" role="switch" name="shuffle" id="t-shuffle" value="1" <?= !empty($f['shuffle'])?'checked':'' ?>>
            <label class="form-check-label" for="t-shuffle">Losowa kolejność pytań</label>
          </div>
          <div class="form-check form-switch mb-1">
            <input class="form-check-input" type="checkbox" role="switch" name="sync_grade" id="t-sync" value="1" <?= !empty($f['sync_grade'])?'checked':'' ?>>
            <label class="form-check-label" for="t-sync">Zapisuj wynik do e-dziennika (ocena z %)</label>
          </div>
          <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" role="switch" name="is_active" id="t-active" value="1" <?= !empty($f['is_active'])?'checked':'' ?>>
            <label class="form-check-label" for="t-active">Udostępnij kursantom</label>
          </div>
          <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary"><?= $edit_row ? 'Zapisz zmiany' : 'Utwórz i dodaj pytania' ?></button>
            <?php if ($edit_row): ?><a href="tests.php?course=<?= $course_id ?>#test-form" class="btn btn-outline-secondary">Anuluj</a><?php endif; ?>
          </div>
        </form>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php include dirname(dirname(__DIR__)) . '/karty30/includes/footer_k30.php'; ?>
