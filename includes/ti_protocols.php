<?php
/**
 * includes/ti_protocols.php — Protokoły ocen (widok USOS panelu dydaktyka).
 *
 * Protokół = zestaw ocen KOŃCOWYCH uczestników kursu za dany okres nauczania,
 * wypełniany zbiorczo w jednej tabeli i zatwierdzany ze śladem (kto, kiedy).
 * Po zatwierdzeniu prowadzący nie może już zmieniać ocen — odblokować może
 * pracownik D3 / administrator, z podaniem powodu (też zapisywanym).
 *
 * ROZDZIAŁ OD E-DZIENNIKA: oceny cząstkowe zostają w k30_ti_grades (kategorie,
 * wagi, średnia ważona). Ocena z protokołu jest oceną końcową i trzyma się we
 * własnej tabeli, żeby NIE wchodziła do średniej ważonej dziennika i żeby
 * zatwierdzenie mogło ją zablokować niezależnie od wpisów bieżących.
 * Skala jest ta sama co w dzienniku (1–6 z +/-), więc obie liczby są
 * porównywalne — protokół pokazuje średnią z dziennika obok oceny końcowej.
 *
 * Osobne od [[project_ti_blackout]] (wyłączenie dziennika blokuje też protokoły)
 * i od k30_ti_tests (wyniki testów).
 */

require_once __DIR__ . '/karty30.php';    // k30_ti_grade_parse_num(), k30_ti_course_grades()

/** Dopuszczalne wpisy poza skalą liczbową (nieklasyfikowany, zwolniony itp.). */
const TI_PROTOCOL_SPECIAL = ['np' => 'nieklasyfikowany', 'nb' => 'nieobecny', 'zw' => 'zwolniony', 'bz' => 'brak zaliczenia'];

if (!defined('TI_PROTOCOL_STATUSES')) {
    define('TI_PROTOCOL_STATUSES', [
        'open'     => ['label' => 'niezatwierdzony', 'badge' => 'secondary'],
        'approved' => ['label' => 'zatwierdzony',    'badge' => 'success'],
    ]);
}

/** Samonaprawa schematu — wołana z każdego punktu wejścia. */
function ti_protocols_migrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS k30_ti_protocols (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            course_id     INTEGER NOT NULL REFERENCES k30_ti_courses(id) ON DELETE CASCADE,
            period_id     INTEGER REFERENCES k30_ti_periods(id) ON DELETE SET NULL,
            title         TEXT    NOT NULL DEFAULT '',
            status        TEXT    NOT NULL DEFAULT 'open',
            note          TEXT    NOT NULL DEFAULT '',
            approved_by   INTEGER REFERENCES users(id) ON DELETE SET NULL,
            approved_name TEXT    NOT NULL DEFAULT '',
            approved_at   TEXT,
            unlocked_by   INTEGER REFERENCES users(id) ON DELETE SET NULL,
            unlocked_name TEXT    NOT NULL DEFAULT '',
            unlocked_at   TEXT,
            unlock_reason TEXT    NOT NULL DEFAULT '',
            created_by    INTEGER,
            created_at    TEXT    NOT NULL DEFAULT (datetime('now')),
            updated_at    TEXT    NOT NULL DEFAULT (datetime('now'))
        )");
        db()->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_ti_prot_course_period
                    ON k30_ti_protocols(course_id, COALESCE(period_id, 0))");

        db()->exec("CREATE TABLE IF NOT EXISTS k30_ti_protocol_entries (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            protocol_id INTEGER NOT NULL REFERENCES k30_ti_protocols(id) ON DELETE CASCADE,
            client_id   INTEGER NOT NULL REFERENCES k30_clients(id) ON DELETE CASCADE,
            value_text  TEXT    NOT NULL DEFAULT '',
            value_num   REAL,
            note        TEXT    NOT NULL DEFAULT '',
            updated_by  INTEGER,
            updated_at  TEXT    NOT NULL DEFAULT (datetime('now'))
        )");
        db()->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_ti_prot_entry
                    ON k30_ti_protocol_entries(protocol_id, client_id)");
    } catch (\Throwable $e) {}
}

/** Etykieta stanu protokołu. */
function ti_protocol_status_label(string $status): string {
    return TI_PROTOCOL_STATUSES[$status]['label'] ?? $status;
}

