<?php
/**
 * tasks/inbox.php — Skrzynka wiadomości wewnętrznych modułu Zadania
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/tasks.php';
require_once dirname(__DIR__) . '/includes/messages.php';

require_login();
require_module_enabled('tasks_enabled', 'Moduł zadań');

$uid  = (int)(current_user()['id'] ?? 0);
$csrf = csrf_token();

// ── Handler POST ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    auth_start();

    if (!hash_equals($_SESSION['csrf'] ?? '', $body['_csrf'] ?? '')) {
        echo json_encode(['ok' => false, 'error' => 'Nieprawidłowy token CSRF.']); exit;
    }

    $action  = $body['action'] ?? '';
    $u       = current_user();
    $org     = defined('ORG_NAME') ? ORG_NAME : 'System';
    $headers = "From: noreply@".($_SERVER['HTTP_HOST']??'localhost')."\r\n"
             . "Content-Type: text/plain; charset=utf-8\r\n";

    // ── Oznacz jako przeczytane ───────────────────────────────────────────
    if ($action === 'mark_read') {
        task_msg_mark_read($uid, isset($body['task_id']) ? (int)$body['task_id'] : null);
        echo json_encode(['ok' => true]); exit;
    }

    // ── Odpowiedź tekstowa ────────────────────────────────────────────────
    if ($action === 'reply') {
        $task_id   = (int)($body['task_id']   ?? 0);
        $target_id = (int)($body['target_id'] ?? 0);
        $msg_body  = trim($body['body'] ?? '');
        $subject   = trim($body['subject'] ?? 'Re: wiadomość zadaniowa');

        if (!$task_id || !$target_id || !$msg_body) {
            echo json_encode(['ok' => false, 'error' => 'Brak wymaganych danych.']); exit;
        }

        $msg_id = task_msg_send($task_id, $uid, $u['name'], $target_id, $subject, $msg_body);
        $tu = db_one("SELECT email FROM users WHERE id=?", [$target_id]);
        $task = db_one("SELECT title FROM tasks WHERE id=?", [$task_id]);
        if ($tu && !empty($tu['email'])) {
            @mail($tu['email'],
                "[{$org}] Nowa wiadomosc: " . ($task['title'] ?? ''),
                "Wiadomosc od {$u['name']}:\n\n{$msg_body}\n\n---\n" . rtrim(APP_URL,'/').'/tasks/inbox.php',
                $headers);
        }
        echo json_encode(['ok' => true, 'id' => $msg_id]); exit;
    }

    // ── Akceptacja przekazania zadania ────────────────────────────────────
    if ($action === 'accept_transfer') {
        $task_id   = (int)($body['task_id']   ?? 0);
        $sender_id = (int)($body['sender_id'] ?? 0);   // pierwotny nadawca

        if (!$task_id) { echo json_encode(['ok'=>false,'error'=>'Brak task_id.']); exit; }

        // Przyjmij zadanie (claim)
        require_once dirname(__DIR__) . '/includes/tasks.php';
        $task = db_one(
            "SELECT t.*, tl.workspace_id, tl.name AS list_name, tw.name AS ws_name
             FROM tasks t JOIN task_lists tl ON tl.id=t.list_id
             JOIN task_workspaces tw ON tw.id=tl.workspace_id
             WHERE t.id=? AND t.deleted_at IS NULL",
            [$task_id]
        );
        if (!$task) { echo json_encode(['ok'=>false,'error'=>'Zadanie nie istnieje.']); exit; }

        // Wstaw przypisanie (INSERT OR IGNORE — composite PK)
        db()->prepare(
            "INSERT OR IGNORE INTO task_assignments (task_id, user_id, assigned_by, assigned_at)
             VALUES (?, ?, ?, ?)"
        )->execute([$task_id, $uid, $uid, date('Y-m-d H:i:s')]);

        task_log($task_id, $uid, 'assigned', null, $u['name']);

        // Potwierdź nadawcy wiadomością wewnętrzną
        $confirm_body = "Zaakceptowałem/am zadanie: **{$task['title']}**.\nZajmuję się nim.";
        if ($sender_id) {
            task_msg_send($task_id, $uid, $u['name'], $sender_id,
                "Re: transfer:{$task_id}:{$task['title']}", $confirm_body);
            $su = db_one("SELECT email FROM users WHERE id=?", [$sender_id]);
            if ($su && !empty($su['email'])) {
                @mail($su['email'],
                    "[{$org}] Zadanie przyjete: {$task['title']}",
                    "{$u['name']} przyjął/przyjęła przekazane zadanie: \"{$task['title']}\".\n\nZadanie jest teraz przypisane.",
                    $headers);
            }
        }

        echo json_encode(['ok' => true, 'msg' => 'Zadanie przyjęte.']); exit;
    }

    // ── Odrzucenie przekazania zadania ────────────────────────────────────
    if ($action === 'reject_transfer') {
        $task_id   = (int)($body['task_id']   ?? 0);
        $sender_id = (int)($body['sender_id'] ?? 0);
        $reason    = trim($body['reason'] ?? '');

        if (!$task_id) { echo json_encode(['ok'=>false,'error'=>'Brak task_id.']); exit; }

        $task = db_one(
            "SELECT t.title, tw.name AS ws_name
             FROM tasks t JOIN task_lists tl ON tl.id=t.list_id
             JOIN task_workspaces tw ON tw.id=tl.workspace_id
             WHERE t.id=?", [$task_id]
        );
        $task_title = $task['title'] ?? "#{$task_id}";

        // Zapisz w historii
        task_log($task_id, $uid, 'transfer_rejected', $u['name'], null,
            ['reason' => $reason ?: null]);

        // Powiadom nadawcę
        $reject_body  = "Odrzucam prośbę o przejęcie zadania: **{$task_title}**.";
        if ($reason) $reject_body .= "\n\nPowód:\n{$reason}";

        if ($sender_id) {
            task_msg_send($task_id, $uid, $u['name'], $sender_id,
                "Odrzucono: {$task_title}", $reject_body);
            $su = db_one("SELECT email FROM users WHERE id=?", [$sender_id]);
            if ($su && !empty($su['email'])) {
                @mail($su['email'],
                    "[{$org}] Odrzucono przekazanie: {$task_title}",
                    "{$u['name']} odrzucił/a przekazanie zadania \"{$task_title}\"."
                    . ($reason ? "\n\nPowód:\n{$reason}" : '') . "\n",
                    $headers);
            }
        }

        echo json_encode(['ok' => true, 'msg' => 'Odrzucono prośbę.']); exit;
    }

    echo json_encode(['ok' => true]); exit;
}

// ── Pobierz wiadomości ────────────────────────────────────────────────────
$messages = task_msg_inbox($uid, 80);

// Pobierz liderów workspace dla zadań z wątków
$_task_ids = array_unique(array_column($messages, 'context_id'));
$_task_leaders = []; // task_id => ['id'=>..., 'name'=>...]
if ($_task_ids) {
    try {
        $placeholders = implode(',', array_fill(0, count($_task_ids), '?'));
        $_leader_rows = db_all(
            "SELECT t.id AS task_id, u.id AS leader_id, u.name AS leader_name
             FROM tasks t
             JOIN task_workspaces tw ON tw.id = t.workspace_id
             JOIN users u ON u.id = tw.created_by
             WHERE t.id IN ({$placeholders})",
            $_task_ids
        );
        foreach ($_leader_rows as $_lr) {
            $_task_leaders[(int)$_lr['task_id']] = ['id' => (int)$_lr['leader_id'], 'name' => $_lr['leader_name']];
        }
    } catch (\Throwable $e) {}
}

// Pobierz wszystkich uczestników wątków (do wyboru odbiorcy)
$_all_users_map = [];
try {
    $_urows = db_all("SELECT id, name FROM users WHERE is_active=1 ORDER BY name", []);
    foreach ($_urows as $_ur) $_all_users_map[(int)$_ur['id']] = $_ur['name'];
} catch (\Throwable $e) {}

// Pogrupuj wg zadania
$threads = [];
foreach ($messages as $m) {
    $key = (int)$m['context_id'];
    $threads[$key]['task_title'] = $m['task_title'] ?? '(zadanie usunięte)';
    $threads[$key]['task_id']    = $key;
    $threads[$key]['msgs'][]     = $m;
    $threads[$key]['leader']     = $_task_leaders[$key] ?? null;
    if (!$m['is_read'] && (int)$m['recipient_id'] === $uid) {
        $threads[$key]['has_unread'] = true;
    }
}

// Sortuj — wątki z nieprzeczytanymi najpierw, potem wg daty ostatniej
usort($threads, function($a, $b) {
    $ua = !empty($a['has_unread']) ? 1 : 0;
    $ub = !empty($b['has_unread']) ? 1 : 0;
    if ($ua !== $ub) return $ub - $ua;
    $la = end($a['msgs'])['created_at'] ?? '';
    $lb = end($b['msgs'])['created_at'] ?? '';
    return strcmp($lb, $la);
});

$unread_total = task_msg_unread($uid);

// Oznacz wszystkie jako przeczytane po wejściu na stronę
task_msg_mark_read($uid);

$PAGE_TITLE       = 'Skrzynka';
$TASKS_BREADCRUMB = 'Skrzynka';
require_once __DIR__ . '/includes/header_tasks.php';
?>

<style type="text/tailwindcss">
/* ── Layout inbox ────────────────────────────────────────────────── */
.inbox-wrap {
  @apply tw-grid tw-bg-white tw-border tw-border-slate-200 tw-rounded-xl tw-overflow-hidden;
  grid-template-columns: 280px 1fr;
  height: calc(100vh - var(--tsk-topbar-h) - 5rem);
  min-height: 400px;
}
@media (max-width: 768px) {
  .inbox-wrap { @apply tw-grid-cols-1 tw-h-auto; }
  .inbox-thread-panel { @apply tw-hidden; }
  .inbox-thread-panel.open { @apply tw-flex; }
  .inbox-sidebar { @apply tw-border-r-0 tw-border-b tw-border-slate-200; }
}

