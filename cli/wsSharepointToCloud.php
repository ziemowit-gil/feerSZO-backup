<?php
/**
 * cli/wsSharepointToCloud.php — jednorazowe przeniesienie plików obszarów Zadań z SharePointa do chmury Nextcloud
 * (2026-11-13: „w zadaniach wywal Microsoft i dodaj Nextcloud”).
 *
 * Foldery: każdy wiersz ws_folders z provider='sharepoint' dostaje folder w chmurze (ws_cloud_folder — to samo robi system
 * przy pierwszym użyciu folderu). Pliki: ostatni raz pobierane z SharePointa (Microsoft Graph) i wgrywane do folderu
 * w chmurze; wiersz ws_files przepisany na chmurę (provider, ścieżka, link). Plik, którego nie da się pobrać, zostaje
 * na liście z błędem (można ponowić). Po przeniesieniu warto przejrzeć foldery w Nextcloudzie.
 *
 *   php cli/wsSharepointToCloud.php            # przenosi
 *   php cli/wsSharepointToCloud.php --dry-run  # tylko pokazuje, co by przeniósł
 */
if (php_sapi_name() !== 'cli') { http_response_code(403); exit("Tylko z konsoli.\n"); }

$base = dirname(__DIR__);
require_once $base . '/config.php';
require_once $base . '/includes/db.php';
require_once $base . '/includes/functions.php';
require_once $base . '/includes/workspaces.php';

$dry = in_array('--dry-run', $argv, true);
if (!ws_available()) exit("Chmura plików nie jest skonfigurowana (/ssoadmin → Chmura).\n");
echo 'Chmura: ' . WsCloud::baseUrl() . ', konto API: ' . WsCloud::api()->apiUid() . ($dry ? ' — PODGLĄD, nic nie zmieniam' : '') . "\n";

$folders = db_all("SELECT * FROM ws_folders WHERE COALESCE(provider,'') <> 'nextcloud' ORDER BY workspace_id, id");
echo 'Foldery z SharePointa: ' . count($folders) . "\n";
foreach ($folders as $f) {
    if ($dry) { echo '  [podgląd] #' . $f['id'] . ' ' . $f['name'] . "\n"; continue; }
    try {
        $nc = ws_cloud_folder($f);
        echo '  ✓ #' . $f['id'] . ' → ' . $nc['remote_path'] . "\n";
    } catch (\Throwable $e) {
        echo '  ✗ #' . $f['id'] . ' ' . $f['name'] . ': ' . $e->getMessage() . "\n";
    }
}

$files = db_all("SELECT * FROM ws_files WHERE COALESCE(provider,'') <> 'nextcloud' AND deleted_at IS NULL ORDER BY id");
echo 'Pliki z SharePointa: ' . count($files) . "\n";
if (!$files) exit(0);

$graph = null;
try {
    require_once $base . '/includes/m365.php';
    $graph = new M365Graph();
    if (!$graph->is_configured()) $graph = null;
} catch (\Throwable $e) {
    $graph = null;
}
if (!$graph) {
    echo "Microsoft 365 nie jest już skonfigurowany, więc plików nie da się pobrać z SharePointa. Pobierz je ręcznie i wgraj do folderów w chmurze:\n";
    foreach ($files as $fl) echo '  - #' . $fl['id'] . ' ' . $fl['name'] . ($fl['web_url'] ? ' (' . $fl['web_url'] . ')' : '') . "\n";
    exit(1);
}

$ok = 0; $bad = 0;
foreach ($files as $fl) {
    $label = '#' . $fl['id'] . ' ' . ($fl['original_name'] ?: $fl['name']);
    if ($dry) { echo '  [podgląd] ' . $label . ' (' . ws_format_size((int)$fl['file_size']) . ")\n"; continue; }
    $tmp = tempnam(sys_get_temp_dir(), 'wsmig');
    try {
        $folder = ws_get_folder((int)$fl['folder_id']);
        if (!$folder) throw new RuntimeException('brak folderu #' . $fl['folder_id']);
        $folder = ws_cloud_folder($folder);
        $bytes = $graph->sp_download_file((string)$fl['drive_id'], (string)$fl['remote_item_id']);
        if ($bytes === '' || $bytes === null) throw new RuntimeException('SharePoint zwrócił pusty plik');
        file_put_contents($tmp, $bytes);
        $name = WsCloud::freeName((string)$folder['remote_path'], (string)($fl['original_name'] ?: $fl['name']));
        $path = $folder['remote_path'] . '/' . $name;
        WsCloud::api()->ownPut($path, $tmp);
        $st = WsCloud::api()->ownStat($path);
        if (!$st || empty($st['fileid'])) throw new RuntimeException('chmura nie potwierdziła zapisu');
        db()->prepare("UPDATE ws_files SET provider='nextcloud', name=?, remote_item_id=?, remote_path=?, drive_id=?, web_url=?, file_size=?, updated_at=datetime('now') WHERE id=?")
            ->execute([$name, (string)$st['fileid'], $path, WsCloud::api()->apiUid(), WsCloud::webUrl((int)$st['fileid']), (int)$st['size'], (int)$fl['id']]);
        echo '  ✓ ' . $label . ' → ' . $path . "\n";
        $ok++;
    } catch (\Throwable $e) {
        echo '  ✗ ' . $label . ': ' . $e->getMessage() . "\n";
        $bad++;
    } finally {
        @unlink($tmp);
    }
}
echo "Przeniesione: {$ok}, błędy: {$bad}.\n";
exit($bad ? 1 : 0);
