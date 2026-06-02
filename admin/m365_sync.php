<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/m365.php';

auth_start();
require_role('admin');

$PAGE_TITLE = 'Synchronizacja kont M365';
$results = [];
$ran = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $ran = true;
    // Mapowanie typów na poprawne nazwy tabel z migracji
    $types = [
        'zlecenie' => 'umowy_zlecenie',
        'dzielo' => 'umowy_dzielo',
        'wolontariat' => 'umowy_wolontariat'
    ];

    try {
        $graph = new M365Graph();
        if (!$graph->is_configured()) throw new RuntimeException('M365 nie jest skonfigurowane.');

        // Pobieramy użytkowników z microsoft_id — jeden user może mieć wiele umów w różnych tabelach
        $users = db_all("SELECT id, name, email, microsoft_id, is_active FROM users WHERE microsoft_id IS NOT NULL AND microsoft_id != ''");

        foreach ($users as $user) {
            $should_be_active = false;
            $m365_user_id     = $user['microsoft_id'];
            $user_email       = $user['email'];
            $contract_infos   = [];
            $has_any_contract = false;

            // 1. Sprawdź WSZYSTKIE tabele umów dla tego użytkownika
            // (Aktywny jeśli choć jedna umowa jest aktywna — unika konfliktu między tabelami)
            foreach ($types as $type_key => $table) {
                $contracts = db_all(
                    "SELECT * FROM {$table}
                     WHERE m365_konto = 1
                       AND (m365_user_id = ? OR (m365_login != '' AND m365_login = ?))",
                    [$m365_user_id, $user_email]
                );
                foreach ($contracts as $contract) {
                    $has_any_contract = true;
                    $active = m365_should_be_active($contract);
                    $contract_infos[] = ($contract['numer_umowy'] ?? '?') . ' ' . ($active ? '✓' : '✗');
                    if ($active) $should_be_active = true;
                }
            }
            $contract_info = $has_any_contract ? implode(', ', $contract_infos) : 'Brak powiązanych umów';

            // Konto dezaktywowane globalnie — nadpisuje umowy
            if (!$has_any_contract || (int)$user['is_active'] === 0) {
                $should_be_active = false;
            }

            // 2. Odpytanie Microsoft Graph o stan faktyczny
            try {
                $ms_account_info   = $graph->get_user_by_id($m365_user_id);
                $current_ms_status = isset($ms_account_info['accountEnabled']) ? (bool)$ms_account_info['accountEnabled'] : null;
                $licenses          = $ms_account_info['assignedLicenses'] ?? [];
                $has_license       = !empty($licenses);

                $action_text = '';
                $status_code = 'skip';

                // LOGIKA DECYZYJNA
                if ($should_be_active && !$has_license) {
                    // Aktywna umowa, ale brak licencji — wymuś wyłączenie
                    if ($current_ms_status === true) {
                        $graph->set_enabled($m365_user_id, false);
                        foreach ($types as $table) {
                            db_query("UPDATE {$table} SET m365_konto_aktywne=0 WHERE m365_user_id=?", [$m365_user_id]);
                        }
                        $action_text = 'Zablokowano (brak licencji)';
                        $status_code = 'ok';
                    } else {
                        $action_text = 'Zablokowane — brak licencji (OK)';
                        $status_code = 'warning';
                    }
                } elseif ($current_ms_status !== null && $should_be_active !== $current_ms_status) {
                    // Synchronizacja stanu Enabled/Disabled
                    $graph->set_enabled($m365_user_id, $should_be_active);
                    foreach ($types as $table) {
                        db_query("UPDATE {$table} SET m365_konto_aktywne=? WHERE m365_user_id=?",
                            [$should_be_active ? 1 : 0, $m365_user_id]);
                    }
                    $action_text = $should_be_active ? 'Włączono konto' : 'Wyłączono konto';
                    $status_code = 'ok';
                } else {
                    // Wyrównaj flagę DB do stanu chmury (bez wywołania API)
                    if ($current_ms_status !== null) {
                        foreach ($types as $table) {
                            db_query("UPDATE {$table} SET m365_konto_aktywne=? WHERE m365_user_id=?",
                                [$current_ms_status ? 1 : 0, $m365_user_id]);
                        }
                    }
                    $action_text = $should_be_active ? 'Aktywne — OK' : 'Zablokowane — OK';
                    $status_code = 'skip';
                }

                $results[] = [
                    'status'      => $status_code,
                    'name'        => $user['name'],
                    'login'       => $user_email,
                    'info'        => $contract_info,
                    'has_license' => $has_license,
                    'action'      => $action_text,
                ];

            } catch (\Exception $e) {
                $results[] = [
                    'status'      => 'err',
                    'name'        => $user['name'],
                    'login'       => $user_email,
                    'info'        => $contract_info,
                    'has_license' => null,
                    'msg'         => $e->getMessage()
                ];
            }
        }

        if (function_exists('m365_save_setting')) {
            m365_save_setting('m365_last_cron_sync', date('Y-m-d H:i:s'));
        }

    } catch (\Exception $e) {
        flash_set('danger', $e->getMessage());
    }
}

