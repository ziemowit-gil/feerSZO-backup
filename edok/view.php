<?php
require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/edok.php';

edok_require_access();
edok_migrate();

$id  = (int)($_GET['id'] ?? 0);
$doc = edok_get($id);
if (!$doc) { http_response_code(404); die('Dokument nie istnieje.'); }

$user   = current_user();
$errors = [];

$locked_meryt      = in_array($doc['steps']['meryt']['status']      ?? null, ['ok', 'uwagi', 'odrzucono'], true);
$locked_formal     = in_array($doc['steps']['formal']['status']     ?? null, ['ok', 'uwagi', 'odrzucono'], true);
$locked_rachunkowa = in_array($doc['steps']['rachunkowa']['status'] ?? null, ['ok', 'uwagi', 'odrzucono'], true);
$locked_dekretacja = in_array($doc['steps']['dekretacja']['status'] ?? null, ['ok', 'uwagi', 'odrzucono'], true);
$is_terminal       = in_array($doc['status'], ['zaakceptowany', 'odrzucony', 'wycofany'], true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    // Edycja danych dokumentu — pola blokują się jedna po drugiej wraz z postępem obiegu,
    // żeby nie dało się zmienić danych na etapie już zdecydowanym (integralność decyzji).
    // Pozycje listy płac — edycja jak danych dokumentu, blokowana razem z kwotami (etap „rachunkowa”).
    $can_edit_wyplaty = $doc['typ_dokumentu'] === 'lista_plac' && !$is_terminal && !$locked_rachunkowa
        && (is_admin() || edok_has_role('upload') || edok_has_role('dekretacja'));
    if (in_array($action, ['wyplata_add', 'wyplata_delete', 'wyplaty_z_rachunkow'], true)) {
        if (!$can_edit_wyplaty) {
            flash_set('danger', 'Pozycji listy płac nie można już zmieniać.');
        } elseif ($action === 'wyplata_add') {
            [$wid, $werr] = edok_wyplata_add($id, $_POST);
            if ($wid) {
                edok_log($id, 'edit', '', $doc['status'], $doc['status'], 'Lista płac: dodano wypłatę — ' . trim($_POST['osoba'] ?? '') . ', ' . trim($_POST['kwota'] ?? '') . ' PLN.');
                flash_set('success', 'Dodano pozycję listy płac.');
            } else {
                flash_set('danger', implode(' ', $werr));
            }
        } elseif ($action === 'wyplata_delete') {
            $w = db_one("SELECT * FROM edok_wyplaty WHERE id=? AND doc_id=?", [(int)($_POST['wyplata_id'] ?? 0), $id]);
            if ($w) {
                edok_wyplata_delete((int)$w['id'], $id);
                edok_log($id, 'edit', '', $doc['status'], $doc['status'], 'Lista płac: usunięto wypłatę — ' . $w['osoba'] . ', ' . $w['kwota'] . ' PLN.');
                flash_set('success', 'Usunięto pozycję listy płac.');
            }
        } else {
            [$n, $werr] = edok_wyplaty_z_rachunkow($doc, (array)($_POST['rachunek_ids'] ?? []));
            if ($n) edok_log($id, 'edit', '', $doc['status'], $doc['status'], "Lista płac: ujęto rachunki do umów zlecenie ($n).");
            if ($werr) flash_set('warning', implode(' ', $werr));
            if ($n) flash_set('success', "Dodano $n wypłat z rachunków do umów.");
        }
        // Tytuł listy płac nie zależy od pozycji, ale kwota brutto dokumentu powinna się zgadzać z sumą — patrz ostrzeżenie w sekcji „Wypłaty”.
        header('Location: ' . APP_URL . '/edok/view.php?id=' . $id . '#wyplaty');
        exit;
    }

    if (in_array($action, ['bank_link', 'bank_unlink'], true) && (is_admin() || edok_has_role('upload') || edok_has_role('ksiegowy'))) {
        require_once __DIR__ . '/../includes/edok_bank.php';
        $err = $action === 'bank_link' ? edok_bank_assign((int)($_POST['tx_id'] ?? 0), $id) : edok_bank_unlink_from_doc((int)($_POST['tx_id'] ?? 0), $id);
        flash_set($err ? 'danger' : 'success', $err ?: ($action === 'bank_link' ? 'Powiązano transakcję z wyciągu z dokumentem.' : 'Odpięto transakcję.'));
        header('Location: ' . APP_URL . '/edok/view.php?id=' . $id);
        exit;
    }

    if ($action === 'update_meta' && (is_admin() || edok_has_role('upload') || edok_has_role('dekretacja')) && !$is_terminal) {
        $description      = $locked_meryt      ? $doc['description']      : trim($_POST['description'] ?? '');
        $kontrahent_nazwa = $locked_formal      ? $doc['kontrahent_nazwa'] : trim($_POST['kontrahent_nazwa'] ?? '');
        $kontrahent_nip   = $locked_formal      ? $doc['kontrahent_nip']   : preg_replace('/\D/', '', trim($_POST['kontrahent_nip'] ?? ''));
        $nr_faktury       = $locked_formal      ? $doc['nr_faktury']       : trim($_POST['nr_faktury'] ?? '');
        $zrodlo_przychodu = $locked_formal      ? $doc['zrodlo_przychodu'] : trim($_POST['zrodlo_przychodu'] ?? '');
        $kwota_netto      = $locked_rachunkowa  ? $doc['kwota_netto']      : trim($_POST['kwota_netto'] ?? '');
        $kwota_vat        = $locked_rachunkowa  ? $doc['kwota_vat']        : trim($_POST['kwota_vat'] ?? '');
        $kwota_brutto     = $locked_rachunkowa  ? $doc['kwota_brutto']     : trim($_POST['kwota_brutto'] ?? '');
        $rodzaj           = $locked_dekretacja  ? $doc['rodzaj_dzialalnosci'] : ($_POST['rodzaj_dzialalnosci'] ?? '');
        if (!isset(EDOK_RODZAJ_DZIALALNOSCI[$rodzaj])) $rodzaj = $locked_dekretacja ? $doc['rodzaj_dzialalnosci'] : '';
        $projekt          = $locked_dekretacja  ? $doc['projekt']         : trim($_POST['projekt'] ?? '');
        $mpk              = trim($_POST['mpk'] ?? '');
        $tytul_przelewu   = trim($_POST['tytul_przelewu'] ?? '');
        // Wynagrodzenia: okres, nr umowy z Rejestru Umów, kwota do wypłaty (po potrąceniach).
        $jest_wynagrodzenie = in_array($doc['typ_dokumentu'], ['rachunek', 'lista_plac'], true) && $doc['kierunek'] === 'wydatek';
        $okres            = $jest_wynagrodzenie && !$locked_rachunkowa && preg_match('/^\d{4}-\d{2}$/', $_POST['okres'] ?? '') ? $_POST['okres'] : ($jest_wynagrodzenie && !$locked_rachunkowa && ($_POST['okres'] ?? null) === '' ? '' : $doc['okres']);
        $umowa_numer      = $jest_wynagrodzenie && !$locked_rachunkowa ? trim($_POST['umowa_numer'] ?? $doc['umowa_numer']) : $doc['umowa_numer'];
        $kwota_do_wyplaty = $doc['typ_dokumentu'] === 'rachunek' && !$locked_rachunkowa ? trim($_POST['kwota_do_wyplaty'] ?? '') : $doc['kwota_do_wyplaty'];
        if ($kwota_do_wyplaty !== '' && _edok_kwota_float($kwota_do_wyplaty) <= 0) $kwota_do_wyplaty = '';
        if ($umowa_numer !== $doc['umowa_numer'] && $umowa_numer !== '') {
            foreach (edok_umowy_do_wyplat() as $u) if ($u['nr_rejestru'] === $umowa_numer) {
                db_exec("UPDATE edok_documents SET contract_type=?, contract_id=? WHERE id=?", [$u['contract_type'], (int)$u['id'], $id]);
                break;
            }
        }
        // Tytuł niezmieniony ręcznie (= zapisany wcześniej) przeliczamy dla wynagrodzeń na bieżąco.
        if ($jest_wynagrodzenie && $tytul_przelewu === $doc['tytul_przelewu']) $tytul_przelewu = '';
        // Zapłata / proforma blokują się razem z kwotami (po etapie „rachunkowa”).
        $zaplata_keys = ['zaplacono_przed', 'data_zaplaty', 'forma_zaplaty', 'zaplacil', 'zwrot_osoba', 'zwrot_rachunek'];
        if ($locked_rachunkowa || $doc['kierunek'] !== 'wydatek') {
            $proforma_id = $doc['proforma_id'] !== null ? (int)$doc['proforma_id'] : null;
            $zaplata = array_intersect_key($doc, array_flip($zaplata_keys));
            $zaplata_errors = [];
        } else {
            [$proforma_id, $zaplata_errors] = edok_proforma_from_post($_POST, $doc['typ_dokumentu'], $id);
            [$zaplata, $e2] = edok_zaplata_from_post($_POST, $proforma_id);
            $zaplata_errors = array_merge($zaplata_errors, $e2);
            if (!$zaplata_errors) {
                [$dowod, $e3] = edok_dowod_zaplaty_upload($zaplata, (string)$doc['dowod_zaplaty_path']);
                $zaplata['dowod_zaplaty_path'] = $dowod;
                $zaplata_errors = array_merge($zaplata_errors, $e3);
            }
        }
        if (!array_key_exists('dowod_zaplaty_path', $zaplata)) $zaplata['dowod_zaplaty_path'] = (string)$doc['dowod_zaplaty_path'];
        if ($zaplata_errors) {
            flash_set('danger', implode(' ', $zaplata_errors));
            header('Location: ' . APP_URL . '/edok/view.php?id=' . $id);
            exit;
        }
        if ($tytul_przelewu === '') {
            $tytul_przelewu = edok_generate_tytul_przelewu([
                'typ_dokumentu'    => $doc['typ_dokumentu'],
                'okres'            => $okres,
                'umowa_numer'      => $umowa_numer,
                'kwota_do_wyplaty' => $kwota_do_wyplaty,
                'nr_faktury'       => $nr_faktury,
                'number'           => $doc['number'],
                'data_wystawienia' => $doc['data_wystawienia'],
                'description'      => $description,
                'kierunek'         => $doc['kierunek'],
                'kwota_brutto'     => $kwota_brutto,
                'waluta'           => $doc['waluta'],
            ]);
        }

        db_exec(
            "UPDATE edok_documents SET description=?, kontrahent_nazwa=?, kontrahent_nip=?, nr_faktury=?, zrodlo_przychodu=?,
                kwota_netto=?, kwota_vat=?, kwota_brutto=?, rodzaj_dzialalnosci=?, projekt=?, mpk=?, tytul_przelewu=?,
                zaplacono_przed=?, data_zaplaty=?, forma_zaplaty=?, zaplacil=?, zwrot_osoba=?, zwrot_rachunek=?, proforma_id=?, dowod_zaplaty_path=?, okres=?, umowa_numer=?, kwota_do_wyplaty=?, updated_at=datetime('now')
             WHERE id=?",
            [$description, $kontrahent_nazwa, $kontrahent_nip, $nr_faktury, $zrodlo_przychodu, $kwota_netto, $kwota_vat, $kwota_brutto, $rodzaj, $projekt, $mpk, $tytul_przelewu,
             (int)$zaplata['zaplacono_przed'], $zaplata['data_zaplaty'], $zaplata['forma_zaplaty'], $zaplata['zaplacil'], $zaplata['zwrot_osoba'], $zaplata['zwrot_rachunek'], $proforma_id, $zaplata['dowod_zaplaty_path'], $okres, $umowa_numer, $kwota_do_wyplaty, $id]
        );
        $nowa_zaplata = $zaplata + ['proforma_id' => $proforma_id];
        $zmiana_zaplaty = edok_zaplata_opis($nowa_zaplata) !== edok_zaplata_opis($doc) || (int)$proforma_id !== (int)$doc['proforma_id'];
        edok_log($id, 'edit', '', $doc['status'], $doc['status'], 'Zaktualizowano dane dokumentu.'
            . ($zmiana_zaplaty ? ' Zapłacono przed akceptacją: ' . (edok_zaplata_opis($nowa_zaplata) ?: 'nie') . '.' : '')
            . ($zaplata['dowod_zaplaty_path'] !== (string)$doc['dowod_zaplaty_path'] ? ' Dołączono dowód zapłaty.' : ''));
        flash_set('success', 'Dane zaktualizowane.');
        header('Location: ' . APP_URL . '/edok/view.php?id=' . $id);
        exit;
    }

    // Weryfikuj i podpisz wszystkie etapy — kolejne etapy z rolą użytkownika, jeden PIN
    if ($action === 'sign_all') {
        if ($is_terminal) {
            $errors[] = 'Dokument jest już zamknięty — decyzja jest zablokowana.';
        } elseif (empty($_POST['sign_all_confirm'])) {
            $errors[] = 'Potwierdź, że zweryfikowałeś(-aś) dokument dla wszystkich podpisywanych etapów.';
        } else {
            $res = edok_sign_all($id, (int)$user['id'], trim($_POST['step_pin'] ?? ''), trim($_POST['step_notes'] ?? ''));
            if ($res['error'] !== null) {
                $errors[] = $res['error'];
            } else {
                $msg = 'Zaakceptowano i podpisano PIN-em: ' . implode(' → ', $res['signed']) . '.';
                if ($res['stopped']) $msg .= ' Zatrzymano: ' . $res['stopped'];
                flash_set($res['stopped'] ? 'warning' : 'success', $msg);
                header('Location: ' . APP_URL . '/edok/view.php?id=' . $id);
                exit;
            }
        }
    }

    // Krok akceptacji: meryt / formal / rachunkowa / dekretacja / zatwierdza
    if (in_array($action, edok_step_order(), true)) {
        edok_require_role($action);

        if ($is_terminal) {
            $errors[] = 'Dokument jest już ' . ($doc['status'] === 'zaakceptowany' ? 'zaakceptowany' : ($doc['status'] === 'wycofany' ? 'wycofany' : 'odrzucony')) . ' — decyzja jest zablokowana.';
        }

        $existing_step = $doc['steps'][$action] ?? null;
        if (!$errors && $existing_step && in_array($existing_step['status'], ['ok', 'uwagi', 'odrzucono'], true)) {
            $errors[] = 'Decyzja dla tego etapu została już podjęta i nie może być zmieniona.';
        }

        if (!$errors) {
            $blocked = edok_step_blocked_reason($doc, $action);
            if ($blocked) $errors[] = $blocked;
        }

        $status = $_POST['step_status'] ?? '';
        $notes  = trim($_POST['step_notes'] ?? '');
        $pin    = trim($_POST['step_pin'] ?? '');
        $pin_verified = false;

        if (!$errors) {
            if (!in_array($status, ['ok', 'uwagi', 'odrzucono'], true)) {
                $errors[] = 'Wybierz decyzję.';
            } elseif ($status === 'ok') {
                foreach (edok_step_validation_errors($doc, $action) as $e) $errors[] = $e;
                // Weryfikacja tożsamości PIN-em — wymagana wyłącznie przy akceptacji
                // ("Tak/OK"), zgodnie z Uchwałą 5/2026 §1 pkt 4.
                if (!$errors) {
                    $pin_error = edok_pin_verify_for_decision((int)$user['id'], $pin, $id, $action);
                    if ($pin_error !== null) {
                        $errors[] = $pin_error;
                    } else {
                        $pin_verified = true;
                    }
                }
            } elseif ($status === 'odrzucono' && $notes === '') {
                $errors[] = 'Podaj powód odrzucenia.';
            }
        }

        if (!$errors) {
            $result = edok_decide_step($doc, $action, $status, (int)$user['id'], $notes, $pin_verified);
            flash_set($result['rejected'] ? 'warning' : 'success', $result['rejected'] ? 'Dokument odrzucony.' : 'Decyzja zapisana.');
            header('Location: ' . APP_URL . '/edok/view.php?id=' . $id);
            exit;
        }
    }

    // Cofnięcie decyzji — tylko admin / rola 'ksiegowy'
    if ($action === 'unlock_doc') {
        if (!edok_has_unlock_perm()) {
            flash_set('danger', 'Brak uprawnień do cofania decyzji.');
            header('Location: ' . APP_URL . '/edok/view.php?id=' . $id);
            exit;
        }
        $reason = trim($_POST['unlock_reason'] ?? '');
        if ($reason === '') {
            $errors[] = 'Podaj powód cofnięcia decyzji.';
        } else {
            try {
                edok_unlock($id, $reason);
                flash_set('warning', 'Decyzja cofnięta — obieg wznowiony od etapu 1.');
            } catch (Throwable $e) {
                flash_set('danger', 'Błąd: ' . $e->getMessage());
            }
            header('Location: ' . APP_URL . '/edok/view.php?id=' . $id);
            exit;
        }
    }

    // Wycofanie dokumentu przez wnioskodawcę / admina
    if ($action === 'withdraw_doc' && (is_admin() || (int)$doc['created_by'] === (int)($user['id'] ?? 0))) {
        $reason = trim($_POST['withdraw_reason'] ?? 'Wycofano przez wnioskodawcę.');
        try {
            edok_withdraw($id, $reason);
            flash_set('warning', 'Dokument wycofany.');
        } catch (Throwable $e) {
            flash_set('danger', 'Błąd: ' . $e->getMessage());
        }
        header('Location: ' . APP_URL . '/edok/view.php?id=' . $id);
        exit;
    }

    // Ręczne zatwierdzenie powiązanej faktury sprzedaży w Comarch Betterfly.
    if ($action === 'betterfly_confirm') {
        if (!(is_admin() || edok_has_role('zatwierdza') || edok_has_role('ksiegowy'))) {
            flash_set('danger', 'Brak uprawnień do zatwierdzania faktur Betterfly.');
            header('Location: ' . APP_URL . '/edok/view.php?id=' . $id);
            exit;
        }
        try {
            require_once __DIR__ . '/../includes/betterfly_invoices.php';
            $row = betterfly_confirm_from_edok($id);
            flash_set('success', 'Faktura zatwierdzona w Betterfly' . (!empty($row['number']) ? ' (nr ' . $row['number'] . ').' : '.'));
        } catch (Throwable $e) {
            flash_set('danger', 'Betterfly: ' . $e->getMessage());
        }
        header('Location: ' . APP_URL . '/edok/view.php?id=' . $id);
        exit;
    }

    $doc = edok_get($id); // odśwież po ew. nieudanej próbie
}

