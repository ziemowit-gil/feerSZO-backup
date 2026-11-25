<?php
/**
 * modules/kontoBezosobowe/view/index.php — konto bez umowy, bezosobowe.
 * Zmienne: $accounts, $ready, $domain, $fresh (ostatnio nadane dane z hasłem albo null), $flash.
 */
$PAGE_TITLE = 'Konto bez umowy, bezosobowe';
include dirname(__DIR__, 3) . '/includes/header.php';
$fmt = fn($d) => $d ? date('d.m.Y H:i', (int)strtotime((string)$d)) : '';
?>
<style>
  .kbo-pass { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 1.05rem; letter-spacing: .03em; word-break: break-all; }
  .kbo-wrap :focus-visible { outline: 3px solid #1d2939; outline-offset: 2px; }
  .kbo-new { border: 2px solid #0f6fa8; border-radius: 8px; background: #f5faff; }
</style>

<div class="container-fluid kbo-wrap py-3" style="max-width: 1100px">
  <h1 class="h3 mb-1"><i class="bi bi-person-badge" aria-hidden="true"></i> Konto bez umowy, bezosobowe</h1>
  <p class="text-secondary mb-3">Skrzynka pocztowa <b>boXXXX@<?= h($domain ?: 'domena') ?></b> z losowymi cyframi i hasłem o długości 20 znaków, np. dla recepcji, projektu albo urządzenia.</p>

  <?php if ($flash): ?>
    <div class="alert alert-<?= h($flash['type']) ?> small" role="<?= $flash['type'] === 'success' ? 'status' : 'alert' ?>"><?= h($flash['msg']) ?></div>
  <?php endif; ?>

  <?php if ($fresh): ?>
    <section id="wynik" class="kbo-new p-3 mb-4" aria-labelledby="kboNewH">
      <h2 id="kboNewH" class="h5">Dane logowania: <?= h($fresh['label']) ?></h2>
      <dl class="row mb-2">
        <dt class="col-sm-3">Adres e-mail i login</dt><dd class="col-sm-9"><?= h($fresh['mailbox']) ?></dd>
        <dt class="col-sm-3">Hasło</dt><dd class="col-sm-9 kbo-pass"><?= h($fresh['password']) ?></dd>
      </dl>
      <div class="alert alert-warning small mb-2" role="note">Hasło widać tylko przez 15 minut od nadania. Potem zostaje „Nowe hasło”.</div>
      <a class="btn btn-primary" href="<?= h(route('kbo.pdf')) ?>"><i class="bi bi-file-earmark-pdf" aria-hidden="true"></i> Drukuj dane logowania (PDF)</a>
    </section>
  <?php endif; ?>

  <section class="card mb-4" aria-labelledby="kboFormH">
    <div class="card-body">
      <h2 id="kboFormH" class="h5">Załóż konto</h2>
      <?php if (!$ready): ?>
        <div class="alert alert-danger small mb-0" role="alert">Brak danych API serwera poczty albo domeny. Uzupełnij je w <a href="/migracjaadmin#ustawienia">Migratorze poczty</a>.</div>
      <?php else: ?>
        <form method="post" action="<?= h(route('kbo.create')) ?>" novalidate id="kboForm">
          <?= Router::csrfField() ?>
          <div class="mb-3">
            <label for="kboLabel" class="form-label">Przeznaczenie <span class="text-danger" aria-hidden="true">*</span></label>
            <input type="text" class="form-control" id="kboLabel" name="label" maxlength="190" required aria-required="true" aria-describedby="kboLabelErr">
            <div id="kboLabelErr" class="invalid-feedback">Podaj przeznaczenie konta, np. Recepcja.</div>
          </div>
          <div class="mb-3">
            <label for="kboNote" class="form-label">Uwagi</label>
            <textarea class="form-control" id="kboNote" name="note" rows="2"></textarea>
          </div>
          <button type="submit" class="btn btn-primary"><i class="bi bi-envelope-plus" aria-hidden="true"></i> Załóż skrzynkę</button>
        </form>
      <?php endif; ?>
    </div>
  </section>

  <section aria-labelledby="kboListH">
    <h2 id="kboListH" class="h5">Konta bezosobowe (<?= count($accounts) ?>)</h2>
    <?php if (!$accounts): ?>
      <p class="text-secondary">Nie ma jeszcze kont.</p>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-sm align-middle">
          <thead><tr><th scope="col">Adres</th><th scope="col">Przeznaczenie</th><th scope="col">Założone</th><th scope="col">Hasło nadane</th><th scope="col"><span class="visually-hidden">Akcje</span></th></tr></thead>
          <tbody>
          <?php foreach ($accounts as $a): ?>
            <tr>
              <td><?= h($a['mailbox']) ?></td>
              <td><?= h($a['label']) ?><?php if (!empty($a['note'])): ?><div class="small text-secondary"><?= nl2br(h($a['note'])) ?></div><?php endif; ?></td>
              <td class="small"><?= h($fmt($a['created_at'])) ?><div class="text-secondary"><?= h($a['created_by']) ?></div></td>
              <td class="small"><?= h($fmt($a['password_at'])) ?><div class="text-secondary"><?= h($a['password_by']) ?></div></td>
              <td class="text-end">
                <form method="post" action="<?= h(route('kbo.password')) ?>" class="d-inline" onsubmit="return confirm('Nadać nowe hasło do <?= h($a['mailbox']) ?>? Dotychczasowe przestanie działać.')">
                  <?= Router::csrfField() ?>
                  <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                  <button type="submit" class="btn btn-outline-secondary btn-sm"><i class="bi bi-key" aria-hidden="true"></i> Nowe hasło</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>
</div>

<script>
(function () {
  var f = document.getElementById('kboForm');
  if (!f) return;
  f.addEventListener('submit', function (ev) {
    var i = document.getElementById('kboLabel');
    if (i.value.trim() === '') {
      ev.preventDefault();
      i.classList.add('is-invalid');
      i.setAttribute('aria-invalid', 'true');
      i.focus();
    }
  });
  document.getElementById('kboLabel').addEventListener('input', function () { this.classList.remove('is-invalid'); this.removeAttribute('aria-invalid'); });
})();
</script>

<?php include dirname(__DIR__, 3) . '/includes/footer.php'; ?>
