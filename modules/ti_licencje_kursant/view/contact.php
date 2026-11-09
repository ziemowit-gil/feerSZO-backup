<?php
if (!isset($me, $c)) { header('Location: /karty30/ti/kursant/licencje/', true, 302); exit; }   // widok tylko przez router modułu
/**
 * modules/ti_licencje_kursant/view/contact.php — dane kontaktowe w panelu licencji.
 * Pierwsze wejście ($required): kto nie ma e-maila albo telefonu, musi je podać — bez tego panel nie pozwala na operacje.
 * Krok 1: e-mail + telefon. Krok 2 ($pending): kod z SMS-a na podany numer (gdy SMS nie wyjdzie — na e-mail).
 * Zmienne: $me, $c, $required, $pending, $otp, $err, $org, $flash.
 */
$PAGE_TITLE = $required ? 'Uzupełnij dane kontaktowe' : 'Dane kontaktowe';
$old = $err['_old'] ?? ['email' => $c['email'], 'phone' => $c['phone']];
include __DIR__ . '/_top.php';
?>
<div class="m-tile" style="max-width:640px">
  <h1 class="m-head"><?= h($PAGE_TITLE) ?></h1>
<?php if ($pending && $otp): ?>
  <p class="tw-mb-3">Wysłaliśmy kod <?= $otp['channel'] === 'sms' ? 'SMS-em na numer ' . h($otp['to']) : ($otp['channel'] === 'email' ? 'e-mailem na ' . h($otp['to']) . ' (SMS nie wyszedł)' : '') ?>. Wpisz go, żeby potwierdzić numer <b><?= h($pending['phone']) ?></b>.</p>
  <?php if ($otp['shown']): ?><div class="pk-alert pk-alert-info" role="status">Konto testowe — nic nie wysyłamy. Kod: <b class="m-mono"><?= h($otp['shown']) ?></b></div><?php endif; ?>
  <?php if (!empty($err['code'])): ?><div class="pk-alert pk-alert-err" role="alert" id="e-code"><?= h($err['code']) ?></div><?php endif; ?>
  <form method="post" action="<?= h(route('lk.contact.code')) ?>" novalidate><?= Router::csrfField() ?>
    <label class="m-label" for="c-code">Kod (6 cyfr)</label>
    <input class="m-input m-code" id="c-code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="\d{6}" autofocus<?= !empty($err['code']) ? ' aria-invalid="true" aria-describedby="e-code"' : '' ?>>
    <div class="tw-flex tw-flex-wrap tw-gap-2 tw-mt-4">
      <button class="m-btn" type="submit">Potwierdź</button>
      <button class="m-btn-ghost" type="submit" name="back" value="1">Popraw numer lub e-mail</button>
    </div>
  </form>
  <p class="m-note tw-mt-3">Kod jest ważny 10 minut. Nie przyszedł? Wróć, sprawdź numer i wyślij jeszcze raz (po minucie).</p>
<?php else: ?>
  <?php if ($required): ?>
  <p class="tw-mb-3">Zanim zaczniesz zarządzać licencjami, potrzebujemy Twojego adresu e-mail i numeru telefonu komórkowego. Na e-mail przychodzą proformy i faktury, a na telefon — kody, którymi potwierdzasz każdą zmianę w panelu.</p>
  <?php else: ?>
  <p class="tw-mb-3">Zmień adres e-mail lub numer telefonu. Nowy numer potwierdzisz kodem z SMS-a.</p>
  <?php endif; ?>
  <?php if (!empty($err['_form'])): ?><div class="pk-alert pk-alert-err" role="alert"><?= h($err['_form']) ?></div><?php endif; ?>
  <?php if (array_diff_key($err, ['_old' => 1, '_form' => 1, 'code' => 1])): ?><div class="pk-alert pk-alert-err" role="alert">Popraw zaznaczone pola.</div><?php endif; ?>
  <form method="post" action="<?= h(route('lk.contact.post')) ?>" novalidate><?= Router::csrfField() ?>
    <div class="tw-mb-4"><label class="m-label" for="c-email">Adres e-mail *</label>
      <input class="m-input" type="email" id="c-email" name="email" value="<?= h((string)$old['email']) ?>" autocomplete="email" required<?= !empty($err['email']) ? ' aria-invalid="true" aria-describedby="e-email"' : '' ?>>
      <?php if (!empty($err['email'])): ?><p class="m-err" id="e-email"><?= h($err['email']) ?></p><?php endif; ?></div>
    <div class="tw-mb-4"><label class="m-label" for="c-phone">Telefon komórkowy *</label>
      <input class="m-input" type="tel" id="c-phone" name="phone" value="<?= h((string)$old['phone']) ?>" autocomplete="tel" inputmode="tel" placeholder="600 100 200" required aria-describedby="c-phone-h<?= !empty($err['phone']) ? ' e-phone' : '' ?>"<?= !empty($err['phone']) ? ' aria-invalid="true"' : '' ?>>
      <p class="m-note tw-mt-1" id="c-phone-h">9 cyfr. Numer zagraniczny — z kierunkowym, np. +44…</p>
      <?php if (!empty($err['phone'])): ?><p class="m-err" id="e-phone"><?= h($err['phone']) ?></p><?php endif; ?></div>
    <div class="tw-flex tw-flex-wrap tw-gap-2">
      <button class="m-btn" type="submit">Wyślij kod SMS</button>
      <?php if (!$required): ?><a class="m-btn-ghost" href="<?= h(route('lk.index')) ?>#konto">Anuluj</a><?php endif; ?>
    </div>
  </form>
<?php endif; ?>
</div>
<?php include __DIR__ . '/_bottom.php'; ?>
