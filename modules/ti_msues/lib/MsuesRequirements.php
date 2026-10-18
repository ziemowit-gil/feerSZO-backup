<?php
declare(strict_types=1);

/**
 * modules/ti_msues/lib/MsuesRequirements.php — wymagania standardu MSUES dla usługi (grupy TI) i wezwania do uzupełnienia.
 *
 * Grupa jest usługą MSUES, gdy w profilu (msues_service_profiles.is_msues) ją tak oznaczono. Wtedy evaluate() zwraca listę
 * wymagań w czterech obszarach standardu (I Usługa, II Kadra, III Infrastruktura i obsługa, IV Zarządzanie jakością),
 * każde ze stanem ok / missing / warn / na, komunikatem „co uzupełnić” i adresem ekranu, na którym się to robi.
 * Adresy są względne do katalogu karty30/ti/dydaktyk (kurs.php, planista.php i msues.php leżą obok siebie).
 *
 * Harmonogram (III): max 8 h zegarowych dziennie, min. 15 min przerwy co 2 h, przy > 6 h dziennie przerwa obiadowa min. 45 min —
 * scheduleIssues() liczy to z lekcji grupy; planista dostaje to samo jako ostrzeżenie (msuesScheduleWarnings()).
 * Dokumenty instytucji (procedury, misja, katalog, CV kadry…) to ustawienia organizacji msues_doc_* — wspólne dla wszystkich usług.
 */
require_once __DIR__ . '/MsuesComplianceService.php';
require_once __DIR__ . '/MsuesSzoData.php';

final class MsuesRequirements
{
    public const AREAS = [
        'I'   => 'I. Usługa',
        'II'  => 'II. Kadra',
        'III' => 'III. Infrastruktura i obsługa',
        'IV'  => 'IV. Zarządzanie jakością',
    ];

    /** Normy sali i wyposażenie (k30_pl_rooms.msues_norms). */
    public const ROOM_NORMS = [
        'przestrzen' => 'Przestrzeń odpowiednia do liczby uczestników',
        'swiatlo'    => 'Oświetlenie dzienne i sztuczne',
        'cisza'      => 'Cisza (brak hałasu z zewnątrz)',
        'sanitariaty'=> 'Dostęp do sanitariatów',
        'meble'      => 'Meble dla dorosłych (stoły, krzesła)',
        'tablica'    => 'Tablica lub flipchart',
        'komputer'   => 'Komputer dla prowadzącego',
        'projektor'  => 'Projektor / ekran',
        'internet'   => 'Dostęp do internetu',
        'pismienne'  => 'Materiały piśmiennicze',
    ];

    /** Dokumenty instytucji: klucz ustawienia => [obszar, nazwa, podpowiedź]. Wartość = adres publikacji albo opis, gdzie leży dokument. */
    public const ORG_DOCS = [
        'msues_doc_podsumowania' => ['I',   'Opublikowane podsumowania ewaluacji i referencje', 'adres strony z wynikami ankiet i referencjami'],
        'msues_doc_rozwoj'       => ['II',  'Ewidencja rozwoju kadry (min. 16 h rocznie na osobę)', 'gdzie prowadzona jest ewidencja szkoleń kadry'],
        'msues_doc_role'         => ['III', 'Procedura ról: opiekun merytoryczny i koordynator organizacyjny', 'adres / znak dokumentu'],
        'msues_doc_reklamacje'   => ['III', 'Procedura reklamacyjna (opublikowana)', 'adres strony z procedurą'],
        'msues_doc_zdarzenia'    => ['III', 'Procedura na wypadek nieprzewidzianych zdarzeń', 'adres strony / znak dokumentu'],
        'msues_doc_misja'        => ['IV',  'Misja, cele, wartości i metody (opublikowane)', 'adres strony'],
        'msues_doc_katalog'      => ['IV',  'Katalog szkoleń (efekty, wymagania, program, czas trwania)', 'adres katalogu'],
        'msues_doc_kadra'        => ['IV',  'Opublikowane CV / kwalifikacje trenerów', 'adres strony z kadrą'],
        'msues_doc_jakosc'       => ['IV',  'Wewnętrzna procedura zapewniania jakości', 'adres / znak dokumentu'],
        'msues_doc_praktyki'     => ['IV',  'Publiczne przykłady dobrych praktyk', 'adres strony'],
        'msues_doc_akredytacje'  => ['IV',  'Akredytacje i licencje wymagane prawem (aktualne)', 'np. wpis RIS nr …, ważne do …'],
    ];

