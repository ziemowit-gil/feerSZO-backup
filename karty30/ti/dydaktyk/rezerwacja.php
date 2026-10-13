<?php
/**
 * karty30/ti/dydaktyk/rezerwacja.php — jedna rezerwacja terminu konsultacji (przeniesione z karty30/schedules/
 * add.php, edit.php, view.php, quick.php).
 *
 *   ?nowa=1[&client_id=&from_waiting=&billing_type=&pfron_contract_id=] — pełny formularz: beneficjent, konsultant, termin,
 *       sala (wspólna z Systemem Rezerwacji Sal) albo zdalnie, rozliczenie bezpłatne / odpłatne / PFRON (wycena na żywo),
 *       seria (powtarzanie), faktura (CRM + powiadomienie administratorów);
 *   ?szybka=1 — wolne sloty na 7 dni roboczych (per konsultant) i zapis jednym kliknięciem;
 *   ?id=X — podgląd: szybka zmiana statusu (z wpisem w CRM), odwołanie przyszłych terminów serii, karta konsultacji
 *       do terminu, wydruk; ?id=X&edytuj=1 — edycja; ?wycena=1 (GET, JSON) — wycena podglądowa do formularza.
 */
require __DIR__ . '/_rezerwacje_nav.php';
TiBookings::srs();

// Wycena podglądowa (fetch z formularza)
if (isset($_GET['wycena'])) {
    header('Content-Type: application/json; charset=utf-8');
    $q = TiBookings::quote((int)($_GET['client_id'] ?? 0), (int)($_GET['minutes'] ?? 0), (string)($_GET['billing_type'] ?? 'free'), (int)($_GET['pfron'] ?? 0) ?: null);
    echo json_encode(['amount' => number_format((float)$q['amount_due'], 2, ',', ' '), 'free' => $q['free_hours'], 'charged' => $q['charged_hours'], 'note' => $q['pricing_note']], JSON_UNESCAPED_UNICODE);
    exit;
}

$id    = (int)($_GET['id'] ?? 0);
$quick = !empty($_GET['szybka']);
$row   = $id ? TiBookings::get($id) : null;
if ($id && !$row) { flash_set('danger', 'Nie znaleziono rezerwacji.'); header('Location: rezerwacje.php'); exit; }
$form  = !$id && !$quick || ($row && !empty($_GET['edytuj']));
$errors = []; $warnings = [];

