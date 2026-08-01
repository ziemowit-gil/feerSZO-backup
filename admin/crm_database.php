<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/crm.php';

require_role('admin');
require_once dirname(__DIR__) . '/includes/tz_auth.php';
tz_require_level(TZ_LEVEL_MFA, APP_URL . '/admin/crm_database.php', 'Baza CRM');
$PAGE_TITLE = 'Ustawienia bazy CRM';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $type = in_array($_POST['crm_db_type'] ?? '', ['main','sqlite','mysql']) ? $_POST['crm_db_type'] : 'main';
    crm_setting_save('crm_db_type', $type);

    if ($type === 'sqlite') {
        crm_setting_save('crm_db_path', trim($_POST['crm_db_path'] ?? ''));
    } elseif ($type === 'mysql') {
        crm_setting_save('crm_db_host', trim($_POST['crm_db_host'] ?? '127.0.0.1'));
        crm_setting_save('crm_db_port', trim($_POST['crm_db_port'] ?? '3306'));
        crm_setting_save('crm_db_name', trim($_POST['crm_db_name'] ?? ''));
        crm_setting_save('crm_db_user', trim($_POST['crm_db_user'] ?? ''));
        if (!empty($_POST['crm_db_pass'])) {
            crm_setting_save('crm_db_pass', $_POST['crm_db_pass']);
        }
    }

    flash_set('success', 'Ustawienia bazy CRM zapisane. Zmiany będą widoczne przy następnym załadowaniu strony.');
    header('Location: ' . $_SERVER['PHP_SELF']); exit;
}

// Pobierz aktualne ustawienia
$cur_type = crm_setting('crm_db_type') ?: 'main';
$cur = [
    'type'    => $cur_type,
    'path'    => crm_setting('crm_db_path'),
    'host'    => crm_setting('crm_db_host') ?: '127.0.0.1',
    'port'    => crm_setting('crm_db_port') ?: '3306',
    'name'    => crm_setting('crm_db_name'),
    'user'    => crm_setting('crm_db_user'),
];

// Test połączenia (po zapisaniu)
$status = null;
if (isset($_GET['test'])) {
    // Wyczyść cache singleton żeby test użył nowych ustawień
    // (wymaga restart — w tym kontekście testujemy co jest w DB)
    $status = crm_db_status();
}

