<?php
/**
 * includes/ti_syllabus.php — Sylabusy przedmiotów TI.
 *
 * Sylabus = program przedmiotu w wersji: zestaw punktów programu, wymagań
 * i kryteriów oceniania, przypisany do rodzaju zajęć (k30_ti_subject_types)
 * i do konkretnych kursów.
 *
 * Podział ról względem istniejącego „Planu nauczania” (k30_ti_curriculum):
 *   sylabus         = WZORZEC przedmiotu (wersjonowany, wspólny dla kursów),
 *   plan nauczania  = REALIZACJA sylabusa w konkretnym kursie (pozycje planu
 *                     wskazują punkt sylabusa kolumną syllabus_item_id).
 * Dlatego program nie jest tu duplikowany: sylabus kopiuje się do planu kursu
 * (ti_syllabus_copy_to_curriculum), a plan dalej łączy się z lekcjami tak,
 * jak dotąd (k30_ti_session_curriculum).
 *
 * Osobne od k30_ti_tests (testy) i k30_ti_grades (oceny) — sylabus opisuje
 * WYMAGANIA i KRYTERIA, nie przechowuje wyników.
 */

if (!defined('TI_SYLLABUS_KINDS')) {
    define('TI_SYLLABUS_KINDS', [
        'program'     => ['label' => 'Program przedmiotu', 'one' => 'punkt programu',      'icon' => 'bi-list-ol',       'time' => true],
        'requirement' => ['label' => 'Wymagania',          'one' => 'wymaganie',            'icon' => 'bi-check2-square', 'time' => false],
        'criterion'   => ['label' => 'Kryteria oceniania', 'one' => 'kryterium oceniania',  'icon' => 'bi-award',         'time' => false],
    ]);
}

if (!defined('TI_SYLLABUS_STATUSES')) {
    define('TI_SYLLABUS_STATUSES', [
        'draft'    => ['label' => 'Projekt',       'badge' => 'secondary'],
        'active'   => ['label' => 'Obowiązujący',  'badge' => 'success'],
        'archived' => ['label' => 'Archiwalny',    'badge' => 'dark'],
    ]);
}

/**
 * Samonaprawa schematu — wołana z każdego punktu wejścia modułu.
 * Wzorzec jak ti_periods_migrate(): CREATE TABLE IF NOT EXISTS + bezpieczne ALTER.
 */
function ti_syllabus_migrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS k30_ti_syllabi (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            subject_type_id INTEGER REFERENCES k30_ti_subject_types(id) ON DELETE SET NULL,
            title           TEXT    NOT NULL DEFAULT '',
            version         TEXT    NOT NULL DEFAULT '1',
            period_id       INTEGER REFERENCES k30_ti_periods(id) ON DELETE SET NULL,
            status          TEXT    NOT NULL DEFAULT 'draft',
            note            TEXT    NOT NULL DEFAULT '',
            created_by      INTEGER,
            created_at      TEXT    NOT NULL DEFAULT (datetime('now')),
            updated_at      TEXT    NOT NULL DEFAULT (datetime('now'))
        )");
        db()->exec("CREATE INDEX IF NOT EXISTS idx_ti_syl_subject ON k30_ti_syllabi(subject_type_id, status)");

        db()->exec("CREATE TABLE IF NOT EXISTS k30_ti_syllabus_items (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            syllabus_id INTEGER NOT NULL REFERENCES k30_ti_syllabi(id) ON DELETE CASCADE,
            kind        TEXT    NOT NULL DEFAULT 'program',   -- program | requirement | criterion
            section     TEXT    NOT NULL DEFAULT '',
            position    INTEGER NOT NULL DEFAULT 0,
            title       TEXT    NOT NULL DEFAULT '',
            description TEXT    NOT NULL DEFAULT '',
            est_minutes INTEGER NOT NULL DEFAULT 0,
            is_active   INTEGER NOT NULL DEFAULT 1,
            created_by  INTEGER,
            created_at  TEXT    NOT NULL DEFAULT (datetime('now')),
            updated_at  TEXT    NOT NULL DEFAULT (datetime('now'))
        )");
        db()->exec("CREATE INDEX IF NOT EXISTS idx_ti_syl_items ON k30_ti_syllabus_items(syllabus_id, kind, position)");
    } catch (\Throwable $e) {}

    // Powiązania z istniejącymi tabelami (kolumna może już istnieć)
    foreach ([
        "ALTER TABLE k30_ti_courses    ADD COLUMN syllabus_id      INTEGER",
        "ALTER TABLE k30_ti_curriculum ADD COLUMN syllabus_item_id INTEGER",
    ] as $sql) {
        try { db()->exec($sql); } catch (\Throwable $e) {}
    }
}