/* ── Sidebar wątków ──────────────────────────────────────────────── */
.inbox-sidebar {
  @apply tw-border-r tw-border-slate-200 tw-flex tw-flex-col tw-overflow-hidden;
}
.inbox-sidebar-head {
  @apply tw-py-3 tw-px-4 tw-border-b tw-border-slate-100 tw-bg-slate-50 tw-flex tw-items-center tw-justify-between tw-flex-shrink-0;
}
.inbox-sidebar-title {
  @apply tw-text-xs tw-font-bold tw-uppercase tw-tracking-wider tw-text-slate-500 tw-m-0;
}
.inbox-thread-list {
  @apply tw-flex-1 tw-overflow-y-auto;
}
.inbox-thread-item {
  @apply tw-flex tw-items-start tw-gap-[.6rem] tw-py-[.7rem] tw-px-4 tw-border-b tw-border-slate-50 tw-cursor-pointer tw-transition-colors tw-border-l-[3px] tw-border-l-transparent;
}
.inbox-thread-item:hover { @apply tw-bg-slate-50; }
.inbox-thread-item.active { @apply tw-bg-blue-50 tw-border-l-blue-600; }
.inbox-thread-item.unread .inbox-th-title { @apply tw-font-bold tw-text-slate-900; }
.inbox-thread-item:focus-visible { outline: 3px solid var(--tsk-focus) !important; }
.inbox-unread-dot {
  @apply tw-w-2 tw-h-2 tw-rounded-full tw-bg-blue-600 tw-flex-shrink-0 tw-mt-[.45rem];
}
.inbox-th-title {
  @apply tw-text-[.86rem] tw-font-medium tw-text-slate-900 tw-whitespace-nowrap tw-overflow-hidden tw-text-ellipsis tw-flex-1;
}
.inbox-th-preview {
  @apply tw-text-[.74rem] tw-text-slate-400 tw-whitespace-nowrap tw-overflow-hidden tw-text-ellipsis tw-mt-[.1rem];
}
.inbox-th-time { @apply tw-text-[.7rem] tw-text-slate-400 tw-whitespace-nowrap tw-flex-shrink-0; }

