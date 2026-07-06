<?php
/**
 * docker/roundcube/config.inc.php — konfiguracja Roundcube (moduł Poczta, FEER SZO)
 *
 * Montowany read-only do /var/roundcube/config/config.inc.php w kontenerze "rc"
 * (docker-compose.rc.yml). Obraz roundcube/roundcubemail generuje własny config
 * z ROUNDCUBEMAIL_* env tylko gdy ten plik NIE istnieje — więc ten plik ma
 * pierwszeństwo i to on jest źródłem prawdy.
 *
 * Skrzynki użytkowników żyją na Microsoft 365 — logowanie przez OAuth2
 * (login.microsoftonline.com), IMAP/SMTP przez XOAUTH2 (bez przechowywania
 * haseł w Roundcube). Wymaga osobnej rejestracji aplikacji Azure AD —
 * zob. docker/roundcube/README.md.
 */

$config = [];

// ── Baza danych Roundcube (własna, mała — preferencje, adresownik) ─────────
$config['db_dsnw'] = 'sqlite:////var/roundcube/db/roundcube.db?mode=0640';

// ── IMAP / SMTP (Microsoft 365) ─────────────────────────────────────────────
$config['default_host']  = getenv('RC_IMAP_HOST') ?: 'ssl://outlook.office365.com:993';
$config['imap_auth_type'] = 'XOAUTH2';

$config['smtp_server']    = getenv('RC_SMTP_HOST') ?: 'tls://smtp.office365.com:587';
$config['smtp_auth_type'] = 'XOAUTH2';
$config['smtp_user']      = '%u';
$config['smtp_pass']      = '%p';

// ── OAuth2 login → Microsoft 365 / Azure AD ─────────────────────────────────
$_rc_tenant = getenv('RC_TENANT_ID') ?: 'common';

$config['oauth_provider']      = 'outlook';
$config['oauth_provider_name'] = 'Microsoft 365';
$config['oauth_client_id']     = getenv('RC_OAUTH_CLIENT_ID') ?: '';
$config['oauth_client_secret'] = getenv('RC_OAUTH_CLIENT_SECRET') ?: '';
$config['oauth_auth_uri']      = "https://login.microsoftonline.com/{$_rc_tenant}/oauth2/v2.0/authorize";
$config['oauth_token_uri']     = "https://login.microsoftonline.com/{$_rc_tenant}/oauth2/v2.0/token";
$config['oauth_identity_uri']  = 'https://graph.microsoft.com/v1.0/me';
// Files.Read na końcu — pozwala pluginowi onedrive_picker użyć tego samego
// tokenu do Microsoft Graph, bez drugiego logowania.
$config['oauth_scope'] = 'openid email profile offline_access '
    . 'https://outlook.office365.com/IMAP.AccessAsUser.All '
    . 'https://outlook.office365.com/SMTP.Send '
    . 'https://graph.microsoft.com/Files.Read';
// Pole z odpowiedzi oauth_identity_uri używane jako login IMAP/SMTP.
$config['oauth_identity_fields'] = ['mail', 'userPrincipalName'];
// Od razu przekieruj do logowania Microsoft — bez własnego formularza Roundcube.
$config['oauth_login_redirect'] = true;

// ── Wygląd / pluginy ─────────────────────────────────────────────────────────
$config['skin'] = getenv('ROUNDCUBEMAIL_SKIN') ?: 'elastic';
$config['plugins'] = [
    'archive',
    'zipdownload',
    'managesieve',
    'onedrive_picker', // własny plugin — zob. plugins/onedrive_picker/
];

$config['language'] = 'pl_PL';
$config['product_name'] = 'Poczta FEER';
