<?php
/**
 * helpdesk/view.php — szczegóły zgłoszenia.
 *  - tryb normalny: pełna strona (np. z linków w mailach)
 *  - ?_pane=1: zwraca sam fragment szczegółów (do konsoli helpdesk/index.php)
 *  - akcje POST: zwykłe → redirect; XHR (X-Requested-With) → JSON dla konsoli
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/helpdesk.php';
helpdesk_migrate();
require_login();

$u   = current_user();
$uid = (int)$u['id'];
$id  = (int)($_GET['id'] ?? 0);
$is_op = hd_is_operator();
$xhr = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

function hd_json(array $d): void {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}
/** Zakończ akcję POST: JSON dla XHR (konsola), redirect dla zwykłego żądania. */
function hd_finish(bool $xhr, int $ticket_id, array $extra = []): void {
    if ($xhr) hd_json(array_merge(['ok' => true, 'ticket_id' => $ticket_id, 'flash' => flash_get()], $extra));
    header('Location: view.php?id=' . $ticket_id); exit;
}

$ticket = $id ? db_one("SELECT t.*, op.name AS assigned_name, op.email AS assigned_email
    FROM helpdesk_tickets t LEFT JOIN users op ON op.id = t.assigned_to WHERE t.id = ?", [$id]) : null;

if (!$ticket) {
    if ($xhr) hd_json(['ok' => false, 'error' => 'Zgłoszenie nie istnieje.']);
    http_response_code(404); die('Zgłoszenie nie istnieje.');
}
if (!hd_can_view_ticket($ticket)) {
    if ($xhr) hd_json(['ok' => false, 'error' => 'Brak dostępu.']);
    flash_set('danger', 'Brak dostępu do tego zgłoszenia.');
    header('Location: ' . APP_URL . '/helpdesk/index.php'); exit;
}

// ── Zmiana statusu ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_set_status']) && $is_op) {
    csrf_check();
    $new_status = $_POST['status'] ?? '';
    $note       = trim($_POST['status_note'] ?? '');
    if (isset(HD_STATUSES[$new_status]) && $new_status !== $ticket['status']) {
        $old_status = $ticket['status'];
        $extra = [];
        if ($new_status === 'rozwiązane') $extra['resolved_at'] = date('Y-m-d H:i:s');
        if ($new_status === 'zamknięte')  $extra['closed_at']   = date('Y-m-d H:i:s');
        if ($new_status === 'przekazane_zewn') {
            $ext_vendor = trim($_POST['ext_vendor'] ?? '');
            $ext_ref    = trim($_POST['ext_ref'] ?? '');
            $ext_reason = trim($_POST['ext_reason'] ?? '');
            $extra['ext_vendor']    = $ext_vendor;
            $extra['ext_ref']       = $ext_ref;
            $extra['ext_reason']    = $ext_reason;
            $extra['ext_handed_at'] = date('Y-m-d H:i:s');
            if ($note === '') {
                $note = trim(($ext_vendor !== '' ? "Firma: {$ext_vendor}\n" : '')
                           . ($ext_ref    !== '' ? "Nr zgłoszenia u firmy: {$ext_ref}\n" : '')
                           . ($ext_reason !== '' ? "Powód: {$ext_reason}" : ''));
            }
        }
        db_update('helpdesk_tickets', array_merge(['status' => $new_status, 'updated_at' => date('Y-m-d H:i:s')], $extra), $id);
        if ($note) {
            db_insert('helpdesk_messages', ['ticket_id' => $id, 'user_id' => $uid, 'user_name' => $u['name'] ?? '', 'body' => $note, 'is_internal' => 1]);
        }
        $ticket = array_merge($ticket, ['status' => $new_status], $extra);
        hd_notify_status_change($ticket, $old_status, $new_status, $note);
        $msg = 'Status zmieniony: ' . (HD_STATUSES[$new_status]['label'] ?? $new_status);

        if ($new_status === 'przekazane_zewn' && !empty($_POST['share_with_vendor'])) {
            $se = trim($_POST['share_email'] ?? '');
            $sn = trim($_POST['share_name'] ?? '');
            if (hd_share_ticket($ticket, $se, $sn, $extra['ext_reason'] ?? '', $u['name'] ?? '')) {
                db_insert('helpdesk_messages', ['ticket_id' => $id, 'user_id' => $uid, 'user_name' => $u['name'] ?? '',
                    'body' => 'Udostępniono podgląd zgłoszenia: ' . ($sn !== '' ? "{$sn} <{$se}>" : $se), 'is_internal' => 1]);
                $msg .= '. Link wysłano do: ' . $se;
            } elseif ($se !== '') {
                $msg .= '. Uwaga: nie udało się wysłać linku (sprawdź adres e-mail).';
            }
        }
        flash_set('success', $msg);
    }
    hd_finish($xhr, $id);
}

