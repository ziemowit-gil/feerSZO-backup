<?php
/**
 * karty30/admin/test_login.php — Testowe logowanie jako prowadzący lub kursant.
 * Tylko dla adminów. Generuje jednorazowy token i przekierowuje do panelu.
 */
require_once dirname(dirname(__DIR__)) . '/includes/common.php';
require_once dirname(dirname(__DIR__)) . '/includes/karty30.php';
require_once dirname(dirname(__DIR__)) . '/karty30/includes/header_k30.php';

if (!is_admin()) {
    http_response_code(403);
    include dirname(dirname(__DIR__)) . '/includes/403.php';
    exit;
}

karty30_migrate();

// ── Akcja: generuj token i przekieruj ────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['type'], $_POST['target_id'])) {
    csrf_check();
    $type      = $_POST['type'] === 'stu' ? 'stu' : 'dyd';
    $target_id = (int)$_POST['target_id'];
    if ($target_id > 0) {
        $token = k30_imp_token_create($type, $target_id, uid());
        $redirect = $type === 'stu'
            ? APP_URL . '/karty30/ti/kursant/imp.php?t=' . urlencode($token)
            : APP_URL . '/karty30/ti/dydaktyk/imp.php?t=' . urlencode($token);
        header('Location: ' . $redirect);
        exit;
    }
}

// ── Dane ─────────────────────────────────────────────────────────────────────

$instructors = db_all("
    SELECT DISTINCT u.id, u.name, u.email
    FROM users u
    INNER JOIN k30_ti_courses c ON c.instructor_id = u.id AND c.status != 'cancelled'
    WHERE u.is_active = 1
    ORDER BY u.name
");

$students = db_all("
    SELECT sa.id, sa.login, sa.is_active, c.name AS client_name,
           (SELECT COUNT(*) FROM k30_ti_sessions s
            INNER JOIN k30_ti_enrolments e ON e.course_id = s.course_id AND e.client_id = sa.client_id
            WHERE s.status IN ('planned','held') AND s.lesson_date >= date('now')
           ) AS upcoming
    FROM k30_ti_student_accounts sa
    LEFT JOIN k30_clients c ON c.id = sa.client_id
    WHERE sa.is_active = 1
    ORDER BY c.name, sa.login
    LIMIT 100
");

$PAGE_TITLE = 'Testowe logowanie — Dydaktyka 3';
?>

<div class="container-xxl py-4">
  <nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb">
      <li class="breadcrumb-item"><a href="<?= APP_URL ?>/karty30/index.php">Dydaktyka 3</a></li>
      <li class="breadcrumb-item active">Testowe logowanie</li>
    </ol>
  </nav>

  <div class="d-flex align-items-center gap-2 mb-4">
    <i class="bi bi-person-fill-gear fs-4 text-warning"></i>
    <h4 class="mb-0 fw-bold">Testowe logowanie jako prowadzący / kursant</h4>
    <span class="badge bg-warning text-dark ms-2">Tylko admin</span>
  </div>

  <div class="alert alert-warning d-flex gap-2" role="alert">
    <i class="bi bi-exclamation-triangle-fill flex-shrink-0 mt-1"></i>
    <div>Token jednorazowy, ważny <strong>30 sekund</strong>. Otworzy się nowa zakładka z sesją docelowego użytkownika. Twoja sesja admina pozostaje aktywna.</div>
  </div>

  <form method="post" id="impForm">
    <input type="hidden" name="_token" value="<?= csrf_token() ?>">
    <input type="hidden" name="type" id="imp_type" value="">
    <input type="hidden" name="target_id" id="imp_target" value="">
  </form>

  <div class="row g-4">
    <!-- Prowadzący -->
    <div class="col-lg-6">
      <div class="card h-100">
        <div class="card-header fw-semibold">
          <i class="bi bi-person-workspace me-2 text-primary"></i>Prowadzący
          <span class="badge bg-secondary ms-2"><?= count($instructors) ?></span>
        </div>
        <div class="card-body p-0">
          <?php if (empty($instructors)): ?>
            <p class="text-muted p-3 mb-0">Brak prowadzących z aktywnymi kursami.</p>
          <?php else: ?>
            <table class="table table-hover table-sm mb-0">
              <thead class="table-light">
                <tr><th>Imię i nazwisko</th><th>E-mail</th><th></th></tr>
              </thead>
              <tbody>
                <?php foreach ($instructors as $ins): ?>
                  <tr>
                    <td><?= h($ins['name']) ?></td>
                    <td class="text-muted small"><?= h($ins['email']) ?></td>
                    <td>
                      <button type="button" class="btn btn-sm btn-outline-primary imp-btn"
                              data-type="dyd" data-id="<?= $ins['id'] ?>"
                              data-label="<?= h($ins['name']) ?>"
                              target="_blank">
                        <i class="bi bi-box-arrow-in-right me-1"></i>Zaloguj
                      </button>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Kursanci -->
    <div class="col-lg-6">
      <div class="card h-100">
        <div class="card-header fw-semibold">
          <i class="bi bi-mortarboard me-2 text-success"></i>Kursanci
          <span class="badge bg-secondary ms-2"><?= count($students) ?></span>
        </div>
        <div class="card-body p-0">
          <?php if (empty($students)): ?>
            <p class="text-muted p-3 mb-0">Brak aktywnych kont kursantów.</p>
          <?php else: ?>
            <table class="table table-hover table-sm mb-0">
              <thead class="table-light">
                <tr><th>Beneficjent</th><th>Login</th><th>Lekcje</th><th></th></tr>
              </thead>
              <tbody>
                <?php foreach ($students as $stu): ?>
                  <tr>
                    <td><?= h($stu['client_name'] ?? '—') ?></td>
                    <td class="font-monospace small"><?= h($stu['login']) ?></td>
                    <td class="text-muted small"><?= (int)$stu['upcoming'] ?> nadch.</td>
                    <td>
                      <button type="button" class="btn btn-sm btn-outline-success imp-btn"
                              data-type="stu" data-id="<?= $stu['id'] ?>"
                              data-label="<?= h($stu['client_name'] ?? $stu['login']) ?>">
                        <i class="bi bi-box-arrow-in-right me-1"></i>Zaloguj
                      </button>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
document.querySelectorAll('.imp-btn').forEach(function(btn) {
  btn.addEventListener('click', function() {
    var label = this.dataset.label;
    if (!confirm('Zalogować się jako: ' + label + '?\nOtworzy się nowa zakładka.')) return;
    document.getElementById('imp_type').value   = this.dataset.type;
    document.getElementById('imp_target').value = this.dataset.id;
    var form = document.getElementById('impForm');
    form.target = '_blank';
    form.submit();
    form.target = '';
  });
});
</script>

<?php include dirname(dirname(__DIR__)) . '/karty30/includes/footer_k30.php'; ?>
