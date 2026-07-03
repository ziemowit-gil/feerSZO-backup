<?php
/**
 * crm/mosaico/dl.php — odpowiednik `emailProcessorBackend` z kontraktu Mosaico.
 *
 * POST action=download|email, pole `html` z finalnym (zainlinowanym po stronie
 * klienta) HTML-em. Adres tego endpointu jest wstrzyknięty w crm/mosaico/editor.php
 * razem z `?template_id=&_csrf=` — Mosaico nie pozwala dołożyć własnych pól do
 * ciała POST, więc identyfikator szablonu i token CSRF przekazujemy w query stringu.
 *
 * `action=download` przy okazji ZAPISUJE aktualny stan do crm_templates —
 * to nasz jedyny punkt zapisu (Mosaico nie ma własnego API „zapisz do CRM”).
 */

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/crm.php';
require_once dirname(__DIR__, 2) . '/includes/mail_queue.php';

require_login();
require_module_enabled('crm_enabled', 'Moduł CRM');
crm_migrate();

// CSRF — Mosaico POST-uje własnym, sztywnym ciałem (action/html/filename/rcpt/subject),
// więc token sprawdzamy z query stringu (ustawionego przez editor.php), nie z $_POST.
if (($_GET['_csrf'] ?? '') === '' || !hash_equals(csrf_token(), (string)$_GET['_csrf'])) {
    http_response_code(403);
    exit('Błąd CSRF.');
}

$can_write = can_write('crm') || is_admin();
if (!$can_write) { http_response_code(403); exit; }

$template_id = (int)($_GET['template_id'] ?? 0);
$tpl = $template_id ? db_one("SELECT * FROM crm_templates WHERE id=?", [$template_id]) : null;
if (!$tpl || $tpl['source'] !== 'mosaico') { http_response_code(404); exit('Nieznany szablon Mosaico.'); }
if ((int)($tpl['is_locked'] ?? 0) === 1 && !is_admin()) { http_response_code(403); exit('Szablon zastrzeżony.'); }

$action = $_POST['action'] ?? '';
$html   = (string)($_POST['html'] ?? '');

if ($action === 'download') {
    db()->prepare("UPDATE crm_templates SET body=?, is_active=1, updated_at=datetime('now') WHERE id=?")
        ->execute([$html, $template_id]);

    // Wołane przez nasz custom przycisk "Zapisz szablon" (plugin w editor.php) via AJAX —
    // odpowiedź JSON, nie prawdziwy download pliku (Mosaico nie ma natywnego "zapisz do CRM").
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'template_id' => $template_id]);
    exit;
}

if ($action === 'email') {
    $rcpt    = trim((string)($_POST['rcpt'] ?? ''));
    $subject = trim((string)($_POST['subject'] ?? 'Test szablonu Mosaico'));
    if (!$rcpt || !filter_var($rcpt, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        exit('Error: nieprawidłowy adres e-mail');
    }
    try {
        mail_queue_add($rcpt, $rcpt, $subject, $html, strip_tags($html), 'crm_mosaico_test', $template_id, '', true);
        echo 'OK: wysłano do ' . $rcpt;
    } catch (\Throwable $e) {
        http_response_code(500);
        echo 'Error: ' . $e->getMessage();
    }
    exit;
}

http_response_code(400);
