<?php
/**
 * modules/quick_dates — szybki wybór daty przy polach <input type="date">: „+7 dni”, „+14 dni”, „Za miesiąc”.
 *
 * Funkcjonalność wspólna (nie tylko Zadania): wystarczy wczytać zasoby i oznaczyć pole atrybutem
 *   <input type="date" data-quick-dates>          — przyciski pod polem,
 *   <input type="date" data-quick-dates="parent"> — przyciski pod rodzicem pola (pole w jednym wierszu z innym, np. godziną).
 * Skrypt obsługuje też pola wstawione później (panel zadania wczytywany fetch-em), patrz view/quickDates.js.
 */

function quickDatesAssets(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $base = rtrim((string)APP_URL, '/') . '/modules/quick_dates/view/';
    $dir  = dirname(__DIR__) . '/view/';
    $ver  = static fn(string $f): int => (int)@filemtime($dir . $f);
    echo '<link rel="stylesheet" href="' . $base . 'quickDates.css?v=' . $ver('quickDates.css') . '">' . "\n";
    echo '<script src="' . $base . 'quickDates.js?v=' . $ver('quickDates.js') . '"></script>' . "\n";
}
