<?php
/**
 * Dziennik audytu (audit_logs): obejścia drugiego składnika przy wejściu do panelu
 * dydaktyka (dyd.login_totp_bypass, dyd.login_yk_bypass) i inne zdarzenia wrażliwe.
 */

const LOG_CONTROL_BYPASS_ACTIONS = [
    'dyd.login_totp_bypass' => 'wejście bez kodu TOTP',
    'dyd.login_yk_bypass'   => 'wejście bez klucza YubiKey',
];

class LogControlAuditBypassCheck extends ItControlCheck
{
    public function id(): string { return 'logs.audit_bypass'; }
    public function suite(): string { return 'logs'; }
    public function title(): string { return 'Obejścia 2FA i audyt operacji'; }
    public function description(): string { return 'audit_logs: dyd.login_totp_bypass / dyd.login_yk_bypass oraz liczba operacji krytycznych.'; }

    protected function execute(ItControlContext $ctx, ItControlResult $r): void {
        $from = $ctx->windowStartSql();
        $rows = $this->tryAll("SELECT action, COUNT(*) AS n FROM audit_logs WHERE created_at >= ? GROUP BY action", [$from]);
        if ($rows === null) { $r->skip('Tabela audit_logs jeszcze nie istnieje.'); return; }
        $by = array_column($rows, 'n', 'action');
        $r->eventsProcessed = array_sum(array_map('intval', $by));
        $r->metric('by_action', $by);
        $bypassTotal = 0;
        foreach (LOG_CONTROL_BYPASS_ACTIONS as $action => $label) {
            $n = (int)($by[$action] ?? 0);
            if (!$n) continue;
            $bypassTotal += $n;
            $who = $this->tryAll("SELECT a.user_id, u.name, COUNT(*) AS n FROM audit_logs a LEFT JOIN users u ON u.id=a.user_id
                                  WHERE a.action=? AND a.created_at >= ? GROUP BY a.user_id, u.name ORDER BY n DESC LIMIT 5", [$action, $from]) ?? [];
            $names = implode(', ', array_map(fn($w) => ($w['name'] ?: '#' . $w['user_id']) . ' ×' . $w['n'], $who));
            $r->finding('bypass:' . $action, 'medium', "Obejście 2FA ({$label}): {$n}", $names, $n);
        }
        if ($bypassTotal) {
            $r->recommend('Zweryfikuj uzasadnienia obejść 2FA w dzienniku audytu i skonfiguruj tym osobom TOTP/klucz, żeby obejście nie było potrzebne.');
        }
        $r->metric('bypass_total', $bypassTotal);
        $r->summary = 'Wpisów audytu w oknie: ' . $r->eventsProcessed . ', obejść 2FA: ' . $bypassTotal . '.';
    }
}
