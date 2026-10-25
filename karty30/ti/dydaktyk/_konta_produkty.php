<?php
/**
 * karty30/ti/dydaktyk/_konta_produkty.php — zakładka „Usługi i produkty” w panelu konta kursanta (konta.php):
 * domeny, hosting i inne usługi u dostawców współpracujących z fundacją, z numerami faktur „Nasza” i „Obca”.
 * Wejście: $sa (konto kursanta), $active_tab. Zapis: konta.php (_op product_save / product_delete).
 * Logika: modules/ti_products/logic/tiProducts.php (TiProducts).
 */
$tp_list  = TiProducts::forClient((int)$sa['client_id']);
$tp_edit  = !empty($_GET['pedit']) ? TiProducts::get((int)$_GET['pedit']) : null;
if ($tp_edit && (int)$tp_edit['client_id'] !== (int)$sa['client_id']) $tp_edit = null;
$tp_old   = $_SESSION['ti_product_old'] ?? null; unset($_SESSION['ti_product_old']);
$tp_fv    = ['product_type' => 'domain', 'provider' => '', 'provider_other' => '', 'name' => '', 'server_details' => '', 'expires_at' => '',
             'status' => 'active', 'invoice_own' => '', 'invoice_vendor' => '', 'notes' => '',
             'monthly_amount' => '', 'billing_day' => '', 'billing_form' => '', 'discount_type' => '', 'discount_value' => '', 'discount_note' => ''];
