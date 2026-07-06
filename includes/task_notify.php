<?php
/**
 * Moduł powiadomień email — Zadania
 * Wymaga: db.php, functions.php, approval.php (approval_send_email)
 *
 * Publiczne API:
 *   task_notify_assigned(task_id, assigned_uid, by_uid)
 *   task_notify_new_comment(task_id, comment_id, body, author_uid)
 *   task_notify_confirmed(task_id, by_uid)
 *   task_notify_rejected(task_id, by_uid, reason)
 *   task_notify_get_pref(user_id)  → array
 *   task_notify_save_pref(user_id, array)
 */

require_once __DIR__ . '/approval.php';   // approval_send_email()
require_once __DIR__ . '/notifications.php';   // notif_create()

// ─────────────────────────────────────────────────────────────────────────────
//  PUBLICZNE FUNKCJE
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Powiadamia użytkownika o przypisaniu do zadania.
 */
function task_notify_assigned(int $task_id, int $assigned_uid, int $by_uid): void {
    if ($assigned_uid === $by_uid) return;   // nie powiadamiaj siebie

    $user = db_one("SELECT id, name, email FROM users WHERE id=? AND is_active=1", [$assigned_uid]);
    if (!$user) return;

    $task = _tn_task($task_id);
    if (!$task) return;
    $by_name = _tn_user_name($by_uid);

    try {
        notif_create(
            $assigned_uid, 'task',
            'Przypisano Cię do zadania: ' . $task['title'],
            $by_name . ' przypisał(a) Cię do zadania.',
            '/tasks/index.php?task=' . $task_id
        );
    } catch (\Throwable $e) {}

    if (!$user['email']) return;

    $pref = task_notify_get_pref($assigned_uid);
    if (!($pref['notify_assigned'] ?? 1)) return;

    if (!_tn_should_send($assigned_uid, 'assigned', $task_id)) return;

    $task_url = _tn_task_url($task_id);
    $org      = defined('ORG_NAME') ? ORG_NAME : '';

    $subject  = 'Przypisano Cię do zadania: ' . $task['title'];

    $content = '
<p>Cześć <strong>' . htmlspecialchars($user['name']) . '</strong>,</p>
<p>Użytkownik <strong>' . htmlspecialchars($by_name) . '</strong> przypisał Cię do zadania
w systemie <strong>' . htmlspecialchars($org) . '</strong>.</p>
' . _tn_task_card($task) . '
<p style="color:#64748b;font-size:13px">Kliknij przycisk poniżej, aby otworzyć zadanie.</p>';

    $html = _tn_tpl('Nowe przypisanie', $subject, $content, $task_url);
    if (_tn_send($user['email'], $subject, $html)) {
        _tn_log($assigned_uid, 'assigned', $task_id);
    }
    _tn_sms($assigned_uid, 'FEER SZO. Przypisano Cie do zadania: "' . mb_substr($task['title'], 0, 80) . '".');
}

/**
 * Powiadamia o nowym komentarzu:
 *  – wszystkich przypisanych do zadania (poza autorem) jeśli mają notify_comment=1
 *  – każdego @wspomnianego (jeśli ma notify_mentioned=1)
 */
