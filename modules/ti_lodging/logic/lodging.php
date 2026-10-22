<?php
/**
 * modules/ti_lodging/logic/lodging.php — noclegi kadry przy szkoleniach stacjonarnych.
 *
 * Tylko dwie opcje na osobę w grupie: „noc przed” (noc poprzedzająca pierwszą lekcję stacjonarną tej osoby)
 * i „noc po” (noc po ostatniej lekcji stacjonarnej). Daty NIE są zapisywane — wylicza je z lekcji TiLodging::span(),
 * więc przy zmianie terminów noclegi przesuwają się same. Zapis = tylko flaga (grupa, osoba, rodzaj).
 *
 * Sugestia: gdy lokalizacja sali lekcji stacjonarnej (nazwa, skrót, lokalizacja, budynek) zawiera KR, SZ lub WA
 * (wielkie litery), system sugeruje oba noclegi. Rodzaj 'none' = świadoma decyzja „stacjonarne bez noclegu”
 * (wyłącza sugestię; wyklucza się z before/after).
 */
require_once dirname(__DIR__, 3) . '/includes/karty30.php';

final class TiLodging
{
    public const KINDS = ['before' => 'Noc przed szkoleniem', 'after' => 'Noc po szkoleniu'];
    public const SUGGEST_MARKS = ['KR', 'SZ', 'WA'];

    public static function migrate(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        if (function_exists('szo_schema_current') && szo_schema_current('ti_lodging_migrate', __FILE__)) return;
        db()->exec("CREATE TABLE IF NOT EXISTS k30_ti_lodging (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            course_id  INTEGER NOT NULL REFERENCES k30_ti_courses(id) ON DELETE CASCADE,
            user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            kind       TEXT    NOT NULL CHECK (kind IN ('before','after','none')),
            created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(course_id, user_id, kind)
        )");
        // Starsza wersja tabeli (bez 'none' w CHECK) — przebudowa z zachowaniem danych.
        $def = (string)(db_one("SELECT sql FROM sqlite_master WHERE type='table' AND name='k30_ti_lodging'")['sql'] ?? '');
        if ($def !== '' && !str_contains($def, "'none'")) {
            db()->exec("ALTER TABLE k30_ti_lodging RENAME TO k30_ti_lodging_old");
            db()->exec("CREATE TABLE k30_ti_lodging (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                course_id INTEGER NOT NULL REFERENCES k30_ti_courses(id) ON DELETE CASCADE,
                user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                kind TEXT NOT NULL CHECK (kind IN ('before','after','none')),
                created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(course_id, user_id, kind))");
            db()->exec("INSERT INTO k30_ti_lodging (course_id,user_id,kind,created_by,created_at) SELECT course_id,user_id,kind,created_by,created_at FROM k30_ti_lodging_old");
            db()->exec("DROP TABLE k30_ti_lodging_old");
        }
    }

    /**
     * Kadra z lekcjami stacjonarnymi od dnia $from (domyślnie dziś): [course_id, user_id] => [first, last, n].
     * Prowadzący lekcji = COALESCE(s.instructor_id, c.instructor_id); bez wersji roboczych, rezerwacji i odwołanych.
     */
    public static function spans(?string $from = null, int $courseId = 0, int $userId = 0): array
    {
        $from = $from ?: date('Y-m-d');
        $sql = "SELECT s.course_id, COALESCE(s.instructor_id, c.instructor_id) AS uid, c.name AS course_name,
                       MIN(s.lesson_date) AS first_d, MAX(s.lesson_date) AS last_d, COUNT(*) AS n
                  FROM k30_ti_sessions s JOIN k30_ti_courses c ON c.id = s.course_id
                 WHERE s.lesson_method = 'stacjonarna' AND s.draft_id IS NULL
                   AND s.status NOT IN ('draft','reserved','cancelled')
                   AND COALESCE(s.instructor_id, c.instructor_id) IS NOT NULL";
        $p = [];
        if ($courseId) { $sql .= " AND s.course_id = ?"; $p[] = $courseId; }
        if ($userId)   { $sql .= " AND COALESCE(s.instructor_id, c.instructor_id) = ?"; $p[] = $userId; }
        $sql .= " GROUP BY s.course_id, uid HAVING MAX(s.lesson_date) >= ? ORDER BY MIN(s.lesson_date), c.name";
        $p[] = $from;
        $out = [];
        foreach (db_all($sql, $p) as $r) {
            $r['suggest'] = self::suggested((int)$r['course_id'], (int)$r['uid']);
            $out[] = $r;
        }
        return $out;
    }

