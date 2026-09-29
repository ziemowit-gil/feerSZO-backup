<?php
/**
 * karty30/ti/dydaktyk/client_ledger.php — historia pobrań za lekcje z salda
 * (kierownik). GET ?client_id=N&from=Y-m-d&to=Y-m-d[&pdf=1]
 * POST _op=send — wysyłka PDF + podsumowania do kursanta/opiekuna.
 * Logika: modules/ti_lesson_ledger/logic/lessonLedger.php.
 */
require_once __DIR__ . '/auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/modules/ti_lesson_ledger/logic/lessonLedger.php';

$me = dyd_require();
if (!dyd_is_staff()) { http_response_code(403); die('Brak uprawnień.'); }

$client_id = (int)($_GET['client_id'] ?? $_POST['client_id'] ?? 0);
$cl = $client_id ? db_one("SELECT id, name FROM k30_clients WHERE id=?", [$client_id]) : null;
if (!$cl) { http_response_code(404); die('Nie znaleziono kursanta.'); }

$okd  = fn($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$v) ? (string)$v : '';
$sy   = (int)date('Y') - ((int)date('n') < 9 ? 1 : 0);                 // rok szkolny: od 1 września
$req  = $_POST + $_GET;   // nie $_REQUEST — zależy od request_order w php.ini
$from = array_key_exists('from', $req) ? $okd($req['from']) : sprintf('%04d-09-01', $sy);
$to   = $okd($req['to'] ?? '');
$qs   = fn(array $extra = []) => http_build_query(array_merge(['client_id' => $client_id, 'from' => $from, 'to' => $to], $extra));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_op'] ?? '') === 'send') {
    csrf_check();
    $r = ti_lesson_ledger_send($client_id, $from, $to, (string)($me['name'] ?? ''));
    flash_set(is_int($r) ? 'success' : 'danger', is_int($r) ? "Historia pobrań wysłana ({$r} adres" . ($r === 1 ? '' : 'y') . ').' : $r);
    header('Location: client_ledger.php?' . $qs()); exit;
}
if (!empty($_GET['pdf'])) {
    $pdf = ti_lesson_ledger_pdf($client_id, $from, $to);
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="historia_pobran_' . $client_id . '.pdf"');
    echo $pdf; exit;
}
if (!empty($_GET['sent'])) {   // wysłany wcześniej PDF (z historii wysyłek)
    $s = db_one("SELECT file_path FROM k30_ti_ledger_sends WHERE id=? AND client_id=?", [(int)$_GET['sent'], $client_id]);
    $f = $s ? rtrim(UPLOAD_DIR, '/') . '/' . $s['file_path'] : '';
    if (!$s || !is_file($f)) { http_response_code(404); die('Plik niedostępny.'); }
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="historia_pobran_wyslana.pdf"');
    readfile($f); exit;
}

