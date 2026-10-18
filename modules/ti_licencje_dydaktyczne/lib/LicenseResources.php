<?php
declare(strict_types=1);

/**
 * modules/ti_licencje_dydaktyczne/lib/LicenseResources.php — konta i JUŻ POSIADANE licencje / zasoby cyfrowe osoby.
 *
 * Konto: 'client' (uczestnik — k30_clients; ekran konta.php?selected={id konta kursanta}) albo 'user' (pracownik / dydaktyk — users).
 * Zasoby (resource_key):
 *   ti:{id}   — licencja na oprogramowanie z katalogu (k30_ti_client_licenses, modules/ti_licencje — zapisy WYŁĄCZNIE przez TiLicencjeManager),
 *   am:{id}   — przydział z obiegu AM5 / AM7 (license_assignments),
 *   m365:{id} — licencja Microsoft 365 uczestnika (k30_clients.m365_license_sku).
 * Finansowanie: najnowszy wpis license_funding (effective_from <= dziś); bez wpisu — wartość wyprowadzona (forma rozliczenia TI, FEER).
 * Tylko odczyt — zmiany robi LicenseOperationService.
 */
require_once __DIR__ . '/ldSchema.php';

final class LicenseResources
{
    /** Konto: ['kind','id','name','login','email','phone','role','course_id','course_name','sub_id','student_account_id']. */
    public static function account(string $kind, int $id): ?array
    {
        if ($kind === 'client') {
            $c = db_one("SELECT id, name, email, phone FROM k30_clients WHERE id = ?", [$id]);
            if (!$c) return null;
            $acc = null;
            try { $acc = db_one("SELECT id, login, ms_upn FROM k30_ti_student_accounts WHERE client_id = ? ORDER BY id LIMIT 1", [$id]); } catch (\Throwable $e) {}
            $course = null;
            try {
                $course = db_one("SELECT c.id, c.name, c.display_name, c.group_code FROM k30_ti_enrollments e JOIN k30_ti_courses c ON c.id = e.course_id
                                   WHERE e.client_id = ? ORDER BY CASE WHEN e.status = 'active' THEN 0 ELSE 1 END, c.is_active DESC, e.id DESC LIMIT 1", [$id]);
            } catch (\Throwable $e) {}
            $cName = $course ? ((trim((string)$course['display_name']) !== '' ? $course['display_name'] : $course['name']) . (trim((string)$course['group_code']) !== '' ? ' (' . $course['group_code'] . ')' : '')) : '';
            return ['kind' => 'client', 'id' => (int)$c['id'], 'name' => (string)$c['name'], 'login' => (string)($acc['ms_upn'] ?? '') ?: (string)($acc['login'] ?? ''),
                    'email' => (string)$c['email'], 'phone' => (string)$c['phone'], 'role' => 'Kursant', 'course_id' => $course ? (int)$course['id'] : null,
                    'course_name' => $cName, 'sub_id' => ($course ? (int)$course['id'] : 0) . '/' . (int)$c['id'], 'student_account_id' => $acc ? (int)$acc['id'] : null];
        }
        if ($kind === 'user') {
            $u = db_one("SELECT id, name, email, phone_number FROM users WHERE id = ?", [$id]);
            if (!$u) return null;
            $course = null;
            try { $course = db_one("SELECT id, name, display_name FROM k30_ti_courses WHERE instructor_id = ? AND status <> 'cancelled' ORDER BY is_active DESC, id DESC LIMIT 1", [$id]); } catch (\Throwable $e) {}
            return ['kind' => 'user', 'id' => (int)$u['id'], 'name' => (string)$u['name'], 'login' => (string)$u['email'], 'email' => (string)$u['email'],
                    'phone' => (string)$u['phone_number'], 'role' => $course ? 'Dydaktyk' : 'Pracownik', 'course_id' => $course ? (int)$course['id'] : null,
                    'course_name' => $course ? (trim((string)$course['display_name']) !== '' ? (string)$course['display_name'] : (string)$course['name']) : '',
                    'sub_id' => ($course ? (int)$course['id'] : 0) . '/P' . (int)$u['id'], 'student_account_id' => null];
        }
        return null;
    }

    /** Wyszukiwarka kont (uczestnicy + pracownicy) po imieniu, e-mailu, loginie. */
    public static function searchAccounts(string $q): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) return [];
        $like = '%' . mb_strtolower($q) . '%';
        $out = [];
        try {
            foreach (db_all("SELECT c.id, c.name, c.email FROM k30_clients c WHERE lower(c.name) LIKE ? OR lower(COALESCE(c.email,'')) LIKE ? ORDER BY c.name LIMIT 30", [$like, $like]) as $r)
                $out[] = ['kind' => 'client', 'id' => (int)$r['id'], 'name' => $r['name'], 'email' => $r['email'], 'role' => 'Kursant'];
        } catch (\Throwable $e) {}
        foreach (db_all("SELECT id, name, email FROM users WHERE lower(name) LIKE ? OR lower(COALESCE(email,'')) LIKE ? ORDER BY name LIMIT 30", [$like, $like]) as $r)
            $out[] = ['kind' => 'user', 'id' => (int)$r['id'], 'name' => $r['name'], 'email' => $r['email'], 'role' => 'Pracownik / dydaktyk'];
        return $out;
    }

    /**
     * Posiadane licencje konta: lista ['key','source','vendor','sku','type','assigned_at','expires_at','status','login','funding' => [...]].
     * $all = true — także cofnięte / wygasłe.
     */
    public static function forAccount(array $acc, bool $all = false): array
    {
        $out = [];
        if ($acc['kind'] === 'client') {
            try {
                foreach (db_all("SELECT cl.*, l.name AS license_name, l.vendor FROM k30_ti_client_licenses cl JOIN k30_ti_licenses l ON l.id = cl.license_id
                                  WHERE cl.client_id = ?" . ($all ? '' : " AND cl.status = 'active'") . " ORDER BY l.name", [$acc['id']]) as $r) {
                    $out[] = self::norm('ti:' . (int)$r['id'], 'Katalog oprogramowania TI', (string)$r['vendor'], (string)$r['license_name'],
                        trim((string)$r['access_key']) !== '' ? 'Klucz stanowiskowy' : 'Subskrypcja imienna', (string)($r['issued_at'] ?? '') ?: substr((string)$r['assigned_at'], 0, 10),
                        (string)($r['expires_at'] ?? ''), (string)$r['status'] === 'revoked' ? 'cofnięta' : 'aktywna', (string)$r['login'],
                        ['payer' => ldOrgName(), 'source' => 'FEER', 'budget' => trim((string)($r['billing_form'] ?? '') . ' ' . (string)($r['billing_ref'] ?? ''))]);
                }
            } catch (\Throwable $e) {}
            try {
                $m = db_one("SELECT m365_license_sku, m365_login, m365_provisioned_at, m365_expires_at, m365_disabled_at FROM k30_clients WHERE id = ?", [$acc['id']]);
                if ($m && trim((string)$m['m365_license_sku']) !== '' && ($all || empty($m['m365_disabled_at']))) {
                    $out[] = self::norm('m365:' . (int)$acc['id'], 'Microsoft 365 (tenant ośrodka)', 'Microsoft', (string)$m['m365_license_sku'], 'Dostęp Tenant',
                        substr((string)$m['m365_provisioned_at'], 0, 10), (string)($m['m365_expires_at'] ?? ''), empty($m['m365_disabled_at']) ? 'aktywna' : 'wyłączona',
                        (string)$m['m365_login'], ['payer' => ldOrgName(), 'source' => 'FEER', 'budget' => '']);
                }
            } catch (\Throwable $e) {}
        }
        foreach (db_all("SELECT a.*, l.title, l.licensor_name, l.form_kind, l.period_from, l.period_to FROM license_assignments a JOIN licenses l ON l.id = a.license_id
                          WHERE a.holder_kind = ? AND a.holder_id = ?" . ($all ? '' : " AND a.status <> 'revoked'") . " ORDER BY a.id DESC", [$acc['kind'], $acc['id']]) as $r) {
            $am7 = ($r['form_kind'] ?? '') === 'AM7';
            $out[] = self::norm('am:' . (int)$r['id'], $am7 ? 'Obieg AM7 (oprogramowanie)' : 'Obieg AM5 (materiały)', $am7 ? 'placówka' : (string)$r['licensor_name'], (string)$r['title'],
                $am7 ? 'Dostęp przyznany decyzją AM7' : 'Licencja na materiał dydaktyczny', (string)$r['period_from'], (string)$r['period_to'],
                $r['status'] === 'revoked' ? 'cofnięta' : 'aktywna', '', ['payer' => ldOrgName(), 'source' => 'FEER', 'budget' => '']);
        }
        foreach ($out as &$r) $r['funding'] = self::funding($r['key']) ?? $r['funding'];
        return $out;
    }

    private static function norm(string $key, string $source, string $vendor, string $sku, string $type, string $from, string $to, string $status, string $login, array $funding): array
    {
        $expired = $to !== '' && $to < ldToday();
        return ['key' => $key, 'source' => $source, 'vendor' => $vendor, 'sku' => $sku, 'type' => $type, 'assigned_at' => $from, 'expires_at' => $to,
                'status' => $expired && $status === 'aktywna' ? 'wygasła' : $status, 'login' => $login, 'funding' => $funding + ['mode' => '', 'amount' => 0, 'from' => '']];
    }

    /** Jeden zasób konta po kluczu (z kontrolą przynależności do konta). */
    public static function resource(array $acc, string $key): ?array
    {
        foreach (self::forAccount($acc, true) as $r) if ($r['key'] === $key) return $r;
        return null;
    }

    /** Aktualne finansowanie z historii (null = brak wpisu). */
    public static function funding(string $key): ?array
    {
        try {
            $f = db_one("SELECT * FROM license_funding WHERE resource_key = ? AND effective_from <= ? ORDER BY effective_from DESC, id DESC LIMIT 1", [$key, ldToday()]);
        } catch (\Throwable $e) { $f = null; }
        if (!$f) return null;
        return ['payer' => (string)$f['payer'], 'source' => (string)$f['funding_source'], 'budget' => (string)$f['budget_position'],
                'mode' => (string)$f['payment_mode'], 'amount' => (float)$f['amount'], 'from' => (string)$f['effective_from']];
    }

    public static function fundingHistory(string $key): array
    {
        return db_all("SELECT * FROM license_funding WHERE resource_key = ? ORDER BY effective_from DESC, id DESC", [$key]);
    }

    /** Sprawdzenie limitu miejsc u dostawcy (tekst do rekordu: vendor_limit_check). */
    public static function vendorCheck(string $key): string
    {
        [$src, $id] = array_pad(explode(':', $key, 2), 2, '0');
        try {
            if ($src === 'ti') {
                $r = db_one("SELECT l.seats_total, l.name, (SELECT COUNT(*) FROM k30_ti_client_licenses x WHERE x.license_id = l.id AND x.status = 'active') AS used
                               FROM k30_ti_client_licenses cl JOIN k30_ti_licenses l ON l.id = cl.license_id WHERE cl.id = ?", [(int)$id]);
                if (!$r) return 'nie znaleziono licencji w katalogu';
                return (int)$r['seats_total'] > 0 ? 'Katalog: ' . $r['name'] . ' — zajęte ' . (int)$r['used'] . ' z ' . (int)$r['seats_total'] . ' miejsc (wolne: ' . max(0, (int)$r['seats_total'] - (int)$r['used']) . ')'
                                                   : 'Katalog: ' . $r['name'] . ' — bez limitu miejsc w SZO; limit u dostawcy do potwierdzenia w jego panelu';
            }
            if ($src === 'am') {
                $r = db_one("SELECT l.seats, (SELECT COUNT(*) FROM license_assignments y WHERE y.license_id = l.id AND y.status <> 'revoked') AS used FROM license_assignments a JOIN licenses l ON l.id = a.license_id WHERE a.id = ?", [(int)$id]);
                return $r ? ((int)$r['seats'] > 0 ? 'Pula licencji: zajęte ' . (int)$r['used'] . ' z ' . (int)$r['seats'] : 'Pula licencji bez limitu miejsc') : 'nie znaleziono przydziału';
            }
            if ($src === 'm365') return 'Microsoft 365: dostępność licencji w tenancie ośrodka do potwierdzenia w centrum administracyjnym';
        } catch (\Throwable $e) { return 'weryfikacja niedostępna: ' . $e->getMessage(); }
        return 'brak danych o limicie';
    }
}
