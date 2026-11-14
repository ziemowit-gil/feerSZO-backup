<?php
declare(strict_types=1);

namespace Feer\Szo\Warden;

use DateTimeImmutable;
use DateTimeZone;

/**
 * modules/warden/lib/SzoDirectory.php — strona SZO dla WARDEN (główna baza SZO przez db()/PDO, przygotowane wyrażenia).
 *
 * Ostatnie logowanie w SZO: tabela users nie ma kolumny last_login_at, więc bierzemy nowszą z dat:
 *   login_log (udane logowania: login, login_sms, login_code, login_webauthn, login_new_confirmed; daty w UTC)
 *   user_sessions.last_active (każde wejście przez login_user(), także przez Logto; SZO kasuje te wpisy po 30 dniach).
 * WARDEN zapamiętuje najnowszą widzianą datę u siebie (warden_users), więc czyszczenie dzienników jej nie cofa.
 * Blokada w SZO: users.is_active = 0 i usunięcie sesji z user_sessions.
 */
class SzoDirectory
{
    private const LOGIN_ACTIONS = ['login', 'login_sms', 'login_code', 'login_webauthn', 'login_new_confirmed'];

    /**
     * Aktywne konta SZO powiązane z kontem w Logto (sso_accounts.logto_id).
     * @return list<array{id:int,email:string,notify_email:string,name:string,first_name:string,phone:string,created_at:?string,logto_id:string}>
     */
    public function activeLinkedUsers(): array
    {
        try {
            $rows = \db_all("SELECT u.id, u.email, u.name, u.first_name, u.phone_number, u.alt_email, u.use_alt_notifications, u.created_at,
                                    s.logto_id
                               FROM users u
                               JOIN sso_accounts s ON (s.user_id = u.id OR (s.user_id IS NULL AND s.email = lower(u.email)))
                              WHERE u.is_active = 1 AND u.merged_into_id IS NULL AND COALESCE(s.logto_id, '') <> ''
                              ORDER BY u.id");
        } catch (\Throwable $e) {
            return [];   // brak tabeli sso_accounts = logowanie jednokrotne jeszcze nieużywane
        }
        $out = [];
        $seen = [];
        foreach ($rows as $r) {
            $id = (int)$r['id'];
            if (isset($seen[$id])) continue;
            $seen[$id] = true;
            $out[] = $this->shape($r) + ['logto_id' => (string)$r['logto_id']];
        }
        return $out;
    }

    /** Wszystkie aktywne konta z prawdziwym adresem (do ogłoszenia „Poznajcie Mietka”). */
    public function activeUsersForAnnouncement(): array
    {
        $rows = \db_all("SELECT id, email, name, first_name, phone_number, alt_email, use_alt_notifications, created_at
                           FROM users WHERE is_active = 1 AND merged_into_id IS NULL ORDER BY id");
        return array_values(array_filter(array_map(fn($r) => $this->shape($r), $rows), fn($u) => self::deliverable($u['notify_email'])));
    }

    /** Lista do wyboru wyjątków. */
    public function activeUsersBrief(): array
    {
        return \db_all("SELECT id, name, email FROM users WHERE is_active = 1 AND merged_into_id IS NULL ORDER BY name, email");
    }

    public function userBrief(int $id): ?array
    {
        return \db_one('SELECT id, name, email, is_active FROM users WHERE id = ?', [$id]) ?: null;
    }

    /** @param list<int> $ids  @return array<int, DateTimeImmutable> */
    public function lastLogins(array $ids): array
    {
        if (!$ids) return [];
        $out = [];
        foreach (array_chunk($ids, 400) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $acts = implode(',', array_fill(0, count(self::LOGIN_ACTIONS), '?'));
            $sets = [];
            try {
                $sets[] = \db_all("SELECT user_id, MAX(created_at) AS at FROM login_log WHERE user_id IN ($in) AND action IN ($acts) GROUP BY user_id",
                    [...$chunk, ...self::LOGIN_ACTIONS]);
            } catch (\Throwable $e) {}
            try {
                $sets[] = \db_all("SELECT user_id, MAX(last_active) AS at FROM user_sessions WHERE user_id IN ($in) GROUP BY user_id", $chunk);
            } catch (\Throwable $e) {}
            foreach ($sets as $rows) foreach ($rows as $r) {
                $at = self::utc((string)$r['at']);
                $uid = (int)$r['user_id'];
                if ($at && (!isset($out[$uid]) || $at > $out[$uid])) $out[$uid] = $at;
            }
        }
        return $out;
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

    public function isActive(int $userId): bool
    {
        return (int)(\db_one('SELECT is_active FROM users WHERE id = ?', [$userId])['is_active'] ?? 0) === 1;
    }

    public static function utc(?string $s): ?DateTimeImmutable
    {
        if ($s === null || trim($s) === '') return null;
        try { return new DateTimeImmutable($s, new DateTimeZone('UTC')); } catch (\Throwable $e) { return null; }
    }

    public static function deliverable(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false && !preg_match('/@(local|localhost|example\.(com|org|invalid))$/i', $email);
    }

    private function shape(array $r): array
    {
        $email = (string)$r['email'];
        $alt = trim((string)($r['alt_email'] ?? ''));
        $notify = (!empty($r['use_alt_notifications']) && filter_var($alt, FILTER_VALIDATE_EMAIL)) ? $alt : $email;
        $first = trim((string)($r['first_name'] ?? ''));
        if ($first === '') $first = (string)(preg_split('/\s+/', trim((string)$r['name']))[0] ?? '');
        return [
            'id' => (int)$r['id'], 'email' => $email, 'notify_email' => $notify, 'name' => (string)$r['name'],
            'first_name' => $first, 'phone' => trim((string)($r['phone_number'] ?? '')), 'created_at' => $r['created_at'] ?? null,
        ];
    }
}
