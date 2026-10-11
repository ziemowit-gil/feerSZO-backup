<?php
/**
 * modules/zlecenie_inline/logic/zlecenieInlineEdit.php
 * Edycja umowy zlecenie INLINE — w podglądzie (contracts/zlecenie/view.php, zakładka „Umowa”),
 * bez Tailwindu i bez przechodzenia do edit.php. Zapis idzie tą samą ścieżką co edit.php:
 * lista dozwolonych kolumn, diff do audytu, db_update, kontrola utrwalenia, log_contract_action.
 *
 * Status umowy NIE jest tu edytowany (jest szybka zmiana statusu w nagłówku z pełną walidacją przejść).
 * Pliki umowy i dostęp (access_users) też zostają w edit.php.
 */

/** Grupy pól inline: sekcja → [pole => [etykieta, typ, opcje/attr]] — kolejność = kolejność w formularzu. */
function zlecenie_inline_fields(): array {
    return [
        'Dane podstawowe' => [
            'numer_umowy'        => ['label' => 'Numer umowy', 'type' => 'text', 'required' => true, 'mono' => true],
            'data_zawarcia'      => ['label' => 'Data zawarcia', 'type' => 'date'],
            'data_rozpoczecia'   => ['label' => 'Od', 'type' => 'date'],
            'data_zakonczenia'   => ['label' => 'Do', 'type' => 'date'],
            'opiekun'            => ['label' => 'Opiekun', 'type' => 'text'],
            'numer_projektu'     => ['label' => 'Numer projektu', 'type' => 'text'],
            'przedmiot_zlecenia' => ['label' => 'Przedmiot zlecenia', 'type' => 'textarea', 'wide' => true, 'rows' => 3],
        ],
        'Zleceniobiorca' => [
            'imie_nazwisko'    => ['label' => 'Imię i nazwisko', 'type' => 'text', 'required' => true],
            'pesel'            => ['label' => 'PESEL', 'type' => 'text', 'mono' => true, 'maxlength' => 11, 'inputmode' => 'numeric'],
            'email'            => ['label' => 'E-mail', 'type' => 'email'],
            'seria_nr_dowodu'  => ['label' => 'Seria i nr dowodu', 'type' => 'text'],
            'urzad_skarbowy'   => ['label' => 'Urząd skarbowy', 'type' => 'text'],
            'rachunek_bankowy' => ['label' => 'Rachunek bankowy', 'type' => 'text', 'mono' => true, 'wide' => true],
            'addr_street'      => ['label' => 'Ulica', 'type' => 'text'],
            'addr_house'       => ['label' => 'Nr domu', 'type' => 'text', 'short' => true],
            'addr_flat'        => ['label' => 'Nr lokalu', 'type' => 'text', 'short' => true],
            'addr_postal'      => ['label' => 'Kod pocztowy', 'type' => 'text', 'short' => true],
            'addr_city'        => ['label' => 'Miejscowość', 'type' => 'text'],
            'addr_country'     => ['label' => 'Kraj', 'type' => 'text', 'short' => true],
        ],
        'Wynagrodzenie i podatek' => [
            'typ_stawki'              => ['label' => 'Typ stawki', 'type' => 'select', 'options' => ['' => '—', 'godzinowo' => 'Godzinowa', 'miesiecznie' => 'Miesięczna', 'ryczalt' => 'Ryczałt']],
            'stawka_kwota'            => ['label' => 'Kwota stawki (PLN)', 'type' => 'number', 'step' => '0.01'],
            'wynagrodzenie_brutto'    => ['label' => 'Wynagrodzenie brutto (PLN)', 'type' => 'number', 'step' => '0.01'],
            'liczba_godzin_planowana' => ['label' => 'Liczba godzin (plan)', 'type' => 'number', 'step' => '0.5'],
            'sposob_rozliczenia'      => ['label' => 'Sposób rozliczenia', 'type' => 'text'],
            'termin_platnosci'        => ['label' => 'Termin płatności', 'type' => 'text'],
            'zaliczka_podatek'        => ['label' => 'Zaliczka na podatek (PLN)', 'type' => 'number', 'step' => '0.01'],
            'kup'                     => ['label' => 'KUP', 'type' => 'select', 'options' => ['' => '—', '20' => '20 %', '50' => '50 %', 'brak' => 'Brak / standardowe']],
            'zwolnienie_wiek'         => ['label' => 'Zwolnienie (do 26 lat)', 'type' => 'checkbox'],
        ],
        'Rachunek' => [
            'wymagany_rachunek' => ['label' => 'Wymagany rachunek', 'type' => 'checkbox'],
            'data_zl_rachunku'  => ['label' => 'Data złożenia rachunku', 'type' => 'date'],
            'data_rachunku'     => ['label' => 'Data rachunku', 'type' => 'date'],
            'okres_rachunku'    => ['label' => 'Okres rachunku', 'type' => 'text'],
            'nr_roboczy'        => ['label' => 'Numer na rachunkach (roboczy)', 'type' => 'text', 'mono' => true],
        ],
        'Ewidencja godzin' => [
            'zrodlo_godzin'         => ['label' => 'Źródło godzin', 'type' => 'select', 'options' => '__zrodla__'],
            'ti_instructor_user_id' => ['label' => 'Prowadzący w e-dzienniku', 'type' => 'select', 'options' => '__users__'],
            'dydaktyka_udostepnij'  => ['label' => 'Dydaktyka — udostępnij dane do modułu', 'type' => 'checkbox'],
        ],
        'ZUS — rejestracja i wyrejestrowanie' => [
            'zus_skladki'              => ['label' => 'Składki ZUS', 'type' => 'checkbox'],
            'tytul_ubezpieczenia'      => ['label' => 'Tytuł ubezpieczenia', 'type' => 'text'],
            'zus_data_rejestracji'     => ['label' => 'Data rejestracji', 'type' => 'date'],
            'zus_data_wyrejestrowania' => ['label' => 'Data wyrejestrowania', 'type' => 'date'],
        ],
        'Podpisanie' => [
            'forma_podpisania'       => ['label' => 'Forma podpisania', 'type' => 'select', 'options' => ['' => '—', 'papierowa' => 'Papierowa', 'elektroniczna' => 'Elektroniczna', 'epodpis_kwalifikowany' => 'E-podpis kwalifikowany', 'mieszana' => 'Mieszana']],
            'platforma_el'           => ['label' => 'Platforma e-podpisu', 'type' => 'text'],
            'id_dokumentu_el'        => ['label' => 'ID dokumentu el.', 'type' => 'text'],
            'podpisujacy_fundacja'   => ['label' => 'Podpisujący (organizacja)', 'type' => 'text'],
            'podpisujacy_stanowisko' => ['label' => 'Stanowisko podpisującego', 'type' => 'text'],
        ],
        'Numery referencyjne' => [
            'nr_system'   => ['label' => 'Numer systemowy', 'type' => 'text', 'mono' => true],
            'nr_rejestru' => ['label' => 'Nr w rejestrze umów', 'type' => 'text', 'mono' => true],
        ],
        'Uwagi' => [
            'uwagi'                 => ['label' => 'Uwagi', 'type' => 'textarea', 'wide' => true, 'rows' => 3],
            'notatka_do_realizacji' => ['label' => 'Notatka do realizacji (wewnętrzna)', 'type' => 'textarea', 'wide' => true, 'rows' => 2],
        ],
    ];
}

