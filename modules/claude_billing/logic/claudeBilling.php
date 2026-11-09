<?php
declare(strict_types=1);

/**
 * modules/claude_billing/logic/claudeBilling.php — abonament dostępu do Claude (Claude Team w programie Claude for Nonprofit).
 *
 * Zasady (polecenie z 2026-11-09):
 *   • Uczestnik z aktywną licencją Claude (moduł Licencje, ti_term_is_claude_license) sam aktywuje dostęp w panelu
 *     (zakładka Licencje) — akceptuje warunki, a w Helpdesku IT powstaje zgłoszenie „aktywacja Claude”.
 *   • Opłata co miesiąc: 8 USD (cena non-profit; ustawienie) × ostatni kurs PKO BP (domyślnie sprzedaż dewiz) z chwili naliczenia.
 *   • Naliczenie (i faktura VAT w Fakturowni z rabatem non-profit) w dniu aktywacji, potem co miesiąc w dniu aktywacji, ale nie
 *     później niż 8. dnia miesiąca (dzień graniczny zmienia kierownik — globalnie albo dla jednej osoby). Termin płatności: zawsze 5 dni.
 *   • Płatność: „Zapłać” domyślnie prowadzi do portalu płatności SZO (/platnosci — Przelewy24 albo przelew na indywidualny rachunek
 *     wirtualny, który jest też na fakturze); faktura z Fakturowni ma przycisk Szybkiego przelewu (Autopay: BLIK, szybki przelew).
 *     Wpłata w portalu (pp_settle) → faktura „opłacona” w Fakturowni; wpłata w Fakturowni → CRON odczytuje status i zamyka naliczenie.
 *   • Pilnowanie płatności: przypomnienie w dniu terminu, po N dniach zaległości dostęp wstrzymany (zgłoszenie dezaktywacji
 *     w Helpdesku), po uregulowaniu wznowiony (zgłoszenie przywrócenia). Rezygnacja — zgłoszenie dezaktywacji.
 *
 * Tabele: claude_subscriptions, claude_charges, claude_events (dziennik), claude_fx_rates (lib/PkoRates.php).
 * Tryb testowy (is_test=1): bez e-maili do ludzi, bez prawdziwych zgłoszeń Helpdesk (chyba że jawnie), faktura TEST bez Fakturowni.
 */
require_once dirname(__DIR__, 3) . '/includes/db.php';
require_once dirname(__DIR__, 3) . '/includes/functions.php';
require_once dirname(__DIR__) . '/lib/PkoRates.php';

final class ClaudeBilling
{
    public const STATUS = ['active' => 'aktywny', 'suspended' => 'wstrzymany (zaległość)', 'ended' => 'zakończony'];
    public const CHARGE_STATUS = ['pending' => 'do zapłaty', 'paid' => 'opłacone', 'cancelled' => 'anulowane'];
    public const DEFAULTS = [
        'enabled' => '1', 'usd' => '8', 'list_usd' => '25', 'due_day' => '8', 'grace_days' => '7',
        'rate_kind' => 'sale_foreign', 'invoice' => 'auto', 'discount_mode' => 'rabat', 'invoice_email' => '1', 'notify' => '1', 'helpdesk' => '1',
        'pay_redirect' => 'portal', 'fast_transfer_url' => 'https://fundacjafeer.fakturownia.pl/apps/szybki-przelew',
        'bank_account' => '88102029060000130206617932',   // rachunek do wpłat za Claude (polecenie 2026-11-09)
    ];
    /** Termin płatności od naliczenia — zawsze 5 dni (polecenie 2026-11-09). */
    public const TERM_DAYS = 5;
    private const MONTHS = [1 => 'styczeń', 'luty', 'marzec', 'kwiecień', 'maj', 'czerwiec', 'lipiec', 'sierpień', 'wrzesień', 'październik', 'listopad', 'grudzień'];

    /** Zgłoszenia Helpdesk z trybu testowego — zamiast tworzenia (podgląd na stronie testu). */
    public static array $testTickets = [];
    /** Czy tworzyć prawdziwe zgłoszenia także dla testowych abonamentów (zaznaczone na stronie testu). */
    public static bool $testRealTickets = false;
    private static array $afterPay = [];
    private static bool $shutdownSet = false;

    // ── Schemat ─────────────────────────────────────────────────────────────────

    public static function migrate(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        if (function_exists('szo_schema_current') && szo_schema_current('claude_billing_migrate', __FILE__)) return;
        PkoRates::migrate();
        db()->exec("CREATE TABLE IF NOT EXISTS claude_subscriptions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            client_id INTEGER NOT NULL,
            status TEXT NOT NULL DEFAULT 'active',
            activated_on DATE NOT NULL,
            cycle_start DATE NOT NULL,
            due_day INTEGER,
            free INTEGER NOT NULL DEFAULT 0,
            assignment_id INTEGER,
            terms_text TEXT NOT NULL DEFAULT '',
            terms_accepted_at DATETIME,
            terms_ip TEXT NOT NULL DEFAULT '',
            activated_by TEXT NOT NULL DEFAULT '',
            suspended_at DATETIME,
            ended_on DATE,
            ended_reason TEXT NOT NULL DEFAULT '',
            helpdesk_number TEXT NOT NULL DEFAULT '',
            buyer_name TEXT NOT NULL DEFAULT '', buyer_tax_no TEXT NOT NULL DEFAULT '', buyer_street TEXT NOT NULL DEFAULT '',
            buyer_post_code TEXT NOT NULL DEFAULT '', buyer_city TEXT NOT NULL DEFAULT '', buyer_email TEXT NOT NULL DEFAULT '',
            buyer_gap_alert_at DATETIME,
            is_test INTEGER NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)");
        db()->exec("CREATE UNIQUE INDEX IF NOT EXISTS uq_claude_sub_open ON claude_subscriptions(client_id) WHERE status IN ('active','suspended')");
        db()->exec("CREATE TABLE IF NOT EXISTS claude_charges (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            subscription_id INTEGER NOT NULL REFERENCES claude_subscriptions(id) ON DELETE CASCADE,
            client_id INTEGER NOT NULL,
            period TEXT NOT NULL,
            due_on DATE NOT NULL,
            usd REAL NOT NULL, list_usd REAL NOT NULL DEFAULT 0,
            rate REAL NOT NULL, rate_kind TEXT NOT NULL DEFAULT '', rate_table TEXT NOT NULL DEFAULT '', rate_at TEXT NOT NULL DEFAULT '',
            amount_pln REAL NOT NULL, list_pln REAL NOT NULL DEFAULT 0,
            issue_on DATE, invoice_view_url TEXT NOT NULL DEFAULT '',
            status TEXT NOT NULL DEFAULT 'pending',
            pp_item_id INTEGER,
            paid_at DATETIME, paid_via TEXT NOT NULL DEFAULT '', paid_ref TEXT NOT NULL DEFAULT '',
            invoice_id INTEGER, invoice_number TEXT NOT NULL DEFAULT '', invoice_error TEXT NOT NULL DEFAULT '', invoice_payload TEXT,
            reminded_at DATETIME,
            note TEXT NOT NULL DEFAULT '',
            is_test INTEGER NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(subscription_id, period))");
        db()->exec("CREATE INDEX IF NOT EXISTS idx_claude_ch_status ON claude_charges(status, due_on)");
        db()->exec("CREATE INDEX IF NOT EXISTS idx_claude_ch_item ON claude_charges(pp_item_id)");
        db()->exec("CREATE TABLE IF NOT EXISTS claude_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            subscription_id INTEGER, charge_id INTEGER,
            kind TEXT NOT NULL, text TEXT NOT NULL DEFAULT '', by_name TEXT NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)");
        db()->exec("CREATE INDEX IF NOT EXISTS idx_claude_ev_sub ON claude_events(subscription_id, id)");
    }

    // ── Ustawienia ──────────────────────────────────────────────────────────────

