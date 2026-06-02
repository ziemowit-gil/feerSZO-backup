<?php
/**
 * creator.php — Lokalny panel twórcy.
 *
 * Działa BEZ weryfikacji certyfikatu i BEZ normalnego bootstrapu.
 * Dostęp tylko po wpisaniu hasła twórcy: zaq1@WSX
 *
 * Pozwala na:
 * - Generowanie/odnowienie certyfikatu instalacyjnego
 * - Podgląd APP_KEY
 * - Status systemu i bazy danych
 * - Zarządzanie instalacją bez licencji
 */

// ── Minimalny bootstrap — bez weryfikacji cert ───────────────────────────────
if (!defined('APP_INSTALLED'))    define('APP_INSTALLED', true);
if (!defined('BOOTSTRAP_CHECKED')) define('BOOTSTRAP_CHECKED', true); // pomiń bootstrap.php
if (!defined('CREATOR_MODE'))     define('CREATOR_MODE', true);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

session_name('creator_session');
if (session_status() === PHP_SESSION_NONE) session_start();

define('CREATOR_PASSWORD', 'zaq1@WSX');

// ── Auth ──────────────────────────────────────────────────────────────────────
$is_auth  = !empty($_SESSION['creator_auth']);
$error    = '';
$success  = '';

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: creator.php'); exit;
}

if (!$is_auth && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['creator_pass'])) {
    if ($_POST['creator_pass'] === CREATOR_PASSWORD) {
        $_SESSION['creator_auth'] = true;
        $_SESSION['creator_csrf'] = bin2hex(random_bytes(16));
        $is_auth = true;
    } else {
        $error = 'Nieprawidłowe hasło.';
        sleep(1); // throttle
    }
}

// ── CSRF ─────────────────────────────────────────────────────────────────────
function cr_csrf(): string { return $_SESSION['creator_csrf'] ?? ''; }
function cr_csrf_ok(): bool { return ($_POST['_csrf'] ?? '') === ($_SESSION['creator_csrf'] ?? ''); }

// ── Cert helpers (kopiowane z admin/app_license.php) ─────────────────────────
$certs_dir = __DIR__ . '/certs';
$crt_file  = $certs_dir . '/app.crt';
$key_file  = $certs_dir . '/app.key';
$sig_file  = $certs_dir . '/app.sig';

function cr_read_cert(string $crt): ?array {
    if (!file_exists($crt)) return null;
    $pem = file_get_contents($crt);
    $p   = @openssl_x509_parse($pem);
    if (!$p) return null;
    $d = (int)ceil(($p['validTo_time_t'] - time()) / 86400);
    return [
        'pem'       => $pem,
        'krs'       => preg_replace('/^KRS:/', '', $p['subject']['serialNumber'] ?? ''),
        'org'       => $p['subject']['CN'] ?? '',
        'valid_from'=> date('d.m.Y', $p['validFrom_time_t']),
        'valid_to'  => date('d.m.Y', $p['validTo_time_t']),
        'valid_to_ts'=> $p['validTo_time_t'],
        'days_left' => $d,
        'expired'   => $d <= 0,
        'warning'   => $d > 0 && $d <= 30,
    ];
}

function cr_generate_cert(string $krs, string $org, string $certs_dir, string $crt_file, string $key_file, string $sig_file): array {
    if (!extension_loaded('openssl')) return ['ok' => false, 'error' => 'Brak OpenSSL'];
    if (!$krs || !$org) return ['ok' => false, 'error' => 'KRS i nazwa wymagane'];
    if (!is_dir($certs_dir)) mkdir($certs_dir, 0755, true);

    $pkey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    if (!$pkey) return ['ok' => false, 'error' => openssl_error_string()];

    $dn = ['C'=>'PL','ST'=>'Polska','O'=>$org,'OU'=>'Platforma NGO','CN'=>$org,'serialNumber'=>'KRS:'.$krs];
    $csr  = openssl_csr_new($dn, $pkey, ['digest_alg'=>'sha256']);
    $cert = openssl_csr_sign($csr, null, $pkey, 730, ['digest_alg'=>'sha256'], (int)(microtime(true)*1000)&0x7FFFFFFF);
    $cert_pem = $key_pem = '';
    openssl_x509_export($cert, $cert_pem);
    openssl_pkey_export($pkey, $key_pem);
    file_put_contents($crt_file, $cert_pem);
    file_put_contents($key_file, $key_pem); @chmod($key_file, 0600);
    $sig = hash_hmac('sha256', $cert_pem, APP_KEY);
    file_put_contents($sig_file, $sig);
    $parsed = openssl_x509_parse($cert_pem);
    return ['ok'=>true,'valid_to'=>date('d.m.Y',$parsed['validTo_time_t']),'days_left'=>(int)ceil(($parsed['validTo_time_t']-time())/86400)];
}

