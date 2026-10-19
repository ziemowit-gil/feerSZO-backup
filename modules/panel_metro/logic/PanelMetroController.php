<?php
/**
 * modules/panel_metro/logic/PanelMetroController.php — zapis ustawień pulpitu METRO „Mój panel”.
 */
require_once dirname(__DIR__) . '/lib/panelMetro.php';

class PanelMetroController
{
    /** POST /uklad — widok kafli „Grupuj wg funkcji” (grouped=1) albo „Bez grupowania” (grouped=0). */
    public function saveGrouping()
    {
        $uid = (int)(current_user()['id'] ?? 0);
        $on  = (string)($_POST['grouped'] ?? '') === '1';
        panelMetroGroupingSet($uid, $on);

        if (str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) {
            return ['ok' => true, 'grouped' => $on];
        }
        // Powrót na stronę z przełącznikiem — tylko ścieżka w obrębie serwisu (bez //host i \).
        $back = (string)($_POST['back'] ?? '');
        if (!preg_match('~^/(?![/\\\\])~', $back)) $back = APP_URL . '/panel/index.php';
        return Router::redirect($back);
    }
}
