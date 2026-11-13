<?php
declare(strict_types=1);

/**
 * modules/ti_licencje_kursant/logic/LicencjeAdminController.php — część Panelu Licencji dla Administratora Licencji (polecenie 2026-11-10).
 * Logowanie: SZO albo Microsoft 365 (licencje/sso.php → ?wejscie_admin=…, LicencjePanelAuth::admin()); uprawnienie SZO „licencje_admin” albo rola admin.
 * Pulpit: wyszukiwanie uczestnika (imię i nazwisko, login l…, e-mail, nr beneficjenta), stan danych do panelu i konta Office,
 * „Nadaj dostęp / Wyślij nowe hasło” (LicencjePanelAccess), „Sprawdź / załóż konto Office” (LicenceOffice), lista osób bez danych.
 * Wnioski AM trafią tu w następnym kroku.
 */
require_once __DIR__ . '/LicencjePanelAuth.php';
require_once __DIR__ . '/LicencjePanelAccess.php';
require_once dirname(__DIR__, 2) . '/claude_billing/logic/LicenceOffice.php';
require_once dirname(__DIR__, 2) . '/claude_billing/logic/LicenceCard.php';
require_once dirname(__DIR__, 2) . '/ti_poczta_stud/logic/PocztaStud.php';

final class LicencjeAdminController
{
    private const VIEW = __DIR__ . '/../view/';

    private function adm(): array
    {
        $a = LicencjePanelAuth::admin();
        if (!$a) { header('Location: ' . route('lk.login')); exit; }
        return $a;
    }

    private function back(string $type, string $msg): ?string
    {
        $_SESSION['lk_flash'] = [$type, $msg];
        header('Location: ' . route('lk.admin') . (($q = trim((string)($_POST['q'] ?? ''))) !== '' ? '&q=' . rawurlencode($q) : ''));
        return null;
    }

    private static function org(): string { return defined('ORG_NAME') ? (string)ORG_NAME : 'Fundacja FEER'; }

