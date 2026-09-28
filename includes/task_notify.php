<?php
/**
 * task_notify.php — Powiadomienia zadań (e-mail + SMS)
 *
 * Publiczne API:
 *   task_notify_created($task_id, $by_uid)
 *   task_notify_assigned($task_id, $assigned_uid, $by_uid)
 *   task_notify_new_comment($task_id, $comment_id, $body, $author_uid)
 *   task_notify_due($task_id, $event)
 *   task_notify_confirmed($task_id, $by_uid)
 *   task_notify_rejected($task_id, $by_uid, $reason)
 *   task_notify_file_added($task_id, $file_name, $by_uid)
 *   task_notify_moved($task_id, $from_list, $to_list, $by_uid)
 *   task_notify_address($user_id, $account_email) → adres docelowy (własny adres powiadomień lub konto)
 *   task_notify_request_email_change($user_id, $email, $account_email) → wysyła link weryfikacyjny
 *   task_notify_confirm_email($raw_token) → aktywuje oczekujący adres
 *   task_notify_get_pref($user_id)  → array
 *   task_notify_save_pref($user_id, $data)
 *
 * Zasady architektury:
 *   - E-mail i SMS są niezależnymi ścieżkami; błąd/brak jednego nie blokuje drugiego.
 *   - Dedup działa per-kanał: jeden e-mail i jeden SMS na zdarzenie+odbiorca dziennie.
 *   - Każdy błąd jest logowany do task_notification_errors (z kontekstem).
 */

require_once __DIR__ . '/approval.php';
require_once __DIR__ . '/notifications.php';

_tn_schema_heal();

// ─────────────────────────────────────────────────────────────────────────────
//  PUBLICZNE FUNKCJE
// ─────────────────────────────────────────────────────────────────────────────

function task_notify_created(int $task_id, int $by_uid): void {
    $task = _tn_task($task_id);
    if (!$task) return;

    $by_name = _tn_user_name($by_uid);
    $ws_id   = (int)($task['workspace_id'] ?? 0);
    $org     = defined('ORG_NAME') ? ORG_NAME : '';

    $leaders = db_all(
        "SELECT DISTINCT u.id, u.name, u.email
         FROM users u
         LEFT JOIN task_workspace_members m ON m.user_id=u.id AND m.workspace_id=?
         WHERE u.is_active=1
           AND (m.role IN ('admin','editor') OR u.is_admin=1)
         LIMIT 20",
        [$ws_id]
    );

    foreach ($leaders as $u) {
        $uid = (int)$u['id'];
        if ($uid === $by_uid) continue;

        _tn_inapp($uid, 'Nowe zadanie: ' . $task['title'],
            $by_name . ' dodał(a) nowe zadanie.', $task_id);

        $pref = task_notify_get_pref($uid);
        if (!($pref['notify_assigned'] ?? 1)) continue;

        $subject = 'Nowe zadanie: ' . $task['title'];
        $content = '<p>Cześć <strong>' . htmlspecialchars($u['name']) . '</strong>,</p>'
            . '<p><strong>' . htmlspecialchars($by_name) . '</strong> dodał(a) nowe zadanie'
            . ' w systemie <strong>' . htmlspecialchars($org) . '</strong>.</p>'
            . _tn_task_card($task)
            . '<p style="color:#64748b;font-size:13px">Kliknij przycisk poniżej, aby otworzyć zadanie.</p>';

        _tn_email($uid, $u['email'], 'created', $task_id,
            $subject, _tn_tpl('Nowe zadanie', $subject, $content, _tn_task_url($task_id)));
    }
}

function task_notify_assigned(int $task_id, int $assigned_uid, int $by_uid): void {
    if ($assigned_uid === $by_uid) return;

    $user = db_one("SELECT id, name, email FROM users WHERE id=? AND is_active=1", [$assigned_uid]);
    if (!$user) return;

    $task = _tn_task($task_id);
    if (!$task) return;

    $by_name  = _tn_user_name($by_uid);
    $org      = defined('ORG_NAME') ? ORG_NAME : '';
    $task_url = _tn_task_url($task_id);

    _tn_inapp($assigned_uid, 'Przypisano Cię do zadania: ' . $task['title'],
        $by_name . ' przypisał(a) Cię do zadania.', $task_id);

    $pref = task_notify_get_pref($assigned_uid);

    // E-mail — niezależna ścieżka
    if (($pref['notify_assigned'] ?? 1)) {
        $subject = 'Przypisano Cię do zadania: ' . $task['title'];
        $content = '<p>Cześć <strong>' . htmlspecialchars($user['name']) . '</strong>,</p>'
            . '<p><strong>' . htmlspecialchars($by_name) . '</strong> przypisał(a) Cię do zadania'
            . ' w systemie <strong>' . htmlspecialchars($org) . '</strong>.</p>'
            . _tn_task_card($task)
            . '<p style="color:#64748b;font-size:13px">Kliknij przycisk poniżej, aby otworzyć zadanie.</p>';
        _tn_email($assigned_uid, $user['email'], 'assigned', $task_id,
            $subject, _tn_tpl('Nowe przypisanie', $subject, $content, $task_url));
    }

    // SMS — niezależna ścieżka
    if (!empty($pref['notify_sms'])) {
        _tn_sms($assigned_uid, 'sms_assigned', $task_id,
            'FEER SZO. Przypisano Cię do zadania: "' . mb_substr($task['title'], 0, 80) . '".');
    }
}

