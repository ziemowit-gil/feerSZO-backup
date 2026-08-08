<?php
/**
 * karty30/ti/homework.php — Zadania domowe TI: definiowanie, oddania, ocenianie.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/karty30.php';
require_once dirname(dirname(__DIR__)) . '/includes/owncloud.php';

k30_require_access();
karty30_migrate();

$can_write  = can_write('karty30') || is_admin();
$can_delete = is_admin();
$PAGE_TITLE = 'Zadania domowe — TI';

// ── Pobieranie plików (prowadzący) ───────────────────────────────────────────
if (isset($_GET['dl'])) {
    if ($_GET['dl'] === 'attach') {
        $hw = k30_ti_homework_get((int)($_GET['hw'] ?? 0));
        if ($hw && $hw['attach_path'] !== '') k30_ti_homework_send_file($hw['attach_path'], $hw['attach_name']);
    } elseif ($_GET['dl'] === 'sub') {
        $s = db_one("SELECT * FROM k30_ti_homework_submissions WHERE id=?", [(int)($_GET['id'] ?? 0)]);
        if ($s && $s['file_path'] !== '') k30_ti_homework_send_file($s['file_path'], $s['file_name']);
    }
    http_response_code(404); exit('Plik nie istnieje.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_write) {
    csrf_check();
    $op = $_POST['_op'] ?? '';

    if ($op === 'save_homework') {
        $hid       = (int)($_POST['homework_id'] ?? 0);
        $course_id = (int)($_POST['course_id'] ?? 0);
        $title     = trim($_POST['title'] ?? '');
        $desc      = trim($_POST['description'] ?? '');
        $hint      = trim($_POST['hint'] ?? '');
        $due       = trim($_POST['due_at'] ?? '');
        $due_sql   = $due !== '' ? str_replace('T', ' ', $due) . (strlen($due) === 16 ? ':00' : '') : null;
        $dt        = fn($k) => ($v = trim($_POST[$k] ?? '')) !== '' ? str_replace('T',' ',$v) . (strlen($v)===16?':00':'') : null;
        $session_id= (int)($_POST['session_id'] ?? 0) ?: null;
        $open_at   = $dt('open_at');
        $close_at  = $dt('close_at');
        if (!$course_id || $title === '') { flash_set('danger','Wybierz kurs i podaj tytuł zadania.'); header('Location: homework.php'); exit; }
        if ($session_id && !db_one("SELECT 1 FROM k30_ti_sessions WHERE id=? AND course_id=?", [$session_id, $course_id])) $session_id = null;

        try { $up = k30_ti_homework_upload('attach', 'hw'); }
        catch (\Throwable $e) { flash_set('danger', $e->getMessage()); header('Location: homework.php'); exit; }

        if ($hid) {
            $hw = k30_ti_homework_get($hid);
            $set = ['course_id'=>$course_id, 'session_id'=>$session_id, 'title'=>$title, 'description'=>$desc,
                    'hint'=>$hint, 'due_at'=>$due_sql, 'open_at'=>$open_at, 'close_at'=>$close_at,
                    'is_active'=>isset($_POST['is_active'])?1:0];
            if ($up) { // nowy załącznik — usuń stary
                if ($hw && $hw['attach_path'] !== '') k30_ti_homework_delete_file($hw['attach_path']);
                $set['attach_name'] = $up['name']; $set['attach_path'] = $up['stored'];
            }
            $cols=[];$p=[]; foreach ($set as $k=>$v){$cols[]="$k=?";$p[]=$v;} $p[]=$hid;
            db()->prepare("UPDATE k30_ti_homework SET ".implode(',',$cols)." WHERE id=?")->execute($p);
            if (isset($_POST['notify'])) {
                k30_ti_notify_dydaktyka($course_id, 'Zmiana w zadaniu: ' . $title,
                    'Prowadzący zaktualizował zadanie domowe „' . htmlspecialchars($title, ENT_QUOTES) . '".',
                    rtrim(APP_URL,'/') . '/karty30/ti/kursant/index.php?tab=zadania',
                    (defined('ORG_NAME')?ORG_NAME:'TI') . ': zmiana w zadaniu "' . $title . '".');
            }
            flash_set('success','Zadanie zaktualizowane.');
        } else {
            db_insert('k30_ti_homework', [
                'course_id'=>$course_id, 'session_id'=>$session_id, 'title'=>$title, 'description'=>$desc,
                'hint'=>$hint, 'due_at'=>$due_sql, 'open_at'=>$open_at, 'close_at'=>$close_at,
                'attach_name'=>$up['name']??'', 'attach_path'=>$up['stored']??'',
                'is_active'=>1, 'created_by'=>current_user()['id']??null,
            ]);
            if (isset($_POST['notify'])) {
                k30_ti_notify_dydaktyka($course_id, 'Nowe zadanie: ' . $title,
                    'Prowadzący dodał nowe zadanie domowe „' . htmlspecialchars($title, ENT_QUOTES) . '"'
                        . ($due_sql ? ' (termin: ' . substr($due_sql,0,16) . ')' : '') . '.',
                    rtrim(APP_URL,'/') . '/karty30/ti/kursant/index.php?tab=zadania',
                    (defined('ORG_NAME')?ORG_NAME:'TI') . ': nowe zadanie "' . $title . '"' . ($due_sql ? ', termin ' . substr($due_sql,0,16) : '') . '.');
            }
            flash_set('success','Zadanie utworzone.');
        }
        header('Location: homework.php'); exit;
    }

    if ($op === 'delete_homework') {
        if (!$can_delete) { http_response_code(403); die('Brak uprawnień.'); }
        $hid = (int)($_POST['homework_id'] ?? 0);
        $hw  = $hid ? k30_ti_homework_get($hid) : null;
        if ($hw) {
            // usuń pliki oddań + załącznik
            foreach (db_all("SELECT file_path FROM k30_ti_homework_submissions WHERE homework_id=?", [$hid]) as $s) {
                k30_ti_homework_delete_file($s['file_path']);
            }
            k30_ti_homework_delete_file($hw['attach_path']);
            db()->prepare("DELETE FROM k30_ti_homework WHERE id=?")->execute([$hid]);
            flash_set('success','Zadanie usunięte.');
        }
        header('Location: homework.php'); exit;
    }

    if ($op === 'grade') {
        $sid = (int)($_POST['submission_id'] ?? 0);
        $s   = $sid ? db_one("SELECT * FROM k30_ti_homework_submissions WHERE id=?", [$sid]) : null;
        if ($s) {
            $grade = trim($_POST['grade'] ?? '');
            $fb    = trim($_POST['feedback'] ?? '');
            db()->prepare(
                "UPDATE k30_ti_homework_submissions
                 SET grade=?, feedback=?, status=?, graded_by=?, graded_at=datetime('now'), updated_at=datetime('now')
                 WHERE id=?"
            )->execute([$grade, $fb, ($grade!==''||$fb!=='')?'graded':'submitted', current_user()['id']??null, $sid]);
            // Auto-sync oceny do dziennika (e-dziennik)
            k30_ti_grade_sync_from_homework($sid, current_user()['id']??null);
            flash_set('success','Ocena zapisana' . ($grade!=='' ? ' i dodana do dziennika ocen.' : '.'));
        }
        header('Location: homework.php?id=' . (int)$s['homework_id']); exit;
    }
}

$courses   = k30_ti_courses(false);
$view_id   = (int)($_GET['id'] ?? 0);
$view_hw   = $view_id ? k30_ti_homework_get($view_id) : null;
$edit_id   = (int)($_GET['edit'] ?? 0);
$edit_row  = $edit_id ? k30_ti_homework_get($edit_id) : null;
$ef        = $edit_row ?: ['id'=>0,'course_id'=>0,'session_id'=>0,'title'=>'','description'=>'','hint'=>'','due_at'=>'','open_at'=>'','close_at'=>'','attach_name'=>'','is_active'=>1];
$dtv       = fn($v) => $v ? h(str_replace(' ','T',substr($v,0,16))) : '';
$all_sessions = db_all("SELECT id, course_id, lesson_date, topic FROM k30_ti_sessions ORDER BY lesson_date DESC, id DESC");
$homeworks = k30_ti_homework_list();
$subs      = $view_hw ? k30_ti_homework_submissions($view_id) : [];
$now       = date('Y-m-d H:i:s');

include dirname(dirname(__DIR__)) . '/karty30/includes/header_k30.php';
?>

<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/karty30/index.php">Dydaktyka 3</a></li>
  <li class="breadcrumb-item"><a href="index.php">Zajęcia TI</a></li>
  <li class="breadcrumb-item active">Zadania domowe</li>
</ol></nav>

<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <h4 class="mb-0 fw-bold"><i class="bi bi-journal-check text-primary me-2"></i>Zadania domowe</h4>
  <div class="ms-auto d-flex flex-wrap gap-2">
    <a href="materials.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-collection-play me-1"></i>Materiały / eLearning</a>
    <a href="grades.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-table me-1"></i>Dziennik ocen</a>
    <?php if ($can_write): ?>
    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#hwModal">
      <i class="bi bi-plus-lg me-1"></i>Nowe zadanie
    </button>
    <?php endif; ?>
  </div>
</div>

<?= flash_html() ?>

<?php if ($view_hw): /* ── Widok oddań jednego zadania ── */ ?>
<div class="card border-0 shadow-sm mb-4">
  <div class="card-header d-flex align-items-center">
    <span class="fw-semibold"><i class="bi bi-journal-text me-2"></i><?= h($view_hw['title']) ?></span>
    <span class="text-muted small ms-2"><?= h($view_hw['course_name']) ?><?php if ($view_hw['due_at']): ?> · termin: <?= h(substr($view_hw['due_at'],0,16)) ?><?php endif; ?></span>
    <a href="homework.php" class="btn-close ms-auto" aria-label="Zamknij"></a>
  </div>
  <div class="card-body">
    <?php if ($view_hw['description']): ?><p class="mb-2" style="white-space:pre-wrap"><?= h($view_hw['description']) ?></p><?php endif; ?>
    <?php if (trim((string)($view_hw['hint'] ?? '')) !== ''): ?>
    <div class="alert alert-info py-2 mb-2"><i class="bi bi-lightbulb me-1" aria-hidden="true"></i><strong>Podpowiedź:</strong> <span style="white-space:pre-wrap"><?= h($view_hw['hint']) ?></span></div>
    <?php endif; ?>
    <?php if ($view_hw['attach_path']): ?>
    <p class="mb-0"><i class="bi bi-paperclip me-1"></i><a href="?dl=attach&hw=<?= (int)$view_hw['id'] ?>"><?= h($view_hw['attach_name']) ?></a></p>
    <?php endif; ?>
  </div>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0" style="font-size:.86rem">
      <thead class="table-light"><tr><th>Kursant</th><th>Oddano</th><th>Plik</th><th>Treść</th><th>Ocena</th><th class="text-end">Akcje</th></tr></thead>
      <tbody>
        <?php if (!$subs): ?><tr><td colspan="6" class="text-center text-muted py-3">Brak oddań.</td></tr><?php endif; ?>
        <?php foreach ($subs as $s):
          $late = $view_hw['due_at'] && $s['submitted_at'] > $view_hw['due_at']; ?>
        <tr>
          <td class="fw-semibold"><?= h($s['client_name']) ?></td>
          <td class="small text-nowrap"><?= h(substr($s['submitted_at'],0,16)) ?><?php if ($late): ?> <span class="badge bg-warning text-dark">po terminie</span><?php endif; ?></td>
          <td><?php if ($s['file_path']): ?><a href="?dl=sub&id=<?= (int)$s['id'] ?>" class="small"><i class="bi bi-download me-1"></i><?= h(mb_substr($s['file_name'],0,28)) ?></a><?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
          <td class="small" style="max-width:240px"><?= $s['body'] ? nl2br(h(mb_substr($s['body'],0,300))) : '<span class="text-muted">—</span>' ?></td>
          <td><?php if ($s['status']==='graded'): ?><span class="badge bg-success"><?= h($s['grade'] ?: 'ocenione') ?></span><?php else: ?><span class="badge bg-secondary">oddane</span><?php endif; ?></td>
          <td class="text-end">
            <?php if ($can_write): ?>
            <button type="button" class="btn btn-xs btn-sm btn-outline-primary py-0 px-2" data-bs-toggle="collapse" data-bs-target="#gr<?= (int)$s['id'] ?>"><i class="bi bi-pencil me-1"></i>Oceń</button>
            <?php endif; ?>
          </td>
        </tr>
        <?php if ($can_write): ?>
        <tr class="collapse" id="gr<?= (int)$s['id'] ?>">
          <td colspan="6" class="bg-light">
            <form method="post" class="row g-2 align-items-end">
              <input type="hidden" name="_csrf"          value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="_op"             value="grade">
              <input type="hidden" name="submission_id"   value="<?= (int)$s['id'] ?>">
              <div class="col-auto"><label class="form-label small mb-0">Ocena</label>
                <input type="text" name="grade" class="form-control form-control-sm" style="max-width:120px" value="<?= h($s['grade']) ?>" placeholder="np. 4 / 85%"></div>
              <div class="col"><label class="form-label small mb-0">Komentarz dla kursanta</label>
                <input type="text" name="feedback" class="form-control form-control-sm" value="<?= h($s['feedback']) ?>"></div>
              <div class="col-auto"><button class="btn btn-sm btn-primary"><i class="bi bi-save me-1"></i>Zapisz</button></div>
            </form>
          </td>
        </tr>
        <?php endif; ?>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- Lista zadań (pełna szerokość) -->