/** Płaska lista kolumn edytowanych inline (podzbiór $allowed z edit.php). */
function zlecenie_inline_columns(): array {
    $out = [];
    foreach (zlecenie_inline_fields() as $fields) $out = array_merge($out, array_keys($fields));
    return $out;
}

/**
 * Zapis z formularza inline. Zwraca ['errors' => string[], 'changed' => bool].
 * Wzorzec zapisu 1:1 z contracts/zlecenie/edit.php (bez plików, statusu i dostępu).
 */
function zlecenie_inline_save(array $row, array $post, int $uid): array {
    $id     = (int)$row['id'];
    $errors = [];
    $data   = [];
    $cols   = zlecenie_inline_columns();
    $fields = array_merge(...array_values(zlecenie_inline_fields()));

    foreach ($cols as $col) {
        $def = $fields[$col];
        if ($def['type'] === 'checkbox') { $data[$col] = isset($post[$col]) ? 1 : 0; continue; }
        if (!array_key_exists($col, $post)) continue;           // pole nieobecne w POST → nie ruszamy
        $v = trim((string)$post[$col]);
        if ($def['type'] === 'number') { $v = str_replace([',', ' '], ['.', ''], $v); $data[$col] = $v === '' ? null : $v; continue; }
        if ($col === 'ti_instructor_user_id') { $data[$col] = $v === '' ? null : (int)$v; continue; }
        $data[$col] = $v;
    }

    if (($data['numer_umowy'] ?? $row['numer_umowy']) === '')   $errors[] = 'Numer umowy jest wymagany.';
    if (($data['imie_nazwisko'] ?? $row['imie_nazwisko']) === '') $errors[] = 'Imię i nazwisko jest wymagane.';
    foreach (['data_zawarcia', 'data_rozpoczecia', 'data_zakonczenia', 'zus_data_rejestracji', 'zus_data_wyrejestrowania', 'data_zl_rachunku', 'data_rachunku'] as $d) {
        if (!empty($data[$d]) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data[$d])) $errors[] = 'Nieprawidłowa data w polu „' . $fields[$d]['label'] . '”.';
    }
    if (!empty($data['data_rozpoczecia']) && !empty($data['data_zakonczenia']) && $data['data_zakonczenia'] < $data['data_rozpoczecia']) {
        $errors[] = 'Data zakończenia nie może być wcześniejsza niż data rozpoczęcia.';
    }
    if (!empty($data['pesel']) && !preg_match('/^\d{11}$/', $data['pesel'])) $errors[] = 'PESEL musi mieć 11 cyfr.';
    if (($data['zrodlo_godzin'] ?? '') === 'karty30' && empty($data['ti_instructor_user_id'])) {
        $errors[] = 'Dla źródła „Dydaktyka – Karty30 dziennik” wskaż prowadzącego.';
    }
    // Prowadzący zostaje przy źródle „Karty30” albo przy zaznaczonej dydaktyce (powiązanie konta z umową).
    if (($data['zrodlo_godzin'] ?? '') !== 'karty30' && empty($data['dydaktyka_udostepnij'])) $data['ti_instructor_user_id'] = null;
    if ($errors) return ['errors' => $errors, 'changed' => false];

    require_once dirname(__DIR__, 3) . '/includes/amendments.php';
    require_once dirname(__DIR__, 3) . '/includes/approval.php';

    $save = array_intersect_key($data, array_flip($cols));
    $diff = format_field_diff($row, $save);
    if ($diff === '' || $diff === null) {
        return ['errors' => [], 'changed' => false];
    }
    db_update('umowy_zlecenie', $save, $id);
    $missed = contract_assert_saved('umowy_zlecenie', $id, $save);
    if ($missed) {
        error_log('[zlecenie/inline] zapis nieutrwalony id=' . $id . ' pola=' . implode(',', $missed));
        return ['errors' => ['Zmiany NIE zostały zapisane w bazie (' . count($missed) . ' pól nie utrwalono). Zgłoś to administratorowi.'], 'changed' => false];
    }
    log_contract_action('zlecenie', $id, $uid, 'edit', $diff ?: 'Edytowano umowę (inline)');
    return ['errors' => [], 'changed' => true];
}
