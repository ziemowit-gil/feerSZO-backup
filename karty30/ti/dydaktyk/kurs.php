<?php
/**
 * karty30/ti/dydaktyk/kurs.php — Podgląd grupy (kierownik, nowe UI).
 *
 * Odpowiednik karty30/ti/course.php w panelu dydaktyka: uczestnicy (zapis/
 * wypis, indywidualne rozliczanie 9999, stały Zoom per kursant), stały link
 * Zoom kursu, coProwadzący. Lekcjami zarządza zakładka Zajęcia w panelu
 * (index.php?course=N&tab=lekcje); pełna edycja metadanych kursu i operacje
 * administracyjne zostają w module admina (course.php / index.php?edit=).
 */
require_once __DIR__ . '/auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/zoom.php';

$me = dyd_require();
if (!dyd_is_staff()) { header('Location: index.php'); exit; }   // ekran kierownika
$uid      = (int)$me['user_id'];
$dyd_name = (string)($me['name'] ?? '');
karty30_migrate();

/** Synchronizuje alternative_hosts Zoom dla kursu i wszystkich zapisów. */
function _dyd_zoom_sync_alt_hosts(int $course_id, array $course): void {
    if (!zoom_enabled()) return;
    try {
        $api    = new ZoomAPI();
        $emails = k30_ti_course_zoom_alt_hosts($course_id);
        if (!empty($course['zoom_meeting_id'])) $api->update_alternative_hosts((string)$course['zoom_meeting_id'], $emails);
        foreach (db_all("SELECT zoom_meeting_id FROM k30_ti_enrollments WHERE course_id=? AND zoom_meeting_id!=''", [$course_id]) as $e) {
            $api->update_alternative_hosts((string)$e['zoom_meeting_id'], $emails);
        }
    } catch (\Throwable $e) {}
}

$id     = (int)($_GET['id'] ?? 0);
$course = $id ? k30_ti_course_get($id) : null;
if (!$course) { flash_set('danger', 'Kurs nie istnieje.'); header('Location: index.php?tab=kursy'); exit; }

