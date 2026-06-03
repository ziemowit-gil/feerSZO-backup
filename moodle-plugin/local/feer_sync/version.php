<?php
/**
 * local_feer_sync — Synchronizacja wolontariuszy z systemu FEER NGO.
 *
 * Instalacja: skopiuj katalog feer_sync do local/ w katalogu głównym Moodle,
 * a następnie przejdź do Admin → Powiadomienia, aby uruchomić migrację bazy.
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'local_feer_sync';
$plugin->version   = 2026060300;       // YYYYMMDDXX
$plugin->requires  = 2022041900;       // Moodle 4.0+
$plugin->maturity  = MATURITY_STABLE;
$plugin->release   = '1.0.0';
