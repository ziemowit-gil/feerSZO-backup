<?php
/**
 * modules/it_control/index.php — Kontrola IT: pulpit stanu, historia, wysyłki, ustawienia.
 *
 * Status całościowy = najgorszy z ostatnich przebiegów Kontroli ogólnej (ops)
 * i Kontroli Dzienników (logs). Tylko administrator.
 * Logika: modules/it_control/logic/, sprawdzenia dzienników: modules/log_control/.
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once __DIR__ . '/logic/itControlRunner.php';
require_once __DIR__ . '/logic/itControlMail.php';
require_once __DIR__ . '/_ui.php';
require_once dirname(__DIR__) . '/audit_logs/logic/audit_logs.php';

require_role('admin');
itControlMigrate();

$BASE = APP_URL . '/modules/it_control/index.php';
$tab  = in_array($_GET['tab'] ?? '', ['dashboard', 'history', 'mail', 'settings'], true) ? $_GET['tab'] : 'dashboard';
$me   = current_user();

// ── Podgląd e-maila (z ostatnich zapisanych przebiegów, nic nie wysyła) ───────
if (isset($_GET['preview'])) {
    $runs = array_values(array_filter([itControlLatestRun('logs'), itControlLatestRun('ops')]));
    if (!$runs) { flash_set('error', 'Brak przebiegów — najpierw uruchom kontrolę.'); header('Location: ' . $BASE); exit; }
    if ($_GET['preview'] === 'alert') {
        $msg = itControlEmail('alert', $runs, []);
    } else {
        $msg = itControlEmail('report', $runs, ['period_label' => date('d.m.Y'), 'attached' => true]);
    }
    if (($_GET['format'] ?? '') === 'text') {
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Temat: ' . $msg['subject'] . "\n\n" . $msg['text'];
    } else {
        header('Content-Type: text/html; charset=utf-8');
        echo $msg['html'];
    }
    exit;
}

// ── Akcje ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['_action'] ?? '';
    $settings = itControlSettings();
    try {
        if ($action === 'run') {
            $suite = $_POST['suite'] ?? 'all';
            $suites = $suite === 'all' ? ['logs', 'ops'] : (isset(ITC_SUITES[$suite]) ? [$suite] : []);
            $ctx = new ItControlContext($settings);
            $msgs = [];
            foreach ($suites as $s) {
                $run = itControlRunSuite($s, $ctx, 'manual', (int)($me['id'] ?? 0) ?: null);
                if ($s === 'logs') $ctx->cache['latest_logs_run'] = $run;
                $msgs[] = ITC_SUITES[$s]['label'] . ': ' . $run['status'] . ' (zdarzeń ' . $run['events_processed'] . ')';
            }
            try { audit_log('it_control.run', ['suite' => $suite]); } catch (\Throwable $e) {}
            flash_set('success', 'Kontrola wykonana. ' . implode('; ', $msgs));
        } elseif ($action === 'send_report') {
            $runs = array_values(array_filter([itControlLatestRun('logs'), itControlLatestRun('ops')]));
            if (!$runs) throw new RuntimeException('Brak przebiegów do raportu — najpierw uruchom kontrolę.');
            $res = itControlSendReport($settings, $runs, false, true);
            flash_set($res['status'] === 'queued' ? 'success' : 'error', 'Raport: ' . $res['reason']);
        } elseif ($action === 'save_settings') {
            foreach (['logs_enabled', 'ops_enabled', 'report_enabled', 'alert_enabled', 'report_attach'] as $k) {
                itControlSettingSet($k, isset($_POST[$k]) ? 1 : 0);
            }
            $bad = [];
            foreach (preg_split('/[\s,;]+/', (string)($_POST['recipients'] ?? '')) as $e) {
                if (trim($e) !== '' && !filter_var(trim($e), FILTER_VALIDATE_EMAIL)) $bad[] = $e;
            }
            if ($bad) throw new RuntimeException('Nieprawidłowe adresy odbiorców: ' . implode(', ', $bad));
            itControlSettingSet('recipients', (string)($_POST['recipients'] ?? ''));
            itControlSettingSet('report_frequency', ($_POST['report_frequency'] ?? '') === 'weekly' ? 'weekly' : 'daily');
            itControlSettingSet('report_weekday', min(7, max(1, (int)($_POST['report_weekday'] ?? 1))));
            itControlSettingSet('report_hour', min(23, max(0, (int)($_POST['report_hour'] ?? 7))));
            itControlSettingSet('log_files', (string)($_POST['log_files'] ?? ''));
            foreach (ITC_DEFAULTS as $k => $def) {
                if (!is_int($def) && !is_float($def)) continue;
                if (in_array($k, ['logs_enabled', 'ops_enabled', 'report_enabled', 'alert_enabled', 'report_attach', 'report_weekday', 'report_hour'], true)) continue;
                if (isset($_POST['n'][$k]) && $_POST['n'][$k] !== '') itControlSettingSet($k, $_POST['n'][$k]);
            }
            $enabled = array_map('strval', (array)($_POST['checks'] ?? []));
            $disabled = array_values(array_diff(array_keys(ItControlRegistry::all()), $enabled));
            itControlSettingSet('checks_disabled', json_encode($disabled));
            try { audit_log('it_control.settings', ['report' => isset($_POST['report_enabled']), 'alert' => isset($_POST['alert_enabled'])]); } catch (\Throwable $e) {}
            flash_set('success', 'Ustawienia Kontroli IT zapisane.');
        }
    } catch (\Throwable $e) {
        flash_set('error', $e->getMessage());
    }
    header('Location: ' . $BASE . '?tab=' . urlencode($_POST['_tab'] ?? $tab));
    exit;
}

$settings = itControlSettings();
$overall  = itControlOverall();
$PAGE_TITLE = 'Kontrola IT';
include dirname(__DIR__, 2) . '/includes/header.php';
$tabs = ['dashboard' => ['Pulpit', 'bi-speedometer2'], 'history' => ['Historia', 'bi-clock-history'],
         'mail' => ['Wysyłki', 'bi-envelope'], 'settings' => ['Ustawienia', 'bi-sliders']];
?>
<div class="container-fluid px-0" style="max-width:1200px">
  <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
    <h1 class="h3 mb-0 me-auto"><i class="bi bi-shield-check me-2" aria-hidden="true"></i>Kontrola IT</h1>
    <a class="btn btn-outline-secondary btn-sm" href="<?= h(APP_URL) ?>/modules/log_control/index.php"><i class="bi bi-journal-text me-1" aria-hidden="true"></i>Kontrola Dzienników</a>
    <form method="post" class="d-inline">
      <?= csrf_field() ?><input type="hidden" name="_action" value="run"><input type="hidden" name="suite" value="all"><input type="hidden" name="_tab" value="<?= h($tab) ?>">
      <button class="btn btn-primary btn-sm"><i class="bi bi-play-fill me-1" aria-hidden="true"></i>Uruchom kontrolę teraz</button>
    </form>
  </div>
  <p class="text-muted small">Automatyczny audyt operacyjny: usługi i zasoby (baza, dysk, poczta, kopie, certyfikaty, CRON, polityki) oraz analiza dzienników systemowych. Agenci CRON: <code>it_control_logs</code>, <code>it_control_ops</code>, <code>it_control_report</code>.</p>

  <ul class="nav nav-tabs mb-3" role="tablist">
    <?php foreach ($tabs as $k => [$lbl, $ic]): ?>
    <li class="nav-item"><a class="nav-link<?= $tab === $k ? ' active' : '' ?>" <?= $tab === $k ? 'aria-current="page"' : '' ?> href="<?= h($BASE . '?tab=' . $k) ?>"><i class="bi <?= $ic ?> me-1" aria-hidden="true"></i><?= h($lbl) ?></a></li>
    <?php endforeach; ?>
  </ul>

<?php if ($tab === 'dashboard'): ?>
  <?php $st = ITC_UI_STATUS[$overall['status']] ?? ITC_UI_STATUS['SKIPPED']; ?>
  <div class="row g-3 mb-3">
    <div class="col-md-4">
      <div class="card h-100 border-<?= $st['cls'] ?>" style="border-width:2px">
        <div class="card-body text-center">
          <div class="text-muted small text-uppercase">Status całościowy</div>
          <div class="display-6 fw-bold text-<?= $st['cls'] === 'warning' ? 'warning' : $st['cls'] ?>"><i class="bi <?= $st['icon'] ?>" aria-hidden="true"></i> <?= h($overall['status'] === 'SKIPPED' ? 'BRAK DANYCH' : $st['label']) ?></div>
          <div class="small text-muted mt-1">najgorszy z ostatnich przebiegów obu kontroli</div>
          <div class="mt-2 small">
            <a href="<?= h($BASE . '?preview=report') ?>" target="_blank" rel="noopener">Podgląd e-maila raportu</a> ·
            <a href="<?= h($BASE . '?preview=report&format=text') ?>" target="_blank" rel="noopener">tekst</a> ·
            <a href="<?= h($BASE . '?preview=alert') ?>" target="_blank" rel="noopener">alertu</a>
          </div>
        </div>
      </div>
    </div>
    <?php foreach (ITC_SUITES as $suite => $meta): $run = $overall['runs'][$suite]; ?>
    <div class="col-md-4">
      <div class="card h-100">
        <div class="card-body">
          <div class="d-flex align-items-center mb-2">
            <h2 class="h6 mb-0 me-auto"><i class="bi <?= $meta['icon'] ?> me-1" aria-hidden="true"></i><?= h($meta['label']) ?></h2>
            <?= $run ? itcUiBadge($run['status']) : itcUiBadge('SKIPPED') ?>
          </div>
          <?php if ($run): ?>
          <dl class="row small mb-2">
            <dt class="col-7 fw-normal text-muted">Ostatni przebieg</dt><dd class="col-5 mb-1"><?= h(itcUiAgo($run['started_at'])) ?></dd>
            <dt class="col-7 fw-normal text-muted">Przetworzone zdarzenia</dt><dd class="col-5 mb-1"><?= (int)$run['events_processed'] ?></dd>
            <dt class="col-7 fw-normal text-muted">Znaleziska krytyczne</dt><dd class="col-5 mb-1 <?= $run['critical_count'] ? 'text-danger fw-bold' : '' ?>"><?= (int)$run['critical_count'] ?></dd>
            <dt class="col-7 fw-normal text-muted">FAIL / WARNING</dt><dd class="col-5 mb-1"><?= (int)$run['fail_count'] ?> / <?= (int)$run['warning_count'] ?></dd>
          </dl>
          <a class="small" href="<?= h(APP_URL . '/modules/it_control/report.php?run=' . (int)$run['id']) ?>" target="_blank" rel="noopener">Pełny raport</a>
          <?php if ($suite === 'logs'): ?> · <a class="small" href="<?= h(APP_URL . $meta['url']) ?>">Szczegóły</a><?php endif; ?>
          <?php else: ?>
          <p class="small text-muted mb-0">Jeszcze nie uruchomiono.</p>
          <?php endif; ?>
          <?php if (!(int)$settings[$suite . '_enabled']): ?><div class="small text-warning mt-2"><i class="bi bi-pause-circle" aria-hidden="true"></i> Agent wyłączony w ustawieniach</div><?php endif; ?>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <?php $allRuns = array_values(array_filter($overall['runs'])); $recs = [];
        foreach ($allRuns as $r) foreach (itControlRunRecommendations($r) as $x) $recs[$x] = true; ?>
  <?php if ($recs): ?>
  <div class="card mb-3 border-primary"><div class="card-body">
    <h2 class="h6"><i class="bi bi-lightbulb me-1" aria-hidden="true"></i>Rekomendowane akcje</h2>
    <ol class="mb-0 small"><?php foreach (array_keys($recs) as $x): ?><li><?= h($x) ?></li><?php endforeach; ?></ol>
  </div></div>
  <?php endif; ?>

  <?php if ($overall['runs']['ops']): ?>
  <div class="card mb-3"><div class="card-header bg-white"><strong>Kontrola ogólna — wyniki sprawdzeń</strong> <span class="text-muted small"><?= h($overall['runs']['ops']['started_at']) ?></span></div>
    <?= itcUiResultsTable($overall['runs']['ops']) ?>
  </div>
  <?php endif; ?>

  <?php $hist = array_reverse(itControlHistory(null, 48)); if ($hist): ?>
  <div class="card mb-3"><div class="card-body">
    <h2 class="h6">Ostatnie przebiegi (oś czasu)</h2>
    <div class="d-flex flex-wrap gap-1" role="list">
      <?php foreach ($hist as $hr): $c = ITC_UI_STATUS[$hr['status']]['cls'] ?? 'secondary';
            $tip = (ITC_SUITES[$hr['suite']]['label'] ?? $hr['suite']) . ' · ' . $hr['started_at'] . ' · ' . $hr['status']; ?>
      <a role="listitem" href="<?= h(APP_URL . '/modules/it_control/report.php?run=' . (int)$hr['id']) ?>" target="_blank" rel="noopener"
         class="d-inline-block rounded bg-<?= $c ?>" style="width:14px;height:28px" title="<?= h($tip) ?>">
        <span class="visually-hidden"><?= h($tip) ?></span></a>
      <?php endforeach; ?>
    </div>
  </div></div>
  <?php endif; ?>

<?php elseif ($tab === 'history'): ?>
  <?php $suiteF = isset(ITC_SUITES[$_GET['suite'] ?? '']) ? $_GET['suite'] : null; $rows = itControlHistory($suiteF, 200); ?>
  <div class="mb-2 small">Filtr:
    <a href="<?= h($BASE . '?tab=history') ?>" class="<?= !$suiteF ? 'fw-bold' : '' ?>">wszystkie</a>
    <?php foreach (ITC_SUITES as $k => $m): ?> · <a href="<?= h($BASE . '?tab=history&suite=' . $k) ?>" class="<?= $suiteF === $k ? 'fw-bold' : '' ?>"><?= h($m['label']) ?></a><?php endforeach; ?>
  </div>
  <div class="card"><div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
    <thead class="table-light"><tr><th>#</th><th>Kiedy</th><th>Kontrola</th><th>Wywołanie</th><th>Status</th><th class="text-end">Zdarzeń</th><th class="text-end">Krytyczne</th><th class="text-end">FAIL/WARN</th><th class="text-end">Czas</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr><td><?= (int)$r['id'] ?></td><td class="text-nowrap"><?= h($r['started_at']) ?></td><td><?= h(ITC_SUITES[$r['suite']]['label'] ?? $r['suite']) ?></td>
          <td><?= h(['cron' => 'CRON', 'manual' => 'ręcznie', 'cli' => 'CLI', 'report' => 'raport'][$r['trigger_']] ?? $r['trigger_']) ?></td>
          <td><?= itcUiBadge($r['status']) ?></td><td class="text-end"><?= (int)$r['events_processed'] ?></td>
          <td class="text-end <?= $r['critical_count'] ? 'text-danger fw-bold' : '' ?>"><?= (int)$r['critical_count'] ?></td>
          <td class="text-end"><?= (int)$r['fail_count'] ?>/<?= (int)$r['warning_count'] ?></td><td class="text-end"><?= (int)$r['duration_ms'] ?> ms</td>
          <td class="text-nowrap"><a href="<?= h(APP_URL . '/modules/it_control/report.php?run=' . (int)$r['id']) ?>" target="_blank" rel="noopener">raport</a> ·
              <a href="<?= h(APP_URL . '/modules/it_control/report.php?run=' . (int)$r['id'] . '&download=1') ?>">pobierz</a></td></tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="10" class="text-center text-muted py-4">Brak przebiegów.</td></tr><?php endif; ?>
    </tbody></table></div></div>

<?php elseif ($tab === 'mail'): ?>
  <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
    <span class="small">Raport okresowy: <strong><?= (int)$settings['report_enabled'] ? 'włączony' : 'wyłączony' ?></strong>, alerty: <strong><?= (int)$settings['alert_enabled'] ? 'włączone' : 'wyłączone' ?></strong>,
      odbiorcy: <?= h(implode(', ', array_column(itControlRecipients($settings), 'email')) ?: 'brak') ?></span>
    <form method="post" class="ms-auto" onsubmit="return confirm('Wysłać raport z ostatnich przebiegów do odbiorców teraz?')">
      <?= csrf_field() ?><input type="hidden" name="_action" value="send_report"><input type="hidden" name="_tab" value="mail">
      <button class="btn btn-outline-primary btn-sm"><i class="bi bi-send me-1" aria-hidden="true"></i>Wyślij raport teraz</button>
    </form>
  </div>
  <div class="card"><div class="table-responsive"><table class="table table-sm align-middle mb-0">
    <thead class="table-light"><tr><th>Kiedy</th><th>Rodzaj</th><th>Status</th><th>Temat</th><th>Odbiorcy</th><th>Przyczyna</th></tr></thead><tbody>
    <?php $ml = itControlMailHistory(100); foreach ($ml as $m): ?>
      <tr><td class="text-nowrap"><?= h($m['created_at']) ?></td><td><?= $m['kind'] === 'alert' ? '<span class="badge bg-danger">alert</span>' : '<span class="badge bg-primary">raport</span>' ?></td>
          <td><?= h(['queued' => 'wysłany', 'suppressed' => 'wstrzymany', 'dry' => 'próbny'][$m['status']] ?? $m['status']) ?></td>
          <td class="small"><?= h($m['subject']) ?></td><td class="small"><?= h($m['recipients']) ?></td><td class="small text-muted"><?= h($m['reason']) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$ml): ?><tr><td colspan="6" class="text-center text-muted py-4">Brak wysyłek.</td></tr><?php endif; ?>
    </tbody></table></div></div>

<?php else: /* settings */ ?>
  <?php $disabled = itControlDisabledChecks($settings);
        $num = function (string $k, string $label, string $help = '') use ($settings) {
            $step = is_float(ITC_DEFAULTS[$k]) ? '0.1' : '1';
            return '<div class="col-sm-6 col-lg-4"><label class="form-label small mb-0" for="n_' . $k . '">' . h($label) . '</label>'
                 . '<input class="form-control form-control-sm" type="number" min="0" step="' . $step . '" id="n_' . $k . '" name="n[' . $k . ']" value="' . h((string)$settings[$k]) . '">'
                 . ($help ? '<div class="form-text">' . h($help) . '</div>' : '') . '</div>';
        }; ?>
  <form method="post" class="card"><div class="card-body">
    <?= csrf_field() ?><input type="hidden" name="_action" value="save_settings"><input type="hidden" name="_tab" value="settings">
    <h2 class="h6">Włącz / wyłącz</h2>
    <div class="row g-2 mb-3">
      <?php foreach (['logs_enabled' => 'Agent Kontroli Dzienników (co godzinę)', 'ops_enabled' => 'Agent Kontroli ogólnej (co godzinę)',
                      'report_enabled' => 'Okresowy raport e-mail', 'alert_enabled' => 'Natychmiastowy alert przy anomalii krytycznej',
                      'report_attach' => 'Dołączaj pełny raport HTML jako załącznik'] as $k => $lbl): ?>
      <div class="col-md-6"><div class="form-check form-switch">
        <input class="form-check-input" type="checkbox" role="switch" id="s_<?= $k ?>" name="<?= $k ?>" <?= (int)$settings[$k] ? 'checked' : '' ?>>
        <label class="form-check-label" for="s_<?= $k ?>"><?= h($lbl) ?></label></div></div>
      <?php endforeach; ?>
    </div>

    <h2 class="h6">Odbiorcy i harmonogram</h2>
    <div class="row g-2 mb-3">
      <div class="col-lg-6"><label class="form-label small mb-0" for="recipients">Odbiorcy (adresy e-mail, przecinek lub nowa linia)</label>
        <textarea class="form-control form-control-sm" id="recipients" name="recipients" rows="3" placeholder="pusto = wszyscy aktywni administratorzy"><?= h($settings['recipients']) ?></textarea></div>
      <div class="col-sm-4 col-lg-2"><label class="form-label small mb-0" for="freq">Raport</label>
        <select class="form-select form-select-sm" id="freq" name="report_frequency">
          <option value="daily" <?= $settings['report_frequency'] === 'daily' ? 'selected' : '' ?>>dzienny</option>
          <option value="weekly" <?= $settings['report_frequency'] === 'weekly' ? 'selected' : '' ?>>tygodniowy</option></select></div>
      <div class="col-sm-4 col-lg-2"><label class="form-label small mb-0" for="wd">Dzień (tygodniowy)</label>
        <select class="form-select form-select-sm" id="wd" name="report_weekday">
          <?php foreach ([1 => 'poniedziałek', 'wtorek', 'środa', 'czwartek', 'piątek', 'sobota', 'niedziela'] as $i => $d): ?>
          <option value="<?= $i ?>" <?= (int)$settings['report_weekday'] === $i ? 'selected' : '' ?>><?= $d ?></option><?php endforeach; ?></select></div>
      <div class="col-sm-4 col-lg-2"><label class="form-label small mb-0" for="rh">Od godziny</label>
        <input class="form-control form-control-sm" type="number" min="0" max="23" id="rh" name="report_hour" value="<?= (int)$settings['report_hour'] ?>"></div>
    </div>

    <h2 class="h6">Bezpiecznik alertów</h2>
    <div class="row g-2 mb-3">
      <?= $num('alert_cooldown_min', 'Ten sam zestaw anomalii — co ile min', 'Powtórka identycznego alertu dopiero po tym czasie.') ?>
      <?= $num('alert_min_gap_min', 'Min. odstęp między alertami (min)') ?>
      <?= $num('alert_max_per_day', 'Maks. alertów na dobę') ?>
    </div>

    <h2 class="h6">Progi — Kontrola Dzienników</h2>
    <div class="row g-2 mb-3">
      <?= $num('log_window_hours', 'Okno analizy (h)') ?>
      <?= $num('php_errors_warn', 'Błędy w logach → ostrzeżenie') ?>
      <?= $num('php_fatal_critical', 'Fatale w logach → krytyczne') ?>
      <?= $num('login_fail_warn', 'Nieudane logowania → ostrzeżenie') ?>
      <?= $num('bruteforce_ip', 'Brute force: nieudane z 1 IP → krytyczne') ?>
      <?= $num('api_denied_warn', 'Odmowy API (401/403/429) → ostrzeżenie') ?>
      <?= $num('mail_failed_warn', 'Nieudane e-maile → ostrzeżenie') ?>
      <?= $num('spike_factor', 'Skok błędów: krotność średniej') ?>
      <?= $num('spike_min', 'Skok błędów: min. zdarzeń w godzinie') ?>
      <?= $num('spike_baseline_days', 'Skok błędów: baza (dni)') ?>
      <?= $num('log_max_bytes', 'Maks. bajtów czytanych z pliku') ?>
      <div class="col-lg-8"><label class="form-label small mb-0" for="lf">Dodatkowe pliki dzienników (po jednej ścieżce w linii)</label>
        <textarea class="form-control form-control-sm font-monospace" id="lf" name="log_files" rows="2"><?= h($settings['log_files']) ?></textarea></div>
    </div>

    <h2 class="h6">Progi — Kontrola ogólna</h2>
    <div class="row g-2 mb-3">
      <?= $num('disk_warn_pct', 'Dysk: ostrzeżenie od % zajętości') ?>
      <?= $num('disk_fail_pct', 'Dysk: krytyczne od %') ?>
      <?= $num('mailq_pending_warn', 'Kolejka: oczekujących → ostrzeżenie') ?>
      <?= $num('mailq_stuck_min', 'Kolejka: zatkana po (min)') ?>
      <?= $num('backup_fail_factor', 'Backup: FAIL przy wieku > RPO ×') ?>
      <?= $num('cert_warn_days', 'Certyfikat: ostrzeżenie (dni)') ?>
      <?= $num('cert_fail_days', 'Certyfikat: FAIL (dni)') ?>
      <?= $num('cron_heartbeat_min', 'CRON: dyspozytor milczy (min)') ?>
      <?= $num('cron_stale_factor', 'CRON: agent zalega > interwał ×') ?>
      <?= $num('logs_stale_hours', 'Kontrola dzienników nieświeża po (h)') ?>
      <?= $num('retention_days', 'Retencja historii (dni)') ?>
    </div>

    <h2 class="h6">Sprawdzenia</h2>
    <div class="row g-1 mb-3">
      <?php foreach (ItControlRegistry::all() as $id => $chk): ?>
      <div class="col-md-6"><div class="form-check">
        <input class="form-check-input" type="checkbox" id="c_<?= h($id) ?>" name="checks[]" value="<?= h($id) ?>" <?= in_array($id, $disabled, true) ? '' : 'checked' ?>>
        <label class="form-check-label small" for="c_<?= h($id) ?>"><span class="badge bg-light text-dark border"><?= h(ITC_SUITES[$chk->suite()]['label'] ?? $chk->suite()) ?></span>
          <?= h($chk->title()) ?> <span class="text-muted font-monospace"><?= h($id) ?></span></label></div></div>
      <?php endforeach; ?>
    </div>
    <button class="btn btn-primary"><i class="bi bi-save me-1" aria-hidden="true"></i>Zapisz ustawienia</button>
  </div></form>
<?php endif; ?>
</div>
<?php include dirname(__DIR__, 2) . '/includes/footer.php'; ?>
