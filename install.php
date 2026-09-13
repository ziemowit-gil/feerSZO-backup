<?php
/**
 * install.php — Kreator instalacji Platformy NGO
 */
define('INSTALL_MODE', true);
session_start();

define('INSTALL_LOCK_FILE', __DIR__ . '/.install.lock');

/**
 * Ścieżka pliku bazy SQLite wcześniej "podrzuconego" na serwer (np. przez
 * scp/rsync — bin/deploy-mydevil.sh --sync-data) przed uruchomieniem
 * kreatora. Pozwala zaimportować istniejącą bazę bez wgrywania jej przez
 * formularz (limit upload_max_filesize/post_max_size na hostingu).
 */
define('INSTALL_STAGED_DB_FILE', __DIR__ . '/umowy.import.db');

/** Czy plik SQLite ma sensowną strukturę tego systemu (tabela users). */
function install_is_valid_sqlite(string $path): bool {
    try {
        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return (bool)$pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'")->fetch();
    } catch (\Throwable $e) {
        return false;
    }
}

$step      = (int)($_GET['step'] ?? 1);
$reinstall = !empty($_GET['reinstall']) || !empty($_SESSION['reinstall_mode']);

// ── Blokada instalatora ───────────────────────────────────────────────────────
$force_install = isset($_GET['force']) && $_GET['force'] === 'true';

if (file_exists(INSTALL_LOCK_FILE) && !$reinstall && !$force_install) {
    $lock_date = date('d.m.Y H:i', filemtime(INSTALL_LOCK_FILE));
    $lock_path = INSTALL_LOCK_FILE;
    ?><!DOCTYPE html><html lang="pl"><head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Instalator zablokowany</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>body{background:#f1f5f9}.wrap{max-width:560px;margin:4rem auto;padding:0 1rem}</style>
    </head><body><div class="wrap">
      <div class="text-center mb-4">
        <div style="width:56px;height:56px;background:#dc2626;border-radius:12px;display:inline-flex;align-items:center;justify-content:center;font-size:1.5rem;color:#fff;margin-bottom:.75rem">
          <i class="bi bi-lock-fill"></i>
        </div>
        <h1 style="font-size:1.1rem;font-weight:700;color:#0f172a">Instalator jest zablokowany</h1>
        <p class="text-muted small">Platforma NGO została już zainstalowana <?= htmlspecialchars($lock_date) ?>.</p>
      </div>
      <div class="card shadow-sm">
        <div class="card-body">
          <p class="small mb-2">Aby uruchomić instalator ponownie (reinstalacja), usuń plik blokady przez terminal SSH:</p>
          <pre class="p-3 rounded mb-3" style="background:#1e293b;color:#7dd3fc;font-size:.82rem;overflow-x:auto">rm <?= htmlspecialchars($lock_path) ?></pre>
          <div class="alert alert-warning py-2 small mb-3">
            <i class="bi bi-exclamation-triangle me-1"></i>
            Reinstalacja <strong>wyczyści bazę danych</strong> (poza kluczami API i konfiguracji). Upewnij się że masz backup.
          </div>
          <div class="d-flex gap-2 flex-wrap">
            <a href="index.php" class="btn btn-primary btn-sm">Przejdź do systemu</a>
            <a href="upgrade.php" class="btn btn-outline-secondary btn-sm">Panel aktualizacji</a>
            <a href="install.php?force=true" class="btn btn-outline-danger btn-sm ms-auto">Uruchom mimo blokady</a>
          </div>
        </div>
      </div>
      <p class="text-center text-muted mt-3" style="font-size:.72rem">
        Plik blokady: <code><?= htmlspecialchars($lock_path) ?></code>
      </p>
    </div></body></html>
    <?php exit;
}
$errors    = [];

// Tryb reinstalacji — załaduj config żeby wyciągnąć klucze
$preserved = [];
if (file_exists(__DIR__ . '/config.php')) {
    define('BOOTSTRAP_CHECKED', true);
    require_once __DIR__ . '/config.php';

    if (defined('APP_INSTALLED') && APP_INSTALLED) {
        if (!$reinstall && $step < 7) {
            header('Location: index.php'); exit;
        }
        // Zachowaj wrażliwe klucze z bieżącej instalacji
        if ($reinstall || $step > 1) {
            if (file_exists(__DIR__ . '/includes/db.php')) {
                require_once __DIR__ . '/includes/db.php';
                try {
                    $keys_to_preserve = [
                        // Microsoft 365
                        'm365_tenant_id','m365_client_id','m365_client_secret',
                        'm365_send_from_email','m365_send_from_name',
                        // SMTP
                        'smtp_host','smtp_port','smtp_user','smtp_pass',
                        'smtp_from_email','smtp_encryption',
                        // SMS
                        'sms_api_login','sms_api_password','sms_provider',
                        'sms_enabled','sms_sender_name',
                        'sms_twilio_sid','sms_twilio_token','sms_twilio_from',
                        // CRON
                        'cron_token',
                        // Anthropic AI
                        'anthropic_api_key','anthropic_model',
                        // Creator
                        'creator_password_hash',
                    ];
                    $rows = db_all("SELECT key_, value FROM settings WHERE key_ IN (" . implode(',', array_fill(0, count($keys_to_preserve), '?')) . ")", $keys_to_preserve);
                    foreach ($rows as $r) $preserved[$r['key_']] = $r['value'];
                } catch (\Throwable $e) {}
            }
        }
    }
}

if ($reinstall) {
    $_SESSION['reinstall_mode'] = true;
    // Usuń blokadę na czas reinstalacji
    if (file_exists(INSTALL_LOCK_FILE)) @unlink(INSTALL_LOCK_FILE);
}

// ── KROK 2: Baza danych ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 2) {
    $db_type     = $_POST['db_type'] ?? 'sqlite';
    $db_host     = trim($_POST['db_host'] ?? 'localhost');
    $db_name     = trim($_POST['db_name'] ?? 'umowy');
    $db_user     = trim($_POST['db_user'] ?? '');
    $db_pass     = $_POST['db_pass'] ?? '';
    $db_port     = (int)($_POST['db_port'] ?? 3306);
    $sqlite_mode = $_POST['sqlite_mode'] ?? 'new'; // new | import_staged | import_upload
    $imported    = false;

    try {
        if ($db_type === 'sqlite') {
            $target = __DIR__ . '/umowy.db';

            if ($sqlite_mode === 'import_staged') {
                if (!is_file(INSTALL_STAGED_DB_FILE)) {
                    throw new RuntimeException('Plik umowy.import.db nie został znaleziony w katalogu aplikacji.');
                }
                if (!install_is_valid_sqlite(INSTALL_STAGED_DB_FILE)) {
                    throw new RuntimeException('umowy.import.db nie wygląda na poprawną bazę tego systemu (brak tabeli users).');
                }
                if (!@rename(INSTALL_STAGED_DB_FILE, $target)) {
                    throw new RuntimeException('Nie udało się przenieść umowy.import.db — sprawdź uprawnienia zapisu.');
                }
                $imported = true;
            } elseif ($sqlite_mode === 'import_upload') {
                $f = $_FILES['db_import_file'] ?? null;
                if (!$f || $f['error'] === UPLOAD_ERR_NO_FILE) {
                    throw new RuntimeException('Wybierz plik bazy (.db) do zaimportowania.');
                }
                if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE) {
                    throw new RuntimeException('Plik przekracza limit uploadu na tym serwerze (upload_max_filesize/post_max_size). '
                        . 'Prześlij bazę przez SSH/SCP jako umowy.import.db do katalogu aplikacji i wybierz opcję "Plik już na serwerze".');
                }
                if ($f['error'] !== UPLOAD_ERR_OK || empty($f['tmp_name']) || !is_uploaded_file($f['tmp_name'])) {
                    throw new RuntimeException('Błąd wysyłki pliku (kod ' . ($f['error'] ?? '?') . ').');
                }
                if (!install_is_valid_sqlite($f['tmp_name'])) {
                    throw new RuntimeException('Przesłany plik nie wygląda na poprawną bazę tego systemu (brak tabeli users).');
                }
                if (!@move_uploaded_file($f['tmp_name'], $target)) {
                    throw new RuntimeException('Nie udało się zapisać przesłanego pliku.');
                }
                $imported = true;
            } else {
                new PDO('sqlite:' . $target); // dotychczasowe zachowanie — tworzy pustą bazę
            }
        } else {
            new PDO("mysql:host={$db_host};port={$db_port};dbname={$db_name};charset=utf8mb4", $db_user, $db_pass);
        }
        $_SESSION['install_db'] = compact('db_type','db_host','db_name','db_user','db_pass','db_port') + ['imported' => $imported];
        header('Location: install.php?step=3'); exit;
    } catch (\Throwable $e) {
        $errors[] = 'Błąd bazy: ' . $e->getMessage();
    }
}

