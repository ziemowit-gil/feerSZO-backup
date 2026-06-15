<?php
/**
 * upgrade.php — Panel aktualizacji Platformy NGO.
 *
 * Aktualizuje jednocześnie KOD (git pull z origin) i SCHEMAT bazy (migracje).
 *
 * Wymaga:
 *  - Zainstalowanej aplikacji (config.php z APP_INSTALLED=true)
 *  - Logowania na konto admina lub kodu IKA
 *
 * Logika aktualizacji kodu: includes/updater.php (współdzielona z cli/migrate.php).
 * Logika migracji schematu:  migrate_tenant_db() w setup/setup_sql.php.
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
require_once __DIR__ . '/includes/updater.php';
require_once __DIR__ . '/setup/setup_sql.php';

session_name('upgrade_session');
if (session_status() === PHP_SESSION_NONE) session_start();

// ── Auth ──────────────────────────────────────────────────────────────────────
$is_auth = !empty($_SESSION['upgrade_auth']);
$error   = '';
$success = '';
$notice  = '';

function upg_h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

if (isset($_GET['logout'])) { session_destroy(); header('Location: upgrade.php'); exit; }

// Logowanie — admin email+hasło LUB IKA (login_code)
if (!$is_auth && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upg_login'])) {
    $email = trim($_POST['email'] ?? '');
    $pass  = $_POST['password'] ?? '';
    $code  = trim($_POST['ika_code'] ?? '');

    try {
        if ($code) {
            $user = db_one("SELECT * FROM users WHERE login_code=? AND role='admin' AND is_active=1", [$code]);
            if ($user) {
                $_SESSION['upgrade_auth'] = true;
                $_SESSION['upgrade_user'] = $user['name'];
                $_SESSION['upgrade_csrf'] = bin2hex(random_bytes(16));
                $is_auth = true;
            } else {
                $error = 'Nieprawidłowy kod IKA lub brak uprawnień admina.';
            }
        } elseif ($email && $pass) {
            $user = db_one("SELECT * FROM users WHERE email=? AND role='admin' AND is_active=1", [$email]);
            if ($user && password_verify($pass, $user['password'])) {
                $_SESSION['upgrade_auth'] = true;
                $_SESSION['upgrade_user'] = $user['name'];
                $_SESSION['upgrade_csrf'] = bin2hex(random_bytes(16));
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

// ── Helpery wersji / rejestru ──────────────────────────────────────────────────
function upg_installed_version(): string {
    try {
        $r = db_one("SELECT value FROM settings WHERE key_='installed_version'");
        return $r['value'] ?? '';
    } catch (\Throwable $e) { return ''; }
}

function upg_save_version(string $hash): void {
    if (!$hash || $hash === 'unknown') return;
    try {
        $exists = db_one("SELECT 1 FROM settings WHERE key_='installed_version'");
        if ($exists) db()->prepare("UPDATE settings SET value=? WHERE key_='installed_version'")->execute([$hash]);
        else         db()->prepare("INSERT INTO settings (key_,value) VALUES ('installed_version',?)")->execute([$hash]);
    } catch (\Throwable $e) {}
}

/** Ostatnio zastosowane migracje z rejestru schema_migrations. */
function upg_migration_log(int $limit = 40): array {
    try {
        return db_all("SELECT mig_key, status, detail, applied_at
                       FROM schema_migrations
                       ORDER BY applied_at DESC, mig_key ASC
                       LIMIT " . (int)$limit);
    } catch (\Throwable $e) { return []; }
}

/** Uruchom migracje + zapisz wersję. Zwraca [results, backup, summary, err]. */
function upg_run_migrations(bool $do_backup = true): array {
    $backup  = $do_backup ? upd_backup_db() : ['ok' => false];
    $results = [];
    $err     = '';
    try {
        $results = migrate_tenant_db(db());
    } catch (\Throwable $e) {
        $err = 'Błąd migracji: ' . $e->getMessage();
        return [$results, $backup, '', $err];
    }
    $ok   = count(array_filter($results, fn($r) => $r[0] === 'ok'));
    $skip = count(array_filter($results, fn($r) => $r[0] === 'skip'));
    $bad  = count(array_filter($results, fn($r) => $r[0] === 'err'));
    $summary = "Migracje: nowych {$ok}, pominięto {$skip}" . ($bad ? ", błędy {$bad}" : '') . '.';
    return [$results, $backup, $summary, ''];
}

// ── Stan kodu (git) ─────────────────────────────────────────────────────────
$git_ok        = $is_auth ? upd_git_available() : false;
$repo_writable = $git_ok  ? upd_repo_writable()  : false;
$branch        = $git_ok  ? (upd_current_branch() ?: 'main') : 'main';
$current_ver   = app_version();
$installed_ver = upg_installed_version();

// Stan względem origin — z ostatniego fetch (bez sieci przy zwykłym wejściu).
$ba             = $git_ok ? upd_behind_ahead($branch) : ['behind' => 0, 'ahead' => 0, 'ok' => false];
$behind         = $ba['behind'] ?? 0;
$incoming       = ($git_ok && $behind) ? upd_incoming_commits($branch, 50) : [];
$remote_short   = $git_ok ? upd_short('origin/' . $branch) : '';

// Stan działania (wyniki akcji)
$migration_results = null;
$migration_log_rows = null;
$backup_result     = null;
$pull_result       = null;
$fetch_done        = false;

// ── POST: akcje ─────────────────────────────────────────────────────────────
if ($is_auth && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action'])) {
    $action = $_POST['_action'];
    if (!hash_equals($_SESSION['upgrade_csrf'] ?? '', $_POST['_csrf'] ?? '')) {
        $error = 'Błąd CSRF — odśwież stronę i spróbuj ponownie.';
    } else {
        switch ($action) {

            // ── Sprawdź aktualizacje (fetch) ────────────────────────────────
            case 'check':
                if (!$git_ok) { $error = 'Git niedostępny — nie można sprawdzić aktualizacji.'; break; }
                $f = upd_fetch($branch);
                $fetch_done = true;
                if (!$f['ok']) {
                    $error = 'Nie udało się pobrać informacji z origin: ' . ($f['out'] ?: 'nieznany błąd');
                } else {
                    $ba       = upd_behind_ahead($branch);
                    $behind   = $ba['behind'] ?? 0;
                    $incoming = $behind ? upd_incoming_commits($branch, 50) : [];
                    $remote_short = upd_short('origin/' . $branch);
                    $notice = $behind ? "Dostępnych nowych zmian: {$behind}." : 'Kod jest aktualny — brak nowych commitów.';
                }
                break;

            // ── Tylko migracje schematu ─────────────────────────────────────
            case 'migrate':
                [$migration_results, $backup_result, $sum, $merr] = upg_run_migrations();
                if ($merr) { $error = $merr; }
                else {
                    upg_save_version($current_ver['hash']);
                    $installed_ver = $current_ver['hash'];
                    $success = $sum . ($backup_result['ok'] ? ' Backup: ' . basename($backup_result['file']) : '');
                }
                break;

            // ── Pełna aktualizacja: pull → migracje → OPcache ───────────────
            case 'full':
                if (!$git_ok)        { $error = 'Git niedostępny — użyj „Tylko migracje" lub zaktualizuj kod na serwerze.'; break; }
                if (!$repo_writable) { $error = 'Katalog .git nie jest zapisywalny dla serwera WWW — pobierz kod na hoście (docker/update.sh), a tu uruchom migracje.'; break; }

                // 1) Backup przed czymkolwiek
                $backup_result = upd_backup_db();
                // 2) Fetch + pull (ff-only)
                upd_fetch($branch);
                $pull_result = upd_pull($branch);
                if (!$pull_result['ok']) {
                    $error = 'Pobieranie kodu nie powiodło się: ' . ($pull_result['out'] ?: 'nieznany błąd')
                           . ' — kod nie został zmieniony. Migracje pominięto.';
                    break;
                }
                // 3) Migracje schematu (na nowym kodzie) — backup już zrobiony przed pull
                [$migration_results, , $sum, $merr] = upg_run_migrations(false);
                // 4) OPcache reset (nowy kod od razu)
                $opcache = upd_reset_opcache();
                // 5) Zapis wersji
                $new_hash = upd_short('HEAD') ?: $current_ver['hash'];
                upg_save_version($new_hash);
                $installed_ver = $new_hash;

                $parts = [];
                $parts[] = $pull_result['changed']
                    ? "Kod zaktualizowany ({$pull_result['before']} → {$new_hash})."
                    : 'Kod był już aktualny.';
                $parts[] = $sum ?: '';
                if ($opcache) $parts[] = 'OPcache wyczyszczony.';
                if ($backup_result['ok'] ?? false) $parts[] = 'Backup: ' . basename($backup_result['file']);
                if ($merr) $error = $merr;
                $success = trim(implode(' ', array_filter($parts)));

                // Odśwież stan po pull
                $current_ver = app_version();
                $ba       = upd_behind_ahead($branch);
                $behind   = $ba['behind'] ?? 0;
                $incoming = $behind ? upd_incoming_commits($branch, 50) : [];
                $remote_short = upd_short('origin/' . $branch);
                break;
        }
    }
}

// ── Dane do widoku ───────────────────────────────────────────────────────────
$changelog          = $git_ok ? upd_recent_commits(40) : [];
$migration_log_rows = $is_auth ? upg_migration_log(40) : [];
$code_up_to_date    = $git_ok && $behind === 0;

$type_badge = [
    'feat'     => ['#2563eb', 'Nowa funkcja'],
    'fix'      => ['#dc2626', 'Poprawka'],
    'refactor' => ['#7c3aed', 'Refaktor'],
    'docs'     => ['#0891b2', 'Dokumentacja'],
    'perf'     => ['#0d9488', 'Wydajność'],
    'chore'    => ['#64748b', 'Utrzymanie'],
    'other'    => ['#64748b', 'Zmiana'],
];
$status_meta = [
    'ok'   => ['#166534', '#f0fdf4', 'check-circle-fill'],
    'err'  => ['#991b1b', '#fef2f2', 'x-circle-fill'],
    'skip' => ['#6b7280', '#f8fafc', 'dash-circle'],
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
.upg-wrap { max-width: 720px; margin: 3rem auto; padding: 0 1rem 3rem; }
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
.log-box { max-height: 280px; overflow-y: auto; font-size: .78rem; border: 1px solid #e5e7eb; border-radius: .5rem; }
.log-row { display: flex; align-items: center; gap: .5rem; padding: .4rem .75rem; border-bottom: 1px solid #eef2f7; }
.log-row:last-child { border: none; }
.term { background: #0f172a; color: #e2e8f0; font-family: monospace; font-size: .74rem; border-radius: .5rem; padding: .75rem 1rem; white-space: pre-wrap; word-break: break-word; max-height: 220px; overflow-y: auto; }
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
      <button type="button" class="tab-btn active" onclick="switchTab('pw')">E-mail i hasło</button>
      <button type="button" class="tab-btn" onclick="switchTab('ika')">Kod IKA</button>
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

  <?php if ($error):   ?><div class="alert alert-danger py-2 small"><i class="bi bi-exclamation-triangle me-1"></i><?= upg_h($error) ?></div><?php endif; ?>
  <?php if ($success): ?><div class="alert alert-success py-2 small"><i class="bi bi-check-circle me-1"></i><?= upg_h($success) ?></div><?php endif; ?>
  <?php if ($notice):  ?><div class="alert alert-info py-2 small"><i class="bi bi-info-circle me-1"></i><?= upg_h($notice) ?></div><?php endif; ?>

  <!-- Centrum aktualizacji -->
  <div class="card-upg <?= $code_up_to_date ? 'border-success' : ($behind ? 'border-warning' : '') ?>">
    <h2 class="fw-bold mb-1" style="font-size:1rem">
      <i class="bi bi-cloud-arrow-down me-2 text-primary"></i>Centrum aktualizacji
    </h2>
    <p class="text-muted small mb-3">
      Pobiera kod z repozytorium (gałąź <code><?= upg_h($branch) ?></code>), uruchamia migracje schematu i czyści OPcache.
    </p>

    <?php if (!$git_ok): ?>
    <div class="alert alert-warning py-2 small">
      <i class="bi bi-exclamation-triangle me-1"></i>Git niedostępny w tym środowisku — możliwe są tylko migracje schematu.
      Kod aktualizuj na serwerze: <code>bash docker/update.sh</code>.
    </div>
    <?php elseif (!$repo_writable): ?>
    <div class="alert alert-warning py-2 small">
      <i class="bi bi-shield-lock me-1"></i>Katalog <code>.git</code> nie jest zapisywalny dla serwera WWW —
      pobieranie kodu z weba niedostępne. Użyj <code>docker/update.sh</code> na hoście, a tutaj uruchom migracje.
    </div>
    <?php endif; ?>

    <div class="d-flex flex-wrap gap-2">
      <!-- Pełna aktualizacja -->
      <form method="post" class="d-inline"
            onsubmit="return confirm('Pobrać kod z repozytorium i uruchomić migracje bazy?')">
        <input type="hidden" name="_csrf"   value="<?= upg_h($_SESSION['upgrade_csrf'] ?? '') ?>">
        <input type="hidden" name="_action" value="full">
        <button type="submit" class="btn btn-primary" <?= (!$git_ok || !$repo_writable) ? 'disabled' : '' ?>>
          <i class="bi bi-cloud-download me-1"></i>Aktualizuj wszystko
          <?php if ($behind): ?><span class="badge bg-light text-dark ms-1"><?= (int)$behind ?> zmian</span><?php endif; ?>
        </button>
      </form>

      <!-- Sprawdź aktualizacje (fetch) -->
      <form method="post" class="d-inline">
        <input type="hidden" name="_csrf"   value="<?= upg_h($_SESSION['upgrade_csrf'] ?? '') ?>">
        <input type="hidden" name="_action" value="check">
        <button type="submit" class="btn btn-outline-secondary" <?= !$git_ok ? 'disabled' : '' ?>>
          <i class="bi bi-arrow-repeat me-1"></i>Sprawdź aktualizacje
        </button>
      </form>

      <!-- Tylko migracje -->
      <form method="post" class="d-inline"
            onsubmit="return confirm('Uruchomić tylko migracje schematu bazy (bez pobierania kodu)?')">
        <input type="hidden" name="_csrf"   value="<?= upg_h($_SESSION['upgrade_csrf'] ?? '') ?>">
        <input type="hidden" name="_action" value="migrate">
        <button type="submit" class="btn btn-outline-secondary">
          <i class="bi bi-database-gear me-1"></i>Tylko migracje
        </button>
      </form>
    </div>
  </div>

  <!-- Wynik pobierania kodu -->
  <?php if ($pull_result): ?>
  <div class="card-upg">
    <h2 class="fw-bold mb-2" style="font-size:1rem"><i class="bi bi-terminal me-2 text-secondary"></i>Pobieranie kodu</h2>
    <div class="term"><?= upg_h($pull_result['out'] ?: '(brak wyjścia)') ?></div>
  </div>
  <?php endif; ?>

  <!-- Wynik migracji (z bieżącej akcji) -->
  <?php if ($migration_results): ?>
  <div class="card-upg">
    <h2 class="fw-bold mb-2" style="font-size:1rem"><i class="bi bi-list-check me-2 text-secondary"></i>Wynik migracji</h2>
    <div class="log-box">
      <?php foreach ($migration_results as [$st, $label]):
        [$tc, $bg, $ic] = $status_meta[$st] ?? $status_meta['skip']; ?>
      <div class="log-row" style="background:<?= $bg ?>;color:<?= $tc ?>">
        <i class="bi bi-<?= $ic ?>" style="flex-shrink:0"></i><span><?= upg_h($label) ?></span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Wersje -->
  <div class="card-upg">
    <h2 class="fw-bold mb-3" style="font-size:1rem"><i class="bi bi-git me-2 text-primary"></i>Wersje</h2>
    <div class="ver-row">
      <span class="ver-label">Zainstalowana wersja</span>
      <span class="ver-val <?= $installed_ver ? '' : 'text-muted' ?>">
        <?= $installed_ver ? upg_h($installed_ver) : '— nieznana (uruchom aktualizację)' ?>
      </span>
    </div>
    <div class="ver-row">
      <span class="ver-label">Lokalny kod (git HEAD)</span>
      <span class="ver-val"><?= upg_h($current_ver['hash']) ?> <span class="text-muted">· <?= upg_h($branch) ?></span></span>
    </div>
    <?php if ($git_ok && $remote_short): ?>
    <div class="ver-row">
      <span class="ver-label">Zdalny kod (origin/<?= upg_h($branch) ?>)</span>
      <span class="ver-val"><?= upg_h($remote_short) ?></span>
    </div>
    <?php endif; ?>
    <div class="ver-row">
      <span class="ver-label">Data lokalnego commitu</span>
      <span class="ver-val text-muted"><?= upg_h($current_ver['date']) ?></span>
    </div>
    <div class="ver-row">
      <span class="ver-label">Status kodu</span>
      <span>
        <?php if (!$git_ok): ?>
          <span class="badge bg-secondary">Git niedostępny</span>
        <?php elseif ($code_up_to_date): ?>
          <span class="badge bg-success">Aktualny</span>
        <?php else: ?>
          <span class="badge bg-warning text-dark"><?= (int)$behind ?> nowych zmian do pobrania</span>
        <?php endif; ?>
      </span>
    </div>
  </div>

  <!-- Nowe zmiany do pobrania -->
  <?php if ($incoming): ?>
  <div class="card-upg">
    <h2 class="fw-bold mb-3" style="font-size:1rem">
      <i class="bi bi-stars me-2 text-warning"></i>Zmiany do pobrania
      <span class="badge bg-warning text-dark ms-1"><?= count($incoming) ?></span>
    </h2>
    <?php foreach ($incoming as $c):
      [$bc, $bl] = $type_badge[$c['type']] ?? $type_badge['other']; ?>
    <div class="commit-row commit-new">
      <span class="commit-hash"><?= upg_h($c['hash']) ?></span>
      <span><?= upg_h($c['msg']) ?></span>
      <span class="badge" style="background:<?= $bc ?>;font-size:.65rem"><?= $bl ?></span>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- Ochrona konfiguracji -->
  <div class="card-upg" style="border-left:3px solid #16a34a">
    <h2 class="fw-bold mb-1" style="font-size:.95rem"><i class="bi bi-shield-check me-1 text-success"></i>Co jest chronione podczas aktualizacji</h2>
    <p class="text-muted small mb-2">Migracje <strong>tylko dodają</strong> kolumny i tabele — nigdy nie usuwają danych. Kod pobierany jest jako <code>fast-forward</code> (bez nadpisywania lokalnych zmian).</p>
    <div class="d-flex flex-wrap gap-2" style="font-size:.78rem">
      <?php foreach (['Microsoft 365','SMTP / e-mail','SMS API','Branding','APP_KEY','Certyfikat instalacyjny','Dane organizacji','Konta użytkowników','Wszystkie umowy i dane'] as $p): ?>
      <span class="badge bg-success bg-opacity-15 text-success border border-success border-opacity-25"><?= upg_h($p) ?></span>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Rejestr migracji schematu -->
  <?php if ($migration_log_rows): ?>
  <div class="card-upg">
    <h2 class="fw-bold mb-2" style="font-size:1rem">
      <i class="bi bi-clock-history me-2 text-secondary"></i>Rejestr migracji schematu
      <span class="badge bg-secondary ms-1"><?= count($migration_log_rows) ?></span>
    </h2>
    <p class="text-muted small mb-2">Co zostało zastosowane do bazy i kiedy (tabela <code>schema_migrations</code>).</p>
    <div class="log-box">
      <?php foreach ($migration_log_rows as $r):
        [$tc, $bg, $ic] = $status_meta[$r['status']] ?? $status_meta['skip']; ?>
      <div class="log-row" style="background:<?= $bg ?>;color:<?= $tc ?>">
        <i class="bi bi-<?= $ic ?>" style="flex-shrink:0"></i>
        <span class="flex-grow-1"><?= upg_h($r['mig_key']) ?></span>
        <span class="text-muted" style="font-size:.7rem"><?= upg_h((string)($r['applied_at'] ?? '')) ?></span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Historia commitów -->
  <?php if ($changelog): ?>
  <div class="card-upg">
    <h2 class="fw-bold mb-3" style="font-size:1rem">
      <i class="bi bi-list-ul me-2 text-secondary"></i>Historia zmian (ostatnie <?= count($changelog) ?>)
    </h2>
    <?php foreach ($changelog as $c):
      [$bc, $bl] = $type_badge[$c['type']] ?? $type_badge['other']; ?>
    <div class="commit-row">
      <span class="commit-hash"><?= upg_h($c['hash']) ?></span>
      <span><?= upg_h($c['msg']) ?></span>
      <div class="d-flex align-items-center gap-1">
        <?php if (strpos($installed_ver, $c['hash']) === 0 || $c['hash'] === $installed_ver): ?>
        <span class="badge bg-secondary" style="font-size:.62rem">zainstalowana</span>
        <?php endif; ?>
        <span class="badge" style="background:<?= $bc ?>;font-size:.65rem"><?= $bl ?></span>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="text-center">
    <a href="<?= defined('APP_URL') ? upg_h(APP_URL) . '/admin/' : 'admin/' ?>" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-arrow-left me-1"></i>Wróć do panelu admina
    </a>
    <a href="install.php?reinstall=1"
       class="btn btn-outline-danger btn-sm"
       onclick="return confirm('Reinstalacja wyczyści bazę i konfigurację (poza kluczami API i CRON). Kontynuować?')">
      <i class="bi bi-arrow-repeat me-1"></i>Reinstaluj platformę
    </a>
  </div>

<?php endif; ?>

  <p class="text-center text-muted mt-3" style="font-size:.72rem">
    Platforma NGO · upgrade.php · <?= upg_h($current_ver['label']) ?>
  </p>
</div>
</body>
</html>
