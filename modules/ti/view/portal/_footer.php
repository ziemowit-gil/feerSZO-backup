<?php /** modules/ti/view/portal/_footer.php — stopka portalu (linki w jednym rzędzie). Wejście: $ppFootLinks (etykieta => URL). */ ?>
<div class="pp-foot-links"><div class="pp-wrap">
  <?php foreach (($ppFootLinks ?? []) as $l => $u): ?><a href="<?= h($u) ?>"><?= h($l) ?></a><?php endforeach; ?>
</div></div>
<?php require_once dirname(__DIR__, 3) . '/ti_panel_version/lib/tiPanelVersion.php'; ?>
<div class="pp-wrap" style="padding:1rem;font-size:.8rem;color:#5b6472;text-align:center"><?= h($ppOrg ?? '') ?><?= ($ppOrg ?? '') !== '' ? ' · ' : '' ?>Panel TI · wersja <?= h(tiPanelVersion()) ?></div>
