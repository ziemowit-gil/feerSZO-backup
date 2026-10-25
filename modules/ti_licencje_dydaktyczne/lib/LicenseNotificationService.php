<?php
declare(strict_types=1);

/**
 * modules/ti_licencje_dydaktyczne/lib/LicenseNotificationService.php — powiadomienia e-mail / SMS modułu AM5 z fallbackami
 * i dziennikiem w notification_logs (każda próba: kanał, rodzaj, adresat zamaskowany, sent|queued|failed|skipped, błąd).
 *
 * Zasady fallbacku (kolejno):
 *   1. Kanały podstawowe rodzaju (np. wszczęcie: e-mail ORAZ SMS; licencjodawca: e-mail).
 *   2. Brak adresu lub błąd kanału podstawowego, a drugi kanał nie był planowany → próba drugim kanałem (is_fallback=1).
 *      E-mail, który nie wyszedł od razu, zostaje w mail_queue (ponowi CRON) — liczy się jako „queued”, nie jako dostarczony,
 *      więc też uruchamia fallback SMS.
 *   3. Nic nie dotarło do osoby → alert do biura (setting ld_alert_email → org_email → administratorzy) z prośbą o kontakt
 *      telefoniczny/pocztą; sam alert też ma fallback na listę administratorów.
 *   4. SMS bez polskich znaków (160 znaków/część), rodzaj ti_licencja_am5 (modules/sms_senders). Kursanci wirtualni — blokuje rdzeń.
 * Wynik send(): ['delivered' => bool, 'channels' => ['email' => status, 'sms' => status], 'errors' => list<string>].
 */
require_once __DIR__ . '/ldSchema.php';
require_once __DIR__ . '/LicenseEnums.php';
require_once __DIR__ . '/LicenseStorage.php';

final class LicenseNotificationService
{
    public const MODULE = 'ti_licencje_dyd';
    public const SMS_KIND = 'ti_licencja_am5';

    /** $module — znacznik w notification_logs i kontekst mail_queue (np. 'ti_dop' dla wniosków DOP1/DOP2). */
    public function __construct(private readonly string $module = self::MODULE) {}

    /** Podstawowe kanały rodzaju powiadomienia. */
    private static function primary(LicenseNotificationType $t): array
    {
        return match ($t) {
            LicenseNotificationType::RequestStarted, LicenseNotificationType::LicenseAssigned,
            LicenseNotificationType::DecisionInterested, LicenseNotificationType::Reminder => ['email', 'sms'],
            LicenseNotificationType::DeclarationConfirm, LicenseNotificationType::DeclarationDecided => ['email'],
            LicenseNotificationType::LicensorRequest, LicenseNotificationType::DecisionAdmin, LicenseNotificationType::DeclarationAdmin,
            LicenseNotificationType::RequestSigned, LicenseNotificationType::HelpdeskTicket, LicenseNotificationType::Statement => ['email'],
        };
    }