// Powiązanie z fakturą Comarch Betterfly (jeśli dokument powstał z integracji).
$bf_link = null;
if (is_file(__DIR__ . '/../includes/betterfly_invoices.php')) {
    require_once __DIR__ . '/../includes/betterfly_invoices.php';
    if (function_exists('betterfly_invoices_migrate')) {
        try {
            betterfly_invoices_migrate();
            $bf_link = db_one("SELECT * FROM betterfly_invoices WHERE edok_doc_id=?", [$id]);
        } catch (\Throwable $e) { $bf_link = null; }
    }
}

$PAGE_TITLE = $doc['number'] . ' — EODoK';
require_once __DIR__ . '/../includes/header.php';

$jest_przychod = ($doc['kierunek'] ?? 'wydatek') === 'przychod';
$steps_config = [
    'meryt'      => ['sub' => $jest_przychod ? 'Weryfikacja zgodności wpływu z rzeczywistym zdarzeniem' : 'Weryfikacja wykonania usługi/dostawy przez zleceniodawcę', 'icon' => 'bi-patch-check',       'tone' => 'blue'],
    'formal'     => ['sub' => $jest_przychod ? 'Źródło i data wpływu, powiązanie z dokumentem'          : 'NIP, stawki VAT, elementy ustawowe faktury',                 'icon' => 'bi-file-earmark-check', 'tone' => 'cyan'],
    'rachunkowa' => ['sub' => 'Przeliczenia i zgodność kwot netto/VAT/brutto',              'icon' => 'bi-calculator',         'tone' => 'amber'],
    'dekretacja' => ['sub' => $jest_przychod ? 'Rodzaj działalności, projekt/MPK przychodu'              : 'Rodzaj działalności, projekt, MPK',                          'icon' => 'bi-journal-bookmark',   'tone' => 'slate'],
    'zatwierdza' => ['sub' => $jest_przychod ? 'Zatwierdzenie do ujęcia przychodu w ewidencji'           : 'Zatwierdzenie do wypłaty i księgowania',                     'icon' => 'bi-cash-coin',          'tone' => 'emerald'],
];

// Krok "bieżący" = pierwszy niezdecydowany w kolejności — sekwencyjność obiegu
// gwarantuje, że w danej chwili jest ich co najwyżej jeden (edok_step_blocked_reason()).
$current_key = null;
if (!$is_terminal) {
    foreach (edok_step_order() as $sk) {
        $s = $doc['steps'][$sk] ?? null;
        if (!$s || !in_array($s['status'], ['ok', 'uwagi', 'odrzucono'], true)) { $current_key = $sk; break; }
    }
}
$current_cfg     = $current_key ? $steps_config[$current_key] : null;
$current_blocked = $current_key ? edok_step_blocked_reason($doc, $current_key) : null;
$current_can_act = $current_key && !$current_blocked && edok_has_role($current_key) && !$is_terminal;
$current_pending = ($current_key && !$current_blocked) ? edok_step_validation_errors($doc, $current_key) : [];
// „Weryfikuj i podpisz wszystkie etapy” — pokazywane, gdy użytkownik może podpisać ≥ 2 kolejne etapy
$sign_all_plan   = ($current_can_act && edok_pin_is_set((int)$user['id'])) ? edok_sign_all_plan($doc, (int)$user['id']) : [];
$sign_all_ok     = array_values(array_filter($sign_all_plan, fn($p) => !$p['errors']));
$show_sign_all   = count($sign_all_plan) >= 2 && !$sign_all_plan[0]['errors'];

/**
 * Pola "typowe" dla danego etapu/obszaru kontroli — to, co akceptujący
 * powinien mieć przed oczami PODEJMUJĄC decyzję, nie tylko w tle strony.
 * Wartości użytkownika escapowane pojedynczo (h()) przed złożeniem z gotowym
 * znacznikiem HTML (np. ikoną statusu) — string zwracany jest już bezpieczny
 * do wypisania wprost, bez ponownego h() przy renderowaniu.
 */
