<?php
/**
 * Hurtowe generowanie kont Microsoft 365 Education z listy osób (uczniowie / nauczyciele).
 *
 * Wejście: wiersze „Imię;Nazwisko;Rola;Klasa" (separator ; , lub tabulator).
 * Login: uczeń  i.nazwisko{rok}@domena, nauczyciel imie.nazwisko@domena;
 * duplikat → sufiks .1, .2 …; polskie znaki → łacińskie. Licencja wg roli,
 * grupa „Uczniowie_{rok}" / „Nauczyciele" (tworzona, jeśli brak), UsageLocation PL.
 */

require_once dirname(__DIR__, 3) . '/includes/m365.php';
require_once __DIR__ . '/msEduBulkAccounts.php';

function msEduBulkAscii(string $s): string {
    $s = strtr($s, ['ą'=>'a','ć'=>'c','ę'=>'e','ł'=>'l','ń'=>'n','ó'=>'o','ś'=>'s','ź'=>'z','ż'=>'z',
                    'Ą'=>'A','Ć'=>'C','Ę'=>'E','Ł'=>'L','Ń'=>'N','Ó'=>'O','Ś'=>'S','Ź'=>'Z','Ż'=>'Z']);
    $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    if ($t !== false) $s = $t;
    return strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $s));
}

function msEduBulkRole(string $raw): ?string {
    $r = msEduBulkAscii($raw);
    if (in_array($r, ['uczen', 'uczennica', 'student', 'u'], true)) return 'uczen';
    if (in_array($r, ['nauczyciel', 'nauczycielka', 'teacher', 'pracownik', 'n'], true)) return 'nauczyciel';
    return null;
}

/** @return array{rows:array<int,array>,errors:string[]} */
function msEduBulkParse(string $text): array {
    $rows = []; $errors = [];
    foreach (preg_split('/\R/u', $text) as $n => $line) {
        $line = trim($line);
        if ($line === '') continue;
        $p = array_map('trim', preg_split('/[;\t,]/u', $line));
        if (count($p) < 3) { $errors[] = 'Wiersz ' . ($n + 1) . ': oczekiwano Imię;Nazwisko;Rola[;Klasa].'; continue; }
        $role = msEduBulkRole($p[2]);
        if ($p[0] === '' || $p[1] === '') { $errors[] = 'Wiersz ' . ($n + 1) . ': brak imienia lub nazwiska.'; continue; }
        if (!$role) { $errors[] = 'Wiersz ' . ($n + 1) . ': nieznana rola „' . $p[2] . '” (uczeń / nauczyciel).'; continue; }
        $rows[] = ['imie' => $p[0], 'nazwisko' => $p[1], 'rola' => $role,
                   'klasa' => ($p[3] ?? '') !== '' ? $p[3] : ($role === 'nauczyciel' ? 'Kadra Pedagogiczna' : '')];
    }
    return ['rows' => $rows, 'errors' => $errors];
}

/** Czy konto o tym UPN założył ten system (rejestr generatora lub konto kursanta TI) — tylko takie wolno nadpisać. */
function msEduBulkOwnedUpn(string $upn): bool {
    try {
        msEduBulkMigrate();
        $st = db()->prepare("SELECT 1 FROM msedu_bulk_accounts WHERE LOWER(upn)=LOWER(?)"); $st->execute([$upn]);
        if ($st->fetchColumn()) return true;
        $st = db()->prepare("SELECT 1 FROM k30_ti_student_accounts WHERE LOWER(ms_upn)=LOWER(?)"); $st->execute([$upn]);
        return (bool)$st->fetchColumn();
    } catch (\Throwable $e) { return false; }
}