$L     = ti_lesson_ledger($client_id, $from, $to);
$rcp   = ti_lesson_ledger_recipients($client_id);
$sends = ti_lesson_ledger_sends($client_id);
$flash = flash_get();
$zl    = fn(float $v): string => number_format($v, 2, ',', ' ') . ' zł';
?><!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Historia pobrań — <?= h($cl['name']) ?></title>
<style>
  :root { --ink:#1f2937; --muted:#6b7280; --line:#e5e7eb; --acc:#c2410c; --bg:#fff; --soft:#f9fafb; --bad:#b91c1c; --ok:#15803d; }
  * { box-sizing: border-box; }
  body { margin:0; background:var(--soft); color:var(--ink); font:14px/1.45 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
  .sheet { max-width:980px; margin:24px auto; background:var(--bg); border:1px solid var(--line); border-radius:10px; padding:28px 32px; }
  h1 { font-size:20px; margin:0 0 12px; } h2 { font-size:15px; margin:20px 0 4px; }
  .grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(160px, 1fr)); gap:10px; margin:12px 0; }
  .kpi { border:1px solid var(--line); border-radius:8px; padding:10px 12px; } .kpi .l { color:var(--muted); font-size:12px; } .kpi .v { font-size:17px; font-weight:600; }
  .bad { color:var(--bad); } .ok { color:var(--ok); } .mut { color:var(--muted); }
  .wrap { overflow-x:auto; }
  table { width:100%; border-collapse:collapse; margin:6px 0 10px; }
  th, td { text-align:left; padding:5px 8px; border-bottom:1px solid var(--line); vertical-align:top; }
  th { font-size:12px; color:var(--muted); font-weight:600; background:var(--soft); }
  td.r, th.r { text-align:right; white-space:nowrap; }
  tr.unb td { background:#fffbeb; }
  .tools { display:flex; flex-wrap:wrap; gap:8px; align-items:end; font-size:13px; }
  .tools input { font:inherit; font-size:13px; padding:3px 6px; border:1px solid var(--line); border-radius:5px; }
  .btn { font:inherit; font-size:13px; border:1px solid var(--acc); background:#fff; color:var(--acc); border-radius:6px; padding:4px 11px; cursor:pointer; text-decoration:none; display:inline-block; }
  .btn.pri { background:var(--acc); color:#fff; }
  .msg { border-radius:8px; padding:8px 12px; margin-bottom:12px; border:1px solid #86efac; background:#f0fdf4; } .msg.danger { border-color:#fca5a5; background:#fef2f2; }
  .bar { display:flex; gap:8px; justify-content:flex-end; max-width:980px; margin:16px auto -8px; padding:0 4px; }
  .bar a, .bar button { font:inherit; font-size:13px; border:1px solid var(--line); background:#fff; border-radius:6px; padding:5px 12px; color:var(--ink); text-decoration:none; cursor:pointer; }
  @media (max-width:600px) { .sheet { margin:12px; padding:18px 16px; } }
  @media print { body { background:#fff; } .bar, .tools, .msg, .sends { display:none !important; } .sheet { border:0; margin:0; padding:0; max-width:none; } }
</style>
</head>
<body>
<div class="bar">
  <a href="client_billings_view.php?client_id=<?= $client_id ?>">← Rozliczenia kursanta</a>
  <a href="klienci.php">Kursanci</a>
</div>
<main class="sheet">
  <?php if ($flash): ?><div class="msg <?= h($flash['type']) ?>" role="status"><?= h($flash['msg']) ?></div><?php endif; ?>
  <h1>Historia pobrań za lekcje — <?= h($cl['name']) ?></h1>

  <form method="get" class="tools">
    <input type="hidden" name="client_id" value="<?= $client_id ?>">
    <label>Od<br><input type="date" name="from" value="<?= h($from) ?>"></label>
    <label>Do<br><input type="date" name="to" value="<?= h($to) ?>"></label>
    <button class="btn">Pokaż</button>
    <a class="btn" href="client_ledger.php?<?= h($qs(['pdf' => 1])) ?>" target="_blank" rel="noopener">PDF</a>
    <a class="btn" href="#" onclick="window.print();return false;">Drukuj</a>
  </form>

  <div class="grid">
    <div class="kpi"><div class="l">Saldo otwarcia</div><div class="v"><?= $zl($L['opening']) ?></div></div>
    <div class="kpi"><div class="l">Wpłaty</div><div class="v ok">+<?= $zl($L['paid_in']) ?></div></div>
    <div class="kpi"><div class="l">Pobrania za lekcje</div><div class="v bad">−<?= $zl($L['charged']) ?></div></div>
    <div class="kpi"><div class="l">Saldo na koniec</div><div class="v <?= $L['closing'] < -0.005 ? 'bad' : 'ok' ?>"><?= $L['closing'] < -0.005 ? 'do zapłaty ' . $zl(-$L['closing']) : $zl($L['closing']) ?></div></div>
  </div>

  <div class="wrap"><table>
    <thead><tr><th>Data</th><th>Grupa</th><th>Pozycja</th><th class="r">Godz.</th><th class="r">Stawka</th><th class="r">Kwota</th><th class="r">Saldo</th></tr></thead>
    <tbody>
    <?php foreach ($L['rows'] as $r): ?>
      <tr class="<?= $r['unbilled'] ? 'unb' : '' ?>">
        <td style="white-space:nowrap"><?= h(date('d.m.Y', strtotime($r['date']))) ?></td>
        <td><?= h($r['course']) ?></td>
        <td><?= h($r['label']) ?><?= $r['unbilled'] ? ' <span class="mut" title="Miesiąc jeszcze nierozliczony — kwota może się zmienić przy wystawieniu rozliczenia">· nierozliczone</span>' : '' ?></td>
        <td class="r"><?= $r['hours'] !== null ? h(rtrim(rtrim(number_format((float)$r['hours'], 2, ',', ''), '0'), ',')) : '' ?></td>
        <td class="r"><?= $r['rate'] !== null ? $zl((float)$r['rate']) : '' ?></td>
        <td class="r <?= $r['amount'] < 0 ? 'bad' : 'ok' ?>"><?= $r['amount'] < 0 ? '−' : '+' ?><?= $zl(abs($r['amount'])) ?></td>
        <td class="r"><?= $zl($r['balance']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$L['rows']): ?><tr><td colspan="7" class="mut">Brak operacji w tym okresie.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
  <p class="mut" style="font-size:12px">Każda odbyta lekcja (i płatna nieobecność) jest pobierana z salda po stawce z dnia lekcji, ryczałt — 1. dnia miesiąca.
    „Dopłata / zwrot wg rozliczenia” to różnica między wystawionym rozliczeniem a sumą lekcji (korekta, rabat, kwota ustalona indywidualnie).
    <?= $L['unbilled'] > 0.005 ? 'Lekcje z miesięcy nierozliczonych (' . $zl($L['unbilled']) . ') są podświetlone.' : '' ?></p>

  <section class="sends">
    <h2>Wysyłka do kursanta</h2>
    <?php if ($rcp): ?>
    <form method="post" onsubmit="return confirm('Wysłać historię pobrań (PDF) na: <?= h(addslashes(implode(', ', array_keys($rcp)))) ?>?')">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="_op" value="send">
      <input type="hidden" name="client_id" value="<?= $client_id ?>">
      <input type="hidden" name="from" value="<?= h($from) ?>">
      <input type="hidden" name="to" value="<?= h($to) ?>">
      <button class="btn pri">Wyślij historię pobrań</button>
      <span class="mut" style="font-size:13px">na: <?= h(implode(', ', array_keys($rcp))) ?> — podsumowanie w treści, pełna historia w PDF</span>
    </form>
    <?php else: ?>
    <p class="mut">Brak adresu e-mail kursanta (ani opiekuna małoletniego) — uzupełnij dane kursanta.</p>
    <?php endif; ?>

    <h2>Historia wysyłek</h2>
    <div class="wrap"><table>
      <thead><tr><th>Wysłano</th><th>Okres</th><th>Do</th><th class="r">Saldo</th><th>Wysłał(a)</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($sends as $s): ?>
        <tr>
          <td style="white-space:nowrap"><?= h(date('d.m.Y H:i', strtotime((string)$s['created_at']))) ?></td>
          <td><?= h(($s['date_from'] !== '' ? date('d.m.Y', strtotime($s['date_from'])) : 'od początku') . ' – ' . ($s['date_to'] !== '' ? date('d.m.Y', strtotime($s['date_to'])) : 'dziś')) ?></td>
          <td class="mut"><?= h($s['recipients']) ?></td>
          <td class="r"><?= $zl((float)$s['closing']) ?></td>
          <td><?= h($s['by_name']) ?></td>
          <td class="r"><a href="client_ledger.php?<?= h($qs(['sent' => (int)$s['id']])) ?>" target="_blank" rel="noopener">PDF</a></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$sends): ?><tr><td colspan="6" class="mut">Jeszcze nie wysyłano.</td></tr><?php endif; ?>
      </tbody>
    </table></div>
  </section>
  <p class="mut" style="font-size:12px;margin-top:18px">Wygenerowano <?= date('d.m.Y H:i') ?> · <?= h((string)($me['name'] ?? '')) ?></p>
</main>
</body>
</html>
