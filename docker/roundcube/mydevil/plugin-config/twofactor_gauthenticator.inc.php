<?php
// twofactor_gauthenticator (alexandregz, commit b6afa8f44b z GitHuba — nie ma na Packagist;
// instalacja za zgodą użytkownika 2026-11-26). 2FA kodem TOTP z aplikacji. Dobrowolne:
// Ustawienia → 2-etapowa weryfikacja. Wymuszenie: force_enrollment_users.
$rcmail_config['force_enrollment_users'] = false;
$rcmail_config['force_enrollment_users_list'] = [];
$rcmail_config['whitelist'] = [];
$rcmail_config['allow_save_device_30days'] = true;
$rcmail_config['twofactor_formfield_as_password'] = true;
$rcmail_config['users_allowed_2FA'] = ['.*'];
$rcmail_config['enable_fail_logs'] = true;
$rcmail_config['twofactor_pref_encrypt'] = true;
$rcmail_config['twofactor_lockout_delays'] = [1, 2, 5, 20, 60, 300, 600];
$rcmail_config['twofactor_lockout'] = 900;
$rcmail_config['twofactor_trusted_proxies'] = [];
