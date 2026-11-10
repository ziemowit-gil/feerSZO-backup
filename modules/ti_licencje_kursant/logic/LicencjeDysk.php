<?php
declare(strict_types=1);

/**
 * modules/ti_licencje_kursant/logic/LicencjeDysk.php — Dysk Online (pilotaż, realizuje sdata.net.pl): pakiety i ceny (polecenie 2026-11-10).
 * Domyślne pakiety z cennika sdata.net.pl (WD 100 / 300 / 500); cenę i dostępność pakietu zmienia administrator albo kierownik
 * w Panelu Licencji (Dysk Online: pakiety i ceny) — zapis w ustawieniu dysk_plans (JSON). Uczestnik wybiera pakiet przy zgłoszeniu do pilotażu.
 */
final class LicencjeDysk
{
    public const PLANS = [
        'wd100' => ['name' => 'WD 100', 'price' => 19.0, 'size' => '100 GB', 'users' => 10, 'order' => 'https://sdata.net.pl/finalizacja-zamowienia-wirtualny-dysk/?plan=wd100'],
        'wd300' => ['name' => 'WD 300', 'price' => 39.0, 'size' => '300 GB', 'users' => 20, 'order' => 'https://sdata.net.pl/finalizacja-zamowienia-wirtualny-dysk/?plan=wd300'],
        'wd500' => ['name' => 'WD 500', 'price' => 59.0, 'size' => '500 GB', 'users' => null, 'order' => 'https://sdata.net.pl/finalizacja-zamowienia-wirtualny-dysk/?plan=wd500'],
    ];
    /** Wspólne cechy wszystkich pakietów (cennik dostawcy). */
    public const FEATURES = ['dostęp przez przeglądarkę i jako dysk w systemie', 'udostępnianie plików i folderów, także publiczne', 'wersje plików',
                             'tylko szyfrowane połączenia', 'dostęp z telefonu', 'kopia zapasowa w cenie'];

    /** Pakiety z cenami (ustawienia administratora nadpisują domyślne). @return array<string,array{name:string,price:float,size:string,users:?int,order:string,active:bool}> */
    public static function plans(bool $onlyActive = false): array
    {
        $cfg = json_decode((string)(function_exists('org_setting') ? org_setting('dysk_plans') : ''), true);
        $out = [];
        foreach (self::PLANS as $k => $p) {
            $c = is_array($cfg[$k] ?? null) ? $cfg[$k] : [];
            $p['price'] = isset($c['price']) && is_numeric($c['price']) ? max(0.0, (float)$c['price']) : $p['price'];
            $p['users'] = array_key_exists('users', $c) ? ($c['users'] === null || $c['users'] === '' ? null : max(1, (int)$c['users'])) : $p['users'];
            $p['active'] = array_key_exists('active', $c) ? (bool)$c['active'] : true;
            if ($onlyActive && !$p['active']) continue;
            $out[$k] = $p;
        }
        return $out;
    }

    /** Zapis cen i dostępności pakietów. $in: [key => ['price' => '19', 'users' => '10', 'active' => bool]] */
    public static function save(array $in, string $by): void
    {
        $cfg = [];
        foreach (self::PLANS as $k => $p) {
            $r = $in[$k] ?? [];
            $price = str_replace(',', '.', trim((string)($r['price'] ?? '')));
            if ($price === '' || !is_numeric($price) || (float)$price < 0 || (float)$price > 10000) throw new InvalidArgumentException('Cena pakietu ' . $p['name'] . ': wpisz kwotę w złotych.');
            $users = trim((string)($r['users'] ?? ''));
            if ($users !== '' && (!ctype_digit($users) || (int)$users < 1)) throw new InvalidArgumentException('Liczba użytkowników pakietu ' . $p['name'] . ': liczba od 1.');
            $cfg[$k] = ['price' => round((float)$price, 2), 'users' => $users === '' ? null : (int)$users, 'active' => !empty($r['active'])];
        }
        org_setting_set('dysk_plans', json_encode($cfg, JSON_UNESCAPED_UNICODE));
        try { require_once dirname(__DIR__, 2) . '/claude_billing/logic/claudeBilling.php'; ClaudeBilling::event(null, null, 'dysk_plans', 'Dysk Online — ceny pakietów: ' . json_encode($cfg, JSON_UNESCAPED_UNICODE), $by); } catch (\Throwable $e) {}
    }

    public static function zl(float $v): string { return number_format($v, $v == floor($v) ? 0 : 2, ',', ' ') . ' zł'; }
}