/* ── POST ──────────────────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = $_POST['_op'] ?? '';

    if ($op === 'enroll') {
        $cid  = (int)($_POST['client_id'] ?? 0);
        $rate = max(0, (float)str_replace(',', '.', $_POST['hourly_rate'] ?? '0'));
        if ($cid) {
            try {
                db()->prepare(
                    "INSERT INTO k30_ti_enrollments (course_id,client_id,hourly_rate,start_date,status)
                     VALUES (?,?,?,?,?)
                     ON CONFLICT(course_id,client_id) DO UPDATE SET hourly_rate=excluded.hourly_rate, status='active', start_date=excluded.start_date"
                )->execute([$id, $cid, $rate, date('Y-m-d'), 'active']);
            } catch (\Throwable $e) {}
            flash_set('success', 'Uczestnik zapisany.');
        }
        header('Location: kurs.php?id=' . $id . '#uczestnicy'); exit;
    }

    if ($op === 'unenroll') {
        $cid = (int)($_POST['client_id'] ?? 0);
        db()->prepare("UPDATE k30_ti_enrollments SET status='inactive' WHERE course_id=? AND client_id=?")->execute([$id, $cid]);
        flash_set('success', 'Uczestnik wypisany.');
        header('Location: kurs.php?id=' . $id . '#uczestnicy'); exit;
    }

    // ── Zoom: link na poziomie kursu ─────────────────────────────────────────
    if ($op === 'gen_course_zoom' || $op === 'clear_course_zoom') {
        if (!zoom_enabled()) { flash_set('warning', 'Integracja Zoom nie jest skonfigurowana.'); header('Location: kurs.php?id=' . $id . '#zoom'); exit; }
        $api = new ZoomAPI();
        if (!empty($course['zoom_meeting_id'])) {
            $api->delete_meeting($course['zoom_meeting_id']);
            $api->log('delete', $course['zoom_meeting_id'], 'ok', 'clear before regenerate', $id, $uid);
        }
        if ($op === 'clear_course_zoom') {
            db()->prepare("UPDATE k30_ti_courses SET zoom_meeting_id='', default_meeting_url='', zoom_host_email='' WHERE id=?")->execute([$id]);
            $api->log('delete', $course['zoom_meeting_id'] ?? '', 'ok', 'cleared by kierownik', $id, $uid);
            flash_set('success', 'Link Zoom kursu usunięty.');
        } else {
            $altEmails = k30_ti_course_zoom_alt_hosts($id);
            $hostEmail = db_one("SELECT u.email FROM k30_ti_courses c JOIN users u ON u.id=c.instructor_id WHERE c.id=?", [$id])['email'] ?? '';
            if ($hostEmail !== '') {
                try { $api->validate_user_email($hostEmail); }
                catch (\RuntimeException $ex) {
                    flash_set('warning', $ex->getMessage() . ' Link Zoom zostanie utworzony bez alternative_hosts.');
                    $altEmails = ''; $hostEmail = '';
                }
            }
            try {
                $m = $api->create_meeting($course['name'], 'Kurs TI — stały link grupy', $altEmails);
                db()->prepare("UPDATE k30_ti_courses SET zoom_meeting_id=?, default_meeting_url=?, zoom_host_email=? WHERE id=?")
                   ->execute([$m['meeting_id'], $m['join_url'], $hostEmail, $id]);
                $api->log('create', $m['meeting_id'], 'ok', 'alt_hosts=' . $altEmails, $id, $uid);
                flash_set('success', 'Stały link Zoom kursu wygenerowany.');
            } catch (\RuntimeException $ex) {
                $api->log('create', '', 'error', $ex->getMessage(), $id, $uid);
                flash_set('danger', 'Błąd Zoom: ' . $ex->getMessage());
            }
        }
        header('Location: kurs.php?id=' . $id . '#zoom'); exit;
    }

    // ── Zoom: link per kursant ───────────────────────────────────────────────
    if ($op === 'gen_student_zoom' || $op === 'clear_student_zoom') {
        $cid = (int)($_POST['client_id'] ?? 0);
        $en  = $cid ? db_one("SELECT * FROM k30_ti_enrollments WHERE course_id=? AND client_id=?", [$id, $cid]) : null;
        if (!$en) { flash_set('danger', 'Uczestnik nie znaleziony.'); header('Location: kurs.php?id=' . $id . '#uczestnicy'); exit; }
        if (!zoom_enabled()) { flash_set('warning', 'Integracja Zoom nie jest skonfigurowana.'); header('Location: kurs.php?id=' . $id . '#uczestnicy'); exit; }
        $api = new ZoomAPI();
        if (!empty($en['zoom_meeting_id'])) $api->delete_meeting($en['zoom_meeting_id']);
        if ($op === 'clear_student_zoom') {
            db()->prepare("UPDATE k30_ti_enrollments SET zoom_meeting_id='', zoom_meeting_url='' WHERE course_id=? AND client_id=?")->execute([$id, $cid]);
            $api->log('delete', $en['zoom_meeting_id'] ?? '', 'ok', 'student clear client_id=' . $cid, $id, $uid);
            flash_set('success', 'Stały link Zoom uczestnika usunięty.');
        } else {
            $cname     = db_one("SELECT name FROM k30_clients WHERE id=?", [$cid])['name'] ?? (string)$cid;
            $altEmails = k30_ti_course_zoom_alt_hosts($id);
            $hostEmail = db_one("SELECT u.email FROM k30_ti_courses c JOIN users u ON u.id=c.instructor_id WHERE c.id=?", [$id])['email'] ?? '';
            if ($hostEmail !== '') {
                try { $api->validate_user_email($hostEmail); }
                catch (\RuntimeException $ex) { flash_set('warning', $ex->getMessage() . ' Link bez alternative_hosts.'); $altEmails = ''; }
            }
            try {
                $m = $api->create_meeting($course['name'] . ' — ' . $cname, 'Zajęcia TI', $altEmails);
                db()->prepare("UPDATE k30_ti_enrollments SET zoom_meeting_id=?, zoom_meeting_url=? WHERE course_id=? AND client_id=?")
                   ->execute([$m['meeting_id'], $m['join_url'], $id, $cid]);
                $api->log('create', $m['meeting_id'], 'ok', 'student client_id=' . $cid, $id, $uid);
                flash_set('success', 'Stały link Zoom wygenerowany dla uczestnika ' . $cname . '.');
            } catch (\RuntimeException $ex) {
                $api->log('create', '', 'error', $ex->getMessage(), $id, $uid);
                flash_set('danger', 'Błąd Zoom: ' . $ex->getMessage());
            }
        }
        header('Location: kurs.php?id=' . $id . '#uczestnicy'); exit;
    }

    // Indywidualny model rozliczania kursanta (override). model=0 → dziedziczy z kursu.
    if ($op === 'set_billing') {
        $cid   = (int)($_POST['client_id'] ?? 0);
        $model = (int)($_POST['billing_model'] ?? 0);
        if (!in_array($model, [0, 1, 2, 3], true)) $model = 0;
        $amount = max(0, (float)str_replace(',', '.', (string)($_POST['billing_amount'] ?? '0')));
        $rate   = max(0, (float)str_replace(',', '.', (string)($_POST['hourly_rate'] ?? '0')));
        if ($cid) {
            db()->prepare("UPDATE k30_ti_enrollments SET billing_model=?, billing_amount=?, hourly_rate=?, pay_account=?, pay_title=?, pay_due_days=? WHERE course_id=? AND client_id=?")
               ->execute([$model, $amount, $rate, trim($_POST['pay_account'] ?? ''), trim($_POST['pay_title'] ?? ''),
                          ((int)($_POST['pay_due_days'] ?? 0)) ?: null, $id, $cid]);
            flash_set('success', $model > 0 ? 'Ustawiono indywidualny model rozliczania (kod 9999).' : 'Przywrócono model rozliczania kursu.');
        }
        header('Location: kurs.php?id=' . $id . '#uczestnicy'); exit;
    }

    // ── CoProwadzący ─────────────────────────────────────────────────────────
    if ($op === 'coinstr_add') {
        $cu = (int)($_POST['coinstr_user_id'] ?? 0);
        if ($cu && $cu !== (int)$course['instructor_id']) {
            k30_ti_coinstruct_add($id, $cu, $uid);
            _dyd_zoom_sync_alt_hosts($id, $course);
            flash_set('success', 'CoProwadzący dodany.');
        }
        header('Location: kurs.php?id=' . $id . '#coinstructors'); exit;
    }
    if ($op === 'coinstr_remove') {
        $cu = (int)($_POST['coinstr_user_id'] ?? 0);
        if ($cu) {
            k30_ti_coinstruct_remove($id, $cu);
            _dyd_zoom_sync_alt_hosts($id, $course);
            flash_set('success', 'CoProwadzący usunięty.');
        }
        header('Location: kurs.php?id=' . $id . '#coinstructors'); exit;
    }
}

/* ── DANE ──────────────────────────────────────────────────────────────────── */
$enrollments   = k30_ti_enrollments($id);
$all_clients   = db_all("SELECT id, name FROM k30_clients ORDER BY name");
$not_enrolled  = array_filter($all_clients, fn($c) => !in_array((int)$c['id'], array_column($enrollments, 'client_id')));
$coinstructors = k30_ti_course_coinstructors($id);
$coinstr_ids   = array_column($coinstructors, 'user_id');
$consultants   = k30_get_consultants();
$coinstr_available = array_filter($consultants, fn($u) =>
    (int)$u['id'] !== (int)$course['instructor_id'] && !in_array((int)$u['id'], $coinstr_ids));
