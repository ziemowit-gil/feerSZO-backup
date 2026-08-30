<?php
/**
 * includes/rekrutacja.php — Moduł Rekrutacja (wolontariusze + pracownicy)
 *
 * Rdzeń modułu: schemat bazy (samonaprawa), cykl życia zgłoszenia (konfigurowalny
 * pipeline statusów + historia), bezpieczny magazyn dokumentów (CV / list
 * motywacyjny), tagi + autotagi, automatyzacje przy zmianie statusu, szablony
 * e-mail ze zmiennymi {{...}}, notatki rekruterów, harmonogram rozmów oraz
 * synchronizacja kandydata do CRM (autogrupy).
 *
 * Dostęp: administrator lub konto z users.rekrutacja_operator = 1.
 * Flaga modułu: rekrutacja_enabled.
 */

require_once __DIR__ . '/db.php';

// ── Stałe ─────────────────────────────────────────────────────────────────────

const REKR_TYPES = [
    'wolontariat' => ['label' => 'Wolontariat', 'class' => 'success', 'icon' => 'bi-heart-fill'],
    'etat'        => ['label' => 'Etat',        'class' => 'primary', 'icon' => 'bi-briefcase-fill'],
    'zajecia'     => ['label' => 'Zajęcia',     'class' => 'info',    'icon' => 'bi-calendar2-check'],
];

const REKR_FILE_KINDS = [
    'cv'   => 'CV',
    'list' => 'List motywacyjny',
    'inne' => 'Inny dokument',
];

// Limit rozmiaru pojedynczego pliku (w bajtach) i dozwolone typy.
const REKR_MAX_FILE_BYTES = 10 * 1024 * 1024; // 10 MB
const REKR_ALLOWED_MIMES = [
    'application/pdf' => 'pdf',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
];

// Domyślny pipeline — seed dla rekr_statuses (administrator może edytować).
const REKR_DEFAULT_STATUSES = [
    ['slug' => 'nowa',         'label' => 'Nowa aplikacja',      'color' => 'primary',   'is_terminal' => 0],
    ['slug' => 'selekcja',     'label' => 'Wstępna selekcja',    'color' => 'info',      'is_terminal' => 0],
    ['slug' => 'rozmowa',      'label' => 'Rozmowa zaplanowana', 'color' => 'warning',   'is_terminal' => 0],
    ['slug' => 'zaakceptowana','label' => 'Zaakceptowana',       'color' => 'success',   'is_terminal' => 1],
    ['slug' => 'odrzucona',    'label' => 'Odrzucona',           'color' => 'danger',    'is_terminal' => 1],
    ['slug' => 'rezerwa',      'label' => 'Baza rezerwowa',      'color' => 'secondary', 'is_terminal' => 1],
];

// Akcje automatyzacji (wyzwalane przy wejściu zgłoszenia w dany status).
const REKR_AUTOMATION_ACTIONS = [
    'send_template' => 'Wyślij e-mail z szablonu',
    'add_tag'       => 'Dodaj tag',
    'crm_group'     => 'Dodaj kontakt do grupy CRM',
];

// ── Migracja / samonaprawa schematu ──────────────────────────────────────────

