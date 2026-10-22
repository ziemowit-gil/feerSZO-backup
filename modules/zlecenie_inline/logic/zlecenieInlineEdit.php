<?php
/**
 * modules/zlecenie_inline/logic/zlecenieInlineEdit.php
 * Edycja umowy zlecenie INLINE — w podglądzie (contracts/zlecenie/view.php, zakładka „Umowa”),
 * bez Tailwindu i bez przechodzenia do edit.php. Zapis idzie tą samą ścieżką co edit.php:
 * lista dozwolonych kolumn, diff do audytu, db_update, kontrola utrwalenia, log_contract_action.
 *
 * Jedyny edytor umowy zlecenie (osobny formularz edit.php przekierowuje tutaj): także status — z tymi
 * samymi regułami przejść co szybka zmiana w nagłówku (przejścia ze słownika umowy_statusy dla nie-adminów,
 * ContractStatusTransitionValidator dla wszystkich, blokada zamknięcia bez rozliczenia) — pliki umowy,
 * kartoteka osób, komórka, IS, M365 i dostęp (access_users).
 */

/** Grupy pól inline: sekcja → [pole => [etykieta, typ, opcje/attr]] — kolejność = kolejność w formularzu. */
function zlecenie_inline_fields(): array {
    return [
        'Dane podstawowe' => [
            'status'             => ['label' => 'Status', 'type' => 'select', 'options' => '__status__', 'required' => true],
            'numer_umowy'        => ['label' => 'Numer umowy', 'type' => 'text', 'required' => true, 'mono' => true],
            'data_zawarcia'      => ['label' => 'Data zawarcia', 'type' => 'date'],
            'data_rozpoczecia'   => ['label' => 'Od', 'type' => 'date'],
            'data_zakonczenia'   => ['label' => 'Do', 'type' => 'date'],
            'opiekun'            => ['label' => 'Opiekun', 'type' => 'text'],
            'numer_projektu'     => ['label' => 'Numer projektu', 'type' => 'text'],
            'przedmiot_zlecenia' => ['label' => 'Przedmiot zlecenia', 'type' => 'textarea', 'wide' => true, 'rows' => 3],
        ],
        'Zleceniobiorca' => [
            'person_id'        => ['label' => 'Wypełnij z kartoteki osób', 'type' => 'person', 'wide' => true],
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
            'org_unit_id'      => ['label' => 'Komórka organizacyjna', 'type' => 'select', 'options' => '__units__'],
        ],
        'Instytucja szkoleniowa (IS)' => [
            'w_ramach_is'       => ['label' => 'Umowa w ramach IS (instytucji szkoleniowej)', 'type' => 'checkbox'],
            'is_nazwa'          => ['label' => 'Nazwa instytucji szkoleniowej', 'type' => 'text'],
            'is_numer_umowy'    => ['label' => 'Numer umowy z IS', 'type' => 'text'],
            'is_uprawnienia_nr' => ['label' => 'Nr uprawnień', 'type' => 'text'],
            'is_dyplom_nr'      => ['label' => 'Nr dyplomu', 'type' => 'text'],
            'is_dopuszczenie'   => ['label' => 'Dopuszczenie', 'type' => 'text'],
            'is_adres'          => ['label' => 'Adres instytucji szkoleniowej', 'type' => 'textarea', 'wide' => true, 'rows' => 2],
            'klauzula_rodo'     => ['label' => 'Klauzula RODO', 'type' => 'checkbox'],
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
            'epodpis_dostawca'       => ['label' => 'Dostawca e-podpisu', 'type' => 'text'],
            'epodpis_nr_certyfikatu' => ['label' => 'Nr certyfikatu e-podpisu', 'type' => 'text'],
            'epodpis_data_waznosci'  => ['label' => 'Ważność certyfikatu', 'type' => 'date'],
            'plik_umowy'             => ['label' => 'Plik umowy (PDF, DOCX)', 'type' => 'file', 'accept' => '.pdf,.docx'],
            'plik_potwierdzenia'     => ['label' => 'Plik potwierdzenia (PDF)', 'type' => 'file', 'accept' => '.pdf'],
        ],
        'Microsoft 365' => [
            'm365_konto'   => ['label' => 'Konto M365', 'type' => 'checkbox'],
            'm365_login'   => ['label' => 'Login M365', 'type' => 'email'],
            'm365_user_id' => ['label' => 'ID użytkownika M365', 'type' => 'text', 'mono' => true],
        ],
        'Numery referencyjne' => [
            'nr_system'   => ['label' => 'Numer systemowy', 'type' => 'text', 'mono' => true],
            'nr_rejestru' => ['label' => 'Nr w rejestrze umów', 'type' => 'text', 'mono' => true],
        ],
        'Uwagi' => [
            'uwagi'                 => ['label' => 'Uwagi', 'type' => 'textarea', 'wide' => true, 'rows' => 3],
            'notatka_do_realizacji' => ['label' => 'Notatka do realizacji (wewnętrzna)', 'type' => 'textarea', 'wide' => true, 'rows' => 2],
        ],
        'Dostęp' => [
            'access_users' => ['label' => 'Kto ma dostęp do tej umowy', 'type' => 'access', 'wide' => true],
        ],
    ];
}

/** Płaska lista kolumn edytowanych inline (podzbiór $allowed z edit.php). */
function zlecenie_inline_columns(): array {
    $out = [];
    foreach (zlecenie_inline_fields() as $fields) {
        foreach ($fields as $col => $d) if ($d['type'] !== 'access') $out[] = $col;   // dostęp to osobna tabela
    }
    return $out;
}

