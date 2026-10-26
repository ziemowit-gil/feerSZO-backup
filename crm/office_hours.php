<?php
/**
 * crm/office_hours.php — Godziny pracy organizacji (administrator): przedziały na dni tygodnia, strefa czasowa, dni wolne i święta,
 * bramka automatycznych potwierdzeń (Helpdesk, Zadania z maila, autoodpowiedzi Skrzynki CRM). Logika: includes/office_hours.php.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/crm.php';
require_once dirname(__DIR__) . '/includes/office_hours.php';

require_login();
require_module_enabled('crm_enabled', 'Moduł CRM');
if (!is_admin()) { http_response_code(403); die('Godziny pracy ustawia administrator.'); }
crm_migrate();
$PAGE_TITLE = 'Godziny pracy';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['_op'] ?? '') === 'hours') {
        oh_save($_POST);
        flash_set('success', 'Godziny pracy zapisane.');
    }
    header('Location: ' . APP_URL . '/crm/office_hours.php'); exit;
}

$cfg = oh_config();
$open = oh_is_open();
$next = $open ? '' : oh_next_open();
$gate = org_setting('office_hours_gate_acks') === '1';
$year = (int)date('Y');
$hol = oh_pl_holidays($year);
$upcoming = [];
foreach (array_merge($hol, oh_pl_holidays($year + 1)) as $d => $n) if ($d >= date('Y-m-d') && count($upcoming) < 6) $upcoming[$d] = $n;

include __DIR__ . '/includes/header_crm.php';
?>
<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
  <a href="<?= APP_URL ?>/crm/inbox.php" class="btn btn-sm btn-outline-secondary" aria-label="Wróć do Skrzynki CRM"><i class="bi bi-arrow-left" aria-hidden="true"></i></a>
  <h1 class="h4 mb-0"><i class="bi bi-clock me-2" aria-hidden="true"></i>Godziny pracy</h1>
  <span class="badge <?= $open ? 'bg-success' : 'bg-secondary' ?> ms-1" role="status"><?= $open ? 'teraz: czas pracy' : 'teraz: poza godzinami' . ($next ? ' — otwarte ' . h($next) : '') ?></span>
</div>
<p class="small text-muted" style="max-width:820px">Godziny pracy sterują <b>automatycznymi odpowiedziami</b>: potwierdzenia przyjęcia (Helpdesk, Zadania z maila) i autoodpowiedzi Skrzynki CRM mogą być wysyłane
  tylko wtedy, gdy nikt nie pracuje. Czas liczony jest w wybranej strefie czasowej, z uwzględnieniem dni wolnych.</p>

<form method="post" class="card shadow-sm" style="max-width:820px"><div class="card-body">
  <input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="_op" value="hours">
  <div class="row g-2 mb-2">
    <div class="col-12 col-md-5"><label for="oh-tz" class="form-label small mb-1">Strefa czasowa</label>
      <select id="oh-tz" name="tz" class="form-select form-select-sm"><?php foreach (OH_TIMEZONES as $z => $l): ?><option value="<?= h($z) ?>"<?= $cfg['tz'] === $z ? ' selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></div>
  </div>
  <fieldset class="mb-3"><legend class="small fw-semibold mb-1">Dni i godziny pracy</legend>
    <table class="table table-sm align-middle mb-0" style="max-width:520px"><caption class="visually-hidden">Godziny pracy w poszczególnych dniach tygodnia</caption>
      <thead><tr><th scope="col">Dzień</th><th scope="col">Pracujemy</th><th scope="col">Od</th><th scope="col">Do</th></tr></thead><tbody>
      <?php foreach (OH_DAYS as $n => $name): $iv = $cfg['days'][$n]; ?>
        <tr><th scope="row" class="fw-normal"><?= h($name) ?></th>
          <td><input type="checkbox" class="form-check-input" name="days[<?= $n ?>][on]" value="1" aria-label="<?= h($name) ?> — dzień pracy"<?= $iv ? ' checked' : '' ?>></td>
          <td><input type="time" class="form-control form-control-sm" name="days[<?= $n ?>][from]" value="<?= h($iv[0] ?? '08:00') ?>" aria-label="<?= h($name) ?> — od"></td>
          <td><input type="time" class="form-control form-control-sm" name="days[<?= $n ?>][to]" value="<?= h($iv[1] ?? '16:00') ?>" aria-label="<?= h($name) ?> — do"></td></tr>
      <?php endforeach; ?></tbody></table>
  </fieldset>
  <div class="form-check mb-2"><input id="oh-pl" class="form-check-input" type="checkbox" name="pl_holidays" value="1"<?= $cfg['pl_holidays'] ? ' checked' : '' ?>>
    <label for="oh-pl" class="form-check-label small">Polskie święta państwowe są dniami wolnymi (z ruchomymi: Wielkanoc, Zielone Świątki, Boże Ciało)</label></div>
  <?php if ($upcoming): ?><p class="small text-muted ms-4 mb-2">Najbliższe: <?php $b = []; foreach ($upcoming as $d => $n) $b[] = date('j.m', strtotime($d)) . ' — ' . $n; echo h(implode(' · ', $b)); ?></p><?php endif; ?>
  <div class="mb-3"><label for="oh-h" class="form-label small mb-1">Dodatkowe dni wolne (daty RRRR-MM-DD, po przecinku lub w nowych liniach)</label>
    <textarea id="oh-h" name="holidays" rows="2" class="form-control form-control-sm" placeholder="2026-12-28, 2026-12-29"><?= h(implode(', ', $cfg['holidays'])) ?></textarea></div>
  <div class="form-check mb-3"><input id="oh-g" class="form-check-input" type="checkbox" name="gate_acks" value="1"<?= $gate ? ' checked' : '' ?>>
    <label for="oh-g" class="form-check-label small"><b>Potwierdzenia przyjęcia tylko poza godzinami pracy</b> (Helpdesk i Zadania z maila). Gdy wyłączone, potwierdzenia wysyłane są zawsze.</label></div>
  <button class="btn btn-sm btn-primary">Zapisz</button>
</div></form>
<?php include __DIR__ . '/includes/footer_crm.php'; ?>
