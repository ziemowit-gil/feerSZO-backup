<?php
declare(strict_types=1);

/**
 * modules/ti_licencje_dydaktyczne/lib/LicenseZipDelivery.php — wysyłka dokumentów AM w szyfrowanym ZIP-ie (AES-256):
 * e-mail z archiwum do osoby, hasło OSOBNYM SMS-em do osoby i do kierowników panelu TI (rola „kierownik”, telefon w koncie).
 * Bez numeru telefonu osoby / kanału SMS e-mail NIE wychodzi (archiwum bez hasła byłoby bezużyteczne) — DomainException.
 * Kolejność: e-mail z ZIP-em, dopiero po jego przyjęciu SMS z hasłem. Używane przez zmianę UPN (AM90/n + AM91) i pakiet po korekcie AMSK.
 */
require_once __DIR__ . '/LicenseManagementService.php';
require_once __DIR__ . '/LicenseStorage.php';
require_once __DIR__ . '/LicenseNotificationService.php';

final class LicenseZipDelivery
{
    /** Hasło do archiwum: 10 znaków bez mylących (0/O, 1/l/I) — przepisywane z SMS-a. */
    public static function password(int $len = 10): string
    {
        $a = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
        $p = '';
        for ($i = 0; $i < $len; $i++) $p .= $a[random_int(0, strlen($a) - 1)];
        return $p;
    }

    /**
     * @param array $contact ['name','email','phone']
     * @param array $ref     ['type' => 'license'|'operation', 'id' => int] (log powiadomień)
     * @param array $entries [nazwa pliku => bajty]
     * @param bool $managerCopies kierownicy panelu TI (z e-mailem) dostają ten sam ZIP e-mailem (hasło — SMS, jak osoba)
     * @param string $bodyHtml treść e-maila; „{PHONE}” zostanie zastąpione końcówką numeru telefonu (3 cyfry)
     * @return array{report:string, mailed:bool, sms:bool, digits:string, describe:string}
     */
    public static function deliver(LicenseManagementService $svc, array $contact, string $caseNumber, array $ref, array $entries, string $subject, string $heading,
                                   string $bodyHtml, array $actor, string $zipPrefix, string $zipTitle, bool $managerCopies = false): array
    {
        $digits = preg_replace('/\D/', '', (string)($contact['phone'] ?? ''));
        require_once dirname(__DIR__, 3) . '/includes/sms.php';
        if (strlen($digits) < 9) throw new DomainException('Brak numeru telefonu osoby — nie ma dokąd wysłać hasła do archiwum (SMS). Uzupełnij telefon w sprawie i wyślij ponownie.');
        if (!sms_channel_ready()) throw new DomainException('Kanał SMS nie jest skonfigurowany — hasło do archiwum nie mogłoby dotrzeć do osoby.');
        $pass = self::password();
        [$rel, $size] = LicenseStorage::putZip($entries, $pass, $zipPrefix);
        $att = LicenseNotificationService::zipAttachment($rel, $zipTitle, $size);
        $res = $svc->notify->send(LicenseNotificationType::OperationConfirm, $contact, $subject,
            LicenseNotificationService::layout($heading, str_replace('{PHONE}', htmlspecialchars(substr($digits, -3), ENT_QUOTES, 'UTF-8'), $bodyHtml), '', '', 'AM90'), '', $ref, $actor, true, $att);
        $mailed = !empty($res['delivered']) || in_array('queued', (array)($res['channels'] ?? []), true);
        $mgrMailed = 0;
        if ($managerCopies && $mailed) {
            foreach (db_all("SELECT DISTINCT u.name, u.email, u.phone_number FROM k30_ti_panel_roles r JOIN users u ON u.id = r.user_id
                              WHERE r.role = 'kierownik' AND u.is_active = 1 AND COALESCE(u.email, '') <> ''") as $mg) {
                $note = '<p><i>Kopia dla kierownika — hasło do archiwum dostaniesz SMS-em na numer zapisany w koncie (osoba, której dotyczy sprawa, dostaje je osobno).</i></p>';
                $r2 = $svc->notify->send(LicenseNotificationType::OperationConfirm, ['name' => (string)$mg['name'], 'email' => (string)$mg['email'], 'phone' => ''], '[kopia dla kierownika] ' . $subject,
                    LicenseNotificationService::layout($heading, $note . str_replace('{PHONE}', htmlspecialchars(substr($digits, -3), ENT_QUOTES, 'UTF-8'), $bodyHtml), '', '', 'AM90'), '', $ref, $actor, false, $att);
                if (!empty($r2['delivered']) || in_array('queued', (array)($r2['channels'] ?? []), true)) $mgrMailed++;
            }
        }
        $out = ['report' => '', 'mailed' => $mailed, 'sms' => false, 'digits' => $digits, 'describe' => $svc->describe($res)];
        $report = 'ZIP: ' . $out['describe'] . ($managerCopies ? '; kopia ZIP e-mailem do kierownika: ' . $mgrMailed : '');
        if (!$mailed) { $out['report'] = $report . ' — SMS z hasłem nie został wysłany (e-mail nie wyszedł)'; return $out; }
        try {
            require_once dirname(__DIR__, 3) . '/includes/sms_templates.php';
            $m = sms_tpl_render('ti_am7_docs_zip_password', ['case' => $caseNumber, 'password' => $pass]);
            sms_send((string)$contact['phone'], trim((string)$m['message']), 'ti_am7_docs_zip_password');
            $out['sms'] = true;
            $report .= '; hasło do archiwum wysłane SMS-em';
        } catch (\Throwable $e) {
            $report .= '; UWAGA: SMS z hasłem NIE wysłany (' . $e->getMessage() . ') — wyślij ponownie (nowe archiwum i hasło)';
        }
        $out['report'] = $report . self::notifyManagers($caseNumber, (string)($contact['name'] ?? ''), $pass);
        return $out;
    }

    /** SMS z hasłem do archiwum do kierowników panelu TI (telefon w koncie). @return string fragment raportu */
    public static function notifyManagers(string $caseNumber, string $person, string $pass): string
    {
        $sent = 0;
        try {
            require_once dirname(__DIR__, 3) . '/includes/sms.php';
            require_once dirname(__DIR__, 3) . '/includes/sms_templates.php';
            $m = sms_tpl_render('ti_am7_docs_zip_password_mgr', ['case' => $caseNumber, 'person' => $person, 'password' => $pass]);
            if (empty($m['enabled']) || trim((string)$m['message']) === '') return '; SMS do kierownika wyłączony w szablonach';
            foreach (db_all("SELECT DISTINCT u.id, u.phone_number FROM k30_ti_panel_roles r JOIN users u ON u.id = r.user_id
                              WHERE r.role = 'kierownik' AND u.is_active = 1 AND COALESCE(u.phone_number, '') <> ''") as $u) {
                try { sms_send((string)$u['phone_number'], trim((string)$m['message']), 'ti_am7_docs_zip_password_mgr'); $sent++; } catch (\Throwable $e) { /* jeden błąd nie blokuje reszty */ }
            }
        } catch (\Throwable $e) { return '; SMS do kierownika NIE wysłany (' . $e->getMessage() . ')'; }
        return $sent > 0 ? '; hasło wysłane SMS-em także do kierownika (' . $sent . ')' : '; brak kierownika z numerem telefonu w koncie — SMS dla kierownika pominięty';
    }
}
