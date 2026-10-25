<?php
/** Karta wniosku osoby. Zmienne: $r, $found (zbiory z danymi osoby), $log. */
$PAGE_TITLE = 'Wniosek #' . (int)$r['id']; $active = 'rodo.requests';
include __DIR__ . '/_head.php';
$verified = !empty($r['tozsamosc_zweryfikowana_at']);
$post = fn(string $akcja) => 'method="post" action="' . h(route('rodo.request.update', ['id' => $r['id']])) . '"';
?>
<div class="row g-3">
  <div class="col-lg-7">
    <div class="card mb-3"><div class="card-body">
      <h2 class="h5"><?= h(RODO_REQUEST_TYPES[$r['typ']] ?? $r['typ']) ?> <span class="badge <?= h(RODO_REQUEST_STATUS[$r['status']][1]) ?>"><?= h(RODO_REQUEST_STATUS[$r['status']][0]) ?></span></h2>
      <dl class="row mb-0">
        <dt class="col-sm-4">Wnioskodawca</dt><dd class="col-sm-8"><?= h($r['wnioskodawca']) ?></dd>
        <dt class="col-sm-4">E-mail</dt><dd class="col-sm-8"><?= h($r['email'] ?: '—') ?></dd>
        <dt class="col-sm-4">PESEL</dt><dd class="col-sm-8"><?= h($r['pesel'] ? fcMask($r['pesel'], 'digits') : '—') ?></dd>
        <dt class="col-sm-4">Wpłynął / termin</dt><dd class="col-sm-8"><?= h($r['data_wplywu']) ?> / <strong><?= h($r['termin']) ?></strong> <?= $r['po_terminie'] ? '<span class="badge text-bg-danger">po terminie</span>' : '' ?> <?= $r['termin_przedluzony'] ? '<span class="badge text-bg-secondary">przedłużony</span>' : '' ?></dd>
        <dt class="col-sm-4">Treść</dt><dd class="col-sm-8" style="white-space:pre-wrap"><?= h($r['opis'] ?: '—') ?></dd>
        <dt class="col-sm-4">Tożsamość</dt><dd class="col-sm-8"><?= $verified ? 'zweryfikowana ' . h($r['tozsamosc_zweryfikowana_at']) . ' (' . h($r['tozsamosc_metoda'] ?: 'bez opisu') . ')' : '<span class="text-danger">niezweryfikowana</span>' ?></dd>
      </dl>
    </div></div>

    <?php if (!$verified): ?>
    <form <?= $post('') ?> class="card card-body mb-3"><?= csrf_field() ?><input type="hidden" name="akcja" value="zweryfikowano">
      <label class="form-label" for="met">Jak zweryfikowano tożsamość? (art. 12 ust. 6)</label>
      <input id="met" name="metoda" class="form-control mb-2" placeholder="np. odpowiedź z adresu zapisanego w umowie, kod SMS, dokument">
      <button class="btn btn-outline-primary align-self-start" type="submit">Oznacz jako zweryfikowaną</button></form>
    <?php endif; ?>

    <form <?= $post('') ?> class="card card-body mb-3"><?= csrf_field() ?>
      <label class="form-label" for="st">Status</label>
      <select id="st" name="status" class="form-select mb-2 w-auto"><?php foreach (RODO_REQUEST_STATUS as $k => [$l]): ?><option value="<?= $k ?>" <?= $r['status'] === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select>
      <label class="form-label" for="od">Treść odpowiedzi / uzasadnienie odmowy</label>
      <textarea id="od" name="odpowiedz" class="form-control mb-2" rows="4"><?= h($r['odpowiedz']) ?></textarea>
      <div class="d-flex gap-2"><button class="btn btn-primary" type="submit">Zapisz</button>
      <?php if (!$r['termin_przedluzony']): ?><button class="btn btn-outline-secondary" type="submit" name="akcja" value="przedluz" onclick="return confirm('Przedłużyć termin o 2 miesiące? Wnioskodawca musi być o tym powiadomiony w ciągu miesiąca od wniosku.')">Przedłuż termin (+2 mies.)</button><?php endif; ?></div>
    </form>

    <h3 class="h6">Historia</h3>
    <ul class="list-unstyled small"><?php foreach ($log as $l): ?><li><?= h($l['created_at']) ?> — <strong><?= h($l['zdarzenie']) ?></strong> <?= h($l['szczegoly']) ?></li><?php endforeach; ?></ul>
  </div>

  <div class="col-lg-5">
    <div class="card"><div class="card-body">
      <h2 class="h5">Gdzie są dane tej osoby</h2>
      <?php if (!$found): ?><p class="text-muted">Nie znaleziono danych po podanym e-mailu / PESEL-u.</p><?php else: ?>
      <ul class="list-group list-group-flush mb-3"><?php foreach ($found as $f): ?>
        <li class="list-group-item px-0"><strong><?= h($f['zbior']) ?></strong> <span class="badge text-bg-light"><?= (int)$f['count'] ?></span><br>
          <span class="small <?= $f['erasable'] ? 'text-success' : 'text-muted' ?>"><?= $f['erasable'] ? 'można zanonimizować' : 'zatrzymane z mocy prawa: ' . h($f['why']) ?></span></li>
      <?php endforeach; ?></ul><?php endif; ?>
      <?php if ($verified): ?>
        <a class="btn btn-outline-primary btn-sm" href="<?= h(route('rodo.request.export', ['id' => $r['id']])) ?>"><i class="bi bi-download" aria-hidden="true"></i> Pobierz dane osoby (JSON)</a>
        <?php if ($r['typ'] === 'usuniecie' && array_filter($found, fn($f) => $f['erasable'])): ?>
        <form method="post" action="<?= h(route('rodo.request.erase', ['id' => $r['id']])) ?>" class="mt-3 border border-danger rounded p-2"><?= csrf_field() ?>
          <p class="small mb-2"><strong>Anonimizacja jest nieodwracalna.</strong> Dotyczy tylko zbiorów oznaczonych „można zanonimizować”. Pozostałe zostają z mocy prawa — wskaż to w odpowiedzi.</p>
          <label class="form-label small" for="pt">Wpisz USUN, aby potwierdzić</label>
          <div class="input-group input-group-sm"><input id="pt" name="potwierdz" class="form-control" autocomplete="off"><button class="btn btn-danger" type="submit">Anonimizuj</button></div>
        </form><?php endif; ?>
      <?php else: ?><p class="small text-muted mb-0">Eksport i anonimizacja są dostępne po zweryfikowaniu tożsamości.</p><?php endif; ?>
    </div></div>
  </div>
</div>
<?php include __DIR__ . '/_foot.php'; ?>
