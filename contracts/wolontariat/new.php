<?php
/**
 * Wybór trybu dodawania porozumienia wolontariackiego:
 *   - Kreator (wizard) — minimalne pola, krok po kroku
 *   - Pełny formularz  — wszystkie pola
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';

require_role('admin', 'editor');
require_module_enabled('contract_wolontariat', 'Umowy wolontariackie');

$PAGE_TITLE = 'Nowe porozumienie wolontariackie';
include dirname(dirname(__DIR__)) . '/includes/header.php';
?>

<nav aria-label="breadcrumb" class="mb-2">
  <ol class="breadcrumb" style="font-size:.8rem">
    <li class="breadcrumb-item"><a href="list.php">Wolontariat</a></li>
    <li class="breadcrumb-item active">Nowe porozumienie</li>
  </ol>
</nav>

<div class="text-center py-4 mb-2">
  <div class="mb-3">
    <span class="rounded-circle bg-primary bg-opacity-10 d-inline-flex align-items-center justify-content-center"
          style="width:64px;height:64px">
      <i class="bi bi-file-earmark-person text-primary" style="font-size:1.8rem"></i>
    </span>
  </div>
  <h4 class="fw-bold mb-1">Nowe porozumienie wolontariackie</h4>
  <p class="text-muted mb-0">Wybierz sposób wprowadzania danych</p>
</div>


<div class="row g-4 justify-content-center mb-5">

  <!-- ── Kreator ─────────────────────────────────────────────────────────── -->
  <div class="col-md-5">
    <a href="wizard.php" class="text-decoration-none">
    <div class="card shadow-sm border-2 h-100 card-hover-primary">
      <div class="card-body text-center py-5 px-4">
        <div class="mb-3">
          <span class="rounded-circle bg-success bg-opacity-10 d-inline-flex align-items-center justify-content-center"
                style="width:56px;height:56px">
            <i class="bi bi-magic text-success" style="font-size:1.6rem"></i>
          </span>
        </div>
        <h5 class="fw-bold mb-2">Kreator — tryb prosty</h5>
        <p class="text-muted mb-3" style="font-size:.9rem">
          Trzy kroki, minimum formalności.<br>
          Idealne do szybkiego wprowadzenia nowego wolontariusza.
        </p>
        <div class="d-flex flex-column gap-1 text-start mx-auto mb-4" style="max-width:240px">
          <div class="text-muted small"><i class="bi bi-check-circle text-success me-2"></i>Dane wolontariusza</div>
          <div class="text-muted small"><i class="bi bi-check-circle text-success me-2"></i>Podstawowe daty i zakres</div>
          <div class="text-muted small"><i class="bi bi-check-circle text-success me-2"></i>Podgląd i zapis</div>
        </div>
        <span class="btn btn-success px-4">
          <i class="bi bi-magic me-2"></i>Uruchom kreator
        </span>
      </div>
    </div>
    </a>
  </div>

  <!-- ── Pełny formularz ─────────────────────────────────────────────────── -->
  <div class="col-md-5">
    <a href="add.php" class="text-decoration-none">
    <div class="card shadow-sm border-2 h-100 card-hover-primary">
      <div class="card-body text-center py-5 px-4">
        <div class="mb-3">
          <span class="rounded-circle bg-primary bg-opacity-10 d-inline-flex align-items-center justify-content-center"
                style="width:56px;height:56px">
            <i class="bi bi-card-list text-primary" style="font-size:1.6rem"></i>
          </span>
        </div>
        <h5 class="fw-bold mb-2">Pełny formularz</h5>
        <p class="text-muted mb-3" style="font-size:.9rem">
          Wszystkie pola w jednym widoku.<br>
          Dla zaawansowanych przypadków i importu z webNGO.
        </p>
        <div class="d-flex flex-column gap-1 text-start mx-auto mb-4" style="max-width:240px">
          <div class="text-muted small"><i class="bi bi-check-circle text-primary me-2"></i>BHP, ubezpieczenia, ePodpis</div>
          <div class="text-muted small"><i class="bi bi-check-circle text-primary me-2"></i>Adres korespondencyjny</div>
          <div class="text-muted small"><i class="bi bi-check-circle text-primary me-2"></i>Integracja webNGO i M365</div>
        </div>
        <span class="btn btn-primary px-4">
          <i class="bi bi-card-list me-2"></i>Otwórz pełny formularz
        </span>
      </div>
    </div>
    </a>
  </div>

</div>

<style>
.card-hover-primary { transition: border-color .18s, box-shadow .18s; border-color: #dee2e6 !important; }
.card-hover-primary:hover { border-color: var(--bs-primary) !important; box-shadow: 0 0 0 3px rgba(13,110,253,.12) !important; }
</style>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
