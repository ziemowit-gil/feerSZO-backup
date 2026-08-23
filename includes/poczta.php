<?php
/**
 * includes/poczta.php — Moduł Poczty: automatyczne skanowanie skrzynek M365 → oś czasu CRM.
 *
 * Samonaprawa schematu (wzorzec includes/events.php / includes/zlecenie_schema.php):
 * CREATE TABLE / ALTER TABLE w try/catch, uruchamiane raz przy pierwszym require tego pliku.
 *
 * Silnik dopasowywania (kontakt po adresie e-mail, delta-sync Graph, dedup) bazuje na
 * includes/outlook_sync.php — tu żyje wyłącznie logika własna modułu: lista skrzynek do
 * skanowania (opt-in, RODO), harmonogram/kolejka, obsługa błędów/rate-limitu i załączniki.
 */

require_once __DIR__ . '/m365.php';
require_once __DIR__ . '/crm.php';

(function () {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo = db();

    $exec = function (string $sql) use ($pdo) {
        try { $pdo->exec($sql); } catch (\Throwable $e) { /* istnieje / niekrytyczne — ignorujemy */ }
    };

    $exec("CREATE TABLE IF NOT EXISTS poczta_mailboxes (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        mailbox         TEXT    NOT NULL UNIQUE,
        ms_user_id      TEXT,
        display_name    TEXT    NOT NULL DEFAULT '',
        enabled         INTEGER NOT NULL DEFAULT 1,
        status          TEXT    NOT NULL DEFAULT 'ok',
        status_detail   TEXT    NOT NULL DEFAULT '',
        last_scan_at    DATETIME,
        last_success_at DATETIME,
        next_retry_at   DATETIME,
        matched_total   INTEGER NOT NULL DEFAULT 0,
        created_by      INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $exec("CREATE TABLE IF NOT EXISTS poczta_delta (
        mailbox_id INTEGER NOT NULL REFERENCES poczta_mailboxes(id) ON DELETE CASCADE,
        folder     TEXT    NOT NULL,
        delta_link TEXT    NOT NULL,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (mailbox_id, folder)
    )");

    $exec("CREATE TABLE IF NOT EXISTS poczta_scan_log (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        mailbox_id  INTEGER REFERENCES poczta_mailboxes(id) ON DELETE CASCADE,
        fetched     INTEGER NOT NULL DEFAULT 0,
        matched     INTEGER NOT NULL DEFAULT 0,
        created     INTEGER NOT NULL DEFAULT 0,
        skipped     INTEGER NOT NULL DEFAULT 0,
        status      TEXT    NOT NULL DEFAULT 'ok',
        error       TEXT    NOT NULL DEFAULT '',
        duration_ms INTEGER NOT NULL DEFAULT 0,
        run_at      DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $exec("CREATE INDEX IF NOT EXISTS idx_poczta_scan_log_mailbox ON poczta_scan_log(mailbox_id, run_at)");

    $exec("CREATE TABLE IF NOT EXISTS poczta_attachments (
        id                  INTEGER PRIMARY KEY AUTOINCREMENT,
        communication_id    INTEGER NOT NULL REFERENCES crm_communications(id) ON DELETE CASCADE,
        graph_attachment_id TEXT    NOT NULL,
        original_name       TEXT    NOT NULL,
        mime_type           TEXT    NOT NULL DEFAULT '',
        size_bytes          INTEGER NOT NULL DEFAULT 0,
        stored_path         TEXT,
        created_at          DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $exec("CREATE INDEX IF NOT EXISTS idx_poczta_att_comm ON poczta_attachments(communication_id)");

    // Rozszerzenie crm_communications — pełny HTML, dedup po InternetMessageId, powiązanie ze skrzynką
    $exec("ALTER TABLE crm_communications ADD COLUMN body_html TEXT");
    $exec("ALTER TABLE crm_communications ADD COLUMN internet_message_id TEXT");
    $exec("ALTER TABLE crm_communications ADD COLUMN mailbox_id INTEGER REFERENCES poczta_mailboxes(id) ON DELETE SET NULL");
    $exec("ALTER TABLE crm_communications ADD COLUMN has_attachments INTEGER NOT NULL DEFAULT 0");
    $exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_crm_comm_internet_msg ON crm_communications(internet_message_id, contact_id)");
})();

/**
 * Serwis skanowania skrzynek M365 → crm_communications.
 * Wywoływany z cron/poczta_dispatch.php (dispatch_pending), cron/poczta_worker.php
 * (konsument RabbitMQ) i poczta/api/action.php (skanowanie ręczne „teraz").
 */
class PocztaScanService
{
    private \PDO      $pdo;
    private M365Graph $graph;

    public function __construct()
    {
        $this->pdo   = db();
        $this->graph = new M365Graph();
    }

    /** Uzupełnia ms_user_id (Azure AD GUID) skrzynki, jeśli brak — cache w poczta_mailboxes. */
    public function resolve_mailbox(int $mailbox_id): array
    {
        $mb = db_one("SELECT * FROM poczta_mailboxes WHERE id=?", [$mailbox_id]);
        if (!$mb) throw new \RuntimeException("Nieznana skrzynka #{$mailbox_id}.");

        if (!empty($mb['ms_user_id'])) return $mb;

        $found = $this->graph->find_by_email_or_upn($mb['mailbox']);
        if (empty($found['id'])) {
            throw new \RuntimeException("Nie znaleziono konta M365 dla adresu {$mb['mailbox']}.");
        }
        $this->pdo->prepare(
            "UPDATE poczta_mailboxes SET ms_user_id=?, updated_at=CURRENT_TIMESTAMP WHERE id=?"
        )->execute([$found['id'], $mailbox_id]);
        $mb['ms_user_id'] = $found['id'];
        return $mb;
    }

    /**
     * Skanuje jedną skrzynkę (inbox + sentitems), dopasowuje do kontaktów CRM,
     * zapisuje nowe wiadomości do crm_communications. Błąd pojedynczej wiadomości
     * nie przerywa reszty. Zwraca podsumowanie i loguje przebieg w poczta_scan_log.
     *
     * @return array{fetched:int,matched:int,created:int,skipped:int,status:string,error:string}
     */
    public function scan_mailbox(int $mailbox_id): array
    {
        $t0 = microtime(true);
        $result = ['fetched' => 0, 'matched' => 0, 'created' => 0, 'skipped' => 0, 'status' => 'ok', 'error' => ''];

        try {
            $mb = $this->resolve_mailbox($mailbox_id);
        } catch (\Throwable $e) {
            $result['status'] = 'error';
            $result['error']  = $e->getMessage();
            $this->finish($mailbox_id, $result, $t0);
            return $result;
        }

        $download_attachments = org_setting('poczta_download_attachments') === '1';
        $max_attach_kb         = (int)(org_setting('poczta_attach_max_kb') ?: '5120');
        $store_html            = org_setting('poczta_store_html') !== '0';

        $folders = ['inbox' => 'in', 'sentitems' => 'out'];

        foreach ($folders as $folder => $direction) {
            $delta_row  = db_one(
                "SELECT delta_link FROM poczta_delta WHERE mailbox_id=? AND folder=?",
                [$mailbox_id, $folder]
            );
            $delta_link = $delta_row['delta_link'] ?? null;

            try {
                $graph_result = $this->graph->get_messages_delta($mb['ms_user_id'], $folder, $delta_link);
            } catch (\Throwable $e) {
                $result['status'] = 'error';
                $result['error']  = "Folder {$folder}: " . $e->getMessage();
                continue;
            }

            // Graph zwraca błąd (429/401/403) w treści odpowiedzi bez wyjątku — sprawdzamy status HTTP.
            $http_status = $this->graph->last_status();
            if ($http_status === 429) {
                $this->mark_rate_limited($mailbox_id, $http_status);
                $result['status'] = 'rate_limited';
                $result['error']  = "Folder {$folder}: HTTP 429 — przekroczono limit zapytań Graph API.";
                $this->finish($mailbox_id, $result, $t0);
                return $result; // nie kontynuuj drugiego folderu — poczekaj na next_retry_at
            }
            if ($http_status === 401 || $http_status === 403) {
                $this->mark_auth_error($mailbox_id, "HTTP {$http_status} — sprawdź uprawnienia aplikacji Graph (Mail.Read) i client secret.");
                $result['status'] = 'rate_limited' === $result['status'] ? $result['status'] : 'error';
                $result['error']  = "Folder {$folder}: HTTP {$http_status} — wymaga ponownej autoryzacji.";
                $this->finish($mailbox_id, $result, $t0);
                return $result;
            }

            foreach ($graph_result['messages'] as $msg) {
                $result['fetched']++;
                try {
                    $this->process_message($msg, $direction, $mailbox_id, $mb['mailbox'], $store_html, $download_attachments, $max_attach_kb, $result);
                } catch (\Throwable $e) {
                    $result['error'] = trim($result['error'] . ' | Msg [' . ($msg['id'] ?? '?') . ']: ' . $e->getMessage());
                }
            }

            // Delta-link zapisujemy tylko po pomyślnym przejściu strony (bez błędu Graph) — inaczej cofnęlibyśmy kursor.
            if (!empty($graph_result['delta_link'])) {
                $this->pdo->prepare(
                    "INSERT INTO poczta_delta (mailbox_id, folder, delta_link, updated_at)
                     VALUES (?, ?, ?, CURRENT_TIMESTAMP)
                     ON CONFLICT(mailbox_id, folder) DO UPDATE SET delta_link=excluded.delta_link, updated_at=CURRENT_TIMESTAMP"
                )->execute([$mailbox_id, $folder, $graph_result['delta_link']]);
            }
        }

        // Sukces (choćby częściowy) — status skrzynki wraca do 'ok', czyścimy backoff.
        $this->pdo->prepare(
            "UPDATE poczta_mailboxes
             SET status='ok', status_detail='', next_retry_at=NULL,
                 last_scan_at=CURRENT_TIMESTAMP, last_success_at=CURRENT_TIMESTAMP,
                 matched_total = matched_total + ?, updated_at=CURRENT_TIMESTAMP
             WHERE id=?"
        )->execute([$result['created'], $mailbox_id]);

        $this->finish($mailbox_id, $result, $t0);
        return $result;
    }

    /** Dopasowuje i zapisuje jedną wiadomość Graph do osi czasu CRM (jeśli trafia w kontakt). */
    private function process_message(
        array $msg, string $direction, int $mailbox_id, string $mailbox,
        bool $store_html, bool $download_attachments, int $max_attach_kb, array &$result
    ): void {
        $msg_id = $msg['id'] ?? null;
        if (!$msg_id || !empty($msg['@removed'])) {
            $result['skipped']++;
            return;
        }

        $internet_id = trim($msg['internetMessageId'] ?? '') ?: $msg_id;

        $emails = [];
        if ($direction === 'in') {
            $a = strtolower(trim($msg['from']['emailAddress']['address'] ?? ''));
            if ($a) $emails[] = $a;
        } else {
            foreach (array_merge($msg['toRecipients'] ?? [], $msg['ccRecipients'] ?? []) as $r) {
                $a = strtolower(trim($r['emailAddress']['address'] ?? ''));
                if ($a) $emails[] = $a;
            }
        }
        $emails = array_values(array_unique(array_filter($emails)));
        if (!$emails) { $result['skipped']++; return; }

        $contact_ids = $this->contacts_by_emails($emails);

        // Nieznany nadawca: albo zakładamy kartotekę (skrzynka jako źródło zapytań
        // sprzedażowych — Skrzynka CRM), albo pomijamy wiadomość jak dotychczas.
        if (!$contact_ids && $direction === 'in' && $this->autocreate_contacts()) {
            $cid = $this->create_contact_from_sender($msg);
            if ($cid) $contact_ids = [$cid];
        }
        if (!$contact_ids) { $result['skipped']++; return; }
        $result['matched']++;

        $subject     = trim($msg['subject'] ?? '') ?: '(bez tematu)';
        $body_html   = $store_html ? (string)($msg['body']['content'] ?? '') : '';
        $body_text   = trim($body_html !== '' ? strip_tags($body_html) : ($msg['bodyPreview'] ?? '')) ?: '(brak treści)';
        $has_attach  = !empty($msg['hasAttachments']) ? 1 : 0;
        $sent_at     = $this->parse_msg_dt(
            $direction === 'in' ? ($msg['receivedDateTime'] ?? '') : ($msg['sentDateTime'] ?? '')
        );

        foreach ($contact_ids as $cid) {
            $chk = $this->pdo->prepare(
                "SELECT 1 FROM crm_communications WHERE internet_message_id=? AND contact_id=? LIMIT 1"
            );
            $chk->execute([$internet_id, $cid]);
            if ($chk->fetchColumn()) continue;

            // Dane nadawcy dla Inbox Ogólnego (from_name / from_email)
            $from_addr = $msg['from']['emailAddress']['address'] ?? '';
            $from_name = $msg['from']['emailAddress']['name']    ?? '';

            $comm_id = db_insert('crm_communications', [
                'contact_id'          => $cid,
                'channel'             => 'email',
                'direction'           => $direction,
                'subject'             => $subject,
                'body'                => $body_text,
                'body_html'           => $body_html !== '' ? $body_html : null,
                'status'              => 'zsynchronizowana',
                'outlook_message_id'  => $msg_id,
                'internet_message_id' => $internet_id,
                'mailbox_id'          => $mailbox_id,
                'has_attachments'     => $has_attach,
                'sent_at'             => $sent_at,
                'from_email'          => $from_addr,
                'from_name'           => $from_name,
                'is_read'             => 0,
                'inbox_status'        => 'active',
            ]);
            $result['created']++;

            if ($has_attach) {
                $this->store_attachments($comm_id, $mailbox, $msg_id, $cid, $download_attachments, $max_attach_kb);
            }

            // Hook EZD: wykryj numer sprawy w temacie i linkuj.
            // Działa tylko gdy moduł EZD jest aktywny — lazy require przez module_enabled().
            if ($direction === 'in' && function_exists('module_enabled') && module_enabled('ezd_enabled')) {
                try {
                    static $_ezd_mail_loaded = false;
                    if (!$_ezd_mail_loaded) {
                        require_once __DIR__ . '/ezd_mail.php';
                        $_ezd_mail_loaded = true;
                    }
                    (new EzdMailService())->handleIncomingComm($comm_id);
                } catch (\Throwable $e) {
                    // Błąd EZD-hookowania nie przerywa skanowania skrzynki
                }
            }
        }
    }

    /** Metadane (zawsze) + treść (opcjonalnie, jeśli włączone i poniżej limitu) załączników wiadomości. */
    private function store_attachments(int $comm_id, string $mailbox, string $msg_id, int $contact_id, bool $download, int $max_kb): void
    {
        try {
            $attachments = $this->graph->get_message_attachments($mailbox, $msg_id);
        } catch (\Throwable $e) {
            return; // metadane załączników nie są krytyczne — nie przerywamy skanowania
        }

        foreach ($attachments as $att) {
            $att_id    = $att['id'] ?? '';
            $name      = $att['name'] ?? 'plik';
            $size      = (int)($att['size'] ?? 0);
            $stored    = null;

            if ($download && $att_id && $size > 0 && $size <= $max_kb * 1024) {
                try {
                    $bytes = $this->graph->get_attachment_content($mailbox, $msg_id, $att_id);
                    $safe_name = preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?: 'plik';
                    $dir = rtrim(UPLOAD_DIR, '/') . "/poczta/{$contact_id}/{$comm_id}/";
                    if (!is_dir($dir)) mkdir($dir, 0775, true);
                    $stored = $dir . $safe_name;
                    file_put_contents($stored, $bytes);
                    $stored = "poczta/{$contact_id}/{$comm_id}/{$safe_name}";
                } catch (\Throwable $e) {
                    $stored = null;
                }
            }

            db_insert('poczta_attachments', [
                'communication_id'    => $comm_id,
                'graph_attachment_id' => $att_id,
                'original_name'       => $name,
                'mime_type'           => $att['contentType'] ?? '',
                'size_bytes'          => $size,
                'stored_path'         => $stored,
            ]);
        }
    }

    /**
     * ID aktywnych kontaktów CRM pasujących do podanych adresów e-mail.
     *
     * Dopasowujemy po adresie samego kontaktu ORAZ po adresach jego OSÓB
     * KONTAKTOWYCH. Bez tego drugiego wiadomość od księgowej firmy nie trafiała
     * do historii tej firmy — a to najczęstszy przypadek w korespondencji
     * z podmiotami, gdzie pisze konkretny człowiek, nie adres ogólny.
     */
    private function contacts_by_emails(array $emails): array
    {
        if (!$emails) return [];
        $ph  = implode(',', array_fill(0, count($emails), '?'));
        $ids = [];

        $s = $this->pdo->prepare(
            "SELECT id FROM crm_contacts WHERE LOWER(email) IN ($ph) AND crm_active = 1"
        );
        $s->execute($emails);
        foreach ($s->fetchAll(\PDO::FETCH_COLUMN) ?: [] as $v) $ids[(int)$v] = true;

        try {
            $s2 = $this->pdo->prepare(
                "SELECT c.id
                   FROM crm_contact_persons p
                   JOIN crm_contacts       c ON c.id = p.contact_id
                  WHERE LOWER(p.email) IN ($ph) AND c.crm_active = 1"
            );
            $s2->execute($emails);
            foreach ($s2->fetchAll(\PDO::FETCH_COLUMN) ?: [] as $v) $ids[(int)$v] = true;
        } catch (\Throwable $e) { /* brak tabeli osób nie może wstrzymać skanowania */ }

        return array_keys($ids);
    }

    /** Czy nieznany nadawca ma zakładać kartotekę CRM (settings.poczta_autocreate_contacts). */
    private function autocreate_contacts(): bool {
        static $flag = null;
        if ($flag === null) {
            try {
                $r = db_one("SELECT value FROM settings WHERE key_='poczta_autocreate_contacts'");
                $flag = (($r['value'] ?? '0') === '1');
            } catch (\Throwable $e) { $flag = false; }
        }
        return $flag;
    }

    /**
     * Tworzy kartotekę na podstawie nadawcy wiadomości. Zwraca id kontaktu albo null.
     * Nie zakłada kontaktów dla adresów własnej organizacji ani dla niepoprawnych adresów.
     */
    private function create_contact_from_sender(array $msg): ?int {
        $addr = strtolower(trim($msg['from']['emailAddress']['address'] ?? ''));
        $name = trim($msg['from']['emailAddress']['name'] ?? '');
        if ($addr === '' || !filter_var($addr, FILTER_VALIDATE_EMAIL)) return null;

        $own = strtolower((string)(org_setting('org_email') ?: ''));
        $own_domain = $own !== '' ? substr(strrchr($own, '@') ?: '', 1) : '';
        if ($own_domain !== '' && str_ends_with($addr, '@' . $own_domain)) return null;

        // Filtr nadawców z analizatora kartotek — sprawdzany U ŹRÓDŁA, więc raz
        // odfiltrowany adres nie zakłada kartoteki po każdym kolejnym skanowaniu.
        try {
            require_once __DIR__ . '/crm_contact_analyzer.php';
            if (crm_sender_blocked($addr)) return null;
        } catch (\Throwable $e) { /* brak modułu nie może wstrzymać skanowania */ }

        try {
            require_once __DIR__ . '/crm.php';
            $disp  = $name !== '' ? $name : ucfirst((string)strtok($addr, '@'));
            $parts = preg_split('/\s+/', $disp, 2);
            $cid = CrmManager::createContact([
                'type'          => 'osoba',
                'status'        => 'prospect',
                'imie_nazwisko' => $disp,
                'imie'          => $parts[0] ?? '',
                'nazwisko'      => $parts[1] ?? '',
                'email'         => $addr,
                'source'        => 'skrzynka',
            ]);
            return $cid ?: null;
        } catch (\Throwable $e) {
            error_log('[poczta autocreate] ' . $e->getMessage());
            return null;
        }
    }

    private function parse_msg_dt(string $raw): string
    {
        if (!$raw) return date('Y-m-d H:i:s');
        try {
            $ts = new \DateTime($raw);
            $ts->setTimezone(new \DateTimeZone('Europe/Warsaw'));
            return $ts->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return date('Y-m-d H:i:s');
        }
    }

    private function mark_rate_limited(int $mailbox_id, int $http_status): void
    {
        $retry_after = 60; // Graph nie ujawnia Retry-After przez file_get_contents nagłówki niestandardowo — bezpieczny domyślny backoff
        $this->pdo->prepare(
            "UPDATE poczta_mailboxes
             SET status='rate_limited', status_detail=?, next_retry_at=datetime('now', ?), updated_at=CURRENT_TIMESTAMP
             WHERE id=?"
        )->execute(["HTTP {$http_status} — przekroczono limit zapytań Graph API.", "+{$retry_after} seconds", $mailbox_id]);
    }

    private function mark_auth_error(int $mailbox_id, string $detail): void
    {
        $this->pdo->prepare(
            "UPDATE poczta_mailboxes
             SET status='auth_error', status_detail=?, updated_at=CURRENT_TIMESTAMP
             WHERE id=?"
        )->execute([$detail, $mailbox_id]);
    }

    private function finish(int $mailbox_id, array $result, float $t0): void
    {
        $this->pdo->prepare(
            "INSERT INTO poczta_scan_log (mailbox_id, fetched, matched, created, skipped, status, error, duration_ms)
             VALUES (?,?,?,?,?,?,?,?)"
        )->execute([
            $mailbox_id, $result['fetched'], $result['matched'], $result['created'], $result['skipped'],
            $result['status'], $result['error'], (int)round((microtime(true) - $t0) * 1000),
        ]);
        if ($result['status'] === 'error') {
            $this->pdo->prepare("UPDATE poczta_mailboxes SET last_scan_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$mailbox_id]);
        }
    }

    /**
     * Wybiera skrzynki gotowe do skanowania (enabled, nie auth_error, backoff wygasł) i albo
     * publikuje job na RabbitMQ, albo (bez kolejki) skanuje synchronicznie — ta sama odporność
     * co cron/mail_queue.php.
     *
     * @return array{queued:int,scanned:int,errors:string[]}
     */
    public static function dispatch_pending(): array
    {
        $rows = db_all(
            "SELECT id FROM poczta_mailboxes
             WHERE enabled=1 AND status != 'auth_error'
               AND (next_retry_at IS NULL OR next_retry_at <= datetime('now'))"
        );

        $use_rabbit = class_exists('PhpAmqpLib\\Connection\\AMQPLazyConnection') && getenv('RABBITMQ_HOST');
        $queued = $scanned = 0;
        $errors = [];

        foreach ($rows as $row) {
            $mailbox_id = (int)$row['id'];
            try {
                if ($use_rabbit) {
                    require_once __DIR__ . '/rabbitmq.php';
                    rabbit_publish('poczta_skanowanie', ['mailbox_id' => $mailbox_id]);
                    $queued++;
                } else {
                    (new self())->scan_mailbox($mailbox_id);
                    $scanned++;
                }
            } catch (\Throwable $e) {
                $errors[] = "[{$mailbox_id}] " . $e->getMessage();
            }
        }

        return compact('queued', 'scanned', 'errors');
    }
}

/**
 * Domyślna treść komunikatu na stronie logowania Roundcube (gdy admin jeszcze
 * nic nie ustawił w admin/poczta_settings.php). Ta sama treść jest źródłem
 * dla api/internal/rc_login_notice.php, skąd pobiera ją plugin
 * docker/roundcube/plugins/login_notice/ — jedno miejsce prawdy.
 */
function poczta_rc_login_notice_default(): string {
    return 'To oficjalna poczta <strong>Fundacji Edukacji Empatii Rozwoju (FEER)</strong>. '
        . 'Logowanie odbywa się wyłącznie przez konto Microsoft Twojej organizacji — '
        . 'ten system nie przechowuje ani nie widzi Twojego hasła do skrzynki.';
}