/** Skrótowiec nazwy szkolenia: słowa pisane WIELKIMI (≤5 zn.) zostają w całości, pozostałe → pierwsza litera. */
function msEduBulkAbbrev(string $name): string {
    $out = '';
    foreach (preg_split('/[\s\-_\/]+/u', trim($name)) as $w) {
        if ($w === '') continue;
        $a = msEduBulkAscii($w);
        if ($a === '') continue;
        $out .= (mb_strtoupper($w) === $w && mb_strlen($w) <= 5) ? $a : substr($a, 0, 1);
    }
    return $out;
}

/** <option> schematów loginu (wspólne dla kreatorów). */
function msEduBulkModeOptions(string $mode, bool $withRole = true): string {
    $opts = ($withRole ? ['rola' => 'wg roli (uczeń i.nazwisko+rok / nauczyciel imie.nazwisko)'] : []) + [
        'szkolenie'     => 'nazwa szkolenia + numer (excel01)',
        'is_skrot'      => 'i.nazwisko.skrót szkolenia (j.kowalski.co)',
        'in_skrot'      => 'imie.nazwisko.skrót szkolenia (jan.kowalski.co)',
        'skrot_is'      => 'skrót szkolenia.i.nazwisko (co.j.kowalski)',
        'skrot_in'      => 'skrót szkolenia.imie.nazwisko (co.jan.kowalski)',
        'imie_nazwisko' => 'imie.nazwisko (bez szkolenia)',
    ];
    $h = '';
    foreach ($opts as $k => $l) $h .= '<option value="' . $k . '"' . ($k === $mode ? ' selected' : '') . '>' . htmlspecialchars($l, ENT_QUOTES, 'UTF-8') . '</option>';
    return $h;
}

/**
 * Wylicza unikalne loginy (sprawdza Graph + loginy już zaplanowane w tej partii).
 * Dodaje klucz 'upn' do każdego wiersza.
 *
 * Tryby ($mode):
 *  - 'rola'          uczeń i.nazwisko{rok}, nauczyciel imie.nazwisko
 *  - 'imie_nazwisko' imie.nazwisko dla wszystkich
 *  - 'szkolenie'     {nazwa szkolenia}{nr}, np. excel01, excel02 … ($slug = nazwa szkolenia)
 * Duplikat dostaje sufiks .1, .2 …
 */
function msEduBulkPlan(M365Graph $graph, array $rows, string $domain, string $year, string $mode = 'rola', string $slug = '', int $startNr = 1, string $abbr = '', bool $overwrite = false): array {
    $taken = [];
    $nr = max(1, $startNr);
    $abbr = msEduBulkAscii($abbr) ?: msEduBulkAbbrev($slug); // skrót z oryginalnej nazwy (wielkie litery!)
    $slug = msEduBulkAscii($slug);
    if ($mode === 'szkolenie' && $slug === '') $mode = 'imie_nazwisko';
    if (in_array($mode, ['is_skrot', 'in_skrot', 'skrot_is', 'skrot_in'], true) && $abbr === '') $mode = 'imie_nazwisko';
    foreach ($rows as &$r) {
        $i = msEduBulkAscii($r['imie']); $n = msEduBulkAscii($r['nazwisko']);
        if ($mode === 'szkolenie') {
            $base = $slug . str_pad((string)$nr++, 2, '0', STR_PAD_LEFT);
        } elseif (in_array($mode, ['is_skrot', 'in_skrot', 'skrot_is', 'skrot_in'], true)) {
            // nazwa/skrót szkolenia w loginie: i.nazwisko.skrot | imie.nazwisko.skrot | skrot.i.nazwisko | skrot.imie.nazwisko
            $who = in_array($mode, ['is_skrot', 'skrot_is'], true) ? substr($i, 0, 1) . '.' . $n : $i . '.' . $n;
            $base = str_ends_with($mode, '_skrot') ? $who . '.' . $abbr : $abbr . '.' . $who;
        } elseif ($mode === 'imie_nazwisko' || ($r['rola'] ?? '') === 'nauczyciel') {
            $base = $i . '.' . $n;
        } else {
            $base = substr($i, 0, 1) . '.' . $n . $year;
        }
        $cand = $base; $k = 0;
        while (true) {
            if (isset($taken[$cand])) { $k++; $cand = $base . '.' . $k; if ($k > 99) break; continue; }
            if ($graph->login_exists($cand . '@' . $domain)) {
                // Duplikat: nadpisz własne konto (z rejestru generatora / TI), obce konto — dodaj sufiks
                if ($overwrite && msEduBulkOwnedUpn($cand . '@' . $domain)) { $r['nadpisz'] = 1; break; }
                $k++; $cand = $base . '.' . $k; if ($k > 99) break; continue;
            }
            break;
        }
        $taken[$cand] = true;
        $r['upn'] = $cand . '@' . $domain;
    }
    unset($r);
    return $rows;
}

