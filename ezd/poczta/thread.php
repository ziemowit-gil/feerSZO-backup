<?php
/**
 * ezd/poczta/thread.php — Widok wątku/konwersacji.
 *
 * GET ?key=THREAD_KEY          → wątek po kluczu
 * GET ?comm_id=N               → wątek po pojedynczej wiadomości
 * GET ?sprawa_id=N             → wszystkie wiadomości dla sprawy
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

$svc = new EzdMailService();

// ── Wyznacz tryb widoku ───────────────────────────────────────────────────────
$thread_key = trim($_GET['key']      ?? '');
$comm_id    = (int)($_GET['comm_id'] ?? 0);
$sprawa_id  = (int)($_GET['sprawa_id'] ?? 0);

$messages   = [];
$sprawa     = null;

if ($thread_key !== '') {
    $messages = $svc->getThread($thread_key);
    if ($messages) {
        $svc->markRead((int)$messages[0]['id']);
        if ($messages[0]['ezd_sprawa_id']) {
            $sprawa = db_one("SELECT * FROM ezd_sprawy WHERE id=?", [(int)$messages[0]['ezd_sprawa_id']]);
        }
    }
} elseif ($comm_id) {
    $comm = db_one("SELECT * FROM crm_communications WHERE id=?", [$comm_id]);
    if ($comm) {
        $svc->markRead($comm_id);
        if ($comm['thread_key']) {
            $messages = $svc->getThread($comm['thread_key']);
        } else {
            $messages = [$comm];
        }
        if ($comm['ezd_sprawa_id']) {
            $sprawa = db_one("SELECT * FROM ezd_sprawy WHERE id=?", [(int)$comm['ezd_sprawa_id']]);
        }
    }
} elseif ($sprawa_id) {
    $sprawa   = db_one("SELECT * FROM ezd_sprawy WHERE id=?", [$sprawa_id]);
    $messages = db_all(
        "SELECT c.*, u.name AS sender_user
         FROM crm_communications c
         LEFT JOIN users u ON u.id = c.sent_by
         WHERE c.ezd_sprawa_id=? AND c.channel='email'
         ORDER BY c.sent_at ASC",
        [$sprawa_id]
    );
}

if (!$messages && !$sprawa_id) {
    flash_set('error', 'Wątek nie istnieje.');
    header('Location: ' . APP_URL . '/ezd/poczta/index.php');
    exit;
}

$first = $messages[0] ?? [];
$PAGE_TITLE = 'Wątek: ' . mb_substr($first['subject'] ?? 'Poczta EZD', 0, 60);

// Stopki
$sigs = EzdMailService::fetchSignatures();
$users = db_all("SELECT id, name FROM users WHERE is_active=1 ORDER BY name");

include dirname(dirname(__DIR__)) . '/includes/header.php';
?>

<div class="container-fluid px-3 px-md-4" style="max-width:900px">

  <!-- Breadcrumb / nagłówek ──────────────────────────────────────────────── -->
  <div class="d-flex flex-wrap align-items-start gap-2 mb-3">
    <div class="flex-grow-1">
      <nav aria-label="breadcrumb" style="font-size:.82rem">
        <ol class="breadcrumb mb-1">
          <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/poczta/index.php">Poczta EZD</a></li>
          <?php if ($sprawa): ?>
          <li class="breadcrumb-item">
            <a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= (int)$sprawa['id'] ?>"><?= h($sprawa['znak_sprawy']) ?></a>
          </li>
          <?php endif; ?>
          <li class="breadcrumb-item active">Wątek</li>
        </ol>
      </nav>
      <h6 class="mb-0 fw-bold">
        <?= h(mb_substr($first['subject'] ?? '(brak tematu)', 0, 80)) ?>
        <span class="text-muted fw-normal" style="font-size:.8rem">(<?= count($messages) ?> wiad.)</span>
      </h6>
    </div>

    <div class="d-flex gap-2 flex-shrink-0">
      <?php if ($sprawa): ?>
      <a href="<?= APP_URL ?>/ezd/poczta/compose.php?sprawa_id=<?= (int)$sprawa['id'] ?>&reply_to=<?= (int)($first['id'] ?? 0) ?>"
         class="btn btn-sm btn-primary">
        <i class="bi bi-reply me-1"></i>Odpowiedz
      </a>
      <?php endif; ?>
      <a href="<?= APP_URL ?>/ezd/poczta/compose.php?sprawa_id=<?= $sprawa_id ?: '' ?>" class="btn btn-sm btn-outline-primary">
        <i class="bi bi-pencil-square me-1"></i>Nowa
      </a>
    </div>
  </div>

  <?php if ($sprawa): ?>
  <!-- Panel sprawy ───────────────────────────────────────────────────────── -->
  <div class="alert alert-info py-2 mb-3" style="font-size:.83rem">
    <i class="bi bi-folder2-open me-1"></i>
    <strong><?= h($sprawa['znak_sprawy']) ?></strong> — <?= h(mb_substr($sprawa['title'],0,80)) ?>
    <a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= (int)$sprawa['id'] ?>" class="ms-2 small">Otwórz sprawę</a>
  </div>
  <?php endif; ?>

  <!-- Lista wiadomości ────────────────────────────────────────────────────── -->
  <?php if (empty($messages)): ?>
  <div class="text-center text-muted py-5">
    <i class="bi bi-inbox" style="font-size:2.5rem;opacity:.3"></i>
    <p class="mt-2 mb-0">Brak wiadomości.</p>
  </div>
  <?php else: ?>

  <div class="vstack gap-3" id="thread-messages">
    <?php foreach ($messages as $i => $msg):
      $is_out   = ($msg['direction'] ?? 'in') === 'out';
      $sender   = $is_out
                  ? ($msg['sender_user'] ?? ($msg['from_name'] ?? 'Ja'))
                  : ($msg['from_name']   ?? $msg['from_email'] ?? '—');
      $sent_at  = strtotime($msg['sent_at'] ?? 'now');
      $body_html = $msg['body_html'] ?? nl2br(htmlspecialchars($msg['body'] ?? ''));
      $collapse_id = 'msg-' . $msg['id'];
      $is_last  = ($i === count($messages) - 1);
    ?>
    <div class="card border-0 shadow-sm <?= $is_out ? 'border-start border-3 border-primary' : '' ?>" id="card-<?= (int)$msg['id'] ?>">
      <!-- Nagłówek -->
      <div class="card-header bg-white d-flex align-items-start gap-2 py-2 <?= !$is_last ? 'collapsed' : '' ?>"
           data-bs-toggle="collapse" data-bs-target="#<?= $collapse_id ?>"
           style="cursor:pointer;font-size:.83rem">
        <i class="bi <?= $is_out ? 'bi-envelope-arrow-up text-primary' : 'bi-envelope-arrow-down text-success' ?> mt-1 flex-shrink-0"></i>
        <div class="flex-grow-1 overflow-hidden">
          <div class="d-flex align-items-baseline gap-2 flex-wrap">
            <span class="fw-semibold"><?= h($sender) ?></span>
            <?php if (!$is_out && ($msg['from_email'] ?? '')): ?>
            <span class="text-muted" style="font-size:.77rem">&lt;<?= h($msg['from_email']) ?>&gt;</span>
            <?php endif; ?>
            <span class="ms-auto text-muted flex-shrink-0" style="font-size:.77rem"><?= date('d.m.Y H:i', $sent_at) ?></span>
          </div>
          <?php if (!$is_last): ?>
          <div class="text-muted text-truncate" style="font-size:.78rem">
            <?= h(mb_substr(strip_tags($msg['body'] ?? ''), 0, 120)) ?>
          </div>
          <?php endif; ?>
        </div>
        <?php if (!$is_out): ?>
        <div class="flex-shrink-0 ms-1 d-flex gap-1" onclick="event.stopPropagation()">
          <?php if (!($msg['ezd_sprawa_id'] ?? 0)): ?>
          <button class="btn btn-sm btn-outline-success py-0 px-1 btn-assign-sprawa-thread"
                  data-id="<?= (int)$msg['id'] ?>" title="Przypisz do sprawy">
            <i class="bi bi-folder-plus" style="font-size:.8rem"></i>
          </button>
          <?php endif; ?>
          <button class="btn btn-sm btn-outline-secondary py-0 px-1 btn-assign-user-thread"
                  data-id="<?= (int)$msg['id'] ?>" title="Przypisz osobę">
            <i class="bi bi-person-plus" style="font-size:.8rem"></i>
          </button>
          <button class="btn btn-sm btn-outline-secondary py-0 px-1 btn-archive-thread"
                  data-id="<?= (int)$msg['id'] ?>" title="Archiwizuj">
            <i class="bi bi-archive" style="font-size:.8rem"></i>
          </button>
        </div>
        <?php endif; ?>
      </div>

      <!-- Treść (rozwijana) -->
      <div class="collapse <?= $is_last ? 'show' : '' ?>" id="<?= $collapse_id ?>">
        <div class="card-body p-3">

          <!-- Miniheader DL -->
          <dl class="row g-0 mb-2 border-bottom pb-2" style="font-size:.78rem;color:#555">
            <?php if (!$is_out && ($msg['from_email'] ?? '')): ?>
            <dt class="col-auto pe-2 fw-semibold text-muted" style="min-width:3rem">Od:</dt>
            <dd class="col mb-0"><?= h($msg['from_name'] ?? '') ?> &lt;<?= h($msg['from_email']) ?>&gt;</dd>
            <div class="w-100"></div>
            <?php endif; ?>
            <dt class="col-auto pe-2 fw-semibold text-muted" style="min-width:3rem">Data:</dt>
            <dd class="col mb-0"><?= date('d.m.Y H:i:s', $sent_at) ?></dd>
            <?php if ($msg['has_attachments'] ?? 0): ?>
            <div class="w-100"></div>
            <dt class="col-auto pe-2 fw-semibold text-muted" style="min-width:3rem">Załączniki:</dt>
            <dd class="col mb-0 d-flex flex-wrap gap-1">
              <?php
              $atts = db_all(
                  "SELECT * FROM poczta_attachments WHERE communication_id=?",
                  [(int)$msg['id']]
              );
              foreach ($atts as $att):
                $ext  = strtolower(pathinfo($att['original_name'], PATHINFO_EXTENSION));
                $icon = in_array($ext, ['pdf']) ? 'bi-file-pdf text-danger'
                      : (in_array($ext, ['doc','docx']) ? 'bi-file-word text-primary'
                      : (in_array($ext, ['xls','xlsx']) ? 'bi-file-excel text-success'
                      : 'bi-file-earmark'));
              ?>
              <span class="badge text-bg-light border d-flex align-items-center gap-1">
                <i class="bi <?= $icon ?>"></i>
                <?= h(mb_substr($att['original_name'],0,30)) ?>
                <span class="text-muted">(<?= round($att['size_bytes']/1024) ?>KB)</span>
                <?php if ($att['stored_path']): ?>
                <a href="<?= APP_URL ?>/uploads/<?= h($att['stored_path']) ?>"
                   class="text-decoration-none ms-1" target="_blank" title="Pobierz">
                  <i class="bi bi-download"></i>
                </a>
                <?php endif; ?>
              </span>
              <?php endforeach; ?>
            </dd>
            <?php endif; ?>
          </dl>

          <!-- Treść HTML w iframe-like sandboxie div -->
          <div class="email-body-content" style="font-size:.88rem;line-height:1.6;max-width:100%;overflow-x:auto">
            <?= $body_html ?>
          </div>

          <!-- Przycisk odpowiedzi inline -->
          <div class="mt-3 pt-2 border-top">
            <a href="<?= APP_URL ?>/ezd/poczta/compose.php?reply_to=<?= (int)$msg['id'] ?><?= $sprawa ? '&sprawa_id='.(int)$sprawa['id'] : '' ?>"
               class="btn btn-sm btn-outline-primary">
              <i class="bi bi-reply me-1"></i>Odpowiedz
            </a>
          </div>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div><!-- /thread-messages -->
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
        <input type="text" id="assignSprawaSearch" class="form-control" placeholder="Wpisz znak lub tytuł sprawy…" autocomplete="off">
        <div id="assignSprawaResults" class="list-group mt-1" style="max-height:200px;overflow-y:auto;display:none"></div>
        <div id="assignSprawaSelected" class="alert alert-success py-1 mt-2 small" style="display:none">
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
/* Izolacja stylów treści e-mail, ochrona przed overflow i niepożądanym CSS */
.email-body-content { word-break: break-word; }
.email-body-content img { max-width: 100%; height: auto; }
.email-body-content table { max-width: 100%; overflow-x: auto; display: block; }
.email-body-content a { color: #2563eb; }
/* Akordeon wiadomości */
.card-header[data-bs-toggle="collapse"]:hover { background: #f8fafc !important; }
</style>

<script>
const CSRF = <?= json_encode(csrf_token()) ?>;
const API  = <?= json_encode(APP_URL . '/ezd/poczta/api.php') ?>;

// ── Przypisz do sprawy ─────────────────────────────────────────────────────────
let _assignSprawaId = null;
document.querySelectorAll('.btn-assign-sprawa-thread').forEach(btn => {
  btn.addEventListener('click', () => {
    document.getElementById('assignSprawaCommId').value = btn.dataset.id;
    _assignSprawaId = null;
    document.getElementById('assignSprawaSelected').style.display = 'none';
    document.getElementById('btnAssignSprawaConfirm').disabled = true;
    document.getElementById('assignSprawaSearch').value = '';
    new bootstrap.Modal('#assignSprawaModal').show();
  });
});

let _searchTimer;
document.getElementById('assignSprawaSearch')?.addEventListener('input', function() {
  clearTimeout(_searchTimer);
  const q = this.value.trim();
  if (q.length < 2) { document.getElementById('assignSprawaResults').style.display='none'; return; }
  _searchTimer = setTimeout(() => {
    fetch(API + '?action=search_sprawa&q=' + encodeURIComponent(q))
      .then(r => r.json()).then(data => {
        const ul = document.getElementById('assignSprawaResults');
        ul.innerHTML = '';
        (data.rows || []).forEach(s => {
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
        ul.style.display = ul.children.length ? '' : 'none';
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
document.querySelectorAll('.btn-assign-user-thread').forEach(btn => {
  btn.addEventListener('click', () => {
    document.getElementById('assignUserCommId').value = btn.dataset.id;
    new bootstrap.Modal('#assignUserModal').show();
  });
});
document.getElementById('btnAssignUserConfirm')?.addEventListener('click', () => {
  const commId = document.getElementById('assignUserCommId').value;
  const userId = document.getElementById('assignUserSelect').value;
  fetch(API, {method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({_csrf:CSRF, action:'assign_user', id:commId, user_id:userId})
  }).then(r => r.json()).then(d => {
    if (d.ok) { bootstrap.Modal.getInstance('#assignUserModal').hide(); location.reload(); }
    else alert(d.error || 'Błąd');
  });
});

// ── Archiwizuj ─────────────────────────────────────────────────────────────────
document.querySelectorAll('.btn-archive-thread').forEach(btn => {
  btn.addEventListener('click', () => {
    if (!confirm('Archiwizować tę wiadomość?')) return;
    fetch(API, {method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({_csrf:CSRF, action:'set_status', id:btn.dataset.id, status:'archived'})
    }).then(() => document.getElementById('card-' + btn.dataset.id)?.remove());
  });
});
</script>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
