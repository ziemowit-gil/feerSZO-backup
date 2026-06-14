<?php
/**
 * karty30/ti/kursant/accounts.php — Zarządzanie kontami kursantów (admin K30).
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/functions.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/karty30.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/sms.php';
require_once __DIR__ . '/auth.php'; // parent_make_token()

k30_require_access();
karty30_migrate();
if (!(can_write('karty30') || is_admin())) {
    flash_set('danger','Brak uprawnień.'); header('Location: ../index.php'); exit;
}

$PAGE_TITLE = 'Konta kursantów TI';

/** Generuje prosty login: pierwsza litera imienia + kropka + nazwisko, bez polskich znaków */
function _gen_student_login(string $name, string $suffix = ''): string {
    $map = ['ą'=>'a','ć'=>'c','ę'=>'e','ł'=>'l','ń'=>'n','ó'=>'o','ś'=>'s','ź'=>'z','ż'=>'z',
            'Ą'=>'A','Ć'=>'C','Ę'=>'E','Ł'=>'L','Ń'=>'N','Ó'=>'O','Ś'=>'S','Ź'=>'Z','Ż'=>'Z'];
    $n = strtr($name, $map);
    $parts = preg_split('/\s+/', trim($n));
    if (count($parts) >= 2) {
        $login = strtolower($parts[0][0] . '.' . end($parts));
    } else {
        $login = strtolower($parts[0]);
    }
    $login = preg_replace('/[^a-z0-9._-]/', '', $login);
    return $login . ($suffix ? $suffix : '');
}

/** Generuje hasło: słowo + cyfry */
function _gen_student_pass(): string {
    $words = ['Kot','Pies','Dom','Las','Rok','Nos','Byk','Lis','Mak','Rak'];
    return $words[random_int(0, count($words)-1)] . random_int(10, 99);
}

/**
 * Wysyła dane logowania do panelu kursanta SMS-em (jeśli SMS włączony i jest numer).
 * Zwraca dopisek do komunikatu flash informujący o statusie wysyłki.
 */
