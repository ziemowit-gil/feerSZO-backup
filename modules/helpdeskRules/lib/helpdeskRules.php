<?php
/**
 * modules/helpdeskRules/lib/helpdeskRules.php — reguły automatyczne Helpdesku: wyzwalacz + warunki (AND) → kroki.
 *
 * Wyzwalacze:
 *   create   — po utworzeniu zgłoszenia (formularz, panel, e-mail),
 *   status   — po zmianie statusu (opcjonalnie: tylko na wskazany status),
 *   message  — po odpowiedzi zgłaszającego,
 *   idle     — cron: zgłoszenie w statusie X bez aktywności od N dni,
 *   sla      — cron: stan SLA (przekroczone / zbliża się termin reakcji lub rozwiązania).
 * Warunki łączą się iloczynem: kategoria, priorytet, źródło, słowa kluczowe, tag, adres/domena zgłaszającego, przypisanie
 * (nieprzypisane / przypisane), pora (w godzinach pracy / poza nimi). Akcje to kroki jak w makrach (hdMacroSteps):
 * status, priorytet, kategoria, przypisanie, tagi, notatka, obserwator, powiadomienie operatora, odpowiedź do zgłaszającego,
 * wykonanie makra. Reguły idą wg kolejności; „zatrzymaj kolejne” kończy przetwarzanie dla zdarzenia.
 * Reguły czasowe (idle/sla) wykonują się raz na zgłoszenie (helpdesk_rule_log). Zmiany robione przez reguły nie uruchamiają
 * kolejnych reguł (ochrona przed pętlami).
 */
require_once dirname(__DIR__, 3) . '/includes/helpdesk.php';
require_once dirname(__DIR__, 2) . '/helpdeskMacros/lib/helpdeskMacros.php';
require_once dirname(__DIR__, 2) . '/helpdeskTime/lib/helpdeskTime.php';

const HD_RULE_TRIGGERS = [
    'create' => 'Nowe zgłoszenie', 'status' => 'Zmiana statusu', 'message' => 'Odpowiedź zgłaszającego',
    'idle' => 'Brak aktywności (czasowa)', 'sla' => 'Stan SLA (czasowa)',
];
const HD_RULE_SLA_STATES = [
    'resolution_breached' => 'Przekroczony termin rozwiązania', 'resolution_due_soon' => 'Termin rozwiązania za < 1 h',
    'response_breached' => 'Przekroczony termin reakcji', 'response_due_soon' => 'Termin reakcji za < 1 h',
];

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
    foreach ([
        "ALTER TABLE helpdesk_rules ADD COLUMN cond_tag TEXT NOT NULL DEFAULT ''",
        "ALTER TABLE helpdesk_rules ADD COLUMN cond_email TEXT NOT NULL DEFAULT ''",
        "ALTER TABLE helpdesk_rules ADD COLUMN cond_assigned TEXT NOT NULL DEFAULT ''",
        "ALTER TABLE helpdesk_rules ADD COLUMN cond_hours TEXT NOT NULL DEFAULT ''",
        "ALTER TABLE helpdesk_rules ADD COLUMN cond_to_status TEXT NOT NULL DEFAULT ''",
        "ALTER TABLE helpdesk_rules ADD COLUMN cond_sla TEXT NOT NULL DEFAULT ''",
        "ALTER TABLE helpdesk_rules ADD COLUMN act_steps TEXT NOT NULL DEFAULT ''",
        "ALTER TABLE helpdesk_rules ADD COLUMN stop_after INTEGER NOT NULL DEFAULT 0",
    ] as $sql) { try { db()->exec($sql); } catch (\Throwable $e) {} }
    db()->exec("CREATE TABLE IF NOT EXISTS helpdesk_rule_log (
        rule_id   INTEGER NOT NULL,
        ticket_id INTEGER NOT NULL,
        fired_at  TEXT NOT NULL DEFAULT (datetime('now','localtime')),
        PRIMARY KEY (rule_id, ticket_id)
    )");
}

