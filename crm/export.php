<?php
/**
 * crm/export.php — Eksport kontaktów CRM do CSV / XLSX.
 * GET params: te same filtry co crm/index.php (q, q_all, status, type, tag, group,
 *             wojewodztwo, powiat, gmina, source, branza, created_*, last_*, stale_days,
 *             has_email, has_phone) + wybór kolumn cols[] i format.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/crm.php';

require_login();
require_module_enabled('crm_enabled', 'CRM');
if (!can_read('crm_eksport') && !is_admin()) {
    http_response_code(403); die('Brak uprawnień do eksportu kontaktów.');
}
crm_migrate();

$filters = [
    'q'            => trim($_GET['q']            ?? ''),
    'q_all'        => trim($_GET['q_all']        ?? ''),
    'status'       => trim($_GET['status']       ?? ''),
    'type'         => trim($_GET['type']         ?? ''),
    'tag'          => trim($_GET['tag']          ?? ''),
    'group'        => (int)($_GET['group']       ?? 0) ?: '',
    'wojewodztwo'  => trim($_GET['wojewodztwo']  ?? ''),
    'powiat'       => trim($_GET['powiat']       ?? ''),
    'gmina'        => trim($_GET['gmina']        ?? ''),
    'source'       => trim($_GET['source']       ?? ''),
    'branza'       => trim($_GET['branza']       ?? ''),
    'action_id'    => (int)($_GET['action_id']   ?? 0) ?: '',
    'created_from' => trim($_GET['created_from'] ?? ''),
    'created_to'   => trim($_GET['created_to']   ?? ''),
    'last_from'    => trim($_GET['last_from']    ?? ''),
    'last_to'      => trim($_GET['last_to']      ?? ''),
    'stale_days'   => (int)($_GET['stale_days']  ?? 0) ?: '',
    'has_email'    => !empty($_GET['has_email']) ? '1' : '',
    'has_phone'    => !empty($_GET['has_phone']) ? '1' : '',
];

// Eksport tylko zaznaczonych kontaktów (po ID z paska bulk)
$only_ids = array_values(array_filter(array_map('intval', (array)($_GET['ids'] ?? []))));

// ── Definicja dostępnych kolumn: kod => [etykieta, funkcja wartości] ──────────
$COLUMNS = [
    'id'            => ['ID',                fn($r) => $r['id']],
    'type'          => ['Typ',               fn($r) => CRM_CONTACT_TYPES[$r['type']]['label'] ?? ucfirst($r['type'])],
    'imie_nazwisko' => ['Imię i nazwisko',   fn($r) => $r['imie_nazwisko']],
    'email'         => ['E-mail',            fn($r) => $r['email'] ?? ''],
    'telefon'       => ['Telefon',           fn($r) => $r['telefon'] ?? ''],
    'organizacja'   => ['Organizacja',       fn($r) => $r['organizacja'] ?? ''],
    'stanowisko'    => ['Stanowisko',        fn($r) => $r['stanowisko'] ?? ''],
    'status'        => ['Status',            fn($r) => crm_statuses()[$r['status']]['label'] ?? $r['status']],
    'adres'         => ['Adres',             fn($r) => $r['adres'] ?? ''],
    'nip'           => ['NIP',               fn($r) => $r['nip'] ?? ''],
    'krs'           => ['KRS',               fn($r) => $r['krs'] ?? ''],
    'regon'         => ['REGON',             fn($r) => $r['regon'] ?? ''],
    'pesel'         => ['PESEL',             fn($r) => $r['pesel'] ?? ''],
    'branza'        => ['Branża',            fn($r) => $r['branza'] ?? ''],
    'strona_www'    => ['Strona WWW',        fn($r) => $r['strona_www'] ?? ''],
    'wojewodztwo'   => ['Województwo',       fn($r) => $r['wojewodztwo'] ?? ''],
    'powiat'        => ['Powiat',            fn($r) => $r['powiat'] ?? ''],
    'gmina'         => ['Gmina',             fn($r) => $r['gmina'] ?? ''],
    'tags'          => ['Tagi',              fn($r) => $r['tags_csv'] ?? ''],
    'notatka'       => ['Notatka',           fn($r) => $r['notatka'] ?? ''],
    'source'        => ['Źródło',            fn($r) => $r['source'] ?? 'manual'],
    'last_comm'     => ['Ostatni kontakt',   fn($r) => $r['last_comm_at'] ? substr($r['last_comm_at'], 0, 16) : ''],
    'created'       => ['Dodano',            fn($r) => substr((string)$r['created_at'], 0, 10)],
];

// Wybór kolumn — z parametru cols[]; domyślnie wszystkie (kolejność jak w mapie)
$requested = (array)($_GET['cols'] ?? []);
$requested = array_values(array_filter($requested, fn($c) => isset($COLUMNS[$c])));
$selected  = $requested ?: array_keys($COLUMNS);

$headers   = array_map(fn($c) => $COLUMNS[$c][0], $selected);
$build_row = function (array $r) use ($COLUMNS, $selected) {
    $out = [];
    foreach ($selected as $c) {
        $out[] = ($COLUMNS[$c][1])($r);
    }
    return $out;
};

// Pobierz kontakty (bez paginacji — wszystkie pasujące do filtra)
$result = CrmManager::getContacts($filters, 1, 99999);
$rows   = $result['rows'];
if ($only_ids) {
    $idset = array_flip($only_ids);
    $rows  = array_values(array_filter($rows, fn($r) => isset($idset[(int)$r['id']])));
}

$format = in_array($_GET['format'] ?? 'csv', ['csv', 'xlsx'], true) ? $_GET['format'] : 'csv';

if ($format === 'xlsx') {
    require_once dirname(__DIR__) . '/includes/xlsx.php';
    $x = new XlsxWriter();
    $x->addSheet('Kontakty CRM');
    $x->writeRow($headers, ['header']);
    foreach ($rows as $r) {
        $x->writeRow($build_row($r));
    }
    $x->output('crm-kontakty-' . date('Y-m-d') . '.xlsx');
    exit;
}

// CSV (default)
$filename = 'crm-kontakty-' . date('Y-m-d') . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // BOM UTF-8
fputcsv($out, $headers, ';');
foreach ($rows as $r) {
    fputcsv($out, $build_row($r), ';');
}
fclose($out);
exit;
