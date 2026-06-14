<?php
/**
 * includes/ksiegowy_email.php
 * Treść bloku e-mail do księgowego z danymi potrzebnymi do wystawienia
 * rachunku (umowy zlecenie). Pola wypełniane są danymi z umowy.
 *
 * Pola, których umowa nie zawiera (data rachunku, okres rozliczeniowy),
 * pozostają puste do ręcznego uzupełnienia. Używane zarówno na widoku umowy
 * (kopiowanie do schowka), jak i na wydruku PDF.
 *
 * Wymaga wcześniejszego załadowania includes/functions.php (date_pl, money).
 */
if (!function_exists('ksiegowy_rachunek_email_text')) {
    function ksiegowy_rachunek_email_text(array $row = []): string
    {
        $name  = trim((string)($row['imie_nazwisko'] ?? ''));
        $dataU = !empty($row['data_zawarcia']) ? date_pl($row['data_zawarcia']) : '';
        $kwota = (isset($row['wynagrodzenie_brutto']) && $row['wynagrodzenie_brutto'] !== '' && $row['wynagrodzenie_brutto'] !== null)
               ? money((float)$row['wynagrodzenie_brutto']) . ' (brutto)' : '';
        $godz  = trim((string)($row['liczba_godzin_planowana'] ?? ''));

        return "Dzień dobry,\n"
             . "poniżej przesyłam dane do wystawienia rachunku:\n"
             . "- dla kogo rachunek: {$name}\n"
             . "- data umowy: {$dataU}\n"
             . "- data rachunku: \n"
             . "- za jaki okres jest rachunek: \n"
             . "- kwota brutto lub netto: {$kwota}\n"
             . "- ilość przepracowanych godzin: {$godz}\n"
             . "Pozdrawiam";
    }
}
