<?php
/**
 * modules/ti_login/logic/TiLoginFormat.php — formaty loginów TI (decyzja użytkownika 2026-10-20):
 *   Dydaktyk          — tylko Microsoft 365 (bez zmian, users)
 *   Kursant           — imie.nazwisko (duplikat: imie.nazwisko2…) albo numer kursanta (student_no — TiLogin przyjmuje oba)
 *   SzK / konsultacje — 4 cyfry + „k”  (np. 1234k)
 *   Rodzic / opiekun  — 4 cyfry + „r”  (rodzic konta SzK dostaje ten sam numer co dziecko: 1234k → 1234r)
 *   Upoważniony       — 4 cyfry + „u”
 *   (konsultant zastępczy — 3 cyfry, modules/ti_consultant_access)
 * Wszystkie loginy unikalne we wszystkich kolumnach logowania. Nowe konta: wywołania w konta.php, TiSimpleGroups,
 * nabór, osoby upoważnione, konto beneficjenta konsultacji. Istniejące konta: cli/tiLoginyNowyFormat.php
 * (plan → zmiana → SMS z nowym loginem; stary przestaje działać, aliasy logowania są czyszczone).
 */

require_once dirname(__DIR__, 3) . '/includes/db.php';

final class TiLoginFormat
{
    public const SZK = 'k';
    public const PARENT = 'r';
    public const AUTHP = 'u';

    private const PL = ['ą'=>'a','ć'=>'c','ę'=>'e','ł'=>'l','ń'=>'n','ó'=>'o','ś'=>'s','ź'=>'z','ż'=>'z',
                        'Ą'=>'a','Ć'=>'c','Ę'=>'e','Ł'=>'l','Ń'=>'n','Ó'=>'o','Ś'=>'s','Ź'=>'z','Ż'=>'z'];

    /** Czy login jest zajęty (dowolna kolumna logowania TI), z pominięciem wskazanych rekordów. */
    public static function taken(string $login, int $exceptAccount = 0, int $exceptAuthp = 0): bool
    {
        $l = mb_strtolower(trim($login));
        $q = [
            ["SELECT 1 FROM k30_ti_student_accounts WHERE (lower(login) = ? OR lower(login_alias) = ? OR lower(parent_login) = ?) AND id <> ?", [$l, $l, $l, $exceptAccount]],
            ["SELECT 1 FROM k30_ti_authorized_persons WHERE lower(login) = ? AND id <> ?", [$l, $exceptAuthp]],
            ["SELECT 1 FROM ti_consultant_alt_logins WHERE login = ?", [$l]],
        ];
        foreach ($q as [$sql, $p]) { try { if (db_one($sql, $p)) return true; } catch (\Throwable $e) {} }
        return false;
    }

    /** imie.nazwisko (pierwsze imię i ostatni człon nazwiska, bez polskich znaków), unikalny: imie.nazwisko2… */
    public static function nameLogin(string $name, int $exceptAccount = 0, array $reserved = []): string
    {
        $p = preg_split('/\s+/', trim(strtr($name, self::PL))) ?: [''];
        $base = strtolower(count($p) >= 2 ? $p[0] . '.' . end($p) : $p[0]);
        $base = trim(preg_replace('/[^a-z0-9.-]/', '', $base), '.-') ?: 'kursant';
        $login = $base; $i = 2;
        while (in_array($login, $reserved, true) || self::taken($login, $exceptAccount)) $login = $base . $i++;
        return $login;
    }

    /** NNNN + przyrostek (k|r|u); $preferN — numer do użycia, gdy wolny (rodzic konta SzK). */
    public static function numberLogin(string $suffix, ?string $preferN = null, int $exceptAccount = 0, int $exceptAuthp = 0, array $reserved = []): string
    {
        if ($preferN !== null && preg_match('/^\d{4}$/', $preferN)) {
            $l = $preferN . $suffix;
            if (!in_array($l, $reserved, true) && !self::taken($l, $exceptAccount, $exceptAuthp)) return $l;
        }
        for ($i = 0; $i < 400; $i++) {
            $n = (string)random_int(1000, 9999);
            $l = $n . $suffix;
            // numer wolny też dla pozostałych przyrostków — 1234k i 1234r tej samej osoby, nie dwóch różnych
            $clash = false;
            foreach ([self::SZK, self::PARENT, self::AUTHP] as $sx) if (in_array($n . $sx, $reserved, true) || self::taken($n . $sx, $exceptAccount, $exceptAuthp)) { $clash = true; break; }
            if (!$clash) return $l;
        }
        throw new \RuntimeException('Brak wolnych numerów 4-cyfrowych dla loginu.');
    }

