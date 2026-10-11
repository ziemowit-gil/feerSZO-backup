<?php
/**
 * modules/ti_hospitacje/logic/tiHospitacje.php — Hospitacje i wizytacje zajęć TI (moduł kierownika).
 *
 * Hospitacja — obserwacja konkretnych zajęć prowadzącego: kryteria z oceną 1–5 (albo „nie dotyczy”),
 *   mocne strony, wnioski, zalecenia. Wizytacja — wizyta w grupie/placówce: zakres, ustalenia, zalecenia
 *   z terminem realizacji. Obie mają ten sam obieg:
 *     planowana → przeprowadzona (kierownik wypełnia arkusz) → zakończona (prowadzący zapoznał się,
 *     może dopisać komentarz) — albo odwołana.
 *
 * Kierownik (dyd_is_staff) planuje, wypełnia i drukuje (karty30/ti/dydaktyk/hospitacje.php).
 * Prowadzący widzi tylko swoje — od chwili zaplanowania (termin, cel) i po przeprowadzeniu (arkusz),
 * potwierdza zapoznanie się. Wypełnione hospitacje trafiają do sekcji „Hospitacje i wizytacje”
 * w dzienniku zajęć grupy (modules/ti_pdf/logic/dziennik.php).
 *
 * Tabela: k30_ti_hospitacje (kryteria w JSON: {klucz: {ocena: 1–5|0, uwagi: ""}}).
 */

const TI_HOSP_TYPES = ['hospitacja' => 'Hospitacja', 'wizytacja' => 'Wizytacja'];

const TI_HOSP_STATUSES = [
    'planowana'      => 'Planowana',
    'przeprowadzona' => 'Przeprowadzona — czeka na zapoznanie się prowadzącego',
    'zakonczona'     => 'Zakończona',
    'odwolana'       => 'Odwołana',
];

/** Kryteria arkusza hospitacji: klucz => [nazwa, podpowiedź]. */
const TI_HOSP_CRITERIA = [
    'przygotowanie' => ['Przygotowanie zajęć', 'Cel zajęć, plan, przygotowane materiały i sprzęt.'],
    'sylabus'       => ['Realizacja tematu', 'Zgodność z sylabusem i tematem wpisanym w dzienniku.'],
    'metody'        => ['Metody i dostosowanie', 'Dobór metod, dostosowanie do potrzeb i niepełnosprawności kursantów (technologie wspomagające).'],
    'komunikacja'   => ['Komunikacja i aktywizacja', 'Kontakt z kursantami, sprawdzanie zrozumienia, aktywny udział.'],
    'czas'          => ['Organizacja czasu', 'Punktualność, tempo, wykorzystanie czasu zajęć.'],
    'dokumentacja'  => ['Dokumentacja', 'Obecności, tematy i oceny w e-dzienniku na bieżąco.'],
];

function ti_hosp_migrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    db()->exec("CREATE TABLE IF NOT EXISTS k30_ti_hospitacje (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        typ             TEXT    NOT NULL DEFAULT 'hospitacja',
        status          TEXT    NOT NULL DEFAULT 'planowana',
        course_id       INTEGER REFERENCES k30_ti_courses(id) ON DELETE SET NULL,
        session_id      INTEGER REFERENCES k30_ti_sessions(id) ON DELETE SET NULL,
        instructor_id   INTEGER REFERENCES users(id) ON DELETE SET NULL,
        data            TEXT    NOT NULL,
        godzina         TEXT    NOT NULL DEFAULT '',
        temat           TEXT    NOT NULL DEFAULT '',
        cel             TEXT    NOT NULL DEFAULT '',
        zakres          TEXT    NOT NULL DEFAULT '',
        hospitujacy_id  INTEGER REFERENCES users(id) ON DELETE SET NULL,
        hospitujacy     TEXT    NOT NULL DEFAULT '',
        funkcja         TEXT    NOT NULL DEFAULT '',
        kryteria        TEXT    NOT NULL DEFAULT '{}',
        mocne_strony    TEXT    NOT NULL DEFAULT '',
        wnioski         TEXT    NOT NULL DEFAULT '',
        zalecenia       TEXT    NOT NULL DEFAULT '',
        termin_zalecen  TEXT    NOT NULL DEFAULT '',
        przeprowadzona_at TEXT,
        zapoznal_at     TEXT,
        zapoznal_name   TEXT    NOT NULL DEFAULT '',
        komentarz_prow  TEXT    NOT NULL DEFAULT '',
        odwolanie_powod TEXT    NOT NULL DEFAULT '',
        created_by      INTEGER,
        created_at      TEXT    NOT NULL DEFAULT (datetime('now')),
        updated_by      INTEGER,
        updated_at      TEXT
    )");
    db()->exec("CREATE INDEX IF NOT EXISTS idx_ti_hosp_course ON k30_ti_hospitacje(course_id, data)");
    db()->exec("CREATE INDEX IF NOT EXISTS idx_ti_hosp_instr  ON k30_ti_hospitacje(instructor_id, data)");
}

