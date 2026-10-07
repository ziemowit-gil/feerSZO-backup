<?php
/**
 * Pasek z tenantem, w którym SZO tworzy i sprawdza konta EDU: Tenant ID, Application (client) ID, domena.
 * Wejście: opcjonalnie $graph (M365Graph tenanta EDU); gdy brak — pobierany przez m365_edu().
 * Sekret aplikacji NIGDY nie jest wyświetlany.
 */
$_ti = $graph ?? (function_exists('m365_edu') ? m365_edu() : null);
$_tiTenant = $_ti ? $_ti->tenant_id() : '';
$_tiApp    = $_ti ? $_ti->client_id() : '';
$_tiDomain = function_exists('m365_training_domain') ? m365_training_domain() : '';
$_tiOk     = $_ti && $_ti->is_configured();
?>
<div class="alert <?= $_tiOk ? 'alert-secondary' : 'alert-danger' ?> py-2 small d-flex align-items-center gap-3 flex-wrap mb-3" role="status">
  <strong><i class="bi bi-diagram-3 me-1" aria-hidden="true"></i>Tenant EDU</strong>
  <?php if (!$_tiOk): ?>
    <span>nie skonfigurowany — konta nie będą tworzone.</span>
  <?php else: ?>
    <span>Tenant ID: <code class="user-select-all"><?= h($_tiTenant) ?></code></span>
    <span>App ID (client): <code class="user-select-all"><?= h($_tiApp) ?></code></span>
    <?php if ($_tiDomain !== ''): ?><span>Domena: <code><?= h($_tiDomain) ?></code></span><?php endif; ?>
  <?php endif; ?>
</div>
