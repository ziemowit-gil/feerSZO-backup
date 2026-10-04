<?php
/**
 * modules/ti_payment_reminders/logic/tiPaymentReminders.php — codzienne przypomnienia o braku wpłaty za zajęcia TI (e-mail + SMS).
 *
 * Kiedy uznajemy, że „brakuje wpłaty”: należność (k30_ti_billing, status issued) jest wymagalna — termin płatności
 * (due_date; dla wierszy bez terminu: późniejsza z dat „wystawienie + 7 dni” i „koniec okresu rozliczeniowego + 7 dni”)
 * + „dni po terminie” z ustawień jest wcześniejszy niż dziś — a księga
 * wpłat (k30_ti_payments, alokacja FIFO: ti_client_allocation) nie pokrywa jej w całości. Liczymy z księgi na żywo, nie z kolumny
 * paid_amount. Pomijamy: grupy „tylko godziny”, płatnika PFRON / FEER (płaci kto inny), kursantów bez rozliczeń (no_billing) i wirtualnych,
 * należności z oczekującym wnioskiem o odroczenie, kursantów ze zgłoszoną (jeszcze niezatwierdzoną) wpłatą, zaległości poniżej
 * minimalnej kwoty z ustawień.
 *
 * Adresat (jak powiadomienie o wystawieniu rozliczenia, k30_ti_billing_notify): małoletni → opiekun (a gdy opiekun nie ma e-maila /
 * telefonu — dane kursanta), pełnoletni → kursant. To komunikacja rozliczeniowa: nie podlega zgodom na powiadomienia dydaktyczne.
 *
 * Wysyłka codziennie o 7:00 (agent CRON ti_payment_reminders, cron/ti_payment_reminders.php); treść e-maila i SMS to szablony
 * ti_payment_reminder w rejestrach e-mail / SMS (edytowalne w panelu: Szablony e-mail / Szablony SMS). Mechanizm jest WYŁĄCZONY do
 * czasu włączenia w ustawieniach (karty30/ti/dydaktyk/przypomnieniaWplat.php). Bezpieczniki: dziennik k30_ti_payment_reminders
 * z częściowym indeksem unikalnym (jedno przypomnienie na odbiorcę, kanał i dzień — ponowny przebieg niczego nie dubluje),
 * odstęp co N dni, limit przypomnień na należność, podgląd „kto dostanie dziś” bez wysyłki (dry-run).
 */
require_once dirname(__DIR__, 3) . '/includes/karty30.php';
require_once dirname(__DIR__, 3) . '/includes/ti_payments.php';
require_once dirname(__DIR__, 3) . '/includes/sms.php';
require_once dirname(__DIR__, 3) . '/includes/sms_templates.php';
require_once dirname(__DIR__, 3) . '/includes/mail_queue.php';
require_once dirname(__DIR__, 3) . '/includes/email_templates.php';
require_once dirname(__DIR__, 3) . '/includes/ti_virtual.php';
require_once dirname(__DIR__, 3) . '/modules/audit_logs/logic/audit_logs.php';
require_once __DIR__ . '/tiPaymentRemindersSchema.php';

final class TiPaymentReminders
{
    /** Klucz szablonu w rejestrach e-mail (email_tpl_registry) i SMS (sms_tpl_registry). */
    public const TEMPLATE = 'ti_payment_reminder';
    public const CHANNELS = ['email' => 'E-mail', 'sms' => 'SMS'];

    /** Płatnicy, którym przypominamy (pfron / feer płacą sami). */
    private const PAYERS = ['beneficjent', 'rodzic'];

    private const MONTHS = [1 => 'styczeń', 2 => 'luty', 3 => 'marzec', 4 => 'kwiecień', 5 => 'maj', 6 => 'czerwiec',
                            7 => 'lipiec', 8 => 'sierpień', 9 => 'wrzesień', 10 => 'październik', 11 => 'listopad', 12 => 'grudzień'];

    // ── Ustawienia ───────────────────────────────────────────────────────────

