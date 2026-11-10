<?php
declare(strict_types=1);

/**
 * modules/ti_licencje_kursant/logic/LicencjePanelAccess.php — dane logowania do Panelu Licencji wysyłane przez kadrę (polecenie 2026-11-10).
 *
 * Login „l” + 8 cyfr (nadawany raz, modules/license_login) i nowe hasło zakłada kadra w panelu Płatne licencje › Uczestnicy.
 * Tylko dla uczestników z licencjami albo produktami:
 *   • płatne licencje Claude / NordVPN (wniosek, aktywna, wstrzymana), programy i klucze (aktywne), licencje AM5 (przydział) i Office AM7 (przyznana),
 *   • produkty i usługi (domeny, hosting…) — bezpłatne zawsze, płatne (kwota miesięczna albo forma FAK/FAZ) dopiero z fakturą VAT
 *     (nr faktury „Nasza” w produkcie albo faktura VAT za płatną licencję).
 * Wysyłka: e-mail z adresem panelu i loginem, SMS z hasłem (o każdej porze, jak kod jednorazowy). Hasło pokazujemy kadrze tylko wtedy,
 * gdy SMS nie wyszedł. Konto testowe (wirtualne): bez wysyłek, dane na ekranie.
 */
require_once dirname(__DIR__, 2) . '/license_login/logic/licenseLogin.php';
require_once dirname(__DIR__, 2) . '/claude_billing/logic/claudeBilling.php';
require_once __DIR__ . '/LicencjePanelAuth.php';

final class LicencjePanelAccess
{
    /** Co uprawnia do panelu. @return list<string> (puste = brak uprawnienia) */
    public static function reasons(int $cid): array
    {
        $out = [];
        try {
            foreach (db_all("SELECT product, status FROM claude_subscriptions WHERE client_id=? AND is_test=0 AND status IN ('requested','active','suspended')", [$cid]) as $r) {
                $out[] = ClaudeBilling::P((string)$r['product'])['label'] . ' (' . (ClaudeBilling::STATUS[$r['status']] ?? $r['status']) . ')';
            }
        } catch (\Throwable $e) {}
        try {
            foreach (db_all("SELECT name FROM k30_ti_client_licenses WHERE client_id=? AND status='active' AND (expires_at IS NULL OR expires_at='' OR expires_at >= date('now'))", [$cid]) as $r) $out[] = 'program: ' . $r['name'];
        } catch (\Throwable $e) {}
        try {
            $n = (int)(db_one("SELECT COUNT(*) n FROM license_assignments a JOIN licenses l ON l.id=a.license_id WHERE a.holder_kind='client' AND a.holder_id=? AND a.status NOT IN ('revoked','cancelled')", [$cid])['n'] ?? 0);
            if ($n) $out[] = 'licencje AM5 (' . $n . ')';
            $n = (int)(db_one("SELECT COUNT(*) n FROM licenses WHERE interested_kind='client' AND interested_id=? AND COALESCE(form_kind,'AM5')='AM7' AND request_status='granted'", [$cid])['n'] ?? 0);
            if ($n) $out[] = 'Office (AM7)';
        } catch (\Throwable $e) {}
        try {
            require_once dirname(__DIR__, 2) . '/ti_products/logic/tiProducts.php';
            foreach (TiProducts::forClient($cid) as $p) {
                if ($p['status'] === 'archived') continue;
                $paid = (float)($p['monthly_amount'] ?? 0) > 0 || in_array((string)($p['billing_form'] ?? ''), ['FAK', 'FAZ'], true);
                if (!$paid) { $out[] = 'usługa: ' . $p['name']; continue; }
                if (trim((string)$p['invoice_own']) !== '') $out[] = 'usługa płatna: ' . $p['name'] . ' (FV ' . trim((string)$p['invoice_own']) . ')';
            }
        } catch (\Throwable $e) {}
        return array_values(array_unique($out));
    }

    /** Płatne produkty bez faktury VAT (podpowiedź dla kadry, dlaczego nie można wysłać danych). @return list<string> */
    public static function paidWithoutInvoice(int $cid): array
    {
        $out = [];
        try {
            require_once dirname(__DIR__, 2) . '/ti_products/logic/tiProducts.php';
            foreach (TiProducts::forClient($cid) as $p) {
                if ($p['status'] === 'archived') continue;
                $paid = (float)($p['monthly_amount'] ?? 0) > 0 || in_array((string)($p['billing_form'] ?? ''), ['FAK', 'FAZ'], true);
                if ($paid && trim((string)$p['invoice_own']) === '') $out[] = (string)$p['name'];
            }
        } catch (\Throwable $e) {}
        return $out;
    }

    /** Uczestnicy z licencjami lub produktami, którzy nie mają jeszcze hasła do panelu (do listy w panelu kadry). @return list<array{id:int,name:string,reasons:list<string>}> */
    public static function candidates(int $limit = 60): array
    {
        LicenseLogin::migrate();
        $ids = [];
        $q = [
            "SELECT DISTINCT client_id id FROM claude_subscriptions WHERE is_test=0 AND status IN ('requested','active','suspended')",
            "SELECT DISTINCT client_id id FROM k30_ti_client_licenses WHERE status='active'",
            "SELECT DISTINCT holder_id id FROM license_assignments WHERE holder_kind='client' AND status NOT IN ('revoked','cancelled')",
            "SELECT DISTINCT interested_id id FROM licenses WHERE interested_kind='client' AND COALESCE(form_kind,'AM5')='AM7' AND request_status='granted'",
            "SELECT DISTINCT client_id id FROM k30_ti_client_products WHERE status<>'archived'",
        ];
        foreach ($q as $sql) { try { foreach (db_all($sql) as $r) if ((int)$r['id'] > 0) $ids[(int)$r['id']] = true; } catch (\Throwable $e) {} }
        $out = [];
        foreach (array_keys($ids) as $cid) {
            if (LicenseLogin::hasPassword($cid) || ClaudeBilling::isVirtual($cid)) continue;
            $c = db_one("SELECT id, name FROM k30_clients WHERE id=?", [$cid]);
            if (!$c || trim((string)$c['name']) === '') continue;
            $why = self::reasons($cid);
            if (!$why) continue;
            $out[] = ['id' => $cid, 'name' => (string)$c['name'], 'reasons' => $why];
        }
        usort($out, fn($a, $b) => strcoll($a['name'], $b['name']));
        return array_slice($out, 0, $limit);
    }

