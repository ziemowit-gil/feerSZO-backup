<?php
/**
 * modules/ezd_sign_profile/logic/ezdSignProfile.php — profil podpisu użytkownika w EZD (kafel „Profil i podpis” na pulpicie).
 *
 * System nie przechowuje kluczy ani nie łączy się z chmurą CenCert: podpis kwalifikowany składa aplikacja rSign Desktop na
 * komputerze użytkownika (includes/ezd_rsign.php + ezd/sign.php), a serwer tylko sprawdza i zapisuje podpisany PDF. Żeby pulpit
 * mógł pokazać „jakim certyfikatem podpisujesz i do kiedy jest ważny”, przy każdym udanym podpisie zapamiętujemy DANE
 * PUBLICZNE certyfikatu odczytane z podpisu (CN, organizacja, wystawca, numer seryjny, okres ważności) — tabela
 * ezd_sign_certs, jeden wiersz na (użytkownik, numer seryjny). Nic więcej: bez klucza, bez PIN-u, bez pliku.
 *
 * Statystyki podpisów liczymy z ezd_log (akcje sign_rsign — podpis z aplikacji, sign_uploaded — wgrany podpisany plik).
 */
final class EzdSignProfile
{
    /** Ile dni przed końcem ważności certyfikatu pokazujemy ostrzeżenie. */
    public const WARN_DAYS = 30;

    public static function migrate(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            db()->exec("CREATE TABLE IF NOT EXISTS ezd_sign_certs (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id      INTEGER NOT NULL,
                cn           TEXT NOT NULL DEFAULT '',
                o            TEXT NOT NULL DEFAULT '',
                issuer       TEXT NOT NULL DEFAULT '',
                serial       TEXT NOT NULL DEFAULT '',
                valid_from   TEXT NOT NULL DEFAULT '',
                valid_to     TEXT NOT NULL DEFAULT '',
                method       TEXT NOT NULL DEFAULT 'rsign',
                uses         INTEGER NOT NULL DEFAULT 1,
                first_used_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                last_used_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(user_id, serial)
            )");
            db()->exec("CREATE INDEX IF NOT EXISTS idx_ezd_sign_certs_user ON ezd_sign_certs(user_id, last_used_at)");
        } catch (\Throwable $e) {}
    }

    /**
     * Zapamiętuje certyfikat użyty do podpisu (dane z ezd_validate_signature()['certs'][0]). Bez numeru seryjnego nie da się
     * odróżnić certyfikatów — wtedy kluczem jest CN+wystawca. Błąd zapisu nie może zepsuć podpisu (best-effort).
     *
     * @param array{cn?:string,o?:string,issuer?:string,serial?:string,from?:string,to?:string} $cert
     */
    public static function record(int $userId, array $cert, string $method = 'rsign'): void
    {
        if ($userId <= 0 || !$cert) return;
        try {
            self::migrate();
            $serial = trim((string)($cert['serial'] ?? ''));
            if ($serial === '') $serial = 'cn:' . md5(($cert['cn'] ?? '') . '|' . ($cert['issuer'] ?? ''));
            $row = db_one("SELECT id FROM ezd_sign_certs WHERE user_id=? AND serial=?", [$userId, $serial]);
            if ($row) {
                db()->prepare("UPDATE ezd_sign_certs SET cn=?, o=?, issuer=?, valid_from=?, valid_to=?, method=?, uses=uses+1, last_used_at=datetime('now') WHERE id=?")
                    ->execute([(string)($cert['cn'] ?? ''), (string)($cert['o'] ?? ''), (string)($cert['issuer'] ?? ''),
                               (string)($cert['from'] ?? ''), (string)($cert['to'] ?? ''), $method, (int)$row['id']]);
            } else {
                db()->prepare("INSERT INTO ezd_sign_certs (user_id, cn, o, issuer, serial, valid_from, valid_to, method) VALUES (?,?,?,?,?,?,?,?)")
                    ->execute([$userId, (string)($cert['cn'] ?? ''), (string)($cert['o'] ?? ''), (string)($cert['issuer'] ?? ''),
                               $serial, (string)($cert['from'] ?? ''), (string)($cert['to'] ?? ''), $method]);
            }
        } catch (\Throwable $e) {
            error_log('[EzdSignProfile] ' . $e->getMessage());
        }
    }

    /**
     * Ostatnio używany certyfikat użytkownika z wyliczonym stanem: 'active' (ważny), 'expiring' (≤ WARN_DAYS dni),
     * 'expired' (po terminie) albo 'unknown' (brak dat w certyfikacie). null = użytkownik nie podpisywał jeszcze w EZD.
     *
     * @return array{cn:string,o:string,issuer:string,serial:string,valid_from:string,valid_to:string,method:string,uses:int,last_used_at:string,state:string,days_left:?int}|null
     */
    public static function current(int $userId): ?array
    {
        try {
            self::migrate();
            $r = db_one("SELECT * FROM ezd_sign_certs WHERE user_id=? ORDER BY last_used_at DESC, id DESC LIMIT 1", [$userId]);
        } catch (\Throwable $e) { $r = null; }
        if (!$r) return null;
        $to = (string)$r['valid_to'];
        $days = null; $state = 'unknown';
        if ($to !== '' && ($ts = strtotime($to . ' 23:59:59')) !== false) {
            $days  = (int)floor(($ts - time()) / 86400);
            $state = $days < 0 ? 'expired' : ($days <= self::WARN_DAYS ? 'expiring' : 'active');
        }
        return ['cn' => (string)$r['cn'], 'o' => (string)$r['o'], 'issuer' => (string)$r['issuer'], 'serial' => (string)$r['serial'],
                'valid_from' => (string)$r['valid_from'], 'valid_to' => $to, 'method' => (string)$r['method'], 'uses' => (int)$r['uses'],
                'last_used_at' => (string)$r['last_used_at'], 'state' => $state, 'days_left' => $days];
    }

    /**
     * Liczba podpisów złożonych przez użytkownika w EZD i data ostatniego — z dziennika (sign_rsign + sign_uploaded).
     *
     * @return array{count:int,last_at:string}
     */
    public static function stats(int $userId): array
    {
        try {
            $r = db_one("SELECT COUNT(*) AS n, MAX(created_at) AS last_at FROM ezd_log WHERE user_id=? AND action IN ('sign_rsign','sign_uploaded')", [$userId]);
            return ['count' => (int)($r['n'] ?? 0), 'last_at' => (string)($r['last_at'] ?? '')];
        } catch (\Throwable $e) {
            return ['count' => 0, 'last_at' => ''];
        }
    }
}