    /**
     * Ustawienia (tabela settings, klucze ti_payrem_*), czytane wprost z bazy — bez pamięci podręcznej org_setting().
     *
     * @return array{enabled:bool,email:bool,sms:bool,grace_days:int,min_amount:float,every_days:int,max_reminders:int}
     */
    public static function settings(): array
    {
        $raw = [];
        foreach (db_all("SELECT key_, value FROM settings WHERE key_ LIKE 'ti_payrem\\_%' ESCAPE '\\'") as $r) $raw[(string)$r['key_']] = (string)$r['value'];
        $bool = fn(string $k, bool $def): bool => array_key_exists($k, $raw) ? $raw[$k] === '1' : $def;
        $int  = fn(string $k, int $def, int $lo, int $hi): int => isset($raw[$k]) && preg_match('/^\d+$/', $raw[$k]) ? max($lo, min($hi, (int)$raw[$k])) : $def;
        $min  = isset($raw['ti_payrem_min_amount']) && is_numeric($raw['ti_payrem_min_amount']) ? max(0.0, min(100000.0, round((float)$raw['ti_payrem_min_amount'], 2))) : 1.0;
        return [
            'enabled'       => $bool('ti_payrem_enabled', false),         // domyślnie WYŁĄCZONE — najpierw przegląd treści
            'email'         => $bool('ti_payrem_email', true),
            'sms'           => $bool('ti_payrem_sms', true),
            'grace_days'    => $int('ti_payrem_grace_days', 0, 0, 60),
            'min_amount'    => $min,
            'every_days'    => $int('ti_payrem_every_days', 1, 1, 30),    // 1 = codziennie
            'max_reminders' => $int('ti_payrem_max', 0, 0, 365),          // 0 = bez limitu
        ];
    }

    /**
     * Walidacja i zapis ustawień (z formularza). Zmiana trafia do audytu.
     *
     * @return array{ok:bool,errors:list<string>}
     */
    public static function saveSettings(array $in, int $userId = 0): array
    {
        $errors = [];
        $int = function (string $k, string $label, int $lo, int $hi) use ($in, &$errors): int {
            $v = trim((string)($in[$k] ?? ''));
            if (!preg_match('/^\d{1,4}$/', $v) || (int)$v < $lo || (int)$v > $hi) { $errors[] = "{$label}: podaj liczbę całkowitą od {$lo} do {$hi}."; return $lo; }
            return (int)$v;
        };
        $grace = $int('grace_days', 'Dni po terminie płatności', 0, 60);
        $every = $int('every_days', 'Odstęp między przypomnieniami (dni)', 1, 30);
        $max   = $int('max_reminders', 'Limit przypomnień na należność', 0, 365);
        $minRaw = str_replace([' ', ','], ['', '.'], trim((string)($in['min_amount'] ?? '')));
        $min = 0.0;
        if (!preg_match('/^\d{1,6}(\.\d{1,2})?$/', $minRaw)) $errors[] = 'Minimalna kwota zaległości: podaj kwotę, np. 1,00 (0 = bez progu).';
        else $min = round((float)$minRaw, 2);
        $new = ['enabled' => !empty($in['enabled']), 'email' => !empty($in['email']), 'sms' => !empty($in['sms']),
                'grace_days' => $grace, 'min_amount' => $min, 'every_days' => $every, 'max_reminders' => $max];
        if ($new['enabled'] && !$new['email'] && !$new['sms']) $errors[] = 'Włącz przynajmniej jeden kanał (e-mail lub SMS) albo wyłącz przypomnienia.';
        if ($errors) return ['ok' => false, 'errors' => $errors];

        $old = self::settings();
        $map = ['ti_payrem_enabled' => $new['enabled'] ? '1' : '0', 'ti_payrem_email' => $new['email'] ? '1' : '0', 'ti_payrem_sms' => $new['sms'] ? '1' : '0',
                'ti_payrem_grace_days' => (string)$grace, 'ti_payrem_min_amount' => number_format($min, 2, '.', ''),
                'ti_payrem_every_days' => (string)$every, 'ti_payrem_max' => (string)$max];
        $st = db()->prepare("INSERT OR REPLACE INTO settings (key_, value) VALUES (?, ?)");
        foreach ($map as $k => $v) $st->execute([$k, $v]);
        audit_log('ti_payment_reminders.settings', ['before' => $old, 'after' => $new], $userId);
        return ['ok' => true, 'errors' => []];
    }

