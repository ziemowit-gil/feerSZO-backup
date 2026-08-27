<?php
/**
 * karty30/ti/dydaktyk/rekrutacja_print.php — wydruki modułu Zapisy na zajęcia.
 *
 * ?what=siatka&round=X  — siatka godzin tury: tygodniowe tabele (dni × godziny)
 *                          z terminami prowadzących; prowadzący bez uprawnień
 *                          kierownika drukuje wyłącznie własne terminy.
 * ?what=kalendarz        — kalendarz naborów: wszystkie tury z datami zapowiedzi,
 *                          startu i końca zapisów (tylko kierownik).
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
if (!in_array($what, ['siatka','kalendarz'], true)) $what = 'siatka';
if ($what === 'kalendarz' && !$is_staff) { http_response_code(403); die('Kalendarz naborów drukuje kierownik.'); }

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
?>
<!DOCTYPE html>
<html lang="pl" data-bs-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= $what === 'kalendarz' ? 'Kalendarz naborów' : 'Siatka godzin — ' . h($round['name']) ?> — <?= h($org) ?></title>
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

<div class="sign">
  <span>sporządził(a)</span>
  <span>zatwierdził(a) — kierownik</span>
</div>

</body>
</html>
