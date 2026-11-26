<?php
declare(strict_types=1);

/**
 * org/team_export.php?id=&format=csv|print|form&date=RRRR-MM-DD — skład zespołu z podstawą formalną.
 * Bez daty: skład bieżący; z datą: skład z historii członkostwa (team_member_history) na ten dzień.
 * Kolumny: osoba, e-mail, rola, od, do, umowa (rodzaj, numer RU / numer, status, okres) — BEZ kwot.
 * CSV: UTF-8 z BOM, średnik (Excel w polskich ustawieniach). Każde pobranie trafia do audit_log().
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/modules/teams/lib/TeamService.php';

require_login();
require_module_enabled('org_enabled', 'Moduł struktury organizacyjnej');
TeamService::migrate();

$id   = (int)($_GET['id'] ?? 0);
$team = TeamService::get($id);
if (!$team) { http_response_code(404); exit('Nie ma takiego zespołu.'); }
$format = (string)($_GET['format'] ?? 'csv');
$date   = (string)($_GET['date'] ?? '');
if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = '';

if ($format === 'form') {
    $PAGE_TITLE = 'Eksport składu — ' . $team['name'];
    include dirname(__DIR__) . '/includes/header.php'; ?>
    <nav aria-label="Ścieżka" class="small mb-2"><a href="<?= APP_URL ?>/org/index.php">Struktura organizacyjna</a> › <a href="<?= APP_URL ?>/org/teams.php">Zespoły</a> › <a href="<?= APP_URL ?>/org/team.php?id=<?= $id ?>"><?= h($team['name']) ?></a> › <span aria-current="page">Eksport</span></nav>
    <h1 class="h4 fw-bold">Skład zespołu na wybrany dzień</h1>
    <form method="get" class="row g-2 align-items-end" style="max-width:36rem">
      <input type="hidden" name="id" value="<?= $id ?>">
      <div class="col-sm-5"><label class="form-label" for="ex-date">Dzień</label><input type="date" id="ex-date" name="date" class="form-control" required value="<?= h(date('Y-m-d')) ?>"></div>
      <div class="col-sm-4"><label class="form-label" for="ex-fmt">Format</label>
        <select id="ex-fmt" name="format" class="form-select"><option value="csv">CSV (Excel)</option><option value="print">Do druku / PDF</option></select></div>
      <div class="col-sm-3"><button type="submit" class="btn btn-primary w-100">Pobierz</button></div>
    </form>
    <?php include dirname(__DIR__) . '/includes/footer.php';
    exit;
}

$rows = [];
$source = $date !== '' ? TeamService::membersAt($id, $date) : TeamService::members($id);
foreach ($source as $m) {
    $c = !empty($m['contract_id']) ? TeamService::contractInfo((string)$m['contract_type'], (int)$m['contract_id']) : null;
    $rows[] = [
        'Osoba' => (string)$m['name'],
        'E-mail' => (string)($m['email'] ?? ''),
        'Rola w zespole' => TeamService::ROLES[$m['role'] ?: 'member'] ?? (string)$m['role'],
        'W zespole od' => $date !== '' ? (string)$m['joined_at'] : substr((string)($m['added_at'] ?? ''), 0, 10),
        'W zespole do' => $date !== '' ? (string)($m['left_at'] ?? '') : '',
        'Rodzaj umowy' => $c['contract_type_label'] ?? '',
        'Nr rejestru' => $c['nr_rejestru'] ?? '',
        'Numer umowy' => $c['numer_umowy'] ?? '',
        'Stan umowy' => $c['health']['label'] ?? '',
        'Umowa od' => (string)($c['data_rozpoczecia'] ?? ''),
        'Umowa do' => (string)($c['data_zakonczenia'] ?? ''),
    ];
}
$contracts = array_values(array_filter(TeamService::getTeamContracts($id), fn($c) => $c['source'] === 'zespol'));

{
    try { audit_log('teams.export', ['team_id' => $id, 'format' => $format, 'date' => $date ?: null, 'rows' => count($rows)]); } catch (\Throwable $e) {}
}

$stamp = $date !== '' ? $date : date('Y-m-d');
$slug  = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string)($team['code'] ?: 'zespol-' . $id));

if ($format === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="sklad-' . $slug . '-' . $stamp . '.csv"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    $safe = fn($v) => preg_match('/^[=+\-@\t\r]/', (string)$v) ? "'" . $v : (string)$v; // bez formuł w Excelu
    fputcsv($out, ['Zespół', $team['name'], 'Kod', (string)$team['code'], 'Skład na dzień', $stamp], ';', '"', '');
    fputcsv($out, [], ';', '"', '');
    fputcsv($out, array_keys($rows[0] ?? ['Osoba' => '']), ';', '"', '');
    foreach ($rows as $r) fputcsv($out, array_map($safe, array_values($r)), ';', '"', '');
    if ($contracts) {
        fputcsv($out, [], ';', '"', '');
        fputcsv($out, ['Umowy przypięte do zespołu'], ';', '"', '');
        fputcsv($out, ['Rodzaj umowy', 'Nr rejestru', 'Numer umowy', 'Strona umowy', 'Stan', 'Od', 'Do', 'Zakres'], ';', '"', '');
        foreach ($contracts as $c) {
            fputcsv($out, array_map($safe, [$c['contract_type_label'], $c['nr_rejestru'], $c['numer_umowy'], $c['strona'], $c['health']['label'],
                $c['data_rozpoczecia'], $c['data_zakonczenia'], $c['zakres']]), ';', '"', '');
        }
    }
    fclose($out);
    exit;
}

// Do druku / PDF (okno drukowania przeglądarki)
?><!doctype html>
<html lang="pl"><head><meta charset="utf-8"><title>Skład zespołu — <?= h($team['name']) ?> — <?= h($stamp) ?></title>
<style>
body { font: 11pt/1.4 system-ui, -apple-system, "Segoe UI", sans-serif; color: #111; margin: 1.5cm; }
h1 { font-size: 16pt; margin: 0 0 .2rem; } h2 { font-size: 12pt; margin: 1.2rem 0 .4rem; }
.meta { color: #333; margin-bottom: 1rem; }
table { width: 100%; border-collapse: collapse; font-size: 9.5pt; }
th, td { border: 1px solid #999; padding: 3px 5px; text-align: left; vertical-align: top; }
th { background: #eee; }
.foot { margin-top: 1.5rem; font-size: 8.5pt; color: #444; }
@media print { .noprint { display: none; } body { margin: 0; } }
</style></head>
<body>
<p class="noprint"><button type="button" onclick="window.print()">Drukuj / zapisz PDF</button></p>
<h1><?= h($team['name']) ?><?= $team['code'] ? ' (' . h($team['code']) . ')' : '' ?></h1>
<div class="meta">
  Skład na dzień: <strong><?= h(date('d.m.Y', strtotime($stamp))) ?></strong> ·
  Typ: <?= h(TeamService::TYPES[$team['type']] ?? '') ?> · Status: <?= h(TeamService::STATUSES[$team['status']] ?? '') ?>
  <?php if ($team['unit_name']): ?> · Jednostka: <?= h($team['unit_name']) ?><?php endif; ?>
  <?php if ($team['type'] === 'project'): ?> · Okres: <?= h($team['starts_at'] ?: '…') ?> – <?= h($team['ends_at'] ?: '…') ?><?php endif; ?>
</div>
<?php if ($team['goal']): ?><p><strong>Cel:</strong> <?= nl2br(h($team['goal'])) ?></p><?php endif; ?>
<h2>Skład i podstawa formalna</h2>
<?php if (!$rows): ?><p>Brak osób w zespole w tym dniu.</p><?php else: ?>
<table><thead><tr><?php foreach (array_keys($rows[0]) as $hcol): ?><th scope="col"><?= h($hcol) ?></th><?php endforeach; ?></tr></thead>
<tbody><?php foreach ($rows as $r): ?><tr><?php foreach ($r as $v): ?><td><?= h($v) ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody></table>
<?php endif; ?>
<?php if ($contracts): ?>
<h2>Umowy przypięte do zespołu</h2>
<table><thead><tr><th scope="col">Rodzaj</th><th scope="col">Nr rejestru</th><th scope="col">Numer</th><th scope="col">Strona umowy</th><th scope="col">Stan</th><th scope="col">Okres</th><th scope="col">Zakres</th></tr></thead>
<tbody><?php foreach ($contracts as $c): ?><tr><td><?= h($c['contract_type_label']) ?></td><td><?= h($c['nr_rejestru']) ?></td><td><?= h($c['numer_umowy']) ?></td><td><?= h($c['strona']) ?></td><td><?= h($c['health']['label']) ?></td><td><?= h(($c['data_rozpoczecia'] ?: '…') . ' – ' . ($c['data_zakonczenia'] ?: '…')) ?></td><td><?= h($c['zakres']) ?></td></tr><?php endforeach; ?></tbody></table>
<?php endif; ?>
<p class="foot">Wygenerowano <?= h(date('d.m.Y H:i')) ?> przez <?= h(current_user()['name'] ?? '') ?> · System Wspomagania Zarządzania Organizacją</p>
<script>window.addEventListener('load', () => window.setTimeout(() => window.print(), 300));</script>
</body></html>
