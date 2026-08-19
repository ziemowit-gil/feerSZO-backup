<?php
$PAGE_TITLE = 'Zadania EZD';
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/ezd.php';

ezd_require_access();

$user    = current_user();
$user_id = (int)$user['id'];

// ── POST: zakończ / odrzuć dekretację ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf_token($_POST['_csrf'] ?? '')) {
    $action  = $_POST['_action'] ?? '';
    $dekr_id = (int)($_POST['dekr_id'] ?? 0);

    if ($action === 'dekr_done' && $dekr_id) {
        $d = db_one("SELECT * FROM ezd_dekretacje WHERE id=?", [$dekr_id]);
        if ($d && ((int)$d['wykonawca_id'] === $user_id || is_admin())) {
            ezd_dekretacja_complete((int)$d['id'], $user_id);
            flash_set('success', 'Zadanie zostało oznaczone jako <strong>wykonane</strong>.');
        }
    }

    $back = APP_URL . '/ezd/zadania/index.php';
    if (!empty($_GET['tab'])) $back .= '?tab=' . urlencode($_GET['tab']);
    header('Location: ' . $back);
    exit;
}

// ── Filtry ───────────────────────────────────────────────────────────────────
$tab     = in_array($_GET['tab'] ?? '', ['ode_mnie', 'zakonczone', 'wszystkie']) ? $_GET['tab'] : 'do_mnie';
$today   = date('Y-m-d');

// ── Zapytania ─────────────────────────────────────────────────────────────────

