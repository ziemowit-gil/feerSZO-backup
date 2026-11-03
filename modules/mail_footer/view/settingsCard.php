<?php
/**
 * modules/mail_footer/view/settingsCard.php — karta „Wymóg ustawienia stopki” w admin/m365_settings.php.
 * POST _action=mail_footer_mode obsługuje panel/poczta.php (tylko administrator).
 */
$_mfMode = mailFooterMode();
?>
<div class="card shadow-sm mb-3" id="poczta-stopka">
  <div class="card-header fw-semibold d-flex align-items-center gap-2">
    <i class="bi bi-pen text-primary"></i> Wymóg ustawienia własnej stopki
  </div>
  <div class="card-body small">
    <p class="text-muted mb-3">Osoby ze skrzynką Microsoft 365 ustawiają stopkę w panelu
      <a href="<?= h(mailFooterUrl()) ?>" target="_blank" rel="noopener noreferrer">ms365webpanel.feer.org.pl</a>.
      SZO nie widzi stopki, więc osoba potwierdza ją sama na stronie <em>Zarządzanie pocztą</em>
      (potwierdziło dotąd: <strong><?= (int)mailFooterConfirmedCount() ?></strong>).</p>
    <form method="post" action="<?= APP_URL ?>/panel/poczta.php">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="_action" value="mail_footer_mode">
      <fieldset class="mb-3">
        <legend class="form-label fw-semibold" style="font-size:.9rem">Tryb na pulpicie</legend>
        <?php foreach (MAIL_FOOTER_MODES as $k => $label): ?>
        <div class="form-check">
          <input class="form-check-input" type="radio" name="mail_footer_mode" id="mfm_<?= h($k) ?>" value="<?= h($k) ?>" <?= $_mfMode === $k ? 'checked' : '' ?>>
          <label class="form-check-label" for="mfm_<?= h($k) ?>"><?= h($label) ?></label>
        </div>
        <?php endforeach; ?>
        <div class="form-text">„Wymagane” przenosi z pulpitu na stronę Zarządzanie pocztą do czasu potwierdzenia.
          Administratorzy i impersonacja są zwolnieni, widzą tylko pas.</div>
      </fieldset>
      <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1"></i>Zapisz</button>
    </form>
  </div>
</div>
