<?php
/**
 * modules/ti/view/nabor/public/logowanie.php — logowanie do konta kandydata.
 * Zmienne: $error, $email, $next, $org, $acc, $form. Kontroler: NaborCandidateController::loginForm / login.
 */
$title = 'Logowanie — konto kandydata';
include __DIR__ . '/_top.php';
?>
<section class="k-card tw-max-w-md tw-mx-auto" aria-labelledby="lg-h">
  <h1 id="lg-h" class="tw-text-xl tw-font-bold tw-mb-1">Konto kandydata</h1>
  <p class="tw-text-sm tw-text-gray-600 tw-mb-4">Status zgłoszeń, wiadomości od organizatora, dokumenty i potwierdzenie miejsca. Konto powstaje po potwierdzeniu pierwszego zgłoszenia.</p>
  <?php if ($error): ?><div class="tw-bg-red-50 tw-border tw-border-red-300 tw-text-red-900 tw-rounded-xl tw-p-3 tw-mb-4" role="alert"><?= h($error) ?></div><?php endif; ?>
  <form method="post" action="<?= h(naborUrl('/logowanie')) ?>" class="tw-grid tw-gap-4">
    <?= Router::csrfField() ?><input type="hidden" name="next" value="<?= h($next) ?>">
    <div><label class="k-label" for="lg-e">E-mail</label><input id="lg-e" type="email" name="email" class="k-input" value="<?= h($email) ?>" autocomplete="username" required autofocus></div>
    <div><label class="k-label" for="lg-p">Hasło</label><input id="lg-p" type="password" name="password" class="k-input" autocomplete="current-password" required></div>
    <button class="k-btn">Zaloguj się</button>
  </form>
  <p class="tw-text-sm tw-mt-4 tw-flex tw-flex-wrap tw-gap-x-4"><a href="<?= h(naborUrl('/przypomnij')) ?>">Nie pamiętam hasła / nie mam hasła</a><a href="<?= h(naborUrl('/zapisy')) ?>">Złóż pierwsze zgłoszenie</a></p>
</section>
<?php include __DIR__ . '/_bottom.php'; ?>