$collect = fn() => [
    'client_id' => (int)($_POST['client_id'] ?? 0), 'assigned_to' => (int)($_POST['assigned_to'] ?? 0),
    'date' => (string)($_POST['date'] ?? ''), 'time' => (string)($_POST['time'] ?? ''), 'duration' => (int)($_POST['duration'] ?? 60),
    'status' => (string)($_POST['status'] ?? 'preliminary'), 'description' => (string)($_POST['description'] ?? ''),
    'location_type' => (string)($_POST['location_type'] ?? 'remote'), 'resource_id' => (int)($_POST['resource_id'] ?? 0),
    'billing_type' => (string)($_POST['billing_type'] ?? 'free'), 'pfron_contract_id' => (int)($_POST['pfron_contract_id'] ?? 0),
    'needs_invoice' => !empty($_POST['needs_invoice']), 'invoice_type' => (string)($_POST['invoice_type'] ?? 'company'),
    'invoice_name' => (string)($_POST['invoice_name'] ?? ''), 'invoice_nip' => (string)($_POST['invoice_nip'] ?? ''),
    'invoice_address' => (string)($_POST['invoice_address'] ?? ''), 'invoice_email' => (string)($_POST['invoice_email'] ?? ''),
    'recurring' => !empty($_POST['recurring']), 'recur_freq' => (string)($_POST['recur_freq'] ?? 'weekly'), 'recur_days' => (array)($_POST['recur_days'] ?? []),
    'recur_count' => (int)($_POST['recur_count'] ?? 4), 'recur_until' => (string)($_POST['recur_until'] ?? ''), 'recur_end_by' => (string)($_POST['recur_end_by'] ?? 'count'),
    'from_waiting' => (int)($_POST['from_waiting'] ?? 0),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    dyd_token_check();
    $op = (string)($_POST['_op'] ?? '');
    if ($op === 'create' || $op === 'quick') {
        $d = $collect();
        if ($op === 'quick') {
            $slot = (string)($_POST['slot'] ?? '');
            $d = array_merge($d, ['date' => substr($slot, 0, 10), 'time' => substr($slot, 11, 5), 'status' => 'confirmed', 'billing_type' => 'free']);
        }
        $res = TiBookings::create($d, $uid, $byName);
        if ($res['ok']) {
            flash_set('success', (count($res['ids']) > 1 ? 'Utworzono serię ' . count($res['ids']) . ' terminów.' : 'Rezerwacja zapisana.')
                . ($d['needs_invoice'] ? ' Prośba o fakturę przekazana administratorom.' : '') . ($res['warnings'] ? ' Uwaga: ' . implode(' ', $res['warnings']) : ''));
            header('Location: rezerwacja.php?id=' . $res['ids'][0] . (count($res['ids']) === 1 ? '&druk=1' : '')); exit;
        }
        $errors = $res['errors']; $warnings = $res['warnings']; $form = $op === 'create'; $quick = $op === 'quick';
    } elseif ($op === 'update' && $row) {
        $res = TiBookings::update($id, $collect(), $uid, $byName);
        if ($res['ok']) { flash_set('success', 'Zmiany zapisane.' . ($res['warnings'] ? ' Uwaga: ' . implode(' ', $res['warnings']) : '')); header('Location: rezerwacja.php?id=' . $id); exit; }
        $errors = $res['errors']; $form = true;
    } elseif ($op === 'status' && $row) {
        $ok = TiBookings::setStatus($id, (string)($_POST['status'] ?? ''), trim((string)($_POST['cancel_reason'] ?? '')), $uid);
        flash_set($ok ? 'success' : 'danger', $ok ? 'Status zmieniony.' : 'Nieznany status.');
        header('Location: rezerwacja.php?id=' . $id); exit;
    } elseif ($op === 'cancel_series' && $row) {
        $n = TiBookings::cancelSeries($id, trim((string)($_POST['cancel_reason'] ?? '')));
        flash_set('success', 'Odwołano przyszłe terminy serii: ' . $n . '.');
        header('Location: rezerwacja.php?id=' . $id); exit;
    }
}

if ($row && $_SERVER['REQUEST_METHOD'] === 'GET' && !$form) { try { k30_log_access('schedule', $id, 'view', (string)$row['client_name']); } catch (\Throwable $e) {} }

$clients     = db_all("SELECT id, name, status FROM k30_clients ORDER BY name");
$consultants = k30_get_consultants();
$resources   = res_k30_available();
$pfronOn     = k30_pfron_enabled();
$pfronMap    = [];
if ($pfronOn) foreach (db_all("SELECT id, client_id, contract_number, hours_limit, hours_used FROM k30_pfron_contracts WHERE status='active'") as $p)
    $pfronMap[(int)$p['client_id']][] = ['id' => (int)$p['id'], 'label' => $p['contract_number'] . ' (zostało ' . rtrim(rtrim(number_format(max(0, (float)$p['hours_limit'] - (float)$p['hours_used']), 2, ',', ''), '0'), ',') . ' h)'];
$P = $_POST ?: [];
$val = function (string $k, $def = '') use ($P, $row) {
    if (array_key_exists($k, $P)) return $P[$k];
    if ($row) {
        return match ($k) {
            'date' => substr((string)$row['start_time'], 0, 10), 'time' => substr((string)$row['start_time'], 11, 5), 'duration' => (int)$row['duration_minutes'],
            'location_type' => (int)$row['is_remote'] || !(int)$row['resource_id'] ? 'remote' : 'resource', default => $row[$k] ?? $def,
        };
    }
    return $_GET[$k] ?? $def;
};

