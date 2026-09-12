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

$all_instructors  = array_values(array_filter(k30_ti_instructors(), fn($i) => (int)$i['id'] !== (int)$s['user_id']));
$cur_role         = dyd_ctx_role();

require_once dirname(dirname(dirname(__DIR__))) . '/includes/auth_screen.php';

ob_start();
?>
<form method="post" style="display:contents">
  <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
  <nav class="ks-tabs" aria-label="Wybór roli">
    <button type="submit" name="role" value="staff" class="ks-tab" style="border:0;background:none;cursor:pointer"
            <?= $cur_role !== 'instructor' ? 'aria-current="page"' : '' ?>>
      <i class="bi bi-building me-1" aria-hidden="true"></i>Kierownik Instytucji
    </button>
    <button type="submit" name="role" value="instructor" class="ks-tab" style="border:0;background:none;cursor:pointer"
            <?= ($cur_role === 'instructor' && empty($s['acting_as_other'])) ? 'aria-current="page"' : '' ?>>
      <i class="bi bi-mortarboard me-1" aria-hidden="true"></i>Prowadzący
    </button>
  </nav>
</form>
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
  Masz uprawnienia kierownika i jednocześnie prowadzisz własne zajęcia. Wybierz rolę zakładką powyżej —
  możesz to zmienić w każdej chwili linkiem „Zmień rolę".
</p>

<?= flash_html() ?>

<div class="ks-field">
  <label>Kierownik Instytucji</label>
  <p class="ks-fieldhint mt-0">Pełny widok — wszystkie grupy, rozliczenia, zapisy, ustawienia.</p>
</div>
<div class="ks-field">
  <label>Prowadzący</label>
  <p class="ks-fieldhint mt-0">Tylko Twoje własne kursy (<?= count($own_courses) ?>) — jak zwykły prowadzący, bez zakładek Kierownika.</p>
</div>

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
