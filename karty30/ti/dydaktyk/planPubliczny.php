<?php
/**
 * karty30/ti/dydaktyk/planPubliczny.php — Publiczny portal planu zajęć TI (bez logowania).
 *
 * Wygląd w stylu „Plan lekcji Optivum”: z lewej lista Grupy / Prowadzący / Sale, z prawej pasek z nazwą
 * i tabela Nr | Godz | dni tygodnia (konkretny tydzień z datami — TI planuje terminami).
 *   (brak parametrów)  strona startowa (lista + najbliższe zajęcia)
 *   ?k=grupy|prowadzacy|sale  pełna lista (TYLKO wpisy oznaczone „w katalogu” w planLinki.php; Sale — przełącznik)
 *   ?g=<token>         plan grupy (dotychczasowy tajny link; działa też, gdy grupy nie ma w katalogu)
 *   ?p=<token>         plan prowadzącego (tajny link prowadzącego)
 *   ?s=<id sali>       zajętość i rezerwacje sali (gdy zakładka Sale włączona)
 *   &t=<data>          tydzień;  &v=lista  lista terminów;  &ics=1  plik kalendarza (grupa, prowadzący)
 * Krótkie adresy na ti.* / szo.* / kurs*.*: /plan/, /plan/grupy, /plan/prowadzacy, /plan/sale, /plan/<token>, /plan/p/<token>, /plan/s/<id>.
 * Logika: modules/ti_plan_public/logic/planPublicPortal.php; widok: modules/ti_plan_public/ui/.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/functions.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/karty30.php';
require_once dirname(dirname(dirname(__DIR__))) . '/modules/ti_plan_public/logic/planPublicPortal.php';
require_once dirname(dirname(dirname(__DIR__))) . '/modules/ti_plan_public/ui/portalLayout.php';
require_once dirname(dirname(dirname(__DIR__))) . '/modules/ti_plan_public/ui/portalSchedule.php';
require_once dirname(dirname(dirname(__DIR__))) . '/modules/ti_duty/logic/dutyManager.php';
require_once dirname(dirname(dirname(__DIR__))) . '/modules/ti_duty/ui/dutyView.php';

header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-cache, private');   // publikacja w planLinki.php ma być widoczna od razu
tiPlanPublicMigrate();

$self  = (string)($_SERVER['SCRIPT_NAME'] ?? '/karty30/ti/dydaktyk/planPubliczny.php');
$home  = tiPlanPortalUrl($self);

// ── Widok pełny (hasło ustawione przez kierownika w planLinki.php) ──
// Zalogowanie trzymane 90 dni w podpisanym ciasteczku TIPLAN_FULL („ważne do|HMAC”), bez sesji na serwerze
// (sesję PHP sprząta GC po kilkudziesięciu minutach). Kluczem HMAC jest hash hasła — zmiana hasła unieważnia
// wszystkie zalogowania. Ciasteczko powstaje WYŁĄCZNIE po zalogowaniu — zwykły odwiedzający nie dostaje żadnego.
const TI_PLAN_FULL_DAYS = 90;
$https    = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
$fullHash = tiPlanPublicFullHash();
$fullSig  = fn(int $exp): string => hash_hmac('sha256', 'tiplan-full|' . $exp, $fullHash);
$fullCookie = function (string $val, int $exp) use ($https): void {
    if (PHP_SAPI !== 'cli') setcookie('TIPLAN_FULL', $val, ['expires' => $exp, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
};
$fullOk = false;
if ($fullHash !== '' && preg_match('/^(\d{9,11})\.([a-f0-9]{64})$/', (string)($_COOKIE['TIPLAN_FULL'] ?? ''), $fm)) {
    $fullOk = (int)$fm[1] > time() && hash_equals($fullSig((int)$fm[1]), $fm[2]);
}
tiPlanPublicFull($fullOk);
if ($fullOk) header('Cache-Control: no-store, private');

if (isset($_GET['logout'])) {
    $fullCookie('', time() - 3600);
    header('Location: ' . html_entity_decode($home)); exit;
}
$loginErr = '';
if (isset($_GET['login']) && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    [$ok, $loginErr] = tiPlanPublicFullCheck((string)($_POST['haslo'] ?? ''));
    if ($ok) {
        $exp = time() + TI_PLAN_FULL_DAYS * 86400;
        $fullCookie($exp . '.' . $fullSig($exp), $exp);
        header('Location: ' . html_entity_decode($home)); exit;
    }
}

$org   = ti_org_contact_info();
$full  = tiPlanPublicFull();
$dirG  = tiPlanPublicDirectoryGroups();
$dirI  = tiPlanPublicDirectoryInstructors();
$rooms = tiPlanPublicRoomsEnabled() || $full;
$dirR  = $rooms ? tiPlanPublicDirectoryRooms(8) : [];
$links = ['self' => $self, 'groupLinks' => tiPlanPublicDirectoryTokens(), 'instrLinks' => tiPlanPublicDirectoryInstrTokens(), 'roomLinks' => $rooms];
$roomName = fn(array $r) => trim((string)$r['short_label']) !== '' ? (string)$r['short_label'] : (string)$r['name'];
// „Sala online” — wirtualna sala zajęć zdalnych (nie wymaga rezerwacji), zawsze pierwsza na liście sal
if ($rooms) array_unshift($dirR, ['id' => 'online', 'name' => 'Sala online', 'short_label' => '', 'building' => '', 'location' => 'zajęcia zdalne — nie wymaga rezerwacji sali',
                                  'n_lessons' => tiPlanPublicOnlineCount(8)]);
// Widok pełny: wszystkie aktywne grupy (?c=id) i prowadzący (?u=id) z prawdziwymi nazwami
$allG = $full ? tiPlanPublicAllGroups() : [];
$allI = $full ? tiPlanPublicInstructors() : [];
// Lista z lewej (jak „Oddziały / Nauczyciele / Sale” w Optivum)
$side = $full ? [
    'grupy'      => array_map(fn($g) => [(string)$g['name'], tiPlanPortalUrl($self, ['c' => $g['id']])], $allG),
    'prowadzacy' => array_map(fn($u) => [(string)$u['name'], tiPlanPortalUrl($self, ['u' => $u['id']])], $allI),
    'sale'       => array_map(fn($r) => [$roomName($r), tiPlanPortalUrl($self, ['s' => $r['id']])], $dirR),
] : [
    'grupy'      => array_map(fn($g) => [(string)$g['name'], tiPlanPortalUrl($self, ['g' => $g['token']])], $dirG),
    'prowadzacy' => $dirI ? array_map(fn($i) => [(string)$i['initials'], tiPlanPortalUrl($self, ['p' => $i['token']])], $dirI) : null,
    'sale'       => $rooms ? array_map(fn($r) => [$roomName($r), tiPlanPortalUrl($self, ['s' => $r['id']])], $dirR) : null,
];
// DK — dyżury kierownika / Zarządu (publikacja: przełącznik w planLinki.php; w widoku pełnym zawsze)
$dutyOn = tiDutyPublic() || $full;
if ($dutyOn) $side['dk'] = [['Dyżur kierownika / Zarządu', tiPlanPortalUrl($self, ['k' => 'dk'])]];
$base = ['self' => $self, 'org' => $org, 'side' => $side, 'full' => $full, 'canLogin' => $fullHash !== ''];

/* ───────────── Logowanie do widoku pełnego ───────────── */
if (isset($_GET['login'])) {
    tiPlanPortalOpen($base + ['title' => 'Pełny plan — logowanie', 'heading' => 'Pełny plan zajęć']);
    if ($fullHash === '') {
        echo '<p class="empty">Logowanie do pełnego planu nie jest jeszcze włączone — kierownik ustawia hasło w panelu dydaktyka (Linki do planów → Portal planu zajęć).</p>';
        tiPlanPortalClose($base);
        exit;
    }
    ?>
    <form method="post" action="<?= tiPlanPortalUrl($self, ['login' => 1]) ?>" class="box" style="max-width:420px">
      <h2>Logowanie dla pracowników</h2>
      <p>Hasło nadaje kierownik. Po zalogowaniu widać wszystkie grupy, pełne nazwiska i linki do zajęć online — <strong>nie udostępniaj ekranu osobom postronnym</strong>.
        Zalogowanie jest pamiętane na tym urządzeniu przez 90 dni (albo do wylogowania lub zmiany hasła) — nie loguj się na komputerze publicznym.</p>
      <?php if ($loginErr !== ''): ?><p class="st-bad" role="alert"><strong><?= h($loginErr) ?></strong></p><?php endif; ?>
      <p><label for="haslo"><strong>Hasło</strong></label><br>
        <input type="password" id="haslo" name="haslo" required autocomplete="current-password" style="font:inherit;padding:4px 6px;width:100%" autofocus></p>
      <p><button type="submit" style="font:inherit;padding:4px 14px">Zaloguj</button> <a href="<?= $home ?>">Anuluj</a></p>
    </form>
    <?php
    tiPlanPortalClose($base);
    exit;
}