// ── Udostępnienie podglądu innej osobie ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_share']) && $is_op) {
    csrf_check();
    $se = trim($_POST['share_email'] ?? '');
    $sn = trim($_POST['share_name'] ?? '');
    $snote = trim($_POST['share_note'] ?? '');
    if (hd_share_ticket($ticket, $se, $sn, $snote, $u['name'] ?? '')) {
        db_insert('helpdesk_messages', ['ticket_id' => $id, 'user_id' => $uid, 'user_name' => $u['name'] ?? '',
            'body' => 'Udostępniono podgląd zgłoszenia: ' . ($sn !== '' ? "{$sn} <{$se}>" : $se), 'is_internal' => 1]);
        flash_set('success', 'Link do podglądu wysłano do: ' . $se);
    } else {
        flash_set('danger', 'Nie udało się udostępnić — sprawdź adres e-mail.');
    }
    hd_finish($xhr, $id);
}

// ── Przypisanie operatora ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_assign']) && $is_op) {
    csrf_check();
    $assign_to = (int)($_POST['assign_to'] ?? 0) ?: null;
    db_update('helpdesk_tickets', ['assigned_to' => $assign_to, 'updated_at' => date('Y-m-d H:i:s'),
        'status' => $ticket['status'] === 'nowe' ? 'otwarte' : $ticket['status']], $id);
    if ($assign_to) {
        $op = db_one("SELECT email, name FROM users WHERE id=?", [$assign_to]);
        if ($op) hd_notify_assigned($ticket, $op);
        if ($ticket['status'] === 'nowe') hd_notify_status_change($ticket, 'nowe', 'otwarte');
    }
    flash_set('success', $assign_to ? 'Przypisano operatora.' : 'Usunięto przypisanie.');
    hd_finish($xhr, $id);
}

// ── Przypisz do siebie ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_take']) && $is_op) {
    csrf_check();
    $old_status = $ticket['status'];
    $new_status = $old_status === 'nowe' ? 'otwarte' : $old_status;
    db_update('helpdesk_tickets', ['assigned_to' => $uid, 'status' => $new_status, 'updated_at' => date('Y-m-d H:i:s')], $id);
    if ($old_status === 'nowe') hd_notify_status_change($ticket, 'nowe', 'otwarte');
    flash_set('success', 'Zgłoszenie przypisane do Ciebie.');
    hd_finish($xhr, $id);
}

