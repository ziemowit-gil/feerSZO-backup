<?php
/**
 * karty30/ti/messages.php — Wiadomości prowadzący ↔ kursanci (admin D3).
 * Wysyłka do pojedynczego kursanta, całego kursu lub wszystkich aktywnych kont,
 * przegląd wątków i odpowiadanie. Powiadomienia (e-mail/SMS) wg ustawień kursanta.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/karty30.php';
require_once dirname(dirname(__DIR__)) . '/includes/ti_messages.php';

k30_require_access();
karty30_migrate();
if (!(can_write('karty30') || is_admin())) {
    flash_set('danger', 'Brak uprawnień.'); header('Location: index.php'); exit;
}

$PAGE_TITLE = 'Wiadomości — Zajęcia TI';
$me   = current_user() ?: [];
$myId = (int)($me['id'] ?? 0);
$myNm = (string)($me['name'] ?? $me['username'] ?? 'Prowadzący');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = $_POST['_op'] ?? '';

    if ($op === 'send') {
        $target  = $_POST['target'] ?? 'one';            // one | course | all
        $subject = trim($_POST['subject'] ?? '');
        $body    = trim($_POST['body'] ?? '');
        if ($body === '') {
            flash_set('danger', 'Treść wiadomości jest wymagana.');
            header('Location: messages.php'); exit;
        }
        $ids = [];
        if ($target === 'one') {
            $sid = (int)($_POST['account_id'] ?? 0);
            if ($sid) $ids[] = $sid;
        } elseif ($target === 'course') {
            $cid = (int)($_POST['course_id'] ?? 0);
            $ids = array_map(fn($r) => (int)$r['id'], db_all(
                "SELECT a.id FROM k30_ti_student_accounts a
                 JOIN k30_ti_enrollments e ON e.client_id=a.client_id AND e.course_id=? AND e.status='active'
                 WHERE a.is_active=1",
                [$cid]
            ));
        } elseif ($target === 'all') {
            $ids = array_map(fn($r) => (int)$r['id'], db_all("SELECT id FROM k30_ti_student_accounts WHERE is_active=1"));
        }
        if (!$ids) {
            flash_set('danger', 'Brak adresatów dla wybranej opcji.');
            header('Location: messages.php'); exit;
        }
        $n = ti_msg_broadcast($ids, $subject, $body, $myId, $myNm);
        flash_set('success', "Wiadomość wysłana do {$n} " . ($n === 1 ? 'kursanta' : 'kursantów') . '.');
        header('Location: messages.php' . ($target === 'one' && $ids ? '?student=' . (int)$ids[0] : '')); exit;
    }

    if ($op === 'reply') {
        $sid  = (int)($_POST['student_id'] ?? 0);
        $body = trim($_POST['body'] ?? '');
        $acc  = $sid ? db_one("SELECT id FROM k30_ti_student_accounts WHERE id=?", [$sid]) : null;
        if ($acc && $body !== '') {
            ti_msg_post_to_student($sid, '', $body, $myId, $myNm, true);
            flash_set('success', 'Odpowiedź wysłana.');
        }
        header('Location: messages.php?student=' . $sid); exit;
    }
}

// Wybrany wątek
$student = ($sid = (int)($_GET['student'] ?? 0))
    ? db_one("SELECT a.*, cl.name AS client_name, cl.email AS client_email, cl.phone AS client_phone
              FROM k30_ti_student_accounts a LEFT JOIN k30_clients cl ON cl.id=a.client_id WHERE a.id=?", [$sid])
    : null;
if ($student) ti_msg_mark_read_for_staff((int)$student['id']);

// Lista wątków: konta z jakąkolwiek wiadomością, z licznikiem nieprzeczytanych od kursanta
$threads = db_all(
    "SELECT a.id, COALESCE(cl.name, a.login) AS name, a.login,
            MAX(m.created_at) AS last_at,
            SUM(CASE WHEN m.sender='student' AND m.is_read=0 THEN 1 ELSE 0 END) AS unread
     FROM k30_ti_messages m
     JOIN k30_ti_student_accounts a ON a.id=m.student_id
     LEFT JOIN k30_clients cl ON cl.id=a.client_id
     GROUP BY a.id
     ORDER BY last_at DESC"
);

// Dane do formularza wysyłki
$accounts = db_all(
    "SELECT a.id, COALESCE(cl.name, a.login) AS name, a.login
     FROM k30_ti_student_accounts a LEFT JOIN k30_clients cl ON cl.id=a.client_id
     WHERE a.is_active=1 ORDER BY name"
);
$courses = db_all("SELECT id, name FROM k30_ti_courses WHERE is_active=1 ORDER BY name");
$thread_msgs = $student ? ti_msg_list_for_student((int)$student['id']) : [];

include dirname(dirname(__DIR__)) . '/karty30/includes/header_k30.php';
?>

<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/karty30/index.php">Dydaktyka 3</a></li>
  <li class="breadcrumb-item"><a href="index.php">Zajęcia TI</a></li>
  <li class="breadcrumb-item active">Wiadomości</li>
</ol></nav>

<h4 class="mb-3 fw-bold"><i class="bi bi-envelope text-primary me-2"></i>Wiadomości — kursanci</h4>

<?= flash_html() ?>

<div class="row g-4">
  <!-- Nowa wiadomość -->
  <div class="col-lg-5">
    <div class="card border-0 shadow-sm">
      <div class="card-header fw-semibold"><i class="bi bi-pencil-square me-1"></i>Nowa wiadomość</div>
      <div class="card-body">
        <form method="post">
          <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="_op" value="send">
          <div class="mb-2">
            <label class="form-label small">Adresat</label>
            <select class="form-select form-select-sm" name="target" id="tgt" onchange="document.getElementById('tgtOne').classList.toggle('d-none', this.value!=='one');document.getElementById('tgtCourse').classList.toggle('d-none', this.value!=='course');">
              <option value="one">Wybrany kursant</option>
              <option value="course">Wszyscy w kursie</option>
              <option value="all">Wszyscy aktywni kursanci</option>
            </select>
          </div>
          <div class="mb-2" id="tgtOne">
            <label class="form-label small">Kursant</label>
            <select class="form-select form-select-sm" name="account_id">
              <option value="">— wybierz —</option>
              <?php foreach ($accounts as $a): ?>
              <option value="<?= (int)$a['id'] ?>" <?= ($student && (int)$student['id'] === (int)$a['id']) ? 'selected' : '' ?>>
                <?= h($a['name']) ?> (<?= h($a['login']) ?>)
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-2 d-none" id="tgtCourse">
            <label class="form-label small">Kurs</label>
            <select class="form-select form-select-sm" name="course_id">
              <option value="">— wybierz —</option>
              <?php foreach ($courses as $c): ?>
              <option value="<?= (int)$c['id'] ?>"><?= h($c['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-2">
            <label class="form-label small">Temat (opcjonalnie)</label>
            <input class="form-control form-control-sm" name="subject" maxlength="200" placeholder="np. Zmiana terminu zajęć">
          </div>
          <div class="mb-2">
            <label class="form-label small">Treść</label>
            <textarea class="form-control form-control-sm" name="body" rows="4" maxlength="4000" required></textarea>
          </div>
          <button class="btn btn-primary btn-sm"><i class="bi bi-send me-1"></i>Wyślij</button>
          <p class="form-text small mb-0 mt-2">Kursant dostanie powiadomienie e-mail/SMS zgodnie ze swoimi ustawieniami w panelu.</p>
        </form>
      </div>
    </div>

    <!-- Wątki -->
    <div class="card border-0 shadow-sm mt-4">
      <div class="card-header fw-semibold"><i class="bi bi-chat-left-dots me-1"></i>Wątki (<?= count($threads) ?>)</div>
      <div class="list-group list-group-flush">
        <?php if (!$threads): ?>
        <div class="list-group-item text-muted small">Brak wątków.</div>
        <?php else: foreach ($threads as $t): ?>
        <a href="?student=<?= (int)$t['id'] ?>" class="list-group-item list-group-item-action d-flex align-items-center gap-2 <?= ($student && (int)$student['id']===(int)$t['id']) ? 'active' : '' ?>">
          <i class="bi bi-person-circle"></i>
          <span class="flex-grow-1"><?= h($t['name']) ?>
            <span class="d-block small <?= ($student && (int)$student['id']===(int)$t['id']) ? 'text-white-50' : 'text-muted' ?>"><?= $t['last_at'] ? date('d.m.Y H:i', strtotime($t['last_at'])) : '' ?></span>
          </span>
          <?php if ((int)$t['unread'] > 0): ?><span class="badge bg-danger rounded-pill"><?= (int)$t['unread'] ?></span><?php endif; ?>
        </a>
        <?php endforeach; endif; ?>
      </div>
    </div>
  </div>

  <!-- Wątek -->
  <div class="col-lg-7">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header fw-semibold">
        <i class="bi bi-chat-text me-1"></i>
        <?= $student ? 'Wątek: ' . h($student['client_name'] ?? $student['login']) : 'Wybierz wątek' ?>
      </div>
      <div class="card-body">
        <?php if (!$student): ?>
          <p class="text-muted small mb-0">Wybierz wątek z listy po lewej lub napisz nową wiadomość.</p>
        <?php else: ?>
          <?php if (!$thread_msgs): ?>
            <p class="text-muted small">Brak wiadomości w tym wątku.</p>
          <?php else: ?>
            <div class="d-flex flex-column gap-2 mb-3" style="max-height:55vh;overflow-y:auto">
              <?php foreach ($thread_msgs as $m):
                $staff = ($m['sender'] ?? '') === 'staff';
                $ts    = $m['created_at'] ? date('d.m.Y H:i', strtotime($m['created_at'])) : '';
              ?>
              <div class="d-flex <?= $staff ? 'justify-content-end' : 'justify-content-start' ?>">
                <div class="p-2 px-3 rounded-3 <?= $staff ? 'bg-primary text-white' : 'bg-body-tertiary border' ?>" style="max-width:85%">
                  <div class="small fw-semibold mb-1 <?= $staff ? 'text-white-50' : 'text-muted' ?>">
                    <?= $staff ? h($m['sender_name'] !== '' ? $m['sender_name'] : 'Prowadzący') : h($m['sender_name'] !== '' ? $m['sender_name'] : 'Kursant') ?>
                    <span class="ms-2 fw-normal"><?= h($ts) ?></span>
                  </div>
                  <?php if ($staff && trim((string)$m['subject']) !== ''): ?>
                  <div class="fw-bold mb-1"><?= h($m['subject']) ?></div>
                  <?php endif; ?>
                  <div style="white-space:pre-wrap;word-break:break-word"><?= nl2br(h($m['body'])) ?></div>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
          <form method="post">
            <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="_op" value="reply">
            <input type="hidden" name="student_id" value="<?= (int)$student['id'] ?>">
            <label class="form-label small fw-semibold" for="rbody">Odpowiedz</label>
            <textarea class="form-control form-control-sm mb-2" id="rbody" name="body" rows="3" maxlength="4000" required placeholder="Treść odpowiedzi…"></textarea>
            <button class="btn btn-primary btn-sm"><i class="bi bi-send me-1"></i>Wyślij odpowiedź</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php include dirname(dirname(__DIR__)) . '/karty30/includes/footer_k30.php'; ?>