function task_notify_new_comment(int $task_id, int $comment_id, string $body, int $author_uid): void {
    $task = _tn_task($task_id);
    if (!$task) return;

    $author_name = _tn_user_name($author_uid);
    $task_url    = _tn_task_url($task_id);
    $org         = defined('ORG_NAME') ? ORG_NAME : '';
    $snippet     = mb_substr($body, 0, 300) . (mb_strlen($body) > 300 ? '…' : '');

    // ── 1. Powiadomienia dla przypisanych (notify_comment) ─────────────────
    $assignees = db_all(
        "SELECT u.id, u.name, u.email
         FROM task_assignments ta JOIN users u ON u.id=ta.user_id
         WHERE ta.task_id=? AND u.is_active=1",
        [$task_id]
    );
    foreach ($assignees as $u) {
        if ((int)$u['id'] === $author_uid) continue;

        try {
            notif_create(
                (int)$u['id'], 'task',
                $author_name . ' skomentował: ' . $task['title'],
                mb_substr($snippet, 0, 200),
                '/tasks/index.php?task=' . $task_id
            );
        } catch (\Throwable $e) {}

        if (!$u['email']) continue;

        $pref = task_notify_get_pref((int)$u['id']);
        if (!($pref['notify_comment'] ?? 0)) continue;
        if (!_tn_should_send((int)$u['id'], 'comment', $comment_id)) continue;

        $subject = htmlspecialchars($author_name) . ' skomentował: ' . $task['title'];
        $content = '
<p>Cześć <strong>' . htmlspecialchars($u['name']) . '</strong>,</p>
<p><strong>' . htmlspecialchars($author_name) . '</strong> dodał komentarz do zadania,
do którego jesteś przypisany/a.</p>
' . _tn_task_card($task) . '
<div style="background:#f8fafc;border-left:4px solid #2563eb;padding:10px 14px;
            margin:14px 0;border-radius:0 6px 6px 0;font-size:14px;color:#1e293b;line-height:1.5">
  ' . nl2br(htmlspecialchars($snippet)) . '
</div>';
        $html = _tn_tpl('Nowy komentarz', $subject, $content, $task_url);
        if (_tn_send($u['email'], $subject, $html)) {
            _tn_log((int)$u['id'], 'comment', $comment_id);
        }
        _tn_sms((int)$u['id'], 'FEER SZO. ' . mb_substr($author_name, 0, 20) . ' skomentował zadanie: "' . mb_substr($task['title'], 0, 60) . '".');
    }

    // ── 2. @wzmianki (notify_mentioned) ───────────────────────────────────
    $all_users = db_all("SELECT id, name, email FROM users WHERE is_active=1");
    $mentioned = _tn_parse_mentions($body, $all_users, $author_uid);

    foreach ($mentioned as $u) {
        try {
            notif_create(
                (int)$u['id'], 'task',
                $author_name . ' wspomniał Cię w zadaniu: ' . $task['title'],
                mb_substr($snippet, 0, 200),
                '/tasks/index.php?task=' . $task_id
            );
        } catch (\Throwable $e) {}

        if (!$u['email']) continue;
        $pref = task_notify_get_pref((int)$u['id']);
        if (!($pref['notify_mentioned'] ?? 1)) continue;
        if (!_tn_should_send((int)$u['id'], 'mention', $comment_id)) continue;

        $subject = htmlspecialchars($author_name) . ' wspomniał Cię w zadaniu: ' . $task['title'];
        $content = '
<p>Cześć <strong>' . htmlspecialchars($u['name']) . '</strong>,</p>
<p><strong>' . htmlspecialchars($author_name) . '</strong> wspomniał Cię w komentarzu
do zadania w systemie <strong>' . htmlspecialchars($org) . '</strong>.</p>
' . _tn_task_card($task) . '
<div style="background:#eff6ff;border-left:4px solid #2563eb;padding:10px 14px;
            margin:14px 0;border-radius:0 6px 6px 0;font-size:14px;color:#1e293b;line-height:1.5">
  ' . nl2br(_tn_highlight_mentions(htmlspecialchars($snippet), $u['name'])) . '
</div>';
        $html = _tn_tpl('Wspomniano Cię', $subject, $content, $task_url);
        if (_tn_send($u['email'], $subject, $html)) {
            _tn_log((int)$u['id'], 'mention', $comment_id);
        }
        _tn_sms((int)$u['id'], 'FEER SZO. ' . mb_substr($author_name, 0, 20) . ' wspomniał Cię w zadaniu: "' . mb_substr($task['title'], 0, 60) . '".');
    }
}

/**
 * Powiadamia przypisanych o zbliżającym się terminie zadania.
 * $event: 'due_1day' | 'due_today'
 */
