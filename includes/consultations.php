<?php
/**
 * includes/consultations.php — Moduł „Karta konsultacyjna".
 *
 * Rejestr konsultacji udzielanych organizacjom (dostępność, sprawy formalne itp.).
 * Składa się z trzech warstw:
 *   • konsultacje/form.php   — publiczny formularz (BEZ logowania) + honeypot
 *   • konsultacje/admin.php  — tabela kart z filtrem dat + paczka ZIP
 *   • konsultacje/pdf.php    — oficjalny protokół do druku/PDF z miejscem na podpisy
 *
 * Tabela: szo_consultation_cards (auto-migracja, SQLite + MySQL).
 * Wszystkie zapytania to prepared statements (zob. includes/db.php).
 */

require_once __DIR__ . '/db.php';

/* ── Słowniki (klucz w bazie → etykieta dla człowieka) ─────────────────────── */

/** Obszary wsparcia (select w formularzu). */
function cc_areas(): array {
    return [
        'dostepnosc_cyfrowa'        => 'Dostępność cyfrowa',
        'dostepnosc_architektoniczna' => 'Dostępność architektoniczna',
        'formalne_prawne'           => 'Sprawy formalne / prawne',
        'inne'                      => 'Inne',
    ];
}

/** Forma konsultacji (radio w formularzu). */
function cc_forms(): array {
    return [
        'stacjonarnie' => 'Stacjonarnie',
        'online'       => 'Online',
        'telefonicznie'=> 'Telefonicznie',
        'mailowo'      => 'Mailowo',
    ];
}

/** Statusy karty. */
function cc_statuses(): array {
    return ['new' => 'Nowa', 'closed' => 'Zamknięta'];
}

/** Bezpieczna etykieta z mapy (z fallbackiem na surową wartość). */
function cc_label(array $map, ?string $key): string {
    if ($key === null || $key === '') return '—';
    return $map[$key] ?? $key;
}

/* ── Migracja schematu ─────────────────────────────────────────────────────── */

/**
 * Tworzy tabelę szo_consultation_cards, jeśli nie istnieje. Idempotentne.
 * Wołane na początku każdej strony modułu.
 */
