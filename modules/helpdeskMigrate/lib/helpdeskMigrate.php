<?php
/**
 * modules/helpdeskMigrate/lib/helpdeskMigrate.php — migracja numerów istniejących zgłoszeń do wzorca {PREFIX}-{YYYY}-{NNNNN}.
 *
 * Stare numery (HD00020, HER00007, KUR00003…) dostają nowy numer wg domyślnego wzorca: prefiks zostaje, rok = rok utworzenia
 * zgłoszenia, licznik rośnie w kolejności tworzenia (po id) osobno dla prefiksu i roku — z uwzględnieniem numerów już nadanych
 * w nowym formacie. Stary numer zostaje w kolumnie legacy_number: odpowiedzi na stare e-maile ([HD00020] w temacie),
 * wyszukiwarka i łączenie zgłoszeń nadal go rozpoznają. Każda zmiana trafia do helpdesk_number_migrations (historia).
 * Migrację najpierw się podgląda (hdMigratePlan), potem wykonuje w jednej transakcji (hdMigrateRun).
 */
require_once dirname(__DIR__, 3) . '/includes/helpdesk.php';
require_once dirname(__DIR__, 2) . '/helpdeskCategories/lib/helpdeskCategories.php';

const HD_LEGACY_NUMBER_RE = '/^([A-Z]{2,5})(\d{3,8})$/';

function hdMigrateSchema(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    helpdesk_migrate();
    try { db()->exec("ALTER TABLE helpdesk_tickets ADD COLUMN legacy_number TEXT"); } catch (\Throwable $e) {}
    db()->exec("CREATE INDEX IF NOT EXISTS idx_hd_legacy_number ON helpdesk_tickets(legacy_number)");
    db()->exec("CREATE TABLE IF NOT EXISTS helpdesk_number_migrations (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        ticket_id  INTEGER NOT NULL,
        old_number TEXT NOT NULL,
        new_number TEXT NOT NULL,
        done_by    INTEGER,
        done_at    TEXT NOT NULL DEFAULT (datetime('now','localtime'))
    )");
}

/** Plan migracji: lista [id, old, new] w kolejności tworzenia. Nic nie zapisuje. */
function hdMigratePlan(): array
{
    hdMigrateSchema();
    $plan = []; $next = [];
    foreach (db_all("SELECT id, number, created_at FROM helpdesk_tickets WHERE legacy_number IS NULL ORDER BY id") as $t) {
        if (!preg_match(HD_LEGACY_NUMBER_RE, (string)$t['number'], $m)) continue;
        $ts = strtotime((string)$t['created_at']) ?: time();
        [$head, $digits] = hdNumberHead($m[1], null, $ts);
        if (!isset($next[$head])) $next[$head] = hdNumberMax($head);
        $next[$head]++;
        $plan[] = ['id' => (int)$t['id'], 'old' => (string)$t['number'], 'new' => $head . str_pad((string)$next[$head], $digits, '0', STR_PAD_LEFT)];
    }
    return $plan;
}

/** Wykonuje migrację (jedna transakcja). Zwraca ['migrated' => n] albo ['error' => komunikat]. */
function hdMigrateRun(int $byUid): array
{
    $plan = hdMigratePlan();
    if (!$plan) return ['migrated' => 0];
    $new = array_column($plan, 'new');
    if (count($new) !== count(array_unique($new))) return ['error' => 'Plan zawiera zduplikowane numery — przerwano.'];
    $marks = implode(',', array_fill(0, count($new), '?'));
    if (db_one("SELECT 1 FROM helpdesk_tickets WHERE number IN ($marks)", $new)) return ['error' => 'Któryś z nowych numerów już istnieje — przerwano.'];
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $up = $pdo->prepare("UPDATE helpdesk_tickets SET number=?, legacy_number=? WHERE id=? AND legacy_number IS NULL");
        $lg = $pdo->prepare("INSERT INTO helpdesk_number_migrations (ticket_id, old_number, new_number, done_by) VALUES (?,?,?,?)");
        foreach ($plan as $p) {
            $up->execute([$p['new'], $p['old'], $p['id']]);
            $lg->execute([$p['id'], $p['old'], $p['new'], $byUid ?: null]);
        }
        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        return ['error' => 'Błąd migracji: ' . $e->getMessage()];
    }
    return ['migrated' => count($plan)];
}

/** Zgłoszenie po numerze bieżącym albo dawnym (np. z tematu starego e-maila). */
function hdTicketByAnyNumber(string $number): ?array
{
    hdMigrateSchema();
    return db_one("SELECT * FROM helpdesk_tickets WHERE number=? OR legacy_number=? ORDER BY (number=?) DESC LIMIT 1", [$number, $number, $number]) ?: null;
}
