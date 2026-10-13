<?php
/**
 * karty30/ti/komunikacja.php — PRZENIESIONE.
 *
 * Wysyłka e-mail / SMS do kursantów (i opiekunów małoletnich) jest w panelu dydaktyka:
 * karty30/ti/dydaktyk/index.php?tab=komunikacja (_tab_komunikacja.php) — te same filtry (grupa, prowadzący, dzień)
 * plus „wybrane osoby” i „wszyscy”, szablony, podgląd odbiorców i log k30_ti_comm_log.
 * Z konta SZO wchodzi się tam przez mostek office_enter.php. Dawny kod: historia gita.
 */
header('Location: dydaktyk/office_enter.php?next=' . rawurlencode('index.php?tab=komunikacja'));
exit;
