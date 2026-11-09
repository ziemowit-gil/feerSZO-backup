<?php
declare(strict_types=1);

/**
 * modules/claude_billing/logic/claudeBilling.php — płatne licencje uczestników: abonament Claude (Claude Team w programie Claude for Nonprofit)
 * i NordVPN — ten sam mechanizm, osobne ustawienia (prefiks claude_bill_ / nordvpn_bill_). Tabele claude_* są wspólne (kolumna product).
 *
 * Zasady (polecenia z 2026-11-09):
 *   • Uczestnik z przypisaną licencją produktu (moduł Licencje: nazwa/dostawca pasuje do produktu) sam aktywuje dostęp w panelu /licencje —
 *     podaje dane do faktury, akceptuje warunki; w Helpdesku IT powstaje zgłoszenie aktywacji. NordVPN: jedna licencja na osobę (sprawdzane po e-mailu).
 *   • Naliczenie (proforma w Fakturowni) w dniu aktywacji, potem zawsze 1. dnia miesiąca (ustawienie; wcześniej: dzień aktywacji, nie później niż 8.)
 *     (dzień graniczny zmienia kierownik — globalnie albo dla jednej osoby). Termin płatności: zawsze 5 dni.
 *   • Kwota (dynamicznie, z ustawień): cena w USD × ostatni kurs PKO BP (domyślnie sprzedaż dewiz) z dnia naliczenia + narzut % (domyślnie 2% „VAT”)
 *     + prowizja za płatność (domyślnie 1,23 zł, osobna pozycja faktury); narzut domyślnie 23% „VAT” (VAT od importu usługi — koszt po stronie uczestnika); Claude: 8 USD (cena non-profit, regularna 25 USD → rabat na fakturze).
 *   • Płatność: Przelewy24 (bezpośrednio z panelu albo w portalu /platnosci), przelew na rachunek 88 1020 2906 0000 1302 0661 7932, Szybki przelew
 *     z faktury w Fakturowni. Wpłata z każdej drogi zamyka naliczenie, faktura w Fakturowni dostaje status „opłacona”.
 *   • Pilnowanie: przypomnienie w dniu terminu, wstrzymanie po karencji (zgłoszenie Helpdesk), wznowienie po wpłacie; rezygnacja → zgłoszenie dezaktywacji.
 * E-maile do uczestników piszemy prostym językiem: ile, za co, do kiedy, jak zapłacić. Tryb testowy (is_test=1): bez e-maili i bez prawdziwych zgłoszeń.
 */
require_once dirname(__DIR__, 3) . '/includes/db.php';
require_once dirname(__DIR__, 3) . '/includes/functions.php';
require_once dirname(__DIR__) . '/lib/PkoRates.php';

final class ClaudeBilling
{
    /** Produkty. match — fragmenty nazwy/dostawcy licencji w module Licencje; uniqueEmail — jedna licencja na adres e-mail. */
    public const PRODUCTS = [
        'claude'  => ['label' => 'Claude', 'name' => 'Claude Team (Claude for Nonprofit)', 'what' => 'asystent AI Claude', 'prefix' => 'claude_bill_',
                      'match' => ['claude', 'anthropic', 'antropic'], 'uniqueEmail' => false, 'color' => '#da532c', 'requiresEdu' => true, 'pool' => false,
                      'program' => 'w programie Claude for Nonprofit (Anthropic)', 'defaults' => ['enabled' => '1', 'usd' => '8', 'list_usd' => '25']],
        'nordvpn' => ['label' => 'NordVPN', 'name' => 'NordVPN', 'what' => 'NordVPN, szyfrowane połączenie z internetem (VPN)', 'prefix' => 'nordvpn_bill_',
                      'match' => ['nordvpn', 'nord vpn', 'nordlayer', 'nord security'], 'uniqueEmail' => true, 'color' => '#4687ff', 'requiresEdu' => false, 'pool' => true,
                      'program' => '', 'defaults' => ['enabled' => '0', 'usd' => '0', 'list_usd' => '0']],
    ];
    public const STATUS = ['requested' => 'wniosek (czeka na Helpdesk)', 'active' => 'aktywny', 'suspended' => 'wstrzymany (zaległość)', 'ended' => 'zakończony'];
    public const CHARGE_STATUS = ['pending' => 'do zapłaty', 'paid' => 'opłacone', 'cancelled' => 'anulowane'];
    public const DEFAULTS = [
        'enabled' => '1', 'usd' => '8', 'list_usd' => '25', 'due_day' => '1', 'grace_days' => '7',   // dokument zawsze 1. dnia miesiąca (polecenie 2026-11-09)
        'available_from' => '', 'available_until' => '',   // okno zamawiania (włączania) przez uczestników: od / do — puste = bez ograniczenia
        'pct' => '23', 'pct_label' => 'VAT', 'fee_pln' => '1.23',   // 23% VAT od importu usługi — doliczany do ceny, płaci uczestnik (faktura FEER wg ustawień Faktur, domyślnie zw) i prowizja za płatność (polecenia 2026-11-09)
        'rate_kind' => 'sale_foreign', 'invoice' => 'auto', 'discount_mode' => 'rabat', 'invoice_email' => '1', 'notify' => '1', 'helpdesk' => '1',
        'simulate' => '0',   // symulacja wystawiania dokumentów (SYM-…) zamiast Fakturowni — do testów
        'pay_redirect' => 'p24', 'fast_transfer_url' => 'https://fundacjafeer.fakturownia.pl/apps/szybki-przelew',
        'bank_account' => '88102029060000130206617932',   // rachunek do wpłat (polecenie 2026-11-09)
    ];
    /** Termin płatności od naliczenia — zawsze 5 dni (polecenie 2026-11-09). */
    public const TERM_DAYS = 5;
    private const MONTHS = [1 => 'styczeń', 'luty', 'marzec', 'kwiecień', 'maj', 'czerwiec', 'lipiec', 'sierpień', 'wrzesień', 'październik', 'listopad', 'grudzień'];
    private const MONTHS_GEN = [1 => 'stycznia', 'lutego', 'marca', 'kwietnia', 'maja', 'czerwca', 'lipca', 'sierpnia', 'września', 'października', 'listopada', 'grudnia'];
    private const MONTHS_LOC = [1 => 'styczniu', 'lutym', 'marcu', 'kwietniu', 'maju', 'czerwcu', 'lipcu', 'sierpniu', 'wrześniu', 'październiku', 'listopadzie', 'grudniu'];

