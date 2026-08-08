<?php
/**
 * karty30/ti/materials.php — Materiały dydaktyczne / eLearning TI.
 * Materiały powiązane z kursem i (opcjonalnie) z konkretną lekcją:
 * zadanie, link, plik, dokumentacja, wideo, prezentacja, inne.
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
$PAGE_TITLE = 'Materiały / eLearning — TI';
$TYPES      = k30_ti_material_types();

// ── Pobieranie pliku materiału (prowadzący) ──────────────────────────────────
if (isset($_GET['dl'])) {
    $m = k30_ti_material_get((int)($_GET['dl'] ?? 0));
    if ($m && $m['attach_path'] !== '') k30_ti_homework_send_file($m['attach_path'], $m['attach_name']);
    http_response_code(404); exit('Plik nie istnieje.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_write) {
    csrf_check();
    $op = $_POST['_op'] ?? '';

    if ($op === 'save_material') {
        $mid       = (int)($_POST['material_id'] ?? 0);
        $course_id = (int)($_POST['course_id'] ?? 0);
        $session_id= (int)($_POST['session_id'] ?? 0) ?: null;
        $type      = trim($_POST['type'] ?? 'material');
        if (!isset($TYPES[$type])) $type = 'inne';
        $title     = trim($_POST['title'] ?? '');
        $desc      = trim($_POST['description'] ?? '');
        $url       = trim($_POST['url'] ?? '');
        $dt        = fn($k) => ($v = trim($_POST[$k] ?? '')) !== '' ? str_replace('T',' ',$v) . (strlen($v)===16?':00':'') : null;
        $open_at   = $dt('open_at');
        $close_at  = $dt('close_at');
        if (!$course_id || $title === '') { flash_set('danger','Wybierz kurs i podaj tytuł materiału.'); header('Location: materials.php'); exit; }
        // Lekcja musi należeć do wybranego kursu
        if ($session_id) {
            $own = db_one("SELECT id FROM k30_ti_sessions WHERE id=? AND course_id=?", [$session_id, $course_id]);
            if (!$own) $session_id = null;
        }

        try { $up = k30_ti_homework_upload('attach', 'mat'); }
        catch (\Throwable $e) { flash_set('danger', $e->getMessage()); header('Location: materials.php'); exit; }

        if ($mid) {
            $m = k30_ti_material_get($mid);
            $set = ['course_id'=>$course_id, 'session_id'=>$session_id, 'type'=>$type,
                    'title'=>$title, 'description'=>$desc, 'url'=>$url,
                    'open_at'=>$open_at, 'close_at'=>$close_at,
                    'is_active'=>isset($_POST['is_active'])?1:0];
            if ($up) { // nowy załącznik — usuń stary
                if ($m && $m['attach_path'] !== '') k30_ti_homework_delete_file($m['attach_path']);
                $set['attach_name'] = $up['name']; $set['attach_path'] = $up['stored'];
            }
            $cols=[];$p=[]; foreach ($set as $k=>$v){$cols[]="$k=?";$p[]=$v;} $p[]=$mid;
            db()->prepare("UPDATE k30_ti_materials SET ".implode(',',$cols)." WHERE id=?")->execute($p);
            if (isset($_POST['notify'])) {
                k30_ti_notify_dydaktyka($course_id, 'Zmiana w materiale: ' . $title,
                    'Prowadzący zaktualizował materiał „' . htmlspecialchars($title, ENT_QUOTES) . '" w sekcji Dydaktyka / eLearning.',
                    rtrim(APP_URL,'/') . '/karty30/ti/kursant/index.php?tab=zadania',
                    (defined('ORG_NAME')?ORG_NAME:'TI') . ': zaktualizowano material "' . $title . '".');
            }
            flash_set('success','Materiał zaktualizowany.');
        } else {
            $new_id = db_insert('k30_ti_materials', [
                'course_id'=>$course_id, 'session_id'=>$session_id, 'type'=>$type,
                'title'=>$title, 'description'=>$desc, 'url'=>$url,
                'open_at'=>$open_at, 'close_at'=>$close_at,
                'attach_name'=>$up['name']??'', 'attach_path'=>$up['stored']??'',
                'is_active'=>1, 'created_by'=>current_user()['id']??null,
            ]);
            if (isset($_POST['notify'])) {
                k30_ti_notify_dydaktyka($course_id, 'Nowy materiał: ' . $title,
                    'Prowadzący dodał nowy materiał „' . htmlspecialchars($title, ENT_QUOTES) . '" w sekcji Dydaktyka / eLearning.',
                    rtrim(APP_URL,'/') . '/karty30/ti/kursant/index.php?tab=zadania',
                    (defined('ORG_NAME')?ORG_NAME:'TI') . ': nowy material "' . $title . '" w panelu kursanta.');
            }
            flash_set('success','Materiał dodany.');
        }
        header('Location: materials.php'); exit;
    }

    if ($op === 'delete_material') {
        if (!$can_delete) { http_response_code(403); die('Brak uprawnień.'); }
        $mid = (int)($_POST['material_id'] ?? 0);
        $m   = $mid ? k30_ti_material_get($mid) : null;
        if ($m) {
            k30_ti_homework_delete_file($m['attach_path']);
            db()->prepare("DELETE FROM k30_ti_materials WHERE id=?")->execute([$mid]);
            flash_set('success','Materiał usunięty.');
        }
        header('Location: materials.php'); exit;
    }
}

$courses   = k30_ti_courses(false);
$edit_id   = (int)($_GET['edit'] ?? 0);
$edit_row  = $edit_id ? k30_ti_material_get($edit_id) : null;
$ef        = $edit_row ?: ['id'=>0,'course_id'=>0,'session_id'=>0,'type'=>'zadanie','title'=>'','description'=>'','url'=>'','attach_name'=>'','open_at'=>'','close_at'=>'','is_active'=>1];
$dtv       = fn($v) => $v ? h(str_replace(' ','T',substr($v,0,16))) : '';
$materials = k30_ti_materials_list();
// Wszystkie lekcje (do selecta powiązania, filtrowane po kursie w JS)
$all_sessions = db_all("SELECT id, course_id, lesson_date, topic FROM k30_ti_sessions ORDER BY lesson_date DESC, id DESC");

include dirname(dirname(__DIR__)) . '/karty30/includes/header_k30.php';
?>

<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/karty30/index.php">Dydaktyka 3</a></li>
  <li class="breadcrumb-item"><a href="index.php">Zajęcia TI</a></li>
  <li class="breadcrumb-item active">Materiały / eLearning</li>
</ol></nav>

<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <h4 class="mb-0 fw-bold"><i class="bi bi-collection-play text-primary me-2"></i>Materiały dydaktyczne / eLearning</h4>
  <div class="ms-auto d-flex flex-wrap gap-2">
    <a href="homework.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-journal-check me-1"></i>Zadania domowe</a>
    <a href="grades.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-table me-1"></i>Dziennik ocen</a>
    <?php if ($can_write): ?>
    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#matModal">
      <i class="bi bi-plus-lg me-1"></i>Nowy materiał
    </button>
    <?php endif; ?>
  </div>
</div>

<?= flash_html() ?>

<!-- Lista materiałów (pełna szerokość) -->
<div class="card border-0 shadow-sm">
  <div class="card-header fw-semibold"><i class="bi bi-collection me-2"></i>Materiały <span class="badge bg-secondary ms-1"><?= count($materials) ?></span></div>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0" style="font-size:.86rem">
          <thead class="table-light"><tr><th>Typ</th><th>Tytuł</th><th>Kurs / lekcja</th><th>Zasób</th><?php if ($can_write): ?><th class="text-end">Akcje</th><?php endif; ?></tr></thead>
          <tbody>
            <?php if (!$materials): ?><tr><td colspan="5" class="text-center text-muted py-3">Brak materiałów. Dodaj pierwszy.</td></tr><?php endif; ?>
            <?php foreach ($materials as $m): ?>
            <tr class="<?= $m['is_active'] ? '' : 'opacity-50' ?>">
              <td class="text-nowrap"><span class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle"><i class="bi bi-<?= h(k30_ti_material_type_icon($m['type'])) ?> me-1"></i><?= h(k30_ti_material_type_label($m['type'])) ?></span></td>
              <td>
                <span class="fw-semibold"><?= h($m['title']) ?></span>
                <?php if (!$m['is_active']): ?><span class="badge bg-secondary ms-1">ukryte</span><?php endif; ?>
                <?php $av = k30_ti_avail_status($m['open_at'] ?? null, $m['close_at'] ?? null);
                  if ($av['state']==='upcoming'): ?><span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle ms-1"><i class="bi bi-clock me-1"></i><?= h($av['label']) ?></span>
                  <?php elseif ($av['state']==='closed'): ?><span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle ms-1"><i class="bi bi-lock me-1"></i><?= h($av['label']) ?></span>
                  <?php elseif (($m['close_at'] ?? '')!==''): ?><span class="badge bg-success-subtle text-success-emphasis border border-success-subtle ms-1"><?= h($av['label']) ?></span><?php endif; ?>
                <?php if ($m['description']): ?><div class="text-muted small" style="max-width:280px"><?= h(mb_substr($m['description'],0,120)) ?></div><?php endif; ?>
              </td>
              <td class="small"><?= h($m['course_name']) ?>
                <?php if ($m['session_date']): ?><div class="text-muted">lekcja <?= h(substr($m['session_date'],0,10)) ?><?= $m['session_topic'] ? ' · '.h(mb_substr($m['session_topic'],0,30)) : '' ?></div><?php endif; ?>
              </td>
              <td class="small">
                <?php if ($m['attach_path']): ?><a href="?dl=<?= (int)$m['id'] ?>"><i class="bi bi-download me-1"></i><?= h(mb_substr($m['attach_name'],0,24)) ?></a><?php endif; ?>
                <?php if ($m['url']): ?><a href="<?= h($m['url']) ?>" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right me-1"></i>link</a><?php endif; ?>
                <?php if (!$m['attach_path'] && !$m['url']): ?><span class="text-muted">—</span><?php endif; ?>
              </td>
              <?php if ($can_write): ?>
              <td class="text-end text-nowrap">
                <a href="?edit=<?= (int)$m['id'] ?>" class="btn btn-xs btn-sm btn-outline-secondary py-0 px-2" title="Edytuj"><i class="bi bi-pencil"></i></a>
                <?php if ($can_delete): ?>
                <form method="post" class="d-inline" onsubmit="return confirm('Usunąć materiał?')">
                  <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                  <input type="hidden" name="_op" value="delete_material">
                  <input type="hidden" name="material_id" value="<?= (int)$m['id'] ?>">
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

<!-- Formularz materiału (wyskakujące okno — przy dodawaniu/edycji) -->
<?php if ($can_write): ?>
<div class="modal fade" id="matModal" tabindex="-1" aria-labelledby="matModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="matModalLabel"><i class="bi bi-<?= $edit_row ? 'pencil' : 'plus-lg' ?> me-2"></i><?= $edit_row ? 'Edytuj materiał' : 'Nowy materiał' ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
      </div>
      <div class="modal-body">
        <form method="post" enctype="multipart/form-data">
          <input type="hidden" name="_csrf"        value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="_op"           value="save_material">
          <input type="hidden" name="material_id"   value="<?= (int)$ef['id'] ?>">
          <div class="mb-2">
            <label class="form-label fw-semibold">Typ materiału <span class="text-danger">*</span></label>
            <select class="form-select" name="type" required>
              <?php foreach ($TYPES as $slug=>$ti): ?>
              <option value="<?= h($slug) ?>" <?= $ef['type']===$slug?'selected':'' ?>><?= h($ti['label']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-2">
            <label class="form-label fw-semibold">Kurs / grupa <span class="text-danger">*</span></label>
            <select class="form-select" name="course_id" id="mat-course" required>
              <option value="">— wybierz —</option>
              <?php foreach ($courses as $c): ?>
              <option value="<?= (int)$c['id'] ?>" <?= (int)$ef['course_id']===(int)$c['id']?'selected':'' ?>><?= h($c['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-2">
            <label class="form-label">Powiązana lekcja <span class="text-muted small">(opc.)</span></label>
            <select class="form-select" name="session_id" id="mat-session">
              <option value="" data-course="">— bez powiązania —</option>
              <?php foreach ($all_sessions as $s): ?>
              <option value="<?= (int)$s['id'] ?>" data-course="<?= (int)$s['course_id'] ?>" <?= (int)$ef['session_id']===(int)$s['id']?'selected':'' ?>>
                <?= h(substr($s['lesson_date'],0,10)) ?><?= $s['topic'] ? ' · '.h(mb_substr($s['topic'],0,40)) : '' ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-2">
            <label class="form-label fw-semibold">Tytuł <span class="text-danger">*</span></label>
            <input type="text" class="form-control" name="title" value="<?= h($ef['title']) ?>" required placeholder="np. Dokumentacja HTML — MDN">
          </div>
          <div class="mb-2">
            <label class="form-label">Opis</label>
            <textarea class="form-control" name="description" rows="3" placeholder="Krótki opis materiału, polecenie do zadania…"><?= h($ef['description']) ?></textarea>
          </div>
          <div class="mb-2">
            <label class="form-label">Link (URL) <span class="text-muted small">(opc.)</span></label>
            <input type="url" class="form-control" name="url" value="<?= h($ef['url']) ?>" placeholder="https://…">
          </div>
          <div class="mb-2">
            <label class="form-label">Plik <span class="text-muted small">(opc., maks. 25 MB)</span></label>
            <input type="file" class="form-control" name="attach">
            <?php if (!empty($ef['attach_name'])): ?><div class="form-text">Obecny: <?= h($ef['attach_name']) ?> (prześlij nowy, aby zastąpić)</div><?php endif; ?>
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
          <div class="form-text mb-2">Materiał jest dostępny dla kursanta między datą otwarcia a zamknięcia. Puste = bez ograniczeń.</div>
          <?php if ($edit_row): ?>
          <div class="form-check form-switch mb-2">
            <input class="form-check-input" type="checkbox" name="is_active" id="mat_act" <?= $ef['is_active']?'checked':'' ?>>
            <label class="form-check-label" for="mat_act">Widoczne dla kursantów</label>
          </div>
          <?php endif; ?>
          <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" name="notify" id="mat_notify" value="1">
            <label class="form-check-label" for="mat_notify">Powiadom kursantów (e-mail / SMS wg ich ustawień)</label>
          </div>
          <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary"><?= $edit_row ? 'Zapisz' : 'Dodaj materiał' ?></button>
            <?php if ($edit_row): ?><a href="materials.php" class="btn btn-outline-secondary">Anuluj</a><?php endif; ?>
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
  var course = document.getElementById('mat-course');
  var sess   = document.getElementById('mat-session');
  if (!course || !sess) return;
  function refresh(keep){
    var cid = course.value;
    var cur = sess.value;
    Array.prototype.forEach.call(sess.options, function(o){
      var oc = o.getAttribute('data-course');
      var show = (oc === '' || oc === cid);
      o.hidden = !show;
      o.disabled = !show;
    });
    if (!keep && sess.selectedOptions.length && sess.selectedOptions[0].hidden) sess.value = '';
  }
  course.addEventListener('change', function(){ refresh(false); });
  refresh(true);
})();
<?php if ($edit_row): ?>
(function(){
  var el = document.getElementById('matModal');
  if (!el) return;
  bootstrap.Modal.getOrCreateInstance(el).show();
  // Zamknięcie okna edycji czyści stan edycji (wraca do czystej listy)
  el.addEventListener('hidden.bs.modal', function(){ window.location = 'materials.php'; });
})();
<?php endif; ?>
</script>

<?php include dirname(dirname(__DIR__)) . '/karty30/includes/footer_k30.php'; ?>
