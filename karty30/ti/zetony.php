<?php
/**
 * karty30/ti/zetony.php — Żetony SZO w stylu USOS.
 * Pule = kategorie żetonów (np. „Lekcje ind. 2026/Q1") przydzielane masowo.
 * Kursanci mają oddzielne saldo na każdą pulę.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/karty30.php';
require_once dirname(dirname(__DIR__)) . '/includes/ti_planner_ext.php';

k30_require_access();
karty30_migrate();
ti_planner_ext_migrate();

$PAGE_TITLE = 'Żetony — SZO';
$can_write  = can_write('karty30') || is_admin();
$uid        = current_user()['id'] ?? null;

/* ══════════════════════════════════════════════════════════════════════════
   OBSŁUGA POST
   ══════════════════════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!$can_write) { http_response_code(403); die('Brak uprawnień.'); }
    $op = $_POST['_op'] ?? '';

    /* ── Pule ─────────────────────────────────────────────────────────── */
    if ($op === 'pool_save') {
        if (trim($_POST['name'] ?? '') === '') {
            flash_set('danger', 'Podaj nazwę puli.');
            header('Location: zetony.php?tab=pule'); exit;
        }
        $pid = (int)($_POST['pool_id'] ?? 0) ?: null;
        pl_pool_save($_POST, $pid);
        flash_set('success', $pid ? 'Pula zaktualizowana.' : 'Pula utworzona.');
        header('Location: zetony.php?tab=pule'); exit;
    }

    if ($op === 'pool_toggle') {
        $pid = (int)($_POST['pool_id'] ?? 0);
        $p   = pl_pool_get($pid);
        if ($p) {
            db_exec("UPDATE k30_pl_token_pools SET is_active=? WHERE id=?", [empty($p['is_active']) ? 1 : 0, $pid]);
            flash_set('success', empty($p['is_active']) ? 'Pula aktywowana.' : 'Pula dezaktywowana.');
        }
        header('Location: zetony.php?tab=pule'); exit;
    }

    if ($op === 'pool_delete') {
        $pid = (int)($_POST['pool_id'] ?? 0);
        if ($pid) { db_exec("DELETE FROM k30_pl_token_pools WHERE id=?", [$pid]); flash_set('success', 'Pula usunięta.'); }
        header('Location: zetony.php?tab=pule'); exit;
    }

    /* ── Masowe przyznanie żetonów ─────────────────────────────────────── */
    if ($op === 'pool_grant_bulk') {
        $pid    = (int)($_POST['pool_id'] ?? 0);
        $amount = (int)($_POST['amount'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');
        $mode   = $_POST['grant_mode'] ?? 'custom';

        if (!$pid || !pl_pool_get($pid)) { flash_set('danger', 'Wybierz pulę.'); header('Location: zetony.php?tab=pule'); exit; }
        if ($amount <= 0) { flash_set('danger', 'Podaj liczbę żetonów > 0.'); header('Location: zetony.php?tab=pule&pool='.$pid); exit; }

        $client_ids = [];
        if ($mode === 'course') {
            $cid = (int)($_POST['course_id'] ?? 0);
            if ($cid) {
                $rows = db_all("SELECT DISTINCT client_id FROM k30_ti_enrollments WHERE course_id=? AND status='active'", [$cid]);
                $client_ids = array_column($rows, 'client_id');
            }
        } elseif ($mode === 'custom') {
            $raw = trim($_POST['client_ids'] ?? '');
            foreach (explode(',', $raw) as $id) { if ((int)$id > 0) $client_ids[] = (int)$id; }
        } elseif ($mode === 'all_active') {
            $rows = db_all("SELECT DISTINCT client_id FROM k30_ti_enrollments WHERE status='active'", []);
            $client_ids = array_column($rows, 'client_id');
        }

        $client_ids = array_unique(array_filter($client_ids));
        if (!$client_ids) { flash_set('warning', 'Brak kursantów do zasilenia.'); header('Location: zetony.php?tab=pule&pool='.$pid); exit; }

        $n = pl_pool_grant_bulk($pid, $client_ids, $amount, $reason ?: 'przyznanie_admin', $uid);
        flash_set('success', "Dodano $amount żetonów dla $n kursantów.");
        header('Location: zetony.php?tab=pule&pool='.$pid); exit;
    }

    /* ── Ręczne zasilenie pojedynczego portfela puli ─────────────────── */
    if ($op === 'pool_grant_single') {
        $pid    = (int)($_POST['pool_id']   ?? 0);
        $cid    = (int)($_POST['client_id'] ?? 0);
        $amount = (int)($_POST['amount']    ?? 0);
        $reason = trim($_POST['reason'] ?? '');
        if ($pid && $cid && $amount > 0) {
            pl_pool_grant($pid, $cid, $amount, $reason ?: 'doładowanie_admin', $uid);
            flash_set('success', "Dodano $amount żetonów.");
        } else {
            flash_set('danger', 'Nieprawidłowe dane.');
        }
        header('Location: zetony.php?tab=pule&pool='.$pid); exit;
    }

    /* ── Legacy: doładowanie ogólnego portfela ───────────────────────── */
    if ($op === 'grant') {
        $client_id = (int)($_POST['client_id'] ?? 0);
        $amount    = (int)($_POST['amount']    ?? 0);
        $note      = trim($_POST['note'] ?? '');
        if ($client_id > 0 && $amount > 0) {
            try {
                pl_tokens_grant($client_id, $amount, $note ?: 'doładowanie_admin', $uid);
                flash_set('success', "Dodano $amount żetonów do portfela ogólnego.");
            } catch (\Throwable $e) {
                flash_set('danger', 'Błąd: ' . h($e->getMessage()));
            }
        } else {
            flash_set('danger', 'Nieprawidłowe dane.');
        }
        header('Location: zetony.php'); exit;
    }

    /* ── Cennik ──────────────────────────────────────────────────────── */
    if ($op === 'price_save') {
        $id = (int)($_POST['price_id'] ?? 0) ?: null;
        pl_price_save($_POST, $id);
        flash_set('success', 'Reguła cennika zapisana.');
        header('Location: zetony.php?tab=cennik'); exit;
    }

    if ($op === 'price_delete') {
        $id = (int)($_POST['price_id'] ?? 0);
        if ($id) db_exec("DELETE FROM k30_pl_token_prices WHERE id=?", [$id]);
        flash_set('success', 'Reguła usunięta.');
        header('Location: zetony.php?tab=cennik'); exit;
    }
}

/* ══════════════════════════════════════════════════════════════════════════
   DANE
   ══════════════════════════════════════════════════════════════════════════ */
$tab       = $_GET['tab'] ?? 'pule';
$sel_pool  = (int)($_GET['pool'] ?? 0);
$pool_view = $sel_pool ? pl_pool_get($sel_pool) : null;

$pools     = pl_pools_list();
$prices    = pl_prices_list();

// Portfele puli (gdy wybrany konkretny pool)
$pool_wallets = $pool_view ? pl_pool_wallets($sel_pool) : [];

// Historia transakcji puli (gdy ?history_pw=)
$history_pw  = (int)($_GET['history_pw'] ?? 0);
$pool_history = $history_pw ? pl_pool_txns($history_pw, 30) : [];

// Portfele ogólne (legacy)
$q_search = trim($_GET['q'] ?? '');
$wallets  = db_all(
    "SELECT w.*, c.name AS client_name, c.email AS client_email
     FROM k30_pl_token_wallets w
     JOIN k30_clients c ON c.id = w.client_id
     " . ($q_search ? "WHERE c.name LIKE ? OR c.email LIKE ?" : "") . "
     ORDER BY w.balance DESC, c.name",
    $q_search ? ["%$q_search%", "%$q_search%"] : []
);

// Dane do formularzy
$courses    = db_all("SELECT id, name FROM k30_ti_courses WHERE status!='cancelled' ORDER BY name", []);
$tech_paths = db_all("SELECT id, name FROM k30_pl_tech_paths WHERE is_active=1 ORDER BY name", []);
$users      = db_all("SELECT id, name FROM users WHERE is_active=1 ORDER BY name", []);

// Statystyki ogólne
$stats = db_one("SELECT
    COUNT(*) AS cnt,
    SUM(balance)       AS total_balance,
    SUM(total_granted) AS total_granted,
    SUM(total_spent)   AS total_spent
FROM k30_pl_token_wallets");

// Edycja puli
$edit_pool_id = (int)($_GET['edit_pool'] ?? 0);
$edit_pool    = $edit_pool_id ? pl_pool_get($edit_pool_id) : null;
$pf = $edit_pool ?: ['id'=>0,'name'=>'','color'=>'#6366f1','description'=>'','period_key'=>'','valid_from'=>'','valid_to'=>'','default_grant'=>0,'is_active'=>1];

/* ══════════════════════════════════════════════════════════════════════════
   HTML
   ══════════════════════════════════════════════════════════════════════════ */
require_once dirname(dirname(__DIR__)) . '/includes/header.php';
?>
<div class="container-fluid px-4 py-3">

<div class="d-flex align-items-center gap-3 mb-3 flex-wrap">
  <div>
    <h4 class="fw-bold mb-0"><i class="bi bi-ticket-detailed me-2 text-primary"></i>Żetony SZO</h4>
    <p class="text-muted small mb-0">Pule dostępu do zajęć — przydzielane kursantom na okresy</p>
  </div>
  <div class="ms-auto d-flex gap-2">
    <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Kursy TI</a>
  </div>
</div>

<?= flash_html() ?>

<!-- Zakładki -->
<ul class="nav nav-tabs mb-4">
  <li class="nav-item">
    <a class="nav-link <?= $tab==='pule'?'active':'' ?>" href="zetony.php?tab=pule">
      <i class="bi bi-collection me-1" aria-hidden="true"></i>Pule
      <span class="badge bg-secondary ms-1"><?= count($pools) ?></span>
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $tab==='portfele'?'active':'' ?>" href="zetony.php?tab=portfele">
      <i class="bi bi-wallet2 me-1" aria-hidden="true"></i>Portfele ogólne
      <?php if ($wallets): ?><span class="badge bg-secondary ms-1"><?= count($wallets) ?></span><?php endif; ?>
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $tab==='cennik'?'active':'' ?>" href="zetony.php?tab=cennik">
      <i class="bi bi-tags me-1" aria-hidden="true"></i>Cennik
      <?php if ($prices): ?><span class="badge bg-secondary ms-1"><?= count($prices) ?></span><?php endif; ?>
    </a>
  </li>
</ul>

<?php /* ═══════════════════ TAB: PULE ════════════════════════════════════ */ ?>
<?php if ($tab === 'pule'): ?>

<div class="row g-4">

  <!-- Lista pul -->
  <div class="col-xl-8">

    <?php if (!$pool_view): ?>
    <!-- Widok: wszystkie pule -->
    <div class="card border-0 shadow-sm">
      <div class="card-header fw-semibold d-flex align-items-center gap-2">
        <i class="bi bi-collection me-1" aria-hidden="true"></i>Pule żetonów
        <a href="zetony.php?tab=pule#pool-form" class="btn btn-sm btn-primary ms-auto"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Nowa pula</a>
      </div>
      <?php if (!$pools): ?>
      <div class="card-body text-muted">
        <p class="mb-1">Brak pul. Utwórz pierwszą — żetony w stylu USOS:</p>
        <ul class="small mb-0">
          <li>Każda pula ma nazwę, kolor i okres ważności (np. „Lekcje ind. 2026/Q1")</li>
          <li>Przydzielasz kursantom żetony z puli hurtowo lub indywidualnie</li>
          <li>Kursant widzi saldo w panelu</li>
        </ul>
      </div>
      <?php else: ?>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 small">
          <thead class="table-light"><tr>
            <th>Pula</th>
            <th class="text-center">Kursanci</th>
            <th class="text-end">Przyznane</th>
            <th class="text-end">Wydane</th>
            <th class="text-end">Pozostałe</th>
            <th>Status</th>
            <th class="text-end">Akcje</th>
          </tr></thead>
          <tbody>
          <?php foreach ($pools as $p): ?>
          <tr>
            <td>
              <span class="d-inline-block me-2 rounded-circle" style="width:12px;height:12px;background:<?= h($p['color']) ?>;flex-shrink:0" aria-hidden="true"></span>
              <a href="zetony.php?tab=pule&amp;pool=<?= (int)$p['id'] ?>" class="fw-semibold text-decoration-none"><?= h($p['name']) ?></a>
              <?php if ($p['period_key']): ?><span class="badge bg-light text-dark border ms-1"><?= h($p['period_key']) ?></span><?php endif; ?>
              <?php if ($p['description']): ?><div class="text-muted" style="font-size:.75rem"><?= h(mb_strimwidth($p['description'],0,80,'…','UTF-8')) ?></div><?php endif; ?>
              <?php if ($p['valid_from'] || $p['valid_to']): ?>
                <div class="text-muted" style="font-size:.72rem">
                  <?= $p['valid_from'] ? 'od '.h($p['valid_from']) : '' ?><?= $p['valid_to'] ? ' do '.h($p['valid_to']) : '' ?>
                </div>
              <?php endif; ?>
            </td>
            <td class="text-center"><?= (int)$p['n_wallets'] ?></td>
            <td class="text-end text-success"><?= (int)$p['total_granted'] ?></td>
            <td class="text-end text-muted"><?= (int)$p['total_spent'] ?></td>
            <td class="text-end fw-bold text-primary"><?= max(0, (int)$p['total_granted'] - (int)$p['total_spent']) ?></td>
            <td>
              <?php if (!empty($p['is_active'])): ?>
                <span class="badge text-bg-success">Aktywna</span>
              <?php else: ?>
                <span class="badge text-bg-secondary">Nieaktywna</span>
              <?php endif; ?>
            </td>
            <td class="text-end text-nowrap">
              <a href="zetony.php?tab=pule&amp;pool=<?= (int)$p['id'] ?>" class="btn btn-sm btn-outline-primary py-0 px-2" title="Portfele i przyznanie"><i class="bi bi-wallet2" aria-hidden="true"></i></a>
              <a href="zetony.php?tab=pule&amp;edit_pool=<?= (int)$p['id'] ?>#pool-form" class="btn btn-sm btn-outline-secondary py-0 px-2" title="Edytuj"><i class="bi bi-pencil" aria-hidden="true"></i></a>
              <?php if ($can_write): ?>
              <form method="post" class="d-inline">
                <input type="hidden" name="_csrf"    value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="_op"      value="pool_toggle">
                <input type="hidden" name="pool_id"  value="<?= (int)$p['id'] ?>">
                <button class="btn btn-sm btn-outline-secondary py-0 px-2" title="<?= !empty($p['is_active'])?'Dezaktywuj':'Aktywuj' ?>">
                  <i class="bi bi-<?= !empty($p['is_active'])?'pause-fill':'play-fill' ?>" aria-hidden="true"></i>
                </button>
              </form>
              <?php if (!(int)$p['n_wallets']): ?>
              <form method="post" class="d-inline" onsubmit="return confirm('Usunąć pulę?')">
                <input type="hidden" name="_csrf"    value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="_op"      value="pool_delete">
                <input type="hidden" name="pool_id"  value="<?= (int)$p['id'] ?>">
                <button class="btn btn-sm btn-outline-danger py-0 px-2" title="Usuń"><i class="bi bi-trash" aria-hidden="true"></i></button>
              </form>
              <?php endif; ?>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>

    <?php else: ?>
    <!-- Widok: portfele konkretnej puli -->
    <div class="card border-0 shadow-sm">
      <div class="card-header fw-semibold d-flex align-items-center gap-2 flex-wrap">
        <span class="d-inline-block rounded-circle me-1" style="width:14px;height:14px;background:<?= h($pool_view['color']) ?>" aria-hidden="true"></span>
        <?= h($pool_view['name']) ?>
        <?php if ($pool_view['period_key']): ?><span class="badge bg-light text-dark border"><?= h($pool_view['period_key']) ?></span><?php endif; ?>
        <?php if (empty($pool_view['is_active'])): ?><span class="badge text-bg-secondary">Nieaktywna</span><?php endif; ?>
        <a href="zetony.php?tab=pule" class="btn btn-sm btn-outline-secondary ms-auto"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Wszystkie pule</a>
      </div>

      <!-- Statystyki puli -->
      <?php $pw_total_granted = array_sum(array_column($pool_wallets,'granted'));
            $pw_total_spent   = array_sum(array_column($pool_wallets,'spent'));
            $pw_balance       = max(0, $pw_total_granted - $pw_total_spent); ?>
      <div class="card-body border-bottom py-2 d-flex gap-4 flex-wrap small">
        <div><span class="text-muted">Kursanci:</span> <strong><?= count($pool_wallets) ?></strong></div>
        <div><span class="text-muted">Przyznane:</span> <strong class="text-success"><?= $pw_total_granted ?></strong></div>
        <div><span class="text-muted">Wydane:</span> <strong class="text-muted"><?= $pw_total_spent ?></strong></div>
        <div><span class="text-muted">Dostępne:</span> <strong class="text-primary"><?= $pw_balance ?></strong></div>
      </div>

      <!-- Portfele kursantów -->
      <?php if (!$pool_wallets): ?>
      <div class="card-body text-muted small">Brak portfeli — skorzystaj z przyznania żetonów po prawej.</div>
      <?php else: ?>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 small">
          <thead class="table-light"><tr>
            <th>Kursant</th>
            <th class="text-end">Przyznane</th>
            <th class="text-end">Wydane</th>
            <th class="text-end">Dostępne</th>
            <th class="text-end">Akcje</th>
          </tr></thead>
          <tbody>
          <?php foreach ($pool_wallets as $pw): ?>
          <tr>
            <td>
              <div class="fw-semibold"><?= h($pw['client_name']) ?></div>
              <?php if ($pw['client_email']): ?><div class="text-muted" style="font-size:.72rem"><?= h($pw['client_email']) ?></div><?php endif; ?>
            </td>
            <td class="text-end text-success"><?= (int)$pw['granted'] ?></td>
            <td class="text-end text-muted"><?= (int)$pw['spent'] ?></td>
            <td class="text-end fw-bold <?= (int)$pw['balance']>0?'text-primary':'text-danger' ?>"><?= (int)$pw['balance'] ?></td>
            <td class="text-end text-nowrap">
              <a href="?tab=pule&amp;pool=<?= $sel_pool ?>&amp;history_pw=<?= (int)$pw['id'] ?>#hist-<?= (int)$pw['id'] ?>"
                 class="btn btn-sm btn-outline-secondary py-0 px-2" title="Historia"><i class="bi bi-clock-history" aria-hidden="true"></i></a>
              <?php if ($can_write): ?>
              <button class="btn btn-sm btn-outline-warning py-0 px-2"
                      data-bs-toggle="modal" data-bs-target="#modalSingleGrant"
                      data-cid="<?= (int)$pw['client_id'] ?>"
                      data-name="<?= h($pw['client_name']) ?>"
                      title="Doładuj"><i class="bi bi-plus-circle" aria-hidden="true"></i></button>
              <?php endif; ?>
            </td>
          </tr>
          <?php if ($history_pw === (int)$pw['id'] && $pool_history): ?>
          <tr id="hist-<?= (int)$pw['id'] ?>" class="table-warning">
            <td colspan="5" class="px-4 py-2">
              <div class="small fw-semibold text-uppercase text-muted mb-1"><i class="bi bi-clock-history me-1"></i>Ostatnie transakcje</div>
              <table class="table table-sm mb-0" style="font-size:.78rem">
                <thead><tr><th>Data</th><th>Typ</th><th class="text-end">Kwota</th><th>Powód</th></tr></thead>
                <tbody>
                <?php foreach ($pool_history as $tx): ?>
                <tr>
                  <td class="text-muted"><?= h(substr($tx['created_at'],0,16)) ?></td>
                  <td><?= $tx['direction']==='credit' ? '<span class="text-success">↑ credit</span>' : '<span class="text-danger">↓ debit</span>' ?></td>
                  <td class="text-end"><?= (int)$tx['amount'] ?></td>
                  <td><?= h($tx['reason']) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </td>
          </tr>
          <?php endif; ?>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>

    <!-- Modal: doładowanie pojedynczego -->
    <?php if ($can_write): ?>
    <div class="modal fade" id="modalSingleGrant" tabindex="-1" aria-labelledby="msgLabel" aria-hidden="true">
      <div class="modal-dialog modal-sm">
        <form method="post" action="zetony.php?tab=pule&amp;pool=<?= $sel_pool ?>">
          <input type="hidden" name="_csrf"      value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="_op"        value="pool_grant_single">
          <input type="hidden" name="pool_id"    value="<?= $sel_pool ?>">
          <input type="hidden" name="client_id"  id="singleClientId" value="">
          <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title" id="msgLabel">Doładuj portfel</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
              <p class="small text-muted mb-2" id="singleClientName"></p>
              <div class="mb-2">
                <label class="form-label small fw-semibold">Żetonów</label>
                <input type="number" class="form-control" name="amount" min="1" max="9999" value="10" required>
              </div>
              <div class="mb-0">
                <label class="form-label small fw-semibold">Powód</label>
                <input type="text" class="form-control" name="reason" placeholder="opcjonalnie">
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
              <button type="submit" class="btn btn-sm btn-warning fw-semibold"><i class="bi bi-plus-circle me-1" aria-hidden="true"></i>Dodaj</button>
            </div>
          </div>
        </form>
      </div>
    </div>
    <script>
    document.getElementById('modalSingleGrant')?.addEventListener('show.bs.modal', function(e) {
      const btn = e.relatedTarget;
      this.querySelector('#singleClientId').value = btn.dataset.cid || '';
      this.querySelector('#singleClientName').textContent = btn.dataset.name || '';
    });
    </script>
    <?php endif; ?>

    <?php endif; // end pool_view vs pool list ?>
  </div><!-- /col -->

  <!-- PRAWA: formularz puli + masowe przyznanie -->
  <div class="col-xl-4">

    <!-- Formularz puli -->
    <?php if ($can_write): ?>
    <div class="card border-0 shadow-sm mb-4" id="pool-form">
      <div class="card-header fw-semibold">
        <i class="bi bi-<?= $edit_pool?'pencil':'plus-lg' ?> me-2" aria-hidden="true"></i><?= $edit_pool?'Edytuj pulę':'Nowa pula' ?>
      </div>
      <div class="card-body">
        <form method="post">
          <input type="hidden" name="_csrf"    value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="_op"      value="pool_save">
          <input type="hidden" name="pool_id"  value="<?= (int)$pf['id'] ?>">
          <div class="mb-2">
            <label class="form-label fw-semibold small" for="pname">Nazwa <span class="text-danger">*</span></label>
            <input type="text" class="form-control form-control-sm" id="pname" name="name" value="<?= h($pf['name']) ?>" required placeholder="np. Lekcje ind. 2026/Q1">
          </div>
          <div class="mb-2">
            <label class="form-label fw-semibold small" for="pperiod">Klucz okresu</label>
            <input type="text" class="form-control form-control-sm" id="pperiod" name="period_key" value="<?= h($pf['period_key']) ?>" placeholder="np. 2026/Q1, 2025/2026">
          </div>
          <div class="mb-2">
            <label class="form-label small" for="pdesc">Opis</label>
            <textarea class="form-control form-control-sm" id="pdesc" name="description" rows="2"><?= h($pf['description']) ?></textarea>
          </div>
          <div class="row g-2 mb-2">
            <div class="col-8">
              <label class="form-label small fw-semibold" for="pgrant">Domyślne żetony/kursanta</label>
              <input type="number" class="form-control form-control-sm" id="pgrant" name="default_grant" min="0" max="9999" value="<?= (int)$pf['default_grant'] ?>">
            </div>
            <div class="col-4">
              <label class="form-label small fw-semibold" for="pcolor">Kolor</label>
              <input type="color" class="form-control form-control-sm form-control-color" id="pcolor" name="color" value="<?= h($pf['color']) ?>">
            </div>
          </div>
          <div class="row g-2 mb-2">
            <div class="col-6">
              <label class="form-label small">Od</label>
              <input type="date" class="form-control form-control-sm" name="valid_from" value="<?= h($pf['valid_from'] ?? '') ?>">
            </div>
            <div class="col-6">
              <label class="form-label small">Do</label>
              <input type="date" class="form-control form-control-sm" name="valid_to" value="<?= h($pf['valid_to'] ?? '') ?>">
            </div>
          </div>
          <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" role="switch" name="is_active" id="pactive" value="1" <?= !empty($pf['is_active'])?'checked':'' ?>>
            <label class="form-check-label small" for="pactive">Pula aktywna</label>
          </div>
          <div class="d-flex gap-2">
            <button type="submit" class="btn btn-sm btn-primary"><?= $edit_pool?'Zapisz':'Utwórz pulę' ?></button>
            <?php if ($edit_pool): ?><a href="zetony.php?tab=pule" class="btn btn-sm btn-outline-secondary">Anuluj</a><?php endif; ?>
          </div>
        </form>
      </div>
    </div>
    <?php endif; ?>

    <!-- Masowe przyznanie żetonów -->
    <?php if ($can_write && $pools): ?>
    <div class="card border-0 shadow-sm">
      <div class="card-header fw-semibold"><i class="bi bi-send me-2" aria-hidden="true"></i>Przydziel żetony</div>
      <div class="card-body">
        <form method="post" id="bulk-grant-form">
          <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="_op"   value="pool_grant_bulk">
          <div class="mb-2">
            <label class="form-label small fw-semibold" for="bg-pool">Pula <span class="text-danger">*</span></label>
            <select class="form-select form-select-sm" id="bg-pool" name="pool_id" required>
              <option value="">— wybierz —</option>
              <?php foreach ($pools as $p): if (empty($p['is_active'])) continue; ?>
              <option value="<?= (int)$p['id'] ?>" <?= $sel_pool===(int)$p['id']?'selected':'' ?>>
                <?= h($p['name']) ?><?= $p['period_key']?' ['.$p['period_key'].']':'' ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-2">
            <label class="form-label small fw-semibold">Odbiorcy</label>
            <div class="d-flex flex-column gap-1">
              <div class="form-check">
                <input class="form-check-input" type="radio" name="grant_mode" id="gm-course" value="course" <?= !$sel_pool?'checked':'' ?>>
                <label class="form-check-label small" for="gm-course">Wszyscy w kursie</label>
              </div>
              <div class="form-check">
                <input class="form-check-input" type="radio" name="grant_mode" id="gm-all" value="all_active">
                <label class="form-check-label small" for="gm-all">Wszyscy aktywni kursanci</label>
              </div>
              <div class="form-check">
                <input class="form-check-input" type="radio" name="grant_mode" id="gm-custom" value="custom">
                <label class="form-check-label small" for="gm-custom">Wybrani (ID po przecinku)</label>
              </div>
            </div>
          </div>
          <div id="bg-course-wrap" class="mb-2">
            <label class="form-label small" for="bg-course">Kurs</label>
            <select class="form-select form-select-sm" id="bg-course" name="course_id">
              <option value="">— wybierz —</option>
              <?php foreach ($courses as $c): ?>
              <option value="<?= (int)$c['id'] ?>"><?= h($c['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div id="bg-custom-wrap" class="mb-2 d-none">
            <label class="form-label small" for="bg-custom-ids">ID kursantów</label>
            <input type="text" class="form-control form-control-sm" id="bg-custom-ids" name="client_ids" placeholder="np. 12,45,78">
          </div>
          <div class="mb-2">
            <label class="form-label small fw-semibold" for="bg-amount">Żetonów na osobę <span class="text-danger">*</span></label>
            <input type="number" class="form-control form-control-sm" id="bg-amount" name="amount" min="1" max="9999" value="<?= $pool_view ? (int)$pool_view['default_grant'] : 20 ?>" required>
          </div>
          <div class="mb-3">
            <label class="form-label small" for="bg-reason">Powód / komentarz</label>
            <input type="text" class="form-control form-control-sm" id="bg-reason" name="reason" placeholder="np. przydział semestralny">
          </div>
          <button type="submit" class="btn btn-sm btn-success w-100 fw-semibold"><i class="bi bi-send me-1" aria-hidden="true"></i>Przydziel żetony</button>
        </form>
      </div>
    </div>
    <script>
    (function(){
      var radios = document.querySelectorAll('[name="grant_mode"]');
      var courseWrap = document.getElementById('bg-course-wrap');
      var customWrap = document.getElementById('bg-custom-wrap');
      function syncMode() {
        var v = document.querySelector('[name="grant_mode"]:checked')?.value;
        courseWrap.classList.toggle('d-none', v !== 'course');
        customWrap.classList.toggle('d-none', v !== 'custom');
      }
      radios.forEach(r => r.addEventListener('change', syncMode));
      syncMode();
    })();
    </script>
    <?php endif; ?>

  </div><!-- /prawa col -->
</div><!-- /row -->

<?php /* ═══════════════════ TAB: PORTFELE OGÓLNE ═══════════════════════ */ ?>
<?php elseif ($tab === 'portfele'): ?>

<!-- Statystyki -->
<div class="row g-3 mb-4">
  <div class="col-6 col-sm-3">
    <div class="card border-0 bg-body-tertiary text-center py-3">
      <div class="fs-3 fw-bold text-primary"><?= (int)($stats['cnt'] ?? 0) ?></div>
      <div class="small text-muted">Portfele</div>
    </div>
  </div>
  <div class="col-6 col-sm-3">
    <div class="card border-0 bg-warning bg-opacity-10 text-center py-3">
      <div class="fs-3 fw-bold text-warning"><?= (int)($stats['total_balance'] ?? 0) ?></div>
      <div class="small text-muted">Dostępne</div>
    </div>
  </div>
  <div class="col-6 col-sm-3">
    <div class="card border-0 bg-success bg-opacity-10 text-center py-3">
      <div class="fs-3 fw-bold text-success"><?= (int)($stats['total_granted'] ?? 0) ?></div>
      <div class="small text-muted">Przyznane łącznie</div>
    </div>
  </div>
  <div class="col-6 col-sm-3">
    <div class="card border-0 bg-body-tertiary text-center py-3">
      <div class="fs-3 fw-bold text-secondary"><?= (int)($stats['total_spent'] ?? 0) ?></div>
      <div class="small text-muted">Wydane łącznie</div>
    </div>
  </div>
</div>

<div class="alert alert-info small mb-3">
  <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
  Portfele ogólne to stary system (jeden portfel bez kategorii). Zalecane: użyj zakładki <a href="zetony.php?tab=pule">Pule</a> dla nowych przydziałów.
</div>

<form method="get" action="zetony.php" class="mb-3 d-flex gap-2" style="max-width:420px">
  <input type="hidden" name="tab" value="portfele">
  <input class="form-control form-control-sm" type="search" name="q" placeholder="Szukaj po nazwisku lub e-mail…" value="<?= h($q_search) ?>">
  <button class="btn btn-sm btn-outline-secondary" type="submit"><i class="bi bi-search" aria-hidden="true"></i></button>
  <?php if ($q_search): ?><a href="zetony.php?tab=portfele" class="btn btn-sm btn-outline-danger"><i class="bi bi-x-lg" aria-hidden="true"></i></a><?php endif; ?>
</form>

<?php if (!$wallets): ?>
<div class="alert alert-info">Brak portfeli ogólnych.</div>
<?php else: ?>
<div class="card border-0 shadow-sm">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0 small">
      <thead class="table-light"><tr>
        <th>Kursant</th>
        <th class="text-end">Dostępne</th>
        <th class="text-end">Przyznane</th>
        <th class="text-end">Wydane</th>
        <th class="text-end">Akcje</th>
      </tr></thead>
      <tbody>
      <?php foreach ($wallets as $w): ?>
      <tr>
        <td>
          <div class="fw-semibold"><?= h($w['client_name']) ?></div>
          <?php if ($w['client_email']): ?><div class="text-muted" style="font-size:.72rem"><?= h($w['client_email']) ?></div><?php endif; ?>
        </td>
        <td class="text-end fw-bold <?= (int)$w['balance']>0?'text-warning':'text-muted' ?>"><?= (int)$w['balance'] ?></td>
        <td class="text-end text-success"><?= (int)$w['total_granted'] ?></td>
        <td class="text-end text-muted"><?= (int)$w['total_spent'] ?></td>
        <td class="text-end">
          <?php if ($can_write): ?>
          <button class="btn btn-sm btn-outline-warning py-0 px-2"
                  data-bs-toggle="modal" data-bs-target="#modalGrant"
                  data-cid="<?= (int)$w['client_id'] ?>"
                  data-name="<?= h($w['client_name']) ?>">
            <i class="bi bi-plus-circle" aria-hidden="true"></i>
          </button>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($can_write): ?>
<div class="modal fade" id="modalGrant" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-sm">
    <form method="post" action="zetony.php?tab=portfele">
      <input type="hidden" name="_csrf"     value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="_op"       value="grant">
      <input type="hidden" name="client_id" id="grantClientId" value="">
      <div class="modal-content">
        <div class="modal-header"><h5 class="modal-title">Doładuj portfel</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <p class="small text-muted mb-2" id="grantClientName"></p>
          <div class="mb-2">
            <label class="form-label small fw-semibold">Żetonów</label>
            <input type="number" class="form-control" name="amount" min="1" max="9999" value="10" required>
          </div>
          <div class="mb-0">
            <label class="form-label small fw-semibold">Powód</label>
            <input type="text" class="form-control" name="note" placeholder="opcjonalnie">
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="btn btn-sm btn-warning fw-semibold"><i class="bi bi-plus-circle me-1" aria-hidden="true"></i>Dodaj</button>
        </div>
      </div>
    </form>
  </div>
</div>
<script>
document.getElementById('modalGrant')?.addEventListener('show.bs.modal', function(e) {
  const btn = e.relatedTarget;
  this.querySelector('#grantClientId').value = btn.dataset.cid || '';
  this.querySelector('#grantClientName').textContent = btn.dataset.name || '';
});
</script>
<?php endif; ?>
<?php endif; ?>

<?php /* ═══════════════════ TAB: CENNIK ════════════════════════════════ */ ?>
<?php elseif ($tab === 'cennik'): ?>

<div class="row g-4">
  <div class="col-lg-8">
    <div class="card border-0 shadow-sm">
      <div class="card-header fw-semibold d-flex align-items-center gap-2">
        <i class="bi bi-tags me-1" aria-hidden="true"></i>Reguły cennika
        <span class="badge bg-secondary"><?= count($prices) ?></span>
      </div>
      <?php if (!$prices): ?>
      <div class="card-body text-muted small">Brak reguł. Domyślna cena to <strong>1 żeton/lekcję</strong>.</div>
      <?php else: ?>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 small">
          <thead class="table-light"><tr>
            <th>Typ</th><th class="text-center">Żetonów/lekcję</th><th>Ważna</th>
            <?php if ($can_write): ?><th class="text-end">Akcje</th><?php endif; ?>
          </tr></thead>
          <tbody>
          <?php foreach ($prices as $p):
            if     (!empty($p['course_id']))  { $badge='bg-info text-dark'; $tag='kurs';   $label='Kurs #'.(int)$p['course_id']; }
            elseif (!empty($p['mentor_id']))  { $badge='bg-secondary';     $tag='mentor'; $label='Mentor #'.(int)$p['mentor_id']; }
            elseif (!empty($p['path_id']))    { $badge='bg-primary';       $tag='ścieżka';$label='Ścieżka #'.(int)$p['path_id'].($p['level']?', poz.'.h($p['level']):''); }
            else                              { $badge='bg-dark';          $tag='domyślna';$label='Domyślna (wszystkie)'; }
            if (!empty($p['course_id'])) { $c=db_one("SELECT name FROM k30_ti_courses WHERE id=?",[(int)$p['course_id']]); if($c)$label=h($c['name']); }
            if (!empty($p['path_id']))   { $pt=db_one("SELECT name FROM k30_pl_tech_paths WHERE id=?",[(int)$p['path_id']]); if($pt)$label=h($pt['name']).($p['level']?', poz.'.h($p['level']):''); }
            if (!empty($p['mentor_id'])){ $u=db_one("SELECT name FROM users WHERE id=?",[(int)$p['mentor_id']]); if($u)$label=h($u['name']); }
          ?>
          <tr>
            <td><span class="badge <?= $badge ?> fw-normal me-1"><?= $tag ?></span><?= $label ?></td>
            <td class="text-center fw-bold text-warning"><?= (int)$p['tokens_required'] ?></td>
            <td class="text-muted">
              <?= $p['valid_from']?'od '.h($p['valid_from']):'' ?>
              <?= $p['valid_to']?' do '.h($p['valid_to']):'' ?>
              <?= (!$p['valid_from']&&!$p['valid_to'])?'zawsze':'' ?>
            </td>
            <?php if ($can_write): ?>
            <td class="text-end">
              <form method="post" class="d-inline" onsubmit="return confirm('Usunąć regułę?')">
                <input type="hidden" name="_csrf"    value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="_op"      value="price_delete">
                <input type="hidden" name="price_id" value="<?= (int)$p['id'] ?>">
                <button class="btn btn-sm btn-outline-danger py-0 px-2"><i class="bi bi-trash" aria-hidden="true"></i></button>
              </form>
            </td>
            <?php endif; ?>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
    <div class="card border-0 bg-body-tertiary mt-3">
      <div class="card-body py-2 small text-muted">
        <strong>Priorytet:</strong> Kurs &gt; Mentor &gt; Ścieżka+poziom &gt; Ścieżka &gt; Domyślna (1 żeton)
      </div>
    </div>
  </div>

  <?php if ($can_write): ?>
  <div class="col-lg-4">
    <div class="card border-0 shadow-sm">
      <div class="card-header fw-semibold"><i class="bi bi-plus-circle me-1" aria-hidden="true"></i>Nowa reguła</div>
      <div class="card-body">
        <form method="post">
          <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="_op"   value="price_save">
          <div class="mb-2">
            <label class="form-label small fw-semibold">Typ reguły</label>
            <select class="form-select form-select-sm" id="priceType" name="_price_type" onchange="togglePriceFields()">
              <option value="course">Per kurs</option>
              <option value="path">Per ścieżka tech.</option>
              <option value="mentor">Per mentor</option>
            </select>
          </div>
          <div id="pfCourse" class="mb-2">
            <label class="form-label small fw-semibold">Kurs</label>
            <select class="form-select form-select-sm" name="course_id">
              <option value="">— wybierz —</option>
              <?php foreach ($courses as $c): ?><option value="<?= (int)$c['id'] ?>"><?= h($c['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div id="pfPath" class="mb-2 d-none">
            <label class="form-label small fw-semibold">Ścieżka</label>
            <select class="form-select form-select-sm" name="path_id">
              <option value="">— dowolna —</option>
              <?php foreach ($tech_paths as $pt): ?><option value="<?= (int)$pt['id'] ?>"><?= h($pt['name']) ?></option><?php endforeach; ?>
            </select>
            <input type="text" class="form-control form-control-sm mt-1" name="level" placeholder="Poziom (np. A1) — opcjonalnie">
          </div>
          <div id="pfMentor" class="mb-2 d-none">
            <label class="form-label small fw-semibold">Mentor</label>
            <select class="form-select form-select-sm" name="mentor_id">
              <option value="">— wybierz —</option>
              <?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>"><?= h($u['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="mb-2">
            <label class="form-label small fw-semibold">Żetonów/lekcję <span class="text-danger">*</span></label>
            <input type="number" class="form-control form-control-sm" name="tokens_required" min="1" max="100" value="1" required>
          </div>
          <div class="row g-2 mb-3">
            <div class="col-6"><label class="form-label small">Od</label><input type="date" class="form-control form-control-sm" name="valid_from"></div>
            <div class="col-6"><label class="form-label small">Do</label><input type="date" class="form-control form-control-sm" name="valid_to"></div>
          </div>
          <button type="submit" class="btn btn-sm btn-success w-100 fw-semibold"><i class="bi bi-plus-circle me-1" aria-hidden="true"></i>Dodaj regułę</button>
        </form>
      </div>
    </div>
  </div>
  <script>
  function togglePriceFields() {
    const t = document.getElementById('priceType').value;
    document.getElementById('pfCourse').classList.toggle('d-none', t !== 'course');
    document.getElementById('pfPath').classList.toggle('d-none',   t !== 'path');
    document.getElementById('pfMentor').classList.toggle('d-none', t !== 'mentor');
  }
  </script>
  <?php endif; ?>
</div>

<?php endif; ?>
</div>
<?php require_once dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
