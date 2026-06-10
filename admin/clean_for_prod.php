<?php
/**
 * admin/clean_for_prod.php — Webowy panel czyszczenia danych przed wdrożeniem.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

require_role('admin');
$PAGE_TITLE = 'Czyszczenie przed wdrożeniem';

// ── Liczniki ──────────────────────────────────────────────────────────────────
function _cprod_count(string $sql): int {
    try { return (int)(db_one($sql)['n'] ?? 0); } catch(\Throwable $e) { return 0; }
}
function _cprod_table(string $table, ?string $where = null): int {
    try {
        $sql = $where
            ? "SELECT COUNT(*) AS n FROM {$table} WHERE {$where}"
            : "SELECT COUNT(*) AS n FROM {$table}";
        return (int)(db_one($sql)['n'] ?? 0);
    } catch(\Throwable $e) { return -1; } // tabela nie istnieje
}

$groups = [
    'Umowy i wolontariusze' => [
        'umowy_wolontariat','umowy_zlecenie','umowy_dzielo','umowy_praca',
        'umowy_uslugi','umowy_inne','contract_audit_log','contract_letters',
        'contract_amendments','contract_approvals','contract_edit_requests',
        'contract_supervisors','contract_termination_requests',
        'approval_requests','approval_step_decisions','approval_workflow_contracts',
        'zwroty_kosztow','zwroty_log','zwroty_numery',
        'certificate_requests','kdok_certificates','kdok_documents',
        'kdok_generated_pdf','kdok_history','kdok_user_roles',
        'timesheets','shipments','contract_extra_docs',
    ],
    'CRM i kontakty' => [
        'persons','crm_contacts','crm_activities','crm_cases','crm_case_files',
        'crm_case_notes','crm_communications','crm_contact_field_values',
        'crm_events','crm_group_links','crm_group_members','crm_group_tags',
        'crm_group_users','crm_groups','crm_mass_sends','crm_notes',
        'crm_relations','crm_sync_log','crm_action_links',
    ],
    'Zadania' => [
        'tasks','task_assignments','task_comments','task_files','task_history',
        'task_list_time','task_lists','task_notification_log','task_notification_prefs',
        'task_subtasks','task_task_tags','task_time_logs',
        'task_workspace_members','task_workspaces',
    ],
    'Karty30' => [
        'k30_clients','k30_consultations','k30_consultant_certs','k30_schedules',
        'k30_blacklist','k30_waiting_list',
        'k30_ti_attendance','k30_ti_billing','k30_ti_courses',
        'k30_ti_enrollments','k30_ti_sessions','k30_ti_student_accounts',
    ],
    'Zdarzenia i granty' => [
        'ev_events','ev_registrations','ev_checkin_tokens','ev_form_fields','ev_roles',
        'actions','action_indicators','action_grants','grants',
        'onboarding_volunteers','onboarding_messages',
        'volunteer_applications','volunteer_offers','user_applications',
    ],
    'EZD / Dokumenty' => [
        'ezd_pisma','ezd_sprawy','ezd_teczki','ezd_dekretacje',
        'ezd_zalaczniki','ezd_umowy','ezd_log',
        'resolutions','resolution_counters','correspondence',
        'procedures','procedure_versions','procedure_attachments','procedure_relations',
    ],
    'Zasoby i organizacja' => [
        'resources','resource_reservations','resource_reservation_log','resource_availability',
        'org_members','org_history','org_rules_ack','org_positions',
    ],
    'Komunikaty i kolejki' => [
        'announcements','announcement_reads','messages','mail_queue','notifications',
    ],
    'Logowanie i sesje' => [
        'login_attempts','login_log','user_sessions','oauth_states',
        'sms_login_tokens','webauthn_credentials','short_url_routes',
        'm365_standalone_accounts','m365_sync_queue',
    ],
    'Użytkownicy portalu (non-admin)' => [
        ["SELECT COUNT(*) AS n FROM users WHERE role != 'admin'", 'users (rola != admin)'],
        'user_profiles','profile_field_values',
    ],
];

// Zbierz statystyki
$preview = [];
$grand_total = 0;
foreach ($groups as $gname => $tables) {
    $grows = [];
    foreach ($tables as $t) {
        if (is_array($t)) {
            [$sql, $label] = $t;
            $n = _cprod_count($sql);
            if ($n < 0) continue;
            $grows[] = ['label' => $label, 'count' => $n];
            $grand_total += $n;
        } else {
            $n = _cprod_table($t);
            if ($n < 0) continue; // tabela nie istnieje
            $grows[] = ['label' => $t, 'count' => $n];
            $grand_total += $n;
        }
    }
    if ($grows) $preview[$gname] = $grows;
}

// Zachowane
$keep_admins = _cprod_count("SELECT COUNT(*) AS n FROM users WHERE role='admin'");
$keep_settings = _cprod_count("SELECT COUNT(*) AS n FROM settings");

// ── POST: wykonaj czyszczenie ─────────────────────────────────────────────────
$result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'clean_prod') {
    csrf_check();

    if (($_POST['confirm_text'] ?? '') !== 'CZYŚĆ') {
        flash_set('error', 'Wpisz dokładnie CZYŚĆ aby potwierdzić.');
        header('Location: clean_for_prod.php'); exit;
    }

    // Wykonaj skrypt CLI jako include (jest już zabezpieczony)
    $log   = [];
    $total = 0;
    $pdo   = db();
    $pdo->exec('PRAGMA foreign_keys = OFF');
    $pdo->beginTransaction();

    function _web_clean(string $table, ?string $where = null): array {
        global $pdo, $total;
        try {
            $sql  = $where ? "DELETE FROM {$table} WHERE {$where}" : "DELETE FROM {$table}";
            $stmt = $pdo->prepare($sql);
            $stmt->execute();
            $n    = $stmt->rowCount();
            $total += $n;
            return ['table' => $table, 'n' => $n, 'ok' => true];
        } catch(\Throwable $e) {
            return ['table' => $table, 'n' => 0, 'ok' => false, 'err' => $e->getMessage()];
        }
    }

    $result = [];
    $tables_to_clean = [
        'umowy_wolontariat','umowy_zlecenie','umowy_dzielo','umowy_praca',
        'umowy_uslugi','umowy_inne','contract_audit_log','contract_letters',
        'contract_amendments','contract_approvals','contract_edit_requests',
        'contract_supervisors','contract_termination_requests',
        'approval_requests','approval_step_decisions','approval_workflow_contracts',
        'zwroty_kosztow','zwroty_log','zwroty_numery','shipments','timesheets',
        'certificate_requests','kdok_certificates','kdok_documents','kdok_generated_pdf',
        'kdok_history','kdok_user_roles','contract_extra_docs',
        'persons','crm_contacts','crm_activities','crm_cases','crm_case_files',
        'crm_case_notes','crm_communications','crm_contact_field_values',
        'crm_events','crm_group_links','crm_group_members','crm_group_tags',
        'crm_group_users','crm_groups','crm_mass_sends','crm_notes',
        'crm_relations','crm_sync_log','crm_action_links',
        'tasks','task_assignments','task_comments','task_files','task_history',
        'task_list_time','task_lists','task_notification_log','task_notification_prefs',
        'task_subtasks','task_task_tags','task_time_logs',
        'task_workspace_members','task_workspaces',
        'k30_clients','k30_consultations','k30_consultant_certs','k30_schedules',
        'k30_blacklist','k30_waiting_list',
        'k30_ti_attendance','k30_ti_billing','k30_ti_courses',
        'k30_ti_enrollments','k30_ti_sessions','k30_ti_student_accounts',
        'ev_events','ev_registrations','ev_checkin_tokens','ev_form_fields','ev_roles',
        'actions','action_indicators','action_grants',
        'grants',
        'ezd_pisma','ezd_sprawy','ezd_teczki','ezd_dekretacje',
        'ezd_zalaczniki','ezd_umowy','ezd_log',
        'resolutions','resolution_counters','correspondence',
        'procedures','procedure_versions','procedure_attachments','procedure_relations',
        'org_members','org_history','org_rules_ack','org_positions',
        'resources','resource_reservations','resource_reservation_log','resource_availability',
        'onboarding_volunteers','onboarding_messages','volunteer_applications',
        'volunteer_offers','user_applications',
        'announcements','announcement_reads','messages','mail_queue','notifications',
        'login_attempts','login_log','user_sessions','oauth_states',
        'sms_login_tokens','webauthn_credentials','short_url_routes',
        'm365_standalone_accounts','m365_sync_queue',
        'user_profiles','profile_field_values',
    ];

    foreach ($tables_to_clean as $t) {
        $result[] = _web_clean($t);
    }
    // Użytkownicy — tylko non-admin
    $result[] = _web_clean('users', "role != 'admin'");

    $pdo->commit();
    $pdo->exec('PRAGMA foreign_keys = ON');
    try { $pdo->exec('VACUUM'); } catch(\Throwable $e){}

    // Wyczyść uploads — opcjonalnie (tylko pliki umów)
    $cleaned_files = 0;
    $upload_dirs = ['contracts', 'wolontariat', 'zlecenie', 'dzielo', 'praca'];
    foreach ($upload_dirs as $ud) {
        $dir = UPLOAD_DIR . $ud;
        if (is_dir($dir)) {
            foreach (glob($dir . '/*') ?: [] as $f) {
                if (is_file($f)) { @unlink($f); $cleaned_files++; }
            }
        }
    }

    flash_set('success', "Czyszczenie zakończone. Usunięto {$total} wierszy z bazy" . ($cleaned_files ? " i {$cleaned_files} plików" : '') . ".");
    header('Location: clean_for_prod.php?done=1'); exit;
}

$done = isset($_GET['done']);

include dirname(__DIR__) . '/includes/header.php';
?>

<style>
.cprod-group { margin-bottom: .75rem; }
.cprod-group summary {
    cursor: pointer; padding: .4rem .6rem;
    background: #f8fafc; border: 1px solid #e2e8f0; border-radius: .375rem;
    font-size: .84rem; font-weight: 600; list-style: none; display: flex;
    align-items: center; justify-content: space-between;
}
.cprod-group summary::-webkit-details-marker { display: none; }
.cprod-table { width: 100%; border-collapse: collapse; font-size: .8rem; margin-top: 2px; }
.cprod-table td { padding: .25rem .6rem; border-bottom: 1px solid #f1f5f9; }
.cprod-table td:last-child { text-align: right; font-family: monospace; }
.cprod-table tr:last-child td { border: none; }
.count-red  { color: #dc2626; font-weight: 600; }
.count-zero { color: #94a3b8; }
</style>

<nav aria-label="breadcrumb" class="mb-3">
  <ol class="breadcrumb" style="font-size:.8rem">
    <li class="breadcrumb-item"><a href="index.php">Admin</a></li>
    <li class="breadcrumb-item active">Czyszczenie przed wdrożeniem</li>
  </ol>
</nav>

<div class="d-flex align-items-center gap-2 mb-1">
  <h4 class="mb-0"><i class="bi bi-trash3 me-2 text-danger"></i>Czyszczenie danych testowych</h4>
</div>
<p class="text-muted small mb-4">Usuwa wszystkie dane operacyjne. Zachowuje konfigurację systemu i konta administratorów.</p>

<?= flash_html() ?>

<?php if ($done): ?>
<div class="alert alert-success">
  <i class="bi bi-check-circle me-1"></i>
  Baza wyczyszczona. System gotowy do wdrożenia na produkcję.
  <a href="index.php" class="ms-3 btn btn-sm btn-success">Przejdź do panelu admina</a>
</div>
<?php endif; ?>

<div class="row g-3">
<div class="col-lg-7">

<!-- Podgląd co zostanie usunięte -->
<div class="card shadow-sm mb-3">
  <div class="card-header fw-semibold py-2 d-flex justify-content-between">
    <span><i class="bi bi-eye me-1"></i>Co zostanie usunięte</span>
    <span class="badge bg-danger"><?= number_format($grand_total) ?> wierszy łącznie</span>
  </div>
  <div class="card-body py-2">
    <?php foreach ($preview as $gname => $rows):
      $gtotal = array_sum(array_column($rows, 'count'));
    ?>
    <details class="cprod-group">
      <summary>
        <?= h($gname) ?>
        <span class="badge <?= $gtotal > 0 ? 'bg-danger' : 'bg-secondary' ?> ms-2"><?= number_format($gtotal) ?></span>
      </summary>
      <table class="cprod-table">
        <?php foreach ($rows as $r): ?>
        <tr>
          <td class="text-muted"><?= h($r['label']) ?></td>
          <td class="<?= $r['count'] > 0 ? 'count-red' : 'count-zero' ?>"><?= number_format($r['count']) ?></td>
        </tr>
        <?php endforeach; ?>
      </table>
    </details>
    <?php endforeach; ?>
  </div>
</div>

</div>
<div class="col-lg-5">

<!-- Zachowane -->
<div class="card shadow-sm mb-3 border-success">
  <div class="card-header fw-semibold py-2 text-success">
    <i class="bi bi-shield-check me-1"></i>Co zostanie zachowane
  </div>
  <div class="card-body py-2 small">
    <div class="d-flex justify-content-between py-1 border-bottom">
      <span>Konta administratorów</span>
      <span class="badge bg-success"><?= $keep_admins ?></span>
    </div>
    <div class="d-flex justify-content-between py-1 border-bottom">
      <span>Ustawienia systemu</span>
      <span class="badge bg-success"><?= $keep_settings ?></span>
    </div>
    <div class="py-1 text-muted" style="font-size:.75rem">
      Szablony, workflow, cenniki, definicje pól, konfiguracja M365/SMS/SMTP, certyfikat, branding,
      ograniczenie IP admina (<code>admin_ip_restrict</code>).
    </div>
  </div>
</div>

<!-- Formularz potwierdzenia -->
<?php if (!$done): ?>
<div class="card shadow-sm border-danger">
  <div class="card-header fw-semibold py-2 text-danger">
    <i class="bi bi-exclamation-triangle me-1"></i>Wykonaj czyszczenie
  </div>
  <div class="card-body">
    <div class="alert alert-danger py-2 small">
      <strong>Operacja nieodwracalna.</strong> Upewnij się, że masz kopię zapasową bazy danych przed kontynuacją.
    </div>
    <form method="post" onsubmit="return document.getElementById('confirmInput').value === 'CZYŚĆ'">
      <input type="hidden" name="_csrf"    value="<?= csrf_token() ?>">
      <input type="hidden" name="_action" value="clean_prod">
      <div class="mb-3">
        <label class="form-label small fw-semibold">
          Wpisz <code>CZYŚĆ</code> aby potwierdzić
        </label>
        <input type="text" id="confirmInput" name="confirm_text"
               class="form-control form-control-sm font-monospace"
               placeholder="CZYŚĆ" autocomplete="off"
               oninput="document.getElementById('cleanBtn').disabled = this.value !== 'CZYŚĆ'">
      </div>
      <button type="submit" id="cleanBtn" class="btn btn-danger w-100" disabled>
        <i class="bi bi-trash3 me-1"></i>Wyczyść dane testowe
      </button>
    </form>
  </div>
</div>
<?php endif; ?>

</div>
</div>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