/** Etykieta rodzaju pozycji sylabusa. */
function ti_syllabus_kind_label(string $kind): string {
    return TI_SYLLABUS_KINDS[$kind]['label'] ?? $kind;
}

/** Etykieta statusu sylabusa. */
function ti_syllabus_status_label(string $status): string {
    return TI_SYLLABUS_STATUSES[$status]['label'] ?? $status;
}

// ── Sylabusy ─────────────────────────────────────────────────────────────────

/**
 * Lista sylabusów z nazwą przedmiotu, okresem i licznikami pozycji.
 *
 * @param int    $subject_type_id  0 = wszystkie przedmioty
 * @param string $status           '' = wszystkie statusy
 */
function ti_syllabi_list(int $subject_type_id = 0, string $status = ''): array {
    ti_syllabus_migrate();
    $sql = "SELECT s.*, st.abbreviation AS subject_abbr, st.name AS subject_name,
                   p.name AS period_name,
                   (SELECT COUNT(*) FROM k30_ti_syllabus_items i WHERE i.syllabus_id=s.id AND i.kind='program')     AS n_program,
                   (SELECT COUNT(*) FROM k30_ti_syllabus_items i WHERE i.syllabus_id=s.id AND i.kind='requirement') AS n_requirement,
                   (SELECT COUNT(*) FROM k30_ti_syllabus_items i WHERE i.syllabus_id=s.id AND i.kind='criterion')   AS n_criterion,
                   (SELECT COUNT(*) FROM k30_ti_courses c WHERE c.syllabus_id=s.id)                                 AS n_courses
              FROM k30_ti_syllabi s
              LEFT JOIN k30_ti_subject_types st ON st.id = s.subject_type_id
              LEFT JOIN k30_ti_periods       p  ON p.id  = s.period_id
             WHERE 1=1";
    $params = [];
    if ($subject_type_id) { $sql .= " AND s.subject_type_id=?"; $params[] = $subject_type_id; }
    if ($status !== '')   { $sql .= " AND s.status=?";          $params[] = $status; }
    $sql .= " ORDER BY st.abbreviation COLLATE NOCASE, s.status='archived', s.version DESC, s.id DESC";
    return db_all($sql, $params);
}

/** Sylabus z nazwą przedmiotu i okresu. */
function ti_syllabus_get(int $id): ?array {
    ti_syllabus_migrate();
    if (!$id) return null;
    return db_one(
        "SELECT s.*, st.abbreviation AS subject_abbr, st.name AS subject_name, p.name AS period_name
           FROM k30_ti_syllabi s
           LEFT JOIN k30_ti_subject_types st ON st.id = s.subject_type_id
           LEFT JOIN k30_ti_periods       p  ON p.id  = s.period_id
          WHERE s.id=?",
        [$id]
    );
}

