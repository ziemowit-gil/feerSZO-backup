<?php
/**
 * karty30/ti/kursant/claudeAbonament.php — akcje uczestnika dla abonamentu Claude (modules/claude_billing), tylko POST:
 *   op=activate — aktywacja (dane do faktury VAT + akceptacja warunków) → abonament, pierwsze naliczenie z fakturą w Fakturowni, zgłoszenie Helpdesk,
 *   op=buyer    — uzupełnienie / zmiana danych do faktury (zaległe faktury wystawiają się od razu),
 *   op=cancel   — rezygnacja (zgłoszenie dezaktywacji w Helpdesku).
 * Powrót: index.php?tab=licencje#claude-abonament (komunikat w $_SESSION['claude_flash']).
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__, 3) . '/includes/db.php';
require_once dirname(__DIR__, 3) . '/includes/functions.php';
require_once dirname(__DIR__, 3) . '/includes/karty30.php';
require_once dirname(__DIR__, 3) . '/includes/ti_messages.php';   // ti_account_log
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__, 3) . '/modules/claude_billing/logic/claudeBilling.php';

$back = 'index.php?tab=licencje#claude-abonament';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ' . $back); exit; }
$student = student_require();
student_token_check();
$cid = (int)$student['client_id'];
$who = ['name' => (string)(db_one("SELECT name FROM k30_clients WHERE id=?", [$cid])['name'] ?? ''), 'via' => 'panel uczestnika', 'ip' => (string)($_SERVER['REMOTE_ADDR'] ?? '')];
$op = (string)($_POST['op'] ?? '');
$flash = fn(string $t, string $m) => $_SESSION['claude_flash'] = [$t, $m];

try {
    if ($op === 'activate') {
        [$buyer, $err] = ClaudeBilling::buyerFromInput($_POST);
        if (empty($_POST['accept'])) $err['accept'] = 'Zaznacz akceptację warunków opłaty.';
        if ($err) {
            $_SESSION['claude_errors'] = $err; $_SESSION['claude_old'] = $buyer + ['accept' => !empty($_POST['accept'])];
            $flash('err', 'Popraw zaznaczone pola (' . count($err) . ').');
        } else {
            $sub = ClaudeBilling::activate($cid, $who, false, null, true, null, $buyer);
            ti_account_log((int)$student['id'], 'claude_activate', 'Aktywowano abonament Claude (#' . (int)$sub['id'] . ').');
            $first = db_one("SELECT amount_pln, due_on FROM claude_charges WHERE subscription_id=? ORDER BY id LIMIT 1", [(int)$sub['id']]);
            $flash('ok', 'Dostęp do Claude aktywowany. Dział IT dostał zgłoszenie' . ($sub['helpdesk_number'] ? ' ' . $sub['helpdesk_number'] : '') . ' i włączy Twoje miejsce.'
                . ($first ? ' Pierwsza opłata: ' . ClaudeBilling::zl((float)$first['amount_pln']) . ' do ' . ClaudeBilling::date($first['due_on']) . '.' : ''));
        }
    } elseif ($op === 'buyer') {
        $sub = ClaudeBilling::forClient($cid);
        if (!$sub || !in_array($sub['status'], ['active', 'suspended'], true)) throw new DomainException('Brak aktywnego abonamentu Claude.');
        $err = ClaudeBilling::saveBuyer((int)$sub['id'], $_POST, $who['name'] . ' (panel uczestnika)');
        if ($err) { $_SESSION['claude_errors'] = $err; $_SESSION['claude_old'] = $_POST; $flash('err', 'Popraw dane do faktury (' . count($err) . ').'); }
        else $flash('ok', 'Zapisano dane do faktury.');
    } elseif ($op === 'cancel') {
        if (empty($_POST['confirm'])) throw new DomainException('Zaznacz potwierdzenie rezygnacji.');
        $sub = ClaudeBilling::forClient($cid);
        if (!$sub || !in_array($sub['status'], ['active', 'suspended'], true)) throw new DomainException('Brak aktywnego abonamentu Claude.');
        ClaudeBilling::end((int)$sub['id'], 'rezygnacja uczestnika w panelu', $who);
        ti_account_log((int)$student['id'], 'claude_cancel', 'Rezygnacja z abonamentu Claude (#' . (int)$sub['id'] . ').');
        $flash('ok', 'Zrezygnowano z dostępu do Claude. Kolejne opłaty nie będą naliczane.');
    } else {
        throw new DomainException('Nieznana operacja.');
    }
} catch (DomainException | InvalidArgumentException $e) {
    $flash('err', $e->getMessage());
} catch (\Throwable $e) {
    error_log('claude kursant: ' . $e);
    $flash('err', 'Nie udało się wykonać operacji. Spróbuj ponownie za chwilę albo skontaktuj się z biurem.');
}
header('Location: ' . $back);
exit;