/* ── Panel wątku ─────────────────────────────────────────────────── */
.inbox-thread-panel {
  @apply tw-flex tw-flex-col tw-overflow-hidden;
}
.inbox-thread-head {
  @apply tw-py-3 tw-px-[1.1rem] tw-border-b tw-border-slate-100 tw-bg-slate-50 tw-flex-shrink-0;
}
.inbox-thread-task {
  @apply tw-text-[.9rem] tw-font-bold tw-text-slate-900 tw-flex tw-items-center tw-gap-2;
}
.inbox-thread-task a { @apply tw-text-blue-600 tw-text-[.78rem]; }

.inbox-msg-list {
  @apply tw-flex-1 tw-overflow-y-auto tw-py-4 tw-px-[1.1rem] tw-flex tw-flex-col tw-gap-3;
}

/* Dymek wiadomości */
.inbox-bubble {
  @apply tw-flex tw-gap-[.55rem] tw-max-w-[90%];
}
.inbox-bubble.mine { @apply tw-flex-row-reverse tw-self-end; }
.inbox-bubble-av {
  @apply tw-w-[30px] tw-h-[30px] tw-rounded-full tw-flex tw-items-center tw-justify-center tw-text-[.62rem] tw-font-bold tw-text-white tw-flex-shrink-0;
}
.inbox-bubble-body { @apply tw-flex tw-flex-col; }
.inbox-bubble.mine .inbox-bubble-body { @apply tw-items-end; }

.inbox-bubble-text {
  @apply tw-bg-slate-100 tw-py-[.55rem] tw-px-3 tw-text-[.86rem] tw-leading-relaxed tw-text-slate-900 tw-whitespace-pre-wrap tw-break-words tw-max-w-full;
  border-radius: .55rem .55rem .55rem .15rem;
}
.inbox-bubble.mine .inbox-bubble-text {
  @apply tw-bg-blue-100;
  border-radius: .55rem .55rem .15rem .55rem;
  color: #1e3a5f;
}
.inbox-bubble-meta {
  @apply tw-text-[.7rem] tw-text-slate-400 tw-mt-[.2rem] tw-flex tw-items-center tw-gap-[.4rem];
}

/* CTA w dymku — prośba o przejęcie */
.inbox-bubble-cta {
  @apply tw-mt-2 tw-flex tw-gap-2 tw-flex-wrap;
}

/* Pole odpowiedzi */
.inbox-reply-bar {
  @apply tw-py-3 tw-px-[1.1rem] tw-border-t tw-border-slate-200 tw-bg-white tw-flex-shrink-0;
}
.inbox-reply-inner {
  @apply tw-flex tw-gap-[.6rem] tw-items-end;
}
.inbox-reply-ta {
  @apply tw-flex-1 tw-text-[.86rem] tw-rounded-lg tw-py-[.45rem] tw-px-3 tw-resize-none tw-leading-relaxed;
  border: 1.5px solid #e2e8f0; max-height: 120px;
}
.inbox-reply-ta:focus { @apply tw-outline tw-outline-2 tw-outline-blue-600 tw-outline-offset-1 tw-border-transparent; }

/* Stan pusty */
.inbox-empty {
  @apply tw-flex tw-flex-col tw-items-center tw-justify-center tw-flex-1 tw-text-slate-400 tw-text-center tw-p-8;
}
.inbox-empty i { @apply tw-text-3xl tw-block tw-mb-2 tw-opacity-25; }
</style>

<div class="d-flex align-items-center justify-content-between mb-3">
  <div>
    <h1 class="h4 fw-bold mb-0">
      <i class="bi bi-inbox text-primary me-2" aria-hidden="true"></i>Skrzynka wiadomości
    </h1>
    <p class="text-muted small mb-0">Wiadomości wewnętrzne powiązane z zadaniami</p>
  </div>
  <?php if ($unread_total > 0): ?>
  <span class="badge bg-primary fs-6 px-3 py-2"><?= $unread_total ?> nieprzeczytanych</span>
  <?php endif; ?>
</div>