    /** Uczestnicy pasujący do zapytania (do 25). */
    private function search(string $q): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) return [];
        $ids = [];
        if (preg_match('/^l\d{8}$/i', $q)) { foreach (db_all("SELECT client_id id FROM license_logins WHERE login=?", [strtolower($q)]) as $r) $ids[(int)$r['id']] = true; }
        $like = '%' . $q . '%';
        foreach (db_all("SELECT id FROM k30_clients WHERE name LIKE ? OR email LIKE ? ORDER BY name LIMIT 25", [$like, $like]) as $r) $ids[(int)$r['id']] = true;
        try { foreach (db_all("SELECT client_id id FROM k30_ti_student_accounts WHERE student_no=? OR login=? LIMIT 5", [$q, $q]) as $r) $ids[(int)$r['id']] = true; } catch (\Throwable $e) {}
        $out = [];
        foreach (array_slice(array_keys($ids), 0, 25) as $cid) $out[] = $this->row($cid);
        return array_values(array_filter($out));
    }

    private function row(int $cid): ?array
    {
        $c = db_one("SELECT id, name, email, phone FROM k30_clients WHERE id=?", [$cid]);
        if (!$c) return null;
        $last = db_one("SELECT last_login FROM license_logins WHERE client_id=?", [$cid]);
        return $c + ['login' => LicenseLogin::get($cid), 'hasPass' => LicenseLogin::hasPassword($cid), 'last' => (string)($last['last_login'] ?? ''),
                     'why' => LicencjePanelAccess::reasons($cid), 'unpaid' => LicencjePanelAccess::paidWithoutInvoice($cid), 'office' => LicenceOffice::summary($cid),
                     'card' => LicenceCard::active($cid), 'virtual' => ClaudeBilling::isVirtual($cid), 'mail' => PocztaStud::get($cid)];
    }

    public function index(): string
    {
        $a = $this->adm();
        $q = trim((string)($_GET['q'] ?? ''));
        $flash = $_SESSION['lk_flash'] ?? null; unset($_SESSION['lk_flash']);
        return Router::view(self::VIEW . 'admin.php', ['adm' => $a, 'q' => $q, 'found' => $this->search($q), 'cand' => $q === '' ? LicencjePanelAccess::candidates(40) : [],
            'org' => self::org(), 'flash' => $flash]);
    }

    /** Nadanie dostępu do Panelu Licencji (login l + 8 cyfr, nowe hasło: SMS z hasłem, e-mail z adresem i loginem). */
    public function access(): ?string
    {
        $a = $this->adm();
        $cid = (int)($_POST['client_id'] ?? 0);
        try {
            $r = LicencjePanelAccess::issue($cid, ['name' => $a['name'] . ' (Administrator Licencji)']);
            return $this->back($r['sms'] || $r['password'] === '' ? 'ok' : 'info', ($r['new'] ? 'Nadano dostęp do Panelu Licencji' : 'Nowe hasło do Panelu Licencji') . ': login ' . $r['login'] . '. ' . $r['text']
                . ($r['password'] !== '' ? ' Hasło (pokazujemy je tylko teraz): ' . $r['password'] . '.' : ''));
        } catch (\Throwable $e) { return $this->back('err', $e->getMessage()); }
    }

    /** Konto Office w tenancie edukacyjnym: sprawdź, a gdy go nie ma, załóż. */
    public function office(): ?string
    {
        $a = $this->adm();
        $cid = (int)($_POST['client_id'] ?? 0);
        try {
            $r = LicenceOffice::ensure($cid, ['name' => $a['name'] . ' (Administrator Licencji)']);
            return $this->back(!empty($r['ok']) ? 'ok' : 'err', 'Konto Office: ' . $r['text'] . (!empty($r['password']) ? ' Hasło tymczasowe (pokazujemy je tylko teraz): ' . $r['password'] . '.' : ''));
        } catch (\Throwable $e) { return $this->back('err', $e->getMessage()); }
    }

    /** Poczta @stud.feer.org.pl (sdata.net.pl): login i hasło wpisuje administrator; puste hasło = bez zmiany. */
    public function studMailSave(): ?string
    {
        $a = $this->adm();
        try { return $this->back('ok', PocztaStud::save((int)($_POST['client_id'] ?? 0), (string)($_POST['mail_login'] ?? ''), (string)($_POST['mail_pass'] ?? ''), $a['name'] . ' (Administrator Licencji)')); }
        catch (\Throwable $e) { return $this->back('err', 'Poczta: ' . $e->getMessage()); }
    }

    public function studMailRemove(): ?string
    {
        $a = $this->adm();
        return $this->back('ok', PocztaStud::remove((int)($_POST['client_id'] ?? 0), $a['name'] . ' (Administrator Licencji)'));
    }

    /** Dane dostępu do licencji lub usługi (Microsoft 365 A3 / A5, Claude, NordVPN, Dysk Online, inna): e-mail z danymi, hasło SMS-em. */
    public function accessData(): ?string
    {
        $a = $this->adm();
        require_once __DIR__ . '/LicencjeDostep.php';
        $cid = (int)($_POST['client_id'] ?? 0);
        try {
            $r = LicencjeDostep::send($cid, (array)($_POST['acc'] ?? []), ['name' => $a['name'] . ' (Administrator Licencji)']);
            return $this->back($r['email'] || $r['secret'] === '' ? 'ok' : 'info', 'Dane dostępu: ' . $r['text'] . ($r['secret'] !== '' ? ' Hasło / klucz (pokazujemy je tylko teraz): ' . $r['secret'] . '.' : ''));
        } catch (\Throwable $e) { return $this->back('err', $e->getMessage()); }
    }

    /** Podgląd Panelu Licencji oczami uczestnika (polecenie 2026-11-12): administrator wchodzi jako uczestnik; operacje uczestnika są wyłączone. */
    public function impersonate(): ?string
    {
        $a = $this->adm();
        $cid = (int)($_POST['client_id'] ?? 0);
        $c = $cid > 0 ? db_one("SELECT id, name FROM k30_clients WHERE id=?", [$cid]) : null;
        if (!$c) return $this->back('err', 'Nie znaleziono uczestnika.');
        LicencjePanelAuth::impersonate($cid, $a);
        // ślad wejścia: dziennik operacji uczestnika (konto TI) i dziennik Płatnych licencji
        $sid = (int)(db_one("SELECT id FROM k30_ti_student_accounts WHERE client_id=? ORDER BY id DESC LIMIT 1", [$cid])['id'] ?? 0);
        if ($sid > 0 && function_exists('ti_account_log')) ti_account_log($sid, 'licencje_podglad_admin', 'Administrator Licencji ' . $a['name'] . ' otworzył Panel Licencji jako uczestnik (podgląd).', (int)$a['uid'] ?: null, (string)$a['name']);
        try { ClaudeBilling::event(null, null, 'admin_view', 'Podgląd Panelu Licencji jako ' . $c['name'] . ' (Administrator Licencji ' . $a['name'] . ').', (string)$a['name']); } catch (\Throwable $e) {}
        $_SESSION['lk_flash'] = ['info', 'Widzisz Panel Licencji tak jak ' . $c['name'] . '. To podgląd: operacje uczestnika są wyłączone.'];
        header('Location: ' . route('lk.index'));
        return null;
    }

    public function impersonateEnd(): ?string
    {
        $this->adm();
        $cid = LicencjePanelAuth::endImpersonation();
        $c = $cid ? db_one("SELECT name FROM k30_clients WHERE id=?", [$cid]) : null;
        $_SESSION['lk_flash'] = ['ok', 'Koniec podglądu' . ($c ? ': ' . $c['name'] : '') . '.'];
        header('Location: ' . route('lk.admin') . ($c ? '&q=' . rawurlencode((string)$c['name']) : ''));
        return null;
    }

    /** Office ze zniżką edukacyjną: ceny planów A3 / A5 (A1 w cenie) — edytuje kierownik albo administrator. */
    public function officePrices(): string
    {
        $a = $this->adm();
        $flash = $_SESSION['lk_flash'] ?? null; unset($_SESSION['lk_flash']);
        $plans = [];
        foreach (LicenceOffice::PLANS as $k => $pl) {
            if (!$pl['product']) continue;
            $plans[$k] = $pl + ['s' => ClaudeBilling::settings($pl['product']), 'rate' => ClaudeBilling::lastRate($pl['product'])];
        }
        return Router::view(self::VIEW . 'admin_office.php', ['adm' => $a, 'plans' => $plans, 'org' => self::org(), 'flash' => $flash]);
    }

    public function officePricesSave(): ?string
    {
        $a = $this->adm();
        $done = []; $err = [];
        foreach (LicenceOffice::PLANS as $k => $pl) {
            if (!$pl['product'] || !isset($_POST['usd'][$k])) continue;
            try {
                ClaudeBilling::savePrices($pl['product'], !empty($_POST['enabled'][$k]), str_replace(',', '.', trim((string)$_POST['usd'][$k])), str_replace(',', '.', trim((string)($_POST['list_usd'][$k] ?? ''))), $a['name'] . ' (Panel Licencji)');
                $done[] = $pl['name'];
            } catch (\Throwable $e) { $err[] = $pl['name'] . ': ' . $e->getMessage(); }
        }
        $_SESSION['lk_flash'] = $err ? ['err', implode(' ', $err)] : ['ok', 'Zapisano ceny: ' . implode(', ', $done) . '.'];
        header('Location: ' . route('lk.admin.prices'));
        return null;
    }

    /** Wyłącznik poczty Office (EDU) zmienia tylko Administrator Licencji (logowanie SZO / Microsoft 365), nie kierownik wchodzący z panelu TI. */
    public static function canSwitchEduMail(array $a): bool
    {
        return in_array((string)($a['via'] ?? ''), ['SZO', 'Microsoft 365'], true);
    }

    /** Poczta Office (Outlook) kont EDU w panelach: włącz / wyłącz (ti_edu_mail_set; to samo ustawienie co w panelu TI › Nauka online). */
    public function eduMailSave(): ?string
    {
        $a = $this->adm();
        if (!self::canSwitchEduMail($a)) {
            $_SESSION['lk_flash'] = ['err', 'Pocztę Office dla kont EDU włącza i wyłącza tylko administrator.'];
        } else {
            require_once dirname(__DIR__, 3) . '/includes/ti_online.php';
            $on = ($_POST['edu_mail'] ?? '0') === '1';
            ti_edu_mail_set($on, (string)$a['name'] . ' (Panel Licencji)');
            ClaudeBilling::event(null, null, 'office', ($on ? 'Włączono' : 'Wyłączono') . ' pocztę Office (Outlook) kont EDU w panelach.', (string)$a['name']);
            $_SESSION['lk_flash'] = ['ok', $on ? 'Poczta Office dla kont EDU jest włączona w panelach.' : 'Poczta Office dla kont EDU jest wyłączona w panelach.'];
        }
        header('Location: ' . route('lk.admin.prices') . '#poczta-edu');
        return null;
    }

    /** Dysk Online (pilotaż, sdata.net.pl): pakiety i ceny — kierownik albo administrator. */
    public function drivePrices(): string
    {
        $a = $this->adm();
        require_once __DIR__ . '/LicencjeDysk.php';
        $flash = $_SESSION['lk_flash'] ?? null; unset($_SESSION['lk_flash']);
        return Router::view(self::VIEW . 'admin_dysk.php', ['adm' => $a, 'plans' => LicencjeDysk::plans(), 'org' => self::org(), 'flash' => $flash]);
    }

    public function drivePricesSave(): ?string
    {
        $a = $this->adm();
        require_once __DIR__ . '/LicencjeDysk.php';
        try { LicencjeDysk::save((array)($_POST['p'] ?? []), $a['name'] . ' (Panel Licencji)'); $_SESSION['lk_flash'] = ['ok', 'Zapisano pakiety Dysku Online.']; }
        catch (\Throwable $e) { $_SESSION['lk_flash'] = ['err', $e->getMessage()]; }
        header('Location: ' . route('lk.admin.drive'));
        return null;
    }

    public function logout(): ?string
    {
        LicencjePanelAuth::adminLogout();
        $_SESSION['lk_flash'] = ['ok', 'Wylogowano z części administratora.'];
        header('Location: ' . route('lk.login'));
        return null;
    }
}
