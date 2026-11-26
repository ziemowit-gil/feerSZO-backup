<?php
/**
 * carddav (rcmcarddav 5.x) — chmura FEER (Nextcloud nc.feer.org.pl).
 * Hasło do chmury jest inne niż do poczty, więc użytkownik dodaje konto sam
 * (Ustawienia → Kontakty CardDAV → „Chmura FEER”), najlepiej z hasłem aplikacji
 * z https://szo.feer.org.pl/kalendarz-kontakty.
 */
$prefs['_GLOBAL']['pwstore_scheme'] = 'encrypted';   // hasła szyfrowane des_key
$prefs['_GLOBAL']['loglevel'] = \Psr\Log\LogLevel::WARNING;

$prefs['chmura'] = [
    'accountname'  => 'Chmura FEER',
    'discovery_url' => 'https://nc.feer.org.pl/remote.php/dav/',
    'fixed'        => ['discovery_url'],
    'active'       => true,
    'readonly'     => false,
    'refresh_time' => '00:30:00',
    'hide'         => false,
];
