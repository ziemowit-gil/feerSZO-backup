<?php
declare(strict_types=1);

/**
 * modules/ti_licencje_kursant/logic/LicencjeKursantController.php — „Moje licencje” uczestnika (kursant.feer.org.pl/licencje/).
 *
 * Jedno miejsce na wszystkie licencje, opisane prostym językiem:
 *   • płatne licencje (Claude, NordVPN — modules/claude_billing): włączenie (dane do faktury + zgoda), faktury, płatność Przelewy24 / przelew /
 *     Szybki przelew z faktury, uzupełnienie danych do FV, rezygnacja,
 *   • licencje na Office (sprawy AM7: przyznana licencja, login w tenancie),
 *   • licencje dydaktyczne (AM5: oświadczenia) i sprawy licencyjne (AM11–24, ANT1),
 *   • programy i klucze (moduł Licencje — klucze widoczne po zaakceptowaniu oświadczenia o poufności),
 *   • login do licencji „l” + 8 cyfr (modules/license_login).
 * Wyniki: string = HTML, null = przekierowanie.
 */
require_once dirname(__DIR__, 2) . '/claude_billing/logic/claudeBilling.php';
require_once dirname(__DIR__, 2) . '/license_login/logic/licenseLogin.php';

final class LicencjeKursantController
{
    private const VIEW = __DIR__ . '/../view/';
    public const OP_NAMES = ['AM11' => 'Licencja opłacana przez Fundację', 'AM12' => 'Zwrot kosztów licencji', 'AM13' => 'Licencja opłacana przez kogoś innego',
        'AM20' => 'Zmiana płatnika licencji', 'AM21' => 'Zmiana planu licencji', 'AM22' => 'Zawieszenie licencji', 'AM23' => 'Odebranie licencji',
        'AM24' => 'Przedłużenie licencji', 'ANT1' => 'Wniosek o dostęp do Claude'];
    public const OP_STATUS = ['signing' => 'czeka na Twoje potwierdzenie — link przyszedł e-mailem i SMS-em', 'pending' => 'czeka na decyzję',
        'approved' => 'zatwierdzone — Dział IT to teraz wykonuje', 'executed' => 'gotowe', 'rejected' => 'odmowa', 'cancelled' => 'wycofane'];
    public const AM7_STATUS = ['draft' => 'wniosek w przygotowaniu', 'started' => 'wniosek złożony — czekamy na decyzję', 'granted' => 'przyznana',
        'refused' => 'odmowa', 'withdrawn' => 'wniosek wycofany'];
    public const AM5_STATUS = ['invited' => 'czekamy na Twoje oświadczenie — link przyszedł e-mailem', 'submitted' => 'oświadczenie wysłane — sprawdzamy je',
        'accepted' => 'masz dostęp', 'rejected' => 'oświadczenie do poprawy — sprawdź e-mail', 'revoked' => 'dostęp cofnięty'];

    private function me(): array
    {
        $st = student_require();
        $cid = (int)$st['client_id'];
        $c = db_one("SELECT name, email FROM k30_clients WHERE id=?", [$cid]) ?: ['name' => '', 'email' => ''];
        return ['student' => $st, 'cid' => $cid, 'name' => (string)$c['name'], 'email' => (string)$c['email'], 'ip' => (string)($_SERVER['REMOTE_ADDR'] ?? '')];
    }

    private function flash(string $type, string $msg, string $anchor = ''): ?string
    {
        $_SESSION['lk_flash'] = [$type, $msg, $anchor];
        header('Location: ' . route('lk.index') . ($anchor !== '' ? '#' . $anchor : ''));
        return null;
    }

