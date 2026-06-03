<?php
defined('MOODLE_INTERNAL') || die();

$string['pluginname']              = 'FEER NGO — Volunteer Sync';
$string['plugindesc']              = 'Synchronises volunteer accounts from the FEER NGO contract management system with Moodle.';

// Settings
$string['settings_api']            = 'FEER API connection';
$string['feer_url']                = 'FEER system URL';
$string['feer_url_desc']           = 'Base URL of the FEER NGO system, e.g. https://system.myorg.pl';
$string['feer_api_key']            = 'API key';
$string['feer_api_key_desc']       = 'API key with <code>volunteers:read</code> permission. Generate it in FEER Admin → API Keys.';
$string['settings_sync']           = 'Synchronisation behaviour';
$string['auto_enroll_courses']     = 'Auto-enrol all volunteers in courses';
$string['auto_enroll_courses_desc']= 'Comma-separated list of Moodle course IDs. Every active volunteer will be enrolled in these courses.';
$string['action_course_map']       = 'Action → course mapping';
$string['action_course_map_desc']  = 'JSON object mapping FEER action_id to Moodle course_id, e.g. {"12": 5, "15": 8}';
$string['suspend_on_end']          = 'Suspend account when contract ends';
$string['suspend_on_end_desc']     = 'Automatically suspend the Moodle account when a volunteer\'s contract status changes to ended/cancelled.';
$string['create_if_missing']       = 'Create account if not found';
$string['create_if_missing_desc']  = 'Create a new Moodle account for volunteers who do not yet have one.';
$string['sync_page_heading']       = 'FEER NGO — Volunteer Sync';
$string['run_sync_now']            = 'Run sync now';
$string['sync_log']                = 'Sync log';
$string['last_sync']               = 'Last sync';
$string['task_sync']               = 'Sync FEER volunteers';
$string['sync_result_ok']          = 'Sync completed: {$a->created} created, {$a->updated} updated, {$a->suspended} suspended, {$a->errors} errors.';
$string['sync_standalone']         = 'Sync standalone volunteers (without contracts)';
$string['sync_standalone_desc']    = 'When enabled, accounts flagged as "standalone volunteer" in FEER will also be synced to Moodle.';
$string['writeback_login']         = 'Write Moodle login back to FEER';
$string['writeback_login_desc']    = 'After creating a Moodle account, post the username back to the FEER system (/api/v1/moodle_user_update.php). The volunteer will then see their Moodle login in the FEER panel.';
