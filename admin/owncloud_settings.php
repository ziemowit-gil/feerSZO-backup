<?php
/**
 * admin/owncloud_settings.php — Konfiguracja magazynu plików ownCloud
 * (materiały TI i załączniki zadań domowych).
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/owncloud.php';

require_role('admin');
owncloud_migrate();
$PAGE_TITLE = 'Magazyn plików / ownCloud';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['_action'] ?? 'save';

    if ($action === 'save') {
        owncloud_save_setting('enabled',     !empty($_POST['enabled']) ? '1' : '0');
        owncloud_save_setting('url',         rtrim(trim($_POST['url'] ?? ''), '/'));
        owncloud_save_setting('username',    trim($_POST['username'] ?? ''));
        owncloud_save_setting('base_folder', trim($_POST['base_folder'] ?? '') ?: 'feerszo-pliki-lekcji');
        // Hasło — nie nadpisuj pustym (zostaw dotychczasowe)
        if (trim($_POST['password'] ?? '') !== '') owncloud_save_setting('password', trim($_POST['password']));

        owncloud_save_setting('admin_username', trim($_POST['admin_username'] ?? ''));
        if (trim($_POST['admin_password'] ?? '') !== '') owncloud_save_setting('admin_password', trim($_POST['admin_password']));
        owncloud_save_setting('student_quota_mb', (string)max(1, (int)($_POST['student_quota_mb'] ?? 2048)));
        owncloud_save_setting('instructor_quota_mb', (string)max(1, (int)($_POST['instructor_quota_mb'] ?? 5120)));

        flash_set('success', 'Ustawienia ownCloud zapisane.');
        header('Location: owncloud_settings.php'); exit;
    }

    if ($action === 'test') {
        $cfg = [
            'url'         => rtrim(trim($_POST['url'] ?? ''), '/'),
            'username'    => trim($_POST['username'] ?? ''),
            'password'    => trim($_POST['password'] ?? '') !== '' ? trim($_POST['password']) : owncloud_setting('password'),
            'base_folder' => trim($_POST['base_folder'] ?? '') ?: 'feerszo-pliki-lekcji',
        ];
        $r = owncloud_test_connection($cfg);
        flash_set($r['ok'] ? 'success' : 'danger', $r['msg']);
        header('Location: owncloud_settings.php'); exit;
    }
}

$cfg = [
    'enabled'           => owncloud_setting('enabled'),
    'url'               => owncloud_setting('url'),
    'username'          => owncloud_setting('username'),
    'base_folder'       => owncloud_setting('base_folder', 'feerszo-pliki-lekcji'),
    'has_password'      => owncloud_setting('password') !== '',
    'admin_username'      => owncloud_setting('admin_username'),
    'has_admin_password'  => owncloud_setting('admin_password') !== '',
    'student_quota_mb'    => owncloud_setting('student_quota_mb', '2048'),
    'instructor_quota_mb' => owncloud_setting('instructor_quota_mb', '5120'),
];

include dirname(__DIR__) . '/includes/header.php';
?>

<div class="d-flex align-items-center mb-3 gap-2 flex-wrap">
  <h4 class="mb-0 fw-bold"><i class="bi bi-cloud-arrow-up text-primary me-2"></i>Magazyn plików / ownCloud</h4>
  <span class="badge <?= owncloud_enabled() ? 'bg-success' : 'bg-warning text-dark' ?>">
    <?= owncloud_enabled() ? 'Aktywne' : (owncloud_configured() ? 'Skonfigurowane (wyłączone)' : 'Wymaga konfiguracji') ?>
  </span>
</div>

<?= flash_html() ?>

<div class="row g-4">
  <div class="col-lg-7">
    <div class="card border-0 shadow-sm">
      <div class="card-header fw-semibold"><i class="bi bi-gear me-2"></i>Konfiguracja ownCloud</div>
      <div class="card-body">
        <form method="post" id="oc_form">
          <input type="hidden" name="_csrf"   value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="_action" value="save">

          <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" name="enabled" id="oc_en" <?= $cfg['enabled']==='1'?'checked':'' ?>>
            <label class="form-check-label fw-semibold" for="oc_en">Używaj ownCloud jako magazynu plików lekcji</label>
          </div>

          <div class="mb-3">
            <label class="form-label fw-semibold" for="oc_url">Adres ownCloud (URL) <span class="text-danger">*</span></label>
            <input type="url" class="form-control font-monospace" name="url" id="oc_url"
                   value="<?= h($cfg['url']) ?>" placeholder="https://owncloud.przyklad.pl">
            <div class="form-text">Adres bazowy instancji, bez ścieżki (np. bez <code>/remote.php/...</code>).</div>
          </div>

          <div class="mb-3">
            <label class="form-label fw-semibold" for="oc_user">Login <span class="text-danger">*</span></label>
            <input type="text" class="form-control font-monospace" name="username" id="oc_user"
                   value="<?= h($cfg['username']) ?>" placeholder="np. feerszo-integracja">
          </div>

          <div class="mb-3">
            <label class="form-label fw-semibold" for="oc_pass">Hasło (lub hasło aplikacji) <span class="text-danger">*</span></label>
            <input type="password" class="form-control font-monospace" name="password" id="oc_pass"
                   placeholder="<?= $cfg['has_password'] ? '(zapisane — zostaw puste by nie zmieniać)' : 'hasło konta lub app password' ?>">
            <div class="form-text">Zalecane: dedykowane konto integracyjne w ownCloud + hasło aplikacji (Ustawienia → Bezpieczeństwo).</div>
          </div>

          <div class="mb-3">
            <label class="form-label fw-semibold" for="oc_folder">Katalog bazowy</label>
            <input type="text" class="form-control font-monospace" name="base_folder" id="oc_folder"
                   value="<?= h($cfg['base_folder']) ?>" placeholder="feerszo-pliki-lekcji">
            <div class="form-text">Tworzony automatycznie na koncie powyżej — tam trafiają materiały i załączniki zadań domowych.</div>
          </div>

          <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary">Zapisz</button>
            <button type="button" class="btn btn-outline-secondary" onclick="ocTest()">
              <i class="bi bi-wifi me-1"></i>Testuj połączenie
            </button>
          </div>
        </form>
      </div>
    </div>

    <div class="card border-0 shadow-sm mt-4">
      <div class="card-header fw-semibold"><i class="bi bi-hdd-network me-2"></i>Samoobsługowe konta „Mój dysk" (kursant / prowadzący)</div>
      <div class="card-body">
        <p class="text-muted small">Kursant i prowadzący mogą samodzielnie utworzyć własne konto ownCloud z limitem
        miejsca (zakładka „Dysk"/„Mój dysk" w panelu kursanta i panelu dydaktyka). Wymaga to konta
        <strong>administratora</strong> ownCloud — innego niż konto integracyjne WebDAV powyżej (to jest konto
        bootstrapowe kontenera, ustawione przy wdrożeniu —
        <code>OWNCLOUD_ADMIN_USERNAME</code>/<code>OWNCLOUD_ADMIN_PASSWORD</code> w <code>.env.prod</code>).</p>
        <form method="post">
          <input type="hidden" name="_csrf"   value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="_action" value="save">
          <input type="hidden" name="enabled"     value="<?= $cfg['enabled'] ?>">
          <input type="hidden" name="url"         value="<?= h($cfg['url']) ?>">
          <input type="hidden" name="username"    value="<?= h($cfg['username']) ?>">
          <input type="hidden" name="base_folder" value="<?= h($cfg['base_folder']) ?>">

          <div class="mb-3">
            <label class="form-label fw-semibold" for="oc_admin_user">Login administratora ownCloud</label>
            <input type="text" class="form-control font-monospace" name="admin_username" id="oc_admin_user"
                   value="<?= h($cfg['admin_username']) ?>" placeholder="admin">
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold" for="oc_admin_pass">Hasło administratora</label>
            <input type="password" class="form-control font-monospace" name="admin_password" id="oc_admin_pass"
                   placeholder="<?= $cfg['has_admin_password'] ? '(zapisane — zostaw puste by nie zmieniać)' : 'hasło konta administratora' ?>">
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold" for="oc_quota">Limit miejsca dla nowych kont kursantów (MB)</label>
            <input type="number" min="1" class="form-control" name="student_quota_mb" id="oc_quota"
                   value="<?= h($cfg['student_quota_mb']) ?>">
            <div class="form-text">2048 MB = 2 GB. Dotyczy tylko nowo tworzonych kont — nie zmienia limitu już istniejących.</div>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold" for="oc_instr_quota">Limit miejsca dla nowych kont prowadzących (MB)</label>
            <input type="number" min="1" class="form-control" name="instructor_quota_mb" id="oc_instr_quota"
                   value="<?= h($cfg['instructor_quota_mb']) ?>">
            <div class="form-text">5120 MB = 5 GB. Dotyczy tylko nowo tworzonych kont — nie zmienia limitu już istniejących.</div>
          </div>
          <button type="submit" class="btn btn-primary">Zapisz</button>
        </form>
      </div>
    </div>

        <form id="oc_test" method="post">
          <input type="hidden" name="_csrf"   value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="_action" value="test">
          <input type="hidden" name="url" id="oc_test_url">
          <input type="hidden" name="username" id="oc_test_username">
          <input type="hidden" name="password" id="oc_test_password">
          <input type="hidden" name="base_folder" id="oc_test_base_folder">
        </form>
        <script>
        function ocTest() {
          document.getElementById('oc_test_url').value         = document.getElementById('oc_url').value;
          document.getElementById('oc_test_username').value    = document.getElementById('oc_user').value;
          document.getElementById('oc_test_password').value    = document.getElementById('oc_pass').value;
          document.getElementById('oc_test_base_folder').value = document.getElementById('oc_folder').value;
          document.getElementById('oc_test').submit();
        }
        </script>
  </div>

  <div class="col-lg-5">
    <div class="card border-0 shadow-sm">
      <div class="card-header fw-semibold"><i class="bi bi-info-circle me-2"></i>O integracji</div>
      <div class="card-body small text-muted">
        <p>Po włączeniu, nowe pliki dodawane do <strong>Materiałów</strong> i <strong>Zadań domowych</strong>
        w panelu dydaktyka trafiają na ownCloud (przez WebDAV) zamiast na dysk lokalny serwera.</p>
        <p class="mb-0">Pliki wgrane wcześniej, przed włączeniem integracji, pozostają dostępne — nadal są
        serwowane z dysku lokalnego. Nic nie trzeba migrować ręcznie.</p>
      </div>
    </div>
  </div>
</div>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
