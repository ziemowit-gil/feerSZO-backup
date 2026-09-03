<?php
/**
 * karty30/ti/test_build.php — Budowanie pytań testu + ocena odpowiedzi otwartych.
 * Master-detail: lista pytań (lewa) + edytor pytania z wariantami (prawa).
 * Bez modali; warianty dodawane dostępnymi przyciskami; aria-live dla zapisu.
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
if (!$can_write) { http_response_code(403); die('Brak uprawnień.'); }

$test_id = (int)($_GET['test'] ?? ($_POST['test_id'] ?? 0));
$test    = $test_id ? k30_ti_test_get($test_id) : null;
if (!$test) { flash_set('danger','Nie znaleziono testu.'); header('Location: tests.php'); exit; }
$course_id  = (int)$test['course_id'];
$PAGE_TITLE = 'Test: ' . $test['title'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = $_POST['_op'] ?? '';

    if ($op === 'save_question') {
        $qid    = (int)($_POST['question_id'] ?? 0);
        $type   = $_POST['type'] ?? 'single';
        $prompt = trim($_POST['prompt'] ?? '');
        if ($prompt === '') { flash_set('danger','Treść pytania jest wymagana.'); header('Location: test_build.php?test='.$test_id.($qid?'&q='.$qid:'')); exit; }
        $options = [];
        if ($type !== 'open') {
            $labels  = (array)($_POST['opt_label'] ?? []);
            $correct = (array)($_POST['opt_correct'] ?? []);
            foreach ($labels as $i => $lab) {
                if (trim((string)$lab) === '') continue;
                $options[] = ['label' => $lab, 'is_correct' => isset($correct[$i]) ? 1 : 0];
            }
            if (count($options) < 2) { flash_set('danger','Pytanie zamknięte wymaga co najmniej 2 wariantów.'); header('Location: test_build.php?test='.$test_id.($qid?'&q='.$qid:'')); exit; }
            if (!array_filter($options, fn($o)=>$o['is_correct'])) { flash_set('danger','Zaznacz co najmniej jeden poprawny wariant.'); header('Location: test_build.php?test='.$test_id.($qid?'&q='.$qid:'')); exit; }
        }
        k30_ti_test_question_save([
            'test_id' => $test_id, 'type' => $type, 'prompt' => $prompt,
            'points'  => $_POST['points'] ?? 1, 'options' => $options,
            'in_bank' => !empty($_POST['in_bank']) ? 1 : 0,
        ], $qid ?: null);
        flash_set('success', $qid ? 'Pytanie zaktualizowane.' : 'Pytanie dodane.');
        header('Location: test_build.php?test='.$test_id); exit;
    }

    if ($op === 'delete_question') {
        $qid = (int)($_POST['question_id'] ?? 0);
        if ($qid) { k30_ti_test_question_delete($qid); flash_set('success','Pytanie usunięte.'); }
        header('Location: test_build.php?test='.$test_id); exit;
    }

    if ($op === 'move_question') {
        $qid = (int)($_POST['question_id'] ?? 0);
        $dir = ($_POST['dir'] ?? '') === 'up' ? 'up' : 'down';
        $ids = array_map(fn($r)=>(int)$r['id'], k30_ti_test_questions($test_id));
        $pos = array_search($qid, $ids, true);
        if ($pos !== false) {
            $swap = $dir === 'up' ? $pos-1 : $pos+1;
            if ($swap >= 0 && $swap < count($ids)) {
                [$ids[$pos],$ids[$swap]] = [$ids[$swap],$ids[$pos]];
                k30_ti_test_question_reorder($test_id, $ids);
            }
        }
        header('Location: test_build.php?test='.$test_id.'#q-'.$qid); exit;
    }

    if ($op === 'set_bank_draw') {
        $draw = max(0, (int)($_POST['bank_draw'] ?? 0));
        db_update('k30_ti_tests', ['bank_draw' => $draw], $test_id);
        flash_set('success', 'Ustawienie bazy pytań zapisane.');
        header('Location: test_build.php?test='.$test_id.'#bank'); exit;
    }

    if ($op === 'set_fixed_draw') {
        $draw = max(0, (int)($_POST['fixed_draw'] ?? 0));
        db_update('k30_ti_tests', ['fixed_draw' => $draw], $test_id);
        flash_set('success', 'Ustawienie losowania pytań stałych zapisane.');
        header('Location: test_build.php?test='.$test_id.'#fixed'); exit;
    }

    if ($op === 'grade_open') {
        $att_id = (int)($_POST['attempt_id'] ?? 0);
        $pts    = (array)($_POST['points'] ?? []);
        if ($att_id) { k30_ti_test_grade_open($att_id, $pts); flash_set('success','Oceniono odpowiedzi otwarte.'); }
        header('Location: test_build.php?test='.$test_id.'#review'); exit;
    }
}

$questions = k30_ti_test_questions($test_id);
$fixed_qs   = k30_ti_test_fixed_questions($test_id);
$bank_qs    = k30_ti_test_bank_questions($test_id);
$bank_draw  = (int)($test['bank_draw']  ?? 0);
$fixed_draw = (int)($test['fixed_draw'] ?? 0);
$max_score = k30_ti_test_max_score($test_id);

// Edycja pytania
$q_id  = (int)($_GET['q'] ?? 0);
$q_row = $q_id ? k30_ti_test_question_get($q_id) : null;
if ($q_row && (int)$q_row['test_id'] !== $test_id) $q_row = null;
$q_opts = $q_row ? k30_ti_test_options($q_id) : [];
$qf = $q_row ?: ['id'=>0,'type'=>'single','prompt'=>'','points'=>1,'in_bank'=>0];

// Podejścia czekające na ocenę pytań otwartych
$attempts = k30_ti_test_attempts_for_test($test_id);
$to_review = array_values(array_filter($attempts, fn($a)=>(int)$a['needs_review']===1 && $a['status']==='submitted'));

include dirname(dirname(__DIR__)) . '/karty30/includes/header_k30.php';
$QT = K30_TI_QUESTION_TYPES;
?>

<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/karty30/index.php">Dydaktyka 3</a></li>
  <li class="breadcrumb-item"><a href="index.php">Zajęcia TI</a></li>
  <li class="breadcrumb-item"><a href="tests.php?course=<?= $course_id ?>">Testy</a></li>
  <li class="breadcrumb-item active"><?= h($test['title']) ?></li>
</ol></nav>

<div class="d-flex align-items-center mb-3 gap-2 flex-wrap">
  <h4 class="mb-0 fw-bold"><i class="bi bi-card-checklist text-primary me-2"></i><?= h($test['title']) ?></h4>
  <span class="badge bg-secondary"><?= count($fixed_qs) ?> stałych + <?= count($bank_qs) ?> w bazie · <?= rtrim(rtrim(number_format($max_score,2,'.',''),'0'),'.') ?: '0' ?> pkt</span>
  <a href="tests.php?course=<?= $course_id ?>" class="btn btn-outline-secondary btn-sm ms-auto"><i class="bi bi-arrow-left me-1"></i>Lista testów</a>
</div>

<?= flash_html() ?>
<div aria-live="polite" class="visually-hidden" id="save-status"></div>

<div class="row g-4">
  <!-- LISTA pytań (master) -->
  <div class="col-lg-7">
    <!-- Pytania stałe -->
    <div class="card border-0 shadow-sm" id="fixed">
      <div class="card-header fw-semibold d-flex align-items-center gap-2">
        <i class="bi bi-list-ol me-1" aria-hidden="true"></i>Pytania stałe
        <?php if ($fixed_draw > 0): ?><span class="badge bg-primary">losuj <?= $fixed_draw ?> z <?= count($fixed_qs) ?></span><?php endif; ?>
      </div>
      <ol class="list-group list-group-numbered list-group-flush">
        <?php if (!$fixed_qs): ?><li class="list-group-item text-muted">Brak pytań stałych.</li><?php endif; ?>
        <?php foreach ($fixed_qs as $idx => $q):
          $opts = $q['type'] !== 'open' ? k30_ti_test_options((int)$q['id']) : [];
        ?>
        <li class="list-group-item" id="q-<?= (int)$q['id'] ?>">
          <div class="d-flex justify-content-between align-items-start gap-2">
            <div>
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
                <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="_op" value="move_question">
                <input type="hidden" name="test_id" value="<?= $test_id ?>">
                <input type="hidden" name="question_id" value="<?= (int)$q['id'] ?>">
                <button name="dir" value="up" class="btn btn-sm btn-outline-secondary py-0 px-2" title="Wyżej" aria-label="Przesuń pytanie wyżej" <?= $idx===0?'disabled':'' ?>><i class="bi bi-arrow-up" aria-hidden="true"></i></button>
                <button name="dir" value="down" class="btn btn-sm btn-outline-secondary py-0 px-2" title="Niżej" aria-label="Przesuń pytanie niżej" <?= $idx===count($fixed_qs)-1?'disabled':'' ?>><i class="bi bi-arrow-down" aria-hidden="true"></i></button>
              </form>
              <a href="?test=<?= $test_id ?>&q=<?= (int)$q['id'] ?>#q-form" class="btn btn-sm btn-outline-primary py-0 px-2" title="Edytuj" aria-label="Edytuj pytanie"><i class="bi bi-pencil" aria-hidden="true"></i></a>
              <form method="post" class="d-inline" onsubmit="return confirm('Usunąć pytanie?')">
                <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="_op" value="delete_question">
                <input type="hidden" name="test_id" value="<?= $test_id ?>">
                <input type="hidden" name="question_id" value="<?= (int)$q['id'] ?>">
                <button class="btn btn-sm btn-outline-danger py-0 px-2" title="Usuń" aria-label="Usuń pytanie"><i class="bi bi-trash" aria-hidden="true"></i></button>
              </form>
            </div>
          </div>
        </li>
        <?php endforeach; ?>
      </ol>
      <?php if ($fixed_qs): ?>
      <div class="card-footer">
        <form method="post" class="d-flex align-items-center gap-2 flex-wrap">
          <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="_op" value="set_fixed_draw">
          <input type="hidden" name="test_id" value="<?= $test_id ?>">
          <div class="form-check form-switch mb-0 me-1">
            <input class="form-check-input" type="checkbox" role="switch" id="fixed-draw-toggle" <?= $fixed_draw > 0 ? 'checked' : '' ?> onchange="document.getElementById('fixed-draw-wrap').style.display=this.checked?'':'none'">
            <label class="form-check-label fw-semibold" for="fixed-draw-toggle">Losuj pytania stałe</label>
          </div>
          <span id="fixed-draw-wrap" style="display:<?= $fixed_draw > 0 ? '' : 'none' ?>">
            <label class="form-label mb-0 text-nowrap" for="fixed-draw-inp">Losuj:</label>
            <input type="number" class="form-control form-control-sm d-inline-block ms-1" id="fixed-draw-inp" name="fixed_draw" min="1" max="<?= count($fixed_qs) ?>" step="1" value="<?= $fixed_draw ?: '' ?>" style="width:6rem" placeholder="ile?">
            <span class="text-muted small ms-1">z <?= count($fixed_qs) ?> pytań</span>
          </span>
          <button class="btn btn-sm btn-outline-primary"><i class="bi bi-save me-1" aria-hidden="true"></i>Zapisz</button>
        </form>
      </div>
      <?php endif; ?>
    </div>

    <!-- Baza pytań (pula do losowania) -->
    <div class="card border-0 shadow-sm mt-4" id="bank">
      <div class="card-header fw-semibold d-flex align-items-center gap-2">
        <i class="bi bi-collection me-1" aria-hidden="true"></i>Baza pytań (pula do losowania)
        <?php if ($bank_qs): ?><span class="badge bg-info text-dark"><?= count($bank_qs) ?> w puli</span><?php endif; ?>
        <?php if ($bank_draw > 0): ?><span class="badge bg-primary">losuj <?= $bank_draw ?></span><?php endif; ?>
      </div>
      <ol class="list-group list-group-numbered list-group-flush">
        <?php if (!$bank_qs): ?><li class="list-group-item text-muted">Brak pytań w bazie. Dodaj po prawej zaznaczając „Baza pytań".</li><?php endif; ?>
        <?php foreach ($bank_qs as $idx => $q):
          $opts = $q['type'] !== 'open' ? k30_ti_test_options((int)$q['id']) : [];
        ?>
        <li class="list-group-item" id="q-<?= (int)$q['id'] ?>">
          <div class="d-flex justify-content-between align-items-start gap-2">
            <div>
              <span class="badge bg-info text-dark border me-1" title="Pytanie z bazy"><i class="bi bi-collection" aria-hidden="true"></i></span>
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
              <a href="?test=<?= $test_id ?>&q=<?= (int)$q['id'] ?>#q-form" class="btn btn-sm btn-outline-primary py-0 px-2" title="Edytuj" aria-label="Edytuj pytanie"><i class="bi bi-pencil" aria-hidden="true"></i></a>
              <form method="post" class="d-inline" onsubmit="return confirm('Usunąć pytanie z bazy?')">
                <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="_op" value="delete_question">
                <input type="hidden" name="test_id" value="<?= $test_id ?>">
                <input type="hidden" name="question_id" value="<?= (int)$q['id'] ?>">
                <button class="btn btn-sm btn-outline-danger py-0 px-2" title="Usuń" aria-label="Usuń pytanie z bazy"><i class="bi bi-trash" aria-hidden="true"></i></button>
              </form>
            </div>
          </div>
        </li>
        <?php endforeach; ?>
      </ol>
      <div class="card-footer">
        <form method="post" class="d-flex align-items-center gap-2 flex-wrap">
          <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="_op" value="set_bank_draw">
          <input type="hidden" name="test_id" value="<?= $test_id ?>">
          <label class="form-label mb-0 fw-semibold" for="bank-draw-inp">Losuj pytań z bazy:</label>
          <input type="number" class="form-control form-control-sm" id="bank-draw-inp" name="bank_draw" min="0" max="9999" step="1" value="<?= $bank_draw ?>" style="width:7rem">
          <button class="btn btn-sm btn-outline-primary"><i class="bi bi-save me-1" aria-hidden="true"></i>Zapisz</button>
          <span class="text-muted small">(0 = losowanie wyłączone, pytania stałe zawsze widoczne)</span>
        </form>
      </div>
    </div>

    <!-- Przegląd / ocena odpowiedzi otwartych -->
    <div class="card border-0 shadow-sm mt-4" id="review">
      <div class="card-header fw-semibold">
        <i class="bi bi-clipboard-check me-2" aria-hidden="true"></i>Do oceny (pytania otwarte)
        <?php if ($to_review): ?><span class="badge bg-warning text-dark ms-1"><?= count($to_review) ?></span><?php endif; ?>
      </div>
      <div class="card-body">
        <?php if (!$to_review): ?>
          <p class="text-muted mb-0">Brak podejść czekających na ocenę.</p>
        <?php else: foreach ($to_review as $a):
          $ans = db_all(
            "SELECT ta.*, q.prompt, q.points, q.type FROM k30_ti_test_answers ta
             JOIN k30_ti_test_questions q ON q.id=ta.question_id
             WHERE ta.attempt_id=? AND q.type='open' ORDER BY q.position", [(int)$a['id']]);
        ?>
        <form method="post" class="border rounded p-2 mb-2">
          <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="_op" value="grade_open">
          <input type="hidden" name="test_id" value="<?= $test_id ?>">
          <input type="hidden" name="attempt_id" value="<?= (int)$a['id'] ?>">
          <div class="fw-semibold mb-2"><i class="bi bi-person me-1" aria-hidden="true"></i><?= h($a['client_name']) ?>
            <span class="text-muted small">— wynik zamknięte: <?= rtrim(rtrim(number_format((float)$a['score'],2,'.',''),'0'),'.') ?>/<?= rtrim(rtrim(number_format((float)$a['max_score'],2,'.',''),'0'),'.') ?></span></div>
          <?php foreach ($ans as $an): ?>
          <div class="mb-2">
            <div class="small fw-semibold"><?= h($an['prompt']) ?> <span class="text-muted">(max <?= rtrim(rtrim(number_format((float)$an['points'],2,'.',''),'0'),'.') ?> pkt)</span></div>
            <div class="border rounded bg-light p-2 small mb-1" style="white-space:pre-wrap"><?= $an['answer_text']!=='' ? h($an['answer_text']) : '<span class="text-muted">— brak odpowiedzi —</span>' ?></div>
            <label class="form-label small mb-0" for="pts-<?= (int)$a['id'] ?>-<?= (int)$an['question_id'] ?>">Punkty:</label>
            <input type="number" class="form-control form-control-sm d-inline-block" style="width:6rem" id="pts-<?= (int)$a['id'] ?>-<?= (int)$an['question_id'] ?>" name="points[<?= (int)$an['question_id'] ?>]" min="0" max="<?= (float)$an['points'] ?>" step="0.5" value="0">
          </div>
          <?php endforeach; ?>
          <button class="btn btn-sm btn-primary"><i class="bi bi-check-lg me-1" aria-hidden="true"></i>Zapisz ocenę</button>
        </form>
        <?php endforeach; endif; ?>
      </div>
    </div>
  </div>

  <!-- DETAIL: edytor pytania -->
  <div class="col-lg-5">
    <div class="card border-0 shadow-sm" id="q-form">
      <div class="card-header fw-semibold"><i class="bi bi-<?= $q_row ? 'pencil' : 'plus-lg' ?> me-2" aria-hidden="true"></i><?= $q_row ? 'Edytuj pytanie' : 'Nowe pytanie' ?></div>
      <div class="card-body">
        <form method="post" id="qform">
          <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="_op" value="save_question">
          <input type="hidden" name="test_id" value="<?= $test_id ?>">
          <input type="hidden" name="question_id" value="<?= (int)$qf['id'] ?>">
          <div class="mb-2">
            <label class="form-label fw-semibold" for="q-type">Typ pytania</label>
            <select class="form-select" id="q-type" name="type">
              <?php foreach ($QT as $slug=>$lab): ?>
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
            <input type="number" class="form-control" id="q-points" name="points" min="0" max="100" step="0.5" value="<?= rtrim(rtrim(number_format((float)$qf['points'],2,'.',''),'0'),'.') ?: '1' ?>" style="width:7rem">
          </div>
          <div class="mb-2">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" id="q-in-bank" name="in_bank" value="1" <?= !empty($qf['in_bank']) ? 'checked' : '' ?>>
              <label class="form-check-label" for="q-in-bank">Baza pytań <span class="text-muted small">(losowane przy podejściu)</span></label>
            </div>
          </div>
          <div id="opts-wrap" class="mb-2">
            <label class="form-label fw-semibold">Warianty odpowiedzi <span class="form-text">(zaznacz poprawne)</span></label>
            <div id="opts-list">
              <?php
              $rows = $q_opts;
              // dołóż puste wiersze do min. 4
              for ($i = count($rows); $i < 4; $i++) $rows[] = ['label'=>'','is_correct'=>0];
              foreach ($rows as $i => $o): ?>
              <div class="input-group input-group-sm mb-1 opt-row">
                <div class="input-group-text">
                  <input class="form-check-input mt-0" type="checkbox" name="opt_correct[<?= $i ?>]" value="1" <?= !empty($o['is_correct'])?'checked':'' ?> aria-label="Wariant poprawny">
                </div>
                <input type="text" class="form-control" name="opt_label[<?= $i ?>]" value="<?= h($o['label']) ?>" placeholder="Treść wariantu" aria-label="Treść wariantu <?= $i+1 ?>">
              </div>
              <?php endforeach; ?>
            </div>
            <button type="button" class="btn btn-sm btn-outline-secondary mt-1" id="add-opt"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Dodaj wariant</button>
          </div>
          <div class="d-flex gap-2 mt-3">
            <button type="submit" class="btn btn-primary"><?= $q_row ? 'Zapisz pytanie' : 'Dodaj pytanie' ?></button>
            <?php if ($q_row): ?><a href="test_build.php?test=<?= $test_id ?>#q-form" class="btn btn-outline-secondary">Anuluj</a><?php endif; ?>
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
  // Ukryj warianty dla pytań otwartych
  function syncType(){ optsWrap.style.display = (typeSel.value === 'open') ? 'none' : ''; }
  typeSel.addEventListener('change', syncType); syncType();
  // Dodawanie wariantu (dostępne: nowy input dostaje fokus)
  addBtn.addEventListener('click', function(){
    var i = list.querySelectorAll('.opt-row').length;
    var row = document.createElement('div');
    row.className = 'input-group input-group-sm mb-1 opt-row';
    row.innerHTML = '<div class="input-group-text"><input class="form-check-input mt-0" type="checkbox" name="opt_correct['+i+']" value="1" aria-label="Wariant poprawny"></div>'
      + '<input type="text" class="form-control" name="opt_label['+i+']" placeholder="Treść wariantu" aria-label="Treść wariantu '+(i+1)+'">';
    list.appendChild(row);
    var inp = row.querySelector('input[type=text]'); if (inp) inp.focus();
  });
})();
</script>

<?php include dirname(dirname(__DIR__)) . '/karty30/includes/footer_k30.php'; ?>
