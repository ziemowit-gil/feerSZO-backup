<?php
/**
 * modules/account_security/lib/accountSecurityReport.php — raport bezpieczeństwa kont (Administracja Systemu Tożsamości).
 *
 * Dla każdego konta: hasło lokalne, zweryfikowany telefon, drugi składnik (2FA / klucz), powiązanie z Microsoft 365,
 * ostatnie udane logowanie, nieudane logowania z 30 dni, aktywne sesje. Problemy (ISSUES) służą do filtrów i podsumowania.
 * Jedno zapytanie z podzapytaniami; kolumny/tabele, których w danej instalacji brak (np. webauthn_credentials,
 * phone_verified_at, merged_into_id), są pomijane — raport działa także na starszym schemacie.
 * Czasy z login_log (SQLite: UTC) — do wyświetlenia przez AccountSecurityDevices::localTime().
 */
require_once dirname(__DIR__, 3) . '/includes/auth_security.php';
require_once __DIR__ . '/accountSecurityDevices.php';

final class AccountSecurityReport
{
    /** Role uprzywilejowane — brak MFA na takim koncie to problem krytyczny. */
    public const PRIVILEGED = ['admin', 'editor'];
    /** Konto „uśpione”: aktywne, ale bez udanego logowania od tylu dni (albo nigdy). */
    public const DORMANT_DAYS = 90;
    /** Ostrzeżenie o próbach włamania: tyle nieudanych logowań w 30 dni. */
    public const FAILS_ALERT = 5;

    /** Filtry problemów: klucz => [etykieta, opis]. */
    public const ISSUES = [
        'priv_no_mfa' => ['Uprzywilejowane bez MFA', 'Administrator lub operator bez drugiego składnika logowania'],
        'no_mfa'      => ['Bez MFA', 'Brak weryfikacji dwuskładnikowej i klucza bezpieczeństwa'],
        'no_phone'    => ['Bez telefonu', 'Brak zweryfikowanego numeru do kodów SMS i odzyskiwania dostępu'],
        'no_password' => ['Bez hasła lokalnego', 'Logowanie tylko przez Microsoft 365, kod SMS lub link'],
        'dormant'     => ['Uśpione', 'Aktywne konto bez logowania od ' . self::DORMANT_DAYS . ' dni (albo nigdy)'],
        'fails'       => ['Nieudane logowania', 'Co najmniej ' . self::FAILS_ALERT . ' nieudanych lub odrzuconych logowań w 30 dni'],
    ];

    public static function migrate(): void
    {
        if (function_exists('szo_schema_current') && szo_schema_current('account_security_report_migrate', __FILE__)) return;
        _auth_security_init();
        try { db()->exec("CREATE INDEX IF NOT EXISTS idx_login_log_user_time ON login_log(user_id, created_at)"); }
        catch (\Throwable $e) { error_log('[account_security] indeks login_log: ' . $e->getMessage()); }
    }

    /** Kolumny tabeli users w tej instalacji. */
    private static function userColumns(): array
    {
        static $cols = null;
        if ($cols !== null) return $cols;
        $cols = [];
        $st = db()->query("SELECT * FROM users LIMIT 0");
        for ($i = 0; $i < $st->columnCount(); $i++) $cols[] = (string)($st->getColumnMeta($i)['name'] ?? '');
        return $cols;
    }

    /** Znacznik czasu w zapisie bazy: SQLite (CURRENT_TIMESTAMP) = UTC, MySQL = czas serwera. */
    private static function dbTime(int $ts): string
    {
        return (defined('DB_TYPE') ? DB_TYPE : 'sqlite') === 'sqlite' ? gmdate('Y-m-d H:i:s', $ts) : date('Y-m-d H:i:s', $ts);
    }

    private static function tableExists(string $t): bool
    {
        try { db()->query("SELECT 1 FROM {$t} LIMIT 0"); return true; } catch (\Throwable $e) { return false; }
    }

