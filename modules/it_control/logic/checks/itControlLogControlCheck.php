<?php
/**
 * Agregacja: wynik ostatniej Kontroli Dzienników wchodzi do kontroli ogólnej,
 * więc status ogólny uwzględnia też błędy i zdarzenia bezpieczeństwa z logów.
 */

class ItControlLogControlCheck extends ItControlCheck
{
    public function id(): string { return 'ops.log_control'; }
    public function suite(): string { return 'ops'; }
    public function title(): string { return 'Kontrola Dzienników (agregat)'; }

    protected function execute(ItControlContext $ctx, ItControlResult $r): void {
        $last = $ctx->cache['latest_logs_run'] ?? itControlLatestRun('logs');
        if (!$last) {
            $r->finding('never', 'low', 'Kontrola Dzienników jeszcze się nie wykonała', 'Uruchom ją z panelu lub poczekaj na agenta it_control_logs.');
            $r->summary = 'Brak przebiegów Kontroli Dzienników.';
            return;
        }
        $ageH = round(($ctx->now - strtotime($last['started_at'])) / 3600, 1);
        // zdarzeń nie doliczamy (eventsProcessed) — są już policzone w przebiegu dzienników
        $r->metric('run_id', $last['id'] ?? null)->metric('status', $last['status'])->metric('age_hours', $ageH)
          ->metric('critical', (int)$last['critical_count']);
        if ((int)$ctx->setting('logs_enabled') && $ageH > max(1, (int)$ctx->setting('logs_stale_hours'))) {
            $r->finding('stale', 'medium', "Ostatnia Kontrola Dzienników {$ageH} h temu", 'Agent it_control_logs nie działa albo jest wyłączony.');
        }
        foreach ($last['results'] as $res) {
            if ($res['status'] !== 'FAIL' && $res['status'] !== 'WARNING') continue;
            $worst = 'low';
            foreach ($res['findings'] as $f) if (in_array($f['severity'], ['critical', 'high'], true)) { $worst = 'high'; break; }
            if ($res['status'] === 'WARNING') $worst = 'low';
            $r->finding('logs:' . $res['check_id'], $worst, 'Dzienniki — ' . $res['title'] . ': ' . $res['status'], (string)$res['summary']);
        }
        if ($last['status'] !== 'PASS') $r->recommend('Otwórz Kontrolę Dzienników i wykonaj zalecenia z ostatniego raportu.');
        $r->summary = "Ostatnia kontrola dzienników: {$last['status']} ({$ageH} h temu), zdarzeń: {$last['events_processed']}.";
    }
}
