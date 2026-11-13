<?php
declare(strict_types=1);

/**
 * modules/dhosting_migrator/lib/DhostingGraphSource.php — źródło kont dla migratora: Microsoft 365 głównego tenanta (Graph, app-only).
 *
 * Korzysta z istniejącego klienta M365Graph (includes/m365.php): list_all_users() stronicuje @odata.nextLink i rzuca wyjątek
 * przy błędzie (pusta lista nie udaje „pustego tenanta”); get_user_by_id() do samoobsługi.
 * Uprawnienie aplikacji: User.Read.All (albo szersze User.ReadWrite.All / Directory.Read.All — już nadane w SZO).
 * Graph nie zwraca haseł — migrator zawsze generuje nowe.
 */
require_once dirname(__DIR__, 3) . '/includes/m365.php';

final class DhostingGraphSource
{
    private static function graph(): M365Graph
    {
        if (m365_setting('m365_enabled') !== '1') throw new RuntimeException('integracja Microsoft 365 jest wyłączona (Admin › Microsoft 365).');
        $g = new M365Graph();
        if (!$g->is_configured()) throw new RuntimeException('brak danych aplikacji Microsoft 365 (tenant, client ID, sekret).');
        return $g;
    }

    /**
     * Konta członków (bez gości #EXT#) z UPN w podanych domenach.
     * @param string[] $domains
     * @return array<int, array{id:string, displayName:string, userPrincipalName:string, accountEnabled:bool}>
     */
    public static function users(array $domains): array
    {
        static $cache = [];
        $domains = array_map('strtolower', $domains);
        $key = implode(',', $domains);
        if (isset($cache[$key])) return $cache[$key];
        $out = [];
        foreach (self::graph()->list_all_users() as $u) {
            $upn = strtolower((string)($u['userPrincipalName'] ?? ''));
            if ($upn === '' || str_contains($upn, '#ext#')) continue;
            if (!in_array(substr($upn, (int)strrpos($upn, '@') + 1), $domains, true)) continue;
            $out[] = $u;
        }
        return $cache[$key] = $out;
    }

    /** Jedno konto po id (samoobsługa, gdy w SZO brak m365_login). Pusta tablica = nie znaleziono. */
    public static function user(string $id): array
    {
        return self::graph()->get_user_by_id($id);
    }
}
