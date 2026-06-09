<?php
/**
 * helpdesk/bug_report.php — AJAX: utwórz ticket helpdesk z formularza zgłoszenia błędu
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/helpdesk.php';
helpdesk_migrate();

header('Content-Type: application/json; charset=utf-8');

$user = current_user();
if (!$user) {
    echo json_encode(['ok' => false, 'error' => 'Wymagane logowanie.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Nieprawidłowa metoda.']);
    exit;
}

if (org_setting('bug_report_enabled') === '0') {
    echo json_encode(['ok' => false, 'error' => 'Formularz zgłoszeń jest wyłączony.']);
    exit;
}

$page_url   = trim($_POST['page_url']   ?? '');
$description = trim($_POST['description'] ?? '');

if (mb_strlen($description) < 5) {
    echo json_encode(['ok' => false, 'error' => 'Opis błędu jest zbyt krótki (minimum 5 znaków).']);
    exit;
}

// Skróć URL do tytułu — max 80 znaków
$short_url = $page_url ? (mb_strlen($page_url) > 80 ? mb_substr($page_url, 0, 77) . '…' : $page_url) : '(brak adresu)';
$title     = '[Bug] ' . $short_url;

$full_desc = "**Strona:** " . ($page_url ?: '—') . "\n\n"
           . "**Opis błędu:**\n" . $description . "\n\n"
           . "**Zgłoszone przez:** " . ($user['name'] ?? '') . " <" . ($user['email'] ?? '') . ">";

try {
    $number = hd_next_number();
    db()->prepare("INSERT INTO helpdesk_tickets
        (number, title, description, category, priority, status,
         requester_id, requester_name, requester_email, source)
        VALUES (?,?,?,?,?,?,?,?,?,?)")
      ->execute([
          $number,
          $title,
          $full_desc,
          'bug_report',
          'normalny',
          'nowe',
          (int)$user['id'],
          $user['name'] ?? '',
          $user['email'] ?? '',
          'bug_report',
      ]);
    $ticket_id = (int)db()->lastInsertId();

    echo json_encode([
        'ok'     => true,
        'number' => $number,
        'url'    => APP_URL . '/helpdesk/view.php?id=' . $ticket_id,
    ]);
} catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'Błąd zapisu: ' . $e->getMessage()]);
}
