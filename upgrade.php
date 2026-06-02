<?php
/**
 * upgrade.php — Panel aktualizacji Platformy NGO.
 *
 * Wymaga:
 *  - Zainstalowanej aplikacji (config.php z APP_INSTALLED=true)
 *  - Logowania na konto admina lub kodu IKA
 *  - Porównuje aktualną wersję z gitem i uruchamia migracje schematu
 */

// ── Bootstrap bez bootstrap.php (mogą być zmiany schematu) ───────────────────
if (!file_exists(__DIR__ . '/config.php')) {
    header('Location: install.php'); exit;
}
define('BOOTSTRAP_CHECKED', true);
define('APP_INSTALLED', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/version.php';
require_once __DIR__ . '/setup/setup_sql.php';

session_name('upgrade_session');
if (session_status() === PHP_SESSION_NONE) session_start();

// ── Auth ──────────────────────────────────────────────────────────────────────
$is_auth  = !empty($_SESSION['upgrade_auth']);
$error    = '';
$success  = '';

function upg_h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

if (isset($_GET['logout'])) { session_destroy(); header('Location: upgrade.php'); exit; }

// Logowanie — admin email+hasło LUB IKA (login_code)
if (!$is_auth && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upg_login'])) {
    $email = trim($_POST['email'] ?? '');
    $pass  = $_POST['password'] ?? '';
    $code  = trim($_POST['ika_code'] ?? '');

    try {
        if ($code) {
            // IKA — kod jednorazowy admina
            $user = db_one("SELECT * FROM users WHERE login_code=? AND role='admin' AND is_active=1", [$code]);
            if ($user) {
                $_SESSION['upgrade_auth']    = true;
                $_SESSION['upgrade_user']    = $user['name'];
                $_SESSION['upgrade_csrf']    = bin2hex(random_bytes(16));
                $is_auth = true;
            } else {
                $error = 'Nieprawidłowy kod IKA lub brak uprawnień admina.';
            }
        } elseif ($email && $pass) {
            $user = db_one("SELECT * FROM users WHERE email=? AND role='admin' AND is_active=1", [$email]);
            if ($user && password_verify($pass, $user['password'])) {
                $_SESSION['upgrade_auth']    = true;
                $_SESSION['upgrade_user']    = $user['name'];
                $_SESSION['upgrade_csrf']    = bin2hex(random_bytes(16));
                $is_auth = true;
            } else {
                $error = 'Nieprawidłowy e-mail lub hasło. Wymagane konto administratora.';
                sleep(1);
            }
        } else {
            $error = 'Podaj e-mail i hasło lub kod IKA.';
        }
    } catch (\Throwable $e) {
        $error = 'Błąd bazy danych: ' . $e->getMessage();
    }
}

// ── Wersje ────────────────────────────────────────────────────────────────────
function upg_git_log(int $limit = 30): array {
    $base = dirname(__FILE__);
    $raw  = @shell_exec("cd " . escapeshellarg($base) . " && git log --no-merges --format='%h|%ci|%s' -{$limit} 2>/dev/null");
    if (!$raw) return [];
    $items = [];
    foreach (explode("\n", trim($raw)) as $line) {
        if (!$line) continue;
        [$hash, $date, $msg] = array_pad(explode('|', $line, 3), 3, '');
        $type = 'other';
        if (preg_match('/^(feat|fix|refactor|docs|style|chore|perf)/', $msg, $m)) {
            $type = $m[1];
            $msg  = ltrim(preg_replace('/^' . $m[1] . '(\([^)]+\))?:\s*/', '', $msg));
        }
        $items[] = ['hash' => $hash, 'date' => $date ? date('d.m.Y', strtotime($date)) : '', 'msg' => $msg, 'type' => $type];
    }
    return $items;
}

function upg_installed_version(): string {
    try {
        $r = db_one("SELECT value FROM settings WHERE key_='installed_version'");
        return $r['value'] ?? '';
    } catch (\Throwable $e) { return ''; }
}

function upg_save_version(string $hash): void {
    try {
        $exists = db_one("SELECT 1 FROM settings WHERE key_='installed_version'");
        if ($exists) db()->prepare("UPDATE settings SET value=? WHERE key_='installed_version'")->execute([$hash]);
        else         db()->prepare("INSERT INTO settings (key_,value) VALUES ('installed_version',?)")->execute([$hash]);
    } catch (\Throwable $e) {}
}

$current_ver   = app_version();
$installed_ver = upg_installed_version();
$changelog     = $is_auth ? upg_git_log(50) : [];

// Czy są nowe commity od ostatniej instalacji?
$new_commits = [];
if ($installed_ver && $changelog) {
    foreach ($changelog as $c) {
        if ($c['hash'] === $installed_ver) break;
        $new_commits[] = $c;
    }
}
$up_to_date = $installed_ver && empty($new_commits) && $installed_ver === $current_ver['hash'];

// ── POST: uruchom migracje ────────────────────────────────────────────────────
$migration_results = null;
if ($is_auth && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'run_upgrade') {
    if (($_POST['_csrf'] ?? '') !== ($_SESSION['upgrade_csrf'] ?? '')) {
        $error = 'CSRF error.';
    } else {
        try {
            $migration_results = migrate_tenant_db(db());
            upg_save_version($current_ver['hash']);
            $ok_count   = count(array_filter($migration_results, fn($r) => $r[0] === 'ok'));
            $skip_count = count(array_filter($migration_results, fn($r) => $r[0] === 'skip'));
            $err_count  = count(array_filter($migration_results, fn($r) => $r[0] === 'err'));
            $success    = "Aktualizacja zakończona. Nowych zmian: {$ok_count}, pominięto: {$skip_count}" . ($err_count ? ", błędy: {$err_count}" : '') . ".";
            $installed_ver = $current_ver['hash'];
            $new_commits   = [];
            $up_to_date    = true;
        } catch (\Throwable $e) {
            $error = 'Błąd migracji: ' . $e->getMessage();
        }
    }
}

$type_badge = [
    'feat'     => ['#2563eb', 'Nowa funkcja'],
    'fix'      => ['#dc2626', 'Poprawka'],
    'refactor' => ['#7c3aed', 'Refaktor'],
    'docs'     => ['#0891b2', 'Dokumentacja'],
    'other'    => ['#64748b', 'Zmiana'],
];
?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Aktualizacja — Platforma NGO</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
body { background: #f1f5f9; }
.upg-wrap { max-width: 680px; margin: 3rem auto; padding: 0 1rem 3rem; }
.upg-logo { text-align: center; margin-bottom: 2rem; }
.upg-logo-icon { width: 52px; height: 52px; background: #2563eb; border-radius: 12px; display: inline-flex; align-items: center; justify-content: center; font-size: 1.4rem; color: #fff; margin-bottom: .65rem; }
.upg-logo h1 { font-size: 1.1rem; font-weight: 700; color: #0f172a; margin: 0; }
.upg-logo p  { font-size: .8rem; color: #64748b; margin: .15rem 0 0; }
.card-upg { background: #fff; border-radius: .75rem; box-shadow: 0 1px 12px rgba(0,0,0,.08); padding: 1.75rem; margin-bottom: 1.25rem; }
.ver-row { display: flex; justify-content: space-between; align-items: center; padding: .5rem 0; border-bottom: 1px solid #f1f5f9; font-size: .85rem; }
.ver-row:last-child { border: none; }
.ver-label { color: #64748b; }
.ver-val { font-family: monospace; font-size: .82rem; }
.commit-row { display: grid; grid-template-columns: 70px 1fr auto; gap: .5rem; align-items: baseline; padding: .35rem 0; border-bottom: 1px solid #f8fafc; font-size: .81rem; }
.commit-row:last-child { border: none; }
.commit-hash { font-family: monospace; color: #94a3b8; font-size: .73rem; }
.commit-new { background: #fef3c7; }
.tab-btn { border: none; background: none; padding: .4rem .75rem; font-size: .82rem; color: #64748b; border-bottom: 2px solid transparent; cursor: pointer; }
.tab-btn.active { color: #2563eb; border-bottom-color: #2563eb; font-weight: 600; }
.login-tabs { display: flex; border-bottom: 1px solid #e2e8f0; margin-bottom: 1.25rem; }
</style>
</head>
<body>
<div class="upg-wrap">

  <div class="upg-logo">
    <div class="upg-logo-icon"><i class="bi bi-arrow-repeat"></i></div>
    <h1>Platforma NGO</h1>
    <p>Panel aktualizacji systemu</p>
  </div>

<?php if (!$is_auth): ?>

  <!-- ── LOGIN ─────────────────────────────────────────────────────────────── -->
  <div class="card-upg">
    <h2 class="fw-bold mb-1" style="font-size:1.05rem">Weryfikacja tożsamości</h2>
    <p class="text-muted small mb-3">Wymagane konto administratora lub kod IKA.</p>

    <?php if ($error): ?>
    <div class="alert alert-danger py-2 small"><?= upg_h($error) ?></div>
    <?php endif; ?>

    <div class="login-tabs" id="loginTabs">
      <button class="tab-btn active" onclick="switchTab('pw')">E-mail i hasło</button>
      <button class="tab-btn" onclick="switchTab('ika')">Kod IKA</button>
    </div>

    <form method="post">
      <input type="hidden" name="upg_login" value="1">

      <div id="tab-pw">
        <div class="mb-2">
          <label class="form-label small fw-semibold">E-mail administratora</label>
          <input type="email" name="email" class="form-control form-control-sm" autocomplete="username">
        </div>
        <div class="mb-3">
          <label class="form-label small fw-semibold">Hasło</label>
          <input type="password" name="password" class="form-control form-control-sm" autocomplete="current-password">
        </div>
      </div>

      <div id="tab-ika" style="display:none">
        <div class="mb-3">
          <label class="form-label small fw-semibold">Kod IKA administratora</label>
          <input type="text" name="ika_code" class="form-control form-control-sm font-monospace"
                 placeholder="XXXXXXXX" spellcheck="false" autocomplete="one-time-code">
          <div class="form-text">Jednorazowy kod nadany przez twórcę systemu (Admin → Kody dostępu).</div>
        </div>
      </div>

      <button type="submit" class="btn btn-primary w-100">Zaloguj się <i class="bi bi-arrow-right ms-1"></i></button>
    </form>
  </div>

  <script>
  function switchTab(t) {
    document.getElementById('tab-pw').style.display  = t === 'pw'  ? '' : 'none';
    document.getElementById('tab-ika').style.display = t === 'ika' ? '' : 'none';
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    event.currentTarget.classList.add('active');
  }
  </script>

<?php else: ?>

  <!-- ── PANEL ─────────────────────────────────────────────────────────────── -->
  <div class="d-flex justify-content-between align-items-center mb-3">
    <div class="small text-muted">
      <i class="bi bi-person-check me-1"></i><?= upg_h($_SESSION['upgrade_user'] ?? '') ?>
    </div>
    <a href="?logout=1" class="btn btn-sm btn-outline-secondary">Wyloguj</a>
  </div>

  <?php if ($error):   ?><div class="alert alert-danger py-2 small"><?= upg_h($error) ?></div><?php endif; ?>
  <?php if ($success): ?><div class="alert alert-success py-2 small"><i class="bi bi-check-circle me-1"></i><?= upg_h($success) ?></div><?php endif; ?>

  <!-- Wersje -->
  <div class="card-upg">
    <h2 class="fw-bold mb-3" style="font-size:1rem"><i class="bi bi-git me-2 text-primary"></i>Wersje</h2>
    <div class="ver-row">
      <span class="ver-label">Zainstalowana wersja</span>
      <span class="ver-val <?= $installed_ver ? '' : 'text-muted' ?>">
        <?= $installed_ver ? upg_h($installed_ver) : '— nieznana (uruchom aktualizację aby zapisać)' ?>
      </span>
    </div>
    <div class="ver-row">
      <span class="ver-label">Aktualna wersja (git HEAD)</span>
      <span class="ver-val"><?= upg_h($current_ver['hash']) ?></span>
    </div>
    <div class="ver-row">
      <span class="ver-label">Data commitu</span>
      <span class="ver-val text-muted"><?= upg_h($current_ver['date']) ?></span>
    </div>
    <div class="ver-row">
      <span class="ver-label">Status</span>
      <span>
        <?php if ($up_to_date): ?>
        <span class="badge bg-success">Aktualny</span>
        <?php elseif ($new_commits): ?>
        <span class="badge bg-warning text-dark"><?= count($new_commits) ?> nowych zmian</span>
        <?php else: ?>
        <span class="badge bg-secondary">Nieznany — brak zapisanej wersji</span>
        <?php endif; ?>
      </span>
    </div>
  </div>

  <?php if ($new_commits): ?>
  <!-- Nowe zmiany od ostatniej instalacji -->
  <div class="card-upg">
    <h2 class="fw-bold mb-3" style="font-size:1rem">
      <i class="bi bi-stars me-2 text-warning"></i>Zmiany od ostatniej aktualizacji
      <span class="badge bg-warning text-dark ms-1"><?= count($new_commits) ?></span>
    </h2>
    <?php foreach ($new_commits as $c):
      [$bc, $bl] = $type_badge[$c['type']] ?? $type_badge['other'];
    ?>
    <div class="commit-row commit-new">
      <span class="commit-hash"><?= upg_h($c['hash']) ?></span>
      <span><?= upg_h($c['msg']) ?></span>
      <span class="badge" style="background:<?= $bc ?>;font-size:.65rem"><?= $bl ?></span>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- Akcja aktualizacji -->
  <div class="card-upg <?= $up_to_date && !$migration_results ? 'border-success' : '' ?>">
    <h2 class="fw-bold mb-1" style="font-size:1rem">
      <i class="bi bi-database-gear me-2 text-primary"></i>Aktualizacja schematu bazy danych
    </h2>
    <p class="text-muted small mb-3">
      Uruchamia migracje — dodaje nowe kolumny i tabele. Operacja bezpieczna: nie usuwa istniejących danych.
    </p>

    <?php if ($migration_results): ?>
    <div class="mb-3" style="max-height:280px;overflow-y:auto">
      <?php foreach ($migration_results as $r):
        [$status, $label] = $r;
        $icon  = match($status) { 'ok' => 'check-circle-fill text-success', 'skip' => 'dash-circle text-secondary', default => 'x-circle-fill text-danger' };
      ?>
      <div class="d-flex align-items-center gap-2 py-1" style="font-size:.78rem;border-bottom:1px solid #f8fafc">
        <i class="bi bi-<?= $icon ?>" style="flex-shrink:0"></i>
        <span class="<?= $status === 'err' ? 'text-danger' : ($status === 'skip' ? 'text-muted' : '') ?>"><?= upg_h($label) ?></span>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($up_to_date && !$migration_results): ?>
    <div class="alert alert-success py-2 small mb-3">
      <i class="bi bi-check-circle me-1"></i>System jest aktualny. Baza danych jest zsynchronizowana z kodem.
    </div>
    <?php endif; ?>

    <form method="post" onsubmit="return confirm('Uruchomić migracje schematu bazy danych?')">
      <input type="hidden" name="_csrf"   value="<?= upg_h($_SESSION['upgrade_csrf'] ?? '') ?>">
      <input type="hidden" name="_action" value="run_upgrade">
      <button type="submit" class="btn btn-primary <?= $up_to_date && !$migration_results ? 'btn-outline-primary' : '' ?>">
        <i class="bi bi-database-gear me-1"></i>
        <?= $up_to_date && !$migration_results ? 'Uruchom ponownie migracje' : 'Uruchom aktualizację' ?>
      </button>
    </form>
  </div>

  <!-- Historia commitów -->
  <div class="card-upg">
    <h2 class="fw-bold mb-3" style="font-size:1rem">
      <i class="bi bi-clock-history me-2 text-secondary"></i>Historia zmian (ostatnie <?= count($changelog) ?>)
    </h2>
    <?php if ($changelog): ?>
    <?php foreach ($changelog as $c):
      [$bc, $bl] = $type_badge[$c['type']] ?? $type_badge['other'];
      $is_new = in_array($c, $new_commits, true);
    ?>
    <div class="commit-row <?= $is_new ? 'commit-new' : '' ?>">
      <span class="commit-hash"><?= upg_h($c['hash']) ?></span>
      <span><?= upg_h($c['msg']) ?></span>
      <div class="d-flex align-items-center gap-1">
        <?php if ($c['hash'] === $installed_ver): ?>
        <span class="badge bg-secondary" style="font-size:.62rem">zainstalowana</span>
        <?php endif; ?>
        <span class="badge" style="background:<?= $bc ?>;font-size:.65rem"><?= $bl ?></span>
      </div>
    </div>
    <?php endforeach; ?>
    <?php else: ?>
    <p class="text-muted small">Brak danych git — katalog może nie być repozytorium.</p>
    <?php endif; ?>
  </div>

  <div class="text-center">
    <a href="<?= defined('APP_URL') ? upg_h(APP_URL) . '/admin/' : 'admin/' ?>" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-arrow-left me-1"></i>Wróć do panelu admina
    </a>
  </div>

<?php endif; ?>

  <p class="text-center text-muted mt-3" style="font-size:.72rem">
    Platforma NGO · upgrade.php · <?= upg_h($current_ver['label']) ?>
  </p>
</div>
</body>
</html>