<div class="inbox-wrap" id="inbox-wrap">

  <!-- ── Lista wątków ──────────────────────────────────────────────── -->
  <aside class="inbox-sidebar" aria-label="Wątki wiadomości">
    <div class="inbox-sidebar-head">
      <h2 class="inbox-sidebar-title">
        <i class="bi bi-chat-left-dots me-1" aria-hidden="true"></i>Wątki
      </h2>
      <span class="text-muted" style="font-size:.74rem"><?= count($threads) ?></span>
    </div>
    <div class="inbox-thread-list" role="list">
      <?php if (!$threads): ?>
      <div style="text-align:center;padding:2rem 1rem;color:#94a3b8;font-size:.85rem">
        <i class="bi bi-inbox d-block mb-2" style="font-size:1.5rem;opacity:.3"></i>
        Brak wiadomości.
      </div>
      <?php else: foreach ($threads as $tid => $thread):
        $last_msg  = end($thread['msgs']);
        $unread    = !empty($thread['has_unread']);
        $preview   = mb_substr(strip_tags($last_msg['body'] ?? ''), 0, 60);
        $last_time = substr($last_msg['created_at'] ?? '', 5, 11);
        $sender_is_me = (int)($last_msg['sender_id'] ?? 0) === $uid;
      ?>
      <div class="inbox-thread-item <?= $unread ? 'unread' : '' ?>"
           role="listitem"
           tabindex="0"
           data-tid="<?= $tid ?>"
           aria-label="Wątek: <?= h($thread['task_title']) ?><?= $unread ? ', nieprzeczytane' : '' ?>"
           onclick="inboxOpenThread(<?= $tid ?>)"
           onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();inboxOpenThread(<?= $tid ?>)}">
        <?php if ($unread): ?>
        <span class="inbox-unread-dot" aria-hidden="true"></span>
        <?php else: ?>
        <i class="bi bi-chat-left text-muted" style="font-size:.8rem;margin-top:.3rem;flex-shrink:0" aria-hidden="true"></i>
        <?php endif; ?>
        <div style="flex:1;min-width:0">
          <div class="inbox-th-title"><?= h($thread['task_title']) ?></div>
          <div class="inbox-th-preview">
            <?= $sender_is_me ? 'Ty: ' : '' ?><?= h($preview) ?>
          </div>
        </div>
        <div class="inbox-th-time"><?= h($last_time) ?></div>
      </div>
      <?php endforeach; endif; ?>
    </div>
  </aside>

  <!-- ── Panel wątku ───────────────────────────────────────────────── -->
  <section class="inbox-thread-panel" id="inbox-thread-panel"
           aria-label="Wątek wiadomości" aria-live="polite">
    <div class="inbox-empty" id="inbox-placeholder">
      <i class="bi bi-chat-left-dots" aria-hidden="true"></i>
      <p class="mb-0 fw-semibold">Wybierz wątek z listy</p>
      <p class="small">lub poczekaj na nowe wiadomości</p>
    </div>
  </section>

</div>

<!-- Offcanvas szczegółów zadania -->
<div class="offcanvas offcanvas-end shadow-lg" tabindex="-1" id="taskOffcanvas"
     role="dialog" aria-labelledby="taskOffcanvasLabel" aria-modal="true">
  <div class="offcanvas-header" style="border-bottom:1px solid #e2e8f0;padding:.85rem 1.1rem">
    <h2 class="h6 offcanvas-title fw-bold mb-0" id="taskOffcanvasLabel">
      <i class="bi bi-card-text me-1 text-primary" aria-hidden="true"></i>Szczegóły zadania
    </h2>
    <button type="button" class="btn-close" data-bs-dismiss="offcanvas"
            aria-label="Zamknij szczegóły zadania"></button>
  </div>
  <div class="offcanvas-body p-0 overflow-auto" id="taskOffcanvasBody"
       aria-live="polite" aria-atomic="true">
    <div class="text-center py-5 text-muted">
      <div class="spinner-border spinner-border-sm" role="status">
        <span class="visually-hidden">Ładowanie…</span>
      </div>
    </div>
  </div>
</div>

<script>
const CSRF     = <?= json_encode($csrf) ?>;
const BASE     = <?= json_encode(rtrim(APP_URL,'/')) ?>;
const ME_ID    = <?= (int)$uid ?>;
const ME_NAME  = <?= json_encode(current_user()['name'] ?? '') ?>;
const ALL_USERS = <?= json_encode($_all_users_map, JSON_UNESCAPED_UNICODE) ?>;

// Dane wątków przekazane z PHP
const THREADS  = <?= json_encode(array_values($threads), JSON_UNESCAPED_UNICODE) ?>;

let _activeTask = 0;

function escHtml(s) {
    return String(s)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;')
        .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function getInitials(n) {
    return (n||'?').trim().split(/\s+/).slice(0,2).map(p=>p[0]||'').join('').toUpperCase()||'?';
}
function avatarBg(name) {
    const colors = ['#2563eb','#7c3aed','#059669','#dc2626','#d97706','#0891b2'];
    let h = 0;
    for (let i=0; i<(name||'').length; i++) h = (h*31 + name.charCodeAt(i)) & 0xFFFF;
    return colors[h % colors.length];
}

function inboxOpenThread(taskId) {
    _activeTask = taskId;

    // Aktywna klasa na liście
    document.querySelectorAll('.inbox-thread-item').forEach(el => {
        el.classList.toggle('active', parseInt(el.dataset.tid) === taskId);
        if (parseInt(el.dataset.tid) === taskId) {
            el.classList.remove('unread');
            el.querySelector('.inbox-unread-dot')?.remove();
        }
    });

    const thread = THREADS.find(t => t.task_id === taskId);
    if (!thread) return;

    // Oznacz jako przeczytane
    fetch(BASE + '/tasks/inbox.php', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({_csrf:CSRF, action:'mark_read', task_id: taskId})
    }).catch(()=>{});

    renderThread(thread);
}

