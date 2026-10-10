<?php
/**
 * modules/ti_meetings/logic/tiMeetingsManager.php — Protokoły spotkań TI: operacje.
 *
 * Schemat w tiMeetingsSchema.php. Uprawnienia: prowadzący kursu (główny lub współprowadzący)
 * i kierownik — sprawdzane przez dyd_owns_course() po stronie widoku.
 * Podpis wewnętrzny: kto, kiedy i sha256 treści protokołu (bez podpisu kwalifikowanego).
 */
require_once __DIR__ . '/tiMeetingsSchema.php';
require_once dirname(__DIR__, 3) . '/includes/ti_periods.php';
require_once dirname(__DIR__, 3) . '/includes/ti_protocols.php';

const TI_MEETING_KINDS = [
    'obecnosc' => 'Obecność',
    'przebieg' => 'Przebieg zajęć',
    'uchwala'  => 'Uchwała',
    'zadanie'  => 'Zadanie',
    'inne'     => 'Inne',
];

const TI_MEETING_STATUS_LABELS = [
    'szkic'            => 'Szkic',
    'do_zatwierdzenia' => 'Do zatwierdzenia',
    'zatwierdzony'     => 'Zatwierdzony',
    'odblokowany'      => 'Odblokowany',
];

function ti_meeting_get(int $id): ?array {
    ti_meetings_migrate();
    return $id > 0 ? db_one("SELECT m.*, c.name AS course_name FROM k30_ti_meetings m JOIN k30_ti_courses c ON c.id=m.course_id WHERE m.id=?", [$id]) : null;
}

function ti_meeting_items(int $meeting_id): array {
    return db_all("SELECT * FROM k30_ti_meeting_items WHERE meeting_id=? ORDER BY position, id", [$meeting_id]);
}

/** Lista spotkań kursu, najnowsze pierwsze, z liczbą punktów. */
function ti_meeting_list(int $course_id): array {
    ti_meetings_migrate();
    return db_all(
        "SELECT m.id, m.title, m.meeting_date, m.status,
                (SELECT COUNT(*) FROM k30_ti_meeting_items i WHERE i.meeting_id=m.id) AS items_n
           FROM k30_ti_meetings m WHERE m.course_id=? ORDER BY m.meeting_date DESC, m.id DESC",
        [$course_id]
    );
}

function ti_meeting_create(int $course_id, int $period_id, string $title, string $date, int $uid, string $name): int {
    ti_meetings_migrate();
    $id = db_insert('k30_ti_meetings', [
        'course_id' => $course_id, 'period_id' => $period_id, 'title' => mb_substr(trim($title), 0, 200), 'meeting_date' => $date,
        'status' => 'szkic', 'created_by' => $uid ?: null,
    ]);
    ti_meeting_log($id, 'utworzono', '', $uid, $name);
    return $id;
}

function ti_meeting_add_item(int $meeting_id, string $kind, string $title, string $body): int {
    $pos = (int)(db_one("SELECT COALESCE(MAX(position),0)+1 AS p FROM k30_ti_meeting_items WHERE meeting_id=?", [$meeting_id])['p'] ?? 1);
    return db_insert('k30_ti_meeting_items', [
        'meeting_id' => $meeting_id, 'kind' => $kind, 'position' => $pos,
        'title' => mb_substr(trim($title), 0, 200), 'body' => trim($body),
    ]);
}

function ti_meeting_delete_item(int $item_id, int $meeting_id): void {
    db_exec("DELETE FROM k30_ti_meeting_items WHERE id=? AND meeting_id=?", [$item_id, $meeting_id]);
}

/**
 * Kursanci grupy za okres protokołu: godziny (odbyte zajęcia, obecność) i średnia ocen z dziennika.
 * Te same źródła co protokół godzin — wartości nie są przepisywane.
 * @return array<int, array{client_id:int,name:string,hours:float,avg:?float}>
 */
