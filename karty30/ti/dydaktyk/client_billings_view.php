<?php
/**
 * karty30/ti/dydaktyk/client_billings_view.php — podgląd wszystkich rozliczeń
 * kursanta (kierownik; z listy Kursanci → „Rozliczenia”). GET ?client_id=N
 *
 * Saldo konta i per grupa (także archiwalne), wszystkie rozliczenia ze
 * wszystkich okresów (z wycofanymi), historia wpłat. Każde rozliczenie
 * otwiera szczegóły w billing_view.php. Tylko odczyt, do wydruku.
 */
require_once __DIR__ . '/auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_payments.php';

$me = dyd_require();
if (!dyd_is_staff()) { http_response_code(403); die('Brak uprawnień.'); }

$client_id = (int)($_GET['client_id'] ?? 0);
$cl = $client_id ? db_one("SELECT id, name FROM k30_clients WHERE id=?", [$client_id]) : null;
if (!$cl) { http_response_code(404); die('Nie znaleziono kursanta.'); }

$months_pl = [1=>'styczeń','luty','marzec','kwiecień','maj','czerwiec','lipiec','sierpień','wrzesień','październik','listopad','grudzień'];
$zl  = fn(float $v): string => number_format($v, 2, ',', ' ') . ' zł';
$hrs = fn(float $v): string => rtrim(rtrim(number_format($v, 2, ',', ''), '0'), ',');

