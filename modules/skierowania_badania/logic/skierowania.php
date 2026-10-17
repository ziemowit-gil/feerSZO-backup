<?php
/**
 * modules/skierowania_badania/logic/skierowania.php — skierowania na badania lekarskie (medycyna pracy)
 * dla osób z umową wolontariacką (porozumieniem) albo umową zlecenia.
 *
 * Wzór skierowania: załącznik nr 3a do rozporządzenia MZiOS z 30 maja 1996 r. w sprawie badań lekarskich
 * pracowników (art. 229 § 4a KP; dla zleceniobiorcy w zw. z art. 304 § 1 KP, dla wolontariusza w zw. z art. 45
 * ust. 1 pkt 2 ustawy o działalności pożytku publicznego i o wolontariacie) — podstawy prawne edytowalne w ustawieniach.
 * Wykaz czynników = SKB_CZYNNIKI (pięć grup jak na skierowaniu); przy każdym zaznaczonym można wpisać wielkość narażenia.
 *
 * Numer: SKB/{kolejny w roku}/{RRRR}. Każde skierowanie przypięte do umowy (contract_type + contract_id);
 * dane osoby kopiowane z umowy w chwili wystawienia (później edytowalne). Rejestracja w EZD: skierowaniaEzd.php.
 */

require_once dirname(__DIR__, 3) . '/modules/audit_logs/logic/audit_logs.php';

const SKB_TYPY = ['wolontariat' => 'Porozumienie wolontariackie', 'zlecenie' => 'Umowa zlecenia'];
const SKB_RODZAJE = ['wstepne' => 'wstępne', 'okresowe' => 'okresowe', 'kontrolne' => 'kontrolne'];
const SKB_STATUSY = [
    'wystawione'   => ['Wystawione', 'primary'],
    'zrealizowane' => ['Zrealizowane (orzeczenie)', 'success'],
    'anulowane'    => ['Anulowane', 'secondary'],
];
const SKB_WYNIKI = ['zdolny' => 'zdolny/zdolna do pracy', 'niezdolny' => 'niezdolny/niezdolna do pracy', 'przeciwwskazania' => 'z przeciwwskazaniami'];

