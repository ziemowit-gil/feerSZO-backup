<?php
/**
 * Roundcube poczta.feer.org.pl (MyDevil s89, konto feer).
 * Źródło: ~/roundcube/config/config.inc.php (symlink do app/config/).
 * Sekrety w ~/roundcube/secrets (nie wypisywać). Opis: ~/roundcube/README.md.
 */
$config = [];

$__sec = static function (string $n): string {
    return trim((string) @file_get_contents('/usr/home/feer/roundcube/secrets/' . $n));
};

// Baza MySQL (mysql89)
$config['db_dsnw'] = 'mysql://m1045_roundcube:' . rawurlencode($__sec('db-password'))
    . '@mysql89.mydevil.net/m1045_roundcube';

// Serwery poczty: @feer.org.pl = dPoczta (dhosting), @stud.feer.org.pl = sdata.
// Klucze = host IMAP, wartości = domeny (rdzeń umie też sam wybrać po domenie).
$config['imap_host'] = [
    'ssl://imap.dpoczta.pl:993'      => 'Poczta FEER (@feer.org.pl)',
    'ssl://titan.sdata.net.pl:993'   => 'Poczta kursanta (@stud.feer.org.pl)',
];
// Plugin feer_login: domena loginu => host IMAP (wybór bez listy na ekranie)
$config['feer_login_domains'] = [
    'feer.org.pl'      => 'ssl://imap.dpoczta.pl:993',
    'stud.feer.org.pl' => 'ssl://titan.sdata.net.pl:993',
];
$config['feer_login_default_domain'] = 'feer.org.pl';

// SMTP wg serwera IMAP, na który zalogowano
$config['smtp_host'] = [
    'imap.dpoczta.pl'    => 'tls://smtp.dpoczta.pl:587',
    'titan.sdata.net.pl' => 'ssl://titan.sdata.net.pl:465',
];
$config['smtp_user'] = '%u';
$config['smtp_pass'] = '%p';

$config['des_key'] = $__sec('des-key');
$config['support_url'] = 'https://szo.feer.org.pl/uslugi-it';
$config['product_name'] = 'Poczta FEER';
$config['language'] = 'pl_PL';
$config['timezone'] = 'Europe/Warsaw';

$config['skin'] = 'elastic';
$config['skins_allowed'] = ['elastic'];
$config['layout'] = 'widescreen';
$config['skin_logo'] = ['login' => 'plugins/feer_theme/logo.png'];

$config['use_https'] = true;
$config['force_https'] = true;
$config['session_lifetime'] = 30;
$config['login_rate_limit'] = 3;          // prób na minutę z jednego IP
$config['ip_check'] = true;
$config['x_frame_options'] = 'sameorigin';
$config['enable_installer'] = false;
$config['log_driver'] = 'file';
$config['log_dir'] = '/usr/home/feer/roundcube/logs/';
$config['temp_dir'] = '/usr/home/feer/roundcube/tmp/';
$config['max_message_size'] = '25M';
$config['draft_autosave'] = 60;
$config['mime_param_folding'] = 0;

$config['plugins'] = [
    'feer_login',            // wybór serwera po domenie (własny)
    'feer_theme',            // barwy FEER na skinie Elastic (własny)
    'feer_signature',        // stopka z SZO w tożsamości przy logowaniu (własny)
    'carddav',               // książki adresowe z chmury nc.feer.org.pl
    'archive',
    'zipdownload',
    'markasjunk',
    'newmail_notifier',
    'attachment_reminder',
    'emoticons',
    'hide_blockquote',
    'identicon',
    'show_additional_headers',
];

// Domena nowych tożsamości wg serwera IMAP
$config['mail_domain'] = [
    'imap.dpoczta.pl'    => 'feer.org.pl',
    'titan.sdata.net.pl' => 'stud.feer.org.pl',
];

// Stopka z SZO (plugin feer_signature, endpoint api/internal/rc_signature.php)
$config['feer_signature_url'] = 'https://szo.feer.org.pl/api/internal/rc_signature.php';
$config['feer_signature_key_file'] = '/usr/home/feer/roundcube/secrets/szo-key';
// Pisanie w HTML, żeby stopka z logo nie zamieniała się w tekst
$config['htmleditor'] = 1;
