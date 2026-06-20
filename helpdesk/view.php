<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/helpdesk.php';
helpdesk_migrate();
require_login();

$u   = current_user();
$uid = (int)$u['id'];
$id  = (int)($_GET['id'] ?? 0);

$ticket = $id ? db_one("SELECT t.*, op.name AS assigned_name, op.email AS assigned_email
    FROM helpdesk_tickets t
    LEFT JOIN users op ON op.id = t.assigned_to
    WHERE t.id = ?", [$id]) : null;

if (!$ticket) { http_response_code(404); die('Zgłoszenie nie istnieje.'); }
if (!hd_can_view_ticket($ticket)) {
    flash_set('danger', 'Brak dostępu do tego zgłoszenia.');
    header('Location: ' . APP_URL . '/helpdesk/index.php'); exit;
}

$is_op  = hd_is_operator();

// ── Zmiana statusu ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_set_status']) && $is_op) {
    csrf_check();
    $new_status = $_POST['status'] ?? '';
    $note       = trim($_POST['status_note'] ?? '');
    if (isset(HD_STATUSES[$new_status]) && $new_status !== $ticket['status']) {
        $old_status = $ticket['status'];
        $extra = [];
        if ($new_status === 'rozwiązane') $extra['resolved_at'] = date('Y-m-d H:i:s');
        if ($new_status === 'zamknięte')  $extra['closed_at']   = date('Y-m-d H:i:s');
        if ($new_status === 'przekazane_zewn') {
            $ext_vendor = trim($_POST['ext_vendor'] ?? '');
            $ext_ref    = trim($_POST['ext_ref'] ?? '');
            $ext_reason = trim($_POST['ext_reason'] ?? '');
            $extra['ext_vendor']    = $ext_vendor;
            $extra['ext_ref']       = $ext_ref;
            $extra['ext_reason']    = $ext_reason;
            $extra['ext_handed_at'] = date('Y-m-d H:i:s');
            // Powód przekazania trafia też do notatki/powiadomienia, jeśli nie podano osobnej.
            if ($note === '') {
                $note = trim(($ext_vendor !== '' ? "Firma: {$ext_vendor}\n" : '')
                           . ($ext_ref    !== '' ? "Nr zgłoszenia u firmy: {$ext_ref}\n" : '')
                           . ($ext_reason !== '' ? "Powód: {$ext_reason}" : ''));
            }
        }
        db_update('helpdesk_tickets', array_merge(['status' => $new_status, 'updated_at' => date('Y-m-d H:i:s')], $extra), $id);
        if ($note) {
            db_insert('helpdesk_messages', [
                'ticket_id'   => $id,
                'user_id'     => $uid,
                'user_name'   => $u['name'] ?? '',
                'body'        => $note,
                'is_internal' => 1,
            ]);
        }
        $ticket = array_merge($ticket, ['status' => $new_status], $extra);
        hd_notify_status_change($ticket, $old_status, $new_status, $note);
        $msg = 'Status zmieniony: ' . (HD_STATUSES[$new_status]['label'] ?? $new_status);

        // Opcjonalne udostępnienie podglądu osobie w firmie zewnętrznej
        if ($new_status === 'przekazane_zewn' && !empty($_POST['share_with_vendor'])) {
            $se = trim($_POST['share_email'] ?? '');
            $sn = trim($_POST['share_name'] ?? '');
            if (hd_share_ticket($ticket, $se, $sn, $extra['ext_reason'] ?? '', $u['name'] ?? '')) {
                db_insert('helpdesk_messages', [
                    'ticket_id' => $id, 'user_id' => $uid, 'user_name' => $u['name'] ?? '',
                    'body' => 'Udostępniono podgląd zgłoszenia: ' . ($sn !== '' ? "{$sn} <{$se}>" : $se),
                    'is_internal' => 1,
                ]);
                $msg .= '. Link wysłano do: ' . $se;
            } elseif ($se !== '') {
                $msg .= '. Uwaga: nie udało się wysłać linku (sprawdź adres e-mail).';
            }
        }
        flash_set('success', $msg);
    }
    header('Location: view.php?id=' . $id); exit;
}

