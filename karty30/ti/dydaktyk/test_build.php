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
    if ($_cc_msg = ti_course_closed_guard($_POST + $_GET)) {
        flash_set('danger', $_cc_msg);
        header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'index.php')); exit;
    }

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
        // Jeśli przyszedł z listy kursów (View B) — wracamy tam
        if (!empty($_POST['_return_course'])) {
            header('Location: test_build.php?course_id='.(int)$test['course_id']); exit;
        }
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
// Fallback: jeśli k30_ti_course_get zawiodło (np. brak kolumny w starym schema),
// użyj danych z już wczytanej listy kursów — zapobiega powrotowi do Widoku A
if (!$course && $course_id) {
    foreach ($my_courses as $_c) {
        if ((int)$_c['id'] === $course_id) { $course = $_c; break; }
    }
}

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
$_pts = fn($v) => rtrim(rtrim(number_format((float)$v,2,'.',''),'0'),'.');
?>
<style>
.tb-q-card { transition: box-shadow .15s; }
.tb-q-card:hover { box-shadow: 0 0 0 2px var(--bs-primary) !important; }
.tb-q-card.tb-editing { box-shadow: 0 0 0 2px var(--bs-warning) !important; background: var(--bs-warning-bg-subtle); }
.tb-opts-list .opt-row + .opt-row { margin-top: .35rem; }
@media (min-width:992px){ #q-editor-col { position:sticky; top:1rem; } }
.tb-test-card { transition: box-shadow .15s; }
.tb-test-card:hover { box-shadow: 0 2px 12px rgba(0,0,0,.12) !important; }
</style>

<div class="container-xxl py-3">

<!-- BREADCRUMB -->
<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb small mb-0">
  <li class="breadcrumb-item">
    <a href="index.php<?= $course_id ? '?tab=testy&amp;course_id='.$course_id : '' ?>">
      <i class="bi bi-house me-1" aria-hidden="true"></i>Panel
    </a>
  </li>
  <?php if ($course): ?>
  <li class="breadcrumb-item"><a href="test_build.php?course_id=<?= $course_id ?>">Testy — <?= h($course['name']) ?></a></li>
  <?php endif; ?>
  <?php if ($test): ?><li class="breadcrumb-item active" aria-current="page"><?= h($test['title']) ?></li><?php endif; ?>
</ol></nav>

<?= flash_html() ?>

<?php /* ══════════════════════════════════════════════════════════════
   WIDOK A: brak kursu — wybierz kurs
   ══════════════════════════════════════════════════════════════════ */
if (!$test && !$course): ?>

<div class="row justify-content-center">
  <div class="col-sm-8 col-md-6 col-lg-5">
    <div class="card border-0 shadow-sm">
      <div class="card-header fw-semibold">
        <i class="bi bi-card-checklist me-2 text-primary" aria-hidden="true"></i>Wybierz kurs
      </div>
      <?php if (!$my_courses): ?>
      <div class="card-body text-muted small">Brak przypisanych kursów.</div>
      <?php else: ?>
      <div class="list-group list-group-flush">
        <?php foreach ($my_courses as $_mc):
          $n_tests = count(k30_ti_tests_list((int)$_mc['id']));
        ?>
        <a href="test_build.php?course_id=<?= (int)$_mc['id'] ?>"
           class="list-group-item list-group-item-action d-flex align-items-center gap-3 py-3">
          <i class="bi bi-mortarboard fs-5 text-primary flex-shrink-0" aria-hidden="true"></i>
          <div class="flex-grow-1 min-width-0">
            <div class="fw-semibold"><?= h($_mc['name']) ?></div>
          </div>
          <span class="badge bg-secondary rounded-pill"><?= $n_tests ?></span>
          <i class="bi bi-chevron-right text-body-secondary" aria-hidden="true"></i>
        </a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php /* ══════════════════════════════════════════════════════════════
   WIDOK B: lista testów kursu (+ formularz nowego/edycji)
   ══════════════════════════════════════════════════════════════════ */
elseif (!$test): ?>

<div class="d-flex align-items-center gap-3 mb-3 flex-wrap">
  <a href="index.php?tab=testy&amp;course_id=<?= $course_id ?>" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Wróć do panelu
  </a>
  <div>
    <h4 class="fw-bold mb-0"><i class="bi bi-card-checklist text-primary me-2" aria-hidden="true"></i><?= h($course['name']) ?></h4>
    <p class="text-body-secondary small mb-0">Testy i sprawdziany — zarządzaj pytaniami i widocznością</p>
  </div>
  <a href="test_build.php?course_id=<?= $course_id ?>#test-form" class="btn btn-primary ms-auto">
    <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Nowy test
  </a>
</div>

<div class="row g-4">

  <!-- Lista testów -->
  <div class="col-lg-7">
    <?php if (!$course_tests): ?>
    <div class="card border-0 shadow-sm text-center py-5">
      <div class="card-body">
        <i class="bi bi-clipboard-x fs-1 text-body-tertiary" aria-hidden="true"></i>
        <p class="mt-3 mb-1 fw-semibold">Brak testów w tym kursie</p>
        <p class="text-body-secondary small">Utwórz pierwszy test w formularzu obok.</p>
        <a href="#test-form" class="btn btn-outline-primary btn-sm">
          <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Utwórz test
        </a>
      </div>
    </div>
    <?php else: ?>
    <div class="d-flex flex-column gap-3">
    <?php foreach ($course_tests as $ct):
      $active = !empty($ct['is_active']);
      $n_q    = (int)$ct['n_questions'];
    ?>
    <div class="card border-0 shadow-sm tb-test-card">
      <div class="card-body py-3">
        <div class="d-flex align-items-start gap-3">
          <div class="flex-grow-1 min-width-0">
            <div class="d-flex align-items-center gap-2 flex-wrap mb-1">
              <a href="test_build.php?test_id=<?= (int)$ct['id'] ?>" class="fw-bold text-decoration-none fs-6"><?= h($ct['title']) ?></a>
              <?php if ($active): ?>
                <span class="badge text-bg-success"><i class="bi bi-eye-fill me-1" aria-hidden="true"></i>Widoczny</span>
              <?php else: ?>
                <span class="badge text-bg-secondary"><i class="bi bi-eye-slash me-1" aria-hidden="true"></i>Ukryty</span>
              <?php endif; ?>
            </div>
            <div class="d-flex flex-wrap gap-2 small text-body-secondary">
              <span><i class="bi bi-question-circle me-1" aria-hidden="true"></i><?= $n_q ?> <?= $n_q===1?'pytanie':($n_q<5?'pytania':'pytań') ?></span>
              <?php if ((int)$ct['time_limit_min']>0): ?>
              <span><i class="bi bi-stopwatch me-1" aria-hidden="true"></i><?= (int)$ct['time_limit_min'] ?> min</span>
              <?php endif; ?>
              <?php if ((int)$ct['pass_pct']>0): ?>
              <span><i class="bi bi-award me-1" aria-hidden="true"></i>próg <?= (int)$ct['pass_pct'] ?>%</span>
              <?php endif; ?>
              <?php if (!empty($ct['shuffle'])): ?>
              <span><i class="bi bi-shuffle me-1" aria-hidden="true"></i>losowe</span>
              <?php endif; ?>
            </div>
          </div>
          <div class="d-flex gap-1 flex-shrink-0">
            <a href="test_build.php?test_id=<?= (int)$ct['id'] ?>" class="btn btn-sm btn-outline-primary" title="Edytuj pytania">
              <i class="bi bi-pencil-square" aria-hidden="true"></i>
            </a>
            <a href="test_build.php?course_id=<?= $course_id ?>&amp;test_id=<?= (int)$ct['id'] ?>&amp;edit_meta=1#test-form"
               class="btn btn-sm btn-outline-secondary" title="Ustawienia">
              <i class="bi bi-gear" aria-hidden="true"></i>
            </a>
            <form method="post" class="d-inline">
              <input type="hidden" name="_token"         value="<?= h(dyd_token()) ?>">
              <input type="hidden" name="_op"            value="toggle_active">
              <input type="hidden" name="test_id"        value="<?= (int)$ct['id'] ?>">
              <input type="hidden" name="_return_course" value="1">
              <button class="btn btn-sm <?= $active ? 'btn-outline-warning' : 'btn-outline-success' ?>"
                      title="<?= $active ? 'Ukryj' : 'Udostępnij' ?>">
                <i class="bi bi-<?= $active ? 'eye-slash' : 'eye' ?>" aria-hidden="true"></i>
              </button>
            </form>
          </div>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <!-- Formularz: nowy/edycja testu -->
  <div class="col-lg-5">
    <div class="card border-0 shadow-sm" id="test-form">
      <div class="card-header fw-semibold">
        <i class="bi bi-<?= ($edit_meta && $test) ? 'gear' : 'plus-lg' ?> me-2" aria-hidden="true"></i><?= ($edit_meta && $test) ? 'Ustawienia testu' : 'Nowy test' ?>
      </div>
      <div class="card-body">
        <form method="post">
          <input type="hidden" name="_token"   value="<?= h(dyd_token()) ?>">
          <input type="hidden" name="_op"      value="save_test">
          <input type="hidden" name="test_id"  value="<?= (int)$meta_f['id'] ?>">
          <input type="hidden" name="course_id" value="<?= $course_id ?>">

          <div class="mb-3">
            <label class="form-label fw-semibold" for="t-title">Tytuł <span class="text-danger" aria-hidden="true">*</span></label>
            <input type="text" class="form-control" id="t-title" name="title"
                   value="<?= h($meta_f['title']) ?>" required maxlength="255"
                   placeholder="np. Sprawdzian — lekcja 5">
          </div>

          <div class="mb-3">
            <label class="form-label small" for="t-desc">Instrukcja dla kursanta</label>
            <textarea class="form-control form-control-sm" id="t-desc" name="description"
                      rows="2" placeholder="Widoczna przed rozpoczęciem testu"><?= h($meta_f['description']) ?></textarea>
          </div>

          <div class="row g-2 mb-3">
            <div class="col-6">
              <label class="form-label small fw-semibold" for="t-time">Limit czasu <span class="text-muted fw-normal">(min)</span></label>
              <input type="number" class="form-control form-control-sm" id="t-time" name="time_limit_min"
                     min="0" max="600" value="<?= (int)$meta_f['time_limit_min'] ?: '' ?>" placeholder="0 = brak">
            </div>
            <div class="col-6">
              <label class="form-label small fw-semibold" for="t-pass">Próg zaliczenia <span class="text-muted fw-normal">(%)</span></label>
              <input type="number" class="form-control form-control-sm" id="t-pass" name="pass_pct"
                     min="0" max="100" value="<?= (int)$meta_f['pass_pct'] ?: '' ?>" placeholder="0 = brak">
            </div>
            <div class="col-6">
              <label class="form-label small fw-semibold" for="t-retake">Próg poprawki <span class="text-muted fw-normal">(%)</span></label>
              <input type="number" class="form-control form-control-sm" id="t-retake" name="retake_pass_pct"
                     min="0" max="100" value="<?= (int)($meta_f['retake_pass_pct'] ?? 0) ?: '' ?>" placeholder="0 = j.w.">
            </div>
          </div>

          <div class="d-flex flex-column gap-1 mb-3">
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" role="switch" name="shuffle" id="t-shuffle" value="1"
                     <?= !empty($meta_f['shuffle']) ? 'checked' : '' ?>>
              <label class="form-check-label small" for="t-shuffle">Losowa kolejność pytań</label>
            </div>
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" role="switch" name="sync_grade" id="t-sync" value="1"
                     <?= !empty($meta_f['sync_grade']) ? 'checked' : '' ?>>
              <label class="form-check-label small" for="t-sync">Zapisuj wynik do e-dziennika</label>
            </div>
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" role="switch" name="is_active" id="t-active" value="1"
                     <?= !empty($meta_f['is_active']) ? 'checked' : '' ?>>
              <label class="form-check-label small" for="t-active">Udostępnij kursantom</label>
            </div>
          </div>

          <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary">
              <?= ($edit_meta && $test) ? 'Zapisz zmiany' : 'Utwórz test' ?>
            </button>
            <?php if ($edit_meta && $test): ?>
            <a href="test_build.php?test_id=<?= $test_id ?>" class="btn btn-outline-secondary">Anuluj</a>
            <?php endif; ?>
          </div>
        </form>
      </div>
    </div>
  </div>

</div><!-- /row B -->

<?php /* ══════════════════════════════════════════════════════════════
   WIDOK C: edytor pytań (mamy test_id)
   ══════════════════════════════════════════════════════════════════ */
else:
  $max_score = k30_ti_test_max_score($test_id);
  $n_total   = count($fixed_qs) + count($bank_qs);
?>

<!-- Toolbar testu -->
<div class="card border-0 shadow-sm mb-3">
  <div class="card-body py-2 px-3 d-flex align-items-center flex-wrap gap-2">
    <div class="flex-grow-1 min-width-0">
      <span class="fw-bold"><?= h($test['title']) ?></span>
      <span class="ms-2 text-body-secondary small">
        <?= $n_total ?> pytań ·
        <?= $_pts($max_score) ?> pkt maks.
        <?php if ((int)$test['time_limit_min']>0): ?> · <i class="bi bi-stopwatch" aria-hidden="true"></i> <?= (int)$test['time_limit_min'] ?> min<?php endif; ?>
        <?php if ((int)$test['pass_pct']>0): ?> · próg <?= (int)$test['pass_pct'] ?>%<?php endif; ?>
      </span>
    </div>
    <div class="d-flex gap-2 flex-shrink-0 flex-wrap">
      <?php if (!empty($test['is_active'])): ?>
        <span class="badge text-bg-success align-self-center"><i class="bi bi-eye-fill me-1" aria-hidden="true"></i>Widoczny</span>
        <form method="post" class="d-inline">
          <input type="hidden" name="_token"  value="<?= h(dyd_token()) ?>">
          <input type="hidden" name="_op"     value="toggle_active">
          <input type="hidden" name="test_id" value="<?= $test_id ?>">
          <button class="btn btn-sm btn-outline-warning"><i class="bi bi-eye-slash me-1" aria-hidden="true"></i>Ukryj</button>
        </form>
      <?php else: ?>
        <span class="badge text-bg-secondary align-self-center"><i class="bi bi-eye-slash me-1" aria-hidden="true"></i>Ukryty</span>
        <form method="post" class="d-inline">
          <input type="hidden" name="_token"  value="<?= h(dyd_token()) ?>">
          <input type="hidden" name="_op"     value="toggle_active">
          <input type="hidden" name="test_id" value="<?= $test_id ?>">
          <button class="btn btn-sm btn-outline-success"><i class="bi bi-eye me-1" aria-hidden="true"></i>Udostępnij</button>
        </form>
      <?php endif; ?>
      <?php if ($edit_meta): ?>
      <a href="test_build.php?test_id=<?= $test_id ?>"
         class="btn btn-sm btn-secondary"><i class="bi bi-x me-1" aria-hidden="true"></i>Zamknij ustawienia</a>
      <?php else: ?>
      <a href="test_build.php?course_id=<?= $course_id ?>&amp;test_id=<?= $test_id ?>&amp;edit_meta=1"
         class="btn btn-sm btn-outline-secondary"><i class="bi bi-gear me-1" aria-hidden="true"></i>Ustawienia</a>
      <?php endif; ?>
      <a href="index.php?tab=testy&amp;course_id=<?= $course_id ?>"
         class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Wróć do panelu</a>
    </div>
  </div>
</div>

<?php if ($edit_meta): ?>
<!-- Formularz ustawień testu (edit_meta=1) -->
<div class="card border-0 shadow-sm border-top border-secondary mb-4">
  <div class="card-header fw-semibold bg-body-secondary">
    <i class="bi bi-gear me-2" aria-hidden="true"></i>Ustawienia testu
  </div>
  <div class="card-body">
    <form method="post">
      <input type="hidden" name="_token"    value="<?= h(dyd_token()) ?>">
      <input type="hidden" name="_op"       value="save_test">
      <input type="hidden" name="test_id"   value="<?= $test_id ?>">
      <input type="hidden" name="course_id" value="<?= $course_id ?>">

      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label fw-semibold" for="ms-title">Tytuł <span class="text-danger" aria-hidden="true">*</span></label>
          <input type="text" class="form-control" id="ms-title" name="title"
                 value="<?= h($test['title']) ?>" required maxlength="255">
        </div>
        <div class="col-md-6">
          <label class="form-label small" for="ms-desc">Instrukcja dla kursanta</label>
          <textarea class="form-control form-control-sm" id="ms-desc" name="description"
                    rows="1"><?= h($test['description'] ?? '') ?></textarea>
        </div>
        <div class="col-4 col-md-2">
          <label class="form-label small fw-semibold" for="ms-time">Limit czasu <span class="text-muted fw-normal">(min)</span></label>
          <input type="number" class="form-control form-control-sm" id="ms-time" name="time_limit_min"
                 min="0" max="600" value="<?= (int)($test['time_limit_min'] ?? 0) ?: '' ?>" placeholder="0=brak">
        </div>
        <div class="col-4 col-md-2">
          <label class="form-label small fw-semibold" for="ms-pass">Próg <span class="text-muted fw-normal">(%)</span></label>
          <input type="number" class="form-control form-control-sm" id="ms-pass" name="pass_pct"
                 min="0" max="100" value="<?= (int)($test['pass_pct'] ?? 0) ?: '' ?>" placeholder="0=brak">
        </div>
        <div class="col-4 col-md-2">
          <label class="form-label small fw-semibold" for="ms-retake">Próg poprawki <span class="text-muted fw-normal">(%)</span></label>
          <input type="number" class="form-control form-control-sm" id="ms-retake" name="retake_pass_pct"
                 min="0" max="100" value="<?= (int)($test['retake_pass_pct'] ?? 0) ?: '' ?>" placeholder="0=j.w.">
        </div>
        <div class="col-md-6 d-flex flex-column gap-1 justify-content-end">
          <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" role="switch" name="shuffle" id="ms-shuffle" value="1"
                   <?= !empty($test['shuffle']) ? 'checked' : '' ?>>
            <label class="form-check-label small" for="ms-shuffle">Losowa kolejność pytań</label>
          </div>
          <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" role="switch" name="sync_grade" id="ms-sync" value="1"
                   <?= !empty($test['sync_grade']) ? 'checked' : '' ?>>
            <label class="form-check-label small" for="ms-sync">Zapisuj wynik do e-dziennika</label>
          </div>
          <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" role="switch" name="is_active" id="ms-active" value="1"
                   <?= !empty($test['is_active']) ? 'checked' : '' ?>>
            <label class="form-check-label small" for="ms-active">Udostępnij kursantom</label>
          </div>
        </div>
      </div>
      <div class="mt-3 d-flex gap-2">
        <button type="submit" class="btn btn-primary">
          <i class="bi bi-save me-1" aria-hidden="true"></i>Zapisz zmiany
        </button>
        <a href="test_build.php?test_id=<?= $test_id ?>" class="btn btn-outline-secondary">Anuluj</a>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php if ($to_review): ?>
<!-- Alert: do oceny -->
<div class="alert alert-warning d-flex align-items-center gap-3 mb-4" role="alert">
  <i class="bi bi-clipboard-check-fill fs-4 flex-shrink-0" aria-hidden="true"></i>
  <div class="flex-grow-1">
    <strong><?= count($to_review) ?> podejść czeka na ocenę</strong> — pytania otwarte wymają ręcznej weryfikacji.
  </div>
  <a href="#review" class="btn btn-sm btn-warning flex-shrink-0">Oceń</a>
</div>
<?php endif; ?>

<div class="row g-4" id="q-list">

  <!-- ── Lewa: pytania ── -->
  <div class="col-lg-7">

    <!-- Pytania stałe -->
    <div class="card border-0 shadow-sm mb-3" id="fixed">
      <div class="card-header d-flex align-items-center gap-2 fw-semibold">
        <i class="bi bi-list-ol text-primary" aria-hidden="true"></i>Pytania stałe
        <span class="badge bg-secondary ms-1"><?= count($fixed_qs) ?></span>
        <?php if ($fixed_draw > 0): ?><span class="badge bg-primary">losuj <?= $fixed_draw ?></span><?php endif; ?>
        <a href="#q-form" class="btn btn-sm btn-primary ms-auto py-0 px-2">
          <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Dodaj pytanie
        </a>
      </div>

      <?php if (!$fixed_qs): ?>
      <div class="card-body text-body-secondary small py-4 text-center">
        <i class="bi bi-question-circle fs-2 d-block mb-2" aria-hidden="true"></i>
        Brak pytań stałych — dodaj pierwsze używając formularza obok.
      </div>
      <?php else: ?>
      <div class="list-group list-group-flush" id="fixed-list">
        <?php foreach ($fixed_qs as $idx => $q):
          $opts     = $q['type'] !== 'open' ? k30_ti_test_options((int)$q['id']) : [];
          $editing  = $q_row && (int)$q_row['id'] === (int)$q['id'];
        ?>
        <div class="list-group-item p-3 tb-q-card <?= $editing ? 'tb-editing' : '' ?>" id="q-<?= (int)$q['id'] ?>">
          <div class="d-flex align-items-start gap-2">
            <span class="text-body-tertiary fw-semibold small mt-1" style="min-width:1.5rem"><?= $idx+1 ?>.</span>
            <div class="flex-grow-1 min-width-0">
              <div class="d-flex align-items-start gap-2 flex-wrap mb-1">
                <span class="badge bg-body-secondary text-body-secondary border"><?= h($QT[$q['type']] ?? $q['type']) ?></span>
                <span class="badge bg-body-secondary text-body-secondary border"><?= $_pts($q['points']) ?> pkt</span>
                <?php if ($editing): ?><span class="badge bg-warning text-dark">edytujesz</span><?php endif; ?>
              </div>
              <p class="mb-1 fw-semibold small"><?= h($q['prompt']) ?></p>
              <?php if ($q['type'] !== 'open' && $opts): ?>
              <ul class="mb-0 ps-3" style="font-size:.78rem">
                <?php foreach ($opts as $o): ?>
                <li class="<?= !empty($o['is_correct']) ? 'text-success fw-semibold' : 'text-body-secondary' ?>">
                  <?= !empty($o['is_correct']) ? '<i class="bi bi-check-circle-fill me-1" aria-hidden="true"></i>' : '' ?><?= h($o['label']) ?>
                </li>
                <?php endforeach; ?>
              </ul>
              <?php elseif ($q['type'] === 'open'): ?>
              <span class="small text-body-secondary"><i class="bi bi-pencil me-1" aria-hidden="true"></i>ocena ręczna</span>
              <?php endif; ?>
            </div>
            <div class="d-flex gap-1 flex-shrink-0 ms-1">
              <form method="post" class="d-flex gap-1">
                <input type="hidden" name="_token"      value="<?= h(dyd_token()) ?>">
                <input type="hidden" name="_op"         value="move_question">
                <input type="hidden" name="test_id"     value="<?= $test_id ?>">
                <input type="hidden" name="question_id" value="<?= (int)$q['id'] ?>">
                <button name="dir" value="up"   class="btn btn-xs btn-outline-secondary py-0 px-1" title="Wyżej" <?= $idx===0?'disabled':'' ?>><i class="bi bi-arrow-up" aria-hidden="true"></i></button>
                <button name="dir" value="down" class="btn btn-xs btn-outline-secondary py-0 px-1" title="Niżej" <?= $idx===count($fixed_qs)-1?'disabled':'' ?>><i class="bi bi-arrow-down" aria-hidden="true"></i></button>
              </form>
              <a href="?test_id=<?= $test_id ?>&amp;q=<?= (int)$q['id'] ?>#q-form" class="btn btn-xs btn-outline-primary py-0 px-1" title="Edytuj"><i class="bi bi-pencil" aria-hidden="true"></i></a>
              <form method="post" class="d-inline" onsubmit="return confirm('Usunąć pytanie?')">
                <input type="hidden" name="_token"      value="<?= h(dyd_token()) ?>">
                <input type="hidden" name="_op"         value="delete_question">
                <input type="hidden" name="test_id"     value="<?= $test_id ?>">
                <input type="hidden" name="question_id" value="<?= (int)$q['id'] ?>">
                <button class="btn btn-xs btn-outline-danger py-0 px-1" title="Usuń"><i class="bi bi-trash" aria-hidden="true"></i></button>
              </form>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <div class="card-footer py-2">
        <form method="post" class="d-flex align-items-center gap-2 flex-wrap">
          <input type="hidden" name="_token"  value="<?= h(dyd_token()) ?>">
          <input type="hidden" name="_op"     value="set_fixed_draw">
          <input type="hidden" name="test_id" value="<?= $test_id ?>">
          <div class="form-check form-switch mb-0">
            <input class="form-check-input" type="checkbox" role="switch" id="fixed-draw-toggle"
                   <?= $fixed_draw > 0 ? 'checked' : '' ?>
                   onchange="document.getElementById('fixed-draw-wrap').style.display=this.checked?'flex':'none'">
            <label class="form-check-label small" for="fixed-draw-toggle">Losuj pytania stałe</label>
          </div>
          <span id="fixed-draw-wrap" class="d-flex align-items-center gap-1" style="display:<?= $fixed_draw>0?'flex':'none' ?>!important">
            <input type="number" class="form-control form-control-sm" name="fixed_draw"
                   min="1" max="<?= count($fixed_qs) ?>" value="<?= $fixed_draw ?: '' ?>"
                   style="width:5rem" placeholder="ile?">
            <span class="text-body-secondary small">z <?= count($fixed_qs) ?></span>
          </span>
          <button class="btn btn-sm btn-outline-primary"><i class="bi bi-save me-1" aria-hidden="true"></i>Zapisz</button>
        </form>
      </div>
      <?php endif; ?>
    </div>

    <!-- Baza pytań -->
    <div class="card border-0 shadow-sm mb-3" id="bank">
      <div class="card-header d-flex align-items-center gap-2 fw-semibold">
        <i class="bi bi-collection text-info" aria-hidden="true"></i>Baza pytań (losowane)
        <span class="badge bg-info text-dark ms-1"><?= count($bank_qs) ?></span>
        <?php if ($bank_draw > 0): ?><span class="badge bg-primary">losuj <?= $bank_draw ?></span><?php endif; ?>
      </div>
      <?php if (!$bank_qs): ?>
      <div class="card-body text-body-secondary small py-3 text-center">
        <i class="bi bi-collection fs-2 d-block mb-1 text-body-tertiary" aria-hidden="true"></i>
        Brak pytań w bazie. Zaznacz „Baza pytań" przy dodawaniu pytania.
      </div>
      <?php else: ?>
      <div class="list-group list-group-flush">
        <?php foreach ($bank_qs as $q):
          $opts    = $q['type'] !== 'open' ? k30_ti_test_options((int)$q['id']) : [];
          $editing = $q_row && (int)$q_row['id'] === (int)$q['id'];
        ?>
        <div class="list-group-item p-3 tb-q-card <?= $editing ? 'tb-editing' : '' ?>" id="q-<?= (int)$q['id'] ?>">
          <div class="d-flex align-items-start gap-2">
            <div class="flex-grow-1 min-width-0">
              <div class="d-flex align-items-center gap-2 flex-wrap mb-1">
                <span class="badge bg-info text-dark"><i class="bi bi-collection me-1" aria-hidden="true"></i>baza</span>
                <span class="badge bg-body-secondary text-body-secondary border"><?= h($QT[$q['type']] ?? $q['type']) ?></span>
                <span class="badge bg-body-secondary text-body-secondary border"><?= $_pts($q['points']) ?> pkt</span>
                <?php if ($editing): ?><span class="badge bg-warning text-dark">edytujesz</span><?php endif; ?>
              </div>
              <p class="mb-1 fw-semibold small"><?= h($q['prompt']) ?></p>
              <?php if ($q['type'] !== 'open' && $opts): ?>
              <ul class="mb-0 ps-3" style="font-size:.78rem">
                <?php foreach ($opts as $o): ?>
                <li class="<?= !empty($o['is_correct']) ? 'text-success fw-semibold' : 'text-body-secondary' ?>">
                  <?= !empty($o['is_correct']) ? '<i class="bi bi-check-circle-fill me-1" aria-hidden="true"></i>' : '' ?><?= h($o['label']) ?>
                </li>
                <?php endforeach; ?>
              </ul>
              <?php endif; ?>
            </div>
            <div class="d-flex gap-1 flex-shrink-0 ms-1">
              <a href="?test_id=<?= $test_id ?>&amp;q=<?= (int)$q['id'] ?>#q-form" class="btn btn-xs btn-outline-primary py-0 px-1" title="Edytuj"><i class="bi bi-pencil" aria-hidden="true"></i></a>
              <form method="post" class="d-inline" onsubmit="return confirm('Usunąć z bazy?')">
                <input type="hidden" name="_token"      value="<?= h(dyd_token()) ?>">
                <input type="hidden" name="_op"         value="delete_question">
                <input type="hidden" name="test_id"     value="<?= $test_id ?>">
                <input type="hidden" name="question_id" value="<?= (int)$q['id'] ?>">
                <button class="btn btn-xs btn-outline-danger py-0 px-1" title="Usuń"><i class="bi bi-trash" aria-hidden="true"></i></button>
              </form>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <div class="card-footer py-2">
        <form method="post" class="d-flex align-items-center gap-2 flex-wrap">
          <input type="hidden" name="_token"  value="<?= h(dyd_token()) ?>">
          <input type="hidden" name="_op"     value="set_bank_draw">
          <input type="hidden" name="test_id" value="<?= $test_id ?>">
          <label class="form-label mb-0 small fw-semibold" for="bank-draw-inp">Losuj z bazy:</label>
          <input type="number" class="form-control form-control-sm" id="bank-draw-inp" name="bank_draw"
                 min="0" max="9999" step="1" value="<?= $bank_draw ?>" style="width:6rem">
          <button class="btn btn-sm btn-outline-primary"><i class="bi bi-save me-1" aria-hidden="true"></i>Zapisz</button>
          <span class="text-body-secondary small">0 = wyłączone</span>
        </form>
      </div>
    </div>

    <!-- Ocenianie pytań otwartych -->
    <?php if ($to_review): ?>
    <div class="card border-0 shadow-sm border-warning" id="review">
      <div class="card-header fw-semibold bg-warning bg-opacity-10">
        <i class="bi bi-clipboard-check me-2 text-warning" aria-hidden="true"></i>Do oceny
        <span class="badge bg-warning text-dark ms-1"><?= count($to_review) ?></span>
      </div>
      <div class="card-body p-3 d-flex flex-column gap-3">
        <?php foreach ($to_review as $a):
          $ans = db_all(
            "SELECT ta.*, q.prompt, q.points, q.type FROM k30_ti_test_answers ta
             JOIN k30_ti_test_questions q ON q.id=ta.question_id
             WHERE ta.attempt_id=? AND q.type='open' ORDER BY q.position",
            [(int)$a['id']]);
        ?>
        <form method="post" class="border rounded p-3">
          <input type="hidden" name="_token"    value="<?= h(dyd_token()) ?>">
          <input type="hidden" name="_op"       value="grade_open">
          <input type="hidden" name="test_id"   value="<?= $test_id ?>">
          <input type="hidden" name="attempt_id" value="<?= (int)$a['id'] ?>">
          <div class="d-flex align-items-center gap-2 mb-3">
            <i class="bi bi-person-circle fs-5 text-body-secondary" aria-hidden="true"></i>
            <strong><?= h($a['client_name']) ?></strong>
            <span class="text-body-secondary small ms-auto">
              zamknięte: <?= $_pts($a['score']) ?>/<?= $_pts($a['max_score']) ?> pkt
            </span>
          </div>
          <?php foreach ($ans as $an): ?>
          <div class="mb-3">
            <p class="mb-1 small fw-semibold"><?= h($an['prompt']) ?>
              <span class="text-body-secondary fw-normal">(max <?= $_pts($an['points']) ?> pkt)</span>
            </p>
            <div class="rounded p-2 small mb-2 bg-body-secondary" style="white-space:pre-wrap;font-family:inherit">
              <?= $an['answer_text']!=='' ? h($an['answer_text']) : '<em class="text-body-tertiary">— brak odpowiedzi —</em>' ?>
            </div>
            <div class="d-flex align-items-center gap-2">
              <label class="form-label mb-0 small" for="pts-<?= (int)$a['id'] ?>-<?= (int)$an['question_id'] ?>">Punkty:</label>
              <input type="number" class="form-control form-control-sm" style="width:7rem"
                     id="pts-<?= (int)$a['id'] ?>-<?= (int)$an['question_id'] ?>"
                     name="points[<?= (int)$an['question_id'] ?>]"
                     min="0" max="<?= (float)$an['points'] ?>" step="0.5" value="0">
              <span class="text-body-secondary small">/ <?= $_pts($an['points']) ?></span>
            </div>
          </div>
          <?php endforeach; ?>
          <button class="btn btn-sm btn-warning fw-semibold">
            <i class="bi bi-check-lg me-1" aria-hidden="true"></i>Zapisz ocenę
          </button>
        </form>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
  </div><!-- /col-lg-7 -->

  <!-- ── Prawa: edytor pytania ── -->
  <div class="col-lg-5" id="q-editor-col">
    <div class="card border-0 shadow-sm" id="q-form">
      <div class="card-header fw-semibold <?= $q_row ? 'bg-warning bg-opacity-10' : '' ?>">
        <i class="bi bi-<?= $q_row ? 'pencil-square text-warning' : 'plus-circle text-primary' ?> me-2" aria-hidden="true"></i>
        <?= $q_row ? 'Edytuj pytanie' : 'Nowe pytanie' ?>
        <?php if ($q_row): ?>
        <a href="test_build.php?test_id=<?= $test_id ?>" class="btn btn-xs btn-outline-secondary float-end py-0 px-2">
          <i class="bi bi-x" aria-hidden="true"></i> Anuluj
        </a>
        <?php endif; ?>
      </div>
      <div class="card-body">
        <form method="post" id="qform">
          <input type="hidden" name="_token"      value="<?= h(dyd_token()) ?>">
          <input type="hidden" name="_op"          value="save_question">
          <input type="hidden" name="test_id"      value="<?= $test_id ?>">
          <input type="hidden" name="question_id"  value="<?= (int)$qf['id'] ?>">

          <div class="mb-3">
            <label class="form-label small fw-semibold" for="q-type">Typ pytania</label>
            <select class="form-select" id="q-type" name="type">
              <?php foreach ($QT as $slug => $lab): ?>
              <option value="<?= h($slug) ?>" <?= $qf['type']===$slug?'selected':'' ?>><?= h($lab) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="mb-3">
            <label class="form-label small fw-semibold" for="q-prompt">Treść pytania <span class="text-danger" aria-hidden="true">*</span></label>
            <textarea class="form-control" id="q-prompt" name="prompt" rows="3" required
                      placeholder="Wpisz treść pytania…"><?= h($qf['prompt']) ?></textarea>
          </div>

          <div class="row g-2 mb-3">
            <div class="col-6">
              <label class="form-label small fw-semibold" for="q-points">Punkty</label>
              <input type="number" class="form-control" id="q-points" name="points"
                     min="0" max="100" step="0.5"
                     value="<?= $_pts($qf['points']) ?: '1' ?>">
            </div>
            <div class="col-6 d-flex align-items-end pb-1">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" id="q-in-bank" name="in_bank" value="1"
                       <?= !empty($qf['in_bank']) ? 'checked' : '' ?>>
                <label class="form-check-label small" for="q-in-bank">
                  <i class="bi bi-collection me-1 text-info" aria-hidden="true"></i>Baza pytań
                </label>
              </div>
            </div>
          </div>

          <div id="opts-wrap" class="mb-3">
            <label class="form-label small fw-semibold">Warianty odpowiedzi</label>
            <p class="text-body-secondary" style="font-size:.75rem;margin-top:-.25rem">Zaznacz checkbox przy poprawnych odpowiedziach</p>
            <div id="opts-list" class="d-flex flex-column tb-opts-list">
              <?php
              $opt_rows = $q_opts;
              for ($i = count($opt_rows); $i < 4; $i++) $opt_rows[] = ['label'=>'','is_correct'=>0];
              foreach ($opt_rows as $i => $o): ?>
              <div class="input-group input-group-sm opt-row">
                <span class="input-group-text bg-body-secondary">
                  <input class="form-check-input mt-0" type="checkbox" name="opt_correct[<?= $i ?>]" value="1"
                         <?= !empty($o['is_correct'])?'checked':'' ?> aria-label="Poprawna odpowiedź">
                </span>
                <input type="text" class="form-control" name="opt_label[<?= $i ?>]"
                       value="<?= h($o['label']) ?>" placeholder="Treść wariantu <?= $i+1 ?>">
              </div>
              <?php endforeach; ?>
            </div>
            <button type="button" class="btn btn-sm btn-outline-secondary mt-2" id="add-opt">
              <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Dodaj wariant
            </button>
          </div>

          <div class="d-grid">
            <button type="submit" class="btn btn-<?= $q_row ? 'warning' : 'primary' ?> fw-semibold">
              <i class="bi bi-<?= $q_row ? 'save' : 'plus-circle' ?> me-1" aria-hidden="true"></i>
              <?= $q_row ? 'Zapisz zmiany' : 'Dodaj pytanie' ?>
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>

</div><!-- /row C -->

<script>
(function(){
  var typeSel  = document.getElementById('q-type');
  var optsWrap = document.getElementById('opts-wrap');
  var list     = document.getElementById('opts-list');
  var addBtn   = document.getElementById('add-opt');
  function syncType(){
    optsWrap.style.display = (typeSel.value === 'open') ? 'none' : '';
  }
  typeSel.addEventListener('change', syncType);
  syncType();
  addBtn.addEventListener('click', function(){
    var i = list.querySelectorAll('.opt-row').length;
    var row = document.createElement('div');
    row.className = 'input-group input-group-sm opt-row';
    row.innerHTML =
      '<span class="input-group-text bg-body-secondary">'
      + '<input class="form-check-input mt-0" type="checkbox" name="opt_correct['+i+']" value="1" aria-label="Poprawna odpowiedź">'
      + '</span>'
      + '<input type="text" class="form-control" name="opt_label['+i+']" placeholder="Treść wariantu '+(i+1)+'">';
    list.appendChild(row);
    row.querySelector('input[type=text]').focus();
  });
  // Scroll to form if editing a question
  <?php if ($q_row): ?>
  (function(){ var el = document.getElementById('q-form'); if(el) el.scrollIntoView({behavior:'smooth',block:'start'}); })();
  <?php endif; ?>
})();
</script>

<?php endif; // widok C ?>
</div><!-- /container -->

<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