// ── Dodaj wiadomość ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_add_msg'])) {
    csrf_check();
    $body        = hd_sanitize_body(trim($_POST['msg_body'] ?? ''));
    $is_internal = $is_op && !empty($_POST['is_internal']) ? 1 : 0;
    if ($body) {
        $msg_id = db_insert('helpdesk_messages', ['ticket_id' => $id, 'user_id' => $uid,
            'user_name' => $u['name'] ?? '', 'body' => $body, 'is_internal' => $is_internal]);
        db_update('helpdesk_tickets', ['updated_at' => date('Y-m-d H:i:s')], $id);
        hd_redmine_push_note($id, $body, $uid, (bool)$is_internal); // komentarz zwrotny → Redmine

        if ($is_op && !$is_internal && empty($ticket['first_response_at'])) {
            db_update('helpdesk_tickets', ['first_response_at' => date('Y-m-d H:i:s')], $id);
        }

        if (!empty($_FILES['msg_attachments']['name'][0])) {
            $dir = UPLOAD_DIR . 'helpdesk/';
            if (!is_dir($dir)) @mkdir($dir, 0755, true);
            foreach ($_FILES['msg_attachments']['name'] as $i => $orig_name) {
                if ($_FILES['msg_attachments']['error'][$i] !== UPLOAD_ERR_OK) continue;
                $ext   = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
                $allow = ['pdf','doc','docx','xls','xlsx','jpg','jpeg','png','gif','zip','txt','csv'];
                if (!in_array($ext, $allow, true)) continue;
                $stored = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                if (@move_uploaded_file($_FILES['msg_attachments']['tmp_name'][$i], $dir . $stored)) {
                    db_insert('helpdesk_attachments', ['ticket_id' => $id, 'message_id' => $msg_id,
                        'original_name' => $orig_name, 'stored_path' => 'helpdesk/' . $stored,
                        'file_size' => $_FILES['msg_attachments']['size'][$i], 'uploaded_by' => $uid]);
                }
            }
        }

        if (!$is_op && $ticket['status'] === 'oczekuje') {
            db_update('helpdesk_tickets', ['status' => 'otwarte', 'updated_at' => date('Y-m-d H:i:s')], $id);
        }
        if (!$is_internal) {
            $fresh = db_one("SELECT t.*, op.email AS assigned_email FROM helpdesk_tickets t LEFT JOIN users op ON op.id=t.assigned_to WHERE t.id=?", [$id]);
            hd_notify_new_message($fresh ?? $ticket, ['user_id' => $uid, 'user_name' => $u['name'] ?? '', 'body' => $body, 'is_internal' => 0]);
        }
        flash_set('success', 'Wiadomość dodana.');
    }
    hd_finish($xhr, $id);
}

// ── Łączenie (scalanie) zgłoszeń ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_merge']) && $is_op) {
    csrf_check();
    $target_num = trim($_POST['merge_target'] ?? '');
    $tgt = $target_num !== '' ? db_one("SELECT id FROM helpdesk_tickets WHERE number=?", [$target_num]) : null;
    if (!$tgt && ctype_digit($target_num)) $tgt = db_one("SELECT id FROM helpdesk_tickets WHERE id=?", [(int)$target_num]);
    if (!$tgt) {
        flash_set('danger', 'Nie znaleziono zgłoszenia docelowego o numerze: ' . h($target_num));
        hd_finish($xhr, $id);
    }
    $res = hd_merge($id, (int)$tgt['id'], $u);
    if (!empty($res['ok'])) {
        flash_set('success', 'Połączono ze zgłoszeniem ' . h($res['target']['number'] ?? ''));
        hd_finish($xhr, (int)$tgt['id']);
    }
    flash_set('danger', h($res['error'] ?? 'Nie udało się połączyć zgłoszeń.'));
    hd_finish($xhr, $id);
}

// ── Podbij zgłoszenie — brak reakcji ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_escalate'])) {
    csrf_check();
    if ($ticket['status'] === 'zamknięte') {
        flash_set('warning', 'Zgłoszenie jest zamknięte — nie można go podbić.');
        hd_finish($xhr, $id);
    }
    $reason = trim($_POST['escalate_reason'] ?? '');
    $esc = hd_escalate($ticket, $reason, ['id' => $uid, 'name' => $u['name'] ?? '']);
    flash_set('success', 'Zgłoszenie podbite — brak reakcji, sprawa przechodzi na 3. linię wsparcia. Nr podbicia: ' . $esc['number'] . '. Potwierdzenie PDF dostępne w sekcji „Podbicia" poniżej.');
    hd_finish($xhr, $id, ['escalation_number' => $esc['number'], 'escalation_id' => $esc['id']]);
}

