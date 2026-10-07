<?php
/**
 * Import listy osób do generatora kont M365 Edu: wklejka (Ctrl+C / Ctrl+V z Excela,
 * wartości rozdzielone tabulatorem, ; lub ,) albo plik CSV/XLSX → siatka → mapowanie kolumn.
 */
require_once __DIR__ . '/msEduBulk.php';

const MS_EDU_BULK_FIELDS = [
    ''              => '— pomiń kolumnę —',
    'imie'          => 'Imię',
    'nazwisko'      => 'Nazwisko',
    'imie_nazwisko' => 'Imię i nazwisko (razem)',
    'email'         => 'E-mail alternatywny (wymagany)',
    'rola'          => 'Rola (uczeń / nauczyciel)',
    'klasa'         => 'Klasa / grupa / dział',
];

/** Wklejony tekst → siatka (wiersze × kolumny). */
function msEduBulkGridFromText(string $text): array {
    $lines = array_values(array_filter(preg_split('/\R/u', trim($text)), fn($l) => trim($l) !== ''));
    if (!$lines) return [];
    $tab = substr_count($lines[0], "\t"); $sc = substr_count($lines[0], ';'); $cc = substr_count($lines[0], ',');
    $delim = $tab > 0 ? "\t" : ($sc >= $cc && $sc > 0 ? ';' : ($cc > 0 ? ',' : "\t"));
    $grid = [];
    foreach ($lines as $l) $grid[] = array_map('trim', str_getcsv($l, $delim, '"', '\\'));
    return msEduBulkGridPad($grid);
}

function msEduBulkGridPad(array $grid): array {
    $w = $grid ? max(array_map('count', $grid)) : 0;
    foreach ($grid as &$r) while (count($r) < $w) $r[] = '';
    return $grid;
}

function msEduBulkGridFromCsv(string $path): array {
    $t = (string)file_get_contents($path);
    if (str_starts_with($t, "\xEF\xBB\xBF")) $t = substr($t, 3);
    if (!mb_check_encoding($t, 'UTF-8')) $t = mb_convert_encoding($t, 'UTF-8', 'Windows-1250');
    return msEduBulkGridFromText($t);
}

function msEduBulkColIdx(string $letters): int {
    $n = 0;
    foreach (str_split(strtoupper($letters)) as $ch) $n = $n * 26 + (ord($ch) - 64);
    return $n - 1;
}

function msEduBulkGridFromXlsx(string $path): array {
    if (!class_exists('ZipArchive')) throw new RuntimeException('Rozszerzenie PHP „zip” jest wymagane do obsługi XLSX.');
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new RuntimeException('Nie można otworzyć pliku XLSX.');
    $strip = fn($x) => preg_replace('/\s+xmlns(?::[a-zA-Z0-9_]+)?="[^"]*"/', '', $x);
    $shared = [];
    if (($ssXml = $zip->getFromName('xl/sharedStrings.xml')) !== false && ($ss = simplexml_load_string($strip($ssXml)))) {
        foreach ($ss->si as $si) {
            if (count($si->r ?? []) > 0) { $t = ''; foreach ($si->r as $r) $t .= (string)($r->t ?? ''); $shared[] = $t; }
            else $shared[] = (string)($si->t ?? '');
        }
    }
    $sheet = 'xl/worksheets/sheet1.xml';
    if (($rx = $zip->getFromName('xl/_rels/workbook.xml.rels')) !== false && ($rels = simplexml_load_string($strip($rx)))) {
        foreach ($rels->Relationship as $rel) {
            if (str_ends_with((string)($rel['Type'] ?? ''), '/worksheet')) {
                $tg = (string)($rel['Target'] ?? '');
                $sheet = str_starts_with($tg, '/xl/') ? ltrim($tg, '/') : 'xl/' . ltrim($tg, '/');
                break;
            }
        }
    }
    $ws = $zip->getFromName($sheet);
    $zip->close();
    if ($ws === false || !($x = simplexml_load_string($strip($ws)))) throw new RuntimeException('Błąd odczytu arkusza XLSX.');
    $grid = [];
    foreach (($x->sheetData->row ?? []) as $row) {
        $cells = []; $max = -1;
        foreach ($row->c as $c) {
            $col = preg_replace('/[0-9]/', '', (string)($c['r'] ?? ''));
            if ($col === '') continue;
            $i = msEduBulkColIdx($col); $max = max($max, $i); $cells[$i] = $c;
        }
        if ($max < 0) continue;
        $arr = array_fill(0, $max + 1, '');
        foreach ($cells as $i => $c) {
            $t = (string)($c['t'] ?? ''); $v = (string)($c->v ?? '');
            $arr[$i] = $t === 's' ? ($shared[(int)$v] ?? '') : ($t === 'inlineStr' ? (string)($c->is->t ?? '') : $v);
            $arr[$i] = trim($arr[$i]);
        }
        if (array_filter($arr, fn($v) => $v !== '')) $grid[] = $arr;
    }
    return msEduBulkGridPad($grid);
}

