<?php
/**
 * karty30/ti/dydaktyk/konsultacja.php — jedna karta konsultacji (przeniesione z karty30/consultations/add|edit|view.php).
 *
 * ?nowa=1[&client_id=&schedule_id=] — nowa karta samodzielna (numer KON.XXX.MM.RRRR nadawany przy zapisie);
 * ?id=<id> — podgląd; robocza karta: edycja (karta terminu SzK — przez konsultacjaTermin.php), zatwierdzenie kodem IKA
 * + certyfikatem x509, anulowanie; PDF karty (konsultacjaPdf.php). Odczyt notowany w rejestrze dostępu RODO (k30_access_log).
 */
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__, 3) . '/modules/ti_consultations/logic/consultations.php';
require_once dirname(__DIR__, 3) . '/includes/pfron.php';

$me    = dyd_require();
$uid   = (int)$me['user_id'];
$staff = dyd_is_staff();
karty30_migrate();
$meRow = db_one("SELECT name, first_name, last_name, k30_consultant, cpc_code FROM users WHERE id=?", [$uid]) ?: [];
$isConsultant = !empty($meRow['k30_consultant']);
$approver = trim(($meRow['first_name'] ?? '') . ' ' . ($meRow['last_name'] ?? '')) ?: (string)($meRow['name'] ?? $me['name'] ?? '');

$id   = (int)($_GET['id'] ?? 0);
$isNew = !$id;
if ($isNew && !$staff && !$isConsultant) { flash_set('danger', 'Nową kartę zakłada kadra albo doradca TI.'); header('Location: konsultacje.php'); exit; }
$card = $id ? TiConsultations::get($id) : null;
if ($id && (!$card || !TiConsultations::canAccess($card, $uid, $staff))) { flash_set('danger', 'Nie znaleziono karty albo nie masz do niej dostępu.'); header('Location: konsultacje.php'); exit; }

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    dyd_token_check();
    $op = (string)($_POST['_op'] ?? '');
    if ($op === 'save') {
        $res = TiConsultations::saveStandalone([
            'client_id' => (int)($_POST['client_id'] ?? 0), 'consultant_id' => (int)($_POST['consultant_id'] ?? 0), 'schedule_id' => (int)($_POST['schedule_id'] ?? 0),
            'datetime' => (string)($_POST['datetime'] ?? ''), 'duration' => (int)($_POST['duration'] ?? 0),
            'description' => (string)($_POST['description'] ?? ''), 'next_action' => (string)($_POST['next_action'] ?? ''),
        ], $isNew ? null : $id, $uid);
        if ($res['ok']) { flash_set('success', $isNew ? 'Karta konsultacji zapisana.' : 'Zmiany zapisane.'); header('Location: konsultacja.php?id=' . $res['id']); exit; }
        $errors = $res['errors'];
    } elseif ($op === 'approve' && $card) {
        $res = TiConsultations::approve([$id], $uid, $approver, (string)($_POST['ika_code'] ?? ''));
        if ($res['ok']) flash_set('success', 'Karta zatwierdzona (IKA + x509).' . ($res['closed'] ? ' Szkolenie ' . $res['closed'] . ' zamknięte — karty trafiły do EZD.' : ''));
        else foreach ($res['errors'] as $e) flash_set('danger', $e);
        header('Location: konsultacja.php?id=' . $id . '#zatwierdzenie'); exit;
    } elseif ($op === 'cancel' && $card) {
        $ok = TiConsultations::cancel($id);
        flash_set($ok ? 'info' : 'warning', $ok ? 'Karta anulowana.' : 'Anulować można tylko roboczą kartę.');
        header('Location: konsultacja.php?id=' . $id); exit;
    }
}
if ($card && $_SERVER['REQUEST_METHOD'] === 'GET') {
    try { k30_log_access('consultation', $id, 'view', (string)($card['client_name'] ?? '')); } catch (\Throwable $e) {}
}
$card = $id ? TiConsultations::get($id) : null;
$edit = $isNew || ($card && $card['status'] === 'draft' && !(int)$card['session_id'] && isset($_GET['edytuj']));

