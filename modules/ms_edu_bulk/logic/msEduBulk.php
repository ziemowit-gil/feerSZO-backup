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
function msEduBulkPlan(M365Graph $graph, array $rows, string $domain, string $year, string $mode = 'rola', string $slug = '', int $startNr = 1): array {
    $taken = [];
    $nr = max(1, $startNr);
    $slug = msEduBulkAscii($slug);
    if ($mode === 'szkolenie' && $slug === '') $mode = 'imie_nazwisko';
    foreach ($rows as &$r) {
        $i = msEduBulkAscii($r['imie']); $n = msEduBulkAscii($r['nazwisko']);
        if ($mode === 'szkolenie') {
            $base = $slug . str_pad((string)$nr++, 2, '0', STR_PAD_LEFT);
        } elseif ($mode === 'imie_nazwisko' || ($r['rola'] ?? '') === 'nauczyciel') {
            $base = $i . '.' . $n;
        } else {
            $base = substr($i, 0, 1) . '.' . $n . $year;
        }
        $cand = $base; $k = 0;
        while (isset($taken[$cand]) || $graph->login_exists($cand . '@' . $domain)) {
            $k++; $cand = $base . '.' . $k;
            if ($k > 99) break;
        }
        $taken[$cand] = true;
        $r['upn'] = $cand . '@' . $domain;
    }
    unset($r);
    return $rows;
}

/** Id grupy o danej nazwie (tworzy, jeśli brak). */
function msEduBulkGroupId(M365Graph $graph, string $name, array &$cache): string {
    if (isset($cache[$name])) return $cache[$name];
    foreach ($graph->get_security_groups() as $g) {
        if (strcasecmp($g['displayName'] ?? '', $name) === 0) return $cache[$name] = $g['id'];
    }
    $g = $graph->create_group($name, preg_replace('/[^A-Za-z0-9_]/', '', $name));
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
            $u = $graph->create_user_full($r['upn'], $r['imie'], $r['nazwisko'], $pass, $r['klasa']);
            $warn = [];
            if (!empty($skus[$r['rola']])) {
                try { $graph->assign_license($u['id'], $skus[$r['rola']]); } catch (\Throwable $e) { $warn[] = 'licencja: ' . $e->getMessage(); }
            }
            if ($groups) try {
                $gname = $r['rola'] === 'uczen' ? 'Uczniowie_' . $year : 'Nauczyciele';
                $graph->add_to_group($u['id'], msEduBulkGroupId($graph, $gname, $gc));
            } catch (\Throwable $e) { $warn[] = 'grupa: ' . $e->getMessage(); }
            $out['ok'][] = $r + ['ms_user_id' => $u['id'], 'password' => $pass, 'warn' => implode('; ', $warn)];
        } catch (\Throwable $e) {
            $out['errors'][] = $r['upn'] . ': ' . $e->getMessage();
        }
    }
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
function msEduBulkAnonPlan(M365Graph $graph, string $szkolenie, int $count, int $start, string $sep, int $pad, string $domain): array {
    $slug = msEduBulkAscii($szkolenie);
    if ($slug === '') return ['rows' => [], 'skipped' => [], 'error' => 'Podaj nazwę szkolenia.'];
    if (!in_array($sep, ['', '.', '_', '-'], true)) $sep = '.';
    $count = min(max($count, 1), 200); $start = max($start, 1); $pad = min(max($pad, 0), 4);
    $label = trim($szkolenie);
    $rows = []; $skipped = []; $nr = $start; $guard = 0;
    while (count($rows) < $count && $guard++ < $count + 500) {
        $num = $pad > 0 ? str_pad((string)$nr, $pad, '0', STR_PAD_LEFT) : (string)$nr;
        $upn = $slug . $sep . $num . '@' . $domain;
        if ($graph->login_exists($upn)) { $skipped[] = $nr; $nr++; continue; }
        $rows[] = ['imie' => $label, 'nazwisko' => $num, 'rola' => 'uczen', 'klasa' => $label, 'email' => '', 'upn' => $upn, 'anonim' => 1];
        $nr++;
    }
    return ['rows' => $rows, 'skipped' => $skipped, 'error' => null];
}