function _student_send_login_sms(string $phone, string $login, string $pass): string {
    $phone = trim($phone);
    if ($phone === '') return ' (brak numeru telefonu — przekaż hasło ręcznie)';
    if (!sms_is_enabled()) return ' (SMS wyłączony — przekaż hasło ręcznie)';
    $org = defined('ORG_NAME') ? ORG_NAME : 'Panel';
    $msg = "{$org} - panel kursanta. Login: {$login}, haslo: {$pass}";
    try {
        sms_send($phone, $msg);
        return ' Hasło wysłano SMS-em.';
    } catch (\Throwable $e) {
        return ' (błąd wysyłki SMS: ' . $e->getMessage() . ')';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = $_POST['_op'] ?? '';

    if ($op === 'create') {
        $cid  = (int)($_POST['client_id'] ?? 0);
        $c    = $cid ? db_one("SELECT * FROM k30_clients WHERE id=?", [$cid]) : null;
        if (!$c) { flash_set('danger','Wybierz beneficjenta.'); header('Location: accounts.php'); exit; }

        // Wygeneruj unikalny login
        $base  = _gen_student_login($c['name']);
        $login = $base;
        $i     = 2;
        while (db_one("SELECT id FROM k30_ti_student_accounts WHERE login=?", [$login])) {
            $login = $base . $i++;
        }
        $pass  = _gen_student_pass();
        $hash  = password_hash($pass, PASSWORD_BCRYPT);

        db_insert('k30_ti_student_accounts', [
            'client_id'     => $cid,
            'login'         => $login,
            'password_hash' => $hash,
            'is_active'     => 1,
            'created_by'    => (int)(current_user()['id'] ?? 0),
            'created_at'    => date('Y-m-d H:i:s'),
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);

        // Pokaż hasło raz w sesji
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        $_SESSION['new_student_creds'] = ['login' => $login, 'password' => $pass, 'name' => $c['name']];
        $sms = _student_send_login_sms($c['phone'] ?? '', $login, $pass);
        flash_set('success', "Konto kursanta dla {$c['name']} utworzone. Login: {$login}." . $sms);
        header('Location: accounts.php'); exit;
    }

    if ($op === 'reset_pass') {
        $aid  = (int)($_POST['account_id'] ?? 0);
        $acc  = $aid ? db_one("SELECT * FROM k30_ti_student_accounts WHERE id=?", [$aid]) : null;
        if (!$acc) { flash_set('danger','Konto nie istnieje.'); header('Location: accounts.php'); exit; }
        $pass = _gen_student_pass();
        db()->prepare("UPDATE k30_ti_student_accounts SET password_hash=?, updated_at=datetime('now') WHERE id=?")
           ->execute([password_hash($pass, PASSWORD_BCRYPT), $aid]);
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        $c = db_one("SELECT name, phone FROM k30_clients WHERE id=?", [$acc['client_id']]);
        $_SESSION['new_student_creds'] = ['login' => $acc['login'], 'password' => $pass, 'name' => $c['name'] ?? ''];
        $sms = _student_send_login_sms($c['phone'] ?? '', $acc['login'], $pass);
        flash_set('success', "Hasło zresetowane dla {$acc['login']}." . $sms);
        header('Location: accounts.php'); exit;
    }

    if ($op === 'toggle') {
        $aid = (int)($_POST['account_id'] ?? 0);
        $acc = db_one("SELECT * FROM k30_ti_student_accounts WHERE id=?", [$aid]);
        if ($acc) {
            db()->prepare("UPDATE k30_ti_student_accounts SET is_active=?, updated_at=datetime('now') WHERE id=?")
               ->execute([$acc['is_active'] ? 0 : 1, $aid]);
        }
        header('Location: accounts.php'); exit;
    }

    if ($op === 'delete') {
        $aid = (int)($_POST['account_id'] ?? 0);
        db()->prepare("DELETE FROM k30_ti_student_accounts WHERE id=?")->execute([$aid]);
        flash_set('success','Konto usunięte.');
        header('Location: accounts.php'); exit;
    }

    // Zapis danych opiekuna + status małoletniego
    if ($op === 'guardian_save') {
        $aid = (int)($_POST['account_id'] ?? 0);
        if ($aid) {
            db()->prepare(
                "UPDATE k30_ti_student_accounts
                 SET is_minor=?, guardian_name=?, guardian_phone=?, guardian_email=?, updated_at=datetime('now')
                 WHERE id=?"
            )->execute([
                isset($_POST['is_minor']) ? 1 : 0,
                trim($_POST['guardian_name'] ?? ''),
                trim($_POST['guardian_phone'] ?? ''),
                trim($_POST['guardian_email'] ?? ''),
                $aid,
            ]);
            flash_set('success', 'Dane opiekuna zapisane.');
        }
        header('Location: accounts.php?guardian=' . $aid); exit;
    }

    // Wygeneruj link magiczny rodzica i wyślij go e-mailem (jeśli jest adres)
    if ($op === 'parent_link') {
        $aid = (int)($_POST['account_id'] ?? 0);
        $acc = $aid ? db_one("SELECT * FROM k30_ti_student_accounts WHERE id=?", [$aid]) : null;
        if ($acc) {
            $token = parent_make_token($aid);
            $url   = rtrim(APP_URL, '/') . '/karty30/ti/kursant/parent.php?t=' . $token;
            if (session_status() !== PHP_SESSION_ACTIVE) session_start();
            $_SESSION['parent_link'] = $url;
            $email = trim($acc['guardian_email'] ?? '');
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                require_once dirname(dirname(dirname(__DIR__))) . '/includes/mail_queue.php';
                $cl   = db_one("SELECT name FROM k30_clients WHERE id=?", [$acc['client_id']]);
                $org  = defined('ORG_NAME') ? ORG_NAME : 'Panel';
                $html = "<p>Dzień dobry,</p>"
                      . "<p>Poniższy link daje dostęp do rozliczeń i frekwencji kursanta <strong>"
                      . h($cl['name'] ?? '') . "</strong> w {$org}:</p>"
                      . "<p><a href=\"{$url}\">{$url}</a></p>"
                      . "<p style='color:#888;font-size:12px'>Link jest ważny 30 dni. Nie udostępniaj go osobom trzecim.</p>";
                try {
                    mail_queue_add($email, $acc['guardian_name'] ?? '', "Dostęp do rozliczeń — {$org}", $html, '', 'ti_parent', $aid, '', true);
                    flash_set('success', 'Link wysłano na e-mail opiekuna: ' . $email);
                } catch (\Throwable $e) {
                    flash_set('warning', 'Link wygenerowany, ale wysyłka e-mail nie powiodła się: ' . $e->getMessage());
                }
            } else {
                flash_set('warning', 'Brak poprawnego e-maila opiekuna — skopiuj link ręcznie poniżej.');
            }
        }
        header('Location: accounts.php?guardian=' . $aid); exit;
    }
}

// Wczytaj
$accounts = db_all(
    "SELECT a.*, cl.name AS client_name
     FROM k30_ti_student_accounts a
     JOIN k30_clients cl ON cl.id=a.client_id
     ORDER BY cl.name"
);
$taken_ids   = array_column($accounts, 'client_id');
$all_clients = db_all("SELECT id, name FROM k30_clients ORDER BY name");
$no_account  = array_filter($all_clients, fn($c) => !in_array((int)$c['id'], $taken_ids));

// Dane nowego konta (z sesji)
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$new_creds = $_SESSION['new_student_creds'] ?? null;
unset($_SESSION['new_student_creds']);
$parent_link = $_SESSION['parent_link'] ?? null;
unset($_SESSION['parent_link']);

// Edytor opiekuna
$guardian_id  = (int)($_GET['guardian'] ?? 0);
$guardian_acc = $guardian_id ? db_one(
    "SELECT a.*, cl.name AS client_name FROM k30_ti_student_accounts a
     JOIN k30_clients cl ON cl.id=a.client_id WHERE a.id=?", [$guardian_id]
) : null;

$portal_url        = rtrim(APP_URL, '/') . '/karty30/ti/kursant/login.php';
$parent_portal_url = rtrim(APP_URL, '/') . '/karty30/ti/kursant/parent.php';

include dirname(dirname(dirname(__DIR__))) . '/karty30/includes/header_k30.php';
?>

<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/karty30/index.php">Karty 30</a></li>
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/karty30/ti/index.php">Zajęcia TI</a></li>
  <li class="breadcrumb-item active">Konta kursantów</li>
</ol></nav>

<div class="d-flex align-items-center mb-3 gap-2">
  <h4 class="mb-0 fw-bold"><i class="bi bi-person-badge text-primary me-2"></i>Konta kursantów</h4>
  <div class="ms-auto d-flex gap-2">
    <a href="<?= h($parent_portal_url) ?>" target="_blank" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-people me-1"></i>Panel rodzica
    </a>
    <a href="<?= h($portal_url) ?>" target="_blank" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-box-arrow-up-right me-1"></i>Panel kursanta
    </a>
  </div>
</div>

<?= flash_html() ?>

<!-- Nowo wygenerowane dane -->
<?php if ($new_creds): ?>
<div class="alert alert-warning d-flex gap-3 align-items-start mb-4 shadow-sm">
  <i class="bi bi-key-fill fs-4 flex-shrink-0" style="color:#b45309"></i>
  <div class="flex-grow-1">
    <div class="fw-bold mb-2">⚠ Dane dostępowe — przekaż kursantowi i zamknij!</div>
    <table class="table table-sm table-bordered mb-2" style="max-width:360px;background:#fff;font-size:.88rem">
      <tr><th>Beneficjent</th><td><?= h($new_creds['name']) ?></td></tr>
      <tr><th>Login</th><td class="font-monospace fw-bold"><?= h($new_creds['login']) ?></td></tr>
      <tr><th>Hasło</th><td class="font-monospace fw-bold text-danger"><?= h($new_creds['password']) ?></td></tr>
    </table>
    <div class="small text-muted">Link do logowania: <a href="<?= h($portal_url) ?>" target="_blank"><?= h($portal_url) ?></a></div>
  </div>
  <button type="button" class="btn-close" onclick="this.closest('.alert').remove()"></button>
</div>
<?php endif; ?>

<!-- Edytor opiekuna / dostęp rodzica -->
<?php if ($guardian_acc): ?>
<div class="card border-0 shadow-sm mb-4" style="max-width:640px">
  <div class="card-header fw-semibold d-flex align-items-center">
    <span><i class="bi bi-people me-2 text-primary"></i>Opiekun / dostęp rodzica — <?= h($guardian_acc['client_name']) ?></span>
    <a href="accounts.php" class="btn-close ms-auto" aria-label="Zamknij"></a>
  </div>
  <div class="card-body">
    <p class="text-muted small mb-3">
      Gdy kursant jest <strong>małoletni</strong>, nie widzi własnych rozliczeń — dostęp ma rodzic/opiekun
      (logowanie kodem SMS na numer opiekuna lub przez link wysłany e-mailem).
    </p>
    <?php if ($parent_link): ?>
    <div class="alert alert-info py-2 small">
      <div class="fw-semibold mb-1"><i class="bi bi-link-45deg me-1"></i>Link dostępu rodzica (ważny 30 dni):</div>
      <code style="word-break:break-all"><?= h($parent_link) ?></code>
    </div>
    <?php endif; ?>
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="_op" value="guardian_save">
      <input type="hidden" name="account_id" value="<?= (int)$guardian_acc['id'] ?>">
      <div class="form-check form-switch mb-3">
        <input class="form-check-input" type="checkbox" name="is_minor" id="minor" <?= $guardian_acc['is_minor'] ? 'checked' : '' ?>>
        <label class="form-check-label fw-semibold" for="minor">Kursant małoletni (ukryj rozliczenia, dostęp dla rodzica)</label>
      </div>
      <div class="row g-2 mb-2">
        <div class="col-md-12"><label class="form-label small">Imię i nazwisko opiekuna</label>
          <input class="form-control form-control-sm" name="guardian_name" value="<?= h($guardian_acc['guardian_name'] ?? '') ?>"></div>
        <div class="col-md-6"><label class="form-label small">Telefon opiekuna (do logowania SMS)</label>
          <input class="form-control form-control-sm" name="guardian_phone" value="<?= h($guardian_acc['guardian_phone'] ?? '') ?>" placeholder="np. 600 100 200"></div>
        <div class="col-md-6"><label class="form-label small">E-mail opiekuna (do linku dostępu)</label>
          <input class="form-control form-control-sm" name="guardian_email" value="<?= h($guardian_acc['guardian_email'] ?? '') ?>" placeholder="rodzic@example.com"></div>
      </div>
      <div class="d-flex gap-2 mt-2">
        <button class="btn btn-primary btn-sm"><i class="bi bi-save me-1"></i>Zapisz</button>
      </div>
    </form>
    <form method="post" class="mt-2">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="_op" value="parent_link">
      <input type="hidden" name="account_id" value="<?= (int)$guardian_acc['id'] ?>">
      <button class="btn btn-outline-secondary btn-sm"><i class="bi bi-envelope-paper me-1"></i>Wygeneruj i wyślij link rodzicowi</button>
    </form>
  </div>
</div>
<?php endif; ?>

<div class="row g-4">

  <!-- Utwórz konto -->
  <?php if ($no_account): ?>
  <div class="col-lg-4">
    <div class="card border-0 shadow-sm">
      <div class="card-header fw-semibold"><i class="bi bi-person-plus me-2 text-success"></i>Utwórz konto</div>
      <div class="card-body">
        <p class="text-muted small mb-3">
          Login i hasło generowane automatycznie.
          Kursant ma dostęp <strong>tylko</strong> do panelu kursanta.
        </p>
        <form method="post">
          <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="_op"   value="create">
          <div class="mb-3">
            <label class="form-label fw-semibold">Beneficjent <span class="text-danger">*</span></label>
            <select class="form-select" name="client_id" required>
              <option value="">— wybierz —</option>
              <?php foreach ($no_account as $c): ?>
              <option value="<?= (int)$c['id'] ?>"><?= h($c['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <button type="submit" class="btn btn-success w-100">
            <i class="bi bi-person-plus me-1"></i>Utwórz konto
          </button>
        </form>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Lista kont -->
  <div class="col-lg-<?= $no_account ? '8' : '12' ?>">
    <div class="card border-0 shadow-sm">
      <div class="card-header fw-semibold d-flex align-items-center">
        <i class="bi bi-people me-2 text-primary"></i>Konta kursantów
        <span class="badge bg-secondary ms-2"><?= count($accounts) ?></span>
      </div>
      <?php if (!$accounts): ?>
      <div class="card-body text-muted">Brak kont. Utwórz pierwsze konto dla beneficjenta.</div>
      <?php else: ?>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0" style="font-size:.86rem">
          <thead class="table-light">
            <tr><th>Beneficjent</th><th>Login</th><th>Status</th><th>Ostatnie logowanie</th><th class="text-end">Akcje</th></tr>
          </thead>
          <tbody>
            <?php foreach ($accounts as $a): ?>
            <tr class="<?= $a['is_active'] ? '' : 'opacity-50' ?>">
              <td class="fw-semibold"><?= h($a['client_name']) ?></td>
              <td class="font-monospace"><?= h($a['login']) ?></td>
              <td>
                <span class="badge <?= $a['is_active'] ? 'bg-success' : 'bg-secondary' ?>">
                  <?= $a['is_active'] ? 'Aktywne' : 'Zablokowane' ?>
                </span>
                <?php if (!empty($a['is_minor'])): ?>
                <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle" title="Małoletni — rozliczenia dla rodzica">
                  <i class="bi bi-people"></i> małoletni
                </span>
                <?php endif; ?>
              </td>
              <td class="text-muted"><?= $a['last_login'] ? date('d.m.Y H:i', strtotime($a['last_login'])) : '—' ?></td>
              <td class="text-end">
                <!-- Opiekun / dostęp rodzica -->
                <a href="?guardian=<?= (int)$a['id'] ?>" class="btn btn-xs btn-sm btn-outline-info py-0 px-2 me-1" title="Opiekun / dostęp rodzica">
                  <i class="bi bi-people"></i>
                </a>
                <!-- Reset hasła -->
                <form method="post" class="d-inline" onsubmit="return confirm('Zresetować hasło?')">
                  <input type="hidden" name="_csrf"       value="<?= h(csrf_token()) ?>">
                  <input type="hidden" name="_op"         value="reset_pass">
                  <input type="hidden" name="account_id"  value="<?= (int)$a['id'] ?>">
                  <button type="submit" class="btn btn-xs btn-sm btn-outline-warning py-0 px-2 me-1" title="Resetuj hasło">
                    <i class="bi bi-key"></i>
                  </button>
                </form>
                <!-- Blokuj/odblokuj -->
                <form method="post" class="d-inline">
                  <input type="hidden" name="_csrf"       value="<?= h(csrf_token()) ?>">
                  <input type="hidden" name="_op"         value="toggle">
                  <input type="hidden" name="account_id"  value="<?= (int)$a['id'] ?>">
                  <button type="submit" class="btn btn-xs btn-sm py-0 px-2 me-1 <?= $a['is_active'] ? 'btn-outline-secondary' : 'btn-outline-success' ?>"
                          title="<?= $a['is_active'] ? 'Zablokuj' : 'Odblokuj' ?>">
                    <i class="bi <?= $a['is_active'] ? 'bi-lock' : 'bi-unlock' ?>"></i>
                  </button>
                </form>
                <!-- Usuń -->
                <form method="post" class="d-inline" onsubmit="return confirm('Usunąć konto?')">
                  <input type="hidden" name="_csrf"       value="<?= h(csrf_token()) ?>">
                  <input type="hidden" name="_op"         value="delete">
                  <input type="hidden" name="account_id"  value="<?= (int)$a['id'] ?>">
                  <button type="submit" class="btn btn-xs btn-sm btn-outline-danger py-0 px-2" title="Usuń konto">
                    <i class="bi bi-trash"></i>
                  </button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>

</div>

<?php include dirname(dirname(dirname(__DIR__))) . '/karty30/includes/footer_k30.php'; ?>
