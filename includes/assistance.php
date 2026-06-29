<?php
/**
 * includes/assistance.php — Moduł „Zgłoszenia asysty i specjalnych potrzeb".
 *
 * Rejestr zgłoszeń potrzeby asysty/wsparcia podczas wydarzeń organizacji
 * (tłumacz PJM, asysta dla osoby na wózku, pętla indukcyjna, neuroróżnorodność…).
 *
 * Warstwy:
 *   • asysta/form.php   — publiczny formularz (BEZ logowania) + honeypot/anty-bot
 *   • asysta/admin.php  — tabela zgłoszeń z filtrami + statystyki
 *   • asysta/view.php   — karta jednego zgłoszenia: status, przypisanie
 *                          wolontariusza, kanał powiadomień, notatki wewnętrzne
 *   • extforms/asystaFEER/ — publiczny punkt wejścia
 *
 * Tabela: szo_assistance_requests (auto-migracja, SQLite + MySQL).
 * Wszystkie zapytania to prepared statements (zob. includes/db.php).
 */

require_once __DIR__ . '/db.php';

/* ── Słowniki (klucz w bazie → etykieta dla człowieka) ─────────────────────── */

/**
 * Statusy zgłoszenia (kolejność = ścieżka obsługi).
 * Klucz trafia do bazy; etykieta jest pokazywana użytkownikowi.
 */
function asr_statuses(): array {
    return [
        'received'           => 'Przyjęty',
        'processing'         => 'W trakcie przetwarzania',
        'reviewing'          => 'W trakcie rozpatrywania',
        'confirmed'          => 'Potwierdzony – czekaj na kontakt',
        'done'               => 'Zrealizowany',
        'rejected'           => 'Odrzucony – czekaj na kontakt',
        'rejected_external'  => 'Odrzucony z przyczyn zewnętrznych – czekaj na kontakt',
        'resubmitted'        => 'Zmieniony – jako nowe zgłoszenie',
    ];
}

/** Kolor (klasa Bootstrap badge) dla statusu — do tabeli/karty. */
function asr_status_class(string $status): string {
    return [
        'received'          => 'text-bg-primary',
        'processing'        => 'text-bg-info',
        'reviewing'         => 'text-bg-info',
        'confirmed'         => 'text-bg-success',
        'done'              => 'text-bg-secondary',
        'rejected'          => 'text-bg-danger',
        'rejected_external' => 'text-bg-warning',
        'resubmitted'       => 'text-bg-dark',
    ][$status] ?? 'text-bg-light';
}

/**
 * Rodzaje asysty / specjalnych potrzeb (grupa checkboxów w formularzu).
 * Klucz → etykieta. „other" wymaga doprecyzowania w polu needs_other.
 */
function asr_needs(): array {
    return [
        'pjm'          => 'Asysta tłumacza języka migowego (PJM)',
        'wheelchair'   => 'Asysta osoby poruszającej się na wózku',
        'hearing_loop' => 'Pętla indukcyjna / wsparcie dla słabosłyszących',
        'blind'        => 'Asysta dla osoby niewidomej / słabowidzącej',
        'quiet'        => 'Miejsce wyciszenia / wsparcie neuroróżnorodności',
        'other'        => 'Inne (własny opis)',
    ];
}

/** Kanały powiadomienia wolontariusza (checkboxy w sekcji administracyjnej). */
function asr_channels(): array {
    return ['email' => 'E-mail', 'sms' => 'SMS'];
}

/** Bezpieczna etykieta z mapy (z fallbackiem na surową wartość). */
function asr_label(array $map, ?string $key): string {
    if ($key === null || $key === '') return '—';
    return $map[$key] ?? $key;
}

/** Zamienia listę kluczy (CSV) na czytelne etykiety. */
function asr_needs_labels(?string $csv): array {
    $map = asr_needs();
    $out = [];
    foreach (array_filter(array_map('trim', explode(',', (string)$csv))) as $k) {
        $out[$k] = $map[$k] ?? $k;
    }
    return $out;
}

/* ── Migracja schematu ─────────────────────────────────────────────────────── */

/**
 * Tworzy tabelę szo_assistance_requests, jeśli nie istnieje. Idempotentne.
 * Wołane na początku każdej strony modułu.
 */
