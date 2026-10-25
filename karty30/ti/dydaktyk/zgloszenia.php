<?php
/**
 * karty30/ti/dydaktyk/zgloszenia.php — Zgłoszenia trenerów (kierownik): kolejka decyzji.
 * Niedostępność: zatwierdź (opcjonalnie z automatycznym doborem zastępców) i rozstrzygnij lekcje.
 * Wniosek o zmianę terminu: zatwierdź (przełożenie z walidacją) lub odrzuć.
 */
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__, 3) . '/modules/ti_scheduler/logic/schedulerWorkflow.php';

$me = dyd_require();
if (!dyd_is_staff()) { header('Location: index.php'); exit; }
$uid = (int)$me['user_id'];
ti_sched_migrate();
$ack = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    dyd_token_check();
    $op = (string)($_POST['_op'] ?? ''); $rid = (int)($_POST['request_id'] ?? 0);
    $force = !empty($_POST['force']);
    if (in_array($op, ['approve', 'approve_auto', 'reject'], true)) {
        $r = sc_request_decide($rid, $op === 'reject' ? 'reject' : 'approve', (string)($_POST['note'] ?? ''), $uid, $op === 'approve_auto', $force);
        flash_set($r['ok'] ? 'success' : 'danger', $r['msg']);
    } elseif ($op === 'item') {
        $act = (string)($_POST['action'] ?? '');
        $r = sc_apply_item((int)($_POST['item_id'] ?? 0), $act, $_POST, $uid, $force);
        if (!empty($r['needs_ack'])) {
            flash_set('warning', $r['msg'] . ' ' . implode(' ', $r['issues'] ?? []) . ' Zaznacz „mimo ostrzeżeń” i zatwierdź ponownie.');
        } else flash_set($r['ok'] ? 'success' : 'danger', $r['msg']);
    }
    header('Location: zgloszenia.php' . (!empty($_POST['hist']) ? '?hist=1' : '')); exit;
}