/** Jedna pozycja z nazwą grupy i prowadzącego. */
function ti_hosp_get(int $id): ?array {
    ti_hosp_migrate();
    return db_one(
        "SELECT h.*, COALESCE(NULLIF(c.display_name, ''), c.name) AS course_name, u.name AS instructor_name, u.email AS instructor_email
           FROM k30_ti_hospitacje h
           LEFT JOIN k30_ti_courses c ON c.id = h.course_id
           LEFT JOIN users u ON u.id = h.instructor_id
          WHERE h.id = ?", [$id]) ?: null;
}

/**
 * Lista z filtrami: typ, status, instructor_id, course_id, ym (RRRR-MM), od/do.
 * $only_instructor — widok prowadzącego: tylko jego pozycje (bez odwołanych).
 */
function ti_hosp_list(array $f = [], ?int $only_instructor = null): array {
    ti_hosp_migrate();
    $w = []; $p = [];
    foreach (['typ', 'status'] as $k) if (!empty($f[$k])) { $w[] = "h.$k = ?"; $p[] = (string)$f[$k]; }
    foreach (['instructor_id', 'course_id'] as $k) if (!empty($f[$k])) { $w[] = "h.$k = ?"; $p[] = (int)$f[$k]; }
    if (!empty($f['ym']) && preg_match('/^\d{4}-\d{2}$/', (string)$f['ym'])) { $w[] = "substr(h.data, 1, 7) = ?"; $p[] = (string)$f['ym']; }
    if ($only_instructor !== null) { $w[] = "h.instructor_id = ? AND h.status <> 'odwolana'"; $p[] = $only_instructor; }
    return db_all(
        "SELECT h.*, COALESCE(NULLIF(c.display_name, ''), c.name) AS course_name, u.name AS instructor_name
           FROM k30_ti_hospitacje h
           LEFT JOIN k30_ti_courses c ON c.id = h.course_id
           LEFT JOIN users u ON u.id = h.instructor_id"
        . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . " ORDER BY h.data DESC, h.godzina DESC, h.id DESC", $p);
}

/** Kryteria z JSON — zawsze pełny zestaw kluczy. */
function ti_hosp_criteria(array $h): array {
    $k = json_decode((string)($h['kryteria'] ?? '{}'), true);
    $k = is_array($k) ? $k : [];
    $out = [];
    foreach (TI_HOSP_CRITERIA as $key => $_) {
        $out[$key] = ['ocena' => max(0, min(5, (int)($k[$key]['ocena'] ?? 0))), 'uwagi' => (string)($k[$key]['uwagi'] ?? '')];
    }
    return $out;
}

/** Średnia z ocen kryteriów (bez „nie dotyczy”) albo null. */
function ti_hosp_average(array $h): ?float {
    $o = array_filter(array_column(ti_hosp_criteria($h), 'ocena'), fn($x) => $x > 0);
    return $o ? round(array_sum($o) / count($o), 2) : null;
}

/**
 * Zapis (nowa albo edycja). $d — pola formularza. Zwraca id.
 * Prowadzący i temat dobierane z zajęć (session_id) albo grupy, gdy nie podano ręcznie.
 */
function ti_hosp_save(array $d, int $uid, ?int $id = null): int {
    ti_hosp_migrate();
    $typ = isset(TI_HOSP_TYPES[$d['typ'] ?? '']) ? (string)$d['typ'] : 'hospitacja';
    $cid = (int)($d['course_id'] ?? 0) ?: null;
    $sid = (int)($d['session_id'] ?? 0) ?: null;
    $data = (string)($d['data'] ?? '');
    $godz = (string)($d['godzina'] ?? '');
    $temat = trim((string)($d['temat'] ?? ''));
    $instr = (int)($d['instructor_id'] ?? 0) ?: null;
    if ($sid) {
        $s = db_one("SELECT course_id, lesson_date, time_from, topic, instructor_id FROM k30_ti_sessions WHERE id = ?", [$sid]);
        if ($s) {
            $cid ??= (int)$s['course_id'];
            if ($data === '') $data = (string)$s['lesson_date'];
            if ($godz === '') $godz = substr((string)$s['time_from'], 0, 5);
            if ($temat === '') $temat = trim((string)$s['topic']);
            $instr ??= ((int)$s['instructor_id'] ?: null);
        }
    }
    if ($cid && !$instr) $instr = (int)(db_one("SELECT instructor_id FROM k30_ti_courses WHERE id = ?", [$cid])['instructor_id'] ?? 0) ?: null;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) throw new \RuntimeException('Podaj datę.');
    if (!$cid) throw new \RuntimeException('Wybierz grupę.');

    $kryt = [];
    foreach (TI_HOSP_CRITERIA as $key => $_) {
        $kryt[$key] = ['ocena' => max(0, min(5, (int)($d['k_ocena'][$key] ?? 0))), 'uwagi' => trim((string)($d['k_uwagi'][$key] ?? ''))];
    }
    $hosp_id = (int)($d['hospitujacy_id'] ?? 0) ?: $uid;
    $row = [
        'typ' => $typ, 'course_id' => $cid, 'session_id' => $sid, 'instructor_id' => $instr,
        'data' => $data, 'godzina' => preg_match('/^\d{2}:\d{2}$/', $godz) ? $godz : '',
        'temat' => $temat, 'cel' => trim((string)($d['cel'] ?? '')), 'zakres' => trim((string)($d['zakres'] ?? '')),
        'hospitujacy_id' => $hosp_id,
        'hospitujacy' => trim((string)($d['hospitujacy'] ?? '')) ?: (string)(db_one("SELECT name FROM users WHERE id = ?", [$hosp_id])['name'] ?? ''),
        'funkcja' => trim((string)($d['funkcja'] ?? '')) ?: 'Kierownik',
        'kryteria' => json_encode($typ === 'hospitacja' ? $kryt : new \stdClass(), JSON_UNESCAPED_UNICODE),
        'mocne_strony' => trim((string)($d['mocne_strony'] ?? '')), 'wnioski' => trim((string)($d['wnioski'] ?? '')),
        'zalecenia' => trim((string)($d['zalecenia'] ?? '')),
        'termin_zalecen' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($d['termin_zalecen'] ?? '')) ? (string)$d['termin_zalecen'] : '',
        'updated_by' => $uid, 'updated_at' => date('Y-m-d H:i:s'),
    ];
    if ($id) {
        $cols = implode(', ', array_map(fn($k) => "$k = ?", array_keys($row)));
        db_exec("UPDATE k30_ti_hospitacje SET $cols WHERE id = ?", array_merge(array_values($row), [$id]));
        return $id;
    }
    $row['status'] = 'planowana';
    $row['created_by'] = $uid;
    return db_insert('k30_ti_hospitacje', $row);
}