$last_sync = function_exists('m365_setting') ? m365_setting('m365_last_cron_sync') : null;
include dirname(__DIR__) . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0"><i class="bi bi-arrow-repeat text-primary"></i> Synchronizacja kont M365</h4>
  <a href="m365_settings.php" class="btn btn-sm btn-outline-secondary">Ustawienia M365</a>
</div>

<div class="card shadow-sm mb-3">
    <div class="card-body">
      <p>System weryfikuje konta M365 na podstawie aktywnych umów oraz sprawdza licencje w Entra ID.</p>
      
      <div class="alert alert-light border small">
        <i class="bi bi-clock-history"></i> Ostatnia pełna weryfikacja: 
        <span class="fw-semibold text-primary"><?= $last_sync ? h($last_sync) : 'brak danych' ?></span>
      </div>

      <form method="post">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <button type="submit" class="btn btn-primary">
          <i class="bi bi-play-circle"></i> Wymuś weryfikację teraz
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
                  <th>Powiązana umowa</th>
                  <th>Licencja</th>
                  <th>Działanie</th>
                  <th>Status</th>
              </tr>
          </thead>
          <tbody>
          <?php foreach ($results as $r): ?>
          <tr class="<?= ($r['status'] === 'warning') ? 'table-warning' : '' ?>">
            <td>
                <strong><?= h($r['name']) ?></strong><br>
                <small class="text-muted"><?= h($r['login']) ?></small>
            </td>
            <td><small><?= h($r['info']) ?></small></td>
            <td>
              <?php if ($r['has_license'] === true): ?>
                <span class="badge bg-success"><i class="bi bi-check-circle"></i> OK</span>
              <?php elseif ($r['has_license'] === false): ?>
                <span class="badge bg-danger"><i class="bi bi-x-circle"></i> BRAK</span>
                <div class="mt-1 small"><a href="https://admin.microsoft.com/#/users" target="_blank" class="text-danger decoration-none">Przypisz →</a></div>
              <?php else: ?>
                <span class="badge bg-secondary">Błąd spr.</span>
              <?php endif; ?>
            </td>
            <td class="<?= $r['status'] === 'warning' ? 'fw-bold text-danger' : '' ?>">
                <?= h($r['action']) ?>
            </td>
            <td>
              <?php if ($r['status'] === 'ok'): ?>
                <span class="badge bg-success">Zmieniono</span>
              <?php elseif ($r['status'] === 'warning'): ?>
                <span class="badge bg-warning text-dark">Wymaga akcji</span>
              <?php elseif ($r['status'] === 'err'): ?>
                <span class="badge bg-danger">Błąd</span>
                <div class="x-small text-danger" style="font-size:0.7rem"><?= h($r['msg'] ?? '') ?></div>
              <?php else: ?>
                <span class="badge bg-light text-muted border">Bez zmian</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>