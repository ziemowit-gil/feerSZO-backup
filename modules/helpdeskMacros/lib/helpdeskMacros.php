<?php
/**
 * modules/helpdeskMacros/lib/helpdeskMacros.php — makra Helpdesku: gotowa odpowiedź + akcje na zgłoszeniu.
 *
 * Makro (helpdesk_macros) ma treść (może być pusta) oraz opcjonalne akcje: status, priorytet, przypisanie do siebie, tag.
 * Przyciski makr w karcie zgłoszenia: kliknięcie wstawia treść do pola odpowiedzi i zapamiętuje makro — akcje wykonują się
 * razem z wysłaniem wiadomości; makro bez treści (same akcje) działa od razu. Przypięte makra (show_button=1) są przyciskami
 * stale widocznymi nad polem odpowiedzi; gdy żadne nie jest przypięte, pokazujemy pierwsze pięć aktywnych.
 */
require_once dirname(__DIR__, 3) . '/includes/helpdesk.php';
require_once dirname(__DIR__, 3) . '/modules/helpdeskCollab/lib/helpdeskCollab.php';

function hdMacrosMigrate(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    helpdesk_migrate();
    foreach ([
        "ALTER TABLE helpdesk_macros ADD COLUMN act_status TEXT NOT NULL DEFAULT ''",
        "ALTER TABLE helpdesk_macros ADD COLUMN act_priority TEXT NOT NULL DEFAULT ''",
        "ALTER TABLE helpdesk_macros ADD COLUMN act_assign_me INTEGER NOT NULL DEFAULT 0",
        "ALTER TABLE helpdesk_macros ADD COLUMN act_tag TEXT NOT NULL DEFAULT ''",
        "ALTER TABLE helpdesk_macros ADD COLUMN show_button INTEGER NOT NULL DEFAULT 0",
        "ALTER TABLE helpdesk_macros ADD COLUMN act_steps TEXT NOT NULL DEFAULT ''",
    ] as $sql) { try { db()->exec($sql); } catch (\Throwable $e) {} }
}

/** Aktywne makra z akcjami, w kolejności wyświetlania. */
function hdMacrosActive(): array
{
    hdMacrosMigrate();
    return db_all("SELECT * FROM helpdesk_macros WHERE is_active=1 ORDER BY sort_order, title");
}

/** Makra jako przyciski: przypięte, a gdy brak — pierwsze 5. */
function hdMacroButtons(array $macros): array
{
    $pinned = array_values(array_filter($macros, fn($m) => (int)($m['show_button'] ?? 0) === 1));
    return $pinned ?: array_slice($macros, 0, 5);
}

/** Typy kroków przebiegu makra: kod => etykieta. */
const HD_MACRO_STEP_TYPES = [
    'status' => 'Ustaw status', 'priority' => 'Ustaw priorytet', 'assign_me' => 'Przypisz do wykonującego', 'assign_user' => 'Przypisz do operatora',
    'tag' => 'Dodaj tag', 'untag' => 'Usuń tag', 'note' => 'Dodaj notatkę wewnętrzną', 'watch' => 'Dodaj obserwatora (operator)',
];

/** Kroki przebiegu: z act_steps (JSON) albo — dla starszych makr — z pojedynczych pól act_*. @return list<array{type:string,value:string}> */
function hdMacroSteps(array $m): array
{
    $json = trim((string)($m['act_steps'] ?? ''));
    if ($json !== '') {
        $d = json_decode($json, true);
        if (is_array($d)) return array_values(array_filter(array_map(fn($x) => isset($x['type']) && isset(HD_MACRO_STEP_TYPES[$x['type']]) ? ['type' => (string)$x['type'], 'value' => (string)($x['value'] ?? '')] : null, $d)));
    }
    $out = [];
    if (($m['act_status'] ?? '') !== '') $out[] = ['type' => 'status', 'value' => (string)$m['act_status']];
    if (($m['act_priority'] ?? '') !== '') $out[] = ['type' => 'priority', 'value' => (string)$m['act_priority']];
    if (!empty($m['act_assign_me'])) $out[] = ['type' => 'assign_me', 'value' => ''];
    if (($m['act_tag'] ?? '') !== '') $out[] = ['type' => 'tag', 'value' => (string)$m['act_tag']];
    return $out;
}

function hdMacroStepLabel(array $st): string
{
    $v = $st['value'];
    return match ($st['type']) {
        'status' => 'status → ' . (HD_STATUSES[$v]['label'] ?? $v),
        'priority' => 'priorytet → ' . (HD_PRIORITIES[$v]['label'] ?? $v),
        'assign_me' => 'przypisz do mnie',
        'assign_user' => 'przypisz: ' . (db_one("SELECT name FROM users WHERE id=?", [(int)$v])['name'] ?? '#' . $v),
        'tag' => 'tag: ' . $v, 'untag' => 'usuń tag: ' . $v,
        'note' => 'notatka',
        'watch' => 'obserwator: ' . (db_one("SELECT name FROM users WHERE id=?", [(int)$v])['name'] ?? '#' . $v),
        default => $st['type'],
    };
}

/** Czytelny opis przebiegu makra (do podpowiedzi na przycisku). */
function hdMacroActionLabels(array $m): array
{
    return array_map('hdMacroStepLabel', hdMacroSteps($m));
}

function hdMacroHasActions(array $m): bool
{
    return (bool)hdMacroSteps($m);
}

