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

// Wylogowanie
if (isset($_GET['logout'])) { authp_logout(); header('Location: ../login.php?tab=up'); exit; }

$me = authp_current();

// Niezalogowany → centralny login
if (!$me) { header('Location: ../login.php?tab=up'); exit; }

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
