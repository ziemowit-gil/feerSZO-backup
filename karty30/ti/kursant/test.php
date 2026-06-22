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
    flash_set('danger', 'Test jest niedostępny.');
    header('Location: index.php?tab=testy'); exit;
}

$questions = k30_ti_test_questions($test_id);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals(student_token(), (string)($_POST['_token'] ?? ''))) { http_response_code(403); exit('Nieprawidłowy token sesji.'); }
    if ($questions) {
        $attempt = k30_ti_test_start_attempt($test_id, (int)$student['client_id']);
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
        k30_ti_test_submit($attempt, $answers);
    }
    header('Location: test.php?test='.$test_id.'&done=1'); exit;
}

$done = isset($_GET['done']);
$last = $done ? k30_ti_test_best_attempt($test_id, (int)$student['client_id']) : null;
// dla widoku wyniku bierzemy najświeższe podejście
if ($done) {
    $last = db_one("SELECT * FROM k30_ti_test_attempts WHERE test_id=? AND client_id=? AND status IN ('submitted','graded') ORDER BY id DESC LIMIT 1", [$test_id, (int)$student['client_id']]);
}

// Kolejność pytań (opcjonalnie losowa)
if (!empty($test['shuffle']) && !$done) shuffle($questions);

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

<?= flash_html() ?>

<?php if ($done && $last):
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

  <form method="post" id="test-form">
    <input type="hidden" name="_token" value="<?= h($tok) ?>">
    <input type="hidden" name="test_id" value="<?= $test_id ?>">
    <?php foreach ($questions as $i => $q):
      $qid = (int)$q['id']; $opts = $q['type'] !== 'open' ? k30_ti_test_options($qid) : [];
    ?>
    <fieldset class="card border-0 shadow-sm mb-3">
      <div class="card-body">
        <legend class="h6 fw-bold mb-3">
          <span class="text-primary me-1"><?= $i+1 ?>.</span><?= h($q['prompt']) ?>
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
      </div>
    </fieldset>
    <?php endforeach; ?>

    <div class="d-flex gap-2 mb-4">
      <button type="submit" class="btn btn-primary btn-lg"><i class="bi bi-send me-1" aria-hidden="true"></i>Wyślij odpowiedzi</button>
      <a href="index.php?tab=testy" class="btn btn-outline-secondary btn-lg">Anuluj</a>
    </div>
  </form>
<?php endif; ?>

<?php include __DIR__ . '/_layout_foot.php'; ?>
