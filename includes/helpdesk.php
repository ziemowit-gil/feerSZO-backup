<?php
/**
 * includes/helpdesk.php — Moduł Helpdesk IT
 */

const HD_STATUSES = [
    'nowe'       => ['label' => 'Nowe',       'class' => 'primary',   'icon' => 'bi-inbox-fill',       'text' => 'primary'],
    'otwarte'    => ['label' => 'Otwarte',    'class' => 'warning',   'icon' => 'bi-folder2-open',     'text' => 'dark'],
    'oczekuje'   => ['label' => 'Oczekuje',   'class' => 'secondary', 'icon' => 'bi-hourglass-split',  'text' => 'white'],
    'rozwiązane' => ['label' => 'Rozwiązane', 'class' => 'info',      'icon' => 'bi-check-circle-fill','text' => 'dark'],
    'zamknięte'  => ['label' => 'Zamknięte',  'class' => 'success',   'icon' => 'bi-lock-fill',        'text' => 'white'],
];

const HD_CATEGORIES = [
    'it_sprzet'         => 'Sprzęt IT',
    'it_oprogramowanie' => 'Oprogramowanie',
    'it_siec'           => 'Sieć / Internet',
    'it_dostep'         => 'Dostęp / Uprawnienia',
    'it_konto'          => 'Konto / Logowanie',
    'it_m365'           => 'Microsoft 365',
    'it_printer'        => 'Drukarki / Urządzenia',
    'it_backup'         => 'Kopia zapasowa / Dane',
    'it_inne'           => 'Inne IT',
    'inne'              => 'Inne (spoza IT)',
    'bug_report'        => 'Zgłoszenie błędu',
];

const HD_PRIORITIES = [
    'niski'    => ['label' => 'Niski',     'class' => 'success',   'order' => 1],
    'normalny' => ['label' => 'Normalny',  'class' => 'secondary', 'order' => 2],
    'wysoki'   => ['label' => 'Wysoki',    'class' => 'warning',   'order' => 3],
    'krytyczny'=> ['label' => 'Krytyczny', 'class' => 'danger',    'order' => 4],
];