function asr_migrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;

    $pdo = db();

    if (DB_TYPE === 'sqlite') {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS szo_assistance_requests (
                id                   INTEGER PRIMARY KEY AUTOINCREMENT,
                participant_name     TEXT    NOT NULL,
                participant_email    TEXT    NOT NULL,
                participant_phone    TEXT    NOT NULL,
                is_guardian          INTEGER NOT NULL DEFAULT 0,
                event_name           TEXT    NOT NULL DEFAULT '',
                event_when_where     TEXT    NOT NULL DEFAULT '',
                needs                TEXT    NOT NULL DEFAULT '',
                needs_other          TEXT    NOT NULL DEFAULT '',
                details              TEXT    NOT NULL DEFAULT '',
                status               TEXT    NOT NULL DEFAULT 'received',
                assigned_volunteer_id INTEGER NULL REFERENCES umowy_wolontariat(id) ON DELETE SET NULL,
                assigned_name        TEXT    NOT NULL DEFAULT '',
                assigned_channels    TEXT    NOT NULL DEFAULT '',
                internal_notes       TEXT    NOT NULL DEFAULT '',
                created_by           INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
                created_ip           TEXT    NULL,
                created_at           DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at           DATETIME NULL
            )
        ");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_asr_status ON szo_assistance_requests(status)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_asr_vol    ON szo_assistance_requests(assigned_volunteer_id)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_asr_created ON szo_assistance_requests(created_at)");
    } else {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS szo_assistance_requests (
                id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
                participant_name     VARCHAR(255) NOT NULL,
                participant_email    VARCHAR(255) NOT NULL,
                participant_phone    VARCHAR(64)  NOT NULL,
                is_guardian          TINYINT(1)   NOT NULL DEFAULT 0,
                event_name           VARCHAR(255) NOT NULL DEFAULT '',
                event_when_where     VARCHAR(255) NOT NULL DEFAULT '',
                needs                TEXT NOT NULL,
                needs_other          TEXT NOT NULL,
                details              TEXT NOT NULL,
                status               VARCHAR(32)  NOT NULL DEFAULT 'received',
                assigned_volunteer_id INT UNSIGNED NULL,
                assigned_name        VARCHAR(255) NOT NULL DEFAULT '',
                assigned_channels    VARCHAR(64)  NOT NULL DEFAULT '',
                internal_notes       TEXT NOT NULL,
                created_by           INT UNSIGNED NULL,
                created_ip           VARCHAR(64)  NULL,
                created_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at           TIMESTAMP NULL,
                PRIMARY KEY (id),
                KEY idx_asr_status (status),
                KEY idx_asr_vol (assigned_volunteer_id),
                KEY idx_asr_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
}

/* ── Operacje na danych ────────────────────────────────────────────────────── */

/** Pobiera pojedyncze zgłoszenie lub null. */
function asr_get(int $id): ?array {
    asr_migrate();
    return db_one("SELECT * FROM szo_assistance_requests WHERE id=?", [$id]);
}

/**
 * Lista zgłoszeń z opcjonalnym filtrem statusu i frazy (imię/e-mail/wydarzenie).
 * Od najnowszych.
 */
function asr_list(string $status = '', string $q = ''): array {
    asr_migrate();
    $w = []; $p = [];
    if ($status !== '' && array_key_exists($status, asr_statuses())) {
        $w[] = 'status = ?'; $p[] = $status;
    }
    if ($q !== '') {
        $w[] = '(participant_name LIKE ? OR participant_email LIKE ? OR event_name LIKE ?)';
        $like = '%' . $q . '%';
        array_push($p, $like, $like, $like);
    }
    $where = $w ? 'WHERE ' . implode(' AND ', $w) : '';
    return db_all("SELECT * FROM szo_assistance_requests $where ORDER BY id DESC", $p);
}

/** Czytelny numer zgłoszenia: ZA/0001/2026 (na podstawie ID i roku zgłoszenia). */
function asr_number(array $r): string {
    $year = substr((string)($r['created_at'] ?? date('Y')), 0, 4);
    if ($year === '' || !ctype_digit($year)) $year = date('Y');
    return sprintf('ZA/%04d/%s', (int)$r['id'], $year);
}

/**
 * Lista aktywnych wolontariuszy do przypisania (id, imię, e-mail, telefon).
 * Bezpieczna, gdy moduł wolontariatu nie istnieje.
 */
function asr_volunteers(): array {
    try {
        return db_all(
            "SELECT id, imie_nazwisko, email, telefon
               FROM umowy_wolontariat
              WHERE imie_nazwisko IS NOT NULL AND imie_nazwisko <> ''
              ORDER BY imie_nazwisko COLLATE NOCASE"
        );
    } catch (\Throwable $e) {
        try {
            return db_all(
                "SELECT id, imie_nazwisko, email, telefon FROM umowy_wolontariat
                  WHERE imie_nazwisko IS NOT NULL AND imie_nazwisko <> '' ORDER BY imie_nazwisko"
            );
        } catch (\Throwable $e2) {
            return [];
        }
    }
}

/**
 * Waliduje dane publiczne formularza (uczestnik + wydarzenie + potrzeby).
 * Zwraca [array $clean, array $errors]. Klucze $errors = nazwy pól.
 */
function asr_validate(array $in): array {
    $err = [];
    $clean = [];

    $clean['participant_name'] = trim((string)($in['participant_name'] ?? ''));
    if ($clean['participant_name'] === '') {
        $err['participant_name'] = 'Podaj imię i nazwisko.';
    } elseif (mb_strlen($clean['participant_name']) > 255) {
        $err['participant_name'] = 'Imię i nazwisko jest zbyt długie (max 255 znaków).';
    }

    $clean['participant_email'] = trim((string)($in['participant_email'] ?? ''));
    if ($clean['participant_email'] === '' || !filter_var($clean['participant_email'], FILTER_VALIDATE_EMAIL)) {
        $err['participant_email'] = 'Podaj poprawny adres e-mail.';
    }

    $clean['participant_phone'] = trim((string)($in['participant_phone'] ?? ''));
    if ($clean['participant_phone'] === '') {
        $err['participant_phone'] = 'Podaj numer telefonu.';
    } elseif (mb_strlen($clean['participant_phone']) > 64) {
        $err['participant_phone'] = 'Numer telefonu jest zbyt długi.';
    }

    $clean['is_guardian'] = (string)($in['is_guardian'] ?? '') === 'yes' ? 1 : 0;

    $clean['event_name']       = mb_substr(trim((string)($in['event_name'] ?? '')), 0, 255);
    $clean['event_when_where'] = mb_substr(trim((string)($in['event_when_where'] ?? '')), 0, 255);

    // Potrzeby — checkboxy; przyjmujemy tylko znane klucze.
    $allowed = array_keys(asr_needs());
    $picked = array_values(array_intersect($allowed, (array)($in['needs'] ?? [])));
    $clean['needs'] = implode(',', $picked);
    $clean['needs_other'] = trim((string)($in['needs_other'] ?? ''));

    if (!$picked) {
        $err['needs'] = 'Zaznacz przynajmniej jeden rodzaj asysty lub potrzeby.';
    } elseif (in_array('other', $picked, true) && $clean['needs_other'] === '') {
        $err['needs_other'] = 'Opisz potrzebę zaznaczoną jako „Inne".';
    }

    $clean['details'] = trim((string)($in['details'] ?? ''));

    return [$clean, $err];
}

/**
 * Zapisuje nowe zgłoszenie ze statusem „Przyjęty".
 * $clean musi pochodzić z asr_validate (bez błędów). Zwraca ID nowego rekordu.
 */
function asr_create(array $clean, ?int $created_by = null, ?string $ip = null): int {
    asr_migrate();
    $id = db_insert('szo_assistance_requests', [
        'participant_name'  => $clean['participant_name'],
        'participant_email' => $clean['participant_email'],
        'participant_phone' => $clean['participant_phone'],
        'is_guardian'       => $clean['is_guardian'],
        'event_name'        => $clean['event_name'],
        'event_when_where'  => $clean['event_when_where'],
        'needs'             => $clean['needs'],
        'needs_other'       => $clean['needs_other'],
        'details'           => $clean['details'],
        'status'            => 'received',
        'created_by'        => $created_by,
        'created_ip'        => $ip,
    ]);

    // ───────────────────────────────────────────────────────────────────────
    // HOOK (backend): nowe zgłoszenie przyjęte.
    // Tutaj można podpiąć powiadomienie do koordynatora dostępności
    // (mail_queue_add) lub webhook do n8n / Power Automate, np.:
    //   asr_webhook_dispatch('created', asr_get($id));
    // ───────────────────────────────────────────────────────────────────────

    return $id;
}

/**
 * Aktualizuje zgłoszenie z poziomu administracji: status, przypisany
 * wolontariusz, kanały powiadomień, notatki wewnętrzne.
 * Zwraca tablicę zmian ['status_changed'=>bool, 'assignment_changed'=>bool].
 */
function asr_admin_update(int $id, array $in): array {
    asr_migrate();
    $cur = asr_get($id);
    if (!$cur) return ['status_changed' => false, 'assignment_changed' => false];

    $status = (string)($in['status'] ?? $cur['status']);
    if (!array_key_exists($status, asr_statuses())) $status = $cur['status'];

    $vol_id = (int)($in['assigned_volunteer_id'] ?? 0) ?: null;
    $vol_name = '';
    if ($vol_id) {
        foreach (asr_volunteers() as $v) {
            if ((int)$v['id'] === $vol_id) { $vol_name = (string)$v['imie_nazwisko']; break; }
        }
        if ($vol_name === '') $vol_id = null; // nieznany wolontariusz → wyczyść
    }

    $allowed_ch = array_keys(asr_channels());
    $channels = implode(',', array_values(array_intersect($allowed_ch, (array)($in['channels'] ?? []))));

    db_update('szo_assistance_requests', [
        'status'                => $status,
        'assigned_volunteer_id' => $vol_id,
        'assigned_name'         => $vol_name,
        'assigned_channels'     => $channels,
        'internal_notes'        => trim((string)($in['internal_notes'] ?? '')),
        'updated_at'            => date('Y-m-d H:i:s'),
    ], $id);

    $status_changed     = $status !== $cur['status'];
    $assignment_changed = (int)($cur['assigned_volunteer_id'] ?? 0) !== (int)($vol_id ?? 0);

    // ───────────────────────────────────────────────────────────────────────
    // HOOK (backend): zmiana statusu / przypisania.
    //   • Powiadomienie uczestnika o zmianie statusu (e-mail/SMS) — np. gdy
    //     status zmieni się na „confirmed"/„done"/„rejected*".
    //   • Webhook do n8n / Power Automate:
    //       if ($status_changed)     asr_webhook_dispatch('status', asr_get($id));
    //       if ($assignment_changed) asr_webhook_dispatch('assigned', asr_get($id));
    // Domyślnie poniżej wysyłamy powiadomienie do przypisanego wolontariusza
    // wybranymi kanałami (e-mail/SMS).
    // ───────────────────────────────────────────────────────────────────────
    if ($vol_id && $channels !== '') {
        asr_notify_volunteer((int)$id, explode(',', $channels));
    }

    // Powiadomienie uczestnika przy kluczowych zmianach statusu (e-mail + SMS).
    if ($status_changed && in_array($status, asr_participant_notify_statuses(), true)) {
        asr_notify_participant((int)$id);
    }

    return ['status_changed' => $status_changed, 'assignment_changed' => $assignment_changed];
}

/* ── Statusy wyzwalające powiadomienie do uczestnika ──────────────────────── */

/** Zwraca statusy, przy których uczestnik dostaje automatyczne powiadomienie. */
function asr_participant_notify_statuses(): array {
    return ['confirmed', 'rejected', 'rejected_external', 'resubmitted'];
}

/* ── Powiadomienie uczestnika przy zmianie statusu ─────────────────────────── */

/**
 * Wysyła uczestnikowi e-mail i SMS o zmianie statusu zgłoszenia.
 * Wołane automatycznie przez asr_admin_update gdy nowy status należy do
 * asr_participant_notify_statuses(). Ciche przy wyłączonych integracjach.
 */
function asr_notify_participant(int $id): void {
    $r = asr_get($id);
    if (!$r) return;

    $no     = asr_number($r);
    $org    = defined('ORG_NAME') ? ORG_NAME : 'Organizacja';
    $status = asr_label(asr_statuses(), $r['status']);

    $msgs = [
        'confirmed'         => 'Twoje zgłoszenie zostało potwierdzone. Wkrótce skontaktujemy się z Tobą w celu omówienia szczegółów.',
        'rejected'          => 'Niestety Twoje zgłoszenie zostało odrzucone. Skontaktujemy się z Tobą, aby wyjaśnić sytuację.',
        'rejected_external' => 'Twoje zgłoszenie zostało odrzucone z przyczyn zewnętrznych, niezależnych od nas. Skontaktujemy się z Tobą.',
        'resubmitted'       => 'Twoje zgłoszenie zostało zmienione i zarejestrowane jako nowe. Sprawdź kolejne potwierdzenie lub skontaktuj się z nami.',
    ];
    $body_text = $msgs[$r['status']] ?? ('Status Twojego zgłoszenia zmienił się na: ' . $status . '.');

    // ── E-mail ────────────────────────────────────────────────────────────────
    if (!empty($r['participant_email']) && function_exists('mail_queue_add')) {
        $subject = "{$org}: aktualizacja zgłoszenia asysty {$no}";
        $html = "<p>Drogi/Droga " . h($r['participant_name']) . ",</p>"
              . "<p>" . h($body_text) . "</p>"
              . "<p>Numer zgłoszenia: <strong>" . h($no) . "</strong><br>"
              . "Status: <strong>" . h($status) . "</strong></p>"
              . "<p style='color:#666;font-size:13px'>Wiadomość wygenerowana automatycznie przez SZO — " . h($org) . ".</p>";
        mail_queue_add(
            (string)$r['participant_email'], (string)$r['participant_name'],
            $subject, $html, '', 'assistance_request', $id
        );
    }

    // ── SMS ───────────────────────────────────────────────────────────────────
    if (!empty($r['participant_phone'])
        && function_exists('sms_send')
        && (!function_exists('sms_is_enabled') || sms_is_enabled())) {
        $sms = "{$org}: zgloszenie {$no} — {$status}. " . mb_substr(strip_tags($body_text), 0, 100);
        try { sms_send((string)$r['participant_phone'], $sms); } catch (\Throwable $e) { /* cicho */ }
    }
}

/* ── Powiadomienia wolontariusza realizującego ─────────────────────────────── */

/**
 * Wysyła do przypisanego wolontariusza powiadomienie o zgłoszeniu asysty
 * wybranymi kanałami: 'email' (przez kolejkę poczty) i/lub 'sms'.
 * Ciche przy braku adresata / wyłączonych integracjach.
 */
function asr_notify_volunteer(int $id, array $channels): void {
    $r = asr_get($id);
    if (!$r || empty($r['assigned_volunteer_id'])) return;

    // Dane kontaktowe wolontariusza ze snapshotu przypisania.
    $vol = null;
    foreach (asr_volunteers() as $v) {
        if ((int)$v['id'] === (int)$r['assigned_volunteer_id']) { $vol = $v; break; }
    }
    if (!$vol) return;

    $no    = asr_number($r);
    $org   = defined('ORG_NAME') ? ORG_NAME : 'Organizacja';
    $needs = implode(', ', asr_needs_labels($r['needs']));
    if (in_array('other', explode(',', (string)$r['needs']), true) && trim((string)$r['needs_other']) !== '') {
        $needs .= ' — ' . trim((string)$r['needs_other']);
    }
    $event = trim(($r['event_name'] ?? '') . ' · ' . ($r['event_when_where'] ?? ''), ' ·');

    // ── E-mail (kolejka poczty) ────────────────────────────────────────────
    if (in_array('email', $channels, true) && !empty($vol['email'])
        && function_exists('mail_queue_add')) {
        $subject = "Przydzielono Ci zgłoszenie asysty {$no}";
        $rows = [
            'Numer zgłoszenia' => $no,
            'Wydarzenie'       => $event !== '' ? $event : '—',
            'Zakres asysty'    => $needs !== '' ? $needs : '—',
            'Uczestnik'        => $r['participant_name'],
            'Kontakt'          => trim($r['participant_email'] . ' · ' . $r['participant_phone'], ' ·'),
        ];
        $html = "<p>Cześć " . h($vol['imie_nazwisko']) . ",</p>"
              . "<p>Przydzielono Ci zgłoszenie asysty do realizacji:</p><table>";
        foreach ($rows as $k => $v) {
            $html .= "<tr><td style='padding:2px 12px 2px 0'><strong>" . h($k) . ":</strong></td><td>" . h($v) . "</td></tr>";
        }
        $html .= "</table>";
        if (trim((string)$r['details']) !== '') {
            $html .= "<p><strong>Uwagi szczegółowe:</strong><br>" . nl2br(h($r['details'])) . "</p>";
        }
        $html .= "<p style='color:#666;font-size:13px'>Wiadomość wygenerowana automatycznie przez SZO — {$org}.</p>";

        mail_queue_add(
            (string)$vol['email'], (string)$vol['imie_nazwisko'],
            $subject, $html, '', 'assistance_request', $id
        );
    }

    // ── SMS ──────────────────────────────────────────────────────────────────
    if (in_array('sms', $channels, true) && !empty($vol['telefon'])
        && function_exists('sms_send') && (!function_exists('sms_is_enabled') || sms_is_enabled())) {
        $msg = "{$org}: przydzielono Ci zgloszenie asysty {$no}"
             . ($event !== '' ? " ({$event})" : '')
             . ". Szczegoly w panelu SZO.";
        try { sms_send((string)$vol['telefon'], $msg); } catch (\Throwable $e) { /* cicho */ }
    }
}
