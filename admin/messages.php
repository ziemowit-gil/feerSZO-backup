<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/messages.php';
require_once dirname(__DIR__) . '/includes/supervisors.php';

require_role('admin', 'editor');
require_module_enabled('messages_enabled', 'Moduł wiadomości');

$PAGE_TITLE = 'Wiadomości';

// Pobierz wszystkie wątki (ostatnia wiadomość na wątek + unread count)
try {
    $threads = db_all("
        SELECT
            m.context_type,
            m.context_id,
            m.contract_type,
            MAX(m.created_at) AS last_at,
            COUNT(*) AS total,
            SUM(CASE WHEN m.sender_type='user' AND m.is_read=0 THEN 1 ELSE 0 END) AS unread_admin,
            (SELECT body FROM messages m2
             WHERE m2.context_type=m.context_type AND m2.context_id=m.context_id
             ORDER BY m2.created_at DESC LIMIT 1) AS last_body,
            (SELECT sender_name FROM messages m2
             WHERE m2.context_type=m.context_type AND m2.context_id=m.context_id
             ORDER BY m2.created_at DESC LIMIT 1) AS last_sender,
            (SELECT recipient_type FROM messages m3
             WHERE m3.context_type=m.context_type AND m3.context_id=m.context_id
               AND m3.sender_type='user'
             ORDER BY m3.created_at DESC LIMIT 1) AS last_user_recipient,
            (SELECT subject FROM messages m3
             WHERE m3.context_type=m.context_type AND m3.context_id=m.context_id
               AND m3.sender_type='user'
             ORDER BY m3.created_at DESC LIMIT 1) AS last_user_subject,
            (SELECT type_id FROM messages m3
             WHERE m3.context_type=m.context_type AND m3.context_id=m.context_id
               AND m3.sender_type='user'
             ORDER BY m3.created_at DESC LIMIT 1) AS last_user_type_id
        FROM messages m
        GROUP BY m.context_type, m.context_id
        ORDER BY last_at DESC
    ");
} catch (\Throwable $e) { $threads = []; }

// Dla każdego wątku pobierz dane kontekstu (numer umowy / imię kandydata) i opiekuna
foreach ($threads as &$t) {
    if ($t['context_type'] === 'contract') {
        $table = 'umowy_' . $t['contract_type'];
        try {
            $row = db_one("SELECT numer_umowy, imie_nazwisko FROM {$table} WHERE id=?", [(int)$t['context_id']]);
            $t['_label']    = $row['numer_umowy'] ?? '#' . $t['context_id'];
            $t['_sublabel'] = $row['imie_nazwisko'] ?? '';
            $t['_url']      = APP_URL . '/contracts/' . $t['contract_type'] . '/view.php?id=' . $t['context_id'];
        } catch (\Throwable $e) { $t['_label'] = '#' . $t['context_id']; $t['_sublabel'] = ''; $t['_url'] = '#'; }
        // Opiekun umowy
        $t['_supervisor'] = supervisor_get($t['contract_type'], (int)$t['context_id']);
    } elseif ($t['context_type'] === 'onboarding') {
        try {
            $row = db_one("SELECT imie_nazwisko, email FROM onboarding_volunteers WHERE id=?", [(int)$t['context_id']]);
            $t['_label']    = $row['imie_nazwisko'] ?? '#' . $t['context_id'];
            $t['_sublabel'] = $row['email'] ?? '';
            $t['_url']      = APP_URL . '/admin/onboarding_view.php?id=' . $t['context_id'];
        } catch (\Throwable $e) { $t['_label'] = '#' . $t['context_id']; $t['_sublabel'] = ''; $t['_url'] = '#'; }
        $t['_supervisor'] = null;
    } elseif ($t['context_type'] === 'task') {
        try {
            $row = db_one("SELECT title FROM tasks WHERE id=?", [(int)$t['context_id']]);
            $t['_label']    = $row['title'] ?? 'Zadanie #' . $t['context_id'];
            $t['_sublabel'] = 'Wiadomość zadaniowa';
        } catch (\Throwable $e) {
            $t['_label']    = 'Zadanie #' . $t['context_id'];
            $t['_sublabel'] = '';
        }
        $t['_url']        = APP_URL . '/tasks/inbox.php';
        $t['_supervisor'] = null;
    } else {
        // Fallback dla nieznanych typów
        $t['_label']    = $t['_label']    ?? '#' . $t['context_id'];
        $t['_sublabel'] = $t['_sublabel'] ?? '';
        $t['_url']      = $t['_url']      ?? '#';
        $t['_supervisor'] = null;
    }
    // Nazwa typu wiadomości
    $t['_type_name'] = msg_type_name($t['last_user_type_id'] ? (int)$t['last_user_type_id'] : null);
}
unset($t);

include dirname(__DIR__) . '/includes/header.php';
?>

<div class="d-flex align-items-center mb-3 gap-2">
  <h4 class="mb-0"><i class="bi bi-chat-dots text-primary"></i> Wiadomości</h4>
</div>

<?= flash_html() ?>

<?php if (!$threads): ?>
<div class="card shadow-sm">
  <div class="card-body text-center py-5 text-muted">
    <i class="bi bi-chat-dots" style="font-size:3rem;opacity:.25"></i>
    <div class="mt-3">Brak wiadomości.</div>
  </div>
</div>
<?php else: ?>

<div class="list-group shadow-sm">
<?php foreach ($threads as $t): ?>
<?php $has_unread = (int)$t['unread_admin'] > 0; ?>
<a href="<?= h($t['_url']) ?>" class="list-group-item list-group-item-action py-3 px-4 <?= $has_unread ? 'border-start border-primary border-3' : '' ?>">
  <div class="d-flex justify-content-between align-items-start gap-3">
    <div class="flex-grow-1 overflow-hidden">
      <div class="d-flex align-items-center gap-2 mb-1">
        <?php if ($t['context_type'] === 'onboarding'): ?>
          <span class="badge bg-warning text-dark" style="font-size:.7rem">Zgłoszenie</span>
        <?php elseif ($t['context_type'] === 'task'): ?>
          <span class="badge" style="font-size:.7rem;background:#dcfce7;color:#15803d;border:1px solid #bbf7d0">
            <i class="bi bi-table me-1"></i>Zadanie
          </span>
        <?php else: ?>
          <span class="badge bg-light text-secondary border" style="font-size:.7rem">
            <?= h(CONTRACT_TYPES[$t['contract_type']] ?? $t['contract_type']) ?>
          </span>
        <?php endif; ?>
        <span class="fw-semibold <?= $has_unread ? 'text-primary' : '' ?>"><?= h($t['_label']) ?></span>
        <?php if ($t['_sublabel']): ?>
        <span class="text-muted small"><?= h($t['_sublabel']) ?></span>
        <?php endif; ?>
        <?php
          $rt = $t['last_user_recipient'] ?? 'admin';
          if ($rt === 'opiekun'): ?>
        <span class="badge ms-1" style="font-size:.65rem;background:#fef9c3;color:#92400e;border:1px solid #fde68a">
          <i class="bi bi-person-check"></i> Opiekun
        </span>
        <?php elseif ($rt === 'admin'): ?>
        <span class="badge ms-1" style="font-size:.65rem;background:#e0f2fe;color:#075985;border:1px solid #bae6fd">
          <i class="bi bi-shield-check"></i> Admin
        </span>
        <?php endif; ?>
        <?php if (!empty($t['_supervisor'])): ?>
        <span class="badge ms-1" style="font-size:.65rem;background:#f0fdf4;color:#166534;border:1px solid #bbf7d0"
              title="Opiekun: <?= h($t['_supervisor']['user_email']) ?>">
          <i class="bi bi-person-check"></i> <?= h($t['_supervisor']['user_name']) ?>
        </span>
        <?php endif; ?>
      </div>
      <div class="text-muted small text-truncate" style="max-width:100%">
        <?php if (!empty($t['_type_name'])): ?>
        <span class="badge me-1" style="font-size:.65rem;background:#f1f5f9;color:#475569;border:1px solid #cbd5e1">
          <i class="bi bi-tag me-1"></i><?= h($t['_type_name']) ?>
        </span>
        <?php endif; ?>
        <?php
          // Dla wiadomości task: subject może zawierać marker "transfer:ID:Tytuł" — wyczyść
          $display_subject = $t['last_user_subject'] ?? '';
          if ($t['context_type'] === 'task' && preg_match('/^(transfer|Re: transfer):\d+:(.+)$/', $display_subject, $sm)) {
              $display_subject = $sm[2];  // tylko tytuł zadania
          }
          // Strip markdown **bold** z treści
          $display_body = preg_replace('/\*\*(.+?)\*\*/', '$1', $t['last_body'] ?? '');
          $display_body = mb_substr(strip_tags($display_body), 0, 100);
        ?>
        <?php if ($display_subject): ?>
        <span class="fw-semibold text-dark"><?= h($display_subject) ?></span>
        <span class="mx-1">·</span>
        <?php endif; ?>
        <strong><?= h($t['last_sender']) ?>:</strong>
        <?= h($display_body) ?>
      </div>
    </div>
    <div class="text-end flex-shrink-0">
      <div class="small text-muted"><?= date_pl($t['last_at']) ?></div>
      <?php if ($has_unread): ?>
      <span class="badge bg-danger mt-1"><?= $t['unread_admin'] ?> nowe</span>
      <?php endif; ?>
      <div class="small text-muted mt-1"><?= $t['total'] ?> wiad.</div>
    </div>
  </div>
</a>
<?php endforeach; ?>
</div>

<?php endif; ?>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
