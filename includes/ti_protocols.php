<?php
/**
 * includes/ti_protocols.php — Protokoły zajęć kursu za okres (panel prowadzącego).
 *
 * Protokół zamyka jeden kurs za jeden okres nauczania i obejmuje CAŁE rozliczenie
 * zajęć, nie tylko oceny: oceny końcowe uczestników, ewidencję godzin prowadzącego,
 * naliczenie wypłaty oraz dwa podpisy elektroniczne (prowadzący i za organizatora).
 * Zatwierdzenie ocen zamyka wpisy ze śladem (kto, kiedy).
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

    // Elektroniczne podpisy: prowadzącego (ewidencja godzin i wypłata)
    // oraz za organizatora (kontrasygnata kierownika / pracownika D3)
    foreach ([
        "ALTER TABLE k30_ti_protocols ADD COLUMN hours_ack_by   INTEGER",
        "ALTER TABLE k30_ti_protocols ADD COLUMN hours_ack_name TEXT NOT NULL DEFAULT ''",
        "ALTER TABLE k30_ti_protocols ADD COLUMN hours_ack_at   TEXT",
        "ALTER TABLE k30_ti_protocols ADD COLUMN hours_ack_ip   TEXT NOT NULL DEFAULT ''",
        "ALTER TABLE k30_ti_protocols ADD COLUMN org_ack_by     INTEGER",
        "ALTER TABLE k30_ti_protocols ADD COLUMN org_ack_name   TEXT NOT NULL DEFAULT ''",
        "ALTER TABLE k30_ti_protocols ADD COLUMN org_ack_at     TEXT",
        "ALTER TABLE k30_ti_protocols ADD COLUMN org_ack_ip     TEXT NOT NULL DEFAULT ''",
    ] as $sql) {
        try { db()->exec($sql); } catch (\Throwable $e) {}
    }
}

/** Etykieta stanu protokołu. */
function ti_protocol_status_label(string $status): string {
    return TI_PROTOCOL_STATUSES[$status]['label'] ?? $status;
}

/**
 * Bramka: protokoły za okres muszą być otwarte przez administrację.
 * Protokoły bez wskazanego okresu (z czasów, gdy było to możliwe) przepuszczamy,
 * żeby dały się domknąć.
 *
 * @throws RuntimeException gdy administracja zamknęła protokoły za ten okres.
 */
