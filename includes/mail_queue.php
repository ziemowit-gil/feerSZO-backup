<?php
/**
 * Mail Queue — kolejka asynchronicznych powiadomień e-mail.
 *
 * SQLite = źródło prawdy (historia, retry, anulowanie, panel admina).
 * RabbitMQ = kanał dostarczania: po INSERT publikowany jest mail_id,
 *            konsumer crona pobiera wiadomość z SQLite po id i wysyła.
 *
 * Gdy RabbitMQ jest niedostępny, mail_queue_process() odpada do
 * pollingu SQLite — kolejka działa bez przerwy.
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
    // Kolumna attachments (JSON) — dodana w v1.6
    try { $pdo->exec("ALTER TABLE mail_queue ADD COLUMN attachments TEXT NOT NULL DEFAULT '[]'"); } catch (\Throwable $e) {}
    // Nadawca nadrzędny (np. skrzynka zalogowanego usera M365) — v1.9
    try { $pdo->exec("ALTER TABLE mail_queue ADD COLUMN from_email TEXT NOT NULL DEFAULT ''"); } catch (\Throwable $e) {}
    // DW / UDW (JSON array of email strings) — v2.0
    try { $pdo->exec("ALTER TABLE mail_queue ADD COLUMN cc_emails  TEXT NOT NULL DEFAULT '[]'"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE mail_queue ADD COLUMN bcc_emails TEXT NOT NULL DEFAULT '[]'"); } catch (\Throwable $e) {}
    // Dodatkowe nagłówki wiadomości (JSON obiekt nazwa=>wartość) — v2.1.
    // Powód: List-Unsubscribe / List-Unsubscribe-Post (RFC 8058). Gmail i Yahoo
    // od 2024 wymagają jednoklikowego wypisu przy wysyłkach masowych.
    try { $pdo->exec("ALTER TABLE mail_queue ADD COLUMN headers TEXT"); } catch (\Throwable $e) {}
    // Tor wysyłki (2026-11-14, odejście od M365): '' = zwykła kolejność, 'smtp' = bez Microsoft 365 (SMTP 1 → SMTP 2),
    // 'user:<id>' = wyłącznie własna skrzynka użytkownika przez SMTP dPoczta (modules/userSmtp). Ustawia mail_queue_add().
    try { $pdo->exec("ALTER TABLE mail_queue ADD COLUMN transport TEXT NOT NULL DEFAULT ''"); } catch (\Throwable $e) {}
})();

/**
 * Tor wysyłki dla korespondencji CRM (context_type crm*): Konto Fundacji → 'smtp' (ustawienia SMTP z Ustawienia → Poczta,
 * bez Microsoft 365); własna skrzynka (from_email = skrzynka dPoczta zalogowanego, z zapisanym hasłem) → 'user:<id>'.
 * Inne konteksty → '' (bez zmian).
 */
function _mail_queue_transport(string $context_type, string $from_email): string {
    if (!str_starts_with($context_type, 'crm')) return '';
    $from = strtolower(trim($from_email));
    if ($from !== '' && function_exists('current_user') && ($u = current_user())) {
        try {
            require_once dirname(__DIR__) . '/modules/userSmtp/lib/UserSmtp.php';
            $acc = UserSmtp::get((int)$u['id']);
            if ($acc && strtolower((string)$acc['email']) === $from) return 'user:' . (int)$u['id'];
        } catch (\Throwable $e) { error_log('[mail_queue] transport: ' . $e->getMessage()); }
    }
    return 'smtp';
}

// ── API ───────────────────────────────────────────────────────────────────────

/**
 * Dodaje e-mail do kolejki.
 *
 * Jeśli wiadomość jest krótka (< 50 KB) i nie ma zaplanowanego czasu wysyłki,
 * system próbuje ją wysłać natychmiast — bez czekania na cron.
 * Przy błędzie wiadomość zostaje w kolejce do ponowienia.
 *
 * @param bool $immediate  Wymuś natychmiastową wysyłkę niezależnie od rozmiaru.
 */
/**
 * Zapisuje przesłany plik jako załącznik CRM.
 * Zwraca ['path'=>string, 'name'=>string, 'mime'=>string, 'size'=>int] lub null przy błędzie.
 */
function mail_queue_save_attachment(array $uploaded_file): ?array {
    $dir = UPLOAD_DIR . 'crm_attachments/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    if ($uploaded_file['error'] !== UPLOAD_ERR_OK) return null;
    if ($uploaded_file['size'] > 15 * 1024 * 1024) return null; // max 15 MB

    $orig_name = basename($uploaded_file['name']);
    $ext       = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));

    // Dozwolone typy
    $allowed = ['pdf','doc','docx','xls','xlsx','csv','txt','png','jpg','jpeg','gif','zip','rar','7z','odt','ods'];
    if (!in_array($ext, $allowed, true)) return null;

    $stored = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $dest   = $dir . $stored;

    if (!move_uploaded_file($uploaded_file['tmp_name'], $dest)) return null;

    $mime = mime_content_type($dest) ?: 'application/octet-stream';

    return [
        'path' => 'crm_attachments/' . $stored,
        'name' => $orig_name,
        'mime' => $mime,
        'size' => filesize($dest),
    ];
}

/**
 * Rozkłada string z adresami (przecinki/średniki/spacje) na tablicę walidowanych adresów.
 */
function mail_parse_addr_list(string $raw): array {
    $out = [];
    foreach (preg_split('/[\s,;]+/', trim($raw)) as $e) {
        $e = trim($e);
        if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)) {
            $out[] = $e;
        }
    }
    return array_values(array_unique($out));
}

/**
 * Zamiana treści HTML e-maila na czytelny zwykły tekst (text/plain).
 * Linki: „etykieta: URL” (etykieta będąca adresem lub pusta → sam URL; mailto → sam adres),
 * bloki/<br> → nowe linie, encje zdekodowane, puste linie zredukowane do jednej,
 * a linia będąca wyłącznie URL-em, który już wystąpił wyżej, jest pomijana
 * (przycisk „Otwórz →” + podpis z adresem w HTML dawały dwa razy ten sam link).
 */
