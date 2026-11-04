<?php
/** Produkt puli: licencje, przydziały, formularz przydziału. Wejście: $p, $licenses, $assignments, $users, $clients, $free, $reveal, $revealId, $actor, $ctx. */
$title = $p['name'] . ' — pula licencji';
include __DIR__ . '/_head.php';
$today = date('Y-m-d');
$typeLabel = ['user' => 'Pracownik', 'client' => 'Uczestnik TI', 'other' => 'Inny'];
?>
<h1 class="h3 mb-1"><?= h($p['name']) ?></h1>
<p class="text-muted"><?= h(trim($p['vendor'] . ' ' . ($p['category'] !== '' ? '· ' . $p['category'] : ''))) ?>
  <?php if ($p['vendor_url'] !== ''): ?> · <a href="<?= h($p['vendor_url']) ?>" target="_blank" rel="noopener">strona produktu</a><?php endif; ?></p>

<?php if ($reveal !== null): ?>
<div class="alert alert-warning" role="alert">
  <strong>Klucz licencji nr <?= (int)$revealId ?>:</strong> <code class="user-select-all"><?= h($reveal) ?></code>
  <div class="small">Odsłonięcie zapisano w dzienniku audytu.</div>
</div>
<?php endif; ?>

<h2 class="h5">Licencje</h2>
<div class="table-responsive mb-4">
<table class="table table-sm align-middle"><caption class="visually-hidden">Licencje produktu</caption>
  <thead><tr><th scope="col">Nr</th><th scope="col">Klucz</th><th scope="col">Miejsca</th><th scope="col">Ważność</th><th scope="col">Uwaga</th><th scope="col">Status</th><th scope="col"></th></tr></thead>
  <tbody>
  <?php foreach ($licenses as $l):
      $exp = $l['valid_until'] && $l['valid_until'] < $today; ?>
    <tr<?= $l['status'] === 'retired' ? ' class="text-muted"' : '' ?>>
      <td><?= (int)$l['id'] ?></td><td class="font-monospace"><?= h($l['code_hint']) ?></td>
      <td><?= (int)$l['used'] ?> / <?= (int)$l['seats'] ?></td>
      <td><?= h($l['valid_until'] ?: '—') ?><?= $exp ? ' <span class="badge text-bg-danger">wygasła</span>' : '' ?></td>
      <td><?= h($l['note']) ?></td>
      <td><?= $l['status'] === 'retired' ? 'wycofana' : 'aktywna' ?></td>
      <td class="text-end text-nowrap">
        <form method="post" action="<?= h(route('lp.reveal', ['id' => $l['id']])) ?>" class="d-inline"><?= Router::csrfField() ?>
          <button class="btn btn-sm btn-outline-secondary" title="Pokaż klucz (wpis w audycie)">Pokaż klucz</button></form>
        <?php if ($l['status'] === 'active'): ?>
        <form method="post" action="<?= h(route('lp.retire', ['id' => $l['id']])) ?>" class="d-inline" onsubmit="return confirm('Wycofać licencję z puli? Przydziały zostają w historii.')"><?= Router::csrfField() ?>
          <button class="btn btn-sm btn-outline-danger">Wycofaj</button></form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; if (!$licenses): ?><tr><td colspan="7" class="text-muted">Brak licencji. <a href="<?= h(route('lp.import')) ?>">Zaimportuj z TXT.</a></td></tr><?php endif; ?>
  </tbody>
</table>
</div>

