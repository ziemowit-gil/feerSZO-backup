<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/ezd.php';

require_login();
require_module_enabled('ezd_enabled', 'Moduł EZD Wirtualne biurko');

$PAGE_TITLE = 'Wirtualne biurko';
$stats      = ezd_stats();
$rpw_stats  = ezd_rpw_stats();
$arch_stats = ezd_arch_stats();
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
     ORDER BY s.title ASC LIMIT 8",
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

// Komu przekazana (najnowsza otwarta dekretacja per koszulka) + ile koszulek
// z otwartą dekretacją "leży" u danego wykonawcy (obciążenie).
$dekr_map = [];
try {
    $open_dekr = db_all(
        "SELECT d.sprawa_id, d.wykonawca_id, u.name AS wykonawca_name
         FROM ezd_dekretacje d JOIN users u ON u.id=d.wykonawca_id
         WHERE d.status='oczekuje' AND d.sprawa_id IS NOT NULL
         ORDER BY d.created_at ASC"
    );
    $workload = [];
    foreach ($open_dekr as $d) $workload[(int)$d['wykonawca_id']][(int)$d['sprawa_id']] = true;
    foreach ($open_dekr as $d) {
        $dekr_map[(int)$d['sprawa_id']] = [
            'name'  => $d['wykonawca_name'],
            'count' => count($workload[(int)$d['wykonawca_id']]),
        ];
    }
} catch (\Throwable $e) {}

// Dane do eDoręczeń (konfigurowalne przez settings; domyślne wartości FEER)
$ede_ade   = org_setting('ezd_ede_ade')   ?: 'AE:PL-70366-85524-UJBAB-23';
$ede_email = org_setting('ezd_ede_email') ?: 'fundacja@feer.org.pl';
$ede_phone = org_setting('ezd_ede_phone') ?: '601 350 487';

