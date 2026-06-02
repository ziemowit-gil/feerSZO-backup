<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/karty30.php';

k30_require_access();
karty30_migrate();

$PAGE_TITLE = 'TyfloKonsultacje — Karty 30';
$can_write  = can_write('karty30') || is_admin();

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
</style>

<!-- Nagłówek strony -->
<div class="d-flex align-items-center gap-3 mb-4 flex-wrap">
  <div role="img" aria-label="TyfloKonsultacje"
       style="width:46px;height:46px;border-radius:11px;background:var(--k30-purple);display:flex;align-items:center;justify-content:center;flex-shrink:0">
    <i class="bi bi-card-checklist text-white" style="font-size:1.3rem" aria-hidden="true"></i>
  </div>
  <div>
    <h1 class="h4 mb-0 fw-bold">TyfloKonsultacje — Karty 30</h1>
    <p class="text-muted mb-0" style="font-size:.84rem">Beneficjenci · Wizyty · Konsultacje · Zajęcia TI</p>
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

<!-- KPI -->
<section aria-label="Podsumowanie statystyk">
<div class="row g-3 mb-4">

  <?php
  $kpis = [
    [
      'val'   => $stats['clients'],
      'lbl'   => 'Beneficjentów',
      'sub'   => null,
      'color' => '#0f4c91',
      'bg'    => '#dbeafe',
      'icon'  => 'bi-people-fill',
      'href'  => APP_URL.'/karty30/clients/index.php',
      'aria'  => 'Beneficjentów: ' . $stats['clients'],
    ],
    [
      'val'   => $stats['today'],
      'lbl'   => 'Wizyt dziś',
      'sub'   => date('d.m.Y'),
      'color' => '#15803d',
      'bg'    => '#dcfce7',
      'icon'  => 'bi-calendar-check-fill',
      'href'  => APP_URL.'/karty30/schedules/index.php',
      'aria'  => 'Wizyt dziś: ' . $stats['today'],
    ],
    [
      'val'   => $stats['week'],
      'lbl'   => 'Wizyt w tym tygodniu',
      'sub'   => null,
      'color' => '#b45309',
      'bg'    => '#fef3c7',
      'icon'  => 'bi-calendar-week-fill',
      'href'  => APP_URL.'/karty30/schedules/calendar.php',
      'aria'  => 'Wizyt w tym tygodniu: ' . $stats['week'],
    ],
    [
      'val'   => $stats['waiting'],
      'lbl'   => 'Oczekuje na termin',
      'sub'   => $stats['waiting_urgent'] ? $stats['waiting_urgent'] . ' pilnych' : null,
      'color' => $stats['waiting_urgent'] ? '#dc2626' : '#7c3aed',
      'bg'    => $stats['waiting_urgent'] ? '#fee2e2' : '#f5f3ff',
      'icon'  => 'bi-hourglass-split',
      'href'  => APP_URL.'/karty30/waiting/index.php',
      'aria'  => 'Na liście oczekujących: ' . $stats['waiting'] . ($stats['waiting_urgent'] ? ', w tym ' . $stats['waiting_urgent'] . ' pilnych' : ''),
    ],
    [
      'val'   => number_format($pfron_month, 1, ',', ''),
      'lbl'   => 'Godz. PFRON (m-c)',
      'sub'   => date('m/Y'),
      'color' => '#6d28d9',
      'bg'    => '#ede9fe',
      'icon'  => 'bi-building-fill-check',
      'href'  => null,
      'aria'  => 'Godziny PFRON w tym miesiącu: ' . number_format($pfron_month, 1, ',', ''),
    ],
    [
      'val'   => $stats['consultations'],
      'lbl'   => 'Konsultacji (łącznie)',
      'sub'   => null,
      'color' => '#0e7490',
      'bg'    => '#cffafe',
      'icon'  => 'bi-clipboard2-check-fill',
      'href'  => APP_URL.'/karty30/consultations/index.php',
      'aria'  => 'Zatwierdzonych konsultacji: ' . $stats['consultations'],
    ],
  ];
  foreach ($kpis as $kpi):
    $tag   = $kpi['href'] ? 'a' : 'div';
    $extra = $kpi['href'] ? 'href="' . h($kpi['href']) . '"' : '';
  ?>
  <div class="col-6 col-sm-4 col-xl-2">
    <<?= $tag ?> <?= $extra ?> class="kpi"
       style="--kpi-color:<?= h($kpi['color']) ?>;--kpi-bg:<?= h($kpi['bg']) ?>"
       <?= $kpi['href'] ? 'aria-label="' . h($kpi['aria']) . '"' : 'aria-label="' . h($kpi['aria']) . '"' ?>>
      <div class="kpi-icon" aria-hidden="true"><i class="bi <?= h($kpi['icon']) ?>"></i></div>
      <div class="kpi-val" aria-hidden="true"><?= $kpi['val'] ?></div>
      <div class="kpi-lbl" aria-hidden="true"><?= h($kpi['lbl']) ?></div>
      <?php if ($kpi['sub']): ?>
      <div class="kpi-sub" aria-hidden="true"><?= h($kpi['sub']) ?></div>
      <?php endif; ?>
    </<?= $tag ?>>
  </div>
  <?php endforeach; ?>