    public const HOURS_TWO_ROLES = 16;      // powyżej — opiekun merytoryczny ≠ koordynator organizacyjny
    public const DAY_MAX_MIN     = 480;     // 8 h zegarowych dziennie
    public const CHAIN_MAX_MIN   = 120;     // co 2 h…
    public const BREAK_MIN       = 15;      // …przerwa min. 15 min
    public const LUNCH_FROM_MIN  = 360;     // przy > 6 h dziennie…
    public const LUNCH_MIN       = 45;      // …przerwa obiadowa min. 45 min
    public const MATERIALS_MAX_DAYS = 365;  // przegląd materiałów nie starszy niż rok

    private static array $profiles = [];

    // ── Profil usługi ─────────────────────────────────────────────────────────

    public static function profile(int $courseId): array
    {
        if (!array_key_exists($courseId, self::$profiles)) {
            msuesMigrate();
            $p = null;
            try { $p = db_one("SELECT * FROM msues_service_profiles WHERE course_id = ?", [$courseId]); } catch (\Throwable $e) {}
            self::$profiles[$courseId] = $p ?: ['course_id' => $courseId, 'is_msues' => 0, 'service_kind' => 'open', 'opiekun_id' => null, 'koordynator_id' => null,
                'needs_analysis_on' => '', 'needs_analysis_note' => '', 'learning_outcomes' => '', 'requirements' => '', 'materials_reviewed_on' => '',
                'materials_note' => '', 'support_form' => '', 'evaluation_method' => '', 'evaluation_report_on' => '', 'evaluation_summary' => '',
                'certificate_doc' => '', 'in_catalog' => 0, 'catalog_title' => '', 'catalog_summary' => '', 'catalog_audience' => '', 'catalog_order' => 0,
                'updated_name' => '', 'updated_at' => null];
        }
        return self::$profiles[$courseId];
    }

    public static function isMsues(int $courseId): bool
    {
        return $courseId > 0 && !empty(self::profile($courseId)['is_msues']);
    }

