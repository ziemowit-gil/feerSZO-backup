<?php
/**
 * admin/app_license.php — Zarządzanie certyfikatem instalacyjnym i kluczem APP_KEY.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

require_role('admin');
$PAGE_TITLE = 'Certyfikat i licencja';

$certs_dir = dirname(__DIR__) . '/certs';
$crt_file  = $certs_dir . '/app.crt';
$key_file  = $certs_dir . '/app.key';
$sig_file  = $certs_dir . '/app.sig';

$openssl_ok = extension_loaded('openssl');
$error      = '';
$success    = '';

// ── Odczyt aktualnego certyfikatu ─────────────────────────────────────────────
function _read_cert(string $crt_file): ?array {
    if (!file_exists($crt_file)) return null;
    $pem    = file_get_contents($crt_file);
    $parsed = @openssl_x509_parse($pem);
    if (!$parsed) return null;
    $days_left = (int)ceil(($parsed['validTo_time_t'] - time()) / 86400);
    return [
        'pem'       => $pem,
        'krs'       => preg_replace('/^KRS:/', '', $parsed['subject']['serialNumber'] ?? ''),
        'org'       => $parsed['subject']['CN'] ?? '',
        'valid_from'=> date('d.m.Y', $parsed['validFrom_time_t']),
        'valid_to'  => date('d.m.Y', $parsed['validTo_time_t']),
        'valid_to_ts'=> $parsed['validTo_time_t'],
        'days_left' => $days_left,
        'expired'   => $days_left <= 0,
        'warning'   => $days_left > 0 && $days_left <= 30,
        'serial'    => $parsed['serialNumber'] ?? '',
        'issuer'    => $parsed['issuer']['O'] ?? '',
    ];
}

// ── Generowanie certyfikatu ────────────────────────────────────────────────────
function _generate_cert(string $krs, string $org, string $certs_dir, string $crt_file, string $key_file, string $sig_file): array {
    if (!extension_loaded('openssl')) return ['ok' => false, 'error' => 'Brak rozszerzenia OpenSSL.'];
    if (!$krs || !$org)             return ['ok' => false, 'error' => 'KRS i nazwa organizacji są wymagane.'];

    if (!is_dir($certs_dir)) mkdir($certs_dir, 0755, true);

    $pkey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    if (!$pkey) return ['ok' => false, 'error' => 'Generowanie klucza nie powiodło się: ' . openssl_error_string()];

    $dn = [
        'C'            => 'PL',
        'ST'           => 'Polska',
        'O'            => $org,
        'OU'           => 'Rejestr Umow',
        'CN'           => $org,
        'serialNumber' => 'KRS:' . $krs,
    ];

    $csr  = openssl_csr_new($dn, $pkey, ['digest_alg' => 'sha256']);
    if (!$csr) return ['ok' => false, 'error' => 'Tworzenie CSR nie powiodło się.'];

    $cert = openssl_csr_sign($csr, null, $pkey, 730, ['digest_alg' => 'sha256'], (int)(microtime(true) * 1000) & 0x7FFFFFFF);
    if (!$cert) return ['ok' => false, 'error' => 'Podpisywanie certyfikatu nie powiodło się.'];

    $cert_pem = $key_pem = '';
    openssl_x509_export($cert, $cert_pem);
    openssl_pkey_export($pkey, $key_pem);
    if (!$cert_pem || !$key_pem) return ['ok' => false, 'error' => 'Eksport PEM nie powiodł się.'];

    file_put_contents($crt_file, $cert_pem);
    file_put_contents($key_file, $key_pem);
    @chmod($key_file, 0600);

    $sig = hash_hmac('sha256', $cert_pem, APP_KEY);
    file_put_contents($sig_file, $sig);

    $parsed = openssl_x509_parse($cert_pem);
    return [
        'ok'        => true,
        'valid_to'  => date('d.m.Y', $parsed['validTo_time_t']),
        'days_left' => (int)ceil(($parsed['validTo_time_t'] - time()) / 86400),
        'sig'       => substr($sig, 0, 16) . '…',
    ];
}

// ── POST ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['_action'] ?? '';

    if ($act === 'generate') {
        $krs = preg_replace('/\D/', '', $_POST['krs'] ?? '');
        $org = trim($_POST['org_name'] ?? '');

        $result = _generate_cert($krs, $org, $certs_dir, $crt_file, $key_file, $sig_file);
        if ($result['ok']) {
            $success = "Certyfikat wygenerowany pomyślnie. Ważny do {$result['valid_to']} ({$result['days_left']} dni).";
        } else {
            $error = $result['error'];
        }
    }
}

$cert    = _read_cert($crt_file);
$has_sig = file_exists($sig_file);
$app_key = defined('APP_KEY') ? APP_KEY : '—';

$db_krs  = org_setting('org_krs');
$db_org  = org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : '');

include dirname(__DIR__) . '/includes/header.php';
?>

<style>
.cert-status-bar {
    height: 8px; border-radius: 4px;
    background: linear-gradient(to right, #22c55e var(--pct), #e5e7eb var(--pct));
}
.key-mask { font-family: monospace; letter-spacing: .05em; user-select: all; }
</style>

<nav aria-label="breadcrumb" class="mb-3">
  <ol class="breadcrumb" style="font-size:.8rem">
    <li class="breadcrumb-item"><a href="index.php">Admin</a></li>
    <li class="breadcrumb-item active">Certyfikat i licencja</li>
  </ol>
</nav>

<div class="d-flex align-items-center gap-2 mb-4">
  <h4 class="mb-0"><i class="bi bi-shield-lock me-2 text-primary"></i>Certyfikat instalacyjny i klucz aplikacji</h4>
</div>

<?php if ($error): ?>
<div class="alert alert-danger py-2"><i class="bi bi-exclamation-triangle me-1"></i><?= h($error) ?></div>
<?php endif; ?>
<?php if ($success): ?>
<div class="alert alert-success py-2"><i class="bi bi-check-circle me-1"></i><?= h($success) ?></div>
<?php endif; ?>

<div class="row g-3">
<div class="col-lg-7">

<!-- ── Stan certyfikatu ──────────────────────────────────────────────────── -->
<div class="card shadow-sm mb-3">
  <div class="card-header fw-semibold py-2 d-flex align-items-center justify-content-between">
    <span><i class="bi bi-file-earmark-lock2 me-1"></i>Certyfikat instalacyjny (x509)</span>
    <?php if ($cert): ?>
    <?php if ($cert['expired']): ?>
    <span class="badge bg-danger">Wygasł</span>
    <?php elseif ($cert['warning']): ?>
    <span class="badge bg-warning text-dark">Wygasa za <?= $cert['days_left'] ?> dni</span>
    <?php else: ?>
    <span class="badge bg-success">Aktywny</span>
    <?php endif; ?>
    <?php else: ?>
    <span class="badge bg-secondary">Brak certyfikatu</span>
    <?php endif; ?>
  </div>
  <div class="card-body">

  <?php if ($cert): ?>

    <?php if (!$openssl_ok): ?>
    <div class="alert alert-warning py-2 small">Brak rozszerzenia OpenSSL — nie można zweryfikować certyfikatu.</div>
    <?php endif; ?>

    <!-- Pasek ważności -->
    <?php
    $total_days  = 730;
    $elapsed     = $total_days - $cert['days_left'];
    $pct         = max(0, min(100, round(($cert['days_left'] / $total_days) * 100)));
    $bar_color   = $cert['expired'] ? '#ef4444' : ($cert['warning'] ? '#f59e0b' : '#22c55e');
    ?>
    <div class="mb-3">
      <div class="d-flex justify-content-between small text-muted mb-1">
        <span>Pozostało: <strong><?= $cert['days_left'] > 0 ? $cert['days_left'] . ' dni' : 'Wygasł' ?></strong></span>
        <span>Do: <strong><?= $cert['valid_to'] ?></strong></span>
      </div>
      <div style="height:8px;border-radius:4px;background:#e5e7eb;overflow:hidden">
        <div style="height:100%;width:<?= $pct ?>%;background:<?= $bar_color ?>;border-radius:4px;transition:width .3s"></div>
      </div>
    </div>

    <!-- Dane certyfikatu -->
    <div class="row g-2 small">
      <div class="col-sm-3 text-muted">Organizacja</div>
      <div class="col-sm-9 fw-semibold"><?= h($cert['org']) ?></div>
      <div class="col-sm-3 text-muted">KRS</div>
      <div class="col-sm-9 font-monospace"><?= h($cert['krs'] ?: '—') ?></div>
      <div class="col-sm-3 text-muted">Ważny od</div>
      <div class="col-sm-9"><?= h($cert['valid_from']) ?></div>
      <div class="col-sm-3 text-muted">Ważny do</div>
      <div class="col-sm-9 <?= $cert['expired'] ? 'text-danger fw-bold' : ($cert['warning'] ? 'text-warning fw-bold' : '') ?>"><?= h($cert['valid_to']) ?></div>
      <div class="col-sm-3 text-muted">Podpis HMAC</div>
      <div class="col-sm-9">
        <?php if ($has_sig): ?>
        <?php $sig_val = trim(file_get_contents($sig_file)); ?>
        <?php
        $expected_sig = hash_hmac('sha256', $cert['pem'], APP_KEY);
        $sig_ok = hash_equals($expected_sig, $sig_val);
        ?>
        <span class="badge <?= $sig_ok ? 'bg-success' : 'bg-danger' ?>">
          <?= $sig_ok ? '✓ Zgodny z APP_KEY' : '✗ Niezgodny — certyfikat z innej instalacji' ?>
        </span>
        <?php else: ?>
        <span class="badge bg-danger">Brak pliku .sig</span>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($cert['expired'] || $cert['warning']): ?>
    <div class="alert alert-<?= $cert['expired'] ? 'danger' : 'warning' ?> py-2 small mt-3 mb-0">
      <i class="bi bi-exclamation-triangle me-1"></i>
      <?= $cert['expired'] ? 'Certyfikat wygasł — aplikacja może być zablokowana. Wygeneruj nowy.' : 'Certyfikat wygasa wkrótce. Zalecana odnowa.' ?>
    </div>
    <?php endif; ?>

  <?php else: ?>
    <div class="text-center py-4 text-muted">
      <i class="bi bi-shield-x" style="font-size:2.5rem;color:#dc3545"></i>
      <div class="mt-2 fw-semibold text-danger">Brak certyfikatu instalacyjnego</div>
      <div class="small mt-1">Aplikacja wymaga certyfikatu do działania. Wygeneruj go poniżej.</div>
    </div>
  <?php endif; ?>

  </div>
</div>

<!-- ── Generuj / odnów certyfikat ───────────────────────────────────────── -->
<div class="card shadow-sm mb-3">
  <div class="card-header fw-semibold py-2">
    <i class="bi bi-arrow-clockwise me-1"></i>
    <?= $cert ? 'Odnów certyfikat' : 'Wygeneruj certyfikat' ?>
  </div>
  <div class="card-body">
    <?php if (!$openssl_ok): ?>
    <div class="alert alert-danger py-2 small">Brak rozszerzenia <code>openssl</code> w PHP — nie można generować certyfikatów przez przeglądarkę. Użyj CLI: <code>php cli/generatorCertyfikatu.php</code></div>
    <?php else: ?>
    <form method="post" class="row g-3">
      <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
      <input type="hidden" name="_action" value="generate">
      <div class="col-sm-4">
        <label class="form-label small fw-semibold">Numer KRS <span class="text-danger">*</span></label>
        <input type="text" name="krs" class="form-control form-control-sm font-monospace"
               value="<?= h($cert['krs'] ?? $db_krs) ?>"
               placeholder="0000000000" maxlength="10" required
               pattern="\d{10}" title="10 cyfr">
      </div>
      <div class="col-sm-8">
        <label class="form-label small fw-semibold">Pełna nazwa organizacji <span class="text-danger">*</span></label>
        <input type="text" name="org_name" class="form-control form-control-sm"
               value="<?= h($cert['org'] ?? $db_org) ?>"
               placeholder="np. Fundacja Edukacji Empatii Rozwoju FEER"
               maxlength="200" required>
      </div>
      <div class="col-12">
        <?php if ($cert): ?>
        <button type="submit" class="btn btn-warning"
                onclick="return confirm('Obecny certyfikat zostanie zastąpiony. Kontynuować?')">
          <i class="bi bi-arrow-clockwise me-1"></i>Odnów certyfikat (730 dni)
        </button>
        <?php else: ?>
        <button type="submit" class="btn btn-primary">
          <i class="bi bi-shield-check me-1"></i>Wygeneruj certyfikat
        </button>
        <?php endif; ?>
        <div class="form-text mt-1">Certyfikat self-signed RSA-2048, SHA-256, ważność 2 lata. Nie wymaga zewnętrznego CA.</div>
      </div>
    </form>
    <?php endif; ?>
  </div>
</div>

</div><!-- /col-7 -->
<div class="col-lg-5">

<!-- ── APP_KEY ──────────────────────────────────────────────────────────── -->
<div class="card shadow-sm mb-3">
  <div class="card-header fw-semibold py-2">
    <i class="bi bi-key me-1"></i>Klucz instalacji (APP_KEY)
  </div>
  <div class="card-body">
    <p class="small text-muted mb-2">Unikalny klucz tej instalacji. Wiąże certyfikat z aplikacją przez HMAC. <strong>Nie udostępniaj go.</strong></p>

    <div class="d-flex gap-2 align-items-stretch mb-3">
      <code id="app-key-display" class="flex-grow-1 p-2 rounded key-mask"
            style="background:#1e293b;color:#fde68a;font-size:.75rem;word-break:break-all;display:block;filter:blur(4px);transition:filter .2s">
        <?= h($app_key) ?>
      </code>
      <div class="d-flex flex-column gap-1">
        <button class="btn btn-sm btn-outline-secondary flex-grow-1"
                onclick="var el=document.getElementById('app-key-display');el.style.filter=el.style.filter?'':'blur(4px)'">
          <i class="bi bi-eye"></i>
        </button>
        <button class="btn btn-sm btn-outline-secondary flex-grow-1"
                onclick="navigator.clipboard.writeText(<?= json_encode($app_key) ?>);this.innerHTML='<i class=\'bi bi-check-lg\'></i>';setTimeout(()=>this.innerHTML='<i class=\'bi bi-clipboard\'></i>',1500)">
          <i class="bi bi-clipboard"></i>
        </button>
      </div>
    </div>

    <div class="alert alert-warning py-2 small mb-0">
      <i class="bi bi-exclamation-triangle me-1"></i>
      Zmiana APP_KEY w <code>config.php</code> unieważni certyfikat — konieczna będzie jego ponowna generacja.
    </div>
  </div>
</div>

<!-- ── Pliki certyfikatu ─────────────────────────────────────────────────── -->
<div class="card shadow-sm mb-3">
  <div class="card-header fw-semibold py-2"><i class="bi bi-folder2-open me-1"></i>Pliki w <code>certs/</code></div>
  <div class="card-body p-0">
    <table class="table table-sm mb-0">
      <thead class="table-light"><tr><th class="ps-3">Plik</th><th>Status</th><th>Rozmiar</th></tr></thead>
      <tbody>
        <?php foreach ([
            'app.crt' => 'Certyfikat x509',
            'app.key' => 'Klucz prywatny',
            'app.sig' => 'Podpis HMAC',
        ] as $fname => $label):
            $fpath = $certs_dir . '/' . $fname;
            $exists = file_exists($fpath);
        ?>
        <tr>
          <td class="ps-3">
            <code style="font-size:.78rem"><?= h($fname) ?></code><br>
            <span class="text-muted" style="font-size:.68rem"><?= h($label) ?></span>
          </td>
          <td>
            <?php if ($exists): ?>
            <span class="badge bg-success">OK</span>
            <?php else: ?>
            <span class="badge bg-danger">Brak</span>
            <?php endif; ?>
          </td>
          <td class="small text-muted">
            <?= $exists ? number_format(filesize($fpath)) . ' B' : '—' ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ── CLI fallback ─────────────────────────────────────────────────────── -->
<div class="card shadow-sm border-secondary">
  <div class="card-header fw-semibold py-2 small"><i class="bi bi-terminal me-1"></i>Alternatywnie — przez SSH</div>
  <div class="card-body p-0">
    <?php
    $app_path = rtrim(str_replace('\\', '/', realpath(dirname(__DIR__))), '/');
    $php_bin  = trim(@shell_exec('which php8.2 || which php8.1 || which php 2>/dev/null') ?: 'php');
    $cli_cmd  = $php_bin . ' ' . $app_path . '/cli/generatorCertyfikatu.php';
    ?>
    <pre class="mb-0 p-3" style="background:#1e293b;color:#7dd3fc;font-size:.78rem;border-radius:0 0 .4rem .4rem;overflow-x:auto"><?= h($cli_cmd) ?></pre>
  </div>
</div>

</div><!-- /col-5 -->
</div><!-- /row -->

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
