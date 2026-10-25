<?php
/**
 * modules/ti_licencje_dydaktyczne/view/_historiaZmian.php — historia zmian na wniosku AM (sprawa / operacja): oś czasu korekt AMSK (pola „było → jest”),
 * zmian UPN, rotacji ASK-UP1, pakietów druków, zmian danych osoby i zdarzeń wniosku. Zmienne: $histScope, $histId.
 */
require_once dirname(__DIR__) . '/lib/LicenseCaseIndex.php';
$_hist = LicenseCaseIndex::changes($histScope, (int)$histId);
?>
<section class="panel" id="historia">
  <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
    <h2 class="mt-0 mb-0 me-auto">Historia zmian wniosku</h2>
    <a class="btn btn-sm btn-metro" style="background:#0f4c5c" href="<?= h(route($histScope === 'operation' ? 'ld.am56.op' : 'ld.am56', ['id' => $histId])) ?>" target="_blank" rel="noopener"><i class="bi bi-list-check" aria-hidden="true"></i> AM56 — spis dokumentów (QR)</a>
  </div>
  <p class="small text-secondary">Wszystkie zmiany danych i dokumentów tej sprawy w jednym miejscu — od najnowszej. Pełny dziennik zdarzeń i powiadomień jest w zakładce „Dziennik”.</p>
  <?php if (!$_hist): ?><p class="text-secondary mb-0">Brak zmian — wniosek nie był korygowany.</p><?php else: ?>
  <div class="table-responsive"><table class="table table-sm align-top mb-0">
    <thead><tr><th>Kiedy</th><th>Rodzaj</th><th>Zmiana</th><th>Kto</th></tr></thead><tbody>
    <?php foreach ($_hist as $e): ?>
      <tr><td class="small text-nowrap"><?= h(ldDt($e['at'])) ?></td><td><?= ldChip($e['kind'], $e['color']) ?></td>
        <td><?php if ($e['link']): ?><a href="<?= h(route($e['link'][0], $e['link'][1])) ?>"><?= h($e['title']) ?></a><?php else: ?><b><?= h($e['title']) ?></b><?php endif; ?>
          <?php if ($e['detail'] !== ''): ?><div class="small text-secondary" style="white-space:pre-line"><?= h($e['detail']) ?></div><?php endif; ?></td>
        <td class="small"><?= h($e['by'] ?: '—') ?></td></tr>
    <?php endforeach; ?></tbody></table></div>
  <?php endif; ?>
</section>
