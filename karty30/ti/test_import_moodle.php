<?php
/**
 * karty30/ti/test_import_moodle.php — Import pytań z pliku XML Moodle do testu.
 * Flow dwuetapowy:
 *  1. Wczytaj XML → podgląd pytań z checkboxami (step=preview)
 *  2. Zatwierdź wybrane pytania → import (step=import)
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/karty30.php';

k30_require_access();
karty30_migrate();

$can_write = can_write('karty30') || is_admin();
if (!$can_write) { http_response_code(403); die('Brak uprawnień.'); }

$test_id = (int)($_GET['test'] ?? 0);
$test    = $test_id ? k30_ti_test_get($test_id) : null;
if (!$test) { flash_set('danger', 'Nie znaleziono testu.'); header('Location: tests.php'); exit; }
$course_id = (int)$test['course_id'];

/* ── Pomocnik: parsowanie XML → tablica pytań ─────────────────────────────── */
function _parse_moodle_xml(SimpleXMLElement $xml): array {
    $questions = [];
    foreach ($xml->question as $q) {
        $type = strtolower((string)($q['type'] ?? ''));
        if (in_array($type, ['category', 'description'], true)) continue;

        $prompt = trim(strip_tags((string)($q->questiontext->text ?? $q->questiontext ?? '')));
        if ($prompt === '') continue;

        $points = max(0, (float)(string)($q->defaultgrade ?? $q->defaultmark ?? 1));
        if ($points <= 0) $points = 1;

        if ($type === 'multichoice') {
            $single  = strtolower((string)($q->single ?? 'true'));
            $q_type  = ($single === 'true' || $single === '1') ? 'single' : 'multi';
            $options = [];
            foreach ($q->answer as $ans) {
                $label    = trim(strip_tags((string)($ans->text ?? $ans ?? '')));
                $fraction = (float)(string)($ans['fraction'] ?? 0);
                if ($label !== '') $options[] = ['label' => $label, 'is_correct' => $fraction > 0 ? 1 : 0];
            }
            if (count($options) < 2 || !array_filter($options, fn($o) => $o['is_correct'])) {
                $questions[] = ['_skip' => true, '_reason' => 'multichoice — za mało wariantów lub brak poprawnej odpowiedzi', 'prompt' => $prompt, 'type_raw' => $type];
                continue;
            }
            $questions[] = ['type' => $q_type, 'prompt' => $prompt, 'points' => $points, 'options' => $options, 'type_raw' => 'multichoice'];

        } elseif ($type === 'truefalse') {
            $options = [];
            foreach ($q->answer as $ans) {
                $label    = trim(strip_tags((string)($ans->text ?? $ans ?? '')));
                $fraction = (float)(string)($ans['fraction'] ?? 0);
                if ($label !== '') $options[] = ['label' => $label, 'is_correct' => $fraction > 0 ? 1 : 0];
            }
            if (count($options) < 2) $options = [['label' => 'Prawda', 'is_correct' => 0], ['label' => 'Fałsz', 'is_correct' => 0]];
            $questions[] = ['type' => 'single', 'prompt' => $prompt, 'points' => $points, 'options' => $options, 'type_raw' => 'truefalse'];

        } elseif (in_array($type, ['essay', 'shortanswer'], true)) {
            $questions[] = ['type' => 'open', 'prompt' => $prompt, 'points' => $points, 'options' => [], 'type_raw' => $type];

        } else {
            $questions[] = ['_skip' => true, '_reason' => 'nieobsługiwany typ: ' . $type, 'prompt' => $prompt, 'type_raw' => $type];
        }
    }
    return $questions;
}

