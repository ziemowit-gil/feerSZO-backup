<?php
declare(strict_types=1);

/**
 * modules/ti_licencje_kursant/logic/LicencjeAdminNav.php — pozycje menu Administratora Licencji (Panel Licencji): [adres, etykieta, klucz bieżącej strony, grupa].
 * Grupa (4. pole) składa pozycje w rozwijane menu boczne (sideNav → _top.php, <details>); poziomy pasek Bootstrap (_admin_nav_bs.php) jest płaski.
 */
final class LicencjeAdminNav
{
    public static function items(): array
    {
        $b = '/licencje/';
        $i = [
            [$b . '?_route=' . rawurlencode('/admin'), 'Uczestnicy', 'admin'],
            [$b . 'licencjeKursantow.php', 'Programy i klucze', 'licencjeKursantow.php', 'Licencje'],
            [$b . 'licencjeFundacji.php', 'Pula licencji Fundacji', 'licencjeFundacji.php', 'Licencje'],
            [$b . 'licencjeDydaktyczne.php', 'Licencje AM5 / AM7 i operacje', 'licencjeDydaktyczne.php', 'Licencje'],
            [$b . 'licencjeDydaktyczne.php?_route=' . rawurlencode('/korekty'), 'Korekty AMSK', 'licencjeDydaktyczne.php?_route=%2Fkorekty', 'Licencje'],
            [$b . 'claudeAbonament.php', 'Płatne licencje', 'claudeAbonament.php', 'Płatne usługi i ceny'],
            [$b . '?_route=' . rawurlencode('/admin/ceny-office'), 'Office: ceny planów', 'ceny-office', 'Płatne usługi i ceny'],
            [$b . '?_route=' . rawurlencode('/admin/dysk'), 'Dysk Online: pakiety', 'dysk', 'Płatne usługi i ceny'],
        ];
        if (trim((string)(function_exists('org_setting') ? org_setting('ant1_enabled') : '')) === '1') $i[] = [$b . 'licencjeDydaktyczne.php?_route=' . rawurlencode('/ant1'), 'Dostęp do Claude (ANT1)', '', 'Licencje'];
        $i[] = [$b . 'logout.php', 'Wyloguj', ''];
        return $i;
    }

    /** Menu boczne (_top.php) z grupami: pozycja [adres, etykieta, plakietka, pilne, bieżąca] albo ['group' => nazwa, 'items' => [...]]. */
    public static function sideNav(string $curKey): array
    {
        $nav = []; $at = [];
        foreach (self::items() as $x) {
            $it = [$x[0], $x[1], '', false, $x[2] !== '' && $x[2] === $curKey];
            $g = (string)($x[3] ?? '');
            if ($g === '') { $nav[] = $it; continue; }
            if (!isset($at[$g])) { $at[$g] = count($nav); $nav[] = ['group' => $g, 'items' => []]; }
            $nav[$at[$g]]['items'][] = $it;
        }
        return $nav;
    }
}
