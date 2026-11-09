<?php
declare(strict_types=1);

/**
 * modules/claude_billing/logic/ClaudeBillingController.php — panel „Abonament Claude” (panel dydaktyka, kadra zarządzająca):
 * pulpit (kafle, ustawienia, lista abonamentów), karta abonamentu (naliczenia, dane do FV, dzień graniczny, zakończenie),
 * ręczne potwierdzenie wpłaty / anulowanie / faktura, uruchomienie przebiegu dziennego oraz strona testu całego przebiegu.
 * Wyniki: string = HTML, null = przekierowanie.
 */
require_once __DIR__ . '/claudeBilling.php';
require_once __DIR__ . '/ClaudeInvoice.php';
require_once __DIR__ . '/PriceChange.php';

final class ClaudeBillingController
{
    private const VIEW = __DIR__ . '/../view/';
    public const TEST_CLIENT = 'TEST — uczestnik testowy (abonament Claude)';

    private function actor(): array
    {
        $me = dyd_require();
        return ['id' => (int)$me['user_id'], 'name' => (string)($me['name'] ?? ''), 'via' => 'panel dydaktyka', 'ip' => (string)($_SERVER['REMOTE_ADDR'] ?? '')];
    }

    private function view(string $file, array $data): string
    {
        $p = $data['product'] ?? $this->product();
        return Router::view(self::VIEW . $file, $data + ['actor' => $this->actor(), 'product' => $p, 'P' => ClaudeBilling::P($p)]);
    }

    /** Produkt z adresu / formularza (claude | nordvpn). */
    private function product(): string
    {
        return ClaudeBilling::product((string)($_POST['product'] ?? $_GET['product'] ?? 'claude'));
    }

    /** Akcja z komunikatem i powrotem. */
    private function act(callable $fn, string $ok, string $to): ?string
    {
        try {
            $msg = $fn();
            flash_set('success', is_string($msg) && $msg !== '' ? $msg : $ok);
        } catch (DomainException | InvalidArgumentException $e) {
            flash_set('danger', $e->getMessage());
        } catch (\Throwable $e) {
            error_log('claude panel: ' . $e);
            flash_set('danger', 'Błąd: ' . $e->getMessage());
        }
        return Router::redirect($to);
    }

    // ── Pulpit ──────────────────────────────────────────────────────────────────

    public function index(): string
    {
        $filter = (string)($_GET['status'] ?? '');
        $q = trim((string)($_GET['q'] ?? ''));
        $found = [];
        if (mb_strlen($q) >= 2) {
            require_once dirname(__DIR__, 2) . '/ti_licencje/logic/tiClaudeStatement.php';
            foreach (db_all("SELECT id, name, email FROM k30_clients WHERE name LIKE ? AND name <> ? ORDER BY name LIMIT 20", ['%' . $q . '%', self::TEST_CLIENT]) as $c) {
                $c['claude'] = ClaudeBilling::assignment((int)$c['id'], $this->product());
                $c['sub'] = ClaudeBilling::forClient((int)$c['id'], false, $this->product());
                $found[] = $c;
            }
        }
        $p = $this->product();
        $changes = PriceChange::list($p);
        $open = array_values(array_filter($changes, fn($c) => in_array($c['status'], ['draft', 'sent'], true)));
        return $this->view('index.php', ['rows' => ClaudeBilling::listAll($filter, false, $p), 'filter' => $filter, 'stats' => ClaudeBilling::stats($p),
            's' => ClaudeBilling::settings($p), 'rate' => ClaudeBilling::lastRate($p), 'q' => $q, 'found' => $found,
            'changes' => $changes, 'changeAnswers' => $open ? PriceChange::answers((int)$open[0]['id']) : []]);
    }

    // ── Zmiany cen (z akceptacją abonentów) ─────────────────────────────────────

    public function priceCreate(): ?string
    {
        $p = $this->product();
        return $this->act(fn() => 'Zaplanowano zmianę ceny #' . PriceChange::create($p, $_POST, $this->actor()['name']) . '. Sprawdź podgląd i wyślij powiadomienia do abonentów.',
            '', route('cb.index') . '?product=' . $p . '#zmiana-ceny');
    }

    public function priceSend(string $id): ?string
    {
        $c = PriceChange::get((int)$id);
        $p = ClaudeBilling::product((string)($c['product'] ?? 'claude'));
        return $this->act(function () use ($id) {
            $r = PriceChange::send((int)$id, $this->actor()['name']);
            return 'Wysłano powiadomienia o zmianie ceny: ' . $r['sent'] . '.' . ($r['skipped'] ? ' Bez wysyłki (brak e-maila): ' . implode(', ', $r['skipped']) . ' — skontaktuj się z nimi inaczej.' : '');
        }, '', route('cb.index') . '?product=' . $p . '#zmiana-ceny');
    }

