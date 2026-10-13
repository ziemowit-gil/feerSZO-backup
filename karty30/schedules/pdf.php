<?php
/**
 * karty30/schedules/pdf.php — PRZENIESIONE (karta terminu do druku).
 *
 * Rezerwacje terminów konsultacji prowadzi się w panelu dydaktyka, tryb Konsultacje (karty30/ti/dydaktyk/rezerwacje.php,
 * rezerwacja.php, rezerwacjeKalendarz.php, rezerwacjaPdf.php, kolejka.php; logika: modules/ti_bookings). Z konta SZO
 * wchodzi się tam przez mostek office_enter.php. Dawny kod: historia gita. API (api/v1/karty30.php) bez zmian.
 */
$q = static function (array $keys): string {
    $o = [];
    foreach ($keys as $k => $as) { $v = (string)($_GET[$k] ?? ''); if ($v !== '' && preg_match('/^[A-Za-z0-9_.\-]{1,40}$/', $v)) $o[] = $as . '=' . $v; }
    return $o ? '&' . implode('&', $o) : '';
};
header('Location: ../ti/dydaktyk/office_enter.php?next=' . rawurlencode('rezerwacjaPdf.php?x=1' . $q(['id' => 'id', 'auto' => 'auto'])));
exit;
