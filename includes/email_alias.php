<?php
/**
 * includes/email_alias.php — Wnioski wolontariuszy o alias e-mail M365.
 *
 * Wolontariusz składa wniosek o alias (np. kasia@feer.org.pl) z panelu.
 * Wniosek wymaga zatwierdzenia (operator helpdesku / admin), a jego ustawienie
 * odbywa się przez Graph API (dopisanie do proxyAddresses). Każdy wniosek
 * otwiera zgłoszenie w helpdesku z prefiksem CHG (change request).
 */

require_once __DIR__ . '/helpdesk.php';

const EALIAS_STATUSES = [
    'oczekuje'    => ['label' => 'Oczekuje',    'class' => 'warning',   'text' => 'dark',  'icon' => 'bi-hourglass-split'],
    'zatwierdzony'=> ['label' => 'Zatwierdzony','class' => 'success',   'text' => 'white', 'icon' => 'bi-check-circle-fill'],
    'odrzucony'   => ['label' => 'Odrzucony',   'class' => 'secondary', 'text' => 'white', 'icon' => 'bi-x-circle-fill'],
    'błąd'        => ['label' => 'Błąd API',    'class' => 'danger',    'text' => 'white', 'icon' => 'bi-exclamation-octagon-fill'],
];

function email_alias_migrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo  = db();

    $pdo->exec("CREATE TABLE IF NOT EXISTS email_alias_requests (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id         INTEGER REFERENCES users(id) ON DELETE SET NULL,
        requester_name  TEXT    NOT NULL DEFAULT '',
        requester_email TEXT    NOT NULL DEFAULT '',
        m365_user_id    TEXT    NOT NULL DEFAULT '',
        m365_login      TEXT    NOT NULL DEFAULT '',
        requested_alias TEXT    NOT NULL,
        status          TEXT    NOT NULL DEFAULT 'oczekuje',
        ticket_id       INTEGER REFERENCES helpdesk_tickets(id) ON DELETE SET NULL,
        decided_by      INTEGER REFERENCES users(id) ON DELETE SET NULL,
        decided_at      DATETIME,
        decision_note   TEXT,
        applied_at      DATETIME,
        graph_error     TEXT,
        created_at      DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ealias_status ON email_alias_requests(status)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ealias_user   ON email_alias_requests(user_id)");
}

function ealias_status_badge(string $status): string {
    $s = EALIAS_STATUSES[$status] ?? ['label' => $status, 'class' => 'secondary', 'text' => 'white', 'icon' => 'bi-question-circle'];
    return '<span class="badge bg-' . $s['class'] . ' text-' . $s['text'] . '">'
         . '<i class="bi ' . $s['icon'] . ' me-1"></i>' . h($s['label']) . '</span>';
}

/** Zwraca bieżący wniosek wolontariusza w toku (oczekuje/błąd) lub null. */
function ealias_user_pending(int $uid): ?array {
    return db_one(
        "SELECT * FROM email_alias_requests
         WHERE user_id=? AND status IN ('oczekuje','błąd')
         ORDER BY id DESC LIMIT 1",
        [$uid]
    ) ?: null;
}

/**
 * Tworzy zgłoszenie helpdesk (prefiks CHG) powiązane z wnioskiem o alias.
 * Zwraca ticket_id.
 *
 * $req wymaga kluczy: user_id, requester_name, requester_email, m365_login, requested_alias
 */
function ealias_create_ticket(array $req): int {
    email_alias_migrate();
    $number = hd_next_number('CHG');

    $alias = $req['requested_alias'];
    $login = $req['m365_login'] ?: '(brak)';
    $desc  = "Wniosek o alias e-mail złożony z panelu wolontariusza.\n\n"
           . "Konto (login/UPN): {$login}\n"
           . "Żądany alias: {$alias}\n\n"
           . "Alias zostanie dopisany do proxyAddresses po zatwierdzeniu przez operatora.";

    $ticket_id = db_insert('helpdesk_tickets', [
        'number'          => $number,
        'title'           => 'Wniosek o alias e-mail: ' . $alias,
        'description'     => $desc,
        'category'        => 'it_m365',
        'priority'        => 'normalny',
        'status'          => 'nowe',
        'requester_id'    => (int)($req['user_id'] ?? 0) ?: null,
        'requester_name'  => $req['requester_name']  ?? '',
        'requester_email' => $req['requester_email'] ?? '',
        'source'          => 'alias_request',
    ]);

    db_insert('helpdesk_messages', [
        'ticket_id'   => $ticket_id,
        'user_id'     => (int)($req['user_id'] ?? 0) ?: null,
        'user_name'   => $req['requester_name'] ?? '',
        'body'        => $desc,
        'is_internal' => 0,
    ]);

    if (function_exists('hd_redmine_sync_ticket')) hd_redmine_sync_ticket($ticket_id); // integracja Redmine

    return $ticket_id;
}
