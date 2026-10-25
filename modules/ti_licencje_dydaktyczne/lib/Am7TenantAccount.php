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
            $skuName = '';
            if (preg_match('/^[0-9a-f-]{36}$/i', $sku)) {
                foreach ($this->skus($tenant) as $sx) if (strcasecmp($sx['id'], $sku) === 0) $skuName = $sx['name'];
                try { $g->assign_license((string)$u['id'], $sku); $res['report'][] = 'licencja nadana'; }
                catch (\Throwable $e) { $res['report'][] = 'konto założone, licencji nie nadano: ' . $e->getMessage(); }
            } else { $res['report'][] = 'licencji w tenancie nie wybrano — nadaj ją ręcznie'; }
        } catch (\Throwable $e) {
            $res['errors'][] = 'Nie utworzono konta: ' . $e->getMessage();
            $this->svc->licenses->event($id, 'tenant.fail', 'Nie utworzono konta w tenancie ' . $tenant . ': ' . $e->getMessage(), $actor);
            return $res;
        }
        db()->prepare("UPDATE licenses SET tenant_kind = ?, tenant_upn = ?, tenant_created_at = ?, tenant_info = ? WHERE id = ?")
            ->execute([$tenant, $res['upn'], ldNow(), json_encode(['tenant_label' => $tenants[$tenant]['label'], 'sku_name' => $skuName, 'name' => $c['name'], 'by' => (string)$actor['name'],
                'no_sms' => $noSms ? 1 : 0], JSON_UNESCAPED_UNICODE), $id]);

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
        $row = db_one("SELECT tenant_info FROM licenses WHERE id = ?", [$id]);
        $info = json_decode((string)($row['tenant_info'] ?? ''), true) ?: [];
        $info += ['email' => $c['email'], 'phone_masked' => '***' . substr(preg_replace('/\D/', '', $c['phone']), -3), 'email_sent' => !empty($res['email_sent']), 'sms_sent' => !empty($res['sms_sent'])];
        db()->prepare("UPDATE licenses SET tenant_info = ? WHERE id = ?")->execute([json_encode($info, JSON_UNESCAPED_UNICODE), $id]);
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

    // ── AM91: zmiana loginu (UPN) konta w tenancie po błędzie — faktyczna zmiana w Microsoft 365 + aktualizacja loginu w SZO ──

    /** Poprawna część lokalna UPN (przed „@”): małe litery, cyfry, kropka, myślnik, podkreślenie. */
    public static function validLocalPart(string $v): bool
    {
        return (bool)preg_match('/^[a-z0-9](?:[a-z0-9._-]{0,62}[a-z0-9])?$/', $v) && !str_contains($v, '..');
    }

    /** Zmiany UPN zapisane w sprawie (od najstarszej): [{old,new,reason,at,by}]. */
    public static function upnChanges(int $id): array
    {
        $row = db_one("SELECT tenant_info FROM licenses WHERE id = ?", [$id]);
        $info = json_decode((string)($row['tenant_info'] ?? ''), true) ?: [];
        return array_values((array)($info['upn_changes'] ?? []));
    }

    /**
     * Zmienia UPN konta utworzonego ze sprawy AM7. $in: login (część przed „@”; domena zostaje bez zmian), reason (opis błędu — wymagany).
     * Kolejność: walidacja → sprawdzenie, że nowy login jest wolny → PATCH w Microsoft 365 → dopiero potem aktualizacja SZO
     * (sprawa, konto kursanta k30_ti_student_accounts.ms_upn / feer_ms_upn — po tym login logowania kursanta przez Microsoft).
     * Hasło i licencje konta nie zmieniają się. Błąd po stronie Microsoftu = zero zmian w SZO.
     * @return array{ok:bool, errors:list<string>, old:string, new:string, report:list<string>}
     */
    public function changeUpn(int $id, array $in, array $actor): array
    {
        $res = ['ok' => false, 'errors' => [], 'old' => '', 'new' => '', 'report' => []];
        $l = $this->svc->licenses->get($id);
        if (!$l || $l['form'] !== LicenseForm::Am7) { $res['errors'][] = 'To nie jest sprawa AM7.'; return $res; }
        $row = db_one("SELECT tenant_kind, tenant_upn, tenant_info FROM licenses WHERE id = ?", [$id]);
        $old = trim((string)($row['tenant_upn'] ?? ''));
        if ($old === '' || !in_array((string)$row['tenant_kind'], ['feer', 'edu'], true)) { $res['errors'][] = 'Konto w tenancie nie zostało jeszcze utworzone — nie ma czego zmieniać.'; return $res; }
        $tenant = (string)$row['tenant_kind'];
        // AM91 dotyczy wyłącznie kont licencyjnych (założonych ze sprawy AM7) — nigdy kont pracowników / wolontariuszy SZO
        if (db_one("SELECT 1 FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1", [$old])) { $res['errors'][] = 'To login konta pracownika SZO — AM91 zmienia tylko konta licencyjne.'; return $res; }
        [$oldLocal, $domain] = array_pad(explode('@', $old, 2), 2, '');
        $local = strtolower(trim((string)($in['login'] ?? '')));
        if (str_contains($local, '@')) $local = explode('@', $local, 2)[0];   // ktoś wkleił cały adres — domena i tak zostaje
        $reason = trim((string)($in['reason'] ?? ''));
        if (!self::validLocalPart($local)) $res['errors'][] = 'Login może zawierać tylko małe litery a–z (bez polskich znaków), cyfry, kropkę, myślnik i podkreślenie.';
        if ($reason === '') $res['errors'][] = 'Opisz błąd, który powoduje zmianę (np. literówka w nazwisku) — trafi do druku AM91.';
        $new = $local . '@' . $domain;
        if ($domain === '') $res['errors'][] = 'Bieżący UPN nie ma domeny — zmień go ręcznie w Microsoft 365.';
        if (strcasecmp($new, $old) === 0) $res['errors'][] = 'Nowy login jest taki sam jak obecny.';
        if ($res['errors']) return $res;

        $tenants = self::tenants();
        if (empty($tenants[$tenant]['ready'])) { $res['errors'][] = 'Tenant „' . ($tenants[$tenant]['label'] ?? $tenant) . '” nie jest skonfigurowany (Microsoft 365 → ustawienia).'; return $res; }

        // Microsoft 365: nowy login nie może być zajęty; potem właściwa zmiana
        try {
            $g = $this->graph($tenant);
            if ($g->login_exists($new)) { $res['errors'][] = 'Login ' . $new . ' jest już zajęty w tenancie — wybierz inny.'; return $res; }
            $g->change_upn($old, $new);
        } catch (\Throwable $e) {
            $res['errors'][] = 'Nie zmieniono loginu: ' . $e->getMessage();
            $this->svc->licenses->event($id, 'tenant.upn_fail', 'Nieudana zmiana UPN ' . $old . ' → ' . $new . ': ' . $e->getMessage(), $actor);
            return $res;
        }
        $res['report'][] = 'login zmieniony w Microsoft 365 (hasło i licencje bez zmian)';

        // SZO: sprawa + konto kursanta (login logowania przez Microsoft) — dokładnie rekordy z dotychczasowym UPN
        $info = json_decode((string)($row['tenant_info'] ?? ''), true) ?: [];
        $info['upn_changes'] = array_merge((array)($info['upn_changes'] ?? []), [['old' => $old, 'new' => $new, 'reason' => $reason, 'at' => ldNow(), 'by' => (string)($actor['name'] ?? '')]]);
        db()->prepare("UPDATE licenses SET tenant_upn = ?, tenant_info = ? WHERE id = ?")->execute([$new, json_encode($info, JSON_UNESCAPED_UNICODE), $id]);
        try {
            $n = 0;
            $st = db()->prepare("UPDATE k30_ti_student_accounts SET ms_upn = ?, updated_at = datetime('now') WHERE LOWER(ms_upn) = LOWER(?)");
            $st->execute([$new, $old]); $n += $st->rowCount();
            require_once dirname(__DIR__, 2) . '/ti_m365_feer/logic/tiM365Feer.php'; TiM365Feer::migrate();
            $st = db()->prepare("UPDATE k30_ti_student_accounts SET feer_ms_upn = ?, updated_at = datetime('now') WHERE LOWER(feer_ms_upn) = LOWER(?)");
            $st->execute([$new, $old]); $n += $st->rowCount();
            $res['report'][] = $n > 0 ? 'login zaktualizowany w koncie kursanta w SZO (' . $n . ')' : 'brak powiązanego konta kursanta w SZO do aktualizacji';
        } catch (\Throwable $e) {
            error_log('am91 upn link: ' . $e->getMessage());
            $res['report'][] = 'UWAGA: login zmieniony w Microsoft 365 i w sprawie, ale konta kursanta w SZO nie zaktualizowano (' . $e->getMessage() . ') — popraw ręcznie';
        }
        $this->svc->licenses->event($id, 'tenant.upn_change', 'Zmieniono UPN ' . $old . ' → ' . $new . ' (AM91). Uzasadnienie: ' . $reason . '. ' . implode('; ', $res['report']) . '.', $actor);
        $res['ok'] = true; $res['old'] = $old; $res['new'] = $new;
        // Automatycznie: nowe potwierdzenie AM90/n „Aktualizacja danych” (z uzasadnieniem) e-mailem do osoby
        try { $res['report'][] = $this->sendAm99($id, $actor, true); }
        catch (\Throwable $e) { $res['report'][] = 'UWAGA: AM90/' . self::am90Revision($id) . ' NIE wysłane (' . $e->getMessage() . ') — wyślij ręcznie z zakładki Wydruki'; }
        return $res;
    }

    /** @return array{out:array,number:string,info:array} wydruk AM91 ostatniej zmiany UPN albo wyjątek, gdy zmiany jeszcze nie było */
    public function am91Output(array $l): array
    {
        $ch = self::upnChanges((int)$l['id']);
        if (!$ch) throw new DomainException('Nie było jeszcze zmiany UPN — druk AM91 dokumentuje wykonaną zmianę.');
        $row = db_one("SELECT tenant_kind, tenant_info FROM licenses WHERE id = ?", [(int)$l['id']]);
        $info = (json_decode((string)$row['tenant_info'], true) ?: []) + ['tenant_label' => ($row['tenant_kind'] === 'feer' ? 'Tenant FEER' : 'Tenant EDU FEER')];
        $info['change'] = end($ch); $info['change_no'] = count($ch);
        $number = LicenseDocument::am91Number($l, count($ch));
        $html = (new LicenseDocument())->am91Html($l, $info);
        return ['out' => LicenseDocument::render($html, 'Zmiana loginu UPN — ' . $l['interested_name'], $number, 'AM91'), 'number' => $number, 'info' => $info];
    }

    // ── AM90: Potwierdzenie wykonania operacji (druk w stylu CIT; wysyłka RĘCZNA) ──────────────────

    /** Rewizja AM90: 1 = potwierdzenie utworzenia konta; każda zmiana UPN (AM91) daje kolejną — AM90/2, AM90/3… „Aktualizacja danych”. */
    public static function am90Revision(int $id): int
    {
        return 1 + count(self::upnChanges($id));
    }

    /** @return array{out:array,number:string,info:array} wydruk AM90 (PDF; fallback HTML) albo wyjątek, gdy konta jeszcze nie ma */
    public function am90Output(array $l): array
    {
        $row = db_one("SELECT tenant_kind, tenant_upn, tenant_created_at, tenant_info FROM licenses WHERE id = ?", [(int)$l['id']]);
        if (empty($row['tenant_upn'])) throw new DomainException('Najpierw utwórz konto w tenancie — druk AM90 potwierdza wykonaną operację.');
        $info = (json_decode((string)$row['tenant_info'], true) ?: []) + ['tenant_label' => ($row['tenant_kind'] === 'feer' ? 'Tenant FEER' : 'Tenant EDU FEER')];
        $info['upn'] = (string)$row['tenant_upn']; $info['created_at'] = (string)$row['tenant_created_at']; $info['tenant_kind'] = (string)$row['tenant_kind'];
        $ch = self::upnChanges((int)$l['id']);
        $info['revision'] = 1 + count($ch);
        if ($ch) $info['change'] = end($ch);
        $number = LicenseDocument::am90Number($l, $info['revision']);
        $html = (new LicenseDocument())->am90Html($l, $info);
        $title = $info['revision'] > 1 ? 'Potwierdzenie wykonania operacji — aktualizacja danych — ' : 'Potwierdzenie wykonania operacji — ';
        return ['out' => LicenseDocument::render($html, $title . $l['interested_name'], $number, 'AM90'), 'number' => $number, 'info' => $info];
    }

    /**
     * Wysyłka AM90 (ręczna; po zmianie UPN automatyczna jako AM90/n „Aktualizacja danych”): e-mail z PDF do osoby (kopia UDW do administratora), zamknięcie zgłoszenia w Helpdesku.
     * @return string raport
     */
    public function sendAm99(int $id, array $actor, bool $auto = false): string
    {
        $l = $this->svc->licenses->get($id);
        if (!$l || $l['form'] !== LicenseForm::Am7) throw new DomainException('To nie jest sprawa AM7.');
        $o = $this->am90Output($l);
        $rev = (int)$o['info']['revision'];
        [$rel] = LicenseStorage::put($o['out']['bytes'], $o['out']['ext'], 'am90' . ($rev > 1 ? '_r' . $rev : '') . '_' . $id);
        $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        if ($rev > 1) {
            $ch = (array)$o['info']['change'];
            $body = '<p>Dzień dobry ' . $e($l['interested_name']) . ',</p><p>w załączniku przesyłamy <b>aktualizację danych (AM90/' . $rev . ')</b> — zmieniliśmy login (UPN) Twojego konta Microsoft 365.</p>'
                  . '<p>Dotychczasowy login: <b>' . $e($ch['old']) . '</b><br>Nowy login: <b>' . $e($ch['new']) . '</b></p>'
                  . '<p><b>Uzasadnienie zmiany:</b> ' . $e($ch['reason']) . '</p>'
                  . '<p><b>Hasło się nie zmieniło.</b> Od teraz loguj się nowym loginem; w aplikacjach (Teams, Outlook, Office, OneDrive) wyloguj się i zaloguj ponownie. Dokument zastępuje poprzednie potwierdzenie AM90 i nie wymaga podpisu. Sprawa: ' . $e($l['case_number']) . '.</p>';
            $subject = 'Aktualizacja danych (AM90/' . $rev . ') — zmiana loginu konta Microsoft 365, sprawa ' . $l['case_number'];
            $heading = 'Aktualizacja danych — zmiana loginu konta';
        } else {
            $body = '<p>Dzień dobry ' . $e($l['interested_name']) . ',</p><p>w załączniku przesyłamy <b>potwierdzenie wykonania operacji (AM90)</b> — konto <b>' . $e($o['info']['upn']) . '</b> zostało utworzone, a w dokumencie znajdziesz warunki udostępnienia.</p>'
                  . '<p>Dokument nie wymaga podpisu. Jeżeli licencjodawca zmieni warunki licencji, Fundacja może dokonać zmiany warunków udostępnienia. Sprawa: ' . $e($l['case_number']) . '.</p>';
            $subject = 'Potwierdzenie wykonania operacji (AM90) — ' . $l['title'] . ', sprawa ' . $l['case_number'];
            $heading = 'Potwierdzenie wykonania operacji';
        }
        $att = LicenseNotificationService::attachment($rel, $o['number']);
        $c = $this->svc->interestedContact($l);
        $res = $this->svc->notify->send(LicenseNotificationType::OperationConfirm, $c, $subject,
            LicenseNotificationService::layout($heading, $body, '', '', 'AM90'), '', ['type' => 'license', 'id' => $id], $actor, true, $att);
        $report = 'AM90' . ($rev > 1 ? '/' . $rev : '') . ': ' . $this->svc->describe($res) . ($att ? '' : ' (bez PDF — wysłano wersję HTML tylko do wglądu w panelu)');
        $this->svc->licenses->event($id, 'am90.sent', ($auto ? 'Automatycznie' : 'Ręcznie') . ' wysłano AM90 ' . $o['number'] . ': ' . $this->svc->describe($res), $actor);
        if (!empty($res['delivered']) || in_array('queued', (array)($res['channels'] ?? []), true)) {
            db()->prepare("UPDATE licenses SET am90_sent_at = ? WHERE id = ?")->execute([ldNow(), $id]);
            if ($rev === 1) $report .= '. ' . $this->closeHelpdesk($l, $actor, $o['number']);   // aktualizacja (AM90/2…) nie rusza zgłoszenia
        }
        return $report;
    }

    /** AM90 zamyka zgłoszenie Helpdesku IT sprawy (etap „Dane przekazane”, status zamknięte, wpis w historii zgłoszenia). */
    private function closeHelpdesk(array $l, array $actor, string $number): string
    {
        $hid = (int)($l['helpdesk_ticket_id'] ?? 0);
        if (!$hid) return 'brak zgłoszenia w Helpdesku do zamknięcia';
        try {
            require_once dirname(__DIR__, 3) . '/includes/helpdesk.php';
            require_once dirname(__DIR__, 3) . '/modules/helpdeskStatuses/lib/helpdeskStatuses.php';
            $t = db_one("SELECT * FROM helpdesk_tickets WHERE id = ?", [$hid]);
            if (!$t) return 'zgłoszenie Helpdesku nie istnieje';
            if ($t['status'] === 'zamknięte') return 'zgłoszenie Helpdesku ' . $t['number'] . ' już zamknięte';
            hdSubstatusSet($hid, 'am_dane_przekazane', (int)$actor['id'], (string)$actor['name']);
            $now = date('Y-m-d H:i:s');
            db_update('helpdesk_tickets', ['status' => 'zamknięte', 'closed_at' => $now, 'updated_at' => $now], $hid);
            db_insert('helpdesk_messages', ['ticket_id' => $hid, 'user_id' => (int)$actor['id'] ?: null, 'user_name' => (string)$actor['name'],
                'body' => 'Zgłoszenie zamknięte — wysłano potwierdzenie wykonania operacji ' . $number . '.', 'is_internal' => 1]);
            try { hd_notify_status_change($t, (string)$t['status'], 'zamknięte'); } catch (\Throwable $e) {}
            return 'zgłoszenie Helpdesku ' . $t['number'] . ' zamknięte';
        } catch (\Throwable $e) { return 'nie zamknięto zgłoszenia Helpdesku: ' . $e->getMessage(); }
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
                $res['email_sent'] = $mailOk;
            } else { $res['report'][] = 'szablon e-maila wyłączony'; }
        } catch (\Throwable $e) { $res['report'][] = 'e-mail: ' . $e->getMessage(); }
        if (!$noSms) {
            try {
                require_once dirname(__DIR__, 3) . '/includes/sms.php';
                require_once dirname(__DIR__, 3) . '/includes/sms_templates.php';
                $m = sms_tpl_render('ti_am7_account_password', ['login' => $upn, 'password' => $pass]);
                sms_send($c['phone'], trim((string)$m['message']), 'ti_am7_account_password');
                $res['report'][] = 'hasło wysłane SMS-em';
                $res['sms_sent'] = true;
            } catch (\Throwable $e) {
                $res['report'][] = 'SMS z hasłem NIE wysłany (' . $e->getMessage() . ') — przekaż hasło ręcznie';
                $res['sms_failed'] = true;
                $mailOk = false;
            }
        }
        return $mailOk && !$noSms && empty($res['sms_failed']);
    }
}