/** Zapis sylabusa (bez pozycji). Zwraca id. */
function ti_syllabus_save(array $data, ?int $id = null, ?int $by = null): int {
    ti_syllabus_migrate();
    $fields = [
        'subject_type_id' => ((int)($data['subject_type_id'] ?? 0)) ?: null,
        'title'           => trim((string)($data['title'] ?? '')),
        'version'         => trim((string)($data['version'] ?? '')) ?: '1',
        'period_id'       => ((int)($data['period_id'] ?? 0)) ?: null,
        'status'          => isset(TI_SYLLABUS_STATUSES[$data['status'] ?? '']) ? (string)$data['status'] : 'draft',
        'note'            => trim((string)($data['note'] ?? '')),
    ];
    if ($id) {
        db_update('k30_ti_syllabi', $fields, $id);   // updated_at dokłada db_update()
        return $id;
    }
    $fields['created_by'] = $by;
    return db_insert('k30_ti_syllabi', $fields);
}

/** Usuwa sylabus wraz z pozycjami; kursy tracą powiązanie (nie tracą planu). */
function ti_syllabus_delete(int $id): void {
    ti_syllabus_migrate();
    if (!$id) return;
    db_exec("UPDATE k30_ti_courses SET syllabus_id=NULL WHERE syllabus_id=?", [$id]);
    db_exec("DELETE FROM k30_ti_syllabus_items WHERE syllabus_id=?", [$id]);
    db_exec("DELETE FROM k30_ti_syllabi WHERE id=?", [$id]);
}

/**
 * Nowa wersja sylabusa: kopia pozycji, status „Projekt”, wersja +1
 * (albo z sufiksem, gdy wersja nie jest liczbą). Zwraca id nowego sylabusa.
 */
function ti_syllabus_clone(int $id, ?int $by = null): int {
    ti_syllabus_migrate();
    $src = ti_syllabus_get($id);
    if (!$src) throw new \RuntimeException('Sylabus nie istnieje.');

    $v = trim((string)$src['version']);
    $new_version = ctype_digit($v) ? (string)((int)$v + 1) : ($v . ' (kopia)');

    $new_id = ti_syllabus_save([
        'subject_type_id' => (int)($src['subject_type_id'] ?? 0),
        'title'           => (string)$src['title'],
        'version'         => $new_version,
        'period_id'       => (int)($src['period_id'] ?? 0),
        'status'          => 'draft',
        'note'            => (string)$src['note'],
    ], null, $by);

    foreach (db_all("SELECT * FROM k30_ti_syllabus_items WHERE syllabus_id=? ORDER BY kind, position, id", [$id]) as $it) {
        db_insert('k30_ti_syllabus_items', [
            'syllabus_id' => $new_id,
            'kind'        => (string)$it['kind'],
            'section'     => (string)$it['section'],
            'position'    => (int)$it['position'],
            'title'       => (string)$it['title'],
            'description' => (string)$it['description'],
            'est_minutes' => (int)$it['est_minutes'],
            'is_active'   => (int)$it['is_active'],
            'created_by'  => $by,
        ]);
    }
    return $new_id;
}

// ── Pozycje sylabusa ─────────────────────────────────────────────────────────

/** Pozycje sylabusa danego rodzaju (null = wszystkie), w kolejności prezentacji. */
function ti_syllabus_items(int $syllabus_id, ?string $kind = null, bool $only_active = false): array {
    ti_syllabus_migrate();
    $sql = "SELECT * FROM k30_ti_syllabus_items WHERE syllabus_id=?";
    $params = [$syllabus_id];
    if ($kind !== null && $kind !== '') { $sql .= " AND kind=?"; $params[] = $kind; }
    if ($only_active)                   { $sql .= " AND is_active=1"; }
    $sql .= " ORDER BY kind, section COLLATE NOCASE, position, id";
    return db_all($sql, $params);
}

function ti_syllabus_item_get(int $id): ?array {
    ti_syllabus_migrate();
    return $id ? db_one("SELECT * FROM k30_ti_syllabus_items WHERE id=?", [$id]) : null;
}

