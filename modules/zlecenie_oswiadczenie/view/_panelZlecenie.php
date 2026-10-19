<?php
/**
 * modules/zlecenie_oswiadczenie/view/_panelZlecenie.php — dane i zakładki „umowa zlecenie” oraz „rachunki” w panelu
 * współpracownika (panel/includes/pv_home.php). Zakładki są widoczne ZAWSZE, gdy osoba ma umowę zlecenia — także gdy
 * w panelu wybrany jest inny kontekst (np. wolontariat). Wymaga w scope: $contracts, $_tabs (dopisuje do niej).
 * Wynik: $_pvz = [[umowa, wiersz umowy, pakiet oświadczeń, dokumenty, rachunki, potrzebny rachunek za miesiąc], …].
 */
$_pvz = [];
try {
    require_once dirname(__DIR__) . '/logic/OswService.php';
    require_once dirname(__DIR__, 3) . '/includes/zlecenie_rachunki.php';
    foreach ($contracts ?? [] as $_c) {
        if (($_c['contract_type'] ?? '') !== 'zlecenie' || !empty($_c['_is_guardian'])) continue;
        $_row = db_one("SELECT * FROM umowy_zlecenie WHERE id = ?", [(int)$_c['id']]);
        if (!$_row) continue;
        $_o = OswService::forContract((int)$_c['id']);
        $_pvz[] = ['umowa' => $_c, 'row' => $_row, 'osw' => $_o, 'docs' => $_o ? OswService::docs((int)$_o['id']) : [],
                   'rach' => get_rachunki('zlecenie', (int)$_c['id']), 'rachTodo' => rachunek_banner_needed($_row)];
    }
} catch (\Throwable $e) { error_log('[panel zlecenie] ' . $e->getMessage()); $_pvz = []; }
if ($_pvz) {
    $_pvzOsw = 0; $_pvzRach = 0;
    foreach ($_pvz as $_z) {
        $_need = !$_z['osw'] || $_z['osw']['status'] === 'roboczy'
              || array_filter($_z['docs'], fn($d) => in_array($d['status'], ['wypelnione', 'wydrukowane', 'odrzucone'], true));
        $_ended = !empty($_z['row']['data_zakonczenia']) && $_z['row']['data_zakonczenia'] < date('Y-m-d');
        if ($_need && !$_ended) $_pvzOsw++;
        foreach ($_z['rach'] as $_r) if (!rachunek_is_signed($_r) && !empty($_r['notify_token'])) $_pvzRach++;
        if ($_z['rachTodo']) $_pvzRach++;
    }
    $_tabs['zlecenie'] = ['umowa zlecenie', $_pvzOsw];
    $_tabs['rachunki'] = ['rachunki', $_pvzRach];
}
