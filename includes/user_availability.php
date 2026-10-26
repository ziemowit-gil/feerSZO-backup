<?php
/**
 * includes/user_availability.php — „Poza biurem” (wzór: moduł Out of Office z FreeScout).
 *
 * Osoba ustawia własną nieobecność (opcjonalnie od–do, z notatką); administrator może to zrobić za każdego. Dopóki trwa:
 *   • nieobecnej osoby nie da się wskazać jako opiekuna rozmowy w Skrzynce CRM (crm_mailbox_assign) — w liście opiekunów jest oznaczona „poza biurem”,
 *   • gdy nadawca odpisze w rozmowie, której opiekunem jest nieobecna osoba, rozmowa wraca do „bez opiekuna” (crm_ooo_sweep, cron + wejście do Skrzynki),
 *   • przypomnienie o własnej nieobecności w Skrzynce z przyciskiem „Wróciłem”.
 * Nieobecność z datą końca wygasa sama (po dacie „do”). Z datą początku w przyszłości — jeszcze nie obowiązuje.
 * Ograniczenie jak w FreeScout: blokada dotyczy ręcznego przypisywania w Skrzynce CRM; API i reguły automatyczne mogą jej nie respektować.
 */

function uav_migrate(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $pk = (defined('DB_TYPE') && DB_TYPE !== 'sqlite') ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS user_availability (
            user_id   INTEGER PRIMARY KEY,
            away      INTEGER NOT NULL DEFAULT 0,
            from_date VARCHAR(10),
            to_date   VARCHAR(10),
            note      VARCHAR(200) NOT NULL DEFAULT '',
            set_at    VARCHAR(19),
            set_by    INTEGER
        )");
        db()->exec("CREATE TABLE IF NOT EXISTS crm_ooo_log (
            id        {$pk},
            comm_id   INTEGER NOT NULL,
            old_user  INTEGER NOT NULL,
            reason    VARCHAR(200) NOT NULL DEFAULT '',
            created_at VARCHAR(19)
        )");
    } catch (\Throwable $e) {
        error_log('[uav_migrate] ' . $e->getMessage());
    }
}

/** Wiersz nieobecności albo null. */
function uav_get(int $user_id): ?array
{
    uav_migrate();
    try { return db_one("SELECT * FROM user_availability WHERE user_id = ?", [$user_id]) ?: null; } catch (\Throwable $e) { return null; }
}

/** Czy wiersz nieobecności obowiązuje w danym dniu (domyślnie dziś). */
function uav_row_active(?array $row, ?string $date = null): bool
{
    if (!$row || !(int)$row['away']) return false;
    $d = $date ?? date('Y-m-d');
    if (!empty($row['from_date']) && $d < $row['from_date']) return false;
    if (!empty($row['to_date']) && $d > $row['to_date']) return false;
    return true;
}

function uav_is_away(int $user_id, ?string $date = null): bool
{
    return $user_id > 0 && uav_row_active(uav_get($user_id), $date);
}

/** Id osób nieobecnych dziś: [user_id => wiersz]. */
function uav_away_map(): array
{
    uav_migrate();
    static $cache = null;
    if ($cache !== null && empty($GLOBALS['_uav_bump'])) return $cache;
    $GLOBALS['_uav_bump'] = 0;
    $cache = [];
    try {
        foreach (db_all("SELECT * FROM user_availability WHERE away = 1") as $r) if (uav_row_active($r)) $cache[(int)$r['user_id']] = $r;
    } catch (\Throwable $e) {}
    return $cache;
}