function mail_html_to_text(string $html): string {
    $t = preg_replace('~<(script|style)\b.*?</\1>~is', '', $html);
    $t = preg_replace('/\s*\r?\n\s*/', ' ', $t);   // łamania wierszy w źródle HTML nie są łamaniami tekstu
    $seen_href = [];
    $t = preg_replace_callback(
        '~<a\b[^>]*href="([^"]+)"[^>]*>(.*?)</a>~is',
        function ($m) use (&$seen_href) {
            $href  = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
            $label = trim(html_entity_decode(strip_tags($m[2]), ENT_QUOTES, 'UTF-8'));
            $label = rtrim(preg_replace('/\s*(→|->|»)\s*$/u', '', $label));
            if (str_starts_with($href, 'mailto:')) return substr($href, 7);
            if ($label === '' || $label === $href || preg_match('~^https?://~i', $label)) { $seen_href[$href] = true; return $href; }
            if (isset($seen_href[$href])) return $label;          // ten sam link drugi raz → sam tekst
            $seen_href[$href] = true;
            return $label . ': ' . $href;
        },
        $t
    );
    $t = preg_replace('~<(br|br/|br /)\s*>~i', "\n", $t);
    $t = preg_replace('~</(p|div|li|tr|h[1-6]|table|blockquote)>~i', "\n\n", $t);
    $t = preg_replace('~<li\b[^>]*>~i', '- ', $t);
    $t = strip_tags($t);
    $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $t = str_replace("\xC2\xA0", ' ', $t);
    $lines = [];
    $seen_urls = [];
    foreach (preg_split("/\r?\n/", $t) as $line) {
        $line = trim(preg_replace('/[ \t]+/', ' ', $line));
        if (preg_match('~^https?://\S+$~', $line)) {
            if (isset($seen_urls[$line])) continue;
            $seen_urls[$line] = true;
        } else {
            foreach (preg_match_all('~https?://\S+~', $line, $mm) ? $mm[0] : [] as $u) $seen_urls[rtrim($u, '.,;)')] = true;
        }
        $lines[] = $line;
    }
    $t = implode("\n", $lines);
    $t = preg_replace("/\n{3,}/", "\n\n", $t);
    return trim($t);
}

function mail_queue_add(
    string $to_email,
    string $to_name,
    string $subject,
    string $body_html,
    string $body_text    = '',
    string $context_type = '',
    ?int   $context_id   = null,
    string $scheduled_at = '',
    bool   $immediate    = false,
    array  $attachments  = [],  // [['path'=>..., 'name'=>..., 'mime'=>..., 'size'=>...], ...]
    string $from_email   = '',  // nadawca nadrzędny (np. skrzynka usera M365); '' = nadawca systemowy
    array  $cc_emails    = [],  // DW — Do Wiadomości
    array  $bcc_emails   = [],  // UDW — Ukryta Do Wiadomości
    array  $headers      = []   // dodatkowe nagłówki, np. List-Unsubscribe
): int {
    require_once __DIR__ . '/ti_virtual.php';
    if (ti_virtual_blocked($to_email)) return 0;   // kursant wirtualny — bez e-maili
    if (!$body_text) $body_text = mail_html_to_text($body_html);
    $att_json = $attachments ? json_encode($attachments, JSON_UNESCAPED_UNICODE) : '[]';
    $cc_json  = $cc_emails   ? json_encode($cc_emails,   JSON_UNESCAPED_UNICODE) : '[]';
    $bcc_json = $bcc_emails  ? json_encode($bcc_emails,  JSON_UNESCAPED_UNICODE) : '[]';
    $hdr_json = $headers     ? json_encode($headers,     JSON_UNESCAPED_UNICODE) : null;
    db()->prepare(
        "INSERT INTO mail_queue (to_email,to_name,subject,body_html,body_text,context_type,context_id,scheduled_at,attachments,from_email,cc_emails,bcc_emails,headers,transport)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
    )->execute([
        $to_email, $to_name, $subject, $body_html, $body_text,
        $context_type, $context_id,
        $scheduled_at ?: date('Y-m-d H:i:s'),
        $att_json, $from_email, $cc_json, $bcc_json, $hdr_json,
        _mail_queue_transport($context_type, $from_email),
    ]);
    $mail_id = (int)db()->lastInsertId();

    // Natychmiastowa wysyłka gdy:
    //  a) flaga $immediate = true
    //  b) mała wiadomość (< 50 KB treści HTML) i brak zaplanowanego czasu
    $is_small = strlen($body_html ?: $body_text) < 51200;
    $sent_immediately = false;
    if (!$scheduled_at && ($immediate || $is_small)) {
        try {
            $msg = db_one("SELECT * FROM mail_queue WHERE id=?", [$mail_id]);
            if ($msg) {
                $tr0 = count(mail_trace());
                $ok = _mail_send($msg);
                if ($ok) {
                    db()->prepare("UPDATE mail_queue SET status='sent',sent_at=datetime('now') WHERE id=?")->execute([$mail_id]);
                    $sent_immediately = true;
                }
                _mail_trace_store($mail_id, array_slice(mail_trace(), $tr0));
            }
        } catch (\Throwable $e) {
            // Przy błędzie zostaje status='pending' — RabbitMQ / cron ponowi
            db()->prepare("UPDATE mail_queue SET last_error=? WHERE id=?")->execute([$e->getMessage(), $mail_id]);
        }
    }

    // Jeśli nie wysłano natychmiast → opublikuj id w kolejce RabbitMQ
    if (!$sent_immediately) {
        _mail_queue_publish($mail_id);
    }

    return $mail_id;
}

/**
 * Przetwarza kolejkę — konsumuje z RabbitMQ (lub odpada do SQLite polling).
 * Zwraca ['sent'=>N, 'failed'=>N, 'source'=>'rabbitmq'|'db'].
 */
