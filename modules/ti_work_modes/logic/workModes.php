<?php
/**
 * modules/ti_work_modes/logic/workModes.php — uprawnienia do trybów pracy panelu dydaktyka per osoba:
 * „Szkolenia” (kursy, grupy, lekcje, szkolenia krótkie SzK — klucz 'kursy') i „Konsultacje” (karty konsultacji,
 * rezerwacje terminów — klucz 'konsultacje').
 *
 * Tabela k30_ti_work_modes: wiersz = ustawienie jawne (kierownik w „Zespół i role”); brak wiersza = automatycznie:
 *   Szkolenia — rola panelu albo własne grupy; Konsultacje — doradca TI (users.k30_consultant).
 * Kierownik (is_staff) ma zawsze oba tryby — tego nie da się wyłączyć.
 */
final class TiWorkModes
{
    public const MODES = ['kursy' => 'Szkolenia', 'konsultacje' => 'Konsultacje'];

    public static function migrate(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        if (function_exists('szo_schema_current') && szo_schema_current('ti_work_modes_migrate', __FILE__)) return;
        db()->exec("CREATE TABLE IF NOT EXISTS k30_ti_work_modes (
            user_id     INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
            kursy       INTEGER NOT NULL DEFAULT 0,
            konsultacje INTEGER NOT NULL DEFAULT 0,
            updated_by  INTEGER REFERENCES users(id) ON DELETE SET NULL,
            updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
    }

    /** Ustawienie jawne albo null (= automatycznie). */
    public static function explicit(int $uid): ?array
    {
        self::migrate();
        $r = db_one("SELECT kursy, konsultacje FROM k30_ti_work_modes WHERE user_id=?", [$uid]);
        return $r ? ['kursy' => (int)$r['kursy'] === 1, 'konsultacje' => (int)$r['konsultacje'] === 1] : null;
    }

    /** Uprawnienia wyliczane automatycznie (bez wiersza). */
    public static function auto(int $uid, string $panelRole = ''): array
    {
        $panelRole = $panelRole !== '' ? $panelRole : (function_exists('ti_panel_role') ? ti_panel_role($uid) : '');
        return [
            'kursy'       => $panelRole !== '' || (bool)k30_ti_instructor_courses($uid, false),
            'konsultacje' => !empty(db_one("SELECT k30_consultant FROM users WHERE id=?", [$uid])['k30_consultant']),
        ];
    }

    /** Dostępne tryby (lista kluczy w kolejności MODES). */
    public static function available(int $uid, bool $staff, string $panelRole = ''): array
    {
        if ($staff) return array_keys(self::MODES);
        $m = self::explicit($uid) ?? self::auto($uid, $panelRole);
        return array_values(array_filter(array_keys(self::MODES), fn($k) => !empty($m[$k])));
    }

    /** Zapis ustawienia jawnego; $reset = true usuwa wiersz (powrót do automatycznych). */
    public static function set(int $uid, bool $kursy, bool $konsultacje, int $by, bool $reset = false): void
    {
        self::migrate();
        if ($reset) { db()->prepare("DELETE FROM k30_ti_work_modes WHERE user_id=?")->execute([$uid]); return; }
        db()->prepare("INSERT INTO k30_ti_work_modes (user_id, kursy, konsultacje, updated_by, updated_at) VALUES (?,?,?,?,datetime('now'))
                       ON CONFLICT(user_id) DO UPDATE SET kursy=excluded.kursy, konsultacje=excluded.konsultacje,
                                                         updated_by=excluded.updated_by, updated_at=excluded.updated_at")
            ->execute([$uid, $kursy ? 1 : 0, $konsultacje ? 1 : 0, $by ?: null]);
    }

    /**
     * Osoby z dostępem do panelu do ustawienia trybów: role panelu, doradcy TI, prowadzący grup oraz osoby z wierszem.
     * @return list<array{id:int,name:string,email:string,panel_role:string,consultant:bool,courses:int,explicit:?array,auto:array,staff:bool}>
     */
    public static function people(): array
    {
        self::migrate();
        if (function_exists('ti_panel_roles_migrate')) ti_panel_roles_migrate();
        $rows = db_all(
            "SELECT u.id, u.name, u.email, u.role, COALESCE(u.k30_consultant,0) AS k30_consultant, pr.role AS panel_role,
                    (SELECT COUNT(*) FROM k30_ti_courses c WHERE c.instructor_id = u.id AND c.is_active = 1) AS courses
               FROM users u
               LEFT JOIN k30_ti_panel_roles pr ON pr.user_id = u.id
              WHERE u.is_active = 1 AND (pr.user_id IS NOT NULL OR COALESCE(u.k30_consultant,0) = 1
                    OR EXISTS (SELECT 1 FROM k30_ti_courses c WHERE c.instructor_id = u.id AND c.is_active = 1)
                    OR EXISTS (SELECT 1 FROM k30_ti_work_modes w WHERE w.user_id = u.id))
              ORDER BY u.name COLLATE NOCASE");
        $out = [];
        foreach ($rows as $r) {
            $uid = (int)$r['id'];
            $pr = (string)($r['panel_role'] ?? '');
            $out[] = [
                'id' => $uid, 'name' => (string)$r['name'], 'email' => (string)$r['email'], 'panel_role' => $pr,
                'consultant' => (int)$r['k30_consultant'] === 1, 'courses' => (int)$r['courses'],
                'explicit' => self::explicit($uid), 'auto' => self::auto($uid, $pr),
                'staff' => (string)$r['role'] === 'admin' || in_array($pr, ['kierownik', 'zastepca'], true),
            ];
        }
        return $out;
    }
}
