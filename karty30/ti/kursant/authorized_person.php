<?php
/**
 * Panel osoby upoważnionej przez pełnoletniego kursanta.
 * Logowanie: login + hasło (nadane przez kursanta w index.php?tab=upowaznieni).
 * Widok tylko do odczytu: lekcje, frekwencja, rozliczenia.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/functions.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/karty30.php';
require_once __DIR__ . '/auth.php';

karty30_migrate();

$err = '';

// Wylogowanie
if (isset($_GET['logout'])) { authp_logout(); header('Location: authorized_person.php'); exit; }

// ── Logowanie ──────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !authp_current()) {
    $login = trim((string)($_POST['login'] ?? ''));
    $pass  = (string)($_POST['password'] ?? '');
    if (!authp_login($login, $pass)) {
        $err = 'Nieprawidłowy login lub hasło.';
    } else {
        header('Location: authorized_person.php'); exit;
    }
}

$me = authp_current();

if (!$me) {
    // Formularz logowania
    $KP_TITLE  = 'Logowanie — wgląd upoważniony';
    $KP_TOPBAR = [
        'brand'  => defined('ORG_NAME') ? ORG_NAME : 'Panel TI',
        'icon'   => 'person-check',
        'user'   => '',
        'logout' => '',
    ];
    include __DIR__ . '/_layout_head.php';
?>
<main id="main" class="container-xl px-3 py-5" style="max-width:420px">
  <div class="card shadow border-0">
    <div class="card-body p-4">
      <h1 class="h5 fw-bold mb-1"><i class="bi bi-person-check text-primary me-2" aria-hidden="true"></i>Panel osoby upoważnionej</h1>
      <p class="text-body-secondary small mb-3">Zaloguj się loginem i hasłem nadanym przez kursanta.</p>
      <?php if ($err): ?>
      <div class="alert alert-danger py-2 small" role="alert"><i class="bi bi-exclamation-circle me-1" aria-hidden="true"></i><?= h($err) ?></div>
      <?php endif; ?>
      <form method="post">
        <div class="mb-3">
          <label class="form-label fw-semibold" for="ap-login">Login</label>
          <input type="text" class="form-control font-monospace" id="ap-login" name="login" autofocus autocomplete="username" required>
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold" for="ap-pass">Hasło</label>
          <input type="password" class="form-control" id="ap-pass" name="password" autocomplete="current-password" required>
        </div>
        <button type="submit" class="btn btn-primary w-100 fw-semibold"><i class="bi bi-box-arrow-in-right me-1" aria-hidden="true"></i>Zaloguj się</button>
      </form>
    </div>
  </div>
</main>
<?php include __DIR__ . '/_layout_foot.php'; exit; }

// ── Panel (zalogowany) ─────────────────────────────────────────────────────────
$account = db_one("SELECT * FROM k30_ti_student_accounts WHERE id=? AND is_active=1", [(int)$me['student_id']]);
if (!$account) { authp_logout(); header('Location: authorized_person.php'); exit; }
$client  = db_one("SELECT * FROM k30_clients WHERE id=?", [(int)$me['client_id']]) ?: [];
$is_minor = !empty($account['is_minor']);
if ($is_minor) { authp_logout(); header('Location: authorized_person.php'); exit; } // nie dla małoletnich

$tab = $_GET['tab'] ?? 'lekcje';

$KP_TITLE  = 'Wgląd upoważniony — ' . ($client['name'] ?? '');
$KP_TOPBAR = [
    'brand'  => defined('ORG_NAME') ? ORG_NAME : 'Panel TI',
    'icon'   => 'person-check',
    'user'   => $me['name'] . ' (upoważniony/a)',
    'logout' => 'authorized_person.php?logout=1',
];
include __DIR__ . '/_layout_head.php';
?>

<nav class="container-xl px-3 pt-3" aria-label="Sekcje panelu">
  <ul class="nav nav-tabs">
    <li class="nav-item">
      <a class="nav-link <?= $tab==='lekcje'?'active':'' ?>" href="?tab=lekcje">
        <i class="bi bi-calendar-check me-1" aria-hidden="true"></i>Lekcje
      </a>
    </li>
    <li class="nav-item">
      <a class="nav-link <?= $tab==='rozliczenia'?'active':'' ?>" href="?tab=rozliczenia">
        <i class="bi bi-receipt me-1" aria-hidden="true"></i>Rozliczenia
      </a>
    </li>
  </ul>
</nav>

<main id="main" class="container-xl px-3 py-4">

<div class="alert alert-info d-flex gap-2 align-items-center py-2 mb-3 small" role="note">
  <i class="bi bi-eye flex-shrink-0" aria-hidden="true"></i>
  <span>Wgląd do panelu kursanta <strong><?= h($client['name'] ?? '') ?></strong>. Widok tylko do odczytu.</span>
</div>

<?php
$rv_client_id = (int)$me['client_id'];
$rv_show_lessons = false;
?>

<?php if ($tab === 'lekcje'): ?>
<h1 class="h5 fw-bold mb-3"><i class="bi bi-calendar-check text-primary me-2" aria-hidden="true"></i>Lekcje</h1>
<?php include __DIR__ . '/_frekwencja_view.php'; ?>

<?php elseif ($tab === 'rozliczenia'): ?>
<h1 class="h5 fw-bold mb-3"><i class="bi bi-receipt text-primary me-2" aria-hidden="true"></i>Rozliczenia</h1>
<?php include __DIR__ . '/_rozliczenia_view.php'; ?>

<?php endif; ?>

</main>

<?php include __DIR__ . '/_layout_foot.php'; ?>
