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

/**
 * Sanityzuje HTML z lekkiego edytora WYSIWYG wiadomości (pogrubienie, kursywa,
 * podkreślenie, listy, linki — bez obrazków/tabel/skryptów/styli). Ten sam
 * wzorzec co crm_offer_sanitize_html() (includes/crm_offers.php), zawężony do
 * tego, co faktycznie daje edytor wiadomości.
 */
function ti_msg_sanitize_html(string $html): string {
    if (trim($html) === '') return '';
    $html = preg_replace('#<(script|style|iframe|object|embed)\b[^>]*>.*?</\1>#is', '', $html) ?? '';
    $html = preg_replace('#<(script|style|iframe|object|embed)\b[^>]*/?>#i', '', $html) ?? '';
    $allowed = '<p><br><strong><b><em><i><u><ul><ol><li><a>';
    $clean = strip_tags($html, $allowed);
    $clean = preg_replace('/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $clean) ?? '';
    $clean = preg_replace('/\s+(href|src)\s*=\s*("\s*javascript:[^"]*"|\'\s*javascript:[^\']*\'|javascript:[^\s>]*)/i', '', $clean) ?? '';
    $plain = html_entity_decode(strip_tags($clean), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $plain = trim(str_replace("\xc2\xa0", ' ', $plain));
    if ($plain === '') return '';
    return trim($clean);
}

/**
 * Renderuje treść wiadomości do wyświetlenia: wpisy z edytora (HTML) przechodzą
 * przez whitelistę, starsze wpisy (czysty tekst sprzed edytora) dostają
 * nl2br(h()) jak dotąd — rozpoznanie po obecności tagów.
 */
function ti_msg_render(?string $v): string {
    $v = trim((string)$v);
    if ($v === '') return '';
    if (preg_match('#</?(p|br|ul|ol|li|strong|em|b|i|u|a)\b#i', $v)) {
        return ti_msg_sanitize_html($v);
    }
    return nl2br(h($v));
}

/** Dozwolone rozszerzenia załączników wiadomości — ten sam zestaw co helpdesk/new.php. */
const TI_MSG_ATTACH_EXT = ['pdf','doc','docx','xls','xlsx','jpg','jpeg','png','gif','zip','txt','csv'];

function ti_msg_attachments_migrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        // `kind` odróżnia do jakiej tabeli odnosi się message_id — wiadomości
        // kursant↔prowadzący (k30_ti_messages) i wiadomości do kierownictwa
        // (k30_ti_admin_msgs) mają rozłączne przestrzenie id, stąd bez ON DELETE
        // CASCADE/FK (miękkie odwołanie, jak w reszcie modułu TI).
        db()->exec("CREATE TABLE IF NOT EXISTS k30_ti_message_attachments (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            message_id    INTEGER NOT NULL,
            kind          TEXT    NOT NULL DEFAULT 'student',
            original_name TEXT    NOT NULL,
            stored_path   TEXT    NOT NULL,
            file_size     INTEGER NOT NULL DEFAULT 0,
            uploaded_at   TEXT    NOT NULL DEFAULT (datetime('now'))
        )");
        db()->exec("CREATE INDEX IF NOT EXISTS idx_ti_msg_attach_msg ON k30_ti_message_attachments(kind, message_id)");
    } catch (\Throwable $e) {}
}

/**
 * Zapisuje na dysk i w bazie załączniki jednej wiadomości z $_FILES['attachments']
 * (pole `multiple`). Pliki spoza whitelisty rozszerzeń są po cichu pomijane —
 * to samo zachowanie co w helpdesk/new.php. $kind: 'student' (k30_ti_messages)
 * albo 'admin' (k30_ti_admin_msgs).
 */
