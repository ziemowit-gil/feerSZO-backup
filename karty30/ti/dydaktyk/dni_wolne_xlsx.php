<?php
/**
 * karty30/ti/dydaktyk/dni_wolne_xlsx.php — Wykaz dni wolnych/przerw za dany rok (XLSX).
 * GET: year (domyślnie bieżący). Dostęp: kierownik (dyd_is_staff()).
 */
require_once __DIR__ . '/auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_print_log.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/xlsx.php';

$me = dyd_require();
if (!dyd_is_staff()) { http_response_code(403); exit('Brak uprawnień.'); }
karty30_migrate();

$year = (int)($_GET['year'] ?? date('Y'));
$year = max(2020, min(2035, $year));

$items = db_all(
    "SELECT * FROM k30_ti_holidays WHERE strftime('%Y', date_from)=? OR strftime('%Y', date_to)=? ORDER BY date_from",
    [(string)$year, (string)$year]
);

$type_labels = ['holiday' => 'Dzień wolny / święto', 'break' => 'Przerwa w działalności', 'other' => 'Inne'];

$x = new XlsxWriter();
$x->addSheet('Dni wolne ' . $year);
$x->writeRow(['Wykaz dni wolnych — ' . $year], ['header']);
$x->writeRow(['Wygenerowano: ' . date('d.m.Y H:i') . ' przez ' . ($me['name'] ?? '')]);
$x->writeRow([]);
$x->writeRow(['Od', 'Do', 'Dni', 'Nazwa', 'Typ', 'Uwagi'], ['header']);

foreach ($items as $h) {
    $hdf = new DateTime($h['date_from']); $hdt = new DateTime($h['date_to']);
    $days = (int)$hdf->diff($hdt)->days + 1;
    $x->writeRow([
        $hdf->format('d.m.Y'), $hdt->format('d.m.Y'), $days,
        (string)$h['name'], $type_labels[(string)$h['type']] ?? (string)$h['type'], (string)($h['note'] ?? ''),
    ]);
}
if (!$items) {
    $x->writeRow(['Brak wpisów w kalendarzu dla roku ' . $year . '.']);
}

ti_print_log_add('dni_wolne_xlsx', 'Wykaz dni wolnych XLSX — ' . $year, 0, 0, ['year' => $year], $me);
$x->output('dni_wolne_' . $year . '.xlsx');
