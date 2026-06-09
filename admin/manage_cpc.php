<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/cpc.php';
require_once dirname(__DIR__) . '/includes/approval.php';
require_once dirname(__DIR__) . '/includes/karty30.php';

require_role('admin');
cpc_migrate();
karty30_migrate();

$PAGE_TITLE = 'Zarządzanie kodami IKA';
$me = current_user();
$me_id = (int) $me['id'];

// ── Helper: display name ───────────────────────────────────────────────────────
function _display_name(array $u): string {
    $fn = trim($u['first_name'] ?? '');
    $ln = trim($u['last_name']  ?? '');
    if ($fn !== '' && $ln !== '') {
        return $fn . ' ' . $ln;
    }
    return $u['name'] ?? '';
}

// ── POST handlers ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action    = $_POST['_action'] ?? '';
    $target_id = (int) ($_POST['user_id'] ?? 0);

    // Pobierz dowolnego aktywnego użytkownika (wszystkie role)
    $target = $target_id ? db_one(
        "SELECT u.id, u.name, u.first_name, u.last_name, u.role
         FROM users u WHERE u.id=? AND u.is_active=1",
        [$target_id]
    ) : null;

    // ── set_cpc ───────────────────────────────────────────────────────────────
    if ($action === 'set_cpc') {
        if (!$target) {
            flash_set('danger', 'Nie znaleziono użytkownika lub brak uprawnień do edycji.');
            header('Location: manage_cpc.php');
            exit;
        }

        $auto_generate = !empty($_POST['auto_generate']);

        if ($auto_generate) {
            $cpc_code = cpc_generate();
        } else {
            $cpc_code = trim($_POST['cpc_code'] ?? '');
            if (!preg_match('/^\d{6}$/', $cpc_code)) {
                flash_set('danger', 'Kod IKA musi składać się dokładnie z 6 cyfr.');
                header('Location: manage_cpc.php');
                exit;
            }
        }

        db()->prepare(
            "UPDATE users SET cpc_code = ?, cpc_fails = 0, cpc_blocked_until = NULL WHERE id = ?"
        )->execute([$cpc_code, $target_id]);

        $target_name = _display_name($target);
        log_system_action(
            $me_id,
            'cpc_set',
            "Admin nadał/zmienił kod IKA użytkownikowi ID:{$target_id} ({$target_name})."
        );
        // Zapisz wygenerowany kod do sesji — pokazany jednorazowo na stronie
        auth_start();
        $_SESSION['_ika_generated'] = [
            'uid'   => $target_id,
            'name'  => $target_name,
            'email' => db_one("SELECT email FROM users WHERE id=?", [$target_id])['email'] ?? '',
            'code'  => $cpc_code,
            'role'  => $target['role'] ?? '',
        ];
        header('Location: manage_cpc.php?ika_set=1#ika-result');
        exit;
    }

    // ── unblock_cpc ───────────────────────────────────────────────────────────
    if ($action === 'unblock_cpc') {
        if (!$target) {
            flash_set('danger', 'Nie znaleziono użytkownika.');
            header('Location: manage_cpc.php');
            exit;
        }

        db()->prepare(
            "UPDATE users SET cpc_fails = 0, cpc_blocked_until = NULL WHERE id = ?"
        )->execute([$target_id]);

        $target_name = _display_name($target);
        log_system_action(
            $me_id,
            'cpc_unblock',
            "Admin odblokował kod CPC użytkownika ID:{$target_id} ({$target_name})."
        );
        flash_set('success', 'Blokada kodu IKA dla ' . $target_name . ' została zdjęta.');
        header('Location: manage_cpc.php');
        exit;
    }

    // ── clear_cpc ─────────────────────────────────────────────────────────────
    if ($action === 'clear_cpc') {
        if (!$target) {
            flash_set('danger', 'Nie znaleziono użytkownika.');
            header('Location: manage_cpc.php');
            exit;
        }

        db()->prepare(
            "UPDATE users SET cpc_code = NULL, cpc_fails = 0, cpc_blocked_until = NULL WHERE id = ?"
        )->execute([$target_id]);

        $target_name = _display_name($target);
        log_system_action(
            $me_id,
            'cpc_clear',
            "Admin usunął kod CPC użytkownika ID:{$target_id} ({$target_name})."
        );
        flash_set('success', 'Kod IKA dla ' . $target_name . ' został usunięty.');
        header('Location: manage_cpc.php');
        exit;
    }

    // ── revoke_ika ────────────────────────────────────────────────────────────
    if ($action === 'revoke_ika') {
        // Własna sesja lub inna (admin może unieważnić każdą)
        $target_id_ika = (int)($_POST['user_id'] ?? 0);
        $target_ika = $target_id_ika
            ? db_one("SELECT id, name, first_name, last_name FROM users WHERE id = ?", [$target_id_ika])
            : null;

        if (!$target_ika) {
            flash_set('danger', 'Nie znaleziono użytkownika.');
            header('Location: manage_cpc.php');
            exit;
        }

        db()->prepare("UPDATE users SET ika_revoked_at = ? WHERE id = ?")
             ->execute([date('Y-m-d H:i:s'), $target_id_ika]);

        // Jeśli admin unieważnia swoją własną sesję — wyczyść też $_SESSION
        if ($target_id_ika === $me_id) {
            unset($_SESSION['_ika_ts']);
        }

        $target_name = _display_name($target_ika);
        log_system_action($me_id, 'ika_revoke',
            "Admin unieważnił sesję IKA użytkownika ID:{$target_id_ika} ({$target_name}).");
        flash_set('success', 'Sesja IKA użytkownika ' . $target_name . ' została unieważniona. Przy następnej operacji wymagana będzie ponowna weryfikacja.');
        header('Location: manage_cpc.php');
        exit;
    }

    // ── set_cpc_k30 — nadaj/zmień IKA doradcy K30 (każda rola) ──────────────────
    if ($action === 'set_cpc_k30') {
        $target_k30 = $target_id ? db_one(
            "SELECT id, name, first_name, last_name, role, email FROM users WHERE id=? AND is_active=1 AND k30_consultant=1",
            [$target_id]
        ) : null;

        if (!$target_k30) {
            flash_set('danger', 'Nie znaleziono aktywnego doradcy K30.');
            header('Location: manage_cpc.php#k30-section'); exit;
        }

        $auto = !empty($_POST['auto_generate']);
        $cpc_code = $auto ? cpc_generate() : trim($_POST['cpc_code'] ?? '');

        if (!$auto && !preg_match('/^\d{6}$/', $cpc_code)) {
            flash_set('danger', 'Kod IKA musi składać się z 6 cyfr.');
            header('Location: manage_cpc.php#k30-section'); exit;
        }

        db()->prepare("UPDATE users SET cpc_code=?, cpc_fails=0, cpc_blocked_until=NULL WHERE id=?")
            ->execute([$cpc_code, $target_id]);

        $dn = _display_name($target_k30);
        log_system_action($me_id, 'cpc_set_k30', "Admin nadał kod IKA doradcy K30 ID:{$target_id} ({$dn}).");
        flash_set('success', "Kod IKA nadany doradcy {$dn}. Przekaż go bezpiecznie.");
        header('Location: manage_cpc.php#k30-section'); exit;
    }

    // ── gen_cert — generuje self-signed x509 dla doradcy K30 ─────────────────
    if ($action === 'gen_cert') {
        if (!extension_loaded('openssl')) {
            flash_set('danger', 'Rozszerzenie PHP OpenSSL jest niedostępne. Certyfikatu nie można wygenerować.');
            header('Location: manage_cpc.php#k30-section'); exit;
        }

        $target_k30 = $target_id ? db_one(
            "SELECT id, name, first_name, last_name, email, role FROM users WHERE id=? AND is_active=1 AND k30_consultant=1",
            [$target_id]
        ) : null;

        if (!$target_k30) {
            flash_set('danger', 'Nie znaleziono aktywnego doradcy K30.');
            header('Location: manage_cpc.php#k30-section'); exit;
        }

        $dn          = _display_name($target_k30);
        $email       = $target_k30['email'] ?? '';
        $org         = org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : 'Organizacja');
        $valid_days  = max(90, (int)($_POST['valid_days'] ?? 730)); // domyślnie 2 lata
        $valid_days  = min($valid_days, 3650); // max 10 lat

        // Konfiguracja certyfikatu
        $dn_config = [
            'countryName'            => 'PL',
            'organizationName'       => mb_substr($org, 0, 64, 'UTF-8'),
            'organizationalUnitName' => 'Karty30 TyfloKonsultacje',
            'commonName'             => mb_substr($dn, 0, 64, 'UTF-8'),
            'emailAddress'           => $email ?: 'brak@email.pl',
        ];

        // Generuj klucz prywatny RSA-2048
        $privkey = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        if (!$privkey) {
            flash_set('danger', 'Błąd generowania klucza prywatnego: ' . openssl_error_string());
            header('Location: manage_cpc.php#k30-section'); exit;
        }

        // CSR
        $csr = openssl_csr_new($dn_config, $privkey, ['digest_alg' => 'sha256']);
        if (!$csr) {
            flash_set('danger', 'Błąd generowania CSR: ' . openssl_error_string());
            header('Location: manage_cpc.php#k30-section'); exit;
        }

        // Self-signed certificate
        $cert = openssl_csr_sign($csr, null, $privkey, $valid_days, ['digest_alg' => 'sha256'], time());
        if (!$cert) {
            flash_set('danger', 'Błąd generowania certyfikatu: ' . openssl_error_string());
            header('Location: manage_cpc.php#k30-section'); exit;
        }

        // Eksportuj certyfikat (PEM — część publiczna)
        $cert_pem = '';
        openssl_x509_export($cert, $cert_pem);

        // Eksportuj klucz prywatny (PEM, bez hasła — serwer go nie przechowuje)
        $key_pem = '';
        openssl_pkey_export($privkey, $key_pem);

        // Parsuj i zapisz do k30_consultant_certs
        $parsed = k30_parse_cert($cert_pem);
        if (!$parsed) {
            flash_set('danger', 'Wygenerowany certyfikat jest nieprawidłowy — skontaktuj się z administratorem systemu.');
            header('Location: manage_cpc.php#k30-section'); exit;
        }

        // Zapisz certyfikat (publiczny) — nadpisz istniejący jeśli jest
        $existing = db_one("SELECT id FROM k30_consultant_certs WHERE user_id=?", [$target_id]);
        $cert_data = [
            'cert_pem'        => $cert_pem,
            'cert_subject'    => $parsed['subject'],
            'cert_fingerprint'=> $parsed['fingerprint'],
            'cert_serial'     => $parsed['serial'],
            'cert_valid_from' => $parsed['valid_from'],
            'cert_valid_to'   => $parsed['valid_to'],
            'is_active'       => 1,
            'uploaded_by'     => $me_id,
            'uploaded_at'     => date('Y-m-d H:i:s'),
        ];
        if ($existing) {
            db_update('k30_consultant_certs', $existing['id'], $cert_data);
        } else {
            $cert_data['user_id'] = $target_id;
            db_insert('k30_consultant_certs', $cert_data);
        }

        log_system_action($me_id, 'k30_cert_gen',
            "Admin wygenerował certyfikat x509 dla doradcy K30 ID:{$target_id} ({$dn}). Fingerprint: " . $parsed['fingerprint']);

        // Zapisz PEM do sesji by pokazać w UI
        auth_start();
        $_SESSION['k30_cert_result'] = [
            'user_name'   => $dn,
            'cert_pem'    => $cert_pem,
            'key_pem'     => $key_pem,          // przekazujemy do przeglądarki RAZ — nie przechowujemy
            'fingerprint' => $parsed['fingerprint'],
            'subject'     => $parsed['subject'],
            'valid_to'    => date('d.m.Y', $parsed['valid_to']),
            'token'       => bin2hex(random_bytes(16)),
        ];

        header('Location: manage_cpc.php?cert_generated=1#k30-section'); exit;
    }

    // ── gen_setup_token — jednorazowy AdminCode do self-service ustawiania IKA+IKAKS ─
    if ($action === 'gen_setup_token') {
        if (!$target) {
            flash_set('danger', 'Nie znaleziono użytkownika.');
            header('Location: manage_cpc.php'); exit;
        }
        $token = cpc_setup_token_generate($target_id);
        $target_name = _display_name($target);
        log_system_action($me_id, 'ika_setup_token_gen',
            "Admin wygenerował token konfiguracyjny IKA/IKAKS dla użytkownika ID:{$target_id} ({$target_name}).");
        auth_start();
        $_SESSION['_ika_setup_token'] = [
            'uid'   => $target_id,
            'name'  => $target_name,
            'email' => db_one("SELECT email FROM users WHERE id=?", [$target_id])['email'] ?? '',
            'token' => $token,
        ];
        header('Location: manage_cpc.php?setup_token=1#ika-result'); exit;
    }

    // Unknown action
    flash_set('danger', 'Nieznana akcja.');
    header('Location: manage_cpc.php');
    exit;
}