/** Lista SKU z pola formularza (string albo tablica) — bez pustych i duplikatów, max 2. */
function msEduBulkSkuList(mixed $v): array {
    return array_slice(array_values(array_unique(array_filter(array_map('strval', (array)$v), fn($x) => $x !== ''))), 0, 2);
}

/**
 * Dodaje użytkownika do grupy z ponawianiem: świeżo utworzone konto/grupa w Entra ID bywa
 * niewidoczne przez kilka sekund (replikacja) i Graph zwraca wtedy 404.
 */
function msEduBulkAddToGroup(M365Graph $graph, string $userId, string $groupId, int $tries = 6): void {
    for ($i = 1; ; $i++) {
        try { $graph->add_to_group($userId, $groupId); return; }
        catch (\RuntimeException $e) {
            if ($i >= $tries || !str_contains($e->getMessage(), '404')) throw $e;
            sleep(2 * $i > 6 ? 6 : 2 * $i);
        }
    }
}

/** Id grupy o danej nazwie (tworzy, jeśli brak). */
function msEduBulkGroupId(M365Graph $graph, string $name, array &$cache): string {
    if (isset($cache[$name])) return $cache[$name];
    foreach ($graph->get_security_groups() as $g) {
        if (strcasecmp($g['displayName'] ?? '', $name) === 0) return $cache[$name] = $g['id'];
    }
    $nick = preg_replace('/[^A-Za-z0-9_]/', '', msEduBulkAscii($name)) ?: ('grupa' . substr(md5($name), 0, 6));
    $g = $graph->create_group($name, $nick, 'Grupa utworzona w kreatorze kont EDU (SZO)');
    $cache['__new'][] = $name;
    return $cache[$name] = $g['id'];
}

/**
 * Tworzy konta wg planu.
 * @param array $skus ['uczen'=>skuId|'', 'nauczyciel'=>skuId|'']
 * @return array{ok:array,errors:string[]}
 */
function msEduBulkCreate(M365Graph $graph, array $planned, array $skus, string $year, bool $groups = true): array {
    $out = ['ok' => [], 'errors' => []]; $gc = [];
    foreach ($planned as $r) {
        try {
            $pass = M365Graph::generate_password(16);
            $warn = [];
            if (!empty($r['nadpisz'])) {
                // Duplikat loginu → nadpisanie własnego konta: nowe dane, nowe hasło (wymuszona zmiana), konto włączone
                $eid = $graph->user_id_by_upn($r['upn']);
                if (!$eid) throw new RuntimeException('Konto do nadpisania nie istnieje już w tenancie.');
                $graph->update_user_full($eid, $r['imie'], $r['nazwisko'], $r['klasa']);
                $graph->set_password($eid, $pass, true);
                $u = ['id' => $eid];
                $warn[] = 'nadpisano istniejące konto';
            } else {
                $u = $graph->create_user_full($r['upn'], $r['imie'], $r['nazwisko'], $pass, $r['klasa']);
            }
            // Do 2 licencji na konto (np. A1 + dodatek); kolejne przypisania liczą się osobno.
            foreach (msEduBulkSkuList($skus[$r['rola']] ?? []) as $sku) {
                try { $graph->assign_license($u['id'], $sku); } catch (\Throwable $e) { $warn[] = 'licencja: ' . $e->getMessage(); }
            }
            if ($groups) try {
                $gname = $r['rola'] === 'uczen' ? 'Uczniowie_' . $year : 'Nauczyciele';
                msEduBulkAddToGroup($graph, $u['id'], msEduBulkGroupId($graph, $gname, $gc));
            } catch (\Throwable $e) { $warn[] = 'grupa: ' . $e->getMessage(); }
            // Grupa jawna (z TI / nowa / z kolumny) → grupa bezpieczeństwa w MS365; tworzona, jeśli jej brak
            if (!empty($r['grupa'])) try {
                msEduBulkAddToGroup($graph, $u['id'], msEduBulkGroupId($graph, $r['grupa'], $gc));
            } catch (\Throwable $e) { $warn[] = 'grupa „' . $r['grupa'] . '”: ' . $e->getMessage(); }
            msEduBulkRegister($u['id'], $r);
            $out['ok'][] = $r + ['ms_user_id' => $u['id'], 'password' => $pass, 'warn' => implode('; ', $warn)];
        } catch (\Throwable $e) {
            $out['errors'][] = $r['upn'] . ': ' . $e->getMessage();
        }
    }
    $out['groups_created'] = $gc['__new'] ?? [];
    return $out;
}

