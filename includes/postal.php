<?php
/**
 * includes/postal.php
 * Funkcje pomocnicze: numery S10 Poczty Polskiej, numeracja KORW,
 * dane przewoźników, integracja z tabelą correspondence.
 */

// ── S10 (UPU) ─────────────────────────────────────────────────────────────────

/**
 * Cyfra kontrolna S10.
 * Wagi: [8,6,4,2,3,5,9,7] dla 8-cyfrowego serialu.
 * Wynik: 0,5 lub 11-reszta.
 */
function s10_check_digit(string $eight_digits): int {
    $w   = [8, 6, 4, 2, 3, 5, 9, 7];
    $sum = 0;
    for ($i = 0; $i < 8; $i++) {
        $sum += (int)$eight_digits[$i] * $w[$i];
    }
    $r = $sum % 11;
    if ($r === 0) return 5;
    if ($r === 1) return 0;
    return 11 - $r;
}

/**
 * Generuje pełny kod S10: XX00000042cPL
 * @param int    $serial       Liczba 1–99999999 (np. auto-increment ID rekordu)
 * @param string $service_code 'RR'=polecony, 'RA'=polecony priorytet, 'CP'=EMS
 */
function s10_generate(int $serial, string $service_code = 'RR'): string {
    $d = str_pad((string)$serial, 8, '0', STR_PAD_LEFT);
    return strtoupper($service_code) . $d . s10_check_digit($d) . 'PL';
}

// ── Numeracja KORW ────────────────────────────────────────────────────────────

/**
 * Następny wolny numer wychodzącej korespondencji w formacie KORW/YYYY/NNN.
 * Zlicza rekordy o danym prefiksie i bieżącym roku.
 */
function corr_next_dispatch_number(string $prefix = 'KORW'): string {
    _corr_init();
    $year = date('Y');
    $row  = db_one(
        "SELECT COUNT(*) AS c FROM correspondence
         WHERE direction='outgoing' AND number LIKE ?",
        [$prefix . '/' . $year . '/%']
    );
    $seq = (int)($row['c'] ?? 0) + 1;
    return $prefix . '/' . $year . '/' . str_pad((string)$seq, 3, '0', STR_PAD_LEFT);
}

// ── Przewoźnicy ───────────────────────────────────────────────────────────────

function carrier_labels(): array {
    return [
        'poczta_polecony'    => 'Poczta Polska — polecony',
        'poczta_polecony_pr' => 'Poczta Polska — polecony priorytet',
        'poczta_zwykly'      => 'Poczta Polska — zwykły (bez śledzenia)',
        'dpd'                => 'DPD',
        'dhl'                => 'DHL',
        'inpost'             => 'InPost Kurier',
        'inpost_paczkomat'   => 'InPost Paczkomat',
    ];
}

/** Przewoźnicy obsługiwani przez Apaczka.pl API. */
function carrier_uses_apaczka(string $carrier): bool {
    return in_array($carrier, ['dpd', 'dhl', 'inpost', 'inpost_paczkomat'], true);
}

/** Kod usługi S10 dla danego przewoźnika (null = brak kodu kreskowego S10). */
function carrier_s10_service(string $carrier): ?string {
    return match ($carrier) {
        'poczta_polecony'    => 'RR',
        'poczta_polecony_pr' => 'RA',
        default              => null,
    };
}

/**
 * Tworzy/aktualizuje rekord korespondencji wychodzącej i wypełnia s10_number.
 *
 * @param array $data   Pola do zapisu (carrier, contract_type, contract_id, …)
 * @param int   $uid    ID użytkownika tworzącego
 * @return array        ['corr_id'=>int, 'number'=>string, 's10'=>string]
 */
function corr_create_dispatch(array $data, int $uid): array {
    _corr_init();

    $number = $data['number'] ?? corr_next_dispatch_number();
    $corr_data = [
        'direction'     => 'outgoing',
        'number'        => $number,
        'date'          => $data['dispatch_date'] ?? date('Y-m-d'),
        'correspondent' => $data['correspondent'] ?? '',
        'subject'       => $data['subject']       ?? 'Korespondencja wychodząca',
        'description'   => $data['description']   ?? '',
        'category'      => $data['category']      ?? 'wysyłka',
        'status'        => 'new',
        'carrier'       => $data['carrier']        ?? '',
        'shipment_type' => $data['shipment_type']  ?? '',
        'dispatch_date' => $data['dispatch_date']  ?? date('Y-m-d'),
        'contract_type' => $data['contract_type']  ?? '',
        'contract_id'   => $data['contract_id']    ? (int)$data['contract_id'] : null,
        'tracking_number'       => $data['tracking_number']       ?? '',
        'apaczka_shipment_id'   => $data['apaczka_shipment_id']   ?? null,
        's10_number'            => '',
        'created_by'            => $uid,
        'created_at'            => date('Y-m-d H:i:s'),
        'updated_at'            => date('Y-m-d H:i:s'),
    ];

    $corr_id = db_insert('correspondence', $corr_data);

    // Generuj S10 z ID rekordu (unikalność gwarantowana)
    $s10 = '';
    $svc = carrier_s10_service($corr_data['carrier']);
    if ($svc) {
        $s10 = s10_generate($corr_id, $svc);
        db()->prepare("UPDATE correspondence SET s10_number=? WHERE id=?")
            ->execute([$s10, $corr_id]);
    }

    return [
        'corr_id' => $corr_id,
        'number'  => $number,
        's10'     => $s10,
    ];
}