// ── Load users — wszyscy aktywni ───────────────────────────────────────────────
$users = db_all(
    "SELECT id, name, first_name, last_name, email, role, is_active,
            cpc_code, cpc_fails, cpc_blocked_until, ika_revoked_at
     FROM users
     WHERE is_active = 1
     ORDER BY
       CASE role WHEN 'admin' THEN 0 WHEN 'editor' THEN 1 WHEN 'crm_user' THEN 2 ELSE 3 END,
       name ASC"
);

// Odczyt jednorazowego wyniku generowania IKA
auth_start();
$ika_result = null;
if (!empty($_GET['ika_set'])) {
    $ika_result = $_SESSION['_ika_generated'] ?? null;
    unset($_SESSION['_ika_generated']);
}

// Odczyt jednorazowego tokenu konfiguracyjnego
$setup_token_result = null;
if (!empty($_GET['setup_token'])) {
    $setup_token_result = $_SESSION['_ika_setup_token'] ?? null;
    unset($_SESSION['_ika_setup_token']);
}

// CRM-only użytkownicy wymagający IKA
$crm_only_no_ika = db_all(
    "SELECT u.id, u.name, u.first_name, u.last_name, u.email, u.role, u.cpc_code
     FROM users u
     LEFT JOIN roles r ON r.name=u.role
     WHERE u.is_active=1 AND (u.role='crm_user' OR r.crm_only=1) AND (u.cpc_code IS NULL OR u.cpc_code='')
     ORDER BY u.name"
);