function ti_msg_save_attachments(int $messageId, array $filesField, string $kind = 'student'): void {
    if (empty($filesField['name'][0])) return;
    ti_msg_attachments_migrate();
    $dir = UPLOAD_DIR . 'ti_messages/';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    foreach ($filesField['name'] as $i => $origName) {
        if (($filesField['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        if (!in_array($ext, TI_MSG_ATTACH_EXT, true)) continue;
        if ((int)($filesField['size'][$i] ?? 0) > 10 * 1024 * 1024) continue; // 10 MB / plik
        $stored = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        if (@move_uploaded_file($filesField['tmp_name'][$i], $dir . $stored)) {
            db_insert('k30_ti_message_attachments', [
                'message_id'    => $messageId,
                'kind'          => $kind,
                'original_name' => mb_substr($origName, 0, 200),
                'stored_path'   => 'ti_messages/' . $stored,
                'file_size'     => (int)($filesField['size'][$i] ?? 0),
            ]);
        }
    }
}

/** Załączniki jednej wiadomości. $kind: 'student' albo 'admin' — patrz ti_msg_save_attachments(). */
function ti_msg_attachments_for(int $messageId, string $kind = 'student'): array {
    ti_msg_attachments_migrate();
    return db_all("SELECT * FROM k30_ti_message_attachments WHERE message_id=? AND kind=? ORDER BY id", [$messageId, $kind]);
}

/**
 * Lista załączników wiadomości jako gotowe HTML (linki pobrania) — wspólna dla
 * panelu dydaktyka (msg_attachment.php) i panelu kursanta (kursant/msg_attachment.php),
 * każdy przekazuje własną ścieżkę skryptu pobierania (osobne sesje/autoryzacja).
 */
function ti_msg_render_attachments(int $messageId, string $kind, string $downloadScript): string {
    $rows = ti_msg_attachments_for($messageId, $kind);
    if (!$rows) return '';
    $out = '<div class="mt-1 d-flex flex-wrap gap-2">';
    foreach ($rows as $r) {
        $out .= '<a class="badge text-bg-light border text-decoration-none" href="'
              . h($downloadScript . '?id=' . (int)$r['id']) . '">'
              . '<i class="bi bi-paperclip me-1" aria-hidden="true"></i>' . h((string)$r['original_name']) . '</a>';
    }
    return $out . '</div>';
}

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
        'body'           => ti_msg_sanitize_html($body),
        'is_read'        => 0,
        'created_at'     => date('Y-m-d H:i:s'),
    ]);
    if ($notify) {
        ti_msg_notify_student($studentId, $subject, $body);
        ti_msg_notify_parent($studentId, $subject, $body);
    }
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
function ti_msg_student_reply(int $studentId, string $body, string $subject = ''): int {
    if (ti_msg_is_blocked($studentId)) {
        ti_account_log($studentId, 'msg_blocked_attempt', 'Próba wysłania wiadomości przy aktywnej blokadzie.');
        throw new \RuntimeException('Wysyłanie wiadomości jest zablokowane przez prowadzącego.');
    }
    $acc    = db_one("SELECT a.*, cl.name AS client_name FROM k30_ti_student_accounts a LEFT JOIN k30_clients cl ON cl.id=a.client_id WHERE a.id=?", [$studentId]);
    $byName = $acc['client_name'] ?? ($acc['login'] ?? 'Kursant');
    $id = db_insert('k30_ti_messages', [
        'student_id'  => $studentId,
        'sender'      => 'student',
        'sender_name' => mb_substr($byName, 0, 120),
        'subject'     => mb_substr(trim($subject), 0, 200),
        'body'        => ti_msg_sanitize_html($body),
        'is_read'     => 0,
        'created_at'  => date('Y-m-d H:i:s'),
    ]);
    ti_msg_notify_staff_reply($studentId, (string)$byName, $body);
    return $id;
}

/** Wysyła powiadomienie do kursanta o nowej wiadomości (e-mail i/lub SMS wg ustawień). */
function ti_msg_notify_student(int $studentId, string $subject, string $body): void {
    $acc = db_one(
        "SELECT a.notify_email_messages, a.notify_sms_messages, a.notify_phone2, a.notify_phone2_verified, a.notify_phone3, a.notify_phone3_verified,
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
                if (!function_exists('email_tpl_render')) @require_once __DIR__ . '/email_templates.php';
                $firstName = (string)(explode(' ', trim((string)($acc['name'] ?? '')))[0] ?: ($acc['name'] ?? ''));
                $url       = rtrim(defined('APP_URL') ? APP_URL : '', '/') . '/karty30/ti/kursant/index.php?tab=wiadomosci';
                $r = function_exists('email_tpl_render') ? email_tpl_render('ti_message', [
                    'org'          => $org,
                    'name'         => htmlspecialchars($firstName, ENT_QUOTES),
                    'subject'      => htmlspecialchars($subj, ENT_QUOTES),
                    'preview_html' => nl2br(htmlspecialchars($preview, ENT_QUOTES)),
                    'url'          => htmlspecialchars($url, ENT_QUOTES),
                ]) : ['subject' => "{$org}: {$subj}", 'html' => "<p>{$preview}</p>", 'enabled' => true];
                try { mail_queue_add($email, (string)($acc['name'] ?? ''), $r['subject'], $r['html'], '', 'ti_message', $studentId, '', true); } catch (\Throwable $e) {}
            }
        }
    }

    // SMS
    if (!empty($acc['notify_sms_messages'])) {
        if (!function_exists('sms_send')) @require_once __DIR__ . '/sms.php';
        if (function_exists('sms_send') && function_exists('sms_is_enabled') && sms_is_enabled()) {
            if (!function_exists('sms_tpl_render')) require_once __DIR__ . '/sms_templates.php';
            $stpl = sms_tpl_render('ti_new_message', ['org' => $org]);
            if ($stpl['enabled']) {
                $nums = function_exists('k30_ti_sms_numbers') ? k30_ti_sms_numbers($acc) : array_filter([trim((string)($acc['phone'] ?? ''))]);
                foreach ($nums as $num) {
                    try { sms_send($num, $stpl['message']); } catch (\Throwable $e) {}
                }
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
    if (!function_exists('email_tpl_render')) @require_once __DIR__ . '/email_templates.php';

    $org     = defined('ORG_NAME') ? ORG_NAME : 'Panel';
    $url     = rtrim(defined('APP_URL') ? APP_URL : '', '/') . '/karty30/ti/messages.php?student=' . $studentId;
    $preview = nl2br(htmlspecialchars(trim(mb_substr(trim(strip_tags($body)), 0, 400)), ENT_QUOTES));
    $r = function_exists('email_tpl_render') ? email_tpl_render('ti_message_reply', [
        'org'          => $org,
        'student_name' => htmlspecialchars($studentName, ENT_QUOTES),
        'preview_html' => $preview,
        'url'          => htmlspecialchars($url, ENT_QUOTES),
    ]) : ['subject' => "{$org}: odpowiedź kursanta — {$studentName}", 'html' => "<p>{$preview}</p>", 'enabled' => true];
    try { mail_queue_add($email, (string)($u['name'] ?? ''), $r['subject'], $r['html'], '', 'ti_message', $studentId, '', true); } catch (\Throwable $e) {}
}

// ── Dziennik zdarzeń na koncie ────────────────────────────────────────────────

/** Zapisuje zdarzenie do dziennika konta kursanta. */
function ti_account_log(int $studentId, string $action, string $detail = '', ?int $byUserId = null, string $byName = '', string $ip = '', string $ua = ''): void {
    if ($ip === '') $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if ($ua === '') $ua = mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300);
    try {
        db_insert('k30_ti_account_log', [
            'student_id' => $studentId,
            'action'     => mb_substr($action, 0, 80),
            'detail'     => mb_substr($detail, 0, 500),
            'by_user_id' => $byUserId ?: null,
            'by_name'    => mb_substr($byName, 0, 120),
            'ip'         => mb_substr($ip, 0, 45),
            'user_agent' => $ua,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    } catch (\Throwable $e) {}
}

/** Zwraca krótki czytelny opis urządzenia/przeglądarki z User-Agent string. */
function ti_log_device_label(string $ua): string {
    if ($ua === '') return '';
    $os = '';
    if (str_contains($ua, 'Windows')) $os = 'Windows';
    elseif (str_contains($ua, 'Android')) $os = 'Android';
    elseif (str_contains($ua, 'iPhone') || str_contains($ua, 'iPad')) $os = 'iOS';
    elseif (str_contains($ua, 'Mac')) $os = 'macOS';
    elseif (str_contains($ua, 'Linux')) $os = 'Linux';
    $br = '';
    if (str_contains($ua, 'Edg/')) $br = 'Edge';
    elseif (str_contains($ua, 'OPR/') || str_contains($ua, 'Opera')) $br = 'Opera';
    elseif (str_contains($ua, 'Chrome')) $br = 'Chrome';
    elseif (str_contains($ua, 'Firefox')) $br = 'Firefox';
    elseif (str_contains($ua, 'Safari')) $br = 'Safari';
    return trim(($br ? $br : '?') . ($os ? ' / ' . $os : ''));
}

/** Zwraca ostatnie $limit wpisów z dziennika konta. */
function ti_account_log_list(int $studentId, int $limit = 100): array {
    return db_all(
        "SELECT * FROM k30_ti_account_log WHERE student_id=? ORDER BY created_at DESC, id DESC LIMIT ?",
        [$studentId, $limit]
    );
}

// ── Blokada wiadomości ────────────────────────────────────────────────────────

/** Zwraca true, jeśli konto ma aktywną blokadę wiadomości. */
function ti_msg_is_blocked(int $studentId): bool {
    $r = db_one("SELECT msg_blocked FROM k30_ti_student_accounts WHERE id=?", [$studentId]);
    return (bool)($r['msg_blocked'] ?? false);
}

/** Ustawia lub zdejmuje blokadę wiadomości; zapisuje zdarzenie w dzienniku. */
function ti_msg_set_blocked(int $studentId, bool $blocked, ?int $byUserId = null, string $byName = ''): void {
    db()->prepare("UPDATE k30_ti_student_accounts SET msg_blocked=? WHERE id=?")->execute([(int)$blocked, $studentId]);
    $action = $blocked ? 'msg_blocked' : 'msg_unblocked';
    $detail = $blocked ? 'Zablokowano wysyłanie wiadomości przez kursanta.' : 'Odblokowano wysyłanie wiadomości.';
    ti_account_log($studentId, $action, $detail, $byUserId, $byName);
}

// ── Archiwizacja wiadomości ───────────────────────────────────────────────────

/** Archiwizuje pojedynczą wiadomość (ukrywa z widoku głównego). */
function ti_msg_archive(int $msgId, ?int $byUserId = null, string $byName = ''): void {
    $msg = db_one("SELECT student_id, sender, body FROM k30_ti_messages WHERE id=?", [$msgId]);
    if (!$msg) return;
    db()->prepare("UPDATE k30_ti_messages SET is_archived=1 WHERE id=?")->execute([$msgId]);
    $preview = mb_substr(trim(strip_tags((string)($msg['body'] ?? ''))), 0, 80);
    ti_account_log((int)$msg['student_id'], 'msg_archived', "Zarchiwizowano wiad. #{$msgId}: {$preview}", $byUserId, $byName);
}

// ── Wiadomości od rodzica/opiekuna ───────────────────────────────────────────

/** Wstawia wiadomość wysłaną przez rodzica/opiekuna do prowadzącego. */
function ti_msg_parent_send(int $studentId, string $subject, string $body, string $parentName): int {
    $id = db_insert('k30_ti_messages', [
        'student_id'  => $studentId,
        'sender'      => 'parent',
        'sender_name' => mb_substr(trim($parentName ?: 'Opiekun'), 0, 120),
        'subject'     => mb_substr(trim($subject), 0, 200),
        'body'        => trim($body),
        'is_read'     => 0,
        'created_at'  => date('Y-m-d H:i:s'),
    ]);
    ti_msg_notify_staff_reply($studentId, $parentName ?: 'Opiekun', '[od rodzica] ' . trim($body));
    return $id;
}

/** Lista wiadomości dla rodzica (widzi wiadomości staff + parent dla swojego dziecka). */
function ti_msg_list_for_parent(int $studentId): array {
    return db_all(
        "SELECT * FROM k30_ti_messages
         WHERE student_id=? AND sender IN ('staff','parent') AND is_archived=0
         ORDER BY created_at ASC",
        [$studentId]
    );
}

/** Powiadomienie e-mail do rodzica o nowej wiadomości od prowadzącego. */
function ti_msg_notify_parent(int $studentId, string $subject, string $body): void {
    $acc = db_one(
        "SELECT a.parent_notify_messages, a.guardian_email, a.guardian_name, cl.name AS child_name
         FROM k30_ti_student_accounts a LEFT JOIN k30_clients cl ON cl.id=a.client_id
         WHERE a.id=?", [$studentId]
    );
    if (!$acc || empty($acc['parent_notify_messages'])) return;
    $gemail = trim((string)($acc['guardian_email'] ?? ''));
    if ($gemail === '' || !filter_var($gemail, FILTER_VALIDATE_EMAIL)) return;
    if (!function_exists('mail_queue_add')) @require_once __DIR__ . '/mail_queue.php';
    if (!function_exists('mail_queue_add')) return;
    $org     = defined('ORG_NAME') ? ORG_NAME : 'Panel kursanta';
    $subj    = trim($subject) !== '' ? trim($subject) : 'Nowa wiadomosc';
    $preview = mb_substr(trim(strip_tags($body)), 0, 280);
    $url     = (defined('APP_URL') ? rtrim(APP_URL, '/') : '') . '/karty30/ti/kursant/parent.php?ptab=wiadomosci';
    $html    = '<p>Drogi/a ' . htmlspecialchars((string)($acc['guardian_name'] ?: 'Opiekunie'), ENT_QUOTES) . ',</p>'
             . '<p>Prowadzacy wyslal nowa wiadomosc do kursanta <strong>' . htmlspecialchars((string)($acc['child_name'] ?? ''), ENT_QUOTES) . '</strong>:</p>'
             . '<blockquote style="border-left:3px solid #2563eb;padding-left:1em;color:#444">' . nl2br(htmlspecialchars($preview, ENT_QUOTES)) . '</blockquote>'
             . '<p><a href="' . htmlspecialchars($url, ENT_QUOTES) . '">Odpowiedz w panelu rodzica &rarr;</a></p>'
             . '<p>Pozdrawiamy,<br>' . htmlspecialchars($org, ENT_QUOTES) . '</p>';
    try { mail_queue_add($gemail, (string)($acc['guardian_name'] ?: ''), "{$org}: {$subj}", $html); } catch (\Throwable $e) {}
}

// ══════════════════════════════════════════════════════════════════════════════
// Wiadomości prowadzącego → kierownictwo (admini SZO)
// ══════════════════════════════════════════════════════════════════════════════

// Kierownik Instytucji Szkoleniowej — hardkodowany
define('TI_KIS_NAME',  'Ziemowit Gil');
define('TI_KIS_EMAIL', 'ziemowit.gil@feer.org.pl');

function ti_admin_users(): array {
    return db_all("SELECT id, name, email FROM users WHERE role='admin' AND is_active=1 ORDER BY name");
}

function ti_sekretariat_users(): array {
    return db_all("SELECT id, name, email FROM users WHERE role='dydaktyk_sekretariat' AND is_active=1 ORDER BY name");
}

function ti_admin_msg_migrate(): void {
    static $done = false; if ($done) return; $done = true;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS k30_ti_admin_msgs (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id         INTEGER NOT NULL,
            user_name       TEXT    NOT NULL DEFAULT '',
            to_admin_id     INTEGER NOT NULL DEFAULT 0,
            subject         TEXT    NOT NULL DEFAULT '',
            body            TEXT    NOT NULL DEFAULT '',
            created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
            is_read         INTEGER NOT NULL DEFAULT 0,
            read_at         DATETIME,
            reply_body      TEXT    NOT NULL DEFAULT '',
            reply_by        TEXT    NOT NULL DEFAULT '',
            replied_at      DATETIME,
            instructor_seen INTEGER NOT NULL DEFAULT 0
        )");
        // kolumna dodana po pierwszej migracji — ignoruj błąd jeśli już istnieje
        try { db()->exec("ALTER TABLE k30_ti_admin_msgs ADD COLUMN to_admin_id INTEGER NOT NULL DEFAULT 0"); } catch (\Throwable $e) {}
    } catch (\Throwable $e) {}
}

// to_admin_id = 0 → wszyscy admini; > 0 → konkretny admin (users.id)
function ti_admin_msg_send(int $userId, string $userName, string $subject, string $body, int $toAdminId = 0): int {
    ti_admin_msg_migrate();
    $id = db_insert('k30_ti_admin_msgs', [
        'user_id'     => $userId,
        'user_name'   => $userName,
        'to_admin_id' => $toAdminId,
        'subject'     => $subject,
        'body'        => ti_msg_sanitize_html($body),
    ]);
    _ti_admin_msg_notify_admins($userName, $subject ?: 'Nowa wiadomość od prowadzącego', $body, $toAdminId);
    return $id;
}

function ti_admin_msg_reply(int $msgId, string $replyBody, string $replyBy): void {
    ti_admin_msg_migrate();
    try {
        db()->prepare("UPDATE k30_ti_admin_msgs SET reply_body=?, reply_by=?, replied_at=datetime('now'), instructor_seen=0 WHERE id=?")
            ->execute([$replyBody, $replyBy, $msgId]);
        $msg = db_one("SELECT * FROM k30_ti_admin_msgs WHERE id=?", [$msgId]);
        if ($msg) _ti_admin_msg_notify_instructor($msg, $replyBody, $replyBy);
    } catch (\Throwable $e) {}
}

function ti_admin_msg_mark_read(int $msgId): void {
    ti_admin_msg_migrate();
    try { db()->prepare("UPDATE k30_ti_admin_msgs SET is_read=1, read_at=datetime('now') WHERE id=? AND is_read=0")->execute([$msgId]); } catch (\Throwable $e) {}
}

// $toAdminId = -1 → zaznacz wszystkie wątki danego prowadzącego
function ti_admin_msg_mark_instructor_seen(int $userId, int $toAdminId = -1): void {
    ti_admin_msg_migrate();
    try {
        if ($toAdminId === -1) {
            db()->prepare("UPDATE k30_ti_admin_msgs SET instructor_seen=1 WHERE user_id=? AND instructor_seen=0")->execute([$userId]);
        } else {
            db()->prepare("UPDATE k30_ti_admin_msgs SET instructor_seen=1 WHERE user_id=? AND to_admin_id=? AND instructor_seen=0")->execute([$userId, $toAdminId]);
        }
    } catch (\Throwable $e) {}
}

// Zwraca jedną pozycję na wątek (to_admin_id) z datą ostatniej wiadomości i liczbą niewidocznych odpowiedzi
function ti_admin_msg_thread_list(int $userId): array {
    ti_admin_msg_migrate();
    return db_all(
        "SELECT to_admin_id, MAX(created_at) AS last_at,
                SUM(CASE WHEN replied_at IS NOT NULL AND instructor_seen=0 THEN 1 ELSE 0 END) AS unseen,
                COUNT(*) AS msg_count
         FROM k30_ti_admin_msgs WHERE user_id=?
         GROUP BY to_admin_id ORDER BY last_at DESC",
        [$userId]
    );
}

// Pełny wątek z konkretnym adminem (lub do wszystkich gdy to_admin_id=0)
function ti_admin_msg_list_for_thread(int $userId, int $toAdminId): array {
    ti_admin_msg_migrate();
    return db_all("SELECT * FROM k30_ti_admin_msgs WHERE user_id=? AND to_admin_id=? ORDER BY created_at ASC", [$userId, $toAdminId]);
}

function ti_admin_msg_unseen_total(int $userId): int {
    ti_admin_msg_migrate();
    return (int)(db_one("SELECT COUNT(*) c FROM k30_ti_admin_msgs WHERE user_id=? AND replied_at IS NOT NULL AND instructor_seen=0", [$userId])['c'] ?? 0);
}

function ti_admin_msg_list_all(int $limit = 200): array {
    ti_admin_msg_migrate();
    return db_all("SELECT * FROM k30_ti_admin_msgs ORDER BY created_at DESC LIMIT ?", [$limit]);
}

function ti_admin_msg_unread_count(): int {
    ti_admin_msg_migrate();
    return (int)(db_one("SELECT COUNT(*) c FROM k30_ti_admin_msgs WHERE is_read=0")['c'] ?? 0);
}

// Zwraca etykietę wątku dla danego to_admin_id
function ti_admin_thread_label(int $toAdminId, array $adminUsers = []): string {
    if ($toAdminId === -1) return TI_KIS_NAME . ' (Kierownik)';
    if ($toAdminId === 0)  return 'Administratorzy (wszyscy)';
    foreach ($adminUsers as $a) {
        if ((int)$a['id'] === $toAdminId) return (string)$a['name'];
    }
    return 'Admin #' . $toAdminId;
}

// to_admin_id: -1 = KIS; 0 = wszyscy admini; > 0 = konkretny admin (users.id)
function _ti_admin_msg_notify_admins(string $fromName, string $subject, string $body, int $toAdminId = 0): void {
    try {
        if ($toAdminId === -1) {
            $admins = [['name' => TI_KIS_NAME, 'email' => TI_KIS_EMAIL]];
        } elseif ($toAdminId > 0) {
            $admins = db_all("SELECT name, email FROM users WHERE id=? AND is_active=1", [$toAdminId]);
        } else {
            $admins = db_all("SELECT name, email FROM users WHERE role='admin' AND is_active=1 AND email IS NOT NULL AND email!=''");
        }
        if (!$admins) return;
        if (!function_exists('mail_queue_add')) @require_once __DIR__ . '/mail_queue.php';
        if (!function_exists('mail_queue_add')) return;
        $org     = defined('ORG_NAME') ? ORG_NAME : 'Panel TI';
        $preview = mb_substr(trim(strip_tags($body)), 0, 300);
        $url     = (defined('APP_URL') ? rtrim(APP_URL, '/') : '') . '/admin/ti_admin_msgs.php';
        $html    = '<p>Nowa wiadomość od prowadzącego <strong>' . htmlspecialchars($fromName, ENT_QUOTES) . '</strong>:</p>'
                 . '<blockquote style="border-left:3px solid #2563eb;padding-left:1em;color:#444">' . nl2br(htmlspecialchars($preview, ENT_QUOTES)) . '</blockquote>'
                 . '<p><a href="' . htmlspecialchars($url, ENT_QUOTES) . '">Przejdź do wiadomości &rarr;</a></p>'
                 . '<p>Pozdrawiamy,<br>' . htmlspecialchars($org, ENT_QUOTES) . '</p>';
        foreach ($admins as $a) {
            $email = trim((string)($a['email'] ?? ''));
            if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
            try { mail_queue_add($email, (string)($a['name'] ?? ''), "{$org}: {$subject}", $html, '', 'ti_admin_msg'); } catch (\Throwable $e) {}
        }
    } catch (\Throwable $e) {}
}

function _ti_admin_msg_notify_instructor(array $msg, string $replyBody, string $replyBy): void {
    try {
        $user = db_one("SELECT email, name FROM users WHERE id=?", [(int)$msg['user_id']]);
        if (!$user) return;
        $email = trim((string)($user['email'] ?? ''));
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) return;
        if (!function_exists('mail_queue_add')) @require_once __DIR__ . '/mail_queue.php';
        if (!function_exists('mail_queue_add')) return;
        $org     = defined('ORG_NAME') ? ORG_NAME : 'Panel TI';
        $preview = mb_substr(trim(strip_tags($replyBody)), 0, 300);
        $html    = '<p>Drogi/a ' . htmlspecialchars((string)($user['name'] ?? ''), ENT_QUOTES) . ',</p>'
                 . '<p>Kierownictwo odpowiedziało na Twoją wiadomość:</p>'
                 . '<blockquote style="border-left:3px solid #16a34a;padding-left:1em;color:#444">' . nl2br(htmlspecialchars($preview, ENT_QUOTES)) . '</blockquote>'
                 . '<p>Odpowiedź: <strong>' . htmlspecialchars($replyBy, ENT_QUOTES) . '</strong></p>'
                 . '<p>Pozdrawiamy,<br>' . htmlspecialchars($org, ENT_QUOTES) . '</p>';
        try { mail_queue_add($email, (string)($user['name'] ?? ''), "{$org}: odpowiedź na Twoją wiadomość", $html, '', 'ti_admin_msg'); } catch (\Throwable $e) {}
    } catch (\Throwable $e) {}
}

// ══════════════════════════════════════════════════════════════════════════════
// Wiadomość kursanta/prowadzącego → sekretariat / KIS (e-mail, bez przechowywania)
// ══════════════════════════════════════════════════════════════════════════════

function ti_secretariat_send(string $fromName, string $body, int $studentId = 0, string $toEmail = TI_KIS_EMAIL, string $toName = TI_KIS_NAME): bool {
    if (!$toEmail || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) return false;
    if (!function_exists('mail_queue_add')) @require_once __DIR__ . '/mail_queue.php';
    if (!function_exists('mail_queue_add')) return false;
    $org     = defined('ORG_NAME') ? ORG_NAME : 'Panel kursanta';
    $preview = mb_substr(trim(strip_tags($body)), 0, 1000);
    $html    = '<p>Wiadomość od <strong>' . htmlspecialchars($fromName, ENT_QUOTES) . '</strong>'
             . ($studentId ? ' (ID: ' . $studentId . ')' : '') . ':</p>'
             . '<blockquote style="border-left:3px solid #2563eb;padding-left:1em;color:#444">' . nl2br(htmlspecialchars($preview, ENT_QUOTES)) . '</blockquote>'
             . '<p>— ' . htmlspecialchars($org, ENT_QUOTES) . '</p>';
    try {
        mail_queue_add($toEmail, $toName, "{$org}: wiadomość od kursanta — {$fromName}", $html, '', 'ti_secretariat');
        return true;
    } catch (\Throwable $e) { return false; }
}
