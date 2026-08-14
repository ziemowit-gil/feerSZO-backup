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
require_once dirname(dirname(__DIR__)) . '/includes/zoom.php';

k30_require_access();
karty30_migrate();

$PAGE_TITLE = 'Zajęcia TI — Dydaktyka 3';
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

// Generowanie linku Zoom dla kursu przez API
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_op'] ?? '') === 'generate_zoom_link') {
    csrf_check();
    if (!$can_write) { http_response_code(403); die('Brak uprawnień.'); }
    $cid = (int)($_POST['course_id'] ?? 0);
    if (!$cid) { flash_set('danger', 'Nie podano kursu.'); header('Location: index.php'); exit; }
    if (!zoom_enabled()) {
        flash_set('danger', 'Zoom nie jest skonfigurowany — przejdź do Nauka online → Zoom.');
        header('Location: index.php?edit=' . $cid); exit;
    }
    $course = db_one("SELECT name, zoom_meeting_id FROM k30_ti_courses WHERE id=?", [$cid]);
    if (!$course) { flash_set('danger', 'Kurs nie istnieje.'); header('Location: index.php'); exit; }
    try {
        $api = new ZoomAPI();
        if (($course['zoom_meeting_id'] ?? '') !== '') {
            $api->delete_meeting((string)$course['zoom_meeting_id']);
        }
        $m = $api->create_meeting((string)$course['name'], 'Zajęcia TI');
        db()->prepare("UPDATE k30_ti_courses SET default_meeting_url=?, zoom_meeting_id=? WHERE id=?")
             ->execute([$m['join_url'], $m['meeting_id'], $cid]);
        flash_set('success', 'Link Zoom wygenerowany i zapisany jako stały link grupy.');
    } catch (\Throwable $e) {
        flash_set('danger', 'Błąd Zoom API: ' . h($e->getMessage()));
    }
    header('Location: index.php?edit=' . $cid); exit;
}

// Globalne stawki potrąceń od wynagrodzenia prowadzących — tylko administrator
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_op'] ?? '') === 'save_payout_settings') {
    csrf_check();
    if (!is_admin()) { http_response_code(403); die('Brak uprawnień.'); }
    foreach (array_keys(K30_TI_PAYOUT_DEFAULTS) as $key) {
        $val = max(0, (float)str_replace(',', '.', (string)($_POST[$key] ?? '0')));
        db()->prepare("INSERT OR REPLACE INTO settings (key_, value) VALUES (?, ?)")
            ->execute([$key, (string)$val]);
    }
    flash_set('success', 'Stawki potrąceń zapisane.');
    header('Location: index.php'); exit;
}

// Ustawienia alertu niskiej frekwencji — tylko administrator
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_op'] ?? '') === 'save_low_att_settings') {
    csrf_check();
    if (!is_admin()) { http_response_code(403); die('Brak uprawnień.'); }
    $pct = (int)($_POST['ti_low_attendance_pct'] ?? 50);
    if ($pct < 1 || $pct > 100) $pct = 50;
    $en  = isset($_POST['ti_low_attendance_enabled']) ? '1' : '0';
    foreach (['ti_low_attendance_pct' => (string)$pct, 'ti_low_attendance_enabled' => $en] as $k => $v) {
        db()->prepare("INSERT OR REPLACE INTO settings (key_, value) VALUES (?, ?)")->execute([$k, $v]);
    }
    flash_set('success', 'Ustawienia alertu frekwencji zapisane.');
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
        'lesson_payout_bb'    => max(0, (float)str_replace(',', '.', (string)($_POST['lesson_payout_bb'] ?? '0'))),
        'is_subgroup'         => isset($_POST['is_subgroup']) ? 1 : 0,
        'is_active'           => isset($_POST['is_active']) ? 1 : 0,
        'track_attendance'    => isset($_POST['track_attendance']) ? 1 : 0,
        'is_online'           => isset($_POST['is_online']) ? 1 : 0,
        'wup_exclude'         => isset($_POST['wup_exclude']) ? 1 : 0,
        'subject_type_id'     => ((int)($_POST['subject_type_id'] ?? 0)) ?: null,
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
        // Generuj kod grupy (3 cyfry + /YY) jeśli nie przekazano
        $data['group_code']  = trim($_POST['group_code'] ?? '') ?: k30_ti_generate_group_code();
        $cid = db_insert('k30_ti_courses', $data);
        flash_set('success','Kurs utworzony.');
        header('Location: course.php?id='.$cid);
    }
    exit;
}

$courses       = k30_ti_courses(false);
$edit_id       = (int)($_GET['edit'] ?? 0);
$edit_row      = $edit_id ? k30_ti_course_get($edit_id) : null;
$show_new      = isset($_GET['new']);
$instructors   = k30_get_consultants();
$subject_types = k30_ti_subject_types(false);