    public function priceCancel(string $id): ?string
    {
        $c = PriceChange::get((int)$id);
        $p = ClaudeBilling::product((string)($c['product'] ?? 'claude'));
        return $this->act(fn() => PriceChange::cancel((int)$id, $this->actor()['name']), 'Zmiana ceny wycofana.', route('cb.index') . '?product=' . $p . '#zmiana-ceny');
    }

    public function saveSettings(): ?string
    {
        $p = $this->product();
        return $this->act(fn() => ClaudeBilling::saveSettings($_POST, $this->actor()['name'], $p), 'Zapisano ustawienia — ' . ClaudeBilling::P($p)['label'] . '.', route('cb.index', ['product' => $p]));
    }

    public function rateCheck(): ?string
    {
        return $this->act(function () {
            $q = ClaudeBilling::quote(null, $this->product());
            return 'Kurs PKO BP (' . $q['kind_label'] . ', tabela ' . PkoRates::tableLabel($q) . '): ' . ClaudeBilling::rateFmt($q['rate']) . '/USD → '
                . ClaudeBilling::usd($q['usd']) . ' = ' . ClaudeBilling::zl($q['amount_pln']) . ($q['stale'] ? ' (UWAGA: ' . $q['source'] . ')' : '') . '.';
        }, '', route('cb.index', ['product' => $this->product()]));
    }

    public function notice(): ?string
    {
        $p = $this->product();
        return $this->act(function () use ($p) {
            $id = ClaudeBilling::publishNotice($p, !empty($_POST['email']), $this->actor());
            return 'Opublikowano komunikat #' . $id . ' w panelu uczestników' . (!empty($_POST['email']) ? ' i wysłano e-mail' : ' (bez e-maila)') . '.';
        }, '', route('cb.index', ['product' => $p]));
    }

    public function runNow(): ?string
    {
        return $this->act(function () {
            $r = ClaudeBilling::run();
            return 'Przebieg: naliczenia ' . $r['charges'] . ', przypomnienia ' . $r['reminders'] . ', wstrzymane ' . $r['suspended'] . ', wznowione ' . $r['resumed']
                . ($r['errors'] ? '. Błędy: ' . implode('; ', array_slice($r['errors'], 0, 3)) : '') . '.';
        }, '', route('cb.index', ['product' => $this->product()]));
    }

    /** Aktywacja przez kadrę (np. małoletni, osoba bez licencji w module Licencje — wtedy zgoda kierownika). */
    public function activate(): ?string
    {
        $cid = (int)($_POST['client_id'] ?? 0);
        $p = $this->product();
        return $this->act(function () use ($cid, $p) {
            $a = $this->actor();
            $sub = ClaudeBilling::activate($cid, $a, false, (string)($_POST['date'] ?? '') ?: null, false, (int)($_POST['due_day'] ?? 0) ?: null, null, $p);
            return 'Włączono ' . ClaudeBilling::P($p)['label'] . ': ' . $sub['client_name'] . '.' . (ClaudeBilling::invoiceDataGaps($sub) ? ' Uzupełnij dane do faktury VAT na karcie abonamentu.' : '');
        }, '', route('cb.index', ['product' => $p]));
    }

    /** Konto w panelu licencji (login „l” + 8 cyfr i nowe hasło) — zakłada kadra; hasło pokazujemy raz, do przekazania uczestnikowi. */
    public function licAccount(): ?string
    {
        $cid = (int)($_POST['client_id'] ?? 0);
        $p = $this->product();
        return $this->act(function () use ($cid) {
            require_once dirname(__DIR__, 2) . '/license_login/logic/licenseLogin.php';
            $c = db_one("SELECT name FROM k30_clients WHERE id=?", [$cid]);
            if (!$c) throw new DomainException('Nie ma takiego uczestnika.');
            $new = !LicenseLogin::get($cid);
            $login = LicenseLogin::generate($cid, $this->actor()['name'] . ' (panel dydaktyka)');
            $pass = LicenseLogin::issuePassword($cid);
            ClaudeBilling::event(null, null, 'lic_account', ($new ? 'Założono' : 'Nowe hasło —') . ' konto w panelu licencji: ' . $c['name'] . ' (numer ' . $login . ').', $this->actor()['name']);
            return ($new ? 'Założono konto w panelu licencji' : 'Nowe hasło do panelu licencji') . ' dla ' . $c['name'] . ': numer w panelu licencji (login) ' . $login . ', hasło ' . $pass
                . ' — przekaż je uczestnikowi (pokazujemy je tylko teraz). Logowanie: https://kursant.feer.org.pl/licencje/';
        }, '', route('cb.index', ['product' => $p, 'q' => (string)($_POST['q'] ?? '')]));
    }