/** Ustawia nieobecność. Daty Y-m-d (puste = bez ograniczenia). Zwraca true przy sukcesie. */
function uav_set(int $user_id, bool $away, string $from = '', string $to = '', string $note = '', ?int $by = null): bool
{
    uav_migrate();
    $ok = fn($d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : null;
    $from = $ok($from); $to = $ok($to);
    if ($from && $to && $to < $from) return false;
    $row = ['away' => $away ? 1 : 0, 'from_date' => $away ? $from : null, 'to_date' => $away ? $to : null, 'note' => mb_substr(trim($note), 0, 200),
            'set_at' => date('Y-m-d H:i:s'), 'set_by' => $by ?? ((int)(current_user()['id'] ?? 0) ?: null)];
    $exists = db_one("SELECT 1 AS x FROM user_availability WHERE user_id = ?", [$user_id]);
    if ($exists) {
        db()->prepare("UPDATE user_availability SET away=:away, from_date=:from_date, to_date=:to_date, note=:note, set_at=:set_at, set_by=:set_by WHERE user_id=:uid")->execute($row + ['uid' => $user_id]);
    } else {
        db()->prepare("INSERT INTO user_availability (user_id, away, from_date, to_date, note, set_at, set_by) VALUES (:uid,:away,:from_date,:to_date,:note,:set_at,:set_by)")->execute($row + ['uid' => $user_id]);
    }
    $GLOBALS['_uav_bump'] = 1;
    return true;
}

/** Od kiedy nieobecność obowiązuje wobec nowych wiadomości: późniejsza z (chwila ustawienia, początek okresu). */
function uav_since(array $row): string
{
    $since = (string)($row['set_at'] ?: date('Y-m-d H:i:s'));
    if (!empty($row['from_date']) && $row['from_date'] . ' 00:00:00' > $since) $since = $row['from_date'] . ' 00:00:00';
    return $since;
}

/** Etykieta okresu do wyświetlenia: „do 5.11.2026”, „od … do …”, „bezterminowo”. */
function uav_period_label(array $row): string
{
    $fmt = fn($d) => date('d.m.Y', strtotime($d));
    if (!empty($row['from_date']) && !empty($row['to_date'])) return 'od ' . $fmt($row['from_date']) . ' do ' . $fmt($row['to_date']);
    if (!empty($row['to_date'])) return 'do ' . $fmt($row['to_date']);
    if (!empty($row['from_date'])) return 'od ' . $fmt($row['from_date']);
    return 'bezterminowo';
}

/**
 * Odpina rozmowy nieobecnych opiekunów, w których odpisał nadawca (po rozpoczęciu nieobecności): wiadomość nowa i nieprzeczytana albo
 * późniejsza wiadomość przychodząca w tym samym wątku. Zwraca liczbę odpiętych wiadomości. Bezpieczne do wielokrotnego wołania.
 */
function crm_ooo_sweep(): int
{
    uav_migrate();
    $total = 0;
    try {
        foreach (uav_away_map() as $uid => $row) {
            $since = uav_since($row);
            $ids = db_all(
                "SELECT c.id FROM crm_communications c
                 WHERE c.assigned_to = ? AND c.direction = 'in' AND c.inbox_status = 'active' AND (
                     (c.is_read = 0 AND c.sent_at >= ?)
                     OR (c.thread_key IS NOT NULL AND c.thread_key <> '' AND EXISTS (
                         SELECT 1 FROM crm_communications n WHERE n.thread_key = c.thread_key AND n.direction = 'in' AND n.id <> c.id
                           AND n.sent_at > c.sent_at AND n.sent_at >= ?)))",
                [$uid, $since, $since]
            );
            if (!$ids) continue;
            $list = array_map('intval', array_column($ids, 'id'));
            $in = implode(',', array_fill(0, count($list), '?'));
            db()->prepare("UPDATE crm_communications SET assigned_to = NULL, updated_at = datetime('now') WHERE id IN ($in)")->execute($list);
            $now = date('Y-m-d H:i:s');
            foreach ($list as $cid) db_insert('crm_ooo_log', ['comm_id' => $cid, 'old_user' => $uid, 'reason' => 'nadawca odpisał, opiekun poza biurem', 'created_at' => $now]);
            $total += count($list);
        }
    } catch (\Throwable $e) {
        error_log('[crm_ooo_sweep] ' . $e->getMessage());
    }
    return $total;
}
