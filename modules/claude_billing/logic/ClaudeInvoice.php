<?php
declare(strict_types=1);

/**
 * modules/claude_billing/logic/ClaudeInvoice.php — dokumenty w Fakturowni za miesiąc płatnej licencji (Claude, NordVPN), zawsze ze stawką „zw”.
 *
 * Obieg (polecenie 2026-11-09): przy naliczeniu PROFORMA (termin 5 dni, rachunek do wpłat, przycisk Szybkiego przelewu), po wpłacie
 * ZAMÓWIENIE i FAKTURA (opłacona, from_invoice_id = proforma). Dokumenty powstają wyłącznie w Fakturowni (API: github.com/fakturownia/api);
 * rejestr w SZO: moduł Faktury (invoices + invoice_items; source claude_pf / claude_zam / claude, source_id = id naliczenia — indeks źródła
 * chroni przed duplikatem), w naliczeniu: proforma_* / order_* / invoice_*.
 *   • pozycja usługi w cenie regularnej (list_usd × kurs + narzut) z rabatem kwotowym do ceny non-profit (show_discount, discount_kind=amount),
 *     prowizja za płatność — osobna pozycja; oid {PRODUKT}/PF|ZAM/{id} + oid_unique (bez dubli w Fakturowni); opis z przeliczeniem.
 *   • po wystawieniu porównujemy kwotę brutto z odpowiedzi — gdy rabat nie został naliczony (ustawienia konta), zapisujemy ostrzeżenie.
 * Nabywca = dane do faktury z abonamentu; braki → dokument czeka, biuro i uczestnik dostają informację, CRON ponawia.
 * Naliczenia sprzed zmiany (faktura VAT wystawiona od razu, bez proformy) obsługujemy jak dawniej: po wpłacie change_status=paid.
 * Test (is_test=1): proforma i zamówienie powstają naprawdę (nie są dokumentami księgowymi, „Wyczyść test” je usuwa), faktura VAT — tylko podgląd.
 * SYMULACJA: gdy Fakturownia nie jest podłączona, odrzuci dokument albo w ustawieniach włączono symulację — dokument zapisuje się w SZO
 * z numerem SYM-PF/… / SYM-ZAM/… / SYM-FV/… (status „symulacja”, powód w last_error) i jest wyraźnie oznaczony w panelach; uczestnik go
 * nie widzi. Gdy Fakturownia działa, ponowne wystawienie (CRON, przycisk) zastępuje symulację prawdziwym dokumentem.
 */
require_once __DIR__ . '/claudeBilling.php';

final class ClaudeInvoice
{
    /** Stawka VAT dokumentów — zawsze zwolnione (polecenie 2026-11-09; 23% VAT od importu usługi jest w cenie). */
    public const TAX = 'zw';
    public const DOCS = [
        'proforma' => ['kind' => 'proforma',     'source' => 'claude_pf',  'label' => 'Proforma',   'oid' => 'PF/',  'sym' => 'SYM-PF/'],
        'order'    => ['kind' => 'client_order', 'source' => 'claude_zam', 'label' => 'Zamówienie', 'oid' => 'ZAM/', 'sym' => 'SYM-ZAM/'],
        'invoice'  => ['kind' => 'vat',          'source' => 'claude',     'label' => 'Faktura',    'oid' => '',     'sym' => 'SYM-FV/'],
    ];

    /** Czy numer dokumentu to symulacja (SYM-…). */
    public static function isSim(?string $number): bool { return str_starts_with((string)$number, 'SYM-'); }

    /** Czy dokumenty idą do Fakturowni naprawdę (konto i token ustawione, symulacja wyłączona). */
    public static function live(?array $s = null): bool
    {
        require_once dirname(__DIR__, 3) . '/includes/invoices.php';
        $s ??= ClaudeBilling::settings();
        return invoices_api_ready() && empty($s['simulate']);
    }

