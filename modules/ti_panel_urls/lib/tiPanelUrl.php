<?php
/**
 * modules/ti_panel_urls/lib/tiPanelUrl.php — czyste adresy paneli TI (bez /karty30/ti/…).
 *
 * Na hostach ti*. i kurs*. (ti.feer.org.pl, kursant.feer.org.pl) .htaccess serwuje panele pod
 * krótkimi ścieżkami: panel uczestnika w korzeniu („/index.php”), panel dydaktyka pod „/dydaktyk/”,
 * logowanie pod „/login.php”. Pełne ścieżki /karty30/ti/… są tam przekierowywane (GET) na krótkie,
 * więc te funkcje tylko oszczędzają zbędny skok przekierowania.
 *
 * Na pozostałych hostach (szo.*, CLI — e-maile/SMS z CRON-a) zwracają dotychczasowe /karty30/ti/….
 */

/** Czy bieżące żądanie przyszło na host z krótkimi adresami paneli TI (ti*. / kurs*.). */
function tiPanelCleanHost(): bool {
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    return $host !== '' && preg_match('/^(ti|kurs)[^.]*\./', $host) === 1;
}

/**
 * Bezwzględny adres strony panelu TI.
 *
 * @param string $panel 'kursant' | 'dydaktyk' | 'ti' (katalog karty30/ti — tylko login.php i pliki obok paneli)
 * @param string $path  ścieżka w panelu, np. 'index.php?tab=lekcje'
 */
function tiPanelUrl(string $panel, string $path = ''): string {
    $base  = defined('APP_URL') ? rtrim((string)APP_URL, '/') : '';
    $path  = ltrim($path, '/');
    $clean = tiPanelCleanHost();
    switch ($panel) {
        case 'kursant':  return $base . ($clean ? '/' : '/karty30/ti/kursant/') . $path;
        case 'dydaktyk': return $base . ($clean ? '/dydaktyk/' : '/karty30/ti/dydaktyk/') . $path;
        default:         return $base . ($clean ? '/' : '/karty30/ti/') . $path;
    }
}
