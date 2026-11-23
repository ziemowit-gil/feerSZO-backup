<?php
declare(strict_types=1);

/**
 * modules/ti_licencje_kursant/logic/ClaudeStud.php — Claude w Panelu Licencji kierowany do aplikacji „Zarządzaj subskrypcją Claude”
 * (stud-cloude.feer.org.pl, konta @stud.feer.org.pl; polecenie 2026-11-23: „W panelu licencji w SZO przekieruj Clauda do tego konta”).
 *
 * Kursant bez trwającego abonamentu Claude w SZO (requested / active / suspended) widzi na stronie Claude opis nowego procesu
 * zamiast wniosku z kontem @edu; kreator wniosku Claude odsyła na tę stronę. Abonamenty już działające w SZO (rozliczenia,
 * rezygnacja) zostają bez zmian. Administrator Licencji ma w menu link do panelu aplikacji.
 * Adres: ustawienie organizacji `claude_stud_url` (puste = DEFAULT_URL).
 */
final class ClaudeStud
{
    public const DEFAULT_URL = 'https://stud-cloude.feer.org.pl';

    public static function url(string $path = ''): string
    {
        $base = trim((string)(function_exists('org_setting') ? org_setting('claude_stud_url') : ''));
        return rtrim($base !== '' ? $base : self::DEFAULT_URL, '/') . '/' . ltrim($path, '/');
    }

    /** Czy uczestnik ma w SZO trwający abonament Claude (wtedy zostaje dotychczasowa strona z rozliczeniami). */
    public static function hasLegacySubscription(int $cid): bool
    {
        try {
            $sub = ClaudeBilling::forClient($cid, false, 'claude');
        } catch (\Throwable $e) {
            return false;
        }
        return $sub !== null && in_array($sub['status'], ['requested', 'active', 'suspended'], true);
    }

    /** Czy strona Claude tego uczestnika prowadzi do nowej aplikacji. */
    public static function redirects(int $cid): bool
    {
        return !self::hasLegacySubscription($cid);
    }
}
