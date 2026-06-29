<?php
/**
 * extforms/index.php — Hub modułu „Formularze zewnętrzne".
 * Lista aktywnych formularzy z linkami do panelu admina i formularza publicznego.
 */
$_root = dirname(__DIR__);
require_once $_root . '/config.php';
require_once $_root . '/includes/db.php';
require_once $_root . '/includes/auth.php';
require_once $_root . '/includes/functions.php';

require_login();
if (is_viewer()) { header('Location: ' . APP_URL . '/panel/index.php'); exit; }

$PAGE_TITLE = 'Formularze zewnętrzne';
include $_root . '/includes/header.php';

// Zbierz dostępne formularze (widoczne dla zalogowanego)
$forms = [];

if (module_enabled('dostepnosc_ngo_enabled')) {
    require_once $_root . '/includes/consultations.php';
    cc_migrate();
    $cnt = db_one("SELECT COUNT(*) AS c FROM szo_consultation_cards")['c'] ?? 0;
    $forms[] = [
        'key'         => 'konsultacjeADNGO',
        'icon'        => 'bi-clipboard2-pulse',
        'color'       => 'primary',
        'title'       => 'Karty doradztwa',
        'subtitle'    => 'ADNGO — formularz zewnętrzny',
        'desc'        => 'Rejestracja sesji doradczych i konsultacji dla organizacji.',
        'admin_url'   => APP_URL . '/extforms/konsultacjeADNGO/admin.php',
        'public_url'  => APP_URL . '/extforms/konsultacjeADNGO/',
        'count'       => (int)$cnt,
        'count_label' => 'kart',
    ];
}

if (module_enabled('dostepnosc_ngo_enabled')) {
    $cnt = db_one("SELECT COUNT(*) AS c FROM szo_assistance_requests WHERE status='new'")['c'] ?? 0;
    $forms[] = [
        'key'         => 'asystaFEER',
        'icon'        => 'bi-universal-access-circle',
        'color'       => 'success',
        'title'       => 'Zgłoszenia asysty',
        'subtitle'    => 'FEER — formularz zewnętrzny',
        'desc'        => 'Zgłoszenia zapotrzebowania na asystę osoby niepełnosprawnej.',
        'admin_url'   => APP_URL . '/asysta/admin.php',
        'public_url'  => APP_URL . '/extforms/asystaFEER/',
        'count'       => (int)$cnt,
        'count_label' => 'nowych',
    ];
}
?>
<div class="container-fluid px-3 px-md-4 py-3">

  <h1 class="h4 fw-bold mb-4">
    <i class="bi bi-collection text-primary me-1"></i>Formularze zewnętrzne
  </h1>

  <?= flash_html() ?>

  <?php if (!$forms): ?>
  <div class="card border-0 shadow-sm">
    <div class="card-body text-center text-muted py-5">
      <i class="bi bi-slash-circle fs-2 d-block mb-2"></i>
      Brak aktywnych formularzy. Włącz odpowiednie moduły w ustawieniach.
    </div>
  </div>
  <?php else: ?>
  <div class="row g-3">
    <?php foreach ($forms as $f): ?>
    <div class="col-md-6 col-xl-4">
      <div class="card border-0 shadow-sm h-100">
        <div class="card-body d-flex flex-column gap-2 p-4">
          <div class="d-flex align-items-center gap-3 mb-1">
            <div class="rounded-3 p-2 bg-<?= h($f['color']) ?> bg-opacity-10">
              <i class="bi <?= h($f['icon']) ?> fs-4 text-<?= h($f['color']) ?>"></i>
            </div>
            <div>
              <div class="fw-bold"><?= h($f['title']) ?></div>
              <div class="text-muted small"><?= h($f['subtitle']) ?></div>
            </div>
            <?php if ($f['count'] > 0): ?>
            <span class="badge text-bg-<?= h($f['color']) ?> ms-auto">
              <?= $f['count'] ?> <?= h($f['count_label']) ?>
            </span>
            <?php endif; ?>
          </div>
          <p class="text-muted small mb-0"><?= h($f['desc']) ?></p>
          <div class="d-flex gap-2 mt-auto pt-2">
            <a href="<?= h($f['admin_url']) ?>" class="btn btn-<?= h($f['color']) ?> btn-sm">
              <i class="bi bi-list-ul me-1"></i>Panel
            </a>
            <a href="<?= h($f['public_url']) ?>" target="_blank" rel="noopener"
               class="btn btn-outline-secondary btn-sm">
              <i class="bi bi-box-arrow-up-right me-1"></i>Formularz
            </a>
          </div>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

</div>
<?php include $_root . '/includes/footer.php'; ?>
