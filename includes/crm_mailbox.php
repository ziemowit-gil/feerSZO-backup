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

/** Ładuje moduł poczty (tworzy tabele i kolumny). Zwraca false, gdy niedostępny. */
function crm_mailbox_ready(): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    $ok = false;
    try {
        require_once __DIR__ . '/poczta.php';
        db_one("SELECT COUNT(*) AS n FROM poczta_mailboxes");
        $ok = true;
    } catch (\Throwable $e) {
        error_log('[crm_mailbox_ready] ' . $e->getMessage());
    }
    return $ok;
}

/** Skrzynki, których wiadomości widzi CRM. */
function crm_mailbox_list(bool $only_enabled = true): array {
    if (!crm_mailbox_ready()) return [];
    $w = $only_enabled ? 'WHERE enabled=1' : '';
    try {
        return db_all("SELECT * FROM poczta_mailboxes $w ORDER BY mailbox");
    } catch (\Throwable $e) { return []; }
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

    if (!empty($f['mailbox_id']))  { $where[] = 'c.mailbox_id=?';  $params[] = (int)$f['mailbox_id']; }
    if (!empty($f['assigned_to'])) { $where[] = 'c.assigned_to=?'; $params[] = (int)$f['assigned_to']; }
    if (!empty($f['contact_id']))  { $where[] = 'c.contact_id=?';  $params[] = (int)$f['contact_id']; }
    if (!empty($f['q'])) {
        $q = '%' . trim((string)$f['q']) . '%';
        $where[] = '(c.subject LIKE ? OR c.from_email LIKE ? OR c.from_name LIKE ? OR ct.imie_nazwisko LIKE ?)';
        array_push($params, $q, $q, $q, $q);
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

    return ['rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $per];
}

/** Liczniki do zakładek widoków. */
function crm_mailbox_counts(): array {
    if (!crm_mailbox_ready()) return [];
    $uid = (int)(current_user()['id'] ?? 0);
    $out = [];
    foreach (array_keys(CRM_MAILBOX_VIEWS) as $view) {
        $params = [];
        $sql = _crm_mailbox_view_sql($view, $uid, $params);
        try {
            $out[$view] = (int)(db_one("SELECT COUNT(*) AS n FROM crm_communications c WHERE $sql", $params)['n'] ?? 0);
        } catch (\Throwable $e) { $out[$view] = 0; }
    }
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
                "SELECT id, direction, subject, sent_at, is_read FROM crm_communications
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
    if (!$boxes) return ['ok' => false, 'error' => 'Nie skonfigurowano żadnej skrzynki.'];

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
