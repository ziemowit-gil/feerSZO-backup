<?php
/**
 * karty30/ti/kursant/test_print.php — Wydruk wypełnionego testu kursanta.
 * Strona bez nawigacji panelu; otwierana w nowej karcie, drukowana przez przeglądarkę.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/functions.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/karty30.php';
require_once __DIR__ . '/auth.php';

karty30_migrate();
$student = student_require();
$client  = db_one("SELECT * FROM k30_clients WHERE id=?", [$student['client_id']]) ?: [];

$attempt_id = (int)($_GET['attempt'] ?? 0);
$attempt    = $attempt_id ? k30_ti_test_attempt_get($attempt_id) : null;

// Kursant może drukować tylko swoje podejście
if (!$attempt || (int)$attempt['client_id'] !== (int)$student['client_id']) {
    http_response_code(403); exit('Brak dostępu.');
}
if (!in_array($attempt['status'], ['submitted', 'graded'], true)) {
    exit('Test nie został jeszcze zakończony.');
}

$test      = k30_ti_test_get((int)$attempt['test_id']);
$questions = k30_ti_test_questions_for_attempt($attempt);

// Odpowiedzi kursanta
$answers_raw = db_all(
    "SELECT * FROM k30_ti_test_answers WHERE attempt_id=?", [$attempt_id]
);
$ans_map = [];
foreach ($answers_raw as $a) $ans_map[(int)$a['question_id']] = $a;

$mx         = (float)$attempt['max_score'];
$score      = (float)$attempt['score'];
$pct        = $mx > 0 ? round(100 * $score / $mx) : 0;
$is_retake  = ($attempt['attempt_label'] ?? '') === 'poprawa';
$base_pass  = (int)($test['pass_pct'] ?? 0);
$act_pass   = $is_retake ? min(100, $base_pass + 20) : $base_pass;
$passed     = $act_pass === 0 || $pct >= $act_pass;
$graded     = $attempt['status'] === 'graded';

$org   = defined('ORG_NAME') ? ORG_NAME : 'Zajęcia TI';
$QT    = K30_TI_QUESTION_TYPES;
$date  = $attempt['submitted_at'] ? date('d.m.Y H:i', strtotime($attempt['submitted_at'])) : '—';
?><!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Wydruk: <?= h($test['title'] ?? '') ?></title>
<style>
*, *::before, *::after { box-sizing: border-box; }
body { font-family: 'Segoe UI', Arial, sans-serif; font-size: 12pt; color: #111; margin: 0; padding: 1.5cm 2cm; }
h1 { font-size: 16pt; margin: 0 0 .2rem; }
h2 { font-size: 11pt; font-weight: 600; margin: 1.2rem 0 .3rem; border-bottom: 1px solid #ccc; padding-bottom: .2rem; }
.meta { font-size: 10pt; color: #555; margin-bottom: .8rem; }
.result-box { border: 2px solid #111; border-radius: .4rem; padding: .6rem 1rem; display: inline-block; margin-bottom: 1rem; }
.result-box .score { font-size: 15pt; font-weight: 700; }
.result-box .verdict { font-size: 11pt; margin-top: .1rem; }
.verdict.pass  { color: #166534; }
.verdict.fail  { color: #991b1b; }
.verdict.pend  { color: #1d4ed8; }
.question { margin-bottom: 1rem; page-break-inside: avoid; }
.q-num  { font-weight: 700; margin-right: .3rem; }
.q-pts  { font-size: 9pt; color: #555; }
.q-text { margin-bottom: .3rem; }
.options { list-style: none; padding: 0; margin: 0 0 0 1rem; }
.options li { margin: .15rem 0; display: flex; align-items: baseline; gap: .4rem; }
.opt-mark { font-size: 10pt; flex-shrink: 0; width: 1rem; }
.correct   { color: #166534; font-weight: 600; }
.wrong     { color: #991b1b; }
.selected  { font-weight: 600; }
.open-ans  { border: 1px solid #bbb; border-radius: .3rem; padding: .4rem .6rem; margin-top: .3rem; white-space: pre-wrap; font-size: 10pt; background: #fafafa; }
.pts-awarded { font-size: 9pt; color: #555; }
.badge-retake { font-size: 9pt; border: 1px solid #ccc; border-radius: .3rem; padding: 0 .4rem; display: inline-block; }
.no-print { }
@media print {
  .no-print { display: none !important; }
  body { padding: 1cm 1.5cm; }
  a { text-decoration: none; color: inherit; }
}
</style>
</head>
<body>

<div class="no-print" style="margin-bottom:1rem">
  <button onclick="window.print()" style="padding:.4rem 1rem;font-size:11pt;cursor:pointer">&#128438; Drukuj / Zapisz PDF</button>
  <button onclick="window.close()" style="padding:.4rem 1rem;font-size:11pt;cursor:pointer;margin-left:.5rem">Zamknij</button>
</div>

<h1><?= h($test['title'] ?? '') ?></h1>
<div class="meta">
  <?= h($org) ?> &nbsp;·&nbsp;
  <?= h($client['name'] ?? '') ?> &nbsp;·&nbsp;
  Wysłano: <?= h($date) ?>
  <?php if ($is_retake): ?>&nbsp;<span class="badge-retake">poprawa</span><?php endif; ?>
</div>

<div class="result-box">
  <div class="score"><?= $pct ?>%
    &nbsp;<span style="font-size:11pt;font-weight:400">(<?= rtrim(rtrim(number_format($score,2,'.',''),'0'),'.') ?> / <?= rtrim(rtrim(number_format($mx,2,'.',''),'0'),'.') ?> pkt)</span>
  </div>
  <?php if ($act_pass > 0): ?>
  <div class="verdict <?= !$graded ? 'pend' : ($passed ? 'pass' : 'fail') ?>">
    <?php if (!$graded): ?>
      Oczekuje na ocenę pytań otwartych
    <?php elseif ($passed): ?>
      ✓ Zaliczono (próg <?= $act_pass ?>%)
    <?php else: ?>
      ✗ Nie zaliczono (próg <?= $act_pass ?>%)
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<?php foreach ($questions as $i => $q):
  $qid  = (int)$q['id'];
  $opts = $q['type'] !== 'open' ? k30_ti_test_options($qid) : [];
  $ans  = $ans_map[$qid] ?? null;
  $sel_ids = $ans ? array_filter(array_map('intval', explode(',', (string)$ans['option_ids']))) : [];
  $is_correct_q = $ans ? (int)($ans['is_correct'] ?? 0) : 0;
  $pts_awarded  = $ans ? $ans['points_awarded'] : null;
?>
<div class="question">
  <div class="q-text">
    <span class="q-num"><?= $i+1 ?>.</span><?= h($q['prompt']) ?>
    <span class="q-pts">[<?= rtrim(rtrim(number_format((float)$q['points'],2,'.',''),'0'),'.') ?> pkt<?php if ($pts_awarded !== null): ?> — przyznano: <?= rtrim(rtrim(number_format((float)$pts_awarded,2,'.',''),'0'),'.') ?><?php endif; ?>]</span>
  </div>

  <?php if ($q['type'] === 'open'): ?>
    <div class="open-ans"><?= $ans && $ans['answer_text'] !== '' ? h($ans['answer_text']) : '<em style="color:#999">— brak odpowiedzi —</em>' ?></div>
    <?php if ($pts_awarded !== null): ?>
    <div class="pts-awarded">Ocena prowadzącego: <?= rtrim(rtrim(number_format((float)$pts_awarded,2,'.',''),'0'),'.') ?> / <?= rtrim(rtrim(number_format((float)$q['points'],2,'.',''),'0'),'.') ?> pkt</div>
    <?php endif; ?>
  <?php else: ?>
    <ul class="options">
      <?php foreach ($opts as $o):
        $oid       = (int)$o['id'];
        $selected  = in_array($oid, $sel_ids, true);
        $correct   = (int)$o['is_correct'] === 1;
        $cls = '';
        if ($correct && $selected)   $cls = 'correct';
        elseif ($correct)            $cls = 'correct';
        elseif ($selected && !$correct) $cls = 'wrong';
      ?>
      <li class="<?= $cls ?>">
        <span class="opt-mark">
          <?php if ($selected && $correct):  ?>✓
          <?php elseif ($selected):          ?>✗
          <?php elseif ($correct):           ?>○
          <?php else:                        ?>&nbsp;
          <?php endif; ?>
        </span>
        <span class="<?= $selected ? 'selected' : '' ?>"><?= h($o['label']) ?></span>
        <?php if ($correct && !$selected): ?><span style="font-size:9pt;color:#555"> ← poprawna</span><?php endif; ?>
      </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</div>
<?php endforeach; ?>

</body>
</html>
