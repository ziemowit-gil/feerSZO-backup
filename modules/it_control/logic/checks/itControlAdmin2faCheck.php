<?php
/** Polityka: aktywni administratorzy bez drugiego składnika (TOTP ani klucza WebAuthn). */

class ItControlAdmin2faCheck extends ItControlCheck
{
    public function id(): string { return 'ops.policy_admin_2fa'; }
    public function suite(): string { return 'ops'; }
    public function title(): string { return 'Polityka: 2FA administratorów'; }

    protected function execute(ItControlContext $ctx, ItControlResult $r): void {
        $admins = $this->tryAll("SELECT u.id, u.name, u.email, COALESCE(u.totp_confirmed,0) AS totp,
                                   (SELECT COUNT(*) FROM webauthn_credentials w WHERE w.user_id=u.id) AS keys_
                                 FROM users u WHERE u.role='admin' AND u.is_active=1")
               ?? $this->tryAll("SELECT id, name, email, COALESCE(totp_confirmed,0) AS totp, 0 AS keys_ FROM users WHERE role='admin' AND is_active=1");
        if ($admins === null) { $r->skip('Nie można odczytać kont.'); return; }
        $r->eventsProcessed = count($admins);
        $without = []; $office = [];
        foreach ($admins as $a) {
            if ((int)$a['totp'] === 1 || (int)$a['keys_'] > 0) continue;
            // konta logujące się wyłącznie przez Microsoft 365 — MFA egzekwuje Entra ID
            if (function_exists('account_is_office_only') && account_is_office_only($a + ['role' => 'admin'])) { $office[] = $a['name']; continue; }
            $without[] = $a['name'] . ' <' . $a['email'] . '>';
        }
        $r->metric('admins', count($admins))->metric('without_2fa', count($without))->metric('office_only', count($office));
        if ($without) {
            $r->finding('admins_no_2fa', 'medium', 'Administratorzy bez 2FA: ' . count($without), implode(', ', $without), count($without));
            $r->recommend('Wymuś konfigurację TOTP albo klucza sprzętowego dla kont administratorów (Tożsamość → Bezpieczeństwo).');
        }
        if ($office) $r->finding('office_only', 'info', 'Administratorzy tylko z logowaniem M365: ' . count($office), 'MFA po stronie Entra ID: ' . implode(', ', $office), count($office));
        $r->summary = count($admins) . ' administratorów, bez 2FA: ' . count($without) . '.';
    }
}