    public static function numberOf(string $login): ?string
    {
        return preg_match('/^(\d{4})[kru]$/', $login, $m) ? $m[1] : null;
    }

    /** Konto szkolenia krótkiego / konsultacji (login „k…”): tryb uproszczony konta albo dotychczasowy login k1234. */
    public static function isSzk(array $acc): bool
    {
        if ((int)($acc['simple_mode'] ?? 0) > 0) return true;
        if (preg_match('/^(k\d{4,5}|\d{4}k)$/i', (string)($acc['login'] ?? ''))) return true;
        if (class_exists('TiSimpleGroups') && (int)($acc['id'] ?? 0) > 0) {
            try { return TiSimpleGroups::accountMode((int)$acc['id']) > 0; } catch (\Throwable $e) {}
        }
        return false;
    }

    /** Czy login już jest w nowym formacie dla danego rodzaju. */
    public static function conforms(string $kind, string $login, string $name = ''): bool
    {
        return match ($kind) {
            'szk'    => (bool)preg_match('/^\d{4}k$/', $login),
            'parent' => (bool)preg_match('/^\d{4}r$/', $login),
            'authp'  => (bool)preg_match('/^\d{4}u$/', $login),
            default  => $name !== '' && preg_match('/^' . preg_quote(self::nameBase($name), '/') . '\d*$/', $login) === 1,
        };
    }

    private static function nameBase(string $name): string
    {
        $p = preg_split('/\s+/', trim(strtr($name, self::PL))) ?: [''];
        return trim(preg_replace('/[^a-z0-9.-]/', '', strtolower(count($p) >= 2 ? $p[0] . '.' . end($p) : $p[0])), '.-') ?: 'kursant';
    }

