<?php
declare(strict_types=1);

/**
 * modules/ti_licencje_dydaktyczne/lib/Am7TenantAccount.php — „Utwórz konto w tenancie” dla przyznanego wniosku AM7 (Office / oprogramowanie)
 * z dostępem „konto w tenancie ośrodka”.
 *
 * Do wyboru dwa tenanty: FEER (główny, konta organizacji — TiM365Feer) albo EDU FEER (szkoleniowy — m365_edu()).
 * Działa jak przydział („Przydzielona”): jeśli licencja nie ma jeszcze aktywnego przydziału, zakłada go (z zaproszeniem do oświadczeń
 * AM7/4 i AM7/5), konto zakładane jest w Microsoft 365, powiązane z kontem kursanta w SZO, a zlecenie Helpdesku dostaje etap
 * „Utworzono konto / zasób” (po wysłaniu danych — „Dane przekazane”).
 * Dane dostępowe: e-mail aktywacyjny z loginem (hasło tylko w osobnym SMS-ie na telefon osoby zainteresowanej). Braki w danych
 * (imię i nazwisko, e-mail, telefon) są zgłaszane PRZED utworzeniem konta — formularz prosi o uzupełnienie i zapisuje je w sprawie.
 */
require_once __DIR__ . '/LicenseManagementService.php';
require_once __DIR__ . '/LicenseNotificationService.php';

final class Am7TenantAccount
{
    public const TIDYCAL_DEFAULT = 'https://tidycal.com/feer-ziemowit/';

    public function __construct(private readonly LicenseManagementService $svc) {}

    public static function tidycalUrl(): string
    {
        $u = trim((string)(db_one("SELECT value FROM settings WHERE key_ = 'am7_tidycal_url'")['value'] ?? ''));
        return preg_match('~^https://~i', $u) ? $u : self::TIDYCAL_DEFAULT;
    }

    /** @return array<string,array{label:string,ready:bool,domain:string}> */
    public static function tenants(): array
    {
        require_once dirname(__DIR__, 3) . '/includes/ti_online.php';
        $feerReady = false; $eduReady = false; $feerDom = 'feer.org.pl'; $eduDom = 'edu.feer.org.pl';
        try { require_once dirname(__DIR__, 2) . '/ti_m365_feer/logic/tiM365Feer.php'; $feerReady = TiM365Feer::ready(); $feerDom = TiM365Feer::domain(); } catch (\Throwable $e) {}
        try { $eduReady = function_exists('m365_edu_ready') && m365_edu_ready(); if (function_exists('m365_training_domain')) $eduDom = m365_training_domain() ?: $eduDom; } catch (\Throwable $e) {}
        return [
            'feer' => ['label' => 'Tenant FEER (' . $feerDom . ') — konta organizacji', 'ready' => $feerReady, 'domain' => $feerDom],
            'edu'  => ['label' => 'Tenant EDU FEER (' . $eduDom . ') — konta szkoleniowe', 'ready' => $eduReady, 'domain' => $eduDom],
        ];
    }

    /** Dane potrzebne do utworzenia konta i wysyłki: ['name'=>…, 'email'=>…, 'phone'=>…] z wniosku / rekordu kursanta (uzupełnione fallbackiem). */
    public function contact(array $l): array
    {
        return $this->svc->interestedContact($l);
    }

    /** @return list<string> klucze brakujących danych: name | email | phone */
    public function missing(array $c): array
    {
        $m = [];
        if (trim((string)$c['name']) === '') $m[] = 'name';
        if (!filter_var(trim((string)$c['email']), FILTER_VALIDATE_EMAIL)) $m[] = 'email';
        if (preg_replace('/\D/', '', (string)$c['phone']) === '' || strlen(preg_replace('/\D/', '', (string)$c['phone'])) < 9) $m[] = 'phone';
        return $m;
    }

    private function graph(string $tenant): M365Graph
    {
        require_once dirname(__DIR__, 3) . '/includes/ti_online.php';
        if ($tenant === 'feer') { require_once dirname(__DIR__, 2) . '/ti_m365_feer/logic/tiM365Feer.php'; return TiM365Feer::graph(); }
        return m365_edu();
    }

