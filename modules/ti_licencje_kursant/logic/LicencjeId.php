<?php
declare(strict_types=1);

/**
 * modules/ti_licencje_kursant/logic/LicencjeId.php — numery osoby w panelu licencji (polecenie 2026-11-09).
 *
 *   • numer w panelu licencji: „l” + 8 cyfr (np. l57538286) — ten sam co w modules/license_login; nadawany RAZ
 *     (przy założeniu konta przez kadrę albo przy pierwszym wejściu z panelu TI), potem się nie zmienia,
 *   • nr beneficjenta: „Nr kursanta” z Ewidencji Uczestników (k30_ti_student_accounts.student_no, 12 cyfr) — nadaje go Ewidencja, tu tylko pokazujemy.
 */
require_once dirname(__DIR__, 2) . '/license_login/logic/licenseLogin.php';

final class LicencjeId
{
    /** Numer w panelu licencji (nadawany raz). */
    public static function licence(int $cid): string
    {
        return LicenseLogin::get($cid) ?: LicenseLogin::generate($cid, 'pierwsze wejście do panelu licencji');
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
