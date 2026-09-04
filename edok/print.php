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

$dekretacja = $doc['steps']['dekretacja'] ?? null;
$zatwierdza = $doc['steps']['zatwierdza'] ?? null;
$org        = defined('ORG_NAME') ? ORG_NAME : '';

function pr_row(string $label, string $val): string {
    if ($val === '') return '';
    return '<tr><td class="l">' . h($label) . '</td><td class="v">' . h($val) . '</td></tr>';
}
function pr_decision(?array $step): string {
    if (!$step || !in_array($step['status'], ['ok', 'uwagi', 'odrzucono'], true)) {
        return '<span class="badge-pending">OCZEKUJE NA DECYZJĘ</span>';
    }
    $label = match($step['status']) { 'ok' => 'TAK', 'uwagi' => 'Z UWAGAMI', 'odrzucono' => 'ODRZUCONO', default => $step['status'] };
    return '<span class="badge-' . h($step['status']) . '">' . h($label) . '</span>';
}
?>
<!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8">
<title>Karta dekretacji i zatwierdzenia — <?= h($doc['number']) ?></title>
<style>
  * { box-sizing: border-box; }
  body { font-family: Arial, Helvetica, sans-serif; color: #1a1a1a; margin: 0; padding: 24px; background: #fff; }
  .sheet { max-width: 620px; margin: 0 auto; border: 1.5px solid #333; padding: 18px 22px; }
  h1 { font-size: 16px; margin: 0 0 2px; letter-spacing: .3px; }
  .sub { color: #555; font-size: 12px; margin-bottom: 14px; }
  h2 { font-size: 12px; text-transform: uppercase; letter-spacing: .5px; color: #444; margin: 16px 0 6px; border-bottom: 1px solid #ccc; padding-bottom: 3px; }
  table { width: 100%; border-collapse: collapse; font-size: 13px; }
  td { padding: 3px 4px; vertical-align: top; }
  td.l { color: #555; width: 42%; }
  td.v { font-weight: 600; font-family: 'Courier New', monospace; }
  .amount-box { border: 1px solid #999; background: #f7f6f2; padding: 8px 10px; margin: 8px 0; }
  .badge-ok, .badge-uwagi, .badge-odrzucono, .badge-pending {
    display: inline-block; padding: 3px 10px; font-size: 12px; font-weight: 700; border-radius: 3px; letter-spacing: .5px;
  }
  .badge-ok { background: #dcfce7; color: #15803d; border: 1px solid #86efac; }
  .badge-uwagi { background: #fef9c3; color: #854d0e; border: 1px solid #fde047; }
  .badge-odrzucono { background: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; }
  .badge-pending { background: #f1f5f9; color: #64748b; border: 1px solid #cbd5e1; }
  .signoff { margin-top: 8px; font-size: 12px; color: #333; }
  .signoff .name { font-weight: 700; }
  .stamp { margin-top: 22px; padding-top: 10px; border-top: 1px dashed #999; font-size: 10.5px; color: #666; line-height: 1.5; }
  .noprint { margin: 14px auto; max-width: 620px; text-align: right; }
  @media print { .noprint { display: none; } body { padding: 0; } .sheet { border: none; max-width: 100%; } }
</style>
</head>
<body>

<div class="noprint">
  <button onclick="window.print()">Drukuj</button>
</div>

<div class="sheet">
  <h1><?= h($org ?: 'EODoK') ?> — Karta dekretacji i zatwierdzenia</h1>
  <div class="sub">Dokument <strong><?= h($doc['number']) ?></strong> · <?= h(EDOK_TYPES[$doc['typ_dokumentu']] ?? $doc['typ_dokumentu']) ?> · <?= h($doc['nr_faktury']) ?></div>

  <table>
    <?= pr_row('Kontrahent', $doc['kontrahent_nazwa']) ?>
    <?= pr_row('NIP', $doc['kontrahent_nip']) ?>
  </table>
  <div class="amount-box">
    <table>
      <tr><td class="l">Netto</td><td class="v"><?= h($doc['kwota_netto']) ?></td></tr>
      <tr><td class="l">VAT</td><td class="v"><?= h($doc['kwota_vat']) ?></td></tr>
      <tr><td class="l"><strong>Brutto</strong></td><td class="v"><?= h($doc['kwota_brutto']) ?> <?= h($doc['waluta']) ?></td></tr>
    </table>
  </div>

  <h2>Dekretacja i alokacja kosztów</h2>
  <table>
    <?= pr_row('Rodzaj działalności', EDOK_RODZAJ_DZIALALNOSCI[$doc['rodzaj_dzialalnosci']] ?? '—') ?>
    <?= pr_row('Projekt / działanie', $doc['projekt']) ?>
    <?= pr_row('MPK', $doc['mpk']) ?>
  </table>
  <div class="signoff">
    <?= pr_decision($dekretacja) ?>
    <?php if ($dekretacja && $dekretacja['decided_at']): ?>
    <div class="mt-1">Zadekretował: <span class="name"><?= h($dekretacja['user_name']) ?></span><?= $dekretacja['user_role'] ? ' (' . h($dekretacja['user_role']) . ')' : '' ?>, dnia <?= date_pl($dekretacja['decided_at']) ?> o <?= date('H:i', strtotime($dekretacja['decided_at'])) ?></div>
    <?php if ($dekretacja['notes']): ?><div>Uwagi: <?= h($dekretacja['notes']) ?></div><?php endif; ?>
    <?php endif; ?>
  </div>

  <h2>Zatwierdzenie końcowe do zapłaty i księgowania</h2>
  <div class="signoff">
    <?= pr_decision($zatwierdza) ?>
    <?php if ($zatwierdza && $zatwierdza['decided_at']): ?>
    <div class="mt-1">Zatwierdził: <span class="name"><?= h($zatwierdza['user_name']) ?></span><?= $zatwierdza['user_role'] ? ' (' . h($zatwierdza['user_role']) . ')' : '' ?>, dnia <?= date_pl($zatwierdza['decided_at']) ?> o <?= date('H:i', strtotime($zatwierdza['decided_at'])) ?></div>
    <?php if ($zatwierdza['notes']): ?><div>Uwagi: <?= h($zatwierdza['notes']) ?></div><?php endif; ?>
    <?php endif; ?>
  </div>

  <div class="stamp">
    Karta wygenerowana elektronicznie z systemu EODoK dnia <?= date('d.m.Y H:i') ?> przez <?= h(current_user()['name'] ?? '—') ?>.
    Identyfikatory osób decydujących, stemple czasowe i historia decyzji stanowią elektroniczny odpowiednik
    tradycyjnych pieczątek dekretacyjnych i zastępują je w pełni w obiegu elektronicznym dokumentu.
    Status dokumentu: <?= h(EDOK_STATUSES[$doc['status']]['label'] ?? $doc['status']) ?>.
  </div>
</div>

</body>
</html>
