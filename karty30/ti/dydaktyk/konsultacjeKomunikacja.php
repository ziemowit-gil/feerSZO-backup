<?php
/**
 * karty30/ti/dydaktyk/konsultacjeKomunikacja.php — Komunikacja w trybie Konsultacje: e-mail / SMS do beneficjentów
 * wg rezerwacji (dzień, okres, konsultant), kolejki oczekujących albo wybranych osób; szablony i wstawki {imie}, {termin};
 * podgląd odbiorców przed wysyłką, log wysyłek. Logika: modules/ti_consult_comm/logic/consultComm.php.
 */
require __DIR__ . '/_rezerwacje_nav.php';
require_once dirname(__DIR__, 3) . '/modules/ti_consult_comm/logic/consultComm.php';
require_once dirname(__DIR__, 3) . '/includes/sms.php';
$smsOn = function_exists('sms_channel_ready') && sms_channel_ready();

$mode = isset(TiConsultComm::MODES[$_POST['mode'] ?? $_GET['mode'] ?? '']) ? (string)($_POST['mode'] ?? $_GET['mode']) : 'dzien';
$F = ['date' => (string)($_POST['date'] ?? date('Y-m-d', strtotime('tomorrow'))), 'from' => (string)($_POST['from'] ?? date('Y-m-d')), 'to' => (string)($_POST['to'] ?? date('Y-m-d', strtotime('+7 days'))),
      'consultant' => (int)($_POST['consultant'] ?? $uid), 'client_ids' => (array)($_POST['client_ids'] ?? [])];
$subject = (string)($_POST['subject'] ?? TiConsultComm::TEMPLATES['przypomnienie'][0]);
$body = (string)($_POST['body'] ?? TiConsultComm::TEMPLATES['przypomnienie'][1]);
$chE = $_SERVER['REQUEST_METHOD'] === 'POST' ? !empty($_POST['ch_email']) : true;
$chS = $_SERVER['REQUEST_METHOD'] === 'POST' ? !empty($_POST['ch_sms']) : $smsOn;
$recips = []; $label = ''; $preview = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    dyd_token_check();
    [$recips, $label] = TiConsultComm::targets($mode, $F);
    $preview = true;
    if (($_POST['_op'] ?? '') === 'send') {
        $err = [];
        if (!$chE && !$chS) $err[] = 'Wybierz kanał: e-mail i/lub SMS.';
        if ($chS && !$smsOn) $err[] = 'SMS jest wyłączony w ustawieniach systemu.';
        if (trim($body) === '') $err[] = 'Wpisz treść.';
        if ($chE && trim($subject) === '') $err[] = 'Podaj temat e-maila.';
        if (!$recips) $err[] = 'Brak odbiorców dla wybranego filtra.';
        if ($err) foreach ($err as $e) flash_set('danger', $e);
        else {
            $r = TiConsultComm::send($recips, $chE, $chS, trim($subject), trim($body), $mode, $label, $uid);
            flash_set($r['fail'] ? 'warning' : 'success', 'Wysłano: ' . $r['ok'] . ($r['fail'] ? ', błędów: ' . $r['fail'] : '') . ' (odbiorców: ' . count($recips) . ').');
            header('Location: konsultacjeKomunikacja.php'); exit;
        }
    }
}
$consultants = k30_get_consultants();
$people = TiConsultComm::people();
$log = TiConsultComm::log();

