<?php
/**
 * modules/log_control/index.php — Kontrola Dzienników (logów systemowych).
 *
 * Wynik ostatniej analizy: status PASS/WARNING/FAIL, przetworzone zdarzenia,
 * błędy krytyczne, rekomendacje, najczęstsze komunikaty, źródła dzienników,
 * historia przebiegów. NIE mylić z „Audytem dzienników” w panelu dydaktyka TI
 * (tam chodzi o dzienniki zajęć). Ustawienia i wysyłki: modules/it_control/index.php.
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__) . '/it_control/logic/itControlRunner.php';
require_once dirname(__DIR__) . '/it_control/_ui.php';
require_once __DIR__ . '/logic/logControlReader.php';

require_role('admin');
itControlMigrate();

$BASE = APP_URL . '/modules/log_control/index.php';
$IT   = APP_URL . '/modules/it_control/index.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'run') {
    csrf_check();
    try {
        $me = current_user();
        $run = itControlRunSuite('logs', new ItControlContext(itControlSettings()), 'manual', (int)($me['id'] ?? 0) ?: null);
        flash_set('success', 'Kontrola Dzienników: ' . $run['status'] . ', przetworzono ' . $run['events_processed'] . ' zdarzeń.');
    } catch (\Throwable $e) {
        flash_set('error', 'Błąd kontroli: ' . $e->getMessage());
    }
    header('Location: ' . $BASE);
    exit;
}

$settings = itControlSettings();
$run   = itControlLatestRun('logs');
$files = logControlLogFiles($settings);
$hist  = itControlHistory('logs', 30);
$PAGE_TITLE = 'Kontrola Dzienników';
include dirname(__DIR__, 2) . '/includes/header.php';
?>
<div class="container-fluid px-0" style="max-width:1200px">
  <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
    <h1 class="h3 mb-0 me-auto"><i class="bi bi-journal-text me-2" aria-hidden="true"></i>Kontrola Dzienników</h1>
    <a class="btn btn-outline-secondary btn-sm" href="<?= h($IT) ?>"><i class="bi bi-shield-check me-1" aria-hidden="true"></i>Pulpit Kontroli IT</a>
    <a class="btn btn-outline-secondary btn-sm" href="<?= h($IT . '?tab=settings') ?>"><i class="bi bi-sliders me-1" aria-hidden="true"></i>Ustawienia</a>
    <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="_action" value="run">
      <button class="btn btn-primary btn-sm"><i class="bi bi-play-fill me-1" aria-hidden="true"></i>Analizuj teraz</button></form>
  </div>
  <p class="text-muted small">Automatyczna analiza dzienników aplikacji i serwera: błędy PHP, wyjście agentów CRON, nieudane logowania i brute force, obejścia 2FA, odmowy API, błędy SSO i poczty oraz skoki liczby błędów. Okno analizy: <?= (int)$settings['log_window_hours'] ?> h.</p>

  <?php if (!$run): ?>
    <div class="alert alert-info">Kontrola Dzienników jeszcze się nie wykonała. Kliknij „Analizuj teraz” albo poczekaj na agenta <code>it_control_logs</code>.</div>
  <?php else: $crit = itControlRunFindings($run, ['critical', 'high']); $recs = itControlRunRecommendations($run); ?>
  <div class="row g-3 mb-3">
    <?php foreach ([['Status', itcUiBadge($run['status'], 'fs-6')], ['Przetworzone zdarzenia', number_format((int)$run['events_processed'], 0, ',', ' ')],
                    ['Błędy krytyczne', (int)$run['critical_count']], ['Ostatnia analiza', h(itcUiAgo($run['started_at']))]] as [$lbl, $val]): ?>
    <div class="col-6 col-md-3"><div class="card h-100 text-center"><div class="card-body py-3">
      <div class="fs-4 fw-bold"><?= $val ?></div><div class="small text-muted text-uppercase"><?= h($lbl) ?></div></div></div></div>
    <?php endforeach; ?>
  </div>

  <?php if ($crit): ?>
  <div class="alert alert-danger"><h2 class="h6"><i class="bi bi-exclamation-octagon me-1" aria-hidden="true"></i>Wykryte błędy krytyczne</h2>
    <ul class="mb-0 small"><?php foreach ($crit as $f): ?><li><strong><?= h($f['title']) ?></strong><?= $f['detail'] !== '' ? ' — ' . h($f['detail']) : '' ?> <span class="text-muted">(<?= h($f['check_title']) ?>)</span></li><?php endforeach; ?></ul></div>
  <?php endif; ?>
  <?php if ($recs): ?>
  <div class="card mb-3 border-primary"><div class="card-body"><h2 class="h6"><i class="bi bi-lightbulb me-1" aria-hidden="true"></i>Rekomendowane akcje</h2>
    <ol class="mb-0 small"><?php foreach ($recs as $x): ?><li><?= h($x) ?></li><?php endforeach; ?></ol></div></div>
  <?php endif; ?>

  <div class="card mb-3"><div class="card-header bg-white d-flex">
      <strong class="me-auto">Wyniki sprawdzeń</strong>
      <a class="small" href="<?= h(APP_URL . '/modules/it_control/report.php?run=' . (int)$run['id']) ?>" target="_blank" rel="noopener">pełny raport</a>
      <span class="mx-1 text-muted">·</span><a class="small" href="<?= h(APP_URL . '/modules/it_control/report.php?run=' . (int)$run['id'] . '&download=1') ?>">pobierz</a></div>
    <?= itcUiResultsTable($run) ?>
  </div>

  <?php $tops = []; foreach ($run['results'] as $r) foreach ($r['metrics']['top'] ?? [] as $t) $tops[] = $t;
        usort($tops, fn($a, $b) => $b['count'] <=> $a['count']); ?>
  <?php if ($tops): ?>
  <div class="card mb-3"><div class="card-header bg-white"><strong>Najczęstsze komunikaty w oknie</strong></div>
    <div class="table-responsive"><table class="table table-sm mb-0 small"><thead class="table-light"><tr><th>Poziom</th><th class="text-end">Liczba</th><th>Sygnatura</th><th>Plik</th><th>Ostatnio</th></tr></thead><tbody>
    <?php foreach (array_slice($tops, 0, 20) as $t): ?>
      <tr><td><?= itcUiSeverity($t['level'] === 'fatal' ? 'critical' : ($t['level'] === 'error' ? 'high' : 'medium')) ?></td><td class="text-end"><?= (int)$t['count'] ?></td>
          <td class="font-monospace text-break" title="<?= h($t['example']) ?>"><?= h($t['sig']) ?></td><td class="text-nowrap"><?= h($t['file']) ?></td><td class="text-nowrap"><?= h(date('d.m H:i', (int)$t['last'])) ?></td></tr>
    <?php endforeach; ?></tbody></table></div></div>
  <?php endif; ?>
  <?php endif; ?>

  <div class="row g-3">
    <div class="col-lg-6"><div class="card h-100"><div class="card-header bg-white"><strong>Źródła dzienników (pliki)</strong></div>
      <ul class="list-group list-group-flush small">
        <?php foreach ($files as $f): ?>
        <li class="list-group-item d-flex gap-2"><span class="badge bg-light text-dark border"><?= h($f['kind']) ?></span>
          <span class="font-monospace text-break me-auto"><?= h($f['path']) ?></span><span class="text-muted text-nowrap"><?= h(number_format((int)@filesize($f['path']) / 1024, 0, ',', ' ')) ?> KB</span></li>
        <?php endforeach; ?>
        <?php if (!$files): ?><li class="list-group-item text-muted">Brak plików. Ustaw <code>error_log</code> PHP, zdefiniuj <code>LOG_PATH</code> albo dopisz ścieżki w ustawieniach.</li><?php endif; ?>
        <li class="list-group-item text-muted">Tabele: login_log, login_attempts, audit_logs, api_audit_log, saml_sso_log, mail_queue.</li>
      </ul></div></div>
    <div class="col-lg-6"><div class="card h-100"><div class="card-header bg-white"><strong>Historia analiz</strong></div>
      <div class="table-responsive"><table class="table table-sm mb-0 small"><thead class="table-light"><tr><th>Kiedy</th><th>Status</th><th class="text-end">Zdarzeń</th><th class="text-end">Kryt.</th><th></th></tr></thead><tbody>
      <?php foreach ($hist as $hr): ?>
        <tr><td class="text-nowrap"><?= h($hr['started_at']) ?></td><td><?= itcUiBadge($hr['status']) ?></td><td class="text-end"><?= (int)$hr['events_processed'] ?></td>
            <td class="text-end <?= $hr['critical_count'] ? 'text-danger fw-bold' : '' ?>"><?= (int)$hr['critical_count'] ?></td>
            <td><a href="<?= h(APP_URL . '/modules/it_control/report.php?run=' . (int)$hr['id']) ?>" target="_blank" rel="noopener">raport</a></td></tr>
      <?php endforeach; ?>
      <?php if (!$hist): ?><tr><td colspan="5" class="text-muted text-center py-3">Brak przebiegów.</td></tr><?php endif; ?>
      </tbody></table></div></div></div>
  </div>
</div>
<?php include dirname(__DIR__, 2) . '/includes/footer.php'; ?>
