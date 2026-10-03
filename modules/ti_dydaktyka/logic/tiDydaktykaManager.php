<?php
/**
 * modules/ti_dydaktyka/logic/tiDydaktykaManager.php — Dydaktyka i postępy kursantów TI.
 *
 * „Karta kursanta” prowadzącego: profil tyflo, sprzęt i licencje, statusy opanowania modułów
 * (punktów planu kursu), dziennik lekcji z obecnością, notatki o postępach i trudnościach oraz
 * materiały przypisane jednemu kursantowi. Lekcje, obecność i plan nauczania pochodzą z istniejących
 * tabel TI (nic nie jest duplikowane); nowe są tylko cztery tabele z tiDydaktykaSchema.php.
 *
 * Dostęp: kierownik (staff) widzi wszystkich kursantów, prowadzący — kursantów zapisanych do
 * jego grup (główny lub współprowadzący). Dane o technologii wspomagającej, sprzęcie i trudnościach
 * mogą być danymi o stanie zdrowia (art. 9 RODO): kursant i opiekun małoletniego widzą wyłącznie
 * notatki oznaczone „dla kursanta” oraz materiały przypisane do niego. Każdy zapis i otwarcie
 * karty trafia do audit_logs (z id z sesji panelu — k30_log_access() nie zna użytkownika panelu).
 *
 * Ekran: karty30/ti/dydaktyk/kartaKursanta.php (panel dydaktyka, dyd_token). Panel kursanta i rodzica
 * czytają tylko studentNotes() / studentMaterials().
 */
require_once dirname(__DIR__, 3) . '/modules/audit_logs/logic/audit_logs.php';
require_once dirname(__DIR__, 3) . '/modules/ti_licencje/logic/tiLicencjeManager.php';
require_once __DIR__ . '/tiDydaktykaSchema.php';

final class TiDydaktykaManager
{
    public const NOTE_KINDS = ['progress' => 'Postęp', 'difficulty' => 'Trudność', 'goal' => 'Cel', 'other' => 'Inne'];
    public const VISIBILITY = ['staff' => 'Wewnętrzna (prowadzący i kierownik)', 'student' => 'Dla kursanta i opiekuna'];
    public const MODULE_STATUSES = ['not_started' => 'Nie rozpoczęto', 'in_progress' => 'W trakcie', 'mastered' => 'Opanowany', 'needs_revision' => 'Do powtórzenia'];
    public const EQUIPMENT_KINDS = ['laptop' => 'Laptop', 'smartfon' => 'Smartfon', 'monitor_brajlowski' => 'Monitor brajlowski',
                                    'notatnik_brajlowski' => 'Notatnik brajlowski', 'lupa' => 'Lupa elektroniczna', 'inny' => 'Inny'];
    public const OWNERSHIP = ['own' => 'Własny kursanta', 'loan' => 'Wypożyczony od fundacji'];
    public const ATTENDANCE = ['present' => 'Obecny', 'excused' => 'Usprawiedliwiony', 'absent' => 'Nieobecny', 'planned' => 'Zaplanowana'];

    /** Podpowiedzi pól profilu tyflo (zapisywane jako listy tekstowe; wartości spoza podpowiedzi też są dozwolone). */
    public const PROFILE_TOOLS   = ['czytnik ekranu', 'lupa ekranowa', 'powiększalnik sprzętowy', 'monitor brajlowski', 'synteza mowy', 'rozpoznawanie mowy'];
    public const PROFILE_FORMATS = ['audio' => 'Audio', 'braille' => 'Braille', 'duza_czcionka' => 'Duża czcionka',
                                    'dostepny_pdf' => 'Dostępny PDF', 'docx' => 'Word (DOCX)', 'tekst_prosty' => 'Tekst prosty'];
    public const PROFILE_TECH    = ['NVDA', 'JAWS', 'SuperNova', 'VoiceOver', 'TalkBack', 'ZoomText', 'Narrator'];

    /** Czas lekcji w minutach (jak SC_REP_DUR w raportach planisty — nie ładujemy modułu planisty dla jednej stałej). */
    private const DURATION_SQL = "COALESCE(NULLIF(s.duration_min,0), CAST((strftime('%s','2000-01-01 '||s.time_to)-strftime('%s','2000-01-01 '||s.time_from))/60 AS INTEGER))";
    private const HELD = "('held','individual_change','remote_material')";

    private static function ready(): void { ti_dydaktyka_migrate(); }
    public static function today(): string { return date('Y-m-d'); }

    // ── Dostęp ───────────────────────────────────────────────────────────────

    /**
     * Czy użytkownik panelu może otworzyć kartę tego kursanta: kierownik — każdego, prowadzący — kursanta
     * zapisanego do grupy, którą prowadzi (główny lub współprowadzący).
     *
     * @param array{id:int,staff:bool,name?:string,real_id?:int} $actor
     */
    public static function canAccess(array $actor, int $clientId): bool
    {
        self::ready();
        if ($clientId <= 0 || !db_one("SELECT 1 FROM k30_clients WHERE id = ?", [$clientId])) return false;
        if (!empty($actor['staff'])) return true;
        return (bool)db_one(
            "SELECT 1 FROM k30_ti_enrollments e JOIN k30_ti_courses c ON c.id = e.course_id
              WHERE e.client_id = ? AND (c.instructor_id = ? OR EXISTS
                    (SELECT 1 FROM k30_ti_course_coinstructors ci WHERE ci.course_id = c.id AND ci.user_id = ?)) LIMIT 1",
            [$clientId, (int)$actor['id'], (int)$actor['id']]
        );
    }

