<?php
/**
 * Skrypt automatycznej synchronizacji kont M365 (Oparty na umowach + Licencje).
 * Uruchamiany z poziomu CLI (Cron) co 10 minut.
 */

if (php_sapi_name() !== 'cli') {
    die("Dostęp dozwolony tylko z poziomu konsoli (CLI).\n");
}

$base_dir = dirname(__DIR__);

require_once $base_dir . '/config.php';
require_once $base_dir . '/includes/db.php';
require_once $base_dir . '/includes/functions.php';
require_once $base_dir . '/includes/m365.php';

echo "[" . date('Y-m-d H:i:s') . "] Rozpoczęcie synchronizacji na podstawie aktywnych umów...\n";

try {
    $graph = new M365Graph();
    if (!$graph->is_configured()) {
        throw new RuntimeException('M365 nie jest skonfigurowane w systemie.');
    }

    $types = [
        'zlecenie'    => ['data_rozpoczecia','data_zakonczenia'],
        'dzielo'      => ['data_zawarcia','termin_oddania'],
        'wolontariat' => ['data_rozpoczecia','data_zakonczenia'],
    ];

    $count_changed = 0;
    $count_errors  = 0;

    foreach ($types as $type => $date_cols) {
        $table = table_for_type($type);
        
        // Pobieramy umowy powiązane z kontem M365 posiadające ID użytkownika Microsoft
        $rows = db_all("SELECT * FROM {$table} WHERE m365_konto = 1 AND m365_user_id != '' AND m365_user_id IS NOT NULL");

        foreach ($rows as $row) {
            $m365_user_id  = $row['m365_user_id'];
            $m365_login    = $row['m365_login'];
            $numer_umowy   = $row['numer_umowy'];
            
            // 1. Weryfikacja logiczna na podstawie dat i statusu umowy
            $should_be_active = m365_should_be_active($row);
            $is_active_in_db  = (bool)$row['m365_konto_aktywne'];

            try {
                // 2. Pobieramy stan rzeczywisty oraz licencje z Microsoft Graph dla tego konta
                $ms_account_info   = $graph->get_user_by_id($m365_user_id); 
                $current_ms_status = isset($ms_account_info['accountEnabled']) ? (bool)$ms_account_info['accountEnabled'] : null;
                
                $licenses    = $ms_account_info['assignedLicenses'] ?? [];
                $has_license = !empty($licenses);

                // Jeśli umowa każe włączyć, ale konto nie ma licencji — wymuszamy wyłączenie
                if (!$has_license) {
                    $should_be_active = false;
                }

                // 3. Jeśli stan rzeczywisty w chmurze różni się od wyliczonego — aktualizujemy
                if ($current_ms_status !== null && $should_be_active !== $current_ms_status) {
                    $graph->set_enabled($m365_user_id, $should_be_active);
                    
                    // Aktualizujemy flagę aktywności w bieżącej umowie
                    db_update($table, ['m365_konto_aktywne' => $should_be_active ? 1 : 0], $row['id']);
                    
                    $reason = !$has_license ? 'BRAK LICENCJI' : 'Zmiana statusu/dat umowy';
                    $action_text = $should_be_active ? 'WŁĄCZONO' : 'WYŁĄCZONO';
                    
                    echo " -> [OK] Umowa: {$numer_umowy} | Login: {$m365_login} | Akcja: {$action_text} ({$reason})\n";
                    $count_changed++;
                } 
                // Zabezpieczenie: jeśli baza danych nie zgadza się z chmurą, wyrównujemy flagę w DB
                elseif ($is_active_in_db !== $current_ms_status && $current_ms_status !== null) {
                    db_update($table, ['m365_konto_aktywne' => $current_ms_status ? 1 : 0], $row['id']);
                }

            } catch (\Exception $e) {
                echo " -> [BLAD] Umowa: {$numer_umowy} | Login: {$m365_login} | Błąd: " . $e->getMessage() . "\n";
                $count_errors++;
            }
        }
    }

    echo "[" . date('Y-m-d H:i:s') . "] Synchronizacja zakończona. Zmieniono: {$count_changed}, Błędów: {$count_errors}.\n";

    if (function_exists('m365_save_setting')) {
        m365_save_setting('m365_last_cron_sync', date('Y-m-d H:i:s'));
    }

} catch (\Exception $e) {
    echo "[" . date('Y-m-d H:i:s') . "] KRYTYCZNY BŁĄD SYSTEMU: " . $e->getMessage() . "\n";
    exit(1);
}