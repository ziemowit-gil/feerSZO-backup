<?php
/**
 * karty30/ti/dydaktyk/subkonto.php — Wpłata na subkonto kursanta + wyciąg z subkonta (kierownik).
 *
 * Zakładka „Wpłata”: kursant wpłacił X zł na swoje subkonto (rachunek wirtualny) — podajesz datę i kwotę,
 * system pokazuje podgląd: wszystkie grupy kursanta i miesiące od wpłaty (rozliczenia wystawione i zajęcia
 * jeszcze bez rozliczenia) z propozycją rozdziału FIFO. Można wybrać grupy, ręcznie dopasować kwotę na
 * miesiąc i poprawić należność z powodem („Przypisz i popraw rozliczenia”). Zatwierdzenie wystawia brakujące
 * rozliczenia, zapisuje korekty, księguje wpłatę i wysyła kursantowi tekstowy e-mail o korektach.
 * Zakładka „Wyciąg”: wpłaty i należności z saldem narastającym, do druku.
 * Tylko dla kursantów z nadanym subkontem. Logika: modules/ti_subaccount/logic/subaccountManager.php.
 */
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__, 3) . '/modules/ti_subaccount/logic/subaccountManager.php';

$me = dyd_require();
if (!dyd_is_staff()) { header('Location: index.php'); exit; }
$dyd_name = (string)($me['name'] ?? '') ?: 'Kierownik';
$SELF = 'subkonto.php';

$clientId = (int)($_REQUEST['client'] ?? 0);
$tab      = ($_GET['tab'] ?? '') === 'wyciag' ? 'wyciag' : 'wplata';
$date     = (string)($_REQUEST['date'] ?? date('Y-m-d'));
$amountIn = (string)($_REQUEST['amount'] ?? '');
$amount   = tiSubNum($amountIn);
$errors   = []; $posted = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_op'] ?? '') === 'save_pay') {
    dyd_token_check();
    $res = tiSubPaySave($clientId, !empty($_POST['enabled']), (array)($_POST['groups'] ?? []), $dyd_name);
    if ($res['ok']) {
        flash_set('success', 'Zapisano opcję „Płatne z subkonta”.' . (!empty($res['turned_on'])
            ? ($res['mailed'] ? ' Wysłano informację e-mailem (' . $res['mailed'] . ' ' . ($res['mailed'] === 1 ? 'adresat' : 'adresatów') . ').' : ' Brak adresu e-mail — kursant nie dostał informacji.')
            : ''));
    } else {
        flash_set('danger', $res['error']);
    }
    header('Location: ' . $SELF . '?client=' . $clientId . '&tab=' . $tab); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_op'] ?? '') === 'apply') {
    dyd_token_check();
    $res = tiSubApply($clientId, $_POST, $dyd_name);
    if ($res['ok']) {
        flash_set('success', 'Zaksięgowano wpłatę ' . number_format((float)$amount, 2, ',', ' ') . ' zł: przypisano '
            . number_format($res['assigned'], 2, ',', ' ') . ' zł'
            . ($res['leftover'] > 0.005 ? ', nadpłata ' . number_format($res['leftover'], 2, ',', ' ') . ' zł' : '')
            . ($res['issued'] ? ', wystawiono rozliczeń: ' . $res['issued'] : '')
            . ($res['corrected'] ? ', korekt: ' . $res['corrected'] . ($res['emailed'] ? ' (wysłano e-mail do kursanta)' : ' (brak adresu e-mail — kursant nie dostał informacji)') : '') . '.');
        header('Location: ' . $SELF . '?client=' . $clientId . '&tab=wyciag'); exit;
    }
    $errors = $res['errors']; $posted = (array)($_POST['rows'] ?? []);
}

