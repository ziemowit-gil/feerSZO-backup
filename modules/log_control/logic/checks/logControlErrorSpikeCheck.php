<?php
/**
 * Wykrywanie anomalii: skok liczby zdarzeń błędów w ostatniej godzinie względem
 * średniej godzinowej z ostatnich N dni (itc_spike_baseline_days).
 *
 * Zdarzenia błędów = linie fatal/error z plików dzienników + nieudane logowania
 * + nieudane e-maile + odmowy/5xx API. Skok = ostatnia godzina ≥ średnia × factor
 * ORAZ ≥ itc_spike_min → znalezisko krytyczne (wyzwala natychmiastowy alert).
 */

require_once dirname(__DIR__) . '/logControlReader.php';

class LogControlErrorSpikeCheck extends ItControlCheck
{
    public function id(): string { return 'logs.error_spike'; }
    public function suite(): string { return 'logs'; }
    public function title(): string { return 'Anomalia: skok liczby błędów'; }
    public function description(): string { return 'Ostatnia godzina vs średnia godzinowa z ostatnich dni (pliki dzienników, logowania, poczta, API).'; }

    protected function execute(ItControlContext $ctx, ItControlResult $r): void {
        $days = max(1, (int)$ctx->setting('spike_baseline_days'));
        $now = $ctx->now;
        $hourNow = intdiv($now, 3600);
        $from = ($hourNow - $days * 24) * 3600;
        $buckets = [];                                     // godzina (unix/3600) => liczba
        $last = 0;                                         // ostatnie 60 minut
        $add = function (int $ts) use (&$buckets, &$last, $from, $now) {
            if ($ts < $from || $ts > $now) return;
            $h = intdiv($ts, 3600);
            $buckets[$h] = ($buckets[$h] ?? 0) + 1;
            if ($ts > $now - 3600) $last++;
        };
        foreach (logControlReadAll($ctx) as $f) {
            foreach ($f['entries'] as $e) if ($e['level'] === 'fatal' || $e['level'] === 'error') $add($e['ts']);
        }
        $fromSql = date('Y-m-d H:i:s', $from);
        $sources = [
            "SELECT created_at AS t FROM login_log WHERE created_at >= ? AND action IN ('login_fail','login_blocked')",
            "SELECT created_at AS t FROM mail_queue WHERE created_at >= ? AND status='failed'",
            "SELECT created_at AS t FROM api_audit_log WHERE created_at >= ? AND (status IN (401,403,429) OR status >= 500)",
        ];
        foreach ($sources as $sql) {
            foreach ($this->tryAll($sql, [$fromSql]) ?? [] as $row) {
                $t = strtotime((string)$row['t']);
                if ($t) $add($t);
            }
        }
        $total = array_sum($buckets);
        $r->eventsProcessed = $total;
        // baza: wszystko sprzed ostatnich 60 min, rozłożone na liczbę godzin bazy
        $baseHours = max(1, ($now - 3600 - $from) / 3600);
        $avg = ($total - $last) / $baseHours;
        $factor = max(1.0, (float)$ctx->setting('spike_factor'));
        $min = max(1, (int)$ctx->setting('spike_min'));
        $r->metric('last_hour', $last)->metric('baseline_avg_per_hour', round($avg, 2))
          ->metric('baseline_days', $days)->metric('factor', $factor)->metric('threshold', max($min, (int)ceil($avg * $factor)));
        // przebieg godzinowy z ostatniej doby (do wykresu w raporcie)
        $series = [];
        for ($h = $hourNow - 23; $h <= $hourNow; $h++) $series[date('H', $h * 3600)] = $buckets[$h] ?? 0;
        $r->metric('last_24h', $series);

        if ($last >= $min && $last >= $avg * $factor) {
            $r->finding('spike', 'critical', "Skok liczby błędów: {$last} w ostatniej godzinie",
                sprintf('Średnia z %d dni: %.1f/h, próg: ×%.1f i min. %d.', $days, $avg, $factor, $min), $last);
            $r->recommend('Sprawdź dziennik PHP i logi agentów z ostatniej godziny — gwałtowny wzrost błędów zwykle oznacza awarię usługi zewnętrznej, wdrożenie z błędem albo atak.');
            $r->summary = sprintf('ANOMALIA: %d zdarzeń błędów w ostatniej godzinie (średnio %.1f/h).', $last, $avg);
        } else {
            $r->summary = sprintf('Ostatnia godzina: %d zdarzeń błędów, średnio %.1f/h (próg %d).', $last, $avg, max($min, (int)ceil($avg * $factor)));
        }
    }
}