$notFound = function (string $what) use ($base, $home): void {
    http_response_code(404);
    tiPlanPortalOpen($base + ['title' => 'Plan niedostępny', 'heading' => 'Plan niedostępny']);
    echo '<p class="empty">' . h($what) . '</p><p class="empty"><a href="' . $home . '">Przejdź do strony głównej planu</a></p>';
    tiPlanPortalClose($base);
    exit;
};
$dayWord = fn(int $nd) => $nd === 0 ? 'dziś' : ($nd === 1 ? 'jutro' : 'za ' . $nd . ' dni');
$nextBox = function (?array $nx, bool $hasAny) use ($dayWord): void {
    if ($nx): $nd = (int)round((strtotime($nx['date']) - strtotime(date('Y-m-d'))) / 86400); ?>
    <section class="box next" aria-label="Najbliższe zajęcia">
      <strong>Najbliższe zajęcia (<?= h($dayWord($nd)) ?>):</strong>
      <span class="when"><?= h(TI_DAYS_PL_FULL[$nx['dow']]) ?>, <?= h(date('d.m.Y', strtotime($nx['date']))) ?>, <?= h($nx['time'] ?: '—') ?></span>
      — <?= h($nx['subject']) ?><?= $nx['method'] === 'online' ? ', online' : '' ?><?= $nx['room'] ? ', ' . h($nx['room']) : '' ?><?= $nx['instr'] ? ', prowadzi ' . h($nx['instr']) : '' ?>
      <?php if ($nx['url'] !== ''): ?> — <a href="<?= h($nx['url']) ?>" target="_blank" rel="noopener"><strong>Dołącz do zajęć online</strong></a>
      <?php elseif ($nx['method'] === 'online'): ?> — link do spotkania prowadzący poda przed zajęciami.<?php endif; ?>
    </section>
    <?php elseif (!$hasAny): ?>
    <section class="box next" aria-label="Najbliższe zajęcia"><strong>Brak zaplanowanych terminów.</strong> Gdy pojawią się nowe terminy, zobaczysz je tutaj.</section>
    <?php endif;
};
$noteBox = function (string $note): void {
    if (trim($note) !== '') echo '<div class="box note" role="note">' . tiPlanPublicTextHtml($note) . '</div>';   // RODO + wiersze od „-” jako lista
};
$sendIcs = function (array $lessons, string $calName, string $seed, string $file): void {
    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: attachment; filename="plan-' . preg_replace('/[^A-Za-z0-9._-]+/', '-', $file) . '.ics"');
    echo tiPlanPublicIcs(array_values(array_filter($lessons, fn($l) => $l['date'] >= date('Y-m-d'))), $calName, $seed);
    exit;
};
$from = tiPlanPublicMonday(date('Y-m-d'));
// Grupa spoza katalogu na planie prowadzącego/sali: bez nazwy (także jako tytuł, gdy brak przedmiotu) i bez linku do spotkania
$hideGroup = function (array $l): array {
    if ($l['subject'] === $l['course']) $l['subject'] = 'Zajęcia';
    $l['course'] = 'niepubliczna';
    $l['url'] = '';
    return $l;
};
$roomTitle = fn(string $n) => preg_match('/^sala\b/iu', $n) ? $n : 'Sala ' . $n;
// Kafelki wyboru planu: [href, nazwa, opis] dla każdej listy
$tiles = $full ? [
    'grupy'      => array_map(fn($g) => [tiPlanPortalUrl($self, ['c' => $g['id']]), (string)$g['name'], implode(' · ', array_filter([(string)$g['subject_name'], (string)$g['instr_name']]))], $allG),
    'prowadzacy' => array_map(fn($u) => [tiPlanPortalUrl($self, ['u' => $u['id']]), (string)$u['name'], 'grup: ' . (int)$u['n_courses']], $allI),
    'sale'       => array_map(fn($r) => [tiPlanPortalUrl($self, ['s' => $r['id']]), $roomName($r),
                        implode(' · ', array_filter([trim((string)$r['short_label']) !== '' ? (string)$r['name'] : '', (string)($r['building'] ?: $r['location']), (int)$r['n_lessons'] . ' zaj. w 8 tyg.']))], $dirR),
] : [
    'grupy'      => array_map(fn($g) => [tiPlanPortalUrl($self, ['g' => $g['token']]), (string)$g['name'],
                        implode(' · ', array_filter([(string)$g['subject_name'], $g['instr'] ? 'prow. ' . $g['instr'] : '', $g['next_date'] ? 'najbliższe ' . date('d.m', strtotime((string)$g['next_date'])) : 'brak terminów']))], $dirG),
    'prowadzacy' => array_map(fn($i) => [tiPlanPortalUrl($self, ['p' => $i['token']]), (string)$i['initials'],
                        implode(', ', array_slice($i['groups'], 0, 3)) . (count($i['groups']) > 3 ? ' …' : '')], $dirI),
    'sale'       => array_map(fn($r) => [tiPlanPortalUrl($self, ['s' => $r['id']]), $roomName($r),
                        implode(' · ', array_filter([trim((string)$r['short_label']) !== '' ? (string)$r['name'] : '', (string)($r['building'] ?: $r['location']), (int)$r['n_lessons'] . ' zaj. w 8 tyg.']))], $dirR),
];
$tileCls = ['grupy' => 't-group', 'prowadzacy' => 't-instr', 'sale' => 't-room'];
$printTiles = function (string $k, ?int $limit = null) use ($tiles, $tileCls, $self): void {
    $titles = ['grupy' => 'Grupy', 'prowadzacy' => 'Prowadzący', 'sale' => 'Sale'];
    $items = $tiles[$k];
    if (!$items) return;
    $more = $limit !== null && count($items) > $limit;
    echo '<h2 class="tiles-head" id="h-' . $k . '"><span>' . $titles[$k] . ' (' . count($items) . ')</span>'
       . ($more ? '<a href="' . tiPlanPortalUrl($self, ['k' => $k]) . '">wszystkie ' . mb_strtolower($titles[$k]) . ' »</a>' : '') . '</h2>';
    echo '<ul class="tiles" aria-labelledby="h-' . $k . '">';
    foreach ($more ? array_slice($items, 0, $limit) : $items as $it)
        echo '<li><a class="tile ' . $tileCls[$k] . '" href="' . $it[0] . '"><strong>' . h($it[1]) . '</strong>' . ($it[2] !== '' ? '<small>' . h($it[2]) . '</small>' : '') . '</a></li>';
    echo '</ul>';
};

