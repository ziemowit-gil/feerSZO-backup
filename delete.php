<?php
/**
 * delete.php — Uniwersalny handler usuwania rekordów.
 *
 * POST: table, id, redirect, _csrf
 * Konfiguracja każdej tabeli: uprawnienia, kaskady, etykieta.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

require_login();
csrf_check();

// ── Konfiguracja tabel ─────────────────────────────────────────────────────────
// role:       'admin' | 'editor' | 'can_write:moduł'
// own:        true = editor widzi tylko swoje (created_by)
// label:      fraza do komunikatu
// redirect:   domyślny URL po usunięciu (można nadpisać POST[redirect])
// cascade:    [[table, fk_column], ...]  — co usunąć najpierw
// ──────────────────────────────────────────────────────────────────────────────
$CONFIG = [
    'actions' => [
        'role'     => 'editor',
        'own'      => true,
        'label'    => 'działanie',
        'redirect' => APP_URL . '/actions/index.php',
        'cascade'  => [
            ['action_grants',     'action_id'],
            ['action_indicators', 'action_id'],
        ],
    ],
    'persons' => [
        'role'     => 'admin',
        'own'      => false,
        'label'    => 'osobę',
        'redirect' => APP_URL . '/persons/index.php',
        'cascade'  => [],
    ],
    'resolutions' => [
        'role'     => 'can_write:resolutions',
        'own'      => true,
        'label'    => 'uchwałę/decyzję',
        'redirect' => APP_URL . '/resolutions/index.php',
        'cascade'  => [],
    ],
    'grants' => [
        'role'     => 'admin',
        'own'      => false,
        'label'    => 'grant',
        'redirect' => APP_URL . '/grants/index.php',
        'cascade'  => [
            ['action_grants',    'grant_id'],
            ['grant_budgets',    'grant_id'],
            ['grant_activities', 'grant_id'],
        ],
    ],
    'correspondence' => [
        'role'     => 'can_write:correspondence',
        'own'      => true,
        'label'    => 'korespondencję',
        'redirect' => APP_URL . '/correspondence/index.php',
        'cascade'  => [],
    ],
    'contract_letters' => [
        'role'     => 'editor',
        'own'      => true,
        'label'    => 'pismo',
        'redirect' => APP_URL . '/contracts/letters/index.php',
        'cascade'  => [],
    ],
    'volunteer_offers' => [
        'role'     => 'editor',
        'own'      => true,
        'label'    => 'ofertę wolontariatu',
        'redirect' => APP_URL . '/contracts/rekrutacja/index.php',
        'cascade'  => [
            ['volunteer_applications', 'volunteer_offer_id'],
        ],
    ],
    'zwroty_kosztow' => [
        'role'     => 'admin',
        'own'      => false,
        'label'    => 'zwrot kosztów',
        'redirect' => APP_URL . '/contracts/zwroty/index.php',
        'cascade'  => [
            ['zwroty_pozycje', 'zwrot_id'],
        ],
    ],
    'task_tags' => [
        'role'     => 'admin',
        'own'      => false,
        'label'    => 'tag',
        'redirect' => APP_URL . '/admin/tasks_tags.php',
        'cascade'  => [
            ['task_task_tags', 'tag_id'],
        ],
    ],
    'timesheets' => [
        'role'     => 'admin',
        'own'      => false,
        'label'    => 'kartę czasu pracy',
        'redirect' => APP_URL . '/admin/timesheets.php',
        'cascade'  => [
            ['timesheet_entries', 'timesheet_id'],
        ],
    ],
    'onboarding_volunteers' => [
        'role'     => 'admin',
        'own'      => false,
        'label'    => 'rekrutację onboardingu',
        'redirect' => APP_URL . '/onboarding/index.php',
        'cascade'  => [],
    ],
];

// ── Walidacja wejścia ──────────────────────────────────────────────────────────
$table    = $_POST['table']    ?? '';
$id       = (int)($_POST['id'] ?? 0);
$redirect = trim($_POST['redirect'] ?? '');

if (!isset($CONFIG[$table]) || $id <= 0) {
    flash_set('danger', 'Nieprawidłowe żądanie usunięcia.');
    header('Location: ' . APP_URL . '/index.php');
    exit;
}

$cfg   = $CONFIG[$table];
$user  = current_user();
$role  = $user['role'] ?? '';

// ── Sprawdzenie uprawnień ──────────────────────────────────────────────────────
$allowed = false;

if (str_starts_with($cfg['role'], 'can_write:')) {
    $module   = substr($cfg['role'], strlen('can_write:'));
    require_once __DIR__ . '/includes/permissions.php';
    $allowed  = can_write($module);
} elseif ($cfg['role'] === 'admin') {
    $allowed  = is_admin();
} elseif ($cfg['role'] === 'editor') {
    $allowed  = in_array($role, ['admin', 'editor'], true);
}

if (!$allowed) {
    flash_set('danger', 'Brak uprawnień do usuwania.');
    header('Location: ' . ($redirect ?: $cfg['redirect']));
    exit;
}

// ── Pobierz rekord (sprawdź istnienie + created_by dla editorów) ───────────────
$row = db_one("SELECT * FROM {$table} WHERE id = ?", [$id]);
if (!$row) {
    flash_set('warning', 'Rekord nie istnieje lub już został usunięty.');
    header('Location: ' . ($redirect ?: $cfg['redirect']));
    exit;
}

if (!empty($cfg['own']) && $role === 'editor') {
    if ((int)($row['created_by'] ?? -1) !== (int)$user['id']) {
        flash_set('danger', 'Możesz usuwać tylko rekordy dodane przez siebie.');
        header('Location: ' . ($redirect ?: $cfg['redirect']));
        exit;
    }
}

// ── Usuwanie w transakcji ──────────────────────────────────────────────────────
$label_id = $row['numer_umowy']
         ?? $row['number']
         ?? $row['title']
         ?? $row['nazwa']
         ?? $row['name']
         ?? $row['tytul']
         ?? $row['subject']
         ?? "#{$id}";

try {
    db()->beginTransaction();

    foreach ($cfg['cascade'] as [$ctable, $cfk]) {
        try {
            db()->prepare("DELETE FROM {$ctable} WHERE {$cfk} = ?")->execute([$id]);
        } catch (\Throwable $e) {
            // Tabela może nie istnieć w tej instalacji
        }
    }

    db()->prepare("DELETE FROM {$table} WHERE id = ?")->execute([$id]);
    db()->commit();

    try {
        require_once __DIR__ . '/includes/approval.php';
        log_user_action((int)$user['id'], (int)$user['id'], "delete:{$table}", "Usunięto {$cfg['label']}: {$label_id}");
    } catch (\Throwable $e) {}

    flash_set('success', ucfirst($cfg['label']) . ' "' . $label_id . '" zostal(a) usuniety/a.');
} catch (\Throwable $e) {
    db()->rollBack();
    flash_set('danger', 'Błąd podczas usuwania: ' . $e->getMessage());
}

header('Location: ' . ($redirect ?: $cfg['redirect']));
exit;
