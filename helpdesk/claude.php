<?php
/**
 * helpdesk/claude.php — kreator zgłoszeń „Dostęp do Claude” (migracja @edu → @stud, logowanie, brak dostępu, błędy).
 * Tylko administrator: zgłasza w imieniu osoby (często kursanta), bez weryfikacji tożsamości. Logika: modules/helpdeskClaude/lib.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/helpdesk.php';
require_once dirname(__DIR__) . '/modules/helpdeskClaude/lib/helpdeskClaude.php';
helpdesk_migrate();
require_login();

if (!is_admin()) {
    http_response_code(403);
    $PAGE_TITLE = 'Brak uprawnień';
    include dirname(__DIR__) . '/includes/header.php';
    echo '<div class="container mt-5"><div class="alert alert-danger">Kreator zgłoszeń dotyczących Claude jest dostępny tylko dla administratora.</div></div>';
    include dirname(__DIR__) . '/includes/footer.php';
    exit;
}

$u = current_user();
$errors = [];
$old = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $old = $_POST;
    [$errors, $data] = hdcValidate($_POST);
    if (!$errors) {
        $res = hdcCreate($data, $u, $_FILES['attachments'] ?? []);
        $ack = $res['ack'];
        flash_set('success', "Zgłoszenie {$res['number']} zostało zarejestrowane. Potwierdzenie dla zgłaszającego: SMS {$ack['sms']}, e-mail {$ack['email']}.");
        header('Location: ' . APP_URL . '/helpdesk/view.php?id=' . $res['id']);
        exit;
    }
}

$PAGE_TITLE = 'Dostęp do Claude: nowe zgłoszenie — Helpdesk';
include dirname(__DIR__) . '/includes/header.php';
echo hd_ui_css();
require dirname(__DIR__) . '/modules/helpdeskClaude/view/wizard.php';
include dirname(__DIR__) . '/includes/footer.php';
