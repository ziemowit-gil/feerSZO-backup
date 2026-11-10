<?php
declare(strict_types=1);

/**
 * modules/claude_billing/logic/LicenceCard.php — płatności kartą za płatne licencje (Przelewy24, płatności cykliczne; polecenia 2026-11-10).
 *
 * Sposób płatności ustawia administrator: dla produktu (Ustawienia › „Sposób płatności”) i, wyjątkowo, dla jednego abonamentu.
 *   • faktura i przelew (dotychczas): proforma → wpłata → faktura VAT,
 *   • karta, automatycznie: kartę wskazuje uczestnik w Panelu Licencji. Najpierw zgoda (treść poniżej, kod SMS), potem opłata weryfikacyjna
 *     karty 1,23 zł (osobne naliczenie kind='card', faktura VAT „Opłata weryfikacyjna karty płatniczej”), z niej numer referencyjny karty
 *     (card/info). Co miesiąc: naliczenie bez proformy, e-mail z kwotą i dniem obciążenia, w dniu terminu card/charge, po wpłacie faktura VAT.
 *     Trzy nieudane próby (albo brak karty) — powrót do proformy i przelewu dla tego naliczenia.
 * Zgoda: tekst, data i godzina, IP, przeglądarka, sposób potwierdzenia → PDF w teczce uczestnika (Dokumenty kursanta, kategoria „zgoda”)
 * i w uploads/licencje_zgody/. Wycofanie zgody (panel albo kadra) odpina kartę; nie jest wypowiedzeniem umowy.
 * Konto testowe (wirtualne): wszystko w symulacji (karta SIM, bez Przelewy24).
 */
require_once __DIR__ . '/claudeBilling.php';

final class LicenceCard
{
    public const PAY_MODES = ['invoice' => 'faktura i przelew (proforma, wpłata ręczna)', 'card' => 'karta, pobieranie automatyczne (FVAT po każdej wpłacie)'];
    public const CHECKBOX = 'Wyrażam zgodę na obciążanie mojej karty płatniczej w celu pobierania przez Fundację Edukacji Empatii Rozwoju „FEER”, za pośrednictwem operatora płatności Przelewy24, comiesięcznej opłaty za usługę {usluga} w wysokości wyświetlonej mi w panelu przed udzieleniem zgody.';
    public const INFO = [
        'Zgoda obejmuje cykliczne, comiesięczne obciążanie karty płatniczej wskazanej przeze mnie w procesie płatności kwotą opłaty za usługę {usluga} wyświetloną mi w panelu przed udzieleniem zgody, do czasu odwołania zgody. Przed zatwierdzeniem płatności zostaną mi przedstawione kwota oraz termin obciążenia. Zgoda nie obejmuje opłat za inne usługi ani kwot lub terminów innych niż przedstawione mi przed udzieleniem zgody.',
        'Mogę w każdej chwili wycofać zgodę na przyszłe obciążenia, za pośrednictwem {kanal}. Wycofanie zgody na płatności nie jest samo w sobie wypowiedzeniem ani rozwiązaniem umowy o usługę. Nie wpływa ono na należności, które stały się wymagalne przed skutecznym wycofaniem zgody.',
    ];
    public const BUTTON = 'Udzielam zgody i przechodzę do płatności';
    public const CHANNEL = 'Panelu Licencji (zakładka „Rozliczenia”, przycisk „Wycofaj zgodę i odepnij kartę”) albo wiadomości e-mail do biura Fundacji';
    public const MAX_ATTEMPTS = 3;

    public static function migrate(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        if (function_exists('szo_schema_current') && szo_schema_current('licence_card_migrate', __FILE__)) return;
        db()->exec("CREATE TABLE IF NOT EXISTS license_cards (
            id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER NOT NULL,
            ref_id TEXT NOT NULL DEFAULT '', mask TEXT NOT NULL DEFAULT '', card_type TEXT NOT NULL DEFAULT '', card_date TEXT NOT NULL DEFAULT '', bin TEXT NOT NULL DEFAULT '', hash TEXT NOT NULL DEFAULT '',
            status TEXT NOT NULL DEFAULT 'active', consent_id INTEGER, verify_charge_id INTEGER, p24_order_id TEXT NOT NULL DEFAULT '', is_sim INTEGER NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, removed_at DATETIME, removed_by TEXT NOT NULL DEFAULT '', removed_reason TEXT NOT NULL DEFAULT '')");
        db()->exec("CREATE INDEX IF NOT EXISTS idx_lic_cards_client ON license_cards(client_id, status)");
        db()->exec("CREATE TABLE IF NOT EXISTS license_card_consents (
            id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER NOT NULL, subscription_id INTEGER, product TEXT NOT NULL DEFAULT 'claude',
            person_name TEXT NOT NULL DEFAULT '', amount_pln REAL NOT NULL DEFAULT 0, verify_pln REAL NOT NULL DEFAULT 0, debit_info TEXT NOT NULL DEFAULT '',
            text TEXT NOT NULL DEFAULT '', sha256 TEXT NOT NULL DEFAULT '', confirmed_by TEXT NOT NULL DEFAULT '',
            ip TEXT NOT NULL DEFAULT '', user_agent TEXT NOT NULL DEFAULT '', accepted_at DATETIME NOT NULL,
            file_path TEXT NOT NULL DEFAULT '', sfile_id INTEGER, card_id INTEGER,
            withdrawn_at DATETIME, withdrawn_ip TEXT NOT NULL DEFAULT '', withdrawn_by TEXT NOT NULL DEFAULT '')");
        db()->exec("CREATE INDEX IF NOT EXISTS idx_lic_consents_client ON license_card_consents(client_id)");
    }

