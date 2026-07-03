<?php
require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ksiegowosc.php';

require_login();
if (!is_admin()) { http_response_code(403); die('Brak uprawnień.'); }
kdok_migrate();

$errors  = [];
$success = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action  = $_POST['action'] ?? '';
    $user_id = (int)($_POST['user_id'] ?? 0);

    // Ustaw IKAKS
    if ($action === 'set_ika' && $user_id) {
        $ika1 = $_POST['ikaks1'] ?? '';
        $ika2 = $_POST['ikaks2'] ?? '';
        if (strlen($ika1) < 6)      $errors[] = 'IKAKS musi mieć minimum 6 znaków.';
        elseif ($ika1 !== $ika2)    $errors[] = 'Kody IKAKS nie są identyczne.';
        else {
            kdok_ikaks_set($user_id, $ika1);
            $success[] = 'IKAKS zostało ustawione.';
        }
    }

    // Wgraj certyfikat X.509
    if ($action === 'upload_cert' && $user_id) {
        $pem = '';
        if (!empty($_FILES['cert_file']['tmp_name']) && $_FILES['cert_file']['error'] === UPLOAD_ERR_OK) {
            $pem = file_get_contents($_FILES['cert_file']['tmp_name']);
        } elseif (!empty($_POST['cert_pem'])) {
            $pem = trim($_POST['cert_pem']);
        }
        if (!$pem) {
            $errors[] = 'Podaj certyfikat (plik lub PEM).';
        } else {
            try {
                $meta = kdok_cert_save($user_id, $pem, (int)current_user()['id']);
                $success[] = 'Certyfikat zapisany. CN: ' . $meta['subject_cn']
                    . ' | Ważny do: ' . date('d.m.Y', strtotime($meta['valid_to']));
            } catch (\Throwable $e) {
                $errors[] = 'Błąd certyfikatu: ' . $e->getMessage();
            }
        }
    }

    // Dezaktywuj certyfikat
    if ($action === 'deactivate_cert') {
        $cert_id = (int)($_POST['cert_id'] ?? 0);
        db()->prepare("UPDATE kdok_certificates SET is_active=0 WHERE id=?")->execute([$cert_id]);
        $success[] = 'Certyfikat dezaktywowany.';
    }

    // Generuj certyfikat X.509
    if ($action === 'generate_cert' && $user_id) {
        $cn       = trim($_POST['cert_cn']   ?? '');
        $org      = trim($_POST['cert_org']  ?? (defined('ORG_NAME') ? ORG_NAME : ''));
        $country  = strtoupper(trim($_POST['cert_country'] ?? 'PL'));
        $days     = max(30, min(3650, (int)($_POST['cert_days'] ?? 365)));
        $bits     = in_array((int)($_POST['cert_bits'] ?? 2048), [2048, 4096]) ? (int)$_POST['cert_bits'] : 2048;
        $p12_pass = $_POST['cert_p12_pass'] ?? '';

        if ($cn === '') {
            $errors[] = 'Pole CN (imię i nazwisko) jest wymagane.';
        } elseif (strlen($country) !== 2) {
            $errors[] = 'Kod kraju musi mieć dokładnie 2 litery (np. PL).';
        } else {
            try {
                // Generuj klucz prywatny
                $pkey = openssl_pkey_new([
                    'private_key_bits' => $bits,
                    'private_key_type' => OPENSSL_KEYTYPE_RSA,
                ]);
                if (!$pkey) throw new RuntimeException('Nie można wygenerować klucza: ' . openssl_error_string());

                // CSR
                $dn = array_filter([
                    'CN' => $cn,
                    'O'  => $org ?: null,
                    'C'  => $country,
                ]);
                $csr = openssl_csr_new($dn, $pkey, ['digest_alg' => 'sha256']);
                if (!$csr) throw new RuntimeException('Błąd CSR: ' . openssl_error_string());

                // Podpisz certyfikat (self-signed)
                $x509 = openssl_csr_sign($csr, null, $pkey, $days, [
                    'digest_alg'       => 'sha256',
                    'x509_extensions'  => 'v3_ca',
                ]);
                if (!$x509) throw new RuntimeException('Błąd podpisywania: ' . openssl_error_string());

                // Eksportuj certyfikat PEM
                openssl_x509_export($x509, $cert_pem);

                // Zapisz certyfikat w bazie
                $meta = kdok_cert_save($user_id, $cert_pem, (int)current_user()['id']);

                // Eksportuj klucz prywatny PEM
                openssl_pkey_export($pkey, $key_pem);

                // Eksportuj PKCS#12 (.p12)
                $p12_data = '';
                openssl_pkcs12_export($x509, $p12_data, $pkey,
                    $p12_pass ?: 'changeme',
                    ['friendly_name' => $cn]
                );

                // Zachowaj klucz prywatny w sesji (jednorazowy odbiór)
                $_SESSION['kdok_pending_key'] = [
                    'user_id'    => $user_id,
                    'user_name'  => db_one("SELECT name FROM users WHERE id=?", [$user_id])['name'] ?? '',
                    'cn'         => $cn,
                    'key_pem'    => $key_pem,
                    'cert_pem'   => $cert_pem,
                    'p12_b64'    => base64_encode($p12_data),
                    'p12_pass'   => $p12_pass,
                    'fingerprint'=> $meta['fingerprint_sha256'],
                    'valid_to'   => $meta['valid_to'],
                    'generated'  => date('Y-m-d H:i:s'),
                ];

                flash_set('success', 'Certyfikat wygenerowany dla: ' . $cn
                    . ' | ważny do: ' . date('d.m.Y', strtotime($meta['valid_to'])));
                header('Location: ' . APP_URL . '/admin/kdok_certs.php#pending-key');
                exit;

            } catch (\Throwable $e) {
                $errors[] = 'Błąd generowania certyfikatu: ' . $e->getMessage();
            }
        }
    }

    if ($action === 'dismiss_pending_key') {
        unset($_SESSION['kdok_pending_key']);
        flash_set('success', 'Klucz prywatny usunięty z pamięci serwera.');
        header('Location: ' . APP_URL . '/admin/kdok_certs.php'); exit;
    }

    if ($success) { flash_set('success', implode(' ', $success)); header('Location: ' . APP_URL . '/admin/kdok_certs.php'); exit; }
}