/**
 * PDF z wygenerowanymi kontami — jedno konto w jednej linijce (Lp, osoba, login, hasło).
 * @param array $rows wiersze z kluczami imie, nazwisko, upn, password
 */
function msEduBulkPdf(array $rows, string $title = 'Konta Microsoft 365'): string {
    require_once dirname(__DIR__, 3) . '/vendor/autoload.php';
    $tmp = rtrim(defined('UPLOAD_DIR') ? UPLOAD_DIR : (dirname(__DIR__, 3) . '/uploads'), '/') . '/mpdf_tmp';
    if (!is_dir($tmp)) @mkdir($tmp, 0755, true);
    $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $html = '<style>body{font-family:dejavusans;font-size:9pt}h1{font-size:13pt;margin:0 0 2mm}'
          . 'table{width:100%;border-collapse:collapse}td,th{border-bottom:0.3pt solid #999;padding:1.6mm 1.5mm;white-space:nowrap}'
          . 'th{background:#eee;text-align:left;font-size:8pt}.m{font-family:dejavusansmono;font-size:8.5pt}</style>'
          . '<h1>' . $e($title) . '</h1><div style="font-size:8pt;color:#555;margin-bottom:3mm">'
          . 'Wygenerowano: ' . date('d.m.Y H:i') . ' · Hasła tymczasowe — zmiana przy pierwszym logowaniu. Dokument poufny.</div>'
          . '<table><thead><tr><th style="width:8mm">Lp</th><th>Osoba</th><th>Login</th><th>Hasło tymczasowe</th></tr></thead><tbody>';
    foreach (array_values($rows) as $i => $r) {
        $html .= '<tr><td>' . ($i + 1) . '</td><td>' . $e(trim(($r['imie'] ?? '') . ' ' . ($r['nazwisko'] ?? '')))
              . '</td><td class="m">' . $e($r['upn']) . '</td><td class="m">' . $e($r['password']) . '</td></tr>';
    }
    $html .= '</tbody></table>';
    $mpdf = new \Mpdf\Mpdf(['mode' => 'utf-8', 'format' => 'A4', 'margin_left' => 12, 'margin_right' => 12,
                            'margin_top' => 12, 'margin_bottom' => 12, 'default_font' => 'dejavusans', 'tempDir' => $tmp]);
    $mpdf->SetTitle($title);
    $mpdf->SetFooter('{PAGENO} / {nbpg}');
    $mpdf->WriteHTML($html);
    return (string)$mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
}

