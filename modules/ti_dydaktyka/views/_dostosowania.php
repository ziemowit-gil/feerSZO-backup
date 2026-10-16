<?php
/**
 * Sekcja „Dostosowania” karty kursanta (odpowiednik IPET) — modules/ti_edziennik/logic/accommodations.php.
 * Zmienne z kontrolera: $clientId, $courseCtx, $accom (wiersz lub wartości z błędnego POST), $open.
 */
?>
<section id="dostosowania" aria-labelledby="h-dostosowania" class="card border-0 shadow-sm mb-4">
  <div class="card-header"><h2 class="h5 mb-0" id="h-dostosowania"><i class="bi bi-sliders text-primary me-2" aria-hidden="true"></i>Dostosowania w ocenianiu i testach</h2></div>
  <div class="card-body">
    <p class="small text-body-secondary">Zalecenia do sprawdzianów, testów i egzaminów (jak IPET). Wydłużony czas działa automatycznie w egzaminach z limitem
      i w czasie sugerowanym testów; pozostałe pozycje widzi kadra przy nazwisku w dzienniku ocen. Dane wrażliwe — kursant i opiekun widzą tylko wydłużony czas.
      <?php if (!empty($accom['updated_at'])): ?>Ostatnia zmiana: <?= h(dk_dt($accom['updated_at'])) ?>.<?php endif; ?></p>
    <form method="post" action="<?= h(dk_url($clientId, $courseCtx, [], '#dostosowania')) ?>">
      <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
      <input type="hidden" name="_op" value="save_accommodations">
      <input type="hidden" name="client" value="<?= (int)$clientId ?>">
      <div class="row g-3">
        <div class="col-md-4">
          <label class="form-label fw-semibold" for="ac-time">Wydłużenie czasu</label>
          <select class="form-select" id="ac-time" name="extra_time_pct">
            <?php foreach (TI_ACCOM_EXTRA_TIME as $v => $l): ?><option value="<?= $v ?>"<?= (int)$accom['extra_time_pct'] === $v ? ' selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-8">
          <fieldset class="border rounded p-2 h-100">
            <legend class="float-none w-auto px-1 fs-6 fw-semibold mb-1">Forma materiałów i odpowiedzi</legend>
            <?php foreach (TI_ACCOM_FLAGS as $k => [$label, $icon]): ?>
            <div class="form-check form-check-inline">
              <input class="form-check-input" type="checkbox" name="<?= $k ?>" id="ac-<?= $k ?>" value="1"<?= !empty($accom[$k]) ? ' checked' : '' ?>>
              <label class="form-check-label" for="ac-<?= $k ?>"><i class="bi bi-<?= $icon ?> me-1" aria-hidden="true"></i><?= h($label) ?></label>
            </div>
            <?php endforeach; ?>
          </fieldset>
        </div>
        <div class="col-12">
          <label class="form-label fw-semibold" for="ac-other">Inne zalecenia</label>
          <textarea class="form-control" id="ac-other" name="other" rows="2" maxlength="500" placeholder="np. odpowiedzi ustne zamiast pisemnych, przerwy co 20 min"><?= h((string)$accom['other']) ?></textarea>
        </div>
      </div>
      <button type="submit" class="btn btn-primary mt-3"><i class="bi bi-check-lg me-1" aria-hidden="true"></i>Zapisz dostosowania</button>
    </form>
  </div>
</section>