function helpdesk_migrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo  = db();

    $pdo->exec("CREATE TABLE IF NOT EXISTS helpdesk_tickets (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        number          TEXT    NOT NULL UNIQUE,
        title           TEXT    NOT NULL,
        description     TEXT    NOT NULL DEFAULT '',
        category        TEXT    NOT NULL DEFAULT 'it_inne',
        priority        TEXT    NOT NULL DEFAULT 'normalny',
        status          TEXT    NOT NULL DEFAULT 'nowe',
        requester_id    INTEGER REFERENCES users(id) ON DELETE SET NULL,
        requester_name  TEXT    NOT NULL DEFAULT '',
        requester_email TEXT,
        requester_phone TEXT,
        assigned_to     INTEGER REFERENCES users(id) ON DELETE SET NULL,
        source          TEXT    NOT NULL DEFAULT 'portal',
        created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
        resolved_at     DATETIME,
        closed_at       DATETIME
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_hd_status   ON helpdesk_tickets(status)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_hd_assigned ON helpdesk_tickets(assigned_to)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_hd_req      ON helpdesk_tickets(requester_id)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS helpdesk_messages (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        ticket_id   INTEGER NOT NULL REFERENCES helpdesk_tickets(id) ON DELETE CASCADE,
        user_id     INTEGER REFERENCES users(id) ON DELETE SET NULL,
        user_name   TEXT    NOT NULL DEFAULT '',
        body        TEXT    NOT NULL,
        is_internal INTEGER NOT NULL DEFAULT 0,
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_hd_msg_ticket ON helpdesk_messages(ticket_id)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS helpdesk_attachments (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        ticket_id     INTEGER NOT NULL REFERENCES helpdesk_tickets(id) ON DELETE CASCADE,
        message_id    INTEGER REFERENCES helpdesk_messages(id) ON DELETE SET NULL,
        original_name TEXT    NOT NULL,
        stored_path   TEXT    NOT NULL,
        file_size     INTEGER NOT NULL DEFAULT 0,
        uploaded_by   INTEGER REFERENCES users(id) ON DELETE SET NULL,
        uploaded_at   DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_hd_att_ticket ON helpdesk_attachments(ticket_id)");

    try { $pdo->exec("ALTER TABLE users ADD COLUMN helpdesk_operator INTEGER NOT NULL DEFAULT 0"); } catch (\Throwable $e) {}

    try {
        $s = db_one("SELECT id FROM settings WHERE key_='helpdesk_enabled'");
        if (!$s) $pdo->prepare("INSERT INTO settings (key_, value) VALUES (?,?)")->execute(['helpdesk_enabled', '1']);
    } catch (\Throwable $e) {}

    try {
        $s = db_one("SELECT id FROM settings WHERE key_='bug_report_enabled'");
        if (!$s) $pdo->prepare("INSERT INTO settings (key_, value) VALUES (?,?)")->execute(['bug_report_enabled', '1']);
    } catch (\Throwable $e) {}
}

// ── Autoryzacja ───────────────────────────────────────────────────────────────

function hd_is_operator(): bool {
    if (is_admin()) return true;
    $u = current_user();
    return $u && !empty($u['helpdesk_operator']);
}

function hd_can_view_ticket(array $ticket): bool {
    $u = current_user();
    if (!$u) return false;
    if (hd_is_operator()) return true;
    return (int)($ticket['requester_id'] ?? 0) === (int)$u['id'];
}

// ── Generowanie numeru ────────────────────────────────────────────────────────

function hd_next_number(string $prefix = 'HD'): string {
    // Numeruj niezależnie per-prefiks (HD, CHG, …), aby uniknąć kolizji UNIQUE,
    // gdy w jednej tabeli mieszają się różne prefiksy o różnej długości.
    $last = db_one(
        "SELECT number FROM helpdesk_tickets WHERE number LIKE ? ORDER BY id DESC LIMIT 1",
        [$prefix . '%']
    );
    $n = $last ? ((int)preg_replace('/\D/', '', $last['number']) + 1) : 1;
    return $prefix . str_pad($n, 5, '0', STR_PAD_LEFT);
}

// ── Badges HTML ───────────────────────────────────────────────────────────────

function hd_status_badge(string $status): string {
    $s = HD_STATUSES[$status] ?? ['label' => $status, 'class' => 'secondary', 'icon' => 'bi-question-circle', 'text' => 'white'];
    return '<span class="badge bg-' . $s['class'] . ' text-' . $s['text'] . '">'
         . '<i class="bi ' . $s['icon'] . ' me-1"></i>' . h($s['label']) . '</span>';
}

function hd_priority_badge(string $priority): string {
    $p = HD_PRIORITIES[$priority] ?? ['label' => $priority, 'class' => 'secondary'];
    return '<span class="badge bg-' . $p['class'] . '-subtle border border-' . $p['class']
         . '-subtle text-' . $p['class'] . '-emphasis">' . h($p['label']) . '</span>';
}

// ── Powiadomienia ─────────────────────────────────────────────────────────────

function hd_notify_status_change(array $ticket, string $old_status, string $new_status, string $note = ''): void {
    $old_label = HD_STATUSES[$old_status]['label'] ?? $old_status;
    $new_label = HD_STATUSES[$new_status]['label'] ?? $new_status;
    $org       = defined('ORG_NAME') ? ORG_NAME : 'Helpdesk';
    $url       = APP_URL . '/helpdesk/view.php?id=' . $ticket['id'];
    $num       = $ticket['number'];

    // SMS
    if (!empty($ticket['requester_phone'])) {
        try {
            require_once dirname(__DIR__) . '/includes/sms.php';
            $sms = "[{$org}] Zgłoszenie {$num} – status: {$old_label} → {$new_label}. Szczegóły: {$url}";
            sms_send($ticket['requester_phone'], $sms);
        } catch (\Throwable $e) {}
    }

    // E-mail
    $email = $ticket['requester_email'] ?? '';
    if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        try {
            require_once dirname(__DIR__) . '/includes/mail_queue.php';
            mail_queue_add(
                $email, $ticket['requester_name'],
                "[{$num}] Zmiana statusu zgłoszenia: {$new_label}",
                _hd_email_status($ticket, $old_label, $new_label, $note, $org, $url)
            );
        } catch (\Throwable $e) {}
    }

    // Powiadom przypisanego operatora (jeśli zmiana istotna)
    if (!empty($ticket['assigned_to'])) {
        try {
            $op = db_one("SELECT email, name FROM users WHERE id=?", [(int)$ticket['assigned_to']]);
            if ($op && !empty($op['email']) && $op['email'] !== $email) {
                require_once dirname(__DIR__) . '/includes/mail_queue.php';
                mail_queue_add(
                    $op['email'], $op['name'] ?? '',
                    "[{$num}] Status: {$new_label} — {$ticket['title']}",
                    _hd_email_status($ticket, $old_label, $new_label, $note, $org, $url)
                );
            }
        } catch (\Throwable $e) {}
    }
}

function hd_notify_new_message(array $ticket, array $message): void {
    if ($message['is_internal']) return;
    $org = defined('ORG_NAME') ? ORG_NAME : 'Helpdesk';
    $url = APP_URL . '/helpdesk/view.php?id=' . $ticket['id'];
    $num = $ticket['number'];

    $msg_uid = (int)($message['user_id'] ?? 0);
    $req_uid = (int)($ticket['requester_id'] ?? 0);

    // Wiadomość od operatora → powiadom zgłaszającego
    if ($msg_uid !== $req_uid && !empty($ticket['requester_email'])) {
        $email = $ticket['requester_email'];
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            try {
                require_once dirname(__DIR__) . '/includes/mail_queue.php';
                mail_queue_add(
                    $email, $ticket['requester_name'],
                    "[{$num}] Nowa odpowiedź na zgłoszenie",
                    _hd_email_message($ticket, $message, $org, $url, false)
                );
            } catch (\Throwable $e) {}
        }
        // SMS do zgłaszającego
        if (!empty($ticket['requester_phone'])) {
            try {
                require_once dirname(__DIR__) . '/includes/sms.php';
                sms_send($ticket['requester_phone'],
                    "[{$org}] Zgłoszenie {$num}: nowa odpowiedź od operatora. Sprawdź: {$url}");
            } catch (\Throwable $e) {}
        }
    }

    // Wiadomość od zgłaszającego → powiadom przypisanego operatora
    if ($msg_uid === $req_uid && !empty($ticket['assigned_to'])) {
        try {
            $op = db_one("SELECT email, name FROM users WHERE id=?", [(int)$ticket['assigned_to']]);
            if ($op && !empty($op['email'])) {
                require_once dirname(__DIR__) . '/includes/mail_queue.php';
                mail_queue_add(
                    $op['email'], $op['name'] ?? '',
                    "[{$num}] Odpowiedź użytkownika: {$ticket['title']}",
                    _hd_email_message($ticket, $message, $org, $url, true)
                );
            }
        } catch (\Throwable $e) {}
    }
}

