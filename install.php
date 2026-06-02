<?php
/**
 * Kreator instalacji — Rejestr Umów Fundacji
 */
define('INSTALL_MODE', true);
session_start();

$step = $_GET['step'] ?? 1;
$errors = [];
$success = '';

// Sprawdź czy już zainstalowano
if (file_exists(__DIR__ . '/config.php') && $step < 5) {
    require_once __DIR__ . '/config.php';
    if (defined('APP_INSTALLED') && APP_INSTALLED) {
        header('Location: index.php');
        exit;
    }
}

// ─── KROK 2: Zapis konfiguracji DB ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step == 2) {
    $db_type    = $_POST['db_type'] ?? 'sqlite';
    $db_host    = trim($_POST['db_host'] ?? 'localhost');
    $db_name    = trim($_POST['db_name'] ?? 'umowy');
    $db_user    = trim($_POST['db_user'] ?? '');
    $db_pass    = $_POST['db_pass'] ?? '';
    $db_port    = intval($_POST['db_port'] ?? 3306);

    // Test połączenia
    try {
        if ($db_type === 'sqlite') {
            $pdo = new PDO('sqlite:' . __DIR__ . '/umowy.db');
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } else {
            $dsn = "mysql:host={$db_host};port={$db_port};dbname={$db_name};charset=utf8mb4";
            $pdo = new PDO($dsn, $db_user, $db_pass);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        }
        $_SESSION['install_db'] = compact('db_type','db_host','db_name','db_user','db_pass','db_port');
        header('Location: install.php?step=3');
        exit;
    } catch (PDOException $e) {
        $errors[] = 'Błąd połączenia z bazą: ' . $e->getMessage();
    }
}

// ─── KROK 3: Konfiguracja Microsoft OAuth ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step == 3) {
    $_SESSION['install_ms'] = [
        'tenant_id'     => trim($_POST['tenant_id'] ?? ''),
        'client_id'     => trim($_POST['client_id'] ?? ''),
        'client_secret' => trim($_POST['client_secret'] ?? ''),
        'enabled'       => !empty($_POST['ms_enabled']),
    ];
    header('Location: install.php?step=4');
    exit;
}

