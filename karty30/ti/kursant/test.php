<?php
/**
 * karty30/ti/kursant/test.php — Rozwiązywanie testu przez kursanta + wynik.
 * Pytania zamknięte oceniane automatycznie; otwarte czekają na ocenę prowadzącego.
 * Dostępność: każde pytanie w <fieldset>/<legend>, etykiety przy każdym wariancie.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/functions.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/karty30.php';
require_once __DIR__ . '/auth.php';

karty30_migrate();
$student = student_require();
$client  = db_one("SELECT * FROM k30_clients WHERE id=?", [$student['client_id']]) ?: [];

$test_id = (int)($_GET['test'] ?? ($_POST['test_id'] ?? 0));
$test    = $test_id ? k30_ti_test_get($test_id) : null;

// Test musi istnieć, być aktywny i dotyczyć kursu, na który kursant jest zapisany
$enrolled_courses = array_map(fn($c)=>(int)$c['course_id'], k30_ti_client_courses((int)$student['client_id']));
if (!$test || empty($test['is_active']) || !in_array((int)$test['course_id'], $enrolled_courses, true)) {
    header('Location: index.php?tab=testy&err=unavailable'); exit;
}

$done    = isset($_GET['done']);
$started = isset($_GET['started']);
$mode    = in_array($_GET['mode'] ?? '', ['all', 'paged'], true) ? $_GET['mode'] : 'all';

// Szybkie dane o bazie pytań (do ekranu startowego)
$fixed_count = count(k30_ti_test_fixed_questions($test_id));
$bank_count  = count(k30_ti_test_bank_questions($test_id));
$bank_draw_n = (int)($test['bank_draw'] ?? 0);
$est_q       = $bank_draw_n > 0 && $bank_count > 0
    ? $fixed_count + min($bank_draw_n, $bank_count)
    : ($fixed_count + $bank_count); // gdy brak losowania: wszystkie

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals(student_token(), (string)($_POST['_token'] ?? ''))) { http_response_code(403); exit('Nieprawidłowy token sesji.'); }
    $attempt_id  = k30_ti_test_start_attempt($test_id, (int)$student['client_id']);
    $attempt_row = k30_ti_test_attempt_get($attempt_id);
    $questions   = $attempt_row ? k30_ti_test_questions_for_attempt($attempt_row) : [];
    if ($questions) {
        $answers = [];
        foreach ($questions as $q) {
            $qid = (int)$q['id'];
            if ($q['type'] === 'open') {
                $answers[$qid] = ['text' => $_POST['open'][$qid] ?? ''];
            } else {
                $v = $_POST['q'][$qid] ?? [];
                $answers[$qid] = ['option_ids' => is_array($v) ? $v : [$v]];
            }
        }
        k30_ti_test_submit($attempt_id, $answers);
    }
    header('Location: test.php?test='.$test_id.'&done=1'); exit;
}

$last = null;
if ($done) {
    $last = db_one("SELECT * FROM k30_ti_test_attempts WHERE test_id=? AND client_id=? AND status IN ('submitted','graded') ORDER BY id DESC LIMIT 1", [$test_id, (int)$student['client_id']]);
}

// Pobierz pytania dla wyświetlenia testu
if ($started && !$done) {
    // Jeśli jest trwające podejście — użyj jego drawn_ids
    $open_att = db_one("SELECT * FROM k30_ti_test_attempts WHERE test_id=? AND client_id=? AND status='in_progress' ORDER BY id DESC LIMIT 1", [$test_id, (int)$student['client_id']]);
    if ($open_att) {
        $questions = k30_ti_test_questions_for_attempt($open_att);
    } else {
        // Nowe podejście zostanie otwarte przy submicie; pokaż pytania domyślnie
        $questions = k30_ti_test_questions($test_id);
    }
    // Kolejność pytań (opcjonalnie losowa) — tylko gdy brak drawn_ids
    if (!empty($test['shuffle']) && (!$open_att || empty($open_att['drawn_ids']))) shuffle($questions);
} else {
    $questions = k30_ti_test_questions($test_id);
}

$tok       = student_token();
$org       = defined('ORG_NAME') ? ORG_NAME : 'Zajęcia TI';
$KP_TITLE  = $test['title'];
$KP_TOPBAR = ['brand'=>$org, 'icon'=>'card-checklist', 'user'=>$client['name'] ?? '', 'logout'=>'index.php?logout=1'];
include __DIR__ . '/_layout_head.php';
?>

<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb">
  <li class="breadcrumb-item"><a href="index.php?tab=testy">Testy</a></li>
  <li class="breadcrumb-item active"><?= h($test['title']) ?></li>
</ol></nav>

<?php if (!$done && !$started && $questions): ?>

  <div class="card border-0 shadow-sm" style="max-width:520px;margin:0 auto">
    <div class="card-body py-4 px-4">
      <h1 class="h5 fw-bold mb-1"><?= h($test['title']) ?></h1>
      <p class="text-body-secondary small mb-3">
        <?= $est_q ?> pytań<?php if ($bank_draw_n > 0 && $bank_count > 0): ?> <span class="text-info">(losowane z bazy)</span><?php endif; ?><?php if ((int)$test['time_limit_min']>0): ?> · <?= (int)$test['time_limit_min'] ?> min<?php endif; ?>
      </p>
      <?php if (trim((string)$test['description']) !== ''): ?>
      <div class="alert alert-info py-2"><i class="bi bi-info-circle me-1" aria-hidden="true"></i><?= nl2br(h($test['description'])) ?></div>
      <?php endif; ?>
      <p class="fw-semibold mb-3">Jak chcesz widzieć pytania?</p>
      <div class="d-grid gap-2">
        <a href="test.php?test=<?= $test_id ?>&started=1&mode=all"
           class="btn btn-outline-primary text-start d-flex align-items-center gap-3 py-3 px-3">
          <i class="bi bi-list-ul fs-4 text-primary flex-shrink-0" aria-hidden="true"></i>
          <span>
            <strong class="d-block">Wszystkie na 1 stronie</strong>
            <span class="text-body-secondary small">Widzisz wszystkie pytania jednocześnie — możesz swobodnie przewijać.</span>
          </span>
        </a>
        <a href="test.php?test=<?= $test_id ?>&started=1&mode=paged"
           class="btn btn-outline-primary text-start d-flex align-items-center gap-3 py-3 px-3">
          <i class="bi bi-file-earmark-text fs-4 text-primary flex-shrink-0" aria-hidden="true"></i>
          <span>
            <strong class="d-block">Każde pytanie na osobnej</strong>
            <span class="text-body-secondary small">Jedno pytanie na raz — przechodzisz dalej przyciskiem.</span>
          </span>
        </a>
      </div>
      <div class="mt-3 text-center">
        <a href="index.php?tab=testy" class="btn btn-link btn-sm text-secondary">Anuluj</a>
      </div>
    </div>
  </div>

<?php elseif ($done && $last):
  $mx  = (float)$last['max_score'];
  $pct = $mx > 0 ? round(100 * (float)$last['score'] / $mx) : 0;
  $review = (int)$last['needs_review'] === 1;
  $passed = (int)$test['pass_pct'] === 0 || $pct >= (int)$test['pass_pct'];
?>
  <div class="card border-0 shadow-sm">
    <div class="card-body text-center py-4">
      <div class="display-6 mb-2">
        <i class="bi bi-<?= $review ? 'hourglass-split text-info' : ($passed ? 'check-circle-fill text-success' : 'x-circle-fill text-secondary') ?>" aria-hidden="true"></i>
      </div>
      <h1 class="h4 fw-bold mb-2"><?= h($test['title']) ?></h1>
      <?php if ($review): ?>
        <p class="mb-1">Test wysłany. Część pytań (otwarte) czeka na ocenę prowadzącego.</p>
        <p class="text-body-secondary">Punkty z pytań zamkniętych: <strong><?= rtrim(rtrim(number_format((float)$last['score'],2,'.',''),'0'),'.') ?>/<?= rtrim(rtrim(number_format($mx,2,'.',''),'0'),'.') ?></strong></p>
      <?php else: ?>
        <p class="fs-5 mb-1">Twój wynik: <strong><?= $pct ?>%</strong>
          (<?= rtrim(rtrim(number_format((float)$last['score'],2,'.',''),'0'),'.') ?>/<?= rtrim(rtrim(number_format($mx,2,'.',''),'0'),'.') ?> pkt)</p>
        <?php if ((int)$test['pass_pct'] > 0): ?>
          <p class="<?= $passed ? 'text-success' : 'text-secondary' ?> fw-semibold">
            <?= $passed ? 'Zaliczono' : 'Nie zaliczono' ?> (próg <?= (int)$test['pass_pct'] ?>%)
          </p>
        <?php endif; ?>
      <?php endif; ?>
      <a href="index.php?tab=testy" class="btn btn-outline-secondary mt-2"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Wróć do testów</a>
    </div>
  </div>

<?php elseif (!$questions): ?>
  <div class="alert alert-secondary">Ten test nie ma jeszcze pytań.</div>
  <a href="index.php?tab=testy" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Wróć</a>

<?php else: ?>
  <?php if (trim((string)$test['description']) !== ''): ?>
  <div class="alert alert-info"><i class="bi bi-info-circle me-1" aria-hidden="true"></i><?= nl2br(h($test['description'])) ?></div>
  <?php endif; ?>
  <p class="text-body-secondary small">
    <?= count($questions) ?> pytań · <?= rtrim(rtrim(number_format($max_score = k30_ti_test_max_score($test_id),2,'.',''),'0'),'.') ?> pkt<?php if ((int)$test['time_limit_min']>0): ?> · sugerowany czas <?= (int)$test['time_limit_min'] ?> min<?php endif; ?>
  </p>

  <?php $n_q = count($questions); ?>
  <form method="post" id="test-form">
    <input type="hidden" name="_token" value="<?= h($tok) ?>">
    <input type="hidden" name="test_id" value="<?= $test_id ?>">
    <?php foreach ($questions as $i => $q):
      $qid  = (int)$q['id'];
      $opts = $q['type'] !== 'open' ? k30_ti_test_options($qid) : [];
      $hidden = ($mode === 'paged' && $i > 0) ? ' style="display:none"' : '';
    ?>
    <fieldset class="card border-0 shadow-sm mb-3 test-question" data-qi="<?= $i ?>"<?= $hidden ?>>
      <div class="card-body">
        <?php if ($mode === 'paged'): ?>
        <div class="text-body-secondary small mb-2">Pytanie <?= $i+1 ?> z <?= $n_q ?></div>
        <?php endif; ?>
        <legend class="h6 fw-bold mb-3">
          <?php if ($mode === 'all'): ?><span class="text-primary me-1"><?= $i+1 ?>.</span><?php endif; ?>
          <?= h($q['prompt']) ?>
          <span class="badge text-bg-light text-dark border ms-1"><?= rtrim(rtrim(number_format((float)$q['points'],2,'.',''),'0'),'.') ?> pkt</span>
          <?php if ($q['type']==='multi'): ?><span class="d-block text-body-secondary small fw-normal mt-1">Można zaznaczyć więcej niż jedną odpowiedź.</span><?php endif; ?>
        </legend>
        <?php if ($q['type'] === 'open'): ?>
          <label class="form-label" for="open-<?= $qid ?>">Twoja odpowiedź</label>
          <textarea class="form-control" id="open-<?= $qid ?>" name="open[<?= $qid ?>]" rows="4"></textarea>
        <?php else: foreach ($opts as $oi => $o):
            $type = $q['type']==='single' ? 'radio' : 'checkbox';
            $name = $q['type']==='single' ? "q[$qid]" : "q[$qid][]";
            $oid  = "opt-$qid-".(int)$o['id'];
        ?>
          <div class="form-check">
            <input class="form-check-input" type="<?= $type ?>" name="<?= $name ?>" id="<?= $oid ?>" value="<?= (int)$o['id'] ?>">
            <label class="form-check-label" for="<?= $oid ?>"><?= h($o['label']) ?></label>
          </div>
        <?php endforeach; endif; ?>

        <?php if ($mode === 'paged'): ?>
        <div class="d-flex gap-2 mt-3">
          <?php if ($i > 0): ?>
          <button type="button" class="btn btn-outline-secondary paged-prev"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Wstecz</button>
          <?php endif; ?>
          <?php if ($i < $n_q - 1): ?>
          <button type="button" class="btn btn-primary paged-next ms-auto">Dalej<i class="bi bi-arrow-right ms-1" aria-hidden="true"></i></button>
          <?php else: ?>
          <button type="submit" class="btn btn-success ms-auto"><i class="bi bi-send me-1" aria-hidden="true"></i>Wyślij odpowiedzi</button>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </div>
    </fieldset>
    <?php endforeach; ?>

    <?php if ($mode === 'all'): ?>
    <div class="d-flex gap-2 mb-4">
      <button type="submit" class="btn btn-primary btn-lg"><i class="bi bi-send me-1" aria-hidden="true"></i>Wyślij odpowiedzi</button>
      <a href="index.php?tab=testy" class="btn btn-outline-secondary btn-lg">Anuluj</a>
    </div>
    <?php endif; ?>
  </form>

  <?php if ($mode === 'paged'): ?>
  <script>
  (function(){
    var cards = Array.from(document.querySelectorAll('.test-question'));
    function show(i) {
      cards.forEach(function(c){ c.style.display = (+c.dataset.qi === i) ? '' : 'none'; });
      window.scrollTo({top: 0, behavior: 'smooth'});
    }
    document.getElementById('test-form').addEventListener('click', function(e){
      var btn = e.target.closest('.paged-next, .paged-prev');
      if (!btn) return;
      var card = btn.closest('.test-question');
      var cur  = +card.dataset.qi;
      if (btn.classList.contains('paged-next')) show(cur + 1);
      else show(cur - 1);
    });
  })();
  </script>
  <?php endif; ?>
<?php endif; ?>

<?php include __DIR__ . '/_layout_foot.php'; ?>
