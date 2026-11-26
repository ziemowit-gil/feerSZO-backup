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
$config['skin_logo'] = [
    'login' => 'plugins/feer_theme/logo.png',
    '*'     => 'plugins/feer_theme/mark.png',   // znak w lewym górnym rogu
];

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
    'feer_directory',        // książka „Fundacja FEER” z katalogu SZO (własny, tylko @feer.org.pl)
    'feer_cloud_dav',        // kalendarz z chmury po połączeniu (PRZED calendar; własny)
    'libcalendaring',        // wymagane przez calendar
    'libkolab',              // wymagane przez calendar
    'calendar',              // kolab/calendar: database albo caldav z chmury (feer_cloud_dav)
    'managesieve',           // filtry i autoodpowiedź (tylko dPoczta; sdata nie ma ManageSieve)
    'nextcloud_attachments', // duże załączniki jako link do chmury
    'feer_cloud_save',       // połączenie z chmurą (Login Flow, Logto) + „Zapisz w chmurze” (własny)
    'contextmenu',           // menu pod prawym przyciskiem
    'thunderbird_labels',    // etykiety jak w Thunderbirdzie
    'persistent_login',      // „Zapamiętaj mnie” (tokeny, 7 dni)
    'twofactor_gauthenticator', // 2FA: kod TOTP z aplikacji (dobrowolne; GitHub b6afa8f44b)
    'mimeshield',            // S/MIME: podpis, weryfikacja, szyfrowanie (takenek/mimeshield 1.0.3)
    'feer_smime_ui',         // kłódka S/MIME (zielona/czerwona) zamiast opisów mimeshield, zwarta sekcja w oknie pisania (PO mimeshield; własny)
    'feer_cert',             // certyfikaty S/MIME z urzędu FEER (Ustawienia → Certyfikat FEER; własny)
    'feer_qsign',            // podpis certyfikatem kwalifikowanym przez SzoCert + PEM-HEART (PO mimeshield; własny)
    'identity_select',       // tożsamość nadawcy wg adresu, na który przyszła wiadomość
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

// Filtry (managesieve): serwer IMAP zalogowanego (%h), port 4190, STARTTLS.
// dPoczta: Dovecot Pigeonhole. sdata (titan) ManageSieve nie ma, @stud zobaczy błąd połączenia w Filtrach.
$config['managesieve_host'] = 'tls://%h';
$config['managesieve_port'] = 4190;
$config['managesieve_vacation'] = 1;   // zakładka Autoodpowiedź obok Filtrów
$config['managesieve_forward'] = 1;    // zakładka Przekierowanie

// Lista wiadomości domyślnie w wątkach (zamiast wtyczki threading_as_default)
$config['default_list_mode'] = 'threads';
$config['autoexpand_threads'] = 0;

// Zebrane adresy (wbudowane od 1.5, zamiast wtyczki automatic_addressbook)
$config['collected_recipients'] = true;
$config['collected_senders'] = true;

// feer_cloud_save: folder w chmurze (pod nim RRRR-MM); logowanie = połączenie nextcloud_attachments
// Chmura FEER dla własnych wtyczek (feer_cloud_save, feer_cloud_dav) — niezależnie od tego, czy
// nextcloud_attachments (który ma własne nextcloud_attachment_server) jest w danym zadaniu załadowany
$config['feer_cloud_server'] = 'https://nc.feer.org.pl';
$config['feer_cloud_save_folder'] = 'Załączniki z poczty/Odebrane';
// Zachęta „Połącz pocztę z chmurą” po logowaniu: tylko skrzynki na tych serwerach IMAP (@feer.org.pl)
$config['feer_cloud_prompt_hosts'] = ['imap.dpoczta.pl'];

// Kalendarz: domyślnie w bazie Roundcube; po połączeniu z chmurą feer_cloud_dav przełącza na caldav
$config['calendar_driver'] = 'database';

// Link pod formularzem logowania (feer_login): odzyskanie dostępu / zmiana hasła w SZO
$config['feer_login_recovery_url'] = 'https://szo.feer.org.pl/nowehaslo';

// Foldery specjalne ustala system, użytkownik ich nie zmienia (Ustawienia → Foldery specjalne znika).
// Nazwy jak na dPoczcie (Dovecot, separator „.”). sdata (@stud): jeśli ma inne nazwy, Roundcube
// utworzy brakujące foldery o tych nazwach.
$config['drafts_mbox']  = 'Drafts';
$config['sent_mbox']    = 'Sent';
$config['junk_mbox']    = 'SPAM';
$config['trash_mbox']   = 'Trash';
$config['archive_mbox'] = 'Archives';
$config['create_default_folders'] = true;
$config['dont_override'] = ['drafts_mbox', 'sent_mbox', 'junk_mbox', 'trash_mbox', 'archive_mbox'];

// Książka adresowa „Fundacja FEER” (feer_directory): katalog SZO, klucz jak przy stopkach
$config['feer_directory_url'] = 'https://szo.feer.org.pl/api/internal/rc_directory.php';
$config['feer_directory_hosts'] = ['imap.dpoczta.pl'];
$config['feer_directory_ttl'] = 3600;

// Urząd certyfikacji FEER (feer_cert): FEER S/MIME CA 1 ← FEER Root CA (tools/feer_ca_init.php)
$config['feer_cert_ca_dir'] = '/usr/home/feer/roundcube/secrets/feer-ca';
$config['feer_cert_hosts'] = ['imap.dpoczta.pl'];
$config['feer_cert_days'] = 397;
$config['feer_cert_max_per_30d'] = 3;
$config['feer_cert_root_url'] = 'https://szo.feer.org.pl/assets/ca/feer-root-ca.crt';
