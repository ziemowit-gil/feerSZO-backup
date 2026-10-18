<?php
/**
 * includes/powierzenie_pliki.php
 * Pliki umowy powierzenia i to, które są wymagane na danym etapie (statusie) — z uzasadnieniem.
 *
 * Jedno źródło reguł dla add.php / edit.php (walidacja + podpowiedź w formularzu),
 * view.php (ostrzeżenia, blokada szybkiej zmiany statusu) i list.php.
 *   plik_projekt      — 1. projekt umowy (nigdy nie wymagany)
 *   plik_jednostronna — 2. egzemplarz podpisany przez Fundację (jednostronnie)
 *   plik_umowy        — 3. egzemplarz podpisany przez obie strony (dawny „Plik umowy”)
 */

const POWIERZENIE_PLIKI = [
    'plik_projekt'      => '1. Projekt umowy',
    'plik_jednostronna' => '2. Umowa podpisana jednostronnie',
    'plik_umowy'        => '3. Umowa podpisana dwustronnie',
];

/** Statusy umowy powierzenia w kolejności obiegu. */
const POWIERZENIE_STATUSY = [
    'projekt'                 => 'Projekt',
    'jednostronnie'           => 'Podpisana jednostronnie',
    'w podpisie w instytucji' => 'W podpisie w instytucji',
    'podpisana'               => 'Podpisana',
    'w realizacji'            => 'W realizacji',
    'do rozliczenia'          => 'Do rozliczenia',
    'zakończona'              => 'Zakończona',
    'rozwiązana'              => 'Rozwiązana',
    'anulowana'               => 'Anulowana',
];

/**
 * Pliki wymagane przy danym statusie: [pole => uzasadnienie].
 * Projekt i anulowana — nic; po podpisie Fundacji — egzemplarz jednostronny;
 * przy statusie „Podpisana” — także egzemplarz dwustronny (tylko wtedy).
 */
function powierzenie_wymagane_pliki(string $status): array {
    $lbl  = POWIERZENIE_STATUSY[$status] ?? $status;
    $jedn = 'status „' . $lbl . '”: Fundacja podpisała umowę — dołącz egzemplarz z naszym podpisem.';
    $dwu  = 'status „' . $lbl . '”: umowa jest zawarta — dołącz egzemplarz podpisany przez obie strony.';
    switch ($status) {
        case 'jednostronnie':
        case 'w podpisie w instytucji':
            return ['plik_jednostronna' => $jedn];
        case 'podpisana':
            // Egzemplarz dwustronny wymagany wyłącznie przy statusie „Podpisana”
            return ['plik_jednostronna' => 'status „' . $lbl . '”: etap podpisu jednostronnego musi być udokumentowany.',
                    'plik_umowy'        => $dwu];
        case 'w realizacji':
        case 'zawieszona':
        case 'do rozliczenia':
        case 'zakończona':
        case 'rozwiązana':
            return ['plik_jednostronna' => 'status „' . $lbl . '”: etap podpisu jednostronnego musi być udokumentowany.'];
        default:
            return [];
    }
}

/** Komunikaty o brakujących plikach dla statusu; $row = wiersz umowy (z już zapisanymi/nowymi ścieżkami). */
function powierzenie_braki_plikow(string $status, array $row): array {
    $out = [];
    foreach (powierzenie_wymagane_pliki($status) as $f => $why) {
        if (empty($row[$f])) $out[] = POWIERZENIE_PLIKI[$f] . ' — wymagany, bo ' . $why;
    }
    return $out;
}

/** Mapa status → [pole => uzasadnienie] dla JS formularza (podpowiedź na żywo przy zmianie statusu). */
function powierzenie_wymagania_json(): string {
    $m = [];
    foreach (array_keys(POWIERZENIE_STATUSY) as $s) $m[$s] = powierzenie_wymagane_pliki($s);
    return json_encode($m, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
}

/**
 * Pole pliku w formularzu add/edit: etykieta, istniejący plik (edycja), input i uzasadnienie wymagania.
 * Atrybut required i tekst uzasadnienia ustawia skrypt powierzenie_pliki_js() po zmianie statusu.
 */
function powierzenie_pole_pliku(string $f, string $status, array $row = []): string {
    $why  = powierzenie_wymagane_pliki($status)[$f] ?? '';
    $has  = !empty($row[$f]);
    $cls  = 'tw-w-full tw-text-sm tw-text-slate-600 file:tw-mr-3 file:tw-rounded-lg file:tw-border-0 file:tw-bg-slate-100 file:tw-px-3 file:tw-py-2 file:tw-text-sm file:tw-font-medium hover:file:tw-bg-slate-200';
    $h  = '<div class="tw-mb-3" data-pow-file="' . h($f) . '" data-has="' . ($has ? '1' : '0') . '">';
    $h .= '<label for="' . h($f) . '" class="tw-block tw-text-sm tw-font-medium tw-text-slate-700 tw-mb-1">' . h(POWIERZENIE_PLIKI[$f])
        . ' <span class="tw-text-red-600" data-pow-star' . ($why ? '' : ' hidden') . '>*</span></label>';
    if ($has) $h .= '<div class="tw-mb-1 tw-text-sm">' . upload_link($row[$f]) . ' <span class="tw-text-xs tw-text-slate-500">— wybierz plik, aby zastąpić</span></div>';
    $h .= '<input id="' . h($f) . '" name="' . h($f) . '" type="file" accept=".pdf,.docx"' . ($why && !$has ? ' required' : '') . ' class="' . $cls . '">';
    $h .= '<p class="tw-text-xs tw-mt-1 tw-mb-0 ' . ($why ? 'tw-text-red-700' : 'tw-text-slate-500') . '" data-pow-why>'
        . h($why ? 'Wymagany — ' . $why : ($f === 'plik_projekt' ? 'Nieobowiązkowy.' : 'Na tym etapie nieobowiązkowy.')) . '</p>';
    return $h . '</div>';
}

/** Skrypt: przy zmianie pola #status przelicza, które pliki są wymagane, i pokazuje uzasadnienie. */
function powierzenie_pliki_js(): string {
    return '<script>(function(){var M=' . powierzenie_wymagania_json() . ';var s=document.getElementById("status");if(!s)return;'
        . 'function u(){var r=M[s.value]||{};document.querySelectorAll("[data-pow-file]").forEach(function(b){var f=b.dataset.powFile,w=r[f]||"",'
        . 'i=b.querySelector("input[type=file]"),p=b.querySelector("[data-pow-why]"),st=b.querySelector("[data-pow-star]");'
        . 'i.required=!!w&&b.dataset.has!=="1";st.hidden=!w;p.textContent=w?"Wymagany — "+w:(f==="plik_projekt"?"Nieobowiązkowy.":"Na tym etapie nieobowiązkowy.");'
        . 'p.className="tw-text-xs tw-mt-1 tw-mb-0 "+(w?"tw-text-red-700":"tw-text-slate-500");});}s.addEventListener("change",u);u();})();</script>';
}