/** Czy protokół jest zamknięty do edycji. */
function ti_protocol_is_locked(array $protocol): bool {
    return (string)($protocol['status'] ?? 'open') === 'approved';
}

/**
 * Sprawdza wpis oceny. Zwraca ['ok'=>bool, 'text'=>string, 'num'=>?float, 'msg'=>string].
 * Puste = wyczyszczenie wpisu (ok, text='').
 */
function ti_protocol_parse_value(string $raw): array {
    $t = trim($raw);
    if ($t === '') return ['ok' => true, 'text' => '', 'num' => null, 'msg' => ''];

    $low = mb_strtolower($t, 'UTF-8');
    if (isset(TI_PROTOCOL_SPECIAL[$low])) {
        return ['ok' => true, 'text' => $low, 'num' => null, 'msg' => ''];
    }
    $t = str_replace(' ', '', $t);
    if (preg_match('/^[1-6][+\-]?$/', $t)) {
        return ['ok' => true, 'text' => $t, 'num' => k30_ti_grade_parse_num($t), 'msg' => ''];
    }
    return [
        'ok' => false, 'text' => '', 'num' => null,
        'msg' => 'Niedozwolony wpis „' . $raw . '". Dozwolone: 1–6 (można z + lub -) albo '
               . implode(', ', array_keys(TI_PROTOCOL_SPECIAL)) . '.',
    ];
}

/** Protokoły kursu (najnowsze pierwsze) z nazwą okresu. */
function ti_protocols_for_course(int $course_id): array {
    ti_protocols_migrate();
    return db_all(
        "SELECT p.*, per.name AS period_name, per.date_from, per.date_to
           FROM k30_ti_protocols p
           LEFT JOIN k30_ti_periods per ON per.id = p.period_id
          WHERE p.course_id = ?
          ORDER BY COALESCE(per.date_from, p.created_at) DESC, p.id DESC",
        [$course_id]
    );
}

function ti_protocol_get(int $id): ?array {
    ti_protocols_migrate();
    if (!$id) return null;
    return db_one(
        "SELECT p.*, per.name AS period_name, per.date_from, per.date_to, c.name AS course_name
           FROM k30_ti_protocols p
           LEFT JOIN k30_ti_periods per ON per.id = p.period_id
           LEFT JOIN k30_ti_courses c   ON c.id   = p.course_id
          WHERE p.id = ?",
        [$id]
    );
}

/**
 * Tworzy protokół dla kursu i okresu albo zwraca istniejący (jeden na parę).
 * @return int id protokołu
 */
function ti_protocol_ensure(int $course_id, int $period_id, ?int $by = null, string $title = ''): int {
    ti_protocols_migrate();
    if (!$course_id) throw new \RuntimeException('Brak kursu.');

    // CAST konieczny: PDO wiąże parametry jako TEKST, a COALESCE(...) jest
    // wyrażeniem bez affinity kolumny — bez rzutowania '1' != 1 i SQLite
    // wpuściłby duplikat wprost na unikalny indeks.
    $existing = db_one(
        "SELECT id FROM k30_ti_protocols WHERE course_id=? AND COALESCE(period_id,0)=CAST(? AS INTEGER)",
        [$course_id, $period_id]
    );
    if ($existing) return (int)$existing['id'];

    if ($title === '') {
        $per   = $period_id ? db_one("SELECT name FROM k30_ti_periods WHERE id=?", [$period_id]) : null;
        $title = $per ? 'Protokół — ' . (string)$per['name'] : 'Protokół — bez okresu';
    }
    return db_insert('k30_ti_protocols', [
        'course_id'  => $course_id,
        'period_id'  => $period_id ?: null,
        'title'      => $title,
        'status'     => 'open',
        'created_by' => $by,
    ]);
}

/** Uczestnicy kursu do protokołu (aktywni zapisani, alfabetycznie). */
function ti_protocol_participants(int $course_id): array {
    ti_protocols_migrate();
    return db_all(
        "SELECT e.client_id, cl.name, cl.email, cl.phone
           FROM k30_ti_enrollments e
           JOIN k30_clients cl ON cl.id = e.client_id
          WHERE e.course_id = ? AND e.status = 'active'
          ORDER BY cl.name COLLATE NOCASE",
        [$course_id]
    );
}

