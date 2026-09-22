<?php
/**
 * auth/redmine_account.php — moje połączenie z Redmine (OAuth) + moje zgłoszenia.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/redmine.php';

require_login();
$uid = (int)(current_user()['id'] ?? 0);
$PAGE_TITLE = 'Moje konto Redmine';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_op'] ?? '') === 'disconnect') {
    csrf_check();
    redmine_user_disconnect($uid);
    flash_set('success', 'Rozłączono konto Redmine.');
    header('Location: redmine_account.php'); exit;
}

$configured = redmine_oauth_configured();
$connected  = redmine_user_connected($uid);

// Moje zgłoszenia z Redmine (gdy połączony).
$issues = [];
$issues_error = '';
if ($connected) {
    try {
        $res = redmine_user_api_request($uid, 'GET', '/issues.json', null,
            ['author_id' => 'me', 'status_id' => '*', 'limit' => 25, 'sort' => 'updated_on:desc']);
        $issues = (array)($res['issues'] ?? []);
    } catch (\Throwable $e) {
        $issues_error = $e->getMessage();
    }
}

include dirname(__DIR__) . '/includes/header.php';
?>
<div class="container my-4" style="max-width:860px">
  <h4 class="mb-3"><i class="bi bi-kanban me-2 text-primary"></i>Moje konto Redmine</h4>
  <?= flash_html() ?>

  <div class="card shadow-sm mb-4">
    <div class="card-body d-flex align-items-center justify-content-between flex-wrap gap-2">
      <?php if (!$configured): ?>
        <span class="text-muted">Integracja OAuth z Redmine nie jest jeszcze skonfigurowana przez administratora.</span>
      <?php elseif ($connected): ?>
        <span><i class="bi bi-check-circle-fill text-success me-1"></i>Twoje konto jest połączone z Redmine.</span>
        <form method="post" onsubmit="return confirm('Rozłączyć konto Redmine?');">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="_op" value="disconnect">
          <button class="btn btn-outline-danger btn-sm"><i class="bi bi-x-circle"></i> Rozłącz</button>
        </form>
      <?php else: ?>
        <span>Połącz swoje konto SZO z Redmine, aby Twoje zgłoszenia i komentarze były podpisane Twoim nazwiskiem.</span>
        <a class="btn btn-primary btn-sm" href="<?= APP_URL ?>/auth/redmine_connect.php">
          <i class="bi bi-box-arrow-up-right"></i> Połącz z Redmine
        </a>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($connected): ?>
  <div class="card shadow-sm">
    <div class="card-header fw-semibold"><i class="bi bi-list-task me-1"></i>Moje zgłoszenia w Redmine</div>
    <div class="card-body p-0">
      <?php if ($issues_error !== ''): ?>
        <div class="p-3 text-danger small">Nie udało się pobrać: <?= h($issues_error) ?></div>
      <?php elseif (!$issues): ?>
        <div class="p-3 text-muted small">Brak zgłoszeń.</div>
      <?php else: ?>
        <table class="table table-sm mb-0 align-middle">
          <thead><tr><th class="small">#</th><th class="small">Temat</th><th class="small">Status</th><th class="small text-nowrap">Aktualizacja</th></tr></thead>
          <tbody>
          <?php foreach ($issues as $i): ?>
            <tr>
              <td class="small"><a href="<?= h(redmine_issue_url((int)($i['id'] ?? 0))) ?>" target="_blank" rel="noopener">#<?= (int)($i['id'] ?? 0) ?></a></td>
              <td class="small"><?= h((string)($i['subject'] ?? '')) ?></td>
              <td class="small"><span class="badge bg-light text-dark border"><?= h((string)($i['status']['name'] ?? '')) ?></span></td>
              <td class="small text-nowrap"><?= h(substr((string)($i['updated_on'] ?? ''), 0, 10)) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
