<?php
/**
 * includes/rpts.php
 *
 * Weryfikacja w Rejestrze Sprawców Przestępstw na Tle Seksualnym (RPTS) dla
 * umów wolontariackich. Wymóg wynika z ustawy z 13.05.2016 r. o przeciwdziałaniu
 * zagrożeniom przestępczością na tle seksualnym i ochronie małoletnich — przed
 * dopuszczeniem osoby do działalności związanej z kontaktem z małoletnimi
 * organizator musi zweryfikować ją w rejestrze i udokumentować sprawdzenie.
 *
 * RPTS (rps.ms.gov.pl) nie udostępnia API do automatycznej weryfikacji —
 * sprawdzenie wykonuje koordynator ręcznie, a tu przechowujemy tylko wynik
 * i dowód (data, kto sprawdził, nr potwierdzenia, skan/wydruk).
 *
 * Wynik „wpis" (osoba figuruje w rejestrze) jest daną wrażliwą — szczegóły
 * widoczne tylko dla zarządu, wzorem includes/byli.php (is_zarzad()).
 *
 * Samonaprawa schematu — wzorzec jak includes/zlecenie_schema.php: ALTER TABLE
 * ADD COLUMN per kolumna w try/catch, bezpieczne na SQLite i MySQL.
 */

require_once __DIR__ . '/byli.php'; // is_zarzad()

(function () {
    static $done = false;
    if ($done) return;
    $done = true;

    $columns = [
        'rpts_wymagana'           => "INTEGER",
        'rpts_zweryfikowano'      => "INTEGER",
        'rpts_data_weryfikacji'   => "DATE",
        'rpts_wynik'              => "VARCHAR(20)",
        'rpts_zweryfikowal'       => "VARCHAR(255)",
        'rpts_nr_potwierdzenia'   => "VARCHAR(100)",
        'rpts_plik_potwierdzenia' => "VARCHAR(500)",
        'rpts_uwagi'              => "TEXT",
    ];

    foreach ($columns as $name => $def) {
        try {
            db()->exec("ALTER TABLE umowy_wolontariat ADD COLUMN {$name} {$def}");
        } catch (\Throwable $e) {
            // Kolumna już istnieje (duplicate) — to normalne, ignorujemy.
        }
    }
})();

if (!function_exists('rpts_wynik_options')) {
    function rpts_wynik_options(): array {
        return [
            'brak_wpisu' => 'Brak wpisu — można dopuścić do wolontariatu',
            'wpis'       => 'Stwierdzono wpis — kontakt z małoletnimi niedozwolony',
        ];
    }
}

if (!function_exists('rpts_wynik_label')) {
    function rpts_wynik_label(?string $key): string {
        return rpts_wynik_options()[$key] ?? '';
    }
}

/** Czy bieżący użytkownik widzi wrażliwe szczegóły weryfikacji (wynik „wpis" i uwagi). */
if (!function_exists('rpts_can_see_sensitive')) {
    function rpts_can_see_sensitive(array $row): bool {
        return ($row['rpts_wynik'] ?? '') !== 'wpis' || is_zarzad();
    }
}

/** Zwięzły badge do list.php — bez ujawniania treści uwag. */
if (!function_exists('rpts_list_badge')) {
    function rpts_list_badge(array $row): string {
        if (empty($row['rpts_wymagana'])) return '';

        if (empty($row['rpts_zweryfikowano'])) {
            return '<span class="badge bg-warning-subtle text-warning" style="font-size:.65rem"><i class="bi bi-shield-exclamation"></i> RPTS: do weryfikacji</span>';
        }
        if (($row['rpts_wynik'] ?? '') === 'wpis') {
            return '<span class="badge bg-danger-subtle text-danger" style="font-size:.65rem"><i class="bi bi-exclamation-octagon-fill"></i> RPTS: wymaga uwagi zarządu</span>';
        }
        return '<span class="badge bg-success-subtle text-success" style="font-size:.65rem"><i class="bi bi-shield-check"></i> RPTS: brak wpisu</span>';
    }
}
