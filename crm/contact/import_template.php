<?php
/**
 * crm/contact/import_template.php — Pobieranie szablonu CSV do importu kontaktów CRM.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';

require_login();

$rows = [
    // Nagłówek
    ['imie_nazwisko', 'email', 'telefon', 'organizacja', 'stanowisko', 'adres', 'nip', 'krs', 'regon', 'strona_www', 'notatka', 'status', 'tagi'],
    // Przykładowe wiersze
    ['Jan Kowalski',       'jan@example.com',     '+48123456789', '',                   'Koordynator',    'ul. Główna 1, Warszawa', '',           '',           '',          '',                    'Wolontariusz od 2024', 'aktywny', 'wolontariusz,projekt-a'],
    ['Fundacja Przykład',  'biuro@fundacja.pl',   '+48987654321', 'Fundacja Przykład',  'Prezes zarządu', 'ul. Kwiatowa 5, Kraków',  '0000000000', '0000000000', '000000000', 'https://fundacja.pl', 'Partner strategiczny', 'partner',  'partner,ngo'],
    ['Anna Nowak',         'anna.nowak@email.pl', '',             'Firma SP z o.o.',    'Specjalista',    '',                       '',           '',           '',          '',                    '',                     'prospect', ''],
];

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="crm_import_szablon_' . date('Y-m-d') . '.csv"');
header('Cache-Control: no-cache');

// BOM dla poprawnego otwarcia w Excel
echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'w');
foreach ($rows as $row) {
    fputcsv($out, $row, ',', '"');
}
fclose($out);
exit;
