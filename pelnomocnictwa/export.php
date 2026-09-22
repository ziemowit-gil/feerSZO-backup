<?php
/**
 * Eksport rejestru pełnomocnictw do CSV (UTF-8 BOM, separator ';' — pod Excel PL).
 * Respektuje filtry listy: ?q=&status=&rok=.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/pelnomocnictwa.php';

require_login();
require_module_enabled('pelnomocnictwa_enabled', 'Rejestr pełnomocnictw');
if (!can_edit()) { flash_set('error', 'Brak uprawnień do rejestru pełnomocnictw.'); header('Location:'.APP_URL.'/index.php'); exit; }

$rows = pelnomocnictwa_all([
    'q'      => trim($_GET['q'] ?? ''),
    'status' => trim($_GET['status'] ?? ''),
    'rok'    => !empty($_GET['rok']) ? (int)$_GET['rok'] : null,
]);

$filename = 'pelnomocnictwa_' . date('Y-m-d') . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // BOM dla Excela

$cols = ['Numer','Rodzaj','Mocodawca','Pełnomocnik','PESEL','E-mail','Zakres',
         'Forma','Data udzielenia','Ważne do','Data odwołania','Status','Podpisany skan','Koszulka EZD','Utworzył'];
fputcsv($out, $cols, ';');

foreach ($rows as $r) {
    $zakres = ($r['rodzaj'] ?? 'ogolne') === 'korespondencja'
        ? pelnomocnictwo_kor_opis($r)
        : implode(' | ', pelnomocnictwo_zakres_items($r));
    [$statusLabel] = pelnomocnictwo_status_label(pelnomocnictwo_status($r));
    fputcsv($out, [
        $r['numer'],
        pelnomocnictwo_rodzaj_label($r['rodzaj'] ?? 'ogolne'),
        $r['mocodawca'],
        $r['pelnomocnik'],
        $r['pelnomocnik_pesel'],
        pelnomocnictwo_grantee_email($r),
        $zakres,
        $r['forma'],
        $r['data_udzielenia'] ? date_pl($r['data_udzielenia']) : '',
        $r['data_waznosci'] ? date_pl($r['data_waznosci']) : 'bezterminowo',
        $r['data_odwolania'] ? date_pl($r['data_odwolania']) : '',
        $statusLabel,
        !empty($r['dokument_plik']) ? 'tak' : 'nie',
        !empty($r['ezd_sprawa_id']) ? ('#' . (int)$r['ezd_sprawa_id']) : '',
        $r['creator_name'] ?? '',
    ], ';');
}
fclose($out);
