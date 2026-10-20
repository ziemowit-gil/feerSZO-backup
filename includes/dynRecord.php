<?php
/**
 * crm/includes/dynRecord.php — elementy kart rekordów CRM w układzie Dynamics 365 (style: assets/css/crmDynamics.css).
 *
 *   dynEntity('Kontakt', 'Osoba')                        — etykieta rodzaju rekordu nad tytułem
 *   dynHFields([['Status', 'Klient', '#2E844A'], …])     — pola nagłówka (wartość jako tekst; HTML przez ['html' => …])
 *   dynStages(['draft' => 'Szkic', …], 'sent', ['done' => [...], 'off' => 'rejected'])  — pasek etapów tylko do odczytu
 *
 * Pasek poleceń to zwykły <div class="dyn-cmdbar" role="toolbar">…</div> z dotychczasowymi przyciskami strony
 * (.btn albo .dyn-cmd) — CSS nadaje im wygląd poleceń, logika przycisków się nie zmienia.
 */

function dynEntity(string $entity, string $detail = ''): string {
    return '<div class="dyn-entity">' . h($entity) . ($detail !== '' ? ' · ' . h($detail) : '') . '</div>';
}

/** @param list<array{0:string,1:string|array,2?:string}> $fields  [etykieta, wartość, kolor?]; wartość ['html' => '…'] wstawiana bez escapowania */
function dynHFields(array $fields): string {
    $out = '<dl class="dyn-hfields">';
    foreach ($fields as $f) {
        [$label, $val] = $f; $color = $f[2] ?? '';
        $v = is_array($val) ? (string)($val['html'] ?? '') : ((string)$val !== '' ? h((string)$val) : '<span class="text-muted">—</span>');
        $out .= '<div><dt>' . h($label) . '</dt><dd' . ($color !== '' ? ' style="color:' . h($color) . '"' : '') . '>' . $v . '</dd></div>';
    }
    return $out . '</dl>';
}

/**
 * Pasek etapów do odczytu. $stages klucz => etykieta (kolejność = proces); $current = bieżący klucz.
 * $opts['off'] = [klucz => etykieta] stanów spoza ścieżki (np. odrzucona) — pokazywany jako ostatni, szary.
 */
function dynStages(array $stages, string $current, array $opts = []): string {
    $keys = array_keys($stages);
    $off  = $opts['off'] ?? [];
    $pos  = array_search($current, $keys, true);
    $h = '<nav class="dyn-bpf" aria-label="' . h($opts['label'] ?? 'Etapy') . '">';
    foreach ($stages as $k => $l) {
        $i = array_search($k, $keys, true);
        $cur = $k === $current; $done = $pos !== false && $i < $pos;
        $h .= '<div class="dyn-stage' . ($cur ? ' current' : ($done ? ' done' : '')) . '"' . ($cur ? ' aria-current="step"' : '') . '><span><span class="dyn-dot" aria-hidden="true">'
            . ($done ? '✓' : $i + 1) . '</span>' . h($l) . '</span></div>';
    }
    if (isset($off[$current])) $h .= '<div class="dyn-stage current cancelled" aria-current="step"><span><span class="dyn-dot" aria-hidden="true">✕</span>' . h($off[$current]) . '</span></div>';
    return $h . '</nav>';
}
