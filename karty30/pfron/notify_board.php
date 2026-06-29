<?php
/**
 * karty30/pfron/notify_board.php — Wysłanie powiadomienia do adminów o umowie PFRON do akceptacji.
 *
 * POST JSON: { pfron_id, _csrf }
 * Odpowiedź JSON: { ok, notified_count } lub { ok:false, error }
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/karty30.php';
require_once dirname(dirname(__DIR__)) . '/includes/mail_queue.php';

header('Content-Type: application/json; charset=utf-8');

function jerr(string $msg, int $code = 400): never {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

k30_require_access();
karty30_migrate();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jerr('Metoda niedozwolona', 405);

$body    = (string)file_get_contents('php://input');
$payload = json_decode($body, true);
$pfron_id = (int)($payload['pfron_id'] ?? 0);
$csrf     = (string)($payload['_csrf']  ?? '');

if (!hash_equals(csrf_token(), $csrf)) jerr('Nieprawidłowy token CSRF.', 403);
if (!$pfron_id) jerr('Brak ID umowy PFRON.');

$pfron = db_one(
    "SELECT pc.*, c.name AS client_name
     FROM k30_pfron_contracts pc
     LEFT JOIN k30_clients c ON c.id = pc.client_id
     WHERE pc.id = ?",
    [$pfron_id]
);
if (!$pfron) jerr('Umowa PFRON nie istnieje.');

// IKA
$_ika_ts = (int)($_SESSION['_ika_ts'] ?? 0);
if (function_exists('ika_require') && $_ika_ts > 0 && (time() - $_ika_ts) >= 1800) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'ika_expired' => true, 'error' => 'Sesja IKA wygasła.']);
    exit;
}

// Oznacz jako oczekuje na akceptację
db()->prepare(
    "UPDATE k30_pfron_contracts SET board_approval_status='pending', board_notified_at=datetime('now'), updated_at=datetime('now') WHERE id=?"
)->execute([$pfron_id]);

// Pobierz adminów
$admins = db()->query(
    "SELECT email, name FROM users WHERE role='admin' AND is_active=1 AND email IS NOT NULL AND email != '' ORDER BY name"
)->fetchAll(\PDO::FETCH_ASSOC);

$sender   = current_user();
$sent_by  = trim(($sender['first_name'] ?? '') . ' ' . ($sender['last_name'] ?? '')) ?: ($sender['email'] ?? 'System');
$client   = h($pfron['client_name'] ?? 'nieznany');
$contract = h($pfron['contract_number'] ?? "ID $pfron_id");
$doc_no   = $pfron['doc_number'] ? h($pfron['doc_number']) : '<em>brak numeru</em>';
$review_url = APP_URL . '/karty30/pfron/docs.php?pfron_id=' . $pfron_id;

$subject = "PFRON: umowa do akceptacji — $client ($contract)";
$html = <<<HTML
<p>Dzień dobry,</p>
<p>Użytkownik <strong>{$sent_by}</strong> przesłał umowę PFRON do akceptacji przez Zarząd.</p>
<table style="border-collapse:collapse;font-size:14px;margin:16px 0">
  <tr><td style="padding:4px 12px 4px 0;color:#555">Beneficjent:</td><td><strong>{$client}</strong></td></tr>
  <tr><td style="padding:4px 12px 4px 0;color:#555">Nr umowy PFRON:</td><td>{$contract}</td></tr>
  <tr><td style="padding:4px 12px 4px 0;color:#555">Nr dokumentu:</td><td>{$doc_no}</td></tr>
</table>
<p><a href="{$review_url}" style="background:#c2410c;color:#fff;padding:10px 20px;border-radius:4px;text-decoration:none;display:inline-block">Przejdź do umowy</a></p>
<p style="color:#888;font-size:12px">Wiadomość wysłana automatycznie przez system SZO.</p>
HTML;

$count = 0;
foreach ($admins as $admin) {
    mail_queue_add(
        $admin['email'], $admin['name'] ?: $admin['email'],
        $subject, $html,
        '', 'pfron_contract', $pfron_id,
        '', true
    );
    $count++;
}

echo json_encode(['ok' => true, 'notified_count' => $count]);
