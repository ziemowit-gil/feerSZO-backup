<?php
/**
 * modules/contract_card/logic/contractCard.php — dane karty umowy: pola wiersza umowy rozłożone na sekcje
 * formularza (A–G), z etykietami, formatowaniem i numeracją pól jak w urzędowym druku.
 *
 * Działa dla każdego typu umowy (umowy_{typ}) bez osobnej konfiguracji: kolumny przypisuje do sekcji
 * po nazwie (contractCardSectionOf), etykiety bierze z FIELD_LABELS (includes/amendments.php) albo z mapy
 * poniżej, a resztę „uczłowiecza” z nazwy kolumny. Kolumny techniczne (identyfikatory, tokeny, M365,
 * dane RPTS, flagi synchronizacji) nie trafiają na kartę.
 */

require_once dirname(__DIR__, 3) . '/includes/amendments.php';   // FIELD_LABELS

const CONTRACT_CARD_SECTIONS = [
    'A' => 'Identyfikacja umowy',
    'B' => 'Druga strona umowy',
    'C' => 'Przedmiot i zakres umowy',
    'D' => 'Terminy',
    'E' => 'Wynagrodzenie i rozliczenia',
    'F' => 'Zawarcie umowy i pliki',
    'G' => 'Informacje dodatkowe',
];

/** Etykiety kolumn spoza FIELD_LABELS. */
const CONTRACT_CARD_LABELS = [
    'nr_rejestru' => 'Nr w Rejestrze Umów', 'nr_roboczy' => 'Nr roboczy', 'nr_system' => 'Nr w systemie',
    'addr_street' => 'Ulica', 'addr_house' => 'Nr domu', 'addr_flat' => 'Nr lokalu', 'addr_postal' => 'Kod pocztowy',
    'addr_city' => 'Miejscowość', 'addr_country' => 'Kraj', 'email' => 'Adres e-mail', 'telefon' => 'Telefon',
    'guardian_initials' => 'Przedstawiciel ustawowy (inicjały)', 'rodzic_email' => 'E-mail przedstawiciela ustawowego',
    'organ_zlecajacy' => 'Organ zlecający', 'organ_reprezentacja' => 'Reprezentacja organu', 'organ_adres' => 'Adres organu',
    'nazwa_zadania' => 'Nazwa zadania publicznego', 'sfera_zadania' => 'Sfera pożytku publicznego',
    'forma_zlecenia' => 'Forma zlecenia', 'tryb_zlecenia' => 'Tryb zlecenia', 'nazwa_konkursu' => 'Nr / nazwa konkursu',
    'zakres_rzeczowy' => 'Zakres rzeczowy', 'rezultaty' => 'Rezultaty', 'kwota_dotacji' => 'Kwota dotacji',
    'wklad_wlasny' => 'Wkład własny finansowy', 'wklad_osobowy' => 'Wkład osobowy', 'calkowity_koszt' => 'Całkowity koszt zadania',
    'rachunek_dotacji' => 'Rachunek dotacji', 'rachunek_dotacji_rodzaj' => 'Rodzaj rachunku dotacji', 'transze' => 'Transze', 'koszty_kwalifikowane' => 'Koszty kwalifikowane',
    'termin_wykorzystania' => 'Termin wykorzystania dotacji', 'termin_sprawozdania' => 'Termin sprawozdania końcowego',
    'stawka_kwota' => 'Stawka', 'typ_stawki' => 'Typ stawki', 'liczba_godzin_planowana' => 'Planowana liczba godzin',
    'sposob_rozliczenia' => 'Sposób rozliczenia', 'termin_platnosci' => 'Termin płatności', 'zus_skladki' => 'Składki ZUS',
    'tytul_ubezpieczenia' => 'Tytuł ubezpieczenia', 'zwolnienie_wiek' => 'Zwolnienie z PIT (do 26 lat)',
    'zaliczka_podatek' => 'Zaliczka na podatek', 'kup' => 'Koszty uzyskania przychodu', 'wymagany_rachunek' => 'Wymagany rachunek',
    'data_zl_rachunku' => 'Data złożenia rachunku', 'data_rachunku' => 'Data rachunku', 'okres_rachunku' => 'Okres rachunku',
    'zus_data_rejestracji' => 'Zgłoszenie do ZUS', 'zus_data_wyrejestrowania' => 'Wyrejestrowanie z ZUS',
    'forma_podpisania' => 'Forma podpisania', 'platforma_el' => 'Platforma podpisu elektronicznego',
    'id_dokumentu_el' => 'Identyfikator dokumentu elektronicznego', 'epodpis_dostawca' => 'Dostawca e-podpisu',
    'epodpis_nr_certyfikatu' => 'Nr certyfikatu e-podpisu', 'epodpis_data_waznosci' => 'Ważność certyfikatu',
    'podpisujacy_fundacja' => 'Podpisujący w imieniu Fundacji', 'podpisujacy_stanowisko' => 'Stanowisko podpisującego',
    'plik_umowy' => 'Plik umowy', 'plik_potwierdzenia' => 'Plik potwierdzenia', 'zalaczniki' => 'Załączniki',
    'plik_projekt' => 'Projekt umowy', 'plik_jednostronna' => 'Umowa podpisana jednostronnie',
    'docusign_status' => 'Status DocuSign', 'autenti_status' => 'Status Autenti', 'bezterminowa' => 'Umowa bezterminowa',
    'w_ramach_is' => 'W ramach innego podmiotu', 'is_nazwa' => 'Podmiot — nazwa', 'is_adres' => 'Podmiot — adres',
    'is_numer_umowy' => 'Podmiot — nr umowy', 'klauzula_rodo' => 'Klauzula RODO', 'notatka_do_realizacji' => 'Notatka do realizacji',
    'zrodlo_godzin' => 'Źródło godzin', 'rozliczenie_okres' => 'Okres rozliczenia', 'waluta' => 'Waluta',
];

