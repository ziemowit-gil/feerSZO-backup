<?php
declare(strict_types=1);

/**
 * modules/claude_billing/logic/PriceChange.php — zmiany cen płatnych licencji (Claude, NordVPN) z akceptacją abonentów (polecenie 2026-11-09).
 *
 * Kierownik planuje zmianę (produkt, od kiedy, nowa cena USD / cena regularna / narzut % / prowizja, powód, co przy braku odpowiedzi) →
 * „Wyślij powiadomienia”: każdy abonent (aktywny / wstrzymany) dostaje e-mail prostym językiem (było → będzie, od kiedy, ile w złotych)
 * z linkiem do decyzji (kursant.feer.org.pl/licencje/?_route=/zmiana-ceny/{token}): „Akceptuję” albo „Nie akceptuję — rezygnuję”.
 * Niepełnoletni: decyzję podejmuje opiekun (link na guardian_email), uczestnik dostaje informację bez linku.
 * W dniu zmiany (CRON — ClaudeBilling::run): ustawienia produktu przestawiają się na nową cenę; „nie akceptuję” (i brak odpowiedzi,
 * gdy tak wybrano) → abonament kończy się dzień przed zmianą (zgłoszenie dezaktywacji w Helpdesku). Wcześniejsze naliczenia bez zmian.
 */
require_once __DIR__ . '/claudeBilling.php';

final class PriceChange
{
    public const NO_ANSWER = ['accept' => 'akceptacja — cena się zmienia, osoba może zrezygnować w każdej chwili', 'reject' => 'rezygnacja — abonament kończy się dzień przed zmianą'];
    public const STATUS = ['draft' => 'szkic', 'sent' => 'wysłana — czeka na dzień zmiany', 'applied' => 'wprowadzona', 'cancelled' => 'wycofana'];

    public static function migrate(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        if (function_exists('szo_schema_current') && szo_schema_current('license_price_changes_migrate', __FILE__)) return;
        db()->exec("CREATE TABLE IF NOT EXISTS license_price_changes (
            id INTEGER PRIMARY KEY AUTOINCREMENT, product TEXT NOT NULL, effective_from DATE NOT NULL,
            usd REAL NOT NULL, list_usd REAL NOT NULL DEFAULT 0, pct REAL NOT NULL DEFAULT 0, fee_pln REAL NOT NULL DEFAULT 0,
            reason TEXT NOT NULL DEFAULT '', no_answer TEXT NOT NULL DEFAULT 'accept', status TEXT NOT NULL DEFAULT 'draft',
            old_json TEXT NOT NULL DEFAULT '', created_by TEXT NOT NULL DEFAULT '', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            sent_at DATETIME, applied_at DATETIME)");
        db()->exec("CREATE TABLE IF NOT EXISTS license_price_answers (
            id INTEGER PRIMARY KEY AUTOINCREMENT, change_id INTEGER NOT NULL, subscription_id INTEGER NOT NULL, client_id INTEGER NOT NULL,
            token_hash TEXT NOT NULL UNIQUE, recipient TEXT NOT NULL DEFAULT 'uczestnik', recipient_email TEXT NOT NULL DEFAULT '',
            decision TEXT NOT NULL DEFAULT '', decided_at DATETIME, decided_ip TEXT NOT NULL DEFAULT '', decided_name TEXT NOT NULL DEFAULT '',
            notified_at DATETIME, UNIQUE(change_id, subscription_id))");
    }

    public static function get(int $id): ?array
    {
        self::migrate();
        return db_one("SELECT * FROM license_price_changes WHERE id=?", [$id]) ?: null;
    }

