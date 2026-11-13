<?php
/**
 * modules/dhosting_migrator/view/self.php — „Moja skrzynka dhosting”: użytkownik zakłada skrzynkę z loginem Microsoft
 * albo dostaje nowe hasło do istniejącej. Hasło przychodzi tylko SMS-em (nie pokazujemy go na ekranie).
 * Zmienne: $st (DhostingMigrator::selfState), $flash, $web.
 */
$PAGE_TITLE = 'Moja skrzynka dhosting';
include dirname(__DIR__, 3) . '/includes/header.php';
$canAct = $st['problem'] === '' && $st['wait'] === 0;
$exists = $st['exists'] === true;
?>
<style>
  .dhs { max-width: 640px; }
  .dhs-card { border: 1px solid #d0d5dd; border-radius: 10px; background: #fff; }
  .dhs-dl { display: grid; grid-template-columns: max-content 1fr; gap: .4rem 1rem; margin: 0; }
  .dhs-dl dt { font-weight: 600; color: #344054; font-size: .875rem; }
  .dhs-dl dd { margin: 0; font-size: .875rem; color: #1d2939; word-break: break-all; }
  .dhs-b { display: inline-block; padding: .15rem .55rem; border-radius: 999px; font-size: .75rem; font-weight: 600; }
  .dhs-b--ok { background: #dcfae6; color: #085d3a; }
  .dhs-b--no { background: #eff4ff; color: #1849a9; }
  .dhs-imp { border-left: 4px solid #b54708; background: #fffaeb; color: #4e1d09; padding: .75rem 1rem; border-radius: 6px; font-size: .875rem; }
  .dhs .btn { min-height: 44px; }
  .dhs :focus-visible { outline: 3px solid #1d2939; outline-offset: 2px; }
</style>

<div class="dhs mx-auto">
  <h1 class="h4 mb-1"><i class="bi bi-envelope-at text-primary" aria-hidden="true"></i> Moja skrzynka dhosting</h1>
  <p class="text-body-secondary small mb-3">
    Skrzynka pocztowa na serwerach dhosting.pl z <strong>tym samym loginem</strong> co Twoje konto Microsoft 365 i <strong>nowym hasłem</strong>.
  </p>

  <?php if ($flash): ?>
    <div class="alert alert-<?= h($flash['type']) ?>" role="<?= $flash['type'] === 'success' ? 'status' : 'alert' ?>"><?= h($flash['msg']) ?></div>
  <?php endif; ?>

  <div class="dhs-card p-3 mb-3">
    <dl class="dhs-dl">
      <dt>Login Microsoft</dt><dd><?= $st['login'] !== '' ? h($st['login']) : '—' ?></dd>
      <dt>Skrzynka dhosting</dt><dd><?= $st['mailbox'] ? h($st['mailbox']) : '—' ?></dd>
      <dt>Stan</dt>
      <dd><?php if ($st['exists'] === true): ?><span class="dhs-b dhs-b--ok">Skrzynka istnieje</span>
          <?php elseif ($st['exists'] === false): ?><span class="dhs-b dhs-b--no">Jeszcze jej nie ma</span>
          <?php else: ?>—<?php endif; ?></dd>
      <dt>Numer do SMS</dt>
      <dd><?= $st['phoneMasked'] !== '' ? h($st['phoneMasked']) : 'brak' ?>
          · <a href="<?= APP_URL ?>/panel/password.php">zmień w Ustawieniach konta</a></dd>
      <?php if (!empty($st['registry']['password_set_at'])): ?>
      <dt>Ostatnie hasło</dt><dd><?= h(substr((string)$st['registry']['password_set_at'], 0, 16)) ?></dd>
      <?php endif; ?>
    </dl>
  </div>

  <?php if ($st['problem'] !== ''): ?>
    <div class="alert alert-warning" role="alert"><?= h($st['problem']) ?></div>
  <?php elseif ($st['wait'] > 0): ?>
    <div class="alert alert-info" role="status">Kolejną prośbę możesz wysłać za <?= (int)ceil($st['wait'] / 60) ?> min.</div>
  <?php endif; ?>

  <div class="dhs-imp mb-3" role="note">
    <strong>Ważne:</strong> hasło wyślemy <strong>tylko SMS-em</strong> na numer <?= $st['phoneMasked'] !== '' ? h($st['phoneMasked']) : 'z Twojego konta' ?> — nie pokażemy go na ekranie ani nie wyślemy e-mailem.
    <?php if ($exists): ?>Nowe hasło zastąpi obecne: programy pocztowe (telefon, Outlook) poproszą o nie ponownie.<?php endif; ?>
    Hasło do konta Microsoft 365 się nie zmienia.
  </div>

  <?php if ($canAct): ?>
  <form method="post" action="<?= h(route('dhm.selfPost')) ?>" id="dhsForm">
    <?= Router::csrfField() ?>
    <div class="form-check mb-3">
      <input class="form-check-input" type="checkbox" name="confirm" value="1" id="dhsConfirm" required>
      <label class="form-check-label" for="dhsConfirm">Numer <?= h($st['phoneMasked']) ?> jest mój i mam przy sobie ten telefon.</label>
    </div>
    <button type="submit" class="btn btn-primary" id="dhsGo">
      <i class="bi <?= $exists ? 'bi-key' : 'bi-envelope-plus' ?>" aria-hidden="true"></i>
      <?= $exists ? 'Wyślij mi nowe hasło SMS-em' : 'Załóż skrzynkę i wyślij hasło SMS-em' ?>
    </button>
  </form>
  <?php endif; ?>

  <?php if ($web !== '' && $exists): ?>
    <p class="mt-3 small">Poczta przez przeglądarkę: <a href="<?= h($web) ?>" target="_blank" rel="noopener"><?= h($web) ?> <span class="visually-hidden">(otwiera się w nowej karcie)</span></a></p>
  <?php endif; ?>
</div>

<script>
(function () {
  const f = document.getElementById('dhsForm');
  if (f) f.addEventListener('submit', () => { const b = document.getElementById('dhsGo'); b.disabled = true; b.textContent = 'Wysyłamy…'; });
})();
</script>

<?php include dirname(__DIR__, 3) . '/includes/footer.php'; ?>
