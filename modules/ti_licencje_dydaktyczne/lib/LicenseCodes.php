<?php
declare(strict_types=1);

/**
 * modules/ti_licencje_dydaktyczne/lib/LicenseCodes.php — kod Code128 łączący formularze AM ze sprawą.
 *
 * Każdy druk AM (AM5, AM7, AM11–AM24, AM90, AM91, AMSK…) ma w stopce Code128 postaci  KOD_SPRAWY/SYMBOL:
 *   LD000002/AM7-2   sprawa AM5/AM7 nr 2, decyzja AM7/2     OP000005/AM20   operacja nr 5, wniosek AM20
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

    /** Kod formularza z numeru dokumentu (numer sprawy / operacji / korekty) i symbolu; '' gdy numeru nie da się powiązać ze sprawą. */
    public static function forNumber(string $number, string $symbol): string
    {
        try {
            if (preg_match('/AMSK-(\d{6})/', $number, $m)) return self::formCode('AMSK-' . $m[1], $symbol);
            $base = trim(explode(' · ', $number)[0]);
            if ($base === '') return '';
            if (str_starts_with($base, LD_CASE_PREFIX . '/')) {
                $r = db_one("SELECT id FROM licenses WHERE case_number = ?", [$base]);
                return $r ? self::formCode(self::caseCode('license', (int)$r['id']), $symbol) : '';
            }
            if (str_starts_with($base, '4000-')) {
                $r = db_one("SELECT id FROM license_operations WHERE number = ?", [$base]);
                return $r ? self::formCode(self::caseCode('operation', (int)$r['id']), $symbol) : '';
            }
        } catch (\Throwable $e) { /* druk bez kodu jest lepszy niż brak druku */ }
        return '';
    }

    /**
     * Rozpoznanie wpisanego / zeskanowanego kodu: LD000002[/AM7-2], OP000005[/AM20], AMSK-000012[/AMSK-1].
     * @return array{scope:string,id:int,symbol:string}|null scope: license | operation | correction
     */
    public static function resolve(string $code): ?array
    {
        $code = strtoupper(trim($code));
        if (!preg_match('~^(LD|OP|AMSK-)(\d{1,9})(?:/([A-Z0-9-]{0,24}))?$~', $code, $m)) return null;
        return ['scope' => ['LD' => 'license', 'OP' => 'operation', 'AMSK-' => 'correction'][$m[1]], 'id' => (int)$m[2], 'symbol' => $m[3] ?? ''];
    }
}
