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
// Sesja przed jakimkolwiek wyjściem — flash_get()/csrf_token() niżej w HTML startowały ją za późno
// („session_name(): … headers have already been sent” w środku strony).
auth_start();
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
        hd_redmine_push_note((int)$ticket['id'], $body, (int)($ticket['requester_id'] ?? 0) ?: null, false); // → Redmine
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
/** Treść wiadomości: HTML z edytora (oczyszczony ponownie przy wyświetleniu) albo zwykły tekst z podziałem linii. */
function _tr_body(string $body): string {
    return strpos($body, '<') !== false ? hd_sanitize_body($body) : nl2br(h($body));
}
function _tr_dt(?string $v): string {
    return $v ? date('d.m.Y H:i', strtotime($v)) : '—';
}
$st  = $ticket ? (HD_STATUSES[$ticket['status']] ?? ['label' => $ticket['status']]) : null;
$pr  = $ticket ? (HD_PRIORITIES[$ticket['priority']] ?? ['label' => $ticket['priority']]) : null;
$closed = $ticket && $ticket['status'] === 'zamknięte';
$fl = $ticket ? flash_get() : null;
?><!doctype html>
<html lang="pl"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= $ticket ? h($ticket['number']) . ' — ' : '' ?>Podgląd zgłoszenia · <?= h($org) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
/* Płasko, bez cieni i gradientów; kontrast tekstu ≥ 4,5:1 (szary pomocniczy #4b5563 na bieli). */
:root {
  --tr-ink:#1d1d1a; --tr-muted:#4b5563; --tr-line:#e5e7eb; --tr-bg:#f3f4f6; --tr-panel:#fff;
  --tr-accent:#1752bf; --tr-accent-l:#e8f0ff; --tr-req:#e8f0ff; --tr-op:#f3f4f6;
  --tr-ok:#065f46; --tr-ok-l:#ecfdf5; --tr-warn:#7a3300; --tr-warn-l:#fff4e5; --tr-err:#991b1b; --tr-err-l:#fef2f2;
}
body { background:var(--tr-bg); color:var(--tr-ink); font-family:'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif; font-size:16px; }
a { color:var(--tr-accent); }
:focus-visible { outline:3px solid var(--tr-accent); outline-offset:2px; }
.tr-skip { position:absolute; left:1rem; top:-4rem; background:var(--tr-accent); color:#fff; padding:.5rem 1rem; z-index:10; }
.tr-skip:focus { top:.5rem; }

.tr-top { background:var(--tr-ink); color:#fff; border-bottom:4px solid #1e6dff; }
.tr-top-in { max-width:1080px; margin:0 auto; padding:.9rem 1rem; display:flex; align-items:center; gap:.75rem; }
.tr-top-org { font-weight:700; letter-spacing:.02em; }
.tr-top-sub { font-size:.85rem; color:#d1d5db; }

.tr-wrap { max-width:1080px; margin:0 auto; padding:1.5rem 1rem 3rem; }
.tr-head { margin-bottom:1.25rem; }
.tr-num { font-family:ui-monospace,Menlo,Consolas,monospace; font-weight:700; color:var(--tr-accent); font-size:.95rem; }
.tr-title { font-size:1.6rem; font-weight:700; line-height:1.25; margin:.25rem 0 .5rem; }
.tr-head-meta { color:var(--tr-muted); font-size:.95rem; display:flex; flex-wrap:wrap; gap:.25rem 1rem; }
.tr-head-meta strong { color:var(--tr-ink); }

.tr-grid { display:grid; gap:1.25rem; grid-template-columns:1fr; }
@media (min-width: 900px) { .tr-grid { grid-template-columns:minmax(0,1fr) 300px; } .tr-side { order:2; } .tr-main { order:1; } }

.tr-card { background:var(--tr-panel); border:1px solid var(--tr-line); border-radius:8px; }
.tr-card-h { font-size:.8rem; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:var(--tr-muted); padding:.85rem 1rem 0; margin:0; }
.tr-card-b { padding:.75rem 1rem 1rem; }

.tr-dl { display:grid; grid-template-columns:auto 1fr; gap:.45rem .9rem; margin:0; font-size:.95rem; }
.tr-dl dt { color:var(--tr-muted); font-weight:400; }
.tr-dl dd { margin:0; font-weight:600; }

.tr-alert { border-radius:8px; padding:.75rem 1rem; margin-bottom:1rem; font-size:.95rem; border-left:4px solid; }
.tr-alert-ok { background:var(--tr-ok-l); border-color:#008a00; color:var(--tr-ok); }
.tr-alert-warn { background:var(--tr-warn-l); border-color:#c25e00; color:var(--tr-warn); }
.tr-alert-err { background:var(--tr-err-l); border-color:#b91c1c; color:var(--tr-err); }
.tr-alert-info { background:var(--tr-accent-l); border-color:var(--tr-accent); color:#0b3a8a; }

.tr-thread { list-style:none; margin:0; padding:0; }
.tr-msg { display:grid; grid-template-columns:40px minmax(0,1fr); gap:.75rem; padding:1rem; border-top:1px solid var(--tr-line); }
.tr-msg:first-child { border-top:0; }
.tr-av { width:40px; height:40px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:.8rem; font-weight:700; color:#fff; background:#4b5563; }
.tr-msg-req .tr-av { background:var(--tr-accent); }
.tr-msg-meta { display:flex; flex-wrap:wrap; align-items:baseline; gap:.25rem .75rem; margin-bottom:.35rem; }
.tr-msg-who { font-weight:700; }
.tr-msg-role { font-size:.85rem; color:var(--tr-muted); }
.tr-msg-time { font-size:.85rem; color:var(--tr-muted); margin-left:auto; }
.tr-body { line-height:1.6; overflow-wrap:anywhere; }
.tr-body p { margin:0 0 .6rem; } .tr-body p:last-child { margin-bottom:0; }
.tr-body.is-clamped { max-height:12em; overflow:hidden; position:relative; }
.tr-body.is-clamped::after { content:''; position:absolute; left:0; right:0; bottom:0; height:3em; background:linear-gradient(transparent, var(--tr-panel)); }
.tr-atts { margin-top:.6rem; display:flex; flex-wrap:wrap; gap:.4rem 1rem; font-size:.9rem; color:var(--tr-muted); }
.tr-more { border:0; background:none; padding:0; margin-top:.4rem; color:var(--tr-accent); text-decoration:underline; font-size:.9rem; }

.tr-reply label { font-weight:700; margin-bottom:.35rem; }
.tr-reply textarea { border:2px solid #6b7280; border-radius:6px; font-size:1rem; }
.tr-reply textarea:focus { border-color:var(--tr-accent); box-shadow:none; }
.tr-btn { background:var(--tr-accent); border:0; color:#fff; font-weight:700; padding:.6rem 1.2rem; border-radius:6px; }
.tr-btn:hover, .tr-btn:focus-visible { background:var(--tr-ink); color:#fff; }
.tr-btn-ghost { background:#fff; border:2px solid var(--tr-ink); color:var(--tr-ink); font-weight:700; padding:.5rem 1rem; border-radius:6px; width:100%; }
.tr-btn-ghost:hover, .tr-btn-ghost:focus-visible { background:var(--tr-ink); color:#fff; }

.tr-esc { display:flex; justify-content:space-between; align-items:center; gap:.5rem; padding:.45rem 0; border-top:1px solid var(--tr-line); font-size:.95rem; }
.tr-esc:first-child { border-top:0; }
.tr-foot { text-align:center; color:var(--tr-muted); font-size:.85rem; margin-top:2rem; }
.tr-empty { text-align:center; padding:4rem 1rem; }
</style>
</head><body>
<a class="tr-skip" href="#tr-main">Przejdź do treści</a>
<header class="tr-top">
  <div class="tr-top-in">
    <i class="bi bi-headset fs-4" aria-hidden="true"></i>
    <div><div class="tr-top-org"><?= h($org) ?></div><div class="tr-top-sub">Helpdesk IT · podgląd zgłoszenia</div></div>
  </div>
</header>

<main class="tr-wrap" id="tr-main">
<?php if (!$ticket): ?>
  <div class="tr-empty tr-card">
    <h1 class="h4 fw-bold">Nie znaleziono zgłoszenia</h1>
    <p class="mb-0" style="color:var(--tr-muted)">Link jest nieprawidłowy lub wygasł. Skorzystaj z najnowszej wiadomości e-mail albo skontaktuj się z helpdeskiem.</p>
  </div>
<?php else: ?>

  <div class="tr-head">
    <div class="tr-num">Zgłoszenie <?= h($ticket['number']) ?></div>
    <h1 class="tr-title"><?= h($ticket['title']) ?></h1>
    <div class="tr-head-meta">
      <span>Status: <strong><?= h($st['label']) ?></strong></span>
      <span>Priorytet: <strong><?= h($pr['label']) ?></strong></span>
      <span>Zgłoszono: <strong><?= _tr_dt($ticket['created_at']) ?></strong></span>
    </div>
  </div>

  <?php if ($fl): $flc = in_array($fl['type'], ['success','ok'], true) ? 'ok' : ($fl['type'] === 'warning' ? 'warn' : ($fl['type'] === 'danger' ? 'err' : 'info')); ?>
  <div class="tr-alert tr-alert-<?= $flc ?>" role="status"><?= h($fl['msg']) ?></div>
  <?php endif; ?>

  <?php if ($ticket['status'] === 'przekazane_zewn' || !empty($ticket['ext_vendor'])): ?>
  <div class="tr-alert tr-alert-info">
    Zgłoszenie przekazano do firmy zewnętrznej<?= !empty($ticket['ext_vendor']) ? ': <strong>' . h($ticket['ext_vendor']) . '</strong>' : '' ?>.
    <?php if (!empty($ticket['ext_ref'])): ?>Numer u firmy: <span class="font-monospace"><?= h($ticket['ext_ref']) ?></span>.<?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="tr-grid">
    <aside class="tr-side" aria-label="Szczegóły zgłoszenia">
      <section class="tr-card mb-3" aria-labelledby="tr-det-h">
        <h2 class="tr-card-h" id="tr-det-h">Szczegóły</h2>
        <div class="tr-card-b">
          <dl class="tr-dl">
            <dt>Status</dt><dd><?= h($st['label']) ?></dd>
            <dt>Priorytet</dt><dd><?= h($pr['label']) ?></dd>
            <dt>Kategoria</dt><dd><?= h(hd_categories()[$ticket['category']] ?? $ticket['category']) ?></dd>
            <dt>Zgłaszający</dt><dd><?= h($ticket['requester_name'] ?: '—') ?></dd>
            <dt>Opiekun</dt><dd><?= $ticket['assigned_name'] ? h($ticket['assigned_name']) : 'czeka na przypisanie' ?></dd>
            <dt>Utworzono</dt><dd><?= _tr_dt($ticket['created_at']) ?></dd>
            <dt>Zmiana</dt><dd><?= _tr_dt($ticket['updated_at'] ?? null) ?></dd>
          </dl>
        </div>
      </section>

      <section class="tr-card" aria-labelledby="tr-esc-h">
        <h2 class="tr-card-h" id="tr-esc-h">Podbicia</h2>
        <div class="tr-card-b">
          <?php if ($escalations): ?>
            <?php foreach ($escalations as $e): ?>
            <div class="tr-esc">
              <span><span class="font-monospace"><?= h($e['number']) ?></span><br><span style="color:var(--tr-muted);font-size:.85rem"><?= _tr_dt($e['created_at']) ?></span></span>
              <a href="<?= APP_URL ?>/helpdesk/escalation_pdf.php?id=<?= (int)$e['id'] ?>&amp;t=<?= h(urlencode($token)) ?>" target="_blank" rel="noopener">PDF<span class="visually-hidden"> potwierdzenia <?= h($e['number']) ?> (nowe okno)</span></a>
            </div>
            <?php endforeach; ?>
          <?php else: ?>
            <p class="mb-2" style="color:var(--tr-muted);font-size:.95rem">Zgłoszenie nie było podbijane.</p>
          <?php endif; ?>
          <?php if (!$closed): ?>
          <p class="mt-2 mb-2" style="font-size:.9rem;color:var(--tr-muted)">Brak odpowiedzi od dłuższego czasu? Podbicie powiadamia operatorów priorytetowo.</p>
          <button class="tr-btn-ghost" type="button" data-bs-toggle="modal" data-bs-target="#hdEscalateModal"><i class="bi bi-megaphone me-1" aria-hidden="true"></i>Podbij zgłoszenie</button>
          <?php endif; ?>
        </div>
      </section>
    </aside>

    <div class="tr-main">
      <section class="tr-card mb-3" aria-labelledby="tr-thr-h">
        <h2 class="tr-card-h" id="tr-thr-h">Rozmowa (<?= count($messages) ?>)</h2>
        <?php
        $total        = count($messages);
        $recent_keep  = 3;
        $hidden_count = ($total > $recent_keep + 1) ? $total - $recent_keep : 0;
        ?>
        <?php if ($hidden_count): ?>
        <div class="px-3 pt-3"><button type="button" class="tr-more" aria-expanded="false" onclick="hdToggleOlder(this)">Pokaż wcześniejsze wiadomości (<?= $hidden_count ?>)</button></div>
        <?php endif; ?>
        <?php if ($messages): ?>
        <ol class="tr-thread">
          <?php foreach ($messages as $i => $m):
            $is_req   = (int)$m['user_id'] === (int)$ticket['requester_id'] || (string)$m['user_name'] === (string)$ticket['requester_name'];
            $msg_atts = array_filter($atts, fn($a) => (int)$a['message_id'] === (int)$m['id']);
            $plain    = trim(strip_tags($m['body']));
            $long     = mb_strlen($plain) > 700 || substr_count($m['body'], "\n") + substr_count($m['body'], '</p>') > 12;
            $author   = $m['user_name'] ?: 'System';
          ?>
          <li class="tr-msg <?= $is_req ? 'tr-msg-req' : 'tr-msg-op' ?>"<?= $hidden_count && $i < $hidden_count ? ' data-older hidden' : '' ?>>
            <div class="tr-av" aria-hidden="true"><?= h(_tr_initials($author)) ?></div>
            <div>
              <div class="tr-msg-meta">
                <span class="tr-msg-who"><?= h($author) ?></span>
                <span class="tr-msg-role"><?= $is_req ? 'zgłaszający' : 'helpdesk' ?></span>
                <time class="tr-msg-time" datetime="<?= h(date('c', strtotime($m['created_at']))) ?>"><?= _tr_dt($m['created_at']) ?></time>
              </div>
              <div class="tr-body<?= $long ? ' is-clamped' : '' ?>" id="trb<?= (int)$m['id'] ?>"><?= _tr_body((string)$m['body']) ?></div>
              <?php if ($long): ?><button type="button" class="tr-more" aria-expanded="false" aria-controls="trb<?= (int)$m['id'] ?>" onclick="hdToggleBody(this)">Pokaż całość</button><?php endif; ?>
              <?php if ($msg_atts): ?>
              <div class="tr-atts"><?php foreach ($msg_atts as $a): ?><span><i class="bi bi-paperclip" aria-hidden="true"></i> <?= h($a['original_name']) ?></span><?php endforeach; ?></div>
              <?php endif; ?>
            </div>
          </li>
          <?php endforeach; ?>
        </ol>
        <?php else: ?>
        <p class="tr-card-b mb-0" style="color:var(--tr-muted)">Brak wiadomości.</p>
        <?php endif; ?>
      </section>

      <?php if (!$closed): ?>
      <section class="tr-card tr-reply" aria-labelledby="tr-rep-h">
        <h2 class="tr-card-h" id="tr-rep-h">Odpowiedz</h2>
        <form method="post" class="tr-card-b">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="t" value="<?= h($token) ?>">
          <label for="tr-msg-body" class="form-label">Twoja wiadomość do helpdesku</label>
          <textarea id="tr-msg-body" name="msg_body" class="form-control mb-3" rows="5" required></textarea>
          <button type="submit" name="_reply" value="1" class="tr-btn"><i class="bi bi-send me-1" aria-hidden="true"></i>Wyślij odpowiedź</button>
        </form>
      </section>
      <?php else: ?>
      <div class="tr-alert tr-alert-info">Zgłoszenie jest zamknięte. Jeśli problem wrócił, utwórz nowe zgłoszenie albo odpowiedz na wiadomość e-mail z helpdesku.</div>
      <?php endif; ?>
    </div>
  </div>

  <?php if (!$closed): ?>
  <div class="modal fade" id="hdEscalateModal" tabindex="-1" aria-labelledby="hdEscTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <form method="post">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="t" value="<?= h($token) ?>">
          <input type="hidden" name="_escalate" value="1">
          <div class="modal-header">
            <h2 class="modal-title h5 fw-bold" id="hdEscTitle">Podbij zgłoszenie <?= h($ticket['number']) ?></h2>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
          </div>
          <div class="modal-body">
            <p>Podbicie priorytetowo powiadamia operatorów, a Ty dostaniesz PDF z potwierdzeniem.</p>
            <label for="tr-esc-reason" class="form-label fw-bold">Opisz sytuację <span class="fw-normal" style="color:var(--tr-muted)">(nieobowiązkowo)</span></label>
            <textarea id="tr-esc-reason" name="escalate_reason" class="form-control" rows="3" aria-describedby="tr-esc-help"></textarea>
            <p id="tr-esc-help" class="mt-1 mb-0" style="font-size:.9rem;color:var(--tr-muted)">Np. od kiedy czekasz i jak pilna jest sprawa.</p>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-dark" data-bs-dismiss="modal">Anuluj</button>
            <button type="submit" class="tr-btn">Podbij zgłoszenie</button>
          </div>
        </form>
      </div>
    </div>
  </div>
  <?php endif; ?>

<?php endif; ?>
  <p class="tr-foot"><?= h($org) ?> · Helpdesk IT</p>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function hdToggleOlder(btn){
  var items=document.querySelectorAll('[data-older]'); if(!items.length) return;
  var show=items[0].hidden;
  items.forEach(function(li){ li.hidden=!show; });
  btn.setAttribute('aria-expanded', show ? 'true' : 'false');
  btn.textContent = show ? 'Ukryj wcześniejsze wiadomości' : 'Pokaż wcześniejsze wiadomości (' + items.length + ')';
}
function hdToggleBody(btn){
  var b=document.getElementById(btn.getAttribute('aria-controls')); if(!b) return;
  var open=b.classList.toggle('is-clamped')===false;
  btn.setAttribute('aria-expanded', open ? 'true' : 'false');
  btn.textContent = open ? 'Zwiń' : 'Pokaż całość';
}
</script>
</body></html>
