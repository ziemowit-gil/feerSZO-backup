<?php
/**
 * includes/helpdesk.php — Moduł Helpdesk IT
 */

const HD_STATUSES = [
    'nowe'            => ['label' => 'Nowe',                            'class' => 'primary',   'icon' => 'bi-inbox-fill',         'text' => 'primary'],
    'otwarte'         => ['label' => 'Otwarte',                         'class' => 'warning',   'icon' => 'bi-folder2-open',       'text' => 'dark'],
    'oczekuje'        => ['label' => 'Oczekuje',                        'class' => 'secondary', 'icon' => 'bi-hourglass-split',    'text' => 'white'],
    'krytyczne'       => ['label' => 'Krytyczne — wymaga interwencji',  'class' => 'danger',    'icon' => 'bi-exclamation-octagon-fill', 'text' => 'white'],
    'przekazane_zewn' => ['label' => 'Przekazano do firmy zewnętrznej', 'class' => 'dark',      'icon' => 'bi-box-arrow-up-right', 'text' => 'white'],
    'zastepcze'       => ['label' => 'Rozwiązanie zastępcze (upadłość firmy zewn.)', 'class' => 'dark', 'icon' => 'bi-shield-exclamation', 'text' => 'white'],
    'wymaga_prac'     => ['label' => 'Wymaga prac programistycznych',   'class' => 'info',      'icon' => 'bi-code-slash',         'text' => 'dark'],
    'rozwiązane'      => ['label' => 'Rozwiązane',                      'class' => 'info',      'icon' => 'bi-check-circle-fill',  'text' => 'dark'],
    'zamknięte'       => ['label' => 'Zamknięte',                       'class' => 'success',   'icon' => 'bi-lock-fill',          'text' => 'white'],
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
    'zgl_blad'          => 'Błąd w systemie',
    'zgl_sugestia'      => 'Sugestia dot. obecnej funkcji',
    'zgl_nowa_funkcja'  => 'Propozycja nowej funkcji',
    'bug_report'        => 'Zgłoszenie błędu', // legacy — zachowane do wyświetlania starszych zgłoszeń
];

// Prefiksy numeracji zgłoszeń (zob. hd_next_number()) per kategoria zgłoszenia/sugestii/funkcji.
const HD_CATEGORY_PREFIXES = [
    'zgl_blad'         => 'HER',
    'zgl_sugestia'     => 'SUG',
    'zgl_nowa_funkcja' => 'HNF',
    'bug_report'       => 'HER',
];

function hd_number_prefix_for(string $category): string {
    return HD_CATEGORY_PREFIXES[$category] ?? 'HD';
}

