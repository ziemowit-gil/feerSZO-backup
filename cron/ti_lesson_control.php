<?php
/**
 * cron/ti_lesson_control.php — agent Kontroli Lekcji i Wpisów TI (rejestr: modules/cron_dispatcher/logic/registry.php).
 *
 * Co godzinę oznacza statusy wpisów z ostatnich ti_lc_lookback_days dni, a potem — tylko gdy włączone
 * w panelu (Kontrola wpisów → Ustawienia) — wysyła przypomnienia prowadzącym (raz dziennie, od ti_lc_remind_hour)
 * i raport nadzorującemu (raz na okres, od ti_lc_report_hour). Ręcznie: php cli/tiLessonControl.php --help.
 */
if (php_sapi_name() !== 'cli') { die("Dostęp dozwolony tylko z poziomu konsoli (CLI).\n"); }

if (!defined('APP_CLI')) define('APP_CLI', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/modules/ti_lesson_control/logic/lessonControlCli.php';

exit(tiLcCliMain(['--notify', '--cron']));