// ── Udostępnienie podglądu innej osobie ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_share']) && $is_op) {
    csrf_check();
    $se = trim($_POST['share_email'] ?? '');
    $sn = trim($_POST['share_name'] ?? '');
    $snote = trim($_POST['share_note'] ?? '');
    if (hd_share_ticket($ticket, $se, $sn, $snote, $u['name'] ?? '')) {
        db_insert('helpdesk_messages', [
            'ticket_id' => $id, 'user_id' => $uid, 'user_name' => $u['name'] ?? '',
            'body' => 'Udostępniono podgląd zgłoszenia: ' . ($sn !== '' ? "{$sn} <{$se}>" : $se),
            'is_internal' => 1,
        ]);
        flash_set('success', 'Link do podglądu wysłano do: ' . $se);
    } else {
        flash_set('danger', 'Nie udało się udostępnić — sprawdź adres e-mail.');
    }
    header('Location: view.php?id=' . $id); exit;
}

// ── Przypisanie operatora ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_assign']) && $is_op) {
    csrf_check();
    $assign_to = (int)($_POST['assign_to'] ?? 0) ?: null;
    db_update('helpdesk_tickets', ['assigned_to' => $assign_to, 'updated_at' => date('Y-m-d H:i:s'),
        'status' => $ticket['status'] === 'nowe' ? 'otwarte' : $ticket['status']], $id);
    if ($assign_to) {
        $op = db_one("SELECT email, name FROM users WHERE id=?", [$assign_to]);
        if ($op) hd_notify_assigned($ticket, $op);
        if ($ticket['status'] === 'nowe') hd_notify_status_change($ticket, 'nowe', 'otwarte');
    }
    flash_set('success', $assign_to ? 'Przypisano operatora.' : 'Usunięto przypisanie.');
    header('Location: view.php?id=' . $id); exit;
}

// ── Przypisz do siebie ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_take']) && $is_op) {
    csrf_check();
    $old_status = $ticket['status'];
    $new_status = $old_status === 'nowe' ? 'otwarte' : $old_status;
    db_update('helpdesk_tickets', ['assigned_to' => $uid, 'status' => $new_status, 'updated_at' => date('Y-m-d H:i:s')], $id);
    if ($old_status === 'nowe') hd_notify_status_change($ticket, 'nowe', 'otwarte');
    flash_set('success', 'Zgłoszenie przypisane do Ciebie.');
    header('Location: view.php?id=' . $id); exit;
}

// ── Dodaj wiadomość ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_add_msg'])) {
    csrf_check();
    $body        = trim($_POST['msg_body'] ?? '');
    $is_internal = $is_op && !empty($_POST['is_internal']) ? 1 : 0;
    if ($body) {
        $msg_id = db_insert('helpdesk_messages', [
            'ticket_id'   => $id,
            'user_id'     => $uid,
            'user_name'   => $u['name'] ?? '',
            'body'        => $body,
            'is_internal' => $is_internal,
        ]);
        db_update('helpdesk_tickets', ['updated_at' => date('Y-m-d H:i:s')], $id);

        // SLA: pierwsza publiczna odpowiedź operatora domyka czas reakcji
        if ($is_op && !$is_internal && empty($ticket['first_response_at'])) {
            db_update('helpdesk_tickets', ['first_response_at' => date('Y-m-d H:i:s')], $id);
        }

        // Załączniki do wiadomości
        if (!empty($_FILES['msg_attachments']['name'][0])) {
            $dir = UPLOAD_DIR . 'helpdesk/';
            if (!is_dir($dir)) @mkdir($dir, 0755, true);
            foreach ($_FILES['msg_attachments']['name'] as $i => $orig_name) {
                if ($_FILES['msg_attachments']['error'][$i] !== UPLOAD_ERR_OK) continue;
                $ext   = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
                $allow = ['pdf','doc','docx','xls','xlsx','jpg','jpeg','png','gif','zip','txt','csv'];
                if (!in_array($ext, $allow, true)) continue;
                $stored = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                if (@move_uploaded_file($_FILES['msg_attachments']['tmp_name'][$i], $dir . $stored)) {
                    db_insert('helpdesk_attachments', [
                        'ticket_id'     => $id,
                        'message_id'    => $msg_id,
                        'original_name' => $orig_name,
                        'stored_path'   => 'helpdesk/' . $stored,
                        'file_size'     => $_FILES['msg_attachments']['size'][$i],
                        'uploaded_by'   => $uid,
                    ]);
                }
            }
        }

        // Jeśli użytkownik odpowiada → auto otwórz z oczekuje
        if (!$is_op && $ticket['status'] === 'oczekuje') {
            db_update('helpdesk_tickets', ['status' => 'otwarte', 'updated_at' => date('Y-m-d H:i:s')], $id);
        }

        if (!$is_internal) {
            $fresh = db_one("SELECT t.*, op.email AS assigned_email FROM helpdesk_tickets t LEFT JOIN users op ON op.id=t.assigned_to WHERE t.id=?", [$id]);
            hd_notify_new_message($fresh ?? $ticket, ['user_id' => $uid, 'user_name' => $u['name'] ?? '', 'body' => $body, 'is_internal' => 0]);
        }

        flash_set('success', 'Wiadomość dodana.');
    }
    header('Location: view.php?id=' . $id); exit;
}

