<?php
/**
 * karty30/ti/dydaktyk/zespol.php — Zespół i role panelu (kierownik).
 *
 * OSOBNY system uprawnień panelu dydaktyka (k30_ti_panel_roles): dowolnemu
 * aktywnemu użytkownikowi SZO można nadać rolę panelu — kierownik, zastępca
 * kierownika (obaj z pełnymi funkcjami kierownika) albo prowadzący TI
 * (wejście do panelu jak doradca) — bez zmian w rolach i uprawnieniach
 * modułowych systemu głównego.
 */
require_once __DIR__ . '/auth.php';

$me = dyd_require();
if (!dyd_is_staff()) { header('Location: index.php'); exit; }   // ekran kierownika
$uid      = (int)$me['user_id'];
$dyd_name = (string)($me['name'] ?? '');
karty30_migrate();
ti_panel_roles_migrate();

/* ── POST ──────────────────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = $_POST['_op'] ?? '';

    if ($op === 'role_set') {
        $zu_id   = (int)($_POST['user_id'] ?? 0);
        $zu_role = (string)($_POST['role'] ?? '');
        $zu_user = $zu_id ? db_one("SELECT id, name FROM users WHERE id=? AND is_active=1", [$zu_id]) : null;
        if (!$zu_user) {
            flash_set('danger', 'Wybierz aktywnego użytkownika SZO.');
        } elseif (!array_key_exists($zu_role, K30_TI_PANEL_ROLES)) {
            flash_set('danger', 'Wybierz rolę panelu.');
        } else {
            ti_panel_role_set($zu_id, $zu_role, $uid);
            flash_set('success', 'Nadano rolę: ' . $zu_user['name'] . ' → ' . K30_TI_PANEL_ROLES[$zu_role]
                . '. Uprawnienia zadziałają od najbliższego logowania tej osoby do panelu.');
        }
        header('Location: zespol.php'); exit;
    }

    if ($op === 'role_del') {
        $zu_id = (int)($_POST['user_id'] ?? 0);
        if ($zu_id === $uid && ti_panel_role($uid) !== '' && ($me['role'] ?? '') !== 'admin') {
            // Bezpiecznik: kierownik z roli panelowej nie odbiera roli sam sobie
            flash_set('danger', 'Nie możesz odebrać roli panelu samemu sobie.');
        } else {
            ti_panel_role_set($zu_id, '', $uid);
            flash_set('success', 'Rola panelu odebrana.');
        }
        header('Location: zespol.php'); exit;
    }
}

/* ── DANE ──────────────────────────────────────────────────────────────────── */
$zu_assigned = ti_panel_roles_all();
$zu_taken    = array_map(fn($r) => (int)$r['user_id'], $zu_assigned);
$zu_users    = db_all("SELECT id, name, email FROM users WHERE is_active=1 ORDER BY name COLLATE NOCASE");

/* ── HTML ──────────────────────────────────────────────────────────────────── */
$KP_TITLE  = 'Zespół i role — Panel dydaktyka';
$KP_TOPBAR = ['brand' => 'Panel dydaktyka', 'icon' => 'easel2', 'user' => $dyd_name, 'logout' => 'logout.php'];
$KP_BODY_CLASS = 'dyd-usos ti-skin';
include dirname(__DIR__) . '/kursant/_layout_head.php';
$_skin_css = __DIR__ . '/../assets/ti_skin.css';
?>
<link rel="stylesheet" href="../assets/ti_skin.css?v=<?= is_file($_skin_css) ? (int)filemtime($_skin_css) : 1 ?>">

<?php $KIER_CUR = 'zespol.php'; $KIER_LABEL = 'Zespół i role';
   include __DIR__ . '/_kierownik_bar.php'; ?>

<main id="main" class="dyd-wrap">

<div class="d-flex align-items-center gap-3 mb-3 flex-wrap">
  <div>
    <h1 class="h4 fw-bold mb-0"><i class="bi bi-person-gear me-2 text-primary" aria-hidden="true"></i>Zespół i role panelu</h1>
    <p class="text-body-secondary small mb-0">
      Osobne uprawnienia panelu dydaktyka — niezależne od ról systemu SZO
    </p>
  </div>
</div>

<?= flash_html() ?>

<div class="alert alert-light border small d-flex gap-2" role="note">
  <i class="bi bi-info-circle text-primary flex-shrink-0" aria-hidden="true"></i>
  <span>Rolę panelu można nadać <strong>dowolnemu aktywnemu użytkownikowi SZO</strong> — loguje się
  do panelu swoim e-mailem i hasłem SZO. <strong>Kierownik</strong> i <strong>Zastępca kierownika</strong>
  mają pełne funkcje kierownika (grupy, rozliczenia, raporty…), <strong>Prowadzący TI</strong> wchodzi
  do panelu jak doradca i widzi własne kursy. Role systemu głównego (admin, uprawnienia modułu
  Dydaktyka 3) działają jak dotąd — ten rejestr ich nie zmienia.</span>
