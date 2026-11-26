<?php
declare(strict_types=1);

/**
 * api/internal/nc_zoom_meeting.php — spotkanie Zoom dla rezerwacji z Kalendarza chmury (nc.feer.org.pl).
 * Wywołuje aplikacja feer_poczta (ZoomBookingListener) przy BeforeAppointmentBookedEvent, gdy lokalizacja
 * harmonogramu zawiera „Zoom” — link trafia jako lokalizacja wydarzenia i do e-maila potwierdzającego.
 *
 * POST JSON { key, topic, start_ts, end_ts, attendee_name, attendee_email, organizer, description }
 * → { ok:true, join_url, meeting_id } albo { ok:false, reason }.
 *
 * Konto: jedno konto hosta Zoom SZO (to samo co lekcje TI) → kolizje sprawdzane jak przy lekcjach:
 *   1) lekcje TI przez Zoom w tym czasie (k30_ti_sessions), 2) spotkania z terminem z API Zoom (ti_zoom_api_busy).
 * Powtórzone wywołanie z tym samym `key` (np. ponowiona rezerwacja) zwraca to samo spotkanie (tabela nc_zoom_meetings).
 * Zabezpieczenie: X-Internal-Key = ustawienie roundcube_signature_key (jak nc_pulpit.php).
 */

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function nzm_out(array $d, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$key      = org_setting('roundcube_signature_key');
$incoming = (string)($_SERVER['HTTP_X_INTERNAL_KEY'] ?? '');
if (strlen($key) < 32 || $incoming === '' || !hash_equals($key, $incoming)) nzm_out(['ok' => false, 'reason' => 'forbidden'], 403);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') nzm_out(['ok' => false, 'reason' => 'Metoda niedozwolona.'], 405);

$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in)) nzm_out(['ok' => false, 'reason' => 'Nieprawidłowe dane.'], 400);

$extKey   = mb_substr(trim((string)($in['key'] ?? '')), 0, 190);
$startTs  = (int)($in['start_ts'] ?? 0);
$endTs    = (int)($in['end_ts'] ?? 0);
$topic    = trim((string)($in['topic'] ?? '')) ?: 'Spotkanie FEER';
$attName  = trim((string)($in['attendee_name'] ?? ''));
$attEmail = trim((string)($in['attendee_email'] ?? ''));
$organizer = trim((string)($in['organizer'] ?? ''));
if ($extKey === '' || $startTs <= 0 || $endTs <= $startTs) nzm_out(['ok' => false, 'reason' => 'Brak terminu rezerwacji.'], 400);