    /**
     * @param array{name:string, email?:string, phone?:string} $to
     * @param array{type:string, id:int} $ref  ('license' | 'assignment')
     */
    public function send(LicenseNotificationType $type, array $to, string $subject, string $html, string $sms, array $ref, array $actor = [], bool $alertOnFail = true, array $attachments = []): array
    {
        $out = ['delivered' => false, 'channels' => [], 'errors' => []];
        $email = trim((string)($to['email'] ?? ''));
        $phone = trim((string)($to['phone'] ?? ''));
        $email = filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
        $phone = strlen(preg_replace('/\D/', '', $phone)) >= 9 ? $phone : '';
        $plan = self::primary($type);
        $name = (string)($to['name'] ?? '');

        foreach ($plan as $ch) {
            $st = $ch === 'email' ? $this->email($type, $email, $name, $subject, $html, $ref, $actor, false, $attachments)
                                  : $this->sms($type, $phone, $sms, $ref, $actor, false);
            $out['channels'][$ch] = $st;
        }
        // Fallback: drugi kanał, gdy podstawowy nie dotarł i drugiego nie było w planie
        $ok = fn() => in_array('sent', $out['channels'], true);
        if (!$ok()) {
            if (!in_array('sms', $plan, true) && $sms !== '' && $phone !== '') $out['channels']['sms'] = $this->sms($type, $phone, $sms, $ref, $actor, true);
            if (!in_array('email', $plan, true) && $email !== '' && $html !== '') $out['channels']['email'] = $this->email($type, $email, $name, $subject, $html, $ref, $actor, true, $attachments);
        }
        $out['delivered'] = $ok();
        foreach ($out['channels'] as $ch => $st) if ($st !== 'sent') $out['errors'][] = ($name !== '' ? $name . ' — ' : '') . ($ch === 'email' ? 'e-mail' : 'SMS') . ': ' . self::statusLabel($st);

        // Nic nie dotarło (poza e-mailem czekającym w kolejce) → alert do biura
        if (!$ok() && $alertOnFail && !in_array($type, [LicenseNotificationType::DecisionAdmin, LicenseNotificationType::DeclarationAdmin, LicenseNotificationType::RequestSigned, LicenseNotificationType::HelpdeskTicket], true)) {
            $queued = in_array('queued', $out['channels'], true);
            $this->alertOffice('Nie dostarczono powiadomienia: ' . $type->label(),
                '<p>Powiadomienie <b>' . htmlspecialchars($type->label()) . '</b> do <b>' . htmlspecialchars($name ?: '—') . '</b> nie zostało dostarczone'
                . ($queued ? ' od razu (e-mail czeka w kolejce na ponowienie)' : '') . '.</p><p>Kanały: ' . htmlspecialchars(implode(', ', array_map(fn($c, $s) => $c . ' — ' . self::statusLabel($s), array_keys($out['channels']), $out['channels']))) . '.</p>'
                . '<p>Prosimy o kontakt z osobą inną drogą (telefon, poczta) i uzupełnienie danych kontaktowych w panelu.</p>', $ref, $actor);
        }
        return $out;
    }

    /**
     * Załącznik mail_queue z pliku dokumentu (tylko PDF; HTML-owy fallback zostaje w module).
     * UWAGA: mail_queue oczekuje ścieżki WZGLĘDNEJ wobec UPLOAD_DIR (sam dokleja UPLOAD_DIR) — bezwzględna jest po cichu pomijana.
     */
    public static function attachment(string $rel, string $name): array
    {
        $p = LicenseStorage::fullPath($rel);
        if (!$p || !str_ends_with($p, '.pdf')) return [];
        return [['path' => ltrim($rel, '/'), 'name' => ldFileName($name) . '.pdf', 'mime' => 'application/pdf', 'size' => (int)filesize($p)]];
    }

    public static function statusLabel(string $s): string
    {
        return match ($s) {
            'sent'    => 'wysłano',
            'queued'  => 'w kolejce (ponowi CRON)',
            'failed'  => 'błąd wysyłki',
            'skipped' => 'brak adresu / pominięto',
            default   => $s,
        };
    }

    private function email(LicenseNotificationType $type, string $email, string $name, string $subject, string $html, array $ref, array $actor, bool $fallback, array $attachments = []): string
    {
        if ($email === '') { $this->log($type, 'email', '', 'skipped', 'brak adresu e-mail', $ref, $actor, $fallback); return 'skipped'; }
        if ($html === '') return 'skipped';
        if (!function_exists('mail_queue_add')) require_once dirname(__DIR__, 3) . '/includes/mail_queue.php';
        try {
            $id = mail_queue_add($email, $name, $subject, $html, '', $this->module, (int)$ref['id'], '', true, $attachments, '', [], self::adminCopy($email));
            if ($id <= 0) { $this->log($type, 'email', $email, 'skipped', 'adres zablokowany (konto wirtualne)', $ref, $actor, $fallback); return 'skipped'; }
            $row = db_one("SELECT status, last_error FROM mail_queue WHERE id = ?", [$id]);
            $st = ($row['status'] ?? '') === 'sent' ? 'sent' : 'queued';
            $this->log($type, 'email', $email, $st, $st === 'sent' ? '' : (string)($row['last_error'] ?? ''), $ref, $actor, $fallback, $id);
            return $st;
        } catch (\Throwable $e) {
            $this->log($type, 'email', $email, 'failed', $e->getMessage(), $ref, $actor, $fallback);
            return 'failed';
        }
    }

