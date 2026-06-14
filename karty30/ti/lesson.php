<?php
/**
 * karty30/ti/lesson.php — Lekcja TI: obecność, temat, uwagi prowadzącego, zadanie.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/karty30.php';

k30_require_access();
karty30_migrate();

$can_write  = can_write('karty30') || is_admin();
$session_id = (int)($_GET['id'] ?? 0);
$session    = $session_id ? k30_ti_session_get($session_id) : null;

if (!$session) {
    flash_set('danger', 'Lekcja nie istnieje.');
    header('Location: index.php');
    exit;
}

$PAGE_TITLE = 'Lekcja: ' . $session['course_name'];

// ── POST ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_write) {
    csrf_check();
    $op = $_POST['_op'] ?? '';

    // Zapis obecności + metadanych lekcji (jeden formularz)
    if ($op === 'save_lesson') {
        $attended = array_map('intval', (array)($_POST['attended'] ?? []));

        // Zapisz obecność (ogólna)
        k30_ti_save_attendance($session_id, $attended);

        // Zapisz indywidualne uwagi per uczestnik
        $ind_notes = (array)($_POST['ind_notes'] ?? []);
        foreach ($ind_notes as $cid => $note) {
            $cid = (int)$cid;
            $note = trim($note);
            try {
                db()->prepare(
                    "UPDATE k30_ti_attendance SET ind_notes=? WHERE session_id=? AND client_id=?"
                )->execute([$note, $session_id, $cid]);
            } catch (\Throwable $e) {}
        }

        // Metadane lekcji
        $topic            = trim($_POST['topic']            ?? '');
        $instructor_notes = trim($_POST['instructor_notes'] ?? '');
        $has_homework     = !empty($_POST['has_homework']) ? 1 : 0;
        $self_prep_remote = !empty($_POST['self_prep_remote']) ? 1 : 0;
        $duration_min     = max(1, (int)($_POST['duration_min'] ?? $session['duration_min']));
        $time_from        = trim($_POST['time_from'] ?? $session['time_from']);
        $time_to          = trim($_POST['time_to']   ?? $session['time_to']);
        // Przelicz czas trwania z od-do jeśli zmieniono godziny
        if ($time_from && $time_to) {
            $m = (strtotime('1970-01-01 '.$time_to) - strtotime('1970-01-01 '.$time_from)) / 60;
            if ($m > 0) $duration_min = (int)$m;
        }

        db()->prepare(
            "UPDATE k30_ti_sessions
             SET status='held', topic=?, instructor_notes=?, has_homework=?, self_prep_remote=?,
                 duration_min=?, time_from=?, time_to=?, updated_at=datetime('now')
             WHERE id=?"
        )->execute([$topic, $instructor_notes, $has_homework, $self_prep_remote, $duration_min, $time_from, $time_to, $session_id]);

        flash_set('success', 'Lekcja zapisana.');
        header('Location: lesson.php?id=' . $session_id);
        exit;
    }

    // Zmiana statusu bez zapisu obecności
    if ($op === 'set_status') {
        $st = array_key_exists($_POST['status'] ?? '', K30_TI_SESSION_STATUSES)
              ? $_POST['status'] : 'planned';
        db()->prepare("UPDATE k30_ti_sessions SET status=?, updated_at=datetime('now') WHERE id=?")
           ->execute([$st, $session_id]);
        header('Location: lesson.php?id=' . $session_id);
        exit;
    }
}

// Przeładuj
$session    = k30_ti_session_get($session_id);
$attendance = k30_ti_session_attendance($session_id);
$st_info    = K30_TI_SESSION_STATUSES[$session['status']] ?? ['label' => $session['status'], 'color' => '#666', 'bg' => '#eee'];
$is_held    = $session['status'] === 'held';

// Indywidualne uwagi (pobierz z bazy)
$ind_notes_map = [];
try {
    $rows = db_all("SELECT client_id, ind_notes FROM k30_ti_attendance WHERE session_id=?", [$session_id]);
    foreach ($rows as $r) $ind_notes_map[(int)$r['client_id']] = $r['ind_notes'];
} catch (\Throwable $e) {}

include dirname(dirname(__DIR__)) . '/karty30/includes/header_k30.php';
?>

<style>
.att-row.present  { background: #f0fdf4; }
.att-row.absent   { background: #fafafa; }
.att-cb           { width: 1.3em; height: 1.3em; flex-shrink: 0; cursor: pointer; }
.section-head     { font-size: .72rem; font-weight: 700; text-transform: uppercase; letter-spacing: .07em; color: #64748b; border-bottom: 1px solid #e2e8f0; padding-bottom: 4px; margin-bottom: 12px; }
</style>

<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb">
  <li class="breadcrumb-item"><a href="index.php">Zajęcia TI</a></li>
  <li class="breadcrumb-item"><a href="course.php?id=<?= (int)$session['course_id'] ?>"><?= h($session['course_name']) ?></a></li>
  <li class="breadcrumb-item active">Lekcja <?= date('d.m.Y', strtotime($session['lesson_date'])) ?></li>
</ol></nav>

<!-- Nagłówek -->
<div class="d-flex align-items-start mb-3 gap-2 flex-wrap">
  <div class="flex-grow-1">
    <h4 class="mb-0 fw-bold">
      <i class="bi bi-clipboard-check text-primary me-2"></i>
      <?= h($session['course_name']) ?>
      <span class="text-muted fw-normal fs-5">— <?= date('d.m.Y', strtotime($session['lesson_date'])) ?></span>
    </h4>
    <div class="text-muted small mt-1 d-flex align-items-center gap-2 flex-wrap">
      <?php if ($session['time_from']): ?>
      <span><i class="bi bi-clock me-1"></i><?= h($session['time_from']) ?>–<?= h($session['time_to']) ?> (<?= (int)$session['duration_min'] ?> min)</span>
      <?php else: ?>
      <span><?= (int)$session['duration_min'] ?> min</span>
      <?php endif; ?>
      <?php if ($session['instructor_name']): ?><span>·</span><span><?= h($session['instructor_name']) ?></span><?php endif; ?>
      <span class="badge" style="background:<?= h($st_info['bg']) ?>;color:<?= h($st_info['color']) ?>;border:1px solid <?= h($st_info['color']) ?>44">
        <?= h($st_info['label']) ?>
      </span>
      <?php if ($session['has_homework'] ?? 0): ?>
      <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle">
        <i class="bi bi-pencil-square me-1"></i>Zadanie domowe
      </span>
      <?php endif; ?>
      <?php if ($session['self_prep_remote'] ?? 0): ?>
      <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle">
        <i class="bi bi-laptop me-1"></i>Praca własna — materiał zdalny
      </span>
      <?php endif; ?>
    </div>
  </div>
  <?php if ($can_write && !$is_held): ?>
  <form method="post" class="flex-shrink-0">
    <input type="hidden" name="_csrf"   value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="_op"     value="set_status">
    <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
      <?php foreach (K30_TI_SESSION_STATUSES as $sk => $sv): ?>
      <option value="<?= h($sk) ?>" <?= $session['status']===$sk?'selected':'' ?>><?= h($sv['label']) ?></option>
      <?php endforeach; ?>
    </select>
  </form>
  <?php endif; ?>
</div>

<?= flash_html() ?>

<?php if ($session['status'] === 'cancelled'): ?>
<div class="alert alert-secondary">Lekcja odwołana.</div>
<?php else: ?>

<form method="post" id="lesson_form">
<input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
<input type="hidden" name="_op"   value="save_lesson">

<div class="row g-4">

  <!-- LEWA: Metadane lekcji -->
  <div class="col-lg-5">

    <!-- Temat i czas trwania -->
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-body">
        <div class="section-head">Informacje o lekcji</div>

        <div class="mb-3">
          <label class="form-label fw-semibold" for="topic">
            <i class="bi bi-journal-text me-1 text-primary"></i>Temat lekcji
          </label>
          <input type="text" class="form-control" id="topic" name="topic"
                 value="<?= h($session['topic'] ?? '') ?>"
                 placeholder="np. Obsługa poczty e-mail, Tworzenie dokumentów w Word…"
                 <?= !$can_write ? 'readonly' : '' ?>>
        </div>

        <!-- Godziny — od/do → czas trwania wyliczany automatycznie -->
        <div class="row g-2 mb-3">
          <div class="col-5">
            <label class="form-label fw-semibold" for="ltime_from">
              <i class="bi bi-clock me-1 text-muted"></i>Początek
            </label>
            <input type="time" class="form-control" id="ltime_from" name="time_from"
                   value="<?= h($session['time_from']) ?>"
                   onchange="recalcDur()"
                   <?= !$can_write ? 'readonly' : '' ?>>
          </div>
          <div class="col-5">
            <label class="form-label fw-semibold" for="ltime_to">
              <i class="bi bi-clock-fill me-1 text-muted"></i>Koniec
            </label>
            <input type="time" class="form-control" id="ltime_to" name="time_to"
                   value="<?= h($session['time_to']) ?>"
                   onchange="recalcDur()"
                   <?= !$can_write ? 'readonly' : '' ?>>
          </div>
          <div class="col-2 d-flex flex-column justify-content-end">
            <div class="text-center pb-1">
              <div class="text-muted" style="font-size:.68rem">czas</div>
              <div class="fw-bold" id="dur_display" style="font-size:1rem">
                <?php
                  $dm = (int)$session['duration_min'];
                  echo $dm >= 60
                    ? floor($dm/60).'h'.($dm%60 ? ' '.($dm%60).'m' : '')
                    : $dm.'m';
                ?>
              </div>
            </div>
          </div>
        </div>
        <!-- Ukryte pole duration_min — wyliczane przez JS -->
        <input type="hidden" id="ldur" name="duration_min" value="<?= (int)$session['duration_min'] ?>">

        <!-- Zadanie domowe -->
        <div class="form-check form-switch mb-2">
          <input class="form-check-input" type="checkbox" role="switch"
                 id="has_homework" name="has_homework" value="1"
                 <?= ($session['has_homework'] ?? 0) ? 'checked' : '' ?>
                 <?= !$can_write ? 'disabled' : '' ?>>
          <label class="form-check-label fw-semibold" for="has_homework">
            <i class="bi bi-pencil-square me-1 text-warning"></i>Zadano zadanie domowe
          </label>
        </div>

        <!-- Praca własna prowadzącego — materiał do wykonania zdalnie -->
        <div class="form-check form-switch mb-3">
          <input class="form-check-input" type="checkbox" role="switch"
                 id="self_prep_remote" name="self_prep_remote" value="1"
                 <?= ($session['self_prep_remote'] ?? 0) ? 'checked' : '' ?>
                 <?= !$can_write ? 'disabled' : '' ?>>
          <label class="form-check-label fw-semibold" for="self_prep_remote">
            <i class="bi bi-laptop me-1 text-info"></i>Praca własna prowadzącego — przygotowanie materiału do wykonania zdalnie
          </label>
        </div>

        <!-- Uwagi prowadzącego -->
        <div>
          <label class="form-label fw-semibold" for="inst_notes">
            <i class="bi bi-chat-square-text me-1 text-secondary"></i>Uwagi prowadzącego
          </label>
          <textarea class="form-control" id="inst_notes" name="instructor_notes"
                    rows="4" placeholder="Postępy grupy, trudności, tematy do powtórzenia…"
                    <?= !$can_write ? 'readonly' : '' ?>><?= h($session['instructor_notes'] ?? '') ?></textarea>
        </div>
      </div>
    </div>

    <!-- Podsumowanie (gdy odbyta) -->
    <?php if ($is_held && $attendance):
      $present = array_filter($attendance, fn($a) => $a['attended']);
      $total_h = (float)$session['duration_min'] / 60;
      $total_pln = array_sum(array_map(fn($a) => $total_h * (float)$a['hourly_rate'], $present));
    ?>
    <div class="card border-0 shadow-sm">
      <div class="card-body">
        <div class="section-head">Podsumowanie</div>
        <div class="d-flex gap-4 flex-wrap">
          <div><div class="text-muted small">Obecni</div>
            <div class="fw-bold fs-4 text-success"><?= count($present) ?><span class="text-muted fs-6">/<?= count($attendance) ?></span></div></div>
          <div><div class="text-muted small">Czas</div>
            <div class="fw-bold fs-4"><?= number_format($total_h,2,',','') ?> h</div></div>
          <div><div class="text-muted small">Kwota</div>
            <div class="fw-bold fs-4 text-primary"><?= number_format($total_pln,2,',','') ?> zł</div></div>
        </div>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- PRAWA: Lista obecności -->
  <div class="col-lg-7">
    <div class="card border-0 shadow-sm">
      <div class="card-header fw-semibold d-flex align-items-center">
        <i class="bi bi-person-check me-2 text-primary"></i>Lista obecności
        <span class="badge bg-secondary ms-2"><?= count($attendance) ?></span>
        <?php if ($can_write && $attendance): ?>
        <div class="ms-auto d-flex gap-2">
          <button type="button" class="btn btn-xs btn-sm btn-outline-secondary py-0 px-2"
                  onclick="toggleAll(true)">Wszyscy ✓</button>
          <button type="button" class="btn btn-xs btn-sm btn-outline-secondary py-0 px-2"
                  onclick="toggleAll(false)">Brak ✗</button>
        </div>
        <?php endif; ?>
      </div>

      <?php if (!$attendance): ?>
      <div class="card-body text-muted">
        Brak uczestników kursu.
        <a href="course.php?id=<?= (int)$session['course_id'] ?>#uczestnicy">Dodaj uczestników</a>.
      </div>
      <?php else: ?>
      <div class="list-group list-group-flush" id="att_list">
        <?php foreach ($attendance as $a):
          $present = (bool)$a['attended'];
          $note    = $ind_notes_map[(int)$a['client_id']] ?? '';
        ?>
        <div class="list-group-item att-row <?= $present ? 'present' : 'absent' ?> py-2 px-3"
             id="row_<?= (int)$a['client_id'] ?>">
          <div class="d-flex align-items-center gap-3">
            <input class="att-cb form-check-input" type="checkbox"
                   name="attended[]" value="<?= (int)$a['client_id'] ?>"
                   <?= $present ? 'checked' : '' ?>
                   onchange="rowToggle(this)"
                   <?= !$can_write ? 'disabled' : '' ?>>
            <div class="flex-grow-1 min-width-0">
              <div class="fw-semibold text-truncate"><?= h($a['client_name']) ?></div>
              <?php if ($a['client_email']): ?>
              <div class="text-muted" style="font-size:.75rem"><?= h($a['client_email']) ?></div>
              <?php endif; ?>
            </div>
            <div class="text-muted text-end flex-shrink-0" style="font-size:.78rem">
              <?= number_format((float)$a['hourly_rate'], 2, ',', '') ?> zł/h
            </div>
          </div>
          <!-- Uwagi indywidualne -->
          <div class="mt-1 ms-5">
            <input type="text"
                   class="form-control form-control-sm border-0 bg-transparent px-0"
                   name="ind_notes[<?= (int)$a['client_id'] ?>]"
                   value="<?= h($note) ?>"
                   placeholder="Uwaga do uczestnika…"
                   <?= !$can_write ? 'readonly' : '' ?>
                   style="font-size:.78rem;color:#64748b">
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <?php if ($can_write): ?>
      <div class="card-footer d-flex align-items-center justify-content-between gap-2">
        <span class="text-muted small" id="att_count">
          Zaznaczono: <strong id="att_num"><?= count(array_filter($attendance,fn($a)=>$a['attended'])) ?></strong>/<?= count($attendance) ?>
        </span>
        <button type="submit" class="btn btn-success">
          <i class="bi bi-check2-all me-1"></i>Zapisz lekcję
        </button>
      </div>
      <?php endif; ?>
    </div>
  </div>

</div><!-- /row -->
</form>
<?php endif; // $is_held ?>
<?php endif; // cancelled ?>

<script>
function recalcDur() {
  var tf = document.getElementById('ltime_from').value;
  var tt = document.getElementById('ltime_to').value;
  var disp = document.getElementById('dur_display');
  if (!tf || !tt) return;
  var m = Math.round((new Date('1970-01-01T'+tt) - new Date('1970-01-01T'+tf)) / 60000);
  if (m <= 0) { if (disp) disp.textContent = '?'; return; }
  document.getElementById('ldur').value = m;
  if (disp) {
    var h = Math.floor(m/60), min = m%60;
    disp.textContent = h > 0 ? h+'h'+(min?' '+min+'m':'') : min+'m';
  }
}

function rowToggle(cb) {
  var row = document.getElementById('row_' + cb.value);
  if (row) row.className = row.className.replace(/\b(present|absent)\b/, cb.checked ? 'present' : 'absent');
  updateCount();
}

function toggleAll(val) {
  document.querySelectorAll('.att-cb').forEach(function(cb) {
    cb.checked = val;
    rowToggle(cb);
  });
}

function updateCount() {
  var total   = document.querySelectorAll('.att-cb').length;
  var checked = document.querySelectorAll('.att-cb:checked').length;
  var el = document.getElementById('att_num');
  if (el) el.textContent = checked;
}

updateCount();
</script>

<?php include dirname(dirname(__DIR__)) . '/karty30/includes/footer_k30.php'; ?>