$alloc  = ti_client_allocation($client_id);
$paid   = []; foreach ($alloc['rows'] as $r) $paid[$r['id']] = $r['paid'];
$groups = ti_client_group_balances($client_id);
$c_stat = [];
foreach (db_all("SELECT id, status FROM k30_ti_courses WHERE id IN (SELECT DISTINCT course_id FROM k30_ti_billing WHERE client_id=?
                  UNION SELECT course_id FROM k30_ti_enrollments WHERE client_id=?)", [$client_id, $client_id]) as $r)
    $c_stat[(int)$r['id']] = (string)$r['status'];
$is_arch = fn(int $cid): bool => in_array($c_stat[$cid] ?? '', ['archived', 'cancelled'], true);

$billings = db_all(
    "SELECT b.*, c.name AS course_name FROM k30_ti_billing b LEFT JOIN k30_ti_courses c ON c.id=b.course_id AND b.course_id>0
      WHERE b.client_id=? AND b.status!='draft' ORDER BY b.year DESC, b.month DESC, b.id DESC", [$client_id]
);
$payments = ti_payments_for_client($client_id);
$mlabel   = ['transfer'=>'przelew','cash'=>'gotówka','stripe'=>'Stripe','payu'=>'PayU','p24'=>'Przelewy24','other'=>'inna','internal'=>'przeniesienie'];
$status_l = ['issued' => 'wystawione', 'paid' => 'opłacone', 'cancelled' => 'wycofane'];
?><!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Rozliczenia — <?= h($cl['name']) ?></title>
<style>
  :root { --ink:#1f2937; --muted:#6b7280; --line:#e5e7eb; --acc:#c2410c; --bg:#fff; --soft:#f9fafb; --bad:#b91c1c; --ok:#15803d; }
  * { box-sizing: border-box; }
  body { margin:0; background:var(--soft); color:var(--ink); font:14px/1.45 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
  .sheet { max-width:980px; margin:24px auto; background:var(--bg); border:1px solid var(--line); border-radius:10px; padding:28px 32px; }
  h1 { font-size:20px; margin:0 0 16px; }
  h2 { font-size:15px; margin:20px 0 4px; }
  .grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(170px, 1fr)); gap:10px; }
  .kpi { border:1px solid var(--line); border-radius:8px; padding:10px 12px; }
  .kpi .l { color:var(--muted); font-size:12px; } .kpi .v { font-size:17px; font-weight:600; }
  .bad { color:var(--bad); } .ok { color:var(--ok); } .mut { color:var(--muted); }
  .wrap { overflow-x:auto; }
  table { width:100%; border-collapse:collapse; margin:6px 0 10px; }
  th, td { text-align:left; padding:6px 8px; border-bottom:1px solid var(--line); vertical-align:top; }
  th { font-size:12px; color:var(--muted); font-weight:600; background:var(--soft); }
  td.r, th.r { text-align:right; white-space:nowrap; }
  tr.x td { color:var(--muted); text-decoration:line-through; }
  tr.x td.keep { text-decoration:none; }
  .tag { display:inline-block; border:1px solid var(--line); border-radius:999px; padding:0 7px; font-size:11.5px; margin-left:4px; color:var(--muted); }
  a { color:var(--acc); }
  .bar { display:flex; gap:8px; justify-content:flex-end; max-width:980px; margin:16px auto -8px; padding:0 4px; }
  .bar a, .bar button { font:inherit; font-size:13px; border:1px solid var(--line); background:#fff; border-radius:6px; padding:5px 12px; color:var(--ink); text-decoration:none; cursor:pointer; }
  .bar button { background:var(--acc); border-color:var(--acc); color:#fff; }
  @media (max-width:600px) { .sheet { margin:12px; padding:18px 16px; } }
  @media print { body { background:#fff; } .bar { display:none; } .sheet { border:0; margin:0; padding:0; max-width:none; } a { color:inherit; text-decoration:none; } }
</style>
</head>
<body>
<div class="bar">
  <a href="klienci.php">← Kursanci</a>
  <button type="button" onclick="window.print()">Drukuj</button>
</div>
<main class="sheet">
  <h1>Rozliczenia — <?= h($cl['name']) ?></h1>

  <div class="grid">
    <div class="kpi"><div class="l">Należności razem</div><div class="v"><?= $zl($alloc['charges']) ?></div></div>
    <div class="kpi"><div class="l">Wpłaty razem</div><div class="v ok"><?= $zl($alloc['payments']) ?></div></div>
    <div class="kpi"><div class="l">Do zapłaty</div><div class="v <?= $alloc['debt'] > 0.005 ? 'bad' : '' ?>"><?= $zl($alloc['debt']) ?></div></div>
    <div class="kpi"><div class="l">Nadpłata</div><div class="v <?= $alloc['credit'] > 0.005 ? 'ok' : '' ?>"><?= $zl($alloc['credit']) ?></div></div>
  </div>

  <h2>Saldo w grupach</h2>
  <div class="wrap"><table>
    <thead><tr><th>Grupa</th><th class="r">Należności</th><th class="r">Zapłacono</th><th class="r">Do zapłaty</th><th class="r">Nadpłata grupy</th></tr></thead>
    <tbody>
    <?php foreach ($groups['groups'] as $g): ?>
      <tr>
        <td><?= h($g['course_name']) ?><?= $is_arch((int)$g['course_id']) ? '<span class="tag">archiwum</span>' : '' ?></td>
        <td class="r"><?= $zl($g['charges']) ?></td>
        <td class="r"><?= $zl($g['paid']) ?></td>
        <td class="r <?= $g['debt'] > 0.005 ? 'bad' : 'mut' ?>"><?= $zl($g['debt']) ?></td>
        <td class="r <?= $g['credit'] > 0.005 ? 'ok' : 'mut' ?>"><?= $zl($g['credit']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($groups['general_credit'] > 0.005): ?>
      <tr><td>Nadpłata ogólna (nieprzypisana do grupy)</td><td></td><td></td><td></td><td class="r ok"><?= $zl($groups['general_credit']) ?></td></tr>
    <?php endif; ?>
    <?php if (!$groups['groups'] && $groups['general_credit'] <= 0.005): ?>
      <tr><td colspan="5" class="mut">Brak rozliczeń.</td></tr>
    <?php endif; ?>
    </tbody>
  </table></div>

  <h2>Rozliczenia</h2>
  <div class="wrap"><table>
    <thead><tr><th>Okres</th><th>Grupa</th><th class="r">Godz.</th><th class="r">Należność</th><th class="r">Zapłacono</th><th class="r">Do zapłaty</th><th>Termin</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($billings as $b):
        $x    = $b['status'] === 'cancelled';
        $due  = round((float)$b['amount'] + (float)($b['adjustment'] ?? 0), 2);
        $p    = $x ? 0.0 : (float)($paid[(int)$b['id']] ?? $b['paid_amount']);
        $left = $x ? 0.0 : round(max(0, $due - $p), 2);
        $cid  = (int)$b['course_id']; ?>
      <tr class="<?= $x ? 'x' : '' ?>">
        <td class="keep"><?= h(($months_pl[(int)$b['month']] ?? $b['month']) . ' ' . $b['year']) ?></td>
        <td><?= $cid > 0 ? h((string)($b['course_name'] ?? '?')) : 'łączne' ?><?= $cid > 0 && $is_arch($cid) ? '<span class="tag">archiwum</span>' : '' ?></td>
        <td class="r"><?= $hrs((float)$b['hours_billed']) ?></td>
        <td class="r"><?= $zl($due) ?><?= abs((float)($b['adjustment'] ?? 0)) > 0.005 ? '<div class="mut" style="font-size:11.5px" title="' . h((string)$b['adjustment_note']) . '">w tym korekta ' . $zl((float)$b['adjustment']) . '</div>' : '' ?></td>
        <td class="r"><?= $zl($p) ?></td>
        <td class="r <?= $left > 0.005 ? 'bad' : '' ?>"><?= $zl($left) ?></td>
        <td class="keep" style="white-space:nowrap"><?= !empty($b['due_date']) ? h(date('d.m.Y', strtotime((string)$b['due_date']))) : '—' ?></td>
        <td class="keep"><?= h($status_l[$b['status']] ?? $b['status']) ?><?= ($b['doc_mode'] ?? '') === 'statement' ? '<span class="tag">bez FVAT</span>' : '' ?><?= trim((string)($b['invoice_no'] ?? '')) !== '' ? '<span class="tag">FV ' . h($b['invoice_no']) . '</span>' : '' ?>
          <?php if ($x && ($b['cancel_reason'] ?? '') !== ''): ?><div class="mut" style="font-size:11.5px"><?= h($b['cancel_reason']) ?></div><?php endif; ?></td>
        <td class="keep r"><a href="billing_view.php?id=<?= (int)$b['id'] ?>">Podgląd</a></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$billings): ?><tr><td colspan="9" class="mut">Brak wystawionych rozliczeń.</td></tr><?php endif; ?>
    </tbody>
  </table></div>

  <h2>Wpłaty</h2>
  <div class="wrap"><table>
    <thead><tr><th>Data</th><th class="r">Kwota</th><th>Grupa</th><th>Metoda</th><th>Opis</th></tr></thead>
    <tbody>
    <?php foreach ($payments as $pm): $pcid = (int)($pm['course_id'] ?? 0); ?>
      <tr>
        <td style="white-space:nowrap"><?= h(date('d.m.Y', strtotime((string)($pm['paid_at'] ?: $pm['created_at'])))) ?></td>
        <td class="r <?= (float)$pm['amount'] < 0 ? 'mut' : 'ok' ?>"><?= (float)$pm['amount'] < 0 ? '−' : '+' ?><?= $zl(abs((float)$pm['amount'])) ?></td>
        <td><?= $pcid > 0 ? h($groups['groups'][$pcid]['course_name'] ?? ti_transfer_group_label($pcid)) : '<span class="mut">ogólna</span>' ?></td>
        <td><?= h($mlabel[$pm['method']] ?? (string)$pm['method']) ?></td>
        <td class="mut"><?= h((string)$pm['note']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$payments): ?><tr><td colspan="5" class="mut">Brak wpłat.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
  <p class="mut" style="font-size:12px;margin-top:18px">Wygenerowano <?= date('d.m.Y H:i') ?> · <?= h((string)($me['name'] ?? '')) ?></p>
</main>
</body>
</html>
