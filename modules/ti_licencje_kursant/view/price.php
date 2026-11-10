<?php
if (!isset($token, $org)) { header('Location: /licencje/', true, 302); exit; }   // widok tylko przez router modułu
/**
 * modules/ti_licencje_kursant/view/price.php — decyzja o zmianie ceny z linku w e-mailu (uczestnik albo opiekun osoby niepełnoletniej).
 * Krok 1: akceptuję / rezygnuję + imię i nazwisko. Krok 2 ($st): kod (SMS na telefon decydującego; gdy brak — e-mail). Bez logowania — link jest kluczem.
 * Zmienne: $a (odpowiedź + zmiana albo null), $token, $st, $otp, $cmp, $err, $org, $flash.
 */
$PAGE_TITLE = 'Zmiana ceny';
include __DIR__ . '/_top.php';
?>
<div class="m-tile" style="max-width:680px">
<?php if (!$a): ?>
  <h1 class="m-head">Link jest nieważny</h1>
  <p>Sprawdź, czy adres z e-maila jest skopiowany w całości. Jeśli tak, napisz do biura.</p>
<?php else: $chg = $a['change']; $P = ClaudeBilling::P((string)$chg['product']); $fromD = ClaudeBilling::dayMonth((string)$chg['effective_from']);
  $closed = $chg['status'] !== 'sent'; $guardian = $a['recipient'] === 'opiekun'; ?>
  <h1 class="m-head"><?= h($P['label']) ?>: nowa cena od <?= h($fromD) ?></h1>
  <p class="tw-mb-3"><?= $guardian ? 'Decydujesz jako opiekun osoby: <b>' . h((string)$a['client_name']) . '</b>.' : 'Dotyczy: <b>' . h((string)$a['client_name']) . '</b>.' ?></p>
  <dl class="m-kv tw-grid sm:tw-grid-cols-2 tw-gap-x-6">
    <div><dt>Teraz</dt><dd><?= h($cmp['old']) ?><?= $cmp['oldPln'] !== null ? '<br><span class="m-note">dziś ≈ ' . h(ClaudeBilling::zl($cmp['oldPln'])) . ' miesięcznie</span>' : '' ?></dd></div>
    <div><dt>Od <?= h($fromD) ?></dt><dd><?= h($cmp['new']) ?><?= $cmp['newPln'] !== null ? '<br><span class="m-note">dziś ≈ ' . h(ClaudeBilling::zl($cmp['newPln'])) . ' miesięcznie</span>' : '' ?></dd></div>
  </dl>
  <?php if ((string)$chg['reason'] !== ''): ?><p class="tw-text-sm tw-mb-3">Powód: <?= h((string)$chg['reason']) ?></p><?php endif; ?>
  <?php if ($a['decision'] !== ''): ?><div class="pk-alert pk-alert-ok" role="status">Zapisana decyzja: <b><?= $a['decision'] === 'accept' ? 'akceptacja nowej ceny' : 'rezygnacja, dostęp kończy się przed ' . h($fromD) ?></b> (<?= h(ClaudeBilling::date((string)$a['decided_at'])) ?>, <?= h((string)$a['decided_name']) ?>).<?= $closed ? '' : ' Możesz ją zmienić do dnia przed zmianą.' ?></div><?php endif; ?>
  <?php if ($err !== ''): ?><div class="pk-alert pk-alert-err" role="alert" id="e-price"><?= h($err) ?></div><?php endif; ?>
  <?php if ($closed): ?>
    <p>Zmiana ceny jest zamknięta. Decyzji nie można już zmienić.</p>
  <?php elseif ($st && $otp): ?>
    <p class="tw-mb-1">Wybrano: <b><?= $st['decision'] === 'accept' ? 'akceptuję nową cenę' : 'rezygnuję' ?></b> (<?= h($st['name']) ?>).</p>
    <p class="tw-mb-3"><?= $otp['channel'] === 'sms' ? 'Kod wysłaliśmy SMS-em na numer ' . h($otp['to']) . '.' : ($otp['channel'] === 'email' ? 'Kod wysłaliśmy e-mailem na ' . h($otp['to']) . '.' : '') ?></p>
    <?php if ($otp['shown']): ?><div class="pk-alert pk-alert-info" role="status">Konto testowe, nic nie wysyłamy. Kod: <b class="m-mono"><?= h($otp['shown']) ?></b></div><?php endif; ?>
    <form method="post" action="<?= h(route('lk.price.link.post', ['token' => $token])) ?>" novalidate><?= Router::csrfField() ?>
      <input type="hidden" name="step" value="code">
      <label class="m-label" for="p-code">Kod (6 cyfr)</label>
      <input class="m-input m-code" id="p-code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="\d{6}" autofocus<?= $err !== '' ? ' aria-invalid="true" aria-describedby="e-price"' : '' ?>>
      <button class="m-btn tw-mt-4" type="submit">Potwierdź decyzję</button>
    </form>
    <form method="post" action="<?= h(route('lk.price.link.post', ['token' => $token])) ?>" class="tw-mt-2"><?= Router::csrfField() ?><input type="hidden" name="step" value="back"><button class="m-btn-ghost" type="submit">Zmień wybór</button></form>
  <?php else: ?>
    <form method="post" action="<?= h(route('lk.price.link.post', ['token' => $token])) ?>" novalidate><?= Router::csrfField() ?>
      <input type="hidden" name="step" value="send">
      <fieldset class="tw-mb-4"><legend class="m-label">Twoja decyzja *</legend>
        <label class="m-check tw-mb-2"><input type="radio" name="decision" value="accept"<?= $a['decision'] === 'accept' ? ' checked' : '' ?>><span><b>Akceptuję nową cenę.</b> Dostęp działa dalej, od <?= h($fromD) ?> po nowej cenie. Zrezygnować można w każdej chwili.</span></label>
        <label class="m-check"><input type="radio" name="decision" value="reject"<?= $a['decision'] === 'reject' ? ' checked' : '' ?>><span><b>Rezygnuję.</b> Dostęp kończy się przed <?= h($fromD) ?>. Do tego dnia obowiązuje stara cena.</span></label>
      </fieldset>
      <div class="tw-mb-4"><label class="m-label" for="p-name">Imię i nazwisko <?= $guardian ? 'opiekuna' : '' ?> *</label><input class="m-input" id="p-name" name="name" autocomplete="name" required></div>
      <button class="m-btn" type="submit">Wyślij kod</button>
      <p class="m-note tw-mt-2">Decyzję potwierdzasz kodem SMS<?= $guardian ? ' na numer opiekuna z kartoteki' : '' ?>. Bez numeru kod przyjdzie e-mailem. Jeśli nikt nie wybierze, <?= $chg['no_answer'] === 'reject' ? 'dostęp skończy się przed ' . h($fromD) : 'dostęp zostanie z nową ceną' ?>.</p>
    </form>
  <?php endif; ?>
<?php endif; ?>
</div>
<?php include __DIR__ . '/_bottom.php'; ?>
