<?php
/**
 * modules/helpdeskRules/lib/helpdeskRules.php — reguły automatyczne Helpdesku (warunek → akcja).
 *
 * Wyzwalacze:
 *  - create — zaraz po utworzeniu zgłoszenia (hd_after_create): warunki kategoria / priorytet / źródło / słowo kluczowe
 *    (w temacie lub opisie), akcje: przypisz operatora, ustaw priorytet, zmień kategorię, dodaj notatkę wewnętrzną;
 *  - idle — cron co godzinę: zgłoszenia w danym statusie bez aktywności od N dni (np. rozwiązane → zamknij).
 * Reguły wykonują się wg kolejności; pierwsze przypisanie wygrywa (kolejne nie nadpisują przypisanego operatora).
 */
require_once dirname(__DIR__, 3) . '/includes/helpdesk.php';

function hdRulesMigrate(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    helpdesk_migrate();
    db()->exec("CREATE TABLE IF NOT EXISTS helpdesk_rules (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        name          TEXT NOT NULL,
        trigger_type  TEXT NOT NULL DEFAULT 'create',
        cond_category TEXT NOT NULL DEFAULT '',
        cond_priority TEXT NOT NULL DEFAULT '',
        cond_source   TEXT NOT NULL DEFAULT '',
        cond_keyword  TEXT NOT NULL DEFAULT '',
        cond_status   TEXT NOT NULL DEFAULT '',
        cond_idle_days INTEGER NOT NULL DEFAULT 0,
        act_assign    INTEGER,
        act_priority  TEXT NOT NULL DEFAULT '',
        act_category  TEXT NOT NULL DEFAULT '',
        act_status    TEXT NOT NULL DEFAULT '',
        act_note      TEXT NOT NULL DEFAULT '',
        is_active     INTEGER NOT NULL DEFAULT 1,
        sort_order    INTEGER NOT NULL DEFAULT 100,
        hits          INTEGER NOT NULL DEFAULT 0,
        last_hit_at   TEXT,
        created_at    TEXT NOT NULL DEFAULT (datetime('now','localtime'))
    )");
}

function hdRulesList(): array
{
    hdRulesMigrate();
    return db_all("SELECT r.*, u.name AS assign_name FROM helpdesk_rules r LEFT JOIN users u ON u.id=r.act_assign ORDER BY r.sort_order, r.id");
}

/** Walidacja i zapis reguły. Zwraca listę błędów (pusta = zapisano). */
function hdRulesSave(int $id, array $in): array
{
    hdRulesMigrate();
    $e = [];
    $name = trim((string)($in['name'] ?? ''));
    if ($name === '') $e[] = 'Podaj nazwę reguły.';
    $trigger = ($in['trigger_type'] ?? 'create') === 'idle' ? 'idle' : 'create';
    $pick = fn(string $k, array $allowed) => isset($allowed[$in[$k] ?? '']) ? (string)$in[$k] : '';
    $d = [
        'name' => mb_substr($name, 0, 120), 'trigger_type' => $trigger,
        'cond_category' => $pick('cond_category', hd_categories()), 'cond_priority' => $pick('cond_priority', HD_PRIORITIES),
        'cond_source' => preg_replace('/[^a-z_]/', '', strtolower((string)($in['cond_source'] ?? ''))),
        'cond_keyword' => mb_substr(trim((string)($in['cond_keyword'] ?? '')), 0, 120),
        'cond_status' => $pick('cond_status', HD_STATUSES), 'cond_idle_days' => max(0, (int)($in['cond_idle_days'] ?? 0)),
        'act_assign' => ((int)($in['act_assign'] ?? 0)) ?: null,
        'act_priority' => $pick('act_priority', HD_PRIORITIES), 'act_category' => $pick('act_category', hd_categories()),
        'act_status' => $pick('act_status', HD_STATUSES), 'act_note' => mb_substr(trim((string)($in['act_note'] ?? '')), 0, 1000),
        'is_active' => !empty($in['is_active']) ? 1 : 0, 'sort_order' => (int)($in['sort_order'] ?? 100),
    ];
    if ($d['act_assign'] && !db_one("SELECT id FROM users WHERE id=? AND helpdesk_operator=1 AND is_active=1", [$d['act_assign']])) $e[] = 'Wybrany użytkownik nie jest operatorem.';
    if ($trigger === 'idle' && ($d['cond_status'] === '' || $d['cond_idle_days'] < 1)) $e[] = 'Reguła czasowa wymaga statusu i liczby dni bez aktywności (min. 1).';
    if ($trigger === 'idle' && $d['act_status'] === '' && !$d['act_note']) $e[] = 'Reguła czasowa musi zmieniać status albo dodawać notatkę.';
    if ($trigger === 'create' && !$d['act_assign'] && $d['act_priority'] === '' && $d['act_category'] === '' && $d['act_note'] === '') $e[] = 'Wybierz co najmniej jedną akcję.';
    if ($e) return $e;
    if ($id > 0) {
        $set = implode(',', array_map(fn($k) => "$k=?", array_keys($d)));
        db()->prepare("UPDATE helpdesk_rules SET $set WHERE id=?")->execute([...array_values($d), $id]);
    } else {
        db_insert('helpdesk_rules', $d);
    }
    return [];
}

