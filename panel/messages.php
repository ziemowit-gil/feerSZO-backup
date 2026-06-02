<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/messages.php';
require_once dirname(__DIR__) . '/includes/approval.php';

require_login();
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
/* ── Q&A cards ──────────────────────────────────────────────────────── */
.qa-question {
  border-left: 4px solid #2563eb;
  background: #fff;
}
.qa-answer {
  border-left: 4px solid #16a34a;
  background: #f0fdf4;
  margin-left: 1.75rem;
}
.qa-answer.unread-ans {
  background: #dcfce7;
}
.qa-hdr-q { background: #eff6ff !important; border-bottom-color: #bfdbfe !important; }
.qa-hdr-a { background: #dcfce7 !important; border-bottom-color: #bbf7d0 !important; }

/* ── Subject pill ───────────────────────────────────────────────────── */
.qa-subject {
  font-weight: 600;
  font-size: .83rem;
  color: #1e293b;
}

/* ── Recipient badge ────────────────────────────────────────────────── */
.rt-opiekun { background: #fef9c3; color: #92400e; border: 1px solid #fde68a; }
.rt-admin   { background: #e0f2fe; color: #075985; border: 1px solid #bae6fd; }
</style>

<?php if ($_is_volunteer_only): ?>

<div class="pv-page-header">
  <h1 class="pv-page-title"><i class="bi bi-chat-left-text me-2" aria-hidden="true"></i>Wiadomości</h1>
  <p class="pv-page-sub">Korespondencja z organizacją</p>
</div>

<?= flash_html() ?>

<?php if (!$contracts): ?>
<div class="vol-detail-card mb-4">
  <div class="vol-detail-header"><i class="bi bi-exclamation-circle me-2"></i>Brak umów</div>
  <div class="vol-detail-body">
    <div class="alert alert-warning mb-0">Nie masz przypisanej żadnej umowy.</div>
  </div>
</div>
<?php else: ?>

<?php if (count($contracts) > 1): ?>
<div class="vol-detail-card mb-4">
  <div class="vol-detail-header"><i class="bi bi-arrow-left-right me-2"></i>Wybierz umowę</div>
  <div class="vol-detail-body">
    <div class="d-flex gap-2 flex-wrap">
      <?php foreach ($contracts as $c):
        $is_active = $_active
          && $c['contract_type'] === $_active['contract_type']
          && (int) $c['id'] === (int) $_active['id'];
        $unr = msg_unread_thread('contract', (int)$c['id'], 'user');
      ?>
      <a href="?cid=<?= (int)$c['id'] ?>&ctype=<?= h($c['contract_type']) ?>"
         class="btn btn-sm <?= $is_active ? 'btn-primary' : 'btn-outline-secondary' ?>">
        <?= h($c['numer_umowy']) ?>
        <?php if ($unr): ?>
        <span class="badge bg-danger ms-1"><?= $unr ?></span>
        <?php endif; ?>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($_active): ?>

<?php if (!$messages): ?>
<div class="vol-detail-card mb-4">
  <div class="vol-detail-header"><i class="bi bi-chat-left-dots me-2"></i>Wiadomości</div>
  <div class="vol-detail-body text-center py-4 text-muted">
    <i class="bi bi-chat-left-dots" style="font-size:3rem;opacity:.25"></i>
    <div class="mt-3 fw-semibold">Brak wiadomości</div>
    <div class="small mt-1">Wyślij swoje pierwsze pytanie korzystając z formularza poniżej.</div>
  </div>
</div>
<?php else: ?>
<div class="vol-detail-card mb-4">
  <div class="vol-detail-header"><i class="bi bi-chat-left-text me-2"></i>Korespondencja</div>
  <div class="vol-detail-body">
    <div class="d-flex flex-column gap-3">
      <?php
      $grouped = [];
      $current_q = null;
      foreach ($messages as $m) {
          if ($m['sender_type'] === 'user') {
              if ($current_q !== null) $grouped[] = $current_q;
              $current_q = ['question' => $m, 'answers' => []];
          } else {
              if ($current_q === null) {
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
      <div>
        <?php if ($q): ?>
        <?php
          $rt       = $q['recipient_type'] ?? 'admin';
          $rt_label = $rt === 'opiekun' ? 'Opiekun umowy' : 'Administrator';
          $rt_cls   = $rt === 'opiekun' ? 'rt-opiekun' : 'rt-admin';
          $rt_icon  = $rt === 'opiekun' ? 'bi-person-check' : 'bi-shield-check';
          $subj     = $q['subject'] ?? '';
        ?>
        <div class="card shadow-sm qa-question mb-2">
          <div class="card-header py-2 qa-hdr-q d-flex align-items-center gap-2 flex-wrap">
            <i class="bi bi-question-circle-fill text-primary"></i>
            <?php if ($subj): ?>
            <span class="qa-subject"><?= h($subj) ?></span>
            <?php else: ?>
            <span class="text-muted small">Pytanie</span>
            <?php endif; ?>
            <span class="badge <?= $rt_cls ?> ms-1">
              <i class="bi <?= $rt_icon ?> me-1"></i>Do: <?= $rt_label ?>
            </span>
            <span class="text-muted small ms-auto" style="font-size:.71rem">
              <?= date('d.m.Y, H:i', strtotime($q['created_at'])) ?>
            </span>
          </div>
          <div class="card-body py-2 px-3" style="font-size:.9rem;line-height:1.6;white-space:pre-line">
            <?= h($q['body']) ?>
          </div>
        </div>
        <?php endif; ?>
        <?php foreach ($answers as $ans):
            $is_unread = !(int)($ans['is_read']);
            $subj_a    = $ans['subject'] ?? '';
        ?>
        <div class="card shadow-sm qa-answer ms-4 <?= $is_unread ? 'unread-ans' : '' ?>">
          <div class="card-header py-2 qa-hdr-a d-flex align-items-center gap-2 flex-wrap">
            <i class="bi bi-check-circle-fill text-success"></i>
            <?php if ($subj_a): ?>
            <span class="qa-subject text-success"><?= h($subj_a) ?></span>
            <?php else: ?>
            <span class="fw-semibold small text-success">Odpowiedź</span>
            <?php endif; ?>
            <?php if ($ans['sender_name']): ?>
            <span class="text-muted small">— <?= h($ans['sender_name']) ?></span>
            <?php endif; ?>
            <?php if ($is_unread): ?>
            <span class="badge bg-success ms-1" style="font-size:.65rem">Nowa</span>
            <?php endif; ?>
            <span class="text-muted small ms-auto" style="font-size:.71rem">
              <?= date('d.m.Y, H:i', strtotime($ans['created_at'])) ?>
            </span>
          </div>
          <div class="card-body py-2 px-3" style="font-size:.9rem;line-height:1.6">
            <?php if ($ans['sender_type'] === 'admin'): ?>
              <?= $ans['body'] ?>
            <?php else: ?>
              <?= nl2br(h($ans['body'])) ?>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
        <?php if ($q && !$answers): ?>
        <div class="ms-4 text-muted small d-flex align-items-center gap-1 mt-1 mb-1">
          <i class="bi bi-hourglass-split"></i> Oczekuje na odpowiedź…
        </div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php endif; /* messages */ ?>

<div class="vol-detail-card">
  <div class="vol-detail-header"><i class="bi bi-pencil-square me-2"></i>Zadaj nowe pytanie</div>
  <div class="vol-detail-body">
    <form method="post" action="<?= APP_URL ?>/panel/messages.php">
      <input type="hidden" name="_csrf"            value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="_msg_send"         value="1">
      <input type="hidden" name="msg_ctx_id"        value="<?= (int) $_active['id'] ?>">
      <input type="hidden" name="msg_contract_type" value="<?= h($_active['contract_type']) ?>">

      <div class="mb-3">
        <label class="form-label fw-semibold small mb-2">Adresuj do <span class="text-danger">*</span></label>
        <div class="d-flex gap-4">
          <div class="form-check">
            <input class="form-check-input" type="radio" name="recipient_type" id="rt_opiekun" value="opiekun" checked>
            <label class="form-check-label" for="rt_opiekun">
              <i class="bi bi-person-check text-warning me-1"></i>
              <strong>Opiekun umowy</strong>
              <div class="text-muted" style="font-size:.75rem;margin-top:1px">Pytania dot. realizacji umowy, harmonogramu, zadań</div>
            </label>
          </div>
          <div class="form-check">
            <input class="form-check-input" type="radio" name="recipient_type" id="rt_admin" value="admin">
            <label class="form-check-label" for="rt_admin">
              <i class="bi bi-shield-check text-info me-1"></i>
              <strong>Administrator</strong>
              <div class="text-muted" style="font-size:.75rem;margin-top:1px">Zmiany danych, wynagrodzenie, dokumenty, konto M365</div>
            </label>
          </div>
        </div>
      </div>

      <?php if ($_msg_types): ?>
      <div class="mb-3">
        <label for="msg_type_id" class="form-label small fw-semibold">Rodzaj pytania <span class="text-muted fw-normal">(opcjonalnie)</span></label>
        <select id="msg_type_id" name="msg_type_id" class="form-select form-select-sm">
          <option value="">— wybierz rodzaj —</option>
          <?php foreach ($_msg_types as $_mt): ?>
          <option value="<?= (int)$_mt['id'] ?>"><?= h($_mt['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>

      <div class="mb-3">
        <label for="msg_subject" class="form-label small fw-semibold">Temat <span class="text-muted fw-normal">(opcjonalnie)</span></label>
        <input type="text" id="msg_subject" name="msg_subject" class="form-control form-control-sm"
               placeholder="Np. Pytanie o harmonogram, Zmiana adresu…" maxlength="120">
      </div>

      <div class="mb-3">
        <label for="msg_body" class="form-label small fw-semibold">Treść <span class="text-danger">*</span></label>
        <textarea id="msg_body" name="msg_body" class="form-control" rows="5"
                  placeholder="Opisz szczegółowo swoje pytanie lub prośbę…" required></textarea>
      </div>

      <div class="d-flex align-items-center gap-3">
        <button type="submit" class="btn btn-primary">
          <i class="bi bi-send me-1"></i> Wyślij pytanie
        </button>
        <span class="text-muted small">
          <i class="bi bi-clock me-1"></i>Odpowiadamy zwykle w ciągu 1–2 dni roboczych
        </span>
      </div>
    </form>
  </div>
</div>

<?php endif; /* $_active */ ?>
<?php endif; /* contracts */ ?>

<?php else: /* !$_is_volunteer_only — admin/editor layout */ ?>

<div class="d-flex align-items-center gap-2 mb-4">
  <h4 class="mb-0"><i class="bi bi-chat-left-text text-primary"></i> Moje wiadomości</h4>
  <?php if ($_active): ?>
  <span class="badge bg-light text-dark border ms-1"><?= h($_active['numer_umowy']) ?></span>
  <?php endif; ?>
  <a href="<?= APP_URL ?>/panel/index.php" class="btn btn-sm btn-outline-secondary ms-auto">
    <i class="bi bi-arrow-left"></i> Powrót do panelu
  </a>
</div>

<?= flash_html() ?>

<?php if (!$contracts): ?>
<div class="alert alert-warning">Nie masz przypisanej żadnej umowy.</div>

<?php elseif (count($contracts) > 1): ?>
<!-- ── Przełącznik umów (wiele umów) ──────────────────────────────────── -->
<div class="card shadow-sm mb-4 border-primary border-opacity-25">
  <div class="card-body py-2 px-3 d-flex align-items-center gap-3 flex-wrap">
    <span class="text-muted small fw-semibold text-nowrap">
      <i class="bi bi-arrow-left-right text-primary"></i> Umowa:
    </span>
    <div class="d-flex gap-2 flex-wrap">
      <?php foreach ($contracts as $c):
        $is_active = $_active
          && $c['contract_type'] === $_active['contract_type']
          && (int) $c['id'] === (int) $_active['id'];
        $unr = msg_unread_thread('contract', (int)$c['id'], 'user');
      ?>
      <a href="?cid=<?= (int)$c['id'] ?>&ctype=<?= h($c['contract_type']) ?>"
         class="btn btn-sm <?= $is_active ? 'btn-primary' : 'btn-outline-secondary' ?>">
        <?= h($c['numer_umowy']) ?>
        <?php if ($unr): ?>
        <span class="badge bg-danger ms-1"><?= $unr ?></span>
        <?php endif; ?>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($_active): ?>

<?php if (!$messages): ?>
<!-- ── Brak wiadomości ────────────────────────────────────────────── -->
<div class="card shadow-sm mb-4">
  <div class="card-body text-center py-5 text-muted">
    <i class="bi bi-chat-left-dots" style="font-size:3rem;opacity:.25"></i>
    <div class="mt-3 fw-semibold">Brak wiadomości</div>
    <div class="small mt-1">Wyślij swoje pierwsze pytanie korzystając z formularza poniżej.</div>
  </div>
</div>

<?php else: ?>
<!-- ── Lista pytań i odpowiedzi ───────────────────────────────────── -->
<div class="d-flex flex-column gap-3 mb-4">
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

  <div>
    <?php if ($q): ?>
    <?php
      $rt       = $q['recipient_type'] ?? 'admin';
      $rt_label = $rt === 'opiekun' ? 'Opiekun umowy' : 'Administrator';
      $rt_cls   = $rt === 'opiekun' ? 'rt-opiekun' : 'rt-admin';
      $rt_icon  = $rt === 'opiekun' ? 'bi-person-check' : 'bi-shield-check';
      $subj     = $q['subject'] ?? '';
    ?>
    <!-- pytanie -->
    <div class="card shadow-sm qa-question mb-2">
      <div class="card-header py-2 qa-hdr-q d-flex align-items-center gap-2 flex-wrap">
        <i class="bi bi-question-circle-fill text-primary"></i>
        <?php if ($subj): ?>
        <span class="qa-subject"><?= h($subj) ?></span>
        <?php else: ?>
        <span class="text-muted small">Pytanie</span>
        <?php endif; ?>
        <span class="badge <?= $rt_cls ?> ms-1">
          <i class="bi <?= $rt_icon ?> me-1"></i>Do: <?= $rt_label ?>
        </span>
        <span class="text-muted small ms-auto" style="font-size:.71rem">
          <?= date('d.m.Y, H:i', strtotime($q['created_at'])) ?>
        </span>
      </div>
      <div class="card-body py-2 px-3" style="font-size:.9rem;line-height:1.6;white-space:pre-line">
        <?= h($q['body']) ?>
      </div>
    </div>
    <?php endif; ?>

    <?php foreach ($answers as $ans):
        $is_unread = !(int)($ans['is_read']);
        $subj_a    = $ans['subject'] ?? '';
    ?>
    <!-- odpowiedź -->
    <div class="card shadow-sm qa-answer ms-4 <?= $is_unread ? 'unread-ans' : '' ?>">
      <div class="card-header py-2 qa-hdr-a d-flex align-items-center gap-2 flex-wrap">
        <i class="bi bi-check-circle-fill text-success"></i>
        <?php if ($subj_a): ?>
        <span class="qa-subject text-success"><?= h($subj_a) ?></span>
        <?php else: ?>
        <span class="fw-semibold small text-success">Odpowiedź</span>
        <?php endif; ?>
        <?php if ($ans['sender_name']): ?>
        <span class="text-muted small">— <?= h($ans['sender_name']) ?></span>
        <?php endif; ?>
        <?php if ($is_unread): ?>
        <span class="badge bg-success ms-1" style="font-size:.65rem">Nowa</span>
        <?php endif; ?>
        <span class="text-muted small ms-auto" style="font-size:.71rem">
          <?= date('d.m.Y, H:i', strtotime($ans['created_at'])) ?>
        </span>
      </div>
      <div class="card-body py-2 px-3" style="font-size:.9rem;line-height:1.6">
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
      <i class="bi bi-hourglass-split"></i> Oczekuje na odpowiedź…
    </div>
    <?php endif; ?>
  </div>

  <?php endforeach; ?>
</div>
<?php endif; /* messages */ ?>

<!-- ══════════════════════════════════════════════════════════════════
     Formularz nowego pytania
══════════════════════════════════════════════════════════════════ -->
<div class="card shadow-sm">
  <div class="card-header fw-semibold">
    <i class="bi bi-pencil-square text-primary me-1"></i>
    Zadaj nowe pytanie
  </div>
  <div class="card-body">

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
              <i class="bi bi-person-check text-warning me-1"></i>
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
              <i class="bi bi-shield-check text-info me-1"></i>
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
                  placeholder="Opisz szczegółowo swoje pytanie lub prośbę…"
                  required></textarea>
      </div>

      <div class="d-flex align-items-center gap-3">
        <button type="submit" class="btn btn-primary">
          <i class="bi bi-send me-1"></i> Wyślij pytanie
        </button>
        <span class="text-muted small">
          <i class="bi bi-clock me-1"></i>Odpowiadamy zwykle w ciągu 1–2 dni roboczych
        </span>
      </div>

    </form>
  </div>
</div>

<?php endif; /* $_active */ ?>

<?php endif; /* $_is_volunteer_only */ ?>

<?php if ($_is_volunteer_only) {
    include __DIR__ . '/includes/footer_panel.php';
} else {
    include dirname(__DIR__) . '/includes/footer.php';
} ?>