function renderThread(thread) {
    const panel = document.getElementById('inbox-thread-panel');

    let html = `
    <div class="inbox-thread-head">
      <div class="inbox-thread-task">
        <i class="bi bi-table" aria-hidden="true"></i>
        ${escHtml(thread.task_title)}
        <button class="btn btn-link btn-sm p-0 ms-1" style="font-size:.78rem"
                onclick="taskOpenById(${thread.task_id})"
                aria-label="Otwórz szczegóły zadania">
          Otwórz zadanie <i class="bi bi-box-arrow-up-right ms-1" aria-hidden="true"></i>
        </button>
      </div>
    </div>
    <div class="inbox-msg-list" id="inbox-msg-list" role="log" aria-label="Wiadomości">
    `;

    for (const m of thread.msgs) {
        const mine   = parseInt(m.sender_id) === ME_ID;
        const initials = getInitials(m.sender_name);
        const bg     = avatarBg(m.sender_name);
        const time   = (m.created_at || '').substring(0,16);
        // Czy to prośba o przekazanie (marker w subject)
        const isTransfer = (m.subject || '').startsWith('transfer:');
        const isReply    = (m.subject || '').startsWith('Re: transfer:');
        const isReject   = (m.subject || '').startsWith('Odrzucono:');

        // ID nadawcy tej wiadomości (potrzebne do callbacków)
        const msgSenderId = parseInt(m.sender_id) || 0;

        // Formatuj treść — **bold**
        const formattedBody = escHtml(m.body)
            .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');

        // Czy bieżący wątek już ma decyzję (akceptacja lub odrzucenie po tym transfer)
        const alreadyDecided = thread.msgs.some(mm =>
            mm !== m && (
                (mm.subject || '').startsWith('Re: transfer:') ||
                (mm.subject || '').startsWith('Odrzucono:')
            )
        );

        html += `
        <div class="inbox-bubble ${mine ? 'mine' : ''}" id="bubble-${m.id}">
          <span class="inbox-bubble-av" style="background:${escHtml(bg)}"
                aria-hidden="true">${escHtml(initials)}</span>
          <div class="inbox-bubble-body">
            <div class="inbox-bubble-text`;

        // Koloruj tło specjalnych dymków
        if (isTransfer && !mine)   html += ` style="border-left:3px solid #f59e0b;background:#fffbeb"`;
        if (isReply   && mine)     html += ` style="border-left:3px solid #16a34a;background:#f0fdf4"`;
        if (isReject)              html += ` style="border-left:3px solid #dc2626;background:#fef2f2"`;

        html += `">${formattedBody}`;

        // ── Przyciski Przyjmij / Odrzuć dla odbiorcy prośby o przekazanie ──
        if (isTransfer && !mine && !alreadyDecided) {
            html += `
            <div class="inbox-bubble-cta" id="cta-${m.id}">
              <button class="btn btn-success btn-sm"
                      onclick="inboxAcceptTransfer(${thread.task_id}, ${msgSenderId}, '${m.id}')"
                      aria-label="Zaakceptuj i przejmij zadanie">
                <i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Przyjmij zadanie
              </button>
              <button class="btn btn-outline-danger btn-sm"
                      onclick="inboxRejectTransfer(${thread.task_id}, ${msgSenderId}, '${m.id}')"
                      aria-label="Odrzuć przekazanie zadania">
                <i class="bi bi-x-circle me-1" aria-hidden="true"></i>Odrzuć
              </button>
            </div>`;
        }
        // Status label jeśli decyzja już była
        if (isTransfer && !mine && alreadyDecided) {
            const wasAccepted = thread.msgs.some(mm => (mm.subject||'').startsWith('Re: transfer:'));
            html += `<div class="mt-2" style="font-size:.75rem;color:${wasAccepted?'#16a34a':'#dc2626'}">
              <i class="bi bi-${wasAccepted?'check-circle':'x-circle'} me-1"></i>
              ${wasAccepted ? 'Zadanie zostało przyjęte.' : 'Zadanie zostało odrzucone.'}
            </div>`;
        }

        html += `</div>
            <div class="inbox-bubble-meta">
              <span>${mine ? 'Ty' : escHtml(m.sender_name)}</span>
              <span aria-hidden="true">·</span>
              <time datetime="${escHtml(m.created_at)}">${escHtml(time)}</time>
              ${!m.is_read && !mine ? '<span class="badge bg-primary" style="font-size:.6rem">Nowe</span>' : ''}
            </div>
          </div>
        </div>`;
    }

    // Zbuduj opcje dla selecta odbiorcy
    const leader = thread.leader;
    const defaultTarget = leader ? leader.id : 0;
    // Unikalni rozmówcy (nie ja)
    const partners = {};
    thread.msgs.forEach(m => {
      if (parseInt(m.sender_id) !== ME_ID) partners[parseInt(m.sender_id)] = m.sender_name;
      if (m.recipient_id && parseInt(m.recipient_id) !== ME_ID) partners[parseInt(m.recipient_id)] = ALL_USERS[parseInt(m.recipient_id)] || m.sender_name;
    });
    if (leader) partners[leader.id] = leader.name;
    let recipientOpts = '';
    Object.keys(partners).forEach(pid => {
      const isLeader = leader && parseInt(pid) === leader.id;
      const sel = (parseInt(pid) === defaultTarget) ? ' selected' : '';
      recipientOpts += `<option value="${escHtml(pid)}"${sel}>${escHtml(partners[pid])}${isLeader ? ' (Lider)' : ''}</option>`;
    });
    // Jeśli brak partnerów — dodaj wszystkich
    if (!recipientOpts) {
      Object.keys(ALL_USERS).forEach(uid => {
        if (parseInt(uid) !== ME_ID) {
          recipientOpts += `<option value="${escHtml(uid)}">${escHtml(ALL_USERS[uid])}</option>`;
        }
      });
    }

    html += `</div>
    <div class="inbox-reply-bar" id="inbox-reply-bar">
      <div class="d-flex align-items-center gap-2 mb-2 px-3 pt-2">
        <label class="form-label mb-0 small fw-semibold text-nowrap" for="inbox-reply-to">Do:</label>
        <select id="inbox-reply-to" class="form-select form-select-sm" required aria-label="Odbiorca">
          ${recipientOpts}
        </select>
      </div>
      <div class="inbox-reply-inner">
        <label class="visually-hidden" for="inbox-reply-ta">Odpowiedz</label>
        <textarea id="inbox-reply-ta"
                  class="inbox-reply-ta"
                  rows="2"
                  placeholder="Odpowiedz…"
                  aria-label="Treść odpowiedzi"
                  onkeydown="if((event.ctrlKey||event.metaKey)&&event.key==='Enter'){event.preventDefault();inboxSendReply()}"></textarea>
        <button class="btn btn-primary btn-sm"
                onclick="inboxSendReply()"
                title="Wyślij (Ctrl+Enter)"
                aria-label="Wyślij odpowiedź">
          <i class="bi bi-send" aria-hidden="true"></i>
        </button>
      </div>
      <p class="text-muted mb-0 mt-1 px-3 pb-2" style="font-size:.72rem">
        Ctrl+Enter aby wysłać · odpowiedź dotrze e-mailem i do skrzynki odbiorcy
      </p>
    </div>`;

    panel.innerHTML = html;
    // Scroll do końca
    const list = document.getElementById('inbox-msg-list');
    if (list) list.scrollTop = list.scrollHeight;

    // Focus textarea
    setTimeout(() => document.getElementById('inbox-reply-ta')?.focus(), 50);
}

