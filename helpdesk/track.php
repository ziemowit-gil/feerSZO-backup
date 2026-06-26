<?php
/**
 * helpdesk/track.php — Publiczny mikropanel zgłoszenia (dostęp po tokenie).
 *
 * Bez logowania. Pozwala zgłaszającemu podejrzeć zgłoszenie, wątek odpowiedzi
 * i dopisać kolejną wiadomość (kontynuacja). Link dołączany do maili helpdesku.
 * Strona jest samodzielna — celowo NIE używa includes/header.php, aby pominąć
 * przekierowania konsentu/przestoju przeznaczone dla zalogowanych.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/helpdesk.php';
helpdesk_migrate();

$org   = defined('ORG_NAME') ? ORG_NAME : 'Helpdesk';
$token = trim($_GET['t'] ?? $_POST['t'] ?? '');

$ticket = null;
if ($token !== '' && preg_match('/^[a-f0-9]{16,64}$/', $token)) {
    $ticket = db_one(
        "SELECT t.*, op.name AS assigned_name
         FROM helpdesk_tickets t
         LEFT JOIN users op ON op.id = t.assigned_to
         WHERE t.access_token = ?", [$token]
    );
}

// ── Dodanie odpowiedzi (kontynuacja) ──────────────────────────────────────────
if ($ticket && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_reply'])) {
    csrf_check();
    $body = hd_sanitize_body(trim($_POST['msg_body'] ?? ''));
    if ($ticket['status'] === 'zamknięte') {
        flash_set('warning', 'Zgłoszenie jest zamknięte — nie można dodać odpowiedzi.');
    } elseif ($body === '') {
        flash_set('danger', 'Treść odpowiedzi nie może być pusta.');
    } else {
        db_insert('helpdesk_messages', [
            'ticket_id'   => (int)$ticket['id'],
            'user_id'     => $ticket['requester_id'] ?: null,
            'user_name'   => $ticket['requester_name'] ?: 'Zgłaszający',
            'body'        => $body,
            'is_internal' => 0,
        ]);
        db_update('helpdesk_tickets', ['updated_at' => date('Y-m-d H:i:s')], (int)$ticket['id']);
        // Odpowiedź zgłaszającego ożywia zgłoszenie czekające na jego reakcję.
        if (in_array($ticket['status'], ['oczekuje', 'rozwiązane'], true)) {
            db_update('helpdesk_tickets', ['status' => 'otwarte', 'updated_at' => date('Y-m-d H:i:s')], (int)$ticket['id']);
        }
        $fresh = db_one("SELECT t.*, op.email AS assigned_email FROM helpdesk_tickets t
                         LEFT JOIN users op ON op.id=t.assigned_to WHERE t.id=?", [(int)$ticket['id']]);
        hd_notify_new_message($fresh ?? $ticket, [
            'user_id'     => (int)($ticket['requester_id'] ?? 0),
            'user_name'   => $ticket['requester_name'] ?: 'Zgłaszający',
            'body'        => $body,
            'is_internal' => 0,
        ]);
        flash_set('success', 'Dziękujemy — Twoja odpowiedź została dodana do zgłoszenia.');
    }
    header('Location: track.php?t=' . urlencode($token));
    exit;
}

$messages = $atts = [];
if ($ticket) {
    $messages = db_all("SELECT * FROM helpdesk_messages WHERE ticket_id=? AND is_internal=0 ORDER BY created_at ASC", [(int)$ticket['id']]);
    $atts     = db_all("SELECT * FROM helpdesk_attachments WHERE ticket_id=? ORDER BY uploaded_at ASC", [(int)$ticket['id']]);
}
?><!doctype html>
<html lang="pl"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= $ticket ? h($ticket['number']) . ' — ' : '' ?>Podgląd zgłoszenia · <?= h($org) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
  body { background:#f1f3f5; }
  .hd-wrap { max-width:720px; }
  .hd-msg { border-radius:10px; padding:14px 18px; margin-bottom:12px; }
  .hd-msg-user { background:#EEF4FF; border-left:3px solid #2563EB; }
  .hd-msg-op   { background:#F0FDF4; border-left:3px solid #16A34A; }
  .hd-body { white-space:pre-wrap; font-size:.92rem; }
  .hd-body.hd-clamp { max-height:11em; overflow:hidden;
    -webkit-mask-image:linear-gradient(180deg,#000 70%,transparent);
            mask-image:linear-gradient(180deg,#000 70%,transparent); }
  .hd-body.hd-expanded { max-height:none; -webkit-mask-image:none; mask-image:none; }
</style>
</head><body>
<div class="container hd-wrap py-4">

  <!-- Baner marki -->
  <div class="d-flex align-items-center gap-2 mb-3 pb-3 border-bottom">
    <div class="d-flex align-items-center justify-content-center rounded-circle text-white flex-shrink-0"
         style="width:40px;height:40px;background:linear-gradient(135deg,#1e40af,#2563EB)">
      <i class="bi bi-headset"></i>
    </div>
    <div class="lh-sm">
      <div class="fw-bold"><?= h($org) ?></div>
      <div class="text-muted" style="font-size:.78rem">Helpdesk IT · podgląd zgłoszenia</div>
    </div>
  </div>

<?php if (!$ticket): ?>
  <div class="text-center py-5">
    <i class="bi bi-question-circle text-secondary" style="font-size:3rem"></i>
    <h1 class="h4 fw-bold mt-3">Nie znaleziono zgłoszenia</h1>
    <p class="text-secondary">Link jest nieprawidłowy lub wygasł. Skorzystaj z najnowszej wiadomości e-mail
      dotyczącej Twojego zgłoszenia albo skontaktuj się z helpdeskiem.</p>
  </div>
<?php else: ?>

  <!-- Nagłówek -->
  <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
    <span class="font-monospace fw-bold text-muted"><?= h($ticket['number']) ?></span>
    <?= hd_status_badge($ticket['status']) ?>
    <?= hd_priority_badge($ticket['priority']) ?>
    <span class="badge bg-light text-dark border"><?= h(HD_CATEGORIES[$ticket['category']] ?? $ticket['category']) ?></span>
  </div>
  <h1 class="h4 fw-bold mb-1"><?= h($ticket['title']) ?></h1>
  <div class="text-muted small mb-3">
    Zgłoszono przez <strong><?= h($ticket['requester_name']) ?></strong>
    · <?= date('d.m.Y H:i', strtotime($ticket['created_at'])) ?>
    <?= $ticket['assigned_name'] ? ' · Opiekun: <strong>' . h($ticket['assigned_name']) . '</strong>' : '' ?>
  </div>

  <?= flash_html() ?>

  <!-- Firma zewnętrzna -->
  <?php if ($ticket['status'] === 'przekazane_zewn' || !empty($ticket['ext_vendor'])): ?>
  <div class="alert alert-dark py-2 small">
    <i class="bi bi-box-arrow-up-right me-1"></i>
    Zgłoszenie zostało przekazane do firmy zewnętrznej<?= !empty($ticket['ext_vendor']) ? ': <strong>' . h($ticket['ext_vendor']) . '</strong>' : '' ?>.
    <?php if (!empty($ticket['ext_ref'])): ?><br>Nr zgłoszenia u firmy: <span class="font-monospace"><?= h($ticket['ext_ref']) ?></span><?php endif; ?>
    <?php if (!empty($ticket['ext_reason'])): ?><br><span class="text-muted"><?= nl2br(h($ticket['ext_reason'])) ?></span><?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- Wątek -->
  <div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
      <?php
        $total        = count($messages);
        $recent_keep  = 3;
        $hidden_count = ($total > $recent_keep + 1) ? $total - $recent_keep : 0;
      ?>
      <?php if ($hidden_count): ?>
      <div class="text-center mb-2">
        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="hdToggleOlder(this)">
          <i class="bi bi-chevron-down me-1"></i>Pokaż wcześniejsze wiadomości (<?= $hidden_count ?>)
        </button>
      </div>
      <?php endif; ?>
      <?php foreach ($messages as $i => $m):
        $is_req    = (int)$m['user_id'] === (int)$ticket['requester_id'] || (string)$m['user_name'] === (string)$ticket['requester_name'];
        $msg_class = $is_req ? 'hd-msg-user' : 'hd-msg-op';
        $msg_atts  = array_filter($atts, fn($a) => (int)$a['message_id'] === (int)$m['id']);
        $long      = mb_strlen($m['body']) > 600 || substr_count($m['body'], "\n") > 10;
        if ($hidden_count && $i === 0)             echo '<div id="hdOlder" class="d-none">';
        if ($hidden_count && $i === $hidden_count)  echo '</div>';
      ?>
      <div class="hd-msg <?= $msg_class ?>">
        <div class="d-flex justify-content-between align-items-start mb-1">
          <strong><?= h($m['user_name'] ?: 'System') ?></strong>
          <small class="text-muted"><?= date('d.m.Y H:i', strtotime($m['created_at'])) ?></small>
        </div>
        <div class="hd-body<?= $long ? ' hd-clamp' : '' ?>"><?= nl2br(h($m['body'])) ?></div>
        <?php if ($long): ?>
        <button type="button" class="btn btn-link btn-sm p-0 mt-1" style="font-size:.8rem" onclick="hdToggleBody(this)">Pokaż całość</button>
        <?php endif; ?>
        <?php if ($msg_atts): ?>
        <div class="mt-2 d-flex flex-wrap gap-1">
          <?php foreach ($msg_atts as $a): ?>
          <span class="badge bg-light text-dark border" style="font-size:.78rem">
            <i class="bi bi-paperclip me-1"></i><?= h($a['original_name']) ?>
          </span>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>

      <?php if (!$messages): ?>
      <p class="text-muted small mb-0">Brak widocznych wiadomości.</p>
      <?php endif; ?>
    </div>
  </div>

  <!-- Odpowiedź (kontynuacja) -->
  <?php if ($ticket['status'] !== 'zamknięte'): ?>
  <div class="card border-0 shadow-sm">
    <div class="card-header py-2 fw-semibold" style="font-size:.85rem">
      <i class="bi bi-reply me-1"></i>Dodaj odpowiedź
    </div>
    <div class="card-body">
      <form method="post">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="t" value="<?= h($token) ?>">
        <textarea name="msg_body" class="form-control mb-2" rows="4" required
                  placeholder="Napisz wiadomość do helpdesku…"></textarea>
        <button type="submit" name="_reply" value="1" class="btn btn-primary btn-sm">
          <i class="bi bi-send me-1"></i>Wyślij odpowiedź
        </button>
      </form>
    </div>
  </div>
  <?php else: ?>
  <div class="alert alert-secondary py-2 small mb-0">
    <i class="bi bi-lock me-1"></i>Zgłoszenie jest zamknięte. Jeśli sprawa powróciła, utwórz nowe zgłoszenie lub odpowiedz na wiadomość e-mail z helpdesku.
  </div>
  <?php endif; ?>

  <p class="text-center text-muted mt-4 mb-0" style="font-size:.8rem">
    <?= h($org) ?> · Helpdesk IT
  </p>

<?php endif; ?>

</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function hdToggleOlder(btn){
  var o=document.getElementById('hdOlder'); if(!o) return;
  var hidden=o.classList.toggle('d-none');
  btn.innerHTML = hidden
    ? '<i class="bi bi-chevron-down me-1"></i>Pokaż wcześniejsze wiadomości'
    : '<i class="bi bi-chevron-up me-1"></i>Ukryj wcześniejsze wiadomości';
}
function hdToggleBody(btn){
  var b=btn.previousElementSibling; if(!b) return;
  var exp=b.classList.toggle('hd-expanded');
  btn.textContent = exp ? 'Zwiń' : 'Pokaż całość';
}
</script>
</body></html>