    private function sms(LicenseNotificationType $type, string $phone, string $text, array $ref, array $actor, bool $fallback): string
    {
        if ($text === '') return 'skipped';
        if ($phone === '') { $this->log($type, 'sms', '', 'skipped', 'brak numeru telefonu', $ref, $actor, $fallback); return 'skipped'; }
        if (!function_exists('sms_send')) require_once dirname(__DIR__, 3) . '/includes/sms.php';
        try {
            // rodzaj SMS musi być literałem (cli/smsKindsLint.php): wnioski DOP mają własny rodzaj
            $r = $this->module === 'ti_dop' ? sms_send($phone, self::ascii($text), 'ti_dop_notice') : sms_send($phone, self::ascii($text), 'ti_licencja_am5');
            $err = '';
            if ($r === '' && function_exists('sms_last_send')) $err = implode('; ', array_map('strval', (array)(sms_last_send()['errors'] ?? [])));
            $this->log($type, 'sms', $phone, $r !== '' ? 'sent' : 'failed', $r === 'odlozony' ? 'odłożony do 7:00 (cisza nocna)' : $err, $ref, $actor, $fallback);
            return $r !== '' ? 'sent' : 'failed';
        } catch (\Throwable $e) {
            $this->log($type, 'sms', $phone, 'failed', $e->getMessage(), $ref, $actor, $fallback);
            return 'failed';
        }
    }

    /** Alert do biura (weryfikacja, decyzje, problemy z doręczeniem). Fallback adresów: ustawienie → e-mail organizacji → administratorzy. */
    public function alertOffice(string $subject, string $bodyHtml, array $ref, array $actor = [], LicenseNotificationType $type = LicenseNotificationType::DeclarationAdmin): bool
    {
        $any = false;
        foreach (self::officeRecipients() as $mail) {
            $tag = $this->module === 'ti_dop' ? '[Rozliczenia]' : '[Licencje]';
            $st = $this->email($type, $mail, 'Biuro', $tag . ' ' . $subject, self::layout($subject, $bodyHtml, '', '', $this->module === 'ti_dop' ? 'SZO' : LD_SYMBOL), $ref, $actor, false);
            $any = $any || $st === 'sent' || $st === 'queued';
        }
        return $any;
    }

    /** @return list<string> */
    /**
     * Adresy UDW z kopią każdego maila AM5/AM7 dla administratora (ustawienie ti_products_admin_email, a gdy puste — konta z rolą admin).
     * Adresata nie dublujemy. @return list<string>
     */
    public static function adminCopy(string $recipient): array
    {
        try {
            require_once dirname(__DIR__, 2) . '/ti_products/logic/tiProducts.php';
            return array_values(array_filter(TiProducts::adminEmails(), fn($a) => strcasecmp($a, trim($recipient)) !== 0));
        } catch (\Throwable $e) { return []; }
    }

    public static function officeRecipients(): array
    {
        $raw = '';
        try { $raw = (string)(db_one("SELECT value FROM settings WHERE key_ = 'ld_alert_email'")['value'] ?? ''); } catch (\Throwable $e) {}
        $list = array_values(array_filter(array_map('trim', preg_split('/[,;\s]+/', $raw) ?: []), fn($m) => filter_var($m, FILTER_VALIDATE_EMAIL)));
        if (!$list && filter_var((string)org_setting('org_email'), FILTER_VALIDATE_EMAIL)) $list = [(string)org_setting('org_email')];
        if (!$list) {
            try { $list = array_values(array_filter(array_column(db_all("SELECT email FROM users WHERE role = 'admin' AND email <> '' ORDER BY id LIMIT 3"), 'email'), fn($m) => filter_var($m, FILTER_VALIDATE_EMAIL))); } catch (\Throwable $e) {}
        }
        return array_values(array_unique($list));
    }