/** Wpisy protokołu jako mapa client_id → wiersz. */
function ti_protocol_entries(int $protocol_id): array {
    ti_protocols_migrate();
    $out = [];
    foreach (db_all("SELECT * FROM k30_ti_protocol_entries WHERE protocol_id=?", [$protocol_id]) as $r) {
        $out[(int)$r['client_id']] = $r;
    }
    return $out;
}

/**
 * Zbiorczy zapis ocen protokołu.
 *
 * @param array $values  client_id => ocena (tekst; puste = wyczyść wpis)
 * @param array $notes   client_id => uwaga
 * @return array{saved:int, cleared:int, errors:string[]}
 * @throws RuntimeException gdy protokół jest zatwierdzony (zamknięty).
 */
function ti_protocol_save_entries(int $protocol_id, array $values, array $notes = [], ?int $by = null): array {
    ti_protocols_migrate();
    $prot = ti_protocol_get($protocol_id);
    if (!$prot) throw new \RuntimeException('Protokół nie istnieje.');
    if (ti_protocol_is_locked($prot)) {
        throw new \RuntimeException('Protokół jest zatwierdzony — ocen nie można już zmieniać.');
    }

    $allowed = array_map(fn($p) => (int)$p['client_id'], ti_protocol_participants((int)$prot['course_id']));
    $existing = ti_protocol_entries($protocol_id);
    $saved = 0; $cleared = 0; $errors = [];

    foreach ($values as $cid => $raw) {
        $cid = (int)$cid;
        if (!in_array($cid, $allowed, true)) continue;   // nie uczestnik tego kursu

        $p = ti_protocol_parse_value((string)$raw);
        if (!$p['ok']) { $errors[] = $p['msg']; continue; }

        $note = trim((string)($notes[$cid] ?? ''));

        if ($p['text'] === '' && $note === '') {
            if (isset($existing[$cid])) {
                db_exec("DELETE FROM k30_ti_protocol_entries WHERE id=?", [(int)$existing[$cid]['id']]);
                $cleared++;
            }
            continue;
        }
        if (isset($existing[$cid])) {
            db()->prepare(
                "UPDATE k30_ti_protocol_entries
                    SET value_text=?, value_num=?, note=?, updated_by=?, updated_at=datetime('now')
                  WHERE id=?"
            )->execute([$p['text'], $p['num'], $note, $by, (int)$existing[$cid]['id']]);
        } else {
            db_insert('k30_ti_protocol_entries', [
                'protocol_id' => $protocol_id,
                'client_id'   => $cid,
                'value_text'  => $p['text'],
                'value_num'   => $p['num'],
                'note'        => $note,
                'updated_by'  => $by,
            ]);
        }
        $saved++;
    }
    db_exec("UPDATE k30_ti_protocols SET updated_at=datetime('now') WHERE id=?", [$protocol_id]);
    return ['saved' => $saved, 'cleared' => $cleared, 'errors' => $errors];
}

/**
 * Zatwierdza protokół (ślad: kto, kiedy) i zamyka go do edycji.
 * @throws RuntimeException gdy brak ocen albo protokół już zatwierdzony.
 */
function ti_protocol_approve(int $protocol_id, ?int $by, string $by_name): void {
    ti_protocols_migrate();
    $prot = ti_protocol_get($protocol_id);
    if (!$prot) throw new \RuntimeException('Protokół nie istnieje.');
    if (ti_protocol_is_locked($prot)) throw new \RuntimeException('Protokół jest już zatwierdzony.');

    $st = ti_protocol_stats($protocol_id, (int)$prot['course_id']);
    if ($st['filled'] === 0) {
        throw new \RuntimeException('Nie można zatwierdzić pustego protokołu — wpisz oceny.');
    }
    db()->prepare(
        "UPDATE k30_ti_protocols
            SET status='approved', approved_by=?, approved_name=?, approved_at=datetime('now'),
                updated_at=datetime('now')
          WHERE id=?"
    )->execute([$by, $by_name, $protocol_id]);
}

/**
 * Odblokowuje zatwierdzony protokół (tylko pracownik D3 / admin — sprawdzane
 * po stronie wywołującej). Ślad odblokowania z powodem zostaje w protokole.
 */
