<?php
// Kanban wyłączony — przekieruj do listy tabelarycznej
require_once dirname(dirname(__DIR__)) . '/config.php';
header('Location: ' . APP_URL . '/contracts/wolontariat/list.php');
exit;
/**
 * contracts/wolontariat/kanban.php — Kanban statusów umów wolontariackich (WYŁĄCZONY)
 *
 * Obsługuje:
 *   GET              — widok kanban z filtrami
 *   POST _action=move — przesuń kartę do innego statusu (drag & drop)
 */

require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/admin_audit.php';

require_role('admin', 'editor');
require_module_enabled('contract_wolontariat', 'Umowy wolontariackie');

$PAGE_TITLE = 'Kanban — Wolontariat';

// ── AJAX: Przesuń kartę ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'move') {
    header('Content-Type: application/json');
    csrf_check();

    $id     = (int)($_POST['id']     ?? 0);
    $status = trim($_POST['status'] ?? '');

    $allowed_statuses = ['projekt', 'podpisana', 'w realizacji', 'zakończona', 'rozwiązana', 'anulowana'];

    if (!$id || !in_array($status, $allowed_statuses, true)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Nieprawidłowe dane.']);
        exit;
    }

    $contract = db_one("SELECT id, numer_umowy, status, imie_nazwisko FROM umowy_wolontariat WHERE id = ?", [$id]);
    if (!$contract) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Umowa nie istnieje.']);
        exit;
    }

    $old_status = $contract['status'];
    if ($old_status === $status) {
        echo json_encode(['ok' => true, 'message' => 'Bez zmian.']);
        exit;
    }

    db()->prepare("UPDATE umowy_wolontariat SET status = ?, updated_at = ? WHERE id = ?")
        ->execute([$status, date('Y-m-d H:i:s'), $id]);

    admin_audit(
        'kanban_move',
        'wolontariat',
        "Zmiana statusu: {$old_status} → {$status}",
        $id,
        $contract['numer_umowy'] . ' ' . $contract['imie_nazwisko']
    );

    echo json_encode(['ok' => true, 'old_status' => $old_status, 'new_status' => $status]);
    exit;
}

// ── Definicja kolumn ──────────────────────────────────────────────────────────
$COLUMNS = [
    'projekt'      => ['label' => 'Projekt',      'color' => '#6366F1', 'bg' => '#EEF2FF', 'icon' => 'bi-file-earmark-text'],
    'podpisana'    => ['label' => 'Podpisana',     'color' => '#2E844A', 'bg' => '#EFF7ED', 'icon' => 'bi-check-circle'],
    'w realizacji' => ['label' => 'W realizacji',  'color' => '#0176D3', 'bg' => '#EEF4FF', 'icon' => 'bi-play-circle'],
    'zakończona'   => ['label' => 'Zakończona',    'color' => '#374151', 'bg' => '#F3F4F6', 'icon' => 'bi-flag'],
    'rozwiązana'   => ['label' => 'Rozwiązana',    'color' => '#D97706', 'bg' => '#FEF3E2', 'icon' => 'bi-x-circle'],
    'anulowana'    => ['label' => 'Anulowana',     'color' => '#DC2626', 'bg' => '#FEF2F2', 'icon' => 'bi-slash-circle'],
];

// ── Filtry ────────────────────────────────────────────────────────────────────
$filter_opiekun = trim($_GET['opiekun'] ?? '');
$filter_projekt = trim($_GET['projekt'] ?? '');

$where_parts  = [];
$where_params = [];

if ($filter_opiekun) {
    $where_parts[]  = "opiekun LIKE ?";
    $where_params[] = "%{$filter_opiekun}%";
}
if ($filter_projekt) {
    $where_parts[]  = "projekt_program LIKE ?";
    $where_params[] = "%{$filter_projekt}%";
}

$where_sql = $where_parts ? ('AND ' . implode(' AND ', $where_parts)) : '';

