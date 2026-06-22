<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/karty30.php';

k30_require_access();
ika_require(APP_URL . '/karty30/index.php');
karty30_migrate();

$PAGE_TITLE = 'Dydaktyka — Karty 30';
$can_write  = can_write('karty30') || is_admin() || k30_is_consultant();

// ── Statystyki ──────────────────────────────────────────────────────────────
function _k($sql, array $p = []): int {
    try { return (int)(db_one($sql, $p)['c'] ?? 0); } catch (\Throwable $e) { return 0; }
}

$stats = [
    'clients'       => _k("SELECT COUNT(*) AS c FROM k30_clients"),
    'today'         => _k("SELECT COUNT(*) AS c FROM k30_schedules WHERE DATE(start_time)=DATE('now') AND status IN ('preliminary','confirmed')"),
    'week'          => _k("SELECT COUNT(*) AS c FROM k30_schedules WHERE start_time BETWEEN datetime('now') AND datetime('now','+7 days') AND status IN ('preliminary','confirmed')"),
    'consultations' => _k("SELECT COUNT(*) AS c FROM k30_consultations WHERE status='completed'"),
    'waiting'       => _k("SELECT COUNT(*) AS c FROM k30_waiting_list WHERE status IN ('waiting','contacted')"),
    'waiting_urgent'=> _k("SELECT COUNT(*) AS c FROM k30_waiting_list WHERE status IN ('waiting','contacted') AND priority='pilny'"),
    'blacklist'     => _k("SELECT COUNT(*) AS c FROM k30_blacklist"),
];

// Godziny PFRON — łącznie bezpłatne tego miesiąca
$pfron_month = (float)(db_one(
    "SELECT COALESCE(SUM(s.free_hours),0) AS h FROM k30_schedules s
     WHERE s.billing_type='pfron' AND s.status NOT IN ('cancelled','rejected')
     AND strftime('%Y-%m',s.start_time)=strftime('%Y-%m','now')"
)['h'] ?? 0);

// Należności (kwoty do zapłaty, nieodbyłe terminy)
$due_today = (float)(db_one(
    "SELECT COALESCE(SUM(s.amount_due),0) AS a FROM k30_schedules s
     WHERE DATE(s.start_time)=DATE('now') AND s.amount_due > 0 AND s.status NOT IN ('cancelled','rejected')"
)['a'] ?? 0);

// Konta M365 — terminy ważności (kolumny dokładane w karty30/admin/m365.php; _k() bezpieczne gdy brak)
$_m365_base = "FROM k30_clients WHERE m365_user_id IS NOT NULL AND m365_user_id<>''"
            . " AND m365_expires_at IS NOT NULL AND m365_expires_at<>''"
            . " AND (m365_disabled_at IS NULL OR m365_disabled_at='')";
$m365_exp7     = _k("SELECT COUNT(*) AS c $_m365_base AND date(m365_expires_at) BETWEEN date('now') AND date('now','+7 days')");
$m365_expired  = _k("SELECT COUNT(*) AS c $_m365_base AND date(m365_expires_at) < date('now')");

// Najbliższe terminy
$upcoming = db_all(
    "SELECT s.id, s.start_time, s.duration_minutes, s.status, s.time_from, s.time_to,
            s.billing_type, s.amount_due, s.is_remote,
            c.name AS client_name,
            u.name AS consultant_name,
            r.name AS resource_name
     FROM k30_schedules s
     LEFT JOIN k30_clients c ON c.id=s.client_id
     LEFT JOIN users u ON u.id=s.assigned_to
     LEFT JOIN resources r ON r.id=s.resource_id
     WHERE s.start_time >= datetime('now') AND s.status IN ('preliminary','confirmed')
     ORDER BY s.start_time LIMIT 10"
);

// Oczekujący (top 5 pilnych)
$waiting_top = db_all(
    "SELECT w.*, cl.name AS client_name, cl.phone AS client_phone
     FROM k30_waiting_list w
     JOIN k30_clients cl ON cl.id=w.client_id
     WHERE w.status IN ('waiting','contacted')
     ORDER BY CASE w.priority WHEN 'pilny' THEN 1 WHEN 'pfron' THEN 2 ELSE 3 END, w.created_at
     LIMIT 5"
);

