<?php
declare(strict_types=1);

/**
 * modules/dhosting_migrator/lib/DhostingCredPdf.php — PDF z danymi logowania (polecenie 2026-11-13: „pozwól też na wydruk PDF danych logowania”).
 *
 * Jedna strona A4 na osobę: poczta (adres i login, hasło — jeśli je mamy, logowanie w przeglądarce, ustawienia programu pocztowego
 * IMAP / POP3 / SMTP szyfrowane), logowanie jednokrotne i chmura plików (login, hasło, adresy). Hasła są osobne — tak też je opisujemy.
 * Administrator: z hasłami, tylko zaraz po ich nadaniu (sesja, 15 min — DhostingMigratorController). Osoba: bez haseł („z SMS-a”).
 *
 * Wpis osoby: ['name', 'mailbox', 'mail_password' (?string), 'sso_login', 'sso_password' (?string), 'sso_logto' (bool), 'sso_cloud' (bool), 'issued_at' (?string)]
 */
require_once __DIR__ . '/DhostingConfig.php';

final class DhostingCredPdf
{
    /** Bajty PDF dla listy osób. */
    public static function render(array $people, string $org): string
    {
        require_once dirname(__DIR__, 3) . '/vendor/autoload.php';
        $tmp = (defined('UPLOAD_DIR') ? rtrim((string)UPLOAD_DIR, '/') : sys_get_temp_dir()) . '/mpdf_tmp';
        if (!is_dir($tmp)) @mkdir($tmp, 0775, true);
        $pdf = new \Mpdf\Mpdf(['mode' => 'utf-8', 'format' => 'A4', 'margin_left' => 18, 'margin_right' => 18, 'margin_top' => 16, 'margin_bottom' => 16,
                               'tempDir' => is_writable($tmp) ? $tmp : sys_get_temp_dir(), 'default_font' => 'dejavusans']);
        $pdf->SetTitle('Dane logowania — ' . $org);
        $pdf->SetCreator('SZO');
        $pdf->WriteHTML(self::css(), \Mpdf\HTMLParserMode::HEADER_CSS);
        $first = true;
        foreach ($people as $p) {
            if (!$first) $pdf->AddPage();
            $first = false;
            $pdf->WriteHTML(self::page($p, $org), \Mpdf\HTMLParserMode::HTML_BODY);
        }
        return $pdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
    }

    private static function css(): string
    {
        return 'body{font-size:10.5pt;color:#111}h1{font-size:17pt;margin:0 0 2mm}h2{font-size:12.5pt;margin:6mm 0 2mm;border-bottom:0.4mm solid #0f6fa8;padding-bottom:1mm}'
             . '.who{font-size:12pt;margin:0 0 4mm}.muted{color:#555;font-size:9pt}table{border-collapse:collapse;width:100%}'
             . 'th,td{border:0.2mm solid #bbb;padding:1.6mm 2mm;text-align:left;vertical-align:top}th{background:#f2f4f7;width:42%;font-weight:bold}'
             . '.pw{font-family:dejavusansmono;font-size:13pt;letter-spacing:0.3mm}.box{border-left:1.2mm solid #d97706;background:#fff7ed;padding:2mm 3mm;margin-top:5mm}';
    }

    private static function page(array $p, string $org): string
    {
        $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $box = (string)($p['mailbox'] ?? '');
        $web = DhostingConfig::webmailOrDefault();
        [$imapHost] = DhostingConfig::imapForUsers();
        $issued = (string)($p['issued_at'] ?? '') !== '' ? date('d.m.Y H:i', (int)strtotime((string)$p['issued_at'])) : '';
        $h = '<h1>Dane logowania</h1><p class="who"><b>' . $e($p['name'] ?? '') . '</b> · ' . $e($org) . '</p>';
        if ($box !== '') {
            $pw = $p['mail_password'] ?? null;
            $h .= '<h2>Poczta</h2><table>'
                . '<tr><th>Adres e-mail i login</th><td>' . $e($box) . '</td></tr>'
                . '<tr><th>Hasło do poczty</th><td>' . ($pw !== null && $pw !== ''
                    ? '<span class="pw">' . $e($pw) . '</span>' . ($issued !== '' ? '<br><span class="muted">obowiązuje od ' . $e($issued) . '</span>' : '')
                    : 'z SMS-a (albo ustawione przez Ciebie) — nie drukujemy go') . '</td></tr>'
                . '<tr><th>Poczta w przeglądarce</th><td>' . $e($web) . '</td></tr>'
                . '<tr><th>Poczta przychodząca (IMAP, polecamy)</th><td>' . $e($imapHost) . ', port 993, SSL/TLS</td></tr>'
                . '<tr><th>Poczta przychodząca (POP3)</th><td>' . $e(DhostingConfig::POP3_HOST) . ', port 995, SSL/TLS</td></tr>'
                . '<tr><th>Poczta wychodząca (SMTP)</th><td>' . $e(DhostingConfig::SMTP_HOST) . ', port ' . (int)DhostingConfig::SMTP_PORT . ', STARTTLS, z uwierzytelnianiem (ten sam login i hasło)</td></tr>'
                . '</table>';
        }
        $ssoLogin = (string)($p['sso_login'] ?? '');
        if ($ssoLogin !== '' && (!empty($p['sso_logto']) || !empty($p['sso_cloud']))) {
            $what = !empty($p['sso_logto']) ? (!empty($p['sso_cloud']) ? 'Logowanie jednokrotne i chmura plików' : 'Logowanie jednokrotne') : 'Chmura plików';
            $spw = $p['sso_password'] ?? null;
            $h .= '<h2>' . $e($what) . '</h2><table>'
                . '<tr><th>Login</th><td>' . $e($ssoLogin) . '</td></tr>'
                . '<tr><th>Hasło</th><td>' . ($spw !== null && $spw !== '' ? '<span class="pw">' . $e($spw) . '</span>' : 'z SMS-a (albo ustawione przez Ciebie) — nie drukujemy go') . '</td></tr>';
            try {
                require_once dirname(__DIR__, 2) . '/sso_logto/lib/SsoConfig.php';
                if (!empty($p['sso_logto']) && SsoConfig::logtoUrl() !== '') $h .= '<tr><th>Logowanie jednokrotne</th><td>' . $e(SsoConfig::logtoUrl()) . '</td></tr>';
                if (!empty($p['sso_cloud']) && SsoConfig::cloudUrl() !== '') $h .= '<tr><th>Chmura plików (zamiennik OneDrive)</th><td>' . $e(SsoConfig::cloudUrl()) . '</td></tr>';
            } catch (\Throwable $ex) {}
            $h .= '</table>';
        }
        $h .= '<div class="box"><b>Ważne.</b> Hasło do poczty i hasło logowania jednokrotnego (chmury) to dwa osobne hasła — celowo, ze względów bezpieczeństwa.'
            . ' Po pierwszym logowaniu zmień hasło na własne. Przechowuj tę kartkę bezpiecznie albo ją zniszcz.'
            . ' Nikt z Fundacji nie poprosi Cię o hasło.</div>'
            . '<p class="muted" style="margin-top:6mm">Wydrukowano ' . $e(date('d.m.Y H:i')) . '.</p>';
        return $h;
    }
}
