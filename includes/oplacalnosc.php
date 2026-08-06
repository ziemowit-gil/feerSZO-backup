<?php
/**
 * includes/oplacalnosc.php — Silnik oceny opłacalności działań (SZO FEER).
 *
 * Dwie perspektywy ROI:
 *   roi_org    — (Zysk Netto Fundacji / Koszt Całkowity) × 100 %
 *                → ile zysku na każdą złotówkę zaangażowanego kosztu
 *   roi_worker — (Wynagrodzenie Netto Pracownika / Brutto Pracownika) × 100 %
 *                → ile pracownik zachowuje z umówionej stawki
 *
 * Jedyny publiczny punkt wejścia: oplacalnosc_oblicz(array $params): array
 */

/* ── Stałe ──────────────────────────────────────────────────────────────── */

const OPLACALNOSC_RESERVE          = 0.10;    // 10 % nadwyżki kosztowej
const OPLACALNOSC_ONLINE_RATE      = 60.00;   // zł/h — stawka online
const OPLACALNOSC_ONLINE_PLATFORM  = 20.00;   // zł — stała opłata za platformę
const OPLACALNOSC_TRAVEL_BASE      = 160.00;  // zł — bazowy koszt wyjazdu
const OPLACALNOSC_TRAVEL_TIME_RATE = 9.81;    // zł/h — wycena czasu dojazdu

const OPLACALNOSC_ROI_OK_THRESHOLD = 10.0;    // % — próg ROI dla statusu OPŁACALNE

/**
 * Modele obciążeń publicznoprawnych — stawki szacunkowe 2025/2026.
 *
 * Klucze "org_*":  obciążenia po stronie FUNDACJI (liczone od nadwyżki)
 * Klucze "emp_*":  obciążenia po stronie PRACOWNIKA (liczone od brutto wynagrodzenia)
 *
 * org_zus_rate  : składki ZUS pracodawcy (% wynagrodzenia brutto)
 * org_kup_rate  : KUP odliczane przed PIT pracodawcy
 * org_it_rate   : PIT od (nadwyżka − ZUS pracodawcy)
 *
 * emp_social    : składki ZUS pracownika (emerytalne+rentowe+chorobowe)
 * emp_health    : składka zdrowotna pracownika (% od brutto − social)
 * emp_kup       : KUP przed PIT pracownika (% brutto)
 * emp_it        : PIT pracownika (% podstawy po KUP)
 */
const OPLACALNOSC_TAX_MODELS = [
    'zlecenie' => [
        'label'      => 'Umowa zlecenie',
        'note'       => 'ZUS 23,14 % + PIT 12 % (org.) | prac.: ZUS 13,71 % + NFZ 9 % + PIT 12 %',
        // Strona fundacji (pracodawca)
        'org_zus'    => 0.2314,   // emerytalne 9.76 % + rentowe 6.5 % + wypadkowe ~1.67 % + FP 2.45 %
        'org_kup'    => 0.0,
        'org_it'     => 0.12,
        // Strona pracownika (zleceniobiorca)
        'emp_social' => 0.1371,   // emerytalne 9.76 % + rentowe 1.5 % + chorobowe 2.45 %
        'emp_health' => 0.09,     // zdrowotna 9 % od (brutto − social)
        'emp_kup'    => 0.20,     // 20 % zryczałtowanych KUP
        'emp_it'     => 0.12,
    ],
    'dzielo' => [
        'label'      => 'Umowa o dzieło',
        'note'       => 'PIT 12 %, KUP 20 % (bez ZUS) (org.) | prac.: PIT 12 %, KUP 20 %',
        'org_zus'    => 0.0,
        'org_kup'    => 0.20,
        'org_it'     => 0.12,
        'emp_social' => 0.0,      // brak ZUS społecznego (poza tytułami zbiegającymi)
        'emp_health' => 0.0,
        'emp_kup'    => 0.20,
        'emp_it'     => 0.12,
    ],
    'b2b' => [
        'label'      => 'B2B / działalność gospodarcza',
        'note'       => 'PIT liniowy 19 % (ZUS po stronie wykonawcy)',
        'org_zus'    => 0.0,
        'org_kup'    => 0.0,
        'org_it'     => 0.19,
        'emp_social' => 0.0,      // ZUS ryczałtowy — płaci wykonawca samodzielnie z całości dz.g.
        'emp_health' => 0.0,
        'emp_kup'    => 0.0,
        'emp_it'     => 0.19,     // podatek liniowy
    ],
];

/* ── Obliczenia strony pracownika ────────────────────────────────────────── */

/**
 * Szacuje wynagrodzenie netto pracownika z podanego brutto.
 *
 * @return array { gross, social, health, pit, net, net_pct }
 */
function oplacalnosc_worker(float $gross, string $model_key): array {
    $t = OPLACALNOSC_TAX_MODELS[$model_key] ?? OPLACALNOSC_TAX_MODELS['zlecenie'];

    $social = round($gross * $t['emp_social'], 2);
    $health = round(($gross - $social) * $t['emp_health'], 2);

    $pit_base = max(0.0, $gross - $social - round($gross * $t['emp_kup'], 2));
    $pit      = round($pit_base * $t['emp_it'], 2);

    $net     = round($gross - $social - $health - $pit, 2);
    $net_pct = $gross > 0 ? round($net / $gross * 100, 2) : 0.0;

    return compact('gross', 'social', 'health', 'pit', 'net', 'net_pct');
}