<div class="row g-4">
<div class="col-lg-5">
  <h2 class="h5">Przydziel licencję</h2>
  <?php if (!$free): ?><div class="alert alert-secondary">Brak wolnych miejsc na ważnych licencjach tego produktu.</div>
  <?php else: ?>
  <form method="post" action="<?= h(route('lp.assign')) ?>" class="border p-3" id="lpAssign">
    <?= Router::csrfField() ?><input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
    <div class="mb-2"><label class="form-label" for="ht">Odbiorca</label>
      <select id="ht" name="holder_type" class="form-select">
        <option value="user"<?= $ctx === 'it' ? ' selected' : '' ?>>Pracownik SZO</option>
        <option value="client"<?= $ctx === 'ti' ? ' selected' : '' ?>>Uczestnik TI</option>
        <option value="other">Inny odbiorca (wpisz)</option>
      </select></div>
    <div class="mb-2" data-for="user"><label class="form-label" for="hu">Pracownik</label>
      <select id="hu" name="holder_id" class="form-select"><option value="">— wybierz —</option>
        <?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>"><?= h($u['name']) ?></option><?php endforeach; ?></select></div>
    <div class="mb-2" data-for="client" hidden><label class="form-label" for="hc">Uczestnik TI</label>
      <select id="hc" class="form-select" disabled><option value="">— wybierz —</option>
        <?php foreach ($clients as $c): ?><option value="<?= (int)$c['id'] ?>"><?= h($c['name']) ?></option><?php endforeach; ?></select></div>
    <div class="mb-2" data-for="other" hidden><label class="form-label" for="ho">Odbiorca</label><input id="ho" name="holder_label" class="form-control" maxlength="190" disabled></div>
    <div data-for="client" hidden>
      <div class="row g-2 mb-2">
        <div class="col-6"><label class="form-label" for="bf">Forma rozliczenia</label>
          <select id="bf" name="billing_form" class="form-select" disabled><option value="">— wybierz —</option><option value="FAK">FAK — faktura</option><option value="FAZ">FAZ — faktura zbiorcza</option><option value="DSC">DSC — kod rabatowy</option><option value="GST">GST — Goodstack</option></select></div>
        <div class="col-6"><label class="form-label" for="br">Numer / kod</label><input id="br" name="billing_ref" class="form-control" maxlength="190" disabled></div>
      </div>
    </div>
    <div class="mb-2" data-for="other" hidden><label class="form-label" for="he">E-mail odbiorcy</label><input id="he" type="email" name="holder_email" class="form-control" maxlength="190" disabled></div>
    <div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="nt" name="notify" value="1" checked><label class="form-check-label" for="nt">Wyślij e-mail z kluczem i instrukcją aktywacji (adres z konta; uczestnik małoletni — opiekun)</label></div>
    <div class="mb-3"><label class="form-label" for="nn">Uwaga (wewnętrzna)</label><input id="nn" name="note" class="form-control" maxlength="300"></div>
    <button class="btn btn-primary">Przydziel</button>
    <div class="small text-muted mt-2">System wybierze licencję z wolnym miejscem (najpierw wygasającą najwcześniej).</div>
  </form>
  <script>
  (function(){
    var f=document.getElementById('lpAssign'), sel=f.querySelector('#ht');
    function sync(){
      var t=sel.value;
      f.querySelectorAll('[data-for]').forEach(function(b){
        var on=b.getAttribute('data-for')===t; b.hidden=!on;
        b.querySelectorAll('input,select').forEach(function(x){ x.disabled=!on; });
      });
      // pole holder_id ma dwie listy (pracownik / uczestnik) — nazwę dostaje tylko aktywna
      f.querySelector('#hu').name=(t==='user')?'holder_id':'';
      f.querySelector('#hc').name=(t==='client')?'holder_id':'';
    }
    sel.addEventListener('change',sync); sync();
  })();
  </script>
  <?php endif; ?>
</div>