/** Kolumny poza kartą: techniczne, integracyjne, wrażliwe (RPTS) lub obsłużone w nagłówku. */
function contractCardHidden(string $c): bool {
    if (in_array($c, ['id', 'access_level', 'status', 'numer_umowy', 'nr_rejestru', 'created_by', 'created_at', 'updated_at',
                      'needs_correction', 'correction_reason', 'dydaktyka_udostepnij', 'is_blocked', 'pre_block_status',
                      'ostatnia_synchronizacja_at', 'guardian_editor_id', 'docusign_signer_email', 'autenti_signer_email'], true)) return true;
    return (bool)preg_match('/(_id$|^m365_|password|token|hash|secret|rpts|^correction_|^unblocked_|_signer_name$|^ezd_)/', $c);
}

function contractCardLabel(string $c): string {
    $l = CONTRACT_CARD_LABELS[$c] ?? (defined('FIELD_LABELS') ? (FIELD_LABELS[$c] ?? '') : '');
    return $l !== '' ? $l : mb_strtoupper(mb_substr(str_replace('_', ' ', $c), 0, 1)) . mb_substr(str_replace('_', ' ', $c), 1);
}

function contractCardSectionOf(string $c): string {
    return match (true) {
        (bool)preg_match('/^(nr_|numer_projektu|opiekun|podpisujacy_|forma_zlecenia|tryb_zlecenia|nazwa_konkursu)/', $c) => 'A',
        (bool)preg_match('/(imie|nazwisk|pesel|dowod|^adres|^addr_|^email|telefon|urzad_skarbowy|rachunek_bankowy|guardian|rodzic|opiekun_prawny|^organ_|^nazwa_firmy|^nazwa_kontrahenta|^kontrahent|^nip|^regon|^krs|data_urodzenia|^w_ramach_is|^is_)/', $c) => 'B',
        (bool)preg_match('/^(data_|termin_|bezterminowa|okres)/', $c) && !preg_match('/rachunk/', $c) => 'D',
        (bool)preg_match('/(wynagrodz|kwota|stawk|wklad|koszt|zaliczk|^kup$|^zus|ubezpiecz|zwolnienie|liczba_godzin|godzin|rozlicz|rachunk|transze|dotacj|waluta|platnosc|brutto|netto)/', $c) => 'E',
        (bool)preg_match('/^(plik_|zalacznik|forma_podpisania|platforma_el|id_dokumentu_el|epodpis_|docusign|autenti|klauzula)/', $c) => 'F',
        (bool)preg_match('/(przedmiot|opis|zakres|nazwa_zadania|sfera|rezultat|stanowisk|wymiar|miejsce)/', $c) => 'C',
        default => 'G',
    };
}