    public static function saveProfile(int $courseId, array $in, array $actor): void
    {
        msuesMigrate();
        $date = fn(string $k) => preg_match('/^\d{4}-\d{2}-\d{2}$/', trim((string)($in[$k] ?? ''))) ? trim((string)$in[$k]) : '';
        $txt  = fn(string $k, int $max = 4000) => mb_substr(trim((string)($in[$k] ?? '')), 0, $max);
        $uid  = fn(string $k) => ((int)($in[$k] ?? 0)) ?: null;
        $row = [
            'is_msues' => !empty($in['is_msues']) ? 1 : 0,
            'service_kind' => ($in['service_kind'] ?? '') === 'closed' ? 'closed' : 'open',
            'opiekun_id' => $uid('opiekun_id'), 'koordynator_id' => $uid('koordynator_id'),
            'needs_analysis_on' => $date('needs_analysis_on'), 'needs_analysis_note' => $txt('needs_analysis_note'),
            'learning_outcomes' => $txt('learning_outcomes', 8000), 'requirements' => $txt('requirements'),
            'materials_reviewed_on' => $date('materials_reviewed_on'), 'materials_note' => $txt('materials_note'),
            'support_form' => $txt('support_form'), 'evaluation_method' => $txt('evaluation_method'),
            'evaluation_report_on' => $date('evaluation_report_on'), 'evaluation_summary' => $txt('evaluation_summary'),
            'certificate_doc' => $txt('certificate_doc', 255),
            'in_catalog' => !empty($in['in_catalog']) ? 1 : 0, 'catalog_title' => $txt('catalog_title', 190),
            'catalog_summary' => $txt('catalog_summary'), 'catalog_audience' => $txt('catalog_audience'), 'catalog_order' => (int)($in['catalog_order'] ?? 0),
            'updated_by' => $actor['id'] ?: null, 'updated_name' => (string)$actor['name'], 'updated_at' => msuesNow(),
        ];
        $exists = db_one("SELECT course_id FROM msues_service_profiles WHERE course_id = ?", [$courseId]);
        if ($exists) {
            $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($row)));
            db()->prepare("UPDATE msues_service_profiles SET $set WHERE course_id = ?")->execute([...array_values($row), $courseId]);
        } else {
            $cols = implode(', ', array_keys($row));
            db()->prepare("INSERT INTO msues_service_profiles (course_id, $cols) VALUES (?" . str_repeat(', ?', count($row)) . ")")->execute([$courseId, ...array_values($row)]);
        }
        unset(self::$profiles[$courseId]);
        if (function_exists('ti_course_log')) { try { ti_course_log($courseId, 'msues_profile', $row['is_msues'] ? 'Profil usługi MSUES zapisany' : 'Grupa nie jest usługą MSUES', $actor['id'] ?: null, (string)$actor['name']); } catch (\Throwable $e) {} }
    }

    /** Grupy oznaczone jako usługi MSUES (bez anulowanych). */
    public static function msuesCourses(bool $activeOnly = true): array
    {
        msuesMigrate();
        try {
            return db_all("SELECT c.id, c.name, c.display_name, c.group_code, c.instructor_id, c.is_active, c.closed_at, u.name AS instructor_name
                             FROM msues_service_profiles p JOIN k30_ti_courses c ON c.id = p.course_id LEFT JOIN users u ON u.id = c.instructor_id
                            WHERE p.is_msues = 1 AND c.status <> 'cancelled'" . ($activeOnly ? " AND c.is_active = 1" : "") . " ORDER BY c.is_active DESC, c.id DESC");
        } catch (\Throwable $e) { return []; }
    }

    // ── Sale ─────────────────────────────────────────────────────────────────

    private static function ensureRoomColumns(): void
    {
        static $ok = false;
        if ($ok) return;
        $ok = true;
        try { db()->query("SELECT msues_norms FROM k30_pl_rooms LIMIT 1"); return; } catch (\Throwable $e) {}
        foreach (["msues_norms TEXT", "msues_checked_at VARCHAR(19)", "msues_checked_by VARCHAR(190) NOT NULL DEFAULT ''"] as $col) {
            try { db()->exec("ALTER TABLE k30_pl_rooms ADD COLUMN {$col}"); } catch (\Throwable $e) {}
        }
    }

    /** @return list<string> spełnione normy sali */
    public static function roomNorms(array $room): array
    {
        $n = json_decode((string)($room['msues_norms'] ?? ''), true);
        return is_array($n) ? array_values(array_intersect(array_keys(self::ROOM_NORMS), $n)) : [];
    }

    public static function rooms(): array
    {
        self::ensureRoomColumns();
        try { return db_all("SELECT * FROM k30_pl_rooms ORDER BY is_active DESC, name"); } catch (\Throwable $e) { return []; }
    }

    public static function room(int $id): ?array
    {
        self::ensureRoomColumns();
        try { return db_one("SELECT * FROM k30_pl_rooms WHERE id = ?", [$id]) ?: null; } catch (\Throwable $e) { return null; }
    }

    public static function saveRoomNorms(int $roomId, array $codes, array $actor): void
    {
        self::ensureRoomColumns();
        $codes = array_values(array_intersect(array_keys(self::ROOM_NORMS), $codes));
        db()->prepare("UPDATE k30_pl_rooms SET msues_norms = ?, msues_checked_at = ?, msues_checked_by = ? WHERE id = ?")
            ->execute([json_encode($codes), msuesNow(), (string)$actor['name'], $roomId]);
    }

    // ── Dokumenty instytucji ──────────────────────────────────────────────────

    public static function orgDocs(): array
    {
        $o = [];
        foreach (self::ORG_DOCS as $k => [$area, $label, $hint]) $o[$k] = ['area' => $area, 'label' => $label, 'hint' => $hint, 'value' => msuesSetting($k)];
        // Katalog: gdy nie wskazano innego, a wbudowany katalog publiczny ma wpisy — liczy się wbudowany
        if ($o['msues_doc_katalog']['value'] === '') {
            require_once __DIR__ . '/MsuesCatalog.php';
            if (MsuesCatalog::count()) $o['msues_doc_katalog']['value'] = MsuesCatalog::url();
        }
        return $o;
    }

    public static function saveOrgDocs(array $in): void
    {
        foreach (array_keys(self::ORG_DOCS) as $k) org_setting_set($k, mb_substr(trim((string)($in[$k] ?? '')), 0, 500));
    }

    // ── Harmonogram ─────────────────────────────────────────────────────────

    /**
     * Naruszenia reguł dnia szkoleniowego.
     * @param list<array{lesson_date:string,time_from:string,time_to:string}> $sessions
     * @return list<array{date:string,msg:string}>
     */
    public static function scheduleIssues(array $sessions): array
    {
        $days = [];
        foreach ($sessions as $s) {
            $f = ti_hm2min(substr((string)$s['time_from'], 0, 5)); $t = ti_hm2min(substr((string)$s['time_to'], 0, 5));
            if ($t > $f) $days[(string)$s['lesson_date']][] = [$f, $t];
        }
        ksort($days);
        $fmt = fn(int $m) => intdiv($m, 60) . ' h' . ($m % 60 ? ' ' . ($m % 60) . ' min' : '');
        $out = [];
        foreach ($days as $d => $ivs) {
            usort($ivs, fn($a, $b) => $a[0] <=> $b[0]);
            $sum = 0; $chainStart = null; $chainEnd = null; $maxGap = 0; $longChain = 0; $prevEnd = null;
            foreach ($ivs as [$f, $t]) {
                $sum += $t - $f;
                if ($prevEnd !== null) $maxGap = max($maxGap, $f - $prevEnd);
                if ($chainStart === null || $f - $chainEnd >= self::BREAK_MIN) { $chainStart = $f; $chainEnd = $t; }
                else $chainEnd = max($chainEnd, $t);
                $longChain = max($longChain, $chainEnd - $chainStart);
                $prevEnd = $prevEnd === null ? $t : max($prevEnd, $t);
            }
            $dl = date('d.m.Y', strtotime($d));
            if ($sum > self::DAY_MAX_MIN) $out[] = ['date' => $d, 'msg' => "$dl: {$fmt($sum)} zajęć — więcej niż 8 h zegarowych dziennie."];
            if ($longChain > self::CHAIN_MAX_MIN) $out[] = ['date' => $d, 'msg' => "$dl: {$fmt($longChain)} zajęć bez przerwy — co 2 h potrzebna przerwa min. 15 min."];
            if ($sum > self::LUNCH_FROM_MIN && $maxGap < self::LUNCH_MIN) $out[] = ['date' => $d, 'msg' => "$dl: ponad 6 h zajęć bez przerwy obiadowej min. 45 min."];
        }
        return $out;
    }

    public static function courseSessions(int $courseId): array
    {
        return db_all("SELECT id, lesson_date, time_from, time_to, room_id FROM k30_ti_sessions
                        WHERE course_id = ? AND draft_id IS NULL AND status NOT IN ('cancelled') ORDER BY lesson_date, time_from", [$courseId]);
    }

    // ── Ocena wymagań ─────────────────────────────────────────────────────────

    /**
     * @return array{course:array, profile:array, items:list<array>, missing:int, warn:int, ok:int, total:int, hours:float}|null  null = grupa nie jest usługą MSUES
     */
    public static function evaluate(int $courseId, bool $force = false): ?array
    {
        $p = self::profile($courseId);
        if (!$force && empty($p['is_msues'])) return null;
        $c = MsuesSzoData::course($courseId);
        if (!$c) return null;
        $sessions = self::courseSessions($courseId);
        $minutes = 0; $last = ''; $rooms = [];
        foreach ($sessions as $s) {
            $minutes += max(0, ti_hm2min(substr((string)$s['time_to'], 0, 5)) - ti_hm2min(substr((string)$s['time_from'], 0, 5)));
            $last = max($last, (string)$s['lesson_date']);
            if ((int)($s['room_id'] ?? 0)) $rooms[(int)$s['room_id']] = true;
        }
        $hours = round($minutes / 60, 1);
        $ended = !empty($c['closed_at']) || ($last !== '' && $last < date('Y-m-d'));
        $prof = 'msues.php?_route=' . rawurlencode('/uslugi/' . $courseId . '/profil');
        $svcUrl = 'msues.php?_route=' . rawurlencode('/uslugi/' . $courseId);
        $docsUrl = 'msues.php?_route=' . rawurlencode('/instytucja');
        $txt = fn(string $k) => trim((string)($p[$k] ?? '')) !== '';
        $items = [];
        $add = function (string $area, string $code, string $label, string $state, string $msg, string $href = '', string $cta = 'Uzupełnij') use (&$items) {
            $items[] = compact('area', 'code', 'label', 'state', 'msg', 'href', 'cta');
        };

        // I. Usługa
        $closed = ($p['service_kind'] ?? 'open') === 'closed';
        $add('I', 'analiza', 'Analiza potrzeb uczestników przed szkoleniem', $p['needs_analysis_on'] !== '' ? 'ok' : 'missing',
            $p['needs_analysis_on'] !== '' ? 'Przeprowadzona ' . date('d.m.Y', strtotime($p['needs_analysis_on'])) . '.'
                : ($closed ? 'Szkolenie zamknięte — przeprowadź analizę potrzeb całej grupy i wpisz datę oraz wnioski.'
                           : 'Szkolenie otwarte — skontaktuj się przynajmniej z częścią uczestników przed startem i wpisz datę oraz wnioski.'),
            $prof . '#needs');
        $add('I', 'efekty', 'Mierzalne cele opisane jako efekty uczenia się', $txt('learning_outcomes') ? 'ok' : 'missing',
            $txt('learning_outcomes') ? 'Opisane w profilu usługi.' : 'Wpisz efekty uczenia się (wiedza / umiejętności / kompetencje społeczne) i sposób ich sprawdzenia.', $prof . '#outcomes');
        $nCur = (int)(db_one("SELECT COUNT(*) AS n FROM k30_ti_curriculum WHERE course_id = ? AND is_active = 1", [$courseId])['n'] ?? 0);
        $add('I', 'program', 'Program szkolenia (plan nauczania)', $nCur ? 'ok' : 'missing',
            $nCur ? "Plan nauczania: $nCur pozycji." : 'Grupa nie ma planu nauczania — dodaj program (tematy, czas).', 'index.php?course=' . $courseId . '&tab=program', 'Dodaj program');
        $mr = (string)$p['materials_reviewed_on'];
        $mrOld = $mr !== '' && (time() - strtotime($mr)) > self::MATERIALS_MAX_DAYS * 86400;
        $add('I', 'materialy', 'Materiały aktualne, ze źródłami, zgodne z prawem autorskim', $mr === '' ? 'missing' : ($mrOld ? 'warn' : 'ok'),
            $mr === '' ? 'Przejrzyj materiały (aktualność, podane źródła, prawa autorskie) i wpisz datę przeglądu.'
                       : ($mrOld ? 'Ostatni przegląd ' . date('d.m.Y', strtotime($mr)) . ' — starszy niż rok, przejrzyj ponownie.' : 'Przegląd ' . date('d.m.Y', strtotime($mr)) . '.'),
            $prof . '#materials');
        $add('I', 'wsparcie', 'Forma wsparcia we wdrożeniu wiedzy po szkoleniu', $txt('support_form') ? 'ok' : 'missing',
            $txt('support_form') ? (string)mb_strimwidth((string)$p['support_form'], 0, 120, '…') : 'Opisz co najmniej jedną formę wsparcia po szkoleniu (np. konsultacje, kontakt e-mail, materiały uzupełniające).', $prof . '#support');
        $add('I', 'ankieta', 'Ankieta ewaluacyjna', $txt('evaluation_method') ? 'ok' : 'missing',
            $txt('evaluation_method') ? 'Ustalona: ' . mb_strimwidth((string)$p['evaluation_method'], 0, 100, '…') : 'Wskaż ankietę ewaluacyjną (formularz, link) dla uczestników.', $prof . '#evaluation');
        $add('I', 'raport', 'Raport z ewaluacji', $p['evaluation_report_on'] !== '' ? 'ok' : ($ended ? 'missing' : 'na'),
            $p['evaluation_report_on'] !== '' ? 'Sporządzony ' . date('d.m.Y', strtotime($p['evaluation_report_on'])) . '.'
                : ($ended ? 'Szkolenie zakończone — sporządź raport z ankiet i wpisz datę oraz wnioski.' : 'Po zakończeniu szkolenia.'), $prof . '#evaluation');

        // II. Kadra
        try {
            $r = (new MsuesComplianceService())->serviceReadiness($courseId);
            $add('II', 'kadra', 'Trenerzy: wykształcenie, doświadczenie, kompetencje (OKK-1, CV, dyplomy, umowa)', $r['ready'] ? 'ok' : 'missing',
                $r['staff'] ? "Spełnione {$r['done']} z {$r['total']} kryteriów kadry." . ($r['ready'] ? '' : ' Uzupełnij dokumenty i oświadczenia prowadzących.') : 'Grupa nie ma prowadzącego.',
                $r['staff'] ? $svcUrl : 'kurs.php?id=' . $courseId . '#edytuj', $r['staff'] ? 'Checklista kadry' : 'Przypisz prowadzącego');
        } catch (\Throwable $e) {}
        $op = (int)($p['opiekun_id'] ?? 0);
        if (!$op) {
            $add('II', 'opiekun_kwal', 'Opiekun merytoryczny: min. 3 lata doświadczenia lub 300 h szkoleń / 500 h doradztwa', 'missing', 'Najpierw wskaż opiekuna merytorycznego.', $prof . '#roles', 'Wskaż opiekuna');
        } else {
            $ok = (bool)(new MsuesComplianceService())->declarations()->approvedFor($op, $courseId);
            $u = MsuesSzoData::user($op);
            $add('II', 'opiekun_kwal', 'Opiekun merytoryczny: min. 3 lata doświadczenia lub 300 h szkoleń / 500 h doradztwa', $ok ? 'ok' : 'missing',
                ($u['name'] ?? 'Opiekun') . ($ok ? ' — doświadczenie potwierdzone zatwierdzonym OKK-1.' : ' — brak zatwierdzonego OKK-1 z doświadczeniem (lata / godziny).'),
                'msues.php?_route=' . rawurlencode('/kadra/' . $op), 'Dokumenty opiekuna');
        }

        // III. Infrastruktura i obsługa
        $ko = (int)($p['koordynator_id'] ?? 0);
        if (!$op || !$ko) {
            $add('III', 'role', 'Opiekun merytoryczny i koordynator organizacyjny', 'missing',
                'Wskaż ' . implode(' i ', array_filter([!$op ? 'opiekuna merytorycznego' : '', !$ko ? 'koordynatora organizacyjnego' : ''])) . '.', $prof . '#roles', 'Wskaż osoby');
        } elseif ($op === $ko && $hours > self::HOURS_TWO_ROLES) {
            $add('III', 'role', 'Opiekun merytoryczny i koordynator organizacyjny', 'missing',
                "Szkolenie ma {$hours} h (> 16 h) — opiekun merytoryczny i koordynator organizacyjny muszą być dwiema różnymi osobami.", $prof . '#roles', 'Zmień osoby');
        } else {
            $add('III', 'role', 'Opiekun merytoryczny i koordynator organizacyjny', 'ok', $op === $ko ? "Jedna osoba (szkolenie {$hours} h ≤ 16 h)." : 'Dwie różne osoby.', $prof . '#roles', 'Zmień');
        }
        $si = self::scheduleIssues($sessions);
        $add('III', 'harmonogram', 'Harmonogram: max 8 h dziennie, 15 min przerwy co 2 h, 45 min obiadu przy > 6 h',
            !$sessions ? 'missing' : ($si ? 'missing' : 'ok'),
            !$sessions ? 'Grupa nie ma zaplanowanych zajęć.' : ($si ? implode(' ', array_slice(array_column($si, 'msg'), 0, 4)) . (count($si) > 4 ? ' (i ' . (count($si) - 4) . ' więcej)' : '') : "Zgodny ({$hours} h zajęć)."),
            'planista.php', 'Otwórz planistę');
        if (!empty($c['is_online']) && !$rooms) {
            $add('III', 'sala', 'Sale szkoleniowe i wyposażenie zgodne z normami', 'na', 'Zajęcia online — sala nie dotyczy.');
        } elseif (!$rooms) {
            $add('III', 'sala', 'Sale szkoleniowe i wyposażenie zgodne z normami', 'missing', 'Lekcje nie mają przypisanych sal — przypisz salę w planiście.', 'planista.php', 'Przypisz salę');
        } else {
            $bad = [];
            foreach (array_keys($rooms) as $rid) {
                $room = self::room($rid);
                if (!$room) continue;
                $miss = array_diff(array_keys(self::ROOM_NORMS), self::roomNorms($room));
                if ($miss) $bad[] = [$rid, (string)$room['name'], count($miss)];
            }
            $add('III', 'sala', 'Sale szkoleniowe i wyposażenie zgodne z normami', $bad ? 'missing' : 'ok',
                $bad ? 'Niepotwierdzone normy: ' . implode(', ', array_map(fn($b) => "{$b[1]} (brak {$b[2]})", $bad)) . '.' : 'Wszystkie sale grupy spełniają normy.',
                $bad ? 'msues.php?_route=' . rawurlencode('/sale/' . $bad[0][0]) : 'msues.php?_route=' . rawurlencode('/sale'), $bad ? 'Potwierdź normy sali' : 'Sale');
        }

        // IV. Zarządzanie jakością — dokumentacja szkolenia
        $docMiss = array_filter([empty($c['track_attendance']) ? 'lista obecności (włącz „Licz frekwencję”)' : '', !$nCur ? 'program' : '', !$txt('certificate_doc') ? 'wzór zaświadczenia o ukończeniu' : '']);
        $add('IV', 'dokumentacja', 'Dokumentacja szkolenia: program, lista obecności, protokół egzaminu, zaświadczenia', $docMiss ? 'missing' : 'ok',
            $docMiss ? 'Brakuje: ' . implode(', ', $docMiss) . '.' : 'Program, lista obecności i dokument po szkoleniu ustalone.', $docMiss && !$txt('certificate_doc') ? $prof . '#docs' : 'kurs.php?id=' . $courseId . '#edytuj');

        // Dokumenty instytucji (wspólne)
        foreach (self::orgDocs() as $k => $d) {
            $add($d['area'], $k, $d['label'], $d['value'] !== '' ? 'ok' : 'missing', $d['value'] !== '' ? $d['value'] : 'Wspólne dla wszystkich usług — wskaż, gdzie dokument jest opublikowany.', $docsUrl . '#' . $k, 'Uzupełnij (instytucja)');
        }

        usort($items, fn($a, $b) => array_search($a['area'], array_keys(self::AREAS)) <=> array_search($b['area'], array_keys(self::AREAS)));
        $cnt = fn(string $s) => count(array_filter($items, fn($i) => $i['state'] === $s));
        return ['course' => $c, 'profile' => $p, 'items' => $items, 'missing' => $cnt('missing'), 'warn' => $cnt('warn'), 'ok' => $cnt('ok'),
                'total' => count(array_filter($items, fn($i) => $i['state'] !== 'na')), 'hours' => $hours, 'ended' => $ended];
    }
}

/**
 * Ostrzeżenia harmonogramu MSUES dla planisty: dzień grupy po dodaniu / przesunięciu lekcji. Pusta lista, gdy grupa nie jest usługą MSUES.
 * Nigdy nie rzuca — błąd modułu nie blokuje planowania.
 * @return list<string>
 */
function msuesScheduleWarnings(int $courseId, string $date, string $tf, string $tt, int $skipId = 0): array
{
    try {
        if (!MsuesRequirements::isMsues($courseId)) return [];
        $day = db_all("SELECT id, lesson_date, time_from, time_to FROM k30_ti_sessions
                        WHERE course_id = ? AND lesson_date = ? AND draft_id IS NULL AND status NOT IN ('cancelled')" . ($skipId ? " AND id <> ?" : ''),
                      $skipId ? [$courseId, $date, $skipId] : [$courseId, $date]);
        $day[] = ['lesson_date' => $date, 'time_from' => $tf, 'time_to' => $tt];
        return array_map(fn($i) => 'MSUES — ' . $i['msg'], MsuesRequirements::scheduleIssues($day));
    } catch (\Throwable $e) {
        error_log('msuesScheduleWarnings: ' . $e->getMessage());
        return [];
    }
}