    public function index(): string
    {
        $me = $this->me();
        $cid = $me['cid'];
        $paid = [];
        foreach (array_keys(ClaudeBilling::PRODUCTS) as $p) {
            try { $v = ClaudeBilling::participantView($cid, $p); if ($v['hasLicense'] || $v['sub']) $paid[$p] = $v; } catch (\Throwable $e) { error_log('lk ' . $p . ': ' . $e->getMessage()); }
        }
        $software = function_exists('k30_ti_client_licenses') ? k30_ti_client_licenses($cid) : [];
        require_once dirname(__DIR__, 3) . '/includes/ti_terms.php';
        $term = ti_term_get('licencje');
        $termOk = !$term || empty($term['is_active']) || ti_term_accepted($cid, 'licencje');
        $office = $am5 = $ops = [];
        try {
            $office = db_all("SELECT id, case_number, title, request_status, period_from, period_to, tenant_upn, tenant_kind, decided_at, helpdesk_number, created_at
                                FROM licenses WHERE interested_kind='client' AND interested_id=? AND COALESCE(form_kind,'AM5')='AM7' ORDER BY id DESC", [$cid]);
            $am5 = db_all("SELECT a.id, a.status, a.due_on, a.assigned_at, a.decided_at, l.title, l.case_number, l.period_from, l.period_to
                             FROM license_assignments a JOIN licenses l ON l.id=a.license_id
                            WHERE a.holder_kind='client' AND a.holder_id=? AND COALESCE(l.form_kind,'AM5')='AM5' ORDER BY a.id DESC", [$cid]);
            $ops = db_all("SELECT id, number, form, status, created_at, executed_at, due_on, request_data FROM license_operations
                            WHERE account_kind='client' AND account_id=? ORDER BY id DESC LIMIT 20", [$cid]);
        } catch (\Throwable $e) { /* moduł AM jeszcze bez tabel */ }
        $flash = $_SESSION['lk_flash'] ?? null; $err = $_SESSION['lk_errors'] ?? []; $old = $_SESSION['lk_old'] ?? [];
        unset($_SESSION['lk_flash'], $_SESSION['lk_errors'], $_SESSION['lk_old']);
        return Router::view(self::VIEW . 'index.php', ['me' => $me, 'paid' => $paid, 'software' => $software, 'termOk' => $termOk, 'office' => $office, 'am5' => $am5,
            'ops' => $ops, 'login' => LicenseLogin::get($cid), 'minor' => ClaudeBilling::isMinor($cid), 'flash' => $flash, 'err' => $err, 'old' => $old,
            'org' => (string)(org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : 'Fundacja FEER'))]);
    }

    public function activate(string $product): ?string
    {
        $me = $this->me();
        $p = ClaudeBilling::product($product);
        $L = ClaudeBilling::P($p)['label'];
        [$buyer, $err] = ClaudeBilling::buyerFromInput($_POST);
        if (empty($_POST['accept'])) $err['accept'] = 'Zaznacz, że akceptujesz zasady opłat.';
        if ($err) {
            $_SESSION['lk_errors'] = [$p => $err]; $_SESSION['lk_old'] = [$p => $buyer + ['accept' => !empty($_POST['accept'])]];
            return $this->flash('err', 'Popraw zaznaczone pola (' . count($err) . ').', $p);
        }
        try {
            $sub = ClaudeBilling::activate($me['cid'], ['name' => $me['name'], 'via' => 'panel uczestnika', 'ip' => $me['ip']], false, null, true, null, $buyer, $p);
            if (function_exists('ti_account_log')) ti_account_log((int)$me['student']['id'], $p . '_activate', 'Włączono ' . $L . ' (#' . (int)$sub['id'] . ').');
            $first = db_one("SELECT amount_pln, due_on FROM claude_charges WHERE subscription_id=? ORDER BY id LIMIT 1", [(int)$sub['id']]);
            return $this->flash('ok', $L . ' włączony. Dział IT dostał zgłoszenie' . ($sub['helpdesk_number'] ? ' ' . $sub['helpdesk_number'] : '') . ' i uruchomi Twoje konto — zwykle w ciągu jednego dnia roboczego.'
                . ($first ? ' Pierwsza faktura: ' . ClaudeBilling::zl((float)$first['amount_pln']) . ', zapłać do ' . ClaudeBilling::dayMonth($first['due_on']) . '.' : ''), $p);
        } catch (DomainException | InvalidArgumentException $e) {
            return $this->flash('err', $e->getMessage(), $p);
        } catch (\Throwable $e) {
            error_log('lk activate: ' . $e);
            return $this->flash('err', 'Nie udało się włączyć ' . $L . '. Spróbuj za chwilę albo napisz do biura.', $p);
        }
    }

    public function buyer(string $product): ?string
    {
        $me = $this->me();
        $p = ClaudeBilling::product($product);
        $sub = ClaudeBilling::forClient($me['cid'], false, $p);
        if (!$sub || !in_array($sub['status'], ['active', 'suspended'], true)) return $this->flash('err', 'Nie masz włączonego ' . ClaudeBilling::P($p)['label'] . '.', $p);
        $err = ClaudeBilling::saveBuyer((int)$sub['id'], $_POST, $me['name'] . ' (panel uczestnika)');
        if ($err) { $_SESSION['lk_errors'] = [$p => $err]; $_SESSION['lk_old'] = [$p => $_POST]; return $this->flash('err', 'Popraw dane do faktury (' . count($err) . ').', $p); }
        return $this->flash('ok', 'Zapisaliśmy dane do faktury. Jeśli jakaś faktura na nie czekała — właśnie się wystawiła.', $p);
    }

    public function cancel(string $product): ?string
    {
        $me = $this->me();
        $p = ClaudeBilling::product($product);
        $L = ClaudeBilling::P($p)['label'];
        if (empty($_POST['confirm'])) return $this->flash('err', 'Zaznacz, że na pewno chcesz zrezygnować.', $p);
        $sub = ClaudeBilling::forClient($me['cid'], false, $p);
        if (!$sub || !in_array($sub['status'], ['active', 'suspended'], true)) return $this->flash('err', 'Nie masz włączonego ' . $L . '.', $p);
        try {
            ClaudeBilling::end((int)$sub['id'], 'rezygnacja uczestnika w panelu', ['name' => $me['name'], 'via' => 'panel uczestnika']);
            if (function_exists('ti_account_log')) ti_account_log((int)$me['student']['id'], $p . '_cancel', 'Rezygnacja z ' . $L . ' (#' . (int)$sub['id'] . ').');
            return $this->flash('ok', 'Zrezygnowałeś/aś z ' . $L . '. Nie dostaniesz kolejnych faktur.', $p);
        } catch (\Throwable $e) {
            return $this->flash('err', $e->getMessage(), $p);
        }
    }

    /** Przelewy24 bezpośrednio za fakturę. */
    public function pay(string $id): ?string
    {
        $me = $this->me();
        $ch = ClaudeBilling::charge((int)$id);
        if (!$ch || (int)$ch['client_id'] !== $me['cid'] || (int)$ch['is_test']) { http_response_code(404); return 'Nie znaleziono faktury.'; }
        $p = ClaudeBilling::productOf($ch);
        try {
            $url = ClaudeBilling::startP24((int)$id, $me['email'], route('lk.pay.return', ['id' => (int)$id]));
            header('Location: ' . $url);
            return null;
        } catch (\Throwable $e) {
            return $this->flash('err', $e->getMessage(), $p);
        }
    }

    /** Powrót z Przelewy24 — sprawdzamy status w bramce (powrót często wyprzedza powiadomienie). */
    public function payReturn(string $id): string
    {
        $me = $this->me();
        $ch = ClaudeBilling::charge((int)$id);
        if (!$ch || (int)$ch['client_id'] !== $me['cid']) { http_response_code(404); return 'Nie znaleziono faktury.'; }
        require_once dirname(__DIR__, 3) . '/includes/p24.php';
        if ($ch['status'] === 'pending' && ($p24 = p24_payment_for('claude_charge', (int)$id))) { p24_reconcile_payment((int)$p24['id']); $ch = ClaudeBilling::charge((int)$id); }
        $p = ClaudeBilling::productOf($ch);
        $L = ClaudeBilling::P($p)['label'];
        if ($ch['status'] === 'paid') return (string)$this->flash('ok', 'Dziękujemy — wpłata ' . ClaudeBilling::zl((float)$ch['amount_pln']) . ' za ' . $L . ' dotarła.', $p);
        return (string)$this->flash('info', 'Czekamy na potwierdzenie płatności z Przelewy24 — zwykle trwa to chwilę. Odśwież stronę za minutę. Jeśli płatność się nie udała, spróbuj ponownie.', $p);
    }

    public function login(): ?string
    {
        $me = $this->me();
        try {
            $l = LicenseLogin::generate($me['cid'], $me['name'] . ' (panel uczestnika)');
            if (function_exists('ti_account_log')) ti_account_log((int)$me['student']['id'], 'license_login', 'Wygenerowano login do licencji: ' . $l . '.');
            return $this->flash('ok', 'Twój login do licencji to ' . $l . '. Użyjemy go przy zakładaniu kont (Office, Claude, NordVPN).', 'login');
        } catch (\Throwable $e) {
            return $this->flash('err', $e->getMessage(), 'login');
        }
    }
}
