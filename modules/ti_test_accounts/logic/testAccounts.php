<?php
/**
 * modules/ti_test_accounts/logic/testAccounts.php — przykładowe konta testowe do konsultacji i szkoleń krótkich (SzK).
 *
 * Administrator zakłada / odświeża je w panelu (dydaktyk/kontaTestowe.php). Wszystkie osoby testowe mają ten sam
 * telefon — org_setting ti_test_phone (edytowalny, domyślnie 535 231 923) — więc SMS-y trafiają do testującego.
 *   • Konsultacje: 3 beneficjentów (konta „k1234”, tryb panelu 4) z rezerwacjami i kartami na różnych etapach
 *     (robocza, podpisana, zatwierdzona przez kierownika); jedna osoba z wymogiem dokumentu potwierdzającego;
 *   • Szkolenia krótkie: SzK „tylko terminy” (stacjonarnie, 2 terminy) i „Hybryda krótka” z płatnościami uproszczonymi (3 terminy).
 * Osoby testowe: k30_clients.notes = MARKER. Odświeżenie nadaje nowe hasła (bez wymuszonej zmiany — do wielokrotnych testów).
 */
require_once dirname(__DIR__, 3) . '/includes/karty30.php';
require_once __DIR__ . '/testFilter.php';
require_once dirname(__DIR__, 3) . '/modules/ti_simple_groups/logic/simpleGroups.php';
require_once dirname(__DIR__, 3) . '/modules/ti_consultations/logic/consultations.php';
require_once dirname(__DIR__, 3) . '/modules/ti_bookings/logic/bookings.php';
require_once dirname(__DIR__, 3) . '/modules/ti_student_docs/logic/studentDocs.php';

final class TiTestAccounts
{
    public const MARKER = TI_TEST_MARKER;   // modules/ti_test_accounts/logic/testFilter.php — wspólny z filtrem raportów
    public const DEFAULT_PHONE = '535231923';
    private const KONS = [['Anna Testowa', 'draft'], ['Jan Testowy', 'signed'], ['Ewa Próbna', 'approved']];
    private const SZK = [
        ['desc' => 'TEST — szkolenie krótkie stacjonarne', 'mode' => 1, 'type' => 'individual', 'letters' => 'TST', 'people' => ['Piotr Testowy', 'Maria Testowa'],
         'lessons' => [[+2, 'stacjonarna'], [+9, 'stacjonarna']]],
        ['desc' => 'TEST — hybryda krótka, płatności uproszczone', 'mode' => 3, 'type' => 'hybrid', 'letters' => 'TSH', 'people' => ['Tomasz Próbny', 'Zofia Testowa'],
         'lessons' => [[-5, 'stacjonarna'], [+3, 'zdalna_zoom'], [+10, 'stacjonarna']]],
    ];

    public static function phone(): string
    {
        $p = preg_replace('/\D/', '', (string)org_setting('ti_test_phone'));
        return strlen($p) >= 9 ? $p : self::DEFAULT_PHONE;
    }

    public static function setPhone(string $p): bool
    {
        $p = preg_replace('/\D/', '', $p);
        if (strlen($p) < 9 || strlen($p) > 15) return false;
        org_setting_set('ti_test_phone', $p);
        db()->prepare("UPDATE k30_clients SET phone=? WHERE notes=?")->execute([$p, self::MARKER]);   // istniejące osoby testowe
        return true;
    }

    private static function client(string $name): int
    {
        $r = db_one("SELECT id FROM k30_clients WHERE name=? AND notes=?", [$name, self::MARKER]);
        if ($r) { db()->prepare("UPDATE k30_clients SET phone=? WHERE id=?")->execute([self::phone(), (int)$r['id']]); return (int)$r['id']; }
        $slug = strtolower(strtr(explode(' ', $name)[0], ['ó' => 'o', 'ł' => 'l']));
        return (int)db_insert('k30_clients', ['name' => $name, 'phone' => self::phone(), 'email' => 'test.' . $slug . '@example.org', 'status' => 'enrolled', 'notes' => self::MARKER]);
    }

    /** Nowe hasło dla konta testowego (bez wymuszonej zmiany) — zwraca hasło. */
    private static function resetPassword(int $accountId): string
    {
        $chars = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789'; $p = '';
        for ($i = 0; $i < 8; $i++) $p .= $chars[random_int(0, strlen($chars) - 1)];
        db()->prepare("UPDATE k30_ti_student_accounts SET password_hash=?, must_change_password=0, is_active=1, updated_at=datetime('now') WHERE id=?")
           ->execute([password_hash($p, PASSWORD_BCRYPT), $accountId]);
        return $p;
    }

