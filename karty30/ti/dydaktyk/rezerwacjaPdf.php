<?php
/**
 * karty30/ti/dydaktyk/rezerwacjaPdf.php — karta terminu konsultacji do druku (przeniesione z karty30/schedules/pdf.php).
 * HTML z CSS do druku A4 (drukuje przeglądarka; „Zapisz jako PDF”), ?auto=1 — od razu okno drukowania. Wydruk w rejestrze RODO.
 */
require __DIR__ . '/_rezerwacje_nav.php';
$id = (int)($_GET['id'] ?? 0);
$r = $id ? TiBookings::get($id) : null;
if (!$r) { http_response_code(404); exit('Nie znaleziono rezerwacji.'); }
try { k30_log_access('schedule', $id, 'print', (string)$r['client_name']); } catch (\Throwable $e) {}
$ser = TiBookings::seriesInfo($r);
$st  = K30_SCHEDULE_STATUSES[$r['status']]['label'] ?? $r['status'];
$bt  = K30_BILLING_TYPES[$r['billing_type'] ?? 'free']['label'] ?? '';
$pst = $r['pfron_status'] ? (K30_PFRON_STATUSES[$r['pfron_status']]['label'] ?? $r['pfron_status']) : '';
$num = fn($v) => rtrim(rtrim(number_format((float)$v, 2, ',', ''), '0'), ',');
$rows = [
    ['Beneficjent', $r['client_name']], ['Data urodzenia', $r['client_dob'] ? date('d.m.Y', strtotime((string)$r['client_dob'])) : ''],
    ['Adres', $r['client_address']], ['Telefon', $r['client_phone']],
    ['Termin', date('d.m.Y H:i', strtotime((string)$r['start_time'])) . ' – ' . date('H:i', strtotime((string)$r['start_time']) + (int)$r['duration_minutes'] * 60) . ' (' . (int)$r['duration_minutes'] . ' min)'],
    ['Seria', $ser ? 'wizyta ' . $ser['index'] . ' z ' . $ser['total'] : ''],
    ['Miejsce', (int)$r['is_remote'] || !$r['resource_name'] ? 'zdalnie' : trim(($r['resource_cat'] ? $r['resource_cat'] . ' — ' : '') . $r['resource_name'])],
    ['Konsultant', trim(($r['consultant_name'] ?? '') . ($r['consultant_email'] ? ' (' . $r['consultant_email'] . ')' : ''))],
    ['Status', $st],
    ['Rozliczenie', $bt . ($r['pfron_number'] ? ' — umowa ' . $r['pfron_number'] : '') . ($pst !== '' ? ', PFRON: ' . $pst : '')],
    ['Godziny', $num($r['billed_hours']) . ' h (bezpłatne ' . $num($r['free_hours']) . ', płatne ' . $num($r['charged_hours']) . ')'],
    ['Do zapłaty', number_format((float)$r['amount_due'], 2, ',', ' ') . ' zł' . ($r['pricing_note'] ? ' — ' . $r['pricing_note'] : '')],
    ['Faktura', (int)$r['needs_invoice'] ? trim($r['invoice_name'] . ($r['invoice_nip'] ? ', NIP ' . $r['invoice_nip'] : '') . ', ' . $r['invoice_address']) : ''],
    ['Opis', $r['description']],
];
?><!doctype html>
<html lang="pl"><head><meta charset="utf-8"><title>Karta terminu — <?= h($r['client_name']) ?></title>
<style>
@page { size: A4; margin: 16mm; }
body { font-family: "DejaVu Sans", Arial, sans-serif; font-size: 11pt; color: #111; max-width: 180mm; margin: 0 auto; }
h1 { font-size: 16pt; margin: 0 0 2mm; } .org { color: #444; font-size: 9pt; margin-bottom: 5mm; }
table { border-collapse: collapse; width: 100%; } th, td { border: .3mm solid #666; padding: 2mm 2.5mm; text-align: left; vertical-align: top; }
th { width: 42mm; background: #eef2f7; } .sig { display: flex; gap: 20mm; margin-top: 18mm; } .sig div { flex: 1; border-top: .3mm solid #333; padding-top: 1.5mm; font-size: 9pt; text-align: center; }
.np { margin: 4mm 0; } @media print { .np { display: none; } }
</style></head>
<body>
<p class="np"><button onclick="window.print()">Drukuj / zapisz jako PDF</button> <a href="rezerwacja.php?id=<?= $id ?>">wróć</a></p>
<div class="org"><?= h(defined('ORG_NAME') ? ORG_NAME : '') ?> — konsultacje tyfloinformatyczne</div>
<h1>Karta terminu konsultacji</h1>
<table><tbody>
<?php foreach ($rows as [$a, $b]): if (trim((string)$b) === '') continue; ?><tr><th><?= h($a) ?></th><td><?= nl2br(h((string)$b)) ?></td></tr><?php endforeach; ?>
</tbody></table>
<div class="sig"><div>podpis beneficjenta</div><div>podpis konsultanta</div></div>
<p style="font-size:8pt;color:#555;margin-top:8mm">Wydruk: <?= date('d.m.Y H:i') ?>, rezerwacja #<?= $id ?></p>
<?php if (!empty($_GET['auto'])): ?><script>window.addEventListener('load', function () { window.print(); });</script><?php endif; ?>
</body></html>
