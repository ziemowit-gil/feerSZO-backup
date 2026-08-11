<?php
/**
 * migrate_jrwa_rebuild.php — Przebudowa JRWA zgodnie ze Statutem Fundacji
 *
 * Co robi:
 *   1. Usuwa błędnie umieszczone wpisy (311-392 pod klasami finansowymi, dodane omyłkowo).
 *   2. Przemianowuje klasę 3 (FINANSE→Działalność merytoryczna §6) i jej podklasy 31-38.
 *   3. Dodaje finanse jako podklasę 27.x pod klasą 2 (Administracja/Majątek/IT).
 *   4. Dodaje 3-cyfrowe segregatory §7 (formy realizacji) pod każdym celem §6.
 *   5. Idempotentny — bezpieczny do wielokrotnego uruchomienia.
 *
 * Uruchom: php migrate_jrwa_rebuild.php
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
if (PHP_SAPI !== 'cli') {
    require_login();
    if (!is_admin()) { http_response_code(403); echo "403 — tylko administrator może uruchomić tę migrację.\n"; exit; }
    header('Content-Type: text/plain; charset=utf-8');
}
require_once __DIR__ . '/includes/ezd.php';   // uruchamia migrację tabeli

$pdo = db();
$pdo->beginTransaction();

try {
    $changed = 0;

    // ── Helpers ────────────────────────────────────────────────────────────────
    $find = $pdo->prepare("SELECT id FROM ezd_jrwa WHERE symbol=? LIMIT 1");
    $ins  = $pdo->prepare("INSERT INTO ezd_jrwa (symbol,title,kat_arch,description,sort_order,parent_id) VALUES (?,?,?,?,?,?)");

    function jrwa_id(string $sym, PDOStatement $find): ?int {
        $find->execute([$sym]);
        $r = $find->fetchColumn();
        return $r !== false ? (int)$r : null;
    }

    function jrwa_add(string $sym, string $tit, string $kat, string $desc, int $ord, ?int $pid,
                      PDOStatement $find, PDOStatement $ins, PDO $pdo, int &$changed): int {
        $find->execute([$sym]);
        $existing = $find->fetchColumn();
        if ($existing !== false) {
            return (int)$existing;
        }
        $ins->execute([$sym, $tit, $kat, $desc, $ord, $pid]);
        $id = (int)$pdo->lastInsertId();
        echo "  [ADD]  {$sym} — {$tit}\n";
        $changed++;
        return $id;
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // KROK 1: Usuń błędnie dodane wpisy (ID 147–170, dzieci klas finansowych)
    // ═══════════════════════════════════════════════════════════════════════════
    echo "── Krok 1: Usuwanie błędnych wpisów pod klasami finansowymi ──\n";
    $bad_ids = range(147, 170);
    // Sprawdź czy nie mają teczek (bezpieczeństwo)
    foreach ($bad_ids as $bid) {
        $cnt = $pdo->query("SELECT COUNT(*) FROM ezd_teczki WHERE jrwa_id={$bid}")->fetchColumn();
        if ($cnt > 0) {
            throw new \RuntimeException("Klasa JRWA id={$bid} ma {$cnt} teczek — nie można usunąć!");
        }
    }
    $in  = implode(',', $bad_ids);
    $del = $pdo->exec("DELETE FROM ezd_jrwa WHERE id IN ({$in})");
    echo "  Usunięto {$del} błędnych wpisów (ID 147–170).\n";
    $changed += $del;

    // ═══════════════════════════════════════════════════════════════════════════
    // KROK 2: Przemianuj klasę 3 na Działalność merytoryczną
    // ═══════════════════════════════════════════════════════════════════════════
    echo "\n── Krok 2: Przemianowanie klasy 3 (FINANSE → Działalność §6) ──\n";

    $map3 = [
        '3'  => ['Działalność merytoryczna — Cele Fundacji §6 Statutu',     '',    'Klasy 31–39 odpowiadają celom §6 Statutu; podklasy 3xx to formy realizacji (§7). Każde działanie/projekt dostaje własny segregator.'],
        '30' => ['Planowanie i monitorowanie działalności statutowej',       'B10', 'Plany działań, harmonogramy, sprawozdania merytoryczne wewnętrzne'],
        '31' => ['Przeciwdziałanie wykluczeniu społecznemu',                 '',    '§6 pkt 1 Statutu'],
        '32' => ['Działalność edukacyjna',                                  '',    '§6 pkt 2 Statutu'],
        '33' => ['Promocja i organizacja wolontariatu',                     '',    '§6 pkt 3 Statutu'],
        '34' => ['Podnoszenie kwalifikacji zawodowych os. z niepełnospr.',  '',    '§6 pkt 4 Statutu'],
        '35' => ['Promowanie samorozwoju os. z niepełnosprawnościami',      '',    '§6 pkt 5 Statutu'],
        '36' => ['Działania na rzecz osób starszych',                       '',    '§6 pkt 6 Statutu'],
        '37' => ['Integracja osób z niepełnosprawnościami',                 '',    '§6 pkt 7 Statutu'],
        '38' => ['Promowanie tyfloinformatyki',                             '',    '§6 pkt 8 Statutu'],
        '39' => ['Działalność na rzecz NGO i aktywizacja społeczeństwa',   '',    '§6 pkt 9 Statutu'],
    ];
    $upd = $pdo->prepare("UPDATE ezd_jrwa SET title=?, kat_arch=?, description=? WHERE symbol=?");
    foreach ($map3 as $sym => [$tit, $kat, $desc]) {
        $find->execute([$sym]);
        if ($find->fetchColumn() !== false) {
            $upd->execute([$tit, $kat, $desc, $sym]);
            echo "  [UPD]  {$sym} → {$tit}\n";
            $changed++;
        } else {
            echo "  [SKIP] {$sym} — nie istnieje (pomijam)\n";
        }
    }

    // Klasa 39 mogła nie istnieć przed krokiem 1 — upewnij się
    $id3 = jrwa_id('3', $find);
    if (!jrwa_id('39', $find) && $id3) {
        jrwa_add('39', 'Działalność na rzecz NGO i aktywizacja społeczeństwa', '', '§6 pkt 9 Statutu', 900, $id3, $find, $ins, $pdo, $changed);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // KROK 3: Dodaj finanse pod klasę 2 (jako podklasa 27)
    // ═══════════════════════════════════════════════════════════════════════════
    echo "\n── Krok 3: Finanse → podklasa 27.x pod klasą 2 ──\n";
    $id2 = jrwa_id('2', $find);
    if (!$id2) {
        echo "  [SKIP] Klasa 2 nie istnieje — pomijam finanse.\n";
    } else {
        $id27 = jrwa_add('27', 'Finanse i dokumentacja finansowa', '', 'Dokumentacja księgowa Fundacji — przeniesiona z kl. 3 (poprzedni schemat).', 270, $id2, $find, $ins, $pdo, $changed);
        $finSub = [
            ['270', 'Polityka rachunkowości i plan kont',              'B10', 'Polityka rachunkowości, ZFŚS, polityka odpisów'],
            ['271', 'Dowody księgowe — faktury i rachunki',            'B5',  'Faktury kosztowe i sprzedażowe, rachunki, noty'],
            ['272', 'Obrót pieniężny — kasa i wyciągi bankowe',        'B5',  'Raporty kasowe, wyciągi bankowe, polecenia przelewu'],
            ['273', 'Rozliczenia podatkowe — CIT, VAT, PIT',          'B5',  'Deklaracje podatkowe i korekty'],
            ['274', 'Wynagrodzenia — listy płac i karty wynagrodzeń', 'B50', 'Listy płac, zasiłki, PFRON'],
            ['275', 'Składki ZUS — deklaracje i wpłaty',              'B50', 'Deklaracje ZUS, raporty RMUA'],
            ['276', 'Roczne sprawozdania finansowe',                  'A',   'Bilans, RZiS, informacja dodatkowa, podpisane elektronicznie'],
            ['277', 'Majątek i inwentaryzacja',                       'B10', 'Środki trwałe, wyposażenie, KŚT, spisy z natury'],
        ];
        $ord = 10;
        foreach ($finSub as [$sym, $tit, $kat, $desc]) {
            jrwa_add($sym, $tit, $kat, $desc, $ord, $id27, $find, $ins, $pdo, $changed);
            $ord += 10;
        }
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // KROK 4: Segregatory §7 (formy realizacji) pod każdym celu §6
    // ═══════════════════════════════════════════════════════════════════════════
    echo "\n── Krok 4: Segregatory §7 (formy realizacji) pod celami §6 ──\n";

    $segregatory = [
        // [parent_sym, [symbol, tytuł, kat_arch, opis_§7]]
        '31' => [
            ['311', 'Spotkania i konferencje — wykluczenie społeczne',       'B5',  '§7 pkt 2, 3: organizacja i udział w konferencjach'],
            ['312', 'Szkolenia, warsztaty i praktyki zawodowe',              'B5',  '§7 pkt 7, 8: szkolenia, kursy, praktyki dla osób zagrożonych wyklucz.'],
            ['313', 'Dokumentacja i sprawozdania działań',                   'B10', 'Raporty, analizy, ewaluacje dla tej sfery'],
        ],
        '32' => [
            ['321', 'Wydawnictwa i portal internetowy',                     'B10', '§7 pkt 1, 10: działalność wydawnicza i prowadzenie portalu'],
            ['322', 'Współpraca z podmiotami edukacyjnymi',                 'B10', '§7 pkt 4: umowy i korespondencja z partnerami edukacyjnymi'],
            ['323', 'Kursy, szkolenia i warsztaty edukacyjne',              'B5',  '§7 pkt 7: kursy, szkolenia, warsztaty (działalność edukacyjna)'],
            ['324', 'Organizacja wydarzeń online — edukacja',               'B5',  '§7 pkt 12: webinary, e-learning, szkolenia online'],
        ],
        '33' => [
            ['331', 'Rekrutacja wolontariuszy i porozumienia',              'B10', '§7 pkt 9: aktywne angażowanie wolontariuszy'],
            ['332', 'Szkolenia, akcje i dokumentacja wolontariatu',         'B5',  '§7 pkt 7, 9: szkolenia wolontariackie, listy obecności, raporty'],
        ],
        '34' => [
            ['341', 'Doradztwo zawodowe i technologie wspomagające (AT)',   'B5',  '§7 pkt 5: doradztwo w zakresie technologii wspomagających'],
            ['342', 'Kursy, szkolenia i warsztaty zawodowe',                'B5',  '§7 pkt 7: szkolenia podnoszące kwalifikacje i samodzielność'],
            ['343', 'Praktyki i staże dla osób z niepełnosprawnościami',    'B10', '§7 pkt 8: organizacja praktyk/staży zawodowych'],
        ],
        '35' => [
            ['351', 'Prezentacje i pokazy technologii wspomagających',      'B5',  '§7 pkt 6: prezentacje/demonstracje technologii wspomagających'],
            ['352', 'Warsztaty samorozwojowe',                             'B5',  '§7 pkt 7: warsztaty motywacyjne i rozwijające samodzielność'],
        ],
        '36' => [
            ['361', 'Spotkania i konferencje dla seniorów',                'B5',  '§7 pkt 2, 3: eventy, konferencje dla osób starszych'],
            ['362', 'Szkolenia i warsztaty dla seniorów',                  'B5',  '§7 pkt 7: szkolenia podnoszące sprawność i samodzielność seniorów'],
        ],
        '37' => [
            ['371', 'Imprezy i wydarzenia integracyjne',                   'B5',  '§7 pkt 2, 12: konferencje, eventy, spotkania integracyjne'],
            ['372', 'Programy wsparcia, doradztwa i asysty',               'B5',  '§7 pkt 5, 7: doradztwo, szkolenia integracyjne'],
        ],
        '38' => [
            ['381', 'Doradztwo i konsultacje tyfloinformatyczne',          'B5',  '§7 pkt 5: doradztwo AT — technologie dla niewidomych/słabowidzących'],
            ['382', 'Prezentacje, demonstracje i pokazy AT',                'B5',  '§7 pkt 6: prezentacje technologii wspomagających — tyfloinf.'],
            ['383', 'Szkolenia tyfloinformatyczne',                        'B5',  '§7 pkt 7: kursy i warsztaty tyfloinformatyczne'],
        ],
        '39' => [
            ['391', 'Szkolenia i warsztaty dla organizacji pozarządowych', 'B5',  '§7 pkt 11: szkolenia dla przedstawicieli NGO'],
            ['392', 'Inicjatywy i programy aktywizacyjne',                 'B5',  '§7 pkt 13: inicjatywy na rzecz aktywizacji społeczeństwa'],
        ],
    ];

    foreach ($segregatory as $parentSym => $kids) {
        $pid = jrwa_id($parentSym, $find);
        if (!$pid) { echo "  [SKIP] Brak klasy {$parentSym} — pomijam jej dzieci.\n"; continue; }
        $ord = 10;
        foreach ($kids as [$sym, $tit, $kat, $desc]) {
            jrwa_add($sym, $tit, $kat, $desc, $ord, $pid, $find, $ins, $pdo, $changed);
            $ord += 10;
        }
    }

    $pdo->commit();
    echo "\n══════════════════════════════════════════════════════════════\n";
    echo "Migracja zakończona. Łącznie zmian: {$changed}.\n";

} catch (\Throwable $e) {
    $pdo->rollBack();
    echo "\nBŁĄD — rollback!\n" . $e->getMessage() . "\n";
    exit(1);
}
