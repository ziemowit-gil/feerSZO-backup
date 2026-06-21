<?php
/**
 * includes/ti_messages.php — Moduł wiadomości kursant ↔ prowadzący (Zajęcia TI).
 *
 * Model: jeden wątek na kursanta (k30_ti_messages.student_id). Każdy wiersz to
 * pojedyncza wiadomość z polem `sender` ('staff' | 'student'). `is_read` oznacza
 * odczytanie przez DRUGĄ stronę:
 *   - wiadomość 'staff'   → is_read=1 gdy odczytał ją kursant,
 *   - wiadomość 'student' → is_read=1 gdy odczytał ją prowadzący.
 *
 * Powiadomienia o nowych wiadomościach dla kursanta wysyłane są e-mailem i/lub
 * SMS-em zależnie od jego ustawień (k30_ti_student_accounts.notify_email_messages
 * / notify_sms_messages). Adres i telefon pobierane są z powiązanego k30_clients.
 */

require_once __DIR__ . '/db.php';

/** Lista wiadomości w wątku kursanta (rosnąco po dacie). */
function ti_msg_list_for_student(int $studentId): array {
    return db_all(
        "SELECT * FROM k30_ti_messages WHERE student_id=? ORDER BY created_at ASC, id ASC",
        [$studentId]
    );
}

/** Liczba nieprzeczytanych wiadomości od prowadzącego dla kursanta. */
function ti_msg_unread_for_student(int $studentId): int {
    $r = db_one(
        "SELECT COUNT(*) c FROM k30_ti_messages WHERE student_id=? AND sender='staff' AND is_read=0",
        [$studentId]
    );
    return (int)($r['c'] ?? 0);
}

/** Oznacza wiadomości od prowadzącego jako przeczytane przez kursanta. */
function ti_msg_mark_read_for_student(int $studentId): void {
    db()->prepare(
        "UPDATE k30_ti_messages SET is_read=1, read_at=datetime('now')
         WHERE student_id=? AND sender='staff' AND is_read=0"
    )->execute([$studentId]);
}

/** Liczba nieprzeczytanych wiadomości OD kursantów dla prowadzących (globalnie lub dla jednego). */
function ti_msg_unread_for_staff(?int $studentId = null): int {
    if ($studentId !== null) {
        $r = db_one("SELECT COUNT(*) c FROM k30_ti_messages WHERE student_id=? AND sender='student' AND is_read=0", [$studentId]);
    } else {
        $r = db_one("SELECT COUNT(*) c FROM k30_ti_messages WHERE sender='student' AND is_read=0");
    }
    return (int)($r['c'] ?? 0);
}

/** Oznacza wiadomości od kursanta jako przeczytane przez prowadzącego. */
function ti_msg_mark_read_for_staff(int $studentId): void {
    db()->prepare(
        "UPDATE k30_ti_messages SET is_read=1, read_at=datetime('now')
         WHERE student_id=? AND sender='student' AND is_read=0"
    )->execute([$studentId]);
}

/**
 * Prowadzący → kursant. Zapisuje wiadomość i (opcjonalnie) wysyła powiadomienie
 * e-mail/SMS zgodnie z ustawieniami kursanta. Zwraca id wstawionej wiadomości.
 */
function ti_msg_post_to_student(int $studentId, string $subject, string $body, ?int $byUserId, string $byName, bool $notify = true): int {
    $id = db_insert('k30_ti_messages', [
        'student_id'     => $studentId,
        'sender'         => 'staff',
        'sender_user_id' => $byUserId ?: null,
        'sender_name'    => mb_substr($byName, 0, 120),
        'subject'        => mb_substr(trim($subject), 0, 200),
        'body'           => trim($body),
        'is_read'        => 0,
        'created_at'     => date('Y-m-d H:i:s'),
    ]);
    if ($notify) ti_msg_notify_student($studentId, $subject, $body);
    return $id;
}

/**
 * Rozsyła wiadomość do wielu kursantów (po kontach). Zwraca liczbę adresatów.
 * $accountIds — tablica id z k30_ti_student_accounts.
 */
function ti_msg_broadcast(array $accountIds, string $subject, string $body, ?int $byUserId, string $byName): int {
    $n = 0;
    foreach (array_unique(array_map('intval', $accountIds)) as $sid) {
        if ($sid <= 0) continue;
        ti_msg_post_to_student($sid, $subject, $body, $byUserId, $byName, true);
        $n++;
    }
    return $n;
}

/** Kursant → prowadzący (odpowiedź w wątku). Powiadamia ostatniego nadawcę-prowadzącego mailem. */
function ti_msg_student_reply(int $studentId, string $body): int {
    $acc    = db_one("SELECT a.*, cl.name AS client_name FROM k30_ti_student_accounts a LEFT JOIN k30_clients cl ON cl.id=a.client_id WHERE a.id=?", [$studentId]);
    $byName = $acc['client_name'] ?? ($acc['login'] ?? 'Kursant');
    $id = db_insert('k30_ti_messages', [
        'student_id'  => $studentId,
        'sender'      => 'student',
        'sender_name' => mb_substr($byName, 0, 120),
        'body'        => trim($body),
        'is_read'     => 0,
        'created_at'  => date('Y-m-d H:i:s'),
    ]);
    ti_msg_notify_staff_reply($studentId, (string)$byName, $body);
    return $id;
}

