<?php
/**
 * Okno „Masz dziś zajęcia" — pokazywane raz po świeżym zalogowaniu kursanta
 * (flaga $_SESSION['kp_today_popup'] ustawiana w student_login_user()).
 * Wymaga: $student, h(), db_all(). Bootstrap Modal zapewnia pułapkę fokusu, Esc i aria-modal.
 */
if (empty($_SESSION['kp_today_popup'])) return;
unset($_SESSION['kp_today_popup']);

try {
    $tp_rows = db_all(
        "SELECT s.id, s.course_id, s.time_from, s.time_to, s.topic, s.lesson_method, s.meeting_url,
                c.name AS course_name, c.default_meeting_url AS course_meeting_url,
                e.zoom_meeting_url AS enrollment_meeting_url, u.name AS instructor_name
         FROM k30_ti_sessions s
         JOIN k30_ti_courses c ON c.id=s.course_id
         LEFT JOIN users u ON u.id=c.instructor_id
         JOIN k30_ti_enrollments e ON e.course_id=c.id AND e.client_id=? AND e.status='active'
         WHERE s.lesson_date = date('now','localtime')
           AND (s.status IS NULL OR s.status NOT IN ('cancelled','removed','reserved'))
         ORDER BY s.time_from",
        [(int)$student['client_id']]
    );
} catch (\Throwable $e) { $tp_rows = []; }

// Pomijamy zajęcia, które już się skończyły.
$tp_now = date('H:i');
$tp_rows = array_values(array_filter($tp_rows, function ($r) use ($tp_now) {
    $to = substr((string)($r['time_to'] ?? ''), 0, 5);
    return $to === '' || $to >= $tp_now;
}));
if (!$tp_rows) return;

// Zapasowe linki (spotkania z zakładki „Szkolenia online") dla zajęć bez własnego linku.
$tp_meet = [];
try {
    foreach (ti_upcoming_meetings((int)$student['client_id']) as $m) {
        if (empty($m['course_id'])) continue;
        $d = substr((string)($m['starts_at'] ?? ''), 0, 10);
        if ($d !== '' && $d !== date('Y-m-d')) continue;
        $tp_meet[(int)$m['course_id']][] = $m['join_url'];
    }
} catch (\Throwable $e) {}
$tp_n = count($tp_rows);
?>
<div class="modal fade kp-dzis-modal" id="kp-today-modal" tabindex="-1" aria-labelledby="kp-today-title" aria-describedby="kp-today-desc" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h2 class="modal-title fs-5" id="kp-today-title">
          <i class="bi bi-calendar-event me-2" aria-hidden="true"></i><?= $tp_n === 1 ? 'Masz dziś zajęcia' : 'Masz dziś ' . $tp_n . ' zajęć' ?>
        </h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij okno"></button>
      </div>
      <div class="modal-body">
        <p id="kp-today-desc" class="mb-3"><?= $tp_n === 1 ? 'Czy chcesz dołączyć?' : 'Czy chcesz dołączyć do któregoś z nich?' ?></p>
        <ul class="list-unstyled mb-0 d-grid gap-3">
        <?php foreach ($tp_rows as $r):
            $lm  = (string)($r['lesson_method'] ?? '');
            $enr = trim((string)($r['enrollment_meeting_url'] ?? ''));
            $mu  = trim((string)($r['meeting_url'] ?? ''));
            $link = $mu !== '' ? $mu : ($enr !== '' ? $enr : trim((string)($r['course_meeting_url'] ?? '')));
            $from = substr((string)($r['time_from'] ?? ''), 0, 5);
            $to   = substr((string)($r['time_to'] ?? ''), 0, 5);
            $hours = $from !== '' ? $from . ($to !== '' ? '–' . $to : '') : '';
            $mlabel = match ($lm) { 'stacjonarna' => 'Zajęcia stacjonarne', 'zdalna_zoom' => 'Zdalnie — Zoom', 'zdalna_inne' => 'Zdalnie', default => '' };
        ?>
          <li class="border rounded p-3 kp-dzis-lekcja">
            <div class="fw-bold"><?= h($r['course_name']) ?></div>
            <div>
              <?php if ($hours !== ''): ?><time><?= h($hours) ?></time><?php endif; ?>
              <?php if ($mlabel !== ''): ?><span class="text-body-secondary"><?= $hours !== '' ? ' · ' : '' ?><?= h($mlabel) ?></span><?php endif; ?>
            </div>
            <?php if (!empty($r['topic'])): ?><div class="text-body-secondary small">Temat: <?= h($r['topic']) ?></div><?php endif; ?>
            <?php if (!empty($r['instructor_name'])): ?><div class="text-body-secondary small">Prowadzący: <?= h($r['instructor_name']) ?></div><?php endif; ?>
            <div class="mt-2">
              <?php if ($link !== ''): ?>
              <a href="<?= h($link) ?>" target="_blank" rel="noopener" class="btn btn-success">
                <i class="bi bi-camera-video me-1" aria-hidden="true"></i>Dołącz do zajęć<span class="visually-hidden"> — <?= h($r['course_name']) ?> (otwiera się w nowej karcie)</span>
              </a>
              <div class="small mt-2 text-break">Link do zajęć: <a href="<?= h($link) ?>" target="_blank" rel="noopener"><?= h($link) ?></a></div>
              <?php elseif ($lm === 'stacjonarna'): ?>
              <span class="small text-body-secondary">Zajęcia odbywają się stacjonarnie — nie ma linku do dołączenia.</span>
              <?php else: ?>
              <a href="?tab=online" class="btn btn-success">
                <i class="bi bi-camera-video me-1" aria-hidden="true"></i>Dołącz do zajęć<span class="visually-hidden"> — <?= h($r['course_name']) ?> (przejdź do zakładki Szkolenia online)</span>
              </a>
              <?php endif; ?>
            </div>
          </li>
        <?php endforeach; ?>
        </ul>
      </div>
      <div class="modal-footer">
        <a href="?tab=lekcje" class="btn btn-outline-secondary">Przejdź do lekcji</a>
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Nie teraz</button>
      </div>
    </div>
  </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var el = document.getElementById('kp-today-modal');
  if (el && window.bootstrap) bootstrap.Modal.getOrCreateInstance(el).show();
});
</script>
