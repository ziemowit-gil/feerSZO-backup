<?php
/**
 * karty30/ti/dydaktyk/kolejka.php — kolejka oczekujących na konsultację (przeniesione z karty30/waiting/index.php).
 * Priorytety pilny / PFRON / zwykły, statusy, SMS z propozycją terminu (rodzaj ti_waiting_list), „Zarezerwuj” →
 * nowa rezerwacja z beneficjentem; po zapisie wpis zmienia się na „Zaplanowane” i dostaje powiązanie z terminem.
 */
require __DIR__ . '/_rezerwacje_nav.php';
require_once dirname(__DIR__, 3) . '/includes/sms.php';
$smsOk = function_exists('sms_channel_ready') && sms_channel_ready();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    dyd_token_check();
    $op = (string)($_POST['_op'] ?? ''); $wid = (int)($_POST['id'] ?? 0);
    if ($op === 'add') {
        $r = TiBookings::waitingAdd((int)($_POST['client_id'] ?? 0), (string)($_POST['priority'] ?? 'zwykly'), trim((string)($_POST['reason'] ?? '')), trim((string)($_POST['notes'] ?? '')), $uid);
        flash_set($r['ok'] ? 'success' : 'warning', $r['msg']);
    } elseif ($op === 'status' && $wid && isset(K30_WAIT_STATUSES[$_POST['status'] ?? ''])) {
        k30_waiting_change_status($wid, (string)$_POST['status']);
        flash_set('success', 'Status zmieniony.');
    } elseif ($op === 'sms' && $wid && $smsOk) {
        $msg = trim((string)($_POST['sms_message'] ?? ''));
        flash_set($msg !== '' && k30_waiting_send_sms($wid, $msg) ? 'success' : 'danger', $msg !== '' ? 'SMS wysłany — status „Skontaktowano”.' : 'Wpisz treść SMS.');
    }
    header('Location: kolejka.php' . (isset($_GET['status']) ? '?status=' . rawurlencode((string)$_GET['status']) : '')); exit;
}

