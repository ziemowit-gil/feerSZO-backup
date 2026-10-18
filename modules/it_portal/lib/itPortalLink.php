<?php
/**
 * modules/it_portal/lib/itPortalLink.php — kanoniczny adres Portalu Usług IT do linkowania z innych modułów
 * (np. panelu dydaktyka na kursant.* / ti.*). Lekki plik bez schematu i zapytań — w przeciwieństwie do
 * logic/itPortal.php można go dołączyć w dowolnym panelu.
 *
 * Portal działa pod /uslugi-it na każdym hoście (reguła .htaccess stoi przed regułami subdomen), ale
 * „nowy adres”, który pokazujemy ludziom, to zawsze ten na hoście SZO: na ti*. i kurs*. podmieniamy
 * pierwszą etykietę hosta na „szo” (kursant.feer.org.pl → szo.feer.org.pl).
 */

/** Pełny adres portalu, np. https://szo.feer.org.pl/uslugi-it/ (ścieżka opcjonalna). */
function itPortalPublicUrl(string $path = ''): string
{
    $base = rtrim(defined('APP_URL') ? APP_URL : '', '/');
    $p    = parse_url($base);
    $host = (string)($p['host'] ?? '');
    if ($host !== '' && preg_match('/^(ti|kurs)[^.]*\.(.+)$/i', $host, $m)) {
        $base = ($p['scheme'] ?? 'https') . '://szo.' . $m[2] . (isset($p['port']) ? ':' . $p['port'] : '');
    }
    return $base . '/uslugi-it/' . ltrim($path, '/');
}

/** Adres do pokazania w tekście (bez https:// i końcowego ukośnika), np. szo.feer.org.pl/uslugi-it. */
function itPortalPublicLabel(): string
{
    return rtrim(preg_replace('#^https?://#', '', itPortalPublicUrl()), '/');
}
