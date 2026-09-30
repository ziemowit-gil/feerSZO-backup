<?php
/**
 * karty30/ti/dydaktyk/billing_view.php — podgląd jednego rozliczenia kursanta
 * (kierownik, z listy Rozliczeń: „Podgląd” przy kursancie). GET ?id=N
 *
 * Zestawienie do wglądu i wydruku: okres, grupa, lekcje (data, godziny, stawka
 * z dnia lekcji), korekta z opisem, wpłaty przypisane, do zapłaty, termin,
 * dane do przelewu, tryb dokumentu i — dla wycofanych — kto/kiedy/dlaczego.
 * Tylko odczyt; lekcje liczone tak samo jak w k30_ti_calculate_billing().
 */
require_once __DIR__ . '/auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_payments.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_price_changes.php';

$me = dyd_require();
if (!dyd_is_staff()) { http_response_code(403); die('Brak uprawnień.'); }

$id = (int)($_GET['id'] ?? 0);
$b  = $id ? db_one(
    "SELECT b.*, cl.name AS client_name FROM k30_ti_billing b JOIN k30_clients cl ON cl.id=b.client_id WHERE b.id=?", [$id]
) : null;
if (!$b) { http_response_code(404); die('Nie znaleziono rozliczenia.'); }

// Korekta ceny lekcji (kierownik) — zapis jako zmiana ceny na jeden dzień (kind='lesson')
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = (string)($_POST['_op'] ?? '');
    if ($op === 'lesson_price') {
        $cid  = (int)($_POST['course_id'] ?? 0);
        $date = (string)($_POST['lesson_date'] ?? '');
        $hrs  = max(0.01, (float)($_POST['hours'] ?? 1));
        $val  = (float)str_replace([' ', ','], ['', '.'], (string)($_POST['value'] ?? ''));
        $rate = ($_POST['unit'] ?? 'h') === 'lesson' ? $val / $hrs : $val;
        $ok_course = (bool)db_one("SELECT 1 FROM k30_ti_enrollments WHERE client_id=? AND course_id=?", [(int)$b['client_id'], $cid]);
        $r = !$ok_course ? ['error' => 'Kursant nie należy do tej grupy.']
           : ti_lesson_price_correction($cid, ($_POST['scope'] ?? 'client') === 'course' ? null : (int)$b['client_id'],
                                        $date, $rate, (string)($_POST['reason'] ?? ''), (int)($me['user_id'] ?? 0) ?: null);
        flash_set(isset($r['error']) ? 'danger' : 'success', $r['error'] ?? $r['msg']);
    } elseif ($op === 'lesson_price_cancel') {
        $pc = ti_price_change_get((int)($_POST['pc_id'] ?? 0));
        if ($pc && ($pc['kind'] ?? '') === 'lesson') {
            ti_price_change_cancel((int)$pc['id']);
            $msg = 'Korekta ceny lekcji anulowana.';
            try { $msg .= ti_price_change_rebill_msg(ti_price_change_rebill((int)$pc['id'])); } catch (\Throwable $e) {}
            flash_set('success', $msg);
        } else flash_set('danger', 'Nie znaleziono korekty ceny lekcji.');
    }
    header('Location: billing_view.php?id=' . $id); exit;
}
$flash = flash_get();
$b = db_one("SELECT b.*, cl.name AS client_name FROM k30_ti_billing b JOIN k30_clients cl ON cl.id=b.client_id WHERE b.id=?", [$id]);

$months_pl = [1=>'styczeń','luty','marzec','kwiecień','maj','czerwiec','lipiec','sierpień','wrzesień','październik','listopad','grudzień'];
$client_id = (int)$b['client_id']; $m = (int)$b['month']; $y = (int)$b['year']; $bcourse = (int)$b['course_id'];
$from = sprintf('%04d-%02d-01', $y, $m); $to = date('Y-m-t', strtotime($from));
$zl   = fn(float $v): string => number_format($v, 2, ',', ' ') . ' zł';

// Stawki z dnia lekcji — z kalkulatora rozliczeń (jedno źródło prawdy)
$calc  = k30_ti_calculate_billing($client_id, $m, $y);
$calc_one = $bcourse > 0 ? k30_ti_calculate_billing($client_id, $m, $y, $bcourse) : $calc;
$rates = [];
foreach ($calc['courses'] as $cc) $rates[(int)$cc['course_id']] = $cc;

