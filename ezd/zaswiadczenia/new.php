<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd.php';
require_once dirname(dirname(__DIR__)) . '/includes/zaswiadczenia_ezd.php';
require_login();
require_module_enabled('ezd_enabled', 'Moduł EZD Wirtualne biurko');
ezd_require_access();

// ── AJAX: wyszukiwanie umów i import danych do formularza zaświadczenia ────────
if (isset($_GET['_ajax'])) {
    header('Content-Type: application/json; charset=utf-8');

    $_sfmt    = fn($d) => $d ? date('d.m.Y', strtotime((string)$d)) : '';
    $_bezterm = fn(array $c) => !empty($c['bezterminowa']) || !empty($c['czas_nieokreslony']);
    $_byl_jest = fn(string $st) =>
        in_array($st, ['podpisana','w realizacji','obowiązująca','aktywna','w trakcie'], true)
            ? 'jest' : 'był/była';
    $_tlab = ['wolontariat'=>'Porozumienie wolontariackie','zlecenie'=>'Umowy zlecenie',
              'praca'=>'Umowy o pracę','dzielo'=>'Umowy o dzieło',
              'uslugi'=>'Umowy o świadczenie usług','inne'=>'Innej umowy','powierzenie'=>'Innej umowy'];
    $_st_umowy = fn(string $st) => [
        'podpisana'=>'aktywna','w realizacji'=>'aktywna','obowiązująca'=>'aktywna',
        'aktywna'=>'aktywna','w trakcie'=>'aktywna','zawieszona'=>'zawieszona',
        'zakończona'=>'zakończona','rozwiązana'=>'rozwiązana',
    ][$st] ?? '';

    if (($_GET['_ajax'] ?? '') === 'search_contracts') {
        $ctype = preg_replace('/[^a-z]/', '', strtolower($_GET['type'] ?? ''));
        $q     = trim($_GET['q'] ?? '');
        if (!$ctype || strlen($q) < 2 || !array_key_exists($ctype, CONTRACT_TYPES)) {
            echo json_encode(['results' => []]); exit;
        }
        $like  = '%' . $q . '%';
        $tbl   = table_for_type($ctype);
        // Kolumna z nazwą różni się per typ
        $name_expr = match ($ctype) {
            'uslugi'      => "COALESCE(imie_nazwisko, nazwa_wykonawcy, '')",
            'inne',
            'powierzenie' => "COALESCE(imie_nazwisko, strona_umowy, '')",
            default       => "COALESCE(imie_nazwisko, '')",
        };
        try {
            $rows = db_all(
                "SELECT id, {$name_expr} AS display_name, COALESCE(numer_umowy,'') AS numer_umowy, status
                 FROM {$tbl}
                 WHERE ({$name_expr} LIKE ? OR numer_umowy LIKE ?)
                 ORDER BY id DESC LIMIT 20",
                [$like, $like]
            );
            echo json_encode(['results' => $rows]);
        } catch (\Throwable $e) {
            echo json_encode(['results' => [], 'error' => $e->getMessage()]);
        }
        exit;
    }

    if (($_GET['_ajax'] ?? '') === 'import') {
        $ctype = preg_replace('/[^a-z]/', '', strtolower($_GET['contract_type'] ?? ''));
        $pid   = (int)($_GET['contract_id'] ?? 0);
        if (!$ctype || !$pid || !array_key_exists($ctype, CONTRACT_TYPES)) {
            echo json_encode(['ok' => false, 'error' => 'Nieprawidłowe parametry.']); exit;
        }
        $vals = [];
        try {
            if ($ctype === 'wolontariat') {
                $_c = db_one(
                    "SELECT w.imie_nazwisko, w.numer_umowy, w.data_zawarcia,
                            w.data_rozpoczecia, w.data_zakonczenia,
                            COALESCE(w.bezterminowa,0) AS bezterminowa,
                            w.status, COALESCE(w.email,'') AS email,
                            COALESCE(w.miejsce_wolontariatu,'') AS miejsce,
                            COALESCE(w.wolontariat_typ,'') AS wolontariat_typ,
                            COALESCE(w.przedmiot_porozumienia,'') AS przedmiot_porozumienia,
                            COALESCE(p.name,'') AS stanowisko_name
                     FROM umowy_wolontariat w
                     LEFT JOIN org_positions p ON p.id=w.org_position_id
                     WHERE w.id=?", [$pid]
                );
                if ($_c) $vals = [
                    '_email'        => $_c['email'],
                    '_name'         => $_c['imie_nazwisko'],
                    'byl_jest'      => $_byl_jest($_c['status'] ?? ''),
                    'imie_nazwisko' => $_c['imie_nazwisko'] ?? '',
                    'numer_umowy'   => $_c['numer_umowy'] ?? '',
                    'data_zawarcia' => $_sfmt($_c['data_zawarcia']),
                    'data_od'       => $_sfmt($_c['data_rozpoczecia']),
                    'data_do'       => $_bezterm($_c) ? 'bezterminowo' : $_sfmt($_c['data_zakonczenia']),
                    'zakres_dzialan'=> $_c['przedmiot_porozumienia'] ?: ($_c['wolontariat_typ'] ?? ''),
                    'typ_umowy'     => 'Porozumienie wolontariackie',
                    'stanowisko'    => $_c['stanowisko_name'] ?: '',
                    'miejsce'       => $_c['miejsce'] ?? '',
                    'status_umowy'  => $_st_umowy($_c['status'] ?? ''),
                ];
            } elseif ($ctype === 'praca') {
                $_c = db_one(
                    "SELECT imie_nazwisko, numer_umowy, data_zawarcia, data_rozpoczecia, data_zakonczenia,
                            COALESCE(czas_nieokreslony,0) AS czas_nieokreslony, status,
                            COALESCE(stanowisko,'') AS stanowisko, COALESCE(email,'') AS email
                     FROM umowy_praca WHERE id=?", [$pid]
                );
                if ($_c) $vals = [
                    '_email'        => $_c['email'],
                    '_name'         => $_c['imie_nazwisko'],
                    'byl_jest'      => $_byl_jest($_c['status'] ?? ''),
                    'imie_nazwisko' => $_c['imie_nazwisko'] ?? '',
                    'typ_umowy'     => 'Umowy o pracę',
                    'numer_umowy'   => $_c['numer_umowy'] ?? '',
                    'data_zawarcia' => $_sfmt($_c['data_zawarcia']),
                    'data_od'       => $_sfmt($_c['data_rozpoczecia']),
                    'data_do'       => !empty($_c['czas_nieokreslony']) ? 'bezterminowo' : $_sfmt($_c['data_zakonczenia']),
                    'stanowisko'    => $_c['stanowisko'] ?? '',
                    'status_umowy'  => $_st_umowy($_c['status'] ?? ''),
                ];
            } else {
                $_tables = ['zlecenie'=>'umowy_zlecenie','uslugi'=>'umowy_uslugi',
                            'dzielo'=>'umowy_dzielo','inne'=>'umowy_inne','powierzenie'=>'umowy_inne'];
                $_ptable = $_tables[$ctype] ?? null;
                if ($_ptable) {
                    $_nc = match ($ctype) {
                        'uslugi'      => "COALESCE(imie_nazwisko, nazwa_wykonawcy, '') AS imie_nazwisko",
                        'inne',
                        'powierzenie' => "COALESCE(imie_nazwisko, strona_umowy, '') AS imie_nazwisko",
                        default       => "COALESCE(imie_nazwisko, '') AS imie_nazwisko",
                    };
                    $_pc = match ($ctype) {
                        'zlecenie' => 'przedmiot_zlecenia',
                        'uslugi'   => 'przedmiot_uslugi',
                        'dzielo'   => 'opis_dziela',
                        default    => 'przedmiot_umowy',
                    };
                    $_btc = in_array($ctype, ['inne','powierzenie'])
                        ? 'COALESCE(czas_nieokreslony,0) AS bezterminowa'
                        : 'COALESCE(bezterminowa,0) AS bezterminowa';
                    $_c = db_one(
                        "SELECT {$_nc}, numer_umowy, data_zawarcia, data_rozpoczecia, data_zakonczenia,
                                {$_btc}, status, COALESCE(email,'') AS email,
                                COALESCE({$_pc},'') AS przedmiot
                         FROM {$_ptable} WHERE id=?", [$pid]
                    );
                    if ($_c) $vals = [
                        '_email'        => $_c['email'],
                        '_name'         => $_c['imie_nazwisko'],
                        'byl_jest'      => $_byl_jest($_c['status'] ?? ''),
                        'imie_nazwisko' => $_c['imie_nazwisko'] ?? '',
                        'typ_umowy'     => $_tlab[$ctype] ?? 'Innej umowy',
                        'numer_umowy'   => $_c['numer_umowy'] ?? '',
                        'data_zawarcia' => $_sfmt($_c['data_zawarcia']),
                        'data_od'       => $_sfmt($_c['data_rozpoczecia']),
                        'data_do'       => !empty($_c['bezterminowa']) ? 'bezterminowo' : $_sfmt($_c['data_zakonczenia']),
                        'przedmiot'     => $_c['przedmiot'] ?? '',
                        'status_umowy'  => $_st_umowy($_c['status'] ?? ''),
                    ];
                }
            }
        } catch (\Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]); exit;
        }
        echo json_encode(['ok' => true, 'vals' => $vals, 'contract_type' => $ctype, 'contract_id' => $pid]);
        exit;
    }

    echo json_encode(['error' => 'unknown']);
    exit;
}
// ── /AJAX ─────────────────────────────────────────────────────────────────────

