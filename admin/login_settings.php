<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

require_role('admin');
if (!defined('TZ_ADMIN_CHROME')) {
    header('Location: ' . APP_URL . '/tozsamosc/ustawienia.php');
    exit;
}
$PAGE_TITLE = 'Metody logowania';

// ── Inicjalizacja tabel ────────────────────────────────────────────────────
try { db()->exec("ALTER TABLE users ADD COLUMN login_code TEXT DEFAULT NULL"); } catch (\Throwable $e) {}
try { db()->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_users_login_code ON users(login_code) WHERE login_code IS NOT NULL"); } catch (\Throwable $e) {}

// ── Klucze ustawień ────────────────────────────────────────────────────────
$method_keys = [
    'login_method_local'  => 1,   // zawsze włączone (domyślnie)
    'login_method_code'   => 1,
    'login_method_sms'    => 0,
    'login_method_ms365'  => 0,
    'login_method_x509'   => 1,   // certyfikat X.509 — domyślnie włączone gdy są aktywne certy
];

function _lm_get(string $key): bool {
    $r = db_one("SELECT value FROM settings WHERE key_=?", [$key]);
    if ($r === null) return (bool)($GLOBALS['method_keys'][$key] ?? 0);
    return (bool)$r['value'];
}
function _lm_set(string $key, bool $val): void {
    db()->prepare("INSERT INTO settings (key_, value) VALUES (?,?) ON CONFLICT(key_) DO UPDATE SET value=excluded.value")
        ->execute([$key, $val ? '1' : '0']);
}

// ── Sprawdź dostępność SMS i M365 ─────────────────────────────────────────
$sms_configured = false;
try {
    require_once dirname(__DIR__) . '/includes/sms.php';
    $sms_configured = sms_is_enabled();
} catch (\Throwable $e) {}

$ms_configured = ms_login_available();

// ── POST handlers ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['_action'] ?? '';

    // Zapis metod
    if ($action === 'save_methods') {
        foreach (array_keys($method_keys) as $k) {
            _lm_set($k, isset($_POST[$k]));
        }
        // local zawsze włączone
        _lm_set('login_method_local', true);
        flash_set('success', 'Ustawienia metod logowania zapisane.');
        header('Location: ' . APP_URL . '/admin/login_settings.php'); exit;
    }

    // Generuj kod jednorazowy dla użytkownika
    if ($action === 'gen_code') {
        $uid = (int)($_POST['user_id'] ?? 0);
        if ($uid) {
            // 10-znakowy alfanumeryczny kod
            $code = strtoupper(substr(bin2hex(random_bytes(8)), 0, 10));
            db()->prepare("UPDATE users SET login_code=? WHERE id=?")->execute([$code, $uid]);
            flash_set('success', "Wygenerowano kod dostępu dla użytkownika.");
        }
        header('Location: ' . APP_URL . '/admin/login_settings.php#codes'); exit;
    }

    // Usuń kod
    if ($action === 'clear_code') {
        $uid = (int)($_POST['user_id'] ?? 0);
        if ($uid) {
            db()->prepare("UPDATE users SET login_code=NULL WHERE id=?")->execute([$uid]);
            flash_set('success', 'Kod dostępu usunięty.');
        }
        header('Location: ' . APP_URL . '/admin/login_settings.php#codes'); exit;
    }

    // Wyślij kod emailem
    if ($action === 'send_code') {
        $uid = (int)($_POST['user_id'] ?? 0);
        if ($uid) {
            $user = db_one("SELECT * FROM users WHERE id=?", [$uid]);
            if ($user && $user['email']) {
                // Wygeneruj nowy kod jeśli brak
                $code = $user['login_code'];
                if (!$code) {
                    $code = strtoupper(substr(bin2hex(random_bytes(8)), 0, 10));
                    db()->prepare("UPDATE users SET login_code=? WHERE id=?")->execute([$code, $uid]);
                }
                try {
                    require_once dirname(__DIR__) . '/includes/mail_queue.php';
                    $org  = defined('ORG_NAME') ? ORG_NAME : 'System';
                    $url  = APP_URL . '/auth/login.php?tab=code';
                    $html = "<p>Witaj <strong>" . h($user['name'] ?? $user['email']) . "</strong>,</p>
                             <p>Twój kod jednorazowego dostępu do systemu <strong>{$org}</strong>:</p>
                             <div style='font-size:2rem;font-weight:700;letter-spacing:.2em;font-family:monospace;padding:12px 20px;background:#f1f5f9;border-radius:8px;display:inline-block'>{$code}</div>
                             <p style='margin-top:16px'>Wejdź na <a href='{$url}'>{$url}</a> i wpisz powyższy kod w zakładce <em>Kod dostępu</em>.</p>
                             <p style='color:#64748b;font-size:.85rem'>Kod jest jednorazowy — po zalogowaniu zostanie unieważniony.</p>";
                    mail_queue_add($user['email'], '', "Kod dostępu do systemu — {$org}", $html);
                    mail_queue_process(1);
                    flash_set('success', "Kod dostępu wysłany na {$user['email']}.");
                } catch (\Throwable $e) {
                    flash_set('error', 'Błąd wysyłki: ' . $e->getMessage());
                }
            }
        }
        header('Location: ' . APP_URL . '/admin/login_settings.php#codes'); exit;
    }
}

