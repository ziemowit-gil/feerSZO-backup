<?php
/** SAML IdP (saml_sso_log): odmowy i błędy logowania jednokrotnego. */

class LogControlSamlCheck extends ItControlCheck
{
    public function id(): string { return 'logs.saml'; }
    public function suite(): string { return 'logs'; }
    public function title(): string { return 'Logowanie jednokrotne SAML'; }

    protected function execute(ItControlContext $ctx, ItControlResult $r): void {
        $rows = $this->tryAll("SELECT result, COUNT(*) AS n FROM saml_sso_log WHERE created_at >= ? GROUP BY result", [$ctx->windowStartSql()]);
        if ($rows === null) { $r->skip('Brak tabeli saml_sso_log (SAML nieużywany).'); return; }
        $by = array_column($rows, 'n', 'result');
        $r->eventsProcessed = array_sum(array_map('intval', $by));
        $bad = (int)($by['denied'] ?? 0) + (int)($by['error'] ?? 0);
        $r->metric('by_result', $by);
        if ((int)($by['error'] ?? 0) > 0) {
            $r->finding('error', 'medium', 'Błędy SSO SAML: ' . (int)$by['error'], '', (int)$by['error']);
            $r->recommend('Sprawdź certyfikat IdP i konfigurację dostawców usług (Admin → SAML).');
        }
        if ((int)($by['denied'] ?? 0) > 0) $r->finding('denied', 'info', 'Odmowy SSO SAML: ' . (int)$by['denied'], '', (int)$by['denied']);
        $r->summary = "Zdarzeń SSO: {$r->eventsProcessed}, odmów/błędów: {$bad}.";
    }
}
