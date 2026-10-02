<?php
/**
 * karty30/ti/dydaktyk/mojeZgloszenia.php — Zgłoszenia trenera do kierownika:
 * niedostępność (zakres dat) i wniosek o zmianę terminu lekcji. Decyzje: zgloszenia.php.
 */
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__, 3) . '/modules/ti_scheduler/logic/schedulerWorkflow.php';

$me  = dyd_require();
$uid = (int)$me['user_id'];
ti_sched_migrate();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    dyd_token_check();
    $op = (string)($_POST['_op'] ?? '');
    if ($op === 'unavail') {
        $r = sc_request_unavailability($uid, trim((string)($_POST['date_from'] ?? '')), trim((string)($_POST['date_to'] ?? '')), (string)($_POST['leave_type'] ?? 'urlop'), (string)($_POST['reason'] ?? ''));
        flash_set($r['ok'] ? 'success' : 'danger', $r['msg']);
    } elseif ($op === 'resched') {
        $r = sc_request_reschedule($uid, (int)($_POST['session_id'] ?? 0), trim((string)($_POST['date'] ?? '')), trim((string)($_POST['time_from'] ?? '')), trim((string)($_POST['time_to'] ?? '')), (string)($_POST['reason'] ?? ''));
        flash_set($r['ok'] ? 'success' : 'danger', $r['msg']);
    } elseif ($op === 'withdraw') {
        flash_set(sc_request_withdraw((int)($_POST['id'] ?? 0), $uid) ? 'success' : 'danger', 'Zgłoszenie wycofane.');
    }
    header('Location: mojeZgloszenia.php'); exit;
}

