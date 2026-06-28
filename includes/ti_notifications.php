<?php
/**
 * includes/ti_notifications.php — Centrum powiadomień kursanta TI.
 *
 * Feed wyliczany na żywo z istniejących danych (oceny, decyzje ws. terminu,
 * nowe zadania/materiały, wiadomości od prowadzącego) — bez osobnej tabeli
 * zdarzeń. Stan „przeczytane" = znacznik `notif_seen_at` na koncie kursanta;
 * pozycje nowsze niż znacznik liczą się jako nieprzeczytane.
 */

require_once __DIR__ . '/karty30.php';

function k30_ti_notif_migrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try { db()->exec("ALTER TABLE k30_ti_student_accounts ADD COLUMN notif_seen_at DATETIME"); } catch (\Throwable $e) {}
}

/** Znacznik ostatniego przejrzenia powiadomień przez kursanta. */
function k30_ti_notif_seen_at(int $account_id): string {
    k30_ti_notif_migrate();
    $r = db_one("SELECT notif_seen_at FROM k30_ti_student_accounts WHERE id=?", [$account_id]);
    return (string)($r['notif_seen_at'] ?? '');
}

/** Oznacz powiadomienia jako przejrzane (teraz). */
function k30_ti_notif_mark_seen(int $account_id): void {
    k30_ti_notif_migrate();
    db()->prepare("UPDATE k30_ti_student_accounts SET notif_seen_at=datetime('now') WHERE id=?")->execute([$account_id]);
}

/**
 * Zbiorczy, posortowany feed powiadomień dla kursanta.
 * Zwraca tablicę pozycji: ['ts','icon','title','url','tab'].
 */
function k30_ti_notifications_for_client(int $client_id, int $account_id, int $limit = 25): array {
    k30_ti_notif_migrate();
    $items = [];

    // 1) Nowe oceny
    foreach (db_all(
        "SELECT g.value_text, COALESCE(g.graded_at, g.created_at) AS ts, c.name AS course_name
         FROM k30_ti_grades g JOIN k30_ti_courses c ON c.id=g.course_id
         WHERE g.client_id=? ORDER BY ts DESC LIMIT 15", [$client_id]) as $r) {
        if (empty($r['ts'])) continue;
        $items[] = ['ts'=>$r['ts'], 'icon'=>'journal-bookmark', 'tab'=>'oceny', 'url'=>'?tab=oceny',
                    'title'=>'Nowa ocena: ' . $r['value_text'] . ' — ' . $r['course_name']];
    }

    // 2) Decyzje ws. propozycji zmiany terminu
    try {
        foreach (db_all(
            "SELECT r.status, r.decided_at AS ts, r.proposed_date, c.name AS course_name
             FROM k30_ti_reschedule_requests r
             JOIN k30_ti_sessions s ON s.id=r.session_id
             JOIN k30_ti_courses c ON c.id=s.course_id
             WHERE r.client_id=? AND r.status IN ('accepted','rejected') AND r.decided_at IS NOT NULL
             ORDER BY r.decided_at DESC LIMIT 10", [$client_id]) as $r) {
            $acc = $r['status'] === 'accepted';
            $items[] = ['ts'=>$r['ts'], 'icon'=>'calendar2-range', 'tab'=>'lekcje', 'url'=>'?tab=lekcje',
                        'title'=>'Propozycja terminu ' . ($acc ? 'zaakceptowana' : 'odrzucona') . ' — ' . $r['course_name']];
        }
    } catch (\Throwable $e) {}

    // 3) Nowe zadania domowe (w aktywnych kursach)
    foreach (db_all(
        "SELECT h.title, h.created_at AS ts, c.name AS course_name
         FROM k30_ti_homework h JOIN k30_ti_courses c ON c.id=h.course_id
         WHERE h.is_active=1 AND h.course_id IN (SELECT course_id FROM k30_ti_enrollments WHERE client_id=? AND status='active')
         ORDER BY h.created_at DESC LIMIT 10", [$client_id]) as $r) {
        if (empty($r['ts'])) continue;
        $items[] = ['ts'=>$r['ts'], 'icon'=>'journal-check', 'tab'=>'zadania', 'url'=>'?tab=zadania',
                    'title'=>'Nowe zadanie: ' . $r['title']];
    }

    // 4) Nowe materiały
    foreach (db_all(
        "SELECT m.title, m.created_at AS ts, c.name AS course_name
         FROM k30_ti_materials m JOIN k30_ti_courses c ON c.id=m.course_id
         WHERE m.is_active=1 AND m.course_id IN (SELECT course_id FROM k30_ti_enrollments WHERE client_id=? AND status='active')
         ORDER BY m.created_at DESC LIMIT 10", [$client_id]) as $r) {
        if (empty($r['ts'])) continue;
        $items[] = ['ts'=>$r['ts'], 'icon'=>'collection-play', 'tab'=>'zadania', 'url'=>'?tab=zadania',
                    'title'=>'Nowy materiał: ' . $r['title']];
    }

    // 5) Wiadomości od prowadzącego
    foreach (db_all(
        "SELECT subject, body, created_at AS ts FROM k30_ti_messages
         WHERE student_id=? AND sender='staff' ORDER BY created_at DESC LIMIT 10", [$account_id]) as $r) {
        if (empty($r['ts'])) continue;
        $t = trim((string)($r['subject'] ?? '')) !== '' ? $r['subject'] : mb_substr(trim((string)$r['body']), 0, 40);
        $items[] = ['ts'=>$r['ts'], 'icon'=>'chat-text', 'tab'=>'wiadomosci', 'url'=>'?tab=wiadomosci',
                    'title'=>'Wiadomość od prowadzącego: ' . $t];
    }

    // Sortuj malejąco po czasie, ogranicz
    usort($items, fn($a, $b) => strcmp((string)$b['ts'], (string)$a['ts']));
    return array_slice($items, 0, $limit);
}

/** Liczba pozycji nowszych niż znacznik przejrzenia. */
function k30_ti_notif_unread_count(array $items, string $seen_at): int {
    $n = 0;
    foreach ($items as $it) {
        if ($seen_at === '' || strcmp((string)$it['ts'], $seen_at) > 0) $n++;
    }
    return $n;
}

/** Krótka etykieta czasu „temu" po polsku. */
function k30_ti_notif_ago(string $ts): string {
    $t = strtotime($ts);
    if (!$t) return '';
    $diff = time() - $t;
    if ($diff < 60)      return 'przed chwilą';
    if ($diff < 3600)    return floor($diff / 60) . ' min temu';
    if ($diff < 86400)   return floor($diff / 3600) . ' godz. temu';
    if ($diff < 604800)  return floor($diff / 86400) . ' dni temu';
    return date('d.m.Y', $t);
}
