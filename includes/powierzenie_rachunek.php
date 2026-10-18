<?php
/**
 * includes/powierzenie_rachunek.php — rachunek bankowy dotacji w umowie powierzenia: wyodrębniony
 * (osobny rachunek dla zadania) albo ogólny rachunek Fundacji (z Ustawień organizacji → Rachunki).
 * Kolumny: rachunek_dotacji (numer), rachunek_dotacji_rodzaj ('wyodrebniony' | 'ogolny').
 */

const POWIERZENIE_RACHUNEK_RODZAJE = ['wyodrebniony' => 'Wyodrębniony rachunek dotacji', 'ogolny' => 'Ogólny rachunek Fundacji'];

/** Rachunki organizacji (settings.org_rachunki_bankowe): [[nrb, label], …]. */
function powierzenieRachunkiOrg(): array {
    $list = json_decode(org_setting('org_rachunki_bankowe') ?: '[]', true);
    $out = [];
    foreach (is_array($list) ? $list : [] as $a) {
        if (!is_array($a) || empty($a['nrb'])) continue;
        $out[] = [(string)$a['nrb'], trim(implode(' — ', array_filter([(string)($a['nazwa'] ?? ''), (string)($a['bank'] ?? '')]))) ?: (string)$a['nrb']];
    }
    return $out;
}

/** Etykieta do widoku/karty: „Wyodrębniony rachunek dotacji” albo „Ogólny rachunek Fundacji”. */
function powierzenieRachunekEtykieta(array $row): string {
    return POWIERZENIE_RACHUNEK_RODZAJE[$row['rachunek_dotacji_rodzaj'] ?? ''] ?? POWIERZENIE_RACHUNEK_RODZAJE['wyodrebniony'];
}

/** Pole formularza add/edit: wybór rodzaju + numer (dla ogólnego — lista rachunków Fundacji wpisuje numer). */
function powierzenieRachunekPole(array $row): string {
    $rodzaj = ($row['rachunek_dotacji_rodzaj'] ?? '') === 'ogolny' ? 'ogolny' : 'wyodrebniony';
    $nr     = (string)($row['rachunek_dotacji'] ?? '');
    $inp    = 'tw-w-full tw-rounded-lg tw-border tw-border-slate-300 tw-px-3 tw-py-2 tw-text-sm focus:tw-border-blue-500 focus:tw-ring-2 focus:tw-ring-blue-100 tw-outline-none';
    $h  = '<fieldset class="tw-border-0 tw-p-0 tw-m-0"><legend class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">Rachunek bankowy dotacji</legend>';
    $h .= '<div class="tw-flex tw-flex-wrap tw-gap-x-4 tw-mb-2 tw-text-sm">';
    foreach (POWIERZENIE_RACHUNEK_RODZAJE as $k => $l) {
        $h .= '<label class="tw-inline-flex tw-items-center tw-gap-1"><input type="radio" name="rachunek_dotacji_rodzaj" value="' . $k . '"'
            . ($rodzaj === $k ? ' checked' : '') . ' data-pow-rach> ' . h($l) . '</label>';
    }
    $h .= '</div>';
    $org = powierzenieRachunkiOrg();
    if ($org) {
        $h .= '<select class="' . $inp . ' tw-bg-white tw-mb-2" data-pow-rach-org aria-label="Rachunek Fundacji"' . ($rodzaj === 'ogolny' ? '' : ' hidden') . '>'
            . '<option value="">— wybierz rachunek Fundacji —</option>';
        foreach ($org as [$nrb, $lbl]) $h .= '<option value="' . h($nrb) . '"' . ($nrb === $nr ? ' selected' : '') . '>' . h($lbl . ' · ' . $nrb) . '</option>';
        $h .= '</select>';
    }
    $h .= '<input id="rachunek_dotacji" name="rachunek_dotacji" class="' . $inp . ' tw-font-mono" value="' . h($nr) . '" aria-label="Numer rachunku dotacji" placeholder="numer rachunku (NRB)">';
    $h .= '</fieldset>';
    $h .= '<script>(function(){var r=document.querySelectorAll("[data-pow-rach]"),s=document.querySelector("[data-pow-rach-org]"),i=document.getElementById("rachunek_dotacji");'
        . 'function u(){var o=document.querySelector("[data-pow-rach]:checked");if(s)s.hidden=!(o&&o.value==="ogolny");}'
        . 'r.forEach(function(x){x.addEventListener("change",u);});if(s)s.addEventListener("change",function(){if(s.value)i.value=s.value;});u();})();</script>';
    return $h;
}
