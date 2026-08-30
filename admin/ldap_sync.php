<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/ldap.php';
if (is_file(dirname(__DIR__) . '/includes/admin_audit.php')) {
    require_once dirname(__DIR__) . '/includes/admin_audit.php';
}

auth_start();
require_role('admin');
if (!defined('TZ_ADMIN_CHROME')) {
    header('Location: ' . APP_URL . '/tozsamosc/ldap.php');
    exit;
}

$PAGE_TITLE = 'Synchronizacja kont LDAP';
$results = [];
$ran = false;

$ACTION_LABELS = [
    'created'     => 'Utworzono wpis',
    'updated'     => 'Zaktualizowano wpis',
    'deactivated' => 'Przeniesiono do dezaktywowanych',
    'reactivated' => 'Przywrócono z dezaktywowanych',
    'skipped'     => 'Pominięto (nieaktywny, nigdy nie był w LDAP)',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $ran = true;

    try {
        $sync = ldap_run_sync();

        foreach ($sync['items'] as $item) {
            $label = $ACTION_LABELS[$item['ldap_action']] ?? $item['ldap_action'];
            if ($item['graph_action'] === 'updated') {
                $label .= ' · M365: zaktualizowano';
            } elseif ($item['graph_action'] === 'error') {
                $label .= ' · M365: błąd';
            }

            $results[] = [
                'status' => $item['ldap_action'] === 'error' || $item['graph_action'] === 'error' ? 'err' : 'ok',
                'name'   => $item['name'],
                'login'  => $item['email'],
                'action' => $item['ldap_action'] === 'error' ? '' : $label,
                'msg'    => trim(($item['ldap_error'] ?? '') . (($item['graph_error'] ?? '') ? ' | M365: ' . $item['graph_error'] : '')),
            ];
        }

        $l = $sync['summary']['ldap'];
        $g = $sync['summary']['graph'];
        $summary = "LDAP — utworzono: {$l['created']}, zaktualizowano: {$l['updated']}, "
            . "dezaktywowano: {$l['deactivated']}, reaktywowano: {$l['reactivated']}, błędy: {$l['error']}."
            . ($g['updated'] > 0 || $g['error'] > 0 ? " M365 — zaktualizowano: {$g['updated']}, błędy: {$g['error']}." : '');

        if (function_exists('admin_audit')) {
            admin_audit('ldap_sync', 'ldap', $summary, 0, 'Eksport kont do LDAP + M365');
        }
        $hasErrors = $l['error'] > 0 || $g['error'] > 0;
        flash_set($hasErrors ? 'warning' : 'success', 'Synchronizacja zakończona. ' . $summary);
    } catch (\Throwable $e) {
        flash_set('danger', 'Synchronizacja LDAP przerwana: ' . $e->getMessage());
    }
}

$last_sync = ldap_setting('ldap_last_sync');
$TZ_ACTIVE = 'administracja';
include dirname(__DIR__) . '/tozsamosc/_head.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0"><i class="bi bi-diagram-3 text-primary"></i> Synchronizacja kont LDAP</h4>
</div>

<div class="card shadow-sm mb-3">
    <div class="card-body">
      <p class="mb-2">
        Jednokierunkowy eksport wszystkich kont (<code>users</code>) do katalogu
        <span class="fw-semibold"><?= h(defined('LDAP_HOST') ? LDAP_HOST : '—') ?></span> —
        dezaktywowane konta trafiają do gałęzi <code><?= h(defined('LDAP_DISABLED_OU') ? LDAP_DISABLED_OU : 'ou=disabled') ?></code>
        zamiast być kasowane. Konta powiązane z M365 (<code>microsoft_id</code>) są dodatkowo
        aktualizowane w Entra ID (status, nazwa). Logowanie do SZO pozostaje lokalne — hasła nie są eksportowane.
      </p>

      <div class="alert alert-light border small mb-3">
        <i class="bi bi-clock-history"></i> Ostatnia synchronizacja:
        <span class="fw-semibold text-primary"><?= $last_sync !== '' ? h($last_sync) : 'brak danych' ?></span>
      </div>

      <form method="post">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <button type="submit" class="btn btn-primary"
                onclick="return confirm('Zsynchronizować wszystkie konta do LDAP (i M365 dla powiązanych)?');">
          <i class="bi bi-arrow-repeat"></i> Synchronizuj teraz
        </button>
      </form>
    </div>
</div>

<?php if ($ran && $results): ?>
<div class="card shadow-sm">
    <div class="card-header fw-semibold">Wyniki operacji</div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-light">
              <tr>
                  <th>Użytkownik</th>
                  <th>Działanie</th>
                  <th>Status</th>
              </tr>
          </thead>
          <tbody>
          <?php foreach ($results as $r): ?>
          <tr>
            <td>
                <strong><?= h($r['name']) ?></strong><br>
                <small class="text-muted"><?= h($r['login']) ?></small>
            </td>
            <td><?= h($r['action']) ?></td>
            <td>
              <?php if ($r['status'] === 'ok'): ?>
                <span class="badge bg-success"><i class="bi bi-check-circle"></i> OK</span>
              <?php else: ?>
                <span class="badge bg-danger">Błąd</span>
                <div class="x-small text-danger" style="font-size:0.7rem"><?= h($r['msg'] ?? '') ?></div>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php include dirname(__DIR__) . '/tozsamosc/_foot.php'; ?>
