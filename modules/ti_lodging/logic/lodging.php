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
        // Migawka lekcji stacjonarnych z chwili zaznaczenia — do wykrycia zmiany terminów (powiadomienie).
        foreach (["snap_first TEXT NOT NULL DEFAULT ''", "snap_last TEXT NOT NULL DEFAULT ''"] as $col) {
            try { db()->exec("ALTER TABLE k30_ti_lodging ADD COLUMN {$col}"); } catch (\Throwable $e) {}
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
        if ($userId)   { $sql .= " AND COALESCE(s.instructor_id, c.instructor_id) = CAST(? AS INTEGER)"; $p[] = $userId; }
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
                           AND COALESCE(s.instructor_id, c.instructor_id) = CAST(? AS INTEGER)", [$courseId, $userId]);
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
            $sp = self::spans(date('Y-m-d', 0), $courseId, $userId)[0];
            db()->prepare("INSERT OR IGNORE INTO k30_ti_lodging (course_id, user_id, kind, created_by, snap_first, snap_last) VALUES (?,?,?,?,?,?)")
               ->execute([$courseId, $userId, $kind, $by ?: null, $sp['first_d'], $sp['last_d']]);
        } else {
            db()->prepare("DELETE FROM k30_ti_lodging WHERE course_id=? AND user_id=? AND kind=?")->execute([$courseId, $userId, $kind]);
        }
        return true;
    }

    /** Zestawienie do zamawiania (zaznaczone noclegi nadchodzących szkoleń) w formacie AnalyticsExport. */
    public static function dataset(int $onlyUser = 0): array
    {
        $flags = self::flags();
        $names = [];
        foreach (db_all("SELECT id, name FROM users") as $u) $names[(int)$u['id']] = (string)$u['name'];
        $rows = [];
        foreach (self::spans(null, 0, $onlyUser) as $r) {
            $nt = self::nights($r['first_d'], $r['last_d']);
            foreach (self::KINDS as $k => $label) {
                if (!isset($flags[$r['course_id'] . ':' . $r['uid'] . ':' . $k])) continue;
                $rows[] = [$nt[$k][0], $nt[$k][1], $names[(int)$r['uid']] ?? '', $r['course_name'], $label];
            }
        }
        usort($rows, fn($a, $b) => [$a[0], $a[2]] <=> [$b[0], $b[2]]);
        return ['title' => 'Noclegi kadry', 'subtitle' => 'Zaznaczone noclegi nadchodzących szkoleń stacjonarnych (data przyjazdu i wyjazdu).',
                'headers' => ['Przyjazd', 'Wyjazd', 'Osoba', 'Grupa', 'Rodzaj'], 'rows' => $rows, 'num' => [], 'dec' => [], 'sheet' => 'Noclegi', 'notes' => []];
    }

    /**
     * Zaznaczone noclegi, którym zmieniły się terminy lekcji stacjonarnych od zaznaczenia:
     * [['id','course_id','user_id','course_name','old'=>[first,last],'new'=>[first,last]|null (brak lekcji)]].
     */
    public static function changed(): array
    {
        self::migrate();
        $out = [];
        foreach (db_all("SELECT l.id, l.course_id, l.user_id, l.snap_first, l.snap_last, c.name AS course_name
                           FROM k30_ti_lodging l JOIN k30_ti_courses c ON c.id = l.course_id
                          WHERE l.kind IN ('before','after') AND l.snap_first != ''") as $l) {
            $sp = self::spans(date('Y-m-d', 0), (int)$l['course_id'], (int)$l['user_id']);
            $new = $sp ? [$sp[0]['first_d'], $sp[0]['last_d']] : null;
            if ($new === [$l['snap_first'], $l['snap_last']]) continue;
            if ($l['snap_last'] < date('Y-m-d') && $new === null) continue;   // szkolenie już za nami
            $out[] = ['id' => (int)$l['id'], 'course_id' => (int)$l['course_id'], 'user_id' => (int)$l['user_id'],
                      'course_name' => (string)$l['course_name'], 'old' => [$l['snap_first'], $l['snap_last']], 'new' => $new];
        }
        return $out;
    }

    /** Przyjmuje nowe terminy jako stan odniesienia (po powiadomieniu). */
    public static function acceptChange(int $lodgingId, ?array $new): void
    {
        if ($new) db()->prepare("UPDATE k30_ti_lodging SET snap_first=?, snap_last=? WHERE id=?")->execute([$new[0], $new[1], $lodgingId]);
        else      db()->prepare("DELETE FROM k30_ti_lodging WHERE id=?")->execute([$lodgingId]);
    }
}
