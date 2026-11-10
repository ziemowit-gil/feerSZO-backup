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
                     'card' => LicenceCard::active($cid), 'virtual' => ClaudeBilling::isVirtual($cid)];
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

    public function logout(): ?string
    {
        LicencjePanelAuth::adminLogout();
        $_SESSION['lk_flash'] = ['ok', 'Wylogowano z części administratora.'];
        header('Location: ' . route('lk.login'));
        return null;
    }
}