// Pobierz klucz prywatny (PKCS#12 lub PEM) — jednorazowo z sesji
if (isset($_GET['dl']) && isset($_SESSION['kdok_pending_key'])) {
    $pk   = $_SESSION['kdok_pending_key'];
    $fmt  = $_GET['dl'];
    if ($fmt === 'pem') {
        unset($_SESSION['kdok_pending_key']);
        header('Content-Type: application/x-pem-file');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-zA-Z0-9_]/', '_', $pk['cn']) . '_private.pem"');
        echo $pk['key_pem'];
        exit;
    }
    if ($fmt === 'p12') {
        unset($_SESSION['kdok_pending_key']);
        header('Content-Type: application/x-pkcs12');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-zA-Z0-9_]/', '_', $pk['cn']) . '.p12"');
        echo base64_decode($pk['p12_b64']);
        exit;
    }
    if ($fmt === 'cert') {
        header('Content-Type: application/x-pem-file');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-zA-Z0-9_]/', '_', $pk['cn']) . '_cert.pem"');
        echo $pk['cert_pem'];
        exit;
    }
}

// Lista użytkowników z rolami KDOK
$users = db_all(
    "SELECT u.id, u.name, u.email, u.role, u.kdok_ikaks_hash, u.kdok_ikaks_set_at
     FROM users u WHERE u.is_active=1 ORDER BY u.name"
);
foreach ($users as &$u) {
    $u['kdok_roles'] = kdok_user_roles($u['id']);
    $u['cert']       = kdok_cert_get($u['id']);
    $u['has_ika']    = !empty($u['kdok_ikaks_hash']);
}
unset($u);

$PAGE_TITLE = 'Certyfikaty X.509 i IKAKS — KDOK';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex align-items-center mb-3 gap-2">
  <a href="<?= APP_URL ?>/admin/" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
  <h4 class="mb-0"><i class="bi bi-patch-check"></i> Certyfikaty X.509 i IKAKS — EOD Dokumentów Księgowych</h4>
</div>

