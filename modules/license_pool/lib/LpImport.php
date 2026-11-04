<?php
declare(strict_types=1);

/**
 * modules/license_pool/lib/LpImport.php — parser TXT z licencjami Fundacji (bez zapisu do bazy).
 *
 * Format (UTF-8 lub Windows-1250, BOM ignorowany):
 *   # komentarz                      — pomijany (puste linie też)
 *   [Nazwa produktu]                 — nagłówek: kolejne linie dotyczą tego produktu
 *   KLUCZ                            — sam klucz
 *   KLUCZ ; miejsca ; ważność ; uwaga   — separator: średnik, tabulator lub |; ważność RRRR-MM-DD albo DD.MM.RRRR
 * Bez nagłówka produkt bierze się z listy w formularzu (parametr $defaultProduct).
 */
final class LpImport
{
    public const MAX_BYTES = 2_000_000;
    public const MAX_LINES = 5000;

    /**
     * @return array{rows:list<array{line:int,product:string,code:string,seats:int,valid_until:?string,note:string}>,
     *               errors:list<array{line:int,msg:string}>}
     */
    public static function parse(string $raw, string $defaultProduct = ''): array
    {
        $raw = self::toUtf8($raw);
        $rows = []; $errors = [];
        $product = trim($defaultProduct);
        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        if (count($lines) > self::MAX_LINES) {
            return ['rows' => [], 'errors' => [['line' => 0, 'msg' => 'Plik ma więcej niż ' . self::MAX_LINES . ' linii — podziel go.']]];
        }
        foreach ($lines as $i => $line) {
            $n = $i + 1;
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            if (preg_match('/^\[(.+)\]$/u', $line, $m)) { $product = trim($m[1]); continue; }
            $cols = array_map('trim', preg_split('/[;\t|]/', $line) ?: []);
            $code = $cols[0] ?? '';
            if ($product === '') { $errors[] = ['line' => $n, 'msg' => 'Brak produktu (wybierz produkt albo dodaj linię [Nazwa produktu]).']; continue; }
            if (mb_strlen($code) < 4 || mb_strlen($code) > 1000 || preg_match('/\s{2,}/', $code)) { $errors[] = ['line' => $n, 'msg' => 'Niepoprawny klucz (4–1000 znaków).']; continue; }
            $seats = 1;
            if (($cols[1] ?? '') !== '') {
                if (!ctype_digit($cols[1]) || (int)$cols[1] < 1 || (int)$cols[1] > 100000) { $errors[] = ['line' => $n, 'msg' => 'Liczba miejsc: liczba całkowita ≥ 1.']; continue; }
                $seats = (int)$cols[1];
            }
            $until = null;
            if (($cols[2] ?? '') !== '') {
                $until = self::date($cols[2]);
                if ($until === null) { $errors[] = ['line' => $n, 'msg' => 'Ważność: format RRRR-MM-DD lub DD.MM.RRRR.']; continue; }
            }
            $note = mb_substr($cols[3] ?? '', 0, 300);
            $rows[] = ['line' => $n, 'product' => $product, 'code' => $code, 'seats' => $seats, 'valid_until' => $until, 'note' => $note];
        }
        return ['rows' => $rows, 'errors' => $errors];
    }

    private static function toUtf8(string $s): string
    {
        if (str_starts_with($s, "\xEF\xBB\xBF")) $s = substr($s, 3);
        if (!mb_check_encoding($s, 'UTF-8')) $s = (string)mb_convert_encoding($s, 'UTF-8', 'Windows-1250');
        return $s;
    }

    private static function date(string $v): ?string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m)) { [$y, $mo, $d] = [(int)$m[1], (int)$m[2], (int)$m[3]]; }
        elseif (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $v, $m)) { [$d, $mo, $y] = [(int)$m[1], (int)$m[2], (int)$m[3]]; }
        else return null;
        return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
    }
}