    // ── Odczyt ─────────────────────────────────────────────────────────────────

    /** Aktywna karta uczestnika albo null. */
    public static function active(int $cid): ?array
    {
        self::migrate();
        return db_one("SELECT * FROM license_cards WHERE client_id=? AND status='active' ORDER BY id DESC LIMIT 1", [$cid]) ?: null;
    }

    public static function consent(int $id): ?array
    {
        self::migrate();
        return db_one("SELECT * FROM license_card_consents WHERE id=?", [$id]) ?: null;
    }

    /** Zgody uczestnika (najnowsze pierwsze). */
    public static function consents(int $cid): array
    {
        self::migrate();
        return db_all("SELECT * FROM license_card_consents WHERE client_id=? ORDER BY id DESC LIMIT 20", [$cid]);
    }

    /** „VISA **** 1234, ważna do 08/2028”. */
    public static function label(?array $card): string
    {
        if (!$card) return '';
        $m = preg_replace('/[^0-9*Xx]/', '', (string)$card['mask']);
        $last = substr(preg_replace('/\D/', '', $m), -4);
        $d = (string)$card['card_date'];
        $exp = preg_match('/^(\d{2})(\d{4})$/', $d, $x) ? $x[1] . '/' . $x[2] : '';
        return trim(($card['card_type'] !== '' ? $card['card_type'] . ' ' : '') . '**** ' . ($last ?: '????') . ($exp !== '' ? ', ważna do ' . $exp : '') . ((int)$card['is_sim'] ? ' (symulacja)' : ''));
    }

    /** Sposób płatności abonamentu: ustawienie abonamentu (gdy jest) albo produktu. */
    public static function mode(array $sub): string
    {
        $m = (string)($sub['pay_mode'] ?? '');
        if (isset(self::PAY_MODES[$m])) return $m;
        $s = ClaudeBilling::settings(ClaudeBilling::productOf($sub));
        return isset(self::PAY_MODES[$s['pay_mode'] ?? '']) ? (string)$s['pay_mode'] : 'invoice';
    }

    /** „Tylko karta” (ustawienie produktu): bez przejścia na proformę i przelew. */
    public static function cardOnly(array $sub): bool
    {
        return self::mode($sub) === 'card' && !empty(ClaudeBilling::settings(ClaudeBilling::productOf($sub))['card_only']);
    }

    /** Naliczenie w trybie karty: abonament w trybie „karta” i (karta podpięta albo wniosek — kartę podepnie zaraz po wniosku; albo „tylko karta”). */
    public static function chargeInCardMode(array $sub): bool
    {
        if (self::mode($sub) !== 'card') return false;
        return self::active((int)$sub['client_id']) !== null || $sub['status'] === 'requested' || self::cardOnly($sub);
    }

    /** Teksty zgody dla produktu. @return array{checkbox:string,info:list<string>,button:string} */
    public static function texts(string $product): array
    {
        $r = ['{usluga}' => ClaudeBilling::P($product)['label'], '{kanal}' => self::CHANNEL];
        return ['checkbox' => strtr(self::CHECKBOX, $r), 'info' => array_map(fn($t) => strtr($t, $r), self::INFO), 'button' => self::BUTTON];
    }

    /** Kwoty pokazywane przed zgodą: opłata weryfikacyjna teraz, opłata miesięczna (najbliższa / bieżąca) i dzień obciążenia. */
    public static function preview(array $sub): array
    {
        $p = ClaudeBilling::productOf($sub);
        $s = ClaudeBilling::settings($p);
        $pending = db_one("SELECT * FROM claude_charges WHERE subscription_id=? AND kind='month' AND status='pending' ORDER BY id LIMIT 1", [(int)$sub['id']]);
        $q = $pending ?: ClaudeBilling::quote(null, $p);
        $nextPeriod = date('Y-m', strtotime(date('Y-m-01') . ' +1 month'));
        return ['verify' => (float)$s['card_fee_pln'], 'verify_name' => (string)$s['card_fee_name'], 'monthly' => (float)$q['amount_pln'],
                'first_now' => $pending !== null, 'first_period' => $pending ? (string)$pending['period'] : '', 'next_on' => ClaudeBilling::dueFor($sub, $nextPeriod, $s),
                'due_day' => ClaudeBilling::dueDay($sub, $s), 'term' => ClaudeBilling::TERM_DAYS];
    }

