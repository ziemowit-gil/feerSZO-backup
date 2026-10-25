<?php
/**
 * Jednorazowe uzupełnienie godzin otwarcia sal (k30_pl_rooms.open_hours) dla CO Reymonta i C10.
 * Domyślnie TYLKO podgląd; zapis: php cli/seed_godziny_sal.php --apply
 * Nie nadpisuje już wpisanych godzin (pole musi być puste), chyba że --force.
 * Sale dobiera po fragmencie nazwy / skrótu / lokalizacji / nazwy budynku; resztę wpisuje się w sale.php.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/ti_planner_ext.php';

$apply = in_array('--apply', $argv ?? [], true);
$force = in_array('--force', $argv ?? [], true);

$centra = [
    'CO Reymonta' => [
        'wzorce' => ['Reymonta'],
        'godziny' => 'Pn 9:00–16:00; Wt 13:00–20:00; Śr 13:00–20:00; Cz 13:00–20:00; Pt 9:00–16:00; II sobota miesiąca 9:00–16:00',
    ],
    'C10' => [
        'wzorce' => ['C10', 'C 10'],
        'godziny' => 'Pn 12:00–19:00; Wt 8:00–15:00; Śr 12:00–19:00; Cz 12:00–19:00; Pt 8:00–15:00; III sobota miesiąca 8:00–15:00',
    ],
];

ti_planner_ext_migrate();
$sale = db_all("SELECT r.id, r.name, r.short_label, r.location, r.operator_label, r.open_hours, b.name AS building
                  FROM k30_pl_rooms r LEFT JOIN k30_pl_buildings b ON b.id = r.building_id WHERE r.is_active = 1");
$n = 0;
foreach ($centra as $nazwa => $c) {
    foreach ($sale as $r) {
        $tekst = implode(' ', [$r['name'], $r['short_label'], $r['location'], $r['operator_label'] ?? '', $r['building'] ?? '']);
        $pasuje = array_filter($c['wzorce'], fn($w) => mb_stripos($tekst, $w) !== false);
        if (!$pasuje) continue;
        $zajete = trim((string)$r['open_hours']) !== '' && !$force;
        printf("[%s] sala #%d %s — %s\n", $nazwa, $r['id'], $r['name'], $zajete ? 'pomijam (ma już godziny)' : ($apply ? 'zapisuję' : 'zapisałbym'));
        if ($zajete) continue;
        if ($apply) db_exec("UPDATE k30_pl_rooms SET open_hours=? WHERE id=?", [$c['godziny'], (int)$r['id']]);
        $n++;
    }
}
echo ($apply ? "Zapisano: $n.\n" : "Podgląd: $n sal. Dodaj --apply, by zapisać.\n");
