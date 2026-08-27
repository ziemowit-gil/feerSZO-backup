<?php
/**
 * karty30/ti/dydaktyk/rekrutacja_print.php — wydruki modułu Zapisy na zajęcia.
 *
 * ?what=siatka&round=X  — siatka godzin tury: tygodniowe tabele (dni × godziny)
 *                          z terminami prowadzących; prowadzący bez uprawnień
 *                          kierownika drukuje wyłącznie własne terminy.
 * ?what=kalendarz        — kalendarz naborów: wszystkie tury z datami zapowiedzi,
 *                          startu i końca zapisów (tylko kierownik).
 * ?what=plakat&round=X   — publiczny plakat tury (A4, do powieszenia): nabór
 *                          od–do, okres zajęć, liczba miejsc, koszt w żetonach,
 *                          prowadzący i instrukcja zapisu. Bez danych osobowych
 *                          kursantów i bez tokenów.
 * ?what=dostepnosci      — zestawienie okien dostępności wszystkich prowadzących
 *                          (dzień, godziny, obowiązywanie, status) z rubrykami
 *                          podpisu kierownika (tylko kierownik).
 *
 * Strona print-friendly: bez skórki panelu, przycisk drukowania ukrywany
 * w @media print.
 */
require_once __DIR__ . '/auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_planner_ext.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_rekrutacja.php';

karty30_migrate();
ti_planner_ext_migrate();
ti_rk_migrate();

$me       = dyd_require();
$uid      = (int)$me['user_id'];
$is_staff = dyd_is_staff();
$org      = defined('ORG_NAME') ? ORG_NAME : 'SZO';

$what = $_GET['what'] ?? 'siatka';
if (!in_array($what, ['siatka','kalendarz','plakat','dostepnosci'], true)) $what = 'siatka';
if (in_array($what, ['kalendarz','dostepnosci'], true) && !$is_staff) {
    http_response_code(403); die('Ten wydruk jest dostępny dla kierownika.');
}

$dow_names = [1=>'poniedziałek',2=>'wtorek',3=>'środa',4=>'czwartek',5=>'piątek',6=>'sobota',0=>'niedziela'];

/* ── Dane: siatka godzin ───────────────────────────────────────────────────── */
$round = null; $weeks = [];
if ($what === 'siatka') {
    $round_id = (int)($_GET['round'] ?? 0);
    $round    = $round_id ? rk_round_get($round_id) : null;
    if (!$round) { http_response_code(404); die('Nie znaleziono tury.'); }

    $where  = ["s.round_id = ?", "s.status IN ('open','locked','done')"];
    $params = [$round_id];
    if (!$is_staff) { $where[] = "s.instructor_id = ?"; $params[] = $uid; }

    $slots = db_all(
        "SELECT s.*, u.name AS instructor_name, r.name AS room_name,
                (s.capacity - s.seats_taken) AS seats_free
           FROM k30_rk_slots s
           JOIN users u ON u.id = s.instructor_id
           LEFT JOIN k30_pl_rooms r ON r.id = s.room_id
          WHERE " . implode(' AND ', $where) . "
          ORDER BY s.starts_at",
        $params
    );

    // Grupowanie: tydzień (poniedziałek) → dzień → sloty
    foreach ($slots as $s) {
        $t    = strtotime((string)$s['starts_at']);
        $mon  = strtotime('monday this week', $t);
        $day  = date('Y-m-d', $t);
        $weeks[date('Y-m-d', $mon)][$day][] = $s;
    }
    ksort($weeks);
}

/* ── Dane: kalendarz naborów ───────────────────────────────────────────────── */
$rounds = $what === 'kalendarz' ? rk_rounds_list() : [];