function edok_step_review_fields(string $step_key, array $doc): array {
    switch ($step_key) {
        case 'meryt':
            return [
                ['Opis', $doc['description'] !== '' ? nl2br(h($doc['description'])) : '—'],
                ['Kwota brutto', h($doc['kwota_brutto']) . ' ' . h($doc['waluta'])],
                ['Dokument źródłowy', $doc['file_path']
                    ? '<span class="tw-text-emerald-600"><i class="bi bi-check-circle-fill"></i> dołączony</span>'
                    : '<span class="tw-text-red-600"><i class="bi bi-x-circle-fill"></i> brak</span>'],
            ];
        case 'formal':
            $nip = trim((string)$doc['kontrahent_nip']);
            $nip_ok = $nip === '' || edok_nip_valid($nip);
            return [
                ['Kontrahent', h($doc['kontrahent_nazwa']) ?: '—'],
                ['NIP', $nip !== ''
                    ? h($nip) . ' ' . ($nip_ok ? '<span class="tw-text-emerald-600"><i class="bi bi-check-circle-fill"></i></span>' : '<span class="tw-text-red-600"><i class="bi bi-x-circle-fill"></i> błędna suma kontrolna</span>')
                    : '—'],
                ['Numer dokumentu', $doc['nr_faktury'] !== '' ? h($doc['nr_faktury']) : '—'],
                ['Data wystawienia', $doc['data_wystawienia'] ? date_pl($doc['data_wystawienia']) : '—'],
            ];
        case 'dekretacja':
            return [
                ['Rodzaj działalności', $doc['rodzaj_dzialalnosci']
                    ? h(EDOK_RODZAJ_DZIALALNOSCI[$doc['rodzaj_dzialalnosci']] ?? $doc['rodzaj_dzialalnosci'])
                    : '<span class="tw-text-red-600">nie uzupełniono</span>'],
                ['Projekt / MPK', ($doc['projekt'] ?: $doc['mpk']) !== '' ? h($doc['projekt'] ?: $doc['mpk']) : '—'],
            ];
        case 'zatwierdza':
            $zap = [];
            if ($doc['typ_dokumentu'] === 'proforma') {
                $zap[] = ['Proforma', '<span class="tw-text-amber-700">podstawa przedpłaty — faktura końcowa zostanie dołączona później</span>'];
            }
            if (!empty($doc['zaplacono_przed'])) {
                $zap[] = ['Zapłacono przed akceptacją', '<strong class="tw-text-amber-700">' . h(edok_zaplata_opis($doc)) . '</strong>'
                    . (edok_zaplata_do_zwrotu($doc) ? '<br><span class="tw-text-xs">Zwrot na rachunek ' . h(edok_nrb_format($doc['zwrot_rachunek'])) . '</span>' : '')
                    . ($doc['forma_zaplaty'] !== EDOK_FORMA_PROFORMA
                        ? '<br><span class="tw-text-xs">' . (!empty($doc['dowod_zaplaty_path'])
                            ? '<a href="' . APP_URL . '/edok/file.php?id=' . (int)$doc['id'] . '&type=dowod" target="_blank"><i class="bi bi-paperclip"></i> dowód zapłaty</a>'
                            : '<span class="tw-text-red-700">brak dowodu zapłaty</span>') . '</span>'
                        : '')];
            }
            return array_merge($zap, [
                ['Kontrahent', h($doc['kontrahent_nazwa']) ?: '—'],
                ['Kwota brutto', h($doc['kwota_brutto']) . ' ' . h($doc['waluta'])],
                [$doc['kierunek'] === 'przychod' ? 'Sugerowana referencja' : 'Tytuł przelewu', $doc['tytul_przelewu'] !== '' ? h($doc['tytul_przelewu']) : '—'],
            ]);
        default:
            return [];
    }
}
$current_review = $current_key ? edok_step_review_fields($current_key, $doc) : [];
?>
<style type="text/tailwindcss">
.edok-page { @apply tw-max-w-6xl tw-mx-auto; }
.edok-topbar { @apply tw-flex tw-items-center tw-justify-between tw-flex-wrap tw-gap-3 tw-mb-4; }
.edok-topbar__title { @apply tw-flex tw-items-center tw-gap-2 tw-text-lg tw-font-bold tw-text-slate-800; }
.edok-actions { @apply tw-flex tw-items-center tw-gap-2 tw-flex-wrap; }

.edok-btn { @apply tw-inline-flex tw-items-center tw-gap-1.5 tw-rounded-lg tw-px-3 tw-py-1.5 tw-text-sm tw-font-semibold tw-border tw-border-solid tw-transition-colors tw-no-underline tw-cursor-pointer; }
.edok-btn-ghost   { @apply tw-bg-white tw-border-slate-200 tw-text-slate-600 hover:tw-bg-slate-50; }
.edok-btn-primary { @apply tw-bg-blue-600 tw-border-blue-600 tw-text-white hover:tw-bg-blue-700; }
.edok-btn-success { @apply tw-bg-emerald-600 tw-border-emerald-600 tw-text-white hover:tw-bg-emerald-700; }
.edok-btn-warning { @apply tw-bg-amber-500 tw-border-amber-500 tw-text-white hover:tw-bg-amber-600; }
.edok-btn-danger  { @apply tw-bg-white tw-border-red-200 tw-text-red-600 hover:tw-bg-red-50; }
.edok-btn-sm      { @apply tw-px-2 tw-py-1 tw-text-xs; }
.edok-btn:disabled { @apply tw-opacity-50 tw-cursor-not-allowed; }

.edok-badge { @apply tw-inline-flex tw-items-center tw-gap-1 tw-rounded-full tw-px-2.5 tw-py-0.5 tw-text-xs tw-font-semibold; }
.edok-badge-secondary { @apply tw-bg-slate-100 tw-text-slate-600; }
.edok-badge-warning   { @apply tw-bg-amber-100 tw-text-amber-700; }
.edok-badge-success   { @apply tw-bg-emerald-100 tw-text-emerald-700; }
.edok-badge-danger    { @apply tw-bg-red-100 tw-text-red-700; }
.edok-badge-dark      { @apply tw-bg-slate-800 tw-text-white; }

.edok-alert { @apply tw-rounded-lg tw-border tw-border-solid tw-px-3 tw-py-2 tw-text-sm tw-flex tw-items-start tw-gap-2; }
.edok-alert-danger  { @apply tw-bg-red-50 tw-border-red-200 tw-text-red-700; }
.edok-alert-warning { @apply tw-bg-amber-50 tw-border-amber-200 tw-text-amber-700; }

.edok-card { @apply tw-bg-white tw-rounded-xl tw-border tw-border-solid tw-border-slate-300 tw-shadow-sm tw-mb-4 tw-overflow-hidden; }
.edok-card__hd { @apply tw-flex tw-items-center tw-gap-2 tw-px-4 tw-py-2.5 tw-border-b tw-border-solid tw-border-slate-200 tw-font-semibold tw-text-slate-800 tw-text-sm; }
.edok-card__bd { @apply tw-p-4; }

.edok-kv { @apply tw-w-full tw-text-sm; }
.edok-kv tr + tr td { @apply tw-pt-1.5; }
.edok-kv td:first-child { @apply tw-text-slate-600 tw-pr-3 tw-align-top tw-w-[42%]; }
.edok-kv td:last-child { @apply tw-font-medium tw-text-slate-900; }
.edok-amounts { @apply tw-rounded-lg tw-bg-slate-100 tw-p-3 tw-mb-3; }
.edok-amounts .total { @apply tw-border-t tw-border-solid tw-border-slate-300 tw-pt-1.5 tw-mt-1.5 tw-font-bold; }

.edok-tracker { @apply tw-flex tw-items-stretch tw-gap-1 sm:tw-gap-2 tw-mb-4; }
.edok-tracker__step { @apply tw-flex-1 tw-rounded-lg tw-border tw-border-solid tw-px-2 tw-py-2 tw-text-center tw-bg-white tw-border-slate-300; }
.edok-tracker__step.is-done    { @apply tw-bg-emerald-50 tw-border-emerald-300; }
.edok-tracker__step.is-current { @apply tw-bg-blue-50 tw-border-blue-400 tw-ring-2 tw-ring-blue-200; }
.edok-tracker__step.is-blocked { @apply tw-bg-slate-100 tw-border-slate-300 tw-opacity-70; }
.edok-tracker__step.is-rejected{ @apply tw-bg-red-50 tw-border-red-300; }
.edok-tracker__step.is-uwagi   { @apply tw-bg-amber-50 tw-border-amber-300; }
.edok-tracker__num { @apply tw-text-[.65rem] tw-font-bold tw-text-slate-500 tw-block; }
.edok-tracker__label { @apply tw-text-[.72rem] tw-font-semibold tw-text-slate-800 tw-block tw-leading-tight tw-mt-0.5; }
.edok-tracker__icon { @apply tw-text-base tw-block tw-mt-1; }

.edok-history-item { @apply tw-flex tw-items-start tw-gap-2 tw-py-2 tw-border-b tw-border-solid tw-border-slate-200 last:tw-border-0 tw-text-sm; }

.edok-wizard .modal-content { @apply tw-rounded-xl tw-border-0 tw-shadow-lg; }
.edok-wizard-hd { @apply tw-px-4 tw-py-3 tw-border-b tw-border-solid tw-border-slate-200; }
.edok-wizard-pane { @apply tw-p-4; }
.edok-choice { @apply tw-flex-1 tw-flex tw-flex-col tw-items-center tw-gap-1 tw-rounded-lg tw-border-2 tw-border-solid tw-border-slate-300 tw-px-3 tw-py-2.5 tw-cursor-pointer tw-transition-colors tw-text-sm tw-font-semibold tw-text-slate-600; }
.edok-choice input { @apply tw-sr-only; }
.edok-choice:has(input:checked).choice-ok      { @apply tw-border-emerald-500 tw-bg-emerald-50 tw-text-emerald-700; }
.edok-choice:has(input:checked).choice-uwagi   { @apply tw-border-amber-500 tw-bg-amber-50 tw-text-amber-700; }
.edok-choice:has(input:checked).choice-odrzuc  { @apply tw-border-red-500 tw-bg-red-50 tw-text-red-700; }
.edok-pin-input { @apply tw-w-full tw-text-center tw-text-2xl tw-tracking-[.5em] tw-font-mono tw-rounded-lg tw-border tw-border-solid tw-border-slate-400 tw-py-2 focus:tw-border-blue-400 focus:tw-outline-none focus:tw-ring-2 focus:tw-ring-blue-100; }
.edok-pin-input.is-invalid { @apply tw-border-red-400 tw-ring-2 tw-ring-red-100; }
</style>