function inboxSendReply() {
    if (!_activeTask) return;
    const ta       = document.getElementById('inbox-reply-ta');
    const toSel    = document.getElementById('inbox-reply-to');
    const msg      = (ta?.value || '').trim();
    if (!msg) { ta?.focus(); return; }

    const target_id = toSel ? parseInt(toSel.value) : 0;
    if (!target_id) {
        toSel?.focus();
        toSel?.setCustomValidity('Wybierz odbiorcę wiadomości.');
        toSel?.reportValidity();
        return;
    }
    toSel?.setCustomValidity('');

    const thread = THREADS.find(t => t.task_id === _activeTask);
    if (!thread) return;

    // Ustaw subject jako Reply
    const orig_subject = thread.msgs[0]?.subject || 'Wiadomość zadaniowa';
    const subject = orig_subject.startsWith('Re:') ? orig_subject : 'Re: ' + orig_subject;

    ta.disabled = true;

    fetch(BASE + '/tasks/inbox.php', {
        method:  'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({
            _csrf:     CSRF,
            action:    'reply',
            task_id:   _activeTask,
            target_id: target_id,
            subject:   subject,
            body:      msg
        })
    })
    .then(r => r.json())
    .then(r => {
        ta.disabled = false;
        if (r.ok) {
            // Dodaj wiadomość do wątku lokalnie
            thread.msgs.push({
                id:          r.id || Date.now(),
                sender_id:   ME_ID,
                sender_name: ME_NAME,
                recipient_id: target_id,
                subject:     subject,
                body:        msg,
                created_at:  new Date().toISOString().replace('T',' ').substring(0,16),
                is_read:     1,
                context_id:  _activeTask,
            });
            ta.value = '';
            renderThread(thread);
        } else {
            alert(r.error || 'Błąd wysyłania.');
            ta.disabled = false;
        }
    })
    .catch(() => {
        ta.disabled = false;
        alert('Błąd połączenia.');
    });
}

// ── Akcja: Przyjmij przekazanie ───────────────────────────────────────────
window.inboxAcceptTransfer = function(taskId, senderId, bubbleId) {
    const cta = document.getElementById('cta-' + bubbleId);
    if (cta) {
        cta.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Przyjmuję…';
    }

    fetch(BASE + '/tasks/inbox.php', {
        method:  'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({
            _csrf:     CSRF,
            action:    'accept_transfer',
            task_id:   taskId,
            sender_id: senderId
        })
    })
    .then(r => r.json())
    .then(r => {
        if (r.ok) {
            const thread = THREADS.find(t => t.task_id === taskId);
            if (thread) {
                thread.msgs.push({
                    id: Date.now(), sender_id: ME_ID, sender_name: ME_NAME,
                    recipient_id: senderId,
                    subject: 'Re: transfer:' + taskId + ':' + (thread.task_title || ''),
                    body: 'Zaakceptowałem/am i przejmuję to zadanie.',
                    created_at: new Date().toISOString().replace('T',' ').substring(0,16),
                    is_read: 1, context_id: taskId,
                });
                renderThread(thread);
            }
        } else {
            if (cta) cta.innerHTML = '<span class="text-danger small">' + escHtml(r.error || 'Błąd.') + '</span>';
            else alert(r.error || 'Błąd.');
        }
    })
    .catch(() => {
        if (cta) cta.innerHTML = '<span class="text-danger small">Błąd połączenia.</span>';
    });
};

