<?php
/**
 * EZD Client — aplikacja desktopowa (macOS / Windows): skanowanie do EZD, szybka lista kontaktów CRM.
 * Instalatory (.dmg/.pkg/.exe/.msi) leżą w UPLOAD_DIR/ezd-client/ — wgrywa je administrator (lub CI).
 * Pobieranie wyłącznie przez ezd/klient/pobierz.php (po zalogowaniu i z dostępem do EZD).
 */

const EZD_CLIENT_DIR_SUB = 'ezd-client/';
const EZD_CLIENT_TYPES = [   // rozszerzenie => [system, etykieta]
    'dmg' => ['mac', 'macOS (.dmg)'],
    'pkg' => ['mac', 'macOS (.pkg — wdrożenia MDM)'],
    'exe' => ['win', 'Windows (.exe)'],
    'msi' => ['win', 'Windows (.msi — wdrożenia GPO/Intune)'],
];

function ezd_client_dir(): string {
    return rtrim(UPLOAD_DIR, '/') . '/' . EZD_CLIENT_DIR_SUB;
}

/**
 * Dostępne instalatory: najnowsza wersja każdego typu pliku.
 * @return array<string,array{file:string,os:string,label:string,version:string,size:int,mtime:int}> klucz = rozszerzenie
 */
function ezd_client_files(): array {
    $dir = ezd_client_dir();
    if (!is_dir($dir)) return [];
    $best = [];
    foreach (scandir($dir) ?: [] as $f) {
        if ($f[0] === '.' || !is_file($dir . $f)) continue;
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        if (!isset(EZD_CLIENT_TYPES[$ext])) continue;
        $ver = preg_match('/(\d+\.\d+\.\d+)/', $f, $m) ? $m[1] : '0.0.0';
        $cur = $best[$ext] ?? null;
        $mtime = (int)filemtime($dir . $f);
        if ($cur && (version_compare($ver, $cur['version']) < 0
            || (version_compare($ver, $cur['version']) === 0 && $mtime <= $cur['mtime']))) continue;
        $best[$ext] = [
            'file' => $f, 'os' => EZD_CLIENT_TYPES[$ext][0], 'label' => EZD_CLIENT_TYPES[$ext][1],
            'version' => $ver, 'size' => (int)filesize($dir . $f), 'mtime' => $mtime,
        ];
    }
    return $best;
}

function ezd_client_size_label(int $bytes): string {
    return $bytes >= 1048576 ? number_format($bytes / 1048576, 1, ',', '') . ' MB' : max(1, (int)round($bytes / 1024)) . ' KB';
}

/** Publiczne repozytorium: pliki instalatorów trzymane w Git LFS (DesktopTools/ezd-client/releases/). */
const EZD_CLIENT_REPO_MEDIA = 'https://codeberg.org/ziemowitgil/feerSZO/media/branch/main/DesktopTools/ezd-client/releases/';

/**
 * Instalatory z manifestu SHA256SUMS.txt (gdy nie ma ich w uploads/ezd-client/) — linki do plików w repozytorium.
 * @return array<string,array{file:string,os:string,label:string,version:string,sha:string,url:string}> klucz = rozszerzenie
 */
function ezd_client_repo_files(): array {
    $manifest = dirname(__DIR__) . '/DesktopTools/ezd-client/releases/SHA256SUMS.txt';
    if (!is_file($manifest)) return [];
    $best = [];
    foreach (file($manifest, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if (!preg_match('/^([0-9a-f]{64})\s+\*?(.+)$/i', trim($line), $m)) continue;
        [$sha, $name] = [strtolower($m[1]), trim($m[2])];
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!isset(EZD_CLIENT_TYPES[$ext]) || str_contains($name, '/')) continue;
        $ver = preg_match('/(\d+\.\d+\.\d+)/', $name, $v) ? $v[1] : '0.0.0';
        if (isset($best[$ext]) && version_compare($ver, $best[$ext]['version']) <= 0) continue;
        $best[$ext] = [
            'file' => $name, 'os' => EZD_CLIENT_TYPES[$ext][0], 'label' => EZD_CLIENT_TYPES[$ext][1],
            'version' => $ver, 'sha' => $sha, 'url' => EZD_CLIENT_REPO_MEDIA . rawurlencode($name),
        ];
    }
    return $best;
}
