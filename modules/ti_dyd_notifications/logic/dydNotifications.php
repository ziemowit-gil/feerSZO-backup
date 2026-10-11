<?php
/**
 * modules/ti_dyd_notifications/logic/dydNotifications.php — Centrum powiadomień prowadzącego (panel dydaktyka).
 *
 * Feed wyliczany na żywo z istniejących danych grup prowadzącego (bez tabeli zdarzeń), w tym samym
 * wyglądzie co powiadomienia kursanta (includes/ti_notifications.php — grupowanie, sekcje Nowe/Wcześniej):
 *   wiadomości od kursantów, prośby o zmianę terminu, prośby o odwołanie udziału, oddane prace do
 *   sprawdzenia, egzaminy do oceny, hospitacje/wizytacje.
 * Stan „przeczytane" = znacznik w k30_ti_dyd_notif_seen (user_id, seen_at); bez znacznika nowe są
 * pozycje z ostatnich 14 dni. Oznaczanie: dydaktyk/dyd_notif_read.php (POST).
 * Dzwonek wstawia kursant/_layout_head.php na każdej stronie panelu dydaktyka.
 */
require_once dirname(__DIR__, 3) . '/includes/ti_notifications.php';

function dyd_notif_migrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    db()->exec("CREATE TABLE IF NOT EXISTS k30_ti_dyd_notif_seen (
        user_id INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
        seen_at TEXT NOT NULL
    )");
}

function dyd_notif_seen_at(int $uid): string {
    dyd_notif_migrate();
    return (string)(db_one("SELECT seen_at FROM k30_ti_dyd_notif_seen WHERE user_id = ?", [$uid])['seen_at'] ?? '');
}

