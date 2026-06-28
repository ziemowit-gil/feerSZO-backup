<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/approval.php';
require_once dirname(__DIR__) . '/includes/permissions.php';
require_once dirname(__DIR__) . '/includes/user_sync.php';

require_role('admin');
ika_require(APP_URL . '/admin/users.php', 3600);
$PAGE_TITLE = 'Zarządzanie użytkownikami';
$errors   = [];
require_once dirname(__DIR__) . '/includes/user_delete.php';
$new_pass = null;

// Load roles from DB for validation and display
$db_roles = roles_all();
$db_roles_map = []; // name => display_name
foreach ($db_roles as $r) {
    $db_roles_map[$r['name']] = $r['display_name'];
}
$valid_roles = array_keys($db_roles_map);

// ── POST handlers ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    // ADD new user
    if ($action === 'add') {
        $first_name = trim($_POST['first_name'] ?? '');
        $last_name  = trim($_POST['last_name']  ?? '');
        $name  = trim($_POST['name'] ?? '');
        // Auto-compose name from first/last if name left blank
        if (!$name && ($first_name || $last_name)) {
            $name = trim("$first_name $last_name");
        }
        $email = trim($_POST['email'] ?? '');
        $pass  = $_POST['password'] ?? '';
        $role  = $_POST['role'] ?? 'viewer';

        if (!$name)  $errors[] = 'Podaj imię i nazwisko.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Nieprawidłowy adres e-mail.';
        if (strlen($pass) < 8)  $errors[] = 'Hasło musi mieć co najmniej 8 znaków.';
        if (!in_array($role, $valid_roles, true)) $errors[] = 'Nieprawidłowa rola.';

        // Opcjonalne połączenie z kontem M365
        $ms_id_link = trim($_POST['microsoft_id'] ?? '');
        // Waliduj format (UUID/GUID z M365)
        if ($ms_id_link && !preg_match('/^[0-9a-f\-]{30,}$/i', $ms_id_link)) {
            $ms_id_link = '';
        }

        if (!$errors) {
            $exists = db_one("SELECT id FROM users WHERE email = ?", [$email]);
            if ($exists) {
                $errors[] = 'Użytkownik z tym e-mailem już istnieje.';
            } else {
                // Jeśli podano microsoft_id, upewnij się że nie jest już zajęty
                if ($ms_id_link) {
                    $ms_conflict = db_one("SELECT id FROM users WHERE microsoft_id = ?", [$ms_id_link]);
                    if ($ms_conflict) {
                        // Odepnij od starego konta — nastąpiło przeniesienie
                        db()->prepare("UPDATE users SET microsoft_id = NULL WHERE microsoft_id = ?")
                            ->execute([$ms_id_link]);
                    }
                }
                $insert_data = [
                    'name'       => $name,
                    'email'      => $email,
                    'password'   => password_hash($pass, PASSWORD_BCRYPT),
                    'role'       => $role,
                    'is_active'  => 1,
                    'created_at' => date('Y-m-d H:i:s'),
                ];
                if ($first_name) $insert_data['first_name'] = $first_name;
                if ($last_name)  $insert_data['last_name']  = $last_name;
                if ($ms_id_link) $insert_data['microsoft_id'] = $ms_id_link;
                db_insert('users', $insert_data);
                $new_uid = (int)db()->lastInsertId();
                $log_note = 'Dodano użytkownika: ' . $name . ' (' . $email . '), rola: ' . $role;
                if ($ms_id_link) $log_note .= ' [połączono z M365]';
                log_user_action($new_uid, (int)current_user()['id'], 'user_create', $log_note);
                user_sync_push($insert_data);
                flash_set('success', 'Użytkownik ' . $name . ' został dodany.'
                    . ($ms_id_link ? ' Konto Microsoft 365 zostało połączone.' : ''));
                header('Location: users.php');
                exit;
            }
        }
    }

    // CHANGE ROLE
    elseif ($action === 'change_role') {
        $uid  = intval($_POST['user_id'] ?? 0);
        $role = $_POST['role'] ?? '';
        if ($uid && in_array($role, $valid_roles, true)) {
            $chk = db_one("SELECT role, email FROM users WHERE id=?", [$uid]);
            if ($chk && $chk['email'] === 'serwis@local') {
                flash_set('danger', 'Konto systemowe SaaS jest chronione.');
            } else {
                db()->prepare("UPDATE users SET role = ? WHERE id = ?")->execute([$role, $uid]);
                log_user_action($uid, (int)current_user()['id'], 'user_role_change',
                    'Zmiana roli: ' . ($chk['role'] ?? '?') . ' → ' . $role);
                user_sync_push(['email' => $chk['email'], 'role' => $role]);
                flash_set('success', 'Rola użytkownika została zmieniona.');
            }
        }
        header('Location: users.php');
        exit;
    }

    // TOGGLE ACTIVE
    elseif ($action === 'toggle_active') {
        $uid = intval($_POST['user_id'] ?? 0);
        $me  = current_user();
        if ($uid && $uid !== (int)$me['id']) {
            $u = db_one("SELECT is_active, email FROM users WHERE id = ?", [$uid]);
            if ($u && $u['email'] === 'serwis@local') {
                flash_set('danger', 'Konto systemowe SaaS jest chronione i nie może być dezaktywowane.');
            } elseif ($u) {
                $new = $u['is_active'] ? 0 : 1;
                db()->prepare("UPDATE users SET is_active = ? WHERE id = ?")->execute([$new, $uid]);
                log_user_action($uid, (int)current_user()['id'], 'user_toggle',
                    $new ? 'Konto aktywowane' : 'Konto dezaktywowane');
                user_sync_push(['email' => $u['email'], 'is_active' => $new]);
                flash_set('success', $new ? 'Użytkownik aktywowany.' : 'Użytkownik dezaktywowany.');
            }
        } else {
            flash_set('danger', 'Nie możesz dezaktywować własnego konta.');
        }
        header('Location: users.php');
        exit;
    }
    // Dostęp do Canva (poziom konta — także dla kont bez umowy) + aprowizacja SSO/JIT
    elseif ($action === 'canva_toggle') {
        require_once dirname(__DIR__) . '/includes/canva.php';
        $uid = intval($_POST['user_id'] ?? 0);
        if ($uid && db_one("SELECT id FROM users WHERE id=?", [$uid])) {
            $cur = canva_user_access_get($uid);
            if ($cur && (int)($cur['access'] ?? 0) === 1) {
                canva_user_revoke($uid);
                log_user_action($uid, (int)current_user()['id'], 'note', 'Canva: wyłączono dostęp');
                flash_set('success', 'Wyłączono dostęp do Canva.');
            } else {
                canva_user_grant($uid, (int)current_user()['id']);
                log_user_action($uid, (int)current_user()['id'], 'note', 'Canva: włączono dostęp (aprowizacja SSO/JIT)');
                flash_set('success', 'Włączono dostęp do Canva. Konto powstanie automatycznie przy pierwszym logowaniu SSO (aprowizacja JIT).');
            }
        }
        header('Location: users.php' . (($_GET['role'] ?? '') ? '?role=' . urlencode($_GET['role']) : ''));
        exit;
    }

    // RESET PASSWORD (random)
    elseif ($action === 'reset_pass') {
        $uid = intval($_POST['user_id'] ?? 0);
        if ($uid) {
            $chars    = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!@#$';
            $new_pass = '';
            for ($i = 0; $i < 12; $i++) {
                $new_pass .= $chars[random_int(0, strlen($chars) - 1)];
            }
            $hash_new = password_hash($new_pass, PASSWORD_BCRYPT);
            // Hasło ustawione przez admina ma działać od razu — także dla kont
            // służbowych @feer.org.pl (polityka „tylko Office"). Flaga
            // allow_local_fallback odblokowuje im logowanie lokalne (jak /auth/convert_account).
            try { db()->exec("ALTER TABLE users ADD COLUMN allow_local_fallback INTEGER NOT NULL DEFAULT 0"); } catch (\Throwable $e) {}
            db()->prepare("UPDATE users SET password=?, must_change_password=1, allow_local_fallback=1 WHERE id=?")
                ->execute([$hash_new, $uid]);
            // Unieważnij wszystkie aktywne sesje użytkownika
            try { require_once dirname(__DIR__) . '/includes/auth_security.php'; session_destroy_all($uid); } catch(\Throwable $e) {}
            log_user_action($uid, (int)current_user()['id'], 'user_password_reset', 'Losowy reset hasła (logowanie lokalne włączone)');
            $u_email = db_one("SELECT email FROM users WHERE id=?", [$uid]);
            if ($u_email) user_sync_push(['email' => $u_email['email'], 'password' => $hash_new]);
            auth_start();
            $_SESSION['reset_pass_info'] = ['uid' => $uid, 'pass' => $new_pass];
        }
        header('Location: users.php');
        exit;
    }

    // FORCE LOGOUT — zdalne wylogowanie wszystkich sesji użytkownika
    elseif ($action === 'force_logout') {
        $uid = intval($_POST['user_id'] ?? 0);
        if ($uid) {
            require_once dirname(__DIR__) . '/includes/auth_security.php';
            $me_id = (int)current_user()['id'];
            // Przy wylogowaniu własnego konta zachowaj bieżącą sesję
            $except = ($uid === $me_id) ? ($_SESSION['_session_token'] ?? '') : '';
            $n = sessions_count_for_user($uid);
            session_destroy_all($uid, $except);
            log_user_action($uid, $me_id, 'user_force_logout', 'Admin zdalnie wylogował użytkownika (sesji: ' . $n . ')');
            $u_email = db_one("SELECT email FROM users WHERE id=?", [$uid]);
            authlog_write($uid, 'force_logout', $u_email['email'] ?? '', 'Zdalne wylogowanie przez administratora');
            flash_set('success', 'Wylogowano użytkownika ze wszystkich aktywnych sesji.');
        }
        header('Location: users.php');
        exit;
    }

    // DISABLE 2FA
    elseif ($action === 'disable_2fa') {
        $uid = intval($_POST['user_id'] ?? 0);
        if ($uid) {
            db()->prepare(
                "UPDATE users SET totp_secret=NULL, totp_confirmed=0, twofa_method='', totp_backup_codes=NULL, twofa_phone=NULL WHERE id=?"
            )->execute([$uid]);
            try { db()->prepare("DELETE FROM webauthn_credentials WHERE user_id=?")->execute([$uid]); } catch (\Throwable $e) {}
            log_user_action($uid, (int)current_user()['id'], '2fa_disabled_admin', 'Admin wyłączył 2FA użytkownika');
        }
        header('Location: users.php');
        exit;
    }

    // SET START PASSWORD
    elseif ($action === 'set_start_pass') {
        $uid    = intval($_POST['user_id'] ?? 0);
        $custom = trim($_POST['start_pass_custom'] ?? '');
        if ($uid) {
            $default_pass = db_one("SELECT value FROM settings WHERE key_='default_user_password'")['value'] ?? '12345qwe';
            $use_pass = ($custom !== '') ? $custom : $default_pass;
            // Hasło ustawione przez admina ma działać od razu — także dla kont
            // służbowych @feer.org.pl (polityka „tylko Office"). Flaga
            // allow_local_fallback odblokowuje im logowanie lokalne (jak /auth/convert_account).
            try { db()->exec("ALTER TABLE users ADD COLUMN allow_local_fallback INTEGER NOT NULL DEFAULT 0"); } catch (\Throwable $e) {}
            db()->prepare("UPDATE users SET password=?, must_change_password=1, allow_local_fallback=1 WHERE id=?")
                ->execute([password_hash($use_pass, PASSWORD_BCRYPT), $uid]);
            try { require_once dirname(__DIR__) . '/includes/auth_security.php'; session_destroy_all($uid); } catch(\Throwable $e) {}
            log_user_action($uid, (int)current_user()['id'], 'user_password_start', 'Ustawiono hasło startowe (logowanie lokalne włączone)');
            auth_start();
            $_SESSION['reset_pass_info'] = ['uid' => $uid, 'pass' => $use_pass];
        }
        header('Location: users.php');
        exit;
    }

    // DELETE USER
    elseif ($action === 'delete_user') {
        $uid    = (int)($_POST['user_id'] ?? 0);
        $confirm_email = trim($_POST['confirm_email'] ?? '');
        $reason = trim($_POST['delete_reason'] ?? '');
        $me     = current_user();

        if (!$uid) {
            flash_set('danger', 'Brak ID użytkownika.');
            header('Location: users.php'); exit;
        }

        // Sprawdź czy e-mail potwierdzający zgadza się z emailem usuwanego
        $target = db_one("SELECT email FROM users WHERE id = ?", [$uid]);
        if (!$target || strtolower($confirm_email) !== strtolower($target['email'])) {
            flash_set('danger', 'Potwierdzenie e-mail niezgodne — anulowano usunięcie.');
            header('Location: users.php'); exit;
        }

        $check = user_delete_preflight($uid, (int)$me['id']);
        if (!$check['ok']) {
            flash_set('danger', $check['msg']);
            header('Location: users.php'); exit;
        }

        $result = user_delete_execute($uid, (int)$me['id'], $reason);
        if ($result['ok']) {
            flash_set('success', $result['msg']);
        } else {
            flash_set('danger', $result['msg']);
        }
        header('Location: users.php'); exit;
    }
}

