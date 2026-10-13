<?php
/**
 * karty30/ti/dydaktyk/choose_mode.php — wspólny ekran wyboru po zalogowaniu: ROLA i TRYB PRACY.
 *
 *   • Rola (tylko kierownik, który ma też własne grupy): Kierownik Instytucji albo Prowadzący;
 *   • Tryb (gdy osoba ma oba — TiWorkModes, ustawiane w „Zespół i role”): Szkolenia albo Konsultacje.
 * Pokazuje tylko te grupy wyborów, które dotyczą zalogowanej osoby; wybór żyje w sesji ($_SESSION['k30_dyd_ctx'])
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

require_once dirname(dirname(dirname(__DIR__))) . '/includes/auth_screen.php';
auth_screen_head([
    'title'      => 'Jak pracujesz',
    'actions'    => false,
    'side_title' => 'Panel dydaktyka',
    'side_sub'   => 'Wybierz rolę i tryb pracy. Menu dopasuje się do wyboru — zmienisz go w każdej chwili w menu użytkownika.',
    'width'      => 600,
    'main_id'    => 'choose-mode-main',
]);
$opt = function (string $name, string $value, bool $checked, string $icon, string $title, string $desc): void { ?>
  <label class="ks-btn ks-btn--ghost mb-2" style="justify-content:flex-start;text-align:left;height:auto;padding:.8rem 1rem;cursor:pointer">
    <input type="radio" name="<?= h($name) ?>" value="<?= h($value) ?>" class="form-check-input me-2 mt-0 flex-shrink-0"<?= $checked ? ' checked' : '' ?> required>
    <i class="bi bi-<?= h($icon) ?> me-2" aria-hidden="true"></i>
    <span><span class="d-block fw-semibold"><?= h($title) ?></span><span class="d-block" style="font-size:.8rem;color:var(--ks-muted)"><?= h($desc) ?></span></span>
  </label>
<?php };
?>
<h1 class="ks-h1">Jak teraz pracujesz?</h1>
<?= flash_html() ?>
<form method="post">
  <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
  <?php if ($askRole): ?>
  <fieldset class="mb-3">
    <legend class="fw-bold" style="font-size:1rem">Rola</legend>
    <?php $opt('role', 'staff', $curRole !== 'instructor', 'building', 'Kierownik Instytucji', 'Pełny widok — wszystkie grupy, rozliczenia, zapisy, ustawienia.'); ?>
    <?php $opt('role', 'instructor', $curRole === 'instructor' && empty($s['acting_as_other']), 'mortarboard', 'Prowadzący', 'Tylko Twoje własne grupy (' . $ownCount . ') — bez funkcji kierownika.'); ?>
  </fieldset>
  <?php endif; ?>
  <?php if ($askMode): ?>
  <fieldset class="mb-3">
    <legend class="fw-bold" style="font-size:1rem">Tryb pracy</legend>
    <?php $opt('mode', 'kursy', $curMode !== 'konsultacje', 'easel2', 'Szkolenia', 'Grupy i lekcje, obecność, dziennik, protokoły, szkolenia krótkie SzK.'); ?>
    <?php $opt('mode', 'konsultacje', $curMode === 'konsultacje', 'clipboard2-check', 'Konsultacje', 'Rezerwacje terminów i karty konsultacji KON.XXX.MM.RRRR — wystawianie, zatwierdzanie, wydruk.'); ?>
  </fieldset>
  <?php endif; ?>
  <button type="submit" class="ks-btn ks-btn--primary"><i class="bi bi-box-arrow-in-right me-1" aria-hidden="true"></i>Przejdź do panelu</button>
</form>
<?php if (!empty($s['is_staff'])): ?>
<p class="mt-3 mb-0" style="font-size:.85rem"><a href="choose_context.php">Wejdź jako inny prowadzący…</a></p>
<?php endif;
auth_screen_foot([
    'links' => [
        ['url' => 'logout.php', 'label' => 'Wyloguj', 'icon' => 'bi-box-arrow-right'],
    ],
]);
