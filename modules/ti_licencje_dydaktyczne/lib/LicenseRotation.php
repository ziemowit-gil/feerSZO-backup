<?php
declare(strict_types=1);

/**
 * modules/ti_licencje_dydaktyczne/lib/LicenseRotation.php — ASK-UP1: uzasadnienie rotacji licencji (zmiana użytkownika / konta licencji, np. AM22 reasygnacja
 * albo przeniesienie licencji po zmianie UPN). Druk z QR i Code128 do ODRĘCZNEGO podpisu kierownika; sam nie zmienia danych licencji —
 * rotację wykonuje operacja (AM22 / AM91), ASK-UP1 dokumentuje uzasadnienie. Podpisany druk potwierdza kierownik / administrator w SZO.
 * Kod Code128: UP1-NNNNNN (skan → karta rotacji), QR → publiczny status bez danych osobowych.
 */
require_once __DIR__ . '/LicenseCorrection.php';

final class LicenseRotation
{
    public const PENDING = 'pending';
    public const SIGNED = 'signed';
    public const CANCELLED = 'cancelled';

    public static function code(int $id): string
    {
        return 'UP1-' . str_pad((string)$id, 6, '0', STR_PAD_LEFT);
    }

    public static function docNumber(array $r): string
    {
        return $r['target_number'] . ' · ' . self::code((int)$r['id']) . ' · ASK-UP1';
    }

    public static function verifyUrl(array $r): string
    {
        return rtrim(APP_URL, '/') . '/karty30/ti/licencja.php?' . http_build_query(['_route' => '/up1/' . $r['token']]);
    }

    public static function statusLabel(string $s): string
    {
        return ['pending' => 'Czeka na podpis kierownika', 'signed' => 'Podpisana odręcznie — potwierdzona', 'cancelled' => 'Anulowana'][$s] ?? $s;
    }

    public static function statusColor(string $s): string
    {
        return ['pending' => '#f0a30a', 'signed' => '#008a00', 'cancelled' => '#647687'][$s] ?? '#647687';
    }

    public static function get(int $id): ?array
    {
        ldMigrate();
        return db_one("SELECT * FROM license_rotations WHERE id = ?", [$id]) ?: null;
    }

    public static function byToken(string $token): ?array
    {
        ldMigrate();
        if (!preg_match('/^[0-9a-f]{24}$/', $token)) return null;
        return db_one("SELECT * FROM license_rotations WHERE token = ?", [$token]) ?: null;
    }

    public static function forTarget(string $scope, int $id): array
    {
        ldMigrate();
        return db_all("SELECT * FROM license_rotations WHERE scope = ? AND target_id = ? ORDER BY id DESC", [$scope, $id]);
    }

    public static function pendingCount(): int
    {
        try { ldMigrate(); return (int)(db_one("SELECT COUNT(*) AS n FROM license_rotations WHERE status = 'pending'")['n'] ?? 0); } catch (\Throwable $e) { return 0; }
    }

    /** Wartości domyślne formularza: licencja, dotychczasowy użytkownik i UPN (z konta w tenancie albo z konta osoby). @return array{license_label:string,from_holder:string,from_upn:string} */
    public static function defaults(string $scope, array $t): array
    {
        if ($scope === 'operation') {
            return ['license_label' => trim((string)(($t['request']['produkt'] ?? '') ?: $t['formEnum']->short())), 'from_holder' => (string)($t['account_name'] ?? ''), 'from_upn' => (string)($t['account_login'] ?? '')];
        }
        $upn = '';
        try { $upn = (string)(db_one("SELECT tenant_upn FROM licenses WHERE id = ?", [(int)$t['id']])['tenant_upn'] ?? ''); } catch (\Throwable $e) {}
        return ['license_label' => trim((string)(($t['decision']['plan'] ?? '') ?: ($t['request']['plan'] ?? '') ?: ($t['title'] ?? ''))), 'from_holder' => (string)($t['interested_name'] ?? ''), 'from_upn' => $upn];
    }