// ── Usuń zgłoszenie (admin) ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_delete']) && is_admin()) {
    csrf_check();
    db()->prepare("DELETE FROM helpdesk_tickets WHERE id=?")->execute([$id]);
    flash_set('success', 'Zgłoszenie usunięte.');
    header('Location: ' . APP_URL . '/helpdesk/index.php'); exit;
}

// ── Dane do widoku ────────────────────────────────────────────────────────────
$ticket    = db_one("SELECT t.*, op.name AS assigned_name
    FROM helpdesk_tickets t LEFT JOIN users op ON op.id=t.assigned_to WHERE t.id=?", [$id]);
$messages  = db_all("SELECT m.*, u.email AS user_email
    FROM helpdesk_messages m LEFT JOIN users u ON u.id=m.user_id
    WHERE m.ticket_id=? " . ($is_op ? '' : "AND m.is_internal=0") . "
    ORDER BY m.created_at ASC", [$id]);
$atts      = db_all("SELECT * FROM helpdesk_attachments WHERE ticket_id=? ORDER BY uploaded_at ASC", [$id]);
$operators = $is_op ? db_all(
    "SELECT id, name FROM users WHERE (helpdesk_operator=1 OR role='admin') AND is_active=1 ORDER BY name", []
) : [];

$PAGE_TITLE = $ticket['number'] . ' — ' . $ticket['title'];
include dirname(__DIR__) . '/includes/header.php';
?>

<style>
.hd-msg { border-radius:10px; padding:14px 18px; margin-bottom:12px; }
.hd-msg-user   { background:#EEF4FF; border-left:3px solid #2563EB; }
.hd-msg-op     { background:#F0FDF4; border-left:3px solid #16A34A; }
.hd-msg-intern { background:#FFF7ED; border-left:3px dashed #EA580C; }
</style>

<!-- Nagłówek -->
<div class="d-flex align-items-start gap-2 mb-3 flex-wrap">
  <a href="<?= APP_URL ?>/helpdesk/index.php" class="btn btn-sm btn-outline-secondary mt-1">
    <i class="bi bi-arrow-left"></i>
  </a>
  <div class="flex-grow-1">
    <div class="d-flex align-items-center gap-2 flex-wrap">
      <span class="font-monospace fw-bold text-muted"><?= h($ticket['number']) ?></span>
      <?= hd_status_badge($ticket['status']) ?>
      <?= hd_priority_badge($ticket['priority']) ?>
      <span class="badge bg-light text-dark border small"><?= h(HD_CATEGORIES[$ticket['category']] ?? $ticket['category']) ?></span>
    </div>
    <h4 class="mb-0 mt-1 fw-bold"><?= h($ticket['title']) ?></h4>
    <div class="text-muted small mt-1">
      Zgłoszono przez <strong><?= h($ticket['requester_name']) ?></strong>
      · <?= date_pl(substr($ticket['created_at'], 0, 10)) ?>
      <?= $ticket['assigned_name']
        ? ' · <i class="bi bi-person-check text-success me-1"></i>Operator: <strong>' . h($ticket['assigned_name']) . '</strong>'
        : ' · <span class="text-danger"><i class="bi bi-exclamation-circle me-1"></i>Nieprzypisane</span>' ?>
    </div>
  </div>
</div>

<?= flash_html() ?>

<div class="row g-3">

<!-- ── Kolumna główna — wiadomości ────────────────────────────────────────── -->
<div class="col-lg-8">

  <!-- Wiadomości -->
  <div class="mb-3">
    <?php foreach ($messages as $m):
      $is_mine     = (int)$m['user_id'] === $uid;
      $is_req      = (int)$m['user_id'] === (int)$ticket['requester_id'];
      $msg_class   = $m['is_internal'] ? 'hd-msg-intern' : ($is_req ? 'hd-msg-user' : 'hd-msg-op');
      $msg_atts    = array_filter($atts, fn($a) => (int)$a['message_id'] === (int)$m['id']);
    ?>
    <div class="hd-msg <?= $msg_class ?>">
      <div class="d-flex justify-content-between align-items-start mb-1">
        <div>
          <strong><?= h($m['user_name'] ?: 'System') ?></strong>
          <?php if ($m['is_internal']): ?>
          <span class="badge bg-warning text-dark ms-1" style="font-size:.68rem">wewn.</span>
          <?php endif; ?>
        </div>
        <small class="text-muted"><?= date('d.m.Y H:i', strtotime($m['created_at'])) ?></small>
      </div>
      <div style="white-space:pre-wrap;font-size:.9rem"><?= nl2br(h($m['body'])) ?></div>
      <?php if ($msg_atts): ?>
      <div class="mt-2 d-flex flex-wrap gap-1">
        <?php foreach ($msg_atts as $a): ?>
        <a href="<?= APP_URL ?>/helpdesk/attachment.php?id=<?= $a['id'] ?>"
           class="badge bg-light text-dark border text-decoration-none" style="font-size:.78rem">
          <i class="bi bi-paperclip me-1"></i><?= h($a['original_name']) ?>
        </a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>

    <!-- Załączniki bez wiadomości (z pierwotnego zgłoszenia) -->
    <?php $init_atts = array_filter($atts, fn($a) => $a['message_id'] === null); ?>
    <?php if ($init_atts): ?>
    <div class="mt-1 mb-2">
      <div class="text-muted small mb-1"><i class="bi bi-paperclip me-1"></i>Załączniki ze zgłoszenia:</div>
      <div class="d-flex flex-wrap gap-1">
        <?php foreach ($init_atts as $a): ?>
        <a href="<?= APP_URL ?>/helpdesk/attachment.php?id=<?= $a['id'] ?>"
           class="badge bg-light text-dark border text-decoration-none">
          <?= h($a['original_name']) ?>
          <span class="text-muted ms-1">(<?= round($a['file_size'] / 1024) ?> KB)</span>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- Formularz odpowiedzi -->
  <?php if ($ticket['status'] !== 'zamknięte'): ?>
  <div class="card border-0 shadow-sm">
    <div class="card-header py-2 fw-semibold" style="font-size:.85rem">
      <i class="bi bi-reply me-1"></i>Dodaj wiadomość
    </div>
    <div class="card-body">
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <?php if ($is_op): $reply_tpls = hd_reply_templates($ticket); ?>
        <div class="dropdown mb-2">
          <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
            <i class="bi bi-card-text me-1"></i>Wstaw szablon odpowiedzi
          </button>
          <ul class="dropdown-menu" style="font-size:.85rem">
            <?php foreach ($reply_tpls as $tk => $tpl): ?>
            <li>
              <button type="button" class="dropdown-item hd-tpl-btn"
                      data-body="<?= h($tpl['body']) ?>"><?= h($tpl['label']) ?></button>
            </li>
            <?php endforeach; ?>
          </ul>
        </div>
        <?php endif; ?>
        <textarea name="msg_body" id="hdMsgBody" class="form-control mb-2" rows="4" required
                  placeholder="Wpisz odpowiedź…"></textarea>
        <?php if ($is_op): ?>
        <div class="form-check mb-2">
          <input class="form-check-input" type="checkbox" name="is_internal" id="is_internal" value="1">
          <label class="form-check-label small" for="is_internal">
            <i class="bi bi-lock me-1 text-warning"></i>Notatka wewnętrzna
            <span class="text-muted">(widoczna tylko dla operatorów)</span>
          </label>
        </div>
        <?php endif; ?>
        <div class="mb-2">
          <input name="msg_attachments[]" type="file" class="form-control form-control-sm" multiple
                 accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.gif,.zip,.txt,.csv">
        </div>
        <button type="submit" name="_add_msg" class="btn btn-primary btn-sm">
          <i class="bi bi-send me-1"></i>Wyślij
        </button>
      </form>
    </div>
  </div>
  <?php else: ?>
  <div class="alert alert-secondary py-2 small">
    <i class="bi bi-lock me-1"></i>Zgłoszenie zamknięte. Nie można dodawać wiadomości.
    <?php if ($is_op): ?>
    <form method="post" class="d-inline ms-2">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="_set_status" value="1">
      <input type="hidden" name="status" value="otwarte">
      <button type="submit" class="btn btn-sm btn-outline-primary py-0">Otwórz ponownie</button>
    </form>
    <?php endif; ?>
  </div>
  <?php endif; ?>

</div><!-- /col-8 -->

<!-- ── Sidebar ────────────────────────────────────────────────────────────── -->
<div class="col-lg-4">

  <!-- Zmiana statusu (operatorzy) -->
  <?php if ($is_op): ?>
  <div class="card border-0 shadow-sm mb-3">
    <div class="card-header py-2 fw-semibold" style="font-size:.85rem">
      <i class="bi bi-arrow-repeat me-1"></i>Status zgłoszenia
    </div>
    <div class="card-body">
      <div class="mb-2"><?= hd_status_badge($ticket['status']) ?></div>
      <form method="post">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_set_status" value="1">
        <div class="d-flex flex-column gap-1 mb-2">
          <?php foreach (HD_STATUSES as $k => $s):
            if ($k === $ticket['status']) continue;
            if ($k === 'przekazane_zewn') continue; // ma własny formularz z polami firmy ?>
          <button type="submit" name="status" value="<?= h($k) ?>"
                  class="btn btn-sm btn-outline-<?= $s['class'] ?> text-start py-1">
            <i class="bi <?= $s['icon'] ?> me-1"></i><?= h($s['label']) ?>
          </button>
          <?php endforeach; ?>
        </div>
        <textarea name="status_note" class="form-control form-control-sm mb-1" rows="2"
                  placeholder="Notatka do zmiany (opcjonalnie, wewnętrzna)"></textarea>
      </form>

      <!-- Przekazanie do firmy zewnętrznej (modal) -->
      <button class="btn btn-sm btn-dark text-start py-1 w-100 mt-1" type="button"
              data-bs-toggle="modal" data-bs-target="#hdExtModal">
        <i class="bi bi-box-arrow-up-right me-1"></i><?= $ticket['status'] === 'przekazane_zewn' ? 'Aktualizuj przekazanie…' : 'Przekaż do firmy zewnętrznej…' ?>
      </button>
    </div>
  </div>

  <!-- Przypisanie -->
  <div class="card border-0 shadow-sm mb-3">
    <div class="card-header py-2 fw-semibold" style="font-size:.85rem">
      <i class="bi bi-person-check me-1"></i>Operator
    </div>
    <div class="card-body">
      <?php if ($ticket['assigned_to']): ?>
      <div class="mb-2">
        <span class="badge bg-success-subtle text-success border border-success-subtle">
          <i class="bi bi-person-check me-1"></i><?= h($ticket['assigned_name']) ?>
        </span>
      </div>
      <?php else: ?>
      <div class="mb-2 text-muted small">
        <i class="bi bi-exclamation-circle text-danger me-1"></i>Brak przypisania
      </div>
      <?php endif; ?>

      <?php if ((int)($ticket['assigned_to'] ?? 0) !== $uid): ?>
      <form method="post" class="mb-2">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <button type="submit" name="_take" class="btn btn-sm btn-primary w-100">
          <i class="bi bi-hand-index-thumb me-1"></i>Przypisz do mnie
        </button>
      </form>
      <?php endif; ?>

      <?php if (is_admin() && $operators): ?>
      <form method="post">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_assign" value="1">
        <div class="input-group input-group-sm">
          <select name="assign_to" class="form-select form-select-sm">
            <option value="">— bez przypisania —</option>
            <?php foreach ($operators as $op): ?>
            <option value="<?= $op['id'] ?>" <?= (int)($ticket['assigned_to'] ?? 0) === (int)$op['id'] ? 'selected' : '' ?>>
              <?= h($op['name']) ?>
            </option>
            <?php endforeach; ?>
          </select>
          <button class="btn btn-outline-secondary btn-sm">Przypisz</button>
        </div>
      </form>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- SLA (operatorzy) -->
  <?php if ($is_op): $sla = hd_sla($ticket); if ($sla['response'] || $sla['resolution']):
    $pr_sla = HD_PRIORITIES[$ticket['priority']] ?? []; ?>
  <div class="card border-0 shadow-sm mb-3">
    <div class="card-header py-2 fw-semibold d-flex justify-content-between align-items-center" style="font-size:.85rem">
      <span><i class="bi bi-speedometer2 me-1"></i>SLA</span>
      <span class="text-muted fw-normal" style="font-size:.78rem"><?= h(HD_PRIORITIES[$ticket['priority']]['label'] ?? $ticket['priority']) ?></span>
    </div>
    <div class="card-body small">
      <?php if ($sla['response']): ?>
      <div class="d-flex justify-content-between align-items-center mb-2">
        <?= hd_sla_badge($sla['response'], 'Reakcja') ?>
        <span class="text-muted text-nowrap ms-2" style="font-size:.74rem">
          do <?= date('d.m H:i', $sla['response']['deadline']) ?>
        </span>
      </div>
      <?php endif; ?>
      <?php if ($sla['resolution']): ?>
      <div class="d-flex justify-content-between align-items-center">
        <?= hd_sla_badge($sla['resolution'], 'Rozwiązanie') ?>
        <span class="text-muted text-nowrap ms-2" style="font-size:.74rem">
          do <?= date('d.m H:i', $sla['resolution']['deadline']) ?>
        </span>
      </div>
      <?php endif; ?>
      <?php if (($sla['resolution']['state'] ?? '') === 'paused'): ?>
      <div class="text-muted mt-2" style="font-size:.74rem">
        <i class="bi bi-info-circle me-1"></i>Zegar rozwiązania wstrzymany (oczekiwanie / firma zewnętrzna).
      </div>
      <?php endif; ?>
      <div class="text-muted mt-2" style="font-size:.72rem">
        Cele: reakcja <?= hd_fmt_secs((int)($pr_sla['sla_response'] ?? 0) * 60) ?>,
        rozwiązanie <?= hd_fmt_secs((int)($pr_sla['sla_resolve'] ?? 0) * 60) ?>.
      </div>
    </div>
  </div>
  <?php endif; endif; ?>

  <!-- Firma zewnętrzna (gdy przekazano) -->
  <?php if ($ticket['status'] === 'przekazane_zewn' || !empty($ticket['ext_vendor'])): ?>
  <div class="card border-0 shadow-sm mb-3">
    <div class="card-header py-2 fw-semibold text-bg-dark" style="font-size:.85rem">
      <i class="bi bi-box-arrow-up-right me-1"></i>Firma zewnętrzna
    </div>
    <div class="card-body small">
      <div class="mb-1"><span class="text-muted">Firma:</span> <strong><?= h($ticket['ext_vendor'] ?: '—') ?></strong></div>
      <?php if (!empty($ticket['ext_ref'])): ?>
      <div class="mb-1"><span class="text-muted">Nr zgłoszenia u firmy:</span> <span class="font-monospace"><?= h($ticket['ext_ref']) ?></span></div>
      <?php endif; ?>
      <?php if (!empty($ticket['ext_handed_at'])): ?>
      <div class="mb-1"><span class="text-muted">Przekazano:</span> <?= date('d.m.Y H:i', strtotime($ticket['ext_handed_at'])) ?></div>
      <?php endif; ?>
      <?php if (!empty($ticket['ext_reason'])): ?>
      <div class="mt-2 p-2 rounded" style="background:#f8f9fa;white-space:pre-wrap"><?= nl2br(h($ticket['ext_reason'])) ?></div>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Udostępnianie mikropanelu (operatorzy) -->
  <?php if ($is_op): $track = hd_track_url($ticket); ?>
  <div class="card border-0 shadow-sm mb-3">
    <div class="card-header py-2 fw-semibold" style="font-size:.85rem">
      <i class="bi bi-share me-1"></i>Udostępnianie podglądu
    </div>
    <div class="card-body small">
      <p class="text-muted mb-2" style="font-size:.78rem">
        Publiczny podgląd i odpowiedź bez logowania. Ten sam link dołączany jest do maili do zgłaszającego.
      </p>
      <div class="input-group input-group-sm mb-3">
        <input type="text" class="form-control font-monospace" style="font-size:.72rem"
               value="<?= h($track) ?>" id="hdTrackUrl" readonly onclick="this.select()">
        <button class="btn btn-outline-secondary" type="button"
                onclick="navigator.clipboard&&navigator.clipboard.writeText(document.getElementById('hdTrackUrl').value);this.innerHTML='<i class=\'bi bi-check2\'></i>'">
          <i class="bi bi-clipboard"></i>
        </button>
      </div>
      <label class="text-muted mb-1" style="font-size:.78rem">
        <i class="bi bi-person-plus me-1"></i>Udostępnij innej osobie (e-mailem)
      </label>
      <form method="post">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_share" value="1">
        <input type="email" name="share_email" class="form-control form-control-sm mb-1"
               placeholder="adres e-mail" required>
        <input type="text" name="share_name" class="form-control form-control-sm mb-1"
               placeholder="imię i nazwisko (opcjonalnie)">
        <textarea name="share_note" class="form-control form-control-sm mb-1" rows="2"
                  placeholder="wiadomość dla odbiorcy (opcjonalnie)"></textarea>
        <button type="submit" class="btn btn-sm btn-outline-primary w-100">
          <i class="bi bi-send me-1"></i>Wyślij link do podglądu
        </button>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <!-- Szczegóły zgłoszenia -->
  <div class="card border-0 shadow-sm mb-3">
    <div class="card-header py-2 fw-semibold" style="font-size:.85rem">
      <i class="bi bi-info-circle me-1"></i>Szczegóły
    </div>
    <div class="card-body small">
      <table class="w-100" style="border-collapse:collapse">
        <?php foreach ([
          ['Numer',      '<span class="font-monospace">' . h($ticket['number']) . '</span>'],
          ['Kategoria',  h(HD_CATEGORIES[$ticket['category']] ?? $ticket['category'])],
          ['Priorytet',  hd_priority_badge($ticket['priority'])],
          ['Zgłaszający',h($ticket['requester_name'])],
          ['E-mail',     $ticket['requester_email'] ? '<a href="mailto:'.h($ticket['requester_email']).'">' . h($ticket['requester_email']) . '</a>' : '—'],
          ['Telefon',    $ticket['requester_phone'] ? h($ticket['requester_phone']) : '—'],
          ['Zgłoszono',  date('d.m.Y H:i', strtotime($ticket['created_at']))],
          ['Zaktualizowano', date('d.m.Y H:i', strtotime($ticket['updated_at']))],
          ['Rozwiązano', $ticket['resolved_at'] ? date('d.m.Y H:i', strtotime($ticket['resolved_at'])) : '—'],
        ] as [$lbl, $val]): ?>
        <tr>
          <td style="padding:4px 8px 4px 0;color:#6c757d;white-space:nowrap;vertical-align:top"><?= $lbl ?></td>
          <td style="padding:4px 0"><?= $val ?></td>
        </tr>
        <?php endforeach; ?>
      </table>
    </div>
  </div>

  <!-- Admin: usuń -->
  <?php if (is_admin()): ?>
  <div class="card border-danger border-opacity-25 border mb-3">
    <div class="card-body py-2">
      <form method="post" onsubmit="return confirm('Usunąć zgłoszenie <?= h($ticket['number']) ?>?')">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <button type="submit" name="_delete" class="btn btn-sm btn-outline-danger w-100">
          <i class="bi bi-trash3 me-1"></i>Usuń zgłoszenie
        </button>
      </form>
    </div>
  </div>
  <?php endif; ?>

</div><!-- /col-4 -->
</div><!-- /row -->

<!-- ── Modal: przekazanie do firmy zewnętrznej ──────────────────────────────── -->
<?php if ($is_op): ?>
<div class="modal fade" id="hdExtModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="post">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_set_status" value="1">
        <input type="hidden" name="status" value="przekazane_zewn">
        <div class="modal-header text-bg-dark py-2">
          <h5 class="modal-title fs-6"><i class="bi bi-box-arrow-up-right me-1"></i>Przekazanie do firmy zewnętrznej</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Zamknij"></button>
        </div>
        <div class="modal-body">
          <div class="mb-2">
            <label class="form-label small mb-1">Nazwa firmy zewnętrznej <span class="text-danger">*</span></label>
            <input type="text" name="ext_vendor" class="form-control form-control-sm"
                   value="<?= h($ticket['ext_vendor'] ?? '') ?>" required>
          </div>
          <div class="mb-2">
            <label class="form-label small mb-1">Nr zgłoszenia u firmy</label>
            <input type="text" name="ext_ref" class="form-control form-control-sm"
                   value="<?= h($ticket['ext_ref'] ?? '') ?>" placeholder="opcjonalnie">
          </div>
          <div class="mb-3">
            <label class="form-label small mb-1">Powód / opis przekazania</label>
            <textarea name="ext_reason" class="form-control form-control-sm" rows="3"
                      placeholder="dlaczego zgłoszenie trafia do firmy zewnętrznej"><?= h($ticket['ext_reason'] ?? '') ?></textarea>
          </div>
          <hr class="my-3">
          <div class="form-check mb-2">
            <input class="form-check-input" type="checkbox" name="share_with_vendor" value="1" id="hdShareVendor"
                   onchange="document.getElementById('hdVendorShare').classList.toggle('d-none', !this.checked)">
            <label class="form-check-label small" for="hdShareVendor">
              <i class="bi bi-person-plus me-1"></i>Udostępnij podgląd osobie w firmie (wyślij link e-mailem)
            </label>
          </div>
          <div id="hdVendorShare" class="d-none">
            <input type="email" name="share_email" class="form-control form-control-sm mb-1"
                   placeholder="adres e-mail osoby w firmie">
            <input type="text" name="share_name" class="form-control form-control-sm"
                   placeholder="imię i nazwisko (opcjonalnie)">
            <div class="form-text" style="font-size:.75rem">
              Odbiorca dostanie link do mikropanelu — może podejrzeć zgłoszenie i odpowiadać bez logowania.
            </div>
          </div>
        </div>
        <div class="modal-footer py-2">
          <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="btn btn-sm btn-dark">
            <i class="bi bi-box-arrow-up-right me-1"></i>Oznacz jako przekazane
          </button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($is_op): ?>
<script>
document.querySelectorAll('.hd-tpl-btn').forEach(function (b) {
  b.addEventListener('click', function () {
    var ta = document.getElementById('hdMsgBody');
    if (!ta) return;
    if (ta.value.trim() !== '' && !confirm('Zastąpić obecną treść wybranym szablonem?')) return;
    ta.value = this.dataset.body;
    ta.focus();
  });
});
</script>
<?php endif; ?>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