$lessons = db_all(
    "SELECT s.id AS session_id, s.course_id, c.name AS course_name, s.lesson_date, s.time_from, s.duration_min, s.topic, s.lesson_method,
            a.attended, COALESCE(a.no_show,0) AS no_show, a.no_show_billing
       FROM k30_ti_attendance a
       JOIN k30_ti_sessions s ON s.id=a.session_id AND s.status IN ('held','individual_change','remote_material')
       JOIN k30_ti_courses c ON c.id=s.course_id
      WHERE a.client_id=? AND s.lesson_date BETWEEN ? AND ? AND COALESCE(a.cancelled,0)=0
        AND (a.attended=1 OR COALESCE(a.no_show,0)=1)" . ($bcourse > 0 ? " AND s.course_id=?" : '') . "
      ORDER BY s.lesson_date, s.time_from",
    $bcourse > 0 ? [$client_id, $from, $to, $bcourse] : [$client_id, $from, $to]
);

$gb    = $bcourse > 0 ? ti_group_balance($client_id, $bcourse) : null;
$bal   = ti_client_balance($client_id);
$pay   = k30_ti_client_payment($client_id);
$due   = round((float)$b['amount'] + (float)($b['adjustment'] ?? 0), 2);
$paid  = (float)$b['paid_amount'];
$left  = round(max(0, $due - $paid), 2);
$cname = $bcourse > 0 ? (string)(db_one("SELECT name FROM k30_ti_courses WHERE id=?", [$bcourse])['name'] ?? '?') : 'rozliczenie łączne';
$status = ['issued' => 'wystawione', 'paid' => 'opłacone', 'cancelled' => 'wycofane', 'draft' => 'szkic'][$b['status']] ?? $b['status'];
$transfers = db_all("SELECT amount, paid_at, note FROM k30_ti_payments WHERE client_id=? AND source_type='transfer' AND source_id=? ORDER BY id", [$client_id, $id]);
?><!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Rozliczenie — <?= h($b['client_name']) ?> — <?= h($months_pl[$m] ?? $m) ?> <?= $y ?></title>
<style>
  :root { --ink:#1f2937; --muted:#6b7280; --line:#e5e7eb; --acc:#c2410c; --bg:#fff; --soft:#f9fafb; --bad:#b91c1c; --ok:#15803d; }
  * { box-sizing: border-box; }
  body { margin:0; background:var(--soft); color:var(--ink); font:14px/1.45 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
  .sheet { max-width:860px; margin:24px auto; background:var(--bg); border:1px solid var(--line); border-radius:10px; padding:28px 32px; }
  h1 { font-size:20px; margin:0 0 2px; }
  .sub { color:var(--muted); margin-bottom:18px; }
  .grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:10px; margin-bottom:18px; }
  .kpi { border:1px solid var(--line); border-radius:8px; padding:10px 12px; }
  .kpi .l { color:var(--muted); font-size:12px; } .kpi .v { font-size:17px; font-weight:600; }
  .bad { color:var(--bad); } .ok { color:var(--ok); }
  table { width:100%; border-collapse:collapse; margin:6px 0 18px; }
  th, td { text-align:left; padding:6px 8px; border-bottom:1px solid var(--line); vertical-align:top; }
  th { font-size:12px; color:var(--muted); font-weight:600; background:var(--soft); }
  td.r, th.r { text-align:right; white-space:nowrap; }
  tfoot td { font-weight:600; border-top:2px solid var(--ink); border-bottom:0; }
  h2 { font-size:15px; margin:18px 0 4px; }
  .note { color:var(--muted); font-size:12.5px; }
  .tag { display:inline-block; border:1px solid var(--line); border-radius:999px; padding:1px 8px; font-size:12px; margin-left:6px; }
  .warn { background:#fef2f2; border:1px solid #fca5a5; border-radius:8px; padding:8px 12px; margin-bottom:14px; }
  .bar { display:flex; gap:8px; justify-content:flex-end; max-width:860px; margin:16px auto -8px; padding:0 4px; }
  .bar a, .bar button { font:inherit; font-size:13px; border:1px solid var(--line); background:#fff; border-radius:6px; padding:5px 12px; color:var(--ink); text-decoration:none; cursor:pointer; }
  .bar button { background:var(--acc); border-color:var(--acc); color:#fff; }
  .msg { border-radius:8px; padding:8px 12px; margin-bottom:14px; border:1px solid #86efac; background:#f0fdf4; }
  .msg.warning { border-color:#fcd34d; background:#fffbeb; } .msg.danger { border-color:#fca5a5; background:#fef2f2; }
  details.lp summary { cursor:pointer; color:var(--acc); font-size:12px; list-style:none; }
  details.lp form { display:flex; flex-wrap:wrap; gap:6px; align-items:center; margin-top:6px; font-size:12.5px; }
  details.lp input, details.lp select { font:inherit; font-size:12.5px; padding:2px 6px; border:1px solid var(--line); border-radius:5px; }
  .btn { font:inherit; font-size:12px; border:1px solid var(--acc); background:#fff; color:var(--acc); border-radius:6px; padding:2px 9px; cursor:pointer; }
  .btn.pri { background:var(--acc); color:#fff; }
  @media print { details.lp, .btn, .msg, form { display:none !important; } }
  @media (max-width:600px) { .sheet { margin:12px; padding:18px 16px; } }
  @media print { body { background:#fff; } .bar { display:none; } .sheet { border:0; margin:0; padding:0; max-width:none; } }
</style>
</head>
<body>
<div class="bar">
  <a href="billing.php?month=<?= $m ?>&amp;year=<?= $y ?>">← Rozliczenia</a>
  <button type="button" onclick="window.print()">Drukuj</button>
</div>
<main class="sheet">
  <?php if ($flash): ?><div class="msg <?= h($flash['type']) ?>" role="status"><?= h($flash['msg']) ?></div><?php endif; ?>
  <h1><?= h($b['client_name']) ?></h1>
  <div class="sub">
    Rozliczenie za <?= h($months_pl[$m] ?? $m) ?> <?= $y ?> · <?= h($cname) ?>
    <span class="tag"><?= h($status) ?></span>
    <span class="tag"><?= ($b['doc_mode'] ?? '') === 'statement' ? 'tylko zestawienie, bez FVAT' : 'z fakturą VAT' ?></span>
    <?php if (trim((string)($b['invoice_no'] ?? '')) !== ''): ?><span class="tag">FV <?= h($b['invoice_no']) ?></span><?php endif; ?>
  </div>

  <?php if ($b['status'] === 'cancelled'): ?>
  <div class="warn">
    Rozliczenie wycofane<?= !empty($b['cancelled_at']) ? ' ' . h(date('d.m.Y H:i', strtotime((string)$b['cancelled_at']))) : '' ?><?= ($b['cancelled_by_name'] ?? '') !== '' ? ' przez ' . h($b['cancelled_by_name']) : '' ?>.
    <?php if (($b['cancel_reason'] ?? '') !== ''): ?>Powód: <?= h($b['cancel_reason']) ?><?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="grid">
    <div class="kpi"><div class="l">Należność</div><div class="v"><?= $zl($due) ?></div></div>
    <div class="kpi"><div class="l">Zaliczone wpłaty</div><div class="v ok"><?= $zl($paid) ?></div></div>
    <div class="kpi"><div class="l">Do zapłaty</div><div class="v <?= $left > 0.005 ? 'bad' : 'ok' ?>"><?= $zl($left) ?></div></div>
    <div class="kpi"><div class="l">Termin płatności</div><div class="v"><?= !empty($b['due_date']) ? h(date('d.m.Y', strtotime((string)$b['due_date']))) : '—' ?></div></div>
  </div>

  <h2>Lekcje</h2>
  <?php if (!$lessons): ?>
  <p class="note">Brak rozliczalnych lekcji w tym okresie<?= ((float)$b['amount'] > 0) ? ' — kwota wynika z ryczałtu / opłaty stałej' : '' ?>.</p>
  <?php else: ?>
  <table>
    <thead><tr><th>Data</th><?php if ($bcourse === 0): ?><th>Grupa</th><?php endif; ?><th>Temat</th><th class="r">Godz.</th><th class="r">Stawka</th><th class="r">Kwota</th></tr></thead>
    <tbody>
    <?php $sum_h = 0.0; $sum_a = 0.0;
    foreach ($lessons as $l):
        $cc     = $rates[(int)$l['course_id']] ?? null;
        $hourly = $cc ? !empty($cc['hourly']) : true;
        $hrs    = ((int)$l['attended'] === 0 && (int)$l['no_show'] === 1 && $l['no_show_billing'] === '1h') ? 1.0 : (float)ceil((int)$l['duration_min'] / 60);
        $rate   = $hourly ? (float)($cc['rate_by_session'][(int)$l['session_id']] ?? $cc['rate_by_date'][$l['lesson_date']] ?? $cc['hourly_rate'] ?? 0) : 0.0;
        $l_on   = ti_session_is_online((string)$l['lesson_method'], (int)$l['course_id']);
        $amt    = $hourly ? round($hrs * $rate, 2) : 0.0;
        $sum_h += $hrs; $sum_a += $amt; ?>
      <tr>
        <td class="r" style="text-align:left"><?= h(date('d.m.Y', strtotime((string)$l['lesson_date']))) ?><?= $l['time_from'] ? ' ' . h(substr((string)$l['time_from'], 0, 5)) : '' ?></td>
        <?php if ($bcourse === 0): ?><td><?= h($l['course_name']) ?></td><?php endif; ?>
        <td><?= h((string)$l['topic']) ?><?= $l_on ? ' <span class="tag">online</span>' : '' ?><?= (int)$l['attended'] === 0 ? ' <span class="note">(nieobecność nieusprawiedliwiona)</span>' : '' ?></td>
        <td class="r"><?= rtrim(rtrim(number_format($hrs, 2, ',', ''), '0'), ',') ?></td>
        <td class="r"><?= $hourly ? $zl($rate) : 'ryczałt' ?>
          <?php $lpc = $hourly ? ti_price_change_effective_on((int)$l['course_id'], $client_id, (string)$l['lesson_date']) : null;
          if ($lpc && ($lpc['kind'] ?? '') === 'lesson'): ?>
          <div class="note" title="<?= h($lpc['reason']) ?>">korekta<?= $lpc['client_id'] ? '' : ' (grupa)' ?>
            <form method="post" style="display:inline" onsubmit="return confirm('Anulować korektę ceny tej lekcji?')">
              <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="_op" value="lesson_price_cancel">
              <input type="hidden" name="pc_id" value="<?= (int)$lpc['id'] ?>"><button class="btn" style="padding:0 6px">anuluj</button>
            </form></div>
          <?php endif; ?></td>
        <td class="r"><?= $hourly ? $zl($amt) : '—' ?></td>
      </tr>
      <?php if ($hourly && $b['status'] !== 'cancelled'): $lk = 'lp' . md5($l['course_id'] . $l['lesson_date'] . $l['time_from']); ?>
      <tr><td colspan="<?= $bcourse === 0 ? 6 : 5 ?>" style="border-bottom:1px solid var(--line);padding-top:0">
        <details class="lp"><summary>Korekta ceny lekcji <?= h(date('d.m', strtotime((string)$l['lesson_date']))) ?></summary>
          <form method="post">
            <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="_op" value="lesson_price">
            <input type="hidden" name="course_id" value="<?= (int)$l['course_id'] ?>">
            <input type="hidden" name="lesson_date" value="<?= h((string)$l['lesson_date']) ?>">
            <input type="hidden" name="hours" value="<?= h((string)$hrs) ?>">
            <label for="<?= $lk ?>v">Nowa cena</label>
            <input id="<?= $lk ?>v" name="value" inputmode="decimal" size="7" value="<?= number_format($rate, 2, ',', '') ?>" required>
            <select name="unit" aria-label="Jednostka"><option value="h">zł/h</option><option value="lesson">zł za lekcję (<?= h((string)$hrs) ?> h)</option></select>
            <select name="scope" aria-label="Zakres"><option value="client">tylko ten kursant</option><option value="course">cała grupa tego dnia</option></select>
            <label for="<?= $lk ?>r">Uzasadnienie</label>
            <input id="<?= $lk ?>r" name="reason" size="28" maxlength="300" required minlength="5" placeholder="np. lekcja skrócona — ustalenie z opiekunem">
            <button class="btn pri">Zapisz</button>
          </form>
        </details>
      </td></tr>
      <?php endif; ?>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr><td colspan="<?= $bcourse === 0 ? 3 : 2 ?>">Razem</td><td class="r"><?= rtrim(rtrim(number_format($sum_h, 2, ',', ''), '0'), ',') ?></td><td></td><td class="r"><?= $sum_a > 0 ? $zl($sum_a) : '' ?></td></tr></tfoot>
  </table>
  <?php endif; ?>

  <h2>Kwota rozliczenia</h2>
  <table>
    <tbody>
      <tr><td>Zajęcia (<?= rtrim(rtrim(number_format((float)$b['hours_billed'], 2, ',', ''), '0'), ',') ?> h)<?php if (($b['manual_amount'] ?? null) !== null): ?>
        <div class="note">Kwota ręczna (rozliczenie indywidualne)<?= ($b['manual_note'] ?? '') !== '' ? ': ' . h($b['manual_note']) : '' ?><?= ($b['manual_by_name'] ?? '') !== '' ? ' — ' . h($b['manual_by_name']) : '' ?>; z cennika <?= $zl((float)$calc_one['amount']) ?></div><?php endif; ?></td><td class="r"><?= $zl((float)$b['amount']) ?></td></tr>
      <?php if (abs((float)($b['adjustment'] ?? 0)) > 0.005): ?>
      <tr><td>Korekta<?= ($b['adjustment_note'] ?? '') !== '' ? '<div class="note">' . h($b['adjustment_note']) . '</div>' : '' ?></td><td class="r"><?= ((float)$b['adjustment'] > 0 ? '+' : '') . $zl((float)$b['adjustment']) ?></td></tr>
      <?php endif; ?>
      <tr><td><strong>Należność</strong></td><td class="r"><strong><?= $zl($due) ?></strong></td></tr>
      <tr><td>Zaliczone wpłaty</td><td class="r">−<?= $zl($paid) ?></td></tr>
      <tr><td><strong>Do zapłaty</strong></td><td class="r"><strong class="<?= $left > 0.005 ? 'bad' : '' ?>"><?= $zl($left) ?></strong></td></tr>
    </tbody>
  </table>
  <?php if ($transfers): ?>
  <p class="note">Przeniesienia nadpłaty na to rozliczenie:
    <?php foreach ($transfers as $t) if ((float)$t['amount'] > 0) echo '<br>' . h(date('d.m.Y', strtotime((string)$t['paid_at']))) . ' · ' . $zl((float)$t['amount']) . ' · ' . h($t['note']); ?>
  </p>
  <?php endif; ?>

  <h2>Saldo kursanta</h2>
  <p class="note" style="font-size:13.5px;color:var(--ink)">
    <?php if ($gb): ?>
    W tej grupie: <?= $gb['credit'] > 0.005 ? '<span class="ok">nadpłata ' . $zl($gb['credit']) . '</span>' : ($gb['debt'] > 0.005 ? '<span class="bad">do zapłaty ' . $zl($gb['debt']) . '</span>' : 'rozliczone') ?>.
    <?php endif; ?>
    Całe konto: <?= $bal['debt'] > 0.005 ? '<span class="bad">do zapłaty ' . $zl($bal['debt']) . '</span>' : 'bez zaległości' ?><?= $bal['general_credit'] > 0.005 ? ', nadpłata ogólna ' . $zl($bal['general_credit']) : '' ?>.
  </p>

  <h2>Płatność</h2>
  <p class="note" style="font-size:13.5px;color:var(--ink)">
    Płatnik: <?= h(k30_ti_billing_payer_label($b)) ?><br>
    Rachunek: <?= h($pay['account'] ?: '—') ?><?php if (!empty($pay['title'])): ?><br>Tytuł przelewu: <?= h($pay['title']) ?><?php endif; ?>
  </p>
  <?php if (trim((string)($b['notes'] ?? '')) !== ''): ?>
  <h2>Uwagi</h2><p class="note"><?= nl2br(h((string)$b['notes'])) ?></p>
  <?php endif; ?>
  <p class="note" style="margin-top:22px">Wygenerowano <?= date('d.m.Y H:i') ?> · <?= h((string)($me['name'] ?? '')) ?></p>
</main>
</body>
</html>