/** Wykaz czynników szkodliwych i uciążliwych (grupy jak na skierowaniu). Klucz = numer rzymski grupy. */
const SKB_CZYNNIKI = [
    'I' => ['Czynniki fizyczne', [
        'hałas', 'hałas ultradźwiękowy', 'drgania działające na organizm człowieka przez kończyny górne (drgania miejscowe)',
        'drgania o ogólnym działaniu na organizm człowieka (drgania ogólne)', 'promieniowanie jonizujące', 'pola elektromagnetyczne',
        'promieniowanie nadfioletowe', 'promieniowanie podczerwone', 'promieniowanie laserowe', 'promieniowanie widzialne (światło)',
        'mikroklimat gorący', 'mikroklimat zimny', 'zwiększone lub obniżone ciśnienie atmosferyczne',
    ]],
    'II' => ['Pyły', [
        'pyły nieorganiczne zawierające krzemionkę krystaliczną', 'pyły węgla (kamiennego, brunatnego)', 'pyły grafitu (naturalnego, syntetycznego)',
        'pyły nieorganiczne zawierające włókna azbestu', 'pył talku', 'pył zawierający metale twarde (np. wolfram, kobalt)',
        'pył zawierający tworzywa sztuczne, w tym sztuczne włókna mineralne',
        'pył organiczny pochodzenia roślinnego lub zwierzęcego, z wyjątkiem pyłów drewna oraz mąki', 'pył drewna', 'pył mąki',
    ]],
    'III' => ['Czynniki chemiczne', [
        'związki akrylowe – akrylonitryl, akrylany', 'aldehydy – akrylowy, formaldehyd', 'amoniak', 'arsen i jego związki, w tym arsenowodór',
        'azotu tlenki', 'bar i jego związki', 'benzen i jego homologi (ksylen, toluen)', 'benzo[a]piren', 'beryl i jego związki',
        'brom i jego związki', 'chlor, chlorowodór i tlenki chloru', 'chrom i związki chromu (VI)', 'czteroetylek ołowiu', 'dwumetyloformamid',
        'epichlorhydryna', 'fenol i jego homologi oraz ich chlorowcopochodne lub nitropochodne', 'fluor i fluorki', 'fosfor biały, żółty',
        'fosforu związki chlorowe', 'ftalowy bezwodnik', 'furfurol', 'glinu tlenek', 'glikole – etylenowy; nitrowe pochodne glikoli lub gliceryny',
        'izocyjanianowe związki', 'kadm i jego związki', 'ketony (aceton, metyloetyloketon i inne)', 'kobalt', 'mangan i jego związki',
        'nafta i jej produkty (benzyna ekstrakcyjna, pak, smoła, asfalty)', 'naftalen i pochodne (naftol, dekalina, tetralina)',
        'nikiel i jego związki', 'ołów i jego związki', 'pestycydy', 'pirydyna', 'rtęć i jej związki', 'siarkowodór', 'siarki tlenki',
        'terpentyna', 'wanad i jego związki', 'węgla dwusiarczek', 'węgla tlenek',
        'węglowodorów alifatycznych związki (nitrowe, aminowe, chlorowcopochodne)',
        'węglowodorów aromatycznych związki (nitrowe, aminowe, chlorowcopochodne)', 'winylobenzen (styren)', 'winylu chlorek',
        'żywice epoksydowe', 'mieszaniny rozpuszczalników organicznych', 'cytostatyki',
    ]],
    'IV' => ['Czynniki biologiczne', [
        'wirus zapalenia wątroby typu B (HBV)', 'wirus zapalenia wątroby typu C (HCV)', 'ludzki wirus niedoboru odporności nabytej (HIV)',
        'pałeczki Brucella abortus bovis', 'promieniowce termofilne, grzyby pleśniowe, w tym o działaniu uczulającym', 'prątek gruźlicy',
        'wirus kleszczowego zapalenia mózgu', 'Borrelia burgdorferi', 'inne czynniki zaliczone do grupy 2, 3 lub 4 zagrożenia',
    ]],
    'V' => ['Inne czynniki, w tym niebezpieczne', [
        'niekorzystne czynniki psychospołeczne (stały duży dopływ informacji, odpowiedzialność, presja czasu, monotonia)',
        'praca związana z obsługą narzędzi, maszyn, urządzeń i pojazdów poza drogami publicznymi',
        'obsługa monitorów ekranowych', 'prace wymagające sprawności psychoruchowej', 'praca na wysokości (powyżej 1 metra)',
        'praca zmianowa, w tym praca w porze nocnej', 'praca fizyczna z dużym wydatkiem energetycznym', 'praca w wymuszonej pozycji',
        'praca wymagająca ruchów monotypowych kończyny', 'praca wymagająca stałego i nadmiernego wysiłku głosowego',
        'praca na stanowiskach związanych z kierowaniem pojazdami, szkoleniem i egzaminowaniem kierowców',
        'praca związana z posługiwaniem się bronią palną',
    ]],
];

/** Gotowe zestawy czynników (przycisk w formularzu) — [etykieta, [grupa => [nazwy]]]. */
const SKB_ZESTAWY = [
    'biuro'     => ['Praca biurowa z monitorem', ['V' => ['obsługa monitorów ekranowych', 'niekorzystne czynniki psychospołeczne (stały duży dopływ informacji, odpowiedzialność, presja czasu, monotonia)']]],
    'zajecia'   => ['Prowadzenie zajęć / szkoleń', ['V' => ['obsługa monitorów ekranowych', 'praca wymagająca stałego i nadmiernego wysiłku głosowego', 'niekorzystne czynniki psychospołeczne (stały duży dopływ informacji, odpowiedzialność, presja czasu, monotonia)']]],
    'kierowca'  => ['Kierowanie pojazdem', ['V' => ['praca na stanowiskach związanych z kierowaniem pojazdami, szkoleniem i egzaminowaniem kierowców', 'prace wymagające sprawności psychoruchowej']]],
];

function skbMigrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    db()->exec("CREATE TABLE IF NOT EXISTS skierowania_badania (
        id               INTEGER PRIMARY KEY AUTOINCREMENT,
        numer            TEXT,
        rok              INTEGER,
        kolejny          INTEGER,
        contract_type    TEXT NOT NULL,
        contract_id      INTEGER NOT NULL,
        rodzaj           TEXT NOT NULL DEFAULT 'wstepne',
        status           TEXT NOT NULL DEFAULT 'wystawione',
        imie_nazwisko    TEXT,
        pesel            TEXT,
        dokument         TEXT,
        adres            TEXT,
        podejmujacy      INTEGER DEFAULT 1,
        stanowisko       TEXT,
        opis_warunkow    TEXT,
        czynniki         TEXT,
        liczba_czynnikow INTEGER DEFAULT 0,
        placowka         TEXT,
        miejscowosc      TEXT,
        data_wystawienia DATE,
        podpisujacy      TEXT,
        uwagi            TEXT,
        data_badania     DATE,
        wynik            TEXT,
        wazne_do         DATE,
        ezd_sprawa_id    INTEGER,
        ezd_pismo_id     INTEGER,
        ezd_at           DATETIME,
        created_by       INTEGER,
        created_name     TEXT,
        created_at       DATETIME DEFAULT (datetime('now')),
        updated_at       DATETIME
    )");
    db()->exec("CREATE INDEX IF NOT EXISTS idx_skb_contract ON skierowania_badania(contract_type, contract_id)");
    db()->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_skb_numer ON skierowania_badania(rok, kolejny)");
}

/** Ustawienie modułu z wartością domyślną. */
function skbSetting(string $key): string {
    $v = trim((string)org_setting('skb_' . $key));
    if ($v !== '') return $v;
    return match ($key) {
        'miejscowosc'         => mb_convert_case((string)org_setting('org_miejscowosc'), MB_CASE_TITLE, 'UTF-8'),
        'podstawa_zlecenie'   => 'art. 229 § 4a w związku z art. 304 § 1 ustawy z dnia 26 czerwca 1974 r. – Kodeks pracy',
        'podstawa_wolontariat'=> 'art. 229 § 4a ustawy z dnia 26 czerwca 1974 r. – Kodeks pracy w związku z art. 45 ust. 1 pkt 2 ustawy z dnia 24 kwietnia 2003 r. o działalności pożytku publicznego i o wolontariacie',
        'ezd_auto'            => '1',
        default               => '',
    };
}

const SKB_SETTINGS = ['miejscowosc', 'podpisujacy', 'placowka', 'podstawa_zlecenie', 'podstawa_wolontariat', 'ezd_auto'];

function skbSettingsSave(array $in): void {
    foreach (SKB_SETTINGS as $k) {
        $v = $k === 'ezd_auto' ? (!empty($in[$k]) ? '1' : '0') : trim((string)($in[$k] ?? ''));
        org_setting_set('skb_' . $k, $v);
    }
}

/** Wiersz umowy (tylko wolontariat / zlecenie) albo null. */
function skbContract(string $type, int $id): ?array {
    if (!isset(SKB_TYPY[$type]) || $id <= 0) return null;
    return db_one("SELECT * FROM umowy_{$type} WHERE id=?", [$id]) ?: null;
}

function skbContractNumber(array $c): string {
    return trim((string)(($c['nr_rejestru'] ?? '') ?: ($c['numer_umowy'] ?? '')));
}

function skbContractUrl(string $type, int $id): string {
    return rtrim(APP_URL, '/') . "/contracts/{$type}/view.php?id={$id}";
}

/** Adres z pól strukturalnych umowy (addr_*) albo z pola tekstowego. */
function skbContractAddress(array $c): string {
    $street = trim((string)($c['addr_street'] ?? ''));
    if ($street !== '' || trim((string)($c['addr_city'] ?? '')) !== '') {
        $nr = trim((string)($c['addr_house'] ?? ''));
        if (trim((string)($c['addr_flat'] ?? '')) !== '') $nr .= '/' . trim((string)$c['addr_flat']);
        $line1 = trim($street . ' ' . $nr);
        $line2 = trim(trim((string)($c['addr_postal'] ?? '')) . ' ' . trim((string)($c['addr_city'] ?? '')));
        return trim($line1 . ($line1 !== '' && $line2 !== '' ? ', ' : '') . $line2);
    }
    return trim((string)($c['adres'] ?? ''));
}

