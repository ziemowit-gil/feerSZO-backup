<?php
/**
 * carddav (rcmcarddav 5.x) — kontakty z chmury FEER (Nextcloud nc.feer.org.pl), autokonfiguracja.
 *
 * Preset „Chmura FEER” powstaje sam, gdy poczta jest połączona z chmurą (preferencja
 * nextcloud_login z feer_cloud_save: Login Flow v2 przez konto FEER/Logto → hasło aplikacji).
 * Bez połączenia presetu nie ma (carddav usuwa wtedy konto z presetu przy logowaniu).
 * Plik czyta carddav leniwie (po zalogowaniu), więc rcmail->user jest dostępny.
 */
$prefs['_GLOBAL']['pwstore_scheme'] = 'encrypted';   // hasła szyfrowane des_key
$prefs['_GLOBAL']['loglevel'] = \Psr\Log\LogLevel::WARNING;

$__rc = rcmail::get_instance();
$__nl = (!empty($__rc->user) && !empty($__rc->user->ID))
    ? (array) ($__rc->user->get_prefs()['nextcloud_login'] ?? [])
    : [];

if (!empty($__nl['loginName']) && !empty($__nl['appPassword'])) {
    $prefs['chmura'] = [
        'accountname'   => 'Chmura FEER',
        'discovery_url' => 'https://nc.feer.org.pl/remote.php/dav/',
        'username'      => (string) $__nl['loginName'],
        'password'      => (string) $__nl['appPassword'],
        'fixed'         => ['accountname', 'discovery_url', 'username', 'password'],
        'active'        => true,
        'readonly'      => false,
        'refresh_time'  => '00:30:00',
        'rediscover_time' => '24:00:00',
        'hide'          => false,
    ];
}
unset($__rc, $__nl);
