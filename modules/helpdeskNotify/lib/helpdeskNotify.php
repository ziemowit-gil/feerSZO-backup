<?php
/**
 * modules/helpdeskNotify/lib/helpdeskNotify.php — preferencje powiadomień e-mail operatorów Helpdesku.
 *
 * Każdy operator ustawia sam (Helpdesk › Administracja › Moje powiadomienia), jakie wiadomości dostaje:
 *   new_ticket  nowe zgłoszenie (także z e-maila)                — wszystkie | wyłączone
 *   assigned    przypisano mi zgłoszenie                         — tak | nie
 *   reply       odpowiedź zgłaszającego                          — wszystkie (moje + nieprzypisane) | tylko moje | wyłączone
 *   status      zmiana statusu zgłoszenia                        — wszystkie | tylko moje | wyłączone
 *   escalation  podbicie zgłoszenia („brak reakcji”)            — wszystkie | tylko moje | wyłączone
 * plus wstrzymanie wszystkich do wybranej daty (urlop). Brak wpisu = zachowanie domyślne sprzed tej opcji (dostaje wszystko).
 * Dotyczy wyłącznie powiadomień DLA operatorów; zgłaszający i obserwatorzy mają własne reguły.
 */
const HD_NOTIFY_EVENTS = [
    'new_ticket' => ['label' => 'Nowe zgłoszenie',                 'modes' => ['all' => 'Wszystkie nowe zgłoszenia', 'off' => 'Wyłączone'], 'default' => 'all'],
    'assigned'   => ['label' => 'Przypisano mi zgłoszenie',        'modes' => ['all' => 'Tak', 'off' => 'Nie'], 'default' => 'all'],
    'reply'      => ['label' => 'Odpowiedź zgłaszającego',         'modes' => ['all' => 'Moje i nieprzypisane', 'mine' => 'Tylko moje', 'off' => 'Wyłączone'], 'default' => 'all'],
    'status'     => ['label' => 'Zmiana statusu',                  'modes' => ['all' => 'Wszystkie', 'mine' => 'Tylko moje', 'off' => 'Wyłączone'], 'default' => 'all'],
    'mention'    => ['label' => 'Wzmianka (@) w notatce',          'modes' => ['all' => 'Tak', 'off' => 'Nie'], 'default' => 'all'],
    'escalation' => ['label' => 'Podbicie zgłoszenia',             'modes' => ['all' => 'Wszystkie', 'mine' => 'Tylko moje', 'off' => 'Wyłączone'], 'default' => 'all'],
];

function hdNotifyMigrate(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    db()->exec("CREATE TABLE IF NOT EXISTS helpdesk_notify_prefs (
        user_id     INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
        prefs       TEXT NOT NULL DEFAULT '',
        muted_until TEXT,
        updated_at  TEXT NOT NULL DEFAULT (datetime('now','localtime'))
    )");
}

/** Ustawienia użytkownika scalone z domyślnymi: ['prefs' => [zdarzenie => tryb], 'muted_until' => 'Y-m-d'|null]. */
function hdNotifyPrefs(int $uid): array
{
    hdNotifyMigrate();
    $row = db_one("SELECT prefs, muted_until FROM helpdesk_notify_prefs WHERE user_id=?", [$uid]);
    $saved = $row && $row['prefs'] !== '' ? (json_decode($row['prefs'], true) ?: []) : [];
    $out = [];
    foreach (HD_NOTIFY_EVENTS as $k => $e) $out[$k] = isset($e['modes'][$saved[$k] ?? '']) ? $saved[$k] : $e['default'];
    return ['prefs' => $out, 'muted_until' => $row['muted_until'] ?? null];
}

/** Zapis z formularza. Zwraca komunikat błędu albo null. */
function hdNotifySave(int $uid, array $in): ?string
{
    hdNotifyMigrate();
    $prefs = [];
    foreach (HD_NOTIFY_EVENTS as $k => $e) $prefs[$k] = isset($e['modes'][$in[$k] ?? '']) ? (string)$in[$k] : $e['default'];
    $muted = trim((string)($in['muted_until'] ?? ''));
    if ($muted !== '') {
        $ts = strtotime($muted);
        if (!$ts || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $muted)) return 'Podaj datę wstrzymania w formacie RRRR-MM-DD.';
        if ($ts > strtotime('+1 year')) return 'Powiadomienia można wstrzymać najwyżej na rok.';
    }
    db()->prepare("INSERT INTO helpdesk_notify_prefs (user_id, prefs, muted_until, updated_at) VALUES (?,?,?,?)
                   ON CONFLICT(user_id) DO UPDATE SET prefs=excluded.prefs, muted_until=excluded.muted_until, updated_at=excluded.updated_at")
        ->execute([$uid, json_encode($prefs), $muted !== '' ? $muted : null, date('Y-m-d H:i:s')]);
    return null;
}

/**
 * Czy operator o danym adresie ma dostać powiadomienie $event o zgłoszeniu $ticket (tablica z kluczem assigned_to).
 * Adres spoza kont SZO (np. skrzynka zespołu) — zawsze tak. Nigdy nie rzuca wyjątku (błąd = tak, nie gubimy powiadomień).
 */
function hdNotifyAllows(string $email, string $event, array $ticket = []): bool
{
    try {
        if (!isset(HD_NOTIFY_EVENTS[$event])) return true;
        $u = db_one("SELECT id FROM users WHERE lower(email)=? AND is_active=1", [strtolower(trim($email))]);
        if (!$u) return true;
        $p = hdNotifyPrefs((int)$u['id']);
        if ($p['muted_until'] && date('Y-m-d') <= $p['muted_until']) return false;
        $mode = $p['prefs'][$event];
        if ($mode === 'off') return false;
        if ($mode === 'mine') return !empty($ticket['assigned_to']) && (int)$ticket['assigned_to'] === (int)$u['id'];
        return true;
    } catch (\Throwable $e) {
        return true;
    }
}