$RZ_TITLE = 'Komunikacja z beneficjentami'; $RZ_CUR = 'konsultacjeKomunikacja.php';
include __DIR__ . '/_rezerwacje_head.php';
?>
<main id="main" class="dyd-wrap">
<div class="container-fluid py-3" style="max-width:1200px">
<h1 class="h4 fw-bold mb-2"><i class="bi bi-send text-primary me-2" aria-hidden="true"></i>Komunikacja z beneficjentami konsultacji</h1>
<?= flash_html() ?>
<?php if (!$smsOn): ?><div class="alert alert-info small py-2">SMS jest wyłączony w ustawieniach systemu — dostępny jest e-mail.</div><?php endif; ?>
<form method="post" class="row g-3">
  <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
  <div class="col-lg-5">
    <fieldset class="card border-0 shadow-sm"><div class="card-body">
      <legend class="h6 fw-bold">Do kogo</legend>
      <?php foreach (TiConsultComm::MODES as $k => $l): ?>
      <div class="form-check"><input class="form-check-input kc-mode" type="radio" name="mode" id="km-<?= $k ?>" value="<?= $k ?>"<?= $mode === $k ? ' checked' : '' ?>><label class="form-check-label" for="km-<?= $k ?>"><?= h($l) ?></label></div>
      <?php endforeach; ?>
      <div class="kc-pane mt-2" data-mode="dzien"><label class="form-label small mb-0" for="kc-d">Dzień</label><input class="form-control form-control-sm" type="date" id="kc-d" name="date" value="<?= h($F['date']) ?>"></div>
      <div class="kc-pane mt-2 row g-2" data-mode="okres"><div class="col"><label class="form-label small mb-0" for="kc-f">Od</label><input class="form-control form-control-sm" type="date" id="kc-f" name="from" value="<?= h($F['from']) ?>"></div>
        <div class="col"><label class="form-label small mb-0" for="kc-t">Do</label><input class="form-control form-control-sm" type="date" id="kc-t" name="to" value="<?= h($F['to']) ?>"></div></div>
      <div class="kc-pane mt-2" data-mode="konsultant"><label class="form-label small mb-0" for="kc-c">Konsultant</label>
        <select class="form-select form-select-sm" id="kc-c" name="consultant"><?php foreach ($consultants as $c): ?><option value="<?= (int)$c['id'] ?>"<?= $F['consultant'] === (int)$c['id'] ? ' selected' : '' ?>><?= h($c['display_name']) ?></option><?php endforeach; ?></select></div>
      <div class="kc-pane mt-2" data-mode="osoby"><label class="form-label small mb-0" for="kc-o">Beneficjenci (Ctrl/Cmd — kilka)</label>
        <select class="form-select form-select-sm" id="kc-o" name="client_ids[]" multiple size="8"><?php foreach ($people as $p): ?><option value="<?= (int)$p['id'] ?>"<?= in_array((string)$p['id'], array_map('strval', $F['client_ids']), true) ? ' selected' : '' ?>><?= h($p['name']) ?></option><?php endforeach; ?></select></div>
      <button class="btn btn-sm btn-outline-primary mt-3" name="_op" value="preview"><i class="bi bi-people me-1" aria-hidden="true"></i>Pokaż odbiorców</button>
    </div></fieldset>
  </div>
  <div class="col-lg-7">
    <fieldset class="card border-0 shadow-sm"><div class="card-body">
      <legend class="h6 fw-bold">Wiadomość</legend>
      <div class="mb-2"><div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="ch_email" id="kc-e" value="1"<?= $chE ? ' checked' : '' ?>><label class="form-check-label" for="kc-e">E-mail</label></div>
        <div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="ch_sms" id="kc-s" value="1"<?= $chS ? ' checked' : '' ?><?= $smsOn ? '' : ' disabled' ?>><label class="form-check-label" for="kc-s">SMS</label></div></div>
      <div class="mb-2 d-flex flex-wrap gap-1" role="group" aria-label="Szablony"><?php foreach (TiConsultComm::TEMPLATES as $k => [$ts, $tb]): ?>
        <button type="button" class="btn btn-sm btn-outline-secondary kc-tpl" data-s="<?= h($ts) ?>" data-b="<?= h($tb) ?>"><?= h($ts) ?></button><?php endforeach; ?></div>
      <label class="form-label small mb-0" for="kc-sub">Temat (e-mail)</label><input class="form-control form-control-sm mb-2" id="kc-sub" name="subject" maxlength="200" value="<?= h($subject) ?>">
      <label class="form-label small mb-0" for="kc-b">Treść</label><textarea class="form-control form-control-sm" id="kc-b" name="body" rows="5" aria-describedby="kc-bh"><?= h($body) ?></textarea>
      <div class="form-text" id="kc-bh">Wstawki: {imie}, {nazwisko}, {termin} (najbliższa rezerwacja z filtra), {konsultant}. SMS dostaje tę samą treść bez formatowania.</div>
      <?php if ($preview && $recips): ?><button class="btn btn-primary mt-3" name="_op" value="send" onclick="return confirm('Wysłać wiadomość do <?= count($recips) ?> odbiorców?')"><i class="bi bi-send me-1" aria-hidden="true"></i>Wyślij (<?= count($recips) ?>)</button>
      <?php else: ?><p class="small text-body-secondary mt-3 mb-0">Najpierw „Pokaż odbiorców” — wysyłka po sprawdzeniu listy.</p><?php endif; ?>
    </div></fieldset>
  </div>
