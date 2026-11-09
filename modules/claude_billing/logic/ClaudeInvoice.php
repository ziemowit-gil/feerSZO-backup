<?php
declare(strict_types=1);

/**
 * modules/claude_billing/logic/ClaudeInvoice.php — faktura VAT za opłacony miesiąc dostępu do Claude, generowana W FAKTUROWNI, z rabatem non-profit.
 *
 * Rejestr w SZO: moduł Faktury (includes/invoices.php — invoices + invoice_items, source='claude', source_id = id naliczenia; indeks źródła
 * chroni przed duplikatem). Dokument księgowy powstaje wyłącznie w Fakturowni (API: github.com/fakturownia/api) — bez Fakturowni faktury
 * nie ma (błąd w naliczeniu + e-mail do biura, CRON ponawia):
 *   • pozycja „Dostęp do narzędzia Claude …” w cenie regularnej (list_usd × kurs) z rabatem KWOTOWYM do ceny non-profit (usd × kurs):
 *     show_discount=1, discount_kind=amount, positions[].discount (+ discount_percent, gdy konto liczy rabat procentowo),
 *   • status=paid, paid, paid_date, payment_type (Przelewy24 / przelew), oid=CLAUDE/{id} + oid_unique=yes (bez dubli po stronie Fakturowni),
 *   • description: przeliczenie (USD, kurs PKO BP z numerem tabeli, cena regularna, rabat, do zapłaty, sposób zapłaty).
 * Po wystawieniu porównujemy kwotę brutto z odpowiedzi — gdy rabat nie został naliczony (ustawienia konta), zapisujemy ostrzeżenie.
 * Nabywca = dane do faktury z abonamentu (uzupełnia uczestnik przy aktywacji); braki → faktura czeka, biuro i uczestnik dostają informację.
 * Test (is_test=1): w Fakturowni powstaje PROFORMA (nie dokument księgowy) z tym samym rabatem — do obejrzenia i usunięcia ze strony testu.
 */
require_once __DIR__ . '/claudeBilling.php';

final class ClaudeInvoice
{
    public const SOURCE = 'claude';

    /** Opis przeliczenia i rabatu — na fakturze (description) i w uwagach faktury w SZO. */
    public static function description(array $ch): string
    {
        $disc = round((float)$ch['list_pln'] - (float)$ch['amount_pln'], 2);
        $kind = PkoRates::KINDS[$ch['rate_kind']] ?? 'kurs';
        $P = ClaudeBilling::P(ClaudeBilling::productOf($ch));
        return ((int)$ch['is_test'] ? '[TEST — proforma testowa, nie jest dokumentem księgowym] ' : '')
            . 'Dostęp do ' . ($P['program'] !== '' ? preg_replace('/\s*\(.*\)$/u', '', $P['name']) . ' ' . $P['program'] : $P['name']) . ', okres: ' . ClaudeBilling::periodLabel((string)$ch['period']) . '. '
            . ($disc > 0 ? 'Cena regularna ' . ClaudeBilling::usd((float)$ch['list_usd']) . ' = ' . ClaudeBilling::zl((float)$ch['list_pln'])
                . '; rabat dla organizacji non-profit ' . ClaudeBilling::usd(round((float)$ch['list_usd'] - (float)$ch['usd'], 2)) . ' = ' . ClaudeBilling::zl($disc) . '; ' : '')
            . 'do zapłaty: ' . ClaudeBilling::breakdown($ch) . ((float)($ch['pct'] ?? 0) > 0 && ($ch['pct_label'] ?? '') === 'VAT' ? ' (' . ClaudeBilling::pctFmt((float)$ch['pct']) . ' VAT od importu usługi, doliczony do ceny)' : '') . '. '
            . 'Kurs ' . $kind . ' PKO BP z tabeli ' . PkoRates::tableLabel(['table_number' => $ch['rate_table'], 'table_at' => $ch['rate_at']]) . ': ' . ClaudeBilling::rateFmt((float)$ch['rate']) . '/USD.'
            . ($ch['status'] === 'paid' ? ' Zapłacono: ' . ($ch['paid_via'] ?: '—') . ', ' . ClaudeBilling::date($ch['paid_at']) . '.' : '');
    }