/** Wartość do druku: [tekst, rodzaj] — rodzaj: text | long | check | money | date | file. */
function contractCardValue(string $c, $v): array {
    $v = $v === null ? '' : (string)$v;
    if (preg_match('/^(plik_|zalacznik)/', $c)) return [$v === '' ? '' : basename($v), 'file'];
    if (preg_match('/^(data_|termin_|.*_data_|.*_at$)/', $c) && preg_match('/^\d{4}-\d{2}-\d{2}/', $v)) {
        return [date('d.m.Y', strtotime(substr($v, 0, 10))), 'date'];
    }
    if (preg_match('/(wynagrodz|kwota|wklad|koszt|brutto|netto|stawka_kwota|zaliczka_podatek)/', $c) && is_numeric($v)) {
        return [number_format((float)$v, 2, ',', ' '), 'money'];
    }
    if (($v === '0' || $v === '1') && preg_match('/^(bezterminowa|zwolnienie|wymagany|w_ramach|klauzula|is_|.*_aktywne)/', $c)) return [$v, 'check'];
    if ($c === 'rachunek_dotacji_rodzaj' && $v !== '') return [['wyodrebniony' => 'Wyodrębniony rachunek dotacji', 'ogolny' => 'Ogólny rachunek Fundacji'][$v] ?? $v, 'text'];
    if ($c === 'forma_zlecenia' && $v !== '') return [['powierzenie' => 'Powierzenie (100% dotacji)', 'wsparcie' => 'Wsparcie (z wkładem własnym)'][$v] ?? $v, 'text'];
    $long = mb_strlen($v) > 70 || str_contains($v, "\n") || preg_match('/(przedmiot|opis|zakres|uwagi|rezultat|transze|koszty_kwal|notatka|adres$)/', $c);
    return [$v, $long ? 'long' : 'text'];
}

function contractCardRow(string $type, int $id): ?array {
    try { return db_one('SELECT * FROM ' . table_for_type($type) . ' WHERE id=?', [$id]) ?: null; }
    catch (\Throwable $e) { return null; }
}

/** Nagłówek druku: pola 1–5 oraz metryka (kto i kiedy sporządził umowę w systemie, koszulka EZD). */
function contractCardHead(string $type, array $row): array {
    $od = $row['data_rozpoczecia'] ?? ($row['data_od'] ?? ($row['data_zawarcia'] ?? ''));
    $do = $row['data_zakonczenia'] ?? ($row['data_do'] ?? ($row['termin_oddania'] ?? ''));
    $author = !empty($row['created_by']) ? (db_one('SELECT name FROM users WHERE id=?', [(int)$row['created_by']])['name'] ?? '') : '';
    $ezd = '';
    try {
        require_once dirname(__DIR__, 3) . '/includes/contract_ezd.php';
        $s = contract_ezd_get_sprawa($type, (int)$row['id']);
        if ($s) $ezd = (string)($s['znak_sprawy'] ?? '');
    } catch (\Throwable $e) {}
    return [
        'numer'   => (string)($row['numer_umowy'] ?? ''),
        'rejestr' => (string)($row['nr_rejestru'] ?? ''),
        'status'  => STATUS_LABELS[$row['status'] ?? '']['label'] ?? (string)($row['status'] ?? ''),
        'od'      => $od ? date('d.m.Y', strtotime((string)$od)) : '',
        'do'      => $do ? date('d.m.Y', strtotime((string)$do)) : (!empty($row['bezterminowa']) ? 'bezterminowo' : ''),
        'rodzaj'  => CONTRACT_TYPES[$type] ?? $type,
        'autor'   => $author,
        'utw'     => !empty($row['created_at']) ? date('d.m.Y H:i', strtotime((string)$row['created_at'])) : '',
        'zmiana'  => !empty($row['updated_at']) ? date('d.m.Y H:i', strtotime((string)$row['updated_at'])) : '',
        'ezd'     => $ezd,
    ];
}

/**
 * Sekcje karty: ['A' => [[nr, etykieta, wartość, rodzaj], …], …]. Numeracja pól ciągła od 6 (1–5 w nagłówku).
 * W sekcji G („Informacje dodatkowe”) tylko pola wypełnione — reszta pokazuje też puste ramki, jak druk.
 */
function contractCardSections(string $type, array $row): array {
    $out = array_fill_keys(array_keys(CONTRACT_CARD_SECTIONS), []);
    foreach ($row as $c => $v) {
        if (contractCardHidden((string)$c)) continue;
        $sec = contractCardSectionOf((string)$c);
        [$text, $kind] = contractCardValue((string)$c, $v);
        if ($sec === 'G' && trim($text) === '') continue;
        $out[$sec][] = ['', contractCardLabel((string)$c), $text, $kind];
    }
    $n = 6;
    foreach ($out as $k => &$fields) {
        // długie pola na końcu sekcji (pełna szerokość), krótkie razem w siatce
        usort($fields, fn($a, $b) => ($a[3] === 'long') <=> ($b[3] === 'long'));
        foreach ($fields as &$f) $f[0] = $n++;
        unset($f);
    }
    unset($fields);
    return array_filter($out);
}

/** Ostatnie wpisy dziennika zmian umowy (sekcja H). */
function contractCardHistory(string $type, int $id): array {
    try {
        return db_all("SELECT created_at, user_snapshot, action, note FROM contract_audit_log
                       WHERE contract_type=? AND contract_id=? ORDER BY id DESC LIMIT 12", [$type, $id]);
    } catch (\Throwable $e) { return []; }
}