// DO MNIE: oczekujące, posortowane: przeterminowane → termin dzisiaj → brak terminu / dalszy
$do_mnie = db_all("
    SELECT d.*,
           z.name AS zlecajacy_name,
           w.name AS wykonawca_name,
           s.znak_sprawy,
           s.title AS sprawa_title,
           s.status AS sprawa_status,
           s.priority AS sprawa_priority,
           t.symbol AS teczka_symbol
    FROM   ezd_dekretacje d
    LEFT JOIN users         z ON z.id = d.zlecajacy_id
    LEFT JOIN users         w ON w.id = d.wykonawca_id
    LEFT JOIN ezd_sprawy    s ON s.id = d.sprawa_id
    LEFT JOIN ezd_teczki    t ON t.id = s.teczka_id
    WHERE  d.wykonawca_id = ? AND d.status = 'oczekuje'
    ORDER BY
        CASE WHEN d.deadline IS NOT NULL AND d.deadline < ? THEN 0
             WHEN d.deadline = ? THEN 1
             WHEN d.deadline IS NOT NULL THEN 2
             ELSE 3 END,
        d.deadline ASC,
        d.created_at DESC
", [$user_id, $today, $today]);

// ODE MNIE: zlecone przez mnie, oczekujące u kogoś innego
$ode_mnie = db_all("
    SELECT d.*,
           z.name AS zlecajacy_name,
           w.name AS wykonawca_name,
           s.znak_sprawy,
           s.title AS sprawa_title,
           t.symbol AS teczka_symbol
    FROM   ezd_dekretacje d
    LEFT JOIN users         z ON z.id = d.zlecajacy_id
    LEFT JOIN users         w ON w.id = d.wykonawca_id
    LEFT JOIN ezd_sprawy    s ON s.id = d.sprawa_id
    LEFT JOIN ezd_teczki    t ON t.id = s.teczka_id
    WHERE  d.zlecajacy_id = ? AND d.wykonawca_id != ? AND d.status = 'oczekuje'
    ORDER BY d.created_at DESC
", [$user_id, $user_id]);

// ZAKOŃCZONE: ostatnie 60 dni, moje (jako wykonawca lub zlecający)
$zakonczone = db_all("
    SELECT d.*,
           z.name AS zlecajacy_name,
           w.name AS wykonawca_name,
           s.znak_sprawy,
           s.title AS sprawa_title
    FROM   ezd_dekretacje d
    LEFT JOIN users         z ON z.id = d.zlecajacy_id
    LEFT JOIN users         w ON w.id = d.wykonawca_id
    LEFT JOIN ezd_sprawy    s ON s.id = d.sprawa_id
    WHERE  (d.wykonawca_id = ? OR d.zlecajacy_id = ?) AND d.status = 'zakonczone'
          AND d.completed_at >= date('now','-60 days')
    ORDER BY d.completed_at DESC
    LIMIT  100
", [$user_id, $user_id]);

// WSZYSTKIE (admin): wszystkie oczekujące w systemie
$wszystkie = [];
if (is_admin()) {
    $wszystkie = db_all("
        SELECT d.*,
               z.name AS zlecajacy_name,
               w.name AS wykonawca_name,
               s.znak_sprawy,
               s.title AS sprawa_title,
               t.symbol AS teczka_symbol
        FROM   ezd_dekretacje d
        LEFT JOIN users         z ON z.id = d.zlecajacy_id
        LEFT JOIN users         w ON w.id = d.wykonawca_id
        LEFT JOIN ezd_sprawy    s ON s.id = d.sprawa_id
        LEFT JOIN ezd_teczki    t ON t.id = s.teczka_id
        WHERE  d.status = 'oczekuje'
        ORDER BY d.deadline ASC, d.created_at DESC
        LIMIT  500
    ");
}

// ── Statystyki paska ─────────────────────────────────────────────────────────
$cnt_pending   = count($do_mnie);
$cnt_overdue   = count(array_filter($do_mnie, fn($d) => $d['deadline'] && $d['deadline'] < $today));
$cnt_today_due = count(array_filter($do_mnie, fn($d) => $d['deadline'] === $today));
$cnt_delegated = count($ode_mnie);

// ── Helper: wiersz zadania ───────────────────────────────────────────────────
function ezd_task_row(array $d, int $uid, bool $show_assignee = false): void {
    $is_over  = $d['deadline'] && $d['deadline'] < date('Y-m-d') && $d['status'] === 'oczekuje';
    $is_today = $d['deadline'] === date('Y-m-d') && $d['status'] === 'oczekuje';
    $done     = $d['status'] === 'zakonczone';
    $pri_cls  = $is_over ? 'overdue' : ($is_today ? 'today' : ($done ? 'done' : 'normal'));

    static $DISP = null;
    if ($DISP === null) $DISP = defined('EZD_DYSPOZYCJE') ? EZD_DYSPOZYCJE : [
        'do_zalat'      => 'Do załatwienia',
        'do_akcept'     => 'Do akceptacji',
        'do_wiadom'     => 'Do wiadomości',
        'do_podpisu'    => 'Do podpisu',
        'do_realizacji' => 'Do realizacji',
    ];

    $badge_map = [
        'do_zalat'      => 'bg-primary',
        'do_akcept'     => 'bg-warning text-dark',
        'do_wiadom'     => 'bg-secondary',
        'do_podpisu'    => 'bg-info text-dark',
        'do_realizacji' => 'bg-success',
    ];
    $badge_cls = $badge_map[$d['dyspozycja']] ?? 'bg-secondary';
    ?>
    <div class="ezd-zt-row <?= $pri_cls ?>">
      <div class="ezd-zt-indicator"></div>
      <div class="ezd-zt-body">
        <div class="d-flex align-items-center gap-1 flex-wrap mb-1">
          <?php if ($d['znak_sprawy']): ?>
          <code class="ezd-zt-sign"><?= h($d['znak_sprawy']) ?></code>
          <?php endif; ?>
          <span class="badge <?= $badge_cls ?>" style="font-size:.58rem;padding:.2rem .45rem">
            <?= h($DISP[$d['dyspozycja']] ?? $d['dyspozycja']) ?>
          </span>
          <?php if ($done): ?>
          <span class="badge bg-success bg-opacity-15 text-success border border-success" style="font-size:.58rem"><i class="bi bi-check-lg"></i> Wykonane</span>
          <?php endif; ?>
        </div>
        <div class="ezd-zt-title <?= $done ? 'text-decoration-line-through text-muted' : '' ?>">
          <?= $d['sprawa_title'] ? h(mb_substr($d['sprawa_title'], 0, 80)) : '<em class="text-muted">brak powiązanej sprawy</em>' ?>
        </div>
        <?php if ($d['tresc']): ?>
        <div class="ezd-zt-tresc"><?= h(mb_substr($d['tresc'], 0, 100)) ?></div>
        <?php endif; ?>
        <div class="ezd-zt-meta">
          <span>
            <i class="bi bi-person-fill opacity-50 me-1"></i>
            <?= $show_assignee
                ? h($d['wykonawca_name'] ?? '—')
                : h($d['zlecajacy_name'] ?? '—') ?>
          </span>
          <?php if ($d['deadline']): ?>
          <span class="<?= $is_over ? 'ezd-zt-overdue' : ($is_today ? 'ezd-zt-today' : '') ?>">
            <i class="bi bi-calendar-event me-1 opacity-50"></i><?= date_pl($d['deadline']) ?>
            <?php if ($is_over): ?><i class="bi bi-exclamation-circle-fill ms-1 text-danger"></i><?php endif; ?>
          </span>
          <?php endif; ?>
          <?php if ($done && $d['completed_at']): ?>
          <span class="text-success"><i class="bi bi-check2-circle me-1"></i><?= date('d.m.Y', strtotime($d['completed_at'])) ?></span>
          <?php endif; ?>
        </div>
      </div>
      <div class="ezd-zt-actions">
        <?php if (!$done && ((int)$d['wykonawca_id'] === $uid || is_admin())): ?>
        <form method="post">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="_action" value="dekr_done">
          <input type="hidden" name="dekr_id" value="<?= (int)$d['id'] ?>">
          <button type="submit" class="btn btn-sm btn-outline-success ezd-zt-act-btn" title="Oznacz jako wykonane">
            <i class="bi bi-check-lg"></i>
          </button>
        </form>
        <?php endif; ?>
        <?php if (!empty($d['sprawa_id'])): ?>
        <a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= (int)$d['sprawa_id'] ?>#tab-zadania"
           class="btn btn-sm btn-outline-secondary ezd-zt-act-btn" title="Otwórz koszulkę">
          <i class="bi bi-folder2-open"></i>
        </a>
        <?php endif; ?>
      </div>
    </div>
    <?php
}

include dirname(dirname(__DIR__)) . '/includes/header.php';
?>

<style>
/* ── Stat karty ──────────────────────────────────────────────────── */
.ezd-zt-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:.65rem;margin-bottom:1.25rem}
.ezd-zt-stat{background:#fff;border:1.5px solid #e2e8f0;border-radius:12px;padding:.85rem 1rem;display:flex;align-items:center;gap:.75rem}
.ezd-zt-stat-ic{width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0}
.ezd-zt-stat-val{font-size:1.45rem;font-weight:800;line-height:1;color:#0f172a}
.ezd-zt-stat-lbl{font-size:.68rem;color:#5b6472;margin-top:.15rem}

/* ── Tabs ─────────────────────────────────────────────────────────── */
.ezd-zt-tabs{border-bottom:2px solid #e2e8f0;margin-bottom:0;flex-wrap:nowrap;overflow-x:auto;white-space:nowrap}
.ezd-zt-tabs .nav-link{font-size:.71rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#64748b;border:none;border-bottom:3px solid transparent;border-radius:0;padding:.6rem .9rem;white-space:nowrap}
.ezd-zt-tabs .nav-link.active,.ezd-zt-tabs .nav-link:hover{color:#1e3a6e;border-bottom-color:#1e3a6e;background:none}
.ezd-zt-panel{background:#fff;border:1.5px solid #e2e8f0;border-top:none;border-radius:0 0 10px 10px;overflow:hidden}

/* ── Wiersz zadania ───────────────────────────────────────────────── */
.ezd-zt-row{display:flex;align-items:flex-start;gap:.75rem;padding:.7rem 1rem;border-bottom:1px solid #f1f5f9;position:relative;transition:background .1s}
.ezd-zt-row:last-child{border-bottom:none}
.ezd-zt-row:hover{background:#f8fafc}
.ezd-zt-indicator{width:3px;min-height:44px;border-radius:2px;background:#e2e8f0;flex-shrink:0;align-self:stretch}
.ezd-zt-row.overdue .ezd-zt-indicator{background:#dc2626}
.ezd-zt-row.today   .ezd-zt-indicator{background:#f59e0b}
.ezd-zt-row.normal  .ezd-zt-indicator{background:#1e6dff}
.ezd-zt-row.done    .ezd-zt-indicator{background:#22c55e}
.ezd-zt-body{flex:1;min-width:0}
.ezd-zt-sign{font-size:.7rem;color:#1e6dff;background:#eef4ff;border:1px solid #bfdbfe;border-radius:4px;padding:.08rem .38rem;font-family:monospace}
.ezd-zt-title{font-size:.84rem;font-weight:600;color:#0f172a;line-height:1.35;margin-bottom:.2rem}
.ezd-zt-tresc{font-size:.76rem;color:#64748b;margin-bottom:.2rem;white-space:pre-line}
.ezd-zt-meta{display:flex;align-items:center;flex-wrap:wrap;gap:.25rem .75rem;font-size:.71rem;color:#94a3b8}
.ezd-zt-overdue{color:#dc2626;font-weight:700}
.ezd-zt-today{color:#d97706;font-weight:700}
.ezd-zt-actions{display:flex;gap:.3rem;flex-shrink:0;align-items:flex-start;padding-top:.1rem}
.ezd-zt-act-btn{width:30px;height:30px;padding:0;display:flex;align-items:center;justify-content:center;font-size:.82rem}

/* ── Pusta lista ─────────────────────────────────────────────────── */
.ezd-zt-empty{text-align:center;padding:3rem 1rem;color:#94a3b8}
.ezd-zt-empty .bi{font-size:2.5rem;display:block;margin-bottom:.6rem;opacity:.25}
.ezd-zt-empty strong{display:block;color:#64748b;font-size:.9rem;margin-bottom:.25rem}
</style>

<!-- Breadcrumb -->
<nav aria-label="breadcrumb" class="mb-3">
  <ol class="breadcrumb" style="font-size:.78rem">
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/index.php">EZD</a></li>
    <li class="breadcrumb-item active">Zadania</li>
  </ol>
</nav>

<?= flash_html() ?>

<!-- Nagłówek -->
<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
  <h1 class="h5 fw-bold mb-0" style="color:#0f172a"><i class="bi bi-person-lines-fill text-primary me-2"></i>Zadania EZD</h1>
  <span class="badge bg-primary ms-1"><?= $cnt_pending ?> oczekujących</span>
  <?php if ($cnt_overdue): ?>
  <span class="badge bg-danger"><?= $cnt_overdue ?> po terminie</span>
  <?php endif; ?>
  <div class="ms-auto d-flex gap-2">
    <?php if (can_edit()): ?>
    <a href="<?= APP_URL ?>/ezd/sprawy/index.php" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-folder2-open me-1"></i>Koszulki
    </a>
    <?php endif; ?>
  </div>
</div>

<!-- Stat karty -->
<div class="ezd-zt-stats">

  <div class="ezd-zt-stat">
    <div class="ezd-zt-stat-ic" style="background:#eef4ff;color:#1e6dff">
      <i class="bi bi-inbox-fill"></i>
    </div>
    <div>
      <div class="ezd-zt-stat-val"><?= $cnt_pending ?></div>
      <div class="ezd-zt-stat-lbl">Do mnie</div>
    </div>
  </div>

  <div class="ezd-zt-stat">
    <div class="ezd-zt-stat-ic" style="background:#fef2f2;color:#dc2626">
      <i class="bi bi-exclamation-circle-fill"></i>
    </div>
    <div>
      <div class="ezd-zt-stat-val <?= $cnt_overdue ? 'text-danger' : '' ?>"><?= $cnt_overdue ?></div>
      <div class="ezd-zt-stat-lbl">Po terminie</div>
    </div>
  </div>

  <div class="ezd-zt-stat">
    <div class="ezd-zt-stat-ic" style="background:#fffbeb;color:#d97706">
      <i class="bi bi-calendar-event-fill"></i>
    </div>
    <div>
      <div class="ezd-zt-stat-val <?= $cnt_today_due ? 'text-warning' : '' ?>"><?= $cnt_today_due ?></div>
      <div class="ezd-zt-stat-lbl">Na dziś</div>
    </div>
  </div>

  <div class="ezd-zt-stat">
    <div class="ezd-zt-stat-ic" style="background:#f0fdf4;color:#16a34a">
      <i class="bi bi-send-fill"></i>
    </div>
    <div>
      <div class="ezd-zt-stat-val"><?= $cnt_delegated ?></div>
      <div class="ezd-zt-stat-lbl">Przeze mnie</div>
    </div>
  </div>

</div>

<!-- Tabs -->
<ul class="nav ezd-zt-tabs" id="ezdZadaniaTabs">
  <li class="nav-item">
    <a class="nav-link <?= $tab === 'do_mnie' ? 'active' : '' ?>"
       href="<?= APP_URL ?>/ezd/zadania/index.php?tab=do_mnie">
      <i class="bi bi-inbox me-1"></i>DO MNIE
      <?php if ($cnt_pending): ?><span class="badge bg-primary ms-1" style="font-size:.58rem"><?= $cnt_pending ?></span><?php endif; ?>
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $tab === 'ode_mnie' ? 'active' : '' ?>"
       href="<?= APP_URL ?>/ezd/zadania/index.php?tab=ode_mnie">
      <i class="bi bi-send me-1"></i>ODE MNIE
      <?php if ($cnt_delegated): ?><span class="badge bg-secondary ms-1" style="font-size:.58rem"><?= $cnt_delegated ?></span><?php endif; ?>
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $tab === 'zakonczone' ? 'active' : '' ?>"
       href="<?= APP_URL ?>/ezd/zadania/index.php?tab=zakonczone">
      <i class="bi bi-check2-all me-1"></i>ZAKOŃCZONE
    </a>
  </li>
  <?php if (is_admin()): ?>
  <li class="nav-item">
    <a class="nav-link <?= $tab === 'wszystkie' ? 'active' : '' ?>"
       href="<?= APP_URL ?>/ezd/zadania/index.php?tab=wszystkie">
      <i class="bi bi-list-task me-1"></i>WSZYSTKIE
      <?php if ($wszystkie): ?><span class="badge bg-warning text-dark ms-1" style="font-size:.58rem"><?= count($wszystkie) ?></span><?php endif; ?>
    </a>
  </li>
  <?php endif; ?>
</ul>

<div class="ezd-zt-panel">

  <?php if ($tab === 'do_mnie'): ?>
  <!-- ── DO MNIE ─────────────────────────────────────────────────── -->
  <?php if ($do_mnie): ?>
  <?php foreach ($do_mnie as $d) ezd_task_row($d, $user_id, false); ?>
  <?php else: ?>
  <div class="ezd-zt-empty">
    <i class="bi bi-check-circle"></i>
    <strong>Brak zadań do wykonania</strong>
    Nie masz żadnych oczekujących dekretacji.
  </div>
  <?php endif; ?>

  <?php elseif ($tab === 'ode_mnie'): ?>
  <!-- ── ODE MNIE ───────────────────────────────────────────────── -->
  <?php if ($ode_mnie): ?>
  <?php foreach ($ode_mnie as $d) ezd_task_row($d, $user_id, true); ?>
  <?php else: ?>
  <div class="ezd-zt-empty">
    <i class="bi bi-send"></i>
    <strong>Brak aktywnych delegacji</strong>
    Nie masz zadań przekazanych innym osobom.
  </div>
  <?php endif; ?>

  <?php elseif ($tab === 'zakonczone'): ?>
  <!-- ── ZAKOŃCZONE ─────────────────────────────────────────────── -->
  <?php if ($zakonczone): ?>
  <div class="px-3 py-2 bg-light border-bottom" style="font-size:.75rem;color:#64748b">
    Ostatnie 60 dni · <?= count($zakonczone) ?> wpisów
  </div>
  <?php foreach ($zakonczone as $d) ezd_task_row($d, $user_id, ($d['zlecajacy_id'] != $user_id)); ?>
  <?php else: ?>
  <div class="ezd-zt-empty">
    <i class="bi bi-archive"></i>
    <strong>Brak zakończonych zadań</strong>
    W ostatnich 60 dniach nie zamknięto żadnego zadania.
  </div>
  <?php endif; ?>

  <?php elseif ($tab === 'wszystkie' && is_admin()): ?>
  <!-- ── WSZYSTKIE (admin) ──────────────────────────────────────── -->
  <?php if ($wszystkie): ?>
  <div class="px-3 py-2 bg-light border-bottom d-flex align-items-center gap-2" style="font-size:.75rem;color:#64748b">
    <i class="bi bi-shield-lock-fill text-warning"></i> Widok administratora — wszystkie oczekujące dekretacje w systemie
    <span class="badge bg-warning text-dark ms-1"><?= count($wszystkie) ?></span>
  </div>
  <?php foreach ($wszystkie as $d) ezd_task_row($d, $user_id, true); ?>
  <?php else: ?>
  <div class="ezd-zt-empty">
    <i class="bi bi-check-circle"></i>
    <strong>Brak oczekujących zadań w systemie</strong>
  </div>
  <?php endif; ?>
  <?php endif; ?>

</div>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