// Doradcy K30 (dowolna rola, k30_consultant=1) + ich certyfikaty
$k30_users = db_all(
    "SELECT u.id, u.name, u.first_name, u.last_name, u.email, u.role,
            u.cpc_code, u.cpc_fails, u.cpc_blocked_until, u.ika_revoked_at,
            c.cert_fingerprint, c.cert_subject, c.cert_valid_to, c.is_active AS cert_active
     FROM users u
     LEFT JOIN k30_consultant_certs c ON c.user_id=u.id
     WHERE u.is_active=1 AND u.k30_consultant=1
     ORDER BY u.first_name, u.last_name, u.name"
);

$now = date('Y-m-d H:i:s');

// Wygenerowany certyfikat (jednorazowy odczyt z sesji)
$cert_result = null;
if (!empty($_GET['cert_generated'])) {
    auth_start();
    $cert_result = $_SESSION['k30_cert_result'] ?? null;
    unset($_SESSION['k30_cert_result']);
}

include dirname(__DIR__) . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0">
    <i class="bi bi-shield-lock text-primary"></i> <?= h($PAGE_TITLE) ?>
  </h4>
</div>

<?= flash_html() ?>

<!-- ── Wygenerowany kod IKA — jednorazowy widok ──────────────────────────── -->
<?php if ($ika_result): ?>
<div class="card border-success shadow mb-4" id="ika-result">
  <div class="card-header bg-success text-white fw-bold d-flex align-items-center gap-2">
    <i class="bi bi-key-fill fs-5"></i>
    Kod IKA wygenerowany — zapisz i przekaż użytkownikowi
  </div>
  <div class="card-body">
    <div class="row align-items-center g-3">
      <div class="col-md-6">
        <div class="mb-1 text-muted small">Użytkownik</div>
        <div class="fw-bold fs-6"><?= h($ika_result['name']) ?></div>
        <div class="text-muted small"><?= h($ika_result['email']) ?> · <?= h($ika_result['role']) ?></div>
      </div>
      <div class="col-md-6 text-center">
        <div class="mb-1 text-muted small">Kod IKA</div>
        <div id="ika-result-code"
             class="font-monospace fw-bold text-success"
             style="font-size:2.8rem;letter-spacing:.35em;line-height:1">
          <?= h($ika_result['code']) ?>
        </div>
        <div class="d-flex gap-2 justify-content-center mt-2 flex-wrap">
          <button type="button" class="btn btn-sm btn-outline-success"
                  onclick="navigator.clipboard.writeText('<?= h($ika_result['code']) ?>').then(()=>this.innerHTML='✓ Skopiowano').catch(()=>{})">
            <i class="bi bi-clipboard me-1"></i>Kopiuj
          </button>
          <a href="<?= APP_URL ?>/admin/ika_karta.php?user_id=<?= $ika_result['uid'] ?>"
             target="_blank" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-printer me-1"></i>Drukuj kartę IKA
          </a>
        </div>
      </div>
    </div>
    <div class="alert alert-warning py-2 mb-0 mt-3 small d-flex gap-2">
      <i class="bi bi-exclamation-triangle-fill flex-shrink-0 mt-1"></i>
      <span>
        <strong>Kod widoczny jednorazowo.</strong>
        Po odświeżeniu strony nie będzie możliwy do odczytania z tego miejsca.
        Przekaż go użytkownikowi osobiście lub szyfrowanym kanałem.
      </span>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($setup_token_result): ?>
<div class="card border-warning shadow mb-4" id="ika-result">
  <div class="card-header bg-warning text-dark fw-bold d-flex align-items-center gap-2">
    <i class="bi bi-qr-code fs-5"></i>
    Token konfiguracyjny wygenerowany — przekaż użytkownikowi
  </div>
  <div class="card-body">
    <div class="row align-items-center g-3">
      <div class="col-md-6">
        <div class="mb-1 text-muted small">Użytkownik</div>
        <div class="fw-bold fs-6"><?= h($setup_token_result['name']) ?></div>
        <div class="text-muted small"><?= h($setup_token_result['email']) ?></div>
      </div>
      <div class="col-md-6 text-center">
        <div class="mb-1 text-muted small">AdminCode (jednorazowy, ważny 48 h)</div>
        <div id="setup-token-val"
             class="font-monospace fw-bold text-warning"
             style="font-size:2.1rem;letter-spacing:.25em;line-height:1">
          <?= h($setup_token_result['token']) ?>
        </div>
        <div class="d-flex gap-2 justify-content-center mt-2 flex-wrap">
          <button type="button" class="btn btn-sm btn-outline-warning"
                  onclick="navigator.clipboard.writeText('<?= h($setup_token_result['token']) ?>').then(()=>this.innerHTML='✓ Skopiowano').catch(()=>{})">
            <i class="bi bi-clipboard me-1"></i>Kopiuj
          </button>
        </div>
      </div>
    </div>
    <div class="alert alert-warning py-2 mb-0 mt-3 small d-flex gap-2">
      <i class="bi bi-exclamation-triangle-fill flex-shrink-0 mt-1"></i>
      <span>
        <strong>Token widoczny jednorazowo.</strong>
        Przekaż go użytkownikowi — wpisze go na stronie
        <a href="<?= APP_URL ?>/panel/set_my_codes.php" target="_blank">/panel/set_my_codes.php</a>
        razem ze swoim nowym kodem IKA i IKAKS. Token wygasa po 48 godzinach lub po pierwszym użyciu.
      </span>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($crm_only_no_ika): ?>