    // ── Zgoda i podpięcie karty ────────────────────────────────────────────────

    /**
     * Uczestnik udzielił zgody (po kodzie): zapis zgody i PDF w teczce, opłata weryfikacyjna karty i przekierowanie do Przelewy24 (tylko karty).
     * @return array{url:string,consent:int,charge:int,sim:bool}
     */
    public static function begin(int $subId, array $actor, string $returnUrl): array
    {
        self::migrate();
        $sub = ClaudeBilling::subscription($subId);
        if (!$sub || !in_array($sub['status'], ['requested', 'active', 'suspended'], true)) throw new DomainException('Ten abonament nie jest aktywny.');
        if (self::mode($sub) !== 'card') throw new DomainException('Dla tej licencji płatność kartą jest wyłączona. Płacisz przelewem po proformie.');
        $cid = (int)$sub['client_id'];
        $virtual = ClaudeBilling::isVirtual($cid);
        require_once dirname(__DIR__, 3) . '/includes/p24.php';
        if (!$virtual && !p24_enabled()) throw new DomainException('Płatności kartą są chwilowo niedostępne. Zapłać przelewem albo spróbuj później.');
        $product = ClaudeBilling::productOf($sub);
        $pv = self::preview($sub);
        $consentId = self::storeConsent($sub, $pv, $actor);
        $ch = self::verificationCharge($sub, $pv['verify'], $consentId);
        if ($virtual) {   // symulacja: wpłata, faktura (SYM), karta SIM, od razu pierwsza opłata miesięczna
            ClaudeBilling::markPaid((int)$ch['id'], 'karta (symulacja)', 'SYM-KARTA-' . date('YmdHis'), ['name' => (string)($actor['name'] ?? ''), 'via' => 'panel licencji']);
            return ['url' => $returnUrl, 'consent' => $consentId, 'charge' => (int)$ch['id'], 'sim' => true];
        }
        $c = ClaudeBilling::client($cid) ?? [];
        $base = defined('APP_URL') ? rtrim((string)APP_URL, '/') : '';
        $o = p24_create_order('claude_charge', (int)$ch['id'], (float)$ch['amount_pln'], self::chargeTitle($ch), $returnUrl, $base . '/api/p24_webhook.php',
                              (string)($c['email'] ?? ''), ['channel' => 4096]);   // 4096 = tylko karty
        ClaudeBilling::event($subId, (int)$ch['id'], 'card_verify', 'Zgoda na płatności kartą (zgoda #' . $consentId . '); opłata weryfikacyjna ' . ClaudeBilling::zl((float)$ch['amount_pln']) . ' w Przelewy24.', (string)($actor['name'] ?? ''));
        return ['url' => (string)$o['url'], 'consent' => $consentId, 'charge' => (int)$ch['id'], 'sim' => false];
    }

    /** Naliczenie „opłata weryfikacyjna karty” (kind='card', okres RRRR-MM-Kn — nie koliduje z opłatami miesięcznymi). */
    private static function verificationCharge(array $sub, float $amount, int $consentId): array
    {
        $amount = round(max(0.01, $amount), 2);
        $n = 1 + (int)(db_one("SELECT COUNT(*) n FROM claude_charges WHERE subscription_id=? AND kind='card'", [(int)$sub['id']])['n'] ?? 0);
        $today = date('Y-m-d');
        $id = (int)db_insert('claude_charges', [
            'subscription_id' => (int)$sub['id'], 'product' => ClaudeBilling::productOf($sub), 'client_id' => (int)$sub['client_id'], 'period' => date('Y-m') . '-K' . $n,
            'due_on' => $today, 'issue_on' => $today, 'usd' => 0, 'list_usd' => 0, 'rate' => 0, 'rate_kind' => '', 'amount_pln' => $amount, 'list_pln' => $amount,
            'pct' => 0, 'pct_label' => '', 'fee_pln' => 0, 'base_pln' => $amount, 'status' => 'pending', 'is_test' => (int)$sub['is_test'],
            'kind' => 'card', 'pay_mode' => 'card', 'note' => 'Opłata weryfikacyjna karty (zgoda #' . $consentId . ').',
        ]);
        return ClaudeBilling::charge($id) ?? [];
    }

    public static function chargeTitle(array $ch): string
    {
        return ClaudeBilling::chargeTitle($ch);   // wspólny wzór tytułu płatności (ClaudeBilling::paymentTitle)
    }