$user_id = (int)current_user()['id'];
$user    = current_user();

// Krok 1: wybór typu (jeśli brak ?typ_id)
$typ_id  = (int)($_GET['typ_id'] ?? $_POST['typ_id'] ?? 0);
$typy    = ezd_zas_typy_all(true);

// Prefill z umowy: ?prefill_type=wolontariat&prefill_id=NNN
$prefill_vals   = [];
$_prefill_ctype = '';  // contract_type do zapisania przy tworzeniu
$_prefill_cid   = 0;  // contract_id do zapisania przy tworzeniu
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_ptype = preg_replace('/[^a-z]/', '', strtolower($_GET['prefill_type'] ?? ''));
    $_pid   = (int)($_GET['prefill_id'] ?? 0);
    if ($_ptype && $_pid) {
        // Mapowanie: typ umowy → kod typu zaświadczenia EZD
        $_ct_map = [
            'wolontariat' => 'zaswiadczenie_wolontariat',
            'praca'       => 'zaswiadczenie_zatrudnienie',
            'zlecenie'    => 'zaswiadczenie_wspolpraca',
            'dzielo'      => 'zaswiadczenie_wspolpraca',
            'uslugi'      => 'zaswiadczenie_wspolpraca',
            'inne'        => 'zaswiadczenie_wspolpraca',
            'powierzenie' => 'zaswiadczenie_wspolpraca',
        ];
        $_preferred_kod = $_ct_map[$_ptype] ?? 'zaswiadczenie_umowy';

        if (!$typ_id) {
            $_zr = db_one("SELECT id FROM ezd_zas_typy WHERE kod=? AND is_active=1", [$_preferred_kod]);
            if (!$_zr) $_zr = db_one("SELECT id FROM ezd_zas_typy WHERE kod='zaswiadczenie_umowy' AND is_active=1");
            if ($_zr) $typ_id = (int)$_zr['id'];
        }

        $_prefill_ctype = $_ptype;
        $_prefill_cid   = $_pid;

        $_sfmt    = fn($d) => $d ? date('d.m.Y', strtotime((string)$d)) : '';
        $_bezterm = fn(array $c) => !empty($c['bezterminowa']) || !empty($c['czas_nieokreslony']);
        $_tlab    = ['wolontariat'=>'Porozumienie wolontariackie','zlecenie'=>'Umowy zlecenie',
                     'praca'=>'Umowy o pracę','dzielo'=>'Umowy o dzieło',
                     'uslugi'=>'Umowy o świadczenie usług','inne'=>'Innej umowy','powierzenie'=>'Innej umowy'];
        // Aktywne statusy → "jest", zakończone → "był/była"
        $_byl_jest = fn(string $st) =>
            in_array($st, ['podpisana','w realizacji','obowiązująca','aktywna','w trakcie'], true)
                ? 'jest' : 'był/była';
        // Status umowy → etykieta dla pola status_umowy (ZAS-UM)
        $_st_umowy = fn(string $st) => [
            'podpisana'    => 'aktywna', 'w realizacji' => 'aktywna',
            'obowiązująca' => 'aktywna', 'aktywna'      => 'aktywna',
            'w trakcie'    => 'aktywna', 'zawieszona'   => 'zawieszona',
            'zakończona'   => 'zakończona', 'rozwiązana' => 'rozwiązana',
        ][$st] ?? '';

        try {
            if ($_ptype === 'wolontariat') {
                $_c = db_one(
                    "SELECT w.imie_nazwisko, w.numer_umowy, w.data_zawarcia,
                            w.data_rozpoczecia, w.data_zakonczenia,
                            COALESCE(w.bezterminowa,0) AS bezterminowa,
                            w.status,
                            COALESCE(w.email,'') AS email,
                            COALESCE(w.miejsce_wolontariatu,'') AS miejsce,
                            COALESCE(w.wolontariat_typ,'') AS wolontariat_typ,
                            COALESCE(w.przedmiot_porozumienia,'') AS przedmiot_porozumienia,
                            COALESCE(p.name,'') AS stanowisko_name
                     FROM umowy_wolontariat w
                     LEFT JOIN org_positions p ON p.id = w.org_position_id
                     WHERE w.id=?",
                    [$_pid]
                );
                if ($_c) {
                    $prefill_vals = [
                        '_email'         => $_c['email'],
                        'byl_jest'       => $_byl_jest($_c['status'] ?? ''),
                        // typ wolontariat
                        'imie_nazwisko'  => $_c['imie_nazwisko'] ?? '',
                        'numer_umowy'    => $_c['numer_umowy'] ?? '',
                        'data_zawarcia'  => $_sfmt($_c['data_zawarcia']),
                        'data_od'        => $_sfmt($_c['data_rozpoczecia']),
                        'data_do'        => $_bezterm($_c) ? 'bezterminowo' : $_sfmt($_c['data_zakonczenia']),
                        'zakres_dzialan' => $_c['przedmiot_porozumienia'] ?: ($_c['wolontariat_typ'] ?? ''),
                        // typ ogólny
                        'typ_umowy'      => 'Porozumienie wolontariackie',
                        'stanowisko'     => $_c['stanowisko_name'] ?: ($_c['wolontariat_typ'] ?? ''),
                        'miejsce'        => $_c['miejsce'] ?? '',
                        'status_umowy'   => $_st_umowy($_c['status'] ?? ''),
                    ];
                }
            } elseif ($_ptype === 'praca') {
                $_c = db_one(
                    "SELECT imie_nazwisko, numer_umowy, data_zawarcia, data_rozpoczecia, data_zakonczenia,
                            COALESCE(czas_nieokreslony,0) AS czas_nieokreslony, status,
                            COALESCE(stanowisko,'') AS stanowisko,
                            COALESCE(email,'') AS email
                     FROM umowy_praca WHERE id=?", [$_pid]
                );
                if ($_c) {
                    $prefill_vals = [
                        '_email'        => $_c['email'],
                        'byl_jest'      => $_byl_jest($_c['status'] ?? ''),
                        'imie_nazwisko' => $_c['imie_nazwisko'] ?? '',
                        'typ_umowy'     => 'Umowa o pracę',
                        'numer_umowy'   => $_c['numer_umowy'] ?? '',
                        'data_zawarcia' => $_sfmt($_c['data_zawarcia']),
                        'data_od'       => $_sfmt($_c['data_rozpoczecia']),
                        'data_do'       => !empty($_c['czas_nieokreslony']) ? 'bezterminowo' : $_sfmt($_c['data_zakonczenia']),
                        'stanowisko'    => $_c['stanowisko'] ?? '',
                        'miejsce'       => '',
                        'status_umowy'  => $_st_umowy($_c['status'] ?? ''),
                    ];
                }
            } elseif ($_ptype === 'dzielo') {
                // dzielo: brak data_rozpoczecia, przedmiot w opis_dziela
                $_c = db_one(
                    "SELECT imie_nazwisko, numer_umowy, data_zawarcia,
                            COALESCE(data_zakonczenia, termin_oddania,'') AS data_zakonczenia,
                            COALESCE(bezterminowa,0) AS bezterminowa, status,
                            COALESCE(email,'') AS email,
                            COALESCE(opis_dziela,'') AS przedmiot
                     FROM umowy_dzielo WHERE id=?", [$_pid]
                );
                if ($_c) {
                    $prefill_vals = [
                        '_email'        => $_c['email'],
                        'byl_jest'      => $_byl_jest($_c['status'] ?? ''),
                        'imie_nazwisko' => $_c['imie_nazwisko'] ?? '',
                        'typ_umowy'     => 'Umowy o dzieło',
                        'numer_umowy'   => $_c['numer_umowy'] ?? '',
                        'data_zawarcia' => $_sfmt($_c['data_zawarcia']),
                        'data_od'       => $_sfmt($_c['data_zawarcia']),
                        'data_do'       => $_bezterm($_c) ? 'bezterminowo' : $_sfmt($_c['data_zakonczenia']),
                        'przedmiot'     => $_c['przedmiot'] ?? '',
                        'stanowisko'    => '',
                        'miejsce'       => '',
                        'status_umowy'  => $_st_umowy($_c['status'] ?? ''),
                    ];
                }
            } else {
                $_tables = [
                    'zlecenie'    => 'umowy_zlecenie',
                    'uslugi'      => 'umowy_uslugi',
                    'inne'        => 'umowy_inne',
                    'powierzenie' => 'umowy_inne',
                ];
                $_ptable = $_tables[$_ptype] ?? null;
                if ($_ptable) {
                    $_przedmiot_col = [
                        'zlecenie'    => 'przedmiot_zlecenia',
                        'uslugi'      => 'przedmiot_uslugi',
                        'inne'        => 'przedmiot_umowy',
                        'powierzenie' => 'przedmiot_umowy',
                    ][$_ptype] ?? '';
                    // imie_nazwisko różni się per tabela
                    $_name_col = [
                        'uslugi'      => "COALESCE(imie_nazwisko, nazwa_wykonawcy,'') AS imie_nazwisko",
                        'inne'        => "COALESCE(imie_nazwisko, strona_umowy,'') AS imie_nazwisko",
                        'powierzenie' => "COALESCE(imie_nazwisko, strona_umowy,'') AS imie_nazwisko",
                    ][$_ptype] ?? "COALESCE(imie_nazwisko,'') AS imie_nazwisko";
                    // bezterminowa vs czas_nieokreslony
                    $_bezterm_col = in_array($_ptype, ['inne','powierzenie'])
                        ? "COALESCE(czas_nieokreslony,0) AS bezterminowa"
                        : "COALESCE(bezterminowa,0) AS bezterminowa";
                    $_c = db_one(
                        "SELECT {$_name_col}, numer_umowy, data_zawarcia, data_rozpoczecia, data_zakonczenia,
                                {$_bezterm_col}, status, COALESCE(email,'') AS email"
                        . ($_przedmiot_col ? ", COALESCE({$_przedmiot_col},'') AS przedmiot" : ", '' AS przedmiot")
                        . " FROM {$_ptable} WHERE id=?", [$_pid]
                    );
                    if ($_c) {
                        $prefill_vals = [
                            '_email'        => $_c['email'] ?? '',
                            'byl_jest'      => $_byl_jest($_c['status'] ?? ''),
                            'imie_nazwisko' => $_c['imie_nazwisko'] ?? '',
                            'typ_umowy'     => $_tlab[$_ptype] ?? 'Innej umowy',
                            'numer_umowy'   => $_c['numer_umowy'] ?? '',
                            'data_zawarcia' => $_sfmt($_c['data_zawarcia']),
                            'data_od'       => $_sfmt($_c['data_rozpoczecia']),
                            'data_do'       => $_bezterm($_c) ? 'bezterminowo' : $_sfmt($_c['data_zakonczenia']),
                            'przedmiot'     => $_c['przedmiot'] ?? '',
                            'stanowisko'    => '',
                            'miejsce'       => '',
                            'status_umowy'  => $_st_umowy($_c['status'] ?? ''),
                        ];
                    }
                }
            }
        } catch (\Throwable $e) { /* brak tabeli lub kolumny — ignoruj */ }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    // Krok 1 POST: wybrano typ
    if (!$typ_id) {
        $typ_id = (int)($_POST['typ_id'] ?? 0);
        if (!$typ_id) { flash_set('error', 'Wybierz typ zaświadczenia.'); header('Location:' . APP_URL . '/ezd/zaswiadczenia/new.php'); exit; }
        header('Location:' . APP_URL . '/ezd/zaswiadczenia/new.php?typ_id=' . $typ_id); exit;
    }

    // Krok 2 POST: złóż wniosek
    $typ = ezd_zas_typ_get($typ_id);
    if (!$typ || !$typ['is_active']) { flash_set('error', 'Nieprawidłowy typ.'); header('Location:' . APP_URL . '/ezd/zaswiadczenia/new.php'); exit; }

    $name  = trim($_POST['wnioskodawca_name'] ?? '');
    $email = trim($_POST['wnioskodawca_email'] ?? '');
    if (!$name) { flash_set('error', 'Imię i nazwisko wnioskodawcy jest wymagane.'); header('Location:' . APP_URL . '/ezd/zaswiadczenia/new.php?typ_id=' . $typ_id); exit; }

    $dane = [];
    $errors = [];
    foreach ($typ['pola'] as $pole) {
        $val = trim($_POST['pole_' . ($pole['name'] ?? '')] ?? '');
        if (!empty($pole['required']) && $val === '') {
            $errors[] = 'Pole „' . h($pole['label']) . '" jest wymagane.';
        } else {
            $dane[$pole['name']] = $val;
        }
    }

    if ($errors) {
        flash_set('error', implode('<br>', $errors));
        header('Location:' . APP_URL . '/ezd/zaswiadczenia/new.php?typ_id=' . $typ_id); exit;
    }

    // Post-processing specyficzny dla typów z tokenami pochodnymi
    if (($typ['kod'] ?? '') === 'oswiadczenie_student_wspolpraca') {
        $zen = ($dane['plec'] ?? '') === 'kobieta';
        $dane['student_forma'] = $zen ? 'studentka'        : 'student';
        $dane['podjal']        = $zen ? 'podjęła'          : 'podjął';
        $kierunek = trim($dane['kierunek'] ?? '');
        $dane['kierunek_fraza'] = $kierunek !== '' ? ', kierunek ' . $kierunek : '';
        if (empty($dane['uczelnia_celownik'])) {
            $dane['uczelnia_celownik'] = $dane['uczelnia'] ?? '';
        }
        $vw = $zen ? 'Wolontariuszki' : 'Wolontariusza';
        if (!empty($dane['dolacz_akapit_uczelni'])) {
            $g   = trim($dane['suma_godzin'] ?? '');
            $od  = trim($dane['okres_od']    ?? '');
            $do  = trim($dane['okres_do']    ?? '');
            $ucz = trim($dane['uczelnia_celownik'] ?: ($dane['uczelnia'] ?? ''));
            $ap  = '<p>Niniejsze zaświadczenie wydaje się na wniosek ' . h($vw) . ' w celu potwierdzenia';
            if ($g !== '') $ap .= ' przepracowania łącznej liczby <strong>' . h($g) . ' godzin</strong>';
            if ($od !== '' || $do !== '') $ap .= ' w okresie od <strong>' . h($od) . '</strong> do <strong>' . h($do) . '</strong>&nbsp;r';
            $ap .= '.';
            if ($ucz !== '') $ap .= ' Zwracamy się z prośbą do <strong>' . h($ucz) . '</strong> o uwzględnienie powyższego zaangażowania społecznego i przyznanie należnych punktów w procesie rekrutacji.';
            $ap .= '</p>';
            $dane['akapit_uczelni'] = $ap;
        } else {
            $dane['akapit_uczelni'] = '';
        }
        if (!empty($dane['dolacz_zamkniecie_zobowiazan'])) {
            $dz  = trim($dane['data_zamkniecia']  ?? '');
            $uwg = trim($dane['uwagi_zamkniecia'] ?? '');
            $az  = '<p style="margin-top:10pt;padding:8pt 10pt;border:1px solid #888;border-radius:3pt;font-size:9pt">'
                 . '<strong>Informacja o zamknięciu zobowiązań:</strong> '
                 . 'Potwierdzamy, że wszelkie zobowiązania wynikające ze współpracy zostały prawidłowo rozliczone i zamknięte';
            if ($dz !== '') $az .= ' w dniu <strong>' . h($dz) . '</strong>';
            $az .= '.';
            if ($uwg !== '') $az .= ' ' . h($uwg);
            $az .= '</p>';
            $dane['akapit_zamkniecie'] = $az;
        } else {
            $dane['akapit_zamkniecie'] = '';
        }
    }

    $_post_ctype = trim($_POST['_contract_type'] ?? '');
    $_post_cid   = (int)($_POST['_contract_id'] ?? 0);
    $id       = ezd_zas_create($typ_id, $name, $email, $dane, $user_id, $_post_ctype, $_post_cid);
    $z_urzedu = isset($_POST['z_urzedu']) ? 1 : 0;
    if ($z_urzedu) {
        db()->prepare("UPDATE ezd_zaswiadczenia_wlasne SET z_urzedu=1 WHERE id=?")->execute([$id]);
    }

    // Jeśli nie wymaga akceptacji → od razu wydaj
    if (!$typ['wymaga_akceptacji']) {
        $res = ezd_zas_wydaj($id, $user_id);
        if ($res['ok']) {
            flash_set('success', 'Zaświadczenie ' . h($res['nr']) . ' wydane natychmiast (bez weryfikacji).');
        }
    } else {
        flash_set('success', 'Wniosek złożony. Czeka na weryfikację i wydanie.');
    }

    header('Location:' . APP_URL . '/ezd/zaswiadczenia/view.php?id=' . $id); exit;
}

