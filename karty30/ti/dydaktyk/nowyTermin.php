<?php
/**
 * karty30/ti/dydaktyk/nowyTermin.php — Odwołane zajęcia do ustalenia nowego terminu (kierownik).
 * Wpisy powstają przy odwołaniu z prośbą o kontakt (ti_cancel_sessions_notify): kierownik oznacza kontakt,
 * proponuje termin (lekcja wraca do planu, uczestnicy dostają e-mail i SMS) albo rezygnuje.
 */
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__, 3) . '/includes/ti_leaves.php';
require_once dirname(__DIR__, 3) . '/modules/ti_scheduler/logic/schedulerWorkflow.php';

$me = dyd_require();
if (!dyd_is_staff()) { header('Location: index.php'); exit; }
$uid = (int)$me['user_id'];
$who = (string)($me['name'] ?? '');
ti_sched_migrate(); ti_rebook_migrate();

$filter = in_array($_GET['f'] ?? '', ['active', 'closed', 'all'], true) ? $_GET['f'] : 'active';
$back   = 'nowyTermin.php' . ($filter !== 'active' ? '?f=' . $filter : '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    dyd_token_check();
    $op = (string)($_POST['_op'] ?? ''); $id = (int)($_POST['id'] ?? 0);
    if ($op === 'contacted' || $op === 'dropped') {
        flash_set(ti_rebook_set($id, $op, (string)($_POST['note'] ?? ''), $who) ? 'success' : 'danger',
            $op === 'contacted' ? 'Zapisano: skontaktowano z uczestnikami.' : 'Zrezygnowano z ustalania nowego terminu.');
    } elseif ($op === 'schedule') {
        $r = ti_rebook_schedule($id, trim((string)($_POST['date'] ?? '')), trim((string)($_POST['time_from'] ?? '')), trim((string)($_POST['time_to'] ?? '')),
            $who, !empty($_POST['notify']), !empty($_POST['force']));
        if (!empty($r['needs_ack'])) flash_set('warning', $r['msg'] . ' ' . implode(' ', $r['issues'] ?? []) . ' Zaznacz „mimo ostrzeżeń” i zatwierdź ponownie.');
        else flash_set($r['ok'] ? 'success' : 'danger', $r['msg']);
    }
    header('Location: ' . $back); exit;
}

$rows = ti_rebook_list($filter);
$badge = ['open' => 'warning', 'contacted' => 'info', 'scheduled' => 'success', 'dropped' => 'secondary', 'restored' => 'secondary'];

$KP_TITLE  = 'Nowe terminy po odwołaniu — Panel dydaktyka';
$KP_TOPBAR = ['brand' => 'Panel dydaktyka', 'icon' => 'easel2', 'user' => $who, 'logout' => 'logout.php'];
$KP_BODY_CLASS = 'dyd-usos ti-skin';
include dirname(__DIR__) . '/kursant/_layout_head.php';
$_skin_css = __DIR__ . '/../assets/ti_skin.css';
$tok = '<input type="hidden" name="_token" value="' . h(dyd_token()) . '">';
?>
<link rel="stylesheet" href="../assets/ti_skin.css?v=<?= is_file($_skin_css) ? (int)filemtime($_skin_css) : 1 ?>">
<?php $PL_CUR = 'nowyTermin.php'; $PL_LABEL = 'Nowe terminy po odwołaniu'; include __DIR__ . '/_planista_bar.php'; ?>
<main id="main" class="dyd-wrap">
<?= flash_html() ?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-2">
  <h1 class="h4 mb-0"><i class="bi bi-calendar-plus me-2 text-primary" aria-hidden="true"></i>Nowe terminy po odwołaniu</h1>
  <nav class="btn-group btn-group-sm ms-auto" aria-label="Filtr listy">
    <a class="btn btn-outline-primary <?= $filter === 'active' ? 'active' : '' ?>" href="nowyTermin.php" <?= $filter === 'active' ? 'aria-current="page"' : '' ?>>Do ustalenia</a>
    <a class="btn btn-outline-primary <?= $filter === 'closed' ? 'active' : '' ?>" href="nowyTermin.php?f=closed" <?= $filter === 'closed' ? 'aria-current="page"' : '' ?>>Zamknięte</a>
    <a class="btn btn-outline-primary <?= $filter === 'all' ? 'active' : '' ?>" href="nowyTermin.php?f=all" <?= $filter === 'all' ? 'aria-current="page"' : '' ?>>Wszystkie</a>
  </nav>
</div>
<p class="text-body-secondary small">Zajęcia odwołane z prośbą o kontakt w sprawie nowego terminu. Po ustaleniu terminu lekcja wraca do planu, a uczestnicy dostają e-mail i SMS.</p>