// ── Pobierz dane ───────────────────────────────────────────────────────────
$methods_enabled = [];
foreach (array_keys($method_keys) as $k) {
    $methods_enabled[$k] = _lm_get($k);
}

$users = db_all("SELECT id, name, email, login_code, is_active, role FROM users WHERE email != 'serwis@local' ORDER BY name, email");

$TZ_ACTIVE = 'administracja';
include dirname(__DIR__) . '/tozsamosc/_head.php';
?>

<div class="d-flex align-items-center gap-2 mb-4">
  <div class="rounded-3 p-2 bg-primary bg-opacity-10 text-primary">
    <i class="bi bi-shield-lock fs-4"></i>
  </div>
  <div>
    <h4 class="mb-0 fw-bold">Metody logowania</h4>
    <div class="text-muted small">Zarządzaj sposobami uwierzytelniania użytkowników</div>
  </div>
</div>

<?= flash_html() ?>

<div class="row g-4">

<!-- ══ Lewa kolumna: ustawienia metod ══════════════════════════════════════ -->
<div class="col-lg-5">

<div class="card shadow-sm mb-4">
<div class="card-header fw-semibold"><i class="bi bi-toggles text-primary me-1"></i> Aktywne metody logowania</div>
<div class="card-body">
  <form method="post">
    <input type="hidden" name="_csrf"    value="<?= csrf_token() ?>">
    <input type="hidden" name="_action" value="save_methods">

    <!-- E-mail + hasło (zawsze włączone) -->
    <div class="d-flex align-items-start gap-3 mb-4">
      <div class="pt-1">
        <div class="form-check form-switch mb-0">
          <input class="form-check-input" type="checkbox" disabled checked
                 style="width:2.5rem;height:1.3rem">
        </div>
      </div>
      <div class="flex-grow-1">
        <div class="d-flex align-items-center gap-2 mb-1">
          <i class="bi bi-person-fill text-primary fs-5"></i>
          <strong>E-mail i hasło</strong>
          <span class="badge bg-secondary bg-opacity-15 text-secondary border" style="font-size:.7rem">Zawsze włączone</span>
        </div>
        <div class="small text-muted">Standardowe logowanie loginem i hasłem. Zawsze dostępne — nie można wyłączyć.</div>
        <div class="small text-muted mt-1">
          <i class="bi bi-shield-check text-success me-1"></i>Ochrona brute-force · <i class="bi bi-phone me-1"></i>Opcjonalne 2FA (SMS / TOTP)
        </div>
      </div>
    </div>

    <hr class="my-3">

    <!-- Kod jednorazowy -->
    <div class="d-flex align-items-start gap-3 mb-4">
      <div class="pt-1">
        <div class="form-check form-switch mb-0">
          <input class="form-check-input" type="checkbox" name="login_method_code"
                 id="m_code" style="width:2.5rem;height:1.3rem"
                 <?= $methods_enabled['login_method_code'] ? 'checked' : '' ?>>
        </div>
      </div>
      <div class="flex-grow-1">
        <label for="m_code" class="d-flex align-items-center gap-2 mb-1" style="cursor:pointer">
          <i class="bi bi-key-fill text-warning fs-5"></i>
          <strong>Kod jednorazowy</strong>
        </label>
        <div class="small text-muted">Użytkownik wpisuje przydzielony przez admina kod (login_code). Kod jest unieważniany po pierwszym użyciu.</div>
        <div class="small text-muted mt-1">
          <i class="bi bi-check-circle text-success me-1"></i>Gotowe do użycia · <a href="#codes" class="small">Zarządzaj kodami ↓</a>
        </div>
      </div>
    </div>

    <hr class="my-3">

    <!-- SMS OTP -->
    <div class="d-flex align-items-start gap-3 mb-4">
      <div class="pt-1">
        <div class="form-check form-switch mb-0">
          <input class="form-check-input" type="checkbox" name="login_method_sms"
                 id="m_sms" style="width:2.5rem;height:1.3rem"
                 <?= $methods_enabled['login_method_sms'] ? 'checked' : '' ?>
                 <?= !$sms_configured ? 'disabled' : '' ?>>
        </div>
      </div>
      <div class="flex-grow-1">
        <label for="m_sms" class="d-flex align-items-center gap-2 mb-1" style="cursor:pointer">
          <i class="bi bi-phone-fill text-success fs-5"></i>
          <strong>Kod SMS (OTP)</strong>
        </label>
        <div class="small text-muted">Użytkownik podaje numer telefonu — system wysyła 6-cyfrowy kod ważny 5 minut.</div>
        <?php if (!$sms_configured): ?>
        <div class="small text-warning mt-1">
          <i class="bi bi-exclamation-triangle me-1"></i>Bramka SMS nie jest skonfigurowana —
          <a href="<?= APP_URL ?>/admin/sms_settings.php">skonfiguruj SMS</a>
        </div>
        <?php else: ?>
        <div class="small text-success mt-1">
          <i class="bi bi-check-circle me-1"></i>Bramka SMS aktywna
        </div>
        <?php endif; ?>
      </div>
    </div>

    <hr class="my-3">

    <!-- Microsoft 365 -->
    <div class="d-flex align-items-start gap-3 mb-3">
      <div class="pt-1">
        <div class="form-check form-switch mb-0">
          <input class="form-check-input" type="checkbox" name="login_method_ms365"
                 id="m_ms365" style="width:2.5rem;height:1.3rem"
                 <?= $methods_enabled['login_method_ms365'] ? 'checked' : '' ?>
                 <?= !$ms_configured ? 'disabled' : '' ?>>
        </div>
      </div>
      <div class="flex-grow-1">
        <label for="m_ms365" class="d-flex align-items-center gap-2 mb-1" style="cursor:pointer">
          <i class="bi bi-microsoft text-primary fs-5" style="color:#00a4ef !important"></i>
          <strong>Microsoft 365 / Azure AD</strong>
        </label>
        <div class="small text-muted">SSO przez konto Microsoft organizacji. Użytkownik jest identyfikowany po adresie e-mail z Azure AD.</div>
        <?php if (!$ms_configured): ?>
        <div class="small text-warning mt-1">
          <i class="bi bi-exclamation-triangle me-1"></i>Microsoft 365 nie jest skonfigurowane —
          <a href="<?= APP_URL ?>/admin/m365_settings.php">skonfiguruj M365</a>
        </div>
        <?php else: ?>
        <div class="small text-success mt-1">
          <i class="bi bi-check-circle me-1"></i>Aplikacja Azure AD aktywna
        </div>
        <?php endif; ?>
      </div>
    </div>

    <hr class="my-3">

    <!-- Certyfikat X.509 -->
    <?php $x509_certs_active = false; try { require_once dirname(__DIR__) . '/includes/x509_login.php'; $x509_certs_active = x509_any_active(); } catch (\Throwable $e) {} ?>
    <div class="d-flex align-items-start gap-3 mb-3">
      <div class="pt-1">
        <div class="form-check form-switch mb-0">
          <input class="form-check-input" type="checkbox" name="login_method_x509"
                 id="m_x509" style="width:2.5rem;height:1.3rem"
                 <?= $methods_enabled['login_method_x509'] ? 'checked' : '' ?>>
        </div>
      </div>
      <div class="flex-grow-1">
        <label for="m_x509" class="d-flex align-items-center gap-2 mb-1" style="cursor:pointer">
          <i class="bi bi-patch-check-fill text-secondary fs-5"></i>
          <strong>Certyfikat X.509</strong>
        </label>
        <div class="small text-muted">Logowanie plikiem PKCS#12 (.p12/.pfx) wygenerowanym przez administratora. Przeznaczone dla adminów i edytorów.</div>
        <?php if (!$x509_certs_active): ?>
        <div class="small text-warning mt-1">
          <i class="bi bi-exclamation-triangle me-1"></i>Brak aktywnych certyfikatów — zakładka nie pojawi się mimo włączonego ustawienia.
          <a href="<?= APP_URL ?>/admin/x509_certs.php">Zarządzaj certyfikatami</a>
        </div>
        <?php else: ?>
        <div class="small text-success mt-1">
          <i class="bi bi-check-circle me-1"></i>Aktywne certyfikaty w systemie
        </div>
        <?php endif; ?>
      </div>
    </div>

    <hr class="mt-4 mb-3">

    <button type="submit" class="btn btn-primary">
      <i class="bi bi-floppy me-1"></i>Zapisz ustawienia
    </button>
  </form>