// ── Moduł Dydaktyka (TI) — statystyki ────────────────────────────────────────
@require_once __DIR__ . '/../includes/ti_messages.php';
$ti = [
    'courses'       => _k("SELECT COUNT(*) AS c FROM k30_ti_courses WHERE status!='cancelled' AND is_active=1"),
    'students'      => _k("SELECT COUNT(DISTINCT client_id) AS c FROM k30_ti_enrollments WHERE status='active'"),
    'lessons_upc'   => _k("SELECT COUNT(*) AS c FROM k30_ti_sessions WHERE status='planned' AND lesson_date>=date('now')"),
    'lessons_today' => _k("SELECT COUNT(*) AS c FROM k30_ti_sessions WHERE status='planned' AND lesson_date=date('now')"),
    'hw_active'     => _k("SELECT COUNT(*) AS c FROM k30_ti_homework WHERE is_active=1"),
    'hw_tograde'    => _k("SELECT COUNT(*) AS c FROM k30_ti_homework_submissions WHERE status='submitted'"),
];
$ti_msg_unread = function_exists('ti_msg_unread_for_staff') ? (int)ti_msg_unread_for_staff() : 0;
$ti_lessons = db_all(
    "SELECT s.id, s.lesson_date, s.time_from, s.time_to, c.name AS course_name
     FROM k30_ti_sessions s JOIN k30_ti_courses c ON c.id=s.course_id
     WHERE s.status='planned' AND s.lesson_date>=date('now')
     ORDER BY s.lesson_date, s.time_from LIMIT 6"
);

include __DIR__ . '/includes/header_k30.php';
?>