function cc_migrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;

    $pdo = db();

    if (DB_TYPE === 'sqlite') {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS szo_consultation_cards (
                id                  INTEGER PRIMARY KEY AUTOINCREMENT,
                crm_org_id          INTEGER NULL REFERENCES crm_contacts(id) ON DELETE SET NULL,
                org_name            TEXT    NOT NULL,
                consultation_date   DATE    NOT NULL,
                area_type           TEXT    NOT NULL DEFAULT 'inne',
                form                TEXT    NOT NULL DEFAULT 'stacjonarnie',
                problem_description TEXT    NOT NULL DEFAULT '',
                actions_taken       TEXT    NOT NULL DEFAULT '',
                next_steps          TEXT    NOT NULL DEFAULT '',
                hours               REAL    NOT NULL DEFAULT 0,
                consultant          TEXT    NOT NULL DEFAULT '',
                status              TEXT    NOT NULL DEFAULT 'new',
                created_by          INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
                created_ip          TEXT    NULL,
                created_at          DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_cc_date   ON szo_consultation_cards(consultation_date)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_cc_status ON szo_consultation_cards(status)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_cc_org    ON szo_consultation_cards(crm_org_id)");
    } else {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS szo_consultation_cards (
                id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
                crm_org_id          INT UNSIGNED NULL,
                org_name            VARCHAR(255) NOT NULL,
                consultation_date   DATE NOT NULL,
                area_type           VARCHAR(64)  NOT NULL DEFAULT 'inne',
                form                VARCHAR(32)  NOT NULL DEFAULT 'stacjonarnie',
                problem_description TEXT NOT NULL,
                actions_taken       TEXT NOT NULL,
                next_steps          TEXT NOT NULL,
                hours               DECIMAL(6,2) NOT NULL DEFAULT 0,
                consultant          VARCHAR(255) NOT NULL DEFAULT '',
                status              VARCHAR(16)  NOT NULL DEFAULT 'new',
                created_by          INT UNSIGNED NULL,
                created_ip          VARCHAR(64)  NULL,
                created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_cc_date   (consultation_date),
                KEY idx_cc_status (status),
                KEY idx_cc_org    (crm_org_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
}

/* ── Operacje na danych ────────────────────────────────────────────────────── */

/** Pobiera pojedynczą kartę lub null. */
function cc_get(int $id): ?array {
    cc_migrate();
    return db_one("SELECT * FROM szo_consultation_cards WHERE id=?", [$id]);
}

/**
 * Zwraca [SQL-where, params] dla filtra zakresu dat.
 * $from/$to w formacie Y-m-d (puste = brak ograniczenia).
 */
function cc_date_where(string $from, string $to): array {
    $w = [];
    $p = [];
    if ($from !== '' && cc_valid_date($from)) { $w[] = 'consultation_date >= ?'; $p[] = $from; }
    if ($to   !== '' && cc_valid_date($to))   { $w[] = 'consultation_date <= ?'; $p[] = $to; }
    return [$w ? 'WHERE ' . implode(' AND ', $w) : '', $p];
}

/** Lista kart w zakresie dat, od najnowszych. */
function cc_list(string $from = '', string $to = ''): array {
    cc_migrate();
    [$where, $params] = cc_date_where($from, $to);
    return db_all(
        "SELECT * FROM szo_consultation_cards $where
         ORDER BY consultation_date DESC, id DESC",
        $params
    );
}

/** Walidacja daty Y-m-d. */
function cc_valid_date(string $d): bool {
    $t = \DateTime::createFromFormat('Y-m-d', $d);
    return $t && $t->format('Y-m-d') === $d;
}

/**
 * Próbuje dopasować nazwę organizacji do kontaktu CRM (po polu organizacja
 * lub imie_nazwisko). Zwraca crm_contacts.id albo null. Bezpieczne, gdy CRM
 * nie jest zainstalowany.
 */
function cc_match_crm_org(string $name): ?int {
    $name = trim($name);
    if ($name === '') return null;
    try {
        $r = db_one(
            "SELECT id FROM crm_contacts
             WHERE organizacja = ? COLLATE NOCASE OR imie_nazwisko = ? COLLATE NOCASE
             ORDER BY id LIMIT 1",
            [$name, $name]
        );
        return $r ? (int)$r['id'] : null;
    } catch (\Throwable $e) {
        // MySQL nie zna COLLATE NOCASE — spróbuj bez niego.
        try {
            $r = db_one(
                "SELECT id FROM crm_contacts WHERE organizacja = ? OR imie_nazwisko = ? ORDER BY id LIMIT 1",
                [$name, $name]
            );
            return $r ? (int)$r['id'] : null;
        } catch (\Throwable $e2) {
            return null;
        }
    }
}

/** Lista nazw organizacji z CRM do podpowiedzi (datalist). Pusta gdy brak CRM. */
function cc_org_suggestions(int $limit = 500): array {
    try {
        $rows = db_all(
            "SELECT DISTINCT organizacja AS n FROM crm_contacts
             WHERE organizacja IS NOT NULL AND organizacja <> ''
             UNION
             SELECT DISTINCT imie_nazwisko AS n FROM crm_contacts
             WHERE type='organizacja' AND imie_nazwisko IS NOT NULL AND imie_nazwisko <> ''
             ORDER BY n LIMIT $limit"
        );
        return array_values(array_filter(array_map(fn($r) => trim((string)$r['n']), $rows)));
    } catch (\Throwable $e) {
        return [];
    }
}

/**
 * Waliduje i normalizuje dane z formularza.
 * Zwraca [array $clean, array $errors]. Klucze $errors = nazwy pól.
 */
function cc_validate(array $in): array {
    $err = [];
    $clean = [];

    $clean['org_name'] = trim((string)($in['org_name'] ?? ''));
    if ($clean['org_name'] === '') {
        $err['org_name'] = 'Podaj nazwę organizacji.';
    } elseif (mb_strlen($clean['org_name']) > 255) {
        $err['org_name'] = 'Nazwa organizacji jest zbyt długa (max 255 znaków).';
    }

    $date = trim((string)($in['consultation_date'] ?? ''));
    if ($date === '' || !cc_valid_date($date)) {
        $err['consultation_date'] = 'Podaj poprawną datę konsultacji.';
    } else {
        $clean['consultation_date'] = $date;
    }

    $area = (string)($in['area_type'] ?? '');
    if (!array_key_exists($area, cc_areas())) {
        $err['area_type'] = 'Wybierz obszar wsparcia.';
    } else {
        $clean['area_type'] = $area;
    }

    $form = (string)($in['form'] ?? '');
    if (!array_key_exists($form, cc_forms())) {
        $err['form'] = 'Wybierz formę konsultacji.';
    } else {
        $clean['form'] = $form;
    }

    $clean['problem_description'] = trim((string)($in['problem_description'] ?? ''));
    if ($clean['problem_description'] === '') {
        $err['problem_description'] = 'Opisz problem lub zagadnienie.';
    }

    $clean['actions_taken'] = trim((string)($in['actions_taken'] ?? ''));
    $clean['next_steps']    = trim((string)($in['next_steps'] ?? ''));

    $hours = str_replace(',', '.', trim((string)($in['hours'] ?? '')));
    if ($hours === '' || !is_numeric($hours) || (float)$hours < 0 || (float)$hours > 999) {
        $err['hours'] = 'Podaj poprawną liczbę godzin (0–999).';
    } else {
        $clean['hours'] = round((float)$hours, 2);
    }

    $clean['consultant'] = trim((string)($in['consultant'] ?? ''));
    if ($clean['consultant'] === '') {
        $err['consultant'] = 'Podaj imię i nazwisko konsultanta (podpis).';
    } elseif (mb_strlen($clean['consultant']) > 255) {
        $err['consultant'] = 'Pole podpisu jest zbyt długie.';
    }

    return [$clean, $err];
}

/**
 * Zapisuje nową kartę. $clean musi pochodzić z cc_validate (bez błędów).
 * Zwraca ID nowego rekordu.
 */
function cc_create(array $clean, ?int $created_by = null, ?string $ip = null): int {
    cc_migrate();
    $data = [
        'crm_org_id'          => cc_match_crm_org($clean['org_name']),
        'org_name'            => $clean['org_name'],
        'consultation_date'   => $clean['consultation_date'],
        'area_type'           => $clean['area_type'],
        'form'                => $clean['form'],
        'problem_description' => $clean['problem_description'],
        'actions_taken'       => $clean['actions_taken'],
        'next_steps'          => $clean['next_steps'],
        'hours'               => $clean['hours'],
        'consultant'          => $clean['consultant'],
        'status'              => 'new',
        'created_by'          => $created_by,
        'created_ip'          => $ip,
    ];
    return db_insert('szo_consultation_cards', $data);
}

/** Format godzin „2.5 h" → „2,5 godz." */
function cc_hours_label(?float $h): string {
    $h = (float)$h;
    $s = rtrim(rtrim(number_format($h, 1, ',', ' '), '0'), ',');
    return $s . ' godz.';
}

/**
 * Renderuje kartę jako czysty protokół tekstowy (do paczki ZIP).
 */
function cc_to_text(array $c): string {
    $L  = fn($k, $v) => str_pad($k . ':', 26) . $v . "\n";
    $hr = str_repeat('=', 60) . "\n";
    $org = defined('ORG_NAME') ? ORG_NAME : 'Organizacja';

    $out  = $hr;
    $out .= "  KARTA KONSULTACYJNA  ·  nr {$c['id']}\n";
    $out .= "  {$org}\n";
    $out .= $hr . "\n";
    $out .= $L('Organizacja',        $c['org_name']);
    $out .= $L('Data konsultacji',   $c['consultation_date']);
    $out .= $L('Obszar wsparcia',    cc_label(cc_areas(), $c['area_type']));
    $out .= $L('Forma konsultacji',  cc_label(cc_forms(), $c['form']));
    $out .= $L('Liczba godzin',      cc_hours_label((float)$c['hours']));
    $out .= $L('Status',             cc_label(cc_statuses(), $c['status']));
    $out .= "\n" . str_repeat('-', 60) . "\n";
    $out .= "PROBLEM / ZAGADNIENIE\n" . str_repeat('-', 60) . "\n";
    $out .= ($c['problem_description'] !== '' ? $c['problem_description'] : '—') . "\n\n";
    $out .= str_repeat('-', 60) . "\n";
    $out .= "PODJĘTE CZYNNOŚCI\n" . str_repeat('-', 60) . "\n";
    $out .= ($c['actions_taken'] !== '' ? $c['actions_taken'] : '—') . "\n\n";
    $out .= str_repeat('-', 60) . "\n";
    $out .= "DALSZE KROKI\n" . str_repeat('-', 60) . "\n";
    $out .= ($c['next_steps'] !== '' ? $c['next_steps'] : '—') . "\n\n";
    $out .= $hr;
    $out .= "Konsultant: " . ($c['consultant'] !== '' ? $c['consultant'] : '—') . "\n";
    $out .= "Utworzono:  " . ($c['created_at'] ?? '') . "\n";
    $out .= $hr;
    return $out;
}

/** Bezpieczna nazwa pliku dla pozycji w archiwum: RRRR-MM-DD_Nazwa_ID. */
function cc_filename_base(array $c): string {
    $name = $c['org_name'] ?? 'organizacja';
    // Transliteracja PL → ASCII gdzie się da, reszta na podkreślenie.
    $map = ['ą'=>'a','ć'=>'c','ę'=>'e','ł'=>'l','ń'=>'n','ó'=>'o','ś'=>'s','ż'=>'z','ź'=>'z',
            'Ą'=>'A','Ć'=>'C','Ę'=>'E','Ł'=>'L','Ń'=>'N','Ó'=>'O','Ś'=>'S','Ż'=>'Z','Ź'=>'Z'];
    $name = strtr($name, $map);
    $name = preg_replace('/[^A-Za-z0-9]+/', '_', $name);
    $name = trim((string)$name, '_');
    if ($name === '') $name = 'organizacja';
    $name = mb_substr($name, 0, 60);
    return sprintf('%s_%s_%d', $c['consultation_date'] ?? '0000-00-00', $name, (int)$c['id']);
}
