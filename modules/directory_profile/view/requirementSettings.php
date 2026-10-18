<?php
/**
 * modules/directory_profile/view/requirementSettings.php — karta „Wymóg uzupełnienia
 * profilu” w admin/profile_fields.php. POST action=dir_requirement obsługuje strona
 * wołająca przez directoryProfileSaveSettings().
 */
$_dpMode  = directoryProfileMode();
$_dpReq   = directoryProfileRequiredItems();
$_dpAvail = directoryProfileAvailableItems();
?>
<div class="card border-0 shadow-sm rounded-3 mb-4" id="wymog">
  <div class="card-header bg-white fw-bold border-bottom">
    <i class="bi bi-person-check me-2"></i>Wymóg uzupełnienia profilu w katalogu
  </div>
  <div class="card-body">
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="dir_requirement">
      <fieldset class="mb-3">
        <legend class="form-label fw-semibold" style="font-size:.9rem">Tryb na pulpicie (SZO i Panel współpracownika)</legend>
        <?php foreach (DIR_PROFILE_MODES as $k => $label): ?>
        <div class="form-check">
          <input class="form-check-input" type="radio" name="dp_mode" id="dp_mode_<?= h($k) ?>" value="<?= h($k) ?>" <?= $_dpMode === $k ? 'checked' : '' ?>>
          <label class="form-check-label" for="dp_mode_<?= h($k) ?>"><?= h($label) ?></label>
        </div>
        <?php endforeach; ?>
        <div class="form-text">
          „Przypomnienie” — stały pas na pulpicie, dopóki profil nie jest kompletny.
          „Wymagane” — wejście na pulpit przenosi do edycji profilu (administratorzy i impersonacja są zwolnieni, widzą tylko pas).
        </div>
      </fieldset>
      <fieldset class="mb-3">
        <legend class="form-label fw-semibold" style="font-size:.9rem">Wymagane elementy profilu</legend>
        <div class="row row-cols-1 row-cols-sm-2 g-1">
          <?php foreach ($_dpAvail as $k => $label): ?>
          <div class="col"><div class="form-check">
            <input class="form-check-input" type="checkbox" name="dp_items[]" id="dp_item_<?= h($k) ?>" value="<?= h($k) ?>" <?= in_array($k, $_dpReq, true) ? 'checked' : '' ?>>
            <label class="form-check-label" for="dp_item_<?= h($k) ?>"><?= h($label) ?><?= str_starts_with($k, 'field_') ? ' <span class="text-muted small">(pole własne)</span>' : '' ?></label>
          </div></div>
          <?php endforeach; ?>
        </div>
        <div class="form-text">Zdjęcie czekające na akceptację administratora liczy się jako dodane.</div>
      </fieldset>
      <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1"></i>Zapisz wymóg</button>
    </form>
  </div>
</div>