// Odwołania — przegląd dla kadry/administratora (ze wszystkich kursów).
// Prośby kursantów czekające na potwierdzenie + ostatnio odwołane lekcje.
$cancel_pending = []; $cancelled_lessons = [];
if ($can_write) {
    $cancel_pending = db_all(
        "SELECT a.session_id, a.client_id, a.cancel_reason, a.cancelled_by, a.cancelled_by_role,
                cl.name AS client_name, s.lesson_date, s.time_from, c.name AS course_name
         FROM k30_ti_attendance a
         JOIN k30_ti_sessions s ON s.id=a.session_id
         JOIN k30_ti_courses  c ON c.id=s.course_id
         JOIN k30_clients     cl ON cl.id=a.client_id
         WHERE a.cancel_pending=1
         ORDER BY s.lesson_date DESC, c.name COLLATE NOCASE"
    );
    $cancelled_lessons = db_all(
        "SELECT s.id, s.lesson_date, s.time_from, s.cancel_reason, s.cancelled_by, s.cancelled_by_role, s.cancelled_at,
                c.name AS course_name
         FROM k30_ti_sessions s JOIN k30_ti_courses c ON c.id=s.course_id
         WHERE s.status='cancelled'
         ORDER BY COALESCE(s.cancelled_at, s.lesson_date) DESC, s.id DESC
         LIMIT 10"
    );
}

include dirname(dirname(__DIR__)) . '/karty30/includes/header_k30.php';
?>

<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/karty30/index.php">Dydaktyka 3</a></li>
  <li class="breadcrumb-item active">Zajęcia TI</li>
</ol></nav>

<div class="alert alert-info border-info d-flex align-items-start gap-2 mb-3" role="alert">
  <i class="bi bi-camera-video-fill fs-5 text-primary flex-shrink-0 mt-1" aria-hidden="true"></i>
  <div>
    <strong>Od 1 września 2026 zajęcia odbywają się przez Zoom.</strong>
    Każdy kurs powinien mieć stały link grupowy — możesz go wygenerować automatycznie przyciskiem
    <em>„Wygeneruj link Zoom"</em> w edycji kursu (wymaga skonfigurowanej integracji Zoom w
    <a href="online_admin.php" class="alert-link">Nauka online</a>).
  </div>
</div>