include dirname(__DIR__) . '/includes/header.php';
?>
<style>
.ezd-hero {
  display:flex; align-items:center; gap:1rem; flex-wrap:wrap;
  background:linear-gradient(135deg,#fef2f2 0%,#ffffff 60%); border:1px solid #fee2e2;
  border-radius:16px; padding:1.15rem 1.4rem; margin-bottom:1.25rem;
}
.ezd-hero-icon {
  width:52px; height:52px; border-radius:14px; background:#dc2626; color:#fff; flex-shrink:0;
  display:flex; align-items:center; justify-content:center; font-size:1.55rem;
  box-shadow:0 4px 10px rgba(220,38,38,.28);
}
.ezd-hero-sub { font-size:.83rem; color:#5b6472; margin-top:.1rem; }
.ezd-search { margin-bottom:1.25rem; }
.ezd-search .input-group {
  box-shadow:0 4px 14px rgba(15,23,42,.08); border-radius:14px; overflow:hidden;
}
.ezd-search .input-group-text { background:#fff; border:1px solid #e2e8f0; border-right:none; font-size:1.1rem; color:#64748b; padding:.7rem .55rem .7rem 1rem; }
.ezd-search input {
  border:1px solid #e2e8f0; border-left:none; border-right:none; font-size:.95rem; padding:.7rem .5rem;
}
.ezd-search input:focus { box-shadow:none; border-color:#e2e8f0; }
.ezd-search .btn { padding:.7rem 1.4rem; font-weight:600; }
.ezd-launcher { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:.65rem; margin-bottom:1.5rem; }
.ezd-launcher-tile {
  position:relative; display:flex; align-items:center; gap:.65rem; padding:.7rem .85rem;
  background:#fff; border:1px solid #e2e8f0; border-radius:12px; text-decoration:none;
  color:#334155; font-size:.8rem; font-weight:600; transition:transform .12s,box-shadow .12s,border-color .12s;
}
.ezd-launcher-tile:hover { transform:translateY(-2px); box-shadow:0 8px 18px rgba(15,23,42,.09); border-color:#cbd5e1; color:#0f172a; }
.ezd-launcher-icon { width:38px; height:38px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:1.05rem; flex-shrink:0; }
.ezd-launcher-badge { position:absolute; top:-7px; right:-7px; }
.ezd-stat {
  background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:1.05rem 1.25rem;
  display:flex; align-items:center; gap:1rem; height:100%; transition:box-shadow .12s,border-color .12s;
}
.ezd-stat:hover { box-shadow:0 8px 18px rgba(15,23,42,.07); border-color:#cbd5e1; }
.ezd-stat-icon { width:46px; height:46px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:1.3rem; flex-shrink:0; }
.ezd-stat-val { font-size:1.55rem; font-weight:800; line-height:1; color:#0f172a; }
.ezd-stat-label { font-size:.74rem; color:#5b6472; margin-top:.2rem; }
.ezd-card { background:#fff; border:1px solid #e2e8f0; border-radius:14px; overflow:hidden; }
.ezd-card-header {
  padding:.75rem 1.1rem; font-size:.86rem; font-weight:700; color:#1e293b;
  border-bottom:1px solid #f1f5f9; background:#fafbfc; display:flex; align-items:center; gap:.55rem;
}
.ezd-card-header .bi { color:#64748b; font-size:.95rem; }
.ezd-card-header .ezd-more { margin-left:auto; font-size:.76rem; font-weight:600; text-transform:none; letter-spacing:0; text-decoration:none; }
.ezd-card-header .ezd-count { margin-left:.15rem; font-weight:600; color:#5b6472; }
.ezd-row-link { cursor:pointer; }
.ezd-row-link:hover { background:#f8fafc; }
.ezd-timeline { position:relative; padding:.4rem 1rem .4rem 1.3rem; max-height:480px; overflow-y:auto; }
.ezd-timeline::before { content:''; position:absolute; left:1.05rem; top:.9rem; bottom:.9rem; width:1px; background:#e7ebf1; }
.activity-item { position:relative; padding:.5rem 0; font-size:.79rem; }
.activity-dot { position:absolute; left:-1.05rem; top:.85rem; width:9px; height:9px; border-radius:50%; box-shadow:0 0 0 2px #fff; }
.activity-empty { text-align:center; color:#5b6472; font-size:.8rem; padding:1.5rem 0; }
</style>

<div class="ezd-hero">
  <div class="ezd-hero-icon"><i class="bi bi-building-gear"></i></div>
  <div class="flex-grow-1">
    <h4 class="mb-0 fw-bold">Wirtualne biurko</h4>
    <div class="ezd-hero-sub">Elektroniczne Zarządzanie Dokumentacją</div>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <?php if (can_edit()): ?>
    <a href="<?= APP_URL ?>/ezd/sprawy/add.php" class="btn btn-primary btn-sm">
      <i class="bi bi-folder-plus me-1"></i>Nowa koszulka
    </a>
    <?php if (is_admin()): ?>
    <a href="<?= APP_URL ?>/ezd/teczki/add.php" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-archive me-1"></i>Nowy segregator
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

<!-- Wyszukiwarka EZD -->
<form method="get" action="<?= APP_URL ?>/ezd/szukaj.php" class="ezd-search" role="search" aria-label="Wyszukiwanie w EZD">
  <div class="input-group">
    <span class="input-group-text"><i class="bi bi-search" aria-hidden="true"></i></span>
    <input type="search" name="q" class="form-control" required minlength="2" autocomplete="off"
           placeholder="Szukaj po numerze dokumentu, znaku koszulki lub tytule…"
           aria-label="Szukaj po numerze dokumentu, znaku koszulki lub tytule">
    <button type="submit" class="btn btn-primary"><i class="bi bi-search me-1"></i>Szukaj</button>
  </div>
</form>

<!-- Launcher modułu -->
<div class="ezd-launcher">
  <?php $tiles = [
    ['label'=>'Dziennik podawczy',      'icon'=>'bi-mailbox2',          'color'=>'info',      'href'=>'/ezd/rpw/index.php',            'badge'=>$rpw_stats['koszulka']],
    ['label'=>'Koszulki',               'icon'=>'bi-folder2-open',      'color'=>'primary',   'href'=>'/ezd/sprawy/index.php'],
    ['label'=>'Segregatory aktowe',     'icon'=>'bi-archive',           'color'=>'dark',      'href'=>'/ezd/teczki/index.php'],
    ['label'=>'Wykaz akt (JRWA)',       'icon'=>'bi-tags',              'color'=>'warning',   'href'=>'/ezd/jrwa/index.php'],
    ['label'=>'Archiwum zakładowe',     'icon'=>'bi-archive-fill',      'color'=>'secondary', 'href'=>'/ezd/archiwum/index.php', 'badge'=>$arch_stats['do_brakowania'] ?: null],
  ]; foreach ($tiles as $t): ?>
  <a href="<?= APP_URL . $t['href'] ?>" class="ezd-launcher-tile">
    <?php if (!empty($t['badge'])): ?>
    <span class="ezd-launcher-badge badge rounded-pill bg-info" style="font-size:.6rem"><?= (int)$t['badge'] ?></span>
    <?php endif; ?>
    <div class="ezd-launcher-icon bg-<?= $t['color'] ?> bg-opacity-10">
      <i class="bi <?= $t['icon'] ?> text-<?= $t['color'] ?>"></i>
    </div>
    <?= h($t['label']) ?>
  </a>
  <?php endforeach; ?>
  <a href="#" class="ezd-launcher-tile" data-bs-toggle="modal" data-bs-target="#ezdRejestryModal">
    <div class="ezd-launcher-icon bg-success bg-opacity-10">
      <i class="bi bi-journals text-success"></i>
    </div>
    Rejestry
  </a>
  <a href="#" class="ezd-launcher-tile" data-bs-toggle="modal" data-bs-target="#ezdEDoreczeniaModal">
    <div class="ezd-launcher-icon bg-primary bg-opacity-10">
      <i class="bi bi-envelope-paper text-primary"></i>
    </div>
    eDoręczenia
  </a>
</div>

<!-- Modal: Rejestry -->
<div class="modal fade" id="ezdRejestryModal" tabindex="-1" aria-labelledby="ezdRejestryModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-sm">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h2 class="modal-title h6 mb-0" id="ezdRejestryModalLabel"><i class="bi bi-journals text-success me-2" aria-hidden="true"></i>Rejestry</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
      </div>
      <div class="list-group list-group-flush">
        <a href="<?= APP_URL ?>/ezd/pelnomocnictwa/index.php" class="list-group-item list-group-item-action d-flex align-items-center gap-2">
          <i class="bi bi-person-vcard text-success"></i> Rejestr pełnomocnictw
        </a>
        <a href="<?= APP_URL ?>/ezd/zaswiadczenia/index.php" class="list-group-item list-group-item-action d-flex align-items-center gap-2">
          <i class="bi bi-award text-secondary"></i> Rejestr zaświadczeń
        </a>
        <a href="<?= APP_URL ?>/ezd/wolontariusze/index.php" class="list-group-item list-group-item-action d-flex align-items-center gap-2">
          <i class="bi bi-heart text-danger"></i> Wolontariusze bez umowy
        </a>
        <?php if (is_admin()): ?>
        <a href="<?= APP_URL ?>/admin/ezd_szablony.php" class="list-group-item list-group-item-action d-flex align-items-center gap-2">
          <i class="bi bi-file-earmark-text text-primary"></i> Szablony pism
        </a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- Modal: eDoręczenia -->
<div class="modal fade" id="ezdEDoreczeniaModal" tabindex="-1" aria-labelledby="ezdEDoreczeniaModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h2 class="modal-title h6 mb-0" id="ezdEDoreczeniaModalLabel"><i class="bi bi-envelope-paper text-primary me-2" aria-hidden="true"></i>eDoręczenia</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
      </div>
      <div class="modal-body">
        <p class="text-muted small mb-3">Dane fundacji do korespondencji przez eDoręczenia. Użyj „Podpowiedz dane", aby skopiować komplet do schowka.</p>

        <div class="list-group mb-3" id="edeFields">
          <div class="list-group-item d-flex align-items-center gap-2">
            <div class="flex-grow-1">
              <div class="text-muted" style="font-size:.7rem;text-transform:uppercase;letter-spacing:.03em">Adres do doręczeń elektronicznych (ADE)</div>
              <div class="fw-semibold font-monospace" data-ede-copy><?= h($ede_ade) ?></div>
            </div>
            <button type="button" class="btn btn-sm btn-outline-secondary ede-copy-btn" data-ede-value="<?= h($ede_ade) ?>" title="Kopiuj adres ADE"><i class="bi bi-clipboard"></i></button>
          </div>
          <div class="list-group-item d-flex align-items-center gap-2">
            <div class="flex-grow-1">
              <div class="text-muted" style="font-size:.7rem;text-transform:uppercase;letter-spacing:.03em">Ogólny adres e-mail</div>
              <div class="fw-semibold" data-ede-copy><?= h($ede_email) ?></div>
            </div>
            <button type="button" class="btn btn-sm btn-outline-secondary ede-copy-btn" data-ede-value="<?= h($ede_email) ?>" title="Kopiuj e-mail"><i class="bi bi-clipboard"></i></button>
          </div>
          <div class="list-group-item d-flex align-items-center gap-2">
            <div class="flex-grow-1">
              <div class="text-muted" style="font-size:.7rem;text-transform:uppercase;letter-spacing:.03em">Telefon</div>
              <div class="fw-semibold" data-ede-copy><?= h($ede_phone) ?></div>
            </div>
            <button type="button" class="btn btn-sm btn-outline-secondary ede-copy-btn" data-ede-value="<?= h($ede_phone) ?>" title="Kopiuj telefon"><i class="bi bi-clipboard"></i></button>
          </div>
        </div>

        <div class="d-flex flex-wrap gap-2">
          <button type="button" class="btn btn-primary btn-sm" id="edeHintBtn"
                  data-ede-all="Adres do doręczeń (ADE): <?= h($ede_ade) ?>&#10;E-mail: <?= h($ede_email) ?>&#10;Telefon: <?= h($ede_phone) ?>">
            <i class="bi bi-magic me-1"></i>Podpowiedz dane
          </button>
          <a href="https://app.edopost.pl/login" target="_blank" rel="noopener" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-box-arrow-up-right me-1"></i>Zaloguj do eDoPost
          </a>
          <span id="edeCopyStatus" class="align-self-center text-success small d-none"><i class="bi bi-check-circle me-1"></i>Skopiowano do schowka</span>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  var modal = document.getElementById('ezdEDoreczeniaModal');
  if (!modal) return;
  var status = document.getElementById('edeCopyStatus');
  function flash(){
    if (!status) return;
    status.classList.remove('d-none');
    clearTimeout(flash._t);
    flash._t = setTimeout(function(){ status.classList.add('d-none'); }, 2000);
  }
  function copy(text){
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(flash, fallback);
    } else { fallback(); }
    function fallback(){
      var ta = document.createElement('textarea');
      ta.value = text; ta.style.position='fixed'; ta.style.opacity='0';
      document.body.appendChild(ta); ta.select();
      try { document.execCommand('copy'); flash(); } catch(e){}
      document.body.removeChild(ta);
    }
  }
  modal.querySelectorAll('.ede-copy-btn').forEach(function(btn){
    btn.addEventListener('click', function(){ copy(btn.getAttribute('data-ede-value') || ''); });
  });
  var hint = document.getElementById('edeHintBtn');
  if (hint) hint.addEventListener('click', function(){
    copy((hint.getAttribute('data-ede-all') || '').replace(/&#10;/g, '\n'));
  });
})();
</script>

<!-- Statystyki -->
<div class="row g-3 mb-4">
  <?php $items = [
    ['label'=>'W koszulce (RPW)',   'val'=>$rpw_stats['koszulka'], 'icon'=>'bi-inbox-fill',          'color'=>'info'],
    ['label'=>'Segregatory otwarte','val'=>$stats['teczki_open'],  'icon'=>'bi-archive-fill',       'color'=>'primary'],
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
        <div class="ezd-stat-val"><?= $it['val'] ?></div>
        <div class="ezd-stat-label"><?= $it['label'] ?></div>
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
      <?php if ($my_sprawy): foreach ($my_sprawy as $s): ?>
      <div class="d-flex align-items-center gap-3 px-3 py-2 border-bottom ezd-row-link" style="font-size:.82rem" onclick="location='<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= $s['id'] ?>'">
        <div class="flex-grow-1 overflow-hidden">
          <div class="fw-semibold text-truncate"><?= h($s['title']) ?>
            <?php if(!empty($s['is_shared'])): ?><span class="badge bg-info bg-opacity-15 text-info border border-info ms-1" style="font-size:.6rem"><i class="bi bi-people me-1"></i>Współdzielona</span><?php endif; ?>
          </div>
          <div class="font-monospace text-muted" style="font-size:.7rem"><?= h($s['znak_sprawy']) ?></div>
        </div>
        <div class="text-nowrap flex-shrink-0"><?= ezd_etap_badge($s['etap'] ?? 'wszczeta') ?></div>
        <div class="text-nowrap text-end flex-shrink-0" style="font-size:.72rem;min-width:5.5rem">
          <?php if(!empty($s['ciagla'])): ?><span class="text-info"><i class="bi bi-infinity"></i></span>
          <?php elseif($s['deadline']): ?><span class="<?= $s['deadline']<date('Y-m-d')?'text-danger fw-bold':'text-muted' ?>"><?= date_pl($s['deadline']) ?></span>
          <?php else: ?><span class="text-muted">—</span><?php endif; ?>
        </div>
      </div>
      <?php endforeach; else: ?>
      <div class="activity-empty">Nie masz przypisanych aktywnych koszulek</div>
      <?php endif; ?>
    </div>
    <!-- Moje dokumenty -->
    <div class="tab-pane fade" id="tab-moje-pisma">
      <?php if ($my_pisma): foreach ($my_pisma as $p): $k = EZD_KIERUNKI[$p['kierunek']] ?? ['icon'=>'bi-envelope','class'=>'secondary','label'=>$p['kierunek']]; ?>
      <div class="d-flex align-items-center gap-3 px-3 py-2 border-bottom ezd-row-link" style="font-size:.82rem" onclick="location='<?= APP_URL ?>/ezd/pisma/view.php?id=<?= $p['id'] ?>'">
        <i class="bi <?= $k['icon'] ?> text-<?= $k['class'] ?> flex-shrink-0" title="<?= h($k['label']) ?>"></i>
        <div class="font-monospace text-muted flex-shrink-0" style="font-size:.72rem"><?= h($p['sygnatura']) ?></div>
        <div class="flex-grow-1 overflow-hidden text-truncate"><?= h($p['title']) ?></div>
        <span class="badge bg-light text-dark border flex-shrink-0" style="font-size:.62rem"><?= h($p['status']) ?></span>
      </div>
      <?php endforeach; else: ?>
      <div class="activity-empty">Nie jesteś referentem żadnego pisma</div>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="row g-4">
  <!-- Aktywne koszulki -->
  <div class="col-lg-7">
    <div class="ezd-card">
      <div class="ezd-card-header">
        <i class="bi bi-folder2-open"></i> Aktywne koszulki <span class="ezd-count">(<?= count($recent_sprawy) ?>)</span>
        <a href="<?= APP_URL ?>/ezd/sprawy/index.php" class="ezd-more">Wszystkie →</a>
      </div>
      <div class="table-responsive">
        <table class="table table-sm table-hover mb-0" style="font-size:.8rem">
          <thead class="table-light">
            <tr><th>Tytuł</th><th>Znak koszulki</th><th>Priorytet</th><th>Deadline</th><th>Właściciel</th><th>Przekazana do</th></tr>
          </thead>
          <tbody>
          <?php foreach ($recent_sprawy as $s): $dk = $dekr_map[(int)$s['id']] ?? null; ?>
          <tr>
            <td><a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= $s['id'] ?>" class="fw-semibold text-decoration-none"><?= h(mb_substr($s['title'], 0, 45)) ?></a></td>
            <td class="font-monospace text-muted" style="font-size:.72rem"><?= h($s['znak_sprawy']) ?></td>
            <td><?= ezd_priority_badge($s['priority']) ?></td>
            <td class="<?= $s['deadline'] && $s['deadline'] < date('Y-m-d') ? 'text-danger fw-bold' : '' ?>">
              <?= $s['deadline'] ? date_pl($s['deadline']) : '—' ?>
            </td>
            <td><?= h($s['owner_name'] ?? '—') ?></td>
            <td>
              <?php if ($dk): ?>
              <?= h($dk['name']) ?>
              <span class="badge bg-warning text-dark ms-1" style="font-size:.62rem"
                    title="Liczba koszulek z otwartym przekazaniem u tej osoby"><?= (int)$dk['count'] ?></span>
              <?php else: ?><span class="text-muted">—</span><?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$recent_sprawy): ?>
          <tr><td colspan="6" class="activity-empty">Brak aktywnych koszulek</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Moje przekazania -->
    <?php if ($my_dekr): ?>
    <div class="ezd-card mt-3">
      <div class="ezd-card-header">
        <i class="bi bi-person-lines-fill text-warning"></i> Moje zadania do wykonania <span class="ezd-count">(<?= count($my_dekr) ?>)</span>
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
      <div class="ezd-timeline">
        <?php $action_colors = ['sprawa_create'=>'success','sprawa_update'=>'primary','pismo_create'=>'info','umowa_create'=>'warning','upload'=>'secondary','teczka_create'=>'success','dekretacja_create'=>'warning']; ?>
        <?php foreach ($activity as $a): ?>
        <div class="activity-item">
          <div class="activity-dot" style="background:#<?= match($action_colors[$a['action']] ?? 'secondary') { 'success'=>'22c55e','primary'=>'3b82f6','info'=>'06b6d4','warning'=>'f59e0b',default=>'94a3b8' } ?>"></div>
          <div>
            <?php if ($a['znak_sprawy']): ?>
            <a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= $a['sprawa_id'] ?>" class="font-monospace text-decoration-none fw-semibold" style="font-size:.72rem"><?= h($a['znak_sprawy']) ?></a>
            <?php endif; ?>
            <div style="color:#374151;line-height:1.3"><?= h($a['details'] ?: $a['action']) ?></div>
            <div style="color:#5b6472;font-size:.7rem"><?= h($a['user_name'] ?? '—') ?> · <?= date('d.m H:i', strtotime($a['created_at'])) ?></div>
          </div>
        </div>
        <?php endforeach; ?>
        <?php if (!$activity): ?>
        <div class="activity-empty">Brak aktywności</div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