// ── Ładuj karty dla każdej kolumny ───────────────────────────────────────────
$board = [];
foreach (array_keys($COLUMNS) as $status_key) {
    $params   = array_merge([$status_key], $where_params);
    $board[$status_key] = db_all(
        "SELECT id, numer_umowy, imie_nazwisko, data_zakonczenia, opiekun, guardian_initials, projekt_program
         FROM umowy_wolontariat
         WHERE status = ? {$where_sql}
         ORDER BY created_at DESC
         LIMIT 50",
        $params
    );
}

// ── Listy do filtrów ──────────────────────────────────────────────────────────
$opiekunowie = db_all(
    "SELECT DISTINCT opiekun FROM umowy_wolontariat
     WHERE opiekun IS NOT NULL AND opiekun != ''
     ORDER BY opiekun"
);
$projekty = db_all(
    "SELECT DISTINCT projekt_program FROM umowy_wolontariat
     WHERE projekt_program IS NOT NULL AND projekt_program != ''
     ORDER BY projekt_program"
);

// Pomocnicza: inicjały z imienia i nazwiska opiekuna
function opiekun_initials(?string $opiekun, ?string $guardian_initials = null): string {
    if ($guardian_initials) return mb_strtoupper($guardian_initials);
    if (!$opiekun) return '?';
    $parts = preg_split('/\s+/', trim($opiekun));
    $initials = '';
    foreach ($parts as $p) {
        if ($p !== '') $initials .= mb_strtoupper(mb_substr($p, 0, 1));
    }
    return mb_substr($initials, 0, 3) ?: '?';
}

include dirname(dirname(__DIR__)) . '/includes/header.php';
?>

<style>
/* ── Kanban layout ──────────────────────────────────────────────────────────── */
.kb-board {
  display: flex;
  gap: 1rem;
  overflow-x: auto;
  padding-bottom: 1rem;
  align-items: flex-start;
}

.kb-column {
  flex: 0 0 260px;
  min-width: 220px;
  max-width: 300px;
  background: #F9FAFB;
  border-radius: 12px;
  border: 1px solid #E5E7EB;
  display: flex;
  flex-direction: column;
}

.kb-col-header {
  padding: .7rem 1rem;
  border-radius: 12px 12px 0 0;
  display: flex;
  align-items: center;
  gap: .5rem;
  font-weight: 700;
  font-size: .82rem;
  text-transform: uppercase;
  letter-spacing: .05em;
  border-bottom: 2px solid transparent;
}

.kb-col-body {
  padding: .5rem;
  min-height: 120px;
  flex: 1;
  overflow-y: auto;
  max-height: calc(100vh - 260px);
}

.kb-col-body.drag-over {
  background: rgba(0,0,0,.04);
  border-radius: 0 0 8px 8px;
  outline: 2px dashed #6366F1;
  outline-offset: -4px;
}

/* Karty */
.kb-card {
  background: #fff;
  border-radius: 8px;
  border: 1px solid #E5E7EB;
  padding: .65rem .8rem;
  margin-bottom: .4rem;
  cursor: grab;
  transition: box-shadow .15s, transform .1s;
  border-left: 4px solid transparent;
  position: relative;
  text-decoration: none;
  display: block;
  color: inherit;
}

.kb-card:hover {
  box-shadow: 0 4px 12px rgba(0,0,0,.1);
  transform: translateY(-1px);
  color: inherit;
  text-decoration: none;
}

.kb-card.dragging {
  opacity: .5;
  cursor: grabbing;
}

.kb-card-num {
  font-family: monospace;
  font-size: .75rem;
  font-weight: 700;
  color: #6B7280;
  margin-bottom: 2px;
}

.kb-card-name {
  font-weight: 600;
  font-size: .85rem;
  color: #111827;
  line-height: 1.3;
  margin-bottom: 4px;
}

.kb-card-meta {
  font-size: .72rem;
  color: #9CA3AF;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: .3rem;
}

.kb-card-date {
  display: flex;
  align-items: center;
  gap: 3px;
}

.kb-card-date.expired {
  color: #DC2626;
  font-weight: 600;
}

.kb-card-date.soon {
  color: #D97706;
  font-weight: 600;
}

