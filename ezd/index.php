<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/ezd.php';

require_login();
require_module_enabled('ezd_enabled', 'Moduł kancelarii');

$PAGE_TITLE = 'Wirtualne biurko';
$stats      = ezd_stats();
$rpw_stats  = ezd_rpw_stats();
$user_id    = (int)current_user()['id'];

// Ostatnie koszulki
$recent_sprawy = ezd_sprawy_all(['status' => ''], $user_id);  // all statuses, limited in function
$recent_sprawy = array_slice(array_filter($recent_sprawy, fn($s) => $s['status'] !== 'closed'), 0, 8);

// Moje przekazania (oczekujące)
$my_dekr = db_all(
    "SELECT d.*, s.znak_sprawy, s.title AS sprawa_title, z.name AS zlec_name
     FROM ezd_dekretacje d
     JOIN ezd_sprawy s ON s.id=d.sprawa_id
     LEFT JOIN users z ON z.id=d.zlecajacy_id
     WHERE d.wykonawca_id=? AND d.status='oczekuje'
     ORDER BY d.deadline ASC, d.created_at DESC LIMIT 10",
    [$user_id]
);

// Moje koszulki (jestem właścicielem/referentem lub mają ze mną współdzielone)
$my_sprawy = db_all(
    "SELECT s.*, t.symbol AS teczka_symbol,
            (s.owner_id!=? AND s.created_by!=?) AS is_shared
     FROM ezd_sprawy s
     JOIN ezd_teczki t ON t.id=s.teczka_id
     WHERE s.status!='closed' AND (s.owner_id=? OR s.created_by=?
           OR EXISTS (SELECT 1 FROM ezd_sprawa_users su WHERE su.sprawa_id=s.id AND su.user_id=?))
     ORDER BY (s.deadline IS NULL), s.deadline ASC, s.updated_at DESC LIMIT 8",
    [$user_id, $user_id, $user_id, $user_id, $user_id]
);
// Moje pisma (jestem referentem)
$my_pisma = db_all(
    "SELECT p.*, s.znak_sprawy FROM ezd_pisma p
     JOIN ezd_sprawy s ON s.id=p.sprawa_id
     WHERE p.owner_id=? ORDER BY p.updated_at DESC LIMIT 8",
    [$user_id]
);
$my_sprawy_cnt = (int)(db_one(
    "SELECT COUNT(*) c FROM ezd_sprawy s WHERE s.status!='closed' AND (s.owner_id=? OR s.created_by=?
     OR EXISTS (SELECT 1 FROM ezd_sprawa_users su WHERE su.sprawa_id=s.id AND su.user_id=?))",
    [$user_id, $user_id, $user_id]
)['c'] ?? 0);
$my_pisma_cnt  = (int)(db_one("SELECT COUNT(*) c FROM ezd_pisma WHERE owner_id=?", [$user_id])['c'] ?? 0);

// Ostatnia aktywność (log)
$activity = db_all(
    "SELECT l.*, u.name AS user_name, s.znak_sprawy
     FROM ezd_log l
     LEFT JOIN users u ON u.id=l.user_id
     LEFT JOIN ezd_sprawy s ON s.id=l.sprawa_id
     ORDER BY l.created_at DESC LIMIT 12"
);