function task_notify_due(int $task_id, string $event): void {
    $task = _tn_task($task_id);
    if (!$task || $task['completed_at']) return;

    $task_url  = _tn_task_url($task_id);
    $org       = defined('ORG_NAME') ? ORG_NAME : '';
    $due_label = $event === 'due_today' ? 'dziś' : 'jutro';
    $due_str   = $task['due_date'] ? date('d.m.Y', strtotime($task['due_date'])) : '';

    $assignees = db_all(
        "SELECT u.id, u.name, u.email
         FROM task_assignments ta JOIN users u ON u.id=ta.user_id
         WHERE ta.task_id=? AND u.is_active=1",
        [$task_id]
    );
    foreach ($assignees as $u) {
        try {
            notif_create(
                (int)$u['id'], 'task',
                'Termin zadania ' . $due_label . ': ' . $task['title'],
                $due_str ? ('Termin: ' . $due_str) : '',
                '/tasks/index.php?task=' . $task_id
            );
        } catch (\Throwable $e) {}

        if (!$u['email']) continue;
        $pref = task_notify_get_pref((int)$u['id']);
        if (!($pref[$event] ?? 1)) continue;
        if (!_tn_should_send((int)$u['id'], $event, $task_id)) continue;

        $subject = "Termin zadania " . ($event === 'due_today' ? 'dzisiaj' : 'jutro') . ': ' . $task['title'];
        $content = '
<p>Cześć <strong>' . htmlspecialchars($u['name']) . '</strong>,</p>
<p>Termin poniższego zadania upływa <strong>' . $due_label . ' (' . $due_str . ')</strong>.</p>
' . _tn_task_card($task) . '
<p style="color:#64748b;font-size:13px">Pamiętaj o aktualizacji statusu zadania.</p>';
        $html = _tn_tpl('Zbliżający się termin', $subject, $content, $task_url);
        if (_tn_send($u['email'], $subject, $html)) {
            _tn_log((int)$u['id'], $event, $task_id);
        }
        $sms_when = $event === 'due_today' ? 'DZISIAJ' : 'JUTRO';
        _tn_sms((int)$u['id'], 'FEER SZO. Termin zadania ' . $sms_when . ': "' . mb_substr($task['title'], 0, 80) . '" (' . $due_str . ').');
    }
}

/**
 * Powiadamia przypisanych wykonawców, że lider potwierdził wykonanie zadania.
 */
function task_notify_confirmed(int $task_id, int $by_uid): void {
    $task = _tn_task($task_id);
    if (!$task) return;

    $by_name  = _tn_user_name($by_uid);
    $task_url = _tn_task_url($task_id);

    $assignees = db_all(
        "SELECT u.id, u.name, u.email
         FROM task_assignments ta JOIN users u ON u.id=ta.user_id
         WHERE ta.task_id=? AND u.is_active=1",
        [$task_id]
    );
    foreach ($assignees as $u) {
        if ((int)$u['id'] === $by_uid) continue;   // nie powiadamiaj siebie

        try {
            notif_create(
                (int)$u['id'], 'task',
                'Potwierdzono wykonanie: ' . $task['title'],
                $by_name . ' potwierdził(a) wykonanie zadania.',
                '/tasks/index.php?task=' . $task_id
            );
        } catch (\Throwable $e) {}

        if (!$u['email']) continue;
        $pref = task_notify_get_pref((int)$u['id']);
        if (!($pref['notify_confirmed'] ?? 1)) continue;
        if (!_tn_should_send((int)$u['id'], 'confirmed', $task_id)) continue;

        $subject = 'Potwierdzono wykonanie zadania: ' . $task['title'];
        $content = '
<p>Cześć <strong>' . htmlspecialchars($u['name']) . '</strong>,</p>
<p><strong>' . htmlspecialchars($by_name) . '</strong> potwierdził(a) wykonanie zadania,
które realizujesz.</p>
' . _tn_task_card($task);
        $html = _tn_tpl('Wykonanie potwierdzone', $subject, $content, $task_url);
        if (_tn_send($u['email'], $subject, $html)) {
            _tn_log((int)$u['id'], 'confirmed', $task_id);
        }
        _tn_sms((int)$u['id'], 'FEER SZO. ' . mb_substr($by_name, 0, 20) . ' potwierdził(a) wykonanie zadania: "' . mb_substr($task['title'], 0, 60) . '".');
    }
}