// ── Akcja: Odrzuć przekazanie ─────────────────────────────────────────────
window.inboxRejectTransfer = function(taskId, senderId, bubbleId) {
    const cta = document.getElementById('cta-' + bubbleId);

    // Inline formularz powodu
    if (cta && !cta.querySelector('.reject-form')) {
        cta.innerHTML = `
        <div class="reject-form" style="width:100%">
          <label style="font-size:.78rem;font-weight:600;display:block;margin-bottom:.3rem">
            Powód odrzucenia <span style="font-weight:400;color:#94a3b8">(opcjonalnie)</span>
          </label>
          <textarea id="reject-reason-${bubbleId}"
                    class="form-control form-control-sm mb-2"
                    rows="2" maxlength="300"
                    placeholder="np. brak czasu, nie moje kompetencje…"
                    aria-label="Powód odrzucenia"></textarea>
          <div class="d-flex gap-2">
            <button class="btn btn-danger btn-sm"
                    onclick="inboxDoReject(${taskId}, ${senderId}, '${bubbleId}')"
                    aria-label="Potwierdź odrzucenie">
              <i class="bi bi-x-circle me-1"></i>Odrzuć
            </button>
            <button class="btn btn-outline-secondary btn-sm"
                    onclick="inboxCancelReject('${bubbleId}', ${taskId}, ${senderId})"
                    aria-label="Anuluj odrzucenie">
              Anuluj
            </button>
          </div>
        </div>`;
        document.getElementById('reject-reason-' + bubbleId)?.focus();
        return;
    }
    inboxDoReject(taskId, senderId, bubbleId);
};

window.inboxCancelReject = function(bubbleId, taskId, senderId) {
    const cta = document.getElementById('cta-' + bubbleId);
    if (cta) {
        cta.innerHTML = `
        <button class="btn btn-success btn-sm"
                onclick="inboxAcceptTransfer(${taskId}, ${senderId}, '${bubbleId}')"
                aria-label="Zaakceptuj i przejmij zadanie">
          <i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Przyjmij zadanie
        </button>
        <button class="btn btn-outline-danger btn-sm"
                onclick="inboxRejectTransfer(${taskId}, ${senderId}, '${bubbleId}')"
                aria-label="Odrzuć przekazanie zadania">
          <i class="bi bi-x-circle me-1" aria-hidden="true"></i>Odrzuć
        </button>`;
    }
};

window.inboxDoReject = function(taskId, senderId, bubbleId) {
    const reason = document.getElementById('reject-reason-' + bubbleId)?.value?.trim() || '';
    const cta    = document.getElementById('cta-' + bubbleId);
    if (cta) cta.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Odrzucam…';

    fetch(BASE + '/tasks/inbox.php', {
        method:  'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({
            _csrf:     CSRF,
            action:    'reject_transfer',
            task_id:   taskId,
            sender_id: senderId,
            reason:    reason
        })
    })
    .then(r => r.json())
    .then(r => {
        if (r.ok) {
            const thread = THREADS.find(t => t.task_id === taskId);
            if (thread) {
                const rej_body = 'Odrzucam przekazanie tego zadania.'
                    + (reason ? '\n\nPowód:\n' + reason : '');
                thread.msgs.push({
                    id: Date.now(), sender_id: ME_ID, sender_name: ME_NAME,
                    recipient_id: senderId,
                    subject: 'Odrzucono: ' + (thread.task_title || ''),
                    body: rej_body,
                    created_at: new Date().toISOString().replace('T',' ').substring(0,16),
                    is_read: 1, context_id: taskId,
                });
                renderThread(thread);
            }
        } else {
            if (cta) cta.innerHTML = '<span class="text-danger small">' + escHtml(r.error || 'Błąd.') + '</span>';
        }
    })
    .catch(() => {
        if (cta) cta.innerHTML = '<span class="text-danger small">Błąd połączenia.</span>';
    });
};

window.taskOpenById = function(id) {
    const body = document.getElementById('taskOffcanvasBody');
    body.innerHTML = '<div class="text-center py-5 text-muted">'
        + '<div class="spinner-border spinner-border-sm" role="status">'
        + '<span class="visually-hidden">Ładowanie…</span></div></div>';
    bootstrap.Offcanvas.getOrCreateInstance(
        document.getElementById('taskOffcanvas')
    ).show();
    fetch(BASE + '/tasks/detail.php?id=' + id)
        .then(r => r.text())
        .then(html => {
            body.innerHTML = '';
            body.appendChild(document.createRange().createContextualFragment(html));
        })
        .catch(() => {
            body.innerHTML = '<div class="alert alert-danger m-3">Błąd ładowania.</div>';
        });
};

// Auto-otwórz pierwszy wątek z nieprzeczytanymi
document.addEventListener('DOMContentLoaded', () => {
    const first = THREADS.find(t => t.has_unread) || THREADS[0];
    if (first) inboxOpenThread(first.task_id);
    startPolling();
});

// ── Dynamiczne polling co 20 sekund ──────────────────────────────────────
let _pollLatest  = <?= json_encode(
    !empty($messages) ? max(array_column($messages,'created_at')) : date('Y-m-d H:i:s')
) ?>;
let _pollTimer   = null;
let _pollPaused  = false;  // pauza gdy karta nieaktywna

function startPolling() {
    clearInterval(_pollTimer);
    _pollTimer = setInterval(doPoll, 20000);
    // Pauza gdy karta w tle, wznowienie gdy wraca
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            _pollPaused = true;
        } else {
            _pollPaused = false;
            doPoll();  // natychmiastowa aktualizacja po powrocie
        }
    });
}

