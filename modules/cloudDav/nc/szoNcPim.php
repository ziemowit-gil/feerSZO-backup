<?php
/**
 * modules/cloudDav/nc/szoNcPim.php — narzędzie uruchamiane PHP Nextclouda (jak occ) na serwerze chmury, tylko z wiersza poleceń.
 *
 * Nextcloud 35 ma occ calendar:import (wydarzenia), ale nie ma polecenia do kontaktów, a dav:create-calendar nie ustawia nazwy
 * wyświetlanej. Ten skrypt robi brakujące kroki przez wewnętrzne API aplikacji dav (CalDavBackend, CardDavBackend), tak jak
 * cron.php Nextclouda (require lib/base.php). Wywołuje go modules/cloudDav/lib/NcPim.php:
 *
 *   php84 -d apc.enable_cli=1 szoNcPim.php <katalog Nextclouda> ensure-calendar    <uid> <uri> <nazwa> [kolor]
 *   php84 -d apc.enable_cli=1 szoNcPim.php <katalog Nextclouda> ensure-addressbook <uid> <uri> <nazwa>
 *   php84 -d apc.enable_cli=1 szoNcPim.php <katalog Nextclouda> import-vcards      <uid> <uri> <plik.vcf>
 *
 * Wynik: jedna linia JSON na stdout ({"ok":true,...} albo {"ok":false,"error":"..."}), kod wyjścia 0 albo różny od 0.
 * Karty: adres = UID karty + „.vcf” (UID „outlook-<sha1>” z PimVcard), istniejąca karta jest nadpisywana (bez duplikatów).
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

$say = static function (array $a, int $code = 0): never {
    echo json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
    exit($code);
};
[, $root, $cmd, $uid] = $argv + [null, '', '', ''];
if (!is_string($root) || !is_file(rtrim($root, '/') . '/lib/base.php')) $say(['ok' => false, 'error' => 'brak Nextclouda w ' . $root], 2);
if (!in_array($cmd, ['ensure-calendar', 'ensure-addressbook', 'import-vcards'], true)) $say(['ok' => false, 'error' => 'nieznane polecenie'], 2);
if (!preg_match('/^[A-Za-z0-9._@+\-]{1,128}$/', (string)$uid)) $say(['ok' => false, 'error' => 'nieprawidłowy login'], 2);
$uri = (string)($argv[4] ?? '');
if (!preg_match('/^[a-z0-9][a-z0-9\-]{0,99}$/', $uri)) $say(['ok' => false, 'error' => 'nieprawidłowy adres kolekcji'], 2);

try {
    require_once rtrim($root, '/') . '/lib/base.php';
    \OCP\Server::get(\OCP\App\IAppManager::class)->loadApp('dav');
    if (!\OCP\Server::get(\OCP\IUserManager::class)->userExists($uid)) $say(['ok' => false, 'error' => 'nie ma konta ' . $uid . ' w chmurze'], 3);
    $principal = 'principals/users/' . $uid;

    if ($cmd === 'ensure-calendar') {
        $cal = \OCP\Server::get(\OCA\DAV\CalDAV\CalDavBackend::class);
        $ex = $cal->getCalendarByUri($principal, $uri);
        if ($ex) $say(['ok' => true, 'id' => (int)$ex['id'], 'created' => false]);
        $props = ['{DAV:}displayname' => mb_substr((string)($argv[5] ?? $uri), 0, 200)];
        $color = (string)($argv[6] ?? '');
        if (preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) $props['{http://apple.com/ns/ical/}calendar-color'] = $color;
        $id = $cal->createCalendar($principal, $uri, $props);
        $say(['ok' => true, 'id' => (int)$id, 'created' => true]);
    }

    $card = \OCP\Server::get(\OCA\DAV\CardDAV\CardDavBackend::class);
    $ab = $card->getAddressBooksByUri($principal, $uri);
    if ($cmd === 'ensure-addressbook') {
        if ($ab) $say(['ok' => true, 'id' => (int)$ab['id'], 'created' => false]);
        $id = $card->createAddressBook($principal, $uri, ['{DAV:}displayname' => mb_substr((string)($argv[5] ?? $uri), 0, 200)]);
        $say(['ok' => true, 'id' => (int)$id, 'created' => true]);
    }

    // import-vcards
    if (!$ab) $say(['ok' => false, 'error' => 'nie ma książki adresowej ' . $uri], 4);
    $file = (string)($argv[5] ?? '');
    $raw = is_file($file) ? (string)file_get_contents($file) : '';
    if ($raw === '') $say(['ok' => false, 'error' => 'pusty plik'], 4);
    preg_match_all('/BEGIN:VCARD\r?\n.*?END:VCARD\r?\n?/s', $raw, $m);
    $n = ['created' => 0, 'updated' => 0, 'failed' => 0, 'errors' => []];
    foreach ($m[0] as $vc) {
        $unfolded = (string)preg_replace("/\r?\n[ \t]/", '', $vc);
        $cardUid = preg_match('/^UID:(.+)$/mi', $unfolded, $u) ? trim($u[1]) : '';
        if (!preg_match('/^[A-Za-z0-9._\-]{1,180}$/', $cardUid)) { $n['failed']++; continue; }
        $cardUri = $cardUid . '.vcf';
        $data = str_replace(["\r\n", "\n"], ["\n", "\r\n"], $vc);
        try {
            if ($card->getCard((int)$ab['id'], $cardUri)) { $card->updateCard((int)$ab['id'], $cardUri, $data); $n['updated']++; }
            else { $card->createCard((int)$ab['id'], $cardUri, $data); $n['created']++; }
        } catch (\Throwable $e) {
            $n['failed']++;
            if (count($n['errors']) < 5) $n['errors'][] = $cardUid . ': ' . mb_substr($e->getMessage(), 0, 160);
        }
    }
    $say(['ok' => true] + $n);
} catch (\Throwable $e) {
    $say(['ok' => false, 'error' => get_class($e) . ': ' . mb_substr($e->getMessage(), 0, 300)], 5);
}
