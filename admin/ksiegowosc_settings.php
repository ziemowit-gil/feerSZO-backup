<?php
require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ksiegowosc.php';

require_login();
if (!is_admin()) { http_response_code(403); die('Brak uprawnień.'); }

$test_result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $section = $_POST['section'] ?? '';

    if ($section === 'db') {
        $keys = [
            'kdok_db_type'        => $_POST['kdok_db_type']        ?? 'main',
            'kdok_db_sqlite_path' => trim($_POST['kdok_db_sqlite_path'] ?? ''),
            'kdok_db_mysql_host'  => trim($_POST['kdok_db_mysql_host']  ?? 'localhost'),
            'kdok_db_mysql_port'  => trim($_POST['kdok_db_mysql_port']  ?? '3306'),
            'kdok_db_mysql_name'  => trim($_POST['kdok_db_mysql_name']  ?? ''),
            'kdok_db_mysql_user'  => trim($_POST['kdok_db_mysql_user']  ?? ''),
        ];
        // Hasło: zachowaj stare jeśli pole puste
        $new_pass = $_POST['kdok_db_mysql_pass'] ?? '';
        if ($new_pass !== '') $keys['kdok_db_mysql_pass'] = $new_pass;

        foreach ($keys as $key => $val) {
            $exists = db_one("SELECT 1 FROM settings WHERE key_=?", [$key]);
            if ($exists) db()->prepare("UPDATE settings SET value=? WHERE key_=?")->execute([$val, $key]);
            else         db()->prepare("INSERT INTO settings (key_,value) VALUES (?,?)")->execute([$key, $val]);
        }

        // Wyczyść statyczny cache org_setting przez redirect + test
        flash_set('success', 'Ustawienia bazy danych zapisane.');
        header('Location: ' . APP_URL . '/admin/ksiegowosc_settings.php?test=1');
        exit;
    }

    if ($section === 'mpk') {
        $mpk_enabled = isset($_POST['kdok_mpk_enabled']) ? '1' : '0';
        $lines = array_unique(array_filter(array_map('trim', explode("\n", $_POST['kdok_mpk_list'] ?? ''))));
        foreach ([
            'kdok_mpk_enabled' => $mpk_enabled,
            'kdok_mpk_list'    => implode("\n", $lines),
        ] as $key => $val) {
            $exists = db_one("SELECT 1 FROM settings WHERE key_=?", [$key]);
            if ($exists) db()->prepare("UPDATE settings SET value=? WHERE key_=?")->execute([$val, $key]);
            else         db()->prepare("INSERT INTO settings (key_,value) VALUES (?,?)")->execute([$key, $val]);
        }
        flash_set('success', 'Ustawienia MPK zapisane.');
        header('Location: ' . APP_URL . '/admin/ksiegowosc_settings.php');
        exit;
    }
}

// Test połączenia po redirect
if (isset($_GET['test'])) {
    kdok_migrate();
    $test_result = kdok_db_test();
}

kdok_migrate();

// Odczyt aktualnych ustawień
$cfg = [];
foreach ([
    'kdok_db_type', 'kdok_db_sqlite_path',
    'kdok_db_mysql_host', 'kdok_db_mysql_port', 'kdok_db_mysql_name', 'kdok_db_mysql_user',
    'kdok_mpk_enabled', 'kdok_mpk_list',
] as $k) {
    $cfg[$k] = org_setting($k);
}

$PAGE_TITLE = 'Ustawienia — Dokumenty Księgowe';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex align-items-center mb-3 gap-2">
  <a href="<?= APP_URL ?>/admin/" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
  <h4 class="mb-0"><i class="bi bi-gear"></i> Ustawienia — Dokumenty Księgowe</h4>
</div>
<?= flash_html() ?>

<?php if ($test_result !== null): ?>
<div class="alert alert-<?= $test_result['ok'] ? 'success' : 'danger' ?> d-flex gap-2 align-items-center">
  <i class="bi bi-<?= $test_result['ok'] ? 'check-circle-fill' : 'x-circle-fill' ?> fs-5"></i>
  <span><strong>Test połączenia:</strong> <?= h($test_result['msg']) ?></span>
</div>
<?php endif; ?>