$typ = $typ_id ? ezd_zas_typ_get($typ_id) : null;
$PAGE_TITLE = 'Nowy wniosek o zaświadczenie';
include dirname(dirname(__DIR__)) . '/includes/header.php';
?>
<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb" style="font-size:.8rem">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/index.php">EZD Wirtualne biurko</a></li>
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/zaswiadczenia/index.php">Zaświadczenia</a></li>
  <li class="breadcrumb-item active">Nowy wniosek</li>
</ol></nav>

<?= flash_html() ?>

<?php if (!$typ_id): ?>
<!-- Krok 1: wybór typu -->
<div class="card shadow-sm" style="max-width:640px">
  <div class="card-header fw-semibold" style="font-size:.88rem"><i class="bi bi-award me-1 text-primary"></i>Wybierz rodzaj zaświadczenia</div>
  <div class="card-body">
    <?php if (!$typy): ?>
    <div class="alert alert-warning py-2" style="font-size:.84rem">
      <i class="bi bi-exclamation-triangle me-1"></i>Brak aktywnych typów zaświadczeń.
      <?php if(ezd_is_manager()||can_edit()): ?><a href="<?= APP_URL ?>/ezd/zaswiadczenia/typy.php">Zdefiniuj typy.</a><?php endif; ?>
    </div>
    <?php else: ?>
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <div class="list-group mb-3">
        <?php foreach($typy as $t): ?>
        <label class="list-group-item list-group-item-action d-flex gap-3 py-2" style="cursor:pointer">
          <input class="form-check-input flex-shrink-0 mt-1" type="radio" name="typ_id" value="<?= $t['id'] ?>" required>
          <div>
            <div class="fw-semibold" style="font-size:.88rem"><?= h($t['nazwa']) ?></div>
            <?php if($t['opis']): ?><div class="text-muted" style="font-size:.76rem"><?= h($t['opis']) ?></div><?php endif; ?>
            <?php if($t['wymaga_akceptacji']): ?><span class="badge bg-warning text-dark mt-1" style="font-size:.66rem">Wymaga akceptacji</span><?php else: ?><span class="badge bg-success mt-1" style="font-size:.66rem">Natychmiastowe wydanie</span><?php endif; ?>
          </div>
        </label>
        <?php endforeach; ?>
      </div>
      <button class="btn btn-primary btn-sm"><i class="bi bi-arrow-right me-1"></i>Dalej</button>
    </form>
    <?php endif; ?>
  </div>