    public static function positionName(array $ch): string
    {
        return 'Dostęp do ' . ClaudeBilling::P(ClaudeBilling::productOf($ch))['name'] . ' — ' . ClaudeBilling::periodLabel((string)$ch['period']);
    }

    /** Nabywca: dane do faktury z abonamentu. */
    public static function buyer(array $ch): array
    {
        $sub = ClaudeBilling::subscription((int)$ch['subscription_id']) ?? [];
        return array_intersect_key($sub, ClaudeBilling::BUYER_FIELDS) + array_fill_keys(array_keys(ClaudeBilling::BUYER_FIELDS), '');
    }

    /** Jak zapłacić — dopisek w opisie faktury. */
    public static function payHint(array $ch, ?array $acc): string
    {
        if ($ch['status'] === 'paid') return '';
        return 'Termin płatności ' . ClaudeBilling::TERM_DAYS . ' dni. Zapłać: Szybki przelew (przycisk przy fakturze — BLIK, szybki przelew), portal płatności SZO (Przelewy24)'
            . ($acc ? ' albo przelewem na rachunek ' . $acc['nrb_fmt'] . ' (tytuł: ' . ClaudeBilling::transferTitle($ch) . ')' : '') . '.';
    }

    /** Dane wysyłane do Fakturowni (faktura + pozycje) — także podgląd na stronie testu. */
    public static function payload(array $ch, ?string $vat = null, ?string $mode = null): array
    {
        require_once dirname(__DIR__, 3) . '/includes/invoices.php';
        $vat ??= invoices_config()['vat'];
        $mode ??= ClaudeBilling::settings(ClaudeBilling::productOf($ch))['discount_mode'];
        $test = (int)$ch['is_test'] === 1;
        $b = self::buyer($ch);
        $fee = round((float)($ch['fee_pln'] ?? 0), 2);
        $svc = round((float)$ch['amount_pln'] - $fee, 2);          // usługa: USD × kurs + narzut % (bez prowizji)
        $svcList = round((float)$ch['list_pln'] - $fee, 2);
        $disc = round($svcList - $svc, 2);
        $useDiscount = $mode === 'rabat' && $disc > 0 && !is_numeric($vat);   // przy stawce liczbowej rabat kwotowy netto/brutto bywa niejednoznaczny — wtedy opis
        $paid = $ch['status'] === 'paid';
        $paidDate = $ch['paid_at'] ? date('Y-m-d', strtotime((string)$ch['paid_at'])) : date('Y-m-d');
        $issue = (string)($ch['issue_on'] ?: date('Y-m-d'));
        $acc = ClaudeBilling::payAccount((int)$ch['client_id'], ClaudeBilling::productOf($ch));
        $parts = preg_split('/\s+/u', trim((string)$b['buyer_name'])) ?: [];
        $last = count($parts) > 1 ? (string)array_pop($parts) : '';
        $company = $b['buyer_tax_no'] !== '';
        $pos = ['name' => self::positionName($ch), 'quantity' => 1, 'quantity_unit' => 'mies.', 'tax' => $vat];
        if ($useDiscount) {
            $pos += ['price_net' => $svcList, 'price_gross' => $svcList, 'discount' => $disc, 'discount_percent' => round($disc / max(0.01, $svcList) * 100, 4)];
        } else {
            $pos += ['total_price_gross' => $svc];
        }
        $positions = [$pos];
        if ($fee > 0) $positions[] = ['name' => 'Prowizja za obsługę płatności', 'quantity' => 1, 'quantity_unit' => 'szt.', 'tax' => $vat, 'total_price_gross' => $fee];
        $inv = [
            'kind' => $test ? 'proforma' : 'vat',
            'sell_date' => $issue, 'issue_date' => $issue, 'payment_to' => (string)$ch['due_on'],
            'buyer_name' => $b['buyer_name'], 'buyer_tax_no' => $b['buyer_tax_no'], 'buyer_street' => $b['buyer_street'],
            'buyer_post_code' => $b['buyer_post_code'], 'buyer_city' => $b['buyer_city'], 'buyer_email' => $b['buyer_email'], 'currency' => 'PLN',
        ];
        $extra = ['oid' => ($test ? 'TEST-' : '') . strtoupper(ClaudeBilling::productOf($ch)) . '/' . (int)$ch['id'], 'oid_unique' => 'yes', 'description' => self::description($ch) . ' ' . self::payHint($ch, $acc),
                  'payment_to_kind' => 'other_date',
                  'buyer_company' => $company ? '1' : '0', 'lang' => 'pl', 'buyer_country' => 'PL',
                  'payment_type' => ($ch['paid_via'] ?? '') === 'Przelewy24' ? 'Przelewy24' : 'transfer'];
        if (!$company) $extra += ['buyer_first_name' => implode(' ', $parts), 'buyer_last_name' => $last];
        if ($acc) $extra['seller_bank_account'] = $acc['nrb_fmt'];   // rachunek do wpłat za Claude (ustawienie modułu)
        if ($paid && !$test) $extra += ['status' => 'paid', 'paid' => number_format((float)$ch['amount_pln'], 2, '.', ''), 'paid_date' => $paidDate];
        if ($useDiscount) $extra += ['show_discount' => '1', 'discount_kind' => 'amount'];
        return ['invoice' => $inv, 'positions' => $positions, 'extra' => $extra, 'mode' => $useDiscount ? 'rabat' : 'opis', 'expected_gross' => (float)$ch['amount_pln']];
    }