<div class="edok-page">
<div class="edok-topbar">
  <div class="edok-topbar__title">
    <a href="<?= APP_URL ?>/edok/index.php" class="edok-btn edok-btn-ghost edok-btn-sm" aria-label="Wróć"><i class="bi bi-arrow-left"></i></a>
    <code><?= h($doc['number']) ?></code>
    <?= edok_status_badge($doc['status'], $doc) ?>
    <?php if (!empty($doc['zaplacono_przed'])): ?>
    <span class="edok-badge edok-badge-success" title="Faktura zapłacona przed akceptacją"><i class="bi bi-cash-coin"></i> Zapłacona <?= h(edok_zaplata_opis($doc)) ?></span>
    <?php endif; ?>
  </div>
  <?php
  $generated = edok_latest_generated_pdf($id);
  // Eksport przelewu — ten sam mechanizm co w Preliminarzu (POST na edok/preliminarz.php,
  // tam potwierdzenie NIP/rachunku przy pierwszym przelewie i pobranie pliku).
  $przelew_rachunki = edok_rachunki_list();
  $can_export_przelew = edok_przelew_exportable($doc)
      && $przelew_rachunki
      && (is_admin() || edok_has_role('zatwierdza') || (function_exists('kdok_has_role') && kdok_has_role('zatwierdza')));
  ?>
  <div class="edok-actions">
    <?php if ($can_export_przelew): ?>
    <button class="edok-btn edok-btn-success" type="button" data-bs-toggle="modal" data-bs-target="#przelewExportModal">
      <i class="bi bi-bank"></i> Eksport przelewu
    </button>
    <?php endif; ?>
    <a href="<?= APP_URL ?>/edok/ustaw_pin.php" class="edok-btn edok-btn-ghost" title="Twój PIN EODoK"><i class="bi bi-shield-lock"></i></a>
    <?php if ($generated): ?>
    <a href="<?= APP_URL ?>/edok/file.php?id=<?= $id ?>&type=final" target="_blank" class="edok-btn edok-btn-primary">
      <i class="bi bi-file-earmark-check"></i> Dokument końcowy
    </a>
    <?php else: ?>
    <a href="<?= APP_URL ?>/edok/print.php?id=<?= $id ?>" target="_blank" class="edok-btn edok-btn-ghost">
      <i class="bi bi-printer"></i> Wydruk
    </a>
    <?php endif; ?>
    <a href="<?= APP_URL ?>/edok/print_pdf.php?id=<?= $id ?>" target="_blank" class="edok-btn edok-btn-ghost" title="PDF: dokument źródłowy + karta akceptacji, do wydruku">
      <i class="bi bi-file-earmark-pdf"></i> PDF do druku
    </a>
    <?php if (edok_has_unlock_perm() && $is_terminal): ?>
    <button class="edok-btn edok-btn-ghost" type="button" data-bs-toggle="modal" data-bs-target="#unlockModal">
      <i class="bi bi-arrow-counterclockwise"></i> Cofnij decyzję
    </button>
    <?php endif; ?>
    <?php if (!$is_terminal && (is_admin() || (int)$doc['created_by'] === (int)($user['id'] ?? 0))): ?>
    <form method="post" onsubmit="return confirm('Wycofać dokument z obiegu?');" class="tw-inline">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="withdraw_doc">
      <button class="edok-btn edok-btn-danger" type="submit"><i class="bi bi-x-lg"></i> Wycofaj</button>
    </form>
    <?php endif; ?>
  </div>
</div>

<?php if ($errors): ?>
<div class="edok-alert edok-alert-danger tw-mb-4">
  <i class="bi bi-exclamation-triangle-fill tw-mt-0.5"></i>
  <ul class="tw-mb-0 tw-pl-4"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<?php if ($bf_link):
  $bf_sales     = ($bf_link['direction'] ?? 'sales') === 'sales';
  $bf_confirmed = (int)($bf_link['doc_status'] ?? 0) === 1;
  $bf_can       = is_admin() || edok_has_role('zatwierdza') || edok_has_role('ksiegowy');