    public static function settings(): array
    {
        $s = [];
        foreach (self::DEFAULTS as $k => $def) { $v = trim((string)org_setting('claude_bill_' . $k)); $s[$k] = $v !== '' ? $v : $def; }
        $s['usd'] = max(0.01, (float)str_replace(',', '.', $s['usd']));
        $s['list_usd'] = max(0.0, (float)str_replace(',', '.', $s['list_usd']));
        $s['due_day'] = min(28, max(1, (int)$s['due_day']));
        $s['grace_days'] = min(60, max(0, (int)$s['grace_days']));
        if (!isset(PkoRates::KINDS[$s['rate_kind']])) $s['rate_kind'] = 'sale_foreign';
        if (!in_array($s['invoice'], ['auto', 'manual', 'off'], true)) $s['invoice'] = 'auto';
        if (!in_array($s['discount_mode'], ['rabat', 'opis'], true)) $s['discount_mode'] = 'rabat';
        if (!in_array($s['pay_redirect'], ['portal', 'fakturownia'], true)) $s['pay_redirect'] = 'portal';
        if (!preg_match('#^https://#', $s['fast_transfer_url'])) $s['fast_transfer_url'] = self::DEFAULTS['fast_transfer_url'];
        $s['bank_account'] = preg_replace('/\D/', '', preg_replace('/^\s*PL/i', '', $s['bank_account']));
        if (strlen($s['bank_account']) !== 26) $s['bank_account'] = self::DEFAULTS['bank_account'];
        foreach (['enabled', 'invoice_email', 'notify', 'helpdesk'] as $b) $s[$b] = $s[$b] === '1';
        return $s;
    }

    public static function saveSettings(array $in, string $by): void
    {
        $num = fn($v) => str_replace(',', '.', trim((string)$v));
        $map = [
            'enabled' => !empty($in['enabled']) ? '1' : '0', 'usd' => $num($in['usd'] ?? ''), 'list_usd' => $num($in['list_usd'] ?? ''),
            'due_day' => (string)(int)($in['due_day'] ?? 8), 'grace_days' => (string)(int)($in['grace_days'] ?? 7),
            'pay_redirect' => (string)($in['pay_redirect'] ?? 'portal'), 'fast_transfer_url' => trim((string)($in['fast_transfer_url'] ?? '')),
            'bank_account' => preg_replace('/\D/', '', preg_replace('/^\s*PL/i', '', (string)($in['bank_account'] ?? ''))),
            'rate_kind' => (string)($in['rate_kind'] ?? 'sale_foreign'), 'invoice' => (string)($in['invoice'] ?? 'auto'), 'discount_mode' => (string)($in['discount_mode'] ?? 'rabat'),
            'invoice_email' => !empty($in['invoice_email']) ? '1' : '0', 'notify' => !empty($in['notify']) ? '1' : '0', 'helpdesk' => !empty($in['helpdesk']) ? '1' : '0',
        ];
        if ((float)$map['usd'] <= 0) throw new InvalidArgumentException('Kwota miesięczna w USD musi być większa od zera.');
        if ((int)$map['due_day'] < 1 || (int)$map['due_day'] > 28) throw new InvalidArgumentException('Dzień graniczny płatności: 1–28.');
        if ((float)$map['list_usd'] > 0 && (float)$map['list_usd'] < (float)$map['usd']) throw new InvalidArgumentException('Cena regularna nie może być niższa od ceny non-profit.');
        require_once dirname(__DIR__, 2) . '/payment_portal/logic/paymentPortal.php';
        if ($map['bank_account'] !== '' && !pp_nrb_valid($map['bank_account'])) throw new InvalidArgumentException('Numer rachunku do wpłat jest nieprawidłowy (26 cyfr, suma kontrolna IBAN).');
        if ($map['fast_transfer_url'] !== '' && !preg_match('#^https://[a-z0-9.-]+\.fakturownia\.pl/#i', $map['fast_transfer_url'])) throw new InvalidArgumentException('Adres Szybkiego przelewu musi wskazywać na konto w Fakturowni (https://….fakturownia.pl/…).');
        foreach ($map as $k => $v) org_setting_set('claude_bill_' . $k, $v);
        self::event(null, null, 'settings', 'Zmieniono ustawienia: ' . json_encode($map, JSON_UNESCAPED_UNICODE), $by);
    }

    /** Treść warunków akceptowanych przy aktywacji (zapisywana w abonamencie). */
    public static function termsText(?array $s = null): string
    {
        $s ??= self::settings();
        $usd = self::usd($s['usd']);
        return 'Aktywuję dostęp do narzędzia Claude (licencja Claude Team w programie Claude for Nonprofit, Anthropic) udostępniony przez Fundację. '
            . 'Akceptuję miesięczną opłatę równą ' . $usd . ' — cenie dla organizacji non-profit'
            . ($s['list_usd'] > 0 ? ' (cena regularna ' . self::usd($s['list_usd']) . ')' : '')
            . ' — przeliczonej na złote po ostatnim kursie ' . PkoRates::KINDS[$s['rate_kind']] . ' PKO Banku Polskiego z dnia naliczenia. '
            . 'Opłatę nalicza się (z fakturą VAT) w dniu aktywacji, a potem co miesiąc w dniu aktywacji, nie później niż ' . $s['due_day'] . '. dnia miesiąca; '
            . 'termin płatności wynosi ' . self::TERM_DAYS . ' dni. Płacę w portalu płatności (Przelewy24 albo przelew na mój indywidualny rachunek) '
            . 'albo Szybkim przelewem z faktury w Fakturowni. '
            . ($s['grace_days'] > 0 ? 'Jeżeli opłata nie wpłynie w ciągu ' . $s['grace_days'] . ' dni po terminie, dostęp zostanie wstrzymany do czasu jej uregulowania. '
                                    : 'Jeżeli opłata nie wpłynie w terminie, dostęp zostanie wstrzymany do czasu jej uregulowania. ')
            . 'Mogę zrezygnować w panelu uczestnika — wtedy kolejne opłaty nie będą naliczane, a dostęp zostanie wyłączony.';
    }

    // ── Pomocnicze ──────────────────────────────────────────────────────────────

    public static function zl(float $v): string { return number_format($v, 2, ',', ' ') . ' zł'; }
    public static function usd(float $v): string { return number_format($v, 2, ',', ' ') . ' USD'; }
    public static function rateFmt(float $v): string { return number_format($v, 4, ',', ' ') . ' zł'; }
    public static function date(?string $d): string { $t = $d ? strtotime($d) : false; return $t ? date('d.m.Y', $t) : '—'; }
    public static function periodLabel(string $p): string { [$y, $m] = array_map('intval', explode('-', $p . '-1')); return (self::MONTHS[$m] ?? $m) . ' ' . $y; }

    public static function dueDay(array $sub, ?array $s = null): int
    {
        $s ??= self::settings();
        return (int)($sub['due_day'] ?: $s['due_day']);
    }

    /** Termin płatności za okres: data naliczenia + 5 dni. */
    public static function dueFor(array $sub, string $period, ?array $s = null): string
    {
        return date('Y-m-d', strtotime(self::billingDate($sub, $period, $s) . ' +' . self::TERM_DAYS . ' day'));
    }

    /** Dzień naliczenia za okres RRRR-MM: miesiąc startu = dzień startu, później min(dzień startu, dzień graniczny). */
    public static function billingDate(array $sub, string $period, ?array $s = null): string
    {
        $start = (string)$sub['cycle_start'];
        if ($period === substr($start, 0, 7)) return $start;
        $day = min((int)date('j', strtotime($start)), self::dueDay($sub, $s));
        $last = (int)date('t', strtotime($period . '-01'));
        return $period . '-' . str_pad((string)min($day, $last), 2, '0', STR_PAD_LEFT);
    }

    /** Kwoty dla kursu (zaokrąglenie do grosza; rabat = różnica, żeby suma zawsze się zgadzała). */
    public static function amounts(float $rate, ?array $s = null): array
    {
        $s ??= self::settings();
        $amount = round($s['usd'] * $rate, 2);
        $list = $s['list_usd'] > 0 ? round($s['list_usd'] * $rate, 2) : $amount;
        return ['usd' => $s['usd'], 'list_usd' => $s['list_usd'] > 0 ? $s['list_usd'] : $s['usd'], 'amount_pln' => $amount, 'list_pln' => $list,
                'discount_pln' => round($list - $amount, 2), 'discount_usd' => round(max(0, $s['list_usd'] - $s['usd']), 2)];
    }

    /** Wycena bieżąca (pobiera kurs z PKO BP). */
    public static function quote(?string $onDate = null): array
    {
        $s = self::settings();
        $r = PkoRates::latest('USD', $s['rate_kind'], $onDate);
        return $r + self::amounts($r['rate'], $s);
    }

    /** Ostatni zapisany kurs (bez łączenia z PKO BP) — do orientacyjnego pokazania w panelu. */
    public static function lastRate(): ?array
    {
        self::migrate();
        $s = self::settings();
        $r = db_one("SELECT * FROM claude_fx_rates WHERE currency='USD' AND kind=? ORDER BY id DESC LIMIT 1", [$s['rate_kind']]);
        return $r ? $r + self::amounts((float)$r['rate'], $s) + ['kind_label' => PkoRates::KINDS[$s['rate_kind']]] : null;
    }