/**
 * Powiadamia przypisanych wykonawców, że lider odrzucił wykonanie zadania (z powodem).
 */
function task_notify_rejected(int $task_id, int $by_uid, string $reason): void {
    $task = _tn_task($task_id);
    if (!$task) return;

    $by_name  = _tn_user_name($by_uid);
    $task_url = _tn_task_url($task_id);
    $reason_h = htmlspecialchars($reason, ENT_QUOTES, 'UTF-8');

    $assignees = db_all(
        "SELECT u.id, u.name, u.email
         FROM task_assignments ta JOIN users u ON u.id=ta.user_id
         WHERE ta.task_id=? AND u.is_active=1",
        [$task_id]
    );
    foreach ($assignees as $u) {
        if ((int)$u['id'] === $by_uid) continue;   // nie powiadamiaj siebie

        try {
            notif_create(
                (int)$u['id'], 'task',
                'Odrzucono wykonanie: ' . $task['title'],
                $by_name . ' odrzucił(a) wykonanie. Powód: ' . mb_substr($reason, 0, 200),
                '/tasks/index.php?task=' . $task_id
            );
        } catch (\Throwable $e) {}

        if (!$u['email']) continue;
        $pref = task_notify_get_pref((int)$u['id']);
        if (!($pref['notify_rejected'] ?? 1)) continue;
        if (!_tn_should_send((int)$u['id'], 'rejected', $task_id)) continue;

        $subject = 'Odrzucono wykonanie zadania: ' . $task['title'];
        $content = '
<p>Cześć <strong>' . htmlspecialchars($u['name']) . '</strong>,</p>
<p><strong>' . htmlspecialchars($by_name) . '</strong> odrzucił(a) wykonanie zadania,
które realizujesz — konieczna poprawa.</p>
' . _tn_task_card($task) . '
<div style="background:#fef2f2;border-left:4px solid #dc2626;padding:10px 14px;
            margin:14px 0;border-radius:0 6px 6px 0;font-size:14px;color:#1e293b;line-height:1.5">
  <strong>Powód:</strong><br>' . nl2br($reason_h) . '
</div>';
        $html = _tn_tpl('Wykonanie odrzucone', $subject, $content, $task_url);
        if (_tn_send($u['email'], $subject, $html)) {
            _tn_log((int)$u['id'], 'rejected', $task_id);
        }
        _tn_sms((int)$u['id'], 'FEER SZO. ' . mb_substr($by_name, 0, 20) . ' odrzucił(a) wykonanie zadania: "' . mb_substr($task['title'], 0, 50) . '". Powod: ' . mb_substr($reason, 0, 60));
    }
}

// ─────────────────────────────────────────────────────────────────────────────
//  PREFERENCJE
// ─────────────────────────────────────────────────────────────────────────────

function task_notify_get_pref(int $user_id): array {
    try {
        $row = db_one(
            "SELECT * FROM task_notification_prefs WHERE user_id=?",
            [$user_id]
        );
    } catch (\Throwable $e) { return _tn_default_prefs(); }

    return $row ? $row : _tn_default_prefs();
}

