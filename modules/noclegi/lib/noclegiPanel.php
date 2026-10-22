<?php
/**
 * modules/noclegi/lib/noclegiPanel.php — kafel „Noclegi” w panelu współpracownika (panel/includes/pv_staff_home.php,
 * pv_home.php). Kafel pokazuje się tylko osobie z dostępem do modułu (znacznik „Dop. noclegi” na umowie albo rola
 * Koordynatora / Logistyka); podpis = najbliższy potwierdzony pobyt albo liczba zgłoszeń w toku.
 */
require_once dirname(__DIR__) . '/logic/noclegi.php';

/** @return array{href:string,icon:string,label:string,sub:string,val:string,color:string,group:string}|null */
function noclPanelTile(int $uid): ?array {
    try {
        if ($uid <= 0 || !noclEligible($uid)) return null;
        $today = date('Y-m-d');
        $next = db_one("SELECT date_from, date_to FROM nocl_requests WHERE (user_id = ? OR created_by = ?) AND status = 'potwierdzone' AND date_to >= ? ORDER BY date_from LIMIT 1", [$uid, $uid, $today]);
        $open = (int)(db_one("SELECT COUNT(*) AS c FROM nocl_requests WHERE (user_id = ? OR created_by = ?) AND status IN ('wniosek','w_trakcie')", [$uid, $uid])['c'] ?? 0);
        $sub = $next ? 'najbliższy pobyt: ' . date('d.m', strtotime($next['date_from'])) . '–' . date('d.m', strtotime($next['date_to'])) : ($open ? 'zgłoszeń w toku' : 'zgłoś nocleg, instrukcja zameldowania');
        return ['href' => APP_URL . '/modules/noclegi/router.php', 'icon' => 'bi-moon-stars', 'label' => 'Noclegi', 'sub' => $sub,
                'val' => $open ? (string)$open : '', 'color' => $next ? 'blue' : 'teal', 'group' => 'praca'];
    } catch (\Throwable $e) { error_log('noclegi: kafel panelu — ' . $e->getMessage()); return null; }
}