    public static function event(?int $subId, ?int $chargeId, string $kind, string $text, string $by = ''): void
    {
        self::migrate();
        db_insert('claude_events', ['subscription_id' => $subId, 'charge_id' => $chargeId, 'kind' => $kind, 'text' => mb_substr($text, 0, 2000), 'by_name' => $by]);
    }

    public static function client(int $clientId): ?array
    {
        return db_one("SELECT id, name, email, phone, address FROM k30_clients WHERE id=?", [$clientId]) ?: null;
    }

    public static function isMinor(int $clientId): bool
    {
        try { return (bool)db_one("SELECT 1 FROM k30_ti_student_accounts WHERE client_id=? AND COALESCE(is_minor,0)=1", [$clientId]); } catch (\Throwable $e) { return false; }
    }

    // ── Dane do faktury VAT ─────────────────────────────────────────────────────

    public const BUYER_FIELDS = ['buyer_name' => 'imię i nazwisko (nazwa nabywcy)', 'buyer_street' => 'ulica i numer', 'buyer_post_code' => 'kod pocztowy',
                                 'buyer_city' => 'miejscowość', 'buyer_email' => 'adres e-mail', 'buyer_tax_no' => 'NIP (tylko gdy płaci firma)'];

    /** Dane nabywcy podpowiadane z kartoteki uczestnika (adres rozbity na ulicę, kod, miasto). */
    public static function buyerDefaults(int $clientId): array
    {
        $c = self::client($clientId) ?? [];
        $addr = trim((string)($c['address'] ?? ''));
        $street = $addr; $code = ''; $city = '';
        if (preg_match('/^(.*?)[,\s]*(\d{2}-\d{3})\s+(.+)$/u', $addr, $m)) { $street = trim($m[1], " ,"); $code = $m[2]; $city = trim($m[3]); }
        elseif (preg_match('/^(\d{2}-\d{3})\s+([^,]+),\s*(.+)$/u', $addr, $m)) { $code = $m[1]; $city = trim($m[2]); $street = trim($m[3]); }
        return ['buyer_name' => trim((string)($c['name'] ?? '')), 'buyer_tax_no' => '', 'buyer_street' => $street, 'buyer_post_code' => $code,
                'buyer_city' => $city, 'buyer_email' => trim((string)($c['email'] ?? ''))];
    }