    /** Lista licencji (SKU) tenanta do wyboru; pusta przy braku konfiguracji lub błędzie. @return list<array{id:string,name:string,free:int}> */
    public function skus(string $tenant): array
    {
        try {
            $out = [];
            foreach ($this->graph($tenant)->get_subscribed_skus() as $s) {
                $free = (int)($s['prepaidUnits']['enabled'] ?? 0) - (int)($s['consumedUnits'] ?? 0);
                $out[] = ['id' => (string)$s['skuId'], 'name' => (string)$s['skuPartNumber'], 'free' => $free];
            }
            return $out;
        } catch (\Throwable $e) { return []; }
    }

    /**
     * Tworzy konto. $in: tenant (feer|edu), name, email, phone, sku (opcjonalnie), no_sms (1 = hasło pokaże się operatorowi zamiast SMS).
     * @return array{ok:bool, errors:list<string>, report:list<string>, upn:string, password:string}
     */
    public function create(int $id, array $in, array $actor): array
    {
        $res = ['ok' => false, 'errors' => [], 'report' => [], 'upn' => '', 'password' => ''];
        $l = $this->svc->licenses->get($id);
        if (!$l || $l['form'] !== LicenseForm::Am7) { $res['errors'][] = 'To nie jest sprawa AM7.'; return $res; }
        if ($l['requestStatus'] !== LicenseRequestStatus::Granted) { $res['errors'][] = 'Konto można utworzyć dopiero po przyznaniu (zatwierdzeniu decyzji AM7/2).'; return $res; }
        $row = db_one("SELECT tenant_upn FROM licenses WHERE id = ?", [$id]);
        if (!empty($row['tenant_upn'])) { $res['errors'][] = 'Konto już utworzone: ' . $row['tenant_upn'] . '.'; return $res; }

        $tenant = (string)($in['tenant'] ?? '');
        $tenants = self::tenants();
        if (!isset($tenants[$tenant])) $res['errors'][] = 'Wybierz tenant: FEER albo EDU FEER.';
        elseif (!$tenants[$tenant]['ready']) $res['errors'][] = 'Tenant „' . $tenants[$tenant]['label'] . '” nie jest skonfigurowany (Microsoft 365 → ustawienia).';

        $c = ['name' => trim((string)($in['name'] ?? '')), 'email' => trim((string)($in['email'] ?? '')), 'phone' => trim((string)($in['phone'] ?? ''))];
        $c = array_merge($this->contact($l), array_filter($c, fn($v) => $v !== ''));
        $miss = $this->missing($c);
        $labels = ['name' => 'imię i nazwisko', 'email' => 'poprawny e-mail (na niego pójdzie login)', 'phone' => 'numer telefonu (na niego pójdzie hasło w SMS-ie)'];
        $noSms = !empty($in['no_sms']);
        foreach ($miss as $k) {
            if ($k === 'phone' && $noSms) continue;   // operator przekaże hasło sam
            $res['errors'][] = 'Uzupełnij: ' . $labels[$k] . '.';
        }
        if (!$noSms && !(function_exists('sms_channel_ready') && sms_channel_ready())) {
            require_once dirname(__DIR__, 3) . '/includes/sms.php';
            if (!sms_channel_ready()) $res['errors'][] = 'Kanał SMS nie jest skonfigurowany — zaznacz „przekażę hasło sam(a)” albo skonfiguruj SMS.';
        }
        if ($res['errors']) return $res;

        // Braki uzupełnione w formularzu zapisujemy w sprawie (i w rekordzie kursanta, jeśli tam były puste)
        $upd = [];
        if (trim((string)$l['interested_email']) === '' || strcasecmp(trim((string)$l['interested_email']), $c['email']) !== 0) $upd['interested_email'] = $c['email'];
        if ($c['phone'] !== '' && trim((string)$l['interested_phone']) !== $c['phone']) $upd['interested_phone'] = $c['phone'];
        if (trim((string)$l['interested_name']) === '') $upd['interested_name'] = $c['name'];
        if ($upd) $this->svc->licenses->update($id, $upd);
        if ($l['interested_kind'] === 'client' && $l['interested_id']) {
            $cl = db_one("SELECT email, phone FROM k30_clients WHERE id = ?", [(int)$l['interested_id']]);
            if ($cl) {
                if (trim((string)$cl['email']) === '') db()->prepare("UPDATE k30_clients SET email = ? WHERE id = ?")->execute([$c['email'], (int)$l['interested_id']]);
                if (trim((string)$cl['phone']) === '' && $c['phone'] !== '') db()->prepare("UPDATE k30_clients SET phone = ? WHERE id = ?")->execute([$c['phone'], (int)$l['interested_id']]);
            }
        }

        // Konto w Microsoft 365
        try {
            $g = $this->graph($tenant);
            $upn = $g->unique_login($c['name']);
            $pass = M365Graph::generate_password();
            $u = $g->create_user($upn, $c['name'], $pass, true);
            if (empty($u['id'])) throw new RuntimeException('Graph nie zwrócił identyfikatora konta.');
            $res['upn'] = $upn; $res['password'] = $pass;
            $sku = trim((string)($in['sku'] ?? ''));
            if (preg_match('/^[0-9a-f-]{36}$/i', $sku)) {
                try { $g->assign_license((string)$u['id'], $sku); $res['report'][] = 'licencja nadana'; }
                catch (\Throwable $e) { $res['report'][] = 'konto założone, licencji nie nadano: ' . $e->getMessage(); }
            } else { $res['report'][] = 'licencji w tenancie nie wybrano — nadaj ją ręcznie'; }
        } catch (\Throwable $e) {
            $res['errors'][] = 'Nie utworzono konta: ' . $e->getMessage();
            $this->svc->licenses->event($id, 'tenant.fail', 'Nie utworzono konta w tenancie ' . $tenant . ': ' . $e->getMessage(), $actor);
            return $res;
        }
        db()->prepare("UPDATE licenses SET tenant_kind = ?, tenant_upn = ?, tenant_created_at = ? WHERE id = ?")->execute([$tenant, $res['upn'], ldNow(), $id]);

        // Powiązanie z kontem kursanta w SZO
        if ($l['interested_kind'] === 'client' && $l['interested_id']) {
            try {
                $acc = db_one("SELECT id, ms_user_id, feer_ms_user_id FROM k30_ti_student_accounts WHERE client_id = ? AND is_active = 1 LIMIT 1", [(int)$l['interested_id']]);
                if ($acc && $tenant === 'edu' && ($acc['ms_user_id'] ?? '') === '') {
                    db()->prepare("UPDATE k30_ti_student_accounts SET ms_user_id = ?, ms_upn = ?, ms_created_at = datetime('now') WHERE id = ?")->execute([(string)$u['id'], $res['upn'], (int)$acc['id']]);
                } elseif ($acc && $tenant === 'feer') {
                    require_once dirname(__DIR__, 2) . '/ti_m365_feer/logic/tiM365Feer.php'; TiM365Feer::migrate();
                    if (($acc['feer_ms_user_id'] ?? '') === '') db()->prepare("UPDATE k30_ti_student_accounts SET feer_ms_user_id = ?, feer_ms_upn = ?, feer_ms_linked_at = datetime('now') WHERE id = ?")->execute([(string)$u['id'], $res['upn'], (int)$acc['id']]);
                }
            } catch (\Throwable $e) { error_log('am7 tenant link: ' . $e->getMessage()); }
        }

        // „Przydzielona”: przydział z zaproszeniem do oświadczeń AM7/4 i AM7/5, jeśli jeszcze go nie ma
        try {
            $has = (int)(db_one("SELECT COUNT(*) AS n FROM license_assignments WHERE license_id = ? AND status NOT IN ('revoked','cancelled')", [$id])['n'] ?? 0);
            if ($has === 0) {
                $r = $this->svc->assign($id, ['kind' => (string)$l['interested_kind'], 'id' => $l['interested_id'] ? (int)$l['interested_id'] : null] + $c, $actor);
                $res['report'][] = 'przydział (Przydzielona): ' . $r['report'];
            } else { $res['report'][] = 'przydział już istnieje (Przydzielona)'; }
        } catch (\Throwable $e) { $res['report'][] = 'przydział nieudany: ' . $e->getMessage(); }

        // Dane dostępowe: e-mail z loginem + SMS z hasłem
        $sent = $this->deliver($l, $tenant, $c, $res['upn'], $res['password'], $noSms, $res);
        $this->svc->licenses->event($id, 'tenant.account', 'Utworzono konto ' . $res['upn'] . ' w tenancie ' . ($tenant === 'feer' ? 'FEER' : 'EDU FEER') . '; ' . implode('; ', $res['report']) . '.', $actor);

        // Helpdesk: etap „Utworzono konto / zasób”, po wysłaniu danych „Dane przekazane”
        try {
            $hid = (int)($l['helpdesk_ticket_id'] ?? 0);
            if ($hid) {
                require_once dirname(__DIR__, 3) . '/modules/helpdeskStatuses/lib/helpdeskStatuses.php';
                hdSubstatusSet($hid, $sent ? 'am_dane_przekazane' : 'am_konto', 0, (string)$actor['name']);
            }
        } catch (\Throwable $e) {}
        $res['ok'] = true;
        if ($noSms) $res['report'][] = 'hasło do przekazania ręcznie';
        return $res;
    }