<div class="alert alert-warning d-flex gap-2 align-items-start mb-3" role="alert">
  <i class="bi bi-exclamation-triangle-fill fs-5 flex-shrink-0 mt-1"></i>
  <div>
    <strong><?= count($crm_only_no_ika) ?> użytkownicy CRM-only bez kodu IKA</strong>
    — Ci użytkownicy <strong>nie będą mogli się zalogować</strong> do modułu CRM dopóki nie otrzymają kodu IKA.
    <div class="mt-2 d-flex flex-wrap gap-2">
      <?php foreach ($crm_only_no_ika as $u): ?>
      <form method="post" class="d-inline">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="set_cpc">
        <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
        <input type="hidden" name="auto_generate" value="1">
        <button class="btn btn-sm btn-warning">
          <i class="bi bi-key me-1"></i>Nadaj IKA dla <?= h($u['first_name'] ?: $u['name']) ?>
        </button>
      </form>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<?php
// Wszyscy użytkownicy CRM-only (z kodami i bez)
$crm_only_all = db_all(
    "SELECT u.id, u.name, u.first_name, u.last_name, u.email, u.role,
            u.cpc_code, u.cpc_fails, u.cpc_blocked_until
     FROM users u
     LEFT JOIN roles r ON r.name = u.role
     WHERE u.is_active = 1 AND (u.role = 'crm_user' OR r.crm_only = 1)
     ORDER BY u.name"
);
?>
<?php if ($crm_only_all): ?>
<div class="card shadow-sm mb-4" id="crm-ika-section">
  <div class="card-header fw-semibold d-flex align-items-center justify-content-between">
    <div class="d-flex align-items-center gap-2">
      <i class="bi bi-diagram-2-fill text-primary"></i>
      Kody IKA — użytkownicy CRM-only
      <span class="badge bg-secondary"><?= count($crm_only_all) ?></span>
    </div>
    <span class="text-muted small fw-normal">Kod widoczny tylko po kliknięciu — admin jest odpowiedzialny za bezpieczne przekazanie</span>
  </div>
  <div class="table-responsive">
    <table class="table table-hover mb-0 small align-middle">
      <thead class="table-light">
        <tr>
          <th>Użytkownik</th>
          <th>E-mail</th>
          <th>Rola</th>
          <th>Kod IKA</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($crm_only_all as $cu):
          $cuid    = (int)$cu['id'];
          $cuname  = _display_name($cu);
          $blocked = !empty($cu['cpc_blocked_until']) && $cu['cpc_blocked_until'] > $now;
        ?>
        <tr>
          <td class="fw-semibold"><?= h($cuname) ?></td>
          <td class="text-muted"><?= h($cu['email'] ?? '') ?></td>
          <td><span class="badge bg-light text-dark border"><?= h($cu['role']) ?></span></td>
          <td>
            <?php if (empty($cu['cpc_code'])): ?>
            <span class="badge bg-danger">Brak kodu</span>
            <?php elseif ($blocked): ?>
            <span class="badge bg-warning text-dark">Zablokowany</span>
            <?php else: ?>
            <!-- Maskowany kod z przyciskiem reveal -->
            <span class="font-monospace ika-masked" id="ika-val-<?= $cuid ?>"
                  style="letter-spacing:.2em;color:#9CA3AF">●●●●●●</span>
            <button type="button" class="btn btn-xs btn-outline-secondary ms-2"
                    style="font-size:.7rem;padding:1px 7px"
                    onclick="toggleIka(<?= $cuid ?>, this)"
                    data-code="<?= h($cu['cpc_code']) ?>"
                    data-shown="0">
              <i class="bi bi-eye"></i>
            </button>
            <?php endif; ?>
          </td>
          <td class="d-flex gap-1">
            <!-- Nadaj nowy kod -->
            <form method="post" class="d-inline">
              <?= csrf_field() ?>
              <input type="hidden" name="_action"      value="set_cpc">
              <input type="hidden" name="user_id"      value="<?= $cuid ?>">
              <input type="hidden" name="auto_generate" value="1">
              <button class="btn btn-xs btn-outline-primary"
                      style="font-size:.7rem;padding:1px 7px"
                      title="Wygeneruj nowy losowy kod IKA">
                <i class="bi bi-arrow-clockwise"></i> Nowy kod
              </button>
            </form>
            <!-- Drukuj kartę -->
            <?php if (!empty($cu['cpc_code'])): ?>
            <a href="<?= APP_URL ?>/admin/ika_karta.php?user_id=<?= $cuid ?>"
               target="_blank"
               class="btn btn-xs btn-outline-secondary"
               style="font-size:.7rem;padding:1px 7px"
               title="Drukuj kartę IKA">
              <i class="bi bi-printer"></i>
            </a>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
function toggleIka(uid, btn) {
  var span = document.getElementById('ika-val-' + uid);
  if (!span) return;
  if (btn.dataset.shown === '1') {
    span.textContent = '●●●●●●';
    span.style.color = '#9CA3AF';
    btn.innerHTML = '<i class="bi bi-eye"></i>';
    btn.dataset.shown = '0';
  } else {
    span.textContent = btn.dataset.code;
    span.style.color = '#111827';
    span.style.fontWeight = '700';
    btn.innerHTML = '<i class="bi bi-eye-slash"></i>';
    btn.dataset.shown = '1';
    // Auto-ukryj po 30 sekundach
    setTimeout(function() {
      if (btn.dataset.shown === '1') toggleIka(uid, btn);
    }, 30000);
  }
}
</script>
<?php endif; ?>

