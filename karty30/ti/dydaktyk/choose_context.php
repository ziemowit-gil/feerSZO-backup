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
    $role = (string)($_POST['role'] ?? '');

    if ($role === 'other_instructor') {
        $instr_id = (int)($_POST['instructor_id'] ?? 0);
        $reason   = trim((string)($_POST['reason'] ?? ''));
        $target   = $instr_id ? db_one("SELECT id, name FROM users WHERE id=? AND is_active=1", [$instr_id]) : null;
        if (!$target || $reason === '') {
            flash_set('danger', 'Wybierz prowadzącego i podaj uzasadnienie przełączenia.');
            header('Location: choose_context.php'); exit;
        }
        $_SESSION['k30_dyd_ctx']['role']            = 'instructor';
        $_SESSION['k30_dyd_ctx']['as_instructor_id'] = (int)$target['id'];
        dyd_instructor_switch_log((int)$s['user_id'], (int)$target['id'], $reason);
        header('Location: index.php'); exit;
    }

    $role = $role === 'instructor' ? 'instructor' : 'staff';
    $_SESSION['k30_dyd_ctx']['role'] = $role;
    unset($_SESSION['k30_dyd_ctx']['as_instructor_id']); // powrót do siebie — koniec wcielenia
    header('Location: index.php'); exit;
}

$all_instructors = k30_ti_instructors();

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
      <?= flash_html() ?>
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

      <?php if ($all_instructors): ?>
      <hr class="my-4">
      <h2 class="h6 fw-bold mb-2"><i class="bi bi-person-video2 text-primary me-2" aria-hidden="true"></i>Wejdź jako inny prowadzący</h2>
      <p class="text-body-secondary small mb-3">
        Zobaczysz panel dokładnie tak, jak widzi go wybrany prowadzący — zapisywane akcje (obecność,
        edycje) trafiają na jego konto. Wymaga uzasadnienia — przełączenie jest logowane.
      </p>
      <form method="post" class="d-flex flex-column gap-2">
        <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
        <input type="hidden" name="role" value="other_instructor">
        <div>
          <label class="form-label small fw-semibold mb-1" for="oi_instr">Prowadzący</label>
          <select class="form-select" id="oi_instr" name="instructor_id" required>
            <option value="">— wybierz —</option>
            <?php foreach ($all_instructors as $ins): if ((int)$ins['id'] === (int)$s['user_id']) continue; ?>
            <option value="<?= (int)$ins['id'] ?>"><?= h($ins['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="form-label small fw-semibold mb-1" for="oi_reason">Uzasadnienie <span class="text-danger">*</span></label>
          <textarea class="form-control" id="oi_reason" name="reason" rows="2" required
                    placeholder="np. weryfikacja zgłoszenia — prowadzący nie widzi swojej lekcji"></textarea>
        </div>
        <button type="submit" class="btn btn-outline-primary btn-lg text-start d-flex align-items-center gap-2">
          <i class="bi bi-box-arrow-in-right fs-5" aria-hidden="true"></i>
          <span>
            <span class="d-block fw-semibold">Przełącz się</span>
            <span class="d-block small text-body-secondary">Pełne wejście na konto wybranego prowadzącego, w Twoim zastępstwie.</span>
          </span>
        </button>
      </form>
      <?php endif; ?>
    </div>
  </div>
</main>

<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