// ── KROK 3: Dane organizacji ──────────────────────────────────────────────────

// AJAX — fetch KRS
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 3 && !empty($_POST['_krs_fetch'])) {
    header('Content-Type: application/json; charset=utf-8');
    if (!file_exists(__DIR__ . '/includes/krs.php')) {
        echo json_encode(['error' => 'Brak modułu KRS.']); exit;
    }
    require_once __DIR__ . '/includes/krs.php';
    $krs_nr = preg_replace('/\D/', '', $_POST['_krs_fetch']);
    echo json_encode(krs_lookup($krs_nr));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 3) {
    $org_name = trim($_POST['org_name'] ?? '');
    $org_krs  = preg_replace('/\D/', '', $_POST['org_krs'] ?? '');
    if (!$org_name) {
        $errors[] = 'Podaj pełną nazwę organizacji.';
    } else {
        $_SESSION['install_org'] = compact('org_name','org_krs');
        header('Location: install.php?step=4'); exit;
    }
}

// ── KROK 4: Microsoft OAuth — auto-skip jeśli klucze zachowane ──────────────
if ($step === 4 && $reinstall && !empty($preserved['m365_tenant_id']) && empty($_GET['ms_overwrite'])) {
    header('Location: install.php?step=5'); exit;
}

// Start PKCE — "Połącz przez Microsoft" (1-click)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 4 && !empty($_POST['ms_connect_pkce'])) {
    $client_id = trim($_POST['client_id'] ?? '');
    if (!$client_id) { $errors[] = 'Podaj Client ID z Azure App Registration.'; }
    else {
        $redirect_uri = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST']
                      . rtrim(dirname($_SERVER['PHP_SELF']), '/') . '/install_m365_callback.php';
        $verifier  = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $state     = bin2hex(random_bytes(16));

        $client_secret = trim($_POST['client_secret_opt'] ?? '');
        $_SESSION['m365_install_pkce_verifier']       = $verifier;
        $_SESSION['m365_install_pkce_state']          = $state;
        $_SESSION['m365_install_pkce_client_id']      = $client_id;
        $_SESSION['m365_install_pkce_client_secret']  = $client_secret;
        $_SESSION['reinstall_mode']                   = $reinstall;

        $params = http_build_query([
            'client_id'             => $client_id,
            'response_type'         => 'code',
            'redirect_uri'          => $redirect_uri,
            'response_mode'         => 'query',
            'scope'                 => 'openid profile email offline_access Directory.Read.All Organization.Read.All',
            'state'                 => $state,
            'code_challenge'        => $challenge,
            'code_challenge_method' => 'S256',
            'prompt'                => 'select_account',
        ]);
        header('Location: https://login.microsoftonline.com/common/oauth2/v2.0/authorize?' . $params);
        exit;
    }
}

// Ręczny formularz (stary sposób)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 4 && empty($_POST['ms_connect_pkce'])) {
    $_SESSION['install_ms'] = [
        'enabled'       => !empty($_POST['ms_enabled']),
        'tenant_id'     => trim($_POST['tenant_id']     ?? ''),
        'client_id'     => trim($_POST['client_id']     ?? ''),
        'client_secret' => trim($_POST['client_secret'] ?? ''),
    ];
    header('Location: install.php?step=5'); exit;
}

// ── KROK 5: E-mail — auto-skip jeśli klucze zachowane ───────────────────────
$_mail_preserved = !empty($preserved['smtp_host']) || !empty($preserved['m365_send_from_email']);
if ($step === 5 && $reinstall && $_mail_preserved && empty($_GET['mail_overwrite'])) {
    header('Location: install.php?step=6'); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 5) {
    $_SESSION['install_mail'] = [
        'type'       => in_array($_POST['mail_type'] ?? '', ['smtp','m365','php'], true) ? $_POST['mail_type'] : 'php',
        'smtp_host'  => trim($_POST['smtp_host']  ?? ''),
        'smtp_port'  => (int)($_POST['smtp_port'] ?? 587),
        'smtp_user'  => trim($_POST['smtp_user']  ?? ''),
        'smtp_pass'  => $_POST['smtp_pass']       ?? '',
        'smtp_from'  => trim($_POST['smtp_from']  ?? ''),
        'smtp_enc'   => in_array($_POST['smtp_enc'] ?? '', ['tls','ssl',''], true) ? $_POST['smtp_enc'] : 'tls',
        'm365_from'  => trim($_POST['m365_from']  ?? ''),
    ];
    header('Location: install.php?step=6'); exit;
}

// ── KROK 6: Moduły ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 6) {
    $all_modules = ['crm_enabled','tasks_enabled','ezd_enabled','events_enabled',
                    'certificates_enabled','onboarding_enabled','reports_enabled',
                    'approvals_enabled','procedures_enabled','letters_enabled',
                    'messages_enabled','resolutions_enabled','correspondence_enabled'];
    $enabled = [];
    foreach ($all_modules as $m) {
        $enabled[$m] = !empty($_POST[$m]) ? '1' : '0';
    }
    $_SESSION['install_modules'] = $enabled;
    header('Location: install.php?step=7'); exit;
}

