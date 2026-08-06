<?php
/**
 * includes/oplacalnosc.php — Silnik oceny opłacalności działań (SZO FEER).
 *
 * Obsługuje dwa tryby kosztowe:
 *   online     — stała opłata platformy + czas_pracy × stawka online
 *   wyjazdowy  — baza + czas_dojazdu × stawka_km + bilety + czas_pracy × stawka_robocza
 *
 * Rezerwa kosztowa: koszt_bazowy × 1.10
 * Obciążenia: ZUS + US liczone od nadwyżki (przychód − koszt_całkowity)
 *
 * Jedyny publiczny punkt wejścia: oplacalnosc_oblicz(array $params): array
 */

/* ── Stałe ──────────────────────────────────────────────────────────────── */

const OPLACALNOSC_RESERVE          = 0.10;    // 10 % nadwyżki kosztowej
const OPLACALNOSC_ONLINE_RATE      = 60.00;   // zł/h — stawka online
const OPLACALNOSC_ONLINE_PLATFORM  = 20.00;   // zł — stała opłata za platformę
const OPLACALNOSC_TRAVEL_BASE      = 160.00;  // zł — bazowy koszt wyjazdu
const OPLACALNOSC_TRAVEL_TIME_RATE = 9.81;    // zł/h — wycena czasu dojazdu

// ROI >= tego progu → status OPŁACALNE; 0 ≤ ROI < progu → DO NEGOCJACJI; < 0 → STRATNE
const OPLACALNOSC_ROI_OK_THRESHOLD = 10.0;    // %

/**
 * Modele obciążeń publicznoprawnych (ZUS + US) — stawki szacunkowe 2025/2026.
 *
 * zus_rate: ułamek nadwyżki przeznaczony na składki ZUS po stronie fundacji.
 * it_rate : stawka PIT od podstawy (nadwyżka − ZUS).
 * kup_rate: kosztów uzysk. przychodu odliczanych przed IT (np. 20 % dla dzieła).
 */
const OPLACALNOSC_TAX_MODELS = [
    'zlecenie' => [
        'label'    => 'Umowa zlecenie',
        'zus_rate' => 0.2314,   // emerytalne + rentowe + wypadkowe (pracodawca ~23,14 %)
        'kup_rate' => 0.0,
        'it_rate'  => 0.12,     // PIT — I próg (do 120 000 zł)
        'note'     => 'ZUS 23,14 % + PIT 12 %',
    ],
    'dzielo' => [
        'label'    => 'Umowa o dzieło',
        'zus_rate' => 0.0,      // brak ZUS społecznego (poza tytułami zbiegającymi)
        'kup_rate' => 0.20,     // 20 % autorskich KUP → podstawa IT = 80 % nadwyżki
        'it_rate'  => 0.12,
        'note'     => 'PIT 12 %, KUP 20 % (bez ZUS)',
    ],
    'b2b' => [
        'label'    => 'B2B / działalność gospodarcza',
        'zus_rate' => 0.0,      // ZUS ryczałtowy — płaci wykonawca samodzielnie
        'kup_rate' => 0.0,
        'it_rate'  => 0.19,     // podatek liniowy 19 %
        'note'     => 'PIT liniowy 19 % (ZUS po stronie wykonawcy)',
    ],
];

/* ── Główna funkcja ─────────────────────────────────────────────────────── */

/**
 * Oblicza pełny raport opłacalności działania.
 *
 * Parametry wejściowe ($p):
 *   tryb          string   'online' | 'wyjazdowy'
 *   przychod      float    Oferowany przychód brutto (PLN)
 *   czas_pracy    float    Czas pracy (godziny)
 *   stawka        float    Stawka robocza zł/h — tylko tryb wyjazdowy
 *   czas_dojazdu  float    Czas dojazdu (godziny) — tylko wyjazdowy
 *   bilety        float    Koszty biletów/transportu (PLN) — tylko wyjazdowy
 *   model_zus     string   'zlecenie' | 'dzielo' | 'b2b' (domyślnie: zlecenie)
 *
 * @return array {
 *   tryb, przychod, koszt_bazowy, nadwyzka_kosztowa, koszt_calkowity,
 *   surplus, zus, podatek, obciazenia, zysk_netto,
 *   roi, status, model_zus_label, model_zus_note,
 *   breakdown — szczegółowe składowe kosztu bazowego (tylko wyjazdowy)
 * }
 */
