<?php
if (!isset($org)) { header('Location: /licencje/', true, 302); exit; }   // widok tylko przez router modułu
/**
 * modules/ti_licencje_kursant/view/login.php — logowanie do panelu licencji (jak Portal Kontrahenta): login „l” + 8 cyfr i hasło.
 * Zmienne: $org, $err, $old, $flash, $adminOn, $adminSzo, $adminMs (logowanie Administratora Licencji przez Microsoft 365 / SZO).
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
  <p class="tw-text-sm tw-mb-2">Login i hasło wysyła biuro: login e-mailem, hasło SMS-em. Jeśli ich nie masz albo nie pamiętasz hasła, napisz do biura.</p>
  <p class="tw-text-sm">Uczestnik zajęć TI może też wejść z <a href="/karty30/ti/login.php">panelu uczestnika</a> (zakładka Licencje) bez drugiego logowania.</p>
</div>
<section class="m-tile tw-mt-4" style="max-width:480px" aria-labelledby="adm-h">
  <h2 class="m-sect" id="adm-h">Dla kadry: Administrator Licencji</h2>
  <?php if (!empty($adminOn)): ?>
    <p class="tw-text-sm tw-mb-2">Jesteś zalogowany jako Administrator Licencji.</p>
    <a class="m-btn" href="<?= h(route('lk.admin')) ?>">Przejdź do części administratora</a>
  <?php else: ?>
    <p class="tw-text-sm tw-mb-3">Zaloguj się kontem służbowym. Wejście mają osoby z rolą Administrator Licencji w SZO.</p>
    <div class="tw-flex tw-flex-wrap tw-gap-2">
      <a class="m-btn" href="<?= h($adminMs) ?>">Zaloguj przez Microsoft 365</a>
      <a class="m-btn-ghost" href="<?= h($adminSzo) ?>">Zaloguj przez SZO</a>
    </div>
    <p class="m-note tw-mt-2">Logowanie odbywa się w SZO (z kluczem lub kodem, jak zwykle). Potem wrócisz tutaj.</p>
  <?php endif; ?>
</section>
<?php include __DIR__ . '/_bottom.php'; ?>
