<?php
/**
 * karty30/ti/grades.php — Dziennik ocen TI (e-dziennik).
 * Oceny szkolne (1–6, +/-) z kategorią, wagą i średnią ważoną — per kurs i kursant.
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
$PAGE_TITLE = 'Dziennik ocen — TI';
$CATS       = k30_ti_grade_categories();

$course_id  = (int)($_GET['course'] ?? 0);

// Eksport PDF dziennika ocen kursu
if (isset($_GET['pdf']) && $course_id) {
    require_once dirname(dirname(__DIR__)) . '/includes/ti_grades_pdf.php';
    ti_grades_pdf_course($course_id);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_write) {
    csrf_check();
    $op = $_POST['_op'] ?? '';

    if ($op === 'save_grade') {
        $gid       = (int)($_POST['grade_id'] ?? 0);
        $cid       = (int)($_POST['course_id'] ?? 0);
        $client_id = (int)($_POST['client_id'] ?? 0);
        $session_id= (int)($_POST['session_id'] ?? 0) ?: null;
        $cat       = trim($_POST['category'] ?? 'inne');
        if (!isset($CATS[$cat])) $cat = 'inne';
        $vtext     = trim($_POST['value_text'] ?? '');
        $weight    = (float)str_replace(',', '.', $_POST['weight'] ?? '1');
        if ($weight <= 0) $weight = 1;
        $desc      = trim($_POST['description'] ?? '');
        // kursant musi być zapisany w kursie
        $ok = $cid && $client_id && db_one("SELECT 1 FROM k30_ti_enrollments WHERE course_id=? AND client_id=?", [$cid, $client_id]);
        if (!$ok || $vtext === '') { flash_set('danger','Wybierz kurs, kursanta i wpisz ocenę.'); header('Location: grades.php?course='.$cid); exit; }
        if ($session_id && !db_one("SELECT 1 FROM k30_ti_sessions WHERE id=? AND course_id=?", [$session_id, $cid])) $session_id = null;
        $vnum = k30_ti_grade_parse_num($vtext);

        if ($gid) {
            db()->prepare(
                "UPDATE k30_ti_grades SET course_id=?, client_id=?, session_id=?, category=?, value_text=?, value_num=?, weight=?, description=? WHERE id=?"
            )->execute([$cid, $client_id, $session_id, $cat, $vtext, $vnum, $weight, $desc, $gid]);
            flash_set('success','Ocena zaktualizowana.');
        } else {
            db_insert('k30_ti_grades', [
                'course_id'=>$cid, 'client_id'=>$client_id, 'session_id'=>$session_id,
                'category'=>$cat, 'value_text'=>$vtext, 'value_num'=>$vnum, 'weight'=>$weight,
                'description'=>$desc, 'graded_by'=>current_user()['id']??null,
            ]);
            flash_set('success','Ocena wystawiona.');
        }
        // Powiadom kursanta o ocenie (opcjonalnie)
        if (isset($_POST['notify'])) {
            k30_ti_notify_grade($cid, $client_id, $vtext, $CATS[$cat]['label'] ?? $cat, $desc);
        }
        header('Location: grades.php?course='.$cid); exit;
    }

    if ($op === 'delete_grade') {
        if (!$can_delete) { http_response_code(403); die('Brak uprawnień.'); }
        $gid = (int)($_POST['grade_id'] ?? 0);
        $g   = $gid ? k30_ti_grade_get($gid) : null;
        if ($g) { db()->prepare("DELETE FROM k30_ti_grades WHERE id=?")->execute([$gid]); flash_set('success','Ocena usunięta.'); }
        header('Location: grades.php?course='.(int)($g['course_id'] ?? 0)); exit;
    }
}

$courses = k30_ti_courses(false);
$course  = $course_id ? k30_ti_course_get($course_id) : null;
$roster  = $course ? array_values(array_filter(k30_ti_enrollments($course_id), fn($e)=>$e['status']==='active')) : [];
$grades  = $course ? k30_ti_course_grades($course_id) : [];
$sessions= $course ? db_all("SELECT id, lesson_date, topic FROM k30_ti_sessions WHERE course_id=? ORDER BY lesson_date DESC, id DESC", [$course_id]) : [];

// Oceny pogrupowane wg kursanta
$by_client = [];
foreach ($grades as $g) { $by_client[(int)$g['client_id']][] = $g; }

// Edycja oceny / preselekcja kursanta
$edit_id  = (int)($_GET['edit'] ?? 0);
$edit_row = $edit_id ? k30_ti_grade_get($edit_id) : null;
$presel   = (int)($_GET['student'] ?? 0);
$gf = $edit_row ?: [
    'id'=>0, 'client_id'=>$presel, 'session_id'=>0,
    'category'=>'sprawdzian', 'value_text'=>'', 'weight'=>$CATS['sprawdzian']['weight'], 'description'=>'',
];

include dirname(dirname(__DIR__)) . '/karty30/includes/header_k30.php';
?>

<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/karty30/index.php">Karty 30</a></li>
  <li class="breadcrumb-item"><a href="index.php">Zajęcia TI</a></li>
  <li class="breadcrumb-item active">Dziennik ocen</li>
</ol></nav>

<div class="d-flex align-items-center mb-3 gap-2 flex-wrap">
  <h4 class="mb-0 fw-bold"><i class="bi bi-table text-primary me-2"></i>Dziennik ocen</h4>
  <a href="materials.php" class="btn btn-outline-secondary btn-sm ms-auto"><i class="bi bi-collection-play me-1"></i>Materiały</a>
  <a href="homework.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-journal-check me-1"></i>Zadania domowe</a>
</div>

<?= flash_html() ?>

<form method="get" class="card border-0 shadow-sm mb-4">
  <div class="card-body d-flex align-items-end gap-2 flex-wrap">
    <div>
      <label class="form-label fw-semibold mb-1">Kurs / grupa</label>
      <select class="form-select" name="course" onchange="this.form.submit()" style="min-width:260px">
        <option value="">— wybierz kurs —</option>
        <?php foreach ($courses as $c): ?>
        <option value="<?= (int)$c['id'] ?>" <?= $course_id===(int)$c['id']?'selected':'' ?>><?= h($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <noscript><button class="btn btn-primary">Pokaż</button></noscript>
  </div>
</form>

<?php if (!$course): ?>
<div class="alert alert-info"><i class="bi bi-info-circle me-1"></i>Wybierz kurs, aby zobaczyć i wystawiać oceny.</div>
<?php else: ?>

<div>
  <!-- Dziennik: kursanci × oceny -->
  <div>
    <div class="card border-0 shadow-sm">
      <div class="card-header fw-semibold d-flex align-items-center gap-2 flex-wrap">
        <span><i class="bi bi-people me-2"></i><?= h($course['name']) ?></span>
        <span class="badge bg-secondary"><?= count($roster) ?> kursantów</span>
        <?php if ($can_write): ?>
        <a href="?course=<?= $course_id ?>&new=1" class="btn btn-sm btn-primary ms-auto">
          <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Wystaw ocenę
        </a>
        <a href="?course=<?= $course_id ?>&pdf=1" class="btn btn-sm btn-outline-danger"><i class="bi bi-file-earmark-pdf me-1"></i>PDF</a>
        <?php else: ?>
        <a href="?course=<?= $course_id ?>&pdf=1" class="btn btn-sm btn-outline-danger ms-auto"><i class="bi bi-file-earmark-pdf me-1"></i>PDF</a>
        <?php endif; ?>
      </div>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead class="table-light"><tr><th>Kursant</th><th>Oceny</th><th class="text-end text-nowrap">Średnia</th><?php if ($can_write): ?><th></th><?php endif; ?></tr></thead>
          <tbody>
            <?php if (!$roster): ?><tr><td colspan="4" class="text-center text-muted py-3">Brak aktywnych kursantów w kursie.</td></tr><?php endif; ?>
            <?php foreach ($roster as $e):
              $cgr = $by_client[(int)$e['client_id']] ?? [];
              $avg = k30_ti_grades_average($cgr);
              [$abg,$afg] = k30_ti_grade_color($avg);
            ?>
            <tr>
              <td class="fw-semibold text-nowrap"><?= h($e['client_name']) ?></td>
              <td>
                <?php if (!$cgr): ?><span class="text-muted small">— brak ocen —</span><?php endif; ?>
                <div class="d-flex flex-wrap gap-1">
                  <?php foreach ($cgr as $g): ?>
                    <?php if ($can_write): ?><a href="?course=<?= $course_id ?>&edit=<?= (int)$g['id'] ?>" class="text-decoration-none" aria-label="Edytuj ocenę"><?= k30_ti_grade_badge($g) ?></a>
                    <?php else: ?><?= k30_ti_grade_badge($g) ?><?php endif; ?>
                  <?php endforeach; ?>
                </div>
              </td>
              <td class="text-end">
                <?php if ($avg !== null): ?>
                <span class="badge" style="background:<?= $abg ?>;color:<?= $afg ?>;font-size:.9rem"><?= number_format($avg, 2, ',', '') ?></span>
                <?php else: ?><span class="text-muted">—</span><?php endif; ?>
              </td>
              <?php if ($can_write): ?>
              <td class="text-end">
                <a href="?course=<?= $course_id ?>&student=<?= (int)$e['client_id'] ?>" class="btn btn-xs btn-sm btn-outline-primary py-0 px-2" title="Wystaw ocenę" aria-label="Wystaw ocenę: <?= h($e['client_name']) ?>"><i class="bi bi-plus-lg" aria-hidden="true"></i></a>
              </td>
              <?php endif; ?>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Ostatnie wpisy (z opisem i możliwością usunięcia) -->
    <div class="card border-0 shadow-sm mt-4">
      <div class="card-header fw-semibold"><i class="bi bi-clock-history me-2"></i>Ostatnie oceny</div>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0" style="font-size:.86rem">
          <thead class="table-light"><tr><th>Data</th><th>Kursant</th><th>Ocena</th><th>Kategoria</th><th>Waga</th><th>Za co</th><th>Wystawił(a)</th><?php if ($can_write): ?><th></th><?php endif; ?></tr></thead>
          <tbody>
            <?php if (!$grades): ?><tr><td colspan="8" class="text-center text-muted py-3">Brak ocen.</td></tr><?php endif; ?>
            <?php foreach (array_slice($grades, 0, 50) as $g): ?>
            <tr>
              <td class="text-nowrap small"><?= h(substr($g['graded_at'],0,10)) ?></td>
              <td class="text-nowrap"><?= h($g['client_name']) ?></td>
              <td><?= k30_ti_grade_badge($g) ?></td>
              <td class="small"><?= h(k30_ti_grade_category_label($g['category'])) ?></td>
              <td class="small"><?= h(rtrim(rtrim(number_format((float)$g['weight'],2,'.',''),'0'),'.') ?: '1') ?></td>
              <td class="small"><?= $g['description'] ? h($g['description']) : '<span class="text-muted">—</span>' ?></td>
              <td class="small text-nowrap"><?= !empty($g['graded_by_name']) ? h($g['graded_by_name']) : '<span class="text-muted">—</span>' ?></td>
              <?php if ($can_write): ?>
              <td class="text-end text-nowrap">
                <a href="?course=<?= $course_id ?>&edit=<?= (int)$g['id'] ?>" class="btn btn-xs btn-sm btn-outline-secondary py-0 px-2" title="Edytuj"><i class="bi bi-pencil"></i></a>
                <?php if ($can_delete): ?>
                <form method="post" class="d-inline" onsubmit="return confirm('Usunąć ocenę?')">
                  <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                  <input type="hidden" name="_op" value="delete_grade">
                  <input type="hidden" name="grade_id" value="<?= (int)$g['id'] ?>">
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
  </div>

</div>

<!-- ══ Modal: wystawianie / edycja oceny (pop-up, WAI-ARIA dialog) ══════════ -->
<?php if ($can_write): ?>
<div class="modal fade" id="gradeModal" tabindex="-1" aria-labelledby="gradeModalTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <form method="post">
        <input type="hidden" name="_csrf"     value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="_op"        value="save_grade">
        <input type="hidden" name="grade_id"   value="<?= (int)$gf['id'] ?>">
        <input type="hidden" name="course_id"  value="<?= $course_id ?>">
        <div class="modal-header">
          <h2 class="modal-title h5" id="gradeModalTitle"><i class="bi bi-<?= $edit_row ? 'pencil' : 'plus-lg' ?> me-2" aria-hidden="true"></i><?= $edit_row ? 'Edytuj ocenę' : 'Wystaw ocenę' ?></h2>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
        </div>
        <div class="modal-body">
          <div class="mb-2">
            <label class="form-label fw-semibold" for="grade-client">Kursant <span class="text-danger" aria-hidden="true">*</span></label>
            <select class="form-select" id="grade-client" name="client_id" required <?= $edit_row?'disabled':'' ?>>
              <option value="">— wybierz —</option>
              <?php foreach ($roster as $e): ?>
              <option value="<?= (int)$e['client_id'] ?>" <?= (int)$gf['client_id']===(int)$e['client_id']?'selected':'' ?>><?= h($e['client_name']) ?></option>
              <?php endforeach; ?>
            </select>
            <?php if ($edit_row): ?><input type="hidden" name="client_id" value="<?= (int)$gf['client_id'] ?>"><?php endif; ?>
          </div>
          <div class="row g-2">
            <div class="col-7 mb-2">
              <label class="form-label fw-semibold" for="grade-cat">Kategoria</label>
              <select class="form-select" name="category" id="grade-cat">
                <?php foreach ($CATS as $slug=>$ci): ?>
                <option value="<?= h($slug) ?>" data-weight="<?= (float)$ci['weight'] ?>" <?= $gf['category']===$slug?'selected':'' ?>><?= h($ci['label']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-5 mb-2">
              <label class="form-label fw-semibold" for="grade-weight">Waga</label>
              <input type="number" class="form-control" name="weight" id="grade-weight" min="0.5" max="10" step="0.5" value="<?= h(rtrim(rtrim(number_format((float)$gf['weight'],2,'.',''),'0'),'.') ?: '1') ?>">
            </div>
          </div>
          <div class="mb-2">
            <label class="form-label fw-semibold" for="grade-value">Ocena <span class="text-danger" aria-hidden="true">*</span></label>
            <div class="d-flex flex-wrap gap-1 mb-2" id="grade-quick">
              <?php foreach (['1','2-','2','2+','3-','3','3+','4-','4','4+','5-','5','5+','6'] as $q): ?>
              <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 grade-q"><?= h($q) ?></button>
              <?php endforeach; ?>
              <?php foreach (['np','bz','nb'] as $q): ?>
              <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 grade-q" title="nie liczy się do średniej"><?= h($q) ?></button>
              <?php endforeach; ?>
            </div>
            <input type="text" class="form-control" name="value_text" id="grade-value" maxlength="8" value="<?= h($gf['value_text']) ?>" required placeholder="np. 5, 4+, 2-, np">
            <div class="form-text">1–6 z opcjonalnym + / −. Wpisy <code>np</code>, <code>bz</code>, <code>nb</code> nie liczą się do średniej.</div>
          </div>
          <div class="mb-2">
            <label class="form-label" for="grade-desc">Za co / opis</label>
            <input type="text" class="form-control" id="grade-desc" name="description" value="<?= h($gf['description']) ?>" placeholder="np. Sprawdzian — pętle i funkcje">
          </div>
          <div class="mb-3">
            <label class="form-label" for="grade-session">Powiązana lekcja <span class="text-muted small">(opc.)</span></label>
            <select class="form-select" id="grade-session" name="session_id">
              <option value="">— bez powiązania —</option>
              <?php foreach ($sessions as $s): ?>
              <option value="<?= (int)$s['id'] ?>" <?= (int)$gf['session_id']===(int)$s['id']?'selected':'' ?>><?= h(substr($s['lesson_date'],0,10)) ?><?= $s['topic'] ? ' · '.h(mb_substr($s['topic'],0,30)) : '' ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" role="switch" name="notify" id="grade-notify" value="1" checked>
            <label class="form-check-label" for="grade-notify">Powiadom kursanta e-mailem o ocenie</label>
          </div>
        </div>
        <div class="modal-footer">
          <?php if ($edit_row): ?><a href="grades.php?course=<?= $course_id ?>" class="btn btn-outline-secondary">Anuluj</a>
          <?php else: ?><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button><?php endif; ?>
          <button type="submit" class="btn btn-primary"><?= $edit_row ? 'Zapisz' : 'Wystaw ocenę' ?></button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
(function(){
  var cat = document.getElementById('grade-cat');
  var w   = document.getElementById('grade-weight');
  var val = document.getElementById('grade-value');
  // Auto-waga z kategorii (gdy użytkownik nie zmienił ręcznie)
  if (cat && w) {
    cat.addEventListener('change', function(){
      var dw = cat.selectedOptions[0] && cat.selectedOptions[0].getAttribute('data-weight');
      if (dw) w.value = dw;
    });
  }
  // Szybkie przyciski ocen
  if (val) {
    Array.prototype.forEach.call(document.querySelectorAll('.grade-q'), function(b){
      b.addEventListener('click', function(){ val.value = b.textContent.trim(); val.focus(); });
    });
  }
  // Auto-otwarcie pop-upu: nowa ocena / edycja / wystawianie dla wybranego kursanta
  <?php if ($can_write && ($edit_row || $presel || isset($_GET['new']))): ?>
  var gm = document.getElementById('gradeModal');
  if (gm && window.bootstrap) { new bootstrap.Modal(gm).show(); }
  <?php endif; ?>
})();
</script>
<?php endif; ?>

<?php include dirname(dirname(__DIR__)) . '/karty30/includes/footer_k30.php'; ?>
