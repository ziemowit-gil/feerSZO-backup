<?php
/**
 * helpdesk/track.php — Publiczny mikropanel zgłoszenia (dostęp po tokenie).
 *
 * Bez logowania. Pozwala zgłaszającemu podejrzeć zgłoszenie, wątek odpowiedzi
 * i dopisać kolejną wiadomość. Link dołączany do maili helpdesku.
 * Strona jest samodzielna — celowo NIE używa includes/header.php.
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

// ── Dodanie odpowiedzi ────────────────────────────────────────────────────────
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

// ── Podbij zgłoszenie — brak reakcji ─────────────────────────────────────────
if ($ticket && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_escalate'])) {
    csrf_check();
    if ($ticket['status'] === 'zamknięte') {
        flash_set('warning', 'Zgłoszenie jest zamknięte — nie można go podbić.');
    } else {
        $reason = trim($_POST['escalate_reason'] ?? '');
        $esc = hd_escalate($ticket, $reason, [
            'id'   => $ticket['requester_id'] ?: null,
            'name' => $ticket['requester_name'] ?: 'Zgłaszający',
        ]);
        flash_set('success', 'Zgłoszenie podbite — brak reakcji, sprawa przechodzi na 3. linię wsparcia. Nr podbicia: ' . $esc['number'] . '. Potwierdzenie PDF poniżej.');
    }
    header('Location: track.php?t=' . urlencode($token));
    exit;
}

$messages = $atts = $escalations = [];
if ($ticket) {
    $messages    = db_all("SELECT * FROM helpdesk_messages WHERE ticket_id=? AND is_internal=0 ORDER BY created_at ASC", [(int)$ticket['id']]);
    $atts        = db_all("SELECT * FROM helpdesk_attachments WHERE ticket_id=? ORDER BY uploaded_at ASC", [(int)$ticket['id']]);
    $escalations = hd_escalations_for_ticket((int)$ticket['id']);
}

function _tr_initials(string $name): string {
    $words = array_values(array_filter(explode(' ', trim($name))));
    if (!$words) return '?';
    if (count($words) === 1) return mb_strtoupper(mb_substr($words[0], 0, 2));
    return mb_strtoupper(mb_substr($words[0], 0, 1) . mb_substr($words[count($words)-1], 0, 1));
}
function _tr_avcolor(string $name): string {
    $colors = ['#6366f1','#8b5cf6','#ec4899','#f59e0b','#10b981','#3b82f6','#ef4444','#06b6d4','#84cc16','#f97316'];
    return $colors[abs(crc32($name)) % count($colors)];
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
:root {
  --hd-accent:#4f46e5;--hd-accent-h:#4338ca;--hd-accent-l:#eef2ff;--hd-accent-m:#e0e7ff;
  --hd-bg:#f6f7fb;--hd-panel:#fff;--hd-bd:#e5e7eb;--hd-bd-l:#f3f4f6;
  --hd-tx:#111827;--hd-tx2:#6b7280;--hd-tx3:#9ca3af;
  --hd-success:#059669;--hd-warn:#d97706;--hd-err:#dc2626;
  --hd-shadow:0 1px 3px rgba(0,0,0,.07),0 1px 2px rgba(0,0,0,.04);
  --hd-shadow-md:0 4px 16px rgba(0,0,0,.08),0 1px 4px rgba(0,0,0,.05);
}
body { background: var(--hd-bg); color: var(--hd-tx); }

/* Brand header */
.tr-brand {
  display: flex; align-items: center; gap: .85rem;
  padding: .9rem 1rem; margin-bottom: 1.25rem;
  background: var(--hd-panel); border-radius: 12px;
  border: 1px solid var(--hd-bd); box-shadow: var(--hd-shadow);
}
.tr-brand-icon {
  width: 42px; height: 42px; border-radius: 50%; flex-shrink: 0;
  background: linear-gradient(135deg, var(--hd-accent) 0%, #7c3aed 100%);
  display: flex; align-items: center; justify-content: center;
  color: #fff; font-size: 1.2rem;
}
.tr-brand-title { font-weight: 800; font-size: .95rem; color: var(--hd-tx); }
.tr-brand-sub   { font-size: .76rem; color: var(--hd-tx3); }

/* Ticket header */
.tr-ticket-head {
  background: var(--hd-panel); border: 1px solid var(--hd-bd);
  border-radius: 12px; padding: 1rem 1.1rem; margin-bottom: .85rem;
  box-shadow: var(--hd-shadow);
}
.tr-ticket-num {
  font-family: monospace; font-size: .72rem; font-weight: 700;
  color: var(--hd-accent); background: var(--hd-accent-l);
  padding: .1rem .4rem; border-radius: 5px; letter-spacing: .03em;
}
.tr-ticket-title { font-size: 1.05rem; font-weight: 800; margin: .3rem 0 .4rem; color: var(--hd-tx); }
.tr-ticket-meta  { font-size: .78rem; color: var(--hd-tx2); display: flex; flex-wrap: wrap; gap: .3rem .6rem; }

/* Side card */
.tr-side-card {
  background: var(--hd-panel); border: 1px solid var(--hd-bd);
  border-radius: 10px; overflow: hidden; margin-bottom: .75rem; box-shadow: var(--hd-shadow);
}
.tr-side-head {
  padding: .5rem .85rem; font-size: .8rem; font-weight: 700;
  background: var(--hd-bg); border-bottom: 1px solid var(--hd-bd-l);
  display: flex; align-items: center; gap: .35rem; color: var(--hd-tx);
}
.tr-side-body { padding: .65rem .85rem; font-size: .8rem; }

/* Messages */
.tr-msg-wrap {
  display: grid; grid-template-columns: 34px 1fr; gap: .65rem;
  margin-bottom: .9rem; align-items: start;
}
.tr-msg-av {
  width: 34px; height: 34px; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-size: .68rem; font-weight: 800; color: #fff; flex-shrink: 0; margin-top: .1rem;
  text-transform: uppercase; letter-spacing: .02em;
}
.tr-msg-av-req { background: linear-gradient(135deg,#0ea5e9,#0284c7); }
.tr-msg-av-op  { background: linear-gradient(135deg,#6366f1,#4f46e5); }
.tr-msg {
  border-radius: 0 10px 10px 10px;
  padding: .7rem .9rem;
}
.tr-msg-user { background: #f0f9ff; border: 1px solid #bae6fd; }
.tr-msg-op   { background: var(--hd-bg); border: 1px solid var(--hd-bd); }
.tr-msg-meta { display: flex; align-items: baseline; gap: .5rem; margin-bottom: .35rem; }
.tr-msg-name { font-size: .8rem; font-weight: 700; color: var(--hd-tx); }
.tr-msg-time { font-size: .71rem; color: var(--hd-tx3); margin-left: auto; }
.tr-body { font-size: .88rem; line-height: 1.55; white-space: pre-wrap; }
.tr-body.hd-clamp {
  max-height: 11em; overflow: hidden;
  -webkit-mask-image: linear-gradient(180deg,#000 70%,transparent);
          mask-image: linear-gradient(180deg,#000 70%,transparent);
}
.tr-body.hd-expanded { max-height: none; -webkit-mask-image: none; mask-image: none; }

/* Reply box */
.tr-reply-box {
  background: var(--hd-panel); border: 1px solid var(--hd-bd);
  border-radius: 12px; overflow: hidden; margin-bottom: .75rem; box-shadow: var(--hd-shadow);
}
.tr-reply-head {
  padding: .55rem .9rem; font-size: .82rem; font-weight: 700;
  background: var(--hd-bg); border-bottom: 1px solid var(--hd-bd-l);
  display: flex; align-items: center; gap: .4rem;
}
.tr-reply-head i { color: var(--hd-accent); }
.tr-reply-body { padding: .85rem .9rem; }

/* Escalation item */
.tr-esc-item {
  display: flex; justify-content: space-between; align-items: center;
  padding: .4rem 0; border-bottom: 1px solid var(--hd-bd-l);
}
.tr-esc-item:last-child { border-bottom: none; }

/* Alerts */
.tr-alert {
  border-radius: 10px; padding: .6rem .85rem; margin-bottom: .75rem;
  font-size: .82rem; border: 1px solid;
}
.tr-alert-info { background: var(--hd-accent-l); border-color: var(--hd-accent-m); color: var(--hd-accent-h); }
.tr-alert-warn { background: #fef3c7; border-color: #fde68a; color: #92400e; }
.tr-alert-ok   { background: #ecfdf5; border-color: #a7f3d0; color: #065f46; }
.tr-alert-err  { background: #fef2f2; border-color: #fecaca; color: #991b1b; }
.tr-alert-dark { background: #f8fafc; border-color: var(--hd-bd); color: var(--hd-tx2); }

/* Empty state */
.tr-empty { text-align: center; padding: 3.5rem 1rem; color: var(--hd-tx3); }
.tr-empty i { font-size: 2.8rem; display: block; margin-bottom: .5rem; opacity: .35; }
</style>
</head><body>
<div class="container py-4" style="max-width:680px">

<!-- Baner marki -->
<div class="tr-brand">
  <div class="tr-brand-icon"><i class="bi bi-headset"></i></div>
  <div>
    <div class="tr-brand-title"><?= h($org) ?></div>
    <div class="tr-brand-sub">Helpdesk IT · podgląd zgłoszenia</div>
  </div>
</div>

<?php if (!$ticket): ?>
<div class="tr-empty">
  <i class="bi bi-question-circle"></i>
  <h2 style="font-size:1.15rem;font-weight:800;color:var(--hd-tx)">Nie znaleziono zgłoszenia</h2>
  <p style="font-size:.85rem">Link jest nieprawidłowy lub wygasł. Skorzystaj z najnowszej wiadomości e-mail lub skontaktuj się z helpdeskiem.</p>
</div>
<?php else: ?>

<!-- Nagłówek zgłoszenia -->
<div class="tr-ticket-head">
  <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
    <span class="tr-ticket-num"><?= h($ticket['number']) ?></span>
    <?= hd_status_badge($ticket['status']) ?>
    <?= hd_priority_badge($ticket['priority']) ?>
    <span class="badge bg-light text-secondary border" style="font-size:.7rem"><?= h(HD_CATEGORIES[$ticket['category']] ?? $ticket['category']) ?></span>
  </div>
  <div class="tr-ticket-title"><?= h($ticket['title']) ?></div>
  <div class="tr-ticket-meta">
    <span><i class="bi bi-person me-1"></i><?= h($ticket['requester_name']) ?></span>
    <span><i class="bi bi-calendar3 me-1"></i><?= date('d.m.Y H:i', strtotime($ticket['created_at'])) ?></span>
    <?php if ($ticket['assigned_name']): ?>
    <span style="color:#10b981"><i class="bi bi-person-check me-1"></i><?= h($ticket['assigned_name']) ?></span>
    <?php else: ?>
    <span style="color:var(--hd-err)"><i class="bi bi-exclamation-circle me-1"></i>Oczekuje na przypisanie</span>
    <?php endif; ?>
  </div>
</div>

<!-- Flash messages -->
<?php $fl = flash_get(); if ($fl): ?>
<div class="tr-alert tr-alert-<?= in_array($fl['type'],['success','ok'],true)?'ok':($fl['type']==='warning'?'warn':('danger'===$fl['type']?'err':'info')) ?>">
  <i class="bi bi-<?= $fl['type']==='success'?'check-circle':($fl['type']==='warning'?'exclamation-triangle':'x-circle') ?> me-1"></i><?= h($fl['msg']) ?>
</div>
<?php endif; ?>

<!-- Firma zewnętrzna -->
<?php if ($ticket['status'] === 'przekazane_zewn' || !empty($ticket['ext_vendor'])): ?>
<div class="tr-alert tr-alert-dark">
  <i class="bi bi-box-arrow-up-right me-1"></i>
  Zgłoszenie przekazane do firmy zewnętrznej<?= !empty($ticket['ext_vendor']) ? ': <strong>' . h($ticket['ext_vendor']) . '</strong>' : '' ?>.
  <?php if (!empty($ticket['ext_ref'])): ?><br>Nr u firmy: <span class="font-monospace" style="font-size:.82rem"><?= h($ticket['ext_ref']) ?></span><?php endif; ?>
</div>
<?php endif; ?>

<!-- Podbicia -->
<div class="tr-side-card">
  <div class="tr-side-head"><i class="bi bi-megaphone" style="color:#f59e0b"></i>Podbicia zgłoszenia</div>
  <div class="tr-side-body">
    <?php if ($escalations): ?>
    <?php foreach ($escalations as $e): ?>
    <div class="tr-esc-item">
      <div>
        <span class="font-monospace" style="font-size:.76rem"><?= h($e['number']) ?></span>
        <div style="font-size:.7rem;color:var(--hd-tx3)"><?= date('d.m.Y H:i', strtotime($e['created_at'])) ?></div>
      </div>
      <a href="<?= APP_URL ?>/helpdesk/escalation_pdf.php?id=<?= (int)$e['id'] ?>&t=<?= h(urlencode($token)) ?>"
         target="_blank" class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size:.76rem">
        <i class="bi bi-file-earmark-pdf me-1"></i>PDF
      </a>
    </div>
    <?php endforeach; ?>
    <?php else: ?>
    <p style="font-size:.78rem;color:var(--hd-tx3);margin:0">Brak podbić tego zgłoszenia.</p>
    <?php endif; ?>
    <?php if ($ticket['status'] !== 'zamknięte'): ?>
    <button class="btn btn-sm w-100 mt-2" style="background:var(--hd-bg);border:1px solid var(--hd-bd);color:var(--hd-tx2);font-size:.8rem;border-radius:7px"
            type="button" data-bs-toggle="modal" data-bs-target="#hdEscalateModal">
      <i class="bi bi-megaphone me-1" style="color:#f59e0b"></i>Podbij — brak reakcji
    </button>
    <?php endif; ?>
  </div>
</div>

<!-- Wątek wiadomości -->
<div style="margin-bottom:.75rem">
  <?php
  $total        = count($messages);
  $recent_keep  = 3;
  $hidden_count = ($total > $recent_keep + 1) ? $total - $recent_keep : 0;
  ?>
  <?php if ($hidden_count): ?>
  <div class="text-center mb-3">
    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="hdToggleOlder(this)" style="font-size:.8rem">
      <i class="bi bi-chevron-down me-1"></i>Pokaż wcześniejsze (<?= $hidden_count ?>)
    </button>
  </div>
  <?php endif; ?>
  <?php foreach ($messages as $i => $m):
    $is_req    = (int)$m['user_id'] === (int)$ticket['requester_id']
              || (string)$m['user_name'] === (string)$ticket['requester_name'];
    $msg_atts  = array_filter($atts, fn($a) => (int)$a['message_id'] === (int)$m['id']);
    $long      = mb_strlen($m['body']) > 600 || substr_count($m['body'], "\n") > 10;
    $author    = $m['user_name'] ?: 'System';
    $initials  = _tr_initials($author);
    $avcolor   = _tr_avcolor($author);
    $av_class  = $is_req ? 'tr-msg-av-req' : 'tr-msg-av-op';
    if ($hidden_count && $i === 0)            echo '<div id="hdOlder" class="d-none">';
    if ($hidden_count && $i === $hidden_count) echo '</div>';
  ?>
  <div class="tr-msg-wrap">
    <div class="tr-msg-av <?= $av_class ?>" style="background:<?= _tr_avcolor($author) ?>" aria-hidden="true"><?= h($initials) ?></div>
    <div>
      <div class="tr-msg-meta">
        <span class="tr-msg-name"><?= h($author) ?></span>
        <span class="tr-msg-time"><?= date('d.m.Y H:i', strtotime($m['created_at'])) ?></span>
      </div>
      <div class="tr-msg <?= $is_req ? 'tr-msg-user' : 'tr-msg-op' ?>">
        <div class="tr-body<?= $long ? ' hd-clamp' : '' ?>"><?= nl2br(h($m['body'])) ?></div>
        <?php if ($long): ?>
        <button type="button" class="btn btn-link btn-sm p-0 mt-1" style="font-size:.78rem" onclick="hdToggleBody(this)">Pokaż całość</button>
        <?php endif; ?>
        <?php if ($msg_atts): ?>
        <div class="mt-2 pt-2 d-flex flex-wrap gap-1" style="border-top:1px solid rgba(0,0,0,.06)">
          <?php foreach ($msg_atts as $a): ?>
          <span class="badge bg-light text-dark border" style="font-size:.75rem">
            <i class="bi bi-paperclip me-1"></i><?= h($a['original_name']) ?>
          </span>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endforeach; ?>

  <?php if (!$messages): ?>
  <div style="text-align:center;padding:2rem;color:var(--hd-tx3);font-size:.85rem">Brak widocznych wiadomości.</div>
  <?php endif; ?>
</div>

<!-- Formularz odpowiedzi -->
<?php if ($ticket['status'] !== 'zamknięte'): ?>
<div class="tr-reply-box">
  <div class="tr-reply-head"><i class="bi bi-reply-fill"></i>Dodaj odpowiedź</div>
  <div class="tr-reply-body">
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="t" value="<?= h($token) ?>">
      <textarea name="msg_body" class="form-control mb-2" rows="4" required
                placeholder="Napisz wiadomość do helpdesku…"
                style="font-size:.88rem;resize:vertical"></textarea>
      <button type="submit" name="_reply" value="1" class="btn btn-primary btn-sm px-3"
              style="background:var(--hd-accent);border-color:var(--hd-accent)">
        <i class="bi bi-send-fill me-1"></i>Wyślij odpowiedź
      </button>
    </form>
  </div>
</div>
<?php else: ?>
<div class="tr-alert tr-alert-dark">
  <i class="bi bi-lock me-1"></i>Zgłoszenie zamknięte. Jeśli sprawa powróciła, utwórz nowe zgłoszenie lub odpowiedz na wiadomość e-mail z helpdesku.
</div>
<?php endif; ?>

<p class="text-center mt-4 mb-0" style="font-size:.75rem;color:var(--hd-tx3)"><?= h($org) ?> · Helpdesk IT</p>

<!-- Modal: podbicie -->
<div class="modal fade" id="hdEscalateModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" style="border-radius:12px;overflow:hidden">
      <form method="post">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="t" value="<?= h($token) ?>">
        <input type="hidden" name="_escalate" value="1">
        <div class="modal-header py-2" style="background:#fef3c7">
          <h5 class="modal-title fs-6 fw-bold" style="color:#92400e"><i class="bi bi-megaphone me-1"></i>Podbij zgłoszenie</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p class="text-muted small">Jeśli od dłuższego czasu nie otrzymujesz odpowiedzi na zgłoszenie
            <strong><?= h($ticket['number']) ?></strong> — formalne podbicie priorytetowo powiadamia operatorów.
            Otrzymasz PDF z potwierdzeniem.</p>
          <div class="mb-2">
            <label class="form-label small mb-1">Opisz sytuację (opcjonalnie)</label>
            <textarea name="escalate_reason" class="form-control form-control-sm" rows="3"
                      placeholder="np. od kiedy czekasz, jak pilna jest sprawa"></textarea>
          </div>
        </div>
        <div class="modal-footer py-2">
          <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="btn btn-sm" style="background:#f59e0b;border:none;color:#fff">
            <i class="bi bi-megaphone me-1"></i>Podbij
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function hdToggleOlder(btn){
  var o=document.getElementById('hdOlder'); if(!o) return;
  var hid=o.classList.toggle('d-none');
  btn.innerHTML=hid
    ?'<i class="bi bi-chevron-down me-1"></i>Pokaż wcześniejsze'
    :'<i class="bi bi-chevron-up me-1"></i>Ukryj wcześniejsze';
}
function hdToggleBody(btn){
  var b=btn.previousElementSibling; if(!b) return;
  btn.textContent=b.classList.toggle('hd-expanded')?'Zwiń':'Pokaż całość';
}
</script>
</body></html>
