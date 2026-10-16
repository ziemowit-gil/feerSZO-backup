<?php
/** Certyfikaty: instalacyjny certs/app.* (ważność + podpis HMAC) i SAML IdP certs/saml-idp.crt. */

class ItControlCertCheck extends ItControlCheck
{
    public function id(): string { return 'ops.certificates'; }
    public function suite(): string { return 'ops'; }
    public function title(): string { return 'Certyfikaty aplikacji'; }

    protected function execute(ItControlContext $ctx, ItControlResult $r): void {
        require_once dirname(__DIR__, 4) . '/includes/app_cert.php';
        $warn = (int)$ctx->setting('cert_warn_days');
        $fail = (int)$ctx->setting('cert_fail_days');
        $app = app_cert_status();
        $parts = [];
        if (!$app) {
            $r->finding('app_missing', 'medium', 'Brak certyfikatu instalacyjnego (certs/app.crt)', '');
            $r->recommend('Wygeneruj certyfikat: php cli/refresh_cert.php');
        } else {
            $this->expiry($r, 'app', 'instalacyjny', $app['days_left'], $warn, $fail, 'php cli/refresh_cert.php');
            if (!$app['hmac_ok']) {
                $r->finding('app_hmac', 'medium', 'Podpis HMAC certyfikatu się nie zgadza (certs/app.sig)', 'APP_KEY zmieniony albo plik podmieniony.');
                $r->recommend('Odśwież komplet certs/app.* poleceniem php cli/refresh_cert.php.');
            }
            $r->metric('app_days_left', $app['days_left']);
            $parts[] = "instalacyjny: {$app['days_left']} dni";
        }
        $saml = dirname(__DIR__, 4) . '/certs/saml-idp.crt';
        if (is_file($saml)) {
            $p = @openssl_x509_parse((string)file_get_contents($saml));
            if ($p) {
                $days = (int)ceil(((int)$p['validTo_time_t'] - $ctx->now) / 86400);
                $this->expiry($r, 'saml', 'SAML IdP', $days, $warn, $fail, 'php cli/saml_gen_cert.php');
                $r->metric('saml_days_left', $days);
                $parts[] = "SAML IdP: {$days} dni";
            }
        }
        $r->summary = $parts ? 'Ważność — ' . implode(', ', $parts) . '.' : 'Brak certyfikatów.';
    }

    private function expiry(ItControlResult $r, string $key, string $label, int $days, int $warn, int $fail, string $cmd): void {
        if ($days < 0) {
            $r->finding($key . '_expired', 'critical', "Certyfikat {$label} WYGASŁ", abs($days) . ' dni temu.');
        } elseif ($days <= $fail) {
            $r->finding($key . '_expiring', 'high', "Certyfikat {$label} wygasa za {$days} dni", '');
        } elseif ($days <= $warn) {
            $r->finding($key . '_expiring', 'medium', "Certyfikat {$label} wygasa za {$days} dni", '');
        } else {
            return;
        }
        $r->recommend("Odnów certyfikat {$label}: {$cmd}");
    }
}