function rekr_migrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo = db();

    // Stanowiska / ogłoszenia rekrutacyjne
    $pdo->exec("CREATE TABLE IF NOT EXISTS rekr_positions (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        name          TEXT    NOT NULL,
        type          TEXT    NOT NULL DEFAULT 'wolontariat',   -- wolontariat|etat|zajecia
        description   TEXT    NOT NULL DEFAULT '',
        auto_tags     TEXT    NOT NULL DEFAULT '',              -- CSV tagów nadawanych automatycznie
        crm_group_id  INTEGER REFERENCES crm_groups(id) ON DELETE SET NULL,
        is_active     INTEGER NOT NULL DEFAULT 1,
        created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // Konfigurowalny pipeline statusów
    $pdo->exec("CREATE TABLE IF NOT EXISTS rekr_statuses (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        slug        TEXT    NOT NULL UNIQUE,
        label       TEXT    NOT NULL,
        color       TEXT    NOT NULL DEFAULT 'secondary',       -- klasa koloru Bootstrap
        sort_order  INTEGER NOT NULL DEFAULT 0,
        is_terminal INTEGER NOT NULL DEFAULT 0
    )");

    // Zgłoszenia (kandydaci)
    $pdo->exec("CREATE TABLE IF NOT EXISTS rekr_applications (
        id             INTEGER PRIMARY KEY AUTOINCREMENT,
        type           TEXT    NOT NULL DEFAULT 'wolontariat',  -- wolontariat|etat|zajecia
        position_id    INTEGER REFERENCES rekr_positions(id) ON DELETE SET NULL,
        status         TEXT    NOT NULL DEFAULT 'nowa',
        imie           TEXT    NOT NULL,
        nazwisko       TEXT    NOT NULL,
        email          TEXT    NOT NULL,
        telefon        TEXT    NOT NULL DEFAULT '',
        message        TEXT    NOT NULL DEFAULT '',             -- wiadomość kandydata z formularza
        source         TEXT    NOT NULL DEFAULT 'formularz',    -- formularz|reczne
        consent_at     DATETIME,                                -- zgoda RODO na przetwarzanie w rekrutacji
        crm_contact_id INTEGER,                                 -- powiązany kontakt CRM (bez FK — CRM bywa wyłączony)
        created_by     INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at     DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_rekr_app_status  ON rekr_applications(status)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_rekr_app_type    ON rekr_applications(type)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_rekr_app_created ON rekr_applications(created_at)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_rekr_app_email   ON rekr_applications(email)");

    // Dokumenty aplikacyjne
    $pdo->exec("CREATE TABLE IF NOT EXISTS rekr_files (
        id             INTEGER PRIMARY KEY AUTOINCREMENT,
        application_id INTEGER NOT NULL REFERENCES rekr_applications(id) ON DELETE CASCADE,
        kind           TEXT    NOT NULL DEFAULT 'cv',           -- cv|list|inne
        original_name  TEXT    NOT NULL,
        stored_path    TEXT    NOT NULL,                        -- względem UPLOAD_DIR
        mime           TEXT    NOT NULL,
        size           INTEGER NOT NULL DEFAULT 0,
        extracted_text TEXT    NOT NULL DEFAULT '',             -- tekst do wyszukiwania słów kluczowych
        uploaded_by    INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at     DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_rekr_files_app ON rekr_files(application_id)");

    // Historia zmian statusów (kto, kiedy, skąd → dokąd)
    $pdo->exec("CREATE TABLE IF NOT EXISTS rekr_status_history (
        id             INTEGER PRIMARY KEY AUTOINCREMENT,
        application_id INTEGER NOT NULL REFERENCES rekr_applications(id) ON DELETE CASCADE,
        from_status    TEXT    NOT NULL DEFAULT '',
        to_status      TEXT    NOT NULL,
        note           TEXT    NOT NULL DEFAULT '',
        changed_by     INTEGER REFERENCES users(id) ON DELETE SET NULL,
        changed_name   TEXT    NOT NULL DEFAULT '',
        created_at     DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_rekr_hist_app ON rekr_status_history(application_id, created_at)");

    // Tagi zgłoszenia
    $pdo->exec("CREATE TABLE IF NOT EXISTS rekr_tags (
        id             INTEGER PRIMARY KEY AUTOINCREMENT,
        application_id INTEGER NOT NULL REFERENCES rekr_applications(id) ON DELETE CASCADE,
        tag            TEXT    NOT NULL,
        created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(application_id, tag)
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_rekr_tags_tag ON rekr_tags(tag)");

    // Reguły autotagów: słowo kluczowe w CV/wiadomości → tag
    $pdo->exec("CREATE TABLE IF NOT EXISTS rekr_autotag_rules (
        id      INTEGER PRIMARY KEY AUTOINCREMENT,
        keyword TEXT NOT NULL,
        tag     TEXT NOT NULL,
        UNIQUE(keyword, tag)
    )");

    // Notatki wewnętrzne rekruterów
    $pdo->exec("CREATE TABLE IF NOT EXISTS rekr_notes (
        id             INTEGER PRIMARY KEY AUTOINCREMENT,
        application_id INTEGER NOT NULL REFERENCES rekr_applications(id) ON DELETE CASCADE,
        user_id        INTEGER REFERENCES users(id) ON DELETE SET NULL,
        user_name      TEXT    NOT NULL DEFAULT '',
        body           TEXT    NOT NULL,
        created_at     DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_rekr_notes_app ON rekr_notes(application_id, created_at)");

    // Harmonogram rozmów kwalifikacyjnych
    $pdo->exec("CREATE TABLE IF NOT EXISTS rekr_interviews (
        id             INTEGER PRIMARY KEY AUTOINCREMENT,
        application_id INTEGER NOT NULL REFERENCES rekr_applications(id) ON DELETE CASCADE,
        starts_at      DATETIME NOT NULL,
        duration_min   INTEGER NOT NULL DEFAULT 30,
        location       TEXT    NOT NULL DEFAULT '',              -- adres lub link do spotkania online
        interviewer_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
        status         TEXT    NOT NULL DEFAULT 'zaplanowana',   -- zaplanowana|odbyta|odwolana
        note           TEXT    NOT NULL DEFAULT '',
        created_by     INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at     DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_rekr_int_app   ON rekr_interviews(application_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_rekr_int_start ON rekr_interviews(starts_at)");

    // Szablony e-mail (edytowalne z panelu — celowo NIE registry z email_templates.php,
    // bo tamto wymaga redeployu przy zmianie listy szablonów)
    $pdo->exec("CREATE TABLE IF NOT EXISTS rekr_email_templates (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        name       TEXT    NOT NULL,
        subject    TEXT    NOT NULL,
        body_html  TEXT    NOT NULL,
        enabled    INTEGER NOT NULL DEFAULT 1,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // Automatyzacje: status → akcja
    $pdo->exec("CREATE TABLE IF NOT EXISTS rekr_automations (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        status_slug TEXT    NOT NULL,
        action      TEXT    NOT NULL,                            -- send_template|add_tag|crm_group
        template_id INTEGER REFERENCES rekr_email_templates(id) ON DELETE CASCADE,
        value       TEXT    NOT NULL DEFAULT '',                 -- tag albo id grupy CRM
        enabled     INTEGER NOT NULL DEFAULT 1
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_rekr_auto_status ON rekr_automations(status_slug)");

    // Log wysłanych e-maili do kandydatów
    $pdo->exec("CREATE TABLE IF NOT EXISTS rekr_email_log (
        id             INTEGER PRIMARY KEY AUTOINCREMENT,
        application_id INTEGER NOT NULL REFERENCES rekr_applications(id) ON DELETE CASCADE,
        to_email       TEXT    NOT NULL,
        subject        TEXT    NOT NULL,
        body_html      TEXT    NOT NULL DEFAULT '',
        sent_by        INTEGER REFERENCES users(id) ON DELETE SET NULL,
        result         TEXT    NOT NULL DEFAULT '',
        created_at     DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_rekr_maillog_app ON rekr_email_log(application_id)");

    // Operator rekrutacji na koncie użytkownika (idempotentny ALTER — jak w helpdesku)
    try { $pdo->exec("ALTER TABLE users ADD COLUMN rekrutacja_operator INTEGER NOT NULL DEFAULT 0"); } catch (\Throwable $e) {}

    // Uproszczony nabór na zajęcia: limit miejsc per stanowisko/kurs,
    // pola dostępności kandydata (idempotentne ALTER — samonaprawa schematu)
    try { $pdo->exec("ALTER TABLE rekr_positions ADD COLUMN limit_miejsc INTEGER"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE rekr_applications ADD COLUMN rodzaj_niepelnosprawnosci TEXT NOT NULL DEFAULT ''"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE rekr_applications ADD COLUMN wymagane_dostosowania TEXT NOT NULL DEFAULT ''"); } catch (\Throwable $e) {}

    // Zapisy na wiele kursów/zajęć bez priorytetów (checkboxy) — jedno zgłoszenie,
    // wiele powiązanych stanowisk typu "zajecia"; status per-kurs odróżnia rezerwę
    // od zapisania, gdy limit_miejsc zostanie wyczerpany w chwili zgłoszenia.
    $pdo->exec("CREATE TABLE IF NOT EXISTS rekr_application_courses (
        id             INTEGER PRIMARY KEY AUTOINCREMENT,
        application_id INTEGER NOT NULL REFERENCES rekr_applications(id) ON DELETE CASCADE,
        position_id    INTEGER NOT NULL REFERENCES rekr_positions(id) ON DELETE CASCADE,
        status         TEXT    NOT NULL DEFAULT 'zapisany',  -- zapisany|rezerwa
        created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(application_id, position_id)
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_rekr_appcourse_pos ON rekr_application_courses(position_id)");

    // Samonaprawa crm_contacts: pola dostosowań potrzebne przez zapisy na zajęcia
    // (bez twardej zależności — gdy moduł CRM nie jest jeszcze zainstalowany, pomiń)
    if (db_one("SELECT name FROM sqlite_master WHERE type='table' AND name='crm_contacts'")) {
        try { $pdo->exec("ALTER TABLE crm_contacts ADD COLUMN rodzaj_niepelnosprawnosci TEXT"); } catch (\Throwable $e) {}
        try { $pdo->exec("ALTER TABLE crm_contacts ADD COLUMN wymagane_dostosowania TEXT"); } catch (\Throwable $e) {}
    }

    // Seed domyślnego pipeline'u statusów
    $cnt = (int)(db_one("SELECT COUNT(*) AS c FROM rekr_statuses")['c'] ?? 0);
    if ($cnt === 0) {
        $ins = $pdo->prepare("INSERT INTO rekr_statuses (slug, label, color, sort_order, is_terminal) VALUES (?,?,?,?,?)");
        foreach (REKR_DEFAULT_STATUSES as $i => $s) {
            $ins->execute([$s['slug'], $s['label'], $s['color'], ($i + 1) * 10, $s['is_terminal']]);
        }
    }

    // Seed przykładowych szablonów e-mail
    $cnt = (int)(db_one("SELECT COUNT(*) AS c FROM rekr_email_templates")['c'] ?? 0);
    if ($cnt === 0) {
        $ins = $pdo->prepare("INSERT INTO rekr_email_templates (name, subject, body_html) VALUES (?,?,?)");
        $ins->execute(['Potwierdzenie otrzymania aplikacji',
            'Dziękujemy za aplikację — {{stanowisko}}',
            '<p>Dzień dobry {{imie}},</p><p>dziękujemy za przesłanie aplikacji na <strong>{{stanowisko}}</strong>. Zapoznamy się z nią i wrócimy z informacją o kolejnych krokach.</p><p>Zespół rekrutacji</p>']);
        $ins->execute(['Zaproszenie na rozmowę',
            'Zaproszenie na rozmowę — {{stanowisko}}',
            '<p>Dzień dobry {{imie}},</p><p>zapraszamy na rozmowę w sprawie: <strong>{{stanowisko}}</strong>.</p><p>Termin: <strong>{{termin_spotkania}}</strong><br>Miejsce / link: {{link_do_spotkania}}</p><p>Zespół rekrutacji</p>']);
        $ins->execute(['Samodzielne umówienie rozmowy (TidyCal)',
            'Umów rozmowę — {{stanowisko}}',
            '<p>Dzień dobry {{imie}},</p><p>zapraszamy do samodzielnego wyboru dogodnego terminu rozmowy w sprawie: <strong>{{stanowisko}}</strong>.</p><p><a href="{{link_tidycal}}">Wybierz termin rozmowy →</a></p><p>Po rezerwacji otrzymasz potwierdzenie z linkiem do spotkania.</p><p>Zespół rekrutacji</p>']);
        $ins->execute(['Informacja o odrzuceniu',
            'Twoja aplikacja — {{stanowisko}}',
            '<p>Dzień dobry {{imie}},</p><p>dziękujemy za udział w rekrutacji na <strong>{{stanowisko}}</strong>. Tym razem zdecydowaliśmy się na innych kandydatów. Za zgodą pozostawimy Twoje zgłoszenie w bazie na przyszłość.</p><p>Zespół rekrutacji</p>']);
    }
}

// ── Uprawnienia ───────────────────────────────────────────────────────────────

function rekr_is_operator(): bool {
    if (is_admin()) return true;
    $u = current_user();
    return $u && !empty($u['rekrutacja_operator']);
}

function rekr_require_operator(): void {
    require_login();
    if (!rekr_is_operator()) {
        http_response_code(403);
        die('Brak dostępu do modułu naboru.');
    }
}

// ── Statusy ───────────────────────────────────────────────────────────────────

/** @return array<string,array> statusy pipeline'u indeksowane slugiem, w kolejności */
function rekr_statuses(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    foreach (db_all("SELECT * FROM rekr_statuses ORDER BY sort_order, id") as $s) {
        $cache[$s['slug']] = $s;
    }
    return $cache;
}

function rekr_status_label(string $slug): string {
    return rekr_statuses()[$slug]['label'] ?? $slug;
}

function rekr_status_badge(string $slug): string {
    $s = rekr_statuses()[$slug] ?? null;
    $color = $s['color'] ?? 'secondary';
    $label = $s['label'] ?? $slug;
    return '<span class="badge text-bg-' . h($color) . '">' . h($label) . '</span>';
}

/**
 * Zmiana statusu zgłoszenia: zapis + historia + automatyzacje.
 * Zwraca listę komunikatów o wykonanych automatyzacjach (do flash/UI).
 */
function rekr_set_status(int $app_id, string $to, string $note = ''): array {
    $app = db_one("SELECT * FROM rekr_applications WHERE id=?", [$app_id]);
    if (!$app || !isset(rekr_statuses()[$to])) return [];
    if ($app['status'] === $to) return [];

    $u = current_user();
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE rekr_applications SET status=?, updated_at=? WHERE id=?")
            ->execute([$to, date('Y-m-d H:i:s'), $app_id]);
        $pdo->prepare("INSERT INTO rekr_status_history (application_id, from_status, to_status, note, changed_by, changed_name)
                       VALUES (?,?,?,?,?,?)")
            ->execute([$app_id, $app['status'], $to, $note, $u['id'] ?? null, $u['name'] ?? 'system']);
        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    $app['status'] = $to;
    return rekr_run_automations($app, $to);
}

// ── Automatyzacje ─────────────────────────────────────────────────────────────

/** Wykonuje automatyzacje skonfigurowane dla statusu; zwraca opisy wykonanych akcji. */
function rekr_run_automations(array $app, string $status_slug): array {
    $done = [];
    $autos = db_all("SELECT * FROM rekr_automations WHERE status_slug=? AND enabled=1", [$status_slug]);
    foreach ($autos as $a) {
        try {
            switch ($a['action']) {
                case 'send_template':
                    $tpl = db_one("SELECT * FROM rekr_email_templates WHERE id=? AND enabled=1", [(int)$a['template_id']]);
                    if ($tpl && $app['email']) {
                        [$subj, $body] = rekr_tpl_render($tpl, $app);
                        if (rekr_send_email($app, $subj, $body, null, 'automatyzacja')) {
                            $done[] = 'Wysłano e-mail „' . $tpl['name'] . '" do ' . $app['email'];
                        }
                    }
                    break;
                case 'add_tag':
                    if ($a['value'] !== '' && rekr_add_tag((int)$app['id'], $a['value'])) {
                        $done[] = 'Dodano tag „' . $a['value'] . '"';
                    }
                    break;
                case 'crm_group':
                    if ($a['value'] !== '' && rekr_crm_group_add($app, (int)$a['value'])) {
                        $done[] = 'Dodano kontakt do grupy CRM #' . (int)$a['value'];
                    }
                    break;
            }
        } catch (\Throwable $e) {
            error_log('[rekrutacja] automatyzacja #' . $a['id'] . ' nie powiodła się: ' . $e->getMessage());
        }
    }
    return $done;
}

// ── Tagi + autotagi ───────────────────────────────────────────────────────────

/** Dodaje tag (idempotentnie). Zwraca true, jeśli tag był nowy. */
function rekr_add_tag(int $app_id, string $tag): bool {
    $tag = trim(mb_strtolower($tag));
    if ($tag === '' || mb_strlen($tag) > 60) return false;
    $stmt = db()->prepare("INSERT OR IGNORE INTO rekr_tags (application_id, tag) VALUES (?,?)");
    $stmt->execute([$app_id, $tag]);
    return $stmt->rowCount() > 0;
}

function rekr_remove_tag(int $app_id, string $tag): void {
    db_exec("DELETE FROM rekr_tags WHERE application_id=? AND tag=?", [$app_id, trim(mb_strtolower($tag))]);
}

/** @return string[] tagi zgłoszenia */
function rekr_tags(int $app_id): array {
    return array_column(db_all("SELECT tag FROM rekr_tags WHERE application_id=? ORDER BY tag", [$app_id]), 'tag');
}

/**
 * Autotagi: tagi ze stanowiska (auto_tags CSV) + reguły słów kluczowych
 * dopasowane do wiadomości kandydata i wyekstrahowanego tekstu CV.
 */
function rekr_apply_autotags(int $app_id): array {
    $app = db_one("SELECT * FROM rekr_applications WHERE id=?", [$app_id]);
    if (!$app) return [];
    $added = [];

    if ($app['position_id']) {
        $pos = db_one("SELECT auto_tags FROM rekr_positions WHERE id=?", [(int)$app['position_id']]);
        foreach (array_filter(array_map('trim', explode(',', $pos['auto_tags'] ?? ''))) as $t) {
            if (rekr_add_tag($app_id, $t)) $added[] = $t;
        }
    }

    $haystack = mb_strtolower($app['message'] . ' ' . implode(' ',
        array_column(db_all("SELECT extracted_text FROM rekr_files WHERE application_id=?", [$app_id]), 'extracted_text')));
    if (trim($haystack) !== '') {
        foreach (db_all("SELECT keyword, tag FROM rekr_autotag_rules") as $rule) {
            $kw = mb_strtolower(trim($rule['keyword']));
            if ($kw !== '' && mb_strpos($haystack, $kw) !== false && rekr_add_tag($app_id, $rule['tag'])) {
                $added[] = $rule['tag'];
            }
        }
    }
    return array_unique($added);
}

// ── Dokumenty aplikacyjne ─────────────────────────────────────────────────────

/**
 * Waliduje i zapisuje przesłany plik ($_FILES[...] pojedynczy).
 * Zwraca ['ok'=>true,'file_id'=>..] albo ['ok'=>false,'error'=>'komunikat'].
 */
function rekr_store_upload(array $file, int $app_id, string $kind, ?int $uploaded_by = null): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'error' => 'Nie wybrano pliku.'];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Błąd przesyłania pliku (kod ' . (int)$file['error'] . ').'];
    }
    if ((int)$file['size'] > REKR_MAX_FILE_BYTES) {
        return ['ok' => false, 'error' => 'Plik przekracza limit ' . (REKR_MAX_FILE_BYTES / 1024 / 1024) . ' MB.'];
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        return ['ok' => false, 'error' => 'Nieprawidłowy plik.'];
    }

    // Walidacja MIME po ZAWARTOŚCI (finfo), nie po rozszerzeniu ani nagłówku klienta
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']) ?: '';
    if (!isset(REKR_ALLOWED_MIMES[$mime])) {
        return ['ok' => false, 'error' => 'Dozwolone są wyłącznie pliki PDF i DOCX.'];
    }
    $ext = REKR_ALLOWED_MIMES[$mime];
    if (!isset(REKR_FILE_KINDS[$kind])) $kind = 'inne';

    $dir = UPLOAD_DIR . 'rekrutacja/' . $app_id;
    if (!is_dir($dir) && !mkdir($dir, 0750, true)) {
        return ['ok' => false, 'error' => 'Nie można utworzyć katalogu na pliki.'];
    }
    // Losowa nazwa docelowa — oryginalna trafia tylko do bazy (bez path traversal)
    $stored_rel = 'rekrutacja/' . $app_id . '/' . $kind . '_' . bin2hex(random_bytes(12)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], UPLOAD_DIR . $stored_rel)) {
        return ['ok' => false, 'error' => 'Nie udało się zapisać pliku.'];
    }

    $orig = mb_substr(preg_replace('/[\x00-\x1F\/\\\\]/u', '_', (string)$file['name']), 0, 200);
    $file_id = db_insert('rekr_files', [
        'application_id' => $app_id,
        'kind'           => $kind,
        'original_name'  => $orig,
        'stored_path'    => $stored_rel,
        'mime'           => $mime,
        'size'           => (int)$file['size'],
        'extracted_text' => rekr_extract_text(UPLOAD_DIR . $stored_rel, $mime),
        'uploaded_by'    => $uploaded_by,
    ]);
    return ['ok' => true, 'file_id' => $file_id];
}

/**
 * Ekstrakcja tekstu do wyszukiwania słów kluczowych (best-effort):
 * DOCX — ZipArchive + word/document.xml; PDF — `pdftotext`, jeśli dostępny.
 * Brak tekstu nie jest błędem (skan/pdf obrazkowy) — zwraca ''.
 */
function rekr_extract_text(string $path, string $mime): string {
    try {
        if ($mime === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' && class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($path) === true) {
                $xml = $zip->getFromName('word/document.xml') ?: '';
                $zip->close();
                // </w:p> → nowa linia, reszta znaczników precz
                $txt = strip_tags(preg_replace('/<\/w:p>/', "\n", $xml));
                return mb_substr(trim(html_entity_decode($txt, ENT_QUOTES | ENT_XML1)), 0, 200000);
            }
        }
        if ($mime === 'application/pdf') {
            $bin = trim((string)@shell_exec('command -v pdftotext 2>/dev/null'));
            if ($bin !== '') {
                $out = @shell_exec($bin . ' -q ' . escapeshellarg($path) . ' - 2>/dev/null');
                return mb_substr(trim((string)$out), 0, 200000);
            }
        }
    } catch (\Throwable $e) {
        error_log('[rekrutacja] ekstrakcja tekstu nieudana: ' . $e->getMessage());
    }
    return '';
}

// ── Przyjęcie zgłoszenia ──────────────────────────────────────────────────────

/**
 * Tworzy zgłoszenie (formularz publiczny lub ręcznie z panelu), zapisuje pliki,
 * nadaje autotagi, synchronizuje kontakt do CRM i odpala automatyzacje statusu
 * startowego. Zwraca ['ok'=>bool, 'id'=>int, 'errors'=>string[]].
 */
function rekr_application_create(array $data, array $files = [], string $source = 'formularz'): array {
    $errors = [];
    $imie     = trim((string)($data['imie'] ?? ''));
    $nazwisko = trim((string)($data['nazwisko'] ?? ''));
    $email    = trim(mb_strtolower((string)($data['email'] ?? '')));
    $telefon  = trim((string)($data['telefon'] ?? ''));
    $type     = isset(REKR_TYPES[$data['type'] ?? '']) ? $data['type'] : 'wolontariat';
    $message  = mb_substr(trim((string)($data['message'] ?? '')), 0, 10000);
    $position_id = (int)($data['position_id'] ?? 0) ?: null;

    if ($imie === '' || mb_strlen($imie) > 100)         $errors[] = 'Podaj imię.';
    if ($nazwisko === '' || mb_strlen($nazwisko) > 100) $errors[] = 'Podaj nazwisko.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))     $errors[] = 'Podaj poprawny adres e-mail.';
    if ($position_id && !db_one("SELECT id FROM rekr_positions WHERE id=? AND is_active=1", [$position_id])) {
        $errors[] = 'Wybrane stanowisko nie istnieje.';
    }
    if (empty($data['consent']) && $source === 'formularz') {
        $errors[] = 'Zgoda na przetwarzanie danych jest wymagana.';
    }
    if ($errors) return ['ok' => false, 'id' => 0, 'errors' => $errors];

    $first_slug = array_key_first(rekr_statuses()) ?: 'nowa';
    $u = current_user();
    $app_id = db_insert('rekr_applications', [
        'type'        => $type,
        'position_id' => $position_id,
        'status'      => $first_slug,
        'imie'        => $imie,
        'nazwisko'    => $nazwisko,
        'email'       => $email,
        'telefon'     => mb_substr($telefon, 0, 30),
        'message'     => $message,
        'source'      => $source,
        'consent_at'  => !empty($data['consent']) ? date('Y-m-d H:i:s') : null,
        'created_by'  => $u['id'] ?? null,
    ]);
    db_exec("INSERT INTO rekr_status_history (application_id, from_status, to_status, note, changed_by, changed_name)
             VALUES (?,?,?,?,?,?)",
        [$app_id, '', $first_slug, 'Zgłoszenie przyjęte (' . $source . ')', $u['id'] ?? null, $u['name'] ?? 'formularz']);

    // Pliki: klucze $files = kind (cv/list/inne); błędny plik nie wywraca zgłoszenia
    $file_errors = [];
    foreach ($files as $kind => $file) {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
        $res = rekr_store_upload($file, $app_id, (string)$kind, $u['id'] ?? null);
        if (!$res['ok']) $file_errors[] = (REKR_FILE_KINDS[$kind] ?? $kind) . ': ' . $res['error'];
    }

    rekr_apply_autotags($app_id);
    try { rekr_crm_sync(db_one("SELECT * FROM rekr_applications WHERE id=?", [$app_id])); }
    catch (\Throwable $e) { error_log('[rekrutacja] sync CRM nieudany: ' . $e->getMessage()); }

    $app = db_one("SELECT * FROM rekr_applications WHERE id=?", [$app_id]);
    rekr_run_automations($app, $first_slug);

    return ['ok' => true, 'id' => $app_id, 'errors' => $file_errors];
}

// ── Integracja z CRM (autogrupy) ─────────────────────────────────────────────

/**
 * Upsert kontaktu CRM po e-mailu + dodanie do grupy stanowiska / domyślnej grupy
 * typu rekrutacji. Tagi zgłoszenia lustrzanie trafiają na kontakt.
 * Bez twardej zależności: gdy CRM wyłączony, po prostu nic nie robi.
 */
function rekr_crm_sync(array $app): void {
    if (!module_enabled('crm_enabled')) return;
    if (!db_one("SELECT name FROM sqlite_master WHERE type='table' AND name='crm_contacts'")) return;

    $contact_id = (int)($app['crm_contact_id'] ?? 0);
    if (!$contact_id) {
        $existing = db_one("SELECT id FROM crm_contacts WHERE email=? AND type='osoba' ORDER BY id LIMIT 1", [$app['email']]);
        if ($existing) {
            $contact_id = (int)$existing['id'];
        } else {
            $contact_id = db_insert('crm_contacts', [
                'type'          => 'osoba',
                'status'        => 'prospect',
                'imie_nazwisko' => trim($app['imie'] . ' ' . $app['nazwisko']),
                'email'         => $app['email'],
                'telefon'       => $app['telefon'],
                'rodzaj_niepelnosprawnosci' => $app['rodzaj_niepelnosprawnosci'] ?? '',
                'wymagane_dostosowania'     => $app['wymagane_dostosowania'] ?? '',
                'source'        => 'rekrutacja',
                'notatka'       => 'Kandydat z modułu naboru (zgłoszenie #' . $app['id'] . ')',
            ]);
        }
        db_exec("UPDATE rekr_applications SET crm_contact_id=? WHERE id=?", [$contact_id, (int)$app['id']]);
    }

    // Kontakt już istniał (kolejne zgłoszenie) — dociągnij nowe dane bez nadpisywania
    // pustymi wartościami (np. telefon lub dostosowania podane tym razem dokładniej).
    if (($app['telefon'] ?? '') !== '' || ($app['rodzaj_niepelnosprawnosci'] ?? '') !== '' || ($app['wymagane_dostosowania'] ?? '') !== '') {
        db_exec("UPDATE crm_contacts SET
                    telefon = COALESCE(NULLIF(?, ''), telefon),
                    rodzaj_niepelnosprawnosci = COALESCE(NULLIF(?, ''), rodzaj_niepelnosprawnosci),
                    wymagane_dostosowania = COALESCE(NULLIF(?, ''), wymagane_dostosowania),
                    updated_at = ?
                 WHERE id=?",
            [$app['telefon'] ?? '', $app['rodzaj_niepelnosprawnosci'] ?? '', $app['wymagane_dostosowania'] ?? '', date('Y-m-d H:i:s'), $contact_id]);
    }

    // Autogrupa: grupa przypisana do stanowiska, a w braku — domyślna per typ
    $group_id = 0;
    if ($app['position_id']) {
        $pos = db_one("SELECT crm_group_id FROM rekr_positions WHERE id=?", [(int)$app['position_id']]);
        $group_id = (int)($pos['crm_group_id'] ?? 0);
    }
    if (!$group_id) {
        if ($app['type'] === 'etat')          $gname = 'Nabór — kandydaci (etat)';
        elseif ($app['type'] === 'zajecia')   $gname = 'Nabór — zapisy na zajęcia';
        else                                  $gname = 'Nabór — potencjalni wolontariusze';
        $g = db_one("SELECT id FROM crm_groups WHERE name=?", [$gname]);
        $group_id = $g ? (int)$g['id']
                       : db_insert('crm_groups', ['name' => $gname, 'description' => 'Grupa automatyczna modułu naboru', 'auto_source' => 'rekrutacja']);
    }
    db_exec("INSERT OR IGNORE INTO crm_group_members (group_id, contact_id) VALUES (?,?)", [$group_id, $contact_id]);

    foreach (rekr_tags((int)$app['id']) as $tag) {
        db_exec("INSERT OR IGNORE INTO crm_tags (contact_id, tag) VALUES (?,?)", [$contact_id, $tag]);
    }
}

/** Dodaje kontakt CRM zgłoszenia do wskazanej grupy (akcja automatyzacji). */
function rekr_crm_group_add(array $app, int $group_id): bool {
    if (!module_enabled('crm_enabled') || !$group_id) return false;
    rekr_crm_sync($app);
    $app = db_one("SELECT * FROM rekr_applications WHERE id=?", [(int)$app['id']]);
    if (empty($app['crm_contact_id'])) return false;
    db_exec("INSERT OR IGNORE INTO crm_group_members (group_id, contact_id) VALUES (?,?)",
        [$group_id, (int)$app['crm_contact_id']]);
    return true;
}

// ── Zapisy na zajęcia (checkboxy, bez priorytetów) ───────────────────────────

/** Liczba zajętych miejsc (status 'zapisany') na dane zajęcia, z aktywnych zgłoszeń. */
function rekr_course_seats_taken(int $position_id): int {
    return (int)(db_one(
        "SELECT COUNT(*) AS c FROM rekr_application_courses ac
         JOIN rekr_applications a ON a.id = ac.application_id
         WHERE ac.position_id=? AND ac.status='zapisany' AND a.status <> 'odrzucona'",
        [$position_id])['c'] ?? 0);
}

/**
 * Tworzy jedno zgłoszenie ("Uproszczony nabór na zajęcia") powiązane z wieloma
 * kursami naraz (bez priorytetów — checkboxy), w jednej transakcji PDO:
 * nagłówek zgłoszenia + powiązania z kursami (z automatycznym przydziałem do
 * rezerwy, gdy limit_miejsc danego kursu jest wyczerpany) + upsert kontaktu CRM
 * + wpis historii w crm_activities. Zwraca ['ok'=>bool,'id'=>int,'courses'=>array,'errors'=>string[]].
 */
function rekr_enrollment_create(array $data, array $position_ids, string $source = 'zapisy'): array {
    $errors = [];
    $imie     = trim((string)($data['imie'] ?? ''));
    $nazwisko = trim((string)($data['nazwisko'] ?? ''));
    $email    = trim(mb_strtolower((string)($data['email'] ?? '')));
    $telefon  = trim((string)($data['telefon'] ?? ''));
    $message  = mb_substr(trim((string)($data['message'] ?? '')), 0, 2000);
    $rodzaj_np = mb_substr(trim((string)($data['rodzaj_niepelnosprawnosci'] ?? '')), 0, 200);
    $dostosow  = mb_substr(trim((string)($data['wymagane_dostosowania'] ?? '')), 0, 2000);

    if ($imie === '' || mb_strlen($imie) > 100)         $errors[] = 'Podaj imię.';
    if ($nazwisko === '' || mb_strlen($nazwisko) > 100) $errors[] = 'Podaj nazwisko.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))     $errors[] = 'Podaj poprawny adres e-mail.';
    if (empty($data['consent']) && $source === 'zapisy') $errors[] = 'Zgoda na przetwarzanie danych jest wymagana.';

    $position_ids = array_values(array_unique(array_map('intval', $position_ids)));
    $courses = $position_ids ? db_all(
        "SELECT * FROM rekr_positions WHERE is_active=1 AND type='zajecia' AND id IN (" .
        implode(',', array_fill(0, count($position_ids), '?')) . ")", $position_ids) : [];
    if (!$courses) $errors[] = 'Wybierz co najmniej jedne zajęcia.';

    if ($errors) return ['ok' => false, 'id' => 0, 'courses' => [], 'errors' => $errors];

    $first_slug = array_key_first(rekr_statuses()) ?: 'nowa';
    $u = current_user();
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $app_id = db_insert('rekr_applications', [
            'type'        => 'zajecia',
            'position_id' => null,
            'status'      => $first_slug,
            'imie'        => $imie,
            'nazwisko'    => $nazwisko,
            'email'       => $email,
            'telefon'     => mb_substr($telefon, 0, 30),
            'message'     => $message,
            'rodzaj_niepelnosprawnosci' => $rodzaj_np,
            'wymagane_dostosowania'     => $dostosow,
            'source'      => $source,
            'consent_at'  => !empty($data['consent']) ? date('Y-m-d H:i:s') : null,
            'created_by'  => $u['id'] ?? null,
        ]);
        db_exec("INSERT INTO rekr_status_history (application_id, from_status, to_status, note, changed_by, changed_name)
                 VALUES (?,?,?,?,?,?)",
            [$app_id, '', $first_slug, 'Zapis na zajęcia przyjęty (' . $source . ')', $u['id'] ?? null, $u['name'] ?? 'formularz']);

        $course_statuses = [];
        foreach ($courses as $c) {
            $limit = $c['limit_miejsc'] !== null ? (int)$c['limit_miejsc'] : null;
            $taken = $limit !== null ? rekr_course_seats_taken((int)$c['id']) : 0;
            $cstatus = ($limit !== null && $taken >= $limit) ? 'rezerwa' : 'zapisany';
            db_insert('rekr_application_courses', [
                'application_id' => $app_id,
                'position_id'    => (int)$c['id'],
                'status'         => $cstatus,
            ]);
            $course_statuses[] = ['id' => (int)$c['id'], 'name' => $c['name'], 'status' => $cstatus];
        }

        $app = db_one("SELECT * FROM rekr_applications WHERE id=?", [$app_id]);
        rekr_crm_sync($app);

        $contact_id = (int)(db_one("SELECT crm_contact_id FROM rekr_applications WHERE id=?", [$app_id])['crm_contact_id'] ?? 0);
        if ($contact_id && db_one("SELECT name FROM sqlite_master WHERE type='table' AND name='crm_activities'")) {
            $lista = implode(', ', array_map(fn($c) => $c['name'] . ($c['status'] === 'rezerwa' ? ' (rezerwa)' : ''), $course_statuses));
            db_insert('crm_activities', [
                'contact_id'   => $contact_id,
                'type'         => 'zajecia_zapis',
                'title'        => 'Zapis na zajęcia',
                'description'  => 'Zgłoszenie #' . $app_id . ' — wybrane zajęcia: ' . $lista,
                'status'       => 'completed',
                'created_by'   => $u['id'] ?? null,
                'completed_at' => date('Y-m-d H:i:s'),
            ]);
        }

        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    rekr_apply_autotags($app_id);
    rekr_run_automations(db_one("SELECT * FROM rekr_applications WHERE id=?", [$app_id]), $first_slug);

    return ['ok' => true, 'id' => $app_id, 'courses' => $course_statuses, 'errors' => []];
}

// ── TidyCal: samodzielne umawianie rozmów ────────────────────────────────────

/**
 * Typ rezerwacji TidyCal wskazany dla rozmów rekrutacyjnych
 * (org_setting rekrutacja_tidycal_type_id) albo null, gdy TidyCal
 * nieskonfigurowany / typ niewybrany. Zwraca wpis z tidycal_types_cache()
 * (id, title, duration, public_url).
 */
function rekr_tidycal_type(): ?array {
    if (!function_exists('tidycal_enabled')) require_once __DIR__ . '/tidycal.php';
    if (!tidycal_enabled()) return null;
    $id = (int)org_setting('rekrutacja_tidycal_type_id');
    if (!$id) return null;
    foreach (tidycal_types_cache() as $t) {
        if ((int)$t['id'] === $id) return $t;
    }
    return null;
}

/** Publiczny link TidyCal do samodzielnego umówienia rozmowy ('' gdy brak). */
function rekr_tidycal_booking_url(): string {
    $t = rekr_tidycal_type();
    return (string)($t['public_url'] ?? '');
}

// ── Szablony e-mail ───────────────────────────────────────────────────────────

/** @return array<string,string> zmienne dynamiczne dla zgłoszenia */
function rekr_tpl_vars(array $app, array $extra = []): array {
    $pos = $app['position_id'] ? db_one("SELECT name FROM rekr_positions WHERE id=?", [(int)$app['position_id']]) : null;
    // Najbliższa zaplanowana rozmowa → {{termin_spotkania}} / {{link_do_spotkania}}
    $int = db_one("SELECT * FROM rekr_interviews WHERE application_id=? AND status='zaplanowana' AND starts_at >= ?
                   ORDER BY starts_at LIMIT 1", [(int)$app['id'], date('Y-m-d H:i:s')]);
    return array_merge([
        'imie'             => $app['imie'],
        'nazwisko'         => $app['nazwisko'],
        'email'            => $app['email'],
        'stanowisko'       => $pos['name'] ?? (REKR_TYPES[$app['type']]['label'] ?? $app['type']),
        'typ'              => REKR_TYPES[$app['type']]['label'] ?? $app['type'],
        'status'           => rekr_status_label($app['status']),
        'termin_spotkania' => $int ? date('d.m.Y H:i', strtotime($int['starts_at'])) : '',
        'link_do_spotkania'=> $int['location'] ?? '',
        'link_tidycal'     => rekr_tidycal_booking_url(),
        'organizacja'      => defined('ORG_NAME') ? ORG_NAME : '',
    ], $extra);
}

/** Podstawia {{zmienne}} w temacie i treści. Zwraca [subject, body_html]. */
function rekr_tpl_render(array $tpl, array $app, array $extra = []): array {
    $vars = rekr_tpl_vars($app, $extra);
    $sub = static function (string $s) use ($vars): string {
        return preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/i',
            fn($m) => $vars[mb_strtolower($m[1])] ?? $m[0], $s);
    };
    return [$sub($tpl['subject']), $sub($tpl['body_html'])];
}

/**
 * Wysyła e-mail do kandydata (łańcuch M365→SMTP→kolejka z approval_send_email)
 * i loguje w rekr_email_log. Treść HTML przechodzi przez sanityzację podstawową.
 */
function rekr_send_email(array $app, string $subject, string $body_html, ?int $sent_by = null, string $via_note = ''): bool {
    if (!function_exists('approval_send_email')) require_once __DIR__ . '/approval.php';
    $ok = approval_send_email($app['email'], $subject, $body_html, 'rekrutacja', (int)$app['id'], 20);
    db_exec("INSERT INTO rekr_email_log (application_id, to_email, subject, body_html, sent_by, result)
             VALUES (?,?,?,?,?,?)",
        [(int)$app['id'], $app['email'], $subject, $body_html, $sent_by,
         ($ok ? 'sent' : 'failed') . ($via_note ? ' (' . $via_note . ')' : '')]);
    return $ok;
}

/** Bardzo zachowawcza sanityzacja HTML edytora wiadomości (whitelist znaczników). */
function rekr_sanitize_html(string $html): string {
    $html = strip_tags($html, '<p><br><b><strong><i><em><u><ul><ol><li><a><h3><h4><blockquote>');
    // Zdejmij atrybuty poza href (i tylko http/https/mailto)
    $html = preg_replace_callback('/<a\s[^>]*href=["\']([^"\']*)["\'][^>]*>/i', function ($m) {
        $href = $m[1];
        if (!preg_match('#^(https?://|mailto:)#i', $href)) return '<a>';
        return '<a href="' . htmlspecialchars($href, ENT_QUOTES) . '" rel="noopener">';
    }, $html);
    // Pozostałe znaczniki: usuń wszelkie atrybuty (on*, style, itd.)
    $html = preg_replace('/<(?!a\s|a>|\/)(\w+)[^>]*>/i', '<$1>', $html);
    return $html;
}

// ── Drobne helpery widoków ────────────────────────────────────────────────────

/** @return array lista stanowisk (opcjonalnie filtrowana typem, np. 'zajecia') */
function rekr_positions(bool $only_active = true, ?string $type = null): array {
    $where = [];
    $params = [];
    if ($only_active) $where[] = 'is_active=1';
    if ($type !== null) { $where[] = 'type=?'; $params[] = $type; }
    $sql = "SELECT * FROM rekr_positions" . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . " ORDER BY type, name";
    return db_all($sql, $params);
}

function rekr_candidate_name(array $app): string {
    return trim($app['imie'] . ' ' . $app['nazwisko']);
}

/** Nadchodzące rozmowy (dla kalendarza / widżetu). */
function rekr_upcoming_interviews(int $days = 14): array {
    return db_all(
        "SELECT i.*, a.imie, a.nazwisko, a.type, a.id AS app_id, u.name AS interviewer_name
         FROM rekr_interviews i
         JOIN rekr_applications a ON a.id = i.application_id
         LEFT JOIN users u ON u.id = i.interviewer_id
         WHERE i.status='zaplanowana' AND i.starts_at BETWEEN ? AND ?
         ORDER BY i.starts_at",
        [date('Y-m-d 00:00:00'), date('Y-m-d 23:59:59', strtotime("+{$days} days"))]);
}