/** Wysyła powiadomienie do kursanta o nowej wiadomości (e-mail i/lub SMS wg ustawień). */
function ti_msg_notify_student(int $studentId, string $subject, string $body): void {
    $acc = db_one(
        "SELECT a.notify_email_messages, a.notify_sms_messages, a.notify_phone2, a.notify_phone3,
                cl.name, cl.email, cl.phone
         FROM k30_ti_student_accounts a LEFT JOIN k30_clients cl ON cl.id=a.client_id
         WHERE a.id=?",
        [$studentId]
    );
    if (!$acc) return;

    $org     = defined('ORG_NAME') ? ORG_NAME : 'Panel kursanta';
    $subj    = trim($subject) !== '' ? trim($subject) : 'Nowa wiadomość';
    $preview = trim(mb_substr(trim(strip_tags($body)), 0, 280));

    // E-mail
    if (!empty($acc['notify_email_messages'])) {
        $email = trim((string)($acc['email'] ?? ''));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            if (!function_exists('mail_queue_add')) @require_once __DIR__ . '/mail_queue.php';
            if (function_exists('mail_queue_add')) {
                $name  = htmlspecialchars((string)($acc['name'] ?? ''), ENT_QUOTES);
                $url   = rtrim(defined('APP_URL') ? APP_URL : '', '/') . '/karty30/ti/kursant/index.php?tab=wiadomosci';
                $bsafe = nl2br(htmlspecialchars($preview, ENT_QUOTES));
                $html  = "<p>Cześć {$name},</p>"
                       . "<p>Masz nową wiadomość w panelu kursanta:</p>"
                       . "<p style='border-left:3px solid #2563eb;padding:6px 12px;color:#333'><strong>" . htmlspecialchars($subj, ENT_QUOTES) . "</strong><br>{$bsafe}</p>"
                       . "<p><a href='" . htmlspecialchars($url, ENT_QUOTES) . "'>Otwórz panel, aby przeczytać i odpowiedzieć</a>.</p>"
                       . "<p style='color:#888;font-size:12px'>Wiadomość automatyczna z systemu {$org}.</p>";
                try { mail_queue_add($email, (string)($acc['name'] ?? ''), "{$org}: {$subj}", $html, '', 'ti_message', $studentId, '', true); } catch (\Throwable $e) {}
            }
        }
    }

    // SMS
    if (!empty($acc['notify_sms_messages'])) {
        if (!function_exists('sms_send')) @require_once __DIR__ . '/sms.php';
        if (function_exists('sms_send') && function_exists('sms_is_enabled') && sms_is_enabled()) {
            $txt  = "{$org}: nowa wiadomosc w panelu kursanta. Zaloguj sie, aby przeczytac.";
            $nums = function_exists('k30_ti_sms_numbers') ? k30_ti_sms_numbers($acc) : array_filter([trim((string)($acc['phone'] ?? ''))]);
            foreach ($nums as $num) {
                try { sms_send($num, $txt); } catch (\Throwable $e) {}
            }
        }
    }
}

/** Powiadamia prowadzącego (ostatniego nadawcę) e-mailem o odpowiedzi kursanta. */
function ti_msg_notify_staff_reply(int $studentId, string $studentName, string $body): void {
    // ostatni prowadzący, który pisał w tym wątku
    $last = db_one(
        "SELECT sender_user_id FROM k30_ti_messages
         WHERE student_id=? AND sender='staff' AND sender_user_id IS NOT NULL
         ORDER BY created_at DESC, id DESC LIMIT 1",
        [$studentId]
    );
    $uid = (int)($last['sender_user_id'] ?? 0);
    if (!$uid) return;
    $u = db_one("SELECT name, email FROM users WHERE id=?", [$uid]);
    $email = trim((string)($u['email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return;

    if (!function_exists('mail_queue_add')) @require_once __DIR__ . '/mail_queue.php';
    if (!function_exists('mail_queue_add')) return;

    $org     = defined('ORG_NAME') ? ORG_NAME : 'Panel';
    $url     = rtrim(defined('APP_URL') ? APP_URL : '', '/') . '/karty30/ti/messages.php?student=' . $studentId;
    $preview = nl2br(htmlspecialchars(trim(mb_substr(trim(strip_tags($body)), 0, 400)), ENT_QUOTES));
    $sn      = htmlspecialchars($studentName, ENT_QUOTES);
    $html    = "<p>Kursant <strong>{$sn}</strong> odpowiedział w panelu:</p>"
             . "<p style='border-left:3px solid #16a34a;padding:6px 12px;color:#333'>{$preview}</p>"
             . "<p><a href='" . htmlspecialchars($url, ENT_QUOTES) . "'>Otwórz wątek</a>.</p>";
    try { mail_queue_add($email, (string)($u['name'] ?? ''), "{$org}: odpowiedź kursanta — {$studentName}", $html, '', 'ti_message', $studentId, '', true); } catch (\Throwable $e) {}
}