    /**
     * Wiersze raportu po filtrach.
     * @param array{q?:string, role?:string, issue?:string, inactive?:bool} $f
     * @return list<array<string,mixed>>
     */
    public static function rows(array $f = []): array
    {
        self::migrate();
        $c = self::userColumns();
        $has = fn(string $col): bool => in_array($col, $c, true);
        $wk  = self::tableExists('webauthn_credentials');

        $sel = ['u.id', 'u.name', 'u.email', 'u.role',
                $has('is_active') ? 'u.is_active' : '1 AS is_active',
                $has('password') ? "CASE WHEN COALESCE(u.password,'') <> '' THEN 1 ELSE 0 END AS has_pwd" : '0 AS has_pwd',
                $has('phone_verified_at') ? "CASE WHEN u.phone_verified_at IS NOT NULL AND u.phone_verified_at <> '' THEN 1 ELSE 0 END AS phone_ok" : '0 AS phone_ok',
                $has('twofa_method') ? "COALESCE(u.twofa_method,'') AS twofa" : "'' AS twofa",
                $has('microsoft_id') ? "CASE WHEN COALESCE(u.microsoft_id,'') <> '' THEN 1 ELSE 0 END AS ms_linked" : '0 AS ms_linked',
                $wk ? '(SELECT COUNT(*) FROM webauthn_credentials w WHERE w.user_id = u.id) AS keys_n' : '0 AS keys_n',
                "(SELECT MAX(l.created_at) FROM login_log l WHERE l.user_id = u.id AND l.action LIKE 'login%'
                   AND l.action NOT LIKE 'login_fail%' AND l.action NOT LIKE 'login_blocked%' AND l.action NOT LIKE 'login_new_denied%') AS last_login",
                "(SELECT COUNT(*) FROM login_log l WHERE l.user_id = u.id AND l.action IN ('login_fail','login_blocked','login_new_denied')
                   AND l.created_at >= ?) AS fails_30",
                '(SELECT COUNT(*) FROM user_sessions s WHERE s.user_id = u.id) AS sessions_n'];
        $where = ['1=1']; $params = [self::dbTime(time() - 30 * 86400)];
        if ($has('merged_into_id')) $where[] = 'u.merged_into_id IS NULL';
        if (empty($f['inactive']) && $has('is_active')) $where[] = 'u.is_active = 1';
        if (($f['role'] ?? '') !== '') { $where[] = 'u.role = ?'; $params[] = (string)$f['role']; }
        $q = trim((string)($f['q'] ?? ''));   // szukanie w PHP: LOWER() w SQLite nie zmienia wielkości polskich liter

        $rows = db_all('SELECT ' . implode(', ', $sel) . ' FROM users u WHERE ' . implode(' AND ', $where) . ' ORDER BY u.name, u.id', $params);

        $dormantBefore = self::dbTime(time() - self::DORMANT_DAYS * 86400);
        $out = [];
        foreach ($rows as $r) {
            if ($q !== '' && mb_stripos((string)$r['name'], $q) === false && mb_stripos((string)$r['email'], $q) === false) continue;
            $mfa  = (string)$r['twofa'] !== '' || (int)$r['keys_n'] > 0;
            $priv = in_array((string)$r['role'], self::PRIVILEGED, true);
            $issues = [];
            if (!$mfa && $priv)                   $issues[] = 'priv_no_mfa';
            if (!$mfa)                            $issues[] = 'no_mfa';
            if (!(int)$r['phone_ok'])             $issues[] = 'no_phone';
            if (!(int)$r['has_pwd'])              $issues[] = 'no_password';
            if ((int)$r['is_active'] === 1 && ((string)$r['last_login'] === '' || (string)$r['last_login'] < $dormantBefore)) $issues[] = 'dormant';
            if ((int)$r['fails_30'] >= self::FAILS_ALERT) $issues[] = 'fails';
            $row = [
                'id' => (int)$r['id'], 'name' => (string)$r['name'], 'email' => (string)$r['email'], 'role' => (string)$r['role'],
                'active' => (int)$r['is_active'] === 1, 'has_pwd' => (bool)(int)$r['has_pwd'], 'phone_ok' => (bool)(int)$r['phone_ok'],
                'mfa' => $mfa, 'mfa_label' => self::mfaLabel((string)$r['twofa'], (int)$r['keys_n']), 'keys_n' => (int)$r['keys_n'],
                'ms_linked' => (bool)(int)$r['ms_linked'], 'privileged' => $priv,
                'last_login' => (string)$r['last_login'], 'fails_30' => (int)$r['fails_30'], 'sessions_n' => (int)$r['sessions_n'],
                'issues' => $issues,
            ];
            $issue = (string)($f['issue'] ?? '');
            if ($issue !== '' && isset(self::ISSUES[$issue]) && !in_array($issue, $issues, true)) continue;
            $out[] = $row;
        }
        // Najpierw konta z problemem krytycznym, potem wg liczby problemów
        usort($out, fn($a, $b) => [in_array('priv_no_mfa', $b['issues'], true), count($b['issues'])] <=> [in_array('priv_no_mfa', $a['issues'], true), count($a['issues'])]
                                  ?: strcasecmp($a['name'], $b['name']));
        return $out;
    }

    private static function mfaLabel(string $twofa, int $keys): string
    {
        $m = match ($twofa) { 'totp' => 'Authenticator', 'sms' => 'SMS', '' => '', default => $twofa };
        $k = $keys > 0 ? ($keys === 1 ? 'klucz' : $keys . ' klucze') : '';
        return implode(' + ', array_filter([$m, $k])) ?: 'brak';
    }

    /** Liczniki do kafli podsumowania (z wierszy BEZ filtra problemu). */
    public static function summary(array $rows): array
    {
        $s = ['total' => count($rows)];
        foreach (self::ISSUES as $k => $_) $s[$k] = count(array_filter($rows, fn($r) => in_array($k, $r['issues'], true)));
        return $s;
    }

    /** CSV (średnik, UTF-8 z BOM — otwiera się poprawnie w polskim Excelu). */
    public static function csv(array $rows): string
    {
        $h = fopen('php://temp', 'w+');
        fwrite($h, "\xEF\xBB\xBF");
        fputcsv($h, ['UID', 'Imię i nazwisko', 'Login', 'Rola', 'Aktywne', 'Hasło lokalne', 'Telefon zweryfikowany', 'MFA', 'Microsoft 365',
                     'Ostatnie logowanie', 'Nieudane logowania (30 dni)', 'Aktywne sesje', 'Problemy'], ';', '"', '');
        foreach ($rows as $r) {
            $last = $r['last_login'] !== '' ? AccountSecurityDevices::localTime($r['last_login'], 'Y-m-d H:i') : 'nigdy';
            fputcsv($h, [$r['id'], self::csvSafe($r['name']), self::csvSafe($r['email']), $r['role'], $r['active'] ? 'tak' : 'nie',
                         $r['has_pwd'] ? 'tak' : 'nie', $r['phone_ok'] ? 'tak' : 'nie', $r['mfa_label'], $r['ms_linked'] ? 'tak' : 'nie',
                         $last, $r['fails_30'], $r['sessions_n'],
                         implode(', ', array_map(fn($i) => self::ISSUES[$i][0], $r['issues']))], ';', '"', '');
        }
        rewind($h);
        $csv = (string)stream_get_contents($h);
        fclose($h);
        return $csv;
    }

    /** Ochrona przed wstrzyknięciem formuł w arkuszu (=, +, -, @ na początku komórki). */
    private static function csvSafe(string $v): string
    {
        return preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v;
    }
}
