<?php
// Duże załączniki trafiają do chmury jako link. Hasło chmury ≠ hasło poczty, więc od razu
// logowanie Nextcloud Login Flow (jednorazowa zgoda w chmurze, potem hasło aplikacji).
$config['nextcloud_attachment_server'] = 'https://nc.feer.org.pl';
$config['nextcloud_attachment_username'] = '%u';
$config['nextcloud_attachment_dont_try_mail_password'] = true;
$config['nextcloud_attachment_folder'] = 'Załączniki z poczty';
$config['nextcloud_attachment_attached_html_lang'] = 'pl';
$config['nextcloud_attachment_folder_layout'] = 'date:Y/m';
$config['nextcloud_attachment_folder_layout_locked'] = true;
$config['nextcloud_attachment_softlimit'] = '20M';
$config['nextcloud_attachment_behavior'] = 'prompt';
$config['nextcloud_attachment_checksum'] = 'sha256';
$config['nextcloud_attachment_password_protected_links'] = false;
$config['nextcloud_attachment_password_protected_links_locked'] = false;
$config['nextcloud_attachment_expire_links'] = false;
$config['nextcloud_attachment_expire_links_locked'] = false;
$config['nextcloud_attachment_verify_https'] = true;