?>
<div class="edok-card tw-mb-4">
  <div class="edok-card__hd"><i class="bi bi-receipt"></i> Comarch Betterfly
    <span class="edok-badge edok-badge-secondary"><?= $bf_sales ? 'Faktura sprzedaży' : 'Faktura zakupu' ?></span>
  </div>
  <div class="edok-card__bd tw-text-sm tw-flex tw-items-center tw-justify-between tw-flex-wrap tw-gap-2">
    <div class="tw-text-slate-600">
      <?php if (!empty($bf_link['number'])): ?>Numer: <strong><?= h($bf_link['number']) ?></strong> · <?php endif; ?>
      Dokument:
      <span class="edok-badge <?= $bf_confirmed ? 'edok-badge-success' : 'edok-badge-warning' ?>">
        <?= $bf_confirmed ? 'zatwierdzona' : 'bufor' ?>
      </span>
      · Płatność: <?= h(betterfly_payment_status_label((int)($bf_link['payment_status'] ?? 0))) ?>
    </div>
    <?php if ($bf_sales && !$bf_confirmed): ?>
      <?php if ($bf_can): ?>
      <form method="post" onsubmit="return confirm('Zatwierdzić fakturę w Betterfly? Operacja jest nieodwracalna.');" class="tw-inline">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="betterfly_confirm">
        <button class="edok-btn edok-btn-success" type="submit">
          <i class="bi bi-check2-circle"></i> Zatwierdź w Betterfly
        </button>
      </form>
      <?php else: ?>
      <span class="tw-text-slate-400 tw-text-xs">Zatwierdzenie po akceptacji obiegu (rola: zatwierdzający / księgowy).</span>
      <?php endif; ?>
    <?php elseif ($bf_sales && $bf_confirmed): ?>
      <span class="edok-badge edok-badge-success"><i class="bi bi-check2-all"></i> Zatwierdzona w Betterfly</span>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<div class="tw-grid tw-grid-cols-1 lg:tw-grid-cols-12 tw-gap-4">
  <div class="lg:tw-col-span-5">
    <!-- Karta dokumentu -->
    <div class="edok-card">
      <div class="edok-card__hd"><i class="bi bi-file-earmark-text"></i> <?= h(EDOK_TYPES[$doc['typ_dokumentu']] ?? $doc['typ_dokumentu']) ?></div>
      <div class="edok-card__bd">
        <table class="edok-kv tw-mb-3">
          <tbody>
            <tr><td>Kontrahent</td><td><?= h($doc['kontrahent_nazwa']) ?></td></tr>
            <?php if ($doc['kontrahent_nip']): ?><tr><td>NIP</td><td class="tw-font-mono"><?= h($doc['kontrahent_nip']) ?></td></tr><?php endif; ?>
            <tr><td>Numer dokumentu</td><td class="tw-font-mono"><?= h($doc['nr_faktury']) ?></td></tr>
            <?php if ($doc['data_wystawienia']): ?><tr><td>Data wystawienia</td><td><?= date_pl($doc['data_wystawienia']) ?></td></tr><?php endif; ?>
            <?php if ($doc['data_sprzedazy']): ?><tr><td>Data sprzedaży/wykonania</td><td><?= date_pl($doc['data_sprzedazy']) ?></td></tr><?php endif; ?>
            <?php if ($doc['data_wplywu']): ?><tr><td>Data wpływu</td><td><?= date_pl($doc['data_wplywu']) ?></td></tr><?php endif; ?>
            <?php if ($doc['okres'] !== ''): ?><tr><td>Okres wynagrodzenia</td><td><?= h(edok_okres_label($doc['okres'])) ?></td></tr><?php endif; ?>
            <?php if ($doc['umowa_numer'] !== ''): ?>
            <tr><td>Umowa nr</td><td class="tw-font-mono">
              <?php if ($doc['contract_type'] && $doc['contract_id']): ?><a href="<?= APP_URL ?>/contracts/<?= h($doc['contract_type']) ?>/view.php?id=<?= (int)$doc['contract_id'] ?>"><?= h($doc['umowa_numer']) ?></a><?php else: ?><?= h($doc['umowa_numer']) ?><?php endif; ?>
            </td></tr>
            <?php endif; ?>
            <?php if ($doc['kwota_do_wyplaty'] !== ''): ?><tr><td>Kwota do wypłaty</td><td class="tw-font-mono"><?= h($doc['kwota_do_wyplaty']) ?> <?= h($doc['waluta']) ?> <span class="tw-text-xs tw-text-slate-500">(brutto <?= h($doc['kwota_brutto']) ?>)</span></td></tr><?php endif; ?>
            <?php if (!empty($doc['zaplacono_przed'])): ?>
            <tr><td>Zapłacono przed akceptacją</td><td><?= h(edok_zaplata_opis($doc)) ?>
              <?php if (edok_zaplata_do_zwrotu($doc)): ?><div class="tw-text-xs tw-font-mono"><?= h(edok_nrb_format($doc['zwrot_rachunek'])) ?></div><?php endif; ?>
              <?php if ($doc['forma_zaplaty'] !== EDOK_FORMA_PROFORMA): ?>
              <div class="tw-text-xs">
                <?php if (!empty($doc['dowod_zaplaty_path'])): ?>
                <a href="<?= APP_URL ?>/edok/file.php?id=<?= $id ?>&type=dowod" target="_blank"><i class="bi bi-paperclip"></i> Dowód zapłaty</a>
                <?php else: ?>
                <span class="tw-text-amber-700"><i class="bi bi-exclamation-triangle"></i> brak dowodu zapłaty</span>
                <?php endif; ?>
              </div>
              <?php endif; ?>
            </td></tr>
            <?php endif; ?>
            <?php if (!empty($doc['proforma_id']) && ($pf = edok_get((int)$doc['proforma_id']))): ?>
            <tr><td>Rozlicza proformę</td><td>
              <a href="<?= APP_URL ?>/edok/view.php?id=<?= (int)$pf['id'] ?>"><?= h($pf['number']) ?></a> · nr <?= h($pf['nr_faktury']) ?> · <?= h($pf['kwota_brutto']) ?> <?= h($pf['waluta']) ?>
              <?php if (($pf['status_platnosci'] ?: 'nowy') !== 'oplacony'): ?>
              <div class="tw-text-xs tw-text-amber-700"><i class="bi bi-exclamation-triangle"></i> Proforma nie ma jeszcze statusu „Opłacony”.</div>
              <?php endif; ?>
              <?php if (abs(_edok_kwota_float((string)$pf['kwota_brutto']) - _edok_kwota_float((string)$doc['kwota_brutto'])) >= 0.01): ?>
              <div class="tw-text-xs tw-text-red-700"><i class="bi bi-exclamation-triangle"></i> Kwota różni się od proformy o <?= number_format(_edok_kwota_float((string)$doc['kwota_brutto']) - _edok_kwota_float((string)$pf['kwota_brutto']), 2, ',', ' ') ?> — dopłatę lub nadpłatę rozlicz osobno.</div>
              <?php endif; ?>
            </td></tr>
            <?php endif; ?>
            <?php if ($doc['typ_dokumentu'] === 'proforma'): $pf_fak = edok_proforma_faktura($id); ?>
            <tr><td>Faktura końcowa</td><td>
              <?php if ($pf_fak): ?>
              <a href="<?= APP_URL ?>/edok/view.php?id=<?= (int)$pf_fak['id'] ?>"><?= h($pf_fak['number']) ?></a> · nr <?= h($pf_fak['nr_faktury']) ?>
              <?php else: ?>
              <span class="tw-text-amber-700"><i class="bi bi-hourglass-split"></i> jeszcze nie dołączona</span>
              <?php if (is_admin() || edok_has_role('upload')): ?> · <a href="<?= APP_URL ?>/edok/add.php">dodaj fakturę</a> i wskaż tę proformę<?php endif; ?>
              <?php endif; ?>
            </td></tr>
            <?php endif; ?>
            <?php if ($doc['kierunek'] === 'przychod' && $doc['zrodlo_przychodu']): ?>
            <tr><td>Źródło przychodu</td><td><?= h($doc['zrodlo_przychodu']) ?></td></tr>
            <?php endif; ?>
            <?php if ($doc['tytul_przelewu']): ?>
            <tr>
              <td><?= $doc['kierunek'] === 'przychod' ? 'Sugerowana referencja' : 'Tytuł przelewu' ?></td>
              <td>
                <span class="tw-font-mono tw-text-xs" id="tytul_przelewu_view"><?= h($doc['tytul_przelewu']) ?></span>
                <button type="button" class="tw-text-blue-600 tw-ml-1" title="Kopiuj"
                  onclick="navigator.clipboard.writeText(document.getElementById('tytul_przelewu_view').textContent)">
                  <i class="bi bi-clipboard"></i>
                </button>
              </td>
            </tr>
            <?php endif; ?>
          </tbody>
        </table>

        <div class="edok-amounts">
          <table class="edok-kv">
            <tbody>
              <tr><td>Netto</td><td class="tw-text-right tw-font-mono"><?= h($doc['kwota_netto']) ?></td></tr>
              <tr><td>VAT</td><td class="tw-text-right tw-font-mono"><?= h($doc['kwota_vat']) ?></td></tr>
              <tr class="total"><td>Brutto</td><td class="tw-text-right tw-font-mono"><?= h($doc['kwota_brutto']) ?> <?= h($doc['waluta']) ?></td></tr>
            </tbody>
          </table>
        </div>

        <?php if ($doc['description']): ?>
        <p class="tw-text-sm tw-mb-3"><strong><?= $doc['kierunek'] === 'przychod' ? 'Opis przychodu:' : 'Opis wydatku:' ?></strong> <?= nl2br(h($doc['description'])) ?></p>
        <?php endif; ?>

        <?php if ($doc['file_path']): ?>
        <a href="<?= APP_URL ?>/edok/file.php?id=<?= $id ?>" target="_blank" class="edok-btn edok-btn-ghost edok-btn-sm">
          <i class="bi bi-file-earmark-pdf"></i> Dokument źródłowy
        </a>
        <?php endif; ?>
      </div>
    </div>

    <!-- Dekretacja -->
    <div class="edok-card">
      <div class="edok-card__hd"><i class="bi bi-journal-bookmark"></i> Dekretacja</div>
      <div class="edok-card__bd">
        <?php if ($doc['rodzaj_dzialalnosci'] || $doc['projekt'] || $doc['mpk']): ?>
        <table class="edok-kv">
          <tbody>
            <?php if ($doc['rodzaj_dzialalnosci']): ?><tr><td>Rodzaj działalności</td><td><?= h(EDOK_RODZAJ_DZIALALNOSCI[$doc['rodzaj_dzialalnosci']] ?? $doc['rodzaj_dzialalnosci']) ?></td></tr><?php endif; ?>
            <?php if ($doc['projekt']): ?><tr><td>Projekt / działanie</td><td class="tw-font-mono"><?= h($doc['projekt']) ?></td></tr><?php endif; ?>
            <?php if ($doc['mpk']): ?><tr><td>MPK</td><td class="tw-font-mono"><?= h($doc['mpk']) ?></td></tr><?php endif; ?>
          </tbody>
        </table>
        <?php else: ?>
        <p class="tw-text-sm tw-text-slate-500 tw-mb-0">Nie uzupełniono — wymagane przed zaakceptowaniem etapu „Dekretacja i alokacja kosztów”.</p>
        <?php endif; ?>
      </div>
    </div>

    <?php if (!$is_terminal && (is_admin() || edok_has_role('upload') || edok_has_role('dekretacja'))): ?>
    <button class="edok-btn edok-btn-ghost tw-mb-4" type="button" data-bs-toggle="collapse" data-bs-target="#metaForm">
      <i class="bi bi-pencil"></i> Edytuj dane dokumentu
    </button>
    <div class="collapse" id="metaForm">
      <form method="post" class="edok-card" enctype="multipart/form-data">
        <div class="edok-card__bd">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="update_meta">

        <div class="tw-mb-2">
          <label class="tw-block tw-text-xs tw-font-semibold tw-text-slate-600 tw-mb-1">Opis <?= $locked_meryt ? '<i class="bi bi-lock-fill tw-text-slate-400"></i>' : '' ?></label>
          <textarea name="description" class="form-control form-control-sm" rows="2" <?= $locked_meryt ? 'readonly' : '' ?>><?= h($doc['description']) ?></textarea>
        </div>
        <div class="row g-2 mb-2">
          <div class="col-sm-8">
            <label class="tw-block tw-text-xs tw-font-semibold tw-text-slate-600 tw-mb-1">Kontrahent <?= $locked_formal ? '<i class="bi bi-lock-fill tw-text-slate-400"></i>' : '' ?></label>
            <input type="text" name="kontrahent_nazwa" class="form-control form-control-sm" value="<?= h($doc['kontrahent_nazwa']) ?>" <?= $locked_formal ? 'readonly' : '' ?>>
          </div>
          <div class="col-sm-4">
            <label class="tw-block tw-text-xs tw-font-semibold tw-text-slate-600 tw-mb-1">NIP</label>
            <input type="text" name="kontrahent_nip" class="form-control form-control-sm" value="<?= h($doc['kontrahent_nip']) ?>" <?= $locked_formal ? 'readonly' : '' ?>>
          </div>
        </div>
        <div class="tw-mb-2">
          <label class="tw-block tw-text-xs tw-font-semibold tw-text-slate-600 tw-mb-1">Numer dokumentu</label>
          <input type="text" name="nr_faktury" class="form-control form-control-sm" value="<?= h($doc['nr_faktury']) ?>" <?= $locked_formal ? 'readonly' : '' ?>>
        </div>
        <?php if ($doc['kierunek'] === 'przychod'): ?>
        <div class="tw-mb-2">
          <label class="tw-block tw-text-xs tw-font-semibold tw-text-slate-600 tw-mb-1">Źródło przychodu <?= $locked_formal ? '<i class="bi bi-lock-fill tw-text-slate-400"></i>' : '' ?></label>
          <input type="text" name="zrodlo_przychodu" class="form-control form-control-sm" value="<?= h($doc['zrodlo_przychodu']) ?>" <?= $locked_formal ? 'readonly' : '' ?>>
        </div>
        <?php endif; ?>
        <div class="row g-2 mb-2">
          <div class="col-sm-4">
            <label class="tw-block tw-text-xs tw-font-semibold tw-text-slate-600 tw-mb-1">Netto <?= $locked_rachunkowa ? '<i class="bi bi-lock-fill tw-text-slate-400"></i>' : '' ?></label>
            <input type="text" id="e_netto" name="kwota_netto" class="form-control form-control-sm font-monospace text-end" value="<?= h($doc['kwota_netto']) ?>" <?= $locked_rachunkowa ? 'readonly' : 'oninput="edokViewRecalc()"' ?>>
          </div>
          <div class="col-sm-4">
            <label class="tw-block tw-text-xs tw-font-semibold tw-text-slate-600 tw-mb-1">VAT</label>
            <input type="text" id="e_vat" name="kwota_vat" class="form-control form-control-sm font-monospace text-end" value="<?= h($doc['kwota_vat']) ?>" <?= $locked_rachunkowa ? 'readonly' : 'oninput="edokViewRecalc()"' ?>>
          </div>
          <div class="col-sm-4">
            <label class="tw-block tw-text-xs tw-font-semibold tw-text-slate-600 tw-mb-1">Brutto</label>
            <input type="text" id="e_brutto" name="kwota_brutto" class="form-control form-control-sm font-monospace text-end fw-semibold" value="<?= h($doc['kwota_brutto']) ?>" <?= $locked_rachunkowa ? 'readonly' : '' ?>>
          </div>
        </div>
        <div class="row g-2 mb-2">
          <div class="col-sm-6">
            <label class="tw-block tw-text-xs tw-font-semibold tw-text-slate-600 tw-mb-1">Rodzaj działalności <?= $locked_dekretacja ? '<i class="bi bi-lock-fill tw-text-slate-400"></i>' : '' ?></label>
            <select name="rodzaj_dzialalnosci" class="form-select form-select-sm" <?= $locked_dekretacja ? 'disabled' : '' ?>>
              <option value="">— wybierz —</option>
              <?php foreach (EDOK_RODZAJ_DZIALALNOSCI as $k => $l): ?>
              <option value="<?= h($k) ?>" <?= $doc['rodzaj_dzialalnosci'] === $k ? 'selected' : '' ?>><?= h($l) ?></option>
              <?php endforeach; ?>
            </select>
            <?php if ($locked_dekretacja): ?><input type="hidden" name="rodzaj_dzialalnosci" value="<?= h($doc['rodzaj_dzialalnosci']) ?>"><?php endif; ?>
          </div>
          <div class="col-sm-6">
            <label class="tw-block tw-text-xs tw-font-semibold tw-text-slate-600 tw-mb-1">Projekt / działanie</label>
            <input type="text" name="projekt" class="form-control form-control-sm" value="<?= h($doc['projekt']) ?>" <?= $locked_dekretacja ? 'readonly' : '' ?>>
          </div>
        </div>
        <?php if ($doc['kierunek'] === 'wydatek'): ?>
        <?php $zp_vals = $doc; $zp_locked = $locked_rachunkowa; $zp_doc_id = $id; $zp_typ = $doc['typ_dokumentu']; include __DIR__ . '/_zaplata_fields.php'; ?>
        <?php endif; ?>
        <?php if (in_array($doc['typ_dokumentu'], ['rachunek', 'lista_plac'], true) && $doc['kierunek'] === 'wydatek'): ?>
        <div class="row g-2 mb-2">
          <div class="col-sm-4">
            <label class="tw-block tw-text-xs tw-font-semibold tw-text-slate-600 tw-mb-1" for="e_okres">Okres wynagrodzenia <?= $locked_rachunkowa ? '<i class="bi bi-lock-fill tw-text-slate-400"></i>' : '' ?></label>
            <?= edok_okres_select_html('okres', 'e_okres', (string)$doc['okres'], $locked_rachunkowa ? 'disabled' : '') ?>
            <?php if ($locked_rachunkowa): ?><input type="hidden" name="okres" value="<?= h($doc['okres']) ?>"><?php endif; ?>
          </div>
          <?php if ($doc['typ_dokumentu'] === 'rachunek'): ?>
          <div class="col-sm-4">
            <label class="tw-block tw-text-xs tw-font-semibold tw-text-slate-600 tw-mb-1" for="e_umowa">Umowa nr (Rejestr Umów)</label>
            <?= edok_umowa_select_html('umowa_numer', 'e_umowa', (string)$doc['umowa_numer'], $locked_rachunkowa ? 'disabled' : '') ?>
            <?php if ($locked_rachunkowa): ?><input type="hidden" name="umowa_numer" value="<?= h($doc['umowa_numer']) ?>"><?php endif; ?>
          </div>
          <div class="col-sm-4">
            <label class="tw-block tw-text-xs tw-font-semibold tw-text-slate-600 tw-mb-1" for="e_do_wyplaty">Kwota do wypłaty</label>
            <input type="text" name="kwota_do_wyplaty" id="e_do_wyplaty" class="form-control form-control-sm font-monospace text-end" value="<?= h($doc['kwota_do_wyplaty']) ?>" placeholder="= brutto" <?= $locked_rachunkowa ? 'readonly' : '' ?>>
          </div>
          <div class="col-12 form-text mt-0">Kwota do wypłaty = brutto rachunku po potrąceniu zaliczki PIT i składek ZUS — na nią pójdzie przelew. Puste = brutto.</div>
          <?php endif; ?>
        </div>
        <?php endif; ?>
        <div class="tw-mb-2">
          <label class="tw-block tw-text-xs tw-font-semibold tw-text-slate-600 tw-mb-1">MPK</label>
          <input type="text" name="mpk" class="form-control form-control-sm" value="<?= h($doc['mpk']) ?>">
        </div>
        <div class="tw-mb-3">
          <label class="tw-block tw-text-xs tw-font-semibold tw-text-slate-600 tw-mb-1"><?= $doc['kierunek'] === 'przychod' ? 'Sugerowana referencja wpłaty' : 'Tytuł przelewu' ?></label>
          <div class="input-group input-group-sm">
            <input type="text" id="e_tytul" name="tytul_przelewu" class="form-control form-control-sm" maxlength="140" value="<?= h($doc['tytul_przelewu']) ?>">
            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="edokViewSuggestTytul()"><i class="bi bi-magic"></i> Generuj</button>
          </div>
        </div>
        <button type="submit" class="edok-btn edok-btn-primary"><i class="bi bi-save"></i> Zapisz</button>
        </div>
      </form>
    </div>
    <?php endif; ?>

    <?php if ($doc['typ_dokumentu'] === 'lista_plac' && $doc['kierunek'] === 'wydatek'):
      $wyplaty = edok_wyplaty($id);
      $can_edit_wyplaty = !$is_terminal && !$locked_rachunkowa && (is_admin() || edok_has_role('upload') || edok_has_role('dekretacja'));
      $suma_wyplat = array_sum(array_map(fn($w) => _edok_kwota_float($w['kwota']), $wyplaty));
    ?>
    <div class="edok-card tw-mt-4" id="wyplaty">
      <div class="edok-card__hd"><i class="bi bi-people"></i> Wypłaty z listy płac (<?= count($wyplaty) ?>)</div>
      <div class="edok-card__bd">
        <p class="tw-text-xs tw-text-slate-500 tw-mb-2">Każda pozycja to osobny przelew z tytułem „WYNAGRODZENIE <?= h(edok_okres_label($doc['okres']) ?: 'MM/RRRR') ?> - umowa nr …”. Kwota = do wypłaty (po potrąceniach).</p>
        <?php if ($wyplaty): ?>
        <div class="table-responsive">
        <table class="table table-sm align-middle mb-2" style="font-size:.82rem">
          <thead><tr><th>Osoba</th><th>Umowa</th><th>Rachunek</th><th class="text-end">Kwota</th><?php if ($can_edit_wyplaty): ?><th></th><?php endif; ?></tr></thead>
          <tbody>
          <?php foreach ($wyplaty as $w): ?>
            <tr>
              <td><?= h($w['osoba']) ?><?php if ($w['opis']): ?><div class="tw-text-xs tw-text-slate-500"><?= h($w['opis']) ?></div><?php endif; ?></td>
              <td class="tw-font-mono tw-text-xs">
                <?php if ($w['contract_type'] && $w['contract_id']): ?><a href="<?= APP_URL ?>/contracts/<?= h($w['contract_type']) ?>/view.php?id=<?= (int)$w['contract_id'] ?>"><?= h($w['umowa_numer'] ?: '—') ?></a><?php else: ?><?= h($w['umowa_numer'] ?: '—') ?><?php endif; ?>
              </td>
              <td class="tw-font-mono tw-text-xs"><?= h(edok_nrb_format($w['rachunek'])) ?></td>
              <td class="text-end tw-font-mono"><?= h($w['kwota']) ?></td>
              <?php if ($can_edit_wyplaty): ?>
              <td class="text-end">
                <form method="post" class="tw-inline" onsubmit="return confirm('Usunąć tę pozycję?');">
                  <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                  <input type="hidden" name="action" value="wyplata_delete">
                  <input type="hidden" name="wyplata_id" value="<?= (int)$w['id'] ?>">
                  <button class="btn btn-sm btn-link text-danger p-0" type="submit" aria-label="Usuń pozycję <?= h($w['osoba']) ?>"><i class="bi bi-trash"></i></button>
                </form>
              </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot><tr><th colspan="3">Razem do wypłaty</th><th class="text-end tw-font-mono"><?= number_format($suma_wyplat, 2, ',', ' ') ?></th><?php if ($can_edit_wyplaty): ?><th></th><?php endif; ?></tr></tfoot>
        </table>
        </div>
        <?php else: ?>
        <p class="tw-text-sm tw-text-amber-700"><i class="bi bi-exclamation-triangle"></i> Lista płac nie ma jeszcze pozycji — bez nich nie da się wyeksportować przelewów.</p>
        <?php endif; ?>

        <?php if ($can_edit_wyplaty):
          $do_ujecia = edok_rachunki_umow_do_wyplaty($doc['okres']);
        ?>
        <?php if ($do_ujecia): ?>
        <form method="post" class="tw-mt-3 tw-rounded-lg tw-border tw-border-solid tw-border-slate-200 tw-p-2">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="action" value="wyplaty_z_rachunkow">
          <div class="tw-text-xs tw-font-semibold tw-text-slate-600 tw-mb-1">Dodaj z rachunków do umów zlecenie<?= $doc['okres'] ? ' (' . h(edok_okres_label($doc['okres'])) . ')' : '' ?></div>
          <?php foreach ($do_ujecia as $r): $cid = 'zr_' . (int)$r['id']; ?>
          <div class="form-check tw-text-sm">
            <input type="checkbox" class="form-check-input" name="rachunek_ids[]" value="<?= (int)$r['id'] ?>" id="<?= $cid ?>" <?= strlen(preg_replace('/\D/', '', (string)$r['umowa_rachunek'])) === 26 ? '' : 'disabled' ?>>
            <label class="form-check-label" for="<?= $cid ?>">
              <?= h($r['imie_nazwisko']) ?> · <span class="tw-font-mono tw-text-xs"><?= h($r['umowa_rejestr']) ?></span> · rach. <?= h($r['numer'] ?: '#' . $r['id']) ?>
              · <?= $r['kwota_brutto'] !== null ? h(number_format((float)$r['kwota_brutto'], 2, ',', ' ')) : '—' ?> · <?= rachunek_status_badge($r['status']) ?>
              <?php if (strlen(preg_replace('/\D/', '', (string)$r['umowa_rachunek'])) !== 26): ?><span class="badge bg-danger">brak rachunku w umowie</span><?php endif; ?>
            </label>
          </div>
          <?php endforeach; ?>
          <div class="form-text">Kwota pozycji = brutto rachunku — popraw ją na kwotę do wypłaty, jeśli są potrącenia (usuń i dodaj ręcznie).</div>
          <button type="submit" class="btn btn-sm btn-outline-primary tw-mt-1"><i class="bi bi-plus-lg"></i> Dodaj zaznaczone</button>
        </form>
        <?php endif; ?>

        <form method="post" class="tw-mt-3 tw-rounded-lg tw-border tw-border-solid tw-border-slate-200 tw-p-2">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="action" value="wyplata_add">
          <input type="hidden" name="contract_type" id="w_ctype">
          <input type="hidden" name="contract_id" id="w_cid">
          <div class="tw-text-xs tw-font-semibold tw-text-slate-600 tw-mb-1">Dodaj pozycję ręcznie</div>
          <div class="row g-2">
            <div class="col-sm-6">
              <label class="form-label small mb-1" for="w_umowa">Umowa nr (Rejestr Umów)</label>
              <?= edok_umowa_select_html('umowa_numer', 'w_umowa', '', 'onchange="edokWyplataUmowa(this)"') ?>
            </div>
            <div class="col-sm-6">
              <label class="form-label small mb-1" for="w_osoba">Osoba</label>
              <input type="text" name="osoba" id="w_osoba" class="form-control form-control-sm" required>
            </div>
            <div class="col-sm-8">
              <label class="form-label small mb-1" for="w_rachunek">Rachunek</label>
              <input type="text" name="rachunek" id="w_rachunek" class="form-control form-control-sm font-monospace" required placeholder="26 cyfr">
            </div>
            <div class="col-sm-4">
              <label class="form-label small mb-1" for="w_kwota">Kwota do wypłaty</label>
              <input type="text" name="kwota" id="w_kwota" class="form-control form-control-sm font-monospace text-end" required>
            </div>
            <div class="col-12">
              <input type="text" name="opis" class="form-control form-control-sm" placeholder="Opis (opcjonalnie), np. wynagrodzenie zasadnicze">
            </div>
          </div>
          <button type="submit" class="btn btn-sm btn-outline-primary tw-mt-2"><i class="bi bi-plus-lg"></i> Dodaj</button>
        </form>
        <script>
        function edokWyplataUmowa(sel) {
          var o = sel.selectedOptions[0] || {dataset: {}};
          document.getElementById('w_ctype').value = o.value ? o.dataset.type : '';
          document.getElementById('w_cid').value = o.value ? o.dataset.id : '';
          if (!o.value) return;
          var os = document.getElementById('w_osoba'), r = document.getElementById('w_rachunek');
          if (!os.value) os.value = o.dataset.osoba || '';
          if (!r.value && o.dataset.rachunek) r.value = o.dataset.rachunek;
        }
        </script>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <div class="lg:tw-col-span-7">
    <!-- Śledzik postępu obiegu -->
    <div class="edok-tracker">
      <?php $n = 0; foreach ($steps_config as $sk => $cfg): $n++;
        $s = $doc['steps'][$sk] ?? null;
        $cls = 'is-pending';
        $icon = 'bi-circle';
        if ($s && $s['status'] === 'ok')        { $cls = 'is-done';     $icon = 'bi-check-circle-fill'; }
        elseif ($s && $s['status'] === 'odrzucono') { $cls = 'is-rejected'; $icon = 'bi-x-circle-fill'; }
        elseif ($s && $s['status'] === 'uwagi') { $cls = 'is-uwagi';    $icon = 'bi-exclamation-circle-fill'; }
        elseif ($sk === $current_key)           { $cls = 'is-current'; $icon = $cfg['icon']; }
        elseif (!$s)                            { $cls = 'is-blocked'; $icon = 'bi-lock-fill'; }
      ?>
      <div class="edok-tracker__step <?= $cls ?>" title="<?= h(edok_step_label($sk, $doc)) ?>">
        <span class="edok-tracker__num">ETAP <?= $n ?></span>
        <i class="bi <?= $icon ?> edok-tracker__icon"></i>
        <span class="edok-tracker__label tw-hidden sm:tw-block"><?= h(edok_step_label($sk, $doc)) ?></span>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- Bieżący etap -->
    <?php if ($is_terminal): ?>
    <div class="edok-card">
      <div class="edok-card__bd tw-text-center tw-py-6">
        <?php if ($doc['status'] === 'zaakceptowany'): ?>
        <i class="bi bi-check-circle-fill tw-text-5xl tw-text-emerald-500"></i>
        <p class="tw-mt-2 tw-font-semibold tw-text-slate-700">Obieg zakończony — dokument w pełni zaakceptowany.</p>
        <?php elseif ($doc['status'] === 'odrzucony'): ?>
        <i class="bi bi-x-circle-fill tw-text-5xl tw-text-red-500"></i>
        <p class="tw-mt-2 tw-font-semibold tw-text-slate-700">Dokument odrzucony.</p>
        <?php else: ?>
        <i class="bi bi-slash-circle tw-text-5xl tw-text-slate-400"></i>
        <p class="tw-mt-2 tw-font-semibold tw-text-slate-700">Dokument wycofany.</p>
        <?php endif; ?>
      </div>
    </div>
    <?php elseif ($current_key): ?>
    <div class="edok-card">
      <div class="edok-card__hd">
        <i class="bi <?= $current_cfg['icon'] ?>"></i> Bieżący etap: <?= h(edok_step_label($current_key, $doc)) ?>
        <span class="edok-badge edok-badge-secondary tw-ml-auto">Etap <?= array_search($current_key, array_keys($steps_config), true) + 1 ?>/5</span>
      </div>
      <div class="edok-card__bd">
        <p class="tw-text-sm tw-text-slate-500 tw-mb-3"><?= h($current_cfg['sub']) ?></p>

        <?php if ($current_blocked): ?>
        <div class="edok-alert edok-alert-warning"><i class="bi bi-lock-fill tw-mt-0.5"></i> <?= h($current_blocked) ?></div>

        <?php elseif (!edok_has_role($current_key)): ?>
        <div class="edok-alert edok-alert-warning"><i class="bi bi-person-x tw-mt-0.5"></i> Oczekuje na decyzję osoby z uprawnieniem do tego etapu.</div>

        <?php elseif (!edok_pin_is_set((int)$user['id'])): ?>
        <div class="edok-alert edok-alert-warning tw-mb-3">
          <i class="bi bi-shield-exclamation tw-mt-0.5"></i>
          Nie masz jeszcze ustawionego PIN-u EODoK — wymagany do zaakceptowania. <a href="<?= APP_URL ?>/edok/ustaw_pin.php" class="tw-font-semibold tw-underline">Ustaw PIN</a>.
        </div>

        <?php else: ?>
        <div class="tw-flex tw-flex-wrap tw-gap-2">
          <button type="button" class="edok-btn edok-btn-primary" data-bs-toggle="modal" data-bs-target="#stepWizardModal">
            <i class="bi bi-ui-checks"></i> Podejmij decyzję
          </button>
          <?php if ($show_sign_all): ?>
          <button type="button" class="edok-btn edok-btn-ghost" data-bs-toggle="modal" data-bs-target="#signAllModal">
            <i class="bi bi-check2-all"></i> Weryfikuj i podpisz wszystkie etapy (<?= count($sign_all_ok) ?>)
          </button>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Historia decyzji już podjętych -->
    <?php
    $decided_steps = array_filter($doc['steps'], fn($s) => in_array($s['status'], ['ok', 'uwagi', 'odrzucono'], true));
    if ($decided_steps):
    ?>
    <div class="edok-card">
      <div class="edok-card__hd"><i class="bi bi-list-check"></i> Decyzje podjęte</div>
      <div class="edok-card__bd tw-py-1">
        <?php foreach ($steps_config as $sk => $cfg): $s = $doc['steps'][$sk] ?? null; if (!$s || !in_array($s['status'], ['ok', 'uwagi', 'odrzucono'], true)) continue; ?>
        <div class="edok-history-item">
          <?php if ($s['status'] === 'ok'): ?><i class="bi bi-check-circle-fill tw-text-emerald-500 tw-mt-0.5"></i>
          <?php elseif ($s['status'] === 'odrzucono'): ?><i class="bi bi-x-circle-fill tw-text-red-500 tw-mt-0.5"></i>
          <?php else: ?><i class="bi bi-exclamation-circle-fill tw-text-amber-500 tw-mt-0.5"></i><?php endif; ?>
          <div class="tw-flex-1">
            <div><strong><?= h(edok_step_label($sk, $doc)) ?></strong> — <?= h($s['user_name']) ?><?= $s['user_role'] ? ' (' . h($s['user_role']) . ')' : '' ?></div>
            <div class="tw-text-xs tw-text-slate-500">
              <?= date_pl($s['decided_at']) ?> <?= date('H:i', strtotime($s['decided_at'])) ?>
              <?php if (($s['verify_method'] ?? '') === 'pin' && ($s['verify_result'] ?? '') === 'ok'): ?> · <i class="bi bi-shield-check"></i> PIN<?php endif; ?>
            </div>
            <?php if ($s['notes']): ?><div class="tw-text-sm tw-text-amber-700 tw-mt-0.5"><?= nl2br(h($s['notes'])) ?></div><?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php require_once __DIR__ . '/../includes/edok_bank.php';
      $bank_tx = edok_bank_for_doc($id); $can_bank = is_admin() || edok_has_role('upload') || edok_has_role('ksiegowy');
      $bank_cand = $can_bank && $doc['status'] !== 'odrzucony' && $doc['status'] !== 'wycofany' ? edok_bank_for_doc_candidates($doc) : []; ?>
    <?php if ($bank_tx || $bank_cand): ?>
    <div class="edok-card">
      <div class="edok-card__hd"><i class="bi bi-bank2"></i> Powiązanie z wyciągiem bankowym</div>
      <div class="edok-card__bd small">
        <?php foreach ($bank_tx as $bt): ?>
        <div class="d-flex align-items-center gap-2 mb-1"><span><?= h(date_pl($bt['data_waluty'])) ?> · <strong><?= $bt['znak'] === 'C' ? '+' : '−' ?><?= h(number_format((float)$bt['kwota'], 2, ',', ' ')) ?> <?= h($bt['waluta']) ?></strong>
          · <?= h($bt['kontrahent_nazwa'] ?: '—') ?> · wyciąg <?= h($bt['statement_no'] ?: '—') ?>, operacja <?= h($bt['numer_operacji']) ?></span>
          <?php if ($can_bank): ?><form method="post" onsubmit="return confirm('Odpiąć transakcję od dokumentu?');"><input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="bank_unlink"><input type="hidden" name="tx_id" value="<?= (int)$bt['id'] ?>"><button class="btn btn-sm btn-link text-danger p-0">odepnij</button></form><?php endif; ?></div>
        <?php endforeach; ?>
        <?php if ($bank_cand): ?>
        <form method="post" class="d-flex gap-2 align-items-center mt-2"><input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="bank_link">
          <select name="tx_id" class="form-select form-select-sm" style="max-width:520px" aria-label="Transakcja z wyciągu">
            <?php foreach ($bank_cand as $c): $t = $c['tx']; ?>
            <option value="<?= (int)$t['id'] ?>"><?= h(date_pl($t['data_waluty'])) ?> · <?= h(number_format((float)$t['kwota'], 2, ',', ' ')) ?> · <?= h(mb_substr($t['kontrahent_nazwa'] ?: '—', 0, 30)) ?> · <?= h(mb_substr($t['tytul'], 0, 50)) ?><?= $c['score'] >= 50 ? ' ★' : '' ?></option>
            <?php endforeach; ?>
          </select>
          <button class="btn btn-sm btn-outline-primary text-nowrap"><i class="bi bi-link-45deg"></i> Powiąż z wyciągiem</button>
        </form>
        <div class="form-text">Lista niepowiązanych transakcji z zaimportowanych wyciągów (★ = pasuje kwota / numer). <a href="<?= APP_URL ?>/edok/mt940_import.php">Wgraj wyciąg</a></div>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Historia / audyt -->
    <div class="edok-card">
      <div class="edok-card__hd"><i class="bi bi-clock-history"></i> Historia obiegu (audyt)</div>
      <div class="edok-card__bd tw-p-0">
        <div class="table-responsive">
          <table class="table table-sm mb-0 small">
            <thead><tr><th>Kiedy</th><th>Kto</th><th>Zdarzenie</th></tr></thead>
            <tbody>
              <?php foreach (edok_events($id) as $ev): ?>
              <tr>
                <td class="text-nowrap font-monospace"><?= date_pl($ev['created_at']) ?> <?= date('H:i', strtotime($ev['created_at'])) ?></td>
                <td><?= h($ev['actor_name']) ?><?= $ev['actor_role'] ? ' <span class="text-muted">(' . h($ev['actor_role']) . ')</span>' : '' ?></td>
                <td><?= h($ev['comment']) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>