<div class="card border-0 shadow-sm">
  <div class="card-header fw-semibold"><i class="bi bi-list-check me-2"></i>Zadania <span class="badge bg-secondary ms-1"><?= count($homeworks) ?></span></div>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0" style="font-size:.86rem">
          <thead class="table-light"><tr><th>Tytuł</th><th>Kurs</th><th>Termin</th><th>Oddania</th><?php if ($can_write): ?><th class="text-end">Akcje</th><?php endif; ?></tr></thead>
          <tbody>
            <?php if (!$homeworks): ?><tr><td colspan="5" class="text-center text-muted py-3">Brak zadań. Utwórz pierwsze.</td></tr><?php endif; ?>
            <?php foreach ($homeworks as $h):
              $overdue = $h['due_at'] && $h['due_at'] < $now; ?>
            <tr class="<?= $h['is_active'] ? '' : 'opacity-50' ?>">
              <td><a href="?id=<?= (int)$h['id'] ?>" class="fw-semibold text-decoration-none"><?= h($h['title']) ?></a>
                <?php if (!$h['is_active']): ?><span class="badge bg-secondary ms-1">nieaktywne</span><?php endif; ?>
                <?php if ($h['attach_path']): ?><i class="bi bi-paperclip text-muted ms-1" title="załącznik"></i><?php endif; ?>
                <?php $hav = k30_ti_avail_status($h['open_at'] ?? null, $h['close_at'] ?? null, $now);
                  if ($hav['state']==='upcoming'): ?><span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle ms-1"><i class="bi bi-clock me-1"></i><?= h($hav['label']) ?></span>
                  <?php elseif ($hav['state']==='closed'): ?><span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle ms-1"><i class="bi bi-lock me-1"></i><?= h($hav['label']) ?></span><?php endif; ?>
              </td>
              <td class="small"><?= h($h['course_name']) ?></td>
              <td class="small text-nowrap <?= $overdue ? 'text-danger' : '' ?>"><?= $h['due_at'] ? h(substr($h['due_at'],0,16)) : '—' ?></td>
              <td><a href="?id=<?= (int)$h['id'] ?>" class="badge bg-info-subtle text-info-emphasis border border-info-subtle text-decoration-none"><?= (int)$h['sub_count'] ?> oddań · <?= (int)$h['graded_count'] ?> ocen.</a></td>
              <?php if ($can_write): ?>
              <td class="text-end text-nowrap">
                <a href="?edit=<?= (int)$h['id'] ?>" class="btn btn-xs btn-sm btn-outline-secondary py-0 px-2" title="Edytuj"><i class="bi bi-pencil"></i></a>
                <?php if ($can_delete): ?>
                <form method="post" class="d-inline" onsubmit="return confirm('Usunąć zadanie i wszystkie oddania?')">
                  <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                  <input type="hidden" name="_op" value="delete_homework">
                  <input type="hidden" name="homework_id" value="<?= (int)$h['id'] ?>">
                  <button class="btn btn-xs btn-sm btn-outline-danger py-0 px-2" title="Usuń"><i class="bi bi-trash"></i></button>
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
</div><!-- /lista -->

