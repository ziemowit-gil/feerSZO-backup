<?php
/**
 * includes/crm_mailbox.php — Skrzynka odbiorcza współdzielona w CRM.
 *
 * Czyta te same dane co Inbox Ogólny EZD (crm_communications + poczta_mailboxes,
 * zasilane przez PocztaScanService z cron/poczta_dispatch.php), ale:
 *   • bez zależności od modułu EZD (żadnych JOIN-ów do ezd_sprawy — CRM działa,
 *     gdy EZD jest wyłączone albo tabel EZD w ogóle nie ma),
 *   • z kontekstem sprzedażowym: kontakt, sprawy CRM, oferty,
 *   • uprawnienia CRM (can_read/can_write('crm')), nie ezd_require_access().
 *
 * Jedna baza wiadomości, dwa widoki — kancelaria pracuje w EZD, sprzedaż w CRM,
 * status „przeczytane" i przypisanie są wspólne, bo to te same kolumny.
 *
 * Ustawienia (settings):
 *   poczta_autocreate_contacts  '1' = nieznany nadawca zakłada kartotekę
 *                               (domyślnie '0' — wiadomość jest wtedy pomijana,
 *                                tak jak dotychczas w Inboksie EZD)
 */

require_once __DIR__ . '/crm.php';
require_once __DIR__ . '/poczta_acl.php';
require_once __DIR__ . '/mime_text.php';

/** Ładuje moduł poczty (tworzy tabele i kolumny). Zwraca false, gdy niedostępny. */
function crm_mailbox_ready(): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    $ok = false;
    try {
        require_once __DIR__ . '/poczta.php';
        db_one("SELECT COUNT(*) AS n FROM poczta_mailboxes");
        crm_mailbox_schema_heal();
        $ok = true;
    } catch (\Throwable $e) {
        error_log('[crm_mailbox_ready] ' . $e->getMessage());
    }
    return $ok;
}

/**
 * Kolumny dokładane przez Skrzynkę CRM (samonaprawa — moduł bywa włączany później):
 *   msg_no      6-cyfrowy numer wiadomości pokazywany ludziom („Nr 481203")
 *   crm_hidden  1 = „nie pokazuj więcej w CRM Inbox" (EZD/Poczta widzą dalej)
 */
