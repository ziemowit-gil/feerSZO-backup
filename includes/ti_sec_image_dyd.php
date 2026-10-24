<?php
/**
 * includes/ti_sec_image_dyd.php — obrazek bezpieczeństwa dla prowadzących (panel dydaktyka).
 * Ten sam zestaw obrazków co dla kursantów (ti_sec_image.php). Wybór w osobnej tabeli,
 * tabela użytkowników nie jest zmieniana.
 */
require_once __DIR__ . '/ti_sec_image.php';

function ti_sec_dyd_migrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    db()->exec("CREATE TABLE IF NOT EXISTS k30_ti_instructor_sec (
        user_id     INTEGER PRIMARY KEY,
        sec_image   INTEGER NOT NULL,
        sec_chosen  INTEGER NOT NULL DEFAULT 0
    )");
}

/** Obrazek prowadzącego; gdy go nie ma — losowy przydział. */
function ti_sec_dyd_assign(int $user_id): int {
    ti_sec_dyd_migrate();
    $row = db_one("SELECT sec_image FROM k30_ti_instructor_sec WHERE user_id = ?", [$user_id]);
    if ($row) return (int)$row['sec_image'];
    $idx = random_int(0, count(TI_SEC_IMAGES) - 1);
    db()->prepare("INSERT OR IGNORE INTO k30_ti_instructor_sec (user_id, sec_image, sec_chosen) VALUES (?, ?, 0)")
        ->execute([$user_id, $idx]);
    return $idx;
}

/** Zapis wyboru prowadzącego: konkretny indeks albo losowy (null). */
function ti_sec_dyd_set(int $user_id, ?int $idx): int {
    ti_sec_dyd_migrate();
    if ($idx === null || !isset(TI_SEC_IMAGES[$idx])) {
        $idx = random_int(0, count(TI_SEC_IMAGES) - 1);
    }
    db()->prepare("INSERT INTO k30_ti_instructor_sec (user_id, sec_image, sec_chosen) VALUES (?, ?, 1)
                   ON CONFLICT(user_id) DO UPDATE SET sec_image = excluded.sec_image, sec_chosen = 1")
        ->execute([$user_id, $idx]);
    return $idx;
}

/** Czy prowadzący już wybrał obrazek (sam albo losowo na stronie wyboru). */
function ti_sec_dyd_chosen(int $user_id): bool {
    ti_sec_dyd_migrate();
    $row = db_one("SELECT sec_chosen FROM k30_ti_instructor_sec WHERE user_id = ?", [$user_id]);
    return $row && (int)$row['sec_chosen'] === 1;
}