/** Zgadywanie pola po nagłówku kolumny. */
function msEduBulkAutoMap(string $header): string {
    $h = msEduBulkAscii($header);
    if ($h === '') return '';
    if (str_contains($h, 'mail')) return 'email';
    if (str_contains($h, 'imie') && str_contains($h, 'nazw')) return 'imie_nazwisko';
    if (str_contains($h, 'imie')) return 'imie';
    if (str_contains($h, 'nazw')) return 'nazwisko';
    if (str_contains($h, 'rola') || $h === 'typ' || str_contains($h, 'status')) return 'rola';
    if (str_contains($h, 'klas') || str_contains($h, 'oddz') || str_contains($h, 'grup') || str_contains($h, 'dzial')) return 'klasa';
    return '';
}

/** Mapowanie bez nagłówka — po zawartości pierwszego wiersza danych. */
function msEduBulkGuessMapByContent(array $row): array {
    $map = []; $names = 0;
    foreach ($row as $i => $v) {
        if (str_contains($v, '@')) $map[$i] = 'email';
        elseif (msEduBulkRole($v) !== null && $v !== '') $map[$i] = 'rola';
        else { $map[$i] = ['imie', 'nazwisko', 'klasa'][$names] ?? ''; $names++; }
    }
    return $map;
}

function msEduBulkLooksLikeHeader(array $row): bool {
    foreach ($row as $v) if (str_contains((string)$v, '@')) return false;
    $hits = 0; foreach ($row as $v) if (msEduBulkAutoMap((string)$v) !== '') $hits++;
    return $hits >= 2;
}

/**
 * Siatka + mapowanie → wiersze osób. E-mail alternatywny jest WYMAGANY i musi być poprawny
 * oraz spoza domeny kont M365 ($forbiddenDomain).
 * @return array{rows:array,errors:string[]}
 */
function msEduBulkRowsFromGrid(array $grid, array $map, bool $header, string $defRole, string $defKlasa, string $forbiddenDomain = ''): array {
    $rows = []; $errors = []; $seen = [];
    $idx = array_flip(array_filter($map, fn($f) => $f !== ''));
    foreach ($grid as $n => $line) {
        if ($header && $n === 0) continue;
        $get = fn($f) => isset($idx[$f]) ? trim((string)($line[$idx[$f]] ?? '')) : '';
        $imie = $get('imie'); $nazw = $get('nazwisko');
        if (($imie === '' || $nazw === '') && $get('imie_nazwisko') !== '') {
            $p = preg_split('/\s+/u', $get('imie_nazwisko'), 2);
            $imie = $imie ?: $p[0]; $nazw = $nazw ?: ($p[1] ?? '');
        }
        if (!array_filter($line, fn($v) => trim((string)$v) !== '')) continue;
        $no = $n + 1;
        if ($imie === '' || $nazw === '') { $errors[] = "Wiersz $no: brak imienia lub nazwiska."; continue; }
        $email = strtolower($get('email'));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) { $errors[] = "Wiersz $no ($imie $nazw): wymagany poprawny e-mail alternatywny."; continue; }
        if ($forbiddenDomain !== '' && str_ends_with($email, '@' . strtolower($forbiddenDomain))) {
            $errors[] = "Wiersz $no ($imie $nazw): e-mail musi być alternatywny — nie z domeny @$forbiddenDomain."; continue;
        }
        $roleRaw = $get('rola');
        $role = $roleRaw !== '' ? msEduBulkRole($roleRaw) : $defRole;
        if (!$role) { $errors[] = "Wiersz $no ($imie $nazw): nieznana rola „$roleRaw”."; continue; }
        $klasa = $get('klasa') !== '' ? $get('klasa') : ($defKlasa !== '' ? $defKlasa : ($role === 'nauczyciel' ? 'Kadra Pedagogiczna' : ''));
        $key = msEduBulkAscii($imie . $nazw) . '|' . $email;
        if (isset($seen[$key])) { $errors[] = "Wiersz $no: duplikat ($imie $nazw, $email)."; continue; }
        $seen[$key] = 1;
        $rows[] = ['imie' => $imie, 'nazwisko' => $nazw, 'rola' => $role, 'klasa' => $klasa, 'email' => $email];
    }
    return ['rows' => $rows, 'errors' => $errors];
}