    public static function list(string $product): array
    {
        self::migrate();
        return db_all("SELECT c.*, (SELECT COUNT(*) FROM license_price_answers a WHERE a.change_id=c.id) n,
                              (SELECT COUNT(*) FROM license_price_answers a WHERE a.change_id=c.id AND a.decision='accept') n_acc,
                              (SELECT COUNT(*) FROM license_price_answers a WHERE a.change_id=c.id AND a.decision='reject') n_rej
                         FROM license_price_changes c WHERE c.product=? ORDER BY c.id DESC LIMIT 20", [ClaudeBilling::product($product)]);
    }

    public static function answers(int $changeId): array
    {
        return db_all("SELECT a.*, c.name FROM license_price_answers a LEFT JOIN k30_clients c ON c.id=a.client_id WHERE a.change_id=? ORDER BY c.name", [$changeId]);
    }

    /** Nowe ustawienia cenowe zmiany (w formacie ClaudeBilling::settings) — do przeliczeń w treści. */
    private static function newSettings(array $chg): array
    {
        $s = ClaudeBilling::settings((string)$chg['product']);
        return ['usd' => (float)$chg['usd'], 'list_usd' => (float)$chg['list_usd'], 'pct' => (float)$chg['pct'], 'fee_pln' => (float)$chg['fee_pln']] + $s;
    }

    public static function create(string $product, array $in, string $by): int
    {
        self::migrate();
        $product = ClaudeBilling::product($product);
        $num = fn($k) => (float)str_replace(',', '.', trim((string)($in[$k] ?? '')));
        $from = (string)($in['effective_from'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || $from <= date('Y-m-d')) throw new InvalidArgumentException('Podaj datę zmiany w przyszłości (najlepiej co najmniej 30 dni naprzód).');
        if ($num('usd') <= 0) throw new InvalidArgumentException('Podaj nową cenę w USD.');
        if ($num('list_usd') > 0 && $num('list_usd') < $num('usd')) throw new InvalidArgumentException('Cena regularna nie może być niższa od nowej ceny.');
        if (db_one("SELECT 1 FROM license_price_changes WHERE product=? AND status IN ('draft','sent')", [$product])) throw new DomainException('Jest już zaplanowana zmiana ceny tego produktu — wycofaj ją albo poczekaj na jej wprowadzenie.');
        $s = ClaudeBilling::settings($product);
        $id = db_insert('license_price_changes', ['product' => $product, 'effective_from' => $from, 'usd' => $num('usd'), 'list_usd' => $num('list_usd'),
            'pct' => trim((string)($in['pct'] ?? '')) === '' ? $s['pct'] : $num('pct'), 'fee_pln' => trim((string)($in['fee_pln'] ?? '')) === '' ? $s['fee_pln'] : $num('fee_pln'),
            'reason' => mb_substr(trim((string)($in['reason'] ?? '')), 0, 500), 'no_answer' => ($in['no_answer'] ?? '') === 'reject' ? 'reject' : 'accept',
            'old_json' => json_encode(['usd' => $s['usd'], 'list_usd' => $s['list_usd'], 'pct' => $s['pct'], 'fee_pln' => $s['fee_pln']]), 'created_by' => $by]);
        ClaudeBilling::event(null, null, 'price_change', ClaudeBilling::P($product)['label'] . ': zaplanowano zmianę ceny #' . $id . ' od ' . ClaudeBilling::date($from) . '.', $by);
        return $id;
    }

    public static function cancel(int $id, string $by): void
    {
        $c = self::get($id);
        if (!$c || !in_array($c['status'], ['draft', 'sent'], true)) throw new DomainException('Tej zmiany nie da się już wycofać.');
        db_exec("UPDATE license_price_changes SET status='cancelled' WHERE id=?", [$id]);
        ClaudeBilling::event(null, null, 'price_change', 'Wycofano zmianę ceny #' . $id . ($c['status'] === 'sent' ? ' (po wysłaniu powiadomień — warto poinformować abonentów)' : '') . '.', $by);
    }

    /** Opis „było → będzie” prostym językiem. @return array{old:string,new:string,oldPln:?float,newPln:?float} */
    public static function compare(array $chg): array
    {
        $old = json_decode((string)$chg['old_json'], true) ?: [];
        $s = ClaudeBilling::settings((string)$chg['product']);
        $oldS = $old + $s; $newS = self::newSettings($chg);
        $r = ClaudeBilling::lastRate((string)$chg['product']);
        $oldPln = $r ? ClaudeBilling::amounts((float)$r['rate'], $oldS)['amount_pln'] : null;
        $newPln = $r ? ClaudeBilling::amounts((float)$r['rate'], $newS)['amount_pln'] : null;
        return ['old' => ClaudeBilling::priceShort($oldS), 'new' => ClaudeBilling::priceShort($newS), 'oldPln' => $oldPln, 'newPln' => $newPln];
    }

    /** Wysyłka: link do decyzji (uczestnik; przy niepełnoletnim — opiekun) + informacja dla uczestnika niepełnoletniego. */
    public static function send(int $id, string $by): array
    {
        $chg = self::get($id);
        if (!$chg || $chg['status'] !== 'draft') throw new DomainException('Powiadomienia wysyła się raz, ze szkicu zmiany.');
        $rep = ['sent' => 0, 'skipped' => []];
        foreach (db_all("SELECT * FROM claude_subscriptions WHERE product=? AND status IN ('active','suspended') AND is_test=0", [$chg['product']]) as $sub) {
            $cid = (int)$sub['client_id'];
            $minor = ClaudeBilling::isMinor($cid);
            $c = ClaudeBilling::client($cid) ?? ['name' => '', 'email' => ''];
            $acc = db_one("SELECT guardian_name, guardian_email FROM k30_ti_student_accounts WHERE client_id=? ORDER BY id DESC LIMIT 1", [$cid]) ?: [];
            $to = $minor ? trim((string)($acc['guardian_email'] ?? '')) : trim((string)($c['email'] ?? ''));
            $toName = $minor ? (string)($acc['guardian_name'] ?? '') : (string)$c['name'];
            $tok = bin2hex(random_bytes(24));
            db_insert('license_price_answers', ['change_id' => $id, 'subscription_id' => (int)$sub['id'], 'client_id' => $cid, 'token_hash' => hash('sha256', $tok),
                'recipient' => $minor ? 'opiekun' : 'uczestnik', 'recipient_email' => $to]);
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) { $rep['skipped'][] = $c['name'] . ' (brak e-maila' . ($minor ? ' opiekuna' : '') . ')'; continue; }
            self::mail($chg, $to, $toName, (string)$c['name'], $minor, $tok);
            if ($minor && filter_var(trim((string)$c['email']), FILTER_VALIDATE_EMAIL)) self::mail($chg, trim((string)$c['email']), (string)$c['name'], (string)$c['name'], false, null);   // informacja bez linku
            db_exec("UPDATE license_price_answers SET notified_at=datetime('now') WHERE change_id=? AND subscription_id=?", [$id, (int)$sub['id']]);
            ClaudeBilling::event((int)$sub['id'], null, 'price_notice', 'Powiadomienie o zmianie ceny #' . $id . ' (od ' . ClaudeBilling::date($chg['effective_from']) . ') — ' . ($minor ? 'do opiekuna' : 'do uczestnika') . '.', $by);
            $rep['sent']++;
        }
        db_exec("UPDATE license_price_changes SET status='sent', sent_at=datetime('now') WHERE id=?", [$id]);
        return $rep;
    }

    public static function decisionUrl(string $token): string
    {
        $base = defined('APP_URL') ? rtrim((string)APP_URL, '/') : '';
        return $base . '/karty30/ti/kursant/licencje/?_route=' . rawurlencode('/zmiana-ceny/' . $token);
    }

    private static function mail(array $chg, string $to, string $toName, string $person, bool $asGuardian, ?string $token): void
    {
        $P = ClaudeBilling::P((string)$chg['product']);
        $cmp = self::compare($chg);
        $from = ClaudeBilling::dayMonth((string)$chg['effective_from']);
        $day0 = ClaudeBilling::dayMonth(date('Y-m-d', strtotime($chg['effective_from'] . ' -1 day')));
        $subject = $P['label'] . ': nowa cena od ' . $from . ($token ? ' (potrzebna Twoja decyzja)' : '');
        $html = '<p>Dzień dobry,</p>'
            . '<p>' . ($asGuardian ? 'jako opiekun decydujesz, czy ' . h($person) . ' dalej korzysta z ' . h($P['label']) . '. Od ' . h($from) . ' zmienia się cena.'
                                   : 'od ' . h($from) . ' zmienia się cena dostępu do ' . h($P['label']) . '.') . '</p>'
            . '<ul><li>Teraz: <b>' . h($cmp['old']) . '</b>' . ($cmp['oldPln'] !== null ? ' (dziś ok. ' . h(ClaudeBilling::zl($cmp['oldPln'])) . ' miesięcznie)' : '') . '</li>'
            . '<li>Od ' . h($from) . ': <b>' . h($cmp['new']) . '</b>' . ($cmp['newPln'] !== null ? ' (dziś ok. ' . h(ClaudeBilling::zl($cmp['newPln'])) . ' miesięcznie)' : '') . '</li></ul>'
            . ((string)$chg['reason'] !== '' ? '<p>Powód: ' . h((string)$chg['reason']) . '</p>' : '')
            . ($token
                ? '<p><a href="' . h(self::decisionUrl($token)) . '" style="background:#063f7a;color:#fff;padding:10px 22px;text-decoration:none;display:inline-block;font-weight:700;border-radius:5px">Wybierz: akceptuję albo rezygnuję</a></p>'
                  . '<p style="color:#555;font-size:13px">Jeśli nie odpowiesz do ' . h($day0) . ': ' . ($chg['no_answer'] === 'reject' ? 'dostęp zakończy się ' . h($day0) . '.' : 'dostęp zostanie z nową ceną. Zrezygnować możesz w każdej chwili w panelu licencji.') . '</p>'
                : '<p>Decyduje opiekun. Wysłaliśmy mu osobną wiadomość z linkiem.</p>')
            . '<p style="color:#555;font-size:13px">Pytania? Odpowiedz na tę wiadomość albo napisz do prowadzącego.</p>';
        try {
            require_once dirname(__DIR__, 3) . '/includes/mail_queue.php';
            mail_queue_add($to, $toName, $subject, $html, '', 'license_price_change', (int)$chg['id']);
        } catch (\Throwable $e) { error_log('price change mail: ' . $e->getMessage()); }
    }

    public static function byToken(string $token): ?array
    {
        self::migrate();
        if (!preg_match('/^[a-f0-9]{48}$/', $token)) return null;
        $a = db_one("SELECT a.*, c.name AS client_name FROM license_price_answers a LEFT JOIN k30_clients c ON c.id=a.client_id WHERE a.token_hash=?", [hash('sha256', $token)]);
        if (!$a) return null;
        $a['change'] = self::get((int)$a['change_id']);
        return $a['change'] ? $a : null;
    }

    public static function decide(string $token, string $decision, string $name, string $ip): array
    {
        $a = self::byToken($token);
        if (!$a) throw new DomainException('Link jest nieważny.');
        self::decideAnswer($a, $decision, $name, $ip, 'link z e-maila');
        return self::byToken($token) ?? [];
    }

    /** Sprawdzenie, czy decyzję można zapisać (przed wysłaniem kodu). */
    public static function validate(array $a, string $decision, string $name): void
    {
        if (!in_array($a['change']['status'] ?? '', ['sent'], true)) throw new DomainException('Zmiana ceny jest zamknięta. Decyzji nie można już zmienić.');
        if (!in_array($decision, ['accept', 'reject'], true)) throw new InvalidArgumentException('Wybierz: akceptuję albo rezygnuję.');
        if (mb_strlen(trim($name)) < 3) throw new InvalidArgumentException('Wpisz imię i nazwisko.');
    }

    /** Zapis decyzji (link z e-maila albo panel licencji — po kodzie). Do dnia zmiany można ją zmienić. */
    public static function decideAnswer(array $a, string $decision, string $name, string $ip, string $via): void
    {
        self::validate($a, $decision, $name);
        db_exec("UPDATE license_price_answers SET decision=?, decided_at=datetime('now'), decided_ip=?, decided_name=? WHERE id=?", [$decision, $ip, mb_substr(trim($name), 0, 120), (int)$a['id']]);
        ClaudeBilling::event((int)$a['subscription_id'], null, 'price_answer', 'Zmiana ceny #' . $a['change_id'] . ': ' . ($decision === 'accept' ? 'akceptacja' : 'rezygnacja') . ' (' . trim($name) . ', ' . $a['recipient'] . ', ' . $via . ', potwierdzone kodem).', trim($name));
    }

    /** Otwarta (wysłana) zmiana ceny dla abonamentu: odpowiedź + zmiana albo null. */
    public static function openForSubscription(int $subId): ?array
    {
        self::migrate();
        $a = db_one("SELECT a.* FROM license_price_answers a JOIN license_price_changes c ON c.id=a.change_id WHERE a.subscription_id=? AND c.status='sent' ORDER BY a.id DESC LIMIT 1", [$subId]);
        if (!$a) return null;
        $a['change'] = self::get((int)$a['change_id']);
        return $a;
    }

    public static function answer(int $id): ?array
    {
        self::migrate();
        $a = db_one("SELECT a.*, c.name AS client_name FROM license_price_answers a LEFT JOIN k30_clients c ON c.id=a.client_id WHERE a.id=?", [$id]);
        if (!$a) return null;
        $a['change'] = self::get((int)$a['change_id']);
        return $a;
    }

    /** Dokąd wysłać kod potwierdzający decyzję: opiekun — jego telefon/e-mail, uczestnik — z kartoteki. @return array{phone:string,email:string} */
    public static function codeTarget(array $a): array
    {
        $cid = (int)$a['client_id'];
        if ($a['recipient'] === 'opiekun') {
            $acc = db_one("SELECT guardian_phone, guardian_email FROM k30_ti_student_accounts WHERE client_id=? ORDER BY id DESC LIMIT 1", [$cid]) ?: [];
            return ['phone' => trim((string)($acc['guardian_phone'] ?? '')), 'email' => trim((string)($acc['guardian_email'] ?? $a['recipient_email']))];
        }
        $c = ClaudeBilling::client($cid) ?? [];
        return ['phone' => trim((string)($c['phone'] ?? '')), 'email' => trim((string)($c['email'] ?? $a['recipient_email']))];
    }

    /** W dniu zmiany (CRON): nowa cena w ustawieniach produktu, rezygnacje → koniec abonamentu dzień przed. */
    public static function applyDue(?string $today = null): array
    {
        self::migrate();
        $today ??= date('Y-m-d');
        $rep = [];
        foreach (db_all("SELECT * FROM license_price_changes WHERE status='sent' AND effective_from<=?", [$today]) as $chg) {
            foreach (self::answers((int)$chg['id']) as $a) {
                $end = $a['decision'] === 'reject' || ($a['decision'] === '' && $chg['no_answer'] === 'reject');
                if (!$end) continue;
                $sub = ClaudeBilling::subscription((int)$a['subscription_id']);
                if ($sub && in_array($sub['status'], ['active', 'suspended'], true)) {
                    try { ClaudeBilling::end((int)$sub['id'], $a['decision'] === 'reject' ? 'nie akceptuje nowej ceny (zmiana #' . $chg['id'] . ')' : 'brak odpowiedzi na zmianę ceny #' . $chg['id'], ['name' => 'system', 'via' => 'zmiana ceny']); }
                    catch (\Throwable $e) { error_log('price change end: ' . $e->getMessage()); }
                }
            }
            $s = ClaudeBilling::settings((string)$chg['product']);
            $in = ['enabled' => $s['enabled'] ? 1 : 0, 'usd' => (string)$chg['usd'], 'list_usd' => (string)$chg['list_usd'], 'due_day' => $s['due_day'], 'grace_days' => $s['grace_days'],
                   'available_from' => $s['available_from'], 'available_until' => $s['available_until'], 'pct' => (string)$chg['pct'], 'pct_label' => $s['pct_label'], 'fee_pln' => (string)$chg['fee_pln'],
                   'rate_kind' => $s['rate_kind'], 'invoice' => $s['invoice'], 'discount_mode' => $s['discount_mode'], 'pay_redirect' => $s['pay_redirect'], 'fast_transfer_url' => $s['fast_transfer_url'],
                   'bank_account' => $s['bank_account'], 'invoice_email' => $s['invoice_email'] ? 1 : 0, 'notify' => $s['notify'] ? 1 : 0, 'helpdesk' => $s['helpdesk'] ? 1 : 0, 'simulate' => $s['simulate'] ? 1 : 0,
                   'position_name' => $s['position_name'], 'fee_name' => $s['fee_name']];
            ClaudeBilling::saveSettings($in, 'zmiana ceny #' . $chg['id'], (string)$chg['product']);
            db_exec("UPDATE license_price_changes SET status='applied', applied_at=datetime('now') WHERE id=?", [(int)$chg['id']]);
            $rep[] = ClaudeBilling::P((string)$chg['product'])['label'] . ': wprowadzono zmianę ceny #' . $chg['id'];
        }
        return $rep;
    }
}
