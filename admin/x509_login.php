<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/x509_login.php';

require_login();
if (!is_admin()) { http_response_code(403); die('Brak uprawnień.'); }

x509_init();

$errors  = [];
$pending = null;

// ── POST handlers ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'generate') {
        $user_id  = (int)($_POST['user_id']    ?? 0);
        $cn       = trim($_POST['cert_cn']      ?? '');
        $org      = trim($_POST['cert_org']     ?? (defined('ORG_NAME') ? ORG_NAME : ''));
        $country  = strtoupper(trim($_POST['cert_country'] ?? 'PL'));
        $days     = max(30, min(1825, (int)($_POST['cert_days']  ?? 730)));
        $bits     = in_array((int)($_POST['cert_bits'] ?? 2048), [2048, 4096]) ? (int)$_POST['cert_bits'] : 2048;
        $pass     = $_POST['cert_password'] ?? '';

        if (!$user_id)           $errors[] = 'Wybierz użytkownika.';
        if ($cn === '')          $errors[] = 'Pole CN jest wymagane.';
        if (strlen($country) !== 2) $errors[] = 'Kod kraju musi mieć 2 litery (np. PL).';
        if ($pass === '')        $errors[] = 'Hasło certyfikatu jest wymagane.';
        if (strlen($pass) < 4)  $errors[] = 'Hasło musi mieć co najmniej 4 znaki.';

        if (!$errors) {
            try {
                $result = x509_generate_for_user($user_id, $pass, $cn, $org, $country, $bits, $days);
                $uname  = db_one("SELECT name FROM users WHERE id=?", [$user_id])['name'] ?? '';
                $_SESSION['x509_pending'] = [
                    'user_id'     => $user_id,
                    'user_name'   => $uname,
                    'cn'          => $cn,
                    'source'      => 'self',
                    'p12_b64'     => base64_encode($result['p12_data']),
                    'p12_pass'    => $result['p12_pass'],
                    'key_pem'     => $result['key_pem'],
                    'cert_pem'    => $result['cert_pem'],
                    'fingerprint' => $result['fingerprint'],
                    'valid_to'    => $result['valid_to'],
                    'generated'   => date('Y-m-d H:i:s'),
                ];
                flash_set('success', 'Certyfikat wygenerowany dla: ' . h($cn)
                    . ' — ważny do ' . date('d.m.Y', strtotime($result['valid_to'])));
                header('Location: ' . APP_URL . '/admin/x509_login.php#pending');
                exit;
            } catch (\Throwable $e) {
                $errors[] = 'Błąd generowania: ' . $e->getMessage();
            }
        }
    }

    if ($action === 'revoke') {
        $cert_id = (int)($_POST['cert_id'] ?? 0);
        if ($cert_id) {
            x509_revoke($cert_id);
            flash_set('success', 'Certyfikat unieważniony.');
        }
        header('Location: ' . APP_URL . '/admin/x509_login.php'); exit;
    }

    if ($action === 'dismiss_pending') {
        unset($_SESSION['x509_pending']);
        flash_set('success', 'Klucz prywatny usunięty z pamięci serwera.');
        header('Location: ' . APP_URL . '/admin/x509_login.php'); exit;
    }
}

// ── Jednorazowe pobieranie kluczy z sesji ──────────────────────────────────────
if (isset($_GET['dl']) && isset($_SESSION['x509_pending'])) {
    $pk  = $_SESSION['x509_pending'];
    $fmt = $_GET['dl'];
    $safe_cn = preg_replace('/[^a-zA-Z0-9_]/', '_', $pk['cn']);
    if ($fmt === 'p12') {
        unset($_SESSION['x509_pending']);
        header('Content-Type: application/x-pkcs12');
        header('Content-Disposition: attachment; filename="' . $safe_cn . '_login.p12"');
        echo base64_decode($pk['p12_b64']);
        exit;
    }
    if ($fmt === 'pem') {
        unset($_SESSION['x509_pending']);
        header('Content-Type: application/x-pem-file');
        header('Content-Disposition: attachment; filename="' . $safe_cn . '_private.pem"');
        echo $pk['key_pem'];
        exit;
    }
    if ($fmt === 'cert') {
        header('Content-Type: application/x-pem-file');
        header('Content-Disposition: attachment; filename="' . $safe_cn . '_cert.pem"');
        echo $pk['cert_pem'];
        exit;
    }
}

// ── Dane ───────────────────────────────────────────────────────────────────────
$all_certs = x509_list_all();
$admins    = db_all(
    "SELECT id, name, email, role FROM users
     WHERE role IN ('admin','editor','superadmin') AND is_active=1
     ORDER BY name"
);

