<?php
/**
 * karty30/ti/dydaktyk/choose_mode.php — wspólny ekran wyboru po zalogowaniu: ROLA i TRYB PRACY.
 *
 *   • Rola (tylko kierownik, który ma też własne grupy): Kierownik Instytucji albo Prowadzący;
 *   • Tryb (gdy osoba ma oba — TiWorkModes, ustawiane w „Zespół i role”): Szkolenia albo Konsultacje.
 * Strona w samym panelu (pasek górny, skórka USOS). Pokazuje tylko te grupy wyborów, które dotyczą zalogowanej osoby; wybór żyje w sesji ($_SESSION['k30_dyd_ctx'])
 * i zmienia się w menu użytkownika („Zmień rolę / tryb pracy”). Wejście na konto innego prowadzącego — choose_context.php.
 *
 * UWAGA: NIE woła dyd_require() — to ten ekran jest celem przekierowania z dyd_require() (pętla).
 */
require_once __DIR__ . '/auth.php';

$s = dyd_current();
if (!$s) { header('Location: login.php'); exit; }
$modes    = dyd_modes_available($s);
$ownCount = !empty($s['is_staff']) ? count(k30_ti_instructor_courses((int)$s['user_id'], false)) : 0;
$askRole  = $ownCount > 0;
$askMode  = count($modes) > 1;

$apply = function (?string $role, ?string $mode) use ($modes, $askRole): void {
    if ($askRole && in_array($role, ['staff', 'instructor'], true)) {
        $_SESSION['k30_dyd_ctx']['role'] = $role;
        unset($_SESSION['k30_dyd_ctx']['as_instructor_id']);   // powrót do siebie — koniec wcielenia
    }
    if ($mode !== null && in_array($mode, $modes, true)) $_SESSION['k30_dyd_ctx']['mode'] = $mode;
};
$go = fn() => header('Location: ' . (dyd_mode() === 'konsultacje' ? 'konsultacje.php' : 'index.php'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    dyd_token_check();
    $apply($_POST['role'] ?? null, $_POST['mode'] ?? null);
    $go(); exit;
}
if (isset($_GET['role']) || isset($_GET['mode'])) {   // stare linki ?role= / ?mode=
    $apply($_GET['role'] ?? null, $_GET['mode'] ?? null);
    $go(); exit;
}
if (!$askRole && !$askMode) {   // nic do wyboru
    $_SESSION['k30_dyd_ctx']['mode'] = $modes[0] ?? 'kursy';
    if (!empty($s['is_staff'])) $_SESSION['k30_dyd_ctx']['role'] = 'staff';
    $go(); exit;
}
$curRole = (string)($_SESSION['k30_dyd_ctx']['role'] ?? 'staff');
$curMode = (string)($_SESSION['k30_dyd_ctx']['mode'] ?? ($modes[0] ?? 'kursy'));

// Ekran w samym panelu (pasek górny, skórka USOS) — nie na ekranie logowania
$KP_TITLE  = 'Jak pracujesz — Panel dydaktyka';
$KP_TOPBAR = ['brand' => 'Panel dydaktyka', 'icon' => 'easel2', 'user' => (string)($s['name'] ?? ''), 'logout' => 'logout.php'];
$KP_BODY_CLASS = 'dyd-usos ti-skin';
include dirname(__DIR__) . '/kursant/_layout_head.php';
$_skin_css = __DIR__ . '/../assets/ti_skin.css';
$opt = function (string $name, string $value, bool $checked, string $icon, string $title, string $desc): void { ?>
      <label class="list-group-item list-group-item-action d-flex gap-3 align-items-start py-3" style="cursor:pointer">
        <input class="form-check-input flex-shrink-0 mt-1" type="radio" name="<?= h($name) ?>" value="<?= h($value) ?>"<?= $checked ? ' checked' : '' ?> required>
        <i class="bi bi-<?= h($icon) ?> fs-4 text-primary flex-shrink-0" aria-hidden="true"></i>
        <span><span class="d-block fw-bold"><?= h($title) ?></span><span class="d-block small text-body-secondary"><?= h($desc) ?></span></span>
      </label>
<?php };
?>
<link rel="stylesheet" href="../assets/ti_skin.css?v=<?= is_file($_skin_css) ? (int)filemtime($_skin_css) : 1 ?>">
<main id="main" class="dyd-wrap">
<div class="container-fluid py-4" style="max-width:860px">
  <h1 class="h4 fw-bold mb-1"><i class="bi bi-signpost-split text-primary me-2" aria-hidden="true"></i>Jak teraz pracujesz?</h1>
  <p class="text-body-secondary small mb-3">Wybór dotyczy tej sesji — menu panelu dopasuje się do niego. Zmienisz go w każdej chwili: menu użytkownika → „Zmień rolę / tryb pracy”.</p>
  <?= flash_html() ?>
  <form method="post">
    <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
    <div class="row g-3">
      <?php if ($askRole): ?>
      <div class="col-md-6">
        <fieldset class="card border-0 shadow-sm h-100">
          <legend class="card-header fw-semibold fs-6 mb-0 w-100">Rola</legend>
          <div class="list-group list-group-flush">
            <?php $opt('role', 'staff', $curRole !== 'instructor', 'building', 'Kierownik Instytucji', 'Pełny widok — wszystkie grupy, rozliczenia, zapisy, ustawienia.'); ?>
            <?php $opt('role', 'instructor', $curRole === 'instructor' && empty($s['acting_as_other']), 'mortarboard', 'Prowadzący', 'Tylko Twoje własne grupy (' . $ownCount . ') — bez funkcji kierownika.'); ?>
          </div>
        </fieldset>
      </div>
      <?php endif; ?>
      <?php if ($askMode): ?>
      <div class="col-md-6">
        <fieldset class="card border-0 shadow-sm h-100">
          <legend class="card-header fw-semibold fs-6 mb-0 w-100">Tryb pracy</legend>
          <div class="list-group list-group-flush">
            <?php $opt('mode', 'kursy', $curMode !== 'konsultacje', 'easel2', 'Szkolenia', 'Grupy i lekcje, obecność, dziennik, protokoły, szkolenia krótkie SzK.'); ?>
            <?php $opt('mode', 'konsultacje', $curMode === 'konsultacje', 'clipboard2-check', 'Konsultacje', 'Rezerwacje terminów i karty konsultacji KON.XXX.MM.RRRR.'); ?>
          </div>
        </fieldset>
      </div>
      <?php endif; ?>
    </div>
    <div class="d-flex flex-wrap align-items-center gap-3 mt-3">
      <button type="submit" class="btn btn-primary"><i class="bi bi-box-arrow-in-right me-1" aria-hidden="true"></i>Przejdź do panelu</button>
      <?php if (!empty($s['is_staff'])): ?><a class="small" href="choose_context.php">Wejdź jako inny prowadzący…</a><?php endif; ?>
    </div>
  </form>
</div>
</main>
<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