function ti_meeting_participant_rows(int $course_id, int $period_id): array {
    $per = $period_id ? ti_period_get($period_id) : null;
    if (!$per) return [];
    $from = (string)$per['date_from']; $to = (string)$per['date_to'];
    $people = db_all(
        "SELECT e.client_id, cl.name FROM k30_ti_enrollments e
           JOIN k30_clients cl ON cl.id = e.client_id
          WHERE e.course_id=? AND e.status='active' ORDER BY cl.name", [$course_id]);
    $mins = [];
    foreach (db_all(
        "SELECT a.client_id, SUM(s.duration_min) AS mins
           FROM k30_ti_attendance a JOIN k30_ti_sessions s ON s.id = a.session_id
          WHERE s.course_id=? AND s.lesson_date BETWEEN ? AND ?
            AND s.status IN ('held','individual_change','remote_material')
            AND a.attended=1 AND COALESCE(a.cancelled,0)=0
          GROUP BY a.client_id", [$course_id, $from, $to]) as $r) {
        $mins[(int)$r['client_id']] = (int)$r['mins'];
    }
    $avgs = ti_protocol_diary_averages($course_id, $from, $to);
    $out = [];
    foreach ($people as $p) {
        $cid = (int)$p['client_id'];
        $out[] = ['client_id' => $cid, 'name' => (string)$p['name'],
                  'hours' => round(($mins[$cid] ?? 0) / 60, 1),
                  'avg' => isset($avgs[$cid]) ? round((float)$avgs[$cid], 2) : null];
    }
    return $out;
}

/** Skrót treści: tytuł, data i wszystkie punkty w kolejności — wykrywa późniejszą zmianę po podpisie. */
function ti_meeting_content_hash(array $meeting, array $items, array $rows = []): string {
    $payload = [
        'title' => (string)$meeting['title'],
        'date'  => (string)$meeting['meeting_date'],
        'items' => array_map(fn($i) => [(string)$i['kind'], (string)$i['title'], (string)$i['body']], $items),
        'rows'  => array_map(fn($r) => [$r['client_id'], $r['hours'], $r['avg']], $rows),
    ];
    return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
}

/** Zatwierdzenie przez prowadzącego/kierownika: podpis wewnętrzny (kto, kiedy, hash). */
function ti_meeting_approve(int $meeting_id, int $uid, string $name): void {
    $m = ti_meeting_get($meeting_id);
    if (!$m) throw new \RuntimeException('Nie ma takiego protokołu.');
    $items = ti_meeting_items($meeting_id);
    if (!$items) throw new \RuntimeException('Protokół nie ma żadnego punktu — dodaj treść przed zatwierdzeniem.');
    $rows = ti_meeting_participant_rows((int)$m['course_id'], (int)($m['period_id'] ?? 0));
    $hash = ti_meeting_content_hash($m, $items, $rows);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        db_insert('k30_ti_meeting_signatures', [
            'meeting_id' => $meeting_id, 'role' => 'prowadzacy', 'user_id' => $uid ?: null,
            'signed_name' => mb_substr(trim($name), 0, 140), 'content_hash' => $hash,
        ]);
        db_exec("UPDATE k30_ti_meetings SET status='zatwierdzony', version=version+1, updated_at=datetime('now') WHERE id=?", [$meeting_id]);
        ti_meeting_log($meeting_id, 'zatwierdzono', 'hash ' . substr($hash, 0, 12), $uid, $name);
        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** Zmiany treści są możliwe tylko w szkicu (po zatwierdzeniu — najpierw odblokowanie, osobna funkcja). */
function ti_meeting_is_editable(array $m): bool {
    return in_array((string)$m['status'], ['szkic', 'odblokowany'], true);
}

function ti_meeting_log(int $meeting_id, string $action, string $reason, int $uid, string $name): void {
    try {
        db_insert('k30_ti_meeting_log', [
            'meeting_id' => $meeting_id, 'action' => mb_substr($action, 0, 60), 'reason' => mb_substr($reason, 0, 500),
            'by_user_id' => $uid ?: null, 'by_name' => mb_substr($name, 0, 140),
        ]);
    } catch (\Throwable $e) {}
}

/**
 * Przeniesienie: każdy stary protokół godzinowy (k30_ti_protocols) jest KOPIOWANY jako protokół zajęć.
 * Stare tabele zostają nietknięte. Idempotentne: legacy_protocol_id nie dopuszcza duplikatów.
 * Zatwierdzony protokół godzin dostaje podpis wewnętrzny z datą i osobą z oryginału.
 */
function ti_meetings_import_legacy(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    if (szo_schema_current('ti_meetings_import_legacy', __FILE__)) return;   // raz na wersję pliku
    ti_meetings_migrate();
    try {
        $rows = db_all(
            "SELECT p.*, per.date_to AS period_to
               FROM k30_ti_protocols p
               LEFT JOIN k30_ti_periods per ON per.id = p.period_id
              WHERE NOT EXISTS (SELECT 1 FROM k30_ti_meetings m WHERE m.legacy_protocol_id = p.id)"
        );
    } catch (\Throwable $e) { error_log('ti_meetings_import_legacy: ' . $e->getMessage()); return; }
    foreach ($rows as $r) {
        $approved = (string)$r['status'] === 'approved';
        $ym = (string)($r['year_month'] ?? '');
        $date = !empty($r['period_to']) ? (string)$r['period_to']
              : (preg_match('/^\d{4}-\d{2}$/', $ym) ? date('Y-m-t', strtotime($ym . '-01'))
              : substr((string)($r['approved_at'] ?: date('Y-m-d')), 0, 10));
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $mid = db_insert('k30_ti_meetings', [
                'course_id' => (int)$r['course_id'], 'period_id' => $r['period_id'] ?: null,
                'title' => (string)($r['title'] ?: 'Protokół godzin'), 'meeting_date' => $date,
                'status' => $approved ? 'zatwierdzony' : 'szkic', 'legacy_protocol_id' => (int)$r['id'],
                'created_by' => $r['created_by'] ?: null,
            ]);
            if ($approved) {
                $payload = json_encode([(int)$r['id'], (int)$r['course_id'], $r['period_id'], $ym, (string)$r['approved_at'], (string)$r['approved_name']]);
                db_insert('k30_ti_meeting_signatures', [
                    'meeting_id' => $mid, 'role' => 'prowadzacy', 'user_id' => $r['approved_by'] ?: null,
                    'signed_name' => (string)$r['approved_name'], 'content_hash' => hash('sha256', (string)$payload),
                    'signed_at' => (string)($r['approved_at'] ?: date('Y-m-d H:i:s')),
                ]);
            }
            ti_meeting_log($mid, 'przeniesiono', 'z protokołu godzin #' . (int)$r['id'], 0, 'migracja');
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            error_log('ti_meetings_import_legacy #' . $r['id'] . ': ' . $e->getMessage());
        }
    }
}

/**
 * Odblokowanie zatwierdzonego protokołu: wymaga powodu, zostaje w logu.
 * Po odblokowaniu treść można zmieniać i zatwierdzić ponownie (nowy podpis).
 */
function ti_meeting_unlock(int $meeting_id, string $reason, int $uid, string $name): void {
    $m = ti_meeting_get($meeting_id);
    if (!$m) throw new \RuntimeException('Nie ma takiego protokołu.');
    if ((string)$m['status'] !== 'zatwierdzony') throw new \RuntimeException('Ten protokół nie jest zatwierdzony.');
    $reason = trim($reason);
    if (mb_strlen($reason) < 10) throw new \RuntimeException('Podaj powód odblokowania (min. 10 znaków).');
    db_exec("UPDATE k30_ti_meetings SET status='odblokowany', updated_at=datetime('now') WHERE id=?", [$meeting_id]);
    ti_meeting_log($meeting_id, 'odblokowano', $reason, $uid, $name);
}
