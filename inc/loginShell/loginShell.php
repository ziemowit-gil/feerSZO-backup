<?php
/**
 * inc/loginShell/loginShell.php — wspólny wygląd stron logowania w stylu logowania TI
 * (karty30/ti/login.php): METRO UI, układ dwukolumnowy jak w iPKO.
 *
 * Lewa kolumna (ciemna): nazwa systemu, tytuł i krótka instrukcja „jak się zalogować".
 * Prawa kolumna (jasna): kwadratowa biała karta z formularzem. Na telefonie kolumny
 * układają się jedna pod drugą. Na górze baner „Logowanie centralne".
 *
 * Użycie (strony z własnym formularzem, Bootstrap 5 w środku karty):
 *   loginShellHead(['title' => 'Logowanie — X', 'sideTitle' => 'X', 'sideSteps' => ['…', '…']]);
 *   … treść karty …
 *   loginShellFoot(['links' => [['url' => …, 'label' => …]]]);
 *
 * Powłoka SZO (includes/auth_screen.php) korzysta z loginShellCss() i loginShellSide(),
 * żeby logowanie SZO, CRM, rejestracja i reset hasła wyglądały tak samo.
 */

/** Kolory METRO wspólne dla wszystkich stron logowania. Ciemniejszy niebieski niż #1ba1e2:
 *  biały tekst ma kontrast ok. 5,4:1 (WCAG AA wymaga 4,5:1; #1ba1e2 dawał tylko 2,9:1). */
const LOGIN_SHELL_ACCENT      = '#0f6fa8';
const LOGIN_SHELL_ACCENT_DARK = '#0c5b8a';

/** Baner „Logowanie centralne" — ten sam tekst co na logowaniu TI. */
function loginShellBanner(): string {
    $sso = rtrim(APP_URL, '/') . '/karty30/ti/sso.php';
    return '<div class="tz-banner" role="region" aria-label="Informacja o logowaniu">'
         . 'Logowanie centralne przez System Tożsamości — adresy i zapisane linki bez zmian.'
         . ' <a href="' . htmlspecialchars($sso, ENT_QUOTES, 'UTF-8') . '">Czym jest SSO?</a></div>';
}

