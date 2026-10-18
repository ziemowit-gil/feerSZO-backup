<?php
/**
 * modules/ti_dop/lib/AutoRm.php — RM wystawiane automatycznie co miesiąc na życzenie uczestnika.
 *
 * Biuro włącza opcję na karcie RM uczestnika (z datą i formą prośby). Agent CRON ti_auto_rm (cron/agents/tiAutoRm.php)
 * od dnia AUTO_RM_DAY każdego miesiąca wystawia RM za poprzedni miesiąc (całe konto) w trybie 'auto':
 * wykonanie od razu, PDF w teczce kursanta, e-mail do kursanta i rodzica. Raz na miesiąc na uczestnika
 * (pomija, gdy RM za ten miesiąc już istnieje — także wystawione ręcznie).
 */
declare(strict_types=1);

require_once __DIR__ . '/SettlementDocs.php';

final class AutoRm
{
    /** Od którego dnia miesiąca wystawiać RM za poprzedni miesiąc (rozliczenia miesiąca zwykle gotowe do 5.). */
    public const DAY = 5;

    public static function migrate(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        settleMigrate();
        if (function_exists('szo_schema_current') && szo_schema_current('ti_dop_auto_rm', __FILE__)) return;
        db()->exec("CREATE TABLE IF NOT EXISTS settle_auto_rm (
            client_id    INTEGER PRIMARY KEY,
            enabled      INTEGER NOT NULL DEFAULT 1,
            requested_on VARCHAR(10) NOT NULL DEFAULT '',
            request_note VARCHAR(300) NOT NULL DEFAULT '',
            set_by       VARCHAR(190) NOT NULL DEFAULT '',
            set_at       VARCHAR(19),
            last_ym      VARCHAR(7) NOT NULL DEFAULT '',
            last_doc_id  INTEGER NOT NULL DEFAULT 0
        )");
    }

    public static function get(int $clientId): ?array
    {
        self::migrate();
        return db_one("SELECT * FROM settle_auto_rm WHERE client_id = ?", [$clientId]) ?: null;
    }

    /** Uczestnicy z włączonym automatycznym RM (z nazwą). */
    public static function enabledList(): array
    {
        self::migrate();
        return db_all("SELECT a.*, c.name AS client_name FROM settle_auto_rm a JOIN k30_clients c ON c.id = a.client_id WHERE a.enabled = 1 ORDER BY c.name COLLATE NOCASE");
    }

    public static function set(int $clientId, bool $enabled, string $requestedOn, string $note, array $actor): void
    {
        self::migrate();
        if (!db_one("SELECT id FROM k30_clients WHERE id = ?", [$clientId])) throw new InvalidArgumentException('Nie ma takiego uczestnika.');
        if ($enabled && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $requestedOn)) throw new InvalidArgumentException('Podaj datę prośby uczestnika.');
        $note = mb_substr(trim($note), 0, 300);
        if (self::get($clientId)) db_exec("UPDATE settle_auto_rm SET enabled = ?, requested_on = CASE WHEN ? = 1 THEN ? ELSE requested_on END, request_note = CASE WHEN ? = 1 THEN ? ELSE request_note END, set_by = ?, set_at = ? WHERE client_id = ?",
            [$enabled ? 1 : 0, $enabled ? 1 : 0, $requestedOn, $enabled ? 1 : 0, $note, (string)$actor['name'], ldNow(), $clientId]);
        else db_insert('settle_auto_rm', ['client_id' => $clientId, 'enabled' => $enabled ? 1 : 0, 'requested_on' => $requestedOn, 'request_note' => $note, 'set_by' => (string)$actor['name'], 'set_at' => ldNow()]);
        if (function_exists('audit_log')) audit_log('ti_dop.auto_rm', ['client_id' => $clientId, 'enabled' => $enabled, 'requested_on' => $requestedOn, 'note' => $note, 'by' => $actor['name']], ($actor['id'] ?? 0) ?: null);
    }

    /** Miesiąc do wystawienia na dzień $today (poprzedni miesiąc, od dnia DAY) albo null. */
    public static function dueYm(string $today): ?string
    {
        $t = strtotime($today);
        return (int)date('j', $t) >= self::DAY ? date('Y-m', strtotime(date('Y-m-01', $t) . ' -1 month')) : null;
    }

    /**
     * Wystawia zaległe RM. @return array{ym:?string, issued:list<string>, skipped:list<string>, errors:list<string>}
     */
    public static function run(bool $dry = false, ?string $today = null): array
    {
        self::migrate();
        $out = ['ym' => self::dueYm($today ?? date('Y-m-d')), 'issued' => [], 'skipped' => [], 'errors' => []];
        if (!$out['ym']) return $out;
        $ym = $out['ym'];
        foreach (self::enabledList() as $a) {
            $cid = (int)$a['client_id'];
            if ($a['requested_on'] !== '' && $a['requested_on'] > date('Y-m-t', strtotime($ym . '-01'))) { $out['skipped'][] = $a['client_name'] . ': prośba po tym miesiącu'; continue; }
            if ($a['last_ym'] === $ym || db_one("SELECT id FROM settlement_docs WHERE kind = 'RM' AND client_id = ? AND course_id = 0 AND status <> 'rejected' AND data LIKE ?", [$cid, '%"ym":"' . $ym . '"%'])) {
                $out['skipped'][] = $a['client_name'] . ': RM za ' . $ym . ' już jest';
                continue;
            }
            if ($dry) { $out['issued'][] = $a['client_name'] . ' — RM za ' . $ym . ' (podgląd)'; continue; }
            try {
                $r = SettlementDocs::create(SettleKind::Rm, $cid, ['course_id' => 0, 'ym' => $ym, 'uzasadnienie' => 'Wystawione automatycznie na prośbę uczestnika z dnia ' . ldDate($a['requested_on'])],
                    'auto', ['id' => 0, 'name' => 'SZO — automatycznie (na prośbę uczestnika)']);
                if ($r['errors']) { $out['errors'][] = $a['client_name'] . ': ' . implode(' ', $r['errors']); continue; }
                db_exec("UPDATE settle_auto_rm SET last_ym = ?, last_doc_id = ? WHERE client_id = ?", [$ym, $r['id'], $cid]);
                $out['issued'][] = $a['client_name'] . ' — ' . SettlementDocs::get($r['id'])['number'] . ' (' . implode('; ', $r['report']) . ')';
            } catch (\Throwable $e) { $out['errors'][] = $a['client_name'] . ': ' . $e->getMessage(); }
        }
        return $out;
    }
}