function task_notify_new_comment(int $task_id, int $comment_id, string $body, int $author_uid): void {
    $task = _tn_task($task_id);
    if (!$task) return;

    $author_name = _tn_user_name($author_uid);
    $task_url    = _tn_task_url($task_id);
    $org         = defined('ORG_NAME') ? ORG_NAME : '';
    $snippet     = mb_substr($body, 0, 300) . (mb_strlen($body) > 300 ? '…' : '');

    // Przypisani (notify_comment)
    $assignees = db_all(
        "SELECT u.id, u.name, u.email FROM task_assignments ta
         JOIN users u ON u.id=ta.user_id
         WHERE ta.task_id=? AND u.is_active=1",
        [$task_id]
    );
    foreach ($assignees as $u) {
        $uid = (int)$u['id'];
        if ($uid === $author_uid) continue;

        _tn_inapp($uid, $author_name . ' skomentował: ' . $task['title'],
            mb_substr($snippet, 0, 200), $task_id);

        $pref = task_notify_get_pref($uid);

        if (($pref['notify_comment'] ?? 0)) {
            $subject = htmlspecialchars($author_name) . ' skomentował: ' . $task['title'];
            $content = '<p>Cześć <strong>' . htmlspecialchars($u['name']) . '</strong>,</p>'
                . '<p><strong>' . htmlspecialchars($author_name) . '</strong> dodał komentarz do zadania.</p>'
                . _tn_task_card($task)
                . '<div style="background:#f8fafc;border-left:4px solid #2563eb;padding:10px 14px;margin:14px 0;border-radius:0 6px 6px 0;font-size:14px">'
                . nl2br(htmlspecialchars($snippet)) . '</div>';
            _tn_email($uid, $u['email'], 'comment', $comment_id,
                $subject, _tn_tpl('Nowy komentarz', $subject, $content, $task_url));
        }

        if (!empty($pref['notify_sms'])) {
            _tn_sms($uid, 'sms_comment', $task_id,
                'FEER SZO. ' . mb_substr($author_name, 0, 20) . ' skomentował zadanie: "' . mb_substr($task['title'], 0, 60) . '".');
        }
    }

    // @wzmianki (notify_mentioned)
    $all_users = db_all("SELECT id, name, email FROM users WHERE is_active=1");
    $mentioned = _tn_parse_mentions($body, $all_users, $author_uid);
    $mentioned_ids = array_map(fn($m) => (int)$m['id'], $mentioned);

    // Obserwujący (nieprzypisani) — notify_watched; wspomniani dostaną mail o wzmiance, nie dublujemy
    foreach (_tn_audience($task_id, $author_uid) as $u) {
        if (!$u['is_watcher'] || in_array((int)$u['id'], $mentioned_ids, true)) continue;
        $uid = (int)$u['id'];
        _tn_inapp($uid, $author_name . ' skomentował: ' . $task['title'], mb_substr($snippet, 0, 200), $task_id);
        $pref = task_notify_get_pref($uid);
        if (!_tn_audience_wants($u, $pref, 'notify_comment', 0)) continue;
        $subject = $author_name . ' skomentował obserwowane zadanie: ' . $task['title'];
        $content = '<p>Cześć <strong>' . htmlspecialchars($u['name']) . '</strong>,</p>'
            . '<p><strong>' . htmlspecialchars($author_name) . '</strong> dodał komentarz do zadania, które obserwujesz.</p>'
            . _tn_task_card($task)
            . '<div style="background:#f8fafc;border-left:4px solid #2563eb;padding:10px 14px;margin:14px 0;border-radius:0 6px 6px 0;font-size:14px">'
            . nl2br(htmlspecialchars($snippet)) . '</div>';
        _tn_email($uid, $u['email'], 'comment', $comment_id,
            $subject, _tn_tpl('Nowy komentarz', $subject, $content, $task_url));
    }

    foreach ($mentioned as $u) {
        $uid = (int)$u['id'];

        _tn_inapp($uid, $author_name . ' wspomniał Cię w: ' . $task['title'],
            mb_substr($snippet, 0, 200), $task_id);

        $pref = task_notify_get_pref($uid);

        if (($pref['notify_mentioned'] ?? 1)) {
            $subject = htmlspecialchars($author_name) . ' wspomniał Cię w zadaniu: ' . $task['title'];
            $content = '<p>Cześć <strong>' . htmlspecialchars($u['name']) . '</strong>,</p>'
                . '<p><strong>' . htmlspecialchars($author_name) . '</strong> wspomniał Cię w komentarzu.</p>'
                . _tn_task_card($task)
                . '<div style="background:#eff6ff;border-left:4px solid #2563eb;padding:10px 14px;margin:14px 0;border-radius:0 6px 6px 0;font-size:14px">'
                . nl2br(_tn_highlight_mentions(htmlspecialchars($snippet), $u['name'])) . '</div>';
            _tn_email($uid, $u['email'], 'mention', $comment_id,
                $subject, _tn_tpl('Wspomniano Cię', $subject, $content, $task_url));
        }

        if (!empty($pref['notify_sms'])) {
            _tn_sms($uid, 'sms_mention', $task_id,
                'FEER SZO. ' . mb_substr($author_name, 0, 20) . ' wspomniał Cię w zadaniu: "' . mb_substr($task['title'], 0, 60) . '".');
        }
    }
}

function task_notify_due(int $task_id, string $event): void {
    $task = _tn_task($task_id);
    if (!$task || $task['completed_at']) return;

    $task_url  = _tn_task_url($task_id);
    $due_label = $event === 'due_today' ? 'dziś' : 'jutro';
    $due_str   = $task['due_date'] ? date('d.m.Y', strtotime($task['due_date'])) : '';

    $assignees = db_all(
        "SELECT u.id, u.name, u.email FROM task_assignments ta
         JOIN users u ON u.id=ta.user_id
         WHERE ta.task_id=? AND u.is_active=1",
        [$task_id]
    );
    foreach ($assignees as $u) {
        $uid  = (int)$u['id'];
        $pref = task_notify_get_pref($uid);

        _tn_inapp($uid, 'Termin zadania ' . $due_label . ': ' . $task['title'],
            $due_str ? 'Termin: ' . $due_str : '', $task_id);

        // Preferencje mają klucze notify_due_1day / notify_due_today, zdarzenie to due_1day / due_today
        if (($pref['notify_' . $event] ?? 1)) {
            $subject = 'Termin zadania ' . ($event === 'due_today' ? 'dzisiaj' : 'jutro') . ': ' . $task['title'];
            $content = '<p>Cześć <strong>' . htmlspecialchars($u['name']) . '</strong>,</p>'
                . '<p>Termin poniższego zadania upływa <strong>' . $due_label . ($due_str ? ' (' . $due_str . ')' : '') . '</strong>.</p>'
                . _tn_task_card($task);
            _tn_email($uid, $u['email'], $event, $task_id,
                $subject, _tn_tpl('Zbliżający się termin', $subject, $content, $task_url));
        }

        if (!empty($pref['notify_sms'])) {
            $when = $event === 'due_today' ? 'DZISIAJ' : 'JUTRO';
            _tn_sms($uid, 'sms_' . $event, $task_id,
                'FEER SZO. Termin zadania ' . $when . ': "' . mb_substr($task['title'], 0, 80) . '"' . ($due_str ? ' (' . $due_str . ')' : '') . '.');
        }
    }
}