/** Następna pozycja w obrębie sylabusa i rodzaju. */
function ti_syllabus_item_next_position(int $syllabus_id, string $kind): int {
    $r = db_one(
        "SELECT COALESCE(MAX(position),0)+1 AS p FROM k30_ti_syllabus_items WHERE syllabus_id=? AND kind=?",
        [$syllabus_id, $kind]
    );
    return (int)($r['p'] ?? 1);
}

/** Zapis pozycji sylabusa. Zwraca id. */
function ti_syllabus_item_save(array $data, ?int $id = null, ?int $by = null): int {
    ti_syllabus_migrate();
    $kind = isset(TI_SYLLABUS_KINDS[$data['kind'] ?? '']) ? (string)$data['kind'] : 'program';
    $fields = [
        'kind'        => $kind,
        'section'     => trim((string)($data['section'] ?? '')),
        'title'       => trim((string)($data['title'] ?? '')),
        'description' => trim((string)($data['description'] ?? '')),
        // Czas ma sens tylko dla programu — wymagania i kryteria go nie mają
        'est_minutes' => TI_SYLLABUS_KINDS[$kind]['time'] ? max(0, (int)($data['est_minutes'] ?? 0)) : 0,
        'is_active'   => !empty($data['is_active']) ? 1 : 0,
    ];
    if ($id) {
        db_update('k30_ti_syllabus_items', $fields, $id);   // updated_at dokłada db_update()
        return $id;
    }
    $fields['syllabus_id'] = (int)$data['syllabus_id'];
    $fields['position']    = ti_syllabus_item_next_position((int)$data['syllabus_id'], $kind);
    $fields['created_by']  = $by;
    return db_insert('k30_ti_syllabus_items', $fields);
}

function ti_syllabus_item_delete(int $id): void {
    ti_syllabus_migrate();
    if (!$id) return;
    // Pozycje planu kursów przestają wskazywać usunięty punkt, ale zostają
    db_exec("UPDATE k30_ti_curriculum SET syllabus_item_id=NULL WHERE syllabus_item_id=?", [$id]);
    db_exec("DELETE FROM k30_ti_syllabus_items WHERE id=?", [$id]);
}

/** Przesuwa pozycję w obrębie rodzaju o jedno miejsce w górę/w dół. */
function ti_syllabus_item_move(int $id, string $dir): void {
    ti_syllabus_migrate();
    $it = ti_syllabus_item_get($id);
    if (!$it) return;
    $items = ti_syllabus_items((int)$it['syllabus_id'], (string)$it['kind']);
    $ids   = array_map(fn($r) => (int)$r['id'], $items);
    $pos   = array_search($id, $ids, true);
    if ($pos === false) return;
    $swap = $dir === 'up' ? $pos - 1 : $pos + 1;
    if ($swap < 0 || $swap >= count($ids)) return;
    [$ids[$pos], $ids[$swap]] = [$ids[$swap], $ids[$pos]];

    $stmt = db()->prepare("UPDATE k30_ti_syllabus_items SET position=?, updated_at=datetime('now') WHERE id=? AND syllabus_id=?");
    $p = 1;
    foreach ($ids as $iid) $stmt->execute([$p++, $iid, (int)$it['syllabus_id']]);
}

// ── Powiązanie z kursami i planem nauczania ──────────────────────────────────

/**
 * Sylabus kursu: przypisany wprost (k30_ti_courses.syllabus_id), a gdy brak —
 * obowiązujący sylabus rodzaju zajęć kursu (najnowsza wersja).
 * Klucz 'inherited' mówi, czy powiązanie jest dziedziczone po przedmiocie.
 */
