<?php
/**
 * karty30/ti/dydaktyk/choose_context.php — wybór roli kierownika-prowadzącego.
 *
 * Kierownik, który jest TEŻ prowadzącym (własne kursy), wybiera raz na sesję:
 * "Kierownik Instytucji" (pełny widok, jak dziś) albo "Prowadzący" (widzi
 * TYLKO swoje kursy — zakładki Kierownika znikają, jak zwykłemu prowadzącemu),
 * albo pełne wejście na konto DOWOLNEGO prowadzącego (wymaga uzasadnienia,
 * logowane w k30_ti_instructor_switch_log). Wybór żyje w sesji
 * ($_SESSION['k30_dyd_ctx']) — patrz dyd_is_staff()/dyd_require() w auth.php —
 * zmienny linkiem „Zmień rolę" bez ponownego logowania.
 *
 * Wygląd współdzielony z resztą logowania SZO (includes/auth_screen.php) —
 * ten sam szablon co karty30/ti/login.php, tylko zamiast zakładek
 * Zaloguj/Rejestracja na górze jest wybór roli.
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

// Przełączenie na siebie (kierownik/prowadzący) — zwykły link GET, tak jak
// zakładki Zaloguj/Rejestracja w SZO i dawny przełącznik wyglądu logowania TI.
// Bez formularza/przycisku submit — eliminuje wszelkie wątpliwości co do
// klikalności (zgłoszenie: przyciski w <form> czasem nie reagowały).
if ($_SERVER['REQUEST_METHOD'] === 'GET' && in_array($_GET['role'] ?? '', ['staff', 'instructor'], true)) {
    $_SESSION['k30_dyd_ctx']['role'] = $_GET['role'];
    unset($_SESSION['k30_dyd_ctx']['as_instructor_id']); // powrót do siebie — koniec wcielenia
    header('Location: index.php'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    dyd_token_check();
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

$all_instructors  = array_values(array_filter(k30_ti_instructors(), fn($i) => (int)$i['id'] !== (int)$s['user_id']));
$cur_role         = dyd_ctx_role();

require_once dirname(dirname(dirname(__DIR__))) . '/includes/auth_screen.php';

ob_start();
?>
<nav class="ks-tabs" aria-label="Wybór roli">
  <a class="ks-tab" href="choose_context.php?role=staff"
     <?= $cur_role !== 'instructor' ? 'aria-current="page"' : '' ?>>
    <i class="bi bi-building me-1" aria-hidden="true"></i>Kierownik Instytucji
  </a>
  <a class="ks-tab" href="choose_context.php?role=instructor"
     <?= ($cur_role === 'instructor' && empty($s['acting_as_other'])) ? 'aria-current="page"' : '' ?>>
    <i class="bi bi-mortarboard me-1" aria-hidden="true"></i>Prowadzący
  </a>
</nav>
<?php
$_tabs_html = ob_get_clean();

auth_screen_head([
    'title'     => 'Wybierz rolę',
    'tabs_html' => $_tabs_html,
    'width'     => 560,
    'main_id'   => 'choose-ctx-main',
]);
?>

<h1 class="ks-h1">Pracuję jako</h1>
<p class="ks-lead">
  Masz uprawnienia kierownika i jednocześnie prowadzisz własne zajęcia. Wybierz rolę poniżej —
  możesz to zmienić w każdej chwili linkiem „Zmień rolę".
</p>

<?= flash_html() ?>

<a href="choose_context.php?role=staff" class="ks-btn ks-btn--ghost mb-2" style="justify-content:flex-start;text-align:left;height:auto;padding:.85rem 1.1rem">
  <i class="bi bi-building me-2" aria-hidden="true"></i>
  <span>
    <span class="d-block fw-semibold"><?= $cur_role !== 'instructor' ? '✓ ' : '' ?>Kierownik Instytucji</span>
    <span class="d-block" style="font-size:.8rem;color:var(--ks-muted)">Pełny widok — wszystkie grupy, rozliczenia, zapisy, ustawienia.</span>
  </span>
</a>
<a href="choose_context.php?role=instructor" class="ks-btn ks-btn--ghost" style="justify-content:flex-start;text-align:left;height:auto;padding:.85rem 1.1rem">
  <i class="bi bi-mortarboard me-2" aria-hidden="true"></i>
  <span>
    <span class="d-block fw-semibold"><?= ($cur_role === 'instructor' && empty($s['acting_as_other'])) ? '✓ ' : '' ?>Prowadzący</span>
    <span class="d-block" style="font-size:.8rem;color:var(--ks-muted)">Tylko Twoje własne kursy (<?= count($own_courses) ?>) — jak zwykły prowadzący, bez zakładek Kierownika.</span>
  </span>
</a>

<?php if ($all_instructors): ?>
<hr class="ks-sep">
<h2 class="ks-h1" style="font-size:1.15rem"><i class="bi bi-person-video2 me-2" aria-hidden="true"></i>Wejdź jako inny prowadzący</h2>
<p class="ks-lead" style="margin-bottom:1.25rem">
  Zobaczysz panel dokładnie tak, jak widzi go wybrany prowadzący — zapisywane akcje (obecność, edycje)
  trafiają na jego konto. Wymaga uzasadnienia — przełączenie jest logowane.
</p>
<form method="post">
  <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
  <input type="hidden" name="role" value="other_instructor">
  <div class="ks-field">
    <label for="oi_instr">Prowadzący</label>
    <select class="form-control" id="oi_instr" name="instructor_id" required>
      <option value="">— wybierz —</option>
      <?php foreach ($all_instructors as $ins): ?>
      <option value="<?= (int)$ins['id'] ?>"><?= h($ins['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="ks-field">
    <label for="oi_reason">Uzasadnienie</label>
    <textarea class="form-control" id="oi_reason" name="reason" rows="2" required
              placeholder="np. weryfikacja zgłoszenia — prowadzący nie widzi swojej lekcji"></textarea>
  </div>
  <button type="submit" class="ks-btn ks-btn--primary">
    <i class="bi bi-box-arrow-in-right me-1" aria-hidden="true"></i>Przełącz się
  </button>
</form>
<?php endif; ?>

<?php
auth_screen_foot([
    'links' => [
        ['url' => 'index.php', 'label' => 'Panel dydaktyka', 'icon' => 'bi-easel2'],
        ['url' => 'logout.php', 'label' => 'Wyloguj',         'icon' => 'bi-box-arrow-right'],
    ],
]);
