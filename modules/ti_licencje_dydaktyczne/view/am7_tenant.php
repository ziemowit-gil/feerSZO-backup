<?php
/**
 * modules/ti_licencje_dydaktyczne/view/am7_tenant.php — „Utwórz konto w tenancie” dla przyznanego wniosku AM7:
 * wybór tenanta (FEER / EDU FEER), dane osoby (braki trzeba uzupełnić PRZED wysyłką), licencja w tenancie, sposób przekazania hasła.
 * Zmienne: $l, $c (dane kontaktowe), $missing, $tenants, $skus (['feer'=>[…],'edu'=>[…]]), $errors, $old, $actor.
 */
require __DIR__ . '/_parts.php';
$title = 'Konto w tenancie — ' . $l['case_number'];
$kierLabel = $title;
include __DIR__ . '/_head.php';
$v = fn(string $k) => h((string)($old[$k] ?? ($c[$k] ?? '')));
$isMissing = fn(string $k) => in_array($k, $missing, true);
$plan = (string)(($l['decision']['plan'] ?? '') ?: ($l['request']['plan'] ?? '') ?: $l['title']);
$sel = (string)($old['tenant'] ?? '');
?>
<div class="pivot"><a href="<?= h(route('ld.index')) ?>">Pulpit</a><a href="<?= h(route('ld.show', ['id' => $l['id']])) ?>"><?= h($l['case_number']) ?></a><a href="<?= h(route('ld.am7.tenant', ['id' => $l['id']])) ?>" aria-current="page">Konto w tenancie</a></div>
<h1><span class="sym" style="background:#0050ef">AM7</span> Utwórz konto w tenancie</h1>
<p class="text-secondary">Usługa: <b><?= h($plan) ?></b> · osoba: <b><?= h($l['interested_name']) ?></b>. Po utworzeniu: e-mail z loginem i instrukcją aktywacji (kopia do administratora) oraz osobny SMS z hasłem; sprawa dostaje przydział (Przydzielona), a zlecenie Helpdesku etap „Utworzono konto”.</p>
<?php if ($errors): ?><div class="alert alert-danger" role="alert"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php if ($missing && !$errors): ?><div class="alert alert-warning" role="alert"><b>Brakuje danych potrzebnych do wysyłki</b> — uzupełnij zaznaczone pola (zapiszemy je też w sprawie i w rekordzie kursanta).</div><?php endif; ?>

<form method="post" action="<?= h(route('ld.am7.tenant', ['id' => $l['id']])) ?>" novalidate>
<?= Router::csrfField() ?>
<section class="panel accent">
  <h2 class="mt-0">1. Tenant</h2>
  <div class="row g-2">
    <?php foreach ($tenants as $k => $t): ?>
    <div class="col-md-6"><div class="form-check">
      <input class="form-check-input" type="radio" name="tenant" id="tn-<?= h($k) ?>" value="<?= h($k) ?>"<?= $sel === $k ? ' checked' : '' ?><?= $t['ready'] ? '' : ' disabled' ?>>
      <label class="form-check-label" for="tn-<?= h($k) ?>"><b><?= h($t['label']) ?></b><?= $t['ready'] ? '' : ' <span class="text-danger">— nieskonfigurowany</span>' ?></label>
    </div></div>
    <?php endforeach; ?>
  </div>
</section>

<section class="panel">
  <h2 class="mt-0">2. Dane osoby</h2>
  <div class="row g-3">
    <div class="col-md-4"><label class="form-label" for="name">Imię i nazwisko<?= $isMissing('name') ? ' <span class="text-danger">— uzupełnij</span>' : '' ?></label>
      <input class="form-control<?= $isMissing('name') ? ' is-invalid' : '' ?>" id="name" name="name" value="<?= $v('name') ?>" required></div>
    <div class="col-md-4"><label class="form-label" for="email">E-mail (na niego pójdzie login)<?= $isMissing('email') ? ' <span class="text-danger">— uzupełnij</span>' : '' ?></label>
      <input class="form-control<?= $isMissing('email') ? ' is-invalid' : '' ?>" type="email" id="email" name="email" value="<?= $v('email') ?>" required></div>
    <div class="col-md-4"><label class="form-label" for="phone">Telefon (na niego pójdzie hasło w SMS-ie)<?= $isMissing('phone') ? ' <span class="text-danger">— uzupełnij</span>' : '' ?></label>
      <input class="form-control<?= $isMissing('phone') ? ' is-invalid' : '' ?>" type="tel" id="phone" name="phone" value="<?= $v('phone') ?>" inputmode="tel"></div>
  </div>
  <div class="form-check mt-2"><input class="form-check-input" type="checkbox" id="no_sms" name="no_sms" value="1"<?= !empty($old['no_sms']) ? ' checked' : '' ?>>
    <label class="form-check-label" for="no_sms">Nie wysyłaj hasła SMS-em — przekażę je sam(a) (hasło pokażemy raz po utworzeniu konta)</label></div>
</section>

<section class="panel">
  <h2 class="mt-0">3. Licencja w tenancie <span class="fw-normal text-secondary">(opcjonalnie)</span></h2>
  <?php foreach ($tenants as $k => $t): ?>
  <div data-ld-tenant="<?= h($k) ?>" hidden>
    <label class="form-label" for="sku-<?= h($k) ?>">Licencja nadana przy tworzeniu konta (<?= h($k === 'feer' ? 'FEER' : 'EDU FEER') ?>)</label>
    <select class="form-select" id="sku-<?= h($k) ?>" data-name="sku">
      <option value="">— nie nadawaj (nadam ręcznie) —</option>
      <?php foreach ($skus[$k] ?? [] as $s): ?><option value="<?= h($s['id']) ?>"<?= (string)($old['sku'] ?? '') === $s['id'] ? ' selected' : '' ?>><?= h($s['name']) ?> — wolnych: <?= (int)$s['free'] ?></option><?php endforeach; ?>
    </select>
    <?php if (empty($skus[$k])): ?><div class="form-text">Nie udało się pobrać listy licencji tego tenanta (albo brak subskrypcji) — licencję nadasz ręcznie.</div><?php endif; ?>
  </div>
  <?php endforeach; ?>
  <input type="hidden" name="sku" id="skuVal" value="<?= h((string)($old['sku'] ?? '')) ?>">
</section>

<div class="d-flex gap-2 mb-4">
  <button class="btn btn-metro green" onclick="return confirm('Utworzyć konto w wybranym tenancie i wysłać dane dostępowe?')">Utwórz konto i wyślij dane</button>
  <a class="btn btn-metro dark" href="<?= h(route('ld.show', ['id' => $l['id']])) ?>">Anuluj</a>
</div>
</form>
<script>
(function () {
  var radios = document.querySelectorAll('input[name="tenant"]'), val = document.getElementById('skuVal');
  function sync() {
    var cur = (document.querySelector('input[name="tenant"]:checked') || {}).value || '';
    document.querySelectorAll('[data-ld-tenant]').forEach(function (b) {
      var on = b.getAttribute('data-ld-tenant') === cur; b.hidden = !on;
      b.querySelectorAll('select').forEach(function (s) { s.disabled = !on; if (on) val.value = s.value; });
    });
  }
  document.querySelectorAll('[data-ld-tenant] select').forEach(function (s) { s.addEventListener('change', function () { val.value = s.value; }); });
  radios.forEach(function (r) { r.addEventListener('change', sync); }); sync();
})();
</script>
<?php include __DIR__ . '/_foot.php'; ?>