/** Statusy do wyboru: obecny + dozwolone następne (jak szybka zmiana w nagłówku). */
function zlecenie_inline_status_options(string $current): array {
    $is_admin = (current_user()['role'] ?? '') === 'admin';
    $next = status_allowed_next($current, $is_admin, 'zlecenie');
    $out = [];
    foreach (contractStatusOptions('zlecenie', $current) + array_fill_keys($next, '') as $k => $v) {
        if ($k === 'aneks') continue;
        if ($k === $current || in_array($k, $next, true)) $out[$k] = contractStatusLabel($k);
    }
    return $out;
}

/**
 * Zapis z formularza inline. Zwraca ['errors' => string[], 'changed' => bool].
 * Wzorzec zapisu jak w dawnym contracts/zlecenie/edit.php — z plikami, statusem i dostępem.
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
        if ($def['type'] === 'file') {
            if (!empty($_FILES[$col]['tmp_name'])) {
                $up = handle_upload($col, 'zlecenie');
                if ($up) $data[$col] = $up; else $errors[] = 'Nie udało się wgrać pliku „' . $def['label'] . '” (dozwolone PDF/DOCX/JPG/PNG do 20 MB).';
            }
            continue;
        }
        if (in_array($col, ['person_id', 'org_unit_id'], true)) {
            if (array_key_exists($col, $post)) $data[$col] = ($post[$col] === '' ? null : (int)$post[$col]);
            continue;
        }
        if (!array_key_exists($col, $post)) continue;           // pole nieobecne w POST → nie ruszamy
        $v = trim((string)$post[$col]);
        if ($def['type'] === 'number') { $v = str_replace([',', ' '], ['.', ''], $v); $data[$col] = $v === '' ? null : $v; continue; }
        if ($col === 'ti_instructor_user_id') { $data[$col] = $v === '' ? null : (int)$v; continue; }
        $data[$col] = $v;
    }

    if (($data['numer_umowy'] ?? $row['numer_umowy']) === '')   $errors[] = 'Numer umowy jest wymagany.';
    // Status: te same reguły co szybka zmiana w nagłówku (api/ajax.php set_status) i edit.php.
    $old_status = (string)$row['status'];
    $new_status = (string)($data['status'] ?? $old_status);
    if ($new_status === '' || !contractStatusExists($new_status, 'zlecenie') || $new_status === 'aneks') {
        $errors[] = 'Wybierz poprawny status.';
    } elseif ($new_status !== $old_status) {
        require_once dirname(__DIR__, 3) . '/includes/contract_transitions.php';
        $is_admin = (current_user()['role'] ?? '') === 'admin';
        if (!$is_admin && !in_array($new_status, status_allowed_next($old_status, false, 'zlecenie'), true)) {
            $errors[] = 'Niedozwolona zmiana statusu: ' . contractStatusLabel($old_status) . ' → ' . contractStatusLabel($new_status) . '.';
        } else {
            try {
                ContractStatusTransitionValidator::assertAllowed($old_status, $new_status, array_merge($row, $data), 'zlecenie');
            } catch (\Throwable $e) {
                $errors[] = $e->getMessage();
            }
            if (in_array($new_status, ContractSettlementGuard::CLOSING_STATUSES, true)
                && ($why = ContractSettlementGuard::closeBlocker('zlecenie', array_merge($row, $data, ['id' => $id])))) {
                $errors[] = $why;
            }
        }
    }
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
    $errors = array_values(array_unique($errors));   // ta sama blokada zamknięcia bywa zgłaszana dwa razy
    if ($errors) return ['errors' => $errors, 'changed' => false];

    require_once dirname(__DIR__, 3) . '/includes/amendments.php';
    require_once dirname(__DIR__, 3) . '/includes/approval.php';

    $save = array_intersect_key($data, array_flip($cols));
    $diff = format_field_diff($row, $save);

    // Dostęp do umowy (osobna tabela) — tylko gdy pole było w formularzu.
    $acc_changed = false;
    if (isset($post['_zi_access'])) {
        require_once dirname(__DIR__, 3) . '/includes/contract_access.php';
        $before = contract_access_user_ids('zlecenie', $id);
        $after  = array_values(array_unique(array_map('intval', (array)($post['access_users'] ?? []))));
        sort($before); sort($after);
        if ($before !== $after) {
            contract_access_set('zlecenie', $id, $after, $uid);
            $acc_changed = true;
            log_contract_action('zlecenie', $id, $uid, 'edit', 'Zmieniono dostęp do umowy (inline)');
        }
    }
    if ($diff === '' || $diff === null) {
        return ['errors' => [], 'changed' => $acc_changed];
    }
    db_update('umowy_zlecenie', $save, $id);
    $missed = contract_assert_saved('umowy_zlecenie', $id, $save);
    if ($missed) {
        error_log('[zlecenie/inline] zapis nieutrwalony id=' . $id . ' pola=' . implode(',', $missed));
        return ['errors' => ['Zmiany NIE zostały zapisane w bazie (' . count($missed) . ' pól nie utrwalono). Zgłoś to administratorowi.'], 'changed' => false];
    }
    log_contract_action('zlecenie', $id, $uid, 'edit', $diff ?: 'Edytowano umowę (inline)');
    if (isset($save['status']) && $save['status'] !== $old_status) {
        if (ContractStatusTransitionValidator::isBlockedGroupExit($old_status, $save['status'])) {
            db_update('umowy_zlecenie', ['is_blocked' => 0, 'unblocked_by' => $uid, 'unblocked_at' => date('Y-m-d H:i:s')], $id);
            log_contract_action('zlecenie', $id, $uid, 'unblock', 'Odblokowano po potwierdzeniu zgody przedstawiciela ustawowego.');
        }
        log_contract_action('zlecenie', $id, $uid, 'status_change', 'Zmiana statusu: ' . $old_status . ' → ' . $save['status']);
    }
    return ['errors' => [], 'changed' => true];
}