    /** Dane nagłówka karty: kursant + czy małoletni (opiekun). */
    public static function client(int $clientId): ?array
    {
        self::ready();
        return db_one(
            "SELECT cl.id, cl.name, cl.email, cl.phone, a.is_minor, a.guardian_name
               FROM k30_clients cl LEFT JOIN k30_ti_student_accounts a ON a.client_id = cl.id
              WHERE cl.id = ? ORDER BY a.id LIMIT 1",
            [$clientId]
        );
    }

    /**
     * Grupy kursanta widoczne dla użytkownika (kierownik: wszystkie; prowadzący: własne). Aktywne zapisy najpierw.
     * @return list<array<string,mixed>>
     */
    public static function courses(array $actor, int $clientId): array
    {
        self::ready();
        $sql = "SELECT c.id, c.name, c.group_code, c.class_type, c.status AS course_status, e.status AS enroll_status,
                       e.start_date, COALESCE(u.name, '') AS instructor_name
                  FROM k30_ti_enrollments e JOIN k30_ti_courses c ON c.id = e.course_id
                  LEFT JOIN users u ON u.id = c.instructor_id
                 WHERE e.client_id = ?";
        $p = [$clientId];
        if (empty($actor['staff'])) {
            $sql .= " AND (c.instructor_id = ? OR EXISTS (SELECT 1 FROM k30_ti_course_coinstructors ci WHERE ci.course_id = c.id AND ci.user_id = ?))";
            array_push($p, (int)$actor['id'], (int)$actor['id']);
        }
        return db_all($sql . " ORDER BY (e.status = 'active') DESC, c.name COLLATE NOCASE", $p);
    }

    // ── Dziennik lekcji i obecność (odczyt z istniejących tabel) ─────────────

    /** Status obecności z pól k30_ti_attendance: attended → Obecny; cancelled → Usprawiedliwiony; odbyta bez obecności → Nieobecny. */
    public static function attendanceStatus(array $r): string
    {
        if (!empty($r['attended'])) return 'present';
        if (!empty($r['cancelled'])) return 'excused';
        if (in_array((string)($r['status'] ?? ''), ['held', 'individual_change', 'remote_material'], true)) return 'absent';
        return 'planned';
    }

    /**
     * Ostatnie lekcje kursanta w widocznych grupach: data, grupa, temat, obecność, czas, uwaga do lekcji.
     * @return list<array<string,mixed>>
     */
    public static function timeline(array $actor, int $clientId, int $limit = 30): array
    {
        $ids = array_map(fn($c) => (int)$c['id'], self::courses($actor, $clientId));
        if (!$ids) return [];
        $in = implode(',', array_fill(0, count($ids), '?'));
        $rows = db_all(
            "SELECT s.id, s.lesson_date, s.time_from, s.time_to, s.status, s.topic, s.course_id, c.name AS course_name,
                    a.attended, a.cancelled, a.no_show, a.ind_notes, " . self::DURATION_SQL . " AS dur
               FROM k30_ti_attendance a
               JOIN k30_ti_sessions s ON s.id = a.session_id
               JOIN k30_ti_courses c  ON c.id = s.course_id
              WHERE a.client_id = ? AND s.course_id IN ($in) AND s.status <> 'cancelled' AND s.draft_id IS NULL
              ORDER BY s.lesson_date DESC, s.time_from DESC, s.id DESC LIMIT " . max(1, min(200, $limit)),
            array_merge([$clientId], $ids)
        );
        foreach ($rows as &$r) $r['att'] = self::attendanceStatus($r);
        unset($r);
        return $rows;
    }

    /** Podsumowanie obecności z odbytych lekcji: obecny / usprawiedliwiony / nieobecny + godziny obecności. */
    public static function attendanceSummary(array $actor, int $clientId): array
    {
        $out = ['present' => 0, 'excused' => 0, 'absent' => 0, 'present_min' => 0, 'held' => 0];
        $ids = array_map(fn($c) => (int)$c['id'], self::courses($actor, $clientId));
        if (!$ids) return $out;
        $in = implode(',', array_fill(0, count($ids), '?'));
        $r = db_one(
            "SELECT COUNT(*) AS held,
                    COALESCE(SUM(CASE WHEN a.attended = 1 THEN 1 ELSE 0 END), 0) AS present,
                    COALESCE(SUM(CASE WHEN a.attended = 0 AND a.cancelled = 1 THEN 1 ELSE 0 END), 0) AS excused,
                    COALESCE(SUM(CASE WHEN a.attended = 0 AND a.cancelled = 0 THEN 1 ELSE 0 END), 0) AS absent,
                    COALESCE(SUM(CASE WHEN a.attended = 1 THEN " . self::DURATION_SQL . " ELSE 0 END), 0) AS present_min
               FROM k30_ti_attendance a JOIN k30_ti_sessions s ON s.id = a.session_id
              WHERE a.client_id = ? AND s.course_id IN ($in) AND s.status IN " . self::HELD . " AND s.draft_id IS NULL",
            array_merge([$clientId], $ids)
        ) ?: [];
        foreach ($out as $k => $_) $out[$k] = (int)($r[$k] ?? 0);
        return $out;
    }