// ── KROK 7: Konto admina + zapis ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 7) {
    $db_for_import_check = $_SESSION['install_db'] ?? [];
    $is_import   = !empty($db_for_import_check['imported']);

    $admin_name  = trim($_POST['admin_name']  ?? '');
    $admin_email = trim($_POST['admin_email'] ?? '');
    $admin_pass  = $_POST['admin_pass']  ?? '';
    $admin_pass2 = $_POST['admin_pass2'] ?? '';

    if (!$is_import) {
        if (!$admin_name)                                    $errors[] = 'Podaj imię i nazwisko.';
        if (!filter_var($admin_email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Nieprawidłowy adres e-mail.';
        if (strlen($admin_pass) < 8)                         $errors[] = 'Hasło musi mieć co najmniej 8 znaków.';
        if ($admin_pass !== $admin_pass2)                    $errors[] = 'Hasła nie są identyczne.';
    }

    if (!$errors) {
        $db  = $_SESSION['install_db']  ?? ['db_type' => 'sqlite'];
        $ms  = $_SESSION['install_ms']  ?? ['enabled' => false];
        $org = $_SESSION['install_org'] ?? ['org_name' => 'Organizacja', 'org_krs' => ''];

        try {
            $pdo = $db['db_type'] === 'sqlite'
                ? new PDO('sqlite:' . __DIR__ . '/umowy.db')
                : new PDO("mysql:host={$db['db_host']};port={$db['db_port']};dbname={$db['db_name']};charset=utf8mb4", $db['db_user'], $db['db_pass']);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // Schema — zawsze bezpieczne (CREATE TABLE IF NOT EXISTS), także dla
            // zaimportowanej bazy: dopełnia tabele dodane w nowszym kodzie.
            $schema = file_get_contents(__DIR__ . '/schema.sql');
            $schema = preg_replace('/--[^\n]*/', '', $schema);
            foreach (array_filter(array_map('trim', explode(';', $schema))) as $sql) {
                try { $pdo->exec($sql); } catch (PDOException $e) {}
            }

            if (!$is_import) {
                // Admin — TYLKO przy świeżej bazie; zaimportowana ma już konta.
                $pdo->prepare("INSERT INTO users (name,email,password,role,is_active) VALUES (?,?,?,'admin',1)")
                    ->execute([$admin_name, $admin_email, password_hash($admin_pass, PASSWORD_BCRYPT)]);
            }

            // Konto serwisowe — losowe hasło, bez ujawniania
            $serwis_exists = $pdo->prepare("SELECT id FROM users WHERE email='serwis@local'")->execute() && $pdo->query("SELECT id FROM users WHERE email='serwis@local'")->fetch();
            if (!$serwis_exists) {
                $pdo->prepare("INSERT INTO users (name,email,password,role,is_active) VALUES ('Konto serwisowe','serwis@local',?,'admin',0)")
                    ->execute([password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT)]);
            }

            // Org settings — TYLKO przy świeżej bazie; zaimportowana ma już swoje.
            $upsert = $db['db_type'] === 'sqlite'
                ? "INSERT OR REPLACE INTO settings (key_,value) VALUES (?,?)"
                : "INSERT INTO settings (key_,value) VALUES (?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)";
            if (!$is_import) {
                foreach (['org_name' => $org['org_name'], 'org_krs' => $org['org_krs']] as $k => $v) {
                    $pdo->prepare($upsert)->execute([$k, $v]);
                }
            }

            // config.php
            $app_key     = bin2hex(random_bytes(32));
            $ms_enabled  = $ms['enabled'] ? 'true' : 'false';
            $redirect_uri = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST']
                          . rtrim(dirname($_SERVER['PHP_SELF']), '/') . '/auth/microsoft.php';

            $cfg  = "<?php\n";
            $cfg .= "define('APP_INSTALLED', true);\n";
            $cfg .= "define('APP_KEY', '{$app_key}');\n";
            $cfg .= "define('ORG_NAME', " . var_export($org['org_name'], true) . ");\n\n";
            $cfg .= "define('DB_TYPE', '{$db['db_type']}');\n";
            if ($db['db_type'] === 'sqlite') {
                $cfg .= "define('DB_PATH', __DIR__ . '/umowy.db');\n";
            } else {
                $cfg .= "define('DB_HOST', " . var_export($db['db_host'], true) . ");\n";
                $cfg .= "define('DB_PORT', {$db['db_port']});\n";
                $cfg .= "define('DB_NAME', " . var_export($db['db_name'], true) . ");\n";
                $cfg .= "define('DB_USER', " . var_export($db['db_user'], true) . ");\n";
                $cfg .= "define('DB_PASS', " . var_export($db['db_pass'], true) . ");\n";
            }
            $cfg .= "\ndefine('MS_ENABLED', {$ms_enabled});\n";
            $cfg .= "define('MS_TENANT_ID', "     . var_export($ms['tenant_id']     ?? '', true) . ");\n";
            $cfg .= "define('MS_CLIENT_ID', "     . var_export($ms['client_id']     ?? '', true) . ");\n";
            $cfg .= "define('MS_CLIENT_SECRET', " . var_export($ms['client_secret'] ?? '', true) . ");\n";
            $cfg .= "define('MS_REDIRECT_URI', "  . var_export($redirect_uri, true) . ");\n";
            $cfg .= "\ndefine('UPLOAD_DIR', __DIR__ . '/uploads/');\n";
            $cfg .= "define('APP_URL', (function() {\n";
            $cfg .= "    \$s = (!empty(\$_SERVER['HTTPS']) && \$_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';\n";
            $cfg .= "    \$h = \$_SERVER['HTTP_HOST'] ?? 'localhost';\n";
            $cfg .= "    \$d = rtrim(str_replace('\\\\','/',realpath(\$_SERVER['DOCUMENT_ROOT']??'')),'/'); \n";
            $cfg .= "    \$a = rtrim(str_replace('\\\\','/',realpath(__DIR__)),'/'); \n";
            $cfg .= "    \$p = (\$d && str_starts_with(\$a,\$d)) ? substr(\$a,strlen(\$d)) : ''; \n";
            $cfg .= "    return rtrim(\$s.'://'.\$h.\$p,'/');\n";
            $cfg .= "})());\n";

            file_put_contents(__DIR__ . '/config.php', $cfg);

            // Przywróć zachowane klucze (reinstalacja)
            if (!empty($preserved)) {
                foreach ($preserved as $k => $v) {
                    $pdo->prepare($upsert)->execute([$k, $v]);
                }
            }
            unset($_SESSION['reinstall_mode']);

            // E-mail
            $mail = $_SESSION['install_mail'] ?? ['type' => 'php'];
            $mail_settings = [];
            if ($mail['type'] === 'smtp') {
                $mail_settings = ['smtp_host' => $mail['smtp_host'], 'smtp_port' => $mail['smtp_port'],
                    'smtp_user' => $mail['smtp_user'], 'smtp_pass' => $mail['smtp_pass'],
                    'smtp_from_email' => $mail['smtp_from'], 'smtp_encryption' => $mail['smtp_enc']];
            } elseif ($mail['type'] === 'm365') {
                $mail_settings = ['m365_send_from_email' => $mail['m365_from']];
            }
            foreach ($mail_settings as $k => $v) {
                $pdo->prepare($upsert)->execute([$k, $v]);
            }

            // Moduły
            $modules = $_SESSION['install_modules'] ?? [];
            foreach ($modules as $k => $v) {
                $pdo->prepare($upsert)->execute([$k, $v]);
            }

            // Utwórz plik blokady
            file_put_contents(INSTALL_LOCK_FILE, date('Y-m-d H:i:s'));

            header('Location: install.php?step=8'); exit;

        } catch (Exception $e) {
            $errors[] = 'Błąd instalacji: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Instalacja — Platforma NGO</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html,body{min-height:100vh;background:#f8fafc;font-family:system-ui,-apple-system,sans-serif;font-size:14px}

/* ── Layout split ── */
.ins-shell { display:flex; min-height:100vh; }

/* ── Sidebar ── */
.ins-sidebar {
  width:240px; flex-shrink:0;
  background:#1e293b; color:#cbd5e1;
  display:flex; flex-direction:column;
  padding:2rem 0;
}
.ins-brand { padding:0 1.5rem 2rem; border-bottom:1px solid #334155; }
.ins-brand-icon {
  width:42px; height:42px; background:#2563eb; border-radius:10px;
  display:flex; align-items:center; justify-content:center;
  font-size:1.2rem; color:#fff; margin-bottom:.75rem;
}
.ins-brand-name { font-size:.95rem; font-weight:700; color:#f1f5f9; }
.ins-brand-sub  { font-size:.72rem; color:#64748b; margin-top:.15rem; }

/* ── Steps nav ── */
.ins-steps { padding:1.25rem 0; flex:1; }
.ins-step {
  display:flex; align-items:center; gap:.75rem;
  padding:.55rem 1.5rem;
  font-size:.82rem; color:#64748b;
  position:relative;
}
.ins-step-num {
  width:24px; height:24px; border-radius:50%; flex-shrink:0;
  display:flex; align-items:center; justify-content:center;
  font-size:.72rem; font-weight:700;
  background:#334155; color:#64748b; border:2px solid #334155;
}
.ins-step.done .ins-step-num   { background:#2563eb; color:#fff; border-color:#2563eb; }
.ins-step.active .ins-step-num { background:#fff; color:#2563eb; border-color:#2563eb; }
.ins-step.active { color:#f1f5f9; font-weight:600; background:rgba(255,255,255,.04); }
.ins-step.done   { color:#94a3b8; }
.ins-step-connector {
  position:absolute; left:calc(1.5rem + 11px); top:100%;
  width:2px; height:calc(100% - 0px); background:#334155; z-index:0;
}
.ins-step.done .ins-step-connector { background:#2563eb; }

/* ── Sidebar footer ── */
.ins-sidebar-foot { padding:1rem 1.5rem; border-top:1px solid #334155; font-size:.72rem; color:#475569; }

/* ── Content ── */
.ins-content { flex:1; display:flex; align-items:flex-start; justify-content:center; padding:3rem 2rem; }
.ins-card {
  background:#fff; border-radius:.75rem;
  box-shadow:0 1px 16px rgba(0,0,0,.09);
  padding:2rem 2rem;
  width:100%; max-width:560px;
}
.ins-card h2 { font-size:1.1rem; font-weight:700; margin-bottom:.25rem; color:#0f172a; }
.ins-card .sub { font-size:.83rem; color:#64748b; margin-bottom:1.5rem; }

/* ── Checks ── */
.chk { display:flex; align-items:center; gap:.6rem; font-size:.85rem; padding:.3rem 0; }
.chk .ok   { color:#16a34a; }
.chk .err  { color:#dc2626; }
.chk .warn { color:#d97706; }

/* ── Success ── */
.ins-success-icon { font-size:3rem; color:#16a34a; text-align:center; display:block; margin-bottom:1rem; }
.next-step { display:flex; align-items:flex-start; gap:.75rem; padding:.65rem 0; border-bottom:1px solid #f1f5f9; }
.next-step:last-child { border:none; }
.next-num { width:22px; height:22px; border-radius:50%; background:#2563eb; color:#fff; font-size:.72rem; font-weight:700; display:flex; align-items:center; justify-content:center; flex-shrink:0; margin-top:.1rem; }
.next-step .title { font-size:.85rem; font-weight:600; }
.next-step .desc  { font-size:.78rem; color:#64748b; }

/* ── Mobile ── */
@media(max-width:768px){
  .ins-shell{flex-direction:column}
  .ins-sidebar{width:100%;flex-direction:row;flex-wrap:wrap;padding:1rem;gap:.5rem}
  .ins-brand{padding:0;border:none;display:flex;align-items:center;gap:.75rem;width:100%}
  .ins-brand-icon{width:30px;height:30px;font-size:.9rem;margin:0}
  .ins-steps{display:flex;flex-wrap:wrap;gap:.25rem;padding:.5rem 0;width:100%}
  .ins-step{padding:.3rem .6rem;font-size:.72rem;border-radius:4px}
  .ins-step-connector{display:none}
  .ins-sidebar-foot{display:none}
  .ins-content{padding:1.5rem 1rem}
}
</style>
</head>
<body>

<?php
$step_labels = [
  1 => ['Wymagania',    'bi-check2-circle'],
  2 => ['Baza danych',  'bi-database'],
  3 => ['Organizacja',  'bi-building'],
  4 => ['Microsoft',    'bi-microsoft'],
  5 => ['E-mail',       'bi-envelope'],
  6 => ['Moduły',       'bi-toggles'],
  7 => ['Administrator','bi-person-lock'],
  8 => ['Gotowe',       'bi-rocket-takeoff'],
];
$total_steps = count($step_labels);
?>

<div class="ins-shell">

<!-- ── Sidebar ── -->
<aside class="ins-sidebar">
  <div class="ins-brand">
    <div class="ins-brand-icon"><i class="bi bi-building-heart"></i></div>
    <div>
      <div class="ins-brand-name">Platforma NGO</div>
      <div class="ins-brand-sub">Kreator instalacji</div>
    </div>
  </div>

  <nav class="ins-steps" aria-label="Kroki instalacji">
    <?php foreach ($step_labels as $n => [$lbl, $icon]):
      $cls  = $n < $step ? 'done' : ($n === $step ? 'active' : '');
      $snum = $n < $step ? '<i class="bi bi-check2" style="font-size:.7rem"></i>' : $n;
      $last = $n === $total_steps;
    ?>
    <div class="ins-step <?= $cls ?>">
      <div class="ins-step-num"><?= $snum ?></div>
      <div>
        <i class="bi <?= $icon ?> me-1" style="font-size:.75rem;opacity:.6"></i><?= $lbl ?>
      </div>
      <?php if (!$last): ?><div class="ins-step-connector"></div><?php endif; ?>
    </div>
    <?php endforeach; ?>
  </nav>

  <div class="ins-sidebar-foot">
    Krok <?= $step ?> z <?= $total_steps ?>
    <?php try { require_once __DIR__ . '/includes/version.php'; $__iv = app_version(); echo '<br>v' . htmlspecialchars($__iv['main']); } catch(\Throwable $e){} ?>
  </div>
</aside>

<!-- ── Content ── -->
<main class="ins-content">
<div class="ins-card">

  <?php if ($errors): ?>
  <div class="alert alert-danger py-2 small mb-3"><ul class="mb-0 ps-3">
    <?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
  </ul></div>
  <?php endif; ?>

  <?php if ($errors): ?>
  <div class="alert alert-danger py-2 small"><ul class="mb-0 ps-3">
    <?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
  </ul></div>
  <?php endif; ?>

  <?php // ── KROK 1: Wymagania
  if ($step === 1):
    $exts   = ['pdo' => true, 'json' => true, 'mbstring' => true, 'openssl' => true, 'zip' => false];
    $sqlite = extension_loaded('pdo_sqlite');
    $mysql  = extension_loaded('pdo_mysql');
    $upload = is_writable(__DIR__ . '/uploads/');
    $all_ok = $upload && ($sqlite || $mysql);
  ?>
  <?php if ($reinstall && !empty($preserved)): ?>
  <div class="alert alert-success py-2 small mb-3">
    <i class="bi bi-shield-check me-1"></i>
    <strong>Tryb reinstalacji — wykryte istniejące klucze:</strong>
    <div class="mt-1 d-flex flex-wrap gap-1">
      <?php
      $detected = [];
      if (!empty($preserved['m365_tenant_id']))        $detected[] = ['Microsoft 365', 'bi-microsoft', 'success'];
      if (!empty($preserved['smtp_host']))              $detected[] = ['SMTP: '.$preserved['smtp_host'], 'bi-envelope', 'info'];
      if (!empty($preserved['m365_send_from_email']))  $detected[] = ['M365 e-mail', 'bi-envelope-fill', 'info'];
      if (!empty($preserved['sms_api_login']))          $detected[] = ['SMS API', 'bi-phone', 'warning'];
      if (!empty($preserved['cron_token']))             $detected[] = ['CRON token', 'bi-clock', 'secondary'];
      if (!empty($preserved['anthropic_api_key']))      $detected[] = ['Claude AI', 'bi-stars', 'primary'];
      foreach ($detected as $d): ?>
      <span class="badge bg-<?= $d[2] ?> bg-opacity-15 text-<?= $d[2] ?> border border-<?= $d[2] ?> border-opacity-25" style="font-size:.72rem">
        <i class="bi <?= $d[1] ?> me-1"></i><?= htmlspecialchars($d[0]) ?>
      </span>
      <?php endforeach; ?>
    </div>
    <div class="mt-1" style="color:#166534">Zostaną automatycznie przywrócone po reinstalacji.</div>
  </div>
  <?php elseif ($reinstall): ?>
  <div class="alert alert-warning py-2 small mb-3">
    <i class="bi bi-exclamation-triangle me-1"></i>
    <strong>Tryb reinstalacji</strong> — brak wykrytych kluczy do zachowania.
  </div>
  <?php endif; ?>
  <h2><?= $reinstall ? 'Reinstalacja platformy' : 'Wymagania systemowe' ?></h2>
  <p class="sub">Sprawdzenie środowiska przed instalacją.</p>

  <!-- Informacja o Microsoft 365 -->
  <div class="alert alert-primary d-flex align-items-start gap-2 py-2 mb-3 small">
    <i class="bi bi-microsoft flex-shrink-0 mt-1" style="font-size:1rem"></i>
    <div>
      <strong>Platforma NGO współdziała z Microsoft 365.</strong>
      Logowanie OAuth, synchronizacja kont i wysyłka e-mail przez M365 są <strong>zalecane do prawidłowego działania</strong>.
      Skonfiguruj konto M365 i zarejestruj aplikację w Azure AD przed instalacją lub w kroku 4 kreatora.
      <a href="https://portal.azure.com" target="_blank" class="alert-link">Azure Portal →</a>
    </div>
  </div>

  <?php foreach ($exts as $ext => $required): ?>
  <div class="chk">
    <?php $ok = extension_loaded($ext); ?>
    <i class="bi bi-<?= $ok ? 'check-circle-fill ok' : ($required ? 'x-circle-fill err' : 'dash-circle warn') ?>"></i>
    <span>PHP <code><?= $ext ?></code></span>
    <?php if (!$ok && !$required): ?><span class="text-muted small">(opcjonalne)</span><?php endif; ?>
  </div>
  <?php endforeach; ?>

  <div class="chk">
    <i class="bi bi-<?= $sqlite ? 'check-circle-fill ok' : 'dash-circle warn' ?>"></i>
    <span>PHP <code>pdo_sqlite</code><?= !$sqlite ? ' <span class="text-muted small">(brak — SQLite niedostępny)</span>' : '' ?></span>
  </div>
  <div class="chk">
    <i class="bi bi-<?= $mysql ? 'check-circle-fill ok' : 'dash-circle warn' ?>"></i>
    <span>PHP <code>pdo_mysql</code><?= !$mysql ? ' <span class="text-muted small">(brak — MySQL niedostępny)</span>' : '' ?></span>
  </div>
  <div class="chk mt-1">
    <i class="bi bi-<?= $upload ? 'check-circle-fill ok' : 'x-circle-fill err' ?>"></i>
    <span>Katalog <code>uploads/</code> zapisywalny<?= !$upload ? ' — <strong>wymagane: <code>chmod 755 uploads/</code></strong>' : '' ?></span>
  </div>

  <div class="mt-3">
    <a href="install.php?step=2" class="btn btn-primary w-100 <?= !$all_ok ? 'disabled' : '' ?>">
      Dalej <i class="bi bi-arrow-right ms-1"></i>
    </a>
    <?php if (!$all_ok): ?>
    <div class="form-text text-center mt-1">Rozwiąż problemy powyżej, a następnie odśwież stronę.</div>
    <?php endif; ?>
  </div>

  <?php // ── KROK 2: Baza
  elseif ($step === 2):
    $staged_exists = is_file(INSTALL_STAGED_DB_FILE);
    $staged_size   = $staged_exists ? round(filesize(INSTALL_STAGED_DB_FILE) / 1024 / 1024, 1) : 0;
  ?>
  <h2>Baza danych</h2>
  <p class="sub">SQLite jest zalecane dla większości instalacji — nie wymaga osobnego serwera.</p>

  <form method="post" enctype="multipart/form-data">
    <div class="mb-3">
      <div class="d-flex flex-column gap-2">
        <label class="border rounded p-3 d-flex align-items-start gap-3" style="cursor:pointer">
          <input type="radio" name="db_type" value="sqlite" checked onchange="toggleMysql(false)" style="margin-top:.2rem">
          <div>
            <div class="fw-semibold small">SQLite <span class="badge bg-primary ms-1" style="font-size:.65rem">Zalecane</span></div>
            <div class="text-muted" style="font-size:.78rem">Plik lokalny, zero konfiguracji</div>
          </div>
        </label>
        <label class="border rounded p-3 d-flex align-items-start gap-3" style="cursor:pointer">
          <input type="radio" name="db_type" value="mysql" onchange="toggleMysql(true)" style="margin-top:.2rem">
          <div>
            <div class="fw-semibold small">MySQL / MariaDB</div>
            <div class="text-muted" style="font-size:.78rem">Dla większych instalacji lub SaaS</div>
          </div>
        </label>
      </div>
    </div>

    <div id="sqlite_fields">
      <div class="mb-3">
        <label class="form-label small fw-semibold">Zawartość bazy</label>
        <div class="d-flex flex-column gap-2">
          <label class="border rounded p-2 d-flex align-items-start gap-2" style="cursor:pointer">
            <input type="radio" name="sqlite_mode" value="new" checked onchange="toggleImport(this.value)" style="margin-top:.2rem">
            <div>
              <div class="fw-semibold small">Nowa, pusta instalacja</div>
              <div class="text-muted" style="font-size:.78rem">Standardowy start od zera.</div>
            </div>
          </label>
          <?php if ($staged_exists): ?>
          <label class="border rounded p-2 d-flex align-items-start gap-2" style="cursor:pointer">
            <input type="radio" name="sqlite_mode" value="import_staged" onchange="toggleImport(this.value)" style="margin-top:.2rem">
            <div>
              <div class="fw-semibold small">Zaimportuj plik już wgrany na serwer <span class="badge bg-success ms-1" style="font-size:.65rem">wykryto</span></div>
              <div class="text-muted" style="font-size:.78rem">
                Znaleziono <code>umowy.import.db</code> (<?= $staged_size ?> MB) w katalogu aplikacji — np. wgrane wcześniej przez SSH/SCP.
              </div>
            </div>
          </label>
          <?php endif; ?>
          <label class="border rounded p-2 d-flex align-items-start gap-2" style="cursor:pointer">
            <input type="radio" name="sqlite_mode" value="import_upload" onchange="toggleImport(this.value)" style="margin-top:.2rem">
            <div>
              <div class="fw-semibold small">Prześlij plik bazy (.db) z tego komputera</div>
              <div class="text-muted" style="font-size:.78rem">Dla mniejszych baz — duże pliki mogą przekroczyć limit uploadu hostingu.</div>
            </div>
          </label>
        </div>
      </div>
      <div id="import_upload_field" class="mb-3" style="display:none">
        <label class="form-label small fw-semibold">Plik bazy (umowy.db)</label>
        <input type="file" name="db_import_file" accept=".db,.sqlite,.sqlite3" class="form-control form-control-sm">
      </div>
    </div>

    <div id="mysql_fields" style="display:none">
      <div class="row g-2 mb-2">
        <div class="col-8"><label class="form-label small fw-semibold">Host</label><input name="db_host" class="form-control form-control-sm" value="localhost"></div>
        <div class="col-4"><label class="form-label small fw-semibold">Port</label><input name="db_port" class="form-control form-control-sm" value="3306" type="number"></div>
      </div>
      <div class="mb-2"><label class="form-label small fw-semibold">Nazwa bazy</label><input name="db_name" class="form-control form-control-sm" value="umowy"></div>
      <div class="mb-2"><label class="form-label small fw-semibold">Użytkownik</label><input name="db_user" class="form-control form-control-sm"></div>
      <div class="mb-3"><label class="form-label small fw-semibold">Hasło</label><input name="db_pass" class="form-control form-control-sm" type="password"></div>
    </div>
    <button type="submit" class="btn btn-primary w-100">Testuj połączenie i kontynuuj <i class="bi bi-arrow-right ms-1"></i></button>
  </form>
  <script>
    function toggleMysql(v) {
      document.getElementById('mysql_fields').style.display = v ? '' : 'none';
      document.getElementById('sqlite_fields').style.display = v ? 'none' : '';
    }
    function toggleImport(mode) {
      document.getElementById('import_upload_field').style.display = mode === 'import_upload' ? '' : 'none';
    }
  </script>

  <?php // ── KROK 3: Organizacja
  elseif ($step === 3): ?>
  <h2>Dane organizacji</h2>
  <p class="sub">Będą widoczne w systemie, dokumentach i stopce e-maili.</p>

  <!-- Fetch KRS -->
  <div class="mb-3">
    <label class="form-label fw-semibold small">Numer KRS</label>
    <div class="d-flex gap-2">
      <input type="text" id="krs-input" class="form-control form-control-sm font-monospace"
             placeholder="0000000000" maxlength="10" pattern="\d{0,10}"
             value="<?= htmlspecialchars($_SESSION['install_org']['org_krs'] ?? '') ?>">
      <button type="button" class="btn btn-outline-primary btn-sm flex-shrink-0" onclick="fetchKRS()">
        <i class="bi bi-search me-1"></i>Zaciągnij z KRS
      </button>
    </div>
    <div id="krs-status" class="form-text"></div>
  </div>

  <!-- Podgląd danych z KRS -->
  <div id="krs-preview" class="border rounded p-3 mb-3 d-none" style="background:#f0fdf4;border-color:#bbf7d0!important;font-size:.82rem">
    <div class="fw-semibold small mb-2"><i class="bi bi-check-circle text-success me-1"></i>Dane pobrane z KRS</div>
    <table class="table table-sm table-borderless mb-2" style="font-size:.8rem">
      <tr><td class="text-muted py-0" style="width:120px">Nazwa</td><td id="kp-nazwa" class="fw-semibold py-0"></td></tr>
      <tr><td class="text-muted py-0">Forma prawna</td><td id="kp-forma" class="py-0"></td></tr>
      <tr><td class="text-muted py-0">NIP</td><td id="kp-nip" class="font-monospace py-0"></td></tr>
      <tr><td class="text-muted py-0">REGON</td><td id="kp-regon" class="font-monospace py-0"></td></tr>
      <tr><td class="text-muted py-0">Adres</td><td id="kp-adres" class="py-0"></td></tr>
      <tr><td class="text-muted py-0">Rejestr</td><td id="kp-rejestr" class="py-0"></td></tr>
    </table>
    <button type="button" class="btn btn-success btn-sm" onclick="applyKRS()">
      <i class="bi bi-check2 me-1"></i>Użyj tych danych
    </button>
  </div>

  <form method="post" id="org-form">
    <div class="mb-3">
      <label class="form-label fw-semibold small">Pełna nazwa organizacji <span class="text-danger">*</span></label>
      <input type="text" name="org_name" id="org-name" class="form-control"
             value="<?= htmlspecialchars($_SESSION['install_org']['org_name'] ?? '') ?>"
             placeholder="np. Fundacja Edukacji Empatii Rozwoju FEER" required maxlength="200">
    </div>
    <input type="hidden" name="org_krs" id="org-krs-hidden"
           value="<?= htmlspecialchars($_SESSION['install_org']['org_krs'] ?? '') ?>">
    <button type="submit" class="btn btn-primary w-100">Dalej <i class="bi bi-arrow-right ms-1"></i></button>
  </form>

  <script>
  var _krsData = null;

  function fetchKRS() {
    var krs = document.getElementById('krs-input').value.replace(/\D/g,'');
    if (!krs) { setKrsStatus('Wpisz numer KRS.','warn'); return; }
    setKrsStatus('Pobieranie danych…','info');
    document.getElementById('krs-preview').classList.add('d-none');

    var fd = new FormData();
    fd.append('_krs_fetch', krs);

    fetch('install.php?step=3', { method:'POST', body: fd })
      .then(r => r.json())
      .then(function(d) {
        if (d.error) { setKrsStatus('✗ ' + d.error, 'danger'); return; }
        _krsData = d;
        document.getElementById('kp-nazwa').textContent   = d.nazwa       || '—';
        document.getElementById('kp-forma').textContent   = d.forma_prawna|| '—';
        document.getElementById('kp-nip').textContent     = d.nip         || '—';
        document.getElementById('kp-regon').textContent   = d.regon       || '—';
        document.getElementById('kp-adres').textContent   = d.adres       || '—';
        document.getElementById('kp-rejestr').textContent = d.rejestr_label|| d.rejestr || '—';
        document.getElementById('krs-preview').classList.remove('d-none');
        setKrsStatus('✓ Znaleziono — sprawdź dane i kliknij „Użyj tych danych"','success');
      })
      .catch(function() { setKrsStatus('Błąd połączenia.','danger'); });
  }

  function applyKRS() {
    if (!_krsData) return;
    document.getElementById('org-name').value       = _krsData.nazwa || '';
    document.getElementById('krs-input').value      = _krsData.krs   || '';
    document.getElementById('org-krs-hidden').value = _krsData.krs   || '';
    setKrsStatus('✓ Dane wpisane do formularza','success');
  }

  function setKrsStatus(msg, type) {
    var el = document.getElementById('krs-status');
    el.textContent = msg;
    el.className = 'form-text ' + {info:'text-primary',success:'text-success',warn:'text-warning',danger:'text-danger'}[type] || 'text-muted';
  }

  // Synchronizuj KRS do hidden przy zmianie
  document.getElementById('krs-input').addEventListener('input', function() {
    document.getElementById('org-krs-hidden').value = this.value.replace(/\D/g,'');
  });
  </script>

  <?php // ── KROK 4: Microsoft
  elseif ($step === 4):
    $redirect_uri = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST']
                  . rtrim(dirname($_SERVER['PHP_SELF']), '/') . '/auth/microsoft.php';
    // Wykryj istniejące klucze M365
    $ms_existing = !empty($preserved['m365_tenant_id']) && !empty($preserved['m365_client_id']);
    $ms_tenant_short = $ms_existing ? substr($preserved['m365_tenant_id'], 0, 8) . '…' : '';
    $ms_keep = $ms_existing && empty($_GET['ms_overwrite']);
  ?>
  <h2>Logowanie Microsoft 365</h2>
  <p class="sub">Pozwala pracownikom i wolontariuszom logować się kontem Microsoft. Można skonfigurować później.</p>

  <?php if ($ms_existing && $ms_keep): ?>
  <!-- M365 już skonfigurowane — tylko info, bez ponownej konfiguracji -->
  <div class="border rounded p-3 mb-3" style="background:#f0fdf4;border-color:#bbf7d0!important">
    <div class="d-flex align-items-center gap-2 mb-1">
      <i class="bi bi-microsoft text-success"></i>
      <strong class="small">Microsoft 365 aktywny</strong>
    </div>
    <div class="small text-muted mb-1">Tenant: <code><?= htmlspecialchars($ms_tenant_short) ?></code> — logowanie OAuth skonfigurowane.</div>
    <div class="small text-muted">Konfiguracja zostanie zachowana. Nie musisz jej zmieniać.</div>
  </div>
  <div class="d-flex flex-column gap-2 mb-4">
    <a href="install.php?step=5" class="btn btn-success w-100">
      <i class="bi bi-check-lg me-1"></i>Dalej
    </a>
    <a href="install.php?step=4&ms_overwrite=1" class="btn btn-link w-100 text-muted" style="font-size:.82rem">
      Zmień konfigurację M365
    </a>
  </div>

  <?php else: ?>
  <?php if (!empty($_GET['ms_ok']) && !empty($_SESSION['install_ms']['autodetected'])): ?>
  <div class="alert alert-success py-2 small mb-3">
    <i class="bi bi-check-circle me-1"></i>
    <strong>Połączono!</strong> Tenant ID wykryty automatycznie.
    <?php if (!empty($_SESSION['install_ms']['org_name'])): ?>· <?= htmlspecialchars($_SESSION['install_ms']['org_name']) ?><?php endif; ?>
    <a href="install.php?step=5" class="btn btn-success btn-sm ms-2">Dalej →</a>
  </div>
  <?php endif; ?>

  <!-- 1-click: tylko Client ID -->
  <div class="card border-primary mb-3">
    <div class="card-body">
      <div class="fw-semibold small mb-1">
        <i class="bi bi-microsoft text-primary me-1"></i>Połącz przez Microsoft — automatyczna konfiguracja
      </div>
      <p class="text-muted mb-3" style="font-size:.78rem">
        Podaj tylko <strong>Client ID</strong> z Azure App Registration — Tenant ID i dane organizacji zostaną wykryte automatycznie po zalogowaniu. Nie potrzebujesz Client Secret.
      </p>
      <?php
      $install_redirect_uri = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST']
                            . rtrim(dirname($_SERVER['PHP_SELF']), '/') . '/install_m365_callback.php';
      ?>
      <div class="alert alert-light border small p-2 mb-3">
        <strong>URI przekierowania</strong> — dodaj w Azure Portal → App registrations → Authentication:<br>
        <code class="user-select-all" style="font-size:.73rem;word-break:break-all"><?= htmlspecialchars($install_redirect_uri) ?></code>
      </div>
      <form method="post">
        <input type="hidden" name="ms_connect_pkce" value="1">
        <div class="mb-2">
          <label class="form-label small fw-semibold">Client ID <span class="text-danger">*</span></label>
          <input type="text" name="client_id" class="form-control form-control-sm font-monospace"
                 value="<?= htmlspecialchars($_SESSION['install_ms']['client_id'] ?? '') ?>"
                 placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx" required>
        </div>
        <div class="mb-3">
          <label class="form-label small fw-semibold">
            Client Secret
            <span class="text-muted fw-normal">(wymagane jeśli aplikacja nie ma włączonego "Allow public client flows")</span>
          </label>
          <input type="password" name="client_secret_opt" class="form-control form-control-sm"
                 placeholder="Zostaw puste jeśli masz public client / PKCE bez secretu">
          <div class="form-text">
            Jeśli Azure zwróci błąd <code>AADSTS7000218</code> bez secretu: w Azure Portal → Authentication → włącz
            <strong>"Allow public client flows" = Yes</strong> albo wpisz Client Secret powyżej.
          </div>
        </div>
        <button type="submit" class="btn btn-primary w-100">
          <i class="bi bi-microsoft me-1"></i>Zaloguj się przez Microsoft
        </button>
      </form>
    </div>
  </div>

  <!-- Ręczna konfiguracja — zwinięta -->
  <details class="mb-3">
    <summary class="small text-muted" style="cursor:pointer">
      Mam Tenant ID i Client Secret — wpisz ręcznie
    </summary>
    <div class="pt-3">
      <form method="post">
        <div class="mb-2"><label class="form-label small fw-semibold">Tenant ID</label>
          <input name="tenant_id" class="form-control form-control-sm font-monospace"
                 value="<?= htmlspecialchars($_SESSION['install_ms']['tenant_id'] ?? '') ?>"
                 placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"></div>
        <div class="mb-2"><label class="form-label small fw-semibold">Client ID</label>
          <input name="client_id" class="form-control form-control-sm font-monospace"
                 value="<?= htmlspecialchars($_SESSION['install_ms']['client_id'] ?? '') ?>"
                 placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"></div>
        <div class="mb-3"><label class="form-label small fw-semibold">Client Secret</label>
          <input name="client_secret" class="form-control form-control-sm" type="password"
                 placeholder="<?= $ms_existing ? '(zostaw puste aby zachować)' : '' ?>"></div>
        <input type="hidden" name="ms_enabled" value="1">
        <button type="submit" class="btn btn-outline-secondary btn-sm">Zapisz ręcznie i dalej</button>
      </form>
    </div>
  </details>

  <a href="install.php?step=5" class="btn btn-link w-100 text-muted" style="font-size:.82rem">
    Pomiń — skonfiguruj Microsoft 365 później w panelu admina
  </a>
  <?php endif; ?>

  <?php // ── KROK 5: E-mail
  elseif ($step === 5):
    $ms = $_SESSION['install_ms'] ?? [];
    // M365 dostępne: skonfigurowane w tym kroku LUB zachowane z poprzedniej instalacji
    $m365_configured = (!empty($ms['enabled']) && !empty($ms['client_id']))
                    || !empty($preserved['m365_tenant_id']);
    // Wykryj istniejące klucze SMTP/M365 e-mail
    $smtp_existing = !empty($preserved['smtp_host']);
    $m365mail_existing = !empty($preserved['m365_send_from_email']);
    $mail_existing = $smtp_existing || $m365mail_existing;
    $mail_keep = $mail_existing && empty($_GET['mail_overwrite']);
  ?>
  <h2>Konfiguracja e-mail</h2>
  <p class="sub">System wysyła powiadomienia, dane logowania i kody SMS. Możesz skonfigurować później w panelu admina.</p>

  <?php if ($mail_existing && $mail_keep): ?>
  <div class="border rounded p-3 mb-3" style="background:#f0fdf4;border-color:#bbf7d0!important">
    <div class="d-flex align-items-center gap-2 mb-1">
      <i class="bi bi-envelope-check-fill text-success"></i>
      <strong class="small">E-mail skonfigurowany</strong>
    </div>
    <?php if ($smtp_existing): ?>
    <div class="small text-muted">SMTP: <code><?= htmlspecialchars($preserved['smtp_host']) ?></code>
      <?php if (!empty($preserved['smtp_from_email'])): ?> · Nadawca: <code><?= htmlspecialchars($preserved['smtp_from_email']) ?></code><?php endif; ?>
    </div>
    <?php elseif ($m365mail_existing): ?>
    <div class="small text-muted"><i class="bi bi-microsoft me-1"></i>Microsoft 365 · <code><?= htmlspecialchars($preserved['m365_send_from_email']) ?></code></div>
    <?php endif; ?>
    <div class="small text-muted mt-1">Konfiguracja zostanie zachowana.</div>
  </div>
  <div class="d-flex flex-column gap-2 mb-4">
    <a href="install.php?step=6" class="btn btn-success w-100">
      <i class="bi bi-check-lg me-1"></i>Dalej
    </a>
    <a href="install.php?step=5&mail_overwrite=1" class="btn btn-link w-100 text-muted" style="font-size:.82rem">
      Zmień konfigurację e-mail
    </a>
  </div>

  <?php else: ?>
  <form method="post">
    <div class="mb-3">
      <label class="form-label small fw-semibold">Metoda wysyłki</label>
      <div class="d-flex flex-column gap-2">
        <?php if ($m365_configured): ?>
        <label class="border rounded p-3 d-flex align-items-start gap-3" style="cursor:pointer">
          <input type="radio" name="mail_type" value="m365" checked onchange="switchMail('m365')" style="margin-top:.2rem">
          <div>
            <div class="fw-semibold small">
              <i class="bi bi-microsoft me-1 text-primary"></i>Microsoft 365
              <span class="badge bg-success ms-1" style="font-size:.65rem">Zalecane — logowanie M365 aktywne</span>
            </div>
            <div class="text-muted" style="font-size:.78rem">Wysyłka przez ten sam tenant co logowanie OAuth</div>
          </div>
        </label>
        <?php endif; ?>
        <label class="border rounded p-3 d-flex align-items-start gap-3" style="cursor:pointer">
          <input type="radio" name="mail_type" value="smtp" <?= !$m365_configured ? 'checked' : '' ?> onchange="switchMail('smtp')" style="margin-top:.2rem">
          <div>
            <div class="fw-semibold small">SMTP</div>
            <div class="text-muted" style="font-size:.78rem">Serwer pocztowy (Gmail, własny SMTP)</div>
          </div>
        </label>
        <label class="border rounded p-3 d-flex align-items-start gap-3" style="cursor:pointer">
          <input type="radio" name="mail_type" value="php" onchange="switchMail('php')" style="margin-top:.2rem">
          <div>
            <div class="fw-semibold small">PHP mail()</div>
            <div class="text-muted" style="font-size:.78rem">Wbudowana funkcja PHP — często blokowana przez hostingi</div>
          </div>
        </label>
      </div>
    </div>
    <div id="mail-smtp" style="display:<?= !$m365_configured ? '' : 'none' ?>">
      <div class="row g-2 mb-2">
        <div class="col-8"><label class="form-label small fw-semibold">Serwer SMTP</label><input name="smtp_host" class="form-control form-control-sm" value="<?= htmlspecialchars($preserved['smtp_host'] ?? '') ?>" placeholder="smtp.gmail.com"></div>
        <div class="col-4"><label class="form-label small fw-semibold">Port</label><input name="smtp_port" class="form-control form-control-sm" value="<?= htmlspecialchars($preserved['smtp_port'] ?? '587') ?>" type="number"></div>
      </div>
      <div class="row g-2 mb-2">
        <div class="col-6"><label class="form-label small fw-semibold">Login</label><input name="smtp_user" class="form-control form-control-sm" type="email" value="<?= htmlspecialchars($preserved['smtp_user'] ?? '') ?>"></div>
        <div class="col-6"><label class="form-label small fw-semibold">Hasło <?= !empty($preserved['smtp_pass']) ? '<span class="text-muted fw-normal">(zostaw puste aby zachować)</span>' : '' ?></label><input name="smtp_pass" class="form-control form-control-sm" type="password"></div>
      </div>
      <div class="row g-2 mb-3">
        <div class="col-8"><label class="form-label small fw-semibold">Adres nadawcy</label><input name="smtp_from" class="form-control form-control-sm" type="email" value="<?= htmlspecialchars($preserved['smtp_from_email'] ?? '') ?>" placeholder="noreply@organizacja.pl"></div>
        <div class="col-4"><label class="form-label small fw-semibold">Szyfrowanie</label>
          <select name="smtp_enc" class="form-select form-select-sm">
            <option value="tls" <?= ($preserved['smtp_encryption'] ?? '') === 'tls' ? 'selected' : '' ?>>STARTTLS</option>
            <option value="ssl" <?= ($preserved['smtp_encryption'] ?? '') === 'ssl' ? 'selected' : '' ?>>SSL</option>
            <option value="" <?= ($preserved['smtp_encryption'] ?? '') === '' ? 'selected' : '' ?>>Brak</option>
          </select>
        </div>
      </div>
    </div>
    <div id="mail-m365" style="display:<?= $m365_configured ? '' : 'none' ?>">
      <div class="mb-3">
        <label class="form-label small fw-semibold">Adres e-mail nadawcy (skrzynka M365)</label>
        <input name="m365_from" class="form-control form-control-sm" type="email" value="<?= htmlspecialchars($preserved['m365_send_from_email'] ?? '') ?>" placeholder="noreply@organizacja.pl">
      </div>
    </div>
    <button type="submit" class="btn btn-primary w-100">Dalej <i class="bi bi-arrow-right ms-1"></i></button>
    <a href="install.php?step=6" class="btn btn-link w-100 text-muted mt-1" style="font-size:.83rem">Pomiń — skonfiguruj później</a>
  </form>
  <script>
  function switchMail(t) {
    document.getElementById('mail-smtp').style.display = t==='smtp' ? '' : 'none';
    document.getElementById('mail-m365').style.display = t==='m365' ? '' : 'none';
  }
  </script>
  <?php endif; ?>

  <?php // ── KROK 6: Moduły
  elseif ($step === 6):
    $module_groups = [
      'Zarządzanie' => [
        ['key'=>'tasks_enabled',       'icon'=>'bi-kanban',              'label'=>'Tablica zadań (Kanban)',       'default'=>true],
        ['key'=>'approvals_enabled',   'icon'=>'bi-check2-square',       'label'=>'Obieg akceptacji',             'default'=>true],
        ['key'=>'procedures_enabled',  'icon'=>'bi-journal-bookmark-fill','label'=>'Procedury wewnętrzne',        'default'=>false],
        ['key'=>'resolutions_enabled', 'icon'=>'bi-hammer',              'label'=>'Uchwały i zarządzenia',        'default'=>false],
      ],
      'Komunikacja i ludzie' => [
        ['key'=>'crm_enabled',         'icon'=>'bi-diagram-2',           'label'=>'CRM — kontakty i komunikacja', 'default'=>true],
        ['key'=>'messages_enabled',    'icon'=>'bi-chat-dots',           'label'=>'Wiadomości wewnętrzne',        'default'=>true],
        ['key'=>'onboarding_enabled',  'icon'=>'bi-person-plus',         'label'=>'Zgłoszenia wolontariuszy',     'default'=>true],
        ['key'=>'events_enabled',      'icon'=>'bi-calendar-event',      'label'=>'Moduł wydarzeń',               'default'=>false],
      ],
      'Dokumenty i raporty' => [
        ['key'=>'letters_enabled',     'icon'=>'bi-envelope-paper',      'label'=>'Pisma i korespondencja',       'default'=>true],
        ['key'=>'certificates_enabled','icon'=>'bi-award',               'label'=>'Zaświadczenia',                'default'=>true],
        ['key'=>'ezd_enabled',         'icon'=>'bi-building-gear',       'label'=>'Wirtualne biurko',               'default'=>false],
        ['key'=>'reports_enabled',     'icon'=>'bi-bar-chart-line',      'label'=>'Zestawienia i raporty',        'default'=>true],
        ['key'=>'correspondence_enabled','icon'=>'bi-mailbox2',          'label'=>'Rejestr korespondencji',       'default'=>false],
      ],
    ];
    $saved_modules = $_SESSION['install_modules'] ?? [];
  ?>
  <h2>Aktywne moduły</h2>
  <p class="sub">Wybierz funkcje do uruchomienia. Pozostałe możesz włączyć w panelu admina w dowolnym momencie.</p>

  <form method="post">
    <?php foreach ($module_groups as $gname => $mods): ?>
    <div class="mb-3">
      <div class="text-muted" style="font-size:.72rem;text-transform:uppercase;letter-spacing:.07em;font-weight:600;margin-bottom:.5rem"><?= $gname ?></div>
      <?php foreach ($mods as $m):
        $checked = isset($saved_modules[$m['key']]) ? $saved_modules[$m['key']] === '1' : $m['default'];
      ?>
      <div class="form-check d-flex align-items-center gap-2 py-1 border-bottom" style="padding-left:0">
        <input type="checkbox" name="<?= $m['key'] ?>" id="<?= $m['key'] ?>" class="form-check-input ms-0 flex-shrink-0" <?= $checked ? 'checked' : '' ?> style="margin-top:0">
        <label for="<?= $m['key'] ?>" class="form-check-label d-flex align-items-center gap-2 w-100" style="cursor:pointer">
          <i class="bi <?= $m['icon'] ?> text-primary" style="font-size:.9rem;width:18px;text-align:center"></i>
          <span style="font-size:.85rem"><?= $m['label'] ?></span>
        </label>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endforeach; ?>

    <button type="submit" class="btn btn-primary w-100 mt-2">Dalej <i class="bi bi-arrow-right ms-1"></i></button>
  </form>

  <?php // ── KROK 7: Administrator
  elseif ($step === 7):
    $is_import_step7 = !empty($_SESSION['install_db']['imported']);
  ?>
  <?php if ($is_import_step7): ?>
  <h2>Zaimportowana baza</h2>
  <p class="sub">Baza zawiera już konta użytkowników — nie zakładamy nowego admina, żeby nic nie zdublować.</p>
  <div class="alert alert-info small">
    <i class="bi bi-info-circle-fill me-1"></i>
    Zaloguj się po instalacji istniejącym kontem administratora z zaimportowanej bazy.
  </div>
  <form method="post">
    <button type="submit" class="btn btn-success w-100 fw-semibold">
      <i class="bi bi-check-lg me-1"></i>Zakończ instalację
    </button>
  </form>
  <?php else: ?>
  <h2>Konto administratora</h2>
  <p class="sub">Pierwsze konto do zarządzania systemem. Możesz dodać więcej użytkowników po instalacji.</p>

  <form method="post">
    <div class="mb-2">
      <label class="form-label small fw-semibold">Imię i nazwisko <span class="text-danger">*</span></label>
      <input type="text" name="admin_name" class="form-control" required>
    </div>
    <div class="mb-2">
      <label class="form-label small fw-semibold">Adres e-mail <span class="text-danger">*</span></label>
      <input type="email" name="admin_email" class="form-control" required>
    </div>
    <div class="mb-2">
      <label class="form-label small fw-semibold">Hasło <span class="text-danger">*</span> <span class="text-muted fw-normal">(min. 8 znaków)</span></label>
      <input type="password" name="admin_pass" class="form-control" required minlength="8">
    </div>
    <div class="mb-4">
      <label class="form-label small fw-semibold">Powtórz hasło <span class="text-danger">*</span></label>
      <input type="password" name="admin_pass2" class="form-control" required>
    </div>
    <button type="submit" class="btn btn-success w-100 fw-semibold">
      <i class="bi bi-check-lg me-1"></i>Zainstaluj Platformę NGO
    </button>
  </form>
  <?php endif; ?>

  <?php // ── KROK 8: Gotowe
  elseif ($step === 8):
    $inst_url    = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST']
                 . rtrim(dirname($_SERVER['PHP_SELF']), '/');
    $creator_url = $inst_url . '/creator.php';

    // Test wysyłki e-mail
    $mail_test_result = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['test_email'])) {
        $to = filter_var(trim($_POST['test_email']), FILTER_VALIDATE_EMAIL);
        if ($to) {
            try {
                require_once __DIR__ . '/config.php';
                require_once __DIR__ . '/includes/db.php';
                require_once __DIR__ . '/includes/mail_queue.php';
                $mid = mail_queue_add($to, '', 'Test instalacji — Platforma NGO',
                    '<p>Wiadomość testowa z kreatora instalacji Platformy NGO.<br>E-mail działa poprawnie.</p>',
                    'Wiadomość testowa z kreatora instalacji Platformy NGO. E-mail działa poprawnie.');
                $mail_test_result = ['ok' => (bool)$mid, 'to' => $to,
                    'msg' => $mid ? "Wiadomość wysłana na {$to}." : 'Błąd kolejki — sprawdź konfigurację e-mail.'];
            } catch (\Throwable $e) {
                $mail_test_result = ['ok' => false, 'to' => $to, 'msg' => 'Błąd: ' . $e->getMessage()];
            }
        } else {
            $mail_test_result = ['ok' => false, 'to' => '', 'msg' => 'Nieprawidłowy adres e-mail.'];
        }
    }
  ?>
  <div class="text-center mb-3">
    <i class="bi bi-check-circle-fill ins-success-icon"></i>
    <h2 style="font-size:1.25rem">Instalacja zakończona!</h2>
    <p class="text-muted small">Platforma NGO jest gotowa. Wyślij poniższe dane twórcy, a następnie wykonaj kolejne kroki.</p>
  </div>

  <!-- Test wysyłki e-mail -->
  <div class="border rounded p-3 mb-3">
    <div class="fw-semibold small mb-2"><i class="bi bi-envelope-check me-1 text-primary"></i>Test wysyłki e-mail</div>
    <?php if ($mail_test_result): ?>
    <div class="alert <?= $mail_test_result['ok'] ? 'alert-success' : 'alert-danger' ?> py-2 small mb-2">
      <?= $mail_test_result['ok'] ? '✓' : '✗' ?> <?= htmlspecialchars($mail_test_result['msg']) ?>
    </div>
    <?php endif; ?>
    <form method="post" class="d-flex gap-2">
      <input type="email" name="test_email" class="form-control form-control-sm flex-grow-1"
             placeholder="adres@domena.pl — wyślij wiadomość testową" required>
      <button type="submit" class="btn btn-outline-primary btn-sm flex-shrink-0">Wyślij test</button>
    </form>
    <div class="form-text mt-1">Sprawdź czy konfiguracja e-mail działa przed uruchomieniem systemu.</div>
  </div>

  <!-- Blok Creator Panel — główna akcja -->
  <div class="border rounded p-3 mb-3" style="background:#eff6ff;border-color:#bfdbfe!important">
    <div class="d-flex align-items-start gap-2 mb-2">
      <i class="bi bi-shield-lock-fill text-primary mt-1" style="font-size:1.1rem"></i>
      <div>
        <div class="fw-bold small">Panel twórcy — aktywacja licencji</div>
        <div class="text-muted" style="font-size:.78rem">
          Hasło zna twórca. Możliwe też zdalne wgranie licencji przez License Manager.
        </div>
      </div>
    </div>

    <div class="d-flex gap-2 align-items-stretch mb-2">
      <code class="flex-grow-1 px-2 py-1 rounded small" id="creator-url-val"
            style="background:#1e293b;color:#7dd3fc;display:block;word-break:break-all">
        <?= htmlspecialchars($creator_url) ?>
      </code>
      <button class="btn btn-sm btn-primary flex-shrink-0"
              onclick="navigator.clipboard.writeText(<?= json_encode($creator_url) ?>);this.textContent='✓';setTimeout(()=>this.textContent='Kopiuj',1500)">
        Kopiuj
      </button>
    </div>

    <div class="alert alert-warning py-2 mb-0" style="font-size:.78rem">
      <i class="bi bi-exclamation-triangle me-1"></i>
      <strong>Zmiana adresu URL instalacji wymaga ponownego wystawienia licencji.</strong>
      Certyfikat jest powiązany z APP_KEY, a APP_KEY z konkretną instalacją — nie z domeną.
      Jeśli instalacja zostanie przeniesiona na inny adres, twórca musi wygenerować nowy certyfikat.
    </div>
  </div>

  <!-- Kolejne kroki -->
  <div class="mb-3">
    <div class="next-step">
      <div class="next-num">1</div>
      <div>
        <div class="title">Aktywuj licencję w Creator Panel</div>
        <div class="desc">
          Twórca loguje się na <code>creator.php</code> (zna hasło) i generuje certyfikat instalacyjny.
          Alternatywnie wgrywa certyfikat zdalnie przez License Manager.
          <strong>Bez certyfikatu aplikacja jest zablokowana.</strong>
        </div>
      </div>
    </div>
    <div class="next-step">
      <div class="next-num">2</div>
      <div>
        <div class="title">Zmień hasło twórcy</div>
        <div class="desc">Creator Panel → sekcja <em>creator.password</em> → ustaw indywidualne hasło dla tej instalacji.</div>
      </div>
    </div>
    <div class="next-step">
      <div class="next-num">3</div>
      <div>
        <div class="title">Skonfiguruj CRON</div>
        <div class="desc">Admin → Konfiguracja CRON → wygeneruj token → dodaj zadanie w DirectAdmin co minutę.</div>
      </div>
    </div>
    <div class="next-step">
      <div class="next-num">4</div>
      <div>
        <div class="title">Uzupełnij dane i wyczyść dane testowe</div>
        <div class="desc">Admin → Dane organizacji → logo, kolory. Przed startem produkcyjnym: Admin → Czyszczenie przed wdrożeniem.</div>
      </div>
    </div>
    <div class="next-step">
      <div class="next-num">5</div>
      <div>
        <div class="title">Usuń lub zablokuj <code>install.php</code></div>
        <div class="desc">Plik instalacyjny powinien być niedostępny publicznie. Usuń go przez FTP lub dodaj regułę w <code>.htaccess</code>.</div>
      </div>
    </div>
  </div>

  <a href="creator.php" class="btn btn-primary w-100 mb-2">
    <i class="bi bi-shield-check me-1"></i>Otwórz Creator Panel
  </a>
  <a href="index.php" class="btn btn-outline-secondary w-100" style="font-size:.85rem">
    Przejdź do systemu (wymaga aktywnej licencji)
  </a>

  <?php endif; ?>

</div><!-- /ins-card -->
</main>
</div><!-- /ins-shell -->
</body>
</html>
