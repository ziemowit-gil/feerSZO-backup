<?php
/**
 * karty30/ti/zetony.php — Zarządzanie żetonami kursantów.
 * Portfele: stan, historia, doładowanie. Cennik: reguły per kurs/ścieżka/mentor.
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

$PAGE_TITLE = 'Żetony — SZO Planner';
$can_write  = can_write('karty30') || is_admin();

/* ── POST handlers ─────────────────────────────────────────────────────────── */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!$can_write) { http_response_code(403); die('Brak uprawnień.'); }

    $op = $_POST['_op'] ?? '';

    // Doładowanie portfela
    if ($op === 'grant') {
        $client_id = (int)($_POST['client_id'] ?? 0);
        $amount    = (int)($_POST['amount']    ?? 0);
        $note      = trim($_POST['note'] ?? '');
        if ($client_id > 0 && $amount > 0) {
            try {
                pl_tokens_grant($client_id, $amount, $note ?: 'doładowanie_admin', current_user()['id'] ?? null);
                flash_set('success', "Dodano $amount żetonów do portfela kursanta.");
            } catch (\Throwable $e) {
                flash_set('danger', 'Błąd: ' . h($e->getMessage()));
            }
        } else {
            flash_set('danger', 'Nieprawidłowe dane doładowania.');
        }
        header('Location: zetony.php'); exit;
    }

    // Zapis reguły cennika
    if ($op === 'price_save') {
        $id = (int)($_POST['price_id'] ?? 0) ?: null;
        pl_price_save($_POST, $id);
        flash_set('success', 'Reguła cennika zapisana.');
        header('Location: zetony.php?tab=cennik'); exit;
    }

    // Usunięcie reguły cennika
    if ($op === 'price_delete') {
        $id = (int)($_POST['price_id'] ?? 0);
        if ($id) db_exec("DELETE FROM k30_pl_token_prices WHERE id=?", [$id]);
        flash_set('success', 'Reguła usunięta.');
        header('Location: zetony.php?tab=cennik'); exit;
    }
}

/* ── Dane ──────────────────────────────────────────────────────────────────── */

$tab = $_GET['tab'] ?? 'portfele';