function mail_queue_process(int $batch_size = 20): array
{
    // Próba konsumpcji z RabbitMQ
    try {
        if (file_exists(__DIR__ . '/rabbitmq.php')) {
            require_once __DIR__ . '/rabbitmq.php';
        }
        $sent = $failed = 0;
        rabbit_consume_batch('mail.send', function (array $payload) use (&$sent, &$failed): bool {
            $mail_id = (int)($payload['id'] ?? 0);
            if (!$mail_id) return true; // odrzuć uszkodzoną wiadomość

            $msg = db_one("SELECT * FROM mail_queue WHERE id=? AND status='pending'", [$mail_id]);
            if (!$msg) return true; // już wysłana lub anulowana

            return _mail_queue_send_one($msg, $sent, $failed);
        }, $batch_size);

        return ['sent' => $sent, 'failed' => $failed, 'source' => 'rabbitmq'];
    } catch (\Throwable $e) {
        error_log('[mail_queue] RabbitMQ niedostępny, fallback na SQLite: ' . $e->getMessage());
    }

    // Fallback: polling SQLite
    return mail_queue_process_db($batch_size);
}

/**
 * Procesuje kolejkę wyłącznie przez polling SQLite (fallback / tryb bez RabbitMQ).
 */
function mail_queue_process_db(int $batch_size = 20): array
{
    $pending = db_all(
        "SELECT * FROM mail_queue WHERE status='pending' AND scheduled_at <= datetime('now')
         ORDER BY scheduled_at LIMIT ?", [$batch_size]
    );

    $sent = $failed = 0;
    foreach ($pending as $msg) {
        _mail_queue_send_one($msg, $sent, $failed);
    }
    return ['sent' => $sent, 'failed' => $failed, 'source' => 'db'];
}

/** Wysyła jedną wiadomość i aktualizuje status w SQLite. Modyfikuje $sent/$failed przez referencję. */
function _mail_queue_send_one(array $msg, int &$sent, int &$failed): bool
{
    // Claim atomowy — dwa crony (mail_queue.php co 5 min + bulk_email_process.php co minutę)
    // mogą pobrać ten sam pending batch na nakładającym się ticku; warunek AND status='pending'
    // gwarantuje, że tylko jeden proces faktycznie wyśle daną wiadomość.
    $claimed = db()->prepare("UPDATE mail_queue SET status='sending' WHERE id=? AND status='pending'");
    $claimed->execute([$msg['id']]);
    if ($claimed->rowCount() === 0) return false;
    try {
        $tr0 = count(mail_trace());
        $ok = _mail_send($msg);
        if ($ok) {
            db()->prepare("UPDATE mail_queue SET status='sent',sent_at=datetime('now') WHERE id=?")->execute([$msg['id']]);
            _mail_trace_store((int)$msg['id'], array_slice(mail_trace(), $tr0));
            $sent++;
            return true;
        }
        $tt = mail_trace_text(array_slice(mail_trace(), $tr0));
        throw new \RuntimeException($tt !== '' ? $tt : 'mail() zwróciło false');
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
        // Przy retry < 5 wróć do RabbitMQ — cron ponowi
        if ($new_status === 'pending') {
            _mail_queue_publish((int)$msg['id']);
        }
        return false;
    }
}

