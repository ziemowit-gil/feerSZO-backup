<?php
/**
 * helpdesk/api/bulk.php — Masowe akcje na zgłoszeniach.
 * POST JSON: { _csrf, action, ids[], value? }
 * Akcje: set_status, assign, close, set_priority, set_category, add_tag, remove_tag, apply_macro
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/helpdesk.php';
require_once dirname(__DIR__, 2) . '/modules/helpdeskCollab/lib/helpdeskCollab.php';
require_once dirname(__DIR__, 2) . '/modules/helpdeskMacros/lib/helpdeskMacros.php';
header('Content-Type: application/json; charset=UTF-8');

function je(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

auth_start();
if (!isset($_SESSION['user_id'])) je('Brak sesji', 401);
if (!hd_is_operator()) je('Brak uprawnień', 403);

$raw    = file_get_contents('php://input');
$data   = json_decode($raw, true);
if (!$data) je('Nieprawidłowy JSON');

if (($data['_csrf'] ?? '') !== ($_SESSION['csrf'] ?? '')) je('Błąd CSRF', 403);

$action   = $data['action'] ?? '';
$ids      = array_map('intval', (array)($data['ids'] ?? []));
$value    = trim((string)($data['value'] ?? ''));
$ids      = array_filter($ids);

if (!$ids) je('Brak wybranych zgłoszeń');

helpdesk_migrate();
$u   = current_user();
$uid = (int)$u['id'];
$now = date('Y-m-d H:i:s');

$placeholders = implode(',', array_fill(0, count($ids), '?'));

switch ($action) {

    case 'set_status':
        if (!isset(HD_STATUSES[$value])) je('Nieprawidłowy status');
        $tickets = db_all("SELECT * FROM helpdesk_tickets WHERE id IN ({$placeholders})", $ids);
        $affected = 0;
        foreach ($tickets as $t) {
            if ($t['status'] === $value) continue;
            $extra = [];
            if ($value === 'rozwiązane') $extra['resolved_at'] = $now;
            if ($value === 'zamknięte')  $extra['closed_at']   = $now;
            db_update('helpdesk_tickets', array_merge(['status' => $value, 'updated_at' => $now], $extra), (int)$t['id']);
            db_insert('helpdesk_messages', [
                'ticket_id'   => (int)$t['id'],
                'user_id'     => $uid,
                'user_name'   => $u['name'] ?? '',
                'body'        => 'Status zmieniony grupowo: ' . (HD_STATUSES[$value]['label'] ?? $value),
                'is_internal' => 1,
            ]);
            try { hd_notify_status_change($t, $t['status'], $value); } catch (\Throwable $e) {}
            $affected++;
        }
        echo json_encode(['ok' => true, 'affected' => $affected], JSON_UNESCAPED_UNICODE);
        break;

    case 'assign':
        if (!is_admin() && !hd_is_operator()) je('Brak uprawnień');
        $assign_to = $value !== '' ? (int)$value : null;
        if ($assign_to !== null) {
            $op = db_one("SELECT id, name, email FROM users WHERE id=?", [$assign_to]);
            if (!$op) je('Nie znaleziono operatora');
        }
        $tickets = db_all("SELECT * FROM helpdesk_tickets WHERE id IN ({$placeholders})", $ids);
        $affected = 0;
        foreach ($tickets as $t) {
            $new_status = ($assign_to && $t['status'] === 'nowe') ? 'otwarte' : $t['status'];
            db_update('helpdesk_tickets', [
                'assigned_to' => $assign_to,
                'status'      => $new_status,
                'updated_at'  => $now,
            ], (int)$t['id']);
            if ($assign_to && isset($op)) {
                db_insert('helpdesk_messages', [
                    'ticket_id'   => (int)$t['id'],
                    'user_id'     => $uid,
                    'user_name'   => $u['name'] ?? '',
                    'body'        => 'Przypisano operatora grupowo: ' . ($op['name'] ?? ''),
                    'is_internal' => 1,
                ]);
                try { hd_notify_assigned($t, $op); } catch (\Throwable $e) {}
            }
            $affected++;
        }
        echo json_encode(['ok' => true, 'affected' => $affected], JSON_UNESCAPED_UNICODE);
        break;

    case 'close':
        $tickets = db_all("SELECT * FROM helpdesk_tickets WHERE id IN ({$placeholders})", $ids);
        $affected = 0;
        foreach ($tickets as $t) {
            if ($t['status'] === 'zamknięte') continue;
            db_update('helpdesk_tickets', ['status' => 'zamknięte', 'closed_at' => $now, 'updated_at' => $now], (int)$t['id']);
            db_insert('helpdesk_messages', [
                'ticket_id'   => (int)$t['id'],
                'user_id'     => $uid,
                'user_name'   => $u['name'] ?? '',
                'body'        => 'Zgłoszenie zamknięte grupowo.',
                'is_internal' => 1,
            ]);
            try { hd_notify_status_change($t, $t['status'], 'zamknięte'); } catch (\Throwable $e) {}
            $affected++;
        }
        echo json_encode(['ok' => true, 'affected' => $affected], JSON_UNESCAPED_UNICODE);
        break;

    case 'set_priority':
        if (!isset(HD_PRIORITIES[$value])) je('Nieprawidłowy priorytet');
        $affected = 0;
        foreach (db_all("SELECT * FROM helpdesk_tickets WHERE id IN ({$placeholders})", $ids) as $t) {
            if ($t['priority'] === $value) continue;
            db_update('helpdesk_tickets', ['priority' => $value, 'updated_at' => $now], (int)$t['id']);
            db_insert('helpdesk_messages', ['ticket_id' => (int)$t['id'], 'user_id' => $uid, 'user_name' => $u['name'] ?? '',
                'body' => 'Priorytet zmieniony grupowo: ' . HD_PRIORITIES[$t['priority']]['label'] . ' → ' . HD_PRIORITIES[$value]['label'], 'is_internal' => 1]);
            $affected++;
        }
        echo json_encode(['ok' => true, 'affected' => $affected], JSON_UNESCAPED_UNICODE);
        break;

    case 'set_category':
        if (!isset(hd_categories()[$value])) je('Nieprawidłowa kategoria');
        $affected = 0;
        foreach (db_all("SELECT * FROM helpdesk_tickets WHERE id IN ({$placeholders})", $ids) as $t) {
            if ($t['category'] === $value) continue;
            db_update('helpdesk_tickets', ['category' => $value, 'updated_at' => $now], (int)$t['id']);
            db_insert('helpdesk_messages', ['ticket_id' => (int)$t['id'], 'user_id' => $uid, 'user_name' => $u['name'] ?? '',
                'body' => 'Kategoria zmieniona grupowo: ' . (hd_categories()[$t['category']] ?? $t['category']) . ' → ' . hd_categories()[$value], 'is_internal' => 1]);
            $affected++;
        }
        echo json_encode(['ok' => true, 'affected' => $affected], JSON_UNESCAPED_UNICODE);
        break;

    case 'add_tag':
    case 'remove_tag':
        $tag = hdTagNormalize($value);
        if (mb_strlen($tag) < 2 || mb_strlen($tag) > 30) je('Tag musi mieć 2–30 znaków');
        $affected = 0;
        foreach (db_all("SELECT id FROM helpdesk_tickets WHERE id IN ({$placeholders})", $ids) as $t) {
            $has = in_array($tag, hdTagsOf((int)$t['id']), true);
            if ($action === 'add_tag' && !$has && hdTagAdd((int)$t['id'], $tag) === null) $affected++;
            if ($action === 'remove_tag' && $has) { hdTagRemove((int)$t['id'], $tag); $affected++; }
        }
        echo json_encode(['ok' => true, 'affected' => $affected], JSON_UNESCAPED_UNICODE);
        break;

    case 'apply_macro':
        $mid = (int)$value;
        $m = db_one("SELECT * FROM helpdesk_macros WHERE id=? AND is_active=1", [$mid]);
        if (!$m || !hdMacroHasActions($m)) je('Makro nie ma kroków do wykonania');
        $affected = 0;
        foreach ($ids as $tid) if (hdMacroApply((int)$tid, $mid, $uid, (string)($u['name'] ?? ''))) $affected++;
        echo json_encode(['ok' => true, 'affected' => $affected], JSON_UNESCAPED_UNICODE);
        break;

    default:
        je('Nieznana akcja');
}