// Priorytety + cele SLA (w minutach): czas reakcji (pierwsza odpowiedź operatora)
// oraz czas rozwiązania. Liczone w czasie kalendarzowym od utworzenia zgłoszenia.
const HD_PRIORITIES = [
    'niski'    => ['label' => 'Niski',     'class' => 'success',   'order' => 1, 'sla_response' => 1440, 'sla_resolve' => 4320],
    'normalny' => ['label' => 'Normalny',  'class' => 'secondary', 'order' => 2, 'sla_response' => 480,  'sla_resolve' => 1440],
    'wysoki'   => ['label' => 'Wysoki',    'class' => 'warning',   'order' => 3, 'sla_response' => 120,  'sla_resolve' => 480],
    'krytyczny'=> ['label' => 'Krytyczny', 'class' => 'danger',    'order' => 4, 'sla_response' => 30,   'sla_resolve' => 240],
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
    try { $pdo->exec("ALTER TABLE users ADD COLUMN helpdesk_intro_seen_at DATETIME"); } catch (\Throwable $e) {}

    // Samonaprawa schematu — kolumny dla przekazania do firmy zewnętrznej
    // oraz token publicznego mikropanelu podglądu/odpowiedzi.
    foreach ([
        "ALTER TABLE helpdesk_tickets ADD COLUMN ext_vendor    TEXT",
        "ALTER TABLE helpdesk_tickets ADD COLUMN ext_ref       TEXT",
        "ALTER TABLE helpdesk_tickets ADD COLUMN ext_reason    TEXT",
        "ALTER TABLE helpdesk_tickets ADD COLUMN ext_handed_at DATETIME",
        "ALTER TABLE helpdesk_tickets ADD COLUMN access_token  TEXT",
        "ALTER TABLE helpdesk_tickets ADD COLUMN first_response_at DATETIME", // SLA: pierwsza odpowiedź operatora
        "ALTER TABLE helpdesk_tickets ADD COLUMN merged_into INTEGER",        // łączenie: id zgłoszenia głównego
        "ALTER TABLE helpdesk_tickets ADD COLUMN redmine_issue_id INTEGER",   // integracja Redmine: numer issue
        "ALTER TABLE helpdesk_tickets ADD COLUMN redmine_last_journal_id INTEGER NOT NULL DEFAULT 0", // ost. zaimportowana notatka
    ] as $sql) { try { $pdo->exec($sql); } catch (\Throwable $e) {} }
    // SQLite dopuszcza wiele NULL w UNIQUE — token unikalny tylko dla wypełnionych.
    try { $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_hd_token ON helpdesk_tickets(access_token)"); } catch (\Throwable $e) {}

    // Makra / gotowe odpowiedzi (edytowalne przez admina)
    $pdo->exec("CREATE TABLE IF NOT EXISTS helpdesk_macros (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        title      TEXT    NOT NULL,
        body       TEXT    NOT NULL DEFAULT '',
        sort_order INTEGER NOT NULL DEFAULT 0,
        is_active  INTEGER NOT NULL DEFAULT 1,
        created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // Licznik nieodczytanych — ostatni odczyt zgłoszenia per operator
    $pdo->exec("CREATE TABLE IF NOT EXISTS helpdesk_reads (
        ticket_id INTEGER NOT NULL REFERENCES helpdesk_tickets(id) ON DELETE CASCADE,
        user_id   INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        read_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (ticket_id, user_id)
    )");

    // Podbicia zgłoszeń — formalna eskalacja "brak reakcji" zgłaszana samodzielnie
    // przez zgłaszającego, z osobną numeracją do wydruku PDF (potwierdzenie).
    $pdo->exec("CREATE TABLE IF NOT EXISTS helpdesk_escalations (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        ticket_id       INTEGER NOT NULL REFERENCES helpdesk_tickets(id) ON DELETE CASCADE,
        number          TEXT    NOT NULL UNIQUE,
        reason          TEXT    NOT NULL DEFAULT '',
        requester_name  TEXT    NOT NULL DEFAULT '',
        requester_email TEXT,
        requester_phone TEXT,
        days_waiting    INTEGER NOT NULL DEFAULT 0,
        created_by      INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at      DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_hd_esc_ticket ON helpdesk_escalations(ticket_id)");

    // Seed makr domyślnych — tylko jeśli tabela dopiero co powstała (pusta)
    try {
        $macro_count = (int)($pdo->query("SELECT COUNT(*) FROM helpdesk_macros")->fetchColumn());
        if ($macro_count === 0) {
            $seed = [
                [1, 'Przyjęto zgłoszenie',
                 "Dzień dobry,\n\nDziękujemy za zgłoszenie. Przyjęliśmy je do realizacji i zajmiemy się nim najszybciej, jak to możliwe. O postępach będziemy informować w tym wątku.\n\nPozdrawiam,\nHelpdesk IT"],
                [2, 'Prośba o dodatkowe informacje',
                 "Dzień dobry,\n\nAby sprawnie zająć się zgłoszeniem, prosimy o dodatkowe informacje:\n- \n- \n\nPo otrzymaniu odpowiedzi wrócimy do sprawy. Możesz odpowiedzieć bezpośrednio w tym wątku.\n\nPozdrawiam,\nHelpdesk IT"],
                [3, 'Krytyczne — wymaga interwencji',
                 "Dzień dobry,\n\nZgłoszenie zostało oznaczone jako <strong>krytyczne i wymagające natychmiastowej interwencji</strong>. Nasz zespół zajmie się nim priorytetowo.\n\nJeśli sprawa dotyczy awarii lub uniemożliwia pracę, prosimy o kontakt telefoniczny z helpdeskiem.\n\nPozdrawiam,\nHelpdesk IT"],
                [4, 'Przekazano do firmy zewnętrznej',
                 "Dzień dobry,\n\nUprzejmie informujemy, że zgłoszenie zostało przekazane do firmy zewnętrznej, która zajmie się jego dalszą realizacją.\n\nDalsze aktualizacje będą się pojawiać w tym wątku. W razie pytań prosimy o odpowiedź na tę wiadomość.\n\nPozdrawiam,\nHelpdesk IT"],
                [5, 'Wymaga prac programistycznych',
                 "Dzień dobry,\n\nDziękujemy za zgłoszenie. Opisana sprawa wymaga prac programistycznych, dlatego nie rozwiążemy jej od razu — zaplanowaliśmy ją do wdrożenia w jednej z najbliższych wersji systemu.\n\nO udostępnieniu zmiany poinformujemy w tym wątku. Dziękujemy za cierpliwość i cenną uwagę.\n\nPozdrawiam,\nHelpdesk IT"],
                [6, 'Rozwiązano — prośba o potwierdzenie',
                 "Dzień dobry,\n\nZgłoszenie zostało rozwiązane. Prosimy o sprawdzenie i potwierdzenie, czy wszystko działa prawidłowo. Jeśli w ciągu kilku dni nie otrzymamy odpowiedzi, zgłoszenie zostanie automatycznie zamknięte.\n\nPozdrawiam,\nHelpdesk IT"],
                [7, 'Zamknięcie zgłoszenia',
                 "Dzień dobry,\n\nZamykamy zgłoszenie. Dziękujemy za kontakt — jeśli problem powróci lub pojawią się nowe pytania, prosimy o utworzenie nowego zgłoszenia.\n\nPozdrawiam,\nHelpdesk IT"],
            ];
            $ins = $pdo->prepare("INSERT INTO helpdesk_macros (sort_order, title, body, is_active) VALUES (?,?,?,1)");
            foreach ($seed as [$sort, $title, $body]) {
                $ins->execute([$sort, $title, $body]);
            }
        }
    } catch (\Throwable $e) {}

    try {
        $s = db_one("SELECT id FROM settings WHERE key_='helpdesk_enabled'");
        if (!$s) $pdo->prepare("INSERT INTO settings (key_, value) VALUES (?,?)")->execute(['helpdesk_enabled', '1']);
    } catch (\Throwable $e) {}

    try {
        $s = db_one("SELECT id FROM settings WHERE key_='bug_report_enabled'");
        if (!$s) $pdo->prepare("INSERT INTO settings (key_, value) VALUES (?,?)")->execute(['bug_report_enabled', '1']);
    } catch (\Throwable $e) {}

    // Szablony e-mail — edytowalne przez admina; domyślne zachowanie zachowuje się
    // jak dotychczas gdy is_active=0 (wiersz istnieje, ale wyłączony).
    $pdo->exec("CREATE TABLE IF NOT EXISTS helpdesk_email_templates (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        key_       TEXT NOT NULL UNIQUE,
        label      TEXT NOT NULL,
        subject    TEXT NOT NULL DEFAULT '',
        body_html  TEXT NOT NULL DEFAULT '',
        placeholders TEXT NOT NULL DEFAULT '[]',
        is_active  INTEGER NOT NULL DEFAULT 0,
        updated_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    // Seed — rekordy seed tworzone raz (puste body; aktywacja przez admina)
    $tpl_seed = [
        ['status_change',    'Zmiana statusu zgłoszenia',
         '[{{org}}] Zmiana statusu zgłoszenia #{{number}}: {{new_status}}',
         '["{{requester_name}}","{{number}}","{{title}}","{{old_status}}","{{new_status}}","{{track_url}}","{{org}}","{{note}}"]'],
        ['new_msg_to_user',  'Nowa odpowiedź dla zgłaszającego',
         '[{{org}}] Nowa odpowiedź na zgłoszenie #{{number}}',
         '["{{requester_name}}","{{number}}","{{title}}","{{from_name}}","{{message_body}}","{{track_url}}","{{org}}"]'],
        ['new_msg_to_agent', 'Nowa odpowiedź od zgłaszającego (do agenta)',
         '[{{org}}] Odpowiedź zgłaszającego — #{{number}} {{title}}',
         '["{{agent_name}}","{{number}}","{{title}}","{{from_name}}","{{message_body}}","{{view_url}}","{{org}}"]'],
        ['assigned',         'Przypisanie zgłoszenia do agenta',
         '[{{org}}] Przypisano Ci zgłoszenie #{{number}}',
         '["{{agent_name}}","{{number}}","{{title}}","{{view_url}}","{{org}}"]'],
        ['escalation_op',    'Podbicie zgłoszenia — powiadomienie operatora',
         '[PILNE] Podbicie zgłoszenia #{{number}} — brak reakcji',
         '["{{number}}","{{title}}","{{escalation_number}}","{{days_waiting}}","{{reason}}","{{view_url}}","{{org}}"]'],
        ['escalation_req',   'Podbicie zgłoszenia — potwierdzenie dla zgłaszającego',
         '[{{org}}] Zgłoszenie #{{number}} podbite — przekazano do 3. linii wsparcia',
         '["{{requester_name}}","{{number}}","{{title}}","{{escalation_number}}","{{track_url}}","{{org}}"]'],
        ['shared_ticket',    'Udostępnienie zgłoszenia innej osobie',
         '[{{number}}] Udostępniono Ci zgłoszenie — {{title}}',
         '["{{to_name}}","{{by_name}}","{{number}}","{{title}}","{{note}}","{{track_url}}","{{org}}"]'],
    ];
    try {
        $ins_tpl = $pdo->prepare(
            "INSERT OR IGNORE INTO helpdesk_email_templates (key_, label, subject, placeholders) VALUES (?,?,?,?)"
        );
        foreach ($tpl_seed as [$k, $l, $s, $ph]) $ins_tpl->execute([$k, $l, $s, $ph]);
    } catch (\Throwable $e) {}
}

// ── Szablony e-mail — obsługa niestandardowych szablonów ──────────────────────

/**
 * Zwraca niestandardowy szablon e-mail (jeśli aktywny), lub null.
 * $data = ['requester_name' => ..., 'number' => ..., ...] — podstawiane do {{klucz}}.
 */
function hd_email_tpl(string $key, array $data = []): ?array {
    try {
        $row = db_one(
            "SELECT subject, body_html FROM helpdesk_email_templates WHERE key_=? AND is_active=1 AND body_html != ''",
            [$key]
        );
        if (!$row) return null;
        $subject  = hd_tpl_replace($row['subject'],  $data);
        $body     = hd_tpl_replace($row['body_html'], $data);
        return ['subject' => $subject, 'body' => $body];
    } catch (\Throwable $e) { return null; }
}

/** Zamienia {{klucz}} na wartości z $data (HTML-escaped). */
function hd_tpl_replace(string $tpl, array $data): string {
    foreach ($data as $k => $v) {
        $tpl = str_replace('{{' . $k . '}}', htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'), $tpl);
    }
    return $tpl;
}

// ── Baner powitalny (tylko przy 1. logowaniu) ──────────────────────────────────
// Znacznik trwały w bazie (nie localStorage) — pokazuje się raz na konto,
// niezależnie od urządzenia/przeglądarki, aż użytkownik go odrzuci.

function helpdesk_intro_mark_seen(int $user_id): void {
    try {
        db()->prepare("UPDATE users SET helpdesk_intro_seen_at = datetime('now') WHERE id = ?")
            ->execute([$user_id]);
    } catch (\Throwable $e) {}
}

function helpdesk_intro_banner_html(): string {
    $u = current_user();
    if (!$u || !module_enabled('helpdesk_enabled')) return '';

    // Odśwież ze świeżej bazy — sesja może być nieaktualna.
    try {
        $row = db_one("SELECT helpdesk_intro_seen_at FROM users WHERE id=?", [(int)$u['id']]);
    } catch (\Throwable $e) { return ''; }
    if (!$row || $row['helpdesk_intro_seen_at'] !== null) return '';

    $h    = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $app  = defined('APP_URL') ? APP_URL : '';
    $ret  = $h($_SERVER['REQUEST_URI'] ?? '/index.php');

    return '<div style="position:sticky;top:0;z-index:10700;display:flex;align-items:center;gap:.6rem;'
        . 'flex-wrap:wrap;background:#eff6ff;color:#1e3a8a;padding:.5rem .9rem;font-size:.85rem;'
        . 'border-bottom:1px solid #bfdbfe" role="alert">'
        . '<i class="bi bi-life-preserver" style="font-size:1rem;flex-shrink:0"></i>'
        . '<span><strong>Masz problem techniczny?</strong> Zgłoś go przez '
        . '<a href="' . $h($app) . '/helpdesk/new.php" style="color:#1d4ed8;font-weight:600">Helpdesk</a> '
        . '— znajdziesz go też w menu górnym.</span>'
        . '<form method="post" action="' . $h($app) . '/helpdesk/intro_dismiss.php" style="margin-left:auto">'
        . '<input type="hidden" name="_csrf" value="' . $h(csrf_token()) . '">'
        . '<input type="hidden" name="return" value="' . $ret . '">'
        . '<button type="submit" class="btn-close" aria-label="Zamknij"></button>'
        . '</form>'
        . '</div>';
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

// ── Publiczny mikropanel (dostęp po tokenie, bez logowania) ────────────────────

/** Zwraca (tworząc w razie potrzeby) token publicznego podglądu zgłoszenia. */
function hd_ticket_token(array $ticket): string {
    if (!empty($ticket['access_token'])) return (string)$ticket['access_token'];
    $tok = bin2hex(random_bytes(16));
    try { db_update('helpdesk_tickets', ['access_token' => $tok], (int)$ticket['id']); } catch (\Throwable $e) {}
    return $tok;
}

/** Pełny URL do mikropanelu podglądu/odpowiedzi (link dołączany do maili do zgłaszającego). */
function hd_track_url(array $ticket): string {
    return APP_URL . '/helpdesk/track.php?t=' . hd_ticket_token($ticket);
}

/**
 * Udostępnia mikropanel innej osobie niż zgłaszający (np. firmie zewnętrznej).
 * Wysyła link do podglądu/odpowiedzi e-mailem. Zwraca true, gdy mail dodano do kolejki.
 */
function hd_share_ticket(array $ticket, string $email, string $name = '', string $note = '', string $by = ''): bool {
    $email = trim($email);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return false;
    $org   = defined('ORG_NAME') ? ORG_NAME : 'Helpdesk';
    $url   = hd_track_url($ticket);
    $num   = h($ticket['number']);
    $title = h($ticket['title']);
    $to_h  = h($name ?: $email);
    $by_h  = $by !== '' ? h($by) : $org;
    $_sh_data = ['to_name' => $name ?: $email, 'by_name' => $by ?: $org,
                 'number' => $num, 'title' => $ticket['title'],
                 'note' => $note, 'track_url' => $url, 'org' => $org];
    $_sh_tpl  = hd_email_tpl('shared_ticket', $_sh_data);
    $note_block = trim($note) !== ''
        ? '<div style="background:#f8f9fa;border-left:3px solid #6c757d;padding:10px 14px;margin:14px 0;border-radius:0 4px 4px 0;font-size:.9em">'
          . nl2br(h($note)) . '</div>'
        : '';
    try {
        require_once dirname(__DIR__) . '/includes/mail_queue.php';
        if ($_sh_tpl) {
            mail_queue_add($email, $name, $_sh_tpl['subject'], $_sh_tpl['body']);
            return true;
        }
        mail_queue_add(
            $email, $name,
            "[{$ticket['number']}] Udostępniono Ci zgłoszenie — {$ticket['title']}",
            <<<HTML
<html><body style="font-family:sans-serif;max-width:600px;margin:0 auto;padding:20px;color:#212529">
<div style="background:#0f172a;padding:20px 24px;border-radius:8px 8px 0 0">
  <h2 style="color:#fff;margin:0;font-size:1.1rem">🔗 Udostępnione zgłoszenie — {$org} Helpdesk</h2>
</div>
<div style="border:1px solid #dee2e6;border-top:none;padding:24px;border-radius:0 0 8px 8px">
  <p>Witaj, <strong>{$to_h}</strong>!</p>
  <p><strong>{$by_h}</strong> udostępnił(a) Ci zgłoszenie <strong>{$num}</strong> — <em>{$title}</em>.</p>
  {$note_block}
  <p>Poniższy link umożliwia podgląd zgłoszenia oraz dodawanie odpowiedzi <strong>bez logowania</strong>:</p>
  <div style="margin:20px 0;text-align:center">
    <a href="{$url}" style="background:#0f172a;color:#fff;padding:11px 26px;border-radius:6px;text-decoration:none;display:inline-block;font-weight:600">
      Otwórz zgłoszenie →
    </a>
  </div>
  <p style="color:#6c757d;font-size:.82em;border-top:1px solid #dee2e6;padding-top:12px;margin-top:20px">
    Link jest osobisty — nie przekazuj go dalej. {$org} · Helpdesk IT
  </p>
</div></body></html>
HTML
        );
        return true;
    } catch (\Throwable $e) { return false; }
}

// ── Szablony odpowiedzi (canned responses) ────────────────────────────────────

/**
 * Gotowe szablony odpowiedzi operatora, z podstawionymi danymi zgłoszenia.
 * Zwraca listę: ['key' => ['label' => ..., 'body' => ...], ...].
 * Używane w formularzu odpowiedzi (helpdesk/view.php) — klik wstawia treść do pola.
 */
// ── Makra (gotowe odpowiedzi z bazy) ─────────────────────────────────────────

function hd_macros_active(): array {
    try {
        return db_all("SELECT id, title, body FROM helpdesk_macros WHERE is_active=1 ORDER BY sort_order, title", []);
    } catch (\Throwable $e) { return []; }
}

// ── Odczyty / licznik nieodczytanych ─────────────────────────────────────────

/** Oznacz zgłoszenie jako odczytane przez danego usera. */
function hd_mark_read(int $ticket_id, int $user_id): void {
    try {
        db()->prepare(
            "INSERT INTO helpdesk_reads (ticket_id, user_id, read_at) VALUES (?,?,?)
             ON CONFLICT(ticket_id, user_id) DO UPDATE SET read_at=excluded.read_at"
        )->execute([$ticket_id, $user_id, date('Y-m-d H:i:s')]);
    } catch (\Throwable $e) {}
}

/**
 * Zwraca set ticket_id które mają nieprzeczytane wiadomości dla danego usera
 * (wiadomości dodane po ostatnim read_at lub nigdy nie otwarte, status != zamknięte).
 */
function hd_unread_ids(int $user_id): array {
    try {
        $rows = db_all(
            "SELECT DISTINCT m.ticket_id FROM helpdesk_messages m
             JOIN helpdesk_tickets t ON t.id = m.ticket_id
             LEFT JOIN helpdesk_reads r ON r.ticket_id = m.ticket_id AND r.user_id = ?
             WHERE m.is_internal = 0
               AND (r.read_at IS NULL OR m.created_at > r.read_at)
               AND t.status NOT IN ('zamknięte')
               AND m.user_id != ?",
            [$user_id, $user_id]
        );
        return array_column($rows, 'ticket_id');
    } catch (\Throwable $e) { return []; }
}

// ── Łączenie (scalanie) zgłoszeń ──────────────────────────────────────────────

/**
 * Łączy zgłoszenie źródłowe (duplikat) ze zgłoszeniem docelowym (głównym):
 * przenosi wiadomości i załączniki do docelowego, zamyka źródłowe i ustawia
 * merged_into. Operacja w transakcji. Zwraca ['ok'=>bool, 'error'?, 'target'?, 'source'?].
 */
function hd_merge(int $sourceId, int $targetId, array $operator): array {
    if ($sourceId === $targetId) return ['ok' => false, 'error' => 'Nie można połączyć zgłoszenia z samym sobą.'];
    $pdo = db();
    $src = db_one("SELECT * FROM helpdesk_tickets WHERE id=?", [$sourceId]);
    $dst = db_one("SELECT * FROM helpdesk_tickets WHERE id=?", [$targetId]);
    if (!$src)                       return ['ok' => false, 'error' => 'Nie znaleziono zgłoszenia źródłowego.'];
    if (!$dst)                       return ['ok' => false, 'error' => 'Nie znaleziono zgłoszenia docelowego.'];
    if (!empty($src['merged_into'])) return ['ok' => false, 'error' => 'To zgłoszenie zostało już połączone z innym.'];
    if (!empty($dst['merged_into'])) return ['ok' => false, 'error' => 'Zgłoszenie docelowe jest już połączone — wskaż zgłoszenie główne.'];

    $op_name = $operator['name'] ?? '';
    $op_id   = (int)($operator['id'] ?? 0) ?: null;
    $now     = date('Y-m-d H:i:s');
    try {
        $pdo->beginTransaction();
        // Przenieś wiadomości i załączniki do docelowego
        $pdo->prepare("UPDATE helpdesk_messages    SET ticket_id=? WHERE ticket_id=?")->execute([$targetId, $sourceId]);
        $pdo->prepare("UPDATE helpdesk_attachments SET ticket_id=? WHERE ticket_id=?")->execute([$targetId, $sourceId]);
        // Notatka w docelowym
        $pdo->prepare("INSERT INTO helpdesk_messages (ticket_id,user_id,user_name,body,is_internal) VALUES (?,?,?,?,1)")
            ->execute([$targetId, $op_id, $op_name,
                "Dołączono zgłoszenie {$src['number']} — {$src['title']} (zgłaszający: {$src['requester_name']})."]);
        // Zamknij źródłowe i oznacz powiązanie
        $pdo->prepare("UPDATE helpdesk_tickets SET status='zamknięte', merged_into=?, closed_at=?, updated_at=? WHERE id=?")
            ->execute([$targetId, $now, $now, $sourceId]);
        $pdo->prepare("INSERT INTO helpdesk_messages (ticket_id,user_id,user_name,body,is_internal) VALUES (?,?,?,?,1)")
            ->execute([$sourceId, $op_id, $op_name,
                "Połączono ze zgłoszeniem {$dst['number']}. Dalsza korespondencja w zgłoszeniu głównym."]);
        $pdo->prepare("UPDATE helpdesk_tickets SET updated_at=? WHERE id=?")->execute([$now, $targetId]);
        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'error' => 'Błąd podczas łączenia: ' . $e->getMessage()];
    }
    return ['ok' => true, 'target' => $dst, 'source' => $src];
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

// ── Podbicie zgłoszenia — brak reakcji ────────────────────────────────────────

/**
 * Generuje unikalny numer podbicia w formacie:
 *   P-<numer zgłoszenia>-<ddmmrr>-<2 cyfry><3 litery>
 * np. P-HD00042-040726-27XQZ — sufiks losowy zabezpiecza przed kolizją/odgadnięciem.
 */
function hd_escalation_number(string $ticket_number): string {
    $letters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $date    = date('dmy');
    $suffix  = '';
    for ($i = 0; $i < 20; $i++) {
        $suffix = str_pad((string)random_int(0, 99), 2, '0', STR_PAD_LEFT);
        for ($j = 0; $j < 3; $j++) $suffix .= $letters[random_int(0, 25)];
        $number = 'P-' . $ticket_number . '-' . $date . '-' . $suffix;
        if (!db_one("SELECT id FROM helpdesk_escalations WHERE number=?", [$number])) return $number;
    }
    // Praktycznie nieosiągalne — dodatkowy losowy człon na wypadek serii kolizji.
    return 'P-' . $ticket_number . '-' . $date . '-' . $suffix . bin2hex(random_bytes(2));
}

/** Lista podbić danego zgłoszenia, najnowsze pierwsze. */
function hd_escalations_for_ticket(int $ticket_id): array {
    try {
        return db_all("SELECT * FROM helpdesk_escalations WHERE ticket_id=? ORDER BY id DESC", [$ticket_id]);
    } catch (\Throwable $e) { return []; }
}

/**
 * Rejestruje formalne podbicie zgłoszenia z powodu braku reakcji: nadaje numer,
 * dopisuje notatkę wewnętrzną do wątku i powiadamia operatorów priorytetowo.
 * $who = ['id'=>?int, 'name'=>string] — osoba zgłaszająca podbicie.
 * Zwraca ['id'=>int, 'number'=>string].
 */
function hd_escalate(array $ticket, string $reason, array $who): array {
    $number = hd_escalation_number($ticket['number']);
    $days   = max(0, (int)floor((time() - strtotime($ticket['updated_at'])) / 86400));

    $esc_id = db_insert('helpdesk_escalations', [
        'ticket_id'       => (int)$ticket['id'],
        'number'          => $number,
        'reason'          => $reason,
        'requester_name'  => $ticket['requester_name'] ?? '',
        'requester_email' => $ticket['requester_email'] ?? null,
        'requester_phone' => $ticket['requester_phone'] ?? null,
        'days_waiting'    => $days,
        'created_by'      => $who['id'] ?? null,
    ]);

    $note = "Zgłoszenie podbite z powodu braku reakcji (nr podbicia: {$number}).";
    if (trim($reason) !== '') $note .= "\n\nUzasadnienie zgłaszającego:\n" . $reason;
    db_insert('helpdesk_messages', [
        'ticket_id'   => (int)$ticket['id'],
        'user_id'     => $who['id'] ?? null,
        'user_name'   => $who['name'] ?? ($ticket['requester_name'] ?: 'Zgłaszający'),
        'body'        => $note,
        'is_internal' => 1,
    ]);
    db_update('helpdesk_tickets', ['updated_at' => date('Y-m-d H:i:s')], (int)$ticket['id']);

    hd_notify_escalation($ticket, $number, $reason, $days);
    hd_notify_escalation_requester($ticket, $number);

    return ['id' => $esc_id, 'number' => $number];
}

/** Informuje zgłaszającego, że z powodu braku reakcji sprawa trafiła do 3. linii wsparcia. */
function hd_notify_escalation_requester(array $ticket, string $number): void {
    $email = $ticket['requester_email'] ?? '';
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) return;

    $org   = defined('ORG_NAME') ? ORG_NAME : 'Helpdesk';
    $url   = hd_track_url($ticket);
    $num   = h($ticket['number']);
    $title = h($ticket['title']);
    $name  = h($ticket['requester_name']);
    $_er_data = ['requester_name' => $ticket['requester_name'], 'number' => $num,
                 'title' => $ticket['title'], 'escalation_number' => $number,
                 'track_url' => $url, 'org' => $org];
    $_er_tpl  = hd_email_tpl('escalation_req', $_er_data);
    $html  = $_er_tpl ? $_er_tpl['body'] : <<<HTML
<html><body style="font-family:sans-serif;max-width:600px;margin:0 auto;padding:20px;color:#212529">
<div style="background:#ea580c;padding:20px 24px;border-radius:8px 8px 0 0">
  <h2 style="color:#fff;margin:0;font-size:1.1rem">🚨 Zgłoszenie podbite — {$org} Helpdesk</h2>
</div>
<div style="border:1px solid #dee2e6;border-top:none;padding:24px;border-radius:0 0 8px 8px">
  <p>Witaj, <strong>{$name}</strong>!</p>
  <p>W związku z brakiem reakcji na zgłoszenie <strong>{$num}</strong> — <em>{$title}</em>, zostało ono podbite
     (nr podbicia: <strong>{$number}</strong>) i przechodzi na <strong>3. linię wsparcia</strong>.</p>
  <p>Sprawą priorytetowo zajmie się teraz nasz zespół — o dalszych krokach poinformujemy w tym wątku.</p>
  <div style="margin:20px 0;text-align:center">
    <a href="{$url}" style="background:#ea580c;color:#fff;padding:11px 26px;border-radius:6px;text-decoration:none;display:inline-block;font-weight:600">
      Otwórz zgłoszenie →
    </a>
  </div>
  <p style="color:#6c757d;font-size:.82em;border-top:1px solid #dee2e6;padding-top:12px;margin-top:20px">
    {$org} · Helpdesk IT
  </p>
</div></body></html>
HTML;
    try {
        require_once dirname(__DIR__) . '/includes/mail_queue.php';
        $_er_subj = $_er_tpl ? $_er_tpl['subject'] : "[{$ticket['number']}] Zgłoszenie podbite — przekazano do 3. linii wsparcia";
        mail_queue_add($email, $ticket['requester_name'] ?? '', $_er_subj, $html);
    } catch (\Throwable $e) {}
}

// ── Firma zewnętrzna nie odpowiada — formalna sprawa w EZD ────────────────────

/**
 * Rejestruje "brak reakcji firmy zewnętrznej" na zgłoszenie przekazane do
 * obsługi zewnętrznej i od razu zakłada formalną sprawę w module EZD Wirtualne biurko
 * (teczka „IT"), żeby sprawę dalej prowadzić do załatwienia — z dekretacją
 * „do załatwienia" do operatora zgłoszenia. Dopisuje notatkę wewnętrzną w wątku
 * zgłoszenia z odnośnikiem do sprawy.
 * $who = ['id'=>?int, 'name'=>string] — operator zgłaszający brak reakcji.
 * @return array{sprawa_id:int, znak_sprawy:string, url:string}|null null, gdy moduł EZD wyłączony.
 */
function hd_vendor_no_response(array $ticket, string $description, array $who): ?array {
    if (!module_enabled('ezd_enabled')) return null;
    require_once dirname(__DIR__) . '/includes/ezd.php';

    $vendor = trim((string)($ticket['ext_vendor'] ?? '')) ?: 'nieznana firma';
    $who_id = (int)($who['id'] ?? 0) ?: null;
    $rok    = (int)date('Y');

    $teczka_id = _ezd_it_teczka_id($rok, $who_id ?: 0);

    $desc = "Zgłoszenie helpdesku: {$ticket['number']} — {$ticket['title']}\n"
          . "Firma zewnętrzna: {$vendor}\n"
          . (!empty($ticket['ext_ref'])       ? "Nr zgłoszenia u firmy: {$ticket['ext_ref']}\n" : '')
          . (!empty($ticket['ext_handed_at']) ? "Przekazano dnia: " . date('d.m.Y', strtotime($ticket['ext_handed_at'])) . "\n" : '')
          . "\nOpis sytuacji:\n" . trim($description);

    $priority = match ($ticket['priority'] ?? 'normalny') {
        'krytyczny' => 'urgent',
        'wysoki'    => 'high',
        'niski'     => 'low',
        default     => 'normal',
    };

    $wykonawca_id = (int)($ticket['assigned_to'] ?? 0) ?: $who_id;
    $deadline     = date('Y-m-d', strtotime('+7 days'));

    $sprawa_id = ezd_sprawa_create([
        'teczka_id'   => $teczka_id,
        'title'       => 'Brak reakcji firmy zewnętrznej — ' . $vendor . ' (zgł. ' . $ticket['number'] . ')',
        'description' => $desc,
        'status'      => 'open',
        'priority'    => $priority,
        'owner_id'    => $wykonawca_id,
        'deadline'    => $deadline,
        'ref_type'    => 'helpdesk_ticket',
        'ref_id'      => (int)$ticket['id'],
    ], $who_id ?: 0);

    if ($wykonawca_id) {
        try {
            $dekr_id = ezd_dekretacja_create([
                'sprawa_id'    => $sprawa_id,
                'pismo_id'     => null,
                'umowa_id'     => null,
                'unit_id'      => null,
                'wykonawca_id' => $wykonawca_id,
                'dyspozycja'   => 'do_zalat',
                'tresc'        => "Firma zewnętrzna ({$vendor}) nie reaguje na zgłoszenie {$ticket['number']} — do załatwienia.",
                'deadline'     => $deadline,
            ], $who_id ?: 0);
            // org.php resolves substitute/adres e-mail; działa niezależnie od przełącznika
            // modułu jednostek (org_enabled dotyczy tylko UI struktury organizacyjnej).
            require_once dirname(__DIR__) . '/includes/org.php';
            require_once dirname(__DIR__) . '/includes/mail_queue.php';
            require_once dirname(__DIR__) . '/includes/notification_service.php';
            NotificationService::onDekretacja($dekr_id);
        } catch (\Throwable $e) {}
    }

    $sprawa = ezd_sprawa_get($sprawa_id);
    $url    = APP_URL . '/ezd/sprawy/view.php?id=' . $sprawa_id;

    $note = "Brak reakcji firmy zewnętrznej ({$vendor}) — założono formalną sprawę w EZD: {$sprawa['znak_sprawy']}.";
    if (trim($description) !== '') $note .= "\n\nOpis sytuacji:\n" . trim($description);
    db_insert('helpdesk_messages', [
        'ticket_id'   => (int)$ticket['id'],
        'user_id'     => $who_id,
        'user_name'   => $who['name'] ?? '',
        'body'        => $note,
        'is_internal' => 1,
    ]);
    db_update('helpdesk_tickets', ['updated_at' => date('Y-m-d H:i:s')], (int)$ticket['id']);

    return ['sprawa_id' => $sprawa_id, 'znak_sprawy' => $sprawa['znak_sprawy'], 'url' => $url];
}

/** Sprawy EZD utworzone dla tego zgłoszenia (brak reakcji firmy zewnętrznej). Pusto, gdy moduł EZD wyłączony. */
function hd_vendor_cases(int $ticket_id): array {
    if (!module_enabled('ezd_enabled')) return [];
    try {
        require_once dirname(__DIR__) . '/includes/ezd.php';
        return ezd_sprawy_by_ref('helpdesk_ticket', $ticket_id);
    } catch (\Throwable $e) { return []; }
}

// ── Upadłość firmy zewnętrznej — skierowanie do rozwiązania zastępczego ───────

/**
 * Gdy firmy zewnętrznej nie da się już użyć do rozwiązania zgłoszenia (upadłość,
 * likwidacja, zerwanie współpracy), operator kieruje sprawę do rozwiązania
 * zastępczego: zgłoszenie przechodzi na status 'zastepcze' (dalej prowadzi je
 * zespół wewnętrzny), a w EZD Wirtualne biurko zakłada się pilną sprawę (priorytet
 * 'urgent', termin 3 dni) z dekretacją „do załatwienia". Wyłącznie dla operatorów
 * helpdesku — sprawdź hd_is_operator() przed wywołaniem.
 * $who = ['id'=>?int, 'name'=>string] — operator dokonujący skierowania.
 * @return array{sprawa_id:int, znak_sprawy:string, url:string} znak_sprawy='' gdy EZD wyłączone.
 */
function hd_vendor_substitute_resolution(array $ticket, string $description, array $who): array {
    $vendor = trim((string)($ticket['ext_vendor'] ?? '')) ?: 'nieznana firma';
    $who_id = (int)($who['id'] ?? 0) ?: null;

    $case = ['sprawa_id' => 0, 'znak_sprawy' => '', 'url' => ''];
    if (module_enabled('ezd_enabled')) {
        require_once dirname(__DIR__) . '/includes/ezd.php';
        $teczka_id = _ezd_it_teczka_id((int)date('Y'), $who_id ?: 0);

        $desc = "Zgłoszenie helpdesku: {$ticket['number']} — {$ticket['title']}\n"
              . "Firma zewnętrzna: {$vendor}\n"
              . (!empty($ticket['ext_ref']) ? "Nr zgłoszenia u firmy: {$ticket['ext_ref']}\n" : '')
              . "\nPowód skierowania do rozwiązania zastępczego (upadłość/niewypłacalność podmiotu):\n" . trim($description);

        $wykonawca_id = (int)($ticket['assigned_to'] ?? 0) ?: $who_id;
        $deadline = date('Y-m-d', strtotime('+3 days'));

        $sprawa_id = ezd_sprawa_create([
            'teczka_id'   => $teczka_id,
            'title'       => 'Rozwiązanie zastępcze — upadłość firmy ' . $vendor . ' (zgł. ' . $ticket['number'] . ')',
            'description' => $desc,
            'status'      => 'open',
            'priority'    => 'urgent',
            'owner_id'    => $wykonawca_id,
            'deadline'    => $deadline,
            'ref_type'    => 'helpdesk_ticket',
            'ref_id'      => (int)$ticket['id'],
        ], $who_id ?: 0);

        if ($wykonawca_id) {
            try {
                $dekr_id = ezd_dekretacja_create([
                    'sprawa_id' => $sprawa_id, 'pismo_id' => null, 'umowa_id' => null, 'unit_id' => null,
                    'wykonawca_id' => $wykonawca_id, 'dyspozycja' => 'do_zalat',
                    'tresc' => "Firma zewnętrzna ({$vendor}) nie może kontynuować (upadłość) — zgłoszenie {$ticket['number']} wymaga rozwiązania zastępczego.",
                    'deadline' => $deadline,
                ], $who_id ?: 0);
                require_once dirname(__DIR__) . '/includes/org.php';
                require_once dirname(__DIR__) . '/includes/mail_queue.php';
                require_once dirname(__DIR__) . '/includes/notification_service.php';
                NotificationService::onDekretacja($dekr_id);
            } catch (\Throwable $e) {}
        }

        $sprawa_row = ezd_sprawa_get($sprawa_id);
        $case = ['sprawa_id' => $sprawa_id, 'znak_sprawy' => $sprawa_row['znak_sprawy'], 'url' => APP_URL . '/ezd/sprawy/view.php?id=' . $sprawa_id];
    }

    $old_status = $ticket['status'];
    db_update('helpdesk_tickets', ['status' => 'zastepcze', 'updated_at' => date('Y-m-d H:i:s')], (int)$ticket['id']);

    $note = "Firma zewnętrzna ({$vendor}) nie może już kontynuować obsługi zgłoszenia (upadłość/niewypłacalność) — skierowano do rozwiązania zastępczego.";
    if ($case['znak_sprawy'] !== '') $note .= " Założono pilną sprawę w EZD: {$case['znak_sprawy']}.";
    if (trim($description) !== '') $note .= "\n\nOpis sytuacji:\n" . trim($description);
    db_insert('helpdesk_messages', [
        'ticket_id'   => (int)$ticket['id'],
        'user_id'     => $who_id,
        'user_name'   => $who['name'] ?? '',
        'body'        => $note,
        'is_internal' => 1,
    ]);

    hd_notify_status_change(array_merge($ticket, ['status' => 'zastepcze']), $old_status, 'zastepcze', trim($description));

    return $case;
}

/** Powiadamia priorytetowo operatora (lub wszystkich, gdy brak przypisania) o podbiciu. */
function hd_notify_escalation(array $ticket, string $number, string $reason, int $days): void {
    $org   = defined('ORG_NAME') ? ORG_NAME : 'Helpdesk';
    $url   = APP_URL . '/helpdesk/view.php?id=' . $ticket['id'];
    $num   = h($ticket['number']);
    $title = h($ticket['title']);
    $_eo_data = ['number' => $num, 'title' => $ticket['title'], 'escalation_number' => $number,
                 'days_waiting' => $days, 'reason' => $reason, 'view_url' => $url, 'org' => $org];
    $_eo_tpl  = hd_email_tpl('escalation_op', $_eo_data);
    $reason_block = trim($reason) !== ''
        ? '<div style="background:#fff7ed;border-left:3px solid #ea580c;padding:10px 14px;margin:12px 0;border-radius:0 4px 4px 0;font-size:.9em">'
          . nl2br(h($reason)) . '</div>'
        : '';
    $html = $_eo_tpl ? $_eo_tpl['body'] : <<<HTML
<html><body style="font-family:sans-serif;max-width:600px;margin:0 auto;padding:20px;color:#212529">
<div style="background:#ea580c;padding:20px 24px;border-radius:8px 8px 0 0">
  <h2 style="color:#fff;margin:0;font-size:1.1rem">🚨 Podbicie zgłoszenia — brak reakcji</h2>
</div>
<div style="border:1px solid #dee2e6;border-top:none;padding:24px;border-radius:0 0 8px 8px">
  <p>Zgłaszający zgłosił <strong>brak reakcji</strong> na zgłoszenie <strong>{$num}</strong> — <em>{$title}</em>.</p>
  <p>Nr podbicia: <strong>{$number}</strong> · dni bez aktualizacji: <strong>{$days}</strong></p>
  {$reason_block}
  <div style="margin:20px 0;text-align:center">
    <a href="{$url}" style="background:#ea580c;color:#fff;padding:11px 26px;border-radius:6px;text-decoration:none;display:inline-block;font-weight:600">
      Otwórz zgłoszenie →
    </a>
  </div>
  <p style="color:#6c757d;font-size:.82em;border-top:1px solid #dee2e6;padding-top:12px;margin-top:20px">
    {$org} · Helpdesk IT
  </p>
</div></body></html>
HTML;
    $subject = $_eo_tpl ? $_eo_tpl['subject'] : "[PILNE] Podbicie zgłoszenia {$ticket['number']} — brak reakcji";
    try {
        require_once dirname(__DIR__) . '/includes/mail_queue.php';
        if (!empty($ticket['assigned_to'])) {
            $op = db_one("SELECT email, name FROM users WHERE id=?", [(int)$ticket['assigned_to']]);
            if ($op && !empty($op['email'])) mail_queue_add($op['email'], $op['name'] ?? '', $subject, $html);
        } else {
            $ops = db_all(
                "SELECT email, name FROM users WHERE (helpdesk_operator=1 OR role='admin') AND is_active=1 AND email IS NOT NULL AND email != ''", []
            );
            foreach ($ops as $op) mail_queue_add($op['email'], $op['name'] ?? '', $subject, $html);
        }
    } catch (\Throwable $e) {}
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

// ── SLA — cele czasowe i śledzenie terminów ───────────────────────────────────

/** Formatuje liczbę sekund jako zwięzły czas, np. "2d 3h", "4h 15m", "12m". */
function hd_fmt_secs(int $s): string {
    $s = abs($s);
    $d = intdiv($s, 86400); $s %= 86400;
    $h = intdiv($s, 3600);  $s %= 3600;
    $m = intdiv($s, 60);
    if ($d > 0) return $d . 'd ' . $h . 'h';
    if ($h > 0) return $h . 'h ' . $m . 'm';
    return $m . 'm';
}

/**
 * Oblicza stan SLA zgłoszenia dla obu celów: reakcji i rozwiązania.
 * Zwraca ['response'=>?part, 'resolution'=>?part], gdzie part to:
 *   ['deadline'=>ts, 'done_at'=>ts|null, 'state'=>'met|breached|paused|due_soon|pending', 'left'=>sek (ujemne=po terminie), 'target_mins'=>int]
 * Statusy „oczekuje" i „przekazane_zewn" wstrzymują zegar rozwiązania (stan paused).
 */
function hd_sla(array $ticket): array {
    $out = ['response' => null, 'resolution' => null];
    $pr  = HD_PRIORITIES[$ticket['priority'] ?? ''] ?? null;
    $created = $ticket['created_at'] ?? null;
    if (!$pr || !$created) return $out;

    $ct  = strtotime($created);
    $now = time();
    $status = (string)($ticket['status'] ?? '');
    $is_paused = in_array($status, ['oczekuje', 'przekazane_zewn', 'wymaga_prac'], true);
    $is_closed = in_array($status, ['rozwiązane', 'zamknięte'], true);

    $mk = function (int $deadline, ?int $done, bool $paused) use ($now): array {
        if ($done !== null) {
            return ['deadline' => $deadline, 'done_at' => $done, 'left' => $deadline - $done,
                    'state' => $done <= $deadline ? 'met' : 'breached'];
        }
        if ($paused) {
            return ['deadline' => $deadline, 'done_at' => null, 'left' => $deadline - $now, 'state' => 'paused'];
        }
        $left = $deadline - $now;
        if ($left < 0)        $state = 'breached';
        elseif ($left < 3600) $state = 'due_soon';   // < 1h do terminu
        else                  $state = 'pending';
        return ['deadline' => $deadline, 'done_at' => null, 'left' => $left, 'state' => $state];
    };

    // Reakcja — domknięta przez pierwszą odpowiedź operatora; nie wstrzymywana.
    if (!empty($pr['sla_response'])) {
        $dl   = $ct + (int)$pr['sla_response'] * 60;
        $done = !empty($ticket['first_response_at']) ? strtotime($ticket['first_response_at']) : null;
        $out['response'] = $mk($dl, $done, false) + ['target_mins' => (int)$pr['sla_response']];
    }
    // Rozwiązanie — domknięte przez resolved_at/closed_at; wstrzymywane w stanach oczekiwania.
    if (!empty($pr['sla_resolve'])) {
        $dl = $ct + (int)$pr['sla_resolve'] * 60;
        $done = null;
        if ($is_closed) {
            $done = !empty($ticket['resolved_at']) ? strtotime($ticket['resolved_at'])
                  : (!empty($ticket['closed_at']) ? strtotime($ticket['closed_at']) : $now);
        }
        $out['resolution'] = $mk($dl, $done, $is_paused && !$is_closed) + ['target_mins' => (int)$pr['sla_resolve']];
    }
    return $out;
}

/** Mapuje stan SLA na klasę Bootstrap + ikonę + krótki opis. */
function _hd_sla_meta(array $part): array {
    return match ($part['state']) {
        'met'      => ['success',   'bi-check-circle-fill', 'w terminie'],
        'breached' => ['danger',    'bi-exclamation-octagon-fill', ($part['done_at'] ? 'po terminie o ' : 'przekroczono o ') . hd_fmt_secs((int)$part['left'])],
        'due_soon' => ['warning',   'bi-alarm-fill', 'pozostało ' . hd_fmt_secs((int)$part['left'])],
        'paused'   => ['secondary', 'bi-pause-circle-fill', 'wstrzymane'],
        default    => ['info',      'bi-clock', 'pozostało ' . hd_fmt_secs((int)$part['left'])],
    };
}

/** Pełny badge SLA z etykietą celu (np. „Reakcja: w terminie"). */
function hd_sla_badge(array $part, string $label): string {
    [$cls, $icon, $txt] = _hd_sla_meta($part);
    return '<span class="badge bg-' . $cls . '-subtle border border-' . $cls . '-subtle text-' . $cls . '-emphasis">'
         . '<i class="bi ' . $icon . ' me-1"></i>' . h($label) . ': ' . h($txt) . '</span>';
}

/**
 * Zwięzły wskaźnik SLA do listy zgłoszeń — pokazuje najpilniejszy stan
 * (przekroczone > wkrótce > wstrzymane > w toku) lub „—" gdy SLA domknięte/N/D.
 */
function hd_sla_indicator(array $ticket): string {
    $sla = hd_sla($ticket);
    $parts = array_filter([$sla['response'] ?? null, $sla['resolution'] ?? null]);
    if (!$parts) return '<span class="text-muted">—</span>';

    // Jeśli oba domknięte (met/breached z done_at) — pokaż wynik rozwiązania.
    $open = array_filter($parts, fn($p) => $p['done_at'] === null && $p['state'] !== 'met');
    $rank = ['breached' => 0, 'due_soon' => 1, 'paused' => 2, 'pending' => 3, 'met' => 4];
    $pick = null;
    foreach (($open ?: $parts) as $p) {
        if ($pick === null || $rank[$p['state']] < $rank[$pick['state']]) $pick = $p;
    }
    if (!$pick) return '<span class="text-muted">—</span>';
    [$cls, $icon, $txt] = _hd_sla_meta($pick);
    return '<span class="badge bg-' . $cls . '-subtle border border-' . $cls . '-subtle text-' . $cls . '-emphasis text-nowrap" title="' . h($txt) . '">'
         . '<i class="bi ' . $icon . '"></i> ' . h($txt) . '</span>';
}

// ── Interfejs konsoli (split-view) ────────────────────────────────────────────

/** Wspólny arkusz stylów konsoli helpdesku (lista + panel + dymki wiadomości). */
/**
 * Zwraca badge weryfikacji emaila zgłaszającego.
 * Sprawdza czy requester_email:
 *   1. należy do domeny org (m365_domain) → badge „org"
 *   2. pasuje do email zalogowanego użytkownika z tabeli users → badge „verified"
 *   3. w przeciwnym razie → badge „unknown"
 */
function hd_email_verify_badge(string $req_email): string {
    if (!$req_email) return '';
    try {
        $req_email  = strtolower(trim($req_email));
        $req_domain = strtolower(substr($req_email, (int)strrpos($req_email, '@') + 1));

        // Domena org — z ustawień (bezpieczne)
        $org_domain = '';
        try {
            if (function_exists('m365_setting')) {
                $org_domain = strtolower(trim(m365_setting('m365_domain') ?: ''));
            } else {
                $row = db_one("SELECT value FROM settings WHERE key='m365_domain' LIMIT 1", []);
                $org_domain = strtolower(trim($row['value'] ?? ''));
            }
        } catch (\Throwable $e) {}

        // Czy email należy do użytkownika w systemie
        try {
            $db_user = db_one("SELECT id, name FROM users WHERE LOWER(COALESCE(email,''))=? LIMIT 1", [$req_email]);
        } catch (\Throwable $e) { $db_user = null; }

        if ($db_user) {
            return '<span class="hd-email-badge verified" title="Zweryfikowany — ' . h($db_user['name']) . '">'
                 . '<i class="bi bi-patch-check-fill" aria-hidden="true"></i> Zweryfikowany'
                 . '</span>';
        }
        if ($org_domain && $req_domain === $org_domain) {
            return '<span class="hd-email-badge org" title="Adres organizacyjny: ' . h($req_email) . '">'
                 . '<i class="bi bi-building-check" aria-hidden="true"></i> Adres org.'
                 . '</span>';
        }
        return '<span class="hd-email-badge unknown" title="E-mail: ' . h($req_email) . '">'
             . '<i class="bi bi-question-circle" aria-hidden="true"></i> Nieznany'
             . '</span>';
    } catch (\Throwable $e) {
        return '';
    }
}

/**
 * Sanitizuje HTML z edytora WYSIWYG — usuwa niebezpieczne tagi/atrybuty, zachowuje formatowanie.
 */
function hd_sanitize_body(string $html): string {
    if (trim($html) === '' || trim(strip_tags($html)) === '') return '';
    $allowed = [
        'p','br','strong','b','em','i','u','s','ul','ol','li',
        'blockquote','a','span','h1','h2','h3','pre','code',
    ];
    // strip_tags zachowuje treść, usuwa tylko niedozwolone tagi
    $clean = strip_tags($html, $allowed);
    // Usuń onclick/onerror/href=javascript: z <a>
    $clean = preg_replace('/\s+on\w+="[^"]*"/i', '', $clean);
    $clean = preg_replace('/\s+on\w+=\'[^\']*\'/i', '', $clean);
    $clean = preg_replace('/<a([^>]*)href\s*=\s*["\']?\s*javascript:[^"\'>\s]*/i', '<a$1href="#"', $clean);
    return trim($clean);
}

function hd_ui_css(): string {
    return <<<CSS
<style>
:root{
  --hd-accent:#4f46e5;--hd-accent-h:#4338ca;--hd-accent-l:#eef2ff;--hd-accent-m:#e0e7ff;
  --hd-bg:#f6f7fb;--hd-panel:#fff;--hd-bd:#e5e7eb;--hd-bd-l:#f3f4f6;
  --hd-tx:#111827;--hd-tx2:#6b7280;--hd-tx3:#9ca3af;
  --hd-success:#059669;--hd-warn:#d97706;--hd-err:#dc2626;
  --hd-shadow:0 1px 3px rgba(0,0,0,.07),0 1px 2px rgba(0,0,0,.04);
  --hd-shadow-md:0 4px 16px rgba(0,0,0,.08),0 1px 4px rgba(0,0,0,.05);
}
/* ── Skip / focus ─────────────────────────────────────────── */
.hd-skip{position:absolute;left:1rem;top:-3rem;background:var(--hd-accent);color:#fff;padding:.5rem 1rem;border-radius:0 0 6px 6px;z-index:1080;text-decoration:none;font-weight:600;transition:top .12s}
.hd-skip:focus{top:0}
.hd-console :focus-visible{outline:3px solid var(--hd-accent);outline-offset:2px;border-radius:4px}
/* ── Console layout (shared by index.php) ─────────────────── */
.hd-console{display:flex;gap:0;align-items:flex-start}
.hd-list-col{flex:0 0 340px;max-width:340px;border-right:1px solid var(--hd-bd)}
.hd-pane-col{flex:1 1 auto;min-width:0;padding:0 1.25rem}
@media(max-width:900px){
  .hd-list-col{flex-basis:100%;max-width:100%;border-right:none}
  .hd-console.hd-show-pane .hd-list-col{display:none}
  .hd-console:not(.hd-show-pane) .hd-pane-col{display:none}
  .hd-pane-col{padding:0}
}
/* ── List ────────────────────────────────────────────────── */
.hd-list{max-height:calc(100dvh - 116px);overflow-y:auto;background:var(--hd-panel);position:relative;transition:opacity .15s}
.hd-list[aria-busy=true]{opacity:.45;pointer-events:none}
.hd-row{display:grid;grid-template-columns:auto 1fr;gap:0 .6rem;align-items:start;padding:12px 14px;border-bottom:1px solid var(--hd-bd-l);text-decoration:none;color:var(--hd-tx);cursor:pointer;transition:background .1s;position:relative}
.hd-row:last-child{border-bottom:none}
.hd-row:hover{background:#fafafa}
.hd-row.active{background:var(--hd-accent-l)}
.hd-row.active::before{content:'';position:absolute;left:0;top:0;bottom:0;width:3px;background:var(--hd-accent);border-radius:0 2px 2px 0}
/* avatar in list */
.hd-row-av{width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.7rem;font-weight:700;color:#fff;flex-shrink:0;margin-top:2px}
.hd-row-body{min-width:0}
.hd-row-top{display:flex;justify-content:space-between;align-items:center;gap:6px;margin-bottom:2px}
.hd-row-num{font:600 .7rem ui-monospace,SFMono-Regular,Menlo,monospace;color:var(--hd-tx3)}
.hd-row-time{font-size:.68rem;color:var(--hd-tx3);white-space:nowrap}
.hd-row-title{font-weight:600;font-size:.85rem;line-height:1.3;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;color:var(--hd-tx)}
.hd-row.active .hd-row-title{color:var(--hd-accent-h)}
.hd-row-meta{display:flex;flex-wrap:wrap;gap:3px;align-items:center;margin-top:4px}
/* ── Pane ──────────────────────────────────────────────── */
.hd-pane{min-height:300px;padding-top:.25rem}
.hd-pane-empty{display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;color:var(--hd-tx3);padding:5rem 1rem;min-height:300px}
.hd-pane-empty i{font-size:3rem;margin-bottom:.75rem;opacity:.35}
.hd-pane-empty p{font-size:.9rem;margin:0}
/* ── Messages ──────────────────────────────────────────── */
.hd-msg-wrap{display:grid;grid-template-columns:32px 1fr;gap:.5rem 10px;align-items:start;margin-bottom:14px}
.hd-msg-av{width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.7rem;font-weight:700;color:#fff;flex-shrink:0;margin-top:2px}
.hd-msg-av-req{background:linear-gradient(135deg,#0ea5e9,#38bdf8)}
.hd-msg-av-op {background:linear-gradient(135deg,var(--hd-accent),#818cf8)}
.hd-msg-av-sys{background:linear-gradient(135deg,#64748b,#94a3b8)}
.hd-msg{border-radius:0 10px 10px 10px;padding:12px 16px;position:relative;word-break:break-word}
.hd-msg-user{background:#f0f9ff;border:1px solid #bae6fd}
.hd-msg-op{background:#f9fafb;border:1px solid var(--hd-bd)}
.hd-msg-intern{background:#fffbeb;border:1px dashed #fcd34d}
.hd-msg-head{display:flex;justify-content:space-between;align-items:baseline;gap:8px;margin-bottom:6px}
.hd-msg-author{font-weight:700;font-size:.82rem;color:var(--hd-tx)}
.hd-msg-ts{font-size:.7rem;color:var(--hd-tx3);white-space:nowrap}
.hd-msg-intern-badge{display:inline-flex;align-items:center;gap:.2rem;font-size:.65rem;font-weight:700;background:#fef3c7;color:#92400e;border:1px solid #fde68a;border-radius:4px;padding:1px 5px;margin-left:4px;text-transform:uppercase;letter-spacing:.03em}
/* ── Body ──────────────────────────────────────────────── */
.hd-body{font-size:.9rem;line-height:1.65;color:var(--hd-tx)}
.hd-body.hd-clamp{max-height:11em;overflow:hidden;-webkit-mask-image:linear-gradient(180deg,#000 70%,transparent);mask-image:linear-gradient(180deg,#000 70%,transparent)}
.hd-body.hd-expanded{max-height:none;-webkit-mask-image:none;mask-image:none}
.hd-body p{margin:0 0 .45em}.hd-body p:last-child{margin-bottom:0}
.hd-body ul,.hd-body ol{padding-left:1.5em;margin:.25em 0}
.hd-body blockquote{border-left:3px solid var(--hd-bd);padding-left:.8em;color:var(--hd-tx2);margin:.4em 0}
.hd-body a{color:var(--hd-accent)}
.hd-body strong{font-weight:700}.hd-body em{font-style:italic}
.hd-more-btn{font-size:.75rem;color:var(--hd-accent);background:none;border:none;padding:2px 0;cursor:pointer;margin-top:4px}
/* ── Reply form ──────────────────────────────────────────── */
.hd-reply-box{background:var(--hd-panel);border:1px solid var(--hd-bd);border-radius:12px;overflow:hidden;box-shadow:var(--hd-shadow)}
.hd-reply-hd{display:flex;align-items:center;justify-content:space-between;padding:.55rem .85rem;border-bottom:1px solid var(--hd-bd-l);background:#fafafa}
.hd-reply-hd-title{font-size:.82rem;font-weight:600;color:var(--hd-tx2)}
.hd-reply-body{padding:.75rem .85rem}
/* ── Macro panel ─────────────────────────────────────────── */
.hd-macro-toggle{display:inline-flex;align-items:center;gap:.3rem;font-size:.78rem;font-weight:600;color:var(--hd-tx2);background:var(--hd-bg);border:1px solid var(--hd-bd);border-radius:6px;padding:.25rem .6rem;cursor:pointer;transition:all .15s}
.hd-macro-toggle:hover{background:var(--hd-accent-l);color:var(--hd-accent);border-color:var(--hd-accent-m)}
.hd-macro-toggle.open{background:var(--hd-accent-l);color:var(--hd-accent);border-color:var(--hd-accent-m)}
.hd-macro-panel{display:none;border-top:1px solid var(--hd-bd-l);background:#fafafa;padding:.6rem}
.hd-macro-panel.open{display:block}
.hd-macro-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:.4rem;max-height:200px;overflow-y:auto}
.hd-macro-card{background:var(--hd-panel);border:1px solid var(--hd-bd);border-radius:8px;padding:.5rem .65rem;cursor:pointer;transition:all .12s;text-align:left}
.hd-macro-card:hover{border-color:var(--hd-accent);background:var(--hd-accent-l);box-shadow:0 0 0 2px var(--hd-accent-m)}
.hd-macro-card-title{font-size:.8rem;font-weight:600;color:var(--hd-tx);line-height:1.3}
.hd-macro-card-preview{font-size:.72rem;color:var(--hd-tx3);margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
/* ── Quill ────────────────────────────────────────────────── */
.hd-quill-wrap .ql-toolbar.ql-snow{border:1px solid var(--hd-bd);border-radius:8px 8px 0 0;background:#fafafa;padding:4px 6px}
.hd-quill-wrap .ql-container.ql-snow{border:1px solid var(--hd-bd);border-top:none;border-radius:0 0 8px 8px}
.hd-quill-wrap .ql-editor{min-height:110px;font-size:.9rem;font-family:inherit}
.hd-quill-wrap .ql-editor.ql-blank::before{color:var(--hd-tx3);font-style:normal}
.hd-quill-wrap.is-invalid .ql-toolbar,.hd-quill-wrap.is-invalid .ql-container{border-color:var(--hd-err)}
/* Fullscreen */
.hd-quill-fs-overlay{display:none;position:fixed;inset:0;z-index:1070;background:rgba(0,0,0,.5);align-items:center;justify-content:center}
.hd-quill-fs-overlay.active{display:flex}
.hd-quill-fs-box{background:#fff;border-radius:14px;width:min(940px,96vw);max-height:93vh;display:flex;flex-direction:column;box-shadow:0 24px 64px rgba(0,0,0,.3)}
.hd-quill-fs-header{display:flex;align-items:center;justify-content:space-between;padding:.65rem 1.1rem;border-bottom:1px solid var(--hd-bd);flex-shrink:0}
.hd-quill-fs-body{flex:1;overflow:hidden;display:flex;flex-direction:column;min-height:0}
.hd-quill-fs-body .ql-toolbar.ql-snow{border-radius:0;border-left:none;border-right:none;border-top:none;flex-shrink:0}
.hd-quill-fs-body .ql-container.ql-snow{border:none;flex:1;overflow:auto}
.hd-quill-fs-body .ql-editor{min-height:300px;height:100%;font-size:1rem;font-family:inherit}
/* ── Email badges ─────────────────────────────────────────── */
.hd-email-badge{display:inline-flex;align-items:center;gap:.3rem;padding:.15rem .55rem;border-radius:999px;font-size:.72rem;font-weight:700;white-space:nowrap}
.hd-email-badge.verified{background:#dcfce7;color:#166534;border:1px solid #86efac}
.hd-email-badge.org{background:var(--hd-accent-m);color:#3730a3;border:1px solid #a5b4fc}
.hd-email-badge.unknown{background:var(--hd-bg);color:var(--hd-tx2);border:1px solid var(--hd-bd)}
/* ── Unread ───────────────────────────────────────────────── */
.hd-row-unread .hd-row-title{font-weight:700}
.hd-unread-dot{display:inline-block;width:7px;height:7px;border-radius:50%;background:var(--hd-accent);flex-shrink:0;margin-right:2px}
.hd-unread-badge{display:inline-flex;align-items:center;justify-content:center;background:var(--hd-accent);color:#fff;border-radius:999px;font-size:.65rem;font-weight:700;min-width:17px;height:17px;padding:0 4px;line-height:1}
/* ── Bulk bar ─────────────────────────────────────────────── */
.hd-bulk-bar{position:fixed;bottom:0;left:0;right:0;z-index:1060;background:#1e1b4b;color:#e0e7ff;padding:.6rem 1.2rem;box-shadow:0 -2px 20px rgba(79,70,229,.3);border-top:2px solid var(--hd-accent)}
.hd-bulk-inner{display:flex;align-items:center;flex-wrap:wrap;gap:.5rem;max-width:1400px;margin:0 auto}
.hd-bulk-count{font-size:.85rem;font-weight:600;white-space:nowrap;color:#a5b4fc}
.hd-bulk-bar .input-group-text,.hd-bulk-bar .form-select{background:#312e81;border-color:#4338ca;color:#e0e7ff}
.hd-bulk-bar .btn-outline-secondary{border-color:#6366f1;color:#c7d2fe}.hd-bulk-bar .btn-outline-secondary:hover{background:#4338ca}
.hd-bulk-bar .btn-outline-danger{border-color:#f87171;color:#fca5a5}.hd-bulk-bar .btn-outline-danger:hover{background:#dc2626;color:#fff}
.hd-bulk-bar .btn-link{color:#818cf8}
/* ── Toast ─────────────────────────────────────────────────── */
.hd-toast-wrap{position:fixed;bottom:1.2rem;right:1.2rem;z-index:1090;display:flex;flex-direction:column;gap:.5rem}
.hd-toast{background:#fff;border-left:4px solid var(--hd-accent);box-shadow:var(--hd-shadow-md);border-radius:10px;padding:.7rem 1.1rem;font-size:.85rem;min-width:240px;max-width:380px;animation:hdToastIn .2s ease;display:flex;align-items:flex-start;gap:.5rem}
.hd-toast.ok{border-color:var(--hd-success)}.hd-toast.err{border-color:var(--hd-err)}
@keyframes hdToastIn{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}
/* ── Detail header badges ──────────────────────────────────── */
.hd-detail-num{font:700 .8rem ui-monospace,SFMono-Regular,monospace;color:var(--hd-tx3);letter-spacing:.03em}
</style>
CSS;
}

// ── Powiadomienia ─────────────────────────────────────────────────────────────

function hd_notify_status_change(array $ticket, string $old_status, string $new_status, string $note = ''): void {
    $old_label = HD_STATUSES[$old_status]['label'] ?? $old_status;
    $new_label = HD_STATUSES[$new_status]['label'] ?? $new_status;
    $org       = defined('ORG_NAME') ? ORG_NAME : 'Helpdesk';
    $view_url  = APP_URL . '/helpdesk/view.php?id=' . $ticket['id'];
    $track_url = hd_track_url($ticket);   // link bez logowania — umożliwia kontynuację
    $num       = $ticket['number'];

    // SMS — zgłaszający dostaje link do mikropanelu
    if (!empty($ticket['requester_phone'])) {
        try {
            require_once dirname(__DIR__) . '/includes/sms.php';
            $sms = "[{$org}] Zgłoszenie {$num} – status: {$old_label} → {$new_label}. Szczegóły: {$track_url}";
            sms_send($ticket['requester_phone'], $sms);
        } catch (\Throwable $e) {}
    }

    // E-mail do zgłaszającego — CTA prowadzi do mikropanelu (podgląd + odpowiedź)
    $email = $ticket['requester_email'] ?? '';
    if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        try {
            require_once dirname(__DIR__) . '/includes/mail_queue.php';
            $_sc_data = ['requester_name' => $ticket['requester_name'], 'number' => $num,
                         'title' => $ticket['title'], 'old_status' => $old_label,
                         'new_status' => $new_label, 'note' => $note,
                         'track_url' => $track_url, 'org' => $org];
            $_sc_tpl  = hd_email_tpl('status_change', $_sc_data);
            mail_queue_add(
                $email, $ticket['requester_name'],
                $_sc_tpl ? $_sc_tpl['subject'] : "[{$num}] Zmiana statusu zgłoszenia: {$new_label}",
                $_sc_tpl ? $_sc_tpl['body']    : _hd_email_status($ticket, $old_label, $new_label, $note, $org, $track_url)
            );
        } catch (\Throwable $e) {}
    }

    // Powiadom przypisanego operatora — link do panelu wewnętrznego
    if (!empty($ticket['assigned_to'])) {
        try {
            $op = db_one("SELECT email, name FROM users WHERE id=?", [(int)$ticket['assigned_to']]);
            if ($op && !empty($op['email']) && $op['email'] !== $email) {
                require_once dirname(__DIR__) . '/includes/mail_queue.php';
                $_sc_op_data = ['requester_name' => $ticket['requester_name'],
                                'agent_name' => $op['name'] ?? '', 'number' => $num,
                                'title' => $ticket['title'], 'old_status' => $old_label,
                                'new_status' => $new_label, 'note' => $note,
                                'view_url' => $view_url, 'track_url' => $track_url, 'org' => $org];
                $_sc_op_tpl  = hd_email_tpl('status_change', $_sc_op_data);
                mail_queue_add(
                    $op['email'], $op['name'] ?? '',
                    $_sc_op_tpl ? $_sc_op_tpl['subject'] : "[{$num}] Status: {$new_label} — {$ticket['title']}",
                    $_sc_op_tpl ? $_sc_op_tpl['body']    : _hd_email_status($ticket, $old_label, $new_label, $note, $org, $view_url)
                );
            }
        } catch (\Throwable $e) {}
    }
}

function hd_notify_new_message(array $ticket, array $message): void {
    if ($message['is_internal']) return;
    $org       = defined('ORG_NAME') ? ORG_NAME : 'Helpdesk';
    $view_url  = APP_URL . '/helpdesk/view.php?id=' . $ticket['id'];
    $track_url = hd_track_url($ticket);   // link bez logowania — umożliwia kontynuację
    $num       = $ticket['number'];

    $msg_uid = (int)($message['user_id'] ?? 0);
    $req_uid = (int)($ticket['requester_id'] ?? 0);

    // Wiadomość od operatora → powiadom zgłaszającego (link do mikropanelu)
    if ($msg_uid !== $req_uid && !empty($ticket['requester_email'])) {
        $email = $ticket['requester_email'];
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            try {
                require_once dirname(__DIR__) . '/includes/mail_queue.php';
                $_nm_u_data = ['requester_name' => $ticket['requester_name'], 'number' => $num,
                               'title' => $ticket['title'], 'message_body' => $message['body'],
                               'agent_name' => $message['user_name'], 'track_url' => $track_url, 'org' => $org];
                $_nm_u_tpl  = hd_email_tpl('new_msg_to_user', $_nm_u_data);
                mail_queue_add(
                    $email, $ticket['requester_name'],
                    $_nm_u_tpl ? $_nm_u_tpl['subject'] : "[{$num}] Nowa odpowiedź na zgłoszenie",
                    $_nm_u_tpl ? $_nm_u_tpl['body']    : _hd_email_message($ticket, $message, $org, $track_url, false)
                );
            } catch (\Throwable $e) {}
        }
        // SMS do zgłaszającego
        if (!empty($ticket['requester_phone'])) {
            try {
                require_once dirname(__DIR__) . '/includes/sms.php';
                sms_send($ticket['requester_phone'],
                    "[{$org}] Zgłoszenie {$num}: nowa odpowiedź od operatora. Sprawdź: {$track_url}");
            } catch (\Throwable $e) {}
        }
    }

    // Wiadomość od zgłaszającego → powiadom operatora(ów) (panel wewnętrzny)
    // Detekcja: user_id = requester_id LUB brak user_id (track.php bez konta)
    $is_from_requester = ($msg_uid === $req_uid)
        || ($msg_uid === 0 && $req_uid === 0)
        || ($msg_uid === 0 && !empty($message['user_name']));

    if ($is_from_requester) {
        try {
            require_once dirname(__DIR__) . '/includes/mail_queue.php';
            $_nm_a_data = ['requester_name' => $ticket['requester_name'], 'number' => $num,
                           'title' => $ticket['title'], 'message_body' => $message['body'],
                           'from_name' => $message['user_name'], 'view_url' => $view_url, 'org' => $org];
            $_nm_a_tpl  = hd_email_tpl('new_msg_to_agent', $_nm_a_data);
            $subject    = $_nm_a_tpl ? $_nm_a_tpl['subject'] : "[{$num}] Odpowiedź zgłaszającego: {$ticket['title']}";
            $body_html  = $_nm_a_tpl ? $_nm_a_tpl['body']   : _hd_email_message($ticket, $message, $org, $view_url, true);
            if (!empty($ticket['assigned_to'])) {
                // Powiadom przypisanego operatora
                $op = db_one("SELECT email, name FROM users WHERE id=?", [(int)$ticket['assigned_to']]);
                if ($op && !empty($op['email'])) {
                    mail_queue_add($op['email'], $op['name'] ?? '', $subject, $body_html);
                }
            } else {
                // Brak przypisania — powiadom wszystkich aktywnych operatorów i adminów
                $ops = db_all(
                    "SELECT email, name FROM users WHERE (helpdesk_operator=1 OR role='admin') AND is_active=1 AND email IS NOT NULL AND email != ''", []
                );
                foreach ($ops as $op) {
                    mail_queue_add($op['email'], $op['name'] ?? '', $subject, $body_html);
                }
            }
        } catch (\Throwable $e) {}
    }
}

function hd_notify_assigned(array $ticket, array $operator): void {
    if (empty($operator['email'])) return;
    $org   = defined('ORG_NAME') ? ORG_NAME : 'Helpdesk';
    $url   = APP_URL . '/helpdesk/view.php?id=' . $ticket['id'];
    $num   = $ticket['number'];
    $name  = h($operator['name'] ?? $operator['email']);
    $title = h($ticket['title']);
    $_as_data = ['agent_name' => $operator['name'] ?? $operator['email'],
                 'number' => $num, 'title' => $ticket['title'],
                 'view_url' => $url, 'org' => $org];
    $_as_tpl  = hd_email_tpl('assigned', $_as_data);
    try {
        require_once dirname(__DIR__) . '/includes/mail_queue.php';
        if ($_as_tpl) {
            mail_queue_add($operator['email'], $operator['name'] ?? '', $_as_tpl['subject'], $_as_tpl['body']);
            return;
        }
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

/**
 * Szybkie utworzenie zgłoszenia bez załączników — do wywołania spoza
 * głównego formularza helpdesk/new.php (który obsługuje też pliki i wymaga
 * sesji SZO/current_user()). Używane m.in. przez panel dydaktyka
 * (karty30/ti/dydaktyk/api_helpdesk.php), którego sesja jest odrębna
 * (k30_dydaktyk) i nie ma current_user().
 *
 * $requester: ['id'=>int|null, 'name'=>string, 'email'=>string].
 * $source: znacznik pochodzenia zgłoszenia (np. 'dydaktyk') — do raportów.
 */
/**
 * Integracja Redmine (jednokierunkowa): tworzy issue w Redmine z nowego zgłoszenia
 * i zapisuje jego numer w helpdesk_tickets.redmine_issue_id. Best-effort — błąd
 * integracji NIE może zablokować utworzenia zgłoszenia w SZO.
 */
function hd_redmine_sync_ticket(int $ticket_id): void {
    if ($ticket_id <= 0) return;
    $rm = __DIR__ . '/redmine.php';
    if (!is_file($rm)) return;
    require_once $rm;
    if (!function_exists('redmine_is_enabled') || !redmine_is_enabled()) return;

    try {
        $t = db_one("SELECT * FROM helpdesk_tickets WHERE id=?", [$ticket_id]);
        if (!$t || (int)($t['redmine_issue_id'] ?? 0) > 0) return; // brak lub już wypchnięte

        $meta = 'Zgłoszenie SZO ' . (string)$t['number'] . "\n"
              . 'Zgłaszający: ' . trim((string)($t['requester_name'] ?? '') . ' <' . (string)($t['requester_email'] ?? '') . '>') . "\n"
              . 'Kategoria: ' . (string)$t['category'] . ' | Priorytet: ' . (string)$t['priority'] . "\n\n"
              . (string)($t['description'] ?? '');

        $res = redmine_create_issue([
            'subject'     => '[' . (string)$t['number'] . '] ' . (string)$t['title'],
            'description' => $meta,
        ]);
        db_update('helpdesk_tickets', ['redmine_issue_id' => (int)$res['id']], $ticket_id);
    } catch (\Throwable $e) {
        error_log('[redmine] sync zgłoszenia #' . $ticket_id . ': ' . $e->getMessage());
    }
}

/**
 * Dwukierunkowa synchronizacja: pobiera z Redmine stan powiązanego issue —
 * importuje NOWE notatki jako wiadomości zgłoszenia i mapuje zamknięcie issue
 * na status SZO. Best-effort. Zwraca true, gdy coś zaktualizowano.
 */
function hd_redmine_pull_ticket(array $ticket): bool {
    $rm = __DIR__ . '/redmine.php';
    if (!is_file($rm)) return false;
    require_once $rm;
    if (!function_exists('redmine_is_enabled') || !redmine_is_enabled()) return false;

    $iid = (int)($ticket['redmine_issue_id'] ?? 0);
    if ($iid <= 0) return false;

    try {
        $issue = redmine_get_issue($iid, ['journals']);
    } catch (\Throwable $e) {
        error_log('[redmine] pull #' . ($ticket['id'] ?? 0) . ': ' . $e->getMessage());
        return false;
    }
    if (!$issue) return false;

    $ticket_id = (int)$ticket['id'];
    $changed   = false;

    // 1) Import nowych notatek z dziennika (journals) jako wiadomości.
    $lastJ = (int)($ticket['redmine_last_journal_id'] ?? 0);
    $maxJ  = $lastJ;
    foreach ((array)($issue['journals'] ?? []) as $j) {
        $jid   = (int)($j['id'] ?? 0);
        $notes = trim((string)($j['notes'] ?? ''));
        if ($jid <= $lastJ || $notes === '') continue;
        $author = trim((string)($j['user']['name'] ?? 'Redmine'));
        db_insert('helpdesk_messages', [
            'ticket_id'   => $ticket_id,
            'user_id'     => null,
            'user_name'   => 'Redmine: ' . $author,
            'body'        => $notes,
            'is_internal' => 0,
        ]);
        if ($jid > $maxJ) $maxJ = $jid;
        $changed = true;
    }

    $upd = [];
    if ($maxJ > $lastJ) $upd['redmine_last_journal_id'] = $maxJ;

    // 2) Mapowanie zamknięcia: issue zamknięte w Redmine → SZO „rozwiązane".
    $closed = !empty($issue['closed_on']);
    if ($closed && !in_array((string)$ticket['status'], ['rozwiązane', 'zamknięte'], true)) {
        $upd['status'] = 'rozwiązane';
        $changed = true;
    }

    if ($upd) db_update('helpdesk_tickets', $upd, $ticket_id);
    return $changed;
}

/**
 * Synchronizuje wszystkie zgłoszenia powiązane z Redmine (nie zamknięte).
 * Do crona. Zwraca ['synced'=>int, 'errors'=>int].
 */
function hd_redmine_pull_all(): array {
    helpdesk_migrate();
    $rows = db_all(
        "SELECT * FROM helpdesk_tickets
          WHERE redmine_issue_id > 0 AND status <> 'zamknięte' AND merged_into IS NULL"
    );
    $synced = 0; $errors = 0;
    foreach ($rows as $t) {
        try { if (hd_redmine_pull_ticket($t)) $synced++; }
        catch (\Throwable $e) { $errors++; error_log('[redmine] pull_all #' . $t['id'] . ': ' . $e->getMessage()); }
    }
    return ['synced' => $synced, 'errors' => $errors];
}

function hd_ticket_quick_create(array $requester, string $title, string $description, string $category, string $priority, string $source): int {
    helpdesk_migrate();
    if (!isset(HD_CATEGORIES[$category])) $category = 'it_inne';
    if (!isset(HD_PRIORITIES[$priority])) $priority = 'normalny';
    $number = hd_next_number(hd_number_prefix_for($category));

    $ticket_id = db_insert('helpdesk_tickets', [
        'number'          => $number,
        'title'           => $title,
        'description'     => $description,
        'category'        => $category,
        'priority'        => $priority,
        'status'          => 'nowe',
        'requester_id'    => $requester['id'] ?: null,
        'requester_name'  => (string)($requester['name'] ?? ''),
        'requester_email' => (string)($requester['email'] ?? ''),
        'source'          => $source,
    ]);
    db_insert('helpdesk_messages', [
        'ticket_id'   => $ticket_id,
        'user_id'     => $requester['id'] ?: null,
        'user_name'   => (string)($requester['name'] ?? ''),
        'body'        => $description,
        'is_internal' => 0,
    ]);

    hd_redmine_sync_ticket($ticket_id); // integracja Redmine (best-effort)

    // E-mail potwierdzający do zgłaszającego + powiadomienie operatorów —
    // ten sam wzorzec co w helpdesk/new.php, w try/catch (mail nie może
    // zablokować utworzenia zgłoszenia).
    try {
        require_once __DIR__ . '/mail_queue.php';
        $url = (defined('APP_URL') ? rtrim(APP_URL, '/') : '') . '/helpdesk/view.php?id=' . $ticket_id;
        $num_h = h($number);
        $title_h = h($title);
        if (!empty($requester['email']) && filter_var($requester['email'], FILTER_VALIDATE_EMAIL)) {
            mail_queue_add(
                (string)$requester['email'], (string)($requester['name'] ?? ''),
                "[{$number}] Zgłoszenie przyjęte",
                "<p>Zgłoszenie <strong>{$num_h}</strong> — {$title_h} zostało zarejestrowane.</p>"
                . "<p><a href=\"{$url}\">Śledź status</a></p>"
            );
        }
        $ops = db_all("SELECT email, name FROM users WHERE helpdesk_operator=1 AND is_active=1 AND email IS NOT NULL AND email != ''");
        foreach ($ops as $op) {
            if (empty($op['email'])) continue;
            mail_queue_add(
                (string)$op['email'], (string)($op['name'] ?? ''),
                "[{$number}] Nowe zgłoszenie: {$title}",
                "<p>Nowe zgłoszenie {$num_h} — {$title_h}.</p><p><a href=\"{$url}\">Otwórz</a></p>"
            );
        }
    } catch (\Throwable $e) {}

    return $ticket_id;
}

/**
 * Zapisuje załączniki zgłoszenia z $_FILES['attachments'] (pole `multiple`) —
 * ten sam wzorzec co helpdesk/new.php (whitelist rozszerzeń, katalog
 * uploads/helpdesk/). Używane m.in. przez panel dydaktyka, gdzie zgłoszenie
 * powstaje przez hd_ticket_quick_create()/API zamiast tego formularza.
 */
function hd_save_attachments(int $ticketId, array $filesField, ?int $uploadedBy = null): void {
    if (empty($filesField['name'][0])) return;
    $dir = UPLOAD_DIR . 'helpdesk/';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $allow = ['pdf','doc','docx','xls','xlsx','jpg','jpeg','png','gif','zip','txt','csv'];
    foreach ($filesField['name'] as $i => $origName) {
        if (($filesField['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        if (!in_array($ext, $allow, true)) continue;
        if ((int)($filesField['size'][$i] ?? 0) > 10 * 1024 * 1024) continue; // 10 MB / plik
        $stored = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        if (@move_uploaded_file($filesField['tmp_name'][$i], $dir . $stored)) {
            db_insert('helpdesk_attachments', [
                'ticket_id'     => $ticketId,
                'original_name' => $origName,
                'stored_path'   => 'helpdesk/' . $stored,
                'file_size'     => $filesField['size'][$i],
                'uploaded_by'   => $uploadedBy,
            ]);
        }
    }
}

/**
 * Wywołuje karty30/ti/dydaktyk/api_helpdesk.php przez HTTP, przekazując
 * ciasteczko bieżącej sesji panelu dydaktyka — patrz ti_protocols_api_call()
 * (includes/ti_protocols.php) po dokładne uzasadnienie tego wzorca. Zwraca
 * null przy jakimkolwiek niepowodzeniu, żeby wywołujący spadł na fallback
 * (hd_ticket_quick_create() wprost).
 */
function hd_dyd_api_call(string $action, array $params = [], string $method = 'GET'): ?array {
    if (!defined('APP_URL') || !function_exists('curl_init')) return null;
    $url = rtrim(APP_URL, '/') . '/karty30/ti/dydaktyk/api_helpdesk.php?action=' . urlencode($action);
    if ($method === 'GET' && $params) $url .= '&' . http_build_query($params);

    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 3,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_COOKIE         => session_name() . '=' . session_id(),
        CURLOPT_CUSTOMREQUEST  => $method,
    ];
    if ($method === 'POST') {
        $opts[CURLOPT_POSTFIELDS] = json_encode($params, JSON_UNESCAPED_UNICODE);
        $opts[CURLOPT_HTTPHEADER] = ['Content-Type: application/json'];
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false || $code < 200 || $code >= 300) return null;
    $json = json_decode((string)$body, true);
    return is_array($json) && !isset($json['error']) ? $json : null;
}
