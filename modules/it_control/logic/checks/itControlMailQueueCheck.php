<?php
/** Kolejka poczty: liczba oczekujących, zatkana kolejka (stare „pending”), nieudane z ostatniej doby. */

class ItControlMailQueueCheck extends ItControlCheck
{
    public function id(): string { return 'ops.mail_queue'; }
    public function suite(): string { return 'ops'; }
    public function title(): string { return 'Kolejka poczty'; }

    protected function execute(ItControlContext $ctx, ItControlResult $r): void {
        $rows = $this->tryAll("SELECT status, COUNT(*) AS n FROM mail_queue GROUP BY status");
        if ($rows === null) { $r->skip('Brak tabeli mail_queue.'); return; }
        $by = array_map('intval', array_column($rows, 'n', 'status'));
        $pending = ($by['pending'] ?? 0) + ($by['sending'] ?? 0);
        $stuckMin = max(5, (int)$ctx->setting('mailq_stuck_min'));
        $stuck = (int)($this->tryOne("SELECT COUNT(*) AS n FROM mail_queue WHERE status IN ('pending','sending') AND scheduled_at <= ?",
                     [date('Y-m-d H:i:s', $ctx->now - $stuckMin * 60)])['n'] ?? 0);
        $oldest = $this->tryOne("SELECT MIN(scheduled_at) AS t FROM mail_queue WHERE status IN ('pending','sending') AND scheduled_at <= ?", [date('Y-m-d H:i:s', $ctx->now)])['t'] ?? null;
        $failed24 = (int)($this->tryOne("SELECT COUNT(*) AS n FROM mail_queue WHERE status='failed' AND created_at >= ?",
                     [date('Y-m-d H:i:s', $ctx->now - 86400)])['n'] ?? 0);
        $r->metric('by_status', $by)->metric('pending', $pending)->metric('stuck', $stuck)
          ->metric('oldest_pending', $oldest)->metric('failed_24h', $failed24);
        if ($stuck > 0) {
            $r->finding('stuck', $stuck >= 20 ? 'high' : 'medium', "Zatkana kolejka: {$stuck} wiadomości czeka dłużej niż {$stuckMin} min",
                'Najstarsza od: ' . $oldest, $stuck);
            $r->recommend('Sprawdź, czy agent mail_queue działa (Admin → Harmonogram CRON) i czy serwer SMTP/M365 przyjmuje pocztę.');
        }
        if ($pending >= max(1, (int)$ctx->setting('mailq_pending_warn'))) {
            $r->finding('pending', 'medium', "Dużo wiadomości w kolejce: {$pending}", '', $pending);
        }
        if ($failed24 > 0) {
            $r->finding('failed', $failed24 >= 10 ? 'medium' : 'info', "Nieudane wysyłki (24 h): {$failed24}", '', $failed24);
            if ($failed24 >= 10) $r->recommend('Przejrzyj nieudane wiadomości w kolejce i ponów je po naprawie konfiguracji.');
        }
        $r->summary = "Oczekujących: {$pending}, zatkanych: {$stuck}, nieudanych w 24 h: {$failed24}.";
    }
}