    // ── Karta abonamentu ────────────────────────────────────────────────────────

    public function show(string $id): string
    {
        $sub = ClaudeBilling::subscription((int)$id);
        if (!$sub) { http_response_code(404); return 'Nie znaleziono abonamentu.'; }
        return $this->view('show.php', ['product' => ClaudeBilling::productOf($sub), 'sub' => $sub, 'charges' => ClaudeBilling::charges((int)$id), 'events' => ClaudeBilling::events((int)$id, 60),
            's' => ClaudeBilling::settings(ClaudeBilling::productOf($sub)), 'gaps' => ClaudeBilling::invoiceDataGaps($sub), 'paidThrough' => ClaudeBilling::paidThrough($sub)]);
    }

    public function buyer(string $id): ?string
    {
        return $this->act(function () use ($id) {
            $err = ClaudeBilling::saveBuyer((int)$id, $_POST, $this->actor()['name'] . ' (panel dydaktyka)');
            if ($err) throw new InvalidArgumentException(implode(' ', $err));
        }, 'Zapisano dane do faktury (zaległe faktury wystawione, jeśli były).', route('cb.show', ['id' => $id]));
    }

    public function dueDay(string $id): ?string
    {
        $d = trim((string)($_POST['due_day'] ?? ''));
        return $this->act(fn() => ClaudeBilling::setDueDay((int)$id, $d === '' ? null : (int)$d, $this->actor()), 'Zapisano dzień graniczny.', route('cb.show', ['id' => $id]));
    }

    public function end(string $id): ?string
    {
        return $this->act(fn() => ClaudeBilling::end((int)$id, trim((string)($_POST['reason'] ?? '')) ?: 'zakończenie przez kierownika', $this->actor()),
            'Zakończono abonament — zgłoszenie dezaktywacji poszło do Helpdesku.', route('cb.show', ['id' => $id]));
    }

    public function chargePaid(string $id): ?string
    {
        $ch = ClaudeBilling::charge((int)$id);
        return $this->act(fn() => ClaudeBilling::markPaid((int)$id, (string)($_POST['via'] ?? 'przelew'), trim((string)($_POST['ref'] ?? '')), $this->actor()),
            'Potwierdzono wpłatę (faktura oznaczona jako opłacona).', route('cb.show', ['id' => (int)($ch['subscription_id'] ?? 0)]));
    }

    public function chargeCancel(string $id): ?string
    {
        $ch = ClaudeBilling::charge((int)$id);
        return $this->act(fn() => ClaudeBilling::cancelCharge((int)$id, $this->actor(), trim((string)($_POST['reason'] ?? ''))),
            'Anulowano naliczenie (fakturę w Fakturowni skoryguj tam, jeśli była wystawiona).', route('cb.show', ['id' => (int)($ch['subscription_id'] ?? 0)]));
    }

    public function chargeInvoice(string $id): ?string
    {
        $ch = ClaudeBilling::charge((int)$id);
        return $this->act(function () use ($id) {
            $r = ClaudeInvoice::issueForCharge((int)$id, true, $this->actor()['id']);
            if (empty($r['ok'])) throw new DomainException((string)$r['error']);
            return 'Dokument ' . $r['number'] . ' w Fakturowni.' . (!empty($r['warning']) ? ' ' . $r['warning'] : '');
        }, '', route('cb.show', ['id' => (int)($ch['subscription_id'] ?? 0)]));
    }

    // ── Test całego przebiegu ───────────────────────────────────────────────────

    private function testClient(): int
    {
        $c = db_one("SELECT id FROM k30_clients WHERE name=? ORDER BY id LIMIT 1", [self::TEST_CLIENT]);
        if ($c) return (int)$c['id'];
        return db_insert('k30_clients', ['name' => self::TEST_CLIENT, 'email' => 'claude-test@example.invalid', 'status' => 'test',
            'address' => 'ul. Zamknięta 10/1.5, 30-554 Kraków', 'notes' => 'Konto techniczne testu abonamentu Claude (panel dydaktyka › Abonament Claude › Test).']);
    }