if ($tp_edit) foreach ($tp_fv as $k => $_) $tp_fv[$k] = (string)($tp_edit[$k] ?? '');
if (is_array($tp_old)) foreach ($tp_fv as $k => $_) if (isset($tp_old[$k])) $tp_fv[$k] = (string)$tp_old[$k];
$tp_open  = $tp_edit || !empty($_GET['pnew']);
$tp_by_type = [];
foreach ($tp_list as $p) $tp_by_type[$p['product_type']][] = $p;
?>
<div class="tab-pane fade <?= $active_tab === 'produkty' ? 'show active' : '' ?>" id="tab-produkty" role="tabpanel" aria-labelledby="tab-btn-produkty" tabindex="0">
  <div class="d-flex align-items-center flex-wrap gap-2 mb-2">
    <div class="small fw-semibold text-body-secondary">Usługi i produkty <span class="fw-normal">(domeny, hosting i inne — u dostawców współpracujących z fundacją)</span></div>
    <?php if (!$tp_open): ?><a class="btn btn-sm btn-outline-success ms-auto" href="konta.php?selected=<?= (int)$sa['id'] ?>&amp;ptab=produkty&amp;pnew=1#tab-produkty"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Dodaj produkt</a><?php endif; ?>
  </div>

  <?php if (!$tp_list): ?>
  <p class="text-body-secondary small mb-2">Brak usług i produktów.</p>
  <?php else: foreach (TiProducts::TYPES as $tk => $tmeta): if (empty($tp_by_type[$tk])) continue; ?>
  <h3 class="h6 fw-semibold mt-2 mb-1"><i class="bi <?= h($tmeta['icon']) ?> me-1" aria-hidden="true"></i><?= h(['other' => 'Inne usługi', 'domain' => 'Domeny', 'hosting' => 'Hosting', 'license' => 'Licencje'][$tk] ?? $tmeta['label']) ?> <span class="badge text-bg-light border"><?= count($tp_by_type[$tk]) ?></span></h3>
  <div class="table-responsive mb-2"><table class="table table-sm align-middle small mb-0">
    <caption class="visually-hidden"><?= h($tmeta['label']) ?> — produkty kursanta</caption>
    <thead class="table-light"><tr>
      <th scope="col"><?= h($tmeta['name_label']) ?></th><th scope="col">Dostawca</th>
      <?php if ($tk !== 'domain'): ?><th scope="col">Pakiet / serwer</th><?php endif; ?>
      <th scope="col">Wygasa</th><th scope="col">Status</th><th scope="col">Faktura nasza</th><th scope="col">Faktura obca</th><th scope="col"><span class="visually-hidden">Akcje</span></th>
    </tr></thead>
    <tbody><?php foreach ($tp_by_type[$tk] as $p): $st = TiProducts::STATUSES[$p['status']] ?? TiProducts::STATUSES['active']; $hint = TiProducts::dateHint($p); $dl = TiProducts::daysLeft($p); ?>
      <tr<?= $p['status'] === 'archived' ? ' class="text-body-secondary"' : '' ?>>
        <th scope="row" class="fw-semibold<?= $tk === 'domain' ? ' font-monospace' : '' ?>"><?= h($p['name']) ?><?php $pam = TiLicencjeManager::amounts($p); if ($pam): ?> <span class="badge text-bg-light border fw-normal" title="Rozliczenie miesięczne<?= $pam[0] !== $pam[1] ? ': po rabacie (cena ' . number_format($pam[0], 2, ',', ' ') . ' zł)' : '' ?>"><?= number_format($pam[1], 2, ',', ' ') ?> zł · <?= (int)$p['billing_day'] ?>. dnia<?= $pam[0] !== $pam[1] ? ' · rabat' : '' ?></span><?php endif; ?><?php if ($p['notes'] !== ''): ?><div class="fw-normal text-body-secondary font-sans-serif"><?= h($p['notes']) ?></div><?php endif; ?></th>
        <td><?= h(TiProducts::providerName($p)) ?></td>
        <?php if ($tk !== 'domain'): ?><td><?= h($p['server_details'] ?: '—') ?></td><?php endif; ?>
        <td class="text-nowrap"><?= $p['expires_at'] ? h(date('d.m.Y', strtotime((string)$p['expires_at']))) : '—' ?>
          <?php if ($hint === 'overdue'): ?><div class="text-danger fw-semibold">po terminie</div><?php elseif ($hint === 'soon'): ?><div class="text-warning-emphasis fw-semibold">za <?= (int)$dl ?> <?= $dl === 1 ? 'dzień' : 'dni' ?></div><?php endif; ?></td>
        <td><span class="badge <?= h($st['cls']) ?>"><i class="bi <?= h($st['icon']) ?> me-1" aria-hidden="true"></i><?= h($st['label']) ?></span></td>
        <td class="font-monospace"><?= h($p['invoice_own'] ?: '—') ?></td>
        <td class="font-monospace"><?= h($p['invoice_vendor'] ?: '—') ?></td>
        <td class="text-end text-nowrap">
          <a class="btn btn-sm btn-outline-secondary py-0 px-2" href="konta.php?selected=<?= (int)$sa['id'] ?>&amp;ptab=produkty&amp;pedit=<?= (int)$p['id'] ?>#tab-produkty" title="Edytuj"><i class="bi bi-pencil" aria-hidden="true"></i><span class="visually-hidden">Edytuj <?= h($p['name']) ?></span></a>
          <form method="post" class="d-inline" onsubmit="return confirm('Usunąć produkt „<?= h(addslashes($p['name'])) ?>”? Zamiast usuwać możesz ustawić status „Zarchiwizowany”.')">
            <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>"><input type="hidden" name="_op" value="product_delete">
            <input type="hidden" name="account_id" value="<?= (int)$sa['id'] ?>"><input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
            <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-2" title="Usuń"><i class="bi bi-trash" aria-hidden="true"></i><span class="visually-hidden">Usuń <?= h($p['name']) ?></span></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <?php endforeach; endif; ?>

  <details class="small mt-2"><summary>Powiadomienia: kopia do administratora i przypomnienia o wygasających domenach i hostingu</summary>
    <form method="post" class="d-flex flex-wrap gap-3 align-items-end mt-2">
      <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>"><input type="hidden" name="_op" value="product_admin_email"><input type="hidden" name="account_id" value="<?= (int)$sa['id'] ?>">
      <div><label class="form-label small mb-0" for="tp-adm">Kopia do administratora — adres(y) e-mail, po przecinku</label>
        <input class="form-control form-control-sm" id="tp-adm" name="admin_email" value="<?= h(org_setting('ti_products_admin_email')) ?>" style="min-width:320px" placeholder="puste = konta z rolą admin"></div>
      <div class="form-check mb-1"><input class="form-check-input" type="checkbox" id="tp-rem-on" name="rem_enabled" value="1"<?= TiProductReminders::enabled() ? ' checked' : '' ?>>
        <label class="form-check-label" for="tp-rem-on">Przypominaj o wygasających domenach i hostingu (codziennie o 8:00)</label></div>
      <div><label class="form-label small mb-0" for="tp-rem-days">Ile dni przed wygaśnięciem</label>
        <input class="form-control form-control-sm" id="tp-rem-days" name="rem_days" value="<?= h(implode(', ', TiProductReminders::days())) ?>" style="width:160px" aria-describedby="tp-rem-h"></div>
      <button class="btn btn-sm btn-outline-primary">Zapisz</button>
    </form>
    <div class="form-text" id="tp-rem-h">Kursant (małoletni — także opiekun) dostaje e-mail przy każdym progu raz na dany termin; 0 = w dniu wygaśnięcia. Administrator dostaje jedno zestawienie dziennie. Teraz kopia trafia do: <?= h(implode(', ', TiProducts::adminEmails()) ?: 'nikogo — brak adresu') ?>.</div>
  </details>

  <?php if ($tp_open): ?>
  <form method="post" class="border rounded p-3 mt-2" id="tp-form" style="max-width:860px">
    <h3 class="h6 fw-bold mb-3"><?= $tp_edit ? 'Edytuj produkt: ' . h($tp_edit['name']) : 'Nowy produkt' ?></h3>
    <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>"><input type="hidden" name="_op" value="product_save">
    <input type="hidden" name="account_id" value="<?= (int)$sa['id'] ?>"><input type="hidden" name="product_id" value="<?= $tp_edit ? (int)$tp_edit['id'] : 0 ?>">
    <fieldset class="mb-3"><legend class="form-label small fw-semibold mb-1">Typ produktu <span class="text-danger" aria-hidden="true">*</span></legend>
      <div class="btn-group flex-wrap" role="group">
        <?php foreach (TiProducts::TYPES as $tk => $tmeta): ?>
        <input type="radio" class="btn-check" name="product_type" id="tp-t-<?= h($tk) ?>" value="<?= h($tk) ?>" data-name-label="<?= h($tmeta['name_label']) ?>" data-name-ph="<?= h($tmeta['name_ph']) ?>"<?= $tp_fv['product_type'] === $tk ? ' checked' : '' ?> required>
        <label class="btn btn-outline-primary btn-sm" for="tp-t-<?= h($tk) ?>"><i class="bi <?= h($tmeta['icon']) ?> me-1" aria-hidden="true"></i><?= h($tmeta['label']) ?></label>
        <?php endforeach; ?>
      </div>
    </fieldset>
    <div class="row g-2">
      <div class="col-md-5"><label class="form-label small mb-0" for="tp-name"><span id="tp-name-lbl">Nazwa</span> <span class="text-danger" aria-hidden="true">*</span></label>
        <input class="form-control form-control-sm" id="tp-name" name="name" value="<?= h($tp_fv['name']) ?>" maxlength="190" required autocomplete="off"></div>
      <div class="col-md-4"><label class="form-label small mb-0" for="tp-prov">Dostawca <span class="text-danger" aria-hidden="true">*</span></label>
        <select class="form-select form-select-sm" id="tp-prov" name="provider" required>
          <option value="">— wybierz —</option>
          <?php foreach (TiProducts::PROVIDERS as $pk => $pn): ?><option value="<?= h($pk) ?>"<?= $tp_fv['provider'] === $pk ? ' selected' : '' ?>><?= h($pn) ?></option><?php endforeach; ?>
        </select></div>
      <div class="col-md-3" id="tp-prov-o-box"><label class="form-label small mb-0" for="tp-prov-o">Nazwa dostawcy</label>
        <input class="form-control form-control-sm" id="tp-prov-o" name="provider_other" value="<?= h($tp_fv['provider_other']) ?>" maxlength="100"></div>
      <div class="col-md-8" id="tp-srv-box"><label class="form-label small mb-0" for="tp-srv">Pakiet / serwer / IP</label>
        <input class="form-control form-control-sm" id="tp-srv" name="server_details" value="<?= h($tp_fv['server_details']) ?>" maxlength="500" placeholder="np. MyDevil Pro, s12.mydevil.net, 128.204.218.1"></div>
      <div class="col-md-3"><label class="form-label small mb-0" for="tp-exp">Wygasa / opłacone do</label>
        <input type="date" class="form-control form-control-sm" id="tp-exp" name="expires_at" value="<?= h($tp_fv['expires_at']) ?>"></div>
      <div class="col-md-3"><label class="form-label small mb-0" for="tp-st">Status</label>
        <select class="form-select form-select-sm" id="tp-st" name="status">
          <?php foreach (TiProducts::STATUSES as $sk => $sm): ?><option value="<?= h($sk) ?>"<?= $tp_fv['status'] === $sk ? ' selected' : '' ?>><?= h($sm['label']) ?></option><?php endforeach; ?>
        </select></div>
      <div class="col-md-3"><label class="form-label small mb-0" for="tp-inv-own">Nr faktury — nasza</label>
        <input class="form-control form-control-sm font-monospace" id="tp-inv-own" name="invoice_own" value="<?= h($tp_fv['invoice_own']) ?>" maxlength="100" aria-describedby="tp-inv-own-h">
        <div class="form-text" id="tp-inv-own-h">wystawiona przez fundację</div></div>
      <div class="col-md-3"><label class="form-label small mb-0" for="tp-inv-ven">Nr faktury — obca</label>
        <input class="form-control form-control-sm font-monospace" id="tp-inv-ven" name="invoice_vendor" value="<?= h($tp_fv['invoice_vendor']) ?>" maxlength="100" aria-describedby="tp-inv-ven-h">
        <div class="form-text" id="tp-inv-ven-h">od dostawcy</div></div>
      <div class="col-12"><fieldset class="border rounded p-2"><legend class="float-none w-auto px-1 fs-6 fw-semibold mb-1">Rozliczenie miesięczne <span class="fw-normal text-body-secondary">(opcjonalne<?= !empty($sa['licenses_only']) ? ' — wymagane na koncie „tylko rozliczenie licencji”' : '' ?>)</span></legend>
        <div class="row g-2">
          <div class="col-6 col-md-2"><label class="form-label small mb-0" for="tp-amt">Kwota mies. (zł)</label>
            <input class="form-control form-control-sm" id="tp-amt" name="monthly_amount" inputmode="decimal" value="<?= h($tp_fv['monthly_amount']) ?>" placeholder="np. 49,00"<?= !empty($sa['licenses_only']) ? ' required' : '' ?>></div>
          <div class="col-6 col-md-2"><label class="form-label small mb-0" for="tp-day">Dzień rozliczenia</label>
            <input type="number" min="1" max="31" class="form-control form-control-sm" id="tp-day" name="billing_day" value="<?= h($tp_fv['billing_day']) ?>" placeholder="dziś"></div>
          <div class="col-6 col-md-2"><label class="form-label small mb-0" for="tp-bf">Forma rozliczenia</label>
            <select class="form-select form-select-sm" id="tp-bf" name="billing_form"><option value="">— wybierz —</option>
              <?php foreach (TiLicencjeManager::BILLING_FORMS as $bfc => $bfm): ?><option value="<?= h($bfc) ?>"<?= $tp_fv['billing_form'] === $bfc ? ' selected' : '' ?>><?= h($bfc . ' — ' . $bfm['label']) ?></option><?php endforeach; ?></select></div>
          <div class="col-6 col-md-2"><label class="form-label small mb-0" for="tp-dt">Rabat</label>
            <select class="form-select form-select-sm" id="tp-dt" name="discount_type"><option value="">brak</option>
              <option value="pct"<?= $tp_fv['discount_type'] === 'pct' ? ' selected' : '' ?>>%</option><option value="amt"<?= $tp_fv['discount_type'] === 'amt' ? ' selected' : '' ?>>zł</option></select></div>
          <div class="col-6 col-md-1"><label class="form-label small mb-0" for="tp-dv">Wartość</label>
            <input class="form-control form-control-sm" id="tp-dv" name="discount_value" inputmode="decimal" value="<?= h($tp_fv['discount_value']) ?>"></div>
          <div class="col-6 col-md-3"><label class="form-label small mb-0" for="tp-dn">Opis rabatu</label>
            <input class="form-control form-control-sm" id="tp-dn" name="discount_note" maxlength="120" value="<?= h($tp_fv['discount_note']) ?>" placeholder="np. rabat edukacyjny"></div>
        </div>
        <div class="form-text">Przy nowej pozycji z kwotą kursant (lub opiekun) dostanie e-mail i SMS z kwotą, formą i dniem rozliczenia, a co miesiąc przypomnienie dzień przed terminem.</div>
      </fieldset></div>
      <div class="col-12"><label class="form-label small mb-0" for="tp-notes">Uwagi</label>
        <input class="form-control form-control-sm" id="tp-notes" name="notes" value="<?= h($tp_fv['notes']) ?>" maxlength="1000"></div>
    </div>
    <p class="form-text mb-2">Nie wpisuj tu haseł ani kodów AuthInfo — tych danych nie przechowujemy.</p>
    <?php if (!$tp_edit): ?>
    <div class="form-check mb-2">
      <input class="form-check-input" type="checkbox" id="tp-notify" name="notify" value="1"<?= !is_array($tp_old) || !empty($tp_old['notify']) ? ' checked' : '' ?>>
      <label class="form-check-label small" for="tp-notify">Wyślij e-mail „uruchomiono w Twoim imieniu nową usługę” do kursanta (małoletni — także do opiekuna) i kopię do administratora</label>
    </div>
    <?php endif; ?>
    <div class="d-flex gap-2">
      <button class="btn btn-sm btn-primary"><i class="bi bi-save me-1" aria-hidden="true"></i><?= $tp_edit ? 'Zapisz zmiany' : 'Dodaj produkt' ?></button>
      <a class="btn btn-sm btn-outline-secondary" href="konta.php?selected=<?= (int)$sa['id'] ?>&amp;ptab=produkty#tab-produkty">Anuluj</a>
    </div>
  </form>
  <script>
  (function () {   // pola zależne od typu (etykieta nazwy, pakiet/serwer tylko poza domeną) i od dostawcy („Inny” → nazwa)
    var f = document.getElementById('tp-form'); if (!f) return;
    var prov = document.getElementById('tp-prov'), provBox = document.getElementById('tp-prov-o-box'), provIn = document.getElementById('tp-prov-o');
    function sync() {
      var t = f.querySelector('input[name=product_type]:checked');
      if (t) { document.getElementById('tp-name-lbl').textContent = t.dataset.nameLabel; document.getElementById('tp-name').placeholder = t.dataset.namePh;
               document.getElementById('tp-srv-box').hidden = t.value === 'domain'; }
      provBox.hidden = prov.value !== 'other'; provIn.required = prov.value === 'other';
    }
    f.querySelectorAll('input[name=product_type]').forEach(function (r) { r.addEventListener('change', sync); });
    prov.addEventListener('change', sync); sync();
  })();
  </script>
  <?php endif; ?>
</div>