    /**
     * Zakłada (albo odnawia) dane do panelu i wysyła je: e-mail z adresem i loginem, SMS z hasłem.
     * @return array{login:string,new:bool,email:bool,sms:bool,password:string,text:string}
     */
    public static function issue(int $cid, array $actor): array
    {
        $c = db_one("SELECT id, name, email, phone FROM k30_clients WHERE id=?", [$cid]);
        if (!$c) throw new DomainException('Nie ma takiego uczestnika.');
        $virtual = ClaudeBilling::isVirtual($cid);
        if (!$virtual && !self::reasons($cid)) {
            $pw = self::paidWithoutInvoice($cid);
            throw new DomainException($c['name'] . ' nie ma licencji ani produktów, więc nie dostanie danych do Panelu Licencji.'
                . ($pw ? ' Płatne usługi bez faktury VAT (' . implode(', ', $pw) . ') liczą się dopiero po jej wystawieniu.' : ''));
        }
        $kontakt = (function () use ($cid) { require_once __DIR__ . '/LicencjeKod.php'; return LicencjeKod::contact($cid); })();
        $email = trim((string)($kontakt['email'] ?? '') ?: (string)$c['email']);
        $phone = trim((string)($kontakt['phone'] ?? '') ?: (string)$c['phone']);
        if (!$virtual && !filter_var($email, FILTER_VALIDATE_EMAIL) && preg_replace('/\D/', '', $phone) === '') {
            throw new DomainException($c['name'] . ' nie ma w kartotece ani e-maila, ani telefonu. Uzupełnij je w Ewidencji Uczestników.');
        }
        $by = (string)($actor['name'] ?? 'kadra');
        $new = !LicenseLogin::get($cid);
        $login = LicenseLogin::generate($cid, $by . ' (Płatne licencje)');
        $pass = LicenseLogin::issuePassword($cid);
        db_exec("UPDATE license_logins SET is_active=1 WHERE client_id=?", [$cid]);
        $url = LicencjePanelAuth::publicUrl();
        $okE = $okS = false;
        if (!$virtual) {
            // najpierw SMS z hasłem — treść e-maila zależy od tego, czy wyszedł
            if (preg_replace('/\D/', '', $phone) !== '') {
                require_once dirname(__DIR__, 3) . '/includes/sms.php';
                $GLOBALS['__sms_quiet_bypass'] = true;
                try { $okS = !in_array(sms_send($phone, 'FEER: haslo do Panelu Licencji (login ' . $login . '): ' . $pass . '. Zmienisz je po zalogowaniu: ' . preg_replace('#^https://#', '', rtrim($url, '/')), 'access_data'), ['', 'odlozony'], true); }
                catch (\Throwable $e) { error_log('licencje panel sms: ' . $e->getMessage()); }
                unset($GLOBALS['__sms_quiet_bypass']);
            }
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                require_once dirname(__DIR__, 3) . '/includes/mail_queue.php';
                $end = substr(preg_replace('/\D/', '', $phone), -3);
                $html = '<p>Dzień dobry,</p>'
                    . '<p>w Panelu Licencji widzisz swoje licencje i usługi z Fundacji FEER: subskrypcje, konto Office, programy i faktury. Tam też zapłacisz za subskrypcję i pobierzesz dokumenty.</p>'
                    . '<p>Adres: <a href="' . h($url) . '">' . h($url) . '</a><br>Login: <b>' . h($login) . '</b></p>'
                    . '<p>' . ($okS ? 'Hasło przyszło osobno SMS-em na numer kończący się na ' . h($end) . '.' : 'Hasło przekaże Ci biuro.') . ' Po zalogowaniu możesz je zmienić w zakładce „Moje konto”.</p>'
                    . '<p>Przy pierwszym wejściu poprosimy o potwierdzenie telefonu i e-maila. Każdą zmianę w panelu potwierdzasz kodem SMS.</p>'
                    . '<p>Jeśli nie spodziewasz się tej wiadomości, odpisz na nią.</p>';
                try { mail_queue_add($email, (string)$c['name'], 'Dostęp do Panelu Licencji', $html, '', 'licencje_panel', $cid, '', true); $okE = true; } catch (\Throwable $e) { error_log('licencje panel mail: ' . $e->getMessage()); }
            }
        }
        $txt = $virtual ? 'Konto testowe: bez wysyłek.' : (($okE ? 'E-mail z adresem i loginem wysłany.' : 'E-mail nie wyszedł.') . ' ' . ($okS ? 'Hasło poszło SMS-em.' : 'SMS z hasłem nie wyszedł, przekaż hasło osobiście.'));
        ClaudeBilling::event(null, null, 'lic_account', ($new ? 'Założono' : 'Nowe hasło:') . ' dane do Panelu Licencji dla ' . $c['name'] . ' (login ' . $login . '). ' . $txt, $by);
        return ['login' => $login, 'new' => $new, 'email' => $okE, 'sms' => $okS, 'password' => ($okS ? '' : $pass), 'text' => $txt, 'url' => $url];
    }
}
