<?php
/**
 * karty30/ti/dydaktyk/planPubliczny.php — Publiczny portal planu zajęć TI (bez logowania).
 *
 * Wygląd wzorowany na „planie lekcji online” szkoły: katalogi + tygodniowa siatka z kartami lekcji.
 *   (brak parametrów)  portal: kafle Grupy / Prowadzący / Sale
 *   ?k=grupy|prowadzacy|sale  katalogi (TYLKO wpisy oznaczone „w katalogu” w planLinki.php; Sale — przełącznik)
 *   ?g=<token>         plan grupy (dotychczasowy tajny link; działa też, gdy grupy nie ma w katalogu)
 *   ?p=<token>         plan prowadzącego (tajny link prowadzącego)
 *   ?s=<id sali>       zajętość i rezerwacje sali (gdy zakładka Sale włączona)
 *   &t=<data>          tydzień;  &v=lista  lista terminów;  &ics=1  plik kalendarza (grupa, prowadzący)
 * Krótkie adresy na ti.*: /plan/, /plan/grupy, /plan/prowadzacy, /plan/sale, /plan/<token>, /plan/p/<token>, /plan/s/<id>.
 * Logika: modules/ti_plan_public/logic/planPublicPortal.php; widok: modules/ti_plan_public/ui/.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/functions.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/karty30.php';
require_once dirname(dirname(dirname(__DIR__))) . '/modules/ti_plan_public/logic/planPublicPortal.php';
require_once dirname(dirname(dirname(__DIR__))) . '/modules/ti_plan_public/ui/portalLayout.php';
require_once dirname(dirname(dirname(__DIR__))) . '/modules/ti_plan_public/ui/portalSchedule.php';

header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-cache, private');   // publikacja w planLinki.php ma być widoczna od razu
tiPlanPublicMigrate();

$self  = (string)($_SERVER['SCRIPT_NAME'] ?? '/karty30/ti/dydaktyk/planPubliczny.php');
$org   = ti_org_contact_info();
$dirG  = tiPlanPublicDirectoryGroups();
$dirI  = tiPlanPublicDirectoryInstructors();
$rooms = tiPlanPublicRoomsEnabled();
$nav   = ['grupy' => (bool)$dirG, 'prowadzacy' => (bool)$dirI, 'sale' => $rooms];
$base  = ['self' => $self, 'org' => $org, 'nav' => $nav];
$home  = tiPlanPortalUrl($self);
$links = ['self' => $self, 'groupLinks' => tiPlanPublicDirectoryTokens(), 'instrLinks' => tiPlanPublicDirectoryInstrTokens(), 'roomLinks' => $rooms];

$notFound = function (string $what) use ($base, $home): void {
    http_response_code(404);
    tiPlanPortalOpen($base + ['title' => 'Plan niedostępny', 'crumbs' => [['Start', $home], ['Plan niedostępny', null]]]);
    echo '<h1 class="title">Ten plan nie jest już dostępny</h1><p class="subtitle">' . h($what) . '</p>'
       . '<p><a class="btn btn-primary" href="' . $home . '">Przejdź do strony głównej planu</a></p>';
    tiPlanPortalClose($base);
    exit;
};
$dayWord = fn(int $nd) => $nd === 0 ? 'dziś' : ($nd === 1 ? 'jutro' : 'za ' . $nd . ' dni');
$nextCard = function (?array $nx, bool $hasAny) use ($dayWord): void {
    if ($nx): $nd = (int)round((strtotime($nx['date']) - strtotime(date('Y-m-d'))) / 86400); ?>
    <section class="next-card" aria-label="Najbliższe zajęcia">
      <div>
        <div class="lbl">Najbliższe zajęcia</div>
        <div class="when"><?= h(TI_DAYS_PL_FULL[$nx['dow']]) ?>, <?= h(date('d.m.Y', strtotime($nx['date']))) ?> · <?= h($nx['time'] ?: '—') ?></div>
        <div class="det"><?= h($nx['subject']) ?><?= $nx['method'] ? ' · ' . h($nx['method']) : '' ?><?= $nx['room'] ? ' · ' . h($nx['room']) : '' ?><?= $nx['instr'] ? ' · prowadzi ' . h($nx['instr']) : '' ?>
          <?php if ($nx['method'] === 'online' && $nx['url'] === ''): ?><br>Link do spotkania prowadzący poda przed zajęciami.<?php endif; ?></div>
        <?php if ($nx['url'] !== ''): ?><p style="margin:8px 0 0"><a class="btn btn-primary" href="<?= h($nx['url']) ?>" target="_blank" rel="noopener">Dołącz do zajęć online</a></p><?php endif; ?>
      </div>
      <span class="in"><?= h($dayWord($nd)) ?></span>
    </section>
    <?php elseif (!$hasAny): ?>
    <section class="next-card" aria-label="Najbliższe zajęcia"><div><div class="lbl">Najbliższe zajęcia</div><div class="when">Brak zaplanowanych terminów</div><div class="det">Gdy pojawią się nowe terminy, zobaczysz je tutaj.</div></div></section>
    <?php endif;
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

/* ───────────── Plan grupy (tajny link) ───────────── */
if (isset($_GET['g'])) {
    $tok  = (string)$_GET['g'];
    $link = tiPlanPublicFindByToken($tok);
    if (!$link || in_array((string)$link['course_status'], ['cancelled', 'archived'], true)) $notFound('Link wygasł, został wyłączony albo zawiera błąd. Poproś prowadzącego lub sekretariat o aktualny adres.');
    $weeks   = (int)$link['weeks'] ?: 12;
    $cid     = (int)$link['course_id'];
    $lessons = tiPlanPublicLessons(['courses' => [$cid]], $from, date('Y-m-d', strtotime("+{$weeks} weeks")));
    if (isset($_GET['ics'])) $sendIcs($lessons, 'Plan zajęć — ' . $link['course_name'], 'g' . $link['id'], (string)$link['course_name']);
    tiPlanPublicTouch((int)$link['id']);
    $course = db_one("SELECT c.simple_mode, c.instructor_id, st.name AS subject_name, u.name AS instr_name FROM k30_ti_courses c
                        LEFT JOIN k30_ti_subject_types st ON st.id=c.subject_type_id LEFT JOIN users u ON u.id=c.instructor_id WHERE c.id=?", [$cid]) ?: [];
    // Szkolenie krótkie (kilka terminów): domyślnie lista terminów zamiast siatki tygodnia
    if ((int)($course['simple_mode'] ?? 0) > 0 && !isset($_GET['t']) && !isset($_GET['v'])) $_GET['v'] = 'lista';
    $instrTok = $links['instrLinks'][(int)($course['instructor_id'] ?? 0)] ?? null;
    $inDir    = (int)$link['in_directory'] === 1;
    tiPlanPortalOpen($base + ['title' => 'Plan zajęć — ' . $link['course_name'], 'active' => $inDir ? 'grupy' : '',
        'crumbs' => array_merge([['Start', $home]], $inDir ? [['Grupy', tiPlanPortalUrl($self, ['k' => 'grupy'])]] : [], [[(string)$link['course_name'], null]])]);
    ?>
    <h1 class="title"><?= h($link['course_name']) ?></h1>
    <p class="subtitle"><?= trim((string)($course['subject_name'] ?? '')) !== '' ? h($course['subject_name']) . ' · ' : '' ?>Prowadzący:
      <?php $ini = tiPlanPublicInitials((string)($course['instr_name'] ?? '')) ?: '—'; ?>
      <?= $instrTok ? '<a href="' . tiPlanPortalUrl($self, ['p' => $instrTok]) . '">' . h($ini) . '</a>' : h($ini) ?></p>
    <?php $nextCard(tiPlanPublicNext($lessons), (bool)$lessons); ?>
    <?php if (trim((string)$link['note']) !== ''): ?><div class="note" role="note"><?= nl2br(h($link['note'])) ?></div><?php endif; ?>
    <?php tiPlanPortalSchedule($links + ['lessons' => $lessons, 'weeks' => $weeks, 'q' => ['g' => $tok], 'mode' => 'group', 'hideGroups' => false,
        'label' => 'grupy ' . $link['course_name'], 'printTitle' => 'Plan zajęć — ' . $link['course_name'],
        'icsUrl' => $lessons ? tiPlanPortalUrl($self, ['g' => $tok, 'ics' => 1]) : '']); ?>
    <?php
    // Kontakty do prowadzących występujących w zakresie (opcja linku)
    $contacts = [];
    if ((int)$link['show_contacts']) {
        $ids = array_values(array_unique(array_filter(array_merge([(int)($course['instructor_id'] ?? 0)], array_column($lessons, 'instr_id')))));
        if ($ids) $contacts = db_all('SELECT name, phone_number, email FROM users WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY name', $ids);
    }
    $moved = array_values(array_filter($lessons, fn($l) => $l['moved'] !== '' && $l['date'] >= date('Y-m-d')));
    if ($contacts || $moved): ?>
    <div class="info-cols">
      <?php if ($contacts): ?>
      <section class="info-box"><h2>Kontakty do prowadzących</h2><ul>
        <?php foreach ($contacts as $c): $ph = preg_replace('/\D/', '', (string)$c['phone_number']); $ph = strlen($ph) === 9 ? '48' . $ph : $ph; ?>
        <li><strong><?= h(tiPlanPublicInitials((string)$c['name'])) ?></strong><br>
          <?= $ph !== '' ? '<a href="tel:+' . h($ph) . '">+' . h(substr($ph, 0, 2) . ' ' . implode(' ', str_split(substr($ph, 2), 3))) . '</a>' : '' ?>
          <?= $ph !== '' && trim((string)$c['email']) !== '' ? ' · ' : '' ?>
          <?= trim((string)$c['email']) !== '' ? '<a href="mailto:' . h($c['email']) . '">' . h($c['email']) . '</a>' : '' ?>
          <?= $ph === '' && trim((string)$c['email']) === '' ? 'brak danych kontaktowych' : '' ?></li>
        <?php endforeach; ?>
      </ul></section>
      <?php endif; ?>
      <?php if ($moved): ?>
      <section class="info-box"><h2>Zmiany terminów</h2><ul>
        <?php foreach ($moved as $l): ?><li><?= h($l['moved']) ?> → <strong><?= h(date('d.m.Y', strtotime($l['date']))) ?>, <?= h($l['time']) ?></strong><?= $l['room'] ? ', ' . h($l['room']) : '' ?></li><?php endforeach; ?>
      </ul></section>
      <?php endif; ?>
    </div>
    <?php endif;
    tiPlanPortalClose($base);
    exit;
}

/* ───────────── Plan prowadzącego (tajny link) ───────────── */
if (isset($_GET['p'])) {
    $tok  = (string)$_GET['p'];
    $link = tiPlanPublicInstrFindByToken($tok);
    if (!$link) $notFound('Link do planu prowadzącego wygasł, został wyłączony albo zawiera błąd.');
    $weeks   = (int)$link['weeks'] ?: 12;
    $ini     = tiPlanPublicInitials((string)$link['user_name']);
    $inDir   = (int)$link['in_directory'] === 1;
    $lessons = tiPlanPublicLessons(['instructor' => (int)$link['user_id']], $from, date('Y-m-d', strtotime("+{$weeks} weeks")));
    // Plan prowadzącego z katalogu publicznego: nazwy grup spoza katalogu ukryte (mogą zawierać nazwiska uczestników)
    if ($inDir) foreach ($lessons as $i => $l) if (!isset($links['groupLinks'][$l['course_id']])) $lessons[$i] = $hideGroup($l);
    if (isset($_GET['ics'])) $sendIcs($lessons, 'Plan zajęć — ' . $ini, 'p' . $link['id'], 'prowadzacy-' . $ini);
    tiPlanPublicInstrTouch((int)$link['id']);
    tiPlanPortalOpen($base + ['title' => 'Plan prowadzącego ' . $ini, 'active' => $inDir ? 'prowadzacy' : '',
        'crumbs' => array_merge([['Start', $home]], $inDir ? [['Prowadzący', tiPlanPortalUrl($self, ['k' => 'prowadzacy'])]] : [], [[$ini, null]])]);
    $nGroups = count(array_unique(array_column(array_filter($lessons, fn($l) => !$l['cancelled']), 'course_id')));
    ?>
    <h1 class="title">Prowadzący: <?= h($ini) ?></h1>
    <p class="subtitle">Zajęcia we wszystkich grupach<?= $nGroups ? ' (' . $nGroups . ' ' . ($nGroups === 1 ? 'grupa' : ($nGroups % 10 >= 2 && $nGroups % 10 <= 4 && ($nGroups % 100 < 10 || $nGroups % 100 >= 20) ? 'grupy' : 'grup')) . ')' : '' ?>, także zastępstwa.</p>
    <?php $nextCard(tiPlanPublicNext($lessons), (bool)$lessons); ?>
    <?php if (trim((string)$link['note']) !== ''): ?><div class="note" role="note"><?= nl2br(h($link['note'])) ?></div><?php endif; ?>
    <?php tiPlanPortalSchedule($links + ['lessons' => $lessons, 'weeks' => $weeks, 'q' => ['p' => $tok], 'mode' => 'instr', 'hideGroups' => $inDir,
        'label' => 'prowadzącego ' . $ini, 'printTitle' => 'Plan prowadzącego ' . $ini,
        'icsUrl' => $lessons ? tiPlanPortalUrl($self, ['p' => $tok, 'ics' => 1]) : '']);
    tiPlanPortalClose($base);
    exit;
}

/* ───────────── Sala: zajętość i rezerwacje ───────────── */
if (isset($_GET['s'])) {
    $room = $rooms ? tiPlanPublicRoom((int)$_GET['s']) : null;
    if (!$room) $notFound('Nie ma takiej sali albo podgląd sal jest wyłączony.');
    $weeks   = 8;
    $lessons = tiPlanPublicLessons(['room' => (int)$room['id']], $from, date('Y-m-d', strtotime("+{$weeks} weeks")));
    foreach ($lessons as $i => $l) { $lessons[$i]['url'] = ''; if (!isset($links['groupLinks'][$l['course_id']])) $lessons[$i] = $hideGroup($l); }
    $name = trim((string)$room['short_label']) !== '' ? (string)$room['short_label'] : (string)$room['name'];
    tiPlanPortalOpen($base + ['title' => $roomTitle($name), 'active' => 'sale',
        'crumbs' => [['Start', $home], ['Sale', tiPlanPortalUrl($self, ['k' => 'sale'])], [$name, null]]]);
    $pending = count(array_filter($lessons, fn($l) => !$l['cancelled'] && !$l['room_ok'] && $l['date'] >= date('Y-m-d')));
    ?>
    <h1 class="title"><?= h($roomTitle($name)) ?></h1>
    <p class="subtitle"><?= h(implode(' · ', array_filter([$name !== (string)$room['name'] ? (string)$room['name'] : '', (string)$room['location'], (string)($room['building'] ?? ''), (string)($room['building_address'] ?? ''), (int)$room['capacity'] ? 'miejsc: ' . (int)$room['capacity'] : '']))) ?></p>
    <?php if ($pending): ?><div class="note" role="note">Terminy z oznaczeniem „Rezerwacja do potwierdzenia” (<?= $pending ?>) czekają na potwierdzenie sali u operatora przestrzeni.</div><?php endif; ?>
    <?php tiPlanPortalSchedule($links + ['lessons' => $lessons, 'weeks' => $weeks, 'q' => ['s' => (int)$room['id']], 'mode' => 'room', 'hideGroups' => true,
        'label' => 'sali ' . $name, 'printTitle' => $roomTitle($name)]); ?>
    <div class="legend"><span class="tag tag-ok">Sala zarezerwowana</span> rezerwacja potwierdzona <span class="tag tag-warn">Rezerwacja do potwierdzenia</span> zgłoszona, jeszcze niepotwierdzona</div>
    <?php
    tiPlanPortalClose($base);
    exit;
}

/* ───────────── Katalogi ───────────── */
$k = (string)($_GET['k'] ?? '');
if (in_array($k, ['grupy', 'prowadzacy', 'sale'], true)) {
    $titles = ['grupy' => 'Grupy', 'prowadzacy' => 'Prowadzący', 'sale' => 'Sale'];
    if ($k === 'sale' && !$rooms) $notFound('Podgląd sal jest wyłączony.');
    tiPlanPortalOpen($base + ['title' => $titles[$k] . ' — plan zajęć', 'active' => $k, 'crumbs' => [['Start', $home], [$titles[$k], null]]]);
    $items = [];
    if ($k === 'grupy') foreach ($dirG as $g) $items[] = [
        'href' => tiPlanPortalUrl($self, ['g' => $g['token']]), 'name' => $g['name'],
        'note' => implode(' · ', array_filter([(string)$g['subject_name'], $g['instr'] ? 'prow. ' . $g['instr'] : '', $g['next_date'] ? 'najbliższe ' . date('d.m', strtotime((string)$g['next_date'])) : 'brak terminów']))];
    if ($k === 'prowadzacy') foreach ($dirI as $i) $items[] = [
        'href' => tiPlanPortalUrl($self, ['p' => $i['token']]), 'name' => $i['initials'],
        'note' => $i['groups'] ? implode(', ', array_slice($i['groups'], 0, 4)) . (count($i['groups']) > 4 ? ' …' : '') : 'plan zajęć'];
    if ($k === 'sale') foreach (tiPlanPublicDirectoryRooms(8) as $r) $items[] = [
        'href' => tiPlanPortalUrl($self, ['s' => $r['id']]), 'name' => trim((string)$r['short_label']) !== '' ? $r['short_label'] : $r['name'],
        'note' => implode(' · ', array_filter([trim((string)$r['short_label']) !== '' ? (string)$r['name'] : '', (string)($r['building'] ?: $r['location']), (int)$r['n_lessons'] . ' zaj. w 8 tyg.']))];
    $hints = ['grupy' => 'np. nazwa grupy albo przedmiot', 'prowadzacy' => 'np. inicjały albo grupa', 'sale' => 'np. nazwa sali albo budynek'];
    ?>
    <h1 class="title"><?= h($titles[$k]) ?></h1>
    <section data-search-root>
      <div class="dir-tools">
        <label class="search-field">Szukaj<input type="search" placeholder="<?= h($hints[$k]) ?>" autocomplete="off" spellcheck="false" data-search-input></label>
        <p class="dir-note" role="status" data-search-status>Wyników: <?= count($items) ?></p>
      </div>
      <?php if (!$items): ?><div class="empty-box">Brak pozycji do wyświetlenia.</div><?php endif; ?>
      <div class="dir-grid">
        <?php foreach ($items as $it): ?>
        <a class="dir-card" href="<?= $it['href'] ?>" data-search-item data-search-text="<?= h($it['name'] . ' ' . $it['note']) ?>"><strong><?= h($it['name']) ?></strong><span><?= h($it['note']) ?></span></a>
        <?php endforeach; ?>
      </div>
    </section>
    <?php
    tiPlanPortalClose($base);
    exit;
}

/* ───────────── Strona główna portalu ───────────── */
tiPlanPortalOpen($base + ['title' => 'Plan zajęć']);
$cards = [];
if ($dirG)  $cards[] = ['grupy', 'groups', 'Grupy', 'Wybierz grupę i sprawdź jej plan tydzień po tygodniu.', count($dirG) . ' ' . (count($dirG) === 1 ? 'grupa' : 'grup')];
if ($dirI)  $cards[] = ['prowadzacy', 'person', 'Prowadzący', 'Plan prowadzącego ze wszystkimi grupami i salami.', count($dirI) . ' os.'];
if ($rooms) $cards[] = ['sale', 'room', 'Sale', 'Zajętość sal i stan rezerwacji — kiedy sala jest zajęta, przez którą grupę.', ''];
?>
<h1 class="title">Plan zajęć</h1>
<p class="subtitle">Aktualny plan zajęć — terminy, sale i prowadzący. Zmiany widać od razu, bez rozsyłania nowych wydruków.</p>
<?php if (!$cards): ?>
<div class="empty-box">Plan nie jest jeszcze publikowany w katalogu. Jeśli masz link do planu swojej grupy, otwórz go bezpośrednio.</div>
<?php else: ?>
<div class="section-head"><h2>Wybierz widok planu</h2></div>
<section class="portal-grid">
  <?php foreach ($cards as $c): ?>
  <a class="portal-card" href="<?= tiPlanPortalUrl($self, ['k' => $c[0]]) ?>">
    <span class="ico"><?= tiPlanPortalIcon($c[1]) ?></span>
    <strong><?= h($c[2]) ?></strong>
    <p><?= h($c[3]) ?></p>
    <?php if ($c[4] !== ''): ?><span class="count"><?= h($c[4]) ?></span><?php endif; ?>
  </a>
  <?php endforeach; ?>
</section>
<?php endif;
tiPlanPortalClose($base);
