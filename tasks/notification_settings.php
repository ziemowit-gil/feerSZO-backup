<?php
/**
 * Ustawienia powiadomień email — moduł Zadań
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/tasks.php';
require_once dirname(__DIR__) . '/includes/task_notify.php';

require_login();
$uid  = (int)(current_user()['id'] ?? 0);
$user = current_user();

$saved   = false;
$error   = '';

// ── Zapis ─────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['_csrf'] ?? '')) {
        $error = 'Nieprawidłowy token CSRF.';
    } else {
        try {
            task_notify_save_pref($uid, [
                'notify_assigned'  => isset($_POST['notify_assigned'])  ? 1 : 0,
                'notify_mentioned' => isset($_POST['notify_mentioned']) ? 1 : 0,
                'notify_comment'   => isset($_POST['notify_comment'])   ? 1 : 0,
                'notify_due_1day'  => isset($_POST['notify_due_1day'])  ? 1 : 0,
                'notify_due_today' => isset($_POST['notify_due_today']) ? 1 : 0,
            ]);
            $saved = true;
        } catch (\Throwable $e) {
            $error = 'Błąd zapisu: ' . $e->getMessage();
        }
    }
}

$pref = task_notify_get_pref($uid);
$csrf = csrf_token();

$page_title = 'Powiadomienia email — Zadania';
require_once dirname(__DIR__) . '/includes/header.php';
?>

<div class="container-fluid py-4" style="max-width:680px">

  <!-- Breadcrumb -->
  <nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb">
      <li class="breadcrumb-item"><a href="<?= APP_URL ?>/tasks/index.php">Zadania</a></li>
      <li class="breadcrumb-item active">Powiadomienia email</li>
    </ol>
  </nav>

  <div class="d-flex align-items-center gap-3 mb-4">
    <div class="rounded-3 d-flex align-items-center justify-content-center flex-shrink-0"
         style="width:48px;height:48px;background:#eff6ff">
      <i class="bi bi-bell-fill text-primary fs-5"></i>
    </div>
    <div>
      <h4 class="mb-0">Powiadomienia email</h4>
      <p class="text-muted mb-0 small">Powiadomienia dla konta: <strong><?= h($user['email'] ?? '') ?></strong></p>
    </div>
  </div>

  <?php if ($saved): ?>
  <div class="alert alert-success alert-dismissible fade show" role="alert">
    <i class="bi bi-check-circle me-2"></i>Ustawienia zapisano.
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
  <?php endif; ?>

  <?php if ($error): ?>
  <div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i><?= h($error) ?></div>
  <?php endif; ?>

  <?php if (!($user['email'] ?? '')): ?>
  <div class="alert alert-warning">
    <i class="bi bi-envelope-exclamation me-2"></i>
    Twoje konto nie ma przypisanego adresu email — powiadomienia nie będą wysyłane.
    Skontaktuj się z administratorem.
  </div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">

    <div class="card shadow-sm">
      <div class="card-header bg-white py-3">
        <h6 class="mb-0 fw-semibold"><i class="bi bi-sliders me-2"></i>Kiedy chcesz otrzymywać powiadomienia?</h6>
      </div>
      <div class="card-body p-0">

        <?php
        $options = [
            [
                'key'   => 'notify_assigned',
                'icon'  => 'bi-person-plus-fill text-primary',
                'title' => 'Przypisanie do zadania',
                'desc'  => 'Gdy ktoś przypisze Cię do zadania.',
            ],
            [
                'key'   => 'notify_mentioned',
                'icon'  => 'bi-at text-info',
                'title' => 'Wzmianka w komentarzu (@)',
                'desc'  => 'Gdy ktoś wspomni Cię w komentarzu używając @NazwaUżytkownika.',
            ],
            [
                'key'   => 'notify_comment',
                'icon'  => 'bi-chat-left-text-fill text-secondary',
                'title' => 'Nowy komentarz',
                'desc'  => 'Gdy ktoś doda komentarz do zadania, do którego jesteś przypisany/a.',
            ],
            [
                'key'   => 'notify_due_1day',
                'icon'  => 'bi-calendar-event-fill text-warning',
                'title' => 'Termin jutro',
                'desc'  => 'Przypomnienie dzień przed terminem zadania.',
            ],
            [
                'key'   => 'notify_due_today',
                'icon'  => 'bi-alarm-fill text-danger',
                'title' => 'Termin dzisiaj',
                'desc'  => 'Przypomnienie w dniu terminu zadania.',
            ],
        ];
        foreach ($options as $i => $opt):
            $checked  = (bool)($pref[$opt['key']] ?? 0);
            $last     = $i === count($options) - 1;
        ?>
        <div class="d-flex align-items-center gap-3 px-4 py-3
                    <?= $last ? '' : 'border-bottom' ?>">
          <div class="flex-shrink-0" style="width:36px;height:36px;background:#f1f5f9;
               border-radius:8px;display:flex;align-items:center;justify-content:center">
            <i class="bi <?= $opt['icon'] ?>"></i>
          </div>
          <div class="flex-grow-1">
            <div class="fw-semibold" style="font-size:.92rem"><?= $opt['title'] ?></div>
            <div class="text-muted" style="font-size:.8rem"><?= $opt['desc'] ?></div>
          </div>
          <div class="flex-shrink-0">
            <div class="form-check form-switch mb-0">
              <input class="form-check-input" type="checkbox"
                     id="<?= $opt['key'] ?>" name="<?= $opt['key'] ?>"
                     role="switch" style="width:2.4em;height:1.3em"
                     <?= $checked ? 'checked' : '' ?>>
              <label class="form-check-label visually-hidden" for="<?= $opt['key'] ?>">
                <?= $opt['title'] ?>
              </label>
            </div>
          </div>
        </div>
        <?php endforeach; ?>

      </div>
      <div class="card-footer bg-white d-flex justify-content-between align-items-center py-3">
        <a href="<?= APP_URL ?>/tasks/index.php" class="btn btn-outline-secondary btn-sm">
          <i class="bi bi-arrow-left me-1"></i>Wróć do tablicy
        </a>
        <button type="submit" class="btn btn-primary btn-sm px-4">
          <i class="bi bi-floppy me-1"></i>Zapisz ustawienia
        </button>
      </div>
    </div>

  </form>

  <!-- Informacja o wysyłce -->
  <div class="mt-4 p-3 rounded-3" style="background:#f8fafc;border:1px solid #e2e8f0">
    <div class="d-flex gap-3 align-items-start">
      <i class="bi bi-info-circle text-primary mt-1 flex-shrink-0"></i>
      <div class="small text-muted">
        <strong>Jak działają powiadomienia?</strong><br>
        Emaile są wysyłane natychmiast po zdarzeniu (przypisanie, komentarz, wzmianka)
        lub raz dziennie (przypomnienia o terminie — przez skrypt cron).
        Każde powiadomienie wysyłane jest najwyżej raz dziennie dla tego samego zdarzenia.
      </div>
    </div>
  </div>

</div>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