function task_notify_save_pref(int $user_id, array $data): void {
    // migracja: kolumny dodane już po wdrożeniu tabeli — starsze bazy ich nie mają
    try { db()->exec("ALTER TABLE task_notification_prefs ADD COLUMN notify_sms INTEGER NOT NULL DEFAULT 0"); }
    catch (\Throwable $e) {}
    try { db()->exec("ALTER TABLE task_notification_prefs ADD COLUMN notify_confirmed INTEGER NOT NULL DEFAULT 1"); }
    catch (\Throwable $e) {}
    try { db()->exec("ALTER TABLE task_notification_prefs ADD COLUMN notify_rejected INTEGER NOT NULL DEFAULT 1"); }
    catch (\Throwable $e) {}

    $fields  = ['notify_assigned', 'notify_mentioned', 'notify_comment',
                'notify_due_1day', 'notify_due_today', 'notify_sms', 'notify_confirmed', 'notify_rejected'];
    $values  = [];
    foreach ($fields as $f) {
        $values[$f] = isset($data[$f]) ? (int)(bool)$data[$f] : 0;
    }
    $values['user_id']    = $user_id;
    $values['updated_at'] = date('Y-m-d H:i:s');

    $cols = implode(', ', array_keys($values));
    $phs  = implode(', ', array_fill(0, count($values), '?'));
    $upd  = implode(', ', array_map(
        fn($k) => "$k=excluded.$k",
        array_filter(array_keys($values), fn($k) => $k !== 'user_id')
    ));
    db()->prepare(
        "INSERT INTO task_notification_prefs ($cols) VALUES ($phs)
         ON CONFLICT(user_id) DO UPDATE SET $upd"
    )->execute(array_values($values));
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
    ];
}

/** Wysyła SMS powiadomienie jeśli użytkownik ma włączone notify_sms i podany numer. */
function _tn_sms(int $user_id, string $message): void {
    try {
        require_once __DIR__ . '/sms.php';
        if (!sms_is_enabled()) return;
        $pref = task_notify_get_pref($user_id);
        if (empty($pref['notify_sms'])) return;
        $u = db_one("SELECT phone_number FROM users WHERE id=?", [$user_id]);
        $phone = $u['phone_number'] ?? '';
        if (!$phone) return;
        sms_send($phone, $message);
    } catch (\Throwable $_) {}
}

// ─────────────────────────────────────────────────────────────────────────────
//  WEWNĘTRZNE HELPERY
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

function _tn_should_send(int $user_id, string $event, int $ref_id): bool {
    try {
        $row = db_one(
            "SELECT 1 FROM task_notification_log
             WHERE user_id=? AND event_type=? AND ref_id=? AND date(sent_at)=date('now','localtime')",
            [$user_id, $event, $ref_id]
        );
        return !$row;
    } catch (\Throwable $e) { return true; }
}

function _tn_log(int $user_id, string $event, int $ref_id): void {
    try {
        db()->prepare(
            "INSERT OR IGNORE INTO task_notification_log (user_id, event_type, ref_id)
             VALUES (?, ?, ?)"
        )->execute([$user_id, $event, $ref_id]);
    } catch (\Throwable $e) {}
}

function _tn_send(string $to, string $subject, string $html): bool {
    try {
        return (bool) approval_send_email($to, $subject, $html);
    } catch (\Throwable $e) { return false; }
}

/** Parsuje @wzmianki z tekstu i zwraca pasujących aktywnych użytkowników. */
function _tn_parse_mentions(string $body, array $all_users, int $exclude_uid): array {
    $result = [];
    $seen   = [];
    // Sortuj od najdłuższego do najkrótszego — unikaj częściowych dopasowań
    usort($all_users, fn($a, $b) => mb_strlen($b['name']) - mb_strlen($a['name']));
    foreach ($all_users as $u) {
        if ((int)$u['id'] === $exclude_uid) continue;
        if (isset($seen[(int)$u['id']])) continue;
        if (str_contains($body, '@' . $u['name'])) {
            $result[] = $u;
            $seen[(int)$u['id']] = true;
        }
    }
    return $result;
}

/** Podświetla @wzmiankę danego użytkownika w treści HTML. */
function _tn_highlight_mentions(string $escaped_body, string $user_name): string {
    $esc_name = htmlspecialchars($user_name, ENT_QUOTES, 'UTF-8');
    return str_replace(
        '@' . $esc_name,
        '<strong style="color:#1d4ed8">@' . $esc_name . '</strong>',
        $escaped_body
    );
}