$clients = tiSubClients();
$cname   = '';
foreach ($clients as $c) if ((int)$c['id'] === $clientId) $cname = (string)$c['name'];
$nrb     = $clientId ? tiSubNrb($clientId) : null;
$plan    = ($clientId && $nrb && $tab === 'wplata' && $amount !== null && $amount > 0) ? tiSubPlan($clientId, $date, $amount) : null;
$stmt    = ($clientId && $nrb && $tab === 'wyciag') ? tiSubStatement($clientId, (string)($_GET['from'] ?? ''), (string)($_GET['to'] ?? '')) : null;
$zl      = fn(float $x): string => number_format($x, 2, ',', ' ') . ' zł';
$num     = fn(float $x): string => number_format($x, 2, ',', '');

$KP_TITLE  = 'Subkonto kursanta — Panel dydaktyka';
$KP_TOPBAR = ['brand' => 'Panel dydaktyka', 'icon' => 'easel2', 'user' => $dyd_name, 'logout' => 'logout.php'];
$KP_BODY_CLASS = 'dyd-usos ti-skin';
include dirname(__DIR__) . '/kursant/_layout_head.php';
$_skin_css = __DIR__ . '/../assets/ti_skin.css';
?>
<link rel="stylesheet" href="../assets/ti_skin.css?v=<?= is_file($_skin_css) ? (int)filemtime($_skin_css) : 1 ?>">
<style>
  .sk-table td, .sk-table th { vertical-align: middle; }
  .sk-table input.form-control { min-width: 6.5rem; }
  .sk-grp th { background: #eef2f7; }
  .sk-sum { position: sticky; bottom: 0; background: #fff; border-top: 2px solid #adb5bd; padding: .6rem 1rem; z-index: 5; }
  .sk-bad { color: #58151c; font-weight: 700; }
  .badge.sk-b { font-weight: 700; border: 1px solid currentColor; }
  .sk-new  { background: #fff3cd; color: #664d03; }
  .sk-old  { background: #f8d7da; color: #58151c; }
  .sk-iss  { background: #e2e3e5; color: #2b2f32; }
  @media print {
    .no-print, .dyd-topbar, header, nav, .kier-bar { display: none !important; }
    .sk-print-head { display: block !important; }
    body { background: #fff !important; }
  }
  .sk-print-head { display: none; }
</style>

<?php $KIER_CUR = $SELF; $KIER_LABEL = 'Subkonto kursanta';
   include __DIR__ . '/_kierownik_bar.php'; ?>

<main id="main" class="dyd-wrap">
<div class="container-fluid py-3" style="max-width:1280px">

<div class="d-flex align-items-center mb-3 gap-2 flex-wrap no-print">
  <h1 class="h4 mb-0 fw-bold"><i class="bi bi-bank text-primary me-2" aria-hidden="true"></i>Subkonto kursanta</h1>
  <span class="text-body-secondary small">wpłata na rachunek wirtualny kursanta — rozdział na grupy i miesiące, korekty, wyciąg</span>
</div>

<?= flash_html() ?>

<?php if ($errors): ?>
<div class="alert alert-danger" role="alert" id="sk-errors" tabindex="-1">
  <strong>Nie zaksięgowano — popraw dane:</strong>
  <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
</div>
<script>document.getElementById('sk-errors').focus();</script>
<?php endif; ?>

<form method="get" class="card card-body mb-3 no-print">
  <div class="row g-2 align-items-end">
    <div class="col-md-5">
      <label for="sk-client" class="form-label small mb-1">Kursant z subkontem</label>
      <select id="sk-client" name="client" class="form-select form-select-sm" onchange="this.form.submit()" required>
        <option value="">— wybierz kursanta —</option>
        <?php foreach ($clients as $c): ?>
        <option value="<?= (int)$c['id'] ?>"<?= (int)$c['id'] === $clientId ? ' selected' : '' ?>><?= h($c['name']) ?> · <?= h(pp_nrb_format((string)$c['nrb'])) ?></option>
        <?php endforeach; ?>
      </select>
      <?php if (!$clients): ?><div class="form-text">Żaden kursant nie ma jeszcze nadanego subkonta (Portal płatności → rachunki wirtualne).</div><?php endif; ?>
    </div>
    <input type="hidden" name="tab" value="<?= h($tab) ?>">
    <?php if ($tab === 'wplata'): ?>
    <div class="col-6 col-md-2">
      <label for="sk-date" class="form-label small mb-1">Data wpłaty</label>
      <input type="date" id="sk-date" name="date" class="form-control form-control-sm" value="<?= h($date) ?>" max="<?= date('Y-m-d') ?>" required>
    </div>
    <div class="col-6 col-md-2">
      <label for="sk-amount" class="form-label small mb-1">Kwota wpłaty (zł)</label>
      <input type="text" inputmode="decimal" id="sk-amount" name="amount" class="form-control form-control-sm" value="<?= h($amountIn) ?>" placeholder="np. 450,00" required>
    </div>
    <div class="col-md-3">
      <button class="btn btn-primary btn-sm"><i class="bi bi-eye me-1" aria-hidden="true"></i>Pokaż podgląd rozliczenia</button>
    </div>
    <?php else: ?>
    <div class="col-6 col-md-2">
      <label for="sk-from" class="form-label small mb-1">Od</label>
      <input type="date" id="sk-from" name="from" class="form-control form-control-sm" value="<?= h((string)($_GET['from'] ?? '')) ?>">
    </div>
    <div class="col-6 col-md-2">
      <label for="sk-to" class="form-label small mb-1">Do</label>
      <input type="date" id="sk-to" name="to" class="form-control form-control-sm" value="<?= h((string)($_GET['to'] ?? '')) ?>">
    </div>
    <div class="col-md-3 d-flex gap-2">
      <button class="btn btn-primary btn-sm"><i class="bi bi-funnel me-1" aria-hidden="true"></i>Pokaż</button>
      <?php if ($stmt): ?><button type="button" class="btn btn-outline-secondary btn-sm" onclick="window.print()"><i class="bi bi-printer me-1" aria-hidden="true"></i>Drukuj / PDF</button><?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</form>

<?php if ($clientId): ?>
<ul class="nav nav-tabs mb-3 no-print">
  <li class="nav-item"><a class="nav-link<?= $tab === 'wplata' ? ' active' : '' ?>"<?= $tab === 'wplata' ? ' aria-current="page"' : '' ?> href="<?= $SELF ?>?client=<?= $clientId ?>">Wpłata na subkonto</a></li>
  <li class="nav-item"><a class="nav-link<?= $tab === 'wyciag' ? ' active' : '' ?>"<?= $tab === 'wyciag' ? ' aria-current="page"' : '' ?> href="<?= $SELF ?>?client=<?= $clientId ?>&tab=wyciag">Wyciąg z subkonta</a></li>
</ul>
<?php if (!$nrb): ?>
<div class="alert alert-warning">Kursant nie ma nadanego subkonta (rachunku wirtualnego) — wpłata na subkonto i wyciąg są niedostępne.</div>
<?php else: $sp = tiSubPayGet($clientId); $spc = tiSubPayCourses($clientId); ?>
<form method="post" class="card card-body mb-3 no-print" aria-labelledby="sk-pay-h"
      onsubmit="return !this.enabled.checked || <?= $sp['enabled'] ? 'true' : 'false' ?> || confirm('Włączyć „Płatne z subkonta”?\n\nKursant (i opiekun, jeśli ma adres) dostanie e-mail z informacją o nowej formie płatności.')">
  <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
  <input type="hidden" name="_op" value="save_pay">
  <input type="hidden" name="client" value="<?= $clientId ?>">
  <div class="d-flex flex-wrap align-items-center gap-3">
    <h2 class="h6 mb-0" id="sk-pay-h"><i class="bi bi-wallet2 me-1" aria-hidden="true"></i>Płatne z subkonta</h2>
    <div class="form-check form-switch mb-0">
      <input class="form-check-input" type="checkbox" role="switch" id="sk-pay-on" name="enabled" value="1"<?= $sp['enabled'] ? ' checked' : '' ?>>
      <label class="form-check-label" for="sk-pay-on"><?= $sp['enabled'] ? 'włączone' : 'wyłączone' ?></label>
    </div>
    <?php if ($sp['enabled'] && $sp['enabled_at']): ?><span class="small text-body-secondary">od <?= h(date('d.m.Y', strtotime((string)$sp['enabled_at']))) ?><?= $sp['notified_at'] ? ' · e-mail wysłany ' . h(date('d.m.Y', strtotime((string)$sp['notified_at']))) : '' ?></span><?php endif; ?>
  </div>
  <fieldset class="mt-2">
    <legend class="small mb-1">Grupy, za które opłaty pobierają się z subkonta</legend>
    <?php if (!$spc): ?><div class="small text-body-secondary">Kursant nie ma aktywnych zapisów do grup.</div><?php endif; ?>
    <div class="d-flex flex-wrap gap-3">
      <?php foreach ($spc as $gid => $gname): ?>
      <label class="form-check mb-0"><input type="checkbox" class="form-check-input" name="groups[]" value="<?= (int)$gid ?>"<?= in_array($gid, $sp['groups'], true) ? ' checked' : '' ?>>
        <span class="form-check-label"><?= h($gname) ?></span></label>
      <?php endforeach; ?>
    </div>
    <div class="form-text">Wpływy na subkonto pokrywają wtedy tylko te grupy (od najstarszej należności); niewykorzystana kwota zostaje na subkoncie. Po włączeniu kursant i opiekun dostają e-mail (szablon „TI: włączono Płatne z subkonta”).</div>
  </fieldset>
  <div class="mt-2"><button class="btn btn-sm btn-primary"><i class="bi bi-save me-1" aria-hidden="true"></i>Zapisz</button></div>
</form>
<?php endif; ?>
<?php endif; ?>

<?php if ($plan): ?>
<form method="post" id="sk-form"
      onsubmit="return skConfirm(this)">
  <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
  <input type="hidden" name="_op" value="apply">
  <input type="hidden" name="client" value="<?= $clientId ?>">
  <input type="hidden" name="date" value="<?= h($plan['date']) ?>">
  <input type="hidden" name="amount" value="<?= h($num($plan['amount'])) ?>">

  <div class="alert alert-info small">
    <strong><?= h($cname) ?></strong> · subkonto <span style="font-family:var(--bs-font-monospace)"><?= h($plan['nrb']) ?></span>
    · wpłata <strong><?= $zl($plan['amount']) ?></strong> z <?= h(date('d.m.Y', strtotime($plan['date']))) ?>.
    Poniżej grupy i miesiące od <?= h(tiSubMonthLabel((int)date('n', strtotime($plan['date'])), (int)date('Y', strtotime($plan['date'])))) ?>:
    rozliczenia wystawione i zajęcia jeszcze bez rozliczenia (zostaną wystawione — bez powiadomienia „do zapłaty”).
    Wcześniejsze zaległości są na liście, ale domyślnie niezaznaczone. Nieprzypisana reszta trafi na konto jako nadpłata.
  </div>

  <?php if (!$plan['rows']): ?>
  <div class="alert alert-secondary">Brak rozliczeń i zrealizowanych zajęć od daty wpłaty — cała kwota zostanie zaksięgowana jako nadpłata.</div>
  <?php else: ?>
  <div class="d-flex gap-2 flex-wrap mb-2 no-print" role="group" aria-label="Wybór grup">
    <span class="small text-body-secondary align-self-center">Pokryj grupy:</span>
    <?php foreach ($plan['courses'] as $cid => $cn): ?>
    <label class="btn btn-sm btn-outline-secondary mb-0">
      <input type="checkbox" class="form-check-input me-1 sk-grp-toggle" data-course="<?= (int)$cid ?>" checked> <?= h($cn) ?>
    </label>
    <?php endforeach; ?>
    <button type="button" class="btn btn-sm btn-outline-primary ms-auto" id="sk-fifo"><i class="bi bi-magic me-1" aria-hidden="true"></i>Rozdziel automatycznie (FIFO)</button>
  </div>

  <div class="table-responsive">
  <table class="table table-sm sk-table align-middle">
    <caption class="visually-hidden">Rozdział wpłaty na grupy i miesiące</caption>
    <thead class="table-light">
      <tr>
        <th scope="col"><span class="visually-hidden">Uwzględnij</span></th>
        <th scope="col">Grupa · miesiąc</th>
        <th scope="col">Stan</th>
        <th scope="col" class="text-end">Należność</th>
        <th scope="col" class="text-end">Pokryte</th>
        <th scope="col" class="text-end">Pozostało</th>
        <th scope="col">Przypisz (zł)</th>
        <th scope="col">Należność po korekcie (zł)</th>
        <th scope="col">Powód korekty</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($plan['rows'] as $r):
      $k  = $r['key']; $pv = $posted[$k] ?? null;
      $on = $pv !== null ? !empty($pv['on']) : $r['selected'];
      $label = tiSubCourseName($r['course_id']) . ', ' . tiSubMonthLabel($r['month'], $r['year']);
    ?>
      <tr class="sk-row" data-course="<?= (int)$r['course_id'] ?>" data-rem="<?= h((string)$r['remaining']) ?>" data-paid="<?= h((string)$r['paid']) ?>" data-due="<?= h((string)$r['due']) ?>">
        <td><input type="checkbox" class="form-check-input sk-on" name="rows[<?= h($k) ?>][on]" value="1" id="sk-on-<?= h($k) ?>"<?= $on ? ' checked' : '' ?> aria-label="Uwzględnij: <?= h($label) ?>"></td>
        <td><label for="sk-on-<?= h($k) ?>" class="mb-0"><span class="fw-semibold"><?= h(tiSubCourseName($r['course_id'])) ?></span><br><span class="small"><?= h(tiSubMonthLabel($r['month'], $r['year'])) ?></span></label></td>
        <td>
          <?php if ($r['to_issue']): ?><span class="badge sk-b sk-new"><i class="bi bi-plus-circle me-1" aria-hidden="true"></i>do wystawienia<?= isset($r['hours']) ? ' · ' . h(rtrim(rtrim(number_format($r['hours'], 2, ',', ''), '0'), ',')) . ' h' : '' ?></span>
          <?php elseif ($r['earlier']): ?><span class="badge sk-b sk-old"><i class="bi bi-clock-history me-1" aria-hidden="true"></i>zaległość wcześniejsza</span>
          <?php else: ?><span class="badge sk-b sk-iss"><i class="bi bi-receipt me-1" aria-hidden="true"></i>wystawione</span><?php endif; ?>
          <?php if ($r['locked']): ?><div class="small text-body-secondary" title="Faktura albo przeniesiona niedopłata — korekta tylko w Rozliczeniach kursantów"><i class="bi bi-lock" aria-hidden="true"></i> bez korekty</div><?php endif; ?>
        </td>
        <td class="text-end"><?= $zl($r['due']) ?></td>
        <td class="text-end"><?= $zl($r['paid']) ?></td>
        <td class="text-end fw-semibold"><?= $zl($r['remaining']) ?></td>
        <td><input type="text" inputmode="decimal" class="form-control form-control-sm sk-assign" name="rows[<?= h($k) ?>][assign]"
                   value="<?= h($pv !== null ? (string)($pv['assign'] ?? '') : $num($r['assign'])) ?>" aria-label="Przypisz z wpłaty: <?= h($label) ?>"></td>
        <td><input type="text" inputmode="decimal" class="form-control form-control-sm sk-due" name="rows[<?= h($k) ?>][due]"
                   value="<?= h((string)($pv['due'] ?? '')) ?>" placeholder="bez zmian"<?= $r['locked'] ? ' disabled' : '' ?> aria-label="Należność po korekcie: <?= h($label) ?>"></td>
        <td><input type="text" class="form-control form-control-sm" name="rows[<?= h($k) ?>][reason]" maxlength="500"
                   value="<?= h((string)($pv['reason'] ?? '')) ?>" placeholder="wymagany przy korekcie"<?= $r['locked'] ? ' disabled' : '' ?> aria-label="Powód korekty: <?= h($label) ?>"></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>

  <div class="sk-sum d-flex flex-wrap gap-3 align-items-center" aria-live="polite">
    <span>Wpłata: <strong><?= $zl($plan['amount']) ?></strong></span>
    <span>Przypisano: <strong id="sk-assigned">—</strong></span>
    <span>Nadpłata (reszta na koncie): <strong id="sk-left">—</strong></span>
    <span id="sk-warn" class="sk-bad"></span>
    <button class="btn btn-success btn-sm ms-auto" id="sk-submit"><i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Przypisz i popraw rozliczenia</button>
  </div>
</form>
<script>
(function () {
  var AMOUNT = <?= json_encode($plan['amount']) ?>;
  var form = document.getElementById('sk-form');
  function n(v) { v = String(v || '').replace(/\s| /g, '').replace(',', '.'); var x = parseFloat(v); return isNaN(x) ? 0 : x; }
  function f(x) { return x.toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, ' ') + ' zł'; }
  function rows() { return Array.prototype.slice.call(form.querySelectorAll('tr.sk-row')); }
  function cap(r) {   // ile można przypisać: pozostało, albo nowa należność − pokryte
    var d = r.querySelector('.sk-due'); var nd = d && d.value.trim() !== '' ? n(d.value) : null;
    return Math.max(0, nd === null ? n(r.dataset.rem) : nd - n(r.dataset.paid));
  }
  function recalc() {
    var s = 0, over = false;
    rows().forEach(function (r) {
      var on = r.querySelector('.sk-on').checked, a = r.querySelector('.sk-assign');
      a.disabled = !on;
      if (on) { var v = n(a.value); s += v; if (v > cap(r) + 0.005) over = true; }
    });
    s = Math.round(s * 100) / 100;
    document.getElementById('sk-assigned').textContent = f(s);
    document.getElementById('sk-left').textContent = f(Math.max(0, AMOUNT - s));
    var w = s > AMOUNT + 0.005 ? 'Suma przypisań przekracza kwotę wpłaty.' : (over ? 'W którejś pozycji przypisano więcej niż pozostało do zapłaty.' : '');
    document.getElementById('sk-warn').textContent = w;
    document.getElementById('sk-submit').disabled = w !== '';
  }
  function fifo() {
    var left = AMOUNT;
    rows().forEach(function (r) {
      var a = r.querySelector('.sk-assign');
      if (!r.querySelector('.sk-on').checked) { a.value = ''; return; }
      var t = Math.min(left, cap(r)); t = Math.round(t * 100) / 100;
      a.value = t.toFixed(2).replace('.', ','); left = Math.round((left - t) * 100) / 100;
    });
    recalc();
  }
  form.addEventListener('input', recalc);
  form.addEventListener('change', recalc);
  var fb = document.getElementById('sk-fifo'); if (fb) fb.addEventListener('click', fifo);
  Array.prototype.forEach.call(document.querySelectorAll('.sk-grp-toggle'), function (g) {
    g.addEventListener('change', function () {
      rows().forEach(function (r) { if (r.dataset.course === g.dataset.course) r.querySelector('.sk-on').checked = g.checked; });
      fifo();
    });
  });
  window.skConfirm = function () {
    var corr = rows().filter(function (r) { var d = r.querySelector('.sk-due'); return r.querySelector('.sk-on').checked && d && d.value.trim() !== ''; }).length;
    return confirm('Zaksięgować wpłatę ' + f(AMOUNT) + ' na subkonto?\n\nPrzypisano: ' + document.getElementById('sk-assigned').textContent
      + '\nNadpłata: ' + document.getElementById('sk-left').textContent
      + (corr ? '\nKorekty należności: ' + corr + ' — kursant dostanie e-mail z powodem.' : '')
      + '\n\nZaznaczone zajęcia bez rozliczenia zostaną wystawione.');
  };
  recalc();
})();
</script>
<?php endif; ?>

<?php if ($stmt): ?>
<section aria-labelledby="sk-st-h">
  <div class="sk-print-head mb-2">
    <strong>Wyciąg z subkonta</strong> — <?= h($stmt['client']) ?> · <?= h((string)$stmt['nrb']) ?> · wygenerowano <?= date('d.m.Y H:i') ?>
  </div>
  <h2 class="h5" id="sk-st-h">Wyciąg z subkonta — <?= h($stmt['client']) ?> <span class="text-body-secondary small" style="font-family:var(--bs-font-monospace)"><?= h((string)$stmt['nrb']) ?></span></h2>
  <div class="table-responsive">
  <table class="table table-sm align-middle">
    <caption class="visually-hidden">Wpłaty i należności z saldem narastającym</caption>
    <thead class="table-light"><tr><th scope="col">Data</th><th scope="col">Operacja</th><th scope="col">Szczegóły</th>
      <th scope="col" class="text-end">Wpłata</th><th scope="col" class="text-end">Należność</th><th scope="col" class="text-end">Saldo</th></tr></thead>
    <tbody>
      <?php if (abs($stmt['opening']) > 0.005 || !empty($_GET['from'])): ?>
      <tr class="table-light"><td colspan="5">Saldo na początek okresu</td><td class="text-end fw-semibold"><?= $zl($stmt['opening']) ?></td></tr>
      <?php endif; ?>
      <?php foreach ($stmt['lines'] as $l): ?>
      <tr>
        <td class="text-nowrap"><?= h(date('d.m.Y', strtotime($l['date']))) ?></td>
        <td><?= $l['kind'] === 'payment' ? '<i class="bi bi-arrow-down-circle text-success me-1" aria-hidden="true"></i>' : '<i class="bi bi-receipt text-body-secondary me-1" aria-hidden="true"></i>' ?><?= h($l['desc']) ?></td>
        <td class="small"><?= h($l['what']) ?></td>
        <td class="text-end"><?= $l['in'] > 0 ? $zl($l['in']) : '' ?></td>
        <td class="text-end"><?= $l['out'] != 0 ? $zl($l['out']) : '' ?></td>
        <td class="text-end fw-semibold<?= $l['balance'] < -0.005 ? ' text-danger' : '' ?>"><?= $zl($l['balance']) ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$stmt['lines']): ?><tr><td colspan="6" class="text-center text-body-secondary py-3">Brak operacji w wybranym okresie.</td></tr><?php endif; ?>
    </tbody>
    <tfoot class="table-light"><tr><th scope="row" colspan="3">Razem w okresie</th><td class="text-end fw-semibold"><?= $zl($stmt['payments']) ?></td><td class="text-end fw-semibold"><?= $zl($stmt['charges']) ?></td><td></td></tr></tfoot>
  </table>
  </div>
  <h3 class="h6 mt-3">Saldo według grup (model kombinowany)</h3>
  <table class="table table-sm w-auto">
    <thead class="table-light"><tr><th scope="col">Grupa</th><th scope="col" class="text-end">Należności</th><th scope="col" class="text-end">Pokryte</th><th scope="col" class="text-end">Niedopłata</th><th scope="col" class="text-end">Nadpłata grupy</th></tr></thead>
    <tbody>
      <?php foreach ($stmt['balance']['groups'] as $g): ?>
      <tr><td><?= h($g['course_name']) ?></td><td class="text-end"><?= $zl($g['charges']) ?></td><td class="text-end"><?= $zl($g['paid']) ?></td>
          <td class="text-end<?= $g['debt'] > 0.005 ? ' text-danger fw-semibold' : '' ?>"><?= $zl($g['debt']) ?></td><td class="text-end"><?= $zl($g['credit']) ?></td></tr>
      <?php endforeach; ?>
      <tr class="table-light"><td>Nadpłata ogólna (na dowolną grupę)</td><td colspan="3"></td><td class="text-end fw-semibold"><?= $zl($stmt['balance']['general_credit']) ?></td></tr>
      <?php if (($stmt['balance']['sub_credit'] ?? 0) > 0.005): ?>
      <tr class="table-light"><td>Środki na subkoncie (na grupy „Płatne z subkonta”)</td><td colspan="3"></td><td class="text-end fw-semibold"><?= $zl($stmt['balance']['sub_credit']) ?></td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</section>
<?php endif; ?>

</div>
</main>
<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