function oplacalnosc_oblicz(array $p): array {
    $tryb       = ($p['tryb'] ?? 'online') === 'wyjazdowy' ? 'wyjazdowy' : 'online';
    $przychod   = max(0.0, (float)($p['przychod']   ?? 0));
    $czas_pracy = max(0.0, (float)($p['czas_pracy'] ?? 0));
    $model_key  = array_key_exists($p['model_zus'] ?? '', OPLACALNOSC_TAX_MODELS)
                  ? $p['model_zus']
                  : 'zlecenie';
    $tax = OPLACALNOSC_TAX_MODELS[$model_key];

    /* Koszt bazowy */
    $breakdown = [];
    if ($tryb === 'wyjazdowy') {
        $czas_dojazdu = max(0.0, (float)($p['czas_dojazdu'] ?? 0));
        $bilety       = max(0.0, (float)($p['bilety']       ?? 0));
        $stawka       = max(0.0, (float)($p['stawka']       ?? 0));

        $breakdown = [
            'baza'      => OPLACALNOSC_TRAVEL_BASE,
            'dojazd'    => round($czas_dojazdu * OPLACALNOSC_TRAVEL_TIME_RATE, 2),
            'bilety'    => $bilety,
            'praca'     => round($czas_pracy * $stawka, 2),
        ];
        $koszt_bazowy = array_sum($breakdown);
    } else {
        $breakdown = [
            'praca'     => round($czas_pracy * OPLACALNOSC_ONLINE_RATE, 2),
            'platforma' => OPLACALNOSC_ONLINE_PLATFORM,
        ];
        $koszt_bazowy = array_sum($breakdown);
    }

    $nadwyzka_kosztowa = round($koszt_bazowy * OPLACALNOSC_RESERVE, 2);
    $koszt_calkowity   = round($koszt_bazowy + $nadwyzka_kosztowa, 2);
    $koszt_bazowy      = round($koszt_bazowy, 2);

    /* Nadwyżka: przychód minus wszystkie koszty operacyjne */
    $surplus = round($przychod - $koszt_calkowity, 2);

    /* Obciążenia publicznoprawne — liczone tylko od dodatniej nadwyżki */
    $zus     = 0.0;
    $podatek = 0.0;
    if ($surplus > 0) {
        $zus     = round($surplus * $tax['zus_rate'], 2);
        $it_base = $surplus - $zus;                          // podstawa opodatkowania
        if ($tax['kup_rate'] > 0) {
            $it_base = round($it_base * (1 - $tax['kup_rate']), 2);
        }
        $podatek = round($it_base * $tax['it_rate'], 2);
    }
    $obciazenia = round($zus + $podatek, 2);
    $zysk_netto = round($surplus - $obciazenia, 2);

    /* ROI */
    $roi = 0.0;
    if ($koszt_calkowity > 0) {
        $roi = round(($zysk_netto / $koszt_calkowity) * 100, 2);
    }

    /* Status rentowności */
    if ($roi >= OPLACALNOSC_ROI_OK_THRESHOLD) {
        $status = 'OPŁACALNE';
    } elseif ($zysk_netto >= 0) {
        $status = 'DO NEGOCJACJI';
    } else {
        $status = 'STRATNE';
    }

    return [
        'tryb'              => $tryb,
        'przychod'          => $przychod,
        'koszt_bazowy'      => $koszt_bazowy,
        'nadwyzka_kosztowa' => $nadwyzka_kosztowa,
        'koszt_calkowity'   => $koszt_calkowity,
        'surplus'           => $surplus,
        'zus'               => $zus,
        'podatek'           => $podatek,
        'obciazenia'        => $obciazenia,
        'zysk_netto'        => $zysk_netto,
        'roi'               => $roi,
        'status'            => $status,
        'model_zus_label'   => $tax['label'],
        'model_zus_note'    => $tax['note'],
        'breakdown'         => $breakdown,
    ];
}

/**
 * Waliduje parametry wejściowe i zwraca tablicę błędów (pusta = brak błędów).
 */
function oplacalnosc_validate(array $p): array {
    $errs = [];
    if (($p['przychod'] ?? '') === '' || (float)$p['przychod'] < 0) {
        $errs[] = 'Przychód musi być liczbą nieujemną.';
    }
    if (($p['czas_pracy'] ?? '') === '' || (float)$p['czas_pracy'] <= 0) {
        $errs[] = 'Czas pracy musi być większy od zera.';
    }
    if (($p['tryb'] ?? '') === 'wyjazdowy') {
        if ((float)($p['stawka'] ?? 0) <= 0) {
            $errs[] = 'Stawka robocza (zł/h) musi być większa od zera w trybie wyjazdowym.';
        }
    }
    return $errs;
}
