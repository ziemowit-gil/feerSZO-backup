<?php
/**
 * crm/api/save_template.php — Zapisuje szablon wiadomości CRM.
 * POST tylko, wymaga sesji + uprawnień CRM.
 */

require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm.php';

require_login();

crm_require_json('inbox', 'write');
require_module_enabled('crm_enabled', 'Moduł CRM');
if (!can_write('crm') && !is_admin()) {
    flash_set('danger', 'Brak uprawnień.');
    header('Location: ' . APP_URL . '/crm/communicate.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . APP_URL . '/crm/communicate.php');
    exit;
}

csrf_check();
crm_migrate();

$name    = trim($_POST['name']    ?? '');
$channel = in_array($_POST['channel'] ?? '', ['email','sms'], true) ? $_POST['channel'] : 'email';
$subject = trim($_POST['subject'] ?? '') ?: null;
$body    = trim($_POST['body']    ?? '');
$user_id = (int)(current_user()['id'] ?? 0);

if ($name === '' || $body === '') {
    flash_set('danger', 'Nazwa i treść szablonu są wymagane.');
    header('Location: ' . APP_URL . '/crm/communicate.php');
    exit;
}

try {
    $existing = db_one("SELECT id FROM crm_templates WHERE name=?", [$name]);
    if ($existing) {
        db_update('crm_templates', [
            'channel'    => $channel,
            'subject'    => $subject,
            'body'       => $body,
        ], (int)$existing['id']);
        flash_set('success', 'Szablon "' . $name . '" zaktualizowany.');
    } else {
        db_insert('crm_templates', [
            'name'       => $name,
            'channel'    => $channel,
            'subject'    => $subject,
            'body'       => $body,
            'created_by' => $user_id,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        flash_set('success', 'Szablon "' . $name . '" zapisany.');
    }
} catch (\Throwable $e) {
    flash_set('danger', 'Błąd zapisu szablonu: ' . $e->getMessage());
}

header('Location: ' . APP_URL . '/crm/communicate.php');
exit;