try {
    db()->exec("CREATE TABLE IF NOT EXISTS nc_zoom_meetings (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ext_key VARCHAR(190) NOT NULL UNIQUE,
        meeting_id VARCHAR(40) NOT NULL DEFAULT '',
        join_url TEXT NOT NULL DEFAULT '',
        topic TEXT NOT NULL DEFAULT '',
        start_ts INTEGER NOT NULL DEFAULT 0,
        end_ts INTEGER NOT NULL DEFAULT 0,
        attendee_email VARCHAR(190) NOT NULL DEFAULT '',
        organizer VARCHAR(190) NOT NULL DEFAULT '',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
} catch (\Throwable $e) {}

$prev = db_one("SELECT meeting_id, join_url FROM nc_zoom_meetings WHERE ext_key=?", [$extKey]);
if ($prev && $prev['join_url'] !== '') nzm_out(['ok' => true, 'join_url' => $prev['join_url'], 'meeting_id' => $prev['meeting_id'], 'reused' => true]);

require_once dirname(__DIR__, 2) . '/includes/zoom.php';
if (!zoom_enabled()) nzm_out(['ok' => false, 'reason' => 'Integracja Zoom nie jest skonfigurowana w SZO.']);

// ── Kolizje na koncie hosta (jak ti_zoom_slot_check, bez kontekstu kursu) ──
require_once dirname(__DIR__, 2) . '/includes/karty30.php';
$tz       = new DateTimeZone('Europe/Warsaw');
$from     = (new DateTimeImmutable('@' . $startTs))->setTimezone($tz);
$to       = (new DateTimeImmutable('@' . $endTs))->setTimezone($tz);
$date     = $from->format('Y-m-d');
$tf       = $from->format('H:i');
$tt       = $to->format('H:i');
try {
    $rows = db_all(
        "SELECT s.course_id, s.time_from, s.time_to, s.lesson_method, c.name AS course_name, c.zoom_meeting_id
           FROM k30_ti_sessions s JOIN k30_ti_courses c ON c.id = s.course_id
          WHERE s.lesson_date = ? AND s.status NOT IN ('cancelled','draft')
            AND s.time_from != '' AND s.time_to != '' AND s.time_from < ? AND s.time_to > ?
            AND COALESCE(c.status,'active') != 'archived'", [$date, $tt, $tf]);
} catch (\Throwable $e) { $rows = []; }
foreach ($rows as $r) {
    if (!ti_lesson_uses_zoom((int)$r['course_id'], (string)($r['lesson_method'] ?? ''), (string)($r['zoom_meeting_id'] ?? ''))) continue;
    nzm_out(['ok' => false, 'reason' => 'Konto Zoom jest zajęte: lekcja „' . $r['course_name'] . '” '
        . substr((string)$r['time_from'], 0, 5) . '–' . substr((string)$r['time_to'], 0, 5) . ' (' . $from->format('d.m.Y') . ').']);
}
$api = ti_zoom_api_busy();
if ($api['ok']) {
    foreach ($api['slots'] as $s) {
        $sf = strtotime((string)($s['start'] ?? '')); $st = strtotime((string)($s['end'] ?? ''));
        if ($sf && $st && $sf < $endTs && $st > $startTs) {
            nzm_out(['ok' => false, 'reason' => 'Konto Zoom jest zajęte spotkaniem „' . ($s['topic'] ?? '') . '” ('
                . date('d.m.Y H:i', $sf) . '–' . date('H:i', $st) . ').']);
        }
    }
}

// ── Utworzenie spotkania ──
$agenda = trim('Rezerwacja z chmury FEER' . ($attName !== '' ? ': ' . $attName : '') . ($attEmail !== '' ? ' <' . $attEmail . '>' : '')
    . "\n" . trim((string)($in['description'] ?? '')));
try {
    $zoom = new ZoomAPI();
    $m = $zoom->create_scheduled_meeting($topic, gmdate('Y-m-d\TH:i:s\Z', $startTs), (int)round(($endTs - $startTs) / 60), $agenda);
    $zoom->log('nc_booking_create', $m['meeting_id'], 'ok', $topic . ' · ' . $from->format('d.m.Y H:i') . ' · ' . $attEmail);
} catch (\Throwable $e) {
    try { (new ZoomAPI())->log('nc_booking_create', '', 'error', $e->getMessage()); } catch (\Throwable $e2) {}
    nzm_out(['ok' => false, 'reason' => 'Zoom nie utworzył spotkania: ' . $e->getMessage()]);
}
if ($m['join_url'] === '') nzm_out(['ok' => false, 'reason' => 'Zoom nie zwrócił linku do spotkania.']);

try {
    db_insert('nc_zoom_meetings', [
        'ext_key' => $extKey, 'meeting_id' => $m['meeting_id'], 'join_url' => $m['join_url'], 'topic' => $topic,
        'start_ts' => $startTs, 'end_ts' => $endTs, 'attendee_email' => $attEmail, 'organizer' => $organizer,
    ]);
} catch (\Throwable $e) {}
if (!$api['ok']) {
    nzm_out(['ok' => true, 'join_url' => $m['join_url'], 'meeting_id' => $m['meeting_id'],
        'warning' => 'Nie sprawdzono spotkań w API Zoom (' . $api['error'] . ') — tylko lekcje z SZO.']);
}
nzm_out(['ok' => true, 'join_url' => $m['join_url'], 'meeting_id' => $m['meeting_id']]);
