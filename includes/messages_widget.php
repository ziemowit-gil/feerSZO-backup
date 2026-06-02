<?php
// ── includes/messages_widget.php ────────────────────────────────────────────
// Wymagane zmienne: $msg_ctx_type, $msg_ctx_id, $msg_contract_type,
//                  $msg_viewer, $msg_viewer_name, $msg_viewer_id, $msg_post_url

msg_mark_read($msg_ctx_type, $msg_ctx_id, $msg_viewer);
$_msgs = msg_thread($msg_ctx_type, $msg_ctx_id);
$_uniq = 'msg_' . $msg_ctx_type . '_' . $msg_ctx_id;
?>
<div class="d-flex flex-column" style="height:100%">
  <!-- Thread -->
  <div class="msg-thread flex-grow-1" id="<?= $_uniq ?>"
       style="max-height:420px;min-height:180px;overflow-y:auto;padding:1rem 1.2rem;background:#f8fafc;border-bottom:1px solid #e8ecf0">

    <?php if (!$_msgs): ?>
    <div class="text-center text-muted py-4">
      <i class="bi bi-chat-dots" style="font-size:2.2rem;opacity:.35"></i>
      <div class="mt-2 small">Brak wiadomości — napisz pierwszą!</div>
    </div>
    <?php else: ?>
    <?php
    $prev_date = null;
    foreach ($_msgs as $_m):
      $_mine  = ($_m['sender_type'] === $msg_viewer);
      $_date  = date('Y-m-d', strtotime($_m['created_at']));
      $_time  = date('H:i', strtotime($_m['created_at']));
    ?>
    <?php if ($_date !== $prev_date): $prev_date = $_date; ?>
    <div class="text-center my-3">
      <span class="badge bg-light text-secondary border" style="font-size:.72rem;font-weight:500">
        <?= date('d.m.Y', strtotime($_m['created_at'])) ?>
      </span>
    </div>
    <?php endif; ?>
    <div class="d-flex mb-2 <?= $_mine ? 'justify-content-end' : 'justify-content-start' ?>">
      <div style="max-width:78%">
        <?php if (!$_mine): ?>
        <div class="small fw-semibold mb-1 ms-1" style="color:#475569"><?= h($_m['sender_name'] ?: 'System') ?></div>
        <?php endif; ?>
        <div class="rounded-3 px-3 py-2 <?= $_mine
              ? 'text-white'
              : 'bg-white border' ?>"
             style="<?= $_mine ? 'background:var(--bs-primary,#0d6efd)' : '' ?>;word-break:break-word;line-height:1.5;font-size:.9rem">
          <?php if ($_m['sender_type'] === 'admin'): ?>
          <?= $_m['body'] /* HTML z CKEditor — zaufany, pochodzi od admina */ ?>
          <?php else: ?>
          <?= nl2br(h($_m['body'])) ?>
          <?php endif; ?>
        </div>
        <div class="small mt-1 <?= $_mine ? 'text-end me-1' : 'ms-1' ?>" style="color:#94a3b8;font-size:.72rem">
          <?php
            $_type_nm = isset($_m['type_id']) && $_m['type_id'] ? msg_type_name((int)$_m['type_id']) : '';
            if ($_type_nm && !$_mine): ?>
          <span class="badge me-1" style="font-size:.62rem;background:#f1f5f9;color:#475569;border:1px solid #cbd5e1">
            <i class="bi bi-tag"></i> <?= h($_type_nm) ?>
          </span>
          <?php endif; ?>
          <?= $_time ?>
          <?php if ($_mine): ?>
          <?= $_m['is_read'] ? ' <i class="bi bi-check2-all" style="color:#22c55e"></i>' : ' <i class="bi bi-check2"></i>' ?>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <?php if (!($msg_hide_form ?? false)): ?>
  <!-- Send form (zwykły textarea — bez CKEditor) -->
  <div class="p-3 border-top">
    <form method="post" action="<?= h($msg_post_url) ?>" id="form-<?= $_uniq ?>">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="_msg_send" value="1">
      <input type="hidden" name="msg_ctx_type" value="<?= h($msg_ctx_type) ?>">
      <input type="hidden" name="msg_ctx_id" value="<?= (int)$msg_ctx_id ?>">
      <input type="hidden" name="msg_contract_type" value="<?= h($msg_contract_type) ?>">
      <div class="d-flex gap-2 align-items-end">
        <textarea name="msg_body" class="form-control form-control-sm"
                  rows="2" placeholder="Napisz wiadomość…"
                  style="resize:none;border-radius:.5rem"
                  required
                  onkeydown="if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();this.closest('form').requestSubmit();}"></textarea>
        <button type="submit" class="btn btn-primary btn-sm px-3" style="height:2.5rem">
          <i class="bi bi-send-fill"></i>
        </button>
      </div>
      <div class="form-text">Enter — wyślij &nbsp;|&nbsp; Shift+Enter — nowa linia</div>
    </form>
  </div>
  <?php endif; ?>
</div>
<script>
(function(){
  var t=document.getElementById('<?= $_uniq ?>');
  if(t) t.scrollTop=t.scrollHeight;
})();
</script>
