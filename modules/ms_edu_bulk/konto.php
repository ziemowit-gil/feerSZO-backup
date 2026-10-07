<?php
/** Szczegółowy podgląd konta EDU (admin): rejestr SZO + dane na żywo z Entra ID. */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/m365.php';
require_once dirname(__DIR__, 2) . '/includes/ti_online.php';
require_once __DIR__ . '/logic/msEduBulk.php';

require_role('admin');
$PAGE_TITLE = 'Konto EDU — podgląd';
$self = strtok((string)($_SERVER['REQUEST_URI'] ?? 'konto.php'), '?');
$back = str_starts_with($self, '/ms') ? '/ms/konta' : 'konta.php';

$rows = msEduBulkAccountsByIds([(int)($_GET['id'] ?? 0)]);
$a = $rows[0] ?? null;
$live = null; $groups = []; $roles = []; $mgr = null; $lic = []; $signin = null; $err = '';
if ($a && m365_edu_ready()) {
    $g = m365_edu();
    $live = $g->user_profile($a['ms_user_id']);
    if (isset($live['error']) || empty($live['id'])) {
        $err = $live['error']['message'] ?? 'Konta nie znaleziono w tenancie EDU (mogło zostać usunięte poza SZO).';
        $live = null;
    } else {
        foreach ($g->user_member_of($a['ms_user_id']) as $m) {
            if (str_contains((string)($m['@odata.type'] ?? ''), 'directoryRole')) $roles[] = $m['displayName'] ?? '?';
            else $groups[] = $m['displayName'] ?? '?';
        }
        $mgr = $g->user_manager($a['ms_user_id']);
        $lic = $g->user_license_details($a['ms_user_id']);
        $signin = $g->user_last_signin($a['ms_user_id']);
    }
} elseif ($a) { $err = 'Tenant EDU nie jest skonfigurowany — widać tylko dane z rejestru SZO.'; }

$dt = fn($v) => $v ? h(str_replace('T', ' ', substr((string)$v, 0, 16))) : '—';
include dirname(__DIR__, 2) . '/includes/header.php';
?>
<div class="container-xl py-4" style="max-width:1000px">
  <div class="d-flex align-items-center gap-2 mb-3">
    <h1 class="h3 fw-bold mb-0"><i class="bi bi-person-vcard me-2"></i>Podgląd konta EDU</h1>
    <a href="<?= h($back) ?>" class="btn btn-sm btn-outline-secondary ms-auto">← Zarządzanie kontami</a>
  </div>
  <?php if (!$a): ?><div class="alert alert-danger">Nie znaleziono konta w rejestrze.</div>
  <?php else: ?>
  <?php if ($err): ?><div class="alert alert-warning small"><?= h($err) ?></div><?php endif; ?>
  <div class="row g-3">
    <div class="col-lg-6"><div class="card h-100"><div class="card-header fw-semibold">Rejestr SZO</div>
      <table class="table table-sm mb-0"><tbody>
        <tr><th style="width:11rem">Osoba</th><td><?= h(trim($a['imie'] . ' ' . $a['nazwisko'])) ?><?= $a['anonim'] ? ' <span class="badge bg-secondary">anonim</span>' : '' ?></td></tr>
        <tr><th>Login (UPN)</th><td class="font-monospace"><?= h($a['upn']) ?></td></tr>
        <tr><th>E-mail alternatywny</th><td><?= h($a['email'] ?: '—') ?></td></tr>
        <tr><th>Rola</th><td><?= h($a['rola'] ?: '—') ?></td></tr>
        <tr><th>Grupa (SZO)</th><td><?= h($a['grupa'] ?: 'bez grupy') ?><?= ($a['grupa'] ?? '') !== '' && empty($a['grupa_ok']) ? ' <span class="badge bg-warning text-dark">dodanie do grupy nieudane</span>' : '' ?></td></tr>
        <tr><th>Status</th><td><?= $a['status'] === 'suspended' ? '<span class="badge bg-danger">zawieszone ' . $dt($a['suspended_at']) . '</span>' : '<span class="badge bg-success">aktywne</span>' ?></td></tr>
        <tr><th>Menedżer (SZO)</th><td><?= h($a['manager_upn'] ?: '—') ?></td></tr>
        <tr><th>MFA wymuszone</th><td><?= $a['mfa'] ? 'tak' : 'nie' ?></td></tr>
        <tr><th>Rola Entra (SZO)</th><td><?= h($a['admin_role'] ?: '—') ?></td></tr>
        <tr><th>Utworzono w SZO</th><td><?= $dt($a['created_at']) ?></td></tr>
      </tbody></table></div></div>
    <div class="col-lg-6"><div class="card h-100"><div class="card-header fw-semibold">Entra ID (na żywo)</div>
      <?php if ($live): ?>
      <table class="table table-sm mb-0"><tbody>
        <tr><th style="width:11rem">Nazwa wyświetlana</th><td><?= h($live['displayName'] ?? '—') ?></td></tr>
        <tr><th>Konto włączone</th><td><?= !empty($live['accountEnabled']) ? '<span class="badge bg-success">tak</span>' : '<span class="badge bg-danger">nie</span>' ?></td></tr>
        <tr><th>Dział</th><td><?= h($live['department'] ?? '—') ?></td></tr>
        <tr><th>UsageLocation</th><td><?= h($live['usageLocation'] ?? '—') ?></td></tr>
        <tr><th>Utworzono</th><td><?= $dt($live['createdDateTime'] ?? null) ?></td></tr>
        <tr><th>Zmiana hasła</th><td><?= $dt($live['lastPasswordChangeDateTime'] ?? null) ?></td></tr>
        <tr><th>Ostatnie logowanie</th><td><?= $signin ? $dt($signin) : '<span class="text-muted">brak danych / uprawnień</span>' ?></td></tr>
        <tr><th>Menedżer</th><td><?= $mgr ? h(($mgr['displayName'] ?? '') . ' (' . $mgr['userPrincipalName'] . ')') : '—' ?></td></tr>
        <tr><th>Role katalogowe</th><td><?= $roles ? h(implode(', ', $roles)) : '—' ?></td></tr>
      </tbody></table>
      <?php else: ?><div class="card-body text-muted small">Brak danych na żywo.</div><?php endif; ?>
    </div></div>
    <?php if ($live): ?>
    <div class="col-lg-6"><div class="card h-100"><div class="card-header fw-semibold">Licencje (<?= count($lic) ?>)</div><div class="card-body">
      <?php foreach ($lic as $l): ?><span class="badge text-bg-light border me-1 mb-1"><?= h($l['skuPartNumber'] ?? $l['skuId'] ?? '?') ?></span><?php endforeach; ?>
      <?php if (!$lic): ?><span class="text-muted">brak licencji</span><?php endif; ?></div></div></div>
    <div class="col-lg-6"><div class="card h-100"><div class="card-header fw-semibold">Grupy (<?= count($groups) ?>)</div><div class="card-body">
      <?php foreach ($groups as $gr): ?><span class="badge text-bg-light border me-1 mb-1"><?= h($gr) ?></span><?php endforeach; ?>
      <?php if (!$groups): ?><span class="text-muted">brak grup</span><?php endif; ?></div></div></div>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>
<?php include dirname(__DIR__, 2) . '/includes/footer.php';
