<?php
/**
 * tozsamosc/uzytkownicy.php — Zarządzanie użytkownikami w chrome Systemu Tożsamości.
 *
 * Cienki wrapper: definiuje stałe TZ_USERS_CHROME / TZ_USERS_URL, a następnie
 * wywołuje pełną logikę admin/users.php (która samo wykrywa stałe i używa
 * odpowiedniego chrome). Daje to jeden plik logiki bez duplikacji kodu.
 */
require_once dirname(__DIR__) . '/config.php';
define('TZ_USERS_URL',    APP_URL . '/tozsamosc/uzytkownicy.php');
define('TZ_USERS_CHROME', true);
require_once dirname(__DIR__) . '/admin/users.php';