function hdRulesDelete(int $id): void
{
    hdRulesMigrate();
    db()->prepare("DELETE FROM helpdesk_rules WHERE id=?")->execute([$id]);
}

function hdRulesToggle(int $id): void
{
    hdRulesMigrate();
    db()->prepare("UPDATE helpdesk_rules SET is_active = 1 - is_active WHERE id=?")->execute([$id]);
}

/** Czy zgłoszenie spełnia warunki reguły „create”. */
function hdRulesMatches(array $r, array $t): bool
{
    if ($r['cond_category'] !== '' && $t['category'] !== $r['cond_category']) return false;
    if ($r['cond_priority'] !== '' && $t['priority'] !== $r['cond_priority']) return false;
    if ($r['cond_source'] !== '' && (string)$t['source'] !== $r['cond_source']) return false;
    if ($r['cond_keyword'] !== '') {
        $hay = mb_strtolower($t['title'] . "\n" . $t['description']);
        $any = false;
        foreach (preg_split('/\s*,\s*/', mb_strtolower($r['cond_keyword'])) as $kw) if ($kw !== '' && mb_strpos($hay, $kw) !== false) { $any = true; break; }
        if (!$any) return false;
    }
    return true;
}

function hdRulesNote(int $ticketId, string $text): void
{
    db_insert('helpdesk_messages', ['ticket_id' => $ticketId, 'user_id' => null, 'user_name' => 'Reguła automatyczna', 'body' => $text, 'is_internal' => 1]);
}

/** Reguły „create” po utworzeniu zgłoszenia. Zwraca liczbę zastosowanych reguł. */
function hdRulesApplyOnCreate(int $ticketId): int
{
    hdRulesMigrate();
    $applied = 0;
    foreach (db_all("SELECT * FROM helpdesk_rules WHERE is_active=1 AND trigger_type='create' ORDER BY sort_order, id") as $r) {
        $t = db_one("SELECT * FROM helpdesk_tickets WHERE id=?", [$ticketId]);
        if (!$t || !hdRulesMatches($r, $t)) continue;
        $upd = [];
        if ($r['act_assign'] && empty($t['assigned_to'])) $upd['assigned_to'] = (int)$r['act_assign'];
        if ($r['act_priority'] !== '') $upd['priority'] = $r['act_priority'];
        if ($r['act_category'] !== '') $upd['category'] = $r['act_category'];
        if ($upd) {
            $upd['updated_at'] = date('Y-m-d H:i:s');
            $set = implode(',', array_map(fn($k) => "$k=?", array_keys($upd)));
            db()->prepare("UPDATE helpdesk_tickets SET $set WHERE id=?")->execute([...array_values($upd), $ticketId]);
        }
        $notes = ['Reguła „' . $r['name'] . '”: ' . implode(', ', array_filter([
            isset($upd['assigned_to']) ? 'przypisano operatora' : '', isset($upd['priority']) ? 'priorytet → ' . (HD_PRIORITIES[$upd['priority']]['label'] ?? $upd['priority']) : '',
            isset($upd['category']) ? 'kategoria → ' . (hd_categories()[$upd['category']] ?? $upd['category']) : '',
        ])) ?: 'bez zmian pól'];
        if ($r['act_note'] !== '') $notes[] = $r['act_note'];
        hdRulesNote($ticketId, implode("\n", $notes));
        db()->prepare("UPDATE helpdesk_rules SET hits=hits+1, last_hit_at=? WHERE id=?")->execute([date('Y-m-d H:i:s'), (int)$r['id']]);
        $applied++;
        if (isset($upd['assigned_to'])) hdRulesNotifyAssignee($ticketId, (int)$upd['assigned_to']);
    }
    return $applied;
}

