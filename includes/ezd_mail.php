<?php
/**
 * includes/ezd_mail.php — Klient Poczty EZD.
 *
 * Łączy moduł Poczty (poczta_mailboxes, crm_communications) z modułem EZD
 * (ezd_sprawy, ezd_pisma). Odpowiada za:
 *  - auto-migrację dodatkowych kolumn w crm_communications i ezd_pisma,
 *  - wykrywanie znaku sprawy EZD w temacie wiadomości ([EZD: ZNAK]),
 *  - kompozycję wychodzących wiadomości z wstrzyknięciem znaku i tworzeniem pisma,
 *  - zarządzanie Inboxem Ogólnym (przypisywanie, statusy, wątki).
 *
 * Wywoływany przez ezd/poczta/*.php i cron/ezd_mail_ingest.php.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/crm.php';
require_once __DIR__ . '/mail_queue.php';

// ── Auto-migracja schematu (wzorzec projektu: try/catch w IIFE) ──────────────
(function () {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo = db();

    $exec = static function (string $sql) use ($pdo): void {
        try { $pdo->exec($sql); } catch (\Throwable $e) { /* kolumna istnieje lub niekrytyczne */ }
    };

    // Rozszerzenia crm_communications dla EZD ─────────────────────────────────
    $exec("ALTER TABLE crm_communications ADD COLUMN ezd_sprawa_id  INTEGER REFERENCES ezd_sprawy(id) ON DELETE SET NULL");
    $exec("ALTER TABLE crm_communications ADD COLUMN inbox_status   TEXT    NOT NULL DEFAULT 'active'");
    $exec("ALTER TABLE crm_communications ADD COLUMN assigned_to    INTEGER REFERENCES users(id) ON DELETE SET NULL");
    $exec("ALTER TABLE crm_communications ADD COLUMN thread_key     TEXT");
    $exec("ALTER TABLE crm_communications ADD COLUMN from_name      TEXT    NOT NULL DEFAULT ''");
    $exec("ALTER TABLE crm_communications ADD COLUMN from_email     TEXT    NOT NULL DEFAULT ''");
    $exec("ALTER TABLE crm_communications ADD COLUMN is_read        INTEGER NOT NULL DEFAULT 0");
    // Kolumny nie było, a serwis próbował ją aktualizować przy każdym powiązaniu
    // wiadomości ze sprawą i przy zmianie statusu — SQLite zwracał wtedy
    // „no such column: updated_at" i cała operacja przerywała się wyjątkiem.
    $exec("ALTER TABLE crm_communications ADD COLUMN updated_at DATETIME");
    // Dziennik przypisań i przekazań korespondencji. Bez niego nie da się
    // odtworzyć, kto komu przekazał wiadomość i kiedy — a przy korespondencji
    // wpływającej to podstawowa informacja rozliczalna.
    $exec("CREATE TABLE IF NOT EXISTS poczta_assign_log (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        comm_id     INTEGER NOT NULL REFERENCES crm_communications(id) ON DELETE CASCADE,
        from_user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
        to_user_id  INTEGER REFERENCES users(id) ON DELETE SET NULL,
        by_user_id  INTEGER REFERENCES users(id) ON DELETE SET NULL,
        note        TEXT    NOT NULL DEFAULT '',
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $exec("CREATE INDEX IF NOT EXISTS idx_poczta_assign_comm ON poczta_assign_log(comm_id, id)");

    $exec("CREATE INDEX IF NOT EXISTS idx_crm_comm_ezd_sprawa  ON crm_communications(ezd_sprawa_id)");
    $exec("CREATE INDEX IF NOT EXISTS idx_crm_comm_inbox       ON crm_communications(inbox_status, is_read, sent_at)");
    $exec("CREATE INDEX IF NOT EXISTS idx_crm_comm_thread      ON crm_communications(thread_key)");

    // Rozszerzenia ezd_pisma dla powiązania z komunikacją CRM ─────────────────
    $exec("ALTER TABLE ezd_pisma ADD COLUMN comm_id INTEGER REFERENCES crm_communications(id) ON DELETE SET NULL");
    $exec("CREATE INDEX IF NOT EXISTS idx_ezd_pisma_comm ON ezd_pisma(comm_id)");

    // Tabela wątków (thread grouping) ─────────────────────────────────────────
    $exec("CREATE TABLE IF NOT EXISTS ezd_mail_threads (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        thread_key      TEXT    NOT NULL UNIQUE,
        sprawa_id       INTEGER REFERENCES ezd_sprawy(id) ON DELETE SET NULL,
        subject_norm    TEXT    NOT NULL DEFAULT '',
        last_message_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        message_count   INTEGER NOT NULL DEFAULT 0,
        unread_count    INTEGER NOT NULL DEFAULT 0,
        created_at      DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $exec("CREATE INDEX IF NOT EXISTS idx_ezd_thread_sprawa ON ezd_mail_threads(sprawa_id)");
    $exec("CREATE INDEX IF NOT EXISTS idx_ezd_thread_last   ON ezd_mail_threads(last_message_at DESC)");

    // Konfiguracja skrzynek wysyłkowych per użytkownik ────────────────────────
    $exec("CREATE TABLE IF NOT EXISTS ezd_mail_user_accounts (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        mailbox     TEXT    NOT NULL,
        display_name TEXT   NOT NULL DEFAULT '',
        is_default  INTEGER NOT NULL DEFAULT 0,
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(user_id, mailbox)
    )");
    $exec("CREATE INDEX IF NOT EXISTS idx_ezd_mail_acct_user ON ezd_mail_user_accounts(user_id)");
})();

