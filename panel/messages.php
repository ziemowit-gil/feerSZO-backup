<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/messages.php';
require_once dirname(__DIR__) . '/includes/approval.php';

require_login();
panel_require_enabled('wiadomosci', 'Wiadomości');
$PAGE_TITLE = 'Moje wiadomości';
$user = current_user();

// ── Microsoft ID ──────────────────────────────────────────────────────────
try {
    $_db_user = db_one("SELECT microsoft_id FROM users WHERE id = ?", [$user['id']]);
    $user['microsoft_id'] = $_db_user['microsoft_id'] ?? '';
} catch (\Throwable $e) { $user['microsoft_id'] = ''; }

// ── Pobierz umowy użytkownika ─────────────────────────────────────────────
function _pnl_msg_contracts(array $user): array
{
    $email = $user['email']        ?? '';
    $ms_id = $user['microsoft_id'] ?? '';
    if (!$email && !$ms_id) return [];

    $results = [];
    $tables = [
        ['zlecenie',    ['m365_user_id', 'm365_login'],                          'data_zakonczenia'],
        ['wolontariat', ['m365_user_id', 'm365_login', 'email', 'rodzic_email'], 'data_zakonczenia'],
        ['dzielo',      ['m365_user_id', 'm365_login'],                          'termin_oddania'],
        ['praca',       ['email_login'],                                         'data_zakonczenia'],
    ];
    foreach ($tables as [$type, $fields, $end_col]) {
        $conds = []; $params = [];
        foreach ($fields as $f) {
            if ($f === 'm365_user_id' && !$ms_id) continue;
            if (in_array($f, ['email', 'm365_login', 'rodzic_email'], true) && !$email) continue;
            $conds[] = "{$f} = ?";
            $params[] = ($f === 'm365_user_id') ? $ms_id : $email;
        }
        if (!$conds) continue;
        $rows = db_all(
            "SELECT id, '{$type}' AS contract_type, numer_umowy, status
             FROM umowy_{$type}
             WHERE (" . implode(' OR ', $conds) . ")",
            $params
        );
        $results = array_merge($results, $rows);
    }
    return $results;
}

auth_start();
$contracts = _pnl_msg_contracts($user);

// ── Aktywna umowa (sesja lub GET) ─────────────────────────────────────────
$_active = null;

if (isset($_GET['cid'], $_GET['ctype'])) {
    $sel_type = preg_replace('/[^a-z]/', '', $_GET['ctype']);
    $sel_id   = (int) $_GET['cid'];
    $match = array_filter($contracts, fn($c) =>
        $c['contract_type'] === $sel_type && (int) $c['id'] === $sel_id);
    if ($match) {
        $_active = reset($match);
        $_SESSION['panel_contract'] = [
            'type'  => $_active['contract_type'],
            'id'    => (int) $_active['id'],
            'numer' => $_active['numer_umowy'],
        ];
    }
}

if (!$_active && !empty($_SESSION['panel_contract'])) {
    $sc    = $_SESSION['panel_contract'];
    $match = array_filter($contracts, fn($c) =>
        $c['contract_type'] === $sc['type'] && (int) $c['id'] === (int) $sc['id']);
    if ($match) $_active = reset($match);
}
if (!$_active && $contracts) {
    $_active = $contracts[0];
    $_SESSION['panel_contract'] = [
        'type'  => $_active['contract_type'],
        'id'    => (int) $_active['id'],
        'numer' => $_active['numer_umowy'],
    ];
}