function ti_course_syllabus(int $course_id): ?array {
    ti_syllabus_migrate();
    if (!$course_id) return null;
    try {
        $c = db_one("SELECT syllabus_id, subject_type_id FROM k30_ti_courses WHERE id=?", [$course_id]);
    } catch (\Throwable $e) { return null; }
    if (!$c) return null;

    if (!empty($c['syllabus_id'])) {
        $s = ti_syllabus_get((int)$c['syllabus_id']);
        if ($s) { $s['inherited'] = false; return $s; }
    }
    if (empty($c['subject_type_id'])) return null;
    $row = db_one(
        "SELECT id FROM k30_ti_syllabi WHERE subject_type_id=? AND status='active' ORDER BY version DESC, id DESC LIMIT 1",
        [(int)$c['subject_type_id']]
    );
    if (!$row) return null;
    $s = ti_syllabus_get((int)$row['id']);
    if ($s) $s['inherited'] = true;
    return $s;
}

/** Kursy przypisane do sylabusa wprost. */
function ti_syllabus_courses(int $syllabus_id): array {
    ti_syllabus_migrate();
    return db_all(
        "SELECT id, name, status FROM k30_ti_courses WHERE syllabus_id=? ORDER BY name COLLATE NOCASE",
        [$syllabus_id]
    );
}

/** Przypisuje/odpina sylabus kursowi ($syllabus_id = 0 → odpięcie). */
function ti_course_set_syllabus(int $course_id, int $syllabus_id): void {
    ti_syllabus_migrate();
    if (!$course_id) return;
    db_exec("UPDATE k30_ti_courses SET syllabus_id=? WHERE id=?", [$syllabus_id ?: null, $course_id]);
}

/**
 * Kopiuje aktywne punkty programu sylabusa do planu nauczania kursu.
 * Idempotentne: punkt już przeniesiony do planu (syllabus_item_id) jest pomijany,
 * więc kolejne uruchomienie dokłada tylko nowości z sylabusa.
 *
 * @return array{added:int, skipped:int}
 */
function ti_syllabus_copy_to_curriculum(int $syllabus_id, int $course_id, ?int $by = null): array {
    ti_syllabus_migrate();
    $added = 0; $skipped = 0;
    if (!$syllabus_id || !$course_id) return ['added' => 0, 'skipped' => 0];

    $existing = [];
    foreach (db_all("SELECT syllabus_item_id FROM k30_ti_curriculum WHERE course_id=? AND syllabus_item_id IS NOT NULL", [$course_id]) as $r) {
        $existing[(int)$r['syllabus_item_id']] = true;
    }
    foreach (ti_syllabus_items($syllabus_id, 'program', true) as $it) {
        if (isset($existing[(int)$it['id']])) { $skipped++; continue; }
        $cid = k30_ti_curriculum_save([
            'course_id'   => $course_id,
            'section'     => (string)$it['section'],
            'title'       => (string)$it['title'],
            'description' => (string)$it['description'],
            'est_minutes' => (int)$it['est_minutes'],
            'is_active'   => 1,
        ], null, $by);
        db_exec("UPDATE k30_ti_curriculum SET syllabus_item_id=? WHERE id=?", [(int)$it['id'], $cid]);
        $added++;
    }
    return ['added' => $added, 'skipped' => $skipped];
}

/**
 * Pokrycie sylabusa planem kursu: ile aktywnych punktów programu sylabusa
 * ma odpowiednik w planie nauczania kursu.
 *
 * @return array{total:int, covered:int, pct:int}
 */
function ti_syllabus_coverage(int $syllabus_id, int $course_id): array {
    ti_syllabus_migrate();
    $total = count(ti_syllabus_items($syllabus_id, 'program', true));
    if (!$total || !$course_id) return ['total' => $total, 'covered' => 0, 'pct' => 0];
    $covered = (int)(db_one(
        "SELECT COUNT(DISTINCT c.syllabus_item_id) AS n
           FROM k30_ti_curriculum c
           JOIN k30_ti_syllabus_items i ON i.id = c.syllabus_item_id
          WHERE c.course_id=? AND i.syllabus_id=? AND i.kind='program' AND i.is_active=1",
        [$course_id, $syllabus_id]
    )['n'] ?? 0);
    return ['total' => $total, 'covered' => $covered, 'pct' => (int)round($covered * 100 / $total)];
}

