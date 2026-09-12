<?php
/**
 * karty30/ti/dydaktyk/zglos_problem.php — Zgłoś problem (panel dydaktyka).
 *
 * Prosty formularz dla KAŻDEGO dydaktyka (nie tylko kierownika) — zgłoszenie
 * trafia do istniejącego modułu Helpdesk (helpdesk_tickets, source='dydaktyk'),
 * ten sam panel operatora (helpdesk/admin.php) je obsługuje.
 *
 * Zapis idzie najpierw przez API (api_helpdesk.php, sesja panelu) —
 * przygotowanie pod przyszłe wydzielenie panelu jako osobnej aplikacji.
 * Fallback przy niedostępności API: bezpośrednio hd_ticket_quick_create()
 * (ta sama baza) — patrz includes/helpdesk.php.
 */
require_once __DIR__ . '/auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/helpdesk.php';

$me      = dyd_require();
$uid     = (int)$me['user_id'];
$me_name = (string)($me['name'] ?? '');
karty30_migrate();
helpdesk_migrate();

$error = '';
$sent_number = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    dyd_token_check();
    $title       = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $category    = (string)($_POST['category'] ?? 'it_inne');
    $priority    = (string)($_POST['priority'] ?? 'normalny');

    if ($title === '' || $description === '') {
        $error = 'Podaj temat i opis problemu.';
    } else {
        $requester = ['id' => $uid, 'name' => $me_name, 'email' => (string)($me['email'] ?? '')];
        $api = hd_dyd_api_call('create', [
            'title' => $title, 'description' => $description, 'category' => $category, 'priority' => $priority,
        ], 'POST');
        $ticket_id = $api['data']['ticket_id'] ?? null;
        if ($ticket_id === null) {
            $ticket_id = hd_ticket_quick_create($requester, $title, $description, $category, $priority, 'dydaktyk');
        }
        $sent = db_one("SELECT number FROM helpdesk_tickets WHERE id=?", [(int)$ticket_id]);
        $sent_number = $sent['number'] ?? null;
    }
}

$KP_TITLE  = 'Zgłoś problem — Panel dydaktyka';
$KP_TOPBAR = ['brand' => 'Panel dydaktyka', 'icon' => 'easel2', 'user' => $me_name, 'logout' => 'logout.php'];
$KP_BODY_CLASS = 'dyd-usos ti-skin';
include dirname(__DIR__) . '/kursant/_layout_head.php';
$_skin_css = __DIR__ . '/../assets/ti_skin.css';
?>
<link rel="stylesheet" href="../assets/ti_skin.css?v=<?= is_file($_skin_css) ? (int)filemtime($_skin_css) : 1 ?>">

<main id="main" class="dyd-wrap">
<div class="container-fluid py-3" style="max-width:640px">

<div class="d-flex align-items-center mb-3 gap-2">
  <h1 class="h4 mb-0 fw-bold"><i class="bi bi-life-preserver text-primary me-2" aria-hidden="true"></i>Zgłoś problem</h1>
  <a href="index.php?tab=pulpit" class="btn btn-sm btn-outline-secondary ms-auto">
    <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Pulpit
  </a>
</div>

<?php if ($sent_number): ?>
<div class="alert alert-success d-flex align-items-center gap-2" role="status">
  <i class="bi bi-check-circle-fill flex-shrink-0" aria-hidden="true"></i>
  <span>Zgłoszenie <strong><?= h($sent_number) ?></strong> zostało zarejestrowane. Potwierdzenie wysłaliśmy na Twój adres e-mail.</span>
</div>
<a href="zglos_problem.php" class="btn btn-outline-secondary btn-sm">
  <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Zgłoś kolejny problem
</a>

<?php else: ?>

<?php if ($error): ?>
<div class="alert alert-danger py-2 small mb-3"><?= h($error) ?></div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
  <div class="card-body">
    <form method="post">
      <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
      <div class="mb-3">
        <label class="form-label fw-semibold" for="hd-title">Temat <span class="text-danger">*</span></label>
        <input type="text" class="form-control" id="hd-title" name="title" required maxlength="200"
               placeholder="np. Nie działa link do spotkania Zoom" autofocus
               value="<?= h($_POST['title'] ?? '') ?>">
      </div>
      <div class="row g-3 mb-3">
        <div class="col-sm-6">
          <label class="form-label fw-semibold" for="hd-cat">Kategoria</label>
          <select class="form-select" id="hd-cat" name="category">
            <?php foreach (HD_CATEGORIES as $k => $v): if (str_starts_with($k, 'zgl_') || $k === 'bug_report') continue; ?>
            <option value="<?= h($k) ?>" <?= ($_POST['category'] ?? 'it_inne') === $k ? 'selected' : '' ?>><?= h($v) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-sm-6">
          <label class="form-label fw-semibold" for="hd-pri">Pilność</label>
          <select class="form-select" id="hd-pri" name="priority">
            <?php foreach (HD_PRIORITIES as $k => $p): ?>
            <option value="<?= h($k) ?>" <?= ($_POST['priority'] ?? 'normalny') === $k ? 'selected' : '' ?>><?= h($p['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="mb-3">
        <label class="form-label fw-semibold" for="hd-desc">Opis problemu <span class="text-danger">*</span></label>
        <textarea class="form-control" id="hd-desc" name="description" rows="5" required
                  placeholder="Co dokładnie się dzieje, od kiedy, na jakim urządzeniu…"><?= h($_POST['description'] ?? '') ?></textarea>
      </div>
      <button type="submit" class="btn btn-primary">
        <i class="bi bi-send-fill me-1" aria-hidden="true"></i>Wyślij zgłoszenie
      </button>
    </form>
  </div>
</div>
<?php endif; ?>

</div>
</main>

<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
