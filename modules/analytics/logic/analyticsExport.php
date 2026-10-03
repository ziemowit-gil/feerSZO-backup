<?php
/**
 * modules/analytics/logic/analyticsExport.php — wyświetlanie i eksport zestawów danych Analityki (CSV, Excel, lista tekstowa).
 *
 * Zestaw danych: patrz nagłówek analyticsManager.php. Wspólne formatowanie liczb (po polsku: przecinek, spacja
 * tysięcy) i komórek, żeby tabela na ekranie, widok listy i pliki pokazywały to samo.
 */
final class AnalyticsExport
{
    /** Liczba/tekst komórki do wyświetlenia na ekranie. null → „—”. */
    public static function display(mixed $v, int $decimals = 0): string
    {
        if ($v === null || $v === '') return '—';
        if (is_int($v) || is_float($v)) return number_format((float)$v, $decimals, ',', ' ');
        return (string)$v;
    }

    /** Liczba miejsc po przecinku kolumny (z $ds['dec']; domyślnie 0). */
    public static function decimals(array $ds, int $col): int { return (int)($ds['dec'][$col] ?? 0); }

    /**
     * Wiersze jako pełne zdania „1. Kursant: Jan Kowalski. E-mail: …” — widok dla czytników ekranu (bez tabeli).
     * Puste pola pomijane, liczby po polsku.
     *
     * @return list<string>
     */
    public static function sentences(array $ds): array
    {
        $out = [];
        foreach ($ds['rows'] as $i => $row) {
            $parts = [];
            foreach ($ds['headers'] as $c => $h) {
                $v = $row[$c] ?? null;
                if ($v === null || $v === '') continue;
                $parts[] = rtrim($h, '.:') . ': ' . self::display($v, self::decimals($ds, $c));
            }
            $out[] = ($i + 1) . '. ' . ($parts ? implode('. ', $parts) . '.' : 'brak danych.');
        }
        return $out;
    }

    /** Nazwa pliku eksportu: analityka_<raport>_<data>.<rozszerzenie>. */
    public static function filename(string $key, string $ext): string
    {
        return 'analityka_' . preg_replace('/[^a-z0-9_]+/i', '_', $key) . '_' . date('Y-m-d') . '.' . ltrim($ext, '.');
    }

    /**
     * Komórka CSV. Teksty zaczynające się od = + - @ (lub tabulatora / CR) dostają apostrof z przodu —
     * bez tego arkusz wykonałby je jak formułę (np. kursant o nazwisku „=HYPERLINK(…)”).
     */
    public static function csvCell(mixed $v, int $decimals = 0): string
    {
        if ($v === null) return '';
        if (is_int($v) || is_float($v)) return number_format((float)$v, $decimals, ',', '');
        $s = (string)$v;
        return preg_match('/^[=+\-@\t\r]/', $s) ? "'" . $s : $s;
    }

    /** Wysyła CSV (średnik, UTF-8 z BOM — Excel otwiera polskie znaki poprawnie). Wywołujący kończy żądanie. */
    public static function csv(array $ds, string $filename): void
    {
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store');
        $o = fopen('php://output', 'w');
        fwrite($o, "\xEF\xBB\xBF");
        fputcsv($o, array_map(fn($h) => self::csvCell((string)$h), $ds['headers']), ';', '"', '\\');
        foreach ($ds['rows'] as $row) {
            $cells = [];
            foreach ($row as $c => $v) $cells[] = self::csvCell($v, self::decimals($ds, (int)$c));
            fputcsv($o, $cells, ';', '"', '\\');
        }
        fclose($o);
    }

    /** Wysyła plik Excel (jeden arkusz: tytuł, opis, nagłówki, dane). XlsxWriter kończy żądanie. */
    public static function xlsx(array $ds, string $by, string $filename): void
    {
        require_once dirname(__DIR__, 3) . '/includes/xlsx.php';
        while (ob_get_level() > 0) ob_end_clean();
        $x = new XlsxWriter();
        $x->addSheet(mb_substr((string)($ds['sheet'] ?? 'Dane'), 0, 31));
        $x->writeRow([$ds['title']], ['header', 'text']);
        $x->writeRow([$ds['subtitle']], ['text']);
        $x->writeRow([]);
        $x->writeRow($ds['headers'], ['header', 'text']);
        foreach ($ds['rows'] as $row) $x->writeRow($row, ['text']);
        if (!$ds['rows']) $x->writeRow(['Brak danych dla wybranych filtrów.'], ['text']);
        $x->writeRow([]);
        $x->writeRow(['Wygenerowano: ' . date('d.m.Y H:i') . ' przez ' . $by], ['text']);
        $x->output($filename);
    }
}
