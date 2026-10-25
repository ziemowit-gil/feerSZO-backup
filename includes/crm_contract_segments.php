<?php
/**
 * includes/crm_contract_segments.php — adresaci wysyłki masowej wg umów: typ umowy × stan (aktywne / wygasłe).
 *
 * Kontakt CRM łączymy z umową po adresie e-mail (tak samo jak synchronizacja grup wolontariuszy).
 * Stan wg słownika statusów umów (grupy statusów):
 *   aktywne = robocze, obowiązujące i zablokowane (draft, active, blocked),
 *   wygasłe = zakończone i anulowane (closed, cancelled) — i BEZ obowiązującej umowy tego typu
 *             (kto ma nową umowę, nie dostaje wiadomości „dla wygasłych”).
 */
require_once __DIR__ . '/../modules/contract_status/lib/contractStatuses.php';

/** Rodzaje umów: klucz => [tabela, etykieta]. */
function crm_contract_segment_types(): array {
    return [
        'wolontariat' => ['umowy_wolontariat', 'Umowa wolontariacka'],
        'zlecenie'    => ['umowy_zlecenie',    'Umowa zlecenie'],
        'dzielo'      => ['umowy_dzielo',      'Umowa o dzieło'],
        'praca'       => ['umowy_praca',       'Umowa o pracę'],
        'uslugi'      => ['umowy_uslugi',      'Umowa o świadczenie usług'],
        'powierzenie' => ['umowy_powierzenie', 'Umowa powierzenia zadania publicznego'],
        'inne'        => ['umowy_inne',        'Inna umowa'],
    ];
}

function _crm_cs_in(array $codes): string {
    $codes = $codes ?: ['-'];
    return implode(',', array_map(fn($c) => "'" . str_replace("'", "''", (string)$c) . "'", $codes));
}

/** @return list<int> identyfikatory aktywnych kontaktów CRM dla typu umowy i stanu ('active'|'expired'). */
function crm_contract_segment_ids(string $type, string $state): array {
    $types = crm_contract_segment_types();
    if (!isset($types[$type]) || !in_array($state, ['active', 'expired'], true)) return [];
    $table  = $types[$type][0];
    $active = _crm_cs_in(contractStatusesInGroup('draft', 'active', 'blocked'));
    $closed = _crm_cs_in(contractStatusesInGroup('closed', 'cancelled'));
    $join   = "JOIN crm_contacts cc ON LOWER(TRIM(cc.email)) = LOWER(TRIM(u.email)) AND cc.crm_active=1";
    try {
        if ($state === 'active') {
            $sql = "SELECT DISTINCT cc.id FROM {$table} u {$join}
                    WHERE COALESCE(TRIM(u.email),'')<>'' AND u.status IN ({$active})";
        } else {
            $sql = "SELECT DISTINCT cc.id FROM {$table} u {$join}
                    WHERE COALESCE(TRIM(u.email),'')<>'' AND u.status IN ({$closed})
                      AND NOT EXISTS (SELECT 1 FROM {$table} a WHERE LOWER(TRIM(a.email))=LOWER(TRIM(u.email)) AND a.status IN ({$active}))";
        }
        return array_map('intval', array_column(db_all($sql), 'id'));
    } catch (\Throwable $e) {
        return [];   // brak tabeli/kolumny w tej instalacji
    }
}

/** Klucze zestawów "typ:stan" z żądania → lista identyfikatorów kontaktów (bez powtórzeń). */
function crm_contract_segment_resolve(array $sets): array {
    $ids = [];
    foreach ($sets as $s) {
        [$t, $st] = array_pad(explode(':', (string)$s, 2), 2, '');
        foreach (crm_contract_segment_ids($t, $st) as $id) $ids[$id] = true;
    }
    return array_map('intval', array_keys($ids));
}

/** Liczby kontaktów do tabeli wyboru: [typ => ['label'=>…, 'active'=>n, 'expired'=>n]]. */
function crm_contract_segment_counts(): array {
    $out = [];
    foreach (crm_contract_segment_types() as $k => [$tbl, $label]) {
        $out[$k] = ['label' => $label,
                    'active'  => count(crm_contract_segment_ids($k, 'active')),
                    'expired' => count(crm_contract_segment_ids($k, 'expired'))];
    }
    return $out;
}