/* ── ETAP 1: wczytaj plik i wyświetl podgląd ─────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_step'] ?? '') === 'upload') {
    csrf_check();

    if (empty($_FILES['moodle_xml']) || $_FILES['moodle_xml']['error'] !== UPLOAD_ERR_OK) {
        flash_set('danger', 'Błąd przesyłania pliku. Upewnij się, że wybrałeś plik XML.');
        header('Location: test_import_moodle.php?test=' . $test_id); exit;
    }
    if (!in_array(strtolower(pathinfo($_FILES['moodle_xml']['name'], PATHINFO_EXTENSION)), ['xml'], true)) {
        flash_set('danger', 'Dozwolony jest tylko plik .xml.');
        header('Location: test_import_moodle.php?test=' . $test_id); exit;
    }

    libxml_use_internal_errors(true);
    $xml = simplexml_load_file($_FILES['moodle_xml']['tmp_name']);
    if ($xml === false) {
        $errs = array_map(fn($e) => $e->message, libxml_get_errors());
        flash_set('danger', 'Nieprawidłowy plik XML: ' . implode('; ', $errs));
        header('Location: test_import_moodle.php?test=' . $test_id); exit;
    }

    $questions = _parse_moodle_xml($xml);
    if (!$questions) {
        flash_set('warning', 'Plik nie zawiera żadnych pytań lub wszystkie zostały pominięte.');
        header('Location: test_import_moodle.php?test=' . $test_id); exit;
    }

    // Przechowaj w sesji — tylko dane, nie obiekt XML
    $_SESSION['moodle_import_' . $test_id] = [
        'questions'    => $questions,
        'add_to_bank'  => !empty($_POST['add_to_bank']) ? 1 : 0,
        'bank_draw'    => max(0, (int)($_POST['bank_draw'] ?? 0)),
        'filename'     => $_FILES['moodle_xml']['name'],
        'ts'           => time(),
    ];

    header('Location: test_import_moodle.php?test=' . $test_id . '&step=preview'); exit;
}

/* ── ETAP 2: zatwierdź wybrane pytania ───────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_step'] ?? '') === 'import') {
    csrf_check();

    $sess = $_SESSION['moodle_import_' . $test_id] ?? null;
    if (!$sess) {
        flash_set('danger', 'Sesja podglądu wygasła. Wczytaj plik ponownie.');
        header('Location: test_import_moodle.php?test=' . $test_id); exit;
    }

    $questions   = $sess['questions'];
    $add_to_bank = (int)($sess['add_to_bank'] ?? 0);
    $bank_draw   = (int)($sess['bank_draw'] ?? 0);
    $selected    = array_map('intval', (array)($_POST['q_idx'] ?? []));
    $pts_map     = $_POST['q_pts'] ?? [];

    $imported = 0; $errors = [];

    foreach ($selected as $idx) {
        if (!isset($questions[$idx]) || !empty($questions[$idx]['_skip'])) continue;
        $q      = $questions[$idx];
        $points = isset($pts_map[$idx]) ? max(0, (float)$pts_map[$idx]) : 1;
        if ($points <= 0) $points = 1;
        k30_ti_test_question_save([
            'test_id' => $test_id,
            'type'    => $q['type'],
            'prompt'  => $q['prompt'],
            'points'  => $points,
            'options' => $q['options'],
            'in_bank' => $add_to_bank,
        ]);
        $imported++;
    }

    if ($add_to_bank && $imported > 0 && $bank_draw > 0) {
        db_update('k30_ti_tests', ['bank_draw' => $bank_draw], $test_id);
    }

    unset($_SESSION['moodle_import_' . $test_id]);

    if ($imported > 0) {
        flash_set('success', 'Zaimportowano ' . $imported . ' ' . ($imported === 1 ? 'pytanie' : ($imported < 5 ? 'pytania' : 'pytań')) . '.');
    } else {
        flash_set('warning', 'Nie wybrano żadnych pytań do importu.');
    }
    header('Location: test_build.php?test=' . $test_id); exit;
}

/* ── GET: podgląd z sesji ─────────────────────────────────────────────────── */
$step    = $_GET['step'] ?? 'upload';
$sess    = $_SESSION['moodle_import_' . $test_id] ?? null;
$preview = ($step === 'preview' && $sess);

if ($step === 'preview' && !$sess) {
    flash_set('warning', 'Sesja podglądu wygasła. Wczytaj plik ponownie.');
    header('Location: test_import_moodle.php?test=' . $test_id); exit;
}

$PAGE_TITLE = 'Import Moodle XML — ' . $test['title'];
include dirname(dirname(__DIR__)) . '/karty30/includes/header_k30.php';
?>

