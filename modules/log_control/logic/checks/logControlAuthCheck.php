<?php
/** Bezpieczeństwo logowań: nieudane i zablokowane logowania, brute force z jednego IP. */

class LogControlAuthCheck extends ItControlCheck
{
    public function id(): string { return 'logs.auth'; }
    public function suite(): string { return 'logs'; }
    public function title(): string { return 'Bezpieczeństwo logowań'; }
    public function description(): string { return 'login_log (login_fail / login_blocked) i login_attempts — progi i brute force per IP.'; }

    protected function execute(ItControlContext $ctx, ItControlResult $r): void {
        $from = $ctx->windowStartSql();
        $rows = $this->tryAll("SELECT action, COUNT(*) AS n FROM login_log WHERE created_at >= ? GROUP BY action", [$from]);
        if ($rows === null) { $r->skip('Brak tabeli login_log.'); return; }
        $by = array_column($rows, 'n', 'action');
        $total = array_sum(array_map('intval', $by));
        $fail = (int)($by['login_fail'] ?? 0);
        $blocked = (int)($by['login_blocked'] ?? 0);
        $attempts = (int)($this->tryOne("SELECT COUNT(*) AS n FROM login_attempts WHERE created_at >= ?", [$from])['n'] ?? 0);
        $r->eventsProcessed = $total + $attempts;
        $r->metric('by_action', $by)->metric('login_fail', $fail)->metric('login_blocked', $blocked)->metric('login_attempts', $attempts);

        $ips = $this->tryAll("SELECT ip, COUNT(*) AS n, COUNT(DISTINCT email) AS accounts FROM login_log
                              WHERE created_at >= ? AND action IN ('login_fail','login_blocked') AND ip <> ''
                              GROUP BY ip ORDER BY n DESC LIMIT 10", [$from]) ?? [];
        $r->metric('top_ips', $ips);
        $thr = max(1, (int)$ctx->setting('bruteforce_ip'));
        foreach ($ips as $ip) {
            if ((int)$ip['n'] < $thr) break;
            $r->finding('bruteforce:' . $ip['ip'], 'critical', 'Podejrzenie ataku brute force z ' . $ip['ip'],
                "{$ip['n']} nieudanych logowań na {$ip['accounts']} kont(a) w oknie (próg {$thr}).", (int)$ip['n']);
            $r->recommend('Zablokuj adres ' . $ip['ip'] . ' na zaporze / w serwerze WWW i sprawdź, czy któreś z atakowanych kont nie zostało przejęte.');
        }
        $warn = max(1, (int)$ctx->setting('login_fail_warn'));
        if ($fail >= $warn) {
            $r->finding('login_fail', 'medium', "Dużo nieudanych logowań: {$fail}", "Próg ostrzeżenia: {$warn} w oknie.", $fail);
            $r->recommend('Przejrzyj nieudane logowania (Admin → Przeglądarka logów) — czy to literówki użytkowników, czy próby zgadywania haseł.');
        } elseif ($fail > 0) {
            $r->finding('login_fail', 'info', "Nieudane logowania: {$fail}", '', $fail);
        }
        if ($blocked > 0) {
            $r->finding('login_blocked', 'medium', "Zablokowane logowania (limit prób): {$blocked}", 'Mechanizm blokady zadziałał — sprawdź, kogo dotyczy.', $blocked);
        }
        $r->summary = "Zdarzeń logowania: {$total}, nieudanych: {$fail}, zablokowanych: {$blocked}.";
    }
}