/** Publikuje mail_id do RabbitMQ; cicho ignoruje błąd (wiadomość zostaje w SQLite pending). */
function _mail_queue_publish(int $mail_id): void
{
    try {
        if (file_exists(__DIR__ . '/rabbitmq.php')) {
            require_once __DIR__ . '/rabbitmq.php';
        }
        rabbit_publish('mail.send', ['id' => $mail_id]);
    } catch (\Throwable $e) {
        error_log('[mail_queue] publish RabbitMQ failed (id=' . $mail_id . '): ' . $e->getMessage());
    }
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
 *  0. wiadomość systemowa przy system_mail_transport=smtp → SMTP1 (przy błędzie dalej jak niżej)
 *  1. Microsoft 365 Graph API (jeśli skonfigurowane)
 *  2. SMTP1 (smtp_host)
 *  3. SMTP2 (smtp2_host) — zapasowy serwer
 *  4. PHP mail() — ostatni fallback
 */
function _mail_send(array $msg): bool {
    $orig = $msg;
    $msg = _mail_apply_system_sender($msg);
    // Własny adres odpowiedzi z nagłówków wiadomości (np. powiadomienia Zadań: odpowiedź → komentarz trafia do skrzynki IMAP
    // modułu, 2026-11-13). Ma pierwszeństwo przed Reply-To nadawcy systemowego; nieprawidłowy adres jest pomijany.
    $rt_own = _mail_header_reply_to($msg);
    if ($rt_own !== '') { $msg['_reply_to'] = $rt_own; $orig['_reply_to'] = $rt_own; }
    // Każda próba trafia do mail_trace() (kanał, cel, wynik, błąd) — test w Ustawieniach → Poczta i last_error w kolejce
    // pokazują techniczny przebieg zamiast „sprawdź logi” (polecenie 2026-11-13).
    $try = function (string $via, string $target, callable $fn): ?bool {
        $t = microtime(true);
        try {
            $ok = (bool)$fn();
            mail_trace(['via' => $via, 'target' => $target, 'ok' => $ok, 'error' => $ok ? '' : 'funkcja zwróciła false', 'ms' => (int)round((microtime(true) - $t) * 1000)]);
            return $ok;
        } catch (\Throwable $e) {
            mail_trace(['via' => $via, 'target' => $target, 'ok' => false, 'error' => $e->getMessage(), 'ms' => (int)round((microtime(true) - $t) * 1000)]);
            error_log('[mail] ' . $via . ' failed: ' . $e->getMessage());
            return null;
        }
    };
    $transport = (string)($msg['transport'] ?? '');
    // Własna skrzynka użytkownika (CRM → „Moja skrzynka”, SMTP dPoczta). BEZ zapasowych dróg: wiadomość nie może wyjść
    // z innego konta niż wybrane. Odrzucone logowanie = hasło do poczty zmienione → zapis nieaktualny, trzeba podać ponownie.
    if (str_starts_with($transport, 'user:')) {
        require_once dirname(__DIR__) . '/modules/userSmtp/lib/UserSmtp.php';
        $uid = (int)substr($transport, 5);
        try {
            $cr = UserSmtp::credentials($uid);
        } catch (\Throwable $e) {
            mail_trace(['via' => 'Moja skrzynka (dPoczta)', 'target' => 'konto #' . $uid, 'ok' => false, 'error' => $e->getMessage(), 'ms' => 0]);
            return false;
        }
        $um = $msg;
        $um['_smtp'] = $cr;
        $um['_system'] = false;
        $t = microtime(true);
        try {
            $ok = _mail_send_smtp($um, $cr['host'], 'smtp');
            mail_trace(['via' => 'Moja skrzynka (dPoczta)', 'target' => $cr['host'] . ':' . $cr['port'] . ', STARTTLS, nadawca ' . $cr['from'], 'ok' => $ok, 'error' => $ok ? '' : 'funkcja zwróciła false', 'ms' => (int)round((microtime(true) - $t) * 1000)]);
            return $ok;
        } catch (\Throwable $e) {
            if (UserSmtp::isAuthError($e->getMessage())) UserSmtp::authFailed($uid, $e->getMessage());
            mail_trace(['via' => 'Moja skrzynka (dPoczta)', 'target' => $cr['host'] . ':' . $cr['port'] . ', nadawca ' . $cr['from'], 'ok' => false, 'error' => $e->getMessage(), 'ms' => (int)round((microtime(true) - $t) * 1000)]);
            error_log('[mail] user SMTP #' . $uid . ' failed: ' . $e->getMessage());
            return false;
        }
    }
    // 0. Wiadomości systemowe przez SMTP 1 (Ustawienia → Poczta → „Wysyłka wiadomości systemowych”, 2026-11-13: nowy serwer
    //    poczty, nadawca fundacja@). Błąd SMTP → dalej zwykła kolejność, wiadomość nie ginie.
    if (!empty($msg['_system']) && _mail_setting('system_mail_transport') === 'smtp') {
        $smtp_host = _mail_setting('smtp_host');
        if ($smtp_host && $try('SMTP 1 (wiadomości systemowe)', _mail_smtp_target('smtp', $smtp_host, $msg), fn() => _mail_send_smtp($msg, $smtp_host, 'smtp'))) return true;
    }
    // 1. M365 Graph API — pomijany dla toru 'smtp' (korespondencja CRM z Konta Fundacji idzie przez SMTP z ustawień)
    if ($transport !== 'smtp' && _mail_m365_configured()) {
        $from = trim((string)($msg['from_email'] ?? '')) ?: (_mail_setting('m365_send_from_email') ?: _mail_setting('m365_sender_user_id'));
        if ($try('Microsoft 365', 'Graph sendMail, nadawca ' . $from, fn() => _mail_send_m365($msg))) return true;
        // Skrzynka systemowa niedostępna (brak skrzynki / uprawnień) — wyślij od domyślnego nadawcy, nie gub wiadomości
        if (!empty($msg['_system'])) {
            $from0 = trim((string)($orig['from_email'] ?? '')) ?: (_mail_setting('m365_send_from_email') ?: _mail_setting('m365_sender_user_id'));
            if ($try('Microsoft 365 (domyślny nadawca)', 'Graph sendMail, nadawca ' . $from0, fn() => _mail_send_m365($orig))) return true;
        }
    }

    // 2. SMTP1
    $smtp_host = _mail_setting('smtp_host');
    if ($smtp_host && $try('SMTP 1', _mail_smtp_target('smtp', $smtp_host, $msg), fn() => _mail_send_smtp($msg, $smtp_host, 'smtp'))) return true;

    // 3. SMTP2 (backup)
    $smtp2_host = _mail_setting('smtp2_host');
    if ($smtp2_host && $try('SMTP 2', _mail_smtp_target('smtp2', $smtp2_host, $msg), fn() => _mail_send_smtp($msg, $smtp2_host, 'smtp2'))) return true;

    // 4. PHP mail()
    return (bool)$try('PHP mail()', 'lokalny sendmail serwera', fn() => _mail_send_native($msg));
}

/** Opis celu SMTP do przebiegu: serwer:port, szyfrowanie, login, nadawca (bez hasła). */
function _mail_smtp_target(string $prefix, string $host, array $msg): string {
    $enc  = strtolower(_mail_setting("{$prefix}_encryption") ?: 'tls');
    $user = _mail_setting("{$prefix}_user");
    $from = !empty($msg['_system']) ? (string)$msg['from_email'] : (_mail_setting("{$prefix}_from_email") ?: _mail_from());
    return $host . ':' . (int)(_mail_setting("{$prefix}_port") ?: 587) . ' ' . (['ssl' => 'SSL', 'tls' => 'STARTTLS', 'none' => 'bez szyfrowania'][$enc] ?? $enc)
        . ($user !== '' ? ', login ' . $user : ', bez logowania') . ', nadawca ' . $from;
}

/**
 * Przebieg wysyłek w tym żądaniu: [['via','target','ok','error','ms','at'], …]. $entry dopisuje, $reset czyści.
 */
function mail_trace(?array $entry = null, bool $reset = false): array {
    static $t = [];
    if ($reset) $t = [];
    if ($entry !== null) $t[] = $entry + ['at' => date('H:i:s')];
    return $t;
}

/** Przebieg jako tekst: „SMTP 1 (cel): BŁĄD — … (1234 ms) → Microsoft 365 (cel): wysłano (310 ms)”. */
function mail_trace_text(array $trace): string {
    $out = [];
    foreach ($trace as $e) {
        $out[] = $e['via'] . ' (' . $e['target'] . '): ' . ($e['ok'] ? 'wysłano' : 'BŁĄD — ' . $e['error']) . ' (' . (int)$e['ms'] . ' ms)';
    }
    return implode(' → ', $out);
}

/** Zapisuje przebieg w last_error wiadomości, gdy była choć jedna nieudana próba (także gdy w końcu wyszła inną drogą). */
function _mail_trace_store(int $mail_id, array $mine): void {
    if (!$mine || !array_filter($mine, fn($e) => !$e['ok'])) return;
    try { db()->prepare("UPDATE mail_queue SET last_error=? WHERE id=?")->execute([mb_substr(mail_trace_text($mine), 0, 2000), $mail_id]); }
    catch (\Throwable $e) {}
}

// ── Nadawca wiadomości systemowych ────────────────────────────────────────────
// Ustawienia → Poczta: system_mail_from (pusty = wyłączone), system_mail_from_name, system_mail_reply_to
// (pusty = domyślny nadawca organizacji), mail_correspondence_contexts — rodzaje (context_type) KORESPONDENCJI
// z beneficjentami, klientami, urzędami: te i wiadomości z własnym nadawcą (from_email) wychodzą jak dotąd.
// Gwiazdka na końcu = prefiks (np. crm*).

const MAIL_CORRESPONDENCE_DEFAULT = "crm*\nnewsletter*\nezd\nezd_pismo\npismo_email_sent\nti_message\nti_admin_msg\nti_secretariat\ninvoice*";

/** Wzorce rodzajów korespondencji (z ustawień albo domyślne). */
function mail_correspondence_patterns(): array {
    $raw = _mail_setting('mail_correspondence_contexts');
    if (trim($raw) === '') $raw = MAIL_CORRESPONDENCE_DEFAULT;
    return array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', strtolower($raw)))));
}

/** Czy wiadomość danego rodzaju jest systemowa (powiadomienie, link, kod, przypomnienie, alert), a nie korespondencją. */
function mail_is_system(string $context_type): bool {
    $c = strtolower(trim($context_type));
    foreach (mail_correspondence_patterns() as $p) {
        if (str_ends_with($p, '*') ? str_starts_with($c, rtrim($p, '*')) : $c === $p) return false;
    }
    return true;
}

/**
 * Reply-To dla wiadomości Helpdesku (temat zaczyna się od numeru zgłoszenia, np. [HD-2026-00042], albo context_type helpdesk*):
 * ustawienie hd_mail_reply_to, a gdy puste — adres pierwszej aktywnej skrzynki Helpdesku (modules/helpdeskMail).
 * Dzięki temu odpowiedź na powiadomienie wraca do skrzynki, z której zgłoszenia i odpowiedzi trafiają do konsoli. '' = to nie mail Helpdesku.
 */
function _mail_is_helpdesk(array $msg): bool {
    $ctx = strtolower((string)($msg['context_type'] ?? ''));
    $isHd = str_starts_with($ctx, 'helpdesk');
    if (!$isHd && preg_match('/^\[([A-Z]{2,5}[-\/]?\d{2,4}[-\/]?\d{3,8})\]/', (string)($msg['subject'] ?? ''), $m)) {
        // numer w nawiasie musi być numerem istniejącego zgłoszenia Helpdesku (inaczej np. [CRM/0001/2026] byłoby błędnie przejęte)
        try { $isHd = (bool)db_one("SELECT 1 FROM helpdesk_tickets WHERE number=? OR legacy_number=?", [$m[1], $m[1]]); } catch (\Throwable $e) { $isHd = false; }
    }
    return $isHd;
}

/** Nazwa nadawcy wiadomości Helpdesku: ustawienie hd_mail_from_name, domyślnie „FEER | Helpdesk” (adres nadawcy bez zmian). */
function _mail_helpdesk_from_name(): string {
    return trim(_mail_setting('hd_mail_from_name')) ?: 'FEER | Helpdesk';
}

function _mail_helpdesk_reply_to(array $msg): string {
    if (!_mail_is_helpdesk($msg)) return '';
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = trim(_mail_setting('hd_mail_reply_to'));
    if ($cache === '') {
        try {
            $r = db_one("SELECT m.mailbox FROM helpdesk_mail_intake i JOIN poczta_mailboxes m ON m.id=i.mailbox_id WHERE i.is_active=1 ORDER BY i.id LIMIT 1");
            $cache = trim((string)($r['mailbox'] ?? ''));
        } catch (\Throwable $e) { $cache = ''; }
    }
    return $cache;
}

/** Ustawia nadawcę systemowego i Reply-To, gdy włączone i wiadomość jest systemowa bez własnego nadawcy. */
function _mail_apply_system_sender(array $msg): array {
    // Wiadomości Helpdesku (powiadomienia, odpowiedzi, potwierdzenia) wychodzą jako „FEER | Helpdesk”
    if (empty($msg['_from_name']) && _mail_is_helpdesk($msg)) $msg['_from_name'] = _mail_helpdesk_from_name();
    $hdrt = _mail_helpdesk_reply_to($msg);
    $sys = trim(_mail_setting('system_mail_from'));
    if ($sys === '' || trim((string)($msg['from_email'] ?? '')) !== '' || !mail_is_system((string)($msg['context_type'] ?? ''))) {
        if ($hdrt !== '' && empty($msg['_reply_to'])) $msg['_reply_to'] = $hdrt;
        return $msg;
    }
    $msg['from_email'] = $sys;
    $msg['_system']    = true;
    $msg['_from_name'] = !empty($msg['_from_name']) ? $msg['_from_name'] : (trim(_mail_setting('system_mail_from_name')) ?: _mail_from_name());
    $rt = trim(_mail_setting('system_mail_reply_to')) ?: (_mail_setting('notify_from_email') ?: _mail_setting('m365_send_from_email'));
    $msg['_reply_to']  = $hdrt !== '' ? $hdrt : (strcasecmp($rt, $sys) !== 0 ? $rt : '');
    return $msg;
}

function _mail_setting(string $key): string {
    // 1. Baza danych (admin panel — najwyższy priorytet)
    try {
        $r = db_one("SELECT value FROM settings WHERE key_=?", [$key]);
        if (isset($r['value']) && $r['value'] !== '') return $r['value'];
    } catch (\Throwable $e) {}

    // 2. Zmienne środowiskowe Docker/entrypoint (fallback gdy brak wpisu w DB)
    //    Mapowanie: klucz ustawienia → nazwa zmiennej środowiskowej
    static $env = [
        'smtp_host'         => 'SMTP_HOST',
        'smtp_port'         => 'SMTP_PORT',
        'smtp_user'         => 'SMTP_USER',
        'smtp_pass'         => 'SMTP_PASS',
        'smtp_from_email'   => 'SMTP_FROM',
        'notify_from_name'  => 'SMTP_FROM_NAME',
        // SMTP_TLS (entrypoint: tls|starttls|off) → smtp_encryption (kod: ssl|tls|none)
        'smtp_encryption'   => 'SMTP_TLS',
        // SMTP2 — zapasowy serwer
        'smtp2_host'        => 'SMTP2_HOST',
        'smtp2_port'        => 'SMTP2_PORT',
        'smtp2_user'        => 'SMTP2_USER',
        'smtp2_pass'        => 'SMTP2_PASS',
        'smtp2_from_email'  => 'SMTP2_FROM',
        'smtp2_encryption'  => 'SMTP2_TLS',
    ];
    if (isset($env[$key])) {
        $val = getenv($env[$key]);
        if ($val !== false && $val !== '' && $val !== 'mailpit') {
            // Normalizacja *_TLS → wartość rozumiana przez _mail_send_smtp()
            if (str_ends_with($key, '_encryption')) {
                return match($val) {
                    'starttls' => 'tls',   // STARTTLS (port 587)
                    'tls'      => 'ssl',   // czysty SSL (port 465)
                    default    => 'none',
                };
            }
            return $val;
        }
    }

    return '';
}

function _mail_from(): string {
    // 1. Explicite ustawiony adres nadawcy M365
    $m365from = _mail_setting('m365_send_from_email');
    if ($m365from) return $m365from;

    // 2. SMTP from
    $smtp = _mail_setting('smtp_from_email') ?: _mail_setting('notify_from_email');
    if ($smtp) return $smtp;

    // 3. Fallback: m365_sender_user_id (Azure Object ID działa jako endpoint sendMail)
    $sender_uid = _mail_setting('m365_sender_user_id');
    if ($sender_uid) return $sender_uid;

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
    // Wystarczy sender_user_id LUB send_from_email + credentials Graph API
    $has_sender = _mail_setting('m365_sender_user_id') || _mail_setting('m365_send_from_email');
    return (bool)($has_sender
        && _mail_setting('m365_tenant_id')
        && _mail_setting('m365_graph_client_id')
        && _mail_setting('m365_graph_client_secret'));
}

function _mail_send_m365(array $msg): bool {
    $tenant   = _mail_setting('m365_tenant_id');
    $client   = _mail_setting('m365_graph_client_id');
    $secret   = _mail_setting('m365_graph_client_secret');
    // Nadawca: nadrzędny z wiadomości (skrzynka usera) > ustawienie globalne > sender_user_id
    $from_override = trim($msg['from_email'] ?? '');
    $from     = $from_override ?: (_mail_setting('m365_send_from_email') ?: _mail_setting('m365_sender_user_id'));

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
            // Brak wersji HTML = nadawca chciał zwykły tekst (np. pisma urzędowe).
            // Wtedy nie dorabiamy HTML-a na siłę — Graph wysyła czysty text/plain.
            'body'    => ($msg['body_html'] ?? '') !== ''
                ? ['contentType' => 'HTML', 'content' => $msg['body_html']]
                : ['contentType' => 'Text', 'content' => (string)$msg['body_text']],
            'toRecipients' => [[
                'emailAddress' => [
                    'address' => $msg['to_email'],
                    'name'    => $msg['to_name'] ?: $msg['to_email'],
                ],
            ]],
            'from' => [
                'emailAddress' => [
                    'address' => $from,
                    'name'    => $msg['_from_name'] ?? _mail_from_name(),
                ],
            ],
        ],
        'saveToSentItems' => false,
    ];

    // Dodatkowe nagłówki. OGRANICZENIE GRAPH API: internetMessageHeaders przyjmuje
    // wyłącznie nazwy zaczynające się od "x-" — List-Unsubscribe przez sendMail
    // ustawić się NIE DA. Jednoklikowy wypis (RFC 8058) działa więc tylko na
    // ścieżce SMTP; przy wysyłce masowej z M365 zostaje link w stopce.
    $hdrs = json_decode($msg['headers'] ?? '', true);
    if (is_array($hdrs)) {
        $graph_hdrs = [];
        foreach ($hdrs as $name => $value) {
            $n = preg_replace('/[^A-Za-z0-9-]/', '', (string)$name);
            if ($n === '' || !str_starts_with(strtolower($n), 'x-')) continue;
            $v = trim(preg_replace('/[\r\n]+/', ' ', (string)$value));
            if ($v === '') continue;
            $graph_hdrs[] = ['name' => $n, 'value' => mb_substr($v, 0, 900)];
        }
        if ($graph_hdrs) $payload['message']['internetMessageHeaders'] = array_slice($graph_hdrs, 0, 5);
    }

    // DW / UDW
    $cc_list  = json_decode($msg['cc_emails']  ?? '[]', true) ?: [];
    $bcc_list = json_decode($msg['bcc_emails'] ?? '[]', true) ?: [];
    if ($cc_list) {
        $payload['message']['ccRecipients'] = array_map(
            fn($e) => ['emailAddress' => ['address' => $e]], $cc_list
        );
    }
    if ($bcc_list) {
        $payload['message']['bccRecipients'] = array_map(
            fn($e) => ['emailAddress' => ['address' => $e]], $bcc_list
        );
    }

    // Wysyłka z konta konkretnego użytkownika: nie wymuszaj nadawcy/nazwy organizacji
    // (skrzynka sama poda swoją tożsamość) i zostaw kopię w „Elementach wysłanych".
    if ($from_override && empty($msg['_system'])) {
        unset($payload['message']['from']);
        $payload['saveToSentItems'] = true;
    }
    // Nadawca systemowy: odpowiedzi trafiają do skrzynki organizacji
    if (!empty($msg['_reply_to'])) $payload['message']['replyTo'] = [['emailAddress' => ['address' => $msg['_reply_to']]]];

    // Załączniki M365
    $attachments = json_decode($msg['attachments'] ?? '[]', true) ?: [];
    if ($attachments) {
        $payload['message']['attachments'] = [];
        foreach ($attachments as $att) {
            $full_path = UPLOAD_DIR . ($att['path'] ?? '');
            if (!is_file($full_path)) continue;
            $content = file_get_contents($full_path);
            if ($content === false) continue;
            $payload['message']['attachments'][] = [
                '@odata.type'  => '#microsoft.graph.fileAttachment',
                'name'         => $att['name'] ?? basename($full_path),
                'contentType'  => $att['mime'] ?? 'application/octet-stream',
                'contentBytes' => base64_encode($content),
            ];
        }
    }

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

