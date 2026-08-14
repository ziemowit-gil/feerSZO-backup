<?php
/**
 * admin/ti_admin_msgs.php — Wiadomości prowadzących do kierownictwa.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/ti_messages.php';

require_role('admin');
ti_admin_msg_migrate();

$PAGE_TITLE = 'Wiadomości od prowadzących';
$me = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = $_POST['_op'] ?? '';
    if ($op === 'reply') {
        $msg_id    = (int)($_POST['msg_id'] ?? 0);
        $reply     = trim((string)($_POST['reply_body'] ?? ''));
        $replyBy   = (string)($me['name'] ?? $me['username'] ?? 'Kierownictwo');
        if ($msg_id && $reply !== '') {
            ti_admin_msg_reply($msg_id, $reply, $replyBy);
            ti_admin_msg_mark_read($msg_id);
            flash_set('success', 'Odpowiedź wysłana.');
        }
        header('Location: ti_admin_msgs.php?id=' . $msg_id); exit;
    }
    if ($op === 'mark_read') {
        $msg_id = (int)($_POST['msg_id'] ?? 0);
        if ($msg_id) ti_admin_msg_mark_read($msg_id);
        header('Location: ti_admin_msgs.php'); exit;
    }
}

$msgs    = ti_admin_msg_list_all(200);
$sel_id  = (int)($_GET['id'] ?? 0);
$sel_msg = null;
if ($sel_id) {
    foreach ($msgs as $m) {
        if ((int)$m['id'] === $sel_id) { $sel_msg = $m; break; }
    }
    if ($sel_msg && !$sel_msg['is_read']) ti_admin_msg_mark_read($sel_id);
}

require_once dirname(__DIR__) . '/includes/header.php';
?>
<div class="container-fluid py-3">
<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
  <h1 class="h4 fw-bold mb-0"><i class="bi bi-envelope-exclamation text-primary me-2"></i>Wiadomości od prowadzących</h1>
  <span class="badge bg-secondary ms-1"><?= count($msgs) ?></span>
</div>

<?php echo flash_get_html(); ?>

<div class="row g-3">
  <!-- Lista -->
  <div class="col-md-4">
    <div class="list-group list-group-flush border rounded" style="max-height:80vh;overflow-y:auto">
      <?php if (empty($msgs)): ?>
      <div class="list-group-item text-muted small py-4 text-center">
        <i class="bi bi-envelope-open d-block mb-1" style="font-size:2rem;opacity:.3"></i>Brak wiadomości
      </div>
      <?php else: ?>
      <?php foreach ($msgs as $m):
        $isActive = (int)$m['id'] === $sel_id;
        $ts = $m['created_at'] ? date('d.m.Y H:i', strtotime($m['created_at'])) : '';
        $hasReply = !empty($m['reply_body']);
        $unread   = !$m['is_read'];
      ?>
      <a href="ti_admin_msgs.php?id=<?= (int)$m['id'] ?>"
         class="list-group-item list-group-item-action py-2 px-3 <?= $isActive ? 'active' : '' ?> <?= $unread && !$isActive ? 'fw-semibold' : '' ?>">
        <div class="d-flex justify-content-between align-items-start gap-1">
          <span class="small"><?= h($m['user_name']) ?></span>
          <?php if ($unread): ?><span class="badge bg-danger ms-auto flex-shrink-0" style="font-size:.65rem">nowa</span><?php endif; ?>
        </div>
        <div class="small <?= $isActive ? 'text-white-50' : 'text-muted' ?>" style="font-size:.75rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
          <?= $m['subject'] ? h($m['subject']) : h(mb_substr($m['body'], 0, 60)) ?>
        </div>
        <div class="<?= $isActive ? 'text-white-50' : 'text-muted' ?>" style="font-size:.7rem"><?= $ts ?>
          <?php if ($hasReply): ?><i class="bi bi-reply ms-1" title="Odpowiedziano"></i><?php endif; ?>
        </div>
      </a>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- Treść wiadomości -->
  <div class="col-md-8">
    <?php if ($sel_msg): ?>
    <div class="card mb-3">
      <div class="card-header d-flex align-items-center gap-2 flex-wrap">
        <div>
          <span class="fw-semibold"><?= h($sel_msg['user_name']) ?></span>
          <span class="text-muted small ms-2"><?= $sel_msg['created_at'] ? date('d.m.Y H:i', strtotime($sel_msg['created_at'])) : '' ?></span>
        </div>
        <?php if ($sel_msg['subject']): ?>
        <span class="badge bg-secondary ms-auto"><?= h($sel_msg['subject']) ?></span>
        <?php endif; ?>
        <?php if (!$sel_msg['is_read']): ?>
        <form method="post" class="ms-auto">
          <input type="hidden" name="_token" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="_op" value="mark_read">
          <input type="hidden" name="msg_id" value="<?= (int)$sel_msg['id'] ?>">
          <button type="submit" class="btn btn-sm btn-outline-secondary">Oznacz jako przeczytaną</button>
        </form>
        <?php endif; ?>
      </div>
      <div class="card-body">
        <div class="border rounded-2 p-3 bg-primary bg-opacity-5 mb-3" style="white-space:pre-wrap"><?= h($sel_msg['body']) ?></div>

        <?php if (!empty($sel_msg['reply_body'])): ?>
        <div class="border rounded-2 p-3 bg-success bg-opacity-5">
          <div class="small text-muted mb-1">
            <strong><?= h($sel_msg['reply_by'] ?: 'Kierownictwo') ?></strong> odpowiedział/a
            <?= $sel_msg['replied_at'] ? date('d.m.Y H:i', strtotime($sel_msg['replied_at'])) : '' ?>
          </div>
          <div style="white-space:pre-wrap"><?= h($sel_msg['reply_body']) ?></div>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Formularz odpowiedzi -->
    <div class="card">
      <div class="card-header"><h2 class="h6 fw-bold mb-0"><i class="bi bi-reply me-1"></i>Odpowiedź</h2></div>
      <div class="card-body">
        <form method="post">
          <input type="hidden" name="_token" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="_op" value="reply">
          <input type="hidden" name="msg_id" value="<?= (int)$sel_msg['id'] ?>">
          <div class="mb-3">
            <label class="form-label fw-semibold" for="replyBody">Treść odpowiedzi</label>
            <textarea class="form-control" id="replyBody" name="reply_body" rows="4" required
                      placeholder="Napisz odpowiedź do prowadzącego…"><?= !empty($sel_msg['reply_body']) ? h($sel_msg['reply_body']) : '' ?></textarea>
          </div>
          <button type="submit" class="btn btn-primary"><i class="bi bi-send me-1"></i>Wyślij odpowiedź</button>
        </form>
      </div>
    </div>

    <?php else: ?>
    <div class="d-flex flex-column align-items-center justify-content-center text-muted" style="min-height:300px">
      <i class="bi bi-envelope-open" style="font-size:3rem;opacity:.3"></i>
      <p class="mt-2">Wybierz wiadomość z listy po lewej.</p>
    </div>
    <?php endif; ?>
  </div>
</div>
</div>
<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
