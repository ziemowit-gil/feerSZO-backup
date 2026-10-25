<?php
/**
 * Tylko-odczytowe JSON API portalu planu zajęć TI — źródło danych dla zewnętrznej aplikacji Node.js „Plan zajęć FEER”.
 * GET /api/v1/plan_public.php?r=<zasób>
 *
 *   r=katalog                      grupy, prowadzący, sale i flaga dyżurów (to, co w menu portalu)
 *   r=plan&id=<id>[&od=Y-m-d]      terminy grupy / prowadzącego / sali; id z r=katalog (g:… p:… s:… c:… u:…)
 *   r=dzis[&dni=2]                 terminy wszystkich widocznych grup na dziś i kolejne dni (domyślnie dziś + jutro)
 *   r=dyzury[&od=Y-m-d&do=Y-m-d]   grafik dyżurów kierownika / Zarządu (gdy opublikowany)
 *
 * Jawność — jak w portalu publicznym planPubliczny.php:
 *   • bez klucza (widok uproszczony): tylko grupy i prowadzący z flagą in_directory, prowadzący inicjałami,
 *     grupy spoza katalogu na planach prowadzącego/sali ukryte („niepubliczna”), brak linków do zajęć online;
 *   • z kluczem w nagłówku X-Plan-Key (widok pełny): wszystkie aktywne grupy i prowadzący z prawdziwymi nazwami,
 *     linki do zajęć online. Klucz ustawia się w config.local.php: define('TI_PLAN_API_KEY', '…')
 *     albo w zmiennej środowiskowej TI_PLAN_API_KEY. Pusty klucz = widok pełny przez API wyłączony.
 *   • zły klucz = 401 (nigdy cichy powrót do widoku uproszczonego).
 */
declare(strict_types=1);
define('SKIP_CONSENT_CHECK', true);

