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

    // Zgoda na weryfikację RPTS + dane do niej potrzebne — trzymana per-osoba
    // (na users), bo dotyczy też osób bez jeszcze zawartej umowy (samodzielne
    // konto wolontariusza) i przetrwa ewentualną zmianę/odnowienie umowy.
    $user_columns = [
        'rpts_consent'           => "INTEGER",
        'rpts_consent_at'        => "DATETIME",
        'rpts_pesel'             => "VARCHAR(11)",
        'rpts_data_urodzenia'    => "DATE",
        'rpts_miejsce_urodzenia' => "VARCHAR(255)",
        'rpts_nazwisko_rodowe'   => "VARCHAR(255)",
        'rpts_imie_ojca'         => "VARCHAR(255)",
        'rpts_imie_matki'        => "VARCHAR(255)",
    ];

    foreach ($user_columns as $name => $def) {
        try {
            db()->exec("ALTER TABLE users ADD COLUMN {$name} {$def}");
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

// ── Zgoda wolontariusza na weryfikację (popup w panelu) ────────────────────────

/**
 * Czy bieżący zalogowany (rola viewer) powinien zobaczyć popup ze zgodą na RPTS?
 * Dotyczy wyłącznie osób, których współpraca wiąże się (lub może wiązać) z
 * kontaktem z małoletnimi: bez umowy wolontariackiej jeszcze (nie wiadomo, czego
 * będzie dotyczyć) LUB z umową, w której zaznaczono „kontakt z małoletnimi"
 * (rpts_wymagana=1). Osoby z umową bez tej flagi nie są pytane — zgoda RPTS
 * ich nie dotyczy.
 *
 * Zwraca null, gdy popup nie jest potrzebny, albo tablicę z ewentualnymi danymi
 * do prefill (pesel/data urodzenia) pobranymi z umowy, jeśli istnieje.
 */
if (!function_exists('rpts_consent_needed')) {
    function rpts_consent_needed(array $user): ?array {
        if (!empty($user['rpts_consent'])) return null;
        if (($user['role'] ?? 'viewer') !== 'viewer') return null;

        if (!empty($user['is_standalone_volunteer'])) {
            return ['contract' => null];
        }

        $email = trim($user['email'] ?? '');
        if ($email === '') return null;

        try {
            $contract = db_one(
                "SELECT id, pesel, data_urodzenia FROM umowy_wolontariat
                 WHERE email = ? AND rpts_wymagana = 1
                 ORDER BY created_at DESC LIMIT 1",
                [$email]
            );
        } catch (\Throwable $e) {
            $contract = null;
        }

        return $contract ? ['contract' => $contract] : null;
    }
}

/** Zapisuje zgodę i uzupełnione dane osobowe potrzebne do weryfikacji RPTS. */
if (!function_exists('rpts_consent_save')) {
    function rpts_consent_save(int $user_id, array $d): void {
        db()->prepare(
            "UPDATE users SET
                rpts_consent = 1,
                rpts_consent_at = ?,
                rpts_pesel = ?,
                rpts_data_urodzenia = ?,
                rpts_miejsce_urodzenia = ?,
                rpts_nazwisko_rodowe = ?,
                rpts_imie_ojca = ?,
                rpts_imie_matki = ?
             WHERE id = ?"
        )->execute([
            date('Y-m-d H:i:s'),
            trim($d['pesel'] ?? ''),
            (trim($d['data_urodzenia'] ?? '') ?: null),
            trim($d['miejsce_urodzenia'] ?? ''),
            trim($d['nazwisko_rodowe'] ?? ''),
            trim($d['imie_ojca'] ?? ''),
            trim($d['imie_matki'] ?? ''),
            $user_id,
        ]);
    }
}