$status = (string)($_GET['status'] ?? 'active');
$rows = k30_waiting_list($status === 'all' ? '' : $status);
$clients = db_all("SELECT id, name FROM k30_clients ORDER BY name");
$org = defined('ORG_NAME') ? ORG_NAME : 'Fundacja';
$smsTpl = "Dzień dobry! {$org} informuje, że możemy zaproponować Panu/Pani termin konsultacji. Prosimy o kontakt tel. lub odpowiedź na tę wiadomość.";
$RZ_TITLE = 'Kolejka oczekujących'; $RZ_CUR = 'kolejka.php';
include __DIR__ . '/_rezerwacje_head.php';
?>
<main id="main" class="dyd-wrap">
<div class="container-fluid py-3" style="max-width:1200px">
<h1 class="h4 fw-bold mb-2"><i class="bi bi-hourglass-split text-primary me-2" aria-hidden="true"></i>Kolejka oczekujących na konsultację</h1>
<?php rz_nav('kolejka'); ?>
<?= flash_html() ?>
<div class="row g-3">
<div class="col-lg-8">
  <nav class="mb-2" aria-label="Filtr statusu"><?php foreach (['active' => 'Aktywne', 'waiting' => 'Oczekuje', 'contacted' => 'Skontaktowano', 'scheduled' => 'Zaplanowane', 'done' => 'Zakończone', 'cancelled' => 'Anulowane', 'all' => 'Wszystkie'] as $k => $l): ?>
    <a class="btn btn-sm <?= $status === $k ? 'btn-primary' : 'btn-outline-secondary' ?> mb-1" href="kolejka.php?status=<?= $k ?>"<?= $status === $k ? ' aria-current="page"' : '' ?>><?= h($l) ?></a><?php endforeach; ?></nav>
  <?php if (!$rows): ?><div class="alert alert-secondary">Kolejka jest pusta.</div><?php endif; ?>
  <?php foreach ($rows as $w): $pr = K30_WAIT_PRIORITIES[$w['priority']] ?? K30_WAIT_PRIORITIES['zwykly']; $ws = K30_WAIT_STATUSES[$w['status']] ?? ['label' => $w['status']]; ?>
  <section class="card border-0 shadow-sm mb-2" aria-label="<?= h($w['client_name'] ?? '') ?>"><div class="card-body py-2">
    <div class="d-flex flex-wrap align-items-center gap-2">
      <strong><?= h($w['client_name'] ?? '') ?></strong>
      <span class="badge" style="background:<?= h($pr['bg']) ?>;color:#111827;border:1px solid <?= h($pr['color']) ?>"><?= h($pr['label']) ?></span>
      <span class="badge text-bg-light border"><?= h($ws['label']) ?></span>
      <span class="small text-body-secondary">od <?= h(date('d.m.Y', strtotime((string)$w['created_at']))) ?><?= (int)$w['sms_count'] ? ' · SMS: ' . (int)$w['sms_count'] : '' ?></span>
      <span class="ms-auto d-flex gap-1">
        <?php if (in_array($w['status'], ['waiting', 'contacted'], true)): ?>
        <a class="btn btn-sm btn-primary" href="rezerwacja.php?nowa=1&amp;client_id=<?= (int)$w['client_id'] ?>&amp;from_waiting=<?= (int)$w['id'] ?><?= $w['priority'] === 'pfron' ? '&amp;billing_type=pfron' : '' ?>">Zarezerwuj termin</a>
        <?php elseif (!empty($w['scheduled_id'])): ?><a class="btn btn-sm btn-outline-secondary" href="rezerwacja.php?id=<?= (int)$w['scheduled_id'] ?>">Rezerwacja</a><?php endif; ?>
      </span>
    </div>
    <?php if ($w['reason'] || $w['notes']): ?><p class="small mb-1 mt-1"><?= h(trim($w['reason'] . ($w['notes'] ? ' — ' . $w['notes'] : ''))) ?></p><?php endif; ?>
    <details class="small"><summary>Zmień status<?= $smsOk ? ' / wyślij SMS' : '' ?></summary>
      <form method="post" class="d-flex flex-wrap gap-1 mt-2"><input type="hidden" name="_token" value="<?= h(dyd_token()) ?>"><input type="hidden" name="_op" value="status"><input type="hidden" name="id" value="<?= (int)$w['id'] ?>">
        <?php foreach (K30_WAIT_STATUSES as $k => $v): if ($k === $w['status']) continue; ?><button class="btn btn-sm btn-outline-secondary" name="status" value="<?= h($k) ?>"><?= h($v['label']) ?></button><?php endforeach; ?></form>
      <?php if ($smsOk): ?>
      <form method="post" class="mt-2"><input type="hidden" name="_token" value="<?= h(dyd_token()) ?>"><input type="hidden" name="_op" value="sms"><input type="hidden" name="id" value="<?= (int)$w['id'] ?>">
        <label class="form-label small mb-0" for="sms<?= (int)$w['id'] ?>">Treść SMS</label>
        <textarea class="form-control form-control-sm mb-1" id="sms<?= (int)$w['id'] ?>" name="sms_message" rows="2" maxlength="300"><?= h($smsTpl) ?></textarea>
        <button class="btn btn-sm btn-outline-primary"><i class="bi bi-chat-dots me-1" aria-hidden="true"></i>Wyślij SMS</button></form>
      <?php endif; ?>
    </details>
  </div></section>
  <?php endforeach; ?>
</div>
<div class="col-lg-4">
  <form method="post" class="card border-0 shadow-sm"><div class="card-body">
    <h2 class="h6 fw-bold">Dodaj do kolejki</h2>
    <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>"><input type="hidden" name="_op" value="add">
    <label class="form-label small mb-0" for="kq-c">Beneficjent</label>
    <select class="form-select form-select-sm mb-2" id="kq-c" name="client_id" required><option value="">— wybierz —</option><?php foreach ($clients as $c): ?><option value="<?= (int)$c['id'] ?>"<?= (int)($_GET['add_client'] ?? 0) === (int)$c['id'] ? ' selected' : '' ?>><?= h($c['name']) ?></option><?php endforeach; ?></select>
    <label class="form-label small mb-0" for="kq-p">Priorytet</label>
    <select class="form-select form-select-sm mb-2" id="kq-p" name="priority"><?php foreach (K30_WAIT_PRIORITIES as $k => $v): ?><option value="<?= h($k) ?>"<?= $k === 'zwykly' ? ' selected' : '' ?>><?= h($v['label']) ?></option><?php endforeach; ?></select>
    <label class="form-label small mb-0" for="kq-r">Powód / temat</label><input class="form-control form-control-sm mb-2" id="kq-r" name="reason">
    <label class="form-label small mb-0" for="kq-n">Uwagi</label><textarea class="form-control form-control-sm mb-2" id="kq-n" name="notes" rows="2"></textarea>
    <button class="btn btn-sm btn-primary">Dodaj</button>
  </div></form>
</div>
</div>
</div>
</main>
<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