<div class="alert alert-info d-flex gap-2 align-items-start mb-4" role="alert">
  <i class="bi bi-info-circle-fill fs-5 flex-shrink-0 mt-1"></i>
  <div>
    <strong>Kody IKA</strong> (Indywidualny Kod Autoryzacyjny) są wymagane do autoryzacji operacji krytycznych (rejestracja umów, zlecenia przesyłek).
    Kod widoczny jest wyłącznie podczas nadawania &mdash; nie jest przechowywany w jawnej postaci w logach.
    <a href="<?= APP_URL ?>/admin/ika_karta.php" class="ms-2" target="_blank">
      <i class="bi bi-printer"></i> Drukuj kartę IKA
    </a>
  </div>
</div>

<div class="card shadow-sm">
  <div class="card-header fw-semibold d-flex align-items-center gap-2 flex-wrap">
    <span><i class="bi bi-people"></i> Wszyscy użytkownicy — kody IKA</span>
    <span class="badge bg-secondary ms-1"><?= count($users) ?></span>
    <span class="ms-auto text-muted fw-normal small">
      Kod widoczny przez 30 s po kliknięciu <i class="bi bi-eye"></i>
    </span>
  </div>

  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th>Użytkownik</th>
          <th>E-mail</th>
          <th>Rola</th>
          <th>Status IKA</th>
          <th>Akcje</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$users): ?>
        <tr>
          <td colspan="5" class="text-center text-muted py-4">
            Brak użytkowników z rolą administratora lub edytora.
          </td>
        </tr>
        <?php endif; ?>

        <?php foreach ($users as $u):
            $uid          = (int) $u['id'];
            $display      = _display_name($u);
            $is_blocked   = !empty($u['cpc_blocked_until']) && $u['cpc_blocked_until'] > $now;
            $has_code     = !empty($u['cpc_code']);
            $fails        = (int) ($u['cpc_fails'] ?? 0);
            $collapse_id  = 'cpc-form-' . $uid;
            $ika_revoked  = $u['ika_revoked_at'] ?? null;
        ?>
        <tr>
          <td class="fw-semibold">
            <?= h($display) ?>
            <?php if ($uid === $me_id): ?>
            <span class="badge bg-info ms-1">ja</span>
            <?php endif; ?>
          </td>
          <td class="text-muted small"><?= h($u['email']) ?></td>
          <td>
            <?php if ($u['role'] === 'admin'): ?>
            <span class="badge bg-primary">Administrator</span>
            <?php else: ?>
            <span class="badge bg-secondary">Edytor</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($is_blocked): ?>
              <span class="badge bg-danger">
                Zablokowany do <?= h(date('d.m.Y H:i', strtotime($u['cpc_blocked_until']))) ?>
              </span>
              &nbsp;
              <form method="post" class="d-inline">
                <input type="hidden" name="_csrf"    value="<?= csrf_token() ?>">
                <input type="hidden" name="_action"  value="unblock_cpc">
                <input type="hidden" name="user_id"  value="<?= $uid ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger"
                        title="Odblokuj kod IKA"
                        onclick="return confirm('Odblokować kod IKA dla <?= h(addslashes($display)) ?>?')">
                  <i class="bi bi-unlock"></i> Odblokuj
                </button>
              </form>
            <?php elseif (!$has_code): ?>
              <span class="badge bg-warning text-dark">Brak kodu</span>
            <?php else: ?>
              <!-- Kod maskowany + reveal -->
              <span class="font-monospace ika-masked" id="ika-val-<?= $uid ?>"
                    style="letter-spacing:.18em;color:#9CA3AF">●●●●●●</span>
              <button type="button" class="btn btn-xs btn-outline-secondary ms-1"
                      style="font-size:.7rem;padding:1px 7px"
                      onclick="toggleIka(<?= $uid ?>, this)"
                      data-code="<?= h($has_code ? $u['cpc_code'] : '') ?>"
                      data-shown="0"
                      title="Pokaż kod IKA (30 s)">
                <i class="bi bi-eye"></i>
              </button>
              <?php if ($fails > 0): ?>
              <span class="badge bg-warning text-dark ms-1"><?= $fails ?> błąd<?= $fails>1?'y':'' ?></span>
              <?php else: ?>
              <span class="badge bg-success ms-1">✓</span>
              <?php endif; ?>
            <?php endif; ?>
          </td>
          <td class="d-flex flex-wrap gap-1 align-items-center">
            <button class="btn btn-sm btn-outline-primary"
                    type="button"
                    data-bs-toggle="collapse"
                    data-bs-target="#<?= $collapse_id ?>"
                    aria-expanded="false"
                    aria-controls="<?= $collapse_id ?>">
              <i class="bi bi-key"></i> Zmień kod IKA
            </button>
            <!-- Token konfiguracyjny do self-service -->
            <form method="post" class="d-inline">
              <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
              <input type="hidden" name="_action" value="gen_setup_token">
              <input type="hidden" name="user_id" value="<?= $uid ?>">
              <button type="submit"
                      class="btn btn-sm btn-outline-warning"
                      title="Wygeneruj jednorazowy AdminCode — użytkownik sam ustawi IKA i IKAKS">
                <i class="bi bi-qr-code"></i> Token konfiguracyjny
              </button>
            </form>
            <!-- Unieważnij sesję IKA -->
            <form method="post" class="d-inline">
              <input type="hidden" name="_csrf"    value="<?= csrf_token() ?>">
              <input type="hidden" name="_action"  value="revoke_ika">
              <input type="hidden" name="user_id"  value="<?= $uid ?>">
              <button type="submit"
                      class="btn btn-sm btn-outline-secondary"
                      title="Wymusza ponowną weryfikację kodu IKA przy następnej operacji"
                      onclick="return confirm('Unieważnić sesję IKA dla <?= h(addslashes($display)) ?>?\nUżytkownik będzie musiał ponownie wpisać kod IKA.')">
                <i class="bi bi-slash-circle"></i> Unieważnij sesję
              </button>
            </form>
          </td>
        </tr>

        <!-- Inline collapse row with set_cpc form -->
        <tr class="table-light border-0">
          <td colspan="5" class="p-0 border-0">
            <div class="collapse" id="<?= $collapse_id ?>">
              <div class="p-3 border-top border-bottom bg-light">
                <form method="post" class="row g-2 align-items-end"
                      id="form-set-<?= $uid ?>">
                  <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
                  <input type="hidden" name="_action" value="set_cpc">
                  <input type="hidden" name="user_id" value="<?= $uid ?>">

                  <div class="col-sm-auto">
                    <label class="form-label small mb-1 fw-semibold">Nowy kod IKA (6 cyfr)</label>
                    <input type="text"
                           name="cpc_code"
                           id="cpc_code_<?= $uid ?>"
                           class="form-control form-control-sm font-monospace"
                           maxlength="6"
                           pattern="\d{6}"
                           placeholder="000000"
                           autocomplete="off"
                           inputmode="numeric">
                  </div>

                  <div class="col-sm-auto d-flex align-items-end pb-1">
                    <div class="form-check mb-0">
                      <input class="form-check-input cpc-auto-generate"
                             type="checkbox"
                             name="auto_generate"
                             id="auto_generate_<?= $uid ?>"
                             data-target="cpc_code_<?= $uid ?>">
                      <label class="form-check-label small" for="auto_generate_<?= $uid ?>">
                        Generuj losowo
                      </label>
                    </div>
                  </div>

                  <div class="col-sm-auto">
                    <button type="submit" class="btn btn-primary btn-sm">
                      <i class="bi bi-save"></i> Zapisz kod IKA
                    </button>
                  </div>

                  <?php if ($has_code): ?>
                  <div class="col-sm-auto">
                    <button type="submit"
                            form="form-clear-<?= $uid ?>"
                            class="btn btn-outline-danger btn-sm"
                            onclick="return confirm('Usunąć kod IKA dla <?= h(addslashes($display)) ?>? Użytkownik nie będzie mógł autoryzować operacji krytycznych.')">
                      <i class="bi bi-trash"></i> Usuń kod
                    </button>
                  </div>
                  <?php endif; ?>
                </form>

                <?php if ($has_code): ?>
                <form method="post" id="form-clear-<?= $uid ?>" class="d-none">
                  <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
                  <input type="hidden" name="_action" value="clear_cpc">
                  <input type="hidden" name="user_id" value="<?= $uid ?>">
                </form>
                <?php endif; ?>
              </div>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ════════════════════════════════════════════════════════════════════════
     SEKCJA K30 — Doradcy TyfloKonsultacje: IKA + certyfikaty x509
     ════════════════════════════════════════════════════════════════════════ -->
