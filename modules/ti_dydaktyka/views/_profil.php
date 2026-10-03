<?php
/**
 * Sekcja „Profil tyflo” karty kursanta. Zmienne z kontrolera: $clientId, $courseCtx, $profile, $profile_mode, $pf, $errors, $open.
 * $pf = wartości formularza (z POST po błędzie albo z zapisanego profilu): tools/formats/tech (+ *_other), notes.
 */
$_groups = [
    ['tools',   'Narzędzia asystujące',            array_combine(TiDydaktykaManager::PROFILE_TOOLS, TiDydaktykaManager::PROFILE_TOOLS)],
    ['formats', 'Preferowane formaty materiałów',  TiDydaktykaManager::PROFILE_FORMATS],
    ['tech',    'Technologie tyflo w użyciu',      array_combine(TiDydaktykaManager::PROFILE_TECH, TiDydaktykaManager::PROFILE_TECH)],
];
?>
<section id="profil" aria-labelledby="h-profil" class="card border-0 shadow-sm mb-4">
  <div class="card-header"><h2 class="h5 mb-0" id="h-profil"><i class="bi bi-universal-access text-primary me-2" aria-hidden="true"></i>Profil tyflo</h2></div>
  <div class="card-body">
    <p class="small text-body-secondary">Technologie i formaty, z których kursant korzysta na zajęciach. To dane o niepełnosprawności (dane wrażliwe) — widzą je wyłącznie prowadzący kursanta i kierownik.
      <?php if (!empty($profile['updated_at'])): ?>Ostatnia zmiana: <?= h(dk_dt($profile['updated_at'])) ?>.<?php endif; ?></p>
    <?php if ($profile['error'] !== ''): ?><div class="alert alert-warning" role="alert"><?= h($profile['error']) ?></div><?php endif; ?>

    <?php if ($profile_mode !== 'local'): ?>
      <div class="alert alert-info" role="status">Profil prowadzi osobny serwis TI (tryb http) — poniżej tylko odczyt; edycja po stronie serwisu TI.</div>
      <dl class="row mb-0">
        <dt class="col-sm-3">Narzędzia asystujące</dt><dd class="col-sm-9"><?= $profile['tools'] ? h(implode(', ', $profile['tools'])) : '—' ?></dd>
        <dt class="col-sm-3">Formaty materiałów</dt><dd class="col-sm-9"><?= $profile['formats'] ? h(implode(', ', array_map(fn($f) => TiDydaktykaManager::PROFILE_FORMATS[$f] ?? $f, $profile['formats']))) : '—' ?></dd>
        <dt class="col-sm-3">Technologie tyflo</dt><dd class="col-sm-9"><?= $profile['tech'] ? h(implode(', ', $profile['tech'])) : '—' ?></dd>
        <dt class="col-sm-3">Notatka</dt><dd class="col-sm-9"><?= $profile['notes'] !== '' ? h($profile['notes']) : '—' ?></dd>
      </dl>
    <?php else: ?>
    <form method="post" action="<?= h(dk_url($clientId, $courseCtx, [], '#profil')) ?>">
      <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
      <input type="hidden" name="_op" value="save_profile">
      <input type="hidden" name="client" value="<?= (int)$clientId ?>">
      <div class="row g-3">
        <?php foreach ($_groups as [$key, $legend, $options]): ?>
        <div class="col-lg-4">
          <fieldset class="border rounded p-2 h-100">
            <legend class="float-none w-auto px-1 fs-6 fw-semibold mb-1"><?= h($legend) ?></legend>
            <?php foreach ($options as $val => $label): $cid = 'pf-' . $key . '-' . md5((string)$val); ?>
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="<?= $key ?>[]" id="<?= h($cid) ?>" value="<?= h($val) ?>"<?= in_array((string)$val, $pf[$key], true) ? ' checked' : '' ?>>
              <label class="form-check-label" for="<?= h($cid) ?>"><?= h($label) ?></label>
            </div>
            <?php endforeach; ?>
            <label class="form-label small mb-0 mt-2" for="pf-<?= $key ?>-other">Inne (po przecinku)</label>
            <input type="text" class="form-control form-control-sm" id="pf-<?= $key ?>-other" name="<?= $key ?>_other" value="<?= h($pf[$key . '_other']) ?>" maxlength="300" autocomplete="off">
          </fieldset>
        </div>
        <?php endforeach; ?>
        <div class="col-12">
          <label class="form-label fw-semibold" for="pf-notes">Notatka do profilu</label>
          <textarea class="form-control" id="pf-notes" name="notes" rows="2" maxlength="1000"><?= h($pf['notes']) ?></textarea>
        </div>
      </div>
      <button type="submit" class="btn btn-primary mt-3"><i class="bi bi-check-lg me-1" aria-hidden="true"></i>Zapisz profil</button>
    </form>
    <?php endif; ?>
  </div>
</section>