<div class="d-flex align-items-center mb-3 gap-2 flex-wrap">
  <h4 class="mb-0 fw-bold"><i class="bi bi-pc-display text-primary me-2"></i>Zajęcia informatyki / TI</h4>

  <!-- Live search -->
  <div class="position-relative ms-auto ti-search-wrap" style="width:260px">
    <input type="search" id="ti-search-q" class="form-control form-control-sm" placeholder="Szukaj w TI…"
           autocomplete="off" aria-label="Szukaj w TI" aria-controls="ti-search-results" aria-expanded="false">
    <ul id="ti-search-results" class="dropdown-menu w-100 p-1" style="display:none;max-height:340px;overflow-y:auto" role="listbox"></ul>
  </div>

  <a href="dydaktyk/index.php" class="btn btn-outline-primary btn-sm" title="Uproszczony panel prowadzącego — Twoje kursy">
    <i class="bi bi-easel2 me-1"></i>Panel dydaktyka
  </a>
  <?php if ($can_write): ?>

  <?php $_unr_cnt = count(k30_ti_unenroll_pending_admin()); ?>

  <!-- ── Komunikacja ── -->
  <div class="dropdown">
    <button class="btn btn-outline-secondary btn-sm dropdown-toggle position-relative" type="button" data-bs-toggle="dropdown" aria-expanded="false">
      <i class="bi bi-chat-dots me-1"></i>Komunikacja
      <?php if ($msg_unread_staff > 0): ?><span class="badge bg-danger ms-1"><?= (int)$msg_unread_staff ?></span><?php endif; ?>
    </button>
    <ul class="dropdown-menu">
      <li><a class="dropdown-item" href="messages.php">
        <i class="bi bi-envelope me-2"></i>Wiadomości z kursantami
        <?php if ($msg_unread_staff > 0): ?><span class="badge bg-danger ms-2"><?= (int)$msg_unread_staff ?></span><?php endif; ?>
      </a></li>
      <li><a class="dropdown-item" href="notices.php"><i class="bi bi-megaphone me-2"></i>Komunikaty placówki</a></li>
      <li><a class="dropdown-item" href="komunikacja.php"><i class="bi bi-send me-2"></i>Wyślij e-mail / SMS</a></li>
    </ul>
  </div>

  <!-- ── Dydaktyka ── -->
  <div class="dropdown">
    <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
      <i class="bi bi-mortarboard me-1"></i>Dydaktyka
    </button>
    <ul class="dropdown-menu">
      <li><a class="dropdown-item" href="materials.php"><i class="bi bi-collection-play me-2"></i>Materiały</a></li>
      <li><a class="dropdown-item" href="homework.php"><i class="bi bi-journal-check me-2"></i>Zadania domowe</a></li>
      <li><a class="dropdown-item" href="grades.php"><i class="bi bi-table me-2"></i>Dziennik ocen</a></li>
      <li><a class="dropdown-item" href="curriculum.php"><i class="bi bi-list-check me-2"></i>Plan nauczania</a></li>
      <li><a class="dropdown-item" href="tests.php"><i class="bi bi-card-checklist me-2"></i>Testy i quizy</a></li>
    </ul>
  </div>

  <!-- ── Finanse ── -->
  <div class="dropdown">
    <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
      <i class="bi bi-cash-coin me-1"></i>Finanse
    </button>
    <ul class="dropdown-menu">
      <li><a class="dropdown-item" href="billing.php"><i class="bi bi-receipt me-2"></i>Rozliczenia kursantów</a></li>
      <li><a class="dropdown-item" href="payouts.php"><i class="bi bi-wallet2 me-2"></i>Wypłaty prowadzących</a></li>
      <li><a class="dropdown-item" href="self_work.php"><i class="bi bi-person-workspace me-2"></i>Praca własna prowadzących</a></li>
      <li><hr class="dropdown-divider"></li>
      <li><a class="dropdown-item" href="zetony.php"><i class="bi bi-coin me-2 text-warning"></i>Żetony SZO</a></li>
    </ul>
  </div>

  <!-- ── Raporty ── -->
  <div class="dropdown">
    <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
      <i class="bi bi-file-earmark-bar-graph me-1"></i>Raporty
    </button>
    <ul class="dropdown-menu">
      <li><a class="dropdown-item" href="raporty.php"><i class="bi bi-file-earmark-bar-graph me-2"></i>Raporty i sprawozdanie WUP</a></li>
      <li><a class="dropdown-item" href="ris.php"><i class="bi bi-card-list me-2"></i>Dane do RIS</a></li>
    </ul>
  </div>

  <!-- ── Kalendarz ── -->
  <div class="dropdown">
    <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
      <i class="bi bi-calendar3 me-1"></i>Kalendarz
    </button>
    <ul class="dropdown-menu">
      <li><a class="dropdown-item" href="periods.php"><i class="bi bi-calendar-range me-2"></i>Okresy nauczania</a></li>
      <li><a class="dropdown-item" href="holidays.php"><i class="bi bi-calendar-x me-2"></i>Dni wolne i przerwy</a></li>
      <li><a class="dropdown-item" href="urlopy.php"><i class="bi bi-airplane me-2"></i>Urlopy prowadzących</a></li>
    </ul>
  </div>

  <!-- ── Platformy ── -->
  <div class="dropdown">
    <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
      <i class="bi bi-hdd-network me-1"></i>Platformy
    </button>
    <ul class="dropdown-menu">
      <li><a class="dropdown-item" href="online_admin.php"><i class="bi bi-camera-video me-2"></i>Nauka online</a></li>
      <li><a class="dropdown-item" href="moodle_admin.php"><i class="bi bi-mortarboard me-2"></i>Moodle</a></li>
      <li><a class="dropdown-item" href="vlab_admin.php"><i class="bi bi-hdd-stack me-2"></i>VLab</a></li>
      <li><a class="dropdown-item" href="licencje_admin.php"><i class="bi bi-key me-2"></i>Licencje</a></li>
    </ul>
  </div>

  <!-- ── Administracja ── -->
  <div class="dropdown">
    <button class="btn btn-outline-secondary btn-sm dropdown-toggle position-relative" type="button" data-bs-toggle="dropdown" aria-expanded="false">
      <i class="bi bi-person-gear me-1"></i>Administracja
      <?php if ($_unr_cnt): ?><span class="badge bg-danger ms-1"><?= (int)$_unr_cnt ?></span><?php endif; ?>
    </button>
    <ul class="dropdown-menu dropdown-menu-end">
      <li><a class="dropdown-item" href="kursant/accounts.php"><i class="bi bi-people me-2"></i>Konta kursantów</a></li>
      <li><a class="dropdown-item" href="unenroll_admin.php"><i class="bi bi-box-arrow-left me-2"></i>Wnioski wypisania
        <?php if ($_unr_cnt): ?><span class="badge bg-danger ms-1"><?= (int)$_unr_cnt ?></span><?php endif; ?>
      </a></li>
      <li><hr class="dropdown-divider"></li>
      <li><a class="dropdown-item" href="subject_types.php"><i class="bi bi-tags me-2"></i>Rodzaje zajęć</a></li>
      <li><a class="dropdown-item" href="terms_admin.php"><i class="bi bi-file-earmark-text me-2"></i>Regulaminy</a></li>
    </ul>
  </div>

  <?php endif; ?>
  <?php if ($can_write && !$show_new && !$edit_row): ?>
  <a href="?new=1" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>Nowy kurs</a>
  <?php endif; ?>
</div>

<?= flash_html() ?>