/**
 * Pola zapisu makra z formularza administracyjnego. Kroki przychodzą jako równoległe tablice
 * (step_type[], sv_status[], sv_priority[], sv_user[], sv_text[]) — wiersz i = jeden krok. Starsze pola act_* zerujemy.
 */
function hdMacroActionFields(array $in): array
{
    $steps = [];
    foreach ((array)($in['step_type'] ?? []) as $i => $type) {
        if (!isset(HD_MACRO_STEP_TYPES[$type])) continue;
        $val = match ($type) {
            'status' => (string)($in['sv_status'][$i] ?? ''),
            'priority' => (string)($in['sv_priority'][$i] ?? ''),
            'assign_user', 'watch' => (string)(int)($in['sv_user'][$i] ?? 0),
            'tag', 'untag' => mb_substr(hdTagNormalize((string)($in['sv_text'][$i] ?? '')), 0, 30),
            'note' => mb_substr(trim((string)($in['sv_text'][$i] ?? '')), 0, 1000),
            default => '',
        };
        if ($type === 'status' && !isset(HD_STATUSES[$val])) continue;
        if ($type === 'priority' && !isset(HD_PRIORITIES[$val])) continue;
        if (in_array($type, ['assign_user', 'watch'], true) && !db_one("SELECT id FROM users WHERE id=? AND is_active=1", [(int)$val])) continue;
        if (in_array($type, ['tag', 'untag', 'note'], true) && $val === '') continue;
        $steps[] = ['type' => $type, 'value' => $val];
        if (count($steps) >= 12) break;
    }
    return [
        'act_steps' => $steps ? json_encode($steps, JSON_UNESCAPED_UNICODE) : '',
        'act_status' => '', 'act_priority' => '', 'act_assign_me' => 0, 'act_tag' => '',
        'show_button' => !empty($in['show_button']) ? 1 : 0,
    ];
}

/**
 * Wykonuje przebieg makra na zgłoszeniu (operator $uid) — kroki po kolei. Zwraca listę wykonanych zmian
 * (pusta = nic nie zmieniono). Zmiana statusu wysyła powiadomienia jak zwykła zmiana; całość zapisuje się notatką wewnętrzną.
 */
function hdMacroApply(int $ticketId, int $macroId, int $uid, string $uname): array
{
    hdMacrosMigrate();
    $m = db_one("SELECT * FROM helpdesk_macros WHERE id=? AND is_active=1", [$macroId]);
    $t = db_one("SELECT * FROM helpdesk_tickets WHERE id=?", [$ticketId]);
    if (!$m || !$t) return [];
    $done = []; $now = date('Y-m-d H:i:s');
    $oldStatus = $t['status'];
    foreach (hdMacroSteps($m) as $st) {
        $v = $st['value'];
        $cur = db_one("SELECT * FROM helpdesk_tickets WHERE id=?", [$ticketId]) ?: $t;
        switch ($st['type']) {
            case 'status':
                if (isset(HD_STATUSES[$v]) && $v !== $cur['status']) {
                    $upd = ['status' => $v, 'updated_at' => $now];
                    if ($v === 'rozwiązane') $upd['resolved_at'] = $now;
                    if ($v === 'zamknięte') $upd['closed_at'] = $now;
                    db_update('helpdesk_tickets', $upd, $ticketId); $done[] = hdMacroStepLabel($st);
                }
                break;
            case 'priority':
                if (isset(HD_PRIORITIES[$v]) && $v !== $cur['priority']) { db_update('helpdesk_tickets', ['priority' => $v, 'updated_at' => $now], $ticketId); $done[] = hdMacroStepLabel($st); }
                break;
            case 'assign_me':
            case 'assign_user':
                $to = $st['type'] === 'assign_me' ? $uid : (int)$v;
                if ($to > 0 && (int)$cur['assigned_to'] !== $to) {
                    $upd = ['assigned_to' => $to, 'updated_at' => $now];
                    if ($cur['status'] === 'nowe') $upd['status'] = 'otwarte';
                    db_update('helpdesk_tickets', $upd, $ticketId);
                    $done[] = $st['type'] === 'assign_me' ? 'przypisano do ' . $uname : hdMacroStepLabel($st);
                }
                break;
            case 'tag':
                if (!in_array($v, hdTagsOf($ticketId), true)) { hdTagAdd($ticketId, $v); $done[] = hdMacroStepLabel($st); }
                break;
            case 'untag':
                if (in_array($v, hdTagsOf($ticketId), true)) { hdTagRemove($ticketId, $v); $done[] = hdMacroStepLabel($st); }
                break;
            case 'note':
                db_insert('helpdesk_messages', ['ticket_id' => $ticketId, 'user_id' => $uid, 'user_name' => $uname, 'body' => $v, 'is_internal' => 1]);
                $done[] = 'notatka';
                break;
            case 'watch':
                if (hdWatchAdd($ticketId, (int)$v, '', '', $uid) === null) $done[] = hdMacroStepLabel($st);
                break;
        }
    }
    if ($done) {
        db_insert('helpdesk_messages', ['ticket_id' => $ticketId, 'user_id' => $uid, 'user_name' => $uname,
            'body' => 'Makro „' . $m['title'] . '”: ' . implode(', ', $done), 'is_internal' => 1]);
        $fresh = db_one("SELECT * FROM helpdesk_tickets WHERE id=?", [$ticketId]);
        if ($fresh && $fresh['status'] !== $oldStatus) hd_notify_status_change($fresh, $oldStatus, $fresh['status'], '');
    }
    return $done;
}
