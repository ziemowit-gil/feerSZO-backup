<?php
/**
 * Mail Queue — kolejka asynchronicznych powiadomień e-mail.
 *
 * Tabela mail_queue tworzona przez auto-migrację poniżej.
 * Wywołaj mail_queue_process() z cron/mail_queue.php lub z admina.
 */

// ── Auto-migracja ─────────────────────────────────────────────────────────────
(function () {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo  = db();

    $pdo->exec("CREATE TABLE IF NOT EXISTS mail_queue (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        to_email     TEXT    NOT NULL,
        to_name      TEXT    NOT NULL DEFAULT '',
        subject      TEXT    NOT NULL,
        body_html    TEXT    NOT NULL DEFAULT '',
        body_text    TEXT    NOT NULL DEFAULT '',
        context_type TEXT    NOT NULL DEFAULT '',
        context_id   INTEGER,
        status       TEXT    NOT NULL DEFAULT 'pending',
        retry_count  INTEGER NOT NULL DEFAULT 0,
        last_error   TEXT    NOT NULL DEFAULT '',
        scheduled_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        sent_at      DATETIME,
        created_at   DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    try {
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_mq_status ON mail_queue(status,scheduled_at)");
    } catch (\Throwable $e) {}
})();

// ── API ───────────────────────────────────────────────────────────────────────

function mail_queue_add(
    string $to_email,
    string $to_name,
    string $subject,
    string $body_html,
    string $body_text    = '',
    string $context_type = '',
    ?int   $context_id   = null,
    string $scheduled_at = ''
): int {
    if (!$body_text) {
        $body_text = strip_tags(str_replace(['</p>','</div>','<br>','<br/>','<br />'], "\n", $body_html));
        $body_text = preg_replace('/[ \t]+/', ' ', $body_text);
        $body_text = trim($body_text);
    }
    db()->prepare(
        "INSERT INTO mail_queue (to_email,to_name,subject,body_html,body_text,context_type,context_id,scheduled_at)
         VALUES (?,?,?,?,?,?,?,?)"
    )->execute([
        $to_email, $to_name, $subject, $body_html, $body_text,
        $context_type, $context_id,
        $scheduled_at ?: date('Y-m-d H:i:s'),
    ]);
    $mail_id = (int)db()->lastInsertId();

    // Jeśli brak konfiguracji SMTP/M365 — wyślij od razu przez PHP mail()
    // żeby wiadomość nie utknęła w kolejce gdy cron nie działa
    $has_smtp  = (bool)_mail_setting('smtp_host');
    $has_m365  = (bool)_mail_setting('m365_sender_user_id');
    if (!$has_smtp && !$has_m365 && !$scheduled_at) {
        try {
            $msg = db_one("SELECT * FROM mail_queue WHERE id=?", [$mail_id]);
            if ($msg && _mail_send_native($msg)) {
                db()->prepare("UPDATE mail_queue SET status='sent',sent_at=datetime('now') WHERE id=?")->execute([$mail_id]);
            }
        } catch (\Throwable $e) {
            // Zostaje w kolejce do następnego uruchomienia crona
        }
    }

    return $mail_id;
}

/**
 * Przetwarza kolejkę — wysyła do $batch_size wiadomości.
 * Zwraca ['sent'=>N, 'failed'=>N].
 */
function mail_queue_process(int $batch_size = 20): array {
    $pending = db_all(
        "SELECT * FROM mail_queue WHERE status='pending' AND scheduled_at <= datetime('now')
         ORDER BY scheduled_at LIMIT ?", [$batch_size]
    );

    $sent = $failed = 0;
    foreach ($pending as $msg) {
        db()->prepare("UPDATE mail_queue SET status='sending' WHERE id=?")->execute([$msg['id']]);
        try {
            $ok = _mail_send($msg);
            if ($ok) {
                db()->prepare("UPDATE mail_queue SET status='sent',sent_at=datetime('now') WHERE id=?")->execute([$msg['id']]);
                $sent++;
            } else {
                throw new \RuntimeException('mail() zwróciło false');
            }
        } catch (\Throwable $e) {
            $retry = (int)$msg['retry_count'] + 1;
            $new_status = $retry >= 5 ? 'failed' : 'pending';
            // Exponential backoff: 5, 15, 60, 240 min
            $delays = [0, 5, 15, 60, 240];
            $delay  = $delays[min($retry, 4)];
            db()->prepare(
                "UPDATE mail_queue SET status=?,retry_count=?,last_error=?,
                 scheduled_at=datetime('now','+'||?||' minutes') WHERE id=?"
            )->execute([$new_status, $retry, $e->getMessage(), $delay, $msg['id']]);
            $failed++;
        }
    }
    return ['sent' => $sent, 'failed' => $failed];
}

function mail_queue_stats(): array {
    $rows = db_all("SELECT status, COUNT(*) AS cnt FROM mail_queue GROUP BY status");
    $out  = ['pending' => 0, 'sending' => 0, 'sent' => 0, 'failed' => 0, 'cancelled' => 0, 'total' => 0];
    foreach ($rows as $r) {
        $out[$r['status']] = (int)$r['cnt'];
        $out['total'] += (int)$r['cnt'];
    }
    return $out;
}

function mail_queue_cancel(int $id): void {
    db()->prepare("UPDATE mail_queue SET status='cancelled' WHERE id=? AND status IN ('pending','failed')")->execute([$id]);
}

function mail_queue_retry(int $id): void {
    db()->prepare("UPDATE mail_queue SET status='pending',retry_count=0,last_error='',scheduled_at=datetime('now') WHERE id=?")->execute([$id]);
}

function mail_queue_all(string $status = '', int $limit = 200): array {
    $where  = $status ? "WHERE status=?" : "";
    $params = $status ? [$status] : [];
    return db_all("SELECT * FROM mail_queue $where ORDER BY created_at DESC LIMIT $limit", $params);
}

// ── Wysyłka (wewnętrzna) ──────────────────────────────────────────────────────

/**
 * Wysyła wiadomość z kolejki. Priorytet:
 *  1. Microsoft 365 Graph API (jeśli skonfigurowane)
 *  2. SMTP (jeśli smtp_host skonfigurowany)
 *  3. PHP mail() — ostatni fallback
 */
function _mail_send(array $msg): bool {
    // 1. M365 Graph API
    try {
        if (_mail_m365_configured()) {
            return _mail_send_m365($msg);
        }
    } catch (\Throwable $e) {
        // loguj i spróbuj SMTP
        error_log('[mail] M365 failed: ' . $e->getMessage());
    }

    // 2. SMTP
    try {
        $smtp_host = _mail_setting('smtp_host');
        if ($smtp_host) {
            return _mail_send_smtp($msg, $smtp_host);
        }
    } catch (\Throwable $e) {
        error_log('[mail] SMTP failed: ' . $e->getMessage());
    }

    // 3. PHP mail()
    return _mail_send_native($msg);
}

function _mail_setting(string $key): string {
    try {
        $r = db_one("SELECT value FROM settings WHERE key_=?", [$key]);
        return $r['value'] ?? '';
    } catch (\Throwable $e) { return ''; }
}

function _mail_from(): string {
    // M365 send_from ma priorytet
    $m365from = _mail_setting('m365_send_from_email');
    if ($m365from && _mail_m365_configured()) return $m365from;

    $smtp = _mail_setting('smtp_from_email') ?: _mail_setting('notify_from_email');
    if ($smtp) return $smtp;

    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return "no-reply@{$host}";
}

function _mail_from_name(): string {
    $n = _mail_setting('notify_from_name');
    if ($n) return $n;
    return defined('ORG_NAME') ? ORG_NAME : 'System';
}

// ── M365 Graph API ────────────────────────────────────────────────────────────

function _mail_m365_configured(): bool {
    return (bool)(_mail_setting('m365_send_from_email')
        && _mail_setting('m365_tenant_id')
        && _mail_setting('m365_graph_client_id')
        && _mail_setting('m365_graph_client_secret'));
}

function _mail_send_m365(array $msg): bool {
    $tenant   = _mail_setting('m365_tenant_id');
    $client   = _mail_setting('m365_graph_client_id');
    $secret   = _mail_setting('m365_graph_client_secret');
    $from     = _mail_setting('m365_send_from_email');

    // Pobierz token
    $tok_resp = _mail_http_post(
        "https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/token",
        http_build_query([
            'grant_type'    => 'client_credentials',
            'client_id'     => $client,
            'client_secret' => $secret,
            'scope'         => 'https://graph.microsoft.com/.default',
        ]),
        ['Content-Type: application/x-www-form-urlencoded']
    );
    $tok = json_decode($tok_resp, true);
    if (empty($tok['access_token'])) {
        throw new \RuntimeException('Brak tokenu M365: ' . $tok_resp);
    }
    $access_token = $tok['access_token'];

    // Buduj payload sendMail
    $payload = [
        'message' => [
            'subject' => $msg['subject'],
            'body'    => [
                'contentType' => 'HTML',
                'content'     => $msg['body_html'] ?: nl2br(htmlspecialchars($msg['body_text'])),
            ],
            'toRecipients' => [[
                'emailAddress' => [
                    'address' => $msg['to_email'],
                    'name'    => $msg['to_name'] ?: $msg['to_email'],
                ],
            ]],
            'from' => [
                'emailAddress' => [
                    'address' => $from,
                    'name'    => _mail_from_name(),
                ],
            ],
        ],
        'saveToSentItems' => false,
    ];

    $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $resp = _mail_http_post(
        "https://graph.microsoft.com/v1.0/users/" . urlencode($from) . "/sendMail",
        $body,
        [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $access_token,
        ]
    );
    // Graph API zwraca 202 Accepted bez body → sukces gdy brak błędu
    $decoded = json_decode($resp, true);
    if (!empty($decoded['error'])) {
        throw new \RuntimeException('Graph sendMail error: ' . json_encode($decoded['error']));
    }
    return true;
}

// ── SMTP ──────────────────────────────────────────────────────────────────────

function _mail_send_smtp(array $msg, string $host): bool {
    $port       = (int)(_mail_setting('smtp_port') ?: 587);
    $user       = _mail_setting('smtp_user');
    $pass       = _mail_setting('smtp_pass');
    $encryption = strtolower(_mail_setting('smtp_encryption') ?: 'tls'); // tls|ssl|none
    $from       = _mail_from();
    $from_name  = _mail_from_name();

    // Ustaw timeout i połącz
    $timeout = 15;
    $addr = ($encryption === 'ssl') ? "ssl://{$host}" : "tcp://{$host}";
    $errno = $errstr = null;
    $sock = stream_socket_client("{$addr}:{$port}", $errno, $errstr, $timeout);
    if (!$sock) throw new \RuntimeException("SMTP connect failed: {$errstr} ({$errno})");

    stream_set_timeout($sock, $timeout);

    $read = fn() => fgets($sock, 512);
    $send = function(string $cmd) use ($sock, $read): string {
        fwrite($sock, $cmd . "\r\n");
        $line = '';
        do {
            $line = fgets($sock, 512);
        } while ($line && strlen($line) > 3 && $line[3] === '-');
        return $line;
    };

    $greet = $read(); // 220

    // EHLO / STARTTLS
    $ehlo = _mail_smtp_domain();
    $send("EHLO {$ehlo}");

    if ($encryption === 'tls') {
        $r = $send("STARTTLS");
        if (!str_starts_with(trim($r), '220')) throw new \RuntimeException("STARTTLS failed: {$r}");
        stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        $send("EHLO {$ehlo}");
    }

    // AUTH LOGIN
    if ($user) {
        $r = $send("AUTH LOGIN");
        if (!str_starts_with(trim($r), '334')) throw new \RuntimeException("AUTH LOGIN failed: {$r}");
        $send(base64_encode($user));
        $r = $send(base64_encode($pass));
        if (!str_starts_with(trim($r), '235')) throw new \RuntimeException("AUTH failed: {$r}");
    }

    // Koperta
    $send("MAIL FROM:<{$from}>");
    $send("RCPT TO:<{$msg['to_email']}>");
    $send("DATA");

    // Buduj wiadomość
    $boundary = 'MP_' . md5(uniqid());
    $date     = date('r');
    $to_enc   = $msg['to_name']
        ? "=?UTF-8?B?" . base64_encode($msg['to_name']) . "?= <{$msg['to_email']}>"
        : $msg['to_email'];
    $from_enc = "=?UTF-8?B?" . base64_encode($from_name) . "?= <{$from}>";
    $subj_enc = "=?UTF-8?B?" . base64_encode($msg['subject']) . "?=";

    $data  = "Date: {$date}\r\n";
    $data .= "From: {$from_enc}\r\n";
    $data .= "To: {$to_enc}\r\n";
    $data .= "Subject: {$subj_enc}\r\n";
    $data .= "MIME-Version: 1.0\r\n";
    $data .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
    $data .= "\r\n";

    if ($msg['body_text']) {
        $data .= "--{$boundary}\r\n";
        $data .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $data .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $data .= chunk_split(base64_encode($msg['body_text'])) . "\r\n";
    }
    $data .= "--{$boundary}\r\n";
    $data .= "Content-Type: text/html; charset=UTF-8\r\n";
    $data .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $data .= chunk_split(base64_encode($msg['body_html'] ?: nl2br(htmlspecialchars($msg['body_text'])))) . "\r\n";
    $data .= "--{$boundary}--\r\n";
    $data .= ".";

    $r = $send($data);
    $send("QUIT");
    fclose($sock);

    if (!str_starts_with(trim($r), '250')) throw new \RuntimeException("SMTP DATA error: {$r}");
    return true;
}

function _mail_smtp_domain(): string {
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return preg_replace('/:\d+$/', '', $host);
}

// ── PHP mail() fallback ───────────────────────────────────────────────────────

function _mail_send_native(array $msg): bool {
    $from_name  = _mail_from_name();
    $from_email = _mail_from();

    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: =?UTF-8?B?" . base64_encode($from_name) . "?= <{$from_email}>\r\n";
    $headers .= "Reply-To: {$from_email}\r\n";
    $headers .= "X-Mailer: RejestrUmow/2026\r\n";

    $to      = $msg['to_name']
        ? "=?UTF-8?B?" . base64_encode($msg['to_name']) . "?= <{$msg['to_email']}>"
        : $msg['to_email'];
    $subject = "=?UTF-8?B?" . base64_encode($msg['subject']) . "?=";

    return mail($to, $subject, $msg['body_html'], $headers);
}

// ── HTTP helper ───────────────────────────────────────────────────────────────

function _mail_http_post(string $url, string $body, array $headers = []): string {
    $ctx = stream_context_create(['http' => [
        'method'        => 'POST',
        'header'        => implode("\r\n", $headers),
        'content'       => $body,
        'timeout'       => 20,
        'ignore_errors' => true,
    ]]);
    $resp = file_get_contents($url, false, $ctx);
    return $resp ?: '';
}
