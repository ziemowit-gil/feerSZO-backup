<?php
/**
 * modules/ti_plan_public/logic/planPublicPortal.php — Portal planu zajęć TI (wzór: plan lekcji online szkoły).
 *
 * Trzy rodzaje stron publicznych (bez logowania), wszystkie w planPubliczny.php:
 *   - portal /plan/            — katalog „Grupy” i „Prowadzący” (TYLKO wpisy z flagą in_directory),
 *   - plan grupy ?g=<token>    — dotychczasowy tajny link grupy (k30_ti_plan_public_links),
 *   - plan prowadzącego ?p=<token> — tajny link prowadzącego (k30_ti_plan_public_instr).
 *
 * Widok = konkretny TYDZIEŃ z datami (TI planuje terminami, nie stałym planem szkolnym):
 * wiersze to godziny zajęć w danym tygodniu, kolumny dni; karta lekcji jak we wzorze.
 *
 * Prywatność: prowadzący zawsze inicjałami; na planie prowadzącego z katalogu nazwy grup
 * spoza katalogu są ukryte („grupa”), bo mogą zawierać nazwiska uczestników.
 */
require_once __DIR__ . '/planPublicManager.php';

/* ───────────────────────── Widok pełny (hasło kierownika) ───────────────────────── */

/** Tryb widoku pełnego w bieżącym żądaniu: prawdziwe nazwy grup, pełne nazwiska, linki do spotkań, wszystkie grupy. */
function tiPlanPublicFull(?bool $set = null): bool {
    static $on = false;
    if ($set !== null) $on = $set;
    return $on;
}

/** Prowadzący na stronie: pełne imię i nazwisko w widoku pełnym, inaczej kod „JKo” (tiPlanPublicStaffCode). */
function tiPlanPublicPerson(string $name): string {
    return tiPlanPublicFull() ? trim($name) : tiPlanPublicStaffCode($name);
}

/** Hash hasła widoku pełnego (pusty = funkcja wyłączona). */
function tiPlanPublicFullHash(): string {
    return (string)org_setting('ti_plan_public_full_hash');
}

function tiPlanPublicFullSetPassword(string $pw): void {
    org_setting_set('ti_plan_public_full_hash', $pw === '' ? '' : password_hash($pw, PASSWORD_DEFAULT));
}

/** Sprawdza hasło; globalna blokada po 10 błędach w 15 min (bez zapisywania IP odwiedzających). */
function tiPlanPublicFullCheck(string $pw): array {
    $hash = tiPlanPublicFullHash();
    if ($hash === '') return [false, 'Widok pełny jest wyłączony.'];
    $st = json_decode((string)org_setting('ti_plan_public_full_fail'), true) ?: ['n' => 0, 'since' => 0];
    if (time() - (int)$st['since'] > 900) $st = ['n' => 0, 'since' => time()];
    if ((int)$st['n'] >= 10) return [false, 'Za dużo nieudanych prób — spróbuj za kilkanaście minut.'];
    if (password_verify($pw, $hash)) { org_setting_set('ti_plan_public_full_fail', ''); return [true, '']; }
    $st['n'] = (int)$st['n'] + 1;
    org_setting_set('ti_plan_public_full_fail', json_encode($st));
    usleep(800000);
    return [false, 'Nieprawidłowe hasło.'];
}

/** Wszystkie aktywne grupy (widok pełny). */
function tiPlanPublicAllGroups(): array {
    return db_all("SELECT c.id, c.name, st.name AS subject_name, u.name AS instr_name FROM k30_ti_courses c
                     LEFT JOIN k30_ti_subject_types st ON st.id=c.subject_type_id LEFT JOIN users u ON u.id=c.instructor_id
                    WHERE c.status NOT IN ('archived','cancelled') ORDER BY c.name COLLATE NOCASE");
}

/* ───────────────────────── RODO: nazwy grup ───────────────────────── */

/** Zapis porównawczy: małe litery, bez polskich znaków. */
function tiPlanPublicFold(string $s): string {
    $s = mb_strtolower($s);
    return strtr($s, ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z']);
}

/**
 * Czy nazwa grupy zawiera imię lub nazwisko któregoś z jej uczestników (zapisy w dowolnym statusie)?
 * Grupy indywidualne bywają nazywane nazwiskiem kursanta — takiej nazwy nie wolno pokazać publicznie.
 * Porównanie całych słów (min. 3 litery), bez polskich znaków.
 */
function tiPlanPublicNameHasPerson(int $courseId, string $courseName): bool {
    static $cache = [];
    if (isset($cache[$courseId])) return $cache[$courseId];
    $words = preg_split('/[^\p{L}]+/u', tiPlanPublicFold($courseName), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $words = array_flip($words);
    $hit = false;
    foreach (db_all("SELECT cl.name FROM k30_ti_enrollments e JOIN k30_clients cl ON cl.id=e.client_id WHERE e.course_id=?", [$courseId]) as $r) {
        foreach (preg_split('/[^\p{L}]+/u', tiPlanPublicFold((string)$r['name']), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $w) {
            if (mb_strlen($w) >= 3 && isset($words[$w])) { $hit = true; break 2; }
        }
    }
    return $cache[$courseId] = $hit;
}

/** Imiona z plików modules/ti_plan_public/data/imiona_*.txt (jedno na linię, # = komentarz) — zapis porównawczy => true. */
function tiPlanPublicFirstNames(): array {
    static $set = null;
    if ($set !== null) return $set;
    $set = [];
    foreach (glob(dirname(__DIR__) . '/data/imiona_*.txt') ?: [] as $f) {
        foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || mb_strlen($line) < 3) continue;
            $set[tiPlanPublicFold($line)] = true;
        }
    }
    // Słowa z wykazu, które w nazwach grup/kursów zwykle NIE są imionami (angielskie / techniczne / miesiące)
    foreach (['can', 'long', 'man', 'son', 'neo', 'dev', 'ram', 'bin', 'pal', 'das', 'dat', 'sing', 'made', 'gene', 'mark', 'ray', 'guy', 'said',
              'wolf', 'earl', 'nick', 'max', 'lee', 'van', 'jay', 'jack', 'ross', 'dean', 'kim', 'nur', 'won', 'min', 'han', 'lam', 'tan', 'sai',
              'sali', 'sala', 'sale', 'salo', 'grupa', 'kurs',
              'maj', 'luty', 'marzec', 'kwiecien', 'czerwiec', 'lipiec', 'sierpien', 'wrzesien', 'pazdziernik', 'listopad', 'grudzien', 'styczen'] as $w) unset($set[$w]);
    return $set;
}

/** Nazwiska bez typowej końcówki z plików data/nazwiska_*.txt — zapis porównawczy => true. */
function tiPlanPublicSurnames(): array {
    static $set = null;
    if ($set !== null) return $set;
    $set = [];
    foreach (glob(dirname(__DIR__) . '/data/nazwiska_*.txt') ?: [] as $f) {
        foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '' && $line[0] !== '#' && mb_strlen($line) >= 3) $set[tiPlanPublicFold($line)] = true;
        }
    }
    return $set;
}