function hd_notify_assigned(array $ticket, array $operator): void {
    if (empty($operator['email'])) return;
    $org = defined('ORG_NAME') ? ORG_NAME : 'Helpdesk';
    $url = APP_URL . '/helpdesk/view.php?id=' . $ticket['id'];
    $num = $ticket['number'];
    $name = h($operator['name'] ?? $operator['email']);
    $title = h($ticket['title']);
    try {
        require_once dirname(__DIR__) . '/includes/mail_queue.php';
        mail_queue_add(
            $operator['email'], $operator['name'] ?? '',
            "[{$num}] Przypisano Ci nowe zgłoszenie IT",
            <<<HTML
<html><body style="font-family:sans-serif;max-width:600px;margin:0 auto;padding:20px;color:#212529">
<div style="background:#1e40af;padding:20px 24px;border-radius:8px 8px 0 0">
  <h2 style="color:#fff;margin:0;font-size:1.1rem">🎫 Nowe przypisanie — {$org} Helpdesk</h2>
</div>
<div style="border:1px solid #dee2e6;border-top:none;padding:24px;border-radius:0 0 8px 8px">
  <p>Witaj, <strong>{$name}</strong>!</p>
  <p>Przypisano Ci zgłoszenie <strong>{$num}</strong>: <em>{$title}</em></p>
  <div style="margin:20px 0;text-align:center">
    <a href="{$url}" style="background:#1e40af;color:#fff;padding:11px 26px;border-radius:6px;text-decoration:none;display:inline-block;font-weight:600">
      Otwórz zgłoszenie →
    </a>
  </div>
</div></body></html>
HTML
        );
    } catch (\Throwable $e) {}
}