include dirname(__DIR__) . '/includes/header.php';
?>
<div class="container-fluid py-4" style="max-width:760px">
  <div class="d-flex align-items-center gap-3 mb-4">
    <div style="width:44px;height:44px;border-radius:10px;background:#fff3e0;display:flex;align-items:center;justify-content:center;font-size:1.2rem;color:#fd7e14">
      <i class="bi bi-database-gear"></i>
    </div>
    <div>
      <h1 class="h5 mb-0 fw-bold">Baza danych CRM</h1>
      <p class="text-muted mb-0 small">CRM może korzystać z osobnej bazy SQLite lub MySQL niezależnie od głównej bazy aplikacji.</p>
    </div>
    <a href="?test=1" class="btn btn-outline-secondary btn-sm ms-auto">
      <i class="bi bi-plug me-1"></i>Test połączenia
    </a>
  </div>

  <?= flash_get() ?>

  <?php if ($status): ?>
  <div class="alert alert-<?= $status['ok'] ? 'success' : 'danger' ?> d-flex align-items-center gap-2 mb-3">
    <i class="bi bi-<?= $status['ok'] ? 'check-circle-fill' : 'x-circle-fill' ?>"></i>
    <div>
      <?php if ($status['ok']): ?>
        Połączenie OK · Typ: <strong><?= h($status['type']) ?></strong> · Tabel CRM: <strong><?= $status['tables'] ?></strong>
      <?php else: ?>
        Błąd: <?= h($status['msg']) ?>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <form method="post">
    <?= csrf_field() ?>

    <div class="card border-0 shadow-sm mb-4">
      <div class="card-header bg-white fw-semibold py-2">Typ bazy</div>
      <div class="card-body">

        <!-- Radio: typ bazy -->
        <div class="row g-3 mb-3">
          <div class="col-md-4">
            <label class="card border d-flex align-items-start gap-2 p-3 <?= $cur['type']==='main'?'border-primary bg-primary-subtle':'' ?>" style="cursor:pointer;border-radius:8px">
              <input type="radio" name="crm_db_type" value="main" <?= $cur['type']==='main'?'checked':'' ?> onchange="showDbFields(this.value)" class="mt-1">
              <div>
                <div class="fw-semibold small">Główna baza aplikacji</div>
                <div class="text-muted" style="font-size:.75rem">Tabele CRM współdzielą bazę umów (domyślnie)</div>
              </div>
            </label>
          </div>
          <div class="col-md-4">
            <label class="card border d-flex align-items-start gap-2 p-3 <?= $cur['type']==='sqlite'?'border-primary bg-primary-subtle':'' ?>" style="cursor:pointer;border-radius:8px">
              <input type="radio" name="crm_db_type" value="sqlite" <?= $cur['type']==='sqlite'?'checked':'' ?> onchange="showDbFields(this.value)" class="mt-1">
              <div>
                <div class="fw-semibold small">Osobna baza SQLite</div>
                <div class="text-muted" style="font-size:.75rem">Plik <code>.db</code> — prosta separacja danych</div>
              </div>
            </label>
          </div>
          <div class="col-md-4">
            <label class="card border d-flex align-items-start gap-2 p-3 <?= $cur['type']==='mysql'?'border-primary bg-primary-subtle':'' ?>" style="cursor:pointer;border-radius:8px">
              <input type="radio" name="crm_db_type" value="mysql" <?= $cur['type']==='mysql'?'checked':'' ?> onchange="showDbFields(this.value)" class="mt-1">
              <div>
                <div class="fw-semibold small">MySQL / MariaDB</div>
                <div class="text-muted" style="font-size:.75rem">Zewnętrzna baza relacyjna — dla dużych wolumenów</div>
              </div>
            </label>
          </div>
        </div>

        <!-- SQLite fields -->
        <div id="fields-sqlite" style="display:<?= $cur['type']==='sqlite'?'':'none' ?>">
          <div class="mb-3">
            <label class="form-label fw-semibold small">Ścieżka do pliku SQLite <span class="text-danger">*</span></label>
            <input type="text" name="crm_db_path" class="form-control font-monospace"
                   value="<?= h($cur['path']) ?>" placeholder="crm.db  lub  /var/data/crm.db">
            <div class="form-text">Ścieżka względna = od katalogu głównego aplikacji. Plik zostanie utworzony automatycznie przy pierwszym uruchomieniu.</div>
          </div>
        </div>

        <!-- MySQL fields -->
        <div id="fields-mysql" style="display:<?= $cur['type']==='mysql'?'':'none' ?>">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold small">Host</label>
              <input type="text" name="crm_db_host" class="form-control form-control-sm font-monospace"
                     value="<?= h($cur['host']) ?>" placeholder="127.0.0.1">
            </div>
            <div class="col-md-2">
              <label class="form-label fw-semibold small">Port</label>
              <input type="number" name="crm_db_port" class="form-control form-control-sm"
                     value="<?= h($cur['port']) ?>" placeholder="3306">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold small">Nazwa bazy <span class="text-danger">*</span></label>
              <input type="text" name="crm_db_name" class="form-control form-control-sm font-monospace"
                     value="<?= h($cur['name']) ?>" placeholder="crm_baza">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold small">Użytkownik <span class="text-danger">*</span></label>
              <input type="text" name="crm_db_user" class="form-control form-control-sm font-monospace"
                     value="<?= h($cur['user']) ?>" placeholder="crm_user">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold small">Hasło</label>
              <input type="password" name="crm_db_pass" class="form-control form-control-sm"
                     placeholder="Zostaw puste aby nie zmieniać">
              <div class="form-text">Przechowywane w tabeli settings (zaszyfruj bazę lub użyj env).</div>
            </div>
          </div>
        </div>

      </div>
    </div>

    <div class="alert alert-warning small py-2 px-3 mb-3">
      <i class="bi bi-exclamation-triangle me-1"></i>
      <strong>Uwaga:</strong> Zmiana bazy CRM nie migruje automatycznie danych. Dane w poprzedniej bazie pozostaną, nowa baza będzie pusta. Uruchom <code>crm_migrate()</code> ręcznie lub załaduj dowolną stronę CRM.
    </div>

    <div class="d-flex gap-2">
      <button type="submit" class="btn btn-warning">
        <i class="bi bi-save me-1"></i>Zapisz ustawienia
      </button>
      <a href="<?= APP_URL ?>/admin/index.php" class="btn btn-outline-secondary">Anuluj</a>
    </div>
  </form>

  <hr class="my-4">

  <div class="d-flex align-items-center gap-3 p-3 rounded" style="background:#f0fdf4;border:1px solid #bbf7d0">
    <i class="bi bi-journal-plus text-success" style="font-size:1.5rem"></i>
    <div>
      <div class="fw-semibold">Generowanie spraw CRM dla istniejących umów</div>
      <div class="small text-muted">Dla umów bez przypisanej sprawy CRM — generuje numery CASE{RRRR}/{NNN}.</div>
    </div>
    <a href="crm_backfill_cases.php" class="btn btn-outline-success btn-sm ms-auto text-nowrap">
      <i class="bi bi-arrow-right me-1"></i>Otwórz
    </a>
  </div>
</div>

<script>
function showDbFields(type) {
  document.getElementById('fields-sqlite').style.display = type === 'sqlite' ? '' : 'none';
  document.getElementById('fields-mysql').style.display  = type === 'mysql'  ? '' : 'none';
}
</script>
<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