$RZ_TITLE = $form ? ($row ? 'Edycja rezerwacji' : 'Nowa rezerwacja') : ($quick ? 'Szybka rezerwacja' : 'Rezerwacja');
include __DIR__ . '/_rezerwacje_head.php';
?>
<main id="main" class="dyd-wrap">
<div class="container-fluid py-3" style="max-width:1000px">
<h1 class="h4 fw-bold mb-2"><i class="bi bi-calendar-check text-primary me-2" aria-hidden="true"></i><?= h($RZ_TITLE) ?></h1>
<?php rz_nav($form && !$row ? 'nowa' : ($quick ? 'szybka' : '')); ?>
<?= flash_html() ?>
<?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

<?php if ($quick): $qc = (int)($_GET['konsultant'] ?? ($P['assigned_to'] ?? $uid)); $slots = TiBookings::quickSlots($qc); ?>
<form method="get" class="d-flex gap-2 align-items-end mb-3"><input type="hidden" name="szybka" value="1">
  <div><label class="form-label small mb-0" for="q-c">Konsultant (wolne sloty liczone dla niego)</label>
    <select class="form-select form-select-sm" id="q-c" name="konsultant" onchange="this.form.submit()"><option value="0">wszyscy (sloty globalnie wolne)</option><?php foreach ($consultants as $c): ?><option value="<?= (int)$c['id'] ?>"<?= $qc === (int)$c['id'] ? ' selected' : '' ?>><?= h($c['display_name']) ?></option><?php endforeach; ?></select></div>