    /** Walidacja i normalizacja danych nabywcy z formularza. @return array{0:array,1:array<string,string>} [dane, błędy pole→komunikat] */
    public static function buyerFromInput(array $in): array
    {
        $d = [];
        foreach (array_keys(self::BUYER_FIELDS) as $k) $d[$k] = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($in[$k] ?? ''))), 0, 160);
        $d['buyer_tax_no'] = preg_replace('/[^0-9]/', '', $d['buyer_tax_no']);
        if (preg_match('/^\d{5}$/', $d['buyer_post_code'])) $d['buyer_post_code'] = substr($d['buyer_post_code'], 0, 2) . '-' . substr($d['buyer_post_code'], 2);
        $err = [];
        foreach (self::invoiceDataGaps($d) as $k => $label) $err[$k] = 'Uzupełnij: ' . $label . '.';
        if ($d['buyer_tax_no'] !== '' && strlen($d['buyer_tax_no']) !== 10) $err['buyer_tax_no'] = 'NIP ma 10 cyfr (albo zostaw puste).';
        return [$d, $err];
    }

    /** Czego brakuje do faktury VAT (pole → etykieta). Pusto = komplet. */
    public static function invoiceDataGaps(array $b): array
    {
        $gaps = [];
        if (count(preg_split('/\s+/u', trim((string)($b['buyer_name'] ?? ''))) ?: []) < 2 && trim((string)($b['buyer_tax_no'] ?? '')) === '') $gaps['buyer_name'] = self::BUYER_FIELDS['buyer_name'];
        if (mb_strlen(trim((string)($b['buyer_street'] ?? ''))) < 3 || !preg_match('/\d/u', (string)($b['buyer_street'] ?? ''))) $gaps['buyer_street'] = self::BUYER_FIELDS['buyer_street'];
        if (!preg_match('/^\d{2}-\d{3}$/', trim((string)($b['buyer_post_code'] ?? '')))) $gaps['buyer_post_code'] = self::BUYER_FIELDS['buyer_post_code'];
        if (mb_strlen(trim((string)($b['buyer_city'] ?? ''))) < 2) $gaps['buyer_city'] = self::BUYER_FIELDS['buyer_city'];
        if (!filter_var(trim((string)($b['buyer_email'] ?? '')), FILTER_VALIDATE_EMAIL)) $gaps['buyer_email'] = self::BUYER_FIELDS['buyer_email'];
        return $gaps;
    }

    /** Zapis danych do faktury (uczestnik albo kadra) i od razu próba wystawienia zaległych faktur. */
    public static function saveBuyer(int $subId, array $in, string $by): array
    {
        [$d, $err] = self::buyerFromInput($in);
        if ($err) return $err;
        $sets = implode(', ', array_map(fn($k) => "$k=?", array_keys($d)));
        db_exec("UPDATE claude_subscriptions SET $sets, buyer_gap_alert_at=NULL, updated_at=datetime('now') WHERE id=?", [...array_values($d), $subId]);
        self::event($subId, null, 'buyer', 'Uzupełniono dane do faktury: ' . $d['buyer_name'] . ', ' . $d['buyer_street'] . ', ' . $d['buyer_post_code'] . ' ' . $d['buyer_city'] . ($d['buyer_tax_no'] !== '' ? ', NIP ' . $d['buyer_tax_no'] : '') . '.', $by);
        require_once __DIR__ . '/ClaudeInvoice.php';
        foreach (db_all("SELECT id FROM claude_charges WHERE subscription_id=? AND status='paid' AND invoice_id IS NULL", [$subId]) as $r) {
            try { ClaudeInvoice::issueForCharge((int)$r['id']); } catch (\Throwable $e) {}
        }
        return [];
    }

    /** „Daj znać”: brak danych do FV — e-mail do biura i do uczestnika (raz, do czasu uzupełnienia). */
    public static function alertBuyerGaps(array $sub, array $gaps, ?array $ch = null): void
    {
        if (!$gaps || (int)$sub['is_test'] || !empty($sub['buyer_gap_alert_at'])) return;
        db_exec("UPDATE claude_subscriptions SET buyer_gap_alert_at=datetime('now') WHERE id=?", [(int)$sub['id']]);
        $list = implode(', ', $gaps);
        self::event((int)$sub['id'], $ch ? (int)$ch['id'] : null, 'buyer_gaps', 'Brak danych do faktury VAT: ' . $list . '.', 'system');
        self::notifyOffice($sub, 'Brak danych do faktury VAT — ' . $sub['client_name'], '<p>Uczestnik <b>' . h((string)$sub['client_name']) . '</b> (abonament Claude #' . (int)$sub['id'] . ') nie ma kompletnych danych do faktury VAT. Brakuje: <b>' . h($list) . '</b>.'
            . ($ch ? ' Faktura za ' . h(self::periodLabel((string)$ch['period'])) . ' (' . self::zl((float)$ch['amount_pln']) . ', opłacone) czeka — wystawi się sama po uzupełnieniu danych.' : '') . '</p>');
        self::notifyParticipant($sub, 'dane_fv', $ch, $list);
    }

    // ── Abonament ───────────────────────────────────────────────────────────────

    public static function subscription(int $id): ?array
    {
        self::migrate();
        return db_one("SELECT s.*, c.name AS client_name, c.email AS client_email FROM claude_subscriptions s LEFT JOIN k30_clients c ON c.id=s.client_id WHERE s.id=?", [$id]) ?: null;
    }

    /** Bieżący (aktywny / wstrzymany) albo ostatni abonament osoby. */
    public static function forClient(int $clientId, bool $test = false): ?array
    {
        self::migrate();
        return db_one("SELECT * FROM claude_subscriptions WHERE client_id=? AND is_test=? ORDER BY CASE WHEN status IN ('active','suspended') THEN 0 ELSE 1 END, id DESC LIMIT 1",
            [$clientId, $test ? 1 : 0]) ?: null;
    }

    /** Czy uczestnik może aktywować dostęp. @return array{ok:bool,reason:string,assignment:?array} */
    public static function eligibility(int $clientId): array
    {
        self::migrate();
        $s = self::settings();
        require_once dirname(__DIR__, 2) . '/ti_licencje/logic/tiClaudeStatement.php';
        $a = TiClaudeStatement::claudeAssignment($clientId);
        $open = db_one("SELECT id FROM claude_subscriptions WHERE client_id=? AND status IN ('active','suspended') AND is_test=0", [$clientId]);
        if ($open)            return ['ok' => false, 'reason' => 'Dostęp do Claude jest już aktywny.', 'assignment' => $a];
        if (!$s['enabled'])   return ['ok' => false, 'reason' => 'Aktywacja dostępu do Claude jest chwilowo wyłączona.', 'assignment' => $a];
        if (!$a)              return ['ok' => false, 'reason' => 'Nie masz przypisanej licencji Claude — poproś prowadzącego albo biuro o wniosek ANT1.', 'assignment' => null];
        if (self::isMinor($clientId)) return ['ok' => false, 'reason' => 'Dostęp osoby niepełnoletniej aktywuje biuro na wniosek opiekuna.', 'assignment' => $a];
        $debt = db_one("SELECT COUNT(*) n FROM claude_charges WHERE client_id=? AND status='pending' AND is_test=0", [$clientId]);
        if ((int)($debt['n'] ?? 0) > 0) return ['ok' => false, 'reason' => 'Masz nieopłacone należności za Claude z poprzedniego okresu — ureguluj je w portalu płatności.', 'assignment' => $a];
        return ['ok' => true, 'reason' => '', 'assignment' => $a];
    }

    /**
     * Aktywacja: abonament + pierwsze naliczenie (termin = dzień aktywacji) + zgłoszenie Helpdesk „aktywacja”.
     * $actor: ['name' => kto, 'via' => 'panel uczestnika'|'panel dydaktyka'|'test', 'ip' => …]. Gdy aktywuje kadra — $checkEligibility=false pomija licencję.
     */
    public static function activate(int $clientId, array $actor, bool $test = false, ?string $date = null, bool $checkEligibility = true, ?int $dueDay = null, ?array $buyer = null): array
    {
        self::migrate();
        $s = self::settings();
        if (!self::client($clientId)) throw new DomainException('Nie ma takiego uczestnika.');
        $assignment = null;
        if (!$test && $checkEligibility) {
            $el = self::eligibility($clientId);
            if (!$el['ok']) throw new DomainException($el['reason']);
            $assignment = $el['assignment'];
        } elseif (db_one("SELECT 1 FROM claude_subscriptions WHERE client_id=? AND status IN ('active','suspended')", [$clientId])) {
            throw new DomainException('Ta osoba ma już aktywny abonament Claude.');
        }
        $date = $date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : date('Y-m-d');
        $free = false;
        if (!$test) { require_once dirname(__DIR__, 3) . '/includes/ti_virtual.php'; $free = ti_client_no_billing($clientId); }
        if (!$free) self::quote();   // bez kursu (PKO BP nie odpowiada i brak zapisanego) — aktywacja nie powstaje w połowie
        $b = self::buyerDefaults($clientId);
        if ($buyer !== null) { [$bd] = self::buyerFromInput($buyer + $b); $b = $bd; }
        $id = db_insert('claude_subscriptions', $b + [
            'client_id' => $clientId, 'status' => 'active', 'activated_on' => $date, 'cycle_start' => $date,
            'due_day' => $dueDay && $dueDay >= 1 && $dueDay <= 28 ? $dueDay : null, 'free' => $free ? 1 : 0,
            'assignment_id' => $assignment['id'] ?? null, 'terms_text' => self::termsText($s), 'terms_accepted_at' => date('Y-m-d H:i:s'),
            'terms_ip' => (string)($actor['ip'] ?? ''), 'activated_by' => trim((string)($actor['name'] ?? '') . ' (' . ($actor['via'] ?? '') . ')'),
            'is_test' => $test ? 1 : 0,
        ]);
        self::event($id, null, 'activated', 'Aktywowano dostęp do Claude od ' . self::date($date) . ($free ? ' — bez opłat (uczestnik „bez rozliczeń”)' : '') . '. Warunki zaakceptowane: ' . date('d.m.Y H:i') . ($actor['ip'] ?? '' ? ', IP ' . $actor['ip'] : '') . '.', (string)($actor['name'] ?? ''));
        $sub = self::subscription($id);
        if ($gaps = self::invoiceDataGaps($sub)) self::alertBuyerGaps($sub, $gaps);
        $charge = null;
        if (!$free) $charge = self::createCharge($sub, substr($date, 0, 7), $date);
        self::helpdesk($sub, 'aktywacja', $actor, $charge);
        self::notifyOffice($sub, 'Aktywacja dostępu do Claude', '<p><b>' . h((string)$sub['client_name']) . '</b> aktywował(a) dostęp do Claude od ' . self::date($date) . '.'
            . ($charge ? ' Pierwsza opłata: <b>' . self::zl((float)$charge['amount_pln']) . '</b> do ' . self::date($charge['due_on']) . '.' : '') . '</p>');
        return self::subscription($id);
    }

    /** Rezygnacja (uczestnik) albo zakończenie (kadra): bez nowych naliczeń, przyszłe nieopłacone anulowane, zgłoszenie dezaktywacji. */
    public static function end(int $subId, string $reason, array $actor): void
    {
        $sub = self::subscription($subId);
        if (!$sub || $sub['status'] === 'ended') throw new DomainException('Abonament jest już zakończony.');
        $today = date('Y-m-d');
        db_exec("UPDATE claude_subscriptions SET status='ended', ended_on=?, ended_reason=?, updated_at=datetime('now') WHERE id=?", [$today, mb_substr($reason, 0, 300), $subId]);
        foreach (db_all("SELECT * FROM claude_charges WHERE subscription_id=? AND status='pending' AND due_on>?", [$subId, $today]) as $c) {
            try { self::cancelCharge((int)$c['id'], ['name' => (string)($actor['name'] ?? '')], 'rezygnacja przed terminem'); } catch (\Throwable $e) {}
        }
        self::event($subId, null, 'ended', 'Zakończono abonament: ' . $reason, (string)($actor['name'] ?? ''));
        $sub = self::subscription($subId);
        self::helpdesk($sub, 'dezaktywacja', $actor, null, $reason);
        self::notifyOffice($sub, 'Rezygnacja z dostępu do Claude', '<p><b>' . h((string)$sub['client_name']) . '</b>: ' . h($reason) . '. Opłacono do: ' . self::date(self::paidThrough($sub)) . '.</p>');
    }

    /** Ostatni dzień opłaconego okresu (dzień przed terminem kolejnego okresu po ostatnim opłaconym) albo null. */
    public static function paidThrough(array $sub): ?string
    {
        $last = db_one("SELECT period FROM claude_charges WHERE subscription_id=? AND status='paid' ORDER BY period DESC LIMIT 1", [(int)$sub['id']]);
        if (!$last) return null;
        $next = date('Y-m', strtotime($last['period'] . '-01 +1 month'));
        return date('Y-m-d', strtotime(self::billingDate($sub, $next) . ' -1 day'));
    }

    public static function setDueDay(int $subId, ?int $day, array $actor): void
    {
        if ($day !== null && ($day < 1 || $day > 28)) throw new InvalidArgumentException('Dzień graniczny: 1–28 (puste = ustawienie ogólne).');
        db_exec("UPDATE claude_subscriptions SET due_day=?, updated_at=datetime('now') WHERE id=?", [$day, $subId]);
        self::event($subId, null, 'due_day', $day ? 'Indywidualny dzień graniczny płatności: ' . $day . '.' : 'Dzień graniczny płatności — wg ustawienia ogólnego.', (string)($actor['name'] ?? ''));
    }

    // ── Naliczenia ──────────────────────────────────────────────────────────────

    public static function charge(int $id): ?array
    {
        self::migrate();
        return db_one("SELECT ch.*, c.name AS client_name, c.email AS client_email FROM claude_charges ch LEFT JOIN k30_clients c ON c.id=ch.client_id WHERE ch.id=?", [$id]) ?: null;
    }

    public static function chargeTitle(array $ch): string
    {
        return ((int)$ch['is_test'] ? '[TEST] ' : '') . 'Dostęp do Claude (Claude for Nonprofit) — ' . self::periodLabel((string)$ch['period'])
            . ' — ' . self::usd((float)$ch['usd']) . ' × ' . self::rateFmt((float)$ch['rate']) . ' (PKO BP)';
    }

    /** Naliczenie za okres — kurs z PKO BP z dnia naliczenia; pozycja w portalu płatności. Musi działać POZA transakcją (pp_add_item ma własną). */
    public static function createCharge(array $sub, string $period, ?string $today = null): ?array
    {
        if ((int)$sub['free']) return null;
        if ($ex = db_one("SELECT id FROM claude_charges WHERE subscription_id=? AND period=?", [(int)$sub['id'], $period])) return self::charge((int)$ex['id']);
        $s = self::settings();
        $today ??= date('Y-m-d');
        $q = self::quote(min($today, date('Y-m-d')));
        $due = self::dueFor($sub, $period, $s);
        $id = db_insert('claude_charges', [
            'subscription_id' => (int)$sub['id'], 'client_id' => (int)$sub['client_id'], 'period' => $period, 'due_on' => $due, 'issue_on' => self::billingDate($sub, $period, $s),
            'usd' => $q['usd'], 'list_usd' => $q['list_usd'], 'rate' => $q['rate'], 'rate_kind' => $q['kind'], 'rate_table' => (string)$q['table_number'],
            'rate_at' => (string)$q['table_at'], 'amount_pln' => $q['amount_pln'], 'list_pln' => $q['list_pln'], 'status' => 'pending', 'is_test' => (int)$sub['is_test'],
            'note' => $q['stale'] ? 'kurs: ' . $q['source'] : '',
        ]);
        $ch = self::charge($id);
        require_once dirname(__DIR__, 2) . '/payment_portal/logic/paymentPortal.php';
        $item = pp_add_item((int)$sub['client_id'], self::chargeTitle($ch), (float)$ch['amount_pln'], 'manual', null, $due, 'Abonament Claude', null);
        if (is_int($item)) db_exec("UPDATE claude_charges SET pp_item_id=? WHERE id=?", [$item, $id]);
        else db_exec("UPDATE claude_charges SET note=? WHERE id=?", [trim($ch['note'] . ' Portal płatności: ' . $item), $id]);
        self::event((int)$sub['id'], $id, 'charge', 'Naliczenie za ' . self::periodLabel($period) . ': ' . self::usd($q['usd']) . ' × ' . self::rateFmt($q['rate'])
            . ' (' . $q['kind_label'] . ', tabela PKO BP ' . PkoRates::tableLabel($q) . ') = ' . self::zl($q['amount_pln']) . ', termin ' . self::date($due) . '.', 'system');
        if ($s['invoice'] === 'auto' || (int)$sub['is_test']) {   // faktura VAT (test: proforma) w Fakturowni od razu — z terminem 5 dni i rabatem
            require_once __DIR__ . '/ClaudeInvoice.php';
            try { ClaudeInvoice::issueForCharge($id); } catch (\Throwable $e) { db_exec("UPDATE claude_charges SET invoice_error=? WHERE id=?", [mb_substr($e->getMessage(), 0, 500), $id]); }
        }
        $ch = self::charge($id);
        self::notifyParticipant($sub, 'naliczenie', $ch);
        return $ch;
    }

    public static function cancelCharge(int $id, array $actor, string $reason = ''): void
    {
        $ch = self::charge($id);
        if (!$ch) throw new DomainException('Nie ma takiego naliczenia.');
        if ($ch['status'] !== 'pending') throw new DomainException('Anulować można tylko naliczenie „do zapłaty”.');
        if ($ch['pp_item_id']) {
            require_once dirname(__DIR__, 2) . '/payment_portal/logic/paymentPortal.php';
            $it = db_one("SELECT status FROM payable_items WHERE id=?", [(int)$ch['pp_item_id']]);
            if ($it && $it['status'] === 'processing') throw new DomainException('Trwa płatność tej pozycji w portalu — poczekaj na jej wynik.');
            if ($it && $it['status'] === 'pending' && ($err = pp_cancel_item((int)$ch['pp_item_id'], (string)($actor['name'] ?? 'Claude'), null))) throw new DomainException($err);
        }
        db_exec("UPDATE claude_charges SET status='cancelled', note=TRIM(note || ' ' || ?) WHERE id=?", ['Anulowano' . ($reason !== '' ? ': ' . $reason : '') . '.', $id]);
        self::event((int)$ch['subscription_id'], $id, 'cancelled', 'Anulowano naliczenie za ' . self::periodLabel($ch['period']) . ($reason !== '' ? ' — ' . $reason : '') . '.', (string)($actor['name'] ?? ''));
    }

    /** Ręczne potwierdzenie wpłaty (gotówka, przelew poza portalem) — pozycja portalu też opłacona. */
    public static function markPaid(int $id, string $via, string $ref, array $actor): void
    {
        $ch = self::charge($id);
        if (!$ch || $ch['status'] !== 'pending') throw new DomainException('Naliczenie nie czeka na wpłatę.');
        if ($ch['pp_item_id']) {
            $it = db_one("SELECT status FROM payable_items WHERE id=?", [(int)$ch['pp_item_id']]);
            if ($it && $it['status'] === 'processing') throw new DomainException('Trwa płatność tej pozycji w portalu — poczekaj na jej wynik.');
            db_exec("UPDATE payable_items SET status='paid', paid_at=datetime('now') WHERE id=? AND status='pending'", [(int)$ch['pp_item_id']]);
        }
        self::settleCharges([$id], $via, $ref, (string)($actor['name'] ?? ''));
        self::afterPayment();
    }

    /**
     * Płatność Przelewy24 bezpośrednio za naliczenie (panel /licencje) — źródło p24 'claude_charge', księgowanie w onP24Paid() z webhooka
     * (includes/p24.php → p24_mark_paid po transaction/verify). Zwraca adres bramki.
     */
    public static function startP24(int $chargeId, string $email, string $returnUrl): string
    {
        require_once dirname(__DIR__, 3) . '/includes/p24.php';
        $ch = self::charge($chargeId);
        if (!$ch || $ch['status'] !== 'pending') throw new DomainException('To naliczenie nie czeka na wpłatę.');
        if (!p24_enabled()) throw new DomainException('Płatność Przelewy24 jest chwilowo niedostępna — zapłać przelewem na numer konta.');
        if ($ch['pp_item_id'] && ($it = db_one("SELECT status FROM payable_items WHERE id=?", [(int)$ch['pp_item_id']])) && $it['status'] === 'processing') {
            throw new DomainException('Płatność za ten miesiąc jest już w toku w portalu płatności — poczekaj chwilę na jej wynik.');
        }
        $base = defined('APP_URL') ? rtrim((string)APP_URL, '/') : '';
        $o = p24_create_order('claude_charge', $chargeId, (float)$ch['amount_pln'], self::chargeTitle($ch), $returnUrl, $base . '/api/p24_webhook.php',
                              filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : (string)($ch['client_email'] ?? ''));
        self::event((int)$ch['subscription_id'], $chargeId, 'p24_start', 'Rozpoczęto płatność Przelewy24 (' . self::zl((float)$ch['amount_pln']) . ').', (string)($ch['client_name'] ?? ''));
        return (string)$o['url'];
    }

    /** Z includes/p24.php (p24_mark_paid, po weryfikacji): kwota musi się zgadzać z naliczeniem; pozycja portalu też opłacona. */
    public static function onP24Paid(int $chargeId, array $p24row): void
    {
        self::migrate();
        $ch = self::charge($chargeId);
        if (!$ch || $ch['status'] !== 'pending') return;
        if ((int)$p24row['amount_grosze'] !== (int)round((float)$ch['amount_pln'] * 100)) {
            self::event((int)$ch['subscription_id'], $chargeId, 'p24_mismatch', 'Przelewy24: kwota ' . ((int)$p24row['amount_grosze'] / 100) . ' zł ≠ naliczenie ' . $ch['amount_pln'] . ' zł — nie zaksięgowano, sprawdź ręcznie.', 'Przelewy24');
            return;
        }
        if ($ch['pp_item_id']) db_exec("UPDATE payable_items SET status='paid', paid_at=datetime('now') WHERE id=? AND status='pending'", [(int)$ch['pp_item_id']]);
        self::settleCharges([$chargeId], 'Przelewy24', 'P24 ' . (string)($p24row['order_id'] ?? $p24row['id'] ?? ''), 'Przelewy24');
        self::afterPayment();
    }

    /** Wywoływane z pp_settle() (portal płatności) wewnątrz jego transakcji: naliczenia → opłacone; faktury i Helpdesk po zakończeniu żądania. */
    public static function onPortalSettled(int $txId): void
    {
        self::migrate();
        $tx = db_one("SELECT transaction_uuid, payment_method FROM portal_transactions WHERE id=?", [$txId]);
        if (!$tx) return;
        $ids = array_map(fn($r) => (int)$r['id'], db_all(
            "SELECT ch.id FROM claude_charges ch JOIN portal_transaction_items ti ON ti.payable_item_id = ch.pp_item_id WHERE ti.portal_transaction_id=? AND ch.status='pending'", [$txId]));
        if (!$ids) return;
        self::settleCharges($ids, $tx['payment_method'] === 'p24' ? 'Przelewy24' : 'przelew', 'portal ' . substr((string)$tx['transaction_uuid'], 0, 8), 'portal płatności');
        if (!self::$shutdownSet) { self::$shutdownSet = true; register_shutdown_function([self::class, 'afterPayment']); }
    }

    private static function settleCharges(array $ids, string $via, string $ref, string $by): void
    {
        foreach ($ids as $id) {
            db_exec("UPDATE claude_charges SET status='paid', paid_at=datetime('now','localtime'), paid_via=?, paid_ref=? WHERE id=? AND status='pending'", [$via, $ref, $id]);
            $ch = db_one("SELECT subscription_id, period, amount_pln FROM claude_charges WHERE id=?", [$id]);
            self::event((int)$ch['subscription_id'], $id, 'paid', 'Wpłata za ' . self::periodLabel($ch['period']) . ': ' . self::zl((float)$ch['amount_pln']) . ' (' . $via . ($ref !== '' ? ', ' . $ref : '') . ').', $by);
            self::$afterPay[$id] = true;
        }
    }

    /** Po wpłacie (poza transakcją): faktura, wznowienie wstrzymanego dostępu, potwierdzenie dla uczestnika. */
    public static function afterPayment(): void
    {
        $ids = array_keys(self::$afterPay);
        self::$afterPay = [];
        $subs = [];
        foreach ($ids as $id) {
            try {
                require_once __DIR__ . '/ClaudeInvoice.php';
                ClaudeInvoice::afterPaid($id);
            } catch (\Throwable $e) {
                db_exec("UPDATE claude_charges SET invoice_error=? WHERE id=?", [mb_substr($e->getMessage(), 0, 500), $id]);
            }
            $ch = self::charge($id);
            if ($ch) { $subs[(int)$ch['subscription_id']] = true; self::notifyParticipant(self::subscription((int)$ch['subscription_id']) ?? [], 'wplata', $ch); }
        }
        foreach (array_keys($subs) as $sid) {
            try { self::maybeResume($sid, date('Y-m-d')); } catch (\Throwable $e) { error_log('claude resume: ' . $e->getMessage()); }
        }
    }

    // ── Pilnowanie płatności (CRON) ─────────────────────────────────────────────

    /** Zaległe naliczenia, po których dostęp się wstrzymuje (termin + karencja minęły). */
    public static function overdue(array $sub, string $today, ?array $s = null): array
    {
        $s ??= self::settings();
        $limit = date('Y-m-d', strtotime($today . ' -' . $s['grace_days'] . ' day'));
        return db_all("SELECT * FROM claude_charges WHERE subscription_id=? AND status='pending' AND due_on < ?", [(int)$sub['id'], $limit]);
    }

    private static function maybeResume(int $subId, string $today): bool
    {
        $sub = self::subscription($subId);
        if (!$sub || $sub['status'] !== 'suspended' || self::overdue($sub, $today)) return false;
        db_exec("UPDATE claude_subscriptions SET status='active', suspended_at=NULL, cycle_start=?, updated_at=datetime('now') WHERE id=?", [$today, $subId]);
        self::event($subId, null, 'resumed', 'Zaległość uregulowana — dostęp wznowiony od ' . self::date($today) . ' (nowy cykl rozliczeń od tego dnia).', 'system');
        $sub = self::subscription($subId);
        self::helpdesk($sub, 'przywrocenie', ['name' => 'system', 'via' => 'płatności'], null);
        self::notifyParticipant($sub, 'wznowienie', null);
        return true;
    }

    /**
     * Przebieg dzienny: naliczenia (N dni przed terminem), przypomnienia w dniu terminu, wstrzymanie po karencji, wznowienie po wpłacie.
     * $today pozwala symulować datę (strona testu); $onlyTest — tylko abonamenty testowe. @return array{charges:int,reminders:int,suspended:int,resumed:int,errors:list<string>,log:list<string>}
     */
    public static function run(?string $today = null, bool $onlyTest = false, ?int $onlySub = null): array
    {
        self::migrate();
        $s = self::settings();
        $today ??= date('Y-m-d');
        $rep = ['charges' => 0, 'reminders' => 0, 'suspended' => 0, 'resumed' => 0, 'errors' => [], 'log' => []];
        $w = ["status IN ('active','suspended')"]; $p = [];
        if ($onlyTest) $w[] = 'is_test=1';
        if ($onlySub) { $w[] = 'id=?'; $p[] = $onlySub; }
        foreach (db_all("SELECT id FROM claude_subscriptions WHERE " . implode(' AND ', $w) . " ORDER BY id", $p) as $row) {
            $sid = (int)$row['id'];
            try {
                $sub = self::subscription($sid);
                if ($sub['status'] === 'suspended') {
                    if (self::maybeResume($sid, $today)) { $rep['resumed']++; $rep['log'][] = $sub['client_name'] . ': wznowiono'; }
                    continue;
                }
                // naliczenia: od miesiąca startu cyklu do miesiąca (dziś + wyprzedzenie)
                if (!(int)$sub['free']) {
                    $p0 = substr((string)$sub['cycle_start'], 0, 7);
                    $pEnd = substr($today, 0, 7);
                    for ($per = $p0; $per <= $pEnd; $per = date('Y-m', strtotime($per . '-01 +1 month'))) {
                        $due = self::dueFor($sub, $per, $s);
                        if (self::billingDate($sub, $per, $s) > $today) continue;
                        if (db_one("SELECT 1 FROM claude_charges WHERE subscription_id=? AND period=?", [$sid, $per])) continue;
                        $ch = self::createCharge($sub, $per, $today);
                        if ($ch) { $rep['charges']++; $rep['log'][] = $sub['client_name'] . ': naliczenie ' . self::periodLabel($per) . ' ' . self::zl((float)$ch['amount_pln']) . ' do ' . self::date($due); }
                    }
                }
                // wpłaty Szybkim przelewem (albo oznaczone w Fakturowni) — faktura „opłacona” zamyka naliczenie
                require_once __DIR__ . '/ClaudeInvoice.php';
                foreach (db_all("SELECT id FROM claude_charges WHERE subscription_id=? AND status='pending' AND invoice_id IS NOT NULL", [$sid]) as $r) {
                    if (!ClaudeInvoice::paidInFakturownia((int)$r['id'])) continue;
                    $ch = self::charge((int)$r['id']);
                    if ($ch['pp_item_id']) {
                        $it = db_one("SELECT status FROM payable_items WHERE id=?", [(int)$ch['pp_item_id']]);
                        if ($it && $it['status'] === 'processing') continue;   // równolegle trwa płatność w portalu — rozstrzygnie się w kolejnym przebiegu
                        db_exec("UPDATE payable_items SET status='paid', paid_at=datetime('now') WHERE id=? AND status='pending'", [(int)$ch['pp_item_id']]);
                    }
                    self::settleCharges([(int)$r['id']], 'Szybki przelew (Fakturownia)', 'faktura ' . $ch['invoice_number'], 'Fakturownia');
                    $rep['log'][] = $sub['client_name'] . ': wpłata w Fakturowni za ' . self::periodLabel($ch['period']);
                }
                self::afterPayment();
                $sub = self::subscription($sid);
                if ($sub['status'] !== 'active') continue;
                // przypomnienie: w dniu terminu albo po nim — raz
                foreach (db_all("SELECT * FROM claude_charges WHERE subscription_id=? AND status='pending' AND due_on<=? AND reminded_at IS NULL", [$sid, $today]) as $ch) {
                    db_exec("UPDATE claude_charges SET reminded_at=datetime('now') WHERE id=?", [(int)$ch['id']]);
                    self::notifyParticipant($sub, 'przypomnienie', self::charge((int)$ch['id']));
                    self::event($sid, (int)$ch['id'], 'reminder', 'Przypomnienie o płatności za ' . self::periodLabel($ch['period']) . ' (termin ' . self::date($ch['due_on']) . ').', 'system');
                    $rep['reminders']++;
                }
                // wstrzymanie po karencji
                if ($od = self::overdue($sub, $today, $s)) {
                    db_exec("UPDATE claude_subscriptions SET status='suspended', suspended_at=datetime('now'), updated_at=datetime('now') WHERE id=? AND status='active'", [$sid]);
                    $sum = array_sum(array_map(fn($c) => (float)$c['amount_pln'], $od));
                    $txt = 'Brak wpłaty ' . self::zl($sum) . ' (najstarszy termin ' . self::date($od[0]['due_on']) . ', karencja ' . $s['grace_days'] . ' dni) — dostęp wstrzymany.';
                    self::event($sid, null, 'suspended', $txt, 'system');
                    $sub = self::subscription($sid);
                    self::helpdesk($sub, 'wstrzymanie', ['name' => 'system', 'via' => 'płatności'], null, $txt);
                    self::notifyParticipant($sub, 'wstrzymanie', $od[0]);
                    self::notifyOffice($sub, 'Wstrzymano dostęp do Claude (zaległość)', '<p><b>' . h((string)$sub['client_name']) . '</b>: ' . h($txt) . '</p>');
                    $rep['suspended']++; $rep['log'][] = $sub['client_name'] . ': wstrzymano';
                }
            } catch (\Throwable $e) {
                $rep['errors'][] = '#' . $sid . ': ' . $e->getMessage();
            }
        }
        // faktury, których nie udało się wystawić od razu (np. Fakturownia nie odpowiadała)
        if (!$onlyTest && $s['invoice'] === 'auto') {
            require_once __DIR__ . '/ClaudeInvoice.php';
            foreach (db_all("SELECT id FROM claude_charges WHERE status IN ('pending','paid') AND invoice_id IS NULL AND is_test=0 AND created_at >= date('now','-60 day') LIMIT 20") as $r) {
                try { ClaudeInvoice::issueForCharge((int)$r['id']); } catch (\Throwable $e) { $rep['errors'][] = 'faktura #' . $r['id'] . ': ' . $e->getMessage(); }
            }
        }
        return $rep;
    }

    // ── Helpdesk i powiadomienia ────────────────────────────────────────────────

    /** Zgłoszenie dla IT: aktywacja / dezaktywacja (rezygnacja) / wstrzymanie (zaległość) / przywrócenie. */
    public static function helpdesk(array $sub, string $kind, array $actor, ?array $charge = null, string $why = ''): ?string
    {
        $s = self::settings();
        if (!$s['helpdesk'] && !(int)$sub['is_test']) return null;
        $titles = ['aktywacja' => 'Aktywacja dostępu do Claude', 'dezaktywacja' => 'Dezaktywacja dostępu do Claude (rezygnacja)',
                   'wstrzymanie' => 'Wstrzymanie dostępu do Claude (zaległość)', 'przywrocenie' => 'Przywrócenie dostępu do Claude (wpłata)'];
        $lic = null;
        try { require_once dirname(__DIR__, 2) . '/ti_licencje/logic/tiClaudeStatement.php'; $lic = TiClaudeStatement::claudeAssignment((int)$sub['client_id']); } catch (\Throwable $e) {}
        $acc = db_one("SELECT login, ms_upn FROM k30_ti_student_accounts WHERE client_id=? ORDER BY id DESC LIMIT 1", [(int)$sub['client_id']]) ?: [];
        $title = ((int)$sub['is_test'] ? '[TEST] ' : '') . $titles[$kind] . ': ' . $sub['client_name'];
        $desc = $titles[$kind] . "\nUczestnik: " . $sub['client_name'] . ' (id ' . $sub['client_id'] . ')'
            . ($sub['client_email'] ? "\nE-mail: " . $sub['client_email'] : '')
            . (!empty($acc['ms_upn']) ? "\nKonto edu: " . $acc['ms_upn'] : (!empty($acc['login']) ? "\nLogin: " . $acc['login'] : ''))
            . ($lic ? "\nLicencja: " . $lic['license_name'] . ($lic['expires_at'] ? ' (do ' . self::date($lic['expires_at']) . ')' : '') : '')
            . "\nAbonament: #" . $sub['id'] . ', start ' . self::date($sub['activated_on']) . ', dzień graniczny płatności ' . self::dueDay($sub, $s)
            . ($charge ? "\nPierwsza opłata: " . self::zl((float)$charge['amount_pln']) . ' do ' . self::date($charge['due_on']) . ' (portal płatności / Przelewy24)' : '')
            . ($kind === 'dezaktywacja' ? "\nOpłacono do: " . self::date(self::paidThrough($sub)) . ' — wyłącz miejsce po tej dacie.' : '')
            . ($kind === 'wstrzymanie' ? "\nWyłącz miejsce Claude do czasu wpłaty — system sam założy zgłoszenie przywrócenia." : '')
            . ($why !== '' ? "\nPowód: " . $why : '')
            . "\nZgłosił: " . ($actor['name'] ?? '') . ' (' . ($actor['via'] ?? '') . ')';
        if ((int)$sub['is_test'] && !self::$testRealTickets) {
            self::$testTickets[] = ['title' => $title, 'desc' => $desc];
            self::event((int)$sub['id'], null, 'helpdesk', 'Zgłoszenie (test — nie utworzono): ' . $title, 'test');
            return null;
        }
        try {
            require_once dirname(__DIR__, 3) . '/includes/helpdesk.php';
            // aktywacja / rezygnacja — zgłasza uczestnik (dostaje potwierdzenia Helpdesku); wstrzymanie / przywrócenie — system płatności (bez maili do uczestnika)
            $sys = ($actor['via'] ?? '') === 'płatności';
            $tid = hd_ticket_quick_create(['id' => null, 'name' => $sys ? 'Abonament Claude (system płatności)' : (string)$sub['client_name'], 'email' => $sys ? '' : (string)($sub['client_email'] ?? '')],
                $title, $desc, 'it_licencje_am', 'normalny', 'claude_' . $kind);
            try {
                require_once dirname(__DIR__, 2) . '/helpdeskStatuses/lib/helpdeskStatuses.php'; hdSubstatusSet($tid, 'am_do_realizacji', 0, 'Abonament Claude');
                require_once dirname(__DIR__, 2) . '/helpdeskCollab/lib/helpdeskCollab.php'; hdTagAdd($tid, 'claude'); hdTagAdd($tid, 'claude_' . $kind);
            } catch (\Throwable $e) {}
            $num = (string)(db_one("SELECT number FROM helpdesk_tickets WHERE id=?", [$tid])['number'] ?? '');
            if ($kind === 'aktywacja') db_exec("UPDATE claude_subscriptions SET helpdesk_number=? WHERE id=?", [$num, (int)$sub['id']]);
            self::event((int)$sub['id'], null, 'helpdesk', 'Zgłoszenie Helpdesk ' . $num . ': ' . $titles[$kind] . '.', (string)($actor['name'] ?? ''));
            if ((int)$sub['is_test']) self::$testTickets[] = ['title' => $title . ' — utworzono ' . $num, 'desc' => $desc];
            return $num;
        } catch (\Throwable $e) {
            self::event((int)$sub['id'], null, 'helpdesk_error', 'Nie utworzono zgłoszenia Helpdesk (' . $e->getMessage() . ') — biuro dostało treść.', 'system');
            self::notifyOffice($sub, 'Zgłoszenie IT do założenia ręcznie: ' . $titles[$kind], '<pre style="white-space:pre-wrap">' . h($desc) . '</pre>');
            return null;
        }
    }

    private static function notifyOffice(array $sub, string $subject, string $html): void
    {
        if ((int)($sub['is_test'] ?? 0)) return;
        try {
            require_once dirname(__DIR__, 3) . '/includes/mail_queue.php';
            require_once dirname(__DIR__, 2) . '/ti_licencje_dydaktyczne/lib/LicenseNotificationService.php';
            foreach (LicenseNotificationService::officeRecipients() as $to) mail_queue_add($to, 'Biuro', '[Claude] ' . $subject, $html . self::panelLink(), '', 'claude_billing', (int)$sub['id']);
        } catch (\Throwable $e) { error_log('claude office: ' . $e->getMessage()); }
    }

    private static function panelLink(): string
    {
        $base = defined('APP_URL') ? rtrim((string)APP_URL, '/') : '';
        return '<p><a href="' . h($base . '/karty30/ti/dydaktyk/claudeAbonament.php') . '">Panel dydaktyka › Abonament Claude</a></p>';
    }

    /** E-mail do uczestnika (naliczenie / przypomnienie / wpłata / wstrzymanie / wznowienie). Bez testów, wirtualnych i małoletnich (tych prowadzi biuro). */
    public static function notifyParticipant(array $sub, string $kind, ?array $ch, string $extra = ''): void
    {
        if (!$sub || (int)($sub['is_test'] ?? 0) || !self::settings()['notify']) return;
        $c = self::client((int)$sub['client_id']);
        $to = trim((string)($c['email'] ?? ''));
        if (!$c || !filter_var($to, FILTER_VALIDATE_EMAIL)) return;
        $base = defined('APP_URL') ? rtrim((string)APP_URL, '/') : '';
        $url = $base . '/karty30/ti/kursant/index.php?tab=licencje#claude-abonament';
        $amt = $ch ? '<b>' . self::zl((float)$ch['amount_pln']) . '</b> (' . self::usd((float)$ch['usd']) . ' × ' . self::rateFmt((float)$ch['rate']) . ' — kurs PKO BP ' . h($ch['rate_table']) . ')' : '';
        $acc = self::payAccount((int)$sub['client_id']);
        $payInfo = $ch && in_array($kind, ['naliczenie', 'przypomnienie'], true)
            ? '<p>Jak zapłacić:</p><ul><li>portal płatności SZO — Przelewy24 albo przelew (przycisk poniżej),</li>'
              . (!empty($ch['invoice_view_url']) ? '<li>Szybki przelew z faktury w Fakturowni (BLIK, szybki przelew): <a href="' . h((string)$ch['invoice_view_url']) . '">faktura ' . h((string)$ch['invoice_number']) . '</a>,</li>' : '')
              . ($acc ? '<li>przelew na Twój indywidualny rachunek: <b>' . h($acc['nrb_fmt']) . '</b>, tytuł: <b>' . h(self::transferTitle($ch)) . '</b>.</li>' : '') . '</ul>'
            : '';
        $per = $ch ? self::periodLabel((string)$ch['period']) : '';
        [$subject, $body] = match ($kind) {
            'naliczenie'    => ['Opłata za dostęp do Claude — ' . $per, '<p>Naliczyliśmy opłatę za dostęp do Claude za ' . h($per) . ': ' . $amt . '. Termin płatności: <b>' . self::date($ch['due_on'] ?? null) . '</b>.</p>'],
            'przypomnienie' => ['Przypomnienie: opłata za dostęp do Claude — ' . $per, '<p>Nie odnotowaliśmy jeszcze opłaty za dostęp do Claude za ' . h($per) . ': ' . $amt . ', termin ' . self::date($ch['due_on'] ?? null) . '. Brak wpłaty wstrzyma dostęp.</p>'],
            'wplata'        => ['Dziękujemy — opłata za Claude zaksięgowana', '<p>Zaksięgowaliśmy opłatę za dostęp do Claude za ' . h($per) . ': ' . $amt . '.' . (!empty($ch['invoice_number']) ? ' Faktura: ' . h($ch['invoice_number']) . '.' : '') . '</p>'],
            'wstrzymanie'   => ['Dostęp do Claude wstrzymany — brak opłaty', '<p>Z powodu braku opłaty dostęp do Claude został wstrzymany. Po uregulowaniu należności przywrócimy go automatycznie.</p>'],
            'wznowienie'    => ['Dostęp do Claude przywrócony', '<p>Dziękujemy za wpłatę — dostęp do Claude zostanie przywrócony.</p>'],
            'dane_fv'       => ['Uzupełnij dane do faktury za Claude', '<p>Do wystawienia faktury VAT za dostęp do Claude brakuje: <b>' . h($extra) . '</b>. Uzupełnij je w panelu uczestnika (zakładka Licencje › Abonament Claude) — faktura wystawi się sama.</p>'],
            default         => ['', ''],
        };
        if ($subject === '') return;
        $html = '<p>Dzień dobry,</p>' . $body . $payInfo . '<p><a href="' . h($url) . '" style="background:#0f6fa8;color:#fff;padding:10px 22px;text-decoration:none;display:inline-block;font-weight:600">Panel uczestnika › Claude</a></p>';
        try {
            require_once dirname(__DIR__, 3) . '/includes/mail_queue.php';
            mail_queue_add($to, (string)$c['name'], $subject, $html, '', 'claude_billing', $ch ? (int)$ch['id'] : (int)$sub['id']);
        } catch (\Throwable $e) { error_log('claude notify: ' . $e->getMessage()); }
    }

    // ── Rachunek do przelewu ────────────────────────────────────────────────────

    /** Rachunek do wpłat za Claude (ustawienie; domyślnie 88 1020 2906 0000 1302 0661 7932) — na fakturze, w panelu i e-mailach. @return ?array{nrb:string,nrb_fmt:string,kind:string} */
    public static function payAccount(int $clientId = 0): ?array
    {
        $nrb = self::settings()['bank_account'];
        if (strlen($nrb) !== 26) return null;
        return ['nrb' => $nrb, 'nrb_fmt' => trim(substr($nrb, 0, 2) . ' ' . implode(' ', str_split(substr($nrb, 2), 4))), 'kind' => 'claude'];
    }

    /** Tytuł przelewu tradycyjnego dla naliczenia. */
    public static function transferTitle(array $ch): string
    {
        return 'CLAUDE ' . ($ch['invoice_number'] ?: ('NAL/' . (int)$ch['id'])) . ' ' . self::periodLabel((string)$ch['period']);
    }

    // ── Dane do widoków ─────────────────────────────────────────────────────────

    public static function charges(int $subId): array
    {
        return db_all("SELECT * FROM claude_charges WHERE subscription_id=? ORDER BY period DESC, id DESC", [$subId]);
    }

    public static function events(int $subId, int $limit = 30): array
    {
        return db_all("SELECT * FROM claude_events WHERE subscription_id=? ORDER BY id DESC LIMIT " . (int)$limit, [$subId]);
    }

    /** Lista abonamentów z podsumowaniem należności (panel kierownika). */
    public static function listAll(string $filter = '', bool $test = false): array
    {
        self::migrate();
        $w = ['s.is_test=?']; $p = [$test ? 1 : 0];
        if (isset(self::STATUS[$filter])) { $w[] = 's.status=?'; $p[] = $filter; }
        return db_all("SELECT s.*, c.name AS client_name, c.email AS client_email,
                (SELECT COUNT(*) FROM claude_charges x WHERE x.subscription_id=s.id AND x.status='pending') AS n_pending,
                (SELECT COALESCE(SUM(amount_pln),0) FROM claude_charges x WHERE x.subscription_id=s.id AND x.status='pending') AS sum_pending,
                (SELECT MIN(due_on) FROM claude_charges x WHERE x.subscription_id=s.id AND x.status='pending') AS next_due,
                (SELECT COUNT(*) FROM claude_charges x WHERE x.subscription_id=s.id AND x.status='paid') AS n_paid
              FROM claude_subscriptions s LEFT JOIN k30_clients c ON c.id=s.client_id
             WHERE " . implode(' AND ', $w) . " ORDER BY CASE s.status WHEN 'suspended' THEN 0 WHEN 'active' THEN 1 ELSE 2 END, c.name", $p);
    }

    public static function stats(): array
    {
        self::migrate();
        $r = db_one("SELECT SUM(status='active') a, SUM(status='suspended') s, SUM(status='ended') e FROM claude_subscriptions WHERE is_test=0") ?: [];
        $c = db_one("SELECT SUM(status='pending') np, COALESCE(SUM(CASE WHEN status='pending' THEN amount_pln END),0) sp,
                            SUM(status='pending' AND due_on < date('now')) no, COALESCE(SUM(CASE WHEN status='paid' AND paid_at >= date('now','start of month') THEN amount_pln END),0) pm,
                            SUM(status='paid' AND invoice_id IS NULL) ni
                       FROM claude_charges WHERE is_test=0") ?: [];
        $gaps = 0;
        foreach (db_all("SELECT * FROM claude_subscriptions WHERE is_test=0 AND status IN ('active','suspended')") as $sb) if (self::invoiceDataGaps($sb)) $gaps++;
        return ['buyer_gaps' => $gaps, 'active' => (int)($r['a'] ?? 0), 'suspended' => (int)($r['s'] ?? 0), 'ended' => (int)($r['e'] ?? 0), 'pending' => (int)($c['np'] ?? 0),
                'pending_sum' => (float)($c['sp'] ?? 0), 'overdue' => (int)($c['no'] ?? 0), 'paid_month' => (float)($c['pm'] ?? 0), 'no_invoice' => (int)($c['ni'] ?? 0)];
    }

    /** Dane do karty w panelu uczestnika. */
    public static function participantView(int $clientId): array
    {
        self::migrate();
        $s = self::settings();
        $sub = self::forClient($clientId);
        $el = self::eligibility($clientId);
        return ['settings' => $s, 'sub' => $sub, 'charges' => $sub ? self::charges((int)$sub['id']) : [], 'eligible' => $el,
                'hasLicense' => $el['assignment'] !== null, 'rate' => self::lastRate(), 'paidThrough' => $sub ? self::paidThrough($sub) : null,
                'account' => self::payAccount($clientId), 'gaps' => $sub ? self::invoiceDataGaps($sub) : []];
    }
}