/** Karta zadania (mini-blok) wewnątrz maila. */
function _tn_task_card(array $task): string {
    $priority_labels = [1=>'Niski',2=>'Normalny',3=>'Wysoki',4=>'Krytyczny'];
    $priority_colors = [1=>'#64748b',2=>'#2563eb',3=>'#d97706',4=>'#dc2626'];
    $p      = (int)($task['priority'] ?? 2);
    $p_lbl  = $priority_labels[$p] ?? '';
    $p_clr  = $priority_colors[$p] ?? '#64748b';
    $due    = $task['due_date'] ? '<br><span style="color:#64748b;font-size:12px">Termin: <strong>' . date('d.m.Y', strtotime($task['due_date'])) . '</strong></span>' : '';
    $list   = htmlspecialchars($task['list_name'] ?? '');
    $title  = htmlspecialchars($task['title'] ?? '');

    return '<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;
                        padding:12px 16px;margin:14px 0">
  <div style="font-size:15px;font-weight:600;color:#1e293b;margin-bottom:6px">' . $title . '</div>
  <span style="background:' . $p_clr . ';color:#fff;font-size:11px;font-weight:600;
               padding:2px 8px;border-radius:20px">' . $p_lbl . '</span>
  <span style="color:#94a3b8;font-size:12px;margin-left:8px">· ' . $list . '</span>' . $due . '
</div>';
}

/** Główny szablon HTML maila. */
function _tn_tpl(string $header_title, string $preheader, string $content_html, string $cta_url): string {
    $org         = defined('ORG_NAME') ? htmlspecialchars(ORG_NAME) : '';
    $settings_url= htmlspecialchars(rtrim(APP_URL, '/') . '/tasks/notification_settings.php');
    $year        = date('Y');

    return '<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>' . htmlspecialchars($preheader) . '</title>
</head>
<body style="margin:0;padding:0;background:#f1f5f9;
             font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif">
<!-- preheader (ukryty) -->
<div style="display:none;max-height:0;overflow:hidden;font-size:1px;line-height:1px;color:#f1f5f9">
  ' . htmlspecialchars($preheader) . '&nbsp;
</div>
<table width="100%" cellpadding="0" cellspacing="0" role="presentation"
       style="background:#f1f5f9;padding:32px 16px">
<tr><td align="center">
  <table width="100%" style="max-width:560px">

    <!-- Logo / header -->
    <tr><td style="background:#2563eb;padding:20px 28px;border-radius:10px 10px 0 0;
                   text-align:left">
      <span style="color:#fff;font-size:17px;font-weight:700">
        📋 ' . htmlspecialchars($header_title) . '
      </span>
    </td></tr>

    <!-- Body -->
    <tr><td style="background:#ffffff;padding:24px 28px;color:#1e293b;
                   font-size:15px;line-height:1.65">
      ' . $content_html . '
    </td></tr>

    <!-- CTA -->
    <tr><td style="background:#ffffff;padding:0 28px 28px;text-align:center">
      <a href="' . htmlspecialchars($cta_url) . '"
         style="background:#2563eb;color:#ffffff;text-decoration:none;
                padding:11px 28px;border-radius:7px;font-weight:600;
                display:inline-block;font-size:14px;letter-spacing:.01em">
        Otwórz zadanie →
      </a>
    </td></tr>

    <!-- Footer -->
    <tr><td style="background:#f8fafc;padding:14px 28px;border-top:1px solid #e2e8f0;
                   border-radius:0 0 10px 10px;color:#94a3b8;font-size:12px;line-height:1.6">
      <p style="margin:0">
        ' . $org . ' · ' . $year . '<br>
        <a href="' . $settings_url . '" style="color:#94a3b8;text-decoration:underline">
          Zarządzaj powiadomieniami
        </a>
      </p>
    </td></tr>

  </table>
</td></tr>
</table>
</body>
</html>';
}
