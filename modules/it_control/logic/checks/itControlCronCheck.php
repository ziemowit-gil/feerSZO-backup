<?php
/**
 * CRON: czy dyspozytor tyka (heartbeat w cron_agent_state) i czy włączeni agenci
 * uruchamiają się zgodnie z interwałem (rejestr modules/cron_dispatcher).
 */

class ItControlCronCheck extends ItControlCheck
{
    public function id(): string { return 'ops.cron'; }
    public function suite(): string { return 'ops'; }
    public function title(): string { return 'Agenci CRON'; }

    protected function execute(ItControlContext $ctx, ItControlResult $r): void {
        require_once dirname(__DIR__, 4) . '/modules/cron_dispatcher/logic/cron_dispatcher.php';
        // W trybie próbnym nie tworzymy tabel dyspozytora — tylko czytamy, jeśli są.
        try {
            $state = [];
            foreach (db()->query("SELECT * FROM cron_agent_state") as $row) $state[$row['name']] = $row;
        } catch (\Throwable $e) { $state = []; }
        // cron_dispatcher_effective() zakłada tabele przy pierwszym użyciu — wołamy je tylko,
        // gdy już istnieją (tryb próbny nie może niczego zapisać).
        $hasOverrides = $this->tryOne("SELECT 1 AS x FROM cron_agent_overrides LIMIT 1") !== null
                     || $this->tryAll("SELECT name FROM cron_agent_overrides LIMIT 0") !== null;
        $agents = $hasOverrides ? cron_dispatcher_effective(cron_dispatcher_registry())
                                : array_map(fn($c) => $c + ['enabled' => true], cron_dispatcher_registry());
        $hb = (int)($state[CRON_DISPATCHER_HEARTBEAT]['last_run_at'] ?? 0);
        $hbMin = max(2, (int)$ctx->setting('cron_heartbeat_min'));
        $r->metric('agents', count($agents))->metric('heartbeat', $hb ? date('Y-m-d H:i:s', $hb) : null);
        if (!$hb) {
            $r->finding('no_heartbeat', 'high', 'Dyspozytor CRON nigdy nie zarejestrował uruchomienia',
                'Brak wpisu w crontab: * * * * * php cron/dispatcher.php');
            $r->recommend('Dodaj dyspozytora do crontaba (Admin → Konfiguracja CRON).');
        } elseif ($ctx->now - $hb > $hbMin * 60) {
            $min = (int)(($ctx->now - $hb) / 60);
            $r->finding('heartbeat', 'critical', "Dyspozytor CRON nie działa od {$min} min", 'Ostatnie tyknięcie: ' . date('Y-m-d H:i', $hb));
            $r->recommend('Sprawdź crontab i log /var/log/umowy_cron.log — żaden agent (poczta, backup, przypomnienia) się nie uruchamia.');
        }
        $factor = max(2, (int)$ctx->setting('cron_stale_factor'));
        $stale = []; $never = 0; $enabled = 0;
        foreach ($agents as $name => $cfg) {
            if (empty($cfg['enabled'])) continue;
            $enabled++;
            if (!is_file($cfg['file'])) { $r->finding('missing:' . $name, 'medium', "Brak pliku agenta {$name}", $cfg['file']); continue; }
            $last = cron_dispatcher_last_run($name, $state);
            if (!$last) { $never++; continue; }
            $interval = max(60, cron_dispatcher_interval_seconds($cfg));
            // agent z oknem godzinowym może legalnie czekać do następnego okna (do doby)
            $limit = $factor * $interval + (isset($cfg['schedule']) ? 86400 : 0);
            if ($ctx->now - $last > $limit) $stale[] = $name . ' (' . round(($ctx->now - $last) / 3600, 1) . ' h)';
        }
        $r->metric('enabled', $enabled)->metric('never_run', $never)->metric('stale', $stale);
        if ($stale && $hb) {
            $r->finding('stale', 'medium', 'Agenci zalegający z uruchomieniem: ' . count($stale), implode(', ', array_slice($stale, 0, 15)), count($stale));
            $r->recommend('Sprawdź w Harmonogramie CRON agentów bez świeżych uruchomień — mogą kończyć się błędem przed startem lub mieć złe okno godzinowe.');
        }
        if ($never) $r->finding('never', 'info', "Agenci bez zarejestrowanego uruchomienia: {$never}", '', $never);
        $r->summary = "Włączonych agentów: {$enabled}, zalegających: " . count($stale) . ', heartbeat: ' . ($hb ? date('H:i', $hb) : 'brak') . '.';
    }
}
