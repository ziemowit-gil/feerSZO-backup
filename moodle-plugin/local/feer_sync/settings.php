<?php
/**
 * Ustawienia pluginu local_feer_sync w panelu administracyjnym Moodle.
 * Admin → Wtyczki → Lokalne wtyczki → FEER NGO — Synchronizacja wolontariuszy
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {

    $settings = new admin_settingpage(
        'local_feer_sync',
        get_string('pluginname', 'local_feer_sync')
    );

    $ADMIN->add('localplugins', $settings);

    // ── Połączenie z API FEER ─────────────────────────────────────────────────
    $settings->add(new admin_setting_heading(
        'local_feer_sync/heading_api',
        get_string('settings_api', 'local_feer_sync'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'local_feer_sync/feer_url',
        get_string('feer_url', 'local_feer_sync'),
        get_string('feer_url_desc', 'local_feer_sync'),
        '',
        PARAM_URL
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'local_feer_sync/feer_api_key',
        get_string('feer_api_key', 'local_feer_sync'),
        get_string('feer_api_key_desc', 'local_feer_sync'),
        ''
    ));

    // Link do panelu synchronizacji
    $sync_url = new moodle_url('/local/feer_sync/admin/sync.php');
    $settings->add(new admin_setting_heading(
        'local_feer_sync/heading_sync_link',
        '',
        html_writer::link($sync_url, '→ ' . get_string('run_sync_now', 'local_feer_sync'),
            ['class' => 'btn btn-primary btn-sm'])
    ));

    // ── Zachowanie synchronizacji ─────────────────────────────────────────────
    $settings->add(new admin_setting_heading(
        'local_feer_sync/heading_sync',
        get_string('settings_sync', 'local_feer_sync'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'local_feer_sync/auto_enroll_courses',
        get_string('auto_enroll_courses', 'local_feer_sync'),
        get_string('auto_enroll_courses_desc', 'local_feer_sync'),
        '',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtextarea(
        'local_feer_sync/action_course_map',
        get_string('action_course_map', 'local_feer_sync'),
        get_string('action_course_map_desc', 'local_feer_sync'),
        '{}',
        PARAM_RAW
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_feer_sync/create_if_missing',
        get_string('create_if_missing', 'local_feer_sync'),
        get_string('create_if_missing_desc', 'local_feer_sync'),
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_feer_sync/suspend_on_end',
        get_string('suspend_on_end', 'local_feer_sync'),
        get_string('suspend_on_end_desc', 'local_feer_sync'),
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_feer_sync/sync_standalone',
        get_string('sync_standalone', 'local_feer_sync'),
        get_string('sync_standalone_desc', 'local_feer_sync'),
        0
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_feer_sync/writeback_login',
        get_string('writeback_login', 'local_feer_sync'),
        get_string('writeback_login_desc', 'local_feer_sync'),
        1
    ));

    // Callback URL — wyświetlany tylko informacyjnie (wyliczany automatycznie z feer_url)
    $feer_url_now = rtrim(get_config('local_feer_sync', 'feer_url') ?: '', '/');
    $callback_url = $feer_url_now ? ($feer_url_now . '/api/v1/moodle_user_update.php') : '— ustaw najpierw adres URL systemu FEER —';
    $settings->add(new admin_setting_heading(
        'local_feer_sync/heading_callback',
        get_string('callback_url', 'local_feer_sync'),
        html_writer::tag('div',
            html_writer::tag('code', s($callback_url),
                ['style' => 'word-break:break-all;font-size:.85rem']) .
            html_writer::tag('p',
                get_string('callback_url_desc', 'local_feer_sync'),
                ['class' => 'text-muted small mt-1']),
            ['class' => 'p-2 bg-light border rounded mt-1'])
    ));
}
