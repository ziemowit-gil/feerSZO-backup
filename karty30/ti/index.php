<?php
/**
 * karty30/ti/index.php — Zajęcia TI: lista kursów.
 * Kurs = kontener z uczestnikami i stawkami. Lekcje zarządzają harmonogramem.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/karty30.php';
require_once dirname(dirname(__DIR__)) . '/includes/ti_messages.php';

k30_require_access();
karty30_migrate();

$PAGE_TITLE = 'Zajęcia TI — Karty 30';
$can_write  = can_write('karty30') || is_admin();
$msg_unread_staff = ti_msg_unread_for_staff();
$can_delete = is_admin(); // usuwanie kursów — tylko administrator (globalnie)

// Miękkie usuwanie kursu (status='cancelled') — tylko admin
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_op'] ?? '') === 'delete') {
    csrf_check();
    if (!$can_delete) { http_response_code(403); die('Brak uprawnień.'); }
    $cid = (int)($_POST['course_id'] ?? 0);
    if ($cid) {
        db()->prepare("UPDATE k30_ti_courses SET status='cancelled' WHERE id=?")->execute([$cid]);
        flash_set('success', 'Kurs usunięty.');
    }
    header('Location: index.php'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_write) {
    csrf_check();
    $data = [
        'name'                => trim($_POST['name'] ?? ''),
        'description'         => trim($_POST['description'] ?? ''),
        'instructor_id'       => ((int)($_POST['instructor_id'] ?? 0)) ?: null,
        'location'            => trim($_POST['location'] ?? ''),
        'default_meeting_url' => trim($_POST['default_meeting_url'] ?? ''),
        'billing_model'       => in_array((int)($_POST['billing_model'] ?? 2), [1,2,3], true) ? (int)$_POST['billing_model'] : 2,
        'billing_amount'      => max(0, (float)str_replace(',', '.', (string)($_POST['billing_amount'] ?? '0'))),
        'pay_account'         => trim($_POST['pay_account'] ?? ''),
        'pay_title'           => trim($_POST['pay_title'] ?? ''),
        'pay_due_days'        => ((int)($_POST['pay_due_days'] ?? 0)) ?: null,
        'is_active'           => isset($_POST['is_active']) ? 1 : 0,
    ];
    if (!$data['name']) { flash_set('danger','Nazwa kursu jest wymagana.'); header('Location: index.php'); exit; }

    $cid = (int)($_POST['course_id'] ?? 0);
    if ($cid) {
        $set=[]; $p=[];
        foreach ($data as $k=>$v){$set[]="$k=?";$p[]=$v;}
        $p[]=$cid;
        db()->prepare("UPDATE k30_ti_courses SET ".implode(',',$set)." WHERE id=?")->execute($p);
        flash_set('success','Kurs zaktualizowany.');
        header('Location: course.php?id='.$cid);
    } else {
        $data['created_by'] = current_user()['id'] ?? null;
        $data['created_at'] = date('Y-m-d H:i:s');
        $cid = db_insert('k30_ti_courses', $data);
        flash_set('success','Kurs utworzony.');
        header('Location: course.php?id='.$cid);
    }
    exit;
}

$courses     = k30_ti_courses(false);
$edit_id     = (int)($_GET['edit'] ?? 0);
$edit_row    = $edit_id ? k30_ti_course_get($edit_id) : null;
$show_new    = isset($_GET['new']);
$instructors = k30_get_consultants();

include dirname(dirname(__DIR__)) . '/karty30/includes/header_k30.php';
?>

<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/karty30/index.php">Karty 30</a></li>
  <li class="breadcrumb-item active">Zajęcia TI</li>
</ol></nav>

<div class="d-flex align-items-center mb-3 gap-2">
  <h4 class="mb-0 fw-bold"><i class="bi bi-pc-display text-primary me-2"></i>Zajęcia informatyki / TI</h4>
  <?php if ($can_write): ?>
  <a href="messages.php" class="btn btn-outline-secondary btn-sm position-relative <?= !$show_new && !$edit_row ? 'ms-auto' : '' ?>">
    <i class="bi bi-envelope me-1"></i>Wiadomości<?php if ($msg_unread_staff > 0): ?><span class="badge bg-danger ms-1"><?= (int)$msg_unread_staff ?></span><?php endif; ?>
  </a>
  <div class="dropdown">
    <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
      <i class="bi bi-grid me-1"></i>Zarządzanie
    </button>
    <ul class="dropdown-menu dropdown-menu-end">
      <li><h6 class="dropdown-header">Dydaktyka</h6></li>
      <li><a class="dropdown-item" href="homework.php"><i class="bi bi-journal-check me-2"></i>Zadania domowe</a></li>
      <li><a class="dropdown-item" href="urlopy.php"><i class="bi bi-airplane me-2"></i>Urlopy prowadzących</a></li>
      <li><a class="dropdown-item" href="billing.php"><i class="bi bi-receipt me-2"></i>Rozliczenia miesięczne</a></li>
      <li><hr class="dropdown-divider"></li>
      <li><h6 class="dropdown-header">Dostępy i narzędzia</h6></li>
      <li><a class="dropdown-item" href="online_admin.php"><i class="bi bi-camera-video me-2"></i>Nauka online</a></li>
      <li><a class="dropdown-item" href="moodle_admin.php"><i class="bi bi-mortarboard me-2"></i>Moodle (serwery / kursy)</a></li>
      <li><a class="dropdown-item" href="licencje_admin.php"><i class="bi bi-key me-2"></i>Licencje</a></li>
      <li><a class="dropdown-item" href="vlab_admin.php"><i class="bi bi-hdd-stack me-2"></i>VLAB / Docker</a></li>
    </ul>
  </div>
  <?php endif; ?>
  <?php if ($can_write && !$show_new && !$edit_row): ?>
  <a href="?new=1" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>Nowy kurs</a>
  <?php endif; ?>
</div>

<?= flash_html() ?>

<?php if ($show_new || $edit_row):
  $f = $edit_row ?? ['name'=>'','description'=>'','instructor_id'=>null,'location'=>'','is_active'=>1];
?>
<div class="card border-0 shadow-sm mb-4" style="max-width:580px">
  <div class="card-header fw-semibold"><?= $edit_row ? 'Edytuj: '.h($f['name']) : 'Nowy kurs TI' ?></div>
  <div class="card-body">
    <p class="text-muted small mb-3">
      <i class="bi bi-info-circle me-1"></i>
      Kurs to tylko kontener — nazwa, prowadzący, uczestnicy i ich stawki.
      Lekcje (z konkretnymi datami i godzinami) dodajesz po wejściu w kurs.
      Lekcje mogą się odbywać dowolnie często — raz, dwa razy czy więcej w tygodniu.
    </p>
    <form method="post">
      <input type="hidden" name="_csrf"      value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="course_id"  value="<?= (int)($f['id']??0) ?>">
      <div class="mb-3">
        <label class="form-label fw-semibold">Nazwa kursu <span class="text-danger">*</span></label>
        <input type="text" class="form-control" name="name" value="<?= h($f['name']) ?>" required
               placeholder="np. Obsługa komputera, MS Office, Internet dla seniorów">
      </div>
      <div class="row g-3 mb-3">
        <div class="col-sm-6">
          <label class="form-label fw-semibold">Prowadzący</label>
          <select class="form-select" name="instructor_id">
            <option value="">— brak —</option>
            <?php foreach ($instructors as $u): ?>
            <option value="<?= (int)$u['id'] ?>" <?= (int)($f['instructor_id']??0)===(int)$u['id']?'selected':'' ?>><?= h($u['display_name']??$u['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-sm-6">
          <label class="form-label">Lokalizacja / sala</label>
          <input type="text" class="form-control" name="location" value="<?= h($f['location']) ?>" placeholder="Sala A, piętro 2…">
        </div>
      </div>
      <div class="mb-3">
        <label class="form-label">
          <i class="bi bi-camera-video me-1 text-primary"></i>Stały link do zajęć online (grupa)
        </label>
        <input type="url" class="form-control" name="default_meeting_url"
               value="<?= h($f['default_meeting_url'] ?? '') ?>"
               placeholder="https://… (Teams/Zoom/Meet)">
        <div class="form-text">Wspólny link dla wszystkich lekcji tej grupy. Można nadpisać linkiem konkretnej lekcji.</div>
      </div>
      <div class="row g-3 mb-3">
        <div class="col-sm-6">
          <label class="form-label fw-semibold"><i class="bi bi-cash-coin me-1 text-success"></i>Model rozliczania</label>
          <?php $bm = (int)($f['billing_model'] ?? 2) ?: 2; ?>
          <select class="form-select" name="billing_model" id="bm_select" onchange="bmToggle()">
            <?php foreach ([1,2,3] as $code): ?>
            <option value="<?= $code ?>" <?= $bm===$code?'selected':'' ?>><?= h(k30_ti_billing_model_label($code)) ?> — <?= h(K30_TI_BILLING_MODELS[$code]['desc']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-sm-6" id="bm_amount_wrap" style="<?= $bm===2?'display:none':'' ?>">
          <label class="form-label fw-semibold">Kwota (zł)</label>
          <input type="number" class="form-control" name="billing_amount" step="0.01" min="0" value="<?= h(number_format((float)($f['billing_amount'] ?? 0),2,'.','')) ?>">
          <div class="form-text" id="bm_amount_help">dla modelu miesięcznego/stałego</div>
        </div>
      </div>
      <div class="row g-3 mb-3">
        <div class="col-sm-6">
          <label class="form-label">Nr konta do wpłat <span class="text-muted small">(domyślny)</span></label>
          <input type="text" class="form-control font-monospace" name="pay_account" value="<?= h($f['pay_account'] ?? '') ?>" placeholder="PL00 0000 0000 0000 0000 0000 0000">
        </div>
        <div class="col-sm-6">
          <label class="form-label">Tytuł wpłaty <span class="text-muted small">(domyślny)</span></label>
          <input type="text" class="form-control" name="pay_title" value="<?= h($f['pay_title'] ?? '') ?>" placeholder="np. Opłata za zajęcia TI">
        </div>
        <div class="col-sm-6">
          <label class="form-label">Termin płatności <span class="text-muted small">(dni)</span></label>
          <input type="number" class="form-control" name="pay_due_days" min="0" max="365" value="<?= !empty($f['pay_due_days']) ? (int)$f['pay_due_days'] : '' ?>" placeholder="<?= K30_TI_PAY_DUE_DAYS_DEFAULT ?> (domyślnie)">
          <div class="form-text">Liczba dni od wystawienia rozliczenia. Puste = <?= K30_TI_PAY_DUE_DAYS_DEFAULT ?> dni.</div>
        </div>
        <div class="col-12"><div class="form-text">Używane domyślnie dla kursantów; można nadpisać indywidualnie (kod 9999) przy uczestniku.</div></div>
      </div>
      <div class="mb-3">
        <label class="form-label">Opis</label>
        <textarea class="form-control" name="description" rows="2" placeholder="Czego dotyczą zajęcia…"><?= h($f['description']) ?></textarea>
      </div>
      <div class="form-check form-switch mb-3">
        <input class="form-check-input" type="checkbox" name="is_active" id="c_act" <?= $f['is_active']?'checked':'' ?>>
        <label class="form-check-label" for="c_act">Kurs aktywny</label>
      </div>
      <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary">Zapisz kurs</button>
        <a href="index.php" class="btn btn-outline-secondary">Anuluj</a>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- Lista kursów -->
<?php if (!$courses && !$show_new): ?>
<div class="alert alert-info">
  Brak kursów TI. <a href="?new=1">Utwórz pierwszy kurs</a>.
</div>
<?php else: ?>
<div class="row g-3">
  <?php foreach ($courses as $c): ?>
  <div class="col-sm-6 col-lg-4">
    <div class="card border-0 shadow-sm h-100 <?= $c['is_active']?'':'opacity-60' ?>">
      <div class="card-body">
        <div class="d-flex align-items-start gap-2 mb-2">
          <div class="rounded d-flex align-items-center justify-content-center flex-shrink-0"
               style="width:36px;height:36px;background:#eff6ff;color:#2563eb;font-size:1.1rem">
            <i class="bi bi-pc-display"></i>
          </div>
          <div class="flex-grow-1 min-width-0">
            <div class="fw-bold text-truncate"><?= h($c['name']) ?></div>
            <?php if ($c['instructor_name']): ?>
            <div class="text-muted small"><i class="bi bi-person me-1"></i><?= h($c['instructor_name']) ?></div>
            <?php endif; ?>
            <?php if ($c['location']): ?>
            <div class="text-muted small"><i class="bi bi-geo-alt me-1"></i><?= h($c['location']) ?></div>
            <?php endif; ?>
          </div>
          <?php if (!$c['is_active']): ?>
          <span class="badge bg-secondary" style="font-size:.65rem">Nieaktywny</span>
          <?php endif; ?>
        </div>
        <div class="text-muted small mb-3">
          <i class="bi bi-people me-1"></i><?= (int)$c['enrolled_count'] ?> uczestników
        </div>
        <div class="d-flex gap-2">
          <a href="course.php?id=<?= (int)$c['id'] ?>" class="btn btn-sm btn-primary flex-grow-1">
            <i class="bi bi-arrow-right me-1"></i>Zarządzaj
          </a>
          <?php if ($can_write): ?>
          <a href="?edit=<?= (int)$c['id'] ?>" class="btn btn-sm btn-outline-secondary" title="Edytuj kurs">
            <i class="bi bi-pencil"></i>
          </a>
          <?php endif; ?>
          <?php if ($can_delete): ?>
          <form method="post" class="d-inline" onsubmit="return confirm('Usunąć kurs „<?= h(addslashes($c['name'])) ?>”? Kurs zniknie z listy.')">
            <input type="hidden" name="_csrf"     value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="_op"       value="delete">
            <input type="hidden" name="course_id" value="<?= (int)$c['id'] ?>">
            <button type="submit" class="btn btn-sm btn-outline-danger" title="Usuń kurs">
              <i class="bi bi-trash"></i>
            </button>
          </form>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<script>
function bmToggle() {
  var sel = document.getElementById('bm_select');
  if (!sel) return;
  var v = parseInt(sel.value, 10);
  var wrap = document.getElementById('bm_amount_wrap');
  var help = document.getElementById('bm_amount_help');
  if (wrap) wrap.style.display = (v === 2) ? 'none' : '';      // godzinowy = stawka per uczestnik
  if (help) help.textContent = (v === 1) ? 'stała kwota za miesiąc' : (v === 3 ? 'jednorazowa stała kwota' : '');
}
bmToggle();
</script>

<?php include dirname(dirname(__DIR__)) . '/karty30/includes/footer_k30.php'; ?>
