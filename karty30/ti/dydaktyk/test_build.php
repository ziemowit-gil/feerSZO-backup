<?php
/**
 * karty30/ti/dydaktyk/test_build.php — Budowanie testów przez prowadzącego.
 * Obsługuje tworzenie/edycję metadanych testu oraz pytań + wariantów.
 * Autoryzacja: dyd_require() — dostęp tylko do własnych kursów.
 */
require_once __DIR__ . '/auth.php';

karty30_migrate();
$me  = dyd_require();
$uid = (int)$me['user_id'];

$my_courses  = dyd_courses($uid);
$my_cids     = array_map(fn($c) => (int)$c['id'], $my_courses);

/* ── Pomocnik: sprawdź, czy kurs należy do prowadzącego ─────────────── */
$assert_course = function(int $cid) use ($my_cids): void {
    if (!in_array($cid, $my_cids, true)) {
        http_response_code(403); exit('Brak uprawnień do tego kursu.');
    }
};

/* ── Pomocnik: sprawdź, czy test należy do prowadzącego ─────────────── */
$assert_test = function(int $tid) use ($my_cids): array {
    $t = $tid ? k30_ti_test_get($tid) : null;
    if (!$t || !in_array((int)$t['course_id'], $my_cids, true)) {
        http_response_code(403); exit('Brak uprawnień do tego testu.');
    }
    return $t;
};

