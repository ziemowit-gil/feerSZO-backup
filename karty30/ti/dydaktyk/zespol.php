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
            // Konto panelowe (dydaktyk_ti) bez roli jest bezużyteczne i nigdzie
            // nie powinno się logować — dezaktywujemy je razem z rolą.
            $zu_row = db_one("SELECT role FROM users WHERE id=?", [$zu_id]);
            if (($zu_row['role'] ?? '') === 'dydaktyk_ti') {
                db()->prepare("UPDATE users SET is_active=0 WHERE id=?")->execute([$zu_id]);
                flash_set('success', 'Rola odebrana, konto panelowe dezaktywowane.');
            } else {
                flash_set('success', 'Rola panelu odebrana.');
            }
        }
        header('Location: zespol.php'); exit;
    }

    // System hybrydowy: dydaktyk tworzony i zarządzany WYŁĄCZNIE w panelu TI —
    // wiersz users z rolą dydaktyk_ti (dla FK: kursy/dostępności/wypłaty),
    // bez logowania do SZO. Dydaktycy z pełnym kontem SZO działają jak dotąd.
    if ($op === 'dyd_create') {
        $zc_name  = trim((string)($_POST['name'] ?? ''));
        $zc_email = strtolower(trim((string)($_POST['email'] ?? '')));
        $zc_role  = (string)($_POST['role'] ?? 'prowadzacy');
        $zc_pass  = (string)($_POST['password'] ?? '');
        if ($zc_name === '' || !filter_var($zc_email, FILTER_VALIDATE_EMAIL)) {
            flash_set('danger', 'Podaj imię i nazwisko oraz poprawny e-mail.');
        } elseif (!array_key_exists($zc_role, K30_TI_PANEL_ROLES)) {
            flash_set('danger', 'Wybierz rolę panelu.');
        } elseif (db_one("SELECT id FROM users WHERE email=?", [$zc_email])) {
            flash_set('danger', 'Użytkownik z tym e-mailem już istnieje — nadaj mu rolę w formularzu obok (system hybrydowy: konta SZO działają bez zmian).');
        } elseif ($zc_pass !== '' && mb_strlen($zc_pass) < 8) {
            flash_set('danger', 'Hasło musi mieć co najmniej 8 znaków (albo zostaw puste — wygenerujemy).');
        } else {
            if ($zc_pass === '') {
                $zc_pass = 'Ti-' . bin2hex(random_bytes(4)) . '-' . random_int(10, 99);
            }
            db_insert('users', [
                'name'       => $zc_name,
                'email'      => $zc_email,
                'password'   => password_hash($zc_pass, PASSWORD_BCRYPT),
                'role'       => 'dydaktyk_ti',
                'is_active'  => 1,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            $zc_id = (int)db()->lastInsertId();
            ti_panel_role_set($zc_id, $zc_role, $uid);
            $_SESSION['zespol_newpass'] = ['email' => $zc_email, 'pass' => $zc_pass];
            flash_set('success', 'Utworzono konto panelowe: ' . $zc_name . ' (' . K30_TI_PANEL_ROLES[$zc_role]
                . '). Loguje się e-mailem i hasłem na stronie logowania panelu dydaktyka — do systemu SZO to konto nie wejdzie.');
        }
        header('Location: zespol.php'); exit;
    }

    // Nowe hasło dla konta panelowego (dydaktyk_ti) — pokazywane jednorazowo
    if ($op === 'dyd_reset_pass') {
        $zu_id  = (int)($_POST['user_id'] ?? 0);
        $zu_row = $zu_id ? db_one("SELECT id, email, role FROM users WHERE id=? AND role='dydaktyk_ti'", [$zu_id]) : null;
        if (!$zu_row) {
            flash_set('danger', 'Hasło można zresetować tylko kontu panelowemu (konta SZO zmieniają hasło w systemie).');
        } else {
            $zc_pass = 'Ti-' . bin2hex(random_bytes(4)) . '-' . random_int(10, 99);
            db()->prepare("UPDATE users SET password=? WHERE id=?")->execute([password_hash($zc_pass, PASSWORD_BCRYPT), $zu_id]);
            $_SESSION['zespol_newpass'] = ['email' => (string)$zu_row['email'], 'pass' => $zc_pass];
            flash_set('success', 'Ustawiono nowe hasło konta panelowego.');
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

<?php $zu_newpass = $_SESSION['zespol_newpass'] ?? null; unset($_SESSION['zespol_newpass']); ?>
<?php if ($zu_newpass): ?>
<div class="alert alert-warning d-flex align-items-start gap-2" role="alert">
  <i class="bi bi-key-fill flex-shrink-0" aria-hidden="true"></i>
  <div>
    <strong>Dane logowania do panelu</strong> — zapisz i przekaż, hasło pokazujemy tylko raz:<br>
    e-mail: <code><?= h($zu_newpass['email']) ?></code> · hasło: <code><?= h($zu_newpass['pass']) ?></code><br>
    <span class="small">Logowanie: <?= h(rtrim(APP_URL, '/')) ?>/karty30/ti/dydaktyk/logowanie.php
      (samodzielna strona tylko dla dydaktyków; działa też wspólna strona logowania).</span>
  </div>
</div>
<?php endif; ?>

<div class="alert alert-light border small d-flex gap-2" role="note">
  <i class="bi bi-info-circle text-primary flex-shrink-0" aria-hidden="true"></i>
  <span><strong>System hybrydowy:</strong> dydaktyka tworzysz wprost tutaj (konto panelu TI —
  loguje się tylko do panelu, do systemu SZO nie wejdzie) <em>albo</em> nadajesz rolę osobie,
  która <strong>ma już konto SZO</strong> — ta loguje się jak dotąd swoim e-mailem i hasłem SZO.
  <strong>Kierownik</strong> i <strong>Zastępca kierownika</strong> mają pełne funkcje kierownika
  (grupy, rozliczenia, raporty…), <strong>Prowadzący TI</strong> widzi własne kursy. Rolami panelu
  zarządza się wyłącznie tutaj; role systemu głównego (admin, uprawnienia modułu Dydaktyka 3)
  działają jak dotąd.</span>
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
                <?php if (($zr['szo_role'] ?? '') === 'dydaktyk_ti'): ?>
                <span class="badge text-bg-light border ms-1" style="font-size:.62rem" title="Konto utworzone w panelu TI — nie loguje się do systemu SZO">konto panelu TI</span>
                <?php else: ?>
                <span class="badge text-bg-light border ms-1" style="font-size:.62rem" title="Pełne konto systemu SZO — loguje się do panelu danymi SZO">konto SZO</span>
                <?php endif; ?>
                <?php if (empty($zr['is_active'])): ?>
                <span class="badge text-bg-secondary ms-1" style="font-size:.62rem">nieaktywne</span>
                <?php endif; ?>
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
              <td class="text-end text-nowrap">
                <?php if (($zr['szo_role'] ?? '') === 'dydaktyk_ti'): ?>
                <form method="post" class="d-inline"
                      onsubmit="return confirm('Ustawić nowe hasło konta panelowego: <?= h(addslashes($zr['name'])) ?>? Stare przestanie działać.')">
                  <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                  <input type="hidden" name="_op" value="dyd_reset_pass">
                  <input type="hidden" name="user_id" value="<?= (int)$zr['user_id'] ?>">
                  <button type="submit" class="btn btn-sm btn-outline-secondary py-0 px-2" title="Nowe hasło (pokażemy raz)">
                    <i class="bi bi-key" aria-hidden="true"></i><span class="visually-hidden">Nowe hasło</span>
                  </button>
                </form>
                <?php endif; ?>
                <form method="post" class="d-inline"
                      onsubmit="return confirm('Odebrać rolę panelu: <?= h(addslashes($zr['name'])) ?>?<?=
                        ($zr['szo_role'] ?? '') === 'dydaktyk_ti' ? '\n\nKonto panelowe zostanie też dezaktywowane.' : '' ?>')">
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

    <div class="card mt-4">
      <div class="card-header fw-semibold bg-white">
        <i class="bi bi-person-plus-fill text-primary me-1" aria-hidden="true"></i>Nowy dydaktyk — konto panelu TI
      </div>
      <div class="card-body">
        <p class="small text-body-secondary">Dla osoby <strong>bez konta SZO</strong>: konto działa
        wyłącznie w panelu dydaktyka (do systemu SZO się nie zaloguje). Osobie z kontem SZO
        nadaj rolę w formularzu wyżej.</p>
        <form method="post" class="vstack gap-3">
          <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="_op" value="dyd_create">
          <div>
            <label class="form-label small fw-semibold mb-1" for="zc-name">Imię i nazwisko</label>
            <input type="text" id="zc-name" name="name" class="form-control form-control-sm" required maxlength="140">
          </div>
          <div>
            <label class="form-label small fw-semibold mb-1" for="zc-email">E-mail (login do panelu)</label>
            <input type="email" id="zc-email" name="email" class="form-control form-control-sm" required maxlength="190">
          </div>
          <div>
            <label class="form-label small fw-semibold mb-1" for="zc-pass">Hasło <span class="text-body-secondary fw-normal">(puste = wygenerujemy i pokażemy raz)</span></label>
            <input type="text" id="zc-pass" name="password" class="form-control form-control-sm" minlength="8" maxlength="72" autocomplete="new-password">
          </div>
          <fieldset>
            <legend class="form-label small fw-semibold fs-6 mb-1">Rola panelu</legend>
            <?php foreach (K30_TI_PANEL_ROLES as $zk => $zl): ?>
            <div class="form-check">
              <input class="form-check-input" type="radio" name="role" id="zc-role-<?= h($zk) ?>"
                     value="<?= h($zk) ?>" <?= $zk === 'prowadzacy' ? 'checked' : '' ?>>
              <label class="form-check-label" for="zc-role-<?= h($zk) ?>"><?= h($zl) ?></label>
            </div>
            <?php endforeach; ?>
          </fieldset>
          <div>
            <button type="submit" class="btn btn-primary btn-sm">
              <i class="bi bi-person-plus me-1" aria-hidden="true"></i>Utwórz konto panelowe
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
