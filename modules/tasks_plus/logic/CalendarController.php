<?php
/**
 * modules/tasks_plus/logic/CalendarController.php — Kalendarz zadań (miesiąc / lista) i kanał iCal.
 */
require_once dirname(__DIR__) . '/lib/tasksCalendar.php';

final class CalendarController
{
    public function index(): ?string
    {
        $uid = (int)current_user()['id'];
        $view = ($_GET['view'] ?? 'month') === 'agenda' ? 'agenda' : 'month';
        $m = preg_match('/^(\d{4})-(\d{2})$/', (string)($_GET['m'] ?? ''), $mm) ? $mm : null;
        $year = $m ? (int)$m[1] : (int)date('Y');
        $month = $m ? max(1, min(12, (int)$m[2])) : (int)date('n');
        $f = ['ws' => (int)($_GET['ws'] ?? 0), 'mine' => !empty($_GET['mine']), 'done' => !empty($_GET['done'])];

        $weeks = tasksCalendarMonthGrid($year, $month);
        $from = $weeks[0][0];
        $to = end($weeks)[6];
        if ($view === 'agenda') {
            $from = sprintf('%04d-%02d-01', $year, $month);
            $to = date('Y-m-t', strtotime($from));
        }
        $items = tasksCalendarItems($uid, $from, $to, $f);
        $workspaces = tasksPlusWorkspaces($uid);
        $token = tasksCalendarToken($uid);
        $icalUrl = $token ? route('cal.feed', ['token' => $token . '.ics']) : null;

        $body = Router::view(dirname(__DIR__) . '/view/calendar.php',
            compact('view', 'year', 'month', 'f', 'weeks', 'items', 'workspaces', 'icalUrl'));
        return tasksPlusPage('Kalendarz zadań', $body, $f['ws'], tasksCalendarMonthName($month) . ' ' . $year);
    }

    public function regenerate(): ?string
    {
        tasksCalendarRegenerate((int)current_user()['id']);
        flash_set('success', 'Wygenerowano nowy prywatny link do kalendarza. Poprzedni link przestał działać.');
        return Router::redirect(route('cal.index') . '#ical');
    }

    public function revoke(): ?string
    {
        tasksCalendarRevoke((int)current_user()['id']);
        flash_set('success', 'Link do kalendarza został wyłączony.');
        return Router::redirect(route('cal.index') . '#ical');
    }

    /** Publiczny kanał iCal — autoryzacja tokenem w adresie. */
    public function feed(string $token): ?string
    {
        if (!module_enabled('tasks_enabled')) { http_response_code(404); return 'Nie znaleziono.'; }
        $u = tasksCalendarUserByToken(preg_replace('/\.ics$/', '', $token));
        if (!$u) { http_response_code(404); return 'Nie znaleziono kalendarza.'; }
        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: inline; filename="zadania.ics"');
        header('Cache-Control: private, max-age=900');
        header('X-Robots-Tag: noindex');
        echo tasksCalendarIcs((int)$u['id'], (string)$u['name']);
        return null;
    }
}
