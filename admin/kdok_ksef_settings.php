<?php
/**
 * admin/kdok_ksef_settings.php — Konfiguracja integracji KSeF (EOD Dokumentów Księgowych).
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/ksiegowosc.php';
require_once dirname(__DIR__) . '/includes/kdok_ksef.php';

require_role('admin');
$PAGE_TITLE = 'KSeF — EOD Dokumentów Księgowych';
kdok_migrate();
kdok_ksef_migrate();

// ── Helpery ustawień ──────────────────────────────────────────────────────────

function kdok_ksef_setting(string $key): string {
    return org_setting('kdok_ksef_' . $key);
}

function kdok_ksef_save(string $key, string $value): void {
    $full_key = 'kdok_ksef_' . $key;
    $exists = db_one("SELECT 1 FROM settings WHERE key_=?", [$full_key]);
    if ($exists) {
        db()->prepare("UPDATE settings SET value=? WHERE key_=?")->execute([$value, $full_key]);
    } else {
        db()->prepare("INSERT INTO settings (key_,value) VALUES (?,?)")->execute([$full_key, $value]);
    }
}

// ── Dane bieżące ──────────────────────────────────────────────────────────────

$ksef_enabled   = kdok_ksef_setting('enabled')   === '1';
$ksef_env       = kdok_ksef_env_id();
$ksef_nip       = kdok_ksef_setting('nip');
$ksef_token     = kdok_ksef_setting('token');
$ksef_last_sync = kdok_ksef_setting('last_sync');
$ksef_has_cert  = (org_setting('kdok_ksef_cert_pem_' . $ksef_env) !== '');
$ksef_has_key   = (org_setting('kdok_ksef_key_pem_'  . $ksef_env) !== '');
$ksef_cert_info = null;
if ($ksef_has_cert) {
    try { $ksef_cert_info = kdok_ksef_parse_cert(org_setting('kdok_ksef_cert_pem_' . $ksef_env)); } catch (\Throwable $_) {}
}
$ksef_auth_method = kdok_ksef_auth_method();

$queue_count = (int)(kdok_one("SELECT COUNT(*) AS cnt FROM kdok_ksef_queue")['cnt'] ?? 0);

$test_result = null;
$errors      = [];

// ── Obsługa POST ──────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['_action'] ?? 'save';

    // ── Zapisz ustawienia ─────────────────────────────────────────────────────
    if ($action === 'save') {
        $new_enabled  = isset($_POST['kdok_ksef_enabled']) ? '1' : '0';
        $new_env      = in_array($_POST['kdok_ksef_env'] ?? '', ['production', 'demo', 'test']) ? $_POST['kdok_ksef_env'] : 'production';
        $new_nip      = trim($_POST['kdok_ksef_nip']    ?? '');
        $new_token    = trim($_POST['kdok_ksef_token']   ?? '');
        $new_pubkey   = trim($_POST['kdok_ksef_pubkey_manual'] ?? '');

        // Walidacja NIP
        if ($new_nip !== '' && !preg_match('/^\d{10}$/', $new_nip)) {
            $errors[] = 'NIP musi składać się z dokładnie 10 cyfr.';
        }
        // Walidacja klucza PEM (jeśli podano)
        if ($new_pubkey !== '' && !str_contains($new_pubkey, 'BEGIN')) {
            $errors[] = 'Klucz publiczny musi być w formacie PEM (-----BEGIN PUBLIC KEY-----)';
        }

        if (!$errors) {
            kdok_ksef_save('enabled', $new_enabled);
            kdok_ksef_save('env', $new_env);
            $ksef_env = $new_env;

            if ($new_nip !== '') {
                kdok_ksef_save('nip', $new_nip);
                $ksef_nip = $new_nip;
            }
            if ($new_token !== '') {
                kdok_ksef_save('token', $new_token);
                $ksef_token = $new_token;
            }
            // Klucz publiczny — zapisz (może być pusty = wyczyść override)
            kdok_ksef_setting_save('kdok_ksef_pubkey_manual_' . $new_env, $new_pubkey);
            // Wyczyść cache żeby użyć nowego klucza natychmiast
            if ($new_pubkey !== '') {
                kdok_ksef_setting_save('kdok_ksef_public_key_cache_' . $new_env, '');
                kdok_ksef_setting_save('kdok_ksef_public_key_ts_'    . $new_env, '');
            }

            // Certyfikat + klucz prywatny — upload pliku lub textarea
            $cert_src  = trim($_POST['kdok_ksef_cert_pem'] ?? '');
            $key_src   = trim($_POST['kdok_ksef_key_pem']  ?? '');
            $key_pass  = $_POST['kdok_ksef_key_pass'] ?? '';  // hasło — nie trim (może mieć spacje)
            // Pliki mają pierwszeństwo nad polami tekstowymi
            if (!empty($_FILES['kdok_ksef_cert_file']['tmp_name'])) {
                $cert_src = trim(file_get_contents($_FILES['kdok_ksef_cert_file']['tmp_name']) ?: '');
            }
            if (!empty($_FILES['kdok_ksef_key_file']['tmp_name'])) {
                $key_src = trim(file_get_contents($_FILES['kdok_ksef_key_file']['tmp_name']) ?: '');
            }
            if ($cert_src !== '') {
                try {
                    kdok_ksef_parse_cert($cert_src);
                    kdok_ksef_setting_save('kdok_ksef_cert_pem_' . $new_env, $cert_src);
                    $ksef_has_cert  = true;
                    $ksef_cert_info = kdok_ksef_parse_cert($cert_src);
                } catch (\Throwable $e) {
                    $errors[] = 'Nieprawidłowy certyfikat: ' . $e->getMessage();
                }
            }
            if (!$errors && $key_src !== '') {
                // Waliduj klucz z hasłem (jeśli podano nowe) lub bez
                $test_pass = ($key_pass !== '') ? $key_pass : null;
                $pkey = @openssl_pkey_get_private($key_src, $test_pass);
                if (!$pkey) {
                    $errors[] = 'Nieprawidłowy klucz prywatny PEM'
                        . ($key_pass !== '' ? ' (sprawdź hasło)' : ' (jeśli klucz jest zaszyfrowany — podaj hasło)') . '.';
                } else {
                    kdok_ksef_setting_save('kdok_ksef_key_pem_' . $new_env, $key_src);
                    $ksef_has_key = true;
                }
            }
            // Hasło — zapisz jeśli podano nowe; wyczyść jeśli zaznaczono checkbox "usuń hasło"
            if ($key_pass !== '') {
                kdok_ksef_setting_save('kdok_ksef_key_pass_' . $new_env, $key_pass);
            } elseif (isset($_POST['kdok_ksef_key_pass_clear'])) {
                kdok_ksef_setting_save('kdok_ksef_key_pass_' . $new_env, '');
            }

            if (!$errors) {
                $ksef_enabled = $new_enabled === '1';
                flash_set('success', 'Ustawienia KSeF zostały zapisane.');
                header('Location: kdok_ksef_settings.php');
                exit;
            }
        }

        // ── Usuń certyfikat ───────────────────────────────────────────────────
        if ($action === 'clear_cert') {
            $env_clr = in_array($_POST['env'] ?? '', ['production','demo','test']) ? $_POST['env'] : $ksef_env;
            kdok_ksef_setting_save('kdok_ksef_cert_pem_'  . $env_clr, '');
            kdok_ksef_setting_save('kdok_ksef_key_pem_'   . $env_clr, '');
            kdok_ksef_setting_save('kdok_ksef_key_pass_'  . $env_clr, '');
            $ksef_has_cert = false; $ksef_has_key = false; $ksef_cert_info = null;
            flash_set('warning', 'Certyfikat i klucz prywatny zostały usunięte.');
            header('Location: kdok_ksef_settings.php');
            exit;
        }
    }

    // ── Test połączenia ───────────────────────────────────────────────────────
    if ($action === 'test') {
        try {
            if (empty($ksef_nip)) {
                throw new RuntimeException('Przed testem zapisz NIP.');
            }
            $auth = kdok_ksef_authenticate_auto($ksef_nip);
            if (!$auth['ok']) {
                throw new RuntimeException($auth['error'] ?? 'Nieznany błąd autoryzacji.');
            }
            // Zakończ sesję od razu — test tylko weryfikuje połączenie
            kdok_ksef_session_terminate($auth['token']);
            $test_result = ['ok' => true, 'token' => $auth['token']];
        } catch (\Throwable $e) {
            $test_result = ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    // ── Przekieruj do synchronizacji ──────────────────────────────────────────
    if ($action === 'sync') {
        header('Location: ' . APP_URL . '/ksiegowosc/ksef_sync.php');
        exit;
    }
}

// ── Pomocnicze: formatowanie daty ─────────────────────────────────────────────

function fmt_sync_date(string $ts): string {
    if (!$ts) return '—';
    $dt = \DateTime::createFromFormat('Y-m-d H:i:s', $ts)
        ?: \DateTime::createFromFormat('U', $ts)
        ?: false;
    if (!$dt) return htmlspecialchars($ts, ENT_QUOTES, 'UTF-8');
    return $dt->format('d.m.Y H:i');
}

include dirname(__DIR__) . '/includes/header.php';
echo flash_html();
?>

<nav aria-label="breadcrumb" class="mb-3">
  <ol class="breadcrumb" style="font-size:.8rem">
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/admin/">Admin</a></li>
    <li class="breadcrumb-item active">KSeF — EOD Dokumentów Księgowych</li>
  </ol>
</nav>

<div class="d-flex justify-content-between align-items-center mb-3">
  <div class="d-flex align-items-center gap-2">
    <h4 class="mb-0 fw-bold"><i class="bi bi-receipt-cutoff text-primary me-2"></i>KSeF — EOD Dokumentów Księgowych</h4>
    <?php if ($ksef_enabled && $ksef_nip && $ksef_token): ?>
      <span class="badge bg-success">Aktywne</span>
    <?php elseif ($ksef_enabled): ?>
      <span class="badge bg-warning text-dark">Włączone — niekompletna konfiguracja</span>
    <?php else: ?>
      <span class="badge bg-secondary">Wyłączone</span>
    <?php endif; ?>
  </div>
  <a href="<?= APP_URL ?>/admin/" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-arrow-left me-1"></i>Panel admina
  </a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger">
  <i class="bi bi-exclamation-triangle-fill me-2"></i>
  <strong>Błąd:</strong>
  <ul class="mb-0 mt-1 ps-3">
    <?php foreach ($errors as $e): ?>
      <li><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<?php if ($test_result !== null): ?>
  <?php if ($test_result['ok']): ?>
    <div class="alert alert-success">
      <i class="bi bi-check-circle-fill me-2"></i>
      <strong>Połączenie z KSeF działa!</strong>
      Autoryzacja poprawna — SDK zarządza tokenami wewnętrznie.
    </div>
  <?php else: ?>
    <div class="alert alert-danger">
      <i class="bi bi-x-circle-fill me-2"></i>
      <strong>Błąd połączenia z KSeF:</strong>
      <?= htmlspecialchars($test_result['error'], ENT_QUOTES, 'UTF-8') ?>
    </div>
  <?php endif; ?>
<?php endif; ?>

<div class="row g-4">

  <!-- ── Kolumna lewa: formularz ───────────────────────────────────────────── -->
  <div class="col-lg-7">

    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="_csrf"    value="<?= csrf_token() ?>">
      <input type="hidden" name="_action"  value="save">

      <!-- Włącz moduł -->
      <div class="card border-0 shadow-sm mb-3">
        <div class="card-header fw-semibold">
          <i class="bi bi-toggle-on me-2 text-primary"></i>Moduł KSeF
        </div>
        <div class="card-body">
          <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" role="switch"
                   name="kdok_ksef_enabled" id="kdok_ksef_enabled" value="1"
                   <?= $ksef_enabled ? 'checked' : '' ?>>
            <label class="form-check-label fw-semibold" for="kdok_ksef_enabled">
              Włącz integrację z Krajowym Systemem e-Faktur
            </label>
          </div>
          <div class="form-text">
            Gdy wyłączony — pobieranie dokumentów z KSeF oraz kolejka synchronizacji są nieaktywne.
          </div>
        </div>
      </div>

      <!-- Środowisko -->
      <div class="card border-0 shadow-sm mb-3">
        <div class="card-header fw-semibold">
          <i class="bi bi-globe2 me-2 text-primary"></i>Środowisko KSeF
        </div>
        <div class="card-body">
          <div class="d-flex gap-4">
            <?php foreach (KSEF_ENVS as $env_id => $env_cfg): ?>
            <div class="form-check">
              <input class="form-check-input" type="radio" name="kdok_ksef_env"
                     id="ksef_env_<?= $env_id ?>" value="<?= $env_id ?>"
                     <?= $ksef_env === $env_id ? 'checked' : '' ?>>
              <label class="form-check-label" for="ksef_env_<?= $env_id ?>">
                <?php if ($env_id === 'production'): ?>
                  <span class="badge bg-danger me-1">PROD</span>
                <?php else: ?>
                  <span class="badge bg-warning text-dark me-1">TEST</span>
                <?php endif; ?>
                <?= h($env_cfg['label']) ?>
                <div class="text-muted small"><?= h($env_cfg['api_base']) ?></div>
              </label>
            </div>
            <?php endforeach; ?>
          </div>
          <?php if ($ksef_env === 'test'): ?>
          <div class="alert alert-warning mt-2 mb-0 py-2 small">
            <i class="bi bi-exclamation-triangle"></i>
            Środowisko testowe — dokumenty z KSeF-test nie są prawdziwymi fakturami.
            Wymagany jest NIP testowy i token z portalu ksef-test.mf.gov.pl.
          </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- NIP -->
      <div class="card border-0 shadow-sm mb-3">
        <div class="card-header fw-semibold">
          <i class="bi bi-building me-2 text-primary"></i>Dane podatnika
        </div>
        <div class="card-body">
          <label class="form-label fw-semibold small" for="kdok_ksef_nip">
            NIP organizacji <span class="text-danger">*</span>
          </label>
          <input type="text" name="kdok_ksef_nip" id="kdok_ksef_nip"
                 class="form-control font-monospace"
                 value="<?= htmlspecialchars($ksef_nip, ENT_QUOTES, 'UTF-8') ?>"
                 maxlength="10" pattern="\d{10}"
                 placeholder="np. 1234567890"
                 autocomplete="off">
          <div class="form-text">
            Dokładnie 10 cyfr, bez myślników. Musi być zgodny z NIP-em użytym w portalu KSeF.
          </div>
        </div>
      </div>

      <!-- Token autoryzujący -->
      <div class="card border-0 shadow-sm mb-3">
        <div class="card-header fw-semibold">
          <i class="bi bi-key me-2 text-primary"></i>Token autoryzujący
        </div>
        <div class="card-body">
          <?php if ($ksef_token): ?>
            <!-- Token zapisany — pokaż zamaskowany podgląd + przycisk zmiany -->
            <div id="tokenMaskedBlock">
              <div class="mb-2">
                <span class="font-monospace text-muted">
                  <?= str_repeat('•', 20) . substr(htmlspecialchars($ksef_token, ENT_QUOTES, 'UTF-8'), -6) ?>
                </span>
                <span class="badge bg-success ms-2">Zapisany</span>
              </div>
              <button type="button" class="btn btn-outline-secondary btn-sm" id="btnChangeToken">
                <i class="bi bi-pencil me-1"></i>Zmień token
              </button>
            </div>
            <div id="tokenEditBlock" class="d-none mt-3">
          <?php else: ?>
            <div id="tokenEditBlock">
          <?php endif; ?>
              <label class="form-label fw-semibold small" for="kdok_ksef_token">
                <?= $ksef_token ? 'Nowy token (pozostaw puste, by nie zmieniać)' : 'Token <span class="text-danger">*</span>' ?>
              </label>
              <textarea name="kdok_ksef_token" id="kdok_ksef_token"
                        class="form-control font-monospace"
                        rows="4"
                        placeholder="Wklej token autoryzujący z portalu KSeF..."
                        autocomplete="off" spellcheck="false"></textarea>
              <div class="form-text">
                Token wieloliniowy jest akceptowany — zostanie zapisany w całości.
                <?php if ($ksef_token): ?>
                  Zostaw puste, by zachować aktualny token.
                <?php endif; ?>
              </div>
            </div>
        </div>
      </div>

      <!-- Klucz publiczny RSA (ręczny override) -->
      <div class="card border-0 shadow-sm mb-3">
        <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
          <span><i class="bi bi-key me-2 text-warning"></i>Klucz publiczny RSA (opcjonalny override)</span>
          <span class="badge bg-secondary small fw-normal">tylko gdy auto-pobieranie nie działa</span>
        </div>
        <div class="card-body">
          <?php
          $current_pubkey = org_setting('kdok_ksef_pubkey_manual_' . $ksef_env);
          $has_manual_key = $current_pubkey !== '' && str_contains($current_pubkey, 'BEGIN');
          ?>
          <?php if ($has_manual_key): ?>
          <div class="alert alert-success py-2 small mb-2">
            <i class="bi bi-check-circle-fill me-1"></i>
            Ręczny klucz publiczny jest aktywny dla środowiska <strong><?= h(KSEF_ENVS[$ksef_env]['label']) ?></strong>.
            Zostaw pole puste i zapisz, aby go usunąć i wrócić do auto-pobierania.
          </div>
          <?php endif; ?>
          <label class="form-label fw-semibold small" for="kdok_ksef_pubkey_manual">
            Klucz publiczny PEM
          </label>
          <textarea name="kdok_ksef_pubkey_manual" id="kdok_ksef_pubkey_manual"
                    class="form-control form-control-sm font-monospace" rows="5"
                    placeholder="-----BEGIN PUBLIC KEY-----&#10;MIIBIjANBgkq...&#10;-----END PUBLIC KEY-----&#10;(zostaw puste = pobieraj automatycznie)"><?= $has_manual_key ? h($current_pubkey) : '' ?></textarea>
          <div class="form-text">
            Pobierz klucz RSA ze strony KSeF MF:
            <a href="https://www.podatki.gov.pl/ksef/" target="_blank">podatki.gov.pl/ksef</a>
            lub z dokumentacji API.
            Wspierane formaty: PEM z nagłówkiem <code>BEGIN PUBLIC KEY</code>.
          </div>
        </div>
      </div>

      <!-- Certyfikat X.509 + klucz prywatny -->
      <div class="card border-0 shadow-sm mb-3">
        <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
          <span><i class="bi bi-patch-check me-2 text-success"></i>Certyfikat X.509 + klucz prywatny</span>
          <?php if ($ksef_auth_method === 'cert'): ?>
            <span class="badge bg-success"><i class="bi bi-shield-fill-check"></i> Aktywna metoda auth</span>
          <?php else: ?>
            <span class="badge bg-secondary fw-normal small">opcjonalnie — zastępuje token</span>
          <?php endif; ?>
        </div>
        <div class="card-body">
          <?php if ($ksef_has_cert && $ksef_cert_info): ?>
          <div class="alert <?= $ksef_cert_info['is_valid'] ? 'alert-success' : 'alert-danger' ?> py-2 small mb-3 d-flex align-items-center gap-2">
            <i class="bi bi-<?= $ksef_cert_info['is_valid'] ? 'patch-check-fill text-success' : 'patch-x-fill text-danger' ?> fs-5"></i>
            <div>
              <strong><?= h($ksef_cert_info['subject_cn']) ?></strong><br>
              Wystawca: <?= h($ksef_cert_info['issuer_cn']) ?> &nbsp;·&nbsp;
              Ważny do: <strong><?= h(substr($ksef_cert_info['valid_to'], 0, 10)) ?></strong>
              <?php if (!$ksef_cert_info['is_valid']): ?><span class="text-danger fw-bold"> — WYGASŁ</span><?php endif; ?>
              <?php if ($ksef_has_key): ?>&nbsp;·&nbsp;<i class="bi bi-key-fill text-success"></i> Klucz prywatny: zapisany<?php endif; ?>
            </div>
            <form method="post" class="ms-auto">
              <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="_action" value="clear_cert">
              <input type="hidden" name="env" value="<?= h($ksef_env) ?>">
              <button type="submit" class="btn btn-sm btn-outline-danger"
                      onclick="return confirm('Usunąć certyfikat i klucz? Autoryzacja wróci do tokena.')">
                <i class="bi bi-trash"></i> Usuń
              </button>
            </form>
          </div>
          <?php else: ?>
          <p class="text-muted small mb-3">
            Brak certyfikatu. Możesz wgrać certyfikat kwalifikowany lub zaufany KSeF
            zamiast tokena — autoryzacja certyfikatem ma pierwszeństwo.
          </p>
          <?php endif; ?>

          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold small">
                Certyfikat X.509 (PEM lub plik .crt/.pem)
              </label>
              <input type="file" name="kdok_ksef_cert_file" class="form-control form-control-sm mb-2"
                     accept=".pem,.crt,.cer">
              <textarea name="kdok_ksef_cert_pem" class="form-control form-control-sm font-monospace" rows="4"
                        placeholder="-----BEGIN CERTIFICATE-----&#10;...lub wklej PEM tutaj...&#10;-----END CERTIFICATE-----"></textarea>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold small">
                Klucz prywatny (PEM lub plik .key/.pem)
                <span class="badge bg-danger ms-1 fw-normal" style="font-size:.6rem">POUFNY</span>
              </label>
              <input type="file" name="kdok_ksef_key_file" class="form-control form-control-sm mb-2"
                     accept=".pem,.key">
              <textarea name="kdok_ksef_key_pem" class="form-control form-control-sm font-monospace" rows="4"
                        placeholder="-----BEGIN PRIVATE KEY-----&#10;...lub wklej PEM tutaj...&#10;-----END PRIVATE KEY-----"
                        autocomplete="off"></textarea>
              <div class="form-text text-danger small">
                <i class="bi bi-exclamation-triangle"></i>
                Klucz prywatny jest przechowywany w bazie danych.
                Używaj tylko jeśli środowisko jest odpowiednio zabezpieczone.
              </div>
            </div>
          </div>

          <!-- Hasło do klucza -->
          <div class="mt-3">
            <?php $has_pass = org_setting('kdok_ksef_key_pass_' . $ksef_env) !== ''; ?>
            <label class="form-label fw-semibold small">
              Hasło do klucza prywatnego
              <?php if ($has_pass): ?>
                <span class="badge bg-secondary fw-normal ms-1">zapisane</span>
              <?php endif; ?>
            </label>
            <div class="d-flex gap-2 align-items-center">
              <input type="password" name="kdok_ksef_key_pass"
                     class="form-control form-control-sm" style="max-width:320px"
                     placeholder="<?= $has_pass ? '••••••••  (zostaw puste = bez zmian)' : 'Hasło jeśli klucz jest zaszyfrowany…' ?>"
                     autocomplete="new-password">
              <?php if ($has_pass): ?>
              <div class="form-check mb-0">
                <input class="form-check-input" type="checkbox" name="kdok_ksef_key_pass_clear"
                       id="pass_clear" value="1">
                <label class="form-check-label small text-danger" for="pass_clear">
                  Usuń hasło
                </label>
              </div>
              <?php endif; ?>
            </div>
            <div class="form-text">
              Wymagane tylko gdy klucz PEM jest zaszyfrowany (<code>ENCRYPTED</code> w nagłówku).
              Klucze bez hasła — zostaw puste.
            </div>
          </div>

          <div class="form-text mt-2 border-top pt-2">
            Plik ma pierwszeństwo nad polem tekstowym. Obsługiwane formaty: PEM (RSA, EC, PKCS#8).
          </div>
        </div>
      </div>

      <!-- Przyciski zapisu -->
      <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary">
          <i class="bi bi-check2 me-1"></i>Zapisz ustawienia
        </button>
      </div>
    </form>

    <!-- Akcje: test i sync — osobne formularze (nie są save) -->
    <div class="d-flex gap-2 mt-3">
      <form method="post" class="d-inline">
        <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="test">
        <button type="submit" class="btn btn-outline-info btn-sm"
                <?= (!$ksef_nip || !$ksef_token) ? 'disabled title="Najpierw zapisz NIP i token"' : '' ?>>
          <i class="bi bi-plug me-1"></i>Testuj połączenie
        </button>
      </form>

      <form method="post" class="d-inline">
        <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="sync">
        <button type="submit" class="btn btn-outline-success btn-sm"
                <?= (!$ksef_enabled || !$ksef_nip || !$ksef_token) ? 'disabled title="Włącz i skonfiguruj KSeF przed synchronizacją"' : '' ?>>
          <i class="bi bi-arrow-repeat me-1"></i>Synchronizuj teraz
        </button>
      </form>
    </div>

  </div><!-- /col-lg-7 -->

  <!-- ── Kolumna prawa: status + info ─────────────────────────────────────── -->
  <div class="col-lg-5">

    <!-- Status synchronizacji -->
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
        <span><i class="bi bi-bar-chart-steps me-2 text-primary"></i>Status synchronizacji</span>
        <?php if ($ksef_env === 'test'): ?>
          <span class="badge bg-warning text-dark"><i class="bi bi-flask"></i> TEST</span>
        <?php else: ?>
          <span class="badge bg-danger">PROD</span>
        <?php endif; ?>
      </div>
      <div class="card-body p-0">
        <table class="table table-sm mb-0">
          <tbody>
            <tr>
              <td class="text-muted ps-3 py-2" style="width:55%">Środowisko</td>
              <td class="fw-semibold py-2">
                <?= h(KSEF_ENVS[$ksef_env]['label'] ?? $ksef_env) ?>
              </td>
            </tr>
            <tr>
              <td class="text-muted ps-3 py-2">Ostatnia synchronizacja</td>
              <td class="fw-semibold py-2">
                <?= $ksef_last_sync ? fmt_sync_date($ksef_last_sync) : '<span class="text-muted">Nigdy</span>' ?>
              </td>
            </tr>
            <tr>
              <td class="text-muted ps-3 py-2">Dokumenty w kolejce</td>
              <td class="fw-semibold py-2">
                <?php if ($queue_count > 0): ?>
                  <span class="badge bg-warning text-dark"><?= $queue_count ?></span>
                <?php else: ?>
                  <span class="text-success"><i class="bi bi-check-circle me-1"></i>0</span>
                <?php endif; ?>
              </td>
            </tr>
            <tr>
              <td class="text-muted ps-3 py-2">NIP</td>
              <td class="fw-semibold py-2 font-monospace">
                <?= $ksef_nip ? htmlspecialchars($ksef_nip, ENT_QUOTES, 'UTF-8') : '<span class="text-muted">—</span>' ?>
              </td>
            </tr>
            <tr>
              <td class="text-muted ps-3 py-2">Token</td>
              <td class="py-2">
                <?= $ksef_token
                    ? '<span class="text-success"><i class="bi bi-check-circle me-1"></i>Zapisany</span>'
                    : '<span class="text-danger"><i class="bi bi-x-circle me-1"></i>Brak</span>' ?>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Informacje o KSeF -->
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-header fw-semibold">
        <i class="bi bi-info-circle me-2 text-primary"></i>O systemie KSeF
      </div>
      <div class="card-body small text-muted">
        <p class="mb-2">
          <strong>Krajowy System e-Faktur (KSeF)</strong> to platforma Ministerstwa Finansów
          umożliwiająca wystawianie, odbieranie i archiwizowanie faktur ustrukturyzowanych (XML FA(2)).
        </p>
        <p class="mb-2">
          Integracja pozwala automatycznie pobierać dokumenty wystawione na NIP organizacji
          i importować je do modułu EOD Dokumentów Księgowych.
        </p>
        <p class="mb-2">
          <a href="https://ksef.mf.gov.pl/" target="_blank" rel="noopener noreferrer" class="text-decoration-none">
            <i class="bi bi-box-arrow-up-right me-1"></i>Portal KSeF — mf.gov.pl
          </a>
        </p>
        <hr class="my-2">
        <p class="mb-0">
          <i class="bi bi-shield-lock text-warning me-1"></i>
          <strong>Bezpieczeństwo tokenu:</strong> Token autoryzujący jest przechowywany
          w bazie danych systemu. Stosuj tokeny o minimalnym zakresie uprawnień
          i regularnie je rotuj w panelu KSeF.
        </p>
      </div>
    </div>

    <!-- Dokumentacja / linki -->
    <div class="card border-0 shadow-sm">
      <div class="card-header fw-semibold">
        <i class="bi bi-link-45deg me-2 text-primary"></i>Przydatne linki
      </div>
      <div class="card-body small">
        <ul class="list-unstyled mb-0">
          <li class="mb-1">
            <a href="https://ksef.mf.gov.pl/" target="_blank" rel="noopener noreferrer" class="text-decoration-none">
              <i class="bi bi-globe me-1 text-muted"></i>Portal KSeF (produkcja)
            </a>
          </li>
          <li class="mb-1">
            <a href="https://ksef-demo.mf.gov.pl/" target="_blank" rel="noopener noreferrer" class="text-decoration-none">
              <i class="bi bi-globe me-1 text-muted"></i>Portal KSeF (demo / testowy)
            </a>
          </li>
          <li class="mb-1">
            <a href="https://www.podatki.gov.pl/ksef/" target="_blank" rel="noopener noreferrer" class="text-decoration-none">
              <i class="bi bi-file-text me-1 text-muted"></i>Dokumentacja i FAQ — podatki.gov.pl
            </a>
          </li>
          <li>
            <a href="<?= APP_URL ?>/ksiegowosc/ksef_sync.php" class="text-decoration-none">
              <i class="bi bi-arrow-repeat me-1 text-muted"></i>Strona synchronizacji KSeF
            </a>
          </li>
        </ul>
      </div>
    </div>

  </div><!-- /col-lg-5 -->

</div><!-- /row -->

<script>
(function () {
  // ── Toggle "Zmień token" ───────────────────────────────────────────────────
  var btnChange  = document.getElementById('btnChangeToken');
  var maskedBlk  = document.getElementById('tokenMaskedBlock');
  var editBlk    = document.getElementById('tokenEditBlock');

  if (btnChange && maskedBlk && editBlk) {
    btnChange.addEventListener('click', function () {
      maskedBlk.classList.add('d-none');
      editBlk.classList.remove('d-none');
      var ta = document.getElementById('kdok_ksef_token');
      if (ta) ta.focus();
    });
  }

})();
</script>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