function ti_protocol_unlock(int $protocol_id, ?int $by, string $by_name, string $reason): void {
    ti_protocols_migrate();
    $prot = ti_protocol_get($protocol_id);
    if (!$prot) throw new \RuntimeException('Protokół nie istnieje.');
    if (!ti_protocol_is_locked($prot)) throw new \RuntimeException('Ten protokół nie jest zatwierdzony.');
    $reason = trim($reason);
    if ($reason === '') throw new \RuntimeException('Podaj powód odblokowania protokołu.');

    // Protokół z zamkniętego okresu jest domknięty razem z nim — najpierw okres
    if (!empty($prot['period_id'])) {
        require_once __DIR__ . '/ti_periods.php';
        $per = ti_period_get((int)$prot['period_id']);
        if ($per && ti_period_is_closed($per)) {
            throw new \RuntimeException(
                'Okres „' . (string)$per['name'] . '" jest zamknięty — aby poprawić protokół, '
                . 'administrator musi najpierw otworzyć okres ponownie.'
            );
        }
    }

    db()->prepare(
        "UPDATE k30_ti_protocols
            SET status='open', unlocked_by=?, unlocked_name=?, unlocked_at=datetime('now'),
                unlock_reason=?, updated_at=datetime('now')
          WHERE id=?"
    )->execute([$by, $by_name, $reason, $protocol_id]);
}

/**
 * Wypełnienie protokołu: ilu uczestników ma ocenę.
 * @return array{total:int, filled:int, pct:int}
 */
function ti_protocol_stats(int $protocol_id, int $course_id): array {
    ti_protocols_migrate();
    $total = count(ti_protocol_participants($course_id));
    $filled = (int)(db_one(
        "SELECT COUNT(*) AS n FROM k30_ti_protocol_entries
          WHERE protocol_id=? AND value_text != ''",
        [$protocol_id]
    )['n'] ?? 0);
    if ($filled > $total) $filled = $total;   // uczestnik wypisany po wpisie oceny
    return [
        'total'  => $total,
        'filled' => $filled,
        'pct'    => $total > 0 ? (int)round($filled * 100 / $total) : 0,
    ];
}

/** Krótki opis wypełnienia, np. „częściowo wypełniony (3 z 8)". */
function ti_protocol_fill_text(array $stats): string {
    if ($stats['total'] === 0)                 return 'brak uczestników';
    if ($stats['filled'] === 0)                return 'pusty (0 z ' . $stats['total'] . ')';
    if ($stats['filled'] < $stats['total'])    return 'częściowo wypełniony (' . $stats['filled'] . ' z ' . $stats['total'] . ')';
    return 'wypełniony (' . $stats['total'] . ' z ' . $stats['total'] . ')';
}

/**
 * Średnia ważona z e-dziennika per uczestnik (do kolumny pomocniczej w protokole).
 * @return array<int, float> client_id => średnia
 */
function ti_protocol_diary_averages(int $course_id): array {
    $by_client = [];
    foreach (k30_ti_course_grades($course_id) as $g) {
        $by_client[(int)$g['client_id']][] = $g;
    }
    $out = [];
    foreach ($by_client as $cid => $grades) {
        $avg = k30_ti_grades_average($grades);
        if ($avg !== null) $out[$cid] = $avg;
    }
    return $out;
}

