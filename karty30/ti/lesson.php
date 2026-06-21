<?php
/**
 * karty30/ti/lesson.php — Lekcja TI: obecność, temat, uwagi prowadzącego, zadanie.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/karty30.php';

k30_require_access();
karty30_migrate();

$can_write  = can_write('karty30') || is_admin();
$session_id = (int)($_GET['id'] ?? 0);
$session    = $session_id ? k30_ti_session_get($session_id) : null;

// Rola i podpis osoby odwołującej (po stronie kadry: Doradca lub administrator)
$cu           = current_user();
$cancel_role  = is_admin() ? 'admin' : 'doradca';
$cancel_label = $cu['name'] ?? ($cu['login'] ?? '');

if (!$session) {
    flash_set('danger', 'Lekcja nie istnieje.');
    header('Location: index.php');
    exit;
}

$PAGE_TITLE = 'Lekcja: ' . $session['course_name'];

// ── POST ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_write) {
    csrf_check();
    $op = $_POST['_op'] ?? '';

    // Zapis obecności + metadanych lekcji (jeden formularz)
    if ($op === 'save_lesson') {
        $attended = array_map('intval', (array)($_POST['attended'] ?? []));

        // Zapisz obecność (ogólna)
        k30_ti_save_attendance($session_id, $attended);

        // Zapisz indywidualne uwagi per uczestnik
        $ind_notes = (array)($_POST['ind_notes'] ?? []);
        foreach ($ind_notes as $cid => $note) {
            $cid = (int)$cid;
            $note = trim($note);
            try {
                db()->prepare(
                    "UPDATE k30_ti_attendance SET ind_notes=? WHERE session_id=? AND client_id=?"
                )->execute([$note, $session_id, $cid]);
            } catch (\Throwable $e) {}
        }

        // Metadane lekcji
        $topic            = trim($_POST['topic']            ?? '');
        $instructor_notes = trim($_POST['instructor_notes'] ?? '');
        $has_homework     = !empty($_POST['has_homework']) ? 1 : 0;
        $self_prep_remote = !empty($_POST['self_prep_remote']) ? 1 : 0;
        $duration_min     = max(1, (int)($_POST['duration_min'] ?? $session['duration_min']));
        $time_from        = trim($_POST['time_from'] ?? $session['time_from']);
        $time_to          = trim($_POST['time_to']   ?? $session['time_to']);
        // Przelicz czas trwania z od-do jeśli zmieniono godziny
        if ($time_from && $time_to) {
            $m = (strtotime('1970-01-01 '.$time_to) - strtotime('1970-01-01 '.$time_from)) / 60;
            if ($m > 0) $duration_min = (int)$m;
        }

        db()->prepare(
            "UPDATE k30_ti_sessions
             SET status='held', topic=?, instructor_notes=?, has_homework=?, self_prep_remote=?,
                 duration_min=?, time_from=?, time_to=?, updated_at=datetime('now')
             WHERE id=?"
        )->execute([$topic, $instructor_notes, $has_homework, $self_prep_remote, $duration_min, $time_from, $time_to, $session_id]);

        flash_set('success', 'Lekcja zapisana.');
        header('Location: lesson.php?id=' . $session_id);
        exit;
    }

    // Zmiana statusu bez zapisu obecności
    if ($op === 'set_status') {
        $st = array_key_exists($_POST['status'] ?? '', K30_TI_SESSION_STATUSES)
              ? $_POST['status'] : 'planned';
        if ($st === 'cancelled') {
            // Odwołanie całej lekcji — wymaga powodu
            $reason = trim($_POST['cancel_reason'] ?? '');
            if ($reason === '') {
                flash_set('danger', 'Podaj powód odwołania lekcji.');
                header('Location: lesson.php?id=' . $session_id); exit;
            }
            k30_ti_cancel_session($session_id, $reason, $cancel_role, $cancel_label);
            flash_set('success', 'Lekcja odwołana — nie zostanie policzona do ceny.');
        } else {
            // Powrót do planowanej / odbytej — czyścimy dane odwołania
            db()->prepare(
                "UPDATE k30_ti_sessions
                 SET status=?, cancel_reason='', cancelled_by_role='', cancelled_by='', cancelled_at=NULL,
                     updated_at=datetime('now')
                 WHERE id=?"
            )->execute([$st, $session_id]);
        }
        header('Location: lesson.php?id=' . $session_id);
        exit;
    }

    // Odwołanie udziału pojedynczego uczestnika (Doradca/admin) — nie liczone do ceny
    if ($op === 'cancel_attendee') {
        $cid    = (int)($_POST['client_id'] ?? 0);
        $reason = trim($_POST['cancel_reason'] ?? '');
        if ($cid && $reason !== '') {
            k30_ti_cancel_attendance($session_id, $cid, $reason, $cancel_role, $cancel_label);
            flash_set('success', 'Udział uczestnika odwołany — nie zostanie policzony do ceny.');
        } else {
            flash_set('danger', 'Podaj powód odwołania udziału.');
        }
        header('Location: lesson.php?id=' . $session_id);
        exit;
    }

    // Przywrócenie udziału uczestnika (cofnięcie odwołania)
    if ($op === 'restore_attendee') {
        $cid = (int)($_POST['client_id'] ?? 0);
        if ($cid) {
            k30_ti_uncancel_attendance($session_id, $cid);
            flash_set('success', 'Udział uczestnika przywrócony.');
        }
        header('Location: lesson.php?id=' . $session_id);
        exit;
    }

    // Zapis linku do lekcji online — bez zmiany statusu lekcji
    if ($op === 'save_link') {
        $url = trim($_POST['meeting_url'] ?? '');
        db()->prepare("UPDATE k30_ti_sessions SET meeting_url=?, updated_at=datetime('now') WHERE id=?")
            ->execute([$url, $session_id]);
        flash_set('success', $url !== '' ? 'Link do lekcji zapisany.' : 'Link do lekcji usunięty.');
        header('Location: lesson.php?id=' . $session_id);
        exit;
    }
}

// Przeładuj
$session    = k30_ti_session_get($session_id);
$attendance = k30_ti_session_attendance($session_id);
$st_info    = K30_TI_SESSION_STATUSES[$session['status']] ?? ['label' => $session['status'], 'color' => '#666', 'bg' => '#eee'];
$is_held    = $session['status'] === 'held';

// Indywidualne uwagi (pobierz z bazy)
$ind_notes_map = [];
try {
    $rows = db_all("SELECT client_id, ind_notes FROM k30_ti_attendance WHERE session_id=?", [$session_id]);
    foreach ($rows as $r) $ind_notes_map[(int)$r['client_id']] = $r['ind_notes'];
} catch (\Throwable $e) {}

// Oceny lekcji od kursantów (1–5)
$ratings     = k30_ti_session_ratings($session_id);
$rating_avg  = $ratings ? round(array_sum(array_column($ratings, 'rating')) / count($ratings), 2) : 0;

include dirname(dirname(__DIR__)) . '/karty30/includes/header_k30.php';
?>

<style>
.att-row.present  { background: #f0fdf4; }
.att-row.absent   { background: #fafafa; }
.att-row.cancelled{ background: #fef2f2; }
.att-cb           { width: 1.3em; height: 1.3em; flex-shrink: 0; cursor: pointer; }
.section-head     { font-size: .72rem; font-weight: 700; text-transform: uppercase; letter-spacing: .07em; color: #64748b; border-bottom: 1px solid #e2e8f0; padding-bottom: 4px; margin-bottom: 12px; }
</style>

<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb">
  <li class="breadcrumb-item"><a href="index.php">Zajęcia TI</a></li>
  <li class="breadcrumb-item"><a href="course.php?id=<?= (int)$session['course_id'] ?>"><?= h($session['course_name']) ?></a></li>
  <li class="breadcrumb-item active">Lekcja <?= date('d.m.Y', strtotime($session['lesson_date'])) ?></li>
</ol></nav>

<!-- Nagłówek -->
<div class="d-flex align-items-start mb-3 gap-2 flex-wrap">
  <div class="flex-grow-1">
    <h4 class="mb-0 fw-bold">
      <i class="bi bi-clipboard-check text-primary me-2"></i>
      <?= h($session['course_name']) ?>
      <span class="text-muted fw-normal fs-5">— <?= date('d.m.Y', strtotime($session['lesson_date'])) ?></span>
    </h4>
    <div class="text-muted small mt-1 d-flex align-items-center gap-2 flex-wrap">
      <?php if ($session['time_from']): ?>
      <span><i class="bi bi-clock me-1"></i><?= h($session['time_from']) ?>–<?= h($session['time_to']) ?> (<?= (int)$session['duration_min'] ?> min)</span>
      <?php else: ?>
      <span><?= (int)$session['duration_min'] ?> min</span>
      <?php endif; ?>
      <?php if ($session['instructor_name']): ?><span>·</span><span><?= h($session['instructor_name']) ?></span><?php endif; ?>
      <span class="badge" style="background:<?= h($st_info['bg']) ?>;color:<?= h($st_info['color']) ?>;border:1px solid <?= h($st_info['color']) ?>44">
        <?= h($st_info['label']) ?>
      </span>
      <?php if ($session['has_homework'] ?? 0): ?>
      <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle">
        <i class="bi bi-pencil-square me-1"></i>Zadanie domowe
      </span>
      <?php endif; ?>
      <?php if ($session['self_prep_remote'] ?? 0): ?>
      <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle">
        <i class="bi bi-laptop me-1"></i>Praca własna — materiał zdalny
      </span>
      <?php endif; ?>
    </div>
  </div>
  <?php if ($can_write): ?>
  <div class="flex-shrink-0 d-flex align-items-center gap-2">
    <?php if ($session['status'] !== 'cancelled'): ?>
    <form method="post" class="d-inline">
      <input type="hidden" name="_csrf"   value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="_op"     value="set_status">
      <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
        <?php foreach (K30_TI_SESSION_STATUSES as $sk => $sv): if ($sk === 'cancelled') continue; ?>
        <option value="<?= h($sk) ?>" <?= $session['status']===$sk?'selected':'' ?>><?= h($sv['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
    <button type="button" class="btn btn-sm btn-outline-danger"
            data-bs-toggle="modal" data-bs-target="#cancelLessonModal">
      <i class="bi bi-x-circle me-1"></i>Odwołaj lekcję
    </button>
    <?php else: ?>
    <form method="post" class="d-inline" onsubmit="return confirm('Przywrócić lekcję (status: zaplanowana)?')">
      <input type="hidden" name="_csrf"  value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="_op"    value="set_status">
      <input type="hidden" name="status" value="planned">
      <button type="submit" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-counterclockwise me-1"></i>Przywróć lekcję
      </button>
    </form>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<?= flash_html() ?>

<!-- Link do lekcji online + oceny kursantów -->
<div class="row g-3 mb-3">
  <div class="col-lg-7">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-body">
        <div class="section-head"><i class="bi bi-camera-video me-1 text-primary"></i>Link do lekcji online</div>
        <?php if ($can_write): ?>
        <form method="post" class="input-group input-group-sm">
          <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="_op"   value="save_link">
          <input type="url" class="form-control font-monospace" name="meeting_url"
                 value="<?= h($session['meeting_url'] ?? '') ?>"
                 placeholder="<?= !empty($session['course_meeting_url']) ? 'puste = stały link grupy' : 'https://… (Teams/Zoom/Meet)' ?>">
          <button type="submit" class="btn btn-outline-primary"><i class="bi bi-save me-1"></i>Zapisz</button>
        </form>
        <?php endif; ?>
        <div class="small text-muted mt-2">
          <?php if (!empty($session['meeting_url'])): ?>
            Link tej lekcji: <a href="<?= h($session['meeting_url']) ?>" target="_blank" rel="noopener"><?= h($session['meeting_url']) ?></a>
          <?php elseif (!empty($session['course_meeting_url'])): ?>
            <i class="bi bi-link-45deg me-1"></i>Używany jest stały link grupy:
            <a href="<?= h($session['course_meeting_url']) ?>" target="_blank" rel="noopener"><?= h($session['course_meeting_url']) ?></a>
          <?php else: ?>
            Brak linku — ustaw powyżej lub stały link w ustawieniach kursu.
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-body">
        <div class="section-head"><i class="bi bi-star me-1 text-warning"></i>Oceny kursantów</div>
        <?php if ($ratings): ?>
        <div class="d-flex align-items-center gap-2 mb-2">
          <span class="fs-4 fw-bold text-warning"><?= number_format($rating_avg, 2, ',', '') ?></span>
          <span class="text-warning">
            <?php for ($i=1;$i<=5;$i++): ?><i class="bi bi-star<?= $i <= round($rating_avg) ? '-fill' : '' ?>"></i><?php endfor; ?>
          </span>
          <span class="text-muted small">(<?= count($ratings) ?>)</span>
        </div>
        <ul class="list-unstyled small mb-0">
          <?php foreach ($ratings as $rr): ?>
          <li class="border-top pt-1 mt-1">
            <span class="text-warning"><?php for ($i=1;$i<=5;$i++): ?><i class="bi bi-star<?= $i <= (int)$rr['rating'] ? '-fill' : '' ?>"></i><?php endfor; ?></span>
            <span class="text-muted ms-1"><?= h($rr['client_name']) ?></span>
            <?php if (!empty($rr['comment'])): ?><div class="text-body-secondary fst-italic">„<?= h($rr['comment']) ?>"</div><?php endif; ?>
          </li>
          <?php endforeach; ?>
        </ul>
        <?php else: ?>
        <div class="text-muted small">Brak ocen. Kursanci mogą ocenić odbytą lekcję w swoim panelu.</div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php if ($session['status'] === 'cancelled'): ?>
<div class="alert alert-danger d-flex align-items-start gap-2">
  <i class="bi bi-x-octagon-fill mt-1"></i>
  <div>
    <div class="fw-semibold">Lekcja odwołana — nie liczona do ceny.</div>
    <?php if (!empty($session['cancel_reason'])): ?>
    <div class="mt-1"><span class="text-muted">Powód:</span> <?= h($session['cancel_reason']) ?></div>
    <?php endif; ?>
    <?php if (!empty($session['cancelled_by_role']) || !empty($session['cancelled_by'])):
      $role_lbl = K30_TI_CANCEL_ROLES[$session['cancelled_by_role']] ?? $session['cancelled_by_role']; ?>
    <div class="small text-muted mt-1">
      Odwołał(a): <?= h(trim(($role_lbl ? $role_lbl : '') . ($session['cancelled_by'] ? ' — '.$session['cancelled_by'] : ''))) ?>
      <?php if (!empty($session['cancelled_at'])): ?> · <?= h(date('d.m.Y H:i', strtotime($session['cancelled_at']))) ?><?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php else: ?>

<form method="post" id="lesson_form">
<input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
<input type="hidden" name="_op"   value="save_lesson">

<div class="row g-4">

  <!-- LEWA: Metadane lekcji -->
  <div class="col-lg-5">

    <!-- Temat i czas trwania -->
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-body">
        <div class="section-head">Informacje o lekcji</div>

        <div class="mb-3">
          <label class="form-label fw-semibold" for="topic">
            <i class="bi bi-journal-text me-1 text-primary"></i>Temat lekcji
          </label>
          <input type="text" class="form-control" id="topic" name="topic"
                 value="<?= h($session['topic'] ?? '') ?>"
                 placeholder="np. Obsługa poczty e-mail, Tworzenie dokumentów w Word…"
                 <?= !$can_write ? 'readonly' : '' ?>>
        </div>

        <!-- Godziny — od/do → czas trwania wyliczany automatycznie -->
        <div class="row g-2 mb-3">
          <div class="col-5">
            <label class="form-label fw-semibold" for="ltime_from">
              <i class="bi bi-clock me-1 text-muted"></i>Początek
            </label>
            <select class="form-select" id="ltime_from" name="time_from"
                    onchange="recalcDur()"
                    <?= !$can_write ? 'disabled' : '' ?>><?= ti_time_options($session['time_from'] ?? '') ?></select>
          </div>
          <div class="col-5">
            <label class="form-label fw-semibold" for="ltime_to">
              <i class="bi bi-clock-fill me-1 text-muted"></i>Koniec
            </label>
            <select class="form-select" id="ltime_to" name="time_to"
                    onchange="recalcDur()"
                    <?= !$can_write ? 'disabled' : '' ?>><?= ti_time_options($session['time_to'] ?? '') ?></select>
          </div>
          <div class="col-2 d-flex flex-column justify-content-end">
            <div class="text-center pb-1">
              <div class="text-muted" style="font-size:.68rem">czas</div>
              <div class="fw-bold" id="dur_display" style="font-size:1rem">
                <?php
                  $dm = (int)$session['duration_min'];
                  echo $dm >= 60
                    ? floor($dm/60).'h'.($dm%60 ? ' '.($dm%60).'m' : '')
                    : $dm.'m';
                ?>
              </div>
            </div>
          </div>
        </div>
        <!-- Ukryte pole duration_min — wyliczane przez JS -->
        <input type="hidden" id="ldur" name="duration_min" value="<?= (int)$session['duration_min'] ?>">

        <!-- Zadanie domowe -->
        <div class="form-check form-switch mb-2">
          <input class="form-check-input" type="checkbox" role="switch"
                 id="has_homework" name="has_homework" value="1"
                 <?= ($session['has_homework'] ?? 0) ? 'checked' : '' ?>
                 <?= !$can_write ? 'disabled' : '' ?>>
          <label class="form-check-label fw-semibold" for="has_homework">
            <i class="bi bi-pencil-square me-1 text-warning"></i>Zadano zadanie domowe
          </label>
        </div>

        <!-- Praca własna prowadzącego — materiał do wykonania zdalnie -->
        <div class="form-check form-switch mb-3">
          <input class="form-check-input" type="checkbox" role="switch"
                 id="self_prep_remote" name="self_prep_remote" value="1"
                 <?= ($session['self_prep_remote'] ?? 0) ? 'checked' : '' ?>
                 <?= !$can_write ? 'disabled' : '' ?>>
          <label class="form-check-label fw-semibold" for="self_prep_remote">
            <i class="bi bi-laptop me-1 text-info"></i>Praca własna prowadzącego — przygotowanie materiału do wykonania zdalnie
          </label>
        </div>

        <!-- Uwagi prowadzącego -->
        <div>
          <label class="form-label fw-semibold" for="inst_notes">
            <i class="bi bi-chat-square-text me-1 text-secondary"></i>Uwagi prowadzącego
          </label>
          <textarea class="form-control" id="inst_notes" name="instructor_notes"
                    rows="4" placeholder="Postępy grupy, trudności, tematy do powtórzenia…"
                    <?= !$can_write ? 'readonly' : '' ?>><?= h($session['instructor_notes'] ?? '') ?></textarea>
        </div>
      </div>
    </div>

    <!-- Podsumowanie (gdy odbyta) -->
    <?php if ($is_held && $attendance):
      $present = array_filter($attendance, fn($a) => $a['attended']);
      $total_h = (float)$session['duration_min'] / 60;
      $total_pln = array_sum(array_map(fn($a) => $total_h * (float)$a['hourly_rate'], $present));
    ?>
    <div class="card border-0 shadow-sm">
      <div class="card-body">
        <div class="section-head">Podsumowanie</div>
        <div class="d-flex gap-4 flex-wrap">
          <div><div class="text-muted small">Obecni</div>
            <div class="fw-bold fs-4 text-success"><?= count($present) ?><span class="text-muted fs-6">/<?= count($attendance) ?></span></div></div>
          <div><div class="text-muted small">Czas</div>
            <div class="fw-bold fs-4"><?= number_format($total_h,2,',','') ?> h</div></div>
          <div><div class="text-muted small">Kwota</div>
            <div class="fw-bold fs-4 text-primary"><?= number_format($total_pln,2,',','') ?> zł</div></div>
        </div>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- PRAWA: Lista obecności -->
  <div class="col-lg-7">
    <div class="card border-0 shadow-sm">
      <div class="card-header fw-semibold d-flex align-items-center">
        <i class="bi bi-person-check me-2 text-primary"></i>Lista obecności
        <span class="badge bg-secondary ms-2"><?= count($attendance) ?></span>
        <?php if ($can_write && $attendance): ?>
        <div class="ms-auto d-flex gap-2">
          <button type="button" class="btn btn-xs btn-sm btn-outline-secondary py-0 px-2"
                  onclick="toggleAll(true)">Wszyscy ✓</button>
          <button type="button" class="btn btn-xs btn-sm btn-outline-secondary py-0 px-2"
                  onclick="toggleAll(false)">Brak ✗</button>
        </div>
        <?php endif; ?>
      </div>

      <?php if (!$attendance): ?>
      <div class="card-body text-muted">
        Brak uczestników kursu.
        <a href="course.php?id=<?= (int)$session['course_id'] ?>#uczestnicy">Dodaj uczestników</a>.
      </div>
      <?php else: ?>
      <div class="list-group list-group-flush" id="att_list">
        <?php foreach ($attendance as $a):
          $cid       = (int)$a['client_id'];
          $cancelled = (int)($a['cancelled'] ?? 0) === 1;
          $present   = !$cancelled && (bool)$a['attended'];
          $note      = $ind_notes_map[$cid] ?? '';
          $role_lbl  = K30_TI_CANCEL_ROLES[$a['cancelled_by_role'] ?? ''] ?? ($a['cancelled_by_role'] ?? '');
        ?>
        <div class="list-group-item att-row <?= $cancelled ? 'cancelled' : ($present ? 'present' : 'absent') ?> py-2 px-3"
             id="row_<?= $cid ?>">
          <div class="d-flex align-items-center gap-3">
            <input class="att-cb form-check-input" type="checkbox"
                   name="attended[]" value="<?= $cid ?>"
                   <?= $present ? 'checked' : '' ?>
                   onchange="rowToggle(this)"
                   <?= (!$can_write || $cancelled) ? 'disabled' : '' ?>>
            <div class="flex-grow-1 min-width-0">
              <div class="fw-semibold text-truncate <?= $cancelled ? 'text-decoration-line-through text-muted' : '' ?>"><?= h($a['client_name']) ?></div>
              <?php if ($a['client_email']): ?>
              <div class="text-muted" style="font-size:.75rem"><?= h($a['client_email']) ?></div>
              <?php endif; ?>
            </div>
            <div class="text-muted text-end flex-shrink-0" style="font-size:.78rem">
              <?= number_format((float)$a['hourly_rate'], 2, ',', '') ?> zł/h
            </div>
            <?php if ($can_write): ?>
              <?php if ($cancelled): ?>
              <button type="button" class="btn btn-xs btn-sm btn-outline-secondary py-0 px-2 flex-shrink-0"
                      onclick="restoreAtt(<?= $cid ?>)" title="Przywróć udział">
                <i class="bi bi-arrow-counterclockwise"></i>
              </button>
              <?php else: ?>
              <button type="button" class="btn btn-xs btn-sm btn-outline-danger py-0 px-2 flex-shrink-0"
                      onclick="openCancelAtt(<?= $cid ?>, <?= htmlspecialchars(json_encode($a['client_name']), ENT_QUOTES) ?>)"
                      title="Odwołaj udział (nie liczone do ceny)">
                <i class="bi bi-x-circle"></i>
              </button>
              <?php endif; ?>
            <?php endif; ?>
          </div>
          <?php if ($cancelled): ?>
          <div class="mt-1 ms-5 small text-danger">
            <i class="bi bi-x-octagon me-1"></i>Udział odwołany — nie liczony do ceny.
            <?php if (!empty($a['cancel_reason'])): ?><span class="text-muted">Powód:</span> <?= h($a['cancel_reason']) ?><?php endif; ?>
            <?php if ($role_lbl || !empty($a['cancelled_by'])): ?>
            <span class="text-muted d-block">Odwołał(a): <?= h(trim(($role_lbl ?: '') . (!empty($a['cancelled_by']) ? ' — '.$a['cancelled_by'] : ''))) ?></span>
            <?php endif; ?>
          </div>
          <?php else: ?>
          <!-- Uwagi indywidualne -->
          <div class="mt-1 ms-5">
            <input type="text"
                   class="form-control form-control-sm border-0 bg-transparent px-0"
                   name="ind_notes[<?= $cid ?>]"
                   value="<?= h($note) ?>"
                   placeholder="Uwaga do uczestnika…"
                   <?= !$can_write ? 'readonly' : '' ?>
                   style="font-size:.78rem;color:#64748b">
          </div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>

      <?php if ($can_write): ?>
      <div class="card-footer d-flex align-items-center justify-content-between gap-2">
        <span class="text-muted small" id="att_count">
          Zaznaczono: <strong id="att_num"><?= count(array_filter($attendance,fn($a)=>$a['attended'])) ?></strong>/<?= count($attendance) ?>
        </span>
        <button type="submit" class="btn btn-success">
          <i class="bi bi-check2-all me-1"></i>Zapisz lekcję
        </button>
      </div>
      <?php endif; ?>
    </div>
  </div>

</div><!-- /row -->
</form>
<?php endif; // $is_held ?>
<?php endif; // cancelled ?>

<?php if ($can_write): ?>
<!-- Modal: odwołanie całej lekcji (Doradca / admin) -->
<div class="modal fade" id="cancelLessonModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form method="post" class="modal-content">
      <input type="hidden" name="_csrf"  value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="_op"    value="set_status">
      <input type="hidden" name="status" value="cancelled">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-x-circle text-danger me-2"></i>Odwołanie lekcji</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
      </div>
      <div class="modal-body">
        <p class="text-muted small mb-2">Odwołana lekcja nie zostanie policzona do ceny. Podaj powód odwołania.</p>
        <label class="form-label fw-semibold" for="cl_reason">Powód odwołania</label>
        <textarea class="form-control" id="cl_reason" name="cancel_reason" rows="3" required
                  placeholder="np. choroba prowadzącego, brak frekwencji, awaria sprzętu…"></textarea>
        <div class="form-text">Odwołujący: <?= h(K30_TI_CANCEL_ROLES[$cancel_role]) ?><?= $cancel_label ? ' — '.h($cancel_label) : '' ?></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
        <button type="submit" class="btn btn-danger"><i class="bi bi-x-circle me-1"></i>Odwołaj lekcję</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: odwołanie udziału uczestnika -->
<div class="modal fade" id="cancelAttModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form method="post" class="modal-content">
      <input type="hidden" name="_csrf"     value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="_op"       value="cancel_attendee">
      <input type="hidden" name="client_id" id="ca_cid" value="">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-x-circle text-danger me-2"></i>Odwołanie udziału</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
      </div>
      <div class="modal-body">
        <p class="mb-2">Uczestnik: <strong id="ca_name"></strong></p>
        <p class="text-muted small mb-2">Odwołany udział nie zostanie policzony do ceny. Podaj powód.</p>
        <label class="form-label fw-semibold" for="ca_reason">Powód odwołania</label>
        <textarea class="form-control" id="ca_reason" name="cancel_reason" rows="3" required
                  placeholder="np. nieobecność zgłoszona przez beneficjenta, choroba…"></textarea>
        <div class="form-text">Odwołujący: <?= h(K30_TI_CANCEL_ROLES[$cancel_role]) ?><?= $cancel_label ? ' — '.h($cancel_label) : '' ?></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
        <button type="submit" class="btn btn-danger"><i class="bi bi-x-circle me-1"></i>Odwołaj udział</button>
      </div>
    </form>
  </div>
</div>

<!-- Ukryty formularz akcji uczestnika (przywracanie) -->
<form method="post" id="attActionForm" class="d-none">
  <input type="hidden" name="_csrf"     value="<?= h(csrf_token()) ?>">
  <input type="hidden" name="_op"       id="aa_op"  value="">
  <input type="hidden" name="client_id" id="aa_cid" value="">
</form>
<?php endif; ?>

<script>
function openCancelAtt(cid, name) {
  document.getElementById('ca_cid').value = cid;
  document.getElementById('ca_name').textContent = name;
  var t = document.getElementById('ca_reason'); if (t) t.value = '';
  new bootstrap.Modal(document.getElementById('cancelAttModal')).show();
}
function restoreAtt(cid) {
  if (!confirm('Przywrócić udział uczestnika?')) return;
  document.getElementById('aa_op').value  = 'restore_attendee';
  document.getElementById('aa_cid').value = cid;
  document.getElementById('attActionForm').submit();
}

function recalcDur() {
  var tf = document.getElementById('ltime_from').value;
  var tt = document.getElementById('ltime_to').value;
  var disp = document.getElementById('dur_display');
  if (!tf || !tt) return;
  var m = Math.round((new Date('1970-01-01T'+tt) - new Date('1970-01-01T'+tf)) / 60000);
  if (m <= 0) { if (disp) disp.textContent = '?'; return; }
  document.getElementById('ldur').value = m;
  if (disp) {
    var h = Math.floor(m/60), min = m%60;
    disp.textContent = h > 0 ? h+'h'+(min?' '+min+'m':'') : min+'m';
  }
}

function rowToggle(cb) {
  var row = document.getElementById('row_' + cb.value);
  if (row) row.className = row.className.replace(/\b(present|absent)\b/, cb.checked ? 'present' : 'absent');
  updateCount();
}

function toggleAll(val) {
  document.querySelectorAll('.att-cb').forEach(function(cb) {
    cb.checked = val;
    rowToggle(cb);
  });
}

function updateCount() {
  var total   = document.querySelectorAll('.att-cb').length;
  var checked = document.querySelectorAll('.att-cb:checked').length;
  var el = document.getElementById('att_num');
  if (el) el.textContent = checked;
}

updateCount();
</script>

<?php include dirname(dirname(__DIR__)) . '/karty30/includes/footer_k30.php'; ?>