/* ── Główna funkcja ─────────────────────────────────────────────────────── */

/**
 * Oblicza pełny raport opłacalności z dwiema perspektywami ROI.
 *
 * Parametry ($p):
 *   tryb         string   'online' | 'wyjazdowy'
 *   przychod     float    Oferowany przychód brutto fundacji (PLN)
 *   czas_pracy   float    Czas pracy (godziny)
 *   stawka       float    Stawka robocza zł/h — tryb wyjazdowy
 *   czas_dojazdu float    Czas dojazdu (godziny) — tryb wyjazdowy
 *   bilety       float    Koszty biletów/transportu (PLN) — tryb wyjazdowy
 *   model_zus    string   'zlecenie' | 'dzielo' | 'b2b'
 *
 * @return array {
 *   // Perspektywa fundacji
 *   tryb, przychod, koszt_bazowy, nadwyzka_kosztowa, koszt_calkowity,
 *   surplus, zus_org, podatek_org, obciazenia_org, zysk_netto,
 *   roi_org,           // ROI fundacji: Zysk / Koszt × 100 %
 *   // Perspektywa pracownika
 *   worker,            // tablica z gross, social, health, pit, net, net_pct
 *   roi_worker,        // = worker.net_pct: Net / Brutto × 100 %
 *   // Wspólne
 *   status, model_key, model_zus_label, model_zus_note,
 *   breakdown
 * }
 */
function oplacalnosc_oblicz(array $p): array {
    $tryb       = ($p['tryb'] ?? 'online') === 'wyjazdowy' ? 'wyjazdowy' : 'online';
    $przychod   = max(0.0, (float)($p['przychod']   ?? 0));
    $czas_pracy = max(0.0, (float)($p['czas_pracy'] ?? 0));
    $model_key  = array_key_exists($p['model_zus'] ?? '', OPLACALNOSC_TAX_MODELS)
                  ? $p['model_zus'] : 'zlecenie';
    $tax = OPLACALNOSC_TAX_MODELS[$model_key];

    /* ── Koszt bazowy ────────────────────────────────────────────────────── */
    $breakdown = [];
    if ($tryb === 'wyjazdowy') {
        $czas_dojazdu = max(0.0, (float)($p['czas_dojazdu'] ?? 0));
        $bilety       = max(0.0, (float)($p['bilety']       ?? 0));
        $stawka       = max(0.0, (float)($p['stawka']       ?? 0));

        $breakdown = [
            'baza'   => OPLACALNOSC_TRAVEL_BASE,
            'dojazd' => round($czas_dojazdu * OPLACALNOSC_TRAVEL_TIME_RATE, 2),
            'bilety' => $bilety,
            'praca'  => round($czas_pracy * $stawka, 2),
        ];
        $worker_gross = $breakdown['praca'];
    } else {
        $stawka = OPLACALNOSC_ONLINE_RATE;
        $breakdown = [
            'praca'     => round($czas_pracy * $stawka, 2),
            'platforma' => OPLACALNOSC_ONLINE_PLATFORM,
        ];
        $worker_gross = $breakdown['praca'];
    }

    $koszt_bazowy      = round(array_sum($breakdown), 2);
    $nadwyzka_kosztowa = round($koszt_bazowy * OPLACALNOSC_RESERVE, 2);
    $koszt_calkowity   = round($koszt_bazowy + $nadwyzka_kosztowa, 2);

    /* ── Perspektywa fundacji ────────────────────────────────────────────── */
    $surplus = round($przychod - $koszt_calkowity, 2);

    $zus_org = 0.0; $podatek_org = 0.0;
    if ($surplus > 0) {
        $zus_org  = round($surplus * $tax['org_zus'], 2);
        $it_base  = $surplus - $zus_org;
        if ($tax['org_kup'] > 0) {
            $it_base = round($it_base * (1 - $tax['org_kup']), 2);
        }
        $podatek_org = round($it_base * $tax['org_it'], 2);
    }
    $obciazenia_org = round($zus_org + $podatek_org, 2);
    $zysk_netto     = round($surplus - $obciazenia_org, 2);

    $roi_org = ($koszt_calkowity > 0)
        ? round(($zysk_netto / $koszt_calkowity) * 100, 2)
        : 0.0;

    /* ── Perspektywa pracownika ──────────────────────────────────────────── */
    $worker    = oplacalnosc_worker($worker_gross, $model_key);
    $roi_worker = $worker['net_pct'];

    /* ── Status ──────────────────────────────────────────────────────────── */
    if ($roi_org >= OPLACALNOSC_ROI_OK_THRESHOLD) {
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
        'zus_org'           => $zus_org,
        'podatek_org'       => $podatek_org,
        'obciazenia_org'    => $obciazenia_org,
        'zysk_netto'        => $zysk_netto,
        'roi_org'           => $roi_org,
        'worker'            => $worker,
        'roi_worker'        => $roi_worker,
        'status'            => $status,
        'model_key'         => $model_key,
        'model_zus_label'   => $tax['label'],
        'model_zus_note'    => $tax['note'],
        'breakdown'         => $breakdown,
    ];
}

/* ── Walidacja ──────────────────────────────────────────────────────────── */

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