    /**
     * Zakłada brakujące dane testowe i nadaje nowe hasła wszystkim kontom testowym.
     * @return list<array{name:string,login:string,password:string,kind:string,hint:string,sms:string}>
     */
    public static function seed(int $uid, bool $sendSms): array
    {
        karty30_migrate();
        TiStudentDocs::migrate();
        $rows = [];
        // ── Konsultacje ──
        foreach (self::KONS as $i => [$name, $stage]) {
            $cid = self::client($name);
            if (!db_one("SELECT 1 FROM k30_schedules WHERE client_id=?", [$cid])) {
                TiBookings::create(['client_id' => $cid, 'assigned_to' => $uid, 'date' => date('Y-m-d', strtotime('+' . (3 + $i) . ' days')), 'time' => '10:00', 'duration' => 60,
                                    'status' => 'confirmed', 'location_type' => 'remote', 'billing_type' => 'free', 'description' => 'Rezerwacja testowa'], $uid, 'konta testowe');
                TiBookings::create(['client_id' => $cid, 'assigned_to' => $uid, 'date' => date('Y-m-d', strtotime('-' . (7 + $i) . ' days')), 'time' => '12:00', 'duration' => 60,
                                    'status' => 'attended', 'location_type' => 'remote', 'billing_type' => $i === 1 ? 'paid' : 'free', 'description' => 'Rezerwacja testowa (odbyta)'], $uid, 'konta testowe');
            }
            if (!db_one("SELECT 1 FROM k30_consultations WHERE client_id=?", [$cid])) {
                $res = TiConsultations::saveStandalone(['client_id' => $cid, 'consultant_id' => $uid, 'datetime' => date('Y-m-d', strtotime('-' . (7 + $i) . ' days')) . 'T12:00', 'duration' => 60,
                    'description' => 'Konsultacja testowa — konfiguracja czytnika ekranu NVDA.', 'next_action' => 'Ćwiczenia z nawigacji po stronach WWW.',
                    'intake' => TiConsultations::intakeFromPost(['intake_email' => 'test@example.org', 'intake_phone' => self::phone(), 'intake_contact_pref' => 'phone', 'intake_who' => 'niewidoma',
                        'intake_vision' => 'niewidoma', 'intake_topics' => ['screenreader', 'konfiguracja'], 'intake_systems' => ['windows', 'android'], 'intake_at_tools' => 'NVDA',
                        'intake_problem' => 'Dane testowe.', 'intake_form' => 'online', 'intake_rodo' => 1])], null, $uid);
                if ($res['ok'] && $stage !== 'draft') {   // etapy do podglądu (bez prawdziwego podpisu IKA)
                    db()->prepare("UPDATE k30_consultations SET status='completed', confirmed=1, approved_by_name='Konsultant testowy', ika_verified_at=datetime('now'), sha1sum='test' WHERE id=?")->execute([$res['id']]);
                    if ($stage === 'approved') db()->prepare("UPDATE k30_consultations SET mgr_approved_by=?, mgr_approved_name='Kierownik testowy', mgr_approved_at=datetime('now') WHERE id=?")->execute([$uid ?: null, $res['id']]);
                }
            }
            $acc = db_one("SELECT id, login FROM k30_ti_student_accounts WHERE client_id=?", [$cid]);
            if (!$acc) { TiConsultations::ensureBeneficiaryAccount($cid, $uid, false); $acc = db_one("SELECT id, login FROM k30_ti_student_accounts WHERE client_id=?", [$cid]); }
            if (!$acc) continue;
            db()->prepare("UPDATE k30_ti_student_accounts SET simple_mode=4 WHERE id=?")->execute([(int)$acc['id']]);
            $hint = 'Moje konsultacje: terminy, karta (etap: ' . (TiConsultations::STAGES[$stage][0] ?? $stage) . '), PDF, Rozliczenia';
            if ($name === 'Ewa Próbna') {   // bramka dokumentu potwierdzającego — wraca przy każdym odświeżeniu
                TiStudentDocs::setRequired((int)$acc['id'], true, 'orzeczenie o niepełnosprawności', true, $uid, 'konta testowe');
                $hint .= '; po zalogowaniu: skan albo oświadczenie (dokument potwierdzający)';
            }
            $rows[] = ['name' => $name, 'login' => (string)$acc['login'], 'password' => self::resetPassword((int)$acc['id']), 'kind' => 'Beneficjent konsultacji', 'hint' => $hint, 'sms' => ''];
        }
        // ── Szkolenia krótkie ──
        foreach (self::SZK as $g) {
            $course = db_one("SELECT id, name FROM k30_ti_courses WHERE internal_desc=? AND simple_mode>0 AND closed_at IS NULL", [$g['desc']]);
            $cids = array_map(fn($n) => self::client($n), $g['people']);
            if (!$course) {
                $lessons = array_map(fn($l) => ['date' => date('Y-m-d', strtotime(($l[0] >= 0 ? '+' : '') . $l[0] . ' days')), 'from' => '10:00', 'to' => '11:30', 'method' => $l[1]], $g['lessons']);
                $r = TiSimpleGroups::create(['letters' => $g['letters'], 'mode' => $g['mode'], 'class_type' => $g['type'], 'instructor_id' => $uid, 'desc' => $g['desc'],
                                             'location' => 'Sala testowa', 'meeting_url' => 'https://zoom.us/j/0000000000', 'clients' => $cids, 'lessons' => $lessons,
                                             'accounts' => true, 'send_logins' => false], $uid, 'konta testowe');
                $course = $r['ok'] ? ['id' => $r['course_id'], 'name' => $r['name']] : null;
            }
            foreach ($cids as $k => $cid) {
                $acc = db_one("SELECT id, login FROM k30_ti_student_accounts WHERE client_id=?", [$cid]);
                if (!$acc) { TiSimpleGroups::createAccount($cid, $uid, false); $acc = db_one("SELECT id, login FROM k30_ti_student_accounts WHERE client_id=?", [$cid]); }
                if (!$acc) continue;
                db()->prepare("UPDATE k30_ti_student_accounts SET simple_mode=0 WHERE id=?")->execute([(int)$acc['id']]);   // tryb z grupy SzK
                $rows[] = ['name' => $g['people'][$k], 'login' => (string)$acc['login'], 'password' => self::resetPassword((int)$acc['id']),
                           'kind' => 'Szkolenie krótkie ' . ($course['name'] ?? ''), 'hint' => $g['mode'] === 1 ? 'Moje lekcje (tylko terminy): odwołanie, przesunięcie, usprawiedliwienie' : 'Moje lekcje + Opłaty (płatności uproszczone); zajęcia mieszane', 'sms' => ''];
            }
        }
        if ($sendSms) {
            require_once dirname(__DIR__, 3) . '/includes/sms.php';
            $org = defined('ORG_NAME') ? ORG_NAME : 'Panel';
            foreach ($rows as &$r) {
                if (!sms_channel_ready()) { $r['sms'] = 'SMS wyłączony'; continue; }
                try { sms_send(self::phone(), "{$org} - konto testowe ({$r['name']}). Login: {$r['login']}, haslo: {$r['password']}", 'access_data'); $r['sms'] = 'wysłano'; }
                catch (\Throwable $e) { $r['sms'] = 'błąd SMS'; }
            }
            unset($r);
        }
        return $rows;
    }