function ti_protocol_require_period_open(array $prot): void {
    $pid = (int)($prot['period_id'] ?? 0);
    if (!$pid) return;
    require_once __DIR__ . '/ti_periods.php';
    if (!ti_period_protocols_open($pid)) {
        throw new \RuntimeException(ti_period_protocols_closed_msg(ti_period_get($pid)));
    }
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
        'msg' => 'Niedozwolony wpis „' . $raw . '”. Dozwolone: 1–6 (można z + lub -) albo '
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
    require_once __DIR__ . '/ti_periods.php';
    if (!$course_id) throw new \RuntimeException('Brak kursu.');

    // Protokół zajęć dotyczy zawsze okresu, a okres do rozliczenia otwiera
    // administracja — bez tego prowadzący nie zakłada protokołu.
    if (!$period_id) {
        throw new \RuntimeException('Wskaż okres nauczania — protokół zajęć zawsze dotyczy okresu.');
    }
    if (!ti_period_protocols_open($period_id)) {
        throw new \RuntimeException(ti_period_protocols_closed_msg(ti_period_get($period_id)));
    }

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
        $title = $per
            ? 'Protokół zajęć za okres: ' . (string)$per['name']
            : 'Protokół zajęć bez wskazanego okresu';
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
    ti_protocol_require_period_open($prot);

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
 *
 * Protokół BEZ OCEN też można zatwierdzić — bywa, że w okresie nikomu oceny nie
 * postawiono (kurs bez oceniania, same zajęcia praktyczne, brak uczestników),
 * a okres i tak trzeba rozliczyć. Taki protokół jest wystawiony jako pusty:
 * wydruk zawiera adnotację, że nie wystawiono żadnej oceny (patrz ti_protocol_pdf).
 *
 * @throws RuntimeException gdy protokół nie istnieje albo jest już zatwierdzony.
 */
function ti_protocol_approve(int $protocol_id, ?int $by, string $by_name): void {
    ti_protocols_migrate();
    $prot = ti_protocol_get($protocol_id);
    if (!$prot) throw new \RuntimeException('Protokół nie istnieje.');
    if (ti_protocol_is_locked($prot)) throw new \RuntimeException('Protokół jest już zatwierdzony.');
    ti_protocol_require_period_open($prot);

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
                'Okres „' . (string)$per['name'] . '” jest zamknięty — aby poprawić protokół, '
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

    // Otwarty protokół to zmienione dane — potwierdzenie ewidencji przestaje
    // odpowiadać stanowi, więc trzeba je złożyć ponownie.
    ti_protocol_hours_ack_clear($protocol_id);
}

/** Czy prowadzący potwierdził ewidencję godzin i naliczenie wypłaty. */
function ti_protocol_hours_acked(array $prot): bool {
    return !empty($prot['hours_ack_at']);
}

/**
 * Potwierdzenie ewidencji godzin i naliczenia wypłaty przez prowadzącego —
 * elektroniczny odpowiednik podpisu na wydruku. Zapisuje kto, kiedy i z jakiego
 * adresu IP; potwierdzenie jest jednorazowe (do wycofania przez odblokowanie
 * protokołu, tak samo jak zatwierdzenie ocen).
 *
 * @throws RuntimeException gdy protokół nie istnieje albo już potwierdzony.
 */
function ti_protocol_hours_ack(int $protocol_id, ?int $by, string $by_name, string $ip = ''): void {
    ti_protocols_migrate();
    $prot = ti_protocol_get($protocol_id);
    if (!$prot)                            throw new \RuntimeException('Protokół nie istnieje.');
    if (ti_protocol_hours_acked($prot))    throw new \RuntimeException('Ewidencja godzin jest już potwierdzona.');

    $ip = trim($ip) !== '' ? trim($ip) : (string)($_SERVER['REMOTE_ADDR'] ?? '');
    db()->prepare(
        "UPDATE k30_ti_protocols
            SET hours_ack_by=?, hours_ack_name=?, hours_ack_at=datetime('now'), hours_ack_ip=?,
                updated_at=datetime('now')
          WHERE id=?"
    )->execute([$by, $by_name, substr($ip, 0, 64), $protocol_id]);
}

/** Czy protokół jest podpisany za organizatora. */
function ti_protocol_org_acked(array $prot): bool {
    return !empty($prot['org_ack_at']);
}

/**
 * Podpis za organizatora — elektroniczna kontrasygnata kierownika / pracownika D3
 * (uprawnienie sprawdza wywołujący). Podpisujemy dokument gotowy, więc wymagany
 * jest zatwierdzony protokół; brak potwierdzenia prowadzącego nie blokuje podpisu,
 * ale panel pokazuje ten stan wprost, żeby nikt nie kontrasygnował w ciemno.
 *
 * @throws RuntimeException gdy protokół nie istnieje, nie jest zatwierdzony
 *                          albo jest już podpisany.
 */
function ti_protocol_org_ack(int $protocol_id, ?int $by, string $by_name, string $ip = ''): void {
    ti_protocols_migrate();
    $prot = ti_protocol_get($protocol_id);
    if (!$prot)                          throw new \RuntimeException('Protokół nie istnieje.');
    if (!ti_protocol_is_locked($prot))   throw new \RuntimeException('Najpierw zatwierdź protokół — podpisuje się dokument zamknięty.');
    if (ti_protocol_org_acked($prot))    throw new \RuntimeException('Protokół jest już podpisany za organizatora.');

    $ip = trim($ip) !== '' ? trim($ip) : (string)($_SERVER['REMOTE_ADDR'] ?? '');
    db()->prepare(
        "UPDATE k30_ti_protocols
            SET org_ack_by=?, org_ack_name=?, org_ack_at=datetime('now'), org_ack_ip=?,
                updated_at=datetime('now')
          WHERE id=?"
    )->execute([$by, $by_name, substr($ip, 0, 64), $protocol_id]);
}

/**
 * Wycofuje oba podpisy (wołane przy odblokowaniu protokołu) — po korekcie danych
 * ani ewidencja, ani kontrasygnata nie odpowiadają już stanowi dokumentu.
 */
function ti_protocol_hours_ack_clear(int $protocol_id): void {
    ti_protocols_migrate();
    db_exec(
        "UPDATE k30_ti_protocols
            SET hours_ack_by=NULL, hours_ack_name='', hours_ack_at=NULL, hours_ack_ip='',
                org_ack_by=NULL,   org_ack_name='',   org_ack_at=NULL,   org_ack_ip='',
                updated_at=datetime('now')
          WHERE id=?",
        [$protocol_id]
    );
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

/** Czy w protokole nie ma ani jednej oceny (protokół pusty). */
function ti_protocol_is_empty(array $stats): bool {
    return (int)$stats['filled'] === 0;
}

/** Adnotacja na wydruk pustego protokołu. */
function ti_protocol_empty_note(array $stats): string {
    return $stats['total'] === 0
        ? 'ADNOTACJA: W okresie objętym protokołem do zajęć nie był zapisany żaden uczestnik — protokół pozostaje pusty.'
        : 'ADNOTACJA: W okresie objętym protokołem nie wystawiono żadnej oceny. Protokół zostaje wystawiony jako pusty dla '
          . $stats['total'] . ' ' . ($stats['total'] === 1 ? 'uczestnika' : 'uczestników') . '.';
}

/** Krótki opis wypełnienia, np. „częściowo wypełniony (3 z 8)”. */
function ti_protocol_fill_text(array $stats): string {
    if ($stats['total'] === 0)                 return 'brak uczestników';
    if ($stats['filled'] === 0)                return 'pusty (0 z ' . $stats['total'] . ')';
    if ($stats['filled'] < $stats['total'])    return 'częściowo wypełniony (' . $stats['filled'] . ' z ' . $stats['total'] . ')';
    return 'wypełniony (' . $stats['total'] . ' z ' . $stats['total'] . ')';
}

/**
 * Średnia ważona z e-dziennika per uczestnik (do kolumny pomocniczej w protokole).
 *
 * Liczona TYLKO z ocen wystawionych w okresie protokołu ($from/$to — daty
 * okresu nauczania), a nie od dnia utworzenia grupy: k30_ti_course_grades()
 * zwraca całą historię ocen kursu (e-dziennik pokazuje ją w całości celowo),
 * więc bez tego ograniczenia protokół za np. wrzesień pokazywałby średnią
 * wliczającą też oceny z czerwca czy lipca. Ocena bez powiązanej lekcji
 * (session_id NULL) liczy się po dacie wystawienia (graded_at).
 *
 * @return array<int, float> client_id => średnia
 */
function ti_protocol_diary_averages(int $course_id, ?string $from = null, ?string $to = null): array {
    $by_client = [];
    foreach (k30_ti_course_grades($course_id) as $g) {
        if ($from !== null && $to !== null) {
            $gdate = substr((string)($g['session_date'] ?? '') ?: (string)($g['graded_at'] ?? ''), 0, 10);
            if ($gdate === '' || $gdate < $from || $gdate > $to) continue;
        }
        $by_client[(int)$g['client_id']][] = $g;
    }
    $out = [];
    foreach ($by_client as $cid => $grades) {
        $avg = k30_ti_grades_average($grades);
        if ($avg !== null) $out[$cid] = $avg;
    }
    return $out;
}

/**
 * Ewidencja godzin prowadzącego i naliczenie wypłaty za okres protokołu.
 *
 * Liczy tak samo, jak zakładka „Wypłaty” i k30_ti_payouts_by_instructor():
 * stawka za zajęcia jest na kursie (lesson_payout_bb), liczą się zajęcia
 * odbyte (held / individual_change / remote_material), a praca własna
 * (self_prep_remote) i formy student/B2B są bezskładkowe.
 *
 * @return array{from:string, to:string, rows:array, lessons:int, total_min:int,
 *               bb:float, payout:array, instructor:string, form:string, has_rate:bool}
 */
function ti_protocol_hours_and_payout(array $prot): array {
    $course_id = (int)$prot['course_id'];

    // Zakres: okres protokołu, a bez okresu — całe życie kursu
    $from = (string)($prot['date_from'] ?? '');
    $to   = (string)($prot['date_to']   ?? '');
    if ($from === '' || $to === '') {
        $r    = db_one("SELECT MIN(lesson_date) AS f, MAX(lesson_date) AS t FROM k30_ti_sessions WHERE course_id=?", [$course_id]);
        $from = (string)($r['f'] ?? date('Y-m-d'));
        $to   = (string)($r['t'] ?? date('Y-m-d'));
    }

    $c = db_one(
        "SELECT c.lesson_payout_bb, COALESCE(u.name,'') AS iname,
                COALESCE(u.ti_payout_form, CASE WHEN COALESCE(u.ti_is_student,0)=1 THEN 'student' ELSE 'zlecenie' END) AS payout_form
           FROM k30_ti_courses c
           LEFT JOIN users u ON u.id = c.instructor_id
          WHERE c.id=?",
        [$course_id]
    ) ?: [];
    $bb          = (float)($c['lesson_payout_bb'] ?? 0);
    $form        = (string)($c['payout_form'] ?? 'zlecenie');
    $form_exempt = in_array($form, ['student', 'b2b'], true);

    $sessions = db_all(
        "SELECT * FROM k30_ti_sessions
          WHERE course_id=? AND lesson_date BETWEEN ? AND ?
            AND status IN ('held','individual_change','remote_material')
          ORDER BY lesson_date, time_from",
        [$course_id, $from, $to]
    );

    $rows = []; $total_min = 0; $acc = _k30_ti_payout_zero();
    foreach ($sessions as $s) {
        $min = (int)($s['duration_min'] ?? 0);
        if ($min <= 0 && $s['time_from'] && $s['time_to']) {
            $min = max(0, ti_hm2min((string)$s['time_to']) - ti_hm2min((string)$s['time_from']));
        }
        $total_min += $min;

        $b = null;
        if ($bb > 0) {
            $b = k30_ti_payout_breakdown($bb, $form_exempt || !empty($s['self_prep_remote']));
            _k30_ti_payout_accumulate($acc, $b);
        }
        $rows[] = [
            'date'    => (string)$s['lesson_date'],
            'from'    => substr((string)$s['time_from'], 0, 5),
            'to'      => substr((string)$s['time_to'], 0, 5),
            'min'     => $min,
            'topic'   => (string)($s['topic'] ?? ''),
            'status'  => (string)$s['status'],
            'own'     => !empty($s['self_prep_remote']),
            'bb'      => $b ? (float)$b['brutto_brutto'] : 0.0,
            'netto'   => $b ? (float)$b['netto'] : 0.0,
        ];
    }

    return [
        'from' => $from, 'to' => $to, 'rows' => $rows,
        'lessons' => count($rows), 'total_min' => $total_min,
        'bb' => $bb, 'payout' => $acc,
        'instructor' => (string)($c['iname'] ?? ''), 'form' => $form,
        'has_rate' => $bb > 0,
    ];
}

/** Minuty → „12 h 30 min” (na wydruk ewidencji). */
function ti_protocol_hm(int $min): string {
    if ($min <= 0) return '0 h';
    $h = intdiv($min, 60); $m = $min % 60;
    return ($h ? $h . ' h' : '') . ($h && $m ? ' ' : '') . ($m ? $m . ' min' : '');
}

/** Kwota w formacie polskim, np. „1 234,50 zł”. */
function ti_protocol_money(float $v): string {
    return number_format($v, 2, ',', ' ') . ' zł';
}

/** Etykieta statusu zajęć na ewidencji. */
function ti_protocol_status_lesson(string $status, bool $own): string {
    if ($own) return 'praca własna';
    return [
        'held'              => 'odbyte',
        'individual_change' => 'zmiana indywidualna',
        'remote_material'   => 'praca własna',
    ][$status] ?? $status;
}

/**
 * Treść protokołu jako HTML do wydruku (wydzielona z ti_protocol_pdf, żeby dało
 * się ją sprawdzić bez generowania PDF — wzorzec jak ti_syllabus_print_html).
 * Protokół bez ocen dostaje wyraźną adnotację o braku ocen.
 */
function ti_protocol_print_html(array $prot): string {
    $course_id = (int)$prot['course_id'];
    $parts     = ti_protocol_participants($course_id);
    $entries   = ti_protocol_entries((int)$prot['id']);
    $avgs      = ti_protocol_diary_averages($course_id, $prot['date_from'] ?? null, $prot['date_to'] ?? null);
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
            . '<td class="c">' . $h(($e['value_text'] ?? '') !== '' ? $e['value_text'] : '—') . '</td>'
            . '<td class="c">' . (isset($avgs[$cid]) ? number_format($avgs[$cid], 2, ',', '') : '—') . '</td>'
            . '<td>' . $h($e['note'] ?? '') . '</td>'
            . '</tr>';
    }

    // ── 2. Ewidencja godzin i 3. Wypłata ─────────────────────────────────────
    $hp = ti_protocol_hours_and_payout($prot);

    $ev = '<h2>2. Ewidencja godzin prowadzącego</h2>';
    $ev .= '<p class="sub">Prowadzący: <strong>' . $h($hp['instructor'] !== '' ? $hp['instructor'] : '—')
        . '</strong> · zakres: ' . $h(date('d.m.Y', strtotime($hp['from'])))
        . '–' . $h(date('d.m.Y', strtotime($hp['to']))) . '</p>';
    if (!$hp['rows']) {
        $ev .= '<p class="empty">W tym zakresie nie ma zajęć odbytych — ewidencja jest pusta.</p>';
    } else {
        $ev .= '<table class="items"><thead><tr>'
            . '<th style="width:6%">#</th><th style="width:14%">Data</th><th style="width:16%">Godziny</th>'
            . '<th style="width:12%">Czas</th><th>Temat</th><th style="width:18%">Rodzaj</th>'
            . ($hp['has_rate'] ? '<th style="width:16%">Wypłata brutto-brutto</th>' : '')
            . '</tr></thead><tbody>';
        $i = 0;
        foreach ($hp['rows'] as $r) {
            $i++;
            $ev .= '<tr>'
                . '<td>' . $i . '.</td>'
                . '<td>' . $h(date('d.m.Y', strtotime($r['date']))) . '</td>'
                . '<td>' . $h($r['from'] !== '' ? $r['from'] . '–' . $r['to'] : '—') . '</td>'
                . '<td>' . $h(ti_protocol_hm((int)$r['min'])) . '</td>'
                . '<td>' . $h($r['topic']) . '</td>'
                . '<td>' . $h(ti_protocol_status_lesson((string)$r['status'], (bool)$r['own'])) . '</td>'
                . ($hp['has_rate'] ? '<td class="r">' . $h(ti_protocol_money((float)$r['bb'])) . '</td>' : '')
                . '</tr>';
        }
        $ev .= '<tr class="sum"><td colspan="3">Razem</td>'
            . '<td>' . $h(ti_protocol_hm((int)$hp['total_min'])) . '</td>'
            . '<td colspan="2">' . (int)$hp['lessons'] . ' ' . ($hp['lessons'] === 1 ? 'zajęcie' : 'zajęć') . '</td>'
            . ($hp['has_rate'] ? '<td class="r">' . $h(ti_protocol_money((float)$hp['payout']['brutto_brutto'])) . '</td>' : '')
            . '</tr>';
        $ev .= '</tbody></table>';
    }

    $pay = '<h2>3. Naliczenie wypłaty</h2>';
    if (!$hp['has_rate']) {
        $pay .= '<p class="empty">Dla tych zajęć nie ustawiono stawki za zajęcie (lesson_payout_bb = 0), '
              . 'więc wypłata nie jest naliczana. Ewidencja godzin powyżej pozostaje wiążąca.</p>';
    } else {
        $P = $hp['payout'];
        $pay .= '<table class="head"><tbody>'
            . '<tr><th>Stawka za zajęcie (brutto-brutto)</th><td>' . $h(ti_protocol_money((float)$hp['bb'])) . '</td></tr>'
            . '<tr><th>Zajęcia rozliczone</th><td>' . (int)$P['lessons'] . '</td></tr>'
            . '<tr><th>Suma brutto-brutto (koszt)</th><td>' . $h(ti_protocol_money((float)$P['brutto_brutto'])) . '</td></tr>'
            . '<tr><th>ZUS pracodawcy</th><td>' . $h(ti_protocol_money((float)$P['zus_employer'])) . '</td></tr>'
            . '<tr><th>Brutto (wynagrodzenie)</th><td>' . $h(ti_protocol_money((float)$P['brutto'])) . '</td></tr>'
            . '<tr><th>Składki potrącone</th><td>' . $h(ti_protocol_money((float)$P['skladki'])) . '</td></tr>'
            . '<tr><th>Zaliczka PIT</th><td>' . $h(ti_protocol_money((float)$P['pit'])) . '</td></tr>'
            . '<tr><th>Do wypłaty netto</th><td><strong>' . $h(ti_protocol_money((float)$P['netto'])) . '</strong></td></tr>'
            . '</tbody></table>';
        $pay .= '<p class="sub">Forma rozliczenia: ' . $h($hp['form'])
              . '. Praca własna prowadzącego liczona jest bezskładkowo.</p>';
    }

    $acked = ti_protocol_hours_acked($prot);
    $sign_instructor = $acked
        ? 'Potwierdzone elektronicznie w panelu:<br><strong>' . $h($prot['hours_ack_name'] ?: '—') . '</strong><br>'
          . $h(date('d.m.Y H:i', strtotime((string)$prot['hours_ack_at'])))
          . ($prot['hours_ack_ip'] !== '' ? '<br>IP ' . $h($prot['hours_ack_ip']) : '')
        : '.............................................<br>data i podpis prowadzącego';

    $org_acked = ti_protocol_org_acked($prot);
    $sign_org  = $org_acked
        ? 'Podpisane elektronicznie za organizatora:<br><strong>' . $h($prot['org_ack_name'] ?: '—') . '</strong><br>'
          . $h(date('d.m.Y H:i', strtotime((string)$prot['org_ack_at'])))
          . ($prot['org_ack_ip'] !== '' ? '<br>IP ' . $h($prot['org_ack_ip']) : '')
        : '.............................................<br>za organizatora';

    $statement = '<div class="stmt">'
        . '<p class="stmt-h">Oświadczenie prowadzącego</p>'
        . '<p>Potwierdzam, że ewidencja godzin oraz naliczenie wypłaty w tym protokole '
        . 'są zgodne ze stanem faktycznym — zajęcia w wykazanych terminach odbyły się '
        . 'w podanym wymiarze, a wykazane kwoty nie budzą moich zastrzeżeń.</p>'
        . ($acked ? '' : '<p class="empty">Oświadczenie niepotwierdzone — wymaga podpisu prowadzącego.</p>')
        . '<table class="signs"><tbody><tr>'
        . '<td>' . $sign_instructor . '</td>'
        . '<td>' . $sign_org . '</td>'
        . '</tr></tbody></table></div>';

    $empty_note = ti_protocol_is_empty($stats)
        ? '<p class="empty-note">' . $h(ti_protocol_empty_note($stats)) . '</p>'
        : '';

    $trace = ti_protocol_is_locked($prot)
        ? 'Zatwierdził: ' . $h($prot['approved_name'] ?: '—')
          . ', ' . $h($prot['approved_at'] ? date('d.m.Y H:i', strtotime((string)$prot['approved_at'])) : '—')
        : 'Protokół niezatwierdzony — wydruk roboczy.';
    if (!empty($prot['unlocked_at'])) {
        $trace .= '<br>Odblokowany: ' . $h($prot['unlocked_name'] ?: '—') . ', '
            . $h(date('d.m.Y H:i', strtotime((string)$prot['unlocked_at'])))
            . ' — powód: ' . $h($prot['unlock_reason']);
    }

    return '<h1>Protokół zajęć kursu za okres</h1>'
        . '<table class="head"><tbody>'
        . '<tr><th>Zajęcia</th><td>' . $h($prot['course_name'] ?? '') . '</td></tr>'
        . '<tr><th>Protokół</th><td>' . $h($prot['title']) . '</td></tr>'
        . '<tr><th>Okres</th><td>' . $h($prot['period_name'] ?: 'nie wskazano') . '</td></tr>'
        . '<tr><th>Stan</th><td>' . $h(ti_protocol_status_label((string)$prot['status']))
            . ' — ' . $h(ti_protocol_fill_text($stats))
            . (ti_protocol_is_empty($stats) ? ' — <strong>brak ocen</strong>' : '') . '</td></tr>'
        . '<tr><th>Wydruk</th><td>' . date('d.m.Y H:i') . '</td></tr>'
        . '</tbody></table>'
        . '<h2>1. Oceny końcowe</h2>'
        . '<table class="items"><thead><tr>'
        . '<th style="width:6%">#</th><th>Uczestnik</th><th style="width:14%">Ocena końcowa</th>'
        . '<th style="width:14%">Średnia z dziennika</th><th style="width:26%">Uwagi</th>'
        . '</tr></thead><tbody>' . ($rows ?: '<tr><td colspan="5">Brak uczestników.</td></tr>') . '</tbody></table>'
        . $empty_note
        . $ev
        . $pay
        . '<p class="trace">' . $trace . '</p>'
        . $statement;
}

/** Protokół jako bajty PDF (mPDF, dejavuserif) albo null przy błędzie. */
function ti_protocol_pdf(array $prot): ?string {
    try {
        require_once dirname(__DIR__) . '/vendor/autoload.php';

        $mpdf_tmp = UPLOAD_DIR . 'mpdf_tmp';
        if (!is_dir($mpdf_tmp)) @mkdir($mpdf_tmp, 0755, true);

        $mpdf = new \Mpdf\Mpdf([
            'mode' => 'utf-8', 'format' => 'A4',
            'margin_left' => 18, 'margin_right' => 16, 'margin_top' => 16, 'margin_bottom' => 16,
            'default_font' => 'dejavuserif', 'tempDir' => $mpdf_tmp,
        ]);
        $mpdf->SetTitle('Protokół zajęć kursu — ' . (string)($prot['course_name'] ?? '')
            . ((string)($prot['period_name'] ?? '') !== '' ? ', ' . (string)$prot['period_name'] : ''));
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
             p.empty-note { margin-top:5mm; padding:2.5mm 3mm; border:.3mm solid #333;
                            background:#f2f2f2; font-size:9.5pt; font-weight:bold; }
             p.trace { margin-top:5mm; font-size:9pt; color:#333; }
             h2 { font-size:11.5pt; margin:6mm 0 2mm; border-bottom:.3mm solid #999; padding-bottom:1mm; }
             p.sub { font-size:9pt; color:#333; margin:0 0 2mm; }
             td.r { text-align:right; }
             tr.sum td { background:#f2f2f2; font-weight:bold; }
             div.stmt { margin-top:6mm; border:.3mm solid #333; padding:3mm; }
             p.stmt-h { font-weight:bold; margin:0 0 1.5mm; font-size:10pt; }
             div.stmt p { font-size:9.5pt; margin:0 0 2mm; }
             table.signs td { width:50%; padding-top:12mm; font-size:9pt; text-align:center; border:none; }',
            \Mpdf\HTMLParserMode::HEADER_CSS
        );
        $mpdf->WriteHTML(ti_protocol_print_html($prot), \Mpdf\HTMLParserMode::HTML_BODY);
        return $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
    } catch (\Throwable $e) {
        return null;
    }
}

/**
 * Oceny końcowe kursanta z ZATWIERDZONYCH protokołów — dla panelu kursanta
 * i opiekuna. Protokół w toku nie jest deklaracją, więc go nie pokazujemy:
 * ocena pojawia się dopiero po zatwierdzeniu.
 *
 * @return array<int, array{course_name:string, period:string, value:string, note:string, approved_at:string, approved_name:string}>
 */
function ti_protocol_final_grades_for_client(int $client_id): array {
    ti_protocols_migrate();
    if (!$client_id) return [];
    try {
        return db_all(
            "SELECT c.id AS course_id, c.name AS course_name,
                    COALESCE(per.name, '') AS period,
                    e.value_text AS value, e.note,
                    p.approved_at, p.approved_name
               FROM k30_ti_protocol_entries e
               JOIN k30_ti_protocols p   ON p.id = e.protocol_id
               JOIN k30_ti_courses   c   ON c.id = p.course_id
               LEFT JOIN k30_ti_periods per ON per.id = p.period_id
              WHERE e.client_id = ? AND p.status = 'approved' AND e.value_text != ''
              ORDER BY COALESCE(per.date_from, p.approved_at) DESC, c.name COLLATE NOCASE",
            [$client_id]
        );
    } catch (\Throwable $e) { return []; }
}

/** Nazwa pliku PDF protokołu. */
function ti_protocol_pdf_filename(array $prot): string {
    $base = 'protokol-' . (string)($prot['course_name'] ?? 'zajecia') . '-' . (string)($prot['period_name'] ?? 'okres');
    $base = preg_replace('/[^A-Za-z0-9_\-]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $base) ?: $base);
    return trim((string)$base, '-') . '.pdf';
}