include dirname(__DIR__) . '/includes/header.php';
?>
<style>
.ezd-stat { background:#fff;border:1.5px solid #e2e8f0;border-radius:12px;padding:1rem 1.25rem;display:flex;align-items:center;gap:.9rem; }
.ezd-stat-icon { width:44px;height:44px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:1.25rem;flex-shrink:0; }
.ezd-card { background:#fff;border:1.5px solid #e2e8f0;border-radius:12px;overflow:hidden; }
.ezd-card-header { padding:.6rem 1rem;font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#94a3b8;border-bottom:1px solid #f1f5f9;background:#fafbfc;display:flex;align-items:center;gap:.4rem; }
.activity-item { display:flex;gap:.65rem;padding:.4rem 0;border-bottom:1px solid #f8fafc;font-size:.78rem;align-items:flex-start; }
.activity-item:last-child { border-bottom:none; }
.activity-dot { width:7px;height:7px;border-radius:50%;margin-top:.3rem;flex-shrink:0; }
</style>

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <div>
    <h4 class="mb-0 fw-bold"><i class="bi bi-building-gear text-primary me-2"></i>Wirtualne biurko</h4>
    <div class="text-muted" style="font-size:.8rem;margin-top:.15rem">Elektroniczne Zarządzanie Dokumentacją</div>
  </div>
  <div class="d-flex gap-2">
    <a href="<?= APP_URL ?>/ezd/rpw/index.php" class="btn btn-outline-primary btn-sm position-relative">
      <i class="bi bi-mailbox2 me-1"></i>Dziennik podawczy
      <?php if ($rpw_stats['koszulka']): ?><span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-info" style="font-size:.6rem"><?= (int)$rpw_stats['koszulka'] ?></span><?php endif; ?>
    </a>
    <?php if (can_edit()): ?>
    <a href="<?= APP_URL ?>/ezd/sprawy/add.php" class="btn btn-primary btn-sm">
      <i class="bi bi-folder-plus me-1"></i>Nowa koszulka
    </a>
    <?php if (is_admin()): ?>
    <a href="<?= APP_URL ?>/ezd/teczki/add.php" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-archive me-1"></i>Nowa teczka
    </a>
    <?php endif; ?>
    <?php endif; ?>
    <?php if (is_admin()): ?>
    <a href="<?= APP_URL ?>/admin/ezd_settings.php" class="btn btn-outline-secondary btn-sm" title="Ustawienia modułu EZD">
      <i class="bi bi-gear"></i>
    </a>
    <?php endif; ?>
  </div>
</div>

<?= flash_html() ?>

<!-- Podnawigacja modułu -->
<div class="d-flex gap-2 mb-4 flex-wrap">
  <a href="<?= APP_URL ?>/ezd/rpw/index.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-mailbox2 me-1"></i>Dziennik podawczy</a>
  <a href="<?= APP_URL ?>/ezd/sprawy/index.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-folder2-open me-1"></i>Koszulki</a>
  <a href="<?= APP_URL ?>/ezd/teczki/index.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-archive me-1"></i>Teczki aktowe</a>
  <a href="<?= APP_URL ?>/ezd/jrwa/index.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-tags me-1"></i>Wykaz akt (JRWA)</a>
  <a href="<?= APP_URL ?>/ezd/pelnomocnictwa/index.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-person-vcard me-1"></i>Rejestr pełnomocnictw</a>
  <a href="<?= APP_URL ?>/ezd/zaswiadczenia/index.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-award me-1"></i>Rejestr zaświadczeń</a>
  <a href="<?= APP_URL ?>/ezd/wolontariusze/index.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-heart me-1"></i>Wolontariusze bez umowy</a>
</div>

<!-- Statystyki -->
<div class="row g-3 mb-4">
  <?php $items = [
    ['label'=>'W koszulce (RPW)',   'val'=>$rpw_stats['koszulka'], 'icon'=>'bi-inbox-fill',          'color'=>'info'],
    ['label'=>'Teczki otwarte',     'val'=>$stats['teczki_open'],  'icon'=>'bi-archive-fill',       'color'=>'primary'],
    ['label'=>'Koszulki aktywne',   'val'=>$stats['sprawy_open'],  'icon'=>'bi-folder2-open',        'color'=>'success'],
    ['label'=>'Pisma (ten miesiąc)','val'=>$stats['pisma_month'],  'icon'=>'bi-envelope-arrow-down', 'color'=>'info'],
    ['label'=>'Przekazania czekają','val'=>$stats['dekr_pending'], 'icon'=>'bi-person-lines-fill',   'color'=>'danger'],
  ]; foreach ($items as $it): ?>
  <div class="col-6 col-md-4 col-xl">
    <div class="ezd-stat">
      <div class="ezd-stat-icon bg-<?= $it['color'] ?> bg-opacity-10">
        <i class="bi <?= $it['icon'] ?> text-<?= $it['color'] ?>"></i>
      </div>
      <div>
        <div style="font-size:1.5rem;font-weight:700;line-height:1"><?= $it['val'] ?></div>
        <div style="font-size:.72rem;color:#64748b"><?= $it['label'] ?></div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Widget: Moje koszulki / Moje dokumenty -->
<div class="ezd-card mb-4">
  <ul class="nav nav-tabs px-2 pt-2" style="font-size:.82rem;border-bottom:1px solid #f1f5f9">
    <li class="nav-item"><a class="nav-link active py-1" data-bs-toggle="tab" href="#tab-moje-sprawy"><i class="bi bi-folder2-open me-1"></i>Moje koszulki <span class="badge bg-primary rounded-pill"><?= $my_sprawy_cnt ?></span></a></li>
    <li class="nav-item"><a class="nav-link py-1" data-bs-toggle="tab" href="#tab-moje-pisma"><i class="bi bi-envelope me-1"></i>Moje dokumenty <span class="badge bg-secondary rounded-pill"><?= $my_pisma_cnt ?></span></a></li>
  </ul>
  <div class="tab-content p-0">
    <!-- Moje koszulki -->
    <div class="tab-pane fade show active" id="tab-moje-sprawy">
      <table class="table table-sm table-hover mb-0 align-middle" style="font-size:.8rem">
        <tbody>
        <?php foreach($my_sprawy as $s): ?>
          <tr onclick="location='<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= $s['id'] ?>'" style="cursor:pointer">
            <td class="font-monospace fw-semibold text-primary" style="white-space:nowrap;font-size:.74rem"><?= h($s['znak_sprawy']) ?></td>
            <td class="text-truncate" style="max-width:1px"><?= h($s['title']) ?>
              <?php if(!empty($s['is_shared'])): ?><span class="badge bg-info bg-opacity-15 text-info border border-info ms-1" style="font-size:.6rem"><i class="bi bi-people me-1"></i>Współdzielona</span><?php endif; ?>
            </td>
            <td class="text-nowrap"><?= ezd_etap_badge($s['etap'] ?? 'wszczeta') ?></td>
            <td class="text-nowrap text-end" style="font-size:.72rem">
              <?php if(!empty($s['ciagla'])): ?><span class="text-info"><i class="bi bi-infinity"></i></span>
              <?php elseif($s['deadline']): ?><span class="<?= $s['deadline']<date('Y-m-d')?'text-danger fw-bold':'text-muted' ?>"><?= date_pl($s['deadline']) ?></span>
              <?php else: ?><span class="text-muted">—</span><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if(!$my_sprawy): ?><tr><td class="text-center text-muted py-3" style="font-size:.8rem">Nie masz przypisanych aktywnych koszulek</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
    <!-- Moje dokumenty -->
    <div class="tab-pane fade" id="tab-moje-pisma">
      <table class="table table-sm table-hover mb-0 align-middle" style="font-size:.8rem">
        <tbody>
        <?php foreach($my_pisma as $p): $k = EZD_KIERUNKI[$p['kierunek']] ?? ['icon'=>'bi-envelope','class'=>'secondary','label'=>$p['kierunek']]; ?>
          <tr onclick="location='<?= APP_URL ?>/ezd/pisma/view.php?id=<?= $p['id'] ?>'" style="cursor:pointer">
            <td class="text-nowrap"><i class="bi <?= $k['icon'] ?> text-<?= $k['class'] ?>" title="<?= h($k['label']) ?>"></i></td>
            <td class="font-monospace" style="white-space:nowrap;font-size:.72rem"><?= h($p['sygnatura']) ?></td>
            <td class="text-truncate" style="max-width:1px"><?= h($p['title']) ?></td>
            <td class="text-nowrap"><span class="badge bg-light text-dark border" style="font-size:.62rem"><?= h($p['status']) ?></span></td>
          </tr>
        <?php endforeach; ?>
        <?php if(!$my_pisma): ?><tr><td class="text-center text-muted py-3" style="font-size:.8rem">Nie jesteś referentem żadnego pisma</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="row g-4">
  <!-- Aktywne teczki -->
  <div class="col-lg-7">
    <div class="ezd-card">
      <div class="ezd-card-header">
        <i class="bi bi-folder2-open"></i> Aktywne koszulki
        <a href="<?= APP_URL ?>/ezd/sprawy/index.php" class="ms-auto text-primary" style="font-size:.75rem;font-weight:600;text-transform:none;letter-spacing:0">Wszystkie →</a>
      </div>
      <div class="table-responsive">
        <table class="table table-sm table-hover mb-0" style="font-size:.8rem">
          <thead class="table-light">
            <tr><th>Znak koszulki</th><th>Tytuł</th><th>Priorytet</th><th>Deadline</th><th>Właściciel</th></tr>
          </thead>
          <tbody>
          <?php foreach ($recent_sprawy as $s): ?>
          <tr>
            <td><a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= $s['id'] ?>" class="fw-semibold font-monospace text-decoration-none" style="font-size:.75rem"><?= h($s['znak_sprawy']) ?></a></td>
            <td><?= h(mb_substr($s['title'], 0, 45)) ?></td>
            <td><?= ezd_priority_badge($s['priority']) ?></td>
            <td class="<?= $s['deadline'] && $s['deadline'] < date('Y-m-d') ? 'text-danger fw-bold' : '' ?>">
              <?= $s['deadline'] ? date_pl($s['deadline']) : '—' ?>
            </td>
            <td><?= h($s['owner_name'] ?? '—') ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$recent_sprawy): ?>
          <tr><td colspan="5" class="text-center text-muted py-3">Brak aktywnych koszulek</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Moje przekazania -->
    <?php if ($my_dekr): ?>
    <div class="ezd-card mt-3">
      <div class="ezd-card-header">
        <i class="bi bi-person-lines-fill text-warning"></i> Moje zadania do wykonania
      </div>
      <div class="p-0">
        <?php foreach ($my_dekr as $d): ?>
        <div class="d-flex align-items-center gap-3 px-3 py-2 border-bottom" style="font-size:.8rem">
          <span class="badge bg-warning text-dark" style="font-size:.65rem;white-space:nowrap"><?= h(EZD_DYSPOZYCJE[$d['dyspozycja']] ?? $d['dyspozycja']) ?></span>
          <div class="flex-grow-1 overflow-hidden">
            <a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= $d['sprawa_id'] ?>" class="text-decoration-none fw-semibold d-block text-truncate"><?= h($d['sprawa_title']) ?></a>
            <div class="text-muted" style="font-size:.7rem"><?= h($d['znak_sprawy']) ?> · od: <?= h($d['zlec_name'] ?? '—') ?> <?= $d['tresc'] ? '· '.h(mb_substr($d['tresc'],0,40)) : '' ?></div>
          </div>
          <?php if ($d['deadline']): ?>
          <span class="<?= $d['deadline'] < date('Y-m-d') ? 'text-danger fw-bold' : 'text-muted' ?>" style="font-size:.72rem;white-space:nowrap"><?= date_pl($d['deadline']) ?></span>
          <?php endif; ?>
          <form method="post" action="<?= APP_URL ?>/ezd/dekretacja/complete.php">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="id" value="<?= $d['id'] ?>">
            <button class="btn btn-xs btn-outline-success btn-sm" title="Oznacz jako wykonane"><i class="bi bi-check-lg"></i></button>
          </form>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- Aktywność -->
  <div class="col-lg-5">
    <div class="ezd-card h-100">
      <div class="ezd-card-header"><i class="bi bi-activity"></i> Ostatnia aktywność</div>
      <div class="p-3" style="max-height:480px;overflow-y:auto">
        <?php $action_colors = ['sprawa_create'=>'success','sprawa_update'=>'primary','pismo_create'=>'info','umowa_create'=>'warning','upload'=>'secondary','teczka_create'=>'success','dekretacja_create'=>'warning']; ?>
        <?php foreach ($activity as $a): ?>
        <div class="activity-item">
          <div class="activity-dot mt-1" style="background:#<?= match($action_colors[$a['action']] ?? 'secondary') { 'success'=>'22c55e','primary'=>'3b82f6','info'=>'06b6d4','warning'=>'f59e0b',default=>'94a3b8' } ?>"></div>
          <div class="flex-grow-1">
            <?php if ($a['znak_sprawy']): ?>
            <a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= $a['sprawa_id'] ?>" class="font-monospace text-decoration-none fw-semibold" style="font-size:.72rem"><?= h($a['znak_sprawy']) ?></a>
            <?php endif; ?>
            <div style="color:#374151;line-height:1.3"><?= h($a['details'] ?: $a['action']) ?></div>
            <div style="color:#94a3b8;font-size:.7rem"><?= h($a['user_name'] ?? '—') ?> · <?= date('d.m H:i', strtotime($a['created_at'])) ?></div>
          </div>
        </div>
        <?php endforeach; ?>
        <?php if (!$activity): ?>
        <div class="text-center text-muted py-3" style="font-size:.8rem">Brak aktywności</div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