/* ═══════════════════ OBSŁUGA POST ═══════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    dyd_token_check();
    $op = $_POST['_op'] ?? '';

    if ($op === 'save_test') {
        $cid = (int)($_POST['course_id'] ?? 0);
        $assert_course($cid);
        $tid = (int)($_POST['test_id'] ?? 0);
        if (trim($_POST['title'] ?? '') === '') {
            flash_set('danger', 'Podaj tytuł testu.');
            header('Location: test_build.php?course_id='.$cid.($tid?'&test_id='.$tid:'')); exit;
        }
        $new = k30_ti_test_save([
            'course_id'       => $cid,
            'title'           => $_POST['title'] ?? '',
            'description'     => $_POST['description'] ?? '',
            'time_limit_min'  => $_POST['time_limit_min'] ?? 0,
            'pass_pct'        => $_POST['pass_pct'] ?? 0,
            'retake_pass_pct' => $_POST['retake_pass_pct'] ?? 0,
            'shuffle'         => isset($_POST['shuffle']) ? 1 : 0,
            'is_active'       => isset($_POST['is_active']) ? 1 : 0,
            'sync_grade'      => isset($_POST['sync_grade']) ? 1 : 0,
        ], $tid ?: null, $uid);
        flash_set('success', $tid ? 'Test zaktualizowany.' : 'Test utworzony — dodaj pytania.');
        header('Location: test_build.php?test_id=' . ($tid ?: $new)); exit;
    }

    if ($op === 'save_question') {
        $tid  = (int)($_POST['test_id'] ?? 0);
        $test = $assert_test($tid);
        $qid  = (int)($_POST['question_id'] ?? 0);
        $type = $_POST['type'] ?? 'single';
        $prompt = trim($_POST['prompt'] ?? '');
        if ($prompt === '') {
            flash_set('danger', 'Treść pytania jest wymagana.');
            header('Location: test_build.php?test_id='.$tid.($qid?'&q='.$qid:'')); exit;
        }
        $options = [];
        if ($type !== 'open') {
            $labels  = (array)($_POST['opt_label'] ?? []);
            $correct = (array)($_POST['opt_correct'] ?? []);
            foreach ($labels as $i => $lab) {
                if (trim((string)$lab) === '') continue;
                $options[] = ['label' => $lab, 'is_correct' => isset($correct[$i]) ? 1 : 0];
            }
            if (count($options) < 2) {
                flash_set('danger', 'Pytanie zamknięte wymaga co najmniej 2 wariantów.');
                header('Location: test_build.php?test_id='.$tid.($qid?'&q='.$qid:'')); exit;
            }
            if (!array_filter($options, fn($o) => $o['is_correct'])) {
                flash_set('danger', 'Zaznacz co najmniej jeden poprawny wariant.');
                header('Location: test_build.php?test_id='.$tid.($qid?'&q='.$qid:'')); exit;
            }
        }
        k30_ti_test_question_save([
            'test_id' => $tid, 'type' => $type, 'prompt' => $prompt,
            'points'  => $_POST['points'] ?? 1, 'options' => $options,
            'in_bank' => !empty($_POST['in_bank']) ? 1 : 0,
        ], $qid ?: null);
        flash_set('success', $qid ? 'Pytanie zaktualizowane.' : 'Pytanie dodane.');
        header('Location: test_build.php?test_id='.$tid.'#q-list'); exit;
    }

    if ($op === 'delete_question') {
        $tid  = (int)($_POST['test_id'] ?? 0);
        $assert_test($tid);
        $qid = (int)($_POST['question_id'] ?? 0);
        if ($qid) { k30_ti_test_question_delete($qid); flash_set('success', 'Pytanie usunięte.'); }
        header('Location: test_build.php?test_id='.$tid); exit;
    }

    if ($op === 'move_question') {
        $tid = (int)($_POST['test_id'] ?? 0);
        $assert_test($tid);
        $qid = (int)($_POST['question_id'] ?? 0);
        $dir = ($_POST['dir'] ?? '') === 'up' ? 'up' : 'down';
        $ids = array_map(fn($r) => (int)$r['id'], k30_ti_test_questions($tid));
        $pos = array_search($qid, $ids, true);
        if ($pos !== false) {
            $swap = $dir === 'up' ? $pos - 1 : $pos + 1;
            if ($swap >= 0 && $swap < count($ids)) {
                [$ids[$pos], $ids[$swap]] = [$ids[$swap], $ids[$pos]];
                k30_ti_test_question_reorder($tid, $ids);
            }
        }
        header('Location: test_build.php?test_id='.$tid.'#q-'.$qid); exit;
    }

    if ($op === 'toggle_active') {
        $tid  = (int)($_POST['test_id'] ?? 0);
        $test = $assert_test($tid);
        db()->prepare("UPDATE k30_ti_tests SET is_active=?, updated_at=datetime('now') WHERE id=?")
             ->execute([empty($test['is_active']) ? 1 : 0, $tid]);
        flash_set('success', empty($test['is_active']) ? 'Test udostępniony kursantom.' : 'Test ukryty.');
        header('Location: test_build.php?test_id='.$tid); exit;
    }

    if ($op === 'set_bank_draw') {
        $tid = (int)($_POST['test_id'] ?? 0);
        $assert_test($tid);
        db_update('k30_ti_tests', ['bank_draw' => max(0, (int)($_POST['bank_draw'] ?? 0))], $tid);
        flash_set('success', 'Ustawienie bazy pytań zapisane.');
        header('Location: test_build.php?test_id='.$tid.'#bank'); exit;
    }

    if ($op === 'set_fixed_draw') {
        $tid = (int)($_POST['test_id'] ?? 0);
        $assert_test($tid);
        db_update('k30_ti_tests', ['fixed_draw' => max(0, (int)($_POST['fixed_draw'] ?? 0))], $tid);
        flash_set('success', 'Ustawienie losowania zapisane.');
        header('Location: test_build.php?test_id='.$tid.'#fixed'); exit;
    }

    if ($op === 'grade_open') {
        $tid    = (int)($_POST['test_id'] ?? 0);
        $assert_test($tid);
        $att_id = (int)($_POST['attempt_id'] ?? 0);
        $pts    = (array)($_POST['points'] ?? []);
        if ($att_id) { k30_ti_test_grade_open($att_id, $pts); flash_set('success', 'Ocena zapisana.'); }
        header('Location: test_build.php?test_id='.$tid.'#review'); exit;
    }

    // Fallback
    header('Location: index.php'); exit;
}

/* ═══════════════════ GET — ustal stan strony ═════════════════════════ */
$test_id   = (int)($_GET['test_id'] ?? 0);
$course_id = (int)($_GET['course_id'] ?? 0);

$test = null;
if ($test_id) {
    $test = k30_ti_test_get($test_id);
    if (!$test || !in_array((int)$test['course_id'], $my_cids, true)) {
        flash_set('danger', 'Nie znaleziono testu lub brak uprawnień.');
        header('Location: index.php'); exit;
    }
    $course_id = (int)$test['course_id'];
}
if ($course_id && !in_array($course_id, $my_cids, true)) {
    flash_set('danger', 'Brak uprawnień do tego kursu.');
    header('Location: index.php'); exit;
}

$course = $course_id ? k30_ti_course_get($course_id) : null;

/* ── Dane dla listy testów kursu ─────────────────────────────────────── */
$course_tests = $course_id ? k30_ti_tests_list($course_id) : [];

/* ── Dane dla edytora pytań (gdy mamy test_id) ─────────────────────── */
$questions  = [];
$fixed_qs   = [];
$bank_qs    = [];
$bank_draw  = 0;
$fixed_draw = 0;
$to_review  = [];
$q_id  = 0;
$q_row = null;
$q_opts = [];
$qf = ['id'=>0,'type'=>'single','prompt'=>'','points'=>1,'in_bank'=>0];