<?php if ($can_write && ($cancel_pending || $cancelled_lessons)): ?>
<div class="card border-0 shadow-sm mb-4">
  <div class="card-header fw-semibold d-flex align-items-center gap-2 flex-wrap">
    <span><i class="bi bi-x-octagon text-danger me-2" aria-hidden="true"></i>Odwołania</span>
    <?php if ($cancel_pending): ?><span class="badge text-bg-warning"><i class="bi bi-hourglass-split me-1" aria-hidden="true"></i><?= count($cancel_pending) ?> do potwierdzenia</span><?php endif; ?>
  </div>
  <div class="card-body">
    <?php if ($cancel_pending): ?>
    <div class="fw-semibold small text-uppercase text-secondary mb-2">Prośby kursantów o odwołanie udziału — czekają na potwierdzenie</div>
    <div class="table-responsive mb-3">
      <table class="table table-sm align-middle mb-0">
        <thead class="table-light"><tr><th scope="col">Data lekcji</th><th scope="col">Kurs</th><th scope="col">Kursant</th><th scope="col">Powód</th><th scope="col" class="text-end">Akcja</th></tr></thead>
        <tbody>
          <?php foreach ($cancel_pending as $p): ?>
          <tr>
            <td class="text-nowrap small"><?= h(date('d.m.Y', strtotime($p['lesson_date']))) ?><?= $p['time_from'] ? ' '.h(substr($p['time_from'],0,5)) : '' ?></td>
            <td class="small"><?= h($p['course_name']) ?></td>
            <td class="small fw-semibold"><?= h($p['client_name']) ?></td>
            <td class="small"><?= trim((string)$p['cancel_reason'])!=='' ? h($p['cancel_reason']) : '<span class="text-muted">—</span>' ?></td>
            <td class="text-end"><a href="lesson.php?id=<?= (int)$p['session_id'] ?>" class="btn btn-sm btn-outline-primary py-0 px-2">Rozpatrz</a></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>

    <?php if ($cancelled_lessons): ?>
    <div class="fw-semibold small text-uppercase text-secondary mb-2">Ostatnio odwołane lekcje</div>
    <div class="table-responsive">
      <table class="table table-sm align-middle mb-0" style="font-size:.88rem">
        <thead class="table-light"><tr><th scope="col">Data</th><th scope="col">Kurs</th><th scope="col">Powód</th><th scope="col">Odwołał(a)</th><th scope="col"></th></tr></thead>
        <tbody>
          <?php foreach ($cancelled_lessons as $cl):
            $rl = K30_TI_CANCEL_ROLES[$cl['cancelled_by_role'] ?? ''] ?? ($cl['cancelled_by_role'] ?? '');
          ?>
          <tr>
            <td class="text-nowrap small"><?= h(date('d.m.Y', strtotime($cl['lesson_date']))) ?><?= $cl['time_from'] ? ' '.h(substr($cl['time_from'],0,5)) : '' ?></td>
            <td class="small"><?= h($cl['course_name']) ?></td>
            <td class="small"><?= trim((string)$cl['cancel_reason'])!=='' ? h($cl['cancel_reason']) : '<span class="text-muted">—</span>' ?></td>
            <td class="small"><?= h(trim((string)($rl ?: '') . (!empty($cl['cancelled_by']) ? ' — '.$cl['cancelled_by'] : ''))) ?: '<span class="text-muted">—</span>' ?></td>
            <td class="text-end"><a href="lesson.php?id=<?= (int)$cl['id'] ?>" class="btn btn-sm btn-outline-secondary py-0 px-2">Otwórz</a></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<?php if ($show_new || $edit_row):
  $f = $edit_row ?? ['name'=>'','description'=>'','instructor_id'=>null,'location'=>'','is_active'=>1,'subject_type_id'=>null,'group_code'=>''];
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

      <?php /* ── Rodzaj zajęć + kod grupy ── */ ?>
      <div class="row g-3 mb-3">
        <div class="col-sm-6">
          <label class="form-label fw-semibold"><i class="bi bi-tags me-1 text-primary"></i>Rodzaj zajęć</label>
          <select class="form-select" name="subject_type_id" id="st_select">
            <option value="">— nie określono —</option>
            <?php foreach ($subject_types as $st): if (!$st['is_active'] && (int)($f['subject_type_id']??0) !== (int)$st['id']) continue; ?>
            <option value="<?= (int)$st['id'] ?>"
                    data-abbr="<?= h($st['abbreviation']) ?>"
                    data-name="<?= h($st['name']) ?>"
                    <?= (int)($f['subject_type_id']??0)===(int)$st['id']?'selected':'' ?>>
              <?= h($st['abbreviation']) ?> — <?= h($st['name']) ?>
            </option>
            <?php endforeach; ?>
          </select>
          <?php if (is_admin()): ?>
          <div class="form-text"><a href="subject_types.php" target="_blank"><i class="bi bi-gear me-1"></i>Zarządzaj rodzajami</a></div>
          <?php endif; ?>
        </div>
        <div class="col-sm-6">
          <label class="form-label fw-semibold">Kod grupy</label>
          <?php if (!$edit_row): ?>
          <div class="input-group">
            <input type="text" class="form-control font-monospace" name="group_code" id="gc_input"
                   placeholder="np. 742/<?= date('y') ?>" maxlength="6"
                   aria-describedby="gc_help">
            <button type="button" class="btn btn-outline-secondary" onclick="tiGenCode()" title="Wygeneruj losowy kod">
              <i class="bi bi-arrow-clockwise"></i>
            </button>
          </div>
          <div id="gc_help" class="form-text">3 cyfry + /<?= date('y') ?> — auto-generowany przy tworzeniu</div>
          <?php else: ?>
          <input type="text" class="form-control font-monospace bg-light" value="<?= h($f['group_code']) ?>" readonly>
          <div class="form-text">Niezmienny po utworzeniu grupy</div>
          <?php endif; ?>
        </div>
      </div>

      <?php /* ── Nazwa grupy ── */ ?>
      <?php if (!$edit_row): ?>
      <div class="mb-3">
        <label class="form-label fw-semibold">Nazwa grupy <span class="text-danger">*</span></label>
        <div class="input-group mb-1">
          <input type="text" class="form-control" id="helper_fullname"
                 placeholder="Imię i nazwisko kursanta (np. Jan Kowalski)" autocomplete="off">
          <button type="button" class="btn btn-outline-primary" onclick="tiAutoName()" title="Wygeneruj nazwę grupy">
            <i class="bi bi-magic me-1"></i>Generuj
          </button>
        </div>
        <input type="text" class="form-control" name="name" id="name_input" value="<?= h($f['name']) ?>" required
               placeholder="np. Informatyka (INF).JanKowalski.742/<?= date('y') ?>">
        <div class="form-text">Format: <code>Przedmiot (Skrót).ImięNazwisko.kod</code> — wpisz imię i nazwisko, kliknij Generuj lub edytuj ręcznie.</div>
      </div>
      <?php else: ?>
      <div class="mb-3">
        <label class="form-label fw-semibold">Nazwa grupy <span class="text-danger">*</span></label>
        <input type="text" class="form-control" name="name" value="<?= h($f['name']) ?>" required
               placeholder="np. ANG.Jan.K 742/<?= date('y') ?>">
      </div>
      <?php endif; ?>

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
        <div class="input-group">
          <input type="url" class="form-control" name="default_meeting_url"
                 value="<?= h($f['default_meeting_url'] ?? '') ?>"
                 placeholder="https://… (Teams/Zoom/Meet)">
          <?php if (!empty($f['id']) && zoom_enabled()): ?>
          <button type="button" class="btn btn-outline-primary"
                  data-bs-toggle="modal" data-bs-target="#zoomGenModal"
                  title="Wygeneruj stały link przez Zoom API">
            <i class="bi bi-camera-video me-1"></i>Wygeneruj Zoom
          </button>
          <?php endif; ?>
        </div>
        <div class="form-text">
          Wspólny link dla wszystkich lekcji tej grupy. Można nadpisać linkiem konkretnej lekcji.
          <?php if (!empty($f['zoom_meeting_id'])): ?>
          <span class="text-success"><i class="bi bi-check-circle me-1"></i>Powiązane spotkanie Zoom: <code><?= h($f['zoom_meeting_id']) ?></code></span>
          <?php endif; ?>
        </div>
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
      <div class="row g-3 mb-3">
        <div class="col-sm-6">
          <label class="form-label fw-semibold"><i class="bi bi-wallet2 me-1 text-primary"></i>Wynagrodzenie prowadzącego — kwota brutto-brutto za lekcję (zł)</label>
          <input type="number" class="form-control" name="lesson_payout_bb" step="0.01" min="0"
                 value="<?= h(number_format((float)($f['lesson_payout_bb'] ?? 0),2,'.','')) ?>" placeholder="0,00">
          <div class="form-text">Stała należność za przeprowadzenie jednej lekcji. Prowadzący widzi w swoim panelu rozbicie na składki, podatek i kwotę „na rękę" (stawki potrąceń ustawisz niżej).</div>
        </div>
      </div>
      <div class="mb-3">
        <label class="form-label">Opis</label>
        <textarea class="form-control" name="description" rows="2" placeholder="Czego dotyczą zajęcia…"><?= h($f['description']) ?></textarea>
      </div>
      <div class="form-check form-switch mb-2">
        <input class="form-check-input" type="checkbox" name="is_subgroup" id="c_sub" <?= !empty($f['is_subgroup'])?'checked':'' ?>>
        <label class="form-check-label" for="c_sub">
          Podgrupa <span class="text-body-secondary small">(lekcje zawsze jako <em>Zajęcia indywidualne</em>, oznaczenie 1I)</span>
        </label>
      </div>
      <div class="form-check form-switch mb-2">
        <input class="form-check-input" type="checkbox" name="track_attendance" id="c_att" <?= (!isset($f['track_attendance']) || $f['track_attendance']) ? 'checked' : '' ?>>
        <label class="form-check-label" for="c_att">
          Licz frekwencję <span class="text-body-secondary small">(obecność/nieobecność; wyłącz dla kursów bez list obecności)</span>
        </label>
      </div>
      <div class="form-check form-switch mb-2">
        <input class="form-check-input" type="checkbox" name="is_online" id="c_online" <?= !empty($f['is_online'])?'checked':'' ?>>
        <label class="form-check-label" for="c_online">
          Zdalne / online <span class="text-body-secondary small">(zajęcia liczone jako online w sprawozdaniu WUP; inaczej — stacjonarne)</span>
        </label>
      </div>
      <div class="form-check form-switch mb-2">
        <input class="form-check-input" type="checkbox" name="wup_exclude" id="c_wupx" <?= !empty($f['wup_exclude'])?'checked':'' ?>>
        <label class="form-check-label" for="c_wupx">
          Nie uwzględniaj w raporcie WUP <span class="text-body-secondary small">(grupa pomijana w sprawozdaniu do Urzędu Pracy)</span>
        </label>
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
            <?php if (!empty($c['subject_name'])): ?>
            <div class="small mb-1">
              <span class="badge text-bg-primary font-monospace"><?= h($c['subject_abbr']) ?></span>
              <span class="text-muted ms-1"><?= h($c['subject_name']) ?></span>
            </div>
            <?php endif; ?>
            <?php if ($c['instructor_name']): ?>
            <div class="text-muted small"><i class="bi bi-person me-1"></i><?= h($c['instructor_name']) ?></div>
            <?php endif; ?>
            <?php if ($c['location']): ?>
            <div class="text-muted small"><i class="bi bi-geo-alt me-1"></i><?= h($c['location']) ?></div>
            <?php endif; ?>
            <?php if (!empty($c['group_code'])): ?>
            <div class="text-muted small font-monospace"><i class="bi bi-hash me-1"></i><?= h($c['group_code']) ?></div>
            <?php endif; ?>
          </div>
          <?php if (!empty($c['is_subgroup'])): ?>
          <span class="badge" style="font-size:.65rem;background:#f5f3ff;color:#7c3aed;border:1px solid #ddd6fe" title="Podgrupa — lekcje zawsze jako Zajęcia indywidualne">1I</span>
          <?php endif; ?>
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