    /**
     * Wystawia fakturę w Fakturowni za opłacone naliczenie. $force — ręcznie z panelu (także przy ustawieniu „ręcznie”/„wyłączone”).
     * @return array{ok:bool,number?:string,error?:string,skipped?:bool,warning?:string,payload?:array,invoice_id?:int,url?:string,gaps?:array}
     */
    public static function issueForCharge(int $chargeId, bool $force = false, ?int $uid = null): array
    {
        $ch = ClaudeBilling::charge($chargeId);
        if (!$ch) return ['ok' => false, 'error' => 'Nie ma takiego naliczenia.'];
        $test = (int)$ch['is_test'] === 1;
        if ($ch['status'] === 'cancelled') return ['ok' => false, 'error' => 'Naliczenie anulowane — bez faktury.'];
        if (!empty($ch['invoice_id'])) return ['ok' => true, 'number' => (string)$ch['invoice_number'], 'invoice_id' => (int)$ch['invoice_id']];
        $s = ClaudeBilling::settings(ClaudeBilling::productOf($ch));
        if (!$force && !$test && $s['invoice'] !== 'auto') return ['ok' => false, 'skipped' => true, 'error' => 'Faktury wystawiane ręcznie (ustawienie).'];

        require_once dirname(__DIR__, 3) . '/includes/invoices.php';
        require_once dirname(__DIR__, 3) . '/includes/fakturownia.php';
        invoices_migrate();
        $cfg = invoices_config();
        $payload = self::payload($ch, $cfg['vat']);
        $sub = ClaudeBilling::subscription((int)$ch['subscription_id']) ?? [];
        if ($gaps = ClaudeBilling::invoiceDataGaps(self::buyer($ch))) {
            ClaudeBilling::alertBuyerGaps($sub, $gaps, $ch);
            return self::fail($chargeId, 'Brak danych do faktury VAT: ' . implode(', ', $gaps) . '.', $payload) + ['gaps' => $gaps];
        }
        if (!invoices_api_ready()) {
            return self::fail($chargeId, 'Fakturownia nie jest skonfigurowana (Administracja › Faktury: konto i token API) — faktura czeka.', $payload);
        }
        $uid ??= (int)(db_one("SELECT id FROM users WHERE role='admin' ORDER BY id LIMIT 1")['id'] ?? 0);
        $ex = db_one("SELECT id FROM invoices WHERE source=? AND source_id=? AND is_test=? AND deleted_at IS NULL", [self::SOURCE, $chargeId, $test ? 1 : 0]);
        if ($ex) $id = (int)$ex['id'];
        else {
            $id = invoice_create(array_intersect_key($payload['invoice'], array_flip(['buyer_name', 'buyer_tax_no', 'buyer_street', 'buyer_post_code', 'buyer_city', 'buyer_email', 'issue_date', 'sell_date', 'payment_to']))
                + ['kind' => $payload['invoice']['kind'], 'source' => self::SOURCE, 'source_id' => $chargeId, 'notes' => self::description($ch), 'is_test' => $test ? 1 : 0],
                array_values(array_filter([['name' => self::positionName($ch), 'unit' => 'mies.', 'qty' => 1, 'unit_net' => round((float)$ch['amount_pln'] - (float)($ch['fee_pln'] ?? 0), 2), 'vat_rate' => $cfg['vat']],
                    (float)($ch['fee_pln'] ?? 0) > 0 ? ['name' => 'Prowizja za obsługę płatności', 'unit' => 'szt.', 'qty' => 1, 'unit_net' => (float)$ch['fee_pln'], 'vat_rate' => $cfg['vat']] : null])), $uid);
            if ($uid <= 0) db_exec("UPDATE invoices SET created_by=NULL WHERE id=?", [$id]);
        }

        $warning = '';
        $inv = invoice_get($id);
        if (empty($inv['fakturownia_id'])) {
            if ($test) {
                // proforma testowa — z pominięciem invoice_push (ta odrzuca faktury testowe); numer nadaje Fakturownia
                $res = fakturownia_create_invoice($cfg['account'], $cfg['token'], $payload['invoice'] + ['positions' => $payload['positions'], 'extra' => $payload['extra']]);
                if (empty($res['success'])) return self::fail($chargeId, 'Fakturownia: ' . (string)($res['error'] ?? 'błąd'), $payload);
                db_exec("UPDATE invoices SET status='wystawiona', number=?, fakturownia_id=?, fakturownia_url=?, issued_at=datetime('now'), last_error=NULL, updated_at=datetime('now') WHERE id=?",
                    [(string)($res['number'] ?? ''), (int)$res['invoice_id'], (string)($res['invoice_url'] ?? ''), $id]);
                invoice_pdf_fetch($id);
                $data = (array)($res['data'] ?? []);
            } else {
                $r = invoice_push($id, ['positions' => $payload['positions'], 'extra' => $payload['extra']]);
                if (empty($r['ok'])) return self::fail($chargeId, 'Fakturownia: ' . (string)$r['error'], $payload);
                $data = (array)($r['fakturownia'] ?? []);
            }
            $got = $data['price_gross'] ?? null;
            if (!empty($data['token'])) db_exec("UPDATE claude_charges SET invoice_view_url=? WHERE id=?", ['https://' . $cfg['account'] . '.fakturownia.pl/invoice/' . $data['token'], $chargeId]);
            if ($got !== null && abs((float)str_replace(',', '.', (string)$got) - $payload['expected_gross']) > 0.009) {
                $warning = 'Fakturownia wystawiła dokument na ' . $got . ' zł zamiast ' . number_format($payload['expected_gross'], 2, ',', '') . ' zł — sprawdź w Fakturowni Ustawienia konta › „Jak obliczać rabat” (kwotowo) i skoryguj dokument.';
                db_exec("UPDATE invoices SET last_error=? WHERE id=?", [$warning, $id]);
            }
        }
        if (!$test && $ch['status'] === 'paid') db_exec("UPDATE invoices SET paid_at=COALESCE(paid_at, ?) WHERE id=?", [(string)($ch['paid_at'] ?: date('Y-m-d H:i:s')), $id]);
        $inv = invoice_get($id);
        db_exec("UPDATE claude_charges SET invoice_id=?, invoice_number=?, invoice_error=?, invoice_payload=? WHERE id=?",
            [$id, (string)$inv['number'], $warning, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), $chargeId]);
        if (!empty($ch['pp_item_id']) && !$test) {
            try { db_exec("INSERT OR IGNORE INTO pp_item_invoices (item_id, local_id, number, by_name) VALUES (?,?,?,?)", [(int)$ch['pp_item_id'], $id, (string)$inv['number'], 'Abonament Claude']); } catch (\Throwable $e) {}
        }
        ClaudeBilling::event((int)$ch['subscription_id'], $chargeId, 'invoice', ($test ? 'Proforma testowa ' : 'Faktura ') . $inv['number'] . ' w Fakturowni — '
            . ($payload['mode'] === 'rabat' ? 'z rabatem non-profit' : 'cena non-profit z opisem rabatu') . '.' . ($warning !== '' ? ' UWAGA: ' . $warning : ''), 'system');
        if (!$test && $s['invoice_email'] && $ch['status'] === 'paid') self::send($id, $ch);   // nieopłacona: link do faktury idzie w powiadomieniu o naliczeniu
        return ['ok' => true, 'number' => (string)$inv['number'], 'invoice_id' => $id, 'warning' => $warning, 'payload' => $payload, 'url' => (string)$inv['fakturownia_url']];
    }

    /** Po wpłacie: brak faktury → wystaw (już jako opłaconą); jest w Fakturowni → oznacz „opłacona” (change_status). */
    public static function afterPaid(int $chargeId): array
    {
        $ch = ClaudeBilling::charge($chargeId);
        if (!$ch || $ch['status'] !== 'paid') return ['ok' => false, 'error' => 'Naliczenie nie jest opłacone.'];
        if (empty($ch['invoice_id'])) return self::issueForCharge($chargeId);
        require_once dirname(__DIR__, 3) . '/includes/invoices.php';
        require_once dirname(__DIR__, 3) . '/includes/fakturownia.php';
        $inv = invoice_get((int)$ch['invoice_id']);
        if (!(int)$ch['is_test']) db_exec("UPDATE invoices SET paid_at=COALESCE(paid_at, datetime('now')) WHERE id=?", [(int)$ch['invoice_id']]);
        if (!$inv || empty($inv['fakturownia_id'])) return ['ok' => true];
        $cfg = invoices_config();
        $r = fakturownia_request($cfg['account'], $cfg['token'], 'invoices/' . (int)$inv['fakturownia_id'] . '/change_status.json?api_token=' . urlencode($cfg['token']) . '&status=paid', 'POST', []);
        $ok = $r['code'] >= 200 && $r['code'] < 300;
        ClaudeBilling::event((int)$ch['subscription_id'], $chargeId, $ok ? 'invoice_paid' : 'invoice_error', $ok ? 'Faktura ' . $inv['number'] . ' oznaczona w Fakturowni jako opłacona (' . $ch['paid_via'] . ').'
            : 'Nie oznaczono faktury ' . $inv['number'] . ' jako opłaconej w Fakturowni: ' . fakturownia_error($r), 'system');
        if (!$ok) db_exec("UPDATE claude_charges SET invoice_error=? WHERE id=?", ['Oznacz fakturę ' . $inv['number'] . ' jako opłaconą w Fakturowni ręcznie: ' . mb_substr(fakturownia_error($r), 0, 300), $chargeId]);
        $s = ClaudeBilling::settings(ClaudeBilling::productOf($ch));
        if ($ok && !(int)$ch['is_test'] && $s['invoice_email']) self::send((int)$ch['invoice_id'], ClaudeBilling::charge($chargeId));
        return ['ok' => $ok];
    }

    /** Czy faktura naliczenia jest opłacona w Fakturowni (Szybki przelew / wpłata zaksięgowana tam). */
    public static function paidInFakturownia(int $chargeId): bool
    {
        $ch = ClaudeBilling::charge($chargeId);
        if (!$ch || empty($ch['invoice_id'])) return false;
        require_once dirname(__DIR__, 3) . '/includes/invoices.php';
        require_once dirname(__DIR__, 3) . '/includes/fakturownia.php';
        $inv = invoice_get((int)$ch['invoice_id']);
        if (!$inv || empty($inv['fakturownia_id']) || !invoices_api_ready()) return false;
        $cfg = invoices_config();
        $r = fakturownia_get_invoice($cfg['account'], $cfg['token'], (int)$inv['fakturownia_id']);
        if (empty($r['ok'])) return false;
        $st = (string)($r['invoice']['status'] ?? '');
        $paidAmt = (float)str_replace([',', ' '], ['.', ''], (string)($r['invoice']['paid'] ?? '0'));
        return $st === 'paid' || $paidAmt + 0.009 >= (float)$ch['amount_pln'];
    }

    /** Usuwa testową proformę z Fakturowni i z rejestru SZO (strona testu). */
    public static function deleteTest(int $chargeId): string
    {
        $ch = ClaudeBilling::charge($chargeId);
        if (!$ch || !(int)$ch['is_test'] || empty($ch['invoice_id'])) return '';
        require_once dirname(__DIR__, 3) . '/includes/invoices.php';
        require_once dirname(__DIR__, 3) . '/includes/fakturownia.php';
        $inv = invoice_get((int)$ch['invoice_id']);
        $msg = '';
        if ($inv && !empty($inv['fakturownia_id'])) {
            $cfg = invoices_config();
            $r = fakturownia_request($cfg['account'], $cfg['token'], 'invoices/' . (int)$inv['fakturownia_id'] . '.json?api_token=' . urlencode($cfg['token']), 'DELETE');
            $msg = in_array($r['code'], [200, 204, 404], true) ? 'usunięto proformę ' . $inv['number'] . ' z Fakturowni' : 'nie usunięto z Fakturowni (' . fakturownia_error($r) . ')';
        }
        db_exec("UPDATE invoices SET deleted_at=datetime('now') WHERE id=?", [(int)$ch['invoice_id']]);
        db_exec("UPDATE claude_charges SET invoice_id=NULL, invoice_number='' WHERE id=?", [$chargeId]);
        return $msg;
    }

    private static function fail(int $chargeId, string $error, array $payload): array
    {
        $ch = ClaudeBilling::charge($chargeId);
        $same = (string)($ch['invoice_error'] ?? '') === mb_substr($error, 0, 500);   // ponowienia (CRON, kolejne wpłaty) nie zaśmiecają dziennika tym samym błędem
        db_exec("UPDATE claude_charges SET invoice_error=?, invoice_payload=? WHERE id=?", [mb_substr($error, 0, 500), json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), $chargeId]);
        if (!$same) ClaudeBilling::event((int)$ch['subscription_id'], $chargeId, 'invoice_error', 'Faktura nie wystawiona: ' . $error, 'system');
        return ['ok' => false, 'error' => $error, 'payload' => $payload];
    }

    /** Faktura do uczestnika e-mailem (PDF z Fakturowni w załączniku) — z informacją, że opłacona. */
    private static function send(int $invoiceId, array $ch): void
    {
        $inv = invoice_get($invoiceId);
        $to = trim((string)($inv['buyer_email'] ?? ''));
        if (!$inv || !filter_var($to, FILTER_VALIDATE_EMAIL) || empty($inv['number'])) return;
        if (empty($inv['pdf_path']) || !is_file(invoices_pdf_dir() . '/' . basename((string)$inv['pdf_path']))) { invoice_pdf_fetch($invoiceId); $inv = invoice_get($invoiceId); }
        $path = !empty($inv['pdf_path']) ? invoices_pdf_dir() . '/' . basename((string)$inv['pdf_path']) : '';
        $att = $path !== '' && is_file($path) ? [['path' => $path, 'name' => 'Faktura_' . preg_replace('/[^A-Za-z0-9\-_]+/', '-', (string)$inv['number']) . '.pdf', 'mime' => 'application/pdf', 'size' => (int)@filesize($path)]] : [];
        $L = ClaudeBilling::P(ClaudeBilling::productOf($ch))['label'];
        $html = '<p>Dzień dobry,</p><p>w załączniku faktura <b>' . h((string)$inv['number']) . '</b> za ' . h($L) . ' ' . h(ClaudeBilling::periodIn((string)$ch['period'])) . ': <b>'
              . ClaudeBilling::zl((float)$ch['amount_pln']) . '</b>. Jest już opłacona — nic więcej nie trzeba robić. Dziękujemy!</p>';
        try {
            require_once dirname(__DIR__, 3) . '/includes/mail_queue.php';
            mail_queue_add($to, (string)$inv['buyer_name'], 'Faktura ' . $inv['number'] . ' za ' . $L . ' (opłacona)', $html, '', 'invoice', $invoiceId, '', false, $att);
        } catch (\Throwable $e) { error_log('claude invoice mail: ' . $e->getMessage()); }
    }
}
