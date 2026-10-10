<?php
/**
 * tozsamosc/ti_role.php — obsługa ról panelu TI z poziomu Systemu Tożsamości.
 * Kto może: administrator albo kierownik TI. Role: kierownik, zastępca kierownika, prowadzący TI, brak roli.
 * Zapis używa tej samej funkcji co panel dydaktyka (ti_panel_role_set).
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/karty30.php';

require_login();
$me = current_user();
$me_id = (int)($me['id'] ?? 0);
ti_panel_roles_migrate();

$moze = is_admin() || ti_panel_role($me_id) === 'kierownik';
if (!$moze) {
    http_response_code(403);
    die('Obsługa ról TI jest dla administratora albo kierownika TI.');
}

$blad = '';
$ok   = isset($_GET['ok']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $uid  = (int)($_POST['user_id'] ?? 0);
    $rola = (string)($_POST['role'] ?? '');
    $znany = db_one("SELECT id FROM users WHERE id = ? AND is_active = 1", [$uid]);
    if (!$znany) {
        $blad = 'Nie ma takiego aktywnego konta.';
    } elseif ($rola !== '' && !array_key_exists($rola, K30_TI_PANEL_ROLES)) {
        $blad = 'Nieznana rola.';
    } else {
        ti_panel_role_set($uid, $rola, $me_id);
        header('Location: ti_role.php?ok=1'); exit;
    }
}

$osoby = db_all(
    "SELECT u.id, u.name, u.email, COALESCE(r.role, '') AS role
       FROM users u LEFT JOIN k30_ti_panel_roles r ON r.user_id = u.id
      WHERE u.is_active = 1
      ORDER BY u.name"
);

$PAGE_TITLE = 'Role TI';
$TZ_ACTIVE  = '';
include __DIR__ . '/_head.php';
?>
<main class="tw-max-w-5xl tw-mx-auto tw-p-4" id="main">
  <h1 class="tz-h1" style="font-size:1.4rem;font-weight:700;margin-bottom:.5rem">Role w panelu TI</h1>
  <p class="small text-body-secondary mb-3">
    Rola decyduje, co dana osoba widzi w panelu dydaktyka. Zmiana działa od razu.
  </p>

  <?php if ($ok): ?>
  <div class="alert alert-success small rounded-0" role="status">Zapisano rolę.</div>
  <?php endif; ?>
  <?php if ($blad !== ''): ?>
  <div class="alert alert-danger small rounded-0" role="alert"><?= h($blad) ?></div>
  <?php endif; ?>

  <div class="table-responsive">
  <table class="table table-sm align-middle">
    <caption class="visually-hidden">Osoby i ich role w panelu TI</caption>
    <thead><tr><th scope="col">Osoba</th><th scope="col">E-mail</th><th scope="col">Rola TI</th><th scope="col"><span class="visually-hidden">Zapis</span></th></tr></thead>
    <tbody>
    <?php foreach ($osoby as $o): ?>
      <tr>
        <td><?= h($o['name']) ?></td>
        <td class="small"><?= h($o['email']) ?></td>
        <td>
          <form method="post" class="d-flex gap-2 align-items-center" id="f<?= (int)$o['id'] ?>">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="user_id" value="<?= (int)$o['id'] ?>">
            <label class="visually-hidden" for="r<?= (int)$o['id'] ?>">Rola dla <?= h($o['name']) ?></label>
            <select class="form-select form-select-sm" id="r<?= (int)$o['id'] ?>" name="role" style="max-width:14rem">
              <option value="" <?= $o['role'] === '' ? 'selected' : '' ?>>— brak roli —</option>
              <?php foreach (K30_TI_PANEL_ROLES as $k => $lbl): ?>
              <option value="<?= h($k) ?>" <?= $o['role'] === $k ? 'selected' : '' ?>><?= h($lbl) ?></option>
              <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-sm rounded-0 text-white" style="background:#1ba1e2">Zapisz</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</main>
<?php include __DIR__ . '/_foot.php'; ?>
