<?php
/**
 * cli/tiLessonControl.php — Kontrola Lekcji i Wpisów TI z konsoli (opcje: --help).
 * Logika: modules/ti_lesson_control/logic/lessonControlCli.php. Agent CRON: cron/ti_lesson_control.php.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Tylko CLI.\n"); }

if (!defined('APP_CLI')) define('APP_CLI', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/modules/ti_lesson_control/logic/lessonControlCli.php';

exit(tiLcCliMain(array_slice($argv, 1)));
