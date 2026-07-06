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
// BEZ oauth_identity_uri (Graph /me) — to zapasowe zapytanie robione jest tym
// SAMYM tokenem co logowanie, wystawionym tylko dla audience
// outlook.office365.com (patrz komentarz przy oauth_scope niżej), więc Graph
// zawsze odrzuci je jako 401 "Invalid audience". Roundcube i tak najpierw
// próbuje wyciągnąć username z claimów id_token (JWT) — patrz
// oauth_identity_fields — do Graph sięga TYLKO gdy tam niczego nie znajdzie.
// UWAGA: celowo BEZ "https://graph.microsoft.com/Files.Read" tutaj — Microsoft
// identity platform (v2.0) wydaje token dla JEDNEGO "resource"/audience na
// żądanie; mieszanie scope'ów z outlook.office365.com i graph.microsoft.com
// w jednym authorize/token request jest nieobsługiwane (token wychodzi ważny
// tylko dla jednego z zasobów, w sposób nieprzewidywalny — patrz dokumentacja
// Microsoft: https://learn.microsoft.com/entra/identity-platform/v2-oauth2-auth-code-flow).
// Plugin onedrive_picker pobiera WŁASNY token Graph osobnym żądaniem
// grant_type=refresh_token (zob. onedrive_picker.php) — stąd offline_access
// poniżej jest wymagane (żeby w ogóle dostać refresh_token).
$config['oauth_scope'] = 'openid email profile offline_access '
    . 'https://outlook.office365.com/IMAP.AccessAsUser.All '
    . 'https://outlook.office365.com/SMTP.Send';
// Claimy OIDC z id_token (JWT) używane jako login IMAP/SMTP — "preferred_username"
// (obecny dzięki scope "profile") to zwykle UPN, "email" dzięki scope "email"
// (bywa nieobecny, zależnie od konfiguracji tenanta/kont). To NIE są nazwy pól
// z Microsoft Graph ("mail"/"userPrincipalName") — te nigdy nie występują w
// id_token i tylko wywoływały zbędne (i niedziałające) zapytanie do Graph.
$config['oauth_identity_fields'] = ['preferred_username', 'email'];
// UWAGA: BEZ auto-przekierowania (na życzenie) — użytkownik musi najpierw
// zobaczyć stronę logowania z komunikatem (plugin login_notice) o wymaganiu
// aktywnego konta Microsoft w organizacji, zanim kliknie przycisk logowania.
$config['oauth_login_redirect'] = false;

// Traefik terminuje TLS i przekazuje ruch do kontenera zwykłym HTTP — bez tego
// Roundcube "widzi" żądanie jako http:// i buduje redirect_uri OAuth z "http://"
// zamiast "https://", co Azure AD odrzuca (AADSTS50011 redirect_uri_mismatch),
// mimo że w Azure zarejestrowany jest poprawny "https://" URI. Middleware
// https-redirect (docker-compose.rc.yml) gwarantuje, że realny ruch zawsze
// dotarł jako https, więc ufamy nagłówkowi Traefika. W dev (bez Traefika)
// nagłówek nie jest ustawiony i to po prostu nic nie zmienia.
$config['use_https'] = (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

// ── Wygląd / pluginy ─────────────────────────────────────────────────────────
$config['skin'] = getenv('ROUNDCUBEMAIL_SKIN') ?: 'elastic';
$config['plugins'] = [
    'archive',
    'zipdownload',
    'managesieve',
    'onedrive_picker', // własny plugin — zob. plugins/onedrive_picker/
    'login_notice',    // własny plugin — komunikat na stronie logowania, zob. plugins/login_notice/

    // ── UX / wygoda ──────────────────────────────────────────────────────────
    'markasjunk',          // przycisk „Oznacz jako spam"
    'newmail_notifier',    // powiadomienie o nowej wiadomości (dźwięk/desktop wg ustawień usera)
    'attachment_reminder', // ostrzeżenie, gdy w treści jest „w załączniku" a nic nie dołączono
    'emoticons',           // emotikony w edytorze treści
    'hide_blockquote',     // zwija cytowaną treść w odpowiedziach (rozwijana po kliknięciu)

    // ── Foldery / organizacja ────────────────────────────────────────────────
    'subscriptions_option',      // przełącznik "używaj subskrypcji IMAP" w Ustawieniach
    'show_additional_headers',  // pokazuje dodatkowe nagłówki wiadomości w podglądzie
    'identicon',                 // automatyczna ikonka nadawcy bez zdjęcia (łatwiejsze rozpoznawanie w liście)
];

$config['language'] = 'pl_PL';
$config['product_name'] = 'Poczta FEER';