<?php if (is_admin()):
  $_pd = K30_TI_PAYOUT_DEFAULTS;
  $_pf = [
    'ti_payout_zus_employer_pct' => 'Składki płatnika (% brutto)',
    'ti_payout_zus_employee_pct' => 'Składki społeczne pracownika (% brutto)',
    'ti_payout_health_pct'       => 'Składka zdrowotna (% podstawy)',
    'ti_payout_kup_pct'          => 'Koszty uzyskania — KUP (% podstawy)',
    'ti_payout_pit_pct'          => 'Zaliczka PIT (%)',
  ];
  $_ex = k30_ti_payout_breakdown(100.0);
  $_fmt = fn($x) => number_format((float)$x, 2, ',', ' ');
?>
<div class="card border-0 shadow-sm mt-4">
  <div class="card-header bg-white d-flex align-items-center" role="button" data-bs-toggle="collapse" data-bs-target="#payoutCfg" aria-expanded="false">
    <i class="bi bi-wallet2 me-2 text-primary"></i>
    <span class="fw-semibold">Wynagrodzenia prowadzących — stawki potrąceń</span>
    <i class="bi bi-chevron-down ms-auto"></i>
  </div>
  <div class="collapse" id="payoutCfg">
    <div class="card-body">
      <p class="text-body-secondary small mb-3">
        Kwotę brutto-brutto za lekcję ustawiasz przy każdym kursie. Poniższe stawki służą do rozbicia tej kwoty na składki, podatek i wartość „na rękę" — prowadzący widzi je w swoim panelu. Wartości przybliżone; dostosuj do formy umowy.
      </p>
      <form method="post" class="row g-3">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_op" value="save_payout_settings">
        <?php foreach ($_pf as $key => $label): ?>
        <div class="col-sm-6 col-lg-4">
          <label class="form-label small fw-semibold"><?= h($label) ?></label>
          <div class="input-group">
            <input type="number" class="form-control" name="<?= $key ?>" step="0.01" min="0"
                   value="<?= h(rtrim(rtrim(number_format(k30_ti_payout_rate($key),2,'.',''),'0'),'.')) ?>">
            <span class="input-group-text">%</span>
          </div>
        </div>
        <?php endforeach; ?>
        <div class="col-12">
          <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-save me-1"></i>Zapisz stawki</button>
        </div>
      </form>
      <div class="mt-3 p-3 rounded bg-light small">
        <div class="fw-semibold mb-1">Przykład dla 100,00 zł brutto-brutto (wg powyższych stawek):</div>
        Brutto: <strong><?= $_fmt($_ex['brutto']) ?> zł</strong> ·
        Składki pracownika: <strong><?= $_fmt($_ex['skladki']) ?> zł</strong> ·
        Podatek: <strong><?= $_fmt($_ex['pit']) ?> zł</strong> ·
        Na rękę: <strong class="text-success"><?= $_fmt($_ex['netto']) ?> zł</strong>
      </div>
    </div>
  </div>
