<?php
/**
 * modules/ti_licencje/logic/tiLicencjeBilling.php — rozliczenie cykliczne licencji kursantów TI: wiadomości rozliczeniowe.
 *
 *  • sendStart()    — w dniu przypisania: e-mail + SMS z kwotą miesięczną (po rabacie), formą rozliczenia, dniem miesiąca,
 *                     informacją o rabacie; pierwsza opłata jest dzisiaj;
 *  • sendReminder() — co miesiąc, DZIEŃ PRZED terminem rozliczenia (cron/ti_license_billing.php o 7:00).
 * To komunikacja rozliczeniowa: jak przypomnienia o wpłatach NIE sprawdza zgód „od dydaktyki”; małoletni → opiekun (gdy ma kontakt),
 * kursant wirtualny nigdy. Dedup: k30_ti_license_billing_msgs (jedna wiadomość na licencję, okres, kanał i adresata).
 * Szablony (edytowalne): ti_license_billing_start[_guardian], ti_license_billing_reminder[_guardian] w rejestrach SMS/e-mail.
 */
require_once dirname(__DIR__, 3) . '/includes/karty30.php';
require_once dirname(__DIR__, 3) . '/includes/sms.php';
require_once dirname(__DIR__, 3) . '/includes/sms_templates.php';
require_once dirname(__DIR__, 3) . '/includes/mail_queue.php';
require_once dirname(__DIR__, 3) . '/includes/email_templates.php';
require_once dirname(__DIR__, 3) . '/includes/ti_virtual.php';
require_once __DIR__ . '/tiLicencjeSchema.php';
require_once __DIR__ . '/tiLicencjeManager.php';