/**
 * @param string $prefix  'smtp' (główny) lub 'smtp2' (zapasowy)
 */
function _mail_send_smtp(array $msg, string $host, string $prefix = 'smtp'): bool {
    $port       = (int)(_mail_setting("{$prefix}_port") ?: 587);
    $user       = _mail_setting("{$prefix}_user");
    $pass       = _mail_setting("{$prefix}_pass");
    $encryption = strtolower(_mail_setting("{$prefix}_encryption") ?: 'tls'); // tls|ssl|none
    // Nadawca: dedykowany adres SMTP lub globalny _mail_from()
    $from_setting = _mail_setting("{$prefix}_from_email");
    $from       = !empty($msg['_system']) ? $msg['from_email'] : ($from_setting ?: _mail_from());
    $from_name  = $msg['_from_name'] ?? _mail_from_name();
    // Dane logowania podane wprost (własna skrzynka użytkownika — modules/userSmtp) mają pierwszeństwo przed ustawieniami
    if (!empty($msg['_smtp']) && is_array($msg['_smtp'])) {
        $ov = $msg['_smtp'];
        $port = (int)$ov['port']; $user = (string)$ov['user']; $pass = (string)$ov['pass'];
        $encryption = (string)$ov['encryption']; $from = (string)$ov['from'];
        if (!isset($msg['_from_name'])) {
            $fn = function_exists('current_user') ? trim((string)(current_user()['name'] ?? '')) : '';
            if ($fn === '') { try { $fn = trim((string)(db_one("SELECT name FROM users WHERE lower(email)=lower(?)", [$from])['name'] ?? '')); } catch (\Throwable $e) {} }
            if ($fn !== '') $from_name = $fn;
        }
    }

    // Ustaw timeout i połącz
    $timeout = 15;
    $addr = ($encryption === 'ssl') ? "ssl://{$host}" : "tcp://{$host}";
    $errno = $errstr = null;
    // Ostrzeżenia PHP z próby połączenia (przy SSL/TLS przyczyna z OpenSSL, np. „wrong version number”, jest tylko w nich)
    $warns = [];
    set_error_handler(function (int $no, string $str) use (&$warns): bool { $warns[] = $str; return true; });
    try { $sock = stream_socket_client("{$addr}:{$port}", $errno, $errstr, $timeout); }
    finally { restore_error_handler(); }
    if (!$sock) {
        throw new \RuntimeException("SMTP connect failed ({$addr}:{$port}): " . trim(($errstr ?: '') . ' (' . (int)$errno . ')')
            . ($warns ? ' — ' . implode(' | ', array_map(fn($w) => preg_replace('/^stream_socket_client\(\): /', '', $w), $warns)) : ''));
    }

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

    // Koperta — każdą odpowiedź sprawdzamy. Po odrzuceniu nadawcy albo wszystkich odbiorców serwer nie przyjmie DATA, a wysłana
    // mimo to treść wygląda dla Postfiksa jak złamanie protokołu („221 2.7.0 Error: I can break rules, too. Goodbye.”) —
    // prawdziwy powód (np. odbiorca serwis@local w niewysyłalnej domenie) ginął w komunikacie błędu.
    $abort = function (string $what, string $resp) use ($sock, $send): void {
        try { $send("RSET"); $send("QUIT"); } catch (\Throwable $e) {}
        fclose($sock);
        throw new \RuntimeException("SMTP {$what}: " . trim($resp));
    };
    $r = $send("MAIL FROM:<{$from}>");
    if (!str_starts_with(trim($r), '250')) $abort("odrzucił nadawcę <{$from}>", $r);
    $rcpt_main = $send("RCPT TO:<{$msg['to_email']}>");
    $accepted  = str_starts_with(trim($rcpt_main), '25') ? 1 : 0;
    $cc_list  = json_decode($msg['cc_emails']  ?? '[]', true) ?: [];
    $bcc_list = json_decode($msg['bcc_emails'] ?? '[]', true) ?: [];
    foreach (array_merge($cc_list, $bcc_list) as $e) {
        if (str_starts_with(trim($send("RCPT TO:<{$e}>")), '25')) $accepted++;   // odrzucony DW/UDW — jak dotąd pomijamy
    }
    if ($accepted === 0) $abort("odrzucił odbiorcę <{$msg['to_email']}>", $rcpt_main);
    $r = $send("DATA");
    if (!str_starts_with(trim($r), '354')) $abort("nie przyjął treści (DATA)", $r);

    // Załączniki
    $attachments = json_decode($msg['attachments'] ?? '[]', true) ?: [];

    // Buduj wiadomość
    $alt_boundary = 'ALT_' . md5(uniqid());
    $mix_boundary = 'MIX_' . md5(uniqid() . 'x');
    $date         = date('r');
    $to_enc       = $msg['to_name']
        ? "=?UTF-8?B?" . base64_encode($msg['to_name']) . "?= <{$msg['to_email']}>"
        : $msg['to_email'];
    $from_enc = "=?UTF-8?B?" . base64_encode($from_name) . "?= <{$from}>";
    $subj_enc = "=?UTF-8?B?" . base64_encode($msg['subject']) . "?=";

    $data  = "Date: {$date}\r\n";
    $data .= "From: {$from_enc}\r\n";
    if (!empty($msg['_reply_to'])) $data .= "Reply-To: {$msg['_reply_to']}\r\n";
    $data .= "To: {$to_enc}\r\n";
    if ($cc_list) { $data .= "Cc: " . implode(', ', $cc_list) . "\r\n"; }
    $data .= "Subject: {$subj_enc}\r\n";
    $data .= _mail_extra_headers($msg);
    $data .= "MIME-Version: 1.0\r\n";

    // Brak wersji HTML = wiadomość ma być zwykłym tekstem; nie dorabiamy HTML-a.
    $plain_only = ($msg['body_html'] ?? '') === '' && ($msg['body_text'] ?? '') !== '';

    if ($attachments) {
        // multipart/mixed — zewnętrzna
        $data .= "Content-Type: multipart/mixed; boundary=\"{$mix_boundary}\"\r\n\r\n";
        $data .= "--{$mix_boundary}\r\n";
        if ($plain_only) {
            $data .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $data .= "Content-Transfer-Encoding: base64\r\n\r\n";
            $data .= chunk_split(base64_encode($msg['body_text'])) . "\r\n";
        } else {
            // wewnątrz: multipart/alternative (text+html)
            $data .= "Content-Type: multipart/alternative; boundary=\"{$alt_boundary}\"\r\n\r\n";
        }
    } elseif ($plain_only) {
        $data .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $data .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $data .= chunk_split(base64_encode($msg['body_text'])) . "\r\n";
    } else {
        $data .= "Content-Type: multipart/alternative; boundary=\"{$alt_boundary}\"\r\n\r\n";
    }

    if (!$plain_only) {
        // Treść tekstowa
        if ($msg['body_text']) {
            $data .= "--{$alt_boundary}\r\n";
            $data .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $data .= "Content-Transfer-Encoding: base64\r\n\r\n";
            $data .= chunk_split(base64_encode($msg['body_text'])) . "\r\n";
        }
        // Treść HTML
        $data .= "--{$alt_boundary}\r\n";
        $data .= "Content-Type: text/html; charset=UTF-8\r\n";
        $data .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $data .= chunk_split(base64_encode($msg['body_html'] ?: nl2br(htmlspecialchars($msg['body_text'])))) . "\r\n";
        $data .= "--{$alt_boundary}--\r\n";
    }

    // Załączniki (tylko przy multipart/mixed)
    foreach ($attachments as $att) {
        $full_path = UPLOAD_DIR . ($att['path'] ?? '');
        if (!is_file($full_path)) continue;
        $content = file_get_contents($full_path);
        if ($content === false) continue;
        $att_name = "=?UTF-8?B?" . base64_encode($att['name'] ?? basename($full_path)) . "?=";
        $mime     = $att['mime'] ?? 'application/octet-stream';
        $data .= "\r\n--{$mix_boundary}\r\n";
        $data .= "Content-Type: {$mime}; name=\"{$att_name}\"\r\n";
        $data .= "Content-Transfer-Encoding: base64\r\n";
        $data .= "Content-Disposition: attachment; filename=\"{$att_name}\"\r\n\r\n";
        $data .= chunk_split(base64_encode($content)) . "\r\n";
    }

    if ($attachments) {
        $data .= "--{$mix_boundary}--\r\n";
    }
    $data .= ".";

    $r = $send($data);
    $send("QUIT");
    fclose($sock);

    if (!str_starts_with(trim($r), '250')) throw new \RuntimeException("SMTP DATA error: {$r}");
    return true;
}

