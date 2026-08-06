<?php
/**
 * ezd/poczta/index.php — Inbox Ogólny EZD.
 *
 * Widok współdzielony dla uprawnionych pracowników (sekretariat, kancelaria):
 * lista wiadomości e-mail z możliwością filtrowania, przypisywania do sprawy/usera
 * i konwertowania wiadomości na nowe sprawy EZD.
 *
 * Wymaga: poczta_enabled + can_read('ezd')
 */

require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd_mail.php';

require_login();
require_module_enabled('ezd_enabled', 'Moduł EZD Wirtualne biurko');
ezd_require_access();

$PAGE_TITLE = 'Poczta EZD';

$svc = new EzdMailService();

// ── Filtry z GET ─────────────────────────────────────────────────────────────
$filters = [
    'status'      => in_array($_GET['status'] ?? '', ['active','archived','spam','sent','unread'], true)
                     ? $_GET['status'] : 'active',
    'sprawa_id'   => (int)($_GET['sprawa_id']   ?? 0) ?: null,
    'assigned_to' => (int)($_GET['assigned_to'] ?? 0) ?: null,
    'mailbox_id'  => (int)($_GET['mailbox_id']  ?? 0) ?: null,
    'q'           => trim($_GET['q'] ?? ''),
    'page'        => max(1, (int)($_GET['page'] ?? 1)),
    'per_page'    => 25,
];

$result = $svc->getInbox($filters);
$rows       = $result['rows'];
$total      = $result['total'];
$pages      = max(1, (int)ceil($total / $filters['per_page']));

// Listy do filtrów
$mailboxes = db_all("SELECT id, mailbox FROM poczta_mailboxes WHERE enabled=1 ORDER BY mailbox");
$users     = db_all("SELECT id, name FROM users WHERE is_active=1 ORDER BY name");

// KPI badge nieprzeczytanych w menu
$unread_count = (int)(db_one(
    "SELECT COUNT(*) AS n FROM crm_communications WHERE direction='in' AND is_read=0 AND inbox_status='active'"
)['n'] ?? 0);

$status_tabs = [
    'active'   => ['Skrzynka odbiorcza',  'bi-inbox',           'primary'],
    'unread'   => ['Nieprzeczytane',      'bi-envelope',        'warning'],
    'sent'     => ['Wysłane',             'bi-send',            'secondary'],
    'archived' => ['Zarchiwizowane',      'bi-archive',         'secondary'],
    'spam'     => ['Spam',               'bi-slash-circle',    'danger'],
];

include dirname(dirname(__DIR__)) . '/includes/header.php';
?>

