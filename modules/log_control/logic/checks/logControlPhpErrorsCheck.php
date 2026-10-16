<?php
/** Dziennik błędów PHP: fatale, błędy, ostrzeżenia w oknie + najczęstsze sygnatury. */

require_once dirname(__DIR__) . '/logControlReader.php';

class LogControlPhpErrorsCheck extends ItControlCheck
{
    public function id(): string { return 'logs.php_errors'; }
    public function suite(): string { return 'logs'; }
    public function title(): string { return 'Dziennik błędów PHP'; }
    public function description(): string { return 'Fatal error / Uncaught / błędy i ostrzeżenia w error_log PHP w oknie analizy.'; }

    protected function execute(ItControlContext $ctx, ItControlResult $r): void {
        logControlCheckFileSummary($ctx, $r, ['php'], 'PHP',
            'Nie znaleziono pliku dziennika PHP (ini error_log pusty lub nieczytelny) — ustaw error_log w php.ini / .user.ini albo dopisz ścieżkę w ustawieniach.');
    }
}

/**
 * Wspólna ocena podsumowania plików dzienników (PHP i aplikacji/CRON).
 * Fatale → FAIL (krytyczne powyżej progu), błędy powyżej progu → ostrzeżenie.
 */
function logControlCheckFileSummary(ItControlContext $ctx, ItControlResult $r, array $kinds, string $what, string $noFilesHint): void {
    $s = logControlSummarize($ctx, $kinds);
    if ($s['files'] === 0) {
        $r->finding('no_source', 'low', "Brak źródła: dziennik {$what}", $noFilesHint, 0);
        $r->summary = 'Brak plików do analizy.';
        return;
    }
    $c = $s['counts'];
    $r->eventsProcessed = $s['lines'];
    $r->metric('files', $s['files'])->metric('fatal', $c['fatal'])->metric('error', $c['error'])
      ->metric('warning', $c['warning'])->metric('notice', $c['notice'])->metric('security', $s['security'])
      ->metric('per_file', $s['per_file'])->metric('top', array_slice($s['signatures'], 0, 10));
    foreach ($s['errors'] as $e) $r->finding('unreadable', 'low', 'Nie można odczytać pliku', $e);

    if ($c['fatal'] > 0) {
        $crit = $c['fatal'] >= max(1, (int)$ctx->setting('php_fatal_critical'));
        $top = array_values(array_filter($s['signatures'], fn($x) => $x['level'] === 'fatal'));
        $r->finding('fatal', $crit ? 'critical' : 'high', "Błędy krytyczne (fatal) w dzienniku {$what}: {$c['fatal']}",
            $top ? '„' . $top[0]['sig'] . '” ×' . $top[0]['count'] . ' (' . $top[0]['file'] . ')' : '', $c['fatal']);
        $r->recommend("Przejrzyj błędy fatal w dzienniku {$what} i popraw kod/konfigurację — każdy oznacza przerwane żądanie lub zadanie.");
    }
    if ($c['error'] >= max(1, (int)$ctx->setting('php_errors_warn'))) {
        $r->finding('errors', 'medium', "Dużo błędów w dzienniku {$what}: {$c['error']}", 'Próg: ' . (int)$ctx->setting('php_errors_warn') . ' w oknie.', $c['error']);
        $r->recommend("Usuń najczęstsze błędy z dziennika {$what} (lista sygnatur w raporcie).");
    } elseif ($c['error'] > 0) {
        $r->finding('errors', 'info', "Błędy w dzienniku {$what}: {$c['error']}", 'Poniżej progu ostrzeżenia.', $c['error']);
    }
    if ($c['warning'] > 0) $r->finding('warnings', 'info', "Ostrzeżenia w dzienniku {$what}: {$c['warning']}", '', $c['warning']);
    if ($s['security'] > 0) {
        $r->finding('security', 'medium', "Wpisy bezpieczeństwa w dzienniku {$what}: {$s['security']}", 'Odmowy dostępu / 401 / 403 / CSRF.', $s['security']);
        $r->recommend('Sprawdź źródła odmów dostępu (adresy IP, konta) w dzienniku ' . $what . '.');
    }
    $r->summary = sprintf('%d plik(ów), %d linii w oknie: fatal %d, błędy %d, ostrzeżenia %d.', $s['files'], $s['lines'], $c['fatal'], $c['error'], $c['warning']);
}