$mine = db_all("SELECT r.*, s.lesson_date AS s_date, s.time_from AS s_from, c.name AS course_name,
                       (SELECT COUNT(*) FROM k30_sc_request_items i WHERE i.request_id=r.id) AS n_items,
                       (SELECT COUNT(*) FROM k30_sc_request_items i WHERE i.request_id=r.id AND i.resolution='pending') AS n_open
                  FROM k30_sc_requests r
                  LEFT JOIN k30_ti_sessions s ON s.id=r.session_id LEFT JOIN k30_ti_courses c ON c.id=s.course_id
                 WHERE r.instructor_id=? ORDER BY r.id DESC LIMIT 50", [$uid]);
$upcoming = db_all("SELECT s.id, s.lesson_date, s.time_from, s.time_to, c.name AS course_name
                      FROM k30_ti_sessions s JOIN k30_ti_courses c ON c.id=s.course_id
                     WHERE COALESCE(s.instructor_id,c.instructor_id)=CAST(? AS INTEGER) AND s.lesson_date>=? AND s.status IN ('planned','reserved') AND s.draft_id IS NULL
                     ORDER BY s.lesson_date, s.time_from LIMIT 200", [$uid, date('Y-m-d')]);
$badge = ['pending' => 'warning', 'approved' => 'success', 'rejected' => 'danger', 'cancelled' => 'secondary'];

$KP_TITLE  = 'Moje zgłoszenia — Panel dydaktyka';
$KP_TOPBAR = ['brand' => 'Panel dydaktyka', 'icon' => 'easel2', 'user' => (string)($me['name'] ?? ''), 'logout' => 'logout.php'];
$KP_BODY_CLASS = 'dyd-usos ti-skin';
include dirname(__DIR__) . '/kursant/_layout_head.php';
$_skin_css = __DIR__ . '/../assets/ti_skin.css';
?>
<link rel="stylesheet" href="../assets/ti_skin.css?v=<?= is_file($_skin_css) ? (int)filemtime($_skin_css) : 1 ?>">
<main id="main" class="dyd-wrap">
<?= flash_html() ?>
<h1 class="h4 mb-2"><i class="bi bi-envelope-exclamation me-2 text-primary"></i>Moje zgłoszenia</h1>
<p class="text-body-secondary small">Zgłoś niedostępność albo poproś o zmianę terminu lekcji. Kierownik dostaje powiadomienie, decyduje o zastępstwie lub przełożeniu, a Ty dostajesz odpowiedź e-mailem.</p>
<div class="row g-3 mb-4">
  <div class="col-lg-6"><div class="card border-0 shadow-sm h-100">
    <div class="card-header fw-semibold bg-body-tertiary">Zgłoś niedostępność</div>
    <form method="post" class="card-body row g-2"><input type="hidden" name="_token" value="<?= h(dyd_token()) ?>"><input type="hidden" name="_op" value="unavail">
      <div class="col-6"><label class="form-label small mb-0" for="uf">Od</label><input type="date" class="form-control form-control-sm" id="uf" name="date_from" required min="<?= date('Y-m-d') ?>"></div>
      <div class="col-6"><label class="form-label small mb-0" for="ut">Do</label><input type="date" class="form-control form-control-sm" id="ut" name="date_to" required min="<?= date('Y-m-d') ?>"></div>
      <div class="col-12"><label class="form-label small mb-0" for="ul">Rodzaj</label><select class="form-select form-select-sm" id="ul" name="leave_type"><?php foreach (TI_LEAVE_TYPES as $k => $l): ?><option value="<?= h($k) ?>"><?= h($l) ?></option><?php endforeach; ?></select></div>
      <div class="col-12"><label class="form-label small mb-0" for="ur">Powód / uwagi</label><textarea class="form-control form-control-sm" id="ur" name="reason" rows="2" maxlength="500"></textarea></div>
      <div class="col-12"><button class="btn btn-primary btn-sm">Wyślij do kierownika</button></div>
    </form></div></div>
  <div class="col-lg-6"><div class="card border-0 shadow-sm h-100">
    <div class="card-header fw-semibold bg-body-tertiary">Wniosek o zmianę terminu lekcji</div>
    <form method="post" class="card-body row g-2"><input type="hidden" name="_token" value="<?= h(dyd_token()) ?>"><input type="hidden" name="_op" value="resched">
      <div class="col-12"><label class="form-label small mb-0" for="rs">Lekcja</label><select class="form-select form-select-sm" id="rs" name="session_id" required><option value="">—</option>
        <?php foreach ($upcoming as $s): ?><option value="<?= (int)$s['id'] ?>"><?= h($s['lesson_date'] . ' ' . substr($s['time_from'], 0, 5) . '–' . substr($s['time_to'], 0, 5) . ' · ' . $s['course_name']) ?></option><?php endforeach; ?></select></div>
      <div class="col-6"><label class="form-label small mb-0" for="rd">Nowa data</label><input type="date" class="form-control form-control-sm" id="rd" name="date" required min="<?= date('Y-m-d') ?>"></div>
      <div class="col-3"><label class="form-label small mb-0" for="rf">Od</label><input type="time" class="form-control form-control-sm" id="rf" name="time_from" required></div>
      <div class="col-3"><label class="form-label small mb-0" for="rt">Do</label><input type="time" class="form-control form-control-sm" id="rt" name="time_to" required></div>
      <div class="col-12"><label class="form-label small mb-0" for="rr">Powód</label><textarea class="form-control form-control-sm" id="rr" name="reason" rows="2" maxlength="500"></textarea></div>
      <div class="col-12"><button class="btn btn-primary btn-sm">Wyślij wniosek</button></div>
    </form></div></div>
</div>
<div class="card border-0 shadow-sm"><div class="card-header fw-semibold bg-body-tertiary">Historia zgłoszeń</div>
<div class="table-responsive"><table class="table table-sm align-middle mb-0">
  <thead class="table-light"><tr><th scope="col">Zgłoszono</th><th scope="col">Rodzaj</th><th scope="col">Szczegóły</th><th scope="col">Status</th><th scope="col">Uwagi kierownika</th><th></th></tr></thead><tbody>
  <?php foreach ($mine as $r): ?><tr>
    <td><?= h(substr((string)$r['created_at'], 0, 16)) ?></td>
    <td><?= $r['type'] === 'reschedule' ? 'Zmiana terminu' : 'Niedostępność' ?></td>
    <td><?php if ($r['type'] === 'reschedule'): ?><?= h($r['course_name'] . ': ' . $r['s_date']) ?> → <?= h($r['proposed_date'] . ' ' . $r['proposed_from'] . '–' . $r['proposed_to']) ?>
      <?php else: ?><?= h($r['date_from'] . ' – ' . $r['date_to']) ?> · lekcji: <?= (int)$r['n_items'] ?><?= $r['status'] === 'approved' && (int)$r['n_open'] ? ' (do rozstrzygnięcia: ' . (int)$r['n_open'] . ')' : '' ?><?php endif; ?></td>
    <td><span class="badge text-bg-<?= $badge[$r['status']] ?? 'secondary' ?>"><?= h(SC_REQ_STATUSES[$r['status']] ?? $r['status']) ?></span></td>
    <td class="small"><?= h($r['decision_note']) ?></td>
    <td class="text-end"><?php if ($r['status'] === 'pending'): ?><form method="post" class="d-inline"><input type="hidden" name="_token" value="<?= h(dyd_token()) ?>"><input type="hidden" name="_op" value="withdraw"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-outline-secondary btn-sm py-0">Wycofaj</button></form><?php endif; ?></td>
  </tr><?php endforeach; if (!$mine): ?><tr><td colspan="6" class="text-body-secondary p-3">Brak zgłoszeń.</td></tr><?php endif; ?>
</tbody></table></div></div>
</main>
<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