<div class="container-fluid px-3 px-md-4">

  <!-- Wiersz 1: nagłówek + przycisk ──────────────────────────────────────────── -->
  <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
    <h5 class="mb-0 fw-bold d-flex align-items-center gap-2">
      <i class="bi bi-envelope-fill" style="color:#2563eb"></i>
      Poczta EZD
      <?php if ($unread_count): ?>
      <span class="badge bg-danger rounded-pill" style="font-size:.65rem"><?= $unread_count ?></span>
      <?php endif; ?>
    </h5>
    <div class="d-flex gap-2">
      <a href="<?= APP_URL ?>/ezd/poczta/compose.php" class="btn btn-sm btn-primary">
        <i class="bi bi-pencil-square me-1"></i>Nowa wiadomość
      </a>
      <?php if (is_admin() || can_write('ezd')): ?>
      <a href="<?= APP_URL ?>/poczta/index.php" class="btn btn-sm btn-outline-secondary" title="Konfiguracja skrzynek">
        <i class="bi bi-gear"></i>
      </a>
      <?php endif; ?>
    </div>
  </div>

  <!-- Wiersz 2: zakładki folderów (poziome nav-pills) ───────────────────────── -->
  <div class="d-flex align-items-center gap-1 flex-wrap mb-2">
    <?php foreach ($status_tabs as $key => [$label, $icon, $color]): ?>
    <a href="?status=<?= $key ?><?= $filters['q'] ? '&q='.h(urlencode($filters['q'])) : '' ?>"
       class="btn btn-sm <?= $filters['status'] === $key ? "btn-{$color}" : 'btn-outline-secondary' ?>">
      <i class="bi <?= $icon ?> me-1"></i><?= $label ?>
      <?php if ($key === 'unread' && $unread_count): ?>
      <span class="badge bg-danger rounded-pill ms-1" style="font-size:.6rem"><?= $unread_count ?></span>
      <?php endif; ?>
    </a>
    <?php endforeach; ?>

    <!-- Filtr inline po prawej -->
    <form method="get" class="d-flex gap-1 ms-auto align-items-center flex-wrap">
      <input type="hidden" name="status" value="<?= h($filters['status']) ?>">
      <div class="input-group input-group-sm" style="width:200px">
        <input type="text" name="q" class="form-control form-control-sm" placeholder="Szukaj…" value="<?= h($filters['q']) ?>">
        <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-search"></i></button>
      </div>
      <?php if ($mailboxes): ?>
      <select name="mailbox_id" class="form-select form-select-sm" style="width:auto;max-width:160px" onchange="this.form.submit()" title="Skrzynka">
        <option value="">Wszystkie skrzynki</option>
        <?php foreach ($mailboxes as $mb): ?>
        <option value="<?= $mb['id'] ?>" <?= $filters['mailbox_id'] == $mb['id'] ? 'selected' : '' ?>><?= h($mb['mailbox']) ?></option>
        <?php endforeach; ?>
      </select>
      <?php endif; ?>
      <select name="assigned_to" class="form-select form-select-sm" style="width:auto;max-width:150px" onchange="this.form.submit()" title="Przypisane do">
        <option value="">Wszyscy</option>
        <?php foreach ($users as $u): ?>
        <option value="<?= $u['id'] ?>" <?= $filters['assigned_to'] == $u['id'] ? 'selected' : '' ?>><?= h($u['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <?php if ($filters['q'] || $filters['sprawa_id'] || $filters['assigned_to'] || $filters['mailbox_id']): ?>
      <a href="?status=<?= h($filters['status']) ?>" class="btn btn-sm btn-outline-danger" title="Wyczyść filtry"><i class="bi bi-x-lg"></i></a>
      <?php endif; ?>
    </form>
  </div>

  <!-- Wiersz 3: lista wiadomości (pełna szerokość) ─────────────────────────── -->
  <?php if (empty($rows)): ?>
  <div class="text-center text-muted py-5">
    <i class="bi bi-inbox" style="font-size:2.5rem;opacity:.3"></i>
    <p class="mt-2 mb-0">Brak wiadomości w tej skrzynce.</p>
  </div>
  <?php else: ?>

  <div class="d-flex align-items-center gap-2 mb-2" style="font-size:.82rem">
    <span class="text-muted"><?= number_format($total) ?> wiadomości</span>
    <button class="btn btn-sm btn-outline-secondary ms-auto" id="btn-mark-all-read">
      <i class="bi bi-check2-all me-1"></i>Oznacz wszystkie jako przeczytane
    </button>
  </div>

  <div class="card shadow-sm border-0">
    <div class="list-group list-group-flush" id="inbox-list">
      <?php foreach ($rows as $row):
        $is_unread   = !$row['is_read'] && $row['direction'] === 'in';
        $has_sprawa  = !empty($row['znak_sprawy']);
        $sender_disp = $row['direction'] === 'in'
                       ? ($row['from_name'] ?: $row['from_email'] ?: '—')
                       : ('➜ ' . ($row['to_email'] ?? ($row['from_email'] ?? '—')));
        $sent_dt    = strtotime($row['sent_at'] ?? 'now');
        $today      = date('d.m.Y') === date('d.m.Y', $sent_dt);
        $sent_label = $today ? date('H:i', $sent_dt) : date('d.m.Y', $sent_dt);
      ?>
      <div class="list-group-item list-group-item-action p-0 inbox-item <?= $is_unread ? 'inbox-unread' : '' ?>"
           data-id="<?= (int)$row['id'] ?>" style="cursor:pointer">
        <div class="d-flex align-items-center gap-2 px-3 py-2">

          <!-- Checkbox -->
          <div class="form-check flex-shrink-0" onclick="event.stopPropagation()">
            <input type="checkbox" class="form-check-input inbox-check" value="<?= (int)$row['id'] ?>">
          </div>

          <!-- Kierunek -->
          <i class="bi <?= $row['direction']==='in' ? 'bi-envelope-arrow-down-fill text-primary' : 'bi-envelope-arrow-up-fill text-success' ?> flex-shrink-0"
             style="font-size:1rem;opacity:.8"></i>

          <!-- Nadawca (stała szerokość) -->
          <div class="flex-shrink-0 text-truncate <?= $is_unread ? 'fw-bold' : '' ?>"
               style="width:180px;font-size:.85rem" title="<?= h($sender_disp) ?>">
            <?= h($sender_disp) ?>
          </div>

          <!-- Temat + badges -->
          <div class="flex-grow-1 overflow-hidden">
            <span class="text-truncate d-block <?= $is_unread ? 'fw-semibold' : '' ?>" style="font-size:.85rem">
              <?= h($row['subject'] ?? '(bez tematu)') ?>
            </span>
            <div class="d-flex gap-1 flex-wrap mt-1">
              <?php if ($has_sprawa): ?>
              <a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= (int)$row['ezd_sprawa_id'] ?>"
                 class="badge rounded-pill text-bg-info text-decoration-none" style="font-size:.65rem"
                 onclick="event.stopPropagation()">
                <i class="bi bi-folder2-open me-1"></i><?= h($row['znak_sprawy']) ?>
              </a>
              <?php else: ?>
              <span class="badge rounded-pill text-bg-warning" style="font-size:.65rem">
                <i class="bi bi-question-circle me-1"></i>nieprzypisana
              </span>
              <?php endif; ?>
              <?php if ($row['assigned_user'] ?? ''): ?>
              <span class="badge rounded-pill text-bg-secondary" style="font-size:.65rem">
                <i class="bi bi-person me-1"></i><?= h($row['assigned_user']) ?>
              </span>
              <?php endif; ?>
              <?php if ($row['has_attachments'] ?? 0): ?>
              <span class="badge rounded-pill text-bg-light border" style="font-size:.65rem;color:#6b7280">
                <i class="bi bi-paperclip"></i>
              </span>
              <?php endif; ?>
            </div>
          </div>

          <!-- Data -->
          <span class="flex-shrink-0 text-muted" style="font-size:.78rem;min-width:3rem;text-align:right"><?= $sent_label ?></span>

          <!-- Akcje (hover) -->
          <div class="flex-shrink-0 inbox-actions d-flex gap-1 ms-1" onclick="event.stopPropagation()"
               style="opacity:0;transition:opacity .15s">
            <a href="<?= APP_URL ?>/ezd/poczta/compose.php?reply_to=<?= (int)$row['id'] ?><?= $has_sprawa ? '&sprawa_id='.(int)$row['ezd_sprawa_id'] : '' ?>"
               class="btn btn-sm btn-outline-primary py-0 px-2" title="Odpowiedz">
              <i class="bi bi-reply"></i>
            </a>
            <?php if (!$has_sprawa): ?>
            <button class="btn btn-sm btn-outline-success py-0 px-2 btn-assign-sprawa"
                    data-id="<?= (int)$row['id'] ?>" title="Przypisz do sprawy">
              <i class="bi bi-folder-plus"></i>
            </button>
            <?php endif; ?>
            <button class="btn btn-sm btn-outline-secondary py-0 px-2 btn-assign-user"
                    data-id="<?= (int)$row['id'] ?>" title="Przypisz osobę">
              <i class="bi bi-person-check"></i>
            </button>
            <button class="btn btn-sm btn-outline-secondary py-0 px-2 btn-archive"
                    data-id="<?= (int)$row['id'] ?>" title="Archiwizuj">
              <i class="bi bi-archive"></i>
            </button>
          </div>

        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Paginacja -->
  <?php if ($pages > 1): ?>
  <nav class="mt-3" aria-label="Paginacja poczty">
    <ul class="pagination pagination-sm justify-content-center mb-0">
      <?php for ($p = 1; $p <= $pages; $p++): ?>
      <li class="page-item <?= $p === $filters['page'] ? 'active' : '' ?>">
        <a class="page-link" href="?<?= http_build_query(array_merge($filters, ['page' => $p])) ?>"><?= $p ?></a>
      </li>
      <?php endfor; ?>
    </ul>
  </nav>
  <?php endif; ?>

  <?php endif; ?>
</div><!-- /container -->

<!-- Modal: Przypisz do sprawy ────────────────────────────────────────────────── -->
<div class="modal fade" id="assignSprawaModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title fw-semibold"><i class="bi bi-folder-plus me-1 text-success"></i>Przypisz do sprawy EZD</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="assignSprawaCommId">
        <div class="mb-3">
          <label class="form-label small fw-semibold">Wyszukaj sprawę (znak lub tytuł)</label>
          <input type="text" id="assignSprawaSearch" class="form-control" placeholder="FEER.123.2026 lub fragment tytułu…" autocomplete="off">
          <div id="assignSprawaResults" class="list-group mt-1" style="max-height:200px;overflow-y:auto;display:none"></div>
        </div>
        <div id="assignSprawaSelected" class="alert alert-success py-1 small" style="display:none">
          <i class="bi bi-check-circle me-1"></i><span id="assignSprawaSelectedText"></span>
        </div>
      </div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Anuluj</button>
        <button type="button" class="btn btn-sm btn-success" id="btnAssignSprawaConfirm" disabled>
          <i class="bi bi-link-45deg me-1"></i>Przypisz
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Modal: Przypisz do osoby ─────────────────────────────────────────────────── -->
<div class="modal fade" id="assignUserModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title fw-semibold"><i class="bi bi-person-check me-1 text-primary"></i>Przypisz do osoby</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="assignUserCommId">
        <label class="form-label small fw-semibold">Wybierz pracownika</label>
        <select id="assignUserSelect" class="form-select">
          <option value="">— odpisz —</option>
          <?php foreach ($users as $u): ?>
          <option value="<?= $u['id'] ?>"><?= h($u['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Anuluj</button>
        <button type="button" class="btn btn-sm btn-primary" id="btnAssignUserConfirm">
          <i class="bi bi-person-check me-1"></i>Przypisz
        </button>
      </div>
    </div>
  </div>
</div>

<style>
.inbox-item:hover .inbox-actions { opacity: 1 !important; }
.inbox-item:hover { background: #f8fafc; }
.inbox-item.inbox-unread { background: #eff6ff; border-left: 3px solid #3b82f6; }
.inbox-item.inbox-unread:hover { background: #dbeafe; }
@media (prefers-color-scheme: dark) {
  .inbox-item.inbox-unread { background: #1e3a5f; border-left-color: #60a5fa; }
  .inbox-item.inbox-unread:hover { background: #1e3461; }
  .inbox-item:hover { background: #1e2535; }
}
</style>

<script>
const CSRF = <?= json_encode(csrf_token()) ?>;
const API  = <?= json_encode(APP_URL . '/ezd/poczta/api.php') ?>;

// ── Otwarcie wiadomości (zaznacz jako przeczytaną i przejdź do wątku) ──────────
document.querySelectorAll('.inbox-item').forEach(el => {
  el.addEventListener('click', function() {
    const id = this.dataset.id;
    fetch(API, {method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({_csrf:CSRF, action:'mark_read', id})
    });
    // Usuń oznaczenie nieprzeczytanej
    this.classList.remove('inbox-unread');
    this.querySelector('.fw-bold')?.classList.replace('fw-bold','');
    this.querySelector('.fw-semibold')?.classList.replace('fw-semibold','');

    const threadKey = this.dataset.threadKey;
    if (threadKey) {
      window.location = <?= json_encode(APP_URL . '/ezd/poczta/thread.php?key=') ?> + encodeURIComponent(threadKey);
    } else {
      window.location = <?= json_encode(APP_URL . '/ezd/poczta/thread.php?comm_id=') ?> + id;
    }
  });
});

// ── Przypisz do sprawy ─────────────────────────────────────────────────────────
let _assignSprawaId = null;
document.querySelectorAll('.btn-assign-sprawa').forEach(btn => {
  btn.addEventListener('click', () => {
    document.getElementById('assignSprawaCommId').value = btn.dataset.id;
    _assignSprawaId = null;
    document.getElementById('assignSprawaSelected').style.display = 'none';
    document.getElementById('btnAssignSprawaConfirm').disabled = true;
    document.getElementById('assignSprawaSearch').value = '';
    new bootstrap.Modal('#assignSprawaModal').show();
  });
});

let _sprawaSearchTimer;
document.getElementById('assignSprawaSearch')?.addEventListener('input', function() {
  clearTimeout(_sprawaSearchTimer);
  const q = this.value.trim();
  if (q.length < 2) { document.getElementById('assignSprawaResults').style.display='none'; return; }
  _sprawaSearchTimer = setTimeout(() => {
    fetch(API + '?action=search_sprawa&q=' + encodeURIComponent(q))
      .then(r => r.json())
      .then(data => {
        const ul = document.getElementById('assignSprawaResults');
        ul.innerHTML = '';
        if (!data.rows?.length) {
          ul.innerHTML = '<div class="list-group-item text-muted small">Brak wyników</div>';
        } else {
          data.rows.forEach(s => {
            const a = document.createElement('a');
            a.className = 'list-group-item list-group-item-action small py-1';
            a.href = '#';
            a.innerHTML = `<strong>${s.znak_sprawy}</strong> — ${s.title}`;
            a.addEventListener('click', e => {
              e.preventDefault();
              _assignSprawaId = s.id;
              document.getElementById('assignSprawaSelectedText').textContent = s.znak_sprawy + ' — ' + s.title;
              document.getElementById('assignSprawaSelected').style.display = '';
              document.getElementById('btnAssignSprawaConfirm').disabled = false;
              ul.style.display = 'none';
            });
            ul.appendChild(a);
          });
        }
        ul.style.display = '';
      });
  }, 280);
});

document.getElementById('btnAssignSprawaConfirm')?.addEventListener('click', () => {
  const commId = document.getElementById('assignSprawaCommId').value;
  if (!_assignSprawaId) return;
  fetch(API, {method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({_csrf:CSRF, action:'assign_sprawa', id:commId, sprawa_id:_assignSprawaId})
  }).then(r => r.json()).then(d => {
    if (d.ok) { bootstrap.Modal.getInstance('#assignSprawaModal').hide(); location.reload(); }
    else alert(d.error || 'Błąd');
  });
});

// ── Przypisz do osoby ──────────────────────────────────────────────────────────
document.querySelectorAll('.btn-assign-user').forEach(btn => {
  btn.addEventListener('click', () => {
    document.getElementById('assignUserCommId').value = btn.dataset.id;
    new bootstrap.Modal('#assignUserModal').show();
  });
});

document.getElementById('btnAssignUserConfirm')?.addEventListener('click', () => {
  const commId  = document.getElementById('assignUserCommId').value;
  const userId  = document.getElementById('assignUserSelect').value;
  fetch(API, {method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({_csrf:CSRF, action:'assign_user', id:commId, user_id:userId})
  }).then(r => r.json()).then(d => {
    if (d.ok) { bootstrap.Modal.getInstance('#assignUserModal').hide(); location.reload(); }
    else alert(d.error || 'Błąd');
  });
});

// ── Archiwizuj ─────────────────────────────────────────────────────────────────
document.querySelectorAll('.btn-archive').forEach(btn => {
  btn.addEventListener('click', () => {
    fetch(API, {method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({_csrf:CSRF, action:'set_status', id:btn.dataset.id, status:'archived'})
    }).then(() => btn.closest('.inbox-item').remove());
  });
});

// ── Oznacz wszystkie jako przeczytane ──────────────────────────────────────────
document.getElementById('btn-mark-all-read')?.addEventListener('click', () => {
  fetch(API, {method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({_csrf:CSRF, action:'mark_all_read'})
  }).then(() => location.reload());
});
</script>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
