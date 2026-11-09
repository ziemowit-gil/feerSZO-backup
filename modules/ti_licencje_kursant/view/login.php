<?php
if (!isset($org)) { header('Location: /karty30/ti/kursant/licencje/', true, 302); exit; }   // widok tylko przez router modułu
/**
 * modules/ti_licencje_kursant/view/login.php — logowanie do panelu licencji (jak Portal Kontrahenta): login „l” + 8 cyfr i hasło.
 * Zmienne: $org, $err, $old.
 */
$PAGE_TITLE = 'Logowanie do panelu licencji';
include __DIR__ . '/_top.php';
?>
<div class="m-tile" style="max-width:480px">
  <h1 class="m-head">Zaloguj się do panelu licencji</h1>
  <p class="tw-text-sm tw-mb-4">Tu zarządzasz swoimi licencjami: Claude, NordVPN, Office, programami i usługami od Fundacji.</p>
  <?php if ($err !== ''): ?><div class="pk-alert pk-alert-err" role="alert" id="blad"><?= h($err) ?></div><?php endif; ?>
  <form method="post" action="<?= h(route('lk.login.post')) ?>" novalidate>
    <?= Router::csrfField() ?>
    <div class="tw-mb-4"><label class="m-label" for="l-login">Numer w panelu licencji (litera l i 8 cyfr)</label>
      <input class="m-input tw-font-mono" id="l-login" name="login" value="<?= h($old) ?>" autocomplete="username" placeholder="l12345678" inputmode="text"<?= $err !== '' ? ' aria-invalid="true" aria-describedby="blad"' : '' ?>></div>
    <div class="tw-mb-4"><label class="m-label" for="l-pass">Hasło</label>
      <input class="m-input" type="password" id="l-pass" name="password" autocomplete="current-password"<?= $err !== '' ? ' aria-invalid="true" aria-describedby="blad"' : '' ?>></div>
    <button class="m-btn" type="submit">Zaloguj się</button>
  </form>
  <hr class="tw-my-5" style="border:0;border-top:1px solid var(--pk-border)">
  <p class="tw-text-sm tw-mb-2">Uczestnik zajęć TI może wejść z <a href="../login.php">panelu uczestnika</a> (zakładka Licencje) bez drugiego logowania.</p>
  <p class="tw-text-sm">Login i hasło do panelu licencji zakłada biuro. Jeśli ich nie masz albo nie pamiętasz hasła, napisz do biura.</p>
</div>
<?php include __DIR__ . '/_bottom.php'; ?>
