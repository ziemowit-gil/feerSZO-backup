<?php
/**
 * Haki Moodle dla local_feer_sync.
 */
defined('MOODLE_INTERNAL') || die();

/**
 * Dodaj link do panelu synchronizacji w bloku nawigacji admina.
 */
function local_feer_sync_extend_navigation_user(navigation_node $nav, stdClass $user, context $ctx): void {
    // Nie dodajemy do nawigacji użytkownika
}

/**
 * Dodaj link do strony Admin → Wtyczki lokalne.
 */
function local_feer_sync_extend_settings_navigation(navigation_node $nav, context $ctx): void {
    global $PAGE;
    if (!has_capability('moodle/site:config', context_system::instance())) {
        return;
    }
    $url = new moodle_url('/local/feer_sync/admin/sync.php');
    if ($PAGE->url->compare($url, URL_MATCH_BASE)) {
        $nav->add(get_string('pluginname', 'local_feer_sync'), $url, navigation_node::TYPE_SETTING);
    }
}