    /**
     * Plan zmiany istniejących kont: lista [kind, ref_id, name, old, new, phone]. Konta już w nowym formacie pomijane.
     * kind: student | szk | parent | authp. Nowe loginy zarezerwowane w obrębie planu (bez kolizji między sobą).
     */
    public static function plan(): array
    {
        if (is_file(dirname(__DIR__, 2) . '/ti_simple_groups/logic/simpleGroups.php')) require_once dirname(__DIR__, 2) . '/ti_simple_groups/logic/simpleGroups.php';
        $out = []; $res = [];
        $accs = db_all("SELECT a.*, c.name AS client_name, c.phone AS client_phone FROM k30_ti_student_accounts a
                          LEFT JOIN k30_clients c ON c.id = a.client_id ORDER BY a.id");
        foreach ($accs as $a) {
            $name = (string)($a['client_name'] ?? '');
            $szk = self::isSzk($a);
            $kind = $szk ? 'szk' : 'student';
            $new = (string)$a['login'];
            if (!self::conforms($kind, $new, $name)) {
                $new = $szk ? self::numberLogin(self::SZK, null, (int)$a['id'], 0, $res) : self::nameLogin($name, (int)$a['id'], $res);
                $res[] = $new;
                $out[] = ['kind' => $kind, 'ref_id' => (int)$a['id'], 'name' => $name, 'old' => (string)$a['login'], 'new' => $new, 'phone' => (string)($a['client_phone'] ?? ''),
                          'alias' => (string)($a['login_alias'] ?? '')];
            } elseif (trim((string)($a['login_alias'] ?? '')) !== '') {
                $out[] = ['kind' => $kind, 'ref_id' => (int)$a['id'], 'name' => $name, 'old' => (string)$a['login'], 'new' => $new, 'phone' => '', 'alias' => (string)$a['login_alias']];
            }
            $pl = trim((string)($a['parent_login'] ?? ''));
            if ($pl !== '' && !self::conforms('parent', $pl)) {
                $np = self::numberLogin(self::PARENT, self::numberOf($new), (int)$a['id'], 0, $res);
                $res[] = $np;
                $out[] = ['kind' => 'parent', 'ref_id' => (int)$a['id'], 'name' => (string)($a['guardian_name'] ?: 'Rodzic: ' . $name), 'old' => $pl, 'new' => $np,
                          'phone' => (string)($a['guardian_phone'] ?? ''), 'alias' => ''];
            }
        }
        try {
            foreach (db_all("SELECT p.id, p.name, p.login, a.client_id FROM k30_ti_authorized_persons p
                               JOIN k30_ti_student_accounts a ON a.id = p.student_account_id WHERE p.revoked_at IS NULL ORDER BY p.id") as $p) {
                if (self::conforms('authp', (string)$p['login'])) continue;
                $nu = self::numberLogin(self::AUTHP, null, 0, (int)$p['id'], $res);
                $res[] = $nu;
                $out[] = ['kind' => 'authp', 'ref_id' => (int)$p['id'], 'name' => (string)$p['name'], 'old' => (string)$p['login'], 'new' => $nu, 'phone' => '', 'alias' => ''];
            }
        } catch (\Throwable $e) {}
        return $out;
    }

    /**
     * Wykonuje plan: zmienia loginy (stary przestaje działać), czyści aliasy, zapisuje ti_login_changes i dziennik konta.
     * $sms — SMS z nowym loginem (rodzaj access_data) do osoby z telefonem. @return list<array> wiersze planu + sms
     */
    public static function apply(array $plan, bool $sms, int $by = 0): array
    {
        db()->exec("CREATE TABLE IF NOT EXISTS ti_login_changes (id INTEGER PRIMARY KEY AUTOINCREMENT, kind TEXT NOT NULL, ref_id INTEGER NOT NULL,
                    old_login TEXT, new_login TEXT, sms TEXT, changed_by INTEGER, changed_at DATETIME NOT NULL)");
        if ($sms) require_once dirname(__DIR__, 3) . '/includes/sms.php';
        $org = (function_exists('org_setting') ? (string)org_setting('org_short_name') : '') ?: 'FEER';   // krótko — jeden SMS
        $url = function_exists('tiPanelUrl') ? tiPanelUrl('ti', 'login.php') : '';
        $done = [];
        foreach ($plan as $r) {
            $pdo = db();
            $pdo->beginTransaction();
            try {
                if ($r['kind'] === 'parent') {
                    db_exec("UPDATE k30_ti_student_accounts SET parent_login = ?, updated_at = datetime('now') WHERE id = ?", [$r['new'], $r['ref_id']]);
                } elseif ($r['kind'] === 'authp') {
                    db_exec("UPDATE k30_ti_authorized_persons SET login = ? WHERE id = ?", [$r['new'], $r['ref_id']]);
                } else {
                    db_exec("UPDATE k30_ti_student_accounts SET login = ?, login_alias = '', updated_at = datetime('now') WHERE id = ?", [$r['new'], $r['ref_id']]);
                }
                $pdo->commit();
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $r['sms'] = 'BŁĄD: ' . $e->getMessage(); $done[] = $r; continue;
            }
            $st = '';
            $changed = $r['old'] !== $r['new'];
            if ($sms && $changed) {
                $phone = trim((string)$r['phone']);
                if ($phone === '') $st = 'brak telefonu';
                elseif (!sms_channel_ready()) $st = 'SMS wyłączony';
                else {
                    $who = ['parent' => 'rodzica/opiekuna', 'authp' => 'osoby upowaznionej'][$r['kind']] ?? 'kursanta';
                    try { sms_send($phone, "{$org} - panel TI: nowy login {$who}: {$r['new']} (stary nie dziala). Haslo bez zmian. {$url}", 'access_data'); $st = 'wysłano'; }
                    catch (\Throwable $e) { $st = 'błąd SMS: ' . $e->getMessage(); }
                }
            }
            $r['sms'] = $st;
            db_insert('ti_login_changes', ['kind' => $r['kind'], 'ref_id' => $r['ref_id'], 'old_login' => $r['old'], 'new_login' => $r['new'],
                                           'sms' => $st ?: null, 'changed_by' => $by ?: null, 'changed_at' => date('Y-m-d H:i:s')]);
            if ($changed && $r['kind'] !== 'authp' && function_exists('ti_account_log')) {
                try { ti_account_log($r['ref_id'], 'login_changed', 'Nowy format loginu' . ($r['kind'] === 'parent' ? ' rodzica' : '') . ': ' . $r['old'] . ' → ' . $r['new']); } catch (\Throwable $e) {}
            }
            $done[] = $r;
        }
        if (function_exists('audit_log')) { try { audit_log('ti.login_format_migration', ['count' => count($done), 'sms' => $sms], $by ?: null); } catch (\Throwable $e) {} }
        return $done;
    }
}