</form>

<?php if ($preview): ?>
<section class="card border-0 shadow-sm mt-3" aria-labelledby="kc-r-h"><div class="card-body">
  <h2 class="h6 fw-bold" id="kc-r-h">Odbiorcy: <?= h($label) ?> — <?= count($recips) ?></h2>
  <?php if (!$recips): ?><p class="small text-body-secondary mb-0">Brak odbiorców dla wybranego filtra.</p><?php else: ?>
  <div class="table-responsive"><table class="table table-sm mb-0"><caption class="visually-hidden">Odbiorcy wiadomości</caption>
    <thead><tr><th scope="col">Beneficjent</th><th scope="col">E-mail</th><th scope="col">Telefon</th><th scope="col">Termin</th><th scope="col">Podgląd treści</th></tr></thead>
    <tbody><?php foreach ($recips as $r): ?><tr><th scope="row" class="fw-normal"><?= h($r['name']) ?></th><td class="small"><?= $r['email'] !== '' ? h($r['email']) : '<span class="text-danger">brak</span>' ?></td>
      <td class="small"><?= $r['phone'] !== '' ? h($r['phone']) : '<span class="text-danger">brak</span>' ?></td><td class="small"><?= h($r['termin']) ?></td><td class="small"><?= h(mb_strimwidth(TiConsultComm::fill($body, $r), 0, 120, '…')) ?></td></tr><?php endforeach; ?></tbody>
  </table></div><?php endif; ?>
</div></section>
<?php endif; ?>

<?php if ($log): ?>
<section class="mt-4" aria-labelledby="kc-l-h"><h2 class="h6 fw-bold" id="kc-l-h">Ostatnie wysyłki</h2>
<div class="table-responsive"><table class="table table-sm"><caption class="visually-hidden">Log wysyłek do beneficjentów konsultacji</caption>
  <thead><tr><th scope="col">Kiedy</th><th scope="col">Kto</th><th scope="col">Kanał</th><th scope="col">Odbiorcy</th><th scope="col">Temat</th><th scope="col" class="text-end">Wysłano</th></tr></thead>
  <tbody><?php foreach ($log as $l): ?><tr><td class="small text-nowrap"><?= h(substr((string)$l['created_at'], 0, 16)) ?></td><td class="small"><?= h($l['by_name'] ?? '') ?></td><td class="small"><?= h($l['channel']) ?></td>
    <td class="small"><?= h($l['filter_label']) ?> (<?= (int)$l['recipients'] ?>)</td><td class="small"><?= h($l['subject']) ?></td><td class="small text-end"><?= (int)$l['sent_ok'] ?><?= (int)$l['sent_fail'] ? ' / błędy ' . (int)$l['sent_fail'] : '' ?></td></tr><?php endforeach; ?></tbody>
</table></div></section>
<?php endif; ?>
</div>
</main>
<script>
(function () {
  function sync() { var m = (document.querySelector('.kc-mode:checked') || {}).value; document.querySelectorAll('.kc-pane').forEach(function (p) { p.hidden = p.dataset.mode !== m; }); }
  document.querySelectorAll('.kc-mode').forEach(function (r) { r.addEventListener('change', sync); }); sync();
  document.querySelectorAll('.kc-tpl').forEach(function (b) { b.addEventListener('click', function () { document.getElementById('kc-sub').value = b.dataset.s; document.getElementById('kc-b').value = b.dataset.b; }); });
})();
</script>
<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