    private static function ip(): string
    {
        return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64);
    }

    private static function event(array $r, string $action, string $note, array $actor): void
    {
        $base = ['action' => $action, 'note' => mb_substr($note, 0, 2000), 'actor_id' => ($actor['id'] ?? 0) ?: null, 'actor_name' => (string)($actor['name'] ?? ''), 'ip' => self::ip(), 'created_at' => ldNow()];
        db_insert('license_events', ($r['scope'] === 'operation' ? ['license_id' => 0, 'operation_id' => (int)$r['target_id']] : ['license_id' => (int)$r['target_id']]) + $base);
    }

    /** @param array $in license_label, from_holder, to_holder, from_upn, to_upn, rotation_date, reason @return int id */
    public function create(string $scope, int $targetId, array $in, array $actor): int
    {
        ldMigrate();
        [$t, $number] = LicenseCorrection::target($scope, $targetId);
        $d = self::defaults($scope, $t);
        $v = fn(string $k, string $def = '') => mb_substr(trim((string)($in[$k] ?? '')) !== '' ? trim((string)$in[$k]) : $def, 0, 190);
        $reason = trim((string)($in['reason'] ?? ''));
        $to = $v('to_holder');
        if (mb_strlen($to) < 2) throw new InvalidArgumentException('Podaj nowego użytkownika licencji (osoba / konto, na które licencja jest przenoszona).');
        if (mb_strlen($reason) < 10) throw new InvalidArgumentException('Podaj uzasadnienie rotacji licencji (min. 10 znaków).');
        if (mb_strlen($reason) > 3000) throw new InvalidArgumentException('Uzasadnienie jest za długie (maks. 3000 znaków).');
        $date = trim((string)($in['rotation_date'] ?? ''));
        if ($date !== '') {
            if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $date, $m)) $date = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !checkdate((int)substr($date, 5, 2), (int)substr($date, 8, 2), (int)substr($date, 0, 4))) throw new InvalidArgumentException('Data rotacji: podaj rrrr-mm-dd albo dd.mm.rrrr.');
        }
        $id = (int)db_insert('license_rotations', ['scope' => $scope, 'target_id' => $targetId, 'target_number' => $number, 'token' => bin2hex(random_bytes(12)), 'status' => self::PENDING,
            'license_label' => $v('license_label', $d['license_label']), 'from_holder' => $v('from_holder', $d['from_holder']), 'to_holder' => $to,
            'from_upn' => $v('from_upn', $d['from_upn']), 'to_upn' => $v('to_upn'), 'rotation_date' => $date, 'reason' => $reason,
            'created_by' => ($actor['id'] ?? 0) ?: null, 'created_name' => (string)($actor['name'] ?? ''), 'created_ip' => self::ip(), 'created_at' => ldNow()]);
        self::event(self::get($id), 'ask_up1.create', 'Wystawiono uzasadnienie rotacji licencji ' . self::code($id) . ' (ASK-UP1) — druk czeka na odręczny podpis kierownika.', $actor);
        return $id;
    }

    /** Potwierdzenie, że druk ASK-UP1 został podpisany odręcznie przez kierownika. Tylko kierownik / administrator. */
    public function confirmSigned(int $id, array $actor): void
    {
        $r = self::get($id);
        if (!$r || $r['status'] !== self::PENDING) throw new DomainException('Ten druk nie czeka na podpis.');
        if (!Am7Workflow::canApprove((int)($actor['id'] ?? 0))) throw new DomainException('Podpis na ASK-UP1 potwierdza kierownik (rola „kierownik” w panelu TI) albo administrator.');
        db()->prepare("UPDATE license_rotations SET status = 'signed', confirmed_by = ?, confirmed_name = ?, confirmed_ip = ?, confirmed_at = ? WHERE id = ?")
           ->execute([($actor['id'] ?? 0) ?: null, (string)($actor['name'] ?? ''), self::ip(), ldNow(), $id]);
        self::event($r, 'ask_up1.signed', 'Potwierdzono odręczny podpis kierownika na ASK-UP1 ' . self::code($id) . '.', $actor);
    }

    public function cancel(int $id, string $reason, array $actor): void
    {
        $r = self::get($id);
        if (!$r || $r['status'] !== self::PENDING) throw new DomainException('Anulować można tylko druk, który czeka na podpis.');
        db()->prepare("UPDATE license_rotations SET status = 'cancelled', cancel_reason = ? WHERE id = ?")->execute([trim($reason), $id]);
        self::event($r, 'ask_up1.cancel', 'Anulowano ASK-UP1 ' . self::code($id) . ($reason !== '' ? ': ' . trim($reason) : '') . '.', $actor);
    }

    /** Druk ASK-UP1. @return array{out:array,number:string} */
    public function document(array $r): array
    {
        [$t] = LicenseCorrection::target((string)$r['scope'], (int)$r['target_id']);
        $html = (new LicenseDocument())->askUp1Html($r, $t);
        $num = self::docNumber($r);
        return ['out' => LicenseDocument::render($html, 'Uzasadnienie rotacji licencji (ASK-UP1) — ' . $r['target_number'], $num, 'ASK-UP1'), 'number' => $num];
    }
}