    private function log(LicenseNotificationType $type, string $channel, string $to, string $status, string $error, array $ref, array $actor, bool $fallback, ?int $queueId = null): void
    {
        try {
            db_insert('notification_logs', ['module' => $this->module, 'ref_type' => (string)$ref['type'], 'ref_id' => (int)$ref['id'], 'type' => $type->value,
                'channel' => $channel, 'recipient' => $channel === 'email' ? self::maskEmail($to) : self::maskPhone($to), 'status' => $status,
                'error' => mb_substr(preg_replace('/[\w.+-]+@[\w.-]+/', '***@***', $error) ?? '', 0, 500), 'is_fallback' => $fallback ? 1 : 0,
                'queue_id' => $queueId, 'actor_id' => ($actor['id'] ?? 0) ?: null, 'created_at' => ldNow()]);
        } catch (\Throwable $e) { error_log('ld notification log: ' . $e->getMessage()); }
    }

    public static function maskEmail(string $e): string
    {
        if ($e === '' || !str_contains($e, '@')) return '';
        [$u, $d] = explode('@', $e, 2);
        return mb_substr($u, 0, 2) . '***@' . $d;
    }

    public static function maskPhone(string $p): string
    {
        $d = preg_replace('/\D/', '', $p);
        return $d === '' ? '' : '***' . substr($d, -3);
    }

    /** Tekst SMS bez polskich znaków. */
    public static function ascii(string $s): string
    {
        $s = strtr($s, ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
                        'Ą' => 'A', 'Ć' => 'C', 'Ę' => 'E', 'Ł' => 'L', 'Ń' => 'N', 'Ó' => 'O', 'Ś' => 'S', 'Ź' => 'Z', 'Ż' => 'Z', '–' => '-', '—' => '-', '„' => '"', '”' => '"']);
        return preg_replace('/[^\x20-\x7E]/', '', $s) ?? '';
    }

    /** Podpis w stopce e-maila wg symbolu dokumentu — dokumenty rozliczeniowe (DOP, ROP, PRZ, RKO, RM…) to nie sprawy AM. */
    public static function footerLabel(string $symbol): string
    {
        return match (true) {
            $symbol === 'AM5' => 'Licencje dydaktyczne (AM5)',
            $symbol === 'AM7' => 'Licencje do oprogramowania (AM7)',
            $symbol === 'AMZ' => 'Zestawienie licencji i usług (AMZ)',
            (bool)preg_match('/^AM\d/', $symbol) => 'Operacje na licencjach (' . $symbol . ')',
            in_array($symbol, ['REZ1', 'REZ2', 'ZPRO'], true) => 'Dokumenty uczestnika (' . $symbol . ')',
            default => 'Dokumenty rozliczeniowe uczestnika' . ($symbol !== '' && $symbol !== 'SZO' ? ' (' . $symbol . ')' : ''),
        };
    }

    /** Szata e-maila w stylu Metro: płaski pasek cyan, kafel z symbolem AM5, bez zaokrągleń. */
    public static function layout(string $title, string $body, string $buttonUrl = '', string $buttonLabel = '', string $symbol = LD_SYMBOL): string
    {
        $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        return '<div style="font-family:Segoe UI,Arial,sans-serif;font-size:14px;line-height:1.5;color:#1d1d1d;max-width:640px;background:#fff">'
            . '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="border-collapse:collapse"><tr>'
            . '<td style="background:#1ba1e2;color:#fff;width:84px;text-align:center;font:600 22px Segoe UI,Arial,sans-serif;padding:14px 0">' . $e($symbol) . '</td>'
            . '<td style="background:#0050ef;color:#fff;padding:14px 18px;font:300 19px Segoe UI Light,Segoe UI,Arial,sans-serif">' . $e($title) . '</td></tr></table>'
            . '<div style="padding:18px;border:1px solid #d6d6d6;border-top:0">' . $body
            . ($buttonUrl !== '' ? '<p style="margin:20px 0"><a href="' . $e($buttonUrl) . '" style="background:#1ba1e2;color:#fff;text-decoration:none;padding:11px 22px;display:inline-block;font-weight:600">' . $e($buttonLabel) . '</a></p>'
                                 . '<p style="font-size:12px;color:#666">Jeśli przycisk nie działa, skopiuj adres: ' . $e($buttonUrl) . '</p>' : '')
            . '<p style="margin-top:18px;font-size:12px;color:#666">' . $e(ldOrgName()) . ' · ' . $e(self::footerLabel($symbol)) . '</p></div></div>';
    }
}