// ── Wydruk ───────────────────────────────────────────────────────────────────

/** Treść sylabusa jako HTML do wydruku (używane też jako podgląd). */
function ti_syllabus_print_html(array $syl): string {
    $items = ti_syllabus_items((int)$syl['id'], null, true);
    $by_kind = ['program' => [], 'requirement' => [], 'criterion' => []];
    foreach ($items as $it) {
        $k = (string)$it['kind'];
        if (isset($by_kind[$k])) $by_kind[$k][] = $it;
    }
    $org  = org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : '');
    $subj = trim((string)($syl['subject_abbr'] ?? '') . ' — ' . (string)($syl['subject_name'] ?? ''), ' —');

    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $out = '<h1>Sylabus przedmiotu</h1>';
    $out .= '<p class="meta">' . $h($org) . '</p>';
    $out .= '<table class="head"><tbody>';
    $out .= '<tr><th>Przedmiot</th><td>' . $h($subj !== '' ? $subj : '—') . '</td></tr>';
    $out .= '<tr><th>Nazwa sylabusa</th><td>' . $h($syl['title'] !== '' ? $syl['title'] : '—') . '</td></tr>';
    $out .= '<tr><th>Wersja</th><td>' . $h($syl['version']) . ' — ' . $h(ti_syllabus_status_label((string)$syl['status'])) . '</td></tr>';
    if (!empty($syl['period_name'])) {
        $out .= '<tr><th>Okres nauczania</th><td>' . $h($syl['period_name']) . '</td></tr>';
    }
    $out .= '<tr><th>Stan na dzień</th><td>' . date('d.m.Y') . '</td></tr>';
    $out .= '</tbody></table>';

    if (trim((string)$syl['note']) !== '') {
        $out .= '<p class="note">' . nl2br($h($syl['note'])) . '</p>';
    }

    // Program — grupowany po działach, z sumą czasu
    $out .= '<h2>1. Program przedmiotu</h2>';
    if (!$by_kind['program']) {
        $out .= '<p class="empty">Nie wprowadzono punktów programu.</p>';
    } else {
        $total_min = 0;
        $last = null;
        $out .= '<table class="items"><thead><tr><th style="width:6%">#</th><th>Temat</th><th style="width:16%">Czas</th></tr></thead><tbody>';
        $n = 0;
        foreach ($by_kind['program'] as $it) {
            $sec = (string)$it['section'];
            if ($sec !== $last) {
                $last = $sec;
                $out .= '<tr class="sec"><td colspan="3">' . $h($sec !== '' ? $sec : 'Bez działu') . '</td></tr>';
            }
            $n++;
            $total_min += (int)$it['est_minutes'];
            $desc = trim((string)$it['description']) !== ''
                ? '<div class="desc">' . nl2br($h($it['description'])) . '</div>' : '';
            $out .= '<tr><td>' . $n . '.</td><td><strong>' . $h($it['title']) . '</strong>' . $desc . '</td>'
                  . '<td>' . ((int)$it['est_minutes'] > 0 ? (int)$it['est_minutes'] . ' min' : '—') . '</td></tr>';
        }
        $out .= '</tbody></table>';
        if ($total_min > 0) {
            $out .= '<p class="sum">Łącznie zaplanowano ' . $total_min . ' min (≈ ' . round($total_min / 60, 1) . ' h).</p>';
        }
    }

    // Wymagania i kryteria — listy numerowane
    foreach ([['requirement', '2. Wymagania'], ['criterion', '3. Kryteria oceniania']] as [$kind, $head]) {
        $out .= '<h2>' . $head . '</h2>';
        if (!$by_kind[$kind]) {
            $out .= '<p class="empty">Nie wprowadzono pozycji.</p>';
            continue;
        }
        $out .= '<ol class="list">';
        foreach ($by_kind[$kind] as $it) {
            $desc = trim((string)$it['description']) !== ''
                ? '<div class="desc">' . nl2br($h($it['description'])) . '</div>' : '';
            $out .= '<li><strong>' . $h($it['title']) . '</strong>' . $desc . '</li>';
        }
        $out .= '</ol>';
    }
    return $out;
}

