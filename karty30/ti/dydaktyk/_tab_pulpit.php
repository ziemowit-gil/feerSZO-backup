<?php
/** _tab_pulpit.php — Dashboard prowadzącego (tab=pulpit).
 *  Wymaga: $dash_today[], $dash_upcoming[], $dash_pending_cancel (z index.php).
 *  Używa MDUI 2 (Material Design 3) załadowanego w index.php.
 */

/* Pomocnicze tłumaczenie statusu lekcji */
$_pulpit_status_label = static function (string $s): string {
    return match($s) {
        'held'              => 'Odbyta',
        'individual_change' => 'Odbyta (ind.)',
        'remote_material'   => 'Praca własna',
        'planned'           => 'Zaplanowana',
        'cancelled'         => 'Odwołana',
        default             => ucfirst($s),
    };
};

/* Dni tygodnia PL */
$_dow_pl = ['Sun'=>'Nd','Mon'=>'Pn','Tue'=>'Wt','Wed'=>'Śr','Thu'=>'Cz','Fri'=>'Pt','Sat'=>'Sb'];

/* Czy status = "odbyła się" */
$_is_done = static fn(string $s): bool =>
    in_array($s, ['held','individual_change','remote_material'], true);
?>
<section aria-label="Pulpit dydaktyka" class="dyd-pulpit-wrap">

  <style>
  /* Pulpit — MD3-aligned Bootstrap overlay */
  .dyd-pulpit-wrap { padding: 1.1rem 1rem 2rem; max-width: 920px; }

  /* Alert banner */
  .dyd-p-banner {
    display: flex; align-items: center; gap: .75rem;
    border-radius: 12px; padding: .75rem 1rem;
    margin-bottom: 1.1rem; font-size: .875rem; line-height: 1.4;
  }
  .dyd-p-banner.warn  { background: #fff7ed; color: #7c2d12; border: 1px solid #fed7aa; }
  .dyd-p-banner.info  { background: #eff6ff; color: #1e3a5f; border: 1px solid #bfdbfe; }
  [data-bs-theme="dark"] .dyd-p-banner.warn { background: #3c1a00; color: #fdba74; border-color: #92400e; }
  [data-bs-theme="dark"] .dyd-p-banner.info { background: #0c1f3a; color: #93c5fd; border-color: #1d4ed8; }

  /* Section heading */
  .dyd-p-sec { margin-bottom: 1.5rem; }
  .dyd-p-sec-head {
    display: flex; align-items: center; gap: .55rem;
    margin-bottom: .75rem; font-size: .92rem; font-weight: 700;
  }
  .dyd-p-sec-meta {
    margin-left: auto; font-size: .72rem; font-weight: 600;
    text-transform: uppercase; letter-spacing: .06em;
    color: var(--bs-secondary-color);
  }

  /* Today zone */
  .dyd-today-zone {
    border: 1.5px solid rgba(37,99,235,.22);
    background: rgba(219,234,254,.28);
    border-radius: 16px; padding: .9rem 1rem;
    margin-bottom: 1.25rem;
  }
  [data-bs-theme="dark"] .dyd-today-zone {
    background: rgba(30,58,138,.2); border-color: rgba(96,165,250,.3);
  }
  .dyd-today-date {
    font-size: .72rem; font-weight: 700; text-transform: uppercase;
    letter-spacing: .07em; color: #1d4ed8;
    margin-bottom: .65rem; display: flex; align-items: center; gap: .35rem;
  }
  [data-bs-theme="dark"] .dyd-today-date { color: #93c5fd; }

  /* Lesson tile */
  .dyd-lt {
    display: flex; border-radius: 10px; overflow: hidden;
    border: 1px solid var(--bs-border-color);
    background: var(--bs-body-bg);
    transition: box-shadow .12s;
    margin-bottom: .45rem;
  }
  .dyd-lt:last-child { margin-bottom: 0; }
  .dyd-lt:hover { box-shadow: 0 2px 10px rgba(0,0,0,.09); }
  .dyd-lt-stripe { width: 4px; flex-shrink: 0; }
  .dyd-lt-stripe.s-today    { background: #2563eb; }
  .dyd-lt-stripe.s-planned  { background: #64748b; }
  .dyd-lt-stripe.s-done     { background: #16a34a; }
  .dyd-lt-stripe.s-cancelled { background: #9ca3af; }
  .dyd-lt-inner {
    flex: 1; display: flex; align-items: center; gap: .85rem;
    padding: .65rem .9rem; flex-wrap: wrap;
  }
  .dyd-lt-time {
    display: flex; flex-direction: column; gap: .05rem;
    font-size: .78rem; font-weight: 700; color: var(--bs-secondary-color);
    min-width: 88px; font-variant-numeric: tabular-nums;
  }
  .dyd-lt-time .dyd-lt-day { font-size: .67rem; text-transform: uppercase; letter-spacing: .05em; }
  .dyd-lt-time .dyd-lt-now { font-size: .68rem; color: #2563eb; font-weight: 700; margin-top: .1rem; }
  [data-bs-theme="dark"] .dyd-lt-time .dyd-lt-now { color: #93c5fd; }
  .dyd-lt-info { flex: 1; min-width: 140px; }
  .dyd-lt-course {
    font-weight: 600; font-size: .88rem; color: var(--bs-body-color);
    text-decoration: none;
  }
  .dyd-lt-course:hover { text-decoration: underline; color: #2563eb; }
  .dyd-lt-topic { font-size: .77rem; color: var(--bs-secondary-color); margin-top: .1rem; }
  .dyd-lt-actions { display: flex; align-items: center; gap: .4rem; flex-wrap: wrap; }

  /* Status chip */
  .dyd-chip {
    display: inline-flex; align-items: center; gap: .25rem;
    padding: .25em .7em; border-radius: 6px; font-size: .7rem; font-weight: 600;
    white-space: nowrap;
  }
  .dyd-chip.planned  { background: rgba(37,99,235,.1);  color: #1d4ed8; }
  .dyd-chip.done     { background: rgba(22,163,74,.1);  color: #15803d; }
  .dyd-chip.now      { background: #fef3c7; color: #92400e; }
  .dyd-chip.cancelled{ background: rgba(107,114,128,.12); color: var(--bs-secondary-color); }
  [data-bs-theme="dark"] .dyd-chip.planned  { background: rgba(96,165,250,.12); color: #93c5fd; }
  [data-bs-theme="dark"] .dyd-chip.done     { background: rgba(34,197,94,.12);  color: #4ade80; }
  [data-bs-theme="dark"] .dyd-chip.now      { background: #422006; color: #fbbf24; }

  /* Empty state */
  .dyd-empty {
    text-align: center; padding: 2rem 1rem;
    color: var(--bs-secondary-color); font-size: .875rem;
  }

  /* Attendance dialog form */
  .dyd-att-item {
    display: flex; align-items: center; gap: .75rem;
    padding: .5rem .25rem; border-bottom: 1px solid var(--bs-border-color);
    cursor: pointer;
  }
  .dyd-att-item:last-child { border-bottom: none; }
  .dyd-att-item:hover { background: rgba(0,0,0,.03); border-radius: 6px; }
  [data-bs-theme="dark"] .dyd-att-item:hover { background: rgba(255,255,255,.05); }
  </style>

<?php if ($dash_pending_cancel > 0): ?>
  <div class="dyd-p-banner warn" role="alert">
    <i class="bi bi-exclamation-triangle-fill flex-shrink-0" aria-hidden="true"></i>
    <div>
      <strong><?= $dash_pending_cancel ?> <?= $dash_pending_cancel === 1 ? 'wniosek' : 'wnioski' ?> o odwołanie udziału</strong>
      oczekuje na Twoją decyzję.
      <?php if ($cur_course): ?>
      <a href="index.php?course=<?= $cur_course ?>&tab=lekcje" class="fw-semibold ms-1">Przejdź do lekcji</a>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<?php if ($dyd_notices_unread > 0): ?>
  <div class="dyd-p-banner info" role="alert">
    <i class="bi bi-megaphone-fill flex-shrink-0" aria-hidden="true"></i>
    <div class="flex-grow-1">
      <strong><?= $dyd_notices_unread === 1 ? '1 nieprzeczytany komunikat' : $dyd_notices_unread . ' nieprzeczytane komunikaty' ?></strong>
      placówki czekają na przeczytanie.
    </div>
    <a href="index.php?tab=komunikaty" class="btn btn-sm btn-outline-primary flex-shrink-0">
      <i class="bi bi-megaphone me-1" aria-hidden="true"></i>Przejdź do komunikatów
    </a>
  </div>
<?php endif; ?>

<?php if (!empty($dyd_wizard_sessions)): ?>
  <div class="dyd-p-banner info" role="complementary" aria-label="Uzupełnij dzisiejsze zajęcia">
    <i class="bi bi-magic flex-shrink-0" style="font-size:1.25rem" aria-hidden="true"></i>
    <div class="flex-grow-1">
      <strong>Masz dziś <?= count($dyd_wizard_sessions) === 1 ? 'zaplanowaną lekcję' : count($dyd_wizard_sessions) . ' zaplanowane lekcje' ?> do uzupełnienia</strong>
      &mdash; uzupełnij obecność i temat w jednym kroku.
    </div>
    <button type="button" class="btn btn-primary btn-sm flex-shrink-0"
            onclick="wizOpen(<?= count($dyd_wizard_sessions)===1 ? (int)$dyd_wizard_sessions[0]['id'] : 'null' ?>)"
            aria-haspopup="dialog">
      <i class="bi bi-magic me-1" aria-hidden="true"></i>Uruchom kreator
    </button>
  </div>
<?php endif; ?>

<?php /* ── Dzisiaj ── */ ?>
<?php
$_today_str = date('Y-m-d');
$_day_names = ['Mon'=>'Poniedziałek','Tue'=>'Wtorek','Wed'=>'Środa','Thu'=>'Czwartek',
                'Fri'=>'Piątek','Sat'=>'Sobota','Sun'=>'Niedziela'];
$_today_day = $_day_names[date('D')] ?? '';
$_today_fmt = date('j') . '.' . date('m') . '.' . date('Y');
?>
<section class="dyd-p-sec">
  <div class="dyd-today-zone" <?= empty($dash_today) ? 'style="opacity:.75"' : '' ?>>
    <div class="dyd-today-date" aria-label="Dziś: <?= $_today_day ?>, <?= $_today_fmt ?>">
      <i class="bi bi-clock" aria-hidden="true"></i>
      Dziś &mdash; <?= h($_today_day) ?>, <?= h($_today_fmt) ?>
    </div>

    <?php if (empty($dash_today)): ?>
    <div class="dyd-empty" aria-label="Brak lekcji na dziś" style="padding:1rem 0">
      <i class="bi bi-check2-circle d-block mb-1 fs-4" aria-hidden="true"></i>
      Brak zaplanowanych lekcji na dziś.
    </div>
    <?php else: ?>
    <div role="list" aria-label="Dzisiejsze lekcje">
      <?php
      $now_hm  = date('H:i');
      foreach ($dash_today as $_s):
        $tf  = substr((string)($_s['time_from'] ?? ''), 0, 5);
        $tt  = substr((string)($_s['time_to'] ?? ''), 0, 5);
        $tf  = $tf  ?: '?:??';
        $tt  = $tt  ?: '?:??';
        $is_done  = $_is_done($_s['status']);
        $is_now   = !$is_done && $_s['status'] !== 'cancelled' && $tf <= $now_hm && ($tt === '?:??' || $tt >= $now_hm);
        $is_canc  = $_s['status'] === 'cancelled';
        $stripe   = $is_done ? 's-done' : ($is_now ? 's-today' : ($is_canc ? 's-cancelled' : 's-planned'));
        $chip_cls = $is_done ? 'done' : ($is_now ? 'now' : ($is_canc ? 'cancelled' : 'planned'));
        $chip_lbl = $is_now ? 'Teraz' : $_pulpit_status_label($_s['status']);
        $enrolled = (int)($_s['enrolled'] ?? 0);
        $course_url = 'index.php?course=' . (int)$_s['course_id'] . '&tab=lekcje';
        $meet_url = trim((string)($_s['meeting_url'] ?: $_s['default_meeting_url'] ?? ''));

        /* Preloaduj listę kursantów lekcji dla przycisku Oznacz obecność */
        $_att_rows = db_all(
            "SELECT a.client_id AS id, COALESCE(cl.name, '') AS name,
                    COALESCE(a.attended,0) AS attended,
                    COALESCE(a.cancelled,0) AS cancelled,
                    COALESCE(a.cancel_pending,0) AS pending
             FROM k30_ti_attendance a
             LEFT JOIN k30_clients cl ON cl.id=a.client_id
             WHERE a.session_id=?
             ORDER BY cl.name",
            [(int)$_s['id']]
        );
        $_att_active = array_filter($_att_rows, fn($r) => !$r['cancelled'] && !$r['pending']);
      ?>
      <article class="dyd-lt" role="listitem"
               aria-label="<?= h($_s['course_name']) ?>, <?= h($tf) ?>–<?= h($tt) ?><?= $is_canc?' — odwołana':'' ?>">
        <div class="dyd-lt-stripe <?= $stripe ?>" aria-hidden="true"></div>
        <div class="dyd-lt-inner">
          <div class="dyd-lt-time">
            <span><?= h($tf) ?>–<?= h($tt) ?></span>
            <?php if ($is_now): ?><span class="dyd-lt-now" aria-label="Lekcja trwa">Trwa</span><?php endif; ?>
          </div>
          <div class="dyd-lt-info">
            <a href="<?= h($course_url) ?>" class="dyd-lt-course"><?= h($_s['course_name']) ?></a>
            <div class="dyd-lt-topic">
              <?= $_s['topic'] ? h($_s['topic']) : 'Lekcja zaplanowana' ?>
              <?php if ($enrolled > 0): ?>&nbsp;·&nbsp; <?= $enrolled ?> kursantów<?php endif; ?>
            </div>
          </div>
          <div class="dyd-lt-actions">
            <span class="dyd-chip <?= $chip_cls ?>"><?= h($chip_lbl) ?></span>

            <?php if (!$is_done && !$is_canc && !empty($_att_active)): ?>
            <button type="button"
                    class="btn btn-primary btn-sm d-inline-flex align-items-center gap-1"
                    data-bs-toggle="modal"
                    data-bs-target="#dyd-pulpit-att-<?= (int)$_s['id'] ?>"
                    aria-haspopup="dialog"
                    aria-label="Oznacz obecność: <?= h($_s['course_name']) ?>">
              <i class="bi bi-check2-all" aria-hidden="true"></i>
              Obecność
            </button>
            <?php endif; ?>

            <?php if ($meet_url): ?>
            <a href="<?= h($meet_url) ?>" target="_blank" rel="noopener noreferrer"
               class="btn btn-outline-secondary btn-sm d-inline-flex align-items-center gap-1"
               aria-label="Otwórz spotkanie Zoom (nowa karta)">
              <i class="bi bi-camera-video" aria-hidden="true"></i>
              Zoom
            </a>
            <?php endif; ?>

            <a href="<?= h($course_url) ?>" class="btn btn-link btn-sm text-decoration-none p-0 ms-1"
               aria-label="Szczegóły kursu <?= h($_s['course_name']) ?>">
              Kurs
            </a>
          </div>
        </div>
      </article>

      <?php /* Modal obecności (Bootstrap 5 — WCAG) */ ?>
      <?php if (!$is_done && !$is_canc && !empty($_att_active)): ?>
      <div class="modal fade" id="dyd-pulpit-att-<?= (int)$_s['id'] ?>" tabindex="-1"
           aria-labelledby="dyd-pulpit-att-lbl-<?= (int)$_s['id'] ?>" aria-modal="true" role="dialog">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
          <div class="modal-content">
            <div class="modal-header">
              <h2 class="modal-title fs-6 fw-semibold" id="dyd-pulpit-att-lbl-<?= (int)$_s['id'] ?>">
                <i class="bi bi-check2-all me-2" aria-hidden="true"></i>Oznacz obecność
              </h2>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
            </div>
            <form method="post" action="index.php?course=<?= (int)$_s['course_id'] ?>&tab=lekcje">
              <input type="hidden" name="_token"     value="<?= h(dyd_token()) ?>">
              <input type="hidden" name="_op"        value="save_attendance">
              <input type="hidden" name="session_id" value="<?= (int)$_s['id'] ?>">
              <input type="hidden" name="course_id"  value="<?= (int)$_s['course_id'] ?>">
              <input type="hidden" name="_tab"       value="lekcje">
              <div class="modal-body">
                <p class="text-secondary small mb-3">
                  <?= h($_s['course_name']) ?> &nbsp;·&nbsp; <?= h($tf) ?>–<?= h($tt) ?>
                </p>
                <div class="d-flex justify-content-between align-items-center mb-2">
                  <span class="text-secondary small"><?= count($_att_active) ?> kursantów</span>
                  <button type="button" class="btn btn-link btn-sm text-decoration-none p-0"
                          onclick="dydPulpitToggleAll(this)"
                          aria-label="Zaznacz / odznacz wszystkich">
                    Zaznacz wszystkich
                  </button>
                </div>
                <div role="list" aria-label="Lista kursantów — zaznacz obecnych">
                  <?php foreach ($_att_active as $_a): ?>
                  <label class="dyd-att-item" role="listitem">
                    <input type="checkbox" name="attended[]"
                           value="<?= (int)$_a['id'] ?>"
                           class="dyd-att-cb form-check-input m-0 flex-shrink-0"
                           <?= $_a['attended'] ? 'checked' : '' ?>
                           style="width:20px;height:20px"
                           aria-label="Obecność: <?= h($_a['name']) ?>">
                    <span style="font-size:.875rem"><?= h($_a['name']) ?></span>
                  </label>
                  <?php endforeach; ?>
                </div>
              </div>
              <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anuluj</button>
                <button type="submit" class="btn btn-primary d-inline-flex align-items-center gap-2">
                  <i class="bi bi-check2" aria-hidden="true"></i>Zapisz obecność
                </button>
              </div>
            </form>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</section>

<?php /* ── Nadchodzące (7 dni) ── */ ?>
<?php if (!empty($dash_upcoming)): ?>
<section class="dyd-p-sec" aria-label="Nadchodzące lekcje">
  <div class="dyd-p-sec-head">
    <i class="bi bi-calendar3 text-primary" aria-hidden="true"></i>
    <span>Nadchodzące</span>
    <span class="dyd-p-sec-meta" aria-label="Zakres: następne 7 dni">następne 7 dni</span>
  </div>
  <div role="list" aria-label="Nadchodzące lekcje">
    <?php foreach ($dash_upcoming as $_s):
      $tf  = substr((string)($_s['time_from'] ?? ''), 0, 5) ?: '?:??';
      $tt  = substr((string)($_s['time_to']   ?? ''), 0, 5) ?: '?:??';
      $day_short  = $_dow_pl[date('D', strtotime((string)$_s['lesson_date']))] ?? '';
      $day_date   = date('j.m', strtotime((string)$_s['lesson_date']));
      $course_url = 'index.php?course=' . (int)$_s['course_id'] . '&tab=lekcje';
    ?>
    <article class="dyd-lt" role="listitem"
             aria-label="<?= h($_s['course_name']) ?>, <?= h($day_short) ?> <?= h($day_date) ?>, <?= h($tf) ?>">
      <div class="dyd-lt-stripe s-planned" aria-hidden="true"></div>
      <div class="dyd-lt-inner">
        <div class="dyd-lt-time">
          <span class="dyd-lt-day"><?= h($day_short) ?> <?= h($day_date) ?></span>
          <span><?= h($tf) ?>–<?= h($tt) ?></span>
        </div>
        <div class="dyd-lt-info">
          <a href="<?= h($course_url) ?>" class="dyd-lt-course"><?= h($_s['course_name']) ?></a>
          <div class="dyd-lt-topic"><?= $_s['topic'] ? h($_s['topic']) : 'Lekcja zaplanowana' ?></div>
        </div>
        <div class="dyd-lt-actions">
          <span class="dyd-chip planned"><?= h($_pulpit_status_label($_s['status'])) ?></span>
          <a href="<?= h($course_url) ?>" class="btn btn-link btn-sm text-decoration-none p-0 ms-1"
             aria-label="Kurs <?= h($_s['course_name']) ?>">Kurs</a>
        </div>
      </div>
    </article>
    <?php endforeach; ?>
  </div>
</section>
<?php elseif (empty($dash_today)): ?>
<div class="dyd-empty">
  <i class="bi bi-calendar-check d-block mb-2 fs-3" aria-hidden="true"></i>
  Brak zaplanowanych lekcji w najbliższych 7 dniach.
</div>
<?php endif; ?>

</section><?php /* /dyd-pulpit-wrap */ ?>

<script>
function dydPulpitToggleAll(btn) {
  var form   = btn.closest('form');
  var boxes  = form.querySelectorAll('.dyd-att-cb');
  var allChk = Array.from(boxes).every(function(b){ return b.checked; });
  boxes.forEach(function(b){ b.checked = !allChk; });
  btn.textContent = allChk ? 'Zaznacz wszystkich' : 'Odznacz wszystkich';
}
</script>