    // ── Zaległości ───────────────────────────────────────────────────────────

    /**
     * Kursanci z wymagalną, niepokrytą należnością na dzień $today.
     *
     * @return list<array{client_id:int,client_name:string,items:list<array{billing_id:int,label:string,due:string,days:int,amount:float}>,
     *                    total:float,oldest_due:string,max_days:int,skip:string}>
     *         skip: niepusty = cały kursant pominięty (powód po polsku)
     */
    public static function overdue(string $today, ?int $onlyClient = null): array
    {
        ti_payment_reminders_migrate();
        $s     = self::settings();
        $limit = date('Y-m-d', strtotime($today . ' -' . $s['grace_days'] . ' days'));
        $def   = (int)K30_TI_PAY_DUE_DAYS_DEFAULT;
        // termin płatności; bez niego (starsze wiersze, opłaty z ti_billing_add_charge, np. po zatwierdzonym odroczeniu): późniejsza z dat
        // „wystawienie + 7 dni” i „koniec okresu rozliczeniowego + 7 dni” — przeniesiona na następny miesiąc płatność nie jest przypominana przed czasem
        $eff   = "COALESCE(NULLIF(b.due_date,''), MAX(date(COALESCE(b.issued_at, b.created_at), '+{$def} days'),
                                                      date(printf('%04d-%02d-01', b.year, b.month), '+1 month', '+{$def} days')))";
        $rows  = db_all(
            "SELECT b.id, b.client_id, b.course_id, b.month, b.year, b.payer_type, {$eff} AS due_eff, cl.name AS client_name, c.name AS course_name
               FROM k30_ti_billing b
               JOIN k30_clients cl ON cl.id = b.client_id
               LEFT JOIN k30_ti_courses c ON c.id = b.course_id AND b.course_id > 0
              WHERE b.status = 'issued' AND COALESCE(b.hours_only, 0) = 0
                AND (b.amount + COALESCE(b.adjustment, 0)) > 0.005
                AND {$eff} < ?" . ($onlyClient ? ' AND b.client_id = ?' : '') . "
              ORDER BY b.client_id, due_eff, b.id",
            $onlyClient ? [$limit, $onlyClient] : [$limit]
        );
        $byClient = [];
        foreach ($rows as $r) $byClient[(int)$r['client_id']][] = $r;

        $out = [];
        foreach ($byClient as $cid => $list) {
            $alloc = ti_client_allocation($cid);                                  // księga na żywo (FIFO), bez zapisu
            $paid  = [];
            foreach ($alloc['rows'] as $ar) $paid[(int)$ar['id']] = $ar;
            $deferred = array_map('intval', array_column(db_all("SELECT billing_id FROM k30_ti_payment_deferrals WHERE client_id = ? AND status = 'pending'", [$cid]), 'billing_id'));

            $defaultPayer = k30_ti_default_payer($cid);                            // rodzic dla małoletniego, inaczej beneficjent
            $items = [];
            foreach ($list as $b) {
                $ar = $paid[(int)$b['id']] ?? null;
                if (!$ar || !empty($ar['ho'])) continue;
                $remaining = round((float)$ar['due'] - (float)$ar['paid'], 2);
                if ($remaining <= 0.005) continue;                                // pokryta (np. wpłatą znaczoną na grupę)
                $pt    = (string)$b['payer_type'];
                $payer = $pt !== '' && isset(K30_TI_PAYERS[$pt]) ? $pt : $defaultPayer;   // jak k30_ti_billing_payer_type()
                if (!in_array($payer, self::PAYERS, true)) continue;
                if (in_array((int)$b['id'], $deferred, true)) continue;           // czeka wniosek o odroczenie
                $period = (self::MONTHS[(int)$b['month']] ?? (string)$b['month']) . ' ' . (int)$b['year'];
                $items[] = ['billing_id' => (int)$b['id'], 'label' => (!empty($b['course_name']) ? $b['course_name'] . ' — ' : 'Zajęcia TI — ') . $period,
                            'due' => (string)$b['due_eff'], 'days' => self::daysBetween((string)$b['due_eff'], $today), 'amount' => $remaining];
            }
            if (!$items) continue;
            $entry = ['client_id' => $cid, 'client_name' => (string)$list[0]['client_name']] + self::totals($items) + ['skip' => ''];
            if (ti_client_no_billing($cid))                                         $entry['skip'] = 'kursant bez rozliczeń (no_billing)';
            elseif (db_one("SELECT 1 AS x FROM k30_ti_wallet_requests WHERE client_id = ? AND status = 'pending' LIMIT 1", [$cid]))
                                                                                    $entry['skip'] = 'zgłoszono wpłatę — czeka na zatwierdzenie';
            elseif ($entry['total'] + 0.0001 < $s['min_amount'])                    $entry['skip'] = 'zaległość poniżej minimalnej kwoty (' . number_format($s['min_amount'], 2, ',', ' ') . ' zł)';
            $out[] = $entry;
        }
        usort($out, fn($a, $b) => [$b['total'], $a['client_name']] <=> [$a['total'], $b['client_name']]);
        return $out;
    }

    /** @return array{total:float,oldest_due:string,max_days:int,items:list<array>} */
    private static function totals(array $items): array
    {
        $total = 0.0; $oldest = ''; $max = 0;
        foreach ($items as $it) {
            $total = round($total + (float)$it['amount'], 2);
            if ($oldest === '' || $it['due'] < $oldest) $oldest = $it['due'];
            $max = max($max, (int)$it['days']);
        }
        return ['items' => array_values($items), 'total' => $total, 'oldest_due' => $oldest, 'max_days' => $max];
    }

    private static function daysBetween(string $from, string $to): int
    {
        return (int)floor((strtotime($to . ' 00:00:00') - strtotime($from . ' 00:00:00')) / 86400);
    }

    // ── Adresaci ─────────────────────────────────────────────────────────────

    /**
     * Kontakt do przypomnień o wpłacie. Małoletni → opiekun (gdy ma dany kanał), inaczej kursant.
     *
     * @return array{name:string,first_name:string,email:string,email_kind:string,phone:string,sms_kind:string}
     */
    public static function contact(int $clientId): array
    {
        $r = db_one(
            "SELECT cl.name, cl.email, cl.phone, a.is_minor, a.guardian_name, a.guardian_email, a.guardian_phone
               FROM k30_clients cl
               LEFT JOIN k30_ti_student_accounts a ON a.client_id = cl.id
              WHERE cl.id = ? ORDER BY a.id LIMIT 1",
            [$clientId]
        ) ?: [];
        $minor  = !empty($r['is_minor']);
        $gEmail = trim((string)($r['guardian_email'] ?? ''));
        $gPhone = trim((string)($r['guardian_phone'] ?? ''));
        $gName  = trim((string)($r['guardian_name'] ?? ''));
        $useGE  = $minor && $gEmail !== '' && filter_var($gEmail, FILTER_VALIDATE_EMAIL);
        $useGP  = $minor && $gPhone !== '';
        $name   = $minor && $gName !== '' && ($useGE || $useGP) ? $gName : trim((string)($r['name'] ?? ''));
        $parts  = preg_split('/\s+/', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return [
            'name'       => $name,
            'first_name' => $parts ? (string)$parts[0] : '',
            'email'      => $useGE ? $gEmail : trim((string)($r['email'] ?? '')),
            'email_kind' => $useGE ? 'opiekun' : 'kursant',
            'phone'      => $useGP ? $gPhone : trim((string)($r['phone'] ?? '')),
            'sms_kind'   => $useGP ? 'opiekun' : 'kursant',
        ];
    }

    public static function maskEmail(string $e): string
    {
        if ($e === '' || !str_contains($e, '@')) return '';
        [$user, $domain] = explode('@', $e, 2);
        return mb_substr($user, 0, 1) . '***@' . $domain;
    }

    public static function maskPhone(string $p): string
    {
        $digits = (string)preg_replace('/\D/', '', $p);
        return $digits === '' ? '' : '***' . substr($digits, -3);
    }

    // ── Plan (co i komu poszłoby dziś) ───────────────────────────────────────

    /**
     * Plan wysyłki na dzień $today — bez żadnych skutków ubocznych. Dla każdego kursanta z zaległością: pozycje, kwoty i decyzja
     * dla kanałów email / sms: action = send | skip, reason (powód pominięcia), kind (kursant|opiekun), masked (zamaskowany adres).
     * Adres / numer do wysyłki leżą w polu to — NIE pokazuj go w interfejsie.
     *
     * @return list<array>
     */
    public static function plan(?string $today = null, ?int $onlyClient = null): array
    {
        $today ??= date('Y-m-d');
        $s       = self::settings();
        $smsOk   = function_exists('sms_channel_ready') && sms_channel_ready();
        $tpl     = ['email' => email_tpl_get(self::TEMPLATE), 'sms' => sms_tpl_get(self::TEMPLATE)];
        $out     = [];
        foreach (self::overdue($today, $onlyClient) as $e) {
            $none = ['action' => 'skip', 'reason' => '', 'kind' => '', 'masked' => '', 'to' => '', 'name' => ''];
            $e['channels'] = ['email' => $none, 'sms' => $none];
            if ($e['skip'] !== '') {
                foreach (['email', 'sms'] as $ch) $e['channels'][$ch]['reason'] = $e['skip'];
                $out[] = $e;
                continue;
            }
            // limit przypomnień na należność: pozycje, które go osiągnęły, nie wchodzą już do wiadomości
            if ($s['max_reminders'] > 0) {
                $keep = array_values(array_filter($e['items'], fn($it) => self::sentDays((int)$it['billing_id']) < $s['max_reminders']));
                if (!$keep) {
                    foreach (['email', 'sms'] as $ch) $e['channels'][$ch]['reason'] = 'osiągnięto limit przypomnień (' . $s['max_reminders'] . ') dla każdej należności';
                    $out[] = $e;
                    continue;
                }
                $e = array_merge($e, self::totals($keep));
            }
            $c = self::contact((int)$e['client_id']);
            foreach (['email', 'sms'] as $ch) {
                $d = &$e['channels'][$ch];
                $to = $ch === 'email' ? $c['email'] : $c['phone'];
                $d = ['action' => 'skip', 'reason' => '', 'kind' => $ch === 'email' ? $c['email_kind'] : $c['sms_kind'], 'to' => $to,
                      'masked' => $ch === 'email' ? self::maskEmail($to) : self::maskPhone($to), 'name' => $c['name']];
                if (!$s[$ch])                                         $d['reason'] = 'kanał wyłączony w ustawieniach';
                elseif (!$tpl[$ch] || !$tpl[$ch]['enabled'])          $d['reason'] = 'szablon wyłączony w edytorze szablonów';
                elseif ($ch === 'email' && ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL))) $d['reason'] = 'brak poprawnego adresu e-mail';
                elseif ($ch === 'sms' && !$smsOk)                     $d['reason'] = 'kanał SMS nie jest skonfigurowany';
                elseif ($ch === 'sms' && $to === '')                  $d['reason'] = 'brak numeru telefonu';
                elseif (ti_virtual_blocked($ch === 'email' ? $to : '', $ch === 'sms' ? $to : '')) $d['reason'] = 'kursant wirtualny — bez wiadomości';
                else {
                    $last = self::lastSent((int)$e['client_id'], $ch);
                    if ($last === $today)                                                   $d['reason'] = 'dziś już wysłano';
                    elseif ($last !== null && self::daysBetween($last, $today) < $s['every_days']) $d['reason'] = 'następne przypomnienie za ' . ($s['every_days'] - self::daysBetween($last, $today)) . ' dn.';
                    else                                                                    $d['action'] = 'send';
                }
                unset($d);
            }
            $out[] = $e;
        }
        return $out;
    }

    /** Data ostatniego udanego przypomnienia (Y-m-d) tym kanałem albo null. */
    private static function lastSent(int $clientId, string $channel): ?string
    {
        $r = db_one("SELECT MAX(run_date) AS d FROM k30_ti_payment_reminders WHERE client_id = ? AND channel = ? AND status = 'sent'", [$clientId, $channel]);
        return !empty($r['d']) ? (string)$r['d'] : null;
    }

    /** Ile różnych dni z udanym przypomnieniem (dowolnym kanałem) objęło tę należność. */
    private static function sentDays(int $billingId): int
    {
        $r = db_one("SELECT COUNT(DISTINCT r.run_date) AS n FROM k30_ti_payment_reminder_items i JOIN k30_ti_payment_reminders r ON r.id = i.reminder_id
                      WHERE i.billing_id = ? AND r.status = 'sent'", [$billingId]);
        return (int)($r['n'] ?? 0);
    }

    // ── Treść ────────────────────────────────────────────────────────────────

    /** „1 dzień”, w pozostałych przypadkach „N dni” (po polsku liczba mnoga tego rzeczownika jest jedna: 2 dni, 5 dni, 22 dni). */
    public static function daysText(int $n): string
    {
        $n = abs($n);
        return $n === 1 ? '1 dzień' : $n . ' dni';
    }

    /** Polskie litery → ASCII i typograficzne znaki → zwykłe (SMS bez polskich znaków mieści 160 znaków w segmencie). */
    public static function ascii(string $s): string
    {
        return strtr($s, ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
                          'Ą' => 'A', 'Ć' => 'C', 'Ę' => 'E', 'Ł' => 'L', 'Ń' => 'N', 'Ó' => 'O', 'Ś' => 'S', 'Ź' => 'Z', 'Ż' => 'Z',
                          '„' => '"', '”' => '"', '“' => '"', '’' => "'", '–' => '-', '—' => '-', '…' => '...', "\u{00A0}" => ' ']);
    }

    /**
     * Zmienne szablonu dla kursanta z zaległością. E-mail: wartości zabezpieczone do HTML (h()) + items_table (gotowy HTML);
     * SMS: czysty tekst, kwoty bez separatora tysięcy, ASCII robi wysyłka.
     *
     * @return array<string,string>
     */
    public static function vars(array $entry, array $contact, string $channel): array
    {
        $org  = defined('ORG_NAME') ? (string)ORG_NAME : 'Placówka';
        $pay  = k30_ti_client_payment((int)$entry['client_id']);
        $base = defined('APP_URL') ? rtrim(APP_URL, '/') : '';
        $sms  = $channel === 'sms';
        $zl   = fn(float $x): string => number_format($x, 2, ',', $sms ? '' : ' ') . ($sms ? ' zl' : ' PLN');
        $v = [
            'org'        => $org,
            'student'    => (string)$entry['client_name'],
            'recipient'  => (string)$contact['name'],
            'greeting'   => $contact['first_name'] !== '' ? ' ' . $contact['first_name'] : '',
            'total'      => $zl((float)$entry['total']),
            'count'      => (string)count($entry['items']),
            'due'        => date('d.m.Y', strtotime((string)$entry['oldest_due'])),
            'days'       => (string)(int)$entry['max_days'],
            'days_text'  => self::daysText((int)$entry['max_days']),
            'account'    => trim((string)$pay['account']) !== '' ? (string)$pay['account'] : 'podany w panelu kursanta',
            'title'      => (string)$pay['title'],
            'portal'     => $base . '/karty30/ti/kursant/login.php',
        ];
        if ($sms) return $v;
        $v = array_map(fn($x) => h($x), $v);
        $v['items_table'] = self::itemsTable($entry['items']);
        return $v;
    }

    /** Tabela zaległych należności do e-maila (HTML, wartości zabezpieczone). */
    private static function itemsTable(array $items): string
    {
        $td  = 'padding:6px 10px;border:1px solid #dee2e6';
        $rows = '';
        foreach ($items as $it) {
            $rows .= '<tr><td style="' . $td . '">' . h($it['label']) . '</td>'
                   . '<td style="' . $td . ';white-space:nowrap">' . h(date('d.m.Y', strtotime((string)$it['due']))) . '</td>'
                   . '<td style="' . $td . ';text-align:right;white-space:nowrap"><strong>' . h(number_format((float)$it['amount'], 2, ',', ' ')) . ' PLN</strong></td></tr>';
        }
        $th = $td . ';background:#f1f3f5;text-align:left';
        return '<table role="presentation" cellspacing="0" cellpadding="0" style="border-collapse:collapse;margin:14px 0;font-size:14px">'
             . '<thead><tr><th style="' . $th . '">Należność</th><th style="' . $th . '">Termin płatności</th><th style="' . $th . ';text-align:right">Do zapłaty</th></tr></thead>'
             . '<tbody>' . $rows . '</tbody></table>';
    }

    // ── Wysyłka ──────────────────────────────────────────────────────────────

    /**
     * Wykonuje przebieg na dzień $opts['today'] (domyślnie dziś): dry_run (podgląd, nic nie wysyła ani nie zapisuje),
     * client_id (tylko ten kursant), user_id (do audytu; w CLI 0). Przy wyłączonych ustawieniach (poza dry_run) nic nie wysyła.
     *
     * @return array{date:string,dry_run:bool,enabled:bool,clients:int,to_send:array{email:int,sms:int},sent:array{email:int,sms:int},failed:int,
     *               skipped:array<string,int>,errors:list<string>,note:string}
     */
    public static function run(array $opts = []): array
    {
        $today = (string)($opts['today'] ?? date('Y-m-d'));
        $dry   = !empty($opts['dry_run']);
        $only  = !empty($opts['client_id']) ? (int)$opts['client_id'] : null;
        $uid   = (int)($opts['user_id'] ?? 0);
        $s     = self::settings();
        $res   = ['date' => $today, 'dry_run' => $dry, 'enabled' => $s['enabled'], 'clients' => 0, 'to_send' => ['email' => 0, 'sms' => 0],
                  'sent' => ['email' => 0, 'sms' => 0], 'failed' => 0, 'skipped' => [], 'errors' => [], 'note' => ''];
        if (!$s['enabled'] && !$dry) { $res['note'] = 'Przypomnienia o wpłatach są wyłączone w ustawieniach.'; return $res; }

        foreach (self::plan($today, $only) as $e) {
            $res['clients']++;
            $contact = null;
            foreach (['email', 'sms'] as $ch) {
                $d = $e['channels'][$ch];
                if ($d['action'] !== 'send') { $res['skipped'][$d['reason']] = ($res['skipped'][$d['reason']] ?? 0) + 1; continue; }
                $res['to_send'][$ch]++;
                if ($dry) continue;
                $contact ??= self::contact((int)$e['client_id']);
                $r = self::deliver($e, $ch, $d, $contact, $today);
                if ($r['status'] === 'sent') $res['sent'][$ch]++;
                elseif ($r['status'] === 'failed') { $res['failed']++; $res['errors'][] = '#' . $e['client_id'] . ' ' . $ch . ': ' . $r['reason']; }
                else $res['skipped'][$r['reason']] = ($res['skipped'][$r['reason']] ?? 0) + 1;
            }
        }
        if (!$dry) {
            try {
                audit_log('ti_payment_reminders.run', ['date' => $today, 'clients' => $res['clients'], 'sent' => $res['sent'], 'failed' => $res['failed'],
                                                       'skipped' => $res['skipped'], 'only_client' => $only], $uid);
            } catch (\Throwable $e) { error_log('[ti_payment_reminders] audyt przebiegu nie zapisany: ' . $e->getMessage()); }
        }
        return $res;
    }

    /**
     * Wysyła jedno przypomnienie (jeden kanał). Najpierw REZERWUJE wiersz w dzienniku (indeks unikalny: jedno przypomnienie na
     * odbiorcę, kanał i dzień) — druga próba tego samego dnia kończy się „pominięto”. Po wysyłce wiersz dostaje status sent / failed.
     *
     * @return array{status:string,reason:string}  status: sent | failed | skipped
     */
    private static function deliver(array $e, string $ch, array $d, array $contact, string $today): array
    {
        $pdo = db();
        try {
            $pdo->prepare("INSERT INTO k30_ti_payment_reminders (run_date, client_id, recipient_kind, channel, status, recipient, amount, items, max_days)
                           VALUES (?, ?, ?, ?, 'pending', ?, ?, ?, ?)")
                ->execute([$today, (int)$e['client_id'], $d['kind'], $ch, $d['masked'], (float)$e['total'], count($e['items']), (int)$e['max_days']]);
        } catch (\PDOException $ex) {
            return ['status' => 'skipped', 'reason' => 'dziś już wysłano'];
        }
        $id = (int)$pdo->lastInsertId();
        $status = 'failed'; $reason = ''; $provider = '';
        try {
            if ($ch === 'email') {
                $r = email_tpl_render(self::TEMPLATE, self::vars($e, $contact, 'email'));
                if (trim($r['subject']) === '' || trim(strip_tags($r['html'])) === '') throw new \RuntimeException('pusty temat lub treść e-maila');
                $mid = mail_queue_add((string)$d['to'], (string)$d['name'], $r['subject'], $r['html'], '', 'ti_payment_reminder', (int)$e['client_id'], '', false);
                if ($mid > 0) $status = 'sent'; else $reason = 'adres zablokowany (kursant wirtualny)';
            } else {
                $m   = sms_tpl_render(self::TEMPLATE, self::vars($e, $contact, 'sms'));
                $msg = trim(self::ascii($m['message']));
                if ($msg === '') throw new \RuntimeException('pusta treść SMS');
                $provider = sms_send((string)$d['to'], $msg, self::TEMPLATE);
                $status = 'sent';
            }
        } catch (\Throwable $ex) {
            $reason = mb_substr(function_exists('sms_scrub') ? sms_scrub($ex->getMessage()) : $ex->getMessage(), 0, 300);
        }
        $pdo->prepare("UPDATE k30_ti_payment_reminders SET status = ?, reason = ?, provider = ? WHERE id = ?")->execute([$status, $reason, $provider, $id]);
        if ($status === 'sent') {
            $ins = $pdo->prepare("INSERT OR IGNORE INTO k30_ti_payment_reminder_items (reminder_id, billing_id, amount) VALUES (?, ?, ?)");
            foreach ($e['items'] as $it) $ins->execute([$id, (int)$it['billing_id'], (float)$it['amount']]);
        }
        return ['status' => $status, 'reason' => $reason];
    }

    // ── Odczyt dla ekranu ────────────────────────────────────────────────────

    /** Ostatnie wysyłki (najnowsze pierwsze) z nazwą kursanta; adresat zamaskowany już w dzienniku. */
    public static function recent(int $limit = 60): array
    {
        ti_payment_reminders_migrate();
        return db_all(
            "SELECT r.*, cl.name AS client_name FROM k30_ti_payment_reminders r LEFT JOIN k30_clients cl ON cl.id = r.client_id
              ORDER BY r.id DESC LIMIT " . max(1, min(500, $limit))
        );
    }

    /** Podsumowanie ostatniego przebiegu z audytu (data, wysłano, błędy) albo null. @return array{at:string,details:array}|null */
    public static function lastRun(): ?array
    {
        audit_logs_migrate();                                                    // tabela powstaje leniwie — przy pierwszym wpisie audytu
        $r = db_one("SELECT created_at, details FROM audit_logs WHERE action = 'ti_payment_reminders.run' ORDER BY id DESC LIMIT 1");
        return $r ? ['at' => (string)$r['created_at'], 'details' => json_decode((string)$r['details'], true) ?: []] : null;
    }
}
