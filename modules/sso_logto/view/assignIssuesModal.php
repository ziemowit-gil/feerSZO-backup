<?php
/**
 * modules/sso_logto/view/assignIssuesModal.php — okno „Problemy z przypisaniami” na /ssoadmin (polecenie 2026-11-14: „popup dla admina,
 * jak problem z przypisaniami, i sugestia, jak przypisać”). Dane: CloudAssignIssues::collect() w $tmIssues (liczone w sekcji #zespoly).
 * Okno otwiera się samo, gdy są problemy, raz na sesję przeglądarki dla tego samego zestawu problemów (podpis w sessionStorage);
 * przycisk w sekcji #zespoly otwiera je zawsze. Poza panelami kart (modal w ukrytym panelu się nie pokaże).
 */
if (empty($tmIssues)) return;
$__aiTotal = CloudAssignIssues::total($tmIssues);
$__aiSig = md5(json_encode(array_map(fn($i) => [$i['key'], $i['count']], $tmIssues)));
?>
<div class="modal fade" id="ssoAssignModal" tabindex="-1" aria-labelledby="ssoAssignH" aria-describedby="ssoAssignD" data-sig="<?= h($__aiSig) ?>">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h2 class="modal-title h5" id="ssoAssignH"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i> Problemy z przypisaniami: <?= (int)$__aiTotal ?></h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
      </div>
      <div class="modal-body small">
        <p id="ssoAssignD">Przez te braki część osób nie trafia do grup w chmurze. Przy każdej pozycji jest podpowiedź, jak to przypisać.
          Po zmianie w SZO grupy poprawią się same w ciągu 2 minut.</p>
        <?php foreach ($tmIssues as $i): ?>
          <section class="mb-3" aria-labelledby="ssoAssign-<?= h($i['key']) ?>">
            <h3 class="h6 mb-1" id="ssoAssign-<?= h($i['key']) ?>"><?= h($i['title']) ?>: <?= (int)$i['count'] ?></h3>
            <p class="text-body-secondary mb-2"><?= h($i['hint']) ?></p>
            <ul class="list-unstyled mb-0">
              <?php foreach ($i['items'] as $it): ?>
                <li class="border-top py-2">
                  <span class="fw-semibold"><?= h($it['name']) ?></span> <span class="text-body-secondary"><?= h($it['detail']) ?></span><br>
                  <?= h($it['suggest']) ?>
                  <?php if ($it['url'] !== ''): ?>
                    <a class="btn btn-outline-primary btn-sm ms-1" href="<?= h($it['url']) ?>"><?= h($it['action']) ?><span class="visually-hidden">: <?= h($it['name']) ?></span></a>
                  <?php endif; ?>
                </li>
              <?php endforeach; ?>
            </ul>
            <?php if ($i['count'] > count($i['items'])): ?>
              <p class="text-body-secondary mt-1 mb-0">Pokazano <?= count($i['items']) ?> z <?= (int)$i['count'] ?>. Po poprawieniu tych pojawią się kolejne.</p>
            <?php endif; ?>
          </section>
        <?php endforeach; ?>
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
  var key = 'ssoAssignSeen', sig = el.getAttribute('data-sig'), seen = '';
  try { seen = sessionStorage.getItem(key) || ''; } catch (e) {}
  el.addEventListener('hidden.bs.modal', function () { try { sessionStorage.setItem(key, sig); } catch (e) {} });
  if (seen !== sig) bootstrap.Modal.getOrCreateInstance(el).show();   // nowe albo inne problemy: pokaż samo
});
</script>