/* ───────────── Plan grupy (tajny link ?g=, a w widoku pełnym każda grupa ?c=) ───────────── */
if (isset($_GET['g']) || ($full && isset($_GET['c']))) {
    if (isset($_GET['g'])) {
        $tok  = (string)$_GET['g'];
        $link = tiPlanPublicFindByToken($tok);
        if (!$link || in_array((string)$link['course_status'], ['cancelled', 'archived'], true)) $notFound('Link wygasł, został wyłączony albo zawiera błąd. Poproś prowadzącego lub sekretariat o aktualny adres.');
        $q = ['g' => $tok];
    } else {
        $cRow = db_one("SELECT id, name, status FROM k30_ti_courses WHERE id=?", [(int)$_GET['c']]);
        if (!$cRow || in_array((string)$cRow['status'], ['cancelled', 'archived'], true)) $notFound('Nie ma takiej aktywnej grupy.');
        $link = (tiPlanPublicGet((int)$cRow['id']) ?: ['id' => 0, 'weeks' => 12, 'note' => '', 'show_contacts' => 1, 'in_directory' => 0, 'public_name' => ''])
              + ['course_id' => (int)$cRow['id'], 'course_name' => (string)$cRow['name']];
        $link['course_name'] = (string)$cRow['name'];
        $q = ['c' => (int)$cRow['id']];
    }
    $weeks   = (int)$link['weeks'] ?: 12;
    $cid     = (int)$link['course_id'];
    $lessons = tiPlanPublicLessons(['courses' => [$cid]], $from, date('Y-m-d', strtotime("+{$weeks} weeks")));
    $course = db_one("SELECT c.simple_mode, c.instructor_id, st.name AS subject_name, u.name AS instr_name FROM k30_ti_courses c
                        LEFT JOIN k30_ti_subject_types st ON st.id=c.subject_type_id LEFT JOIN users u ON u.id=c.instructor_id WHERE c.id=?", [$cid]) ?: [];
    // RODO: nazwa publiczna / zastępcza (gdy nazwa grupy zawiera imię lub nazwisko uczestnika)
    $gName = tiPlanPublicGroupLabel($cid, (string)$link['course_name'], (string)($link['public_name'] ?? ''), (string)($course['subject_name'] ?? ''));
    $inDir = (int)$link['in_directory'] === 1 && !$full;
    // Grupa w katalogu = adres publicznie znany: bez linków do spotkań online (Zoom) i bez kontaktów prowadzących
    if ($inDir) foreach ($lessons as $i => $l) $lessons[$i]['url'] = '';
    if (isset($_GET['ics'])) $sendIcs($lessons, 'Plan zajęć — ' . $gName, 'g' . $cid, $gName);
    if (isset($q['g'])) tiPlanPublicTouch((int)$link['id']);
    // Szkolenie krótkie (kilka terminów): domyślnie lista terminów zamiast tabeli tygodnia
    if ((int)($course['simple_mode'] ?? 0) > 0 && !isset($_GET['t']) && !isset($_GET['v'])) $_GET['v'] = 'lista';
    $instrTok = $links['instrLinks'][(int)($course['instructor_id'] ?? 0)] ?? null;
    $instrUrl = $full && !empty($course['instructor_id']) ? tiPlanPortalUrl($self, ['u' => (int)$course['instructor_id']]) : ($instrTok ? tiPlanPortalUrl($self, ['p' => $instrTok]) : null);
    $ini = tiPlanPublicPerson((string)($course['instr_name'] ?? '')) ?: '—';
    $sub = (trim((string)($course['subject_name'] ?? '')) !== '' ? h($course['subject_name']) . ' · ' : '')
         . 'prowadzący: ' . ($instrUrl ? '<a href="' . $instrUrl . '">' . h($ini) . '</a>' : h($ini));
    tiPlanPortalOpen($base + ['title' => 'Plan zajęć — ' . $gName, 'heading' => $gName, 'sub' => $sub,
        'current' => tiPlanPortalUrl($self, $q)]);
    $nextBox(tiPlanPublicNext($lessons), (bool)$lessons);
    $noteBox((string)$link['note']);
    tiPlanPortalSchedule($links + ['lessons' => $lessons, 'weeks' => $weeks, 'q' => $q, 'mode' => 'group',
        'label' => 'grupy ' . $gName, 'icsUrl' => $lessons ? tiPlanPortalUrl($self, $q + ['ics' => 1]) : '']);
    // Kontakty do prowadzących (opcja linku) i zmiany terminów
    $contacts = [];
    if (((int)$link['show_contacts'] && !$inDir) || $full) {
        $ids = array_values(array_unique(array_filter(array_merge([(int)($course['instructor_id'] ?? 0)], array_column($lessons, 'instr_id')))));
        if ($ids) $contacts = db_all('SELECT name, phone_number, email FROM users WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY name', $ids);
    }
    $moved = array_values(array_filter($lessons, fn($l) => $l['moved'] !== '' && $l['date'] >= date('Y-m-d')));
    if ($moved): ?>
    <section class="box" style="margin-top:12px"><h2>Zmiany terminów</h2><ul>
      <?php foreach ($moved as $l): ?><li><?= h($l['moved']) ?> → <strong><?= h(date('d.m.Y', strtotime($l['date']))) ?>, <?= h($l['time']) ?></strong><?= $l['room'] ? ', ' . h($l['room']) : '' ?></li><?php endforeach; ?>
    </ul></section>
    <?php endif;
    if ($contacts): ?>
    <section class="box" style="margin-top:12px"><h2>Kontakty do prowadzących</h2><ul>
      <?php foreach ($contacts as $c): $ph = preg_replace('/\D/', '', (string)$c['phone_number']); $ph = strlen($ph) === 9 ? '48' . $ph : $ph; $em = trim((string)$c['email']); ?>
      <li><strong><?= h(tiPlanPublicPerson((string)$c['name'])) ?></strong>:
        <?= $ph !== '' ? '<a href="tel:+' . h($ph) . '">+' . h(substr($ph, 0, 2) . ' ' . implode(' ', str_split(substr($ph, 2), 3))) . '</a>' : '' ?><?= $ph !== '' && $em !== '' ? ', ' : '' ?><?= $em !== '' ? '<a href="mailto:' . h($em) . '">' . h($em) . '</a>' : '' ?><?= $ph === '' && $em === '' ? 'brak danych kontaktowych' : '' ?></li>
      <?php endforeach; ?>
    </ul></section>
    <?php endif;
    tiPlanPortalClose($base);
    exit;
}

/* ───────────── Plan prowadzącego (tajny link) ───────────── */
if (isset($_GET['p']) || ($full && isset($_GET['u']))) {
    if (isset($_GET['p'])) {
        $tok  = (string)$_GET['p'];
        $link = tiPlanPublicInstrFindByToken($tok);
        if (!$link) $notFound('Link do planu prowadzącego wygasł, został wyłączony albo zawiera błąd.');
        $q = ['p' => $tok];
    } else {
        $uRow = db_one("SELECT id, name FROM users WHERE id=?", [(int)$_GET['u']]);
        if (!$uRow) $notFound('Nie ma takiego prowadzącego.');
        $link = (tiPlanPublicInstrGet((int)$uRow['id']) ?: ['id' => 0, 'weeks' => 12, 'note' => '', 'in_directory' => 0])
              + ['user_id' => (int)$uRow['id']];
        $link['user_name'] = (string)$uRow['name'];
        $q = ['u' => (int)$uRow['id']];
    }
    $weeks   = (int)$link['weeks'] ?: 12;
    $ini     = tiPlanPublicPerson((string)$link['user_name']);
    $inDir   = (int)$link['in_directory'] === 1 && !$full;
    $lessons = tiPlanPublicLessons(['instructor' => (int)$link['user_id']], $from, date('Y-m-d', strtotime("+{$weeks} weeks")));
    // Plan prowadzącego z katalogu publicznego: nazwy grup spoza katalogu ukryte (mogą zawierać nazwiska uczestników)
    if ($inDir) foreach ($lessons as $i => $l) {
        $lessons[$i]['url'] = '';   // adres publicznie znany — bez linków do spotkań online
        if (!isset($links['groupLinks'][$l['course_id']])) $lessons[$i] = $hideGroup($l);
    }
    if (isset($_GET['ics'])) $sendIcs($lessons, 'Plan zajęć — ' . $ini, 'p' . $link['user_id'], 'prowadzacy-' . $ini);
    if (isset($q['p'])) tiPlanPublicInstrTouch((int)$link['id']);
    $nGroups = count(array_unique(array_column(array_filter($lessons, fn($l) => !$l['cancelled']), 'course_id')));
    $sub = 'prowadzący · zajęcia we wszystkich grupach' . ($nGroups ? ' (' . $nGroups . ')' : '') . ', także zastępstwa';
    tiPlanPortalOpen($base + ['title' => 'Plan prowadzącego ' . $ini, 'heading' => $ini, 'sub' => h($sub), 'current' => tiPlanPortalUrl($self, $q)]);
    $nextBox(tiPlanPublicNext($lessons), (bool)$lessons);
    $noteBox((string)$link['note']);
    tiPlanPortalSchedule($links + ['lessons' => $lessons, 'weeks' => $weeks, 'q' => $q, 'mode' => 'instr',
        'label' => 'prowadzącego ' . $ini, 'icsUrl' => $lessons ? tiPlanPortalUrl($self, $q + ['ics' => 1]) : '']);
    tiPlanPortalClose($base);
    exit;
}

/* ───────────── Sala: zajętość i rezerwacje ───────────── */
if (isset($_GET['s'])) {
    // Sala „online” z rejestru = ta sama „Sala online” (jedna pozycja zamiast dwóch)
    if ((string)$_GET['s'] !== 'online' && in_array((int)$_GET['s'], tiPlanPublicOnlineRoomIds(), true)) {
        header('Location: ' . html_entity_decode(tiPlanPortalUrl($self, ['s' => 'online'] + array_intersect_key($_GET, ['t' => 1, 'v' => 1]))), true, 301); exit;
    }
    $isOnline = (string)$_GET['s'] === 'online';
    $room = !$rooms ? null : ($isOnline
        ? ['id' => 'online', 'name' => 'Sala online', 'short_label' => '', 'location' => 'zajęcia zdalne (Zoom / Teams) — nie wymaga rezerwacji sali', 'building' => '', 'building_address' => '', 'capacity' => 0]
        : tiPlanPublicRoom((int)$_GET['s']));
    if (!$room) $notFound('Nie ma takiej sali albo podgląd sal jest wyłączony.');
    $weeks   = 8;
    $lessons = tiPlanPublicLessons($isOnline ? ['online' => true] : ['room' => (int)$room['id']], $from, date('Y-m-d', strtotime("+{$weeks} weeks")));
    if (!$full) foreach ($lessons as $i => $l) { $lessons[$i]['url'] = ''; if (!isset($links['groupLinks'][$l['course_id']])) $lessons[$i] = $hideGroup($l); }
    $name = $roomName($room);
    $sub  = implode(' · ', array_filter([$name !== (string)$room['name'] ? (string)$room['name'] : '', (string)$room['location'], (string)($room['building'] ?? ''), (string)($room['building_address'] ?? ''), (int)$room['capacity'] ? 'miejsc: ' . (int)$room['capacity'] : '']));
    tiPlanPortalOpen($base + ['title' => $roomTitle($name), 'heading' => $roomTitle($name), 'sub' => h($sub), 'current' => tiPlanPortalUrl($self, ['s' => $room['id']])]);
    if ($isOnline) echo '<div class="box note" role="note"><strong>Zajęcia zdalne</strong> — odbywają się online (Zoom / Teams), nie wymagają rezerwacji sali. Link do spotkania uczestnicy dostają od prowadzącego lub w panelu kursanta.</div>';
    $pending = $isOnline ? 0 : count(array_filter($lessons, fn($l) => !$l['cancelled'] && !$l['room_ok'] && $l['room_id'] > 0 && $l['date'] >= date('Y-m-d')));
    if ($pending) echo '<div class="box note" role="note">Terminy oznaczone „rezerwacja do potwierdzenia” (' . $pending . ') czekają na potwierdzenie sali u operatora przestrzeni.</div>';
    tiPlanPortalSchedule($links + ['lessons' => $lessons, 'weeks' => $weeks, 'q' => ['s' => $room['id']], 'mode' => 'room', 'label' => 'sali ' . $name]);
    if (!$isOnline) echo '<p class="legend"><span class="st-ok"><strong>sala zarezerwowana</strong></span> — rezerwacja potwierdzona; <span class="st-warn"><strong>rezerwacja do potwierdzenia</strong></span> — zgłoszona, jeszcze niepotwierdzona</p>';
    tiPlanPortalClose($base);
    exit;
}

/* ───────────── DK: dyżury kierownika / Zarządu ───────────── */
if (($_GET['k'] ?? '') === 'dk') {
    if (!$dutyOn) $notFound('Grafik dyżurów nie jest publikowany.');
    tiPlanPortalOpen($base + ['title' => 'Dyżury kierownika i Zarządu', 'heading' => 'Dyżur kierownika / Zarządu',
        'sub' => h('kiedy i gdzie można zastać kierownika lub członka Zarządu'), 'current' => tiPlanPortalUrl($self, ['k' => 'dk'])]);
    tiDutyNowBox($self);
    tiDutyRenderWeek($self);
    echo '<p class="legend">Dyżurni oznaczeni inicjałami i funkcją; kontakt — telefon / e-mail dyżurowy. Zmiany w konkretnych dniach są oznaczone.</p>';
    tiPlanPortalClose($base);
    exit;
}

/* ───────────── Pełne listy jako kafelki ───────────── */
$k = (string)($_GET['k'] ?? '');
if (in_array($k, ['grupy', 'prowadzacy', 'sale'], true)) {
    $titles = ['grupy' => 'Grupy', 'prowadzacy' => 'Prowadzący', 'sale' => 'Sale'];
    if ($k === 'sale' && !$rooms) $notFound('Podgląd sal jest wyłączony.');
    tiPlanPortalOpen($base + ['title' => $titles[$k] . ' — plan zajęć', 'heading' => $titles[$k], 'current' => tiPlanPortalUrl($self, ['k' => $k])]);
    if (!$tiles[$k]) echo '<p class="empty">Brak pozycji do wyświetlenia.</p>';
    else $printTiles($k);
    tiPlanPortalClose($base);
    exit;
}

/* ───────────── Strona startowa ───────────── */
$orgName = trim((string)($org['name'] ?? ''));
tiPlanPortalOpen($base + ['title' => 'Plan zajęć', 'heading' => 'Plan zajęć', 'sub' => h($orgName)]);
if (!$full && !$dirG && !$dirI && !$rooms && !$dutyOn): ?>
  <p class="empty">Plan nie jest jeszcze publikowany w katalogu. Jeśli masz link do planu swojej grupy, otwórz go bezpośrednio.</p>
<?php else: ?>
  <p class="empty">Wybierz grupę, prowadzącego lub salę, aby zobaczyć plan zajęć tydzień po tygodniu.</p>
  <?php if ($dutyOn) tiDutyNowBox($self); ?>
  <?php foreach (['grupy', 'prowadzacy', 'sale'] as $_k) $printTiles($_k, 24); ?>
  <?php if ($dutyOn): $_n = tiDutyCurrentOrNext(); ?>
  <h2 class="tiles-head" id="h-dk"><span>DK</span></h2>
  <ul class="tiles" aria-labelledby="h-dk"><li><a class="tile t-duty" href="<?= tiPlanPortalUrl($self, ['k' => 'dk']) ?>"><strong>Dyżur kierownika / Zarządu</strong>
    <small><?= $_n ? h(($_n['now'] ? 'teraz: ' : 'najbliższy: ') . date('d.m', strtotime($_n['date'])) . ' ' . $_n['item']['time_from'] . '–' . $_n['item']['time_to'] . ', ' . tiDutyPersonLabel($_n['item'])) : 'grafik dyżurów' ?></small></a></li></ul>
  <?php endif; ?>
  <?php
  // Dziś i jutro we wszystkich grupach z katalogu — szybki podgląd jak tablica ogłoszeń
  $ids = $full ? array_map(fn($g) => (int)$g['id'], $allG) : array_map(fn($g) => (int)$g['course_id'], $dirG);
  $soon = ($full || $ids) ? array_values(array_filter(tiPlanPublicLessons($full ? ['all' => true] : ['courses' => $ids], date('Y-m-d'), date('Y-m-d', strtotime('+1 day'))), fn($l) => !$l['cancelled'])) : [];
  if (!$full) foreach ($soon as $i => $l) $soon[$i]['url'] = '';   // strona publiczna — bez linków do spotkań online
  if ($soon): $byDay = []; foreach ($soon as $l) $byDay[$l['date']][] = $l; ?>
  <h2 class="tiles-head">Dziś i jutro</h2>
  <div class="terms">
    <?php foreach ($byDay as $d => $ls): ?>
    <h3><?= $d === date('Y-m-d') ? 'Zajęcia dziś' : 'Zajęcia jutro' ?> — <?= h(TI_DAYS_PL_FULL[(int)date('N', strtotime($d))]) ?>, <?= h(date('d.m.Y', strtotime($d))) ?></h3>
    <?php foreach ($ls as $l): ?><div class="row"><div class="g"><?= h($l['time'] ?: '—') ?></div><?= tiPlanPortalCard($l, $links + ['mode' => 'all']) ?></div><?php endforeach; ?>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
<?php endif;
tiPlanPortalClose($base);
