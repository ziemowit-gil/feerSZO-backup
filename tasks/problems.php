<?php
/**
 * tasks/problems.php — Zgłoszenia problemów (widok lidera/admina)
 * Pokazuje wszystkie zdarzenia leader_notified w obszarach,
 * gdzie zalogowany użytkownik jest adminem lub editorem.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/tasks.php';
require_once dirname(__DIR__) . '/includes/messages.php';

require_login();
require_module_enabled('tasks_enabled', 'Moduł zadań');

$uid      = (int)(current_user()['id'] ?? 0);
$is_admin = is_admin();
$csrf     = csrf_token();

// Wyznacz obszary, w których user jest liderem (admin/editor)
if ($is_admin) {
    $led_ws = db_all("SELECT id FROM task_workspaces WHERE is_active=1");
    $led_ids = array_column($led_ws, 'id');
} else {
    $led_ws = db_all(
        "SELECT workspace_id AS id FROM task_workspace_members
         WHERE user_id=? AND role IN ('admin','editor')",
        [$uid]
    );
    $led_ids = array_column($led_ws, 'id');
}

if (!$led_ids) {
    // Brak obszarów lidera — pokaż komunikat
    $PAGE_TITLE       = 'Problemy';
    $TASKS_BREADCRUMB = 'Problemy';
    require_once __DIR__ . '/includes/header_tasks.php';
    echo '<div class="alert alert-info">Nie jesteś liderem żadnego obszaru roboczego.</div>';
    require_once __DIR__ . '/includes/footer_tasks.php';
    exit;
}

// ── Oznacz jako rozwiązane (POST) ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    // CSRF
    auth_start();
    $tok = $body['_csrf'] ?? '';
    if (!hash_equals($_SESSION['csrf'] ?? '', $tok)) {
        http_response_code(403);
        echo json_encode(['ok'=>false,'error'=>'Nieprawidłowy token CSRF.']);
        exit;
    }
    $event_id = (int)($body['event_id'] ?? 0);
    $action   = $body['action'] ?? '';

    if ($action === 'resolve' && $event_id) {
        // Sprawdź czy zdarzenie należy do obszaru lidera
        $ev = db_one(
            "SELECT th.*, t.workspace_id FROM task_history th
             JOIN tasks t ON t.id = th.task_id
             WHERE th.id = ? AND th.event_type = 'leader_notified'",
            [$event_id]
        );
        if ($ev && ($is_admin || in_array((int)$ev['workspace_id'], $led_ids, true))) {
            // Dodaj zdarzenie "problem_resolved" do historii
            task_log((int)$ev['task_id'], $uid, 'problem_resolved',
                null, current_user()['name'],
                ['resolved_event_id' => $event_id]);
            // Oznacz oryginalne zdarzenie jako rozwiązane przez metadata
            db()->prepare(
                "UPDATE task_history SET metadata=json_set(COALESCE(metadata,'{}'),'$.resolved',1,'$.resolved_by',?)
                 WHERE id=?"
            )->execute([$uid, $event_id]);
            echo json_encode(['ok'=>true]); exit;
        }
    }

    // ── Odpowiedź wewnętrzna na zgłoszenie ────────────────────────────────
    if ($action === 'reply') {
        $task_id     = (int)($body['task_id']     ?? 0);
        $reporter_id = (int)($body['reporter_id'] ?? 0);
        $msg_body    = trim($body['body']         ?? '');

        if (!$task_id || !$reporter_id || !$msg_body) {
            echo json_encode(['ok'=>false,'error'=>'Brak wymaganych danych.']); exit;
        }

        // Sprawdź dostęp lidera do obszaru zadania
        $task = db_one("SELECT workspace_id, title FROM tasks WHERE id=?", [$task_id]);
        if (!$task || (!$is_admin && !in_array((int)$task['workspace_id'], $led_ids, true))) {
            echo json_encode(['ok'=>false,'error'=>'Brak dostępu.']); exit;
        }

        $actor = current_user();

        // Wyślij wiadomość wewnętrzną
        $msg_id = task_msg_send(
            $task_id,
            $uid,
            $actor['name'],
            $reporter_id,
            'Re: Problem — ' . $task['title'],
            $msg_body
        );

        // E-mail do zgłaszającego
        $reporter = db_one("SELECT name, email FROM users WHERE id=?", [$reporter_id]);
        if ($reporter && !empty($reporter['email'])) {
            $org     = defined('ORG_NAME') ? ORG_NAME : 'System';
            $url     = rtrim(APP_URL,'/') . '/tasks/inbox.php';
            $headers = "From: noreply@".($_SERVER['HTTP_HOST']??'localhost')."\r\n"
                     . "Reply-To: ".($actor['email']??'')."\r\n"
                     . "Content-Type: text/plain; charset=utf-8\r\n";
            @mail(
                $reporter['email'],
                "[{$org}] Odpowiedz lidera: {$task['title']}",
                "{$actor['name']} odpowiedzial/a na Twoje zgloszenie:\n\n{$msg_body}\n\n---\nSkrzynka: {$url}",
                $headers
            );
        }

        echo json_encode([
            'ok'      => true,
            'msg_id'  => $msg_id,
            'sender'  => $actor['name'],
            'sent_at' => date('Y-m-d H:i'),
        ]); exit;
    }

    echo json_encode(['ok'=>false,'error'=>'Brak dostępu.']); exit;
}

// ── Filtr ─────────────────────────────────────────────────────────────────
$filter = $_GET['filter'] ?? 'open'; // open | resolved | all

// Zbuduj listę placeholderów dla IN (?)
$placeholders = implode(',', array_fill(0, count($led_ids), '?'));

// Pobierz zgłoszenia
$base_sql = "
    SELECT th.id AS event_id,
           th.task_id,
           th.user_id AS reporter_id,
           th.to_value AS message_preview,
           th.occurred_at,
           th.metadata,
           t.title   AS task_title,
           t.priority,
           t.completed_at,
           tl.name   AS list_name,
           tw.name   AS ws_name,
           tw.color  AS ws_color,
           u.name    AS reporter_name,
           u.email   AS reporter_email
    FROM task_history th
    JOIN tasks t ON t.id = th.task_id
    JOIN task_lists tl ON tl.id = t.list_id
    JOIN task_workspaces tw ON tw.id = t.workspace_id
    JOIN users u ON u.id = th.user_id
    WHERE th.event_type = 'leader_notified'
      AND t.workspace_id IN ({$placeholders})
";

$params = $led_ids;

if ($filter === 'open') {
    $base_sql .= " AND (th.metadata IS NULL OR json_extract(th.metadata,'$.resolved') IS NULL)";
} elseif ($filter === 'resolved') {
    $base_sql .= " AND json_extract(th.metadata,'$.resolved') = 1";
}

$base_sql .= " ORDER BY th.occurred_at DESC LIMIT 100";

$problems = db_all($base_sql, $params);

// Doczytaj istniejące odpowiedzi wewnętrzne dla każdego problemu
foreach ($problems as &$p) {
    try {
        $p['_replies'] = db_all(
            "SELECT m.id, m.sender_id, m.sender_name, m.body, m.created_at, m.is_read
             FROM messages m
             WHERE m.context_type = 'task'
               AND m.context_id   = ?
               AND m.recipient_id = ?
               AND m.subject LIKE 'Re: Problem%'
             ORDER BY m.created_at ASC",
            [(int)$p['task_id'], (int)$p['reporter_id']]
        );
    } catch (\Throwable $e) {
        $p['_replies'] = [];
    }
}
unset($p);

// Liczniki
$cnt_all      = (int)(db_one("SELECT COUNT(*) AS n FROM task_history th JOIN tasks t ON t.id=th.task_id WHERE th.event_type='leader_notified' AND t.workspace_id IN ({$placeholders})", $led_ids)['n'] ?? 0);
$cnt_open     = (int)(db_one("SELECT COUNT(*) AS n FROM task_history th JOIN tasks t ON t.id=th.task_id WHERE th.event_type='leader_notified' AND t.workspace_id IN ({$placeholders}) AND (th.metadata IS NULL OR json_extract(th.metadata,'$.resolved') IS NULL)", $led_ids)['n'] ?? 0);
$cnt_resolved = $cnt_all - $cnt_open;

$PAGE_TITLE       = 'Problemy';
$TASKS_BREADCRUMB = 'Problemy (' . $cnt_open . ' otwartych)';
require_once __DIR__ . '/includes/header_tasks.php';
?>

<style>
/* ── Karta problemu ─────────────────────────────────────────────────── */
.prob-card {
  background: #fff;
  border: 1.5px solid #e2e8f0;
  border-radius: .65rem;
  margin-bottom: .75rem;
  overflow: hidden;
  transition: box-shadow .12s;
}
.prob-card:hover { box-shadow: 0 3px 12px rgba(0,0,0,.07); }
.prob-card.resolved {
  opacity: .65;
  border-color: #f1f5f9;
}