function task_notify_confirmed(int $task_id, int $by_uid): void {
    $task    = _tn_task($task_id);
    if (!$task) return;
    $by_name = _tn_user_name($by_uid);
    $task_url = _tn_task_url($task_id);

    $assignees = db_all(
        "SELECT u.id, u.name, u.email FROM task_assignments ta
         JOIN users u ON u.id=ta.user_id
         WHERE ta.task_id=? AND u.is_active=1",
        [$task_id]
    );
    foreach ($assignees as $u) {
        $uid = (int)$u['id'];
        if ($uid === $by_uid) continue;

        _tn_inapp($uid, 'Potwierdzono wykonanie: ' . $task['title'],
            $by_name . ' potwierdził(a) wykonanie zadania.', $task_id);

        $pref = task_notify_get_pref($uid);

        if (($pref['notify_confirmed'] ?? 1)) {
            $subject = 'Potwierdzono wykonanie zadania: ' . $task['title'];
            $content = '<p>Cześć <strong>' . htmlspecialchars($u['name']) . '</strong>,</p>'
                . '<p><strong>' . htmlspecialchars($by_name) . '</strong> potwierdził(a) wykonanie zadania.</p>'
                . _tn_task_card($task);
            _tn_email($uid, $u['email'], 'confirmed', $task_id,
                $subject, _tn_tpl('Wykonanie potwierdzone', $subject, $content, $task_url));
        }

        if (!empty($pref['notify_sms'])) {
            _tn_sms($uid, 'sms_confirmed', $task_id,
                'FEER SZO. ' . mb_substr($by_name, 0, 20) . ' potwierdził(a) wykonanie: "' . mb_substr($task['title'], 0, 60) . '".');
        }
    }
}

