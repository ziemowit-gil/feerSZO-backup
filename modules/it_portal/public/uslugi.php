<?php
/**
 * modules/it_portal/public/uslugi.php — Portal Usług IT: kafle usług.
 * Adresy: <APP_URL>/uslugi-it/uslugi           — wszystkie usługi
 *         <APP_URL>/uslugi-it/dla/{grupa}      — usługi dostępne dla grupy odbiorców (?dla=slug)
 * Nad kaflami filtr „Dla kogo” (aktywne grupy). Kafel z linkiem zewnętrznym prowadzi poza portal (np. Helpdesk).
 */
require_once __DIR__ . '/_layout.php';

$auds = itPortalAudiences(true);
$aud  = null;
$dla  = (string)($_GET['dla'] ?? '');
if ($dla !== '') {
    $aud = itPortalAudienceBySlug($dla);
    if (!$aud) itPortalNotFound('Nie ma takiej grupy odbiorców albo została ukryta.');
}
$cats = $aud ? itPortalCategoriesFor((int)$aud['id']) : itPortalCategories();

itPortalPageStart($aud ? 'Usługi dla: ' . $aud['name'] : 'Usługi', 'uslugi',
    $aud ? ($aud['description'] ?: 'Usługi informatyczne ' . ORG_NAME . ' dostępne dla grupy: ' . $aud['name'] . '.')
         : 'Usługi informatyczne ' . ORG_NAME . ' — instrukcje i dostęp.');
?>
<div class="itp-kicker"><?= h(itPortalSetting('title')) ?></div>
<h1 class="itp-title"><?= $aud ? 'Usługi dla: ' . h($aud['name']) : 'Usługi' ?></h1>
<?php if ($aud && $aud['description'] !== ''): ?>
  <p class="itp-lead"><?= nl2br(h($aud['description'])) ?></p>
<?php else: ?>
  <p class="itp-lead">Wybierz usługę, żeby zobaczyć instrukcje i sposób dostępu.</p>
<?php endif; ?>
<?php if ($auds): ?>
  <nav class="itp-groups" aria-label="Dla kogo">
    <span class="itp-groups__label" aria-hidden="true">Dla kogo:</span>
    <ul>
      <li><a href="<?= h(itPortalUrl('uslugi')) ?>"<?= !$aud ? ' aria-current="page"' : '' ?>>Wszystkie usługi</a></li>
      <?php foreach ($auds as $a): $cur = $aud && (int)$a['id'] === (int)$aud['id']; ?>
        <li><a href="<?= h(itPortalAudienceUrl($a)) ?>"<?= $cur ? ' aria-current="page"' : '' ?>><?= itPortalAudienceIcon($a['icon']) ?><?= h($a['name']) ?></a></li>
      <?php endforeach; ?>
    </ul>
  </nav>
<?php endif; ?>
<?php if (!$cats): ?>
  <div class="itp-empty"><p><?= $aud ? 'Dla tej grupy nie ma jeszcze przypisanych usług.' : 'Lista usług jest w przygotowaniu.' ?></p></div>
<?php else: ?>
  <ul class="itp-tiles" aria-label="<?= h($aud ? 'Usługi dla: ' . $aud['name'] : 'Usługi informatyczne') ?>">
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