if ($test) {
    $questions  = k30_ti_test_questions($test_id);
    $fixed_qs   = k30_ti_test_fixed_questions($test_id);
    $bank_qs    = k30_ti_test_bank_questions($test_id);
    $bank_draw  = (int)($test['bank_draw']  ?? 0);
    $fixed_draw = (int)($test['fixed_draw'] ?? 0);
    $attempts   = k30_ti_test_attempts_for_test($test_id);
    $to_review  = array_values(array_filter($attempts,
        fn($a) => (int)$a['needs_review'] === 1 && $a['status'] === 'submitted'));
    $q_id  = (int)($_GET['q'] ?? 0);
    $q_row = $q_id ? k30_ti_test_question_get($q_id) : null;
    if ($q_row && (int)$q_row['test_id'] !== $test_id) $q_row = null;
    $q_opts = $q_row ? k30_ti_test_options($q_id) : [];
    $qf = $q_row ?: $qf;
}

/* ── Formularz ustawień testu (nowy lub edycja) ─────────────────────── */
$edit_meta = (bool)($_GET['edit_meta'] ?? false);
$meta_f = $test ?: ['id'=>0,'title'=>'','description'=>'','time_limit_min'=>0,'pass_pct'=>0,'retake_pass_pct'=>0,'shuffle'=>0,'is_active'=>0,'sync_grade'=>0];

/* ═══════════════════ HTML ═══════════════════════════════════════════ */
$KP_TITLE  = $test ? 'Test: '.($test['title']) : ($course ? 'Testy — '.$course['name'] : 'Testy');
$KP_TOPBAR = ['brand'=>'Panel dydaktyka','icon'=>'easel2','user'=>$me['name']??'','logout'=>'logout.php'];
include dirname(__DIR__) . '/kursant/_layout_head.php';
$QT = K30_TI_QUESTION_TYPES;
?>

<div class="container-xxl py-3">

<!-- NAWIGACJA WSTECZ -->
<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb small">
  <li class="breadcrumb-item"><a href="index.php">Panel dydaktyka</a></li>
  <?php if ($course): ?>
  <li class="breadcrumb-item"><a href="index.php?tab=testy&amp;course=<?= $course_id ?>">Testy — <?= h($course['name']) ?></a></li>
  <?php endif; ?>
  <?php if ($test): ?><li class="breadcrumb-item active"><?= h($test['title']) ?></li><?php endif; ?>
</ol></nav>

<?= flash_html() ?>

<?php if (!$test): ?>
<!-- ════════════════════════════════════════════════════════════════════
     WIDOK 1: Lista testów kursu + formularz nowego / edycja metadanych
     ════════════════════════════════════════════════════════════════════ -->

<?php if (!$course): ?>
<div class="card border-0 shadow-sm" style="max-width:480px">
  <div class="card-body">
    <h5 class="card-title fw-semibold mb-3"><i class="bi bi-card-checklist me-2 text-primary" aria-hidden="true"></i>Wybierz kurs</h5>
    <?php if (!$my_courses): ?>
    <p class="text-muted small mb-0">Brak przypisanych kursów.</p>
    <?php else: ?>
    <div class="list-group list-group-flush">
      <?php foreach ($my_courses as $_mc): ?>
      <a href="test_build.php?course_id=<?= (int)$_mc['id'] ?>"
         class="list-group-item list-group-item-action d-flex align-items-center gap-2 py-2">
        <i class="bi bi-mortarboard text-secondary" aria-hidden="true"></i>
        <span class="fw-semibold"><?= h($_mc['name']) ?></span>
      </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php else: ?>

