<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/ezd.php';

require_login();
require_module_enabled('ezd_enabled', 'Moduł EZD Wirtualne biurko'); ezd_require_access();

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
  // Przycisk kopiowania ADE z karty (poza modalem)
  document.querySelectorAll('.ede-copy-btn[data-ede-value]').forEach(function(btn){
    btn.addEventListener('click', function(){ copy(btn.getAttribute('data-ede-value') || ''); });
  });
})();
</script>

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

<div class="row g-4">
  <!-- Lewa kolumna -->
  <div class="col-lg-8">

    <!-- Widget: Moje koszulki / Moje dokumenty -->
    <div class="ezd-card mb-3">
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

    <!-- Aktywne koszulki -->
    <div class="ezd-card mb-3">
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
    <div class="ezd-card">
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

  </div><!-- /.col-lg-8 -->

  <!-- Prawa kolumna -->
  <div class="col-lg-4">

    <!-- eDoręczenia -->
    <div class="ezd-card mb-3">
      <div class="ezd-card-header">
        <i class="bi bi-envelope-paper"></i> eDoręczenia
        <button type="button" class="ezd-more btn btn-link p-0 border-0" data-bs-toggle="modal" data-bs-target="#ezdEDoreczeniaModal" style="font-size:.76rem;font-weight:600">
          Szczegóły →
        </button>
      </div>
      <div class="px-3 py-2" style="font-size:.8rem">
        <div class="mb-1 text-muted" style="font-size:.68rem;text-transform:uppercase;letter-spacing:.04em">Adres ADE</div>
        <div class="d-flex align-items-center gap-2">
          <code class="flex-grow-1 text-truncate" style="font-size:.77rem;color:#1e293b"><?= h($ede_ade) ?></code>
          <button type="button" class="btn btn-xs btn-outline-secondary btn-sm ede-card-copy" data-ede-value="<?= h($ede_ade) ?>" title="Kopiuj ADE">
            <i class="bi bi-clipboard"></i>
          </button>
        </div>
        <div class="mt-2 text-muted" style="font-size:.75rem"><?= h($ede_email) ?> · <?= h($ede_phone) ?></div>
      </div>
    </div>

    <!-- Aktywność -->
    <div class="ezd-card">
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

  </div><!-- /.col-lg-4 -->
</div><!-- /.row -->

<script>
(function(){
  document.querySelectorAll('.ede-card-copy').forEach(function(btn){
    btn.addEventListener('click', function(){
      var text = btn.getAttribute('data-ede-value') || '';
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text);
      } else {
        var ta = document.createElement('textarea');
        ta.value = text; ta.style.position='fixed'; ta.style.opacity='0';
        document.body.appendChild(ta); ta.select();
        try { document.execCommand('copy'); } catch(e){}
        document.body.removeChild(ta);
      }
      var icon = btn.querySelector('.bi');
      if (icon) { icon.className = 'bi bi-clipboard-check'; setTimeout(function(){ icon.className = 'bi bi-clipboard'; }, 1500); }
    });
  });
})();
</script>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
