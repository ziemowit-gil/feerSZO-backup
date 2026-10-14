<?php
/**
 * modules/it_portal/public/uslugi.php — Portal Usług IT: kafle usług.
 * Adres: <APP_URL>/uslugi-it/uslugi. Kafel z linkiem zewnętrznym prowadzi poza portal (np. Helpdesk).
 */
require_once __DIR__ . '/_layout.php';

$cats = itPortalCategories();
itPortalPageStart('Usługi', 'uslugi', 'Usługi informatyczne ' . ORG_NAME . ' — instrukcje i dostęp.');
?>
<h1 class="itp-sr">Usługi</h1>
<?php if (!$cats): ?>
  <div class="itp-empty"><p>Lista usług jest w przygotowaniu.</p></div>
<?php else: ?>
  <ul class="itp-tiles">
    <?php foreach ($cats as $c): $ext = $c['external_url'] !== ''; ?>
      <li>
        <a class="itp-tile" href="<?= h(itPortalServiceUrl($c)) ?>">
          <?= itPortalIcon($c['icon']) ?>
          <span><?= h($c['name']) ?><?php if ($ext): ?><span class="itp-tile__ext">otwiera osobny serwis</span><?php endif; ?></span>
        </a>
      </li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>
<?php
itPortalPageEnd();