// ── Szablony e-mail (prywatne) ────────────────────────────────────────────────

function _hd_email_status(array $ticket, string $old_label, string $new_label, string $note, string $org, string $url): string {
    $num   = h($ticket['number']);
    $title = h($ticket['title']);
    $name  = h($ticket['requester_name']);
    $color = match($new_label) {
        'Zamknięte' => '#16a34a', 'Rozwiązane' => '#0891b2',
        'Krytyczny' => '#dc2626', 'Wysoki' => '#d97706',
        default => '#1e40af',
    };
    $note_block = $note
        ? '<div style="background:#f8f9fa;border-left:3px solid #6c757d;padding:10px 14px;margin:12px 0;border-radius:0 4px 4px 0;font-size:.9em">'
          . nl2br(h($note)) . '</div>'
        : '';
    return <<<HTML
<html><body style="font-family:sans-serif;max-width:600px;margin:0 auto;padding:20px;color:#212529">
<div style="background:{$color};padding:20px 24px;border-radius:8px 8px 0 0">
  <h2 style="color:#fff;margin:0;font-size:1.1rem">🎫 Zmiana statusu — {$org} Helpdesk</h2>
</div>
<div style="border:1px solid #dee2e6;border-top:none;padding:24px;border-radius:0 0 8px 8px">
  <p>Witaj, <strong>{$name}</strong>!</p>
  <p>Status Twojego zgłoszenia <strong>{$num}</strong> — <em>{$title}</em> uległ zmianie:</p>
  <p style="font-size:1.05em;padding:10px 16px;background:#f8f9fa;border-radius:6px">
    <strong>{$old_label}</strong> &rarr; <strong style="color:{$color}">{$new_label}</strong>
  </p>
  {$note_block}
  <div style="margin:20px 0;text-align:center">
    <a href="{$url}" style="background:{$color};color:#fff;padding:11px 26px;border-radius:6px;text-decoration:none;display:inline-block;font-weight:600">
      Otwórz zgłoszenie →
    </a>
  </div>
  <p style="color:#6c757d;font-size:.82em;border-top:1px solid #dee2e6;padding-top:12px;margin-top:20px">
    {$org} · Helpdesk IT
  </p>
</div></body></html>
HTML;
}

function _hd_email_message(array $ticket, array $message, string $org, string $url, bool $for_operator): string {
    $num   = h($ticket['number']);
    $title = h($ticket['title']);
    $name  = $for_operator ? h($message['user_name']) : h($ticket['requester_name']);
    $from  = h($message['user_name']);
    $body  = nl2br(h($message['body']));
    $intro = $for_operator
        ? "Zgłaszający odpowiedział na zgłoszenie <strong>{$num}</strong>:"
        : "Masz nową odpowiedź na zgłoszenie <strong>{$num}</strong> — <em>{$title}</em>:";
    return <<<HTML
<html><body style="font-family:sans-serif;max-width:600px;margin:0 auto;padding:20px;color:#212529">
<div style="background:#1e40af;padding:20px 24px;border-radius:8px 8px 0 0">
  <h2 style="color:#fff;margin:0;font-size:1.1rem">💬 Nowa wiadomość — {$org} Helpdesk</h2>
</div>
<div style="border:1px solid #dee2e6;border-top:none;padding:24px;border-radius:0 0 8px 8px">
  <p>Witaj, <strong>{$name}</strong>!</p>
  <p>{$intro}</p>
  <div style="background:#f8f9fa;border-left:4px solid #1e40af;padding:12px 16px;margin:16px 0;border-radius:0 6px 6px 0">
    <div style="font-size:.8rem;color:#6c757d;margin-bottom:6px">{$from}:</div>
    {$body}
  </div>
  <div style="margin:20px 0;text-align:center">
    <a href="{$url}" style="background:#1e40af;color:#fff;padding:11px 26px;border-radius:6px;text-decoration:none;display:inline-block;font-weight:600">
      Odpowiedz →
    </a>
  </div>
  <p style="color:#6c757d;font-size:.82em;border-top:1px solid #dee2e6;padding-top:12px;margin-top:20px">
    {$org} · Helpdesk IT
  </p>
</div></body></html>
HTML;
}
