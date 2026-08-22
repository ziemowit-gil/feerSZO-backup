<?php
/**
 * config_local.php — konfiguracja SquirrelMaila brana z ENV kontenera.
 *
 * SquirrelMail wczytuje ten plik PO config.php i nadpisuje jego wartości, więc
 * nie trzeba generować config.php interaktywnym conf.pl w obrazie.
 *
 * UWAGA — to nie zadziała ze skrzynkami Microsoft 365. SquirrelMail 1.4.x zna
 * tylko logowanie hasłem (Basic Auth) do IMAP/SMTP, a Microsoft wyłączył je
 * w Exchange Online na stałe. Ten kontener ma sens wyłącznie dla skrzynek na
 * innym serwerze IMAP (własny Dovecot, poczta u zewnętrznego dostawcy).
 * Szczegóły i uzasadnienie: README.md obok.
 */

$org_name  = getenv('SQM_ORG_NAME')  ?: 'Poczta';
$org_logo  = '';
$domain    = getenv('SQM_DOMAIN_MAIL') ?: 'example.org';

// ── IMAP ─────────────────────────────────────────────────────────────────────
$imapServerAddress = getenv('SQM_IMAP_HOST') ?: 'localhost';
$imapPort          = (int)(getenv('SQM_IMAP_PORT') ?: 143);
$use_imap_tls      = (int)(getenv('SQM_IMAP_TLS') ?: 0);   // 0 = brak, 1 = SSL/TLS, 2 = STARTTLS
$imap_server_type  = getenv('SQM_IMAP_TYPE') ?: 'other';
$optional_delimiter = 'detect';

// ── SMTP ─────────────────────────────────────────────────────────────────────
$useSendmail       = false;
$smtpServerAddress = getenv('SQM_SMTP_HOST') ?: 'localhost';
$smtpPort          = (int)(getenv('SQM_SMTP_PORT') ?: 587);
$use_smtp_tls      = (int)(getenv('SQM_SMTP_TLS') ?: 2);
$smtp_auth_mech    = getenv('SQM_SMTP_AUTH') ?: 'login';
$pop_before_smtp   = false;

// ── Dane użytkowników POZA webrootem (w 1.4.x to jedyna ochrona tych plików) ──
$data_dir          = '/var/local/squirrelmail/data/';
$attachment_dir    = '/var/local/squirrelmail/attach/';
$default_folder_prefix = '';
$trash_folder      = 'INBOX.Trash';
$sent_folder       = 'INBOX.Sent';
$draft_folder      = 'INBOX.Drafts';
$default_move_to_trash = true;
$default_charset   = 'utf-8';
$squirrelmail_default_language = 'pl_PL';
$default_use_javascript_addr_book = false;

// ── Wygląd / zachowanie ──────────────────────────────────────────────────────
$motd = 'To awaryjny, bardzo prosty klient poczty. Jeśli masz skrzynkę '
      . '@feer.org.pl, użyj Outlooka, Roundcube albo SnappyMaila — '
      . 'ten klient nie obsługuje logowania kontem Microsoft.';
$provider_name = $org_name;
$provider_uri  = getenv('SQM_BACK_URL') ?: '';
$disable_server_sort = true;
$allow_thread_sort   = false;
$default_sub_of_inbox = true;
$check_referrer = 'SERVER_NAME';   // ochrona przed CSRF na formularzach 1.4.x
