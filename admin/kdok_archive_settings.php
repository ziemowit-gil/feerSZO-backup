<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/ksiegowosc.php';
require_once dirname(__DIR__) . '/includes/kdok_archive.php';

require_role('admin');

$PAGE_TITLE   = 'eArchiwum — EOD Dokumentów Księgowych';
$success      = '';
$errors       = [];
$test_result  = null;

// ── Helper: save a single setting ────────────────────────────────────────────
function kdok_save(string $key, string $value): void {
    db()->prepare("INSERT OR REPLACE INTO settings (key_, value) VALUES (?,?)")
        ->execute([$key, $value]);
}

// ── POST handler ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['_action'] ?? 'save';

    // ── Test FTP ──────────────────────────────────────────────────────────────
    if ($action === 'test_ftp') {
        try {
            $result = kdok_archive_test_ftp();
            $test_result = ['section' => 'ftp', 'ok' => (bool)($result['ok'] ?? false), 'msg' => $result['msg'] ?? ''];
        } catch (\Throwable $e) {
            $test_result = ['section' => 'ftp', 'ok' => false, 'msg' => $e->getMessage()];
        }
    }

    // ── Test R2 ───────────────────────────────────────────────────────────────
    elseif ($action === 'test_r2') {
        try {
            $result = kdok_archive_test_r2();
            $test_result = ['section' => 'r2', 'ok' => (bool)($result['ok'] ?? false), 'msg' => $result['msg'] ?? ''];
        } catch (\Throwable $e) {
            $test_result = ['section' => 'r2', 'ok' => false, 'msg' => $e->getMessage()];
        }
    }

    // ── Save all settings ─────────────────────────────────────────────────────
    elseif ($action === 'save') {

        // Master switch
        kdok_save('kdok_archive_enabled', isset($_POST['kdok_archive_enabled']) ? '1' : '0');

        // ── FTP ──────────────────────────────────────────────────────────────
        kdok_save('kdok_ftp_enabled',  isset($_POST['kdok_ftp_enabled'])  ? '1' : '0');
        kdok_save('kdok_ftp_host',     trim($_POST['kdok_ftp_host']     ?? ''));
        kdok_save('kdok_ftp_port',     trim($_POST['kdok_ftp_port']     ?? '21') ?: '21');
        kdok_save('kdok_ftp_user',     trim($_POST['kdok_ftp_user']     ?? ''));
        kdok_save('kdok_ftp_path',     trim($_POST['kdok_ftp_path']     ?? '/kdok') ?: '/kdok');
        kdok_save('kdok_ftp_passive',  isset($_POST['kdok_ftp_passive'])  ? '1' : '0');

        // Password: only overwrite if non-empty
        $ftp_pass = $_POST['kdok_ftp_pass'] ?? '';
        if ($ftp_pass !== '') {
            kdok_save('kdok_ftp_pass', $ftp_pass);
        }

        // ── R2 ───────────────────────────────────────────────────────────────
        kdok_save('kdok_r2_enabled',     isset($_POST['kdok_r2_enabled']) ? '1' : '0');
        kdok_save('kdok_r2_account_id',  trim($_POST['kdok_r2_account_id']  ?? ''));
        kdok_save('kdok_r2_access_key',  trim($_POST['kdok_r2_access_key']  ?? ''));
        kdok_save('kdok_r2_bucket',      trim($_POST['kdok_r2_bucket']      ?? ''));
        kdok_save('kdok_r2_prefix',      trim($_POST['kdok_r2_prefix']      ?? 'kdok') ?: 'kdok');

        $r2_secret = $_POST['kdok_r2_secret_key'] ?? '';
        if ($r2_secret !== '') {
            kdok_save('kdok_r2_secret_key', $r2_secret);
        }

        // ── PIN ───────────────────────────────────────────────────────────────
        $pin = $_POST['kdok_przeglad_pin'] ?? '';
        if ($pin !== '') {
            kdok_save('kdok_przeglad_pin_hash', password_hash($pin, PASSWORD_BCRYPT));
        }

        flash_set('success', 'Ustawienia eArchiwum zapisane.');
        header('Location: ' . APP_URL . '/admin/kdok_archive_settings.php');
        exit;
    }
}

