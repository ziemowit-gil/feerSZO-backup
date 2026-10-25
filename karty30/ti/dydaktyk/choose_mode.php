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

$apply = function (?string $role, ?string $mode) use ($modes, $askRole, $s): void {
    if ($askRole && in_array($role, ['staff', 'instructor'], true)) {
        $_SESSION['k30_dyd_ctx']['role'] = $role;
        unset($_SESSION['k30_dyd_ctx']['as_instructor_id']);   // powrót do siebie — koniec wcielenia
        dyd_pref_set((int)$s['user_id'], 'dyd_ctx_role', $role);   // przywracane po cichym wznowieniu sesji (auth.php)
    }
    if ($mode !== null && in_array($mode, $modes, true)) {
        $_SESSION['k30_dyd_ctx']['mode'] = $mode;
        dyd_pref_set((int)$s['user_id'], 'dyd_ctx_mode', $mode);
    }
    unset($_SESSION['k30_dyd_resumed']);
};
// Grupa, na której pracujesz (tryb Szkolenia): kierownik — wszystkie aktywne, prowadzący — własne; zapamiętywana jak wybór w panelu
$groups = !empty($s['is_staff']) ? k30_ti_courses(true) : k30_ti_instructor_courses((int)$s['user_id'], true);
$groupIds = array_map(fn($c) => (int)$c['id'], $groups);
$prefGroup = function_exists('dyd_pref') ? (int)dyd_pref((int)$s['user_id'], 'dyd_last_course') : 0;
$go = function () use ($groupIds) {
    $g = (int)($_POST['course'] ?? 0);
    if (dyd_mode() === 'konsultacje') header('Location: konsultacjeStart.php');
    elseif ($g && in_array($g, $groupIds, true)) header('Location: index.php?course=' . $g . '&tab=pulpit');   // index.php zapamiętuje wybór
    else header('Location: index.php');
};

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
// Funkcja wybrana na stronie logowania (cookie ti_login_fn z login.php, jednorazowo) — wstępne zaznaczenie trybu i roli
$_fn = (string)($_COOKIE['ti_login_fn'] ?? '');
if ($_fn !== '') {
    setcookie('ti_login_fn', '', ['expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
    if ($_fn === 'konsultacje' && in_array('konsultacje', $modes, true)) $curMode = 'konsultacje';
    elseif ($_fn === 'szkolenia') { $curMode = in_array('kursy', $modes, true) ? 'kursy' : $curMode; if ($askRole) $curRole = 'instructor'; }
    elseif ($_fn === 'kierownik') { $curMode = in_array('kursy', $modes, true) ? 'kursy' : $curMode; $curRole = 'staff'; }
}

// Ekran w samym panelu (pasek górny, skórka USOS) — nie na ekranie logowania
$KP_TITLE  = 'Jak pracujesz — Panel dydaktyka';
$KP_TOPBAR = ['brand' => 'Panel dydaktyka', 'icon' => 'easel2', 'user' => (string)($s['name'] ?? ''), 'logout' => 'logout.php'];
$KP_BODY_CLASS = 'dyd-usos ti-skin';
include dirname(__DIR__) . '/kursant/_layout_head.php';
$_skin_css = __DIR__ . '/../assets/ti_skin.css';
require_once dirname(__DIR__, 3) . '/modules/panel_metro/lib/panelMetro.php';
// Kafel wyboru (Metro): radio ukryte wizualnie, cały kafel klikalny; zaznaczony = kolor + znacznik
$opt = function (string $name, string $value, bool $checked, string $icon, string $title, string $desc, string $color): void { ?>
      <label class="cm-opt" style="--c:<?= h(panelMetroColor($color)) ?>">
        <input type="radio" name="<?= h($name) ?>" value="<?= h($value) ?>"<?= $checked ? ' checked' : '' ?> required>
        <i class="bi bi-<?= h($icon) ?> ic" aria-hidden="true"></i>
        <i class="bi bi-check-lg ok" aria-hidden="true"></i>
        <span class="bd"><span class="lbl"><?= h($title) ?></span><span class="sub"><?= h($desc) ?></span></span>
      </label>
<?php };
?>
<link rel="stylesheet" href="../assets/ti_skin.css?v=<?= is_file($_skin_css) ? (int)filemtime($_skin_css) : 1 ?>">
<?php panelMetroAssets(panelMetroColor('orange')); ?>
<style>
.pvm.cm{max-width:960px;padding:0 16px}
/* Układ: każdy wybór (Rola, Tryb pracy) to osobny wiersz na całą szerokość, a kafle w wierszu obok siebie */
.pvm .cm-sets{display:grid;gap:1.75rem;grid-template-columns:1fr;border:0;margin:0}
.pvm fieldset.cm-set{border:0;margin:0;padding:0;min-width:0}
.pvm .cm-set legend{float:none;width:auto;margin:0 0 .5rem;padding:0;font-size:1.05rem;font-weight:300;color:var(--pvm-muted);letter-spacing:.01em}
.pvm .cm-opts{display:grid;gap:10px;grid-template-columns:1fr}
@media(min-width:768px){.pvm .cm-opts{grid-template-columns:1fr 1fr}}
/* Kafel niezaznaczony: jasny odcień własnego koloru z grubą krawędzią (żadnej szarości); zaznaczony: pełny kolor */
.pvm .cm-opt{position:relative;display:flex;gap:14px;align-items:flex-start;min-height:104px;padding:16px 44px 16px 16px;background:color-mix(in srgb,var(--c) 11%,#fff);border-left:8px solid var(--c);color:var(--pvm-ink);cursor:pointer;margin:0;transition:background .12s}
.pvm .cm-opt:hover{background:color-mix(in srgb,var(--c) 20%,#fff)}
.pvm .cm-opt input{position:absolute;opacity:0;width:1px;height:1px;margin:0;pointer-events:none}
.pvm .cm-opt .ic{font-size:2rem;line-height:1;color:var(--c);flex-shrink:0}
.pvm .cm-opt .ok{position:absolute;top:10px;right:12px;font-size:1.4rem;line-height:1;display:none}
.pvm .cm-opt .lbl{display:block;font-size:1.15rem;font-weight:600;line-height:1.2}
.pvm .cm-opt .sub{display:block;font-size:.86rem;margin-top:.3rem;color:#333;line-height:1.4}
.pvm .cm-opt:has(input:checked){background:var(--c);color:#fff}
.pvm .cm-opt:has(input:checked) .ic,.pvm .cm-opt:has(input:checked) .sub{color:#fff}
.pvm .cm-opt:has(input:checked) .ok{display:block}
.pvm .cm-opt:has(input:focus-visible){outline:3px solid #fbbf24;outline-offset:2px}
.pvm .cm-group{margin-top:1.5rem}
.pvm .cm-group label{display:block;margin:0 0 .5rem;font-size:1.05rem;font-weight:300;color:var(--pvm-muted)}
.pvm .cm-group select{width:100%;max-width:32rem;border:2px solid #8a8a8a;border-radius:0;padding:.55rem .75rem;font:inherit;font-size:1rem;background:var(--pvm-bg);color:var(--pvm-ink);min-height:44px}
.pvm .cm-group select:focus{border-color:var(--pvm-acc);outline:0}
.pvm .cm-hint{font-size:.84rem;color:var(--pvm-muted);margin:.45rem 0 0;max-width:70ch}
.pvm .cm-foot{display:flex;flex-wrap:wrap;align-items:center;gap:1rem;margin-top:1.75rem}
.pvm .cm-foot a.cm-alt{font-size:.9rem;font-weight:600;color:var(--pvm-ink);text-decoration:underline;text-underline-offset:3px}
:root[data-theme="hc"] .pvm .cm-opt{border:2px solid #000}
:root[data-theme="hc"] .pvm .cm-opt:has(input:checked){background:#000;color:#fff}
@media(prefers-reduced-motion:reduce){.pvm .cm-opt{transition:none}}
</style>
<main id="main" class="dyd-wrap">
<div class="pvm cm py-4">
  <div class="mt-head">
    <div>
      <div class="mt-kicker">Panel dydaktyka<?= !empty($s['name']) ? ' · ' . h((string)$s['name']) : '' ?></div>
      <h1 class="mt-title">Jak teraz pracujesz?</h1>
      <p class="mt-lead">Wybór dotyczy tej sesji — menu panelu dopasuje się do niego. Zmienisz go w każdej chwili: menu użytkownika → „Zmień rolę / tryb pracy”.</p>
    </div>
  </div>
  <?= flash_html() ?>
  <form method="post">
    <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
    <div class="cm-sets<?= $askRole && $askMode ? ' cm-sets--2' : '' ?>">
      <?php if ($askRole): ?>
      <fieldset class="cm-set">
        <legend>Rola</legend>
        <div class="cm-opts">
          <?php $opt('role', 'staff', $curRole !== 'instructor', 'building', 'Kierownik Instytucji', 'Widzisz wszystkie grupy i całą organizację zajęć — rozliczenia, zapisy, zespół i ustawienia.', 'indigo'); ?>
          <?php $opt('role', 'instructor', $curRole === 'instructor' && empty($s['acting_as_other']), 'mortarboard', 'Prowadzący', 'Widzisz tylko swoje grupy (' . $ownCount . '), tak jak każdy prowadzący.', 'teal'); ?>
        </div>
      </fieldset>
      <?php endif; ?>
      <?php if ($askMode): ?>
      <fieldset class="cm-set">
        <legend>Tryb pracy</legend>
        <div class="cm-opts">
          <?php $opt('mode', 'kursy', $curMode !== 'konsultacje', 'easel2', 'Szkolenia', 'Prowadzisz zajęcia: sprawdzasz obecność, uzupełniasz dziennik i zamykasz miesiąc protokołem.', 'orange'); ?>
          <?php $opt('mode', 'konsultacje', $curMode === 'konsultacje', 'clipboard2-check', 'Konsultacje', 'Umawiasz spotkania z beneficjentami i po każdym wypełniasz kartę konsultacji.', 'violet'); ?>
        </div>
      </fieldset>
      <?php endif; ?>
    </div>
    <?php if ($groups): ?>
    <div class="cm-group" id="cm-group">
      <label for="cm-course">Grupa, na której pracujesz <small>(Szkolenia)</small></label>
      <select id="cm-course" name="course" aria-describedby="cm-course-hint">
        <option value="0">— wybiorę w panelu —</option>
        <?php foreach ($groups as $g): ?><option value="<?= (int)$g['id'] ?>"<?= (int)$g['id'] === $prefGroup ? ' selected' : '' ?>><?= h($g['name']) ?><?= trim((string)($g['display_name'] ?? '')) !== '' ? ' — ' . h($g['display_name']) : '' ?></option><?php endforeach; ?>
      </select>
      <p class="cm-hint" id="cm-course-hint">Panel otworzy się od razu na tej grupie i zapamięta ją na kolejne logowania; zmienisz ją w każdej chwili przełącznikiem grupy u góry.</p>
    </div>
    <?php endif; ?>
    <div class="cm-foot">
      <button type="submit" class="mt-btn"><i class="bi bi-box-arrow-in-right" aria-hidden="true"></i>Przejdź do panelu</button>
      <?php if (!empty($s['is_staff'])): ?><a class="cm-alt" href="choose_context.php">Wejdź jako inny prowadzący…</a><?php endif; ?>
    </div>
  </form>
</div>
</main>
<script>
(function () {   // pole grupy tylko przy trybie Szkolenia
  var box = document.getElementById('cm-group'); if (!box) return;
  function sync() { var m = document.querySelector('input[name=mode]:checked'); box.hidden = !!m && m.value !== 'kursy'; }
  document.querySelectorAll('input[name=mode]').forEach(function (r) { r.addEventListener('change', sync); }); sync();
})();
</script>
<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
