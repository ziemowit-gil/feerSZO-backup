<?php
/**
 * includes/helpdesk.php — Moduł Helpdesk IT
 */

const HD_STATUSES = [
    'nowe'            => ['label' => 'Nowe',                            'class' => 'primary',   'icon' => 'bi-inbox-fill',         'text' => 'primary'],
    'otwarte'         => ['label' => 'Otwarte',                         'class' => 'warning',   'icon' => 'bi-folder2-open',       'text' => 'dark'],
    'oczekuje'        => ['label' => 'Oczekuje',                        'class' => 'secondary', 'icon' => 'bi-hourglass-split',    'text' => 'white'],
    'przekazane_zewn' => ['label' => 'Przekazano do firmy zewnętrznej', 'class' => 'dark',      'icon' => 'bi-box-arrow-up-right', 'text' => 'white'],
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
    'bug_report'        => 'Zgłoszenie błędu',
];

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
    ] as $sql) { try { $pdo->exec($sql); } catch (\Throwable $e) {} }
    // SQLite dopuszcza wiele NULL w UNIQUE — token unikalny tylko dla wypełnionych.
    try { $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_hd_token ON helpdesk_tickets(access_token)"); } catch (\Throwable $e) {}

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
    $note_block = trim($note) !== ''
        ? '<div style="background:#f8f9fa;border-left:3px solid #6c757d;padding:10px 14px;margin:14px 0;border-radius:0 4px 4px 0;font-size:.9em">'
          . nl2br(h($note)) . '</div>'
        : '';
    try {
        require_once dirname(__DIR__) . '/includes/mail_queue.php';
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
function hd_reply_templates(array $ticket): array {
    $org   = defined('ORG_NAME') ? ORG_NAME : 'Helpdesk';
    $name  = trim((string)($ticket['requester_name'] ?? ''));
    $first = $name !== '' ? (preg_split('/\s+/', $name)[0] ?? $name) : '';
    $hello = $first !== '' ? "Dzień dobry {$first}," : 'Dzień dobry,';
    $num   = (string)($ticket['number'] ?? '');
    $firma = trim((string)($ticket['ext_vendor'] ?? ''));
    $firma_txt = $firma !== '' ? $firma : 'firmy zewnętrznej';
    $ref   = trim((string)($ticket['ext_ref'] ?? ''));
    $ref_line = $ref !== '' ? " Numer sprawy nadany przez firmę: {$ref}." : '';
    $op    = trim((string)($ticket['assigned_name'] ?? ''));
    $sig   = ($op !== '' ? $op . "\n" : '') . $org . ' · Helpdesk IT';

    return [
        'przyjete' => [
            'label' => 'Przyjęto zgłoszenie',
            'body'  => "{$hello}\n\nDziękujemy za zgłoszenie {$num}. Przyjęliśmy je do realizacji i zajmiemy się nim najszybciej, jak to możliwe. O postępach będziemy informować w tym wątku.\n\nPozdrawiam,\n{$sig}",
        ],
        'info' => [
            'label' => 'Prośba o dodatkowe informacje',
            'body'  => "{$hello}\n\nAby sprawnie zająć się zgłoszeniem {$num}, prosimy o dodatkowe informacje:\n- \n- \n\nPo otrzymaniu odpowiedzi wrócimy do sprawy. Możesz odpowiedzieć bezpośrednio w tym wątku.\n\nPozdrawiam,\n{$sig}",
        ],
        'przekazane_zewn' => [
            'label' => 'Przekazano do firmy zewnętrznej',
            'body'  => "{$hello}\n\nUprzejmie informujemy, że zgłoszenie {$num} zostało przekazane do {$firma_txt}, która zajmie się jego dalszą realizacją.{$ref_line}\n\nDalsze aktualizacje będą się pojawiać w tym wątku. W razie pytań prosimy o odpowiedź na tę wiadomość.\n\nPozdrawiam,\n{$sig}",
        ],
        'wymaga_prac' => [
            'label' => 'Wymaga prac programistycznych',
            'body'  => "{$hello}\n\nDziękujemy za zgłoszenie {$num}. Opisana sprawa wymaga prac programistycznych, dlatego nie rozwiążemy jej od razu — zaplanowaliśmy ją do wdrożenia w jednej z najbliższych wersji systemu.\n\nO udostępnieniu zmiany poinformujemy w tym wątku. Dziękujemy za cierpliwość i cenną uwagę.\n\nPozdrawiam,\n{$sig}",
        ],
        'rozwiazane' => [
            'label' => 'Rozwiązano — prośba o potwierdzenie',
            'body'  => "{$hello}\n\nZgłoszenie {$num} zostało rozwiązane. Prosimy o sprawdzenie i potwierdzenie, czy wszystko działa prawidłowo. Jeśli w ciągu kilku dni nie otrzymamy odpowiedzi, zgłoszenie zostanie automatycznie zamknięte.\n\nPozdrawiam,\n{$sig}",
        ],
        'zamkniete' => [
            'label' => 'Zamknięcie zgłoszenia',
            'body'  => "{$hello}\n\nZamykamy zgłoszenie {$num}. Dziękujemy za kontakt — jeśli problem powróci lub pojawią się nowe pytania, prosimy o utworzenie nowego zgłoszenia.\n\nPozdrawiam,\n{$sig}",
        ],
    ];
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
function hd_ui_css(): string {
    return <<<CSS
<style>
.hd-skip{position:absolute;left:1rem;top:-3rem;background:#2563EB;color:#fff;padding:.5rem 1rem;border-radius:0 0 6px 6px;z-index:1080;text-decoration:none;font-weight:600;transition:top .12s}
.hd-skip:focus{top:0}
.hd-console :focus-visible{outline:3px solid #2563EB;outline-offset:2px;border-radius:4px}
.hd-console{display:flex;gap:14px;align-items:flex-start}
.hd-list-col{flex:0 0 340px;max-width:340px}
.hd-pane-col{flex:1 1 auto;min-width:0}
@media(max-width:900px){
  .hd-list-col{flex-basis:100%;max-width:100%}
  .hd-console.hd-show-pane .hd-list-col{display:none}
  .hd-console:not(.hd-show-pane) .hd-pane-col{display:none}
}
.hd-list{max-height:calc(100vh - 220px);overflow:auto;border:1px solid #e5e7eb;border-radius:12px;background:#fff;position:relative;transition:opacity .15s}
.hd-list[aria-busy=true]{opacity:.5;pointer-events:none}
.hd-row{display:block;padding:10px 12px;border-bottom:1px solid #f1f3f5;text-decoration:none;color:inherit;cursor:pointer}
.hd-row:last-child{border-bottom:none}
.hd-row:hover{background:#f8fafc}
.hd-row.active{background:#EEF4FF;box-shadow:inset 3px 0 0 #2563EB}
.hd-row-top{display:flex;justify-content:space-between;align-items:center;gap:6px}
.hd-row-num{font:600 .72rem ui-monospace,SFMono-Regular,Menlo,monospace;color:#64748b}
.hd-row-time{font-size:.7rem;color:#94a3b8;white-space:nowrap}
.hd-row-title{font-weight:600;font-size:.86rem;margin:.15rem 0;line-height:1.25;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.hd-row-meta{display:flex;flex-wrap:wrap;gap:4px;align-items:center}
.hd-pane{min-height:340px}
.hd-pane-empty{display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;color:#94a3b8;padding:4rem 1rem;border:1px dashed #cbd5e1;border-radius:12px;background:#fff;min-height:340px}
.hd-pane-empty i{font-size:2.6rem;color:#cbd5e1;margin-bottom:.6rem}
.hd-msg{border-radius:10px;padding:14px 18px;margin-bottom:12px}
.hd-msg-user{background:#EEF4FF;border-left:3px solid #2563EB}
.hd-msg-op{background:#F0FDF4;border-left:3px solid #16A34A}
.hd-msg-intern{background:#FFF7ED;border-left:3px dashed #EA580C}
.hd-body{white-space:pre-wrap;font-size:.9rem}
.hd-body.hd-clamp{max-height:11em;overflow:hidden;-webkit-mask-image:linear-gradient(180deg,#000 70%,transparent);mask-image:linear-gradient(180deg,#000 70%,transparent)}
.hd-body.hd-expanded{max-height:none;-webkit-mask-image:none;mask-image:none}
.hd-toast-wrap{position:fixed;bottom:1.2rem;right:1.2rem;z-index:1090;display:flex;flex-direction:column;gap:.5rem}
.hd-toast{background:#fff;border-left:4px solid #2563EB;box-shadow:0 6px 20px rgba(0,0,0,.15);border-radius:8px;padding:.7rem 1rem;font-size:.85rem;min-width:240px;max-width:360px;animation:hdToastIn .2s ease}
.hd-toast.ok{border-color:#16A34A}.hd-toast.err{border-color:#DC2626}
@keyframes hdToastIn{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}
</style>
CSS;
}

/** Renderuje wiersze listy zgłoszeń do konsoli (lub pusty stan). */
function hd_console_rows(array $tickets, bool $is_op, int $selId = 0): string {
    if (!$tickets) {
        return '<div class="hd-pane-empty" style="border:none;background:transparent;min-height:200px">'
             . '<i class="bi bi-inbox"></i><div>Brak zgłoszeń spełniających kryteria.</div></div>';
    }
    $now = time();
    $h = '';
    foreach ($tickets as $t) {
        $age = max(0, $now - strtotime($t['updated_at'] ?? 'now'));
        $ago = $age < 3600 ? max(1, (int)($age / 60)) . ' min'
             : ($age < 86400 ? (int)($age / 3600) . ' godz' : (int)($age / 86400) . ' dni');
        $active = ((int)$t['id'] === $selId) ? ' active' : '';
        $h .= '<a class="hd-row' . $active . '" data-id="' . (int)$t['id'] . '" href="' . APP_URL . '/helpdesk/view.php?id=' . (int)$t['id'] . '">';
        $h .= '<div class="hd-row-top"><span class="hd-row-num">' . h($t['number']) . '</span><span class="hd-row-time">' . $ago . ' temu</span></div>';
        $h .= '<div class="hd-row-title">' . h($t['title']) . '</div>';
        $h .= '<div class="hd-row-meta">' . hd_status_badge($t['status']) . hd_priority_badge($t['priority']);
        if ($is_op) $h .= hd_sla_indicator($t);
        $h .= '</div>';
        if ($is_op) {
            $assignee = !empty($t['assigned_name'])
                ? ' · <i class="bi bi-person-check text-success"></i> ' . h($t['assigned_name'])
                : ' · <span class="text-danger">nieprzypisane</span>';
            $h .= '<div class="hd-row-meta mt-1"><span class="text-muted" style="font-size:.72rem">'
                . '<i class="bi bi-person me-1"></i>' . h($t['requester_name']) . $assignee . '</span></div>';
        }
        $h .= '</a>';
    }
    return $h;
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
            mail_queue_add(
                $email, $ticket['requester_name'],
                "[{$num}] Zmiana statusu zgłoszenia: {$new_label}",
                _hd_email_status($ticket, $old_label, $new_label, $note, $org, $track_url)
            );
        } catch (\Throwable $e) {}
    }

    // Powiadom przypisanego operatora — link do panelu wewnętrznego
    if (!empty($ticket['assigned_to'])) {
        try {
            $op = db_one("SELECT email, name FROM users WHERE id=?", [(int)$ticket['assigned_to']]);
            if ($op && !empty($op['email']) && $op['email'] !== $email) {
                require_once dirname(__DIR__) . '/includes/mail_queue.php';
                mail_queue_add(
                    $op['email'], $op['name'] ?? '',
                    "[{$num}] Status: {$new_label} — {$ticket['title']}",
                    _hd_email_status($ticket, $old_label, $new_label, $note, $org, $view_url)
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
                mail_queue_add(
                    $email, $ticket['requester_name'],
                    "[{$num}] Nowa odpowiedź na zgłoszenie",
                    _hd_email_message($ticket, $message, $org, $track_url, false)
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

    // Wiadomość od zgłaszającego → powiadom przypisanego operatora (panel wewnętrzny)
    if ($msg_uid === $req_uid && !empty($ticket['assigned_to'])) {
        try {
            $op = db_one("SELECT email, name FROM users WHERE id=?", [(int)$ticket['assigned_to']]);
            if ($op && !empty($op['email'])) {
                require_once dirname(__DIR__) . '/includes/mail_queue.php';
                mail_queue_add(
                    $op['email'], $op['name'] ?? '',
                    "[{$num}] Odpowiedź użytkownika: {$ticket['title']}",
                    _hd_email_message($ticket, $message, $org, $view_url, true)
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
