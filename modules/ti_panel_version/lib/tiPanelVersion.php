<?php
/**
 * modules/ti_panel_version/lib/tiPanelVersion.php — wersja panelu TI pokazywana w stopkach
 * (panel uczestnika, panel dydaktyka, strona logowania). Ustawia kierownik w panelu dydaktyka
 * (dydaktyk/wylaczenia.php → „Wersja panelu”), zapis w settings.key_ = ti_panel_version.
 */

const TI_PANEL_VERSION_KEY     = 'ti_panel_version';
const TI_PANEL_VERSION_DEFAULT = '3.1b';

function tiPanelVersion(): string {
    $v = '';
    try { $v = function_exists('org_setting') ? trim(org_setting(TI_PANEL_VERSION_KEY)) : ''; } catch (\Throwable $e) {}
    return $v !== '' ? $v : TI_PANEL_VERSION_DEFAULT;
}

/** Zapis z formularza: krótki napis (cyfry, litery, kropki, myślniki), pusty = domyślna wersja. */
function tiPanelVersionSet(string $v): void {
    $v = trim($v);
    if ($v !== '' && !preg_match('/^[\p{L}0-9 ._\-]{1,20}$/u', $v)) {
        throw new \InvalidArgumentException('Wersja może mieć do 20 znaków: litery, cyfry, kropki i myślniki.');
    }
    org_setting_set(TI_PANEL_VERSION_KEY, $v);
}

/** Linijka stopki „Panel TI · wersja 3.1b”. */
function tiPanelVersionFooter(): string {
    return '<p class="ti-panel-version text-center small text-body-secondary my-3">Panel TI · wersja '
        . htmlspecialchars(tiPanelVersion(), ENT_QUOTES, 'UTF-8') . '</p>';
}
