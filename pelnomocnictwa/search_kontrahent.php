<?php
/**
 * Wyszukiwanie osoby/strony spośród zawartych umów (do pola „Pełnomocnik") — JSON.
 * Przeszukuje bezpośrednio tabele umów (nie rejestr osób), bo starsze umowy nie
 * zawsze mają wpis w `persons` — zwraca tylko tych, którzy faktycznie mają umowę.
 *
 * GET ?q=...  min. 2 znaki.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

require_login();
header('Content-Type: application/json; charset=utf-8');

$q = trim($_GET['q'] ?? '');
if (mb_strlen($q) < 2) { echo json_encode([]); exit; }

$like = '%' . $q . '%';

// Kolumna „strony umowy" i identyfikatora (PESEL/NIP) różni się między tabelami typów.
// Bez `powierzenie` — tam "strona" to nazwa zadania, nie osoba/kontrahent.
$configs = [
    'wolontariat' => ['name_col' => 'imie_nazwisko',   'id_col' => 'pesel'],
    'zlecenie'    => ['name_col' => 'imie_nazwisko',   'id_col' => 'pesel'],
    'dzielo'      => ['name_col' => 'imie_nazwisko',   'id_col' => 'pesel'],
    'praca'       => ['name_col' => 'imie_nazwisko',   'id_col' => 'pesel'],
    'uslugi'      => ['name_col' => 'nazwa_wykonawcy', 'id_col' => 'nip_pesel'],
    'inne'        => ['name_col' => 'strona_umowy',    'id_col' => 'pesel_nip_krs'],
];

$out = [];
foreach ($configs as $slug => $cfg) {
    $table = table_for_type($slug);
    try {
        $rows = db_all(
            "SELECT id, {$cfg['name_col']} AS nazwa, {$cfg['id_col']} AS identyfikator, status, numer_umowy
             FROM {$table} WHERE {$cfg['name_col']} LIKE ? ORDER BY {$cfg['name_col']} LIMIT 15",
            [$like]
        );
    } catch (\Throwable $e) { continue; }
    foreach ($rows as $r) {
        $nazwa = trim((string)$r['nazwa']);
        if ($nazwa === '') continue;
        $pesel = trim((string)$r['identyfikator']);
        $out[] = [
            'contract_type'       => $slug,
            'contract_type_label' => CONTRACT_TYPES[$slug] ?? $slug,
            'contract_id'         => (int)$r['id'],
            'imie_nazwisko'       => $nazwa,
            'pesel'               => $pesel,
            'pesel_display'       => $pesel !== '' ? (mb_substr($pesel, 0, 6) . '…') : '',
            'numer_umowy'         => $r['numer_umowy'] ?? '',
            'status'              => $r['status'] ?? '',
        ];
    }
}

// Ta sama osoba może mieć kilka umów — zwiń po imię+PESEL, zbierz typy umów w listę.
$byKey = [];
foreach ($out as $r) {
    $key = mb_strtolower($r['imie_nazwisko']) . '|' . $r['pesel'];
    if (!isset($byKey[$key])) {
        $r['contract_types'] = [$r['contract_type_label']];
        $byKey[$key] = $r;
    } else {
        $byKey[$key]['contract_types'][] = $r['contract_type_label'];
    }
}
$out = array_values($byKey);
usort($out, fn($a, $b) => strcasecmp($a['imie_nazwisko'], $b['imie_nazwisko']));

echo json_encode(array_slice($out, 0, 20));