</div>
</div>

<!-- Podgląd okna logowania -->
<div class="card shadow-sm border-0" style="background:#f8fafc">
<div class="card-header fw-semibold bg-transparent border-bottom">
  <i class="bi bi-eye text-muted me-1"></i> Podgląd dostępnych zakładek
</div>
<div class="card-body py-2">
  <div class="d-flex flex-wrap gap-2 py-1" id="preview-tabs">
    <span class="badge bg-primary px-3 py-2" style="font-size:.82rem">
      <i class="bi bi-person-fill me-1"></i>E-mail i hasło
    </span>
    <?php if ($methods_enabled['login_method_code']): ?>
    <span class="badge bg-warning text-dark px-3 py-2" id="prev-code" style="font-size:.82rem">
      <i class="bi bi-key-fill me-1"></i>Kod dostępu
    </span>
    <?php else: ?>
    <span class="badge bg-light text-muted border px-3 py-2 text-decoration-line-through" id="prev-code" style="font-size:.82rem">
      <i class="bi bi-key-fill me-1"></i>Kod dostępu
    </span>
    <?php endif; ?>
    <?php if ($methods_enabled['login_method_sms'] && $sms_configured): ?>
    <span class="badge bg-success px-3 py-2" id="prev-sms" style="font-size:.82rem">
      <i class="bi bi-phone-fill me-1"></i>Kod SMS
    </span>
    <?php else: ?>
    <span class="badge bg-light text-muted border px-3 py-2 text-decoration-line-through" id="prev-sms" style="font-size:.82rem">
      <i class="bi bi-phone-fill me-1"></i>Kod SMS
    </span>
    <?php endif; ?>
    <?php if ($methods_enabled['login_method_ms365'] && $ms_configured): ?>
    <span class="badge bg-primary px-3 py-2" id="prev-ms365" style="font-size:.82rem;background:#00a4ef !important">
      <i class="bi bi-microsoft me-1"></i>Microsoft 365
    </span>
    <?php else: ?>
    <span class="badge bg-light text-muted border px-3 py-2 text-decoration-line-through" id="prev-ms365" style="font-size:.82rem">
      <i class="bi bi-microsoft me-1"></i>Microsoft 365
    </span>
    <?php endif; ?>
    <?php if ($methods_enabled['login_method_x509'] && $x509_certs_active): ?>
    <span class="badge bg-secondary px-3 py-2" id="prev-x509" style="font-size:.82rem">
      <i class="bi bi-patch-check-fill me-1"></i>Certyfikat X.509
    </span>
    <?php else: ?>
    <span class="badge bg-light text-muted border px-3 py-2 text-decoration-line-through" id="prev-x509" style="font-size:.82rem">
      <i class="bi bi-patch-check-fill me-1"></i>Certyfikat X.509
    </span>
    <?php endif; ?>
  </div>
  <div class="small text-muted mt-2">
    <i class="bi bi-info-circle me-1"></i>Przekreślone = wyłączone lub niezskonfigurowane
  </div>
