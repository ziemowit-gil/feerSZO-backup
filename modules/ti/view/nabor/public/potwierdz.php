<?php
/**
 * modules/ti/view/nabor/public/potwierdz.php — potwierdzenie adresu e-mail opiekuna.
 * Zmienne: $state (ask|done|invalid), $sub, $org. Kontroler: NaborPublicController::confirmForm / confirm.
 */
?><!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Potwierdzenie zgłoszenia — <?= h($org) ?></title>
<script src="https://cdn.tailwindcss.com"></script>
<script>tailwind.config = { prefix: 'tw-' }</script>
</head>
<body class="tw-bg-[#edf3fe] tw-text-gray-900 tw-font-sans">
<main class="tw-max-w-lg tw-mx-auto tw-px-4 tw-py-16">
  <div class="tw-bg-white tw-rounded-2xl tw-shadow-lg tw-p-8 tw-text-center">
  <?php if ($state === 'invalid'): ?>
    <h1 class="tw-text-xl tw-font-bold tw-mb-3">Link jest nieważny</h1>
    <p class="tw-text-gray-600">Link potwierdzający wygasł (ważny <?= NABOR_CONFIRM_HOURS ?> godz.) albo jest niepełny. Wypełnij formularz ponownie lub skontaktuj się z nami.</p>
  <?php elseif ($state === 'done'): ?>
    <div class="tw-text-5xl tw-mb-3" aria-hidden="true">✅</div>
    <h1 class="tw-text-xl tw-font-bold tw-mb-3">Zgłoszenie potwierdzone</h1>
    <p class="tw-text-gray-600 tw-mb-4">Numer zgłoszenia:</p>
    <p class="tw-text-2xl tw-font-bold tw-text-[#1d68f2] tw-mb-4"><?= h($sub['registration_number']) ?></p>
    <?php if (($sub['status'] ?? '') === 'waitlist'): ?>
      <p class="tw-text-amber-700">W wybranej grupie nie ma już wolnych miejsc — zgłoszenie jest na liście rezerwowej.</p>
    <?php else: ?>
      <p class="tw-text-gray-600">Zgłoszenie trafiło do rozpatrzenia. PDF z danymi wysłaliśmy na adres e-mail.</p>
    <?php endif; ?>
  <?php else: ?>
    <h1 class="tw-text-xl tw-font-bold tw-mb-3">Potwierdź zgłoszenie</h1>
    <p class="tw-text-gray-600 tw-mb-6">Zgłoszenie <strong><?= h($sub['registration_number']) ?></strong>. Kliknij, aby potwierdzić adres e-mail i przekazać zgłoszenie do rozpatrzenia.</p>
    <form method="post">
      <?= csrf_field() ?>
      <button class="tw-bg-[#1d68f2] hover:tw-bg-[#164eb6] tw-text-white tw-font-bold tw-rounded-xl tw-px-6 tw-py-3 focus:tw-outline-none focus:tw-ring-4 focus:tw-ring-blue-300">Potwierdzam zgłoszenie</button>
    </form>
  <?php endif; ?>
  </div>
</main>
</body>
</html>
