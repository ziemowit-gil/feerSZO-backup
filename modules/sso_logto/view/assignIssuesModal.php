<?php
/**
 * modules/sso_logto/view/assignIssuesModal.php — okno „Problemy z przypisaniami” na /ssoadmin/grupy (polecenia 2026-11-14: „popup dla admina,
 * jak problem z przypisaniami, i sugestia, jak przypisać”, „pozwól ignorować ostrzeżenia”, „załóż konto mimo braku umowy i rób ticket”).
 * Dane: $tmIssues (CloudAssignIssues::collect(), liczone w view/groupsSection.php) i $tmIgnored (CloudAssignIssues::ignoredList()).
 * Okno otwiera się samo, gdy są problemy, raz na sesję przeglądarki dla tego samego zestawu (podpis w sessionStorage), oraz po akcji
 * (?problemy=1). Przy pozycji: podpowiedź, link albo „Załóż konto”, „Ignoruj”; przy rodzaju: „Ignoruj cały rodzaj”; na dole zignorowane
 * z „Przywróć”. „Ignoruj” działa bez przeładowania (fetch z Accept: JSON), a bez JavaScriptu zwykłym formularzem.
 */
$tmIgnored = $tmIgnored ?? CloudAssignIssues::ignoredList();
if (empty($tmIssues) && !$tmIgnored) return;
$__aiTotal = CloudAssignIssues::total($tmIssues);
$__aiSig = md5(json_encode(array_map(fn($i) => [$i['key'], $i['count']], $tmIssues)));
$__aiOpen = !empty($_GET['problemy']);
$__aiIgnore = function (string $key, string $label, string $text, string $cls = 'btn-outline-secondary') {
    return '<form method="post" action="' . h(route('sso.assignIgnore')) . '" class="d-inline js-ignore m-0">' . Router::csrfField()
         . '<input type="hidden" name="key" value="' . h($key) . '"><input type="hidden" name="label" value="' . h($label) . '"><input type="hidden" name="do" value="ignore">'
         . '<button type="submit" class="btn ' . $cls . ' btn-sm">' . $text . '</button></form>';
};
?>
<div class="modal fade" id="ssoAssignModal" tabindex="-1" aria-labelledby="ssoAssignH" aria-describedby="ssoAssignD"
     data-sig="<?= h($__aiSig) ?>" data-auto="<?= $tmIssues ? '1' : '0' ?>" data-open="<?= $__aiOpen ? '1' : '0' ?>">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h2 class="modal-title h5" id="ssoAssignH"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i> Problemy z przypisaniami: <span data-ai-total><?= (int)$__aiTotal ?></span></h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
      </div>
      <div class="modal-body small">
        <p id="ssoAssignD">Przez te braki część osób nie trafia do grup w chmurze. Przy każdej pozycji jest podpowiedź, jak to przypisać.
          Po zmianie w SZO grupy poprawią się same w ciągu 2 minut. Ostrzeżenie, które Cię nie dotyczy, możesz zignorować.</p>
        <p class="visually-hidden" role="status" aria-live="polite" id="ssoAssignLive"></p>
        <?php if (!$tmIssues): ?><p class="mb-3">Nie ma problemów do poprawienia.</p><?php endif; ?>
        <?php foreach ($tmIssues as $i): ?>
          <section class="mb-3" aria-labelledby="ssoAssign-<?= h($i['key']) ?>" data-ai-section>
            <div class="d-flex flex-wrap align-items-baseline gap-2 mb-1">
              <h3 class="h6 mb-0" id="ssoAssign-<?= h($i['key']) ?>"><?= h($i['title']) ?>: <span data-ai-count><?= (int)$i['count'] ?></span></h3>
              <span class="ms-auto"><?= $__aiIgnore('type:' . $i['key'], $i['title'], 'Ignoruj cały rodzaj<span class="visually-hidden">: ' . h($i['title']) . '</span>', 'btn-link p-0') ?></span>
            </div>
            <p class="text-body-secondary mb-2"><?= h($i['hint']) ?></p>
            <ul class="list-unstyled mb-0">
              <?php foreach ($i['items'] as $it): ?>
                <li class="border-top py-2" data-ai-item>
                  <span class="fw-semibold"><?= h($it['name']) ?></span> <span class="text-body-secondary"><?= h($it['detail']) ?></span><br>
                  <?= h($it['suggest']) ?>
                  <span class="d-inline-flex flex-wrap gap-1 ms-1 align-middle">
                    <?php if (!empty($it['create'])): ?>
                      <form method="post" action="<?= h(route('sso.assignAccount')) ?>" class="d-inline m-0"
                            onsubmit="return confirm('Założyć konto w chmurze i w logowaniu jednokrotnym: <?= h(addslashes($it['name'])) ?>?<?= empty($it['create']['ctype']) ? ' Osoba nie ma aktywnej umowy, więc powstanie też zgłoszenie w Helpdesku.' : '' ?>')">
                        <?= Router::csrfField() ?>
                        <?php foreach ($it['create'] as $k => $v): ?><input type="hidden" name="<?= h($k) ?>" value="<?= h((string)$v) ?>"><?php endforeach; ?>
                        <button type="submit" class="btn btn-primary btn-sm">Załóż konto<span class="visually-hidden">: <?= h($it['name']) ?></span></button>
                      </form>
                    <?php elseif ($it['url'] !== ''): ?>
                      <a class="btn btn-outline-primary btn-sm" href="<?= h($it['url']) ?>"><?= h($it['action']) ?><span class="visually-hidden">: <?= h($it['name']) ?></span></a>
                    <?php endif; ?>
                    <?= $__aiIgnore($it['key'], $i['title'] . ': ' . $it['name'] . ' (' . rtrim($it['detail'], '.') . ')', 'Ignoruj<span class="visually-hidden">: ' . h($it['name']) . '</span>') ?>
                  </span>
                </li>
              <?php endforeach; ?>
            </ul>
            <?php if ($i['count'] > count($i['items'])): ?>
              <p class="text-body-secondary mt-1 mb-0">Pokazano <?= count($i['items']) ?> z <?= (int)$i['count'] ?>. Po poprawieniu tych pojawią się kolejne.</p>
            <?php endif; ?>
          </section>
        <?php endforeach; ?>

        <?php if ($tmIgnored): ?>
          <details class="border-top pt-2" <?= !$tmIssues ? 'open' : '' ?>>
            <summary>Zignorowane: <?= count($tmIgnored) ?></summary>
            <ul class="list-unstyled mb-0 mt-2">
              <?php foreach ($tmIgnored as $g): ?>
                <li class="py-1">
                  <?= h($g['label'] !== '' ? $g['label'] : $g['key']) ?>
                  <span class="text-body-secondary">(<?= h(trim(($g['by'] !== '' ? $g['by'] . ', ' : '') . ($g['at'] ? date('d.m.Y H:i', strtotime((string)$g['at'])) : ''), ', ')) ?>)</span>
                  <form method="post" action="<?= h(route('sso.assignIgnore')) ?>" class="d-inline m-0"><?= Router::csrfField() ?>
                    <input type="hidden" name="key" value="<?= h($g['key']) ?>"><input type="hidden" name="do" value="restore">
                    <button type="submit" class="btn btn-link btn-sm p-0 ms-1">Przywróć<span class="visually-hidden">: <?= h($g['label']) ?></span></button>
                  </form>
                </li>
              <?php endforeach; ?>
            </ul>
          </details>
        <?php endif; ?>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Zamknij</button>
      </div>
    </div>
  </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var el = document.getElementById('ssoAssignModal');
  if (!el || !window.bootstrap) return;
  var modal = bootstrap.Modal.getOrCreateInstance(el);
  var key = 'ssoAssignSeen', sig = el.getAttribute('data-sig'), seen = '';
  try { seen = sessionStorage.getItem(key) || ''; } catch (e) {}
  el.addEventListener('hidden.bs.modal', function () { try { sessionStorage.setItem(key, sig); } catch (e) {} });
  if (el.dataset.open === '1' || (el.dataset.auto === '1' && seen !== sig)) modal.show();   // po akcji albo nowe problemy

  // „Ignoruj” bez przeładowania: znika pozycja (albo cały rodzaj), liczniki maleją, czytnik ekranu dostaje komunikat
  var live = document.getElementById('ssoAssignLive');
  var total = el.querySelector('[data-ai-total]');
  el.querySelectorAll('form.js-ignore').forEach(function (f) {
    f.addEventListener('submit', function (e) {
      e.preventDefault();
      var btn = f.querySelector('button'); btn.disabled = true;
      fetch(f.action, { method: 'POST', body: new FormData(f), headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
        .then(function (r) { return r.ok ? r.json() : { ok: false }; })
        .then(function (j) {
          if (!j.ok) { btn.disabled = false; live.textContent = 'Nie udało się zignorować. Spróbuj jeszcze raz.'; return; }
          var section = f.closest('[data-ai-section]');
          var whole = f.querySelector('[name=key]').value.indexOf('type:') === 0;
          var gone = whole ? section.querySelectorAll('[data-ai-item]').length : 1;
          var cnt = section.querySelector('[data-ai-count]');
          var n = whole ? 0 : Math.max(0, parseInt(cnt.textContent, 10) - 1);
          if (whole || n === 0) { section.remove(); } else { f.closest('[data-ai-item]').remove(); cnt.textContent = n; }
          total.textContent = Math.max(0, parseInt(total.textContent, 10) - (whole ? parseInt(cnt.textContent, 10) : gone));
          live.textContent = 'Zignorowano: ' + f.querySelector('[name=label]').value + '. Przywrócisz je na dole okna po odświeżeniu strony.';
          var head = document.getElementById('ssoAssignH'); if (head) { head.setAttribute('tabindex', '-1'); head.focus(); }
        })
        .catch(function () { btn.disabled = false; live.textContent = 'Nie udało się zignorować. Spróbuj jeszcze raz.'; });
    });
  });
});
</script>
