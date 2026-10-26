<?php
/**
 * includes/crm_mail_followers.php — obserwatorzy rozmów w Skrzynce CRM (wzór: moduł Followers z FreeScout).
 *
 * Osoba może zapisać siebie lub inne osoby jako OBSERWUJĄCYCH rozmowę (wątek; wiadomość bez wątku = sama wiadomość). Obserwujący dostają powiadomienie
 * (dzwonek w systemie + e-mail, e-mail z limitem na godzinę) gdy: nadawca odpisał, ktoś z zespołu odpisał, zmienił się opiekun, rozmowa trafiła do innej
 * kolejki albo zmienił się jej status (załatwiona / spam / przywrócona). Autor zmiany nie dostaje powiadomienia o własnej akcji.
 *
 * Zdarzenia „nowa wiadomość” wykrywa przebieg crm_fol_sweep (cron co 5 min) — znacznik last_seen_id na obserwującego, więc skaner Poczty nie wymaga zmian.
 * Zmiany opiekuna/kolejki/statusu zgłaszają bezpośrednio crm_mailbox_assign / crm_mq_move / crm_mailbox_set_status (crm_fol_on_change).
 * Dostęp: obserwować (i być dodanym) może tylko ktoś, kto widzi skrzynkę rozmowy — powiadomienie zawiera temat, więc nie może wyciekać poza ACL skrzynek.
 */

const CRM_FOL_MAIL_PER_HOUR = 6;

function crm_fol_migrate(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $pk = (defined('DB_TYPE') && DB_TYPE !== 'sqlite') ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS crm_comm_followers (
            id           {$pk},
            conv_key     VARCHAR(190) NOT NULL,
            user_id      INTEGER NOT NULL,
            added_by     INTEGER,
            last_seen_id INTEGER NOT NULL DEFAULT 0,
            created_at   VARCHAR(19)
        )");
        db()->exec("CREATE UNIQUE INDEX IF NOT EXISTS ux_crm_followers ON crm_comm_followers(conv_key, user_id)");
        db()->exec("CREATE INDEX IF NOT EXISTS idx_crm_followers_user ON crm_comm_followers(user_id)");
        db()->exec("CREATE TABLE IF NOT EXISTS crm_fol_mail_log (
            id         {$pk},
            user_id    INTEGER NOT NULL,
            created_at VARCHAR(19) NOT NULL
        )");
        db()->exec("CREATE INDEX IF NOT EXISTS idx_crm_fol_mail_user ON crm_fol_mail_log(user_id, created_at)");
    } catch (\Throwable $e) {
        error_log('[crm_fol_migrate] ' . $e->getMessage());
    }
}

/** Klucz rozmowy: thread_key albo m{id} dla wiadomości bez wątku. */
function crm_fol_key(array $c): string
{
    $tk = trim((string)($c['thread_key'] ?? ''));
    return $tk !== '' ? mb_substr($tk, 0, 190) : 'm' . (int)$c['id'];
}

function crm_fol_key_of(int $comm_id): ?string
{
    $c = db_one("SELECT id, thread_key FROM crm_communications WHERE id = ?", [$comm_id]);
    return $c ? crm_fol_key($c) : null;
}

/** Najwyższe id wiadomości rozmowy. */
function crm_fol_max_id(string $key): int
{
    if (preg_match('/^m(\d+)$/', $key, $m) && !db_one("SELECT 1 AS x FROM crm_communications WHERE thread_key = ?", [$key])) return (int)$m[1];
    return (int)(db_one("SELECT MAX(id) AS n FROM crm_communications WHERE thread_key = ?", [$key])['n'] ?? 0);
}

/** Czy użytkownik widzi skrzynkę (admin zawsze; reszta wg ACL / skrzynka osobista). */
function crm_fol_user_can_see(int $user_id, int $mailbox_id): bool
{
    if ($user_id <= 0) return false;
    $u = db_one("SELECT role, is_active FROM users WHERE id = ?", [$user_id]);
    if (!$u || !(int)$u['is_active']) return false;
    if (($u['role'] ?? '') === 'admin') return true;
    if ($mailbox_id <= 0) return false;   // wiadomość bez skrzynki: tylko administrator
    require_once __DIR__ . '/poczta_acl.php';
    return in_array($mailbox_id, poczta_allowed_mailbox_ids($user_id), true);
}