.prob-card-head {
  display: flex; align-items: center; gap: .75rem;
  padding: .8rem 1rem .6rem;
  border-bottom: 1px solid #f1f5f9;
}
.prob-task-link {
  flex: 1;
  font-weight: 700; font-size: .92rem;
  color: #0f172a; text-decoration: none;
  cursor: pointer;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.prob-task-link:hover { color: #2563eb; text-decoration: underline; }

.prob-ws-chip {
  display: inline-flex; align-items: center; gap: .3rem;
  font-size: .72rem; color: #64748b; flex-shrink: 0;
}
.prob-ws-dot { width: 7px; height: 7px; border-radius: 50%; }

.prob-badge-resolved {
  font-size: .68rem; font-weight: 700;
  padding: .15rem .5rem; border-radius: 2rem;
  background: #dcfce7; color: #15803d;
  flex-shrink: 0; white-space: nowrap;
}
.prob-badge-open {
  font-size: .68rem; font-weight: 700;
  padding: .15rem .5rem; border-radius: 2rem;
  background: #fef9c3; color: #92400e;
  flex-shrink: 0; white-space: nowrap;
}

.prob-card-body {
  padding: .7rem 1rem;
}
.prob-message {
  font-size: .87rem; color: #334155;
  line-height: 1.55; white-space: pre-wrap; word-break: break-word;
  background: #f8fafc; border: 1px solid #e2e8f0;
  border-radius: .4rem; padding: .6rem .8rem;
  margin-bottom: .6rem;
}

.prob-meta {
  display: flex; align-items: center; gap: 1rem;
  flex-wrap: wrap; font-size: .77rem; color: #94a3b8;
}
.prob-meta strong { color: #475569; }

.prob-actions { display: flex; gap: .5rem; align-items: center; margin-top: .65rem; }

/* Filtr pills */
.prob-filter {
  display: flex; gap: .35rem; flex-wrap: wrap; margin-bottom: 1.1rem;
}
.prob-pill {
  display: inline-flex; align-items: center; gap: .3rem;
  padding: .25rem .7rem; border-radius: 2rem;
  font-size: .8rem; font-weight: 600;
  border: 1.5px solid #e2e8f0;
  background: #fff; color: #64748b;
  text-decoration: none; transition: all .12s;
}
.prob-pill:hover { border-color: #94a3b8; }
.prob-pill.active { background: #0f172a; color: #fff; border-color: #0f172a; }
.prob-pill[data-f="open"].active  { background: #d97706; border-color: #d97706; }
.prob-pill[data-f="resolved"].active { background: #16a34a; border-color: #16a34a; }
.prob-pill:focus-visible { outline: 3px solid var(--tsk-focus) !important; }

/* Empty */
.prob-empty {
  text-align: center; padding: 4rem 1rem;
  color: #94a3b8;
}
.prob-empty i { font-size: 2rem; display: block; margin-bottom: .6rem; opacity: .25; }

/* SR announce */
#prob-sr { position:absolute;width:1px;height:1px;padding:0;margin:-1px;
  overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0 }

/* ── Sekcja odpowiedzi lidera ──────────────────────────────────────── */
.prob-replies {
  border-top: 1px solid #f1f5f9;
  background: #f8fafc;
  padding: .7rem 1rem;
}
.prob-reply-bubble {
  display: flex; gap: .55rem; align-items: flex-start;
  margin-bottom: .55rem;
}
.prob-reply-av {
  width: 26px; height: 26px; border-radius: 50%;
  background: #059669; color: #fff;
  display: flex; align-items: center; justify-content: center;
  font-size: .6rem; font-weight: 700; flex-shrink: 0; margin-top: .1rem;
}
.prob-reply-body {
  flex: 1;
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: .15rem .5rem .5rem .5rem;
  padding: .45rem .65rem;
}
.prob-reply-meta {
  display: flex; gap: .5rem; align-items: center;
  font-size: .7rem; color: #94a3b8; margin-bottom: .2rem;
}
.prob-reply-meta strong { color: #0f172a; font-size: .78rem; }
.prob-reply-text {
  font-size: .84rem; color: #334155;
  white-space: pre-wrap; word-break: break-word; line-height: 1.5;
}

/* Pole odpowiedzi */
.prob-reply-form {
  display: none;
  margin-top: .5rem;
}
.prob-reply-form.open { display: block; }
.prob-reply-ta {
  width: 100%; font-size: .84rem;
  border: 1.5px solid #e2e8f0; border-radius: .45rem;
  padding: .45rem .65rem; resize: vertical; min-height: 70px;
  font-family: inherit; line-height: 1.5; color: #0f172a;
  transition: border-color .12s;
}
.prob-reply-ta:focus { outline: 2px solid #059669; border-color: transparent; }
.prob-reply-ta.invalid { border-color: #dc2626; }
.prob-reply-hint {
  font-size: .71rem; color: #94a3b8; margin-top: .25rem; margin-bottom: .4rem;
}
.btn-reply-toggle {
  font-size: .78rem; font-weight: 600;
  padding: .26rem .65rem; border-radius: .4rem;
  border: 1.5px solid #059669; color: #059669; background: #fff;
  cursor: pointer; transition: all .12s; display: inline-flex; align-items: center; gap: .3rem;
}
.btn-reply-toggle:hover { background: #059669; color: #fff; }
.btn-reply-toggle:focus-visible { outline: 2px solid #059669; outline-offset: 2px; }
</style>

<div id="prob-sr" aria-live="polite" aria-atomic="true"></div>

<!-- Nagłówek -->
<div class="d-flex align-items-start justify-content-between mb-3">
  <div>
    <h1 class="h4 fw-bold mb-0">
      <i class="bi bi-megaphone text-warning me-2" aria-hidden="true"></i>Zgłoszone problemy
    </h1>
    <p class="text-muted small mb-0">
      Problemy zgłoszone przez wolontariuszy w Twoich obszarach
    </p>
  </div>
  <?php if ($cnt_open > 0): ?>
  <span class="badge fs-6 fw-semibold px-3 py-2"
        style="background:#fef9c3;color:#92400e;border:1px solid #fcd34d">
    <?= $cnt_open ?> otwartych
  </span>
  <?php endif; ?>
</div>

<!-- Filtry -->
<nav class="prob-filter" aria-label="Filtr problemów">
  <?php
  $filters = [
    'open'     => ['Otwarte',    $cnt_open,     'bi-exclamation-circle'],
    'resolved' => ['Rozwiązane', $cnt_resolved, 'bi-check-circle'],
    'all'      => ['Wszystkie',  $cnt_all,      'bi-list-ul'],
  ];
  foreach ($filters as $fk => [$flbl, $fcnt, $fico]):
    $url = '?filter=' . $fk;
  ?>
  <a href="<?= $url ?>"
     class="prob-pill <?= $filter===$fk?'active':'' ?>"
     data-f="<?= $fk ?>"
     aria-pressed="<?= $filter===$fk?'true':'false' ?>">
    <i class="bi <?= $fico ?>" aria-hidden="true"></i>
    <?= $flbl ?>
    <span style="opacity:.75;font-size:.72rem"><?= $fcnt ?></span>
  </a>
  <?php endforeach; ?>
</nav>

<!-- Lista problemów -->
<div id="prob-list" aria-label="Lista zgłoszonych problemów">

<?php if (!$problems): ?>
<div class="prob-empty">
  <i class="bi bi-check2-all" aria-hidden="true"></i>
  <p class="fw-semibold mb-1">
    <?= $filter === 'open' ? 'Brak otwartych problemów — świetnie!' : 'Brak zgłoszeń.' ?>
  </p>
  <?php if ($filter !== 'all'): ?>
  <p class="small"><a href="?filter=all">Zobacz wszystkie zgłoszenia</a></p>
  <?php endif; ?>
</div>

<?php else: foreach ($problems as $p):
  $resolved = !empty(json_decode($p['metadata'] ?? '{}', true)['resolved']);
  $overdue  = $p['due_date'] ?? null;
  $pri_color = [4=>'#dc2626',3=>'#f59e0b',2=>'#3b82f6',1=>'#94a3b8'][$p['priority']] ?? '#94a3b8';
?>
<article class="prob-card <?= $resolved ? 'resolved' : '' ?>"
         id="prob-<?= $p['event_id'] ?>"
         aria-label="Zgłoszenie: <?= h($p['task_title']) ?>, <?= $resolved?'rozwiązane':'otwarte' ?>">

  <div class="prob-card-head">
    <!-- Priorytet dot -->
    <span style="width:10px;height:10px;border-radius:50%;background:<?= $pri_color ?>;flex-shrink:0"
          aria-hidden="true"></span>

    <!-- Nazwa zadania -->
    <span class="prob-task-link"
          tabindex="0" role="link"
          onclick="taskOpenById(<?= $p['task_id'] ?>)"
          onkeydown="if(event.key==='Enter')taskOpenById(<?= $p['task_id'] ?>)"
          aria-label="Otwórz zadanie: <?= h($p['task_title']) ?>">
      <?= h($p['task_title']) ?>
    </span>

    <!-- Obszar -->
    <span class="prob-ws-chip">
      <span class="prob-ws-dot" style="background:<?= h($p['ws_color']) ?>" aria-hidden="true"></span>
      <?= h($p['ws_name']) ?> › <?= h($p['list_name']) ?>
    </span>

    <!-- Status badge -->
    <span class="<?= $resolved ? 'prob-badge-resolved' : 'prob-badge-open' ?>">
      <?= $resolved ? '✓ Rozwiązane' : '⚠ Otwarte' ?>
    </span>
  </div>

  <div class="prob-card-body">
    <!-- Treść wiadomości -->
    <blockquote class="prob-message" aria-label="Treść zgłoszenia">
      <?= h($p['message_preview'] ?? '(brak treści)') ?>
    </blockquote>

    <!-- Meta -->
    <div class="prob-meta">
      <span>
        <i class="bi bi-person me-1" aria-hidden="true"></i>
        <strong><?= h($p['reporter_name']) ?></strong>
        <?php if ($p['reporter_email']): ?>
        · <a href="mailto:<?= h($p['reporter_email']) ?>"><?= h($p['reporter_email']) ?></a>
        <?php endif; ?>
      </span>
      <span>
        <i class="bi bi-clock me-1" aria-hidden="true"></i>
        <time datetime="<?= h($p['occurred_at']) ?>"><?= h(substr($p['occurred_at'],0,16)) ?></time>
      </span>
      <?php if ($p['completed_at']): ?>
      <span class="text-success">
        <i class="bi bi-check-circle me-1" aria-hidden="true"></i>Zadanie ukończone
      </span>
      <?php endif; ?>
    </div>

    <!-- Akcje -->
    <div class="prob-actions">
      <?php if (!$resolved): ?>
      <button type="button"
              class="btn btn-sm btn-success"
              onclick="resolveProb(<?= $p['event_id'] ?>, this)"
              aria-label="Oznacz problem jako rozwiązany">
        <i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Oznacz jako rozwiązane
      </button>
      <?php else: ?>
      <span class="text-success small">
        <i class="bi bi-check-circle me-1" aria-hidden="true"></i>Rozwiązany
      </span>
      <?php endif; ?>

      <!-- Odpowiedź wewnętrzna -->
      <button type="button"
              class="btn-reply-toggle"
              onclick="probToggleReply('<?= $p['event_id'] ?>', this)"
              aria-expanded="false"
              aria-controls="reply-form-<?= $p['event_id'] ?>"
              aria-label="Odpowiedz wewnętrznie do <?= h($p['reporter_name']) ?>">
        <i class="bi bi-reply" aria-hidden="true"></i>Odpowiedz
      </button>

      <?php if ($p['reporter_email']): ?>
      <a href="mailto:<?= h($p['reporter_email']) ?>"
         class="btn btn-sm btn-outline-secondary"
         aria-label="Napisz e-mail do <?= h($p['reporter_name']) ?>">
        <i class="bi bi-envelope me-1" aria-hidden="true"></i>E-mail
      </a>
      <?php endif; ?>
    </div>
  </div><!-- /prob-card-body -->

  <!-- Sekcja odpowiedzi wewnętrznych -->
  <div class="prob-replies" id="replies-<?= $p['event_id'] ?>">

    <?php if ($p['_replies']): ?>
    <!-- Istniejące odpowiedzi -->
    <div class="mb-1" id="reply-list-<?= $p['event_id'] ?>" role="log" aria-label="Odpowiedzi lidera">
      <?php foreach ($p['_replies'] as $r):
        $parts = preg_split('/\s+/', trim($r['sender_name']));
        $initials = implode('', array_map(fn($w) => mb_strtoupper(mb_substr($w,0,1,'UTF-8'),'UTF-8'), array_slice($parts,0,2)));
      ?>
      <div class="prob-reply-bubble">
        <span class="prob-reply-av" aria-hidden="true"><?= h($initials ?: '?') ?></span>
        <div class="prob-reply-body">
          <div class="prob-reply-meta">
            <strong><?= h($r['sender_name']) ?></strong>
            <time datetime="<?= h($r['created_at']) ?>"><?= h(substr($r['created_at'],0,16)) ?></time>
            <span class="badge" style="font-size:.6rem;background:#dcfce7;color:#15803d">Wewnętrzna</span>
            <?php if (!$r['is_read']): ?>
            <span class="badge" style="font-size:.6rem;background:#dbeafe;color:#1d4ed8">Nieprzeczytana</span>
            <?php endif; ?>
          </div>
          <div class="prob-reply-text"><?= h($r['body']) ?></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div id="reply-list-<?= $p['event_id'] ?>"></div>
    <?php endif; ?>

    <!-- Formularz odpowiedzi (ukryty domyślnie) -->
    <div class="prob-reply-form"
         id="reply-form-<?= $p['event_id'] ?>"
         role="region"
         aria-label="Formularz odpowiedzi do <?= h($p['reporter_name']) ?>">
      <label class="visually-hidden"
             for="reply-ta-<?= $p['event_id'] ?>">
        Odpowiedź dla <?= h($p['reporter_name']) ?>
      </label>
      <textarea id="reply-ta-<?= $p['event_id'] ?>"
                class="prob-reply-ta"
                rows="3"
                maxlength="2000"
                placeholder="Napisz odpowiedź do <?= h($p['reporter_name']) ?>…"
                aria-describedby="reply-hint-<?= $p['event_id'] ?>"
                onkeydown="if((event.ctrlKey||event.metaKey)&&event.key==='Enter'){event.preventDefault();probSendReply('<?= $p['event_id'] ?>',<?= $p['task_id'] ?>,<?= $p['reporter_id'] ?>)}"></textarea>
      <p id="reply-hint-<?= $p['event_id'] ?>"
         class="prob-reply-hint">
        <kbd style="font-size:.67rem;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:3px;padding:0 .3rem">Ctrl+Enter</kbd>
        aby wysłać · wiadomość trafi do skrzynki wolontariusza + e-mailem
      </p>
      <div style="display:flex;gap:.4rem;align-items:center;flex-wrap:wrap">
        <button type="button"
                class="btn btn-sm"
                style="background:#059669;color:#fff;border:none"
                onclick="probSendReply('<?= $p['event_id'] ?>',<?= $p['task_id'] ?>,<?= $p['reporter_id'] ?>)"
                id="reply-btn-<?= $p['event_id'] ?>"
                aria-label="Wyślij odpowiedź do <?= h($p['reporter_name']) ?>">
          <i class="bi bi-send me-1" aria-hidden="true"></i>Wyślij odpowiedź
        </button>
        <button type="button"
                class="btn btn-sm btn-outline-secondary"
                onclick="probToggleReply('<?= $p['event_id'] ?>', null, true)"
                aria-label="Anuluj odpowiedź">
          Anuluj
        </button>
        <div id="reply-status-<?= $p['event_id'] ?>"
             aria-live="polite"
             style="font-size:.78rem;color:#16a34a"></div>
      </div>
    </div>

  </div><!-- /prob-replies -->

</article>
<?php endforeach; endif; ?>
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
const CSRF = <?= json_encode($csrf) ?>;
const BASE = <?= json_encode(rtrim(APP_URL,'/')) ?>;

function srAnnounce(msg) {
    const el = document.getElementById('prob-sr');
    if (!el) return;
    el.textContent = '';
    setTimeout(() => { el.textContent = msg; }, 50);
}

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

window.resolveProb = function(eventId, btn) {
    btn.disabled    = true;
    btn.textContent = 'Zapisuję…';

    fetch(BASE + '/tasks/problems.php', {
        method:  'POST',
        headers: {'Content-Type': 'application/json'},
        body:    JSON.stringify({_csrf: CSRF, event_id: eventId, action: 'resolve'})
    })
    .then(r => r.json())
    .then(r => {
        if (r.ok) {
            const card = document.getElementById('prob-' + eventId);
            if (card) {
                card.classList.add('resolved');
                // Podmień badge
                const badge = card.querySelector('.prob-badge-open');
                if (badge) {
                    badge.className = 'prob-badge-resolved';
                    badge.textContent = '✓ Rozwiązane';
                }
                // Podmień akcje
                const actions = card.querySelector('.prob-actions');
                if (actions) {
                    actions.innerHTML = '<span class="text-success small">'
                        + '<i class="bi bi-check-circle me-1" aria-hidden="true"></i>'
                        + 'Problem oznaczony jako rozwiązany.</span>';
                }
            }
            srAnnounce('Problem oznaczony jako rozwiązany.');
        } else {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-check2-circle me-1"></i>Oznacz jako rozwiązane';
            alert(r.error || 'Błąd zapisu.');
        }
    })
    .catch(() => {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-check2-circle me-1"></i>Oznacz jako rozwiązane';
        alert('Błąd połączenia.');
    });
};

// ── Odpowiedź wewnętrzna ──────────────────────────────────────────────────

window.probToggleReply = function(eventId, toggleBtn, forceClose) {
    const form = document.getElementById('reply-form-' + eventId);
    const ta   = document.getElementById('reply-ta-' + eventId);
    if (!form) return;

    const isOpen = form.classList.contains('open');

    if (forceClose || isOpen) {
        form.classList.remove('open');
        if (toggleBtn) {
            toggleBtn.setAttribute('aria-expanded', 'false');
            toggleBtn.innerHTML = '<i class="bi bi-reply" aria-hidden="true"></i>Odpowiedz';
        }
    } else {
        form.classList.add('open');
        if (toggleBtn) {
            toggleBtn.setAttribute('aria-expanded', 'true');
            toggleBtn.innerHTML = '<i class="bi bi-x" aria-hidden="true"></i>Schowaj';
        }
        requestAnimationFrame(() => ta?.focus());
    }
};

window.probSendReply = function(eventId, taskId, reporterId) {
    const ta     = document.getElementById('reply-ta-'     + eventId);
    const btn    = document.getElementById('reply-btn-'    + eventId);
    const status = document.getElementById('reply-status-' + eventId);
    const list   = document.getElementById('reply-list-'   + eventId);

    const body = (ta?.value || '').trim();
    if (!body) {
        ta?.classList.add('invalid');
        ta?.setAttribute('aria-invalid', 'true');
        ta?.focus();
        return;
    }
    ta.classList.remove('invalid');
    ta.removeAttribute('aria-invalid');

    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>Wysyłam…';
    if (status) status.textContent = '';

    fetch(BASE + '/tasks/problems.php', {
        method:  'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            _csrf:       CSRF,
            action:      'reply',
            task_id:     taskId,
            reporter_id: reporterId,
            body:        body,
        })
    })
    .then(r => r.json())
    .then(r => {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-send me-1" aria-hidden="true"></i>Wyślij odpowiedź';

        if (r.ok) {
            // Wstaw nową wiadomość do listy odpowiedzi
            const initials = (r.sender || '?').trim().split(/\s+/)
                .slice(0, 2).map(w => w[0] || '').join('').toUpperCase() || '?';
            const bubble = document.createElement('div');
            bubble.className = 'prob-reply-bubble';
            bubble.innerHTML = `
              <span class="prob-reply-av" aria-hidden="true">${escHtml(initials)}</span>
              <div class="prob-reply-body">
                <div class="prob-reply-meta">
                  <strong>${escHtml(r.sender || '')}</strong>
                  <time>${escHtml(r.sent_at || '')}</time>
                  <span class="badge" style="font-size:.6rem;background:#dcfce7;color:#15803d">Wewnętrzna</span>
                </div>
                <div class="prob-reply-text">${escHtml(body)}</div>
              </div>`;
            list?.appendChild(bubble);

            // Reset formularza i zamknij
            ta.value = '';
            probToggleReply(eventId, null, true);

            // Poinformuj przycisk "Odpowiedz" o nowych odpowiedziach
            if (status) {
                status.textContent = '✓ Odpowiedź wysłana';
                setTimeout(() => { status.textContent = ''; }, 4000);
            }
            srAnnounce('Odpowiedź wysłana do ' + (r.sender ? r.sender.split(' ')[0] : 'wolontariusza') + '.');
        } else {
            const err = r.error || 'Błąd wysyłania.';
            if (status) status.style.color = '#dc2626', status.textContent = err;
            else alert(err);
            ta?.focus();
        }
    })
    .catch(() => {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-send me-1" aria-hidden="true"></i>Wyślij odpowiedź';
        if (status) { status.style.color = '#dc2626'; status.textContent = 'Błąd połączenia.'; }
    });
};
</script>

<?php require_once __DIR__ . '/includes/footer_tasks.php'; ?>