</div>

<?php $_la_pct = k30_ti_low_attendance_threshold(); $_la_on = k30_ti_low_attendance_enabled(); ?>
<div class="card border-0 shadow-sm mt-3">
  <div class="card-header bg-white d-flex align-items-center" role="button" data-bs-toggle="collapse" data-bs-target="#lowAttCfg" aria-expanded="false">
    <i class="bi bi-graph-down-arrow me-2 text-primary"></i>
    <span class="fw-semibold">Alert niskiej frekwencji</span>
    <span class="badge <?= $_la_on ? 'bg-success' : 'bg-secondary' ?> ms-2"><?= $_la_on ? 'włączony' : 'wyłączony' ?></span>
    <i class="bi bi-chevron-down ms-auto"></i>
  </div>
  <div class="collapse" id="lowAttCfg">
    <div class="card-body">
      <p class="text-body-secondary small mb-3">
        Gdy frekwencja kursanta w kursie spadnie poniżej progu (po zapisie obecności), system raz wyśle powiadomienie e-mail/SMS do kursanta i opiekuna. Kolejny alert dopiero, gdy frekwencja wróci powyżej progu i znów spadnie. Kursy z wyłączonym liczeniem frekwencji oraz praca własna są pomijane.
      </p>
      <form method="post" class="row g-3 align-items-end">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_op" value="save_low_att_settings">
        <div class="col-auto">
          <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" name="ti_low_attendance_enabled" id="la_on" <?= $_la_on ? 'checked' : '' ?>>
            <label class="form-check-label" for="la_on">Alerty włączone</label>
          </div>
        </div>
        <div class="col-auto">
          <label class="form-label small fw-semibold mb-1">Próg frekwencji</label>
          <div class="input-group" style="width:130px">
            <input type="number" class="form-control" name="ti_low_attendance_pct" min="1" max="100" value="<?= (int)$_la_pct ?>">
            <span class="input-group-text">%</span>
          </div>
        </div>
        <div class="col-auto">
          <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-save me-1"></i>Zapisz</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