</div><!-- /.edok-page -->

<?php if (edok_has_unlock_perm()): ?>
<div class="modal fade" id="unlockModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="unlock_doc">
        <div class="modal-header"><h5 class="modal-title">Cofnij decyzję</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <p class="small text-muted">Resetuje wszystkie etapy i wznawia obieg od etapu 1. Wymaga uzasadnienia (zapisywane w audycie).</p>
          <textarea name="unlock_reason" class="form-control" rows="2" required placeholder="Powód cofnięcia…"></textarea>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="btn btn-warning">Cofnij decyzję</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($can_export_przelew): ?>
<div class="modal fade" id="przelewExportModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post" action="<?= APP_URL ?>/edok/preliminarz.php">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="export_przelewy">
        <input type="hidden" name="pakiet_ids" value="<?= $id ?>">
        <input type="hidden" name="rachunek_map" value="{}">
        <div class="modal-header"><h5 class="modal-title"><i class="bi bi-bank"></i> Eksport przelewu</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <p class="small text-muted mb-3">
            <?php $pd = edok_przelew_rows($doc)[0] ?? edok_przelew_doc($doc); ?>
            <?php if ($doc['typ_dokumentu'] === 'lista_plac'): $wr = edok_przelew_rows($doc); ?>
            <span class="badge bg-info text-dark">Lista płac</span> <?= count($wr) ?> przelewów ·
            <strong><?= number_format(array_sum(array_map(fn($x) => _edok_kwota_float((string)$x['kwota_brutto']), $wr)), 2, ',', ' ') ?> <?= h($doc['waluta'] ?? 'PLN') ?></strong>
            <?php else: ?>
            <?= !empty($pd['_zwrot']) ? '<span class="badge bg-warning text-dark">Zwrot kosztów</span> ' : '' ?>
            <?= h($pd['kontrahent_nazwa'] ?? '') ?> · <span class="font-monospace"><?= h(edok_nrb_format(preg_replace('/\D/', '', (string)$pd['rachunek_bankowy']))) ?></span>
            · <strong><?= h($pd['kwota_brutto']) ?> <?= h($doc['waluta'] ?? 'PLN') ?></strong>
            <div class="small">Tytuł: <span class="font-monospace"><?= h(edok_generate_tytul_przelewu($pd)) ?></span></div>
            <?php endif; ?>
          </p>
          <label class="form-label small fw-semibold" for="przelew_rachunek">Z rachunku</label>
          <select name="rachunek_zlecen" id="przelew_rachunek" class="form-select form-select-sm mb-3" required>
            <?php foreach ($przelew_rachunki as $r): ?>
            <option value="<?= h($r['nrb']) ?>"><?= h($r['nazwa'] ?: $r['bank']) ?> (…<?= h(substr(preg_replace('/\D/', '', $r['nrb']), -4)) ?>)</option>
            <?php endforeach; ?>
          </select>
          <label class="form-label small fw-semibold" for="przelew_format">Format pliku</label>
          <select name="format" id="przelew_format" class="form-select form-select-sm">
            <?php foreach (EDOK_PRZELEWY_FORMATY as $k => $label): ?>
            <option value="<?= h($k) ?>"><?= h($label) ?></option>
            <?php endforeach; ?>
          </select>
          <p class="small text-muted mt-2 mb-0">Plik ELIXIR-O do zaimportowania w bankowości — zweryfikuj przelew przed skierowaniem do realizacji. Przy pierwszym przelewie do kontrahenta pojawi się prośba o potwierdzenie NIP i rachunku.</p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="btn btn-success"><i class="bi bi-download"></i> Pobierz plik</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($current_key && $current_can_act && edok_pin_is_set((int)$user['id'])): ?>
