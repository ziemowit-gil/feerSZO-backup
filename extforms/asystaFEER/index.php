<?php
/**
 * extforms/asystaFEER/index.php
 * Publiczny punkt wejścia formularza „Zgłoszenie asysty i specjalnych potrzeb"
 * pod adresem /extforms/asystaFEER
 *
 * Cienki wrapper: ustawia adres formularza na bieżącą ścieżkę i dołącza
 * współdzieloną logikę z asysta/form.php (bez logowania).
 */
$_root = dirname(__DIR__, 2);                 // korzeń aplikacji
require_once $_root . '/config.php';

// Ten sam adres dla wyświetlenia, POST i przekierowania po zapisie.
$ASR_FORM_URL = APP_URL . '/extforms/asystaFEER/';

require $_root . '/asysta/form.php';
