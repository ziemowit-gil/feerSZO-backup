<?php
/**
 * modules/helpdeskCollab/lib/helpdeskCollab.php — współpraca przy zgłoszeniach: obserwatorzy (CC), tagi i zapisane widoki.
 *
 *  - Obserwatorzy: dodatkowe osoby (operator SZO albo dowolny e-mail) dostają kopię publicznych wiadomości i zmian statusu.
 *    Użytkownik SZO dostaje link do panelu, osoba spoza — link do mikropanelu (jak przy udostępnianiu podglądu).
 *  - Tagi: swobodne etykiety (małe litery, 2–30 znaków) obok kategorii; filtr na liście, chipy w siatce.
 *  - Zapisane widoki: zestaw filtrów konsoli (view, status, category, priority, q, tag) pod nazwą; prywatne albo wspólne.
 */
require_once dirname(__DIR__, 3) . '/includes/helpdesk.php';

const HD_VIEW_KEYS = ['view', 'status', 'category', 'priority', 'q', 'tag'];

function hdCollabMigrate(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    helpdesk_migrate();
    db()->exec("CREATE TABLE IF NOT EXISTS helpdesk_watchers (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        ticket_id  INTEGER NOT NULL REFERENCES helpdesk_tickets(id) ON DELETE CASCADE,
        user_id    INTEGER REFERENCES users(id) ON DELETE CASCADE,
        email      TEXT NOT NULL,
        name       TEXT NOT NULL DEFAULT '',
        added_by   INTEGER,
        created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
        UNIQUE(ticket_id, email)
    )");
    db()->exec("CREATE TABLE IF NOT EXISTS helpdesk_tags (
        ticket_id INTEGER NOT NULL REFERENCES helpdesk_tickets(id) ON DELETE CASCADE,
        tag       TEXT NOT NULL,
        PRIMARY KEY (ticket_id, tag)
    )");
    db()->exec("CREATE INDEX IF NOT EXISTS idx_hd_tags_tag ON helpdesk_tags(tag)");
    db()->exec("CREATE TABLE IF NOT EXISTS helpdesk_views (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        name       TEXT NOT NULL,
        query      TEXT NOT NULL DEFAULT '',
        is_shared  INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
    )");
}

// ── Obserwatorzy ─────────────────────────────────────────────────────────────

/** Dodaje obserwatora: podaj id operatora ($userId) albo adres e-mail. Zwraca komunikat błędu albo null. */
function hdWatchAdd(int $ticketId, int $userId, string $email, string $name, int $byUid): ?string
{
    hdCollabMigrate();
    if ($userId > 0) {
        $u = db_one("SELECT id, name, email FROM users WHERE id=? AND is_active=1 AND email IS NOT NULL AND email<>''", [$userId]);
        if (!$u) return 'Wybrany użytkownik nie ma adresu e-mail.';
        $email = (string)$u['email']; $name = (string)$u['name'];
    } else {
        $email = trim($email);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return 'Podaj poprawny adres e-mail obserwatora.';
        $name = mb_substr(trim($name), 0, 100);
    }
    $email = strtolower($email);
    if (db_one("SELECT 1 FROM helpdesk_watchers WHERE ticket_id=? AND email=?", [$ticketId, $email])) return 'Ta osoba już obserwuje zgłoszenie.';
    db_insert('helpdesk_watchers', ['ticket_id' => $ticketId, 'user_id' => $userId > 0 ? $userId : null, 'email' => $email, 'name' => $name, 'added_by' => $byUid]);
    return null;
}

function hdWatchRemove(int $ticketId, int $watchId): void
{
    hdCollabMigrate();
    db()->prepare("DELETE FROM helpdesk_watchers WHERE id=? AND ticket_id=?")->execute([$watchId, $ticketId]);
}

function hdWatchList(int $ticketId): array
{
    hdCollabMigrate();
    return db_all("SELECT * FROM helpdesk_watchers WHERE ticket_id=? ORDER BY id", [$ticketId]);
}

/** Kopia powiadomienia dla obserwatorów (poza adresami z $exclude). Nigdy nie rzuca wyjątku. Zwraca liczbę wysłanych. */
function hdWatchNotify(array $ticket, string $subject, string $htmlBody, array $exclude = []): int
{
    try {
        $watchers = hdWatchList((int)$ticket['id']);
        if (!$watchers) return 0;
        require_once dirname(__DIR__, 3) . '/includes/mail_queue.php';
        $exclude = array_map('strtolower', array_filter($exclude));
        $n = 0;
        foreach ($watchers as $w) {
            if (in_array(strtolower($w['email']), $exclude, true)) continue;
            $url = $w['user_id'] ? APP_URL . '/helpdesk/view.php?id=' . (int)$ticket['id'] : hd_track_url($ticket);
            mail_queue_add($w['email'], (string)$w['name'], $subject, hd_mail_layout([
                'title' => 'Kopia dla obserwatora', 'preheader' => $ticket['number'] . ' — ' . $ticket['title'],
                'intro' => $htmlBody, 'facts' => [['Numer', $ticket['number']], ['Temat', $ticket['title']]],
                'cta' => ['label' => 'Otwórz zgłoszenie', 'url' => $url],
                'footnote' => 'Otrzymujesz tę wiadomość jako obserwator zgłoszenia.',
            ]));
            $n++;
        }
        return $n;
    } catch (\Throwable $e) {
        error_log('[helpdeskCollab] obserwatorzy: ' . $e->getMessage());
        return 0;
    }
}