$cbm = (int)($course['billing_model'] ?? 2) ?: 2;

/* ── HTML ──────────────────────────────────────────────────────────────────── */
$KP_TITLE  = 'Grupa: ' . $course['name'] . ' — Panel dydaktyka';
$KP_TOPBAR = ['brand' => 'Panel dydaktyka', 'icon' => 'easel2', 'user' => $dyd_name, 'logout' => 'logout.php'];
$KP_BODY_CLASS = 'dyd-usos ti-skin';
include dirname(__DIR__) . '/kursant/_layout_head.php';
$_skin_css = __DIR__ . '/../assets/ti_skin.css';
?>
<link rel="stylesheet" href="../assets/ti_skin.css?v=<?= is_file($_skin_css) ? (int)filemtime($_skin_css) : 1 ?>">

<?php $KIER_CUR = ''; $KIER_LABEL = $course['name'];
   include __DIR__ . '/_kierownik_bar.php'; ?>

<main id="main" class="dyd-wrap">

<div class="d-flex align-items-center gap-3 mb-3 flex-wrap">
  <div>
    <h1 class="h4 fw-bold mb-0">
      <i class="bi bi-pc-display me-2 text-primary" aria-hidden="true"></i><?= h($course['name']) ?>
      <?php if (!empty($course['subject_abbr'])): ?>
      <span class="badge text-bg-primary font-monospace ms-1 align-middle" style="font-size:.7rem"><?= h($course['subject_abbr']) ?></span>
      <?php endif; ?>
      <?php if (!empty($course['group_code'])): ?>
      <span class="badge bg-light text-secondary border font-monospace ms-1 align-middle" style="font-size:.7rem"><?= h($course['group_code']) ?></span>
      <?php endif; ?>
      <?php if (!empty($course['is_oneoff'])): ?>
      <span class="badge text-bg-info ms-1 align-middle" style="font-size:.7rem">jednorazowy<?=
        !empty($course['oneoff_date']) ? ' · ' . h(date('d.m.Y', strtotime((string)$course['oneoff_date']))) : '' ?></span>
      <?php endif; ?>
    </h1>
    <p class="text-body-secondary small mb-0">
      <?php if ($course['instructor_name']): ?><i class="bi bi-person me-1" aria-hidden="true"></i><?= h($course['instructor_name']) ?><?php endif; ?>
      <?php if ($course['location']): ?> · <i class="bi bi-geo-alt me-1" aria-hidden="true"></i><?= h($course['location']) ?><?php endif; ?>
      · <i class="bi bi-cash-coin me-1" aria-hidden="true"></i>Rozliczanie: <?= h(k30_ti_billing_model_label($cbm)) ?> (kod <?= $cbm ?>)<?php
        if ($cbm !== 2 && (float)($course['billing_amount'] ?? 0) > 0): ?> · <?= number_format((float)$course['billing_amount'], 2, ',', '') ?> zł<?php endif; ?>
    </p>
  </div>
  <div class="ms-auto d-flex gap-2 flex-wrap">
    <a href="index.php?course=<?= $id ?>&tab=lekcje" class="btn btn-sm btn-outline-primary">
      <i class="bi bi-calendar-week me-1" aria-hidden="true"></i>Zajęcia</a>
    <a href="index.php?course=<?= $id ?>&tab=uczestnicy" class="btn btn-sm btn-outline-primary">
      <i class="bi bi-people me-1" aria-hidden="true"></i>Kartoteka grupy</a>
    <a href="index.php?course=<?= $id ?>&tab=rozliczenia" class="btn btn-sm btn-outline-primary">
      <i class="bi bi-receipt me-1" aria-hidden="true"></i>Rozliczenia grupy</a>
    <a href="../index.php?edit=<?= $id ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary"
       title="Pełna edycja metadanych kursu (moduł admina — wymaga logowania do SZO)">
      <i class="bi bi-pencil me-1" aria-hidden="true"></i>Edytuj kurs <i class="bi bi-box-arrow-up-right" style="font-size:.62rem" aria-hidden="true"></i></a>
  </div>