</form>
<form method="post" class="card border-0 shadow-sm"><div class="card-body">
  <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>"><input type="hidden" name="_op" value="quick"><input type="hidden" name="assigned_to" value="<?= $qc ?>">
  <fieldset class="mb-3"><legend class="h6 fw-bold">Wolny termin</legend>
    <?php if (!$slots): ?><p class="small text-body-secondary">Brak wolnych slotów w najbliższym tygodniu — użyj pełnego formularza.</p><?php endif; ?>
    <div class="d-flex flex-wrap gap-2">
    <?php foreach ($slots as $i => $sl): ?>
      <input type="radio" class="btn-check" name="slot" id="sl<?= $i ?>" value="<?= h($sl) ?>" required<?= $i === 0 ? ' checked' : '' ?>>
      <label class="btn btn-sm btn-outline-primary" for="sl<?= $i ?>"><?= h(['', 'pn', 'wt', 'śr', 'cz', 'pt'][(int)date('N', strtotime($sl))] ?? '') ?> <?= h(date('d.m H:i', strtotime($sl))) ?></label>
    <?php endforeach; ?>
    </div></fieldset>
  <div class="row g-2 mb-3">
    <div class="col-md-6"><label class="form-label" for="q-cl">Beneficjent</label>
      <select class="form-select" id="q-cl" name="client_id" required><option value="">— wybierz —</option><?php foreach ($clients as $c): if (!in_array($c['status'], ['enrolled', 'learning'], true)) continue; ?><option value="<?= (int)$c['id'] ?>"><?= h($c['name']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-3"><label class="form-label" for="q-d">Czas (min)</label><input class="form-control" type="number" id="q-d" name="duration" min="15" step="15" value="60"></div>
    <div class="col-md-3"><label class="form-label" for="q-l">Miejsce</label>
      <select class="form-select" id="q-l" name="resource_id" onchange="document.getElementById('q-lt').value = this.value === '0' ? 'remote' : 'resource'"><option value="0">zdalnie</option><?php foreach ($resources as $r): ?><option value="<?= (int)$r['id'] ?>"><?= h($r['name']) ?></option><?php endforeach; ?></select>
      <input type="hidden" name="location_type" id="q-lt" value="remote"></div>
  </div>
  <p class="small text-body-secondary">Szybka rezerwacja: status „Potwierdzona”, rozliczenie bezpłatne (wycena i liczniki godzin jak w pełnym formularzu).</p>
  <button class="btn btn-primary"<?= $slots ? '' : ' disabled' ?>><i class="bi bi-lightning me-1" aria-hidden="true"></i>Zarezerwuj</button>
</div></form>

<?php elseif ($form): $op = $row ? 'update' : 'create'; ?>
<form method="post" class="card border-0 shadow-sm" id="rz-form"><div class="card-body">
  <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>"><input type="hidden" name="_op" value="<?= $op ?>">
  <input type="hidden" name="from_waiting" value="<?= (int)$val('from_waiting', 0) ?>">
  <?php if ((int)$val('from_waiting', 0)): ?><div class="alert alert-info small py-2">Rezerwacja z kolejki oczekujących — po zapisie wpis w kolejce zmieni się na „Zaplanowane”.</div><?php endif; ?>
  <div class="row g-3 mb-3">
    <div class="col-md-6"><label class="form-label fw-semibold" for="f-cl">Beneficjent <span class="text-danger" aria-hidden="true">*</span></label>
      <select class="form-select" id="f-cl" name="client_id" required><option value="">— wybierz —</option><?php foreach ($clients as $c): ?><option value="<?= (int)$c['id'] ?>"<?= (int)$val('client_id', 0) === (int)$c['id'] ? ' selected' : '' ?>><?= h($c['name']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-6"><label class="form-label" for="f-as">Konsultant</label>
      <select class="form-select" id="f-as" name="assigned_to"><option value="0">— nie przypisano —</option><?php foreach ($consultants as $c): ?><option value="<?= (int)$c['id'] ?>"<?= (int)$val('assigned_to', $row ? 0 : $uid) === (int)$c['id'] ? ' selected' : '' ?>><?= h($c['display_name']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-4"><label class="form-label fw-semibold" for="f-d">Data <span class="text-danger" aria-hidden="true">*</span></label><input class="form-control" type="date" id="f-d" name="date" required value="<?= h((string)$val('date', date('Y-m-d', strtotime('tomorrow')))) ?>"></div>
    <div class="col-md-4"><label class="form-label fw-semibold" for="f-t">Godzina <span class="text-danger" aria-hidden="true">*</span></label><input class="form-control" type="time" id="f-t" name="time" required value="<?= h((string)$val('time', '10:00')) ?>"></div>
    <div class="col-md-4"><label class="form-label" for="f-du">Czas trwania (min)</label><input class="form-control" type="number" id="f-du" name="duration" min="15" step="15" value="<?= (int)$val('duration', 60) ?>"></div>
    <div class="col-md-6"><label class="form-label" for="f-st">Status</label>
      <select class="form-select" id="f-st" name="status"><?php foreach (K30_SCHEDULE_STATUSES as $k => $v): ?><option value="<?= h($k) ?>"<?= (string)$val('status', 'preliminary') === $k ? ' selected' : '' ?>><?= h($v['label']) ?></option><?php endforeach; ?></select></div>
  </div>

  <fieldset class="mb-3"><legend class="h6 fw-bold">Miejsce</legend>
    <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="location_type" id="f-lr" value="remote"<?= $val('location_type', 'remote') === 'remote' ? ' checked' : '' ?>><label class="form-check-label" for="f-lr">Zdalnie</label></div>
    <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="location_type" id="f-ls" value="resource"<?= $val('location_type') === 'resource' ? ' checked' : '' ?>><label class="form-check-label" for="f-ls">Sala</label></div>
    <select class="form-select mt-2" id="f-res" name="resource_id" aria-label="Sala"><option value="0">— wybierz salę —</option><?php foreach ($resources as $r): ?><option value="<?= (int)$r['id'] ?>"<?= (int)$val('resource_id', 0) === (int)$r['id'] ? ' selected' : '' ?>><?= h(($r['cat_name'] ? $r['cat_name'] . ' — ' : '') . $r['name']) ?></option><?php endforeach; ?></select>
    <div class="form-text">Sale wspólne z Systemem Rezerwacji Sal — zapis sprawdza godziny otwarcia i kolizje w obu rejestrach.</div>
  </fieldset>

  <?php if (!$row): ?>
  <fieldset class="mb-3"><legend class="h6 fw-bold">Rozliczenie</legend>
    <?php foreach (K30_BILLING_TYPES as $k => $v): if ($k === 'pfron' && !$pfronOn) continue; ?>
    <div class="form-check form-check-inline"><input class="form-check-input rz-bt" type="radio" name="billing_type" id="f-bt-<?= h($k) ?>" value="<?= h($k) ?>"<?= (string)$val('billing_type', 'free') === $k ? ' checked' : '' ?>><label class="form-check-label" for="f-bt-<?= h($k) ?>"><?= h($v['label']) ?></label></div>
    <?php endforeach; ?>
    <div id="f-pfron-w" class="mt-2" hidden><label class="form-label small mb-0" for="f-pfron">Umowa PFRON</label><select class="form-select form-select-sm" id="f-pfron" name="pfron_contract_id" data-sel="<?= (int)$val('pfron_contract_id', 0) ?>"></select></div>
    <p class="small mt-2 mb-0" id="f-quote" aria-live="polite"></p>
  </fieldset>

  <fieldset class="mb-3"><legend class="h6 fw-bold">Powtarzanie</legend>
    <div class="form-check"><input class="form-check-input" type="checkbox" id="f-rec" name="recurring" value="1"<?= $val('recurring') ? ' checked' : '' ?>><label class="form-check-label" for="f-rec">Seria terminów</label></div>
    <div id="f-rec-w" class="row g-2 mt-1" hidden>
      <div class="col-md-4"><label class="form-label small mb-0" for="f-rf">Częstotliwość</label><select class="form-select form-select-sm" id="f-rf" name="recur_freq"><?php foreach (TiBookings::RECUR_FREQ as $k => $v): ?><option value="<?= h($k) ?>"<?= (string)$val('recur_freq', 'weekly') === $k ? ' selected' : '' ?>><?= h($v) ?></option><?php endforeach; ?></select></div>
      <div class="col-md-8"><span class="form-label small d-block mb-0">Dni tygodnia (dla tygodniowych)</span>
        <?php foreach ([1 => 'pn', 2 => 'wt', 3 => 'śr', 4 => 'cz', 5 => 'pt', 6 => 'sb', 0 => 'nd'] as $dk => $dl): ?><div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="recur_days[]" value="<?= $dk ?>" id="f-rd<?= $dk ?>"<?= in_array((string)$dk, array_map('strval', (array)$val('recur_days', [])), true) ? ' checked' : '' ?>><label class="form-check-label small" for="f-rd<?= $dk ?>"><?= $dl ?></label></div><?php endforeach; ?></div>
      <div class="col-md-4"><label class="form-label small mb-0" for="f-reb">Koniec</label><select class="form-select form-select-sm" id="f-reb" name="recur_end_by"><option value="count">po liczbie terminów</option><option value="date"<?= $val('recur_end_by') === 'date' ? ' selected' : '' ?>>w dniu</option></select></div>
      <div class="col-md-4"><label class="form-label small mb-0" for="f-rc">Liczba terminów (1–52)</label><input class="form-control form-control-sm" type="number" min="1" max="52" id="f-rc" name="recur_count" value="<?= (int)$val('recur_count', 4) ?>"></div>
      <div class="col-md-4"><label class="form-label small mb-0" for="f-ru">Do dnia</label><input class="form-control form-control-sm" type="date" id="f-ru" name="recur_until" value="<?= h((string)$val('recur_until')) ?>"></div>
    </div>
  </fieldset>

  <fieldset class="mb-3"><legend class="h6 fw-bold">Faktura</legend>
    <div class="form-check"><input class="form-check-input" type="checkbox" id="f-inv" name="needs_invoice" value="1"<?= $val('needs_invoice') ? ' checked' : '' ?>><label class="form-check-label" for="f-inv">Beneficjent prosi o fakturę</label></div>
    <div id="f-inv-w" class="row g-2 mt-1" hidden>
      <div class="col-md-4"><label class="form-label small mb-0" for="f-it">Rodzaj</label><select class="form-select form-select-sm" id="f-it" name="invoice_type"><option value="company">firmowa (z NIP)</option><option value="personal"<?= $val('invoice_type') === 'personal' ? ' selected' : '' ?>>imienna</option></select></div>
      <div class="col-md-4"><label class="form-label small mb-0" for="f-in">NIP</label><input class="form-control form-control-sm" id="f-in" name="invoice_nip" inputmode="numeric" value="<?= h((string)$val('invoice_nip')) ?>"></div>
      <div class="col-md-4 d-flex align-items-end"><button type="button" class="btn btn-sm btn-outline-secondary" id="f-ceidg">Pobierz dane z CEIDG/KRS</button></div>
      <div class="col-md-6"><label class="form-label small mb-0" for="f-iname">Nazwa / imię i nazwisko</label><input class="form-control form-control-sm" id="f-iname" name="invoice_name" value="<?= h((string)$val('invoice_name')) ?>"></div>
      <div class="col-md-6"><label class="form-label small mb-0" for="f-ie">E-mail do faktury</label><input class="form-control form-control-sm" type="email" id="f-ie" name="invoice_email" value="<?= h((string)$val('invoice_email')) ?>"></div>
      <div class="col-12"><label class="form-label small mb-0" for="f-ia">Adres</label><input class="form-control form-control-sm" id="f-ia" name="invoice_address" value="<?= h((string)$val('invoice_address')) ?>"></div>
    </div>
  </fieldset>
  <?php else: ?>
  <p class="small text-body-secondary">Rozliczenie (<?= h(K30_BILLING_TYPES[$row['billing_type']]['label'] ?? '') ?>), seria i faktura nie zmieniają się przy edycji — w razie potrzeby odwołaj termin i zarezerwuj nowy.</p>
  <?php endif; ?>

  <div class="mb-3"><label class="form-label" for="f-desc">Opis / cel konsultacji</label><textarea class="form-control" id="f-desc" name="description" rows="3"><?= h((string)$val('description')) ?></textarea></div>
  <button class="btn btn-primary"><i class="bi bi-check-lg me-1" aria-hidden="true"></i>Zapisz</button>
  <a class="btn btn-outline-secondary" href="<?= $row ? 'rezerwacja.php?id=' . $id : 'rezerwacje.php' ?>">Anuluj</a>
</div></form>
<script>
(function () {
  var $ = function (i) { return document.getElementById(i); }, pf = <?= json_encode($pfronMap, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?>;
  function loc() { var r = $('f-res'); if (r) r.hidden = !$('f-ls').checked; }
  document.querySelectorAll('input[name=location_type]').forEach(function (e) { e.addEventListener('change', loc); }); loc();
  function tog(cb, w) { var c = $(cb), x = $(w); if (!c || !x) return; var f = function () { x.hidden = !c.checked; }; c.addEventListener('change', f); f(); }
  tog('f-rec', 'f-rec-w'); tog('f-inv', 'f-inv-w');
  function bt() { var v = (document.querySelector('.rz-bt:checked') || {}).value, w = $('f-pfron-w'); if (!w) return;
    w.hidden = v !== 'pfron'; var s = $('f-pfron'), cl = $('f-cl').value, list = pf[cl] || [], sel = s.dataset.sel;
    s.innerHTML = list.length ? '' : '<option value="">— brak aktywnej umowy PFRON —</option>';
    list.forEach(function (o) { var op = document.createElement('option'); op.value = o.id; op.textContent = o.label; if (String(o.id) === sel) op.selected = true; s.appendChild(op); }); quote(); }
  function quote() { var q = $('f-quote'); if (!q) return; var cl = $('f-cl').value, v = (document.querySelector('.rz-bt:checked') || {}).value || 'free';
    if (!cl) { q.textContent = ''; return; }
    fetch('rezerwacja.php?wycena=1&client_id=' + cl + '&minutes=' + ($('f-du').value || 0) + '&billing_type=' + v + '&pfron=' + (($('f-pfron') || {}).value || 0))
      .then(function (r) { return r.json(); }).then(function (j) { q.textContent = 'Wycena: ' + j.amount + ' zł' + (j.note ? ' — ' + j.note : ''); }).catch(function () {}); }
  document.querySelectorAll('.rz-bt').forEach(function (e) { e.addEventListener('change', bt); });
  ['f-cl', 'f-du', 'f-pfron'].forEach(function (i) { var e = $(i); if (e) e.addEventListener('change', i === 'f-cl' ? bt : quote); }); bt();
  var cg = $('f-ceidg'); if (cg) cg.addEventListener('click', function () { var nip = ($('f-in').value || '').replace(/\D/g, ''); if (!nip) return;
    fetch(<?= json_encode(rtrim(APP_URL, '/') . '/api/ceidg.php?nip=') ?> + nip).then(function (r) { return r.json(); }).then(function (j) {
      var d = j.data || j; if (d && (d.name || d.nazwa)) { $('f-iname').value = d.name || d.nazwa; if (d.address || d.adres) $('f-ia').value = d.address || d.adres; } }).catch(function () {}); });
})();
</script>

<?php else: $st = $row['status']; $ser = TiBookings::seriesInfo($row); $cards = db_all("SELECT id, card_no, status FROM k30_consultations WHERE schedule_id=? AND status<>'cancelled'", [$id]); ?>
<?php if (!empty($_GET['druk'])): ?><div class="alert alert-success small d-flex flex-wrap gap-2 align-items-center">Rezerwacja zapisana. <a class="btn btn-sm btn-outline-success" href="rezerwacjaPdf.php?id=<?= $id ?>&amp;auto=1" target="_blank" rel="noopener"><i class="bi bi-printer me-1" aria-hidden="true"></i>Drukuj kartę terminu</a></div><?php endif; ?>
<div class="d-flex flex-wrap align-items-start gap-2 mb-3">
  <div>
    <h2 class="h5 fw-bold mb-0"><?= h($row['client_name']) ?></h2>
    <div class="small text-body-secondary"><?= h(date('d.m.Y H:i', strtotime((string)$row['start_time']))) ?> · <?= (int)$row['duration_minutes'] ?> min<?= $ser ? ' · wizyta ' . $ser['index'] . ' z ' . $ser['total'] : '' ?></div>
    <div class="mt-1"><?= rz_badge($st) ?></div>
  </div>
  <div class="ms-auto d-flex flex-wrap gap-2">
    <a class="btn btn-sm btn-outline-secondary" href="rezerwacjaPdf.php?id=<?= $id ?>" target="_blank" rel="noopener"><i class="bi bi-printer me-1" aria-hidden="true"></i>Karta terminu</a>
    <a class="btn btn-sm btn-outline-primary" href="rezerwacja.php?id=<?= $id ?>&amp;edytuj=1"><i class="bi bi-pencil me-1" aria-hidden="true"></i>Edytuj</a>
    <?php if ($cards): foreach ($cards as $k): ?><a class="btn btn-sm btn-outline-success" href="konsultacja.php?id=<?= (int)$k['id'] ?>"><i class="bi bi-clipboard2-check me-1" aria-hidden="true"></i><?= h($k['card_no']) ?></a><?php endforeach;
          elseif (in_array($st, ['confirmed', 'attended'], true)): ?><a class="btn btn-sm btn-primary" href="konsultacja.php?nowa=1&amp;client_id=<?= (int)$row['client_id'] ?>&amp;schedule_id=<?= $id ?>"><i class="bi bi-clipboard2-plus me-1" aria-hidden="true"></i>Wystaw kartę konsultacji</a><?php endif; ?>
  </div>
</div>
<div class="row g-3">
  <div class="col-md-6"><div class="card border-0 shadow-sm"><div class="card-body small"><dl class="row mb-0">
    <dt class="col-5 fw-normal text-body-secondary">Konsultant</dt><dd class="col-7"><?= h($row['consultant_name'] ?: '—') ?></dd>
    <dt class="col-5 fw-normal text-body-secondary">Miejsce</dt><dd class="col-7"><?= (int)$row['is_remote'] || !$row['resource_name'] ? 'zdalnie' : h(($row['resource_cat'] ? $row['resource_cat'] . ' — ' : '') . $row['resource_name']) ?></dd>
    <dt class="col-5 fw-normal text-body-secondary">Rozliczenie</dt><dd class="col-7"><?= h(K30_BILLING_TYPES[$row['billing_type'] ?? 'free']['label'] ?? '') ?><?= $row['pfron_number'] ? ' · umowa ' . h($row['pfron_number']) : '' ?></dd>
    <dt class="col-5 fw-normal text-body-secondary">Godziny</dt><dd class="col-7"><?= h(rtrim(rtrim(number_format((float)$row['billed_hours'], 2, ',', ''), '0'), ',')) ?> h (bezpł. <?= h(rtrim(rtrim(number_format((float)$row['free_hours'], 2, ',', ''), '0'), ',')) ?>, płatne <?= h(rtrim(rtrim(number_format((float)$row['charged_hours'], 2, ',', ''), '0'), ',')) ?>)</dd>
    <dt class="col-5 fw-normal text-body-secondary">Do zapłaty</dt><dd class="col-7"><?= h(number_format((float)$row['amount_due'], 2, ',', ' ')) ?> zł<?= $row['pricing_note'] ? '<div class="text-body-secondary">' . h($row['pricing_note']) . '</div>' : '' ?></dd>
    <?php if ((int)$row['needs_invoice']): ?><dt class="col-5 fw-normal text-body-secondary">Faktura</dt><dd class="col-7"><?= h($row['invoice_name']) ?><?= $row['invoice_nip'] ? ', NIP ' . h($row['invoice_nip']) : '' ?><br><?= h($row['invoice_address']) ?></dd><?php endif; ?>
    <?php if ($row['cancel_reason']): ?><dt class="col-5 fw-normal text-body-secondary">Powód odwołania</dt><dd class="col-7"><?= h($row['cancel_reason']) ?></dd><?php endif; ?>
    <dt class="col-5 fw-normal text-body-secondary">Dodał(a)</dt><dd class="col-7"><?= h($row['created_by_name'] ?: '—') ?></dd>
  </dl><?php if ($row['description']): ?><hr><?= nl2br(h($row['description'])) ?><?php endif; ?></div></div></div>
  <div class="col-md-6">
    <section class="card border-0 shadow-sm mb-3" aria-labelledby="rz-s-h"><div class="card-header fw-semibold" id="rz-s-h">Zmień status</div><div class="card-body">
      <form method="post" class="vstack gap-2">
        <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>"><input type="hidden" name="_op" value="status">
        <div class="d-flex flex-wrap gap-1" role="group" aria-label="Nowy status">
          <?php foreach (['confirmed', 'attended', 'no_show', 'cancelled_by_client', 'cancelled_by_feer', 'cancelled'] as $k): if ($k === $st) continue; ?>
          <button class="btn btn-sm btn-outline-secondary" name="status" value="<?= h($k) ?>"><?= h(K30_SCHEDULE_STATUSES[$k]['label']) ?></button>
          <?php endforeach; ?>
        </div>
        <label class="form-label small mb-0" for="rz-cr">Powód (przy odwołaniu)</label><input class="form-control form-control-sm" id="rz-cr" name="cancel_reason">
      </form>
      <p class="small text-body-secondary mb-0 mt-2">Każda zmiana trafia do historii kontaktu w CRM.</p>
    </div></section>
    <?php if ($ser): ?>
    <section class="card border-0 shadow-sm" aria-labelledby="rz-ser-h"><div class="card-header fw-semibold" id="rz-ser-h">Seria (<?= $ser['total'] ?> terminów)</div><div class="card-body">
      <a class="btn btn-sm btn-outline-secondary mb-2" href="rezerwacje.php?series=<?= h($row['series_id']) ?>">Pokaż wszystkie terminy serii</a>
      <form method="post" onsubmit="return confirm('Odwołać ten i wszystkie późniejsze terminy serii?')">
        <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>"><input type="hidden" name="_op" value="cancel_series">
        <label class="form-label small mb-0" for="rz-scr">Powód</label><input class="form-control form-control-sm mb-2" id="rz-scr" name="cancel_reason">
        <button class="btn btn-sm btn-outline-danger">Odwołaj ten i dalsze terminy serii</button>
      </form>
    </div></section>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>
</div>
</main>
<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
