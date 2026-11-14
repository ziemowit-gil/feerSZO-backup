<?php
declare(strict_types=1);

namespace Feer\Szo\LoginSentry;

use DateTimeImmutable;
use DateTimeZone;

/**
 * modules/login_sentry/lib/SzoDirectory.php — strona SZO dla LoginSentry („Staszek”): główna baza SZO przez db()/PDO,
 * tylko przygotowane wyrażenia.
 *
 * Logowania SZO: tabela login_log (includes/auth_security.php). Udane: login, login_sms, login_code, login_webauthn,
 * login_new_confirmed; nieudane: login_fail, login_blocked. Daty w UTC (CURRENT_TIMESTAMP SQLite).
 * Uwaga: IP pochodzi z REMOTE_ADDR. Gdy SZO stoi za Cloudflare bez przywracania adresu, będą to adresy Cloudflare;
 * Staszek je rozpoznaje (IpTools::usable) i pomija przy ocenie sieci, a panel pokazuje ostrzeżenie.
 * Blokada w SZO: users.is_active = 0 i usunięcie sesji (jak Mietek).
 */
interface DirectoryInterface
{
    /** @return list<LoginEvent> */
    public function szoLogins(DateTimeImmutable $since, ?int $onlyUserId = null): array;

    /** Aktywne konta SZO powiązane z Logto: logto_id => [id,email,name,role]. */
    public function linkedByLogtoId(): array;

    /** @return array{id:int,email:string,name:string,role:string,is_active:int,logto_id:string}|null */
    public function user(int $id): ?array;

    /** @return list<array{email:string,name:string}> aktywni administratorzy SZO */
    public function admins(): array;

    public function block(int $userId): void;

    public function unblock(int $userId): void;
}

final class SzoDirectory implements DirectoryInterface
{
    public const OK_ACTIONS = ['login', 'login_sms', 'login_code', 'login_webauthn', 'login_new_confirmed'];
    public const FAIL_ACTIONS = ['login_fail', 'login_blocked'];

    public function szoLogins(DateTimeImmutable $since, ?int $onlyUserId = null): array
    {
        $acts = [...self::OK_ACTIONS, ...self::FAIL_ACTIONS];
        $in = implode(',', array_fill(0, count($acts), '?'));
        $sql = "SELECT l.id, l.user_id, l.email, l.ip, l.user_agent, l.action, l.created_at, u.email AS user_email
                  FROM login_log l LEFT JOIN users u ON u.id = l.user_id
                 WHERE l.created_at >= ? AND l.action IN ($in)";
        $args = [$since->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'), ...$acts];
        if ($onlyUserId !== null) {
            $sql .= ' AND (l.user_id = ? OR lower(l.email) = (SELECT lower(email) FROM users WHERE id = ?))';
            array_push($args, $onlyUserId, $onlyUserId);
        }
        $sql .= ' ORDER BY l.id';
        try {
            $rows = \db_all($sql, $args);
        } catch (\Throwable $e) {
            return [];   // brak login_log = nikt się jeszcze nie logował
        }
        $out = [];
        foreach ($rows as $r) {
            $at = self::utc((string)$r['created_at']);
            if (!$at) continue;
            $email = strtolower(trim((string)($r['user_email'] ?: $r['email'])));
            $out[] = new LoginEvent('szo', (string)$r['id'], $r['user_id'] !== null ? (int)$r['user_id'] : null, '', $email,
                (string)$r['ip'], (string)$r['user_agent'], in_array($r['action'], self::OK_ACTIONS, true), $at);
        }
        return $out;
    }

    public function linkedByLogtoId(): array
    {
        try {
            $rows = \db_all("SELECT u.id, u.email, u.name, u.role, s.logto_id
                               FROM users u
                               JOIN sso_accounts s ON (s.user_id = u.id OR (s.user_id IS NULL AND s.email = lower(u.email)))
                              WHERE u.merged_into_id IS NULL AND COALESCE(s.logto_id, '') <> ''
                              ORDER BY u.id");
        } catch (\Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) $out[(string)$r['logto_id']] ??= ['id' => (int)$r['id'], 'email' => strtolower((string)$r['email']), 'name' => (string)$r['name'], 'role' => (string)$r['role']];
        return $out;
    }

    public function user(int $id): ?array
    {
        $u = \db_one('SELECT id, email, name, role, is_active FROM users WHERE id = ?', [$id]);
        if (!$u) return null;
        $logto = '';
        try {
            $s = \db_one("SELECT logto_id FROM sso_accounts WHERE (user_id = ? OR (user_id IS NULL AND email = lower(?))) AND COALESCE(logto_id,'') <> '' LIMIT 1", [$id, (string)$u['email']]);
            $logto = (string)($s['logto_id'] ?? '');
        } catch (\Throwable $e) {}
        return ['id' => (int)$u['id'], 'email' => (string)$u['email'], 'name' => (string)$u['name'], 'role' => (string)$u['role'],
                'is_active' => (int)$u['is_active'], 'logto_id' => $logto];
    }

    public function admins(): array
    {
        return array_map(fn($r) => ['email' => (string)$r['email'], 'name' => (string)$r['name']],
            \db_all("SELECT email, name FROM users WHERE role = 'admin' AND is_active = 1 AND merged_into_id IS NULL ORDER BY id"));
    }

    /** Lista do wyboru wyjątków w panelu. */
    public function activeUsersBrief(): array
    {
        return \db_all('SELECT id, name, email FROM users WHERE is_active = 1 AND merged_into_id IS NULL ORDER BY name, email');
    }

    public function block(int $userId): void
    {
        \db()->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([$userId]);
        try { \db()->prepare('DELETE FROM user_sessions WHERE user_id = ?')->execute([$userId]); } catch (\Throwable $e) {}
    }

    public function unblock(int $userId): void
    {
        \db()->prepare('UPDATE users SET is_active = 1 WHERE id = ?')->execute([$userId]);
    }

    public static function utc(?string $s): ?DateTimeImmutable
    {
        if ($s === null || trim($s) === '') return null;
        try { return new DateTimeImmutable($s, new DateTimeZone('UTC')); } catch (\Throwable $e) { return null; }
    }
}
