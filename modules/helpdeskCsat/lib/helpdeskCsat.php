<?php
/**
 * modules/helpdeskCsat/lib/helpdeskCsat.php — ankieta zadowolenia (CSAT) po rozwiązaniu/zamknięciu zgłoszenia.
 *
 * Włączana w Helpdesk › Ustawienia (hd_csat_enabled, domyślnie WYŁĄCZONA — wysyła e-maile do zgłaszających).
 * Jedna ankieta na zgłoszenie (helpdesk_csat.ticket_id UNIQUE); link z tokenem otwiera stronę helpdesk/csat.php, a ocena
 * 1–5 (+ komentarz) zapisuje się dopiero po wysłaniu formularza (skanery poczty otwierające linki nie głosują).
 */
require_once dirname(__DIR__, 3) . '/includes/helpdesk.php';

function hdCsatMigrate(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    helpdesk_migrate();
    db()->exec("CREATE TABLE IF NOT EXISTS helpdesk_csat (
        ticket_id   INTEGER PRIMARY KEY REFERENCES helpdesk_tickets(id) ON DELETE CASCADE,
        token       TEXT NOT NULL UNIQUE,
        rating      INTEGER,
        comment     TEXT NOT NULL DEFAULT '',
        sent_at     TEXT NOT NULL DEFAULT (datetime('now','localtime')),
        answered_at TEXT
    )");
}

function hdCsatEnabled(): bool
{
    return org_setting('hd_csat_enabled') === '1';
}

/** Po zmianie statusu: wyślij ankietę raz, gdy zgłoszenie rozwiązane/zamknięte (i funkcja włączona). Zwraca true, gdy wysłano. */
function hdCsatMaybeSend(array $ticket, string $newStatus): bool
{
    if (!hdCsatEnabled() || !in_array($newStatus, ['rozwiązane', 'zamknięte'], true)) return false;
    $email = trim((string)($ticket['requester_email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !empty($ticket['merged_into'])) return false;
    hdCsatMigrate();
    if (db_one("SELECT 1 FROM helpdesk_csat WHERE ticket_id=?", [(int)$ticket['id']])) return false;
    $token = bin2hex(random_bytes(16));
    db()->prepare("INSERT INTO helpdesk_csat (ticket_id, token, sent_at) VALUES (?,?,?)")->execute([(int)$ticket['id'], $token, date('Y-m-d H:i:s')]);
    try {
        require_once dirname(__DIR__, 3) . '/includes/mail_queue.php';
        $url = APP_URL . '/helpdesk/csat.php?t=' . $token;
        $html = hd_mail_layout([
            'title' => 'Jak oceniasz obsługę zgłoszenia?', 'preheader' => 'Oceń obsługę zgłoszenia ' . $ticket['number'] . ' — zajmie to kilka sekund.',
            'greeting' => 'Dzień dobry' . (!empty($ticket['requester_name']) ? ', ' . $ticket['requester_name'] : '') . ',',
            'intro' => '<p style="margin:0">Twoje zgłoszenie zostało zakończone. Powiedz nam, jak je załatwiliśmy — to pomaga nam poprawiać obsługę.</p>',
            'facts' => [['Numer', $ticket['number']], ['Temat', $ticket['title']]],
            'cta' => ['label' => 'Oceń obsługę', 'url' => $url],
        ]);
        mail_queue_add($email, (string)($ticket['requester_name'] ?? ''), '[' . $ticket['number'] . '] Jak oceniasz obsługę zgłoszenia?', $html);
    } catch (\Throwable $e) {
        error_log('[helpdeskCsat] ' . $e->getMessage());
    }
    return true;
}

function hdCsatByToken(string $token): ?array
{
    hdCsatMigrate();
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) return null;
    return db_one("SELECT c.*, t.number, t.title FROM helpdesk_csat c JOIN helpdesk_tickets t ON t.id=c.ticket_id WHERE c.token=?", [$token]) ?: null;
}

/** Zapis oceny (jednorazowy). Zwraca komunikat błędu albo null. */
function hdCsatAnswer(string $token, int $rating, string $comment): ?string
{
    $c = hdCsatByToken($token);
    if (!$c) return 'Nieprawidłowy lub wygasły link.';
    if ($c['answered_at']) return 'Ankieta została już wypełniona — dziękujemy!';
    if ($rating < 1 || $rating > 5) return 'Wybierz ocenę od 1 do 5.';
    db()->prepare("UPDATE helpdesk_csat SET rating=?, comment=?, answered_at=? WHERE token=? AND answered_at IS NULL")
        ->execute([$rating, mb_substr(trim($comment), 0, 1000), date('Y-m-d H:i:s'), $token]);
    $t = db_one("SELECT id FROM helpdesk_tickets WHERE number=?", [$c['number']]);
    if ($t) db_insert('helpdesk_messages', ['ticket_id' => (int)$t['id'], 'user_id' => null, 'user_name' => 'Ankieta zadowolenia',
        'body' => 'Ocena obsługi: ' . $rating . '/5' . (trim($comment) !== '' ? "\n" . mb_substr(trim($comment), 0, 1000) : ''), 'is_internal' => 1]);
    return null;
}

/** Ocena zgłoszenia do karty (albo null). */
function hdCsatFor(int $ticketId): ?array
{
    hdCsatMigrate();
    $r = db_one("SELECT rating, comment, answered_at, sent_at FROM helpdesk_csat WHERE ticket_id=?", [$ticketId]);
    return $r ?: null;
}

/** Średnia i liczba ocen (opcjonalnie od daty) — do pulpitu. */
function hdCsatSummary(?string $since = null): array
{
    hdCsatMigrate();
    $r = db_one("SELECT COUNT(*) AS n, AVG(rating) AS avg, SUM(rating>=4) AS good FROM helpdesk_csat WHERE rating IS NOT NULL"
        . ($since ? " AND answered_at >= ?" : ''), $since ? [$since] : []);
    $sent = (int)(db_one("SELECT COUNT(*) AS n FROM helpdesk_csat" . ($since ? " WHERE sent_at >= ?" : ''), $since ? [$since] : [])['n'] ?? 0);
    return ['answered' => (int)($r['n'] ?? 0), 'sent' => $sent, 'avg' => $r['avg'] !== null ? round((float)$r['avg'], 2) : null,
            'good_pct' => (int)($r['n'] ?? 0) ? (int)round(100 * (int)$r['good'] / (int)$r['n']) : null];
}
