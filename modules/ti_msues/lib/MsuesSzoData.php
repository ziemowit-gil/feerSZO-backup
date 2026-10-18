<?php
declare(strict_types=1);

/**
 * modules/ti_msues/lib/MsuesSzoData.php — dane z SZO dla MSUES: prowadzący, usługi (grupy TI), umowy z Rejestru,
 * kwalifikacje z planisty (k30_sc_qualifications). Tylko odczyt.
 *
 * Usługa = grupa TI (k30_ti_courses); kadra usługi = prowadzący (instructor_id) + coProwadzący (k30_ti_course_coinstructors).
 * Umowy prowadzącego — jak w zakładce Formalności panelu dydaktyka: po e-mailu, identyfikatorze M365 albo
 * wskazaniu prowadzącego w umowie zlecenia (ti_instructor_user_id).
 */
final class MsuesSzoData
{
    /** rodzaj umowy => [tabela, kolumna końca, podstawa współpracy w oświadczeniu] */
    public const CONTRACT_TYPES = [
        'zlecenie'    => ['umowy_zlecenie',    'data_zakonczenia', 'zlecenie',    'Umowa zlecenia'],
        'wolontariat' => ['umowy_wolontariat', 'data_zakonczenia', 'wolontariat', 'Porozumienie wolontariackie'],
        'dzielo'      => ['umowy_dzielo',      'termin_oddania',   'inna',        'Umowa o dzieło'],
        'praca'       => ['umowy_praca',       'data_zakonczenia', 'inna',        'Umowa o pracę'],
    ];
    public const ACTIVE_STATUSES = ['podpisana', 'w realizacji'];

    public static function user(int $id): ?array
    {
        return db_one("SELECT id, name, email, microsoft_id, phone_number, role FROM users WHERE id = ?", [$id]) ?: null;
    }

