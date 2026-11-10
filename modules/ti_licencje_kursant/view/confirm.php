<?php
if (!isset($me, $op)) { header('Location: /licencje/', true, 302); exit; }   // widok tylko przez router modułu
/**
 * modules/ti_licencje_kursant/view/confirm.php — potwierdzenie operacji kodem jednorazowym (SMS, gdy nie wyjdzie — e-mail).
 * Zmienne: $me, $op (co potwierdzamy), $otp (kanał, maska, kod na ekranie dla konta testowego), $err, $org, $flash.
 */
$PAGE_TITLE = 'Potwierdź kodem';
include __DIR__ . '/_top.php';
?>
<div class="m-tile" style="max-width:560px">
  <h1 class="m-head">Potwierdź kodem</h1>
  <p class="tw-mb-1">Potwierdzasz: <b><?= h($op['what']) ?></b>.</p>
  <p class="tw-mb-3"><?php if (!$otp): ?>Kod wygasł — wyślij nowy.<?php elseif ($otp['channel'] === 'sms'): ?>Kod wysłaliśmy SMS-em na numer <?= h($otp['to']) ?>.<?php elseif ($otp['channel'] === 'email'): ?>SMS nie wyszedł, więc kod wysłaliśmy e-mailem na <?= h($otp['to']) ?>.<?php endif; ?></p>
  <?php if ($otp && $otp['shown']): ?><div class="pk-alert pk-alert-info" role="status">Konto testowe, nic nie wysyłamy. Kod: <b class="m-mono"><?= h($otp['shown']) ?></b></div><?php endif; ?>
  <?php if ($err !== ''): ?><div class="pk-alert pk-alert-err" role="alert" id="e-code"><?= h($err) ?></div><?php endif; ?>
  <?php if ($otp): ?>
  <form method="post" action="<?= h(route('lk.confirm.post')) ?>" novalidate><?= Router::csrfField() ?>
    <label class="m-label" for="o-code">Kod (6 cyfr)</label>
    <input class="m-input m-code" id="o-code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="\d{6}" autofocus<?= $err !== '' ? ' aria-invalid="true" aria-describedby="e-code"' : '' ?>>
    <button class="m-btn tw-mt-4" type="submit">Potwierdź</button>
  </form>
  <?php endif; ?>
  <div class="tw-flex tw-flex-wrap tw-gap-2 tw-mt-4">
    <form method="post" action="<?= h(route('lk.confirm.resend')) ?>"><?= Router::csrfField() ?><button class="m-btn-ghost" type="submit">Wyślij nowy kod</button></form>
    <form method="post" action="<?= h(route('lk.confirm.cancel')) ?>"><?= Router::csrfField() ?><button class="m-btn-ghost" type="submit">Anuluj</button></form>
  </div>
  <p class="m-note tw-mt-3">Kod jest ważny 10 minut. Masz 5 prób. Nikomu go nie podawaj, pracownicy Fundacji nigdy o niego nie proszą.</p>
</div>
<?php include __DIR__ . '/_bottom.php'; ?>