function doPoll() {
    if (_pollPaused) return;
    fetch(BASE + '/tasks/api/inbox_poll.php?since=' + encodeURIComponent(_pollLatest))
        .then(r => r.json())
        .then(data => {
            if (!data.ok) return;

            // Zaktualizuj licznik nieprzeczytanych w sidebarze
            updateUnreadBadge(data.unread);

            if (!data.updated_threads || !data.updated_threads.length) return;

            _pollLatest = data.latest || _pollLatest;

            // Dla każdego zaktualizowanego wątku
            data.updated_threads.forEach(upd => {
                const idx = THREADS.findIndex(t => t.task_id === upd.task_id);

                if (idx >= 0) {
                    // Zaktualizuj dane wątku
                    THREADS[idx].msgs        = upd.msgs;
                    THREADS[idx].has_unread  = upd.has_unread;
                    THREADS[idx].last_body   = upd.last_body;
                    THREADS[idx].last_at     = upd.last_at;
                    // Jeśli to aktywny wątek — odśwież widok wiadomości
                    if (_activeTask === upd.task_id) {
                        renderThread(THREADS[idx]);
                    }
                } else {
                    // Nowy wątek — dodaj na górę
                    THREADS.unshift(upd);
                }
                // Zaktualizuj pozycję w liście wątków
                refreshThreadListItem(upd);
            });

            // Powiadomienie dla wiadomości nie z aktywnego wątku
            const foreignNew = data.updated_threads.filter(u =>
                u.task_id !== _activeTask &&
                u.msgs.some(m => !m.is_read && parseInt(m.recipient_id) === ME_ID)
            );
            if (foreignNew.length) {
                showToast(
                    foreignNew.length === 1
                        ? 'Nowa wiadomość: ' + foreignNew[0].task_title
                        : foreignNew.length + ' nowych wiadomości'
                );
            }
        })
        .catch(() => {/* sieć niedostępna — ignoruj */ });
}

function updateUnreadBadge(count) {
    // Badge w sidebarze modułu (link skrzynki)
    const badges = document.querySelectorAll('.tsk-nav-badge');
    badges.forEach(b => {
        if (b.closest('a')?.href?.includes('inbox')) {
            b.textContent = count || '';
            b.style.display = count ? '' : 'none';
        }
    });
    // Tytuł karty
    const base = 'Skrzynka — Zadania';
    document.title = count > 0 ? `(${count}) ${base}` : base;
}

function refreshThreadListItem(upd) {
    const existing = document.querySelector(`.inbox-thread-item[data-tid="${upd.task_id}"]`);
    const list     = document.querySelector('.inbox-thread-list');
    if (!list) return;

    const isActive  = _activeTask === upd.task_id;
    const hasUnread = upd.has_unread && !isActive;
    const preview   = (upd.last_body || '').replace(/\*\*(.+?)\*\*/g,'$1').substring(0, 55);
    const time      = (upd.last_at || '').substring(5, 16);

    const html = `
    <div class="inbox-thread-item ${hasUnread ? 'unread' : ''} ${isActive ? 'active' : ''}"
         role="listitem" tabindex="0"
         data-tid="${upd.task_id}"
         aria-label="Wątek: ${escHtml(upd.task_title)}${hasUnread ? ', nieprzeczytane' : ''}"
         onclick="inboxOpenThread(${upd.task_id})"
         onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();inboxOpenThread(${upd.task_id})}">
      ${hasUnread
          ? '<span class="inbox-unread-dot" aria-hidden="true"></span>'
          : '<i class="bi bi-chat-left text-muted" style="font-size:.8rem;margin-top:.3rem;flex-shrink:0" aria-hidden="true"></i>'}
      <div style="flex:1;min-width:0">
        <div class="inbox-th-title">${escHtml(upd.task_title)}</div>
        <div class="inbox-th-preview">${upd.last_mine ? 'Ty: ' : ''}${escHtml(preview)}</div>
      </div>
      <div class="inbox-th-time">${escHtml(time)}</div>
    </div>`;

    if (existing) {
        existing.outerHTML = html;
    } else {
        // Wstaw na górę listy
        list.insertAdjacentHTML('afterbegin', html);
    }
}

// Toast powiadomień
function showToast(msg) {
    let container = document.getElementById('inbox-toast-container');
    if (!container) {
        container = document.createElement('div');
        container.id = 'inbox-toast-container';
        container.style.cssText = 'position:fixed;bottom:1.5rem;right:1.5rem;z-index:9999;display:flex;flex-direction:column;gap:.5rem';
        document.body.appendChild(container);
    }
    const toast = document.createElement('div');
    toast.setAttribute('role', 'status');
    toast.setAttribute('aria-live', 'polite');
    toast.style.cssText = 'background:#0f172a;color:#fff;border-radius:.55rem;padding:.6rem 1rem;'
        + 'font-size:.84rem;font-weight:500;box-shadow:0 4px 16px rgba(0,0,0,.25);'
        + 'display:flex;align-items:center;gap:.5rem;max-width:280px;'
        + 'animation:toastIn .2s ease';
    toast.innerHTML = '<i class="bi bi-chat-fill text-success"></i>' + escHtml(msg);
    container.appendChild(toast);
    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transition = 'opacity .3s';
        setTimeout(() => toast.remove(), 320);
    }, 4000);
}
</script>
<style>
@keyframes toastIn {
  from { opacity:0; transform:translateY(8px); }
  to   { opacity:1; transform:translateY(0); }
}
</style>

<?php require_once __DIR__ . '/includes/footer_tasks.php'; ?>