final class TiLicencjeBilling
{
    /** Adresat rozliczeniowy: małoletni → opiekun (gdy ma kontakt), inaczej kursant. */
    public static function contact(int $clientId): array
    {
        $r = db_one(
            "SELECT cl.name, cl.email, cl.phone, a.is_minor, a.guardian_name, a.guardian_email, a.guardian_phone
               FROM k30_clients cl LEFT JOIN k30_ti_student_accounts a ON a.client_id = cl.id
              WHERE cl.id = ? ORDER BY a.id LIMIT 1", [$clientId]
        ) ?: [];
        $minor = !empty($r['is_minor']);
        $gE = trim((string)($r['guardian_email'] ?? '')); $gP = trim((string)($r['guardian_phone'] ?? '')); $gN = trim((string)($r['guardian_name'] ?? ''));
        $useGE = $minor && $gE !== '' && filter_var($gE, FILTER_VALIDATE_EMAIL);
        $useGP = $minor && $gP !== '';
        $name  = $minor && $gN !== '' && ($useGE || $useGP) ? $gN : trim((string)($r['name'] ?? ''));
        $parts = preg_split('/\s+/', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return [
            'name' => $name, 'first_name' => $parts ? (string)$parts[0] : '',
            'email' => $useGE ? $gE : trim((string)($r['email'] ?? '')), 'email_kind' => $useGE ? 'opiekun' : 'kursant',
            'phone' => $useGP ? $gP : trim((string)($r['phone'] ?? '')), 'sms_kind' => $useGP ? 'opiekun' : 'kursant',
        ];
    }

    private static function ascii(string $s): string
    {
        return strtr($s, ['ą'=>'a','ć'=>'c','ę'=>'e','ł'=>'l','ń'=>'n','ó'=>'o','ś'=>'s','ź'=>'z','ż'=>'z','Ą'=>'A','Ć'=>'C','Ę'=>'E','Ł'=>'L','Ń'=>'N','Ó'=>'O','Ś'=>'S','Ź'=>'Z','Ż'=>'Z',
                          '„'=>'"','”'=>'"','–'=>'-','—'=>'-',"\u{00A0}"=>' ']);
    }

    /** Pozycja rozliczana: licencja ('license') albo produkt ('product'; typ Licencja/Domena/Hosting/Inne) — ujednolicone pola. */
    private static function assignment(int $id, string $type = 'license'): ?array
    {
        ti_licencje_migrate();
        if ($type === 'product') {
            require_once dirname(__DIR__, 2) . '/ti_products/logic/tiProducts.php';
            TiProducts::migrate();
            $r = db_one("SELECT p.*, c.name AS client_name FROM k30_ti_client_products p JOIN k30_clients c ON c.id = p.client_id WHERE p.id = ?", [$id]);
            if (!$r) return null;
            $r['software']    = ($r['product_type'] === 'domain' ? 'domena ' : ($r['product_type'] === 'hosting' ? 'hosting ' : '')) . (string)$r['name'];
            $r['assigned_at'] = (string)$r['created_at'];
            $r['issued_at']   = substr((string)$r['created_at'], 0, 10);
            $r['status']      = in_array($r['status'], ['suspended', 'archived'], true) ? 'revoked' : 'active';   // wstrzymany/zarchiwizowany = bez wiadomości
            $r['item_type']   = 'product';
            return $r;
        }
        $r = db_one(
            "SELECT cl.*, l.name AS software, c.name AS client_name
               FROM k30_ti_client_licenses cl JOIN k30_ti_licenses l ON l.id = cl.license_id JOIN k30_clients c ON c.id = cl.client_id
              WHERE cl.id = ?", [$id]) ?: null;
        if ($r) $r['item_type'] = 'license';
        return $r;
    }

    /** Opis rabatu do wiadomości: SMS „ po rabacie 20% (cena 61,25 zl)”, e-mail „ — rabat 20%, cena 61,25 PLN”. */
    private static function discountText(array $a, string $channel): string
    {
        $am = TiLicencjeManager::amounts($a);
        if (!$am || $am[0] === $am[1] || (string)($a['discount_type'] ?? '') === '') return '';
        $val  = (float)$a['discount_value'];
        $what = $a['discount_type'] === 'pct' ? rtrim(rtrim(number_format($val, 2, ',', ''), '0'), ',') . '%' : number_format($val, 2, ',', '') . ($channel === 'sms' ? ' zl' : ' PLN');
        $note = trim((string)($a['discount_note'] ?? ''));
        if ($channel === 'sms') return ' po rabacie ' . $what . ' (cena ' . number_format($am[0], 2, ',', '') . ' zl)';
        return ' — rabat ' . $what . ($note !== '' ? ' (' . $note . ')' : '') . ', cena katalogowa ' . number_format($am[0], 2, ',', ' ') . ' PLN';
    }

    /** Zmienne szablonów (SMS: czysty tekst; e-mail: wartości zabezpieczone). */
    public static function vars(array $a, array $contact, string $channel, string $kind, string $dateYmd): array
    {
        $am    = TiLicencjeManager::amounts($a) ?? [0.0, 0.0];
        $form  = TiLicencjeManager::BILLING_FORMS[(string)$a['billing_form']]['label'] ?? 'zgodnie z ustaleniem';
        $sms   = $channel === 'sms';
        $date  = date('d.m.Y', strtotime($dateYmd));
        $guardian = $contact['email_kind'] === 'opiekun' || $contact['sms_kind'] === 'opiekun';
        $disc  = self::discountText($a, $channel);
        if ($sms) {
            return ['software' => (string)$a['software'], 'amount' => number_format($am[1], 2, ',', ''), 'discount' => $disc,
                    'form' => mb_strtolower($form), 'day' => (string)(int)$a['billing_day'], 'student' => (string)$a['client_name'], 'date' => $date];
        }
        $pay  = k30_ti_client_payment((int)$a['client_id']);
        $base = defined('APP_URL') ? rtrim((string)APP_URL, '/') : '';
        $v = [
            'org' => defined('ORG_NAME') ? (string)ORG_NAME : 'Placówka', 'student' => (string)$a['client_name'], 'software' => (string)$a['software'],
            'greeting' => $contact['first_name'] !== '' ? ' ' . ($guardian ? $contact['name'] : $contact['first_name']) : '',
            'amount' => number_format($am[1], 2, ',', ' ') . ' PLN', 'discount_html' => $disc, 'form' => $form, 'date' => $date,
            'account' => trim((string)$pay['account']) !== '' ? (string)$pay['account'] : 'podany w panelu kursanta',
            'title' => (string)$pay['title'], 'portal' => $base . '/karty30/ti/kursant/login.php', 'day' => (string)(int)$a['billing_day'],
        ];
        $v = array_map(fn($x) => h($x), $v);
        if ($kind === 'start') {
            $v['lead'] = h($guardian ? 'przypisaliśmy ' . $a['client_name'] . ' licencję na oprogramowanie. Poniżej warunki rozliczenia.' : 'przypisaliśmy Ci licencję na oprogramowanie. Poniżej warunki rozliczenia.');
        } else {
            $v['for_student'] = $guardian ? h(' (kursant: ' . $a['client_name'] . ')') : '';
        }
        return $v;
    }

    private static function log(string $type, int $aid, string $kind, string $period, string $channel, string $rk, string $to, string $status, string $detail, float $amount): bool
    {
        try {
            db()->prepare("INSERT INTO k30_ti_license_billing_msgs (item_type,assignment_id,kind,period,channel,recipient_kind,recipient,status,detail,amount,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$type, $aid, $kind, $period, $channel, $rk, $to, $status, mb_substr($detail, 0, 300), $amount, date('Y-m-d H:i:s')]);
            return true;
        } catch (\PDOException $e) { return false; }   // unikalny indeks: już wysłano
    }

    /**
     * Wysyła wiadomość rozliczeniową ($kind: 'start' | 'reminder') dla okresu $period (RRRR-MM), $dateYmd = data w treści.
     * @return array{ok:bool,sent:array{email:int,sms:int},msg:string,skipped:list<string>}
     */
    private static function send(string $type, int $aid, string $kind, string $period, string $dateYmd, array $channels): array
    {
        $res = ['ok' => false, 'sent' => ['email' => 0, 'sms' => 0], 'msg' => '', 'skipped' => []];
        $a = self::assignment($aid, $type);
        if (!$a)                          { $res['msg'] = 'Nie znaleziono pozycji.'; return $res; }
        if ($a['status'] === 'revoked')   { $res['msg'] = 'Licencja jest unieważniona.'; return $res; }
        $am = TiLicencjeManager::amounts($a);
        if (!$am || empty($a['billing_day'])) { $res['msg'] = 'Licencja nie ma rozliczenia cyklicznego.'; return $res; }
        // „Bez rozliczeń i powiadomień” wyłącza także wiadomości o licencjach — chyba że konto ma opcję „tylko rozliczenie licencji”
        // (ona jest szczegółowsza: lekcji nie naliczamy, licencje tak).
        $flags = db_one("SELECT MAX(COALESCE(no_billing,0)) AS nb, MAX(COALESCE(licenses_only,0)) AS lo FROM k30_ti_student_accounts WHERE client_id = ?", [(int)$a['client_id']]);
        if ($flags && (int)$flags['nb'] === 1 && (int)$flags['lo'] === 0) { $res['msg'] = 'Konto ma „Bez rozliczeń i powiadomień” — wiadomości rozliczeniowej nie wysłano.'; return $res; }
        $c = self::contact((int)$a['client_id']);
        $tplBase = 'ti_license_billing_' . $kind;
        $amount = $am[1];

        if (in_array('email', $channels, true)) {
            $to = $c['email'];
            if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) $res['skipped'][] = 'brak poprawnego adresu e-mail';
            elseif (ti_virtual_blocked($to)) $res['skipped'][] = 'kursant wirtualny';
            elseif (self::log($type, $aid, $kind, $period, 'email', $c['email_kind'], TiLicencjeNotifier::maskEmail($to), 'pending', '', $amount)) {
                $status = 'failed'; $reason = '';
                try {
                    $r = email_tpl_render($tplBase, self::vars($a, $c, 'email', $kind, $dateYmd));
                    if (empty($r['enabled'])) { $status = 'skipped'; $reason = 'szablon e-mail wyłączony'; }
                    else {
                        $mid = mail_queue_add($to, $c['name'], $r['subject'], $r['html'], '', 'ti_license_billing', $aid, '', false);
                        if ($mid > 0) { $status = 'sent'; $res['sent']['email']++; } else { $status = 'skipped'; $reason = 'zablokowane (kursant wirtualny)'; }
                    }
                } catch (\Throwable $e) { $reason = $e->getMessage(); }
                db()->prepare("UPDATE k30_ti_license_billing_msgs SET status=?, detail=? WHERE item_type=? AND assignment_id=? AND kind=? AND period=? AND channel='email' AND recipient_kind=? AND status='pending'")
                    ->execute([$status, mb_substr($reason, 0, 300), $type, $aid, $kind, $period, $c['email_kind']]);
                if ($status !== 'sent') $res['skipped'][] = 'e-mail: ' . ($reason ?: $status);
            }
        }

        if (in_array('sms', $channels, true)) {
            $phone = $c['phone'];
            if (!(function_exists('sms_channel_ready') && sms_channel_ready())) $res['skipped'][] = 'kanał SMS nie jest skonfigurowany';
            elseif ($phone === '') $res['skipped'][] = 'brak numeru telefonu';
            elseif (ti_virtual_blocked('', $phone)) $res['skipped'][] = 'kursant wirtualny';
            elseif (self::log($type, $aid, $kind, $period, 'sms', $c['sms_kind'], TiLicencjeNotifier::maskPhone($phone), 'pending', '', $amount)) {
                $tpl = $tplBase . ($c['sms_kind'] === 'opiekun' ? '_guardian' : '');
                $status = 'failed'; $reason = '';
                try {
                    $m = sms_tpl_render($tpl, self::vars($a, $c, 'sms', $kind, $dateYmd));
                    $msg = trim(self::ascii((string)$m['message']));
                    if (empty($m['enabled']) || $msg === '') { $status = 'skipped'; $reason = 'szablon SMS wyłączony'; }
                    else { sms_send($phone, $msg, $tpl); $status = 'sent'; $res['sent']['sms']++; }
                } catch (\Throwable $e) { $reason = function_exists('sms_scrub') ? sms_scrub($e->getMessage()) : $e->getMessage(); }
                db()->prepare("UPDATE k30_ti_license_billing_msgs SET status=?, detail=? WHERE item_type=? AND assignment_id=? AND kind=? AND period=? AND channel='sms' AND recipient_kind=? AND status='pending'")
                    ->execute([$status, mb_substr($reason, 0, 300), $type, $aid, $kind, $period, $c['sms_kind']]);
                if ($status !== 'sent') $res['skipped'][] = 'SMS: ' . ($reason ?: $status);
            }
        }
        $tot = $res['sent']['email'] + $res['sent']['sms'];
        $res['ok'] = $tot > 0;
        $res['msg'] = $tot > 0
            ? 'Wysłano: e-mail ' . $res['sent']['email'] . ', SMS ' . $res['sent']['sms'] . '.' . ($res['skipped'] ? ' Pominięto: ' . implode('; ', $res['skipped']) . '.' : '')
            : 'Nie wysłano wiadomości rozliczeniowej' . ($res['skipped'] ? ' (' . implode('; ', $res['skipped']) . ')' : '') . '.';
        return $res;
    }

    /** W dniu przypisania: kwota, forma, dzień miesiąca, rabat; pierwsza opłata dzisiaj. $type: 'license' | 'product'. */
    public static function sendStart(int $assignmentId, array $channels = ['email', 'sms'], ?int $by = null, string $type = 'license', bool $force = false): array
    {
        $a = self::assignment($assignmentId, $type);
        $issued = $a ? (string)($a['issued_at'] ?: substr((string)$a['assigned_at'], 0, 10)) : date('Y-m-d');
        if ($force) {   // ponowna wysyłka: poprzednie wpisy „start” przestają blokować (zostają w dzienniku jako 'superseded')
            db()->prepare("UPDATE k30_ti_license_billing_msgs SET status='superseded' WHERE item_type=? AND assignment_id=? AND kind='start' AND status IN ('sent','pending')")
                ->execute([$type, $assignmentId]);
        }
        $res = self::send($type, $assignmentId, 'start', substr($issued, 0, 7), date('Y-m-d'), array_values(array_intersect(['email', 'sms'], $channels)));
        if ($res['ok'] && $type === 'license') db_exec("UPDATE k30_ti_client_licenses SET notified_at = ? WHERE id = ?", [date('Y-m-d H:i:s'), $assignmentId]);
        return $res;
    }

    /**
     * Przypomnienia „jutro rozliczenie”. Dla $today wyznacza licencje, których termin w miesiącu JUTRZEJSZEJ daty wypada jutro
     * (z przycięciem dnia do długości miesiąca); pierwszy miesiąc (dzień przypisania) pomijamy — wtedy poszła wiadomość startowa.
     * @return array{due:list<array{id:int,client:string,software:string,date:string}>,sent:int,skipped:int}
     */
    public static function runReminders(?string $today = null, bool $dry = false, ?int $only = null, string $onlyType = ''): array
    {
        ti_licencje_migrate();
        require_once dirname(__DIR__, 2) . '/ti_products/logic/tiProducts.php';
        TiProducts::migrate();
        $today    = $today ?: date('Y-m-d');
        $tomorrow = date('Y-m-d', strtotime($today . ' +1 day'));
        $ym       = substr($tomorrow, 0, 7);
        $out = ['due' => [], 'sent' => 0, 'skipped' => 0];
        $pool = [];
        if ($onlyType === '' || $onlyType === 'license') {
            foreach (db_all(
                "SELECT cl.id, cl.billing_day, cl.expires_at, cl.issued_at, cl.assigned_at, l.name AS software, c.name AS client_name
                   FROM k30_ti_client_licenses cl JOIN k30_ti_licenses l ON l.id = cl.license_id JOIN k30_clients c ON c.id = cl.client_id
                  WHERE cl.status = 'active' AND cl.monthly_amount IS NOT NULL AND cl.monthly_amount > 0 AND cl.billing_day IS NOT NULL"
                  . ($only && $onlyType === 'license' ? " AND cl.id = " . (int)$only : "")) as $r) $pool[] = ['license', $r];
        }
        if ($onlyType === '' || $onlyType === 'product') {
            foreach (db_all(
                "SELECT p.id, p.billing_day, p.expires_at, substr(p.created_at,1,10) AS issued_at, p.created_at AS assigned_at, p.name AS software, c.name AS client_name
                   FROM k30_ti_client_products p JOIN k30_clients c ON c.id = p.client_id
                  WHERE p.status IN ('active','expiring') AND p.monthly_amount IS NOT NULL AND p.monthly_amount > 0 AND p.billing_day IS NOT NULL"
                  . ($only && $onlyType === 'product' ? " AND p.id = " . (int)$only : "")) as $r) $pool[] = ['product', $r];
        }
        foreach ($pool as [$type, $r]) {
            if (TiLicencjeManager::dueDate((int)$r['billing_day'], $ym) !== $tomorrow) continue;                 // termin nie wypada jutro
            $issued = (string)($r['issued_at'] ?: substr((string)$r['assigned_at'], 0, 10));
            if (substr($issued, 0, 7) >= $ym) continue;                                                           // miesiąc przypisania — wiadomość startowa
            if (!empty($r['expires_at']) && (string)$r['expires_at'] < $tomorrow) continue;                       // wygasa przed terminem
            $out['due'][] = ['type' => $type, 'id' => (int)$r['id'], 'client' => (string)$r['client_name'], 'software' => (string)$r['software'], 'date' => $tomorrow];
            if ($dry) continue;
            $res = self::send($type, (int)$r['id'], 'reminder', $ym, $tomorrow, ['email', 'sms']);
            if ($res['ok']) $out['sent']++; else $out['skipped']++;
        }
        return $out;
    }

    /** Dziennik wiadomości rozliczeniowych licencji (do ekranu). */
    public static function recent(int $assignmentId, string $type = 'license'): array
    {
        ti_licencje_migrate();
        return db_all("SELECT kind, period, channel, recipient_kind, recipient, status, detail, amount, created_at FROM k30_ti_license_billing_msgs WHERE item_type = ? AND assignment_id = ? ORDER BY id DESC LIMIT 40", [$type, $assignmentId]);
    }
}
