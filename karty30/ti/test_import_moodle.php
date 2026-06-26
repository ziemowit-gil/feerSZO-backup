<?php
/**
 * karty30/ti/test_import_moodle.php — Import pytań z pliku XML Moodle do testu.
 * Obsługiwane typy: multichoice (single/multi), essay (open), shortanswer (open).
 * Kategorie ($CATEGORY) są pomijane — wszystkie pytania trafiają do wybranego testu.
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
$PAGE_TITLE = 'Import Moodle XML — ' . $test['title'];

$results = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    if (empty($_FILES['moodle_xml']) || $_FILES['moodle_xml']['error'] !== UPLOAD_ERR_OK) {
        flash_set('danger', 'Błąd przesyłania pliku. Upewnij się, że wybrałeś plik XML.');
        header('Location: test_import_moodle.php?test=' . $test_id);
        exit;
    }

    $file = $_FILES['moodle_xml'];
    if (!in_array(strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)), ['xml'], true)) {
        flash_set('danger', 'Dozwolony jest tylko plik .xml.');
        header('Location: test_import_moodle.php?test=' . $test_id);
        exit;
    }

    libxml_use_internal_errors(true);
    $xml = simplexml_load_file($file['tmp_name']);
    if ($xml === false) {
        $errs = array_map(fn($e) => $e->message, libxml_get_errors());
        flash_set('danger', 'Nieprawidłowy plik XML: ' . implode('; ', $errs));
        header('Location: test_import_moodle.php?test=' . $test_id);
        exit;
    }

    $add_to_bank = !empty($_POST['add_to_bank']);
    $bank_draw   = max(0, (int)($_POST['bank_draw'] ?? 0));

    $imported = 0;
    $skipped  = 0;
    $errors   = [];

    foreach ($xml->question as $q) {
        $type = strtolower((string)($q['type'] ?? ''));

        // Pomiń kategorie i opisy
        if (in_array($type, ['category', 'description'], true)) continue;

        // Tekst pytania (Moodle trzyma HTML w <text> wewnątrz <questiontext>)
        $prompt = trim(strip_tags((string)($q->questiontext->text ?? $q->questiontext ?? '')));
        if ($prompt === '') {
            $skipped++;
            $errors[] = 'Pominięto pytanie bez treści (typ: ' . h($type) . ').';
            continue;
        }

        $points = max(0, (float)(string)($q->defaultgrade ?? $q->defaultmark ?? 1));
        if ($points <= 0) $points = 1;

        if ($type === 'multichoice') {
            // Sprawdź single vs multi: single=jednorazowy wybór
            $single = strtolower((string)($q->single ?? 'true'));
            $q_type = ($single === 'true' || $single === '1') ? 'single' : 'multi';

            $options = [];
            foreach ($q->answer as $ans) {
                $label = trim(strip_tags((string)($ans->text ?? $ans ?? '')));
                if ($label === '') continue;
                $fraction = (float)(string)($ans['fraction'] ?? 0);
                $options[] = ['label' => $label, 'is_correct' => $fraction > 0 ? 1 : 0];
            }

            if (count($options) < 2) {
                $skipped++;
                $errors[] = 'Pominięto „' . mb_strimwidth($prompt, 0, 60, '…') . '" — za mało wariantów.';
                continue;
            }
            if (!array_filter($options, fn($o) => $o['is_correct'])) {
                $skipped++;
                $errors[] = 'Pominięto „' . mb_strimwidth($prompt, 0, 60, '…') . '" — brak poprawnej odpowiedzi.';
                continue;
            }

            k30_ti_test_question_save([
                'test_id' => $test_id,
                'type'    => $q_type,
                'prompt'  => $prompt,
                'points'  => $points,
                'options' => $options,
                'in_bank' => $add_to_bank ? 1 : 0,
            ]);
            $imported++;

        } elseif (in_array($type, ['essay', 'shortanswer', 'truefalse'], true)) {
            if ($type === 'truefalse') {
                // Zamień prawda/fałsz na pytanie single z 2 wariantami
                $options = [];
                foreach ($q->answer as $ans) {
                    $label = trim(strip_tags((string)($ans->text ?? $ans ?? '')));
                    if ($label === '') continue;
                    $fraction = (float)(string)($ans['fraction'] ?? 0);
                    $options[] = ['label' => $label, 'is_correct' => $fraction > 0 ? 1 : 0];
                }
                if (count($options) < 2) {
                    // Moodle trzyma True/False jako wartości atrybutu — fallback
                    $options = [
                        ['label' => 'Prawda', 'is_correct' => 0],
                        ['label' => 'Fałsz',  'is_correct' => 0],
                    ];
                }
                k30_ti_test_question_save([
                    'test_id' => $test_id,
                    'type'    => 'single',
                    'prompt'  => $prompt,
                    'points'  => $points,
                    'options' => $options,
                    'in_bank' => $add_to_bank ? 1 : 0,
                ]);
            } else {
                k30_ti_test_question_save([
                    'test_id' => $test_id,
                    'type'    => 'open',
                    'prompt'  => $prompt,
                    'points'  => $points,
                    'options' => [],
                    'in_bank' => $add_to_bank ? 1 : 0,
                ]);
            }
            $imported++;

        } else {
            $skipped++;
            $errors[] = 'Pominięto pytanie „' . mb_strimwidth($prompt, 0, 60, '…') . '" — nieobsługiwany typ: ' . h($type) . '.';
        }
    }

    if ($add_to_bank && $imported > 0 && $bank_draw > 0) {
        db_update('k30_ti_tests', ['bank_draw' => $bank_draw], 'id=?', [$test_id]);
    }

    if ($imported > 0) {
        $msg = 'Zaimportowano ' . $imported . ' ' . ($imported === 1 ? 'pytanie' : ($imported < 5 ? 'pytania' : 'pytań')) . '.';
        if ($skipped > 0) $msg .= ' Pominięto: ' . $skipped . '.';
        flash_set('success', $msg);
    } else {
        flash_set('warning', 'Nie zaimportowano żadnego pytania. ' . ($skipped ? 'Pominięto: ' . $skipped . '.' : 'Plik może być pusty lub zawierać nieobsługiwane typy.'));
    }

    if ($errors) {
        // Pokaż szczegóły przez sesję (maks. 10)
        $_SESSION['import_errors'] = array_slice($errors, 0, 10);
    }

    header('Location: test_build.php?test=' . $test_id);
    exit;
}

// Pobierz ewentualne błędy z poprzedniego importu
$import_errors = $_SESSION['import_errors'] ?? [];
unset($_SESSION['import_errors']);

include dirname(dirname(__DIR__)) . '/karty30/includes/header_k30.php';
?>

<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/karty30/index.php">Karty 30</a></li>
  <li class="breadcrumb-item"><a href="index.php">Zajęcia TI</a></li>
  <li class="breadcrumb-item"><a href="tests.php?course=<?= $course_id ?>">Testy</a></li>
  <li class="breadcrumb-item"><a href="test_build.php?test=<?= $test_id ?>"><?= h($test['title']) ?></a></li>
  <li class="breadcrumb-item active">Import Moodle XML</li>
</ol></nav>

<div class="d-flex align-items-center mb-3 gap-2">
  <h4 class="mb-0 fw-bold"><i class="bi bi-file-earmark-arrow-up text-primary me-2"></i>Import pytań — Moodle XML</h4>
</div>

<?= flash_html() ?>

<?php if ($import_errors): ?>
<div class="alert alert-warning">
  <strong>Szczegóły pominięć:</strong>
  <ul class="mb-0 mt-1">
    <?php foreach ($import_errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<div class="row g-4">
  <div class="col-lg-6">
    <div class="card border-0 shadow-sm">
      <div class="card-header fw-semibold"><i class="bi bi-upload me-2" aria-hidden="true"></i>Prześlij plik XML</div>
      <div class="card-body">
        <p class="text-muted small mb-3">Importuj pytania wyeksportowane z Moodle w formacie <strong>Moodle XML</strong>. Pytania zostaną dodane na końcu testu <strong><?= h($test['title']) ?></strong>.</p>
        <form method="post" enctype="multipart/form-data">
          <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
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
            <button type="submit" class="btn btn-primary"><i class="bi bi-file-earmark-arrow-up me-1" aria-hidden="true"></i>Importuj pytania</button>
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
  var cb = document.getElementById('add-to-bank');
  var wrap = document.getElementById('bank-draw-wrap');
  if (cb && wrap) {
    cb.addEventListener('change', function(){ wrap.style.display = this.checked ? '' : 'none'; });
  }
})();
</script>
<?php include dirname(dirname(__DIR__)) . '/karty30/includes/footer_k30.php'; ?>
