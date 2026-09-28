<?php
/**
 * edok/_zaplata_fields.php — pola „Rozlicza proformę” + „Faktura już zapłacona”
 * (kto zapłacił, zwrot kosztów), wspólne dla edok/add.php i formularza edycji
 * w edok/view.php. Walidacja po stronie serwera: edok_proforma_from_post() +
 * edok_zaplata_from_post() w includes/edok.php.
 *
 * Wejście:
 *   $zp_vals    — bieżące wartości (wiersz edok_documents albo $_POST)
 *   $zp_locked  — true = tylko do odczytu (po etapie „rachunkowa”)
 *   $zp_doc_id  — id edytowanego dokumentu (null w add.php)
 *   $zp_typ_el  — id selecta typu dokumentu (add.php) albo null, gdy typ stały ($zp_typ)
 *   $zp_typ     — typ dokumentu, gdy nie ma selecta
 */
$zp_vals   = $zp_vals ?? [];
$zp_locked = !empty($zp_locked);
$zp_doc_id = $zp_doc_id ?? null;
$zp_typ_el = $zp_typ_el ?? null;
$zp_typ    = $zp_typ ?? '';
$zp_dis    = $zp_locked ? 'disabled' : '';
$zp_on     = !empty($zp_vals['zaplacono_przed']) && ($zp_vals['forma_zaplaty'] ?? '') !== EDOK_FORMA_PROFORMA;
$zp_osoba  = ($zp_vals['zaplacil'] ?? '') === 'osoba';
$zp_pf_id  = (int)($zp_vals['proforma_id'] ?? 0);
$zp_pfs    = edok_proformy_do_rozliczenia($zp_pf_id ?: null, $zp_doc_id);
?>
<div id="zp_wrap" class="mb-3">
  <?php if ($zp_locked): ?>
  <div class="small text-muted mb-1"><i class="bi bi-lock-fill"></i> Dane zapłaty zablokowane po kontroli rachunkowej.</div>
  <?php endif; ?>

  <div id="zp_proforma_info" class="alert alert-info py-2 small mb-2" style="display:none">
    <i class="bi bi-info-circle"></i> Proforma nie jest dokumentem księgowym. Przejdzie obieg jako podstawa zapłaty i trafi do eksportu przelewów.
    Gdy przyjdzie faktura końcowa, dodaj ją w EODoK i wskaż tę proformę w polu „Rozlicza proformę” — faktura nie zostanie zapłacona drugi raz.
  </div>

  <div id="zp_proforma_wrap" class="border rounded p-2 mb-2" style="display:none">
    <label class="form-label small fw-semibold mb-1" for="zp_proforma_id">Rozlicza proformę (zapłaconą wcześniej)</label>
    <select name="proforma_id" id="zp_proforma_id" class="form-select form-select-sm" <?= $zp_dis ?> onchange="edokZpSync(true)">
      <option value="">— nie dotyczy —</option>
      <?php foreach ($zp_pfs as $p): ?>
      <option value="<?= (int)$p['id'] ?>" <?= $zp_pf_id === (int)$p['id'] ? 'selected' : '' ?>
        data-nazwa="<?= h($p['kontrahent_nazwa']) ?>" data-nip="<?= h($p['kontrahent_nip']) ?>">
        <?= h($p['number']) ?> — <?= h($p['kontrahent_nazwa']) ?>, nr <?= h($p['nr_faktury']) ?>, <?= h($p['kwota_brutto']) ?> <?= h($p['waluta']) ?>
        <?= ($p['status_platnosci'] ?? '') === 'oplacony' ? '· opłacona' : '· ' . h(EDOK_STATUSES[$p['status']]['label'] ?? $p['status']) ?>
      </option>
      <?php endforeach; ?>
    </select>
    <div class="form-text">Faktura końcowa do proformy zostanie oznaczona jako zapłacona na podstawie proformy — nie trafi do eksportu przelewów.</div>
  </div>

  <div id="zp_zaplata_wrap" class="border rounded p-2 bg-light">
    <div class="form-check">
      <input type="checkbox" class="form-check-input" name="zaplacono_przed" id="zp_zaplacono" value="1" <?= $zp_on ? 'checked' : '' ?> <?= $zp_dis ?> onchange="edokZpSync()">
      <label class="form-check-label" for="zp_zaplacono">Faktura już zapłacona (np. kartą, gotówką) — akceptacja po zapłacie</label>
    </div>
    <div id="zp_zaplata_fields" class="mt-2" style="<?= $zp_on ? '' : 'display:none' ?>">
      <div class="row g-2">
        <div class="col-sm-4">
          <label class="form-label small mb-1" for="zp_data">Data zapłaty</label>
          <input type="date" name="data_zaplaty" id="zp_data" class="form-control form-control-sm" max="<?= date('Y-m-d') ?>" value="<?= h($zp_vals['data_zaplaty'] ?? '') ?>" <?= $zp_dis ?>>
        </div>
        <div class="col-sm-5">
          <label class="form-label small mb-1" for="zp_forma">Forma zapłaty</label>
          <select name="forma_zaplaty" id="zp_forma" class="form-select form-select-sm" <?= $zp_dis ?>>
            <option value="">— wybierz —</option>
            <?php foreach (EDOK_FORMY_ZAPLATY as $k => $l): ?>
            <option value="<?= h($k) ?>" <?= ($zp_vals['forma_zaplaty'] ?? '') === $k ? 'selected' : '' ?>><?= h($l) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="mt-2">
        <span class="form-label small d-block mb-1">Kto zapłacił?</span>
        <div class="form-check form-check-inline">
          <input type="radio" class="form-check-input" name="zaplacil" id="zp_org" value="organizacja" <?= !$zp_osoba ? 'checked' : '' ?> <?= $zp_dis ?> onchange="edokZpSync()">
          <label class="form-check-label small" for="zp_org">Organizacja (rachunek / karta służbowa)</label>
        </div>
        <div class="form-check form-check-inline">
          <input type="radio" class="form-check-input" name="zaplacil" id="zp_osoba" value="osoba" <?= $zp_osoba ? 'checked' : '' ?> <?= $zp_dis ?> onchange="edokZpSync()">
          <label class="form-check-label small" for="zp_osoba">Osoba z prywatnych środków — do zwrotu</label>
        </div>
      </div>
      <div class="row g-2 mt-1" id="zp_zwrot_fields" style="<?= $zp_osoba ? '' : 'display:none' ?>">
        <div class="col-sm-5">
          <label class="form-label small mb-1" for="zp_zwrot_osoba">Odbiorca zwrotu</label>
          <input type="text" name="zwrot_osoba" id="zp_zwrot_osoba" class="form-control form-control-sm" maxlength="120" value="<?= h($zp_vals['zwrot_osoba'] ?? '') ?>" placeholder="Imię i nazwisko" <?= $zp_dis ?>>
        </div>
        <div class="col-sm-7">
          <label class="form-label small mb-1" for="zp_zwrot_rachunek">Rachunek do zwrotu</label>
          <input type="text" name="zwrot_rachunek" id="zp_zwrot_rachunek" class="form-control form-control-sm font-monospace" maxlength="40" value="<?= h(edok_nrb_format((string)($zp_vals['zwrot_rachunek'] ?? ''))) ?>" placeholder="26 cyfr" <?= $zp_dis ?>>
        </div>
      </div>
      <div class="form-text mt-1" id="zp_hint_org">Po akceptacji dokument dostanie status płatności „Opłacony” i nie trafi do eksportu przelewów.</div>
      <div class="form-text mt-1" id="zp_hint_osoba" style="display:none">Po akceptacji dokument trafi do eksportu przelewów jako <strong>zwrot kosztów</strong> na rachunek osoby, która zapłaciła.</div>
    </div>
  </div>
