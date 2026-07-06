<?php
/**
 * contracts/bulk_action.php — obsługuje akcje masowe na umowach.
 * POST JSON endpoint, zwraca {"ok":bool,"count":int,"msg":string}
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/amendments.php';
require_once dirname(__DIR__) . '/includes/contract_access.php';

header('Content-Type: application/json; charset=utf-8');

function _json(bool $ok, int $count = 0, string $msg = ''): never {
    echo json_encode(['ok' => $ok, 'count' => $count, 'msg' => $msg]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') _json(false, 0, 'Wymagana metoda POST.');
if (!current_user())                       _json(false, 0, 'Brak autoryzacji.');

// CSRF
if (!hash_equals(csrf_token(), $_POST['_csrf'] ?? '')) _json(false, 0, 'Nieprawidłowy token CSRF.');

$action = $_POST['action'] ?? '';
$type   = $_POST['type']   ?? '';
$ids    = array_values(array_filter(array_map('intval', (array)($_POST['ids'] ?? []))));

$valid_types = ['wolontariat', 'zlecenie', 'dzielo', 'praca', 'uslugi', 'powierzenie', 'inne'];
if (!in_array($type, $valid_types, true)) _json(false, 0, 'Nieznany typ umowy.');
if (!$ids)                                 _json(false, 0, 'Nie wskazano żadnych rekordów.');

$tbl_map = [
    'wolontariat' => 'umowy_wolontariat',
    'zlecenie'    => 'umowy_zlecenie',
    'dzielo'      => 'umowy_dzielo',
    'praca'       => 'umowy_praca',
    'uslugi'      => 'umowy_uslugi',
    'powierzenie' => 'umowy_powierzenie',
    'inne'        => 'umowy_inne',
];
$tbl = $tbl_map[$type];

// Weryfikacja: tylko rekordy istniejące w tej tabeli i dostępne dla zalogowanego użytkownika
$ph   = implode(',', array_fill(0, count($ids), '?'));
$rows = db_all("SELECT id FROM {$tbl} WHERE id IN ({$ph}) AND " . contract_access_where($type), $ids);
$ids  = array_column($rows, 'id');
if (!$ids) _json(false, 0, 'Nie znaleziono wskazanych rekordów.');

// ── Akcja: Aneks ────────────────────────────────────────────────────────────
if ($action === 'amendment') {
    if (!can_edit()) _json(false, 0, 'Brak uprawnień do składania aneksów.');
    $numer = trim($_POST['numer'] ?? '');
    $opis  = trim($_POST['opis']  ?? '');
    if (!$numer) _json(false, 0, 'Brakuje oznaczenia aneksu.');
    if (!$opis)  _json(false, 0, 'Brakuje opisu zmian.');

    $uid   = (int)(current_user()['id'] ?? 0);
    $count = 0;
    foreach ($ids as $id) {
        try {
            submit_amendment($type, (int)$id, $uid, $numer, $opis, null);
            $count++;
        } catch (\Throwable $e) {
            error_log("[bulk_amendment] type={$type} id={$id}: " . $e->getMessage());
        }
    }
    _json(true, $count);
}

// ── Akcja: Zmień dostęp ─────────────────────────────────────────────────────
if ($action === 'set_access') {
    if (!can_edit()) _json(false, 0, 'Brak uprawnień do zmiany dostępu.');
    $allowed = ['full', 'tasks_only', 'crm_only'];
    $level   = in_array($_POST['level'] ?? '', $allowed, true) ? $_POST['level'] : null;
    if (!$level) _json(false, 0, 'Nieprawidłowy poziom dostępu.');

    // Idempotentne dodanie kolumny access_level (jeśli jeszcze nie istnieje)
    try { db()->exec("ALTER TABLE {$tbl} ADD COLUMN access_level TEXT NOT NULL DEFAULT 'full'"); }
    catch (\Throwable $e) {}

    $count = 0;
    foreach ($ids as $id) {
        try {
            db_update($tbl, ['access_level' => $level], (int)$id);
            $count++;
        } catch (\Throwable $e) {
            error_log("[bulk_access] type={$type} id={$id}: " . $e->getMessage());
        }
    }
    _json(true, $count);
}

// ── Akcja: Zmień status ─────────────────────────────────────────────────────
if ($action === 'set_status') {
    if (!can_edit()) _json(false, 0, 'Brak uprawnień do zmiany statusu.');
    $status = trim($_POST['status'] ?? '');
    if (!array_key_exists($status, STATUS_LABELS)) _json(false, 0, 'Nieprawidłowy status.');

    require_once dirname(__DIR__) . '/includes/approval.php';
    $uid   = (int)(current_user()['id'] ?? 0);
    $count = 0;
    foreach ($ids as $id) {
        try {
            $old = db_one("SELECT status FROM {$tbl} WHERE id=?", [(int)$id]);
            if (($old['status'] ?? '') === $status) continue;  // bez zmian — pomiń
            db_update($tbl, ['status' => $status], (int)$id);
            log_contract_action($type, (int)$id, $uid, 'status',
                'Masowa zmiana statusu: ' . ($old['status'] ?? '—') . ' → ' . $status);
            $count++;
        } catch (\Throwable $e) {
            error_log("[bulk_status] type={$type} id={$id}: " . $e->getMessage());
        }
    }
    _json(true, $count);
}

_json(false, 0, 'Nieznana akcja.');
