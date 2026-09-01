<?php
/**
 * karty30/ti/dydaktyk/choose_context.php — wybór roli kierownika-prowadzącego.
 *
 * Kierownik, który jest TEŻ prowadzącym (własne kursy), wybiera raz na sesję:
 * "Kierownik Instytucji" (pełny widok, jak dziś) albo "Prowadzący" (widzi
 * TYLKO swoje kursy — zakładki Kierownika znikają, jak zwykłemu prowadzącemu).
 * Wybór żyje w sesji ($_SESSION['k30_dyd_ctx']['role']) — patrz dyd_is_staff()
 * w auth.php — zmienny linkiem „Zmień rolę" bez ponownego logowania.
 *
 * UWAGA: NIE woła dyd_require() — ten ekran jest właśnie tym, co dyd_require()
 * przekierowuje, więc wywołanie go tutaj zapętliłoby przekierowania.
 */
require_once __DIR__ . '/auth.php';

$s = dyd_current();
if (!$s) { header('Location: login.php'); exit; }

$own_courses = k30_ti_instructor_courses((int)$s['user_id'], false);

// Nic do wyboru (nie jest kierownikiem, albo nie ma własnych kursów) — nie ma po co tu być.
if (empty($s['is_staff']) || !$own_courses) {
    if (!empty($s['is_staff'])) $_SESSION['k30_dyd_ctx']['role'] = 'staff';
    header('Location: index.php'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    dyd_token_check();
    $role = ($_POST['role'] ?? '') === 'instructor' ? 'instructor' : 'staff';
    $_SESSION['k30_dyd_ctx']['role'] = $role;
    header('Location: index.php'); exit;
}

$KP_TITLE  = 'Wybierz rolę';
$KP_TOPBAR = ['brand' => 'Panel dydaktyka', 'icon' => 'easel2', 'user' => (string)($s['name'] ?? ''), 'logout' => 'logout.php'];
$KP_BODY_CLASS = '';
include dirname(__DIR__) . '/kursant/_layout_head.php';
?>

<main id="main" class="kp-auth-wrap mx-auto my-5">
  <div class="card kp-auth-card shadow-lg border-0">
    <div class="card-body p-4 p-lg-5">
      <h1 class="h4 fw-bold mb-2"><i class="bi bi-person-gear text-primary me-2" aria-hidden="true"></i>Pracuję jako</h1>
      <p class="text-body-secondary mb-4">
        Masz uprawnienia kierownika i jednocześnie prowadzisz własne zajęcia. Wybierz, w jakiej
        roli chcesz teraz pracować — możesz to zmienić w każdej chwili linkiem „Zmień rolę".
      </p>
      <form method="post" class="d-flex flex-column gap-2">
        <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
        <button type="submit" name="role" value="staff" class="btn btn-outline-primary btn-lg text-start d-flex align-items-center gap-2">
          <i class="bi bi-building fs-5" aria-hidden="true"></i>
          <span>
            <span class="d-block fw-semibold">Kierownik Instytucji</span>
            <span class="d-block small text-body-secondary">Pełny widok — wszystkie grupy, rozliczenia, zapisy, ustawienia.</span>
          </span>
        </button>
        <button type="submit" name="role" value="instructor" class="btn btn-outline-primary btn-lg text-start d-flex align-items-center gap-2">
          <i class="bi bi-mortarboard fs-5" aria-hidden="true"></i>
          <span>
            <span class="d-block fw-semibold">Prowadzący</span>
            <span class="d-block small text-body-secondary">
              Tylko Twoje własne kursy (<?= count($own_courses) ?>) — jak zwykły prowadzący, bez zakładek Kierownika.
            </span>
          </span>
        </button>
      </form>
    </div>
  </div>
</main>

<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
