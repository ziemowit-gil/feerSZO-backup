<?php
/**
 * Partial: usługi i produkty kursanta (domeny, hosting, inne) — modules/ti_products. Wymaga: $rv_client_id (int).
 * Opcjonalnie $rv_uslugi_heading (h2 / h3). Używany przez panel kursanta (zakładka „Licencje”) i panel rodzica.
 * Kursant widzi usługi bez zarchiwizowanych; nr faktury „obcej” (od dostawcy) i uwagi są tylko dla kadry.
 */
require_once dirname(__DIR__, 3) . '/modules/ti_products/logic/tiProducts.php';
$rv_products = array_values(array_filter(TiProducts::forClient((int)$rv_client_id), fn($p) => $p['status'] !== 'archived'));
$rv_uslugi_heading = $rv_uslugi_heading ?? 'h2';
if (!$rv_products) return;
?>
<section class="mt-4" aria-labelledby="kp-uslugi-h">
  <<?= $rv_uslugi_heading ?> class="h5 fw-bold mb-1" id="kp-uslugi-h"><i class="bi bi-globe2 text-primary me-1" aria-hidden="true"></i>Usługi i produkty</<?= $rv_uslugi_heading ?>>
  <p class="text-body-secondary small mb-3">Domeny, hosting i inne usługi uruchomione w Twoim imieniu przez fundację. Dane dostępowe przekazujemy osobno.</p>
  <div class="row g-3">
    <?php foreach ($rv_products as $p):
      $t = TiProducts::TYPES[$p['product_type']] ?? TiProducts::TYPES['other'];
      $st = TiProducts::STATUSES[$p['status']] ?? TiProducts::STATUSES['active'];
      $hint = TiProducts::dateHint($p); $dl = TiProducts::daysLeft($p);
    ?>
    <div class="col-md-6">
      <div class="card h-100<?= $hint === 'overdue' ? ' border-danger' : ($hint === 'soon' ? ' border-warning' : '') ?>">
        <div class="card-body">
          <div class="d-flex align-items-start gap-2 mb-2">
            <div class="rounded d-flex align-items-center justify-content-center flex-shrink-0" style="width:38px;height:38px;background:#ecfeff;color:#0e7490;font-size:1.1rem">
              <i class="bi <?= h($t['icon']) ?>" aria-hidden="true"></i>
            </div>
            <div class="flex-grow-1" style="min-width:0">
              <div class="fw-bold text-break<?= $p['product_type'] === 'domain' ? ' font-monospace' : '' ?>"><?= h($p['name']) ?></div>
              <div class="text-body-secondary small"><?= h($t['label']) ?> · <?= h(TiProducts::providerName($p)) ?></div>
            </div>
            <span class="badge <?= h($st['cls']) ?>"><i class="bi <?= h($st['icon']) ?> me-1" aria-hidden="true"></i><?= h($st['label']) ?></span>
          </div>
          <dl class="row mb-0 small">
            <?php if ($p['product_type'] !== 'domain' && $p['server_details'] !== ''): ?>
            <dt class="col-5 text-body-secondary fw-normal">Pakiet / serwer</dt><dd class="col-7 mb-1 text-break"><?= h($p['server_details']) ?></dd>
            <?php endif; ?>
            <dt class="col-5 text-body-secondary fw-normal">Ważna do</dt>
            <dd class="col-7 mb-1"><?= $p['expires_at'] ? h(date('d.m.Y', strtotime((string)$p['expires_at']))) : 'bez daty' ?>
              <?php if ($hint === 'overdue'): ?><span class="text-danger fw-semibold"> — po terminie</span><?php elseif ($hint === 'soon'): ?><span class="text-warning-emphasis fw-semibold"> — za <?= (int)$dl ?> <?= $dl === 1 ? 'dzień' : 'dni' ?></span><?php endif; ?></dd>
            <?php if ($p['invoice_own'] !== ''): ?>
            <dt class="col-5 text-body-secondary fw-normal">Faktura</dt><dd class="col-7 mb-1 font-monospace"><?= h($p['invoice_own']) ?></dd>
            <?php endif; ?>
          </dl>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</section>