// ─── KROK 4: Konto administratora ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step == 4) {
    $admin_name  = trim($_POST['admin_name'] ?? '');
    $admin_email = trim($_POST['admin_email'] ?? '');
    $admin_pass  = $_POST['admin_pass'] ?? '';
    $admin_pass2 = $_POST['admin_pass2'] ?? '';
    $org_name    = trim($_POST['org_name'] ?? 'Fundacja');

    if (!$admin_name)  $errors[] = 'Podaj imię i nazwisko administratora.';
    if (!filter_var($admin_email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Nieprawidłowy adres e-mail.';
    if (strlen($admin_pass) < 8) $errors[] = 'Hasło musi mieć co najmniej 8 znaków.';
    if ($admin_pass !== $admin_pass2) $errors[] = 'Hasła nie są identyczne.';

    if (!$errors) {
        // Buduj bazę i zapisz config
        $db   = $_SESSION['install_db'];
        $ms    = $_SESSION['install_ms'] ?? ['enabled' => false];

        try {
            if ($db['db_type'] === 'sqlite') {
                $pdo = new PDO('sqlite:' . __DIR__ . '/umowy.db');
            } else {
                $dsn = "mysql:host={$db['db_host']};port={$db['db_port']};dbname={$db['db_name']};charset=utf8mb4";
                $pdo = new PDO($dsn, $db['db_user'], $db['db_pass']);
            }
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // Uruchom schemat
            $schema = file_get_contents(__DIR__ . '/schema.sql');
            $schema = preg_replace('/--[^\n]*/', '', $schema);
            $statements = array_filter(array_map('trim', explode(';', $schema)));
            foreach ($statements as $sql) {
                if (trim($sql)) {
                    try { $pdo->exec($sql); } catch (PDOException $e) { /* ignoruj IF NOT EXISTS duplikaty */ }
                }
            }

            // Dodaj admina
            $hash = password_hash($admin_pass, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare("INSERT INTO users (name, email, password, role, is_active) VALUES (?, ?, ?, 'admin', 1)");
            $stmt->execute([$admin_name, $admin_email, $hash]);

            // Zapisz nazwę organizacji (kompatybilne SQLite i MySQL)
            if ($db['db_type'] === 'sqlite') {
                $pdo->prepare("INSERT OR REPLACE INTO settings (key_, value) VALUES ('org_name', ?)")->execute([$org_name]);
            } else {
                $pdo->prepare("INSERT INTO settings (key_, value) VALUES ('org_name', ?) ON DUPLICATE KEY UPDATE value=?")->execute([$org_name, $org_name]);
            }

            // Generuj config.php
            $app_key = bin2hex(random_bytes(32));
            $ms_enabled = $ms['enabled'] ? 'true' : 'false';
            $redirect_uri = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST']
                          . rtrim(dirname($_SERVER['PHP_SELF']), '/') . '/auth/microsoft.php';

            $config = "<?php\n";
            $config .= "define('APP_INSTALLED', true);\n";
            $config .= "define('APP_KEY', '{$app_key}');\n";
            $config .= "define('ORG_NAME', " . var_export($org_name, true) . ");\n\n";
            $config .= "// Baza danych\n";
            $config .= "define('DB_TYPE', '{$db['db_type']}');\n";
            if ($db['db_type'] === 'sqlite') {
                $config .= "define('DB_PATH', __DIR__ . '/umowy.db');\n";
            } else {
                $config .= "define('DB_HOST', " . var_export($db['db_host'], true) . ");\n";
                $config .= "define('DB_PORT', {$db['db_port']});\n";
                $config .= "define('DB_NAME', " . var_export($db['db_name'], true) . ");\n";
                $config .= "define('DB_USER', " . var_export($db['db_user'], true) . ");\n";
                $config .= "define('DB_PASS', " . var_export($db['db_pass'], true) . ");\n";
            }
            $config .= "\n// Microsoft OAuth\n";
            $config .= "define('MS_ENABLED', {$ms_enabled});\n";
            $config .= "define('MS_TENANT_ID', " . var_export($ms['tenant_id'] ?? '', true) . ");\n";
            $config .= "define('MS_CLIENT_ID', " . var_export($ms['client_id'] ?? '', true) . ");\n";
            $config .= "define('MS_CLIENT_SECRET', " . var_export($ms['client_secret'] ?? '', true) . ");\n";
            $config .= "define('MS_REDIRECT_URI', " . var_export($redirect_uri, true) . ");\n";
            $config .= "\n// Ścieżki\n";
            $config .= "define('UPLOAD_DIR', __DIR__ . '/uploads/');\n";
            $config .= "define('APP_URL', (function() {\n";
            $config .= "    \$scheme  = (!empty(\$_SERVER['HTTPS']) && \$_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';\n";
            $config .= "    \$host    = \$_SERVER['HTTP_HOST'] ?? 'localhost';\n";
            $config .= "    \$docRoot = rtrim(str_replace('\\\\', '/', realpath(\$_SERVER['DOCUMENT_ROOT'] ?? '')), '/');\n";
            $config .= "    \$appDir  = rtrim(str_replace('\\\\', '/', realpath(__DIR__)), '/');\n";
            $config .= "    \$path    = (\$docRoot && str_starts_with(\$appDir, \$docRoot)) ? substr(\$appDir, strlen(\$docRoot)) : '';\n";
            $config .= "    return rtrim(\$scheme . '://' . \$host . \$path, '/');\n";
            $config .= "})());\n";

            file_put_contents(__DIR__ . '/config.php', $config);

            header('Location: install.php?step=5');
            exit;
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
<title>Instalacja — Rejestr Umów</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
body{background:#f0f4f8}
.install-card{max-width:600px;margin:60px auto}
.step-badge{width:36px;height:36px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-weight:700;font-size:.9rem}
.step-done{background:#198754;color:#fff}
.step-active{background:#0d6efd;color:#fff}
.step-todo{background:#dee2e6;color:#6c757d}
</style>
</head>
<body>
<div class="install-card">
  <div class="text-center mb-4">
    <h3 class="fw-bold"><i class="bi bi-file-earmark-text"></i> Rejestr Umów</h3>
    <p class="text-muted">Kreator instalacji</p>
  </div>

  <!-- Pasek kroków -->
  <div class="d-flex justify-content-between align-items-center mb-4 px-2">
    <?php
    $steps = ['Start','Baza danych','Microsoft','Administrator','Gotowe'];
    foreach ($steps as $i => $label):
        $n = $i+1;
        $cls = $n < $step ? 'step-done' : ($n == $step ? 'step-active' : 'step-todo');
        $icon = $n < $step ? '<i class="bi bi-check"></i>' : $n;
    ?>
    <div class="text-center" style="flex:1">
      <div class="step-badge <?= $cls ?> mx-auto"><?= $icon ?></div>
      <div class="small mt-1 <?= $n==$step?'fw-bold':'' ?>"><?= $label ?></div>
    </div>
    <?php if ($i < 4): ?><div style="flex:.5;height:2px;background:#dee2e6;margin-top:-18px"></div><?php endif; ?>
    <?php endforeach; ?>
  </div>

  <div class="card shadow-sm">
    <div class="card-body p-4">

    <?php if ($errors): ?>
      <div class="alert alert-danger"><ul class="mb-0"><?php foreach($errors as $e) echo "<li>$e</li>"; ?></ul></div>
    <?php endif; ?>

    <!-- ── KROK 1: Start ── -->
    <?php if ($step == 1): ?>
      <h5 class="mb-3">Witaj w kreatorze instalacji</h5>
      <p>Zanim zaczniesz, upewnij się że:</p>
      <ul>
        <li>PHP 8.1+ z rozszerzeniami: <code>pdo</code>, <code>pdo_sqlite</code> lub <code>pdo_mysql</code>, <code>json</code>, <code>mbstring</code></li>
        <li>Katalog <code>uploads/</code> jest zapisywalny przez serwer</li>
        <li>Plik <code>config.php</code> będzie tworzony automatycznie</li>
      </ul>
      <?php
        $exts = ['pdo','json','mbstring'];
        foreach ($exts as $ext): ?>
        <div class="d-flex align-items-center gap-2 mb-1">
          <?= extension_loaded($ext) ? '<i class="bi bi-check-circle-fill text-success"></i>' : '<i class="bi bi-x-circle-fill text-danger"></i>' ?>
          <span><?= $ext ?></span>
        </div>
      <?php endforeach;
        $sqlite_ok = extension_loaded('pdo_sqlite');
        $mysql_ok  = extension_loaded('pdo_mysql');
      ?>
      <div class="d-flex align-items-center gap-2 mb-1">
        <?= $sqlite_ok ? '<i class="bi bi-check-circle-fill text-success"></i>' : '<i class="bi bi-dash-circle text-warning"></i>' ?>
        pdo_sqlite <?= $sqlite_ok ? '' : '(brak — SQLite niedostępny)' ?>
      </div>
      <div class="d-flex align-items-center gap-2 mb-1">
        <?= $mysql_ok ? '<i class="bi bi-check-circle-fill text-success"></i>' : '<i class="bi bi-dash-circle text-warning"></i>' ?>
        pdo_mysql <?= $mysql_ok ? '' : '(brak — MySQL niedostępny)' ?>
      </div>
      <div class="d-flex align-items-center gap-2 mb-3">
        <?= is_writable(__DIR__ . '/uploads/') ? '<i class="bi bi-check-circle-fill text-success"></i>' : '<i class="bi bi-x-circle-fill text-danger"></i>' ?>
        Katalog uploads/ zapisywalny
      </div>
      <a href="install.php?step=2" class="btn btn-primary w-100">Dalej <i class="bi bi-arrow-right"></i></a>

    <!-- ── KROK 2: Baza ── -->
    <?php elseif ($step == 2): ?>
      <h5 class="mb-3">Konfiguracja bazy danych</h5>
      <form method="post">
        <div class="mb-3">
          <label class="form-label fw-semibold">Typ bazy danych</label>
          <div class="form-check">
            <input class="form-check-input" type="radio" name="db_type" id="sqlite" value="sqlite" checked onchange="toggleMysql(false)">
            <label class="form-check-label" for="sqlite">SQLite <span class="text-muted small">(plik lokalny — zalecane dla małych instalacji)</span></label>
          </div>
          <div class="form-check">
            <input class="form-check-input" type="radio" name="db_type" id="mysql" value="mysql" onchange="toggleMysql(true)">
            <label class="form-check-label" for="mysql">MySQL / MariaDB</label>
          </div>
        </div>
        <div id="mysql_fields" style="display:none">
          <div class="row g-2">
            <div class="col-8"><label class="form-label">Host</label><input name="db_host" class="form-control" value="localhost"></div>
            <div class="col-4"><label class="form-label">Port</label><input name="db_port" class="form-control" value="3306" type="number"></div>
          </div>
          <div class="mb-2 mt-2"><label class="form-label">Nazwa bazy</label><input name="db_name" class="form-control" value="umowy"></div>
          <div class="mb-2"><label class="form-label">Użytkownik</label><input name="db_user" class="form-control"></div>
          <div class="mb-3"><label class="form-label">Hasło</label><input name="db_pass" class="form-control" type="password"></div>
        </div>
        <button type="submit" class="btn btn-primary w-100">Testuj i kontynuuj <i class="bi bi-arrow-right"></i></button>
      </form>
      <script>function toggleMysql(v){document.getElementById('mysql_fields').style.display=v?'':'none'}</script>

    <!-- ── KROK 3: Microsoft OAuth ── -->
    <?php elseif ($step == 3): ?>
      <h5 class="mb-3">Logowanie Microsoft (Azure AD)</h5>
      <form method="post">
        <div class="form-check form-switch mb-3">
          <input class="form-check-input" type="checkbox" role="switch" name="ms_enabled" id="ms_enabled" onchange="toggleMs(this.checked)" checked>
          <label class="form-check-label" for="ms_enabled">Włącz logowanie przez konto Microsoft</label>
        </div>
        <div id="ms_fields">
          <div class="alert alert-info small p-2">
            <b>Jak uzyskać dane:</b> Azure Portal → Azure Active Directory → Rejestracje aplikacji → Nowa rejestracja.
            Jako URI przekierowania podaj: <code><?= htmlspecialchars((isset($_SERVER['HTTPS'])?'https':'http').'://'.$_SERVER['HTTP_HOST'].rtrim(dirname($_SERVER['PHP_SELF']),'/'))?>/auth/microsoft.php</code>
          </div>
          <div class="mb-2"><label class="form-label">Tenant ID</label><input name="tenant_id" class="form-control" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"></div>
          <div class="mb-2"><label class="form-label">Client ID (Application ID)</label><input name="client_id" class="form-control" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"></div>
          <div class="mb-3"><label class="form-label">Client Secret</label><input name="client_secret" class="form-control" type="password"></div>
        </div>
        <button type="submit" class="btn btn-primary w-100">Dalej <i class="bi bi-arrow-right"></i></button>
        <a href="install.php?step=4" class="btn btn-link w-100 text-muted">Pomiń (tylko logowanie lokalne)</a>
      </form>
      <script>function toggleMs(v){document.getElementById('ms_fields').style.display=v?'':'none'}</script>

    <!-- ── KROK 4: Admin ── -->
    <?php elseif ($step == 4): ?>
      <h5 class="mb-3">Konto administratora</h5>
      <form method="post">
        <div class="mb-2"><label class="form-label">Nazwa organizacji</label><input name="org_name" class="form-control" value="Fundacja" required></div>
        <hr>
        <div class="mb-2"><label class="form-label">Imię i nazwisko</label><input name="admin_name" class="form-control" required></div>
        <div class="mb-2"><label class="form-label">E-mail</label><input name="admin_email" class="form-control" type="email" required></div>
        <div class="mb-2"><label class="form-label">Hasło (min. 8 znaków)</label><input name="admin_pass" class="form-control" type="password" required></div>
        <div class="mb-3"><label class="form-label">Powtórz hasło</label><input name="admin_pass2" class="form-control" type="password" required></div>
        <button type="submit" class="btn btn-success w-100">Zainstaluj <i class="bi bi-check-lg"></i></button>
      </form>

    <!-- ── KROK 5: Gotowe ── -->
    <?php elseif ($step == 5): ?>
      <div class="text-center py-3">
        <i class="bi bi-check-circle-fill text-success" style="font-size:3rem"></i>
        <h4 class="mt-3">Instalacja zakończona!</h4>
        <p class="text-muted">System Rejestru Umów jest gotowy do użycia.</p>
        <div class="alert alert-warning text-start small">
          <i class="bi bi-exclamation-triangle-fill"></i>
          <strong>Ważne:</strong> Usuń lub zabezpiecz plik <code>install.php</code> przed publicznym dostępem.
        </div>
        <a href="index.php" class="btn btn-primary btn-lg">Przejdź do rejestru <i class="bi bi-arrow-right"></i></a>
      </div>
    <?php endif; ?>

    </div>
  </div>
  <p class="text-center text-muted small mt-3">Rejestr Umów Fundacji</p>
</div>
</body>
</html>
