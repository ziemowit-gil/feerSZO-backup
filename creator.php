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
<title>creator@<?= h(defined('ORG_NAME') ? strtolower(preg_replace('/\s+/', '-', ORG_NAME)) : 'local') ?></title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#0a0a0a; --bg2:#111; --border:#222;
  --fg:#c8c8c8; --dim:#555; --hi:#e8e8e8;
  --green:#3d9970; --red:#c0392b; --yellow:#c9a227;
  --mono:'Courier New',Courier,monospace;
}
html,body{min-height:100vh;background:var(--bg);color:var(--fg);font-family:var(--mono);font-size:13px;line-height:1.6}

/* ── LOGIN ── */
.login{display:flex;align-items:center;justify-content:center;min-height:100vh}
.login-box{width:340px;border:1px solid var(--border);padding:2rem}
.login-prompt{color:var(--dim);margin-bottom:1.5rem;font-size:.85rem}
.login-prompt strong{color:var(--hi);display:block;font-size:1rem;margin-bottom:.25rem}
.login-prompt span{display:block}

/* ── LAYOUT ── */
.wrap{max-width:900px;margin:0 auto;padding:1.5rem 1rem}
.topbar{display:flex;align-items:baseline;gap:1.5rem;border-bottom:1px solid var(--border);padding-bottom:.75rem;margin-bottom:1.5rem}
.topbar-prompt{color:var(--dim);font-size:.8rem}
.topbar-prompt b{color:var(--green)}
.topbar-prompt em{color:var(--hi);font-style:normal}
.topbar-right{margin-left:auto;display:flex;align-items:center;gap:1rem}
.ver{color:var(--dim);font-size:.75rem}

/* ── SECTIONS ── */
.section{border:1px solid var(--border);margin-bottom:1rem}
.section-head{
  padding:.4rem .75rem;background:var(--bg2);
  border-bottom:1px solid var(--border);
  display:flex;align-items:center;justify-content:space-between;
  font-size:.78rem;color:var(--dim);letter-spacing:.05em;text-transform:uppercase
}
.section-head b{color:var(--fg);text-transform:none;letter-spacing:0;font-size:.82rem}
.section-body{padding:.75rem}

/* ── STATUS LINE ── */
.status-line{display:flex;align-items:center;gap:.75rem;font-size:.82rem;margin-bottom:.5rem}
.dot{width:8px;height:8px;border-radius:50%;flex-shrink:0}
.dot-ok{background:var(--green)}
.dot-warn{background:var(--yellow)}
.dot-err{background:var(--red)}

/* ── PROGRESS ── */
.bar-wrap{height:3px;background:var(--border);margin-top:.35rem;overflow:hidden}
.bar{height:100%;transition:width .3s}

/* ── TABLE ── */
.kv{width:100%;border-collapse:collapse;font-size:.82rem}
.kv td{padding:.3rem .5rem;border-bottom:1px solid var(--border)}
.kv td:first-child{color:var(--dim);width:38%;padding-right:1rem}
.kv tr:last-child td{border:none}

/* ── FORMS ── */
.fg{margin-bottom:.65rem}
label.lbl{display:block;font-size:.75rem;color:var(--dim);margin-bottom:.25rem;letter-spacing:.04em;text-transform:uppercase}
input.inp,select.inp{width:100%;padding:.4rem .6rem;background:var(--bg);border:1px solid var(--border);color:var(--fg);font-family:var(--mono);font-size:.82rem}
input.inp:focus,select.inp:focus{outline:none;border-color:var(--fg)}
input.inp::placeholder{color:var(--dim)}
.row2{display:grid;grid-template-columns:1fr 1fr;gap:.65rem}

/* ── BUTTONS ── */
.btn{display:inline-flex;align-items:center;gap:.35rem;padding:.35rem .85rem;font-family:var(--mono);font-size:.8rem;cursor:pointer;border:1px solid;text-decoration:none;background:none}
.btn:hover{opacity:.75}
.btn-ok   {border-color:var(--green);color:var(--green)}
.btn-bad  {border-color:var(--red);color:var(--red)}
.btn-warn {border-color:var(--yellow);color:var(--yellow)}
.btn-dim  {border-color:var(--border);color:var(--dim)}

/* ── ALERTS ── */
.msg{padding:.45rem .75rem;margin-bottom:.75rem;font-size:.82rem;border-left:3px solid}
.msg-ok  {border-color:var(--green);color:var(--green)}
.msg-err {border-color:var(--red);color:var(--red)}