/* ── Dane: zestawienie dostępności do podpisu ──────────────────────────────── */
$av_rows = [];
if ($what === 'dostepnosci') {
    $av_rows = db_all(
        "SELECT a.*, u.name AS instructor_name
           FROM k30_ti_instructor_availability a
           JOIN users u ON u.id = a.instructor_id
          WHERE a.is_active = 1 AND u.is_active = 1
          ORDER BY u.name COLLATE NOCASE, (a.day_of_week + 6) % 7, a.time_from");
}

/* ── Dane: plakat publiczny ────────────────────────────────────────────────── */
$pl = null;
if ($what === 'plakat') {
    $round_id = (int)($_GET['round'] ?? 0);
    $round    = $round_id ? rk_round_get($round_id) : null;
    if (!$round) { http_response_code(404); die('Nie znaleziono tury.'); }

    $period = !empty($round['period_id'])
        ? db_one("SELECT name, date_from, date_to FROM k30_ti_periods WHERE id=?", [(int)$round['period_id']])
        : null;
    $stats = db_one(
        "SELECT COUNT(*) AS n_slots,
                COALESCE(SUM(capacity),0)               AS seats_total,
                COALESCE(SUM(capacity - seats_taken),0) AS seats_free,
                MIN(token_cost) AS cost_min, MAX(token_cost) AS cost_max,
                MIN(starts_at)  AS first_at, MAX(starts_at)  AS last_at
           FROM k30_rk_slots
          WHERE round_id = ? AND status = 'open'", [$round_id]);
    $pl_instructors = db_all(
        "SELECT DISTINCT u.name FROM k30_rk_slots s JOIN users u ON u.id = s.instructor_id
          WHERE s.round_id = ? AND s.status = 'open' ORDER BY u.name COLLATE NOCASE", [$round_id]);
    $pl = ['round' => $round, 'period' => $period, 'stats' => $stats,
           'instructors' => array_column($pl_instructors, 'name')];
}
?>
<!DOCTYPE html>
<html lang="pl" data-bs-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= match ($what) {
    'kalendarz'   => 'Kalendarz naborów',
    'plakat'      => 'Zapisy na zajęcia — ' . h($pl['round']['name']),
    'dostepnosci' => 'Dostępności prowadzących',
    default       => 'Siatka godzin — ' . h($round['name']),
} ?> — <?= h($org) ?></title>
<style>
  * { box-sizing: border-box; }
  body { font: 13px/1.5 -apple-system, "Segoe UI", Arial, sans-serif; color: #1a1a1a; margin: 24px; background: #fff; }
  h1 { font-size: 19px; margin: 0 0 2px; }
  h2 { font-size: 14px; margin: 22px 0 6px; }
  .muted { color: #666; }
  .meta { font-size: 11.5px; color: #666; margin-bottom: 16px; }
  table { border-collapse: collapse; width: 100%; margin-bottom: 14px; }
  th, td { border: 1px solid #bbb; padding: 4px 7px; vertical-align: top; text-align: left; }
  th { background: #f0f0f0; font-size: 11px; text-transform: uppercase; letter-spacing: .04em; }
  .slot { margin: 0 0 4px; padding: 2px 5px; border-left: 3px solid #c2410c; background: #faf5f1; font-size: 11.5px; }
  .slot .t { font-weight: 700; }
  .slot .who { display: block; }
  .slot .inf { color: #666; font-size: 10.5px; }
  .full { border-left-color: #888; background: #f2f2f2; }
  .cancelled { text-decoration: line-through; color: #999; }
  .badge { display: inline-block; border: 1px solid #bbb; border-radius: 3px; padding: 0 5px; font-size: 10.5px; }
  .toolbar { margin-bottom: 18px; display: flex; gap: 8px; }
  .toolbar button, .toolbar a { font: inherit; padding: 6px 14px; border: 1px solid #888; background: #f5f5f5; border-radius: 4px; cursor: pointer; text-decoration: none; color: #1a1a1a; }
  .sign { margin-top: 36px; display: flex; justify-content: space-between; font-size: 11.5px; color: #666; }
  .sign span { border-top: 1px dotted #999; padding-top: 4px; min-width: 200px; text-align: center; }
  @media print {
    body { margin: 8mm; font-size: 11.5px; }
    .toolbar { display: none; }
    h2 { break-after: avoid; }
    table { break-inside: auto; }
    tr { break-inside: avoid; }
  }
</style>
</head>
<body>

<div class="toolbar">
  <button onclick="window.print()">🖨 Drukuj</button>
  <a href="rekrutacja.php<?= $what === 'kalendarz' ? '?tab=tury' : '' ?>">← Wróć do zapisów</a>
</div>

<?php if ($what === 'siatka'): ?>

<h1>Siatka godzin — <?= h($round['name']) ?></h1>
<div class="meta">
  <?= h($org) ?> · nabór <?= h(rk_audience_kind_label((string)($round['audience_kind'] ?? 'continuing'))) ?>
  · zapisy od <?= h(rk_fmt_dt((string)$round['opens_at'])) ?><?= $round['closes_at'] ? ' do ' . h(rk_fmt_dt((string)$round['closes_at'])) : '' ?>
  · wydruk: <?= date('d.m.Y H:i') ?><?= $is_staff ? '' : ' · tylko moje terminy' ?>
</div>

<?php if (!$weeks): ?>
<p class="muted">Brak terminów w tej turze<?= $is_staff ? '' : ' (Twoich)' ?>.</p>
<?php else: ?>

<?php foreach ($weeks as $monday => $days):
    $mon_ts = strtotime($monday); ?>
<h2>Tydzień <?= date('d.m', $mon_ts) ?>–<?= date('d.m.Y', strtotime('+6 days', $mon_ts)) ?></h2>
<table>
  <thead>
    <tr>
      <?php for ($d = 0; $d < 7; $d++): $ts = strtotime("+$d days", $mon_ts); ?>
      <th style="width:14.28%"><?= h($dow_names[(int)date('w', $ts)]) ?><br><?= date('d.m', $ts) ?></th>
      <?php endfor; ?>
    </tr>
  </thead>
  <tbody>
    <tr>
      <?php for ($d = 0; $d < 7; $d++): $day = date('Y-m-d', strtotime("+$d days", $mon_ts)); ?>
      <td>
        <?php foreach ($days[$day] ?? [] as $s):
            $free = (int)$s['seats_free']; ?>
        <div class="slot <?= $free <= 0 ? 'full' : '' ?>">
          <span class="t"><?= h(substr((string)$s['starts_at'], 11, 5)) ?>–<?= h(substr((string)$s['ends_at'], 11, 5)) ?></span>
          <span class="who"><?= h($s['instructor_name']) ?></span>
          <span class="inf">
            <?= h($s['subject_label'] ?: 'konsultacja') ?>
            · <?= (int)$s['seats_taken'] ?>/<?= (int)$s['capacity'] ?> os.
            · <?= (int)$s['token_cost'] ?> żet.
            <?= $s['room_name'] ? '· ' . h($s['room_name']) : ($s['mode'] === 'online' ? '· online' : '') ?>
          </span>
        </div>
        <?php endforeach; ?>
      </td>
      <?php endfor; ?>
    </tr>
  </tbody>
</table>
<?php endforeach; ?>
<?php endif; ?>

<?php elseif ($what === 'dostepnosci'): ?>

<h1>Dostępności prowadzących — zestawienie do zatwierdzenia</h1>
<div class="meta"><?= h($org) ?> · okna tygodniowe z obowiązywaniem · wydruk: <?= date('d.m.Y H:i') ?></div>

<?php if (!$av_rows): ?>
<p class="muted">Brak aktywnych okien dostępności.</p>
<?php else: ?>
<table>
  <thead>
    <tr>
      <th>Prowadzący</th>
      <th>Dzień tygodnia</th>
      <th>Godziny</th>
      <th>Obowiązuje</th>
      <th>Status</th>
      <th style="width:120px">Podpis prowadzącego</th>
    </tr>
  </thead>
  <tbody>
    <?php $prev = ''; foreach ($av_rows as $w): ?>
    <tr>
      <td><?= $w['instructor_name'] !== $prev ? '<strong>' . h($w['instructor_name']) . '</strong>' : '' ?><?php $prev = $w['instructor_name']; ?></td>
      <td><?= h($dow_names[(int)$w['day_of_week']] ?? (string)$w['day_of_week']) ?></td>
      <td><strong><?= h(substr((string)$w['time_from'],0,5)) ?>–<?= h(substr((string)$w['time_to'],0,5)) ?></strong></td>
      <td>
        <?php if (!empty($w['valid_from']) && $w['valid_from'] === ($w['valid_to'] ?? '')): ?>
          jednorazowo <?= h($w['valid_from']) ?>
        <?php elseif (!empty($w['valid_from']) || !empty($w['valid_to'])): ?>
          <?= $w['valid_from'] ? 'od ' . h($w['valid_from']) : '' ?><?= $w['valid_to'] ? ' do ' . h($w['valid_to']) : '' ?>
        <?php else: ?>bezterminowo<?php endif; ?>
        <?= !empty($w['notes']) ? '<br><span class="muted">' . h($w['notes']) . '</span>' : '' ?>
      </td>
      <td><?= ($w['status'] ?? 'approved') === 'approved' ? 'zatwierdzona' : '<strong>szkic</strong>' ?></td>
      <td></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<p class="muted" style="font-size:11.5px">
  Szkice wymagają zatwierdzenia (panel kierownika › Dostępności prowadzących), zanim generator
  utworzy z nich terminy zapisów. Okna z zakresem dat obowiązują tylko we wskazanym okresie.
</p>
<?php endif; ?>

<?php elseif ($what === 'plakat'):
    $r      = $pl['round'];
    $st     = $pl['stats'];
    $period = $pl['period'];
    $z_from = $period['date_from'] ?? ($st['first_at'] ? substr((string)$st['first_at'], 0, 10) : '');
    $z_to   = $period['date_to']   ?? ($st['last_at']  ? substr((string)$st['last_at'], 0, 10) : '');
    $cost   = (int)($st['cost_min'] ?? 0) === (int)($st['cost_max'] ?? 0)
        ? (string)(int)($st['cost_min'] ?? 0)
        : (int)$st['cost_min'] . '–' . (int)$st['cost_max'];
    $fmt_d  = fn(?string $d) => $d ? date('d.m.Y', strtotime($d)) : '…';
?>
<style>
  /* Plakat A4 — duża typografia, oszczędny druk, jeden akcent */
  .poster { max-width: 760px; margin: 0 auto; text-align: center; }
  .poster .org { font-size: 15px; letter-spacing: .14em; text-transform: uppercase; color: #666; }
  .poster h1 { font-size: 44px; line-height: 1.15; margin: 10px 0 2px; }
  .poster .round-name { font-size: 26px; color: #c2410c; font-weight: 700; margin: 0 0 4px; }
  .poster .kind { display: inline-block; border: 2px solid #c2410c; color: #c2410c; border-radius: 999px;
                  padding: 3px 16px; font-weight: 700; font-size: 14px; text-transform: uppercase; letter-spacing: .06em; }
  .poster .dates { font-size: 22px; margin: 22px 0 4px; }
  .poster .dates strong { white-space: nowrap; }
  .poster .grid { display: flex; gap: 14px; justify-content: center; margin: 26px 0; }
  .poster .stat { border: 2px solid #1a1a1a; border-radius: 10px; padding: 14px 22px; min-width: 170px; }
  .poster .stat .num { font-size: 42px; font-weight: 800; line-height: 1.1; }
  .poster .stat .lbl { font-size: 13px; text-transform: uppercase; letter-spacing: .08em; color: #555; }
  .poster .who { font-size: 16px; margin: 10px 0 22px; }
  .poster .how { text-align: left; display: inline-block; border-top: 3px solid #c2410c; padding-top: 14px; margin-top: 6px; }
  .poster .how h2 { font-size: 18px; margin: 0 0 8px; }
  .poster .how ol { margin: 0; padding-left: 22px; font-size: 15.5px; line-height: 1.7; }
  .poster .note { font-size: 13px; color: #555; margin-top: 22px; }
  @media print { .poster h1 { font-size: 40px; } }
</style>

<div class="poster">
  <div class="org"><?= h($org) ?></div>
  <h1>Zapisy na zajęcia</h1>
  <p class="round-name"><?= h($r['name']) ?></p>
  <span class="kind">nabór <?= h(rk_audience_kind_label((string)($r['audience_kind'] ?? 'continuing'))) ?></span>

  <p class="dates">
    Zapisy: <strong><?= h(rk_fmt_dt((string)$r['opens_at'])) ?></strong>
    – <strong><?= $r['closes_at'] ? h(rk_fmt_dt((string)$r['closes_at'])) : 'do wyczerpania miejsc' ?></strong>
  </p>
  <p class="dates" style="font-size:19px">
    Zajęcia<?= $period ? ' (' . h($period['name']) . ')' : '' ?>:
    <strong><?= h($fmt_d($z_from)) ?></strong> – <strong><?= h($fmt_d($z_to)) ?></strong>
  </p>

  <div class="grid">
    <div class="stat">
      <div class="num"><?= (int)$st['seats_free'] ?></div>
      <div class="lbl">wolnych miejsc<br>(z <?= (int)$st['seats_total'] ?>)</div>
    </div>
    <div class="stat">
      <div class="num"><?= h($cost) ?></div>
      <div class="lbl"><?= (int)$st['cost_max'] === 1 && (int)$st['cost_min'] === 1 ? 'żeton' : 'żetonów' ?><br>za zajęcia</div>
    </div>
    <div class="stat">
      <div class="num"><?= (int)$st['n_slots'] ?></div>
      <div class="lbl">terminów<br>do wyboru</div>
    </div>
  </div>

  <?php if ($pl['instructors']): ?>
  <p class="who">Prowadzą: <strong><?= h(implode(', ', $pl['instructors'])) ?></strong></p>
  <?php endif; ?>

  <div class="how">
    <h2>Jak się zapisać</h2>
    <ol>
      <li>Zaloguj się do panelu kursanta i wejdź w <strong>Nauka › Zapisy na zajęcia</strong> —
          albo otwórz osobisty link z e-maila o starcie zapisów.</li>
      <li>Wybierz prowadzącego, potem termin z jego kalendarza.</li>
      <li>Kliknij <strong>„Rezerwuję”</strong> (pojedyncze zajęcia) albo
          <strong>„Ustal zajęcia na cały okres”</strong> — ten sam dzień i godzina co tydzień.
          Wybrana data to data pierwszych zajęć.</li>
    </ol>
  </div>

  <p class="note">
    Rezerwację opłacasz żetonami z puli przydzielonej przez ośrodek — dzięki nim każdy
    zapisuje się tam, gdzie faktycznie będzie, a miejsca nie są blokowane „na zapas”.
    Odpowiednio wczesna rezygnacja (do <?= (int)$r['refund_hours'] ?> godz. przed zajęciami)
    zwraca żetony w całości.<?= (int)$r['max_per_client'] > 0 ? ' Limit rezerwacji na osobę: ' . (int)$r['max_per_client'] . '.' : '' ?>
    <br>Stan na <?= date('d.m.Y H:i') ?>.
  </p>
</div>

<?php else: /* kalendarz naborów */ ?>

<h1>Kalendarz naborów</h1>
<div class="meta"><?= h($org) ?> · zapisy na zajęcia · wydruk: <?= date('d.m.Y H:i') ?></div>

<?php if (!$rounds): ?>
<p class="muted">Brak tur zapisów.</p>
<?php else: ?>
<table>
  <thead>
    <tr>
      <th>Tura</th>
      <th>Rodzaj naboru</th>
      <th>Zapowiedź e-mail</th>
      <th>Start zapisów</th>
      <th>Koniec zapisów</th>
      <th>Pula żetonów</th>
      <th>Limit/os.</th>
      <th>Zwrot do</th>
      <th style="text-align:right">Terminy</th>
      <th style="text-align:right">Rezerwacje</th>
      <th>Status</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($rounds as $r): ?>
    <tr>
      <td><strong><?= h($r['name']) ?></strong></td>
      <td><span class="badge"><?= h(rk_audience_kind_label((string)($r['audience_kind'] ?? 'continuing'))) ?></span></td>
      <td><?= $r['announce_at'] ? h(rk_fmt_dt((string)$r['announce_at'])) . ($r['announced_at'] ? ' ✓' : '') : '—' ?></td>
      <td><?= h(rk_fmt_dt((string)$r['opens_at'])) ?></td>
      <td><?= $r['closes_at'] ? h(rk_fmt_dt((string)$r['closes_at'])) : 'do wyczerpania' ?></td>
      <td><?= $r['pool_name'] ? h($r['pool_name']) : 'dowolna' ?></td>
      <td><?= (int)$r['max_per_client'] ?: 'bez limitu' ?></td>
      <td><?= (int)$r['refund_hours'] ?> h</td>
      <td style="text-align:right"><?= (int)$r['n_slots'] ?></td>
      <td style="text-align:right"><?= (int)$r['n_bookings'] ?></td>
      <td><?= h(match ((string)$r['status']) {
          'open' => 'otwarta', 'scheduled' => 'zaplanowana',
          'closed' => 'zamknięta', default => 'robocza' }) ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>

<?php endif; ?>

<?php if ($what !== 'plakat'): /* plakat publiczny — bez rubryk podpisów */ ?>
<div class="sign">
  <span>sporządził(a)</span>
  <span>zatwierdził(a) — kierownik</span>
</div>
<?php endif; ?>

</body>
</html>
