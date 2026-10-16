<?php
/** REST API (api_audit_log): odmowy 401/403 i limity 429 w oknie. */

class LogControlApiAccessCheck extends ItControlCheck
{
    public function id(): string { return 'logs.api_access'; }
    public function suite(): string { return 'logs'; }
    public function title(): string { return 'Odmowy dostępu API (401/403/429)'; }

    protected function execute(ItControlContext $ctx, ItControlResult $r): void {
        $from = $ctx->windowStartSql();
        $rows = $this->tryAll("SELECT status, COUNT(*) AS n FROM api_audit_log WHERE created_at >= ? GROUP BY status", [$from]);
        if ($rows === null) { $r->skip('Brak tabeli api_audit_log.'); return; }
        $by = array_column($rows, 'n', 'status');
        $r->eventsProcessed = array_sum(array_map('intval', $by));
        $denied = (int)($by[401] ?? 0) + (int)($by[403] ?? 0) + (int)($by[429] ?? 0);
        $errors5xx = 0;
        foreach ($by as $st => $n) if ((int)$st >= 500) $errors5xx += (int)$n;
        $r->metric('by_status', $by)->metric('denied', $denied)->metric('errors_5xx', $errors5xx);
        $warn = max(1, (int)$ctx->setting('api_denied_warn'));
        if ($denied >= $warn) {
            $top = $this->tryAll("SELECT ip, COUNT(*) AS n FROM api_audit_log WHERE created_at >= ? AND status IN (401,403,429)
                                  GROUP BY ip ORDER BY n DESC LIMIT 3", [$from]) ?? [];
            $r->finding('denied', 'medium', "Odmowy dostępu API: {$denied}", 'Najwięcej z: ' . implode(', ', array_map(fn($t) => $t['ip'] . ' ×' . $t['n'], $top)), $denied);
            $r->recommend('Sprawdź klucze API (Admin → Klucze API) — nieważny klucz u integratora albo próby nieuprawnionego dostępu.');
        } elseif ($denied > 0) {
            $r->finding('denied', 'info', "Odmowy dostępu API: {$denied}", '', $denied);
        }
        if ($errors5xx > 0) {
            $r->finding('5xx', 'medium', "Błędy serwera API (5xx): {$errors5xx}", '', $errors5xx);
            $r->recommend('Przejrzyj dziennik PHP z godzin błędów 5xx API.');
        }
        $r->summary = "Wywołań API: {$r->eventsProcessed}, odmów: {$denied}, błędów 5xx: {$errors5xx}.";
    }
}
