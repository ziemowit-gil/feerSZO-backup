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
        $pdf->SetTitle('Dane logowania, ' . $org);
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

    /**
     * Układ (2026-11-14, polecenie „przebuduj layout, ten bardzo pachnie AI”): kartka z danymi zamiast tabeli z szarymi nagłówkami
     * i pomarańczową ramką. Po lewej nazwa sekcji, po prawej pola: mała etykieta nad dużą wartością. Ustawienia serwerów jednym
     * zdaniem (programy same je znajdują), na dole krótka stopka. Bez myślników i pogrubionych etykiet.
     */
    private static function css(): string
    {
        return 'body{font-family:dejavusans;font-size:10.5pt;color:#1f2937}'
             . '.org{font-size:8.5pt;color:#6b7280;letter-spacing:0.4mm;text-transform:uppercase}'
             . 'h1{font-size:21pt;font-weight:bold;color:#111827;margin:1.5mm 0 0}'
             . '.who{font-size:12.5pt;color:#374151;margin:1mm 0 9mm}'
             . 'table.sec{width:100%;border-collapse:collapse;margin:0 0 8mm}'
             . 'table.sec td{vertical-align:top;padding:0}'
             . 'td.name{width:28%;padding-top:2mm;border-top:0.7mm solid #0f6fa8;font-size:9pt;font-weight:bold;color:#0f6fa8;text-transform:uppercase;letter-spacing:0.3mm}'
             . 'td.body{padding-top:0.5mm;border-top:0.25mm solid #d1d5db}'
             . 'table.f{width:100%;border-collapse:collapse}table.f td{padding:3.2mm 0 0;vertical-align:top}'
             . '.lbl{font-size:8.5pt;color:#6b7280}'
             . '.val{font-size:13pt;color:#111827}'
             . '.soft{font-size:11pt;color:#4b5563}'
             . '.pw{font-family:dejavusansmono;font-size:14pt;letter-spacing:0.4mm}'
             . '.note{font-size:9.5pt;color:#374151;line-height:1.45}table.f td.n{padding-top:4.5mm}'
             . '.foot{border-top:0.25mm solid #d1d5db;padding-top:3.5mm;font-size:9pt;color:#4b5563;line-height:1.5}';
    }

    /** Pole: etykieta i wartość (wartość już w HTML). */
    private static function field(string $label, string $valueHtml, string $cls = 'val'): string
    {
        return '<tr><td><div class="lbl">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</div><div class="' . $cls . '">' . $valueHtml . '</div></td></tr>';
    }

    /** Sekcja: nazwa po lewej, pola po prawej. */
    private static function section(string $name, string $bodyHtml): string
    {
        return '<table class="sec"><tr><td class="name">' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</td><td class="body"><table class="f">' . $bodyHtml . '</table></td></tr></table>';
    }

    private static function page(array $p, string $org): string
    {
        $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $box = (string)($p['mailbox'] ?? '');
        $web = DhostingConfig::webmailOrDefault();
        $webHost = (string)(parse_url($web, PHP_URL_HOST) ?: $web);
        [$imapHost] = DhostingConfig::imapForUsers();
        $issued = (string)($p['issued_at'] ?? '') !== '' ? date('d.m.Y, H:i', (int)strtotime((string)$p['issued_at'])) : '';
        $noPw = '<span class="soft">Z SMS-a albo ustawione przez Ciebie. Nie drukujemy go.</span>';

        $h = '<div class="org">' . $e($org) . '</div><h1>Dane logowania</h1><div class="who">' . $e($p['name'] ?? '') . '</div>';
        if ($box !== '') {
            $pw = $p['mail_password'] ?? null;
            $body = self::field('Adres e-mail i login', $e($box))
                  . self::field('Hasło do poczty', ($pw !== null && $pw !== '')
                        ? '<span class="pw">' . $e($pw) . '</span>' . ($issued !== '' ? '<div class="soft">obowiązuje od ' . $e($issued) . '</div>' : '')
                        : $noPw)
                  . self::field('Poczta w przeglądarce', $e($webHost))
                  . '<tr><td class="n"><div class="note">W telefonie i programie pocztowym wpisz tylko adres e-mail i hasło. Resztę ustawień program znajdzie sam. '
                  . 'Gdyby zapytał o serwery: IMAP ' . $e($imapHost) . ', port 993, SSL. SMTP ' . $e(DhostingConfig::SMTP_HOST) . ', port ' . (int)DhostingConfig::SMTP_PORT
                  . ', STARTTLS, z tym samym loginem i hasłem. POP3 ' . $e(DhostingConfig::POP3_HOST) . ', port 995, SSL.</div></td></tr>';
            $h .= self::section('Poczta', $body);
        }
        $ssoLogin = (string)($p['sso_login'] ?? '');
        if ($ssoLogin !== '' && (!empty($p['sso_logto']) || !empty($p['sso_cloud']))) {
            $what = !empty($p['sso_logto']) ? (!empty($p['sso_cloud']) ? 'Logowanie jednokrotne i chmura' : 'Logowanie jednokrotne') : 'Chmura plików';
            $spw = $p['sso_password'] ?? null;
            $body = self::field('Login', $e($ssoLogin))
                  . self::field('Hasło', ($spw !== null && $spw !== '') ? '<span class="pw">' . $e($spw) . '</span>' : $noPw);
            try {
                require_once dirname(__DIR__, 2) . '/sso_logto/lib/SsoConfig.php';
                if (!empty($p['sso_logto']) && SsoConfig::logtoUrl() !== '') $body .= self::field('Logowanie jednokrotne', $e(preg_replace('#^https?://#', '', SsoConfig::logtoUrl())));
                if (!empty($p['sso_cloud']) && SsoConfig::cloudUrl() !== '') $body .= self::field('Chmura plików', $e(preg_replace('#^https?://#', '', SsoConfig::cloudUrl())));
            } catch (\Throwable $ex) {}
            $h .= self::section($what, $body);
        }
        $h .= '<div class="foot">Hasło do poczty i hasło logowania jednokrotnego są różne. Po pierwszym logowaniu zmień je na własne. '
            . 'Kartkę przechowuj w bezpiecznym miejscu albo ją zniszcz. Nikt z Fundacji nie poprosi Cię o hasło.<br>'
            . 'Wydrukowano ' . $e(date('d.m.Y, H:i')) . '.</div>';
        return $h;
    }
}