/** Protokół jako bajty PDF (mPDF, dejavuserif) albo null przy błędzie. */
function ti_protocol_pdf(array $prot): ?string {
    try {
        require_once dirname(__DIR__) . '/vendor/autoload.php';

        $mpdf_tmp = UPLOAD_DIR . 'mpdf_tmp';
        if (!is_dir($mpdf_tmp)) @mkdir($mpdf_tmp, 0755, true);

        $course_id = (int)$prot['course_id'];
        $parts     = ti_protocol_participants($course_id);
        $entries   = ti_protocol_entries((int)$prot['id']);
        $avgs      = ti_protocol_diary_averages($course_id);
        $stats     = ti_protocol_stats((int)$prot['id'], $course_id);
        $h         = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

        $rows = '';
        $i = 0;
        foreach ($parts as $p) {
            $cid = (int)$p['client_id'];
            $e   = $entries[$cid] ?? null;
            $i++;
            $rows .= '<tr>'
                . '<td>' . $i . '.</td>'
                . '<td>' . $h($p['name']) . '</td>'
                . '<td class="c">' . $h($e['value_text'] ?? '—') . '</td>'
                . '<td class="c">' . (isset($avgs[$cid]) ? number_format($avgs[$cid], 2, ',', '') : '—') . '</td>'
                . '<td>' . $h($e['note'] ?? '') . '</td>'
                . '</tr>';
        }

        $trace = ti_protocol_is_locked($prot)
            ? 'Zatwierdził: ' . $h($prot['approved_name'] ?: '—')
              . ', ' . $h($prot['approved_at'] ? date('d.m.Y H:i', strtotime((string)$prot['approved_at'])) : '—')
            : 'Protokół niezatwierdzony — wydruk roboczy.';
        if (!empty($prot['unlocked_at'])) {
            $trace .= '<br>Odblokowany: ' . $h($prot['unlocked_name'] ?: '—') . ', '
                . $h(date('d.m.Y H:i', strtotime((string)$prot['unlocked_at'])))
                . ' — powód: ' . $h($prot['unlock_reason']);
        }

        $html = '<h1>Protokół ocen</h1>'
            . '<table class="head"><tbody>'
            . '<tr><th>Zajęcia</th><td>' . $h($prot['course_name'] ?? '') . '</td></tr>'
            . '<tr><th>Protokół</th><td>' . $h($prot['title']) . '</td></tr>'
            . '<tr><th>Okres</th><td>' . $h($prot['period_name'] ?: 'nie wskazano') . '</td></tr>'
            . '<tr><th>Stan</th><td>' . $h(ti_protocol_status_label((string)$prot['status']))
                . ' — ' . $h(ti_protocol_fill_text($stats)) . '</td></tr>'
            . '<tr><th>Wydruk</th><td>' . date('d.m.Y H:i') . '</td></tr>'
            . '</tbody></table>'
            . '<table class="items"><thead><tr>'
            . '<th style="width:6%">#</th><th>Uczestnik</th><th style="width:14%">Ocena końcowa</th>'
            . '<th style="width:14%">Średnia z dziennika</th><th style="width:26%">Uwagi</th>'
            . '</tr></thead><tbody>' . ($rows ?: '<tr><td colspan="5">Brak uczestników.</td></tr>') . '</tbody></table>'
            . '<p class="trace">' . $trace . '</p>'
            . '<p class="sign">.............................................<br>podpis prowadzącego</p>';

        $mpdf = new \Mpdf\Mpdf([
            'mode' => 'utf-8', 'format' => 'A4',
            'margin_left' => 18, 'margin_right' => 16, 'margin_top' => 16, 'margin_bottom' => 16,
            'default_font' => 'dejavuserif', 'tempDir' => $mpdf_tmp,
        ]);
        $mpdf->SetTitle('Protokół ocen — ' . (string)($prot['course_name'] ?? ''));
        $mpdf->SetAuthor(org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : 'FEER'));
        $mpdf->WriteHTML(
            'body { font-family:"DejaVu Serif",serif; font-size:10pt; color:#000; }
             h1 { font-size:14pt; margin:0 0 3mm; }
             table { border-collapse:collapse; width:100%; }
             table.head th { text-align:left; width:30%; background:#f4f6f8; }
             table.head th, table.head td { border:.2mm solid #ccc; padding:1.3mm 2mm; font-size:9.5pt; }
             table.items { margin-top:5mm; }
             table.items th { background:#eef1f4; border:.2mm solid #999; padding:1.3mm 2mm; font-size:9pt; text-align:left; }
             table.items td { border:.2mm solid #999; padding:1.3mm 2mm; font-size:9.5pt; }
             td.c { text-align:center; }
             p.trace { margin-top:5mm; font-size:9pt; color:#333; }
             p.sign { margin-top:14mm; font-size:9pt; text-align:right; }',
            \Mpdf\HTMLParserMode::HEADER_CSS
        );
        $mpdf->WriteHTML($html, \Mpdf\HTMLParserMode::HTML_BODY);
        return $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
    } catch (\Throwable $e) {
        return null;
    }
}

/** Nazwa pliku PDF protokołu. */
function ti_protocol_pdf_filename(array $prot): string {
    $base = 'protokol-' . (string)($prot['course_name'] ?? 'zajecia') . '-' . (string)($prot['period_name'] ?? 'okres');
    $base = preg_replace('/[^A-Za-z0-9_\-]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $base) ?: $base);
    return trim((string)$base, '-') . '.pdf';
}