</div>

<?php else: ?>
<!-- Krok 2: formularz wniosku -->
<div class="row g-4" style="max-width:860px">
  <div class="col-lg-7">
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="typ_id" value="<?= $typ_id ?>">
      <?php if ($_prefill_ctype && $_prefill_cid): ?>
      <input type="hidden" name="_contract_type" value="<?= h($_prefill_ctype) ?>">
      <input type="hidden" name="_contract_id"   value="<?= $_prefill_cid ?>">
      <?php endif; ?>

      <!-- Panel importu danych z umowy -->
      <div class="mb-3">
        <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-import-toggle">
          <i class="bi bi-cloud-download me-1"></i>Importuj dane z umowy
        </button>
        <div id="zas-import-panel" class="card border-secondary mt-2" style="display:none">
          <div class="card-body p-2">
            <div class="row g-2 mb-2">
              <div class="col-auto">
                <select id="zas-import-type" class="form-select form-select-sm">
                  <?php foreach (CONTRACT_TYPES as $_k => $_lbl): ?>
                  <option value="<?= $_k ?>" <?= ($_prefill_ctype === $_k ? 'selected' : '') ?>><?= h($_lbl) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col">
                <input type="text" id="zas-import-q" class="form-control form-control-sm"
                       placeholder="Nazwisko lub numer umowy…">
              </div>
              <div class="col-auto">
                <button type="button" id="zas-import-search" class="btn btn-sm btn-outline-primary">Szukaj</button>
              </div>
            </div>
            <div id="zas-import-results" style="max-height:180px;overflow-y:auto"></div>
          </div>
        </div>
        <?php if ($_prefill_ctype && $_prefill_cid): ?>
        <div class="badge bg-secondary mt-1" style="font-size:.7rem">
          <i class="bi bi-link-45deg me-1"></i>Dane zaimportowane z: <?= h(CONTRACT_TYPES[$_prefill_ctype] ?? $_prefill_ctype) ?> #<?= $_prefill_cid ?>
        </div>
        <?php endif; ?>
      </div>

      <div class="card shadow-sm">
        <div class="card-header">
          <div class="fw-semibold" style="font-size:.88rem"><i class="bi bi-award me-1 text-primary"></i><?= h($typ['nazwa']) ?></div>
          <?php if($typ['opis']): ?><div class="text-muted mt-1" style="font-size:.76rem"><?= h($typ['opis']) ?></div><?php endif; ?>
        </div>
        <div class="card-body">
          <div class="mb-3">
            <label class="form-label fw-semibold mb-1" style="font-size:.78rem">Imię i nazwisko wnioskodawcy <span class="text-danger">*</span></label>
            <input type="text" name="wnioskodawca_name" class="form-control form-control-sm"
                   value="<?= h($prefill_vals['imie_nazwisko'] ?? $user['name'] ?? '') ?>" required>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold mb-1" style="font-size:.78rem">Adres e-mail kontaktowy</label>
            <input type="email" name="wnioskodawca_email" class="form-control form-control-sm"
                   value="<?= h($prefill_vals['_email'] ?? $user['email'] ?? '') ?>">
          </div>
          <div class="mb-3">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="z_urzedu" value="1" id="chk-zurzedu-new">
              <label class="form-check-label" for="chk-zurzedu-new" style="font-size:.85rem">
                <i class="bi bi-building me-1"></i>Wystawione z inicjatywy organizacji (z urzędu)
              </label>
            </div>
          </div>
          <?php if($typ['pola']): ?>
          <hr class="my-3">
          <div class="fw-semibold mb-2" style="font-size:.82rem">Dane do zaświadczenia</div>
          <?php foreach($typ['pola'] as $pole): ?>
          <div class="mb-3">
            <label class="form-label fw-semibold mb-1" style="font-size:.78rem">
              <?= h($pole['label'] ?? $pole['name']) ?>
              <?php if(!empty($pole['required'])): ?><span class="text-danger">*</span><?php endif; ?>
            </label>
            <?php
              $fn    = 'pole_' . ($pole['name'] ?? '');
              $pname = $pole['name'] ?? '';
              $type  = $pole['type'] ?? 'text';
              $req   = !empty($pole['required']) ? 'required' : '';
              $pval  = $prefill_vals[$pname] ?? '';
              if ($type === 'checkbox'): ?>
            <div class="form-check mt-1">
              <input class="form-check-input zas-chk-field" type="checkbox"
                     name="<?= h($fn) ?>" id="<?= h($fn) ?>" value="1"
                     data-field="<?= h($pname) ?>"
                     <?= $pval ? 'checked' : '' ?>>
              <label class="form-check-label" for="<?= h($fn) ?>" style="font-size:.82rem">
                <?= h($pole['label'] ?? $pname) ?>
              </label>
            </div>
            <?php elseif ($type === 'textarea'): ?>
            <textarea name="<?= h($fn) ?>" class="form-control form-control-sm" rows="3" <?= $req ?>><?= h($pval) ?></textarea>
            <?php elseif ($type === 'select'): ?>
            <select name="<?= h($fn) ?>" class="form-select form-select-sm" <?= $req ?>>
              <option value="">— wybierz —</option>
              <?php foreach($pole['options'] ?? [] as $opt): ?>
              <option <?= $opt === $pval ? 'selected' : '' ?>><?= h($opt) ?></option>
              <?php endforeach; ?>
            </select>
            <?php elseif ($type === 'date'): ?>
            <input type="date" name="<?= h($fn) ?>" class="form-control form-control-sm" <?= $req ?> value="<?= h($pval) ?>">
            <?php else: ?>
            <input type="text" name="<?= h($fn) ?>" class="form-control form-control-sm" <?= $req ?> value="<?= h($pval) ?>">
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
          <?php endif; ?>
        </div>
        <div class="card-footer d-flex gap-2 justify-content-between">
          <a href="<?= APP_URL ?>/ezd/zaswiadczenia/new.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Zmień typ</a>
          <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-send me-1"></i>Złóż wniosek</button>
        </div>
      </div>
    </form>
  </div>

  <?php if($typ['szablon_tresc']): ?>
  <div class="col-lg-5">
    <div class="card shadow-sm">
      <div class="card-header fw-semibold" style="font-size:.82rem"><i class="bi bi-eye me-1 text-muted"></i>Podgląd szablonu treści</div>
      <div class="card-body p-3" style="font-size:.78rem;white-space:pre-wrap;font-family:inherit;line-height:1.7;max-height:400px;overflow-y:auto"><?= h($typ['szablon_tresc']) ?></div>
      <div class="card-footer text-muted" style="font-size:.72rem"><i class="bi bi-info-circle me-1"></i>Tokeny <span class="font-monospace">{{...}}</span> zostaną zastąpione wprowadzonymi danymi.</div>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<script>