    private function testLog(string $line, array $tickets = []): void
    {
        $_SESSION['claude_test_log'][] = date('H:i:s') . ' ' . $line;
        foreach ($tickets as $t) $_SESSION['claude_test_tickets'][] = $t;
    }

    public function test(): string
    {
        ClaudeBilling::migrate();
        $cid = $this->testClient();
        $p = $this->product();
        $sub = ClaudeBilling::forClient($cid, true, $p);
        $quote = null; $quoteErr = '';
        try { $quote = ClaudeBilling::quote(null, $p); } catch (\Throwable $e) { $quoteErr = $e->getMessage(); }
        require_once dirname(__DIR__, 3) . '/includes/p24.php';
        require_once dirname(__DIR__, 3) . '/includes/invoices.php';
        $log = $_SESSION['claude_test_log'] ?? []; $tickets = $_SESSION['claude_test_tickets'] ?? [];
        return $this->view('test.php', ['sub' => $sub, 'charges' => $sub ? ClaudeBilling::charges((int)$sub['id']) : [], 'events' => $sub ? ClaudeBilling::events((int)$sub['id'], 80) : [],
            's' => ClaudeBilling::settings($p), 'quote' => $quote, 'quoteErr' => $quoteErr, 'log' => $log, 'tickets' => $tickets, 'clientId' => $cid,
            'buyer' => ClaudeBilling::buyerDefaults($cid), 'fakturowniaReady' => invoices_api_ready(), 'p24' => ['enabled' => p24_enabled(), 'env' => p24_environment()]]);
    }

    public function testStart(): ?string
    {
        return $this->act(function () {
            $cid = $this->testClient();
            $p = $this->product();
            if ($old = ClaudeBilling::forClient($cid, true, $p)) if (in_array($old['status'], ['active', 'suspended'], true)) throw new DomainException('Test już trwa — najpierw „Wyczyść test”.');
            ClaudeBilling::$testRealTickets = !empty($_POST['real_tickets']);
            ClaudeBilling::$testTickets = [];
            $sub = ClaudeBilling::activate($cid, ['name' => $this->actor()['name'], 'via' => 'test', 'ip' => (string)($_SERVER['REMOTE_ADDR'] ?? '')], true,
                (string)($_POST['date'] ?? '') ?: null, false, (int)($_POST['due_day'] ?? 0) ?: null, $_POST, $p);
            $ch = db_one("SELECT * FROM claude_charges WHERE subscription_id=? ORDER BY id LIMIT 1", [(int)$sub['id']]);
            $this->testLog('Aktywacja testowa od ' . ClaudeBilling::date($sub['activated_on']) . '. Naliczenie: ' . ($ch ? ClaudeBilling::zl((float)$ch['amount_pln']) . ' (' . ClaudeBilling::usd((float)$ch['usd']) . ' × ' . ClaudeBilling::rateFmt((float)$ch['rate']) . '), termin ' . ClaudeBilling::date($ch['due_on'])
                . '. Proforma: ' . ($ch['proforma_number'] ?: 'nie wystawiona — ' . ($ch['invoice_error'] ?: '?')) : 'brak') . '.', ClaudeBilling::$testTickets);
            return 'Test uruchomiony — przejdź przez kolejne kroki poniżej.';
        }, '', route('cb.test', ['product' => $this->product()]));
    }