</div>
</div>

</div><!-- /col-5 -->

<!-- ══ Prawa kolumna: kody jednorazowe ═════════════════════════════════════ -->
<div class="col-lg-7" id="codes">

<div class="card shadow-sm">
<div class="card-header fw-semibold d-flex align-items-center justify-content-between">
  <span><i class="bi bi-key-fill text-warning me-1"></i> Kody jednorazowe użytkowników</span>
  <span class="badge bg-secondary bg-opacity-15 text-secondary border">
    <?= count(array_filter($users, fn($u) => !empty($u['login_code']))) ?> aktywnych
  </span>
</div>
<div class="card-body p-0">
  <div class="table-responsive">
    <table class="table table-hover mb-0 align-middle" style="font-size:.87rem">
      <thead class="table-light">
        <tr>
          <th>Użytkownik</th>
          <th>Rola</th>
          <th class="text-center">Kod dostępu</th>
          <th class="text-center">Akcje</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($users as $u): ?>
        <tr class="<?= !$u['is_active'] ? 'opacity-50' : '' ?>">
          <td>
            <div class="fw-semibold"><?= h($u['name'] ?: $u['email']) ?></div>
            <div class="text-muted" style="font-size:.78rem"><?= h($u['email']) ?></div>
          </td>
          <td>
            <?php
            $role_badges = [
                'admin'  => ['bg-danger',  'Admin'],
                'editor' => ['bg-primary', 'Edytor'],
                'viewer' => ['bg-secondary','Podgląd'],
            ];
            [$rb_cls, $rb_lbl] = $role_badges[$u['role']] ?? ['bg-secondary','?'];
            ?>
            <span class="badge <?= $rb_cls ?>"><?= $rb_lbl ?></span>
          </td>
          <td class="text-center">
            <?php if ($u['login_code']): ?>
            <code class="bg-warning bg-opacity-15 text-warning-emphasis px-2 py-1 rounded"
                  style="font-size:.9rem;letter-spacing:.1em;cursor:pointer"
                  title="Kliknij, aby skopiować"
                  onclick="navigator.clipboard?.writeText('<?= h($u['login_code']) ?>');this.classList.add('text-success');setTimeout(()=>this.classList.remove('text-success'),1000)">
              <?= h($u['login_code']) ?>
            </code>
            <?php else: ?>
            <span class="text-muted small">— brak —</span>
            <?php endif; ?>
          </td>
          <td class="text-center">
            <div class="d-flex justify-content-center gap-1 flex-wrap">
              <!-- Generuj nowy kod -->
              <form method="post" class="d-inline" onsubmit="return confirm('Wygenerować nowy kod? Stary zostanie unieważniony.')">
                <input type="hidden" name="_csrf"    value="<?= csrf_token() ?>">
                <input type="hidden" name="_action" value="gen_code">
                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-warning" title="Generuj kod">
                  <i class="bi bi-arrow-clockwise"></i>
                </button>
              </form>
              <?php if ($u['login_code'] && $u['email']): ?>
              <!-- Wyślij emailem -->
              <form method="post" class="d-inline">
                <input type="hidden" name="_csrf"    value="<?= csrf_token() ?>">
                <input type="hidden" name="_action" value="send_code">
                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-primary" title="Wyślij kod e-mailem">
                  <i class="bi bi-envelope-arrow-up"></i>
                </button>
              </form>
              <!-- Usuń kod -->
              <form method="post" class="d-inline" onsubmit="return confirm('Unieważnić kod dostępu?')">
                <input type="hidden" name="_csrf"    value="<?= csrf_token() ?>">
                <input type="hidden" name="_action" value="clear_code">
                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger" title="Usuń kod">
                  <i class="bi bi-x-lg"></i>
                </button>
              </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
</div>

<!-- Informacja o 2FA -->
<div class="card shadow-sm mt-4 border-0" style="background:#f0f7ff">
<div class="card-body py-3">
  <div class="d-flex gap-3">
    <i class="bi bi-shield-check text-primary fs-4 flex-shrink-0 mt-1"></i>
    <div>
      <div class="fw-semibold mb-1">Uwierzytelnianie dwuskładnikowe (2FA)</div>
      <div class="small text-muted mb-2">
        2FA jest konfigurowane per-użytkownik w panelu administratora (zakładka Użytkownicy → edycja).
        Dostępne metody: <strong>SMS</strong> (wymaga bramki SMS) i <strong>TOTP</strong> (Google Authenticator, Authy itp.).
        Działa jako drugi krok po zalogowaniu e-mailem i hasłem.
      </div>
      <a href="<?= APP_URL ?>/admin/users.php" class="btn btn-sm btn-outline-primary">
        <i class="bi bi-people me-1"></i>Zarządzaj użytkownikami i 2FA
      </a>
    </div>
  </div>
</div>
</div>

</div><!-- /col-7 -->

</div><!-- /row -->

<?php include dirname(__DIR__) . '/tozsamosc/_foot.php'; ?>