// ── Current values ────────────────────────────────────────────────────────────
$s = function(string $key): string { return org_setting($key); };

include dirname(__DIR__) . '/includes/header.php';
echo flash_html();
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0 fw-bold">
    <i class="bi bi-archive text-primary me-2"></i>eArchiwum — EOD Dokumentów Księgowych
  </h4>
  <a href="<?= APP_URL ?>/admin/" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-arrow-left me-1"></i>Panel admina
  </a>
</div>

<?php if ($test_result): ?>
  <?php if ($test_result['ok']): ?>
  <div class="alert alert-success alert-dismissible fade show" role="alert">
    <i class="bi bi-check-circle-fill me-2"></i>
    <strong>Test <?= $test_result['section'] === 'ftp' ? 'FTP' : 'R2' ?> zakończony sukcesem.</strong>
    <?php if (!empty($test_result['msg'])): ?>
      <?= htmlspecialchars((string)$test_result['msg'], ENT_QUOTES) ?>
    <?php endif; ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
  <?php else: ?>
  <div class="alert alert-danger alert-dismissible fade show" role="alert">
    <i class="bi bi-x-circle-fill me-2"></i>
    <strong>Błąd testu <?= $test_result['section'] === 'ftp' ? 'FTP' : 'R2' ?>:</strong>
    <?= htmlspecialchars((string)$test_result['msg'], ENT_QUOTES) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
  <?php endif; ?>
<?php endif; ?>

