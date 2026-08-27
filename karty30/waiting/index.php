<?php
/**
 * karty30/waiting/index.php — Lista oczekujących na konsultację D3.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/karty30.php';

k30_require_access();
karty30_migrate();

$can_write = can_write('karty30') || is_admin();
$PAGE_TITLE = 'Lista oczekujących — D3';

// SMS — sprawdź dostępność
$sms_ok = false;
try {
    require_once dirname(dirname(__DIR__)) . '/includes/sms.php';
    $sms_ok = sms_channel_ready();
} catch (\Throwable $e) {}

// ── POST ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_write) {
    csrf_check();
    $op = $_POST['_op'] ?? '';

    if ($op === 'add') {
        $cid      = (int)($_POST['client_id'] ?? 0);
        $priority = $_POST['priority'] ?? 'zwykly';
        $reason   = trim($_POST['reason'] ?? '');
        $notes    = trim($_POST['notes']  ?? '');
        if (!$cid) { flash_set('danger', 'Wybierz beneficjenta.'); header('Location: index.php#add'); exit; }

        // Sprawdź czy nie ma już aktywnego wpisu
        $existing = db_one(
            "SELECT id FROM k30_waiting_list WHERE client_id=? AND status IN ('waiting','contacted')",
            [$cid]
        );
        if ($existing) {
            flash_set('warning', 'Beneficjent jest już na liście oczekujących.');
        } else {
            k30_waiting_add($cid, $priority, $reason, $notes);
            flash_set('success', 'Dodano do kolejki oczekujących.');
        }
        header('Location: index.php'); exit;
    }

    if ($op === 'status') {
        $id     = (int)($_POST['id'] ?? 0);
        $status = $_POST['status'] ?? '';
        if ($id && array_key_exists($status, K30_WAIT_STATUSES)) {
            k30_waiting_change_status($id, $status);
            flash_set('success', 'Status zmieniony.');
        }
        header('Location: index.php'); exit;
    }

    if ($op === 'sms' && $sms_ok) {
        $id  = (int)($_POST['id'] ?? 0);
        $msg = trim($_POST['sms_message'] ?? '');
        if ($id && $msg) {
            $ok = k30_waiting_send_sms($id, $msg);
            flash_set($ok ? 'success' : 'danger', $ok ? 'SMS wysłany.' : 'Błąd wysyłki SMS (sprawdź numer telefonu).');
        }
        header('Location: index.php'); exit;
    }

    if ($op === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            k30_waiting_change_status($id, 'cancelled');
            flash_set('success', 'Wpis anulowany.');
        }
        header('Location: index.php'); exit;
    }

    if ($op === 'schedule') {
        // Przekieruj do formularza nowego terminu z prefill klienta
        $id  = (int)($_POST['id'] ?? 0);
        $row = db_one("SELECT client_id FROM k30_waiting_list WHERE id=?", [$id]);
        if ($row) {
            k30_waiting_change_status($id, 'scheduled');
            header('Location: ' . APP_URL . '/karty30/schedules/add.php?client_id=' . (int)$row['client_id'] . '&from_waiting=' . $id);
            exit;
        }
    }
}

// Filtry
$status_filter = $_GET['status'] ?? 'active';
$waiting          = k30_waiting_list($status_filter);
$all_clients      = db_all("SELECT id, name, phone FROM k30_clients ORDER BY name");
$prefill_client   = (int)($_GET['add_client'] ?? 0);

// Statystyki
$counts = [];
foreach (['active','waiting','contacted','scheduled','cancelled'] as $s) {
    $counts[$s] = (int)(db_one(
        "SELECT COUNT(*) AS c FROM k30_waiting_list WHERE " . ($s === 'active' ? "status IN ('waiting','contacted')" : "status='$s'")
    )['c'] ?? 0);
}

// Szablon SMS
$org = defined('ORG_NAME') ? ORG_NAME : 'Dydaktyka';
$sms_template = "Dzień dobry! {$org} informuje, że możemy zaproponować Panu/Pani termin konsultacji. Prosimy o kontakt tel. lub odpowiedź na tę wiadomość.";

include dirname(dirname(__DIR__)) . '/karty30/includes/header_k30.php';
?>

<style>
.wl-priority { display:inline-flex;align-items:center;gap:.3rem;padding:.2em .6em;border-radius:6px;font-size:.75rem;font-weight:700 }
.wl-status   { display:inline-block;padding:.2em .55em;border-radius:5px;font-size:.73rem;font-weight:600 }
.wl-row-pilny  { border-left:3px solid #dc2626 }
.wl-row-pfron  { border-left:3px solid #7c3aed }
.wl-row-zwykly { border-left:3px solid #2563eb }
</style>

<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/karty30/index.php">Dydaktyka 3</a></li>
  <li class="breadcrumb-item active">Lista oczekujących</li>
</ol></nav>

<div class="d-flex align-items-center mb-3 gap-2 flex-wrap">
  <h4 class="mb-0 fw-bold">
    <i class="bi bi-hourglass-split text-warning me-2"></i>Lista oczekujących
  </h4>
  <?php if ($counts['active'] > 0): ?>
  <span class="badge bg-warning text-dark fs-6"><?= $counts['active'] ?> oczekuje</span>
  <?php endif; ?>
</div>

<?= flash_html() ?>

<!-- Statystyki -->
<div class="row g-2 mb-3">
  <?php foreach ([
    ['active',    'Aktywni',     'text-warning', 'bi-hourglass-split'],
    ['scheduled', 'Zaplanowane', 'text-success',  'bi-calendar-check'],
    ['contacted', 'Skontaktowani','text-primary', 'bi-chat-dots'],
    ['cancelled', 'Anulowane',   'text-muted',    'bi-x-circle'],
  ] as [$s, $lbl, $cls, $ic]): ?>
  <div class="col-6 col-sm-3">
    <a href="?status=<?= $s ?>" class="card border-0 shadow-sm text-decoration-none <?= $status_filter===$s?'border-primary border':'' ?>">
      <div class="card-body py-2 px-3 d-flex align-items-center gap-2">
        <i class="bi <?= $ic ?> <?= $cls ?> fs-5"></i>
        <div>
          <div class="fw-bold fs-5"><?= $counts[$s] ?></div>
          <div class="text-muted small"><?= $lbl ?></div>
        </div>
      </div>
    </a>
  </div>
  <?php endforeach; ?>
</div>

<!-- Tabela kolejki -->
<div class="card border-0 shadow-sm mb-4">
  <div class="card-header d-flex align-items-center gap-2 fw-semibold">
    <i class="bi bi-list-ol me-1"></i>
    Kolejka
    <?php foreach ([
      'active'    => 'Oczekujący',
      'scheduled' => 'Zaplanowani',
      'cancelled' => 'Anulowani',
      ''          => 'Wszyscy',
    ] as $s => $l): ?>
    <a href="?status=<?= $s ?>"
       class="btn btn-xs btn-sm py-0 px-2 ms-1 <?= $status_filter===$s?'btn-primary':'btn-outline-secondary' ?>">
      <?= $l ?>
    </a>
    <?php endforeach; ?>
  </div>

  <?php if (!$waiting): ?>
  <div class="card-body text-muted">Brak wpisów w wybranej kategorii.</div>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0" style="font-size:.86rem">
      <thead class="table-light">
        <tr>
          <th style="width:36px">#</th>
          <th>Priorytet</th>
          <th>Beneficjent</th>
          <th>Powód / uwagi</th>
          <th>Status</th>
          <th>Czeka od</th>
          <th>SMS</th>
          <th class="text-end">Akcje</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($waiting as $i => $w):
          $p    = K30_WAIT_PRIORITIES[$w['priority']] ?? K30_WAIT_PRIORITIES['zwykly'];
          $st   = K30_WAIT_STATUSES[$w['status']]    ?? K30_WAIT_STATUSES['waiting'];
          $days = (int)floor((time() - strtotime($w['created_at'])) / 86400);
        ?>
        <tr class="wl-row-<?= h($w['priority']) ?>">
          <td class="text-muted"><?= $i + 1 ?></td>
          <td>
            <span class="wl-priority" style="background:<?= h($p['bg']) ?>;color:<?= h($p['color']) ?>">
              <i class="bi <?= h($p['icon']) ?>"></i><?= h($p['label']) ?>
            </span>
          </td>
          <td>
            <div class="fw-semibold">
              <a href="<?= APP_URL ?>/karty30/clients/view.php?id=<?= (int)$w['client_id'] ?>"><?= h($w['client_name']) ?></a>
            </div>
            <?php if ($w['client_phone']): ?>
            <div class="text-muted small"><i class="bi bi-telephone me-1"></i><?= h($w['client_phone']) ?></div>
            <?php endif; ?>
          </td>
          <td style="max-width:200px">
            <?php if ($w['reason']): ?>
            <div class="text-truncate" style="max-width:190px" title="<?= h($w['reason']) ?>"><?= h($w['reason']) ?></div>
            <?php endif; ?>
            <?php if ($w['notes']): ?>
            <div class="text-muted small text-truncate" style="max-width:190px"><?= h($w['notes']) ?></div>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($can_write && in_array($w['status'],['waiting','contacted'])): ?>
            <form method="post" class="d-inline">
              <input type="hidden" name="_csrf"  value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="_op"    value="status">
              <input type="hidden" name="id"     value="<?= (int)$w['id'] ?>">
              <select name="status" class="form-select form-select-sm" style="width:auto;font-size:.75rem" onchange="this.form.submit()">
                <?php foreach (K30_WAIT_STATUSES as $sk => $sv): ?>
                <option value="<?= h($sk) ?>" <?= $w['status']===$sk?'selected':'' ?>><?= h($sv['label']) ?></option>
                <?php endforeach; ?>
              </select>
            </form>
            <?php else: ?>
            <span class="wl-status" style="background:<?= h($st['bg']) ?>;color:<?= h($st['color']) ?>">
              <?= h($st['label']) ?>
            </span>
            <?php if ($w['scheduled_time']): ?>
            <div class="text-muted small"><?= date('d.m.Y H:i', strtotime($w['scheduled_time'])) ?></div>
            <?php endif; ?>
            <?php endif; ?>
          </td>
          <td class="text-nowrap text-muted">
            <?= date('d.m.Y', strtotime($w['created_at'])) ?>
            <div class="small <?= $days > 30 ? 'text-danger fw-bold' : ($days > 14 ? 'text-warning' : '') ?>">
              <?= $days === 0 ? 'dzisiaj' : $days . ' dni' ?>
            </div>
          </td>
          <td class="text-center">
            <?php if ($w['sms_count'] > 0): ?>
            <span class="badge bg-success-subtle text-success border border-success-subtle"
                  title="Ostatni: <?= $w['sms_sent_at'] ? date('d.m H:i', strtotime($w['sms_sent_at'])) : '' ?>">
              <i class="bi bi-check2 me-1"></i><?= (int)$w['sms_count'] ?>
            </span>
            <?php else: ?>
            <span class="text-muted">—</span>
            <?php endif; ?>
          </td>
          <td class="text-end">
            <div class="d-flex gap-1 justify-content-end">
              <!-- Zaplanuj termin -->
              <?php if ($can_write && in_array($w['status'],['waiting','contacted'])): ?>
              <form method="post" class="d-inline">
                <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="_op"   value="schedule">
                <input type="hidden" name="id"    value="<?= (int)$w['id'] ?>">
                <button type="submit" class="btn btn-xs btn-sm btn-success py-0 px-2" title="Zaplanuj termin">
                  <i class="bi bi-calendar-plus"></i>
                </button>
              </form>
              <!-- SMS -->
              <?php if ($sms_ok && $w['client_phone']): ?>
              <button type="button" class="btn btn-xs btn-sm btn-outline-primary py-0 px-2"
                      title="Wyślij SMS"
                      data-id="<?= (int)$w['id'] ?>"
                      data-name="<?= h($w['client_name']) ?>"
                      data-phone="<?= h($w['client_phone']) ?>"
                      onclick="openSms(this)">
                <i class="bi bi-phone"></i>
              </button>
              <?php endif; ?>
              <!-- Anuluj -->
              <form method="post" class="d-inline" onsubmit="return confirm('Anulować wpis?')">
                <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="_op"   value="delete">
                <input type="hidden" name="id"    value="<?= (int)$w['id'] ?>">
                <button type="submit" class="btn btn-xs btn-sm btn-outline-danger py-0 px-2" title="Anuluj">
                  <i class="bi bi-x-lg"></i>
                </button>
              </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<!-- Formularz dodania -->
<?php if ($can_write): ?>
<div class="card border-0 shadow-sm mb-4" id="add" style="max-width:680px">
  <div class="card-header fw-semibold"><i class="bi bi-plus-circle me-2 text-success"></i>Dodaj do kolejki</div>
  <div class="card-body">
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="_op"   value="add">
      <div class="row g-3 mb-3">
        <div class="col-sm-6">
          <label class="form-label fw-semibold">Beneficjent <span class="text-danger">*</span></label>
          <select class="form-select" name="client_id" required>
            <option value="">— wybierz —</option>
            <?php foreach ($all_clients as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= $prefill_client===(int)$c['id']?'selected':'' ?>><?= h($c['name']) ?><?= $c['phone'] ? ' · ' . h($c['phone']) : '' ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-sm-6">
          <label class="form-label fw-semibold">Priorytet</label>
          <div class="d-flex gap-3 flex-wrap pt-1">
            <?php foreach (K30_WAIT_PRIORITIES as $pk => $pv): ?>
            <div class="form-check">
              <input class="form-check-input" type="radio" name="priority" id="p_<?= $pk ?>"
                     value="<?= $pk ?>" <?= $pk === 'zwykly' ? 'checked' : '' ?>>
              <label class="form-check-label" for="p_<?= $pk ?>" style="color:<?= h($pv['color']) ?>;font-weight:600">
                <i class="bi <?= h($pv['icon']) ?> me-1"></i><?= h($pv['label']) ?>
              </label>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
      <div class="row g-3 mb-3">
        <div class="col-sm-7">
          <label class="form-label">Powód / cel wizyty</label>
          <input type="text" class="form-control" name="reason"
                 placeholder="np. Obsługa komputera, MS Office, e-mail…">
        </div>
        <div class="col-sm-5">
          <label class="form-label">Uwagi</label>
          <input type="text" class="form-control" name="notes"
                 placeholder="Dyspozycyjność, kontakt itp.">
        </div>
      </div>
      <button type="submit" class="btn btn-success">
        <i class="bi bi-plus-lg me-1"></i>Dodaj do kolejki
      </button>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- Modal SMS -->
<?php if ($sms_ok): ?>
<div class="modal fade" id="smsModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="post" id="smsForm">
        <input type="hidden" name="_csrf"  value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="_op"    value="sms">
        <input type="hidden" name="id"     id="sms_id">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-phone me-2 text-primary"></i>Wyślij SMS</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-2 fw-semibold" id="sms_recipient"></div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Treść SMS <span class="text-danger">*</span></label>
            <textarea class="form-control font-monospace" name="sms_message" id="sms_msg"
                      rows="4" maxlength="160" required
                      oninput="document.getElementById('sms_len').textContent=this.value.length"><?= h($sms_template) ?></textarea>
            <div class="form-text d-flex justify-content-between">
              <span>Maks. 160 znaków (1 SMS)</span>
              <span id="sms_len"><?= mb_strlen($sms_template) ?></span>
            </div>
          </div>
          <div class="d-flex gap-2 flex-wrap">
            <button type="button" class="btn btn-xs btn-sm btn-outline-secondary"
                    onclick="document.getElementById('sms_msg').value='<?= addslashes($sms_template) ?>';document.getElementById('sms_len').textContent=<?= mb_strlen($sms_template) ?>">
              Domyślny
            </button>
            <button type="button" class="btn btn-xs btn-sm btn-outline-secondary"
                    onclick="var m=document.getElementById('sms_msg');m.value='Prosimy o kontakt w sprawie umówienia terminu konsultacji: tel. ';document.getElementById('sms_len').textContent=m.value.length">
              Krótki
            </button>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="btn btn-primary">
            <i class="bi bi-send me-1"></i>Wyślij SMS
          </button>
        </div>
      </form>
    </div>
  </div>
</div>
<script>
function openSms(btn) {
  document.getElementById('sms_id').value = btn.dataset.id;
  document.getElementById('sms_recipient').textContent =
    btn.dataset.name + ' · ' + btn.dataset.phone;
  new bootstrap.Modal(document.getElementById('smsModal')).show();
}
</script>
<?php endif; ?>

<?php include dirname(dirname(__DIR__)) . '/karty30/includes/footer_k30.php'; ?>
