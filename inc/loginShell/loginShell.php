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

/** Kolory METRO wspólne dla wszystkich stron logowania. */
const LOGIN_SHELL_ACCENT      = '#1ba1e2';
const LOGIN_SHELL_ACCENT_DARK = '#1790c9';

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
.tz-side { background: #1d1d1d; color: #fff; padding: 2.5rem 2rem; flex: 1 1 50%; display: flex; flex-direction: column; justify-content: center; }
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
 * Lewa kolumna: organizacja, tytuł, podtytuł, instrukcja i opcjonalna nawigacja.
 *
 * @param array $o sideTitle, sideSub, sideSteps (lista zdań), sideHtml (dowolny HTML),
 *                 navHtml (linki/zakładki), logoUrl, orgLabel
 */
function loginShellSide(array $o): string {
    $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $org   = $o['orgLabel'] ?? 'FEER · System Tożsamości';
    $steps = $o['sideSteps'] ?? [];
    $out   = '<aside class="tz-side" aria-labelledby="tz-side-h">';
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
    return $out . '</aside>';
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
