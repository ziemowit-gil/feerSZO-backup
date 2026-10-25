<?php
declare(strict_types=1);

/**
 * modules/ti_licencje_dydaktyczne/lib/LicenseCodes.php — kod Code128 łączący formularze AM ze sprawą.
 *
 * Każdy druk AM (AM5, AM7, AM11–AM24, AM90, AM91, AMSK…) ma w stopce Code128 postaci  KOD_SPRAWY/SYMBOL:
 *   LD000002/AM7-2/R2   sprawa AM5/AM7 nr 2, decyzja AM7/2, rewizja R2 (po jednej zatwierdzonej korekcie AMSK)
 *   OP000005/AM20/R1    operacja nr 5, wniosek AM20, rewizja R1
 *   AMSK-000012/AMSK-1   korekta nr 12, druk kontrolny AMSK/1
 * Prefiks przed „/” jest wspólny dla wszystkich formularzy jednej sprawy — skan dowolnego z nich (czytnik wpisuje kod w pole
 * „Skanuj kod” na pulpicie licencji) otwiera sprawę, korektę albo operację. Alfabet: wielkie litery, cyfry, „-” i „/” (Code128-B).
 */
require_once __DIR__ . '/ldSchema.php';

final class LicenseCodes
{
    public static function caseCode(string $scope, int $id): string
    {
        return ($scope === 'operation' ? 'OP' : 'LD') . str_pad((string)$id, 6, '0', STR_PAD_LEFT);
    }

    /** Symbol dokumentu do kodu: wielkie litery i cyfry, reszta → „-” (AM7/2 → AM7-2). */
    public static function symbolToken(string $symbol): string
    {
        return trim((string)preg_replace('/[^A-Z0-9]+/', '-', strtoupper($symbol)), '-');
    }

    public static function formCode(string $caseCode, string $symbol): string
    {
        $t = self::symbolToken($symbol);
        return $t === '' ? $caseCode : $caseCode . '/' . $t;
    }

    /** Rewizja druków sprawy: 1 + liczba ZATWIERDZONYCH korekt AMSK. Druk wydany przed korektą ma niższą rewizję (jest nieaktualny). */
    public static function revision(string $scope, int $id): int
    {
        if (in_array($scope, ['correction', 'rotation', 'package'], true)) return 1;
        try {
            return 1 + (int)(db_one("SELECT COUNT(*) AS n FROM license_corrections WHERE scope = ? AND target_id = ? AND status = 'applied'", [$scope, $id])['n'] ?? 0);
        } catch (\Throwable $e) { return 1; }
    }

    /** Symbol dokumentu z jego numeru („… · AM7/2” → AM7-2, „… · AM90/2” → AM90-2) albo z symbolu formularza (AM7 → AM7-1: wniosek). */
    public static function docToken(string $number, string $symbol): string
    {
        $parts = explode(' · ', $number);
        if (isset($parts[1]) && trim($parts[1]) !== '') return self::symbolToken($parts[1]);
        $t = self::symbolToken($symbol);
        return in_array($t, ['AM5', 'AM7'], true) ? $t . '-1' : $t;
    }

    /**
     * Kod formularza z numeru dokumentu (numer sprawy / operacji / korekty) i symbolu, z rewizją: LD000002/AM7-2/R2.
     * '' gdy numeru nie da się powiązać ze sprawą. Korekty (AMSK-…) nie mają rewizji.
     */
    public static function forNumber(string $number, string $symbol): string
    {
        try {
            if (preg_match('/AMSK-(\d{6})/', $number, $m)) return self::formCode('AMSK-' . $m[1], $symbol);
            if (preg_match('/PP-(\d{6})/', $number, $m) && str_contains($symbol, 'PP')) return self::formCode('PP-' . $m[1], $symbol);
            if (preg_match('/UP1-(\d{6})/', $number, $m) && str_contains($symbol, 'UP1')) return self::formCode('UP1-' . $m[1], $symbol);
            $base = trim(explode(' · ', $number)[0]);
            if ($base === '') return '';
            $scope = ''; $id = 0;
            if (str_starts_with($base, LD_CASE_PREFIX . '/')) {
                $r = db_one("SELECT id FROM licenses WHERE case_number = ?", [$base]); if ($r) { $scope = 'license'; $id = (int)$r['id']; }
            } elseif (str_starts_with($base, '4000-')) {
                $r = db_one("SELECT id FROM license_operations WHERE number = ?", [$base]); if ($r) { $scope = 'operation'; $id = (int)$r['id']; }
            }
            if ($id === 0) return '';
            return self::formCode(self::caseCode($scope, $id), self::docToken($number, $symbol)) . '/R' . self::revision($scope, $id);
        } catch (\Throwable $e) { /* druk bez kodu jest lepszy niż brak druku */ }
        return '';
    }

    /**
     * Rozpoznanie wpisanego / zeskanowanego kodu: LD000002[/AM7-2], OP000005[/AM20], AMSK-000012[/AMSK-1].
     * Kod może mieć rewizję: LD000002/AM7-2/R2 (rev = 2; 0 = druk bez oznaczenia wersji, sprzed wprowadzenia kodów z rewizją).
     * @return array{scope:string,id:int,symbol:string,rev:int}|null scope: license | operation | correction | rotation (UP1-NNNNNN) | package (PP-NNNNNN)
     */
    public static function resolve(string $code): ?array
    {
        $code = strtoupper(trim($code));
        if (!preg_match('~^(LD|OP|AMSK-|UP1-|PP-)(\d{1,9})(?:/(?!R\d+$)([A-Z0-9-]{1,24}))?(?:/R(\d{1,3}))?$~', $code, $m)) return null;
        return ['scope' => ['LD' => 'license', 'OP' => 'operation', 'AMSK-' => 'correction', 'UP1-' => 'rotation', 'PP-' => 'package'][$m[1]], 'id' => (int)$m[2], 'symbol' => $m[3] ?? '', 'rev' => (int)($m[4] ?? 0)];
    }
}
