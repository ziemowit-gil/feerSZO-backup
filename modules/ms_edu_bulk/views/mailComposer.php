<?php
/**
 * Edytor wiadomości z danymi logowania.
 * Wejście: $mbRows (wiersze wyniku), $mbTokenName, $mbTokenValue, $mbAction (URL), $mbSubject, $mbBody, $mbSzkolenie.
 * Pola: _op = mail_test | mail_send (+ mb_sel[] = indeksy zaznaczonych).
 */
$mbPending = count(array_filter($mbRows, fn($r) => empty($r['sent'])));
[$_pvSub, $_pvHtml] = $mbRows ? msEduBulkMailRender($mbSubject, $mbBody, $mbRows[0], $mbSzkolenie) : ['', ''];
?>
<form method="post" action="<?= h($mbAction) ?>" class="card border-0 shadow-sm mb-4" id="mbForm">
  <input type="hidden" name="<?= h($mbTokenName) ?>" value="<?= h($mbTokenValue) ?>">
  <div class="card-header fw-semibold"><i class="bi bi-envelope-paper me-2 text-primary" aria-hidden="true"></i>Wyślij dane logowania e-mailem (na adres alternatywny)</div>
  <div class="card-body">
    <div class="row g-3">
      <div class="col-12"><label class="form-label small fw-semibold" for="mbSub">Temat</label>
        <input id="mbSub" name="mb_subject" class="form-control" value="<?= h($mbSubject) ?>"></div>
      <div class="col-lg-7"><label class="form-label small fw-semibold" for="mbBody">Treść</label>
        <textarea id="mbBody" name="mb_body" rows="13" class="form-control font-monospace" style="font-size:.85rem"><?= h($mbBody) ?></textarea>
        <div class="small text-muted mt-1">Zmienne: <?php foreach (MS_EDU_BULK_MAIL_VARS as $v): ?><code><?= h($v) ?></code> <?php endforeach; ?></div>
        <label class="form-label small fw-semibold mt-2" for="mbSzk">Nazwa szkolenia (dla <code>{szkolenie}</code>)</label>
        <input id="mbSzk" name="mb_szkolenie" class="form-control form-control-sm" value="<?= h($mbSzkolenie) ?>"></div>
      <div class="col-lg-5"><div class="small fw-semibold mb-1">Podgląd (dla: <?= h($mbRows ? $mbRows[0]['imie'] . ' ' . $mbRows[0]['nazwisko'] : '—') ?>)</div>
        <div class="border rounded p-2 bg-white" style="font-size:.85rem"><div class="fw-semibold mb-2"><?= h($_pvSub) ?></div><?= $_pvHtml ?></div>
        <div class="small text-muted mt-1">Podgląd odświeża się po „Zapisz i odśwież podgląd”.</div></div>
      <div class="col-12">
        <div class="table-responsive" style="max-height:260px"><table class="table table-sm align-middle mb-0" style="font-size:.84rem">
          <thead class="table-light"><tr><th style="width:2rem"><input type="checkbox" id="mbAll" checked aria-label="Zaznacz wszystkich"></th><th>Osoba</th><th>E-mail alternatywny</th><th>Login</th><th>Status</th></tr></thead>
          <tbody><?php foreach ($mbRows as $i => $r): ?>
            <tr><td><input type="checkbox" class="mbSel" name="mb_sel[]" value="<?= (int)$i ?>"<?= empty($r['sent']) ? ' checked' : ' disabled' ?>></td>
              <td><?= h($r['imie'] . ' ' . $r['nazwisko']) ?></td><td><?= h($r['email'] ?? '—') ?></td><td class="font-monospace"><?= h($r['upn']) ?></td>
              <td><?= !empty($r['sent']) ? '<span class="badge bg-success">wysłano ' . h(substr($r['sent'], 11, 5)) . '</span>' : '<span class="badge bg-secondary">oczekuje</span>' ?></td></tr>
          <?php endforeach; ?></tbody></table></div>
      </div>
    </div>
    <div class="d-flex gap-2 flex-wrap mt-3">
      <button name="_op" value="mail_preview" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i>Zapisz i odśwież podgląd</button>
      <button name="_op" value="mail_test" class="btn btn-outline-primary btn-sm"><i class="bi bi-send me-1" aria-hidden="true"></i>Wyślij test do mnie</button>
      <button name="_op" value="mail_send" class="btn btn-primary btn-sm ms-auto"
              onclick="return confirm('Wysłać dane logowania do zaznaczonych osób (' + document.querySelectorAll('.mbSel:checked').length + ')?')"><i class="bi bi-envelope-arrow-up me-1" aria-hidden="true"></i>Wyślij do zaznaczonych (<?= $mbPending ?> oczekuje)</button>
    </div>
  </div>
</form>
<script>
document.getElementById('mbAll').addEventListener('change', function () {
  document.querySelectorAll('.mbSel:not(:disabled)').forEach(function (c) { c.checked = this.checked; }, this);
});
</script>