.kb-avatar {
  width: 22px;
  height: 22px;
  border-radius: 50%;
  background: #E5E7EB;
  color: #374151;
  font-size: .6rem;
  font-weight: 700;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.kb-badge {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 22px;
  height: 22px;
  padding: 0 .4rem;
  border-radius: 11px;
  font-size: .7rem;
  font-weight: 700;
  background: rgba(0,0,0,.1);
  margin-left: auto;
}

/* Filter bar */
.kb-filter-bar {
  background: #fff;
  border: 1px solid #E5E7EB;
  border-radius: 10px;
  padding: .6rem 1rem;
  display: flex;
  gap: .75rem;
  align-items: center;
  flex-wrap: wrap;
  margin-bottom: 1rem;
}

/* Loading overlay for move */
.kb-moving {
  pointer-events: none;
  opacity: .6;
}

/* Hidden during JS search */
.kb-card[data-hidden="true"] {
  display: none;
}
</style>

<div class="container-fluid py-3" style="max-width: 100%;">

  <!-- Nagłówek -->
  <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
    <div>
      <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-1 small">
          <li class="breadcrumb-item"><a href="<?= APP_URL ?>/contracts/wolontariat/list.php">Wolontariat</a></li>
          <li class="breadcrumb-item active">Kanban</li>
        </ol>
      </nav>
      <h1 class="h4 mb-0">
        <i class="bi bi-kanban me-2 text-primary"></i>
        Kanban — Porozumienia wolontariackie
      </h1>
    </div>
    <div class="d-flex gap-2">
      <a href="<?= APP_URL ?>/contracts/wolontariat/list.php" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-list-ul me-1"></i>Lista
      </a>
      <a href="<?= APP_URL ?>/contracts/wolontariat/add.php" class="btn btn-primary btn-sm">
        <i class="bi bi-plus-lg me-1"></i>Nowa umowa
      </a>
    </div>
  </div>

  <!-- Filtry -->
  <form method="get" class="kb-filter-bar">
    <div class="d-flex align-items-center gap-1 me-1">
      <i class="bi bi-funnel text-muted"></i>
      <span class="fw-semibold small text-muted">Filtry:</span>
    </div>

    <select name="opiekun" class="form-select form-select-sm" style="width:200px"
            onchange="this.form.submit()">
      <option value="">Wszyscy opiekunowie</option>
      <?php foreach ($opiekunowie as $op): ?>
        <option value="<?= h($op['opiekun']) ?>"
          <?= $filter_opiekun === $op['opiekun'] ? 'selected' : '' ?>>
          <?= h($op['opiekun']) ?>
        </option>
      <?php endforeach ?>
    </select>

    <select name="projekt" class="form-select form-select-sm" style="width:200px"
            onchange="this.form.submit()">
      <option value="">Wszystkie projekty</option>
      <?php foreach ($projekty as $pj): ?>
        <option value="<?= h($pj['projekt_program']) ?>"
          <?= $filter_projekt === $pj['projekt_program'] ? 'selected' : '' ?>>
          <?= h($pj['projekt_program']) ?>
        </option>
      <?php endforeach ?>
    </select>

    <div class="input-group input-group-sm" style="width:220px">
      <span class="input-group-text bg-white border-end-0">
        <i class="bi bi-search text-muted"></i>
      </span>
      <input type="text" id="kb-search" class="form-control border-start-0"
             placeholder="Szukaj po nazwie/numerze…"
             oninput="kbSearch(this.value)"
             autocomplete="off">
    </div>

    <?php if ($filter_opiekun || $filter_projekt): ?>
      <a href="<?= APP_URL ?>/contracts/wolontariat/kanban.php" class="btn btn-outline-danger btn-sm">
        <i class="bi bi-x-circle me-1"></i>Wyczyść
      </a>
    <?php endif ?>
  </form>

  <!-- Tablica Kanban -->
  <div class="kb-board" id="kb-board">
    <?php foreach ($COLUMNS as $status_key => $col): ?>
      <?php
        $cards     = $board[$status_key];
        $count     = count($cards);
        $slug      = preg_replace('/[^a-z0-9]/', '_', strtolower($status_key));
        $today     = date('Y-m-d');
        $soon_date = date('Y-m-d', strtotime('+14 days'));
      ?>
      <div class="kb-column" id="col-<?= h($slug) ?>" data-status="<?= h($status_key) ?>">

        <!-- Nagłówek kolumny -->
        <div class="kb-col-header"
             style="background:<?= h($col['bg']) ?>; border-color:<?= h($col['color']) ?>; color:<?= h($col['color']) ?>">
          <i class="bi <?= h($col['icon']) ?>"></i>
          <span><?= h($col['label']) ?></span>
          <span class="kb-badge" id="badge-<?= h($slug) ?>"><?= $count ?></span>
        </div>

        <!-- Karty -->
        <div class="kb-col-body"
             id="body-<?= h($slug) ?>"
             data-status="<?= h($status_key) ?>"
             ondragover="event.preventDefault(); this.classList.add('drag-over')"
             ondragleave="this.classList.remove('drag-over')"
             ondrop="kbDrop(event, this)">

          <?php if (!$cards): ?>
            <div class="text-center text-muted small py-3" id="empty-<?= h($slug) ?>">
              Brak umów
            </div>
          <?php endif ?>

          <?php foreach ($cards as $c): ?>
            <?php
              $initials    = opiekun_initials($c['opiekun'] ?? null, $c['guardian_initials'] ?? null);
              $dt_zakon    = $c['data_zakonczenia'] ?? '';
              $date_class  = '';
              $date_icon   = 'bi-calendar3';
              if ($dt_zakon) {
                  if ($dt_zakon < $today)         { $date_class = 'expired'; $date_icon = 'bi-calendar-x'; }
                  elseif ($dt_zakon <= $soon_date) { $date_class = 'soon';    $date_icon = 'bi-calendar-warning'; }
              }
            ?>
            <a href="<?= APP_URL ?>/contracts/wolontariat/view.php?id=<?= (int)$c['id'] ?>"
               class="kb-card"
               data-id="<?= (int)$c['id'] ?>"
               data-name="<?= h(strtolower($c['imie_nazwisko'] ?? '')) ?>"
               data-num="<?= h(strtolower($c['numer_umowy'] ?? '')) ?>"
               draggable="true"
               style="border-left-color:<?= h($col['color']) ?>"
               ondragstart="kbDragStart(event, this)"
               ondragend="kbDragEnd(event, this)"
               onclick="kbCardClick(event, this)">

              <div class="kb-card-num"><?= h($c['numer_umowy']) ?></div>
              <div class="kb-card-name"><?= h($c['imie_nazwisko']) ?></div>
              <div class="kb-card-meta">
                <div class="kb-card-date <?= $date_class ?>">
                  <?php if ($dt_zakon): ?>
                    <i class="bi <?= $date_icon ?>" style="font-size:.8rem"></i>
                    <?= date_pl($dt_zakon) ?>
                  <?php else: ?>
                    <span class="text-muted" style="font-style:italic">bezterminowa</span>
                  <?php endif ?>
                </div>
                <?php if ($c['opiekun']): ?>
                  <div class="kb-avatar" title="<?= h($c['opiekun']) ?>"><?= h($initials) ?></div>
                <?php endif ?>
              </div>

            </a>
          <?php endforeach ?>

        </div>
      </div>
    <?php endforeach ?>
  </div>

</div>

<!-- CSRF token dla AJAX -->
<script>
const CSRF_TOKEN  = <?= json_encode(csrf_token()) ?>;
const MOVE_URL    = <?= json_encode(APP_URL . '/contracts/wolontariat/kanban.php') ?>;

// ── Drag & drop state ─────────────────────────────────────────────────────────
let _dragCard   = null;
let _dragOrigin = null;

function kbDragStart(e, el) {
  _dragCard   = el;
  _dragOrigin = el.closest('.kb-col-body');
  e.dataTransfer.effectAllowed = 'move';
  e.dataTransfer.setData('text/plain', el.dataset.id);
  setTimeout(() => el.classList.add('dragging'), 0);
}

function kbDragEnd(e, el) {
  el.classList.remove('dragging');
  document.querySelectorAll('.kb-col-body').forEach(b => b.classList.remove('drag-over'));
  _dragCard   = null;
  _dragOrigin = null;
}

function kbDrop(e, col) {
  e.preventDefault();
  col.classList.remove('drag-over');

  if (!_dragCard) return;
  const newStatus = col.dataset.status;
  const id        = parseInt(_dragCard.dataset.id, 10);

  // Jeśli to samo miejsce — nic
  if (_dragOrigin === col) return;

  // Optymistycznie przesuń kartę w DOM
  const originBody = _dragOrigin;
  col.appendChild(_dragCard);
  _dragCard.style.borderLeftColor = col.closest('.kb-column').querySelector('.kb-col-header').style.color ||
    getComputedStyle(col.closest('.kb-column').querySelector('.kb-col-header')).color;

  // Odśwież liczniki
  updateBadge(originBody);
  updateBadge(col);

  // Ukryj pustą informację jeśli potrzeba
  toggleEmpty(originBody);
  toggleEmpty(col);

  // Wyślij do serwera
  const fd = new FormData();
  fd.append('_action', 'move');
  fd.append('_csrf',   CSRF_TOKEN);
  fd.append('id',      id);
  fd.append('status',  newStatus);

  _dragCard.classList.add('kb-moving');

  fetch(MOVE_URL, { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      _dragCard && _dragCard.classList.remove('kb-moving');
      if (!data.ok) {
        alert('Błąd: ' + (data.error || 'Nieznany błąd'));
        // Cofnij w DOM
        originBody.appendChild(_dragCard);
        updateBadge(originBody);
        updateBadge(col);
        toggleEmpty(originBody);
        toggleEmpty(col);
      }
    })
    .catch(() => {
      _dragCard && _dragCard.classList.remove('kb-moving');
      alert('Błąd połączenia z serwerem.');
    });
}

// Kliknięcie w kartę: przejdź do view.php (tylko jeśli nie było drag)
function kbCardClick(e, el) {
  // Jeśli to było kliknięcie-po-drag: nie nawiguj
  // href już jest ustawiony na <a>, więc przeglądarka sam przejdzie
  // Tę funkcję można rozbudować o blokadę po wykryciu drag
}

// ── Aktualizacja licznika ─────────────────────────────────────────────────────
function updateBadge(colBody) {
  const column = colBody.closest('.kb-column');
  if (!column) return;
  const slug   = column.id.replace('col-', '');
  const badge  = document.getElementById('badge-' + slug);
  if (!badge) return;
  const visible = colBody.querySelectorAll('.kb-card:not([data-hidden="true"])').length;
  badge.textContent = visible;
}

function toggleEmpty(colBody) {
  const column   = colBody.closest('.kb-column');
  if (!column) return;
  const slug     = column.id.replace('col-', '');
  let emptyEl    = document.getElementById('empty-' + slug);
  const hasCards = colBody.querySelectorAll('.kb-card').length > 0;
  if (hasCards) {
    if (emptyEl) emptyEl.style.display = 'none';
  } else {
    if (!emptyEl) {
      emptyEl = document.createElement('div');
      emptyEl.id = 'empty-' + slug;
      emptyEl.className = 'text-center text-muted small py-3';
      emptyEl.textContent = 'Brak umów';
      colBody.appendChild(emptyEl);
    } else {
      emptyEl.style.display = '';
    }
  }
}

// ── Wyszukiwanie JS ───────────────────────────────────────────────────────────
function kbSearch(query) {
  const q = query.toLowerCase().trim();
  document.querySelectorAll('.kb-col-body').forEach(colBody => {
    colBody.querySelectorAll('.kb-card').forEach(card => {
      const match = !q ||
        card.dataset.name.includes(q) ||
        card.dataset.num.includes(q);
      card.dataset.hidden = match ? 'false' : 'true';
    });
    updateBadge(colBody);
  });
}
</script>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