</div>
</section>

<!-- Główna zawartość -->
<div class="row g-3">

  <!-- Kolumna lewa: terminy + oczekujący -->
  <div class="col-lg-7">

    <!-- Najbliższe terminy -->
    <section aria-labelledby="upcoming-heading" class="panel mb-3">
      <div class="panel-head">
        <span id="upcoming-heading">
          <i class="bi bi-calendar3 me-1" aria-hidden="true"></i>Najbliższe terminy
        </span>
        <a href="<?= APP_URL ?>/karty30/schedules/index.php"
           class="btn btn-outline-secondary btn-sm py-0 px-2" style="font-size:.73rem">
          Wszystkie <span class="visually-hidden">terminy</span>
        </a>
      </div>

      <?php if ($upcoming): ?>
      <ul class="list-unstyled mb-0" role="list">
        <?php foreach ($upcoming as $s):
          $sc       = K30_SCHEDULE_STATUSES[$s['status']] ?? K30_SCHEDULE_STATUSES['preliminary'];
          $dt       = new DateTime($s['start_time']);
          $isToday  = $dt->format('Y-m-d') === date('Y-m-d');
          $isTomorrow = $dt->format('Y-m-d') === date('Y-m-d', strtotime('+1 day'));
          $dow      = ['Nd','Pn','Wt','Śr','Czw','Pt','Sb'][(int)$dt->format('w')];
          $time_str = $s['time_from'] ?: $dt->format('H:i');
        ?>
        <li role="listitem">
          <a href="<?= APP_URL ?>/karty30/schedules/view.php?id=<?= (int)$s['id'] ?>"
             class="panel-row"
             aria-label="<?= h($s['client_name']) ?>, <?= $isToday ? 'dziś' : ($isTomorrow ? 'jutro' : '') ?> <?= $dt->format('d.m') ?> <?= $time_str ?>">
            <!-- Data -->
            <div style="flex-shrink:0;width:44px;text-align:center" aria-hidden="true">
              <div style="font-size:1.2rem;font-weight:800;line-height:1;color:<?= $isToday?'var(--k30-purple)':'#1e293b' ?>"><?= $dt->format('d') ?></div>
              <div style="font-size:.62rem;text-transform:uppercase;color:#94a3b8;font-weight:600"><?= $dow ?></div>
              <?php if ($isToday): ?>
              <div style="font-size:.58rem;background:var(--k30-purple);color:#fff;border-radius:3px;padding:.05em .3em;margin-top:.1rem;font-weight:700">DZIŚ</div>
              <?php elseif ($isTomorrow): ?>
              <div style="font-size:.58rem;background:#f59e0b;color:#fff;border-radius:3px;padding:.05em .3em;margin-top:.1rem;font-weight:700">JUTRO</div>
              <?php endif; ?>
            </div>
            <!-- Dane -->
            <div style="flex:1;min-width:0" aria-hidden="true">
              <div class="fw-semibold text-truncate" style="font-size:.86rem"><?= h($s['client_name']) ?></div>
              <div style="font-size:.74rem;color:#64748b" class="d-flex gap-2 flex-wrap">
                <span><?= h($time_str) ?><?= $s['time_to'] ? '–'.h($s['time_to']) : '' ?></span>
                <?php if ($s['consultant_name']): ?>
                <span>· <?= h($s['consultant_name']) ?></span>
                <?php endif; ?>
                <?php if ($s['is_remote']): ?>
                <span class="text-info">· Zdalnie</span>
                <?php elseif ($s['resource_name']): ?>
                <span>· <?= h($s['resource_name']) ?></span>
                <?php endif; ?>
              </div>
            </div>
            <!-- Billing + status -->
            <div class="d-flex flex-column align-items-end gap-1 flex-shrink-0" aria-hidden="true">
              <?php $bt = K30_BILLING_TYPES[$s['billing_type']] ?? null; if ($bt): ?>
              <span style="font-size:.65rem;font-weight:700;color:<?= h($bt['color']) ?>"><?= h($bt['label']) ?></span>
              <?php endif; ?>
              <?= k30_status_badge($s['status']) ?>
            </div>
          </a>
        </li>
        <?php endforeach; ?>
      </ul>
      <?php else: ?>
      <div class="text-center py-4 text-muted" role="status">
        <i class="bi bi-calendar-x d-block mb-2 opacity-25" style="font-size:1.8rem" aria-hidden="true"></i>
        Brak nadchodzących wizyt
      </div>
      <?php endif; ?>
    </section>

    <!-- Lista oczekujących (top 5) -->
    <?php if ($waiting_top): ?>
    <section aria-labelledby="waiting-heading" class="panel">
      <div class="panel-head">
        <span id="waiting-heading">
          <i class="bi bi-hourglass-split me-1" aria-hidden="true"></i>Oczekujący na termin
          <span class="badge bg-warning text-dark ms-1" aria-label="<?= $stats['waiting'] ?> oczekujących"><?= $stats['waiting'] ?></span>
        </span>
        <a href="<?= APP_URL ?>/karty30/waiting/index.php"
           class="btn btn-outline-secondary btn-sm py-0 px-2" style="font-size:.73rem">
          Pełna kolejka <span class="visually-hidden">oczekujących</span>
        </a>
      </div>
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
    </section>
    <?php endif; ?>

  </div>

  <!-- Kolumna prawa: szybkie akcje + skróty -->
  <div class="col-lg-5">

    <!-- Szybkie akcje -->
    <section aria-labelledby="actions-heading" class="panel mb-3">
      <div class="panel-head" id="actions-heading">
        <span><i class="bi bi-grid-3x3-gap me-1" aria-hidden="true"></i>Moduły</span>
      </div>
      <div class="p-3 d-grid gap-2">
        <?php
        $links = [
          [APP_URL.'/karty30/clients/index.php',          'bi-people-fill',       'Beneficjenci',        'outline'],
          [APP_URL.'/karty30/schedules/index.php',        'bi-calendar3',          'Harmonogram wizyt',   'outline'],
          [APP_URL.'/karty30/schedules/calendar.php',     'bi-calendar-week',      'Kalendarz',           'outline'],
          [APP_URL.'/karty30/consultations/index.php',    'bi-clipboard2-check',   'Konsultacje',         'outline'],
          [APP_URL.'/karty30/ti/index.php',               'bi-pc-display',         'Zajęcia TI',          'outline'],
          [APP_URL.'/karty30/waiting/index.php',          'bi-hourglass-split',    'Lista oczekujących',  'warn'],
          [APP_URL.'/karty30/reports/index.php',          'bi-bar-chart-line',     'Raporty',             'outline'],
          [APP_URL.'/karty30/schedules/quick.php',        'bi-lightning-charge',   'Szybka rezerwacja',   'outline'],
        ];
        foreach ($links as [$href, $icon, $label, $type]):
        ?>
        <a href="<?= h($href) ?>"
           class="qa qa-<?= $type ?>"
           aria-label="<?= h($label) ?>">
          <i class="bi <?= h($icon) ?>" aria-hidden="true"></i><?= h($label) ?>
          <?php if ($label === 'Lista oczekujących' && $stats['waiting']): ?>
          <span class="badge bg-warning text-dark ms-auto" aria-hidden="true"><?= $stats['waiting'] ?></span>
          <?php endif; ?>
        </a>
        <?php endforeach; ?>
      </div>
    </section>

    <!-- Szybkie informacje -->
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