$hist = !empty($_GET['hist']);
$where = $hist ? "r.status!='pending'" : "(r.status='pending' OR EXISTS (SELECT 1 FROM k30_sc_request_items i WHERE i.request_id=r.id AND i.resolution='pending' AND r.status='approved'))";
$reqs = db_all("SELECT r.*, COALESCE(NULLIF(TRIM(u.first_name||' '||u.last_name),''), u.name) AS iname, s.lesson_date AS s_date, s.time_from AS s_from, s.time_to AS s_to, c.name AS course_name
                  FROM k30_sc_requests r JOIN users u ON u.id=r.instructor_id
                  LEFT JOIN k30_ti_sessions s ON s.id=r.session_id LEFT JOIN k30_ti_courses c ON c.id=s.course_id
                 WHERE $where ORDER BY r.id DESC LIMIT 60");
$badge = ['pending' => 'warning', 'approved' => 'success', 'rejected' => 'danger', 'cancelled' => 'secondary'];

$KP_TITLE  = 'Zgłoszenia trenerów — Panel dydaktyka';
$KP_TOPBAR = ['brand' => 'Panel dydaktyka', 'icon' => 'easel2', 'user' => (string)($me['name'] ?? ''), 'logout' => 'logout.php'];
$KP_BODY_CLASS = 'dyd-usos ti-skin';
include dirname(__DIR__) . '/kursant/_layout_head.php';
$_skin_css = __DIR__ . '/../assets/ti_skin.css';
$tok = '<input type="hidden" name="_token" value="' . h(dyd_token()) . '">';
?>
<link rel="stylesheet" href="../assets/ti_skin.css?v=<?= is_file($_skin_css) ? (int)filemtime($_skin_css) : 1 ?>">
<?php $PL_CUR = 'zgloszenia.php'; $PL_LABEL = 'Zgłoszenia trenerów'; include __DIR__ . '/_planista_bar.php'; ?>
<main id="main" class="dyd-wrap">
<?= flash_html() ?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-2">
  <h1 class="h4 mb-0"><i class="bi bi-inbox me-2 text-primary"></i>Zgłoszenia trenerów</h1>
  <div class="btn-group btn-group-sm ms-auto"><a class="btn btn-outline-primary <?= $hist ? '' : 'active' ?>" href="zgloszenia.php">Do decyzji</a><a class="btn btn-outline-primary <?= $hist ? 'active' : '' ?>" href="zgloszenia.php?hist=1">Historia</a></div>
  <a class="btn btn-outline-secondary btn-sm" href="planista.php"><i class="bi bi-calendar3-range me-1"></i>Planista</a>
</div>
<?php foreach ($reqs as $r):
  $items = $r['type'] === 'unavailability' ? db_all("SELECT i.*, s.lesson_date, s.time_from, s.time_to, s.status AS sstatus, c.name AS course_name
        FROM k30_sc_request_items i JOIN k30_ti_sessions s ON s.id=i.session_id JOIN k30_ti_courses c ON c.id=s.course_id WHERE i.request_id=? ORDER BY s.lesson_date, s.time_from", [(int)$r['id']]) : [];
?>
<div class="card border-0 shadow-sm mb-3">
  <div class="card-header d-flex flex-wrap align-items-center gap-2 bg-body-tertiary">
    <strong><?= h($r['iname']) ?></strong>
    <span><?= $r['type'] === 'reschedule' ? 'wniosek o zmianę terminu' : 'niedostępność: ' . h(ti_leave_type_label($r['leave_type'])) ?></span>
    <span class="badge text-bg-<?= $badge[$r['status']] ?? 'secondary' ?>"><?= h(SC_REQ_STATUSES[$r['status']] ?? $r['status']) ?></span>
    <span class="ms-auto small text-body-secondary"><?= h(substr((string)$r['created_at'], 0, 16)) ?></span>
  </div>
  <div class="card-body">
    <?php if ($r['type'] === 'reschedule'): ?>
      <p class="mb-1"><?= h((string)$r['course_name']) ?>: <?= h($r['s_date'] . ' ' . substr((string)$r['s_from'], 0, 5) . '–' . substr((string)$r['s_to'], 0, 5)) ?> → <strong><?= h($r['proposed_date'] . ' ' . $r['proposed_from'] . '–' . $r['proposed_to']) ?></strong></p>
    <?php else: ?>
      <p class="mb-1">Termin: <strong><?= h($r['date_from'] . ' – ' . $r['date_to'] . (($r['time_from'] ?? '') !== '' ? ', ' . $r['time_from'] . '–' . $r['time_to'] : '')) ?></strong> · lekcji dotkniętych: <?= count($items) ?></p>
    <?php endif; ?>
    <?php if ($r['reason'] !== ''): ?><p class="small text-body-secondary mb-2">Powód: <?= h($r['reason']) ?></p><?php endif; ?>
    <?php if ($r['decision_note'] !== ''): ?><p class="small mb-2">Uwagi kierownika: <?= h($r['decision_note']) ?></p><?php endif; ?>

    <?php if ($r['status'] === 'pending'): ?>
    <form method="post" class="d-flex flex-wrap gap-2 align-items-center"><?= $tok ?><input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>">
      <input name="note" class="form-control form-control-sm" style="max-width:20rem" placeholder="Uwagi dla trenera (opcjonalnie)" aria-label="Uwagi">
      <?php if ($r['type'] === 'unavailability'): ?><button class="btn btn-success btn-sm" name="_op" value="approve_auto" title="Zatwierdź i przypisz najlepszych zastępców bez ostrzeżeń"><i class="bi bi-lightning-charge me-1"></i>Zatwierdź + zastępstwa</button><?php endif; ?>
      <button class="btn btn-outline-success btn-sm" name="_op" value="approve">Zatwierdź</button>
      <label class="form-check-label small"><input type="checkbox" class="form-check-input me-1" name="force" value="1">mimo ostrzeżeń</label>
      <button class="btn btn-outline-danger btn-sm" name="_op" value="reject">Odrzuć</button>
    </form>
    <?php endif; ?>

    <?php if ($items): ?>
    <div class="table-responsive mt-3"><table class="table table-sm align-middle mb-0">
      <thead class="table-light"><tr><th scope="col">Lekcja</th><th scope="col">Stan</th><th scope="col">Rozstrzygnięcie</th></tr></thead><tbody>
      <?php foreach ($items as $it): $open = $it['resolution'] === 'pending' && $r['status'] === 'approved';
        $sugg = $open ? sc_suggest_substitutes((int)$it['session_id'], 5) : []; ?>
      <tr>
        <td><?= h($it['lesson_date'] . ' ' . substr($it['time_from'], 0, 5) . '–' . substr($it['time_to'], 0, 5)) ?><br><small class="text-body-secondary"><?= h($it['course_name']) ?></small></td>
        <td><?= h(SC_RES_LABELS[$it['resolution']] ?? $it['resolution']) ?><?= $it['substitute_id'] ? ' — ' . h(sc_user_name((int)$it['substitute_id'])) : '' ?></td>
        <td>
        <?php if ($open): ?>
          <form method="post" class="d-flex flex-wrap gap-1 align-items-center"><?= $tok ?><input type="hidden" name="_op" value="item"><input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>"><?php if ($hist): ?><input type="hidden" name="hist" value="1"><?php endif; ?>
            <select name="action" class="form-select form-select-sm" style="width:auto" aria-label="Akcja">
              <option value="substitute">Zastępstwo</option><option value="reschedule">Przełóż</option><option value="cancel">Odwołaj</option><option value="keep">Bez zmian</option></select>
            <select name="substitute_id" class="form-select form-select-sm" style="width:auto;max-width:16rem" aria-label="Zastępca">
              <?php foreach ($sugg as $c): ?><option value="<?= (int)$c['id'] ?>"><?= h($c['name'] . ' (' . round($c['week_min'] / 60, 1) . ' h/tydz.)' . ($c['soft'] ? ' ⚠' : '')) ?></option><?php endforeach; if (!$sugg): ?><option value="0">brak wolnych zastępców</option><?php endif; ?></select>
            <input type="date" name="date" class="form-control form-control-sm" style="width:auto" aria-label="Nowa data (przełożenie)">
            <input type="time" name="time_from" class="form-control form-control-sm" style="width:auto" value="<?= h(substr($it['time_from'], 0, 5)) ?>" aria-label="Od">
            <input type="time" name="time_to" class="form-control form-control-sm" style="width:auto" value="<?= h(substr($it['time_to'], 0, 5)) ?>" aria-label="Do">
            <label class="small"><input type="checkbox" name="force" value="1" class="form-check-input me-1">mimo ostrzeżeń</label>
            <button class="btn btn-primary btn-sm">Zastosuj</button>
          </form>
        <?php else: ?><span class="text-body-secondary small">—</span><?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?></tbody></table></div>
    <?php endif; ?>
  </div>
</div>
<?php endforeach; if (!$reqs): ?><div class="alert alert-light border"><i class="bi bi-check2-circle text-success me-1"></i><?= $hist ? 'Brak historii zgłoszeń.' : 'Nic nie czeka na decyzję.' ?></div><?php endif; ?>
</main>
<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
