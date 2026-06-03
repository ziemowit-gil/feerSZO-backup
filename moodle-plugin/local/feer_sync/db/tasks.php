<?php
/**
 * Definicja zaplanowanego zadania synchronizacji.
 * Domyślnie: codziennie o 03:00.
 */
defined('MOODLE_INTERNAL') || die();

$tasks = [
    [
        'classname'   => '\local_feer_sync\task\sync_task',
        'blocking'    => 0,
        'minute'      => '0',
        'hour'        => '3',
        'day'         => '*',
        'month'       => '*',
        'dayofweek'   => '*',
        'disabled'    => 0,
    ],
];