/** Przymiotniki z końcówkami nazwiskowymi (-ski/-cki…), które NIE są nazwiskami: języki, regiony, miasta. */
function tiPlanPublicSurnameExceptions(): array {
    static $ex = null;
    if ($ex !== null) return $ex;
    $base = ['angielski', 'polski', 'niemiecki', 'hiszpanski', 'wloski', 'francuski', 'rosyjski', 'ukrainski', 'bialoruski', 'czeski', 'slowacki',
             'litewski', 'lotewski', 'szwedzki', 'norweski', 'dunski', 'finski', 'portugalski', 'japonski', 'chinski', 'koreanski', 'arabski', 'turecki',
             'grecki', 'lacinski', 'wegierski', 'rumunski', 'chorwacki', 'serbski', 'brajlowski', 'migowski', 'europejski', 'miejski', 'wiejski',
             'slaski', 'mazowiecki', 'malopolski', 'pomorski', 'wielkopolski', 'podkarpacki', 'lubelski', 'lodzki', 'swietokrzyski', 'kujawski',
             'lubuski', 'opolski', 'podlaski', 'dolnoslaski', 'zachodniopomorski', 'warminski', 'mazurski', 'krakowski', 'warszawski', 'gdanski',
             'poznanski', 'wroclawski', 'szczecinski', 'bydgoski', 'torunski', 'katowicki', 'sadecki', 'rzeszowski', 'bialostocki', 'kielecki',
             'olsztynski', 'gorski', 'morski', 'swiecki', 'akademicki', 'techniczny', 'praktyczny', 'specjalistyczny', 'indywidualny'];
    $ex = [];
    foreach ($base as $w) { $ex[$w] = true; $ex[preg_replace('/i$/', 'a', $w)] = true; }   // polski → polska
    return $ex;
}

/** Odmienione imię (zapis porównawczy): Annę/Anny/Annie/Anną → Anna; Jana/Janowi/Janem/Janie/Janu → Jan; Marka → Marek. */
function tiPlanPublicInflectedName(string $f, array $first): bool {
    if (mb_strlen($f) < 4) return false;
    foreach (['ie' => 'a', 'e' => 'a', 'y' => 'a', 'i' => 'a', 'a' => ''] as $end => $base) {   // żeńskie: Annie/Annę(e po fold.)/Anny/Ani…
        if (str_ends_with($f, $end) && isset($first[mb_substr($f, 0, -mb_strlen($end)) . $base])) return true;
    }
    foreach (['owi', 'em', 'ie', 'u', 'a'] as $end) {                                        // męskie: Janowi/Janem/Janie/Janu/Jana
        $stem = mb_substr($f, 0, -mb_strlen($end));
        if (str_ends_with($f, $end) && (isset($first[$stem]) || (str_ends_with($stem, 'k') && isset($first[mb_substr($stem, 0, -1) . 'ek'])))) return true;   // Marka → Marek
    }
    return false;
}

/** Odmienione nazwisko z listy data/nazwiska_*.txt: Nowakiem/Nowaka/Nowakowi → Nowak; Mazurem → Mazur. */
function tiPlanPublicInflectedSurname(string $f, array $sur): bool {
    if (mb_strlen($f) < 4) return false;
    foreach (['iem', 'owi', 'em', 'ie', 'ej', 'a', 'u', 'y'] as $end) {
        if (str_ends_with($f, $end) && isset($sur[mb_substr($f, 0, -mb_strlen($end))])) return true;
    }
    return false;
}

/**
 * RODO: w tekście publicznym skraca do inicjałów słowa pisane wielką literą, które są imionami
 * (wykaz PESEL w data/imiona_*.txt), są na liście nazwisk (data/nazwiska_*.txt) albo wyglądają na nazwiska
 * (-icki/-icka, -cki/-cka, -dzki/-dzka, -ski/-ska, -wicz, -czyk, -czak, -iak). W widoku pełnym — bez zmian.
 * „Kurs Jan Kowalski — gr. 2” → „Kurs J. K. — gr. 2”.
 */
