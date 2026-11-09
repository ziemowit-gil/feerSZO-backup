<?php
declare(strict_types=1);

/**
 * modules/ti_licencje_kursant/logic/LicencjeId.php — identyfikatory osoby w panelu licencji (polecenie 2026-11-09).
 *
 * Dwa numery do kontaktu z biurem i do dokumentów, NIGDZIE nie są loginami:
 *   • identyfikator w systemie licencji: LIC-XXXX-XXXX (losowy, stały, bez liter mylących się z cyframi; tabela license_identities),
 *   • numer beneficjenta: BEN-000123 (numer osoby w kartotece uczestników, k30_clients.id).
 * Login do panelu licencji (l + 8 cyfr) to osobna rzecz (modules/license_login).
 */
final class LicencjeId
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    private static function migrate(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        if (function_exists('szo_schema_current') && szo_schema_current('license_identities_migrate', __FILE__)) return;
        db()->exec("CREATE TABLE IF NOT EXISTS license_identities (client_id INTEGER PRIMARY KEY, ident TEXT NOT NULL UNIQUE, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)");
    }

    /** Identyfikator w systemie licencji (tworzony przy pierwszym odczycie). */
    public static function licence(int $cid): string
    {
        self::migrate();
        $r = db_one("SELECT ident FROM license_identities WHERE client_id=?", [$cid]);
        if ($r) return (string)$r['ident'];
        for ($i = 0; $i < 10; $i++) {
            $c = '';
            for ($k = 0; $k < 8; $k++) $c .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            $id = 'LIC-' . substr($c, 0, 4) . '-' . substr($c, 4);
            try { db_exec("INSERT INTO license_identities (client_id, ident) VALUES (?, ?)", [$cid, $id]); return $id; }
            catch (\Throwable $e) {
                $r = db_one("SELECT ident FROM license_identities WHERE client_id=?", [$cid]);   // równoległe wejście już go założyło
                if ($r) return (string)$r['ident'];
            }
        }
        throw new RuntimeException('Nie udało się nadać identyfikatora w systemie licencji.');
    }

    /** Numer beneficjenta: numer w kartotece uczestników. */
    public static function beneficiary(int $cid): string
    {
        return 'BEN-' . str_pad((string)$cid, 6, '0', STR_PAD_LEFT);
    }
}
