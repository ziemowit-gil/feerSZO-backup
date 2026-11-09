<?php
/**
 * helpdesk/licencjeWniosek.php — akcje Helpdesku na wniosku o płatną licencję (karta „Wniosek o płatną licencję” w zgłoszeniu):
 * wpłata dotarła (przelew), wystaw fakturę VAT (Fakturownia), aktywuj dostęp, odrzuć wniosek. Logika: modules/claude_billing/logic/LicenceRequest.php.
 * Tylko operator Helpdesku z dostępem do zgłoszenia; POST z tokenem _csrf; po akcji powrót do zgłoszenia z komunikatem.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/helpdesk.php';
require_once dirname(__DIR__) . '/modules/claude_billing/logic/LicenceRequest.php';

require_login();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.php'); exit; }
csrf_check();
$tid = (int)($_POST['ticket_id'] ?? 0);
$back = 'view.php?id=' . $tid;
$ticket = $tid > 0 ? db_one("SELECT * FROM helpdesk_tickets WHERE id=?", [$tid]) : null;
if (!$ticket || !hd_is_operator() || !hd_can_view_ticket($ticket)) { http_response_code(403); exit('Brak dostępu do tego zgłoszenia.'); }
$req = LicenceRequest::forTicket($tid);
$sid = (int)($_POST['sub_id'] ?? 0);
if (!$req || (int)$req['sub']['id'] !== $sid) { flash_set('danger', 'To zgłoszenie nie ma wniosku o płatną licencję.'); header('Location: ' . $back); exit; }
$u = current_user() ?? [];
$actor = ['id' => (int)($u['id'] ?? 0), 'name' => (string)($u['name'] ?? $u['username'] ?? 'Helpdesk'), 'via' => 'Helpdesk ' . ($ticket['number'] ?? ''), 'ip' => (string)($_SERVER['REMOTE_ADDR'] ?? '')];
try {
    $msg = match ((string)($_POST['act'] ?? '')) {
        'paid'     => LicenceRequest::markPaid($sid, $actor, mb_substr(trim((string)($_POST['ref'] ?? '')), 0, 120)),
        'invoice'  => LicenceRequest::issueInvoice($sid, $actor),
        'activate' => LicenceRequest::activate($sid, $actor),
        'reject'   => LicenceRequest::reject($sid, (string)($_POST['reason'] ?? ''), $actor),
        default    => throw new InvalidArgumentException('Nieznana akcja.'),
    };
    flash_set('success', $msg);
} catch (DomainException | InvalidArgumentException $e) {
    flash_set('danger', $e->getMessage());
} catch (\Throwable $e) {
    error_log('helpdesk licencje: ' . $e);
    flash_set('danger', 'Błąd: ' . $e->getMessage());
}
header('Location: ' . $back . '#hd-licencja');
exit;