<div class="mt-5" id="k30-section">
  <h5 class="fw-bold mb-1 d-flex align-items-center gap-2">
    <i class="bi bi-card-checklist text-purple" style="color:#7C3AED"></i>
    Doradcy TyfloKonsultacje — IKA i certyfikaty x509
  </h5>
  <p class="text-muted small mb-3">
    Doradcy K30 mogą mieć dowolną rolę systemową (także wolontariusz/widz).
    Do zatwierdzania kart konsultacji wymagany jest <strong>kod IKA</strong>
    oraz <strong>ważny certyfikat x509</strong>. Certyfikat można wygenerować poniżej.
  </p>

  <?php if ($cert_result): ?>
  <!-- Wynik generowania certyfikatu — jednorazowy widok -->
  <div class="alert alert-success d-flex gap-2 align-items-start mb-3" role="alert">
    <i class="bi bi-shield-check-fill fs-4 flex-shrink-0 mt-1 text-success"></i>
    <div class="flex-grow-1">
      <strong>Certyfikat x509 wygenerowany dla: <?= h($cert_result['user_name']) ?></strong><br>
      <small>
        Podmiot: <code><?= h($cert_result['subject']) ?></code><br>
        Ważny do: <strong><?= h($cert_result['valid_to']) ?></strong><br>
        Fingerprint: <code style="word-break:break-all"><?= h($cert_result['fingerprint']) ?></code>
      </small>
    </div>
  </div>

  <div class="row g-3 mb-4">
    <!-- Certyfikat publiczny (PEM) — do wgrania na serwer (już wgrany) -->
    <div class="col-md-6">
      <div class="card border-success">
        <div class="card-header fw-semibold text-success py-2" style="font-size:.85rem">
          <i class="bi bi-patch-check me-1"></i> Certyfikat publiczny (PEM) — zapisany w systemie
        </div>
        <div class="card-body p-2">
          <textarea class="form-control form-control-sm font-monospace"
                    rows="8" readonly onclick="this.select()"
                    aria-label="Certyfikat publiczny PEM — tylko do odczytu"
                    style="font-size:.72rem"><?= h($cert_result['cert_pem']) ?></textarea>
          <div class="d-flex justify-content-end mt-2">
            <button type="button" class="btn btn-outline-success btn-sm"
                    onclick="navigator.clipboard.writeText(document.querySelector('[aria-label=\'Certyfikat publiczny PEM — tylko do odczytu\']').value).then(()=>this.textContent='✓ Skopiowano').catch(()=>{})"
                    aria-label="Skopiuj certyfikat publiczny PEM do schowka">
              <i class="bi bi-clipboard me-1"></i>Kopiuj certyfikat
            </button>
          </div>
          <div class="text-muted mt-1" style="font-size:.75rem">
            Certyfikat publiczny jest już zapisany w systemie. Możesz go też wgrać do zewnętrznego systemu.
          </div>
        </div>
      </div>
    </div>

    <!-- Klucz prywatny — jednorazowy odczyt -->
    <div class="col-md-6">
      <div class="card border-danger">
        <div class="card-header fw-semibold text-danger py-2" style="font-size:.85rem">
          <i class="bi bi-key-fill me-1"></i> Klucz prywatny — pobierz TERAZ (jednorazowy)
        </div>
        <div class="card-body p-2">
          <div class="alert alert-danger py-2 mb-2" style="font-size:.78rem">
            <i class="bi bi-exclamation-triangle me-1"></i>
            <strong>Klucz prywatny nie jest przechowywany na serwerze.</strong>
            Pobierz go teraz i przekaż bezpiecznie doradcy. Po zamknięciu strony klucz jest niedostępny.
          </div>
          <textarea class="form-control form-control-sm font-monospace"
                    rows="8" readonly onclick="this.select()"
                    id="private-key-pem"
                    aria-label="Klucz prywatny PEM — pobierz jednorazowo"
                    style="font-size:.72rem"><?= h($cert_result['key_pem']) ?></textarea>
          <div class="d-flex gap-2 justify-content-end mt-2 flex-wrap">
            <button type="button" class="btn btn-outline-secondary btn-sm"
                    onclick="navigator.clipboard.writeText(document.getElementById('private-key-pem').value).then(()=>this.textContent='✓ Skopiowano').catch(()=>{})"
                    aria-label="Kopiuj klucz prywatny do schowka">
              <i class="bi bi-clipboard me-1"></i>Kopiuj klucz
            </button>
            <button type="button" class="btn btn-danger btn-sm"
                    onclick="downloadPem('<?= h(addslashes($cert_result['user_name'])) ?>', document.getElementById('private-key-pem').value)"
                    aria-label="Pobierz klucz prywatny jako plik PEM">
              <i class="bi bi-download me-1"></i>Pobierz .pem
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <?php if (!$k30_users): ?>
  <div class="alert alert-light border">
    Brak doradców K30. Najpierw przyznaj uprawnienie w
    <a href="<?= APP_URL ?>/karty30/admin/consultants.php">panelu doradców</a>.
  </div>
  <?php else: ?>
  <div class="card shadow-sm">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>Doradca</th>
            <th>Rola systemowa</th>
            <th>Status IKA</th>
            <th>Certyfikat x509</th>
            <th>Akcje</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($k30_users as $u):
            $uid         = (int)$u['id'];
            $display     = _display_name($u);
            $is_blocked  = !empty($u['cpc_blocked_until']) && $u['cpc_blocked_until'] > $now;
            $has_ika     = !empty($u['cpc_code']);
            $has_cert    = !empty($u['cert_fingerprint']);
            $cert_ok     = $has_cert && (int)$u['cert_valid_to'] > time() && $u['cert_active'];
            $cert_expired= $has_cert && (int)$u['cert_valid_to'] < time();
            $days_left   = $has_cert ? (int)(((int)$u['cert_valid_to'] - time()) / 86400) : 0;
            $col_k30     = 'k30-cpc-' . $uid;
            $col_cert    = 'k30-cert-' . $uid;
            $role_labels = ['admin'=>'Administrator','editor'=>'Edytor','viewer'=>'Wolontariusz/Widz','crm_user'=>'Użytkownik CRM'];
          ?>
          <tr>
            <td>
              <div class="fw-semibold"><?= h($display) ?></div>
              <div class="text-muted" style="font-size:.78rem"><?= h($u['email']) ?></div>
            </td>
            <td>
              <span class="badge <?= $u['role']==='admin' ? 'bg-primary' : ($u['role']==='editor' ? 'bg-secondary' : 'bg-warning text-dark') ?>">
                <?= h($role_labels[$u['role']] ?? $u['role']) ?>
              </span>
            </td>
            <td>
              <?php if ($is_blocked): ?>
                <span class="badge bg-danger">Zablokowany</span>
              <?php elseif (!$has_ika): ?>
                <span class="badge bg-warning text-dark">Brak kodu IKA</span>
              <?php else: ?>
                <span class="badge bg-success">Aktywny ✓</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if (!$has_cert): ?>
                <span class="badge bg-danger">Brak certyfikatu</span>
              <?php elseif ($cert_expired): ?>
                <span class="badge bg-warning text-dark">Wygasł <?= date('d.m.Y', (int)$u['cert_valid_to']) ?></span>
              <?php elseif (!$u['cert_active']): ?>
                <span class="badge bg-secondary">Dezaktywowany</span>
              <?php elseif ($days_left < 30): ?>
                <span class="badge bg-warning text-dark">Wygasa za <?= $days_left ?> dni</span>
              <?php else: ?>
                <span class="badge bg-success">Ważny do <?= date('d.m.Y', (int)$u['cert_valid_to']) ?> ✓</span>
              <?php endif; ?>
            </td>
            <td>
              <div class="d-flex flex-wrap gap-1">
                <!-- Ustaw IKA -->
                <button class="btn btn-sm btn-outline-primary"
                        type="button"
                        data-bs-toggle="collapse"
                        data-bs-target="#<?= $col_k30 ?>"
                        aria-expanded="false"
                        aria-controls="<?= $col_k30 ?>"
                        aria-label="Zmień kod IKA doradcy <?= h($display) ?>">
                  <i class="bi bi-key"></i> IKA
                </button>
                <!-- Generuj certyfikat -->
                <button class="btn btn-sm btn-outline-purple"
                        style="border-color:#7C3AED;color:#7C3AED"
                        type="button"
                        data-bs-toggle="collapse"
                        data-bs-target="#<?= $col_cert ?>"
                        aria-expanded="false"
                        aria-controls="<?= $col_cert ?>"
                        aria-label="Generuj certyfikat x509 dla doradcy <?= h($display) ?>">
                  <i class="bi bi-patch-plus"></i> Certyfikat
                </button>
                <?php if ($is_blocked): ?>
                <form method="post" class="d-inline">
                  <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
                  <input type="hidden" name="_action" value="set_cpc_k30">
                  <input type="hidden" name="user_id" value="<?= $uid ?>">
                  <button type="submit" name="auto_generate" value="1"
                          class="btn btn-sm btn-outline-danger"
                          onclick="return confirm('Odblokować kod IKA i wygenerować nowy dla <?= h(addslashes($display)) ?>?')"
                          aria-label="Odblokuj i zresetuj kod IKA dla <?= h($display) ?>">
                    <i class="bi bi-unlock"></i> Odblokuj
                  </button>
                </form>
                <?php endif; ?>
              </div>
            </td>
          </tr>

          <!-- Panel IKA (collapse) -->
          <tr class="table-light">
            <td colspan="5" class="p-0 border-0">
              <div class="collapse" id="<?= $col_k30 ?>">
                <div class="p-3 border-top bg-light">
                  <form method="post" class="row g-2 align-items-end">
                    <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
                    <input type="hidden" name="_action" value="set_cpc_k30">
                    <input type="hidden" name="user_id" value="<?= $uid ?>">
                    <div class="col-sm-auto">
                      <label class="form-label small fw-semibold mb-1" for="k30_cpc_<?= $uid ?>">
                        Kod IKA (6 cyfr) dla <?= h($display) ?>
                      </label>
                      <input type="text" name="cpc_code" id="k30_cpc_<?= $uid ?>"
                             class="form-control form-control-sm font-monospace"
                             maxlength="6" pattern="\d{6}" placeholder="000000"
                             autocomplete="off" inputmode="numeric"
                             aria-describedby="hint_k30_cpc_<?= $uid ?>">
                      <div id="hint_k30_cpc_<?= $uid ?>" class="form-text">6 cyfr lub zaznacz „Generuj losowo"</div>
                    </div>
                    <div class="col-sm-auto d-flex align-items-end pb-1">
                      <div class="form-check mb-0">
                        <input class="form-check-input cpc-auto-generate" type="checkbox"
                               name="auto_generate"
                               id="k30_auto_<?= $uid ?>"
                               data-target="k30_cpc_<?= $uid ?>">
                        <label class="form-check-label small" for="k30_auto_<?= $uid ?>">Generuj losowo</label>
                      </div>
                    </div>
                    <div class="col-sm-auto">
                      <button type="submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-save"></i> Zapisz IKA
                      </button>
                    </div>
                  </form>
                </div>
              </div>
            </td>
          </tr>

          <!-- Panel certyfikatu (collapse) -->
          <tr class="table-light">
            <td colspan="5" class="p-0 border-0">
              <div class="collapse" id="<?= $col_cert ?>">
                <div class="p-3 border-top bg-light">
                  <div class="row g-3 align-items-end">
                    <div class="col-sm-auto">
                      <label class="form-label small fw-semibold mb-1" for="k30_days_<?= $uid ?>">
                        <i class="bi bi-calendar3 me-1"></i>Ważność certyfikatu (dni)
                      </label>
                      <input type="number" id="k30_days_<?= $uid ?>"
                             class="form-control form-control-sm" style="max-width:120px"
                             value="730" min="90" max="3650" step="1"
                             aria-describedby="hint_days_<?= $uid ?>">
                      <div id="hint_days_<?= $uid ?>" class="form-text">90–3650 dni (domyślnie 2 lata)</div>
                    </div>
                    <div class="col-sm-auto">
                      <form method="post" id="gen-cert-form-<?= $uid ?>">
                        <input type="hidden" name="_csrf"      value="<?= csrf_token() ?>">
                        <input type="hidden" name="_action"    value="gen_cert">
                        <input type="hidden" name="user_id"    value="<?= $uid ?>">
                        <input type="hidden" name="valid_days" id="k30_days_hidden_<?= $uid ?>" value="730">
                        <button type="submit"
                                class="btn btn-sm"
                                style="background:#7C3AED;color:#fff;border:none"
                                onclick="document.getElementById('k30_days_hidden_<?= $uid ?>').value=document.getElementById('k30_days_<?= $uid ?>').value"
                                aria-label="Generuj nowy certyfikat x509 dla doradcy <?= h($display) ?> — klucz prywatny zostanie pokazany jednorazowo">
                          <i class="bi bi-patch-plus me-1"></i>
                          <?= $has_cert ? 'Zastąp certyfikat' : 'Generuj certyfikat x509' ?>
                        </button>
                      </form>
                    </div>
                    <?php if ($has_cert): ?>
                    <div class="col-sm-auto">
                      <a href="<?= APP_URL ?>/karty30/admin/cert_upload.php"
                         class="btn btn-outline-secondary btn-sm"
                         aria-label="Przejdź do panelu certyfikatów aby wgrać własny certyfikat PEM">
                        <i class="bi bi-upload me-1"></i>Wgraj własny PEM
                      </a>
                    </div>
                    <?php endif; ?>
                  </div>
                  <?php if ($has_cert): ?>
                  <div class="mt-2 p-2 rounded" style="background:#F0FDF4;border:1px solid #86EFAC;font-size:.8rem">
                    <i class="bi bi-info-circle me-1 text-success"></i>
                    Bieżący certyfikat: <strong><?= h($u['cert_subject']) ?></strong>
                    · ważny do <?= date('d.m.Y', (int)$u['cert_valid_to']) ?>
                    · <code><?= h(substr($u['cert_fingerprint'], 0, 30)) ?>…</code>
                    <br>
                    <span class="text-warning">
                      <i class="bi bi-exclamation-triangle me-1"></i>
                      Generowanie nowego certyfikatu zastąpi istniejący. Poprzednie zatwierdzone konsultacje zachowują swój fingerprint.
                    </span>
                  </div>
                  <?php endif; ?>
                </div>
              </div>
            </td>
          </tr>

          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>
