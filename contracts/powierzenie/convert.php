<?php
/**
 * contracts/powierzenie/convert.php
 * Konwersja istniejącej umowy (typ „inne" lub „usługi") na umowę powierzenia
 * zadania publicznego. Tworzy NOWY rekord w umowy_powierzenie, mapuje wspólne
 * pola i nadaje nowy numer. Oryginał pozostaje nietknięty.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/powierzenie_schema.php';
require_once dirname(dirname(__DIR__)) . '/includes/approval.php';

require_role('admin', 'editor');
require_module_enabled('contract_powierzenie', 'Ten typ umowy');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . APP_URL . '/contracts/powierzenie/list.php'); exit;
}
csrf_check();

$src_type = $_POST['source_type'] ?? '';
$src_id   = (int)($_POST['source_id'] ?? 0);

// Tylko z umów „inne" i „usługi" — zgodnie z konfiguracją modułu.
$ALLOWED_SOURCES = ['inne', 'uslugi'];
if (!in_array($src_type, $ALLOWED_SOURCES, true) || $src_id <= 0) {
    flash_set('error', 'Nieprawidłowy typ umowy źródłowej do konwersji.');
    header('Location: ' . APP_URL . '/contracts/powierzenie/list.php'); exit;
}

$src_table = table_for_type($src_type);
$src = db_one("SELECT * FROM {$src_table} WHERE id = ?", [$src_id]);
if (!$src) {
    flash_set('error', 'Nie znaleziono umowy źródłowej.');
    header('Location: ' . APP_URL . '/contracts/' . $src_type . '/list.php'); exit;
}

// Status: zachowaj jeśli sensowny, w innym wypadku „projekt".
$keep_statuses = ['projekt', 'podpisana', 'w realizacji', 'do rozliczenia', 'zakończona', 'rozwiązana', 'anulowana'];
$status = in_array($src['status'] ?? '', $keep_statuses, true) ? $src['status'] : 'projekt';

// Wspólne (kopiowane 1:1 jeśli istnieją w źródle).
$common = [
    'data_zawarcia'          => $src['data_zawarcia']          ?? null,
    'data_rozpoczecia'       => $src['data_rozpoczecia']       ?? null,
    'data_zakonczenia'       => $src['data_zakonczenia']       ?? null,
    'numer_projektu'         => $src['numer_projektu']         ?? null,
    'opiekun'                => $src['opiekun']                ?? null,
    'email'                  => $src['email']                 ?? null,
    'forma_podpisania'       => $src['forma_podpisania']       ?? null,
    'platforma_el'           => $src['platforma_el']           ?? null,
    'id_dokumentu_el'        => $src['id_dokumentu_el']        ?? null,
    'epodpis_dostawca'       => $src['epodpis_dostawca']       ?? null,
    'epodpis_nr_certyfikatu' => $src['epodpis_nr_certyfikatu'] ?? null,
    'epodpis_data_waznosci'  => $src['epodpis_data_waznosci']  ?? null,
    'plik_umowy'             => $src['plik_umowy']             ?? null,
    'plik_potwierdzenia'     => $src['plik_potwierdzenia']     ?? null,
    'zalaczniki'             => $src['zalaczniki']             ?? null,
    'nr_roboczy'             => $src['nr_roboczy']             ?? null,
    'nr_system'              => $src['nr_system']              ?? null,
    'podpisujacy_fundacja'   => $src['podpisujacy_fundacja']   ?? null,
    'podpisujacy_stanowisko' => $src['podpisujacy_stanowisko'] ?? null,
    'waluta'                 => $src['waluta'] ?: 'PLN',
];

// Mapowanie specyficzne dla typu źródłowego.
if ($src_type === 'inne') {
    $mapped = [
        'organ_zlecajacy'      => $src['strona_umowy']      ?? null,
        'organ_adres'          => $src['adres']             ?? null,
        'nazwa_zadania'        => $src['przedmiot_umowy']    ?? null,
        'zakres_rzeczowy'      => $src['przedmiot_umowy']    ?? null,
        'kwota_dotacji'        => $src['wartosc_umowy']      ?? null,
        'calkowity_koszt'      => $src['wartosc_umowy']      ?? null,
        'koszty_kwalifikowane' => $src['warunki_finansowe']  ?? null,
    ];
} else { // uslugi
    $mapped = [
        'organ_zlecajacy'      => $src['nazwa_wykonawcy']    ?? null,
        'organ_adres'          => $src['adres']             ?? null,
        'nazwa_zadania'        => $src['przedmiot_uslugi']    ?? null,
        'zakres_rzeczowy'      => $src['zakres_uslug']        ?? ($src['przedmiot_uslugi'] ?? null),
        'kwota_dotacji'        => $src['wartosc_brutto']      ?? null,
        'calkowity_koszt'      => $src['wartosc_brutto']      ?? null,
    ];
}

$src_label = CONTRACT_TYPES[$src_type] ?? $src_type;
$src_numer = $src['numer_umowy'] ?? ('#' . $src_id);
$note_line = "Skonwertowano z: {$src_label} {$src_numer}.";
$uwagi     = trim((string)($src['uwagi'] ?? ''));
$uwagi     = $uwagi !== '' ? ($note_line . "\n\n" . $uwagi) : $note_line;

$data = array_merge($common, $mapped, [
    'numer_umowy'    => next_contract_number('powierzenie'),
    'status'         => $status,
    'forma_zlecenia' => 'powierzenie',
    'uwagi'          => $uwagi,
    'created_by'     => current_user()['id'],
    'created_at'     => date('Y-m-d H:i:s'),
    'updated_at'     => date('Y-m-d H:i:s'),
]);

// Usuń puste, by nie wstawiać pustych stringów do kolumn liczbowych/dat.
foreach ($data as $k => $v) {
    if ($v === '' ) $data[$k] = null;
}

assign_nr_rejestru($data);
$new_id = db_insert('umowy_powierzenie', $data);

log_contract_action('powierzenie', $new_id, current_user()['id'], 'create',
    "Utworzono przez konwersję z: {$src_label} {$src_numer}");
log_contract_action($src_type, $src_id, current_user()['id'], 'convert',
    "Skonwertowano na umowę powierzenia {$data['numer_umowy']}");

try {
    require_once dirname(dirname(__DIR__)) . '/includes/crm.php';
    crm_migrate();
    CrmManager::autoCreateContractCase('powierzenie', $new_id, $data['numer_umowy'], $data, (int)(current_user()['id'] ?? 0));
} catch (\Throwable $e) {
    error_log('[crm_case_auto] ' . $e->getMessage());
}

flash_set('success', 'Umowę skonwertowano na umowę powierzenia. Uzupełnij pola specyficzne dla zadania publicznego.');
header('Location: ' . APP_URL . '/contracts/powierzenie/edit.php?id=' . $new_id);
exit;
