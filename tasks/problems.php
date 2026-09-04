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

<style type="text/tailwindcss">
/* ── Karta problemu ─────────────────────────────────────────────────── */
.prob-card {
  @apply tw-bg-white tw-rounded-xl tw-mb-3 tw-overflow-hidden tw-transition-shadow;
  border: 1.5px solid #e2e8f0;
}
.prob-card:hover { box-shadow: 0 3px 12px rgba(0,0,0,.07); }
.prob-card.resolved {
  @apply tw-opacity-[.65];
  border-color: #f1f5f9;
}

.prob-card-head {
  @apply tw-flex tw-items-center tw-gap-3 tw-pt-[.8rem] tw-px-4 tw-pb-[.6rem] tw-border-b tw-border-slate-100;
}
.prob-task-link {
  @apply tw-flex-1 tw-block tw-bg-transparent tw-border-0 tw-p-0 tw-text-left tw-font-sans tw-font-bold tw-text-[.92rem] tw-text-slate-900 tw-no-underline tw-cursor-pointer tw-whitespace-nowrap tw-overflow-hidden tw-text-ellipsis;
}
.prob-task-link:hover { @apply tw-text-blue-600 tw-underline; }
.prob-task-link:focus-visible { @apply tw-outline tw-outline-2 tw-outline-blue-600 tw-outline-offset-2 tw-rounded; }

.prob-ws-chip {
  @apply tw-inline-flex tw-items-center tw-gap-[.3rem] tw-text-xs tw-text-slate-500 tw-flex-shrink-0;
}
.prob-ws-dot { @apply tw-w-[7px] tw-h-[7px] tw-rounded-full; }

.prob-badge-resolved {
  @apply tw-text-[.68rem] tw-font-bold tw-py-[.15rem] tw-px-2 tw-rounded-full tw-bg-green-100 tw-text-green-700 tw-flex-shrink-0 tw-whitespace-nowrap;
}
.prob-badge-open {
  @apply tw-text-[.68rem] tw-font-bold tw-py-[.15rem] tw-px-2 tw-rounded-full tw-bg-yellow-100 tw-text-amber-800 tw-flex-shrink-0 tw-whitespace-nowrap;
}

.prob-card-body {
  @apply tw-py-[.7rem] tw-px-4;
}
.prob-message {
  @apply tw-text-[.87rem] tw-text-slate-700 tw-leading-[1.55] tw-whitespace-pre-wrap tw-break-words tw-bg-slate-50 tw-border tw-border-slate-200 tw-rounded tw-py-[.6rem] tw-px-[.8rem] tw-mb-[.6rem];
}

.prob-meta {
  @apply tw-flex tw-items-center tw-gap-4 tw-flex-wrap tw-text-[.77rem] tw-text-slate-400;
}
.prob-meta strong { @apply tw-text-slate-600; }

.prob-actions { @apply tw-flex tw-gap-2 tw-items-center tw-mt-[.65rem]; }

/* Filtr pills */
.prob-filter {
  @apply tw-flex tw-gap-[.35rem] tw-flex-wrap tw-mb-[1.1rem];
}
.prob-pill {
  @apply tw-inline-flex tw-items-center tw-gap-[.3rem] tw-py-1 tw-px-[.7rem] tw-rounded-full tw-text-[.8rem] tw-font-semibold tw-bg-white tw-text-slate-500 tw-no-underline tw-transition-all;
  border: 1.5px solid #e2e8f0;
}
.prob-pill:hover { @apply tw-border-slate-400; }
.prob-pill.active { @apply tw-bg-slate-900 tw-text-white tw-border-slate-900; }
.prob-pill[data-f="open"].active  { @apply tw-bg-amber-600 tw-border-amber-600; }
.prob-pill[data-f="resolved"].active { @apply tw-bg-green-600 tw-border-green-600; }
.prob-pill:focus-visible { outline: 3px solid var(--tsk-focus) !important; }

/* Empty */
.prob-empty {
  @apply tw-text-center tw-py-16 tw-px-4 tw-text-slate-400;
}
.prob-empty i { @apply tw-text-3xl tw-block tw-mb-[.6rem] tw-opacity-25; }

/* SR announce */
#prob-sr { position:absolute;width:1px;height:1px;padding:0;margin:-1px;
  overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0 }

/* ── Sekcja odpowiedzi lidera ──────────────────────────────────────── */
.prob-replies {
  @apply tw-border-t tw-border-slate-100 tw-bg-slate-50 tw-py-[.7rem] tw-px-4;
}
.prob-reply-bubble {
  @apply tw-flex tw-gap-[.55rem] tw-items-start tw-mb-[.55rem];
}
.prob-reply-av {
  @apply tw-w-[26px] tw-h-[26px] tw-rounded-full tw-bg-emerald-600 tw-text-white tw-flex tw-items-center tw-justify-center tw-text-[.6rem] tw-font-bold tw-flex-shrink-0 tw-mt-[.1rem];
}
.prob-reply-body {
  @apply tw-flex-1 tw-bg-white tw-border tw-border-slate-200 tw-py-[.45rem] tw-px-[.65rem];
  border-radius: .15rem .5rem .5rem .5rem;
}
.prob-reply-meta {
  @apply tw-flex tw-gap-2 tw-items-center tw-text-[.7rem] tw-text-slate-400 tw-mb-[.2rem];
}
.prob-reply-meta strong { @apply tw-text-slate-900 tw-text-[.78rem]; }
.prob-reply-text {
  @apply tw-text-[.84rem] tw-text-slate-700 tw-whitespace-pre-wrap tw-break-words tw-leading-relaxed;
}

/* Pole odpowiedzi */
.prob-reply-form {
  @apply tw-hidden tw-mt-2;
}
.prob-reply-form.open { @apply tw-block; }
.prob-reply-ta {
  @apply tw-w-full tw-text-[.84rem] tw-rounded-lg tw-py-[.45rem] tw-px-[.65rem] tw-resize-y tw-font-sans tw-leading-relaxed tw-text-slate-900 tw-transition-colors;
  border: 1.5px solid #e2e8f0; min-height: 70px;
}
.prob-reply-ta:focus { @apply tw-outline tw-outline-2 tw-outline-emerald-600 tw-border-transparent; }
.prob-reply-ta.invalid { @apply tw-border-red-600; }
.prob-reply-hint {
  @apply tw-text-[.71rem] tw-text-slate-400 tw-mt-1 tw-mb-[.4rem];
}
.btn-reply-toggle {
  @apply tw-text-[.78rem] tw-font-semibold tw-py-[.26rem] tw-px-[.65rem] tw-rounded tw-text-emerald-600 tw-bg-white tw-cursor-pointer tw-transition-all tw-inline-flex tw-items-center tw-gap-[.3rem];
  border: 1.5px solid #059669;
}
.btn-reply-toggle:hover { @apply tw-bg-emerald-600 tw-text-white; }
.btn-reply-toggle:focus-visible { @apply tw-outline tw-outline-2 tw-outline-emerald-600 tw-outline-offset-2; }
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
    <button type="button" class="prob-task-link"
            onclick="taskOpenById(<?= $p['task_id'] ?>)"
            aria-label="Otwórz zadanie: <?= h($p['task_title']) ?>">
      <?= h($p['task_title']) ?>
    </button>

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
