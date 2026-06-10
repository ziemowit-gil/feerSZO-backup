<?php
/**
 * clean_for_prod.php — Czyszczenie danych testowych przed wdrożeniem na produkcję.
 *
 * Co ZACHOWUJE:
 *   - settings (konfiguracja systemu, M365, SMS, SMTP, ograniczenie IP admina)
 *   - users z rolą 'admin' (konta administratorów)
 *   - roles, role_permissions
 *   - org_units (struktura organizacyjna)
 *   - approval_workflows + steps + approvers (szablony przepływów)
 *   - k30_price_tiers, k30_pfron_contracts (cenniki kart30)
 *   - resource_categories, resource_field_defs
 *   - profile_field_defs, crm_contact_field_defs
 *   - application_types, application_type_fields
 *   - crm_templates, crm_tags (szablony i tagi CRM)
 *   - ezd_jrwa (klasyfikacja archiwalna)
 *   - _migration_log (historia migracji schematu)
 *
 * Co CZYŚCI:
 *   - Wszystkie umowy i dane wolontariuszy
 *   - CRM — kontakty, sprawy, działania
 *   - Zadania i obszary robocze
 *   - Karty30 — klienci, konsultacje
 *   - Zdarzenia, rejestracje
 *   - Procedury, uchwały
 *   - EZD — pisma, sprawy, teczki
 *   - Logowanie, sesje, logi
 *   - Kolejki mail, wiadomości, powiadomienia
 *   - Zwroty kosztów, przesyłki, arkusze czasu
 *   - Konta portalu (users bez roli admin)
 *
 * URUCHAMIANIE: php cli/clean_for_prod.php
 * lub przez panel: Admin → Czyszczenie przed wdrożeniem
 */

// ── Dostęp tylko CLI lub lokalnie ─────────────────────────────────────────────
$is_cli = (php_sapi_name() === 'cli');
if (!$is_cli) {
    // Z przeglądarki: tylko localhost
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!in_array($ip, ['127.0.0.1', '::1', 'localhost'])) {
        http_response_code(403);
        die('Dostęp tylko z localhost lub CLI.');
    }
}

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';

// ── Potwierdzenie ──────────────────────────────────────────────────────────────
if ($is_cli) {
    echo "\n";
    echo "╔══════════════════════════════════════════════════════════════╗\n";
    echo "║   CZYSZCZENIE DANYCH TESTOWYCH — PRZYGOTOWANIE DO PROD      ║\n";
    echo "╚══════════════════════════════════════════════════════════════╝\n\n";
    echo "Ta operacja NIEODWRACALNIE usunie wszystkie dane testowe.\n";
    echo "Zachowane zostaną: ustawienia systemu i konta administratorów.\n\n";
    echo "Wpisz  tak  i naciśnij Enter, aby kontynuować: ";
    $confirm = trim(fgets(STDIN));
    if (strtolower($confirm) !== 'tak') {
        echo "\nAnulowano.\n\n";
        exit(0);
    }
    echo "\n";
} elseif ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // Formularz potwierdzenia w przeglądarce
    ?><!DOCTYPE html>
<html lang="pl">
<head><meta charset="UTF-8"><title>Czyszczenie bazy — prod</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5" style="max-width:640px">
  <div class="card border-danger">
    <div class="card-header bg-danger text-white fw-bold">
      <i class="bi bi-exclamation-triangle-fill me-2"></i>Czyszczenie danych testowych
    </div>
    <div class="card-body">
      <p class="fw-semibold">Ta operacja <strong class="text-danger">nieodwracalnie</strong> usunie wszystkie dane testowe z bazy.</p>
      <p><strong>Zachowane:</strong> konfiguracja systemu, konta administratorów, szablony przepływów, struktura organizacyjna.</p>
      <p><strong>Usunięte:</strong> umowy, wolontariusze, CRM, zadania, karty30, zdarzenia, logi, sesje, konta portalu (non-admin).</p>
      <hr>
      <form method="post">
        <div class="mb-3">
          <label class="form-label fw-semibold">Wpisz <code>CZYSZCZE</code> aby potwierdzić:</label>
          <input name="confirm" class="form-control" required>
        </div>
        <button class="btn btn-danger w-100">Uruchom czyszczenie</button>
      </form>
    </div>
  </div>