(function () {
  const btn    = document.getElementById('btn-import-toggle');
  const panel  = document.getElementById('zas-import-panel');
  const selTyp = document.getElementById('zas-import-type');
  const inpQ   = document.getElementById('zas-import-q');
  const btnSrc = document.getElementById('zas-import-search');
  const resCtn = document.getElementById('zas-import-results');
  if (!btn) return;

  btn.addEventListener('click', () => {
    panel.style.display = panel.style.display === 'none' ? '' : 'none';
  });

  async function doSearch() {
    const type = selTyp.value;
    const q    = inpQ.value.trim();
    if (q.length < 2) { resCtn.innerHTML = '<small class="text-muted p-1 d-block">Wpisz min. 2 znaki.</small>'; return; }
    resCtn.innerHTML = '<small class="text-muted p-1 d-block">Szukam…</small>';
    try {
      const r = await fetch(`?_ajax=search_contracts&type=${encodeURIComponent(type)}&q=${encodeURIComponent(q)}`);
      const d = await r.json();
      if (!d.results || !d.results.length) { resCtn.innerHTML = '<small class="text-muted p-1 d-block">Brak wyników.</small>'; return; }
      resCtn.innerHTML = '<ul class="list-group list-group-flush" style="font-size:.8rem">'
        + d.results.map(row =>
          `<li class="list-group-item list-group-item-action py-1 px-2 zas-import-row"
              style="cursor:pointer" data-ctype="${type}" data-cid="${row.id}">
            <strong>${row.display_name}</strong>
            ${row.numer_umowy ? `<span class="text-muted ms-1">${row.numer_umowy}</span>` : ''}
            ${row.status ? `<span class="badge bg-secondary ms-1 float-end">${row.status}</span>` : ''}
          </li>`
        ).join('') + '</ul>';
      resCtn.querySelectorAll('.zas-import-row').forEach(li => {
        li.addEventListener('click', () => doImport(li.dataset.ctype, li.dataset.cid));
      });
    } catch { resCtn.innerHTML = '<small class="text-danger p-1 d-block">Błąd pobierania.</small>'; }
  }

  async function doImport(ctype, cid) {
    resCtn.innerHTML = '<small class="text-muted p-1 d-block">Importuję dane…</small>';
    try {
      const r = await fetch(`?_ajax=import&contract_type=${encodeURIComponent(ctype)}&contract_id=${encodeURIComponent(cid)}`);
      const d = await r.json();
      if (!d.ok) { resCtn.innerHTML = `<small class="text-danger p-1 d-block">${d.error ?? 'Błąd importu.'}</small>`; return; }
      const vals = d.vals || {};
      const setField = (name, val) => {
        const el = document.querySelector(`[name="${name}"]`);
        if (!el || val === undefined || val === null) return;
        if (el.tagName === 'SELECT') {
          for (const opt of el.options) { if (opt.value === val || opt.text === val) { el.value = opt.value; break; } }
        } else if (el.type === 'checkbox') { el.checked = !!val;
        } else { el.value = val; }
      };
      setField('wnioskodawca_name',  vals._name  ?? '');
      setField('wnioskodawca_email', vals._email ?? '');
      setField('_contract_type', ctype);
      setField('_contract_id',   cid);
      for (const [k, v] of Object.entries(vals)) {
        if (!k.startsWith('_')) setField('pole_' + k, v);
      }
      panel.style.display = 'none';
      resCtn.innerHTML = '';
      btn.innerHTML = `<i class="bi bi-check-circle me-1 text-success"></i>Zaimportowano z umowy #${cid}`;
      btn.disabled = true;
    } catch { resCtn.innerHTML = '<small class="text-danger p-1 d-block">Błąd importu.</small>'; }
  }

  btnSrc.addEventListener('click', doSearch);
  inpQ.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); doSearch(); } });
})();

// Show/hide pól zależnych od checkboxów (np. akapit uczelni → pola godzin)
(function () {
  const DEPS = {
    'dolacz_akapit_uczelni':       ['suma_godzin','okres_od','okres_do','uczelnia_celownik'],
    'dolacz_zamkniecie_zobowiazan':['data_zamkniecia','uwagi_zamkniecia'],
  };
  function applyDep(chk) {
    const deps = DEPS[chk.dataset.field] || [];
    deps.forEach(name => {
      const wrap = document.querySelector(`[name="pole_${name}"]`)?.closest('.mb-3');
      if (wrap) wrap.style.display = chk.checked ? '' : 'none';
    });
  }
  document.querySelectorAll('.zas-chk-field').forEach(chk => {
    applyDep(chk);
    chk.addEventListener('change', () => applyDep(chk));
  });
})();
</script>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