</div>

<div class="row g-4">
  <div class="col-12 col-lg-7">
    <div class="card">
      <div class="card-header fw-semibold bg-white">
        <i class="bi bi-people text-primary me-1" aria-hidden="true"></i>Nadane role panelu
      </div>
      <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
          <caption class="visually-hidden">Użytkownicy z rolami panelu dydaktyka</caption>
          <thead class="table-light">
            <tr>
              <th scope="col">Użytkownik</th>
              <th scope="col">Rola panelu</th>
              <th scope="col">Nadał(a)</th>
              <th scope="col" class="text-end">Akcje</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$zu_assigned): ?>
            <tr><td colspan="4" class="text-center text-body-secondary py-4">Nikt jeszcze nie ma roli panelu — nadaj pierwszą obok.</td></tr>
            <?php endif; ?>
            <?php foreach ($zu_assigned as $zr): ?>
            <tr>
              <th scope="row" class="fw-semibold">
                <?= h($zr['name']) ?>
                <div class="text-body-secondary fw-normal" style="font-size:.75rem"><?= h($zr['email']) ?></div>
              </th>
              <td>
                <span class="badge text-bg-<?= $zr['role'] === 'prowadzacy' ? 'secondary' : 'primary' ?>">
                  <?= h(K30_TI_PANEL_ROLES[$zr['role']] ?? $zr['role']) ?></span>
              </td>
              <td class="small text-body-secondary">
                <?= h($zr['granted_by_name'] ?? '—') ?>
                <?php if (!empty($zr['created_at'])): ?>
                <div style="font-size:.72rem"><?= h(substr((string)$zr['created_at'], 0, 10)) ?></div>
                <?php endif; ?>
              </td>
              <td class="text-end">
                <form method="post" class="d-inline"
                      onsubmit="return confirm('Odebrać rolę panelu: <?= h(addslashes($zr['name'])) ?>?')">
                  <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                  <input type="hidden" name="_op" value="role_del">
                  <input type="hidden" name="user_id" value="<?= (int)$zr['user_id'] ?>">
                  <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-2" title="Odbierz rolę">
                    <i class="bi bi-trash" aria-hidden="true"></i><span class="visually-hidden">Odbierz rolę</span>
                  </button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="card-footer bg-white small text-body-secondary">
        Zmiana lub odebranie roli działa od najbliższego logowania danej osoby do panelu.
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-5">
    <div class="card">
      <div class="card-header fw-semibold bg-white">
        <i class="bi bi-person-plus text-primary me-1" aria-hidden="true"></i>Nadaj rolę
      </div>
      <div class="card-body">
        <form method="post" class="vstack gap-3">
          <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="_op" value="role_set">
          <div>
            <label class="form-label small fw-semibold mb-1" for="zu-user">Użytkownik SZO</label>
            <select id="zu-user" name="user_id" class="form-select form-select-sm" required>
              <option value="">— wybierz osobę —</option>
              <?php foreach ($zu_users as $zu): ?>
              <option value="<?= (int)$zu['id'] ?>"><?= h($zu['name']) ?> · <?= h($zu['email']) ?><?=
                in_array((int)$zu['id'], $zu_taken, true) ? ' (ma już rolę — zostanie zmieniona)' : '' ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <fieldset>
            <legend class="form-label small fw-semibold fs-6 mb-1">Rola panelu</legend>
            <?php foreach (K30_TI_PANEL_ROLES as $zk => $zl): ?>
            <div class="form-check">
              <input class="form-check-input" type="radio" name="role" id="zu-role-<?= h($zk) ?>"
                     value="<?= h($zk) ?>" <?= $zk === 'zastepca' ? 'checked' : '' ?>>
              <label class="form-check-label" for="zu-role-<?= h($zk) ?>">
                <?= h($zl) ?>
                <span class="text-body-secondary small">
                  <?= $zk === 'prowadzacy' ? '— wejście do panelu, własne kursy' : '— pełne funkcje kierownika' ?>
                </span>
              </label>
            </div>
            <?php endforeach; ?>
          </fieldset>
          <div>
            <button type="submit" class="btn btn-primary btn-sm">
              <i class="bi bi-check-lg me-1" aria-hidden="true"></i>Nadaj rolę
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

</main>
<?php $PRINT_TITLE = 'Zespół i role panelu'; include __DIR__ . '/_print_page.php'; ?>
<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