function dyd_notif_mark_seen(int $uid): void {
    dyd_notif_migrate();
    db()->prepare("INSERT INTO k30_ti_dyd_notif_seen (user_id, seen_at) VALUES (?, datetime('now'))
                   ON CONFLICT(user_id) DO UPDATE SET seen_at = excluded.seen_at")->execute([$uid]);
}

/** Rodzaje: etykiety grup (1 / 2–4 / 5+) i kolory ikon. */
const DYD_NOTIF_LABELS = [
    'wiadomosc' => ['wiadomość od kursanta', 'wiadomości od kursantów', 'wiadomości od kursantów'],
    'termin'    => ['prośba o zmianę terminu', 'prośby o zmianę terminu', 'próśb o zmianę terminu'],
    'odwolanie' => ['prośba o odwołanie udziału', 'prośby o odwołanie udziału', 'próśb o odwołanie udziału'],
    'praca'     => ['praca do sprawdzenia', 'prace do sprawdzenia', 'prac do sprawdzenia'],
    'egzamin'   => ['egzamin do oceny', 'egzaminy do oceny', 'egzaminów do oceny'],
];
const DYD_NOTIF_TONES = [
    'wiadomosc' => ['#FCE7F3', '#9D174D'], 'termin' => ['#DCFCE7', '#166534'], 'odwolanie' => ['#FEE2E2', '#991B1B'],
    'praca' => ['#DBEAFE', '#1E40AF'], 'egzamin' => ['#FEF3C7', '#92400E'], 'hospitacja' => ['#E0E7FF', '#3730A3'],
];

/** Feed prowadzącego (posortowany malejąco, maks. $limit pozycji). */
function dyd_notifications_for(int $uid, int $limit = 30): array {
    $items = [];
    $since = date('Y-m-d H:i:s', time() - 60 * 86400);   // starsze niż 60 dni nie zaśmiecają listy
    $mine  = "SELECT id FROM k30_ti_courses WHERE instructor_id = :uid";
    $cname = "COALESCE(NULLIF(c.display_name, ''), c.name)";
    $q = function (string $sql, array $p) { try { return db_all($sql, $p); } catch (\Throwable $e) { return []; } };

    // 1) Wiadomości od kursantów moich grup
    foreach ($q("SELECT m.created_at AS ts, m.subject, m.body, m.sender_name, a.client_id,
                        (SELECT {$cname} FROM k30_ti_enrollments e JOIN k30_ti_courses c ON c.id = e.course_id
                          WHERE e.client_id = a.client_id AND c.instructor_id = :uid ORDER BY e.id DESC LIMIT 1) AS course
                   FROM k30_ti_messages m JOIN k30_ti_student_accounts a ON a.id = m.student_id
                  WHERE m.sender = 'student' AND m.created_at >= :since
                    AND a.client_id IN (SELECT e.client_id FROM k30_ti_enrollments e WHERE e.course_id IN ({$mine}))
                  ORDER BY m.created_at DESC LIMIT 15", ['uid' => $uid, 'since' => $since]) as $r) {
        $t = trim((string)$r['subject']) !== '' ? (string)$r['subject'] : mb_strimwidth(trim((string)$r['body']), 0, 50, '…');
        $items[] = ['ts' => $r['ts'], 'kind' => 'wiadomosc', 'icon' => 'chat-text', 'url' => 'index.php?tab=wiadomosci',
                    'course' => (string)$r['course'], 'detail' => (string)$r['sender_name'],
                    'title' => 'Wiadomość' . ((string)$r['sender_name'] !== '' ? ' od ' . $r['sender_name'] : '') . ': ' . $t];
    }

    // 2) Prośby o zmianę terminu czekające na decyzję
    foreach ($q("SELECT r.created_at AS ts, r.course_id, r.proposed_date, r.proposed_from, cl.name AS who, {$cname} AS course
                   FROM k30_ti_reschedule_requests r JOIN k30_ti_courses c ON c.id = r.course_id
                   LEFT JOIN k30_clients cl ON cl.id = r.client_id
                  WHERE r.status = 'pending' AND c.instructor_id = :uid
                  ORDER BY r.created_at DESC LIMIT 15", ['uid' => $uid]) as $r) {
        $items[] = ['ts' => $r['ts'], 'kind' => 'termin', 'icon' => 'calendar2-range', 'course' => (string)$r['course'],
                    'url' => 'index.php?course=' . (int)$r['course_id'] . '&tab=lekcje', 'detail' => (string)$r['who'],
                    'title' => 'Prośba o zmianę terminu' . ($r['who'] ? ' — ' . $r['who'] : '')
                             . ($r['proposed_date'] ? ' (na ' . date('d.m', strtotime((string)$r['proposed_date'])) . ($r['proposed_from'] ? ' ' . substr((string)$r['proposed_from'], 0, 5) : '') . ')' : '')];
    }

    // 3) Prośby kursantów o odwołanie udziału w zajęciach
    foreach ($q("SELECT a.cancelled_at AS ts, s.course_id, s.lesson_date, cl.name AS who, {$cname} AS course
                   FROM k30_ti_attendance a JOIN k30_ti_sessions s ON s.id = a.session_id
                   JOIN k30_ti_courses c ON c.id = s.course_id LEFT JOIN k30_clients cl ON cl.id = a.client_id
                  WHERE a.cancel_pending = 1 AND c.instructor_id = :uid AND a.cancelled_at IS NOT NULL
                  ORDER BY a.cancelled_at DESC LIMIT 15", ['uid' => $uid]) as $r) {
        $items[] = ['ts' => $r['ts'], 'kind' => 'odwolanie', 'icon' => 'person-x', 'course' => (string)$r['course'],
                    'url' => 'index.php?course=' . (int)$r['course_id'] . '&tab=nieobecnosci', 'detail' => (string)$r['who'],
                    'title' => 'Prośba o odwołanie udziału' . ($r['who'] ? ' — ' . $r['who'] : '') . ' (' . date('d.m', strtotime((string)$r['lesson_date'])) . ')'];
    }

    // 4) Oddane prace domowe do sprawdzenia
    foreach ($q("SELECT sb.submitted_at AS ts, h.course_id, h.title AS hw, cl.name AS who, {$cname} AS course
                   FROM k30_ti_homework_submissions sb JOIN k30_ti_homework h ON h.id = sb.homework_id
                   JOIN k30_ti_courses c ON c.id = h.course_id LEFT JOIN k30_clients cl ON cl.id = sb.client_id
                  WHERE COALESCE(sb.status, '') <> 'graded' AND sb.submitted_at IS NOT NULL AND sb.submitted_at >= :since AND c.instructor_id = :uid
                  ORDER BY sb.submitted_at DESC LIMIT 15", ['uid' => $uid, 'since' => $since]) as $r) {
        $items[] = ['ts' => $r['ts'], 'kind' => 'praca', 'icon' => 'journal-check', 'course' => (string)$r['course'],
                    'url' => 'index.php?course=' . (int)$r['course_id'] . '&tab=zadania', 'detail' => (string)$r['who'],
                    'title' => 'Praca do sprawdzenia: ' . $r['hw'] . ($r['who'] ? ' — ' . $r['who'] : '')];
    }

    // 5) Egzaminy (Equi Exams) czekające na ocenę
    foreach ($q("SELECT at.submitted_at AS ts, e.course_id, e.title AS ex, cl.name AS who, {$cname} AS course
                   FROM k30_ti_exam_attempts at JOIN k30_ti_exams e ON e.id = at.exam_id
                   JOIN k30_ti_courses c ON c.id = e.course_id LEFT JOIN k30_clients cl ON cl.id = at.client_id
                  WHERE at.needs_review = 1 AND at.status = 'submitted' AND c.instructor_id = :uid AND at.submitted_at IS NOT NULL
                  ORDER BY at.submitted_at DESC LIMIT 15", ['uid' => $uid]) as $r) {
        $items[] = ['ts' => $r['ts'], 'kind' => 'egzamin', 'icon' => 'patch-question', 'course' => (string)$r['course'],
                    'url' => 'index.php?course=' . (int)$r['course_id'] . '&tab=egzaminy', 'detail' => (string)$r['who'],
                    'title' => 'Egzamin do oceny: ' . $r['ex'] . ($r['who'] ? ' — ' . $r['who'] : '')];
    }

    // 6) Hospitacje i wizytacje: zaplanowane i arkusze do zapoznania się
    foreach ($q("SELECT h.id, h.typ, h.status, h.data, h.godzina, COALESCE(h.przeprowadzona_at, h.created_at) AS ts, {$cname} AS course
                   FROM k30_ti_hospitacje h LEFT JOIN k30_ti_courses c ON c.id = h.course_id
                  WHERE h.instructor_id = :uid AND h.status IN ('planowana', 'przeprowadzona')
                  ORDER BY ts DESC LIMIT 10", ['uid' => $uid]) as $r) {
        $rodz = $r['typ'] === 'wizytacja' ? 'Wizytacja' : 'Hospitacja';
        $items[] = ['ts' => $r['ts'], 'kind' => 'hospitacja', 'icon' => 'clipboard2-pulse', 'course' => (string)$r['course'],
                    'url' => 'hospitacje.php?id=' . (int)$r['id'], 'detail' => '',
                    'title' => $r['status'] === 'planowana'
                        ? $rodz . ' zaplanowana na ' . date('d.m', strtotime((string)$r['data'])) . ($r['godzina'] ? ', ' . $r['godzina'] : '')
                        : $rodz . ' z ' . date('d.m', strtotime((string)$r['data'])) . ' — zapoznaj się z arkuszem'];
    }

    usort($items, fn($a, $b) => strcmp((string)$b['ts'], (string)$a['ts']));
    return array_slice($items, 0, $limit);
}

/** HTML dzwonka z listą (Bootstrap dropdown) — do paska panelu dydaktyka. */
function dyd_notif_bell_html(int $uid, string $token, string $back): string {
    $items  = dyd_notifications_for($uid);
    $seen   = dyd_notif_seen_at($uid);
    $unread = k30_ti_notif_unread_count($items, $seen);
    $groups = k30_ti_notif_grouped($items, $seen, DYD_NOTIF_LABELS);
    $new = array_values(array_filter($groups, fn($g) => $g['new']));
    $old = array_values(array_filter($groups, fn($g) => !$g['new']));
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $row = function (array $g) use ($h): string {
        [$bg, $fg] = DYD_NOTIF_TONES[$g['kind'] ?? ''] ?? ['#F1F5F9', '#334155'];
        return '<a class="dropdown-item kp-notif-item' . ($g['new'] ? ' is-new' : '') . '" href="' . $h($g['url']) . '">'
             . '<span class="kp-notif-ico" style="background:' . $bg . ';color:' . $fg . '" aria-hidden="true"><i class="bi bi-' . $h($g['icon']) . '"></i></span>'
             . '<span class="kp-notif-txt"><span class="kp-notif-title">' . $h($g['title']) . '</span><span class="kp-notif-meta">' . $h($g['meta']) . '</span></span>'
             . ($g['new'] ? '<span class="kp-notif-dot" aria-hidden="true"></span><span class="visually-hidden"> (nieprzeczytane)</span>' : '') . '</a>';
    };
    ob_start(); ?>
<div class="dropdown">
  <button class="btn btn-outline-light btn-sm position-relative dyd-notif-btn" type="button" id="dydNotifBtn" data-bs-toggle="dropdown" aria-expanded="false"
          aria-label="Powiadomienia<?= $unread ? ' (' . $unread . ' ' . k30_ti_notif_plural($unread, 'nowe', 'nowe', 'nowych') . ')' : '' ?>" title="Powiadomienia">
    <i class="bi bi-bell" aria-hidden="true"></i>
    <?php if ($unread > 0): ?><span class="position-absolute top-0 start-100 translate-middle badge rounded-pill text-bg-danger" style="font-size:.6rem" aria-hidden="true"><?= $unread > 9 ? '9+' : $unread ?></span><?php endif; ?>
  </button>
  <div class="dropdown-menu dropdown-menu-end shadow kp-notif" aria-labelledby="dydNotifBtn">
    <div class="kp-notif-head">
      <div class="d-flex align-items-center gap-2">
        <span class="fw-bold fs-6"><i class="bi bi-bell me-1" aria-hidden="true"></i>Powiadomienia</span>
        <?php if ($unread > 0): ?><span class="badge rounded-pill text-bg-danger"><?= $unread ?> <?= $h(k30_ti_notif_plural($unread, 'nowe', 'nowe', 'nowych')) ?></span><?php endif; ?>
      </div>
      <?php if ($unread > 0): ?>
      <form method="post" action="dyd_notif_read.php" class="mt-2">
        <input type="hidden" name="_token" value="<?= $h($token) ?>"><input type="hidden" name="back" value="<?= $h($back) ?>">
        <button type="submit" class="btn btn-sm btn-outline-primary w-100"><i class="bi bi-check2-all me-1" aria-hidden="true"></i>Oznacz wszystkie jako przeczytane</button>
      </form>
      <?php endif; ?>
    </div>
    <div class="kp-notif-list">
      <?php if (!$groups): ?>
      <div class="px-3 py-4 text-body-secondary small text-center"><i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Brak powiadomień — nic nie czeka.</div>
      <?php else: ?>
        <?php if ($new): ?><h3 class="kp-notif-sec">Nowe</h3><?php foreach ($new as $g) echo $row($g); endif; ?>
        <?php if ($old): ?><h3 class="kp-notif-sec">Wcześniej</h3><?php foreach ($old as $g) echo $row($g); endif; ?>
      <?php endif; ?>
    </div>
  </div>
</div>
<style>
  .kp-notif { width: 400px; max-width: 94vw; padding: 0; overflow: hidden; }
  .kp-notif-head { padding: .75rem 1rem; border-bottom: 1px solid #e2e8f0; }
  .kp-notif-list { max-height: 65vh; overflow-y: auto; padding-bottom: .25rem; }
  .kp-notif-sec { font-size: .72rem; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: #475569; margin: .7rem 1rem .25rem; }
  .kp-notif-item { display: flex; align-items: flex-start; gap: .65rem; padding: .55rem 1rem; white-space: normal; border-left: 3px solid transparent; }
  .kp-notif-item.is-new { background: #f8fbff; border-left-color: #2563eb; }
  .kp-notif-item:hover, .kp-notif-item:focus { background: #eef4ff; }
  .kp-notif-ico { flex: 0 0 2rem; height: 2rem; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: .95rem; }
  .kp-notif-txt { flex: 1 1 auto; min-width: 0; }
  .kp-notif-title { display: block; color: #0f172a; line-height: 1.3; }
  .kp-notif-item.is-new .kp-notif-title { font-weight: 700; }
  .kp-notif-meta { display: block; font-size: .78rem; color: #475569; margin-top: .1rem; }
  .kp-notif-dot { flex: 0 0 .55rem; height: .55rem; border-radius: 50%; background: #2563eb; margin-top: .45rem; }
</style>
<?php
    return (string)ob_get_clean();
}