/** Dodaje obserwującego (znacznik = ostatnia wiadomość rozmowy: bez powiadomień o starej poczcie). @return bool true = dodano */
function crm_fol_add(int $comm_id, int $user_id, ?int $by = null): bool
{
    crm_fol_migrate();
    $c = db_one("SELECT id, thread_key, mailbox_id FROM crm_communications WHERE id = ?", [$comm_id]);
    if (!$c || !crm_fol_user_can_see($user_id, (int)($c['mailbox_id'] ?? 0))) return false;
    $key = crm_fol_key($c);
    if (db_one("SELECT 1 AS x FROM crm_comm_followers WHERE conv_key = ? AND user_id = ?", [$key, $user_id])) return false;
    db_insert('crm_comm_followers', ['conv_key' => $key, 'user_id' => $user_id, 'added_by' => $by ?? ((int)(current_user()['id'] ?? 0) ?: null),
                                     'last_seen_id' => crm_fol_max_id($key), 'created_at' => date('Y-m-d H:i:s')]);
    return true;
}

function crm_fol_remove(int $comm_id, int $user_id): bool
{
    crm_fol_migrate();
    $key = crm_fol_key_of($comm_id);
    if ($key === null) return false;
    $st = db()->prepare("DELETE FROM crm_comm_followers WHERE conv_key = ? AND user_id = ?");
    $st->execute([$key, $user_id]);
    return $st->rowCount() > 0;
}

/** Obserwujący rozmowę: [['id','name','email'], …]. */
function crm_fol_list(int $comm_id): array
{
    crm_fol_migrate();
    $key = crm_fol_key_of($comm_id);
    if ($key === null) return [];
    try {
        return db_all("SELECT u.id, u.name, u.email FROM crm_comm_followers f JOIN users u ON u.id = f.user_id WHERE f.conv_key = ? ORDER BY u.name", [$key]);
    } catch (\Throwable $e) { return []; }
}

/** [id wiadomości => true] dla wierszy listy, których rozmowę obserwuje użytkownik. */
function crm_fol_flags_for_rows(array $rows, int $user_id): array
{
    crm_fol_migrate();
    if (!$rows || $user_id <= 0) return [];
    try {
        $mine = array_flip(array_column(db_all("SELECT conv_key FROM crm_comm_followers WHERE user_id = ?", [$user_id]), 'conv_key'));
    } catch (\Throwable $e) { return []; }
    $out = [];
    if ($mine) foreach ($rows as $r) if (isset($mine[crm_fol_key($r)])) $out[(int)$r['id']] = true;
    return $out;
}

// ── Powiadomienia ───────────────────────────────────────────────────────────

/**
 * Powiadamia obserwujących rozmowy (poza autorem zmiany): dzwonek + e-mail (limit CRM_FOL_MAIL_PER_HOUR na osobę na godzinę).
 * @return int liczba powiadomionych osób
 */
function crm_fol_notify(string $key, string $title, string $body, int $comm_id, int $actor = 0, ?int $onlyUser = null): int
{
    crm_fol_migrate();
    if (!function_exists('notif_create')) require_once __DIR__ . '/notifications.php';
    $n = 0;
    try {
        $c = db_one("SELECT mailbox_id FROM crm_communications WHERE id = ?", [$comm_id]);
        $fs = db_all("SELECT f.user_id, u.email, u.name FROM crm_comm_followers f JOIN users u ON u.id = f.user_id AND u.is_active = 1 WHERE f.conv_key = ?", [$key]);
    } catch (\Throwable $e) { return 0; }
    $url = rtrim(defined('APP_URL') ? APP_URL : '', '/') . '/crm/inbox.php?view=all&msg=' . $comm_id;
    foreach ($fs as $f) {
        $uid = (int)$f['user_id'];
        if ($uid === $actor || ($onlyUser !== null && $uid !== $onlyUser)) continue;
        if (!crm_fol_user_can_see($uid, (int)($c['mailbox_id'] ?? 0))) continue;   // utracony dostęp do skrzynki — bez treści
        try { notif_create($uid, 'crm_follow', $title, $body, $url); } catch (\Throwable $e) { error_log('[crm_fol_notify] dzwonek: ' . $e->getMessage()); }
        $email = trim((string)$f['email']);
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $recent = (int)(db_one("SELECT COUNT(*) AS n FROM crm_fol_mail_log WHERE user_id = ? AND created_at >= ?", [$uid, date('Y-m-d H:i:s', strtotime('-1 hour'))])['n'] ?? 0);
            if ($recent < CRM_FOL_MAIL_PER_HOUR) {
                require_once __DIR__ . '/crm_mail_autoreply.php';   // crm_ar_send: kolejka maili / zaczep testowy
                $html = '<div style="font-family:Segoe UI,Arial,sans-serif;font-size:14px;line-height:1.5"><p><b>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</b></p><p>'
                      . nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8'), false) . '</p><p><a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">Otwórz rozmowę w Skrzynce CRM</a></p>'
                      . '<p style="color:#666;font-size:12px">Dostajesz to, bo obserwujesz tę rozmowę. Możesz przestać ją obserwować w Skrzynce CRM.</p></div>';
                $ok = crm_ar_send(['to' => $email, 'to_name' => (string)$f['name'], 'subject' => mb_substr($title, 0, 150), 'html' => $html, 'text' => $title . "\n\n" . $body . "\n\n" . $url,
                                   'context_type' => 'crm_follow', 'context_id' => $comm_id, 'from' => '', 'headers' => ['Auto-Submitted' => 'auto-generated', 'X-Auto-Response-Suppress' => 'All']]);
                if ($ok) db_insert('crm_fol_mail_log', ['user_id' => $uid, 'created_at' => date('Y-m-d H:i:s')]);
            }
        }
        $n++;
    }
    return $n;
}