</div><!-- /k30-section -->

<div class="alert alert-warning d-flex gap-2 align-items-start mt-4" role="alert">
  <i class="bi bi-exclamation-triangle-fill fs-5 flex-shrink-0 mt-1"></i>
  <div>
    <strong>Uwaga dotycząca bezpieczeństwa:</strong>
    Wartość kodu IKA nigdy nie pojawia się w dzienniku zdarzeń. Administrator jest odpowiedzialny
    za bezpieczne przekazanie kodu pracownikowi (np. osobiście lub szyfrowanym kanałem).
    Skorzystaj z funkcji <a href="<?= APP_URL ?>/admin/ika_karta.php" target="_blank">Drukuj kartę IKA</a>
    aby wydrukować kod na małej kartce do wręczenia pracownikowi.
    <br><br>
    <strong>Klucz prywatny certyfikatu x509</strong> jest pokazywany jednorazowo po wygenerowaniu
    i <strong>nie jest przechowywany na serwerze</strong>. Przekaż go doradcy bezpiecznym kanałem.
  </div>
</div>

<script>
(function () {
    // Auto-generate IKA checkboxes
    document.querySelectorAll('.cpc-auto-generate').forEach(function (chk) {
        var targetId = chk.dataset.target;
        var input    = document.getElementById(targetId);
        if (!input) return;
        chk.addEventListener('change', function () {
            if (chk.checked) {
                var code = String(Math.floor(Math.random() * 1000000)).padStart(6, '0');
                input.value = code;
            } else {
                input.value = '';
            }
        });
    });
})();

// Pobierz klucz prywatny jako plik .pem
function downloadPem(userName, keyContent) {
    var safe = userName.replace(/[^a-z0-9_-]/gi, '_').toLowerCase();
    var blob = new Blob([keyContent], { type: 'application/x-pem-file' });
    var url  = URL.createObjectURL(blob);
    var a    = document.createElement('a');
    a.href     = url;
    a.download = 'k30_klucz_' + safe + '_' + new Date().toISOString().slice(0,10) + '.pem';
    document.body.appendChild(a);
    a.click();
    URL.revokeObjectURL(url);
    a.remove();
}
</script>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