<!-- Formularz zadania (wyskakujące okno — przy dodawaniu/edycji) -->
<?php if ($can_write): ?>
<div class="modal fade" id="hwModal" tabindex="-1" aria-labelledby="hwModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="hwModalLabel"><i class="bi bi-<?= $edit_row ? 'pencil' : 'plus-lg' ?> me-2"></i><?= $edit_row ? 'Edytuj zadanie' : 'Nowe zadanie' ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
      </div>
      <div class="modal-body">
        <form method="post" enctype="multipart/form-data">
          <input type="hidden" name="_csrf"        value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="_op"           value="save_homework">
          <input type="hidden" name="homework_id"   value="<?= (int)$ef['id'] ?>">
          <div class="mb-2">
            <label class="form-label fw-semibold">Kurs / grupa <span class="text-danger">*</span></label>
            <select class="form-select" name="course_id" id="hw-course" required>
              <option value="">— wybierz —</option>
              <?php foreach ($courses as $c): ?>
              <option value="<?= (int)$c['id'] ?>" <?= (int)$ef['course_id']===(int)$c['id']?'selected':'' ?>><?= h($c['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-2">
            <label class="form-label">Powiązana lekcja <span class="text-muted small">(opc.)</span></label>
            <select class="form-select" name="session_id" id="hw-session">
              <option value="" data-course="">— bez powiązania —</option>
              <?php foreach ($all_sessions as $s): ?>
              <option value="<?= (int)$s['id'] ?>" data-course="<?= (int)$s['course_id'] ?>" <?= (int)($ef['session_id']??0)===(int)$s['id']?'selected':'' ?>>
                <?= h(substr($s['lesson_date'],0,10)) ?><?= $s['topic'] ? ' · '.h(mb_substr($s['topic'],0,40)) : '' ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-2">
            <label class="form-label fw-semibold">Tytuł <span class="text-danger">*</span></label>
            <input type="text" class="form-control" name="title" value="<?= h($ef['title']) ?>" required placeholder="np. Ćwiczenie 3 — formatowanie tekstu">
          </div>
          <div class="mb-2">
            <label class="form-label">Treść / polecenie</label>
            <textarea class="form-control" name="description" rows="4" placeholder="Opis zadania, wymagania…"><?= h($ef['description']) ?></textarea>
          </div>
          <div class="mb-2">
            <label class="form-label"><i class="bi bi-lightbulb me-1" aria-hidden="true"></i>Podpowiedź <span class="text-muted small">(opc.)</span></label>
            <textarea class="form-control" name="hint" rows="2" placeholder="Wskazówka dla kursanta — od czego zacząć, na co zwrócić uwagę…"><?= h($ef['hint'] ?? '') ?></textarea>
          </div>
          <div class="mb-2">
            <label class="form-label">Termin oddania <span class="text-muted small">(opc.)</span></label>
            <input type="datetime-local" class="form-control" name="due_at" value="<?= $dtv($ef['due_at']) ?>">
          </div>
          <div class="row g-2">
            <div class="col-6 mb-2">
              <label class="form-label">Otwarcie <span class="text-muted small">(opc.)</span></label>
              <input type="datetime-local" class="form-control" name="open_at" value="<?= $dtv($ef['open_at']) ?>">
            </div>
            <div class="col-6 mb-2">
              <label class="form-label">Zamknięcie <span class="text-muted small">(opc.)</span></label>
              <input type="datetime-local" class="form-control" name="close_at" value="<?= $dtv($ef['close_at']) ?>">
            </div>
          </div>
          <div class="form-text mb-2">Otwarcie/zamknięcie steruje dostępnością. Po dacie zamknięcia kursant nie może już oddać zadania.</div>
          <div class="mb-2">
            <label class="form-label">Załącznik prowadzącego <span class="text-muted small">(opc.)</span></label>
            <input type="file" class="form-control" name="attach">
            <?php if (!empty($ef['attach_name'])): ?><div class="form-text">Obecny: <?= h($ef['attach_name']) ?> (prześlij nowy, aby zastąpić)</div><?php endif; ?>
          </div>
          <?php if ($edit_row): ?>
          <div class="form-check form-switch mb-2">
            <input class="form-check-input" type="checkbox" name="is_active" id="hw_act" <?= $ef['is_active']?'checked':'' ?>>
            <label class="form-check-label" for="hw_act">Aktywne (widoczne dla kursantów)</label>
          </div>
          <?php endif; ?>
          <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" name="notify" id="hw_notify" value="1">
            <label class="form-check-label" for="hw_notify">Powiadom kursantów (e-mail / SMS wg ich ustawień)</label>
          </div>
          <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary"><?= $edit_row ? 'Zapisz' : 'Utwórz zadanie' ?></button>
            <?php if ($edit_row): ?><a href="homework.php" class="btn btn-outline-secondary">Anuluj</a><?php endif; ?>
          </div>
        </form>
      </div><!-- /modal-body -->
    </div><!-- /modal-content -->
  </div><!-- /modal-dialog -->
</div><!-- /modal -->
<?php endif; ?>

<script>
// Filtrowanie listy lekcji wg wybranego kursu.
(function(){
  var course = document.getElementById('hw-course');
  var sess   = document.getElementById('hw-session');
  if (!course || !sess) return;
  function refresh(keep){
    var cid = course.value;
    Array.prototype.forEach.call(sess.options, function(o){
      var oc = o.getAttribute('data-course');
      var show = (oc === '' || oc === cid);
      o.hidden = !show; o.disabled = !show;
    });
    if (!keep && sess.selectedOptions.length && sess.selectedOptions[0].hidden) sess.value = '';
  }
  course.addEventListener('change', function(){ refresh(false); });
  refresh(true);
})();
<?php if ($edit_row): ?>
(function(){
  var el = document.getElementById('hwModal');
  if (!el) return;
  bootstrap.Modal.getOrCreateInstance(el).show();
  // Zamknięcie okna edycji czyści stan edycji (wraca do czystej listy)
  el.addEventListener('hidden.bs.modal', function(){ window.location = 'homework.php'; });
})();
<?php endif; ?>
</script>

<?php include dirname(dirname(__DIR__)) . '/karty30/includes/footer_k30.php'; ?>
