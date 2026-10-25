<?php
/**
 * modules/helpdeskReports/logic/HelpdeskReportsController.php — pulpit raportowy (trasy w router.php).
 */
require_once dirname(__DIR__) . '/lib/helpdeskReports.php';

final class HelpdeskReportsController
{
    private function days(): int
    {
        $d = (int)($_GET['days'] ?? 30);
        return isset(HD_REP_PERIODS[$d]) ? $d : 30;
    }

    public function index(): string
    {
        $days = $this->days();
        $since = hdRepSince($days);
        return Router::view(__DIR__ . '/../view/index.php', [
            'days' => $days, 'kpi' => hdRepKpis($since), 'cats' => hdRepByCategory($since),
            'ops' => hdRepByOperator($since), 'trend' => hdRepTrend($days),
        ]);
    }

    public function csv(): ?string
    {
        $days = $this->days();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="helpdesk-zgloszenia-' . date('Ymd') . '.csv"');
        echo hdRepCsv(hdRepSince($days));
        return null;
    }
}
