<?php
/**
 * modules/strategy_actions/logic/ActionsJournal.php — etapy (kamienie milowe) i historia działania.
 * Historia jest dopisywana (bez edycji i usuwania): zmiany z formularza, status, wskaźniki, etapy, uczestnicy CRM,
 * kopie oraz komentarze zespołu. Daty zapisywane w czasie lokalnym serwera (date()).
 */
require_once __DIR__ . '/actionsSchema.php';

final class ActionsJournal
{
    public const KINDS = [
        'create'    => ['label' => 'Utworzenie',  'icon' => 'bi-plus-circle'],
        'change'    => ['label' => 'Zmiana',      'icon' => 'bi-pencil'],
        'status'    => ['label' => 'Status',      'icon' => 'bi-arrow-left-right'],
        'indicator' => ['label' => 'Wskaźniki',   'icon' => 'bi-bar-chart'],
        'milestone' => ['label' => 'Etap',        'icon' => 'bi-flag'],
        'crm'       => ['label' => 'Uczestnicy',  'icon' => 'bi-people'],
        'duplicate' => ['label' => 'Kopia',       'icon' => 'bi-copy'],
        'comment'   => ['label' => 'Komentarz',   'icon' => 'bi-chat-left-text'],
    ];

    public static function log(int $actionId, string $kind, string $message, ?int $userId = null): void
    {
        actionsSchemaEnsure();
        $userId ??= (int)(current_user()['id'] ?? 0);
        db()->prepare("INSERT INTO action_log (action_id, user_id, kind, message, created_at) VALUES (?,?,?,?,?)")
            ->execute([$actionId, $userId ?: null, isset(self::KINDS[$kind]) ? $kind : 'change', mb_substr($message, 0, 2000), date('Y-m-d H:i:s')]);
    }