/** Zmiana statusu: przeprowadzona / odwolana / planowana (przywrócenie). */
function ti_hosp_set_status(int $id, string $status, int $uid, string $powod = ''): void {
    ti_hosp_migrate();
    if (!isset(TI_HOSP_STATUSES[$status]) || $status === 'zakonczona') throw new \RuntimeException('Nieznany status.');
    db_exec("UPDATE k30_ti_hospitacje SET status = ?, updated_by = ?, updated_at = datetime('now'),
                    przeprowadzona_at = CASE WHEN ? = 'przeprowadzona' THEN COALESCE(przeprowadzona_at, datetime('now')) ELSE przeprowadzona_at END,
                    odwolanie_powod = CASE WHEN ? = 'odwolana' THEN ? ELSE odwolanie_powod END
              WHERE id = ?", [$status, $uid, $status, $status, $powod, $id]);
}

/** Prowadzący potwierdza zapoznanie się z arkuszem (status → zakończona). */
function ti_hosp_acknowledge(int $id, int $uid, string $name, string $komentarz): void {
    $h = ti_hosp_get($id);
    if (!$h || (int)$h['instructor_id'] !== $uid) throw new \RuntimeException('To nie jest Twoja hospitacja.');
    if ($h['status'] !== 'przeprowadzona') throw new \RuntimeException('Arkusz nie czeka na zapoznanie się.');
    db_exec("UPDATE k30_ti_hospitacje SET status = 'zakonczona', zapoznal_at = datetime('now'), zapoznal_name = ?, komentarz_prow = ?
              WHERE id = ?", [$name, trim($komentarz), $id]);
}

/**
 * Powiadomienie prowadzącego e-mailem: 'plan' — zaplanowano, 'arkusz' — arkusz do zapoznania się.
 * Zwraca true, gdy mail trafił do kolejki.
 */
function ti_hosp_notify(int $id, string $co): bool {
    try {
        require_once dirname(__DIR__, 3) . '/includes/mail_queue.php';
        $h = ti_hosp_get($id);
        if (!$h || trim((string)$h['instructor_email']) === '') return false;
        $rodz = mb_strtolower(TI_HOSP_TYPES[$h['typ']] ?? 'Hospitacja');
        $kiedy = date('d.m.Y', strtotime((string)$h['data'])) . ($h['godzina'] !== '' ? ', godz. ' . $h['godzina'] : '');
        $url = rtrim((string)(defined('APP_URL') ? APP_URL : ''), '/') . '/karty30/ti/dydaktyk/hospitacje.php?id=' . $id;
        if ($co === 'plan') {
            $subj = 'Zaplanowana ' . $rodz . ' zajęć — ' . $kiedy;
            $txt = "Dzień dobry,\n\nzaplanowano {$rodz} zajęć w grupie " . $h['course_name'] . " — {$kiedy}.\n"
                 . ($h['cel'] !== '' ? "Cel: {$h['cel']}\n" : '') . 'Prowadzi: ' . $h['hospitujacy'] . "\n\nSzczegóły w panelu dydaktyka: {$url}\n\n--\nWiadomość automatyczna.";
        } else {
            $subj = 'Arkusz: ' . $rodz . ' z dnia ' . date('d.m.Y', strtotime((string)$h['data'])) . ' — zapoznaj się';
            $txt = "Dzień dobry,\n\narkusz ({$rodz} zajęć w grupie " . $h['course_name'] . ", {$kiedy}) jest gotowy.\n"
                 . "Zapoznaj się z nim i potwierdź w panelu dydaktyka — możesz dopisać swój komentarz: {$url}\n\n--\nWiadomość automatyczna.";
        }
        mail_queue_add((string)$h['instructor_email'], (string)$h['instructor_name'], $subj,
            '<p>' . nl2br(htmlspecialchars($txt, ENT_QUOTES, 'UTF-8')) . '</p>', $txt, 'ti_hospitacja', $id);
        return true;
    } catch (\Throwable $e) {
        error_log('ti_hosp_notify #' . $id . ': ' . $e->getMessage());
        return false;
    }
}

/** HTML arkusza do PDF (ten sam wygląd co dokumenty protokołów — ti_pdf_new). */
function ti_hosp_html(array $h): string {
    $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $nl = fn($s) => nl2br($e($s));
    $hosp = $h['typ'] === 'hospitacja';
    $out = '<h1>' . ($hosp ? 'Arkusz hospitacji zajęć' : 'Protokół wizytacji') . '</h1>'
         . '<table class="head"><tbody>'
         . '<tr><th>Grupa</th><td>' . $e($h['course_name'] ?? '') . '</td></tr>'
         . '<tr><th>Prowadzący</th><td>' . $e(($h['instructor_name'] ?? '') ?: '—') . '</td></tr>'
         . '<tr><th>Data</th><td>' . $e(date('d.m.Y', strtotime((string)$h['data'])) . ($h['godzina'] !== '' ? ', godz. ' . $h['godzina'] : '')) . '</td></tr>'
         . ($h['temat'] !== '' ? '<tr><th>Temat zajęć</th><td>' . $e($h['temat']) . '</td></tr>' : '')
         . '<tr><th>' . ($hosp ? 'Hospitujący' : 'Wizytujący') . '</th><td>' . $e($h['hospitujacy'] . ($h['funkcja'] !== '' ? ', ' . $h['funkcja'] : '')) . '</td></tr>'
         . ($h['cel'] !== '' ? '<tr><th>Cel</th><td>' . $nl($h['cel']) . '</td></tr>' : '')
         . (!$hosp && $h['zakres'] !== '' ? '<tr><th>Zakres</th><td>' . $nl($h['zakres']) . '</td></tr>' : '')
         . '</tbody></table>';
    if ($hosp) {
        $rows = ''; $i = 0;
        foreach (ti_hosp_criteria($h) as $k => $c) {
            $i++;
            $rows .= '<tr><td>' . $i . '.</td><td><strong>' . $e(TI_HOSP_CRITERIA[$k][0]) . '</strong><br><span class="sub">' . $e(TI_HOSP_CRITERIA[$k][1]) . '</span></td>'
                   . '<td class="r">' . ($c['ocena'] > 0 ? $c['ocena'] : 'n/d') . '</td><td>' . $nl($c['uwagi']) . '</td></tr>';
        }
        $avg = ti_hosp_average($h);
        $out .= '<h2>Ocena zajęć (1 — niedostatecznie, 5 — bardzo dobrze)</h2>'
              . '<table class="items"><thead><tr><th style="width:6%">Lp.</th><th style="width:40%">Kryterium</th><th style="width:10%" class="r">Ocena</th><th>Uwagi</th></tr></thead><tbody>'
              . $rows . '<tr class="sum"><td colspan="2">Średnia</td><td class="r">' . ($avg !== null ? $e(number_format($avg, 2, ',', '')) : '—') . '</td><td></td></tr></tbody></table>';
        if ($h['mocne_strony'] !== '') $out .= '<h2>Mocne strony</h2><p>' . $nl($h['mocne_strony']) . '</p>';
    }
    if ($h['wnioski'] !== '')   $out .= '<h2>' . ($hosp ? 'Wnioski' : 'Ustalenia') . '</h2><p>' . $nl($h['wnioski']) . '</p>';
    if ($h['zalecenia'] !== '') $out .= '<h2>Zalecenia</h2><p>' . $nl($h['zalecenia'])
        . ($h['termin_zalecen'] !== '' ? '<br><strong>Termin realizacji:</strong> ' . $e(date('d.m.Y', strtotime((string)$h['termin_zalecen']))) : '') . '</p>';
    if (!empty($h['zapoznal_at'])) {
        $out .= '<h2>Zapoznanie się prowadzącego</h2><p>' . $e($h['zapoznal_name']) . ' zapoznał(a) się z arkuszem '
              . $e(date('d.m.Y H:i', strtotime((string)$h['zapoznal_at'] . ' UTC'))) . ' (potwierdzenie w systemie SZO).'
              . ($h['komentarz_prow'] !== '' ? '<br><strong>Komentarz prowadzącego:</strong> ' . $nl($h['komentarz_prow']) : '') . '</p>';
    }
    return $out . '<table class="signs"><tr><td>..............................................<br>podpis ' . ($hosp ? 'hospitującego' : 'wizytującego') . '</td>'
                . '<td>..............................................<br>podpis prowadzącego</td></tr></table>';
}

function ti_hosp_pdf(array $h): ?string {
    try {
        require_once dirname(__DIR__, 3) . '/includes/ti_protocols.php';
        $label = $h['typ'] === 'hospitacja' ? 'Arkusz hospitacji' : 'Protokół wizytacji';
        $mpdf = ti_pdf_new($label . ' — ' . (string)($h['course_name'] ?? '') . ', ' . (string)$h['data'], $label);
        if ($h['status'] === 'planowana') { $mpdf->SetWatermarkText('PLAN', 0.12); $mpdf->showWatermarkText = true; }
        $mpdf->WriteHTML(ti_hosp_html($h), \Mpdf\HTMLParserMode::HTML_BODY);
        return $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
    } catch (\Throwable $e) {
        error_log('ti_hosp_pdf: ' . $e->getMessage());
        return null;
    }
}

/** Do dziennika zajęć: przeprowadzone/zakończone pozycje grupy (opcjonalnie miesiąc). */
function ti_hosp_for_dziennik(int $course_id, string $ym = ''): array {
    try {
        ti_hosp_migrate();
        return db_all("SELECT data, typ, temat, hospitujacy, funkcja, status FROM k30_ti_hospitacje
                        WHERE course_id = ? AND status IN ('przeprowadzona', 'zakonczona')" . ($ym !== '' ? " AND substr(data, 1, 7) = ?" : '')
                    . " ORDER BY data", $ym !== '' ? [$course_id, $ym] : [$course_id]);
    } catch (\Throwable $e) {
        return [];
    }
}