/** Zdarzenie zmiany (opiekun / kolejka / status) zgłaszane przez funkcje Skrzynki — nigdy nie przerywa głównej operacji. */
function crm_fol_on_change(int $comm_id, string $what): void
{
    try {
        crm_fol_migrate();
        $c = db_one("SELECT id, thread_key, subject FROM crm_communications WHERE id = ?", [$comm_id]);
        if (!$c) return;
        $key = crm_fol_key($c);
        if (!db_one("SELECT 1 AS x FROM crm_comm_followers WHERE conv_key = ? LIMIT 1", [$key])) return;
        $me = current_user();
        $by = trim((string)($me['name'] ?? '')) ?: 'system';
        crm_fol_notify($key, 'Rozmowa: ' . mb_substr((string)($c['subject'] ?: '(bez tematu)'), 0, 80), $what . ' (' . $by . ')', $comm_id, (int)($me['id'] ?? 0));
    } catch (\Throwable $e) { error_log('[crm_fol_on_change] ' . $e->getMessage()); }
}

/**
 * Przebieg: nowe wiadomości w rozmowach obserwowanych (id > last_seen_id) → jedno powiadomienie na obserwującego i rozmowę;
 * odpowiedzi samego obserwującego (wychodzące, sent_by = on) pomijane. @return array{notified:int}
 */
function crm_fol_sweep(): array
{
    crm_fol_migrate();
    $out = ['notified' => 0];
    try { $rows = db_all("SELECT * FROM crm_comm_followers ORDER BY id"); } catch (\Throwable $e) { return $out; }
    $maxCache = [];
    foreach ($rows as $f) {
        $key = (string)$f['conv_key']; $uid = (int)$f['user_id'];
        $max = $maxCache[$key] ??= crm_fol_max_id($key);
        if ($max <= (int)$f['last_seen_id']) continue;
        try {
            $new = preg_match('/^m(\d+)$/', $key, $m) && !db_one("SELECT 1 AS x FROM crm_communications WHERE thread_key = ?", [$key])
                ? db_all("SELECT id, direction, sent_by, subject, from_name FROM crm_communications WHERE id = ? AND id > ?", [(int)$m[1], (int)$f['last_seen_id']])
                : db_all("SELECT id, direction, sent_by, subject, from_name FROM crm_communications WHERE thread_key = ? AND id > ? ORDER BY id", [$key, (int)$f['last_seen_id']]);
            db()->prepare("UPDATE crm_comm_followers SET last_seen_id = ? WHERE id = ?")->execute([$max, (int)$f['id']]);
            $in = 0; $outc = 0; $last = null;
            foreach ($new as $c) {
                if ($c['direction'] === 'in') { $in++; $last = $c; }
                elseif ((int)($c['sent_by'] ?? 0) !== $uid) { $outc++; $last = $c; }
            }
            if (!$last) continue;
            $bits = [];
            if ($in) $bits[] = $in . ' od nadawcy' . ($last['from_name'] && $last['direction'] === 'in' ? ' (' . $last['from_name'] . ')' : '');
            if ($outc) $bits[] = $outc . ' od zespołu';
            $out['notified'] += crm_fol_notify($key, 'Nowa wiadomość: ' . mb_substr((string)($last['subject'] ?: '(bez tematu)'), 0, 80), 'W obserwowanej rozmowie: ' . implode(', ', $bits) . '.', (int)$last['id'], 0, $uid);
        } catch (\Throwable $e) { error_log('[crm_fol_sweep] ' . $e->getMessage()); }
    }
    return $out;
}