// ── Stałe ────────────────────────────────────────────────────────────────────

/** Regex do wykrywania znaku sprawy EZD w temacie, np. [EZD: FEER.123.2026] lub [EZD:2026/5]. */
const EZD_SUBJECT_PATTERN = '/\[EZD:\s*([^\]]+)\]/i';

// ── Serwis ────────────────────────────────────────────────────────────────────

class EzdMailService
{
    private \PDO $pdo;

    public function __construct()
    {
        $this->pdo = db();
    }

    // ── Wykrywanie znaku EZD ──────────────────────────────────────────────────

    /**
     * Próbuje wyłuskać znak sprawy z tematu wiadomości.
     * Zwraca surowy ciąg znaku lub null.
     */
    public static function detectEzdSign(string $subject): ?string
    {
        if (preg_match(EZD_SUBJECT_PATTERN, $subject, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    /**
     * Szuka sprawy EZD pasującej do wykrytego znaku.
     * Porównuje `znak_sprawy` case-insensitively (LIKE); zwraca rekord sprawy lub null.
     */
    public static function findSprawaBySign(string $sign): ?array
    {
        $sign = trim($sign);
        if ($sign === '') return null;
        return db_one(
            "SELECT id, znak_sprawy, title, status FROM ezd_sprawy WHERE LOWER(znak_sprawy) = LOWER(?) LIMIT 1",
            [$sign]
        );
    }

    // ── Powiązanie komunikacja ↔ sprawa ───────────────────────────────────────

    /**
     * Przypisuje istniejącą crm_communications do sprawy EZD.
     * Jeśli jeszcze nie ma pisma przychodzącego, tworzy je w ezd_pisma.
     * Zwraca id nowo-utworzonego pisma lub null (gdy pismo już istniało).
     */
    public function linkCommToSprawa(int $comm_id, int $sprawa_id): ?int
    {
        $comm = db_one("SELECT * FROM crm_communications WHERE id=?", [$comm_id]);
        if (!$comm) return null;

        $this->pdo->prepare(
            "UPDATE crm_communications SET ezd_sprawa_id=?, updated_at=CURRENT_TIMESTAMP WHERE id=?"
        )->execute([$sprawa_id, $comm_id]);

        // Aktualizuj wątek powiązany
        if ($comm['thread_key']) {
            $this->pdo->prepare(
                "UPDATE ezd_mail_threads SET sprawa_id=? WHERE thread_key=?"
            )->execute([$sprawa_id, $comm['thread_key']]);
        }

        // Sprawdź czy pismo już nie istnieje
        $existing = db_one("SELECT id FROM ezd_pisma WHERE comm_id=?", [$comm_id]);
        if ($existing) return null;

        // Utwórz pismo w rejestrze EZD
        return $this->createPismoFromComm($comm, $sprawa_id);
    }

    /**
     * Zakłada nową koszulkę (sprawę) EZD z wiadomości e-mail i dowiązuje do niej pismo.
     * Wspólna ścieżka dla Poczty EZD i Skrzynki CRM — numeracja i znak sprawy powstają
     * w jednym miejscu, żeby oba widoki nie rozjechały się w formacie znaku.
     *
     * @return array{sprawa_id:int, pismo_id:?int, znak:string, url:string}
     */
    public function createSprawaFromComm(int $comm_id, int $teczka_id, string $title = ''): array
    {
        $comm = db_one("SELECT * FROM crm_communications WHERE id=?", [$comm_id]);
        if (!$comm) throw new \RuntimeException('Wiadomość nie istnieje.');
        $teczka = db_one("SELECT id, symbol FROM ezd_teczki WHERE id=?", [$teczka_id]);
        if (!$teczka) throw new \RuntimeException('Wybrany segregator nie istnieje.');

        $user_id = (int)(current_user()['id'] ?? 0);
        $numer   = (int)(db_one(
            "SELECT COALESCE(MAX(numer),0)+1 AS n FROM ezd_sprawy WHERE teczka_id=?", [$teczka_id]
        )['n'] ?? 1);
        $znak = $teczka['symbol'] . '/' . date('Y') . '/' . str_pad((string)$numer, 4, '0', STR_PAD_LEFT);

        $title = trim($title) !== '' ? $title : (string)($comm['subject'] ?? 'Nowa sprawa z e-mail');

        $this->pdo->prepare(
            "INSERT INTO ezd_sprawy (teczka_id, znak_sprawy, numer, title, owner_id, created_by)
             VALUES (?,?,?,?,?,?)"
        )->execute([$teczka_id, $znak, $numer, mb_substr($title, 0, 200), $user_id, $user_id]);
        $sprawa_id = (int)$this->pdo->lastInsertId();

        $pismo_id = $this->linkCommToSprawa($comm_id, $sprawa_id);

        return [
            'sprawa_id' => $sprawa_id,
            'pismo_id'  => $pismo_id,
            'znak'      => $znak,
            'url'       => APP_URL . '/ezd/sprawy/view.php?id=' . $sprawa_id,
        ];
    }

    /**
     * Tworzy rekord ezd_pisma na podstawie crm_communications.
     * Używane zarówno przy ingestion (kierunek=przychodzace) jak
     * i po potwierdzeniu odebranego pisma (kierunek=wychodzace).
     */
    private function createPismoFromComm(array $comm, int $sprawa_id): int
    {
        $sprawa = db_one("SELECT * FROM ezd_sprawy WHERE id=?", [$sprawa_id]);
        if (!$sprawa) throw new \RuntimeException("Sprawa #{$sprawa_id} nie istnieje.");

        $kierunek = ($comm['direction'] ?? 'in') === 'out' ? 'wychodzace' : 'przychodzace';

        $sygnatura = $this->nextSygnatura($sprawa_id, $kierunek);
        $user_id   = (int)($comm['sent_by'] ?? 0) ?: null;

        $this->pdo->prepare(
            "INSERT INTO ezd_pisma
               (sprawa_id, sygnatura, kierunek, title, rodzaj_medium, owner_id, created_by, comm_id)
             VALUES (?,?,?,?,'email',?,?,?)"
        )->execute([
            $sprawa_id,
            $sygnatura,
            $kierunek,
            mb_substr($comm['subject'] ?? '(bez tematu)', 0, 200),
            $user_id,
            $user_id,
            $comm['id'],
        ]);
        $pismo_id = (int)$this->pdo->lastInsertId();

        ezd_log(null, $sprawa_id, $pismo_id, null, $user_id ?? 0, 'pismo_email_linked',
            "Powiązano z komunikacją CRM #{$comm['id']} ({$kierunek})");

        return $pismo_id;
    }

    /**
     * Generuje kolejną sygnaturę pisma w ramach sprawy.
     * Format: ZNAK.WY.001 / ZNAK.PK.001 (WY=wychodzące, PK=przychodzące).
     */
    private function nextSygnatura(int $sprawa_id, string $kierunek): string
    {
        $sprawa = db_one("SELECT znak_sprawy FROM ezd_sprawy WHERE id=?", [$sprawa_id]);
        $znak   = $sprawa['znak_sprawy'] ?? "S{$sprawa_id}";
        $prefix = $kierunek === 'wychodzace' ? 'WY' : 'PK';
        $cnt    = (int)(db_one(
            "SELECT COUNT(*) AS n FROM ezd_pisma WHERE sprawa_id=? AND kierunek=?",
            [$sprawa_id, $kierunek]
        )['n'] ?? 0);
        return $znak . '.' . $prefix . '.' . str_pad($cnt + 1, 3, '0', STR_PAD_LEFT);
    }

    // ── Kompozycja wychodząca ─────────────────────────────────────────────────

    /**
     * Wysyła wiadomość e-mail z kontekstu EZD i zapisuje ją jako:
     *  - crm_communications (kierunek=out) z ezd_sprawa_id,
     *  - ezd_pisma (kierunek=wychodzace).
     *
     * $data: [
     *   sprawa_id, to_email, to_name?, subject, body_html,
     *   cc_emails?, bcc_emails?, attachments?, from_email?,
     *   contact_id?  (jeśli znany kontakt CRM)
     * ]
     * Zwraca ['comm_id'=>int, 'pismo_id'=>int].
     */
    public function compose(array $data): array
    {
        $sprawa_id  = (int)($data['sprawa_id'] ?? 0);
        $to_email   = trim($data['to_email'] ?? '');
        $to_name    = trim($data['to_name']   ?? $to_email);
        $body_html  = $data['body_html']  ?? '';
        $from_email = trim($data['from_email'] ?? '');
        $cc         = $data['cc_emails']   ?? [];
        $bcc        = $data['bcc_emails']  ?? [];
        $attachments = $data['attachments'] ?? [];

        if (!filter_var($to_email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException("Nieprawidłowy adres e-mail odbiorcy.");
        }
        if ($sprawa_id <= 0) {
            throw new \InvalidArgumentException("Wymagany numer sprawy.");
        }

        $sprawa = db_one("SELECT * FROM ezd_sprawy WHERE id=?", [$sprawa_id]);
        if (!$sprawa) throw new \RuntimeException("Sprawa #{$sprawa_id} nie istnieje.");

        // Wstrzyknij znak sprawy do tematu
        $subject = self::injectEzdSign(trim($data['subject'] ?? ''), $sprawa['znak_sprawy']);

        // Dołącz stopki (wzorzec z CrmManager::sendAndLog)
        $user_id = (int)(current_user()['id'] ?? 0);
        $html_body = $body_html;
        $user_row  = db_one("SELECT crm_email_signature FROM users WHERE id=?", [$user_id]);
        $user_sig  = trim($user_row['crm_email_signature'] ?? '');
        if ($user_sig !== '') {
            $html_body .= "\n<hr style=\"border:none;border-top:1px solid #e5e7eb;margin:1rem 0\">\n" . $user_sig;
        }
        $org_footer = trim(org_setting('crm_email_footer'));
        if ($org_footer !== '') {
            $html_body .= "\n<hr style=\"border:none;border-top:1px solid #e5e7eb;margin:1rem 0\">\n" . $org_footer;
        }

        // Wyślij przez mail_queue
        $contact_id = (int)($data['contact_id'] ?? 0) ?: $this->resolveContactByEmail($to_email);
        if (!$contact_id) {
            // Utwórz kontakt tymczasowy/anonimowy lub użyj dummy — CRM wymaga contact_id
            $contact_id = $this->ensureContactForEmail($to_email, $to_name);
        }

        mail_queue_add($to_email, $to_name, $subject, $html_body, '', 'ezd', $sprawa_id, '', true, $attachments, $from_email, $cc, $bcc);

        // Zaloguj w crm_communications
        $thread_key = $this->computeThreadKey($subject, $sprawa_id);
        $comm_id = db_insert('crm_communications', [
            'contact_id'     => $contact_id,
            'channel'        => 'email',
            'direction'      => 'out',
            'subject'        => $subject,
            'body'           => strip_tags(str_replace(['</p>','<br>'], "\n", $body_html)),
            'body_html'      => $html_body,
            'status'         => 'wysłana',
            'sent_by'        => $user_id,
            'sent_at'        => date('Y-m-d H:i:s'),
            'ezd_sprawa_id'  => $sprawa_id,
            'inbox_status'   => 'sent',
            'is_read'        => 1,
            'thread_key'     => $thread_key,
            'from_email'     => $from_email ?: '',
            'from_name'      => current_user()['name'] ?? '',
        ]);

        // Utwórz/zaktualizuj wątek
        $this->upsertThread($thread_key, $sprawa_id, $subject);

        // Utwórz pismo wychodzące
        $pismo_id = $this->createPismoFromComm(
            db_one("SELECT * FROM crm_communications WHERE id=?", [$comm_id]),
            $sprawa_id
        );

        return compact('comm_id', 'pismo_id');
    }

    /**
     * Wstrzykuje tag [EZD: ZNAK] do tematu (jeśli jeszcze go nie ma).
     */
    public static function injectEzdSign(string $subject, string $znak): string
    {
        if (preg_match(EZD_SUBJECT_PATTERN, $subject)) return $subject;
        $tag = '[EZD: ' . $znak . ']';
        return $subject !== '' ? $subject . ' ' . $tag : $tag;
    }

    // ── Ingestion (hook wywoływany z PocztaScanService::process_message) ──────

    /**
     * Wywoływany AFTER zapisania crm_communications — sprawdza temat pod kątem
     * znaku EZD, powiązuje sprawę, tworzy pismo przychodzące, aktualizuje wątek.
     *
     * @return int|null  ID pisma EZD (lub null gdy brak dopasowania lub błąd)
     */
    public function handleIncomingComm(int $comm_id): ?int
    {
        $comm = db_one("SELECT * FROM crm_communications WHERE id=?", [$comm_id]);
        if (!$comm) return null;

        $subject = $comm['subject'] ?? '';
        $sign    = self::detectEzdSign($subject);

        if ($sign !== null) {
            $sprawa = self::findSprawaBySign($sign);
            if ($sprawa) {
                return $this->linkCommToSprawa($comm_id, (int)$sprawa['id']);
            }
        }

        // Brak sprawy → trafia do Inboxu Ogólnego
        $thread_key = $this->computeThreadKey($subject, 0);
        $this->pdo->prepare(
            "UPDATE crm_communications SET thread_key=?, inbox_status='active', is_read=0 WHERE id=?"
        )->execute([$thread_key, $comm_id]);
        $this->upsertThread($thread_key, null, $subject, true);

        return null;
    }

    // ── Inbox Ogólny ──────────────────────────────────────────────────────────

    /**
     * Pobiera wiadomości do Inboxu Ogólnego.
     * $filters: status, sprawa_id, assigned_to, q (search), page, per_page
     */
    public function getInbox(array $filters = []): array
    {
        $where  = [];
        $params = [];

        $status = $filters['status'] ?? 'active';
        if ($status === 'sent') {
            $where[] = "c.direction='out'";
        } elseif ($status === 'unread') {
            $where[] = "c.direction='in' AND c.is_read=0 AND c.inbox_status='active'";
        } elseif (in_array($status, ['active','archived','spam'], true)) {
            $where[] = "c.inbox_status=? AND c.direction='in'";
            $params[] = $status;
        } else {
            $where[] = "c.inbox_status IN ('active','archived') AND c.direction='in'";
        }

        if (!empty($filters['sprawa_id'])) {
            $where[] = "c.ezd_sprawa_id=?";
            $params[] = (int)$filters['sprawa_id'];
        }
        if (!empty($filters['assigned_to'])) {
            $where[] = "c.assigned_to=?";
            $params[] = (int)$filters['assigned_to'];
        }
        if (!empty($filters['q'])) {
            $where[] = "(c.subject LIKE ? OR c.from_email LIKE ? OR c.from_name LIKE ?)";
            $q = '%' . $filters['q'] . '%';
            $params[] = $q; $params[] = $q; $params[] = $q;
        }
        if (!empty($filters['mailbox_id'])) {
            $where[] = "c.mailbox_id=?";
            $params[] = (int)$filters['mailbox_id'];
        }

        $where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $per_page  = max(10, min(100, (int)($filters['per_page'] ?? 25)));
        $page      = max(1, (int)($filters['page'] ?? 1));
        $offset    = ($page - 1) * $per_page;

        $total = (int)(db_one(
            "SELECT COUNT(*) AS n FROM crm_communications c $where_sql",
            $params
        )['n'] ?? 0);

        $rows = db_all(
            "SELECT c.*,
                    s.znak_sprawy, s.title AS sprawa_title,
                    u.name AS assigned_user,
                    m.mailbox AS mailbox_name,
                    pa.stored_path IS NOT NULL AS has_download
             FROM crm_communications c
             LEFT JOIN ezd_sprawy s     ON s.id = c.ezd_sprawa_id
             LEFT JOIN users u          ON u.id = c.assigned_to
             LEFT JOIN poczta_mailboxes m ON m.id = c.mailbox_id
             LEFT JOIN poczta_attachments pa ON pa.communication_id = c.id
             $where_sql
             GROUP BY c.id
             ORDER BY c.sent_at DESC
             LIMIT ? OFFSET ?",
            array_merge($params, [$per_page, $offset])
        );

        return compact('rows', 'total', 'page', 'per_page');
    }

    /**
     * Pobiera wszystkie wiadomości w wątku (dla widoku thread.php).
     */
    public function getThread(string $thread_key): array
    {
        return db_all(
            "SELECT c.*,
                    s.znak_sprawy,
                    u.name AS sender_user
             FROM crm_communications c
             LEFT JOIN ezd_sprawy s ON s.id = c.ezd_sprawa_id
             LEFT JOIN users u      ON u.id = c.sent_by
             WHERE c.thread_key = ?
             ORDER BY c.sent_at ASC",
            [$thread_key]
        );
    }

    // ── Zarządzanie statusami i przypisaniami ─────────────────────────────────

    public function markStatus(int $comm_id, string $status): void
    {
        $allowed = ['active', 'archived', 'spam', 'deleted'];
        if (!in_array($status, $allowed, true)) return;
        $this->pdo->prepare(
            "UPDATE crm_communications SET inbox_status=?, updated_at=CURRENT_TIMESTAMP WHERE id=?"
        )->execute([$status, $comm_id]);
    }

    public function markRead(int $comm_id, bool $read = true): void
    {
        $this->pdo->prepare(
            "UPDATE crm_communications SET is_read=?, updated_at=CURRENT_TIMESTAMP WHERE id=?"
        )->execute([$read ? 1 : 0, $comm_id]);
        // Zaktualizuj licznik nieprzeczytanych w wątku
        $comm = db_one("SELECT thread_key FROM crm_communications WHERE id=?", [$comm_id]);
        if ($comm && $comm['thread_key']) {
            $unread = (int)(db_one(
                "SELECT COUNT(*) AS n FROM crm_communications WHERE thread_key=? AND is_read=0",
                [$comm['thread_key']]
            )['n'] ?? 0);
            $this->pdo->prepare(
                "UPDATE ezd_mail_threads SET unread_count=? WHERE thread_key=?"
            )->execute([$unread, $comm['thread_key']]);
        }
    }

    /**
     * Przypisuje wiadomość do użytkownika (albo zdejmuje przypisanie: $user_id = null).
     *
     * Każda zmiana idzie do poczta_assign_log — to jedyne miejsce, przez które
     * przechodzą wszystkie przypisania, więc dziennik jest kompletny niezależnie
     * od tego, skąd akcja przyszła (Inbox EZD, karta pisma, Skrzynka CRM).
     * Przekazanie „dalej" to zwykłe przypisanie z podanym powodem — z punktu
     * widzenia akt liczy się para (od kogo, do kogo) i uzasadnienie.
     *
     * @param string   $note Powód / dyspozycja dla odbiorcy.
     * @param int|null $by   Kto przekazuje; domyślnie zalogowany użytkownik.
     */
    public function assignToUser(int $comm_id, ?int $user_id, string $note = '', ?int $by = null): void
    {
        $prev = db_one("SELECT assigned_to FROM crm_communications WHERE id=?", [$comm_id]);
        $from = $prev ? ((int)($prev['assigned_to'] ?? 0) ?: null) : null;
        $to   = $user_id ?: null;

        $this->pdo->prepare(
            "UPDATE crm_communications SET assigned_to=?, updated_at=CURRENT_TIMESTAMP WHERE id=?"
        )->execute([$to, $comm_id]);

        // Brak zmiany adresata bez treści dyspozycji nie jest zdarzeniem —
        // nie zaśmiecamy dziennika powtórzeniami tego samego przypisania.
        if ($from === $to && trim($note) === '') return;

        if ($by === null && function_exists('current_user')) {
            $by = (int)(current_user()['id'] ?? 0) ?: null;
        }

        try {
            $this->pdo->prepare(
                "INSERT INTO poczta_assign_log (comm_id, from_user_id, to_user_id, by_user_id, note, created_at)
                 VALUES (?,?,?,?,?,?)"
            )->execute([$comm_id, $from, $to, $by, trim($note), date('Y-m-d H:i:s')]);
        } catch (\Throwable $e) {
            // Dziennik nie może zablokować samego przekazania.
        }
    }

    /**
     * Historia przypisań i przekazań wiadomości — najstarsze pierwsze.
     *
     * @return list<array{id:int,note:string,created_at:string,from_name:?string,to_name:?string,by_name:?string}>
     */
    public function assignHistory(int $comm_id): array
    {
        try {
            return db_all(
                "SELECT l.id, l.note, l.created_at,
                        uf.name AS from_name, ut.name AS to_name, ub.name AS by_name
                   FROM poczta_assign_log l
                   LEFT JOIN users uf ON uf.id = l.from_user_id
                   LEFT JOIN users ut ON ut.id = l.to_user_id
                   LEFT JOIN users ub ON ub.id = l.by_user_id
                  WHERE l.comm_id = ?
               ORDER BY l.id",
                [$comm_id]
            );
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function assignToSprawa(int $comm_id, int $sprawa_id): ?int
    {
        return $this->linkCommToSprawa($comm_id, $sprawa_id);
    }

    // ── Stopki i szablony ─────────────────────────────────────────────────────

    /**
     * Zwraca podpis użytkownika i stopkę organizacyjną (do wstrzyknięcia w kompozytorze).
     */
    public static function fetchSignatures(?int $user_id = null): array
    {
        $user_id  = $user_id ?? (int)(current_user()['id'] ?? 0);
        $user_row = db_one(
            "SELECT name, first_name, last_name, email, crm_job_title, crm_display_phone,
                    phone_number, crm_email_signature
               FROM users WHERE id=?",
            [$user_id]
        ) ?? [];

        $user = trim((string)($user_row['crm_email_signature'] ?? ''));
        $org  = trim(org_setting('crm_email_footer'));

        // Gdy podpis/stopka nie są skonfigurowane, składamy je z danych, które
        // system i tak ma — inaczej „Wstaw mój podpis" / „Stopka org." nie robią
        // nic i wiadomość wychodzi bez identyfikacji nadawcy. Podpis z CRM
        // (users.crm_email_signature, Ustawienia konta) ma pierwszeństwo.
        if ($user === '') $user = self::defaultUserSignature($user_row);
        if ($org === '')  $org  = self::defaultOrgFooter();

        return ['user' => $user, 'org' => $org];
    }

    /**
     * Zapasowy podpis nadawcy z danych konta (imię i nazwisko, stanowisko CRM,
     * e-mail, telefon). Tylko dopuszczone tagi — treść przechodzi przez
     * strip_tags() w kompozytorze (ezd/poczta/compose.php).
     */
    public static function defaultUserSignature(array $u): string
    {
        $name = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')) ?: trim((string)($u['name'] ?? ''));
        if ($name === '') return '';

        $phone = trim((string)($u['crm_display_phone'] ?? '')) ?: trim((string)($u['phone_number'] ?? ''));
        $lines = ['<strong>' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</strong>'];

        if (!empty($u['crm_job_title'])) $lines[] = htmlspecialchars((string)$u['crm_job_title'], ENT_QUOTES, 'UTF-8');
        if ($org_name = trim(org_setting('org_name'))) $lines[] = htmlspecialchars($org_name, ENT_QUOTES, 'UTF-8');
        if (!empty($u['email'])) {
            $mail = htmlspecialchars((string)$u['email'], ENT_QUOTES, 'UTF-8');
            $lines[] = '<a href="mailto:' . $mail . '">' . $mail . '</a>';
        }
        if ($phone !== '') $lines[] = 'tel. ' . htmlspecialchars($phone, ENT_QUOTES, 'UTF-8');

        return '<div style="font-size:13px;line-height:1.55;color:#1f2937">'
             . implode('<br>', $lines) . '</div>';
    }

    /**
     * Zapasowa stopka organizacji z danych rejestrowych (Admin → Organizacja):
     * nazwa, adres, NIP/KRS/REGON. Używana, gdy nie ustawiono crm_email_footer.
     */
    public static function defaultOrgFooter(): string
    {
        $name = trim(org_setting('org_name'));
        if ($name === '') return '';

        $addr = trim(org_setting('org_adres'));
        $city = trim(org_setting('org_miejscowosc'));
        $reg  = [];
        foreach (['NIP' => 'org_nip', 'KRS' => 'org_krs', 'REGON' => 'org_regon'] as $label => $key) {
            if ($v = trim(org_setting($key))) $reg[] = $label . ': ' . htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        }

        $lines = ['<strong>' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</strong>'];
        if ($addr !== '' || $city !== '') {
            $lines[] = htmlspecialchars(trim($addr . ($addr && $city ? ', ' : '') . $city), ENT_QUOTES, 'UTF-8');
        }
        if ($reg) $lines[] = implode(' &middot; ', $reg);

        return '<div style="font-size:11px;line-height:1.5;color:#6b7280">'
             . implode('<br>', $lines) . '</div>';
    }

    /**
     * Podstawia zmienne szablonu w treści wiadomości EZD.
     * Obsługuje: {{sprawa.znak}}, {{sprawa.tytul}}, {{uzytkownik.imie_nazwisko}}, {{data}}, itp.
     */
    public static function renderVars(string $tpl, array $sprawa = [], ?array $user = null): string
    {
        $user = $user ?? current_user() ?? [];
        $vars = [
            '{{sprawa.znak}}'           => $sprawa['znak_sprawy'] ?? '',
            '{{sprawa.tytul}}'          => $sprawa['title']       ?? '',
            '{{sprawa.status}}'         => $sprawa['status']      ?? '',
            '{{uzytkownik.imie_nazwisko}}' => trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?: ($user['name'] ?? ''),
            '{{uzytkownik.email}}'      => $user['email'] ?? '',
            '{{data}}'                  => date('d.m.Y'),
            '{{data_czas}}'             => date('d.m.Y H:i'),
        ];
        return str_replace(array_keys($vars), array_values($vars), $tpl);
    }

    // ── Prywatne helpery ──────────────────────────────────────────────────────

    private function computeThreadKey(string $subject, int $sprawa_id): string
    {
        // Usuwamy prefiks Re:/Fwd: i tag EZD, normalizujemy
        $norm = preg_replace(['/^(re|fwd|fw):\s*/i', EZD_SUBJECT_PATTERN], '', $subject);
        $norm = mb_strtolower(trim($norm));
        if ($sprawa_id > 0) {
            return 'ezd.' . $sprawa_id . '.' . substr(md5($norm ?: 'pusta'), 0, 8);
        }
        return 'gen.' . substr(md5($norm ?: uniqid('t', true)), 0, 12);
    }

    private function upsertThread(string $thread_key, ?int $sprawa_id, string $subject, bool $is_unread = false): void
    {
        $norm = mb_strtolower(trim(preg_replace(['/^(re|fwd|fw):\s*/i', EZD_SUBJECT_PATTERN], '', $subject)));
        $this->pdo->prepare(
            "INSERT INTO ezd_mail_threads (thread_key, sprawa_id, subject_norm, last_message_at, message_count, unread_count)
             VALUES (?,?,?,CURRENT_TIMESTAMP,1,?)
             ON CONFLICT(thread_key) DO UPDATE
               SET sprawa_id=COALESCE(excluded.sprawa_id, sprawa_id),
                   last_message_at=excluded.last_message_at,
                   message_count=message_count+1,
                   unread_count=unread_count+?"
        )->execute([$thread_key, $sprawa_id, $norm, $is_unread ? 1 : 0, $is_unread ? 1 : 0]);
    }

    private function resolveContactByEmail(string $email): int
    {
        $row = db_one(
            "SELECT id FROM crm_contacts WHERE LOWER(email)=LOWER(?) AND crm_active=1 LIMIT 1",
            [$email]
        );
        return $row ? (int)$row['id'] : 0;
    }

    private function ensureContactForEmail(string $email, string $name): int
    {
        $existing = $this->resolveContactByEmail($email);
        if ($existing) return $existing;

        $parts = explode(' ', trim($name), 2);
        return CrmManager::createContact([
            'imie_nazwisko' => $name ?: $email,
            'email'         => $email,
            'crm_active'    => 1,
        ]);
    }
}
