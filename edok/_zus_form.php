<?php
/**
 * edok/_zus_form.php — formularz „Przelew składek ZUS” (bez obiegu akceptacji).
 * Wejście: $zus_form (stan po błędzie walidacji albo null), $rachunki_org.
 * Obsługa POST: edok_zus_handle_post() w includes/edok.php.
 */
?>
<?php if ($rachunki_org):
  $zf        = $zus_form ?? ['okres' => edok_zus_okres_domyslny(), 'mieszana' => false, 'format' => 'auto', 'czesci' => [], 'kwota' => '', 'rachunek' => '', 'zus_nrs' => '', 'dup' => []];
  $nrs_cfg   = edok_zus_nrs_config();
  $zus_hist  = edok_zus_przelewy();
  $m_rows    = $zf['czesci'] && $zf['mieszana'] ? $zf['czesci'] : [['nrb' => '', 'kwota' => ''], ['nrb' => '', 'kwota' => '']];
  $rach_opts = function (string $sel) use ($rachunki_org): string {
      $h = '<option value="">— rachunek —</option>';
      foreach ($rachunki_org as $r) {
          $n = preg_replace('/\D/', '', $r['nrb']);
          $h .= '<option value="' . h($n) . '"' . ($n === preg_replace('/\D/', '', $sel) ? ' selected' : '') . '>' . h(($r['nazwa'] ?: $r['bank']) . ' (…' . substr($n, -4) . ')') . '</option>';
      }
      return $h;
  };
