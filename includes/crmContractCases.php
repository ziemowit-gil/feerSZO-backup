<?php
/**
 * includes/crmContractCases.php — autokończenie spraw CRM przypisanych do umowy, gdy umowa się kończy.
 *
 * Sprawa założona automatycznie przy zapisie umowy (CrmManager::autoCreateContractCase: crm_cases.contract_type + contract_id)
 * zostawała otwarta po zakończeniu, rozwiązaniu albo anulowaniu umowy i wisiała na liście „Do zamknięcia”. Teraz sprawa
 * zamyka się sama: status „Zamknięta”, powód „Umowa zakończona”, wpis w dzienniku statusów sprawy.
 *
 * „Umowa się kończy” = status umowy w grupie closed lub cancelled (słownik statusów umów: zakończona, rozwiązana, anulowana…).
 * Status „do rozliczenia” należy jeszcze do grupy aktywnej, więc sprawa zostaje otwarta do ostatecznego zamknięcia umowy.
 * Wyłączenie: ustawienie org `crm_case_autoclose_contract` = 0. Sprawy zamknięte ręcznie nie są ruszane.
 * Uruchamiane: cron/crm_cases_contract_close.php (co godzinę), po automatycznym kończeniu umów oraz po zmianie statusu
 * umowy z listy/kanbanu (contract_status_after_change).
 */
const CRM_CASE_CLOSE_REASON_CONTRACT = 'umowa_zakonczona';

function crm_cases_contract_autoclose_enabled(): bool {
    return !function_exists('org_setting') || org_setting('crm_case_autoclose_contract') !== '0';
}

/**
 * Zamyka otwarte sprawy umów zakończonych. $type/$contract_id zawężają przebieg do jednej umowy.
 * @return array{closed:int,checked:int}
 */
function crm_cases_close_for_ended_contracts(?string $type = null, ?int $contract_id = null): array {
    $out = ['closed' => 0, 'checked' => 0];
    if (!crm_cases_contract_autoclose_enabled()) return $out;
    require_once __DIR__ . '/crm.php';
    require_once __DIR__ . '/crm_case_extras.php';
    require_once dirname(__DIR__) . '/modules/contract_status/lib/contractStatuses.php';

    $tables = ['wolontariat' => 'umowy_wolontariat', 'zlecenie' => 'umowy_zlecenie', 'dzielo' => 'umowy_dzielo', 'praca' => 'umowy_praca',
               'uslugi' => 'umowy_uslugi', 'powierzenie' => 'umowy_powierzenie', 'inne' => 'umowy_inne'];
    foreach ($tables as $t => $tbl) {
        if ($type !== null && $type !== $t) continue;
        try {
            $sql = "SELECT c.id, t.status AS cstatus FROM crm_cases c JOIN {$tbl} t ON t.id = c.contract_id
                     WHERE c.contract_type = ? AND " . crm_case_active_sql('c.status');
            $par = [$t];
            if ($contract_id) { $sql .= " AND c.contract_id = ?"; $par[] = $contract_id; }
            foreach (db_all($sql, $par) as $r) {
                $out['checked']++;
                if (!in_array(contractStatusGroup((string)$r['cstatus']), ['closed', 'cancelled'], true)) continue;
                $res = crm_case_set_status((int)$r['id'], 'closed', CRM_CASE_CLOSE_REASON_CONTRACT, 0);
                if (!empty($res['ok']) && empty($res['unchanged'])) $out['closed']++;
            }
        } catch (\Throwable $e) {
            error_log('[crm_cases_close_for_ended_contracts] ' . $t . ': ' . $e->getMessage());
        }
    }
    return $out;
}
