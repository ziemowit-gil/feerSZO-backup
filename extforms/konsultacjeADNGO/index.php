<?php
/**
 * extforms/konsultacjeADNGO/index.php
 * Publiczny punkt wejścia formularza „Karta doradztwa" pod adresem
 *   /extforms/konsultacjeADNGO
 *
 * Cienki wrapper: ustawia adres formularza na bieżącą ścieżkę i dołącza
 * współdzieloną logikę z konsultacje/form.php (bez logowania).
 */
$_root = dirname(__DIR__, 2);                 // korzeń aplikacji
require_once $_root . '/config.php';

// Ten sam adres dla wyświetlenia, POST i przekierowania po zapisie.
$CC_FORM_URL = APP_URL . '/extforms/konsultacjeADNGO/';

require $_root . '/konsultacje/form.php';