// ── Akcje POST (tylko dla zalogowanego) ───────────────────────────────────────
if ($is_auth && $_SERVER['REQUEST_METHOD'] === 'POST' && cr_csrf_ok()) {
    $act = $_POST['_action'] ?? '';

    if ($act === 'generate_cert') {
        $krs = preg_replace('/\D/', '', $_POST['krs'] ?? '');
        $org = trim($_POST['org_name'] ?? '');
        $res = cr_generate_cert($krs, $org, $certs_dir, $crt_file, $key_file, $sig_file);
        if ($res['ok']) $success = "✅ Certyfikat wygenerowany. Ważny do {$res['valid_to']} ({$res['days_left']} dni).";
        else            $error   = "❌ Błąd: " . $res['error'];
    }

    if ($act === 'clear_cert') {
        foreach ([$crt_file, $key_file, $sig_file] as $f) { if (file_exists($f)) @unlink($f); }
        $success = 'Pliki certyfikatu usunięte.';
    }

    if ($act === 'save_settings') {
        $org_name = trim($_POST['org_name_setting'] ?? '');
        $org_krs  = preg_replace('/\D/', '', $_POST['org_krs_setting'] ?? '');
        if ($org_name) {
            try {
                $s = db()->prepare("INSERT INTO settings (key_,value) VALUES (?,?) ON CONFLICT(key_) DO UPDATE SET value=excluded.value");
                if ($org_name) $s->execute(['org_name', $org_name]);
                if ($org_krs)  $s->execute(['org_krs',  $org_krs]);
                $success = 'Ustawienia zapisane.';
            } catch (\Throwable $e) { $error = $e->getMessage(); }
        }
    }
}

// ── Odczyt danych ─────────────────────────────────────────────────────────────
$cert       = $is_auth ? cr_read_cert($crt_file) : null;
$app_key    = defined('APP_KEY') ? APP_KEY : '—';
$openssl_ok = extension_loaded('openssl');

$org_name_db = '';
$org_krs_db  = '';
if ($is_auth) {
    try {
        $org_name_db = db_one("SELECT value FROM settings WHERE key_='org_name'")['value'] ?? '';
        $org_krs_db  = db_one("SELECT value FROM settings WHERE key_='org_krs'")['value'] ?? '';
    } catch (\Throwable $e) {}
}

// ── System info ───────────────────────────────────────────────────────────────
$sys = [];
if ($is_auth) {
    $sys = [
        'PHP'       => PHP_VERSION,
        'OpenSSL'   => extension_loaded('openssl') ? openssl_get_cert_locations()['default_cert_dir'] ?? 'tak' : '❌ brak',
        'APP_URL'   => defined('APP_URL') ? APP_URL : '—',
        'DB_TYPE'   => defined('DB_TYPE') ? DB_TYPE : '—',
        'UPLOAD_DIR'=> defined('UPLOAD_DIR') ? (is_writable(UPLOAD_DIR) ? '✅ '.UPLOAD_DIR : '❌ '.UPLOAD_DIR) : '—',
        'certs/'    => is_dir($certs_dir) ? (is_writable($certs_dir) ? '✅ zapisywalny' : '⚠️ tylko do odczytu') : '❌ brak katalogu',
        'Strefa cz.'=> date_default_timezone_get(),
    ];
}