    /** Opis przeliczenia i rabatu — na dokumencie (description) i w uwagach w SZO. */
    public static function description(array $ch): string
    {
        $disc = round((float)$ch['list_pln'] - (float)$ch['amount_pln'], 2);
        $kind = PkoRates::KINDS[$ch['rate_kind']] ?? 'kurs';
        $P = ClaudeBilling::P(ClaudeBilling::productOf($ch));
        return ((int)$ch['is_test'] ? '[TEST — dokument testowy] ' : '')
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

    /** Jak zapłacić — dopisek na proformie. */
    public static function payHint(array $ch, ?array $acc): string
    {
        if ($ch['status'] === 'paid') return '';
        return 'Termin płatności ' . ClaudeBilling::TERM_DAYS . ' dni. Zapłać: Szybki przelew (przycisk przy dokumencie — BLIK, szybki przelew), Przelewy24 w panelu „Moje licencje”'
            . ($acc ? ' albo przelewem na rachunek ' . $acc['nrb_fmt'] . ' (tytuł: ' . ClaudeBilling::transferTitle($ch) . ')' : '') . '. Fakturę wystawimy po wpłacie.';
    }

    /** Dane wysyłane do Fakturowni dla dokumentu ($doc: proforma | order | invoice) — także podgląd na stronie testu. */
    public static function payload(array $ch, string $doc = 'proforma', ?string $mode = null): array
    {
        $D = self::DOCS[$doc] ?? self::DOCS['proforma'];
        $mode ??= ClaudeBilling::settings(ClaudeBilling::productOf($ch))['discount_mode'];
        $vat = self::TAX;
        $test = (int)$ch['is_test'] === 1;
        $b = self::buyer($ch);
        $fee = round((float)($ch['fee_pln'] ?? 0), 2);
        $svc = round((float)$ch['amount_pln'] - $fee, 2);          // usługa: USD × kurs + narzut % (bez prowizji)
        $svcList = round((float)$ch['list_pln'] - $fee, 2);
        $disc = round($svcList - $svc, 2);
        $useDiscount = $mode === 'rabat' && $disc > 0;
        $paid = $ch['status'] === 'paid';
        $paidDate = $ch['paid_at'] ? date('Y-m-d', strtotime((string)$ch['paid_at'])) : date('Y-m-d');
        $issue = $doc === 'proforma' ? (string)($ch['issue_on'] ?: date('Y-m-d')) : $paidDate;
        $acc = ClaudeBilling::payAccount((int)$ch['client_id'], ClaudeBilling::productOf($ch));
        $parts = preg_split('/\s+/u', trim((string)$b['buyer_name'])) ?: [];
        $last = count($parts) > 1 ? (string)array_pop($parts) : '';
        $company = $b['buyer_tax_no'] !== '';
        $pos = ['name' => self::positionName($ch), 'quantity' => 1, 'quantity_unit' => 'mies.', 'tax' => $vat];
        $pos += $useDiscount ? ['price_net' => $svcList, 'price_gross' => $svcList, 'discount' => $disc, 'discount_percent' => round($disc / max(0.01, $svcList) * 100, 4)]
                             : ['total_price_gross' => $svc];
        $positions = [$pos];
        if ($fee > 0) $positions[] = ['name' => 'Prowizja za obsługę płatności', 'quantity' => 1, 'quantity_unit' => 'szt.', 'tax' => $vat, 'total_price_gross' => $fee];
        $inv = [
            'kind' => $D['kind'],
            'sell_date' => $issue, 'issue_date' => $issue, 'payment_to' => $doc === 'proforma' ? (string)$ch['due_on'] : $paidDate,
            'buyer_name' => $b['buyer_name'], 'buyer_tax_no' => $b['buyer_tax_no'], 'buyer_street' => $b['buyer_street'],
            'buyer_post_code' => $b['buyer_post_code'], 'buyer_city' => $b['buyer_city'], 'buyer_email' => $b['buyer_email'], 'currency' => 'PLN',
        ];
        $extra = ['oid' => ($test ? 'TEST-' : '') . strtoupper(ClaudeBilling::productOf($ch)) . '/' . $D['oid'] . (int)$ch['id'], 'oid_unique' => 'yes',
                  'description' => trim(self::description($ch) . ' ' . ($doc === 'proforma' ? self::payHint($ch, $acc) : '')),
                  'payment_to_kind' => 'other_date', 'buyer_company' => $company ? '1' : '0', 'lang' => 'pl', 'buyer_country' => 'PL',
                  'payment_type' => ($ch['paid_via'] ?? '') === 'Przelewy24' ? 'Przelewy24' : 'transfer'];
        if (!$company) $extra += ['buyer_first_name' => implode(' ', $parts), 'buyer_last_name' => $last];
        if ($acc) $extra['seller_bank_account'] = $acc['nrb_fmt'];   // rachunek do wpłat (ustawienie modułu)
        if ($doc !== 'proforma' && !empty($ch['proforma_fid'])) $extra['from_invoice_id'] = (int)$ch['proforma_fid'];   // zamówienie i faktura na podstawie proformy
        if ($doc === 'invoice' && $paid) $extra += ['status' => 'paid', 'paid' => number_format((float)$ch['amount_pln'], 2, '.', ''), 'paid_date' => $paidDate];
        if ($useDiscount) $extra += ['show_discount' => '1', 'discount_kind' => 'amount'];
        return ['doc' => $doc, 'invoice' => $inv, 'positions' => $positions, 'extra' => $extra, 'mode' => $useDiscount ? 'rabat' : 'opis', 'expected_gross' => (float)$ch['amount_pln']];
    }

    /** Naliczenie z identyfikatorem proformy w Fakturowni (potrzebny do from_invoice_id). */
    private static function chargeWithDocs(int $chargeId): ?array
    {
        $ch = ClaudeBilling::charge($chargeId);
        if (!$ch) return null;
        $ch['proforma_fid'] = null;
        if (!empty($ch['proforma_id'])) $ch['proforma_fid'] = db_one("SELECT fakturownia_id FROM invoices WHERE id=?", [(int)$ch['proforma_id']])['fakturownia_id'] ?? null;
        return $ch;
    }

    /**
     * Wystawia jeden dokument w Fakturowni. @return array{ok:bool,number?:string,error?:string,skipped?:bool,warning?:string,payload?:array,local_id?:int,url?:string,gaps?:array}
     */
    public static function issueDoc(int $chargeId, string $doc, bool $force = false, ?int $uid = null): array
    {
        $D = self::DOCS[$doc] ?? null;
        $ch = self::chargeWithDocs($chargeId);
        if (!$D || !$ch) return ['ok' => false, 'error' => 'Nie ma takiego naliczenia.'];
        $col = $doc;   // kolumny naliczenia: proforma_* / order_* / invoice_*
        $s0 = ClaudeBilling::settings(ClaudeBilling::productOf($ch));
        if (!empty($ch[$col . '_id'])) {
            if (!self::isSim((string)$ch[$col . '_number']) || !self::live($s0)) {
                return ['ok' => true, 'number' => (string)$ch[$col . '_number'], 'local_id' => (int)$ch[$col . '_id'], 'simulated' => self::isSim((string)$ch[$col . '_number'])];
            }
            // Fakturownia już działa — symulację zastępujemy prawdziwym dokumentem
            db_exec("UPDATE invoices SET deleted_at=datetime('now') WHERE id=?", [(int)$ch[$col . '_id']]);
            db_exec("UPDATE claude_charges SET {$col}_id=NULL, {$col}_number=''" . ($col !== 'order' ? ", {$col}_view_url=''" : '') . " WHERE id=?", [$chargeId]);
            $ch[$col . '_id'] = null; $ch[$col . '_number'] = '';
        }
        if ($ch['status'] === 'cancelled') return ['ok' => false, 'error' => 'Naliczenie anulowane — bez dokumentów.'];
        $test = (int)$ch['is_test'] === 1;
        $s = ClaudeBilling::settings(ClaudeBilling::productOf($ch));
        if (!$force && !$test && $s['invoice'] !== 'auto') return ['ok' => false, 'skipped' => true, 'error' => 'Dokumenty wystawiane ręcznie (ustawienie).'];

        require_once dirname(__DIR__, 3) . '/includes/invoices.php';
        require_once dirname(__DIR__, 3) . '/includes/fakturownia.php';
        invoices_migrate();
        $cfg = invoices_config();
        $payload = self::payload($ch, $doc);
        if ($test && $doc === 'invoice') {   // faktury VAT w teście nie wystawiamy (dokument księgowy, KSeF) — tylko podgląd danych
            db_exec("UPDATE claude_charges SET invoice_payload=? WHERE id=?", [json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), $chargeId]);
            ClaudeBilling::event((int)$ch['subscription_id'], $chargeId, 'invoice', 'Faktura (test): tylko podgląd danych — w teście nie wystawiamy faktury VAT.', 'test');
            return ['ok' => false, 'skipped' => true, 'error' => 'W teście faktura VAT jest tylko w podglądzie.', 'payload' => $payload];
        }
        $sub = ClaudeBilling::subscription((int)$ch['subscription_id']) ?? [];
        if ($gaps = ClaudeBilling::invoiceDataGaps(self::buyer($ch))) {
            ClaudeBilling::alertBuyerGaps($sub, $gaps, $ch);
            return self::fail($chargeId, 'Brak danych do faktury: ' . implode(', ', $gaps) . '.', $payload) + ['gaps' => $gaps];
        }
        $uid ??= (int)(db_one("SELECT id FROM users WHERE role='admin' ORDER BY id LIMIT 1")['id'] ?? 0);
        $fee = round((float)($ch['fee_pln'] ?? 0), 2);
        $ex = db_one("SELECT id FROM invoices WHERE source=? AND source_id=? AND is_test=? AND deleted_at IS NULL", [$D['source'], $chargeId, $test ? 1 : 0]);
        if ($ex) $id = (int)$ex['id'];
        else {
            $items = [['name' => self::positionName($ch), 'unit' => 'mies.', 'qty' => 1, 'unit_net' => round((float)$ch['amount_pln'] - $fee, 2), 'vat_rate' => self::TAX]];
            if ($fee > 0) $items[] = ['name' => 'Prowizja za obsługę płatności', 'unit' => 'szt.', 'qty' => 1, 'unit_net' => $fee, 'vat_rate' => self::TAX];
            $id = invoice_create(array_intersect_key($payload['invoice'], array_flip(['buyer_name', 'buyer_tax_no', 'buyer_street', 'buyer_post_code', 'buyer_city', 'buyer_email', 'issue_date', 'sell_date', 'payment_to']))
                + ['kind' => $D['kind'], 'source' => $D['source'], 'source_id' => $chargeId, 'notes' => self::description($ch), 'is_test' => $test ? 1 : 0], $items, $uid);
            if ($uid <= 0) db_exec("UPDATE invoices SET created_by=NULL WHERE id=?", [$id]);
        }
        if (!self::live($s)) {
            return self::simulate($chargeId, $doc, $ch, $payload, $id, !empty($s['simulate']) ? 'włączony tryb symulacji (Płatne licencje › Ustawienia)'
                : 'Fakturownia nie jest podłączona (Administracja › Faktury: konto i token API)');
        }
        $warning = '';
        $inv = invoice_get($id);
        $data = [];
        if (empty($inv['fakturownia_id'])) {
            if ($test) {   // dokument testowy — z pominięciem invoice_push (ta odrzuca dokumenty testowe); numer nadaje Fakturownia
                $res = fakturownia_create_invoice($cfg['account'], $cfg['token'], $payload['invoice'] + ['positions' => $payload['positions'], 'extra' => $payload['extra']]);
                if (empty($res['success'])) return self::simulate($chargeId, $doc, $ch, $payload, $id, 'Fakturownia odrzuciła dokument: ' . (string)($res['error'] ?? 'błąd połączenia'));
                db_exec("UPDATE invoices SET status='wystawiona', number=?, fakturownia_id=?, fakturownia_url=?, issued_at=datetime('now'), last_error=NULL, updated_at=datetime('now') WHERE id=?",
                    [(string)($res['number'] ?? ''), (int)$res['invoice_id'], (string)($res['invoice_url'] ?? ''), $id]);
                invoice_pdf_fetch($id);
                $data = (array)($res['data'] ?? []);
            } else {
                $r = invoice_push($id, ['positions' => $payload['positions'], 'extra' => $payload['extra']]);
                if (empty($r['ok'])) return self::simulate($chargeId, $doc, $ch, $payload, $id, 'Fakturownia odrzuciła dokument albo nie odpowiada: ' . (string)$r['error']);
                $data = (array)($r['fakturownia'] ?? []);
            }
            $got = $data['price_gross'] ?? null;
            if ($got !== null && abs((float)str_replace(',', '.', (string)$got) - $payload['expected_gross']) > 0.009) {
                $warning = $D['label'] . ' w Fakturowni ma kwotę ' . $got . ' zł zamiast ' . number_format($payload['expected_gross'], 2, ',', '') . ' zł — sprawdź Ustawienia konta › „Jak obliczać rabat” (kwotowo) i popraw dokument.';
                db_exec("UPDATE invoices SET last_error=? WHERE id=?", [$warning, $id]);
            }
        }
        $inv = invoice_get($id);
        $view = !empty($data['token']) ? 'https://' . $cfg['account'] . '.fakturownia.pl/invoice/' . $data['token'] : '';
        $sets = "{$col}_id=?, {$col}_number=?, invoice_error=?, invoice_payload=?" . ($col !== 'order' && $view !== '' ? ", {$col}_view_url=?" : '');
        $par = [$id, (string)$inv['number'], $warning, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)];
        if ($col !== 'order' && $view !== '') $par[] = $view;
        db_exec("UPDATE claude_charges SET $sets WHERE id=?", [...$par, $chargeId]);
        if ($doc === 'invoice' && !$test) {
            if ($ch['status'] === 'paid') db_exec("UPDATE invoices SET paid_at=COALESCE(paid_at, ?) WHERE id=?", [(string)($ch['paid_at'] ?: date('Y-m-d H:i:s')), $id]);
            if (!empty($ch['pp_item_id'])) { try { db_exec("INSERT OR IGNORE INTO pp_item_invoices (item_id, local_id, number, by_name) VALUES (?,?,?,?)", [(int)$ch['pp_item_id'], $id, (string)$inv['number'], 'Płatne licencje']); } catch (\Throwable $e) {} }
        }
        ClaudeBilling::event((int)$ch['subscription_id'], $chargeId, $doc, $D['label'] . ($test ? ' testowa' : '') . ' ' . $inv['number'] . ' w Fakturowni (zw) — '
            . ($payload['mode'] === 'rabat' ? 'z rabatem non-profit' : 'cena non-profit z opisem rabatu') . '.' . ($warning !== '' ? ' UWAGA: ' . $warning : ''), 'system');
        if ($doc === 'invoice' && !$test && $s['invoice_email']) self::send($id, ClaudeBilling::charge($chargeId));
        return ['ok' => true, 'number' => (string)$inv['number'], 'local_id' => $id, 'warning' => $warning, 'payload' => $payload, 'url' => (string)$inv['fakturownia_url']];
    }

    /**
     * Dokument właściwy dla stanu naliczenia: do zapłaty → proforma; opłacone → zamówienie i faktura (afterPaid).
     * $force — ręcznie z panelu (także przy ustawieniu „ręcznie”/„wyłączone”).
     */
    public static function issueForCharge(int $chargeId, bool $force = false, ?int $uid = null): array
    {
        $ch = ClaudeBilling::charge($chargeId);
        if (!$ch) return ['ok' => false, 'error' => 'Nie ma takiego naliczenia.'];
        if ($ch['status'] === 'paid') return self::afterPaid($chargeId, $force, $uid);
        if (!empty($ch['invoice_id']) && empty($ch['proforma_id'])) return ['ok' => true, 'number' => (string)$ch['invoice_number']];   // naliczenie sprzed zmiany (faktura od razu)
        return self::issueDoc($chargeId, 'proforma', $force, $uid);
    }

    /** Po wpłacie: proforma „opłacona”, potem zamówienie i faktura (opłacona). Naliczenia sprzed zmiany — faktura „opłacona” (change_status). */
    public static function afterPaid(int $chargeId, bool $force = false, ?int $uid = null): array
    {
        $ch = ClaudeBilling::charge($chargeId);
        if (!$ch || $ch['status'] !== 'paid') return ['ok' => false, 'error' => 'Naliczenie nie jest opłacone.'];
        if (!empty($ch['invoice_id']) && empty($ch['proforma_id'])) return self::markPaidLegacy($ch);
        if (!empty($ch['proforma_id'])) self::changeStatusPaid((int)$ch['proforma_id'], $ch, 'Proforma');
        $o = self::issueDoc($chargeId, 'order', $force, $uid);
        if (empty($o['ok']) && empty($o['skipped'])) return $o;
        return self::issueDoc($chargeId, 'invoice', $force, $uid);
    }

    /** change_status=paid dla dokumentu w Fakturowni (best effort). */
    private static function changeStatusPaid(int $localId, array $ch, string $label): bool
    {
        require_once dirname(__DIR__, 3) . '/includes/invoices.php';
        require_once dirname(__DIR__, 3) . '/includes/fakturownia.php';
        $inv = invoice_get($localId);
        if (!$inv || empty($inv['fakturownia_id']) || self::isSim((string)$inv['number']) || !self::live()) return false;
        $cfg = invoices_config();
        $r = fakturownia_request($cfg['account'], $cfg['token'], 'invoices/' . (int)$inv['fakturownia_id'] . '/change_status.json?api_token=' . urlencode($cfg['token']) . '&status=paid', 'POST', []);
        $ok = $r['code'] >= 200 && $r['code'] < 300;
        ClaudeBilling::event((int)$ch['subscription_id'], (int)$ch['id'], $ok ? 'doc_paid' : 'invoice_error', $ok ? $label . ' ' . $inv['number'] . ' oznaczona w Fakturowni jako opłacona.'
            : 'Nie oznaczono dokumentu ' . $inv['number'] . ' jako opłaconego w Fakturowni: ' . fakturownia_error($r), 'system');
        return $ok;
    }

    private static function markPaidLegacy(array $ch): array
    {
        if (!(int)$ch['is_test']) db_exec("UPDATE invoices SET paid_at=COALESCE(paid_at, datetime('now')) WHERE id=?", [(int)$ch['invoice_id']]);
        $ok = self::changeStatusPaid((int)$ch['invoice_id'], $ch, 'Faktura');
        if (!$ok) db_exec("UPDATE claude_charges SET invoice_error=? WHERE id=?", ['Oznacz fakturę ' . $ch['invoice_number'] . ' jako opłaconą w Fakturowni ręcznie.', (int)$ch['id']]);
        if ($ok && !(int)$ch['is_test'] && ClaudeBilling::settings(ClaudeBilling::productOf($ch))['invoice_email']) self::send((int)$ch['invoice_id'], $ch);
        return ['ok' => $ok, 'number' => (string)$ch['invoice_number']];
    }

    /** Czy dokument do zapłaty (proforma, a dla starych naliczeń faktura) jest opłacony w Fakturowni (Szybki przelew / wpłata zaksięgowana tam). */
    public static function paidInFakturownia(int $chargeId): bool
    {
        $ch = ClaudeBilling::charge($chargeId);
        $local = (int)($ch['proforma_id'] ?: $ch['invoice_id'] ?: 0);
        if (!$ch || !$local) return false;
        require_once dirname(__DIR__, 3) . '/includes/invoices.php';
        require_once dirname(__DIR__, 3) . '/includes/fakturownia.php';
        $inv = invoice_get($local);
        if (!$inv || empty($inv['fakturownia_id']) || self::isSim((string)$inv['number']) || !self::live()) return false;
        $cfg = invoices_config();
        $r = fakturownia_get_invoice($cfg['account'], $cfg['token'], (int)$inv['fakturownia_id']);
        if (empty($r['ok'])) return false;
        $paidAmt = (float)str_replace([',', ' '], ['.', ''], (string)($r['invoice']['paid'] ?? '0'));
        return (string)($r['invoice']['status'] ?? '') === 'paid' || $paidAmt + 0.009 >= (float)$ch['amount_pln'];
    }

    /** Usuwa testowe dokumenty (proforma, zamówienie) z Fakturowni i z rejestru SZO (strona testu). */
    public static function deleteTest(int $chargeId): string
    {
        $ch = ClaudeBilling::charge($chargeId);
        if (!$ch || !(int)$ch['is_test']) return '';
        require_once dirname(__DIR__, 3) . '/includes/invoices.php';
        require_once dirname(__DIR__, 3) . '/includes/fakturownia.php';
        $cfg = invoices_config();
        $msg = [];
        foreach (['proforma_id', 'order_id', 'invoice_id'] as $c) {
            if (empty($ch[$c])) continue;
            $inv = invoice_get((int)$ch[$c]);
            if ($inv && !empty($inv['fakturownia_id'])) {
                $r = fakturownia_request($cfg['account'], $cfg['token'], 'invoices/' . (int)$inv['fakturownia_id'] . '.json?api_token=' . urlencode($cfg['token']), 'DELETE');
                $msg[] = in_array($r['code'], [200, 204, 404], true) ? 'usunięto ' . $inv['number'] . ' z Fakturowni' : 'nie usunięto ' . $inv['number'] . ' z Fakturowni (' . fakturownia_error($r) . ')';
            }
            db_exec("UPDATE invoices SET deleted_at=datetime('now') WHERE id=?", [(int)$ch[$c]]);
        }
        db_exec("UPDATE claude_charges SET proforma_id=NULL, proforma_number='', order_id=NULL, order_number='', invoice_id=NULL, invoice_number='' WHERE id=?", [$chargeId]);
        return implode('; ', $msg);
    }

    /** Symulacja wystawienia: dokument w SZO z numerem SYM-…, oznaczony i z powodem; nic nie idzie do Fakturowni ani do uczestnika. */
    private static function simulate(int $chargeId, string $doc, array $ch, array $payload, int $localId, string $why): array
    {
        $D = self::DOCS[$doc];
        $no = $D['sym'] . (int)$ch['id'];
        db_exec("UPDATE invoices SET status='symulacja', number=?, last_error=?, fakturownia_id=NULL, updated_at=datetime('now') WHERE id=?", [$no, mb_substr($why, 0, 500), $localId]);
        $msg = 'SYMULACJA: ' . $D['label'] . ' ' . $no . ' — ' . $why . '. Dokument NIE istnieje w Fakturowni; zostanie wystawiony naprawdę, gdy połączenie zadziała (CRON co godzinę albo przycisk na karcie).';
        $same = (string)($ch['invoice_error'] ?? '') === mb_substr($msg, 0, 500);
        db_exec("UPDATE claude_charges SET {$doc}_id=?, {$doc}_number=?, invoice_error=?, invoice_payload=? WHERE id=?",
            [$localId, $no, mb_substr($msg, 0, 500), json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), $chargeId]);
        if (!$same) ClaudeBilling::event((int)$ch['subscription_id'], $chargeId, 'simulated', $msg, 'system');
        return ['ok' => true, 'simulated' => true, 'number' => $no, 'local_id' => $localId, 'warning' => $msg, 'payload' => $payload];
    }

    private static function fail(int $chargeId, string $error, array $payload): array
    {
        $ch = ClaudeBilling::charge($chargeId);
        $same = (string)($ch['invoice_error'] ?? '') === mb_substr($error, 0, 500);   // ponowienia (CRON, kolejne wpłaty) nie zaśmiecają dziennika tym samym błędem
        db_exec("UPDATE claude_charges SET invoice_error=?, invoice_payload=? WHERE id=?", [mb_substr($error, 0, 500), json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), $chargeId]);
        if (!$same) ClaudeBilling::event((int)$ch['subscription_id'], $chargeId, 'invoice_error', 'Dokument nie wystawiony: ' . $error, 'system');
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
        } catch (\Throwable $e) { error_log('licencje invoice mail: ' . $e->getMessage()); }
    }
}