<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/karty30/index.php">Dydaktyka 3</a></li>
  <li class="breadcrumb-item"><a href="index.php">Zajęcia TI</a></li>
  <li class="breadcrumb-item"><a href="tests.php?course=<?= $course_id ?>">Testy</a></li>
  <li class="breadcrumb-item"><a href="test_build.php?test=<?= $test_id ?>"><?= h($test['title']) ?></a></li>
  <li class="breadcrumb-item active">Import Moodle XML</li>
</ol></nav>

<div class="d-flex align-items-center mb-3 gap-3">
  <h4 class="mb-0 fw-bold"><i class="bi bi-file-earmark-arrow-up text-primary me-2"></i>Import pytań — Moodle XML</h4>
  <?php if ($preview): ?>
  <span class="badge bg-primary-subtle text-primary border border-primary-subtle">Krok 2 z 2 — wybierz pytania</span>
  <?php else: ?>
  <span class="badge bg-secondary-subtle text-secondary border">Krok 1 z 2 — wczytaj plik</span>
  <?php endif; ?>
</div>

<?= flash_html() ?>

<?php if ($preview):
    $questions   = $sess['questions'];
    $importable  = array_filter($questions, fn($q) => empty($q['_skip']));
    $skipped_qs  = array_filter($questions, fn($q) => !empty($q['_skip']));
    $type_labels = ['single' => 'Jednokrotny', 'multi' => 'Wielokrotny', 'open' => 'Otwarte'];
?>
<!-- ══ PODGLĄD + WYBÓR ════════════════════════════════════════════════════ -->
<div class="alert alert-info py-2 small mb-3">
  <i class="bi bi-info-circle me-1"></i>
  Plik: <strong><?= h($sess['filename']) ?></strong> —
  <?= count($importable) ?> pytań do wyboru<?= count($skipped_qs) ? ', ' . count($skipped_qs) . ' pominiętych' : '' ?>.
  Zaznacz te, które chcesz zaimportować do testu <strong><?= h($test['title']) ?></strong>.
</div>

