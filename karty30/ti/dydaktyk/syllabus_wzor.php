<?php
/**
 * karty30/ti/dydaktyk/syllabus_wzor.php — wzór pliku CSV do wgrania sylabusa.
 *
 * Plik ma BOM UTF-8, bo bez niego Excel otwiera polskie znaki jako krzaki,
 * i separator „;”, który arkusze w polskiej lokalizacji rozpoznają domyślnie.
 * Kolumny są takie same, jakich oczekuje k30_ti_curriculum_import_csv().
 */
require_once __DIR__ . '/auth.php';

dyd_require();

$rows = [
    ['dział', 'temat', 'opis', 'czas_min'],
    ['Podstawy obsługi', 'Włączanie i logowanie', 'Uruchomienie komputera, logowanie do systemu', '45'],
    ['Podstawy obsługi', 'Pulpit i okna', 'Ikony, menu Start, przełączanie okien', '30'],
    ['Czytnik ekranu', 'Pierwsze uruchomienie NVDA', 'Skróty klawiszowe, odczyt ekranu', '60'],
    ['Czytnik ekranu', 'Nawigacja po stronie internetowej', '', '90'],
];

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="wzor-sylabusa.csv"');

// Składamy plik ręcznie, a nie przez fputcsv(): w PHP 8.4 wywołanie bez
// parametru $escape jest przeterminowane, a ostrzeżenie wypisane do strumienia
// zepsułoby pobierany plik. Pola wzoru nie zawierają średnika ani cudzysłowu,
// więc cytowanie jest zbędne; końce wierszy CRLF, bo tego oczekuje Excel.
$lines = [];
foreach ($rows as $r) {
    $lines[] = implode(';', array_map(
        fn($v) => (strpbrk((string)$v, ";\"\r\n") !== false)
            ? '"' . str_replace('"', '""', (string)$v) . '"'
            : (string)$v,
        $r
    ));
}
echo "\xEF\xBB\xBF" . implode("\r\n", $lines) . "\r\n";   // BOM — dla Excela