// ── Firma zewnętrzna nie odpowiada — od razu twórz sprawę w EZD ───────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_vendor_no_response']) && $is_op) {
    csrf_check();
    $description = trim($_POST['vendor_situation'] ?? '');
    if ($description === '') {
        flash_set('danger', 'Opisz sytuację przed założeniem sprawy.');
        hd_finish($xhr, $id);
    }
    $case = hd_vendor_no_response($ticket, $description, ['id' => $uid, 'name' => $u['name'] ?? '']);
    if ($case) {
        flash_set('success', 'Założono sprawę ' . $case['znak_sprawy'] . ' w module EZD Wirtualne biurko — do załatwienia.');
        hd_finish($xhr, $id, ['sprawa_id' => $case['sprawa_id'], 'znak_sprawy' => $case['znak_sprawy']]);
    }
    flash_set('warning', 'Moduł EZD Wirtualne biurko jest wyłączony — sprawy nie założono.');
    hd_finish($xhr, $id);
}

// ── Upadłość firmy zewnętrznej — skierowanie do rozwiązania zastępczego ──────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_vendor_insolvent']) && $is_op) {
    csrf_check();
    $description = trim($_POST['vendor_insolvent_desc'] ?? '');
    if ($description === '') {
        flash_set('danger', 'Opisz sytuację przed skierowaniem do rozwiązania zastępczego.');
        hd_finish($xhr, $id);
    }
    $case = hd_vendor_substitute_resolution($ticket, $description, ['id' => $uid, 'name' => $u['name'] ?? '']);
    $msg = 'Zgłoszenie skierowane do rozwiązania zastępczego.';
    if ($case['znak_sprawy'] !== '') $msg .= ' Założono pilną sprawę w EZD: ' . $case['znak_sprawy'] . '.';
    flash_set('success', $msg);
    hd_finish($xhr, $id, $case);
}

// ── Usuń zgłoszenie (admin) ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_delete']) && is_admin()) {
    csrf_check();
    db()->prepare("DELETE FROM helpdesk_tickets WHERE id=?")->execute([$id]);
    flash_set('success', 'Zgłoszenie usunięte.');
    if ($xhr) hd_json(['ok' => true, 'deleted' => true, 'flash' => flash_get()]);
    header('Location: ' . APP_URL . '/helpdesk/index.php'); exit;
}

// ── Dane do widoku ────────────────────────────────────────────────────────────
$ticket    = db_one("SELECT t.*, op.name AS assigned_name,
        req.name AS requester_account_name, req.role AS requester_role
    FROM helpdesk_tickets t
    LEFT JOIN users op  ON op.id=t.assigned_to
    LEFT JOIN users req ON req.id=t.requester_id
    WHERE t.id=?", [$id]);
$messages  = db_all("SELECT m.*, u.email AS user_email
    FROM helpdesk_messages m LEFT JOIN users u ON u.id=m.user_id
    WHERE m.ticket_id=? " . ($is_op ? '' : "AND m.is_internal=0") . "
    ORDER BY m.created_at ASC", [$id]);
$atts      = db_all("SELECT * FROM helpdesk_attachments WHERE ticket_id=? ORDER BY uploaded_at ASC", [$id]);
$operators = $is_op ? db_all(
    "SELECT id, name FROM users WHERE (helpdesk_operator=1 OR role='admin') AND is_active=1 ORDER BY name", []
) : [];
$escalations = hd_escalations_for_ticket($id);
$vendor_cases = $is_op ? hd_vendor_cases($id) : [];

// ── Tryb pane (fragment do konsoli) ────────────────────────────────────────────
if (isset($_GET['_pane'])) {
    $GLOBALS['hd_pane_mode'] = true;
    include __DIR__ . '/_detail.php';
    exit;
}

// ── Strona samodzielna ──────────────────────────────────────────────────────────
$PAGE_TITLE = $ticket['number'] . ' — ' . $ticket['title'];
include dirname(__DIR__) . '/includes/header.php';
echo hd_ui_css();
include __DIR__ . '/_detail.php';
include dirname(__DIR__) . '/includes/footer.php';
