<?php
/**
 * tasks/nozbe_settings.php
 * Osobiste połączenie z Nozbe (per użytkownik) — CAŁKOWICIE OSOBNE od
 * globalnej integracji w admin/nozbe_settings.php / crm/settings/nozbe.php.
 * Zob. includes/task_nozbe.php.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/tasks.php';
require_once dirname(__DIR__) . '/includes/task_nozbe.php';

require_login();
require_module_enabled('tasks_enabled', 'Moduł zadań');

$uid = (int)(current_user()['id'] ?? 0);
$SELF = APP_URL . '/tasks/nozbe_settings.php';
$test_result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['_action'] ?? '';

    if ($action === 'save') {
        $token = trim($_POST['api_token'] ?? '');
        $proj  = trim($_POST['project_id'] ?? '') ?: 'task_me';
        // Puste pole tokenu przy już zapisanym = "zostaw jak było"
        if ($token === '' && task_nozbe_configured($uid)) {
            db()->prepare("UPDATE task_nozbe_tokens SET project_id=?, updated_at=datetime('now','localtime') WHERE user_id=?")
                ->execute([$proj, $uid]);
            flash_set('success', 'Ustawienia zaktualizowane.');
        } elseif ($token === '') {
            flash_set('error', 'Wklej token API, aby połączyć konto.');
        } else {
            task_nozbe_save_token($uid, $token, $proj);
            flash_set('success', 'Konto Nozbe połączone. Zadania zaczną się synchronizować automatycznie.');
        }
        header('Location: ' . $SELF); exit;
    }

    if ($action === 'disconnect') {
        task_nozbe_disconnect($uid);
        flash_set('success', 'Odłączono konto Nozbe.');
        header('Location: ' . $SELF); exit;
    }

    if ($action === 'test') {
        $test_result = task_nozbe_test($uid);
    }
}

$cfg = task_nozbe_get_token($uid);

$PAGE_TITLE       = 'Nozbe — moje połączenie';
$TASKS_BREADCRUMB = 'Nozbe';
require_once __DIR__ . '/includes/header_tasks.php';
?>

<?= flash_html() ?>

<div class="tw-max-w-[640px] tw-mx-auto">

  <h1 class="tw-text-lg tw-font-bold tw-mb-1 tw-flex tw-items-center tw-gap-2">
    <i class="bi bi-check2-square tw-text-blue-600" aria-hidden="true"></i>Moje połączenie z Nozbe
  </h1>
  <p class="tw-text-slate-500 tw-text-sm tw-mb-4">
    Zadania, do których jesteś przypisany/a w module Zadania, będą automatycznie
    trafiać do Twojego <strong>osobistego</strong> konta Nozbe — nikt inny ich tam
    nie zobaczy. Kierunek jest jednostronny (SZO → Nozbe): zamknięcie zadania w
    Nozbe nie cofa się do SZO, ale zakończenie go w SZO oznacza je jako zakończone
    też w Twoim Nozbe.
  </p>

  <?php if ($test_result): ?>
  <div class="alert <?= $test_result['ok'] ? 'alert-success' : 'alert-danger' ?> py-2">
    <?= $test_result['ok']
        ? '<i class="bi bi-check-circle me-1"></i>Połączenie działa poprawnie.'
        : '<i class="bi bi-exclamation-triangle me-1"></i>' . h($test_result['error']) ?>
  </div>
  <?php endif; ?>

  <?php if ($cfg && $cfg['last_error']): ?>
  <div class="alert alert-warning py-2">
    <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>
    Ostatnia automatyczna synchronizacja nie powiodła się: <?= h($cfg['last_error']) ?>
  </div>
  <?php endif; ?>

  <div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
      <form method="post">
        <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="save">

        <?php if ($cfg): ?>
        <div class="tw-flex tw-items-center tw-gap-2 tw-mb-3 tw-text-[.85rem] tw-text-green-700">
          <i class="bi bi-check-circle-fill" aria-hidden="true"></i>
          Połączono<?= $cfg['last_sync_at'] ? ' · ostatnia synchronizacja: ' . h(date('d.m.Y H:i', strtotime($cfg['last_sync_at']))) : ' · jeszcze nie synchronizowano' ?>
        </div>
        <?php endif; ?>

        <div class="mb-3">
          <label class="form-label fw-semibold small" for="api_token">Token API</label>
          <input type="text" class="form-control form-control-sm" id="api_token" name="api_token"
                 autocomplete="off"
                 placeholder="<?= $cfg ? '•••••••• (zapisany — zostaw puste, aby nie zmieniać)' : 'wklej swój token z Nozbe' ?>">
          <div class="form-text">
            W Nozbe: Ustawienia → API tokens → <em>Add new token</em>. To Twój prywatny
            klucz — traktuj go jak hasło, nikt w feerSZO go nie widzi po zapisaniu.
          </div>
        </div>

        <div class="mb-3">
          <label class="form-label fw-semibold small" for="project_id">Projekt docelowy w Nozbe</label>
          <input type="text" class="form-control form-control-sm" id="project_id" name="project_id"
                 value="<?= h($cfg['project_id'] ?? 'task_me') ?>" placeholder="task_me">
          <div class="form-text">
            Domyślnie <code>task_me</code> — Twój osobisty Inbox w Nozbe. Możesz tu wkleić
            ID innego projektu, jeśli wolisz żeby zadania trafiały tam.
          </div>
        </div>

        <button class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1"></i><?= $cfg ? 'Zapisz' : 'Połącz konto' ?></button>
      </form>

      <?php if ($cfg): ?>
      <hr>
      <div class="d-flex gap-2 flex-wrap">
        <form method="post">
          <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
          <input type="hidden" name="_action" value="test">
          <button class="btn btn-outline-secondary btn-sm"><i class="bi bi-plug me-1"></i>Testuj połączenie</button>
        </form>
        <form method="post" onsubmit="return confirm('Odłączyć konto Nozbe? Automatyczna synchronizacja zostanie zatrzymana.')">
          <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
          <input type="hidden" name="_action" value="disconnect">
          <button class="btn btn-outline-danger btn-sm"><i class="bi bi-x-lg me-1"></i>Odłącz konto</button>
        </form>
      </div>
      <?php endif; ?>
    </div>
  </div>

</div>

<?php require_once __DIR__ . '/includes/footer_tasks.php'; ?>
