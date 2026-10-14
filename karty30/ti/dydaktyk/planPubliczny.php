<?php
/**
 * karty30/ti/dydaktyk/planPubliczny.php — Publiczny plan zajęć grupy TI (bez logowania).
 *
 * Dostęp przez tajny token w adresie (?g=<token>), jeden link na grupę — włączany,
 * wyłączany i regenerowany w panelu dydaktyka (planLinki.php). Pokazuje siatkę
 * tygodnia (jak wydruk „Siatka”) i listę nadchodzących terminów; bez danych
 * kursantów; z linkiem do zajęć online (link terminu albo stały link grupy, np. Zoom). Logika: modules/ti_plan_public/logic/.
 *
 * URL: <APP_URL>/karty30/ti/dydaktyk/planPubliczny.php?g=<token>
 *      https://ti.<domena>/plan/<token>   (skrót z .htaccess)
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/functions.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/karty30.php';
require_once dirname(dirname(dirname(__DIR__))) . '/modules/ti_plan_public/logic/planPublicManager.php';

header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');

$link = tiPlanPublicFindByToken((string)($_GET['g'] ?? ''));
if (!$link || in_array((string)$link['course_status'], ['cancelled', 'archived'], true)) {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>Plan niedostępny</title><style>body{font-family:system-ui,Segoe UI,Arial,sans-serif;margin:0;padding:48px 20px;color:#1e293b;background:#f8fafc}'
       . 'main{max-width:560px;margin:0 auto;background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:28px}h1{font-size:1.3rem;margin:0 0 12px}p{margin:0;color:#475569}</style></head>'
       . '<body><main><h1>Ten plan zajęć nie jest już dostępny</h1><p>Link wygasł, został wyłączony albo zawiera błąd. Poproś prowadzącego lub sekretariat o aktualny adres.</p></main></body></html>';
    exit;
}
tiPlanPublicTouch((int)$link['id']);

$D      = tiPlanPublicData($link);
$L      = $D['grid'];
$course = $L['course'] ?? null;
$org    = ti_org_contact_info();
$dowCols = [1,2,3,4,5,6,7];
$dowLbl  = TI_DAYS_PL_FULL;
$hasFlags = false;
foreach ($L['grid'] ?? [] as $row) foreach ($row as $c) if (($c['date_flag'] ?? '') !== '') { $hasFlags = true; break 2; }
$upcoming = array_values(array_filter($D['list'], fn($r) => !$r['cancelled']));
$cancelledN = count($D['list']) - count($upcoming);
$genAt = date('d.m.Y H:i');
// Szkolenie krótkie (SzK, kilka terminów): sama lista terminów, bez siatki tygodnia i „52 tyg.”
$short = (int)(db_one("SELECT simple_mode FROM k30_ti_courses WHERE id = ?", [(int)$link['course_id']])['simple_mode'] ?? 0) > 0;
$next  = null;
foreach ($upcoming as $r) { if ($r['date'] > date('Y-m-d') || ($r['date'] === date('Y-m-d') && substr($r['time'], 6, 5) >= date('H:i'))) { $next = $r; break; } }
$lastDate = $upcoming ? end($upcoming)['date'] : '';
$fmtPhone = function (string $p): string {   // 48535231923 → +48 535 231 923
    $d = preg_replace('/\D/', '', $p);
    if (strlen($d) === 11 && str_starts_with($d, '48')) return '+48 ' . implode(' ', str_split(substr($d, 2), 3));
    if (strlen($d) === 9) return implode(' ', str_split($d, 3));
    return $p;
};

// Plik kalendarza (.ics) z nadchodzącymi terminami — „Dodaj do kalendarza”
if (isset($_GET['ics'])) {
    $esc = fn(string $s) => str_replace(["\\", ';', ',', "\n"], ["\\\\", '\\;', '\\,', '\\n'], $s);
    $host = parse_url((string)(defined('APP_URL') ? APP_URL : ''), PHP_URL_HOST) ?: 'feer.org.pl';
    $out = ["BEGIN:VCALENDAR", "VERSION:2.0", "PRODID:-//FEER//Plan zajec TI//PL", "CALSCALE:GREGORIAN", "METHOD:PUBLISH",
            'X-WR-CALNAME:' . $esc('Plan zajęć — ' . $link['course_name']), "X-WR-TIMEZONE:Europe/Warsaw",
            // definicja strefy (Outlook jej wymaga przy TZID)
            "BEGIN:VTIMEZONE", "TZID:Europe/Warsaw",
            "BEGIN:DAYLIGHT", "TZOFFSETFROM:+0100", "TZOFFSETTO:+0200", "TZNAME:CEST", "DTSTART:19700329T020000", "RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=-1SU", "END:DAYLIGHT",
            "BEGIN:STANDARD", "TZOFFSETFROM:+0200", "TZOFFSETTO:+0100", "TZNAME:CET", "DTSTART:19701025T030000", "RRULE:FREQ=YEARLY;BYMONTH=10;BYDAY=-1SU", "END:STANDARD",
            "END:VTIMEZONE"];
    foreach ($upcoming as $i => $r) {
        if (!preg_match('/^(\d{2}):(\d{2})–(\d{2}):(\d{2})$/u', $r['time'], $t)) continue;
        $day = str_replace('-', '', $r['date']);
        $out[] = 'BEGIN:VEVENT';
        $out[] = 'UID:' . sha1($link['id'] . '|' . $r['date'] . '|' . $r['time']) . '@' . $host;
        $out[] = 'DTSTAMP:' . gmdate('Ymd\THis\Z');
        $out[] = "DTSTART;TZID=Europe/Warsaw:{$day}T{$t[1]}{$t[2]}00";
        $out[] = "DTEND;TZID=Europe/Warsaw:{$day}T{$t[3]}{$t[4]}00";
        $out[] = 'SUMMARY:' . $esc('Zajęcia — ' . $link['course_name']);
        $out[] = 'LOCATION:' . $esc($r['method'] === 'online' ? ($r['url'] ?: 'online') : ($r['room'] ?: (string)($course['location'] ?? '')));
        if ($r['url'] !== '') $out[] = 'URL:' . $r['url'];
        $out[] = 'DESCRIPTION:' . $esc('Forma: ' . ($r['method'] ?: '—') . ($r['url'] ? '. Link do zajęć: ' . $r['url'] : '') . ($r['instr'] ? '. Prowadzący: ' . rtrim($r['instr'], '.') : '') . '. Plan może ulec zmianie.');
        $out[] = 'END:VEVENT';
    }
    $out[] = 'END:VCALENDAR';
    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: attachment; filename="plan-' . preg_replace('/[^A-Za-z0-9._-]+/', '-', (string)$link['course_name']) . '.ics"');
    echo implode("\r\n", $out) . "\r\n";
    exit;
}
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: private, max-age=300');
?><!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Plan zajęć — <?= h($link['course_name']) ?><?= $org['name'] !== '' ? ' — ' . h($org['name']) : '' ?></title>
<style>
  :root { --ink:#0f172a; --muted:#475569; --line:#cbd5e1; --soft:#f1f5f9; --head:#1e293b; --accent:#0f766e; }
  * { box-sizing: border-box; }
  html { -webkit-text-size-adjust: 100%; }
  body { font-family: 'Segoe UI', system-ui, Arial, sans-serif; font-size: 15px; line-height: 1.45; color: var(--ink); margin: 0; background: #f8fafc; }
  a { color: #0c4a6e; }
  .skip { position:absolute; left:-9999px; } .skip:focus { left: 8px; top: 8px; background:#fff; padding:6px 10px; z-index: 10; }
  header.top { background: var(--head); color: #fff; padding: 18px 20px; }
  header.top .wrap { max-width: 1180px; margin: 0 auto; display: flex; justify-content: space-between; gap: 16px; align-items: flex-end; flex-wrap: wrap; }
  header.top h1 { margin: 0; font-size: 1.35rem; font-weight: 700; }
  header.top .sub { opacity: .85; font-size: .9rem; margin-top: 4px; }
  header.top .meta { font-size: .85rem; opacity: .85; text-align: right; }
  main { max-width: 1180px; margin: 0 auto; padding: 18px 20px 32px; }
  .toolbar { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-bottom: 14px; }
  .toolbar button { font: inherit; font-size: .9rem; padding: 7px 14px; border: 1px solid var(--head); background: var(--head); color: #fff; border-radius: 6px; cursor: pointer; min-height: 40px; }
  .toolbar button.secondary { background: #fff; color: var(--head); }
  .toolbar .range { color: var(--muted); font-size: .9rem; }
  .note { background: #fffbeb; border: 1px solid #f59e0b; border-left-width: 6px; border-radius: 6px; padding: 10px 14px; margin: 0 0 14px; color: #78350f; }
  h2 { font-size: 1.05rem; margin: 22px 0 8px; color: var(--head); border-bottom: 1px solid var(--line); padding-bottom: 4px; }
  .grid-scroll { overflow-x: auto; -webkit-overflow-scrolling: touch; background: #fff; border: 1px solid var(--line); border-radius: 6px; }
  table.plan { width: 100%; min-width: 760px; border-collapse: collapse; table-layout: fixed; }
  table.plan th, table.plan td { border: 1px solid var(--line); padding: 6px 7px; vertical-align: top; }
  table.plan thead th { background: var(--soft); font-size: .85rem; color: #334155; text-align: center; }
  table.plan .time-col { width: 7.5rem; background: #f8fafc; font-weight: 600; font-size: .85rem; text-align: center; white-space: nowrap; font-variant-numeric: tabular-nums; }
  .cell { overflow-wrap: anywhere; }
  .cell .subject { font-weight: 700; }
  .next { background: #fff; border: 1px solid var(--line); border-left: 6px solid var(--accent); border-radius: 8px; padding: 14px 18px; margin: 0 0 18px; display: flex; gap: 18px; align-items: center; flex-wrap: wrap; }
  .next .lbl { font-size: .8rem; text-transform: uppercase; letter-spacing: .04em; color: var(--accent); font-weight: 700; }
  .next .when { font-size: 1.35rem; font-weight: 700; line-height: 1.2; }
  .next .det { color: var(--muted); }
  .next .in { margin-left: auto; background: #ecfdf5; color: #065f46; border: 1px solid #6ee7b7; border-radius: 999px; padding: 4px 12px; font-weight: 700; white-space: nowrap; }
  .toolbar a.btn { font-size: .9rem; padding: 7px 14px; border: 1px solid var(--head); background: #fff; color: var(--head); border-radius: 6px; min-height: 40px; display: inline-flex; align-items: center; text-decoration: none; }
  table.list tr.is-next td { background: #ecfdf5; font-weight: 600; }
  .next a.join { display: inline-block; margin-top: 10px; background: #0b5cad; color: #fff; font-weight: 700; padding: 9px 18px; border-radius: 6px; text-decoration: none; min-height: 40px; }
  .next a.join:hover { background: #084a8c; text-decoration: underline; }
  .tag-next { display: inline-block; font-size: .72rem; font-weight: 700; color: #065f46; background: #d1fae5; border-radius: 999px; padding: 1px 8px; margin-left: 6px; }
  .cell .instr   { color: #334155; font-size: .88rem; }
  .cell .room    { color: var(--accent); font-size: .88rem; }
  .cell .valid   { color: #b45309; font-size: .75rem; margin-top: 2px; }
  .flag { display: inline-block; font-size: .72rem; font-weight: 700; margin-top: 3px; padding: 1px 6px; border-radius: 3px; }
  .flag-tentative { color: #92400e; background: #fef3c7; }
  .flag-change    { color: #075985; background: #e0f2fe; }
  .empty { color: #94a3b8; }
  table.list { width: 100%; border-collapse: collapse; background: #fff; border: 1px solid var(--line); border-radius: 6px; }
  table.list th, table.list td { border-bottom: 1px solid #e2e8f0; padding: 7px 9px; text-align: left; font-size: .92rem; vertical-align: top; }
  table.list thead th { background: var(--soft); font-size: .82rem; color: #334155; }
  table.list tr.cancelled td { color: #991b1b; text-decoration: line-through; background: #fff1f2; }
  table.list tr.cancelled td .st { text-decoration: none; font-weight: 700; }
  .cols { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
  .cols ul { margin: 0; padding-left: 18px; } .cols li { margin-bottom: 5px; font-size: .92rem; }
  .cols .name { font-weight: 600; } .cols .line { color: var(--muted); font-size: .85rem; }
  footer { max-width: 1180px; margin: 0 auto; padding: 0 20px 26px; color: #64748b; font-size: .8rem; }
  @media (max-width: 760px) { .cols { grid-template-columns: 1fr; } header.top .meta { text-align: left; } table.list th:nth-child(5), table.list td:nth-child(5) { display: none; } }
  @media print {
    body { background: #fff; font-size: 10.5pt; } .toolbar, .skip { display: none; }
    header.top, table.plan thead th, table.plan .time-col { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .grid-scroll, table.list { border: 0; } table.plan { min-width: 0; }
    @page { size: A4 landscape; margin: 10mm; }
  }
</style>
</head>
<body>
<a class="skip" href="#plan">Przejdź do planu</a>
<header class="top">
  <div class="wrap">
    <div>
      <h1>Plan zajęć — <?= h($link['course_name']) ?></h1>
      <div class="sub">Prowadzący: <?= h($course['instructor_name'] ?? '—') ?></div>
    </div>
    <div class="meta">
      <?php if ($org['name'] !== ''): ?><strong><?= h($org['name']) ?></strong><br><?php endif; ?>
      <?php if ($org['address'] !== ''): ?><?= h($org['address']) ?><br><?php endif; ?>
      <?php $oc = array_filter([$org['phone'], $org['email']]); if ($oc): ?><?= h(implode(' · ', $oc)) ?><br><?php endif; ?>
      Stan na <?= h($genAt) ?>
    </div>
  </div>
</header>

<main id="plan">
  <div class="toolbar">
    <button type="button" onclick="window.print()">Drukuj</button>
    <button type="button" class="secondary" onclick="location.reload()">Odśwież</button>
    <?php if ($upcoming): ?><a class="btn" href="?g=<?= h(urlencode((string)($_GET['g'] ?? ''))) ?>&amp;ics=1">Dodaj do kalendarza</a><?php endif; ?>
    <span class="range"><?php if ($short || ($lastDate !== '' && $lastDate < $D['to'])): ?>
      <?= count($upcoming) ?> <?= count($upcoming) === 1 ? 'termin' : (count($upcoming) % 10 >= 2 && count($upcoming) % 10 <= 4 && (count($upcoming) % 100 < 10 || count($upcoming) % 100 >= 20) ? 'terminy' : 'terminów') ?><?php if ($upcoming): ?>: od <?= h(date('d.m.Y', strtotime($upcoming[0]['date']))) ?> do <?= h(date('d.m.Y', strtotime($lastDate))) ?><?php endif; ?>
    <?php else: ?>Zakres: od <?= h(date('d.m.Y', strtotime($D['from']))) ?> do <?= h(date('d.m.Y', strtotime($D['to']))) ?><?php endif; ?></span>
  </div>

  <?php if ($next): $nd = (int)round((strtotime($next['date']) - strtotime(date('Y-m-d'))) / 86400); ?>
  <section class="next" aria-label="Najbliższe zajęcia">
    <div>
      <div class="lbl">Najbliższe zajęcia</div>
      <div class="when"><?= h($next['dow']) ?>, <?= h(date('d.m.Y', strtotime($next['date']))) ?> · <?= h($next['time'] ?: '—') ?></div>
      <div class="det"><?= h($next['method'] ?: '') ?><?= $next['room'] ? ' · ' . h($next['room']) : '' ?><?= $next['instr'] ? ' · prowadzi ' . h($next['instr']) : '' ?>
        <?php if ($next['method'] === 'online' && $next['url'] === ''): ?><br>Link do spotkania prowadzący poda przed zajęciami.<?php endif; ?></div>
      <?php if ($next['url'] !== ''): ?><a class="join" href="<?= h($next['url']) ?>" target="_blank" rel="noopener">Dołącz do zajęć online</a><?php endif; ?>
    </div>
    <span class="in"><?= $nd === 0 ? 'dziś' : ($nd === 1 ? 'jutro' : 'za ' . $nd . ' dni') ?></span>
  </section>
  <?php elseif (!$upcoming): ?>
  <section class="next" aria-label="Najbliższe zajęcia"><div><div class="lbl">Najbliższe zajęcia</div><div class="when">Brak zaplanowanych terminów</div><div class="det">Gdy pojawią się nowe terminy, zobaczysz je tutaj.</div></div></section>
  <?php endif; ?>

  <?php if (trim((string)$link['note']) !== ''): ?>
  <div class="note" role="note"><?= nl2br(h($link['note'])) ?></div>
  <?php endif; ?>

  <?php if (!$short): ?>
  <h2>Siatka tygodnia</h2>
  <?php if (!$L['time_slots']): ?>
    <p class="empty">Brak zaplanowanych terminów w tym okresie.</p>
  <?php else: ?>
  <div class="grid-scroll">
    <table class="plan">
      <caption class="skip">Plan zajęć grupy <?= h($link['course_name']) ?> — godziny w wierszach, dni tygodnia w kolumnach</caption>
      <thead><tr><th scope="col" class="time-col">Godzina</th><?php foreach ($dowCols as $d): ?><th scope="col"><?= h($dowLbl[$d]) ?></th><?php endforeach; ?></tr></thead>
      <tbody>
      <?php foreach ($L['time_slots'] as $tk): ?>
        <tr>
          <th scope="row" class="time-col"><?= h($tk) ?></th>
          <?php foreach ($dowCols as $d): $cell = $L['grid'][$tk][$d] ?? null; ?>
          <td>
            <?php if ($cell): ?>
            <div class="cell">
              <div class="subject"><?= h($cell['subject']) ?></div>
              <div class="instr"><?= h($cell['instructor']) ?></div>
              <?php if (($cell['room'] ?? '') !== '' && $cell['room'] !== '—'): ?><div class="room"><?= h($cell['room']) ?></div><?php endif; ?>
              <?php if ($L['multi_slot']): ?><div class="valid">obowiązuje: <?= h(date('d.m.Y', strtotime($cell['valid_from']))) ?>–<?= h(date('d.m.Y', strtotime($cell['valid_to']))) ?></div><?php endif; ?>
              <?php if (($cell['date_flag'] ?? '') === 'change_possible'): ?><div class="flag flag-change">Możliwa zmiana terminu</div>
              <?php elseif (($cell['date_flag'] ?? '') === 'tentative'): ?><div class="flag flag-tentative">Termin niepewny</div><?php endif; ?>
            </div>
            <?php else: ?><span class="empty" aria-label="brak zajęć">—</span><?php endif; ?>
          </td>
          <?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
  <?php endif; /* !$short */ ?>

  <?php if ((int)$link['show_list'] || $short): ?>
  <h2>Terminy zajęć</h2>
  <?php if (!$D['list']): ?>
    <p class="empty">Brak terminów w tym okresie.</p>
  <?php else: ?>
  <table class="list">
    <caption class="skip">Lista terminów zajęć<?= $upcoming ? ' od ' . h(date('d.m.Y', strtotime($upcoming[0]['date']))) . ' do ' . h(date('d.m.Y', strtotime($lastDate))) : '' ?></caption>
    <thead><tr><th scope="col">Data</th><th scope="col">Dzień</th><th scope="col">Godziny</th><th scope="col">Forma</th><th scope="col">Prowadzący</th><th scope="col">Sala</th></tr></thead>
    <tbody>
    <?php foreach ($D['list'] as $r): $isNext = $next && $r === $next; ?>
      <tr<?= $r['cancelled'] ? ' class="cancelled"' : ($isNext ? ' class="is-next"' : '') ?>>
        <td><?= h(date('d.m.Y', strtotime($r['date']))) ?><?= $r['cancelled'] ? ' <span class="st">(odwołane)</span>' : '' ?><?= $isNext ? '<span class="tag-next">najbliższe</span>' : '' ?></td>
        <td><?= h($r['dow']) ?></td>
        <td><?= h($r['time'] ?: '—') ?><?php if ($r['flag'] === 'tentative'): ?> <span class="flag flag-tentative">niepewny</span><?php elseif ($r['flag'] === 'change_possible'): ?> <span class="flag flag-change">możliwa zmiana</span><?php endif; ?></td>
        <td><?= h($r['method'] ?: '—') ?><?php if ($r['url'] !== '' && !$r['cancelled']): ?> · <a href="<?= h($r['url']) ?>" target="_blank" rel="noopener">dołącz<span class="skip"> do zajęć <?= h(date('d.m.Y', strtotime($r['date']))) ?></span></a><?php endif; ?></td>
        <td><?= h($r['instr'] ?: '—') ?></td>
        <td><?= h($r['room'] ?: '—') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if ($cancelledN): ?><p class="empty" style="font-size:.85rem">Odwołane terminy (<?= $cancelledN ?>) są przekreślone.</p><?php endif; ?>
  <?php endif; ?>
  <?php endif; ?>

  <div class="cols">
    <?php if ((int)$link['show_contacts']): ?>
    <div>
      <h2>Kontakty do prowadzących</h2>
      <?php if (!$L['instructors']): ?><p class="empty">Brak przypisanego prowadzącego.</p>
      <?php else: ?>
      <ul>
        <?php foreach ($L['instructors'] as $ins): $ic = [];
          if (trim((string)$ins['phone']) !== '') $ic[] = '<a href="tel:+' . h(preg_replace('/\D/', '', strlen(preg_replace('/\D/', '', (string)$ins['phone'])) === 9 ? '48' . $ins['phone'] : (string)$ins['phone'])) . '">' . h($fmtPhone((string)$ins['phone'])) . '</a>';
          if (trim((string)$ins['email']) !== '') $ic[] = '<a href="mailto:' . h($ins['email']) . '">' . h($ins['email']) . '</a>'; ?>
        <li><span class="name"><?= h($ins['name']) ?></span><br><span class="line"><?= $ic ? implode(' · ', $ic) : 'brak danych kontaktowych' ?></span></li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php if ($L['multi_slot'] || $hasFlags || $L['exceptions']): /* sekcja tylko, gdy jest co pokazać */ ?>
    <div>
      <h2>Uwagi</h2>
      <ul>
        <?php if ($L['multi_slot']): ?><li>Harmonogram grupy zmienia się w tym okresie — przy każdym terminie podano zakres dat, w którym obowiązuje.</li><?php endif; ?>
        <?php if ($hasFlags): ?><li>Etykiety „Termin niepewny” / „Możliwa zmiana terminu” dotyczą co najmniej jednej daty w danym slocie.</li><?php endif; ?>
        <?php foreach ($L['exceptions'] as $ex): ?>
        <li>Zmiana terminu: <?= h($ex['from_label']) ?> → <strong><?= h($ex['to_label']) ?></strong><?php if ($ex['room'] !== '—'): ?>, <span class="room"><?= h($ex['room']) ?></span><?php endif; ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>
  </div>
</main>
<footer>Plan może ulec zmianie — aktualna wersja zawsze pod tym adresem. Wygenerowano <?= h($genAt) ?>.</footer>
</body>
</html>