    // ── Profil tyflo (warstwa hybrid: domena TI) ─────────────────────────────

    /** 'local' = profil edytowalny tutaj; 'http' = domena TI działa w osobnym serwisie (tylko odczyt). */
    public static function profileMode(): string
    {
        return class_exists(\FeerSzo\Hybrid\Config\DriverConfig::class)
            && \FeerSzo\Hybrid\Config\DriverConfig::isHttp(\FeerSzo\Hybrid\Config\DriverConfig::tiDriver()) ? 'http' : 'local';
    }

    /** @return array{tools:list<string>,formats:list<string>,tech:list<string>,notes:string,updated_at:?string,error:string} */
    public static function profile(int $clientId): array
    {
        self::ready();
        $out = ['tools' => [], 'formats' => [], 'tech' => [], 'notes' => '', 'updated_at' => null, 'error' => ''];
        if (!class_exists(\FeerSzo\Hybrid\ClientFactory::class)) { $out['error'] = 'Warstwa profilu dostępności (hybrid/) nie jest dostępna.'; return $out; }
        try {
            $p = \FeerSzo\Hybrid\ClientFactory::tyfloProfileClient()->getProfile($clientId);
            $out['tools'] = array_values(array_map('strval', $p->assistiveTools));
            $out['formats'] = array_values(array_map('strval', $p->preferredMaterialFormats));
            $out['tech'] = array_values(array_map('strval', $p->tyfloTechnologies));
            $out['notes'] = $p->notes;
            if (self::profileMode() === 'local') {
                $r = db_one("SELECT updated_at FROM k30_ti_accessibility_profiles WHERE client_id = ?", [$clientId]);
                $out['updated_at'] = $r['updated_at'] ?? null;
            }
        } catch (\Throwable $e) {
            $out['error'] = 'Nie udało się pobrać profilu: ' . $e->getMessage();
        }
        return $out;
    }

    /** Zapis profilu tyflo (tylko tryb lokalny). Pola list: checkboxy `<pole>[]` + „inne” po przecinku (`<pole>_other`). */
    public static function saveProfile(array $actor, int $clientId, array $in): array
    {
        self::ready();
        if (!self::canAccess($actor, $clientId)) return ['ok' => false, 'errors' => ['Brak dostępu do tego kursanta.']];
        if (self::profileMode() !== 'local') return ['ok' => false, 'errors' => ['Profil prowadzi osobny serwis TI (tryb http) — edycja po jego stronie.']];
        $errors = [];
        $lists = [];
        foreach (['tools' => 'Narzędzia asystujące', 'formats' => 'Preferowane formaty materiałów', 'tech' => 'Technologie tyflo'] as $k => $label) {
            $items = [];
            foreach ((array)($in[$k] ?? []) as $v) $items[] = trim((string)$v);
            foreach (preg_split('/[,;\n]+/', (string)($in[$k . '_other'] ?? '')) ?: [] as $v) $items[] = trim($v);
            $items = array_values(array_unique(array_filter($items, fn($v) => $v !== '')));
            foreach ($items as $v) if (mb_strlen($v) > 60) { $errors[] = 'Pole „' . $label . '”: wartość „' . mb_substr($v, 0, 20) . '…” jest za długa (maks. 60 znaków).'; break; }
            if (count($items) > 20) $errors[] = 'Pole „' . $label . '”: maksymalnie 20 pozycji.';
            $lists[$k] = $items;
        }
        $notes = trim((string)($in['notes'] ?? ''));
        if (mb_strlen($notes) > 1000) $errors[] = 'Notatka do profilu jest za długa (maks. 1000 znaków).';
        if ($errors) return ['ok' => false, 'errors' => $errors];

        self::atomic(function () use ($actor, $clientId, $lists, $notes): void {
            (new \FeerSzo\Hybrid\Ti\Service\AccessibilityProfileService())->saveProfile(new \FeerSzo\Hybrid\Dto\AccessibilityProfileDto(
                clientId: $clientId, assistiveTools: $lists['tools'], preferredMaterialFormats: $lists['formats'],
                tyfloTechnologies: $lists['tech'], notes: $notes));
            self::audit($actor, 'profile_saved', ['client_id' => $clientId, 'tools' => count($lists['tools']), 'formats' => count($lists['formats']), 'tech' => count($lists['tech'])]);
        });
        return ['ok' => true, 'errors' => []];
    }

    // ── Sprzęt kursanta i licencje ───────────────────────────────────────────

    /** @return list<array<string,mixed>> */
    public static function equipment(int $clientId): array
    {
        self::ready();
        return db_all(
            "SELECT q.*, l.name AS license_name, u.name AS created_by_name
               FROM k30_ti_client_equipment q
               LEFT JOIN k30_ti_client_licenses cl ON cl.id = q.license_assignment_id
               LEFT JOIN k30_ti_licenses l ON l.id = cl.license_id
               LEFT JOIN users u ON u.id = q.created_by
              WHERE q.client_id = ?
              ORDER BY (q.returned_at IS NULL OR q.returned_at = '') DESC, q.created_at DESC, q.id DESC",
            [$clientId]
        );
    }