// ── Generator kodu grupy ──────────────────────────────────────────────────────
function tiGenCode() {
  var n   = String(Math.floor(Math.random() * 1000)).padStart(3, '0');
  var yr  = '<?= date('y') ?>';
  var val = n + '/' + yr;
  var inp = document.getElementById('gc_input');
  if (inp) { inp.value = val; tiAutoName(); }
}

// ── Auto-generowanie nazwy grupy ─────────────────────────────────────────────
function tiAutoName() {
  var sel  = document.getElementById('st_select');
  var gc   = document.getElementById('gc_input');
  var full = document.getElementById('helper_fullname');
  var out  = document.getElementById('name_input');
  if (!sel || !out) return;

  var opt  = sel.options[sel.selectedIndex];
  var abbr = (opt && opt.dataset.abbr) ? opt.dataset.abbr : '';
  var subj = (opt && opt.dataset.name) ? opt.dataset.name : '';
  if (!abbr) return;

  var words = full ? full.value.trim().split(/\s+/).filter(Boolean) : [];
  var code  = gc ? gc.value.trim() : '';

  // Format: Przedmiot (Skrót).ImięNazwisko.kod  →  Informatyka (INF).JanKowalski.742/26
  var subjPart = subj ? subj + ' (' + abbr + ')' : abbr;
  var namePart = words.join('');
  var parts = [subjPart];
  if (namePart) parts.push(namePart);
  if (code)     parts.push(code);
  out.value = parts.join('.');
}

// Nasłuchuj zmian
(function(){
  var sel  = document.getElementById('st_select');
  var full = document.getElementById('helper_fullname');
  if (sel)  sel.addEventListener('change', tiAutoName);
  if (full) full.addEventListener('input',  tiAutoName);
  var gc = document.getElementById('gc_input');
  if (gc && !gc.value) tiGenCode();
})();

// ── Model rozliczania ─────────────────────────────────────────────────────────
function bmToggle() {
  var sel = document.getElementById('bm_select');
  if (!sel) return;
  var v = parseInt(sel.value, 10);
  var wrap = document.getElementById('bm_amount_wrap');
  var help = document.getElementById('bm_amount_help');
  if (wrap) wrap.style.display = (v === 2) ? 'none' : '';
  if (help) help.textContent = (v === 1) ? 'stała kwota za miesiąc' : (v === 3 ? 'jednorazowa stała kwota' : '');
}
bmToggle();

