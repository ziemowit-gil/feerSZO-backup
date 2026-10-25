<?php
/**
 * modules/ti_licencje_dydaktyczne/view/_rotacjaForm.php — ASK-UP1: uzasadnienia rotacji licencji celu + formularz „Wystaw uzasadnienie rotacji”.
 * Zmienne: $corrScope ('license'|'operation'), $corrId, $corrTarget. Druk czeka na odręczny podpis kierownika (QR + Code128).
 */
require_once dirname(__DIR__) . '/lib/LicenseRotation.php';
$_rl = LicenseRotation::forTarget($corrScope, (int)$corrId);
$_rd = LicenseRotation::defaults($corrScope, $corrTarget);
?>
<section class="panel" id="rotacje">
  <h2 class="mt-0"><span class="sym" style="background:#14532d">ASK-UP1</span> Uzasadnienie rotacji licencji</h2>
  <p class="small text-secondary">Rotacja licencji (zmiana użytkownika lub konta, np. reasygnacja AM22 albo przeniesienie po zmianie UPN) wymaga uzasadnienia. Druk <b>ASK-UP1</b> (QR + Code128) podpisuje <b>odręcznie kierownik</b> — dotyczy danych osobowych. Druk nie zmienia danych licencji; samą rotację wykonuje operacja.</p>
  <?php if ($_rl): ?>
  <table class="table table-sm align-middle"><thead><tr><th>Kod</th><th>Było → jest</th><th>Stan</th><th>Wystawiono</th><th></th></tr></thead><tbody>
    <?php foreach ($_rl as $r): ?>
    <tr><td class="text-nowrap"><a href="<?= h(route('ld.rot', ['id' => $r['id']])) ?>"><?= h(LicenseRotation::code((int)$r['id'])) ?></a></td>
      <td class="small"><?= h($r['from_holder'] ?: '—') ?> → <b><?= h($r['to_holder']) ?></b></td>
      <td><?= ldChip(LicenseRotation::statusLabel($r['status']), LicenseRotation::statusColor($r['status'])) ?></td>
      <td class="small text-nowrap"><?= h(ldDt($r['created_at'])) ?></td>
      <td class="text-end"><a class="btn btn-sm btn-metro" style="background:#14532d" href="<?= h(route('ld.rot.pdf', ['id' => $r['id']])) ?>" target="_blank" rel="noopener"><i class="bi bi-printer" aria-hidden="true"></i> ASK-UP1</a></td></tr>
    <?php endforeach; ?></tbody></table>
  <?php endif; ?>
  <details<?= $_rl ? '' : ' open' ?>><summary class="fw-semibold">Wystaw uzasadnienie rotacji (ASK-UP1)</summary>
    <form method="post" action="<?= h(route('ld.rot.new')) ?>" class="row g-2 mt-1"><?= Router::csrfField() ?>
      <input type="hidden" name="scope" value="<?= h($corrScope) ?>"><input type="hidden" name="target_id" value="<?= (int)$corrId ?>">
      <div class="col-md-6"><label class="form-label small mb-0" for="rt-lic">Licencja / usługa</label><input class="form-control form-control-sm" id="rt-lic" name="license_label" value="<?= h($_rd['license_label']) ?>"></div>
      <div class="col-md-6"><label class="form-label small mb-0" for="rt-date">Data rotacji</label><input class="form-control form-control-sm" id="rt-date" name="rotation_date" placeholder="rrrr-mm-dd"></div>
      <div class="col-md-6"><label class="form-label small mb-0" for="rt-from">Dotychczasowy użytkownik</label><input class="form-control form-control-sm" id="rt-from" name="from_holder" value="<?= h($_rd['from_holder']) ?>"></div>
      <div class="col-md-6"><label class="form-label small mb-0" for="rt-to">Nowy użytkownik (wymagane)</label><input class="form-control form-control-sm" id="rt-to" name="to_holder" required></div>
      <div class="col-md-6"><label class="form-label small mb-0" for="rt-fupn">Dotychczasowy login (UPN)</label><input class="form-control form-control-sm" id="rt-fupn" name="from_upn" value="<?= h($_rd['from_upn']) ?>"></div>
      <div class="col-md-6"><label class="form-label small mb-0" for="rt-tupn">Nowy login (UPN), jeśli dotyczy</label><input class="form-control form-control-sm" id="rt-tupn" name="to_upn"></div>
      <div class="col-12"><label class="form-label small mb-0" for="rt-reason">Uzasadnienie rotacji (wymagane)</label>
        <textarea class="form-control form-control-sm" id="rt-reason" name="reason" rows="3" required minlength="10" placeholder="np. zakończenie udziału w kursie, licencja wraca do puli i jest przekazywana kolejnej osobie z listy"></textarea></div>
      <div class="col-12"><button class="btn btn-sm btn-metro" style="background:#14532d">Wystaw i przejdź do druku ASK-UP1</button></div>
    </form>
  </details>
</section>