    /** Licencje kursanta (z modułu Licencje) do wyświetlenia na karcie; status liczony jak w module licencji. @return list<array<string,mixed>> */
    public static function licenses(int $clientId): array
    {
        $rows = TiLicencjeManager::search(['client_id' => $clientId, 'status' => ''], 1, 100)['rows'];
        foreach ($rows as &$r) $r['status_key'] = TiLicencjeManager::statusOf($r);
        unset($r);
        return $rows;
    }

    /** Nieunieważnione licencje kursanta do powiązania ze sprzętem. */
    public static function licenseOptions(int $clientId): array
    {
        self::ready();
        return db_all(
            "SELECT cl.id, l.name, cl.expires_at FROM k30_ti_client_licenses cl JOIN k30_ti_licenses l ON l.id = cl.license_id
              WHERE cl.client_id = ? AND cl.status <> 'revoked' ORDER BY l.name COLLATE NOCASE", [$clientId]);
    }

    public static function saveEquipment(array $actor, int $clientId, array $in, int $id = 0): array
    {
        self::ready();
        if (!self::canAccess($actor, $clientId)) return ['ok' => false, 'errors' => ['Brak dostępu do tego kursanta.']];
        $errors = [];
        $kind = (string)($in['kind'] ?? '');
        if (!isset(self::EQUIPMENT_KINDS[$kind])) $errors[] = 'Wybierz rodzaj sprzętu.';
        $ownership = (string)($in['ownership'] ?? 'own');
        if (!isset(self::OWNERSHIP[$ownership])) { $errors[] = 'Wybierz, czyj to sprzęt.'; $ownership = 'own'; }
        $model = trim((string)($in['model'] ?? '')); $serial = trim((string)($in['serial'] ?? '')); $note = trim((string)($in['note'] ?? ''));
        if (mb_strlen($model) > 190)  $errors[] = 'Model jest za długi (maks. 190 znaków).';
        if (mb_strlen($serial) > 190) $errors[] = 'Numer seryjny jest za długi (maks. 190 znaków).';
        if (mb_strlen($note) > 500)   $errors[] = 'Uwagi są za długie (maks. 500 znaków).';
        $lent = self::parseDate($in['lent_at'] ?? ''); $ret = self::parseDate($in['returned_at'] ?? '');
        if ($lent === false) { $errors[] = 'Data wypożyczenia ma niepoprawny format (RRRR-MM-DD).'; $lent = ''; }
        if ($ret === false)  { $errors[] = 'Data zwrotu ma niepoprawny format (RRRR-MM-DD).'; $ret = ''; }
        if ($lent !== '' && $ret !== '' && $ret < $lent) $errors[] = 'Data zwrotu nie może być wcześniejsza niż data wypożyczenia.';
        $lic = (int)($in['license_assignment_id'] ?? 0);
        if ($lic > 0 && !db_one("SELECT 1 FROM k30_ti_client_licenses WHERE id = ? AND client_id = ?", [$lic, $clientId])) {
            $errors[] = 'Wybrana licencja nie należy do tego kursanta.'; $lic = 0;
        }
        if ($id > 0 && !db_one("SELECT 1 FROM k30_ti_client_equipment WHERE id = ? AND client_id = ?", [$id, $clientId])) $errors[] = 'Nie znaleziono tego sprzętu.';
        if ($errors) return ['ok' => false, 'errors' => $errors];

        $row = ['kind' => $kind, 'model' => $model, 'serial' => $serial, 'ownership' => $ownership, 'lent_at' => $lent ?: null,
                'returned_at' => $ret ?: null, 'license_assignment_id' => $lic ?: null, 'note' => $note];
        $newId = self::atomic(function () use ($actor, $clientId, $row, $id): int {
            if ($id > 0) {
                $set = []; $p = [];
                foreach ($row as $k => $v) { $set[] = "$k = ?"; $p[] = $v; }
                $p[] = $id; $p[] = $clientId;
                db_exec("UPDATE k30_ti_client_equipment SET " . implode(', ', $set) . " WHERE id = ? AND client_id = ?", $p);
                $eid = $id;
            } else {
                $eid = db_insert('k30_ti_client_equipment', $row + ['client_id' => $clientId, 'created_by' => (int)$actor['id'], 'created_at' => date('Y-m-d H:i:s')]);
            }
            self::audit($actor, 'equipment_saved', ['client_id' => $clientId, 'equipment_id' => $eid, 'kind' => $row['kind'], 'ownership' => $row['ownership']]);
            return $eid;
        });
        return ['ok' => true, 'errors' => [], 'id' => $newId];
    }

    /** Oznacza sprzęt jako zwrócony (dziś). */
    public static function returnEquipment(array $actor, int $clientId, int $id): array
    {
        self::ready();
        if (!self::canAccess($actor, $clientId)) return ['ok' => false, 'errors' => ['Brak dostępu do tego kursanta.']];
        $q = db_one("SELECT id, returned_at FROM k30_ti_client_equipment WHERE id = ? AND client_id = ?", [$id, $clientId]);
        if (!$q) return ['ok' => false, 'errors' => ['Nie znaleziono tego sprzętu.']];
        if (!empty($q['returned_at'])) return ['ok' => false, 'errors' => ['Ten sprzęt jest już oznaczony jako zwrócony.']];
        self::atomic(function () use ($actor, $clientId, $id): void {
            db_exec("UPDATE k30_ti_client_equipment SET returned_at = ? WHERE id = ? AND client_id = ?", [self::today(), $id, $clientId]);
            self::audit($actor, 'equipment_returned', ['client_id' => $clientId, 'equipment_id' => $id]);
        });
        return ['ok' => true, 'errors' => []];
    }

    public static function deleteEquipment(array $actor, int $clientId, int $id): array
    {
        self::ready();
        if (!self::canAccess($actor, $clientId)) return ['ok' => false, 'errors' => ['Brak dostępu do tego kursanta.']];
        $q = db_one("SELECT id, created_by FROM k30_ti_client_equipment WHERE id = ? AND client_id = ?", [$id, $clientId]);
        if (!$q) return ['ok' => false, 'errors' => ['Nie znaleziono tego sprzętu.']];
        if (empty($actor['staff']) && (int)$q['created_by'] !== (int)$actor['id']) return ['ok' => false, 'errors' => ['Sprzęt dodany przez kogoś innego może usunąć tylko kierownik.']];
        self::atomic(function () use ($actor, $clientId, $id): void {
            db_exec("DELETE FROM k30_ti_client_equipment WHERE id = ? AND client_id = ?", [$id, $clientId]);
            self::audit($actor, 'equipment_deleted', ['client_id' => $clientId, 'equipment_id' => $id]);
        });
        return ['ok' => true, 'errors' => []];
    }

    // ── Notatki o postępach i trudnościach ───────────────────────────────────

    /** @return list<array<string,mixed>> notatki kursanta (najnowsze pierwsze); $visibility = null → wszystkie */
    public static function notes(int $clientId, ?string $visibility = null): array
    {
        self::ready();
        $sql = "SELECT n.*, u.name AS author, c.name AS course_name, s.lesson_date AS lesson_date
                  FROM k30_ti_progress_notes n
                  LEFT JOIN users u ON u.id = n.created_by
                  LEFT JOIN k30_ti_courses c ON c.id = n.course_id
                  LEFT JOIN k30_ti_sessions s ON s.id = n.session_id
                 WHERE n.client_id = ?";
        $p = [$clientId];
        if ($visibility !== null) { $sql .= " AND n.visibility = ?"; $p[] = $visibility; }
        return db_all($sql . " ORDER BY n.created_at DESC, n.id DESC LIMIT 300", $p);
    }

    /** Notatki oznaczone „dla kursanta” — to jedyne, co widzi kursant i opiekun małoletniego. */
    public static function studentNotes(int $clientId): array { return self::notes($clientId, 'student'); }

    /** Notatkę edytuje jej autor albo kierownik. */
    public static function canEditNote(array $actor, array $note): bool
    {
        return !empty($actor['staff']) || (int)$note['created_by'] === (int)$actor['id'];
    }

    public static function saveNote(array $actor, int $clientId, array $in, int $id = 0): array
    {
        self::ready();
        if (!self::canAccess($actor, $clientId)) return ['ok' => false, 'errors' => ['Brak dostępu do tego kursanta.']];
        $errors = [];
        $kind = (string)($in['kind'] ?? 'progress');
        if (!isset(self::NOTE_KINDS[$kind])) { $errors[] = 'Wybierz rodzaj notatki.'; $kind = 'progress'; }
        $vis = (string)($in['visibility'] ?? 'staff');
        if (!isset(self::VISIBILITY[$vis])) { $errors[] = 'Wybierz widoczność notatki.'; $vis = 'staff'; }
        $topic = trim((string)($in['topic'] ?? '')); $body = trim((string)($in['body'] ?? ''));
        if (mb_strlen($topic) > 190) $errors[] = 'Temat jest za długi (maks. 190 znaków).';
        if ($body === '') $errors[] = 'Wpisz treść notatki.';
        elseif (mb_strlen($body) > 4000) $errors[] = 'Treść notatki jest za długa (maks. 4000 znaków).';

        $courseIds = array_map(fn($c) => (int)$c['id'], self::courses($actor, $clientId));
        $courseId = (int)($in['course_id'] ?? 0); $sessionId = (int)($in['session_id'] ?? 0);
        if ($sessionId > 0) {
            $s = db_one("SELECT id, course_id FROM k30_ti_sessions WHERE id = ?", [$sessionId]);
            if (!$s || !in_array((int)$s['course_id'], $courseIds, true)) { $errors[] = 'Wybrana lekcja nie należy do grup tego kursanta.'; $sessionId = 0; }
            else $courseId = (int)$s['course_id'];
        }
        if ($courseId > 0 && !in_array($courseId, $courseIds, true)) { $errors[] = 'Wybrana grupa nie należy do tego kursanta.'; $courseId = 0; }
        $cur = null;
        if ($id > 0) {
            $cur = db_one("SELECT * FROM k30_ti_progress_notes WHERE id = ? AND client_id = ?", [$id, $clientId]);
            if (!$cur) $errors[] = 'Nie znaleziono tej notatki.';
            elseif (!self::canEditNote($actor, $cur)) $errors[] = 'Notatkę może edytować tylko jej autor albo kierownik.';
        }
        if ($errors) return ['ok' => false, 'errors' => $errors];

        $now = date('Y-m-d H:i:s');
        $newId = self::atomic(function () use ($actor, $clientId, $id, $kind, $vis, $topic, $body, $courseId, $sessionId, $now): int {
            if ($id > 0) {
                db_exec("UPDATE k30_ti_progress_notes SET kind = ?, visibility = ?, topic = ?, body = ?, course_id = ?, session_id = ?, updated_at = ?
                          WHERE id = ? AND client_id = ?", [$kind, $vis, $topic, $body, $courseId ?: null, $sessionId ?: null, $now, $id, $clientId]);
                $nid = $id;
            } else {
                $nid = db_insert('k30_ti_progress_notes', ['client_id' => $clientId, 'course_id' => $courseId ?: null, 'session_id' => $sessionId ?: null,
                    'kind' => $kind, 'topic' => $topic, 'body' => $body, 'visibility' => $vis, 'created_by' => (int)$actor['id'], 'created_at' => $now, 'updated_at' => $now]);
            }
            self::audit($actor, 'note_saved', ['client_id' => $clientId, 'note_id' => $nid, 'kind' => $kind, 'visibility' => $vis, 'edit' => $id > 0]);
            return $nid;
        });
        return ['ok' => true, 'errors' => [], 'id' => $newId];
    }

    public static function deleteNote(array $actor, int $clientId, int $id): array
    {
        self::ready();
        if (!self::canAccess($actor, $clientId)) return ['ok' => false, 'errors' => ['Brak dostępu do tego kursanta.']];
        $n = db_one("SELECT * FROM k30_ti_progress_notes WHERE id = ? AND client_id = ?", [$id, $clientId]);
        if (!$n) return ['ok' => false, 'errors' => ['Nie znaleziono tej notatki.']];
        if (!self::canEditNote($actor, $n)) return ['ok' => false, 'errors' => ['Notatkę może usunąć tylko jej autor albo kierownik.']];
        self::atomic(function () use ($actor, $clientId, $id, $n): void {
            db_exec("DELETE FROM k30_ti_progress_notes WHERE id = ? AND client_id = ?", [$id, $clientId]);
            self::audit($actor, 'note_deleted', ['client_id' => $clientId, 'note_id' => $id, 'kind' => $n['kind'], 'visibility' => $n['visibility']]);
        });
        return ['ok' => true, 'errors' => []];
    }

    // ── Statusy opanowania modułów (punktów planu kursu) ─────────────────────

    /**
     * Punkty planu kursu ze statusem kursanta (domyślnie „Nie rozpoczęto”).
     * @return list<array<string,mixed>>
     */
    public static function modules(int $clientId, int $courseId): array
    {
        self::ready();
        return db_all(
            "SELECT cu.id, cu.section, cu.position, cu.title, cu.description, cu.est_minutes,
                    COALESCE(sm.status, 'not_started') AS status, sm.status_date, COALESCE(sm.note, '') AS note, sm.updated_at
               FROM k30_ti_curriculum cu
               LEFT JOIN k30_ti_student_modules sm ON sm.curriculum_id = cu.id AND sm.client_id = ?
              WHERE cu.course_id = ? AND cu.is_active = 1
              ORDER BY cu.section COLLATE NOCASE, cu.position, cu.id",
            [$clientId, $courseId]
        );
    }

    /** @return array{total:int,mastered:int,in_progress:int,needs_revision:int,pct:int} */
    public static function moduleSummary(array $modules): array
    {
        $s = ['total' => count($modules), 'mastered' => 0, 'in_progress' => 0, 'needs_revision' => 0, 'pct' => 0];
        foreach ($modules as $m) if (isset($s[$m['status']])) $s[$m['status']]++;
        $s['pct'] = $s['total'] ? (int)round($s['mastered'] * 100 / $s['total']) : 0;
        return $s;
    }

    /**
     * Zapis statusów całej grupy punktów planu: $rows[curriculum_id] = ['status' => …, 'note' => …].
     * Zmieniony status dostaje datę dzisiejszą; wiersze „Nie rozpoczęto” bez notatki nie są zapisywane.
     */
    public static function saveModules(array $actor, int $clientId, int $courseId, array $rows): array
    {
        self::ready();
        if (!self::canAccess($actor, $clientId)) return ['ok' => false, 'errors' => ['Brak dostępu do tego kursanta.'], 'changed' => 0];
        $courseIds = array_map(fn($c) => (int)$c['id'], self::courses($actor, $clientId));
        if (!in_array($courseId, $courseIds, true)) return ['ok' => false, 'errors' => ['Ta grupa nie należy do tego kursanta.'], 'changed' => 0];
        $current = [];
        foreach (self::modules($clientId, $courseId) as $m) $current[(int)$m['id']] = $m;

        $errors = []; $todo = [];
        foreach ($rows as $cid => $r) {
            $cid = (int)$cid;
            if (!isset($current[$cid])) continue;                                   // nie z tej grupy — pomijamy po cichu
            $status = (string)($r['status'] ?? 'not_started');
            $note = trim((string)($r['note'] ?? ''));
            if (!isset(self::MODULE_STATUSES[$status])) { $errors[] = 'Niepoprawny status modułu „' . $current[$cid]['title'] . '”.'; continue; }
            if (mb_strlen($note) > 300) { $errors[] = 'Notatka przy module „' . $current[$cid]['title'] . '” jest za długa (maks. 300 znaków).'; continue; }
            $old = $current[$cid];
            if ($old['status'] === $status && (string)$old['note'] === $note) continue;   // bez zmian
            $todo[$cid] = [$status, $note, $old['status'] !== $status];
        }
        if ($errors) return ['ok' => false, 'errors' => $errors, 'changed' => 0];

        $now = date('Y-m-d H:i:s'); $today = self::today();
        self::atomic(function () use ($actor, $clientId, $courseId, $todo, $now, $today): void {
            foreach ($todo as $cid => [$status, $note, $statusChanged]) {
                $had = db_one("SELECT id, status_date FROM k30_ti_student_modules WHERE client_id = ? AND curriculum_id = ?", [$clientId, $cid]);
                $date = $statusChanged || !$had ? $today : ($had['status_date'] ?: $today);
                if ($had) {
                    db_exec("UPDATE k30_ti_student_modules SET status = ?, status_date = ?, note = ?, updated_by = ?, updated_at = ? WHERE id = ?",
                        [$status, $date, $note, (int)$actor['id'], $now, (int)$had['id']]);
                } else {
                    db_insert('k30_ti_student_modules', ['client_id' => $clientId, 'curriculum_id' => $cid, 'status' => $status, 'status_date' => $date,
                        'note' => $note, 'updated_by' => (int)$actor['id'], 'updated_at' => $now]);
                }
            }
            if ($todo) self::audit($actor, 'modules_saved', ['client_id' => $clientId, 'course_id' => $courseId, 'changed' => array_keys($todo)]);
        });
        return ['ok' => true, 'errors' => [], 'changed' => count($todo)];
    }

    // ── Materiały przypisane kursantowi ──────────────────────────────────────

    /** Materiały przypisane kursantowi (z autorem przypisania i grupą materiału). */
    public static function assignedMaterials(int $clientId): array
    {
        self::ready();
        return db_all(
            "SELECT a.*, m.title, m.type, m.url, m.attach_name, m.attach_path, m.description, m.is_active, m.course_id, m.open_at, m.close_at,
                    c.name AS course_name, u.name AS assigned_by_name
               FROM k30_ti_material_assignments a
               JOIN k30_ti_materials m ON m.id = a.material_id
               LEFT JOIN k30_ti_courses c ON c.id = m.course_id
               LEFT JOIN users u ON u.id = a.assigned_by
              WHERE a.client_id = ? ORDER BY a.assigned_at DESC, a.id DESC", [$clientId]);
    }

    /** Materiały, które użytkownik może przypisać (kierownik: wszystkie aktywne; prowadzący: z własnych grup), bez już przypisanych. */
    public static function assignableMaterials(array $actor, int $clientId): array
    {
        self::ready();
        $sql = "SELECT m.id, m.title, m.type, m.attach_name, m.url, c.name AS course_name
                  FROM k30_ti_materials m JOIN k30_ti_courses c ON c.id = m.course_id
                 WHERE m.is_active = 1 AND m.id NOT IN (SELECT material_id FROM k30_ti_material_assignments WHERE client_id = ?)";
        $p = [$clientId];
        if (empty($actor['staff'])) {
            $sql .= " AND (c.instructor_id = ? OR EXISTS (SELECT 1 FROM k30_ti_course_coinstructors ci WHERE ci.course_id = c.id AND ci.user_id = ?))";
            array_push($p, (int)$actor['id'], (int)$actor['id']);
        }
        return db_all($sql . " ORDER BY c.name COLLATE NOCASE, m.title COLLATE NOCASE LIMIT 400", $p);
    }

    /** Przypisuje wybrane materiały kursantowi; już przypisane pomija. @return array{ok:bool,errors:list<string>,added:int,skipped:int} */
    public static function assignMaterials(array $actor, int $clientId, array $materialIds, string $note = '', string $due = ''): array
    {
        self::ready();
        if (!self::canAccess($actor, $clientId)) return ['ok' => false, 'errors' => ['Brak dostępu do tego kursanta.'], 'added' => 0, 'skipped' => 0];
        $errors = [];
        $ids = array_values(array_unique(array_filter(array_map('intval', $materialIds), fn($v) => $v > 0)));
        if (!$ids) $errors[] = 'Zaznacz co najmniej jeden materiał.';
        $note = trim($note);
        if (mb_strlen($note) > 500) $errors[] = 'Uwaga do materiału jest za długa (maks. 500 znaków).';
        $dueDate = self::parseDate($due);
        if ($dueDate === false) { $errors[] = 'Termin ma niepoprawny format (RRRR-MM-DD).'; $dueDate = ''; }
        $todo = []; $skipped = 0;
        foreach ($ids as $mid) {
            if (db_one("SELECT 1 FROM k30_ti_material_assignments WHERE material_id = ? AND client_id = ?", [$mid, $clientId])) { $skipped++; continue; }
            $sql = "SELECT m.id FROM k30_ti_materials m JOIN k30_ti_courses c ON c.id = m.course_id WHERE m.id = ? AND m.is_active = 1";
            $p = [$mid];
            if (empty($actor['staff'])) {                       // prowadzący przypisuje tylko materiały ze swoich grup
                $sql .= " AND (c.instructor_id = ? OR EXISTS (SELECT 1 FROM k30_ti_course_coinstructors ci WHERE ci.course_id = c.id AND ci.user_id = ?))";
                array_push($p, (int)$actor['id'], (int)$actor['id']);
            }
            if (db_one($sql, $p)) $todo[] = $mid;
            else $errors[] = 'Materiał nr ' . $mid . ' nie jest dostępny do przypisania.';
        }
        if ($errors) return ['ok' => false, 'errors' => $errors, 'added' => 0, 'skipped' => $skipped];

        $now = date('Y-m-d H:i:s');
        self::atomic(function () use ($actor, $clientId, $todo, $note, $dueDate, $now): void {
            foreach ($todo as $mid) {
                db_insert('k30_ti_material_assignments', ['material_id' => $mid, 'client_id' => $clientId, 'note' => $note,
                    'due_at' => $dueDate ?: null, 'assigned_by' => (int)$actor['id'], 'assigned_at' => $now]);
            }
            if ($todo) self::audit($actor, 'material_assigned', ['client_id' => $clientId, 'material_ids' => $todo]);
        });
        return ['ok' => true, 'errors' => [], 'added' => count($todo), 'skipped' => $skipped];
    }

    public static function unassignMaterial(array $actor, int $clientId, int $assignmentId): array
    {
        self::ready();
        if (!self::canAccess($actor, $clientId)) return ['ok' => false, 'errors' => ['Brak dostępu do tego kursanta.']];
        $a = db_one("SELECT id, material_id, assigned_by FROM k30_ti_material_assignments WHERE id = ? AND client_id = ?", [$assignmentId, $clientId]);
        if (!$a) return ['ok' => false, 'errors' => ['Nie znaleziono tego przypisania.']];
        if (empty($actor['staff']) && (int)$a['assigned_by'] !== (int)$actor['id']) return ['ok' => false, 'errors' => ['Przypisanie dodane przez kogoś innego może cofnąć tylko kierownik.']];
        self::atomic(function () use ($actor, $clientId, $a): void {
            db_exec("DELETE FROM k30_ti_material_assignments WHERE id = ? AND client_id = ?", [(int)$a['id'], $clientId]);
            self::audit($actor, 'material_unassigned', ['client_id' => $clientId, 'material_id' => (int)$a['material_id']]);
        });
        return ['ok' => true, 'errors' => []];
    }

    // ── Odczyty dla panelu kursanta i rodzica ────────────────────────────────

    /** Materiały przypisane kursantowi i wciąż aktywne (panel kursanta). */
    public static function studentMaterials(int $clientId): array
    {
        return array_values(array_filter(self::assignedMaterials($clientId), fn($m) => !empty($m['is_active'])));
    }

    /** Czy kursant może pobrać/otworzyć ten materiał dzięki przypisaniu (pobieranie pliku w material_file.php). */
    public static function studentMayOpenMaterial(int $clientId, int $materialId): bool
    {
        self::ready();
        return (bool)db_one(
            "SELECT 1 FROM k30_ti_material_assignments a JOIN k30_ti_materials m ON m.id = a.material_id
              WHERE a.client_id = ? AND a.material_id = ? AND m.is_active = 1", [$clientId, $materialId]);
    }

    /** Zapamiętuje pierwsze otwarcie przypisanego materiału. */
    public static function markOpened(int $clientId, int $materialId): void
    {
        self::ready();
        db_exec("UPDATE k30_ti_material_assignments SET opened_at = ? WHERE client_id = ? AND material_id = ? AND opened_at IS NULL",
            [date('Y-m-d H:i:s'), $clientId, $materialId]);
    }

    // ── Audyt i pomocnicze ───────────────────────────────────────────────────

    /** Otwarcie karty (dane wrażliwe) — ślad w audycie; błąd zapisu nie blokuje podglądu. */
    public static function logView(array $actor, int $clientId): void
    {
        try { self::audit($actor, 'card_viewed', ['client_id' => $clientId]); } catch (\Throwable $e) { /* podgląd działa dalej */ }
    }

    private static function audit(array $actor, string $event, array $details): void
    {
        if (!empty($actor['real_id']) && (int)$actor['real_id'] !== (int)$actor['id']) $details['real_user_id'] = (int)$actor['real_id'];   // wcielenie w prowadzącego
        audit_log('ti_dydaktyka.' . $event, $details, (int)$actor['id']);
    }

    /** '' = puste, false = niepoprawny format, inaczej data Y-m-d. */
    private static function parseDate(mixed $v): string|false
    {
        $s = trim((string)$v);
        if ($s === '') return '';
        $d = DateTime::createFromFormat('!Y-m-d', $s);
        return ($d && $d->format('Y-m-d') === $s) ? $s : false;
    }

    /** Operacja + wpis audytu w jednej transakcji (audit_log() dołącza do otwartej transakcji). */
    private static function atomic(callable $fn): mixed
    {
        $pdo = db();
        if ($pdo->inTransaction()) return $fn();
        $pdo->beginTransaction();
        try {
            $r = $fn();
            $pdo->commit();
            return $r;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}
