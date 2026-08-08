<?php
/**
 * karty30/admin/clean_k30.php — Skrypt czyszczący dane modułu D3.
 * Dostępny wyłącznie dla administratora.
 *
 * Operacje (każda osobno potwierdzona):
 *  - Wyczyść harmonogram (terminy)
 *  - Wyczyść konsultacje
 *  - Wyczyść listę oczekujących
 *  - Wyczyść konta M365 D3 (z bazy — NIE usuwa kont z Azure)
 *  - Wyczyść zajęcia TI (kursy, lekcje, obecność, rozliczenia)
 *  - Wyczyść WSZYSTKO (wszystkie tabele k30_*)
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/karty30.php';

k30_require_access();
karty30_migrate();
require_role('admin');

$PAGE_TITLE = 'Czyszczenie danych D3';

// Definicje operacji czyszczenia
$OPS = [
    'schedules' => [
        'label'   => 'Terminy i harmonogram',
        'desc'    => 'Usuwa wszystkie terminy wizyt, serie cykliczne i powiązane dane finansowe.',
        'icon'    => 'bi-calendar-x',
        'color'   => '#f59e0b',
        'tables'  => ['k30_schedules'],
        'confirm' => 'Usunąć WSZYSTKIE terminy K30?',
        'danger'  => false,
    ],
    'consultations' => [
        'label'   => 'Konsultacje',
        'desc'    => 'Usuwa wszystkie karty konsultacji (podpisane i niepodpisane).',
        'icon'    => 'bi-clipboard2-x',
        'color'   => '#f59e0b',
        'tables'  => ['k30_consultations'],
        'confirm' => 'Usunąć WSZYSTKIE konsultacje K30?',
        'danger'  => false,
    ],
    'waiting' => [
        'label'   => 'Lista oczekujących',
        'desc'    => 'Czyści kolejkę oczekujących na konsultację.',
        'icon'    => 'bi-hourglass-split',
        'color'   => '#6366f1',
        'tables'  => ['k30_waiting_list'],
        'confirm' => 'Wyczyścić listę oczekujących?',
        'danger'  => false,
    ],
    'm365' => [
        'label'   => 'Konta M365 (tylko baza)',
        'desc'    => 'Czyści dane kont M365 z bazy (login, ID konta). NIE usuwa kont z Azure AD.',
        'icon'    => 'bi-microsoft',
        'color'   => '#0ea5e9',
        'sql'     => "UPDATE k30_clients SET m365_user_id=NULL, m365_login=NULL, m365_employee_id=NULL, m365_license_sku=NULL, m365_provisioned_at=NULL",
        'confirm' => 'Wyczyścić dane kont M365 z bazy? (Konta w Azure NIE zostaną usunięte)',
        'danger'  => false,
    ],
    'ti' => [
        'label'   => 'Zajęcia TI',
        'desc'    => 'Usuwa kursy TI, lekcje, listy obecności, rozliczenia miesięczne i konta kursantów.',
        'icon'    => 'bi-pc-display',
        'color'   => '#2563eb',
        'tables'  => ['k30_ti_billing','k30_ti_attendance','k30_ti_sessions','k30_ti_enrollments','k30_ti_student_accounts','k30_ti_courses'],
        'confirm' => 'Usunąć WSZYSTKIE dane zajęć TI (kursy, lekcje, konta kursantów)?',
        'danger'  => true,
    ],
    'pfron' => [
        'label'   => 'Umowy PFRON',
        'desc'    => 'Usuwa wszystkie umowy PFRON i ich historię.',
        'icon'    => 'bi-building-fill-check',
        'color'   => '#7c3aed',
        'tables'  => ['k30_pfron_contracts'],
        'confirm' => 'Usunąć WSZYSTKIE umowy PFRON?',
        'danger'  => true,
    ],
    'clients' => [
        'label'   => 'Beneficjenci i wszystkie dane',
        'desc'    => 'Usuwa WSZYSTKICH beneficjentów i kaskadowo całe dane D3 (terminy, konsultacje, PFRON, TI, kolejka, M365).',
        'icon'    => 'bi-people-fill',
        'color'   => '#dc2626',
        'tables'  => ['k30_waiting_list','k30_ti_billing','k30_ti_attendance','k30_ti_sessions','k30_ti_enrollments','k30_ti_student_accounts','k30_ti_courses','k30_pfron_contracts','k30_schedules','k30_consultations','k30_clients'],
        'confirm' => 'UWAGA! Usunąć WSZYSTKICH beneficjentów i wszystkie dane K30? Tej operacji nie można cofnąć!',
        'danger'  => true,
    ],
];

// Statystyki aktualnego stanu
function k30_count(string $table): int {
    try {
        $r = db_one("SELECT COUNT(*) AS c FROM $table");
        return (int)($r['c'] ?? 0);
    } catch (\Throwable $e) { return 0; }
}

$stats = [
    'k30_clients'              => k30_count('k30_clients'),
    'k30_schedules'            => k30_count('k30_schedules'),
    'k30_consultations'        => k30_count('k30_consultations'),
    'k30_waiting_list'         => k30_count('k30_waiting_list'),
    'k30_pfron_contracts'      => k30_count('k30_pfron_contracts'),
    'k30_ti_courses'           => k30_count('k30_ti_courses'),
    'k30_ti_sessions'          => k30_count('k30_ti_sessions'),
    'k30_ti_attendance'        => k30_count('k30_ti_attendance'),
    'k30_ti_student_accounts'  => k30_count('k30_ti_student_accounts'),
    'k30_ti_billing'           => k30_count('k30_ti_billing'),
];
$m365_count = (int)(db_one("SELECT COUNT(*) AS c FROM k30_clients WHERE m365_user_id IS NOT NULL AND m365_user_id != ''")['c'] ?? 0);

// POST
$result_msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = $_POST['_op'] ?? '';
    if (!array_key_exists($op, $OPS)) {
        flash_set('danger', 'Nieznana operacja.');
        header('Location: clean_k30.php'); exit;
    }
    $def = $OPS[$op];
    $pdo = db();
    $pdo->exec("PRAGMA foreign_keys=OFF");
    try {
        if (isset($def['sql'])) {
            $pdo->exec($def['sql']);
            $affected = $pdo->query("SELECT changes()")->fetchColumn();
        } else {
            $affected = 0;
            foreach ($def['tables'] as $t) {
                $pdo->exec("DELETE FROM $t");
                $affected += (int)$pdo->query("SELECT changes()")->fetchColumn();
            }
        }
        try {
            require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
            authlog_write((int)(current_user()['id'] ?? 0), 'k30_clean',
                current_user()['email'] ?? '',
                "Czyszczenie D3 [{$op}]: usunięto {$affected} rekordów"
            );
        } catch (\Throwable $el) {}
        flash_set('success', 'Operacja "' . $def['label'] . '" zakonczona. Usunieto ' . $affected . ' rekordow.');
    } catch (\Throwable $e) {
        flash_set('danger', 'Błąd: ' . $e->getMessage());
    } finally {
        $pdo->exec("PRAGMA foreign_keys=ON");
    }
    header('Location: clean_k30.php'); exit;
}

include dirname(dirname(__DIR__)) . '/karty30/includes/header_k30.php';
?>

<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/karty30/index.php">Dydaktyka 3</a></li>
  <li class="breadcrumb-item active">Czyszczenie danych</li>
</ol></nav>

<div class="d-flex align-items-center mb-3 gap-2">
  <h4 class="mb-0 fw-bold text-danger"><i class="bi bi-trash3-fill me-2"></i>Czyszczenie danych K30</h4>
</div>

<div class="alert alert-danger d-flex gap-2 mb-4">
  <i class="bi bi-exclamation-triangle-fill flex-shrink-0 fs-5 mt-1"></i>
  <div>
    <strong>Uwaga!</strong> Operacje są <strong>nieodwracalne</strong>.
    Każda operacja usuwa dane trwale z bazy. Zrób backup przed czyszczeniem.
    Czyszczenie kont M365 z bazy nie usuwa kont z Azure AD.
  </div>
</div>

<?= flash_html() ?>

<!-- Statystyki -->
<div class="card border-0 shadow-sm mb-4" style="max-width:700px">
  <div class="card-header fw-semibold"><i class="bi bi-database me-2"></i>Aktualny stan bazy K30</div>
  <div class="card-body py-2">
    <div class="row g-1" style="font-size:.85rem">
      <?php foreach ([
        ['Beneficjenci',       $stats['k30_clients'],             'bi-people'],
        ['Terminy wizyt',      $stats['k30_schedules'],           'bi-calendar3'],
        ['Konsultacje',        $stats['k30_consultations'],       'bi-clipboard2-check'],
        ['Oczekujący',         $stats['k30_waiting_list'],        'bi-hourglass-split'],
        ['Umowy PFRON',        $stats['k30_pfron_contracts'],     'bi-building-fill-check'],
        ['Kursy TI',           $stats['k30_ti_courses'],          'bi-pc-display'],
        ['Lekcje TI',          $stats['k30_ti_sessions'],         'bi-calendar-check'],
        ['Wpisy obecności',    $stats['k30_ti_attendance'],       'bi-person-check'],
        ['Konta kursantów',    $stats['k30_ti_student_accounts'], 'bi-person-badge'],
        ['Rozliczenia TI',     $stats['k30_ti_billing'],          'bi-receipt'],
        ['Konta M365 (baza)',  $m365_count,                       'bi-microsoft'],
      ] as [$lbl, $cnt, $ic]): ?>
      <div class="col-6 col-sm-4 col-md-3">
        <div class="d-flex align-items-center gap-2 p-1">
          <i class="bi <?= $ic ?> text-muted" style="width:16px"></i>
          <span class="text-muted"><?= $lbl ?>:</span>
          <strong class="<?= $cnt > 0 ? 'text-dark' : 'text-muted' ?>"><?= $cnt ?></strong>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- Operacje czyszczenia -->
<div class="row g-3" style="max-width:900px">
  <?php foreach ($OPS as $op_key => $op): ?>
  <div class="col-sm-6 col-lg-4">
    <div class="card border-0 shadow-sm h-100" style="border-top:3px solid <?= h($op['color']) ?>!important">
      <div class="card-body d-flex flex-column">
        <div class="d-flex align-items-center gap-2 mb-2">
          <i class="bi <?= h($op['icon']) ?> fs-5" style="color:<?= h($op['color']) ?>"></i>
          <span class="fw-semibold"><?= h($op['label']) ?></span>
          <?php if ($op['danger']): ?>
          <span class="badge bg-danger-subtle text-danger border border-danger-subtle ms-auto" style="font-size:.65rem">Uwaga</span>
          <?php endif; ?>
        </div>
        <p class="text-muted small mb-3 flex-grow-1"><?= h($op['desc']) ?></p>
        <form method="post"
              onsubmit="return confirm('<?= h(addslashes($op['confirm'])) ?>')">
          <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="_op"   value="<?= h($op_key) ?>">
          <button type="submit"
                  class="btn btn-sm w-100 <?= $op['danger'] ? 'btn-danger' : 'btn-outline-warning' ?>">
            <i class="bi bi-trash me-1"></i>Wyczyść: <?= h($op['label']) ?>
          </button>
        </form>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<div class="mt-4 text-muted small" style="max-width:700px">
  <i class="bi bi-info-circle me-1"></i>
  Wszystkie operacje są logowane w dzienniku systemowym.
  Czyszczenie kont M365 z bazy nie wysyła żadnych żądań do Microsoft Graph API —
  konta w Azure AD pozostają aktywne i muszą być usunięte ręcznie w portalu Azure.
</div>

<?php include dirname(dirname(__DIR__)) . '/karty30/includes/footer_k30.php'; ?>