    /** Symulowana wpłata Przelewy24: transakcja portalu + pp_settle (ten sam kod co webhook P24), bez pieniędzy. */
    public function testPay(string $id): ?string
    {
        return $this->act(function () use ($id) {
            $ch = ClaudeBilling::charge((int)$id);
            if (!$ch || !(int)$ch['is_test']) throw new DomainException('To nie jest naliczenie testowe.');
            if ($ch['status'] !== 'pending') throw new DomainException('Naliczenie nie czeka na wpłatę.');
            if (!$ch['pp_item_id']) throw new DomainException('Naliczenie nie ma pozycji w portalu płatności (' . $ch['note'] . ').');
            require_once dirname(__DIR__, 2) . '/payment_portal/logic/paymentPortal.php';
            ClaudeBilling::$testTickets = [];
            $pdo = db(); $pdo->beginTransaction();
            try {
                $uuid = pp_uuid();
                $tid = db_insert('portal_transactions', ['participant_id' => (int)$ch['client_id'], 'transaction_uuid' => $uuid, 'total_amount' => (float)$ch['amount_pln'],
                    'payment_method' => 'p24', 'status' => 'pending', 'transfer_title' => '[TEST] ' . ClaudeBilling::chargeTitle($ch)]);
                db_exec("UPDATE payable_items SET status='processing' WHERE id=? AND status='pending'", [(int)$ch['pp_item_id']]);
                db_insert('portal_transaction_items', ['portal_transaction_id' => $tid, 'payable_item_id' => (int)$ch['pp_item_id'], 'amount' => (float)$ch['amount_pln']]);
                $pdo->commit();
            } catch (\Throwable $e) { $pdo->rollBack(); throw $e; }
            if ($err = pp_settle($tid, ['gateway' => 'p24', 'simulation' => 'test abonamentu Claude'], 'Test Claude', $this->actor()['id'])) throw new RuntimeException($err);
            ClaudeBilling::afterPayment();
            $ch = ClaudeBilling::charge((int)$id);
            $this->testLog('Symulowana wpłata P24 za ' . ClaudeBilling::periodLabel($ch['period']) . ' → naliczenie: ' . ClaudeBilling::CHARGE_STATUS[$ch['status']]
                . ', zamówienie ' . ($ch['order_number'] ?: '—') . ', faktura: ' . ($ch['invoice_number'] ?: 'podgląd (w teście bez faktury VAT)')
                . ($ch['invoice_error'] ? ' (' . $ch['invoice_error'] . ')' : ''), ClaudeBilling::$testTickets);
            return 'Wpłata zasymulowana.';
        }, '', route('cb.test', ['product' => $this->product()]));
    }

    /** Prawdziwa transakcja w sandboksie Przelewy24 (tylko gdy P24 włączone w środowisku sandbox). */
    public function testP24(string $id): ?string
    {
        try {
            require_once dirname(__DIR__, 3) . '/includes/p24.php';
            if (!p24_enabled() || p24_environment() !== 'sandbox') throw new DomainException('Przelewy24 nie są w trybie sandbox — prawdziwej płatności w teście nie uruchamiamy.');
            $url = ClaudeBilling::startP24((int)$id, (string)(db_one("SELECT email FROM users WHERE id=?", [$this->actor()['id']])['email'] ?? ''), route('cb.test', ['product' => $this->product()]));
            header('Location: ' . $url);
            return null;
        } catch (\Throwable $e) {
            flash_set('danger', $e->getMessage());
            return Router::redirect(route('cb.test', ['product' => $this->product()]));
        }
    }

    public function testTime(): ?string
    {
        return $this->act(function () {
            $day = (string)($_POST['today'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) throw new InvalidArgumentException('Podaj datę.');
            ClaudeBilling::$testTickets = [];
            $r = ClaudeBilling::run($day, true);
            $this->testLog('Przebieg dzienny na ' . ClaudeBilling::date($day) . ': naliczenia ' . $r['charges'] . ', przypomnienia ' . $r['reminders'] . ', wstrzymane ' . $r['suspended'] . ', wznowione ' . $r['resumed']
                . ($r['log'] ? ' — ' . implode('; ', $r['log']) : '') . ($r['errors'] ? ' BŁĘDY: ' . implode('; ', $r['errors']) : ''), ClaudeBilling::$testTickets);
            return 'Przebieg dzienny wykonany dla daty ' . ClaudeBilling::date($day) . '.';
        }, '', route('cb.test', ['product' => $this->product()]));
    }

    public function testClean(): ?string
    {
        return $this->act(function () {
            $cid = $this->testClient();
            $msgs = [];
            foreach (db_all("SELECT id, pp_item_id FROM claude_charges WHERE client_id=? AND is_test=1", [$cid]) as $c) {
                if ($m = ClaudeInvoice::deleteTest((int)$c['id'])) $msgs[] = $m;
                if ($c['pp_item_id']) db_exec("UPDATE payable_items SET status='cancelled' WHERE id=? AND status IN ('pending','processing')", [(int)$c['pp_item_id']]);
            }
            db_exec("DELETE FROM claude_events WHERE subscription_id IN (SELECT id FROM claude_subscriptions WHERE client_id=? AND is_test=1)", [$cid]);
            db_exec("DELETE FROM claude_charges WHERE client_id=? AND is_test=1", [$cid]);
            db_exec("DELETE FROM claude_subscriptions WHERE client_id=? AND is_test=1", [$cid]);
            unset($_SESSION['claude_test_log'], $_SESSION['claude_test_tickets']);
            return 'Wyczyszczono test' . ($msgs ? ' (' . implode('; ', $msgs) . ')' : '') . '.';
        }, '', route('cb.test', ['product' => $this->product()]));
    }
}