/**
 * Serializuje dodatkowe nagłówki wiadomości do bloku DATA.
 *
 * Biała lista nazw jest tu celowo wąska: nagłówki wędrują z bazy do surowego
 * strumienia SMTP, więc dowolna nazwa/wartość pozwoliłaby wstrzyknąć CRLF i
 * rozbić wiadomość (header injection). Wartości obcinamy do jednej linii.
 */
/** Reply-To podany w nagłówkach wiadomości (mail_queue_add(..., headers: ['Reply-To' => adres])) albo ''. */
function _mail_header_reply_to(array $msg): string {
    $raw = json_decode($msg['headers'] ?? '', true);
    if (!is_array($raw)) return '';
    foreach ($raw as $name => $value) {
        if (strtolower(trim((string)$name)) !== 'reply-to') continue;
        $v = trim(preg_replace('/[\r\n]+/', '', (string)$value));
        return filter_var($v, FILTER_VALIDATE_EMAIL) ? $v : '';
    }
    return '';
}

function _mail_extra_headers(array $msg): string {
    $allowed = ['list-unsubscribe', 'list-unsubscribe-post', 'list-id', 'precedence',
                'auto-submitted', 'in-reply-to', 'references'];
    $raw = json_decode($msg['headers'] ?? '', true);
    if (!is_array($raw)) return '';

    $out = '';
    foreach ($raw as $name => $value) {
        $n = strtolower(trim((string)$name));
        // Własne nagłówki X-* są bezpieczne i przydatne w diagnostyce.
        if (!in_array($n, $allowed, true) && !str_starts_with($n, 'x-')) continue;
        $v = preg_replace('/[\r\n]+/', ' ', (string)$value);
        $v = trim(mb_substr($v, 0, 900));
        if ($v === '') continue;
        $name_clean = preg_replace('/[^A-Za-z0-9-]/', '', (string)$name);
        if ($name_clean === '') continue;
        $out .= "{$name_clean}: {$v}\r\n";
    }
    return $out;
}