    /** Czy któraś sala lekcji stacjonarnych tej osoby w grupie ma w lokalizacji KR / SZ / WA. */
    public static function suggested(int $courseId, int $userId): bool
    {
        $rows = db_all("SELECT DISTINCT r.name, r.short_label, r.location, b.name AS bname
                          FROM k30_ti_sessions s
                          JOIN k30_ti_courses c ON c.id = s.course_id
                          JOIN k30_pl_rooms r ON r.id = s.room_id
                          LEFT JOIN k30_pl_buildings b ON b.id = r.building_id
                         WHERE s.course_id = ? AND s.lesson_method = 'stacjonarna' AND s.draft_id IS NULL
                           AND s.status NOT IN ('draft','reserved','cancelled')
                           AND COALESCE(s.instructor_id, c.instructor_id) = ?", [$courseId, $userId]);
        foreach ($rows as $r) {
            $txt = ($r['name'] ?? '') . ' ' . ($r['short_label'] ?? '') . ' ' . ($r['location'] ?? '') . ' ' . ($r['bname'] ?? '');
            foreach (self::SUGGEST_MARKS as $m) if (str_contains($txt, $m)) return true;
        }
        return false;
    }

    /** Daty nocy: before = [dzień przed pierwszą lekcją → pierwsza lekcja], after = [ostatnia lekcja → dzień po]. */
    public static function nights(string $first, string $last): array
    {
        return [
            'before' => [date('Y-m-d', strtotime($first . ' -1 day')), $first],
            'after'  => [$last, date('Y-m-d', strtotime($last . ' +1 day'))],
        ];
    }

    /** Zaznaczone noclegi: "course_id:user_id:kind" => true. */
    public static function flags(): array
    {
        self::migrate();
        $o = [];
        foreach (db_all("SELECT course_id, user_id, kind FROM k30_ti_lodging") as $r) $o[$r['course_id'] . ':' . $r['user_id'] . ':' . $r['kind']] = true;
        return $o;
    }

    /** Ustawia / zdejmuje nocleg. Zwraca false przy nieprawidłowych danych (brak lekcji stacjonarnych osoby w grupie). */
    public static function set(int $courseId, int $userId, string $kind, bool $on, int $by): bool
    {
        self::migrate();
        if ((!isset(self::KINDS[$kind]) && $kind !== 'none') || !$courseId || !$userId) return false;
        if ($on) {
            // „Bez noclegu” wyklucza noc przed/po i odwrotnie
            $drop = $kind === 'none' ? "kind IN ('before','after')" : "kind = 'none'";
            db()->prepare("DELETE FROM k30_ti_lodging WHERE course_id=? AND user_id=? AND $drop")->execute([$courseId, $userId]);
            if (!self::spans(date('Y-m-d', 0), $courseId, $userId)) return false;
            db()->prepare("INSERT OR IGNORE INTO k30_ti_lodging (course_id, user_id, kind, created_by) VALUES (?,?,?,?)")
               ->execute([$courseId, $userId, $kind, $by ?: null]);
        } else {
            db()->prepare("DELETE FROM k30_ti_lodging WHERE course_id=? AND user_id=? AND kind=?")->execute([$courseId, $userId, $kind]);
        }
        return true;
    }
}