// Read one-time password info
auth_start();
$reset_info = $_SESSION['reset_pass_info'] ?? null;
unset($_SESSION['reset_pass_info']);

// ── Filters ────────────────────────────────────────────────────────────────────
$filter_search = trim($_GET['q'] ?? '');
$filter_role   = trim($_GET['role'] ?? '');
$filter_active = $_GET['active'] ?? '';

$where_parts = ["email != 'serwis@local'"];
$where_params = [];

if ($filter_search !== '') {
    $where_parts[] = "(name LIKE ? OR email LIKE ?)";
    $where_params[] = '%' . $filter_search . '%';
    $where_params[] = '%' . $filter_search . '%';
}
if ($filter_role !== '') {
    $where_parts[] = "role = ?";
    $where_params[] = $filter_role;
}
if ($filter_active !== '') {
    $where_parts[] = "is_active = ?";
    $where_params[] = (int)$filter_active;
}

$where_sql = $where_parts ? 'WHERE ' . implode(' AND ', $where_parts) : '';
$users = db_all("SELECT * FROM users $where_sql ORDER BY created_at DESC", $where_params);
$me    = current_user();

// Użytkownicy z kluczem WebAuthn
$webauthn_uids = [];
try {
    foreach (db_all("SELECT DISTINCT user_id FROM webauthn_credentials") as $wk) {
        $webauthn_uids[(int)$wk['user_id']] = true;
    }
} catch (\Throwable $e) {}

