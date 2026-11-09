<?php
declare(strict_types=1);

/**
 * modules/claude_billing/lib/PkoRates.php — ostatni kurs waluty z tabeli kursów PKO Banku Polskiego.
 *
 * Źródło: publiczne API strony pkobp.pl (to samo, z którego korzysta strona „Kursy walut”):
 *   1) GET /api/modules/fxrates/main/table?date=RRRR-MM-DD → lista tabel z dnia (kilka w ciągu dnia; ostatnia = najnowsza),
 *      w dni bez notowań pusta lista — cofamy się do 10 dni,
 *   2) GET /api/modules/fxrates/main/rates?table-id=N → kursy walut z tabeli.
 * Rodzaje kursu (pola odpowiedzi): sale_foreign = sprzedaż dewiz (domyślnie — po tym kursie bank sprzedaje USD na płatność
 * za licencję), buy_foreign = kupno dewiz, avg_foreign = kurs średni, sale / buy = sprzedaż / kupno pieniędzy (gotówka).
 *
 * Pobrane kursy trafiają do tabeli claude_fx_rates (dziennik — każde naliczenie wskazuje tabelę i kurs). Gdy API nie odpowiada,
 * zwracamy ostatni zapisany kurs (z flagą stale), a gdy nie ma żadnego — wyjątek (naliczenie się nie tworzy, CRON spróbuje ponownie).
 * Bez curl — stream_context (MyDevil, jak w integracji ownCloud).
 */
final class PkoRates
{
    public const KINDS = [
        'sale_foreign' => 'sprzedaż dewiz',
        'avg_foreign'  => 'kurs średni dewiz',
        'buy_foreign'  => 'kupno dewiz',
        'sale'         => 'sprzedaż pieniędzy',
        'buy'          => 'kupno pieniędzy',
    ];
    private const BASE = 'https://www.pkobp.pl/api/modules/fxrates/main';

    public static function migrate(): void
    {
        db()->exec("CREATE TABLE IF NOT EXISTS claude_fx_rates (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            currency TEXT NOT NULL, kind TEXT NOT NULL, rate REAL NOT NULL,
            table_id INTEGER, table_number TEXT NOT NULL DEFAULT '', table_at TEXT NOT NULL DEFAULT '',
            fetched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)");
        db()->exec("CREATE INDEX IF NOT EXISTS idx_claude_fx ON claude_fx_rates(currency, kind, id)");
    }

    /**
     * Najnowszy kurs. @return array{rate:float,currency:string,kind:string,kind_label:string,table_id:int,table_number:string,table_at:string,source:string,stale:bool,fetched_at:string}
     * @throws RuntimeException gdy nie ma ani kursu z API, ani zapisanego wcześniej
     */
    public static function latest(string $currency = 'USD', string $kind = 'sale_foreign', ?string $onDate = null): array
    {
        self::migrate();
        $currency = strtoupper($currency);
        if (!isset(self::KINDS[$kind])) $kind = 'sale_foreign';
        $err = '';
        try {
            $day = $onDate ? strtotime($onDate) : time();
            for ($i = 0; $i <= 10; $i++) {
                $d = date('Y-m-d', strtotime("-$i day", $day));
                $tables = self::get(self::BASE . '/table?date=' . $d);
                if (!$tables) continue;
                $t = end($tables);
                $tid = (int)($t['id'] ?? 0);
                if ($tid <= 0) continue;
                foreach (self::get(self::BASE . '/rates?table-id=' . $tid) as $r) {
                    if (strtoupper((string)($r['currency_code'] ?? '')) !== $currency) continue;
                    $unit = max(1, (int)($r['currency_unit'] ?? 1));
                    $rate = round((float)str_replace(',', '.', (string)($r[$kind] ?? '0')) / $unit, 6);
                    if ($rate <= 0) throw new RuntimeException('PKO BP nie podał kursu „' . self::KINDS[$kind] . '” dla ' . $currency . '.');
                    $row = ['currency' => $currency, 'kind' => $kind, 'rate' => $rate, 'table_id' => $tid,
                            'table_number' => (string)($t['table_number'] ?? ''), 'table_at' => (string)($t['table_date'] ?? $d)];
                    $last = db_one("SELECT * FROM claude_fx_rates WHERE currency=? AND kind=? ORDER BY id DESC LIMIT 1", [$currency, $kind]);
                    if (!$last || (int)$last['table_id'] !== $tid || abs((float)$last['rate'] - $rate) > 0.0000001) db_insert('claude_fx_rates', $row);
                    return $row + ['kind_label' => self::KINDS[$kind], 'source' => 'PKO BP', 'stale' => false, 'fetched_at' => date('Y-m-d H:i:s')];
                }
                throw new RuntimeException('W tabeli PKO BP nr ' . ($t['table_number'] ?? $tid) . ' nie ma waluty ' . $currency . '.');
            }
            $err = 'brak tabel kursów PKO BP z ostatnich 10 dni';
        } catch (\Throwable $e) { $err = $e->getMessage(); }

        $last = db_one("SELECT * FROM claude_fx_rates WHERE currency=? AND kind=? ORDER BY id DESC LIMIT 1", [$currency, $kind]);
        if (!$last) throw new RuntimeException('Nie udało się pobrać kursu ' . $currency . ' z PKO BP (' . $err . ').');
        return ['currency' => $currency, 'kind' => $kind, 'rate' => (float)$last['rate'], 'table_id' => (int)$last['table_id'],
                'table_number' => (string)$last['table_number'], 'table_at' => (string)$last['table_at'], 'kind_label' => self::KINDS[$kind],
                'source' => 'PKO BP (ostatni zapisany — ' . $err . ')', 'stale' => true, 'fetched_at' => (string)$last['fetched_at']];
    }

    /** Data tabeli w formacie dd.mm.rrrr (gg:mm). */
    public static function tableLabel(array $r): string
    {
        $ts = strtotime((string)($r['table_at'] ?? ''));
        return trim('nr ' . ($r['table_number'] ?? '') . ($ts ? ' z ' . date('d.m.Y H:i', $ts) : ''));
    }

    private static function get(string $url): array
    {
        $ctx = stream_context_create(['http' => [
            'method' => 'GET', 'timeout' => 12, 'ignore_errors' => true,
            'header' => "Accept: application/json\r\nUser-Agent: Mozilla/5.0 (compatible; SZO-FEER/1.0)",
        ]]);
        $raw = @file_get_contents($url, false, $ctx);
        $code = 0;
        $hdr = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : (${'http_response_header'} ?? []);   // PHP 8.5: zmienna przestarzała
        if (isset($hdr[0]) && preg_match('#HTTP/\S+\s+(\d+)#', $hdr[0], $m)) $code = (int)$m[1];
        if (!is_string($raw) || $raw === '') throw new RuntimeException('PKO BP nie odpowiada (HTTP ' . $code . ').');
        $data = json_decode($raw, true);
        if (!is_array($data)) throw new RuntimeException('PKO BP zwrócił stronę zamiast danych (HTTP ' . $code . ', np. przerwa techniczna).');
        return $data;
    }
}