<div class="row g-4">
  <!-- Lista testów -->
  <div class="col-lg-8">
    <div class="card border-0 shadow-sm">
      <div class="card-header fw-semibold d-flex align-items-center gap-2">
        <i class="bi bi-card-checklist me-1" aria-hidden="true"></i><?= h($course['name']) ?>
        <span class="badge bg-secondary"><?= count($course_tests) ?> testów</span>
        <a href="test_build.php?course_id=<?= $course_id ?>#test-form" class="btn btn-sm btn-primary ms-auto"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Nowy test</a>
      </div>
      <?php if (!$course_tests): ?>
        <div class="card-body text-muted">Brak testów w tym kursie. Utwórz pierwszy po prawej.</div>
      <?php else: ?>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead class="table-light"><tr>
            <th>Test</th><th class="text-center">Pytania</th><th>Status</th><th class="text-end">Akcje</th>
          </tr></thead>
          <tbody>
          <?php foreach ($course_tests as $ct): ?>
          <tr>
            <td>
              <a href="test_build.php?test_id=<?= (int)$ct['id'] ?>" class="fw-semibold text-decoration-none"><?= h($ct['title']) ?></a>
              <?php if ((int)$ct['time_limit_min']>0): ?><span class="badge bg-light text-dark border ms-1"><i class="bi bi-stopwatch" aria-hidden="true"></i> <?= (int)$ct['time_limit_min'] ?> min</span><?php endif; ?>
              <?php if ((int)$ct['pass_pct']>0): ?><span class="badge bg-light text-dark border ms-1">próg <?= (int)$ct['pass_pct'] ?>%</span><?php endif; ?>
            </td>
            <td class="text-center"><?= (int)$ct['n_questions'] ?></td>
            <td>
              <?php if (!empty($ct['is_active'])): ?>
                <span class="badge text-bg-success"><i class="bi bi-eye-fill me-1" aria-hidden="true"></i>Widoczny</span>
              <?php else: ?>
                <span class="badge text-bg-secondary"><i class="bi bi-eye-slash me-1" aria-hidden="true"></i>Ukryty</span>
              <?php endif; ?>
            </td>
            <td class="text-end text-nowrap">
              <a href="test_build.php?test_id=<?= (int)$ct['id'] ?>" class="btn btn-sm btn-outline-primary py-0 px-2" title="Pytania"><i class="bi bi-pencil-square" aria-hidden="true"></i></a>
              <a href="test_build.php?course_id=<?= $course_id ?>&test_id=<?= (int)$ct['id'] ?>&edit_meta=1#test-form" class="btn btn-sm btn-outline-secondary py-0 px-2" title="Ustawienia"><i class="bi bi-gear" aria-hidden="true"></i></a>
              <form method="post" class="d-inline">
                <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
                <input type="hidden" name="_op" value="toggle_active">
                <input type="hidden" name="test_id" value="<?= (int)$ct['id'] ?>">
                <button class="btn btn-sm btn-outline-secondary py-0 px-2" title="<?= !empty($ct['is_active'])?'Ukryj':'Pokaż' ?>"><i class="bi bi-<?= !empty($ct['is_active'])?'eye-slash':'eye' ?>" aria-hidden="true"></i></button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Formularz: nowy / edycja testu -->
  <div class="col-lg-4">
    <div class="card border-0 shadow-sm" id="test-form">
      <div class="card-header fw-semibold">
        <i class="bi bi-<?= $edit_meta && $test ? 'gear' : 'plus-lg' ?> me-2" aria-hidden="true"></i><?= ($edit_meta && $test) ? 'Ustawienia testu' : 'Nowy test' ?>
      </div>
      <div class="card-body">
        <form method="post">
          <input type="hidden" name="_token"     value="<?= h(dyd_token()) ?>">
          <input type="hidden" name="_op"         value="save_test">
          <input type="hidden" name="test_id"     value="<?= (int)$meta_f['id'] ?>">
          <input type="hidden" name="course_id"   value="<?= $course_id ?>">
          <div class="mb-2">
            <label class="form-label fw-semibold" for="t-title">Tytuł <span class="text-danger" aria-hidden="true">*</span></label>
            <input type="text" class="form-control" id="t-title" name="title" value="<?= h($meta_f['title']) ?>" required maxlength="255" placeholder="np. Sprawdzian — lekcja 5">
          </div>
          <div class="mb-2">
            <label class="form-label" for="t-desc">Opis / instrukcja dla kursanta</label>
            <textarea class="form-control" id="t-desc" name="description" rows="2" placeholder="Widoczna przed rozpoczęciem"><?= h($meta_f['description']) ?></textarea>
          </div>
          <div class="row g-2">
            <div class="col-6 mb-2">
              <label class="form-label" for="t-time">Limit czasu (min)</label>
              <input type="number" class="form-control" id="t-time" name="time_limit_min" min="0" max="600" value="<?= (int)$meta_f['time_limit_min'] ?: '' ?>" placeholder="0 = brak">
            </div>
            <div class="col-6 mb-2">
              <label class="form-label" for="t-pass">Próg zaliczenia (%)</label>
              <input type="number" class="form-control" id="t-pass" name="pass_pct" min="0" max="100" value="<?= (int)$meta_f['pass_pct'] ?: '' ?>" placeholder="0 = brak">
            </div>
          </div>
          <div class="form-check form-switch mb-1">
            <input class="form-check-input" type="checkbox" role="switch" name="shuffle" id="t-shuffle" value="1" <?= !empty($meta_f['shuffle'])?'checked':'' ?>>
            <label class="form-check-label" for="t-shuffle">Losowa kolejność pytań</label>
          </div>
          <div class="form-check form-switch mb-1">
            <input class="form-check-input" type="checkbox" role="switch" name="sync_grade" id="t-sync" value="1" <?= !empty($meta_f['sync_grade'])?'checked':'' ?>>
            <label class="form-check-label" for="t-sync">Zapisuj wynik do e-dziennika</label>
          </div>
          <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" role="switch" name="is_active" id="t-active" value="1" <?= !empty($meta_f['is_active'])?'checked':'' ?>>
            <label class="form-check-label" for="t-active">Udostępnij kursantom</label>
          </div>
          <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary"><?= ($edit_meta && $test) ? 'Zapisz zmiany' : 'Utwórz i dodaj pytania' ?></button>
            <?php if ($edit_meta && $test): ?><a href="test_build.php?course_id=<?= $course_id ?>" class="btn btn-outline-secondary">Anuluj</a><?php endif; ?>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php else: ?>
