<?php
/**
 * karty30/ti/dydaktyk/choose_mode.php — wybór trybu pracy w panelu: Kursy albo Konsultacje.
 *
 * Pokazywany po zalogowaniu (dyd_require), gdy dostępne są oba tryby (dyd_modes_available): kierownik albo prowadzący,
 * który jest też doradcą TI. Wybór żyje w sesji ($_SESSION['k30_dyd_ctx']['mode']) i zmienia się linkiem
 * „Zmień tryb pracy” w menu użytkownika. Od trybu zależy górne menu (dyd_nav_sections) i ekran startowy:
 * Kursy → Mój panel, Konsultacje → Karty konsultacji.
 *
 * UWAGA: NIE woła dyd_require() — to ten ekran jest celem przekierowania z dyd_require() (pętla).
 */
require_once __DIR__ . '/auth.php';

$s = dyd_current();
if (!$s) { header('Location: login.php'); exit; }
$modes = dyd_modes_available($s);

if ($_SERVER['REQUEST_METHOD'] === 'GET' && in_array($_GET['mode'] ?? '', ['kursy', 'konsultacje'], true)) {
    $want = (string)$_GET['mode'];
    if (in_array($want, $modes, true)) $_SESSION['k30_dyd_ctx']['mode'] = $want;
    header('Location: ' . (dyd_mode() === 'konsultacje' ? 'konsultacje.php' : 'index.php')); exit;
}
if (count($modes) < 2) {   // nie ma czego wybierać
    $_SESSION['k30_dyd_ctx']['mode'] = $modes[0] ?? 'kursy';
    header('Location: ' . (dyd_mode() === 'konsultacje' ? 'konsultacje.php' : 'index.php')); exit;
}
$cur = $_SESSION['k30_dyd_ctx']['mode'] ?? '';

require_once dirname(dirname(dirname(__DIR__))) . '/includes/auth_screen.php';
ob_start(); ?>
<nav class="ks-tabs" aria-label="Wybór trybu pracy">
  <a class="ks-tab" href="choose_mode.php?mode=kursy"<?= $cur === 'kursy' ? ' aria-current="page"' : '' ?>><i class="bi bi-mortarboard me-1" aria-hidden="true"></i>Kursy</a>
  <a class="ks-tab" href="choose_mode.php?mode=konsultacje"<?= $cur === 'konsultacje' ? ' aria-current="page"' : '' ?>><i class="bi bi-clipboard2-check me-1" aria-hidden="true"></i>Konsultacje</a>
</nav>
<?php $_tabs_html = ob_get_clean();

auth_screen_head([
    'title'      => 'Tryb pracy',
    'actions'    => false,
    'side_title' => 'Panel dydaktyka',
    'side_sub'   => 'Wybierz, czym się teraz zajmujesz. Menu dopasuje się do trybu — zmienisz go w każdej chwili.',
    'tabs_html'  => $_tabs_html,
    'width'      => 560,
    'main_id'    => 'choose-mode-main',
]);
?>
<h1 class="ks-h1">W czym teraz pracujesz?</h1>
<p class="ks-lead">Masz dostęp do kursów i do konsultacji. Wybierz tryb — tryb zmienisz w menu użytkownika („Zmień tryb pracy”).</p>
<?= flash_html() ?>

<a href="choose_mode.php?mode=kursy" class="ks-btn ks-btn--ghost mb-2" style="justify-content:flex-start;text-align:left;height:auto;padding:.85rem 1.1rem">
  <i class="bi bi-mortarboard me-2" aria-hidden="true"></i>
  <span>
    <span class="d-block fw-semibold"><?= $cur === 'kursy' ? '✓ ' : '' ?>Kursy</span>
    <span class="d-block" style="font-size:.8rem;color:var(--ks-muted)">Grupy i lekcje, obecność, dziennik, protokoły, szkolenia krótkie SzK<?= !empty($s['is_staff']) ? ', funkcje kierownika' : '' ?>.</span>
  </span>
</a>
<a href="choose_mode.php?mode=konsultacje" class="ks-btn ks-btn--ghost" style="justify-content:flex-start;text-align:left;height:auto;padding:.85rem 1.1rem">
  <i class="bi bi-clipboard2-check me-2" aria-hidden="true"></i>
  <span>
    <span class="d-block fw-semibold"><?= $cur === 'konsultacje' ? '✓ ' : '' ?>Konsultacje</span>
    <span class="d-block" style="font-size:.8rem;color:var(--ks-muted)">Karty konsultacji KON.XXX.MM.RRRR: wystawianie, zatwierdzanie kodem IKA i certyfikatem, wydruk.</span>
  </span>
</a>
<?php
auth_screen_foot([
    'links' => [
        ['url' => 'logout.php', 'label' => 'Wyloguj', 'icon' => 'bi-box-arrow-right'],
    ],
]);