</div>
</body></html>
    <?php
    exit;
} else {
    if (($_POST['confirm'] ?? '') !== 'CZYSZCZE') {
        die('<p class="text-danger p-4">Błędne potwierdzenie. <a href="">Wróć</a>.</p>');
    }
}

// ── Pomocnicze ─────────────────────────────────────────────────────────────────
$pdo   = db();
$log   = [];
$total = 0;

function clean(string $table, ?string $where = null): void {
    global $pdo, $log, $total;
    try {
        $sql = $where
            ? "DELETE FROM {$table} WHERE {$where}"
            : "DELETE FROM {$table}";
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $n = $stmt->rowCount();
        $total += $n;
        $log[] = ['table' => $table, 'deleted' => $n, 'ok' => true];
    } catch (\Throwable $e) {
        $log[] = ['table' => $table, 'deleted' => 0, 'ok' => false, 'err' => $e->getMessage()];
    }
}

function keep_note(string $msg): void {
    global $log;
    $log[] = ['table' => '——', 'deleted' => null, 'ok' => true, 'note' => $msg];
}

// ── START ──────────────────────────────────────────────────────────────────────
$pdo->exec('PRAGMA foreign_keys = OFF');
$pdo->beginTransaction();

try {

// ══════════════════════════════════════════════════════════════════════════════
//  UMOWY — wszystkie typy
// ══════════════════════════════════════════════════════════════════════════════
keep_note('UMOWY');
clean('umowy_wolontariat');
clean('umowy_zlecenie');
clean('umowy_dzielo');
clean('umowy_praca');
clean('umowy_uslugi');
clean('umowy_inne');
clean('contract_audit_log');
clean('contract_letters');
clean('contract_amendments');
clean('contract_approvals');
clean('contract_edit_requests');
clean('contract_supervisors');
clean('contract_termination_requests');
clean('approval_requests');
clean('approval_step_decisions');
clean('approval_workflow_contracts');
clean('zwroty_kosztow');
clean('zwroty_log');
clean('zwroty_numery');
clean('shipments');
clean('timesheets');
clean('certificate_requests');
clean('kdok_certificates');
clean('kdok_documents');
clean('kdok_generated_pdf');
clean('kdok_history');
clean('kdok_user_roles');
clean('contract_extra_docs');

// ══════════════════════════════════════════════════════════════════════════════
//  OSOBY / CRM
// ══════════════════════════════════════════════════════════════════════════════
keep_note('OSOBY / CRM');
clean('persons');
clean('crm_contacts');
clean('crm_activities');
clean('crm_cases');
clean('crm_case_files');
clean('crm_case_notes');
clean('crm_communications');
clean('crm_contact_field_values');
clean('crm_events');
clean('crm_group_links');
clean('crm_group_members');
clean('crm_group_tags');
clean('crm_group_users');
clean('crm_groups');
clean('crm_mass_sends');
clean('crm_notes');
clean('crm_relations');
clean('crm_sync_log');
clean('crm_action_links');

// ══════════════════════════════════════════════════════════════════════════════
//  ZADANIA
// ══════════════════════════════════════════════════════════════════════════════
keep_note('ZADANIA');
clean('tasks');
clean('task_assignments');
clean('task_comments');
clean('task_files');
clean('task_history');
clean('task_list_time');
clean('task_lists');
clean('task_notification_log');
clean('task_notification_prefs');
clean('task_subtasks');
clean('task_task_tags');
clean('task_time_logs');
clean('task_workspace_members');
clean('task_workspaces');

// ══════════════════════════════════════════════════════════════════════════════
//  KARTY30
// ══════════════════════════════════════════════════════════════════════════════
keep_note('KARTY30');
clean('k30_clients');
clean('k30_consultations');
clean('k30_consultant_certs');
clean('k30_schedules');
clean('k30_blacklist');
clean('k30_waiting_list');
clean('k30_ti_attendance');
clean('k30_ti_billing');
clean('k30_ti_courses');
clean('k30_ti_enrollments');
clean('k30_ti_sessions');
clean('k30_ti_student_accounts');

// ══════════════════════════════════════════════════════════════════════════════
//  ZDARZENIA / REJESTRACJE
// ══════════════════════════════════════════════════════════════════════════════
keep_note('ZDARZENIA');
clean('ev_events');
clean('ev_registrations');
clean('ev_checkin_tokens');
clean('ev_form_fields');
clean('ev_roles');
clean('actions');
clean('action_indicators');
clean('action_grants');

// ══════════════════════════════════════════════════════════════════════════════
//  GRANTY
// ══════════════════════════════════════════════════════════════════════════════
keep_note('GRANTY');
clean('grants');

// ══════════════════════════════════════════════════════════════════════════════
//  EZD — dokumenty
// ══════════════════════════════════════════════════════════════════════════════
keep_note('EZD');
clean('ezd_pisma');
clean('ezd_sprawy');
clean('ezd_teczki');
clean('ezd_dekretacje');
clean('ezd_zalaczniki');
clean('ezd_umowy');
clean('ezd_log');

// ══════════════════════════════════════════════════════════════════════════════
//  UCHWAŁY / KORESPONDENCJA / PROCEDURY
// ══════════════════════════════════════════════════════════════════════════════
keep_note('UCHWAŁY / KORESPONDENCJA / PROCEDURY');
clean('resolutions');
clean('resolution_counters');
clean('correspondence');
clean('procedures');
clean('procedure_versions');
clean('procedure_attachments');
clean('procedure_relations');

// ══════════════════════════════════════════════════════════════════════════════
//  ORGANIZACJA — historia / członkowie
// ══════════════════════════════════════════════════════════════════════════════
keep_note('ORGANIZACJA');
clean('org_members');
clean('org_history');
clean('org_rules_ack');
clean('org_positions');

// ══════════════════════════════════════════════════════════════════════════════
//  ZASOBY
// ══════════════════════════════════════════════════════════════════════════════
keep_note('ZASOBY');
clean('resources');
clean('resource_reservations');
clean('resource_reservation_log');
clean('resource_availability');

// ══════════════════════════════════════════════════════════════════════════════
//  ONBOARDING / WNIOSKI / APLIKACJE
// ══════════════════════════════════════════════════════════════════════════════
keep_note('ONBOARDING / WNIOSKI');
clean('onboarding_volunteers');
clean('onboarding_messages');
clean('volunteer_applications');
clean('volunteer_offers');
clean('user_applications');

// ══════════════════════════════════════════════════════════════════════════════
//  KOMUNIKATY / WIADOMOŚCI / POWIADOMIENIA
// ══════════════════════════════════════════════════════════════════════════════
keep_note('KOMUNIKATY / WIADOMOŚCI');
clean('announcements');
clean('announcement_reads');
clean('messages');
clean('mail_queue');
clean('notifications');

// ══════════════════════════════════════════════════════════════════════════════
//  LOGOWANIE / SESJE / LOGI
// ══════════════════════════════════════════════════════════════════════════════
keep_note('LOGOWANIE / SESJE / LOGI');
clean('login_attempts');
clean('login_log');
clean('user_sessions');
clean('oauth_states');
clean('sms_login_tokens');
clean('webauthn_credentials');
clean('short_url_routes');
clean('m365_standalone_accounts');
clean('m365_sync_queue');

// ══════════════════════════════════════════════════════════════════════════════
//  UŻYTKOWNICY PORTALU — zachowaj tylko adminów
// ══════════════════════════════════════════════════════════════════════════════
keep_note("USERS — zachowuję tylko role='admin'");
clean('user_profiles');
clean('profile_field_values');
clean('users', "role != 'admin'");   // <-- zachowaj adminy

    $pdo->commit();

} catch (\Throwable $e) {
    $pdo->rollBack();
    $pdo->exec('PRAGMA foreign_keys = ON');
    $msg = 'BŁĄD — transakcja cofnięta: ' . $e->getMessage();
    if ($is_cli) { echo $msg . "\n"; exit(1); }
    die('<pre class="text-danger p-4">' . htmlspecialchars($msg) . '</pre>');
}

