<?php
declare(strict_types=1);

/**
 * modules/ti_licencje_kursant/logic/LicencjeId.php — identyfikatory osoby w panelu licencji (polecenie 2026-11-09).
 *
 * Dwa numery do kontaktu z biurem i do dokumentów:
 *   • numer w panelu licencji: LIC-XXXX-XXXX — nadawany RAZ (przy założeniu konta przez kadrę albo pierwszym wejściu), potem się nie zmienia;
 *     losowy, bez liter mylących się z cyframi, tabela license_identities; nie jest loginem,
 *   • nr beneficjenta: „Nr kursanta” z Ewidencji Uczestników (k30_ti_student_accounts.student_no, 12 cyfr) — nadaje go Ewidencja, tu tylko pokazujemy.
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

    /** Nr beneficjenta = numer z Ewidencji Uczestników (student_no najnowszego aktywnego konta TI); '' gdy osoba nie ma konta w Ewidencji. */
    public static function beneficiary(int $cid): string
    {
        try {
            $r = db_one("SELECT student_no FROM k30_ti_student_accounts WHERE client_id=? AND COALESCE(student_no,'')<>'' ORDER BY is_active DESC, id DESC LIMIT 1", [$cid]);
            return trim((string)($r['student_no'] ?? ''));
        } catch (\Throwable $e) { return ''; }
    }
}