function _mail_smtp_domain(): string {
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return preg_replace('/:\d+$/', '', $host);
}

// ── PHP mail() fallback ───────────────────────────────────────────────────────

function _mail_send_native(array $msg): bool {
    $from_name  = $msg['_from_name'] ?? _mail_from_name();
    $from_email = !empty($msg['_system']) ? $msg['from_email'] : _mail_from();

    // Brak wersji HTML = wiadomość zwykłotekstowa (jak w ścieżkach Graph/SMTP)
    $plain_only = ($msg['body_html'] ?? '') === '' && ($msg['body_text'] ?? '') !== '';

    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= $plain_only
        ? "Content-Type: text/plain; charset=UTF-8\r\n"
        : "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: =?UTF-8?B?" . base64_encode($from_name) . "?= <{$from_email}>\r\n";
    $headers .= "Reply-To: " . (!empty($msg['_reply_to']) ? $msg['_reply_to'] : $from_email) . "\r\n";
    $headers .= "X-Mailer: RejestrUmow/2026\r\n";
    $cc_list  = json_decode($msg['cc_emails']  ?? '[]', true) ?: [];
    $bcc_list = json_decode($msg['bcc_emails'] ?? '[]', true) ?: [];
    if ($cc_list)  { $headers .= "Cc: "  . implode(', ', $cc_list)  . "\r\n"; }
    if ($bcc_list) { $headers .= "Bcc: " . implode(', ', $bcc_list) . "\r\n"; }

    $to      = $msg['to_name']
        ? "=?UTF-8?B?" . base64_encode($msg['to_name']) . "?= <{$msg['to_email']}>"
        : $msg['to_email'];
    $subject = "=?UTF-8?B?" . base64_encode($msg['subject']) . "?=";

    return mail($to, $subject, $plain_only ? $msg['body_text'] : $msg['body_html'], $headers);
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