$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec('VACUUM');

// ── RAPORT ─────────────────────────────────────────────────────────────────────
if ($is_cli) {

    $section = '';
    foreach ($log as $row) {
        if (isset($row['note'])) {
            echo "\n  ── {$row['note']}\n";
            continue;
        }
        $status = $row['ok'] ? '  ✓' : '  ✗';
        $cnt    = $row['deleted'] !== null ? sprintf('%5d', $row['deleted']) : '     ';
        $err    = isset($row['err']) ? "  ← BŁĄD: {$row['err']}" : '';
        echo sprintf("%s  %-42s  %s wierszy%s\n", $status, $row['table'], $cnt, $err);
    }

    $admins = db_all("SELECT name, email, role FROM users ORDER BY role, name");
    echo "\n──────────────────────────────────────────────────────────\n";
    echo "Łącznie usunięto: {$total} wierszy\n";
    echo "\nZachowane konta:\n";
    foreach ($admins as $u) {
        echo sprintf("  %-30s  %-35s  %s\n", $u['name'], $u['email'], $u['role']);
    }
    echo "\n✓ Baza gotowa do wdrożenia na produkcję.\n\n";

} else {
    // HTML raport
    header('Content-Type: text/html; charset=utf-8');
    ?><!DOCTYPE html>
<html lang="pl">
<head><meta charset="UTF-8"><title>Czyszczenie — raport</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-4" style="max-width:860px">
  <div class="alert alert-success"><strong>✓ Czyszczenie zakończone.</strong> Usunięto łącznie <strong><?= $total ?></strong> wierszy.</div>

  <table class="table table-sm table-bordered bg-white" style="font-size:.83rem">
    <thead class="table-dark"><tr><th>Tabela</th><th class="text-end">Usunięto</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($log as $row): ?>
      <?php if (isset($row['note'])): ?>
      <tr class="table-secondary"><td colspan="3" class="fw-bold text-muted small py-1">── <?= htmlspecialchars($row['note']) ?></td></tr>
      <?php else: ?>
      <tr class="<?= $row['ok'] ? '' : 'table-danger' ?>">
        <td class="font-monospace"><?= htmlspecialchars($row['table']) ?></td>
        <td class="text-end"><?= $row['deleted'] !== null ? number_format($row['deleted']) : '—' ?></td>
        <td><?= $row['ok'] ? '✓' : '✗ ' . htmlspecialchars($row['err'] ?? '') ?></td>
      </tr>
      <?php endif; ?>
    <?php endforeach; ?>
    </tbody>
  </table>

  <h6 class="mt-4">Zachowane konta użytkowników:</h6>
  <table class="table table-sm table-bordered bg-white" style="font-size:.83rem">
    <thead class="table-light"><tr><th>Nazwa</th><th>E-mail</th><th>Rola</th></tr></thead>
    <tbody>
    <?php foreach (db_all("SELECT name,email,role FROM users ORDER BY role,name") as $u): ?>
    <tr><td><?= htmlspecialchars($u['name']) ?></td><td><?= htmlspecialchars($u['email']) ?></td><td><code><?= htmlspecialchars($u['role']) ?></code></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <div class="alert alert-warning mt-3">
    <strong>Następny krok:</strong> Usuń ten plik przed finalnym wdrożeniem:<br>
    <code>rm setup/clean_for_prod.php</code>
  </div>
</div>
</body></html>
    <?php
}