$clients     = $edit ? db_all("SELECT id, name FROM k30_clients ORDER BY name") : [];
$consultants = $edit ? k30_get_consultants() : [];
$schedules   = $edit ? db_all("SELECT s.id, s.start_time, s.duration_minutes, s.client_id, c.name AS client_name FROM k30_schedules s LEFT JOIN k30_clients c ON c.id=s.client_id
                               WHERE s.status IN ('confirmed','attended') ORDER BY s.start_time DESC LIMIT 100") : [];
$pfron = [];
if ($edit && k30_pfron_enabled()) foreach (db_all("SELECT client_id, contract_number FROM k30_pfron_contracts WHERE status='active'") as $p) $pfron[(int)$p['client_id']] = (string)$p['contract_number'];

$v = fn(string $k, $def = '') => $_POST[$k] ?? ($card[$k] ?? $def);
$certChk = k30_verify_consultant_cert($uid);
$hasIka  = trim((string)($meRow['cpc_code'] ?? '')) !== '';
$canApprove = $card && $card['status'] === 'draft' && $certChk['ok'] && $hasIka;
$ST = TiConsultations::statuses();

$KP_TITLE  = ($isNew ? 'Nowa konsultacja' : 'Karta ' . ($card['card_no'] ?: '#' . $id)) . ' — Panel dydaktyka';
$KP_TOPBAR = ['brand' => 'Panel dydaktyka', 'icon' => 'easel2', 'user' => (string)($me['name'] ?? ''), 'logout' => 'logout.php'];
$KP_BODY_CLASS = 'dyd-usos ti-skin';
include dirname(__DIR__) . '/kursant/_layout_head.php';
$_skin_css = __DIR__ . '/../assets/ti_skin.css';
?>
<link rel="stylesheet" href="../assets/ti_skin.css?v=<?= is_file($_skin_css) ? (int)filemtime($_skin_css) : 1 ?>">
<?php if ($staff): $KIER_CUR = 'konsultacje.php'; $KIER_LABEL = 'Karty konsultacji'; include __DIR__ . '/_kierownik_bar.php'; endif; ?>

<main id="main" class="dyd-wrap">
<div class="container-fluid py-3" style="max-width:1000px">
<nav aria-label="Okruszki" class="small mb-2"><a href="konsultacje.php">Karty konsultacji</a> › <?= $isNew ? 'Nowa' : h($card['card_no'] ?: '#' . $id) ?></nav>
<?= flash_html() ?>
<?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

<?php if ($edit): ?>
<h1 class="h4 fw-bold"><i class="bi bi-clipboard2-plus text-primary me-2" aria-hidden="true"></i><?= $isNew ? 'Nowa konsultacja' : 'Edycja karty ' . h($card['card_no']) ?></h1>
<?php if ($isNew): ?><p class="small text-body-secondary">Numer KON.XXX.MM.RRRR nada się przy zapisie (kolejny w miesiącu konsultacji).</p><?php endif; ?>
<form method="post" class="card border-0 shadow-sm"><div class="card-body">
  <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>"><input type="hidden" name="_op" value="save">
  <div class="mb-3">
    <label class="form-label" for="kc-sched">Powiąż z terminem harmonogramu (opcjonalnie)</label>
    <select class="form-select" id="kc-sched" name="schedule_id">
      <option value="">— bez terminu —</option>
      <?php foreach ($schedules as $s): ?><option value="<?= (int)$s['id'] ?>" data-client="<?= (int)$s['client_id'] ?>" data-dt="<?= h(str_replace(' ', 'T', substr((string)$s['start_time'], 0, 16))) ?>" data-dur="<?= (int)$s['duration_minutes'] ?>"<?= (int)$v('schedule_id', (int)($_GET['schedule_id'] ?? 0)) === (int)$s['id'] ? ' selected' : '' ?>><?= h($s['client_name'] . ' — ' . date('d.m.Y H:i', strtotime((string)$s['start_time']))) ?></option><?php endforeach; ?>
    </select>
    <div class="form-text">Wybranie terminu uzupełni beneficjenta, datę i czas trwania.</div>
  </div>
  <div class="mb-3">
    <label class="form-label fw-semibold" for="kc-client">Beneficjent <span aria-hidden="true" class="text-danger">*</span></label>
    <select class="form-select" id="kc-client" name="client_id" required>
      <option value="">— wybierz —</option>
      <?php foreach ($clients as $c): ?><option value="<?= (int)$c['id'] ?>"<?= (int)$v('client_id', (int)($_GET['client_id'] ?? 0)) === (int)$c['id'] ? ' selected' : '' ?>><?= h($c['name']) ?></option><?php endforeach; ?>
    </select>
    <div class="alert alert-warning small py-2 mt-2 mb-0" id="kc-pfron" role="status" aria-live="polite" hidden>Ten beneficjent ma aktywną umowę PFRON (<span id="kc-pfron-no" class="font-monospace"></span>) — szkolenie PFRON to odrębne świadczenie. Upewnij się, że to konsultacja.</div>
  </div>
  <div class="mb-3">
    <label class="form-label" for="kc-cons">Konsultant</label>
    <select class="form-select" id="kc-cons" name="consultant_id">
      <option value="">— nie przypisano —</option>
      <?php foreach ($consultants as $u): ?><option value="<?= (int)$u['id'] ?>"<?= (int)$v('consultant_id', $isNew ? $uid : 0) === (int)$u['id'] ? ' selected' : '' ?>><?= h($u['display_name']) ?></option><?php endforeach; ?>
    </select>
  </div>
  <div class="row g-3 mb-3">
    <div class="col-md-8"><label class="form-label fw-semibold" for="kc-dt">Data i godzina <span aria-hidden="true" class="text-danger">*</span></label>
      <input class="form-control" type="datetime-local" id="kc-dt" name="datetime" required value="<?= h((string)($_POST['datetime'] ?? ($card ? str_replace(' ', 'T', substr((string)$card['consultation_datetime'], 0, 16)) : date('Y-m-d\TH:i')))) ?>"></div>
    <div class="col-md-4"><label class="form-label" for="kc-dur">Czas trwania (min)</label>
      <input class="form-control" type="number" min="5" step="5" id="kc-dur" name="duration" value="<?= h((string)($_POST['duration'] ?? ($card['duration_minutes'] ?? 60))) ?>"></div>
  </div>
  <div class="mb-3"><label class="form-label" for="kc-desc">Opis konsultacji</label><textarea class="form-control" id="kc-desc" name="description" rows="5"><?= h((string)$v('description')) ?></textarea></div>
  <div class="mb-3"><label class="form-label" for="kc-next">Następne działania</label><textarea class="form-control" id="kc-next" name="next_action" rows="2"><?= h((string)$v('next_action')) ?></textarea></div>
  <button class="btn btn-primary"><i class="bi bi-check-lg me-1" aria-hidden="true"></i>Zapisz</button>
  <a class="btn btn-outline-secondary" href="<?= $isNew ? 'konsultacje.php' : 'konsultacja.php?id=' . $id ?>">Anuluj</a>
</div></form>
<script>
(function () {
  var sch = document.getElementById('kc-sched'), cl = document.getElementById('kc-client'), pf = <?= json_encode($pfron, JSON_HEX_TAG) ?>;
  sch.addEventListener('change', function () { var o = sch.options[sch.selectedIndex]; if (!o.value) return;
    cl.value = o.dataset.client || ''; document.getElementById('kc-dt').value = o.dataset.dt || ''; document.getElementById('kc-dur').value = o.dataset.dur || ''; pfr(); });
  function pfr() { var b = document.getElementById('kc-pfron'), n = pf[parseInt(cl.value, 10)]; b.hidden = !n; if (n) document.getElementById('kc-pfron-no').textContent = n; }
  cl.addEventListener('change', pfr); pfr();
})();
</script>

<?php else: $st = $ST[$card['status']] ?? ['label' => $card['status'], 'color' => '#555', 'bg' => '#eee']; ?>
<div class="d-flex align-items-start gap-2 flex-wrap mb-3">
  <div>
    <h1 class="h4 fw-bold mb-0"><span class="font-monospace"><?= h($card['card_no'] ?: '#' . $id) ?></span> — <?= h($card['client_name']) ?></h1>
    <div class="small text-body-secondary"><?= h(date('d.m.Y H:i', strtotime((string)$card['consultation_datetime']))) ?><?= $card['duration_minutes'] ? ' · ' . (int)$card['duration_minutes'] . ' min' : '' ?></div>
    <span class="badge mt-1" style="background:<?= h($st['bg']) ?>;color:<?= h($st['color']) ?>;border:1px solid <?= h($st['color']) ?>"><?= h($st['label']) ?></span>
  </div>
  <div class="ms-auto d-flex gap-2 flex-wrap">
    <a class="btn btn-sm btn-outline-secondary" href="konsultacjaPdf.php?id=<?= $id ?>" target="_blank" rel="noopener"><i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>PDF</a>
    <?php if ($card['status'] === 'draft'): ?>
      <?php if ((int)$card['session_id']): ?>
      <a class="btn btn-sm btn-outline-primary" href="konsultacjaTermin.php?session=<?= (int)$card['session_id'] ?>"><i class="bi bi-pencil me-1" aria-hidden="true"></i>Edytuj w kartach terminu</a>
      <?php else: ?>
      <a class="btn btn-sm btn-outline-primary" href="konsultacja.php?id=<?= $id ?>&amp;edytuj=1"><i class="bi bi-pencil me-1" aria-hidden="true"></i>Edytuj</a>
      <?php endif; ?>
      <form method="post" class="d-inline" onsubmit="return confirm('Anulować tę kartę? Tej operacji nie można cofnąć.')">
        <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>"><input type="hidden" name="_op" value="cancel">
        <button class="btn btn-sm btn-outline-danger"><i class="bi bi-x-circle me-1" aria-hidden="true"></i>Anuluj kartę</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<div class="row g-3">
  <div class="col-md-5">
    <div class="card border-0 shadow-sm"><div class="card-body small">
      <dl class="row mb-0">
        <dt class="col-5 fw-normal text-body-secondary">Beneficjent</dt><dd class="col-7"><?= h($card['client_name']) ?></dd>
        <dt class="col-5 fw-normal text-body-secondary">Konsultant</dt><dd class="col-7"><?= h($card['consultant_name'] ?: '—') ?></dd>
        <?php if ($card['course_id']): ?><dt class="col-5 fw-normal text-body-secondary">Szkolenie</dt><dd class="col-7"><a class="font-monospace" href="konsultacje.php?course=<?= (int)$card['course_id'] ?>"><?= h($card['course_name']) ?></a></dd><?php endif; ?>
        <?php if ($card['approved_by_name']): ?><dt class="col-5 fw-normal text-body-secondary">Zatwierdził(a)</dt><dd class="col-7"><?= h($card['approved_by_name']) ?></dd><?php endif; ?>
        <?php if ($card['ika_verified_at']): ?><dt class="col-5 fw-normal text-body-secondary">Weryfikacja IKA</dt><dd class="col-7"><?= h(date('d.m.Y H:i:s', strtotime((string)$card['ika_verified_at']))) ?></dd><?php endif; ?>
        <?php if ($card['sha1sum']): ?><dt class="col-5 fw-normal text-body-secondary">SHA1</dt><dd class="col-7 font-monospace text-break" style="font-size:.72rem"><?= h($card['sha1sum']) ?></dd><?php endif; ?>
        <?php if ($card['cert_subject']): ?><dt class="col-5 fw-normal text-body-secondary">Certyfikat x509</dt><dd class="col-7"><?= h($card['cert_subject']) ?><div class="font-monospace text-break" style="font-size:.7rem"><?= h($card['cert_fingerprint']) ?></div></dd><?php endif; ?>
        <?php if ($card['created_by_name']): ?><dt class="col-5 fw-normal text-body-secondary">Dodał(a)</dt><dd class="col-7"><?= h($card['created_by_name']) ?></dd><?php endif; ?>
      </dl>
    </div></div>
  </div>
  <div class="col-md-7">
    <section class="card border-0 shadow-sm mb-3" aria-labelledby="kc-d-h"><div class="card-header fw-semibold" id="kc-d-h">Opis konsultacji</div><div class="card-body"><?= $card['description'] ? nl2br(h($card['description'])) : '<span class="text-body-secondary">—</span>' ?></div></section>
    <section class="card border-0 shadow-sm" aria-labelledby="kc-n-h"><div class="card-header fw-semibold" id="kc-n-h">Następne działania</div><div class="card-body"><?= $card['next_action'] ? nl2br(h($card['next_action'])) : '<span class="text-body-secondary">—</span>' ?></div></section>
  </div>
</div>

<?php if ($card['status'] === 'draft'): ?>
<section class="card border-0 shadow-sm mt-3" id="zatwierdzenie" aria-labelledby="kc-a-h">
  <div class="card-header fw-semibold" id="kc-a-h"><i class="bi bi-shield-lock me-1 text-primary" aria-hidden="true"></i>Zatwierdzenie — kod IKA + certyfikat x509</div>
  <div class="card-body">
  <?php if ($canApprove): ?>
    <p class="small mb-2">Certyfikat: <strong><?= h($certChk['cert']['cert_subject'] ?? '') ?></strong>, ważny do <?= h(date('d.m.Y', (int)($certChk['cert']['cert_valid_to'] ?? 0))) ?>. Zatwierdzenie jest nieodwracalne.</p>
    <form method="post" class="d-flex flex-wrap align-items-end gap-2">
      <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>"><input type="hidden" name="_op" value="approve">
      <div><label class="form-label small mb-0" for="kc-ika">Kod IKA (6 cyfr)</label>
        <input class="form-control font-monospace text-center" style="width:9rem;letter-spacing:.3em" id="kc-ika" name="ika_code" inputmode="numeric" pattern="\d{6}" maxlength="6" autocomplete="one-time-code" required aria-describedby="kc-ika-h"></div>
      <button class="btn btn-success"><i class="bi bi-shield-check me-1" aria-hidden="true"></i>Zatwierdź</button>
      <div class="form-text w-100" id="kc-ika-h">3 błędne próby blokują kod na 15 minut.</div>
    </form>
  <?php else: ?>
    <p class="small mb-1">Aby zatwierdzić kartę, potrzebujesz:</p>
    <ul class="small mb-0">
      <?php if (!$hasIka): ?><li>kodu IKA — nadaje administrator</li><?php endif; ?>
      <?php if (!$certChk['ok']): ?><li>certyfikatu x509 — <?= h((string)$certChk['error']) ?></li><?php endif; ?>
    </ul>
  <?php endif; ?>
  </div>
</section>
<?php endif; ?>
<?php endif; ?>
</div>
</main>
<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