<?= flash_html() ?>
<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<?php if (!empty($_SESSION['kdok_pending_key'])): ?>
<?php $pk = $_SESSION['kdok_pending_key']; ?>
<div class="alert alert-warning border-warning shadow" id="pending-key">
  <h5 class="alert-heading"><i class="bi bi-exclamation-triangle-fill"></i> Pobierz klucz prywatny — jednorazowo!</h5>
  <p class="mb-2">
    Klucz prywatny do certyfikatu <strong><?= h($pk['cn']) ?></strong>
    (użytkownik: <?= h($pk['user_name']) ?>) jest dostępny tylko teraz.<br>
    <strong>Po opuszczeniu tej strony lub zamknięciu przeglądarki klucz zostanie usunięty z pamięci serwera.</strong>
  </p>
  <div class="d-flex gap-2 flex-wrap mb-3">
    <a href="?dl=p12" class="btn btn-warning btn-sm">
      <i class="bi bi-download"></i> Pobierz PKCS#12 (.p12)
      <?= $pk['p12_pass'] ? '· hasło: <code>' . h($pk['p12_pass']) . '</code>' : '· hasło: <code>changeme</code>' ?>
    </a>
    <a href="?dl=pem" class="btn btn-outline-warning btn-sm">
      <i class="bi bi-download"></i> Pobierz klucz prywatny (.pem)
    </a>
    <a href="?dl=cert" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-download"></i> Pobierz certyfikat (.pem)
    </a>
  </div>
  <details class="mb-2">
    <summary class="small text-muted">Podgląd klucza prywatnego (PEM)</summary>
    <textarea class="form-control form-control-sm font-monospace mt-2" rows="6" readonly
      onclick="this.select()"><?= h($pk['key_pem']) ?></textarea>
  </details>
  <div class="small text-muted">
    Fingerprint SHA-256 certyfikatu: <code><?= h($pk['fingerprint']) ?></code><br>
    Ważny do: <?= date('d.m.Y', strtotime($pk['valid_to'])) ?> · Wygenerowano: <?= h($pk['generated']) ?>
  </div>
  <hr class="my-2">
  <form method="post" class="d-inline">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="action" value="dismiss_pending_key">
    <button type="submit" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-check-lg"></i> Pobrałem klucz — usuń z pamięci
    </button>
  </form>
</div>
<?php endif; ?>

<div class="alert alert-info small mb-3">
  <strong>Autoryzacja:</strong>
  Każda akceptacja dokumentu wymaga ważnego <strong>certyfikatu X.509</strong> (PEM) oraz — jako podstawowej metody —
  zarejestrowanego przez użytkownika klucza <strong>WebAuthn</strong> (Mój profil → Klucze bezpieczeństwa).
  Osoba bez zarejestrowanego klucza może awaryjnie użyć <strong>IKAKS</strong> (Indywidualny Kod Autoryzacyjny —
  min. 6 znaków, ustawiany tutaj przez admina) — nadaj go, jeśli ktoś nie ma jeszcze klucza WebAuthn.
  Imię i nazwisko na PDF pochodzi z pola <code>CN</code> certyfikatu.
</div>

<div class="table-responsive">
<table class="table table-hover align-middle small">
  <thead class="table-light">
    <tr>
      <th>Użytkownik</th>
      <th>Role KDOK</th>
      <th class="text-center">IKAKS</th>
      <th>Certyfikat X.509 (aktywny)</th>
      <th class="text-center">Ważność</th>
      <th></th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($users as $u):
    $cert = $u['cert'];
    $cert_ok = $cert && kdok_cert_is_valid($cert);
  ?>
  <tr>
    <td>
      <strong><?= h($u['name']) ?></strong><br>
      <span class="text-muted"><?= h($u['email']) ?></span>
    </td>
    <td>
      <?php foreach ($u['kdok_roles'] as $r): ?>
      <span class="badge bg-secondary"><?= h($r) ?></span>
      <?php endforeach; ?>
      <?php if ($u['role'] === 'admin'): ?><span class="badge bg-dark">admin</span><?php endif; ?>
    </td>
    <td class="text-center">
      <?php if ($u['has_ika']): ?>
      <i class="bi bi-check-circle-fill text-success" title="IKAKS ustawione <?= h($u['kdok_ikaks_set_at']) ?>"></i>
      <?php else: ?>
      <i class="bi bi-x-circle-fill text-danger" title="Brak IKAKS"></i>
      <?php endif; ?>
    </td>
    <td>
      <?php if ($cert): ?>
      <strong><?= h($cert['subject_cn']) ?></strong><br>
      <span class="text-muted" style="font-size:.7rem">SHA-256: <?= h(substr($cert['fingerprint_sha256'], 0, 47)) ?>…</span>
      <?php else: ?>
      <span class="text-muted fst-italic">Brak certyfikatu</span>
      <?php endif; ?>
    </td>
    <td class="text-center">
      <?php if ($cert_ok): ?>
      <span class="badge bg-success">do <?= date('d.m.Y', strtotime($cert['valid_to'])) ?></span>
      <?php elseif ($cert): ?>
      <span class="badge bg-danger">Wygasł <?= date('d.m.Y', strtotime($cert['valid_to'])) ?></span>
      <?php else: ?>
      <span class="badge bg-secondary">—</span>
      <?php endif; ?>
    </td>
    <td>
      <button class="btn btn-sm btn-outline-primary" type="button"
        data-bs-toggle="modal" data-bs-target="#modalUser<?= $u['id'] ?>">
        <i class="bi bi-gear"></i> Zarządzaj
      </button>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<!-- Modale per użytkownik -->
