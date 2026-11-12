<?php
/**
 * modules/account_security/lib/accountSecurityDevices.php — dane i akcje sekcji „Urządzenia i logowania” (System Tożsamości).
 *
 * Źródła (schematy w includes/auth_security.php):
 *   • user_sessions   — aktywne sesje; usunięcie wiersza wylogowuje tę sesję przy następnym żądaniu (session_token_alive),
 *   • trusted_logins  — znane urządzenia (ciasteczko szo_dev, kind=device) i adresy IP (kind=ip); zapomniane urządzenie
 *                       przy następnym logowaniu musi zostać potwierdzone („Nowe logowanie”),
 *   • login_log       — historia logowań i zmian zabezpieczeń,
 *   • ip_geo_cache    — lokalizacja adresu IP: TYLKO z pamięci podręcznej (strona nie odpytuje zewnętrznego serwisu).
 * Token sesji nigdy nie trafia do HTML — formularze posługują się id wiersza, a akcje sprawdzają user_id.
 */
require_once dirname(__DIR__, 3) . '/includes/auth_security.php';

final class AccountSecurityDevices
{
    /** Zdarzenia login_log pokazywane w historii: akcja → [etykieta, ton plakietki ok|warn|err|info|off, ikona]. */
    private const LABELS = [
        'login'               => ['Logowanie hasłem', 'ok', 'bi-box-arrow-in-right'],
        'login_2fa'           => ['Logowanie z drugim składnikiem', 'ok', 'bi-shield-check'],
        'login_sms'           => ['Logowanie kodem SMS', 'ok', 'bi-phone'],
        'login_code'          => ['Logowanie kodem jednorazowym', 'ok', 'bi-key'],
        'login_ms'            => ['Logowanie przez Microsoft 365', 'ok', 'bi-microsoft'],
        'login_webauthn'      => ['Logowanie kluczem bezpieczeństwa', 'ok', 'bi-fingerprint'],
        'login_x509'          => ['Logowanie certyfikatem', 'ok', 'bi-patch-check'],
        'login_x509_app'      => ['Logowanie certyfikatem (aplikacja)', 'ok', 'bi-patch-check'],
        'mobile_pin_login'    => ['Logowanie PIN-em do dialera', 'ok', 'bi-123'],
        'login_new_confirmed' => ['Potwierdzono logowanie z nowego miejsca', 'info', 'bi-check2-circle'],
        'login_new_denied'    => ['Odrzucono logowanie z nowego miejsca', 'err', 'bi-x-octagon'],
        'login_fail'          => ['Nieudane logowanie — błędne hasło', 'err', 'bi-x-circle'],
        'login_blocked'       => ['Logowanie zablokowane', 'err', 'bi-shield-x'],
        'logout'              => ['Wylogowanie', 'off', 'bi-box-arrow-right'],
        'force_logout'        => ['Wylogowanie przez administratora', 'warn', 'bi-box-arrow-right'],
        'pwd_changed'         => ['Zmiana hasła', 'warn', 'bi-key-fill'],
        'pwd_set_via_link'    => ['Ustawienie hasła z linku', 'warn', 'bi-key-fill'],
        'phone_verified'      => ['Zweryfikowano numer telefonu', 'info', 'bi-phone'],
        'phone_changed'       => ['Zmiana numeru telefonu', 'warn', 'bi-phone'],
        'phone_removed'       => ['Usunięto numer telefonu', 'warn', 'bi-phone'],
        'sessions_ended'      => ['Wylogowano pozostałe urządzenia', 'info', 'bi-laptop'],
        'device_forgotten'    => ['Zapomniano urządzenie', 'info', 'bi-laptop'],
        'security_lockdown'   => ['Zgłoszono „To nie ja” — konto zabezpieczone', 'err', 'bi-shield-exclamation'],
    ];

