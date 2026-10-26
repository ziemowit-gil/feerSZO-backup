<?php
/**
 * modules/ti_licencje_dydaktyczne/view/_korektaForm.php — AMSK: lista korekt celu + formularz „Wystaw korektę”.
 * Zmienne: $corrScope ('license'|'operation'), $corrId, $corrTarget (sprawa / operacja). Korekta czeka na odręczny podpis kierownika.
 */
require_once dirname(__DIR__) . '/lib/LicenseCorrection.php';
$_cf = LicenseCorrection::fields($corrScope, $corrTarget);
$_cl = LicenseCorrection::forTarget($corrScope, (int)$corrId);
$_rows = 3;
?>
<section class="panel" id="korekty">
  <h2 class="mt-0"><span class="sym" style="background:#7c2d12">AMSK</span> Korekty danych w dokumentach AM</h2>
  <p class="small text-secondary">Błąd w danych dokumentu? Wystaw korektę: wskaż pola i nowe wartości. <b>Dane zmienią się dopiero po odręcznym podpisie kierownika</b> na druku kontrolnym AMSK/1 (QR + Code128) i potwierdzeniu podpisu w SZO.</p>
  <?php if ($_cl): ?>
  <table class="table table-sm align-middle"><thead><tr><th>Korekta</th><th>Pola</th><th>Stan</th><th>Wystawiono</th><th></th></tr></thead><tbody>
    <?php foreach ($_cl as $c): ?>
    <tr><td class="text-nowrap"><a href="<?= h(route('ld.corr', ['id' => $c['id']])) ?>"><?= h(LicenseCorrection::code((int)$c['id'])) ?></a></td>
      <td class="small"><?= h(implode(', ', array_map(fn($i) => $i['label'], $c['itemsList']))) ?></td>
      <td><?= ldChip(LicenseCorrection::statusLabel($c['status']), LicenseCorrection::statusColor($c['status'])) ?><?php if ($c['status'] === LicenseCorrection::APPLIED): $_ps = LicenseCorrection::packageState($c); if ($_ps === 'manual'): ?> <?= ldChip('brak pakietu — ręczne generowanie', '#fa6800') ?><?php elseif ($_ps === 'scheduled'): ?> <?= ldChip('pakiet automatycznie o ' . substr((string)$c['package_due_at'], 11, 5), '#1ba1e2') ?><?php endif; endif; ?></td>
      <td class="small text-nowrap"><?= h(ldDt($c['created_at'])) ?></td>
      <td class="text-end text-nowrap"><a class="btn btn-sm btn-metro" style="background:#7c2d12" href="<?= h(route('ld.corr.pdf', ['id' => $c['id']])) ?>" target="_blank" rel="noopener"><i class="bi bi-printer" aria-hidden="true"></i> AMSK/1</a></td></tr>
    <?php endforeach; ?></tbody></table>
  <?php endif; ?>
  <?php if ($_cf): ?>
  <details<?= $_cl ? '' : ' open' ?>><summary class="fw-semibold">Wystaw nową korektę</summary>
    <form method="post" action="<?= h(route('ld.corr.new')) ?>" class="mt-2"><?= Router::csrfField() ?>
      <input type="hidden" name="scope" value="<?= h($corrScope) ?>"><input type="hidden" name="target_id" value="<?= (int)$corrId ?>">
      <?php for ($i = 0; $i < $_rows; $i++): ?>
      <div class="row g-2 mb-2 amsk-row">
        <div class="col-md-5"><label class="form-label small mb-0" for="ak<?= $i ?>">Pole do korekty <?= $i + 1 ?></label>
          <select class="form-select form-select-sm amsk-key" id="ak<?= $i ?>" name="key[]"><option value="">— nie zmieniaj —</option>
            <?php foreach ($_cf as $k => $d): ?><option value="<?= h($k) ?>" data-cur="<?= h(LicenseCorrection::display($d, $d[4])) ?>" data-type="<?= h($d[1]) ?>"<?= $d[1] === 'select' ? ' data-opts="' . h(json_encode($d[3], JSON_UNESCAPED_UNICODE)) . '"' : '' ?>><?= h($d[2] . ' › ' . $d[0]) ?></option><?php endforeach; ?></select>
          <div class="small text-secondary amsk-cur" aria-live="polite"></div></div>
        <div class="col-md-7"><label class="form-label small mb-0" for="av<?= $i ?>">Nowa wartość <?= $i + 1 ?></label>
          <input class="form-control form-control-sm amsk-val" id="av<?= $i ?>" name="val[]" maxlength="2000"></div>
      </div>
      <?php endfor; ?>
      <label class="form-label small mb-0" for="areason">Uzasadnienie korekty (wymagane)</label>
      <textarea class="form-control form-control-sm mb-2" id="areason" name="reason" rows="2" required minlength="5" placeholder="np. literówka w nazwisku; błędny adres e-mail podany we wniosku"></textarea>
      <button class="btn btn-sm btn-metro" style="background:#7c2d12">Wystaw korektę i przejdź do druku AMSK/1</button>
    </form>
  </details>
  <script>
  (function () {
    document.querySelectorAll('#korekty .amsk-row').forEach(function (row) {
      var sel = row.querySelector('.amsk-key'), cur = row.querySelector('.amsk-cur'), inp = row.querySelector('.amsk-val');
      sel.addEventListener('change', function () {
        var o = sel.options[sel.selectedIndex];
        cur.textContent = o.value ? 'Teraz: ' + o.getAttribute('data-cur') : '';
        var t = o.getAttribute('data-type'), ph = { date: 'rrrr-mm-dd', money: 'np. 49,00', int: 'liczba' };
        inp.setAttribute('placeholder', ph[t] || '');
        var holder = inp.parentNode, name = inp.name, id = inp.id, old = inp;
        if (t === 'select' && o.getAttribute('data-opts')) {
          var s = document.createElement('select'); s.className = 'form-select form-select-sm amsk-val'; s.name = name; s.id = id;
          var opts = JSON.parse(o.getAttribute('data-opts'));
          Object.keys(opts).forEach(function (k) { var x = document.createElement('option'); x.value = k; x.textContent = opts[k]; s.appendChild(x); });
          holder.replaceChild(s, old); inp = s;
        } else if (inp.tagName === 'SELECT') {
          var n = document.createElement('input'); n.className = 'form-control form-control-sm amsk-val'; n.name = name; n.id = id; n.maxLength = 2000;
          holder.replaceChild(n, old); inp = n;
        }
      });
    });
  })();
  </script>
  <?php else: ?><p class="text-secondary mb-0">W tym dokumencie nie ma pól do korekty.</p><?php endif; ?>
</section>