function hdRulesList(): array
{
    hdRulesMigrate();
    return db_all("SELECT * FROM helpdesk_rules ORDER BY sort_order, id");
}

/** Kroki reguły: z act_steps albo — dla reguł sprzed kreatora — z pojedynczych pól act_*. */
function hdRuleSteps(array $r): array
{
    if (trim((string)($r['act_steps'] ?? '')) !== '') return hdMacroSteps(['act_steps' => $r['act_steps']]);
    $out = [];
    if (!empty($r['act_assign'])) $out[] = ['type' => 'assign_user', 'value' => (string)(int)$r['act_assign']];
    if (($r['act_priority'] ?? '') !== '') $out[] = ['type' => 'priority', 'value' => (string)$r['act_priority']];
    if (($r['act_category'] ?? '') !== '') $out[] = ['type' => 'category', 'value' => (string)$r['act_category']];
    if (($r['act_status'] ?? '') !== '') $out[] = ['type' => 'status', 'value' => (string)$r['act_status']];
    if (($r['act_note'] ?? '') !== '') $out[] = ['type' => 'note', 'value' => (string)$r['act_note']];
    return $out;
}

/** Walidacja i zapis reguły. Zwraca listę błędów (pusta = zapisano). */
function hdRulesSave(int $id, array $in): array
{
    hdRulesMigrate();
    $e = [];
    $name = trim((string)($in['name'] ?? ''));
    if ($name === '') $e[] = 'Podaj nazwę reguły.';
    $trigger = isset(HD_RULE_TRIGGERS[$in['trigger_type'] ?? '']) ? (string)$in['trigger_type'] : 'create';
    $pick = fn(string $k, array $allowed) => isset($allowed[$in[$k] ?? '']) ? (string)$in[$k] : '';
    $enum = fn(string $k, array $allowed) => in_array($in[$k] ?? '', $allowed, true) ? (string)$in[$k] : '';
    $steps = hdMacroActionFields($in)['act_steps'];
    $d = [
        'name' => mb_substr($name, 0, 120), 'trigger_type' => $trigger,
        'cond_category' => $pick('cond_category', hd_categories()), 'cond_priority' => $pick('cond_priority', HD_PRIORITIES),
        'cond_source' => preg_replace('/[^a-z_]/', '', strtolower((string)($in['cond_source'] ?? ''))),
        'cond_keyword' => mb_substr(trim((string)($in['cond_keyword'] ?? '')), 0, 120),
        'cond_tag' => mb_substr(hdTagNormalize((string)($in['cond_tag'] ?? '')), 0, 30),
        'cond_email' => mb_substr(strtolower(trim((string)($in['cond_email'] ?? ''))), 0, 120),
        'cond_assigned' => $enum('cond_assigned', ['none', 'some']), 'cond_hours' => $enum('cond_hours', ['on', 'off']),
        'cond_status' => $pick('cond_status', HD_STATUSES), 'cond_idle_days' => max(0, (int)($in['cond_idle_days'] ?? 0)),
        'cond_to_status' => $pick('cond_to_status', HD_STATUSES), 'cond_sla' => $pick('cond_sla', HD_RULE_SLA_STATES),
        'act_steps' => $steps, 'act_assign' => null, 'act_priority' => '', 'act_category' => '', 'act_status' => '', 'act_note' => '',
        'stop_after' => !empty($in['stop_after']) ? 1 : 0,
        'is_active' => !empty($in['is_active']) ? 1 : 0, 'sort_order' => (int)($in['sort_order'] ?? 100),
    ];
    // pola warunków nieużywane przez wybrany wyzwalacz zerujemy (ukryte w formularzu, ale wysyłane)
    if ($trigger !== 'status') $d['cond_to_status'] = '';
    if ($trigger !== 'idle') { $d['cond_status'] = ''; $d['cond_idle_days'] = 0; }
    if ($trigger !== 'sla') $d['cond_sla'] = '';
    if ($steps === '') $e[] = 'Dodaj co najmniej jeden krok.';
    if ($trigger === 'idle' && ($d['cond_status'] === '' || $d['cond_idle_days'] < 1)) $e[] = 'Reguła „brak aktywności” wymaga statusu i liczby dni (min. 1).';
    if ($trigger === 'sla' && $d['cond_sla'] === '') $e[] = 'Reguła SLA wymaga wybrania stanu SLA.';
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
    db()->prepare("DELETE FROM helpdesk_rule_log WHERE rule_id=?")->execute([$id]);
}

