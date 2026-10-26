<?php
/**
 * modules/ti_simple_groups/logic/simpleGroups.php — Szkolenia krótkie TI (SzK, grupy uproszczone: najwyżej 4 terminy).
 * Do 2026-10-14 nazywane KON.xxxxx.yyy.rrrr — prefiks KON przeszedł na numerację kart konsultacji (KON.XXX.MM.RRRR),
 * stare nazwy grup przemianowuje migracja 2026-10_ti_szk_rename (modules/selfrepairDB).
 *
 * Grupa uproszczona = zwykły kurs (k30_ti_courses) z simple_mode > 0 i nazwą SzK.xxxxx.yyy.rrrr
 * (xxxxx — 5 cyfr, kolejny numer w roku; yyy — 3 litery; rrrr — rok pierwszych zajęć).
 * Tworzona w jednym kroku: grupa + uczestnicy (istniejący lub nowi) + 1–4 terminy.
 * Uczestnicy, których WSZYSTKIE aktywne grupy są uproszczone, dostają uproszczony panel kursanta
 * w trybie grupy (1 = tylko terminy, 2 = terminy + płatności, 3 = terminy + płatności uproszczone) —
 * chyba że konto ma własny tryb (k30_ti_student_accounts.simple_mode > 0), on ma pierwszeństwo.
 * Konwerter zamienia istniejącą grupę w uproszczoną (z nazwą SzK) i z powrotem.
 * Opis grupy (internal_desc) widzi tylko kadra — kursant widzi sam numer SzK.
 * Przy tworzeniu uczestnicy bez konta panelu dostają konto automatycznie (jak „Utwórz konto” w dydaktyk/konta.php:
 * login imię.nazwisko, hasło jednorazowe z wymuszoną zmianą, 12-cyfrowy numer kursanta, rachunek w portalu płatności, SMS).
 */
require_once dirname(__DIR__, 3) . '/includes/karty30.php';
require_once dirname(__DIR__, 3) . '/includes/ti_periods.php';
require_once dirname(__DIR__, 3) . '/includes/sms.php';

final class TiSimpleGroups
{
    public const MODES = [1 => 'tylko terminy', 2 => 'terminy + płatności', 3 => 'terminy + płatności uproszczone'];
    public const MAX_LESSONS = 4;
    public const NAME_RE = '/^SzK\.\d{5}\.\p{Lu}{3}\.\d{4}$/u';
    /** Forma pojedynczych zajęć (k30_ti_sessions.lesson_method) — w grupie można je mieszać. */
    public const METHODS = ['stacjonarna' => 'Stacjonarnie', 'zdalna_zoom' => 'Online — Zoom', 'zdalna_inne' => 'Online — inne'];

    /** Nazwa SzK.xxxxx.yyy.rrrr z części; litery wielkie, bez spacji. */
    public static function name(int $number, string $letters, int $year): string
    {
        return sprintf('SzK.%05d.%s.%04d', $number, mb_strtoupper($letters), $year);
    }

    public static function isValidName(string $name): bool { return (bool)preg_match(self::NAME_RE, $name); }

    /** Kolejny wolny numer xxxxx w danym roku (po istniejących grupach SzK.*.rrrr). */
    public static function nextNumber(int $year): int
    {
        $max = 0;
        foreach (db_all("SELECT name FROM k30_ti_courses WHERE name LIKE ?", ['SzK.%.' . $year]) as $r) {
            if (preg_match('/^SzK\.(\d{5})\./', (string)$r['name'], $m)) $max = max($max, (int)$m[1]);
        }
        return $max + 1;
    }

    /** Propozycja liter yyy: pierwsze litery nazwiska i imienia pierwszego uczestnika („Jan Kowalski” → KOJ). */
    public static function suggestLetters(string $personName): string
    {
        $p = preg_split('/\s+/u', trim($personName)) ?: [];
        $p = array_values(array_filter($p, fn($x) => $x !== ''));
        if (!$p) return 'XXX';
        $last = (string)end($p); $first = (string)$p[0];
        $l = mb_strtoupper(mb_substr(preg_replace('/[^\p{L}]/u', '', $last), 0, 2) . mb_substr(preg_replace('/[^\p{L}]/u', '', $first), 0, 1));
        return mb_strlen($l) === 3 ? $l : str_pad($l, 3, 'X');
    }

