<?php
declare(strict_types=1);

/** modules/ti_licencje_kursant/logic/LicencjeAdminNav.php — pozycje menu Administratora Licencji (Panel Licencji): [adres, etykieta, klucz bieżącej strony]. */
final class LicencjeAdminNav
{
    public static function items(): array
    {
        $b = '/licencje/';
        $i = [
            [$b . '?_route=' . rawurlencode('/admin'), 'Uczestnicy', 'admin'],
            [$b . 'licencjeKursantow.php', 'Programy i klucze', 'licencjeKursantow.php'],
            [$b . 'licencjeFundacji.php', 'Pula licencji Fundacji', 'licencjeFundacji.php'],
            [$b . 'licencjeDydaktyczne.php', 'Licencje AM5 / AM7 i operacje', 'licencjeDydaktyczne.php'],
            [$b . 'licencjeDydaktyczne.php?_route=' . rawurlencode('/korekty'), 'Korekty AMSK', 'licencjeDydaktyczne.php?_route=%2Fkorekty'],
            [$b . '?_route=' . rawurlencode('/admin/ceny-office'), 'Office: ceny planów', 'ceny-office'],
            [$b . 'claudeAbonament.php', 'Płatne licencje', 'claudeAbonament.php'],
        ];
        if (trim((string)(function_exists('org_setting') ? org_setting('ant1_enabled') : '')) === '1') $i[] = [$b . 'licencjeDydaktyczne.php?_route=' . rawurlencode('/ant1'), 'Dostęp do Claude (ANT1)', ''];
        $i[] = [$b . 'logout.php', 'Wyloguj', ''];
        return $i;
    }
}
