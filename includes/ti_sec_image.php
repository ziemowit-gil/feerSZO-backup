<?php
/**
 * includes/ti_sec_image.php — obrazek bezpieczeństwa przy logowaniu kursanta (jak w iPKO).
 * Kursant dostaje obrazek przypisany losowo przez system. Obrazek pokazuje się po wpisaniu loginu;
 * dla nieistniejącego loginu — ogólny obrazek, żeby nie zdradzać, które loginy są w systemie.
 */

/** Zestaw obrazków: [nazwa (tekst dla czytników), kształt, kolor]. Indeks zapisany w koncie. */
const TI_SEC_IMAGES = [
    ['niebieskie koło',        'circle',   '#2563eb'],
    ['zielony kwadrat',        'square',   '#16a34a'],
    ['pomarańczowy trójkąt',   'triangle', '#ea580c'],
    ['fioletowa gwiazda',      'star',     '#7c3aed'],
    ['czerwony romb',          'diamond',  '#dc2626'],
    ['turkusowy sześciokąt',   'hexagon',  '#0891b2'],
    ['żółty pięciokąt',        'pentagon', '#ca8a04'],
    ['szary krzyż',            'cross',    '#475569'],
];

function ti_sec_image_migrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try { db()->exec("ALTER TABLE k30_ti_student_accounts ADD COLUMN sec_image INTEGER"); } catch (\Throwable $e) {}
    // 1 = kursant wybrał obrazek sam (albo wylosował przy wyborze), 0 = nie wybrał jeszcze.
    try { db()->exec("ALTER TABLE k30_ti_student_accounts ADD COLUMN sec_chosen INTEGER NOT NULL DEFAULT 0"); } catch (\Throwable $e) {}
}

/**
 * Zapisuje wybór kursanta: konkretny indeks (kliknięty obrazek) albo losowy (null).
 * Zwraca zapisany indeks.
 */
function ti_sec_image_set(int $account_id, ?int $idx): int {
    ti_sec_image_migrate();
    if ($idx === null || !isset(TI_SEC_IMAGES[$idx])) {
        $idx = random_int(0, count(TI_SEC_IMAGES) - 1);
    }
    db()->prepare("UPDATE k30_ti_student_accounts SET sec_image = ?, sec_chosen = 1 WHERE id = ?")
        ->execute([$idx, $account_id]);
    return $idx;
}

/** Przypisuje losowy obrazek koncie, jeśli jeszcze nie ma. Zwraca indeks. */
function ti_sec_image_assign(int $account_id): int {
    ti_sec_image_migrate();
    $row = db_one("SELECT sec_image FROM k30_ti_student_accounts WHERE id = ?", [$account_id]);
    if ($row && $row['sec_image'] !== null) return (int)$row['sec_image'];
    $idx = random_int(0, count(TI_SEC_IMAGES) - 1);
    db()->prepare("UPDATE k30_ti_student_accounts SET sec_image = ? WHERE id = ?")->execute([$idx, $account_id]);
    return $idx;
}

/** Opis obrazka dla czytników ekranu (np. „Twój obrazek bezpieczeństwa: niebieskie koło”). */
function ti_sec_image_label(int $idx): string {
    if (!isset(TI_SEC_IMAGES[$idx])) return 'Obrazek bezpieczeństwa';
    return 'Twój obrazek bezpieczeństwa: ' . TI_SEC_IMAGES[$idx][0];
}

/** SVG obrazka o indeksie; idx < 0 — ogólny obrazek (szary znak zapytania). */
function ti_sec_image_svg(int $idx): string {
    if (!isset(TI_SEC_IMAGES[$idx])) {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" role="img" aria-hidden="true">'
             . '<rect width="64" height="64" rx="12" fill="#e2e8f0"/>'
             . '<text x="32" y="44" font-size="34" text-anchor="middle" fill="#475569" font-family="sans-serif">?</text></svg>';
    }
    [, $ksztalt, $kolor] = TI_SEC_IMAGES[$idx];
    $fig = match ($ksztalt) {
        'circle'   => '<circle cx="32" cy="32" r="20" fill="' . $kolor . '"/>',
        'square'   => '<rect x="14" y="14" width="36" height="36" rx="4" fill="' . $kolor . '"/>',
        'triangle' => '<polygon points="32,12 52,50 12,50" fill="' . $kolor . '"/>',
        'star'     => '<polygon points="32,8 39,25 57,26 43,37 48,55 32,45 16,55 21,37 7,26 25,25" fill="' . $kolor . '"/>',
        'diamond'  => '<polygon points="32,8 56,32 32,56 8,32" fill="' . $kolor . '"/>',
        'hexagon'  => '<polygon points="32,8 54,20 54,44 32,56 10,44 10,20" fill="' . $kolor . '"/>',
        'pentagon' => '<polygon points="32,10 53,26 45,52 19,52 11,26" fill="' . $kolor . '"/>',
        'cross'    => '<polygon points="24,10 40,10 40,24 54,24 54,40 40,40 40,54 24,54 24,40 10,40 10,24 24,24" fill="' . $kolor . '"/>',
    };
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" role="img" aria-hidden="true">'
         . '<rect width="64" height="64" rx="12" fill="#f8fafc"/>' . $fig . '</svg>';
}