<!-- Baza danych -->
<div class="card shadow-sm mb-4" style="max-width:640px">
  <div class="card-header py-2 fw-semibold">
    <i class="bi bi-database"></i> Baza danych modułu
  </div>
  <div class="card-body">
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="section" value="db">

      <div class="mb-3">
        <label class="form-label fw-semibold">Typ bazy</label>
        <div class="d-flex gap-3">
          <?php foreach (['main' => 'Główna baza aplikacji', 'sqlite' => 'Osobny plik SQLite', 'mysql' => 'Osobna baza MySQL'] as $v => $lbl): ?>
          <div class="form-check">
            <input class="form-check-input db-type-radio" type="radio" name="kdok_db_type"
              id="db_<?= $v ?>" value="<?= $v ?>" <?= $cfg['kdok_db_type'] === $v ? 'checked' : '' ?>>
            <label class="form-check-label" for="db_<?= $v ?>"><?= h($lbl) ?></label>
          </div>
          <?php endforeach; ?>
        </div>
        <div class="form-text">
          <strong>Główna</strong> — wszystko w jednym miejscu.
          <strong>Osobna</strong> — dokumenty księgowe w izolowanej bazie, np. dla innych uprawnień backupu.
        </div>
      </div>

      <!-- SQLite -->
      <div id="section_sqlite" style="display:none">
        <div class="mb-3">
          <label class="form-label fw-semibold">Ścieżka do pliku SQLite</label>
          <input type="text" name="kdok_db_sqlite_path" class="form-control font-monospace"
            value="<?= h($cfg['kdok_db_sqlite_path']) ?>"
            placeholder="<?= h(dirname(__DIR__) . '/kdok.db') ?>">
          <div class="form-text">Ścieżka bezwzględna. Katalog musi istnieć i być zapisywalny przez serwer WWW.
            Zalecane: poza katalogiem <code>public_html</code>.</div>
        </div>
      </div>

      <!-- MySQL -->
      <div id="section_mysql" style="display:none">
        <div class="row g-3 mb-3">
          <div class="col-sm-8">
            <label class="form-label fw-semibold">Host</label>
            <input type="text" name="kdok_db_mysql_host" class="form-control"
              value="<?= h($cfg['kdok_db_mysql_host'] ?: 'localhost') ?>">
          </div>
          <div class="col-sm-4">
            <label class="form-label fw-semibold">Port</label>
            <input type="number" name="kdok_db_mysql_port" class="form-control"
              value="<?= h($cfg['kdok_db_mysql_port'] ?: '3306') ?>">
          </div>
        </div>
        <div class="row g-3 mb-3">
          <div class="col-sm-6">
            <label class="form-label fw-semibold">Nazwa bazy</label>
            <input type="text" name="kdok_db_mysql_name" class="form-control"
              value="<?= h($cfg['kdok_db_mysql_name']) ?>" placeholder="np. kdok_prod">
          </div>
          <div class="col-sm-6">
            <label class="form-label fw-semibold">Użytkownik</label>
            <input type="text" name="kdok_db_mysql_user" class="form-control"
              value="<?= h($cfg['kdok_db_mysql_user']) ?>" autocomplete="off">
          </div>
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold">Hasło</label>
          <input type="password" name="kdok_db_mysql_pass" class="form-control"
            placeholder="Pozostaw puste, by nie zmieniać" autocomplete="new-password">
        </div>
      </div>

      <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Zapisz i przetestuj</button>
        <a href="?test=1" class="btn btn-outline-secondary"><i class="bi bi-plug"></i> Tylko test połączenia</a>
      </div>
    </form>
  </div>
</div>

<!-- MPK -->
<div class="card shadow-sm" style="max-width:640px">
  <div class="card-header py-2 fw-semibold"><i class="bi bi-diagram-3"></i> MPK / Centra kosztów</div>
  <div class="card-body">
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="section" value="mpk">
      <div class="form-check form-switch mb-3">
        <input class="form-check-input" type="checkbox" id="mpk_enabled" name="kdok_mpk_enabled"
          <?= $cfg['kdok_mpk_enabled'] === '1' ? 'checked' : '' ?>>
        <label class="form-check-label fw-semibold" for="mpk_enabled">
          Włącz pole MPK w dokumentach księgowych
        </label>
      </div>
      <div id="mpk_list_section" <?= $cfg['kdok_mpk_enabled'] !== '1' ? 'style="display:none"' : '' ?>>
        <label class="form-label fw-semibold">Lista MPK (jedna wartość w linii)</label>
        <textarea name="kdok_mpk_list" class="form-control font-monospace" rows="8"
          placeholder="101 – Zarząd&#10;201 – Projekty&#10;301 – Marketing"><?= h($cfg['kdok_mpk_list']) ?></textarea>
        <div class="form-text">Pusta lista = pole tekstowe. Wypełniona = select rozwijany.</div>
      </div>
      <div class="mt-3">
        <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Zapisz ustawienia MPK</button>
      </div>
    </form>
  </div>
</div>

<script>
(function () {
  function updateDb() {
    var v = document.querySelector('.db-type-radio:checked')?.value || 'main';
    document.getElementById('section_sqlite').style.display = (v === 'sqlite') ? '' : 'none';
    document.getElementById('section_mysql').style.display  = (v === 'mysql')  ? '' : 'none';
  }
  document.querySelectorAll('.db-type-radio').forEach(r => r.addEventListener('change', updateDb));
  updateDb();

  document.getElementById('mpk_enabled').addEventListener('change', function () {
    document.getElementById('mpk_list_section').style.display = this.checked ? '' : 'none';
  });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