    /**
     * Czas z tabel bezpieczeństwa do wyświetlenia: w SQLite CURRENT_TIMESTAMP / datetime('now') to UTC — przeliczamy
     * na strefę aplikacji (Europe/Warsaw). W MySQL znacznik jest już w czasie serwera. '' → '—'.
     */
    public static function localTime(string $stored, string $format = 'd.m.Y H:i'): string
    {
        if ($stored === '') return '—';
        try {
            $utc = (defined('DB_TYPE') ? DB_TYPE : 'sqlite') === 'sqlite';
            $dt = new \DateTimeImmutable($stored, new \DateTimeZone($utc ? 'UTC' : date_default_timezone_get()));
            return $dt->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format($format);
        } catch (\Throwable $e) {
            return $stored;
        }
    }

    /**
     * Odwrotność localTime(): czas lokalny (np. granica filtra „od dnia”) → zapis porównywalny z kolumną tabeli
     * bezpieczeństwa (SQLite: UTC, MySQL: bez zmian). Nieprawidłowa data → wejście bez zmian.
     */
    public static function dbTimeFromLocal(string $local): string
    {
        if ((defined('DB_TYPE') ? DB_TYPE : 'sqlite') !== 'sqlite') return $local;
        try {
            return (new \DateTimeImmutable($local, new \DateTimeZone(date_default_timezone_get())))
                ->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return $local;
        }
    }

    /** Ten sam czas w formacie ISO 8601 (atrybut datetime elementu <time>). */
    public static function isoTime(string $stored): string
    {
        return $stored === '' ? '' : self::localTime($stored, DATE_ATOM);
    }

    /** Token bieżącej sesji (pusty = sesja sprzed rejestru sesji). */
    public static function currentToken(): string
    {
        return (string)($_SESSION['_session_token'] ?? '');
    }

    /** Skrót ciasteczka urządzenia (tak jak zapisuje auth_new_login_check); '' gdy brak ciasteczka. */
    public static function currentDeviceHash(): string
    {
        $tok = (string)($_COOKIE['szo_dev'] ?? '');
        return preg_match('/^[a-f0-9]{32}$/', $tok) ? hash('sha256', $tok) : '';
    }

