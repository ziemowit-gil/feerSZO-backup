<?php
/**
 * feer_cloud_dav — autokonfiguracja kalendarza (kolab/calendar) z chmury FEER.
 *
 * Gdy poczta jest połączona z chmurą (preferencja nextcloud_login z feer_cloud_save,
 * Login Flow v2 przez konto FEER/Logto), kalendarz używa sterownika caldav na
 * nc.feer.org.pl z hasłem aplikacji podanym w adresie (kolab_dav_client czyta
 * user:pass z URL-a). Bez połączenia: sterownik database (kalendarz w bazie Roundcube).
 *
 * MUSI być na liście pluginów PRZED calendar, a calendar_driver/calendar_caldav_server
 * NIE mogą być w config.inc.php wtyczki calendar (jej load_config nadpisałby ustawienie).
 * Kontakty (CardDAV) ustawia config carddav (config/carddav.config.inc.php).
 */
class feer_cloud_dav extends rcube_plugin
{
    public function init(): void
    {
        $this->add_hook('ready', [$this, 'ready']);
        $rc = rcmail::get_instance();
        if (empty($rc->user) || empty($rc->user->ID)) {
            return;
        }
        $login = (array) ($rc->user->get_prefs()['nextcloud_login'] ?? []);
        $srv   = rtrim((string) $rc->config->get('feer_cloud_server', ''), '/');
        if ($srv === '' || empty($login['loginName']) || empty($login['appPassword'])) {
            return;
        }
        $p = parse_url($srv);
        $url = ($p['scheme'] ?? 'https') . '://'
            . rawurlencode((string) $login['loginName']) . ':' . rawurlencode((string) $login['appPassword'])
            . '@' . ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '')
            . rtrim((string) ($p['path'] ?? ''), '/') . '/remote.php/dav';

        $rc->config->set('calendar_driver', 'caldav');
        $rc->config->set('calendar_caldav_server', $url);
    }

    /**
     * Po połączeniu z chmurą (feer_cloud_save ustawia flagę): jednorazowo odśwież presety
     * carddav, żeby „Chmura FEER” pojawiła się bez ponownego logowania. Tu carddav ma już
     * config liczony z aktualnych preferencji.
     */
    public function ready(array $args): array
    {
        // raz na sesję przy połączonej chmurze (też w sesjach sprzed połączenia albo sprzed tej wtyczki)
        // i od razu po połączeniu (flaga z feer_cloud_save)
        $rc = rcmail::get_instance();
        $connected = $rc->config->get('calendar_driver') === 'caldav';
        if (!empty($_SESSION['feer_carddav_resync']) || ($connected && empty($_SESSION['feer_carddav_synced']))) {
            unset($_SESSION['feer_carddav_resync']);
            $_SESSION['feer_carddav_synced'] = 1;
            try {
                $cd = rcmail::get_instance()->plugins->get_plugin('carddav');
                if ($cd && method_exists($cd, 'afterLogin')) {
                    $cd->afterLogin();
                }
            } catch (\Throwable $e) {
                rcube::raise_error(['code' => 500, 'message' => 'feer_cloud_dav carddav: ' . $e->getMessage()], true, false);
            }
        }
        return $args;
    }
}