/** Dane do nowego skierowania skopiowane z umowy. */
function skbPrefill(string $type, array $c): array {
    $pos = !empty($c['org_position_id']) ? db_one("SELECT name FROM org_positions WHERE id=?", [(int)$c['org_position_id']]) : null;
    $zakres = trim((string)($type === 'zlecenie' ? ($c['przedmiot_zlecenia'] ?? '') : ($c['przedmiot_porozumienia'] ?? '')));
    $stanowisko = trim((string)($pos['name'] ?? ''));
    if ($stanowisko === '') $stanowisko = ($type === 'wolontariat' ? 'Wolontariusz' : 'Zleceniobiorca') . ($zakres !== '' ? ' — ' . mb_substr($zakres, 0, 160) : '');
    $doc = '';
    if (trim((string)($c['pesel'] ?? '')) === '') {
        $doc = trim(trim((string)($c['id_document_type'] ?? '')) . ' ' . trim((string)($c['id_document_number'] ?? ($c['seria_nr_dowodu'] ?? ''))));
    }
    $prev = db_one("SELECT data_badania FROM skierowania_badania WHERE contract_type=? AND contract_id=? AND status='zrealizowane' ORDER BY data_badania DESC LIMIT 1", [$type, (int)$c['id']]);
    return [
        'contract_type'    => $type,
        'contract_id'      => (int)$c['id'],
        'rodzaj'           => $prev ? 'okresowe' : 'wstepne',
        'imie_nazwisko'    => (string)($c['imie_nazwisko'] ?? ''),
        'pesel'            => (string)($c['pesel'] ?? ''),
        'dokument'         => $doc,
        'adres'            => skbContractAddress($c),
        'podejmujacy'      => in_array((string)($c['status'] ?? ''), ['podpisana', 'w realizacji', 'obowiązująca'], true) ? 0 : 1,
        'stanowisko'       => $stanowisko,
        'opis_warunkow'    => $zakres,
        'czynniki'         => [],
        'placowka'         => skbSetting('placowka'),
        'miejscowosc'      => skbSetting('miejscowosc'),
        'data_wystawienia' => date('Y-m-d'),
        'podpisujacy'      => skbSetting('podpisujacy'),
        'uwagi'            => '',
    ];
}

/** Czynniki z formularza: czynnik[GR][i]=1, wielkosc[GR][i]=..., inne[GR]=linie. → lista [{g,name,wielkosc}]. */
function skbFactorsFromPost(array $post): array {
    $out = [];
    foreach (SKB_CZYNNIKI as $g => [, $list]) {
        foreach ($list as $i => $name) {
            if (empty($post['czynnik'][$g][$i])) continue;
            $out[] = ['g' => $g, 'name' => $name, 'wielkosc' => trim((string)($post['wielkosc'][$g][$i] ?? ''))];
        }
        foreach (preg_split('/\R/u', (string)($post['inne'][$g] ?? '')) as $line) {
            $line = trim($line);
            if ($line === '') continue;
            [$n, $w] = array_pad(array_map('trim', explode('|', $line, 2)), 2, '');
            $out[] = ['g' => $g, 'name' => mb_substr($n, 0, 300), 'wielkosc' => mb_substr($w, 0, 200), 'own' => 1];
        }
    }
    return $out;
}

function skbFactors(array $s): array {
    $f = is_array($s['czynniki'] ?? null) ? $s['czynniki'] : json_decode((string)($s['czynniki'] ?? ''), true);
    return is_array($f) ? $f : [];
}

/** Czynniki pogrupowane: [GR => [{name,wielkosc}]] (tylko grupy z czynnikami). */
function skbFactorsByGroup(array $s): array {
    $out = [];
    foreach (skbFactors($s) as $f) $out[$f['g']][] = $f;
    return $out;
}

function skbGet(int $id): ?array {
    skbMigrate();
    return db_one("SELECT * FROM skierowania_badania WHERE id=?", [$id]) ?: null;
}

function skbForContract(string $type, int $id): array {
    skbMigrate();
    return db_all("SELECT * FROM skierowania_badania WHERE contract_type=? AND contract_id=? ORDER BY id DESC", [$type, $id]);
}