// ── Wyślij wiadomość (POST) ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_msg_send'])) {
    csrf_check();
    $body      = trim($_POST['msg_body']      ?? '');
    $ctx_id    = (int) ($_POST['msg_ctx_id']  ?? 0);
    $c_type    = preg_replace('/[^a-z]/', '', $_POST['msg_contract_type'] ?? '');
    $recipient = in_array($_POST['recipient_type'] ?? '', ['admin', 'opiekun'], true)
                 ? $_POST['recipient_type'] : 'admin';
    $subject   = mb_substr(trim($_POST['msg_subject'] ?? ''), 0, 120);

    $type_id = ((int)($_POST['msg_type_id'] ?? 0)) ?: null;

    if ($body !== '' && $ctx_id && $c_type) {
        $owned = array_filter($contracts, fn($c) =>
            $c['contract_type'] === $c_type && (int) $c['id'] === $ctx_id);
        if ($owned) {
            pmsg_send(
                'contract', $ctx_id, $c_type,
                'user', (int) $user['id'], $user['name'],
                $body, $recipient, $subject, $type_id
            );
            // Powiadomienie email do opiekuna / adminów
            try {
                msg_send_notify('contract', $ctx_id, $c_type, 'to_admin', $body, $subject);
            } catch (\Throwable $e) {}
            flash_set('success', 'Wiadomość wysłana. Odpiszemy wkrótce.');
        }
    }
    header('Location: ' . APP_URL . '/panel/messages.php');
    exit;
}