function crm_mailbox_schema_heal(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo = db();
    foreach ([
        "ALTER TABLE crm_communications ADD COLUMN msg_no TEXT",
        "ALTER TABLE crm_communications ADD COLUMN crm_hidden INTEGER NOT NULL DEFAULT 0",
        "ALTER TABLE crm_communications ADD COLUMN crm_hidden_at DATETIME",
        "ALTER TABLE crm_communications ADD COLUMN crm_hidden_by INTEGER",
        "ALTER TABLE crm_communications ADD COLUMN ezd_notified_at DATETIME",
    ] as $sql) {
        try { $pdo->exec($sql); } catch (\Throwable $e) {}
    }
    try { $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_crm_comm_msgno ON crm_communications(msg_no)"); }
    catch (\Throwable $e) {}
}

/**
 * Numer wiadomości — 6 cyfr, stały i unikalny. Nadawany leniwie: pierwsza
 * wiadomość, którą ktoś otworzy albo wypisze na liście, dostaje numer i już go
 * nie zmienia. Dzięki temu numer mają też wiadomości sprzed wdrożenia funkcji.
 *
 * @param int         $id       ID wiersza crm_communications
 * @param string|null $existing Numer już odczytany z bazy (oszczędza zapytanie)
 */
function crm_msg_no(int $id, ?string $existing = null): string {
    if ($id <= 0) return '';
    $existing = trim((string)$existing);
    if ($existing !== '') return $existing;

    crm_mailbox_schema_heal();
    try {
        $cur = trim((string)(db_one("SELECT msg_no FROM crm_communications WHERE id=?", [$id])['msg_no'] ?? ''));
        if ($cur !== '') return $cur;

        for ($try = 0; $try < 12; $try++) {
            $no = (string)random_int(100000, 999999);
            $taken = db_one("SELECT id FROM crm_communications WHERE msg_no=?", [$no]);
            if ($taken) continue;
            db()->prepare("UPDATE crm_communications SET msg_no=? WHERE id=? AND (msg_no IS NULL OR msg_no='')")
                ->execute([$no, $id]);
            return trim((string)(db_one("SELECT msg_no FROM crm_communications WHERE id=?", [$id])['msg_no'] ?? $no));
        }
    } catch (\Throwable $e) {
        error_log('[crm_msg_no] ' . $e->getMessage());
    }
    return '';
}

/** Skrzynki, których wiadomości widzi bieżący użytkownik (wg poczta_mailbox_acl). */
function crm_mailbox_list(bool $only_enabled = true): array {
    if (!crm_mailbox_ready()) return [];
    $rows = poczta_mailboxes_for_user();
    if ($only_enabled) {
        $rows = array_values(array_filter($rows, static fn($r) => (int)($r['enabled'] ?? 1) === 1));
    }
    return $rows;
}

/**
 * Kolory skrzynek — na liście każda wiadomość dostaje pasek w kolorze skrzynki,
 * na którą wpłynęła. Kolor jest STAŁY: wynika z kolejności id skrzynek, więc nie
 * zmienia się przy filtrowaniu ani po odświeżeniu.
 */
const CRM_MAILBOX_COLORS = [
    '#0176D3', '#2E844A', '#B45309', '#7C3AED',
    '#0F766E', '#BE123C', '#0369A1', '#A16207',
];

function crm_mailbox_color(int $mailbox_id): string {
    static $map = null;
    if ($map === null) {
        $map = [];
        try {
            $ids = array_column(db_all("SELECT id FROM poczta_mailboxes ORDER BY id"), 'id');
        } catch (\Throwable $e) { $ids = []; }
        foreach (array_values($ids) as $i => $mid) {
            $map[(int)$mid] = CRM_MAILBOX_COLORS[$i % count(CRM_MAILBOX_COLORS)];
        }
    }
    return $map[$mailbox_id] ?? '#9CA3AF';
}

/** Czy nieznany nadawca ma zakładać kartotekę (wspólne z Inboksem EZD). */
function crm_mailbox_autocreate(): bool {
    return crm_setting('poczta_autocreate_contacts') === '1';
}

/** Widoki listy — etykiety, ikony i warunki SQL. */
const CRM_MAILBOX_VIEWS = [
    'new'        => ['label' => 'Nowe',            'icon' => 'bi-envelope-fill'],
    'mine'       => ['label' => 'Przypisane mi',   'icon' => 'bi-person-check-fill'],
    'unassigned' => ['label' => 'Bez opiekuna',    'icon' => 'bi-person-dash'],
    'all'        => ['label' => 'Wszystkie',       'icon' => 'bi-inbox-fill'],
    'archived'   => ['label' => 'Załatwione',      'icon' => 'bi-archive-fill'],
    'spam'       => ['label' => 'Spam',            'icon' => 'bi-slash-circle'],
    'hidden'     => ['label' => 'Ukryte',          'icon' => 'bi-eye-slash'],
    'sent'       => ['label' => 'Wysłane',         'icon' => 'bi-send'],
    'drafts'     => ['label' => 'Kopie robocze',   'icon' => 'bi-file-earmark-text'],
];

/** Warunek SQL dla widoku (bez prefiksu WHERE). */
function _crm_mailbox_view_sql(string $view, int $uid, array &$params): string {
    switch ($view) {
        case 'new':
            return "c.direction='in' AND c.inbox_status='active' AND c.is_read=0";
        case 'mine':
            $params[] = $uid;
            return "c.direction='in' AND c.inbox_status<>'deleted' AND c.assigned_to=?";
        case 'unassigned':
            return "c.direction='in' AND c.inbox_status='active' AND (c.assigned_to IS NULL OR c.assigned_to=0)";
        case 'archived':
            return "c.direction='in' AND c.inbox_status='archived'";
        case 'spam':
            return "c.direction='in' AND c.inbox_status='spam'";
        case 'hidden':
            return "c.direction='in' AND c.crm_hidden=1";
        // Wychodzące: wysłane (poszły albo czekają w kolejce) i „kopie robocze",
        // czyli wiadomości zapisane w historii BEZ wysyłki (odznaczone „wyślij teraz").
        case 'sent':
            return "c.direction='out' AND c.channel='email'"
                 . " AND (c.status LIKE 'wys%' OR c.status IN ('w kolejce','zsynchronizowana'))";
        case 'drafts':
            return "c.direction='out' AND c.channel='email' AND c.status='zaplanowana'";
        default:
            return "c.direction='in' AND c.inbox_status IN ('active','archived')";
    }
}

/**
 * Lista wiadomości skrzynki współdzielonej z kontekstem CRM.
 *
 * @param array $f view, mailbox_id, assigned_to, contact_id, q, page, per_page
 * @return array{rows:array,total:int,page:int,per_page:int}
 */
function crm_mailbox_inbox(array $f = []): array {
    if (!crm_mailbox_ready()) return ['rows' => [], 'total' => 0, 'page' => 1, 'per_page' => 25];

    $uid    = (int)(current_user()['id'] ?? 0);
    $view   = isset(CRM_MAILBOX_VIEWS[$f['view'] ?? '']) ? (string)$f['view'] : 'new';
    $params = [];
    $where  = [_crm_mailbox_view_sql($view, $uid, $params)];

    // „Nie pokazuj więcej w CRM Inbox" — poza widokiem „Ukryte" te wiadomości znikają
    if ($view !== 'hidden') $where[] = 'COALESCE(c.crm_hidden,0)=0';

    // Widok obejmuje wyłącznie skrzynki, do których użytkownik ma dostęp
    $where[] = poczta_scope_sql('c.mailbox_id');
    if (!empty($f['mailbox_id'])) {
        if (!poczta_can_access((int)$f['mailbox_id'], 'read')) return ['rows' => [], 'total' => 0, 'page' => 1, 'per_page' => 25];
        $where[] = 'c.mailbox_id=?'; $params[] = (int)$f['mailbox_id'];
    }
    if (!empty($f['assigned_to'])) { $where[] = 'c.assigned_to=?'; $params[] = (int)$f['assigned_to']; }
    if (!empty($f['contact_id']))  { $where[] = 'c.contact_id=?';  $params[] = (int)$f['contact_id']; }
    if (!empty($f['q'])) {
        $q = '%' . trim((string)$f['q']) . '%';
        $where[] = '(c.subject LIKE ? OR c.from_email LIKE ? OR c.from_name LIKE ? OR ct.imie_nazwisko LIKE ?'
                 . ' OR c.msg_no LIKE ?)';
        array_push($params, $q, $q, $q, $q, $q);
    }
    $where_sql = 'WHERE ' . implode(' AND ', $where);

    $per  = max(5, min(100, (int)($f['per_page'] ?? 25)));
    $page = max(1, (int)($f['page'] ?? 1));
    $off  = ($page - 1) * $per;

    $total = (int)(db_one(
        "SELECT COUNT(*) AS n FROM crm_communications c
         LEFT JOIN crm_contacts ct ON ct.id=c.contact_id $where_sql", $params
    )['n'] ?? 0);

    $rows = db_all(
        "SELECT c.id, c.contact_id, c.subject, c.body, c.sent_at, c.is_read, c.inbox_status,
                c.assigned_to, c.has_attachments, c.from_name, c.from_email, c.thread_key, c.mailbox_id,
                c.msg_no, COALESCE(c.crm_hidden,0) AS crm_hidden, c.direction, c.status,
                ct.imie_nazwisko AS contact_name, ct.type AS contact_type, ct.email AS contact_email,
                u.name AS assigned_name, m.mailbox AS mailbox_name
         FROM crm_communications c
         LEFT JOIN crm_contacts ct       ON ct.id = c.contact_id
         LEFT JOIN users u               ON u.id  = c.assigned_to
         LEFT JOIN poczta_mailboxes m    ON m.id  = c.mailbox_id
         $where_sql
         ORDER BY c.sent_at DESC, c.id DESC
         LIMIT $per OFFSET $off", $params
    );

    // Podgląd na liście też nie może pokazywać granic MIME i quoted-printable
    foreach ($rows as $i => $r) {
        $fixed = crm_mail_plaintext((string)($r['body'] ?? ''));
        if ($fixed !== null) $rows[$i]['body'] = $fixed;
    }

    return ['rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $per];
}

/** Liczniki do zakładek widoków. */
function crm_mailbox_counts(): array {
    if (!crm_mailbox_ready()) return [];
    $uid = (int)(current_user()['id'] ?? 0);
    $out = [];
    foreach (array_keys(CRM_MAILBOX_VIEWS) as $view) {
        $params = [];
        $sql = _crm_mailbox_view_sql($view, $uid, $params)
             . ($view !== 'hidden' ? ' AND COALESCE(c.crm_hidden,0)=0' : '')
             . ' AND ' . poczta_scope_sql('c.mailbox_id');
        try {
            $out[$view] = (int)(db_one("SELECT COUNT(*) AS n FROM crm_communications c WHERE $sql", $params)['n'] ?? 0);
        } catch (\Throwable $e) { $out[$view] = 0; }
    }
    return $out;
}

/**
 * Powiadamia NADAWCĘ, że jego sprawa trafiła do rejestru EZD.
 *
 * Treść jest stała (uzgodniona z fundacją) i wysyłana jako ZWYKŁY TEKST — bez
 * HTML-a, ramki _feer_email_tpl() i stylowania; stopka i klauzula RODO są
 * już w samej treści.
 *
 * Wysyłamy TYLKO przy założeniu NOWEJ koszulki. Dopięcie do już prowadzonej
 * sprawy nadawcy nie interesuje — o niej wie, korespondencja już trwa.
 *
 * Nie wysyłamy: gdy wyłączone ustawieniem `crm_ezd_notify_sender` = '0', gdy adres
 * nadawcy jest pusty/niepoprawny, gdy to nadawca automatyczny (noreply, mailer…)
 * albo gdy dla tej wiadomości powiadomienie już poszło (ezd_notified_at).
 *
 * @return bool czy wiadomość trafiła do kolejki
 */
function crm_mailbox_notify_ezd_sender(int $comm_id): bool {
    if (crm_setting('crm_ezd_notify_sender') === '0') return false;

    crm_mailbox_schema_heal();
    try {
        $m = db_one("SELECT id, contact_id, from_email, from_name, subject, thread_key, ezd_notified_at
                     FROM crm_communications WHERE id=?", [$comm_id]);
    } catch (\Throwable $e) { return false; }
    if (!$m || !empty($m['ezd_notified_at'])) return false;

    $to = trim((string)($m['from_email'] ?? ''));
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return false;

    require_once __DIR__ . '/crm_contact_analyzer.php';
    if (crm_is_robot_sender($to)) return false;          // do automatu nie ma po co pisać

    // Nie odpisujemy sami sobie — adresy naszych skrzynek odpadają
    try {
        foreach (db_all("SELECT mailbox FROM poczta_mailboxes") as $mb) {
            if (strcasecmp(trim((string)$mb['mailbox']), $to) === 0) return false;
        }
    } catch (\Throwable $e) {}

    $subject = 'Informacja o zarejestrowaniu sprawy w systemie EZD FEER';

    $text = "Szanowny Panie / Szanowna Pani,\n\n"
          . "Uprzejmie informujemy, że z uwagi na charakter Państwa sprawy, została ona zarejestrowana "
          . "w systemie Elektronicznego Zarządzania Dokumentacją FEER.\n\n"
          . "Niebawem otrzymają Państwo odpowiedź w tej sprawie. W temacie oraz treści korespondencji będzie "
          . "pojawiał się numer koszulki / wirtualnej teczki, co ułatwi identyfikację sprawy.\n\n"
          . "Informujemy również, że w związku z tym przetwarzamy Państwa dane osobowe w związku z potrzebą "
          . "załatwienia sprawy i prowadzoną korespondencją.\n\n"
          . "W przypadku dodatkowych pytań pozostajemy do dyspozycji.\n\n"
          . "Z poważaniem,\n\nFundacja Edukacji Empatii Rozwoju \"FEER\"\nul. W. Barbackiego 28/18\n"
          . "33-300 Nowy Sącz\nNIP: 7343570539\n\n"
          . "Klauzula informacyjna RODO:\nAdministratorem Państwa danych osobowych jest Fundacja Edukacji Empatii "
          . "Rozwoju \"FEER\" (ul. W. Barbackiego 28/18, 33-300 Nowy Sącz, NIP: 7343570539). Dane są przetwarzane "
          . "w celu załatwienia sprawy oraz prowadzenia korespondencji. Pełna treść klauzuli informacyjnej znajduje "
          . "się na naszej stronie internetowej pod adresem: feer.org.pl/rodo.";

    try {
        require_once __DIR__ . '/mail_queue.php';
        // Pusta wersja HTML = mail_queue wysyła czysty text/plain (Graph: contentType Text)
        mail_queue_add($to, (string)($m['from_name'] ?? ''), $subject, '', $text,
            'crm_ezd_notify', $comm_id, '', false);
    } catch (\Throwable $e) {
        error_log('[crm_mailbox_notify_ezd_sender] ' . $e->getMessage());
        return false;
    }

    try {
        db()->prepare("UPDATE crm_communications SET ezd_notified_at=? WHERE id=?")
            ->execute([date('Y-m-d H:i:s'), $comm_id]);
    } catch (\Throwable $e) {}

    // Ślad w historii kontaktu — inaczej nikt nie wie, że nadawca dostał tę informację
    try {
        db_insert('crm_communications', [
            'contact_id'    => (int)($m['contact_id'] ?? 0) ?: null,
            'channel'       => 'email',
            'direction'     => 'out',
            'template_name' => 'ezd_rejestracja',
            'subject'       => $subject,
            'body'          => $text,
            'status'        => 'w kolejce',
            'sent_by'       => (int)(current_user()['id'] ?? 0) ?: null,
            'sent_at'       => date('Y-m-d H:i:s'),
            'inbox_status'  => 'archived',
            'is_read'       => 1,
            'thread_key'    => $m['thread_key'] ?: null,
        ]);
    } catch (\Throwable $e) {
        error_log('[crm_mailbox_notify_ezd_sender] log: ' . $e->getMessage());
    }

    return true;
}

/**
 * „Nie pokazuj więcej w CRM Inbox" — wiadomość znika z widoków Skrzynki CRM
 * (zostaje w widoku „Ukryte"). Nie kasujemy jej i nie ruszamy inbox_status,
 * bo tę samą wiadomość widzi Poczta i EZD — to filtr CRM-owy, nie usunięcie.
 */
function crm_mailbox_set_hidden(int $id, bool $hidden = true): bool {
    crm_mailbox_schema_heal();
    $uid = (int)(current_user()['id'] ?? 0);
    try {
        db()->prepare("UPDATE crm_communications SET crm_hidden=?, crm_hidden_at=?, crm_hidden_by=? WHERE id=?")
            ->execute([$hidden ? 1 : 0, $hidden ? date('Y-m-d H:i:s') : null, $hidden ? ($uid ?: null) : null, $id]);
        return true;
    } catch (\Throwable $e) {
        error_log('[crm_mailbox_set_hidden] ' . $e->getMessage());
        return false;
    }
}

/**
 * Liczba wiadomości w BIEŻĄCYM widoku w rozbiciu na skrzynki — do selektora
 * skrzynek nad listą. Jedno zapytanie zamiast N liczników.
 *
 * @return array [mailbox_id => liczba]
 */
function crm_mailbox_counts_by_mailbox(string $view): array {
    if (!crm_mailbox_ready()) return [];
    $uid    = (int)(current_user()['id'] ?? 0);
    $view   = isset(CRM_MAILBOX_VIEWS[$view]) ? $view : 'new';
    $params = [];
    $where  = [_crm_mailbox_view_sql($view, $uid, $params)];
    if ($view !== 'hidden') $where[] = 'COALESCE(c.crm_hidden,0)=0';
    $where[] = poczta_scope_sql('c.mailbox_id');

    try {
        $rows = db_all(
            "SELECT c.mailbox_id AS mid, COUNT(*) AS n FROM crm_communications c
             WHERE " . implode(' AND ', $where) . " GROUP BY c.mailbox_id", $params
        );
    } catch (\Throwable $e) { return []; }

    $out = [];
    foreach ($rows as $r) $out[(int)$r['mid']] = (int)$r['n'];
    return $out;
}

/** Jedna wiadomość z pełną treścią i kontekstem kontaktu. */
function crm_mailbox_message(int $id): ?array {
    if (!crm_mailbox_ready()) return null;
    $m = db_one(
        "SELECT c.*, ct.imie_nazwisko AS contact_name, ct.type AS contact_type, ct.email AS contact_email,
                ct.telefon AS contact_phone, ct.nip AS contact_nip, ct.status AS contact_status,
                u.name AS assigned_name, mb.mailbox AS mailbox_name
         FROM crm_communications c
         LEFT JOIN crm_contacts ct    ON ct.id = c.contact_id
         LEFT JOIN users u            ON u.id  = c.assigned_to
         LEFT JOIN poczta_mailboxes mb ON mb.id = c.mailbox_id
         WHERE c.id=?", [$id]
    );
    if (!$m) return null;
    // Wiadomość ze skrzynki, do której nie ma dostępu, nie może być otwarta z URL-a
    $mb_id = (int)($m['mailbox_id'] ?? 0);
    if ($mb_id && !poczta_can_access($mb_id, 'read')) return null;
    if (!$mb_id && !(function_exists('is_admin') && is_admin())) return null;

    // Surowy MIME w treści (także w body_html) — rozpakuj i zapisz naprawioną
    // wersję, żeby kolejne otwarcia, lista i EZD miały już czysto.
    $fixed = crm_mail_normalize((string)($m['body'] ?? ''), (string)($m['body_html'] ?? ''));
    if ($fixed !== null) {
        $m['body']      = $fixed['body'];
        $m['body_html'] = $fixed['body_html'];
        try {
            db()->prepare("UPDATE crm_communications SET body=?, body_html=? WHERE id=?")
                ->execute([$fixed['body'], $fixed['body_html'] ?: null, $id]);
        } catch (\Throwable $e) {
            error_log('[crm_mailbox_message] mime fix: ' . $e->getMessage());
        }
    }

    $m['attachments'] = [];
    try {
        $m['attachments'] = db_all(
            "SELECT id, original_name, mime_type, size_bytes, stored_path
             FROM poczta_attachments WHERE communication_id=? ORDER BY id", [$id]
        );
    } catch (\Throwable $e) {}

    // Kontekst sprzedażowy kontaktu — po to jest skrzynka w CRM, a nie w kliencie poczty
    $m['ctx'] = ['cases' => [], 'offers' => [], 'thread' => []];
    if (!empty($m['contact_id'])) {
        $cid = (int)$m['contact_id'];
        try {
            $m['ctx']['cases'] = db_all(
                "SELECT id, title, status FROM crm_cases WHERE contact_id=? ORDER BY updated_at DESC LIMIT 5", [$cid]
            );
        } catch (\Throwable $e) {}
        try {
            $m['ctx']['offers'] = db_all(
                "SELECT id, offer_number, title, status, total_gross, currency
                 FROM crm_offers WHERE contact_id=? AND deleted_at IS NULL
                 ORDER BY created_at DESC LIMIT 5", [$cid]
            );
        } catch (\Throwable $e) {}
    }
    if (!empty($m['thread_key'])) {
        try {
            $m['ctx']['thread'] = db_all(
                "SELECT id, direction, subject, sent_at, is_read, msg_no FROM crm_communications
                 WHERE thread_key=? ORDER BY sent_at ASC LIMIT 30", [$m['thread_key']]
            );
        } catch (\Throwable $e) {}
    }
    return $m;
}

// ─────────────────────────────────────────────────────────────────────────────
// AKCJE
// ─────────────────────────────────────────────────────────────────────────────

function crm_mailbox_mark_read(int $id, bool $read = true): void {
    db()->prepare("UPDATE crm_communications SET is_read=? WHERE id=?")->execute([$read ? 1 : 0, $id]);
}

function crm_mailbox_set_status(int $id, string $status): bool {
    if (!in_array($status, ['active', 'archived', 'spam', 'deleted'], true)) return false;
    db()->prepare("UPDATE crm_communications SET inbox_status=? WHERE id=?")->execute([$status, $id]);
    return true;
}

function crm_mailbox_assign(int $id, ?int $user_id): void {
    db()->prepare("UPDATE crm_communications SET assigned_to=? WHERE id=?")->execute([$user_id ?: null, $id]);
}

/**
 * Zakłada sprawę CRM na podstawie wiadomości i przypisuje ją do bieżącego użytkownika.
 * Wiadomość idzie do „załatwionych" — dalsza praca toczy się na sprawie.
 */
function crm_mailbox_create_case(int $id, array $opts = []): array {
    $m = crm_mailbox_message($id);
    if (!$m) return ['ok' => false, 'error' => 'Wiadomość nie istnieje.'];
    if (empty($m['contact_id'])) return ['ok' => false, 'error' => 'Wiadomość nie ma powiązanego kontaktu.'];

    $uid   = (int)(current_user()['id'] ?? 0);
    $body  = trim((string)($m['body'] ?? ''));
    $prio  = in_array($opts['priority'] ?? '', ['low', 'medium', 'high'], true) ? $opts['priority'] : 'medium';
    $title = trim((string)($opts['title'] ?? '')) ?: (trim((string)($m['subject'] ?? '')) ?: 'Wiadomość e-mail');
    $case_id = db_insert('crm_cases', [
        'contact_id'  => (int)$m['contact_id'],
        'title'       => mb_substr($title, 0, 200),
        'description' => "Z wiadomości od " . (string)($m['from_email'] ?: $m['contact_email']) . ' ('
                       . date('d.m.Y H:i', strtotime((string)$m['sent_at'])) . "):\n\n"
                       . mb_substr($body, 0, 4000),
        'status'      => 'open',
        'priority'    => $prio,
        'created_by'  => $uid ?: null,
        'created_at'  => date('Y-m-d H:i:s'),
        'updated_at'  => date('Y-m-d H:i:s'),
    ]);
    crm_mailbox_assign($id, (int)($opts['assign_to'] ?? 0) ?: $uid);
    crm_mailbox_mark_read($id, true);
    // Wiadomość zostaje w skrzynce tylko, gdy operator wyraźnie tego chce
    if (empty($opts['keep_open'])) crm_mailbox_set_status($id, 'archived');
    try {
        require_once __DIR__ . '/crm_automation.php';
        crm_automation_fire('case_created', (int)$m['contact_id'], ['case_id' => $case_id]);
    } catch (\Throwable $e) {}

    return ['ok' => true, 'case_id' => $case_id, 'url' => APP_URL . '/crm/cases/view.php?id=' . $case_id];
}

// ─────────────────────────────────────────────────────────────────────────────
// PRZEKAZANIE WIADOMOŚCI DALEJ
// ─────────────────────────────────────────────────────────────────────────────

/** Czy da się przekazać wiadomość do EZD (moduł włączony, uprawnienia, tabele). */
function crm_mailbox_ezd_available(): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    $ok = false;
    try {
        if (!function_exists('module_enabled') || !module_enabled('ezd_enabled')) return $ok;
        if (!is_admin() && !can_write('ezd')) return $ok;
        db_one("SELECT COUNT(*) AS n FROM ezd_sprawy");
        db_one("SELECT COUNT(*) AS n FROM ezd_teczki");
        require_once __DIR__ . '/ezd_mail.php';
        $ok = class_exists('EzdMailService');
    } catch (\Throwable $e) {
        $ok = false;
    }
    return $ok;
}

/** Segregatory (teczki) EZD do wyboru przy zakładaniu koszulki z wiadomości. */
function crm_mailbox_ezd_teczki(int $limit = 200): array {
    if (!crm_mailbox_ezd_available()) return [];
    try {
        return db_all("SELECT id, symbol, title FROM ezd_teczki ORDER BY symbol LIMIT $limit");
    } catch (\Throwable $e) { return []; }
}

/** Otwarte koszulki EZD — do dopięcia wiadomości do istniejącej sprawy. */
function crm_mailbox_ezd_sprawy(string $q = '', int $limit = 30): array {
    if (!crm_mailbox_ezd_available()) return [];
    try {
        if ($q !== '') {
            $like = '%' . $q . '%';
            return db_all(
                "SELECT id, znak_sprawy, title FROM ezd_sprawy
                 WHERE status <> 'closed' AND (znak_sprawy LIKE ? OR title LIKE ?)
                 ORDER BY updated_at DESC LIMIT $limit", [$like, $like]
            );
        }
        return db_all(
            "SELECT id, znak_sprawy, title FROM ezd_sprawy
             WHERE status <> 'closed' ORDER BY updated_at DESC LIMIT $limit"
        );
    } catch (\Throwable $e) { return []; }
}

/**
 * Przekazuje wiadomość do EZD: dopina do wskazanej koszulki albo zakłada nową
 * w wybranym segregatorze. Rejestr pism i numeracja po stronie EzdMailService,
 * żeby Skrzynka CRM i Poczta EZD nie rozjechały się w formacie znaku sprawy.
 *
 * @return array{ok:bool, error:string, url:string, znak:string}
 */
function crm_mailbox_to_ezd(int $comm_id, ?int $sprawa_id = null, ?int $teczka_id = null): array {
    $out = ['ok' => false, 'error' => '', 'url' => '', 'znak' => ''];
    if (!crm_mailbox_ezd_available()) {
        $out['error'] = 'Moduł EZD jest wyłączony albo nie masz w nim uprawnień do zapisu.';
        return $out;
    }
    require_once __DIR__ . '/ezd_mail.php';
    $svc = new EzdMailService();

    try {
        $new_sprawa = false;
        if ($sprawa_id) {
            $svc->linkCommToSprawa($comm_id, $sprawa_id);
            $sp = db_one("SELECT znak_sprawy FROM ezd_sprawy WHERE id=?", [$sprawa_id]);
            $out = [
                'ok' => true, 'error' => '',
                'znak' => (string)($sp['znak_sprawy'] ?? ''),
                'url'  => APP_URL . '/ezd/sprawy/view.php?id=' . $sprawa_id,
            ];
        } elseif ($teczka_id) {
            $r = $svc->createSprawaFromComm($comm_id, $teczka_id);
            $out = ['ok' => true, 'error' => '', 'znak' => $r['znak'], 'url' => $r['url']];
            $new_sprawa = true;
        } else {
            $out['error'] = 'Wskaż koszulkę EZD albo segregator dla nowej koszulki.';
            return $out;
        }
    } catch (\Throwable $e) {
        $out['error'] = $e->getMessage();
        return $out;
    }

    // Wiadomość obsłużona — znika ze skrzynki, ale zostaje w historii kontaktu
    crm_mailbox_mark_read($comm_id, true);
    crm_mailbox_set_status($comm_id, 'archived');

    // Nadawca dowiaduje się, że jego sprawa jest już w rejestrze EZD — tylko przy
    // NOWEJ koszulce; przy dopięciu do istniejącej korespondencja już trwa.
    $out['notified'] = $new_sprawa && crm_mailbox_notify_ezd_sender($comm_id);
    return $out;
}

/**
 * Przekazuje wiadomość e-mailem dalej (do osoby albo na inną skrzynkę),
 * z cytatem oryginału i notatką od przekazującego. Zapisuje wpis wychodzący
 * w historii komunikacji kontaktu, żeby było widać, komu przekazano sprawę.
 */
function crm_mailbox_forward(int $comm_id, string $to, string $note = ''): array {
    $out = ['ok' => false, 'error' => ''];
    $to  = trim($to);
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) { $out['error'] = 'Podaj poprawny adres e-mail.'; return $out; }

    $m = crm_mailbox_message($comm_id);
    if (!$m) { $out['error'] = 'Wiadomość nie istnieje.'; return $out; }

    $orig = (string)($m['body_html'] ?? '');
    if ($orig === '') $orig = nl2br(h((string)($m['body'] ?? '')));

    $head = '<p><strong>Wiadomość przekazana z systemu SZO</strong></p>';
    if ($note !== '') $head .= '<p>' . nl2br(h($note)) . '</p>';
    $head .= '<hr><p style="font-size:13px;color:#6B7280">'
           . 'Od: ' . h((string)($m['from_name'] ?: '')) . ' &lt;' . h((string)($m['from_email'] ?: '')) . '&gt;<br>'
           . 'Data: ' . h(date('d.m.Y H:i', strtotime((string)$m['sent_at']))) . '<br>'
           . 'Temat: ' . h((string)($m['subject'] ?: '(bez tematu)')) . '</p>';

    $subject = 'FW: ' . ((string)($m['subject'] ?: '(bez tematu)'));

    try {
        require_once __DIR__ . '/mail_queue.php';
        mail_queue_add($to, '', $subject, _feer_email_tpl($head . $orig, 'Przekazana wiadomość'),
            '', 'crm_inbox_forward', $comm_id, '', false);
    } catch (\Throwable $e) {
        $out['error'] = 'Nie udało się dodać wiadomości do kolejki: ' . $e->getMessage();
        return $out;
    }

    try {
        db_insert('crm_communications', [
            'contact_id'    => (int)$m['contact_id'],
            'channel'       => 'email',
            'direction'     => 'out',
            'template_name' => 'przekazanie',
            'subject'       => $subject . ' → ' . $to,
            'body'          => ($note !== '' ? $note . "\n\n" : '') . 'Przekazano wiadomość z ' . $to . '.',
            'status'        => 'w kolejce',
            'sent_by'       => (int)(current_user()['id'] ?? 0) ?: null,
            'sent_at'       => date('Y-m-d H:i:s'),
            'inbox_status'  => 'archived',
            'is_read'       => 1,
            'thread_key'    => $m['thread_key'] ?: null,
        ]);
    } catch (\Throwable $e) {
        error_log('[crm_mailbox_forward] log: ' . $e->getMessage());
    }

    crm_mailbox_mark_read($comm_id, true);
    $out['ok'] = true;
    return $out;
}

/** Ręczne skanowanie skrzynki („Sprawdź teraz") — jedna skrzynka albo wszystkie włączone. */
function crm_mailbox_scan(?int $mailbox_id = null): array {
    if (!crm_mailbox_ready()) return ['ok' => false, 'error' => 'Moduł poczty niedostępny.'];
    $boxes = $mailbox_id
        ? array_filter(crm_mailbox_list(false), static fn($b) => (int)$b['id'] === $mailbox_id)
        : crm_mailbox_list(true);
    $boxes = array_filter($boxes, static fn($b) => poczta_can_access((int)$b['id'], 'manage'));
    if (!$boxes) return ['ok' => false, 'error' => 'Brak skrzynki, którą możesz skanować (potrzebne uprawnienie zarządzania).'];

    $sum = ['fetched' => 0, 'created' => 0, 'matched' => 0, 'skipped' => 0, 'errors' => []];
    $svc = new PocztaScanService();
    foreach ($boxes as $b) {
        try {
            $r = $svc->scan_mailbox((int)$b['id']);
            foreach (['fetched', 'created', 'matched', 'skipped'] as $k) {
                $sum[$k] += (int)($r[$k] ?? 0);
            }
            if (!empty($r['error'])) $sum['errors'][] = $b['mailbox'] . ': ' . $r['error'];
        } catch (\Throwable $e) {
            $sum['errors'][] = $b['mailbox'] . ': ' . $e->getMessage();
        }
    }
    return ['ok' => true] + $sum;
}