<style>
/* ── KPI cards ─────────────────────────────────────────────── */
.kpi {
  background: #fff;
  border: 1.5px solid #e2e8f0;
  border-radius: 12px;
  padding: 1rem 1.1rem 1rem;
  text-decoration: none;
  display: block;
  transition: box-shadow .15s, border-color .15s;
  position: relative;
  overflow: hidden;
}
.kpi:hover, .kpi:focus-visible {
  box-shadow: 0 4px 18px rgba(0,0,0,.10);
  border-color: #94a3b8;
}
.kpi::before {
  content:'';
  position:absolute;
  top:0; left:0; right:0;
  height:3px;
  background: var(--kpi-color, #6366f1);
}
.kpi-icon {
  width:36px; height:36px; border-radius:8px;
  display:flex; align-items:center; justify-content:center;
  font-size:1rem; margin-bottom:.6rem;
  background: var(--kpi-bg, #eff6ff);
  color: var(--kpi-color, #2563eb);
}
.kpi-val {
  font-size:1.85rem; font-weight:800; line-height:1;
  color: var(--kpi-color, #1e293b);
  margin-bottom:.2rem;
}
.kpi-lbl { font-size:.75rem; color:#64748b; font-weight:500; }
.kpi-sub { font-size:.7rem; color:#94a3b8; margin-top:.15rem; }

/* ── Panel cards ────────────────────────────────────────────── */
.panel {
  background:#fff; border:1.5px solid #e2e8f0;
  border-radius:12px; overflow:hidden;
}
.panel-head {
  display:flex; align-items:center; justify-content:space-between;
  padding:.7rem 1rem; border-bottom:1px solid #f1f5f9;
  font-size:.82rem; font-weight:700; color:#1e293b;
}
.panel-row {
  display:flex; align-items:center; gap:.75rem;
  padding:.65rem 1rem; border-bottom:1px solid #f8fafc;
  font-size:.83rem; color: #1e293b;
  text-decoration:none; transition:background .1s;
}
.panel-row:last-child { border-bottom:none; }
.panel-row:hover { background:#f8fafc; }
a.panel-row:focus-visible { outline:3px solid var(--k30-focus); outline-offset:-3px; }

/* Quick-action buttons */
.qa { display:flex; align-items:center; gap:.6rem; padding:.55rem .9rem; border-radius:8px; font-size:.84rem; font-weight:600; text-decoration:none; transition:background .12s, color .12s; border:1.5px solid transparent; }
.qa:focus-visible { outline:3px solid var(--k30-focus); outline-offset:2px; }
.qa-primary   { background:#0f4c91; color:#fff; }
.qa-primary:hover { background:#0c3d78; color:#fff; }
.qa-outline   { background:#fff; border-color:#cbd5e1; color:#374151; }
.qa-outline:hover { background:#f1f5f9; color:#1e293b; }
.qa-warn      { background:#fff; border-color:#f59e0b; color:#92400e; }
.qa-warn:hover { background:#fffbeb; color:#78350f; }

/* ── Waiting priority badge ─────────────────────────────────── */
.wp { display:inline-flex;align-items:center;gap:.25rem;padding:.15em .5em;border-radius:4px;font-size:.7rem;font-weight:700 }

/* ── Panele modułów (2 działające moduły) ───────────────────── */
.mod-panel { display:flex; flex-direction:column; height:100%; border-top:3px solid var(--mod,#6366f1); }
.mod-head { display:flex; align-items:center; gap:.8rem; padding:1rem 1.1rem; border-bottom:1px solid #f1f5f9; }
.mod-ic { width:46px; height:46px; border-radius:11px; flex-shrink:0; display:flex; align-items:center; justify-content:center; font-size:1.35rem; background:var(--mod,#6366f1); color:#fff; }
.mod-title { font-size:1.05rem; font-weight:800; margin:0; line-height:1.2; color:#0f172a; }
.mod-sub { font-size:.76rem; color:#64748b; }
.mod-cta { white-space:nowrap; }
/* Mini KPI w panelu modułu */
.mod-kpis { display:grid; grid-template-columns:repeat(3,1fr); gap:1px; background:#eef0f4; border-bottom:1px solid #f1f5f9; }
.mstat { background:#fff; padding:.7rem .8rem; text-decoration:none; display:block; transition:background .12s; }
.mstat:hover { background:#f8fafc; }
.mstat-val { font-size:1.45rem; font-weight:800; line-height:1; color:var(--mod,#1e293b); }
.mstat-lbl { font-size:.7rem; color:#64748b; font-weight:600; margin-top:.2rem; }
.mstat-sub { font-size:.64rem; color:#94a3b8; }
/* Lista skrótów modułu */
.mod-links { display:flex; flex-wrap:wrap; gap:.4rem; padding:.85rem 1rem; margin-top:auto; border-top:1px solid #f1f5f9; }
.mod-link { display:inline-flex; align-items:center; gap:.35rem; font-size:.78rem; font-weight:600; text-decoration:none; color:#374151; border:1.5px solid #e2e8f0; border-radius:7px; padding:.3rem .6rem; transition:background .12s,border-color .12s,color .12s; }
.mod-link:hover { background:var(--k30-purple-bg); border-color:var(--k30-purple-mid); color:var(--k30-purple); }
.mod-mini { display:flex; align-items:center; gap:.6rem; padding:.5rem 1rem; border-bottom:1px solid #f8fafc; font-size:.82rem; text-decoration:none; color:#1e293b; }
.mod-mini:last-of-type { border-bottom:0; }
.mod-mini:hover { background:#f8fafc; }
.mod-mini-date { flex-shrink:0; width:42px; text-align:center; }
</style>

<!-- Nagłówek strony -->
<div class="d-flex align-items-center gap-3 mb-4 flex-wrap">
  <div role="img" aria-label="Dydaktyka"
       style="width:46px;height:46px;border-radius:11px;background:var(--k30-purple);display:flex;align-items:center;justify-content:center;flex-shrink:0">
    <i class="bi bi-card-checklist text-white" style="font-size:1.3rem" aria-hidden="true"></i>
  </div>
  <div>
    <h1 class="h4 mb-0 fw-bold">Dydaktyka — Karty 30</h1>
    <p class="text-muted mb-0" style="font-size:.84rem">Dwa moduły: <strong>Konsultacje i wizyty</strong> · <strong>Dydaktyka (TI)</strong></p>
  </div>
  <?php if ($can_write): ?>
  <nav class="ms-auto d-flex gap-2 flex-wrap" aria-label="Szybkie akcje">
    <a href="<?= APP_URL ?>/karty30/schedules/add.php" class="qa qa-primary">
      <i class="bi bi-calendar-plus" aria-hidden="true"></i>Nowy termin
    </a>
    <a href="<?= APP_URL ?>/karty30/clients/add.php" class="qa qa-outline">
      <i class="bi bi-person-plus" aria-hidden="true"></i>Nowy beneficjent
    </a>
    <a href="<?= APP_URL ?>/karty30/waiting/index.php" class="qa qa-warn">
      <i class="bi bi-hourglass-split" aria-hidden="true"></i>Kolejka
      <?php if ($stats['waiting']): ?>
      <span class="badge bg-warning text-dark ms-1" aria-label="<?= $stats['waiting'] ?> oczekujących"><?= $stats['waiting'] ?></span>
      <?php endif; ?>
    </a>
  </nav>
  <?php endif; ?>
</div>

<!-- M365 — alert administracyjny (gdy konta wygasają) -->
<?php if (is_admin() && ($m365_exp7 > 0 || $m365_expired > 0)): ?>
<a href="<?= APP_URL ?>/karty30/admin/m365.php" class="d-flex align-items-center gap-2 text-decoration-none mb-3 p-2 rounded"
   style="border:1.5px solid <?= $m365_expired ? '#dc2626' : '#f59e0b' ?>;background:<?= $m365_expired ? '#fef2f2' : '#fffbeb' ?>;color:<?= $m365_expired ? '#7f1d1d' : '#78350f' ?>;font-size:.85rem">
  <i class="bi bi-microsoft" aria-hidden="true"></i>
  <span><strong><?= $m365_exp7 ?></strong> kont M365 wygasa w ciągu 7 dni<?= $m365_expired ? ', <strong>'.$m365_expired.'</strong> już wygasło' : '' ?> — kliknij, aby zarządzać.</span>
</a>
<?php endif; ?>

<!-- ══ DWA DZIAŁAJĄCE MODUŁY ══════════════════════════════════════ -->
<section aria-label="Moduły" class="row g-3 mb-3">

  <!-- Moduł 1: Konsultacje i wizyty -->
  <div class="col-lg-6">
    <div class="panel mod-panel" style="--mod:#0f4c91">
      <div class="mod-head">
        <div class="mod-ic" aria-hidden="true"><i class="bi bi-clipboard2-pulse-fill"></i></div>
        <div>
          <h2 class="mod-title">Konsultacje i wizyty</h2>
          <div class="mod-sub">Beneficjenci · harmonogram · konsultacje · PFRON</div>
        </div>
        <a class="btn btn-k30 btn-sm ms-auto mod-cta" href="<?= APP_URL ?>/karty30/schedules/index.php" aria-label="Otwórz moduł Konsultacje i wizyty">Otwórz</a>
      </div>
      <div class="mod-kpis">
        <a class="mstat" href="<?= APP_URL ?>/karty30/clients/index.php" aria-label="Beneficjentów: <?= $stats['clients'] ?>"><div class="mstat-val" aria-hidden="true"><?= $stats['clients'] ?></div><div class="mstat-lbl" aria-hidden="true">Beneficjentów</div></a>
        <a class="mstat" href="<?= APP_URL ?>/karty30/schedules/index.php" aria-label="Wizyt dziś: <?= $stats['today'] ?>, w tygodniu: <?= $stats['week'] ?>"><div class="mstat-val" aria-hidden="true"><?= $stats['today'] ?></div><div class="mstat-lbl" aria-hidden="true">Wizyt dziś</div><div class="mstat-sub" aria-hidden="true"><?= $stats['week'] ?> w tygodniu</div></a>
        <a class="mstat" href="<?= APP_URL ?>/karty30/waiting/index.php" aria-label="Oczekuje na termin: <?= $stats['waiting'] ?><?= $stats['waiting_urgent'] ? ', w tym pilnych: '.$stats['waiting_urgent'] : '' ?>"><div class="mstat-val" aria-hidden="true" style="<?= $stats['waiting_urgent']?'color:#dc2626':'' ?>"><?= $stats['waiting'] ?></div><div class="mstat-lbl" aria-hidden="true">Oczekuje</div><?php if ($stats['waiting_urgent']): ?><div class="mstat-sub" aria-hidden="true" style="color:#dc2626"><?= $stats['waiting_urgent'] ?> pilnych</div><?php endif; ?></a>
        <a class="mstat" href="<?= APP_URL ?>/karty30/consultations/index.php" aria-label="Konsultacji łącznie: <?= $stats['consultations'] ?>"><div class="mstat-val" aria-hidden="true"><?= $stats['consultations'] ?></div><div class="mstat-lbl" aria-hidden="true">Konsultacji</div></a>
        <div class="mstat" aria-label="Godziny PFRON w tym miesiącu: <?= number_format($pfron_month,1,',','') ?>"><div class="mstat-val" aria-hidden="true"><?= number_format($pfron_month,1,',','') ?></div><div class="mstat-lbl" aria-hidden="true">Godz. PFRON</div><div class="mstat-sub" aria-hidden="true"><?= date('m/Y') ?></div></div>
        <a class="mstat" href="<?= APP_URL ?>/karty30/schedules/calendar.php" aria-label="Kalendarz wizyt"><div class="mstat-val" aria-hidden="true" style="font-size:1.2rem"><i class="bi bi-calendar-week"></i></div><div class="mstat-lbl" aria-hidden="true">Kalendarz</div></a>
      </div>
      <div role="list" aria-label="Najbliższe terminy">
        <?php if ($upcoming): foreach (array_slice($upcoming,0,4) as $s):
          $dt = new DateTime($s['start_time']); $isToday = $dt->format('Y-m-d') === date('Y-m-d');
          $tstr = $s['time_from'] ?: $dt->format('H:i'); ?>
        <a class="mod-mini" role="listitem" href="<?= APP_URL ?>/karty30/schedules/view.php?id=<?= (int)$s['id'] ?>"
           aria-label="<?= h($s['client_name']) ?>, <?= $isToday?'dziś':$dt->format('d.m') ?> <?= h($tstr) ?>">
          <div class="mod-mini-date" aria-hidden="true"><div style="font-size:1.05rem;font-weight:800;line-height:1;color:<?= $isToday?'var(--k30-purple)':'#1e293b' ?>"><?= $dt->format('d') ?></div><div style="font-size:.6rem;color:#94a3b8;text-transform:uppercase"><?= $dt->format('m') ?></div></div>
          <div style="flex:1;min-width:0" aria-hidden="true"><div class="fw-semibold text-truncate"><?= h($s['client_name']) ?></div><div style="font-size:.73rem;color:#64748b" class="text-truncate"><?= h($tstr) ?><?= $isToday?' · dziś':'' ?><?= $s['consultant_name']?' · '.h($s['consultant_name']):'' ?></div></div>
          <span aria-hidden="true"><?= k30_status_badge($s['status']) ?></span>
        </a>
        <?php endforeach; else: ?>
        <div class="text-muted text-center py-3" style="font-size:.82rem" role="status">Brak nadchodzących wizyt</div>
        <?php endif; ?>
      </div>
      <nav class="mod-links" aria-label="Skróty: Konsultacje i wizyty">
        <a class="mod-link" href="<?= APP_URL ?>/karty30/clients/index.php"><i class="bi bi-people-fill" aria-hidden="true"></i>Beneficjenci</a>
        <a class="mod-link" href="<?= APP_URL ?>/karty30/schedules/index.php"><i class="bi bi-calendar3" aria-hidden="true"></i>Harmonogram</a>
        <a class="mod-link" href="<?= APP_URL ?>/karty30/consultations/index.php"><i class="bi bi-clipboard2-check" aria-hidden="true"></i>Konsultacje</a>
        <a class="mod-link" href="<?= APP_URL ?>/karty30/waiting/index.php"><i class="bi bi-hourglass-split" aria-hidden="true"></i>Oczekujący<?php if($stats['waiting']): ?> (<?= $stats['waiting'] ?>)<?php endif; ?></a>
        <a class="mod-link" href="<?= APP_URL ?>/karty30/reports/index.php"><i class="bi bi-bar-chart-line" aria-hidden="true"></i>Raporty</a>
      </nav>
    </div>
  </div>

  <!-- Moduł 2: Dydaktyka (TI) -->
  <div class="col-lg-6">
    <div class="panel mod-panel" style="--mod:#4338ca">
      <div class="mod-head">
        <div class="mod-ic" aria-hidden="true"><i class="bi bi-pc-display"></i></div>
        <div>
          <h2 class="mod-title">Dydaktyka (TI)</h2>
          <div class="mod-sub">Kursy · lekcje · zadania · e-dziennik</div>
        </div>
        <a class="btn btn-k30 btn-sm ms-auto mod-cta" href="<?= APP_URL ?>/karty30/ti/index.php" aria-label="Otwórz moduł Dydaktyka TI">Otwórz</a>
      </div>
      <div class="mod-kpis">
        <a class="mstat" href="<?= APP_URL ?>/karty30/ti/index.php" aria-label="Aktywnych kursów: <?= $ti['courses'] ?>"><div class="mstat-val" aria-hidden="true"><?= $ti['courses'] ?></div><div class="mstat-lbl" aria-hidden="true">Kursy</div></a>
        <a class="mstat" href="<?= APP_URL ?>/karty30/ti/kursant/accounts.php" aria-label="Aktywnych kursantów: <?= $ti['students'] ?>"><div class="mstat-val" aria-hidden="true"><?= $ti['students'] ?></div><div class="mstat-lbl" aria-hidden="true">Kursanci</div></a>
        <a class="mstat" href="<?= APP_URL ?>/karty30/ti/index.php" aria-label="Najbliższych lekcji: <?= $ti['lessons_upc'] ?><?= $ti['lessons_today']?', dziś: '.$ti['lessons_today']:'' ?>"><div class="mstat-val" aria-hidden="true"><?= $ti['lessons_upc'] ?></div><div class="mstat-lbl" aria-hidden="true">Lekcje</div><?php if($ti['lessons_today']): ?><div class="mstat-sub" aria-hidden="true"><?= $ti['lessons_today'] ?> dziś</div><?php endif; ?></a>
        <a class="mstat" href="<?= APP_URL ?>/karty30/ti/homework.php" aria-label="Oddań do oceny: <?= $ti['hw_tograde'] ?>"><div class="mstat-val" aria-hidden="true" style="<?= $ti['hw_tograde']?'color:#b45309':'' ?>"><?= $ti['hw_tograde'] ?></div><div class="mstat-lbl" aria-hidden="true">Do oceny</div></a>
        <a class="mstat" href="<?= APP_URL ?>/karty30/ti/homework.php" aria-label="Aktywnych zadań: <?= $ti['hw_active'] ?>"><div class="mstat-val" aria-hidden="true"><?= $ti['hw_active'] ?></div><div class="mstat-lbl" aria-hidden="true">Zadania</div></a>
        <a class="mstat" href="<?= APP_URL ?>/karty30/ti/messages.php" aria-label="Nieprzeczytanych wiadomości: <?= $ti_msg_unread ?>"><div class="mstat-val" aria-hidden="true" style="<?= $ti_msg_unread?'color:#dc2626':'' ?>"><?= $ti_msg_unread ?></div><div class="mstat-lbl" aria-hidden="true">Wiadomości</div></a>
      </div>
      <div role="list" aria-label="Najbliższe lekcje">
        <?php if ($ti_lessons): foreach (array_slice($ti_lessons,0,4) as $l):
          $ld = new DateTime($l['lesson_date']); $isT = $ld->format('Y-m-d') === date('Y-m-d'); ?>
        <a class="mod-mini" role="listitem" href="<?= APP_URL ?>/karty30/ti/lesson.php?id=<?= (int)$l['id'] ?>"
           aria-label="<?= h($l['course_name']) ?>, <?= $isT?'dziś':$ld->format('d.m') ?><?= $l['time_from']?' '.h($l['time_from']):'' ?>">
          <div class="mod-mini-date" aria-hidden="true"><div style="font-size:1.05rem;font-weight:800;line-height:1;color:<?= $isT?'var(--k30-purple)':'#1e293b' ?>"><?= $ld->format('d') ?></div><div style="font-size:.6rem;color:#94a3b8;text-transform:uppercase"><?= $ld->format('m') ?></div></div>
          <div style="flex:1;min-width:0" aria-hidden="true"><div class="fw-semibold text-truncate"><?= h($l['course_name']) ?></div><div style="font-size:.73rem;color:#64748b"><?= $l['time_from']?h($l['time_from']):'—' ?><?= $l['time_to']?'–'.h($l['time_to']):'' ?><?= $isT?' · dziś':'' ?></div></div>
          <i class="bi bi-chevron-right text-muted" aria-hidden="true"></i>
        </a>
        <?php endforeach; else: ?>
        <div class="text-muted text-center py-3" style="font-size:.82rem" role="status">Brak zaplanowanych lekcji</div>
        <?php endif; ?>
      </div>
      <nav class="mod-links" aria-label="Skróty: Dydaktyka TI">
        <a class="mod-link" href="<?= APP_URL ?>/karty30/ti/index.php"><i class="bi bi-pc-display" aria-hidden="true"></i>Kursy</a>
        <a class="mod-link" href="<?= APP_URL ?>/karty30/ti/materials.php"><i class="bi bi-collection-play" aria-hidden="true"></i>Materiały</a>
        <a class="mod-link" href="<?= APP_URL ?>/karty30/ti/homework.php"><i class="bi bi-journal-check" aria-hidden="true"></i>Zadania</a>
        <a class="mod-link" href="<?= APP_URL ?>/karty30/ti/grades.php"><i class="bi bi-table" aria-hidden="true"></i>Dziennik</a>
        <a class="mod-link" href="<?= APP_URL ?>/karty30/ti/messages.php"><i class="bi bi-envelope" aria-hidden="true"></i>Wiadomości<?php if($ti_msg_unread): ?> (<?= $ti_msg_unread ?>)<?php endif; ?></a>
        <a class="mod-link" href="<?= APP_URL ?>/karty30/ti/dydaktyk/login.php"><i class="bi bi-easel2" aria-hidden="true"></i>Panel dydaktyka</a>
      </nav>
    </div>
  </div>

</section>

<!-- ══ Szczegóły: kolejka oczekujących + informacje bieżące ════════ -->
<div class="row g-3">

  <!-- Oczekujący na termin (moduł Konsultacje) -->
  <div class="col-lg-7">
    <section aria-labelledby="waiting-heading" class="panel">
      <div class="panel-head">
        <span id="waiting-heading">
          <i class="bi bi-hourglass-split me-1" aria-hidden="true"></i>Oczekujący na termin
          <?php if ($stats['waiting']): ?><span class="badge bg-warning text-dark ms-1" aria-label="<?= $stats['waiting'] ?> oczekujących"><?= $stats['waiting'] ?></span><?php endif; ?>
        </span>
        <a href="<?= APP_URL ?>/karty30/waiting/index.php"
           class="btn btn-outline-secondary btn-sm py-0 px-2" style="font-size:.73rem">
          Pełna kolejka <span class="visually-hidden">oczekujących</span>
        </a>
      </div>
      <?php if ($waiting_top): ?>
      <ul class="list-unstyled mb-0" role="list">
        <?php foreach ($waiting_top as $w):
          $p    = K30_WAIT_PRIORITIES[$w['priority']] ?? K30_WAIT_PRIORITIES['zwykly'];
          $days = (int)floor((time() - strtotime($w['created_at'])) / 86400);
        ?>
        <li role="listitem">
          <div class="panel-row">
            <span class="wp flex-shrink-0"
                  style="background:<?= h($p['bg']) ?>;color:<?= h($p['color']) ?>"
                  aria-label="Priorytet: <?= h($p['label']) ?>">
              <i class="bi <?= h($p['icon']) ?>" aria-hidden="true"></i><?= h($p['label']) ?>
            </span>
            <div style="flex:1;min-width:0">
              <div class="fw-semibold text-truncate" style="font-size:.85rem"><?= h($w['client_name']) ?></div>
              <div style="font-size:.73rem;color:#64748b" class="text-truncate"><?= h($w['reason']) ?></div>
            </div>
            <div style="font-size:.72rem;flex-shrink:0" class="text-end">
              <div class="<?= $days > 14 ? 'text-danger fw-bold' : 'text-muted' ?>">
                <?= $days === 0 ? 'dziś' : $days . ' dni' ?>
              </div>
              <?php if ($w['client_phone']): ?>
              <a href="tel:<?= h($w['client_phone']) ?>" class="text-muted text-decoration-none"
                 aria-label="Zadzwoń do <?= h($w['client_name']) ?>: <?= h($w['client_phone']) ?>">
                <i class="bi bi-telephone" aria-hidden="true"></i>
              </a>
              <?php endif; ?>
            </div>
            <?php if ($can_write): ?>
            <form method="post" action="<?= APP_URL ?>/karty30/waiting/index.php" class="d-inline">
              <input type="hidden" name="_csrf"     value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="_op"       value="schedule">
              <input type="hidden" name="id"        value="<?= (int)$w['id'] ?>">
              <button type="submit"
                      class="btn btn-sm btn-outline-success py-0 px-2"
                      aria-label="Zaplanuj termin dla <?= h($w['client_name']) ?>">
                <i class="bi bi-calendar-plus" aria-hidden="true"></i>
              </button>
            </form>
            <?php endif; ?>
          </div>
        </li>
        <?php endforeach; ?>
      </ul>
      <?php else: ?>
      <div class="text-center py-4 text-muted" role="status">
        <i class="bi bi-check2-circle d-block mb-2 opacity-25" style="font-size:1.8rem" aria-hidden="true"></i>
        Brak osób oczekujących na termin
      </div>
      <?php endif; ?>
    </section>
  </div>

  <!-- Informacje bieżące -->
  <div class="col-lg-5">
    <section aria-labelledby="info-heading" class="panel">
      <div class="panel-head" id="info-heading">
        <span><i class="bi bi-info-circle me-1" aria-hidden="true"></i>Informacje bieżące</span>
      </div>
      <div class="p-3" style="font-size:.84rem">
        <dl class="row g-1 mb-0">
          <dt class="col-6 text-muted fw-normal">Data</dt>
          <dd class="col-6 fw-semibold mb-1"><?= date('d.m.Y, l') ?></dd>

          <?php if ($due_today > 0): ?>
          <dt class="col-6 text-muted fw-normal">Należności dziś</dt>
          <dd class="col-6 fw-semibold text-danger mb-1"><?= number_format($due_today, 2, ',', ' ') ?> zł</dd>
          <?php endif; ?>

          <dt class="col-6 text-muted fw-normal">Godz. PFRON (m-c)</dt>
          <dd class="col-6 fw-semibold mb-1"><?= number_format($pfron_month, 2, ',', '') ?> h</dd>

          <dt class="col-6 text-muted fw-normal">Lekcje TI dziś</dt>
          <dd class="col-6 fw-semibold mb-1"><?= (int)$ti['lessons_today'] ?></dd>

          <?php if ($ti['hw_tograde']): ?>
          <dt class="col-6 text-muted fw-normal">Zadań do oceny</dt>
          <dd class="col-6 mb-1"><a href="<?= APP_URL ?>/karty30/ti/homework.php" class="fw-semibold" style="color:#b45309"><?= (int)$ti['hw_tograde'] ?></a></dd>
          <?php endif; ?>

          <?php if ($stats['blacklist']): ?>
          <dt class="col-6 text-muted fw-normal">Czarna lista</dt>
          <dd class="col-6 mb-1">
            <a href="<?= APP_URL ?>/karty30/blacklist/index.php" class="text-danger fw-semibold">
              <?= $stats['blacklist'] ?> os.
            </a>
          </dd>
          <?php endif; ?>

          <dt class="col-6 text-muted fw-normal">Oczekujących pilnych</dt>
          <dd class="col-6 fw-semibold <?= $stats['waiting_urgent'] > 0 ? 'text-danger' : 'text-muted' ?> mb-1">
            <?= $stats['waiting_urgent'] ?> os.
          </dd>
        </dl>
      </div>
    </section>
  </div>

</div>

<?php include __DIR__ . '/includes/footer_k30.php'; ?>