/* ── CODE ── */
.code{font-family:var(--mono);background:var(--bg2);border:1px solid var(--border);padding:.5rem .75rem;font-size:.78rem;color:#7dd3fc;word-break:break-all;position:relative;cursor:pointer}
.code::after{content:'[click to reveal]';position:absolute;inset:0;background:var(--bg2);display:flex;align-items:center;justify-content:center;color:var(--dim);font-size:.75rem}
.code.revealed::after{display:none}
.links{display:flex;flex-wrap:wrap;gap:.5rem}
</style>
</head>
<body>

<?php if (!$is_auth): ?>
<div class="login">
  <div class="login-box">
    <div class="login-prompt">
      <strong>creator@local:~$</strong>
      <span>Wymagane uwierzytelnienie.</span>
      <span style="color:var(--dim);font-size:.75rem;margin-top:.5rem;display:block"><?= h(defined('ORG_NAME') ? ORG_NAME : '') ?> <?= h($app_version) ?></span>
    </div>
    <?php if ($error): ?>
    <div class="msg msg-err">&gt; <?= h($error) ?></div>
    <?php endif; ?>
    <form method="post">
      <div class="fg" style="display:flex;gap:.5rem;align-items:center">
        <span style="color:var(--dim);flex-shrink:0">password:</span>
        <input type="password" name="creator_pass" class="inp" autofocus autocomplete="off" style="flex:1">
        <button type="submit" class="btn btn-ok">enter</button>
      </div>
    </form>
  </div>
</div>

<?php else: ?>
<div class="wrap">

  <!-- topbar -->
  <div class="topbar">
    <div class="topbar-prompt">
      <b>creator</b><span style="color:var(--dim)">@</span><em><?= h(defined('ORG_NAME') ? strtolower(preg_replace('/\s+/','-',ORG_NAME)) : 'local') ?></em>
      <span style="color:var(--dim)">:~$</span>
      <span style="color:var(--hi)"> ./creator.php</span>
    </div>
    <div class="topbar-right">
      <?php if ($app_version): ?><span class="ver"><?= h($app_version) ?></span><?php endif; ?>
      <a href="?logout=1" class="btn btn-dim" style="font-size:.72rem;padding:.2rem .6rem">logout</a>
    </div>
  </div>

  <?php if ($error):   ?><div class="msg msg-err">&gt;&gt; ERROR: <?= h($error) ?></div><?php endif; ?>
  <?php if ($success): ?><div class="msg msg-ok">&gt;&gt; OK: <?= h($success) ?></div><?php endif; ?>

  <!-- cert status -->
  <?php
  $has_sig = file_exists($sig_file);
  $sig_ok  = false;
  if ($cert && $has_sig) {
      $sig_stored   = trim(file_get_contents($sig_file));
      $sig_expected = hash_hmac('sha256', $cert['pem'], $app_key);
      $sig_ok       = hash_equals($sig_expected, $sig_stored);
  }
  $pct = $cert ? max(0, min(100, round($cert['days_left'] / 730 * 100))) : 0;
  $bc  = $cert ? ($cert['expired'] ? 'var(--red)' : ($cert['warning'] ? 'var(--yellow)' : 'var(--green)')) : 'var(--red)';
  ?>
  <div class="section">
    <div class="section-head">
      <b>cert.status</b>
      <span>
        <?php if (!$cert): ?>
          <span style="color:var(--red)">[MISSING]</span>
        <?php elseif ($cert['expired']): ?>
          <span style="color:var(--red)">[EXPIRED <?= abs($cert['days_left']) ?>d ago]</span>
        <?php elseif ($cert['warning']): ?>
          <span style="color:var(--yellow)">[EXPIRING in <?= $cert['days_left'] ?>d]</span>
        <?php else: ?>
          <span style="color:var(--green)">[OK <?= $cert['days_left'] ?>d left]</span>
        <?php endif; ?>
      </span>
    </div>
    <div class="section-body">
      <?php if ($cert): ?>
      <table class="kv">
        <tr><td>org</td><td><?= h($cert['org']) ?></td></tr>
        <tr><td>krs</td><td><?= h($cert['krs'] ?: '—') ?></td></tr>
        <tr><td>valid</td><td><?= h($cert['valid_from']) ?> → <?= h($cert['valid_to']) ?></td></tr>
        <tr><td>hmac</td><td style="color:<?= $sig_ok ? 'var(--green)' : 'var(--red)' ?>"><?= $sig_ok ? '✓ ok' : '✗ mismatch — regenerate' ?></td></tr>
      </table>
      <div class="bar-wrap" style="margin-top:.5rem"><div class="bar" style="width:<?= $pct ?>%;background:<?= $bc ?>"></div></div>
      <?php else: ?>
      <div style="color:var(--red)">! no certificate found — app may be blocked</div>
      <?php endif; ?>
    </div>
  </div>

  <!-- generate cert -->
  <div class="section">
    <div class="section-head"><b><?= $cert ? 'cert.renew' : 'cert.generate' ?></b> <span>RSA-2048 · SHA-256 · 730d</span></div>
    <div class="section-body">
    <?php if (!$openssl_ok): ?>
    <div class="msg msg-err">! openssl extension missing — use CLI: php cli/generatorCertyfikatu.php</div>
    <?php else: ?>
    <form method="post">
      <input type="hidden" name="_csrf"   value="<?= h(cr_csrf()) ?>">
      <input type="hidden" name="_action" value="generate_cert">
      <div class="row2" style="margin-bottom:.65rem">
        <div class="fg">
          <label class="lbl">krs *</label>
          <input type="text" name="krs" class="inp" value="<?= h($cert['krs'] ?? $org_krs_db) ?>" placeholder="0000000000" maxlength="10" required>
        </div>
        <div class="fg">
          <label class="lbl">org_name *</label>
          <input type="text" name="org_name" class="inp" value="<?= h($cert['org'] ?? $org_name_db) ?>" placeholder="Fundacja XYZ" required>
        </div>
      </div>
      <button type="submit" class="btn <?= $cert ? 'btn-warn' : 'btn-ok' ?>"
              <?= $cert ? 'onclick="return confirm(\'overwrite current cert?\')"' : '' ?>>
        &gt; <?= $cert ? 'renew_cert()' : 'generate_cert()' ?>
      </button>
    </form>
    <?php endif; ?>
    </div>
  </div>

  <!-- app_key -->
  <div class="section">
    <div class="section-head"><b>app.key</b> <span style="color:var(--dim)">[HMAC seed · CRON token]</span></div>
    <div class="section-body">
      <div class="code" id="ak" onclick="this.classList.add('revealed')"><?= h($app_key) ?></div>
      <div style="margin-top:.4rem;display:flex;gap:.5rem">
        <button class="btn btn-dim" style="font-size:.72rem" onclick="document.getElementById('ak').classList.add('revealed')">reveal</button>
        <button class="btn btn-dim" style="font-size:.72rem" onclick="navigator.clipboard.writeText(<?= json_encode($app_key) ?>);this.textContent='copied'">copy</button>
      </div>
    </div>
  </div>

  <!-- org settings -->
  <div class="section">
    <div class="section-head"><b>org.settings</b></div>
    <div class="section-body">
      <form method="post">
        <input type="hidden" name="_csrf"   value="<?= h(cr_csrf()) ?>">
        <input type="hidden" name="_action" value="save_settings">
        <div class="row2" style="margin-bottom:.65rem">
          <div class="fg">
            <label class="lbl">org_name</label>
            <input type="text" name="org_name_setting" class="inp" value="<?= h($org_name_db ?: (defined('ORG_NAME') ? ORG_NAME : '')) ?>">
          </div>
          <div class="fg">
            <label class="lbl">org_krs</label>
            <input type="text" name="org_krs_setting" class="inp" value="<?= h($org_krs_db) ?>" maxlength="10" placeholder="0000000000">
          </div>
        </div>
        <button type="submit" class="btn btn-ok">&gt; save()</button>
      </form>
    </div>
  </div>

  <!-- sys info -->
  <div class="section">
    <div class="section-head"><b>sys.info</b></div>
    <div class="section-body" style="padding:0">
      <table class="kv">
        <?php foreach ($sys as $k => $v): ?>
        <tr><td><?= h($k) ?></td><td><?= h($v) ?></td></tr>
        <?php endforeach; ?>
        <tr><td>cert</td><td style="color:<?= $cert ? ($cert['expired'] ? 'var(--red)' : 'var(--green)') : 'var(--red)' ?>"><?= $cert ? ($cert['expired'] ? 'expired' : 'ok ('.$cert['days_left'].'d)') : 'missing' ?></td></tr>
        <tr><td>config</td><td style="font-size:.75rem"><?= h(__DIR__.'/config.php') ?></td></tr>
        <tr><td>certs_dir</td><td style="font-size:.75rem"><?= h($certs_dir) ?></td></tr>
      </table>
    </div>
  </div>

  <!-- links -->
  <div class="section">
    <div class="section-head"><b>links</b></div>
    <div class="section-body links">
      <?php if (defined('APP_URL')): ?>
      <a href="<?= h(APP_URL) ?>/admin/" class="btn btn-dim" target="_blank">admin/</a>
      <a href="<?= h(APP_URL) ?>/admin/app_license.php" class="btn btn-dim" target="_blank">cert_admin</a>
      <a href="<?= h(APP_URL) ?>/admin/cron_setup.php" class="btn btn-dim" target="_blank">cron</a>
      <a href="<?= h(APP_URL) ?>/admin/version.php" class="btn btn-dim" target="_blank">changelog</a>
      <a href="<?= h(rtrim(APP_URL,'/') . '/../licensemanager/') ?>" class="btn btn-dim" target="_blank">license_mgr</a>
      <?php endif; ?>
    </div>
  </div>

  <!-- danger -->
  <?php if ($cert): ?>
  <div class="section" style="border-color:var(--red)">
    <div class="section-head" style="color:var(--red)"><b>danger.zone</b></div>
    <div class="section-body">
      <form method="post" onsubmit="return confirm('delete cert files? app will be blocked.')">
        <input type="hidden" name="_csrf"   value="<?= h(cr_csrf()) ?>">
        <input type="hidden" name="_action" value="clear_cert">
        <button type="submit" class="btn btn-bad">&gt; delete_cert() // blocks app</button>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <div style="color:var(--dim);font-size:.72rem;padding:.75rem 0;border-top:1px solid var(--border);margin-top:.5rem">
    creator.php · <?= h(defined('ORG_NAME') ? ORG_NAME : '') ?> · <?= h($app_version) ?>
  </div>

</div>
<?php endif; ?>

</body>
</html>