<form method="post">
  <?= csrf_field() ?>
  <input type="hidden" name="_action" value="save">

  <div class="row g-4">
    <div class="col-lg-8">

      <!-- ── Master switch ──────────────────────────────────────────────────── -->
      <div class="card border-0 shadow-sm mb-4">
        <div class="card-header fw-semibold">
          <i class="bi bi-toggle-on me-2 text-primary"></i>Moduł eArchiwum
        </div>
        <div class="card-body">
          <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" role="switch"
                   name="kdok_archive_enabled" id="kdok_archive_enabled" value="1"
                   <?= $s('kdok_archive_enabled') === '1' ? 'checked' : '' ?>>
            <label class="form-check-label fw-semibold" for="kdok_archive_enabled">
              Włącz eArchiwum dokumentów księgowych
            </label>
          </div>
          <div class="form-text">
            Gdy wyłączony — automatyczne archiwizowanie dokumentów nie działa, a przeglądarka archiwum jest niedostępna.
          </div>
        </div>
      </div>

      <!-- ── FTP ───────────────────────────────────────────────────────────── -->
      <div class="card border-0 shadow-sm mb-4">
        <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
          <span><i class="bi bi-hdd-network me-2 text-primary"></i>Archiwizacja FTP</span>
          <div class="form-check form-switch mb-0">
            <input class="form-check-input" type="checkbox" role="switch"
                   name="kdok_ftp_enabled" id="kdok_ftp_enabled" value="1"
                   <?= $s('kdok_ftp_enabled') === '1' ? 'checked' : '' ?>>
            <label class="form-check-label small" for="kdok_ftp_enabled">Włącz FTP</label>
          </div>
        </div>
        <div class="card-body">
          <div class="row g-3">

            <div class="col-md-8">
              <label class="form-label fw-semibold small">Host FTP</label>
              <input name="kdok_ftp_host" class="form-control font-monospace"
                     value="<?= htmlspecialchars($s('kdok_ftp_host'), ENT_QUOTES) ?>"
                     placeholder="np. ftp.example.com">
            </div>

            <div class="col-md-4">
              <label class="form-label fw-semibold small">Port</label>
              <input name="kdok_ftp_port" type="number" min="1" max="65535"
                     class="form-control font-monospace"
                     value="<?= htmlspecialchars($s('kdok_ftp_port') ?: '21', ENT_QUOTES) ?>"
                     placeholder="21">
            </div>

            <div class="col-md-6">
              <label class="form-label fw-semibold small">Użytkownik</label>
              <input name="kdok_ftp_user" class="form-control font-monospace"
                     value="<?= htmlspecialchars($s('kdok_ftp_user'), ENT_QUOTES) ?>"
                     placeholder="login FTP" autocomplete="off">
            </div>

            <div class="col-md-6">
              <label class="form-label fw-semibold small">Hasło</label>
              <div class="input-group">
                <input name="kdok_ftp_pass" type="password" id="ftpPassField"
                       class="form-control font-monospace"
                       placeholder="<?= $s('kdok_ftp_pass') !== '' ? '••••••••' : 'hasło FTP' ?>"
                       autocomplete="new-password">
                <button type="button" class="btn btn-outline-secondary"
                        onclick="var f=document.getElementById('ftpPassField');f.type=f.type==='password'?'text':'password'">
                  <i class="bi bi-eye"></i>
                </button>
              </div>
              <div class="form-text">Zostaw puste, aby zachować aktualne hasło.</div>
            </div>

            <div class="col-md-8">
              <label class="form-label fw-semibold small">Ścieżka docelowa</label>
              <input name="kdok_ftp_path" class="form-control font-monospace"
                     value="<?= htmlspecialchars($s('kdok_ftp_path') ?: '/kdok', ENT_QUOTES) ?>"
                     placeholder="/kdok">
            </div>

            <div class="col-md-4 d-flex align-items-end pb-1">
              <div class="form-check">
                <input class="form-check-input" type="checkbox"
                       name="kdok_ftp_passive" id="kdok_ftp_passive" value="1"
                       <?= ($s('kdok_ftp_passive') === '' || $s('kdok_ftp_passive') === '1') ? 'checked' : '' ?>>
                <label class="form-check-label fw-semibold small" for="kdok_ftp_passive">
                  Tryb pasywny
                </label>
              </div>
            </div>

          </div><!-- /row -->
        </div><!-- /card-body -->
        <div class="card-footer bg-transparent border-top-0 pt-0">
          <button type="submit" name="_action" value="test_ftp"
                  class="btn btn-outline-primary btn-sm">
            <i class="bi bi-wifi me-1"></i>Testuj połączenie FTP
          </button>
        </div>
      </div>

      <!-- ── Cloudflare R2 ──────────────────────────────────────────────────── -->
      <div class="card border-0 shadow-sm mb-4">
        <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
          <span><i class="bi bi-cloud-arrow-up me-2 text-primary"></i>Archiwizacja Cloudflare R2</span>
          <div class="form-check form-switch mb-0">
            <input class="form-check-input" type="checkbox" role="switch"
                   name="kdok_r2_enabled" id="kdok_r2_enabled" value="1"
                   <?= $s('kdok_r2_enabled') === '1' ? 'checked' : '' ?>>
            <label class="form-check-label small" for="kdok_r2_enabled">Włącz R2</label>
          </div>
        </div>
        <div class="card-body">
          <div class="row g-3">

            <div class="col-12">
              <label class="form-label fw-semibold small">Account ID</label>
              <input name="kdok_r2_account_id" class="form-control font-monospace"
                     value="<?= htmlspecialchars($s('kdok_r2_account_id'), ENT_QUOTES) ?>"
                     placeholder="np. abc123def456..." autocomplete="off">
              <div class="form-text">
                Widoczny w panelu Cloudflare → R2 → Overview.
              </div>
            </div>

            <div class="col-md-6">
              <label class="form-label fw-semibold small">Access Key ID</label>
              <input name="kdok_r2_access_key" class="form-control font-monospace"
                     value="<?= htmlspecialchars($s('kdok_r2_access_key'), ENT_QUOTES) ?>"
                     placeholder="klucz dostępu R2" autocomplete="off">
            </div>

            <div class="col-md-6">
              <label class="form-label fw-semibold small">Secret Access Key</label>
              <div class="input-group">
                <input name="kdok_r2_secret_key" type="password" id="r2SecretField"
                       class="form-control font-monospace"
                       placeholder="<?= $s('kdok_r2_secret_key') !== '' ? '••••••••' : 'tajny klucz R2' ?>"
                       autocomplete="new-password">
                <button type="button" class="btn btn-outline-secondary"
                        onclick="var f=document.getElementById('r2SecretField');f.type=f.type==='password'?'text':'password'">
                  <i class="bi bi-eye"></i>
                </button>
              </div>
              <div class="form-text">Zostaw puste, aby zachować aktualny klucz.</div>
            </div>

            <div class="col-md-8">
              <label class="form-label fw-semibold small">Bucket</label>
              <input name="kdok_r2_bucket" class="form-control font-monospace"
                     value="<?= htmlspecialchars($s('kdok_r2_bucket'), ENT_QUOTES) ?>"
                     placeholder="np. feer-kdok">
            </div>

            <div class="col-md-4">
              <label class="form-label fw-semibold small">Prefix (katalog)</label>
              <input name="kdok_r2_prefix" class="form-control font-monospace"
                     value="<?= htmlspecialchars($s('kdok_r2_prefix') ?: 'kdok', ENT_QUOTES) ?>"
                     placeholder="kdok">
            </div>

          </div><!-- /row -->
        </div><!-- /card-body -->
        <div class="card-footer bg-transparent border-top-0 pt-0">
          <button type="submit" name="_action" value="test_r2"
                  class="btn btn-outline-primary btn-sm">
            <i class="bi bi-cloud me-1"></i>Testuj połączenie R2
          </button>
        </div>
      </div>

    </div><!-- /col-lg-8 -->

    <div class="col-lg-4">

      <!-- ── PIN przeglądarki archiwum ──────────────────────────────────────── -->
      <div class="card border-0 shadow-sm mb-4">
        <div class="card-header fw-semibold">
          <i class="bi bi-shield-lock me-2 text-primary"></i>PIN przeglądarki archiwum
        </div>
        <div class="card-body">
          <label class="form-label fw-semibold small">Nowy PIN</label>
          <div class="input-group">
            <input name="kdok_przeglad_pin" type="password" id="pinField"
                   class="form-control font-monospace"
                   placeholder="<?= $s('kdok_przeglad_pin_hash') !== '' ? '•••• (ustawiony)' : 'ustaw PIN' ?>"
                   autocomplete="new-password">
            <button type="button" class="btn btn-outline-secondary"
                    onclick="var f=document.getElementById('pinField');f.type=f.type==='password'?'text':'password'">
              <i class="bi bi-eye"></i>
            </button>
          </div>
          <div class="form-text">
            PIN jest przechowywany jako hash bcrypt.
            Zostaw puste, aby zachować aktualny PIN.
            <?php if ($s('kdok_przeglad_pin_hash') !== ''): ?>
              <span class="text-success"><i class="bi bi-check-circle me-1"></i>PIN jest aktualnie ustawiony.</span>
            <?php else: ?>
              <span class="text-warning"><i class="bi bi-exclamation-triangle me-1"></i>PIN nie jest jeszcze ustawiony.</span>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- ── Zapisz ─────────────────────────────────────────────────────────── -->
      <div class="d-grid gap-2">
        <button type="submit" name="_action" value="save" class="btn btn-primary">
          <i class="bi bi-floppy me-1"></i>Zapisz ustawienia
        </button>
        <a href="<?= APP_URL ?>/admin/" class="btn btn-outline-secondary">
          <i class="bi bi-arrow-left me-1"></i>Wróć do panelu
        </a>
      </div>

      <!-- ── Info ───────────────────────────────────────────────────────────── -->
      <div class="card border-0 shadow-sm mt-4">
        <div class="card-header fw-semibold small">
          <i class="bi bi-info-circle me-2 text-secondary"></i>Informacje
        </div>
        <div class="card-body small text-muted">
          <ul class="list-unstyled mb-0">
            <li class="mb-1"><i class="bi bi-dot"></i>FTP i R2 mogą być włączone jednocześnie — dokumenty trafią do obu miejsc.</li>
            <li class="mb-1"><i class="bi bi-dot"></i>Ścieżka FTP jest względem katalogu domowego użytkownika FTP.</li>
            <li class="mb-1"><i class="bi bi-dot"></i>Prefix R2 to katalog wewnątrz bucketu (bez wiodącego slasha).</li>
            <li><i class="bi bi-dot"></i>PIN zabezpiecza dostęp do przeglądarki archiwum bez logowania do systemu.</li>
          </ul>
        </div>
      </div>

    </div><!-- /col-lg-4 -->
  </div><!-- /row -->
</form>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