/**
 * Plan kont anonimowych: {szkolenie}{separator}{nr}, np. excel.01, excel_02, excel3.
 * Zajęte numery są pomijane (numeracja idzie dalej), więc login nigdy nie dostaje sufiksu.
 *
 * @param string $sep ''|'.'|'_'|'-'   @param int $pad liczba cyfr numeru (0 = bez zer wiodących)
 * @return array{rows:array,skipped:int[],error:?string}
 */
function msEduBulkAnonPlan(M365Graph $graph, string $szkolenie, int $count, int $start, string $sep, int $pad, string $domain, bool $overwrite = false): array {
    $slug = msEduBulkAscii($szkolenie);
    if ($slug === '') return ['rows' => [], 'skipped' => [], 'error' => 'Podaj nazwę szkolenia.'];
    if (!in_array($sep, ['', '.', '_', '-'], true)) $sep = '.';
    $count = min(max($count, 1), 200); $start = max($start, 1); $pad = min(max($pad, 0), 4);
    $label = trim($szkolenie);
    $rows = []; $skipped = []; $nr = $start; $guard = 0;
    while (count($rows) < $count && $guard++ < $count + 500) {
        $num = $pad > 0 ? str_pad((string)$nr, $pad, '0', STR_PAD_LEFT) : (string)$nr;
        $upn = $slug . $sep . $num . '@' . $domain;
        $over = false;
        if ($graph->login_exists($upn)) {
            if (!($overwrite && msEduBulkOwnedUpn($upn))) { $skipped[] = $nr; $nr++; continue; }
            $over = true;
        }
        $rows[] = ['imie' => $label, 'nazwisko' => $num, 'rola' => 'uczen', 'klasa' => $label, 'email' => '', 'upn' => $upn, 'anonim' => 1] + ($over ? ['nadpisz' => 1] : []);
        $nr++;
    }
    return ['rows' => $rows, 'skipped' => $skipped, 'error' => null];
}

/**
 * PDF w układzie kartek do rozcięcia: 2 kolumny × 5 wierszy na A4 (10 kart), przerywane ramki.
 * Każda karta = jedno konto (osoba, login, hasło, krótka instrukcja).
 */
function msEduBulkPdfCards(array $rows, string $title = 'Konto Microsoft 365'): string {
    require_once dirname(__DIR__, 3) . '/vendor/autoload.php';
    $tmp = rtrim(defined('UPLOAD_DIR') ? UPLOAD_DIR : (dirname(__DIR__, 3) . '/uploads'), '/') . '/mpdf_tmp';
    if (!is_dir($tmp)) @mkdir($tmp, 0755, true);
    $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $org = defined('ORG_NAME') ? ORG_NAME : '';
    $card = function (?array $r) use ($e, $org, $title) {
        if (!$r) return '<td class="c empty"></td>';
        $anon = !empty($r['anonim']);
        $who = $anon ? ($r['klasa'] ?? '') : trim(($r['imie'] ?? '') . ' ' . ($r['nazwisko'] ?? ''));
        return '<td class="c"><div class="o">' . $e($org ?: $title) . '</div>'
             . ($who !== '' ? '<div class="w">' . $e($who) . '</div>' : '')
             . '<div class="l">Login</div><div class="m">' . $e($r['upn']) . '</div>'
             . '<div class="l">Hasło tymczasowe</div><div class="m p">' . $e($r['password']) . '</div>'
             . '<div class="i">Zaloguj się na office.com. Przy pierwszym logowaniu ustaw własne hasło. Nie udostępniaj tych danych.</div></td>';
    };
    $html = '<style>table{width:100%;border-collapse:collapse}td.c{width:50%;height:53mm;border:0.4pt dashed #666;padding:3.5mm 4mm;vertical-align:top;font-family:dejavusans}'
          . '.o{font-size:7pt;color:#666;text-transform:uppercase}.w{font-size:11pt;font-weight:bold;margin:1mm 0 2mm}'
          . '.l{font-size:6.5pt;color:#666;margin-top:1.5mm}.m{font-family:dejavusansmono;font-size:9pt}.p{font-size:11pt;font-weight:bold}'
          . '.i{font-size:6.5pt;color:#555;margin-top:2.5mm}</style>';
    $rows = array_values($rows);
    foreach (array_chunk($rows, 10) as $pi => $page) {
        if ($pi > 0) $html .= '<pagebreak />';
        $html .= '<table>';
        foreach (array_chunk($page, 2) as $pair) $html .= '<tr>' . $card($pair[0]) . $card($pair[1] ?? null) . '</tr>';
        $html .= '</table>';
    }
    $mpdf = new \Mpdf\Mpdf(['mode' => 'utf-8', 'format' => 'A4', 'margin_left' => 8, 'margin_right' => 8,
                            'margin_top' => 8, 'margin_bottom' => 8, 'default_font' => 'dejavusans', 'tempDir' => $tmp]);
    $mpdf->SetTitle($title . ' — kartki');
    $mpdf->WriteHTML($html);
    return (string)$mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
}