?>
<form method="post" class="card shadow-sm mb-3 border-primary-subtle">
  <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
  <input type="hidden" name="action" value="export_zus">
  <div class="card-header fw-semibold"><i class="bi bi-shield-check"></i> Przelew składek ZUS
    <span class="small text-muted fw-normal">— bez obiegu akceptacji (kwota wynika z rozliczonych rachunków i umów)</span></div>
  <div class="card-body">
    <div class="row g-2 align-items-end mb-2">
      <div class="col-sm-3">
        <label class="form-label small fw-semibold mb-1" for="zus_okres">Składki za miesiąc</label>
        <?= edok_okres_select_html('okres', 'zus_okres', $zf['okres'], 'required onchange="edokZusTytul()"') ?>
      </div>
      <div class="col-sm-5">
        <span class="form-label small fw-semibold mb-1 d-block">Tytuł przelewu</span>
        <div class="font-monospace small border rounded px-2 py-1 bg-light" id="zus_tytul"><?= h(edok_zus_tytul($zf['okres'])) ?></div>
      </div>
      <div class="col-sm-4">
        <label class="form-label small fw-semibold mb-1" for="zus_format">Format pliku</label>
        <select name="format" id="zus_format" class="form-select form-select-sm">
          <?php foreach (EDOK_PRZELEWY_FORMATY as $k => $label): ?>
          <option value="<?= h($k) ?>" <?= $zf['format'] === $k ? 'selected' : '' ?>><?= h($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="mb-2 small">
      Odbiorca: <strong><?= h(EDOK_ZUS_ODBIORCA) ?></strong>, rachunek składkowy
      <?php if ($nrs_cfg !== ''): ?>
      <span class="font-monospace"><?= h(edok_nrb_format($nrs_cfg)) ?></span> <span class="text-muted">(z konfiguracji organizacji)</span>
      <?php endif; ?>
    </div>
    <?php if ($nrs_cfg === ''): ?>
    <div class="alert alert-warning py-2 small">
      <strong>Pierwszy przelew do ZUS — potwierdź numer rachunku składkowego.</strong>
      Numer nie jest jeszcze zapisany w konfiguracji organizacji. Sprawdź go w PUE ZUS / eZUS — po potwierdzeniu zostanie zapisany i nie trzeba będzie go powtarzać.
      <div class="row g-2 mt-1 align-items-center">
        <div class="col-md-6">
          <label class="visually-hidden" for="zus_nrs">Numer rachunku składkowego</label>
          <input type="text" name="zus_nrs" id="zus_nrs" class="form-control form-control-sm font-monospace" required
                 value="<?= h(edok_nrb_format($zf['zus_nrs'] !== '' ? preg_replace('/\D/', '', $zf['zus_nrs']) : EDOK_ZUS_NRS_DOMYSLNY)) ?>">
        </div>
        <div class="col-md-6">
          <div class="form-check">
            <input type="checkbox" class="form-check-input" name="nrs_confirm" value="1" id="nrs_confirm" required>
            <label class="form-check-label" for="nrs_confirm">Potwierdzam — to nasz numer rachunku składkowego ZUS</label>
          </div>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <div class="form-check mb-2">
      <input type="checkbox" class="form-check-input" name="mieszana" value="1" id="zus_mieszana" <?= $zf['mieszana'] ? 'checked' : '' ?> onchange="edokZusMieszana()">
      <label class="form-check-label small" for="zus_mieszana">Płatność mieszana — część kwoty z jednego rachunku, część z innego</label>
    </div>

    <div class="row g-2 align-items-end" id="zus_single">
      <div class="col-sm-4">
        <label class="form-label small fw-semibold mb-1" for="zus_kwota">Kwota</label>
        <input type="text" name="kwota" id="zus_kwota" class="form-control form-control-sm font-monospace text-end" value="<?= h($zf['kwota']) ?>" placeholder="0,00">
      </div>
      <div class="col-sm-5">
        <label class="form-label small fw-semibold mb-1" for="zus_rachunek">Z rachunku</label>
        <select name="rachunek" id="zus_rachunek" class="form-select form-select-sm"><?= $rach_opts($zf['rachunek']) ?></select>
      </div>
    </div>

    <div id="zus_mixed" style="display:none">
      <div id="zus_mixed_rows">
        <?php foreach ($m_rows as $i => $c): ?>
        <div class="row g-2 align-items-end mb-1 zus-mixed-row">
          <div class="col-sm-4">
            <label class="form-label small mb-1" for="zus_mk_<?= $i ?>">Kwota (część <?= $i + 1 ?>)</label>
            <input type="text" name="m_kwota[]" id="zus_mk_<?= $i ?>" class="form-control form-control-sm font-monospace text-end" value="<?= h($c['kwota']) ?>" placeholder="0,00" oninput="edokZusSuma()">
          </div>
          <div class="col-sm-5">
            <label class="form-label small mb-1" for="zus_mr_<?= $i ?>">Z rachunku</label>
            <select name="m_rachunek[]" id="zus_mr_<?= $i ?>" class="form-select form-select-sm"><?= $rach_opts($c['nrb']) ?></select>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <button type="button" class="btn btn-sm btn-link px-0" onclick="edokZusDodaj()"><i class="bi bi-plus"></i> kolejny rachunek</button>
      <div class="small">Razem: <strong class="font-monospace" id="zus_suma">0,00</strong> PLN — każdy rachunek dostanie osobny plik (ZIP).</div>
    </div>

    <?php if ($zf['dup']): ?>
    <div class="alert alert-danger py-2 small mt-2">
      <strong>Przelew składek za <?= h(edok_okres_label($zf['okres'])) ?> był już generowany:</strong>
      <?php foreach ($zf['dup'] as $d): ?> <?= h($d['kwota']) ?> PLN (<?= h(date('d.m.Y H:i', strtotime($d['created_at']))) ?>, <?= h($d['user_name']) ?>);<?php endforeach; ?>
      <div class="form-check mt-1">
        <input type="checkbox" class="form-check-input" name="confirm_dup" value="1" id="zus_confirm_dup">
        <label class="form-check-label" for="zus_confirm_dup">Sprawdziłem — to kolejna wpłata za ten okres</label>
      </div>
    </div>
    <?php endif; ?>

    <button type="submit" class="btn btn-sm btn-success mt-2"><i class="bi bi-download"></i> Pobierz plik przelewu</button>

    <?php if ($zus_hist): ?>
    <details class="mt-3 small">
      <summary>Ostatnie przelewy ZUS</summary>
      <table class="table table-sm mb-0 mt-1">
        <thead><tr><th>Okres</th><th class="text-end">Kwota</th><th>Z rachunku</th><th>Wygenerowano</th></tr></thead>
        <tbody>
        <?php foreach ($zus_hist as $d): ?>
          <tr><td><?= h(edok_okres_label($d['okres'])) ?></td><td class="text-end font-monospace"><?= h($d['kwota']) ?></td>
              <td class="font-monospace">…<?= h(substr($d['rachunek_z'], -4)) ?></td>
              <td><?= h(date('d.m.Y H:i', strtotime($d['created_at']))) ?> · <?= h($d['user_name']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </details>
    <?php endif; ?>
  </div>
</form>
<script>
function edokZusTytul() {
  var v = document.getElementById('zus_okres').value;
  document.getElementById('zus_tytul').textContent = /^\d{4}-\d{2}$/.test(v) ? 'Składki ZUS za ' + v.substring(5, 7) + '/' + v.substring(0, 4) : '—';
}
function edokZusMieszana() {
  var on = document.getElementById('zus_mieszana').checked;
  document.getElementById('zus_single').style.display = on ? 'none' : '';
  document.getElementById('zus_mixed').style.display = on ? '' : 'none';
  document.getElementById('zus_kwota').required = !on;
  document.getElementById('zus_rachunek').required = !on;
  edokZusSuma();
}
function edokZusSuma() {
  var s = 0;
  document.querySelectorAll('[name="m_kwota[]"]').forEach(function (i) { s += parseFloat((i.value || '0').replace(/\s/g, '').replace(',', '.')) || 0; });
  document.getElementById('zus_suma').textContent = s.toFixed(2).replace('.', ',');
}
function edokZusDodaj() {
  var rows = document.getElementById('zus_mixed_rows');
  var last = rows.querySelector('.zus-mixed-row:last-child');
  var n = rows.querySelectorAll('.zus-mixed-row').length;
  var c = last.cloneNode(true);
  c.querySelectorAll('input,select').forEach(function (el) { el.value = ''; el.id = el.id.replace(/_\d+$/, '_' + n); });
  c.querySelectorAll('label').forEach(function (l) { l.htmlFor = l.htmlFor.replace(/_\d+$/, '_' + n); l.textContent = l.textContent.replace(/część \d+/, 'część ' + (n + 1)); });
  rows.appendChild(c);
}
edokZusMieszana();
</script>
<?php endif; ?>