    /** Zapis zgody: treść, data i godzina, IP, przeglądarka; PDF do uploads/licencje_zgody/ i do teczki uczestnika. */
    private static function storeConsent(array $sub, array $pv, array $actor): int
    {
        $cid = (int)$sub['client_id'];
        $product = ClaudeBilling::productOf($sub);
        $t = self::texts($product);
        $c = ClaudeBilling::client($cid) ?? ['name' => ''];
        $debit = 'Opłata weryfikacyjna karty: ' . ClaudeBilling::zl($pv['verify']) . ' teraz. Opłata miesięczna: ' . ClaudeBilling::zl($pv['monthly'])
            . ($pv['first_now'] ? ' za ' . ClaudeBilling::periodLabel($pv['first_period']) . ' zaraz po weryfikacji karty, potem' : '')
            . ' co miesiąc, w dniu terminu płatności (' . $pv['due_day'] . '. dnia miesiąca + ' . $pv['term'] . ' dni; najbliższy: ' . ClaudeBilling::date($pv['next_on']) . ').'
            . ' Kwota zależy od kursu PKO BP z dnia naliczenia; przed każdym obciążeniem dostaniesz e-mail z kwotą i dniem.';
        $text = $t['checkbox'] . "\n\n" . implode("\n\n", $t['info']);
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? ($actor['ip'] ?? ''));
        $ua = mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300);
        $at = date('Y-m-d H:i:s');
        $id = (int)db_insert('license_card_consents', [
            'client_id' => $cid, 'subscription_id' => (int)$sub['id'], 'product' => $product, 'person_name' => (string)$c['name'], 'amount_pln' => $pv['monthly'], 'verify_pln' => $pv['verify'],
            'debit_info' => $debit, 'text' => $text, 'sha256' => hash('sha256', $text . "\n" . $debit), 'confirmed_by' => (string)($actor['confirm'] ?? 'kod jednorazowy (Panel Licencji)'),
            'ip' => $ip, 'user_agent' => $ua, 'accepted_at' => $at,
        ]);
        try {
            $pdf = self::consentPdf(self::consent($id) ?? []);
            $dir = rtrim(UPLOAD_DIR, '/') . '/licencje_zgody';
            if (!is_dir($dir)) @mkdir($dir, 0750, true);
            if (!is_file($dir . '/.htaccess')) @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
            $name = 'zgoda-karta-' . $id . '-' . bin2hex(random_bytes(6)) . '.pdf';
            file_put_contents($dir . '/' . $name, $pdf);
            $sfile = 0;
            $acc = db_one("SELECT id FROM k30_ti_student_accounts WHERE client_id=? AND is_active=1 ORDER BY id DESC LIMIT 1", [$cid]);
            if ($acc) {
                require_once dirname(__DIR__, 2) . '/ti_student_folder/logic/studentFolder.php';
                $sfile = TiStudentFolder::addSystemFile((int)$acc['id'], 'zgoda', 'Zgoda na płatności kartą (' . ClaudeBilling::P($product)['label'] . ')', 'zgoda-platnosci-karta-' . $id . '.pdf', $pdf,
                    'Udzielona ' . date('d.m.Y H:i:s', strtotime($at)) . ', IP ' . $ip . ' (Panel Licencji).');
            }
            db_exec("UPDATE license_card_consents SET file_path=?, sfile_id=? WHERE id=?", ['licencje_zgody/' . $name, $sfile ?: null, $id]);
        } catch (\Throwable $e) { error_log('licencje zgoda pdf #' . $id . ': ' . $e->getMessage()); }
        ClaudeBilling::event((int)$sub['id'], null, 'card_consent', 'Zgoda na płatności kartą #' . $id . ' (' . $c['name'] . '), ' . date('d.m.Y H:i:s', strtotime($at)) . ', IP ' . $ip . '.', (string)($actor['name'] ?? ''));
        return $id;
    }

    /** PDF zgody: treść z polem wyboru, kwoty, data i godzina, IP, przeglądarka, sposób potwierdzenia, skrót SHA-256. */
    public static function consentPdf(array $z): string
    {
        require_once dirname(__DIR__, 3) . '/vendor/autoload.php';
        $tmp = rtrim(UPLOAD_DIR, '/') . '/mpdf_tmp';
        if (!is_dir($tmp)) @mkdir($tmp, 0755, true);
        $t = self::texts((string)$z['product']);
        $nr = '';
        try { require_once dirname(__DIR__, 2) . '/ti_licencje_kursant/logic/LicencjeId.php'; $nr = LicencjeId::licence((int)$z['client_id']); } catch (\Throwable $e) {}
        $row = fn(string $k, string $v) => '<tr><td style="padding:3px 8px 3px 0;color:#555;width:42%">' . h($k) . '</td><td style="padding:3px 0">' . $v . '</td></tr>';
        $html = '<div style="font-family:dejavusans;font-size:10pt;line-height:1.45">'
            . '<div style="font-size:8pt;color:#555">Fundacja Edukacji Empatii Rozwoju „FEER” · Panel Licencji</div>'
            . '<h1 style="font-size:15pt;margin:10px 0 4px">Zgoda na płatności kartą</h1>'
            . '<div style="font-size:9pt;color:#555;margin-bottom:12px">Zgoda nr ' . (int)$z['id'] . ' · usługa ' . h(ClaudeBilling::P((string)$z['product'])['label']) . '</div>'
            . '<p style="margin:0 0 6px"><span style="font-family:dejavusans">☑</span> ' . h($t['checkbox']) . '</p>'
            . '<p style="font-size:9pt;color:#333;margin:10px 0 4px"><b>Informacja wyświetlona przy zgodzie</b></p>';
        foreach ($t['info'] as $p) $html .= '<p style="font-size:9pt;margin:0 0 6px">' . h($p) . '</p>';
        $html .= '<p style="font-size:9pt;margin:10px 0 4px"><b>Kwoty pokazane przed udzieleniem zgody</b></p><p style="font-size:9pt;margin:0 0 6px">' . h((string)$z['debit_info']) . '</p>'
            . '<p style="font-size:9pt;margin:0 0 12px">Przycisk: „' . h($t['button']) . '”</p>'
            . '<table style="font-size:9pt;border-collapse:collapse;width:100%;border-top:0.3mm solid #999">'
            . $row('Osoba', h((string)$z['person_name'])) . $row('Numer w Panelu Licencji', h($nr ?: '—'))
            . $row('Zgoda udzielona', h(date('d.m.Y H:i:s', strtotime((string)$z['accepted_at']))) . ' (' . h(date_default_timezone_get()) . ')')
            . $row('Adres IP', h((string)$z['ip'] ?: '—')) . $row('Przeglądarka', '<span style="font-size:7.5pt">' . h((string)$z['user_agent'] ?: '—') . '</span>')
            . $row('Potwierdzenie', h((string)$z['confirmed_by'])) . $row('Skrót treści (SHA-256)', '<span style="font-size:7pt">' . h((string)$z['sha256']) . '</span>')
            . '</table>'
            . '<p style="font-size:8pt;color:#555;margin-top:14px">Dokument wygenerowany automatycznie w chwili udzielenia zgody. Wycofanie zgody: ' . h(self::CHANNEL) . '.</p></div>';
        $mpdf = new \Mpdf\Mpdf(['mode' => 'utf-8', 'format' => 'A4', 'margin_left' => 20, 'margin_right' => 20, 'margin_top' => 18, 'margin_bottom' => 18, 'default_font' => 'dejavusans', 'tempDir' => $tmp]);
        $mpdf->SetTitle('Zgoda na płatności kartą nr ' . (int)$z['id']);
        $mpdf->SetHTMLFooter('<div style="font-family:dejavusans;font-size:7pt;border-top:0.25mm solid #999;text-align:right">Zgoda nr ' . (int)$z['id'] . ' · strona {PAGENO}/{nbpg}</div>');
        $mpdf->WriteHTML($html);
        return $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
    }

    /** PDF zgody do pobrania. @return array{bytes:string,name:string} */
    public static function consentFile(int $id): array
    {
        $z = self::consent($id);
        if (!$z) throw new DomainException('Nie ma takiej zgody.');
        $full = rtrim(UPLOAD_DIR, '/') . '/' . (string)$z['file_path'];
        $bytes = $z['file_path'] !== '' && is_file($full) ? (string)file_get_contents($full) : self::consentPdf($z);
        return ['bytes' => $bytes, 'name' => 'zgoda-platnosci-karta-' . $id . '.pdf'];
    }

    // ── Po wpłacie (z ClaudeBilling::afterPayment) ─────────────────────────────

    /** Opłacona opłata weryfikacyjna: zapis karty (card/info), potem obciążenie zaległych opłat miesięcznych w trybie karty. */
    public static function afterVerification(int $chargeId): void
    {
        self::migrate();
        $ch = ClaudeBilling::charge($chargeId);
        if (!$ch || ($ch['kind'] ?? '') !== 'card' || $ch['status'] !== 'paid') return;
        if (db_one("SELECT 1 FROM license_cards WHERE verify_charge_id=?", [$chargeId])) return;
        $cid = (int)$ch['client_id'];
        $consentId = preg_match('/zgoda #(\d+)/', (string)$ch['note'], $m) ? (int)$m[1] : null;
        $sim = ClaudeBilling::isVirtual($cid) || str_starts_with((string)$ch['paid_ref'], 'SYM');
        $info = [];
        $order = '';
        if ($sim) {
            $info = ['refId' => 'SIM-' . bin2hex(random_bytes(8)), 'mask' => '************4242', 'cardType' => 'VISA', 'cardDate' => date('m', strtotime('+2 year')) . date('Y', strtotime('+2 year')), 'bin' => '424242', 'hash' => 'sim'];
        } else {
            require_once dirname(__DIR__, 3) . '/includes/p24.php';
            $p = db_one("SELECT order_id FROM p24_payments WHERE source_type='claude_charge' AND source_id=? AND status='paid' ORDER BY id DESC LIMIT 1", [$chargeId]);
            $order = (string)($p['order_id'] ?? '');
            $info = $order !== '' ? p24_card_info($order) : [];
            if (!$info) {
                ClaudeBilling::event((int)$ch['subscription_id'], $chargeId, 'card_error', 'Opłata weryfikacyjna wpłynęła, ale Przelewy24 nie podało numeru referencyjnego karty (np. zapłacono portfelem Google Pay/Apple Pay). Karta nie jest podpięta — opłaty idą przelewem.', 'Przelewy24');
                self::fallbackOpen((int)$ch['subscription_id'], 'karta nie została zapisana');
                return;
            }
        }
        db_exec("UPDATE license_cards SET status='replaced', removed_at=datetime('now'), removed_reason='nowa karta' WHERE client_id=? AND status='active'", [$cid]);
        db_exec("UPDATE claude_charges SET card_attempts=0, card_error='' WHERE client_id=? AND status='pending' AND kind='month' AND pay_mode='card'", [$cid]);   // nowa karta — próby od nowa
        $cardId = (int)db_insert('license_cards', ['client_id' => $cid, 'ref_id' => (string)$info['refId'], 'mask' => (string)($info['mask'] ?? ''), 'card_type' => (string)($info['cardType'] ?? ''),
            'card_date' => (string)($info['cardDate'] ?? ''), 'bin' => (string)($info['bin'] ?? ''), 'hash' => (string)($info['hash'] ?? ''), 'status' => 'active',
            'consent_id' => $consentId, 'verify_charge_id' => $chargeId, 'is_sim' => $sim ? 1 : 0] + ($order !== '' ? ['p24_order_id' => $order] : []));   // db_insert zamienia '' w kolumnach *_id na NULL
        if ($consentId) db_exec("UPDATE license_card_consents SET card_id=? WHERE id=?", [$cardId, $consentId]);
        $card = db_one("SELECT * FROM license_cards WHERE id=?", [$cardId]);
        ClaudeBilling::event((int)$ch['subscription_id'], $chargeId, 'card_saved', 'Karta podpięta: ' . self::label($card) . '. Kolejne opłaty pobieramy automatycznie.', 'Przelewy24');
        // zaległe opłaty miesięczne w trybie karty — od razu (uczestnik widział kwotę przed zgodą)
        foreach (db_all("SELECT ch.id FROM claude_charges ch JOIN claude_subscriptions s ON s.id=ch.subscription_id WHERE ch.client_id=? AND ch.kind='month' AND ch.status='pending'
                          AND s.status IN ('requested','active','suspended') ORDER BY ch.id", [$cid]) as $r) {
            $x = ClaudeBilling::charge((int)$r['id']);
            $sx = $x ? ClaudeBilling::subscription((int)$x['subscription_id']) : null;
            if (!$x || !$sx || self::mode($sx) !== 'card') continue;
            if (($x['pay_mode'] ?? '') !== 'card') {
                if ((string)$x['proforma_number'] !== '' || (int)$x['pp_item_id']) continue;   // ma już proformę / pozycję w portalu — płaci przelewem
                db_exec("UPDATE claude_charges SET pay_mode='card' WHERE id=?", [(int)$x['id']]);
            }
            if ((int)$x['due_on'] === 0 || (string)$x['due_on'] <= date('Y-m-d', strtotime('+' . ClaudeBilling::TERM_DAYS . ' day'))) self::chargeNow((int)$x['id'], 'system (po podpięciu karty)');
        }
    }

    // ── Obciążenie karty ───────────────────────────────────────────────────────

    /** Obciążenie zapisanej karty za naliczenie (card/charge). Wynik przyjdzie powiadomieniem P24. @return array{ok:bool,text:string} */
    public static function chargeNow(int $chargeId, string $by = 'system'): array
    {
        self::migrate();
        $ch = ClaudeBilling::charge($chargeId);
        if (!$ch || $ch['status'] !== 'pending' || ($ch['kind'] ?? '') !== 'month') return ['ok' => false, 'text' => 'Naliczenie nie czeka na wpłatę.'];
        $cid = (int)$ch['client_id'];
        $card = self::active($cid);
        if (!$card) { self::fallback($chargeId, 'brak podpiętej karty'); return ['ok' => false, 'text' => 'Brak podpiętej karty' . (($s0 = ClaudeBilling::subscription((int)$ch['subscription_id'])) && self::cardOnly($s0) ? ' — czekamy, aż uczestnik ją podepnie (tylko karta).' : ' — naliczenie przeszło na przelew.')]; }
        if ((int)$ch['card_attempts'] >= self::MAX_ATTEMPTS) { self::fallback($chargeId, self::MAX_ATTEMPTS . ' nieudane próby'); return ['ok' => false, 'text' => 'Wyczerpane próby obciążenia karty.']; }
        require_once dirname(__DIR__, 3) . '/includes/p24.php';
        $open = db_one("SELECT id FROM p24_payments WHERE source_type='claude_charge' AND source_id=? AND status='pending' AND order_id<>'' AND created_at >= datetime('now','-1 day')", [$chargeId]);
        if ($open) return ['ok' => true, 'text' => 'Obciążenie już trwa — czekamy na wynik z Przelewy24.'];
        db_exec("UPDATE claude_charges SET card_attempts=card_attempts+1, card_tried_at=datetime('now'), card_id=? WHERE id=?", [(int)$card['id'], $chargeId]);
        if ((int)$card['is_sim']) {
            ClaudeBilling::markPaid($chargeId, 'karta (symulacja)', 'SYM-KARTA-' . date('YmdHis'), ['name' => $by]);
            return ['ok' => true, 'text' => 'Symulacja: pobrano ' . ClaudeBilling::zl((float)$ch['amount_pln']) . ' z karty ' . self::label($card) . '.'];
        }
        if (!p24_enabled()) { db_exec("UPDATE claude_charges SET card_error=? WHERE id=?", ['Przelewy24 wyłączone', $chargeId]); return ['ok' => false, 'text' => 'Przelewy24 są wyłączone.']; }
        $base = defined('APP_URL') ? rtrim((string)APP_URL, '/') : '';
        try {
            $r = p24_card_charge('claude_charge', $chargeId, (float)$ch['amount_pln'], ClaudeBilling::chargeTitle($ch), $base . '/api/p24_webhook.php', (string)($ch['client_email'] ?? ''), (string)$card['ref_id']);
            db_exec("UPDATE claude_charges SET card_error='' WHERE id=?", [$chargeId]);
            ClaudeBilling::event((int)$ch['subscription_id'], $chargeId, 'card_charge', 'Obciążenie karty ' . self::label($card) . ': ' . ClaudeBilling::zl((float)$ch['amount_pln']) . ' (zlecenie P24 ' . $r['order_id'] . ', próba ' . ((int)$ch['card_attempts'] + 1) . ').', $by);
            return ['ok' => true, 'text' => 'Zlecono obciążenie karty (' . ClaudeBilling::zl((float)$ch['amount_pln']) . ').'];
        } catch (\Throwable $e) {
            db_exec("UPDATE claude_charges SET card_error=? WHERE id=?", [mb_substr($e->getMessage(), 0, 300), $chargeId]);
            ClaudeBilling::event((int)$ch['subscription_id'], $chargeId, 'card_error', 'Nie udało się obciążyć karty ' . self::label($card) . ' (próba ' . ((int)$ch['card_attempts'] + 1) . '): ' . $e->getMessage(), $by);
            if ((int)$ch['card_attempts'] + 1 >= self::MAX_ATTEMPTS) self::fallback($chargeId, self::MAX_ATTEMPTS . ' nieudane próby obciążenia karty');
            return ['ok' => false, 'text' => 'Nie udało się obciążyć karty: ' . $e->getMessage()];
        }
    }

    /** Naliczenie w trybie karty przechodzi na przelew: proforma + pozycja w portalu płatności + e-mail do uczestnika. */
    public static function fallback(int $chargeId, string $why): void
    {
        $ch = ClaudeBilling::charge($chargeId);
        if (!$ch || $ch['status'] !== 'pending' || ($ch['kind'] ?? '') !== 'month' || ($ch['pay_mode'] ?? '') !== 'card') return;
        $sub0 = ClaudeBilling::subscription((int)$ch['subscription_id']) ?? [];
        if ($sub0 && self::cardOnly($sub0)) {   // tylko karta: zostaje na karcie — prośba o inną kartę (raz na naliczenie), wstrzymanie jak zwykle po karencji
            if (!str_contains((string)$ch['note'], '[prośba o kartę]')) {
                db_exec("UPDATE claude_charges SET note=TRIM(note || ' [prośba o kartę]') WHERE id=?", [$chargeId]);
                ClaudeBilling::event((int)$ch['subscription_id'], $chargeId, 'card_fallback', 'Opłata za ' . ClaudeBilling::periodLabel((string)$ch['period']) . ' nie pobrana (' . $why . '). Tylko karta: prośba o podpięcie innej karty.', 'system');
                ClaudeBilling::notifyParticipant($sub0, 'karta_inna', $ch, $why);
            }
            return;
        }
        db_exec("UPDATE claude_charges SET pay_mode='invoice', due_on=MAX(due_on, ?) WHERE id=?", [date('Y-m-d', strtotime('+' . ClaudeBilling::TERM_DAYS . ' day')), $chargeId]);
        ClaudeBilling::event((int)$ch['subscription_id'], $chargeId, 'card_fallback', 'Opłata za ' . ClaudeBilling::periodLabel((string)$ch['period']) . ' przechodzi na przelew (' . $why . ').', 'system');
        ClaudeBilling::toInvoiceMode($chargeId);
        $sub = ClaudeBilling::subscription((int)$ch['subscription_id']) ?? [];
        ClaudeBilling::notifyParticipant($sub, 'karta_blad', ClaudeBilling::charge($chargeId), $why);
    }

    /** Wszystkie oczekujące naliczenia abonamentu w trybie karty → przelew (np. karta nie zapisana). */
    private static function fallbackOpen(int $subId, string $why): void
    {
        foreach (db_all("SELECT id FROM claude_charges WHERE subscription_id=? AND kind='month' AND status='pending' AND pay_mode='card'", [$subId]) as $r) self::fallback((int)$r['id'], $why);
    }

    /** Wycofanie zgody: karta odpięta, kolejne opłaty przelewem (oczekujące jeszcze nieobciążone — od razu proforma). */
    public static function withdraw(int $cid, array $actor): string
    {
        self::migrate();
        $card = self::active($cid);
        if (!$card) throw new DomainException('Nie ma podpiętej karty.');
        $by = (string)($actor['name'] ?? '');
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        db_exec("UPDATE license_cards SET status='removed', removed_at=datetime('now'), removed_by=?, removed_reason='wycofanie zgody' WHERE id=?", [$by, (int)$card['id']]);
        db_exec("UPDATE license_card_consents SET withdrawn_at=datetime('now'), withdrawn_ip=?, withdrawn_by=? WHERE client_id=? AND withdrawn_at IS NULL", [$ip, $by, $cid]);
        foreach (db_all("SELECT ch.id FROM claude_charges ch WHERE ch.client_id=? AND ch.kind='month' AND ch.status='pending' AND ch.pay_mode='card'
                          AND NOT EXISTS (SELECT 1 FROM p24_payments p WHERE p.source_type='claude_charge' AND p.source_id=ch.id AND p.status='pending' AND p.order_id<>'')", [$cid]) as $r) {
            self::fallback((int)$r['id'], 'zgoda na płatności kartą wycofana');
        }
        $sub = db_one("SELECT id FROM claude_subscriptions WHERE client_id=? AND status IN ('requested','active','suspended') ORDER BY id DESC LIMIT 1", [$cid]);
        ClaudeBilling::event($sub ? (int)$sub['id'] : null, null, 'card_removed', 'Zgoda na płatności kartą wycofana, karta ' . self::label($card) . ' odpięta (' . $by . ($ip !== '' ? ', IP ' . $ip : '') . '). Kolejne opłaty: proforma i przelew.', $by);
        $only = $sub && ($sx = ClaudeBilling::subscription((int)$sub['id'])) && self::cardOnly($sx);
        return $only ? 'Karta odpięta, zgoda wycofana. Za tę licencję płaci się tylko kartą: podepnij inną kartę albo zrezygnuj z licencji. Wycofanie zgody nie jest rezygnacją.'
                     : 'Karta odpięta, zgoda wycofana. Kolejne opłaty: proforma i przelew. To nie jest rezygnacja z licencji.';
    }

    // ── Przebieg dzienny (z ClaudeBilling::run) ───────────────────────────────

    public static function run(string $today, array &$rep): void
    {
        self::migrate();
        // porzucone opłaty weryfikacyjne (płatność nie dokończona) — anulujemy po dniu
        foreach (db_all("SELECT id FROM claude_charges WHERE kind='card' AND status='pending' AND created_at < datetime('now','-1 day')") as $r) {
            db_exec("UPDATE claude_charges SET status='cancelled', note=TRIM(note || ' Płatność nie dokończona — anulowano.') WHERE id=? AND status='pending'", [(int)$r['id']]);
        }
        require_once dirname(__DIR__, 3) . '/includes/p24.php';
        // obciążenia bez powiadomienia — sprawdzamy w P24 (transaction/verify)
        foreach (db_all("SELECT p.id FROM p24_payments p JOIN claude_charges ch ON ch.id=p.source_id WHERE p.source_type='claude_charge' AND p.status='pending' AND p.order_id<>'' AND ch.status='pending' LIMIT 30") as $r) {
            try { p24_reconcile_payment((int)$r['id']); } catch (\Throwable $e) {}
        }
        ClaudeBilling::afterPayment();
        // wnioski w trybie karty bez karty po 2 dniach — proforma i przelew
        foreach (db_all("SELECT ch.id, ch.client_id FROM claude_charges ch WHERE ch.kind='month' AND ch.status='pending' AND ch.pay_mode='card' AND ch.created_at < datetime('now','-2 day')") as $r) {
            if (!self::active((int)$r['client_id'])) self::fallback((int)$r['id'], 'karta nie została podpięta');
        }
        // opłaty miesięczne w dniu terminu
        foreach (db_all("SELECT id FROM claude_charges WHERE kind='month' AND status='pending' AND pay_mode='card' AND due_on<=? AND (card_tried_at IS NULL OR date(card_tried_at) < ?) AND card_attempts < ? AND is_test=0 ORDER BY id LIMIT 50", [$today, $today, self::MAX_ATTEMPTS]) as $r) {
            $x = self::chargeNow((int)$r['id'], 'system');
            $rep['log'][] = 'karta, naliczenie #' . (int)$r['id'] . ': ' . $x['text'];
        }
    }
}
