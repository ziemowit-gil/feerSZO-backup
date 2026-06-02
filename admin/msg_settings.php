<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

require_role('admin');

$PAGE_TITLE = 'Ustawienia wiadomości';

// ── POST handler ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $notify_admin = isset($_POST['notify_admin']) ? '1' : '0';
    $notify_user  = isset($_POST['notify_user'])  ? '1' : '0';

    foreach ([
        'msg_notify_admin_on_question' => $notify_admin,
        'msg_notify_user_on_reply'     => $notify_user,
    ] as $key => $val) {
        try {
            db()->prepare(
                "INSERT INTO settings (key_, value) VALUES (?, ?)
                 ON CONFLICT(key_) DO UPDATE SET value=excluded.value"
            )->execute([$key, $val]);
        } catch (\Throwable $e) {
            // Fallback — próbuj bez ON CONFLICT (starszy SQLite)
            try {
                $exists = db_one("SELECT key_ FROM settings WHERE key_=?", [$key]);
                if ($exists) {
                    db()->prepare("UPDATE settings SET value=? WHERE key_=?")->execute([$val, $key]);
                } else {
                    db_insert('settings', ['key_' => $key, 'value' => $val]);
                }
            } catch (\Throwable $e2) {}
        }
    }

    flash_set('success', 'Ustawienia zostały zapisane.');
    header('Location: msg_settings.php');
    exit;
}

// ── Odczyt bieżących ustawień ─────────────────────────────────────────────────
$notify_admin = org_setting('msg_notify_admin_on_question');
$notify_user  = org_setting('msg_notify_user_on_reply');
// Domyślnie włączone gdy ustawienia brak
$notify_admin_checked = ($notify_admin !== '0');
$notify_user_checked  = ($notify_user  !== '0');

include dirname(__DIR__) . '/includes/header.php';
?>

<div class="d-flex align-items-center mb-3 gap-2">
  <h4 class="mb-0"><i class="bi bi-bell text-primary"></i> Ustawienia powiadomień email (wiadomości)</h4>
  <a href="message_types.php" class="btn btn-sm btn-outline-secondary ms-auto">
    <i class="bi bi-tags"></i> Typy wiadomości
  </a>
</div>

<?= flash_html() ?>

<div class="card shadow-sm mb-4" style="max-width:640px">
  <div class="card-body">
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">

      <h6 class="text-muted fw-semibold mb-3 text-uppercase" style="font-size:.75rem;letter-spacing:.08em">
        Powiadomienia email
      </h6>

      <!-- Powiadomienie dla admina/opiekuna -->
      <div class="mb-4 p-3 border rounded">
        <div class="form-check form-switch">
          <input class="form-check-input" type="checkbox" role="switch"
                 id="notify_admin" name="notify_admin"
                 <?= $notify_admin_checked ? 'checked' : '' ?>>
          <label class="form-check-label fw-semibold" for="notify_admin">
            Powiadom opiekuna / administratora
          </label>
        </div>
        <div class="text-muted small mt-2 ms-4 ps-2">
          Gdy użytkownik (wolontariusz, pracownik, zleceniobiorca itp.) wyśle nową wiadomość,
          system automatycznie prześle powiadomienie email do przypisanego opiekuna umowy.
          Jeśli opiekun nie jest przypisany, mail trafi do wszystkich administratorów.
        </div>
      </div>

      <!-- Powiadomienie dla użytkownika -->
      <div class="mb-4 p-3 border rounded">
        <div class="form-check form-switch">
          <input class="form-check-input" type="checkbox" role="switch"
                 id="notify_user" name="notify_user"
                 <?= $notify_user_checked ? 'checked' : '' ?>>
          <label class="form-check-label fw-semibold" for="notify_user">
            Powiadom użytkownika o odpowiedzi
          </label>
        </div>
        <div class="text-muted small mt-2 ms-4 ps-2">
          Gdy administrator odpowie na wiadomość, użytkownik otrzyma powiadomienie email
          na adres przypisany do jego umowy.
        </div>
      </div>

      <div class="alert alert-info small d-flex align-items-start gap-2">
        <i class="bi bi-info-circle-fill flex-shrink-0 mt-1"></i>
        <div>
          Powiadomienia są wysyłane przez skonfigurowany kanał email (Microsoft 365 Graph API lub PHP mail()).
          Upewnij się, że konfiguracja poczty wychodzącej jest poprawna w
          <a href="m365.php">ustawieniach Microsoft 365</a>.
        </div>
      </div>

      <button type="submit" class="btn btn-primary">
        <i class="bi bi-check-lg me-1"></i> Zapisz ustawienia
      </button>
    </form>
  </div>
</div>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