<?php if (!$rows): ?>
<div class="alert alert-light border"><i class="bi bi-check2-circle text-success me-1" aria-hidden="true"></i><?= $filter === 'active' ? 'Nic nie czeka na ustalenie nowego terminu.' : 'Brak wpisów.' ?></div>
<?php else: ?>
<div class="table-responsive"><table class="table table-sm align-middle">
  <caption class="visually-hidden">Odwołane zajęcia do ustalenia nowego terminu</caption>
  <thead class="table-light"><tr><th scope="col">Zajęcia</th><th scope="col">Powód</th><th scope="col">Stan</th><th scope="col">Działania</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r):
    $act = in_array($r['status'], TI_REBOOK_ACTIVE, true) && $r['session_status'] === 'cancelled'; ?>
  <tr>
    <td>
      <strong><?= h($r['course_name']) ?></strong><br>
      <span class="small text-body-secondary">odwołane: <?= h(date('d.m.Y', strtotime((string)$r['orig_date']))) ?>, <?= h(substr((string)$r['orig_from'], 0, 5)) ?>–<?= h(substr((string)$r['orig_to'], 0, 5)) ?>
        <?= $r['instructor_name'] ? ' · ' . h($r['instructor_name']) : '' ?></span>
      <?php if ($r['status'] === 'scheduled'): ?><br><span class="small">nowy termin: <strong><?= h(date('d.m.Y', strtotime((string)$r['lesson_date']))) ?>, <?= h(substr((string)$r['time_from'], 0, 5)) ?>–<?= h(substr((string)$r['time_to'], 0, 5)) ?></strong></span><?php endif; ?>
    </td>
    <td class="small"><?= h($r['reason']) ?><?= $r['note'] !== '' ? '<br><em>Notatka: ' . h($r['note']) . '</em>' : '' ?></td>
    <td><span class="badge text-bg-<?= $badge[$r['status']] ?? 'secondary' ?>"><?= h(TI_REBOOK_STATUSES[$r['status']] ?? $r['status']) ?></span>
      <?php if ($r['decided_by'] !== ''): ?><br><span class="small text-body-secondary"><?= h($r['decided_by']) ?>, <?= h(substr((string)$r['decided_at'], 0, 16)) ?></span><?php endif; ?></td>
    <td>
    <?php if ($act): ?>
      <form method="post" class="d-flex flex-wrap gap-1 align-items-center mb-1"><?= $tok ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
        <label class="visually-hidden" for="nt<?= (int)$r['id'] ?>">Notatka z kontaktu</label>
        <input id="nt<?= (int)$r['id'] ?>" name="note" class="form-control form-control-sm" style="width:12rem" placeholder="Notatka (opcjonalnie)" value="<?= h($r['note']) ?>">
        <button class="btn btn-outline-info btn-sm" name="_op" value="contacted">Skontaktowano</button>
        <button class="btn btn-outline-secondary btn-sm" name="_op" value="dropped" onclick="return confirm('Zrezygnować z ustalania nowego terminu?')">Zrezygnuj</button>
      </form>
      <form method="post" class="d-flex flex-wrap gap-1 align-items-center"><?= $tok ?><input type="hidden" name="_op" value="schedule"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
        <label class="visually-hidden" for="nd<?= (int)$r['id'] ?>">Nowa data</label><input type="date" id="nd<?= (int)$r['id'] ?>" name="date" class="form-control form-control-sm" style="width:auto" min="<?= date('Y-m-d') ?>" required>
        <label class="visually-hidden" for="nf<?= (int)$r['id'] ?>">Od</label><input type="time" id="nf<?= (int)$r['id'] ?>" name="time_from" class="form-control form-control-sm" style="width:auto" value="<?= h(substr((string)$r['orig_from'], 0, 5)) ?>" required>
        <label class="visually-hidden" for="nu<?= (int)$r['id'] ?>">Do</label><input type="time" id="nu<?= (int)$r['id'] ?>" name="time_to" class="form-control form-control-sm" style="width:auto" value="<?= h(substr((string)$r['orig_to'], 0, 5)) ?>" required>
        <label class="small"><input type="checkbox" name="notify" value="1" class="form-check-input me-1" checked>powiadom (e-mail + SMS)</label>
        <label class="small"><input type="checkbox" name="force" value="1" class="form-check-input me-1">mimo ostrzeżeń</label>
        <button class="btn btn-primary btn-sm">Zaproponuj termin</button>
      </form>
    <?php else: ?><span class="text-body-secondary small">—</span><?php endif; ?>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody></table></div>
<?php endif; ?>
</main>
<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