/** Lista z filtrami: q (osoba / numer), status, typ, wygasa (dni do końca ważności orzeczenia). */
function skbList(array $f): array {
    skbMigrate();
    $w = []; $p = [];
    if (($q = trim((string)($f['q'] ?? ''))) !== '') { $w[] = "(imie_nazwisko LIKE ? OR numer LIKE ? OR stanowisko LIKE ?)"; array_push($p, "%$q%", "%$q%", "%$q%"); }
    if (isset(SKB_STATUSY[$f['status'] ?? ''])) { $w[] = "status=?"; $p[] = $f['status']; }
    if (isset(SKB_TYPY[$f['typ'] ?? '']))       { $w[] = "contract_type=?"; $p[] = $f['typ']; }
    if (!empty($f['wygasa'])) { $w[] = "status='zrealizowane' AND wazne_do IS NOT NULL AND wazne_do <= date('now', ?)"; $p[] = '+' . (int)$f['wygasa'] . ' days'; }
    return db_all("SELECT * FROM skierowania_badania" . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . " ORDER BY id DESC LIMIT 500", $p);
}

/** Aktywne umowy wolontariackie i zlecenia do wyboru przy nowym skierowaniu. */
function skbContractsSearch(string $q): array {
    $out = [];
    foreach (array_keys(SKB_TYPY) as $t) {
        $p = []; $w = "COALESCE(status,'') NOT IN ('rozwiązana','anulowana','zakończona','wygasła','wypowiedziana')";
        if ($q !== '') { $w .= " AND (imie_nazwisko LIKE ? OR numer_umowy LIKE ? OR nr_rejestru LIKE ?)"; array_push($p, "%$q%", "%$q%", "%$q%"); }
        try {
            foreach (db_all("SELECT id, imie_nazwisko, numer_umowy, nr_rejestru, status, data_rozpoczecia FROM umowy_{$t} WHERE {$w} ORDER BY imie_nazwisko COLLATE NOCASE LIMIT 200", $p) as $r)
                $out[] = $r + ['type' => $t];
        } catch (\Throwable $e) {}
    }
    usort($out, fn($a, $b) => strcoll((string)$a['imie_nazwisko'], (string)$b['imie_nazwisko']));
    return $out;
}

/** Walidacja + normalizacja danych formularza. @return array|string dane albo komunikat błędu */
function skbValidate(array $in, array $post): array|string {
    $d = [
        'rodzaj'           => isset(SKB_RODZAJE[$in['rodzaj'] ?? '']) ? $in['rodzaj'] : 'wstepne',
        'imie_nazwisko'    => trim((string)($in['imie_nazwisko'] ?? '')),
        'pesel'            => preg_replace('/\D/', '', (string)($in['pesel'] ?? '')),
        'dokument'         => trim((string)($in['dokument'] ?? '')),
        'adres'            => trim((string)($in['adres'] ?? '')),
        'podejmujacy'      => !empty($in['podejmujacy']) ? 1 : 0,
        'stanowisko'       => trim((string)($in['stanowisko'] ?? '')),
        'opis_warunkow'    => trim((string)($in['opis_warunkow'] ?? '')),
        'placowka'         => trim((string)($in['placowka'] ?? '')),
        'miejscowosc'      => trim((string)($in['miejscowosc'] ?? '')),
        'data_wystawienia' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in['data_wystawienia'] ?? '')) ? $in['data_wystawienia'] : date('Y-m-d'),
        'podpisujacy'      => trim((string)($in['podpisujacy'] ?? '')),
        'uwagi'            => trim((string)($in['uwagi'] ?? '')),
    ];
    if ($d['imie_nazwisko'] === '') return 'Podaj imię i nazwisko osoby kierowanej.';
    if ($d['pesel'] !== '' && strlen($d['pesel']) !== 11) return 'PESEL ma 11 cyfr.';
    if ($d['pesel'] === '' && $d['dokument'] === '') return 'Podaj PESEL albo — gdy go nie nadano — rodzaj, serię i numer dokumentu tożsamości.';
    if ($d['stanowisko'] === '') return 'Podaj stanowisko (rodzaj wykonywanych czynności).';
    $f = skbFactorsFromPost($post);
    $d['czynniki'] = json_encode($f, JSON_UNESCAPED_UNICODE);
    $d['liczba_czynnikow'] = count($f);
    return $d;
}