<!-- ════════════════════════════════════════════════════════════════════
     WIDOK 2: Edytor pytań (mamy test_id)
     ════════════════════════════════════════════════════════════════════ -->

<div class="d-flex align-items-center mb-3 gap-2 flex-wrap">
  <h4 class="mb-0 fw-bold"><i class="bi bi-card-checklist text-primary me-2"></i><?= h($test['title']) ?></h4>
  <?php $max_score = k30_ti_test_max_score($test_id); ?>
  <span class="badge bg-secondary"><?= count($fixed_qs) ?> stałych + <?= count($bank_qs) ?> w bazie · <?= rtrim(rtrim(number_format($max_score,2,'.',''),'0'),'.') ?: '0' ?> pkt</span>
  <?php if (!empty($test['is_active'])): ?>
    <span class="badge text-bg-success"><i class="bi bi-eye-fill me-1" aria-hidden="true"></i>Widoczny</span>
  <?php else: ?>
    <span class="badge text-bg-secondary"><i class="bi bi-eye-slash me-1" aria-hidden="true"></i>Ukryty</span>
  <?php endif; ?>
  <div class="ms-auto d-flex gap-2 flex-wrap">
    <form method="post" class="d-inline">
      <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
      <input type="hidden" name="_op" value="toggle_active">
      <input type="hidden" name="test_id" value="<?= $test_id ?>">
      <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-<?= !empty($test['is_active'])?'eye-slash':'eye' ?> me-1" aria-hidden="true"></i><?= !empty($test['is_active'])?'Ukryj':'Udostępnij' ?></button>
    </form>
    <a href="test_build.php?course_id=<?= $course_id ?>&test_id=<?= $test_id ?>&edit_meta=1" class="btn btn-sm btn-outline-secondary"><i class="bi bi-gear me-1" aria-hidden="true"></i>Ustawienia</a>
    <a href="test_build.php?course_id=<?= $course_id ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Testy kursu</a>
    <a href="index.php?tab=testy&amp;course=<?= $course_id ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-grid me-1" aria-hidden="true"></i>Panel</a>
  </div>
</div>

