<?php
/**
 * cli/wsCloudAdmins.php — administratorzy folderów obszarów Zadań w chmurze (modules/wsCloud, ustawienie ws_cloud_admins).
 * Administrator folderów dostaje cały folder „Obszary zadań” z pełnymi uprawnieniami (wszystkie obszary, też przyszłe).
 * Po każdej zmianie listy od razu uzgadnia udostępnienia (folder główny + foldery obszarów).
 *
 *   php cli/wsCloudAdmins.php                       # lista
 *   php cli/wsCloudAdmins.php --add=administrator   # dopisanie (login w chmurze, kilka po przecinku)
 *   php cli/wsCloudAdmins.php --remove=administrator
 *   php cli/wsCloudAdmins.php --set=administrator,ziemowit.gil
 *   php cli/wsCloudAdmins.php --sync                # tylko uzgodnienie udostępnień
 */
if (php_sapi_name() !== 'cli') { http_response_code(403); exit("Tylko z konsoli.\n"); }

$base = dirname(__DIR__);
require_once $base . '/config.php';
require_once $base . '/includes/db.php';
require_once $base . '/includes/functions.php';
require_once $base . '/includes/workspaces.php';

$opt = getopt('', ['add:', 'remove:', 'set:', 'sync']);
if (!ws_available()) exit("Chmura plików nie jest skonfigurowana (/ssoadmin → Chmura).\n");
$list = fn(string $v) => array_values(array_filter(array_map('trim', preg_split('/[,;\s]+/', $v)), fn($u) => $u !== ''));

$admins = WsCloud::admins();
$want = null;
if (isset($opt['set']))    $want = $list((string)$opt['set']);
if (isset($opt['add']))    $want = array_merge($want ?? $admins, $list((string)$opt['add']));
if (isset($opt['remove'])) { $rm = array_map('strtolower', $list((string)$opt['remove'])); $want = array_values(array_filter($want ?? $admins, fn($u) => !in_array(strtolower($u), $rm, true))); }

if ($want !== null) {
    $api = WsCloud::api();
    foreach ($want as $uid) {
        if (!$api->userExists($uid)) exit("Nie ma konta „{$uid}” w chmurze — nic nie zmieniam.\n");
    }
    WsCloud::setAdmins($want);
    $admins = WsCloud::admins();
}
echo 'Administratorzy folderów: ' . ($admins ? implode(', ', $admins) : '(brak)') . "\n";

if ($want !== null || isset($opt['sync'])) {
    $r = WsCloud::syncRootShares();
    echo 'Folder „' . WsCloud::root() . '”: dodane ' . (implode(', ', $r['added']) ?: '—') . '; zmienione ' . (implode(', ', $r['updated']) ?: '—')
       . '; usunięte ' . (implode(', ', $r['removed']) ?: '—') . ($r['error'] !== '' ? '; BŁĄD: ' . $r['error'] : '') . "\n";
    foreach (WsCloud::syncAll() as $wsId => $s) {
        $name = (string)(db_one("SELECT name FROM task_workspaces WHERE id = ?", [$wsId])['name'] ?? $wsId);
        echo '  ' . $name . ': dodane ' . (implode(', ', $s['added']) ?: '—') . '; zmienione ' . (implode(', ', $s['updated']) ?: '—')
           . '; usunięte ' . (implode(', ', $s['removed']) ?: '—') . ($s['error'] !== '' ? '; BŁĄD: ' . $s['error'] : '') . "\n";
    }
}
