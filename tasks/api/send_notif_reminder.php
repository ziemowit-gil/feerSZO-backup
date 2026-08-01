<?php
/**
 * tasks/api/send_notif_reminder.php
 * AJAX: wyślij przypomnienie o konfiguracji powiadomień.
 * POST { _csrf, action: 'send'|'bulk', uid? }
 *  send → uid wymaganeg
 *  bulk → wszyscy bez task_notification_prefs
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/tasks.php';
require_once dirname(__DIR__, 2) . '/includes/task_notify.php';

require_login();
header('Content-Type: application/json');

$uid = (int)(current_user()['id'] ?? 0);

$is_leader_or_admin = is_admin() || (bool)db_one(
    "SELECT 1 FROM task_workspace_members WHERE user_id=? AND role IN ('admin','editor')",
    [$uid]
);

if (!$is_leader_or_admin) {
    echo json_encode(['ok' => false, 'msg' => 'Brak uprawnień.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'msg' => 'Wymagana metoda POST.']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true) ?: [];

if (!hash_equals($_SESSION['csrf'] ?? '', $body['_csrf'] ?? '')) {
    echo json_encode(['ok' => false, 'msg' => 'Błąd CSRF — odśwież stronę.']);
    exit;
}

$settings_url = APP_URL . '/tasks/notification_settings.php';

function _snr_build_html(string $name): string {
    global $settings_url;
    $n = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $content =
        '<p>Cześć <strong>' . $n . '</strong>,</p>'
      . '<p>Twoje powiadomienia o zadaniach działają z <strong>ustawieniami domyślnymi</strong>. '
      . 'Poświęć chwilę, aby wybrać co i kiedy ma trafiać na Twoją skrzynkę.</p>'
      . '<ul style="margin:14px 0;padding-left:20px;color:#334155;font-size:14px;line-height:1.9">'
      . '<li>Przypisania do zadań</li>'
      . '<li>Komentarze i wzmianki (@)</li>'
      . '<li>Przypomnienia o terminach</li>'
      . '<li>Potwierdzenia i odrzucenia</li>'
      . '</ul>'
      . '<p style="color:#64748b;font-size:13px">Kliknij poniżej, aby przejść do ustawień.</p>';
    return _tn_tpl('Skonfiguruj powiadomienia — Zadania', 'Skonfiguruj powiadomienia — Zadania', $content, $settings_url);
}

$action = $body['action'] ?? '';

/* ── send: pojedynczy użytkownik ──────────────────────────────────────────── */
if ($action === 'send') {
    $target_uid = (int)($body['uid'] ?? 0);
    if (!$target_uid) {
        echo json_encode(['ok' => false, 'msg' => 'Brak uid.']);
        exit;
    }
    $u = db_one("SELECT id, name, email FROM users WHERE id=? AND is_active=1", [$target_uid]);
    if (!$u || !$u['email']) {
        echo json_encode(['ok' => false, 'msg' => 'Użytkownik nie ma adresu e-mail.']);
        exit;
    }
    $html = _snr_build_html($u['name']);
    $ok   = (bool)approval_send_email(
        $u['email'],
        'Skonfiguruj powiadomienia — Zadania',
        $html,
        'task_notif_reminder',
        (int)$u['id']
    );
    echo json_encode(['ok' => $ok, 'msg' => $ok
        ? 'Wysłano do ' . $u['name']
        : 'Błąd wysyłki — sprawdź konfigurację e-mail.']);
    exit;
}

/* ── bulk: wszyscy niekonfigurowany ──────────────────────────────────────── */
if ($action === 'bulk') {
    try {
        $unconfigured = db_all("
            SELECT DISTINCT u.id, u.name, u.email
            FROM users u
            JOIN task_workspace_members twm ON twm.user_id = u.id
            LEFT JOIN task_notification_prefs tnp ON tnp.user_id = u.id
            WHERE u.is_active = 1
              AND tnp.user_id IS NULL
              AND u.email IS NOT NULL AND u.email != ''
        ");
    } catch (\Throwable $e) {
        echo json_encode(['ok' => false, 'msg' => 'Błąd bazy: ' . $e->getMessage()]);
        exit;
    }
    if (!$unconfigured) {
        echo json_encode(['ok' => true, 'sent' => 0, 'failed' => 0,
            'msg' => 'Brak niekonfigurowanych użytkowników.']);
        exit;
    }
    $sent = 0; $failed = 0;
    foreach ($unconfigured as $u) {
        $html = _snr_build_html($u['name']);
        $ok   = (bool)approval_send_email(
            $u['email'],
            'Skonfiguruj powiadomienia — Zadania',
            $html,
            'task_notif_reminder',
            (int)$u['id']
        );
        $ok ? $sent++ : $failed++;
    }
    $msg = 'Wysłano: ' . $sent . ($failed ? ', błąd: ' . $failed : '') . '.';
    echo json_encode(['ok' => true, 'sent' => $sent, 'failed' => $failed, 'msg' => $msg]);
    exit;
}

echo json_encode(['ok' => false, 'msg' => 'Nieznana akcja.']);