function hdRulesToggle(int $id): void
{
    hdRulesMigrate();
    db()->prepare("UPDATE helpdesk_rules SET is_active = 1 - is_active WHERE id=?")->execute([$id]);
}

/** Czy teraz jest czas pracy (wg ustawień Helpdesku). */
function hdRulesInWorkHours(?int $ts = null): bool
{
    $ts = $ts ?? time();
    $w = hdWorkWindow($ts, hdWorkConfig());
    return $w !== null && $ts >= $w[0] && $ts < $w[1];
}

/** Czy zgłoszenie spełnia warunki reguły (wszystkie niepuste warunki naraz). $ctx['to_status'] — nowy status przy zdarzeniu „status”. */
function hdRulesMatches(array $r, array $t, array $ctx = []): bool
{
    if ($r['cond_category'] !== '' && $t['category'] !== $r['cond_category']) return false;
    if ($r['cond_priority'] !== '' && $t['priority'] !== $r['cond_priority']) return false;
    if ($r['cond_source'] !== '' && (string)$t['source'] !== $r['cond_source']) return false;
    if (($r['cond_to_status'] ?? '') !== '' && ($ctx['to_status'] ?? '') !== $r['cond_to_status']) return false;
    if (($r['cond_assigned'] ?? '') === 'none' && !empty($t['assigned_to'])) return false;
    if (($r['cond_assigned'] ?? '') === 'some' && empty($t['assigned_to'])) return false;
    if (($r['cond_hours'] ?? '') !== '' && (($r['cond_hours'] === 'on') !== hdRulesInWorkHours())) return false;
    if (($r['cond_email'] ?? '') !== '') {
        $mail = strtolower((string)($t['requester_email'] ?? ''));
        $any = false;
        foreach (preg_split('/\s*,\s*/', $r['cond_email']) as $x) if ($x !== '' && str_contains($mail, $x)) { $any = true; break; }
        if (!$any) return false;
    }
    if (($r['cond_tag'] ?? '') !== '' && !in_array($r['cond_tag'], hdTagsOf((int)$t['id']), true)) return false;
    if ($r['cond_keyword'] !== '') {
        $hay = mb_strtolower($t['title'] . "\n" . $t['description']);
        $any = false;
        foreach (preg_split('/\s*,\s*/', mb_strtolower($r['cond_keyword'])) as $kw) if ($kw !== '' && mb_strpos($hay, $kw) !== false) { $any = true; break; }
        if (!$any) return false;
    }
    return true;
}

/** Wykonuje kroki reguły; zapisuje notatkę wewnętrzną i powiadamia o zmianie statusu. Zwraca listę zmian. */
function hdRulesRunSteps(array $r, int $ticketId): array
{
    unset($GLOBALS['_hdStepsStatus'][$ticketId]);
    $done = hdStepsRun($ticketId, hdRuleSteps($r), 0, 'Reguła „' . $r['name'] . '”');
    db_insert('helpdesk_messages', ['ticket_id' => $ticketId, 'user_id' => null, 'user_name' => 'Reguła automatyczna',
        'body' => 'Reguła „' . $r['name'] . '”: ' . ($done ? implode(', ', $done) : 'warunki spełnione, bez zmian'), 'is_internal' => 1]);
    db()->prepare("UPDATE helpdesk_rules SET hits=hits+1, last_hit_at=? WHERE id=?")->execute([date('Y-m-d H:i:s'), (int)$r['id']]);
    if (isset($GLOBALS['_hdStepsStatus'][$ticketId])) {
        [$old, $new] = $GLOBALS['_hdStepsStatus'][$ticketId];
        $fresh = db_one("SELECT * FROM helpdesk_tickets WHERE id=?", [$ticketId]);
        if ($fresh) { try { hd_notify_status_change($fresh, $old, $new, ''); } catch (\Throwable $e) {} }
    }
    return $done;
}

