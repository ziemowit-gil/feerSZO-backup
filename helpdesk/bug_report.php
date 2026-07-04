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

// Typ zgłoszenia: błąd (HER), sugestia dot. obecnej funkcji (SUG), nowa funkcja (HNF).
const BUG_REPORT_TYPES = [
    'blad'         => ['category' => 'zgl_blad',         'tag' => 'Błąd'],
    'sugestia'     => ['category' => 'zgl_sugestia',      'tag' => 'Sugestia'],
    'nowa_funkcja' => ['category' => 'zgl_nowa_funkcja', 'tag' => 'Nowa funkcja'],
];

$type = $_POST['type'] ?? 'blad';
if (!isset(BUG_REPORT_TYPES[$type])) $type = 'blad';
$category = BUG_REPORT_TYPES[$type]['category'];
$tag      = BUG_REPORT_TYPES[$type]['tag'];

$page_url   = trim($_POST['page_url']   ?? '');
$description = trim($_POST['description'] ?? '');

if (mb_strlen($description) < 5) {
    echo json_encode(['ok' => false, 'error' => 'Opis jest zbyt krótki (minimum 5 znaków).']);
    exit;
}

// Skróć URL do tytułu — max 80 znaków
$short_url = $page_url ? (mb_strlen($page_url) > 80 ? mb_substr($page_url, 0, 77) . '…' : $page_url) : '(brak adresu)';
$title     = '[' . $tag . '] ' . $short_url;

$full_desc = "**Strona:** " . ($page_url ?: '—') . "\n\n"
           . "**Opis (" . $tag . "):**\n" . $description . "\n\n"
           . "**Zgłoszone przez:** " . ($user['name'] ?? '') . " <" . ($user['email'] ?? '') . ">";

try {
    $number = hd_next_number(hd_number_prefix_for($category));
    db()->prepare("INSERT INTO helpdesk_tickets
        (number, title, description, category, priority, status,
         requester_id, requester_name, requester_email, source)
        VALUES (?,?,?,?,?,?,?,?,?,?)")
      ->execute([
          $number,
          $title,
          $full_desc,
          $category,
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