// Statystyki ogólne
$stats = db_one("SELECT
    COUNT(*) AS cnt,
    SUM(balance)       AS total_balance,
    SUM(balance_hold)  AS total_hold,
    SUM(total_granted) AS total_granted,
    SUM(total_spent)   AS total_spent
FROM k30_pl_token_wallets");

// Portfele kursantów (z nazwą klienta)
$q_search = trim($_GET['q'] ?? '');
$wallets  = db_all(
    "SELECT w.*, c.name AS client_name, c.email AS client_email
     FROM k30_pl_token_wallets w
     JOIN k30_clients c ON c.id = w.client_id
     " . ($q_search ? "WHERE c.name LIKE ? OR c.email LIKE ?" : "") . "
     ORDER BY w.balance DESC, c.name",
    $q_search ? ["%$q_search%", "%$q_search%"] : []
);

// Historia portfela (parametr ?history_wid=)
$history_wid = (int)($_GET['history_wid'] ?? 0);
$history = $history_wid ? pl_wallet_transactions($history_wid, 50) : [];

// Cennik
$prices = pl_prices_list();

// Ścieżki technologiczne (do formularza cennika)
$tech_paths = db_all("SELECT id, name FROM k30_pl_tech_paths WHERE is_active=1 ORDER BY name", []);

// Kursy (do formularza cennika)
$courses = db_all("SELECT id, title FROM k30_ti_courses WHERE status != 'cancelled' ORDER BY title", []);

// Użytkownicy (do formularza cennika — mentor)
$users = db_all("SELECT id, name FROM users WHERE active=1 ORDER BY name", []);

/* ── Widok ─────────────────────────────────────────────────────────────────── */
require_once dirname(dirname(__DIR__)) . '/includes/header.php';
?>
<div class="container-fluid px-4 py-3">

  <!-- Nagłówek -->
  <div class="d-flex align-items-center gap-3 mb-3 flex-wrap">
    <div>
      <h4 class="fw-bold mb-0"><i class="bi bi-coin me-2 text-warning"></i>Żetony SZO</h4>
      <p class="text-muted small mb-0">Portfele kursantów i cennik zajęć w SZO Planner</p>
    </div>
    <div class="ms-auto d-flex gap-2">
      <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Kursy TI</a>
    </div>
  </div>

  <?= flash_html() ?>

  <!-- Statystyki -->
  <div class="row g-3 mb-4">
    <div class="col-6 col-sm-3">
      <div class="card border-0 bg-body-tertiary text-center py-3 px-2 h-100">
        <div class="fs-2 fw-bold text-primary"><?= (int)($stats['cnt'] ?? 0) ?></div>
        <div class="small text-muted">Portfele</div>
      </div>
    </div>
    <div class="col-6 col-sm-3">
      <div class="card border-0 bg-warning bg-opacity-10 text-center py-3 px-2 h-100">
        <div class="fs-2 fw-bold text-warning"><?= (int)($stats['total_balance'] ?? 0) ?></div>
        <div class="small text-muted">Dostępne</div>
      </div>
    </div>
    <div class="col-6 col-sm-3">
      <div class="card border-0 bg-success bg-opacity-10 text-center py-3 px-2 h-100">
        <div class="fs-2 fw-bold text-success"><?= (int)($stats['total_granted'] ?? 0) ?></div>
        <div class="small text-muted">Przyznane łącznie</div>
      </div>
    </div>
    <div class="col-6 col-sm-3">
      <div class="card border-0 bg-body-tertiary text-center py-3 px-2 h-100">
        <div class="fs-2 fw-bold text-secondary"><?= (int)($stats['total_spent'] ?? 0) ?></div>
        <div class="small text-muted">Wydane łącznie</div>
      </div>
    </div>
  </div>

  <!-- Zakładki -->
  <ul class="nav nav-tabs mb-3">
    <li class="nav-item">
      <a class="nav-link <?= $tab === 'portfele' ? 'active' : '' ?>" href="zetony.php?tab=portfele">
        <i class="bi bi-wallet2 me-1"></i>Portfele
        <span class="badge bg-secondary ms-1"><?= count($wallets) ?></span>
      </a>
    </li>
    <li class="nav-item">
      <a class="nav-link <?= $tab === 'cennik' ? 'active' : '' ?>" href="zetony.php?tab=cennik">
        <i class="bi bi-tags me-1"></i>Cennik
        <span class="badge bg-secondary ms-1"><?= count($prices) ?></span>
      </a>
    </li>
  </ul>

  <!-- ══ TAB: PORTFELE ════════════════════════════════════════════════════ -->
  <?php if ($tab === 'portfele'): ?>

  <!-- Szukaj -->
  <form method="get" action="zetony.php" class="mb-3 d-flex gap-2" style="max-width:420px">
    <input type="hidden" name="tab" value="portfele">
    <input class="form-control form-control-sm" type="search" name="q" placeholder="Szukaj po nazwisku lub e-mail…" value="<?= h($q_search) ?>">
    <button class="btn btn-sm btn-outline-secondary" type="submit"><i class="bi bi-search"></i></button>
    <?php if ($q_search): ?><a href="zetony.php?tab=portfele" class="btn btn-sm btn-outline-danger"><i class="bi bi-x-lg"></i></a><?php endif; ?>
  </form>

  <?php if (!$wallets): ?>
  <div class="alert alert-info">Brak portfeli — żaden kursant nie ma jeszcze żetonów.</div>
  <?php else: ?>
  <div class="card border-0 shadow-sm">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0 small">
        <thead class="table-light">
          <tr>
            <th scope="col">Kursant</th>
            <th scope="col" class="text-end">Dostępne</th>
            <th scope="col" class="text-end">Zarezerwowane</th>
            <th scope="col" class="text-end">Przyznane</th>
            <th scope="col" class="text-end">Wydane</th>
            <th scope="col" class="text-end">Akcje</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($wallets as $w): ?>
          <tr>
            <td>
              <div class="fw-semibold"><?= h($w['client_name']) ?></div>
              <?php if ($w['client_email']): ?><div class="text-muted" style="font-size:.75rem"><?= h($w['client_email']) ?></div><?php endif; ?>
            </td>
            <td class="text-end fw-bold <?= (int)$w['balance'] > 0 ? 'text-warning' : 'text-muted' ?>">
              <?= (int)$w['balance'] ?>
            </td>
            <td class="text-end text-secondary"><?= (int)$w['balance_hold'] ?: '—' ?></td>
            <td class="text-end text-success"><?= (int)$w['total_granted'] ?></td>
            <td class="text-end text-secondary"><?= (int)$w['total_spent'] ?></td>
            <td class="text-end">
              <div class="d-flex gap-1 justify-content-end">
                <a href="zetony.php?tab=portfele&history_wid=<?= (int)$w['id'] ?>#hist-<?= (int)$w['id'] ?>"
                   class="btn btn-sm btn-outline-secondary py-0 px-2">
                  <i class="bi bi-clock-history"></i>
                </a>
                <?php if ($can_write): ?>
                <button class="btn btn-sm btn-outline-warning py-0 px-2"
                        data-bs-toggle="modal"
                        data-bs-target="#modalGrant"
                        data-cid="<?= (int)$w['client_id'] ?>"
                        data-name="<?= h($w['client_name']) ?>">
                  <i class="bi bi-plus-circle"></i>
                </button>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <!-- Historia (jeśli załadowana dla tego portfela) -->
          <?php if ($history_wid === (int)$w['id'] && $history): ?>
          <tr id="hist-<?= (int)$w['id'] ?>" class="bg-light">
            <td colspan="6" class="px-4 py-3">
              <div class="fw-semibold small text-uppercase text-secondary mb-2">
                <i class="bi bi-clock-history me-1"></i>Historia transakcji — ostatnie <?= count($history) ?>
              </div>
              <div class="table-responsive">
                <table class="table table-sm mb-0" style="font-size:.8rem">
                  <thead><tr><th>Data</th><th>Typ</th><th class="text-end">Kwota</th><th>Powód</th></tr></thead>
                  <tbody>
                  <?php foreach ($history as $tx):
                    $dir_map = ['credit'=>'text-success','debit'=>'text-danger','hold'=>'text-warning','unhold'=>'text-secondary'];
                    $dir_icon = ['credit'=>'↑','debit'=>'↓','hold'=>'⏸','unhold'=>'▶'];
                    $cls = $dir_map[$tx['direction']] ?? '';
                    $ico = $dir_icon[$tx['direction']] ?? '?';
                  ?>
                  <tr>
                    <td class="text-muted"><?= h(substr($tx['created_at'],0,16)) ?></td>
                    <td><span class="<?= $cls ?>"><?= $ico ?> <?= h($tx['direction']) ?></span></td>
                    <td class="text-end <?= $cls ?>"><?= (int)$tx['amount'] ?></td>
                    <td><?= h($tx['reason']) ?><?= $tx['ref_type'] ? " <span class='text-muted'>($tx[ref_type])</span>" : '' ?></td>
                  </tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </td>
          </tr>
          <?php endif; ?>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <!-- Modal: doładowanie -->
  <?php if ($can_write): ?>
  <div class="modal fade" id="modalGrant" tabindex="-1" aria-labelledby="modalGrantLabel" aria-hidden="true">
    <div class="modal-dialog modal-sm">
      <form method="post" action="zetony.php">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="_op" value="grant">
        <input type="hidden" name="client_id" id="grantClientId" value="">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="modalGrantLabel"><i class="bi bi-coin me-2 text-warning"></i>Doładuj portfel</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <p class="small text-muted mb-3" id="grantClientName"></p>
            <div class="mb-3">
              <label class="form-label fw-semibold small">Liczba żetonów</label>
              <input type="number" class="form-control" name="amount" min="1" max="9999" value="10" required>
            </div>
            <div class="mb-2">
              <label class="form-label fw-semibold small">Powód <span class="text-muted">(opcjonalny)</span></label>
              <input type="text" class="form-control" name="note" placeholder="np. opłata za miesiąc">
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
            <button type="submit" class="btn btn-sm btn-warning fw-semibold">
              <i class="bi bi-plus-circle me-1"></i>Dodaj żetony
            </button>
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

  <!-- ══ TAB: CENNIK ══════════════════════════════════════════════════════ -->
  <?php elseif ($tab === 'cennik'): ?>

  <div class="row g-4">

    <!-- Istniejące reguły -->
    <div class="col-lg-8">
      <div class="card border-0 shadow-sm">
        <div class="card-header fw-semibold d-flex align-items-center gap-2">
          <i class="bi bi-tags me-1 text-primary"></i>Reguły cennika
          <span class="badge bg-secondary ms-1"><?= count($prices) ?></span>
        </div>
        <?php if (!$prices): ?>
        <div class="card-body text-muted small">Brak reguł. Domyślna cena to <strong>1 żeton/lekcję</strong>.</div>
        <?php else: ?>
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0 small">
            <thead class="table-light">
              <tr>
                <th scope="col">Typ reguły</th>
                <th scope="col" class="text-center">Żetony/lekcję</th>
                <th scope="col">Obowiązuje</th>
                <?php if ($can_write): ?><th scope="col" class="text-end">Akcje</th><?php endif; ?>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($prices as $p):
              if     (!empty($p['course_id']))  { $type_label = 'Kurs #' . (int)$p['course_id']; $badge = 'bg-info text-dark'; }
              elseif (!empty($p['mentor_id']))  { $type_label = 'Mentor #' . (int)$p['mentor_id']; $badge = 'bg-secondary'; }
              elseif (!empty($p['path_id']))    { $level_s = $p['level'] ? ', poz. ' . h($p['level']) : ''; $type_label = 'Ścieżka #' . (int)$p['path_id'] . $level_s; $badge = 'bg-primary'; }
              else                              { $type_label = 'Domyślna (wszystkie)'; $badge = 'bg-dark'; }

              // Nazwy z bazy
              if (!empty($p['course_id'])) {
                $c = db_one("SELECT title FROM k30_ti_courses WHERE id=?", [(int)$p['course_id']]);
                if ($c) $type_label = h($c['title']);
              }
              if (!empty($p['path_id'])) {
                $pt = db_one("SELECT name FROM k30_pl_tech_paths WHERE id=?", [(int)$p['path_id']]);
                if ($pt) { $type_label = h($pt['name']) . ($p['level'] ? ', poz. '.h($p['level']) : ''); }
              }
              if (!empty($p['mentor_id'])) {
                $u = db_one("SELECT name FROM users WHERE id=?", [(int)$p['mentor_id']]);
                if ($u) $type_label = h($u['name']);
              }
            ?>
            <tr>
              <td>
                <span class="badge <?= $badge ?> me-2 fw-normal"><?= !empty($p['course_id']) ? 'kurs' : (!empty($p['mentor_id']) ? 'mentor' : (!empty($p['path_id']) ? 'ścieżka' : 'domyślna')) ?></span>
                <?= $type_label ?>
              </td>
              <td class="text-center fw-bold text-warning"><?= (int)$p['tokens_required'] ?></td>
              <td class="text-muted">
                <?= $p['valid_from'] ? 'od ' . h($p['valid_from']) : '' ?>
                <?= $p['valid_to']   ? ' do ' . h($p['valid_to'])   : '' ?>
                <?= (!$p['valid_from'] && !$p['valid_to']) ? 'zawsze' : '' ?>
              </td>
              <?php if ($can_write): ?>
              <td class="text-end">
                <form method="post" action="zetony.php" class="d-inline" onsubmit="return confirm('Usunąć tę regułę?')">
                  <input type="hidden" name="_csrf"     value="<?= h(csrf_token()) ?>">
                  <input type="hidden" name="_op"       value="price_delete">
                  <input type="hidden" name="price_id"  value="<?= (int)$p['id'] ?>">
                  <button class="btn btn-sm btn-outline-danger py-0 px-2"><i class="bi bi-trash3"></i></button>
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

      <!-- Legenda priorytetu -->
      <div class="card border-0 bg-body-tertiary mt-3">
        <div class="card-body py-2 px-3 small text-muted">
          <strong>Priorytet:</strong> Kurs &gt; Mentor &gt; Ścieżka+poziom &gt; Ścieżka &gt; Domyślna (1 żeton)
        </div>
      </div>
    </div>

    <!-- Formularz nowej reguły -->
    <?php if ($can_write): ?>
    <div class="col-lg-4">
      <div class="card border-0 shadow-sm">
        <div class="card-header fw-semibold">
          <i class="bi bi-plus-circle me-1 text-success"></i>Nowa reguła
        </div>
        <div class="card-body">
          <form method="post" action="zetony.php">
            <input type="hidden" name="_csrf"     value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="_op"       value="price_save">
            <input type="hidden" name="tab"       value="cennik">

            <div class="mb-3">
              <label class="form-label fw-semibold small">Typ reguły</label>
              <select class="form-select form-select-sm" id="priceType" name="_price_type" onchange="togglePriceFields()">
                <option value="course">Per kurs</option>
                <option value="path">Per ścieżka tech.</option>
                <option value="mentor">Per mentor</option>
              </select>
            </div>

            <!-- Per kurs -->
            <div id="pfCourse" class="mb-3">
              <label class="form-label fw-semibold small">Kurs</label>
              <select class="form-select form-select-sm" name="course_id">
                <option value="">— wybierz —</option>
                <?php foreach ($courses as $c): ?>
                <option value="<?= (int)$c['id'] ?>"><?= h($c['title']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <!-- Per ścieżka -->
            <div id="pfPath" class="mb-3 d-none">
              <label class="form-label fw-semibold small">Ścieżka technologiczna</label>
              <select class="form-select form-select-sm" name="path_id">
                <option value="">— dowolna —</option>
                <?php foreach ($tech_paths as $pt): ?>
                <option value="<?= (int)$pt['id'] ?>"><?= h($pt['name']) ?></option>
                <?php endforeach; ?>
              </select>
              <input type="text" class="form-control form-control-sm mt-2" name="level" placeholder="Poziom (np. A1, B2) — opcjonalnie">
            </div>

            <!-- Per mentor -->
            <div id="pfMentor" class="mb-3 d-none">
              <label class="form-label fw-semibold small">Mentor (prowadzący)</label>
              <select class="form-select form-select-sm" name="mentor_id">
                <option value="">— wybierz —</option>
                <?php foreach ($users as $u): ?>
                <option value="<?= (int)$u['id'] ?>"><?= h($u['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="mb-3">
              <label class="form-label fw-semibold small">Żetony/lekcję <span class="text-danger">*</span></label>
              <input type="number" class="form-control form-control-sm" name="tokens_required" min="1" max="100" value="1" required>
            </div>

            <div class="row g-2 mb-3">
              <div class="col-6">
                <label class="form-label fw-semibold small">Obowiązuje od</label>
                <input type="date" class="form-control form-control-sm" name="valid_from">
              </div>
              <div class="col-6">
                <label class="form-label fw-semibold small">do</label>
                <input type="date" class="form-control form-control-sm" name="valid_to">
              </div>
            </div>

            <button type="submit" class="btn btn-sm btn-success w-100 fw-semibold">
              <i class="bi bi-plus-circle me-1"></i>Dodaj regułę
            </button>
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

  </div><!-- /row -->
  <?php endif; ?>

</div><!-- /container -->
<?php require_once dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
