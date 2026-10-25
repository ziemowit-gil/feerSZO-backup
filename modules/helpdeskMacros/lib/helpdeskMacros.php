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

/** Czytelny opis akcji makra (do podpowiedzi na przycisku i do notatki). */
function hdMacroActionLabels(array $m): array
{
    $out = [];
    if (($m['act_status'] ?? '') !== '' && isset(HD_STATUSES[$m['act_status']])) $out[] = 'status → ' . HD_STATUSES[$m['act_status']]['label'];
    if (($m['act_priority'] ?? '') !== '' && isset(HD_PRIORITIES[$m['act_priority']])) $out[] = 'priorytet → ' . HD_PRIORITIES[$m['act_priority']]['label'];
    if (!empty($m['act_assign_me'])) $out[] = 'przypisz do mnie';
    if (($m['act_tag'] ?? '') !== '') $out[] = 'tag: ' . $m['act_tag'];
    return $out;
}

function hdMacroHasActions(array $m): bool
{
    return (bool)hdMacroActionLabels($m);
}

/** Walidacja pól akcji z formularza administracyjnego → tablica do zapisu. */
function hdMacroActionFields(array $in): array
{
    $st = (string)($in['act_status'] ?? '');
    $pr = (string)($in['act_priority'] ?? '');
    return [
        'act_status' => isset(HD_STATUSES[$st]) ? $st : '',
        'act_priority' => isset(HD_PRIORITIES[$pr]) ? $pr : '',
        'act_assign_me' => !empty($in['act_assign_me']) ? 1 : 0,
        'act_tag' => mb_substr(hdTagNormalize((string)($in['act_tag'] ?? '')), 0, 30),
        'show_button' => !empty($in['show_button']) ? 1 : 0,
    ];
}

/**
 * Wykonuje akcje makra na zgłoszeniu (operator $uid). Zwraca listę wykonanych zmian (pusta = nic nie zmieniono).
 * Zmiana statusu wysyła powiadomienia jak zwykła zmiana; całość zapisuje się notatką wewnętrzną.
 */
function hdMacroApply(int $ticketId, int $macroId, int $uid, string $uname): array
{
    hdMacrosMigrate();
    $m = db_one("SELECT * FROM helpdesk_macros WHERE id=? AND is_active=1", [$macroId]);
    $t = db_one("SELECT * FROM helpdesk_tickets WHERE id=?", [$ticketId]);
    if (!$m || !$t) return [];
    $done = []; $upd = []; $now = date('Y-m-d H:i:s');
    $oldStatus = $t['status']; $newStatus = $oldStatus;
    if ($m['act_status'] !== '' && isset(HD_STATUSES[$m['act_status']]) && $m['act_status'] !== $oldStatus) {
        $newStatus = $m['act_status']; $upd['status'] = $newStatus;
        if ($newStatus === 'rozwiązane') $upd['resolved_at'] = $now;
        if ($newStatus === 'zamknięte') $upd['closed_at'] = $now;
        $done[] = 'status → ' . HD_STATUSES[$newStatus]['label'];
    }
    if ($m['act_priority'] !== '' && isset(HD_PRIORITIES[$m['act_priority']]) && $m['act_priority'] !== $t['priority']) {
        $upd['priority'] = $m['act_priority']; $done[] = 'priorytet → ' . HD_PRIORITIES[$m['act_priority']]['label'];
    }
    if (!empty($m['act_assign_me']) && (int)$t['assigned_to'] !== $uid) {
        $upd['assigned_to'] = $uid; $done[] = 'przypisano do ' . $uname;
        if ($newStatus === 'nowe') { $newStatus = 'otwarte'; $upd['status'] = 'otwarte'; }
    }
    if ($upd) { $upd['updated_at'] = $now; db_update('helpdesk_tickets', $upd, $ticketId); }
    if ($m['act_tag'] !== '' && !in_array($m['act_tag'], hdTagsOf($ticketId), true)) {
        hdTagAdd($ticketId, $m['act_tag']); $done[] = 'tag: ' . $m['act_tag'];
    }
    if ($done) {
        db_insert('helpdesk_messages', ['ticket_id' => $ticketId, 'user_id' => $uid, 'user_name' => $uname,
            'body' => 'Makro „' . $m['title'] . '”: ' . implode(', ', $done), 'is_internal' => 1]);
        if ($newStatus !== $oldStatus) {
            $fresh = db_one("SELECT * FROM helpdesk_tickets WHERE id=?", [$ticketId]);
            if ($fresh) hd_notify_status_change($fresh, $oldStatus, $newStatus, '');
        }
    }
    return $done;
}
