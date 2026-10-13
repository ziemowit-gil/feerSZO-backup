<?php
/**
 * modules/ti_protocol_numbers/logic/protocolNumbers.php — numeracja protokołów zajęć TI: PRT/<kod grupy>/<MM>/<RR>.
 *
 * Kod grupy = k30_ti_courses.group_code (5 cyfr; gdy pusty — G<id kursu>), MM i RR — miesiąc i rok protokołu
 * (miesięczny: year_month; za okres nauczania: miesiąc końca okresu, z dopiskiem „-O”, żeby nie zderzył się z miesięcznym).
 * Numer zapisujemy przy protokole (k30_ti_protocols.prot_no) przy pierwszym odczycie — nie zmienia się, gdy później
 * zmieni się kod grupy. Dla miesięcy bez protokołu numer można „podejrzeć” (preview) — prowadzący wpisuje go wtedy
 * na dokumentach papierowych, a protokół założony później dostanie ten sam numer.
 */
final class TiProtocolNumbers
{
    public static function code(int $courseId): string
    {
        $gc = trim((string)(db_one("SELECT group_code FROM k30_ti_courses WHERE id=?", [$courseId])['group_code'] ?? ''));
        return $gc !== '' ? $gc : 'G' . $courseId;
    }

    /** Numer dla grupy i miesiąca RRRR-MM (bez zapisu). */
    public static function preview(int $courseId, string $ym, bool $period = false): string
    {
        if (!preg_match('/^(\d{4})-(\d{2})$/', $ym, $m)) return '';
        return 'PRT/' . self::code($courseId) . '/' . $m[2] . '/' . substr($m[1], 2) . ($period ? '-O' : '');
    }

    /** Numer protokołu (wiersz z ti_protocol_get) — zapisany albo nadany teraz. */
    public static function forProtocol(array $prot): string
    {
        $no = trim((string)($prot['prot_no'] ?? ''));
        if ($no !== '' || empty($prot['id'])) return $no;
        $ym = (string)($prot['year_month'] ?? '');
        $period = $ym === '';
        if ($period) $ym = substr((string)($prot['date_to'] ?? ''), 0, 7) ?: substr((string)($prot['created_at'] ?? date('Y-m-d')), 0, 7);
        $base = self::preview((int)$prot['course_id'], $ym, $period);
        if ($base === '') return '';
        $no = $base; $i = 2;
        while (db_one("SELECT 1 FROM k30_ti_protocols WHERE prot_no=? AND id<>?", [$no, (int)$prot['id']])) $no = $base . '-' . $i++;
        try { db()->prepare("UPDATE k30_ti_protocols SET prot_no=? WHERE id=? AND COALESCE(prot_no,'')=''")->execute([$no, (int)$prot['id']]); }
        catch (\Throwable $e) { /* kolumna jeszcze bez migracji — numer i tak zwracamy */ }
        return $no;
    }

    /** Numer dla grupy i miesiąca: zapisany numer istniejącego protokołu miesięcznego albo podgląd. */
    public static function forCourseMonth(int $courseId, string $ym): string
    {
        try {
            $p = db_one("SELECT id, course_id, year_month, prot_no, created_at FROM k30_ti_protocols WHERE course_id=? AND year_month=?", [$courseId, $ym]);
            if ($p) return self::forProtocol($p);
        } catch (\Throwable $e) {}
        return self::preview($courseId, $ym);
    }
}
