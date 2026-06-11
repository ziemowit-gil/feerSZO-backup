<?php
/**
 * admin/avatars.php — moderacja zdjęć profilowych użytkowników.
 * Admin zatwierdza lub odrzuca oczekujące przesłania.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/directory.php';

require_role('admin');
directory_migrate();

$PAGE_TITLE = 'Moderacja zdjęć profilowych';
$me_id      = (int)(current_user()['id'] ?? 0);

// ── POST ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['_action'] ?? '';
    $uid    = (int)($_POST['uid'] ?? 0);

    if ($action === 'approve' && $uid) {
        if (directory_avatar_approve($uid)) {
            $u = db_one("SELECT name, first_name, last_name FROM users WHERE id=?", [$uid]);
            $n = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')) ?: ($u['name'] ?? '');
            flash_set('success', "Zdjęcie użytkownika <strong>" . h($n) . "</strong> zostało zaakceptowane.");
        } else {
            flash_set('danger', 'Nie udało się zaakceptować zdjęcia.');
        }
    } elseif ($action === 'reject' && $uid) {
        if (directory_avatar_reject($uid)) {
            $u = db_one("SELECT name, first_name, last_name FROM users WHERE id=?", [$uid]);
            $n = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')) ?: ($u['name'] ?? '');
            flash_set('info', "Zdjęcie użytkownika <strong>" . h($n) . "</strong> zostało odrzucone.");
        } else {
            flash_set('danger', 'Nie udało się odrzucić zdjęcia.');
        }
    }
    header('Location: avatars.php');
    exit;
}

$pending = directory_pending_avatars();

include dirname(__DIR__) . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h4 class="mb-0">
    <i class="bi bi-person-bounding-box text-primary"></i>
    Moderacja zdjęć profilowych
    <?php if ($pending): ?>
    <span class="badge bg-warning text-dark ms-1"><?= count($pending) ?></span>
    <?php endif; ?>
  </h4>
  <a href="<?= APP_URL ?>/admin/index.php" class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-arrow-left me-1"></i>Panel admina
  </a>
</div>

<?= flash_html() ?>

<?php if (!$pending): ?>
<div class="card shadow-sm">
  <div class="card-body text-center py-5 text-muted">
    <i class="bi bi-check2-circle fs-2 d-block mb-2 text-success"></i>
    Brak oczekujących zdjęć do zaakceptowania.
  </div>
</div>
<?php else: ?>

<div class="row g-3">
  <?php foreach ($pending as $p):
    $display = trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? '')) ?: ($p['name'] ?? '');
    $pending_url  = APP_URL . '/directory/avatar_thumb.php?uid=' . (int)$p['id'] . '&t=pending';
    $approved_url = !empty($p['avatar_file'])
        ? APP_URL . '/uploads/avatars/' . h($p['avatar_file'])
        : null;
    $since = $p['avatar_pending_at']
        ? date('d.m.Y H:i', strtotime($p['avatar_pending_at']))
        : '—';
  ?>
  <div class="col-sm-6 col-lg-4 col-xl-3">
    <div class="card shadow-sm h-100">
      <div class="card-body p-3">

        <!-- Nagłówek użytkownika -->
        <div class="d-flex align-items-center gap-2 mb-3">
          <?= directory_avatar_html($p, 36) ?>
          <div class="min-w-0">
            <div class="fw-semibold text-truncate" style="font-size:.9rem"><?= h($display) ?></div>
            <div class="text-muted text-truncate" style="font-size:.75rem"><?= h($p['email']) ?></div>
          </div>
        </div>

        <!-- Zdjęcia -->
        <div class="d-flex gap-3 justify-content-center mb-3">
          <!-- Oczekujące (nowe) -->
          <div class="text-center">
            <div class="text-muted mb-1" style="font-size:.7rem;text-transform:uppercase;letter-spacing:.06em">Nowe</div>
            <img src="<?= $pending_url ?>"
                 alt="Proponowane zdjęcie <?= h($display) ?>"
                 class="rounded-circle shadow-sm"
                 style="width:80px;height:80px;object-fit:cover;border:2px solid #F59E0B"
                 onerror="this.src='data:image/svg+xml,<svg xmlns=\'http://www.w3.org/2000/svg\' width=\'80\' height=\'80\'><rect width=\'80\' height=\'80\' fill=\'%23fee2e2\'/><text x=\'50%\' y=\'55%\' dominant-baseline=\'middle\' text-anchor=\'middle\' font-size=\'12\' fill=\'%23991b1b\'>błąd</text></svg>'">
          </div>
          <!-- Aktualne -->
          <div class="text-center">
            <div class="text-muted mb-1" style="font-size:.7rem;text-transform:uppercase;letter-spacing:.06em">Aktualne</div>
            <?php if ($approved_url): ?>
            <img src="<?= $approved_url ?>"
                 alt="Aktualne zdjęcie <?= h($display) ?>"
                 class="rounded-circle"
                 style="width:80px;height:80px;object-fit:cover;border:2px solid #D1D5DB">
            <?php else: ?>
            <?= directory_avatar_html($p, 80) ?>
            <?php endif; ?>
          </div>
        </div>

        <div class="text-center text-muted mb-3" style="font-size:.75rem">
          <i class="bi bi-clock me-1"></i>Przesłano: <?= $since ?>
        </div>

        <!-- Przyciski -->
        <div class="d-flex gap-2">
          <form method="post" class="flex-fill">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="approve">
            <input type="hidden" name="uid"     value="<?= (int)$p['id'] ?>">
            <button type="submit"
                    class="btn btn-success btn-sm w-100"
                    onclick="return confirm('Zaakceptować zdjęcie użytkownika <?= h(addslashes($display)) ?>?')">
              <i class="bi bi-check-lg me-1"></i>Akceptuj
            </button>
          </form>
          <form method="post" class="flex-fill">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="reject">
            <input type="hidden" name="uid"     value="<?= (int)$p['id'] ?>">
            <button type="submit"
                    class="btn btn-outline-danger btn-sm w-100"
                    onclick="return confirm('Odrzucić zdjęcie użytkownika <?= h(addslashes($display)) ?>?')">
              <i class="bi bi-x-lg me-1"></i>Odrzuć
            </button>
          </form>
        </div>

        <!-- Link do profilu -->
        <div class="text-center mt-2">
          <a href="<?= APP_URL ?>/directory/profile.php?id=<?= (int)$p['id'] ?>"
             target="_blank"
             class="text-muted"
             style="font-size:.75rem">
            <i class="bi bi-box-arrow-up-right me-1"></i>Profil użytkownika
          </a>
        </div>

      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
