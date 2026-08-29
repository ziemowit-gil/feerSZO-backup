<?php
/**
 * includes/ti_print_log.php — Rejestr wygenerowanych wydruków panelu kierownika TI.
 *
 * Każdy z endpointów wydruku (dydaktyk/hours_pdf.php, billing_pdf.php, …) dopisuje
 * tu jeden wiersz przy udanym wygenerowaniu. Zasila zakładkę „Historia" na
 * karty30/ti/dydaktyk/wydruki.php — kierownik widzi, kto i kiedy co wydrukował.
 */

function ti_print_log_migrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    db()->exec("CREATE TABLE IF NOT EXISTS k30_ti_print_log (
        id                INTEGER PRIMARY KEY AUTOINCREMENT,
        type              TEXT    NOT NULL,
        label             TEXT    NOT NULL DEFAULT '',
        course_id         INTEGER NOT NULL DEFAULT 0,
        client_id         INTEGER NOT NULL DEFAULT 0,
        params            TEXT    NOT NULL DEFAULT '',
        generated_by      INTEGER REFERENCES users(id) ON DELETE SET NULL,
        generated_by_name TEXT    NOT NULL DEFAULT '',
        created_at        DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    db()->exec("CREATE INDEX IF NOT EXISTS idx_ti_print_log_created ON k30_ti_print_log(created_at)");
}

/**
 * Zapisuje jeden wpis w rejestrze wydruków. $actor — sesja panelu (dyd_require()),
 * bo current_user() w panelu dydaktyka jest puste.
 */
function ti_print_log_add(string $type, string $label, int $course_id = 0, int $client_id = 0,
                           array $params = [], ?array $actor = null): void {
    ti_print_log_migrate();
    try {
        db_insert('k30_ti_print_log', [
            'type'              => $type,
            'label'             => mb_substr($label, 0, 200),
            'course_id'         => max(0, $course_id),
            'client_id'         => max(0, $client_id),
            'params'            => json_encode($params, JSON_UNESCAPED_UNICODE),
            'generated_by'      => $actor['user_id'] ?? null,
            'generated_by_name' => mb_substr((string)($actor['name'] ?? ''), 0, 200),
        ]);
    } catch (\Throwable $e) { error_log('[ti_print_log_add] ' . $e->getMessage()); }
}

/** Ostatnie wpisy rejestru (najnowsze pierwsze) — do widoku historii. */
function ti_print_log_list(int $limit = 200): array {
    ti_print_log_migrate();
    return db_all(
        "SELECT l.*, c.name AS course_name, cl.name AS client_name
         FROM k30_ti_print_log l
         LEFT JOIN k30_ti_courses c ON c.id = l.course_id
         LEFT JOIN k30_clients   cl ON cl.id = l.client_id
         ORDER BY l.id DESC LIMIT ?",
        [$limit]
    );
}