<div class="col-lg-7">
  <h2 class="h5">Przydziały</h2>
  <div class="table-responsive">
  <table class="table table-sm align-middle"><caption class="visually-hidden">Przydziały licencji produktu</caption>
    <thead><tr><th scope="col">Odbiorca</th><th scope="col">Licencja</th><th scope="col">Przydzielono</th><th scope="col">Status</th><th scope="col"></th></tr></thead>
    <tbody>
    <?php foreach ($assignments as $a): $rev = (bool)$a['revoked_at']; ?>
      <tr<?= $rev ? ' class="text-muted"' : '' ?>>
        <td><?= h($a['holder_label']) ?> <span class="badge text-bg-light border"><?= h($typeLabel[$a['holder_type']] ?? '') ?></span></td>
        <td class="font-monospace"><?= h($a['code_hint']) ?></td>
        <td><?= h(substr((string)$a['assigned_at'], 0, 10)) ?><div class="small text-muted"><?= h($a['by_name'] ?? '') ?></div></td>
        <td><?= $a['mail_status'] !== '' ? '<div class="small text-muted">e-mail: ' . h($a['mail_status']) . ($a['mailed_to'] !== '' ? ' → ' . h($a['mailed_to']) : '') . '</div>' : '' ?>
        <?= $rev ? 'odebrana ' . h(substr((string)$a['revoked_at'], 0, 10)) . ($a['revoke_reason'] !== '' ? '<div class="small">' . h($a['revoke_reason']) . '</div>' : '') : 'aktywna' ?></td>
        <td class="text-end">
          <?php if (!$rev): ?>
          <form method="post" action="<?= h(route('lp.resend', ['id' => $a['id']])) ?>" class="d-inline"><?= Router::csrfField() ?>
            <button class="btn btn-sm btn-outline-secondary" title="Wyślij e-mail z kluczem ponownie">Wyślij ponownie</button></form>
          <form method="post" action="<?= h(route('lp.revoke', ['id' => $a['id']])) ?>" class="d-flex gap-1" onsubmit="return confirm('Odebrać licencję temu odbiorcy?')">
            <?= Router::csrfField() ?><label class="visually-hidden" for="rr<?= (int)$a['id'] ?>">Powód</label>
            <input id="rr<?= (int)$a['id'] ?>" name="reason" class="form-control form-control-sm" placeholder="powód" maxlength="300">
            <button class="btn btn-sm btn-outline-danger">Odbierz</button></form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; if (!$assignments): ?><tr><td colspan="5" class="text-muted">Brak przydziałów.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</div>
</div>

<details class="mt-4"><summary>Edytuj dane produktu</summary>
  <form method="post" action="<?= h(route('lp.product.save')) ?>" class="border p-3 mt-2">
    <?= Router::csrfField() ?><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
    <div class="row g-2">
      <div class="col-md-6"><label class="form-label" for="en">Nazwa</label><input id="en" name="name" class="form-control" value="<?= h($p['name']) ?>" required maxlength="190"></div>
      <div class="col-md-3"><label class="form-label" for="ev">Producent</label><input id="ev" name="vendor" class="form-control" value="<?= h($p['vendor']) ?>" maxlength="190"></div>
      <div class="col-md-3"><label class="form-label" for="ec">Kategoria</label><input id="ec" name="category" class="form-control" value="<?= h($p['category']) ?>" maxlength="190"></div>
      <div class="col-md-8"><label class="form-label" for="eu">Link</label><input id="eu" name="vendor_url" class="form-control" value="<?= h($p['vendor_url']) ?>" maxlength="500"></div>
      <div class="col-md-4 d-flex align-items-end"><div class="form-check"><input class="form-check-input" type="checkbox" id="ea" name="is_active" value="1"<?= $p['is_active'] ? ' checked' : '' ?>><label class="form-check-label" for="ea">Produkt aktywny</label></div></div>
      <div class="col-12"><label class="form-label" for="ei">Instrukcja aktywacji (trafia do e-maila z kluczem)</label><textarea id="ei" name="instructions" rows="3" class="form-control" maxlength="2000"><?= h($p['instructions']) ?></textarea></div>
      <div class="col-12"><label class="form-label" for="eo">Uwagi</label><input id="eo" name="notes" class="form-control" value="<?= h($p['notes']) ?>" maxlength="500"></div>
    </div>
    <button class="btn btn-primary mt-3">Zapisz</button>
  </form>
</details>
<?php include __DIR__ . '/_foot.php'; ?>
