<?php
/**
 * modules/ti_test_accounts/logic/testProtocols.php — usuwanie protokołów miesięcznych grup testowych i demonstracyjnych.
 *
 * Grupa kwalifikuje się, gdy jej nazwa zawiera „TEST” albo „DEMO” wielkimi literami (np. „TEST — 4 protokoły”,
 * „Pracownia komputerowa (DEMO)”) — „Testowanie” czy „demonstracja” pisane zwykle nie są łapane. Usuwa protokoły
 * (k30_ti_protocols) z wpisami (k30_ti_protocol_entries); same grupy, lekcje i rozliczenia zostają. Dokumenty
 * zarejestrowane już w EZD nie są ruszane (rejestr kancelaryjny). Ekran: dydaktyk/kontaTestowe.php (administrator).
 */
final class TiTestProtocols
{
    /** Czy nazwa grupy wskazuje grupę testową / demonstracyjną. */
    public static function matches(string $name): bool
    {
        return str_contains($name, 'TEST') || str_contains($name, 'DEMO');
    }

    /** Grupy TEST/DEMO, które mają protokoły: [id, name, protocols, approved]. */
    public static function candidates(): array
    {
        $rows = db_all("SELECT c.id, c.name, COUNT(p.id) AS protocols, SUM(CASE WHEN p.status='approved' THEN 1 ELSE 0 END) AS approved
                        FROM k30_ti_courses c JOIN k30_ti_protocols p ON p.course_id = c.id
                        GROUP BY c.id, c.name ORDER BY c.name");
        return array_values(array_filter($rows, fn($r) => self::matches((string)$r['name'])));
    }

    /** Usuwa protokoły wszystkich grup TEST/DEMO. Jedna transakcja. @return array{courses:int,protocols:int,entries:int} */
    public static function purge(int $by = 0, string $byName = ''): array
    {
        $ids = array_map(fn($r) => (int)$r['id'], self::candidates());
        if (!$ids) return ['courses' => 0, 'protocols' => 0, 'entries' => 0];
        $in = implode(',', $ids);
        db()->beginTransaction();
        try {
            $e = db()->prepare("DELETE FROM k30_ti_protocol_entries WHERE protocol_id IN (SELECT id FROM k30_ti_protocols WHERE course_id IN ($in))");
            $e->execute();
            $p = db()->prepare("DELETE FROM k30_ti_protocols WHERE course_id IN ($in)");
            $p->execute();
            db()->commit();
        } catch (\Throwable $ex) {
            db()->rollBack();
            throw $ex;
        }
        $res = ['courses' => count($ids), 'protocols' => $p->rowCount(), 'entries' => $e->rowCount()];
        foreach ($ids as $cid) {
            try { db()->prepare("INSERT INTO k30_ti_course_log (course_id, action, detail, by_user_id, by_name, created_at) VALUES (?, 'protocols_purged', ?, ?, ?, datetime('now'))")
                     ->execute([$cid, 'Usunięto protokoły grupy testowej/demonstracyjnej (Konta testowe)', $by ?: null, $byName]); } catch (\Throwable $ex) {}
        }
        if (function_exists('audit_log')) { try { audit_log('ti_test_protocols.purge', $res + ['course_ids' => $ids], $by ?: null); } catch (\Throwable $ex) {} }
        return $res;
    }
}