// Użytkownicy z włączonym dostępem do Canva (poziom konta)
$canva_uids = [];
try {
    foreach (db_all("SELECT user_id FROM canva_user_access WHERE access=1") as $cr) {
        $canva_uids[(int)$cr['user_id']] = true;
    }
} catch (\Throwable $e) {}

// Liczba dodatkowych modułów przypisanych indywidualnie (ponad rolę)
$extra_mod_counts = [];
try {
    foreach (db_all("SELECT user_id, COUNT(*) AS c FROM user_permissions GROUP BY user_id") as $em) {
        $extra_mod_counts[(int)$em['user_id']] = (int)$em['c'];
    }
} catch (\Throwable $e) {}

// Liczba aktywnych sesji na użytkownika (dla przycisku zdalnego wylogowania)
$session_counts = [];
try {
    require_once dirname(__DIR__) . '/includes/auth_security.php';
    foreach (db_all("SELECT user_id, COUNT(*) AS c FROM user_sessions GROUP BY user_id") as $sc) {
        $session_counts[(int)$sc['user_id']] = (int)$sc['c'];
    }
} catch (\Throwable $e) {}

// Stats
$stats = db_one("SELECT
    COUNT(*) AS total,
    SUM(CASE WHEN is_active=1 THEN 1 ELSE 0 END) AS active,
    SUM(CASE WHEN is_active=0 THEN 1 ELSE 0 END) AS inactive
    FROM users WHERE email != 'serwis@local'");

// Per-role counts
$role_counts = [];
foreach ($db_roles as $r) {
    $row = db_one("SELECT COUNT(*) AS c FROM users WHERE role=? AND email != 'serwis@local'", [$r['name']]);
    $role_counts[$r['name']] = (int)($row['c'] ?? 0);
}

// Orphaned viewers
function orphaned_viewers(): array {
    $viewers = db_all("SELECT * FROM users WHERE is_active = 1 AND role = 'viewer' ORDER BY name");
    $active_statuses = ['projekt','podpisana','w realizacji','obowiązująca'];
    $placeholders    = implode(',', array_fill(0, count($active_statuses), '?'));
    $orphaned = [];
    foreach ($viewers as $u) {
        $email = $u['email'] ?? '';
        $ms_id = $u['microsoft_id'] ?? '';
        if (!$email && !$ms_id) continue;
        $found = false;
        foreach (['zlecenie', 'dzielo'] as $t) {
            if ($found) break;
            $conds  = ['m365_login = ?'];
            $params = [$email];
            if ($ms_id) { $conds[] = 'm365_user_id = ?'; $params[] = $ms_id; }
            $r = db_one(
                "SELECT id FROM umowy_{$t} WHERE (" . implode(' OR ', $conds) . ") AND status IN ({$placeholders}) LIMIT 1",
                array_merge($params, $active_statuses)
            );
            if ($r) $found = true;
        }
        if (!$found) {
            $conds  = ['m365_login = ?', 'email = ?'];
            $params = [$email, $email];
            if ($ms_id) { $conds[] = 'm365_user_id = ?'; $params[] = $ms_id; }
            $r = db_one(
                "SELECT id FROM umowy_wolontariat WHERE (" . implode(' OR ', $conds) . ") AND status IN ({$placeholders}) LIMIT 1",
                array_merge($params, $active_statuses)
            );
            if ($r) $found = true;
        }
        if (!$found && $email) {
            try {
                $r = db_one("SELECT id FROM umowy_praca WHERE email_login = ? AND status IN ({$placeholders}) LIMIT 1",
                    array_merge([$email], $active_statuses));
                if ($r) $found = true;
            } catch (\Exception $e) {}
        }
        if (!$found) $orphaned[] = $u;
    }
    return $orphaned;
}

$orphaned = orphaned_viewers();

include dirname(__DIR__) . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0"><i class="bi bi-people text-primary"></i> Zarządzanie użytkownikami</h4>
  <div class="d-flex gap-2">
    <a href="<?= APP_URL ?>/admin/user_sync.php" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-arrow-repeat"></i> Sync →testy
    </a>
    <button class="btn btn-primary btn-sm" data-bs-toggle="offcanvas" data-bs-target="#addUserPanel">
      <i class="bi bi-person-plus"></i> Dodaj użytkownika
    </button>
  </div>
</div>

<?= flash_html() ?>

<?php if ($reset_info): ?>
<div class="alert alert-warning alert-dismissible fade show" role="alert">
  <strong><i class="bi bi-key"></i> Nowe hasło wygenerowane jednorazowo:</strong>
  Użytkownik ID <?= intval($reset_info['uid']) ?> — hasło: <code class="fs-6"><?= h($reset_info['pass']) ?></code>
  <br><small class="text-muted">To hasło jest wyświetlane tylko raz. Przekaż je użytkownikowi bezpiecznym kanałem.</small>
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if ($errors): ?>
<div class="alert alert-danger">
  <ul class="mb-0"><?php foreach ($errors as $e) echo '<li>' . h($e) . '</li>'; ?></ul>
</div>
<?php endif; ?>

<!-- Stats cards -->
<div class="row g-3 mb-3">
  <div class="col-6 col-sm-3">
    <div class="card text-center border-0 shadow-sm h-100">
      <div class="card-body py-3">
        <div class="fs-3 fw-bold text-primary"><?= intval($stats['total']) ?></div>
        <div class="small text-muted">Wszyscy użytkownicy</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-sm-3">
    <div class="card text-center border-0 shadow-sm h-100">
      <div class="card-body py-3">
        <div class="fs-3 fw-bold text-success"><?= intval($stats['active']) ?></div>
        <div class="small text-muted">Aktywni</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-sm-3">
    <div class="card text-center border-0 shadow-sm h-100">
      <div class="card-body py-3">
        <div class="fs-3 fw-bold text-secondary"><?= intval($stats['inactive']) ?></div>
        <div class="small text-muted">Nieaktywni</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-sm-3">
    <div class="card text-center border-0 shadow-sm h-100">
      <div class="card-body py-3">
        <div class="fs-3 fw-bold text-warning"><?= count($orphaned) ?></div>
        <div class="small text-muted">Do weryfikacji</div>
      </div>
    </div>
  </div>
</div>

<?php if ($orphaned): ?>
<div class="alert alert-warning alert-dismissible fade show d-flex gap-3 align-items-start mb-3" role="alert">
  <i class="bi bi-person-exclamation fs-4 mt-1 flex-shrink-0"></i>
  <div class="flex-grow-1">
    <strong>Konta do weryfikacji (<?= count($orphaned) ?>)</strong> —
    poniżsi użytkownicy nie mają żadnej aktywnej umowy w systemie.
    <div class="mt-2 d-flex flex-wrap gap-2">
    <?php foreach ($orphaned as $ou): ?>
      <div class="d-flex align-items-center gap-2 border rounded px-3 py-2 bg-white">
        <div>
          <span class="fw-semibold"><?= h($ou['name']) ?></span>
          <span class="text-muted small ms-1"><?= h($ou['email']) ?></span>
        </div>
        <form method="post" class="d-inline">
          <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
          <input type="hidden" name="action"  value="toggle_active">
          <input type="hidden" name="user_id" value="<?= intval($ou['id']) ?>">
          <button type="submit" class="btn btn-sm btn-warning"
                  onclick="return confirm('Dezaktywować konto <?= h(addslashes($ou['name'])) ?>?')">
            <i class="bi bi-person-dash"></i> Dezaktywuj
          </button>
        </form>
      </div>
    <?php endforeach; ?>
    </div>
  </div>
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Filter bar -->
<div class="card shadow-sm mb-3">
  <div class="card-body py-2">
    <form method="get" class="row g-2 align-items-end">
      <div class="col-sm-4">
        <input type="text" name="q" class="form-control form-control-sm"
               placeholder="Szukaj po nazwie lub e-mailu"
               value="<?= h($filter_search) ?>">
      </div>
      <div class="col-sm-3">
        <select name="role" class="form-select form-select-sm">
          <option value="">Wszystkie role</option>
          <?php foreach ($db_roles as $r): ?>
          <option value="<?= h($r['name']) ?>" <?= $filter_role === $r['name'] ? 'selected' : '' ?>>
            <?= h($r['display_name']) ?> (<?= intval($role_counts[$r['name']] ?? 0) ?>)
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-2">
        <select name="active" class="form-select form-select-sm">
          <option value="">Wszyscy</option>
          <option value="1" <?= $filter_active === '1' ? 'selected' : '' ?>>Aktywni</option>
          <option value="0" <?= $filter_active === '0' ? 'selected' : '' ?>>Nieaktywni</option>
        </select>
      </div>
      <div class="col-sm-auto">
        <button type="submit" class="btn btn-sm btn-outline-primary">
          <i class="bi bi-search"></i> Filtruj
        </button>
        <?php if ($filter_search || $filter_role || $filter_active !== ''): ?>
        <a href="users.php" class="btn btn-sm btn-outline-secondary ms-1">
          <i class="bi bi-x-lg"></i> Wyczyść
        </a>
        <?php endif; ?>
      </div>
    </form>
  </div>
</div>

<!-- Users table -->
<div class="card shadow-sm">
  <div class="card-header fw-semibold d-flex align-items-center gap-2">
    <i class="bi bi-list-ul"></i> Lista użytkowników
    <span class="badge bg-secondary ms-1"><?= count($users) ?></span>
    <a href="roles.php" class="btn btn-sm btn-outline-secondary ms-auto">
      <i class="bi bi-shield-lock"></i> Zarządzaj rolami
    </a>
  </div>
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead class="table-light">
        <tr>
          <th>Imię i nazwisko</th>
          <th>E-mail</th>
          <th>Rola</th>
          <th>Aktywny</th>
          <th>Dodano</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($users as $u): ?>
        <tr class="<?= !$u['is_active'] ? 'text-muted' : '' ?>">
          <td class="fw-semibold">
            <?= h($u['name']) ?>
            <?php if ((int)$u['id'] === (int)$me['id']): ?>
            <span class="badge bg-info ms-1">ja</span>
            <?php endif; ?>
          </td>
          <td>
            <?= h($u['email']) ?>
            <?php if (!empty($u['microsoft_id'])): ?>
            <i class="bi bi-microsoft ms-1 text-primary" style="font-size:.8rem;color:#00a4ef!important" title="Połączone z M365: <?= h($u['microsoft_id']) ?>"></i>
            <?php endif; ?>
          </td>
          <td>
            <form method="post" class="d-inline-flex align-items-center gap-1">
              <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="action" value="change_role">
              <input type="hidden" name="user_id" value="<?= intval($u['id']) ?>">
              <select name="role" class="form-select form-select-sm" style="width:auto"
                      onchange="this.form.submit()" title="Zmień rolę">
                <?php foreach ($db_roles as $r): ?>
                <option value="<?= h($r['name']) ?>" <?= $u['role'] === $r['name'] ? 'selected' : '' ?>>
                  <?= h($r['display_name']) ?>
                </option>
                <?php endforeach; ?>
              </select>
            </form>
          </td>
          <td>
            <?php if ($u['is_active']): ?>
            <span class="badge bg-success">Tak</span>
            <?php else: ?>
            <span class="badge bg-secondary">Nie</span>
            <?php endif; ?>
          </td>
          <td class="small"><?= date_pl($u['created_at']) ?></td>
          <td class="text-end">
            <div class="d-flex justify-content-end gap-1">
              <form method="post" class="d-inline">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="toggle_active">
                <input type="hidden" name="user_id" value="<?= intval($u['id']) ?>">
                <?php if ((int)$u['id'] === (int)$me['id']): ?>
                <button type="button" class="btn btn-sm btn-outline-secondary" disabled title="Nie możesz dezaktywować siebie">
                  <i class="bi bi-person-dash"></i>
                </button>
                <?php else: ?>
                <button type="submit" class="btn btn-sm <?= $u['is_active'] ? 'btn-outline-warning' : 'btn-outline-success' ?>"
                        title="<?= $u['is_active'] ? 'Dezaktywuj' : 'Aktywuj' ?>"
                        onclick="return confirm('<?= $u['is_active'] ? 'Dezaktywować' : 'Aktywować' ?> tego użytkownika?')">
                  <i class="bi bi-person-<?= $u['is_active'] ? 'dash' : 'check' ?>"></i>
                </button>
                <?php endif; ?>
              </form>
              <?php $has_canva = isset($canva_uids[(int)$u['id']]); ?>
              <form method="post" class="d-inline"
                    onsubmit="return confirm('<?= $has_canva ? 'Wyłączyć' : 'Włączyć' ?> dostęp do Canva dla <?= h(addslashes($u['name'])) ?>?')">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="canva_toggle">
                <input type="hidden" name="user_id" value="<?= intval($u['id']) ?>">
                <button type="submit" class="btn btn-sm <?= $has_canva ? 'text-white' : 'btn-outline-secondary' ?>"
                        style="<?= $has_canva ? 'background:#7c3aed;border-color:#7c3aed' : '' ?>"
                        title="<?= $has_canva ? 'Canva: dostęp włączony — kliknij, aby wyłączyć' : 'Canva: włącz dostęp (aprowizacja SSO/JIT)' ?>">
                  <i class="bi bi-palette<?= $has_canva ? '-fill' : '' ?>"></i>
                </button>
              </form>
              <?php $extra_n = $extra_mod_counts[(int)$u['id']] ?? 0; ?>
              <a href="<?= APP_URL ?>/admin/user_modules.php?uid=<?= intval($u['id']) ?>"
                 class="btn btn-sm position-relative <?= $extra_n ? 'text-white' : 'btn-outline-secondary' ?>"
                 style="<?= $extra_n ? 'background:#0d9488;border-color:#0d9488' : '' ?>"
                 title="Dodatkowe moduły (ponad rolę)<?= $extra_n ? " — przypisano: $extra_n" : '' ?>">
                <i class="bi bi-grid-3x3-gap<?= $extra_n ? '-fill' : '' ?>"></i>
                <?php if ($extra_n): ?><span class="badge rounded-pill bg-light text-dark position-absolute top-0 start-100 translate-middle" style="font-size:.6rem"><?= $extra_n ?></span><?php endif; ?>
              </a>
              <button type="button" class="btn btn-sm btn-outline-danger"
                      title="Resetuj / ustaw hasło"
                      data-bs-toggle="modal" data-bs-target="#passModal"
                      data-uid="<?= intval($u['id']) ?>"
                      data-name="<?= h($u['name']) ?>">
                <i class="bi bi-key"></i>
              </button>
              <?php
              $has_2fa = !empty($u['twofa_method']) || !empty($u['totp_confirmed']) || isset($webauthn_uids[(int)$u['id']]);
              $fa_label = isset($webauthn_uids[(int)$u['id']]) ? 'WebAuthn' : strtoupper($u['twofa_method'] ?: 'TOTP');
              ?>
              <?php if ($has_2fa): ?>
              <form method="post" class="d-inline"
                    onsubmit="return confirm('Wyłączyć 2FA (<?= $fa_label ?>) dla <?= h(addslashes($u['name'])) ?>?')">
                <input type="hidden" name="_csrf"    value="<?= csrf_token() ?>">
                <input type="hidden" name="action"   value="disable_2fa">
                <input type="hidden" name="user_id"  value="<?= intval($u['id']) ?>">
                <button type="submit" class="btn btn-sm btn-outline-warning" title="Wyłącz 2FA (<?= $fa_label ?>)">
                  <i class="bi bi-shield-x"></i>
                </button>
              </form>
              <?php endif; ?>
              <?php $sess_n = $session_counts[(int)$u['id']] ?? 0; ?>
              <?php if ($sess_n > 0): ?>
              <form method="post" class="d-inline"
                    onsubmit="return confirm('Zdalnie wylogować <?= h(addslashes($u['name'])) ?> ze wszystkich aktywnych sesji (<?= $sess_n ?>)?<?= (int)$u['id'] === (int)$me['id'] ? '\nTwoja bieżąca sesja pozostanie aktywna.' : '' ?>')">
                <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
                <input type="hidden" name="action"  value="force_logout">
                <input type="hidden" name="user_id" value="<?= intval($u['id']) ?>">
                <button type="submit" class="btn btn-sm btn-outline-warning position-relative"
                        title="Zdalne wylogowanie — zakończ wszystkie sesje (<?= $sess_n ?>)">
                  <i class="bi bi-box-arrow-right"></i>
                  <span class="badge rounded-pill bg-secondary position-absolute top-0 start-100 translate-middle" style="font-size:.6rem"><?= $sess_n ?></span>
                </button>
              </form>
              <?php endif; ?>
              <?php if ((int)$u['id'] !== (int)$me['id'] && $u['is_active']): ?>
              <form method="post" action="impersonate.php" class="d-inline"
                    onsubmit="return confirm('Przełączyć na konto <?= h(addslashes($u['name'])) ?>?\nBędziesz widzieć panel tak jak ten użytkownik.')">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="user_id" value="<?= intval($u['id']) ?>">
                <button type="submit" class="btn btn-sm btn-outline-secondary"
                        title="Podgląd jako ten użytkownik">
                  <i class="bi bi-person-badge"></i>
                </button>
              </form>
              <?php endif; ?>
              <?php if ((int)$u['id'] !== (int)$me['id'] && $u['email'] !== 'serwis@local'): ?>
              <button type="button"
                      class="btn btn-sm btn-outline-danger"
                      title="Usuń konto użytkownika"
                      data-bs-toggle="modal" data-bs-target="#deleteUserModal"
                      data-uid="<?= intval($u['id']) ?>"
                      data-name="<?= h($u['name']) ?>"
                      data-email="<?= h($u['email']) ?>"
                      data-role="<?= h($u['role']) ?>"
                      data-active="<?= $u['is_active'] ? '1' : '0' ?>">
                <i class="bi bi-trash3"></i>
              </button>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$users): ?>
        <tr><td colspan="6" class="text-center text-muted py-4">Brak użytkowników spełniających kryteria.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Offcanvas: Add user -->
