<?php
/**
 * Panel kursanta TI — dashboard: moje lekcje, rozliczenia, VLab.
 * UI: Bootstrap 5.3 (motyw ciemny) + WCAG 2.1 AA.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/functions.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/karty30.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/vlab.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/sms.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_messages.php';
require_once __DIR__ . '/auth.php';

karty30_migrate();

// Zakończenie podglądu administratora („zaloguj jako") — wróć do listy kont kursantów.
if (isset($_GET['stop_impersonation'])) {
    $was_imp = student_impersonator() !== null;
    student_logout();
    header('Location: ' . ($was_imp ? rtrim(APP_URL, '/') . '/karty30/ti/kursant/accounts.php' : 'login.php'));
    exit;
}

// Wylogowanie (przed jakimkolwiek wyjściem)
if (isset($_GET['logout'])) { student_logout(); header('Location: login.php'); exit; }

$student    = student_require();
$tab        = $_GET['tab'] ?? 'lekcje';
$vlab_token = student_token();

// Dane kursanta
$account = db_one("SELECT * FROM k30_ti_student_accounts WHERE id=?", [$student['id']]);
// Konto usunięte/zablokowane w trakcie sesji → wyloguj
if (!$account || empty($account['is_active'])) { student_logout(); header('Location: login.php'); exit; }
$client  = db_one("SELECT * FROM k30_clients WHERE id=?", [$student['client_id']]) ?: [];

// ── Odwołanie / przywrócenie udziału w lekcji przez Beneficjenta ─────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $op  = $_POST['_op'] ?? '';
    $tok = $_POST['_token'] ?? '';
    if (!hash_equals(student_token(), (string)$tok)) { http_response_code(403); exit('Nieprawidłowy token sesji.'); }

    if ($op === 'cancel_lesson' || $op === 'uncancel_lesson') {
        $sid = (int)($_POST['session_id'] ?? 0);
        // Lekcja musi należeć do kursu, do którego kursant jest aktywnie zapisany, i być zaplanowana
        $own = db_one(
            "SELECT s.id, s.status FROM k30_ti_sessions s
             JOIN k30_ti_enrollments e ON e.course_id=s.course_id AND e.client_id=? AND e.status='active'
             WHERE s.id=?",
            [$student['client_id'], $sid]
        );
        if ($own && $own['status'] === 'planned') {
            if ($op === 'cancel_lesson') {
                $reason = trim($_POST['reason'] ?? '');
                k30_ti_cancel_attendance(
                    $sid, $student['client_id'],
                    $reason !== '' ? $reason : 'Odwołane przez beneficjenta',
                    'beneficjent', $client['name'] ?? ''
                );
            } else {
                k30_ti_uncancel_attendance($sid, $student['client_id']);
            }
        }
        header('Location: index.php?tab=lekcje'); exit;
    }

    if ($op === 'reset_calendar_token') {
        k30_ti_calendar_token_reset((int)$student['id']);
        header('Location: index.php?tab=ustawienia&cal=reset'); exit;
    }

    if ($op === 'toggle_sms_lessons') {
        $on = !empty($_POST['enabled']) ? 1 : 0;
        db_update('k30_ti_student_accounts', ['notify_sms_lessons' => $on], (int)$student['id']);
        header('Location: index.php?tab=ustawienia&sms=' . ($on ? 'on' : 'off')); exit;
    }

    // Zapis ustawień powiadomień o wiadomościach (e-mail / SMS)
    if ($op === 'msg_prefs') {
        db_update('k30_ti_student_accounts', [
            'notify_email_messages' => !empty($_POST['email']) ? 1 : 0,
            'notify_sms_messages'   => !empty($_POST['sms'])   ? 1 : 0,
        ], (int)$student['id']);
        header('Location: index.php?tab=ustawienia&prefs=1'); exit;
    }

    // Odpowiedź kursanta w wątku wiadomości
    if ($op === 'msg_reply') {
        $body = trim((string)($_POST['body'] ?? ''));
        if ($body !== '') {
            ti_msg_student_reply((int)$student['id'], mb_substr($body, 0, 4000));
            header('Location: index.php?tab=wiadomosci&sent=1'); exit;
        }
        header('Location: index.php?tab=wiadomosci'); exit;
    }

    // Zapis dodatkowych numerów telefonu do powiadomień SMS
    if ($op === 'notify_phones') {
        db_update('k30_ti_student_accounts', [
            'notify_phone2' => mb_substr(trim((string)($_POST['phone2'] ?? '')), 0, 30),
            'notify_phone3' => mb_substr(trim((string)($_POST['phone3'] ?? '')), 0, 30),
        ], (int)$student['id']);
        header('Location: index.php?tab=ustawienia&phones=1'); exit;
    }

    // Zmiana hasła do panelu (samoobsługa oraz wymuszona po nadaniu hasła przez admina)
    if ($op === 'change_password') {
        $cur = (string)($_POST['current'] ?? '');
        $new = (string)($_POST['new'] ?? '');
        $cnf = (string)($_POST['confirm'] ?? '');
        $acc = db_one("SELECT password_hash FROM k30_ti_student_accounts WHERE id=?", [(int)$student['id']]);
        $forced = !empty($account['must_change_password']);
        $err = '';
        if (!$acc || !password_verify($cur, $acc['password_hash'])) {
            $err = 'Aktualne hasło jest nieprawidłowe.';
        } elseif (mb_strlen($new) < 8) {
            $err = 'Nowe hasło musi mieć co najmniej 8 znaków.';
        } elseif ($new !== $cnf) {
            $err = 'Nowe hasła nie są identyczne.';
        } elseif ($new === $cur) {
            $err = 'Nowe hasło musi różnić się od dotychczasowego.';
        }
        if ($err !== '') {
            header('Location: index.php?tab=ustawienia' . ($forced ? '&force_pw=1' : '') . '&pwerr=' . rawurlencode($err)); exit;
        }
        db()->prepare("UPDATE k30_ti_student_accounts SET password_hash=?, must_change_password=0, updated_at=datetime('now') WHERE id=?")
           ->execute([password_hash($new, PASSWORD_BCRYPT), (int)$student['id']]);
        header('Location: index.php?tab=ustawienia&pwok=1'); exit;
    }
}

// Kursy i lekcje kursanta
$courses = k30_ti_client_courses($student['client_id']);
$lessons = k30_ti_client_lessons($student['client_id'], 40);

// Statystyki
$total_lessons  = count($lessons);
$attended_count = count(array_filter($lessons, fn($l) => $l['attended']));
$pct = $total_lessons > 0 ? round($attended_count / $total_lessons * 100) : 0;

// Zadania domowe — lekcje odbyte z oznaczonym zadaniem (do bloku na stronie głównej)
$homework_lessons = array_values(array_filter($lessons, fn($l) =>
    !empty($l['has_homework'])
    && ($l['status'] ?? '') === 'held'
    && (int)($l['att_cancelled'] ?? 0) !== 1
    && empty($l['self_prep_remote'])));

// Powiadomienia SMS o zajęciach — zgoda beneficjenta (opt-in)
$sms_pref       = (int)($account['notify_sms_lessons'] ?? 0);
$sms_phone      = trim((string)($client['phone'] ?? ''));
$sms_global_on  = function_exists('sms_is_enabled') && sms_is_enabled();

// Wiadomości — licznik nieprzeczytanych + ustawienia powiadomień
$msg_unread     = ti_msg_unread_for_student((int)$student['id']);
$msg_pref_email = (int)($account['notify_email_messages'] ?? 1);
$msg_pref_sms   = (int)($account['notify_sms_messages'] ?? 0);
$msg_email_addr = trim((string)($client['email'] ?? ''));

// Prywatny kanał iCal lekcji (subskrypcja w Kalendarzu Google / Apple / Outlook)
$cal_token  = k30_ti_calendar_token((int)$account['id']);
$cal_https  = rtrim(APP_URL, '/') . '/karty30/ti/kursant/ical.php?id=' . (int)$account['id'] . '&t=' . $cal_token;
$cal_webcal = preg_replace('#^https?://#i', 'webcal://', $cal_https);
$cal_gcal   = 'https://calendar.google.com/calendar/r?cid=' . rawurlencode($cal_webcal);

$org        = defined('ORG_NAME') ? ORG_NAME : 'Zajęcia TI';
$is_minor   = !empty($account['is_minor']);
$months_pl  = [1=>'Sty',2=>'Lut',3=>'Mar',4=>'Kwi',5=>'Maj',6=>'Cze',
               7=>'Lip',8=>'Sie',9=>'Wrz',10=>'Paź',11=>'Lis',12=>'Gru'];

$KP_TITLE  = 'Panel kursanta';
$KP_TOPBAR = [
    'brand'  => $org,
    'icon'   => 'pc-display',
    'user'   => $client['name'] ?? $account['login'],
    'logout' => 'index.php?logout=1',
];
include __DIR__ . '/_layout_head.php';
?>

<?php if (!empty($account['must_change_password'])):
  // Wymuszona zmiana hasła (np. po nadaniu/zresetowaniu hasła przez admina) — blokuje panel.
  $pwerr = (string)($_GET['pwerr'] ?? '');
?>
<main id="main" class="container-xl px-3 py-4" style="max-width:480px">
  <div class="card shadow-sm border-0">
    <div class="card-body p-4">
      <h1 class="h5 fw-bold d-flex align-items-center gap-2 mb-2"><i class="bi bi-shield-lock text-primary" aria-hidden="true"></i>Ustaw nowe hasło</h1>
      <p class="text-body-secondary small mb-3">Aby kontynuować, ustaw własne hasło do panelu. To wymagane po nadaniu hasła przez administratora.</p>
      <?php if ($pwerr !== ''): ?>
      <div class="alert alert-danger py-2 small" role="alert"><i class="bi bi-exclamation-circle me-1" aria-hidden="true"></i><?= h($pwerr) ?></div>
      <?php endif; ?>
      <form method="post" autocomplete="off">
        <input type="hidden" name="_token" value="<?= h($vlab_token) ?>">
        <input type="hidden" name="_op" value="change_password">
        <div class="mb-3">
          <label class="form-label" for="cp-cur">Aktualne hasło</label>
          <input type="password" class="form-control" id="cp-cur" name="current" required autocomplete="current-password" autofocus>
        </div>
        <div class="mb-3">
          <label class="form-label" for="cp-new">Nowe hasło</label>
          <input type="password" class="form-control" id="cp-new" name="new" required minlength="8" autocomplete="new-password" placeholder="min. 8 znaków">
        </div>
        <div class="mb-3">
          <label class="form-label" for="cp-cnf">Powtórz nowe hasło</label>
          <input type="password" class="form-control" id="cp-cnf" name="confirm" required minlength="8" autocomplete="new-password">
        </div>
        <button type="submit" class="btn btn-primary w-100 fw-semibold"><i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Zapisz nowe hasło</button>
      </form>
      <p class="text-center mt-3 mb-0"><a href="index.php?logout=1" class="small text-body-secondary">Wyloguj się</a></p>
    </div>
  </div>
</main>
<?php include __DIR__ . '/_layout_foot.php'; exit; endif; ?>

<nav class="container-xl px-3 pt-3" aria-label="Sekcje panelu">
  <ul class="nav nav-tabs">
    <li class="nav-item">
      <a class="nav-link <?= $tab==='lekcje'?'active':'' ?>" href="?tab=lekcje" <?= $tab==='lekcje'?'aria-current="page"':'' ?>>
        <i class="bi bi-calendar-check me-1" aria-hidden="true"></i>Moje lekcje
      </a>
    </li>
    <?php if (!$is_minor): ?>
    <li class="nav-item">
      <a class="nav-link <?= $tab==='rozliczenia'?'active':'' ?>" href="?tab=rozliczenia" <?= $tab==='rozliczenia'?'aria-current="page"':'' ?>>
        <i class="bi bi-receipt me-1" aria-hidden="true"></i>Rozliczenia
      </a>
    </li>
    <?php endif; ?>
    <li class="nav-item">
      <a class="nav-link <?= $tab==='vlab'?'active':'' ?>" href="?tab=vlab" <?= $tab==='vlab'?'aria-current="page"':'' ?>>
        <i class="bi bi-code-square me-1" aria-hidden="true"></i>VLab
      </a>
    </li>
    <li class="nav-item">
      <a class="nav-link <?= $tab==='wiadomosci'?'active':'' ?>" href="?tab=wiadomosci" <?= $tab==='wiadomosci'?'aria-current="page"':'' ?>>
        <i class="bi bi-envelope me-1" aria-hidden="true"></i>Wiadomości
        <?php if ($msg_unread > 0): ?><span class="badge text-bg-danger ms-1"><?= $msg_unread ?></span><?php endif; ?>
      </a>
    </li>
    <li class="nav-item">
      <a class="nav-link <?= $tab==='online'?'active':'' ?>" href="?tab=online" <?= $tab==='online'?'aria-current="page"':'' ?>>
        <i class="bi bi-camera-video me-1" aria-hidden="true"></i>Szkolenia online
      </a>
    </li>
    <li class="nav-item">
      <a class="nav-link <?= $tab==='ustawienia'?'active':'' ?>" href="?tab=ustawienia" <?= $tab==='ustawienia'?'aria-current="page"':'' ?>>
        <i class="bi bi-gear me-1" aria-hidden="true"></i>Ustawienia
      </a>
    </li>
  </ul>
</nav>

<main id="main" class="container-xl px-3 py-4">

<?php if (!empty($homework_lessons)): ?>
<!-- ── Zadania domowe — widoczne od razu po zalogowaniu ──────────────────────── -->
<section class="card border-warning mb-4" aria-labelledby="hw-heading">
  <div class="card-body">
    <h2 id="hw-heading" class="h6 fw-bold mb-3">
      <i class="bi bi-journal-text text-warning me-2" aria-hidden="true"></i>Zadania domowe
      <span class="badge text-bg-warning ms-1"><?= count($homework_lessons) ?></span>
    </h2>
    <ul class="list-group list-group-flush">
      <?php foreach (array_slice($homework_lessons, 0, 6) as $hl):
        $hd = new DateTime($hl['lesson_date']);
      ?>
      <li class="list-group-item bg-transparent d-flex flex-wrap align-items-center gap-2 px-0">
        <i class="bi bi-pencil-square text-warning" aria-hidden="true"></i>
        <span class="fw-semibold"><?= $hl['topic'] ? h($hl['topic']) : 'Lekcja' ?></span>
        <span class="text-body-secondary small">
          <?= h($hl['course_name']) ?> ·
          <?= $hd->format('d') ?> <?= $months_pl[(int)$hd->format('n')] ?> <?= $hd->format('Y') ?>
        </span>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php if (count($homework_lessons) > 6): ?>
    <p class="text-body-secondary small mb-0 mt-2">
      …i <?= count($homework_lessons) - 6 ?> więcej — zobacz w zakładce „Moje lekcje".
    </p>
    <?php endif; ?>
  </div>
</section>
<?php endif; ?>

<?php if ($tab === 'lekcje'): ?>

  <h1 class="h5 fw-bold mb-3">Moje lekcje</h1>

  <!-- Statystyki -->
  <div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
      <div class="card h-100"><div class="card-body">
        <div class="fs-3 fw-bold lh-1"><?= $total_lessons ?></div>
        <div class="text-body-secondary small mt-1">Wszystkich lekcji</div>
      </div></div>
    </div>
    <div class="col-6 col-lg-3">
      <div class="card h-100"><div class="card-body">
        <div class="fs-3 fw-bold lh-1 text-success"><?= $attended_count ?></div>
        <div class="text-body-secondary small mt-1">Obecności</div>
      </div></div>
    </div>
    <div class="col-6 col-lg-3">
      <div class="card h-100"><div class="card-body">
        <div class="fs-3 fw-bold lh-1"><?= $pct ?>%</div>
        <div class="text-body-secondary small mt-1 mb-1">Frekwencja</div>
        <div class="progress" role="progressbar" aria-label="Frekwencja"
             aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100" style="height:6px">
          <div class="progress-bar" style="width:<?= $pct ?>%"></div>
        </div>
      </div></div>
    </div>
    <div class="col-6 col-lg-3">
      <div class="card h-100"><div class="card-body">
        <div class="fs-3 fw-bold lh-1"><?= count($courses) ?></div>
        <div class="text-body-secondary small mt-1">Kursów/grup</div>
      </div></div>
    </div>
  </div>

  <!-- Moje kursy -->
  <?php if ($courses): ?>
  <div class="mb-3 d-flex flex-wrap gap-2" aria-label="Moje kursy">
    <?php foreach ($courses as $c): ?>
    <span class="badge text-bg-primary fs-6 fw-normal">
      <i class="bi bi-pc-display me-1" aria-hidden="true"></i><?= h($c['course_name']) ?>
    </span>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- Lista lekcji -->
  <div class="card">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <caption class="visually-hidden">Lista ostatnich lekcji z obecnością</caption>
        <thead>
          <tr>
            <th scope="col">Data</th>
            <th scope="col">Kurs</th>
            <th scope="col">Godziny</th>
            <th scope="col">Temat</th>
            <th scope="col">Zadanie</th>
            <th scope="col">Typ lekcji</th>
            <th scope="col" class="text-center">Obecność</th>
            <th scope="col">Uwagi</th>
            <th scope="col" class="text-end">Akcje</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$lessons): ?>
          <tr><td colspan="9" class="text-center text-body-secondary py-4">Brak lekcji.</td></tr>
          <?php endif; ?>
          <?php foreach ($lessons as $l):
            $d   = new DateTime($l['lesson_date']);
            $dow = ['Nd','Pn','Wt','Śr','Czw','Pt','Sb'][(int)$d->format('w')];
          ?>
          <tr>
            <td class="text-nowrap">
              <span class="text-body-secondary small"><?= $dow ?></span>
              <span class="fw-semibold"><?= $d->format('d') ?></span>
              <span class="text-body-secondary small"><?= $months_pl[(int)$d->format('n')] ?> <?= $d->format('Y') ?></span>
            </td>
            <td class="text-body-secondary small"><?= h($l['course_name']) ?></td>
            <td class="text-nowrap small">
              <?= $l['time_from'] ? h($l['time_from']).'–'.h($l['time_to']) : ((int)$l['duration_min']).' min' ?>
            </td>
            <td style="max-width:240px">
              <?php if ($l['topic']): ?>
              <?= h($l['topic']) ?>
              <?php else: ?><span class="text-body-secondary">—</span><?php endif; ?>
            </td>
            <td>
              <?php if (($l['has_homework'] ?? 0) && empty($l['self_prep_remote'])): ?>
              <span class="badge text-bg-warning"><i class="bi bi-journal-text me-1" aria-hidden="true"></i>zadanie</span>
              <?php else: ?><span class="text-body-secondary">—</span><?php endif; ?>
            </td>
            <td>
              <?php if (!empty($l['self_prep_remote'])): ?>
              <span class="badge text-bg-info"><i class="bi bi-laptop me-1" aria-hidden="true"></i>Przygotowanie materiałów</span>
              <?php else: ?>
              <span class="badge text-bg-secondary"><i class="bi bi-person-video3 me-1" aria-hidden="true"></i>Lekcja z uczestnikiem</span>
              <?php endif; ?>
            </td>
            <?php $att_cancelled = (int)($l['att_cancelled'] ?? 0) === 1; ?>
            <td class="text-center">
              <?php if ($att_cancelled): ?>
              <span class="badge text-bg-danger" title="<?= h($l['att_cancel_reason'] ?? '') ?>"><i class="bi bi-x-octagon me-1" aria-hidden="true"></i>odwołane</span>
              <?php elseif ($l['status'] !== 'held'): ?>
              <span class="badge text-bg-secondary"><?= $l['status']==='planned'?'planowana':h($l['status']) ?></span>
              <?php elseif ($l['attended']): ?>
              <span class="badge text-bg-success"><i class="bi bi-check-lg me-1" aria-hidden="true"></i>obecny</span>
              <?php else: ?>
              <span class="badge text-bg-danger"><i class="bi bi-x-lg me-1" aria-hidden="true"></i>nieobecny</span>
              <?php endif; ?>
            </td>
            <td class="small text-body-secondary">
              <?php if ($att_cancelled && !empty($l['att_cancel_reason'])): ?>
              <span class="text-danger">Powód odwołania: <?= h(mb_substr($l['att_cancel_reason'],0,60)) ?></span>
              <?php else: ?>
              <?= $l['ind_notes'] ? h(mb_substr($l['ind_notes'],0,60)) : '' ?>
              <?php endif; ?>
            </td>
            <td class="text-end">
              <?php if ($l['status'] === 'planned' && !$att_cancelled): ?>
              <button type="button" class="btn btn-sm btn-outline-danger"
                      data-cancel-session="<?= (int)$l['id'] ?>"
                      data-lesson-label="<?= h($l['course_name'].' — '.(new DateTime($l['lesson_date']))->format('d.m.Y')) ?>">
                <i class="bi bi-x-circle me-1" aria-hidden="true"></i>Odwołaj
              </button>
              <?php elseif ($l['status'] === 'planned' && $att_cancelled): ?>
              <form method="post" class="d-inline" onsubmit="return confirm('Cofnąć odwołanie i potwierdzić udział?')">
                <input type="hidden" name="_token"     value="<?= h($vlab_token) ?>">
                <input type="hidden" name="_op"         value="uncancel_lesson">
                <input type="hidden" name="session_id"  value="<?= (int)$l['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-secondary">
                  <i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>Cofnij
                </button>
              </form>
              <?php else: ?>
              <span class="text-body-secondary">—</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <p class="text-body-secondary small mt-2">
    <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
    Zaplanowaną lekcję możesz odwołać, podając powód — odwołany udział nie jest liczony do ceny.
  </p>

  <!-- Modal: odwołanie udziału przez beneficjenta -->
  <div class="modal fade" id="cancelLessonModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
      <form method="post" class="modal-content">
        <input type="hidden" name="_token"      value="<?= h($vlab_token) ?>">
        <input type="hidden" name="_op"          value="cancel_lesson">
        <input type="hidden" name="session_id"   id="cl_session_id" value="">
        <div class="modal-header">
          <h2 class="modal-title h5"><i class="bi bi-x-circle text-danger me-2" aria-hidden="true"></i>Odwołanie lekcji</h2>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
        </div>
        <div class="modal-body">
          <p class="mb-2">Lekcja: <strong id="cl_lesson_label"></strong></p>
          <p class="text-body-secondary small mb-2">Odwołany udział nie zostanie policzony do ceny. Podaj powód odwołania.</p>
          <label class="form-label fw-semibold" for="cl_reason">Powód odwołania</label>
          <textarea class="form-control" id="cl_reason" name="reason" rows="3" required
                    placeholder="np. choroba, kolizja z innymi obowiązkami…"></textarea>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="btn btn-danger"><i class="bi bi-x-circle me-1" aria-hidden="true"></i>Odwołaj lekcję</button>
        </div>
      </form>
    </div>
  </div>

  <script>
  (function(){
    var modalEl = document.getElementById('cancelLessonModal');
    if (!modalEl) return;
    document.querySelectorAll('[data-cancel-session]').forEach(function(btn){
      btn.addEventListener('click', function(){
        document.getElementById('cl_session_id').value = btn.getAttribute('data-cancel-session');
        document.getElementById('cl_lesson_label').textContent = btn.getAttribute('data-lesson-label') || '';
        document.getElementById('cl_reason').value = '';
        new bootstrap.Modal(modalEl).show();
      });
    });
  })();
  </script>

<?php elseif ($tab === 'rozliczenia' && !$is_minor):
  $rv_client_id    = $student['client_id'];
  $rv_show_lessons = false;
  include __DIR__ . '/_rozliczenia_view.php';
?>

<?php elseif ($tab === 'vlab'): ?>

  <div id="vlab-root" data-token="<?= h($vlab_token) ?>">
    <h1 class="h5 fw-bold d-flex align-items-center gap-2 mb-1">
      <i class="bi bi-hdd-stack text-primary" aria-hidden="true"></i>VLab — Twoje maszyny
    </h1>
    <p class="text-body-secondary small mb-3">
      Twórz własne środowiska (kontenery Docker) do ćwiczeń. Dostęp przez terminal w przeglądarce lub po SSH.
    </p>
    <div id="vlab-content" aria-live="polite">
      <div class="text-body-secondary py-4 text-center">Ładowanie…</div>
    </div>
  </div>

  <script>
  (function(){
    const root = document.getElementById('vlab-root');
    const box  = document.getElementById('vlab-content');
    const token = root.dataset.token;
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

    async function api(action, params){
      const body = new URLSearchParams(Object.assign({action, _token: token}, params || {}));
      const r = await fetch('vlab_api.php', {method:'POST', headers:{'X-CSRF-Token':token}, body});
      return r.json();
    }
    const stMap = {running:['success','działa'], stopped:['secondary','zatrzymana'], error:['danger','błąd'], provisioning:['warning','tworzenie']};
    const lastCreds = {}; // pełne dane logowania pokazywane JEDEN raz po utworzeniu: {id: creds}

    function render(d){
      if (d.disabled){
        box.innerHTML = '<div class="alert alert-warning d-flex align-items-start gap-2" role="alert">'
          + '<i class="bi bi-pause-circle-fill mt-1" aria-hidden="true"></i>'
          + '<span style="white-space:pre-wrap">'+esc(d.notice || 'Moduł VLab jest chwilowo niedostępny.')+'</span></div>';
        return;
      }
      if (!d.enabled){
        box.innerHTML = '<div class="alert alert-warning d-flex align-items-center gap-2" role="alert">'
          + '<i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>'
          + '<span>Moduł VLab nie został jeszcze skonfigurowany przez administratora.</span></div>';
        return;
      }
      let html = '<h2 class="h6 fw-bold d-flex align-items-center mb-2"><i class="bi bi-pc-display me-2" aria-hidden="true"></i>'
        + 'Moje maszyny <span class="badge text-bg-secondary ms-2">'+d.count+' / '+d.max+'</span></h2>';

      if (!d.machines.length){
        html += '<div class="border border-secondary-subtle rounded p-4 text-center text-body-secondary mb-4">'
          + 'Nie masz jeszcze żadnej maszyny. Utwórz ją z szablonu poniżej.</div>';
      } else {
        for (const m of d.machines){
          const [col,lbl] = stMap[m.status] || ['secondary', m.status];
          html += '<div class="card mb-3"><div class="card-body">'
            + '<div class="d-flex align-items-center gap-2 mb-2">'
            + '<span class="fw-semibold">'+esc(m.label)+'</span>'
            + '<span class="badge text-bg-'+col+'">'+lbl+'</span>'
            + (m.force_pw ? '<span class="badge text-bg-warning"><i class="bi bi-key-fill me-1" aria-hidden="true"></i>zmień hasło przy logowaniu</span>' : '')
            + '</div>';
          if (m.status === 'error' && m.error){
            html += '<p class="text-danger small mb-2">'+esc(m.error)+'</p>';
          }
          // Efektywne dane SSH: konto hosta (preferowane) lub fallback na bezpośredni port kontenera.
          const sshUser = m.host_user || m.ssh_user || '';
          const sshPort = m.host_user ? m.host_port : (m.ssh_port || 0);
          const sshOk   = m.status === 'running' && m.ssh_host && sshUser && sshPort;
          if (sshOk){
            const c = lastCreds[m.id]; // pełne dane logowania, tylko bezpośrednio po utworzeniu
            html += '<div class="bg-body-tertiary border rounded p-2 mb-2 small font-monospace">'
              + '<div class="fw-semibold mb-1" style="font-family:inherit"><i class="bi bi-box-arrow-in-right me-1" aria-hidden="true"></i>Dane logowania (Docker)</div>'
              + '<div><span class="text-body-secondary">SSH:</span> ssh '+esc(sshUser)+'@'+esc(m.ssh_host)+' -p '+sshPort+'</div>';
            if (c){
              html += '<div class="d-flex align-items-center gap-2 mt-1"><span><span class="text-body-secondary">hasło SSH (pokazywane tylko raz):</span> <span class="fw-bold">'+esc(c.host_password)+'</span></span>'
                + '<button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1" data-copy="'+esc(c.host_password)+'" aria-label="Kopiuj hasło SSH"><i class="bi bi-clipboard" aria-hidden="true"></i></button></div>';
              if (c.ttyd_user){
                html += '<div class="mt-1"><span class="text-body-secondary">Login terminala (przeglądarka):</span> '+esc(c.ttyd_user)+' / <span class="fw-bold">'+esc(c.ttyd_password)+'</span></div>';
              }
              if (c.force_change){
                html += '<div class="mt-1 text-warning" style="font-family:inherit"><i class="bi bi-key-fill me-1" aria-hidden="true"></i>Przy pierwszym logowaniu SSH system poprosi o ustawienie własnego hasła.</div>';
              }
            } else if (m.host_user){
              html += '<div class="mt-1 text-body-secondary" style="font-family:inherit"><i class="bi bi-envelope me-1" aria-hidden="true"></i>Dane logowania wysłaliśmy e-mailem przy tworzeniu maszyny.</div>';
            } else {
              html += '<div class="mt-1 text-body-secondary" style="font-family:inherit"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>Dane logowania zgodne z obrazem maszyny (hasło lub klucz SSH).</div>';
            }
            html += '</div>';
          }
          html += '<div class="d-flex flex-wrap gap-2">';
          if (m.ttyd_url && m.status === 'running'){
            html += '<a class="btn btn-primary btn-sm" href="'+esc(m.ttyd_url)+'" target="_blank" rel="noopener"><i class="bi bi-terminal me-1" aria-hidden="true"></i>Otwórz terminal</a>';
          }
          if (sshOk){
            html += '<a class="btn btn-outline-primary btn-sm" href="ssh://'+esc(sshUser)+'@'+esc(m.ssh_host)+':'+sshPort+'" title="Otwiera klienta SSH zainstalowanego w systemie"><i class="bi bi-hdd-network me-1" aria-hidden="true"></i>Połącz po SSH</a>';
          }
          if (m.status === 'running'){
            html += '<button type="button" class="btn btn-outline-secondary btn-sm" data-act="stop" data-id="'+m.id+'"><i class="bi bi-stop-circle me-1" aria-hidden="true"></i>Zatrzymaj</button>';
            html += '<button type="button" class="btn btn-outline-secondary btn-sm" data-act="restart" data-id="'+m.id+'"><i class="bi bi-arrow-clockwise me-1" aria-hidden="true"></i>Restart</button>';
          } else if (m.status === 'stopped'){
            html += '<button type="button" class="btn btn-outline-success btn-sm" data-act="start" data-id="'+m.id+'"><i class="bi bi-play-circle me-1" aria-hidden="true"></i>Uruchom</button>';
          }
          html += '<button type="button" class="btn btn-outline-danger btn-sm" data-act="remove" data-id="'+m.id+'"><i class="bi bi-trash me-1" aria-hidden="true"></i>Usuń</button>';
          html += '</div></div></div>';
        }
      }

      html += '<h2 class="h6 fw-bold d-flex align-items-center mt-4 mb-2"><i class="bi bi-collection me-2" aria-hidden="true"></i>Utwórz nową maszynę</h2>';
      const canCreate = d.count < d.max;
      if (!canCreate){
        html += '<p class="text-body-secondary small">Osiągnięto limit maszyn ('+d.max+'). Usuń istniejącą, aby utworzyć nową.</p>';
      }
      if (!d.templates.length){
        html += '<div class="border border-secondary-subtle rounded p-4 text-center text-body-secondary">Brak dostępnych szablonów.</div>';
      } else {
        html += '<div class="row g-3">';
        for (const t of d.templates){
          html += '<div class="col-12 col-md-6 col-lg-4"><div class="card h-100"><div class="card-body d-flex flex-column">'
            + '<h3 class="h6 mb-1">'+esc(t.name)+'</h3>'
            + '<p class="text-body-secondary small flex-grow-1">'+esc(t.description||'')+'</p>'
            + '<button type="button" class="btn btn-primary btn-sm" data-create="'+t.id+'" '+(canCreate?'':'disabled')+'>'
            + '<i class="bi bi-plus-circle me-1" aria-hidden="true"></i>Utwórz</button>'
            + '</div></div></div>';
        }
        html += '</div>';
      }
      box.innerHTML = html;
    }

    async function reload(){ const d = await api('list'); if (d.ok) render(d); }

    box.addEventListener('click', async (e)=>{
      const copyBtn = e.target.closest('[data-copy]');
      if (copyBtn){ navigator.clipboard?.writeText(copyBtn.dataset.copy); copyBtn.innerHTML='<i class="bi bi-check2" aria-hidden="true"></i>'; return; }

      const createBtn = e.target.closest('[data-create]');
      if (createBtn){
        const label = prompt('Nazwa maszyny (litery, cyfry, myślniki):', 'lab');
        if (label === null) return;
        createBtn.disabled = true; createBtn.innerHTML = '<i class="bi bi-hourglass-split me-1" aria-hidden="true"></i>Tworzę…';
        const r = await api('create', {template_id: createBtn.dataset.create, label});
        if (r.id && r.creds) lastCreds[r.id] = r.creds; // pełne dane logowania — pokaż raz
        if (!r.ok) alert(r.msg || 'Błąd.');
        if (r.data) render(r.data); else reload();
        return;
      }

      const actBtn = e.target.closest('[data-act]');
      if (actBtn){
        const act = actBtn.dataset.act;
        if (act === 'remove' && !confirm('Usunąć maszynę? Tej operacji nie można cofnąć.')) return;
        actBtn.disabled = true;
        const r = await api(act, {id: actBtn.dataset.id});
        if (!r.ok) alert(r.msg || 'Błąd.');
        if (r.data) render(r.data); else reload();
      }
    });

    reload();
  })();
  </script>

<?php elseif ($tab === 'wiadomosci'):
  // Oznacz wiadomości od prowadzącego jako przeczytane przy wejściu na zakładkę
  ti_msg_mark_read_for_student((int)$student['id']);
  $messages = ti_msg_list_for_student((int)$student['id']);
?>

  <h1 class="h5 fw-bold d-flex align-items-center gap-2 mb-1">
    <i class="bi bi-envelope text-primary" aria-hidden="true"></i>Wiadomości
  </h1>
  <p class="text-body-secondary small mb-3">Wiadomości od prowadzącego. Możesz odpowiedzieć — odpowiedź trafi do prowadzącego.</p>

  <?php if (($_GET['sent'] ?? '') === '1'): ?>
  <div class="alert alert-success py-2 small" role="alert"><i class="bi bi-check-circle me-1" aria-hidden="true"></i>Wiadomość wysłana.</div>
  <?php endif; ?>

  <section class="card" aria-labelledby="msg-thread-h">
    <div class="card-body">
      <h2 id="msg-thread-h" class="h6 fw-bold mb-3"><i class="bi bi-chat-left-text me-2" aria-hidden="true"></i>Twój wątek</h2>
      <?php if (!$messages): ?>
        <div class="border border-secondary-subtle rounded p-4 text-center text-body-secondary">
          Brak wiadomości. Gdy prowadzący coś napisze, pojawi się tutaj.
        </div>
      <?php else: ?>
        <div class="d-flex flex-column gap-2 mb-3" style="max-height:60vh;overflow-y:auto">
          <?php foreach ($messages as $m):
            $mine = ($m['sender'] ?? '') === 'student';
            $ts   = $m['created_at'] ? date('d.m.Y H:i', strtotime($m['created_at'])) : '';
          ?>
          <div class="d-flex <?= $mine ? 'justify-content-end' : 'justify-content-start' ?>">
            <div class="p-2 px-3 rounded-3 <?= $mine ? 'bg-primary text-white' : 'bg-body-tertiary border' ?>" style="max-width:85%">
              <div class="small fw-semibold mb-1 <?= $mine ? 'text-white-50' : 'text-body-secondary' ?>">
                <?= $mine ? 'Ty' : h($m['sender_name'] !== '' ? $m['sender_name'] : 'Prowadzący') ?>
                <span class="ms-2 fw-normal"><?= h($ts) ?></span>
              </div>
              <?php if (!$mine && trim((string)$m['subject']) !== ''): ?>
              <div class="fw-bold mb-1"><?= h($m['subject']) ?></div>
              <?php endif; ?>
              <div style="white-space:pre-wrap;word-break:break-word"><?= nl2br(h($m['body'])) ?></div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <form method="post" class="mt-2">
        <input type="hidden" name="_token" value="<?= h($vlab_token) ?>">
        <input type="hidden" name="_op" value="msg_reply">
        <label class="form-label small fw-semibold" for="msg-body">Napisz wiadomość do prowadzącego</label>
        <textarea class="form-control mb-2" id="msg-body" name="body" rows="3" maxlength="4000" required placeholder="Treść wiadomości…"></textarea>
        <button class="btn btn-primary btn-sm"><i class="bi bi-send me-1" aria-hidden="true"></i>Wyślij</button>
      </form>
      <p class="text-body-secondary small mb-0 mt-3">
        <i class="bi bi-gear me-1" aria-hidden="true"></i>Powiadomienia o nowych wiadomościach ustawisz w zakładce
        <a href="?tab=ustawienia">Ustawienia</a>.
      </p>
    </div>
  </section>

<?php elseif ($tab === 'ustawienia'): ?>

  <h1 class="h5 fw-bold d-flex align-items-center gap-2 mb-1">
    <i class="bi bi-gear text-primary" aria-hidden="true"></i>Ustawienia i preferencje
  </h1>
  <p class="text-body-secondary small mb-3">Powiadomienia oraz synchronizacja lekcji z Twoim kalendarzem.</p>

  <?php if (($_GET['sms'] ?? '') === 'on'): ?>
  <div class="alert alert-success py-2 small" role="alert"><i class="bi bi-check-circle me-1" aria-hidden="true"></i>Włączono powiadomienia SMS o zajęciach.</div>
  <?php elseif (($_GET['sms'] ?? '') === 'off'): ?>
  <div class="alert alert-secondary py-2 small" role="alert">Wyłączono powiadomienia SMS o zajęciach.</div>
  <?php elseif (($_GET['prefs'] ?? '') === '1'): ?>
  <div class="alert alert-success py-2 small" role="alert"><i class="bi bi-check-circle me-1" aria-hidden="true"></i>Ustawienia powiadomień zapisane.</div>
  <?php elseif (($_GET['cal'] ?? '') === 'reset'): ?>
  <div class="alert alert-success py-2 small" role="alert"><i class="bi bi-check-circle me-1" aria-hidden="true"></i>Adres kalendarza został zmieniony. Poprzedni link przestał działać — zaktualizuj subskrypcję w swoim kalendarzu.</div>
  <?php elseif (($_GET['pwok'] ?? '') === '1'): ?>
  <div class="alert alert-success py-2 small" role="alert"><i class="bi bi-check-circle me-1" aria-hidden="true"></i>Hasło zostało zmienione.</div>
  <?php elseif (($_GET['phones'] ?? '') === '1'): ?>
  <div class="alert alert-success py-2 small" role="alert"><i class="bi bi-check-circle me-1" aria-hidden="true"></i>Numery do powiadomień SMS zapisane.</div>
  <?php endif; ?>
  <?php if (($_GET['pwerr'] ?? '') !== ''): ?>
  <div class="alert alert-danger py-2 small" role="alert"><i class="bi bi-exclamation-circle me-1" aria-hidden="true"></i><?= h((string)$_GET['pwerr']) ?></div>
  <?php endif; ?>

  <div class="row g-4">
    <!-- ── Powiadomienia o wiadomościach (e-mail / SMS) ──────────────────────── -->
    <div class="col-12 col-lg-6">
      <section class="card h-100" aria-labelledby="msg-prefs-h">
        <div class="card-body">
          <h2 id="msg-prefs-h" class="h6 fw-bold mb-2"><i class="bi bi-bell me-2 text-info" aria-hidden="true"></i>Powiadomienia o wiadomościach</h2>
          <p class="text-body-secondary small mb-3">Wybierz, jak chcesz być informowany o nowych wiadomościach od prowadzącego.</p>
          <form method="post" id="msgPrefsForm">
            <input type="hidden" name="_token" value="<?= h($vlab_token) ?>">
            <input type="hidden" name="_op" value="msg_prefs">
            <div class="form-check form-switch mb-2">
              <input class="form-check-input" type="checkbox" role="switch" id="prefEmail" name="email" value="1"
                     <?= $msg_pref_email ? 'checked' : '' ?> onchange="document.getElementById('msgPrefsForm').submit()">
              <label class="form-check-label" for="prefEmail"><i class="bi bi-envelope me-1" aria-hidden="true"></i>E-mail</label>
            </div>
            <?php if ($msg_pref_email && $msg_email_addr === ''): ?>
            <p class="text-warning small ms-4 mb-2"><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>Brak adresu e-mail w Twoich danych.</p>
            <?php elseif ($msg_email_addr !== ''): ?>
            <p class="text-body-secondary small ms-4 mb-2" style="margin-top:-4px"><?= h($msg_email_addr) ?></p>
            <?php endif; ?>
            <div class="form-check form-switch mb-1">
              <input class="form-check-input" type="checkbox" role="switch" id="prefSms" name="sms" value="1"
                     <?= $msg_pref_sms ? 'checked' : '' ?> <?= $sms_global_on ? '' : 'disabled' ?>
                     onchange="document.getElementById('msgPrefsForm').submit()">
              <label class="form-check-label" for="prefSms"><i class="bi bi-chat-dots me-1" aria-hidden="true"></i>SMS</label>
            </div>
            <?php if (!$sms_global_on): ?>
            <p class="text-body-secondary small ms-4 mb-0"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>Powiadomienia SMS są obecnie niedostępne.</p>
            <?php elseif ($sms_phone === ''): ?>
            <p class="text-warning small ms-4 mb-0"><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>Brak numeru telefonu w Twoich danych.</p>
            <?php else: ?>
            <p class="text-body-secondary small ms-4 mb-0" style="margin-top:-2px"><i class="bi bi-telephone me-1" aria-hidden="true"></i>Numer: <?= h(preg_replace('/.(?=.{2})/u', '•', $sms_phone)) ?></p>
            <?php endif; ?>
          </form>
        </div>
      </section>
    </div>

    <!-- ── Powiadomienia SMS o zajęciach ─────────────────────────────────────── -->
    <div class="col-12 col-lg-6">
      <section class="card h-100" aria-labelledby="sms-heading">
        <div class="card-body">
          <h2 id="sms-heading" class="h6 fw-bold mb-2"><i class="bi bi-chat-dots text-info me-2" aria-hidden="true"></i>Powiadomienia SMS o zajęciach</h2>
          <?php if (!$sms_global_on): ?>
          <p class="text-body-secondary small mb-0"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>Powiadomienia SMS są obecnie niedostępne.</p>
          <?php else: ?>
          <p class="text-body-secondary small mb-2">Otrzymasz krótki SMS, gdy prowadzący doda Ci nowe zajęcia.</p>
          <form method="post" id="smsPrefForm">
            <input type="hidden" name="_token" value="<?= h($vlab_token) ?>">
            <input type="hidden" name="_op"     value="toggle_sms_lessons">
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" role="switch" id="smsToggle" name="enabled" value="1"
                     <?= $sms_pref ? 'checked' : '' ?>
                     onchange="document.getElementById('smsPrefForm').submit()">
              <label class="form-check-label" for="smsToggle">Chcę dostawać SMS o nowych zajęciach</label>
            </div>
          </form>
            <?php if ($sms_phone === ''): ?>
          <p class="text-warning small mb-0 mt-2"><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>Brak numeru telefonu w Twoich danych — SMS nie dotrą, dopóki administrator go nie uzupełni.</p>
            <?php else: ?>
          <p class="text-body-secondary small mb-0 mt-2"><i class="bi bi-telephone me-1" aria-hidden="true"></i>Numer: <?= h(preg_replace('/.(?=.{2})/u', '•', $sms_phone)) ?></p>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      </section>
    </div>

    <!-- ── Zmiana hasła ──────────────────────────────────────────────────────── -->
    <div class="col-12 col-lg-6">
      <section class="card h-100" aria-labelledby="pw-heading">
        <div class="card-body">
          <h2 id="pw-heading" class="h6 fw-bold mb-2"><i class="bi bi-shield-lock me-2 text-info" aria-hidden="true"></i>Zmień hasło</h2>
          <p class="text-body-secondary small mb-3">Ustaw własne hasło do panelu kursanta.</p>
          <form method="post" autocomplete="off">
            <input type="hidden" name="_token" value="<?= h($vlab_token) ?>">
            <input type="hidden" name="_op" value="change_password">
            <div class="mb-2">
              <label class="form-label small" for="cps-cur">Aktualne hasło</label>
              <input type="password" class="form-control form-control-sm" id="cps-cur" name="current" required autocomplete="current-password">
            </div>
            <div class="mb-2">
              <label class="form-label small" for="cps-new">Nowe hasło</label>
              <input type="password" class="form-control form-control-sm" id="cps-new" name="new" required minlength="8" autocomplete="new-password" placeholder="min. 8 znaków">
            </div>
            <div class="mb-2">
              <label class="form-label small" for="cps-cnf">Powtórz nowe hasło</label>
              <input type="password" class="form-control form-control-sm" id="cps-cnf" name="confirm" required minlength="8" autocomplete="new-password">
            </div>
            <button class="btn btn-primary btn-sm"><i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Zapisz hasło</button>
          </form>
        </div>
      </section>
    </div>

    <!-- ── Dodatkowe numery do powiadomień SMS ───────────────────────────────── -->
    <div class="col-12 col-lg-6">
      <section class="card h-100" aria-labelledby="ph-heading">
        <div class="card-body">
          <h2 id="ph-heading" class="h6 fw-bold mb-2"><i class="bi bi-telephone-plus me-2 text-info" aria-hidden="true"></i>Dodatkowe numery do SMS</h2>
          <p class="text-body-secondary small mb-3">Powiadomienia SMS (o zajęciach i wiadomościach) wyślemy też na te numery — np. do rodzica lub opiekuna.</p>
          <?php if ($sms_phone !== ''): ?>
          <p class="text-body-secondary small mb-2"><i class="bi bi-telephone me-1" aria-hidden="true"></i>Numer główny: <?= h(preg_replace('/.(?=.{2})/u', '•', $sms_phone)) ?></p>
          <?php endif; ?>
          <form method="post">
            <input type="hidden" name="_token" value="<?= h($vlab_token) ?>">
            <input type="hidden" name="_op" value="notify_phones">
            <div class="mb-2">
              <label class="form-label small" for="ph2">Drugi numer</label>
              <input type="tel" class="form-control form-control-sm" id="ph2" name="phone2" maxlength="30" value="<?= h((string)($account['notify_phone2'] ?? '')) ?>" placeholder="np. 600 700 800">
            </div>
            <div class="mb-2">
              <label class="form-label small" for="ph3">Trzeci numer</label>
              <input type="tel" class="form-control form-control-sm" id="ph3" name="phone3" maxlength="30" value="<?= h((string)($account['notify_phone3'] ?? '')) ?>" placeholder="np. 600 700 900">
            </div>
            <button class="btn btn-primary btn-sm"><i class="bi bi-save me-1" aria-hidden="true"></i>Zapisz numery</button>
            <?php if (!$sms_global_on): ?>
            <p class="text-body-secondary small mb-0 mt-2"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>Bramka SMS jest obecnie niedostępna.</p>
            <?php endif; ?>
          </form>
        </div>
      </section>
    </div>

    <!-- ── Synchronizacja z kalendarzem (Google / Apple / Outlook) ──────────── -->
    <div class="col-12">
      <section class="card" aria-labelledby="cal-heading">
        <div class="card-body">
          <h2 id="cal-heading" class="h6 fw-bold mb-2"><i class="bi bi-calendar-plus text-info me-2" aria-hidden="true"></i>Synchronizacja z kalendarzem</h2>
          <p class="text-body-secondary small mb-3">Dodaj swoje lekcje do Kalendarza Google, Apple lub Outlook. Kalendarz odświeża się automatycznie, gdy prowadzący doda lub zmieni terminy.</p>

          <div class="d-flex flex-wrap gap-2 mb-3">
            <a href="<?= h($cal_gcal) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-primary"><i class="bi bi-google me-1" aria-hidden="true"></i>Dodaj do Google Calendar</a>
            <a href="<?= h($cal_webcal) ?>" class="btn btn-sm btn-info"><i class="bi bi-apple me-1" aria-hidden="true"></i>Subskrybuj (Apple / Outlook)</a>
            <a href="<?= h($cal_https) ?>" class="btn btn-sm btn-outline-secondary" download="lekcje.ics"><i class="bi bi-download me-1" aria-hidden="true"></i>Pobierz plik .ics</a>
          </div>

          <label class="form-label small fw-semibold" for="cal-url">Adres kanału (do ręcznego dodania „z adresu URL")</label>
          <div class="input-group input-group-sm mb-2">
            <input type="text" class="form-control" id="cal-url" value="<?= h($cal_https) ?>" readonly aria-label="Adres kanału iCal" onclick="this.select()">
            <button type="button" class="btn btn-outline-secondary" id="cal-copy"><i class="bi bi-clipboard me-1" aria-hidden="true"></i>Kopiuj</button>
          </div>

          <div class="d-flex flex-wrap align-items-center gap-2 justify-content-between">
            <p class="text-body-secondary mb-0" style="font-size:.78rem"><i class="bi bi-shield-lock me-1" aria-hidden="true"></i>Adres jest prywatny — nie udostępniaj go innym. Jeśli wyciekł, zresetuj go.</p>
            <form method="post" class="m-0" onsubmit="return confirm('Zresetować adres kalendarza? Dotychczasowa subskrypcja przestanie działać i trzeba ją dodać ponownie.')">
              <input type="hidden" name="_token" value="<?= h($vlab_token) ?>">
              <input type="hidden" name="_op"     value="reset_calendar_token">
              <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i>Resetuj adres</button>
            </form>
          </div>
        </div>
      </section>
    </div>
  </div>

  <script>
  (function(){
    var btn = document.getElementById('cal-copy');
    var inp = document.getElementById('cal-url');
    if (!btn || !inp) return;
    btn.addEventListener('click', function(){
      inp.select();
      var done = function(){
        var html = btn.innerHTML;
        btn.innerHTML = '<i class="bi bi-check-lg me-1" aria-hidden="true"></i>Skopiowano';
        setTimeout(function(){ btn.innerHTML = html; }, 1500);
      };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(inp.value).then(done, function(){ try { document.execCommand('copy'); done(); } catch(e){} });
      } else { try { document.execCommand('copy'); done(); } catch(e){} }
    });
  })();
  </script>

<?php elseif ($tab === 'online'): ?>

  <div id="online-root" data-token="<?= h($vlab_token) ?>">
    <h1 class="h5 fw-bold d-flex align-items-center gap-2 mb-1">
      <i class="bi bi-camera-video text-primary" aria-hidden="true"></i>Szkolenia online
    </h1>
    <p class="text-body-secondary small mb-3">
      Twoje konto szkoleniowe Microsoft&nbsp;365, dostęp do platformy e-learningowej oraz linki do nadchodzących szkoleń (Zoom / MS&nbsp;Teams).
    </p>
    <div id="online-content" aria-live="polite">
      <div class="text-body-secondary py-4 text-center">Ładowanie…</div>
    </div>
  </div>

  <script>
  (function(){
    const root = document.getElementById('online-root');
    const box  = document.getElementById('online-content');
    const token = root.dataset.token;
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

    async function api(action, params){
      const body = new URLSearchParams(Object.assign({action, _token: token}, params || {}));
      const r = await fetch('ti_online_api.php', {method:'POST', headers:{'X-CSRF-Token':token}, body});
      return r.json();
    }

    function fmtDate(s){
      if (!s) return '';
      const d = new Date(s.replace(' ', 'T'));
      if (isNaN(d)) return esc(s);
      return d.toLocaleString('pl-PL', {day:'2-digit', month:'short', hour:'2-digit', minute:'2-digit'});
    }
    const platMap = {zoom:['primary','camera-video','Zoom'], teams:['info','microsoft-teams','MS Teams'], other:['secondary','link-45deg','Link']};

    let lastCreds = null; // jednorazowe dane konta MS po utworzeniu

    function cardMS(d){
      let inner;
      if (!d.ms_enabled){
        inner = '<p class="text-body-secondary small mb-0">Moduł kont Microsoft nie został skonfigurowany przez administratora.</p>';
      } else if (d.ms_active){
        inner = '<p class="small mb-2">Twój login (działa też w Moodle):<br><span class="font-monospace fw-semibold">'+esc(d.ms_upn)+'</span></p>';
        if (lastCreds && lastCreds.upn === d.ms_upn){
          inner += '<div class="alert alert-warning small py-2"><i class="bi bi-key-fill me-1" aria-hidden="true"></i>'
            + 'Hasło tymczasowe (zapisz teraz, zmienisz przy pierwszym logowaniu): <span class="font-monospace fw-bold">'+esc(lastCreds.password)+'</span></div>';
        }
        inner += '<button type="button" class="btn btn-outline-danger btn-sm" data-act="ms_delete"><i class="bi bi-trash me-1" aria-hidden="true"></i>Usuń konto</button>';
      } else if (d.ms_external_upn){
        inner = '<div class="alert alert-info small py-2 mb-0">'
          + '<i class="bi bi-info-circle me-1" aria-hidden="true"></i>'
          + 'Konto Microsoft o loginie <span class="font-monospace fw-semibold">'+esc(d.ms_external_upn)+'</span> '
          + 'już istnieje (utworzone poza systemem). Zaloguj się nim — <strong>nie tworzymy nowego</strong>, aby go nie nadpisać. '
          + 'Jeśli to nie Twoje konto, skontaktuj się z administratorem.</div>';
      } else {
        inner = '<p class="text-body-secondary small mb-2">Nie masz jeszcze konta szkoleniowego. Utwórz je, aby korzystać z usług Microsoft i platformy e-learningowej.</p>'
          + '<button type="button" class="btn btn-primary btn-sm" data-act="ms_create"><i class="bi bi-microsoft me-1" aria-hidden="true"></i>Utwórz konto</button>';
      }
      return '<div class="col-12 col-lg-6"><div class="card h-100"><div class="card-body">'
        + '<h2 class="h6 fw-bold d-flex align-items-center mb-2"><i class="bi bi-microsoft me-2 text-primary" aria-hidden="true"></i>Konto Microsoft 365</h2>'
        + inner + '</div></div></div>';
    }

    function cardMoodle(d){
      let inner;
      if (!d.moodle_enabled){
        inner = '<p class="text-body-secondary small mb-0">Integracja z platformą e-learningową nie została skonfigurowana.</p>';
      } else if (d.moodle_active){
        inner = '<p class="small mb-2">Login: <span class="font-monospace fw-semibold">'+esc(d.moodle_login)+'</span><br>'
          + '<span class="text-body-secondary">Hasło: domyślnie takie samo jak do konta Microsoft — możesz ustawić własne poniżej.</span></p>'
          + (d.moodle_url ? '<a class="btn btn-success btn-sm mb-2" href="'+esc(d.moodle_url)+'" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>Otwórz platformę</a>' : '')
          + '<details class="mt-1">'
          + '<summary class="small text-primary" style="cursor:pointer"><i class="bi bi-key me-1" aria-hidden="true"></i>Ustaw własne hasło do platformy</summary>'
          + '<div class="mt-2" style="max-width:340px">'
          + '<label class="form-label small mb-1" for="moodle-pwd">Nowe hasło</label>'
          + '<input type="password" class="form-control form-control-sm mb-2" id="moodle-pwd" autocomplete="new-password" minlength="8" placeholder="min. 8 znaków, A-z, cyfra, znak specjalny">'
          + '<button type="button" class="btn btn-primary btn-sm" data-act="moodle_password"><i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Zapisz hasło</button>'
          + '<p class="form-text small mb-0">Hasło musi mieć min. 8 znaków oraz zawierać małą i wielką literę, cyfrę i znak specjalny.</p>'
          + '</div></details>';
      } else if (!d.ms_active){
        inner = '<p class="text-body-secondary small mb-0"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>Najpierw utwórz konto Microsoft — jego login posłuży jako login do platformy.</p>';
      } else {
        inner = '<p class="text-body-secondary small mb-2">Utwórz konto na platformie e-learningowej (login = Twój adres Microsoft).</p>'
          + '<button type="button" class="btn btn-primary btn-sm" data-act="moodle_create"><i class="bi bi-mortarboard me-1" aria-hidden="true"></i>Utwórz konto Moodle</button>';
      }
      return '<div class="col-12 col-lg-6"><div class="card h-100"><div class="card-body">'
        + '<h2 class="h6 fw-bold d-flex align-items-center mb-2"><i class="bi bi-mortarboard me-2 text-primary" aria-hidden="true"></i>Platforma e-learning</h2>'
        + inner + '</div></div></div>';
    }

    function meetingRow(m){
      const [col,icon,lbl] = platMap[m.platform] || platMap.other;
      return '<div class="list-group-item d-flex align-items-center gap-3 flex-wrap">'
        + '<span class="badge text-bg-'+col+'"><i class="bi bi-'+icon+' me-1" aria-hidden="true"></i>'+lbl+'</span>'
        + '<span class="flex-grow-1"><span class="fw-semibold">'+esc(m.title)+'</span>'
        + (m.starts_at ? ' <span class="text-body-secondary small d-block d-sm-inline">'+fmtDate(m.starts_at)+'</span>' : '')+'</span>'
        + '<a class="btn btn-outline-primary btn-sm" href="'+esc(m.join_url)+'" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>Dołącz</a>'
        + '</div>';
    }

    function cardMeetings(d){
      let body;
      if (!d.meetings || !d.meetings.length){
        body = '<div class="border border-secondary-subtle rounded p-4 text-center text-body-secondary">Brak zaplanowanych szkoleń online.</div>';
      } else {
        // Grupuj linki pod konkretną grupą (course_name); wspólne/tenantowe na końcu.
        const groups = new Map();
        for (const m of d.meetings){
          const key = m.course_name || ' all';
          if (!groups.has(key)) groups.set(key, []);
          groups.get(key).push(m);
        }
        const keys = [...groups.keys()].sort((a,b)=>{
          if (a===' all') return 1; if (b===' all') return -1;
          return a.localeCompare(b,'pl');
        });
        body = '';
        for (const key of keys){
          const isAll = key===' all';
          const label = isAll ? 'Dla wszystkich grup' : key;
          const gicon = isAll ? 'broadcast' : 'people-fill';
          body += '<div class="mb-3">'
            + '<div class="fw-semibold small text-uppercase text-body-secondary mb-2">'
            + '<i class="bi bi-'+gicon+' me-1" aria-hidden="true"></i>'+esc(label)+'</div>'
            + '<div class="list-group">' + groups.get(key).map(meetingRow).join('') + '</div></div>';
        }
      }
      return '<div class="col-12"><div class="card"><div class="card-body">'
        + '<h2 class="h6 fw-bold d-flex align-items-center mb-3"><i class="bi bi-calendar-event me-2 text-primary" aria-hidden="true"></i>Nadchodzące szkolenia</h2>'
        + body + '</div></div></div>';
    }

    function render(d){
      box.innerHTML = '<div class="row g-3">' + cardMS(d) + cardMoodle(d) + cardMeetings(d) + '</div>';
    }

    async function reload(){ const d = await api('list'); if (d.ok) render(d); }

    box.addEventListener('click', async (e)=>{
      const btn = e.target.closest('[data-act]');
      if (!btn) return;
      const act = btn.dataset.act;
      if (act === 'ms_delete' && !confirm('Usunąć konto Microsoft? Stracisz dostęp do powiązanych usług.')) return;
      let params = {};
      if (act === 'moodle_password'){
        const inp = box.querySelector('#moodle-pwd');
        const pwd = inp ? inp.value : '';
        if (!pwd){ if (inp) inp.focus(); return; }
        params = {password: pwd};
      }
      btn.disabled = true;
      const orig = btn.innerHTML;
      btn.innerHTML = '<i class="bi bi-hourglass-split me-1" aria-hidden="true"></i>Pracuję…';
      const r = await api(act, params);
      if (act === 'ms_create' && r.ok && r.password) lastCreds = {upn: r.upn, password: r.password};
      if (act === 'moodle_password' && r.ok) alert(r.msg || 'Hasło zmienione.');
      if (!r.ok) alert(r.msg || 'Błąd.');
      if (r.data) render(r.data); else { btn.disabled = false; btn.innerHTML = orig; reload(); }
    });

    reload();
  })();
  </script>

<?php endif; ?>

</main>

<?php include __DIR__ . '/_layout_foot.php'; ?>