$PAGE_TITLE = 'Certyfikaty X.509 — logowanie admina';
include dirname(__DIR__) . '/includes/header.php';
?>

<div class="d-flex align-items-center mb-3 gap-2">
  <a href="<?= APP_URL ?>/admin/" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
  <h4 class="mb-0"><i class="bi bi-patch-check-fill text-primary"></i> Certyfikaty X.509 — logowanie administratorów</h4>
</div>

<?= flash_html() ?>
<?php if ($errors): ?>
<div class="alert alert-danger">
  <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<div class="alert alert-info small mb-4">
  <i class="bi bi-info-circle-fill me-1"></i>
  Certyfikaty X.509 umożliwiają logowanie administratorów plikiem <strong>.p12 + hasło</strong> — bez podawania e-maila i hasła konta.
  Klucz prywatny jest generowany jednorazowo i <strong>nie jest przechowywany na serwerze</strong>.
  Metoda pojawia się automatycznie na stronie logowania, gdy istnieje co najmniej jeden aktywny certyfikat.
</div>

<?php if (!empty($_SESSION['x509_pending'])): ?>
<?php $pk = $_SESSION['x509_pending']; ?>
<div class="card border-warning shadow mb-4" id="pending">
  <div class="card-header bg-warning text-dark fw-bold">
    <i class="bi bi-exclamation-triangle-fill me-1"></i>
    Pobierz certyfikat — dostępny tylko teraz!
  </div>
  <div class="card-body">
    <p class="mb-2">
      Certyfikat dla <strong><?= h($pk['cn']) ?></strong> (<?= h($pk['user_name']) ?>)
      <?php if (($pk['source'] ?? 'self') === 'ejbca'): ?><span class="badge bg-primary">EJBCA</span><?php else: ?><span class="badge bg-secondary">self-signed</span><?php endif; ?>
      jest gotowy do pobrania. <strong>Po opuszczeniu strony klucz prywatny zostanie usunięty z pamięci serwera.</strong>
    </p>
    <div class="d-flex gap-2 flex-wrap mb-3">
      <a href="?dl=p12" class="btn btn-warning">
        <i class="bi bi-download me-1"></i>Pobierz PKCS#12 (.p12)
      </a>
      <a href="?dl=pem" class="btn btn-outline-warning">
        <i class="bi bi-download me-1"></i>Pobierz klucz prywatny (.pem)
      </a>
      <a href="?dl=cert" class="btn btn-outline-secondary btn-sm align-self-center">
        <i class="bi bi-download me-1"></i>Certyfikat (.pem)
      </a>
    </div>
    <div class="mb-3 p-2 rounded" style="background:#fffbeb;border:1px solid #f59e0b">
      <strong>Hasło PKCS#12:</strong> <code class="fs-6"><?= h($pk['p12_pass']) ?></code>
      <br><small class="text-muted">Zachowaj to hasło — będzie potrzebne przy każdym logowaniu.</small>
    </div>
    <details class="mb-3">
      <summary class="small text-muted">Fingerprint SHA-256 i szczegóły techniczne</summary>
      <div class="mt-2 small font-monospace p-2 bg-light rounded">
        <div><strong>Fingerprint:</strong> <?= h($pk['fingerprint']) ?></div>
        <div><strong>Ważny do:</strong> <?= date('d.m.Y H:i', strtotime($pk['valid_to'])) ?></div>
        <div><strong>Wygenerowano:</strong> <?= h($pk['generated']) ?></div>
      </div>
    </details>
    <form method="post" class="d-inline">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="dismiss_pending">
      <button type="submit" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-check-lg me-1"></i>Pobrałem klucz — usuń z pamięci
      </button>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- Generuj nowy certyfikat -->