    /** @return bool czy e-mail z loginem został wysłany (hasło: SMS albo ręcznie) */
    private function deliver(array $l, string $tenant, array $c, string $upn, string $pass, bool $noSms, array &$res): bool
    {
        require_once dirname(__DIR__, 3) . '/includes/mail_queue.php';
        require_once dirname(__DIR__, 3) . '/includes/email_templates.php';
        $first = trim(explode(' ', trim($c['name']))[0] ?? '');
        $service = (string)(($l['decision']['plan'] ?? '') ?: ($l['request']['plan'] ?? '') ?: $l['title']);
        $mailOk = false;
        try {
            $r = email_tpl_render('ti_am7_account_activated', ['greeting' => $first !== '' ? ' ' . h($first) : '', 'service' => h($service), 'login' => h($upn),
                'tidycal_url' => h(self::tidycalUrl()), 'org' => h(defined('ORG_NAME') ? (string)ORG_NAME : 'FEER')]);
            if (!empty($r['enabled'])) {
                $mailOk = mail_queue_add($c['email'], $c['name'], $r['subject'], $r['html'], '', 'ti_am7_account', (int)$l['id'], '', true, [], '', [], LicenseNotificationService::adminCopy($c['email'])) > 0;
                $res['report'][] = $mailOk ? 'e-mail z loginem wysłany' : 'e-mail z loginem NIE wysłany (adres zablokowany)';
            } else { $res['report'][] = 'szablon e-maila wyłączony'; }
        } catch (\Throwable $e) { $res['report'][] = 'e-mail: ' . $e->getMessage(); }
        if (!$noSms) {
            try {
                require_once dirname(__DIR__, 3) . '/includes/sms.php';
                require_once dirname(__DIR__, 3) . '/includes/sms_templates.php';
                $m = sms_tpl_render('ti_am7_account_password', ['login' => $upn, 'password' => $pass]);
                sms_send($c['phone'], trim((string)$m['message']), 'ti_am7_account_password');
                $res['report'][] = 'hasło wysłane SMS-em';
            } catch (\Throwable $e) {
                $res['report'][] = 'SMS z hasłem NIE wysłany (' . $e->getMessage() . ') — przekaż hasło ręcznie';
                $res['sms_failed'] = true;
                $mailOk = false;
            }
        }
        return $mailOk && !$noSms && empty($res['sms_failed']);
    }
}