/** Arkusz stylów METRO (bez znacznika <style>). */
function loginShellCss(): string {
    $a = LOGIN_SHELL_ACCENT;
    $d = LOGIN_SHELL_ACCENT_DARK;
    return <<<CSS
body.tzl { font-family: "Segoe UI", system-ui, sans-serif; background: #f3f4f6; margin: 0; color: #1d1d1d; }
.tz-banner { background: {$a}; color: #fff; padding: .6rem 1rem; text-align: center; font-weight: 600; font-size: .95rem; }
.tz-banner a { color: #fff; text-decoration: underline; margin-left: .5rem; }
.tz-skip { position: absolute; left: -999px; top: auto; }
.tz-skip:focus { left: 1rem; top: 1rem; background: #fff; color: #1d1d1d; padding: .5rem 1rem; z-index: 10; }
.tz-split { display: flex; flex-direction: column; min-height: 100vh; }
@media (min-width: 768px) { .tz-split { flex-direction: row; } }
/* Telefon: najpierw formularz, potem panel z instrukcją i serwisami. */
@media (max-width: 767.98px) { .tz-main { order: 1; } .tz-side, .tz-side-show { order: 2; } .tz-side { padding-top: 3.5rem; } }
.tz-side { position: relative; background: #1d1d1d; color: #fff; padding: 2.5rem 2rem; flex: 1 1 50%; display: flex; flex-direction: column; justify-content: center; }
.tz-side-hide { position: absolute; top: .75rem; right: .75rem; display: inline-flex; align-items: center; gap: .35rem; min-height: 40px; padding: .35rem .75rem; background: transparent; color: #fff; border: 1px solid rgba(255,255,255,.45); font-size: .85rem; font-weight: 600; cursor: pointer; }
.tz-side-hide:hover { background: rgba(255,255,255,.1); border-color: #fff; }
.tz-side-show { display: none; align-items: center; justify-content: center; gap: .5rem; background: #1d1d1d; color: #fff; border: 0; font-weight: 600; font-size: .9rem; cursor: pointer; padding: .75rem 1rem; }
.tz-side-show:hover { background: #2d2d2d; }
.tz-side-hide:focus-visible, .tz-side-show:focus-visible { outline: 3px solid #facc15; outline-offset: 2px; }
html.tz-side-off .tz-side { display: none; }
html.tz-side-off .tz-side-show { display: flex; }
@media (min-width: 768px) {
  .tz-side { padding-top: 3.5rem; }
  .tz-side-show { flex: 0 0 52px; flex-direction: column; padding: 1rem .25rem; }
  .tz-side-show span { writing-mode: vertical-rl; transform: rotate(180deg); }
}
.tz-side .tz-org { font-size: .8rem; font-weight: 700; letter-spacing: .06em; margin-bottom: .5rem; text-transform: uppercase; }
.tz-side .tz-logo { max-height: 56px; max-width: 220px; object-fit: contain; filter: brightness(0) invert(1); margin-bottom: 1rem; align-self: flex-start; }
.tz-side h2 { font-size: 1.6rem; font-weight: 700; margin: 0 0 .5rem; color: #fff; }
.tz-side .sub { color: #cbd5e1; font-size: .95rem; margin: 0; }
.tz-side ol, .tz-side ul { padding-left: 1.1rem; margin: 1.25rem 0 0; font-size: .92rem; }
.tz-side li { margin-bottom: .5rem; }
.tz-side a { color: #7dd3fc; }
.tz-side-nav { display: flex; flex-direction: column; gap: .25rem; margin-top: 1.75rem; max-width: 320px; }
.tz-side-nav .ks-tab, .tz-side-nav a { display: block; padding: .7rem 1rem; color: #fff; text-decoration: none; font-weight: 600; border-left: 4px solid transparent; background: rgba(255,255,255,.06); }
.tz-side-nav .ks-tab:hover, .tz-side-nav a:hover { background: rgba(255,255,255,.14); color: #fff; }
.tz-side-nav [aria-current="page"] { border-left-color: {$a}; background: rgba(27,161,226,.22); }
.tz-actions { margin-top: 2rem; max-width: 480px; }
.tz-actions h3 { font-size: .8rem; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: #cbd5e1; margin: 0 0 .25rem; }
.tz-actions-sub { color: #cbd5e1; font-size: .88rem; margin: 0 0 .75rem; }
/* Kafle METRO: równa wysokość, ikona u góry, podpis na dole; ostatni samotny kafel = kafel szeroki. */
.tz-actions ul { list-style: none; padding: 0; margin: 0; display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 6px; }
.tz-actions li { margin: 0; }
.tz-actions li:last-child:nth-child(3n+1) { grid-column: 1 / -1; }
.tz-actions li:last-child:nth-child(3n+2) { grid-column: span 2; }
.tz-actions a { position: relative; display: flex; flex-direction: column; justify-content: space-between; height: 96px; padding: .7rem .75rem .6rem; color: #fff; text-decoration: none; font-weight: 600; font-size: .86rem; line-height: 1.2; background: var(--tz-k, #2d2d2d); outline: 2px solid transparent; outline-offset: -2px; transition: outline-color .12s, filter .12s; }
.tz-actions a:hover { filter: brightness(1.1); outline-color: rgba(255,255,255,.55); color: #fff; }
.tz-actions a .bi { font-size: 1.55rem; line-height: 1; }
.tz-actions a .tz-ext { position: absolute; top: .55rem; right: .6rem; font-size: .8rem; opacity: .8; }
@media (max-width: 420px) {
  .tz-actions ul { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .tz-actions li:last-child:nth-child(n) { grid-column: auto; }
  .tz-actions li:last-child:nth-child(odd) { grid-column: 1 / -1; }
}
.tz-side-nav nav, .tz-side-nav .ks-tabs { display: flex; flex-direction: column; gap: .25rem; }
.tz-main { flex: 1 1 50%; display: flex; align-items: center; justify-content: center; padding: 2rem 1.25rem; }
.tz-card { background: #fff; box-shadow: 0 2px 10px rgba(0,0,0,.08); padding: 2.5rem 2rem; width: 100%; max-width: 420px; border-radius: 0; }
.tz-card--wide { max-width: 560px; }
.tz-card .form-control, .tz-card .form-select { border-radius: 0; box-shadow: none; padding: .7rem .9rem; font-size: 1.05rem; }
.tz-card .form-control:focus, .tz-card .form-select:focus { border-color: {$a}; box-shadow: none; }
.tz-card .input-group-text { border-radius: 0; }
.tz-card label { font-weight: 600; font-size: .92rem; margin-bottom: .3rem; }
.tz-card .btn { border-radius: 0; font-weight: 600; }
.tz-card .btn-primary { --bs-btn-bg: {$a}; --bs-btn-border-color: {$a}; --bs-btn-hover-bg: {$d}; --bs-btn-hover-border-color: {$d}; --bs-btn-active-bg: {$d}; --bs-btn-active-border-color: {$d}; }
.tz-card .btn-outline-primary { --bs-btn-color: #1d1d1d; --bs-btn-border-color: {$a}; --bs-btn-hover-bg: #e6f6fc; --bs-btn-hover-color: #1d1d1d; --bs-btn-hover-border-color: {$a}; border-width: 2px; }
.tz-card .alert { border-radius: 0; }
.tz-card a { color: #1d4ed8; }
.tz-btn { border-radius: 0; background: {$a}; border: 0; color: #fff; font-weight: 600; padding: .75rem 1rem; width: 100%; text-align: center; text-decoration: none; display: block; cursor: pointer; }
.tz-btn:hover { background: {$d}; color: #fff; }
.tz-card a.tz-btn { color: #fff; }
.tz-btn-ghost, .tz-card a.tz-btn-ghost { background: #fff; color: #1d1d1d; border: 2px solid {$a}; }
.tz-btn-ghost:hover, .tz-card a.tz-btn-ghost:hover { background: #e6f6fc; color: #1d1d1d; }
.tz-btn + .tz-btn { margin-top: .5rem; }
.tz-account { margin-top: 1.75rem; max-width: 420px; }
.tz-account-h { color: #cbd5e1; font-size: .8rem; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; margin: 0 0 .6rem; }
.tz-account .tz-btn { text-align: left; }
.tz-side a.tz-btn-side { background: transparent; color: #fff; border: 1px solid rgba(255,255,255,.45); font-weight: 600; }
.tz-side a.tz-btn-side:hover { background: rgba(255,255,255,.1); border-color: #fff; color: #fff; }
.tz-card :focus-visible, .tz-side a:focus-visible, .tz-banner a:focus-visible, .tz-btn:focus-visible, .tz-footer a:focus-visible { outline: 3px solid #facc15; outline-offset: 2px; }
.tz-footer { text-align: center; font-size: .85rem; color: #4b5563; padding: 1rem; }
.tz-footer a { color: #1d4ed8; text-decoration: none; margin: 0 .5rem; }
.tz-hint { font-size: .85rem; color: #4b5563; margin-top: .75rem; }
.tz-sep { display: flex; align-items: center; gap: .6rem; color: #6b7280; font-size: .82rem; margin: 1rem 0; }
.tz-sep::before, .tz-sep::after { content: ""; height: 1px; background: #e5e7eb; flex: 1; }
.pass-wrap { position: relative; }
.pass-wrap .form-control { padding-right: 3rem; }
.pass-toggle { position: absolute; right: 0; top: 0; height: 100%; width: 3rem; border: 0; background: transparent; }
@media (prefers-reduced-motion: reduce) { *, *::before, *::after { transition: none !important; animation: none !important; } }
CSS;
}

/**
 * Kafle „Serwisy i systemy z kontem w Tożsamości" w lewej kolumnie: systemy, do których
 * wchodzi się kontem z Systemu Tożsamości (to samo konto i hasło), oraz aktywne aplikacje
 * podłączone przez SAML (tabela saml_sp, pole relay_default). Kafel bieżącej strony jest pomijany.
 */
function loginShellActions(): string {
    $e    = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $app  = rtrim(APP_URL, '/');
    $self = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    $systemy = [
        // Kolory METRO przyciemnione tak, by biały tekst miał kontrast ≥ 4,5:1.
        [$app . '/tozsamosc/index.php',   'Moje konto (Tożsamość)', 'bi-person-vcard',       '#0f6fa8'],
        [$app . '/auth/login.php',        'System SZO',             'bi-building',           '#2d2d2d'],
        [$app . '/karty30/ti/login.php',  'Panel kursanta TI',      'bi-pc-display',         '#00796b'],
        [$app . '/karty30/login.php',     'Dydaktyka 3',            'bi-card-checklist',     '#5b21b6'],
        [$app . '/crm/login.php',         'CRM',                    'bi-people',             '#b0125b'],
        [$app . '/ezd/index.php',         'EZD (dokumenty)',        'bi-folder2-open',       '#a16207'],
        [$app . '/tasks/index.php',       'Zadania',                'bi-check2-square',      '#c2410c'],
        ['https://outlook.office.com',    'Poczta',                 'bi-envelope',           '#0050c8'],
        [$app . '/panel/index.php',       'Panel współpracownika',  'bi-heart',              '#15803d'],
        ['https://www.office.com',        'Microsoft 365',          'bi-microsoft',          '#2d2d2d'],
    ];
    // Aplikacje zewnętrzne logujące przez Tożsamość (SAML) — tylko gdy baza jest dostępna.
    try {
        if (function_exists('db_all')) {
            foreach (db_all("SELECT name, relay_default FROM saml_sp WHERE is_active = 1 AND COALESCE(relay_default, '') != '' ORDER BY name") as $sp) {
                if (preg_match('#^https?://#i', (string)$sp['relay_default'])) {
                    $systemy[] = [(string)$sp['relay_default'], (string)$sp['name'], 'bi-box-arrow-up-right', '#2d2d2d'];
                }
            }
        }
    } catch (\Throwable $ex) {}

    $li = '';
    foreach ($systemy as [$url, $label, $icon, $kolor]) {
        $path = (string)parse_url($url, PHP_URL_PATH);
        if ($self !== '' && str_starts_with($url, $app) && $path === $self) continue;   // bieżąca strona
        $ext = !str_starts_with($url, $app);
        $li .= '<li><a href="' . $e($url) . '" style="--tz-k:' . $kolor . '">'
             . '<i class="bi ' . $icon . '" aria-hidden="true"></i>'
             . ($ext ? '<i class="bi bi-box-arrow-up-right tz-ext" aria-hidden="true"></i>' : '')
             . '<span>' . $e($label) . ($ext ? '<span class="visually-hidden"> (serwis zewnętrzny)</span>' : '') . '</span></a></li>';
    }
    return '<section class="tz-actions" aria-labelledby="tz-actions-h">'
         . '<h3 id="tz-actions-h">Serwisy i systemy z kontem w Tożsamości</h3>'
         . '<p class="tz-actions-sub">Wszędzie tu logujesz się tym samym kontem.</p><ul>' . $li . '</ul></section>';
}

/**
 * Przyciski w lewej kolumnie: „Zaloguj się", „Załóż nowe konto", „Odzyskaj hasło".
 * Przycisk strony, na której jesteśmy, jest pomijany.
 *
 * @param array $which podzbiór ['login', 'register', 'reset']
 * @param bool  $registerOpen czy rejestracja jest otwarta (gdy nie — bez „Załóż konto")
 */
function loginShellAccountButtons(array $which = ['register', 'reset'], bool $registerOpen = true): string {
    $app  = rtrim(APP_URL, '/');
    $self = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    $btn  = [
        'login'    => ['/auth/login.php',       'Zaloguj się',      'bi-box-arrow-in-right'],
        'register' => ['/user/register.php',    'Załóż nowe konto', 'bi-person-plus'],
        'reset'    => ['/user/verify_reset.php','Odzyskaj hasło',   'bi-key'],
    ];
    $out = '';
    foreach ($which as $k) {
        if (!isset($btn[$k]) || ($k === 'register' && !$registerOpen)) continue;
        [$path, $label, $icon] = $btn[$k];
        if ($self !== '' && str_ends_with($self, $path)) continue;
        $out .= '<a href="' . $app . $path . '" class="tz-btn tz-btn-side">'
              . '<i class="bi ' . $icon . ' me-2" aria-hidden="true"></i>' . $label . '</a>';
    }
    if ($out === '') return '';
    return '<div class="tz-account" role="group" aria-labelledby="tz-account-h">'
         . '<p class="tz-account-h" id="tz-account-h">Konto i hasło</p>' . $out . '</div>';
}

/**
 * Lewa kolumna: organizacja, tytuł, podtytuł, instrukcja i opcjonalna nawigacja.
 *
 * @param array $o sideTitle, sideSub, sideSteps (lista zdań), sideHtml (dowolny HTML),
 *                 navHtml (linki/zakładki), logoUrl, orgLabel, actions (kafle „Serwisy i systemy z kontem w Tożsamości", domyślnie true),
 *                 account (['login','register','reset'] — przyciski konta), registerOpen
 */
function loginShellSide(array $o): string {
    $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $org   = $o['orgLabel'] ?? 'FEER · System Tożsamości';
    $steps = $o['sideSteps'] ?? [];
    // Panel można schować; wybór pamięta przeglądarka. Skrypt przed panelem — bez mignięcia.
    $out   = '<script>try{if(localStorage.getItem("tzSideOff")==="1")document.documentElement.classList.add("tz-side-off");}catch(e){}</script>'
           . '<button type="button" class="tz-side-show" aria-controls="tz-side" aria-expanded="false" onclick="tzPanel(true)">'
           . '<i class="bi bi-layout-sidebar" aria-hidden="true"></i><span>Pokaż panel informacji</span></button>'
           . '<aside class="tz-side" id="tz-side" aria-labelledby="tz-side-h">'
           . '<button type="button" class="tz-side-hide" aria-controls="tz-side" aria-expanded="true" onclick="tzPanel(false)">'
           . '<i class="bi bi-chevron-double-left" aria-hidden="true"></i>Ukryj panel</button>';
    if (!empty($o['logoUrl'])) {
        $out .= '<img class="tz-logo" src="' . $e($o['logoUrl']) . '" alt="">';
    }
    $out .= '<div class="tz-org">' . $e($org) . '</div>';
    $out .= '<h2 id="tz-side-h">' . $e($o['sideTitle'] ?? 'Logowanie') . '</h2>';
    if (!empty($o['sideSub'])) $out .= '<p class="sub">' . $e($o['sideSub']) . '</p>';
    if ($steps) {
        $out .= '<ol>';
        foreach ($steps as $s) $out .= '<li>' . $e($s) . '</li>';
        $out .= '</ol>';
    }
    $out .= $o['sideHtml'] ?? '';
    if (!empty($o['navHtml'])) {
        $out .= '<nav class="tz-side-nav" aria-label="' . $e($o['navLabel'] ?? 'Logowanie') . '">' . $o['navHtml'] . '</nav>';
    }
    if (!empty($o['account'])) $out .= loginShellAccountButtons($o['account'], $o['registerOpen'] ?? true);
    if ($o['actions'] ?? true) $out .= loginShellActions();
    return $out . '</aside>'
         . '<script>function tzPanel(on){var r=document.documentElement;r.classList.toggle("tz-side-off",!on);'
         . 'try{localStorage.setItem("tzSideOff",on?"0":"1");}catch(e){}'
         . 'var b=document.querySelector(on?".tz-side-hide":".tz-side-show");if(b)b.focus();}</script>';
}

/**
 * Otwiera stronę logowania.
 *
 * @param array $o title, sideTitle, sideSub, sideSteps, sideHtml, navHtml, banner (domyślnie true),
 *                 mainId, wide (szersza karta), extraHead (HTML do <head>)
 */
function loginShellHead(array $o = []): void {
    $e      = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $mainId = $o['mainId'] ?? 'tz-login-main';
    ?><!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $e($o['title'] ?? 'Logowanie') ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style><?= loginShellCss() ?></style>
<?= $o['extraHead'] ?? '' ?>
</head>
<body class="tzl">
<a href="#<?= $e($mainId) ?>" class="tz-skip">Przejdź do formularza logowania</a>
<?= ($o['banner'] ?? true) ? loginShellBanner() : '' ?>
<div class="tz-split">
  <?= loginShellSide($o) ?>
  <main class="tz-main" id="<?= $e($mainId) ?>" tabindex="-1">
    <div class="tz-card<?= !empty($o['wide']) ? ' tz-card--wide' : '' ?>">
<?php
}

/**
 * Zamyka stronę logowania: karta, stopka z linkami, skrypt pokazywania hasła.
 *
 * @param array $o links ([['url'=>, 'label'=>], …]), extraHtml, extraJs, bootstrapJs (domyślnie true)
 */
function loginShellFoot(array $o = []): void {
    $e     = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $links = $o['links'] ?? [['url' => 'https://feer.org.pl', 'label' => 'Strona Fundacji FEER']];
    ?>
    </div>
  </main>
</div>
<?= $o['extraHtml'] ?? '' ?>
<?php if ($links): ?>
<footer class="tz-footer" aria-label="Linki">
  <?php foreach ($links as $l): ?><a href="<?= $e($l['url']) ?>"><?= $e($l['label']) ?></a><?php endforeach; ?>
</footer>
<?php endif; ?>
<?php if ($o['bootstrapJs'] ?? true): ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php endif; ?>
<script>
// Pokazywanie/ukrywanie hasła (dostępne z klawiatury, z aria-pressed).
function tzPokaz(id, btn) {
  var p = document.getElementById(id);
  if (!p) return;
  var pokaz = p.type === 'password';
  p.type = pokaz ? 'text' : 'password';
  btn.setAttribute('aria-pressed', pokaz ? 'true' : 'false');
  btn.setAttribute('aria-label', pokaz ? 'Ukryj hasło' : 'Pokaż hasło');
  var i = btn.querySelector('i'); if (i) i.className = pokaz ? 'bi bi-eye-slash' : 'bi bi-eye';
}
</script>
<?= $o['extraJs'] ?? '' ?>
</body>
</html>
<?php
}