// Live search TI
(function(){
  var inp = document.getElementById('ti-search-q');
  var box = document.getElementById('ti-search-results');
  if (!inp || !box) return;
  var timer, lastQ = '';
  var typeIcon = {kurs:'pc-display',kursant:'person',lekcja:'calendar-event',zadanie:'journal-check','materiał':'collection-play'};
  var typeCls  = {kurs:'text-bg-primary',kursant:'text-bg-success',lekcja:'text-bg-info',zadanie:'text-bg-warning','materiał':'text-bg-secondary'};

  function esc(s){ var d=document.createElement('div'); d.textContent=s; return d.innerHTML; }

  function show(results) {
    if (!results.length) {
      box.innerHTML = '<li class="dropdown-item text-muted small py-1">Brak wyników.</li>';
    } else {
      box.innerHTML = results.map(function(r){
        var icon = typeIcon[r.type] || 'search';
        var badge = typeCls[r.type] || 'text-bg-secondary';
        return '<li><a class="dropdown-item d-flex align-items-center gap-2 py-1" href="'+esc(r.url)+'">'
          + '<i class="bi bi-'+esc(icon)+' text-primary flex-shrink-0" aria-hidden="true"></i>'
          + '<span class="flex-grow-1 overflow-hidden"><span class="d-block text-truncate fw-semibold">'+esc(r.label)+'</span>'
          + (r.sub ? '<span class="d-block text-truncate small text-muted">'+esc(r.sub)+'</span>' : '')
          + '</span><span class="badge '+esc(badge)+' flex-shrink-0 ms-1">'+esc(r.type)+'</span></a></li>';
      }).join('')
        + '<li><hr class="dropdown-divider my-1"></li>'
        + '<li><a class="dropdown-item small text-muted py-1" href="search.php?q='+encodeURIComponent(inp.value)+'"><i class="bi bi-search me-1"></i>Wszystkie wyniki…</a></li>';
    }
    box.style.display = '';
    inp.setAttribute('aria-expanded', 'true');
  }

  inp.addEventListener('input', function(){
    var q = inp.value.trim();
    clearTimeout(timer);
    if (q.length < 2) { box.style.display = 'none'; inp.setAttribute('aria-expanded','false'); return; }
    if (q === lastQ) return;
    timer = setTimeout(function(){
      lastQ = q;
      fetch('search.php?_ajax=1&q='+encodeURIComponent(q))
        .then(function(r){ return r.json(); })
        .then(function(d){ show(d.results || []); })
        .catch(function(){ box.style.display='none'; });
    }, 220);
  });

  inp.addEventListener('keydown', function(e){
    if (e.key === 'Escape') { box.style.display='none'; inp.setAttribute('aria-expanded','false'); }
    if (e.key === 'Enter' && inp.value.trim().length >= 2) {
      e.preventDefault();
      window.location.href = 'search.php?q='+encodeURIComponent(inp.value.trim());
    }
  });

  document.addEventListener('click', function(e){
    if (!inp.contains(e.target) && !box.contains(e.target)) {
      box.style.display = 'none'; inp.setAttribute('aria-expanded','false');
    }
  });
})();
</script>

<?php if (!empty($edit_row) && zoom_enabled()): ?>
<!-- Modal potwierdzenia wygenerowania linku Zoom -->
<div class="modal fade" id="zoomGenModal" tabindex="-1" aria-labelledby="zoomGenLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="post">
        <input type="hidden" name="_csrf"      value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="_op"        value="generate_zoom_link">
        <input type="hidden" name="course_id"  value="<?= (int)$edit_row['id'] ?>">
        <div class="modal-header">
          <h5 class="modal-title" id="zoomGenLabel">
            <i class="bi bi-camera-video text-primary me-2"></i>Wygeneruj stały link Zoom
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
        </div>
        <div class="modal-body">
          <p>Zostanie utworzone nowe spotkanie Zoom dla kursu <strong><?= h($edit_row['name']) ?></strong> (typ: cykliczne bez stałego terminu — generuje stały link).</p>
          <?php if (!empty($edit_row['zoom_meeting_id'])): ?>
          <div class="alert alert-warning py-2 mb-2">
            <i class="bi bi-exclamation-triangle me-1"></i>
            Kurs ma już powiązane spotkanie Zoom (<code><?= h($edit_row['zoom_meeting_id']) ?></code>). Zostanie ono usunięte i zastąpione nowym.
          </div>
          <?php elseif (!empty($edit_row['default_meeting_url'])): ?>
          <div class="alert alert-warning py-2 mb-2">
            <i class="bi bi-exclamation-triangle me-1"></i>
            Bieżący link (<code><?= h(mb_substr($edit_row['default_meeting_url'], 0, 60)) ?>…</code>) zostanie zastąpiony nowym linkiem Zoom.
          </div>
          <?php endif; ?>
          <p class="text-muted small mb-0">Link zostanie zapisany jako stały link grupy i wyświetlony kursantom przy każdej lekcji.</p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="btn btn-primary">
            <i class="bi bi-camera-video me-1"></i>Generuj i zapisz
          </button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php include dirname(dirname(__DIR__)) . '/karty30/includes/footer_k30.php'; ?>
