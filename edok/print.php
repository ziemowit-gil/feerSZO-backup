<?php
require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/edok.php';

edok_require_access();
edok_migrate();

$id  = (int)($_GET['id'] ?? 0);
$doc = edok_get($id);
if (!$doc) { http_response_code(404); die('Dokument nie istnieje.'); }

$org = defined('ORG_NAME') ? ORG_NAME : '';

function pr_decision_label(?array $step): string {
    if (!$step || !in_array($step['status'], ['ok', 'uwagi', 'odrzucono'], true)) return 'OCZEKUJE';
    return match($step['status']) { 'ok' => 'TAK', 'uwagi' => 'Z UWAGAMI', 'odrzucono' => 'ODRZUCONO', default => $step['status'] };
}
function pr_who(?array $step): string {
    if (!$step || !$step['decided_at']) return '—';
    return h($step['user_name']) . ($step['user_role'] ? ' (' . h($step['user_role']) . ')' : '')
        . '<br>' . date_pl($step['decided_at']) . ' ' . date('H:i', strtotime($step['decided_at']));
}
?>
<!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8">
<title>Karta akceptacji dokumentu — <?= h($doc['number']) ?></title>
<style>
  @page { size: A4; margin: 10mm 12mm; }
  * { box-sizing: border-box; }
  body { font-family: Arial, Helvetica, sans-serif; color: #000; margin: 0; padding: 14px; background: #fff; font-size: 11px; line-height: 1.3; }
  .sheet { max-width: 700px; margin: 0 auto; }
  h1 { font-size: 13px; margin: 0 0 2px; font-weight: 700; }
  .sub { font-size: 10px; margin-bottom: 8px; }
  h2 { font-size: 10px; text-transform: uppercase; letter-spacing: .3px; margin: 8px 0 2px; border-bottom: 1px solid #000; padding-bottom: 1px; }
  table { width: 100%; border-collapse: collapse; }
  td, th { padding: 2px 4px; vertical-align: top; }
  .head-table td { border: none; padding: 1px 4px; }
  .head-table td.l { width: 15%; }
  .kwoty td { border-top: 1px solid #000; border-bottom: 1px solid #000; font-weight: 700; }
  .kwoty td.lbl { font-weight: 400; width: 12%; }
  .desc { border: 1px solid #000; padding: 3px 5px; margin: 2px 0 4px; min-height: 12px; }
  .steps { border-collapse: collapse; margin-top: 2px; }
  .steps th, .steps td { border: 1px solid #000; font-size: 10px; }
  .steps th { text-transform: uppercase; font-size: 8.5px; font-weight: 700; text-align: left; }
  .steps td.dec { text-align: center; font-weight: 700; white-space: nowrap; }
  .stamp { margin-top: 8px; padding-top: 4px; border-top: 1px solid #000; font-size: 8.5px; line-height: 1.35; }
  .noprint { margin: 10px auto; max-width: 700px; text-align: right; }
  @media print { .noprint { display: none; } body { padding: 0; } }
</style>
</head>
<body>

<div class="noprint">
  <button onclick="window.print()">Drukuj</button>
</div>

<div class="sheet">
  <h1><?= h($org ?: 'EODoK') ?> — Karta akceptacji dokumentu</h1>
  <div class="sub">Dokument <strong><?= h($doc['number']) ?></strong> · <?= h(EDOK_TYPES[$doc['typ_dokumentu']] ?? $doc['typ_dokumentu']) ?> · nr <?= h($doc['nr_faktury']) ?> · status: <?= h(EDOK_STATUSES[$doc['status']]['label'] ?? $doc['status']) ?></div>

  <table class="head-table">
    <tr><td class="l">Kontrahent</td><td><?= h($doc['kontrahent_nazwa']) ?></td><td class="l">NIP</td><td><?= h($doc['kontrahent_nip'] ?: '—') ?></td></tr>
  </table>

  <table class="kwoty">
    <tr>
      <td class="lbl">Netto</td><td><?= h($doc['kwota_netto'] ?: '—') ?></td>
      <td class="lbl">VAT</td><td><?= h($doc['kwota_vat'] ?: '—') ?></td>
      <td class="lbl">Brutto</td><td><?= h($doc['kwota_brutto'] ?: '—') ?> <?= h($doc['waluta']) ?></td>
    </tr>
  </table>

  <h2>Opis wydatku</h2>
  <div class="desc"><?= trim($doc['description']) !== '' ? nl2br(h($doc['description'])) : '—' ?></div>

  <h2>Dekretacja i alokacja kosztów</h2>
  <table class="head-table">
    <tr>
      <td class="l">Rodzaj działalności</td><td><?= h(EDOK_RODZAJ_DZIALALNOSCI[$doc['rodzaj_dzialalnosci']] ?? '—') ?></td>
      <td class="l">Projekt / MPK</td><td><?= h($doc['projekt'] ?: $doc['mpk'] ?: '—') ?></td>
    </tr>
  </table>

  <h2>Etapy akceptacji</h2>
  <table class="steps">
    <thead>
      <tr><th style="width:32%">Etap</th><th style="width:14%">Rodzaj akceptacji</th><th style="width:22%">Kto / kiedy</th><th>Opis / uwagi</th></tr>
    </thead>
    <tbody>
      <?php foreach (EDOK_STEPS as $sk => $sl): $s = $doc['steps'][$sk] ?? null; ?>
      <tr>
        <td><?= h($sl) ?></td>
        <td class="dec"><?= h(pr_decision_label($s)) ?></td>
        <td><?= pr_who($s) ?></td>
        <td><?= $s && trim((string)$s['notes']) !== '' ? nl2br(h($s['notes'])) : '—' ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <div class="stamp">
    Karta wygenerowana elektronicznie z systemu EODoK dnia <?= date('d.m.Y H:i') ?> przez <?= h(current_user()['name'] ?? '—') ?>.
    Identyfikatory osób decydujących, stemple czasowe i historia decyzji zastępują w pełni tradycyjne pieczątki dekretacyjne.
  </div>
</div>

</body>
</html>
