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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $ran = true;

    $created = 0;
    $updated = 0;
    $failed = 0;

    try {
        $ldap = new LdapDirectory();
        if (!$ldap->is_configured()) {
            throw new RuntimeException('LDAP nie jest skonfigurowany (uzupełnij stałe LDAP_* w config.local.php).');
        }
        $ldap->connect();

        foreach (ldap_collect_users() as $user) {
            try {
                $action = $ldap->upsert_user($user);
                $action === 'created' ? $created++ : $updated++;

                $results[] = [
                    'status' => 'ok',
                    'name'   => $user['name'] ?? '',
                    'login'  => $user['email'] ?? '',
                    'action' => $action === 'created' ? 'Utworzono wpis' : 'Zaktualizowano wpis',
                ];
            } catch (\Throwable $e) {
                $failed++;
                error_log('[LDAP sync] uid=' . ($user['id'] ?? '?') . ': ' . $e->getMessage());
                $results[] = [
                    'status' => 'err',
                    'name'   => $user['name'] ?? '',
                    'login'  => $user['email'] ?? '',
                    'action' => '',
                    'msg'    => $e->getMessage(),
                ];
            }
        }

        $ldap->close();
        ldap_save_setting('ldap_last_sync', date('Y-m-d H:i:s'));

        $summary = "Utworzono: {$created}, zaktualizowano: {$updated}, błędy: {$failed}.";
        if (function_exists('admin_audit')) {
            admin_audit('ldap_sync', 'ldap', $summary, 0, 'Eksport kont do LDAP');
        }
        flash_set($failed > 0 ? 'warning' : 'success', 'Synchronizacja LDAP zakończona. ' . $summary);
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
        Jednokierunkowy eksport aktywnych kont (<code>users</code>) do katalogu
        <span class="fw-semibold"><?= h(defined('LDAP_HOST') ? LDAP_HOST : '—') ?></span>.
        Logowanie do SZO pozostaje lokalne — hasła nie są eksportowane.
      </p>

      <div class="alert alert-light border small mb-3">
        <i class="bi bi-clock-history"></i> Ostatnia synchronizacja:
        <span class="fw-semibold text-primary"><?= $last_sync !== '' ? h($last_sync) : 'brak danych' ?></span>
      </div>

      <form method="post">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <button type="submit" class="btn btn-primary"
                onclick="return confirm('Zsynchronizować wszystkie aktywne konta do LDAP?');">
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