$app_version = '';
try { require_once __DIR__ . '/includes/version.php'; $app_version = 'v' . app_version()['hash']; } catch(\Throwable $e) {}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Creator Panel<?= $is_auth ? ' — ' . h(defined('ORG_NAME') ? ORG_NAME : '') : '' ?></title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{font-size:14px}
body{font-family:system-ui,-apple-system,sans-serif;background:#0f172a;color:#e2e8f0;min-height:100vh}

/* Login */
.login-wrap{display:flex;align-items:center;justify-content:center;min-height:100vh}
.login-card{background:#1e293b;border:1px solid #334155;border-radius:.75rem;padding:2.5rem 2rem;width:360px}
.login-icon{font-size:3rem;text-align:center;margin-bottom:1rem}
.login-title{font-size:1.3rem;font-weight:700;text-align:center;color:#f8fafc;margin-bottom:.4rem}
.login-sub{font-size:.82rem;color:#64748b;text-align:center;margin-bottom:1.75rem}

/* Layout */
.cr-wrap{max-width:960px;margin:0 auto;padding:2rem 1rem}
.cr-header{display:flex;align-items:center;gap:1rem;margin-bottom:2rem;padding-bottom:1rem;border-bottom:1px solid #334155}
.cr-title{font-size:1.5rem;font-weight:700;color:#f8fafc}
.cr-subtitle{font-size:.82rem;color:#64748b;margin-top:.2rem}
.cr-badge{display:inline-block;padding:.2rem .6rem;border-radius:4px;font-size:.72rem;font-weight:700;background:#0f3460;color:#7dd3fc;border:1px solid #1d4ed8}

/* Cards */
.card{background:#1e293b;border:1px solid #334155;border-radius:.6rem;margin-bottom:1.25rem;overflow:hidden}
.card-header{padding:.75rem 1.25rem;background:#1a2744;border-bottom:1px solid #334155;font-weight:600;font-size:.9rem;display:flex;align-items:center;justify-content:space-between}
.card-body{padding:1.25rem}
.card-danger .card-header{background:#450a0a;border-color:#7f1d1d;color:#fca5a5}

/* Forms */
.form-group{margin-bottom:.9rem}
label.fl{display:block;font-size:.8rem;color:#94a3b8;margin-bottom:.35rem;font-weight:500}
input.fi,select.fi,textarea.fi{width:100%;padding:.5rem .75rem;background:#0f172a;border:1px solid #334155;border-radius:.375rem;color:#e2e8f0;font-size:.88rem;font-family:inherit}
input.fi:focus,select.fi:focus{outline:none;border-color:#38bdf8}
input.fi::placeholder{color:#475569}
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:.9rem}
.span2{grid-column:1/-1}

/* Buttons */
.btn{display:inline-flex;align-items:center;gap:.35rem;padding:.45rem 1rem;border-radius:.375rem;font-size:.84rem;font-weight:500;cursor:pointer;border:none;text-decoration:none;transition:opacity .12s}
.btn:hover{opacity:.82}
.btn-primary{background:#0284c7;color:#fff}
.btn-success{background:#16a34a;color:#fff}
.btn-danger {background:#dc2626;color:#fff}
.btn-ghost  {background:#334155;color:#e2e8f0}
.btn-warning{background:#d97706;color:#fff}
.btn-sm{padding:.25rem .6rem;font-size:.76rem}

/* Alerts */
.alert{padding:.75rem 1rem;border-radius:.375rem;margin-bottom:1rem;font-size:.85rem}
.alert-success{background:#14532d;color:#86efac;border:1px solid #166534}
.alert-danger {background:#450a0a;color:#fca5a5;border:1px solid #7f1d1d}
.alert-warn   {background:#451a03;color:#fdba74;border:1px solid #92400e}
.alert-info   {background:#1e3a5f;color:#7dd3fc;border:1px solid #1e40af}

/* Code */
.code-block{font-family:monospace;background:#0f172a;border:1px solid #334155;border-radius:.375rem;padding:.75rem 1rem;font-size:.8rem;color:#7dd3fc;word-break:break-all;position:relative}
.copy-btn{position:absolute;top:.4rem;right:.4rem;background:#334155;border:none;color:#94a3b8;border-radius:4px;padding:.2rem .5rem;font-size:.7rem;cursor:pointer}
.copy-btn:hover{color:#fff}

/* Stats */
.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:1rem;margin-bottom:1.25rem}
.stat{background:#1e293b;border:1px solid #334155;border-radius:.5rem;padding:1rem 1.25rem;text-align:center}
.stat-val{font-size:1.5rem;font-weight:700}
.stat-label{font-size:.72rem;color:#64748b;margin-top:.2rem}

/* Progress */
.bar-wrap{height:8px;background:#334155;border-radius:4px;overflow:hidden;margin-top:.4rem}
.bar{height:100%;border-radius:4px}

/* Sys table */
.sys-table{width:100%;border-collapse:collapse;font-size:.84rem}
.sys-table td{padding:.45rem .75rem;border-bottom:1px solid #334155}
.sys-table td:first-child{color:#64748b;width:40%}
.sys-table tr:last-child td{border:none}
</style>
</head>
<body>

<?php if (!$is_auth): ?>
<!-- ══ LOGIN ══════════════════════════════════════════════════════════════════ -->
<div class="login-wrap">
  <div class="login-card">
    <div class="login-icon">🛡</div>
    <div class="login-title">Creator Panel</div>
    <div class="login-sub">Lokalny panel twórcy — zarządzanie instalacją</div>
    <?php if ($error): ?>
    <div class="alert alert-danger"><?= h($error) ?></div>
    <?php endif; ?>
    <form method="post">
      <div class="form-group">
        <label class="fl">Hasło twórcy</label>
        <input type="password" name="creator_pass" class="fi" autofocus autocomplete="off" placeholder="••••••••">
      </div>
      <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center">🔓 Wejdź</button>
    </form>
    <div style="text-align:center;margin-top:1.5rem;font-size:.75rem;color:#475569">
      <?php if ($app_version): ?>v <?= h($app_version) ?> · <?php endif; ?>
      <?= h(defined('ORG_NAME') ? ORG_NAME : '') ?>
    </div>
  </div>
</div>

<?php else: ?>
<!-- ══ PANEL ═══════════════════════════════════════════════════════════════════ -->
<div class="cr-wrap">

  <div class="cr-header">
    <div>
      <div class="cr-title">🛡 Creator Panel</div>
      <div class="cr-subtitle"><?= h(defined('ORG_NAME') ? ORG_NAME : '') ?> <?= h(defined('APP_URL') ? '— '.APP_URL : '') ?></div>
    </div>
    <div style="display:flex;gap:.75rem;align-items:center;margin-left:auto">
      <span class="cr-badge">CREATOR MODE</span>
      <?php if ($app_version): ?><span style="font-size:.75rem;color:#64748b;font-family:monospace"><?= h($app_version) ?></span><?php endif; ?>
      <a href="?logout=1" class="btn btn-ghost btn-sm">🚪 Wyloguj</a>
    </div>
  </div>

  <?php if ($error):   ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>
  <?php if ($success): ?><div class="alert alert-success"><?= h($success) ?></div><?php endif; ?>

  <!-- ── Status certyfikatu ──────────────────────────────────────────────── -->
  <div class="card">
    <div class="card-header">
      🔐 Certyfikat instalacyjny
      <?php if ($cert): ?>
        <?php if ($cert['expired']): ?>
        <span style="color:#ef4444;font-size:.8rem">Wygasł <?= abs($cert['days_left']) ?> dni temu</span>
        <?php elseif ($cert['warning']): ?>
        <span style="color:#f59e0b;font-size:.8rem">Wygasa za <?= $cert['days_left'] ?> dni</span>
        <?php else: ?>
        <span style="color:#86efac;font-size:.8rem">✓ Aktywny — <?= $cert['days_left'] ?> dni</span>
        <?php endif; ?>
      <?php else: ?>
      <span style="color:#fca5a5;font-size:.8rem">Brak certyfikatu</span>
      <?php endif; ?>
    </div>
    <div class="card-body">
    <?php if ($cert): ?>
      <?php
      $pct = max(0, min(100, round($cert['days_left'] / 730 * 100)));
      $bc  = $cert['expired'] ? '#ef4444' : ($cert['warning'] ? '#f59e0b' : '#22c55e');
      ?>
      <div style="display:flex;justify-content:space-between;font-size:.8rem;color:#64748b;margin-bottom:.3rem">
        <span><?= h($cert['org']) ?> · KRS <?= h($cert['krs'] ?: '—') ?></span>
        <span>do <?= h($cert['valid_to']) ?></span>
      </div>
      <div class="bar-wrap"><div class="bar" style="width:<?= $pct ?>%;background:<?= $bc ?>"></div></div>

      <?php
      $has_sig   = file_exists($sig_file);
      $sig_ok    = false;
      if ($has_sig) {
          $sig_stored  = trim(file_get_contents($sig_file));
          $sig_expected = hash_hmac('sha256', $cert['pem'], $app_key);
          $sig_ok = hash_equals($sig_expected, $sig_stored);
      }
      ?>
      <div style="margin-top:.75rem;font-size:.8rem;color:<?= $sig_ok ? '#86efac' : '#fca5a5' ?>">
        <?= $sig_ok ? '✓ Podpis HMAC zgodny z APP_KEY' : '✗ Podpis HMAC niezgodny — wygeneruj certyfikat ponownie' ?>
      </div>

      <?php if ($cert['expired'] || $cert['warning'] || !$sig_ok): ?>
      <div class="alert alert-<?= $cert['expired'] || !$sig_ok ? 'danger' : 'warn' ?>" style="margin-top:.75rem;margin-bottom:0">
        <?= $cert['expired'] ? '🔴 Certyfikat wygasł — aplikacja może być zablokowana.' : ($sig_ok ? '⚠️ Certyfikat wygasa wkrótce.' : '🔴 Podpis nieprawidłowy — certyfikat z innej instalacji lub APP_KEY się zmienił.') ?>
        Wygeneruj nowy certyfikat poniżej.
      </div>
      <?php endif; ?>
    <?php else: ?>
      <div class="alert alert-danger" style="margin:0">
        🔴 Brak certyfikatu instalacyjnego. Aplikacja jest zablokowana. Wygeneruj certyfikat poniżej.
      </div>
    <?php endif; ?>
    </div>
  </div>

  <!-- ── Generuj certyfikat ──────────────────────────────────────────────── -->
  <div class="card">
    <div class="card-header">⚙️ <?= $cert ? 'Odnów certyfikat' : 'Wygeneruj certyfikat' ?></div>
    <div class="card-body">
    <?php if (!$openssl_ok): ?>
    <div class="alert alert-danger">Brak rozszerzenia OpenSSL — nie można generować certyfikatów przez przeglądarkę.</div>
    <?php else: ?>
    <form method="post">
      <input type="hidden" name="_csrf"    value="<?= h(cr_csrf()) ?>">
      <input type="hidden" name="_action" value="generate_cert">
      <div class="grid2">
        <div class="form-group">
          <label class="fl">Numer KRS <span style="color:#ef4444">*</span></label>
          <input type="text" name="krs" class="fi"
                 value="<?= h($cert['krs'] ?? $org_krs_db) ?>"
                 placeholder="0000000000" maxlength="10" required>
        </div>
        <div class="form-group">
          <label class="fl">Pełna nazwa organizacji <span style="color:#ef4444">*</span></label>
          <input type="text" name="org_name" class="fi"
                 value="<?= h($cert['org'] ?? $org_name_db) ?>"
                 placeholder="Fundacja XYZ" required>
        </div>
      </div>
      <button type="submit" class="btn <?= $cert ? 'btn-warning' : 'btn-success' ?>"
              <?= $cert ? 'onclick="return confirm(\'Nowy certyfikat nadpisze obecny. Kontynuować?\')"' : '' ?>>
        🔑 <?= $cert ? 'Odnów certyfikat (730 dni)' : 'Generuj certyfikat' ?>
      </button>
    </form>
    <?php endif; ?>
    </div>
  </div>

  <!-- ── APP_KEY ─────────────────────────────────────────────────────────── -->
  <div class="card">
    <div class="card-header">🗝 APP_KEY instalacji</div>
    <div class="card-body">
      <div class="code-block" id="ak-block" style="filter:blur(5px);cursor:pointer" onclick="this.style.filter=''">
        <?= h($app_key) ?>
        <button class="copy-btn" onclick="event.stopPropagation();document.getElementById('ak-block').style.filter='';navigator.clipboard.writeText(<?= json_encode($app_key) ?>);this.textContent='✓'">📋</button>
      </div>
      <div style="font-size:.76rem;color:#64748b;margin-top:.5rem">Kliknij aby odsłonić. Używany jako klucz HMAC certyfikatu i tokenu CRON.</div>
    </div>
  </div>

  <!-- ── Ustawienia org ──────────────────────────────────────────────────── -->
  <div class="card">
    <div class="card-header">🏢 Dane organizacji (baza)</div>
    <div class="card-body">
      <form method="post">
        <input type="hidden" name="_csrf"   value="<?= h(cr_csrf()) ?>">
        <input type="hidden" name="_action" value="save_settings">
        <div class="grid2">
          <div class="form-group">
            <label class="fl">Nazwa organizacji</label>
            <input type="text" name="org_name_setting" class="fi"
                   value="<?= h($org_name_db ?: (defined('ORG_NAME') ? ORG_NAME : '')) ?>">
          </div>
          <div class="form-group">
            <label class="fl">KRS</label>
            <input type="text" name="org_krs_setting" class="fi"
                   value="<?= h($org_krs_db) ?>" maxlength="10" placeholder="0000000000">
          </div>
        </div>
        <button type="submit" class="btn btn-primary">💾 Zapisz</button>
      </form>
    </div>
  </div>

  <!-- ── Status systemu ──────────────────────────────────────────────────── -->
  <div class="card">
    <div class="card-header">📊 Status systemu</div>
    <div class="card-body" style="padding:0">
      <table class="sys-table">
        <?php foreach ($sys as $k => $v): ?>
        <tr><td><?= h($k) ?></td><td style="font-family:monospace;font-size:.8rem"><?= h($v) ?></td></tr>
        <?php endforeach; ?>
        <tr><td>Certyfikat</td><td><?= $cert ? ($cert['expired'] ? '<span style="color:#ef4444">Wygasł</span>' : '<span style="color:#86efac">OK ('.$cert['days_left'].' dni)</span>') : '<span style="color:#ef4444">Brak</span>' ?></td></tr>
        <tr><td>config.php</td><td style="font-size:.8rem;font-family:monospace"><?= h(__DIR__ . '/config.php') ?></td></tr>
        <tr><td>certs/</td><td style="font-size:.8rem;font-family:monospace"><?= h($certs_dir) ?></td></tr>
      </table>
    </div>
  </div>

  <!-- ── Szybkie linki ───────────────────────────────────────────────────── -->
  <div class="card">
    <div class="card-header">🔗 Szybkie linki</div>
    <div class="card-body" style="display:flex;flex-wrap:wrap;gap:.75rem">
      <?php if (defined('APP_URL')): ?>
      <a href="<?= h(APP_URL) ?>/admin/" class="btn btn-ghost" target="_blank">⚙️ Panel admina</a>
      <a href="<?= h(APP_URL) ?>/admin/app_license.php" class="btn btn-ghost" target="_blank">🔐 Certyfikat (admin)</a>
      <a href="<?= h(APP_URL) ?>/admin/cron_setup.php" class="btn btn-ghost" target="_blank">⏰ CRON</a>
      <a href="<?= h(APP_URL) ?>/admin/version.php" class="btn btn-ghost" target="_blank">📋 Historia zmian</a>
      <?php endif; ?>
      <?php if (defined('APP_URL')): ?>
      <a href="<?= h(rtrim(APP_URL,'/') . '/../licensemanager/') ?>" class="btn btn-ghost" target="_blank">🛡 License Manager</a>
      <?php endif; ?>
    </div>
  </div>

  <!-- ── Usuń certyfikat ─────────────────────────────────────────────────── -->
  <?php if ($cert): ?>
  <div class="card card-danger">
    <div class="card-header">⚠️ Strefa niebezpieczna</div>
    <div class="card-body">
      <form method="post" onsubmit="return confirm('Usunąć pliki certyfikatu? Aplikacja zostanie zablokowana do czasu wygenerowania nowego.')">
        <input type="hidden" name="_csrf"   value="<?= h(cr_csrf()) ?>">
        <input type="hidden" name="_action" value="clear_cert">
        <button type="submit" class="btn btn-danger">🗑 Usuń certyfikat (zablokuje aplikację)</button>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <div style="text-align:center;font-size:.72rem;color:#334155;padding:1rem 0">
    Creator Panel · Platforma NGO · <?= h($app_version) ?> · <?= date('Y') ?>
  </div>

</div><!-- /cr-wrap -->
<?php endif; ?>

</body>
</html>