<form method="post" id="importForm">
  <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
  <input type="hidden" name="_step" value="import">

  <!-- Toolbar zaznaczania -->
  <div class="d-flex align-items-center gap-3 mb-3 flex-wrap">
    <div>
      <button type="button" class="btn btn-sm btn-outline-secondary" onclick="selAll(true)">Zaznacz wszystkie</button>
      <button type="button" class="btn btn-sm btn-outline-secondary ms-1" onclick="selAll(false)">Odznacz wszystkie</button>
    </div>
    <span class="text-muted small" id="selCount">Zaznaczono: <?= count($importable) ?> z <?= count($importable) ?></span>
    <div class="ms-auto d-flex gap-2">
      <a href="test_import_moodle.php?test=<?= $test_id ?>" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>Wróć i wczytaj inny plik
      </a>
      <button type="submit" class="btn btn-sm btn-primary" id="btnImport">
        <i class="bi bi-check-lg me-1"></i>Importuj zaznaczone
      </button>
    </div>
  </div>

  <!-- Tabela pytań -->
  <div class="card border-0 shadow-sm mb-3">
    <div class="table-responsive">
      <table class="table table-hover table-sm mb-0 align-middle" id="previewTable">
        <thead class="table-light">
          <tr>
            <th style="width:36px">
              <input type="checkbox" class="form-check-input" id="chkAll" checked
                     aria-label="Zaznacz wszystkie" onchange="selAll(this.checked)">
            </th>
            <th style="width:70px">Typ</th>
            <th>Treść pytania</th>
            <th style="width:72px" class="text-end">Punkty</th>
            <th style="width:90px">Warianty</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($questions as $idx => $q):
            $skip = !empty($q['_skip']);
          ?>
          <tr class="<?= $skip ? 'table-secondary opacity-50' : '' ?>" id="qrow<?= $idx ?>">
            <td>
              <?php if (!$skip): ?>
              <input type="checkbox" class="form-check-input q-chk" name="q_idx[]"
                     value="<?= $idx ?>" checked onchange="updateCount()"
                     aria-label="Zaznacz pytanie <?= $idx + 1 ?>">
              <?php else: ?>
              <i class="bi bi-slash-circle text-muted" title="Pominięte: <?= h($q['_reason'] ?? '') ?>"></i>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($skip): ?>
              <span class="badge bg-secondary-subtle text-secondary border" style="font-size:.72rem">pominięte</span>
              <?php elseif ($q['type'] === 'single'): ?>
              <span class="badge bg-primary-subtle text-primary border border-primary-subtle" style="font-size:.72rem">Jednokr.</span>
              <?php elseif ($q['type'] === 'multi'): ?>
              <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle" style="font-size:.72rem">Wielokr.</span>
              <?php else: ?>
              <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle" style="font-size:.72rem">Otwarte</span>
              <?php endif; ?>
            </td>
            <td>
              <div class="fw-semibold" style="font-size:.88rem"><?= h(mb_strimwidth($q['prompt'], 0, 160, '…')) ?></div>
              <?php if (!$skip && !empty($q['options'])): ?>
              <div class="mt-1 d-flex flex-wrap gap-1">
                <?php foreach (array_slice($q['options'], 0, 5) as $o): ?>
                <span class="badge <?= $o['is_correct'] ? 'bg-success-subtle text-success border border-success-subtle' : 'bg-light text-secondary border' ?>" style="font-size:.7rem;font-weight:400">
                  <?= $o['is_correct'] ? '<i class="bi bi-check me-1"></i>' : '' ?><?= h(mb_strimwidth($o['label'], 0, 40, '…')) ?>
                </span>
                <?php endforeach; ?>
                <?php if (count($q['options']) > 5): ?>
                <span class="text-muted" style="font-size:.72rem">+<?= count($q['options']) - 5 ?> więcej</span>
                <?php endif; ?>
              </div>
              <?php elseif ($skip): ?>
              <div class="text-muted" style="font-size:.78rem"><i class="bi bi-exclamation-triangle me-1"></i><?= h($q['_reason'] ?? '') ?></div>
              <?php endif; ?>
            </td>
            <td class="text-end">
              <?php if (!$skip): ?>
              <input type="number" name="q_pts[<?= $idx ?>]" value="1"
                     min="0" max="999" step="0.5"
                     class="form-control form-control-sm text-end p-1"
                     style="width:60px;font-size:.82rem"
                     aria-label="Punkty za pytanie <?= $idx + 1 ?>"
                     onclick="event.stopPropagation()">
              <?php else: ?>
              <span class="text-muted">—</span>
              <?php endif; ?>
            </td>
            <td class="text-muted" style="font-size:.82rem"><?= $skip ? '—' : (empty($q['options']) ? 'brak' : count($q['options'])) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Przycisk dolny -->
  <div class="d-flex justify-content-end gap-2 mb-4">
    <a href="test_import_moodle.php?test=<?= $test_id ?>" class="btn btn-outline-secondary">
      <i class="bi bi-arrow-left me-1"></i>Wróć
    </a>
    <button type="submit" class="btn btn-primary">
      <i class="bi bi-check-lg me-1"></i>Importuj zaznaczone
    </button>
  </div>
</form>

<script>
(function(){
  var chkAll = document.getElementById('chkAll');
  var countEl = document.getElementById('selCount');
  var total = document.querySelectorAll('.q-chk').length;

  function updateCount() {
    var n = document.querySelectorAll('.q-chk:checked').length;
    if (countEl) countEl.textContent = 'Zaznaczono: ' + n + ' z ' + total;
    if (chkAll) {
      chkAll.indeterminate = (n > 0 && n < total);
      chkAll.checked = (n === total);
    }
    var btn = document.getElementById('btnImport');
    if (btn) btn.disabled = (n === 0);
  }
  window.updateCount = updateCount;

  window.selAll = function(checked) {
    document.querySelectorAll('.q-chk').forEach(function(c){ c.checked = checked; });
    if (chkAll) { chkAll.checked = checked; chkAll.indeterminate = false; }
    updateCount();
  };

  // Klik wiersza toggleuje checkbox
  document.querySelectorAll('#previewTable tbody tr').forEach(function(tr){
    tr.addEventListener('click', function(e){
      if (e.target.type === 'checkbox' || e.target.type === 'number') return;
      var chk = tr.querySelector('.q-chk');
      if (!chk) return;
      chk.checked = !chk.checked;
      updateCount();
    });
    var chk = tr.querySelector('.q-chk');
    if (chk) tr.style.cursor = 'pointer';
  });

  // Walidacja przed submit
  document.getElementById('importForm').addEventListener('submit', function(e){
    if (!document.querySelectorAll('.q-chk:checked').length) {
      e.preventDefault();
      alert('Zaznacz przynajmniej jedno pytanie do importu.');
    }
  });

  updateCount();
})();
</script>