function tiPlanPublicAnonymize(string $text): string {
    if ($text === '') return $text;
    if (tiPlanPublicFull()) return $text;   // widok pełny (hasło kierownika)
    $first = tiPlanPublicFirstNames();
    $sur   = tiPlanPublicSurnames();
    $ex    = tiPlanPublicSurnameExceptions();
    return (string)preg_replace_callback('/\p{Lu}[\p{L}]*(?:-\p{Lu}[\p{L}]*)*/u', function ($m) use ($first, $sur, $ex) {
        $parts = explode('-', $m[0]);
        $hit = false;
        foreach ($parts as $w) {
            $f = tiPlanPublicFold($w);
            if ((mb_strlen($w) >= 3 && (isset($first[$f]) || isset($sur[$f]) || tiPlanPublicInflectedName($f, $first)))
                || (mb_strlen($w) >= 3 && tiPlanPublicInflectedSurname($f, $sur))
                || (mb_strlen($w) >= 5 && !isset($ex[preg_replace('/(sk|ck|dzk)(iego|iej|iemu|imi|ich|im|a|i)$/u', '$1i', $f)])
                    && preg_match('/((sk|ck|dzk)(i|a|iego|iej|iemu|imi|ich|im)|wicz(a|owi|em|u)?|(czyk|czak|iak)(a|owi|iem|u)?)$/u', $f))) { $hit = true; break; }
        }
        // nazwisko dwuczłonowe: gdy którykolwiek człon pasuje — inicjały wszystkich („Nowak-Wiśniewska” → „N.-W.”)
        return $hit ? implode('-', array_map(fn($w) => mb_strtoupper(mb_substr($w, 0, 1)) . '.', $parts)) : $m[0];
    }, $text);
}

/**
 * Nazwa grupy na stronach publicznych: „nazwa publiczna” z planLinki.php, a gdy pusta — nazwa grupy,
 * chyba że zawiera dane uczestnika: wtedy zastępcza „<przedmiot> — grupa nr <id>”. Zawsze przez tiPlanPublicAnonymize().
 */
function tiPlanPublicGroupLabel(int $courseId, string $courseName, string $publicName = '', string $subject = ''): string {
    if (tiPlanPublicFull()) return $courseName;
    if (trim($publicName) !== '') return tiPlanPublicAnonymize(trim($publicName));
    if (!tiPlanPublicNameHasPerson($courseId, $courseName)) return tiPlanPublicAnonymize($courseName);
    return (trim($subject) !== '' ? tiPlanPublicAnonymize(trim($subject)) . ' — ' : '') . 'grupa nr ' . $courseId;
}

/* ───────────────────────── Linki prowadzących ───────────────────────── */

function tiPlanPublicInstrGet(int $userId): ?array {
    tiPlanPublicMigrate();
    return db_one("SELECT * FROM k30_ti_plan_public_instr WHERE user_id=?", [$userId]) ?: null;
}

/** [user_id => wiersz linku] */
function tiPlanPublicInstrMap(): array {
    tiPlanPublicMigrate();
    $out = [];
    foreach (db_all("SELECT * FROM k30_ti_plan_public_instr") as $r) $out[(int)$r['user_id']] = $r;
    return $out;
}

function tiPlanPublicInstrEnable(int $userId, int $by): array {
    tiPlanPublicMigrate();
    if (tiPlanPublicInstrGet($userId)) {
        db()->prepare("UPDATE k30_ti_plan_public_instr SET is_enabled=1, updated_at=datetime('now') WHERE user_id=?")->execute([$userId]);
    } else {
        db()->prepare("INSERT INTO k30_ti_plan_public_instr(user_id, token, is_enabled, created_by) VALUES(?,?,1,?)")
            ->execute([$userId, tiPlanPublicToken(), $by]);
    }
    return tiPlanPublicInstrGet($userId);
}

function tiPlanPublicInstrDisable(int $userId): void {
    tiPlanPublicMigrate();
    db()->prepare("UPDATE k30_ti_plan_public_instr SET is_enabled=0, updated_at=datetime('now') WHERE user_id=?")->execute([$userId]);
}

function tiPlanPublicInstrRegenerate(int $userId, int $by): array {
    tiPlanPublicMigrate();
    if (!tiPlanPublicInstrGet($userId)) return tiPlanPublicInstrEnable($userId, $by);
    db()->prepare("UPDATE k30_ti_plan_public_instr SET token=?, views=0, last_view_at=NULL, updated_at=datetime('now') WHERE user_id=?")
        ->execute([tiPlanPublicToken(), $userId]);
    return tiPlanPublicInstrGet($userId);
}

function tiPlanPublicInstrUpdateOptions(int $userId, array $in): void {
    tiPlanPublicMigrate();
    $weeks = (int)($in['weeks'] ?? 12);
    if (!in_array($weeks, TI_PLAN_PUBLIC_WEEKS, true)) $weeks = 12;
    db()->prepare("UPDATE k30_ti_plan_public_instr SET weeks=?, in_directory=?, note=?, updated_at=datetime('now') WHERE user_id=?")
        ->execute([$weeks, !empty($in['in_directory']) ? 1 : 0, mb_substr(trim((string)($in['note'] ?? '')), 0, 500), $userId]);
}