<!-- Kreator decyzji: jeden modal, dwa "panele" (decyzja → PIN dla "Tak/OK") — patrz edokWizard* w skrypcie niżej. -->
<div class="modal fade edok-wizard" id="stepWizardModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header edok-wizard-hd">
        <h6 class="modal-title tw-font-bold"><i class="bi <?= $current_cfg['icon'] ?>"></i> <?= h(edok_step_label($current_key, $doc)) ?></h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="post" id="wizardForm" onsubmit="return edokWizardSubmit(event, this)">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="<?= h($current_key) ?>">
        <input type="hidden" name="step_pin" id="wizardPin">

        <div class="edok-wizard-pane" id="wizardPaneDecision">
          <?php if ($current_review): ?>
          <table class="edok-kv tw-mb-3">
            <tbody>
              <?php foreach ($current_review as [$label, $value]): ?>
              <tr><td><?= h($label) ?></td><td><?= $value ?></td></tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <?php endif; ?>

          <?php if ($current_key === 'rachunkowa'):
            $netto  = (float) str_replace(',', '.', str_replace(' ', '', (string)$doc['kwota_netto']));
            $vat    = (float) str_replace(',', '.', str_replace(' ', '', (string)$doc['kwota_vat']));
            $brutto = (float) str_replace(',', '.', str_replace(' ', '', (string)$doc['kwota_brutto']));
            $ok_sum = $brutto <= 0 || abs(($netto + $vat) - $brutto) <= 0.01;
          ?>
          <div class="edok-alert <?= $ok_sum ? 'tw-bg-emerald-50 tw-border-emerald-200 tw-text-emerald-700' : 'edok-alert-danger' ?> tw-mb-3">
            <i class="bi <?= $ok_sum ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill' ?> tw-mt-0.5"></i>
            <?= $ok_sum ? 'Netto + VAT zgadza się z kwotą brutto.' : 'Netto + VAT NIE zgadza się z kwotą brutto — popraw dane dokumentu przed akceptacją.' ?>
          </div>
          <?php endif; ?>

          <?php if ($current_pending): ?>
          <div class="edok-alert edok-alert-warning tw-mb-3">
            <i class="bi bi-exclamation-triangle tw-mt-0.5"></i>
            <ul class="tw-mb-0 tw-pl-4"><?php foreach ($current_pending as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
          </div>
          <?php endif; ?>

          <div class="tw-flex tw-gap-2 tw-mb-3">
            <label class="edok-choice choice-ok">
              <input type="radio" name="step_status" value="ok" required>
              <i class="bi bi-check-circle-fill tw-text-xl"></i> Tak / OK
            </label>
            <label class="edok-choice choice-uwagi">
              <input type="radio" name="step_status" value="uwagi">
              <i class="bi bi-exclamation-circle-fill tw-text-xl"></i> Z uwagami
            </label>
            <label class="edok-choice choice-odrzuc">
              <input type="radio" name="step_status" value="odrzucono">
              <i class="bi bi-x-circle-fill tw-text-xl"></i> Odrzuć
            </label>
          </div>
          <textarea name="step_notes" class="form-control form-control-sm" rows="2" placeholder="Ewentualne uwagi lub powód odrzucenia…"></textarea>
        </div>

        <div class="edok-wizard-pane tw-hidden" id="wizardPanePin">
          <p class="tw-text-sm tw-text-slate-500 tw-mb-2">Akceptacja ("Tak/OK") wymaga weryfikacji tożsamości PIN-em EODoK.</p>
          <input type="password" id="wizardPinInput" class="edok-pin-input" inputmode="numeric" pattern="\d{6}" maxlength="6" autocomplete="off" placeholder="••••••">
        </div>

        <div class="tw-flex tw-justify-end tw-gap-2 tw-px-4 tw-pb-4">
          <button type="button" class="edok-btn edok-btn-ghost" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="edok-btn edok-btn-primary" id="wizardNextBtn"><i class="bi bi-arrow-right"></i> Dalej</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($show_sign_all): ?>
<!-- Weryfikuj i podpisz wszystkie etapy: lista etapów + oświadczenie + jeden PIN -->
<div class="modal fade edok-wizard" id="signAllModal" tabindex="-1" aria-hidden="true" aria-labelledby="signAllTitle">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header edok-wizard-hd">
        <h6 class="modal-title tw-font-bold" id="signAllTitle"><i class="bi bi-check2-all"></i> Weryfikuj i podpisz wszystkie etapy</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
      </div>
      <form method="post" id="signAllForm" class="tw-px-4 tw-pt-3 tw-pb-4">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="sign_all">

        <p class="tw-text-sm tw-text-slate-600 tw-mb-2">Zostaną zaakceptowane („Tak/OK”) i podpisane Twoim PIN-em — każdy osobno, w tej kolejności:</p>
        <ol class="tw-text-sm tw-mb-3 tw-pl-5">
          <?php foreach ($sign_all_plan as $p): ?>
          <li class="tw-mb-1">
            <?php if ($p['errors']): ?>
            <span class="tw-text-slate-400"><?= h($p['label']) ?> — <strong>nie zostanie podpisany</strong>:</span>
            <ul class="tw-text-amber-700 tw-pl-4"><?php foreach ($p['errors'] as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
            <?php else: ?>
            <i class="bi bi-check-circle-fill tw-text-emerald-600" aria-hidden="true"></i> <?= h($p['label']) ?>
            <?php endif; ?>
          </li>
          <?php endforeach; ?>
        </ol>
        <?php
          $sa_last = end($sign_all_plan);
          $sa_rest = array_slice(edok_step_order(), array_search($sa_last['key'], edok_step_order(), true) + ($sa_last['errors'] ? 0 : 1));
        ?>
        <?php if ($sa_rest): ?>
        <p class="tw-text-xs tw-text-slate-500 tw-mb-3">Pozostałe etapy (<?= h(implode(', ', array_map(fn($k) => edok_step_label($k, $doc), $sa_rest))) ?>) — decyzja innej osoby lub po uzupełnieniu danych.</p>
        <?php endif; ?>

        <label class="tw-flex tw-gap-2 tw-items-start tw-text-sm tw-mb-3">
          <input type="checkbox" name="sign_all_confirm" value="1" required class="tw-mt-1">
          <span>Oświadczam, że zweryfikowałem(-am) dokument w zakresie każdego z wymienionych etapów.</span>
        </label>
        <label for="signAllNotes" class="tw-text-xs tw-text-slate-500">Uwagi (opcjonalnie, trafią do każdego etapu)</label>
        <textarea name="step_notes" id="signAllNotes" class="form-control form-control-sm tw-mb-3" rows="2"></textarea>

        <label for="signAllPin" class="tw-text-sm tw-text-slate-600">PIN EODoK</label>
        <input type="password" name="step_pin" id="signAllPin" class="edok-pin-input tw-mb-3" inputmode="numeric"
               pattern="\d{6}" maxlength="6" autocomplete="off" placeholder="••••••" required>

        <div class="tw-flex tw-justify-end tw-gap-2">
          <button type="button" class="edok-btn edok-btn-ghost" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="edok-btn edok-btn-primary"><i class="bi bi-pen"></i> Podpisz <?= count($sign_all_ok) ?> <?= count($sign_all_ok) >= 5 ? 'etapów' : 'etapy' ?></button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
(function () {
  var modalEl = document.getElementById('stepWizardModal');
  if (!modalEl) return;
  var modal = window.bootstrap ? new bootstrap.Modal(modalEl) : null;
  var paneDecision = document.getElementById('wizardPaneDecision');
  var panePin      = document.getElementById('wizardPanePin');
  var pinInput      = document.getElementById('wizardPinInput');
  var pinHidden     = document.getElementById('wizardPin');
  var nextBtn        = document.getElementById('wizardNextBtn');
  var form          = document.getElementById('wizardForm');

  function resetWizard() {
    paneDecision.classList.remove('tw-hidden');
    panePin.classList.add('tw-hidden');
    nextBtn.innerHTML = '<i class="bi bi-arrow-right"></i> Dalej';
    if (pinInput) { pinInput.value = ''; pinInput.classList.remove('is-invalid'); }
  }
  modalEl.addEventListener('shown.bs.modal', resetWizard);

  window.edokWizardSubmit = function (event, f) {
    var status = (f.querySelector('input[name="step_status"]:checked') || {}).value;
    if (!status) { event.preventDefault(); return false; }
    if (status !== 'ok') return true; // "Z uwagami"/"Odrzuć" — bez PIN, wysyła się normalnie

    var onPinPane = !panePin.classList.contains('tw-hidden');
    if (!onPinPane) {
      event.preventDefault();
      paneDecision.classList.add('tw-hidden');
      panePin.classList.remove('tw-hidden');
      nextBtn.innerHTML = '<i class="bi bi-check2"></i> Potwierdź';
      setTimeout(function () { pinInput.focus(); }, 80);
      return false;
    }
    var pin = pinInput.value.trim();
    if (!/^\d{6}$/.test(pin)) {
      event.preventDefault();
      pinInput.classList.add('is-invalid');
      pinInput.focus();
      return false;
    }
    pinHidden.value = pin;
    return true; // wysyła formularz normalnie
  };
})();

function edokViewRecalc() {
  var n = document.getElementById('e_netto'), v = document.getElementById('e_vat'), b = document.getElementById('e_brutto');
  if (!n || !v || !b) return;
  var netto = parseFloat((n.value || '0').replace(',', '.').replace(/\s/g, '')) || 0;
  var vat   = parseFloat((v.value || '0').replace(',', '.').replace(/\s/g, '')) || 0;
  if (netto + vat !== 0) b.value = (netto + vat).toFixed(2).replace('.', ',');
}

// Ten sam wzorzec co edok_generate_tytul_przelewu() w PHP (Uchwała 5/2026 §2 pkt 8-9) —
// numer EODoK i typ dokumentu są tu stałe (nieedytowalne w tym formularzu), numer
// faktury i opis brane z pól edycji (opis skracany do 60 znaków tak jak w PHP).
var EDOK_NUMBER_VIEW       = <?= json_encode($doc['number'], JSON_UNESCAPED_UNICODE) ?>;
var EDOK_TYP_KEY_VIEW      = <?= json_encode($doc['typ_dokumentu'], JSON_UNESCAPED_UNICODE) ?>;
var EDOK_TYP_LABEL_VIEW    = <?= json_encode(EDOK_TYPES[$doc['typ_dokumentu']] ?? $doc['typ_dokumentu'], JSON_UNESCAPED_UNICODE) ?>;
var EDOK_KIERUNEK_VIEW     = <?= json_encode($doc['kierunek'] ?? 'wydatek', JSON_UNESCAPED_UNICODE) ?>;
var EDOK_WALUTA_VIEW       = <?= json_encode($doc['waluta'] ?: 'PLN', JSON_UNESCAPED_UNICODE) ?>;
var EDOK_FAKTURA_TYPES_VIEW = ['faktura_vat', 'faktura_korygujaca'];
function edokViewSuggestTytul() {
  if (EDOK_KIERUNEK_VIEW === 'wydatek' && (EDOK_TYP_KEY_VIEW === 'lista_plac' || (EDOK_TYP_KEY_VIEW === 'rachunek' && document.getElementById('e_umowa') && document.getElementById('e_umowa').value.trim()))) {
    var ok = (document.getElementById('e_okres') || {}).value || '';
    var okres = /^\d{4}-\d{2}$/.test(ok) ? ok.substring(5, 7) + '/' + ok.substring(0, 4) : '';
    var um = document.getElementById('e_umowa') ? document.getElementById('e_umowa').value.trim() : '';
    document.getElementById('e_tytul').value = EDOK_TYP_KEY_VIEW === 'lista_plac'
      ? ('WYNAGRODZENIA ' + okres).trim()
      : ('WYNAGRODZENIE ' + okres).trim() + ' - umowa nr ' + um;
    return;
  }
  var nrField = document.querySelector('#metaForm [name="nr_faktury"]');
  var opisField = document.querySelector('#metaForm [name="description"]');
  var bruttoField = document.getElementById('e_brutto');
  var nr = nrField ? nrField.value.trim() : '';
  var opis = (opisField ? opisField.value : '').trim().replace(/\s+/g, ' ');
  if (opis.length > 60) opis = opis.substring(0, 60) + '...';
  if (!opis) opis = EDOK_TYP_LABEL_VIEW;
  var jestPrzychod = EDOK_KIERUNEK_VIEW === 'przychod';
  var jestFaktura = !jestPrzychod && EDOK_FAKTURA_TYPES_VIEW.indexOf(EDOK_TYP_KEY_VIEW) !== -1 && nr !== '';
  var ident = jestFaktura ? ('FAK: ' + nr) : ('DOK: ' + (EDOK_TYP_LABEL_VIEW + (nr ? ' ' + nr : '')).trim());
  var kwota = bruttoField ? bruttoField.value.trim() : '';
  var t = (jestPrzychod ? 'PRZYCHÓD: ' : 'PŁATNOŚĆ: ') + opis + ' - ' + ident + (EDOK_NUMBER_VIEW ? (' - AKC: ' + EDOK_NUMBER_VIEW) : '') + (kwota ? (' - ' + kwota + ' ' + EDOK_WALUTA_VIEW) : '');
  document.getElementById('e_tytul').value = t.trim().substring(0, 140);
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
