<?php
/**
 * Hurtowe generowanie kont Microsoft 365 Education z listy osób (uczniowie / nauczyciele).
 *
 * Wejście: wiersze „Imię;Nazwisko;Rola;Klasa" (separator ; , lub tabulator).
 * Login: uczeń  i.nazwisko{rok}@domena, nauczyciel imie.nazwisko@domena;
 * duplikat → sufiks .1, .2 …; polskie znaki → łacińskie. Licencja wg roli,
 * grupa „Uczniowie_{rok}" / „Nauczyciele" (tworzona, jeśli brak), UsageLocation PL.
 */

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
 */
function msEduBulkPlan(M365Graph $graph, array $rows, string $domain, string $year): array {
    $taken = [];
    foreach ($rows as &$r) {
        $i = msEduBulkAscii($r['imie']); $n = msEduBulkAscii($r['nazwisko']);
        $base = $r['rola'] === 'uczen' ? substr($i, 0, 1) . '.' . $n . $year : $i . '.' . $n;
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
function msEduBulkCreate(M365Graph $graph, array $planned, array $skus, string $year): array {
    $out = ['ok' => [], 'errors' => []]; $gc = [];
    foreach ($planned as $r) {
        try {
            $pass = M365Graph::generate_password(16);
            $u = $graph->create_user_full($r['upn'], $r['imie'], $r['nazwisko'], $pass, $r['klasa']);
            $warn = [];
            if (!empty($skus[$r['rola']])) {
                try { $graph->assign_license($u['id'], $skus[$r['rola']]); } catch (\Throwable $e) { $warn[] = 'licencja: ' . $e->getMessage(); }
            }
            try {
                $gname = $r['rola'] === 'uczen' ? 'Uczniowie_' . $year : 'Nauczyciele';
                $graph->add_to_group($u['id'], msEduBulkGroupId($graph, $gname, $gc));
            } catch (\Throwable $e) { $warn[] = 'grupa: ' . $e->getMessage(); }
            $out['ok'][] = $r + ['password' => $pass, 'warn' => implode('; ', $warn)];
        } catch (\Throwable $e) {
            $out['errors'][] = $r['upn'] . ': ' . $e->getMessage();
        }
    }
    return $out;
}