try {
    require_once dirname(__DIR__, 2) . '/config.php';
    require_once dirname(__DIR__, 2) . '/includes/db.php';
    require_once dirname(__DIR__, 2) . '/includes/functions.php';
    require_once dirname(__DIR__, 2) . '/includes/karty30.php';
    require_once dirname(__DIR__, 2) . '/modules/ti_plan_public/logic/planPublicPortal.php';
    require_once dirname(__DIR__, 2) . '/modules/ti_duty/logic/dutyManager.php';

    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('X-Robots-Tag: noindex, nofollow');

    /** Odpowiedź JSON i koniec. Widok pełny nigdy nie trafia do współdzielonych cache'y. */
    $send = function (array $data, int $code = 200) use (&$full): never {
        http_response_code($code);
        header('Cache-Control: ' . ($full ? 'no-store, private' : 'public, max-age=30'));
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    };

    $full = false;
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') $send(['error' => 'Dozwolona tylko metoda GET.'], 405);

    // ── Klucz widoku pełnego ──
    $configured = defined('TI_PLAN_API_KEY') ? (string)TI_PLAN_API_KEY : (string)(getenv('TI_PLAN_API_KEY') ?: '');
    $given = (string)($_SERVER['HTTP_X_PLAN_KEY'] ?? '');
    if ($given !== '') {
        if ($configured === '' || !hash_equals($configured, $given)) $send(['error' => 'Nieprawidłowy klucz API.'], 401);
        $full = true;
    }
    tiPlanPublicMigrate();
    tiPlanPublicFull($full);

    $rooms  = tiPlanPublicRoomsEnabled() || $full;
    $dutyOn = tiDutyPublic() || $full;
    $dirTok = tiPlanPublicDirectoryTokens();       // [course_id => token] — grupy z katalogu
    $roomName = fn(array $r): string => trim((string)$r['short_label']) !== '' ? (string)$r['short_label'] : (string)$r['name'];

    /** Platforma zajęć online z adresu spotkania (adres sam poza widokiem pełnym nie wychodzi). */
    $platform = function (array $l): string {
        if ($l['method'] !== 'online') return '';
        $host = strtolower((string)parse_url((string)$l['url'], PHP_URL_HOST));
        if ($host === '') return 'inna / brak linku';
        return match (true) {
            str_contains($host, 'zoom') => 'Zoom',
            str_contains($host, 'teams') => 'Teams',
            str_contains($host, 'meet.google') => 'Google Meet',
            default => 'inna platforma',
        };
    };

    /** Termin w kształcie API; poza widokiem pełnym: bez linków online, grupy spoza katalogu ukryte. */
    $shape = function (array $l) use ($full, $dirTok, $platform): array {
        $hidden = !$full && !isset($dirTok[$l['course_id']]) && ($l['kind'] ?? '') !== 'consult';
        $subject = $l['subject'];
        if ($hidden && $l['subject'] === $l['course']) $subject = 'Zajęcia';
        return [
            'date' => $l['date'], 'dow' => $l['dow'], 'from' => $l['tf'], 'to' => $l['tt'],
            'course' => $hidden ? 'niepubliczna' : $l['course'],
            'subject' => $subject,
            'instr' => $l['instr'], 'method' => $l['method'], 'platform' => $platform($l), 'room' => $l['room'],
            'url' => $full ? $l['url'] : '',
            'cancelled' => $l['cancelled'], 'moved' => $l['moved'], 'flag' => $l['flag'],
            'kind' => $l['kind'] ?? 'course',
        ];
    };
    $range = function (string $from, int $weeks): array {
        return [$from, date('Y-m-d', strtotime($from . ' +' . $weeks . ' weeks'))];
    };
    $validDate = fn(string $s): bool => (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) && strtotime($s) !== false;

    $r = (string)($_GET['r'] ?? 'katalog');

    /* ───────────── katalog ───────────── */
    if ($r === 'katalog') {
        $groups = []; $instr = []; $roomsOut = [];
        if ($full) {
            foreach (tiPlanPublicAllGroups() as $g) $groups[] = ['id' => 'c:' . $g['id'], 'name' => (string)$g['name']];
            foreach (tiPlanPublicInstructors() as $u) $instr[] = ['id' => 'u:' . $u['id'], 'name' => trim((string)$u['name']), 'groups' => (int)$u['n_courses']];
        } else {
            foreach (tiPlanPublicDirectoryGroups() as $g) {
                $groups[] = ['id' => 'g:' . $g['token'], 'name' => (string)$g['name'], 'instr' => (string)$g['instr'],
                             'next' => $g['next_date'] ?: null];
            }
            foreach (tiPlanPublicDirectoryInstructors() as $i) {
                $instr[] = ['id' => 'p:' . $i['token'], 'name' => (string)$i['initials'], 'groups' => $i['groups']];
            }
        }
        if ($rooms) {
            $roomsOut[] = ['id' => 's:online', 'name' => 'Zajęcia online', 'place' => 'zajęcia zdalne — według platformy i grupy', 'lessons' => tiPlanPublicOnlineCount(8)];
            foreach (tiPlanPublicDirectoryRooms(8) as $s) {
                $roomsOut[] = ['id' => 's:' . $s['id'], 'name' => $roomName($s), 'place' => trim(implode(', ', array_filter([(string)$s['building'], (string)$s['location']]))),
                               'lessons' => (int)$s['n_lessons']];
            }
        }
        $next = $dutyOn ? tiDutyCurrentOrNext() : null;
        $send(['full' => $full, 'generated' => date('c'), 'groups' => $groups, 'instructors' => $instr, 'rooms' => $roomsOut,
               'duty' => ['enabled' => $dutyOn, 'next' => $next ? ['date' => $next['date'], 'now' => $next['now'], 'from' => $next['item']['time_from'], 'to' => $next['item']['time_to']] : null]]);
    }

    /* ───────────── plan jednej pozycji ───────────── */
    if ($r === 'plan') {
        $id = (string)($_GET['id'] ?? '');
        if (!preg_match('/^([gpcusr]):([A-Za-z0-9_-]{1,64})$/', $id, $m)) $send(['error' => 'Brak lub zły parametr id.'], 400);
        [$kind, $key] = [$m[1], $m[2]];
        if (in_array($kind, ['c', 'u'], true) && !$full) $send(['error' => 'Ten widok wymaga klucza API.'], 403);
        $from = (string)($_GET['od'] ?? '');
        $from = $validDate($from) ? tiPlanPublicMonday($from) : tiPlanPublicMonday(date('Y-m-d'));
        $out = ['full' => $full, 'id' => $id, 'from' => $from, 'weeks' => 12, 'note' => ''];

        if ($kind === 'g' || $kind === 'c') {
            if ($kind === 'g') {
                $link = tiPlanPublicFindByToken($key);
                if (!$link || (int)$link['in_directory'] !== 1) $send(['error' => 'Nie ma takiej grupy.'], 404);   // tajne linki spoza katalogu nie wychodzą przez API
                $cid = (int)$link['course_id'];
                $title = tiPlanPublicGroupLabel($cid, (string)$link['course_name'], (string)($link['public_name'] ?? ''), '');
                $out['weeks'] = (int)$link['weeks'] ?: 12;
                $out['note'] = trim((string)($link['note'] ?? ''));
            } else {
                $row = db_one("SELECT id, name FROM k30_ti_courses WHERE id=? AND status NOT IN ('archived','cancelled')", [(int)$key]);
                if (!$row) $send(['error' => 'Nie ma takiej grupy.'], 404);
                $cid = (int)$row['id']; $title = (string)$row['name'];
            }
            [$a, $b] = $range($from, $out['weeks']);
            $lessons = tiPlanPublicLessons(['courses' => [$cid]], $a, $b);
            $out['kind'] = 'grupa'; $out['title'] = $title;
        } elseif ($kind === 'p' || $kind === 'u') {
            if ($kind === 'p') {
                $link = tiPlanPublicInstrFindByToken($key);
                if (!$link || (int)$link['in_directory'] !== 1) $send(['error' => 'Nie ma takiego prowadzącego.'], 404);
                $uid = (int)$link['user_id']; $out['weeks'] = (int)$link['weeks'] ?: 12;
                $out['note'] = trim((string)($link['note'] ?? ''));
                $name = (string)$link['user_name'];
            } else {
                $row = db_one("SELECT id, name FROM users WHERE id=?", [(int)$key]);
                if (!$row) $send(['error' => 'Nie ma takiego prowadzącego.'], 404);
                $uid = (int)$row['id']; $name = (string)$row['name'];
            }
            [$a, $b] = $range($from, $out['weeks']);
            $lessons = tiPlanPublicLessons(['instructor' => $uid], $a, $b);
            $out['kind'] = 'prowadzacy'; $out['title'] = tiPlanPublicPerson($name);
        } else {   // sala
            if (!$rooms) $send(['error' => 'Podgląd sal jest wyłączony.'], 404);
            $out['weeks'] = 8;
            [$a, $b] = $range($from, 8);
            if ($key === 'online') {
                $lessons = tiPlanPublicLessons(['online' => true], $a, $b);
                $title = 'Zajęcia online';
            } else {
                $room = tiPlanPublicRoom((int)$key);
                if (!$room) $send(['error' => 'Nie ma takiej sali.'], 404);
                $lessons = tiPlanPublicLessons(['room' => (int)$room['id']], $a, $b);
                $title = $roomName($room);
            }
            $out['kind'] = 'sala'; $out['title'] = $title;
        }
        $out['lessons'] = array_map($shape, $lessons);
        $send($out);
    }

    /* ───────────── dziś i jutro ───────────── */
    if ($r === 'dzis') {
        $days = max(1, min(7, (int)($_GET['dni'] ?? 2)));
        $from = date('Y-m-d');
        $to = date('Y-m-d', strtotime("+" . ($days - 1) . " days"));
        $lessons = tiPlanPublicLessons(['all' => true], $from, $to);
        // poza widokiem pełnym: tylko grupy z katalogu (zajęcia spoza katalogu i konsultacje indywidualne pomijamy)
        if (!$full) $lessons = array_values(array_filter($lessons, fn($l) => isset($dirTok[$l['course_id']])));
        $send(['full' => $full, 'from' => $from, 'to' => $to, 'now' => date('c'), 'lessons' => array_map($shape, $lessons)]);
    }

    /* ───────────── dyżury ───────────── */
    if ($r === 'dyzury') {
        if (!$dutyOn) $send(['error' => 'Grafik dyżurów nie jest opublikowany.'], 404);
        $from = (string)($_GET['od'] ?? ''); $to = (string)($_GET['do'] ?? '');
        $from = $validDate($from) ? $from : tiPlanPublicMonday(date('Y-m-d'));
        $to = $validDate($to) ? $to : date('Y-m-d', strtotime($from . ' +13 days'));
        if (strtotime($to) - strtotime($from) > 62 * 86400) $to = date('Y-m-d', strtotime($from . ' +62 days'));
        $days = [];
        foreach (tiDutyOccurrences($from, $to) as $d => $occ) {
            $items = [];
            foreach ($occ['items'] as $it) {
                $name = trim((string)$it['person_name']);
                $items[] = ['from' => (string)$it['time_from'], 'to' => (string)$it['time_to'],
                            'person' => $name === '' ? '' : ($full ? $name : tiPlanPublicStaffCode($name)),
                            'role' => trim((string)$it['role_label']), 'place' => (string)$it['place'],
                            'phone' => (string)$it['contact_phone'], 'email' => (string)$it['contact_email'], 'note' => (string)$it['note']];
            }
            $days[] = ['date' => $d, 'cancelled' => (bool)$occ['cancelled'], 'special' => (bool)$occ['special'], 'items' => $items];
        }
        $send(['full' => $full, 'from' => $from, 'to' => $to, 'days' => $days]);
    }

    $send(['error' => 'Nieznany zasób. Dozwolone: katalog, plan, dzis, dyzury.'], 404);
} catch (\Throwable $e) {
    // Globalny try/catch (wymóg projektu): zawsze JSON, bez szczegółów błędu na zewnątrz
    if (!headers_sent()) { http_response_code(500); header('Content-Type: application/json; charset=utf-8'); }
    error_log('plan_public API: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    echo json_encode(['error' => 'Błąd serwera.'], JSON_UNESCAPED_UNICODE);
}