/** Włączony link prowadzącego po tokenie (z nazwą użytkownika). */
function tiPlanPublicInstrFindByToken(string $token): ?array {
    tiPlanPublicMigrate();
    if (!preg_match('/^[a-z0-9]{24}$/', $token)) return null;
    return db_one("SELECT l.*, u.name AS user_name FROM k30_ti_plan_public_instr l JOIN users u ON u.id=l.user_id
                    WHERE l.token=? AND l.is_enabled=1", [$token]) ?: null;
}

function tiPlanPublicInstrTouch(int $linkId): void {
    try {
        db()->prepare("UPDATE k30_ti_plan_public_instr SET views=views+1, last_view_at=datetime('now') WHERE id=?")->execute([$linkId]);
    } catch (\Throwable $e) {}
}

function tiPlanPublicInstrUrl(array $row): string {
    return rtrim(defined('APP_URL') ? APP_URL : '', '/') . '/karty30/ti/dydaktyk/planPubliczny.php?p=' . rawurlencode((string)$row['token']);
}

function tiPlanPublicInstrShortUrl(array $row): ?string {
    $u = tiPlanPublicShortUrl($row);
    return $u === null ? null : str_replace('/plan/', '/plan/p/', $u);
}

/** Adres portalu (katalogu) — krótki na subdomenie ti.*, inaczej techniczny. */
function tiPlanPublicPortalUrl(): string {
    $short = tiPlanPublicShortUrl(['token' => '']);
    return $short !== null ? $short : rtrim(defined('APP_URL') ? APP_URL : '', '/') . '/karty30/ti/dydaktyk/planPubliczny.php';
}

/** Prowadzący aktywnych grup (domyślni + zastępstwa w terminach od dziś) — do zarządzania linkami. */
function tiPlanPublicInstructors(): array {
    return db_all(
        "SELECT u.id, u.name, COUNT(DISTINCT x.course_id) AS n_courses
           FROM (SELECT c.instructor_id AS uid, c.id AS course_id FROM k30_ti_courses c
                  WHERE c.instructor_id IS NOT NULL AND c.status NOT IN ('archived','cancelled')
                 UNION
                 SELECT s.instructor_id, s.course_id FROM k30_ti_sessions s JOIN k30_ti_courses c ON c.id=s.course_id
                  WHERE s.instructor_id IS NOT NULL AND s.lesson_date >= date('now') AND c.status NOT IN ('archived','cancelled')) x
           JOIN users u ON u.id = x.uid
          GROUP BY u.id, u.name
          ORDER BY u.name COLLATE NOCASE");
}

/**
 * Publikuje naraz plany wszystkich aktywnych grup (bez zarchiwizowanych/odwołanych).
 * Istniejące tokeny zostają; $dir = także „w katalogu portalu”. Zwraca [opublikowano nowych, łącznie].
 */
function tiPlanPublicEnableAll(int $by, bool $dir): array {
    tiPlanPublicMigrate();
    $new = 0; $all = 0;
    foreach (k30_ti_courses(false) as $c) {
        $cid = (int)$c['id'];
        $row = tiPlanPublicGet($cid);
        if (!$row || (int)$row['is_enabled'] !== 1) { tiPlanPublicEnable($cid, $by); $new++; }
        if ($dir) db()->prepare("UPDATE k30_ti_plan_public_links SET in_directory=1, updated_at=datetime('now') WHERE course_id=?")->execute([$cid]);
        $all++;
    }
    return [$new, $all];
}

/** Publikuje naraz plany wszystkich prowadzących aktywnych grup; $dir = także w katalogu. */
function tiPlanPublicInstrEnableAll(int $by, bool $dir): array {
    tiPlanPublicMigrate();
    $new = 0; $all = 0;
    foreach (tiPlanPublicInstructors() as $u) {
        $uid = (int)$u['id'];
        $row = tiPlanPublicInstrGet($uid);
        if (!$row || (int)$row['is_enabled'] !== 1) { tiPlanPublicInstrEnable($uid, $by); $new++; }
        if ($dir) db()->prepare("UPDATE k30_ti_plan_public_instr SET in_directory=1, updated_at=datetime('now') WHERE user_id=?")->execute([$uid]);
        $all++;
    }
    return [$new, $all];
}

/* ───────────────────────── Katalog portalu ───────────────────────── */

/** Grupy w katalogu: link włączony + in_directory + grupa aktywna. */
function tiPlanPublicDirectoryGroups(): array {
    tiPlanPublicMigrate();
    $rows = db_all(
        "SELECT l.token, l.public_name, c.id AS course_id, c.name, u.name AS instr_name, st.name AS subject_name,
                (SELECT MIN(s.lesson_date) FROM k30_ti_sessions s
                  WHERE s.course_id=c.id AND s.lesson_date >= date('now') AND s.draft_id IS NULL
                    AND s.status NOT IN ('draft','reserved','cancelled')) AS next_date
           FROM k30_ti_plan_public_links l
           JOIN k30_ti_courses c ON c.id = l.course_id
           LEFT JOIN users u ON u.id = c.instructor_id
           LEFT JOIN k30_ti_subject_types st ON st.id = c.subject_type_id
          WHERE l.is_enabled=1 AND l.in_directory=1 AND c.status NOT IN ('archived','cancelled')
          ORDER BY c.name COLLATE NOCASE");
    foreach ($rows as &$r) {
        $r['instr'] = tiPlanPublicStaffCode((string)$r['instr_name']);
        $r['name']  = tiPlanPublicGroupLabel((int)$r['course_id'], (string)$r['name'], (string)$r['public_name'], (string)$r['subject_name']);
    }
    unset($r);
    usort($rows, fn($a, $b) => strcasecmp($a['name'], $b['name']));
    return $rows;
}

/** Prowadzący w katalogu: link włączony + in_directory; z nazwami ich grup z katalogu. */
function tiPlanPublicDirectoryInstructors(): array {
    tiPlanPublicMigrate();
    $rows = db_all(
        "SELECT l.token, l.user_id, u.name FROM k30_ti_plan_public_instr l JOIN users u ON u.id=l.user_id
          WHERE l.is_enabled=1 AND l.in_directory=1 ORDER BY u.name COLLATE NOCASE");
    $dirCourses = [];
    foreach (tiPlanPublicDirectoryGroups() as $g) $dirCourses[(int)$g['course_id']] = $g['name'];
    foreach ($rows as &$r) {
        $r['initials'] = tiPlanPublicStaffCode((string)$r['name']);
        $ids = array_map('intval', array_column(db_all(
            "SELECT DISTINCT c.id FROM k30_ti_courses c
               LEFT JOIN k30_ti_sessions s ON s.course_id=c.id AND s.lesson_date >= date('now')
              WHERE c.status NOT IN ('archived','cancelled') AND (c.instructor_id=? OR s.instructor_id=?)",
            [(int)$r['user_id'], (int)$r['user_id']]), 'id'));
        $r['groups'] = array_values(array_intersect_key($dirCourses, array_flip($ids)));
        unset($r['name'], $r['user_id']);   // pełne nazwisko nie wychodzi poza serwer
    }
    return $rows;
}

/** Zakładka „Sale” w portalu (zajętość i rezerwacje sal) — przełącznik w planLinki.php. */
function tiPlanPublicRoomsEnabled(): bool {
    return org_setting('ti_plan_public_rooms') === '1';
}

/** [course_id => token] grup z katalogu — do linkowania kart lekcji z planów sal i prowadzących. */
function tiPlanPublicDirectoryTokens(): array {
    static $map = null;
    if ($map === null) {
        $map = [];
        foreach (tiPlanPublicDirectoryGroups() as $g) $map[(int)$g['course_id']] = (string)$g['token'];
    }
    return $map;
}

/** [user_id => token] prowadzących z katalogu — do linkowania z planów grup i sal. */
function tiPlanPublicDirectoryInstrTokens(): array {
    tiPlanPublicMigrate();
    $out = [];
    foreach (db_all("SELECT user_id, token FROM k30_ti_plan_public_instr WHERE is_enabled=1 AND in_directory=1") as $r) $out[(int)$r['user_id']] = (string)$r['token'];
    return $out;
}

/** Sale (aktywne) z liczbą terminów w najbliższych $weeks tygodniach; bez sal bez zajęć. */
function tiPlanPublicDirectoryRooms(int $weeks = 8): array {
    ti_planner_ext_migrate();
    $rows = db_all(
        "SELECT r.id, r.name, r.short_label, r.location, r.capacity, r.open_hours, r.reserve_hours, b.name AS building,
                (SELECT COUNT(*) FROM k30_ti_sessions s JOIN k30_ti_courses c ON c.id=s.course_id
                  WHERE s.room_id=r.id AND s.lesson_date BETWEEN date('now') AND date('now', ?)
                    AND s.draft_id IS NULL AND s.status NOT IN ('draft','reserved','cancelled')
                    AND c.status NOT IN ('archived','cancelled')) AS n_lessons
           FROM k30_pl_rooms r LEFT JOIN k30_pl_buildings b ON b.id = r.building_id
          WHERE r.is_active=1
          ORDER BY COALESCE(b.name, r.location) COLLATE NOCASE, r.name COLLATE NOCASE",
        ['+' . (7 * max(1, $weeks)) . ' days']);
    $oids = tiPlanPublicOnlineRoomIds();
    return array_values(array_filter($rows, fn($r) => (int)$r['n_lessons'] > 0 && !in_array((int)$r['id'], $oids, true)));
}

/** Jedna aktywna sala (do strony planu sali) albo null. */
function tiPlanPublicRoom(int $id): ?array {
    ti_planner_ext_migrate();
    return db_one("SELECT r.*, b.name AS building, b.address AS building_address FROM k30_pl_rooms r
                     LEFT JOIN k30_pl_buildings b ON b.id = r.building_id WHERE r.id=? AND r.is_active=1", [$id]) ?: null;
}

/* ───────────────────────── Terminy i siatka tygodnia ───────────────────────── */

/**
 * Terminy (bez wersji roboczych i rezerwacji) w zakresie dat. $filter: ['courses' => [id…]]
 * (grupa), ['instructor' => uid] (domyślny grupy lub zastępstwo w terminie) albo ['room' => id].
 * Zwraca znormalizowane wpisy gotowe do widoku publicznego.
 */
function tiPlanPublicLessons(array $filter, string $from, string $to): array {
    tiPlanPublicMigrate();
    ti_planner_ext_migrate();
    k30_ti_reschedule_migrate();
    $where = ''; $args = [];
    if (isset($filter['instructor'])) {
        $where = "COALESCE(s.instructor_id, c.instructor_id) = CAST(? AS INTEGER) AND c.status NOT IN ('archived','cancelled')";
        $args[] = (int)$filter['instructor'];
    } elseif (isset($filter['room'])) {
        $where = "s.room_id = ? AND c.status NOT IN ('archived','cancelled')";
        $args[] = (int)$filter['room'];
    } elseif (!empty($filter['online'])) {   // „Sala online”: wszystkie zajęcia zdalne (+ terminy w salach „online” z rejestru)
        $oids = tiPlanPublicOnlineRoomIds();
        $where = "(s.lesson_method IN ('zdalna_zoom','zdalna_inne') OR (COALESCE(s.lesson_method,'')='' AND c.is_online=1)"
               . ($oids ? ' OR s.room_id IN (' . implode(',', $oids) . ')' : '') . ") AND c.status NOT IN ('archived','cancelled')";
    } elseif (!empty($filter['all'])) {      // widok pełny: wszystkie aktywne grupy
        $where = "c.status NOT IN ('archived','cancelled')";
    } else {
        $ids = array_values(array_filter(array_map('intval', (array)($filter['courses'] ?? []))));
        if (!$ids) return [];
        $where = 's.course_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
        $args = $ids;
    }
    $args[] = $from; $args[] = $to;
    $rows = db_all(
        "SELECT s.id, s.course_id, s.room_id, s.room_reservation_status, s.lesson_date, s.time_from, s.time_to, s.status, s.lesson_method, s.date_flag, s.meeting_url,
                s.rescheduled_from_date, s.rescheduled_from_time_from,
                c.name AS course_name, c.simple_mode, pl.public_name, c.default_meeting_url, c.location AS course_location, c.is_online AS course_online,
                st.name AS subject_name, u.name AS instr_name, COALESCE(s.instructor_id, c.instructor_id) AS instr_id,
                r.name AS room_name, r.location AS room_location, r.short_label AS room_short_label
           FROM k30_ti_sessions s
           JOIN k30_ti_courses c ON c.id = s.course_id
           LEFT JOIN k30_ti_subject_types st ON st.id = c.subject_type_id
           LEFT JOIN users u ON u.id = COALESCE(s.instructor_id, c.instructor_id)
           LEFT JOIN k30_pl_rooms r ON r.id = s.room_id
           LEFT JOIN k30_ti_plan_public_links pl ON pl.course_id = c.id
          WHERE $where AND s.lesson_date BETWEEN ? AND ?
            AND s.draft_id IS NULL AND s.status <> 'draft'
            AND (s.status <> 'reserved' OR c.simple_mode > 0)   -- SzK / konsultacje: terminy zarezerwowane też są w planie
          ORDER BY s.lesson_date, s.time_from, c.name",
        $args);
    $methods = ['stacjonarna' => 'stacjonarnie', 'zdalna_zoom' => 'online', 'zdalna_inne' => 'online'];
    $out = [];
    foreach ($rows as $r) {
        $method = $methods[(string)$r['lesson_method']] ?? ((string)$r['lesson_method'] === '' ? ((int)$r['course_online'] === 1 ? 'online' : 'stacjonarnie') : '');
        $room = ti_room_label($r['room_name'] !== null ? ['name' => $r['room_name'], 'location' => $r['room_location'], 'short_label' => $r['room_short_label']] : null, (string)($r['course_location'] ?? ''));
        $tf = substr((string)$r['time_from'], 0, 5);
        $tt = substr((string)$r['time_to'], 0, 5);
        $moved = trim((string)($r['rescheduled_from_date'] ?? '')) !== ''
            ? date('d.m', strtotime((string)$r['rescheduled_from_date'])) . ($r['rescheduled_from_time_from'] ? ' ' . substr((string)$r['rescheduled_from_time_from'], 0, 5) : '')
            : '';
        $out[] = [
            'date'      => (string)$r['lesson_date'],
            'dow'       => (int)date('N', strtotime((string)$r['lesson_date'])),
            'tf'        => $tf,
            'tt'        => $tt,
            'time'      => $tf !== '' ? $tf . '–' . $tt : '',
            'course_id' => (int)$r['course_id'],
            // RODO: nazwa publiczna / zastępcza, gdy nazwa grupy zawiera dane uczestnika
            'course'    => $label = tiPlanPublicGroupLabel((int)$r['course_id'], (string)$r['course_name'], (string)($r['public_name'] ?? ''), (string)$r['subject_name']),
            'subject'   => trim((string)$r['subject_name']) !== '' ? (string)$r['subject_name'] : $label,
            'instr'     => tiPlanPublicPerson((string)($r['instr_name'] ?? '')),
            'instr_id'  => (int)$r['instr_id'],
            'method'    => $method,
            'room'      => $room !== '—' && $method !== 'online' ? $room : '',
            'url'       => $method === 'online' ? (trim((string)$r['meeting_url']) ?: trim((string)$r['default_meeting_url'])) : '',
            'cancelled' => (string)$r['status'] === 'cancelled',
            'flag'      => (string)($r['date_flag'] ?? ''),
            'moved'     => $moved,   // przeniesione z „dd.mm hh:mm”
            'room_id'   => $method !== 'online' ? (int)$r['room_id'] : 0,
            // rezerwacja sali u operatora przestrzeni (k30_ti_sessions.room_reservation_status)
            'room_ok'   => (string)($r['room_reservation_status'] ?? '') === 'potwierdzone',
            'kind'      => (int)$r['simple_mode'] > 0 ? 'szk' : 'course',   // SzK / konsultacje grupowe
        ];
    }
    // Konsultacje indywidualne (rezerwacje k30_schedules) — plan prowadzącego, „Sala online”, widok pełny
    if (isset($filter['instructor']) || !empty($filter['online']) || !empty($filter['all'])) {
        $out = array_merge($out, tiPlanPublicConsultations($filter, $from, $to));
        usort($out, fn($a, $b) => [$a['date'], $a['tf'], $a['course']] <=> [$b['date'], $b['tf'], $b['course']]);
    }
    return $out;
}

/**
 * Konsultacje indywidualne z rezerwacji (k30_schedules, moduł ti_bookings) jako wpisy planu.
 * Tytuł „Konsultacje”, zamiast grupy inicjały beneficjenta (RODO: nigdy pełne nazwisko, także w widoku pełnym).
 */
function tiPlanPublicConsultations(array $filter, string $from, string $to): array {
    try {
        $where = "date(s.start_time) BETWEEN ? AND ? AND s.status IN ('preliminary','confirmed','attended','cancelled_by_feer','cancelled_by_client','cancelled')";
        $args = [$from, $to];
        if (isset($filter['instructor'])) { $where .= ' AND s.assigned_to = CAST(? AS INTEGER)'; $args[] = (int)$filter['instructor']; }
        if (!empty($filter['online'])) $where .= ' AND s.is_remote = 1';
        $rows = db_all("SELECT s.id, s.start_time, s.duration_minutes, s.time_from, s.time_to, s.status, s.is_remote, s.assigned_to,
                               u.name AS instr_name, r.name AS res_name, cl.name AS client_name
                          FROM k30_schedules s
                          LEFT JOIN users u ON u.id = s.assigned_to
                          LEFT JOIN k30_clients cl ON cl.id = s.client_id
                          LEFT JOIN resources r ON r.id = s.resource_id
                         WHERE $where ORDER BY s.start_time", $args);
    } catch (\Throwable $e) { return []; }   // brak modułu rezerwacji w tej instalacji
    $out = [];
    foreach ($rows as $r) {
        $t  = strtotime((string)$r['start_time']);
        $tf = trim((string)$r['time_from']) !== '' ? substr((string)$r['time_from'], 0, 5) : date('H:i', $t);
        $tt = trim((string)$r['time_to']) !== '' ? substr((string)$r['time_to'], 0, 5) : date('H:i', $t + 60 * max(15, (int)$r['duration_minutes']));
        $online = (int)$r['is_remote'] === 1;
        $out[] = [
            'date' => date('Y-m-d', $t), 'dow' => (int)date('N', $t), 'tf' => $tf, 'tt' => $tt, 'time' => $tf . '–' . $tt,
            'course_id' => 0, 'course' => tiPlanPublicInitials((string)($r['client_name'] ?? '')) ?: 'konsultacja indywidualna', 'subject' => 'Konsultacje',
            'instr' => tiPlanPublicPerson((string)($r['instr_name'] ?? '')), 'instr_id' => (int)$r['assigned_to'],
            'method' => $online ? 'online' : 'stacjonarnie', 'room' => $online ? '' : (string)($r['res_name'] ?? ''),
            'url' => '', 'cancelled' => str_starts_with((string)$r['status'], 'cancelled'),
            'flag' => (string)$r['status'] === 'preliminary' ? 'tentative' : '', 'moved' => '',
            'room_id' => 0, 'room_ok' => true, 'kind' => 'consult',
        ];
    }
    return $out;
}

/**
 * Sale z rejestru, które w rzeczywistości oznaczają zajęcia zdalne (nazwa / skrót / lokalizacja „online”, „zdalnie”
 * albo tryb sali „Zdalny” = mode_support 'remote') — scalane z wirtualną „Salą online” (?s=online), żeby nie było dwóch takich samych pozycji.
 */
function tiPlanPublicOnlineRoomIds(): array {
    static $ids = null;
    if ($ids !== null) return $ids;
    ti_planner_ext_migrate();
    $ids = [];
    foreach (db_all("SELECT id, name, short_label, location, mode_support FROM k30_pl_rooms") as $r) {
        $txt = tiPlanPublicFold(implode(' ', [(string)$r['name'], (string)$r['short_label'], (string)$r['location']]));
        // także literówki typu „onlline”, „onlin”, „on-line”
        if (in_array((string)$r['mode_support'], ['remote', 'online'], true) || preg_match('/\b(on-?l+i+n+e?|zdaln\w*|zoom|teams|wirtualn\w*)\b/u', $txt)) $ids[] = (int)$r['id'];
    }
    return $ids;
}

/** Liczba zajęć zdalnych (sesje + konsultacje) w najbliższych $weeks tygodniach — do kafla „Zajęcia online”. */
function tiPlanPublicOnlineCount(int $weeks = 8): int {
    return count(array_filter(tiPlanPublicLessons(['online' => true], date('Y-m-d'), date('Y-m-d', strtotime('+' . (7 * $weeks) . ' days'))), fn($l) => !$l['cancelled']));
}

/**
 * Tekst komunikatu → bezpieczny HTML: wiersze zaczynające się od „-” (także „–”, „•”, „*”) stają się listą punktowaną,
 * pozostałe akapitami z <br>. Przed escapowaniem: RODO (inicjały imion i nazwisk).
 */
function tiPlanPublicTextHtml(string $text): string {
    $lines = preg_split('/\R/u', trim(tiPlanPublicAnonymize($text))) ?: [];
    $out = ''; $list = []; $para = [];
    $flushPara = function () use (&$para, &$out) { if ($para) { $out .= '<p class="note-p">' . implode('<br>', $para) . '</p>'; $para = []; } };
    $flushList = function () use (&$list, &$out) { if ($list) { $out .= '<ul class="note-ul">' . implode('', $list) . '</ul>'; $list = []; } };
    foreach ($lines as $ln) {
        if (preg_match('/^\s*[-–•*]\s*(.*)$/u', $ln, $m) && trim($m[1]) !== '') { $flushPara(); $list[] = '<li>' . h(trim($m[1])) . '</li>'; continue; }
        $flushList();
        if (trim($ln) === '') { $flushPara(); continue; }
        $para[] = h(trim($ln));
    }
    $flushPara(); $flushList();
    return $out;
}

/** Platforma zajęć online (Zoom / Teams / Google Meet / inna) z adresu spotkania terminu; '' dla zajęć stacjonarnych. */
function tiPlanPublicPlatform(array $l): string {
    if (($l['method'] ?? '') !== 'online') return '';
    $host = strtolower((string)parse_url((string)($l['url'] ?? ''), PHP_URL_HOST));
    if ($host === '') return 'inna / brak linku';
    return match (true) {
        str_contains($host, 'zoom') => 'Zoom',
        str_contains($host, 'teams') => 'Teams',
        str_contains($host, 'meet.google') => 'Google Meet',
        default => 'inna platforma',
    };
}

/** Poniedziałek tygodnia zawierającego datę (Y-m-d). */
function tiPlanPublicMonday(string $date): string {
    $t = strtotime($date);
    return date('Y-m-d', strtotime('-' . ((int)date('N', $t) - 1) . ' days', $t));
}

/**
 * Siatka jednego tygodnia: dni (Pn–Pt zawsze, Sb/Nd gdy są zajęcia), wiersze = przedziały godzin
 * występujące w tym tygodniu, komórka = lista terminów (kilka grup/prowadzących naraz).
 */
function tiPlanPublicWeekGrid(array $lessons, string $monday): array {
    $sunday = date('Y-m-d', strtotime($monday . ' +6 days'));
    $week = array_values(array_filter($lessons, fn($l) => $l['date'] >= $monday && $l['date'] <= $sunday));
    $short = [1 => 'Pn', 2 => 'Wt', 3 => 'Śr', 4 => 'Cz', 5 => 'Pt', 6 => 'Sb', 7 => 'Nd'];
    $ids   = [1 => 'pn', 2 => 'wt', 3 => 'sr', 4 => 'cz', 5 => 'pt', 6 => 'sb', 7 => 'nd'];
    $days = [];
    for ($d = 1; $d <= 7; $d++) {
        $n = count(array_filter($week, fn($l) => $l['dow'] === $d && !$l['cancelled']));
        $any = count(array_filter($week, fn($l) => $l['dow'] === $d));
        if ($d >= 6 && !$any) continue;
        $date = date('Y-m-d', strtotime($monday . ' +' . ($d - 1) . ' days'));
        $days[$d] = ['dow' => $d, 'id' => $ids[$d], 'short' => $short[$d], 'full' => TI_DAYS_PL_FULL[$d],
                     'date' => $date, 'count' => $n, 'today' => $date === date('Y-m-d')];
    }
    $slots = []; $cells = [];
    foreach ($week as $l) {
        $k = $l['time'] !== '' ? $l['time'] : '—';
        $slots[$k] = ['key' => $k, 'tf' => $l['tf'], 'tt' => $l['tt']];
        $cells[$k][$l['dow']][] = $l;
    }
    uasort($slots, fn($a, $b) => [$a['tf'], $a['tt']] <=> [$b['tf'], $b['tt']]);
    return ['monday' => $monday, 'sunday' => $sunday, 'days' => $days, 'slots' => array_values($slots), 'cells' => $cells, 'lessons' => $week];
}

/** Najbliższy nieodwołany termin (od teraz) albo null. */
function tiPlanPublicNext(array $lessons): ?array {
    $today = date('Y-m-d'); $now = date('H:i');
    foreach ($lessons as $l) {
        if ($l['cancelled']) continue;
        if ($l['date'] > $today || ($l['date'] === $today && $l['tt'] >= $now)) return $l;
    }
    return null;
}

/** Plik .ics z nieodwołanymi terminami. */
function tiPlanPublicIcs(array $lessons, string $calName, string $uidSeed): string {
    $esc = fn(string $s) => str_replace(["\\", ';', ',', "\n"], ["\\\\", '\\;', '\\,', '\\n'], $s);
    $host = parse_url((string)(defined('APP_URL') ? APP_URL : ''), PHP_URL_HOST) ?: 'feer.org.pl';
    $out = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//FEER//Plan zajec TI//PL', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH',
            'X-WR-CALNAME:' . $esc($calName), 'X-WR-TIMEZONE:Europe/Warsaw',
            'BEGIN:VTIMEZONE', 'TZID:Europe/Warsaw',
            'BEGIN:DAYLIGHT', 'TZOFFSETFROM:+0100', 'TZOFFSETTO:+0200', 'TZNAME:CEST', 'DTSTART:19700329T020000', 'RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=-1SU', 'END:DAYLIGHT',
            'BEGIN:STANDARD', 'TZOFFSETFROM:+0200', 'TZOFFSETTO:+0100', 'TZNAME:CET', 'DTSTART:19701025T030000', 'RRULE:FREQ=YEARLY;BYMONTH=10;BYDAY=-1SU', 'END:STANDARD',
            'END:VTIMEZONE'];
    foreach ($lessons as $l) {
        if ($l['cancelled'] || !preg_match('/^(\d{2}):(\d{2})$/', $l['tf'], $a) || !preg_match('/^(\d{2}):(\d{2})$/', $l['tt'], $b)) continue;
        $day = str_replace('-', '', $l['date']);
        $out[] = 'BEGIN:VEVENT';
        $out[] = 'UID:' . sha1($uidSeed . '|' . $l['course_id'] . '|' . $l['date'] . '|' . $l['time']) . '@' . $host;
        $out[] = 'DTSTAMP:' . gmdate('Ymd\THis\Z');
        $out[] = "DTSTART;TZID=Europe/Warsaw:{$day}T{$a[1]}{$a[2]}00";
        $out[] = "DTEND;TZID=Europe/Warsaw:{$day}T{$b[1]}{$b[2]}00";
        $out[] = 'SUMMARY:' . $esc($l['subject']);
        $out[] = 'LOCATION:' . $esc($l['method'] === 'online' ? ($l['url'] ?: 'online') : $l['room']);
        if ($l['url'] !== '') $out[] = 'URL:' . $l['url'];
        $out[] = 'DESCRIPTION:' . $esc('Forma: ' . ($l['method'] ?: '—') . ($l['url'] ? '. Link do zajęć: ' . $l['url'] : '') . ($l['instr'] ? '. Prowadzący: ' . $l['instr'] : '') . '. Plan może ulec zmianie.');
        $out[] = 'END:VEVENT';
    }
    $out[] = 'END:VCALENDAR';
    return implode("\r\n", $out) . "\r\n";
}


/** Adnotacja przy godzinach otwarcia sal (portal publiczny, API, aplikacja Node). */
const TI_PLAN_RESERVE_NOTE = 'Godziny rezerwacji to okno, w którym salę można rezerwować; rezerwacja jest ważna dopiero po potwierdzeniu.';
const TI_PLAN_HOURS_NOTE = 'Godziny otwarcia sali nie są równoznaczne z jej rezerwacją — sala jest zarezerwowana tylko w terminach widocznych w planie.';