function task_notify_rejected(int $task_id, int $by_uid, string $reason): void {
    $task    = _tn_task($task_id);
    if (!$task) return;
    $by_name  = _tn_user_name($by_uid);
    $task_url = _tn_task_url($task_id);
    $reason_h = htmlspecialchars($reason, ENT_QUOTES, 'UTF-8');

    $assignees = db_all(
        "SELECT u.id, u.name, u.email FROM task_assignments ta
         JOIN users u ON u.id=ta.user_id
         WHERE ta.task_id=? AND u.is_active=1",
        [$task_id]
    );
    foreach ($assignees as $u) {
        $uid = (int)$u['id'];
        if ($uid === $by_uid) continue;

        _tn_inapp($uid, 'Odrzucono wykonanie: ' . $task['title'],
            $by_name . ' odrzucił(a). Powód: ' . mb_substr($reason, 0, 200), $task_id);

        $pref = task_notify_get_pref($uid);

        if (($pref['notify_rejected'] ?? 1)) {
            $subject = 'Odrzucono wykonanie zadania: ' . $task['title'];
            $content = '<p>Cześć <strong>' . htmlspecialchars($u['name']) . '</strong>,</p>'
                . '<p><strong>' . htmlspecialchars($by_name) . '</strong> odrzucił(a) wykonanie — konieczna poprawa.</p>'
                . _tn_task_card($task)
                . '<div style="background:#fef2f2;border-left:4px solid #dc2626;padding:10px 14px;margin:14px 0;border-radius:0 6px 6px 0;font-size:14px">'
                . '<strong>Powód:</strong><br>' . nl2br($reason_h) . '</div>';
            _tn_email($uid, $u['email'], 'rejected', $task_id,
                $subject, _tn_tpl('Wykonanie odrzucone', $subject, $content, $task_url));
        }

        if (!empty($pref['notify_sms'])) {
            _tn_sms($uid, 'sms_rejected', $task_id,
                'FEER SZO. ' . mb_substr($by_name, 0, 20) . ' odrzucił(a) zadanie: "' . mb_substr($task['title'], 0, 50) . '". Powód: ' . mb_substr($reason, 0, 60));
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────────
//  PREFERENCJE
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Adres, na który idą e-maile z modułu Zadań: własny adres powiadomień z preferencji
 * (task_notification_prefs.notify_email), a gdy pusty/niepoprawny — adres z konta.
 */
function task_notify_address(int $user_id, ?string $account_email = ''): string {
    $account_email = (string)$account_email;
    $pref   = task_notify_get_pref($user_id);
    $custom = trim((string)($pref['notify_email'] ?? ''));
    if ($custom !== '' && filter_var($custom, FILTER_VALIDATE_EMAIL)) return $custom;
    if ($account_email === '' && $user_id) {
        $account_email = (string)(db_one("SELECT email FROM users WHERE id=?", [$user_id])['email'] ?? '');
    }
    return trim($account_email);
}

/** Zdarzenia wysyłane od razu także w trybie podsumowania dziennego. */
const TASK_NOTIFY_DIGEST_BYPASS = ['due_1day', 'due_today', 'due_soon', 'notify_due_1day', 'notify_due_today'];

/** Przypomnienie ~1 h przed godziną terminu (cron/tasks_due_soon.php co 15 min). */
function task_notify_due_soon(int $task_id): void {
    $task = _tn_task($task_id);
    if (!$task || $task['completed_at'] || empty($task['due_time'])) return;
    $when = date('H:i', strtotime($task['due_date'] . ' ' . $task['due_time']));
    foreach (_tn_assignees_except($task_id, 0) as $u) {
        $uid  = (int)$u['id'];
        $pref = task_notify_get_pref($uid);
        _tn_inapp($uid, 'Termin o ' . $when . ': ' . $task['title'], 'Zostało mniej niż godzinę.', $task_id);
        if (!($pref['notify_due_soon'] ?? 1)) continue;
        $subject = 'Termin za mniej niż godzinę (' . $when . '): ' . $task['title'];
        $content = '<p>Cześć <strong>' . htmlspecialchars($u['name']) . '</strong>,</p>'
            . '<p>Termin poniższego zadania mija dziś o <strong>' . $when . '</strong>.</p>'
            . _tn_task_card($task);
        _tn_email($uid, $u['email'], 'due_soon', $task_id,
            $subject, _tn_tpl('Termin za chwilę', $subject, $content, _tn_task_url($task_id)));
    }
}

/**
 * Wysyła podsumowanie dzienne (cron/tasks_digest.php): per użytkownik jedna wiadomość
 * z zdarzeniami z kolejki, pogrupowanymi po zadaniu. Zwraca [wysłane, błędy].
 */
function task_notify_send_digests(): array {
    $sent = 0; $errs = 0;
    $users = db_all("SELECT DISTINCT q.user_id, u.name, u.email FROM task_digest_queue q
                     JOIN users u ON u.id = q.user_id AND u.is_active = 1
                     WHERE q.sent_at IS NULL");
    foreach ($users as $u) {
        $uid   = (int)$u['user_id'];
        $items = db_all(
            "SELECT q.*, t.title AS task_title FROM task_digest_queue q
             LEFT JOIN tasks t ON t.id = q.task_id
             WHERE q.user_id = ? AND q.sent_at IS NULL ORDER BY q.task_id, q.created_at",
            [$uid]
        );
        if (!$items) continue;
        $to = task_notify_address($uid, (string)$u['email']);
        $ids = array_map(fn($i) => (int)$i['id'], $items);
        $mark = function () use ($ids) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            db()->prepare("UPDATE task_digest_queue SET sent_at=? WHERE id IN ($ph)")
                ->execute(array_merge([date('Y-m-d H:i:s')], $ids));
        };
        if (!$to) { $mark(); continue; }

        $by_task = [];
        foreach ($items as $it) $by_task[(int)$it['task_id']][] = $it;
        $html_list = '';
        foreach ($by_task as $tid => $its) {
            $title = $its[0]['task_title'] ?? '';
            $html_list .= '<div style="margin:0 0 14px">'
                . ($tid ? '<a href="' . htmlspecialchars(_tn_task_url($tid)) . '" style="font-weight:700;color:#1d4ed8;text-decoration:none">'
                          . htmlspecialchars($title ?: 'Zadanie #' . $tid) . '</a>' : '<strong>Inne</strong>')
                . '<ul style="margin:4px 0 0;padding-left:18px;color:#334155;font-size:14px">';
            foreach ($its as $it) {
                $html_list .= '<li>' . htmlspecialchars($it['subject'])
                    . ' <span style="color:#94a3b8;font-size:12px">' . htmlspecialchars(substr($it['created_at'], 11, 5)) . '</span></li>';
            }
            $html_list .= '</ul></div>';
        }
        $n = count($items);
        $subject = 'Podsumowanie dnia — Zadania (' . $n . ')';
        $content = '<p>Cześć <strong>' . htmlspecialchars($u['name']) . '</strong>,</p>'
            . '<p>Oto co wydarzyło się w Twoich zadaniach od ostatniego podsumowania:</p>' . $html_list
            . '<p style="color:#64748b;font-size:13px">Podsumowania zamiast pojedynczych wiadomości włączasz i wyłączasz w ustawieniach powiadomień.</p>';
        $via = '';
        try {
            $ok = (bool)approval_send_email($to, $subject,
                _tn_tpl('Podsumowanie dnia', $subject, $content, rtrim(APP_URL, '/') . '/tasks/index.php'),
                'task_digest', $uid, 20, $via);
        } catch (\Throwable $e) { $ok = false; _tn_log_error($uid, 'digest', 'email', $e->getMessage()); }
        if ($ok) { $mark(); $sent++; } else { $errs++; }   // błąd → zostaje w kolejce na następny przebieg
    }
    try { db()->exec("DELETE FROM task_digest_queue WHERE sent_at < datetime('now','-30 days')"); } catch (\Throwable $e) {}
    return [$sent, $errs];
}

const TASK_NOTIFY_EMAIL_TOKEN_TTL = 48 * 3600;   // ważność linku weryfikacyjnego
const TASK_NOTIFY_EMAIL_RESEND_GAP = 60;         // min. odstęp między wysyłkami linku (s)

/** Zapewnia wiersz preferencji (domyślne wartości), żeby UPDATE-y weryfikacji miały na czym działać. */
function _tn_pref_row_ensure(int $user_id): void {
    db()->prepare("INSERT OR IGNORE INTO task_notification_prefs (user_id) VALUES (?)")->execute([$user_id]);
}

/**
 * Zmiana własnego adresu powiadomień. Adres NIE zaczyna działać od razu — trafia do
 * notify_email_pending, a na niego idzie jednorazowy link (48 h). Do czasu potwierdzenia
 * powiadomienia idą na dotychczasowy adres.
 *   ''              → usuwa własny adres i oczekującą zmianę (wraca adres z konta)
 *   = adres z konta → j.w. (nie ma czego weryfikować)
 *   = obecny własny → bez zmian (anuluje oczekującą zmianę)
 * Zwraca ['status' => cleared|unchanged|pending|throttled|error, 'msg' => string].
 */
function task_notify_request_email_change(int $user_id, string $email, string $account_email = ''): array {
    $email = trim($email);
    _tn_pref_row_ensure($user_id);
    $pref = task_notify_get_pref($user_id);
    $clear_pending = "notify_email_pending='', notify_email_token_hash='', notify_email_token_exp=NULL";

    if ($email === '' || strcasecmp($email, trim($account_email)) === 0) {
        db()->prepare("UPDATE task_notification_prefs SET notify_email='', {$clear_pending} WHERE user_id=?")
            ->execute([$user_id]);
        return ['status' => 'cleared', 'msg' => ''];
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 254) {
        return ['status' => 'error', 'msg' => 'Nieprawidłowy adres e-mail do powiadomień.'];
    }
    if (strcasecmp($email, (string)($pref['notify_email'] ?? '')) === 0) {
        db()->prepare("UPDATE task_notification_prefs SET {$clear_pending} WHERE user_id=?")->execute([$user_id]);
        return ['status' => 'unchanged', 'msg' => ''];
    }

    // Limit na użytkownika (nie na adres) — inaczej zmienianie adresów pozwalałoby
    // zasypywać linkami cudze skrzynki
    $sent_at = strtotime((string)($pref['notify_email_sent_at'] ?? '')) ?: 0;
    if (time() - $sent_at < TASK_NOTIFY_EMAIL_RESEND_GAP) {
        return ['status' => 'throttled',
                'msg'    => 'Link weryfikacyjny wysłano przed chwilą — sprawdź skrzynkę lub spróbuj ponownie za minutę.'];
    }

    $raw  = bin2hex(random_bytes(32));
    $link = rtrim(APP_URL, '/') . '/tasks/verify_notify_email.php?t=' . $raw;
    $now  = date('Y-m-d H:i:s');
    db()->prepare(
        "UPDATE task_notification_prefs
         SET notify_email_pending=?, notify_email_token_hash=?, notify_email_token_exp=?, notify_email_sent_at=?
         WHERE user_id=?"
    )->execute([$email, hash('sha256', $raw), date('Y-m-d H:i:s', time() + TASK_NOTIFY_EMAIL_TOKEN_TTL), $now, $user_id]);

    $name = _tn_user_name($user_id);
    $org  = defined('ORG_NAME') ? ORG_NAME : '';
    $html = _feer_email_tpl(
        '<p>Cześć <strong>' . htmlspecialchars($name) . '</strong>,</p>'
        . '<p>Ten adres został podany jako adres do powiadomień z modułu <strong>Zadania</strong>'
        . ($org !== '' ? ' w systemie <strong>' . htmlspecialchars($org) . '</strong>' : '') . '.</p>'
        . '<p>Kliknij przycisk poniżej i potwierdź zmianę. Link jest ważny 48 godzin.</p>'
        . '<p style="color:#64748b;font-size:13px">Jeśli to nie Ty — zignoruj tę wiadomość; adres nie zostanie użyty.</p>',
        'Potwierdź adres do powiadomień',
        $link,
        'Potwierdź adres →'
    );
    $ok = (bool)approval_send_email($email, 'Potwierdź adres do powiadomień — Zadania', $html, 'task_email_verify', $user_id);
    if (!$ok) {
        return ['status' => 'error', 'msg' => 'Nie udało się wysłać linku weryfikacyjnego na ' . $email . '. Spróbuj ponownie później.'];
    }
    return ['status' => 'pending',
            'msg'    => 'Wysłaliśmy link weryfikacyjny na ' . $email . '. Adres zacznie działać po potwierdzeniu.'];
}

/**
 * Szuka oczekującej zmiany po tokenie (bez aktywacji) — do wyświetlenia strony potwierdzenia.
 * Zwraca wiersz preferencji albo null (zły / wygasły / zużyty token).
 */
function task_notify_email_token_lookup(string $raw_token): ?array {
    if (!preg_match('/^[0-9a-f]{64}$/', $raw_token)) return null;
    $row = db_one(
        "SELECT p.*, u.name AS user_name FROM task_notification_prefs p
         JOIN users u ON u.id = p.user_id
         WHERE p.notify_email_token_hash = ? AND p.notify_email_pending != ''",
        [hash('sha256', $raw_token)]
    );
    if (!$row || strtotime((string)$row['notify_email_token_exp']) < time()) return null;
    return $row;
}

/** Aktywuje oczekujący adres (token jednorazowy). Zwraca aktywowany adres albo null. */
function task_notify_confirm_email(string $raw_token): ?string {
    $row = task_notify_email_token_lookup($raw_token);
    if (!$row) return null;
    $st = db()->prepare(
        "UPDATE task_notification_prefs
         SET notify_email = notify_email_pending, notify_email_pending = '',
             notify_email_token_hash = '', notify_email_token_exp = NULL, updated_at = ?
         WHERE user_id = ? AND notify_email_token_hash = ?"
    );
    $st->execute([date('Y-m-d H:i:s'), (int)$row['user_id'], hash('sha256', $raw_token)]);
    return $st->rowCount() ? (string)$row['notify_email_pending'] : null;
}

/**
 * Odbiorcy zdarzeń zadania: przypisani + obserwujący (task_watchers), bez autora zdarzenia.
 * Obserwujący, który jest też przypisany, występuje raz — jako przypisany.
 * 'is_watcher' decyduje, którą flagą preferencji sterujemy e-mailem (notify_watched).
 */
function _tn_audience(int $task_id, int $except_uid): array {
    $out = [];
    foreach (_tn_assignees_except($task_id, $except_uid) as $u) {
        $out[(int)$u['id']] = $u + ['is_watcher' => false];
    }
    if (function_exists('task_watchers')) {
        foreach (task_watchers($task_id) as $w) {
            $wid = (int)$w['id'];
            if ($wid === $except_uid || isset($out[$wid])) continue;
            $out[$wid] = $w + ['is_watcher' => true];
        }
    }
    return array_values($out);
}

/** Czy wysłać e-mail: przypisany → własna flaga zdarzenia; obserwujący → notify_watched. */
function _tn_audience_wants(array $u, array $pref, string $flag, int $flag_default = 1): bool {
    return $u['is_watcher'] ? (bool)($pref['notify_watched'] ?? 1) : (bool)($pref[$flag] ?? $flag_default);
}

/** Przypisani do zadania (aktywni) poza autorem zdarzenia. */
function _tn_assignees_except(int $task_id, int $except_uid): array {
    return array_values(array_filter(db_all(
        "SELECT u.id, u.name, u.email FROM task_assignments ta
         JOIN users u ON u.id=ta.user_id
         WHERE ta.task_id=? AND u.is_active=1",
        [$task_id]
    ), fn($u) => (int)$u['id'] !== $except_uid));
}

function task_notify_file_added(int $task_id, string $file_name, int $by_uid): void {
    $task = _tn_task($task_id);
    if (!$task) return;
    $by_name = _tn_user_name($by_uid);

    foreach (_tn_audience($task_id, $by_uid) as $u) {
        $uid = (int)$u['id'];
        _tn_inapp($uid, 'Nowy plik w zadaniu: ' . $task['title'],
            $by_name . ' dodał(a) plik „' . mb_substr($file_name, 0, 120) . '”.', $task_id);

        $pref = task_notify_get_pref($uid);
        if (!_tn_audience_wants($u, $pref, 'notify_file')) continue;

        $subject = 'Nowy plik w zadaniu: ' . $task['title'];
        $content = '<p>Cześć <strong>' . htmlspecialchars($u['name']) . '</strong>,</p>'
            . '<p><strong>' . htmlspecialchars($by_name) . '</strong> dodał(a) plik '
            . '<strong>' . htmlspecialchars($file_name) . '</strong> do zadania.</p>'
            . _tn_task_card($task);
        // ref_id = zadanie → najwyżej jeden taki e-mail na zadanie dziennie (seria uploadów ≠ seria maili)
        _tn_email($uid, $u['email'], 'file_added', $task_id,
            $subject, _tn_tpl('Nowy plik', $subject, $content, _tn_task_url($task_id)));
    }
}

function task_notify_moved(int $task_id, string $from_list, string $to_list, int $by_uid): void {
    if ($from_list === $to_list) return;
    $task = _tn_task($task_id);
    if (!$task) return;
    $by_name = _tn_user_name($by_uid);

    foreach (_tn_audience($task_id, $by_uid) as $u) {
        $uid = (int)$u['id'];
        _tn_inapp($uid, 'Zmiana statusu: ' . $task['title'],
            $by_name . ': ' . $from_list . ' → ' . $to_list, $task_id);

        $pref = task_notify_get_pref($uid);
        if (!_tn_audience_wants($u, $pref, 'notify_moved')) continue;

        $subject = 'Zadanie przeniesione do „' . $to_list . '”: ' . $task['title'];
        $content = '<p>Cześć <strong>' . htmlspecialchars($u['name']) . '</strong>,</p>'
            . '<p><strong>' . htmlspecialchars($by_name) . '</strong> przeniósł/przeniosła zadanie z kolumny '
            . '<strong>' . htmlspecialchars($from_list) . '</strong> do <strong>' . htmlspecialchars($to_list) . '</strong>.</p>'
            . _tn_task_card($task);
        _tn_email($uid, $u['email'], 'moved', $task_id,
            $subject, _tn_tpl('Zmiana statusu', $subject, $content, _tn_task_url($task_id)));
    }
}

function task_notify_get_pref(int $user_id): array {
    try {
        $row = db_one("SELECT * FROM task_notification_prefs WHERE user_id=?", [$user_id]);
        return $row ?: _tn_default_prefs();
    } catch (\Throwable $e) {
        return _tn_default_prefs();
    }
}

function task_notify_save_pref(int $user_id, array $data): void {
    $fields = ['notify_assigned', 'notify_mentioned', 'notify_comment',
               'notify_due_1day', 'notify_due_today', 'notify_sms',
               'notify_confirmed', 'notify_rejected'];
    $values = ['user_id' => $user_id, 'updated_at' => date('Y-m-d H:i:s')];
    foreach ($fields as $f) {
        $values[$f] = isset($data[$f]) ? (int)(bool)$data[$f] : 0;
    }
    // Nowsze flagi zapisywane tylko, gdy wywołujący je przysłał — starsze formularze
    // (modal w liście zadań, api/notify_prefs.php) ich nie znają i nie mogą ich zerować.
    foreach (['notify_file', 'notify_moved', 'notify_watched', 'notify_digest', 'notify_due_soon'] as $f) {
        if (array_key_exists($f, $data)) $values[$f] = (int)(bool)$data[$f];
    }
    // notify_email celowo NIE jest tu zapisywany — tylko przez task_notify_request_email_change()
    // + potwierdzenie linkiem (task_notify_confirm_email), żeby nie dało się podpiąć cudzej skrzynki.

    $cols = implode(', ', array_keys($values));
    $phs  = implode(', ', array_fill(0, count($values), '?'));
    $upd  = implode(', ', array_map(
        fn($k) => "$k=excluded.$k",
        array_filter(array_keys($values), fn($k) => $k !== 'user_id')
    ));
    try {
        db()->prepare(
            "INSERT INTO task_notification_prefs ($cols) VALUES ($phs)
             ON CONFLICT(user_id) DO UPDATE SET $upd"
        )->execute(array_values($values));
    } catch (\Throwable $e) {
        error_log('[task_notify_save_pref] ' . $e->getMessage());
    }
}

function _tn_default_prefs(): array {
    return [
        'notify_assigned'  => 1,
        'notify_mentioned' => 1,
        'notify_comment'   => 0,
        'notify_due_1day'  => 1,
        'notify_due_today' => 1,
        'notify_sms'       => 0,
        'notify_confirmed' => 1,
        'notify_rejected'  => 1,
        'notify_file'      => 1,
        'notify_moved'     => 1,
        'notify_watched'   => 1,
        'notify_digest'    => 0,
        'notify_due_soon'  => 1,
        'notify_email'     => '',
        'notify_email_pending' => '',
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
//  WEWNĘTRZNE HELPERY WYSYŁKI
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Wysyła e-mail z dedup per-kanał (raz na zdarzenie+odbiorca dziennie).
 * Dedup blokuje tylko e-mail — nie wpływa na SMS ani in-app.
 * Każda próba (sukces i błąd) jest zapisywana w task_notification_log.
 */
function _tn_email(int $user_id, ?string $to, string $event, int $ref_id,
                   string $subject, string $html): void {
    $to = task_notify_address($user_id, (string)$to);
    if (!$to) return;
    if (!_tn_dedup_ok($user_id, $event, $ref_id, 'email')) return;

    // Tryb „podsumowanie dzienne”: zdarzenia aktywności trafiają do kolejki
    // (cron/tasks_digest.php), przypomnienia o terminach idą od razu.
    if (!in_array($event, TASK_NOTIFY_DIGEST_BYPASS, true)
        && !empty(task_notify_get_pref($user_id)['notify_digest'])) {
        try {
            preg_match('#/tasks/index\.php\?task=(\d+)#', $html, $m);
            db()->prepare(
                "INSERT INTO task_digest_queue (user_id, event_type, ref_id, task_id, subject, created_at)
                 VALUES (?, ?, ?, ?, ?, ?)"
            )->execute([$user_id, $event, $ref_id, (int)($m[1] ?? 0), mb_substr($subject, 0, 300), date('Y-m-d H:i:s')]);
            _tn_dedup_mark($user_id, $event, $ref_id, 'email', 'digest');
        } catch (\Throwable $e) {
            _tn_log_error($user_id, $event, 'digest', $e->getMessage());
        }
        return;
    }

    $via = '';
    try {
        $ok = (bool) approval_send_email($to, $subject, $html, 'task', $ref_id, 20, $via);
        if ($ok) {
            _tn_dedup_mark($user_id, $event, $ref_id, 'email', $via ?: 'direct');
        } else {
            // Zawsze loguj nieudane próby — widoczne w historii jako "Błąd"
            _tn_dedup_mark($user_id, $event, $ref_id, 'email', $via ?: 'failed', true);
            _tn_log_error($user_id, $event, 'email', "approval_send_email zwróciło false [{$via}] dla {$to}");
        }
    } catch (\Throwable $e) {
        _tn_dedup_mark($user_id, $event, $ref_id, 'email', 'failed', true);
        _tn_log_error($user_id, $event, 'email', $e->getMessage());
    }
}

/**
 * Wysyła SMS z dedup per-kanał (raz na zdarzenie+odbiorca dziennie).
 * Wymaga: sms_is_enabled()=true i notify_sms=1 w preferencjach użytkownika.
 */
function _tn_sms(int $user_id, string $event, int $ref_id, string $message): void {
    if (!_tn_dedup_ok($user_id, $event, $ref_id, 'sms')) return;

    try {
        require_once __DIR__ . '/sms.php';
        if (!sms_is_enabled()) return;

        $u = db_one("SELECT phone_number FROM users WHERE id=?", [$user_id]);
        $phone = $u['phone_number'] ?? '';
        if (!$phone) return;

        sms_send($phone, $message);
        // sms_send() zwraca void; zakładamy sukces jeśli nie rzuciło wyjątku
        _tn_dedup_mark($user_id, $event, $ref_id, 'sms', 'sms');
    } catch (\Throwable $e) {
        _tn_dedup_mark($user_id, $event, $ref_id, 'sms', 'failed', true);
        _tn_log_error($user_id, $event, 'sms', $e->getMessage());
    }
}

/** Tworzy powiadomienie in-app (zawsze, niezależnie od preferencji e-mail/SMS). */
function _tn_inapp(int $user_id, string $title, string $body, int $task_id): void {
    try {
        notif_create($user_id, 'task', $title, $body, '/tasks/index.php?task=' . $task_id);
    } catch (\Throwable $e) {}
}

// ─────────────────────────────────────────────────────────────────────────────
//  DEDUP
// ─────────────────────────────────────────────────────────────────────────────

function _tn_dedup_ok(int $user_id, string $event, int $ref_id, string $channel): bool {
    try {
        // Blokuj ponowne wysłanie tylko jeśli poprzedni wpis był sukcesem (nie 'failed')
        $row = db_one(
            "SELECT 1 FROM task_notification_log
             WHERE user_id=? AND event_type=? AND ref_id=? AND channel=?
               AND delivery != 'failed'
               AND date(sent_at)=date('now','localtime')",
            [$user_id, $event, $ref_id, $channel]
        );
        return !$row;
    } catch (\Throwable $e) {
        return true;
    }
}

/**
 * @param bool $is_error  Gdy true — nie blokuje dedup dla przyszłych prób tego dnia
 */
function _tn_dedup_mark(int $user_id, string $event, int $ref_id, string $channel,
                        string $delivery = 'direct', bool $is_error = false): void {
    try {
        if ($is_error) {
            // Błędy wstawiamy zawsze (nie IGNORE), żeby były widoczne w historii
            db()->prepare(
                "INSERT INTO task_notification_log (user_id, event_type, ref_id, channel, delivery)
                 VALUES (?, ?, ?, ?, ?)"
            )->execute([$user_id, $event, $ref_id, $channel, $delivery]);
        } else {
            db()->prepare(
                "INSERT OR IGNORE INTO task_notification_log (user_id, event_type, ref_id, channel, delivery)
                 VALUES (?, ?, ?, ?, ?)"
            )->execute([$user_id, $event, $ref_id, $channel, $delivery]);
        }
    } catch (\Throwable $e) {}
}

// ─────────────────────────────────────────────────────────────────────────────
//  WEWNĘTRZNE HELPERY OGÓLNE
// ─────────────────────────────────────────────────────────────────────────────

function _tn_task(int $task_id): ?array {
    return db_one(
        "SELECT t.*, tl.name AS list_name FROM tasks t
         JOIN task_lists tl ON tl.id=t.list_id
         WHERE t.id=? AND t.deleted_at IS NULL",
        [$task_id]
    ) ?: null;
}

function _tn_user_name(int $uid): string {
    $u = db_one("SELECT name FROM users WHERE id=?", [$uid]);
    return $u['name'] ?? "Użytkownik #{$uid}";
}

function _tn_task_url(int $task_id): string {
    return rtrim(APP_URL, '/') . '/tasks/index.php?task=' . $task_id;
}

function _tn_log_error(int $user_id, string $event, string $channel, string $msg): void {
    error_log("[task_notify] uid={$user_id} event={$event} ch={$channel}: {$msg}");
    try {
        db()->prepare(
            "INSERT INTO task_notification_errors (task_id, user_id, event_type, channel, error_msg, context)
             VALUES (0, ?, ?, ?, ?, '{}')"
        )->execute([$user_id, $event, $channel, mb_substr($msg, 0, 1000)]);
    } catch (\Throwable $e) {}
}

function _tn_parse_mentions(string $body, array $all_users, int $exclude_uid): array {
    $result = [];
    $seen   = [];
    usort($all_users, fn($a, $b) => mb_strlen($b['name']) - mb_strlen($a['name']));
    foreach ($all_users as $u) {
        if ((int)$u['id'] === $exclude_uid) continue;
        if (isset($seen[(int)$u['id']])) continue;
        if (str_contains($body, '@' . $u['name'])) {
            $result[]                  = $u;
            $seen[(int)$u['id']]       = true;
        }
    }
    return $result;
}

function _tn_highlight_mentions(string $escaped_body, string $user_name): string {
    $esc = htmlspecialchars($user_name, ENT_QUOTES, 'UTF-8');
    return str_replace('@' . $esc,
        '<strong style="color:#1d4ed8">@' . $esc . '</strong>', $escaped_body);
}

function _tn_task_card(array $task): string {
    $pl = [1 => 'Niski', 2 => 'Normalny', 3 => 'Wysoki', 4 => 'Krytyczny'];
    $pc = [1 => '#64748b', 2 => '#2563eb', 3 => '#d97706', 4 => '#dc2626'];
    $p  = (int)($task['priority'] ?? 2);
    $due = $task['due_date']
        ? '<br><span style="color:#64748b;font-size:12px">Termin: <strong>'
          . date('d.m.Y', strtotime($task['due_date']))
          . (function_exists('task_normalize_due_time') && ($tm = task_normalize_due_time($task['due_time'] ?? '')) ? ', godz. ' . $tm : '')
          . '</strong></span>'
        : '';
    return '<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px 16px;margin:14px 0">'
        . '<div style="font-size:15px;font-weight:600;color:#1e293b;margin-bottom:6px">'
        . htmlspecialchars($task['title'] ?? '') . '</div>'
        . '<span style="background:' . $pc[$p] . ';color:#fff;font-size:11px;font-weight:600;'
        . 'padding:2px 8px;border-radius:20px">' . ($pl[$p] ?? '') . '</span>'
        . '<span style="color:#94a3b8;font-size:12px;margin-left:8px">· '
        . htmlspecialchars($task['list_name'] ?? '') . '</span>' . $due . '</div>';
}

function _tn_tpl(string $header_title, string $preheader, string $content_html, string $cta_url): string {
    $settings_url = htmlspecialchars(rtrim(APP_URL, '/') . '/tasks/notification_settings.php', ENT_QUOTES, 'UTF-8');
    $title_h      = htmlspecialchars($header_title, ENT_QUOTES, 'UTF-8');

    $body = '<p style="margin:0 0 4px;font-size:11px;font-weight:700;text-transform:uppercase;'
          . 'letter-spacing:.08em;color:#64748b">Zadania</p>'
          . '<p style="margin:0 0 18px;font-size:17px;font-weight:700;color:#1e293b">📋 ' . $title_h . '</p>'
          . $content_html
          . '<p style="margin:22px 0 0;font-size:12px;color:#94a3b8">'
          . '<a href="' . $settings_url . '" style="color:#94a3b8;text-decoration:underline">Zarządzaj powiadomieniami</a>'
          . '</p>';

    return _feer_email_tpl($body, $preheader, $cta_url, 'Otwórz zadanie →');
}

// ─────────────────────────────────────────────────────────────────────────────
//  SCHEMA
// ─────────────────────────────────────────────────────────────────────────────

function _tn_schema_heal(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS task_notification_prefs (
            user_id          INTEGER PRIMARY KEY,
            notify_assigned  INTEGER NOT NULL DEFAULT 1,
            notify_mentioned INTEGER NOT NULL DEFAULT 1,
            notify_comment   INTEGER NOT NULL DEFAULT 0,
            notify_due_1day  INTEGER NOT NULL DEFAULT 1,
            notify_due_today INTEGER NOT NULL DEFAULT 1,
            notify_sms       INTEGER NOT NULL DEFAULT 0,
            notify_confirmed INTEGER NOT NULL DEFAULT 1,
            notify_rejected  INTEGER NOT NULL DEFAULT 1,
            updated_at       TEXT    NOT NULL DEFAULT (datetime('now','localtime')),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )");
        db()->exec("CREATE TABLE IF NOT EXISTS task_notification_log (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id    INTEGER NOT NULL,
            event_type TEXT    NOT NULL,
            ref_id     INTEGER NOT NULL,
            channel    TEXT    NOT NULL DEFAULT 'email',
            delivery   TEXT    NOT NULL DEFAULT 'direct',
            sent_at    TEXT    NOT NULL DEFAULT (datetime('now','localtime')),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )");
        db()->exec("CREATE TABLE IF NOT EXISTS task_digest_queue (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id    INTEGER NOT NULL,
            event_type TEXT    NOT NULL,
            ref_id     INTEGER NOT NULL DEFAULT 0,
            task_id    INTEGER NOT NULL DEFAULT 0,
            subject    TEXT    NOT NULL,
            created_at TEXT    NOT NULL DEFAULT (datetime('now','localtime')),
            sent_at    TEXT
        )");
        db()->exec("CREATE INDEX IF NOT EXISTS idx_task_digest_pending ON task_digest_queue(user_id, sent_at)");
        db()->exec(
            "CREATE UNIQUE INDEX IF NOT EXISTS idx_notif_log_dedup
             ON task_notification_log(user_id, event_type, ref_id, channel, date(sent_at))"
        );
    } catch (\Throwable $e) {}

    // Kolumny dodane po wdrożeniu
    $add_cols = [
        'task_notification_prefs' => [
            'notify_sms       INTEGER NOT NULL DEFAULT 0',
            'notify_confirmed INTEGER NOT NULL DEFAULT 1',
            'notify_rejected  INTEGER NOT NULL DEFAULT 1',
            'notify_file      INTEGER NOT NULL DEFAULT 1',
            'notify_moved     INTEGER NOT NULL DEFAULT 1',
            'notify_watched   INTEGER NOT NULL DEFAULT 1',
            'notify_digest    INTEGER NOT NULL DEFAULT 0',
            'notify_due_soon  INTEGER NOT NULL DEFAULT 1',
            "notify_email     TEXT    NOT NULL DEFAULT ''",
            // Weryfikacja własnego adresu: notify_email ustawiany DOPIERO po kliknięciu linku
            "notify_email_pending    TEXT NOT NULL DEFAULT ''",
            "notify_email_token_hash TEXT NOT NULL DEFAULT ''",
            "notify_email_token_exp  TEXT",
            "notify_email_sent_at    TEXT",
        ],
        'task_notification_log' => [
            "channel  TEXT NOT NULL DEFAULT 'email'",
            "delivery TEXT NOT NULL DEFAULT 'direct'",
        ],
    ];
    foreach ($add_cols as $tbl => $defs) {
        try {
            $existing = array_column(db_all("PRAGMA table_info({$tbl})"), 'name');
            foreach ($defs as $def) {
                $col = explode(' ', trim($def))[0];
                if (!in_array($col, $existing, true)) {
                    try { db()->exec("ALTER TABLE {$tbl} ADD COLUMN {$def}"); } catch (\Throwable $e) {}
                }
            }
        } catch (\Throwable $e) {}
    }
}