    /** Historia działania od najnowszych. */
    public static function entries(int $actionId, int $limit = 200): array
    {
        actionsSchemaEnsure();
        return array_map(fn($r) => [
            'id' => (int)$r['id'], 'kind' => (string)$r['kind'], 'message' => (string)$r['message'],
            'at' => (string)$r['created_at'], 'user' => (string)($r['user_name'] ?? ''),
            'icon' => self::KINDS[$r['kind']]['icon'] ?? 'bi-dot',
        ], db_all("SELECT l.*, u.name AS user_name FROM action_log l LEFT JOIN users u ON u.id = l.user_id
                   WHERE l.action_id = ? ORDER BY l.id DESC LIMIT " . max(1, $limit), [$actionId]));
    }

    // ── Etapy ─────────────────────────────────────────────────────────────────

    public static function milestones(int $actionId): array
    {
        actionsSchemaEnsure();
        return array_map([self::class, 'msShape'], db_all(
            "SELECT m.*, o.name AS owner_name, d.name AS done_by_name
             FROM action_milestones m
             LEFT JOIN users o ON o.id = m.owner_id
             LEFT JOIN users d ON d.id = m.done_by
             WHERE m.action_id = ?
             ORDER BY (m.done_at IS NOT NULL), (m.due_date IS NULL), m.due_date, m.sort_order, m.id", [$actionId]));
    }

    private static function msShape(array $m): array
    {
        return ['id' => (int)$m['id'], 'title' => (string)$m['title'], 'due' => $m['due_date'] ?: null,
                'ownerId' => $m['owner_id'] !== null ? (int)$m['owner_id'] : null, 'owner' => (string)($m['owner_name'] ?? ''),
                'done' => $m['done_at'] !== null, 'doneAt' => (string)($m['done_at'] ?? ''), 'doneBy' => (string)($m['done_by_name'] ?? '')];
    }

    /** Podsumowanie etapów wszystkich działań: [action_id => total, done, overdue, next{title,due}] — jedno zapytanie. */
    public static function milestoneStats(): array
    {
        actionsSchemaEnsure();
        $today = date('Y-m-d');
        $out = [];
        foreach (db_all("SELECT action_id, title, due_date, done_at FROM action_milestones ORDER BY due_date IS NULL, due_date, id") as $m) {
            $aid = (int)$m['action_id'];
            $s = $out[$aid] ?? ['total' => 0, 'done' => 0, 'overdue' => 0, 'next' => null];
            $s['total']++;
            if ($m['done_at'] !== null) { $s['done']++; }
            else {
                if ($m['due_date'] && $m['due_date'] < $today) $s['overdue']++;
                if ($s['next'] === null) $s['next'] = ['title' => (string)$m['title'], 'due' => $m['due_date'] ?: null];
            }
            $out[$aid] = $s;
        }
        return $out;
    }

    /** @return string|null komunikat błędu */
    public static function addMilestone(int $actionId, string $title, ?string $due, ?int $ownerId): ?string
    {
        actionsSchemaEnsure();
        $title = trim($title);
        if ($title === '') return 'Podaj nazwę etapu.';
        if ($due !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) $due = null;
        if ($ownerId && !db_one("SELECT id FROM users WHERE id = ?", [$ownerId])) $ownerId = null;
        $uid = (int)(current_user()['id'] ?? 0);
        $sort = (int)(db_one("SELECT COALESCE(MAX(sort_order), 0) + 1 AS n FROM action_milestones WHERE action_id = ?", [$actionId])['n'] ?? 1);
        db()->prepare("INSERT INTO action_milestones (action_id, title, due_date, owner_id, sort_order, created_by, created_at) VALUES (?,?,?,?,?,?,?)")
            ->execute([$actionId, mb_substr($title, 0, 200), $due, $ownerId ?: null, $sort, $uid ?: null, date('Y-m-d H:i:s')]);
        self::log($actionId, 'milestone', 'Dodano etap „' . mb_substr($title, 0, 200) . '”' . ($due ? ' (termin ' . date('d.m.Y', strtotime($due)) . ')' : '') . '.');
        return null;
    }

    public static function toggleMilestone(int $actionId, int $msId): ?string
    {
        actionsSchemaEnsure();
        $m = db_one("SELECT * FROM action_milestones WHERE id = ? AND action_id = ?", [$msId, $actionId]);
        if (!$m) return 'Etap nie istnieje.';
        $uid = (int)(current_user()['id'] ?? 0);
        if ($m['done_at'] === null) {
            db()->prepare("UPDATE action_milestones SET done_at = ?, done_by = ? WHERE id = ?")->execute([date('Y-m-d H:i:s'), $uid ?: null, $msId]);
            self::log($actionId, 'milestone', 'Zakończono etap „' . $m['title'] . '”.');
        } else {
            db()->prepare("UPDATE action_milestones SET done_at = NULL, done_by = NULL WHERE id = ?")->execute([$msId]);
            self::log($actionId, 'milestone', 'Ponownie otwarto etap „' . $m['title'] . '”.');
        }
        return null;
    }

    public static function deleteMilestone(int $actionId, int $msId): ?string
    {
        actionsSchemaEnsure();
        $m = db_one("SELECT title FROM action_milestones WHERE id = ? AND action_id = ?", [$msId, $actionId]);
        if (!$m) return 'Etap nie istnieje.';
        db()->prepare("DELETE FROM action_milestones WHERE id = ? AND action_id = ?")->execute([$msId, $actionId]);
        self::log($actionId, 'milestone', 'Usunięto etap „' . $m['title'] . '”.');
        return null;
    }

    /** Kopia etapów do nowego działania (bez terminów i odhaczeń — nowy cykl). */
    public static function copyMilestones(int $fromId, int $toId): void
    {
        actionsSchemaEnsure();
        db()->prepare("INSERT INTO action_milestones (action_id, title, owner_id, sort_order, created_by, created_at)
                       SELECT ?, title, owner_id, sort_order, ?, ? FROM action_milestones WHERE action_id = ? ORDER BY sort_order, id")
            ->execute([$toId, (int)(current_user()['id'] ?? 0) ?: null, date('Y-m-d H:i:s'), $fromId]);
    }

    // ── Opis zmian z formularza ───────────────────────────────────────────────

    /**
     * Krótki opis różnic między zapisanym działaniem ($old = ActionsService::find) a nowymi danymi formularza.
     * Pusty tekst = brak zmian.
     */
    public static function describeChanges(array $old, array $new, array $grants, array $inds): string
    {
        $labels = [
            'nazwa' => 'nazwa', 'typ' => 'typ', 'opis' => 'opis', 'koordynator_id' => 'koordynator',
            'data_od' => 'termin', 'data_do' => 'termin', 'cykliczne' => 'cykliczność', 'czestotliwosc' => 'cykliczność',
            'lokalizacja' => 'miejsce', 'forma' => 'forma', 'link_online' => 'forma', 'wlasne_dzialanie' => 'finansowanie',
            'korzysci_tytul' => 'opis dla uczestników', 'korzysci' => 'opis dla uczestników', 'dla_kogo' => 'opis dla uczestników',
            'cel_statutowy' => 'cel §6',
        ];
        $norm = fn($v) => $v === null ? '' : trim((string)$v);
        $parts = [];
        if ($norm($old['status'] ?? '') !== $norm($new['status'] ?? '')) {
            $parts['status'] = 'status (' . ActionsService::statusLabel((string)$old['status']) . ' → ' . ActionsService::statusLabel((string)$new['status']) . ')';
        }
        foreach ($labels as $k => $label) {
            if (!array_key_exists($k, $new)) continue;
            if ($norm($old[$k] ?? null) !== $norm($new[$k])) $parts[$label] = $label;
        }
        $gKey = fn($rows) => implode('|', array_map(fn($g) => (int)$g['grant_id'] . ':' . (float)($g['kwota'] ?? 0) . ':' . (float)($g['udzial_procent'] ?? 0),
                                                    $rows));
        $oldG = $old['grants'] ?? [];
        usort($oldG, fn($a, $b) => (int)$a['grant_id'] <=> (int)$b['grant_id']);
        usort($grants, fn($a, $b) => (int)$a['grant_id'] <=> (int)$b['grant_id']);
        if ($gKey($oldG) !== $gKey($grants)) $parts['finansowanie'] = 'finansowanie';
        $iKey = fn($rows) => implode('|', array_map(fn($i) => trim((string)$i['nazwa']) . ':' . (float)($i['wartosc_planowana'] ?? 0) . ':' . (float)($i['wartosc_realizowana'] ?? 0), $rows));
        if ($iKey($old['indicators'] ?? []) !== $iKey($inds)) $parts['wskaźniki'] = 'wskaźniki';
        return $parts ? 'Zmieniono: ' . implode(', ', array_values($parts)) . '.' : '';
    }
}