/**
 * Sylabus jako bajty PDF (mPDF — wzorzec jak guardian_consent_generate_pdf:
 * dejavuserif dla polskich znaków, własny zapisywalny tempDir).
 * Zwraca null przy błędzie (np. brak mPDF) — wołający pokazuje komunikat.
 */
function ti_syllabus_pdf(array $syl): ?string {
    try {
        require_once dirname(__DIR__) . '/vendor/autoload.php';

        $mpdf_tmp = UPLOAD_DIR . 'mpdf_tmp';
        if (!is_dir($mpdf_tmp)) @mkdir($mpdf_tmp, 0755, true);

        $mpdf = new \Mpdf\Mpdf([
            'mode'          => 'utf-8',
            'format'        => 'A4',
            'margin_left'   => 20,
            'margin_right'  => 18,
            'margin_top'    => 18,
            'margin_bottom' => 18,
            'default_font'  => 'dejavuserif',
            'tempDir'       => $mpdf_tmp,
        ]);
        $name = trim((string)($syl['subject_abbr'] ?? '') . ' ' . (string)$syl['title']);
        $mpdf->SetTitle('Sylabus — ' . ($name !== '' ? $name : 'przedmiot'));
        $mpdf->SetAuthor(org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : 'FEER'));
        $mpdf->WriteHTML(
            'body { font-family: "DejaVu Serif", serif; font-size: 10.5pt; line-height: 1.45; color: #000; }
             h1 { font-size: 15pt; margin: 0 0 2mm; }
             h2 { font-size: 12pt; margin: 6mm 0 2mm; border-bottom: .3mm solid #999; padding-bottom: 1mm; }
             p.meta { color: #555; font-size: 9.5pt; margin: 0 0 4mm; }
             p.note { background: #f4f6f8; padding: 2mm 3mm; font-size: 10pt; }
             p.sum, p.empty { font-size: 9.5pt; color: #555; }
             table { border-collapse: collapse; width: 100%; }
             table.head th { text-align: left; width: 34%; background: #f4f6f8; }
             table.head th, table.head td { border: .2mm solid #ccc; padding: 1.4mm 2mm; font-size: 10pt; }
             table.items th { background: #eef1f4; border: .2mm solid #ccc; padding: 1.4mm 2mm; text-align: left; font-size: 9.5pt; }
             table.items td { border: .2mm solid #ccc; padding: 1.4mm 2mm; vertical-align: top; }
             tr.sec td { background: #f7f7f7; font-weight: bold; font-size: 9.5pt; }
             div.desc { color: #444; font-size: 9.5pt; margin-top: .8mm; }
             ol.list li { margin-bottom: 1.6mm; }',
            \Mpdf\HTMLParserMode::HEADER_CSS
        );
        $mpdf->WriteHTML(ti_syllabus_print_html($syl), \Mpdf\HTMLParserMode::HTML_BODY);
        return $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
    } catch (\Throwable $e) {
        return null;
    }
}

/** Nazwa pliku PDF sylabusa (bez znaków problematycznych). */
function ti_syllabus_pdf_filename(array $syl): string {
    $base = 'sylabus-' . ((string)($syl['subject_abbr'] ?? '') ?: 'przedmiot') . '-v' . (string)$syl['version'];
    $base = preg_replace('/[^A-Za-z0-9_\-]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $base) ?: $base);
    return trim((string)$base, '-') . '.pdf';
}