/**
 * Reguły zdarzeniowe (create / status / message) dla zgłoszenia. Zwraca liczbę zastosowanych reguł.
 * Wywołanie z reguły (np. krok zmieniający status) nie uruchamia kolejnych reguł.
 */
function hdRulesFire(int $ticketId, string $trigger, array $ctx = []): int
{
    hdRulesMigrate();
    if (!empty($GLOBALS['_hdRulesRunning'])) return 0;
    $GLOBALS['_hdRulesRunning'] = true;
    $applied = 0;
    try {
        foreach (db_all("SELECT * FROM helpdesk_rules WHERE is_active=1 AND trigger_type=? ORDER BY sort_order, id", [$trigger]) as $r) {
            $t = db_one("SELECT * FROM helpdesk_tickets WHERE id=?", [$ticketId]);
            if (!$t || !hdRulesMatches($r, $t, $ctx)) continue;
            hdRulesRunSteps($r, $ticketId);
            $applied++;
            if (!empty($r['stop_after'])) break;
        }
    } finally {
        $GLOBALS['_hdRulesRunning'] = false;
    }
    return $applied;
}

/** Zgodność wsteczna: reguły „przy utworzeniu”. */
function hdRulesApplyOnCreate(int $ticketId): int
{
    return hdRulesFire($ticketId, 'create');
}

/** Reguły czasowe (idle, sla) — cron co godzinę; każda reguła raz na zgłoszenie. Zwraca [nazwa reguły => liczba zgłoszeń]. */
function hdRulesRunTime(bool $dryRun = false): array
{
    hdRulesMigrate();
    $out = [];
    $GLOBALS['_hdRulesRunning'] = true;
    try {
        foreach (db_all("SELECT * FROM helpdesk_rules WHERE is_active=1 AND trigger_type IN ('idle','sla') ORDER BY sort_order, id") as $r) {
            $hits = 0;
            if ($r['trigger_type'] === 'idle') {
                $cut = date('Y-m-d H:i:s', strtotime('-' . (int)$r['cond_idle_days'] . ' days'));
                $cands = db_all("SELECT * FROM helpdesk_tickets WHERE status=? AND merged_into IS NULL AND COALESCE(updated_at, created_at) < ?", [$r['cond_status'], $cut]);
            } else {
                $cands = db_all("SELECT * FROM helpdesk_tickets WHERE merged_into IS NULL AND status NOT IN ('rozwiązane','zamknięte')");
                [$part, $state] = explode('_', $r['cond_sla'], 2) + ['', ''];
                $want = $state === 'due_soon' ? 'due_soon' : 'breached';
                $cands = array_values(array_filter($cands, fn($t) => (hd_sla($t)[$part]['state'] ?? '') === $want));
            }
            foreach ($cands as $t) {
                if (!hdRulesMatches($r, $t)) continue;
                if (db_one("SELECT 1 FROM helpdesk_rule_log WHERE rule_id=? AND ticket_id=?", [(int)$r['id'], (int)$t['id']])) continue;
                $hits++;
                if ($dryRun) continue;
                db()->prepare("INSERT OR IGNORE INTO helpdesk_rule_log (rule_id, ticket_id) VALUES (?,?)")->execute([(int)$r['id'], (int)$t['id']]);
                hdRulesRunSteps($r, (int)$t['id']);
            }
            $out[$r['name']] = $hits;
        }
    } finally {
        $GLOBALS['_hdRulesRunning'] = false;
    }
    return $out;
}

/** Zgodność wsteczna dla crona. */
function hdRulesRunIdle(bool $dryRun = false): array
{
    return hdRulesRunTime($dryRun);
}
