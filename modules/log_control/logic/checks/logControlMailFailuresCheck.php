<?php
/** Kolejka poczty (mail_queue): nieudane wysyłki w oknie i najczęstsze przyczyny. */

class LogControlMailFailuresCheck extends ItControlCheck
{
    public function id(): string { return 'logs.mail_failures'; }
    public function suite(): string { return 'logs'; }
    public function title(): string { return 'Nieudane wysyłki e-mail'; }

    protected function execute(ItControlContext $ctx, ItControlResult $r): void {
        $from = $ctx->windowStartSql();
        $rows = $this->tryAll("SELECT status, COUNT(*) AS n FROM mail_queue WHERE created_at >= ? GROUP BY status", [$from]);
        if ($rows === null) { $r->skip('Brak tabeli mail_queue.'); return; }
        $by = array_column($rows, 'n', 'status');
        $r->eventsProcessed = array_sum(array_map('intval', $by));
        $failed = (int)($by['failed'] ?? 0);
        $r->metric('by_status', $by)->metric('failed', $failed);
        if ($failed > 0) {
            $errs = $this->tryAll("SELECT last_error, COUNT(*) AS n FROM mail_queue WHERE created_at >= ? AND status='failed'
                                   GROUP BY last_error ORDER BY n DESC LIMIT 3", [$from]) ?? [];
            $detail = implode('; ', array_map(fn($e) => mb_substr((string)$e['last_error'], 0, 120) . ' ×' . $e['n'], $errs));
            $warn = max(1, (int)$ctx->setting('mail_failed_warn'));
            $r->finding('failed', $failed >= $warn ? 'medium' : 'info', "Nieudane e-maile: {$failed}", $detail, $failed);
            if ($failed >= $warn) $r->recommend('Sprawdź konfigurację SMTP/M365 (Admin → Kolejka e-mail) i ponów nieudane wiadomości.');
        }
        $r->summary = "E-maili w oknie: {$r->eventsProcessed}, nieudanych: {$failed}.";
    }
}