<?php foreach ($users as $u): ?>
<div class="modal fade" id="modalUser<?= $u['id'] ?>" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-person-lock"></i> <?= h($u['name']) ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">

        <!-- IKAKS -->
        <h6 class="fw-bold"><i class="bi bi-key-fill text-warning"></i> Ustaw / zmień IKAKS</h6>
        <form method="post" class="mb-4">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="action" value="set_ika">
          <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
          <div class="row g-2">
            <div class="col-sm-5">
              <label class="form-label small">Nowy IKAKS <span class="text-danger">*</span></label>
              <input type="password" name="ikaks1" class="form-control form-control-sm"
                autocomplete="new-password" placeholder="min. 6 znaków" required>
            </div>
            <div class="col-sm-5">
              <label class="form-label small">Powtórz IKAKS <span class="text-danger">*</span></label>
              <input type="password" name="ikaks2" class="form-control form-control-sm"
                autocomplete="new-password" placeholder="powtórz" required>
            </div>
            <div class="col-sm-2 d-flex align-items-end">
              <button type="submit" class="btn btn-warning btn-sm w-100">Zapisz</button>
            </div>
          </div>
          <div class="form-text">IKAKS przechowywane jako bcrypt. Admin nie może go odczytać.</div>
        </form>

        <!-- Zakładki: Generuj / Wgraj -->
        <ul class="nav nav-tabs mb-3" id="certTabs<?= $u['id'] ?>" role="tablist">
          <li class="nav-item">
            <button class="nav-link active" data-bs-toggle="tab"
              data-bs-target="#tab_gen_<?= $u['id'] ?>">
              <i class="bi bi-stars text-warning"></i> Generuj certyfikat
            </button>
          </li>
          <li class="nav-item">
            <button class="nav-link" data-bs-toggle="tab"
              data-bs-target="#tab_upload_<?= $u['id'] ?>">
              <i class="bi bi-upload"></i> Wgraj istniejący
            </button>
          </li>
        </ul>

        <div class="tab-content">

          <!-- Generowanie certyfikatu -->
          <div class="tab-pane fade show active" id="tab_gen_<?= $u['id'] ?>">
            <form method="post">
              <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="action" value="generate_cert">
              <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
              <div class="row g-2 mb-2">
                <div class="col-sm-8">
                  <label class="form-label small fw-semibold">
                    CN — imię i nazwisko <span class="text-danger">*</span>
                  </label>
                  <input type="text" name="cert_cn" class="form-control form-control-sm"
                    value="<?= h($u['name']) ?>" required
                    placeholder="Jan Kowalski">
                  <div class="form-text">Pojawi się na PDF jako imię i nazwisko podpisującego.</div>
                </div>
                <div class="col-sm-4">
                  <label class="form-label small fw-semibold">Kraj (2 litery)</label>
                  <input type="text" name="cert_country" class="form-control form-control-sm"
                    value="PL" maxlength="2" pattern="[A-Za-z]{2}">
                </div>
              </div>
              <div class="mb-2">
                <label class="form-label small fw-semibold">Organizacja</label>
                <input type="text" name="cert_org" class="form-control form-control-sm"
                  value="<?= h(defined('ORG_NAME') ? ORG_NAME : '') ?>"
                  placeholder="Nazwa organizacji (opcjonalnie)">
              </div>
              <div class="row g-2 mb-2">
                <div class="col-sm-5">
                  <label class="form-label small fw-semibold">Ważność (dni)</label>
                  <select name="cert_days" class="form-select form-select-sm">
                    <option value="365">1 rok (365 dni)</option>
                    <option value="730">2 lata (730 dni)</option>
                    <option value="1095" selected>3 lata (1095 dni)</option>
                    <option value="1825">5 lat (1825 dni)</option>
                  </select>
                </div>
                <div class="col-sm-4">
                  <label class="form-label small fw-semibold">Rozmiar klucza</label>
                  <select name="cert_bits" class="form-select form-select-sm">
                    <option value="2048" selected>2048 bit (standard)</option>
                    <option value="4096">4096 bit (silniejszy)</option>
                  </select>
                </div>
                <div class="col-sm-3">
                  <label class="form-label small fw-semibold">Hasło PKCS#12</label>
                  <input type="text" name="cert_p12_pass" class="form-control form-control-sm"
                    placeholder="opcjonalne">
                </div>
              </div>
              <div class="alert alert-warning py-2 small mb-2">
                <i class="bi bi-exclamation-triangle"></i>
                Certyfikat jest <strong>self-signed</strong> — wystawiony przez sam system, bez zewnętrznego CA.
                Klucz prywatny zostanie pokazany <strong>jednorazowo</strong> bezpośrednio po wygenerowaniu.
              </div>
              <button type="submit" class="btn btn-warning btn-sm">
                <i class="bi bi-stars"></i> Generuj certyfikat X.509
              </button>
            </form>
          </div>

          <!-- Wgrywanie certyfikatu -->
          <div class="tab-pane fade" id="tab_upload_<?= $u['id'] ?>">
            <form method="post" enctype="multipart/form-data">
              <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="action" value="upload_cert">
              <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
              <div class="mb-2">
                <label class="form-label small fw-semibold">Plik certyfikatu (.pem, .cer, .crt)</label>
                <input type="file" name="cert_file" class="form-control form-control-sm" accept=".pem,.cer,.crt,.txt">
              </div>
              <div class="mb-2">
                <label class="form-label small fw-semibold">…lub wklej PEM</label>
                <textarea name="cert_pem" class="form-control form-control-sm font-monospace" rows="5"
                  placeholder="-----BEGIN CERTIFICATE-----&#10;…&#10;-----END CERTIFICATE-----"></textarea>
              </div>
              <button type="submit" class="btn btn-primary btn-sm">
                <i class="bi bi-upload"></i> Wgraj certyfikat
              </button>
              <div class="form-text mt-1">
                Format PEM (Base64). Pole CN → imię i nazwisko na PDF.
                Poprzedni certyfikat zostanie dezaktywowany.
              </div>
            </form>
          </div>

        </div><!-- /tab-content -->

        <!-- Historia certyfikatów -->
        <?php $all_certs = db_all("SELECT * FROM kdok_certificates WHERE user_id=? ORDER BY id DESC LIMIT 5", [$u['id']]); ?>
        <?php if ($all_certs): ?>
        <hr>
        <h6 class="fw-bold small">Historia certyfikatów (ostatnie 5)</h6>
        <table class="table table-sm small mb-0">
          <thead><tr><th>CN</th><th>Ważny do</th><th>SHA-256 (skrót)</th><th>Status</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($all_certs as $c): ?>
          <tr>
            <td><?= h($c['subject_cn']) ?></td>
            <td><?= date('d.m.Y', strtotime($c['valid_to'])) ?></td>
            <td><code><?= h(substr($c['fingerprint_sha256'], 0, 23)) ?>…</code></td>
            <td>
              <?php if ($c['is_active']): ?>
              <span class="badge bg-<?= kdok_cert_is_valid($c) ? 'success' : 'danger' ?>">
                <?= kdok_cert_is_valid($c) ? 'Aktywny' : 'Wygasł' ?>
              </span>
              <?php else: ?>
              <span class="badge bg-secondary">Nieaktywny</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($c['is_active'] && kdok_cert_is_valid($c)): ?>
              <form method="post" class="d-inline">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="deactivate_cert">
                <input type="hidden" name="cert_id" value="<?= $c['id'] ?>">
                <button type="submit" class="btn btn-xs btn-outline-danger"
                  onclick="return confirm('Dezaktywować certyfikat?')">
                  <i class="bi bi-x"></i>
                </button>
              </form>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php endforeach; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