</div>
<script>
function edokZpTyp() {
  <?php if ($zp_typ_el): ?>
  var el = document.getElementById(<?= json_encode($zp_typ_el) ?>);
  return el ? el.value : '';
  <?php else: ?>
  return <?= json_encode($zp_typ) ?>;
  <?php endif; ?>
}
function edokZpSync(fromProforma) {
  var typ = edokZpTyp();
  var pfSel = document.getElementById('zp_proforma_id');
  var mozeRozliczac = typ === 'faktura_vat' || typ === 'rachunek';
  document.getElementById('zp_proforma_info').style.display = typ === 'proforma' ? '' : 'none';
  document.getElementById('zp_proforma_wrap').style.display = mozeRozliczac ? '' : 'none';
  if (!mozeRozliczac) pfSel.value = '';
  var zProformy = !!pfSel.value;
  document.getElementById('zp_zaplata_wrap').style.display = zProformy ? 'none' : '';
  var on = document.getElementById('zp_zaplacono').checked;
  var osoba = document.getElementById('zp_osoba').checked;
  document.getElementById('zp_zaplata_fields').style.display = on ? '' : 'none';
  document.getElementById('zp_zwrot_fields').style.display = osoba ? '' : 'none';
  document.getElementById('zp_hint_org').style.display = osoba ? 'none' : '';
  document.getElementById('zp_hint_osoba').style.display = osoba ? '' : 'none';
  // Wybór proformy podpowiada kontrahenta, jeśli pola są jeszcze puste.
  if (fromProforma && zProformy) {
    var o = pfSel.selectedOptions[0];
    [['kontrahent_nazwa', o.dataset.nazwa], ['kontrahent_nip', o.dataset.nip]].forEach(function (p) {
      var f = document.querySelector('[name="' + p[0] + '"]');
      if (f && !f.value && !f.readOnly && p[1]) f.value = p[1];
    });
  }
}
<?php if ($zp_typ_el): ?>
document.getElementById(<?= json_encode($zp_typ_el) ?>).addEventListener('change', function () { edokZpSync(); });
<?php endif; ?>
edokZpSync();
</script>