</div>

<?= flash_html() ?>

<?php if (zoom_enabled()): ?>
<div class="card mb-3" id="zoom">
  <div class="card-body py-2 px-3 d-flex align-items-center gap-3 flex-wrap">
    <span class="fw-semibold text-nowrap"><i class="bi bi-camera-video-fill text-primary me-1" aria-hidden="true"></i>Zoom — link kursu</span>
    <?php if (!empty($course['default_meeting_url'])): ?>
      <a href="<?= h($course['default_meeting_url']) ?>" target="_blank" rel="noopener"
         class="text-break small font-monospace"><?= h($course['default_meeting_url']) ?></a>
      <span class="text-body-secondary" style="font-size:.72rem">ID: <?= h($course['zoom_meeting_id']) ?></span>
      <?php if (!empty($course['zoom_host_email'])): ?>
      <span class="badge bg-light text-secondary border" style="font-size:.7rem"><i class="bi bi-person me-1" aria-hidden="true"></i><?= h($course['zoom_host_email']) ?></span>
      <?php endif; ?>
    <?php else: ?>
      <span class="text-body-secondary small">Brak stałego linku — kursanci i prowadzący dołączają przez indywidualne linki lub wpisują URL ręcznie.</span>
    <?php endif; ?>
    <div class="ms-auto d-flex gap-2 flex-shrink-0">
      <form method="post" class="d-inline">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="_op"   value="gen_course_zoom">
        <button type="submit" class="btn btn-sm btn-outline-primary"
                onclick="return confirm('<?= empty($course['default_meeting_url']) ? 'Wygenerować stały link Zoom dla kursu?' : 'Zregenerować stały link Zoom? Stary link przestanie działać.' ?>')">
          <i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i><?= empty($course['default_meeting_url']) ? 'Wygeneruj link Zoom' : 'Regeneruj link' ?>
        </button>
      </form>
      <?php if (!empty($course['default_meeting_url'])): ?>
      <form method="post" class="d-inline">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="_op"   value="clear_course_zoom">
        <button type="submit" class="btn btn-sm btn-outline-danger"
                onclick="return confirm('Usunąć stały link Zoom kursu? Operacja jest nieodwracalna.')">
          <i class="bi bi-trash me-1" aria-hidden="true"></i>Usuń link
        </button>
      </form>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="card mb-4" id="coinstructors">
  <div class="card-header fw-semibold bg-white d-flex align-items-center py-2">
    <i class="bi bi-people-fill me-2 text-secondary" aria-hidden="true"></i>CoProwadzący
    <span class="badge bg-secondary ms-2"><?= count($coinstructors) ?></span>
    <span class="ms-2 text-body-secondary fw-normal" style="font-size:.8rem">Dostęp do kursu w panelu dydaktyka — bez rozliczenia</span>
  </div>
  <div class="card-body py-2 px-3">
    <?php if ($coinstructors): ?>
    <div class="d-flex flex-wrap gap-2 align-items-center">
      <?php foreach ($coinstructors as $ci): ?>
      <div class="d-flex align-items-center gap-1 border rounded px-2 py-1" style="font-size:.85rem">
        <i class="bi bi-person-badge text-secondary me-1" aria-hidden="true"></i>
        <span><?= h($ci['user_name']) ?></span>
        <span class="text-body-secondary" style="font-size:.75rem"><?= h($ci['user_email']) ?></span>
        <form method="post" class="d-inline ms-1">
          <input type="hidden" name="_csrf"           value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="_op"             value="coinstr_remove">
          <input type="hidden" name="coinstr_user_id" value="<?= (int)$ci['user_id'] ?>">
          <button type="submit" class="btn btn-sm btn-outline-danger border-0 p-0 px-1" title="Usuń coProwadzącego"
                  onclick="return confirm('Usunąć <?= h(addslashes($ci['user_name'])) ?> z listy coProwadzących?')">
            <i class="bi bi-x-lg" style="font-size:.7rem" aria-hidden="true"></i><span class="visually-hidden">Usuń</span>
          </button>
        </form>
      </div>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <span class="text-body-secondary" style="font-size:.85rem">Brak coProwadzących — kurs dostępny wyłącznie dla głównego prowadzącego.</span>
    <?php endif; ?>

    <?php if ($coinstr_available): ?>
    <form method="post" class="d-flex gap-2 align-items-end mt-2 flex-wrap">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="_op"   value="coinstr_add">
      <div>
        <label class="form-label small fw-semibold mb-1" for="coinstr-sel">Dodaj coProwadzącego</label>
        <select id="coinstr-sel" name="coinstr_user_id" class="form-select form-select-sm" required style="min-width:220px">
          <option value="">— wybierz prowadzącego —</option>
          <?php foreach ($coinstr_available as $u): ?>
          <option value="<?= (int)$u['id'] ?>"><?= h($u['display_name'] ?? $u['name']) ?> (<?= h($u['email']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="bi bi-person-plus me-1" aria-hidden="true"></i>Dodaj</button>
    </form>
    <?php endif; ?>
  </div>
</div>

<div class="card" id="uczestnicy">
  <div class="card-header fw-semibold bg-white d-flex align-items-center">
    <i class="bi bi-people me-2 text-primary" aria-hidden="true"></i>Uczestnicy i rozliczanie
    <span class="badge bg-secondary ms-2"><?= count(array_filter($enrollments, fn($e) => $e['status'] === 'active')) ?></span>
  </div>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <caption class="visually-hidden">Uczestnicy kursu z modelem rozliczania i akcjami</caption>
      <thead class="table-light">
        <tr><th scope="col">Kursant</th><th scope="col">Rozliczanie</th><th scope="col">Status</th><th scope="col" class="text-end">Akcje</th></tr>
      </thead>
      <tbody>
        <?php foreach ($enrollments as $e):
          $eff = k30_ti_effective_billing($e, $course); ?>
        <tr class="<?= $e['status'] !== 'active' ? 'text-muted opacity-75' : '' ?>">
          <th scope="row" class="fw-semibold"><?= h($e['client_name']) ?></th>
          <td>
            <?php if ($eff['individual']): ?>
            <span class="badge bg-warning text-dark" title="Indywidualne ustalenia">9999 · indyw.</span>
            <?php else: ?>
            <span class="badge bg-light text-secondary border" title="Kod modelu">kod <?= (int)$eff['code'] ?></span>
            <?php endif; ?>
            <span class="small"><?= h($eff['label']) ?>:</span>
            <span class="fw-semibold small">
              <?php if ($eff['model'] === 2): ?><?= number_format($eff['hourly_rate'], 2, ',', '') ?> zł/h
              <?php else: ?><?= number_format($eff['amount'], 2, ',', '') ?> zł<?php endif; ?>
            </span>
            <button type="button" class="btn btn-sm btn-link p-0 ms-1 align-baseline" data-bs-toggle="modal"
                    data-bs-target="#bill<?= (int)$e['client_id'] ?>" title="Zmień rozliczanie">
              <i class="bi bi-pencil" aria-hidden="true"></i><span class="visually-hidden">Zmień rozliczanie</span></button>
          </td>
          <td><span class="badge <?= $e['status'] === 'active' ? 'text-bg-success' : 'bg-secondary' ?>"><?= $e['status'] === 'active' ? 'Aktywny' : 'Nieaktywny' ?></span></td>
          <td class="text-end text-nowrap">
            <?php if ($e['status'] === 'active' && zoom_enabled()): ?>
            <button type="button" class="btn btn-sm btn-outline-primary py-0 px-2 me-1"
                    data-bs-toggle="modal" data-bs-target="#zoomStu<?= (int)$e['client_id'] ?>"
                    title="<?= !empty($e['zoom_meeting_url']) ? 'Regeneruj / usuń stały link Zoom' : 'Wygeneruj stały link Zoom' ?>">
              <i class="bi bi-camera-video" aria-hidden="true"></i>
              <?php if (!empty($e['zoom_meeting_url'])): ?><span class="badge text-bg-success ms-1" style="font-size:.6rem">Zoom</span><?php endif; ?>
            </button>
            <?php endif; ?>
            <?php if ($e['status'] === 'active'): ?>
            <form method="post" class="d-inline" onsubmit="return confirm('Wypisać uczestnika?')">
              <input type="hidden" name="_csrf"     value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="_op"       value="unenroll">
              <input type="hidden" name="client_id" value="<?= (int)$e['client_id'] ?>">
              <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-2" title="Wypisz">
                <i class="bi bi-x-lg" aria-hidden="true"></i><span class="visually-hidden">Wypisz</span>
              </button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$enrollments): ?>
        <tr><td colspan="4" class="text-body-secondary text-center py-3">Brak uczestników.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($not_enrolled): ?>
  <div class="card-footer bg-white">
    <form method="post" class="d-flex gap-2 align-items-end flex-wrap">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="_op"   value="enroll">
      <div>
        <label class="form-label small fw-semibold mb-1" for="enroll-sel">Dodaj uczestnika</label>
        <select id="enroll-sel" name="client_id" class="form-select form-select-sm" required style="min-width:220px">
          <option value="">— wybierz —</option>
          <?php foreach ($not_enrolled as $c): ?>
          <option value="<?= (int)$c['id'] ?>"><?= h($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="form-label small fw-semibold mb-1" for="enroll-rate">Stawka (zł/h)</label>
        <input type="number" class="form-control form-control-sm" id="enroll-rate" name="hourly_rate" step="0.01" min="0" value="0" style="width:90px">
      </div>
      <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-person-plus me-1" aria-hidden="true"></i>Zapisz</button>
      <span class="text-body-secondary small align-self-center">Przenosiny między grupami: zakładka
        <a href="index.php?course=<?= $id ?>&tab=uczestnicy">Uczestnicy</a>.</span>
    </form>
  </div>
  <?php endif; ?>
</div>

<!-- Modale: indywidualne rozliczanie kursanta -->
<?php $course_due = (int)($course['pay_due_days'] ?? 0) ?: K30_TI_PAY_DUE_DAYS_DEFAULT;
foreach ($enrollments as $e): ?>
<div class="modal fade" id="bill<?= (int)$e['client_id'] ?>" tabindex="-1" aria-labelledby="billLbl<?= (int)$e['client_id'] ?>" aria-hidden="true">
  <div class="modal-dialog modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h2 class="modal-title h6 mb-0" id="billLbl<?= (int)$e['client_id'] ?>">
          <i class="bi bi-cash-coin text-success me-2" aria-hidden="true"></i>Rozliczanie — <?= h($e['client_name']) ?>
        </h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
      </div>
      <form method="post">
        <div class="modal-body">
          <input type="hidden" name="_csrf"     value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="_op"       value="set_billing">
          <input type="hidden" name="client_id" value="<?= (int)$e['client_id'] ?>">
          <div class="mb-3">
            <label class="form-label small fw-semibold mb-1" for="bm<?= (int)$e['client_id'] ?>">Model (override)</label>
            <select name="billing_model" id="bm<?= (int)$e['client_id'] ?>" class="form-select form-select-sm" onchange="billToggle(this)">
              <option value="0" <?= (int)$e['billing_model'] === 0 ? 'selected' : '' ?>>— jak kurs (<?= h(k30_ti_billing_model_label((int)($course['billing_model'] ?: 2))) ?>) —</option>
              <?php foreach ([1, 2, 3] as $code): ?>
              <option value="<?= $code ?>" <?= (int)$e['billing_model'] === $code ? 'selected' : '' ?>>Indywidualny: <?= h(k30_ti_billing_model_label($code)) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="row g-2 mb-3">
            <div class="col-6 bill-rate" style="<?= in_array((int)$e['billing_model'], [1, 3], true) ? 'display:none' : '' ?>">
              <label class="form-label small mb-0">Stawka (zł/h)</label>
              <input type="number" name="hourly_rate" class="form-control form-control-sm" step="0.01" min="0" value="<?= h(number_format((float)$e['hourly_rate'], 2, '.', '')) ?>">
            </div>
            <div class="col-6 bill-amount" style="<?= in_array((int)$e['billing_model'], [1, 3], true) ? '' : 'display:none' ?>">
              <label class="form-label small mb-0">Kwota (zł)</label>
              <input type="number" name="billing_amount" class="form-control form-control-sm" step="0.01" min="0" value="<?= h(number_format((float)$e['billing_amount'], 2, '.', '')) ?>">
            </div>
          </div>
          <div class="row g-2">
            <div class="col-12">
              <label class="form-label small mb-0">Nr konta (indyw., gdy kod 9999)</label>
              <input type="text" name="pay_account" class="form-control form-control-sm font-monospace" value="<?= h($e['pay_account'] ?? '') ?>" placeholder="puste = domyślny kursu / organizacji">
            </div>
            <div class="col-sm-8">
              <label class="form-label small mb-0">Tytuł wpłaty (indyw.)</label>
              <input type="text" name="pay_title" class="form-control form-control-sm" value="<?= h($e['pay_title'] ?? '') ?>" placeholder="puste = automatyczny">
            </div>
            <div class="col-sm-4">
              <label class="form-label small mb-0">Termin płatn. (dni)</label>
              <input type="number" name="pay_due_days" class="form-control form-control-sm" min="0" max="365" value="<?= !empty($e['pay_due_days']) ? (int)$e['pay_due_days'] : '' ?>" placeholder="<?= $course_due ?>" title="puste = jak kurs (<?= $course_due ?> dni)">
            </div>
          </div>
          <p class="form-text mt-2 mb-0">Wybór indywidualnego modelu nadaje kursantowi kod 9999. Dane do wpłat działają tylko przy kodzie 9999 (inaczej obowiązują domyślne kursu, a przy pustych — konto organizacji „dla TI" i tytuł automatyczny).</p>
        </div>
        <div class="modal-footer py-2">
          <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-save me-1" aria-hidden="true"></i>Zapisz</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endforeach; ?>

<!-- Modale: stały link Zoom per kursant -->
<?php if (zoom_enabled()): foreach ($enrollments as $e): if ($e['status'] !== 'active') continue; ?>
<div class="modal fade" id="zoomStu<?= (int)$e['client_id'] ?>" tabindex="-1" aria-labelledby="zoomStuLbl<?= (int)$e['client_id'] ?>" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h2 class="modal-title h6 mb-0" id="zoomStuLbl<?= (int)$e['client_id'] ?>">
          <i class="bi bi-camera-video text-primary me-2" aria-hidden="true"></i>Stały link Zoom — <?= h($e['client_name']) ?>
        </h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
      </div>
      <div class="modal-body">
        <?php if (!empty($e['zoom_meeting_url'])): ?>
        <div class="alert alert-success py-2 mb-3">
          <i class="bi bi-check-circle me-1" aria-hidden="true"></i>Aktywny link:<br>
          <a href="<?= h($e['zoom_meeting_url']) ?>" target="_blank" rel="noopener" class="small"><?= h($e['zoom_meeting_url']) ?></a>
          <div class="text-body-secondary" style="font-size:.72rem">ID spotkania: <?= h($e['zoom_meeting_id']) ?></div>
        </div>
        <p class="mb-0">Generowanie nowego linku <strong>usunie</strong> bieżące spotkanie Zoom i stworzy nowe (stały URL się zmieni).</p>
        <?php else: ?>
        <p>Zostanie utworzone nowe spotkanie Zoom (cykliczne bez stałego terminu) dla kursanta <strong><?= h($e['client_name']) ?></strong>.</p>
        <p class="text-body-secondary small mb-0">Link będzie widoczny kursantowi przy każdej zaplanowanej lekcji oraz w zakładce Szkolenia online.</p>
        <?php endif; ?>
      </div>
      <div class="modal-footer py-2">
        <?php if (!empty($e['zoom_meeting_url'])): ?>
        <form method="post" class="me-auto">
          <input type="hidden" name="_csrf"     value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="_op"       value="clear_student_zoom">
          <input type="hidden" name="client_id" value="<?= (int)$e['client_id'] ?>">
          <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash me-1" aria-hidden="true"></i>Usuń link</button>
        </form>
        <?php endif; ?>
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button>
        <form method="post" class="d-inline">
          <input type="hidden" name="_csrf"     value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="_op"       value="gen_student_zoom">
          <input type="hidden" name="client_id" value="<?= (int)$e['client_id'] ?>">
          <button type="submit" class="btn btn-primary btn-sm">
            <i class="bi bi-camera-video me-1" aria-hidden="true"></i><?= !empty($e['zoom_meeting_url']) ? 'Regeneruj link' : 'Wygeneruj link' ?>
          </button>
        </form>
      </div>
    </div>
  </div>
</div>
<?php endforeach; endif; ?>

<script>
function billToggle(sel) {
  var body   = sel.closest('.modal-body');
  var model  = parseInt(sel.value, 10);
  var fixed  = (model === 1 || model === 3);
  body.querySelector('.bill-rate').style.display   = fixed ? 'none' : '';
  body.querySelector('.bill-amount').style.display = fixed ? '' : 'none';
}
</script>

</main>
<?php $PRINT_TITLE = 'Grupa: ' . $course['name']; include __DIR__ . '/_print_page.php'; ?>
<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