    /** Lokalizacja adresu IP z pamięci podręcznej (bez zapytania sieciowego); '' = nieznana. */
    public static function geoCached(string $ip): string
    {
        if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return $ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) ? 'sieć lokalna' : '';
        }
        _nl_init();
        try {
            $c = db_one("SELECT country, region, city FROM ip_geo_cache WHERE ip=?", [$ip]);
        } catch (\Throwable $e) { return ''; }
        if (!$c || (string)$c['country'] === '') return '';
        return implode(', ', array_filter([(string)$c['city'], (string)$c['country']], fn($x) => $x !== ''));
    }

    /**
     * Aktywne sesje konta — bieżąca pierwsza.
     * @return list<array{id:int, device:string, ip:string, geo:string, last_active:string, created_at:string, current:bool}>
     */
    public static function sessions(int $uid): array
    {
        $cur = self::currentToken();
        $out = [];
        foreach (sessions_for_user($uid) as $s) {
            $ip = (string)($s['ip'] ?? '');
            $out[] = [
                'id'          => (int)$s['id'],
                'device'      => auth_device_label((string)($s['user_agent'] ?? '')),
                'ip'          => $ip,
                'geo'         => self::geoCached($ip),
                'last_active' => (string)($s['last_active'] ?? ''),
                'created_at'  => (string)($s['created_at'] ?? ''),
                'current'     => $cur !== '' && hash_equals((string)$s['token'], $cur),
            ];
        }
        usort($out, fn($a, $b) => [$b['current'], $b['last_active']] <=> [$a['current'], $a['last_active']]);
        return $out;
    }

    /**
     * Znane (zaufane) urządzenia — logowanie z nich nie wymaga potwierdzenia.
     * @return list<array{id:int, label:string, geo:string, first_seen:string, last_seen:string, current:bool}>
     */
    public static function devices(int $uid): array
    {
        _nl_init();
        $curDev = self::currentDeviceHash();
        $rows = db_all("SELECT id, value, label, geo, first_seen, last_seen FROM trusted_logins
                        WHERE user_id=? AND kind='device' ORDER BY last_seen DESC", [$uid]);
        return array_map(fn($r) => [
            'id'         => (int)$r['id'],
            'label'      => (string)$r['label'] !== '' ? (string)$r['label'] : 'Urządzenie',
            'geo'        => (string)$r['geo'],
            'first_seen' => (string)$r['first_seen'],
            'last_seen'  => (string)$r['last_seen'],
            'current'    => $curDev !== '' && hash_equals((string)$r['value'], $curDev),
        ], $rows);
    }

    /**
     * Historia logowań i zmian zabezpieczeń (najnowsze pierwsze).
     * @return list<array{at:string, label:string, tone:string, icon:string, device:string, ip:string, geo:string, detail:string}>
     */
    public static function history(int $uid, int $limit = 30): array
    {
        $out = [];
        foreach (authlog_user($uid, max(1, min(200, $limit))) as $r) {
            $action = (string)$r['action'];
            [$label, $tone, $icon] = self::LABELS[$action] ?? self::fallbackLabel($action);
            $ip = (string)($r['ip'] ?? '');
            $out[] = [
                'at'     => (string)$r['created_at'],
                'label'  => $label,
                'tone'   => $tone,
                'icon'   => $icon,
                'device' => (string)($r['user_agent'] ?? '') !== '' ? auth_device_label((string)$r['user_agent']) : '',
                'ip'     => $ip,
                'geo'    => self::geoCached($ip),
                'detail' => (string)($r['detail'] ?? ''),
            ];
        }
        return $out;
    }

    /** Liczba nieudanych logowań w ostatnich $days dniach (do ostrzeżenia nad historią). */
    public static function recentFailures(int $uid, int $days = 14): int
    {
        _auth_security_init();
        $r = db_one("SELECT COUNT(*) AS c FROM login_log WHERE user_id=? AND action IN ('login_fail','login_blocked','login_new_denied')
                     AND created_at >= datetime('now', ?)", [$uid, '-' . max(1, $days) . ' days']);
        return (int)($r['c'] ?? 0);
    }

    private static function fallbackLabel(string $action): array
    {
        if (str_starts_with($action, 'login_blocked')) return ['Logowanie zablokowane', 'err', 'bi-shield-x'];
        if (str_starts_with($action, 'login'))         return ['Logowanie', 'ok', 'bi-box-arrow-in-right'];
        [$l, , $i] = authlog_action_label($action);
        return [$l, 'off', $i];
    }

    // ── Akcje ────────────────────────────────────────────────────────────────

    /** Kończy jedną sesję konta (nie bieżącą). Zwraca true, gdy coś usunięto. */
    public static function endSession(int $uid, int $sessionId): bool
    {
        _auth_security_init();
        $st = db()->prepare("DELETE FROM user_sessions WHERE id=? AND user_id=? AND token != ?");
        $st->execute([$sessionId, $uid, self::currentToken()]);
        return $st->rowCount() > 0;
    }

    /** Kończy wszystkie sesje konta poza bieżącą. Zwraca liczbę zakończonych. */
    public static function endOtherSessions(int $uid): int
    {
        _auth_security_init();
        $st = db()->prepare("DELETE FROM user_sessions WHERE user_id=? AND token != ?");
        $st->execute([$uid, self::currentToken()]);
        return $st->rowCount();
    }

    /** Zapomina jedno znane urządzenie konta. Zwraca true, gdy coś usunięto. */
    public static function forgetDevice(int $uid, int $deviceId): bool
    {
        _nl_init();
        $st = db()->prepare("DELETE FROM trusted_logins WHERE id=? AND user_id=? AND kind='device'");
        $st->execute([$deviceId, $uid]);
        return $st->rowCount() > 0;
    }

    /**
     * Zapomina wszystkie znane urządzenia i adresy IP konta poza bieżącym urządzeniem i adresem —
     * każde logowanie z innego miejsca będzie wymagało potwierdzenia. Zwraca liczbę usuniętych wpisów.
     */
    public static function forgetOtherDevices(int $uid): int
    {
        _nl_init();
        $st = db()->prepare("DELETE FROM trusted_logins WHERE user_id=?
                             AND NOT (kind='device' AND value=?) AND NOT (kind='ip' AND value=?)");
        $st->execute([$uid, self::currentDeviceHash(), _auth_ip()]);
        return $st->rowCount();
    }
}