<?php else: // ══ KROK 1: formularz wczytania pliku ═════════════════════════ ?>

<div class="row g-4">
  <div class="col-lg-6">
    <div class="card border-0 shadow-sm">
      <div class="card-header fw-semibold"><i class="bi bi-upload me-2" aria-hidden="true"></i>Prześlij plik XML</div>
      <div class="card-body">
        <p class="text-muted small mb-3">
          Importuj pytania wyeksportowane z Moodle w formacie <strong>Moodle XML</strong>.
          Po wczytaniu pliku zobaczysz podgląd wszystkich pytań i wybierzesz, które zaimportować do testu
          <strong><?= h($test['title']) ?></strong>.
        </p>
        <form method="post" enctype="multipart/form-data">
          <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="_step" value="upload">
          <div class="mb-3">
            <label class="form-label fw-semibold" for="moodle-xml">Plik XML <span class="text-danger" aria-hidden="true">*</span></label>
            <input type="file" class="form-control" id="moodle-xml" name="moodle_xml" accept=".xml,application/xml,text/xml" required>
            <div class="form-text">Tylko pliki .xml wyeksportowane z banku pytań Moodle.</div>
          </div>
          <div class="mb-3">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" id="add-to-bank" name="add_to_bank" value="1">
              <label class="form-check-label" for="add-to-bank">Dodaj pytania do <strong>bazy pytań</strong> (in_bank)</label>
            </div>
            <div class="mt-2" id="bank-draw-wrap" style="display:none">
              <label class="form-label small mb-1" for="bank-draw">Liczba pytań do losowania (bank_draw)</label>
              <input type="number" class="form-control form-control-sm" id="bank-draw" name="bank_draw" min="0" max="9999" step="1" value="0" style="width:8rem">
              <div class="form-text">Ile pytań z bazy ma być losowanych przy każdym podejściu (0 = bez zmian).</div>
            </div>
          </div>
          <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary"><i class="bi bi-eye me-1" aria-hidden="true"></i>Wczytaj i podgląd</button>
            <a href="test_build.php?test=<?= $test_id ?>" class="btn btn-outline-secondary">Anuluj</a>
          </div>
        </form>
      </div>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="card border-0 shadow-sm">
      <div class="card-header fw-semibold"><i class="bi bi-info-circle me-2" aria-hidden="true"></i>Obsługiwane typy pytań</div>
      <div class="card-body">
        <table class="table table-sm mb-0">
          <thead class="table-light"><tr><th>Typ Moodle</th><th>Importowany jako</th></tr></thead>
          <tbody>
            <tr><td><code>multichoice</code> (single)</td><td>Jednokrotny wybór</td></tr>
            <tr><td><code>multichoice</code> (multi)</td><td>Wielokrotny wybór</td></tr>
            <tr><td><code>truefalse</code></td><td>Jednokrotny wybór (Prawda/Fałsz)</td></tr>
            <tr><td><code>essay</code></td><td>Otwarte (ocena ręczna)</td></tr>
            <tr><td><code>shortanswer</code></td><td>Otwarte (ocena ręczna)</td></tr>
            <tr><td><code>category</code>, <code>description</code></td><td><span class="text-muted">pominięte</span></td></tr>
            <tr><td>pozostałe</td><td><span class="text-muted">pominięte (z informacją)</span></td></tr>
          </tbody>
        </table>
        <p class="small text-muted mt-2 mb-0">Jak wyeksportować: Moodle → Bank pytań → Eksport → Format Moodle XML.</p>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  var cb   = document.getElementById('add-to-bank');
  var wrap = document.getElementById('bank-draw-wrap');
  if (cb && wrap) cb.addEventListener('change', function(){ wrap.style.display = this.checked ? '' : 'none'; });
})();
</script>

<?php endif; ?>
<?php include dirname(dirname(__DIR__)) . '/karty30/includes/footer_k30.php'; ?>