    public static function course(int $id): ?array
    {
        $c = db_one("SELECT c.*, u.name AS instructor_name, st.name AS subject_name
                       FROM k30_ti_courses c
                       LEFT JOIN users u ON u.id = c.instructor_id
                       LEFT JOIN k30_ti_subject_types st ON st.id = c.subject_type_id
                      WHERE c.id = ?", [$id]);
        return $c ?: null;
    }

    public static function courseLabel(?array $c): string
    {
        if (!$c) return '';
        $n = trim((string)($c['display_name'] ?? '')) !== '' ? (string)$c['display_name'] : (string)$c['name'];
        return $n . (trim((string)($c['group_code'] ?? '')) !== '' ? ' (' . $c['group_code'] . ')' : '');
    }

    /** Usługi do audytu: aktywne grupy (bez anulowanych), najnowsze pierwsze. */
    public static function services(bool $activeOnly = true): array
    {
        return db_all("SELECT c.id, c.name, c.display_name, c.group_code, c.instructor_id, c.is_active, c.status, c.closed_at, u.name AS instructor_name
                         FROM k30_ti_courses c LEFT JOIN users u ON u.id = c.instructor_id
                        WHERE c.status <> 'cancelled'" . ($activeOnly ? " AND c.is_active = 1" : "") . "
                        ORDER BY c.is_active DESC, c.id DESC");
    }

    /** Kadra usługi: [user_id => ['id','name','email','role' => 'prowadzący'|'coProwadzący']]. */
    public static function serviceStaff(int $courseId): array
    {
        $out = [];
        $c = db_one("SELECT c.instructor_id, u.name, u.email FROM k30_ti_courses c JOIN users u ON u.id = c.instructor_id WHERE c.id = ?", [$courseId]);
        if ($c) $out[(int)$c['instructor_id']] = ['id' => (int)$c['instructor_id'], 'name' => (string)$c['name'], 'email' => (string)$c['email'], 'role' => 'prowadzący'];
        try {
            foreach (db_all("SELECT ci.user_id, u.name, u.email FROM k30_ti_course_coinstructors ci JOIN users u ON u.id = ci.user_id WHERE ci.course_id = ? ORDER BY u.name", [$courseId]) as $r) {
                $out[(int)$r['user_id']] ??= ['id' => (int)$r['user_id'], 'name' => (string)$r['name'], 'email' => (string)$r['email'], 'role' => 'coProwadzący'];
            }
        } catch (\Throwable $e) {}
        return $out;
    }

    /** Usługi, w których osoba prowadzi zajęcia (prowadzący albo coProwadzący). */
    public static function trainerServices(int $uid): array
    {
        return db_all("SELECT c.id, c.name, c.display_name, c.group_code, c.is_active FROM k30_ti_courses c
                        WHERE c.status <> 'cancelled' AND (c.instructor_id = ? OR EXISTS (SELECT 1 FROM k30_ti_course_coinstructors ci WHERE ci.course_id = c.id AND ci.user_id = ?))
                        ORDER BY c.is_active DESC, c.name", [$uid, $uid]);
    }

    /** Kadra wszystkich aktywnych usług + osoby, które mają już oświadczenia lub dokumenty MSUES. */
    public static function trainers(): array
    {
        $ids = [];
        foreach (db_all("SELECT DISTINCT instructor_id AS id FROM k30_ti_courses WHERE is_active = 1 AND status <> 'cancelled' AND instructor_id IS NOT NULL") as $r) $ids[(int)$r['id']] = true;
        try {
            foreach (db_all("SELECT DISTINCT ci.user_id AS id FROM k30_ti_course_coinstructors ci JOIN k30_ti_courses c ON c.id = ci.course_id WHERE c.is_active = 1 AND c.status <> 'cancelled'") as $r) $ids[(int)$r['id']] = true;
        } catch (\Throwable $e) {}
        foreach (db_all("SELECT DISTINCT trainer_id AS id FROM trainer_declarations UNION SELECT DISTINCT trainer_id FROM msues_staff_documents WHERE deleted_at IS NULL") as $r) $ids[(int)$r['id']] = true;
        unset($ids[0]);
        if (!$ids) return [];
        $in = implode(',', array_map('intval', array_keys($ids)));
        return db_all("SELECT id, name, email, phone_number FROM users WHERE id IN ({$in}) ORDER BY name");
    }

    /** Kandydaci do wyboru przy zakładaniu oświadczenia (konta z dostępem do panelu dydaktyka). */
    public static function trainerCandidates(): array
    {
        $known = array_column(self::trainers(), null, 'id');
        foreach (db_all("SELECT id, name, email FROM users WHERE is_active = 1 AND k30_consultant = 1 ORDER BY name") as $u) $known[(int)$u['id']] ??= $u;
        uasort($known, fn($a, $b) => strcoll((string)$a['name'], (string)$b['name']));
        return array_values($known);
    }

    /** Nazwy kolumn tabeli (przenośnie: metadane pustego zapytania). */
    private static function columns(string $table): array
    {
        static $cache = [];
        if (isset($cache[$table])) return $cache[$table];
        try {
            $st = db()->prepare("SELECT * FROM {$table} WHERE 1 = 0");
            $st->execute();
            $cols = [];
            for ($i = 0; $i < $st->columnCount(); $i++) $cols[] = (string)($st->getColumnMeta($i)['name'] ?? '');
            return $cache[$table] = $cols;
        } catch (\Throwable $e) {
            return $cache[$table] = [];
        }
    }

    /**
     * Umowy osoby z Rejestru Umów (wszystkie rodzaje), aktywne pierwsze.
     * @return list<array{type:string,id:int,label:string,numer_umowy:string,status:string,data_zawarcia:string,data_zakonczenia:string,adres:string,plik_umowy:string,active:bool,podstawa:string,uprawnienia:string,dyplom:string,przedmiot:string}>
     */
    public static function contracts(int $uid): array
    {
        $u = self::user($uid);
        if (!$u) return [];
        $out = [];
        foreach (self::CONTRACT_TYPES as $type => [$table, $endCol, $podstawa, $label]) {
            $cols = self::columns($table);
            if (!$cols) continue;
            $conds = []; $params = [];
            if (trim((string)$u['email']) !== '' && in_array('email', $cols, true)) { $conds[] = 'LOWER(email) = ?'; $params[] = mb_strtolower(trim((string)$u['email'])); }
            if (trim((string)$u['microsoft_id']) !== '' && in_array('m365_user_id', $cols, true)) { $conds[] = 'm365_user_id = ?'; $params[] = trim((string)$u['microsoft_id']); }
            if (in_array('ti_instructor_user_id', $cols, true)) { $conds[] = 'ti_instructor_user_id = ?'; $params[] = $uid; }
            if (!$conds) continue;
            $c = fn(string $n) => in_array($n, $cols, true) ? $n : "'' AS {$n}";
            try {
                $rows = db_all("SELECT id, " . implode(', ', array_map($c, ['numer_umowy', 'nr_rejestru', 'status', 'data_zawarcia', 'adres', 'plik_umowy', 'is_uprawnienia_nr', 'is_dyplom_nr', 'przedmiot_zlecenia', 'przedmiot_porozumienia', 'stanowisko']))
                    . ", " . (in_array($endCol, $cols, true) ? $endCol : "''") . " AS data_konca FROM {$table} WHERE (" . implode(' OR ', $conds) . ") ORDER BY data_zawarcia DESC", $params);
            } catch (\Throwable $e) { continue; }
            foreach ($rows as $r) {
                $out[] = [
                    'type' => $type, 'id' => (int)$r['id'], 'label' => $label,
                    'numer_umowy' => (string)($r['numer_umowy'] ?: $r['nr_rejestru']), 'status' => (string)$r['status'],
                    'data_zawarcia' => (string)$r['data_zawarcia'], 'data_zakonczenia' => (string)$r['data_konca'],
                    'adres' => (string)$r['adres'], 'plik_umowy' => (string)$r['plik_umowy'],
                    'active' => in_array((string)$r['status'], self::ACTIVE_STATUSES, true),
                    'podstawa' => $podstawa, 'uprawnienia' => (string)$r['is_uprawnienia_nr'], 'dyplom' => (string)$r['is_dyplom_nr'],
                    'przedmiot' => trim((string)($r['przedmiot_zlecenia'] ?: $r['przedmiot_porozumienia'] ?: $r['stanowisko'])),
                ];
            }
        }
        usort($out, fn($a, $b) => [$b['active'], $b['data_zawarcia']] <=> [$a['active'], $a['data_zawarcia']]);
        return $out;
    }

    /** Kwalifikacje z planisty („Limity i kwalifikacje kadry”) jako tekst. */
    public static function qualifications(int $uid): array
    {
        try {
            return array_map(fn($r) => trim((string)$r['name'] . ($r['level'] !== '' ? ' — poziom ' . $r['level'] : '') . ($r['valid_to'] !== '' ? ' (ważne do ' . $r['valid_to'] . ')' : '')),
                db_all("SELECT q.level, q.valid_to, COALESCE(st.name, tp.name, '') AS name FROM k30_sc_qualifications q
                          LEFT JOIN k30_ti_subject_types st ON q.ref_kind = 'subject' AND st.id = q.ref_id
                          LEFT JOIN k30_pl_tech_paths tp ON q.ref_kind = 'path' AND tp.id = q.ref_id
                         WHERE q.instructor_id = ? ORDER BY name", [$uid]));
        } catch (\Throwable $e) {
            return [];
        }
    }

    public static function orgName(): string
    {
        return trim((string)org_setting('org_name')) ?: (defined('ORG_NAME') ? (string)ORG_NAME : 'Fundacja');
    }

    public static function orgNameGenitive(): string
    {
        try {
            if (!function_exists('org_name_genitive')) require_once dirname(__DIR__, 3) . '/includes/org_case.php';
            $g = trim(org_name_genitive());
            if ($g !== '') return $g;
        } catch (\Throwable $e) {}
        return self::orgName();
    }

    public static function orgCity(): string
    {
        $c = trim((string)org_setting('org_miejscowosc'));
        if ($c === '') return '';
        return mb_convert_case(mb_strtolower($c), MB_CASE_TITLE);
    }
}