// ── Wczytaj typy wiadomości ───────────────────────────────────────────────
$_msg_types = [];
try {
    db()->exec("CREATE TABLE IF NOT EXISTS message_types (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        description TEXT NOT NULL DEFAULT '',
        available_for TEXT NOT NULL DEFAULT 'both',
        is_active INTEGER NOT NULL DEFAULT 1,
        sort_order INTEGER NOT NULL DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $_msg_types = db_all(
        "SELECT id, name, description FROM message_types
         WHERE is_active=1 AND available_for IN ('user','both')
         ORDER BY sort_order ASC, id ASC"
    );
} catch (\Throwable $e) { $_msg_types = []; }

// ── Wczytaj wiadomości i oznacz jako przeczytane ──────────────────────────
$messages = [];
if ($_active) {
    msg_mark_read('contract', (int) $_active['id'], 'user');
    $messages = msg_thread('contract', (int) $_active['id']);
}

$_is_volunteer_only = is_viewer() && !db_one("SELECT id FROM users WHERE id=? AND k30_consultant=1", [(int)$user['id']]);

if ($_is_volunteer_only) {
    include __DIR__ . '/includes/header_panel.php';
} else {
    include dirname(__DIR__) . '/includes/header.php';
}
?>

<style>
/* ── Chat bubbles ───────────────────────────────────────────────────── */
.chat-wrap { display:flex; flex-direction:column; gap:1.25rem; padding:.25rem 0 1rem; }
.msg-row { display:flex; align-items:flex-end; gap:.6rem; }
.msg-out { flex-direction:row-reverse; }
.msg-bubble {
  max-width: 80%; padding: .65rem .9rem;
  font-size: .9rem; line-height: 1.6; word-break: break-word;
  box-shadow: 0 1px 4px rgba(0,0,0,.08);
}
.msg-out .msg-bubble {
  background: var(--tz); color: #fff;
  border-radius: 18px 18px 4px 18px;
}
.msg-in .msg-bubble {
  background: var(--tz-bg-sub, #F3F4F6); color: var(--tz-ink, #111827);
  border-radius: 18px 18px 18px 4px;
}
.msg-in.unread .msg-bubble {
  background: color-mix(in srgb, #16a34a 12%, var(--tz-bg-sub, #F3F4F6));
}
.msg-avatar {
  width:32px; height:32px; border-radius:50%; flex-shrink:0;
  background: var(--tz, #2563eb);
  color:#fff; font-size:.72rem; font-weight:700;
  display:flex; align-items:center; justify-content:center;
}
.msg-meta { font-size:.7rem; color:var(--tz-ink-muted, #9CA3AF); margin-top:.25rem; }
.msg-out .msg-meta { text-align:right; }
.msg-subject-chip {
  display:inline-flex; align-items:center; gap:.3rem;
  font-size:.73rem; font-weight:600; padding:.15rem .6rem;
  border-radius:2rem;
  background: color-mix(in srgb, var(--tz, #2563eb) 8%, var(--tz-bg, #fff));
  color: var(--tz, #2563eb);
  border: 1px solid color-mix(in srgb, var(--tz, #2563eb) 30%, transparent);
  margin-bottom:.35rem;
}
.msg-subject-chip.to-opiekun { background:#FFFBEB; color:#92400E; border-color:#FDE68A; }
.msg-thread-group { display:flex; flex-direction:column; gap:.5rem; }
.msg-typing {
  display:inline-flex; align-items:center; gap:.25rem;
  padding:.5rem .75rem; background:var(--tz-bg-sub, #F3F4F6); border-radius:18px;
  font-size:.78rem; color:var(--tz-ink-muted, #9CA3AF);
}
.msg-typing span { width:6px;height:6px;border-radius:50%;background:var(--tz-ink-muted,#CBD5E1);animation:pulse 1.2s infinite; }
.msg-typing span:nth-child(2){animation-delay:.2s}
.msg-typing span:nth-child(3){animation-delay:.4s}
@keyframes pulse{0%,80%,100%{transform:scale(.8);opacity:.5}40%{transform:scale(1);opacity:1}}
.msg-new-badge {
  text-align:center; font-size:.72rem; color:#16A34A; font-weight:600;
  padding:.25rem 0; margin:.25rem 0;
}
/* ── Recipient selector buttons ──────────────────────────────────── */
.msg-recipient-btn {
  display:inline-flex; align-items:center; gap:.4rem;
  padding:.35rem .8rem; border-radius:2rem;
  border: 2px solid var(--tz-border, #E5E7EB);
  background: var(--tz-bg, #fff);
  font-size:.8rem; font-weight:500; color: var(--tz-ink, #374151);
  cursor:pointer; transition:all .12s; white-space:nowrap; text-decoration:none;
}
.msg-recipient-btn.active {
  border-color: var(--tz);
  background: color-mix(in srgb, var(--tz) 8%, var(--tz-bg, #fff));
  color: var(--tz); font-weight:600;
}
/* ── Q&A cards (admin/editor view) ───────────────────────────────── */
.qa-question { border-left: 4px solid var(--tz, #2563eb) !important; }
.qa-answer   { border-left: 4px solid #16a34a !important; background: #f0fdf4; }
.qa-answer.unread-ans { background: #dcfce7; }
.qa-hdr-q {
  background: color-mix(in srgb, var(--tz, #2563eb) 6%, var(--tz-bg, #fff)) !important;
  border-bottom-color: color-mix(in srgb, var(--tz, #2563eb) 25%, transparent) !important;
}
.qa-hdr-a { background: #dcfce7 !important; border-bottom-color: #bbf7d0 !important; }
.qa-subject { font-weight:600; font-size:.83rem; color:var(--tz-ink, #1e293b); }
/* ── Recipient badges ────────────────────────────────────────────── */
.rt-opiekun { background:#fef9c3; color:#92400e; border:1px solid #fde68a; }
.rt-admin   { background:#e0f2fe; color:#075985; border:1px solid #bae6fd; }
@media(prefers-reduced-motion:reduce){.msg-typing span{animation:none;transition:none}}
</style>

<div class="pv-wrap" style="max-width:1100px">

<?php if ($_is_volunteer_only): ?>

<div class="pv-page-header">
  <div class="pv-page-head-main">
    <a href="<?= APP_URL ?>/panel/index.php" class="pv-page-back">
      <i class="bi bi-arrow-left" aria-hidden="true"></i> Panel
    </a>
    <h1 class="pv-page-title"><i class="bi bi-chat-dots" aria-hidden="true"></i>Wiadomości</h1>
    <p class="pv-page-sub">Korespondencja z koordynatorem</p>
  </div>
</div>

<?= flash_html() ?>

<?php if (!$contracts): ?>
<div class="tz-card mb-4">
  <div class="tz-card__hd"><i class="bi bi-exclamation-circle me-2" aria-hidden="true"></i>Brak umów</div>
  <div class="tz-card__bd">
    <div class="tz-note tz-note--warning mb-0">Nie masz przypisanej żadnej umowy.</div>
  </div>
</div>
<?php else: ?>

<?php if (count($contracts) > 1): ?>
<div class="d-flex gap-2 flex-wrap mb-3" role="list" aria-label="Przełącznik umów">
  <?php foreach ($contracts as $c):
    $is_active = $_active && $c['contract_type'] === $_active['contract_type'] && (int)$c['id'] === (int)$_active['id'];
    $unr = msg_unread_thread('contract', (int)$c['id'], 'user');
  ?>
  <a href="?cid=<?= (int)$c['id'] ?>&ctype=<?= h($c['contract_type']) ?>"
     class="msg-recipient-btn <?= $is_active ? 'active' : '' ?>"
     role="listitem"
     aria-current="<?= $is_active ? 'page' : 'false' ?>"
     aria-label="Umowa <?= h($c['numer_umowy']) ?><?= $unr ? ", $unr nieprzeczytanych" : '' ?>">
    <i class="bi bi-file-text" aria-hidden="true"></i>
    <?= h($c['numer_umowy']) ?>
    <?php if ($unr): ?><span class="tz-badge" aria-hidden="true"><?= $unr ?></span><?php endif; ?>
  </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($_active): ?>

<?php if (!$messages): ?>
<div class="tz-empty mb-3" role="status">
  <i class="bi bi-chat-left-dots tz-empty__icon" aria-hidden="true" style="font-size:3rem;opacity:.2;display:block;margin-bottom:.75rem"></i>
  <p class="tz-empty__title fw-semibold mb-1">Brak wiadomości</p>
  <p class="tz-empty__sub small">Wyślij swoje pierwsze pytanie korzystając z formularza poniżej.</p>
</div>
<?php else: ?>
<?php
// Grupowanie: pytania + odpowiedzi
$grouped = [];
$current_q = null;
foreach ($messages as $m) {
    if ($m['sender_type'] === 'user') {
        if ($current_q !== null) $grouped[] = $current_q;
        $current_q = ['question' => $m, 'answers' => []];
    } else {
        if ($current_q === null) $current_q = ['question' => null, 'answers' => [$m]];
        else $current_q['answers'][] = $m;
    }
}
if ($current_q !== null) $grouped[] = $current_q;
$_first_unread_shown = false;
?>
<div class="chat-wrap mb-3" role="log" aria-label="Historia korespondencji" aria-live="polite">
<?php foreach ($grouped as $pair):
    $q = $pair['question'];
    $answers = $pair['answers'];
?>
<div class="msg-thread-group"
     aria-label="<?= $q ? 'Wątek: ' . h(mb_substr($q['subject'] ?? $q['body'] ?? 'Wiadomość', 0, 40)) : 'Odpowiedź admina' ?>">
  <?php if ($q): ?>
  <?php
    $rt = $q['recipient_type'] ?? 'admin';
    $rt_label = $rt === 'opiekun' ? 'Opiekun umowy' : 'Administrator';
    $subj = trim($q['subject'] ?? '');
  ?>
  <div>
    <?php if ($subj): ?>
    <div class="text-end mb-1">
      <span class="msg-subject-chip <?= $rt === 'opiekun' ? 'to-opiekun' : '' ?>">
        <i class="bi <?= $rt === 'opiekun' ? 'bi-person-check' : 'bi-shield-check' ?>" aria-hidden="true"></i>
        <?= h($subj) ?> → <?= h($rt_label) ?>
      </span>
    </div>
    <?php endif; ?>
    <div class="msg-row msg-out" role="listitem">
      <div>
        <div class="msg-bubble"><?= nl2br(h($q['body'])) ?></div>
        <div class="msg-meta"><?= date('d.m.Y, H:i', strtotime($q['created_at'])) ?><?= !$subj ? ' → '.$rt_label : '' ?></div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <?php foreach ($answers as $ans):
    $is_unread = !(int)($ans['is_read']);
    if ($is_unread && !$_first_unread_shown): $_first_unread_shown = true; ?>
    <div class="msg-new-badge" role="status">
      <i class="bi bi-arrow-down-circle me-1" aria-hidden="true"></i>Nowa odpowiedź
    </div>
    <?php endif; ?>
  <div class="msg-row msg-in <?= $is_unread ? 'unread' : '' ?>" role="listitem">
    <div class="msg-avatar" aria-hidden="true">
      <?= h(mb_strtoupper(mb_substr($ans['sender_name'] ?? 'A', 0, 2))) ?>
    </div>
    <div>
      <div class="msg-bubble">
        <?php if ($ans['sender_type'] === 'admin'): ?>
          <?= $ans['body'] ?>
        <?php else: ?>
          <?= nl2br(h($ans['body'])) ?>
        <?php endif; ?>
      </div>
      <div class="msg-meta"><?= h($ans['sender_name'] ?? '') ?> · <?= date('d.m.Y, H:i', strtotime($ans['created_at'])) ?><?= $is_unread ? ' · <span style="color:#16A34A">nowa</span>' : '' ?></div>
    </div>
  </div>
  <?php endforeach; ?>

  <?php if ($q && !$answers): ?>
  <div class="msg-row msg-in" role="status" aria-label="Oczekuje na odpowiedź">
    <div class="msg-avatar" aria-hidden="true">?</div>
    <div class="msg-typing" aria-hidden="true">
      <span></span><span></span><span></span>
      <span style="width:auto;height:auto;background:none;font-size:.78rem;color:var(--tz-ink-muted,#9CA3AF)">Oczekuje na odpowiedź…</span>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php endforeach; ?>
</div>
<?php endif; /* messages */ ?>

<!-- Formularz -->
<div class="tz-card" role="region" aria-label="Nowa wiadomość">
  <div class="tz-card__hd">
    <i class="bi bi-pencil-square me-1" aria-hidden="true"></i>Zadaj nowe pytanie
  </div>
  <div class="tz-card__bd">
    <form method="post" action="<?= APP_URL ?>/panel/messages.php" id="msg-form">
      <input type="hidden" name="_csrf"            value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="_msg_send"         value="1">
      <input type="hidden" name="msg_ctx_id"        value="<?= (int)$_active['id'] ?>">
      <input type="hidden" name="msg_contract_type" value="<?= h($_active['contract_type']) ?>">
      <input type="hidden" name="recipient_type"    id="recipient_type_hidden" value="opiekun">
      <div class="d-flex gap-2 flex-wrap mb-3">
        <button type="button" class="msg-recipient-btn active" id="btn-opiekun"
                onclick="selectRecipient('opiekun')" aria-pressed="true">
          <i class="bi bi-person-check" aria-hidden="true"></i>Opiekun umowy
        </button>
        <button type="button" class="msg-recipient-btn" id="btn-admin"
                onclick="selectRecipient('admin')" aria-pressed="false">
          <i class="bi bi-shield-check" aria-hidden="true"></i>Administrator
        </button>
      </div>
      <?php if ($_msg_types): ?>
      <div class="mb-2">
        <label for="msg_type_id" class="form-label small fw-semibold mb-1">Rodzaj <span class="text-muted fw-normal">(opcjonalnie)</span></label>
        <select id="msg_type_id" name="msg_type_id" class="form-select form-select-sm" style="border-radius:8px">
          <option value="">— wybierz rodzaj —</option>
          <?php foreach ($_msg_types as $_mt): ?>
          <option value="<?= (int)$_mt['id'] ?>"><?= h($_mt['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <div class="mb-2">
        <label for="msg_subject" class="form-label small fw-semibold mb-1">Temat <span class="text-muted fw-normal">(opcjonalnie)</span></label>
        <input type="text" id="msg_subject" name="msg_subject" class="form-control form-control-sm"
               style="border-radius:8px" placeholder="Np. Pytanie o harmonogram…" maxlength="120">
      </div>
      <div class="mb-3">
        <label for="msg_body" class="form-label small fw-semibold mb-1">Wiadomość <span class="text-danger">*</span></label>
        <textarea id="msg_body" name="msg_body" class="form-control" rows="4"
                  aria-label="Nowa wiadomość"
                  placeholder="Opisz swoje pytanie lub prośbę…" required></textarea>
      </div>
      <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
        <button type="submit" class="tz-btn">
          <i class="bi bi-send-fill" aria-hidden="true"></i>Wyślij
        </button>
        <span class="text-muted" style="font-size:.76rem">
          <i class="bi bi-clock me-1" aria-hidden="true"></i>Odpowiadamy w ciągu 1–2 dni
        </span>
      </div>
    </form>
  </div>
</div>

<script>
function selectRecipient(val) {
  document.getElementById('recipient_type_hidden').value = val;
  document.getElementById('btn-opiekun').classList.toggle('active', val==='opiekun');
  document.getElementById('btn-opiekun').setAttribute('aria-pressed', val==='opiekun');
  document.getElementById('btn-admin').classList.toggle('active', val==='admin');
  document.getElementById('btn-admin').setAttribute('aria-pressed', val==='admin');
}
// Scroll to bottom of chat on load
document.addEventListener('DOMContentLoaded', function() {
  var chat = document.querySelector('.chat-wrap');
  if (chat) chat.scrollIntoView({behavior:'smooth', block:'end'});
});
</script>

<?php endif; /* $_active */ ?>
<?php endif; /* contracts */ ?>

<?php else: /* !$_is_volunteer_only — admin/editor layout */ ?>

<div class="pv-page-header">
  <div class="pv-page-head-main">
    <a href="<?= APP_URL ?>/panel/index.php" class="pv-page-back">
      <i class="bi bi-arrow-left" aria-hidden="true"></i> Panel
    </a>
    <h1 class="pv-page-title">
      <i class="bi bi-chat-dots" aria-hidden="true"></i>Wiadomości
      <?php if ($_active): ?>
      <span class="tz-badge ms-1"><?= h($_active['numer_umowy']) ?></span>
      <?php endif; ?>
    </h1>
    <p class="pv-page-sub">Korespondencja z koordynatorem</p>
  </div>
</div>

<?= flash_html() ?>

<?php if (!$contracts): ?>
<div class="tz-note tz-note--warning mb-4">Nie masz przypisanej żadnej umowy.</div>

<?php elseif (count($contracts) > 1): ?>
<!-- ── Przełącznik umów (wiele umów) ──────────────────────────────────── -->
<div class="tz-card mb-4">
  <div class="tz-card__bd py-2">
    <div class="d-flex align-items-center gap-3 flex-wrap">
      <span class="text-muted small fw-semibold text-nowrap">
        <i class="bi bi-arrow-left-right" aria-hidden="true"></i> Umowa:
      </span>
      <div class="d-flex gap-2 flex-wrap" role="list" aria-label="Przełącznik umów">
        <?php foreach ($contracts as $c):
          $is_active = $_active
            && $c['contract_type'] === $_active['contract_type']
            && (int) $c['id'] === (int) $_active['id'];
          $unr = msg_unread_thread('contract', (int)$c['id'], 'user');
        ?>
        <a href="?cid=<?= (int)$c['id'] ?>&ctype=<?= h($c['contract_type']) ?>"
           class="tz-btn <?= $is_active ? '' : 'tz-btn--ghost' ?>"
           role="listitem"
           aria-current="<?= $is_active ? 'page' : 'false' ?>"
           aria-label="Umowa <?= h($c['numer_umowy']) ?><?= $unr ? ", $unr nieprzeczytanych" : '' ?>">
          <?= h($c['numer_umowy']) ?>
          <?php if ($unr): ?>
          <span class="tz-badge ms-1" aria-hidden="true"><?= $unr ?></span>
          <?php endif; ?>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($_active): ?>

<?php if (!$messages): ?>
<!-- ── Brak wiadomości ────────────────────────────────────────────── -->
<div class="tz-empty mb-4" role="status">
  <i class="bi bi-chat-left-dots tz-empty__icon" aria-hidden="true" style="font-size:3rem;opacity:.25;display:block;margin-bottom:.75rem"></i>
  <p class="tz-empty__title fw-semibold">Brak wiadomości</p>
  <p class="tz-empty__sub small mt-1">Wyślij swoje pierwsze pytanie korzystając z formularza poniżej.</p>
</div>

<?php else: ?>
<!-- ── Lista pytań i odpowiedzi ───────────────────────────────────── -->
<div class="d-flex flex-column gap-3 mb-4" role="log" aria-label="Historia korespondencji" aria-live="polite">
  <?php
  // Group messages: pairs of (question + following answers)
  // We display them grouped: user question followed immediately by admin replies
  $grouped = [];
  $current_q = null;
  foreach ($messages as $m) {
      if ($m['sender_type'] === 'user') {
          if ($current_q !== null) $grouped[] = $current_q;
          $current_q = ['question' => $m, 'answers' => []];
      } else {
          // admin reply
          if ($current_q === null) {
              // admin message without preceding question — treat as orphan
              $current_q = ['question' => null, 'answers' => [$m]];
          } else {
              $current_q['answers'][] = $m;
          }
      }
  }
  if ($current_q !== null) $grouped[] = $current_q;

  foreach ($grouped as $pair):
      $q = $pair['question'];
      $answers = $pair['answers'];
  ?>

  <div aria-label="<?= $q ? 'Wątek: ' . h(mb_substr($q['subject'] ?? $q['body'] ?? 'Wiadomość', 0, 40)) : 'Odpowiedź admina' ?>">
    <?php if ($q): ?>
    <?php
      $rt       = $q['recipient_type'] ?? 'admin';
      $rt_label = $rt === 'opiekun' ? 'Opiekun umowy' : 'Administrator';
      $rt_cls   = $rt === 'opiekun' ? 'rt-opiekun' : 'rt-admin';
      $rt_icon  = $rt === 'opiekun' ? 'bi-person-check' : 'bi-shield-check';
      $subj     = $q['subject'] ?? '';
    ?>
    <!-- pytanie -->
    <div class="tz-card qa-question mb-2">
      <div class="tz-card__hd qa-hdr-q d-flex align-items-center gap-2 flex-wrap">
        <i class="bi bi-question-circle-fill text-primary" aria-hidden="true"></i>
        <?php if ($subj): ?>
        <span class="qa-subject"><?= h($subj) ?></span>
        <?php else: ?>
        <span class="text-muted small">Pytanie</span>
        <?php endif; ?>
        <span class="tz-badge <?= $rt_cls ?> ms-1">
          <i class="bi <?= $rt_icon ?> me-1" aria-hidden="true"></i>Do: <?= $rt_label ?>
        </span>
        <span class="text-muted small ms-auto" style="font-size:.71rem">
          <?= date('d.m.Y, H:i', strtotime($q['created_at'])) ?>
        </span>
      </div>
      <div class="tz-card__bd" style="font-size:.9rem;line-height:1.6;white-space:pre-line">
        <?= h($q['body']) ?>
      </div>
    </div>
    <?php endif; ?>

    <?php foreach ($answers as $ans):
        $is_unread = !(int)($ans['is_read']);
        $subj_a    = $ans['subject'] ?? '';
    ?>
    <!-- odpowiedź -->
    <div class="tz-card qa-answer ms-4 <?= $is_unread ? 'unread-ans' : '' ?>">
      <div class="tz-card__hd qa-hdr-a d-flex align-items-center gap-2 flex-wrap">
        <i class="bi bi-check-circle-fill text-success" aria-hidden="true"></i>
        <?php if ($subj_a): ?>
        <span class="qa-subject text-success"><?= h($subj_a) ?></span>
        <?php else: ?>
        <span class="fw-semibold small text-success">Odpowiedź</span>
        <?php endif; ?>
        <?php if ($ans['sender_name']): ?>
        <span class="text-muted small">— <?= h($ans['sender_name']) ?></span>
        <?php endif; ?>
        <?php if ($is_unread): ?>
        <span class="tz-badge ms-1" style="font-size:.65rem">Nowa</span>
        <?php endif; ?>
        <span class="text-muted small ms-auto" style="font-size:.71rem">
          <?= date('d.m.Y, H:i', strtotime($ans['created_at'])) ?>
        </span>
      </div>
      <div class="tz-card__bd" style="font-size:.9rem;line-height:1.6">
        <?php if ($ans['sender_type'] === 'admin'): ?>
          <?= $ans['body'] /* HTML z CKEditor — zaufany, admin */ ?>
        <?php else: ?>
          <?= nl2br(h($ans['body'])) ?>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>

    <?php if ($q && !$answers): ?>
    <!-- brak odpowiedzi -->
    <div class="ms-4 text-muted small d-flex align-items-center gap-1 mt-1 mb-1">
      <i class="bi bi-hourglass-split" aria-hidden="true"></i> Oczekuje na odpowiedź…
    </div>
    <?php endif; ?>
  </div>

  <?php endforeach; ?>
</div>
<?php endif; /* messages */ ?>

<!-- ══════════════════════════════════════════════════════════════════
     Formularz nowego pytania
══════════════════════════════════════════════════════════════════ -->
<div class="tz-card">
  <div class="tz-card__hd">
    <i class="bi bi-pencil-square me-1" aria-hidden="true"></i>
    Zadaj nowe pytanie
  </div>
  <div class="tz-card__bd">

    <form method="post" action="<?= APP_URL ?>/panel/messages.php">
      <input type="hidden" name="_csrf"            value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="_msg_send"         value="1">
      <input type="hidden" name="msg_ctx_id"        value="<?= (int) $_active['id'] ?>">
      <input type="hidden" name="msg_contract_type" value="<?= h($_active['contract_type']) ?>">

      <!-- Adresat -->
      <div class="mb-3">
        <label class="form-label fw-semibold small mb-2">
          Adresuj do <span class="text-danger">*</span>
        </label>
        <div class="d-flex gap-4">
          <div class="form-check">
            <input class="form-check-input" type="radio"
                   name="recipient_type" id="rt_opiekun" value="opiekun" checked>
            <label class="form-check-label" for="rt_opiekun">
              <i class="bi bi-person-check text-warning me-1" aria-hidden="true"></i>
              <strong>Opiekun umowy</strong>
              <div class="text-muted" style="font-size:.75rem;margin-top:1px">
                Pytania dot. realizacji umowy, harmonogramu, zadań
              </div>
            </label>
          </div>
          <div class="form-check">
            <input class="form-check-input" type="radio"
                   name="recipient_type" id="rt_admin" value="admin">
            <label class="form-check-label" for="rt_admin">
              <i class="bi bi-shield-check text-info me-1" aria-hidden="true"></i>
              <strong>Administrator</strong>
              <div class="text-muted" style="font-size:.75rem;margin-top:1px">
                Zmiany danych, wynagrodzenie, dokumenty, konto M365
              </div>
            </label>
          </div>
        </div>
      </div>

      <?php if ($_msg_types): ?>
      <!-- Rodzaj pytania -->
      <div class="mb-3">
        <label for="msg_type_id" class="form-label small fw-semibold">
          Rodzaj pytania <span class="text-muted fw-normal">(opcjonalnie)</span>
        </label>
        <select id="msg_type_id" name="msg_type_id" class="form-select form-select-sm">
          <option value="">— wybierz rodzaj —</option>
          <?php foreach ($_msg_types as $_mt): ?>
          <option value="<?= (int)$_mt['id'] ?>"><?= h($_mt['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>

      <!-- Temat -->
      <div class="mb-3">
        <label for="msg_subject" class="form-label small fw-semibold">
          Temat <span class="text-muted fw-normal">(opcjonalnie)</span>
        </label>
        <input type="text" id="msg_subject" name="msg_subject"
               class="form-control form-control-sm"
               placeholder="Np. Pytanie o harmonogram, Zmiana adresu…"
               maxlength="120">
      </div>

      <!-- Treść -->
      <div class="mb-3">
        <label for="msg_body" class="form-label small fw-semibold">
          Treść <span class="text-danger">*</span>
        </label>
        <textarea id="msg_body" name="msg_body" class="form-control"
                  rows="5"
                  aria-label="Nowa wiadomość"
                  placeholder="Opisz szczegółowo swoje pytanie lub prośbę…"
                  required></textarea>
      </div>

      <div class="d-flex align-items-center gap-3">
        <button type="submit" class="tz-btn">
          <i class="bi bi-send me-1" aria-hidden="true"></i> Wyślij pytanie
        </button>
        <span class="text-muted small">
          <i class="bi bi-clock me-1" aria-hidden="true"></i>Odpowiadamy zwykle w ciągu 1–2 dni roboczych
        </span>
      </div>

    </form>
  </div>
</div>

<?php endif; /* $_active */ ?>

<?php endif; /* $_is_volunteer_only */ ?>

</div><!-- /pv-wrap -->

<?php if ($_is_volunteer_only) {
    include __DIR__ . '/includes/footer_panel.php';
} else {
    include dirname(__DIR__) . '/includes/footer.php';
} ?>