    /** Dezaktywuje konta testowe (dane zostają). */
    public static function deactivate(): int
    {
        $st = db()->prepare("UPDATE k30_ti_student_accounts SET is_active=0 WHERE client_id IN (SELECT id FROM k30_clients WHERE notes=?)");
        $st->execute([self::MARKER]);
        return $st->rowCount();
    }

    /** Osoby i konta testowe (bez haseł). */
    public static function list(): array
    {
        return db_all("SELECT c.id, c.name, c.phone, a.login, a.is_active, a.simple_mode FROM k30_clients c LEFT JOIN k30_ti_student_accounts a ON a.client_id = c.id
                        WHERE c.notes=? ORDER BY c.name", [self::MARKER]);
    }

    /** PDF z danymi logowania kont testowych. */
    public static function pdf(array $rows): string
    {
        $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $url = rtrim(defined('APP_URL') ? APP_URL : '', '/') . '/karty30/ti/kursant/login.php';
        $h = '<style>body{font-family:dejavusans;font-size:9.5pt}h1{font-size:14pt;margin:0 0 2mm}table{border-collapse:collapse;width:100%}
              th,td{border:0.3mm solid #555;padding:1.8mm;vertical-align:top}th{background:#eef2f7}.m{font-family:dejavusansmono;font-size:10.5pt}.s{color:#555;font-size:8.5pt}</style>'
           . '<h1>Konta testowe — konsultacje i szkolenia krótkie</h1>'
           . '<p class="s">Wydruk ' . date('d.m.Y H:i') . ' · logowanie: ' . $e($url) . ' · telefon wszystkich osób testowych: ' . $e(self::phone())
           . ' · hasła bez wymuszonej zmiany; ponowne „Odśwież” w panelu nadaje nowe.</p>'
           . '<table><thead><tr><th>Osoba</th><th>Rodzaj konta</th><th>Login</th><th>Hasło</th><th>Co sprawdzić</th></tr></thead><tbody>';
        foreach ($rows as $r) $h .= '<tr><td>' . $e($r['name']) . '</td><td>' . $e($r['kind']) . '</td><td class="m">' . $e($r['login']) . '</td><td class="m">' . $e($r['password']) . '</td><td class="s">' . $e($r['hint']) . '</td></tr>';
        $h .= '</tbody></table><p class="s">Kadra: rezerwacje i karty tych osób są w panelu dydaktyka → Konsultacje; szkolenia SzK w „Szkolenia krótkie (SzK)” (opis zaczyna się od „TEST —”).</p>';
        require_once dirname(__DIR__, 3) . '/vendor/autoload.php';
        $tmp = (defined('UPLOAD_DIR') ? UPLOAD_DIR : sys_get_temp_dir() . '/') . 'mpdf_tmp';
        if (!is_dir($tmp)) @mkdir($tmp, 0755, true);
        $pdf = new \Mpdf\Mpdf(['mode' => 'utf-8', 'format' => 'A4-L', 'margin_left' => 12, 'margin_right' => 12, 'margin_top' => 12, 'margin_bottom' => 12, 'default_font' => 'dejavusans', 'tempDir' => $tmp]);
        $pdf->SetTitle('Konta testowe');
        $pdf->WriteHTML($h);
        return $pdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
    }
}