function hdRulesNotifyAssignee(int $ticketId, int $uid): void
{
    try {
        $op = db_one("SELECT email, name FROM users WHERE id=?", [$uid]);
        $t = db_one("SELECT number, title FROM helpdesk_tickets WHERE id=?", [$ticketId]);
        if (!$op || empty($op['email']) || !$t) return;
        require_once dirname(__DIR__, 3) . '/includes/mail_queue.php';
        mail_queue_add((string)$op['email'], (string)$op['name'], "[{$t['number']}] Przypisano Ci zgłoszenie: {$t['title']}",
            '<p>Reguła automatyczna przypisała Ci zgłoszenie ' . h($t['number']) . ' — ' . h($t['title']) . '.</p><p><a href="'
            . h(APP_URL . '/helpdesk/view.php?id=' . $ticketId) . '">Otwórz</a></p>');
    } catch (\Throwable $e) { error_log('[helpdeskRules] ' . $e->getMessage()); }
}

/** Reguły „idle” (cron). Zwraca [reguła => liczba zgłoszeń]. */
function hdRulesRunIdle(bool $dryRun = false): array
{
    hdRulesMigrate();
    $out = [];
    foreach (db_all("SELECT * FROM helpdesk_rules WHERE is_active=1 AND trigger_type='idle' ORDER BY sort_order, id") as $r) {
        $cut = date('Y-m-d H:i:s', strtotime('-' . (int)$r['cond_idle_days'] . ' days'));
        $rows = db_all("SELECT id FROM helpdesk_tickets WHERE status=? AND merged_into IS NULL AND COALESCE(updated_at, created_at) < ?"
            . ($r['cond_category'] !== '' ? " AND category=?" : '') . ($r['cond_priority'] !== '' ? " AND priority=?" : ''),
            array_values(array_filter([$r['cond_status'], $cut, $r['cond_category'] ?: null, $r['cond_priority'] ?: null], fn($v) => $v !== null)));
        $out[$r['name']] = count($rows);
        if ($dryRun) continue;
        foreach ($rows as $row) {
            $now = date('Y-m-d H:i:s');
            if ($r['act_status'] !== '') {
                $close = in_array($r['act_status'], ['zamknięte'], true);
                db()->prepare("UPDATE helpdesk_tickets SET status=?, updated_at=?" . ($close ? ", closed_at=COALESCE(closed_at, ?)" : '') . " WHERE id=?")
                    ->execute($close ? [$r['act_status'], $now, $now, (int)$row['id']] : [$r['act_status'], $now, (int)$row['id']]);
            }
            hdRulesNote((int)$row['id'], 'Reguła „' . $r['name'] . '”: brak aktywności od ' . (int)$r['cond_idle_days'] . ' dni'
                . ($r['act_status'] !== '' ? ' — status → ' . (HD_STATUSES[$r['act_status']]['label'] ?? $r['act_status']) : '') . '.' . ($r['act_note'] !== '' ? "\n" . $r['act_note'] : ''));
        }
        if ($rows) db()->prepare("UPDATE helpdesk_rules SET hits=hits+?, last_hit_at=? WHERE id=?")->execute([count($rows), date('Y-m-d H:i:s'), (int)$r['id']]);
    }
    return $out;
}