    /**
     * Tryb panelu dla konta kursanta: własny tryb konta, a gdy 0 — tryb grup (jeśli wszystkie aktywne grupy
     * kursanta są uproszczone, najszerszy z ich trybów). 0 = pełny panel.
     */
    public static function accountMode(int $accountId): int
    {
        if ($accountId <= 0) return 0;
        try {
            $a = db_one("SELECT client_id, simple_mode FROM k30_ti_student_accounts WHERE id=?", [$accountId]);
            if (!$a) return 0;
            if ((int)$a['simple_mode'] > 0) return (int)$a['simple_mode'];
            $g = db_one(
                "SELECT COUNT(*) AS n, SUM(CASE WHEN c.simple_mode > 0 THEN 1 ELSE 0 END) AS s, MAX(c.simple_mode) AS m
                   FROM k30_ti_enrollments e JOIN k30_ti_courses c ON c.id = e.course_id
                  WHERE e.client_id = ? AND e.status = 'active' AND c.is_active = 1", [(int)$a['client_id']]);
            return ((int)($g['n'] ?? 0) > 0 && (int)$g['n'] === (int)$g['s']) ? (int)$g['m'] : 0;
        } catch (\Throwable $e) {
            return 0;   // kolumny jeszcze bez migracji
        }
    }

    /**
     * Utworzenie grupy uproszczonej.
     * $in: number, letters, year (części nazwy), instructor_id, class_type, is_online, location, meeting_url, mode (1–3),
     *      desc (opis tylko dla kadry), accounts (bool — załóż konta panelu), send_logins (bool — SMS z loginem i hasłem),
     *      notify (bool — SMS o terminach), clients: int[] (istniejący), new_people: [['name','phone','email'], …],
     *      lessons: [['date','from','to','method'], …] (puste wiersze pomijane, 1–4 terminy; method z METHODS).
     * „Hybryda krótka” (class_type hybrid): zajęcia mieszane — część stacjonarnie, część online; mieszane formy
     * terminów ustawiają ją same, nawet gdy wybrano inny rodzaj.
     * @return array{ok:bool, errors:string[], warnings:string[], course_id:int, name:string, no_account:string[], accounts:array}
     */
    public static function create(array $in, int $uid, string $byName): array
    {
        $err = []; $warn = [];
        $mode   = (int)($in['mode'] ?? 1);
        $instr  = max(0, (int)($in['instructor_id'] ?? 0));
        $type   = in_array($in['class_type'] ?? '', ['individual', 'group', 'hybrid'], true) ? (string)$in['class_type'] : 'individual';
        if (!isset(self::MODES[$mode])) $err[] = 'Wybierz tryb panelu uczestników.';

        // Terminy
        $lessons = [];
        foreach ((array)($in['lessons'] ?? []) as $l) {
            $d = trim((string)($l['date'] ?? '')); $f = trim((string)($l['from'] ?? '')); $t = trim((string)($l['to'] ?? ''));
            $lm = isset(self::METHODS[$l['method'] ?? '']) ? (string)$l['method'] : (!empty($in['is_online']) ? 'zdalna_zoom' : 'stacjonarna');
            if ($d === '' && $f === '' && $t === '') continue;
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || !preg_match('/^\d{2}:\d{2}$/', $f) || !preg_match('/^\d{2}:\d{2}$/', $t) || $t <= $f) {
                $err[] = 'Termin ' . ($d ?: '(bez daty)') . ': podaj datę oraz godzinę od i do (koniec po początku).'; continue;
            }
            if ($pc = ti_period_closed_for_date($d)) { $err[] = 'Termin ' . date('d.m.Y', strtotime($d)) . ': ' . ti_period_closed_msg($pc); continue; }
            if ($instr) {
                $av = ti_instructor_available_at($instr, $d, $f, $t);
                if (!$av['ok']) $warn[] = 'Termin ' . date('d.m.Y', strtotime($d)) . ' ' . $f . ': ' . $av['reason'];
            }
            $lessons[] = ['date' => $d, 'from' => $f, 'to' => $t, 'method' => $lm];
        }
        if (!$lessons) $err[] = 'Dodaj przynajmniej jeden termin zajęć.';
        if (count($lessons) > self::MAX_LESSONS) $err[] = 'Grupa uproszczona ma najwyżej ' . self::MAX_LESSONS . ' terminy — dłuższy kurs załóż jako zwykłą grupę.';
        usort($lessons, fn($a, $b) => [$a['date'], $a['from']] <=> [$b['date'], $b['from']]);

        // Uczestnicy
        $clients = array_values(array_unique(array_filter(array_map('intval', (array)($in['clients'] ?? [])))));
        foreach ($clients as $c) if (!db_one("SELECT 1 FROM k30_clients WHERE id=?", [$c])) $err[] = 'Nie znaleziono uczestnika #' . $c . '.';
        $newPeople = [];
        foreach ((array)($in['new_people'] ?? []) as $p) {
            $n = trim((string)($p['name'] ?? '')); $e = trim((string)($p['email'] ?? '')); $ph = trim((string)($p['phone'] ?? ''));
            if ($n === '' && $e === '' && $ph === '') continue;
            if ($n === '') { $err[] = 'Nowa osoba: podaj imię i nazwisko.'; continue; }
            if ($e !== '' && !filter_var($e, FILTER_VALIDATE_EMAIL)) { $err[] = 'Nowa osoba ' . $n . ': nieprawidłowy e-mail.'; continue; }
            $newPeople[] = ['name' => $n, 'email' => $e, 'phone' => $ph];
        }
        if (!$clients && !$newPeople) $err[] = 'Dodaj przynajmniej jednego uczestnika.';

        // Nazwa SzK.xxxxx.yyy.rrrr
        $year = (int)($in['year'] ?? 0) ?: (int)substr($lessons[0]['date'] ?? date('Y'), 0, 4);
        $num  = (int)($in['number'] ?? 0) ?: self::nextNumber($year);
        $name = self::name($num, (string)($in['letters'] ?? ''), $year);
        if (!self::isValidName($name)) $err[] = 'Nazwa musi mieć postać SzK.xxxxx.yyy.rrrr — 5 cyfr, 3 litery, rok.';
        elseif (db_one("SELECT 1 FROM k30_ti_courses WHERE name=?", [$name])) $err[] = 'Grupa ' . $name . ' już istnieje — zmień numer albo litery.';

        // Forma grupy z form zajęć: same online → is_online; mieszane → „Hybryda krótka” (class_type hybrid, stawki online/stacjonarne osobno)
        $remote = array_filter($lessons, fn($l) => str_starts_with($l['method'], 'zdalna'));
        $online = $lessons && count($remote) === count($lessons) ? 1 : 0;
        if ($remote && count($remote) < count($lessons)) $type = 'hybrid';
        $url = trim((string)($in['meeting_url'] ?? ''));

        if ($err) return ['ok' => false, 'errors' => $err, 'warnings' => $warn, 'course_id' => 0, 'name' => $name, 'no_account' => [], 'accounts' => []];

        $pdo = db(); $pdo->beginTransaction();
        try {
            $cid = (int)db_insert('k30_ti_courses', [
                'name' => $name, 'display_name' => '', 'description' => '', 'internal_desc' => mb_substr(trim((string)($in['desc'] ?? '')), 0, 300),
                'instructor_id' => $instr ?: null, 'location' => mb_substr(trim((string)($in['location'] ?? '')), 0, 200),
                'default_meeting_url' => $remote ? $url : '',
                'is_active' => 1, 'track_attendance' => 1, 'is_online' => $online, 'class_type' => $type,
                'billing_model' => 2, 'group_code' => k30_ti_generate_group_code(), 'simple_mode' => $mode,
                'created_by' => $uid ?: null, 'created_at' => date('Y-m-d H:i:s'),
            ]);
            foreach ($newPeople as $p) {
                $clients[] = (int)db_insert('k30_clients', ['name' => $p['name'], 'email' => $p['email'] ?: null, 'phone' => $p['phone'] ?: null,
                                                            'status' => 'enrolled', 'created_by' => $uid ?: null]);
            }
            $start = $lessons[0]['date'];
            $enr = $pdo->prepare("INSERT INTO k30_ti_enrollments (course_id, client_id, start_date, status) VALUES (?,?,?,'active')
                                  ON CONFLICT(course_id, client_id) DO UPDATE SET status='active', start_date=excluded.start_date");
            foreach ($clients as $c) $enr->execute([$cid, $c, $start]);
            $att = $pdo->prepare("INSERT OR IGNORE INTO k30_ti_attendance (session_id, client_id, attended) VALUES (?,?,0)");
            foreach ($lessons as $l) {
                $dur = (int)((strtotime('2000-01-01 ' . $l['to']) - strtotime('2000-01-01 ' . $l['from'])) / 60);
                $sid = (int)db_insert('k30_ti_sessions', [
                    'course_id' => $cid, 'lesson_date' => $l['date'], 'time_from' => $l['from'], 'time_to' => $l['to'], 'duration_min' => $dur,
                    'status' => 'planned', 'lesson_method' => $l['method'],
                    'meeting_url' => str_starts_with($l['method'], 'zdalna') ? $url : '',
                    'created_by' => $uid ?: null, 'created_at' => date('Y-m-d H:i:s'),
                ]);
                foreach ($clients as $c) $att->execute([$sid, $c]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            error_log('ti_simple_groups create: ' . $e->getMessage());
            return ['ok' => false, 'errors' => ['Nie udało się utworzyć grupy — spróbuj ponownie.'], 'warnings' => $warn, 'course_id' => 0, 'name' => $name, 'no_account' => [], 'accounts' => []];
        }
        foreach ($newPeople as $p) {
            try { k30_sync_to_crm(['name' => $p['name'], 'email' => $p['email'], 'phone' => $p['phone']], $uid); } catch (\Throwable $e) {}
        }
        ti_course_log($cid, 'create', 'Utworzono szkolenie krótkie „' . $name . '” (' . self::MODES[$mode] . ($type === 'hybrid' ? ', hybryda krótka' : '') . '), terminów: ' . count($lessons)
                      . ' (online: ' . count($remote) . '), uczestników: ' . count($clients) . '.', $uid, $byName);

        if (!empty($in['notify'])) {
            $when = implode(', ', array_map(fn($l) => date('d.m', strtotime($l['date'])) . ' ' . $l['from'], $lessons));
            try { ti_lesson_sms_notify($cid, 'Zaplanowano zajecia: ' . $when . '. Szczegoly w panelu kursanta.', 'ti_lesson_added'); } catch (\Throwable $e) {}
        }
        if ($type === 'hybrid' && ($in['class_type'] ?? '') !== 'hybrid') $warn[] = 'Terminy mają różne formy — grupa została założona jako „Hybryda krótka”.';
        $accounts = [];
        if (!empty($in['accounts'])) {
            foreach ($clients as $c) {
                try { if ($a = self::createAccount($c, $uid, !empty($in['send_logins']))) $accounts[] = $a; }
                catch (\Throwable $e) { error_log('ti_simple_groups account: ' . $e->getMessage()); $warn[] = 'Nie udało się założyć konta dla uczestnika #' . $c . '.'; }
            }
            if ($accounts) ti_course_log($cid, 'accounts', 'Założono konta panelu kursanta: ' . implode(', ', array_column($accounts, 'name')) . '.', $uid, $byName);
        }
        return ['ok' => true, 'errors' => [], 'warnings' => $warn, 'course_id' => $cid, 'name' => $name, 'no_account' => self::withoutAccount($clients), 'accounts' => $accounts];
    }

    /**
     * Lista grup uproszczonych (simple_mode > 0) z prowadzącym, uczestnikami, terminami (odbyte / wszystkie,
     * najbliższy) i formami zajęć. $active: true = tylko aktywne, false = wszystkie (także zamknięte i anulowane).
     */
    public static function listGroups(bool $active = true): array
    {
        return db_all(
            "SELECT c.id, c.name, c.internal_desc, c.simple_mode, c.class_type, c.is_active, c.status, u.name AS instructor_name, c.zoom_meeting_id, c.default_meeting_url,
                    (SELECT COUNT(*) FROM k30_ti_enrollments e WHERE e.course_id = c.id AND e.status = 'active') AS people,
                    (SELECT GROUP_CONCAT(cl.name, ', ') FROM k30_ti_enrollments e JOIN k30_clients cl ON cl.id = e.client_id
                      WHERE e.course_id = c.id AND e.status = 'active') AS people_names,
                    (SELECT COUNT(*) FROM k30_ti_sessions s WHERE s.course_id = c.id AND s.draft_id IS NULL AND s.status NOT IN ('draft','reserved','cancelled')) AS lessons,
                    (SELECT COUNT(*) FROM k30_ti_sessions s WHERE s.course_id = c.id AND s.status IN ('held','individual_change','remote_material')) AS held,
                    (SELECT COUNT(*) FROM k30_ti_sessions s WHERE s.course_id = c.id AND s.draft_id IS NULL AND s.status NOT IN ('draft','reserved','cancelled') AND s.lesson_method LIKE 'zdalna%') AS online,
                    (SELECT MIN(s.lesson_date || ' ' || s.time_from) FROM k30_ti_sessions s WHERE s.course_id = c.id AND s.status = 'planned' AND s.lesson_date >= date('now')) AS next_lesson,
                    (SELECT MIN(s.lesson_date) FROM k30_ti_sessions s WHERE s.course_id = c.id AND s.draft_id IS NULL) AS first_lesson
               FROM k30_ti_courses c LEFT JOIN users u ON u.id = c.instructor_id
              WHERE c.simple_mode > 0" . ($active ? " AND c.is_active = 1 AND COALESCE(c.status,'') NOT IN ('cancelled','archived')" : '') . "
              ORDER BY c.is_active DESC, first_lesson DESC, c.name DESC");
    }

    /**
     * Konto panelu kursanta dla uczestnika bez konta — te same zasady co „Utwórz konto” w dydaktyk/konta.php.
     * @return array{name:string, login:string, password:string, sms:string}|null  null = konto już istnieje
     */
    /** $szk — konto szkolenia krótkiego (login 1234k); false = zwykły kursant (imie.nazwisko), np. nabór. */
    public static function createAccount(int $clientId, int $uid, bool $sendSms, bool $szk = true): ?array
    {
        if (db_one("SELECT 1 FROM k30_ti_student_accounts WHERE client_id=?", [$clientId])) return null;
        $c = db_one("SELECT id, name, phone FROM k30_clients WHERE id=?", [$clientId]);
        if (!$c) return null;
        require_once dirname(__DIR__, 2) . '/ti_login/logic/TiLoginFormat.php';
        $login = $szk ? TiLoginFormat::numberLogin(TiLoginFormat::SZK) : TiLoginFormat::nameLogin((string)$c['name']);
        $words = ['Kot', 'Pies', 'Dom', 'Las', 'Rok', 'Nos', 'Byk', 'Lis', 'Mak', 'Rak'];
        $pass = $words[random_int(0, count($words) - 1)] . random_int(10, 99);   // jednorazowe — kursant zmienia przy pierwszym logowaniu
        db_insert('k30_ti_student_accounts', [
            'client_id' => $clientId, 'login' => $login, 'password_hash' => password_hash($pass, PASSWORD_BCRYPT),
            'student_no' => self::studentNo($clientId), 'is_active' => 1, 'must_change_password' => 1,
            'created_by' => $uid ?: null, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        try {
            require_once dirname(__DIR__, 2) . '/payment_portal/logic/paymentPortal.php';
            pp_ti_sync_nrb($clientId, 'grupa uproszczona', null);   // rachunek wirtualny z numeru kursanta
        } catch (\Throwable $e) {}
        $sms = '';
        if ($sendSms) {
            $phone = trim((string)($c['phone'] ?? ''));
            if ($phone === '') $sms = 'brak telefonu — przekaż ręcznie';
            elseif (!sms_channel_ready()) $sms = 'SMS wyłączony — przekaż ręcznie';
            else {
                $org = defined('ORG_NAME') ? ORG_NAME : 'Panel';
                try { sms_send($phone, "{$org} - panel kursanta. Login: {$login}, haslo: {$pass}", 'access_data'); $sms = 'wysłano SMS'; }
                catch (\Throwable $e) { $sms = 'błąd SMS — przekaż ręcznie'; }
            }
        }
        return ['name' => (string)$c['name'], 'login' => $login, 'password' => $pass, 'sms' => $sms];
    }

    /** Login „j.kowalski” bez polskich znaków (jak _gen_student_login w konta.php). */
    private static function loginBase(string $name): string
    {
        $n = strtr($name, ['ą'=>'a','ć'=>'c','ę'=>'e','ł'=>'l','ń'=>'n','ó'=>'o','ś'=>'s','ź'=>'z','ż'=>'z',
                           'Ą'=>'A','Ć'=>'C','Ę'=>'E','Ł'=>'L','Ń'=>'N','Ó'=>'O','Ś'=>'S','Ź'=>'Z','Ż'=>'Z']);
        $p = preg_split('/\s+/', trim($n)) ?: [''];
        $l = preg_replace('/[^a-z0-9._-]/', '', strtolower(count($p) >= 2 ? $p[0][0] . '.' . end($p) : $p[0]));
        return $l !== '' ? $l : 'kursant';
    }

    /** Unikalny 12-cyfrowy numer kursanta, niekolidujący z rachunkami wirtualnymi innych uczestników. */
    public static function studentNo(int $clientId): string
    {
        do {
            $no = ''; for ($i = 0; $i < 12; $i++) $no .= random_int(0, 9);
            $clash = (bool)db_one("SELECT 1 FROM k30_ti_student_accounts WHERE student_no=?", [$no]);
            if (!$clash) { try { $clash = (bool)db_one("SELECT 1 FROM payment_portal_users WHERE substr(individual_nrb,15,12)=? AND participant_id!=?", [$no, $clientId]); } catch (\Throwable $e) {} }
        } while ($clash);
        return $no;
    }

    /**
     * PDF z loginami i hasłami nowych kont grupy: strona zbiorcza dla kadry + kartki do wręczenia uczestnikom
     * (numer grupy SzK, terminy zajęć, login, hasło jednorazowe, adres logowania). Opisu grupy NIE drukujemy —
     * kartki trafiają do kursantów. $rows: [['name','login','password'], …] (z sesji — hasła nie są w bazie).
     */
    public static function credentialsPdf(int $courseId, array $rows): string
    {
        $c = db_one("SELECT name, location, default_meeting_url FROM k30_ti_courses WHERE id=?", [$courseId]) ?: ['name' => ''];
        $days = ['niedziela', 'poniedziałek', 'wtorek', 'środa', 'czwartek', 'piątek', 'sobota'];
        $terms = [];
        foreach (db_all("SELECT lesson_date, time_from, time_to, lesson_method FROM k30_ti_sessions WHERE course_id=? AND draft_id IS NULL
                          AND status NOT IN ('draft','reserved','cancelled') ORDER BY lesson_date, time_from", [$courseId]) as $s) {
            $terms[] = date('d.m.Y', strtotime((string)$s['lesson_date'])) . ' (' . $days[(int)date('w', strtotime((string)$s['lesson_date']))] . '), '
                     . substr((string)$s['time_from'], 0, 5) . '–' . substr((string)$s['time_to'], 0, 5)
                     . (str_starts_with((string)$s['lesson_method'], 'zdalna') ? ' — online' : (trim((string)($c['location'] ?? '')) !== '' ? ' — ' . $c['location'] : ' — stacjonarnie'));
        }
        $org   = defined('ORG_NAME') ? ORG_NAME : 'Panel kursanta';
        $url   = rtrim(defined('APP_URL') ? APP_URL : '', '/') . '/karty30/ti/kursant/login.php';
        $e     = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $termsHtml = $terms ? '<ul style="margin:2mm 0 0 0;padding-left:5mm">' . implode('', array_map(fn($t) => '<li>' . $e($t) . '</li>', $terms)) . '</ul>' : '';

        $html = '<style>body{font-family:dejavusans;font-size:10pt;color:#1d1d1d}h1{font-size:14pt;margin:0 0 2mm;padding-bottom:2mm;border-bottom:.8mm solid #1ba1e2}
                 table.t{border-collapse:collapse;width:100%}table.t th,table.t td{border:0.3mm solid #d1d5db;padding:2mm;text-align:left}
                 table.t th{background:#1d1d1d;color:#fff;border-color:#1d1d1d}.mono{font-family:dejavusansmono;font-size:11pt}
                 .slip{border:0.4mm dashed #4b5563;border-left:1.6mm solid #1ba1e2;padding:5mm;margin:0 0 6mm 0;page-break-inside:avoid}
                 .slip .big{font-family:dejavusansmono;font-size:14pt;font-weight:bold}.muted{color:#4b5563;font-size:8.5pt}</style>';
        $html .= '<h1>Dane logowania — grupa ' . $e($c['name']) . '</h1>'
               . '<p class="muted">Wydruk dla kadry, ' . date('d.m.Y H:i') . '. Hasła są jednorazowe — przy pierwszym logowaniu kursant ustawia własne. Po wręczeniu kartek zniszcz tę stronę.</p>'
               . '<table class="t"><thead><tr><th style="width:8mm">#</th><th>Uczestnik</th><th>Login</th><th>Hasło jednorazowe</th></tr></thead><tbody>';
        foreach (array_values($rows) as $i => $r) {
            $html .= '<tr><td>' . ($i + 1) . '</td><td>' . $e($r['name']) . '</td><td class="mono">' . $e($r['login']) . '</td><td class="mono">' . $e($r['password']) . '</td></tr>';
        }
        $html .= '</tbody></table><p class="muted">Adres logowania: ' . $e($url) . '</p>';
        $html .= '<pagebreak />';
        foreach ($rows as $r) {
            $html .= '<div class="slip"><div class="muted">' . $e($org) . ' — panel kursanta</div>'
                   . '<p style="margin:2mm 0"><strong>' . $e($r['name']) . '</strong> · grupa ' . $e($c['name']) . '</p>'
                   . '<table style="width:100%"><tr><td style="width:50%">Login:<br><span class="big">' . $e($r['login']) . '</span></td>'
                   . '<td>Hasło jednorazowe:<br><span class="big">' . $e($r['password']) . '</span></td></tr></table>'
                   . '<p style="margin:3mm 0 0">Zaloguj się na: <strong>' . $e($url) . '</strong><br>Przy pierwszym logowaniu ustawisz własne hasło. W panelu zobaczysz terminy zajęć i możesz je odwołać lub przesunąć.</p>'
                   . ($termsHtml !== '' ? '<p style="margin:3mm 0 0"><strong>Twoje zajęcia:</strong></p>' . $termsHtml : '')
                   . '</div>';
        }
        require_once dirname(__DIR__, 3) . '/vendor/autoload.php';
        $tmp = (defined('UPLOAD_DIR') ? UPLOAD_DIR : sys_get_temp_dir() . '/') . 'mpdf_tmp';
        if (!is_dir($tmp)) @mkdir($tmp, 0755, true);
        // Stopka z kodami (rejestr dokumentów: QR + Code128 + kod) — kod na grupę, zestaw loginów i dzień wydruku (bez haseł); błąd kodów nie blokuje PDF
        require_once dirname(__DIR__, 3) . '/includes/ti_terms.php';
        $codes = ti_pdf_print_codes('ti_szk_dane_logowania', $courseId . '|' . substr(md5(json_encode(array_column($rows, 'login'))), 0, 10) . '|' . date('Ymd'), 'Dane logowania — grupa ' . (string)$c['name']);
        $pdf = new \Mpdf\Mpdf(['mode' => 'utf-8', 'format' => 'A4', 'margin_left' => 15, 'margin_right' => 15, 'margin_top' => 15, 'margin_bottom' => $codes ? 26 : 15, 'margin_footer' => 5,
                                'default_font' => 'dejavusans', 'tempDir' => $tmp]);
        $pdf->SetTitle('Dane logowania — ' . $c['name']);
        $pdf->SetHTMLFooter(ti_mpdf_codes_html($codes));
        $pdf->WriteHTML($html);
        return $pdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
    }

    /** Nazwiska uczestników bez konta w panelu kursanta (nie zalogują się, dopóki konto nie powstanie). */
    public static function withoutAccount(array $clientIds): array
    {
        $out = [];
        foreach ($clientIds as $c) {
            if (!db_one("SELECT 1 FROM k30_ti_student_accounts WHERE client_id=?", [(int)$c]))
                $out[] = (string)(db_one("SELECT name FROM k30_clients WHERE id=?", [(int)$c])['name'] ?? ('#' . $c));
        }
        return $out;
    }

    /**
     * Konwerter: zwykła grupa → uproszczona (mode 1–3, opcjonalnie nowa nazwa SzK) albo uproszczona → zwykła (mode 0,
     * opcjonalnie nowa nazwa). Lekcje, zapisy i rozliczenia zostają bez zmian.
     * @return array{ok:bool, msg:string}
     */
    public static function convert(int $courseId, int $mode, string $newName, int $uid, string $byName, ?string $desc = null): array
    {
        $c = db_one("SELECT id, name, simple_mode FROM k30_ti_courses WHERE id=?", [$courseId]);
        if (!$c) return ['ok' => false, 'msg' => 'Nie znaleziono grupy.'];
        if ($mode !== 0 && !isset(self::MODES[$mode])) return ['ok' => false, 'msg' => 'Nieznany tryb.'];
        $newName = trim($newName);
        if ($newName === '') $newName = (string)$c['name'];
        if ($mode > 0 && !self::isValidName($newName)) return ['ok' => false, 'msg' => 'Grupa uproszczona musi mieć nazwę SzK.xxxxx.yyy.rrrr — 5 cyfr, 3 litery, rok.'];
        if ($newName !== $c['name'] && db_one("SELECT 1 FROM k30_ti_courses WHERE name=? AND id<>?", [$newName, $courseId])) return ['ok' => false, 'msg' => 'Grupa ' . $newName . ' już istnieje.'];
        $oldDesc = (string)(db_one("SELECT internal_desc FROM k30_ti_courses WHERE id=?", [$courseId])['internal_desc'] ?? '');
        $desc = $desc === null ? $oldDesc : mb_substr(trim($desc), 0, 300);
        if ($mode === (int)$c['simple_mode'] && $newName === $c['name'] && $desc === $oldDesc) return ['ok' => false, 'msg' => 'Nic się nie zmienia — wybierz inny tryb, nazwę albo opis.'];
        db()->prepare("UPDATE k30_ti_courses SET internal_desc=? WHERE id=?")->execute([$desc, $courseId]);
        db()->prepare("UPDATE k30_ti_courses SET simple_mode=?, name=? WHERE id=?")->execute([$mode, $newName, $courseId]);
        $from = (int)$c['simple_mode'] > 0 ? 'uproszczona (' . self::MODES[(int)$c['simple_mode']] . ')' : 'zwykła';
        $to   = $mode > 0 ? 'uproszczona (' . self::MODES[$mode] . ')' : 'zwykła';
        $det  = ($from !== $to ? $from . ' → ' . $to : $to) . ($newName !== $c['name'] ? '; nazwa: ' . $c['name'] . ' → ' . $newName : '')
              . ($desc !== $oldDesc ? '; opis: ' . ($desc !== '' ? $desc : '(usunięty)') : '');
        ti_course_log($courseId, 'convert_simple', 'Konwerter grup: ' . $det, $uid, $byName);
        return ['ok' => true, 'msg' => ($from !== $to ? 'Grupa przekonwertowana: ' : 'Zapisano zmiany grupy: ') . $det . '.'];
    }

    /**
     * Stały link Zoom szkolenia krótkiego (jak kurs.php → gen_course_zoom): spotkanie Zoom typu 3 na koncie
     * organizacji z prowadzącym i współprowadzącymi jako alternative hosts; link trafia do grupy (default_meeting_url,
     * zoom_meeting_id) i do przyszłych zajęć „Online — Zoom” bez własnego linku (albo ze starym linkiem grupy).
     * Ponowne generowanie usuwa poprzednie spotkanie. @return array{ok:bool, msg:string, url:string}
     */
    public static function zoomGenerate(int $courseId, int $uid, string $byName = ''): array
    {
        require_once dirname(__DIR__, 3) . '/includes/zoom.php';
        $c = db_one("SELECT id, name, internal_desc, zoom_meeting_id, default_meeting_url FROM k30_ti_courses WHERE id = ?", [$courseId]);
        if (!$c) return ['ok' => false, 'msg' => 'Nie znaleziono grupy.', 'url' => ''];
        if (!zoom_enabled()) return ['ok' => false, 'msg' => 'Integracja Zoom nie jest skonfigurowana (Ustawienia → Zoom).', 'url' => ''];
        $api = new ZoomAPI();
        $oldUrl = (string)$c['default_meeting_url'];
        if (!empty($c['zoom_meeting_id'])) {
            try { $api->delete_meeting((string)$c['zoom_meeting_id']); $api->log('delete', (string)$c['zoom_meeting_id'], 'ok', 'SzK: przed ponownym wygenerowaniem', $courseId, $uid); } catch (\Throwable $e) {}
        }
        $alt = k30_ti_course_zoom_alt_hosts($courseId);
        $host = (string)(db_one("SELECT u.email FROM k30_ti_courses c JOIN users u ON u.id = c.instructor_id WHERE c.id = ?", [$courseId])['email'] ?? '');
        $warn = '';
        if ($host !== '') {
            try { $api->validate_user_email($host); }
            catch (\RuntimeException $ex) { $warn = ' ' . $ex->getMessage() . ' Link utworzono bez współgospodarzy.'; $alt = ''; $host = ''; }
        }
        try {
            $m = $api->create_meeting((string)$c['name'], 'Szkolenie krótkie TI — stały link grupy', $alt);
        } catch (\RuntimeException $ex) {
            $api->log('create', '', 'error', $ex->getMessage(), $courseId, $uid);
            return ['ok' => false, 'msg' => 'Błąd Zoom: ' . $ex->getMessage(), 'url' => ''];
        }
        db()->prepare("UPDATE k30_ti_courses SET zoom_meeting_id = ?, default_meeting_url = ?, zoom_host_email = ? WHERE id = ?")
            ->execute([$m['meeting_id'], $m['join_url'], $host, $courseId]);
        $st = db()->prepare("UPDATE k30_ti_sessions SET meeting_url = ? WHERE course_id = ? AND lesson_method = 'zdalna_zoom'
                               AND status IN ('planned','draft','reserved') AND lesson_date >= date('now')
                               AND (meeting_url = '' OR meeting_url = ?)");
        $st->execute([$m['join_url'], $courseId, $oldUrl]);
        $n = $st->rowCount();
        $api->log('create', $m['meeting_id'], 'ok', 'SzK alt_hosts=' . $alt, $courseId, $uid);
        ti_course_log($courseId, 'zoom', 'Wygenerowano stały link Zoom grupy' . ($n ? " (zajęcia online: $n)" : '') . '.', $uid, $byName);
        return ['ok' => true, 'msg' => 'Link Zoom wygenerowany' . ($n ? " i wpisany do zajęć online ($n)" : '') . '.' . $warn, 'url' => $m['join_url']];
    }

    /** Usuwa stały link Zoom grupy (spotkanie w Zoom, link grupy i ten sam link w przyszłych zajęciach). */
    public static function zoomClear(int $courseId, int $uid, string $byName = ''): array
    {
        require_once dirname(__DIR__, 3) . '/includes/zoom.php';
        $c = db_one("SELECT id, zoom_meeting_id, default_meeting_url FROM k30_ti_courses WHERE id = ?", [$courseId]);
        if (!$c) return ['ok' => false, 'msg' => 'Nie znaleziono grupy.'];
        if (!empty($c['zoom_meeting_id']) && zoom_enabled()) {
            $api = new ZoomAPI();
            try { $api->delete_meeting((string)$c['zoom_meeting_id']); $api->log('delete', (string)$c['zoom_meeting_id'], 'ok', 'SzK: usunięty przez kierownika', $courseId, $uid); } catch (\Throwable $e) {}
        }
        if ((string)$c['default_meeting_url'] !== '') {
            db()->prepare("UPDATE k30_ti_sessions SET meeting_url = '' WHERE course_id = ? AND meeting_url = ? AND lesson_date >= date('now') AND status IN ('planned','draft','reserved')")
                ->execute([$courseId, (string)$c['default_meeting_url']]);
        }
        db()->prepare("UPDATE k30_ti_courses SET zoom_meeting_id = '', default_meeting_url = '', zoom_host_email = '' WHERE id = ?")->execute([$courseId]);
        ti_course_log($courseId, 'zoom', 'Usunięto stały link Zoom grupy.', $uid, $byName);
        return ['ok' => true, 'msg' => 'Link Zoom usunięty.'];
    }
}
