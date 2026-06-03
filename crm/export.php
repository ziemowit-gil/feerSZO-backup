<?php
/**
 * crm/export.php — Eksport kontaktów CRM do CSV.
 * GET params: te same co crm/index.php (q, status, type, tag, group, wojewodztwo)
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/crm.php';

require_login();
require_module_enabled('crm_enabled', 'CRM');
if (!can_write('crm') && !is_admin()) {
    http_response_code(403); die('Brak uprawnień.');
}
crm_migrate();

$filters = [
    'q'           => trim($_GET['q']           ?? ''),
    'status'      => trim($_GET['status']      ?? ''),
    'type'        => trim($_GET['type']        ?? ''),
    'tag'         => trim($_GET['tag']         ?? ''),
    'group'       => (int)($_GET['group']      ?? 0) ?: '',
    'wojewodztwo' => trim($_GET['wojewodztwo'] ?? ''),
];

// Pobierz kontakty (bez paginacji — wszystkie)
$result = CrmManager::getContacts($filters, 1, 99999);
$rows   = $result['rows'];
$format = in_array($_GET['format'] ?? 'csv', ['csv','xlsx']) ? $_GET['format'] : 'csv';

$headers = [
    'ID', 'Typ', 'Imię i nazwisko', 'E-mail', 'Telefon', 'Organizacja',
    'Stanowisko', 'Status', 'Adres', 'NIP', 'KRS', 'REGON',
    'Branża', 'Strona WWW', 'Województwo', 'Powiat', 'Gmina',
    'Tagi', 'Notatka', 'Źródło', 'Ostatni kontakt', 'Dodano',
];

$build_row = fn(array $r) => [
    $r['id'],
    $r['type'] === 'organizacja' ? 'Organizacja' : 'Osoba',
    $r['imie_nazwisko'],
    $r['email'] ?? '',
    $r['telefon'] ?? '',
    $r['organizacja'] ?? '',
    $r['stanowisko'] ?? '',
    crm_statuses()[$r['status']]['label'] ?? $r['status'],
    $r['adres'] ?? '',
    $r['nip'] ?? '',
    $r['krs'] ?? '',
    $r['regon'] ?? '',
    $r['branza'] ?? '',
    $r['strona_www'] ?? '',
    $r['wojewodztwo'] ?? '',
    $r['powiat'] ?? '',
    $r['gmina'] ?? '',
    $r['tags_csv'] ?? '',
    $r['notatka'] ?? '',
    $r['source'] ?? 'manual',
    $r['last_comm_at'] ? substr($r['last_comm_at'], 0, 16) : '',
    substr($r['created_at'], 0, 10),
];

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