    /** Zgłoszenia Helpdesk z trybu testowego — zamiast tworzenia (podgląd na stronie testu). */
    public static array $testTickets = [];
    /** Czy tworzyć prawdziwe zgłoszenia także dla testowych abonamentów (zaznaczone na stronie testu). */
    public static bool $testRealTickets = false;
    private static array $afterPay = [];
    private static string $poolNote = '';
    /** Ustawienia produktu (jedno zapytanie na prefiks; czyszczone przy zapisie — org_setting() trzyma wartości do końca żądania). */
    private static array $settingsCache = [];
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
            product TEXT NOT NULL DEFAULT 'claude',
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
        db()->exec("CREATE TABLE IF NOT EXISTS claude_charges (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            subscription_id INTEGER NOT NULL REFERENCES claude_subscriptions(id) ON DELETE CASCADE,
            product TEXT NOT NULL DEFAULT 'claude',
            client_id INTEGER NOT NULL,
            period TEXT NOT NULL,
            due_on DATE NOT NULL,
            usd REAL NOT NULL, list_usd REAL NOT NULL DEFAULT 0,
            rate REAL NOT NULL, rate_kind TEXT NOT NULL DEFAULT '', rate_table TEXT NOT NULL DEFAULT '', rate_at TEXT NOT NULL DEFAULT '',
            amount_pln REAL NOT NULL, list_pln REAL NOT NULL DEFAULT 0,
            pct REAL NOT NULL DEFAULT 0, pct_label TEXT NOT NULL DEFAULT '', fee_pln REAL NOT NULL DEFAULT 0, base_pln REAL,
            issue_on DATE, invoice_view_url TEXT NOT NULL DEFAULT '',
            proforma_id INTEGER, proforma_number TEXT NOT NULL DEFAULT '', proforma_view_url TEXT NOT NULL DEFAULT '', order_id INTEGER, order_number TEXT NOT NULL DEFAULT '',
            status TEXT NOT NULL DEFAULT 'pending',
            pp_item_id INTEGER,
            paid_at DATETIME, paid_via TEXT NOT NULL DEFAULT '', paid_ref TEXT NOT NULL DEFAULT '',
            invoice_id INTEGER, invoice_number TEXT NOT NULL DEFAULT '', invoice_error TEXT NOT NULL DEFAULT '', invoice_payload TEXT,
            reminded_at DATETIME,
            note TEXT NOT NULL DEFAULT '',
            is_test INTEGER NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(subscription_id, period))");
        // tabele z pierwszej wersji (tylko Claude) — kolumna produktu
        foreach (['claude_subscriptions', 'claude_charges'] as $t) { try { db()->exec("ALTER TABLE $t ADD COLUMN product TEXT NOT NULL DEFAULT 'claude'"); } catch (\Throwable $e) {} }
        foreach (["pct REAL NOT NULL DEFAULT 0", "pct_label TEXT NOT NULL DEFAULT ''", "fee_pln REAL NOT NULL DEFAULT 0", "base_pln REAL",   // narzut % i prowizja (2026-11-09)
                  "proforma_id INTEGER", "proforma_number TEXT NOT NULL DEFAULT ''", "proforma_view_url TEXT NOT NULL DEFAULT ''", "order_id INTEGER", "order_number TEXT NOT NULL DEFAULT ''"] as $col) {   // proforma → zamówienie → faktura
            try { db()->exec("ALTER TABLE claude_charges ADD COLUMN $col"); } catch (\Throwable $e) {}
        }
        db()->exec("DROP INDEX IF EXISTS uq_claude_sub_open");
        try { db()->exec("ALTER TABLE claude_subscriptions ADD COLUMN helpdesk_ticket_id INTEGER"); } catch (\Throwable $e) {}   // wniosek z kreatora → karta w zgłoszeniu Helpdesk
        db()->exec("DROP INDEX IF EXISTS uq_lic_sub_open");
        db()->exec("CREATE UNIQUE INDEX IF NOT EXISTS uq_lic_sub_open2 ON claude_subscriptions(client_id, product) WHERE status IN ('requested','active','suspended')");
        db()->exec("CREATE INDEX IF NOT EXISTS idx_claude_ch_status ON claude_charges(status, due_on)");
        db()->exec("CREATE INDEX IF NOT EXISTS idx_claude_ch_item ON claude_charges(pp_item_id)");
        db()->exec("CREATE TABLE IF NOT EXISTS claude_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            subscription_id INTEGER, charge_id INTEGER,
            kind TEXT NOT NULL, text TEXT NOT NULL DEFAULT '', by_name TEXT NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)");
        db()->exec("CREATE INDEX IF NOT EXISTS idx_claude_ev_sub ON claude_events(subscription_id, id)");
        db()->exec("CREATE TABLE IF NOT EXISTS license_edu_requests (
            client_id INTEGER PRIMARY KEY, proposed TEXT NOT NULL DEFAULT '', helpdesk_number TEXT NOT NULL DEFAULT '',
            created_by TEXT NOT NULL DEFAULT '', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)");
    }

    // ── Konto @edu.feer.org.pl (wymagane do Claude) ─────────────────────────────

    public const EDU_DOMAIN = 'edu.feer.org.pl';

    /** Konto uczestnika w tenancie edukacyjnym (ms_upn, sprawa AM7, e-mail w kartotece) albo null. */
    public static function eduAccount(int $clientId): ?string
    {
        $like = '%@' . self::EDU_DOMAIN;
        foreach ([["SELECT ms_upn v FROM k30_ti_student_accounts WHERE client_id=? AND LOWER(COALESCE(ms_upn,'')) LIKE ? ORDER BY id DESC LIMIT 1", true],
                  ["SELECT tenant_upn v FROM licenses WHERE interested_kind='client' AND interested_id=? AND LOWER(tenant_upn) LIKE ? ORDER BY id DESC LIMIT 1", true],
                  ["SELECT email v FROM k30_clients WHERE id=? AND LOWER(COALESCE(email,'')) LIKE ?", true]] as [$sql]) {
            try { $r = db_one($sql, [$clientId, $like]); if ($r && trim((string)$r['v']) !== '') return trim((string)$r['v']); } catch (\Throwable $e) {}
        }
        return null;
    }

    /** Wniosek o konto @edu.feer.org.pl (jeden na osobę) albo null. */
    public static function eduRequest(int $clientId): ?array
    {
        self::migrate();
        return db_one("SELECT * FROM license_edu_requests WHERE client_id=?", [$clientId]) ?: null;
    }

    /** Proponowany adres konta edu: login „l” + 8 cyfr @edu.feer.org.pl (login tworzony, gdy go nie ma). */
    public static function eduProposal(int $clientId): string
    {
        require_once dirname(__DIR__, 2) . '/license_login/logic/licenseLogin.php';
        return (LicenseLogin::get($clientId) ?: LicenseLogin::generate($clientId, 'propozycja konta edu')) . '@' . self::EDU_DOMAIN;
    }

    /** Uczestnik prosi o konto @edu.feer.org.pl — zgłoszenie w Helpdesku IT z proponowanym loginem. */
    public static function requestEdu(int $clientId, array $actor): array
    {
        self::migrate();
        if (self::eduAccount($clientId)) throw new DomainException('Masz już konto @' . self::EDU_DOMAIN . '.');
        if ($r = self::eduRequest($clientId)) return $r;
        $c = self::client($clientId) ?? ['name' => '', 'email' => ''];
        $proposed = self::eduProposal($clientId);
        $num = '';
        try {
            require_once dirname(__DIR__, 3) . '/includes/helpdesk.php';
            $desc = "Załóż konto w tenancie edukacyjnym (@" . self::EDU_DOMAIN . ") — potrzebne do Claude.\nOsoba: " . $c['name'] . ' (uczestnik nr ' . $clientId . ')'
                . ($c['email'] ? "\nE-mail kontaktowy: " . $c['email'] : '') . "\nProponowany login: " . $proposed . "\nZgłosił: " . ($actor['name'] ?? '') . ' (' . ($actor['via'] ?? '') . ')';
            $tid = hd_ticket_quick_create(['id' => null, 'name' => (string)$c['name'], 'email' => (string)$c['email']], 'Załóż konto @' . self::EDU_DOMAIN . ': ' . $c['name'], $desc, 'it_licencje_am', 'normalny', 'license_edu');
            try { require_once dirname(__DIR__, 2) . '/helpdeskCollab/lib/helpdeskCollab.php'; hdTagAdd($tid, 'claude'); hdTagAdd($tid, 'konto_edu'); } catch (\Throwable $e) {}
            $num = (string)(db_one("SELECT number FROM helpdesk_tickets WHERE id=?", [$tid])['number'] ?? '');
        } catch (\Throwable $e) {
            self::notifyOffice(['is_test' => 0, 'id' => 0, 'product' => 'claude'], 'Konto @' . self::EDU_DOMAIN . ' do założenia: ' . $c['name'], '<p>Proponowany login: <b>' . h($proposed) . '</b> (zgłoszenie Helpdesk nie powstało: ' . h($e->getMessage()) . ').</p>');
        }
        db_insert('license_edu_requests', ['client_id' => $clientId, 'proposed' => $proposed, 'helpdesk_number' => $num, 'created_by' => (string)($actor['name'] ?? '')]);
        return self::eduRequest($clientId) ?? [];
    }

    // ── Pula licencji Fundacji (modules/license_pool) ───────────────────────────

    /** Produkt w puli licencji Fundacji pasujący do produktu (np. NordVPN) albo null. */
    public static function poolProduct(string $product): ?int
    {
        $P = self::P($product);
        if (empty($P['pool'])) return null;
        try {
            require_once dirname(__DIR__, 2) . '/license_pool/lib/LpPool.php';
            foreach (LpPool::products(true) as $pp) {
                $hay = mb_strtolower((string)$pp['name'] . ' ' . (string)($pp['vendor'] ?? ''));
                foreach ($P['match'] as $m) if (str_contains($hay, $m)) return (int)$pp['id'];
            }
        } catch (\Throwable $e) {}
        return null;
    }

    /** Czy w puli jest wolne miejsce na produkt. */
    public static function poolHasFree(string $product): bool
    {
        $pid = self::poolProduct($product);
        if (!$pid) return false;
        try { return LpPool::pickFree($pid) !== null; } catch (\Throwable $e) { return false; }
    }

    // ── Produkty i ustawienia ───────────────────────────────────────────────────

    public static function product(string $p): string { return isset(self::PRODUCTS[$p]) ? $p : 'claude'; }
    public static function P(string $p): array { return self::PRODUCTS[self::product($p)] + ['key' => self::product($p)]; }
    public static function productOf(?array $row): string { return self::product((string)($row['product'] ?? 'claude')); }

    public static function settings(string $product = 'claude'): array
    {
        $P = self::P($product);
        $def = $P['defaults'] + self::DEFAULTS;
        $s = ['product' => $P['key']];
        if (!isset(self::$settingsCache[$P['prefix']])) {
            $raw = [];
            foreach (db_all("SELECT key_, value FROM settings WHERE key_ LIKE ?", [$P['prefix'] . '%']) as $r) $raw[substr((string)$r['key_'], strlen($P['prefix']))] = (string)$r['value'];
            self::$settingsCache[$P['prefix']] = $raw;
        }
        foreach ($def as $k => $d) { $v = trim(self::$settingsCache[$P['prefix']][$k] ?? ''); $s[$k] = $v !== '' ? $v : $d; }
        $s['usd'] = max(0.0, (float)str_replace(',', '.', (string)$s['usd']));
        $s['list_usd'] = max(0.0, (float)str_replace(',', '.', (string)$s['list_usd']));
        $s['due_day'] = min(28, max(1, (int)$s['due_day']));
        $s['grace_days'] = min(60, max(0, (int)$s['grace_days']));
        $s['pct'] = min(50.0, max(0.0, (float)str_replace(',', '.', (string)$s['pct'])));
        $s['fee_pln'] = round(min(100.0, max(0.0, (float)str_replace(',', '.', (string)$s['fee_pln']))), 2);
        $s['pct_label'] = mb_substr(trim((string)$s['pct_label']), 0, 30);
        foreach (['available_from', 'available_until'] as $dk) if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$s[$dk])) $s[$dk] = '';
        if (!isset(PkoRates::KINDS[$s['rate_kind']])) $s['rate_kind'] = 'sale_foreign';
        if (!in_array($s['invoice'], ['auto', 'manual', 'off'], true)) $s['invoice'] = 'auto';
        if (!in_array($s['discount_mode'], ['rabat', 'opis'], true)) $s['discount_mode'] = 'rabat';
        if (!in_array($s['pay_redirect'], ['p24', 'portal', 'fakturownia'], true)) $s['pay_redirect'] = 'p24';
        if (!preg_match('#^https://#', $s['fast_transfer_url'])) $s['fast_transfer_url'] = self::DEFAULTS['fast_transfer_url'];
        $s['bank_account'] = preg_replace('/\D/', '', preg_replace('/^\s*PL/i', '', (string)$s['bank_account']));
        if (strlen($s['bank_account']) !== 26) $s['bank_account'] = self::DEFAULTS['bank_account'];
        foreach (['enabled', 'invoice_email', 'notify', 'helpdesk', 'simulate'] as $b) $s[$b] = $s[$b] === '1';
        $s['priced'] = $s['usd'] > 0;
        return $s;
    }

    public static function saveSettings(array $in, string $by, string $product = 'claude'): void
    {
        $P = self::P($product);
        $num = fn($v) => str_replace(',', '.', trim((string)$v));
        $map = [
            'enabled' => !empty($in['enabled']) ? '1' : '0', 'usd' => $num($in['usd'] ?? ''), 'list_usd' => $num($in['list_usd'] ?? '') ?: '0',
            'due_day' => (string)(int)($in['due_day'] ?? 8), 'grace_days' => (string)(int)($in['grace_days'] ?? 7),
            'available_from' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in['available_from'] ?? '')) ? (string)$in['available_from'] : '',
            'available_until' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in['available_until'] ?? '')) ? (string)$in['available_until'] : '',
            'pct' => $num($in['pct'] ?? '0') ?: '0', 'pct_label' => mb_substr(trim((string)($in['pct_label'] ?? '')), 0, 30), 'fee_pln' => $num($in['fee_pln'] ?? '0') ?: '0',
            'pay_redirect' => (string)($in['pay_redirect'] ?? 'p24'), 'fast_transfer_url' => trim((string)($in['fast_transfer_url'] ?? '')),
            'bank_account' => preg_replace('/\D/', '', preg_replace('/^\s*PL/i', '', (string)($in['bank_account'] ?? ''))),
            'rate_kind' => (string)($in['rate_kind'] ?? 'sale_foreign'), 'invoice' => (string)($in['invoice'] ?? 'auto'), 'discount_mode' => (string)($in['discount_mode'] ?? 'rabat'),
            'invoice_email' => !empty($in['invoice_email']) ? '1' : '0', 'notify' => !empty($in['notify']) ? '1' : '0', 'helpdesk' => !empty($in['helpdesk']) ? '1' : '0',
            'simulate' => !empty($in['simulate']) ? '1' : '0',
        ];
        if ($map['enabled'] === '1' && (float)$map['usd'] <= 0) throw new InvalidArgumentException('Ustaw miesięczną cenę w USD, zanim włączysz samodzielną aktywację ' . $P['label'] . '.');
        if ((float)$map['usd'] < 0) throw new InvalidArgumentException('Cena nie może być ujemna.');
        if ((float)$map['pct'] < 0 || (float)$map['pct'] > 50) throw new InvalidArgumentException('Narzut procentowy: 0–50%.');
        if ((float)$map['fee_pln'] < 0 || (float)$map['fee_pln'] > 100) throw new InvalidArgumentException('Prowizja za płatność: 0–100 zł.');
        if ((int)$map['due_day'] < 1 || (int)$map['due_day'] > 28) throw new InvalidArgumentException('Dzień graniczny: 1–28.');
        if ($map['available_from'] !== '' && $map['available_until'] !== '' && $map['available_until'] < $map['available_from']) throw new InvalidArgumentException('Data „zamawianie do” nie może być wcześniejsza niż „od”.');
        if ((float)$map['list_usd'] > 0 && (float)$map['list_usd'] < (float)$map['usd']) throw new InvalidArgumentException('Cena regularna nie może być niższa od ceny po rabacie.');
        require_once dirname(__DIR__, 2) . '/payment_portal/logic/paymentPortal.php';
        if ($map['bank_account'] !== '' && !pp_nrb_valid($map['bank_account'])) throw new InvalidArgumentException('Numer rachunku do wpłat jest nieprawidłowy (26 cyfr, suma kontrolna IBAN).');
        if ($map['fast_transfer_url'] !== '' && !preg_match('#^https://[a-z0-9.-]+\.fakturownia\.pl/#i', $map['fast_transfer_url'])) throw new InvalidArgumentException('Adres Szybkiego przelewu musi wskazywać na konto w Fakturowni (https://….fakturownia.pl/…).');
        foreach ($map as $k => $v) org_setting_set($P['prefix'] . $k, $v);
        unset(self::$settingsCache[$P['prefix']]);
        self::event(null, null, 'settings', $P['label'] . ' — zmieniono ustawienia: ' . json_encode($map, JSON_UNESCAPED_UNICODE), $by);
    }

    /** Warunki akceptowane przy aktywacji — prostym językiem (zapisywane w abonamencie). */
    public static function termsText(?array $s = null, string $product = 'claude'): string
    {
        $s ??= self::settings($product);
        $P = self::P($s['product'] ?? $product);
        return 'Włączam dostęp do ' . $P['label'] . '. Wiem, że co miesiąc płacę ' . self::usdWords($s['usd'])
            . ($s['list_usd'] > $s['usd'] ? ' (cena dla organizacji non-profit, normalnie ' . self::usdWords($s['list_usd']) . ')' : '')
            . ' przeliczone na złote po kursie PKO BP z dnia wystawienia faktury' . self::extrasWords($s) . '. '
            . 'Pierwszą proformę dostanę od razu, kolejne ' . ($s['due_day'] === 1 ? 'zawsze 1. dnia miesiąca' : 'co miesiąc w dniu włączenia dostępu, ale nie później niż ' . $s['due_day'] . '. dnia miesiąca') . '; po wpłacie dostanę fakturę. '
            . 'Na zapłatę mam ' . self::TERM_DAYS . ' dni (Przelewy24, przelew na konto albo Szybki przelew z faktury). '
            . 'Jeśli nie zapłacę w ciągu ' . $s['grace_days'] . ' dni po terminie, dostęp zostanie wstrzymany do czasu wpłaty. '
            . 'Mogę zrezygnować w każdej chwili w panelu — wtedy nie dostanę kolejnych faktur.';
    }

    // ── Pomocnicze ──────────────────────────────────────────────────────────────

    public static function zl(float $v): string { return number_format($v, 2, ',', ' ') . ' zł'; }
    public static function usd(float $v): string { return number_format($v, 2, ',', ' ') . ' USD'; }
    public static function usdWords(float $v): string { $n = rtrim(rtrim(number_format($v, 2, ',', ''), '0'), ','); return $n . ' ' . ((float)$v == 1.0 ? 'dolara' : 'dolarów'); }
    public static function rateFmt(float $v): string { return number_format($v, 4, ',', ' ') . ' zł'; }
    public static function pctFmt(float $v): string { return rtrim(rtrim(number_format($v, 2, ',', ''), '0'), ',') . '%'; }
    /** „, plus 2% VAT i 1,23 zł prowizji za płatność” (puste, gdy brak narzutu i prowizji). */
    public static function extrasWords(array $s): string
    {
        $x = [];
        if ((float)($s['pct'] ?? 0) > 0) $x[] = self::pctFmt((float)$s['pct']) . ((string)($s['pct_label'] ?? '') !== '' ? ' ' . $s['pct_label'] : '');
        if ((float)($s['fee_pln'] ?? 0) > 0) $x[] = self::zl((float)$s['fee_pln']) . ' prowizji za płatność';
        return $x ? ', plus ' . implode(' i ', $x) : '';
    }
    /** Krótko: „8 USD + 2% VAT + 1,23 zł prowizji” (ustawienia albo naliczenie). */
    public static function priceShort(array $s): string
    {
        return self::usd((float)($s['usd'] ?? 0)) . ((float)($s['pct'] ?? 0) > 0 ? ' + ' . self::pctFmt((float)$s['pct']) . ((string)($s['pct_label'] ?? '') !== '' ? ' ' . $s['pct_label'] : '') : '')
            . ((float)($s['fee_pln'] ?? 0) > 0 ? ' + ' . self::zl((float)$s['fee_pln']) . ' prowizji' : '');
    }
    /** Rozbicie kwoty naliczenia: USD × kurs = baza; + narzut %; + prowizja = razem (dla starych naliczeń bez narzutu — samo przeliczenie). */
    public static function breakdown(array $ch): string
    {
        $raw = round((float)$ch['usd'] * (float)$ch['rate'], 2);
        $base = $ch['base_pln'] !== null && $ch['base_pln'] !== '' ? (float)$ch['base_pln'] : round((float)$ch['amount_pln'] - (float)($ch['fee_pln'] ?? 0), 2);
        $t = self::usd((float)$ch['usd']) . ' × ' . self::rateFmt((float)$ch['rate']) . ' = ' . self::zl($raw);
        if ((float)($ch['pct'] ?? 0) > 0) $t .= ' + ' . self::pctFmt((float)$ch['pct']) . ((string)($ch['pct_label'] ?? '') !== '' ? ' ' . $ch['pct_label'] : '') . ' (' . self::zl(round($base - $raw, 2)) . ')';
        if ((float)($ch['fee_pln'] ?? 0) > 0) $t .= ' + prowizja ' . self::zl((float)$ch['fee_pln']);
        return $t . ' = ' . self::zl((float)$ch['amount_pln']);
    }
    public static function date(?string $d): string { $t = $d ? strtotime($d) : false; return $t ? date('d.m.Y', $t) : '—'; }
    /** „13 grudnia” (bez roku, gdy bieżący) — do maili i panelu. */
    public static function dayMonth(?string $d): string
    {
        $t = $d ? strtotime($d) : false;
        if (!$t) return '—';
        return (int)date('j', $t) . ' ' . self::MONTHS_GEN[(int)date('n', $t)] . (date('Y', $t) !== date('Y') ? ' ' . date('Y', $t) : '');
    }
    public static function periodLabel(string $p): string { [$y, $m] = array_map('intval', explode('-', $p . '-1')); return (self::MONTHS[$m] ?? $m) . ' ' . $y; }
    /** „w grudniu” / „w styczniu 2027” */
    public static function periodIn(string $p): string { [$y, $m] = array_map('intval', explode('-', $p . '-1')); return 'w ' . (self::MONTHS_LOC[$m] ?? $m) . ($y !== (int)date('Y') ? ' ' . $y : ''); }

    public static function dueDay(array $sub, ?array $s = null): int
    {
        $s ??= self::settings(self::productOf($sub));
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
    public static function amounts(float $rate, array $s): array
    {
        $k = 1 + (float)($s['pct'] ?? 0) / 100;
        $fee = round((float)($s['fee_pln'] ?? 0), 2);
        $base = round($s['usd'] * $rate * $k, 2);                                             // USD × kurs + narzut %
        $listBase = $s['list_usd'] > $s['usd'] ? round($s['list_usd'] * $rate * $k, 2) : $base;
        return ['usd' => $s['usd'], 'list_usd' => max($s['list_usd'], $s['usd']), 'pct' => (float)($s['pct'] ?? 0), 'pct_label' => (string)($s['pct_label'] ?? ''),
                'fee_pln' => $fee, 'base_pln' => $base, 'list_base_pln' => $listBase,
                'amount_pln' => round($base + $fee, 2), 'list_pln' => round($listBase + $fee, 2),
                'discount_pln' => round($listBase - $base, 2), 'discount_usd' => round(max(0, $s['list_usd'] - $s['usd']), 2)];
    }

    /**
     * Cena regularna samej usługi w zł (lista USD × kurs + narzut %), BEZ prowizji za płatność — tę kwotę pokazujemy jako „cena regularna X USD = … zł”.
     * list_pln zawiera prowizję (żeby rabat = list_pln − amount_pln się zgadzał), więc nie wolno go podpisywać jako równowartości X USD.
     */
    public static function listService(array $x): float
    {
        return isset($x['list_base_pln']) ? (float)$x['list_base_pln'] : round((float)($x['list_pln'] ?? 0) - (float)($x['fee_pln'] ?? 0), 2);
    }

    /** Wycena bieżąca (pobiera kurs z PKO BP). */
    public static function quote(?string $onDate = null, string $product = 'claude'): array
    {
        $s = self::settings($product);
        $r = PkoRates::latest('USD', $s['rate_kind'], $onDate);
        return $r + self::amounts($r['rate'], $s);
    }

    /** Ostatni zapisany kurs (bez łączenia z PKO BP) — do orientacyjnego pokazania w panelu. */
    public static function lastRate(string $product = 'claude'): ?array
    {
        self::migrate();
        $s = self::settings($product);
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

    /** Konto wirtualne (testowe, np. seeder licencje_test): pełna symulacja — dokumenty SYM bez Fakturowni, Helpdesk bez zgłoszeń, bez e-maili i SMS. */
    public static function isVirtual(int $clientId): bool
    {
        try { return (bool)db_one("SELECT 1 FROM k30_ti_student_accounts WHERE client_id=? AND COALESCE(is_virtual,0)=1", [$clientId]); } catch (\Throwable $e) { return false; }
    }

    public static function isMinor(int $clientId): bool
    {
        try { return (bool)db_one("SELECT 1 FROM k30_ti_student_accounts WHERE client_id=? AND COALESCE(is_minor,0)=1", [$clientId]); } catch (\Throwable $e) { return false; }
    }

    /** Aktywne, niewygasłe przypisanie licencji produktu (moduł Licencje) albo null. */
    public static function assignment(int $clientId, string $product = 'claude'): ?array
    {
        $P = self::P($product);
        foreach (db_all("SELECT cl.id, cl.client_id, cl.expires_at, cl.login, l.name AS license_name, l.vendor
                           FROM k30_ti_client_licenses cl JOIN k30_ti_licenses l ON l.id = cl.license_id
                          WHERE cl.client_id = ? AND cl.status = 'active' AND (cl.expires_at IS NULL OR cl.expires_at = '' OR cl.expires_at >= date('now'))
                          ORDER BY cl.id DESC", [$clientId]) as $r) {
            $hay = mb_strtolower($r['license_name'] . ' ' . $r['vendor']);
            foreach ($P['match'] as $m) if (str_contains($hay, $m)) return $r;
        }
        return null;
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
        foreach (db_all("SELECT id FROM claude_charges WHERE subscription_id=? AND ((status='pending' AND proforma_id IS NULL AND invoice_id IS NULL) OR (status='paid' AND invoice_id IS NULL))", [$subId]) as $r) {
            try { ClaudeInvoice::issueForCharge((int)$r['id']); } catch (\Throwable $e) {}
        }
        return [];
    }

    /** „Daj znać”: brak danych do FV — e-mail do biura i do uczestnika (raz, do czasu uzupełnienia). */
    public static function alertBuyerGaps(array $sub, array $gaps, ?array $ch = null): void
    {
        if (!$gaps || (int)$sub['is_test'] || !empty($sub['buyer_gap_alert_at'])) return;
        db_exec("UPDATE claude_subscriptions SET buyer_gap_alert_at=datetime('now') WHERE id=?", [(int)$sub['id']]);
        $P = self::P(self::productOf($sub));
        $list = implode(', ', $gaps);
        self::event((int)$sub['id'], $ch ? (int)$ch['id'] : null, 'buyer_gaps', 'Brak danych do faktury VAT: ' . $list . '.', 'system');
        self::notifyOffice($sub, 'Brak danych do faktury — ' . $sub['client_name'], '<p>Nie możemy wystawić faktury za ' . h($P['label']) . ' dla <b>' . h((string)$sub['client_name']) . '</b>, bo brakuje: <b>' . h($list) . '</b>.'
            . ($ch ? ' Czeka faktura za ' . h(self::periodLabel((string)$ch['period'])) . ' (' . self::zl((float)$ch['amount_pln']) . ') — wystawi się sama, gdy dane zostaną uzupełnione (uczestnik w panelu albo Ty na karcie abonamentu).' : '') . '</p>');
        self::notifyParticipant($sub, 'dane_fv', $ch, $list);
    }

    // ── Abonament ───────────────────────────────────────────────────────────────

    public static function subscription(int $id): ?array
    {
        self::migrate();
        return db_one("SELECT s.*, c.name AS client_name, c.email AS client_email FROM claude_subscriptions s LEFT JOIN k30_clients c ON c.id=s.client_id WHERE s.id=?", [$id]) ?: null;
    }

    /** Bieżący (aktywny / wstrzymany) albo ostatni abonament osoby dla produktu. */
    public static function forClient(int $clientId, bool $test = false, string $product = 'claude'): ?array
    {
        self::migrate();
        return db_one("SELECT * FROM claude_subscriptions WHERE client_id=? AND is_test=? AND product=? ORDER BY CASE WHEN status IN ('requested','active','suspended') THEN 0 ELSE 1 END, id DESC LIMIT 1",
            [$clientId, $test ? 1 : 0, self::product($product)]) ?: null;
    }

    /** Czy ktoś inny z tym adresem e-mail ma już aktywny abonament produktu (NordVPN: jedna licencja na osobę). */
    public static function emailTaken(string $email, string $product, int $exceptClient = 0): ?string
    {
        $e = mb_strtolower(trim($email));
        if ($e === '') return null;
        $r = db_one("SELECT c.name FROM claude_subscriptions s JOIN k30_clients c ON c.id=s.client_id
                      WHERE s.product=? AND s.status IN ('active','suspended') AND s.is_test=0 AND s.client_id<>?
                        AND (LOWER(TRIM(c.email))=? OR LOWER(TRIM(s.buyer_email))=?) LIMIT 1", [self::product($product), $exceptClient, $e, $e]);
        return $r ? (string)$r['name'] : null;
    }

    /** Czy uczestnik może aktywować dostęp. @return array{ok:bool,reason:string,assignment:?array} */
    public static function eligibility(int $clientId, string $product = 'claude'): array
    {
        self::migrate();
        $s = self::settings($product);
        $P = self::P($product);
        $a = self::assignment($clientId, $product);
        $open = db_one("SELECT id FROM claude_subscriptions WHERE client_id=? AND product=? AND status IN ('active','suspended') AND is_test=0", [$clientId, $P['key']]);
        if ($open)                 return ['ok' => false, 'reason' => 'Masz już włączony ' . $P['label'] . '.', 'assignment' => $a];
        $fromPool = false;   // uczestnik włącza tylko licencję PRZYPISANĄ mu w SZO (NordVPN: przydział z puli robi kadra — polecenie 2026-11-09)
        if (!$a)                   return ['ok' => false, 'reason' => 'Nie masz przypisanej licencji ' . $P['label'] . '. Przypisuje ją prowadzący albo biuro.', 'assignment' => null];
        if (!$s['enabled'] || !$s['priced']) return ['ok' => false, 'reason' => 'Włączanie ' . $P['label'] . ' jest teraz wyłączone. Napisz do biura.', 'assignment' => $a];
        if ($s['available_until'] !== '' && $s['available_until'] < date('Y-m-d')) return ['ok' => false, 'reason' => 'Zamawianie ' . $P['label'] . ' zakończyło się ' . self::dayMonth($s['available_until']) . '. Jeśli potrzebujesz dostępu, napisz do biura.', 'assignment' => $a];
        if ($s['available_from'] !== '' && $s['available_from'] > date('Y-m-d')) return ['ok' => false, 'reason' => $P['label'] . ' można włączyć od ' . self::dayMonth($s['available_from']) . '.', 'assignment' => $a, 'from' => $s['available_from']];
        if (self::isMinor($clientId)) return ['ok' => false, 'reason' => 'Dostęp osoby niepełnoletniej włącza biuro na prośbę opiekuna.', 'assignment' => $a];
        if (!empty($P['requiresEdu']) && !self::eduAccount($clientId)) {
            return ['ok' => false, 'needEdu' => true, 'assignment' => $a, 'reason' => $P['label'] . ' działa tylko z kontem @' . self::EDU_DOMAIN . '. Nie masz go. Złóż wniosek poniżej, konto założy Dział IT.'];
        }
        if ($P['uniqueEmail']) {
            $email = trim((string)(self::client($clientId)['email'] ?? ''));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'reason' => $P['label'] . ' wymaga adresu e-mail. Uzupełnij go w „Moje konto”.', 'assignment' => $a];
            if (self::emailTaken($email, $P['key'], $clientId)) return ['ok' => false, 'reason' => 'Na adres ' . $email . ' jest już włączona licencja ' . $P['label'] . '. Jedna osoba może mieć jedną.', 'assignment' => $a];
        }
        $debt = db_one("SELECT COUNT(*) n FROM claude_charges WHERE client_id=? AND product=? AND status='pending' AND is_test=0", [$clientId, $P['key']]);
        if ((int)($debt['n'] ?? 0) > 0) return ['ok' => false, 'reason' => 'Masz niezapłaconą fakturę za ' . $P['label'] . '. Najpierw ją zapłać.', 'assignment' => $a];
        return ['ok' => true, 'reason' => '', 'assignment' => $a, 'fromPool' => $fromPool];
    }

    /**
     * Aktywacja: abonament + pierwsze naliczenie (z fakturą) + zgłoszenie Helpdesk „aktywacja”.
     * $actor: ['name' => kto, 'via' => 'panel uczestnika'|'panel dydaktyka'|'test', 'ip' => …]. $checkEligibility=false — aktywuje kadra.
     */
    public static function activate(int $clientId, array $actor, bool $test = false, ?string $date = null, bool $checkEligibility = true, ?int $dueDay = null, ?array $buyer = null, string $product = 'claude'): array
    {
        self::migrate();
        $product = self::product($product);
        $P = self::P($product);
        $s = self::settings($product);
        if (!self::client($clientId)) throw new DomainException('Nie ma takiego uczestnika.');
        $assignment = null;
        if (!$test && $checkEligibility) {
            $el = self::eligibility($clientId, $product);
            if (!$el['ok']) throw new DomainException($el['reason']);
            $assignment = $el['assignment'];
        } else {
            if (db_one("SELECT 1 FROM claude_subscriptions WHERE client_id=? AND product=? AND status IN ('active','suspended')", [$clientId, $product])) throw new DomainException('Ta osoba ma już włączony ' . $P['label'] . '.');
            if (!$test && $P['uniqueEmail'] && ($who = self::emailTaken((string)(self::client($clientId)['email'] ?? ''), $product, $clientId))) throw new DomainException('Ten adres e-mail ma już licencję ' . $P['label'] . ' (' . $who . ') — jedna na osobę.');
            if (!$s['priced']) throw new DomainException('Najpierw ustaw cenę ' . $P['label'] . ' w ustawieniach.');
            $assignment = self::assignment($clientId, $product);
        }
        if (!$assignment && !$test && !empty($P['pool']) && ($pid = self::poolProduct($product))) {   // licencja z puli licencji Fundacji (klucz trafia do modułu Licencje)
            require_once dirname(__DIR__, 2) . '/license_pool/lib/LpPool.php';
            $c = self::client($clientId) ?? [];
            $r = LpPool::assign(['holder_type' => 'client', 'holder_id' => $clientId, 'product_id' => $pid, 'context' => 'Płatne licencje — ' . $P['label'],
                                 'note' => 'włączone w panelu licencji (' . ($actor['via'] ?? '') . ')', 'notify' => false, 'holder_email' => (string)($c['email'] ?? ''),
                                 'billing_form' => 'FAK', 'billing_ref' => 'subskrypcja ' . $P['label'] . ' — proforma/faktura co miesiąc (Płatne licencje)'], null);   // rozliczenie: faktura (moduł Licencje wymaga formy)
            if (empty($r['ok'])) throw new DomainException('Nie udało się przydzielić licencji ' . $P['label'] . ' z puli: ' . implode(' ', (array)($r['errors'] ?? [])));
            $assignment = self::assignment($clientId, $product);
            self::$poolNote = 'licencja z puli Fundacji (przydział #' . (int)($r['id'] ?? 0) . ')';
        }
        $date = $date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : date('Y-m-d');
        $free = false;
        if (!$test) { require_once dirname(__DIR__, 3) . '/includes/ti_virtual.php'; $free = ti_client_no_billing($clientId); }
        if (!$free) self::quote(null, $product);   // bez kursu (PKO BP nie odpowiada i brak zapisanego) — aktywacja nie powstaje w połowie
        $b = self::buyerDefaults($clientId);
        if ($buyer !== null) { [$bd] = self::buyerFromInput($buyer + $b); $b = $bd; }
        $id = db_insert('claude_subscriptions', $b + [
            'product' => $product, 'client_id' => $clientId, 'status' => 'active', 'activated_on' => $date, 'cycle_start' => $date,
            'due_day' => $dueDay && $dueDay >= 1 && $dueDay <= 28 ? $dueDay : null, 'free' => $free ? 1 : 0,
            'assignment_id' => $assignment['id'] ?? null, 'terms_text' => self::termsText($s), 'terms_accepted_at' => date('Y-m-d H:i:s'),
            'terms_ip' => (string)($actor['ip'] ?? ''), 'activated_by' => trim((string)($actor['name'] ?? '') . ' (' . ($actor['via'] ?? '') . ')'),
            'is_test' => $test ? 1 : 0,
        ]);
        self::event($id, null, 'activated', 'Włączono ' . $P['label'] . ' od ' . self::date($date) . ($free ? ' — bez opłat (uczestnik „bez rozliczeń”)' : '') . '. Warunki zaakceptowane ' . date('d.m.Y H:i') . (($actor['ip'] ?? '') !== '' ? ', IP ' . $actor['ip'] : '') . '.', (string)($actor['name'] ?? ''));
        if (self::$poolNote !== '') { self::event($id, null, 'pool', 'Przydzielono ' . self::$poolNote . '.', (string)($actor['name'] ?? '')); self::$poolNote = ''; }
        $sub = self::subscription($id);
        if ($gaps = self::invoiceDataGaps($sub)) self::alertBuyerGaps($sub, $gaps);
        $charge = null;
        if (!$free) $charge = self::createCharge($sub, substr($date, 0, 7), $date);
        self::helpdesk($sub, 'aktywacja', $actor, $charge);
        self::notifyOffice($sub, 'Włączenie ' . $P['label'] . ' — ' . $sub['client_name'], '<p><b>' . h((string)$sub['client_name']) . '</b> włączył(a) ' . h($P['label']) . ' od ' . self::dayMonth($date) . '.'
            . ($charge ? ' Pierwsza faktura: <b>' . self::zl((float)$charge['amount_pln']) . '</b>, płatna do ' . self::dayMonth($charge['due_on']) . '.' : '') . '</p>');
        return self::subscription($id);
    }

    /** Rezygnacja (uczestnik) albo zakończenie (kadra): bez nowych naliczeń, przyszłe nieopłacone anulowane, zgłoszenie dezaktywacji. */
    public static function end(int $subId, string $reason, array $actor): void
    {
        $sub = self::subscription($subId);
        if (!$sub || $sub['status'] === 'ended') throw new DomainException('Ten dostęp jest już wyłączony.');
        $P = self::P(self::productOf($sub));
        $today = date('Y-m-d');
        db_exec("UPDATE claude_subscriptions SET status='ended', ended_on=?, ended_reason=?, updated_at=datetime('now') WHERE id=?", [$today, mb_substr($reason, 0, 300), $subId]);
        foreach (db_all("SELECT * FROM claude_charges WHERE subscription_id=? AND status='pending' AND due_on>?", [$subId, $today]) as $c) {
            try { self::cancelCharge((int)$c['id'], ['name' => (string)($actor['name'] ?? '')], 'rezygnacja przed terminem'); } catch (\Throwable $e) {}
        }
        self::event($subId, null, 'ended', 'Wyłączono ' . $P['label'] . ': ' . $reason, (string)($actor['name'] ?? ''));
        $sub = self::subscription($subId);
        self::helpdesk($sub, 'dezaktywacja', $actor, null, $reason);
        self::notifyOffice($sub, 'Rezygnacja z ' . $P['label'] . ' — ' . $sub['client_name'], '<p><b>' . h((string)$sub['client_name']) . '</b>: ' . h($reason) . '. Opłacone do: ' . self::dayMonth(self::paidThrough($sub)) . '.</p>');
    }

    /** Ostatni dzień opłaconego okresu (dzień przed naliczeniem kolejnego okresu po ostatnim opłaconym) albo null. */
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
        self::event($subId, null, 'due_day', $day ? 'Indywidualny dzień graniczny: ' . $day . '.' : 'Dzień graniczny — wg ustawienia ogólnego.', (string)($actor['name'] ?? ''));
    }

    // ── Naliczenia ──────────────────────────────────────────────────────────────

    public static function charge(int $id): ?array
    {
        self::migrate();
        return db_one("SELECT ch.*, c.name AS client_name, c.email AS client_email FROM claude_charges ch LEFT JOIN k30_clients c ON c.id=ch.client_id WHERE ch.id=?", [$id]) ?: null;
    }

    public static function chargeTitle(array $ch): string
    {
        $P = self::P(self::productOf($ch));
        $doc = self::docRef($ch);
        return ((int)$ch['is_test'] ? '[TEST] ' : '') . ($doc !== '' ? $doc . ' — ' : '') . $P['label'] . ' — ' . self::periodLabel((string)$ch['period'])
            . ' (' . self::priceShort($ch) . ', kurs PKO BP ' . self::rateFmt((float)$ch['rate']) . ')';
    }

    /** „Proforma PF/…” albo „Faktura FV/…” (opłacone) — do tytułów przelewu i płatności; pusto, gdy brak dokumentu albo symulacja. */
    public static function docRef(array $ch): string
    {
        $paid = ($ch['status'] ?? '') === 'paid' && (string)($ch['invoice_number'] ?? '') !== '';
        $no = $paid ? (string)$ch['invoice_number'] : ((string)($ch['proforma_number'] ?? '') ?: (string)($ch['invoice_number'] ?? ''));
        if ($no === '' || str_starts_with($no, 'SYM-')) return '';
        return ($paid || (string)($ch['proforma_number'] ?? '') === '' ? 'Faktura ' : 'Proforma ') . $no;
    }

    /** Naliczenie za okres — kurs z PKO BP z dnia naliczenia; pozycja w portalu płatności; faktura. Musi działać POZA transakcją (pp_add_item ma własną). */
    public static function createCharge(array $sub, string $period, ?string $today = null): ?array
    {
        if ((int)$sub['free']) return null;
        if ($ex = db_one("SELECT id FROM claude_charges WHERE subscription_id=? AND period=?", [(int)$sub['id'], $period])) return self::charge((int)$ex['id']);
        $product = self::productOf($sub);
        $s = self::settings($product);
        $today ??= date('Y-m-d');
        $q = self::quote(min($today, date('Y-m-d')), $product);
        $due = self::dueFor($sub, $period, $s);
        $id = db_insert('claude_charges', [
            'subscription_id' => (int)$sub['id'], 'product' => $product, 'client_id' => (int)$sub['client_id'], 'period' => $period, 'due_on' => $due,
            'issue_on' => self::billingDate($sub, $period, $s), 'usd' => $q['usd'], 'list_usd' => $q['list_usd'], 'rate' => $q['rate'], 'rate_kind' => $q['kind'],
            'rate_table' => (string)$q['table_number'], 'rate_at' => (string)$q['table_at'], 'amount_pln' => $q['amount_pln'], 'list_pln' => $q['list_pln'],
            'pct' => $q['pct'], 'pct_label' => $q['pct_label'], 'fee_pln' => $q['fee_pln'], 'base_pln' => $q['base_pln'],
            'status' => 'pending', 'is_test' => (int)$sub['is_test'], 'note' => $q['stale'] ? 'kurs: ' . $q['source'] : '',
        ]);
        if ($s['invoice'] === 'auto' || (int)$sub['is_test']) {   // najpierw proforma (numer trafia do tytułu płatności), potem pozycja portalu
            require_once __DIR__ . '/ClaudeInvoice.php';
            try { ClaudeInvoice::issueForCharge($id); } catch (\Throwable $e) { db_exec("UPDATE claude_charges SET invoice_error=? WHERE id=?", [mb_substr($e->getMessage(), 0, 500), $id]); }
        }
        $ch = self::charge($id);
        require_once dirname(__DIR__, 2) . '/payment_portal/logic/paymentPortal.php';
        $item = pp_add_item((int)$sub['client_id'], self::chargeTitle($ch), (float)$ch['amount_pln'], 'manual', null, $due, 'Abonament ' . self::P($product)['label'], null);
        if (is_int($item)) db_exec("UPDATE claude_charges SET pp_item_id=? WHERE id=?", [$item, $id]);
        else db_exec("UPDATE claude_charges SET note=? WHERE id=?", [trim($ch['note'] . ' Portal płatności: ' . $item), $id]);
        self::event((int)$sub['id'], $id, 'charge', 'Naliczenie za ' . self::periodLabel($period) . ': ' . self::breakdown(self::charge($id))
            . ' (' . $q['kind_label'] . ', tabela PKO BP ' . PkoRates::tableLabel($q) . '), termin ' . self::date($due) . '.', 'system');
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
            if ($it && $it['status'] === 'pending' && ($err = pp_cancel_item((int)$ch['pp_item_id'], (string)($actor['name'] ?? 'abonament'), null))) throw new DomainException($err);
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

    /** Płatność Przelewy24 bezpośrednio za naliczenie (panel /licencje) — źródło p24 'claude_charge'; księgowanie w onP24Paid() z webhooka. */
    public static function startP24(int $chargeId, string $email, string $returnUrl): string
    {
        require_once dirname(__DIR__, 3) . '/includes/p24.php';
        $ch = self::charge($chargeId);
        if (!$ch || $ch['status'] !== 'pending') throw new DomainException('Ta faktura nie czeka na wpłatę.');
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

    /** Po wpłacie (poza transakcją): faktura „opłacona” w Fakturowni, wznowienie wstrzymanego dostępu, podziękowanie dla uczestnika. */
    public static function afterPayment(): void
    {
        $ids = array_keys(self::$afterPay);
        self::$afterPay = [];
        $subs = [];
        foreach ($ids as $id) {
            try {
                $ch0 = self::charge($id);
                $sub0 = $ch0 ? self::subscription((int)$ch0['subscription_id']) : null;
                if ($sub0 && $sub0['status'] === 'requested') {   // wniosek: fakturę VAT wystawia Helpdesk (karta w zgłoszeniu)
                    require_once __DIR__ . '/LicenceRequest.php';
                    LicenceRequest::note((int)$sub0['helpdesk_ticket_id'], 'Wpłata ' . self::zl((float)$ch0['amount_pln']) . ' dotarła (' . ($ch0['paid_via'] ?: 'wpłata') . '). Następny krok: Wystaw fakturę VAT, potem Aktywuj dostęp.');
                } else {
                    require_once __DIR__ . '/ClaudeInvoice.php';
                    ClaudeInvoice::afterPaid($id);
                }
            } catch (\Throwable $e) {
                db_exec("UPDATE claude_charges SET invoice_error=? WHERE id=?", [mb_substr($e->getMessage(), 0, 500), $id]);
            }
            $ch = self::charge($id);
            if ($ch) { $subs[(int)$ch['subscription_id']] = true; self::notifyParticipant(self::subscription((int)$ch['subscription_id']) ?? [], 'wplata', $ch); }
        }
        foreach (array_keys($subs) as $sid) {
            try { self::maybeResume($sid, date('Y-m-d')); } catch (\Throwable $e) { error_log('licencje resume: ' . $e->getMessage()); }
        }
    }

    // ── Pilnowanie płatności (CRON) ─────────────────────────────────────────────

    /** Zaległe naliczenia, po których dostęp się wstrzymuje (termin + karencja minęły). */
    public static function overdue(array $sub, string $today, ?array $s = null): array
    {
        $s ??= self::settings(self::productOf($sub));
        $limit = date('Y-m-d', strtotime($today . ' -' . $s['grace_days'] . ' day'));
        return db_all("SELECT * FROM claude_charges WHERE subscription_id=? AND status='pending' AND due_on < ?", [(int)$sub['id'], $limit]);
    }

    private static function maybeResume(int $subId, string $today): bool
    {
        $sub = self::subscription($subId);
        if (!$sub || $sub['status'] !== 'suspended' || self::overdue($sub, $today)) return false;
        db_exec("UPDATE claude_subscriptions SET status='active', suspended_at=NULL, cycle_start=?, updated_at=datetime('now') WHERE id=?", [$today, $subId]);
        self::event($subId, null, 'resumed', 'Zaległość uregulowana — dostęp wznowiony od ' . self::date($today) . ' (nowy cykl od tego dnia).', 'system');
        $sub = self::subscription($subId);
        self::helpdesk($sub, 'przywrocenie', ['name' => 'system', 'via' => 'płatności'], null);
        self::notifyParticipant($sub, 'wznowienie', null);
        return true;
    }

    /**
     * Przebieg dzienny: naliczenia (w dniu naliczenia), wpłaty z Fakturowni, przypomnienia w dniu terminu, wstrzymanie po karencji, wznowienie.
     * $today pozwala symulować datę (strona testu); $onlyTest — tylko abonamenty testowe. @return array{charges:int,reminders:int,suspended:int,resumed:int,errors:list<string>,log:list<string>}
     */
    public static function run(?string $today = null, bool $onlyTest = false, ?int $onlySub = null): array
    {
        self::migrate();
        $today ??= date('Y-m-d');
        $rep = ['charges' => 0, 'reminders' => 0, 'suspended' => 0, 'resumed' => 0, 'errors' => [], 'log' => []];
        if (!$onlyTest && !$onlySub) {   // zmiany cen w dniu wejścia w życie (przed naliczeniami — nowe dokumenty już po nowej cenie)
            require_once __DIR__ . '/PriceChange.php';
            try { foreach (PriceChange::applyDue($today) as $l) $rep['log'][] = $l; } catch (\Throwable $e) { $rep['errors'][] = 'zmiana ceny: ' . $e->getMessage(); }
        }
        $w = ["status IN ('requested','active','suspended')"]; $p = [];
        if ($onlyTest) $w[] = 'is_test=1';
        if ($onlySub) { $w[] = 'id=?'; $p[] = $onlySub; }
        require_once __DIR__ . '/ClaudeInvoice.php';
        foreach (db_all("SELECT id FROM claude_subscriptions WHERE " . implode(' AND ', $w) . " ORDER BY id", $p) as $row) {
            $sid = (int)$row['id'];
            try {
                $sub = self::subscription($sid);
                $s = self::settings(self::productOf($sub));
                $L = self::P(self::productOf($sub))['label'];
                if ($sub['status'] === 'requested') {   // wniosek: tylko wpłaty (Szybki przelew w Fakturowni); resztę robi Helpdesk
                    self::syncFakturownia($sid, $rep);
                    self::afterPayment();
                    continue;
                }
                if ($sub['status'] === 'suspended') {
                    self::syncFakturownia($sid, $rep);   // może wpłynęło Szybkim przelewem
                    self::afterPayment();
                    if (self::maybeResume($sid, $today)) { $rep['resumed']++; $rep['log'][] = $sub['client_name'] . ' (' . $L . '): wznowiono'; }
                    continue;
                }
                if (!(int)$sub['free']) {   // naliczenia od miesiąca startu cyklu do bieżącego
                    $pEnd = substr($today, 0, 7);
                    for ($per = substr((string)$sub['cycle_start'], 0, 7); $per <= $pEnd; $per = date('Y-m', strtotime($per . '-01 +1 month'))) {
                        if (self::billingDate($sub, $per, $s) > $today) continue;
                        if (db_one("SELECT 1 FROM claude_charges WHERE subscription_id=? AND period=?", [$sid, $per])) continue;
                        $ch = self::createCharge($sub, $per, $today);
                        if ($ch) { $rep['charges']++; $rep['log'][] = $sub['client_name'] . ' (' . $L . '): naliczenie ' . self::periodLabel($per) . ' ' . self::zl((float)$ch['amount_pln']) . ' do ' . self::date($ch['due_on']); }
                    }
                }
                self::syncFakturownia($sid, $rep);
                self::afterPayment();
                $sub = self::subscription($sid);
                if ($sub['status'] !== 'active') continue;
                foreach (db_all("SELECT * FROM claude_charges WHERE subscription_id=? AND status='pending' AND due_on<=? AND reminded_at IS NULL", [$sid, $today]) as $ch) {
                    db_exec("UPDATE claude_charges SET reminded_at=datetime('now') WHERE id=?", [(int)$ch['id']]);
                    self::notifyParticipant($sub, 'przypomnienie', self::charge((int)$ch['id']));
                    self::event($sid, (int)$ch['id'], 'reminder', 'Przypomnienie o płatności za ' . self::periodLabel($ch['period']) . ' (termin ' . self::date($ch['due_on']) . ').', 'system');
                    $rep['reminders']++;
                }
                if ($od = self::overdue($sub, $today, $s)) {
                    db_exec("UPDATE claude_subscriptions SET status='suspended', suspended_at=datetime('now'), updated_at=datetime('now') WHERE id=? AND status='active'", [$sid]);
                    $sum = array_sum(array_map(fn($c) => (float)$c['amount_pln'], $od));
                    $txt = 'Brak wpłaty ' . self::zl($sum) . ' (najstarszy termin ' . self::date($od[0]['due_on']) . ', karencja ' . $s['grace_days'] . ' dni) — dostęp wstrzymany.';
                    self::event($sid, null, 'suspended', $txt, 'system');
                    $sub = self::subscription($sid);
                    self::helpdesk($sub, 'wstrzymanie', ['name' => 'system', 'via' => 'płatności'], null, $txt);
                    self::notifyParticipant($sub, 'wstrzymanie', $od[0]);
                    self::notifyOffice($sub, 'Wstrzymano ' . $L . ' — ' . $sub['client_name'], '<p><b>' . h((string)$sub['client_name']) . '</b>: ' . h($txt) . '</p>');
                    $rep['suspended']++; $rep['log'][] = $sub['client_name'] . ' (' . $L . '): wstrzymano';
                }
            } catch (\Throwable $e) {
                $rep['errors'][] = '#' . $sid . ': ' . $e->getMessage();
            }
        }
        if (!$onlyTest) {   // faktury, których nie udało się wystawić od razu (Fakturownia nie odpowiadała, brakowało danych)
            require_once __DIR__ . '/ClaudeInvoice.php';
            $simSql = ClaudeInvoice::live() ? " OR proforma_number LIKE 'SYM-%' OR order_number LIKE 'SYM-%' OR invoice_number LIKE 'SYM-%'" : '';   // Fakturownia działa — zastąp symulacje
            foreach (db_all("SELECT id, product FROM claude_charges WHERE ((status='pending' AND proforma_id IS NULL AND invoice_id IS NULL) OR (status='paid' AND invoice_id IS NULL AND subscription_id NOT IN (SELECT id FROM claude_subscriptions WHERE status='requested'))$simSql) AND status<>'cancelled' AND is_test=0 AND created_at >= date('now','-60 day') LIMIT 20") as $r) {
                if (self::settings((string)$r['product'])['invoice'] !== 'auto') continue;
                try { ClaudeInvoice::issueForCharge((int)$r['id']); } catch (\Throwable $e) { $rep['errors'][] = 'faktura #' . $r['id'] . ': ' . $e->getMessage(); }
            }
        }
        return $rep;
    }

    /** Wpłaty Szybkim przelewem (albo oznaczone w Fakturowni) — faktura „opłacona” zamyka naliczenie. */
    private static function syncFakturownia(int $sid, array &$rep): void
    {
        foreach (db_all("SELECT id FROM claude_charges WHERE subscription_id=? AND status='pending' AND (proforma_id IS NOT NULL OR invoice_id IS NOT NULL) AND is_test=0", [$sid]) as $r) {
            if (!ClaudeInvoice::paidInFakturownia((int)$r['id'])) continue;
            $ch = self::charge((int)$r['id']);
            if ($ch['pp_item_id']) {
                $it = db_one("SELECT status FROM payable_items WHERE id=?", [(int)$ch['pp_item_id']]);
                if ($it && $it['status'] === 'processing') continue;   // równolegle trwa płatność w portalu — rozstrzygnie się w kolejnym przebiegu
                db_exec("UPDATE payable_items SET status='paid', paid_at=datetime('now') WHERE id=? AND status='pending'", [(int)$ch['pp_item_id']]);
            }
            self::settleCharges([(int)$r['id']], 'Szybki przelew (Fakturownia)', ($ch['proforma_number'] ? 'proforma ' . $ch['proforma_number'] : 'faktura ' . $ch['invoice_number']), 'Fakturownia');
            $rep['log'][] = $ch['client_name'] . ': wpłata w Fakturowni za ' . self::periodLabel($ch['period']);
        }
    }

    // ── Helpdesk i powiadomienia ────────────────────────────────────────────────

    /** Zgłoszenie dla IT: aktywacja / dezaktywacja (rezygnacja) / wstrzymanie (zaległość) / przywrócenie. */
    public static function helpdesk(array $sub, string $kind, array $actor, ?array $charge = null, string $why = ''): ?string
    {
        $product = self::productOf($sub);
        $P = self::P($product);
        $s = self::settings($product);
        if (!$s['helpdesk'] && !(int)$sub['is_test'] && $kind !== 'wniosek') return null;   // wniosek z kreatora zawsze idzie do Helpdesku (tam się go załatwia)
        $titles = ['wniosek' => 'Wniosek: ' . $P['label'], 'aktywacja' => 'Włącz ' . $P['label'], 'dezaktywacja' => 'Wyłącz ' . $P['label'] . ' (rezygnacja)',
                   'wstrzymanie' => 'Wstrzymaj ' . $P['label'] . ' (brak wpłaty)', 'przywrocenie' => 'Przywróć ' . $P['label'] . ' (wpłata dotarła)'];
        $lic = self::assignment((int)$sub['client_id'], $product);
        $acc = db_one("SELECT login, ms_upn FROM k30_ti_student_accounts WHERE client_id=? ORDER BY id DESC LIMIT 1", [(int)$sub['client_id']]) ?: [];
        $own = null;
        try { require_once dirname(__DIR__, 2) . '/license_login/logic/licenseLogin.php'; $own = LicenseLogin::get((int)$sub['client_id']); } catch (\Throwable $e) {}
        $title = ((int)$sub['is_test'] ? '[TEST] ' : '') . $titles[$kind] . ': ' . $sub['client_name'];
        $desc = $titles[$kind] . "\nOsoba: " . $sub['client_name'] . ' (uczestnik nr ' . $sub['client_id'] . ')'
            . ($sub['client_email'] ? "\nE-mail: " . $sub['client_email'] : '')
            . ($own ? "\nLogin do licencji (wygenerowany przez uczestnika): " . $own : '')
            . (!empty($lic['login']) ? "\nLogin licencji: " . $lic['login'] : (!empty($acc['ms_upn']) ? "\nKonto edu: " . $acc['ms_upn'] : ''))
            . ($lic ? "\nLicencja: " . $lic['license_name'] . ($lic['expires_at'] ? ' (ważna do ' . self::date($lic['expires_at']) . ')' : '') : '')
            . ($kind === 'wniosek' ? "\nWniosek z: " . self::date($sub['activated_on']) : "\nWłączony od: " . self::date($sub['activated_on']) . ', faktura co miesiąc ok. ' . self::dueDay($sub, $s) . '. dnia')
            . ($charge ? "\nPierwsza opłata: " . self::zl((float)$charge['amount_pln']) . ' do ' . self::date($charge['due_on']) . ($kind === 'wniosek' ? "\nProforma: " . ($charge['proforma_number'] ?: '(w przygotowaniu)') : '') : '')
            . ($kind === 'wniosek' ? "\nCo zrobić: w karcie „Wniosek o płatną licencję” (po prawej) po wpłacie kliknij „Wystaw fakturę VAT”, a potem „Aktywuj dostęp”. Wpłata przelewem: przycisk „Wpłata dotarła”." : '')
            . ($kind === 'dezaktywacja' ? "\nOpłacone do: " . self::date(self::paidThrough($sub)) . ' — wyłącz miejsce po tej dacie.' : '')
            . ($kind === 'wstrzymanie' ? "\nWyłącz miejsce do czasu wpłaty. Gdy pieniądze dotrą, system sam założy zgłoszenie „Przywróć”." : '')
            . ($why !== '' ? "\nPowód: " . $why : '')
            . "\nZgłosił: " . ($actor['name'] ?? '') . ' (' . ($actor['via'] ?? '') . ')';
        $virtual = !(int)$sub['is_test'] && self::isVirtual((int)$sub['client_id']);
        if ($virtual && $kind === 'wniosek') $title = '[TEST] ' . $title;   // wniosek konta testowego — prawdziwe zgłoszenie do przećwiczenia Helpdesku, bez e-maili
        if ($virtual && $kind !== 'wniosek') {   // konto testowe: pozostałe zgłoszenia tylko w historii
            self::event((int)$sub['id'], null, 'helpdesk', 'Zgłoszenie (konto testowe, symulacja, nie utworzono): ' . $title, 'system');
            if ($kind === 'aktywacja') db_exec("UPDATE claude_subscriptions SET helpdesk_number='SYMULACJA' WHERE id=?", [(int)$sub['id']]);
            return $kind === 'aktywacja' ? 'SYMULACJA' : null;
        }
        if ((int)$sub['is_test'] && !self::$testRealTickets) {
            self::$testTickets[] = ['title' => $title, 'desc' => $desc];
            self::event((int)$sub['id'], null, 'helpdesk', 'Zgłoszenie (test — nie utworzono): ' . $title, 'test');
            return null;
        }
        try {
            require_once dirname(__DIR__, 3) . '/includes/helpdesk.php';
            // aktywacja / rezygnacja — zgłasza uczestnik (dostaje potwierdzenia Helpdesku); wstrzymanie / przywrócenie — system płatności (bez maili do uczestnika)
            $sys = ($actor['via'] ?? '') === 'płatności';
            $tid = hd_ticket_quick_create(['id' => null, 'name' => $sys ? 'Płatne licencje (system)' : (string)$sub['client_name'], 'email' => $sys || $virtual ? '' : (string)($sub['client_email'] ?? '')],
                $title, $desc, 'it_licencje_am', 'normalny', $product . '_' . $kind);
            try {
                require_once dirname(__DIR__, 2) . '/helpdeskStatuses/lib/helpdeskStatuses.php'; hdSubstatusSet($tid, 'am_do_realizacji', 0, 'Płatne licencje');
                require_once dirname(__DIR__, 2) . '/helpdeskCollab/lib/helpdeskCollab.php'; hdTagAdd($tid, $product); hdTagAdd($tid, $product . '_' . $kind);
            } catch (\Throwable $e) {}
            $num = (string)(db_one("SELECT number FROM helpdesk_tickets WHERE id=?", [$tid])['number'] ?? '');
            if ($kind === 'aktywacja') db_exec("UPDATE claude_subscriptions SET helpdesk_number=? WHERE id=?", [$num, (int)$sub['id']]);
            if ($kind === 'wniosek') db_exec("UPDATE claude_subscriptions SET helpdesk_number=?, helpdesk_ticket_id=? WHERE id=?", [$num, $tid, (int)$sub['id']]);
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
            $base = defined('APP_URL') ? rtrim((string)APP_URL, '/') : '';
            $link = '<p><a href="' . h($base . '/karty30/ti/dydaktyk/claudeAbonament.php?product=' . self::productOf($sub)) . '">Panel dydaktyka › Płatne licencje</a></p>';
            foreach (LicenseNotificationService::officeRecipients() as $to) mail_queue_add($to, 'Biuro', '[' . self::P(self::productOf($sub))['label'] . '] ' . $subject, $html . $link, '', 'claude_billing', (int)$sub['id']);
        } catch (\Throwable $e) { error_log('licencje office: ' . $e->getMessage()); }
    }

    /** Adres panelu licencji uczestnika (kursant.feer.org.pl/licencje/). */
    public static function panelUrl(string $anchor = ''): string
    {
        $base = defined('APP_URL') ? rtrim((string)APP_URL, '/') : '';
        return $base . '/karty30/ti/kursant/licencje/' . ($anchor !== '' ? '#' . $anchor : '');
    }

    /** E-mail do uczestnika — prosto: za co, ile, do kiedy, jak zapłacić. Bez testów i małoletnich (rozliczenia prowadzi opiekun/biuro). */
    public static function notifyParticipant(array $sub, string $kind, ?array $ch, string $extra = ''): void
    {
        if (!$sub || (int)($sub['is_test'] ?? 0)) return;
        $product = self::productOf($sub);
        if (!self::settings($product)['notify']) return;
        $P = self::P($product);
        $c = self::client((int)$sub['client_id']);
        $to = trim((string)($c['email'] ?? ''));
        if (!$c || !filter_var($to, FILTER_VALIDATE_EMAIL)) return;
        $L = $P['label'];
        $url = self::panelUrl($product);
        $amt = $ch ? self::zl((float)$ch['amount_pln']) : '';
        $in = $ch ? self::periodIn((string)$ch['period']) : '';
        $due = $ch ? self::dayMonth((string)$ch['due_on']) : '';
        $acc = self::payAccount(0, $product);
        $docUrl = $ch ? (string)(($ch['status'] ?? '') === 'paid' ? ($ch['invoice_view_url'] ?? '') : (($ch['proforma_view_url'] ?? '') ?: ($ch['invoice_view_url'] ?? ''))) : '';
        $why = $ch ? '<p style="color:#555;font-size:13px">Skąd ta kwota? ' . h(self::breakdown($ch)) . ' (kurs PKO BP z ' . h(self::dayMonth(substr((string)$ch['rate_at'], 0, 10))) . ')'
            . ((float)$ch['list_pln'] > (float)$ch['amount_pln'] ? '. Normalnie ' . h($L) . ' kosztuje ' . h(self::usdWords((float)$ch['list_usd'])) . ' — jako organizacja non-profit płacimy mniej i tę zniżkę przekazujemy Tobie.' : '.') . '</p>' : '';
        $how = $ch ? '<p><b>Jak zapłacić</b> (wybierz jedno):</p><ul>'
            . '<li>Przelewy24 — w panelu, przycisk „Zapłać”,</li>'
            . ($docUrl !== '' ? '<li>BLIK albo szybki przelew — przycisk na <a href="' . h($docUrl) . '">' . ($ch['proforma_view_url'] ? 'proformie' : 'fakturze') . '</a>,</li>' : '')
            . ($acc ? '<li>zwykły przelew na konto <b>' . h($acc['nrb_fmt']) . '</b>, w tytule: <b>' . h(self::transferTitle($ch)) . '</b>.</li>' : '') . '</ul>' : '';
        [$subject, $body] = match ($kind) {
            'naliczenie'    => [$L . ': opłata ' . $in . ' — ' . $amt . ' do ' . $due,
                                '<p>Twoja proforma za ' . h($L) . ' ' . h($in) . ' jest gotowa: <b>' . h($amt) . '</b>, płatna do <b>' . h($due) . '</b>.'
                                . ($docUrl !== '' ? ' <a href="' . h($docUrl) . '">' . ($ch['proforma_view_url'] ? 'Zobacz proformę' : 'Zobacz fakturę') . '</a> (fakturę wystawimy po wpłacie).' : '') . '</p>' . $how . $why],
            'przypomnienie' => [$L . ': czekamy na wpłatę ' . $amt,
                                '<p>Nie widzimy jeszcze wpłaty za ' . h($L) . ' ' . h($in) . ' (' . h($amt) . ', termin: ' . h($due) . ').</p>'
                                . '<p>Jeśli już zapłacono — dziękujemy, wpłata pojawi się u nas w ciągu 1–2 dni roboczych. Jeśli nie — zapłać w ciągu kilku dni, inaczej będziemy musieli wstrzymać dostęp.</p>' . $how],
            'wplata'        => ['Dziękujemy — wpłata za ' . $L . ' dotarła',
                                '<p>Dostaliśmy <b>' . h($amt) . '</b> za ' . h($L) . ' ' . h($in) . '. Dziękujemy!'
                                . (!empty($ch['invoice_number']) ? ' Faktura ' . h((string)$ch['invoice_number']) . ' jest oznaczona jako opłacona.' : '') . '</p>'],
            'wstrzymanie'   => [$L . ' wstrzymany — brak wpłaty',
                                '<p>Wstrzymaliśmy Twój dostęp do ' . h($L) . ', bo nie dotarła wpłata za ' . h(self::periodLabel((string)($ch['period'] ?? ''))) . ' (' . h($amt) . ').</p>'
                                . '<p>Gdy tylko zapłacisz, włączymy go z powrotem — nie musisz nic zgłaszać.</p>' . $how],
            'wznowienie'    => [$L . ' wraca — dziękujemy',
                                '<p>Dziękujemy za wpłatę. Dział IT włącza Twój dostęp do ' . h($L) . ' z powrotem — zwykle w ciągu jednego dnia roboczego.</p>'],
            'aktywny'       => [$L . ': dostęp włączony',
                                '<p>Helpdesk włączył Twój dostęp do ' . h($L) . '. Fakturę znajdziesz w panelu licencji.</p>'
                                . '<p>Kolejne opłaty: co miesiąc dostaniesz proformę, masz ' . self::TERM_DAYS . ' dni na wpłatę. Zrezygnować możesz w każdej chwili w panelu.</p>'],
            'odrzucony'     => ['Wniosek o ' . $L . ' odrzucony',
                                '<p>Twój wniosek o ' . h($L) . ' został odrzucony. Powód: ' . h($extra) . '</p><p>Proforma do tego wniosku jest nieaktualna, nie płać jej.</p>'],
            'dane_fv'       => ['Brakuje danych do faktury za ' . $L,
                                '<p>Żeby wystawić fakturę za ' . h($L) . ', potrzebujemy jeszcze: <b>' . h($extra) . '</b>. Uzupełnisz to w panelu w minutę — faktura wystawi się sama.</p>'],
            default         => ['', ''],
        };
        if ($subject === '') return;
        $html = '<p>Dzień dobry,</p>' . $body . '<p><a href="' . h($url) . '" style="background:#0f6fa8;color:#fff;padding:10px 22px;text-decoration:none;display:inline-block;font-weight:600">Moje licencje</a></p>'
              . '<p style="color:#555;font-size:13px">Pytania? Odpowiedz na tę wiadomość albo napisz do prowadzącego w panelu.</p>';
        try {
            require_once dirname(__DIR__, 3) . '/includes/mail_queue.php';
            mail_queue_add($to, (string)$c['name'], $subject, $html, '', 'claude_billing', $ch ? (int)$ch['id'] : (int)$sub['id']);
        } catch (\Throwable $e) { error_log('licencje notify: ' . $e->getMessage()); }
    }

    // ── Rachunek do przelewu ────────────────────────────────────────────────────

    /** Rachunek do wpłat (ustawienie; domyślnie 88 1020 2906 0000 1302 0661 7932). @return ?array{nrb:string,nrb_fmt:string,kind:string} */
    public static function payAccount(int $clientId = 0, string $product = 'claude'): ?array
    {
        $nrb = self::settings($product)['bank_account'];
        if (strlen($nrb) !== 26) return null;
        return ['nrb' => $nrb, 'nrb_fmt' => trim(substr($nrb, 0, 2) . ' ' . implode(' ', str_split(substr($nrb, 2), 4))), 'kind' => 'org'];
    }

    /** Tytuł przelewu tradycyjnego dla naliczenia. */
    public static function transferTitle(array $ch): string
    {
        $doc = self::docRef($ch);   // numer proformy albo faktury — wymagany w tytule
        return ($doc !== '' ? $doc : 'NAL/' . (int)$ch['id']) . ' ' . self::P(self::productOf($ch))['label'] . ' ' . self::periodLabel((string)$ch['period']);
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

    /** Lista abonamentów produktu z podsumowaniem należności (panel kierownika). */
    public static function listAll(string $filter = '', bool $test = false, string $product = 'claude'): array
    {
        self::migrate();
        $w = ['s.is_test=?', 's.product=?']; $p = [$test ? 1 : 0, self::product($product)];
        if (isset(self::STATUS[$filter])) { $w[] = 's.status=?'; $p[] = $filter; }
        return db_all("SELECT s.*, c.name AS client_name, c.email AS client_email,
                (SELECT COUNT(*) FROM claude_charges x WHERE x.subscription_id=s.id AND x.status='pending') AS n_pending,
                (SELECT COALESCE(SUM(amount_pln),0) FROM claude_charges x WHERE x.subscription_id=s.id AND x.status='pending') AS sum_pending,
                (SELECT MIN(due_on) FROM claude_charges x WHERE x.subscription_id=s.id AND x.status='pending') AS next_due,
                (SELECT COUNT(*) FROM claude_charges x WHERE x.subscription_id=s.id AND x.status='paid') AS n_paid
              FROM claude_subscriptions s LEFT JOIN k30_clients c ON c.id=s.client_id
             WHERE " . implode(' AND ', $w) . " ORDER BY CASE s.status WHEN 'suspended' THEN 0 WHEN 'active' THEN 1 ELSE 2 END, c.name", $p);
    }

    public static function stats(string $product = 'claude'): array
    {
        self::migrate();
        $pr = self::product($product);
        $r = db_one("SELECT SUM(status='active') a, SUM(status='suspended') s, SUM(status='ended') e FROM claude_subscriptions WHERE is_test=0 AND product=?", [$pr]) ?: [];
        $c = db_one("SELECT SUM(status='pending') np, COALESCE(SUM(CASE WHEN status='pending' THEN amount_pln END),0) sp,
                            SUM(status='pending' AND due_on < date('now')) no, COALESCE(SUM(CASE WHEN status='paid' AND paid_at >= date('now','start of month') THEN amount_pln END),0) pm,
                            SUM((status='pending' AND proforma_id IS NULL AND invoice_id IS NULL) OR (status='paid' AND invoice_id IS NULL)) ni
                       FROM claude_charges WHERE is_test=0 AND product=?", [$pr]) ?: [];
        $gaps = 0;
        foreach (db_all("SELECT * FROM claude_subscriptions WHERE is_test=0 AND product=? AND status IN ('active','suspended')", [$pr]) as $sb) if (self::invoiceDataGaps($sb)) $gaps++;
        return ['buyer_gaps' => $gaps, 'active' => (int)($r['a'] ?? 0), 'suspended' => (int)($r['s'] ?? 0), 'ended' => (int)($r['e'] ?? 0), 'pending' => (int)($c['np'] ?? 0),
                'pending_sum' => (float)($c['sp'] ?? 0), 'overdue' => (int)($c['no'] ?? 0), 'paid_month' => (float)($c['pm'] ?? 0), 'no_invoice' => (int)($c['ni'] ?? 0)];
    }

    /**
     * Projekcja: ta płatność (otwarte naliczenie albo — przed włączeniem — pierwsza, dziś) i następna (dzień naliczenia kolejnego miesiąca),
     * kwota szacowana po ostatnim zapisanym kursie PKO BP. @return array{now:?array{amount:float,due:string,issue:string,estimate:bool},next:?array{amount:float,due:string,issue:string,estimate:bool}}
     */
    public static function projection(?array $sub, string $product = 'claude', ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $s = self::settings($product);
        $r = self::lastRate($product);
        $est = $r ? (float)$r['amount_pln'] : null;
        $out = ['now' => null, 'next' => null];
        $open = $sub && in_array($sub['status'], ['active', 'suspended'], true);
        if (!$open) {   // przed włączeniem: pierwsza faktura dziś (albo w dniu, od którego przyjmujemy wnioski), następna w dniu naliczenia kolejnego miesiąca
            if ($est === null) return $out;
            if ($s['available_from'] !== '' && $s['available_from'] > $today) $today = $s['available_from'];
            $fake = ['cycle_start' => $today, 'due_day' => null, 'product' => $product];
            $out['now'] = ['amount' => $est, 'issue' => $today, 'due' => date('Y-m-d', strtotime($today . ' +' . self::TERM_DAYS . ' day')), 'estimate' => true];
            $np = date('Y-m', strtotime(substr($today, 0, 7) . '-01 +1 month'));
            $out['next'] = ['amount' => $est, 'issue' => self::billingDate($fake, $np, $s), 'due' => self::dueFor($fake, $np, $s), 'estimate' => true];
            return $out;
        }
        if ((int)$sub['free']) return $out;
        $pend = db_one("SELECT * FROM claude_charges WHERE subscription_id=? AND status='pending' ORDER BY due_on LIMIT 1", [(int)$sub['id']]);
        if ($pend) $out['now'] = ['amount' => (float)$pend['amount_pln'], 'issue' => (string)$pend['issue_on'], 'due' => (string)$pend['due_on'], 'estimate' => false];
        $last = db_one("SELECT MAX(period) p FROM claude_charges WHERE subscription_id=?", [(int)$sub['id']]);
        $np = $last && $last['p'] ? date('Y-m', strtotime($last['p'] . '-01 +1 month')) : substr((string)$sub['cycle_start'], 0, 7);
        if ($est !== null && $sub['status'] === 'active') $out['next'] = ['amount' => $est, 'issue' => self::billingDate($sub, $np, $s), 'due' => self::dueFor($sub, $np, $s), 'estimate' => true];
        return $out;
    }

    /** Treść komunikatu dla uczestników (Komunikaty TI) o możliwości wnioskowania — prostym językiem. @return array{title:string,body:string,expires:string} */
    public static function noticeText(string $product = 'claude'): array
    {
        $P = self::P($product);
        $s = self::settings($product);
        $from = $s['available_from'] !== '' && $s['available_from'] > date('Y-m-d') ? $s['available_from'] : date('Y-m-d');
        $r = self::lastRate($product);
        $title = $P['label'] . ' — możesz go włączyć' . ($from > date('Y-m-d') ? ' od ' . self::dayMonth($from) : '') . ' w panelu „Moje licencje”';
        $body = ($from > date('Y-m-d') ? 'Od ' . self::dayMonth($from) : 'Od dziś') . ' możesz sam(a) włączyć ' . $P['what'] . ' w panelu „Moje licencje” (kursant.feer.org.pl/licencje).' . "\n\n"
            . 'Ile to kosztuje: ' . self::usdWords($s['usd']) . ' miesięcznie po kursie PKO BP' . self::extrasWords($s) . ($r ? ' — dziś razem ok. ' . self::zl((float)$r['amount_pln']) : '') . '.'
            . ($s['list_usd'] > $s['usd'] ? ' To cena dla organizacji non-profit (normalnie ' . self::usdWords($s['list_usd']) . ').' : '') . "\n"
            . 'Jak to działa: po włączeniu dostajesz proformę, płacisz w ' . self::TERM_DAYS . ' dni (Przelewy24, przelew albo BLIK), a po wpłacie — fakturę. Kolejne opłaty co miesiąc. Zrezygnować możesz w każdej chwili.' . "\n"
            . ($s['available_until'] !== '' ? 'Zamawiać możesz do ' . self::dayMonth($s['available_until']) . '. ' : '')
            . 'Warunek: przydzielona licencja ' . $P['label'] . '. Nie masz jej, a chcesz korzystać? Napisz do prowadzącego.';
        return ['title' => $title, 'body' => $body, 'expires' => date('Y-m-d', strtotime($from . ' +30 day'))];
    }

    /** Publikuje komunikat w panelu uczestników (k30_ti_notices, przypięty, 30 dni od daty); e-mail do wszystkich tylko na życzenie. */
    public static function publishNotice(string $product, bool $email, array $actor): int
    {
        require_once dirname(__DIR__, 3) . '/includes/ti_notices.php';
        ti_notices_migrate();
        $n = self::noticeText($product);
        $id = db_insert('k30_ti_notices', ['title' => $n['title'], 'body' => $n['body'], 'audience' => 'all', 'is_pinned' => 1, 'is_active' => 1,
            'expires_at' => $n['expires'], 'author_id' => ($actor['id'] ?? 0) ?: null, 'author_name' => (string)($actor['name'] ?? '')]);
        if ($email) ti_notices_send_email($id, $n['title'], $n['body']);
        self::event(null, null, 'notice', self::P($product)['label'] . ': opublikowano komunikat #' . $id . ' „' . $n['title'] . '”' . ($email ? ' (z e-mailem do uczestników)' : '') . '.', (string)($actor['name'] ?? ''));
        return $id;
    }

    /** Dane do sekcji produktu w panelu uczestnika. */
    public static function participantView(int $clientId, string $product = 'claude'): array
    {
        self::migrate();
        $product = self::product($product);
        $s = self::settings($product);
        $sub = self::forClient($clientId, false, $product);
        $el = self::eligibility($clientId, $product);
        return ['product' => $product, 'P' => self::P($product), 'settings' => $s, 'sub' => $sub, 'charges' => $sub ? self::charges((int)$sub['id']) : [], 'eligible' => $el,
                'hasLicense' => $el['assignment'] !== null, 'rate' => self::lastRate($product), 'paidThrough' => $sub ? self::paidThrough($sub) : null,
                'account' => self::payAccount($clientId, $product), 'gaps' => $sub ? self::invoiceDataGaps($sub) : [], 'projection' => self::projection($sub, $product)];
    }
}