<div class="row g-4">
  <!-- LISTA PYTAŃ (master) -->
  <div class="col-lg-7" id="q-list">

    <!-- Pytania stałe -->
    <div class="card border-0 shadow-sm" id="fixed">
      <div class="card-header fw-semibold d-flex align-items-center gap-2">
        <i class="bi bi-list-ol me-1" aria-hidden="true"></i>Pytania stałe
        <?php if ($fixed_draw > 0): ?><span class="badge bg-primary">losuj <?= $fixed_draw ?> z <?= count($fixed_qs) ?></span><?php endif; ?>
      </div>
      <ol class="list-group list-group-numbered list-group-flush">
        <?php if (!$fixed_qs): ?><li class="list-group-item text-muted small">Brak pytań stałych. Dodaj po prawej.</li><?php endif; ?>
        <?php foreach ($fixed_qs as $idx => $q):
          $opts = $q['type'] !== 'open' ? k30_ti_test_options((int)$q['id']) : [];
        ?>
        <li class="list-group-item" id="q-<?= (int)$q['id'] ?>">
          <div class="d-flex justify-content-between align-items-start gap-2">
            <div class="flex-grow-1 mw-0">
              <span class="badge bg-light text-dark border me-1"><?= h($QT[$q['type']] ?? $q['type']) ?></span>
              <span class="badge bg-light text-dark border me-1"><?= rtrim(rtrim(number_format((float)$q['points'],2,'.',''),'0'),'.') ?> pkt</span>
              <span class="fw-semibold"><?= h($q['prompt']) ?></span>
              <?php if ($q['type'] !== 'open'): ?>
              <ul class="small text-muted mb-0 mt-1">
                <?php foreach ($opts as $o): ?>
                <li><?= !empty($o['is_correct']) ? '<i class="bi bi-check-circle-fill text-success" aria-label="poprawna"></i> ' : '' ?><?= h($o['label']) ?></li>
                <?php endforeach; ?>
              </ul>
              <?php else: ?><div class="small text-muted mt-1"><i class="bi bi-pencil" aria-hidden="true"></i> ocena ręczna</div><?php endif; ?>
            </div>
            <div class="text-nowrap flex-shrink-0">
              <form method="post" class="d-inline">
                <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
                <input type="hidden" name="_op" value="move_question">
                <input type="hidden" name="test_id" value="<?= $test_id ?>">
                <input type="hidden" name="question_id" value="<?= (int)$q['id'] ?>">
                <button name="dir" value="up" class="btn btn-sm btn-outline-secondary py-0 px-1" title="Wyżej" <?= $idx===0?'disabled':'' ?>><i class="bi bi-arrow-up" aria-hidden="true"></i></button>
                <button name="dir" value="down" class="btn btn-sm btn-outline-secondary py-0 px-1" title="Niżej" <?= $idx===count($fixed_qs)-1?'disabled':'' ?>><i class="bi bi-arrow-down" aria-hidden="true"></i></button>
              </form>
              <a href="?test_id=<?= $test_id ?>&q=<?= (int)$q['id'] ?>#q-form" class="btn btn-sm btn-outline-primary py-0 px-1" title="Edytuj"><i class="bi bi-pencil" aria-hidden="true"></i></a>
              <form method="post" class="d-inline" onsubmit="return confirm('Usunąć pytanie?')">
                <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
                <input type="hidden" name="_op" value="delete_question">
                <input type="hidden" name="test_id" value="<?= $test_id ?>">
                <input type="hidden" name="question_id" value="<?= (int)$q['id'] ?>">
                <button class="btn btn-sm btn-outline-danger py-0 px-1" title="Usuń"><i class="bi bi-trash" aria-hidden="true"></i></button>
              </form>
            </div>
          </div>
        </li>
        <?php endforeach; ?>
      </ol>
      <?php if ($fixed_qs): ?>
      <div class="card-footer">
        <form method="post" class="d-flex align-items-center gap-2 flex-wrap">
          <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
          <input type="hidden" name="_op" value="set_fixed_draw">
          <input type="hidden" name="test_id" value="<?= $test_id ?>">
          <div class="form-check form-switch mb-0 me-1">
            <input class="form-check-input" type="checkbox" role="switch" id="fixed-draw-toggle" <?= $fixed_draw > 0 ? 'checked' : '' ?> onchange="document.getElementById('fixed-draw-wrap').style.display=this.checked?'':'none'">
            <label class="form-check-label fw-semibold small" for="fixed-draw-toggle">Losuj pytania stałe</label>
          </div>
          <span id="fixed-draw-wrap" style="display:<?= $fixed_draw > 0 ? '' : 'none' ?>">
            <input type="number" class="form-control form-control-sm d-inline-block" id="fixed-draw-inp" name="fixed_draw" min="1" max="<?= count($fixed_qs) ?>" step="1" value="<?= $fixed_draw ?: '' ?>" style="width:5rem" placeholder="ile?">
            <span class="text-muted small ms-1">z <?= count($fixed_qs) ?></span>
          </span>
          <button class="btn btn-sm btn-outline-primary"><i class="bi bi-save me-1" aria-hidden="true"></i>Zapisz</button>
        </form>
      </div>
      <?php endif; ?>
    </div>

    <!-- Baza pytań -->
    <div class="card border-0 shadow-sm mt-3" id="bank">
      <div class="card-header fw-semibold d-flex align-items-center gap-2">
        <i class="bi bi-collection me-1" aria-hidden="true"></i>Baza pytań
        <?php if ($bank_qs): ?><span class="badge bg-info text-dark"><?= count($bank_qs) ?> w puli</span><?php endif; ?>
        <?php if ($bank_draw > 0): ?><span class="badge bg-primary">losuj <?= $bank_draw ?></span><?php endif; ?>
      </div>
      <ol class="list-group list-group-numbered list-group-flush">
        <?php if (!$bank_qs): ?><li class="list-group-item text-muted small">Brak pytań w bazie. Zaznacz „Baza pytań" przy dodawaniu.</li><?php endif; ?>
        <?php foreach ($bank_qs as $q):
          $opts = $q['type'] !== 'open' ? k30_ti_test_options((int)$q['id']) : [];
        ?>
        <li class="list-group-item" id="q-<?= (int)$q['id'] ?>">
          <div class="d-flex justify-content-between align-items-start gap-2">
            <div class="flex-grow-1">
              <span class="badge bg-info text-dark border me-1"><i class="bi bi-collection" aria-hidden="true"></i></span>
              <span class="badge bg-light text-dark border me-1"><?= h($QT[$q['type']] ?? $q['type']) ?></span>
              <span class="fw-semibold"><?= h($q['prompt']) ?></span>
              <?php if ($q['type'] !== 'open'): ?>
              <ul class="small text-muted mb-0 mt-1">
                <?php foreach ($opts as $o): ?>
                <li><?= !empty($o['is_correct']) ? '<i class="bi bi-check-circle-fill text-success" aria-label="poprawna"></i> ' : '' ?><?= h($o['label']) ?></li>
                <?php endforeach; ?>
              </ul>
              <?php endif; ?>
            </div>
            <div class="text-nowrap flex-shrink-0">
              <a href="?test_id=<?= $test_id ?>&q=<?= (int)$q['id'] ?>#q-form" class="btn btn-sm btn-outline-primary py-0 px-1" title="Edytuj"><i class="bi bi-pencil" aria-hidden="true"></i></a>
              <form method="post" class="d-inline" onsubmit="return confirm('Usunąć z bazy?')">
                <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
                <input type="hidden" name="_op" value="delete_question">
                <input type="hidden" name="test_id" value="<?= $test_id ?>">
                <input type="hidden" name="question_id" value="<?= (int)$q['id'] ?>">
                <button class="btn btn-sm btn-outline-danger py-0 px-1" title="Usuń"><i class="bi bi-trash" aria-hidden="true"></i></button>
              </form>
            </div>
          </div>
        </li>
        <?php endforeach; ?>
      </ol>
      <div class="card-footer">
        <form method="post" class="d-flex align-items-center gap-2 flex-wrap">
          <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
          <input type="hidden" name="_op" value="set_bank_draw">
          <input type="hidden" name="test_id" value="<?= $test_id ?>">
          <label class="form-label mb-0 small fw-semibold" for="bank-draw-inp">Losuj z bazy:</label>
          <input type="number" class="form-control form-control-sm" id="bank-draw-inp" name="bank_draw" min="0" max="9999" step="1" value="<?= $bank_draw ?>" style="width:6rem">
          <button class="btn btn-sm btn-outline-primary"><i class="bi bi-save me-1" aria-hidden="true"></i>Zapisz</button>
          <span class="text-muted small">(0 = wyłączone)</span>
        </form>
      </div>
    </div>

    <!-- Do oceny: pytania otwarte -->
    <?php if ($to_review): ?>
    <div class="card border-0 shadow-sm mt-3" id="review">
      <div class="card-header fw-semibold">
        <i class="bi bi-clipboard-check me-2" aria-hidden="true"></i>Do oceny
        <span class="badge bg-warning text-dark ms-1"><?= count($to_review) ?></span>
      </div>
      <div class="card-body">
        <?php foreach ($to_review as $a):
          $ans = db_all(
            "SELECT ta.*, q.prompt, q.points, q.type FROM k30_ti_test_answers ta
             JOIN k30_ti_test_questions q ON q.id=ta.question_id
             WHERE ta.attempt_id=? AND q.type='open' ORDER BY q.position", [(int)$a['id']]);
        ?>
        <form method="post" class="border rounded p-2 mb-2">
          <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
          <input type="hidden" name="_op" value="grade_open">
          <input type="hidden" name="test_id" value="<?= $test_id ?>">
          <input type="hidden" name="attempt_id" value="<?= (int)$a['id'] ?>">
          <div class="fw-semibold mb-2 small"><i class="bi bi-person me-1" aria-hidden="true"></i><?= h($a['client_name']) ?>
            <span class="text-muted"> — zamknięte: <?= rtrim(rtrim(number_format((float)$a['score'],2,'.',''),'0'),'.') ?>/<?= rtrim(rtrim(number_format((float)$a['max_score'],2,'.',''),'0'),'.') ?> pkt</span></div>
          <?php foreach ($ans as $an): ?>
          <div class="mb-2">
            <div class="small fw-semibold"><?= h($an['prompt']) ?> <span class="text-muted">(max <?= rtrim(rtrim(number_format((float)$an['points'],2,'.',''),'0'),'.') ?> pkt)</span></div>
            <div class="border rounded bg-light p-2 small mb-1" style="white-space:pre-wrap"><?= $an['answer_text']!=='' ? h($an['answer_text']) : '<span class="text-muted">— brak odpowiedzi —</span>' ?></div>
            <label class="form-label small mb-0" for="pts-<?= (int)$a['id'] ?>-<?= (int)$an['question_id'] ?>">Punkty:</label>
            <input type="number" class="form-control form-control-sm d-inline-block" style="width:6rem"
                   id="pts-<?= (int)$a['id'] ?>-<?= (int)$an['question_id'] ?>"
                   name="points[<?= (int)$an['question_id'] ?>]" min="0" max="<?= (float)$an['points'] ?>" step="0.5" value="0">
          </div>
          <?php endforeach; ?>
          <button class="btn btn-sm btn-primary"><i class="bi bi-check-lg me-1" aria-hidden="true"></i>Zapisz ocenę</button>
        </form>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- EDYTOR PYTANIA (detail) -->
  <div class="col-lg-5">
    <div class="card border-0 shadow-sm" id="q-form">
      <div class="card-header fw-semibold">
        <i class="bi bi-<?= $q_row ? 'pencil' : 'plus-lg' ?> me-2" aria-hidden="true"></i><?= $q_row ? 'Edytuj pytanie' : 'Nowe pytanie' ?>
      </div>
      <div class="card-body">
        <form method="post" id="qform">
          <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
          <input type="hidden" name="_op" value="save_question">
          <input type="hidden" name="test_id" value="<?= $test_id ?>">
          <input type="hidden" name="question_id" value="<?= (int)$qf['id'] ?>">
          <div class="mb-2">
            <label class="form-label fw-semibold" for="q-type">Typ pytania</label>
            <select class="form-select" id="q-type" name="type">
              <?php foreach ($QT as $slug => $lab): ?>
              <option value="<?= h($slug) ?>" <?= $qf['type']===$slug?'selected':'' ?>><?= h($lab) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-2">
            <label class="form-label fw-semibold" for="q-prompt">Treść pytania <span class="text-danger" aria-hidden="true">*</span></label>
            <textarea class="form-control" id="q-prompt" name="prompt" rows="2" required><?= h($qf['prompt']) ?></textarea>
          </div>
          <div class="mb-2">
            <label class="form-label" for="q-points">Punkty</label>
            <input type="number" class="form-control" id="q-points" name="points" min="0" max="100" step="0.5"
                   value="<?= rtrim(rtrim(number_format((float)$qf['points'],2,'.',''),'0'),'.') ?: '1' ?>" style="width:7rem">
          </div>
          <div class="mb-2">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" id="q-in-bank" name="in_bank" value="1" <?= !empty($qf['in_bank'])?'checked':'' ?>>
              <label class="form-check-label small" for="q-in-bank">Baza pytań <span class="text-muted">(losowane przy każdym podejściu)</span></label>
            </div>
          </div>
          <div id="opts-wrap" class="mb-2">
            <label class="form-label fw-semibold">Warianty odpowiedzi <span class="form-text">(zaznacz poprawne)</span></label>
            <div id="opts-list">
              <?php
              $rows = $q_opts;
              for ($i = count($rows); $i < 4; $i++) $rows[] = ['label'=>'','is_correct'=>0];
              foreach ($rows as $i => $o): ?>
              <div class="input-group input-group-sm mb-1 opt-row">
                <div class="input-group-text">
                  <input class="form-check-input mt-0" type="checkbox" name="opt_correct[<?= $i ?>]" value="1"
                         <?= !empty($o['is_correct'])?'checked':'' ?> aria-label="Wariant poprawny">
                </div>
                <input type="text" class="form-control" name="opt_label[<?= $i ?>]"
                       value="<?= h($o['label']) ?>" placeholder="Treść wariantu" aria-label="Wariant <?= $i+1 ?>">
              </div>
              <?php endforeach; ?>
            </div>
            <button type="button" class="btn btn-sm btn-outline-secondary mt-1" id="add-opt">
              <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Dodaj wariant
            </button>
          </div>
          <div class="d-flex gap-2 mt-3">
            <button type="submit" class="btn btn-primary"><?= $q_row ? 'Zapisz pytanie' : 'Dodaj pytanie' ?></button>
            <?php if ($q_row): ?><a href="test_build.php?test_id=<?= $test_id ?>#q-form" class="btn btn-outline-secondary">Anuluj</a><?php endif; ?>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  var typeSel = document.getElementById('q-type');
  var optsWrap = document.getElementById('opts-wrap');
  var list = document.getElementById('opts-list');
  var addBtn = document.getElementById('add-opt');
  function syncType(){ optsWrap.style.display = (typeSel.value === 'open') ? 'none' : ''; }
  typeSel.addEventListener('change', syncType); syncType();
  addBtn.addEventListener('click', function(){
    var i = list.querySelectorAll('.opt-row').length;
    var row = document.createElement('div');
    row.className = 'input-group input-group-sm mb-1 opt-row';
    row.innerHTML = '<div class="input-group-text"><input class="form-check-input mt-0" type="checkbox" name="opt_correct['+i+']" value="1" aria-label="Wariant poprawny"></div>'
      + '<input type="text" class="form-control" name="opt_label['+i+']" placeholder="Treść wariantu" aria-label="Wariant '+(i+1)+'">';
    list.appendChild(row);
    var inp = row.querySelector('input[type=text]'); if (inp) inp.focus();
  });
})();
</script>

<?php endif; ?>
</div>

<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