// ── Tagi ─────────────────────────────────────────────────────────────────────

function hdTagNormalize(string $tag): string
{
    $tag = mb_strtolower(trim($tag));
    $tag = preg_replace('/[^\p{L}\p{N}_-]+/u', '-', $tag);
    return trim((string)$tag, '-');
}

function hdTagAdd(int $ticketId, string $input): ?string
{
    hdCollabMigrate();
    $added = 0;
    foreach (preg_split('/[,;\n]+/', $input) as $raw) {
        $tag = hdTagNormalize($raw);
        if ($tag === '') continue;
        if (mb_strlen($tag) < 2 || mb_strlen($tag) > 30) return 'Tag musi mieć 2–30 znaków.';
        if ((int)db_one("SELECT COUNT(*) AS n FROM helpdesk_tags WHERE ticket_id=?", [$ticketId])['n'] >= 15) return 'Zgłoszenie może mieć najwyżej 15 tagów.';
        $before = (int)db_one("SELECT COUNT(*) AS n FROM helpdesk_tags WHERE ticket_id=?", [$ticketId])['n'];
        db()->prepare("INSERT OR IGNORE INTO helpdesk_tags (ticket_id, tag) VALUES (?,?)")->execute([$ticketId, $tag]);
        $added += (int)db_one("SELECT COUNT(*) AS n FROM helpdesk_tags WHERE ticket_id=?", [$ticketId])['n'] - $before;
    }
    return $added ? null : 'Podaj tag (litery, cyfry, myślnik).';
}

function hdTagRemove(int $ticketId, string $tag): void
{
    hdCollabMigrate();
    db()->prepare("DELETE FROM helpdesk_tags WHERE ticket_id=? AND tag=?")->execute([$ticketId, $tag]);
}

function hdTagsOf(int $ticketId): array
{
    hdCollabMigrate();
    return array_column(db_all("SELECT tag FROM helpdesk_tags WHERE ticket_id=? ORDER BY tag", [$ticketId]), 'tag');
}

/** Tagi wielu zgłoszeń naraz: id => [tagi]. */
function hdTagsForTickets(array $ids): array
{
    hdCollabMigrate();
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids) return [];
    $out = [];
    foreach (db_all("SELECT ticket_id, tag FROM helpdesk_tags WHERE ticket_id IN (" . implode(',', $ids) . ") ORDER BY tag") as $r) $out[(int)$r['ticket_id']][] = $r['tag'];
    return $out;
}

/** Tagi używane w Helpdesku (do filtra i podpowiedzi) z liczbą zgłoszeń. */
function hdTagsInUse(): array
{
    hdCollabMigrate();
    return db_all("SELECT tag, COUNT(*) AS n FROM helpdesk_tags GROUP BY tag ORDER BY n DESC, tag LIMIT 100");
}

// ── Zapisane widoki ──────────────────────────────────────────────────────────

/** Zostawia tylko znane klucze filtrów; zwraca query string. */
function hdViewCleanQuery(string $qs): string
{
    parse_str(ltrim($qs, '?'), $in);
    $out = [];
    foreach (HD_VIEW_KEYS as $k) if (isset($in[$k]) && is_string($in[$k]) && $in[$k] !== '') $out[$k] = mb_substr($in[$k], 0, 200);
    return http_build_query($out);
}

function hdViewSave(int $uid, string $name, string $qs, bool $shared): ?string
{
    hdCollabMigrate();
    $name = mb_substr(trim($name), 0, 60);
    if ($name === '') return 'Podaj nazwę widoku.';
    $qs = hdViewCleanQuery($qs);
    if ($qs === '') return 'Ustaw najpierw jakiś filtr (status, kategorię, tag lub wyszukiwanie).';
    if ((int)db_one("SELECT COUNT(*) AS n FROM helpdesk_views WHERE user_id=?", [$uid])['n'] >= 30) return 'Możesz mieć najwyżej 30 widoków.';
    db_insert('helpdesk_views', ['user_id' => $uid, 'name' => $name, 'query' => $qs, 'is_shared' => $shared ? 1 : 0]);
    return null;
}

/** Widoki własne i wspólne (wspólne tylko do podglądu przez innych). */
function hdViewsFor(int $uid): array
{
    hdCollabMigrate();
    return db_all("SELECT v.*, u.name AS owner FROM helpdesk_views v JOIN users u ON u.id=v.user_id
                   WHERE v.user_id=? OR v.is_shared=1 ORDER BY (v.user_id=?) DESC, v.name", [$uid, $uid]);
}

function hdViewDelete(int $uid, int $id, bool $isAdmin): void
{
    hdCollabMigrate();
    db()->prepare("DELETE FROM helpdesk_views WHERE id=?" . ($isAdmin ? '' : ' AND user_id=?'))->execute($isAdmin ? [$id] : [$id, $uid]);
}