<div class="card shadow-sm mb-4">
  <div class="card-header fw-semibold">
    <i class="bi bi-plus-circle me-1 text-success"></i>Generuj nowy certyfikat logowania
  </div>
  <div class="card-body">
    <form method="post">
      <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
      <input type="hidden" name="action"  value="generate">
      <div class="row g-3">
        <div class="col-md-4">
          <label class="form-label fw-semibold">Użytkownik <span class="text-danger">*</span></label>
          <select name="user_id" class="form-select" required>
            <option value="">— wybierz —</option>
            <?php foreach ($admins as $a): ?>
            <option value="<?= $a['id'] ?>"><?= h($a['name']) ?> (<?= h($a['role']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label fw-semibold">CN — imię i nazwisko <span class="text-danger">*</span></label>
          <input type="text" name="cert_cn" class="form-control" placeholder="Jan Kowalski" required>
        </div>
        <div class="col-md-4">
          <label class="form-label fw-semibold">Hasło PKCS#12 <span class="text-danger">*</span></label>
          <input type="text" name="cert_password" class="form-control"
                 placeholder="min. 4 znaki" required minlength="4"
                 autocomplete="new-password">
          <div class="form-text">Użytkownik będzie podawać to hasło przy logowaniu.</div>
        </div>
        <div class="col-md-4">
          <label class="form-label fw-semibold">Organizacja</label>
          <input type="text" name="cert_org" class="form-control"
                 value="<?= h(defined('ORG_NAME') ? ORG_NAME : '') ?>">
        </div>
        <div class="col-md-2">
          <label class="form-label fw-semibold">Kraj</label>
          <input type="text" name="cert_country" class="form-control"
                 value="PL" maxlength="2" pattern="[A-Za-z]{2}">
        </div>
        <div class="col-md-3">
          <label class="form-label fw-semibold">Ważność</label>
          <select name="cert_days" class="form-select">
            <option value="365">1 rok</option>
            <option value="730" selected>2 lata</option>
            <option value="1095">3 lata</option>
            <option value="1825">5 lat</option>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label fw-semibold">Rozmiar klucza</label>
          <select name="cert_bits" class="form-select">
            <option value="2048" selected>2048 bit</option>
            <option value="4096">4096 bit</option>
          </select>
        </div>
        <div class="col-12">
          <button type="submit" class="btn btn-success">
            <i class="bi bi-stars me-1"></i>Generuj certyfikat X.509
          </button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- Lista certyfikatów -->
<div class="card shadow-sm">
  <div class="card-header fw-semibold">
    <i class="bi bi-list-ul me-1"></i>Wszystkie certyfikaty logowania
    <span class="badge bg-secondary ms-1"><?= count($all_certs) ?></span>
  </div>
  <?php if ($all_certs): ?>
  <div class="table-responsive">
    <table class="table table-hover table-sm align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th>Użytkownik</th>
          <th>CN</th>
          <th>Źródło</th>
          <th>Fingerprint SHA-256</th>
          <th>Ważny do</th>
          <th>Wystawiono</th>
          <th class="text-center">Status</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($all_certs as $c):
        $now     = time();
        $expired = strtotime($c['valid_to']) < $now;
        $revoked = !empty($c['revoked_at']);
        if ($revoked)      $status = ['danger',  'Unieważniony'];
        elseif ($expired)  $status = ['warning', 'Wygasły'];
        else               $status = ['success', 'Aktywny'];
      ?>
      <tr class="<?= $revoked ? 'table-secondary' : '' ?>">
        <td>
          <strong><?= h($c['user_name']) ?></strong><br>
          <small class="text-muted"><?= h($c['user_email']) ?></small>
        </td>
        <td><?= h($c['subject_cn']) ?></td>
        <td>
          <?php if (($c['issuer_type'] ?? 'self') === 'ejbca'): ?>
          <span class="badge bg-primary">EJBCA</span>
          <?php else: ?>
          <span class="badge bg-secondary">self-signed</span>
          <?php endif; ?>
        </td>
        <td>
          <code class="small" title="<?= h($c['fingerprint']) ?>">
            <?= h(substr($c['fingerprint'], 0, 20)) ?>…
          </code>
        </td>
        <td class="small text-nowrap"><?= date('d.m.Y', strtotime($c['valid_to'])) ?></td>
        <td class="small text-nowrap"><?= date('d.m.Y', strtotime($c['issued_at'])) ?></td>
        <td class="text-center">
          <span class="badge bg-<?= $status[0] ?>"><?= $status[1] ?></span>
        </td>
        <td>
          <?php if (!$revoked && !$expired): ?>
          <form method="post" class="d-inline"
                onsubmit="return confirm('Unieważnić certyfikat dla <?= h(addslashes($c['subject_cn'])) ?>?')">
            <input type="hidden" name="_csrf"    value="<?= csrf_token() ?>">
            <input type="hidden" name="action"   value="revoke">
            <input type="hidden" name="cert_id"  value="<?= $c['id'] ?>">
            <button type="submit" class="btn btn-sm btn-outline-danger">
              <i class="bi bi-x-circle me-1"></i>Unieważnij
            </button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php else: ?>
  <div class="card-body text-muted text-center py-4">
    <i class="bi bi-patch-check fs-2 d-block mb-2 text-secondary"></i>
    Brak certyfikatów X.509. Wygeneruj pierwszy powyżej.
  </div>
  <?php endif; ?>
</div>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
