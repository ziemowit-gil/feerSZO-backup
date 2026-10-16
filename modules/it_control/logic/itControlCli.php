<?php
/**
 * modules/it_control/logic/itControlCli.php — wspólny punkt wejścia CLI i agentów CRON.
 *
 *   php cli/itControl.php [--suite=logs|ops|all] [--dry-run] [--json]
 *   php cli/itControl.php --report [--force] [--dry-run]
 *   php cli/itControl.php --mail-preview=html|text [--alert]
 *
 * Agenci (cron/agents/itControl.php, rejestr modules/cron_dispatcher) wołają to
 * samo z --cron: wtedy obowiązują przełączniki itc_logs_enabled / itc_ops_enabled,
 * po przebiegu działa alert z bezpiecznikiem, a raport wychodzi tylko gdy należny.
 */

require_once __DIR__ . '/itControlRunner.php';
require_once __DIR__ . '/itControlMail.php';

function itControlCliHelp(): string {
    return <<<TXT
Kontrola IT — Kontrola Dzienników (logs) i Kontrola ogólna (ops)

Użycie:
  php cli/itControl.php [opcje]

Opcje:
  --suite=logs|ops|all   zestaw sprawdzeń (domyślnie all)
  --dry-run              bez zapisu do bazy i bez wysyłki — tylko wynik na ekranie
  --report               raport okresowy e-mailem (tylko gdy należny i włączony)
  --force                z --report: wyślij teraz, niezależnie od harmonogramu i przełącznika
  --mail-preview=html|text  wydrukuj e-mail raportu (z --alert: e-mail alertu); nic nie wysyła
  --alert                z --mail-preview: podgląd alertu zamiast raportu
  --json                 wynik jako JSON
  --cron                 tryb agenta CRON (przełączniki z ustawień, alerty, retencja)
  --help                 ta pomoc

Kody wyjścia: 0 = PASS/WARNING, 1 = FAIL, 2 = błąd wykonania.

TXT;
}

function itControlCliMain(array $argv): int {
    $opt = ['suite' => 'all', 'dry' => false, 'json' => false, 'report' => false, 'force' => false,
            'preview' => '', 'alert' => false, 'cron' => false];
    foreach (array_slice($argv, 1) as $a) {
        if ($a === '--help' || $a === '-h') { echo itControlCliHelp(); return 0; }
        elseif ($a === '--dry-run') $opt['dry'] = true;
        elseif ($a === '--json')    $opt['json'] = true;
        elseif ($a === '--report')  $opt['report'] = true;
        elseif ($a === '--force')   $opt['force'] = true;
        elseif ($a === '--alert')   $opt['alert'] = true;
        elseif ($a === '--cron')    $opt['cron'] = true;
        elseif (str_starts_with($a, '--suite=')) $opt['suite'] = substr($a, 8);
        elseif (str_starts_with($a, '--mail-preview')) $opt['preview'] = substr($a, 15) ?: 'html';
        else { fwrite(STDERR, "Nieznana opcja: {$a}\n\n" . itControlCliHelp()); return 2; }
    }
    if (!in_array($opt['suite'], ['logs', 'ops', 'all'], true)) { fwrite(STDERR, "Nieznany zestaw: {$opt['suite']}\n"); return 2; }
    if ($opt['preview'] !== '') $opt['dry'] = true;          // podgląd nigdy niczego nie zapisuje
    if (!in_array($opt['preview'], ['', 'html', 'text'], true)) { fwrite(STDERR, "--mail-preview=html|text\n"); return 2; }

    $settings = itControlSettings();
    $log = fn(string $m) => $opt['json'] || $opt['preview'] ? null : print('[' . date('Y-m-d H:i:s') . "] {$m}\n");

    try {
        if (!$opt['dry']) itControlMigrate();

        // Raport okresowy: w trybie CRON najpierw sprawdzamy, czy jest należny (bez zbędnych przebiegów)
        if ($opt['report'] && !$opt['force'] && !$opt['dry'] && !itControlReportDue($settings, time())) {
            $log('Raport nie jest należny (wyłączony albo już wysłany w tym okresie).');
            return 0;
        }

        $suites = $opt['suite'] === 'all' || $opt['report'] || $opt['preview'] !== '' ? ['logs', 'ops'] : [$opt['suite']];
        if ($opt['alert'] && $opt['preview'] !== '' && $opt['suite'] !== 'all') $suites = [$opt['suite']];
        $ctx = new ItControlContext($settings, $opt['dry']);
        $trigger = $opt['report'] ? 'report' : ($opt['cron'] ? 'cron' : 'cli');
        $runs = [];
        $alerts = [];
        foreach ($suites as $suite) {
            if ($opt['cron'] && !$opt['report'] && !(int)$settings[$suite . '_enabled']) {
                $log("Zestaw {$suite} wyłączony w ustawieniach — pomijam.");
                continue;
            }
            $run = itControlRunSuite($suite, $ctx, $trigger);
            if ($suite === 'logs') $ctx->cache['latest_logs_run'] = $run;     // agregat w ops widzi świeży wynik
            $runs[] = $run;
            $log(sprintf('%s: %s, zdarzeń %d, krytycznych %d%s', $suite, $run['status'], $run['events_processed'],
                 $run['critical_count'], $run['id'] ? ", przebieg #{$run['id']}" : ' (próbny)'));
            if (!$opt['report'] && $opt['preview'] === '') {
                $alerts[$suite] = itControlProcessAlert($settings, $run, $opt['dry']);
                if ($alerts[$suite]['status'] !== 'none') $log("Alert {$suite}: {$alerts[$suite]['status']} — {$alerts[$suite]['reason']}");
            }
        }
        if (!$runs) return 0;

        if ($opt['preview'] !== '') {
            $msg = $opt['alert'] ? itControlEmail('alert', [$runs[0]], ['critical' => itControlRunFindings($runs[0], ['critical'])])
                                 : itControlEmail('report', $runs, ['period_label' => date('d.m.Y'), 'attached' => true]);
            echo $opt['preview'] === 'html' ? $msg['html'] : "Temat: {$msg['subject']}\n\n{$msg['text']}";
            return 0;
        }

        $report = null;
        if ($opt['report']) {
            $report = itControlSendReport($settings, $runs, $opt['dry'], $opt['force']);
            $log("Raport: {$report['status']} — {$report['reason']}");
        }
        if ($opt['cron'] && !$opt['dry']) {
            $n = itControlPrune((int)$settings['retention_days']);
            if ($n) $log("Retencja: usunięto {$n} starych przebiegów.");
        }

        if ($opt['json']) {
            echo json_encode(['status' => itControlRunsStatus($runs), 'dry_run' => $opt['dry'], 'runs' => $runs,
                              'alerts' => array_map(fn($a) => array_diff_key($a, ['message' => 1]), $alerts),
                              'report' => $report ? array_diff_key($report, ['message' => 1]) : null],
                             JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
        } elseif (!$opt['cron'] || $opt['dry']) {
            echo "\n" . itControlReportText($runs, ['title' => 'Kontrola IT' . ($opt['dry'] ? ' (tryb próbny — bez zapisu i wysyłki)' : ''),
                                                  'dashboard_url' => itControlDashboardUrl()]);
        }
        return itControlRunsStatus($runs) === 'FAIL' ? 1 : 0;
    } catch (\Throwable $e) {
        fwrite(STDERR, '[BŁĄD] ' . get_class($e) . ': ' . $e->getMessage() . "\n");
        return 2;
    }
}