/**
 * PDF w formie pasków: kolumny „Login | Hasło”, każdy wiersz = pasek do odcięcia
 * (linia cięcia pod każdym). $blocks=2: dwa bloki obok siebie (po 18 pasków); $blocks=1: jeden blok
 * „Login | Hasło” na całą szerokość (20 pasków na stronie).
 */
function msEduBulkPdfStrips(array $rows, string $title = 'Konta Microsoft 365', int $blocks = 2): string {
    $blocks = $blocks === 1 ? 1 : 2;
    require_once dirname(__DIR__, 3) . '/vendor/autoload.php';
    $tmp = rtrim(defined('UPLOAD_DIR') ? UPLOAD_DIR : (dirname(__DIR__, 3) . '/uploads'), '/') . '/mpdf_tmp';
    if (!is_dir($tmp)) @mkdir($tmp, 0755, true);
    $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $block = function (array $part) use ($e) {
        $h = '<table class="b"><tr><th>Login</th><th>Hasło</th></tr>';
        foreach ($part as $r) $h .= '<tr><td class="s"><div style="font-size:11pt;line-height:1">&nbsp;</div>' . $e($r['upn']) . '</td><td class="s p"><div style="font-size:11pt;line-height:1">&nbsp;</div>' . $e($r['password']) . '</td></tr>';
        return $h . '</table>';
    };
    $html = '<style>table.b{width:100%;border-collapse:separate;border-spacing:0}th{font-size:7pt;color:#666;text-align:left;padding:0 2mm 1mm;font-family:dejavusans}'
          . 'td.s{height:13.5mm;border-top:0.4pt dashed #666;padding:0 2mm;font-family:dejavusansmono;font-size:8.5pt;vertical-align:middle}'
          . 'td.p{font-size:10pt;font-weight:bold;border-left:0.4pt dotted #bbb}table.o{width:100%;border-collapse:collapse}table.o td{vertical-align:top;width:50%;padding:0 2mm}</style>';
    $per = $blocks === 1 ? 20 : 18;
    $pages = array_chunk(array_values($rows), $per * $blocks);
    foreach ($pages as $pi => $pg) {
        if ($pi > 0) $html .= '<pagebreak />';
        $cols = array_chunk($pg, $per);
        $html .= $blocks === 1
            ? '<div style="padding:0 8mm">' . $block($cols[0]) . '</div>'
            : '<table class="o"><tr><td>' . $block($cols[0]) . '</td><td>' . (isset($cols[1]) ? $block($cols[1]) : '') . '</td></tr></table>';
    }
    $mpdf = new \Mpdf\Mpdf(['mode' => 'utf-8', 'format' => 'A4', 'margin_left' => 8, 'margin_right' => 8,
                            'margin_top' => 10, 'margin_bottom' => 8, 'default_font' => 'dejavusans', 'tempDir' => $tmp]);
    $mpdf->SetTitle($title . ' — paski');
    $mpdf->WriteHTML($html);
    return (string)$mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
}