/** Nowe skierowanie do umowy. @return int|string id albo komunikat błędu */
function skbCreate(string $type, int $contractId, array $d, array $user): int|string {
    skbMigrate();
    if (!skbContract($type, $contractId)) return 'Nie znaleziono umowy wolontariackiej ani zlecenia.';
    $rok = (int)substr($d['data_wystawienia'], 0, 4);
    db()->beginTransaction();
    try {
        $n = (int)(db_one("SELECT MAX(kolejny) m FROM skierowania_badania WHERE rok=?", [$rok])['m'] ?? 0) + 1;
        $cols = $d + ['numer' => "SKB/{$n}/{$rok}", 'rok' => $rok, 'kolejny' => $n, 'contract_type' => $type, 'contract_id' => $contractId,
                      'status' => 'wystawione', 'created_by' => (int)($user['id'] ?? 0) ?: null, 'created_name' => (string)($user['name'] ?? ''),
                      'updated_at' => date('Y-m-d H:i:s')];
        db()->prepare("INSERT INTO skierowania_badania (" . implode(',', array_keys($cols)) . ") VALUES (" . rtrim(str_repeat('?,', count($cols)), ',') . ")")
            ->execute(array_values($cols));
        $id = (int)db()->lastInsertId();
        db()->commit();
    } catch (\Throwable $e) {
        db()->rollBack();
        error_log('skbCreate: ' . $e->getMessage());
        return 'Nie udało się zapisać skierowania.';
    }
    audit_log('skierowania.create', ['id' => $id, 'numer' => "SKB/{$n}/{$rok}", 'contract' => "{$type}#{$contractId}"], (int)($user['id'] ?? 0) ?: null);
    try { log_contract_action($type, $contractId, (int)($user['id'] ?? 0), 'skierowanie_badania', "Wystawiono skierowanie na badania lekarskie SKB/{$n}/{$rok}"); } catch (\Throwable $e) {}
    return $id;
}

function skbUpdate(int $id, array $d, array $user): ?string {
    $s = skbGet($id);
    if (!$s) return 'Nie znaleziono skierowania.';
    if ($s['status'] === 'anulowane') return 'Skierowanie jest anulowane.';
    unset($d['data_wystawienia']);   // data i numer zostają z wystawienia
    $d['updated_at'] = date('Y-m-d H:i:s');
    $set = implode(',', array_map(fn($k) => "{$k}=?", array_keys($d)));
    db()->prepare("UPDATE skierowania_badania SET {$set} WHERE id=?")->execute([...array_values($d), $id]);
    audit_log('skierowania.update', ['id' => $id, 'numer' => $s['numer']], (int)($user['id'] ?? 0) ?: null);
    return null;
}

/** Wynik badania (orzeczenie lekarskie): data, wynik, ważne do → status „zrealizowane”. */
function skbSaveResult(int $id, array $in, array $user): ?string {
    $s = skbGet($id);
    if (!$s || $s['status'] === 'anulowane') return 'Nie można zapisać wyniku dla tego skierowania.';
    $data = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in['data_badania'] ?? '')) ? $in['data_badania'] : null;
    $do   = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in['wazne_do'] ?? '')) ? $in['wazne_do'] : null;
    $wyn  = isset(SKB_WYNIKI[$in['wynik'] ?? '']) ? $in['wynik'] : null;
    if (!$data || !$wyn) return 'Podaj datę badania i wynik orzeczenia.';
    db()->prepare("UPDATE skierowania_badania SET data_badania=?, wynik=?, wazne_do=?, status='zrealizowane', updated_at=datetime('now') WHERE id=?")
        ->execute([$data, $wyn, $do, $id]);
    audit_log('skierowania.result', ['id' => $id, 'wynik' => $wyn, 'wazne_do' => $do], (int)($user['id'] ?? 0) ?: null);
    return null;
}

function skbCancel(int $id, array $user): ?string {
    $s = skbGet($id);
    if (!$s) return 'Nie znaleziono skierowania.';
    db()->prepare("UPDATE skierowania_badania SET status='anulowane', updated_at=datetime('now') WHERE id=?")->execute([$id]);
    audit_log('skierowania.cancel', ['id' => $id, 'numer' => $s['numer']], (int)($user['id'] ?? 0) ?: null);
    return null;
}

function skbPdfFilename(array $s): string {
    $who = preg_replace('/[^A-Za-z0-9]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string)$s['imie_nazwisko']) ?: 'osoba');
    return 'skierowanie_' . str_replace('/', '-', (string)$s['numer']) . '_' . trim($who, '_') . '.pdf';
}