<div class="offcanvas offcanvas-end" tabindex="-1" id="addUserPanel" style="width:400px">
  <div class="offcanvas-header border-bottom">
    <h5 class="offcanvas-title"><i class="bi bi-person-plus"></i> Dodaj użytkownika</h5>
    <button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button>
  </div>
  <div class="offcanvas-body">
    <form method="post" id="addUserForm">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="add">
      <input type="hidden" name="microsoft_id" id="m365LinkId" value="">
      <div class="row g-2 mb-3">
        <div class="col-6">
          <label class="form-label">Imię</label>
          <input name="first_name" id="addUserFirstName" class="form-control"
                 value="<?= h($_POST['first_name'] ?? '') ?>" autocomplete="off"
                 placeholder="np. Jan">
        </div>
        <div class="col-6">
          <label class="form-label">Nazwisko</label>
          <input name="last_name" id="addUserLastName" class="form-control"
                 value="<?= h($_POST['last_name'] ?? '') ?>" autocomplete="off"
                 placeholder="np. Kowalski">
        </div>
        <div class="col-12">
          <div class="form-text mt-0">Opcjonalnie — używane do inicjałów opiekuna i powiadomień.</div>
        </div>
      </div>
      <div class="mb-3">
        <label class="form-label">Imię i nazwisko (wyświetlane) *</label>
        <input name="name" id="addUserName" class="form-control"
               value="<?= h($_POST['name'] ?? '') ?>" required
               autocomplete="off" placeholder="Imię Nazwisko">
        <div class="form-text">Wypełnia się automatycznie z pól powyżej.</div>
      </div>
      <div class="mb-3">
        <label class="form-label">Adres e-mail *</label>
        <input name="email" id="addUserEmail" type="email" class="form-control"
               value="<?= h($_POST['email'] ?? '') ?>" required>
      </div>

      <!-- M365 match hint — shown dynamically -->
      <div id="m365MatchHint" class="mb-3" style="display:none">
        <div class="card border-primary border-opacity-50 shadow-none">
          <div class="card-body py-2 px-3 d-flex align-items-start gap-2">
            <i class="bi bi-microsoft text-primary fs-5 mt-1 flex-shrink-0" style="color:#00a4ef!important"></i>
            <div class="flex-grow-1">
              <div class="small fw-semibold mb-1">Znaleziono konto Microsoft 365</div>
              <div class="small text-muted" id="m365MatchInfo"></div>
              <div class="mt-2 d-flex gap-2" id="m365MatchButtons">
                <button type="button" class="btn btn-sm btn-primary" id="m365LinkBtn">
                  <i class="bi bi-link-45deg"></i> Połącz konta
                </button>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="m365SkipBtn">
                  Pomiń
                </button>
              </div>
              <div class="mt-1 d-none" id="m365LinkedConfirm">
                <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Konta zostaną połączone</span>
                <button type="button" class="btn btn-link btn-sm text-danger p-0 ms-2" id="m365UnlinkBtn">Cofnij</button>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="mb-3">
        <label class="form-label">Hasło * <small class="text-muted">(min. 8 znaków)</small></label>
        <input name="password" type="password" class="form-control" minlength="8" required autocomplete="new-password">
      </div>
      <div class="mb-3">
        <label class="form-label">Rola</label>
        <select name="role" class="form-select">
          <?php foreach ($db_roles as $r): ?>
          <option value="<?= h($r['name']) ?>" <?= ($_POST['role'] ?? 'viewer') === $r['name'] ? 'selected' : '' ?>>
            <?= h($r['display_name']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <button type="submit" class="btn btn-primary w-100">
        <i class="bi bi-person-plus"></i> Dodaj użytkownika
      </button>
    </form>

    <hr>
    <h6 class="text-muted small text-uppercase fw-bold">Legenda ról</h6>
    <?php foreach ($db_roles as $r): ?>
    <div class="mb-1 small">
      <strong><?= h($r['display_name']) ?></strong>
      <?php if ($r['description']): ?>
      — <span class="text-muted"><?= h($r['description']) ?></span>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- Modal: Reset / Set password -->
<div class="modal fade" id="passModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-key"></i> Ustaw hasło użytkownika</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div id="passModalUserName" class="fw-semibold mb-3 text-center text-muted small"></div>

        <div class="alert alert-info py-2 px-3 small d-flex gap-2 mb-3">
          <i class="bi bi-info-circle-fill flex-shrink-0 mt-1"></i>
          <span>Dla kont służbowych <strong>@feer.org.pl</strong> (logowanie tylko przez Microsoft 365)
          ustawienie hasła <strong>odblokowuje logowanie lokalne</strong> tym hasłem (logowanie awaryjne).
          Użytkownik zostanie poproszony o zmianę hasła przy pierwszym logowaniu.</span>
        </div>

        <!-- Option A: start password -->
        <div class="card border-primary border-opacity-50 mb-3">
          <div class="card-body py-2 px-3">
            <form method="post" id="formStartPass">
              <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
              <input type="hidden" name="action"  value="set_start_pass">
              <input type="hidden" name="user_id" id="spUserId" value="">
              <div class="d-flex align-items-center gap-2 mb-2">
                <i class="bi bi-123 text-primary fs-4"></i>
                <div>
                  <div class="fw-semibold">Hasło startowe</div>
                  <div class="text-muted small">Proste hasło do przekazania wolontariuszowi</div>
                </div>
              </div>
              <?php
              $default_pw = db_one("SELECT value FROM settings WHERE key_='default_user_password'")['value'] ?? '12345qwe';
              ?>
              <div class="input-group mb-2">
                <input type="text" name="start_pass_custom" class="form-control form-control-sm font-monospace"
                       placeholder="Hasło startowe" value="<?= h($default_pw) ?>"
                       id="startPassInput">
                <button type="button" class="btn btn-sm btn-outline-secondary"
                        onclick="document.getElementById('startPassInput').value='<?= h(addslashes($default_pw)) ?>'">
                  Domyślne
                </button>
              </div>
              <button type="submit" class="btn btn-primary btn-sm w-100">
                <i class="bi bi-key-fill"></i> Ustaw to hasło
              </button>
            </form>
          </div>
        </div>

        <!-- Option B: random password -->
        <div class="card">
          <div class="card-body py-2 px-3">
            <form method="post" id="formRandPass">
              <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
              <input type="hidden" name="action"  value="reset_pass">
              <input type="hidden" name="user_id" id="rpUserId" value="">
              <div class="d-flex align-items-center gap-2 mb-2">
                <i class="bi bi-shuffle text-secondary fs-4"></i>
                <div>
                  <div class="fw-semibold">Losowe hasło</div>
                  <div class="text-muted small">Wygeneruj bezpieczne hasło 12 znaków</div>
                </div>
              </div>
              <button type="submit" class="btn btn-outline-secondary btn-sm w-100">
                <i class="bi bi-arrow-repeat"></i> Wygeneruj losowe hasło
              </button>
            </form>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
document.getElementById('passModal').addEventListener('show.bs.modal', function(e) {
    var btn  = e.relatedTarget;
    document.getElementById('passModalUserName').textContent = btn.dataset.name;
    document.getElementById('spUserId').value = btn.dataset.uid;
    document.getElementById('rpUserId').value = btn.dataset.uid;
});

// ── M365 auto-match ──────────────────────────────────────────────────────────
(function () {
    var nameEl   = document.getElementById('addUserName');
    var emailEl  = document.getElementById('addUserEmail');
    var hint     = document.getElementById('m365MatchHint');
    var info     = document.getElementById('m365MatchInfo');
    var buttons  = document.getElementById('m365MatchButtons');
    var confirm_ = document.getElementById('m365LinkedConfirm');
    var linkBtn  = document.getElementById('m365LinkBtn');
    var skipBtn  = document.getElementById('m365SkipBtn');
    var unlinkBtn = document.getElementById('m365UnlinkBtn');
    var hidId    = document.getElementById('m365LinkId');

    if (!nameEl) return; // M365 or form not present

    var _timer = null;
    var _currentMsId = '';

    function reset() {
        hint.style.display = 'none';
        hidId.value = '';
        _currentMsId = '';
        buttons.classList.remove('d-none');
        confirm_.classList.add('d-none');
    }

    function showMatch(msUser, source, expectedLogin, alreadyLinked) {
        _currentMsId = msUser.id;
        var upn  = msUser.userPrincipalName || msUser.mail || '?';
        var name = msUser.displayName || upn;
        var html = '<strong>' + escH(name) + '</strong> <span class="text-muted">(' + escH(upn) + ')</span>';
        if (source === 'generated_login') {
            html += '<br><span class="text-muted" style="font-size:.72rem">Login wygenerowany z imienia i nazwiska: '
                  + escH(expectedLogin) + '</span>';
        }
        if (alreadyLinked) {
            html += '<br><span class="text-warning" style="font-size:.72rem">'
                  + '<i class="bi bi-exclamation-triangle"></i> Połączone z kontem: '
                  + escH(alreadyLinked.name) + '</span>';
        }
        info.innerHTML = html;
        buttons.classList.remove('d-none');
        confirm_.classList.add('d-none');
        hidId.value = '';
        hint.style.display = '';
    }

    function escH(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function search() {
        var email = emailEl.value.trim();
        var name  = nameEl.value.trim();
        if (!email && !name) return;
        var url = '<?= APP_URL ?>/admin/api/m365_find_user.php?'
                + 'email=' + encodeURIComponent(email)
                + '&name='  + encodeURIComponent(name);
        fetch(url)
            .then(function(r){ return r.json(); })
            .then(function(data) {
                if (!data.configured) return reset();
                var results = data.results || [];
                if (!results.length) return reset();
                var r = results[0]; // Pokaż pierwszy wynik
                showMatch(r.ms_user, r.source, r.expected_login || '', r.already_linked || null);
            })
            .catch(function() { reset(); });
    }

    function debounce(fn, ms) {
        return function() {
            clearTimeout(_timer);
            _timer = setTimeout(fn, ms);
        };
    }

    nameEl.addEventListener('blur', debounce(search, 300));
    emailEl.addEventListener('blur', debounce(search, 300));

    linkBtn.addEventListener('click', function() {
        hidId.value = _currentMsId;
        buttons.classList.add('d-none');
        confirm_.classList.remove('d-none');
    });

    skipBtn.addEventListener('click', function() {
        reset();
    });

    unlinkBtn.addEventListener('click', function() {
        hidId.value = '';
        buttons.classList.remove('d-none');
        confirm_.classList.add('d-none');
    });

    // Reset po zamknięciu offcanvasa
    var oc = document.getElementById('addUserPanel');
    if (oc) oc.addEventListener('hidden.bs.offcanvas', function() {
        reset();
        // Nie czyść formularza — PHP zachowuje wartości po błędzie
    });
})();

// ── Auto-fill display name from first + last name ───────────────────────────
(function () {
    var fn   = document.getElementById('addUserFirstName');
    var ln   = document.getElementById('addUserLastName');
    var name = document.getElementById('addUserName');
    if (!fn || !ln || !name) return;

    function compose() {
        // Only auto-fill if user hasn't typed in the name field themselves
        var composed = (fn.value.trim() + ' ' + ln.value.trim()).trim();
        if (composed) name.value = composed;
    }

    fn.addEventListener('input', compose);
    ln.addEventListener('input', compose);

    // Also clear auto-compose if user edits name manually
    name.addEventListener('input', function () {
        // Mark as manually edited so we stop overwriting
        name.dataset.manual = '1';
    });
    fn.addEventListener('input', function () { if (!name.dataset.manual) compose(); });
    ln.addEventListener('input', function () { if (!name.dataset.manual) compose(); });
    document.getElementById('addUserPanel').addEventListener('hidden.bs.offcanvas', function () {
        delete name.dataset.manual;
    });
})();
</script>

<!-- ══ MODAL: Usuń użytkownika ══════════════════════════════════════════════ -->
<div class="modal fade" id="deleteUserModal" tabindex="-1" aria-labelledby="deleteUserModalLabel">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-danger">
      <div class="modal-header bg-danger text-white">
        <h5 class="modal-title" id="deleteUserModalLabel">
          <i class="bi bi-trash3-fill me-2"></i>Trwałe usunięcie konta
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="post" id="deleteUserForm">
        <?= csrf_field() ?>
        <input type="hidden" name="action"   value="delete_user">
        <input type="hidden" name="user_id"  id="del-uid" value="">
        <div class="modal-body">

          <!-- Info o użytkowniku -->
          <div id="del-user-info" class="alert alert-secondary py-2 mb-3">
            <div class="d-flex gap-2 align-items-start">
              <i class="bi bi-person-circle fs-4 flex-shrink-0"></i>
              <div>
                <div class="fw-bold" id="del-name"></div>
                <div class="small text-muted" id="del-email-info"></div>
                <div class="small" id="del-role-badge"></div>
              </div>
            </div>
          </div>

          <!-- Ostrzeżenie o aktywnym koncie -->
          <div id="del-active-warn" class="alert alert-warning d-flex gap-2 py-2 mb-3 d-none">
            <i class="bi bi-exclamation-triangle-fill flex-shrink-0"></i>
            <span class="small">Konto jest <strong>aktywne</strong>. Rozważ dezaktywację zamiast trwałego usunięcia.</span>
          </div>

          <!-- Powiązane dane (ładowane AJAX) -->
          <div id="del-impact" class="mb-3">
            <div class="text-center text-muted small py-2">
              <span class="spinner-border spinner-border-sm me-1"></span>Analizuję powiązane dane…
            </div>
          </div>

          <!-- Powód usunięcia -->
          <div class="mb-3">
            <label class="form-label small fw-semibold" for="del-reason">
              Powód usunięcia <span class="text-muted fw-normal">(opcjonalnie, zapisywany w logu)</span>
            </label>
            <input type="text" class="form-control form-control-sm"
                   id="del-reason" name="delete_reason"
                   placeholder="np. Konto testowe, duplikat…" maxlength="200">
          </div>

          <!-- Potwierdzenie przez wpisanie e-maila -->
          <div class="mb-1">
            <label class="form-label small fw-semibold text-danger" for="del-confirm">
              Wpisz adres e-mail użytkownika aby potwierdzić:
            </label>
            <input type="email" class="form-control form-control-sm border-danger"
                   id="del-confirm" name="confirm_email"
                   placeholder="adres@email.pl" autocomplete="off" required>
          </div>
          <div class="form-text text-danger small">
            <i class="bi bi-exclamation-triangle me-1"></i>
            Operacja jest <strong>nieodwracalna</strong>. Dane powiązane (sesje, preferencje) zostaną usunięte.
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="btn btn-danger btn-sm" id="del-submit-btn" disabled>
            <i class="bi bi-trash3 me-1"></i>Usuń trwale
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
(function () {
  var modal = document.getElementById('deleteUserModal');
  if (!modal) return;

  // Wypełnij modal danymi klikniętego użytkownika
  modal.addEventListener('show.bs.modal', function (e) {
    var btn     = e.relatedTarget;
    var uid     = btn.dataset.uid;
    var name    = btn.dataset.name;
    var email   = btn.dataset.email;
    var role    = btn.dataset.role;
    var isActive= btn.dataset.active === '1';

    document.getElementById('del-uid').value   = uid;
    document.getElementById('del-name').textContent  = name;
    document.getElementById('del-email-info').textContent = email;
    document.getElementById('del-role-badge').innerHTML =
      '<span class="badge bg-secondary">' + role + '</span>';

    // Ostrzeżenie o aktywnym koncie
    document.getElementById('del-active-warn').classList.toggle('d-none', !isActive);

    // Reset formularza
    document.getElementById('del-confirm').value = '';
    document.getElementById('del-reason').value  = '';
    document.getElementById('del-submit-btn').disabled = true;

    // Załaduj impact AJAX
    var impactEl = document.getElementById('del-impact');
    impactEl.innerHTML = '<div class="text-center text-muted small py-2">' +
      '<span class="spinner-border spinner-border-sm me-1"></span>Analizuję powiązane dane…</div>';

    fetch('<?= APP_URL ?>/admin/users_ajax.php?action=delete_impact&uid=' + uid, {
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(json => {
      if (!json.ok) { impactEl.innerHTML = ''; return; }
      var html = '';
      var d = json.data;

      if (d.contracts && Object.keys(d.contracts).length) {
        html += '<div class="alert alert-danger py-2 mb-2 small"><i class="bi bi-file-earmark-x me-1"></i>' +
          '<strong>Aktywne umowy:</strong> ' +
          Object.entries(d.contracts).map(([k,v]) => k + ' (' + v + ')').join(', ') +
          '</div>';
      }
      if (d.cascade && Object.keys(d.cascade).length) {
        html += '<div class="small mb-1"><strong class="text-danger">Zostanie usunięte:</strong><ul class="mb-1 mt-1">';
        for (var k in d.cascade) html += '<li>' + k + ': ' + d.cascade[k] + '</li>';
        html += '</ul></div>';
      }
      if (d.set_null && Object.keys(d.set_null).length) {
        html += '<div class="small mb-1 text-muted"><strong>Pozostaje (user_id = NULL):</strong><ul class="mb-1 mt-1">';
        for (var k in d.set_null) html += '<li>' + k + ': ' + d.set_null[k] + '</li>';
        html += '</ul></div>';
      }
      if (!html) html = '<div class="text-muted small">Brak powiązanych danych.</div>';
      impactEl.innerHTML = html;
    })
    .catch(() => { impactEl.innerHTML = ''; });
  });

  // Odblokuj przycisk gdy e-mail wpisany
  document.getElementById('del-confirm').addEventListener('input', function () {
    var emailInfo = document.getElementById('del-email-info').textContent.trim();
    document.getElementById('del-submit-btn').disabled =
      this.value.toLowerCase() !== emailInfo.toLowerCase();
  });
})();
</script>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
