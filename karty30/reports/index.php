<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/karty30.php';

k30_require_access();
karty30_migrate();

$PAGE_TITLE = 'Raporty — Karty 30';

include dirname(dirname(__DIR__)) . '/karty30/includes/header_k30.php';
?>

<nav aria-label="breadcrumb" class="mb-3">
  <ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Start</a></li>
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/karty30/index.php">Karty 30</a></li>
    <li class="breadcrumb-item active">Raporty</li>
  </ol>
</nav>

<h4 class="fw-bold mb-4"><i class="bi bi-bar-chart text-secondary me-2"></i>Raporty — Dydaktyka</h4>

<div class="row g-3">
  <div class="col-md-4">
    <a href="monthly.php" class="text-decoration-none">
      <div class="card shadow-sm h-100 border-0" style="border-left:4px solid #2E844A !important">
        <div class="card-body">
          <div class="d-flex align-items-center gap-2 mb-2">
            <div style="width:38px;height:38px;border-radius:9px;background:#EFF7ED;color:#2E844A;display:flex;align-items:center;justify-content:center;font-size:1.1rem">
              <i class="bi bi-calendar-month"></i>
            </div>
            <h6 class="mb-0 fw-bold">Raport miesięczny</h6>
          </div>
          <p class="text-muted mb-0" style="font-size:.84rem">
            Lista zatwierdzonych konsultacji w wybranym miesiącu z eksportem CSV (format MRPiPS).
          </p>
        </div>
      </div>
    </a>
  </div>

  <div class="col-md-4">
    <a href="cancelled.php" class="text-decoration-none">
      <div class="card shadow-sm h-100 border-0" style="border-left:4px solid #DC2626 !important">
        <div class="card-body">
          <div class="d-flex align-items-center gap-2 mb-2">
            <div style="width:38px;height:38px;border-radius:9px;background:#FEF2F2;color:#DC2626;display:flex;align-items:center;justify-content:center;font-size:1.1rem">
              <i class="bi bi-x-circle"></i>
            </div>
            <h6 class="mb-0 fw-bold">Odwołane terminy</h6>
          </div>
          <p class="text-muted mb-0" style="font-size:.84rem">
            Historia odwołanych wizyt z powodami — przez FEER, przez beneficjenta lub nieobecność.
          </p>
        </div>
      </div>
    </a>
  </div>

  <div class="col-md-4">
    <a href="<?= APP_URL ?>/karty30/blacklist/index.php" class="text-decoration-none">
      <div class="card shadow-sm h-100 border-0" style="border-left:4px solid #7C3AED !important">
        <div class="card-body">
          <div class="d-flex align-items-center gap-2 mb-2">
            <div style="width:38px;height:38px;border-radius:9px;background:#F5F3FF;color:#7C3AED;display:flex;align-items:center;justify-content:center;font-size:1.1rem">
              <i class="bi bi-slash-circle"></i>
            </div>
            <h6 class="mb-0 fw-bold">Czarna lista</h6>
          </div>
          <p class="text-muted mb-0" style="font-size:.84rem">
            Beneficjenci wpisani na czarną listę z powodami.
          </p>
        </div>
      </div>
    </a>
  </div>
</div>

<!-- Szybkie statystyki -->
<div class="row g-3 mt-2">
  <?php
  $total_clients = (int)(db_one("SELECT COUNT(*) AS c FROM k30_clients")['c'] ?? 0);
  $total_completed = (int)(db_one("SELECT COUNT(*) AS c FROM k30_consultations WHERE status='completed'")['c'] ?? 0);
  $total_hours = (float)(db_one("SELECT COALESCE(SUM(duration_minutes),0)/60.0 AS h FROM k30_consultations WHERE status='completed'")['h'] ?? 0);
  $total_cancelled = (int)(db_one("SELECT COUNT(*) AS c FROM k30_schedules WHERE status IN ('cancelled_by_feer','cancelled_by_client','no_show','cancelled')")['c'] ?? 0);
  ?>
  <div class="col-md-3">
    <div class="card shadow-sm text-center p-3">
      <div style="font-size:1.8rem;font-weight:800;color:#1D4ED8"><?= $total_clients ?></div>
      <div class="text-muted small">Beneficjentów łącznie</div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="card shadow-sm text-center p-3">
      <div style="font-size:1.8rem;font-weight:800;color:#2E844A"><?= $total_completed ?></div>
      <div class="text-muted small">Zatwierdzonych konsultacji</div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="card shadow-sm text-center p-3">
      <div style="font-size:1.8rem;font-weight:800;color:#7C3AED"><?= number_format($total_hours, 1) ?></div>
      <div class="text-muted small">Godzin konsultacji</div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="card shadow-sm text-center p-3">
      <div style="font-size:1.8rem;font-weight:800;color:#DC2626"><?= $total_cancelled ?></div>
      <div class="text-muted small">Odwołanych terminów</div>
    </div>
  </div>
</div>

<?php include dirname(dirname(__DIR__)) . '/karty30/includes/footer_k30.php'; ?>
