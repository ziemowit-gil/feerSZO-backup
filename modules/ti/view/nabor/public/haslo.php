<?php
/**
 * modules/ti/view/nabor/public/haslo.php — ustawienie hasła z linku (set), link nieważny (invalid), „nie pamiętam hasła” (forgot).
 * Zmienne: $mode, $error, $email, $sent, $org, $acc, $form. Kontroler: NaborCandidateController::passwordForm / passwordSave / forgot*.
 */
$title = $mode === 'forgot' ? 'Nowe hasło — konto kandydata' : 'Ustaw hasło — konto kandydata';
include __DIR__ . '/_top.php';
?>
<section class="k-card tw-max-w-md tw-mx-auto" aria-labelledby="pw-h">
<?php if ($mode === 'set'): ?>
  <h1 id="pw-h" class="tw-text-xl tw-font-bold tw-mb-1">Ustaw hasło</h1>
  <p class="tw-text-sm tw-text-gray-600 tw-mb-4">Konto kandydata: <strong><?= h($email) ?></strong></p>
  <?php if ($error): ?><div class="tw-bg-red-50 tw-border tw-border-red-300 tw-text-red-900 tw-rounded-xl tw-p-3 tw-mb-4" role="alert"><?= h($error) ?></div><?php endif; ?>
  <form method="post" class="tw-grid tw-gap-4">
    <?= Router::csrfField() ?>
    <input type="email" name="username" value="<?= h($email) ?>" autocomplete="username" class="tw-hidden" aria-hidden="true" tabindex="-1">
    <div><label class="k-label" for="pw-1">Nowe hasło</label><input id="pw-1" type="password" name="password" class="k-input" autocomplete="new-password" minlength="<?= NABOR_PASS_MIN ?>" required aria-describedby="pw-h1">
      <p id="pw-h1" class="k-help">Co najmniej <?= NABOR_PASS_MIN ?> znaków. Najlepiej kilka słów, które łatwo zapamiętać.</p></div>
    <div><label class="k-label" for="pw-2">Powtórz hasło</label><input id="pw-2" type="password" name="password2" class="k-input" autocomplete="new-password" required></div>
    <button class="k-btn">Zapisz hasło i zaloguj</button>
  </form>
<?php elseif ($mode === 'invalid'): ?>
  <h1 id="pw-h" class="tw-text-xl tw-font-bold tw-mb-2">Link wygasł</h1>
  <p class="tw-text-gray-600 tw-mb-4">Link do ustawienia hasła jest nieważny albo został już użyty.</p>
  <a class="k-btn" href="<?= h(naborUrl('/przypomnij')) ?>">Wyślij nowy link</a>
<?php else: ?>
  <h1 id="pw-h" class="tw-text-xl tw-font-bold tw-mb-1">Nie pamiętam hasła</h1>
  <?php if ($sent): ?>
    <div class="tw-bg-green-50 tw-border tw-border-green-300 tw-text-green-900 tw-rounded-xl tw-p-3 tw-mt-3" role="status">Jeśli konto <strong><?= h($email) ?></strong> istnieje, wysłaliśmy na nie link do ustawienia hasła (ważny 2 godziny). Sprawdź też folder SPAM.</div>
  <?php else: ?>
    <p class="tw-text-sm tw-text-gray-600 tw-mb-4">Podaj adres e-mail z formularza zgłoszenia — wyślemy link do ustawienia hasła.</p>
    <form method="post" action="<?= h(naborUrl('/przypomnij')) ?>" class="tw-grid tw-gap-4">
      <?= Router::csrfField() ?>
      <div><label class="k-label" for="fg-e">E-mail</label><input id="fg-e" type="email" name="email" class="k-input" autocomplete="email" required></div>
      <button class="k-btn">Wyślij link</button>
    </form>
  <?php endif; ?>
  <p class="tw-text-sm tw-mt-4"><a href="<?= h(naborUrl('/logowanie')) ?>">← Logowanie</a></p>
<?php endif; ?>
</section>
<?php include __DIR__ . '/_bottom.php'; ?>
